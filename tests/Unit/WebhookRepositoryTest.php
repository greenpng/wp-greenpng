<?php
/**
 * Webhook endpoint store (ADR-0016 D1): https-only storage, the
 * secret length gate, the closed event vocabulary intersection, the
 * hard cap, envelope-at-rest secrets, and the delivery health
 * writeback that opens the owner-reset circuit at the threshold.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Secrets;
use GreenPNG\Integrations\Webhook\Gr_Webhook_Repository;
use PHPUnit\Framework\TestCase;

final class WebhookRepositoryTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    protected function tearDown(): void {
        delete_option( Gr_Webhook_Repository::OPTION );
        parent::tearDown();
    }

    /**
     * Adds a well-formed endpoint and returns its id.
     *
     * @param array<int, string> $events Event names.
     * @param bool               $active Active flag.
     * @return int
     */
    private function add_one( array $events = array( 'conversion' ), bool $active = true ): int {
        $error = '';
        $id    = Gr_Webhook_Repository::add( 'https://receiver.example.test/hook', 'unit-test-secret-0123456789', $events, $active, $error );

        self::assertGreaterThan( 0, $id, (string) $error );

        return $id;
    }

    public function testHttpUrlsAreRefusedAndHttpsAccepted(): void {
        $error = '';
        $id    = Gr_Webhook_Repository::add( 'http://receiver.example.test/hook', 'unit-test-secret-0123456789', array( 'conversion' ), true, $error );

        self::assertSame( 0, $id );
        self::assertSame( 'url', $error );
        self::assertSame( array(), Gr_Webhook_Repository::all() );

        self::assertGreaterThan( 0, $this->add_one() );
    }

    public function testTheUrlGateIsStructuralNotDnsBound(): void {
        // The e2e receiver host is a reserved TLD no resolver ever
        // answers, and a private-range host is refused by the core
        // wire validator without any lookup: both must store,
        // because resolvability is a delivery-time concern, not a
        // storage one.
        $error = '';
        $id    = Gr_Webhook_Repository::add( 'https://hooks.e2e.test/receive', 'unit-test-secret-0123456789', array( 'conversion' ), true, $error );
        self::assertGreaterThan( 0, $id, (string) $error );
        self::assertTrue( Gr_Webhook_Repository::valid_url( 'https://192.168.10.10/hook' ) );

        // What the gate still refuses is structural.
        self::assertFalse( Gr_Webhook_Repository::valid_url( 'http://hooks.e2e.test/receive' ) );
        self::assertFalse( Gr_Webhook_Repository::valid_url( 'ftp://hooks.e2e.test/receive' ) );
        self::assertFalse( Gr_Webhook_Repository::valid_url( 'https:///receive' ) );
        self::assertFalse( Gr_Webhook_Repository::valid_url( '' ) );
        self::assertFalse( Gr_Webhook_Repository::valid_url( 'https://hooks.e2e.test/' . str_repeat( 'a', 2100 ) ) );
    }

    public function testShortSecretsAreRefused(): void {
        $error = '';
        $id    = Gr_Webhook_Repository::add( 'https://receiver.example.test/hook', 'short-secret', array( 'conversion' ), true, $error );

        self::assertSame( 0, $id );
        self::assertSame( 'secret', $error );
    }

    public function testTheEventListIntersectsTheClosedVocabulary(): void {
        $error = '';
        $id    = Gr_Webhook_Repository::add( 'https://receiver.example.test/hook', 'unit-test-secret-0123456789', array( 'conversion', 'pageview', 'not-an-event' ), true, $error );

        // The known name survives, the out-of-vocabulary names drop.
        self::assertGreaterThan( 0, $id );
        $row = Gr_Webhook_Repository::find( $id );
        self::assertSame( array( 'conversion' ), $row['events'] );

        // A list empty after the intersection is a refusal.
        $error = '';
        $id    = Gr_Webhook_Repository::add( 'https://receiver.example.test/hook', 'unit-test-secret-0123456789', array( 'signal' ), true, $error );
        self::assertSame( 0, $id );
        self::assertSame( 'events', $error );
    }

    public function testTheEndpointCapIsEnforced(): void {
        for ( $i = 0; $i < Gr_Webhook_Repository::CAP; $i++ ) {
            $error = '';
            $url   = 'https://receiver' . $i . '.example.test/hook';
            $id    = Gr_Webhook_Repository::add( $url, 'unit-test-secret-0123456789', array( 'conversion' ), true, $error );
            self::assertGreaterThan( 0, $id, "endpoint {$i} must store" );
        }

        $error = '';
        $id    = Gr_Webhook_Repository::add( 'https://one-too-many.example.test/hook', 'unit-test-secret-0123456789', array( 'conversion' ), true, $error );

        self::assertSame( 0, $id );
        self::assertSame( 'cap', $error );
        self::assertCount( Gr_Webhook_Repository::CAP, Gr_Webhook_Repository::all() );
    }

    public function testSecretsAreStoredAsEnvelopesAndRoundTrip(): void {
        $id  = $this->add_one();
        $row = Gr_Webhook_Repository::find( $id );

        // At rest: an envelope, never the plaintext.
        $raw = get_option( Gr_Webhook_Repository::OPTION, array() );
        self::assertNotSame( 'unit-test-secret-0123456789', (string) $raw[0]['secret'] );
        self::assertSame( $raw[0]['secret'], (string) $row['secret'] );

        // Through the read side: the exact plaintext returns for
        // signing, and the masked display never carries it whole.
        self::assertSame( 'unit-test-secret-0123456789', Gr_Webhook_Repository::secret_of( $row ) );
        self::assertStringNotContainsString( 'unit-test-secret-0123456789', Gr_Secrets::mask( Gr_Webhook_Repository::secret_of( $row ) ) );
    }

    public function testMatchingRespectsTheActiveAndCircuitState(): void {
        $active = $this->add_one( array( 'conversion' ), true );
        $paused = $this->add_one( array( 'conversion' ), false );

        self::assertCount( 1, Gr_Webhook_Repository::matching( 'conversion' ) );

        // Five exhausted deliveries open the circuit; the owner's
        // reset is the only thing that closes it again.
        for ( $i = 0; $i < Gr_Webhook_Repository::CIRCUIT_THRESHOLD; $i++ ) {
            Gr_Webhook_Repository::record_delivery( $active, false, 'HTTP 500' );
        }
        $row = Gr_Webhook_Repository::find( $active );
        self::assertNotSame( 0, (int) $row['circuit_open_since'] );
        self::assertSame( array(), Gr_Webhook_Repository::matching( 'conversion' ) );

        self::assertTrue( Gr_Webhook_Repository::reset_circuit( $active ) );
        self::assertCount( 1, Gr_Webhook_Repository::matching( 'conversion' ) );

        // The paused twin never matched all along.
        self::assertSame( 0, (int) Gr_Webhook_Repository::find( $paused )['circuit_open_since'] );
    }

    public function testASuccessfulDeliveryResetsTheStreak(): void {
        $id = $this->add_one();

        Gr_Webhook_Repository::record_delivery( $id, false, 'HTTP 500' );
        Gr_Webhook_Repository::record_delivery( $id, false, 'HTTP 500' );
        Gr_Webhook_Repository::record_delivery( $id, true, '200' );

        $row = Gr_Webhook_Repository::find( $id );
        self::assertSame( 0, (int) $row['consecutive_failures'] );
        self::assertSame( 0, (int) $row['circuit_open_since'] );
        self::assertSame( '200', (string) $row['last_status'] );
        self::assertNotSame( '', (string) $row['last_delivery_at'] );
    }

    public function testUpdateKeepsTheEnvelopeWhenTheSecretFieldStaysEmpty(): void {
        $id  = $this->add_one();
        $row = Gr_Webhook_Repository::find( $id );
        $old = (string) $row['secret'];

        self::assertTrue( Gr_Webhook_Repository::update( $id, 'https://moved.example.test/hook', '', array( 'lead' ), true ) );

        $row = Gr_Webhook_Repository::find( $id );
        self::assertSame( 'https://moved.example.test/hook', (string) $row['url'] );
        self::assertSame( $old, (string) $row['secret'] );
        self::assertSame( 'unit-test-secret-0123456789', Gr_Webhook_Repository::secret_of( $row ) );

        // A rotation swaps the envelope and the plaintext behind it.
        self::assertTrue( Gr_Webhook_Repository::update( $id, 'https://moved.example.test/hook', 'rotated-secret-0123456789', array( 'lead' ), true ) );
        self::assertSame( 'rotated-secret-0123456789', Gr_Webhook_Repository::secret_of( Gr_Webhook_Repository::find( $id ) ) );
    }

    public function testDeleteRemovesTheEndpointAndItsMatchability(): void {
        $id = $this->add_one();

        self::assertTrue( Gr_Webhook_Repository::delete( $id ) );
        self::assertNull( Gr_Webhook_Repository::find( $id ) );
        self::assertSame( array(), Gr_Webhook_Repository::matching( 'conversion' ) );
        self::assertFalse( Gr_Webhook_Repository::delete( $id ) );
    }
}
