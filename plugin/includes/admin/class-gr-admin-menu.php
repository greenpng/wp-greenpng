<?php
/**
 * Admin menu skeleton (docs/06 §1): one top-level entry, pages slot
 * in as their tasks land. The hook suffix add_menu_page() returns is
 * kept so asset enqueueing matches by the real suffix, not by a
 * hand-built string that reorderings would silently break.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

use GreenPNG\Rest\Gr_Dashboard_Controller;
use GreenPNG\Rest\Gr_Live_Controller;
use GreenPNG\Rest\Gr_Panels_Controller;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers the top-level menu and the pages that exist so far.
 */
final class Gr_Admin_Menu {

    /** Top-level menu slug (docs/06: dashicons-chart-area, position 30). */
    public const SLUG = 'greenpng-dashboard';

    /**
     * Hook suffix of the dashboard page, filled by register(); tests
     * reset it.
     *
     * @var string
     */
    private static $dashboard_hook = '';

    /**
     * Hook suffix of the traffic page, filled by register().
     *
     * @var string
     */
    private static $traffic_hook = '';

    /**
     * Hook registration.
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( 'admin_menu', array( __CLASS__, 'register' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    /**
     * Menu assembly. The dashboard is the top-level target itself, so
     * the top-level click and the first submenu entry agree; later
     * pages add their own submenu entries.
     *
     * @return void
     */
    public static function register(): void {
        self::$dashboard_hook = add_menu_page(
            __( 'greenpng', 'greenpng' ),
            __( 'greenpng', 'greenpng' ),
            'manage_options',
            self::SLUG,
            array( Gr_Dashboard_Page::class, 'render' ),
            'dashicons-chart-area',
            30
        );

        // First submenu entry (docs/06 tree, OQ-1 decision): the
        // global switches, ahead of the dashboard.
        add_submenu_page(
            self::SLUG,
            __( 'Settings', 'greenpng' ),
            __( 'Settings', 'greenpng' ),
            'manage_options',
            Gr_Settings_Page::SLUG,
            array( Gr_Settings_Page::class, 'render' )
        );

        add_submenu_page(
            self::SLUG,
            __( 'Dashboard', 'greenpng' ),
            __( 'Dashboard', 'greenpng' ),
            'manage_options',
            self::SLUG,
            array( Gr_Dashboard_Page::class, 'render' )
        );

        self::$traffic_hook = add_submenu_page(
            self::SLUG,
            __( 'Traffic & Security', 'greenpng' ),
            __( 'Traffic & Security', 'greenpng' ),
            'manage_options',
            Gr_Traffic_Page::SLUG,
            array( Gr_Traffic_Page::class, 'render' )
        );

        // Nested under Traffic & Security per the docs/06 tree; the
        // page registers its own admin_init write handler.
        add_submenu_page(
            Gr_Traffic_Page::SLUG,
            __( 'Access Rules', 'greenpng' ),
            __( 'Access Rules', 'greenpng' ),
            'manage_options',
            Gr_Access_Rules_Page::SLUG,
            array( Gr_Access_Rules_Page::class, 'render' )
        );

        // Login Protection sits beside Access Rules in the docs/06
        // tree; its write handler joins on admin_init.
        add_submenu_page(
            Gr_Traffic_Page::SLUG,
            __( 'Login Protection', 'greenpng' ),
            __( 'Login Protection', 'greenpng' ),
            'manage_options',
            Gr_Login_Protection_Page::SLUG,
            array( Gr_Login_Protection_Page::class, 'render' )
        );

        // Bot & Device Signals beside its sibling engines (docs/06
        // tree); read-only summaries, no write handler of its own.
        add_submenu_page(
            Gr_Traffic_Page::SLUG,
            __( 'Bot & Device Signals', 'greenpng' ),
            __( 'Bot & Device Signals', 'greenpng' ),
            'manage_options',
            Gr_Bot_Signals_Page::SLUG,
            array( Gr_Bot_Signals_Page::class, 'render' )
        );

        // Marketing section opens under the top-level menu (docs/06
        // tree); read-only page, no write handler of its own.
        add_submenu_page(
            self::SLUG,
            __( 'Campaigns', 'greenpng' ),
            __( 'Campaigns', 'greenpng' ),
            'manage_options',
            Gr_Campaigns_Page::SLUG,
            array( Gr_Campaigns_Page::class, 'render' )
        );

        add_submenu_page(
            self::SLUG,
            __( 'URL Builder', 'greenpng' ),
            __( 'URL Builder', 'greenpng' ),
            'manage_options',
            Gr_Url_Builder_Page::SLUG,
            array( Gr_Url_Builder_Page::class, 'render' )
        );

        // Funnels & Goals closes the marketing section (docs/06
        // tree); the v1.0 A/B reporting page is read-only, no write
        // handler of its own.
        add_submenu_page(
            self::SLUG,
            __( 'Funnels & Goals', 'greenpng' ),
            __( 'Funnels & Goals', 'greenpng' ),
            'manage_options',
            Gr_Funnels_Page::SLUG,
            array( Gr_Funnels_Page::class, 'render' )
        );

        // Audience section opens (docs/06 tree): the CRM surfaces.
        // Contacts is the captured-lead list, read-only; the profile
        // page nested under it carries the audited reveal and rescore
        // writes on its own admin_init handler.
        add_submenu_page(
            self::SLUG,
            __( 'Contacts', 'greenpng' ),
            __( 'Contacts', 'greenpng' ),
            'manage_options',
            Gr_Contacts_Page::SLUG,
            array( Gr_Contacts_Page::class, 'render' )
        );

        // Inline entry from the Contacts list (docs/06 tree); a bare
        // visit explains itself and links back.
        add_submenu_page(
            Gr_Contacts_Page::SLUG,
            __( 'Contact Profile', 'greenpng' ),
            __( 'Contact Profile', 'greenpng' ),
            'manage_options',
            Gr_Contact_Profile_Page::SLUG,
            array( Gr_Contact_Profile_Page::class, 'render' )
        );

        // The site owner's points table (docs/06 tree); its write
        // handler joins on admin_init.
        add_submenu_page(
            self::SLUG,
            __( 'Scoring Rules', 'greenpng' ),
            __( 'Scoring Rules', 'greenpng' ),
            'manage_options',
            Gr_Scoring_Rules_Page::SLUG,
            array( Gr_Scoring_Rules_Page::class, 'render' )
        );

        // The behavior reporting surface closes the Audience
        // section (docs/06 tree), read-only, consent-gated upstream.
        add_submenu_page(
            self::SLUG,
            __( 'Behavior Insights', 'greenpng' ),
            __( 'Behavior Insights', 'greenpng' ),
            'manage_options',
            Gr_Behavior_Page::SLUG,
            array( Gr_Behavior_Page::class, 'render' )
        );

        // Integrations section opens (docs/06 tree); the outbound
        // analytics page registers its own admin_init write handler.
        add_submenu_page(
            self::SLUG,
            __( 'Analytics & CAPI', 'greenpng' ),
            __( 'Analytics & CAPI', 'greenpng' ),
            'manage_options',
            Gr_Analytics_Page::SLUG,
            array( Gr_Analytics_Page::class, 'render' )
        );

        // IP Intelligence (docs/06 tree): GeoIP management with the
        // owner-clicked refresh; the page registers its own
        // admin_init write handler.
        add_submenu_page(
            self::SLUG,
            __( 'IP Intelligence', 'greenpng' ),
            __( 'IP Intelligence', 'greenpng' ),
            'manage_options',
            Gr_Ip_Intel_Page::SLUG,
            array( Gr_Ip_Intel_Page::class, 'render' )
        );

        // Webhooks (docs/06 tree): owner-configured outbound
        // notifications; the page registers its own admin_init write
        // handler.
        add_submenu_page(
            self::SLUG,
            __( 'Webhooks', 'greenpng' ),
            __( 'Webhooks', 'greenpng' ),
            'manage_options',
            Gr_Webhooks_Page::SLUG,
            array( Gr_Webhooks_Page::class, 'render' )
        );

        // Tools section (docs/06 tree); read-only trail, server-side
        // pagination.
        add_submenu_page(
            self::SLUG,
            __( 'Audit Log', 'greenpng' ),
            __( 'Audit Log', 'greenpng' ),
            'manage_options',
            Gr_Audit_Log_Page::SLUG,
            array( Gr_Audit_Log_Page::class, 'render' )
        );

        // Tools section (docs/06 tree); retention rails and the
        // human-only rebuild.
        add_submenu_page(
            self::SLUG,
            __( 'Data Retention', 'greenpng' ),
            __( 'Data Retention', 'greenpng' ),
            'manage_options',
            Gr_Data_Retention_Page::SLUG,
            array( Gr_Data_Retention_Page::class, 'render' )
        );

        // Tools section (docs/06 tree); environment snapshot and the
        // sanitized export.
        add_submenu_page(
            self::SLUG,
            __( 'Status & Diagnostics', 'greenpng' ),
            __( 'Status & Diagnostics', 'greenpng' ),
            'manage_options',
            Gr_Status_Page::SLUG,
            array( Gr_Status_Page::class, 'render' )
        );
    }

