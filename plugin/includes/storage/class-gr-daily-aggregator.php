<?php
/**
 * Daily aggregation (docs/05 §1/§5): folds the raw tables into
 * gr_daily_stats so every report reads the summary table and the raw
 * tables can be slimmed without changing a single report number. Runs
 * FIRST in the daily chain (priority 10; retention riders attach later)
 * and recomputes a short lookback window — the window must stay well
 * below the smallest default retention (30 days) so a recomputed day
 * always still has its raw rows; days beyond the window are never
 * revisited, which is what keeps slimmed history stable in the reports.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Storage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Queue;
use GreenPNG\Core\Gr_Database;

/**
 * Static job: subscribes to the daily maintenance hook and exposes a
 * per-date seam for tests and backfill.
 */
final class Gr_Daily_Aggregator {

    /**
     * Days recomputed besides today. Any day in the window is at most
     * this many days old, so its raw rows cannot have been slimmed at
     * default retention (>= 30); raising this above the smallest
     * retention would let recomputation read already-slimmed data.
     */
    public const LOOKBACK_DAYS = 7;

    /**
     * Hook attachment; priority 5 keeps aggregation strictly ahead of
     * every default-priority daily rider, present and future
     * (docs/05 §5: aggregate first, slim after) regardless of
     * registration order.
     *
     * @return void
     */
    public static function register(): void {
        add_action( Gr_Queue::DAILY_HOOK, array( self::class, 'run' ), 5, 0 );
        add_action( 'gr_recompute_conversion_date', array( self::class, 'recompute_conversions_for_date' ), 10, 1 );
    }

    /**
     * Recomputes every day in the lookback window, oldest first, so a
     * crashed run leaves the oldest days already settled. Day math
     * runs on the site-local date (the raw tables store local
     * current_time() values), derived from the mysql form because the
     * timestamp form is not a UTC epoch.
     *
     * @return void
     */
    public static function run(): void {
        $today = substr( (string) current_time( 'mysql' ), 0, 10 );

        for ( $ago = self::LOOKBACK_DAYS; $ago >= 0; $ago-- ) {
            self::aggregate_date( gmdate( 'Y-m-d', (int) strtotime( $today . ' -' . $ago . ' days' ) ) );
        }
    }

    /**
     * Aggregates one calendar day (site-local; the raw tables store
     * current_time() values) and upserts the metric rows.
     *
     * @param string $date Day to aggregate, 'Y-m-d'.
     * @return int Rows written (upserts included).
     */
    public static function aggregate_date( string $date ): int {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return 0;
        }

        $start = $date . ' 00:00:00';
        $end   = gmdate( 'Y-m-d H:i:s', (int) strtotime( $date . ' +1 day' ) );

        $rows = array_merge(
            self::session_metrics( $start, $end, $date ),
            self::pageview_metrics( $start, $end, $date ),
            self::behavior_metrics( $start, $end, $date ),
            self::security_metrics( $start, $end, $date ),
            self::conversion_metrics( $start, $end, $date )
        );

