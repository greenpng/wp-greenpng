<?php
/**
 * Read side of the daily summary (docs/05 §1): every report surface
 * reads gr_daily_stats and nothing else — the raw tables are the
 * aggregator's business alone, which is what lets retention slim
 * them without moving a single report number.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Storage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Database;

/**
 * Read-only queries over the summary table.
 */
final class Gr_Daily_Stats_Repository {

    /**
     * Upper bound for any window a caller asks for; the table is a
     * report source, not an archive browser.
     */
    private const MAX_DAYS = 90;

    /**
     * The five scalar metric types a trend reads, in display order.
     *
     * @var array<int, string>
     */
    private const SCALARS = array( 'sessions', 'visitors', 'pageviews', 'conversions', 'revenue' );

    /**
     * Per-day scalar series, oldest first, every day present (missing
     * values coalesce to 0 — the aggregator already writes dense rows,
     * this keeps the shape honest even for backfilled gaps).
     *
     * @param int $days Day count ending today, clamped to [1, 90].
     * @return array<string, array<string, int|float>> date => type => value.
     */
    public function series( int $days = 14 ): array {
        global $wpdb;

        $days  = max( 1, min( $days, self::MAX_DAYS ) );
        $table = Gr_Database::table( 'daily_stats' );
        $from  = gmdate( 'Y-m-d', (int) strtotime( substr( (string) current_time( 'mysql' ), 0, 10 ) . ' -' . ( $days - 1 ) . ' days' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin/REST report read, never a front-end request.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from the DDL registry, not user input.
                "SELECT stat_date, metric_type, metric_value FROM {$table} WHERE metric_type IN ('sessions', 'visitors', 'pageviews', 'conversions', 'revenue') AND stat_date >= %s ORDER BY stat_date ASC",
                $from
            ),
            ARRAY_A
        );

        // Dense frame: every date in the window exists even when the
        // summary table has no row for it.
        $frame = array();
        for ( $i = $days - 1; $i >= 0; $i-- ) {
            $day           = gmdate( 'Y-m-d', (int) strtotime( substr( (string) current_time( 'mysql' ), 0, 10 ) . ' -' . $i . ' days' ) );
            $frame[ $day ] = array_fill_keys( self::SCALARS, 0.0 );
        }

        foreach ( (array) $rows as $row ) {
            $day  = (string) $row['stat_date'];
            $type = (string) $row['metric_type'];
            if ( isset( $frame[ $day ] ) && in_array( $type, self::SCALARS, true ) ) {
                $frame[ $day ][ $type ] = (float) $row['metric_value'];
            }
        }

        return $frame;
    }

    /**
     * Dimension totals over a window, largest first: the country
     * distribution and every future breakdown read this shape.
     *
     * @param string $type  Dimension metric type (e.g. sessions_by_country).
     * @param int    $days  Window ending today, clamped to [1, 90].
     * @param int    $limit Row ceiling, clamped to [1, 50].
     * @return array<int, array<string, int|string>> ['key' =>, 'value' =>] rows.
     */
    public function dimension( string $type, int $days = 30, int $limit = 10 ): array {
        global $wpdb;

        $days  = max( 1, min( $days, self::MAX_DAYS ) );
        $limit = max( 1, min( $limit, 50 ) );
        $table = Gr_Database::table( 'daily_stats' );
        $from  = gmdate( 'Y-m-d', (int) strtotime( substr( (string) current_time( 'mysql' ), 0, 10 ) . ' -' . ( $days - 1 ) . ' days' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin/REST report read, never a front-end request.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from the DDL registry; the type is a literal from the class vocabulary, not user input.
                "SELECT metric_key, SUM(metric_value) AS total FROM {$table} WHERE metric_type = %s AND stat_date >= %s GROUP BY metric_key ORDER BY total DESC LIMIT %d",
                $type,
                $from,
                $limit
            ),
            ARRAY_A
        );

        $out = array();
        foreach ( (array) $rows as $row ) {
            $out[] = array(
                'key'   => (string) $row['metric_key'],
                'value' => (float) $row['total'],
            );
        }

        return $out;
    }
}
