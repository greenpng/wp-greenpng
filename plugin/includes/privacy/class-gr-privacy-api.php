<?php
/**
 * WordPress privacy API integration (ADR-0005 §4, docs/13 V2): the
 * person-level export and erase arms over the marketing rail. The
 * email-to-visitor mapping is real in v1.0: the WooCommerce orders a
 * person placed carry the visitor binding this plugin wrote at
 * checkout, so the chain email → orders → visitor ids → rows is a
 * join over public data both sides already own. The CRM contacts
 * exporter rides along over the contacts table's email hash; with no
 * CRM writer yet it honestly finds nothing, and the same code works
 * the day the CRM phase lands.
 *
 * The security rail is deliberately absent: security logs keep full
 * addresses on a legitimate-interest basis with short retention, are
 * not email-linked, and leave this tool's scope on purpose.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Privacy;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Database;
use GreenPNG\Core\Gr_Secrets;
use GreenPNG\Integrations\Ecosystem\Gr_Woocommerce_Adapter;
use GreenPNG\Storage\Gr_Conversion_Repository;
use GreenPNG\Storage\Gr_Session_Repository;
use GreenPNG\Storage\Gr_Touchpoint_Repository;

/**
 * Exporters and erasers for the core privacy tools.
 */
final class Gr_Privacy_Api {

    /** Exporter key: marketing sessions. */
    public const EXPORTER_SESSIONS = 'greenpng-sessions';

    /** Exporter key: attribution touchpoints. */
    public const EXPORTER_TOUCHPOINTS = 'greenpng-touchpoints';

    /** Exporter key: conversions. */
    public const EXPORTER_CONVERSIONS = 'greenpng-conversions';

    /** Exporter key: CRM contacts (empty until the CRM phase lands). */
    public const EXPORTER_CONTACTS = 'greenpng-contacts';

    /**
     * Hook registration.
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporters' ) );
        add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_erasers' ) );
        add_action( 'admin_init', array( __CLASS__, 'policy_content' ) );
    }

    /**
     * Appends this plugin's exporters to the core registry.
     *
     * @param array<int, array<string, mixed>> $exporters Core's list.
     * @return array<int, array<string, mixed>>
     */
    public static function register_exporters( array $exporters ): array {
        $exporters[] = array(
            'exporter_friendly_name' => __( 'greenpng sessions', 'greenpng' ),
            'callback'               => array( __CLASS__, 'export_sessions' ),
        );
        $exporters[] = array(
            'exporter_friendly_name' => __( 'greenpng touchpoints', 'greenpng' ),
            'callback'               => array( __CLASS__, 'export_touchpoints' ),
        );
        $exporters[] = array(
            'exporter_friendly_name' => __( 'greenpng conversions', 'greenpng' ),
            'callback'               => array( __CLASS__, 'export_conversions' ),
        );
        $exporters[] = array(
            'exporter_friendly_name' => __( 'greenpng CRM contact', 'greenpng' ),
            'callback'               => array( __CLASS__, 'export_contact' ),
        );

        return $exporters;
    }

    /**
     * Appends this plugin's erasers to the core registry.
     *
     * @param array<int, array<string, mixed>> $erasers Core's list.
     * @return array<int, array<string, mixed>>
     */
    public static function register_erasers( array $erasers ): array {
        $erasers[] = array(
            'eraser_friendly_name' => __( 'greenpng sessions', 'greenpng' ),
            'callback'             => array( __CLASS__, 'erase_sessions' ),
        );
        $erasers[] = array(
            'eraser_friendly_name' => __( 'greenpng touchpoints', 'greenpng' ),
            'callback'             => array( __CLASS__, 'erase_touchpoints' ),
        );
        $erasers[] = array(
            'eraser_friendly_name' => __( 'greenpng conversions', 'greenpng' ),
            'callback'             => array( __CLASS__, 'erase_conversions' ),
        );
        $erasers[] = array(
            'eraser_friendly_name' => __( 'greenpng CRM contact', 'greenpng' ),
            'callback'             => array( __CLASS__, 'erase_contact' ),
        );

        return $erasers;
    }

