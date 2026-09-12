<?php
/**
 * Access rules repository (docs/05 §2 #2, docs/13 W4): the only layer
 * that touches $wpdb for the gr_access_rules table. The read here is
 * the one-shot active-rule list the security layer memoizes per
 * request; write operations join with the admin Access Rules page in
 * the U phase.
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
 * Read and write access to the gr_access_rules table.
 */
final class Gr_Access_Rules_Repository {

    /**
     * Every active rule in id order, carrying only the columns the
     * matching predicates consume. A missing table or failed read
     * degrades to an empty list — no rule ever fails closed.
     *
     * @return array<int, array<string, string>> rule_type/match_kind/match_value rows.
     */
    public function active_rules(): array {
        global $wpdb;

        $table = Gr_Database::table( 'access_rules' );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is a DDL-validated identifier from Gr_Database, not user input; static rows the caller memoizes once per request (docs/09 §1.1's 0~1 line), an object-cache layer would only duplicate that.
        $rows = $wpdb->get_results( "SELECT rule_type, match_kind, match_value FROM {$table} WHERE is_active = 1 ORDER BY id ASC", ARRAY_A );

        if ( ! is_array( $rows ) ) {
            return array();
        }

        $rules = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $rules[] = array(
                'rule_type'   => isset( $row['rule_type'] ) && is_scalar( $row['rule_type'] ) ? (string) $row['rule_type'] : '',
                'match_kind'  => isset( $row['match_kind'] ) && is_scalar( $row['match_kind'] ) ? (string) $row['match_kind'] : '',
                'match_value' => isset( $row['match_value'] ) && is_scalar( $row['match_value'] ) ? (string) $row['match_value'] : '',
            );
        }

        return $rules;
    }

    /**
     * Every rule of one type, inactive included — the admin list
     * manages state, so hiding rows would hide the work.
     *
     * @param string $type TYPE_ALLOW or TYPE_BAN.
     * @return array<int, array<string, string>> Full-column rows, id order.
     */
    public function rules_of_type( string $type ): array {
        global $wpdb;

        $table = Gr_Database::table( 'access_rules' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin list read, never a front-end request.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from the DDL registry, not user input.
                "SELECT id, rule_type, match_kind, match_value, note, is_active, created_by, created_at, updated_at FROM {$table} WHERE rule_type = %s ORDER BY id ASC",
                $type
            ),
            ARRAY_A
        );

        if ( ! is_array( $rows ) ) {
            return array();
        }

        return array_values( array_filter( $rows, 'is_array' ) );
    }

    /**
     * Insert one rule; the caller has already validated the shape.
     *
     * @param string $type TYPE_ALLOW or TYPE_BAN.
     * @param string $kind KIND_IP or KIND_URL.
     * @param string $value Match value.
     * @param string $note  Owner-facing note.
     * @return int New row id, or 0 on failure.
     */
    public function add( string $type, string $kind, string $value, string $note = '' ): int {
        global $wpdb;

        $table = Gr_Database::table( 'access_rules' );
        $now   = current_time( 'mysql' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- owner-initiated admin write, one row.
        $ok = $wpdb->insert(
            $table,
            array(
                'rule_type'   => $type,
                'match_kind'  => $kind,
                'match_value' => substr( $value, 0, 191 ),
                'note'        => substr( $note, 0, 191 ),
                'is_active'   => 1,
                'created_by'  => get_current_user_id(),
                'created_at'  => $now,
                'updated_at'  => $now,
            ),
            array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
        );

        if ( false === $ok || $wpdb->insert_id <= 0 ) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Deletes one rule; the type rides the WHERE clause so a stale
     * tab URL can never reach into the other list.
     *
     * @param int    $id   Rule id.
     * @param string $type Rule type the caller believes it edits.
     * @return bool
     */
    public function delete( int $id, string $type ): bool {
        global $wpdb;

        $table = Gr_Database::table( 'access_rules' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- owner-initiated admin write.
        $count = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from the DDL registry, not user input.
                "DELETE FROM {$table} WHERE id = %d AND rule_type = %s",
                $id,
                $type
            )
        );

        return 1 === (int) $count;
    }

    /**
     * Flips one rule's active flag; type rides the WHERE like delete.
     *
     * @param int    $id     Rule id.
     * @param string $type   Rule type.
     * @param bool   $active Target state.
     * @return bool
     */
    public function set_active( int $id, string $type, bool $active ): bool {
        global $wpdb;

        $table = Gr_Database::table( 'access_rules' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- owner-initiated admin write.
        $count = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from the DDL registry, not user input.
                "UPDATE {$table} SET is_active = %d, updated_at = %s WHERE id = %d AND rule_type = %s",
                $active ? 1 : 0,
                current_time( 'mysql' ),
                $id,
                $type
            )
        );

        return 1 === (int) $count;
    }
}
