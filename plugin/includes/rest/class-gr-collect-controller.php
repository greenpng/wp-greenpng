<?php
/**
 * Public collect endpoint (docs/02 §2.5): one REST route that receives
 * the client probe's sendBeacon events. Three gates run at the
 * permission stage — daily-salt token, per-IP rate limit, 8KB body cap —
 * before the strict schema (registered event names, whitelisted fields,
 * conclusion-only signal values) and the dispatch into the local event
 * stream. Identity is resolved server-side from the dual-track model;
 * the client can never assert visitor or session ids.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Rest;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Rate_Limiter;
use GreenPNG\Core\Gr_Secrets;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Registers and serves greenpng/v1/collect.
 */
final class Gr_Collect_Controller {

    /** Route namespace and path. */
    public const ROUTE = 'greenpng/v1/collect';

    /** Body ceiling in bytes; sendBeacon payloads stay far below. */
    public const BODY_LIMIT = 8192;

    /** Default per-IP allowance per window. */
    public const RATE_LIMIT = 60;

    /** Rate window in seconds. */
    public const RATE_WINDOW = 60;

    /**
     * Registered event vocabulary: name => event_group. The filter lets
     * site owners and extensions register more names; unknown names are
     * rejected either way (docs/10 §5).
     */
    private const EVENTS = array(
        'pageview' => 'web',
        'signal'   => 'probe',
    );

    /**
     * The complete accepted-field list; anything else in the body is a
     * schema violation. Identity keys are deliberately absent — the
     * server derives them.
     */
    private const FIELDS = array(
        'token',
        'name',
        'event_id',
        'path',
        'bot_score',
        'webdriver',
        'software_renderer',
        'headless_window',
        'language_anomaly',
    );

    /**
     * Boolean conclusion flags the probe may report; raw signal strings
     * and fingerprint material are not accepted (ADR-0007 bridge rule).
     */
    private const FLAG_FIELDS = array(
        'webdriver',
        'software_renderer',
        'headless_window',
        'language_anomaly',
    );

    /**
     * Route registration on rest_api_init.
     *
     * @return void
     */
    public static function register_routes(): void {
        register_rest_route(
            'greenpng/v1',
            '/collect',
            array(
                'methods'             => 'POST',
                'callback'            => array( new self(), 'handle' ),
                'permission_callback' => array( new self(), 'gate' ),
                'args'                => self::args_schema(),
            )
        );
    }

    /**
     * The score at which a session reads as a known bot: the owner's
     * dial, clamped to the column's range. The default of 70 asks for
     * two corroborating signals, so a single automation flag on a real
     * developer's browser never costs them their conversions
     * (ADR-0009 D1).
     *
     * @return int 1..100.
     */
    private static function verdict_threshold(): int {
        $threshold = (int) gr()->settings()->get( 'bot_verdict_threshold', 70 );

        return max( 1, min( 100, $threshold ) );
    }

    /**
     * Today's token, derived from the site salt; not a secret — its job
     * is to make blind mass watering cost a page fetch and to expire
     * daily (docs/02 §2.5). Exposed so the probe enqueue can localize it
     * into pages.
     *
     * @return string
     */
    public static function token(): string {
        $day = substr( current_time( 'mysql' ), 0, 10 );

        return Gr_Secrets::sign_hmac( 'collect|' . $day, wp_salt( 'auth' ) . '|greenpng-collect' );
    }

    /**
     * Script-injection data for the probe (consumed by the enqueue phase).
     *
     * @return array<string, string>
     */
    public static function script_data(): array {
        return array(
            'url'   => esc_url_raw( rest_url( self::ROUTE ) ),
            'token' => self::token(),
        );
    }

    /**
     * Permission-stage gates: token, rate, body size. Runs before schema
     * validation and the controller, so abusive requests never reach
     * either.
     *
     * @param WP_REST_Request $request Incoming request.
     * @return true|WP_Error
     */
    public function gate( WP_REST_Request $request ) {
        $token = $request->get_param( 'token' );
        if ( ! is_string( $token ) || ! hash_equals( self::token(), $token ) ) {
            return new WP_Error(
                'gr_collect_token',
                __( 'Invalid collect token.', 'greenpng' ),
                array( 'status' => 401 )
            );
        }

        $rate   = apply_filters(
            'gr_collect_rate',
            array(
                'limit'  => self::RATE_LIMIT,
                'window' => self::RATE_WINDOW,
            )
        );
        $limit  = is_array( $rate ) && isset( $rate['limit'] ) ? (int) $rate['limit'] : self::RATE_LIMIT;
        $window = is_array( $rate ) && isset( $rate['window'] ) ? (int) $rate['window'] : self::RATE_WINDOW;

        if ( ! Gr_Rate_Limiter::allowed( 'collect', gr_get_client_ip(), $limit, $window ) ) {
            return new WP_Error(
                'gr_collect_rate',
                __( 'Too many collect requests.', 'greenpng' ),
                array( 'status' => 429 )
            );
        }

        if ( strlen( (string) $request->get_body() ) > self::BODY_LIMIT ) {
            return new WP_Error(
                'gr_collect_body',
                __( 'Collect payload too large.', 'greenpng' ),
                array( 'status' => 413 )
            );
        }

        return true;
    }

