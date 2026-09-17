<?php
/**
 * Audit log repository for gr_audit_logs (docs/05 #13):
 * one row per admin write, carrying the diff computed at write time
 * in diff_json. The page reads the stored diff verbatim — old and
 * new snapshots are not re-diffed at display time, because a DB
 * round trip stringifies values and a re-diff would report type
 * changes no human made.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Storage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Audit_Diff;
use GreenPNG\Core\Gr_Database;

/**
 * Write and filtered, paginated read access to gr_audit_logs.
 */
final class Gr_Audit_Repository {

    /** Row cap clamp for reads; the CSV export rides the same clamp. */
    private const LIMIT_CEILING = 5000;

    /**
     * Writes one audit row: the diff of the two snapshots is computed
     * here, once, and stored as the row's only payload.
     *
     * @param string               $action      Short verb, e.g. 'add'.
     * @param string               $object_type Object family, e.g. 'access_rule'.
     * @param string               $object_id   Object identifier as text.
     * @param array<string, mixed> $before        Earlier state, array() for creations.
     * @param array<string, mixed> $after        Later state, array() for removals.
     * @param int                  $user_id     Acting user, 0 for system.
     * @return int Inserted row id, 0 when the write failed.
     */
    public function log( string $action, string $object_type, string $object_id, array $before, array $after, int $user_id = 0 ): int {
        global $wpdb;

        $table = Gr_Database::table( 'audit_logs' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- audit rows are append-only history; caching history would only serve stale truth.
        $written = $wpdb->insert(
            $table,
            array(
                'user_id'     => $user_id,
                'action'      => substr( $action, 0, 64 ),
                'object_type' => substr( $object_type, 0, 32 ),
                'object_id'   => substr( $object_id, 0, 64 ),
                'diff_json'   => (string) wp_json_encode( Gr_Audit_Diff::diff( $before, $after ) ),
                'created_at'  => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( false === $written ) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Filtered, newest-first page of audit rows plus the total that
     * the filter matches — pagination math needs both.
     *
     * @param array<string, mixed> $filters Whitelisted keys: user_id,
     *        object_type, object_id, action, from, to (Y-m-d, invalid
     *        formats are ignored), s (free search over action,
     *        object_type, object_id). Absent or empty filters stay
     *        out of the WHERE.
     * @param int                  $limit   Page size, clamped 1..5000.
     * @param int                  $offset  Row offset, at least 0.
     * @return array{rows: array<int, array<string, string>>, total: int}
     */
    public function query( array $filters = array(), int $limit = 50, int $offset = 0 ): array {
        global $wpdb;

        $limit  = max( 1, min( $limit, self::LIMIT_CEILING ) );
        $offset = max( 0, $offset );
        $table  = Gr_Database::table( 'audit_logs' );

        $clauses = array();
        $values  = array();
        if ( isset( $filters['user_id'] ) && (int) $filters['user_id'] > 0 ) {
            $clauses[] = 'user_id = %d';
            $values[]  = (int) $filters['user_id'];
        }
        foreach ( array( 'object_type', 'object_id', 'action' ) as $key ) {
            if ( isset( $filters[ $key ] ) && '' !== (string) $filters[ $key ] ) {
                $clauses[] = $key . ' = %s';
                $values[]  = substr( (string) $filters[ $key ], 0, 64 );
            }
        }
        $from = (string) ( $filters['from'] ?? '' );
        if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
            $clauses[] = 'created_at >= %s';
            $values[]  = $from . ' 00:00:00';
        }
        $to = (string) ( $filters['to'] ?? '' );
        if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
            $clauses[] = 'created_at <= %s';
            $values[]  = $to . ' 23:59:59';
        }
        $search = trim( (string) ( $filters['s'] ?? '' ) );
        if ( '' !== $search ) {
            $clauses[] = '(action LIKE %s OR object_type LIKE %s OR object_id LIKE %s)';
            $like      = '%' . $wpdb->esc_like( substr( $search, 0, 64 ) ) . '%';
            $values    = array_merge( $values, array( $like, $like, $like ) );
        }

        $where = array() === $clauses ? '' : 'WHERE ' . implode( ' AND ', $clauses );

        // With no filters the statement carries no placeholder, and
        // prepare() on a placeholder-less statement is a core
        // doing-it_wrong — so the plain count runs unprepared (no
        // user input ever joined it).
        if ( array() === $values ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- append-only history read; the only interpolation is the DDL table name, nothing else ever entered the statement.
            $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the clause list is built above from a fixed key whitelist, not from data; every value reaches prepare() below.
            $count_sql = $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $table is a DDL-validated identifier from Gr_Database, not user input; $where carries only the whitelisted clauses above, invisible to the sniffer as literal placeholders.
                "SELECT COUNT(*) FROM {$table} {$where}",
                $values
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- prepared above from the same fixed whitelist.
            $count = (int) $wpdb->get_var( $count_sql );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the clause list and the pagination pair join through one spread array, which the sniffer counts as a single replacement; the whitelist above already vetted every clause.
        $page_sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
            "SELECT id, user_id, action, object_type, object_id, diff_json, created_at FROM {$table} {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
            ...array_merge( $values, array( $limit, $offset ) )
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- prepared above; append-only history, page read.
        $rows = $wpdb->get_results( $page_sql, ARRAY_A );

        if ( ! is_array( $rows ) ) {
            $rows = array();
        }

        return array(
            'rows'  => array_values( array_filter( $rows, 'is_array' ) ),
            'total' => $count,
        );
    }
}
