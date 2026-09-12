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

        add_submenu_page(
            self::SLUG,
            __( 'Dashboard', 'greenpng' ),
            __( 'Dashboard', 'greenpng' ),
            'manage_options',
            self::SLUG,
            array( Gr_Dashboard_Page::class, 'render' )
        );
    }

    /**
     * Page-scoped asset loading: the dashboard page opts into the
     * chart library and its own script; every other admin screen
     * stays at registration only (docs/06 §2.3).
     *
     * @param string $hook_suffix Current admin screen's hook suffix.
     * @return void
     */
    public static function enqueue_assets( string $hook_suffix ): void {
        if ( '' === self::$dashboard_hook || $hook_suffix !== self::$dashboard_hook ) {
            return;
        }

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
            'window.GreenPNGDashboard=' . (string) wp_json_encode( Gr_Dashboard_Controller::script_data() ) . ';',
            'before'
        );
    }

    /**
     * Test seam: forget the captured hook suffix.
     *
     * @return void
     */
    public static function reset_for_tests(): void {
        self::$dashboard_hook = '';
    }
}
