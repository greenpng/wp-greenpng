<?php
/**
 * Dashboard data endpoint (docs/06 §2.2/§2.3): the chart script
 * fetches its series from here instead of the page embedding data,
 * so the same payload shape serves any future report surface. Read
 * side only, summary table only, manage_options gated.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Rest;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Storage\Gr_Daily_Stats_Repository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /greenpng/v1/dashboard.
 */
final class Gr_Dashboard_Controller {

    /** Route path under the plugin namespace. */
    public const ROUTE = '/dashboard';

    /** Trend window the endpoint serves. */
    public const TREND_DAYS = 14;

    /** Country-distribution window the endpoint serves. */
    public const DIMENSION_DAYS = 30;

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
                'args'                => array(
                    'days' => array(
                        'type'              => 'integer',
                        'minimum'           => 1,
                        'maximum'           => 90,
                        'sanitize_callback' => 'absint',
                    ),
                ),
            )
        );
    }

    /**
     * Page-localized script data: the endpoint URL, the REST nonce the
     * fetch needs under cookie authentication, and the chart legend
     * labels — the JS carries no copy of its own (i18n rule 5).
     *
     * @return array<string, string|int|array<string, string>>
     */
    public static function script_data(): array {
        return array(
            'endpoint'  => esc_url_raw( rest_url( 'greenpng/v1' . self::ROUTE ) ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'trendDays' => self::TREND_DAYS,
            'labels'    => array(
                'sessions'    => __( 'Sessions', 'greenpng' ),
                'visitors'    => __( 'Visitors', 'greenpng' ),
                'pageviews'   => __( 'Page views', 'greenpng' ),
                'conversions' => __( 'Conversions', 'greenpng' ),
            ),
        );
    }

    /**
     * Permission stage: read-only but still owner-only — report data
     * is site data (docs/06 §2.2, at least manage_options). The
     * request parameter is deliberately not declared: core passes it,
     * plain PHP methods ignore extras, and the gate needs nothing
     * from it.
     *
     * @return true|WP_Error
     */
    public function gate() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error(
                'gr_dashboard_forbidden',
                __( 'You do not have permission to read this data.', 'greenpng' ),
                array( 'status' => 403 )
            );
        }

        return true;
    }

    /**
     * Serves the dashboard payload: dense per-day scalar series and
     * the country distribution.
     *
     * @param WP_REST_Request $request Incoming request.
     * @return WP_REST_Response
     */
    public function handle( WP_REST_Request $request ): WP_REST_Response {
        $days = (int) $request->get_param( 'days' );
        if ( $days < 1 ) {
            $days = self::TREND_DAYS;
        }

        $repo      = new Gr_Daily_Stats_Repository();
        $series    = $repo->series( $days );
        $countries = $repo->dimension( 'sessions_by_country', self::DIMENSION_DAYS, 10 );

        $dates = array();
        $lines = array(
            'sessions'    => array(),
            'visitors'    => array(),
            'pageviews'   => array(),
            'conversions' => array(),
        );

        foreach ( $series as $day => $row ) {
            $dates[]                = (string) $day;
            $lines['sessions'][]    = (float) $row['sessions'];
            $lines['visitors'][]    = (float) $row['visitors'];
            $lines['pageviews'][]   = (float) $row['pageviews'];
            $lines['conversions'][] = (float) $row['conversions'];
        }

        return rest_ensure_response(
            array(
                'dates'     => $dates,
                'series'    => $lines,
                'countries' => $countries,
            )
        );
    }
}