    /**
     * Page-scoped asset loading: the dashboard page opts into the
     * chart library and its own script, the traffic page into the
     * datagrid; every other admin screen stays at registration only
     * (docs/06 §2.3).
     *
     * @param string $hook_suffix Current admin screen's hook suffix.
     * @return void
     */
    public static function enqueue_assets( string $hook_suffix ): void {
        if ( '' !== self::$dashboard_hook && $hook_suffix === self::$dashboard_hook ) {
            Gr_Chart_Assets::enqueue();

            wp_enqueue_script(
                'gr-dashboard',
                GR_PLUGIN_URL . 'assets/js/gr-dashboard.js',
                array( Gr_Chart_Assets::HANDLE ),
                GR_VERSION,
                true
            );

            wp_add_inline_script(
                'gr-dashboard',
                'window.GreenPNGDashboard=' . (string) wp_json_encode(
                    array_merge(
                        Gr_Dashboard_Controller::script_data(),
                        Gr_Panels_Controller::script_data()
                    )
                ) . ';',
                'before'
            );

            return;
        }

        if ( '' !== self::$traffic_hook && $hook_suffix === self::$traffic_hook ) {
            wp_enqueue_script(
                'gr-datagrid',
                GR_PLUGIN_URL . 'assets/js/gr-datagrid.js',
                array(),
                GR_VERSION,
                true
            );

            $live    = Gr_Live_Controller::script_data();
            $labels  = $live['labels'];
            $inline  = 'window.GreenPNGLive=' . (string) wp_json_encode( $live ) . ';';
            $inline .= 'new window.GrDataGrid({containerId:"' . Gr_Traffic_Page::GRID_MOUNT . '",'
                . 'endpoint:' . (string) wp_json_encode( $live['endpoint'] ) . ','
                . 'nonce:' . (string) wp_json_encode( $live['nonce'] ) . ','
                . 'pollMs:' . (int) $live['pollMs'] . ','
                . 'columns:['
                . '{key:"time",label:' . (string) wp_json_encode( $labels['time'] ) . '},'
                . '{key:"name",label:' . (string) wp_json_encode( $labels['name'] ) . '},'
                . '{key:"group",label:' . (string) wp_json_encode( $labels['group'] ) . '},'
                . '{key:"visitor",label:' . (string) wp_json_encode( $labels['visitor'] ) . '}'
                . ']});';

            wp_add_inline_script( 'gr-datagrid', $inline, 'after' );
        }
    }

    /**
     * Test seam: forget the captured hook suffixes.
     *
     * @return void
     */
    public static function reset_for_tests(): void {
        self::$dashboard_hook = '';
        self::$traffic_hook   = '';
    }
}
