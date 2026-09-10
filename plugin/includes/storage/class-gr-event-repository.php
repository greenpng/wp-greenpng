<?php
/**
 * Events repository for the gr_events stream (docs/05 §2 #4): the only
 * code allowed to touch the event table; names resolve through
 * Gr_Database so no query hand-spells them.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Storage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Database;
use GreenPNG\Core\Gr_Event;

/**
 * Read/write access to the gr_events table (docs/05 §2 #4); repositories
 * are the only layer permitted to hold $wpdb (docs/02 §2.3).
 */
final class Gr_Event_Repository {

    /**
     * Upper bound for one feed read; keeps a fat-fingered limit from
     * turning into a full-table scan.
     */
    private const LIMIT_CEILING = 500;

    /**
     * Inserts one event row; the DTO is the only input surface.
     *
     * @param Gr_Event $event Event to persist.
     * @return int Row id, or 0 when the write failed (e.g. the table has
     *             not been created yet).
     */
    public function insert( Gr_Event $event ): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one row per real event; an object-cache layer would only duplicate the stream.
        $ok = $wpdb->insert(
            Gr_Database::table( 'events' ),
            array(
                'visitor_id'    => $event->visitor_id(),
                'session_id'    => $event->session_id(),
                'event_name'    => $event->name(),
                'event_group'   => $event->group(),
                'event_id'      => $event->event_id(),
                'payload_json'  => self::encode_payload( $event->payload() ),
                'ab_experiment' => $event->ab_experiment(),
                'ab_variant'    => $event->ab_variant(),
                'ab_type'       => $event->ab_type(),
                'created_at'    => $event->created_at(),
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( false === $ok || $wpdb->insert_id <= 0 ) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Reads the newest events, newest first; payload_json is decoded into
     * a 'payload' array key on every row.
     *
     * @param string $name  Optional event-name filter.
     * @param int    $limit Row ceiling, clamped to [1, 500].
     * @return array<int, array<string, mixed>>
     */
    public function recent( string $name = '', int $limit = 50 ): array {
        global $wpdb;

        $table = Gr_Database::table( 'events' );
        $limit = max( 1, min( $limit, self::LIMIT_CEILING ) );

        if ( '' !== $name ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input.
            $sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE event_name = %s ORDER BY id DESC LIMIT %d", $name, $limit );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input.
            $sql = $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the prepare() output of either branch above; detail-feed read for the admin list, report pages read the aggregated table (docs/05 §1).
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( ! is_array( $rows ) ) {
            return array();
        }

        foreach ( $rows as $index => $row ) {
            if ( is_array( $row ) ) {
                $row['payload'] = self::decode_payload( isset( $row['payload_json'] ) ? (string) $row['payload_json'] : '' );
                $rows[ $index ] = $row;
            }
        }

        return $rows;
    }

    /**
     * A/B arm counts for one experiment, grouped from the stream:
     * variant => type => count. Conversions without a matching
     * impression are still honest data — they count on their own.
     *
     * @param string $experiment Experiment key.
     * @return array<string, array<string, int>>
     */
    public function ab_counts( string $experiment ): array {
        global $wpdb;

        $table = Gr_Database::table( 'events' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- report-time aggregation on the ab_events index (ab_experiment, ab_type, created_at); admin reads only, never on a front-end request path.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on the first string line so this ignore reaches it.
                "SELECT ab_variant, ab_type, COUNT(*) AS n FROM {$table}
                    WHERE ab_experiment = %s AND ab_variant != '' AND ab_type != ''
                    GROUP BY ab_variant, ab_type",
                $experiment
            ),
            ARRAY_A
        );

        $counts = array();
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                if ( is_array( $row ) ) {
                    $variant = (string) ( $row['ab_variant'] ?? '' );
                    $type    = (string) ( $row['ab_type'] ?? '' );
                    if ( '' !== $variant && '' !== $type ) {
                        $counts[ $variant ][ $type ] = (int) ( $row['n'] ?? 0 );
                    }
                }
            }
        }

        return $counts;
    }

    /**
     * JSON-encodes the payload; a failed encode degrades to '' rather
     * than failing the whole insert.
     *
     * @param array<string, mixed> $payload Payload.
     * @return string
     */
    private static function encode_payload( array $payload ): string {
        $json = wp_json_encode( $payload );

        return is_string( $json ) ? $json : '';
    }

    /**
     * Decodes payload_json back to an array; malformed JSON degrades to
     * an empty array instead of failing the read.
     *
     * @param string $json Stored JSON.
     * @return array<string, mixed>
     */
    private static function decode_payload( string $json ): array {
        if ( '' === $json ) {
            return array();
        }

        $decoded = json_decode( $json, true );

        return is_array( $decoded ) ? $decoded : array();
    }
}
