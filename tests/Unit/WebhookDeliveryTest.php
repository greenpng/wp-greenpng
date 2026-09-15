<?php
/**
 * Webhook delivery job (ADR-0016 D2/D3): the four signature headers
 * with the exact-body contract, the queue retry ladder and its
 * exhaustion writeback, and the refusal paths (deleted, paused,
 * open-circuit, unreadable secret) that never touch the wire.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Integrations\Webhook\Gr_Webhook_Delivery;
use GreenPNG\Integrations\Webhook\Gr_Webhook_Repository;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class WebhookDeliveryTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    protected function tearDown(): void {
        delete_option( Gr_Webhook_Repository::OPTION );
        parent::tearDown();
    }

    /**
     * One stored endpoint, ready to deliver.
     *
     * @return int Endpoint id.
     */
    private function endpoint(): int {
        $error = '';
        $id    = Gr_Webhook_Repository::add( 'https://receiver.example.test/hook', 'unit-test-secret-0123456789', array( 'conversion' ), true, $error );
        self::assertGreaterThan( 0, $id, (string) $error );

        return $id;
    }

    /**
     * A well-formed job for the given attempt.
     *
     * @param int $id      Endpoint id.
     * @param int $attempt Attempt index.
     * @return array<string, mixed>
     */
    private function job( int $id, int $attempt = 0 ): array {
        return array(
            'endpoint' => $id,
            'attempt'  => $attempt,
            'event'    => array(
                'name'       => 'conversion',
                'group'      => 'funnel',
                'visitor_id' => str_repeat( 'a', 32 ),
                'session_id' => 'b1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
                'payload'    => array( 'amount' => 19.98, 'currency' => 'USD' ),
            ),
        );
    }

    public function testTheSignatureHeadersCoverTheExactBodyBytes(): void {
        $body    = '{"name":"conversion","amount":19.98}';
        $headers = Gr_Webhook_Delivery::headers( 'conversion', $body, 'unit-test-secret-0123456789' );

        // The receiver recomputes over the same bytes and the header
        // matches — the whole round-trip contract in one assertion.
        $expected = 'sha256=' . hash_hmac( 'sha256', $body, 'unit-test-secret-0123456789' );
        self::assertSame( $expected, $headers[ Gr_Webhook_Delivery::HEADER_SIGNATURE ] );

        self::assertSame( 'conversion', $headers[ Gr_Webhook_Delivery::HEADER_EVENT ] );
        self::assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $headers[ Gr_Webhook_Delivery::HEADER_DELIVERY ] );
        self::assertGreaterThan( 0, (int) $headers[ Gr_Webhook_Delivery::HEADER_TIMESTAMP ] );
    }

    public function testASuccessfulDeliverySignsTheExactBodyItSends(): void {
        $id     = $this->endpoint();
        $job    = $this->job( $id );
        $GLOBALS['gr_stub_http']['https://receiver.example.test/hook'] = array(
            'response' => array( 'code' => 200 ),
            'body'     => 'ok',
        );

        Gr_Webhook_Delivery::run( $job );

        self::assertCount( 1, $GLOBALS['gr_stub_http_calls'] );
        $call = $GLOBALS['gr_stub_http_calls'][0];
        self::assertSame( 'POST', $call['method'] );

        // Byte-exactness: what went out is json_encode of the
        // snapshot, and the signature header covers exactly those
        // bytes with the stored secret.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_wp_json_encode -- byte-parity with the delivery, which signs plain json_encode.
        $sent = (string) $call['args']['body'];
        self::assertSame( json_encode( $job['event'] ), $sent );
        $headers = $call['args']['headers'];
        self::assertSame(
            'sha256=' . hash_hmac( 'sha256', $sent, 'unit-test-secret-0123456789' ),
            (string) $headers[ Gr_Webhook_Delivery::HEADER_SIGNATURE ]
        );
        self::assertSame( 'conversion', (string) $headers[ Gr_Webhook_Delivery::HEADER_EVENT ] );

        $row = Gr_Webhook_Repository::find( $id );
        self::assertSame( '200', (string) $row['last_status'] );
        self::assertSame( 0, (int) $row['consecutive_failures'] );
        self::assertSame( array(), $GLOBALS['gr_stub_cron'] );
    }

    public function testAFailedDeliveryWalksTheRetryLadderThenParks(): void {
        $id  = $this->endpoint();
        $GLOBALS['gr_stub_http']['https://receiver.example.test/hook'] = new WP_Error( 'http_request_failed', 'Connection timed out.' );

        // Attempt 0: one wire call, one re-enqueue at 60 seconds.
        $before = time();
        Gr_Webhook_Delivery::run( $this->job( $id, 0 ) );
        $after = time();
        self::assertCount( 1, $GLOBALS['gr_stub_http_calls'] );
        self::assertCount( 1, $GLOBALS['gr_stub_cron'] );
        self::assertSame( Gr_Webhook_Delivery::HOOK, $GLOBALS['gr_stub_cron'][0]['hook'] );
        self::assertGreaterThanOrEqual( $before + 60, $GLOBALS['gr_stub_cron'][0]['timestamp'] );
        self::assertLessThanOrEqual( $after + 60, $GLOBALS['gr_stub_cron'][0]['timestamp'] );
        self::assertSame( 1, (int) $GLOBALS['gr_stub_cron'][0]['args'][0]['attempt'] );

        // The queue's +60 seconds outlives the client's 30-second
        // backoff step; the test clears the client state between
        // ladder steps to model the time the queue ride spent.
        delete_transient( 'gr_http_' . Gr_Webhook_Delivery::service( $id ) );

        // Attempt 1: the 300-second step.
        $GLOBALS['gr_stub_cron'] = array();
        $before = time();
        Gr_Webhook_Delivery::run( $this->job( $id, 1 ) );
        $after = time();
        self::assertCount( 1, $GLOBALS['gr_stub_cron'] );
        self::assertGreaterThanOrEqual( $before + 300, $GLOBALS['gr_stub_cron'][0]['timestamp'] );
        self::assertLessThanOrEqual( $after + 300, $GLOBALS['gr_stub_cron'][0]['timestamp'] );
        self::assertSame( 2, (int) $GLOBALS['gr_stub_cron'][0]['args'][0]['attempt'] );

        delete_transient( 'gr_http_' . Gr_Webhook_Delivery::service( $id ) );

        // Attempt 2: no third retry, the failure parks on the
        // endpoint record with the wire reason word.
        $GLOBALS['gr_stub_cron'] = array();
        Gr_Webhook_Delivery::run( $this->job( $id, 2 ) );
        self::assertSame( array(), $GLOBALS['gr_stub_cron'] );
        $row = Gr_Webhook_Repository::find( $id );
        self::assertSame( 1, (int) $row['consecutive_failures'] );
        self::assertStringContainsString( 'timed out', (string) $row['last_status'] );
    }

    public function testAPrematureCallInsideTheClientWaitWindowDefersWithoutBurningAnAttempt(): void {
        $id  = $this->endpoint();
        $GLOBALS['gr_stub_http']['https://receiver.example.test/hook'] = new WP_Error( 'http_request_failed', 'Connection timed out.' );

        // The first failure opens the client's 30-second backoff.
        Gr_Webhook_Delivery::run( $this->job( $id, 0 ) );
        self::assertCount( 1, $GLOBALS['gr_stub_http_calls'] );

        // A premature second call (the queue fired early) is a
        // timing refusal, not a delivery attempt: the job defers at
        // the client's own window, keeps the attempt number, and
        // leaves the endpoint record untouched.
        $GLOBALS['gr_stub_cron'] = array();
        $before = time();
        Gr_Webhook_Delivery::run( $this->job( $id, 1 ) );
        $after = time();

        self::assertCount( 1, $GLOBALS['gr_stub_http_calls'] );
        self::assertCount( 1, $GLOBALS['gr_stub_cron'] );
        $deferred = $GLOBALS['gr_stub_cron'][0]['args'][0];
        self::assertSame( 1, (int) $deferred['attempt'] );
        self::assertGreaterThanOrEqual( $before + 1, $GLOBALS['gr_stub_cron'][0]['timestamp'] );
        self::assertLessThanOrEqual( $after + 60, $GLOBALS['gr_stub_cron'][0]['timestamp'] );

        $row = Gr_Webhook_Repository::find( $id );
        self::assertSame( '', (string) $row['last_status'] );
        self::assertSame( 0, (int) $row['consecutive_failures'] );
    }

    public function testExhaustedStreaksOpenTheCircuitAndRefuseTheWire(): void {
        $id = $this->endpoint();
        for ( $i = 0; $i < Gr_Webhook_Repository::CIRCUIT_THRESHOLD; $i++ ) {
            Gr_Webhook_Repository::record_delivery( $id, false, 'HTTP 500' );
        }

        $GLOBALS['gr_stub_http']['https://receiver.example.test/hook'] = array(
            'response' => array( 'code' => 200 ),
            'body'     => 'ok',
        );

        Gr_Webhook_Delivery::run( $this->job( $id ) );

        // Open circuit: not one byte left the site.
        self::assertSame( array(), $GLOBALS['gr_stub_http_calls'] );
    }

    public function testPendingWorkForADeletedOrPausedEndpointIsANoop(): void {
        $id  = $this->endpoint();
        $job = $this->job( $id );
        $GLOBALS['gr_stub_http']['https://receiver.example.test/hook'] = array(
            'response' => array( 'code' => 200 ),
            'body'     => 'ok',
        );

        // Paused by the owner: no wire attempt.
        Gr_Webhook_Repository::toggle( $id );
        Gr_Webhook_Delivery::run( $job );
        self::assertSame( array(), $GLOBALS['gr_stub_http_calls'] );

        // Deleted meanwhile: pending queue jobs die quietly.
        Gr_Webhook_Repository::toggle( $id );
        Gr_Webhook_Repository::delete( $id );
        Gr_Webhook_Delivery::run( $job );
        self::assertSame( array(), $GLOBALS['gr_stub_http_calls'] );
        self::assertSame( array(), $GLOBALS['gr_stub_cron'] );
    }

    public function testAnUnreadableSecretRefusesInsteadOfForging(): void {
        $id = $this->endpoint();

        // An envelope the cipher can no longer open: the honest
        // refusal is a recorded failure, not an unsigned delivery.
        $rows = get_option( Gr_Webhook_Repository::OPTION, array() );
        $rows[0]['secret'] = 'not-an-envelope';
        update_option( Gr_Webhook_Repository::OPTION, $rows, false );

        Gr_Webhook_Delivery::run( $this->job( $id ) );

        self::assertSame( array(), $GLOBALS['gr_stub_http_calls'] );
        self::assertSame( 'secret_unreadable', (string) Gr_Webhook_Repository::find( $id )['last_status'] );
    }
}
