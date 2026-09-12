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

    /**
     * Campaign entries grouped by campaign name and carrier: entries
     * count touchpoint rows, visitors the distinct identities behind
     * them. Campaigns here mean named utm_campaign values — direct
     * traffic has no campaign and stays off this breakdown.
     *
     * @param int $days  Window in days, clamped 1..365.
     * @param int $limit Row cap, most entries first.
     * @return array<int, array<string, string|int>> Rows keyed by column.
     */
    public function campaign_breakdown( int $days, int $limit = 30 ): array {
        global $wpdb;

        list($cutoff, $limit, $table) = $this->read_frame( $days, $limit );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin report read over the campaign index; caching would duplicate a fresh aggregate.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "SELECT utm_campaign, channel, utm_source, utm_medium, COUNT(*) AS entries, COUNT(DISTINCT visitor_id) AS visitors, MIN(created_at) AS first_seen, MAX(created_at) AS last_seen FROM {$table}
                WHERE utm_campaign <> '' AND created_at >= %s
                GROUP BY utm_campaign, channel, utm_source, utm_medium
                ORDER BY entries DESC, last_seen DESC
                LIMIT %d",
                array(
                    $cutoff,
                    $limit,
                )
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
    }

    /**
     * UTM parameter tuples that actually carried a parameter, grouped
     * by the full five-part tuple — the classic source/medium table.
     *
     * @param int $days  Window in days, clamped 1..365.
     * @param int $limit Row cap, most entries first.
     * @return array<int, array<string, string|int>> Rows keyed by column.
     */
    public function utm_breakdown( int $days, int $limit = 30 ): array {
        global $wpdb;

        list($cutoff, $limit, $table) = $this->read_frame( $days, $limit );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin report read over the campaign index; caching would duplicate a fresh aggregate.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "SELECT utm_source, utm_medium, utm_campaign, utm_term, utm_content, COUNT(*) AS entries, COUNT(DISTINCT visitor_id) AS visitors, MAX(created_at) AS last_seen FROM {$table}
                WHERE (utm_source <> '' OR utm_medium <> '' OR utm_campaign <> '' OR utm_term <> '' OR utm_content <> '') AND created_at >= %s
                GROUP BY utm_source, utm_medium, utm_campaign, utm_term, utm_content
                ORDER BY entries DESC, last_seen DESC
                LIMIT %d",
                array(
                    $cutoff,
                    $limit,
                )
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
    }

    /**
     * Click-id carriers: the surviving click parameter value grouped
     * with the channel it produced (cpc for search ids, social for
     * social ids — the taxonomy the parser already closed).
     *
     * @param int $days  Window in days, clamped 1..365.
     * @param int $limit Row cap, most entries first.
     * @return array<int, array<string, string|int>> Rows keyed by column.
     */
    public function click_id_breakdown( int $days, int $limit = 30 ): array {
        global $wpdb;

        list($cutoff, $limit, $table) = $this->read_frame( $days, $limit );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin report read; caching would duplicate a fresh aggregate.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "SELECT click_id, channel, COUNT(*) AS entries, COUNT(DISTINCT visitor_id) AS visitors, MIN(created_at) AS first_seen, MAX(created_at) AS last_seen FROM {$table}
                WHERE click_id <> '' AND created_at >= %s
                GROUP BY click_id, channel
                ORDER BY entries DESC, last_seen DESC
                LIMIT %d",
                array(
                    $cutoff,
                    $limit,
                )
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
    }

    /**
     * Campaign labels for a set of touchpoint ids, as the attribution
     * comparison needs them: the stored split names touchpoints by id,
     * and this read turns ids back into the campaign a human reads.
     * Ids the retention sweep already removed simply stay absent.
     *
     * @param array<int, int> $ids Touchpoint ids.
     * @return array<int, array{campaign: string, source: string, medium: string}> Rows keyed by touchpoint id.
     */
    public function campaigns_for_ids( array $ids ): array {
        global $wpdb;

        $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
        $ids = array_filter( $ids );
        if ( array() === $ids ) {
            return array();
        }

        $table        = Gr_Database::table( 'touchpoints' );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the placeholder list is built above from count(), not from data; every id reaches prepare() below.
        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- both interpolations are ours: the table from the DDL registry, the placeholder list from count(); an all-interpolated IN list has no literal %s for the sniffer to see.
            "SELECT id, utm_campaign, utm_source, utm_medium FROM {$table} WHERE id IN ({$placeholders})",
            $ids
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above; admin report read.
        $rows = $wpdb->get_results( $sql, ARRAY_A );

        if ( ! is_array( $rows ) ) {
            return array();
        }

        $labels = array();
        foreach ( $rows as $row ) {
            if ( is_array( $row ) ) {
                $labels[ (int) $row['id'] ] = array(
                    'campaign' => (string) ( $row['utm_campaign'] ?? '' ),
                    'source'   => (string) ( $row['utm_source'] ?? '' ),
                    'medium'   => (string) ( $row['utm_medium'] ?? '' ),
                );
            }
        }

        return $labels;
    }

    /**
     * Shared read frame: clamped window cutoff in UTC, clamped row
     * cap, and the table name.
     *
     * @param int $days  Window in days.
     * @param int $limit Row cap.
     * @return array{0: string, 1: int, 2: string}
     */
    private function read_frame( int $days, int $limit ): array {
        $days  = max( self::DAYS_FLOOR, min( $days, self::DAYS_CEILING ) );
        $limit = max( 1, min( $limit, 100 ) );

        $cutoff = ( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) )
            ->modify( '-' . $days . ' days' )
            ->format( 'Y-m-d H:i:s' );

        return array(
            $cutoff,
            $limit,
            Gr_Database::table( 'touchpoints' ),
        );
    }
}
