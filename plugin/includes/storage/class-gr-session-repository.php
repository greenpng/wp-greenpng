<?php
/**
 * Sessions repository for the gr_sessions table (docs/05 §2 #3): upserts
 * one row per visit using the MySQL-native INSERT ... ON DUPLICATE KEY
 * UPDATE so the session_id UNIQUE key makes concurrent touches race-free
 * (ADR-0007 decision 4), and counts online visitors over the last_active
 * index (docs/05 §3.2: a range COUNT, never a shared transient counter).
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Storage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Database;
use GreenPNG\Core\Gr_Ip_Quality;

/**
 * Read/write access to the gr_sessions table; repositories are the only
 * layer permitted to hold $wpdb (docs/02 §2.3).
 */
final class Gr_Session_Repository {

    /** Lower clamp for the online window. */
    private const WINDOW_FLOOR = 30;

    /** Upper clamp for the online window. */
    private const WINDOW_CEILING = 3600;

    /** Ceiling for one paged/export read; the page layer stays far below it. */
    private const PAGED_CEILING = 5000;

    /**
     * All session rows for one visitor, oldest first — the WP privacy
     * export read for the marketing rail. No window: an export that
     * silently stops at 30 days would understate the person's data.
     *
     * @param string $visitor_id Visitor identity.
     * @return array<int, array<string, mixed>>
     */
    public function rows_for_visitor( string $visitor_id ): array {
        global $wpdb;

        if ( '' === $visitor_id ) {
            return array();
        }

        $table = Gr_Database::table( 'sessions' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy-tool read over the visitor index, off the front-end path.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "SELECT * FROM {$table} WHERE visitor_id = %s ORDER BY started_at ASC",
                $visitor_id
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Deletes every session row for one visitor — the privacy erasure
     * arm. Aggregates stay: they hold counts, never identities.
     *
     * @param string $visitor_id Visitor identity.
     * @return int Rows removed.
     */
    public function delete_for_visitor( string $visitor_id ): int {
        global $wpdb;

        if ( '' === $visitor_id ) {
            return 0;
        }

        $table = Gr_Database::table( 'sessions' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy-tool erasure, owner-initiated only.
        return (int) $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "DELETE FROM {$table} WHERE visitor_id = %s",
                $visitor_id
            )
        );
    }

    /**
     * Touches one session: inserts on first sight, then only slides
     * last_active and bumps pageviews — landing attribution stays as
     * captured at session start. VALUES() is used deliberately: MariaDB
     * does not support the MySQL 8.0.20 alias form, and VALUES() still
     * works everywhere in the supported range.
     *
     * @param string               $visitor_id Visitor identity.
     * @param string               $session_id Visit identity.
     * @param array<string, mixed> $landing    Optional landing attributes
     *                                         (channel, utm_*, click_id, …);
     *                                         unknown keys are ignored.
     * @return int Rows affected, or 0 when the write failed.
     */
    public function touch( string $visitor_id, string $session_id, array $landing = array() ): int {
        global $wpdb;

        $row   = array_merge( self::landing_defaults(), array_intersect_key( $landing, self::landing_defaults() ) );
        $now   = current_time( 'mysql' );
        $table = Gr_Database::table( 'sessions' );

        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input.
            "INSERT INTO {$table}
                (visitor_id, session_id, user_id, channel, utm_source, utm_medium, utm_campaign, click_id, landing_path, referrer_host, device_type, ua_family, country_code, ip_quality, is_bot, bot_score, pageviews, started_at, last_active)
            VALUES (%s, %s, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %d, %d, %s, %s)
            ON DUPLICATE KEY UPDATE last_active = VALUES(last_active), pageviews = pageviews + 1",
            array(
                $visitor_id,
                $session_id,
                (int) $row['user_id'],
                (string) $row['channel'],
                (string) $row['utm_source'],
                (string) $row['utm_medium'],
                (string) $row['utm_campaign'],
                (string) $row['click_id'],
                (string) $row['landing_path'],
                (string) $row['referrer_host'],
                (string) $row['device_type'],
                (string) $row['ua_family'],
                (string) $row['country_code'],
                (string) $row['ip_quality'],
                (int) $row['is_bot'],
                (int) $row['bot_score'],
                1,
                $now,
                $now,
            )
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the prepare() output above; atomic upsert per docs/05 §2 (ADR-0007 #4), the session stream is per-hit data.
        $affected = $wpdb->query( $sql );

        return false === $affected ? 0 : (int) $affected;
    }

    /**
     * Persists the probe's conclusion onto one session row (ADR-0009
     * D2): the score only ever rises — GREATEST keeps the strongest
     * evidence seen — and a bot verdict is sticky, so a later weaker
     * signal never un-convicts a session. The row itself belongs to
     * touch(); a verdict on a path that never touched marks nothing.
     *
     * @param string $visitor_id Visitor identity.
     * @param string $session_id Visit identity.
     * @param int    $score     Probe score, clamped to 0..100.
     * @param int    $verdict   1 when the score crossed the threshold.
     * @return int Rows affected, or 0 when the write failed.
     */
    public function apply_probe_score( string $visitor_id, string $session_id, int $score, int $verdict ): int {
        global $wpdb;

        $score   = max( 0, min( 100, $score ) );
        $verdict = 1 === $verdict ? 1 : 0;
        $table   = Gr_Database::table( 'sessions' );

        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
            "UPDATE {$table} SET bot_score = GREATEST(bot_score, %d), is_bot = IF(%d = 1, 1, is_bot) WHERE visitor_id = %s AND session_id = %s",
            array(
                $score,
                $verdict,
                $visitor_id,
                $session_id,
            )
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the prepare() output above; the probe conclusion rides the REST-collect budget line (docs/09 §1.1), one row per signal.
        $affected = $wpdb->query( $sql );

        return false === $affected ? 0 : (int) $affected;
    }

    /**
     * Flags one session row as a known bot from the detector side
     * (scanner UA, payload, trap verdicts — ADR-0009 D2): the boolean
     * conclusion only; the probe score stays whatever the probe
     * measured. UPDATE-only on purpose — rows come from touch(), and a
     * detector verdict on a path that never touched (the login post,
     * the trap's own wp_die) has no row worth inventing.
     *
     * @param string $visitor_id Visitor identity.
     * @param string $session_id Visit identity.
     * @return int Rows affected, or 0 when the write failed.
     */
    public function mark_session_bot( string $visitor_id, string $session_id ): int {
        global $wpdb;

        $table = Gr_Database::table( 'sessions' );

        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
            "UPDATE {$table} SET is_bot = 1 WHERE visitor_id = %s AND session_id = %s",
            array(
                $visitor_id,
                $session_id,
            )
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the prepare() output above; request-end conclusion write, one row.
        $affected = $wpdb->query( $sql );

        return false === $affected ? 0 : (int) $affected;
    }

    /**
     * Visitors active within the window, via the last_active index range
     * scan (docs/05 §3.2); a live metric, so no persistent cache.
     *
     * @param int $window Seconds of activity window, clamped.
     * @return int
     */
    public function count_online( int $window = 300 ): int {
        global $wpdb;

        $window = max( self::WINDOW_FLOOR, min( $window, self::WINDOW_CEILING ) );
        $cutoff = self::cutoff( $window );
        $table  = Gr_Database::table( 'sessions' );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input.
        $sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE last_active > %s", $cutoff );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the prepare() output above; live 5-minute metric over an indexed range (docs/05 §3.2).
        return (int) $wpdb->get_var( $sql );
    }

    /**
     * Filtered, newest-activity-first page of session rows plus the
     * total the filter matches — the visitor session list and its
     * CSV export share one read shape. The WHERE is assembled from a
     * fixed key whitelist only, and every value reaches prepare().
     * No IP or user-agent column is selected: the list surface
     * (docs/12 G4) never renders either.
     *
     * @param array<string, mixed> $filters Whitelisted keys: from, to
     *        (Y-m-d, invalid formats are ignored), s (free search
     *        over visitor_id / session_id / landing_path /
     *        utm_campaign). Absent or empty filters stay out of the
     *        WHERE.
     * @param int                  $per_page Page size, clamped 1..5000.
     * @param int                  $offset   Row offset, at least 0.
     * @return array{rows: array<int, array<string, string>>, total: int}
     */
    public function paged( array $filters = array(), int $per_page = 20, int $offset = 0 ): array {
        global $wpdb;

        $per_page = max( 1, min( $per_page, self::PAGED_CEILING ) );
        $offset   = max( 0, $offset );
        $table    = Gr_Database::table( 'sessions' );

        $clauses = array();
        $values  = array();

        $from = (string) ( $filters['from'] ?? '' );
        if ( self::is_date( $from ) ) {
            $clauses[] = 'started_at >= %s';
            $values[]  = $from . ' 00:00:00';
        }
        $to = (string) ( $filters['to'] ?? '' );
        if ( self::is_date( $to ) ) {
            $clauses[] = 'started_at <= %s';
            $values[]  = $to . ' 23:59:59';
        }
        $search = trim( (string) ( $filters['s'] ?? '' ) );
        if ( '' !== $search ) {
            $clauses[] = '(visitor_id LIKE %s OR session_id LIKE %s OR landing_path LIKE %s OR utm_campaign LIKE %s)';
            $like      = '%' . $wpdb->esc_like( substr( $search, 0, 64 ) ) . '%';
            $values    = array_merge( $values, array( $like, $like, $like, $like ) );
        }

        $where  = array() === $clauses ? '' : 'WHERE ' . implode( ' AND ', $clauses );
        $select = 'visitor_id, session_id, channel, utm_campaign, landing_path, referrer_host, device_type, country_code, ip_quality, is_bot, pageviews, started_at, last_active';

        // With no filters the statement carries no placeholder, and
        // prepare() on a placeholder-less statement is a core
        // doing-it-wrong — so the plain count runs unprepared (no
        // user input ever joined it).
        if ( array() === $values ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- admin list read; the only interpolation is the DDL table name, nothing else ever entered the statement.
            $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the clause list is built above from a fixed key whitelist, not from data; every value reaches prepare() below.
            $count_sql = $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $table is a DDL-validated identifier from Gr_Database, not user input; $where carries only the whitelisted clauses above, invisible to the sniffer as literal placeholders.
                "SELECT COUNT(*) FROM {$table} {$where}",
                $values
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- prepared above from the same fixed whitelist.
            $count = (int) $wpdb->get_var( $count_sql );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the clause list and the pagination pair join through one spread array, which the sniffer counts as a single replacement; the whitelist above already vetted every clause.
        $page_sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
            "SELECT {$select} FROM {$table} {$where} ORDER BY last_active DESC, session_id DESC LIMIT %d OFFSET %d",
            ...array_merge( $values, array( $per_page, $offset ) )
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- prepared above; admin list page read over the last_active index.
        $rows = $wpdb->get_results( $page_sql, ARRAY_A );

        if ( ! is_array( $rows ) ) {
            $rows = array();
        }

        return array(
            'rows'  => array_values( array_filter( $rows, 'is_array' ) ),
            'total' => $count,
        );
    }

    /**
     * Today's device split with the bot totals riding the same
     * aggregate — the dashboard live panel's single bounded read
     * (started_at >= today-midnight, the started index). Live
     * metric, so like count_online() it computes per call and caches
     * nothing (docs/05 §3.2).
     *
     * @return array{devices: array<int, array{key: string, value: int}>, sessions: int, bots: int}
     */
    public function today_device_split(): array {
        global $wpdb;

        $table = Gr_Database::table( 'sessions' );
        // Today's midnight from the mysql form, the same derivation
        // the aggregator and the collect controller use for day keys —
        // the format-typed current_time() forms are less reliable
        // under a runtime timezone change.
        $cutoff = substr( (string) current_time( 'mysql' ), 0, 10 ) . ' 00:00:00';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared below; admin live panel aggregate bounded to today's rows over the started index, never a front-end request.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "SELECT device_type, COUNT(*) AS sessions, SUM(is_bot) AS bots FROM {$table} WHERE started_at >= %s GROUP BY device_type ORDER BY sessions DESC, device_type ASC",
                $cutoff
            ),
            ARRAY_A
        );

        if ( ! is_array( $rows ) ) {
            return array(
                'devices'  => array(),
                'sessions' => 0,
                'bots'     => 0,
            );
        }

        $devices  = array();
        $sessions = 0;
        $bots     = 0;
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $count     = (int) ( $row['sessions'] ?? 0 );
            $devices[] = array(
                'key'   => (string) ( $row['device_type'] ?? '' ),
                'value' => $count,
            );
            $sessions += $count;
            $bots     += (int) ( $row['bots'] ?? 0 );
        }

        return array(
            'devices'  => $devices,
            'sessions' => $sessions,
            'bots'     => $bots,
        );
    }

