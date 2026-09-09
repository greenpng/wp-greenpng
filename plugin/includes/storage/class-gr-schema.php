<?php
/**
 * Schema owner for the plugin's 15 physical objects (docs/05, the only
 * source of truth for tables). All DDL is assembled here from one-column-per-
 * line arrays so the dbDelta discipline (docs/04 §3.11.8) holds by
 * construction: comma-separated fields on their own lines, PRIMARY KEY
 * double-spaced, integer types carrying core-style display widths, string
 * index columns capped at 191, no foreign keys.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Storage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Activation- and version-gated DDL runner; nothing in this class may be
 * reachable from a front-end request (iron rule 3).
 */
final class Gr_Schema {

    /** Bump once per schema change; migrations step one version at a time. */
    public const DB_VERSION = 1;

    /** Option key holding the installed schema version (autoload=no). */
    public const VERSION_OPTION = 'gr_db_version';

    /**
     * Runs dbDelta for every object and records the version only once all
     * objects verifiably exist: a failed run must leave the stored version
     * behind so the admin_init gate retries instead of masking a broken
     * schema. Safe to run repeatedly: dbDelta is a no-op on matching tables.
     *
     * @return void
     */
    public static function install(): void {
        global $wpdb;

        $prefix = (string) $wpdb->prefix;
        self::dbdelta_all( $prefix, (string) $wpdb->get_charset_collate() );

        if ( ! self::all_objects_present( $prefix ) ) {
            return;
        }

        self::store_version( self::DB_VERSION );
    }

    /**
     * Version gate for the admin_init mount: only acts when the stored
     * version lags, so steady-state admin requests do zero DDL.
     *
     * @return void
     */
    public static function maybe_upgrade(): void {
        $installed = (int) get_option( self::VERSION_OPTION, 0 );
        if ( $installed >= self::DB_VERSION ) {
            return;
        }

        self::install();
    }

    /**
     * Fully-prefixed object names in docs/05 §2 order, derived from the DDL
     * so the list can never drift from the statements themselves.
     *
     * @param string $prefix Table prefix, e.g. wp_.
     * @return array<int, string>
     */
    public static function table_names( string $prefix = '' ): array {
        if ( '' === $prefix ) {
            global $wpdb;
            $prefix = (string) $wpdb->prefix;
        }

        $names = array();
        foreach ( self::tables( $prefix, '' ) as $statement ) {
            preg_match( '/^CREATE TABLE (\S+) \(/', $statement, $match );
            $names[] = $match[1];
        }

        return $names;
    }