        return self::upsert( $rows );
    }

    /**
     * Session-derived metrics: the two scalars plus the four dimension
     * breakdowns the dashboard and campaign pages read.
     *
     * @param string $start Day start, 'Y-m-d H:i:s'.
     * @param string $end   Next day start, 'Y-m-d H:i:s'.
     * @param string $date  Day label.
     * @return array<int, array{0: string, 1: string, 2: string, 3: float}> Metric rows.
     */
    private static function session_metrics( string $start, string $end, string $date ): array {
        global $wpdb;

        $sessions = Gr_Database::table( 'sessions' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- daily batch job, never a front-end request.
        $totals = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from the DDL registry, not user input.
                "SELECT COUNT(*) AS sessions, COUNT(DISTINCT visitor_id) AS visitors FROM {$sessions} WHERE started_at >= %s AND started_at < %s",
                $start,
                $end
            ),
            ARRAY_A
        );

        if ( ! is_array( $totals ) ) {
            $totals = array(
                'sessions' => 0,
                'visitors' => 0,
            );
        }

        $rows = array(
            array( $date, 'sessions', '', (float) $totals['sessions'] ),
            array( $date, 'visitors', '', (float) $totals['visitors'] ),
        );

        $dimensions = array(
            'sessions_by_country' => 'country_code',
            'sessions_by_channel' => 'channel',
            'sessions_by_device'  => 'device_type',
        );

        foreach ( $dimensions as $type => $column ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- daily batch job, never a front-end request.
            $grouped = $wpdb->get_results(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and column names come from this class, not user input.
                    "SELECT {$column} AS metric_key, COUNT(*) AS metric_value FROM {$sessions} WHERE started_at >= %s AND started_at < %s GROUP BY {$column}",
                    $start,
                    $end
                ),
                ARRAY_A
            );

            foreach ( (array) $grouped as $hit ) {
                $rows[] = array( $date, $type, (string) $hit['metric_key'], (float) $hit['metric_value'] );
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- daily batch job, never a front-end request.
        $bots = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from the DDL registry, not user input.
                "SELECT is_bot AS metric_key, COUNT(*) AS metric_value FROM {$sessions} WHERE started_at >= %s AND started_at < %s GROUP BY is_bot",
                $start,
                $end
            ),
            ARRAY_A
        );

        foreach ( (array) $bots as $hit ) {
            $rows[] = array( $date, 'sessions_by_bot', ( '1' === (string) $hit['metric_key'] ) ? 'bot' : 'human', (float) $hit['metric_value'] );
        }

        return $rows;
    }

    /**
     * Page-view events are the only raw-event metric in v1.0; other
     * event names belong to their own consumers (A/B reads gr_events
     * itself, docs/13 U17).
     *
     * @param string $start Day start.
     * @param string $end   Next day start.
     * @param string $date  Day label.
     * @return array<int, array{0: string, 1: string, 2: string, 3: float}> Metric rows.
     */
    private static function pageview_metrics( string $start, string $end, string $date ): array {
        global $wpdb;

        $events = Gr_Database::table( 'events' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- daily batch job, never a front-end request.
        $count = $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from the DDL registry, not user input.
                "SELECT COUNT(*) FROM {$events} WHERE event_name = %s AND created_at >= %s AND created_at < %s",
                'pageview',
                $start,
                $end
            )
        );

        return array( array( $date, 'pageviews', '', (float) $count ) );
    }

    /**
     * Behavior events per name (ADR-0012 D4): the engagement and
     * friction vocabulary folded into one metric family whose key is
     * the event name, so the Behavior Insights tiles read the summary
     * table like every other report.
     *
     * @param string $start Day start.
     * @param string $end   Next day start.
     * @param string $date  Day label.
     * @return array<int, array{0: string, 1: string, 2: string, 3: float}> Metric rows.
     */
    private static function behavior_metrics( string $start, string $end, string $date ): array {
        global $wpdb;

        $events = Gr_Database::table( 'events' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- daily batch job, never a front-end request.
        $grouped = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from the DDL registry, not user input; it sits on the first string line so this ignore reaches it.
                "SELECT event_name AS metric_key, COUNT(*) AS metric_value FROM {$events} WHERE event_group = %s AND created_at >= %s AND created_at < %s GROUP BY event_name",
                'behavior',
                $start,
                $end
            ),
            ARRAY_A
        );

        $rows = array();
        foreach ( (array) $grouped as $hit ) {
            if ( is_array( $hit ) ) {
                $rows[] = array( $date, 'behavior_events', (string) $hit['metric_key'], (float) $hit['metric_value'] );
            }
        }

        return $rows;
    }

    /**
     * Security hits per rule, summed over the fold rows' hit_count;
     * a fold row lives inside one hourly window, so its hits belong to
     * the day of its last_seen.
     *
     * @param string $start Day start.
     * @param string $end   Next day start.
     * @param string $date  Day label.
     * @return array<int, array{0: string, 1: string, 2: string, 3: float}> Metric rows.
     */
    private static function security_metrics( string $start, string $end, string $date ): array {
        global $wpdb;

        $logs = Gr_Database::table( 'security_logs' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- daily batch job, never a front-end request.
        $grouped = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from the DDL registry, not user input.
                "SELECT rule_id AS metric_key, SUM(hit_count) AS metric_value FROM {$logs} WHERE last_seen >= %s AND last_seen < %s GROUP BY rule_id",
                $start,
                $end
            ),
            ARRAY_A
        );

        $rows = array();
        foreach ( (array) $grouped as $hit ) {
            $rows[] = array( $date, 'security_hits', (string) $hit['metric_key'], (float) $hit['metric_value'] );
        }

        return $rows;
    }

    /**
     * Targeted recompute for one date's conversion metrics only
     * (ADR-0010 D3): a refund can land years after the purchase, and
     * the other metric families of that old date would read already
     * slimmed raw tables — so this path deliberately recomputes just
     * conversions/revenue, whose source table keeps rows permanently.
     * The full aggregate_date() stays window-bound for that reason.
     *
     * @param string $date Day to recompute, 'Y-m-d'.
     * @return int Rows written.
     */
    public static function recompute_conversions_for_date( string $date ): int {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return 0;
        }

        $start = $date . ' 00:00:00';
        $end   = gmdate( 'Y-m-d H:i:s', (int) strtotime( $date . ' +1 day' ) );

        return self::upsert( self::conversion_metrics( $start, $end, $date ) );
    }

    /**
     * Conversion count and net revenue for the day. Counting stays
     * over all bound rows — the conversion happened, so the rate must
     * not move on a refund — while revenue sums only active rows
     * (ADR-0010 D3): the money actually kept.
     *
     * @param string $start Day start.
     * @param string $end   Next day start.
     * @param string $date  Day label.
     * @return array<int, array{0: string, 1: string, 2: string, 3: float}> Metric rows.
     */
    private static function conversion_metrics( string $start, string $end, string $date ): array {
        global $wpdb;

        $conversions = Gr_Database::table( 'conversions' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- daily batch job, never a front-end request.
        $totals = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from the DDL registry, not user input.
                "SELECT COUNT(*) AS conversions, COALESCE(SUM(CASE WHEN status = 'active' THEN amount ELSE 0 END), 0) AS revenue FROM {$conversions} WHERE created_at >= %s AND created_at < %s",
                $start,
                $end
            ),
            ARRAY_A
        );

        if ( ! is_array( $totals ) ) {
            $totals = array(
                'conversions' => 0,
                'revenue'     => 0,
            );
        }

        return array(
            array( $date, 'conversions', '', (float) $totals['conversions'] ),
            array( $date, 'revenue', '', (float) $totals['revenue'] ),
        );
    }

    /**
     * Batched idempotent upsert: the recomputation replaces the stored
     * value, so re-running a day never doubles it (docs/05 §1.4).
     *
     * @param array<int, array{0: string, 1: string, 2: string, 3: float}> $rows [date, type, key, value] tuples.
     * @return int Rows written.
     */
    private static function upsert( array $rows ): int {
        global $wpdb;

        if ( array() === $rows ) {
            return 0;
        }

        $table   = Gr_Database::table( 'daily_stats' );
        $base    = "INSERT INTO {$table} (stat_date, metric_type, metric_key, metric_value) VALUES ";
        $odku    = ' ON DUPLICATE KEY UPDATE metric_value = VALUES(metric_value)';
        $written = 0;

        foreach ( array_chunk( $rows, 100 ) as $batch ) {
            $values = array();
            $args   = array();

            foreach ( $batch as $row ) {
                $values[] = '(%s, %s, %s, %f)';
                $args[]   = $row[0];
                $args[]   = $row[1];
                $args[]   = $row[2];
                $args[]   = $row[3];
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- daily batch job, never a front-end request.
            $wpdb->query(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the placeholder list is assembled per row in $values above; every dynamic fragment is a literal or a placeholder.
                $wpdb->prepare( $base . implode( ', ', $values ) . $odku, $args )
            );

            $written += count( $batch );
        }

        return $written;
    }
}
