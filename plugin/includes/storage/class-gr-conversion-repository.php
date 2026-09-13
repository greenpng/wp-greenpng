<?php
/**
 * Conversions repository for the gr_conversions table (docs/05 §3.3):
 * the permanent order/form ↔ attribution binding. Writes are INSERT
 * IGNORE against the source_unique key, so a replayed callback can
 * never produce a second row; the first binding wins and is returned
 * to every caller thereafter.
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
 * Read/write access to gr_conversions; repositories are the only layer
 * permitted to hold $wpdb (docs/02 §2.3).
 */
final class Gr_Conversion_Repository {

    /**
     * Binds one conversion source. Idempotent by the UNIQUE
     * (source_type, source_id) key: a fresh insert returns the new row
     * id; a replay returns the already-bound row's id; a write that
     * fails outright returns 0.
     *
     * @param string $source_type      'woocommerce' or a form adapter id.
     * @param int    $source_id        Order or form submission id.
     * @param string $visitor_id       Visitor identity at conversion time.
     * @param string $session_id       Session identity at conversion time.
     * @param float  $amount           Conversion amount.
     * @param string $currency         Three-letter currency code.
     * @param int    $first_touch_id   Earliest touchpoint id, 0 when direct.
     * @param int    $last_touch_id    Latest touchpoint id, 0 when direct.
     * @param string $model_weights_json Five-model split, JSON-encoded.
     * @return int Bound row id, existing id on replay, or 0 on failure.
     */
    public function bind(
        string $source_type,
        int $source_id,
        string $visitor_id,
        string $session_id,
        float $amount,
        string $currency,
        int $first_touch_id,
        int $last_touch_id,
        string $model_weights_json
    ): int {
        global $wpdb;

        $table = Gr_Database::table( 'conversions' );
        $now   = current_time( 'mysql' );

        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input.
            "INSERT IGNORE INTO {$table}
                (source_type, source_id, session_id, visitor_id, amount, currency, first_touch_id, last_touch_id, model_weights, created_at)
            VALUES (%s, %d, %s, %s, %s, %s, %d, %d, %s, %s)",
            array(
                substr( $source_type, 0, 16 ),
                $source_id,
                substr( $session_id, 0, 36 ),
                substr( $visitor_id, 0, 64 ),
                number_format( round( $amount, 2 ), 2, '.', '' ),
                strtoupper( substr( $currency, 0, 3 ) ),
                $first_touch_id,
                $last_touch_id,
                $model_weights_json,
                $now,
            )
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the prepare() output above; the binding is the idempotency boundary (docs/05 §3.3) and each source writes exactly once.
        $wpdb->query( $sql );

        $id = (int) $wpdb->insert_id;
        if ( $id > 0 ) {
            return $id;
        }

        // Replayed callback (UNIQUE swallowed the insert) or a failed
        // write: the existing binding decides.
        return $this->id_for_source( $source_type, $source_id );
    }

    /**
     * The row id already bound to a source, 0 when none exists.
     *
     * @param string $source_type Source type.
     * @param int    $source_id   Source id.
     * @return int
     */
    public function id_for_source( string $source_type, int $source_id ): int {
        global $wpdb;

        $table = Gr_Database::table( 'conversions' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- point lookup on the UNIQUE source_unique key; the result is stable for a bound source by definition.
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input.
                "SELECT id FROM {$table} WHERE source_type = %s AND source_id = %d",
                array( substr( $source_type, 0, 16 ), $source_id )
            )
        );
    }

    /**
     * Newest bound conversions with the split they were recorded
     * with: the attribution comparison reads the snapshot that rode
     * with the binding, never a recomputation over today's chain
     * (touches landing after a conversion must not rewrite history).
     *
     * @param int $days  Window in days, clamped 1..365.
     * @param int $limit Row cap, newest first.
     * @return array<int, array<string, string>> Rows keyed by column.
     */
    public function recent( int $days, int $limit = 200 ): array {
        global $wpdb;

        $days  = max( 1, min( $days, 365 ) );
        $limit = max( 1, min( $limit, 500 ) );
        $table = Gr_Database::table( 'conversions' );

        $cutoff = ( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) )
            ->modify( '-' . $days . ' days' )
            ->format( 'Y-m-d H:i:s' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin report read over the created index; caching would duplicate a fresh aggregate.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "SELECT id, source_type, source_id, visitor_id, amount, currency, model_weights, created_at FROM {$table}
                WHERE created_at >= %s
                ORDER BY created_at DESC, id DESC
                LIMIT %d",
                array(
                    $cutoff,
                    $limit,
                )
            ),
            ARRAY_A
        );

        if ( ! is_array( $rows ) ) {
            return array();
        }

        return array_values( array_filter( $rows, 'is_array' ) );
    }

    /**
     * All conversion rows for one visitor, oldest first — the WP
     * privacy export read. The weights JSON travels as stored; the
     * export page shows it as the attribution record it is.
     *
     * @param string $visitor_id Visitor identity.
     * @return array<int, array<string, mixed>>
     */
    public function rows_for_visitor( string $visitor_id ): array {
        global $wpdb;

        if ( '' === $visitor_id ) {
            return array();
        }

        $table = Gr_Database::table( 'conversions' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy-tool read over the visitor index, off the front-end path.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "SELECT id, source_type, source_id, session_id, amount, currency, model_weights, created_at FROM {$table}
                WHERE visitor_id = %s
                ORDER BY created_at ASC, id ASC",
                $visitor_id
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
    }

    /**
     * Deletes every conversion row for one visitor — the privacy
     * erasure arm. Order meta binding is the caller's to remove (it
     * lives in WooCommerce's store, not this table).
     *
     * @param string $visitor_id Visitor identity.
     * @return int Rows removed.
     */
    public function delete_for_visitor( string $visitor_id ): int {
        global $wpdb;

        if ( '' === $visitor_id ) {
            return 0;
        }

        $table = Gr_Database::table( 'conversions' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy-tool erasure, owner-initiated only.
        return (int) $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "DELETE FROM {$table} WHERE visitor_id = %s",
                $visitor_id
            )
        );
    }
}