    /**
     * Resolves a short table key ('events', 'security_logs') to its
     * fully-prefixed name, validating it against the DDL-derived list so a
     * typo fails loudly at the call site instead of producing a silent
     * query against a table that does not exist. A leading "gr_" on the
     * key is tolerated, so both spellings resolve identically.
     *
     * @param string $key    Short key, with or without a leading gr_.
     * @param string $prefix Table prefix; empty reads $wpdb->prefix.
     * @return string Fully-prefixed table name.
     * @throws \InvalidArgumentException When the key matches no DDL object.
     */
    public static function resolve_table( string $key, string $prefix = '' ): string {
        if ( '' === $prefix ) {
            global $wpdb;
            $prefix = (string) $wpdb->prefix;
        }

        $bare = str_starts_with( $key, 'gr_' ) ? substr( $key, 3 ) : $key;
        $name = $prefix . 'gr_' . $bare;

        if ( ! in_array( $name, self::table_names( $prefix ), true ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput -- developer-facing exception text, not browser output; logs need the raw key.
            throw new \InvalidArgumentException( 'Unknown greenpng table key: ' . $bare );
        }

        return $name;
    }

    /**
     * All 15 DDL statements in docs/05 §2 order. Prefix and charset are
     * injectable so unit tests can inspect the statements without WordPress.
     *
     * @param string $prefix          Table prefix, e.g. wp_.
     * @param string $charset_collate Value from $wpdb->get_charset_collate().
     * @return array<int, string>
     */
    public static function tables( string $prefix = '', string $charset_collate = '' ): array {
        if ( '' === $prefix ) {
            global $wpdb;
            $prefix          = (string) $wpdb->prefix;
            $charset_collate = (string) $wpdb->get_charset_collate();
        }

        return array(
            self::ddl(
                $prefix,
                $charset_collate,
                'gr_security_logs',
                array(
                    'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
                    'fold_key CHAR(32) NOT NULL',
                    'ip VARBINARY(16) NOT NULL',
                    'rule_id VARCHAR(64) NOT NULL',
                    'request_path VARCHAR(191) NOT NULL DEFAULT \'\'',
                    'user_agent VARCHAR(191) NOT NULL DEFAULT \'\'',
                    'reason VARCHAR(191) NOT NULL DEFAULT \'\'',
                    'action_taken VARCHAR(16) NOT NULL DEFAULT \'logged\'',
                    'hit_count INT(10) UNSIGNED NOT NULL DEFAULT 1',
                    'first_seen DATETIME NOT NULL',
                    'last_seen DATETIME NOT NULL',
                    'PRIMARY KEY  (id)',
                    'UNIQUE KEY fold_key (fold_key)',
                    'KEY rule_seen (rule_id, last_seen)',
                    'KEY ip_seen (ip, last_seen)',
                )
            ),
            self::ddl(
                $prefix,
                $charset_collate,
                'gr_access_rules',
                array(
                    'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
                    'rule_type VARCHAR(16) NOT NULL',
                    'match_kind VARCHAR(16) NOT NULL',
                    'match_value VARCHAR(191) NOT NULL',
                    'note VARCHAR(191) NOT NULL DEFAULT \'\'',
                    'is_active TINYINT(1) NOT NULL DEFAULT 1',
                    'created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
                    'created_at DATETIME NOT NULL',
                    'updated_at DATETIME NOT NULL',
                    'PRIMARY KEY  (id)',
                    'KEY type_active (rule_type, is_active)',
                )
            ),
            self::ddl(
                $prefix,
                $charset_collate,
                'gr_sessions',
                array(
                    'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
                    'visitor_id CHAR(64) NOT NULL',
                    'session_id CHAR(36) NOT NULL',
                    'user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
                    'channel VARCHAR(32) NOT NULL DEFAULT \'direct\'',
                    'utm_source VARCHAR(191) NOT NULL DEFAULT \'\'',
                    'utm_medium VARCHAR(191) NOT NULL DEFAULT \'\'',
                    'utm_campaign VARCHAR(191) NOT NULL DEFAULT \'\'',
                    'click_id VARCHAR(191) NOT NULL DEFAULT \'\'',
                    'landing_path VARCHAR(191) NOT NULL DEFAULT \'\'',
                    'referrer_host VARCHAR(191) NOT NULL DEFAULT \'\'',
                    'device_type VARCHAR(16) NOT NULL DEFAULT \'desktop\'',
                    'ua_family VARCHAR(64) NOT NULL DEFAULT \'\'',
                    'country_code CHAR(2) NOT NULL DEFAULT \'\'',
                    'is_bot TINYINT(1) NOT NULL DEFAULT 0',
                    'bot_score TINYINT(3) UNSIGNED NOT NULL DEFAULT 0',
                    'pageviews INT(10) UNSIGNED NOT NULL DEFAULT 1',
                    'started_at DATETIME NOT NULL',
                    'last_active DATETIME NOT NULL',
                    'PRIMARY KEY  (id)',
                    'UNIQUE KEY session_id (session_id)',
                    'KEY visitor_time (visitor_id, started_at)',
                    'KEY started (started_at)',
                    'KEY last_active (last_active)',
                    'KEY channel_time (channel, started_at)',
                )
            ),
            self::ddl(
                $prefix,
                $charset_collate,
                'gr_events',
                array(
                    'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
                    'visitor_id CHAR(64) NOT NULL DEFAULT \'\'',
                    'session_id CHAR(36) NOT NULL DEFAULT \'\'',
                    'event_name VARCHAR(64) NOT NULL',
                    'event_group VARCHAR(32) NOT NULL DEFAULT \'core\'',
                    'event_id VARCHAR(64) NOT NULL DEFAULT \'\'',
                    'payload_json TEXT NULL',
                    'ab_experiment VARCHAR(64) NOT NULL DEFAULT \'\'',
                    'ab_variant VARCHAR(32) NOT NULL DEFAULT \'\'',
                    'ab_type VARCHAR(16) NOT NULL DEFAULT \'\'',
                    'created_at DATETIME NOT NULL',
                    'PRIMARY KEY  (id)',
                    'KEY visitor_time (visitor_id, created_at)',
                    'KEY name_time (event_name, created_at)',
                    'KEY created (created_at)',
                    'KEY ab_events (ab_experiment, ab_type, created_at)',
                )
            ),
            self::ddl(
                $prefix,
                $charset_collate,
                'gr_touchpoints',
                array(
                    'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
                    'visitor_id CHAR(64) NOT NULL',
                    'session_id CHAR(36) NOT NULL DEFAULT \'\'',
                    'channel VARCHAR(32) NOT NULL DEFAULT \'direct\'',
                    'utm_source VARCHAR(191) NOT NULL DEFAULT \'\'',
                    'utm_medium VARCHAR(191) NOT NULL DEFAULT \'\'',
                    'utm_campaign VARCHAR(191) NOT NULL DEFAULT \'\'',
                    'utm_term VARCHAR(191) NOT NULL DEFAULT \'\'',
                    'utm_content VARCHAR(191) NOT NULL DEFAULT \'\'',
                    'click_id VARCHAR(191) NOT NULL DEFAULT \'\'',
                    'landing_url VARCHAR(191) NOT NULL DEFAULT \'\'',
                    'referrer_host VARCHAR(191) NOT NULL DEFAULT \'\'',
                    'created_at DATETIME NOT NULL',
                    'PRIMARY KEY  (id)',
                    'KEY visitor_time (visitor_id, created_at)',
                    'KEY created (created_at)',
                )
            ),
            self::ddl(
                $prefix,
                $charset_collate,
                'gr_conversions',
                array(
                    'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
                    'source_type VARCHAR(16) NOT NULL',
                    'source_id BIGINT(20) UNSIGNED NOT NULL',
                    'session_id CHAR(36) NOT NULL DEFAULT \'\'',
                    'visitor_id CHAR(64) NOT NULL DEFAULT \'\'',
                    'amount DECIMAL(12,2) NOT NULL DEFAULT 0.00',
                    'currency CHAR(3) NOT NULL DEFAULT \'USD\'',
                    'first_touch_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
                    'last_touch_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
                    'model_weights TEXT NULL',
                    'created_at DATETIME NOT NULL',
                    'PRIMARY KEY  (id)',
                    'UNIQUE KEY source_unique (source_type, source_id)',
                    'KEY created (created_at)',
                )
            ),
            self::ddl(
                $prefix,
                $charset_collate,
                'gr_funnels',
                array(
                    'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
                    'name VARCHAR(191) NOT NULL',
                    'slug VARCHAR(191) NOT NULL',
                    'flow_json MEDIUMTEXT NULL',
                    'is_active TINYINT(1) NOT NULL DEFAULT 1',
                    'created_at DATETIME NOT NULL',
                    'updated_at DATETIME NOT NULL',
                    'PRIMARY KEY  (id)',
                    'UNIQUE KEY slug (slug)',
                )
            ),
            self::ddl(
                $prefix,
                $charset_collate,
                'gr_funnel_sessions',
                array(
                    'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
                    'funnel_id BIGINT(20) UNSIGNED NOT NULL',
                    'session_id CHAR(36) NOT NULL',
                    'visitor_id CHAR(64) NOT NULL DEFAULT \'\'',
                    'current_step INT(10) UNSIGNED NOT NULL DEFAULT 1',
                    'max_step INT(10) UNSIGNED NOT NULL DEFAULT 1',
                    'entered_at DATETIME NOT NULL',
                    'last_step_at DATETIME NOT NULL',
                    'completed_at DATETIME NULL',
                    'PRIMARY KEY  (id)',
                    'UNIQUE KEY funnel_session (funnel_id, session_id)',
                    'KEY visitor (visitor_id)',
                    'KEY entered (entered_at)',
                )
            ),
            self::ddl(
                $prefix,
                $charset_collate,
                'gr_cart_abandonments',
                array(
                    'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
                    'session_id CHAR(36) NOT NULL',
                    'email_hash CHAR(64) NOT NULL DEFAULT \'\'',
                    'email_enc TEXT NULL',
                    'cart_json MEDIUMTEXT NULL',
                    'total DECIMAL(12,2) NOT NULL DEFAULT 0.00',
                    'currency CHAR(3) NOT NULL DEFAULT \'USD\'',
                    'status VARCHAR(16) NOT NULL DEFAULT \'captured\'',
                    'recovery_token CHAR(64) NOT NULL DEFAULT \'\'',
                    'order_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
                    'captured_at DATETIME NOT NULL',
                    'abandoned_at DATETIME NULL',
                    'recovered_at DATETIME NULL',
                    'PRIMARY KEY  (id)',
                    'KEY session_time (session_id, captured_at)',
                    'KEY status_time (status, abandoned_at)',
                    'KEY recovery_token (recovery_token)',
                )
            ),
            self::ddl(
                $prefix,
                $charset_collate,
                'gr_contacts',
                array(
                    'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
                    'email_hash CHAR(64) NOT NULL',
                    'email_enc TEXT NULL',
                    'first_name VARCHAR(191) NOT NULL DEFAULT \'\'',
                    'last_name VARCHAR(191) NOT NULL DEFAULT \'\'',
                    'user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
                    'lead_score INT(10) UNSIGNED NOT NULL DEFAULT 0',
                    'ltv DECIMAL(12,2) NOT NULL DEFAULT 0.00',
                    'rfm_segment VARCHAR(16) NOT NULL DEFAULT \'\'',
                    'first_seen DATETIME NOT NULL',
                    'last_seen DATETIME NOT NULL',
                    'PRIMARY KEY  (id)',
                    'UNIQUE KEY email_hash (email_hash)',
                    'KEY user_id_lookup (user_id)',
                    'KEY lead_score_idx (lead_score)',
                )
            ),
            self::ddl(
                $prefix,
                $charset_collate,
                'gr_tags',
                array(
                    'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
                    'name VARCHAR(191) NOT NULL',
                    'slug VARCHAR(191) NOT NULL',
                    'is_system TINYINT(1) NOT NULL DEFAULT 0',
                    'created_at DATETIME NOT NULL',
                    'PRIMARY KEY  (id)',
                    'UNIQUE KEY slug (slug)',
                )
            ),
            self::ddl(
                $prefix,
                $charset_collate,
                'gr_contact_tags',
                array(
                    'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
                    'contact_id BIGINT(20) UNSIGNED NOT NULL',
                    'tag_id BIGINT(20) UNSIGNED NOT NULL',
                    'created_at DATETIME NOT NULL',
                    'PRIMARY KEY  (id)',
                    'UNIQUE KEY contact_tag (contact_id, tag_id)',
                    'KEY tag_contacts (tag_id, contact_id)',
                )
            ),
            self::ddl(
                $prefix,
                $charset_collate,
                'gr_audit_logs',
                array(
                    'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
                    'user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
                    'action VARCHAR(64) NOT NULL',
                    'object_type VARCHAR(32) NOT NULL DEFAULT \'\'',
                    'object_id VARCHAR(64) NOT NULL DEFAULT \'\'',
                    'diff_json MEDIUMTEXT NULL',
                    'created_at DATETIME NOT NULL',
                    'PRIMARY KEY  (id)',
                    'KEY user_time (user_id, created_at)',
                    'KEY object_lookup (object_type, object_id)',
                    'KEY created (created_at)',
                )
            ),
            self::ddl(
                $prefix,
                $charset_collate,
                'gr_dynamic_events',
                array(
                    'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
                    'hook_name VARCHAR(191) NOT NULL',
                    'event_name VARCHAR(64) NOT NULL DEFAULT \'\'',
                    'param_map_json TEXT NULL',
                    'is_active TINYINT(1) NOT NULL DEFAULT 1',
                    'created_at DATETIME NOT NULL',
                    'PRIMARY KEY  (id)',
                    'KEY hook_name_idx (hook_name)',
                )
            ),
            self::ddl(
                $prefix,
                $charset_collate,
                'gr_daily_stats',
                array(
                    'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
                    'stat_date DATE NOT NULL',
                    'metric_type VARCHAR(32) NOT NULL',
                    'metric_key VARCHAR(191) NOT NULL DEFAULT \'\'',
                    'metric_value DECIMAL(14,4) NOT NULL DEFAULT 0',
                    'PRIMARY KEY  (id)',
                    'UNIQUE KEY stat_unique (stat_date, metric_type, metric_key)',
                    'KEY type_date (metric_type, stat_date)',
                )
            ),
        );
    }

    /**
     * Assembles one dbDelta statement from column lines; the line-per-column
     * layout is what dbDelta parses, so it is enforced here rather than
     * reviewed.
     *
     * @param string             $prefix          Table prefix.
     * @param string             $charset_collate Charset clause.
     * @param string             $table           Table name after the prefix.
     * @param array<int, string> $columns Column and index lines.
     * @return string
     */
    private static function ddl( string $prefix, string $charset_collate, string $table, array $columns ): string {
        // dbDelta executes the CREATE text verbatim for new tables, so every
        // line but the last must carry a trailing comma.
        return 'CREATE TABLE ' . $prefix . $table . " (\n" . implode( ",\n", $columns ) . "\n) " . $charset_collate . ';';
    }

    /**
     * Executes every statement through dbDelta.
     *
     * @param string $prefix          Table prefix.
     * @param string $charset_collate Charset clause.
     * @return void
     */
    private static function dbdelta_all( string $prefix, string $charset_collate ): void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach ( self::tables( $prefix, $charset_collate ) as $statement ) {
            dbDelta( $statement );
        }
    }

    /**
     * Whether every object exists; the comparison is case-insensitive
     * because table-name case depends on the server's storage settings.
     *
     * @param string $prefix Table prefix.
     * @return bool
     */
    private static function all_objects_present( string $prefix ): bool {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-shot install-time existence probe; no user input, nothing to cache.
        $existing = array_map( 'strtolower', (array) $wpdb->get_col( 'SHOW TABLES' ) );
        foreach ( self::table_names( $prefix ) as $name ) {
            if ( ! in_array( strtolower( $name ), $existing, true ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Records the schema version as autoload=no; the update path never
     * passes an autoload flag because update_option() only gained that
     * parameter in WP 6.4 and the floor here is 6.0.
     *
     * @param int $version Installed schema version.
     * @return void
     */
    private static function store_version( int $version ): void {
        if ( false !== get_option( self::VERSION_OPTION, false ) ) {
            update_option( self::VERSION_OPTION, $version );
            return;
        }

        add_option( self::VERSION_OPTION, $version, '', 'no' );
    }
}
