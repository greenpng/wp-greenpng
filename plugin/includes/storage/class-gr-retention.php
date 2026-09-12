<?php
/**
 * Data retention engine (docs/05 §5): the daily rider that trims
 * high-growth tables on two independent rails — age (retention_days)
 * and row ceiling (retention_rows), whichever fires first — plus the
 * manual-only OPTIMIZE entry point. The cron rider never optimizes:
 * InnoDB table rebuilds lock, and a scheduled lock is a self-inflicted
 * outage, so rebuilding happens only when the site owner presses the
 * button and accepts the cost.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Storage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Database;
use GreenPNG\Core\Gr_Queue;
use GreenPNG\Core\Gr_Settings;

/**
 * Prune rider and manual optimizer.
 */
final class Gr_Retention {

    /** Wall-clock budget for one daily run (docs/05 §5: 10s). */
    public const TIME_BUDGET = 10.0;

    /** Rows per DELETE statement (docs/05 §5: 2000). */
    public const BATCH = 2000;

    /** Pause between batches (docs/05 §5: 100ms). */
    private const SLEEP_US = 100000;

    /**
     * The prunable tables and the column their age is read from
     * (docs/05 §2). Static tables (rules, funnels, contacts, tags,
     * conversions) never appear here — user data and aggregated
     * history are not the rider's business.
     *
     * @var array<string, string>
     */
    private const DATE_COLUMNS = array(
        'security_logs'     => 'last_seen',
        'sessions'          => 'started_at',
        'events'            => 'created_at',
        'touchpoints'       => 'created_at',
        'funnel_sessions'   => 'entered_at',
        'cart_abandonments' => 'captured_at',
        'audit_logs'        => 'created_at',
        'daily_stats'       => 'stat_date',
    );

    /**
     * Prunable table keys in canonical order, for the settings page.
     *
     * @return array<int, string>
     */
    public static function prunable_tables(): array {
        return array_keys( self::DATE_COLUMNS );
    }

    /**
     * The age column for one prunable table.
     *
     * @param string $table_key Short table key.
     * @return string Column name, '' when the table is not prunable.
     */
    public static function date_column( string $table_key ): string {
        return self::DATE_COLUMNS[ $table_key ] ?? '';
    }

    /**
     * Daily rider on the maintenance hook, after the aggregator (5):
     * summaries land first, trimming never eats data the report has
     * not yet absorbed.
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( Gr_Queue::DAILY_HOOK, array( self::class, 'run_daily' ), 10, 0 );
    }

    /**
     * One maintenance pass over both rails. The budget is a wall: a
     * pass that runs out of time leaves the rest for tomorrow —
     * trimming is convergent, not transactional.
     *
     * @param float|null $budget Seconds; null = the documented default.
     * @return void
     */
    public static function run_daily( ?float $budget = null ): void {
        $settings = new Gr_Settings();
        $days     = (array) $settings->get( 'retention_days', array() );
        $rows     = (array) $settings->get( 'retention_rows', array() );
        $deadline = microtime( true ) + ( $budget ?? self::TIME_BUDGET );

        foreach ( self::DATE_COLUMNS as $key => $date_col ) {
            $keep_days = isset( $days[ $key ] ) ? max( 0, (int) $days[ $key ] ) : 0;
            $keep_rows = isset( $rows[ $key ] ) ? max( 0, (int) $rows[ $key ] ) : 0;

            if ( $keep_days > 0 && microtime( true ) < $deadline ) {
                self::prune( $key, $date_col, $keep_days, self::BATCH, $deadline );
            }

            if ( $keep_rows > 0 && microtime( true ) < $deadline ) {
                self::prune_rows( $key, $keep_rows, self::BATCH, $deadline );
            }
        }
    }

