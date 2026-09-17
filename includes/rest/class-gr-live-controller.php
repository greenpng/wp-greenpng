<?php
/**
 * Realtime stream endpoint (docs/06 §2.2/§2.3, docs/13 U5): the
 * traffic page's grid polls this for the newest events. Owner-only
 * read; the payload is already display-shaped so the grid renders
 * values, never markup.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Rest;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Storage\Gr_Event_Repository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /greenpng/v1/live.
 */
final class Gr_Live_Controller {

    /** Route path under the plugin namespace. */
    public const ROUTE = '/live';

    /** Rows per fetch; the grid replaces its whole body each time. */
    public const ROWS = 30;

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
     * Page-localized script data for the grid mount.
     *
     * @return array<string, string|int|array<string, string>>
     */
    public static function script_data(): array {
        return array(
            'endpoint' => esc_url_raw( rest_url( 'greenpng/v1' . self::ROUTE ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'pollMs'   => 15000,
            'labels'   => array(
                'time'    => __( 'Time', 'greenpng' ),
                'name'    => __( 'Event', 'greenpng' ),
                'group'   => __( 'Group', 'greenpng' ),
                'visitor' => __( 'Visitor', 'greenpng' ),
            ),
        );
    }

    /**
     * Permission stage: the live stream is site data; owner-only.
     *
     * @return true|WP_Error
     */
    public function gate() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error(
                'gr_live_forbidden',
                __( 'You do not have permission to read this data.', 'greenpng' ),
                array( 'status' => 403 )
            );
        }

        return true;
    }

    /**
     * Serves the newest events, display-shaped: strings only, visitor
     * ids shortened, nothing an owner could not read aloud. The
     * request parameter stays undeclared: core passes it, the handler
     * takes its window from the class constant.
     *
     * @return WP_REST_Response
     */
    public function handle(): WP_REST_Response {
        $rows = ( new Gr_Event_Repository() )->recent( '', self::ROWS );

        $out = array();
        foreach ( $rows as $row ) {
            $out[] = array(
                'time'    => (string) ( $row['created_at'] ?? '' ),
                'name'    => (string) ( $row['event_name'] ?? '' ),
                'group'   => (string) ( $row['event_group'] ?? '' ),
                'visitor' => self::short_visitor( (string) ( $row['visitor_id'] ?? '' ) ),
            );
        }

        return rest_ensure_response( array( 'rows' => $out ) );
    }

    /**
     * Visitor id shortened for display; the full id belongs to the
     * profile drill-downs, not the live stream.
     *
     * @param string $visitor Full visitor id.
     * @return string
     */
    private static function short_visitor( string $visitor ): string {
        if ( '' === $visitor ) {
            return '';
        }

        return substr( $visitor, 0, 8 ) . '…';
    }
}
