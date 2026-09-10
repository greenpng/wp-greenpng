<?php
/**
 * Touchpoints repository for the gr_touchpoints table (docs/05 #5): the
 * cross-day attribution chain, one row per campaign entry. Writes are
 * plain inserts (the listener only records real entries), reads serve
 * the attribution models with the visitor's ordered sequence.
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
 * Read/write access to gr_touchpoints; repositories are the only layer
 * permitted to hold $wpdb (docs/02 §2.3).
 */
final class Gr_Touchpoint_Repository {

    /** Upper clamp for sequence reads. */
    private const LIMIT_CEILING = 500;

    /** Lower clamp for the read window in days. */
    private const DAYS_FLOOR = 1;

    /** Upper clamp for the read window in days. */
    private const DAYS_CEILING = 365;

    /**
     * Records one touchpoint row. String columns are clamped to their
     * schema widths so an oversized value can never fail the whole
     * insert under a strict SQL mode.
     *
     * @param string               $visitor_id Visitor identity (cookie track).
     * @param array<string, mixed> $params     Parsed attribution columns.
     * @param string               $session_id Visit identity.
     * @param string               $url        Landing URL.
     * @return int Inserted row id, or 0 when the write failed.
     */
    public function record( string $visitor_id, array $params, string $session_id = '', string $url = '' ): int {
        global $wpdb;

        $table = Gr_Database::table( 'touchpoints' );
        $now   = current_time( 'mysql' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one row per real campaign entry; an object-cache layer would only duplicate the chain.
        $written = $wpdb->insert(
            $table,
            array(
                'visitor_id'    => substr( $visitor_id, 0, 64 ),
                'session_id'    => substr( $session_id, 0, 36 ),
                'channel'       => substr( (string) ( $params['channel'] ?? 'direct' ), 0, 32 ),
                'utm_source'    => substr( (string) ( $params['utm_source'] ?? '' ), 0, 191 ),
                'utm_medium'    => substr( (string) ( $params['utm_medium'] ?? '' ), 0, 191 ),
                'utm_campaign'  => substr( (string) ( $params['utm_campaign'] ?? '' ), 0, 191 ),
                'utm_term'      => substr( (string) ( $params['utm_term'] ?? '' ), 0, 191 ),
                'utm_content'   => substr( (string) ( $params['utm_content'] ?? '' ), 0, 191 ),
                'click_id'      => substr( (string) ( $params['click_id'] ?? '' ), 0, 191 ),
                'landing_url'   => substr( $url, 0, 191 ),
                'referrer_host' => substr( (string) ( $params['referrer_host'] ?? '' ), 0, 191 ),
                'created_at'    => $now,
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( false === $written ) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * The visitor's ordered touchpoint sequence inside the window.
     *
     * @param string $visitor_id Visitor identity.
     * @param int    $days       Window in days, clamped 1..365.
     * @return array<int, array<string, mixed>> Rows keyed by column name.
     */
    public function get_for_visitor( string $visitor_id, int $days = 30 ): array {
        global $wpdb;

        $days  = max( self::DAYS_FLOOR, min( $days, self::DAYS_CEILING ) );
        $table = Gr_Database::table( 'touchpoints' );

        // Explicit UTC arithmetic: the cutoff must not depend on the
        // runtime timezone (the C5 lesson).
        $cutoff = ( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) )
            ->modify( '-' . $days . ' days' )
            ->format( 'Y-m-d H:i:s' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- attribution sequences are per-visitor reads over the visitor_time index; caching would only duplicate rows the models need fresh.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "SELECT id, channel, utm_source, utm_medium, utm_campaign, click_id, landing_url, referrer_host, created_at FROM {$table}
                WHERE visitor_id = %s AND created_at >= %s
                ORDER BY created_at ASC, id ASC
                LIMIT %d",
                array(
                    $visitor_id,
                    $cutoff,
                    self::LIMIT_CEILING,
                )
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : array();
    }
}
