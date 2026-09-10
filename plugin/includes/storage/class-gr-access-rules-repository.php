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
 * Read access to the gr_access_rules table.
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
}
