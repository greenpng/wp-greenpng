<?php
/**
 * Front-end probe enqueue (docs/02 §2.5 dataflow): registers
 * gr-probe.js on the site's own pages with defer semantics and
 * localizes the collect endpoint data from the controller. The
 * setting gate lives here, so a switched-off probe means zero script
 * output on the page — not merely a silent script.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Rest;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers and arms the client probe script.
 */
final class Gr_Probe_Script {

    /** Script handle. */
    public const HANDLE = 'gr-probe';

    /**
     * Hook registration; the per-request gate runs inside enqueue().
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_filter( 'script_loader_tag', array( __CLASS__, 'add_defer_attribute' ), 10, 2 );
    }

    /**
     * Enqueues the probe when the site owner has it on. Admin screens
     * never see it; login and REST contexts do not run this hook.
     *
     * @return void
     */
    public static function enqueue(): void {
        if ( is_admin() ) {
            return;
        }

        if ( 1 !== (int) gr()->settings()->get( 'probe_enabled' ) ) {
            return;
        }

        wp_enqueue_script( self::HANDLE, GR_PLUGIN_URL . 'assets/js/gr-probe.js', array(), GR_VERSION, true );

        // The endpoint data rides in front of the file: URL plus the
        // daily token (a watering canary, not a secret — docs/02 §2.5).
        wp_add_inline_script(
            self::HANDLE,
            'window.GreenPNGProbe=' . (string) wp_json_encode( Gr_Collect_Controller::script_data() ) . ';',
            'before'
        );
    }

    /**
     * Defer semantics for WP 6.0, where enqueue strategies do not
     * exist yet (docs/04 §4): the probe must never block rendering,
     * on any supported version.
     *
     * @param string $tag    The script tag HTML.
     * @param string $handle Script handle.
     * @return string
     */
    public static function add_defer_attribute( string $tag, string $handle ): string {
        if ( self::HANDLE !== $handle || false === strpos( $tag, 'src=' ) ) {
            return $tag;
        }

        return str_replace( ' src=', ' defer src=', $tag );
    }
}
