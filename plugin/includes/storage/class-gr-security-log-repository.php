<?php
/**
 * Security log repository (docs/05 §3.1, docs/13 W6): the surge-fold
 * writer for gr_security_logs. One atomic MySQL upsert per hit — the
 * fold collapses ROW COUNT under the md5(ip|rule|hour) key, not the
 * number of writes, and nothing here may claim more than that. The
 * IP column is filled by SQL INET6_ATON so raw binary never travels
 * through the escaping layer; the anonymize switch truncates the
 * address in PHP before it reaches the query (IPv4 /24, IPv6 /48).
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Storage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Database;
use GreenPNG\Core\Gr_Settings;

/**
 * Write access to the gr_security_logs table.
 */
final class Gr_Security_Log_Repository {

    /** IPv4 mask width when anonymizing: /24. */
    private const ANON_V4_PREFIX = 24;

    /** IPv6 mask width when anonymizing: /48. */
    private const ANON_V6_PREFIX = 48;

    /**
     * Fold-window length in seconds.
     *
     * @var int
     */
    private const WINDOW = 3600;

    /**
     * Logs one hit: an INSERT that folds into the existing row for the
     * same address, rule, and hour window, bumping its counter and
     * sliding last_seen. First-seen context (path, agent, reason)
     * stays as captured on the row's first hit — the fold keeps one
     * representative sample, not the latest.
     *
     * @param string $ip     Client address as text.
     * @param string $rule_id Rule identifier, at most 64 chars.
     * @param string $path   Request path, at most 191 chars.
     * @param string $ua     User agent, at most 191 chars.
     * @param string $reason Free-text cause, at most 191 chars.
     * @param int    $window Fold window id; 0 derives the current hour
     *                       (tests pass explicit ids to avoid boundary
     *                       flake).
     * @return int Rows affected by the upsert.
     */
    public function log( string $ip, string $rule_id, string $path = '', string $ua = '', string $reason = '', int $window = 0 ): int {
        global $wpdb;

        $ip = $this->normalize_ip( $ip );
        if ( 0 === $window ) {
            $window = intdiv( time(), self::WINDOW );
        }

        $fold_key = md5( $ip . '|' . $rule_id . '|' . $window );
        $now      = current_time( 'mysql' );
        $table    = Gr_Database::table( 'security_logs' );

        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input.
            "INSERT INTO {$table}
                (fold_key, ip, rule_id, request_path, user_agent, reason, action_taken, hit_count, first_seen, last_seen)
            VALUES (%s, INET6_ATON(%s), %s, %s, %s, %s, 'logged', 1, %s, %s)
            ON DUPLICATE KEY UPDATE hit_count = hit_count + 1, last_seen = VALUES(last_seen)",
            array(
                $fold_key,
                $ip,
                substr( $rule_id, 0, 64 ),
                substr( $path, 0, 191 ),
                substr( $ua, 0, 191 ),
                substr( $reason, 0, 191 ),
                $now,
                $now,
            )
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the prepare() output above; one atomic upsert per hit IS the fold design (docs/05 §3.1), an object-cache layer would only duplicate it.
        $affected = $wpdb->query( $sql );

        return false === $affected ? 0 : (int) $affected;
    }

    /**
     * The current hour-window id, exposed for the admin surfaces that
     * need to group by the same bucket the writer used.
     *
     * @return int
     */
    public static function current_window(): int {
        return intdiv( time(), self::WINDOW );
    }

    /**
     * Distinct crawler-address claims from the recent fold rows, newest
     * first: the FCrDNS daily sweep enqueues verifications for exactly
     * these pairs (docs/13 W10). The address comes back in the column's
     * binary form; the caller turns it into text and skips anything it
     * cannot read.
     *
     * @param int $hours Look-back window in hours.
     * @param int $limit Row cap, newest first.
     * @return array<int, array{ip: string, user_agent: string}> Binary ip plus the agent string.
     */
    public function recent_scanner_ips( int $hours, int $limit ): array {
        global $wpdb;

        $hours = max( 1, $hours );
        $limit = max( 1, $limit );
        $since = gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS );
        $table = Gr_Database::table( 'security_logs' );

        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input.
            "SELECT ip, user_agent FROM {$table}
                WHERE rule_id = %s AND last_seen >= %s
                GROUP BY ip, user_agent
                ORDER BY MAX(last_seen) DESC
                LIMIT %d",
            array(
                'scanner_ua',
                $since,
                $limit,
            )
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above; a maintenance-path read, never a front-end request.
        $rows = $wpdb->get_results( $sql, ARRAY_A );

        if ( ! is_array( $rows ) ) {
            return array();
        }

        $pairs = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $pairs[] = array(
                'ip'         => (string) ( $row['ip'] ?? '' ),
                'user_agent' => (string) ( $row['user_agent'] ?? '' ),
            );
        }

        return $pairs;
    }

    /**
     * Address hygiene: invalid input falls back to the unspecified
     * address so one malformed call can never skip the log row, and
     * the anonymize switch truncates before storage.
     *
     * @param string $ip Raw address text.
     * @return string Normalized address text.
     */
    private function normalize_ip( string $ip ): string {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return '0.0.0.0';
        }

        $settings = gr()->settings();
        if ( 1 !== (int) $settings->get( 'security_log_anonymize' ) ) {
            return $ip;
        }

        $binary = (string) inet_pton( $ip );
        if ( 4 === strlen( $binary ) ) {
            return inet_ntop( substr( $binary, 0, 3 ) . "\x00" );
        }

        return (string) inet_ntop( substr( $binary, 0, 6 ) . str_repeat( "\x00", 10 ) );
    }
}
