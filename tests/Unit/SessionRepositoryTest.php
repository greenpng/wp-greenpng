<?php
/**
 * Sessions repository (docs/13 C5): atomic upsert shape, landing
 * whitelist, and the online count's indexed range query.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Storage\Gr_Session_Repository;
use PHPUnit\Framework\TestCase;

final class SessionRepositoryTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    public function testTouchBuildsTheAtomicUpsertAgainstTheResolvedTable(): void {
        global $wpdb;
        $wpdb->query_result = 1;

        $repository = new Gr_Session_Repository();
        $affected   = $repository->touch(
            'v' . str_repeat( 'a', 31 ),
            'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
            array(
                'channel'     => 'cpc',
                'utm_source'  => 'google',
                'landing_path'=> '/offer/',
                'malicious_key' => 'dropped by the whitelist',
            )
        );

        self::assertSame( 1, $affected );

        $sql = (string) end( $wpdb->queries );
        self::assertStringContainsString( 'INSERT INTO wp_gr_sessions', $sql );
        self::assertStringContainsString( 'ON DUPLICATE KEY UPDATE last_active = VALUES(last_active), pageviews = pageviews + 1', $sql );
        self::assertStringContainsString( "'cpc'", $sql );
        self::assertStringContainsString( "'google'", $sql );
        self::assertStringContainsString( "'/offer/'", $sql );
        self::assertStringNotContainsString( "'dropped by the whitelist'", $sql );
        // Landing columns only update via the duplicate-key clause.
        self::assertStringNotContainsString( 'started_at = VALUES', $sql );
        self::assertStringNotContainsString( 'channel = VALUES', $sql );
    }

    public function testTouchReportsZeroWhenTheWriteFails(): void {
        global $wpdb;
        $wpdb->query_result = false;

        $repository = new Gr_Session_Repository();

        self::assertSame( 0, $repository->touch( str_repeat( 'a', 32 ), 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d' ) );
    }

    public function testCountOnlineReadsTheFiveMinuteCutoff(): void {
        global $wpdb;
        $wpdb->var_result = '3';

        $repository = new Gr_Session_Repository();

        self::assertSame( 3, $repository->count_online() );

        $sql = (string) end( $wpdb->queries );
        self::assertStringContainsString( 'SELECT COUNT(*) FROM wp_gr_sessions', $sql );
        // Stub clock is 2026-09-10 00:00:00; the default window is 300s.
        self::assertStringContainsString( "WHERE last_active > '2026-09-09 23:55:00'", $sql );
    }

    public function testCountOnlineClampsTheWindow(): void {
        global $wpdb;
        $wpdb->var_result = '0';

        $repository = new Gr_Session_Repository();
        $repository->count_online( 0 );
        $repository->count_online( 999999 );

        $floor_sql   = (string) $wpdb->queries[0];
        $ceiling_sql = (string) $wpdb->queries[2];

        self::assertStringContainsString( "'2026-09-09 23:59:30'", $floor_sql );
        self::assertStringContainsString( "'2026-09-09 23:00:00'", $ceiling_sql );
    }

    public function testApplyProbeScoreRaisesTheScoreAndSticksTheVerdict(): void {
        global $wpdb;
        $wpdb->query_result = 1;

        $affected = ( new Gr_Session_Repository() )->apply_probe_score(
            str_repeat( 'a', 32 ),
            'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
            70,
            1
        );

        self::assertSame( 1, $affected );

        // GREATEST keeps the strongest evidence ever seen, and the
        // verdict is sticky: a weaker later signal never un-convicts
        // (ADR-0009 D2).
        $sql = (string) end( $wpdb->queries );
        self::assertStringContainsString( 'UPDATE wp_gr_sessions', $sql );
        self::assertStringContainsString( 'bot_score = GREATEST(bot_score, 70)', $sql );
        self::assertStringContainsString( 'is_bot = IF(1 = 1, 1, is_bot)', $sql );
        self::assertStringContainsString( "WHERE visitor_id = '" . str_repeat( 'a', 32 ) . "'", $sql );
        self::assertStringContainsString( "AND session_id = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d'", $sql );
    }

    public function testApplyProbeScoreBelowTheVerdictLeavesTheColumnAlone(): void {
        global $wpdb;
        $wpdb->query_result = 1;

        ( new Gr_Session_Repository() )->apply_probe_score(
            str_repeat( 'b', 32 ),
            'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
            40,
            0
        );

        $sql = (string) end( $wpdb->queries );
        self::assertStringContainsString( 'bot_score = GREATEST(bot_score, 40)', $sql );
        // A non-verdict reads IF(0 = 1, …): is_bot keeps whatever it
        // already held.
        self::assertStringContainsString( 'is_bot = IF(0 = 1, 1, is_bot)', $sql );
    }

    public function testApplyProbeScoreClampsToTheColumnRange(): void {
        global $wpdb;
        $wpdb->query_result = 1;

        ( new Gr_Session_Repository() )->apply_probe_score(
            str_repeat( 'c', 32 ),
            'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
            250,
            1
        );

        $sql = (string) end( $wpdb->queries );
        self::assertStringContainsString( 'GREATEST(bot_score, 100)', $sql );
    }

    public function testMarkSessionBotTouchesOnlyTheVerdictColumn(): void {
        global $wpdb;
        $wpdb->query_result = 1;

        self::assertSame(
            1,
            ( new Gr_Session_Repository() )->mark_session_bot(
                str_repeat( 'd', 32 ),
                'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d'
            )
        );

        // The detector side writes the boolean conclusion only; the
        // probe's measured score stays whatever the probe measured —
        // the two mounts never overwrite each other (ADR-0009 D2).
        $sql = (string) end( $wpdb->queries );
        self::assertStringContainsString( 'UPDATE wp_gr_sessions SET is_bot = 1', $sql );
        self::assertStringNotContainsString( 'bot_score', $sql );
    }

    public function testPagedReadsNewestActivityFirstWithBothTotals(): void {
        global $wpdb;
        $wpdb->var_result = '12';
        $wpdb->results    = array(
            array( 'visitor_id' => 'abcdef1234', 'started_at' => '2026-09-10 09:00:00' ),
        );

        $result = ( new Gr_Session_Repository() )->paged( array(), 20, 0 );

        self::assertSame( 12, $result['total'] );
        self::assertCount( 1, $result['rows'] );

        // The count runs unprepared when no filter joined it, and the
        // page read orders by newest activity.
        $count_sql = (string) $wpdb->queries[0];
        $page_sql  = (string) end( $wpdb->queries );
        self::assertStringContainsString( 'SELECT COUNT(*) FROM wp_gr_sessions', $count_sql );
        self::assertStringNotContainsString( 'WHERE', $count_sql );
        self::assertStringContainsString( 'ORDER BY last_active DESC, session_id DESC', $page_sql );
        self::assertStringContainsString( 'LIMIT 20 OFFSET 0', $page_sql );

        // The read never selects identification columns the surface
        // refuses to show: no IP column exists on the table, and the
        // user-agent family stays out of the list vocabulary.
        self::assertStringNotContainsString( 'ua_family', $page_sql );

        // The hosting label has its own list column, so the read
        // must carry it — a surface column without its select column
        // renders a silent "No" for every row.
        self::assertStringContainsString( 'ip_quality', $page_sql );
    }

    public function testPagedBuildsTheDateRangeAndPreparesBothBounds(): void {
        global $wpdb;
        $wpdb->var_result = '5';
        $wpdb->results    = array();

        ( new Gr_Session_Repository() )->paged(
            array(
                'from' => '2026-09-01',
                'to'   => '2026-09-30',
                'zzz'  => 'unknown keys never join the WHERE',
            ),
            20,
            0
        );

        $count_sql = (string) $wpdb->queries[0];
        self::assertStringContainsString( "started_at >= '2026-09-01 00:00:00'", $count_sql );
        self::assertStringContainsString( "started_at <= '2026-09-30 23:59:59'", $count_sql );
        self::assertStringNotContainsString( 'unknown keys', $count_sql );
    }

    public function testPagedDropsMalformedDatesAndBuildsTheFourWaySearch(): void {
        global $wpdb;
        $wpdb->var_result = '2';
        $wpdb->results    = array();

        ( new Gr_Session_Repository() )->paged(
            array(
                'from' => 'not-a-date',
                's'    => 'spring sale',
            ),
            20,
            0
        );

        $count_sql = (string) $wpdb->queries[0];
        // Malformed dates never join; the search spans the four
        // searchable columns with the same wrapped value.
        self::assertStringNotContainsString( 'not-a-date', $count_sql );
        self::assertSame( 4, substr_count( $count_sql, "'%spring sale%'" ) );
        self::assertStringContainsString( 'visitor_id LIKE', $count_sql );
        self::assertStringContainsString( 'session_id LIKE', $count_sql );
        self::assertStringContainsString( 'landing_path LIKE', $count_sql );
        self::assertStringContainsString( 'utm_campaign LIKE', $count_sql );
    }

    public function testPagedSearchEscapesLikeWildcards(): void {
        global $wpdb;
        $wpdb->var_result = '1';
        $wpdb->results    = array();

        ( new Gr_Session_Repository() )->paged( array( 's' => '40% off' ), 20, 0 );

        $count_sql = (string) $wpdb->queries[0];

        // The user's percent never becomes pattern syntax: esc_like
        // backs it with a backslash before prepare() runs.
        self::assertStringContainsString( '40', $count_sql );
        self::assertStringContainsString( '\%', $count_sql );
        self::assertSame( 4, substr_count( $count_sql, 'LIKE ' ) );
    }

    public function testPagedClampsItsPaginationArguments(): void {
        global $wpdb;
        $wpdb->var_result = '0';
        $wpdb->results    = array();

        ( new Gr_Session_Repository() )->paged( array(), 0, -5 );

        $page_sql = (string) end( $wpdb->queries );
        self::assertStringContainsString( 'LIMIT 1 OFFSET 0', $page_sql );

        $wpdb->queries = array();
        ( new Gr_Session_Repository() )->paged( array(), 999999, 0 );
        $page_sql = (string) end( $wpdb->queries );
        self::assertStringContainsString( 'LIMIT 5000', $page_sql );
    }

    public function testTodayDeviceSplitAggregatesInOneBoundedRead(): void {
        global $wpdb;
        $wpdb->results = array(
            array( 'device_type' => 'mobile', 'sessions' => '7', 'bots' => '2' ),
            array( 'device_type' => 'desktop', 'sessions' => '5', 'bots' => '0' ),
        );

        $split = ( new Gr_Session_Repository() )->today_device_split();

        self::assertSame(
            array(
                array( 'key' => 'mobile', 'value' => 7 ),
                array( 'key' => 'desktop', 'value' => 5 ),
            ),
            $split['devices']
        );
        self::assertSame( 12, $split['sessions'] );
        self::assertSame( 2, $split['bots'] );

        $sql = (string) end( $wpdb->queries );
        self::assertStringContainsString( 'GROUP BY device_type', $sql );
        // Today's boundary rides the started index, matching the
        // panels endpoint's bounded-read contract.
        self::assertStringContainsString( "WHERE started_at >= '2026-09-10 00:00:00'", $sql );
    }

    public function testTodayDeviceSplitDegradesToZeroOnAFailedRead(): void {
        global $wpdb;
        $wpdb->results = 'not-an-array';

        $split = ( new Gr_Session_Repository() )->today_device_split();

        self::assertSame( array(), $split['devices'] );
        self::assertSame( 0, $split['sessions'] );
        self::assertSame( 0, $split['bots'] );
    }

    public function testTouchCarriesTheIpQualityLandingAttribute(): void {
        global $wpdb;
        $wpdb->query_result = 1;

        $repository = new Gr_Session_Repository();
        $affected   = $repository->touch(
            'v' . str_repeat( 'a', 31 ),
            'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
            array(
                'ip_quality' => 'hosting',
                'channel'    => 'organic',
            )
        );

        self::assertSame( 1, $affected );

        $sql = (string) end( $wpdb->queries );
        // The landing category rides the INSERT arm only: it is a
        // first-touch fact, never an update arm on later touches.
        self::assertStringContainsString( 'ip_quality', $sql );
        self::assertStringContainsString( "'hosting'", $sql );
        self::assertStringNotContainsString( 'ip_quality = VALUES', $sql );
    }

    public function testInvalidTrafficByCampaignAggregatesInTwoIndexedReads(): void {
        global $wpdb;

        // The closure discriminates the sessions read from the
        // conversions read so both queries can be stubbed at once.
        $wpdb->results = function ( $sql ) {
            if ( str_contains( (string) $sql, 'utm_campaign' ) && str_contains( (string) $sql, 'touchpoints' ) ) {
                return array( array( 'campaign' => 'spring', 'converted' => '1' ) );
            }

            return array(
                array( 'campaign' => 'spring', 'sessions' => '9', 'bots' => '3', 'hosting' => '2' ),
                array( 'campaign' => 'summer', 'sessions' => '5', 'bots' => '0', 'hosting' => '1' ),
            );
        };

        $rows = ( new Gr_Session_Repository() )->invalid_traffic_by_campaign( 90, 25 );

        self::assertSame( 'spring', $rows[0]['campaign'] );
        self::assertSame( 9, $rows[0]['sessions'] );
        self::assertSame( 3, $rows[0]['bots'] );
        self::assertSame( 2, $rows[0]['hosting'] );
        // The conversion merge credits spring with one order.
        self::assertSame( 1, $rows[0]['converted'] );
        self::assertSame( 0, $rows[1]['converted'] );

        // The stub logs the prepared SQL and the executed read, so
        // the first entry is the sessions aggregate and the last is
        // the conversions join.
        $sql = array_map( 'strval', $wpdb->queries );
        $first = (string) reset( $sql );
        $last  = (string) end( $sql );
        self::assertStringContainsString( 'GROUP BY s.utm_campaign', $first );
        self::assertStringContainsString( 'SUM(s.is_bot)', $first );
        self::assertStringContainsString( "ip_quality = 'hosting'", $first );
        self::assertStringContainsString( 'ORDER BY sessions DESC', $first );
        self::assertStringContainsString( 'LIMIT 25', $first );
        self::assertStringContainsString( 'JOIN', $last );
        self::assertStringContainsString( 'touchpoints', $last );
        self::assertStringContainsString( 'last_touch_id', $last );
    }
}
