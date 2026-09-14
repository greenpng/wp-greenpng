<?php
/**
 * Behavior probe module (ADR-0012): delivers the second client file
 * and registers its event vocabulary on the collect route. The
 * marketing-track gates stack three deep — the setting defaults to
 * off, the script only reaches visitors whose consent state the
 * server can see as allowed, and the endpoint refuses behavior
 * events without marketing consent (the controller's gate). The
 * client file re-checks the consent cookie on every buffer addition,
 * so mid-session changes take effect immediately.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Behavior;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Privacy\Gr_Consent;
use GreenPNG\Rest\Gr_Collect_Controller;

/**
 * Registers and arms the behavior probe.
 */
final class Gr_Behavior {

    /** Script handle. */
    public const HANDLE = 'gr-probe-behavior';

    /**
     * Event names this module registers on the collect vocabulary.
     * 'behavior' is the batch envelope the client flushes once per
     * page; the four inner names are also individually accepted so
     * the same validation path serves direct posts.
     *
     * @return array<string, string>
     */
    public static function event_names(): array {
        return array(
            'behavior'     => 'behavior',
            'dwell'        => 'behavior',
            'scroll_depth' => 'behavior',
            'rage_click'   => 'behavior',
            'dead_click'   => 'behavior',
        );
    }

    /**
     * Hook registration.
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_filter( 'script_loader_tag', array( __CLASS__, 'add_defer_attribute' ), 10, 2 );
        add_filter( 'gr_collect_events', array( __CLASS__, 'vocabulary' ) );
    }

    /**
     * Extends the collect event map only while the module is on; the
     * core vocabulary in the controller stays untouched, and a
     * switched-off module means unknown names — the endpoint's own
     * 400, not a special case here.
     *
     * @param array<string, string> $events Core map.
     * @return array<string, string>
     */
    public static function vocabulary( array $events ): array {
        if ( 1 !== (int) gr()->settings()->get( 'behavior_enabled' ) ) {
            return $events;
        }

        return array_merge( $events, self::event_names() );
    }

    /**
     * Page-localized endpoint data: the collect pair plus the
     * server-side consent view for this visitor. The client treats
     * the WP Consent API cookie as authoritative when a CMP wrote
     * one and this flag as the fallback.
     *
     * @return array<string, string|bool>
     */
    public static function script_data(): array {
        $data = Gr_Collect_Controller::script_data();

        return array(
            'url'     => $data['url'],
            'token'   => $data['token'],
            'consent' => Gr_Consent::allows( 'marketing' ),
        );
    }

    /**
     * Enqueues the behavior file when both probes are on and this
     * visitor's consent state allows the marketing track. Admin
     * screens never see it; login and REST contexts do not run this
     * hook.
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

        if ( 1 !== (int) gr()->settings()->get( 'behavior_enabled' ) ) {
            return;
        }

        if ( ! Gr_Consent::allows( 'marketing' ) ) {
            // No consent in the server's view: zero script output, not
            // a silent script (the same discipline as the probe gate).
            return;
        }

        wp_enqueue_script( self::HANDLE, GR_PLUGIN_URL . 'assets/js/gr-probe-behavior.js', array(), GR_VERSION, true );

        wp_add_inline_script(
            self::HANDLE,
            'window.GreenPNGBehavior=' . (string) wp_json_encode( self::script_data() ) . ';',
            'before'
        );
    }

    /**
     * Defer semantics for WP 6.0, where enqueue strategies do not
     * exist yet: the behavior probe must never block rendering
     * either.
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
