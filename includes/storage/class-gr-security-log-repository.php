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
use GreenPNG\Security\Gr_Crawler_Verify;

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
     * @param string $action Outcome word, at most 32 chars ('logged'
     *                       for engine hits; the FCrDNS engine files
     *                       its verdict here).
     * @return int Rows affected by the upsert.
     */
    public function log( string $ip, string $rule_id, string $path = '', string $ua = '', string $reason = '', int $window = 0, string $action = 'logged' ): int {
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
            VALUES (%s, INET6_ATON(%s), %s, %s, %s, %s, %s, 1, %s, %s)
            ON DUPLICATE KEY UPDATE hit_count = hit_count + 1, last_seen = VALUES(last_seen)",
            array(
                $fold_key,
                $ip,
                substr( $rule_id, 0, 64 ),
                substr( $path, 0, 191 ),
                substr( $ua, 0, 191 ),
                substr( $reason, 0, 191 ),
                substr( $action, 0, 32 ),
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
     * Newest fold rows for the threat-events tab: the address comes
     * back as TEXT (INET6_NTOA) because this is the display read; the
     * masking itself stays with the page layer — the repository hands
     * over the address, the page decides what a human sees.
     *
     * @param int $limit Row cap, newest first.
     * @return array<int, array<string, string|int>> Rows keyed by column.
     */
    public function recent( int $limit = 30 ): array {
        global $wpdb;

        $limit = max( 1, min( $limit, 100 ) );
        $table = Gr_Database::table( 'security_logs' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin report read, never a front-end request.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from the DDL registry, not user input.
                "SELECT id, rule_id, INET6_NTOA(ip) AS ip, request_path, user_agent, hit_count, action_taken, last_seen FROM {$table} ORDER BY last_seen DESC, id DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        if ( ! is_array( $rows ) ) {
            return array();
        }

        return array_values( array_filter( $rows, 'is_array' ) );
    }

    /**
     * Newest fold rows for one set of rules, as the login audit tab
     * reads them (docs/13 U7): the address comes back as text for the
     * page layer to mask; the repository has no display opinions.
     *
     * @param array<int, string> $rule_ids Rule identifiers to include.
     * @param int                $limit    Row cap, newest first.
     * @return array<int, array<string, string|int>> Rows keyed by column.
     */
    public function recent_by_rules( array $rule_ids, int $limit = 30 ): array {
        global $wpdb;

        $rule_ids = array_values(
            array_filter(
                array_map( 'strval', $rule_ids ),
                static function ( string $id ): bool {
                    return '' !== $id;
                }
            )
        );
        if ( array() === $rule_ids ) {
            return array();
        }

        $limit        = max( 1, min( $limit, 100 ) );
        $table        = Gr_Database::table( 'security_logs' );
        $placeholders = implode( ',', array_fill( 0, count( $rule_ids ), '%s' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the placeholder list is built above from count(), not from data; prepare() gets every value below.
        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- both interpolations are ours: the table from the DDL registry, the placeholder list from count().
            "SELECT id, rule_id, INET6_NTOA(ip) AS ip, request_path, user_agent, reason, hit_count, action_taken, first_seen, last_seen FROM {$table} WHERE rule_id IN ({$placeholders})
                ORDER BY last_seen DESC, id DESC
                LIMIT %d",
            array_merge( $rule_ids, array( $limit ) )
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above; admin report read, never a front-end request.
        $rows = $wpdb->get_results( $sql, ARRAY_A );

        if ( ! is_array( $rows ) ) {
            return array();
        }

        return array_values( array_filter( $rows, 'is_array' ) );
    }

    /**
     * Addresses the log has seen within the window, one row per
     * address, newest activity first. The lock-management tab checks
     * each candidate against the live lock store and only the locked
     * ones survive; the window matches the longest TTL the lock store
     * accepts, so a live lock's placement row is always a candidate.
     *
     * @param int $hours Look-back window in hours.
     * @param int $limit Address cap, newest first.
     * @return array<int, array{ip: string, last_seen: string}> Address text plus newest activity.
     */
    public function distinct_recent_ips( int $hours, int $limit = 100 ): array {
        global $wpdb;

        $hours = max( 1, $hours );
        $limit = max( 1, min( $limit, 100 ) );
        $since = gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS );
        $table = Gr_Database::table( 'security_logs' );

        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input.
            "SELECT INET6_NTOA(ip) AS ip, MAX(last_seen) AS last_seen FROM {$table}
                WHERE last_seen >= %s
                GROUP BY ip
                ORDER BY last_seen DESC
                LIMIT %d",
            array(
                $since,
                $limit,
            )
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above; admin report read, never a front-end request.
        $rows = $wpdb->get_results( $sql, ARRAY_A );

        if ( ! is_array( $rows ) ) {
            return array();
        }

        $pairs = array();
        foreach ( $rows as $row ) {
            if ( is_array( $row ) ) {
                $pairs[] = array(
                    'ip'        => (string) ( $row['ip'] ?? '' ),
                    'last_seen' => (string) ( $row['last_seen'] ?? '' ),
                );
            }
        }

        return $pairs;
    }

    /**
     * Recent FCrDNS verdict rows, newest first: one row per fresh DNS
     * walk (the 24h verdict cache suppresses repeats), the verdict in
     * action_taken, the PTR hostname in reason. The page masks the
     * address for display; the row keeps the full one like every
     * security-track entry.
     *
     * @param int $hours Look-back window in hours.
     * @param int $limit Row cap, newest first.
     * @return array<int, array<string, string>> Rows keyed by column.
     */
    public function fcrdns_recent( int $hours, int $limit = 25 ): array {
        global $wpdb;

        $hours = max( 1, $hours );
        $limit = max( 1, min( $limit, 100 ) );
        $since = gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS );
        $table = Gr_Database::table( 'security_logs' );

        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input.
            "SELECT INET6_NTOA(ip) AS ip, user_agent, reason AS host, action_taken, hit_count, last_seen FROM {$table}
                WHERE rule_id = %s AND last_seen >= %s
                ORDER BY last_seen DESC, id DESC
                LIMIT %d",
            array(
                Gr_Crawler_Verify::LOG_RULE,
                $since,
                $limit,
            )
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above; admin report read, never a front-end request.
        $rows = $wpdb->get_results( $sql, ARRAY_A );

        if ( ! is_array( $rows ) ) {
            return array();
        }

        return array_values( array_filter( $rows, 'is_array' ) );
    }

    /**
     * Verdict totals for the window: one row per action_taken word
     * with folded hit counts, so the summary line above the table
     * counts DNS walks, not row explosions.
     *
     * @param int $hours Look-back window in hours.
     * @return array<string, array{walks: int, rows: int}> Keyed by verdict word.
     */
    public function fcrdns_summary( int $hours ): array {
        global $wpdb;

        $hours = max( 1, $hours );
        $since = gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS );
        $table = Gr_Database::table( 'security_logs' );

        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input.
            "SELECT action_taken, SUM(hit_count) AS walks, COUNT(*) AS fold_rows FROM {$table}
                WHERE rule_id = %s AND last_seen >= %s
                GROUP BY action_taken",
            array(
                Gr_Crawler_Verify::LOG_RULE,
                $since,
            )
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above; admin report read, never a front-end request.
        $rows = $wpdb->get_results( $sql, ARRAY_A );

        $out = array();
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                if ( is_array( $row ) ) {
                    $out[ (string) ( $row['action_taken'] ?? '' ) ] = array(
                        'walks' => (int) ( $row['walks'] ?? 0 ),
                        'rows'  => (int) ( $row['fold_rows'] ?? 0 ),
                    );
                }
            }
        }

        return $out;
    }

    /**
     * Scanner-UA engine statistics: one row per agent string the
     * engine folded hits for, heaviest first. hit_count carries the
     * real detection volume — the fold rows are just its container.
     *
     * @param int $hours Look-back window in hours.
     * @param int $limit Agent cap, heaviest first.
     * @return array<int, array<string, string|int>> Rows keyed by column.
     */
    public function ua_engine_stats( int $hours, int $limit = 15 ): array {
        global $wpdb;

        $hours = max( 1, $hours );
        $limit = max( 1, min( $limit, 100 ) );
        $since = gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS );
        $table = Gr_Database::table( 'security_logs' );

        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input.
            "SELECT user_agent, SUM(hit_count) AS hits, COUNT(*) AS fold_rows, MAX(last_seen) AS last_seen FROM {$table}
                WHERE rule_id = 'scanner_ua' AND last_seen >= %s
                GROUP BY user_agent
                ORDER BY hits DESC
                LIMIT %d",
            array(
                $since,
                $limit,
            )
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above; admin report read, never a front-end request.
        $rows = $wpdb->get_results( $sql, ARRAY_A );

        if ( ! is_array( $rows ) ) {
            return array();
        }

        return array_values( array_filter( $rows, 'is_array' ) );
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
            return inet_ntop( substr( $binary, 0, (int) ( self::ANON_V4_PREFIX / 8 ) ) . "\x00" );
        }

        return (string) inet_ntop( substr( $binary, 0, (int) ( self::ANON_V6_PREFIX / 8 ) ) . str_repeat( "\x00", 10 ) );
    }
}