    /**
     * The person's visitor ids: derived from the WooCommerce orders
     * placed under the email, each carrying the visitor binding this
     * plugin wrote at checkout. Without WooCommerce the chain is
     * empty and every exporter honestly reports no data — no
     * fabricated linkage.
     *
     * @param string $email The requester's email address.
     * @return array<int, string> Unique visitor ids.
     */
    public static function visitor_ids_for_email( string $email ): array {
        if ( '' === $email || ! Gr_Woocommerce_Adapter::is_available() ) {
            return array();
        }

        try {
            $orders = wc_get_orders(
                array(
                    'billing_email' => $email,
                    'status'        => 'any',
                    'limit'         => -1,
                )
            );
        } catch ( \Throwable $error ) {
            do_action( 'gr_adapter_error', 'privacy_api', $error );

            return array();
        }

        $ids = array();
        foreach ( (array) $orders as $order ) {
            if ( ! $order instanceof \WC_Order ) {
                continue;
            }
            $visitor = (string) $order->get_meta( Gr_Woocommerce_Adapter::VISITOR_META );
            if ( '' !== $visitor ) {
                $ids[] = $visitor;
            }
        }

        return array_values( array_unique( $ids ) );
    }

    /**
     * Sessions exporter: one page per visitor id, all of that
     * visitor's rows.
     *
     * @param string $email Requester email.
     * @param int    $page  1-based page number.
     * @return array<string, mixed> Core exporter shape.
     */
    public static function export_sessions( string $email, int $page = 1 ) {
        return self::export_by_visitor(
            self::EXPORTER_SESSIONS,
            __( 'greenpng sessions', 'greenpng' ),
            array( new Gr_Session_Repository(), 'rows_for_visitor' ),
            $email,
            $page,
            array(
                'session_id',
                'channel',
                'utm_source',
                'utm_medium',
                'utm_campaign',
                'click_id',
                'landing_path',
                'referrer_host',
                'device_type',
                'ua_family',
                'country_code',
                'is_bot',
                'bot_score',
                'pageviews',
                'started_at',
                'last_active',
            )
        );
    }

    /**
     * Touchpoints exporter.
     *
     * @param string $email Requester email.
     * @param int    $page  1-based page number.
     * @return array<string, mixed>
     */
    public static function export_touchpoints( string $email, int $page = 1 ) {
        return self::export_by_visitor(
            self::EXPORTER_TOUCHPOINTS,
            __( 'greenpng touchpoints', 'greenpng' ),
            array( new Gr_Touchpoint_Repository(), 'rows_for_visitor' ),
            $email,
            $page,
            array(
                'channel',
                'utm_source',
                'utm_medium',
                'utm_campaign',
                'utm_term',
                'utm_content',
                'click_id',
                'landing_url',
                'referrer_host',
                'created_at',
            )
        );
    }

    /**
     * Conversions exporter.
     *
     * @param string $email Requester email.
     * @param int    $page  1-based page number.
     * @return array<string, mixed>
     */
    public static function export_conversions( string $email, int $page = 1 ) {
        return self::export_by_visitor(
            self::EXPORTER_CONVERSIONS,
            __( 'greenpng conversions', 'greenpng' ),
            array( new Gr_Conversion_Repository(), 'rows_for_visitor' ),
            $email,
            $page,
            array(
                'source_type',
                'source_id',
                'session_id',
                'amount',
                'currency',
                'model_weights',
                'created_at',
            )
        );
    }

    /**
     * CRM contact exporter: keyed by the internal email hash. The
     * contacts table has no v1.0 writer, so this honestly returns no
     * data until the CRM phase lands; the lookup itself is the real
     * one the CRM will feed.
     *
     * @param string $email Requester email.
     * @param int    $page  Unused; a hash lookup is one page.
     * @return array<string, mixed>
     */
    public static function export_contact( string $email, int $page = 1 ) {
        $out = array(
            'data' => array(),
            'done' => true,
        );

        // The hash is unique: one page holds everything. A later page
        // answers done immediately so the core tool stops cleanly.
        if ( '' === $email || $page > 1 ) {
            return $out;
        }

        $row = self::contact_row( $email );
        if ( array() === $row ) {
            return $out;
        }

        $out['data'][] = array(
            'group'     => __( 'greenpng CRM contact', 'greenpng' ),
            'item_key'  => self::EXPORTER_CONTACTS . '-' . (string) ( $row['id'] ?? '0' ),
            'item_name' => __( 'CRM contact', 'greenpng' ),
            'data'      => array(
                array(
                    'name'  => __( 'First name', 'greenpng' ),
                    'value' => (string) ( $row['first_name'] ?? '' ),
                ),
                array(
                    'name'  => __( 'Last name', 'greenpng' ),
                    'value' => (string) ( $row['last_name'] ?? '' ),
                ),
                array(
                    'name'  => __( 'Lead score', 'greenpng' ),
                    'value' => (string) ( $row['lead_score'] ?? '0' ),
                ),
                array(
                    'name'  => __( 'Lifetime value', 'greenpng' ),
                    'value' => (string) ( $row['ltv'] ?? '0' ),
                ),
                array(
                    'name'  => __( 'RFM segment', 'greenpng' ),
                    'value' => (string) ( $row['rfm_segment'] ?? '' ),
                ),
                array(
                    'name'  => __( 'First seen', 'greenpng' ),
                    'value' => (string) ( $row['first_seen'] ?? '' ),
                ),
                array(
                    'name'  => __( 'Last seen', 'greenpng' ),
                    'value' => (string) ( $row['last_seen'] ?? '' ),
                ),
            ),
        );

        return $out;
    }