    /**
     * Y-m-d shape check for the range filters; anything else is
     * dropped rather than trusted into the WHERE.
     *
     * @param string $date Candidate.
     * @return bool
     */
    private static function is_date( string $date ): bool {
        return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date );
    }

    /**
     * Distribution of bot_score across the window's sessions: fixed
     * bands (0, 1-25, 26-50, 51-75, 76-99, 100) plus the human/bot
     * split, in one aggregate query — the page renders exactly these
     * numbers and invents nothing. Sessions the probe never scored
     * sit in band 0 by the column default; the split reads is_bot,
     * the boolean conclusion the scorer already reached.
     *
     * @param int $days Look-back window in days.
     * @return array{total: int, bots: int, bands: array<int|string, int>}
     */
    public function bot_score_distribution( int $days ): array {
        global $wpdb;

        $days  = max( 1, min( $days, 365 ) );
        $since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
        $table = Gr_Database::table( 'sessions' );

        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
            "SELECT COUNT(*) AS total, SUM(is_bot) AS bots, SUM(bot_score = 0) AS b0, SUM(bot_score BETWEEN 1 AND 25) AS b1_25, SUM(bot_score BETWEEN 26 AND 50) AS b26_50, SUM(bot_score BETWEEN 51 AND 75) AS b51_75, SUM(bot_score BETWEEN 76 AND 99) AS b76_99, SUM(bot_score = 100) AS b100 FROM {$table} WHERE started_at >= %s",
            $since
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above; admin report aggregate over the started index, never a front-end request.
        $row = $wpdb->get_row( $sql, ARRAY_A );
        if ( ! is_array( $row ) ) {
            return array(
                'total' => 0,
                'bots'  => 0,
                'bands' => self::empty_bands(),
            );
        }

        return array(
            'total' => (int) ( $row['total'] ?? 0 ),
            'bots'  => (int) ( $row['bots'] ?? 0 ),
            'bands' => array(
                '0'     => (int) ( $row['b0'] ?? 0 ),
                '1-25'  => (int) ( $row['b1_25'] ?? 0 ),
                '26-50' => (int) ( $row['b26_50'] ?? 0 ),
                '51-75' => (int) ( $row['b51_75'] ?? 0 ),
                '76-99' => (int) ( $row['b76_99'] ?? 0 ),
                '100'   => (int) ( $row['b100'] ?? 0 ),
            ),
        );
    }

    /**
     * Campaign-level invalid-traffic report (ADR-0011 D6, docs/16 §3):
     * per campaign, the session count, the sessions the probe
     * concluded were bots, the sessions from known datacenter ranges,
     * and the conversions the campaign credits. Two indexed
     * aggregates instead of one visitor join: conversions have no
     * visitor index, and the last-touch credit matches the
     * attribution semantics the rest of the page already speaks.
     *
     * @param int $days  Look-back window in days, clamped 1..365.
     * @param int $limit Row cap, most sessions first.
     * @return array<int, array{campaign: string, sessions: int, bots: int, hosting: int, converted: int}>
     */
    public function invalid_traffic_by_campaign( int $days, int $limit = 30 ): array {
        global $wpdb;

        $days  = max( 1, min( $days, 365 ) );
        $limit = max( 1, min( 500, $limit ) );
        $since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

        $sessions_table = Gr_Database::table( 'sessions' );
        $converts_table = Gr_Database::table( 'conversions' );
        $touches_table  = Gr_Database::table( 'touchpoints' );

        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sessions_table is a DDL-validated identifier from Gr_Database, not user input; the whole statement sits on this one string line on purpose, within the ignore's reach.
            "SELECT s.utm_campaign AS campaign, COUNT(*) AS sessions, SUM(s.is_bot) AS bots, SUM(s.ip_quality = %s) AS hosting FROM {$sessions_table} s WHERE s.started_at >= %s GROUP BY s.utm_campaign ORDER BY sessions DESC, s.utm_campaign DESC LIMIT %d",
            array(
                Gr_Ip_Quality::CATEGORY_HOSTING,
                $since,
                $limit,
            )
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above; admin report aggregate over the started index, never a front-end request.
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        $rows = is_array( $rows ) ? $rows : array();

        $converted = array();
        $conv_sql  = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $converts_table and $touches_table are DDL-validated identifiers from Gr_Database, not user input; the whole statement sits on this one string line on purpose, within the ignore's reach.
            "SELECT t.utm_campaign AS campaign, COUNT(DISTINCT c.id) AS converted FROM {$converts_table} c JOIN {$touches_table} t ON t.id = c.last_touch_id WHERE c.status = 'active' AND c.created_at >= %s AND t.utm_campaign <> '' GROUP BY t.utm_campaign",
            $since
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above; admin report aggregate over the created index and the touchpoint primary key.
        $conv_rows = $wpdb->get_results( $conv_sql, ARRAY_A );
        foreach ( is_array( $conv_rows ) ? $conv_rows : array() as $conv ) {
            $converted[ (string) ( $conv['campaign'] ?? '' ) ] = (int) ( $conv['converted'] ?? 0 );
        }

        $out = array();
        foreach ( $rows as $row ) {
            $campaign = (string) ( $row['campaign'] ?? '' );
            $out[]    = array(
                'campaign'  => $campaign,
                'sessions'  => (int) ( $row['sessions'] ?? 0 ),
                'bots'      => (int) ( $row['bots'] ?? 0 ),
                'hosting'   => (int) ( $row['hosting'] ?? 0 ),
                'converted' => isset( $converted[ $campaign ] ) ? $converted[ $campaign ] : 0,
            );
        }

        return $out;
    }

    /**
     * Traffic-quality verdict for one visitor: the bot conclusion of
     * their most recent session, or null when the visitor has no
     * sessions at all (no evidence either way — admin-created orders
     * and API orders look like this). Read by the outbound forwarders
     * whose product promise is never feeding robots to an ad
     * platform's algorithm.
     *
     * @param string $visitor_id Visitor identity.
     * @return bool|null True when the latest session is a known bot.
     */
    public function visitor_bot_verdict( string $visitor_id ): ?bool {
        global $wpdb;

        if ( '' === $visitor_id ) {
            return null;
        }

        $table = Gr_Database::table( 'sessions' );

        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
            "SELECT is_bot FROM {$table} WHERE visitor_id = %s ORDER BY last_active DESC, session_id DESC LIMIT 1",
            $visitor_id
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above; single-row indexed lookup on the visitor index (docs/05 §3.2), off the front-end path.
        $is_bot = $wpdb->get_var( $sql );

        if ( null === $is_bot || '' === (string) $is_bot ) {
            return null;
        }

        return '1' === (string) $is_bot;
    }

    /**
     * Whether any session of one visitor was bot-flagged: the CRM
     * verdict consumption's single boolean (ADR-0013 D3). Verdicts are
     * sticky — one flagged session marks the visitor.
     *
     * @param string $visitor_id Visitor identity.
     * @return bool True when at least one session row says is_bot=1.
     */
    public function is_bot_for_visitor( string $visitor_id ): bool {
        global $wpdb;

        if ( '' === $visitor_id ) {
            return false;
        }

        $table = Gr_Database::table( 'sessions' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- scoring/queue-path read over the visitor index; bounded by the exists-style LIMIT 1.
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input.
                "SELECT 1 FROM {$table} WHERE visitor_id = %s AND is_bot = 1 LIMIT 1",
                $visitor_id
            )
        ) === 1;
    }

    /**
     * The zero state of the band vocabulary.
     *
     * @return array<int|string, int>
     */
    private static function empty_bands(): array {
        return array(
            '0'     => 0,
            '1-25'  => 0,
            '26-50' => 0,
            '51-75' => 0,
            '76-99' => 0,
            '100'   => 0,
        );
    }

    /**
     * Activity cutoff string, window seconds before the site clock. Both
     * parse and format pin UTC explicitly so a runtime timezone change
     * can never skew the boundary (the stored stamps are naive site-time
     * on both sides of the comparison).
     *
     * @param int $window Window seconds.
     * @return string 'Y-m-d H:i:s'.
     */
    private static function cutoff( int $window ): string {
        $now = \DateTime::createFromFormat( 'Y-m-d H:i:s', (string) current_time( 'mysql' ), new \DateTimeZone( 'UTC' ) );
        if ( false === $now ) {
            return '1970-01-01 00:00:00';
        }

        return $now->sub( new \DateInterval( 'PT' . $window . 'S' ) )->format( 'Y-m-d H:i:s' );
    }

    /**
     * Landing-attribute whitelist with schema defaults; intersecting
     * input against it keeps unknown keys out of the INSERT.
     *
     * @return array<string, mixed>
     */
    private static function landing_defaults(): array {
        return array(
            'user_id'       => 0,
            'channel'       => 'direct',
            'utm_source'    => '',
            'utm_medium'    => '',
            'utm_campaign'  => '',
            'click_id'      => '',
            'landing_path'  => '',
            'referrer_host' => '',
            'device_type'   => 'desktop',
            'ua_family'     => '',
            'country_code'  => '',
            'ip_quality'    => '',
            'is_bot'        => 0,
            'bot_score'     => 0,
        );
    }
}
