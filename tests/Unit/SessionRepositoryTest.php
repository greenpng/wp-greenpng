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
}