    /**
     * Sessions eraser.
     *
     * @param string $email Requester email.
     * @return array<string, mixed> Core eraser shape.
     */
    public static function erase_sessions( string $email ) {
        return self::erase_by_visitor(
            array( new Gr_Session_Repository(), 'delete_for_visitor' ),
            $email,
            __( 'greenpng sessions', 'greenpng' )
        );
    }

    /**
     * Touchpoints eraser.
     *
     * @param string $email Requester email.
     * @return array<string, mixed>
     */
    public static function erase_touchpoints( string $email ) {
        return self::erase_by_visitor(
            array( new Gr_Touchpoint_Repository(), 'delete_for_visitor' ),
            $email,
            __( 'greenpng touchpoints', 'greenpng' )
        );
    }

    /**
     * Conversions eraser: the rows only. The order binding meta is
     * stripped by the contact eraser, which runs after this one, so
     * the email → orders → visitor mapping every family needs stays
     * alive until all families have erased.
     *
     * @param string $email Requester email.
     * @return array<string, mixed>
     */
    public static function erase_conversions( string $email ) {
        return self::erase_by_visitor(
            array( new Gr_Conversion_Repository(), 'delete_for_visitor' ),
            $email,
            __( 'greenpng conversions', 'greenpng' )
        );
    }

    /**
     * CRM contact eraser: deletes the hash-keyed row when it exists,
     * then finalizes the whole family set by stripping the visitor
     * binding meta from the orders — the linkage is the person's data
     * too, and removing it last keeps every other eraser's mapping
     * intact until it has run.
     *
     * @param string $email Requester email.
     * @return array<string, mixed>
     */
    public static function erase_contact( string $email ) {
        global $wpdb;

        $out = array(
            'items_removed'  => 0,
            'items_retained' => 0,
            'messages'       => array(),
            'done'           => true,
        );

        if ( '' === $email ) {
            return $out;
        }

        $row = self::contact_row( $email );

        if ( array() !== $row ) {
            $table = Gr_Database::table( 'contacts' );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy-tool erasure, owner-initiated only.
            $out['items_removed'] = (int) $out['items_removed'] + (int) $wpdb->query(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                    "DELETE FROM {$table} WHERE email_hash = %s",
                    Gr_Secrets::hash_pii( $email, 'email' )
                )
            );
        }

        // Finalizer: the order bindings go last, after every other
        // family has had its chance to find the visitor through them.
        $orders = self::orders_for_email( $email );
        foreach ( $orders as $order ) {
            if ( '' !== (string) $order->get_meta( Gr_Woocommerce_Adapter::VISITOR_META ) ) {
                $order->delete_meta_data( Gr_Woocommerce_Adapter::VISITOR_META );
                $order->save();
                $out['items_removed'] = (int) $out['items_removed'] + 1;
            }
        }

        if ( 0 < (int) $out['items_removed'] ) {
            $out['messages'][] = sprintf(
                /* translators: %s: number of items removed. */
                __( '%s items removed from the CRM contact and order bindings.', 'greenpng' ),
                (int) $out['items_removed']
            );
        }

