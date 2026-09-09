<?php
/**
 * Event DTO, dispatcher, repository, and facades (docs/13 C1): context
 * lifting, clamping, persistence, native-hook dispatch, and the recent
 * feed.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Event;
use GreenPNG\Core\Gr_Plugin;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    public function testCreateLiftsContextKeysOutOfThePayload(): void {
        $event = Gr_Event::create(
            'pageview',
            array(
                'visitor_id'    => 'v-123',
                'session_id'    => 's-456',
                'event_id'      => 'evt-789',
                'event_group'   => 'web',
                'ab_experiment' => 'exp1',
                'ab_variant'    => 'b',
                'ab_type'       => 'impression',
                'bot_score'     => 3,
            )
        );

        self::assertSame( 'pageview', $event->name() );
        self::assertSame( 'web', $event->group() );
        self::assertSame( 'v-123', $event->visitor_id() );
        self::assertSame( 's-456', $event->session_id() );
        self::assertSame( 'evt-789', $event->event_id() );
        self::assertSame( 'exp1', $event->ab_experiment() );
        self::assertSame( 'b', $event->ab_variant() );
        self::assertSame( 'impression', $event->ab_type() );
        self::assertSame( array( 'bot_score' => 3 ), $event->payload() );
    }

    public function testCreateDefaultsMatchTheSchemaColumnDefaults(): void {
        $event = Gr_Event::create( 'goal' );

        self::assertSame( 'core', $event->group() );
        self::assertSame( '', $event->visitor_id() );
        self::assertSame( '', $event->session_id() );
        self::assertSame( '', $event->event_id() );
        self::assertSame( array(), $event->payload() );
        self::assertSame( 0, $event->persisted_id() );
        self::assertSame( '2026-09-10 00:00:00', $event->created_at() );
    }

    public function testCreateClampsEveryStringToItsColumnWidth(): void {
        $event = Gr_Event::create(
            str_repeat( 'n', 100 ),
            array(
                'visitor_id' => str_repeat( 'v', 100 ),
                'session_id' => str_repeat( 's', 100 ),
                'ab_type'    => str_repeat( 't', 100 ),
            )
        );

        self::assertSame( 64, strlen( $event->name() ) );
        self::assertSame( 64, strlen( $event->visitor_id() ) );
        self::assertSame( 36, strlen( $event->session_id() ) );
        self::assertSame( 16, strlen( $event->ab_type() ) );
    }

    public function testCreateRejectsAnEmptyName(): void {
        $this->expectException( \InvalidArgumentException::class );

        Gr_Event::create( '   ' );
    }

    public function testNonScalarContextValuesDegradeToEmptyStrings(): void {
        $event = Gr_Event::create( 'x', array( 'visitor_id' => array( 'nope' ) ) );

        self::assertSame( '', $event->visitor_id() );
        self::assertArrayNotHasKey( 'visitor_id', $event->payload() );
    }

    public function testContextKeyListStaysInLockstepWithTheWidthTable(): void {
        $reflection = new \ReflectionClass( Gr_Event::class );
        $keys       = (array) $reflection->getConstant( 'CONTEXT_KEYS' );
        $widths     = (array) $reflection->getConstant( 'CONTEXT_WIDTHS' );

        unset( $widths['event_name'] );

        self::assertSame( $keys, array_keys( $widths ) );
    }

    public function testDispatchPersistsAndFiresTheNativeHook(): void {
        Gr_Plugin::run();

        $event = gr_dispatch_event( 'unit_goal', array( 'amount' => 5, 'visitor_id' => 'v1' ) );

        self::assertInstanceOf( Gr_Event::class, $event );
        self::assertGreaterThan( 0, $event->persisted_id() );

        $fired = array_values(
            array_filter(
                $GLOBALS['gr_stub_fired_action_args'],
                static function ( $record ): bool {
                    return 'gr_event' === $record['hook'];
                }
            )
        );
        self::assertNotEmpty( $fired );
        self::assertCount( 1, $fired[0]['args'] );
        self::assertSame( $event, $fired[0]['args'][0] );

        global $wpdb;
        self::assertCount( 1, $wpdb->inserts );
        self::assertSame( 'wp_gr_events', $wpdb->inserts[0]['table'] );
        self::assertSame( 'unit_goal', $wpdb->inserts[0]['data']['event_name'] );
        self::assertSame( 'core', $wpdb->inserts[0]['data']['event_group'] );
        self::assertSame( 'v1', $wpdb->inserts[0]['data']['visitor_id'] );
        self::assertSame( '2026-09-10 00:00:00', $wpdb->inserts[0]['data']['created_at'] );
        self::assertStringContainsString( '"amount":5', (string) $wpdb->inserts[0]['data']['payload_json'] );
    }

    public function testPersistFilterCanSkipPersistenceWithoutSkippingTheHook(): void {
        add_filter(
            'gr_persist_event',
            static function ( $persist ) {
                return false;
            }
        );

        Gr_Plugin::run();
        $event = gr_dispatch_event( 'filtered', array() );

        global $wpdb;
        self::assertSame( 0, $event->persisted_id() );
        self::assertCount( 0, $wpdb->inserts );
        self::assertContains( 'gr_event', $GLOBALS['gr_stub_fired_actions'] );
    }

    public function testRecentDecodesPayloadAndAppliesTheNameFilter(): void {
        global $wpdb;
        $wpdb->results = array(
            array( 'id' => '7', 'event_name' => 'unit_goal', 'payload_json' => '{"amount":5}' ),
        );

        $rows = gr_get_recent_events( 'unit_goal', 5 );

        self::assertSame( array( 'amount' => 5 ), $rows[0]['payload'] );
        self::assertSame( '{"amount":5}', $rows[0]['payload_json'] );

        $sql = (string) $wpdb->queries[0];
        self::assertStringContainsString( 'wp_gr_events', $sql );
        self::assertStringContainsString( "WHERE event_name = 'unit_goal'", $sql );
        self::assertStringContainsString( 'ORDER BY id DESC', $sql );
        self::assertStringContainsString( 'LIMIT 5', $sql );
    }

    public function testRecentWithoutNameSkipsTheWhereClause(): void {
        gr_get_recent_events( '', 10 );

        global $wpdb;
        $sql = (string) $wpdb->queries[0];

        self::assertStringNotContainsString( 'WHERE', $sql );
        self::assertStringContainsString( 'LIMIT 10', $sql );
    }

    public function testRecentClampsTheLimitIntoASaneRange(): void {
        gr_get_recent_events( '', 0 );
        gr_get_recent_events( '', 9999 );

        global $wpdb;
        $all_sql = implode( ' ', $wpdb->queries );

        self::assertStringContainsString( 'LIMIT 1', $all_sql );
        self::assertStringContainsString( 'LIMIT 500', $all_sql );
    }
}
