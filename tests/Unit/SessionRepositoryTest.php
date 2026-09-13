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
}
