<?php
/**
 * Chart library packaging (docs/06 §2.3, docs/13 U2): uPlot ships
 * inside the plugin — minified for the wire, source and license
 * beside it for the audit trail — and is only ever REGISTERED on
 * admin screens. A page opts in by calling enqueue() from its own
 * renderer, so no chart-less page ever pays for the library.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers (never enqueues) the vendored chart library.
 */
final class Gr_Chart_Assets {

    /** Script handle for the vendored uPlot build. */
    public const HANDLE = 'gr-uplot';

    /** Style handle for the vendored uPlot stylesheet. */
    public const STYLE_HANDLE = 'gr-uplot-css';

    /**
     * Hook registration; admin_enqueue_scripts is where core expects
     * asset registration for admin pages.
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'register' ) );
    }

    /**
     * Registers the library handles. Deliberately no enqueue here:
     * registration is free, output is the page's decision.
     *
     * @return void
     */
    public static function register(): void {
        wp_register_script(
            self::HANDLE,
            GR_PLUGIN_URL . 'assets/js/vendor/uplot.iife.min.js',
            array(),
            GR_VERSION,
            true
        );

        wp_register_style(
            self::STYLE_HANDLE,
            GR_PLUGIN_URL . 'assets/css/uplot.min.css',
            array(),
            GR_VERSION
        );
    }

    /**
     * The opt-in chart pages call from their renderer; everything else
     * stays at registration only.
     *
     * @return void
     */
    public static function enqueue(): void {
        wp_enqueue_script( self::HANDLE );
        wp_enqueue_style( self::STYLE_HANDLE );
    }
}
