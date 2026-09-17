<?php
/**
 * Dashboard live panels endpoint (docs/12 G5, docs/06 §2.2): the
 * online count, today's device split, and today's bot totals. Live
 * metrics compute per call over their bounded indexes — the online
 * range on last_active and today's rows on started_at — with no
 * shared transient (docs/05 §3.2's measured decision; an admin
 * poll every 30 seconds stays two cheap queries). Read side only,
 * manage_options gated, REST only — no admin-ajax.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Rest;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Storage\Gr_Session_Repository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /greenpng/v1/panels.
 */
final class Gr_Panels_Controller {

    /** Route path under the plugin namespace. */
    public const ROUTE = '/panels';

    /** Activity window for the online count, matching count_online's default. */
    public const ONLINE_WINDOW = 300;

    /** Client poll interval the page-localized config suggests. */
    public const POLL_MS = 30000;

    /**
     * Route registration on rest_api_init.
     *
     * @return void
     */
    public static function register_routes(): void {
        register_rest_route(
            'greenpng/v1',
            self::ROUTE,
            array(
                'methods'             => 'GET',
                'callback'            => array( new self(), 'handle' ),
                'permission_callback' => array( new self(), 'gate' ),
            )
        );
    }

    /**
     * Page-localized script data, keyed to stay collision-free beside
     * the dashboard controller's config in one inline script: labels
     * arrive from the server, the JS carries no copy of its own
     * (i18n rule 5).
     *
     * @return array<string, int|string|array<string, string|array<string, string>>>
     */
    public static function script_data(): array {
        return array(
            'panelsEndpoint' => esc_url_raw( rest_url( 'greenpng/v1' . self::ROUTE ) ),
            'panelPollMs'    => self::POLL_MS,
            'panelsLabels'   => array(
                'online'        => __( 'Online now', 'greenpng' ),
                'sessionsToday' => __( 'Sessions today', 'greenpng' ),
                'botsToday'     => __( 'Suspected bots today', 'greenpng' ),
                'devices'       => self::device_labels(),
                'noneToday'     => __( 'No sessions recorded yet today.', 'greenpng' ),
            ),
        );
    }

    /**
     * Device-type display vocabulary; codes outside it (a detector
     * update, a manual row) render as the raw code rather than a
     * wrong label.
     *
     * @return array<string, string>
     */
    public static function device_labels(): array {
        return array(
            'desktop' => __( 'Desktop', 'greenpng' ),
            'mobile'  => __( 'Mobile', 'greenpng' ),
            'tablet'  => __( 'Tablet', 'greenpng' ),
            'other'   => __( 'Other', 'greenpng' ),
        );
    }

    /**
     * Permission stage: live or not, this is site data — owner-only
     * (docs/06 §2.2, at least manage_options). The request parameter
     * is deliberately not declared: core passes it, plain PHP
     * methods ignore extras, and the gate needs nothing from it.
     *
     * @return true|WP_Error
     */
    public function gate() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error(
                'gr_panels_forbidden',
                __( 'You do not have permission to read this data.', 'greenpng' ),
                array( 'status' => 403 )
            );
        }

        return true;
    }

    /**
     * Serves the panel payload: the online count and today's split.
     * Device keys stay raw; the client maps them through the
     * localized labels.
     *
     * @param WP_REST_Request $request Incoming request (unused; the shape is fixed).
     * @return WP_REST_Response
     */
    public function handle( WP_REST_Request $request ): WP_REST_Response {
        unset( $request );

        $repository = new Gr_Session_Repository();
        $split      = $repository->today_device_split();

        return rest_ensure_response(
            array(
                'online'        => $repository->count_online( self::ONLINE_WINDOW ),
                'window'        => self::ONLINE_WINDOW,
                'sessionsToday' => $split['sessions'],
                'botsToday'     => $split['bots'],
                'devices'       => $split['devices'],
            )
        );
    }
}
