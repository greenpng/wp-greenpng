<?php
/**
 * Table statistics reader (docs/03 §10): row estimates and byte sizes
 * for the plugin's tables from information_schema, held in a short
 * transient so a status page refresh never hammers the catalog. The
 * row counts are engine estimates on InnoDB, by design — this is a
 * capacity read, not an accounting one (the retention page carries
 * the exact COUNTs).
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
 * Capacity snapshot over the plugin's tables.
 */
final class Gr_Table_Stats {

    /** Cache lifetime. */
    private const TTL = 300;

    /** Transient key. */
    private const CACHE_KEY = 'gr_table_stats';

    /**
     * Rows and bytes per table in stable alphabetical order — the
     * page reads like a roster, not like the catalog's whim of the
     * day.
     *
     * @return array<string, array{rows: int, data_bytes: int, index_bytes: int, total_bytes: int}>
     */
    public static function stats(): array {
        $cached = get_transient( self::CACHE_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- catalog read on an admin page, cached right below.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the LIKE pattern interpolates our own prefix plus a literal fragment; every value reaches prepare().
                "SELECT table_name, table_rows, data_length, index_length
                 FROM information_schema.TABLES
                 WHERE table_schema = DATABASE() AND table_name LIKE %s",
                $wpdb->prefix . 'gr\_%'
            ),
            ARRAY_A
        );

        $out = array();
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $name = (string) ( $row['table_name'] ?? '' );
                if ( '' === $name ) {
                    continue;
                }

                $short = self::short_key( $name );
                if ( '' === $short ) {
                    continue;
                }

                $data  = (int) ( $row['data_length'] ?? 0 );
                $index = (int) ( $row['index_length'] ?? 0 );

                $out[ $short ] = array(
                    'rows'        => max( 0, (int) ( $row['table_rows'] ?? 0 ) ),
                    'data_bytes'  => max( 0, $data ),
                    'index_bytes' => max( 0, $index ),
                    'total_bytes' => max( 0, $data ) + max( 0, $index ),
                );
            }
        }

        ksort( $out );
        set_transient( self::CACHE_KEY, $out, self::TTL );

        return $out;
    }

    /**
     * Drops the cache so the next read sees fresh numbers — the
     * optimize button path uses this after a rebuild.
     *
     * @return void
     */
    public static function flush(): void {
        delete_transient( self::CACHE_KEY );
    }

    /**
     * Maps a physical table name back to its short key; '' when the
     * name does not belong to this plugin's registry.
     *
     * @param string $table Physical table name.
     * @return string
     */
    private static function short_key( string $table ): string {
        global $wpdb;

        $prefix = $wpdb->prefix . 'gr_';
        if ( 0 !== strpos( $table, $prefix ) ) {
            return '';
        }

        $bare = substr( $table, strlen( $prefix ) );
        if ( '' === $bare ) {
            return '';
        }

        try {
            // Resolving through the registry both validates the key
            // and keeps one naming authority.
            Gr_Database::table( $bare );
        } catch ( \InvalidArgumentException $e ) {
            return '';
        }

        return $bare;
    }
}
