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

/**
 * Read/write access to the gr_sessions table; repositories are the only
 * layer permitted to hold $wpdb (docs/02 §2.3).
 */
final class Gr_Session_Repository {

    /** Lower clamp for the online window. */
    private const WINDOW_FLOOR = 30;

    /** Upper clamp for the online window. */
    private const WINDOW_CEILING = 3600;

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
                (visitor_id, session_id, user_id, channel, utm_source, utm_medium, utm_campaign, click_id, landing_path, referrer_host, device_type, ua_family, country_code, is_bot, bot_score, pageviews, started_at, last_active)
            VALUES (%s, %s, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %d, %d, %s, %s)
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
     * Distribution of bot_score across the window's sessions: fixed
     * bands (0, 1-25, 26-50, 51-75, 76-99, 100) plus the human/bot
     * split, in one aggregate query — the page renders exactly these
     * numbers and invents nothing. Sessions the probe never scored
     * sit in band 0 by the column default; the split reads is_bot,
     * the boolean conclusion the scorer already reached.
     *
     * @param int $days Look-back window in days.
     * @return array{total: int, bots: int, bands: array<string, int>}
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
     * The zero state of the band vocabulary.
     *
     * @return array<string, int>
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
            'is_bot'        => 0,
            'bot_score'     => 0,
        );
    }
}
