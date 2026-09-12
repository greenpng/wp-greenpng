<?php
/**
 * Diagnostics export (docs/03 §10, docs/09 §4): a support-ready
 * snapshot of versions, queue posture, adapter mounts, and table
 * capacity. The discipline is a whitelist: every field is chosen by
 * name here, and nothing from options, rows, or secrets ever enters
 * the payload — a credential that cannot be copied in cannot leak
 * out. IP addresses, emails, and paths stay out for the same reason.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Integrations\Gr_Ecosystem_Detector;
use GreenPNG\Storage\Gr_Table_Stats;

/**
 * Builds the exportable environment snapshot.
 */
final class Gr_Diagnostics {

    /**
     * The snapshot. Field set is fixed on purpose: adding a field is
     * a decision, not an accident of "dump what we have".
     *
     * @return array<string, mixed>
     */
    public static function export(): array {
        global $wpdb;

        $next = wp_next_scheduled( Gr_Queue::EVENT_HOOK );

        return array(
            'plugin'        => array(
                'version' => GR_VERSION,
            ),
            'environment'   => array(
                'php'        => PHP_VERSION,
                'wp'         => get_bloginfo( 'version' ),
                'db'         => self::db_family() . ' ' . $wpdb->db_version(),
                'multisite'  => is_multisite(),
                'locale'     => get_locale(),
            ),
            'queue'         => array(
                'backend'    => Gr_Queue::backend(),
                'next_daily' => is_int( $next ) ? (int) $next : 0,
            ),
            'adapters'      => self::adapters(),
            'tables'        => Gr_Table_Stats::stats(),
            'generated_at'  => gmdate( 'c' ),
        );
    }

    /**
     * The database family as a word. The server_info string is the
     * authority — the is_mariadb flag is not always initialized
     * (CLI contexts can leave it null), and a version-only string
     * like "12.3.3" is a MariaDB-only version scheme anyway.
     *
     * @return string 'MariaDB' or 'MySQL'.
     */
    private static function db_family(): string {
        global $wpdb;

        $info = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';
        if ( false !== stripos( $info, 'MariaDB' ) ) {
            return 'MariaDB';
        }

        // Null-defense: the flag may never have been computed.
        return ! empty( $wpdb->is_mariadb ) ? 'MariaDB' : 'MySQL';
    }

    /**
     * Adapter posture: which ecosystem bridges are mounted, in the
     * detector's own vocabulary — bridge names and file names, never
     * settings, credentials, or integration contents.
     *
     * @return array<string, array{mounted: bool, file: string}>
     */
    private static function adapters(): array {
        $out = array();

        $detected = Gr_Ecosystem_Detector::detect();
        foreach ( $detected as $bridge => $info ) {
            $file   = is_array( $info ) && isset( $info['plugin'] ) ? (string) $info['plugin'] : '';
            $active = is_array( $info ) && ! empty( $info['active'] );

            $out[ (string) $bridge ] = array(
                'mounted' => $active && '' !== $file,
                'file'    => $file,
            );
        }

        return $out;
    }
}
