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
 * The behavior module (ADR-0012) rides the same route with a batch
 * envelope: the client flushes once per page under the 'behavior'
 * name carrying at most 20 inner events (dwell, scroll_depth,
 * rage_click, dead_click), each validated against its own field
 * whitelist before its own dispatch. Behavior events additionally
 * pass two purpose gates: marketing consent (the marketing track's
 * checkpoint) and a prefetch refusal — a prefetched page is not a
 * visit, so it must not count behavior.
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
use GreenPNG\Privacy\Gr_Consent;
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

    /** Inner events one behavior batch may carry (ADR-0012 D3). */
    public const BEHAVIOR_BATCH_CAP = 20;

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
     * The complete accepted-field list across every event; anything
     * else in the body is a transport-layer schema violation. Identity
     * keys are deliberately absent — the server derives them. Tighter
     * per-event rules follow in validate_event().
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
        'events',
        'bucket',
        'milestone',
        'clicks',
        'locator',
    );

    /**
     * Behavior vocabulary: the inner event names the batch envelope
     * may carry, each with its own accepted fields (ADR-0012 D2 —
     * whitelists tighten per event, never per route).
     */
    private const BEHAVIOR_FIELDS = array(
        'dwell'        => array( 'path', 'event_id', 'bucket' ),
        'scroll_depth' => array( 'path', 'event_id', 'milestone' ),
        'rage_click'   => array( 'path', 'event_id', 'clicks', 'locator' ),
        'dead_click'   => array( 'path', 'event_id', 'locator' ),
    );

    /**
     * Dwell bucket vocabulary: the four engagement bands the client
     * reports seconds through (ADR-0012 D2).
     */
    private const DWELL_BUCKETS = array( '0-15', '15-60', '60-180', '180+' );

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
     * dispatch into the stream, session activity slide. The behavior
     * envelope unwraps into its inner events, each dispatched on its
     * own row with its own validation.
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

        // Purpose gates for the behavior group: consent first (the
        // marketing track's checkpoint, ADR-0005), then the prefetch
        // refusal — a prefetched page is not a visit.
        if ( 'behavior' === $events[ $name ] ) {
            if ( ! Gr_Consent::allows( 'marketing' ) ) {
                return new WP_Error(
                    'gr_collect_consent',
                    __( 'Behavior events require marketing consent.', 'greenpng' ),
                    array( 'status' => 400 )
                );
            }

            if ( $this->is_prefetch( $request ) ) {
                return new WP_Error(
                    'gr_collect_prefetch',
                    __( 'Prefetched pages do not count behavior.', 'greenpng' ),
                    array( 'status' => 400 )
                );
            }
        }

        $violation = $this->validate_event( $name, $params );
        if ( null !== $violation ) {
            return $violation;
        }

        $identity = gr()->identity();

        // The batch envelope: validate every inner event, then store
        // each on its own row. A violation anywhere rejects the whole
        // batch — partial acceptance would tell the client less than
        // the truth.
        if ( 'behavior' === $name ) {
            $inner = $params['events'];
            $rows  = array();
            foreach ( (array) $inner as $event ) {
                if ( ! is_array( $event ) ) {
                    return new WP_Error( 'gr_collect_batch', __( 'Malformed behavior batch.', 'greenpng' ), array( 'status' => 400 ) );
                }
                $rows[] = $event;
            }

            if ( count( $rows ) > self::BEHAVIOR_BATCH_CAP ) {
                return new WP_Error( 'gr_collect_batch', __( 'Behavior batch too large.', 'greenpng' ), array( 'status' => 400 ) );
            }

            foreach ( $rows as $event ) {
                $inner_name = isset( $event['name'] ) ? (string) $event['name'] : '';
                $violation  = $this->validate_behavior_event( $inner_name, $event );
                if ( null !== $violation ) {
                    return $violation;
                }
            }

            $stored   = 0;
            $first_id = 0;
            foreach ( $rows as $event ) {
                $inner_name = (string) $event['name'];
                $row_event  = $this->store_event( $inner_name, $events, $event, $identity->visitor_id(), $identity->session_id() );
                if ( $row_event->persisted_id() > 0 ) {
                    ++$stored;
                    if ( 0 === $first_id ) {
                        $first_id = $row_event->persisted_id();
                    }
                }
            }

            gr()->sessions()->touch( $identity->visitor_id(), $identity->session_id() );
            nocache_headers();

            return rest_ensure_response(
                array(
                    'stored' => $stored > 0,
                    'id'     => $first_id,
                )
            );
        }

        $event = $this->store_event( $name, $events, $params, $identity->visitor_id(), $identity->session_id() );

        gr()->sessions()->touch( $identity->visitor_id(), $identity->session_id() );

        if ( 'signal' === $name && isset( $params['bot_score'] ) && is_int( $params['bot_score'] ) ) {
            // The probe's conclusion lands on the session row
            // (ADR-0009 D1/D2): the server owns the verdict, the client
            // only reports the score it measured.
            gr()->sessions()->apply_probe_score(
                (string) $identity->visitor_id(),
                (string) $identity->session_id(),
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
     * Builds and dispatches one event row with the server-derived
     * identity; transport keys never reach the payload.
     *
     * @param string                $name       Event name.
     * @param array<string, string> $group_map  Registered names and groups.
     * @param array<string, mixed>  $params     Body params.
     * @param string                $visitor_id Server identity.
     * @param string                $session_id Server identity.
     * @return \GreenPNG\Core\Gr_Event
     */
    private function store_event( string $name, array $group_map, array $params, string $visitor_id, string $session_id ) {
        $payload = $params;
        unset( $payload['token'], $payload['name'], $payload['events'] );

        if ( ! isset( $payload['event_id'] ) ) {
            $payload['event_id'] = '';
        } else {
            $payload['event_id'] = (string) $payload['event_id'];
        }

        // The locator carries the structural form whatever the client
        // sent; validation already refused junk-only values.
        if ( isset( $payload['locator'] ) ) {
            $payload['locator'] = $this->sanitize_locator( (string) $payload['locator'] );
        }

        $payload['visitor_id']  = $visitor_id;
        $payload['session_id']  = $session_id;
        $payload['event_group'] = $group_map[ $name ];

        return gr_dispatch_event( $name, $payload );
    }

    /**
     * Prefetch detection on the fetch-metadata purpose header: a
     * prefetch or prerender fetch is the browser warming the cache,
     * not a visit.
     *
     * @param WP_REST_Request $request Incoming request.
     * @return bool
     */
    private function is_prefetch( WP_REST_Request $request ): bool {
        $purpose = (string) $request->get_header( 'Sec-Purpose' );

        if ( '' === $purpose ) {
            return false;
        }

        return false !== stripos( $purpose, 'prefetch' ) || false !== stripos( $purpose, 'prerender' );
    }

    /**
     * Per-event field rules beyond the shared args schema: lengths, the
     * envelope shape, and the per-event key whitelists (ADR-0012 D2).
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

        if ( 'behavior' === $name ) {
            if ( ! isset( $params['events'] ) || ! is_array( $params['events'] ) ) {
                return new WP_Error( 'gr_collect_batch', __( 'Behavior batches carry an events array.', 'greenpng' ), array( 'status' => 400 ) );
            }

            return null;
        }

        if ( isset( self::BEHAVIOR_FIELDS[ $name ] ) ) {
            // A direct post of one behavior event: same per-event rules
            // the batch applies to its inner events.
            return $this->validate_behavior_event( $name, $params );
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
     * One inner behavior event against its own whitelist: the name
     * must be one of the four (no nested envelopes), the fields must
     * be exactly that event's, and each value must sit in its
     * vocabulary — buckets in the four bands, milestones in the four
     * steps, click counts plausible, locators in the structural
     * charset the client builds them from.
     *
     * @param string               $name   Inner event name.
     * @param array<string, mixed> $params Inner event body.
     * @return WP_Error|null
     */
    private function validate_behavior_event( string $name, array $params ) {
        if ( ! isset( self::BEHAVIOR_FIELDS[ $name ] ) ) {
            return new WP_Error( 'gr_collect_event', __( 'Unknown behavior event name.', 'greenpng' ), array( 'status' => 400 ) );
        }

        // Direct posts still carry the transport keys at this point;
        // the batch's inner events never do. Stripping both here keeps
        // one whitelist for the two shapes.
        $body = $params;
        unset( $body['token'], $body['name'] );

        $unknown = array_diff( array_keys( $body ), self::BEHAVIOR_FIELDS[ $name ] );
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

        if ( isset( $params['path'] ) && strlen( (string) $params['path'] ) > 191 ) {
            return new WP_Error( 'gr_collect_path', __( 'Path too long.', 'greenpng' ), array( 'status' => 400 ) );
        }

        if ( 'dwell' === $name ) {
            if ( ! isset( $params['bucket'] ) || ! in_array( (string) $params['bucket'], self::DWELL_BUCKETS, true ) ) {
                return new WP_Error( 'gr_collect_bucket', __( 'Dwell bucket outside the vocabulary.', 'greenpng' ), array( 'status' => 400 ) );
            }
        }

        if ( 'scroll_depth' === $name ) {
            if ( ! isset( $params['milestone'] ) || ! is_int( $params['milestone'] ) || ! in_array( $params['milestone'], array( 25, 50, 75, 100 ), true ) ) {
                return new WP_Error( 'gr_collect_milestone', __( 'Scroll milestone outside the vocabulary.', 'greenpng' ), array( 'status' => 400 ) );
            }
        }

        if ( 'rage_click' === $name ) {
            if ( ! isset( $params['clicks'] ) || ! is_int( $params['clicks'] ) || $params['clicks'] < 3 || $params['clicks'] > 100 ) {
                return new WP_Error( 'gr_collect_clicks', __( 'Rage click count outside the plausible range.', 'greenpng' ), array( 'status' => 400 ) );
            }
        }

        if ( isset( $params['locator'] ) ) {
            $locator = $this->sanitize_locator( (string) $params['locator'] );
            if ( '' === $locator && '' !== (string) $params['locator'] ) {
                return new WP_Error( 'gr_collect_locator', __( 'Locator outside the structural charset.', 'greenpng' ), array( 'status' => 400 ) );
            }
        }

        return null;
    }

    /**
     * Locator re-sanitization server-side: tag, optional id or class
     * mark, at most 64 characters — the structural vocabulary the
     * client builds, with everything outside it clipped away.
     *
     * @param string $locator Client locator.
     * @return string
     */
    private function sanitize_locator( string $locator ): string {
        $clean = preg_replace( '/[^A-Za-z0-9_.#-]/', '', substr( $locator, 0, 64 ) );

        return is_string( $clean ) ? $clean : '';
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
            'events'            => array(
                'type' => 'array',
            ),
            'bucket'            => array(
                'type' => 'string',
                'enum' => self::DWELL_BUCKETS,
            ),
            'milestone'         => array(
                'type'    => 'integer',
                'minimum' => 25,
                'maximum' => 100,
            ),
            'clicks'            => array(
                'type'    => 'integer',
                'minimum' => 3,
                'maximum' => 100,
            ),
            'locator'           => array( 'type' => 'string' ),
        );
    }
}
