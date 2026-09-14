<?php
/**
 * Daily aggregation (docs/13 U1, docs/05 §1/§5): every metric family
 * folds into gr_daily_stats, quiet days still get dense scalar rows,
 * re-running a day replaces instead of adding, and days beyond the
 * lookback window are never revisited — which is what keeps reports
 * stable after the raw tables are slimmed.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Core\Gr_Queue;
use GreenPNG\Storage\Gr_Daily_Aggregator;
use PHPUnit\Framework\TestCase;

final class DailyAggregatorTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    /**
     * Canned raw-table reads: one row set per aggregation query, keyed
     * by the table/column each statement touches.
     *
     * @param array<string, array<int, array<string, string>>> $counts Per-family rows.
     * @param string|int                                        $pageviews get_var answer.
     * @return void
     */
    private function seed_reads( array $counts, $pageviews = '0' ): void {
        $GLOBALS['wpdb']->var_result = (string) $pageviews;

        $GLOBALS['wpdb']->results = static function ( string $sql ) use ( $counts ): array {
            if ( false !== strpos( $sql, 'COUNT(DISTINCT visitor_id)' ) ) {
                return $counts['session_totals'];
            }
            if ( false !== strpos( $sql, 'country_code' ) ) {
                return $counts['by_country'];
            }
            if ( false !== strpos( $sql, 'channel' ) ) {
                return $counts['by_channel'];
            }
            if ( false !== strpos( $sql, 'device_type' ) ) {
                return $counts['by_device'];
            }
            if ( false !== strpos( $sql, 'is_bot' ) ) {
                return $counts['by_bot'];
            }
            if ( false !== strpos( $sql, 'gr_security_logs' ) ) {
                return $counts['security'];
            }
            if ( false !== strpos( $sql, 'gr_conversions' ) ) {
                return $counts['conversions'];
            }
            return array();
        };
    }

    /**
     * Every upsert statement this test issued (prepare and query each
     * record one identical line, so duplicates collapse).
     *
     * @return array<int, string>
     */
    private function upserts(): array {
        global $wpdb;

        $found = array();
        foreach ( $wpdb->queries as $sql ) {
            if ( false !== strpos( (string) $sql, 'INSERT INTO wp_gr_daily_stats' ) ) {
                $found[] = (string) $sql;
            }
        }

        return array_values( array_unique( $found ) );
    }

    public function testEveryMetricFamilyFoldsIntoUpsertRows(): void {
        $this->seed_reads(
            array(
                'session_totals' => array( array( 'sessions' => '7', 'visitors' => '3' ) ),
                'by_country'     => array(
                    array( 'metric_key' => 'US', 'metric_value' => '5' ),
                    array( 'metric_key' => '', 'metric_value' => '2' ),
                ),
                'by_channel'     => array(
                    array( 'metric_key' => 'organic', 'metric_value' => '4' ),
                    array( 'metric_key' => 'direct', 'metric_value' => '3' ),
                ),
                'by_device'      => array( array( 'metric_key' => 'mobile', 'metric_value' => '7' ) ),
                'by_bot'         => array(
                    array( 'metric_key' => '1', 'metric_value' => '2' ),
                    array( 'metric_key' => '0', 'metric_value' => '5' ),
                ),
                'security'       => array( array( 'metric_key' => 'scanner_ua', 'metric_value' => '6' ) ),
                'conversions'    => array( array( 'conversions' => '2', 'revenue' => '129.90' ) ),
            ),
            '11'
        );

        $written = Gr_Daily_Aggregator::aggregate_date( '2026-09-10' );

        // 5 scalars + 2 country + 2 channel + 1 device + 2 bot + 1 security.
        $this->assertSame( 13, $written );
        $sql = implode( ' ', $this->upserts() );

        $this->assertStringContainsString( "('2026-09-10', 'sessions', '', 7.000000)", $sql );
        $this->assertStringContainsString( "('2026-09-10', 'visitors', '', 3.000000)", $sql );
        $this->assertStringContainsString( "('2026-09-10', 'pageviews', '', 11.000000)", $sql );
        $this->assertStringContainsString( "('2026-09-10', 'sessions_by_country', 'US', 5.000000)", $sql );
        $this->assertStringContainsString( "('2026-09-10', 'sessions_by_country', '', 2.000000)", $sql );
        $this->assertStringContainsString( "('2026-09-10', 'sessions_by_channel', 'organic', 4.000000)", $sql );
        $this->assertStringContainsString( "('2026-09-10', 'sessions_by_device', 'mobile', 7.000000)", $sql );
        $this->assertStringContainsString( "('2026-09-10', 'sessions_by_bot', 'bot', 2.000000)", $sql );
        $this->assertStringContainsString( "('2026-09-10', 'sessions_by_bot', 'human', 5.000000)", $sql );
        $this->assertStringContainsString( "('2026-09-10', 'security_hits', 'scanner_ua', 6.000000)", $sql );
        $this->assertStringContainsString( "('2026-09-10', 'conversions', '', 2.000000)", $sql );
        $this->assertStringContainsString( "('2026-09-10', 'revenue', '', 129.900000)", $sql );
    }

    public function testQuietDayStillWritesDenseScalarRows(): void {
        $this->seed_reads(
            array(
                'session_totals' => array(),
                'by_country'     => array(),
                'by_channel'     => array(),
                'by_device'      => array(),
                'by_bot'         => array(),
                'security'       => array(),
                'conversions'    => array(),
            )
        );

        $written = Gr_Daily_Aggregator::aggregate_date( '2026-09-10' );

        $this->assertSame( 5, $written ); // scalars only, no dimension rows.

        $sql = implode( ' ', $this->upserts() );
        foreach ( array( 'sessions', 'visitors', 'pageviews', 'conversions', 'revenue' ) as $type ) {
            $this->assertStringContainsString( "('2026-09-10', '{$type}', '', 0.000000)", $sql );
        }
        $this->assertStringNotContainsString( 'sessions_by_', $sql );
        $this->assertStringNotContainsString( 'security_hits', $sql );
    }

    public function testBehaviorEventsFoldIntoTheirOwnMetricFamily(): void {
        $GLOBALS['wpdb']->var_result = '0';

        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, "event_group = 'behavior'" ) ) {
                return array(
                    array( 'metric_key' => 'dwell', 'metric_value' => '12' ),
                    array( 'metric_key' => 'rage_click', 'metric_value' => '2' ),
                );
            }

            return array();
        };

        $written = Gr_Daily_Aggregator::aggregate_date( '2026-09-10' );

        // 5 scalar rows + the two behavior dimension rows; the
        // query itself read the behavior group only.
        $this->assertSame( 7, $written );
        $sql = implode( ' ', $this->upserts() );
        $this->assertStringContainsString( "('2026-09-10', 'behavior_events', 'dwell', 12.000000)", $sql );
        $this->assertStringContainsString( "('2026-09-10', 'behavior_events', 'rage_click', 2.000000)", $sql );

        // The family source is the behavior group of the event
        // stream, its own statement beside the pageview count.
        $queries = implode( ' ', $GLOBALS['wpdb']->queries );
        $this->assertStringContainsString( "WHERE event_group = 'behavior'", $queries );
    }

    public function testRepeatedRunsReplaceInsteadOfAdding(): void {
        $this->seed_reads(
            array(
                'session_totals' => array( array( 'sessions' => '4', 'visitors' => '2' ) ),
                'by_country'     => array( array( 'metric_key' => 'US', 'metric_value' => '4' ) ),
                'by_channel'     => array( array( 'metric_key' => 'direct', 'metric_value' => '4' ) ),
                'by_device'      => array( array( 'metric_key' => 'desktop', 'metric_value' => '4' ) ),
                'by_bot'         => array( array( 'metric_key' => '0', 'metric_value' => '4' ) ),
                'security'       => array( array( 'metric_key' => 'blackhole', 'metric_value' => '3' ) ),
                'conversions'    => array( array( 'conversions' => '1', 'revenue' => '49.50' ) ),
            ),
            '9'
        );

        Gr_Daily_Aggregator::aggregate_date( '2026-09-10' );
        $first = $this->upserts();

        Gr_Daily_Aggregator::aggregate_date( '2026-09-10' );
        $second = $this->upserts();

        // Replacement semantics: the ON DUPLICATE KEY arm writes the
        // recomputed value, never adds to it, so the second pass emits
        // byte-identical statements — no doubled numbers anywhere.
        $this->assertSame( $first, $second );
        $this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE metric_value = VALUES(metric_value)', $first[0] );
        $this->assertStringNotContainsString( 'metric_value = metric_value +', $first[0] );
    }

    public function testRunCoversTheLookbackWindowAndStops(): void {
        $this->seed_reads(
            array(
                'session_totals' => array( array( 'sessions' => '1', 'visitors' => '1' ) ),
                'by_country'     => array(),
                'by_channel'     => array(),
                'by_device'      => array(),
                'by_bot'         => array(),
                'security'       => array(),
                'conversions'    => array(),
            )
        );

        // 2026-09-10 site-local noon; the window is 09-03..09-10.
        $GLOBALS['gr_stub_now'] = '2026-09-10 12:00:00';

        Gr_Daily_Aggregator::run();

        $dates = array();
        foreach ( $this->upserts() as $sql ) {
            preg_match_all( "/\('(\d{4}-\d{2}-\d{2})', 'sessions'/", $sql, $m );
            foreach ( $m[1] as $day ) {
                $dates[] = $day;
            }
        }

        $this->assertSame(
            array( '2026-09-03', '2026-09-04', '2026-09-05', '2026-09-06', '2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10' ),
            $dates,
            'oldest first, today last, exactly LOOKBACK_DAYS + 1 days'
        );
    }

    public function testDaysBeyondTheWindowAreNeverRevisited(): void {
        // A backfilled day (the public seam) settles once; after its
        // raw rows would be slimmed, the daily run must not touch it
        // again — recomputing a slimmed day would zero the report.
        $this->seed_reads(
            array(
                'session_totals' => array( array( 'sessions' => '5', 'visitors' => '2' ) ),
                'by_country'     => array(),
                'by_channel'     => array(),
                'by_device'      => array(),
                'by_bot'         => array(),
                'security'       => array(),
                'conversions'    => array(),
            )
        );

        Gr_Daily_Aggregator::aggregate_date( '2026-08-01' );
        $this->assertStringContainsString( "('2026-08-01', 'sessions', '', 5.000000)", implode( ' ', $this->upserts() ) );

        gr_stub_reset_options();
        $this->seed_reads(
            array(
                'session_totals' => array( array( 'sessions' => '0', 'visitors' => '0' ) ),
                'by_country'     => array(),
                'by_channel'     => array(),
                'by_device'      => array(),
                'by_bot'         => array(),
                'security'       => array(),
                'conversions'    => array(),
            )
        );
        $GLOBALS['gr_stub_now'] = '2026-09-10 12:00:00';

        Gr_Daily_Aggregator::run();

        // The slimmed day is outside the window; no statement the run
        // issued mentions it, so its settled value survives untouched.
        $this->assertStringNotContainsString( '2026-08-01', implode( ' ', $this->upserts() ) );
    }

    public function testInvalidDateIsRejectedWithoutQueries(): void {
        $this->seed_reads( array() );

        $this->assertSame( 0, Gr_Daily_Aggregator::aggregate_date( 'not-a-date' ) );
        $this->assertSame( 0, Gr_Daily_Aggregator::aggregate_date( '2026-9-1' ) );
        $this->assertSame( array(), $GLOBALS['wpdb']->queries );
    }

    public function testPluginRegistersTheAggregatorAheadOfDailyRiders(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        $found = null;
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            if ( Gr_Queue::DAILY_HOOK === (string) $registration['hook']
                && array( Gr_Daily_Aggregator::class, 'run' ) === $registration['callback'] ) {
                $found = (int) $registration['priority'];
            }
        }

        $this->assertNotNull( $found );
        $this->assertSame( 5, $found, 'aggregation must stay first on the daily chain' );
    }
}