    /**
     * Age rail: deletes rows older than the retention window, oldest
     * first, in bounded batches. Days <= 0 means "keep everything" —
     * the documented 0 = days-only convention also guards the call.
     *
     * @param string      $table_key     Short table key.
     * @param string      $date_col      Age column; identifier-shaped
     *                                   or the call refuses to run.
     * @param int         $retention_days Days to keep; 0 keeps all.
     * @param int         $batch         Rows per statement.
     * @param float|null  $deadline      Wall-clock stop; null = no limit.
     * @return int Rows deleted.
     */
    public static function prune( string $table_key, string $date_col, int $retention_days, int $batch = self::BATCH, ?float $deadline = null ): int {
        global $wpdb;

        if ( $retention_days <= 0 || '' === $date_col || 1 !== preg_match( '/^[a-z_]+$/', $date_col ) ) {
            return 0;
        }

        $batch  = max( 1, min( $batch, self::BATCH ) );
        $table  = Gr_Database::table( $table_key );
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $retention_days * DAY_IN_SECONDS );

        $total = 0;
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- maintenance rider; both interpolations are ours (DDL-registered table, validated identifier column), every value reaches prepare() below.
            $deleted = (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table} WHERE {$date_col} < %s ORDER BY id ASC LIMIT %d",
                    $cutoff,
                    $batch
                )
            );
            $total += $deleted;

            if ( $deleted < $batch ) {
                break;
            }
            if ( null !== $deadline && microtime( true ) >= $deadline ) {
                break;
            }
            usleep( self::SLEEP_US );
        } while ( true );

        return $total;
    }

    /**
     * Ceiling rail: keeps the newest max_rows rows, deletes the rest.
     * The boundary is read once per pass, then batches walk it down.
     *
     * @param string     $table_key Short table key.
     * @param int        $max_rows  Rows to keep; 0 disables the rail.
     * @param int        $batch     Rows per statement.
     * @param float|null $deadline  Wall-clock stop; null = no limit.
     * @return int Rows deleted.
     */
    public static function prune_rows( string $table_key, int $max_rows, int $batch = self::BATCH, ?float $deadline = null ): int {
        global $wpdb;

        if ( $max_rows <= 0 ) {
            return 0;
        }

        $batch = max( 1, min( $batch, self::BATCH ) );
        $table = Gr_Database::table( $table_key );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- maintenance rider; the table name is DDL-registered, the LIMIT pair is derived from counts.
        $boundary = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} ORDER BY id DESC LIMIT %d, 1",
                $max_rows
            )
        );
        if ( null === $boundary || (int) $boundary <= 0 ) {
            return 0;
        }
        $boundary = (int) $boundary;

        $total = 0;
        do {
            // The boundary row is the first row the ceiling ejects
            // (the max_rows+1-th newest), so the delete includes it:
            // at max_rows+1 total rows this removes exactly one.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- maintenance rider; the table name is DDL-registered, the boundary id is a read of our own primary key.
            $deleted = (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table} WHERE id <= %d ORDER BY id ASC LIMIT %d",
                    $boundary,
                    $batch
                )
            );
            $total += $deleted;

            if ( $deleted < $batch ) {
                break;
            }
            if ( null !== $deadline && microtime( true ) >= $deadline ) {
                break;
            }
            usleep( self::SLEEP_US );
        } while ( true );

        return $total;
    }

    /**
     * Manual-only table rebuild. Nothing scheduled ever calls this:
     * OPTIMIZE rewrites the table and locks it while it does, so the
     * cost is paid exactly when a human chose to pay it.
     *
     * @param string $table_key Short table key.
     * @return bool
     */
    public static function optimize( string $table_key ): bool {
        global $wpdb;

        $table = Gr_Database::table( $table_key );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- owner-initiated manual maintenance; the table name is DDL-registered, the statement carries no data.
        return false !== $wpdb->query( "OPTIMIZE TABLE {$table}" );
    }

    /**
     * Live row counts for the prunable tables, for the settings page.
     *
     * @return array<string, int>
     */
    public static function counts(): array {
        global $wpdb;

        $out = array();
        foreach ( array_keys( self::DATE_COLUMNS ) as $key ) {
            $table = Gr_Database::table( $key );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin page read of maintenance state.
            $out[ $key ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        }

        return $out;
    }
}
