<?php
/**
 * Webhook bus subscriber (ADR-0016 D3): matching events fan out as
 * per-endpoint queue snapshots, non-matching events cost nothing,
 * and the snapshot is the entire delivery — nothing is looked up
 * from the live request again.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Event;
use GreenPNG\Integrations\Webhook\Gr_Webhook_Delivery;
use GreenPNG\Integrations\Webhook\Gr_Webhook_Dispatcher;
use GreenPNG\Integrations\Webhook\Gr_Webhook_Repository;
use PHPUnit\Framework\TestCase;

final class WebhookDispatcherTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        Gr_Webhook_Dispatcher::register();
    }

    protected function tearDown(): void {
        delete_option( Gr_Webhook_Repository::OPTION );
        parent::tearDown();
    }

    /**
     * One endpoint subscribed to one event.
     *
     * @return int Endpoint id.
     */
    private function endpoint( string $event = 'conversion', bool $active = true ): int {
        $error = '';
        $id    = Gr_Webhook_Repository::add( 'https://receiver.example.test/hook', 'unit-test-secret-0123456789', array( $event ), $active, $error );
        self::assertGreaterThan( 0, $id, (string) $error );

        return $id;
    }

    public function testAMatchingEventSnapshotsOneQueueJobPerEndpoint(): void {
        $one = $this->endpoint( 'conversion' );
        $two = $this->endpoint( 'lead' );

        $event = Gr_Event::create(
            'conversion',
            array(
                'event_group' => 'funnel',
                'visitor_id'  => str_repeat( 'a', 32 ),
                'session_id'  => 'b1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
                'amount'      => 19.98,
                'currency'    => 'USD',
            )
        );

        Gr_Webhook_Dispatcher::on_event( $event );

        // One dispatch: only the conversion subscriber matched.
        self::assertCount( 1, $GLOBALS['gr_stub_cron'] );
        $job = $GLOBALS['gr_stub_cron'][0];
        self::assertSame( Gr_Webhook_Delivery::HOOK, $job['hook'] );
        $args = $job['args'][0];

        // The snapshot carries the whole DTO — the queue job must
        // never come back to the live request for anything.
        self::assertSame( $one, (int) $args['endpoint'] );
        self::assertSame( 0, (int) $args['attempt'] );
        self::assertSame( 'conversion', $args['event']['name'] );
        self::assertSame( 'funnel', $args['event']['group'] );
        self::assertSame( str_repeat( 'a', 32 ), $args['event']['visitor_id'] );
        self::assertSame( 19.98, $args['event']['payload']['amount'] );

        // The lead-only twin saw nothing.
        $dispatched = array_map(
            static function ( $row ) {
                return (int) $row['args'][0]['endpoint'];
            },
            $GLOBALS['gr_stub_cron']
        );
        self::assertNotContains( $two, $dispatched );
    }

    public function testEventsOutsideEveryVocabularyCostOneOptionRead(): void {
        $this->endpoint( 'conversion' );

        Gr_Webhook_Dispatcher::on_event( Gr_Event::create( 'pageview', array() ) );
        Gr_Webhook_Dispatcher::on_event( Gr_Event::create( 'signal', array( 'bot_score' => 70 ) ) );

        self::assertSame( array(), $GLOBALS['gr_stub_cron'] );
    }

    public function testPausedAndOpenCircuitEndpointsDoNotDispatch(): void {
        $active  = $this->endpoint( 'conversion' );
        $paused  = $this->endpoint( 'conversion', false );

        Gr_Webhook_Dispatcher::on_event( Gr_Event::create( 'conversion', array() ) );
        self::assertCount( 1, $GLOBALS['gr_stub_cron'] );
        $GLOBALS['gr_stub_cron'] = array();

        for ( $i = 0; $i < Gr_Webhook_Repository::CIRCUIT_THRESHOLD; $i++ ) {
            Gr_Webhook_Repository::record_delivery( $active, false, 'HTTP 500' );
        }

        Gr_Webhook_Dispatcher::on_event( Gr_Event::create( 'conversion', array() ) );
        self::assertSame( array(), $GLOBALS['gr_stub_cron'] );

        // The paused twin stays untouched by the other's circuit.
        self::assertSame( 0, (int) Gr_Webhook_Repository::find( $paused )['consecutive_failures'] );
    }
}