        return $out;
    }

    /**
     * Suggested privacy-policy content for the site owner: what the
     * plugin collects, on what basis, and for how long.
     *
     * @return void
     */
    public static function policy_content(): void {
        wp_add_privacy_policy_content(
            'greenpng',
            sprintf(
                '<h3>%s</h3><p>%s</p><p>%s</p><p>%s</p>',
                esc_html__( 'greenpng analytics', 'greenpng' ),
                esc_html__( 'Marketing analytics (visits, campaign attribution, conversion tracking) store anonymized IP addresses (IPv4 /24, IPv6 /48) and a visitor identifier, only after marketing consent through the WordPress Consent API. Consent can be withdrawn at any time.', 'greenpng' ),
                esc_html__( 'Security logs keep complete IP addresses for a short retention period on a legitimate-interest basis (protection against bots and abuse, GDPR Recital 49), masked in the admin display, and can be switched to anonymized storage.', 'greenpng' ),
                esc_html__( 'Data leaves this site only for the outbound services the site owner configured (GA4, Meta), never automatically and never without visitor consent. The WordPress personal data export and erasure tools cover this plugin\'s marketing tables.', 'greenpng' )
            )
        );
    }

    /**
     * The shared per-visitor exporter loop: page N exports the Nth
     * visitor's rows for one table, all of them.
     *
     * @param string             $key        Exporter key.
     * @param string             $group      Item group label.
     * @param callable           $rows       Visitor rows callback.
     * @param string             $email      Requester email.
     * @param int                $page       1-based page number.
     * @param array<int, string> $columns Columns exported per row.
     * @return array<string, mixed>
     */
    private static function export_by_visitor( string $key, string $group, callable $rows, string $email, int $page, array $columns ): array {
        $out = array(
            'data' => array(),
            'done' => true,
        );

        $visitors = self::visitor_ids_for_email( $email );
        if ( array() === $visitors ) {
            return $out;
        }

        $index       = max( 1, $page ) - 1;
        $out['done'] = $index >= count( $visitors );

        if ( $out['done'] ) {
            return $out;
        }

        $visitor = $visitors[ $index ];
        foreach ( (array) call_user_func( $rows, $visitor ) as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $item = array(
                'group'     => $group,
                'item_key'  => $key . '-' . (string) ( $row['id'] ?? '0' ),
                'item_name' => $group,
                'data'      => array(),
            );
            foreach ( $columns as $column ) {
                if ( array_key_exists( $column, $row ) ) {
                    $item['data'][] = array(
                        'name'  => $column,
                        'value' => (string) $row[ $column ],
                    );
                }
            }
            $out['data'][] = $item;
        }

        return $out;
    }

    /**
     * The shared per-visitor eraser loop: all visitors, one pass.
     *
     * @param callable $delete Rows-removed callback per visitor.
     * @param string   $email  Requester email.
     * @param string   $name   Friendly name for the messages array.
     * @return array<string, mixed>
     */
    private static function erase_by_visitor( callable $delete, string $email, string $name ): array {
        $out = array(
            'items_removed'  => 0,
            'items_retained' => 0,
            'messages'       => array(),
            'done'           => true,
        );

        foreach ( self::visitor_ids_for_email( $email ) as $visitor ) {
            $out['items_removed'] += (int) call_user_func( $delete, $visitor );
        }

        $out['messages'][] = sprintf(
            /* translators: 1: number of rows removed, 2: data family name. */
            __( '%1$s rows removed from %2$s.', 'greenpng' ),
            (int) $out['items_removed'],
            $name
        );

        return $out;
    }

    /**
     * The orders under one email, reusing the mapping chain.
     *
     * @param string $email Requester email.
     * @return array<int, \WC_Order>
     */
    private static function orders_for_email( string $email ): array {
        $orders = array();
        if ( '' === $email || ! Gr_Woocommerce_Adapter::is_available() ) {
            return $orders;
        }

        try {
            $found = wc_get_orders(
                array(
                    'billing_email' => $email,
                    'status'        => 'any',
                    'limit'         => -1,
                )
            );
        } catch ( \Throwable $error ) {
            do_action( 'gr_adapter_error', 'privacy_api', $error );

            return $orders;
        }

        foreach ( (array) $found as $order ) {
            if ( $order instanceof \WC_Order ) {
                $orders[] = $order;
            }
        }

        return $orders;
    }

    /**
     * The CRM contact row for an email by internal hash, or an empty
     * array when the table holds nothing for the person.
     *
     * @param string $email Requester email.
     * @return array<string, mixed>
     */
    private static function contact_row( string $email ): array {
        global $wpdb;

        $table = Gr_Database::table( 'contacts' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy-tool single-row lookup on the email_hash unique key.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "SELECT id, first_name, last_name, lead_score, ltv, rfm_segment, first_seen, last_seen FROM {$table} WHERE email_hash = %s",
                Gr_Secrets::hash_pii( $email, 'email' )
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : array();
    }
}