    /**
     * Serves one validated event: strict schema, server-side identity,
     * dispatch into the stream, session activity slide.
     *
     * @param WP_REST_Request $request Incoming request.
     * @return WP_REST_Response|WP_Error
     */
    public function handle( WP_REST_Request $request ) {
        $events = self::event_map();
        $name   = (string) $request->get_param( 'name' );

        if ( ! isset( $events[ $name ] ) ) {
            return new WP_Error(
                'gr_collect_event',
                __( 'Unknown event name.', 'greenpng' ),
                array( 'status' => 400 )
            );
        }

        $params = $request->get_json_params();
        if ( ! is_array( $params ) ) {
            $params = array();
        }

        $unknown = array_diff( array_keys( $params ), self::FIELDS );
        if ( array() !== $unknown ) {
            return new WP_Error(
                'gr_collect_field',
                __( 'Unknown collect field.', 'greenpng' ),
                array(
                    'status' => 400,
                    'field'  => (string) reset( $unknown ),
                )
            );
        }

        if ( 'signal' === $name && 1 !== (int) gr()->settings()->get( 'probe_enabled' ) ) {
            return new WP_Error(
                'gr_collect_probe',
                __( 'Client signals are disabled.', 'greenpng' ),
                array( 'status' => 400 )
            );
        }

        $violation = $this->validate_event( $name, $params );
        if ( null !== $violation ) {
            return $violation;
        }

        $identity               = gr()->identity();
        $payload                = $this->payload( $name, $params );
        $payload['visitor_id']  = $identity->visitor_id();
        $payload['session_id']  = $identity->session_id();
        $payload['event_id']    = isset( $params['event_id'] ) ? (string) $params['event_id'] : '';
        $payload['event_group'] = $events[ $name ];

        $event = gr_dispatch_event( $name, $payload );

        gr()->sessions()->touch( $payload['visitor_id'], $payload['session_id'] );

        if ( 'signal' === $name && isset( $params['bot_score'] ) && is_int( $params['bot_score'] ) ) {
            // The probe's conclusion lands on the session row
            // (ADR-0009 D1/D2): the server owns the verdict, the client
            // only reports the score it measured.
            gr()->sessions()->apply_probe_score(
                (string) $payload['visitor_id'],
                (string) $payload['session_id'],
                $params['bot_score'],
                $params['bot_score'] >= self::verdict_threshold() ? 1 : 0
            );
        }

        nocache_headers();

        return rest_ensure_response(
            array(
                'stored' => $event->persisted_id() > 0,
                'id'     => $event->persisted_id(),
            )
        );
    }

    /**
     * Per-event field rules beyond the shared args schema: lengths and
     * the signal-specific requirements.
     *
     * @param string               $name   Event name.
     * @param array<string, mixed> $params Body params.
     * @return WP_Error|null
     */
    private function validate_event( string $name, array $params ) {
        if ( isset( $params['path'] ) && strlen( (string) $params['path'] ) > 191 ) {
            return new WP_Error( 'gr_collect_path', __( 'Path too long.', 'greenpng' ), array( 'status' => 400 ) );
        }

        if ( isset( $params['event_id'] ) && strlen( (string) $params['event_id'] ) > 64 ) {
            return new WP_Error( 'gr_collect_event_id', __( 'Event id too long.', 'greenpng' ), array( 'status' => 400 ) );
        }

        if ( 'signal' !== $name ) {
            return null;
        }

        if ( ! isset( $params['bot_score'] ) || ! is_int( $params['bot_score'] ) ) {
            return new WP_Error( 'gr_collect_score', __( 'bot_score is required for signals.', 'greenpng' ), array( 'status' => 400 ) );
        }

        foreach ( self::FLAG_FIELDS as $flag ) {
            if ( isset( $params[ $flag ] ) && ! is_bool( $params[ $flag ] ) ) {
                return new WP_Error( 'gr_collect_flag', __( 'Signal flags must be booleans.', 'greenpng' ), array( 'status' => 400 ) );
            }
        }

        return null;
    }

    /**
     * Strips the transport keys and keeps the event's real payload.
     *
     * @param string               $name   Event name.
     * @param array<string, mixed> $params Body params.
     * @return array<string, mixed>
     */
    private function payload( string $name, array $params ): array {
        unset( $params['token'], $params['name'] );

        if ( ! isset( $params['event_id'] ) ) {
            $params['event_id'] = '';
        }

        return $params;
    }

    /**
     * Registered event map after the filter, with group values checked.
     *
     * @return array<string, string>
     */
    private static function event_map(): array {
        $events = apply_filters( 'gr_collect_events', self::EVENTS );
        if ( ! is_array( $events ) ) {
            return self::EVENTS;
        }

        $map = array();
        foreach ( $events as $event_name => $group ) {
            if ( is_string( $event_name ) && is_string( $group ) && '' !== $event_name && '' !== $group ) {
                $map[ $event_name ] = $group;
            }
        }

        return $map;
    }

    /**
     * Shared REST args schema: types and the enum whitelist. The token is
     * deliberately not marked required here — core checks required params
     * before the permission stage, and the gate must own the 401 for a
     * missing token (docs/02 §2.5). Names are re-checked in handle()
     * against the same filtered map.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function args_schema(): array {
        return array(
            'token'             => array( 'type' => 'string' ),
            'name'              => array(
                'type' => 'string',
                'enum' => array_keys( self::event_map() ),
            ),
            'event_id'          => array( 'type' => 'string' ),
            'path'              => array( 'type' => 'string' ),
            'bot_score'         => array(
                'type'    => 'integer',
                'minimum' => 0,
                'maximum' => 100,
            ),
            'webdriver'         => array( 'type' => 'boolean' ),
            'software_renderer' => array( 'type' => 'boolean' ),
            'headless_window'   => array( 'type' => 'boolean' ),
            'language_anomaly'  => array( 'type' => 'boolean' ),
        );
    }
}
