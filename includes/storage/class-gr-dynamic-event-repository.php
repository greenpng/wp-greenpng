<?php
/**
 * Dynamic event rule store (docs/03 §9, docs/05 table 14): the owner
 * configured hook-listening rules, one bounded row each. Only rules
 * the admin surface validated reach this table; the sniffer reads
 * the active subset and mounts nothing on front-end requests.
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
 * Read/write access to the gr_dynamic_events table.
 */
final class Gr_Dynamic_Event_Repository {

    /** Rule cap: sniffing is a bounded diagnostic surface, not a pipeline. */
    public const CAP = 50;

    /** Longest accepted hook name, matching the column width. */
    public const HOOK_MAX = 191;

    /** Longest accepted event name, matching the column width. */
    public const EVENT_MAX = 64;

    /** Longest accepted param-map expression. */
    public const EXPR_MAX = 191;

    /** Longest accepted param key. */
    public const KEY_MAX = 64;

    /** Most param-map entries per rule. */
    public const MAP_MAX = 16;

    /**
     * Every rule row, oldest first, with the param map decoded.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array {
        global $wpdb;
        $table = Gr_Database::table( 'dynamic_events' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- bounded admin-surface config read; the only interpolation is the DDL table identifier from Gr_Database, never user input.
        $rows = $wpdb->get_results( "SELECT id, hook_name, event_name, param_map_json, is_active, created_at FROM {$table} ORDER BY id ASC", ARRAY_A );
        if ( ! is_array( $rows ) ) {
            return array();
        }

        $out = array();
        foreach ( $rows as $row ) {
            $out[] = self::hydrate( (array) $row );
        }

        return $out;
    }

    /**
     * The active subset the sniffer mounts.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function active(): array {
        return array_values(
            array_filter(
                self::all(),
                static function ( array $rule ): bool {
                    return 1 === (int) $rule['is_active'];
                }
            )
        );
    }

    /**
     * One rule row, or null when the id is gone.
     *
     * @param int $id Rule id.
     * @return array<string, mixed>|null
     */
    public static function find( int $id ): ?array {
        foreach ( self::all() as $rule ) {
            if ( $id === (int) $rule['id'] ) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Inserts one rule after shape validation. Returns the new row
     * id, or 0 when the rule is refused; the page renders the refusal
     * reasons, the repository stays the last line of defense.
     *
     * @param string               $hook      Hook name.
     * @param string               $event     Event name, '' for audit-only.
     * @param array<string, mixed> $param_map Payload key => expression.
     * @return int
     */
    public static function add( string $hook, string $event, array $param_map ): int {
        $hook  = trim( $hook );
        $event = trim( $event );

        if ( ! self::valid_hook( $hook ) || ! self::valid_event( $event ) ) {
            return 0;
        }

        $map = self::clean_map( $param_map );
        if ( null === $map ) {
            return 0;
        }

        if ( count( self::all() ) >= self::CAP ) {
            return 0;
        }

        global $wpdb;
        $table = Gr_Database::table( 'dynamic_events' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- audited admin write through core's insert helper.
        $ok = $wpdb->insert(
            $table,
            array(
                'hook_name'      => $hook,
                'event_name'     => $event,
                'param_map_json' => wp_json_encode( $map ),
                'is_active'      => 1,
                'created_at'     => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%d', '%s' )
        );

        return false === $ok ? 0 : (int) $wpdb->insert_id;
    }

    /**
     * Flips one rule between active and inactive.
     *
     * @param int $id Rule id.
     * @return bool
     */
    public static function toggle( int $id ): bool {
        $rule = self::find( $id );
        if ( null === $rule ) {
            return false;
        }

        global $wpdb;
        $table = Gr_Database::table( 'dynamic_events' );
        $next  = 1 === (int) $rule['is_active'] ? 0 : 1;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- audited admin write; the WHERE id and the flipped flag both reach prepare() as values.
        $result = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "UPDATE {$table} SET is_active = %d WHERE id = %d",
                $next,
                $id
            )
        );

        return false !== $result;
    }

    /**
     * Removes one rule.
     *
     * @param int $id Rule id.
     * @return bool
     */
    public static function delete( int $id ): bool {
        global $wpdb;
        $table = Gr_Database::table( 'dynamic_events' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- audited admin write; the WHERE id reaches prepare() as a value.
        $result = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "DELETE FROM {$table} WHERE id = %d",
                $id
            )
        );

        return false !== $result;
    }

    /**
     * Hook-name gate: the characters hook names are made of, within
     * the column width.
     *
     * @param string $hook Hook name.
     * @return bool
     */
    public static function valid_hook( string $hook ): bool {
        return 1 === preg_match( '/^[A-Za-z0-9_\/\.\-]{1,' . self::HOOK_MAX . '}$/', $hook );
    }

    /**
     * Event-name gate: same vocabulary, may be empty (audit-only).
     *
     * @param string $event Event name.
     * @return bool
     */
    public static function valid_event( string $event ): bool {
        return '' === $event || 1 === preg_match( '/^[A-Za-z0-9_\.]{1,' . self::EVENT_MAX . '}$/', $event );
    }

    /**
     * Normalizes a param map: slug keys, bounded expressions, at
     * most MAP_MAX entries. Returns null when the map is refused.
     *
     * @param array<string, mixed> $param_map Raw map.
     * @return array<string, string>|null
     */
    private static function clean_map( array $param_map ): ?array {
        if ( count( $param_map ) > self::MAP_MAX ) {
            return null;
        }

        $out = array();
        foreach ( $param_map as $key => $expr ) {
            $key   = sanitize_key( (string) $key );
            $expr  = is_scalar( $expr ) ? trim( (string) $expr ) : '';
            $clean = self::valid_expression( $expr );
            if ( '' === $key || null === $clean ) {
                return null;
            }
            $out[ $key ] = $clean;
        }

        return $out;
    }

    /**
     * Expression gate: bounded, no control characters.
     *
     * @param string $expr Raw expression.
     * @return string|null
     */
    private static function valid_expression( string $expr ) {
        if ( '' === $expr || strlen( $expr ) > self::EXPR_MAX ) {
            return null;
        }

        return 1 === preg_match( '/^[\P{Cc}]+$/', $expr ) ? $expr : null;
    }

    /**
     * Decodes one raw row into the public shape.
     *
     * @param array<string, mixed> $row Raw row.
     * @return array<string, mixed>
     */
    private static function hydrate( array $row ): array {
        $map = json_decode( (string) ( $row['param_map_json'] ?? '' ), true );
        if ( ! is_array( $map ) ) {
            $map = array();
        }

        return array(
            'id'         => (int) ( $row['id'] ?? 0 ),
            'hook_name'  => (string) ( $row['hook_name'] ?? '' ),
            'event_name' => (string) ( $row['event_name'] ?? '' ),
            'param_map'  => $map,
            'is_active'  => (int) ( $row['is_active'] ?? 0 ),
            'created_at' => (string) ( $row['created_at'] ?? '' ),
        );
    }
}
