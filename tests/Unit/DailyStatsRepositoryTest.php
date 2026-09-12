<?php
/**
 * Summary-table read side (docs/13 U3): the dashboard's every number
 * comes from gr_daily_stats and nowhere else — asserted as a
 * machine rule over the issued SQL, not as a promise — plus the
 * dense pivot shape and the dimension ranking.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Storage\Gr_Daily_Stats_Repository;
use PHPUnit\Framework\TestCase;

final class DailyStatsRepositoryTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    /**
     * Stats rows for the canned read: today plus two sparser days.
     *
     * @return void
     */
    private function seed_stats(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'sessions_by_country' ) ) {
                return array(
                    array( 'metric_key' => 'US', 'total' => '9' ),
                    array( 'metric_key' => '', 'total' => '4' ),
                    array( 'metric_key' => 'DE', 'total' => '2' ),
                );
            }

            return array(
                array( 'stat_date' => '2026-09-08', 'metric_type' => 'sessions', 'metric_value' => '3.0000' ),
                array( 'stat_date' => '2026-09-08', 'metric_type' => 'visitors', 'metric_value' => '2.0000' ),
                array( 'stat_date' => '2026-09-10', 'metric_type' => 'sessions', 'metric_value' => '7.0000' ),
                array( 'stat_date' => '2026-09-10', 'metric_type' => 'revenue', 'metric_value' => '129.9000' ),
            );
        };
    }

    public function testSeriesPivotsDenseAndOrdered(): void {
        $this->seed_stats();

        $series = ( new Gr_Daily_Stats_Repository() )->series( 14 );

        // The stub clock's today is 2026-09-10; the window starts 13
        // days back and ends today, oldest first.
        $dates = array_keys( $series );
        $this->assertCount( 14, $dates );
        $this->assertSame( '2026-08-28', $dates[0] );
        $this->assertSame( '2026-09-10', $dates[13] );

        // Stored values land on their day; absent days and absent
        // types coalesce to 0 — the trend frame is always dense.
        $this->assertSame( 7.0, $series['2026-09-10']['sessions'] );
        $this->assertSame( 129.9, $series['2026-09-10']['revenue'] );
        $this->assertSame( 3.0, $series['2026-09-08']['sessions'] );
        $this->assertSame( 0.0, $series['2026-09-09']['sessions'] );
        $this->assertSame( 0.0, $series['2026-09-10']['pageviews'] );

        // Every day carries the full scalar vocabulary.
        $this->assertSame(
            array( 'sessions', 'visitors', 'pageviews', 'conversions', 'revenue' ),
            array_keys( $series['2026-09-10'] )
        );
    }

    public function testDimensionRanksByTotal(): void {
        $this->seed_stats();

        $rows = ( new Gr_Daily_Stats_Repository() )->dimension( 'sessions_by_country', 30, 10 );

        $this->assertSame(
            array(
                array( 'key' => 'US', 'value' => 9.0 ),
                array( 'key' => '', 'value' => 4.0 ),
                array( 'key' => 'DE', 'value' => 2.0 ),
            ),
            $rows
        );

        $sql = implode( ' ', $GLOBALS['wpdb']->queries );
        $this->assertStringContainsString( 'ORDER BY total DESC', $sql );
        $this->assertStringContainsString( 'LIMIT 10', $sql );
    }

    public function testEveryQueryTouchesOnlyTheSummaryTable(): void {
        $this->seed_stats();

        $repo = new Gr_Daily_Stats_Repository();
        $repo->series( 14 );
        $repo->dimension( 'sessions_by_country', 30, 10 );

        // The acceptance rule as a machine assertion: summary table in
        // every statement, raw tables in none of them.
        foreach ( $GLOBALS['wpdb']->queries as $sql ) {
            $this->assertStringContainsString( 'wp_gr_daily_stats', (string) $sql );
            $this->assertStringNotContainsString( 'wp_gr_sessions', (string) $sql );
            $this->assertStringNotContainsString( 'wp_gr_events', (string) $sql );
            $this->assertStringNotContainsString( 'wp_gr_security_logs', (string) $sql );
            $this->assertStringNotContainsString( 'wp_gr_conversions', (string) $sql );
        }
    }

    public function testDayWindowsClamp(): void {
        $this->seed_stats();

        ( new Gr_Daily_Stats_Repository() )->series( 500 );
        $sql = implode( ' ', $GLOBALS['wpdb']->queries );
        $this->assertStringContainsString( "'2026-06-13'", $sql, '90-day ceiling: 89 days back from 2026-09-10 (window includes today)' );
    }
}
