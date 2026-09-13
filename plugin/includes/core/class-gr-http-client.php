<?php
/**
 * Unified outbound channel (docs/07 §2, ADR-0007): every
 * wp_safe_remote_* call the plugin ever makes goes through this
 * class, so the timeout cap, the consecutive-failure breaker, 429
 * Retry-After, and the give-up ledger behave identically for every
 * service. The class never dispatches work itself: callers run
 * inside Gr_Queue jobs (iron rule 3 — no synchronous outbound in a
 * front-end request), read the retry hint from the returned error's
 * data, and reschedule the job with it. Three retries at 30s/2m/15m,
 * then the attempt is given up and one audit row records it.
 *
 * Failure state lives in one transient per service — never in an
 * autoloaded option, because it grows with traffic (iron rule 3).
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Storage\Gr_Audit_Repository;

/**
 * The only sanctioned outbound door.
 */
final class Gr_Http_Client {

    /** Service key: Meta Conversions API. */
    public const SERVICE_META_CAPI = 'meta_capi';

    /** Service key: GA4 Measurement Protocol. */
    public const SERVICE_GA4_MP = 'ga4_mp';

    /** Service key: the DB-IP data refresh (no credentials, owner-clicked). */
    public const SERVICE_DBIP = 'dbip_update';

    /** Service not enabled with its credentials: nothing leaves the site. */
    public const ERR_NOT_CONFIGURED = 'gr_not_configured';

    /** The breaker is open: fail fast without touching the network. */
    public const ERR_BREAKER_OPEN = 'gr_breaker_open';

    /** A retry arrived before its scheduled time: fail fast. */
    public const ERR_BACKOFF_WAIT = 'gr_backoff_wait';

    /** The service answered 429 and its Retry-After was recorded. */
    public const ERR_RATE_LIMITED = 'gr_rate_limited';

    /** The backoff budget is spent; the caller must stop rescheduling. */
    public const ERR_GIVE_UP = 'gr_http_give_up';

    /** One attempt failed; retry_after in the error data is the reschedule delay. */
    public const ERR_FAILED = 'gr_http_failed';

    /** Request ceiling for every service (docs/07 §2). */
    private const TIMEOUT = 5;

    /** Consecutive failures that open the breaker. */
    private const BREAKER_THRESHOLD = 3;

    /** How long an open breaker stays open. */
    private const BREAKER_SECONDS = 300;

    /** Backoff ladder; the third failure is the last retry, the fourth gives up. */
    private const BACKOFF = array( 30, 120, 900 );

    /** Ceiling for a server-sent Retry-After, so a hostile value cannot pin the service for hours. */
    private const RETRY_CAP = 3600;

    /**
     * GET one URL for a service.
     *
     * @param string               $service Service key constant.
     * @param string               $url     Absolute https URL.
     * @param array<string, mixed> $args Extra wp_safe_remote_get arguments.
     * @return array{code: int, body: string}|\WP_Error Success shape, or
     *         a coded error whose data may carry retry_after.
     */
    public static function get( string $service, string $url, array $args = array() ) {
        return self::request( $service, 'GET', $url, $args );
    }

    /**
     * POST a JSON payload for a service.
     *
     * @param string               $service Service key constant.
     * @param string               $url     Absolute https URL.
     * @param array<string, mixed> $payload JSON body.
     * @return array{code: int, body: string}|\WP_Error
     */
    public static function post( string $service, string $url, array $payload = array() ) {
        $args = array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $payload ),
        );

        return self::request( $service, 'POST', $url, $args );
    }

    /**
     * One service's current posture for the status page: configuration,
     * consecutive failures, breaker deadline, next-allowed time.
     *
     * @param string $service Service key constant.
     * @return array{configured: bool, fails: int, open_until: int, next_at: int}
     */
    public static function service_state( string $service ): array {
        $state = self::read_state( $service );

        return array(
            'configured' => self::configured( $service ),
            'fails'      => (int) $state['fails'],
            'open_until' => (int) $state['open_until'],
            'next_at'    => (int) $state['next_at'],
        );
    }

    /**
     * Whether the owner has enabled a service and stored its full
     * credential pair. Capture-side producers ask this before queueing
     * anything, so an off service never even manufactures work — the
     * request path re-checks at send time regardless, which is what
     * actually keeps the network quiet.
     *
     * @param string $service Service key constant.
     * @return bool
     */
    public static function is_configured( string $service ): bool {
        return self::configured( $service );
    }

    /**
     * The shared request path: gates, one wire call, state transitions.
     *
     * @param string               $service Service key constant.
     * @param string               $method  'GET' or 'POST'.
     * @param string               $url     Absolute https URL.
     * @param array<string, mixed> $args Prepared wp_safe_remote_* arguments.
     * @return array{code: int, body: string}|\WP_Error
     */
    private static function request( string $service, string $method, string $url, array $args ) {
        if ( ! self::configured( $service ) ) {
            // No credentials, no outbound, no fake success (S4).
            return new \WP_Error( self::ERR_NOT_CONFIGURED, 'Service is not configured.' );
        }

        $state = self::read_state( $service );
        $now   = time();

        if ( $now < (int) $state['open_until'] ) {
            // The breaker answers without a network round trip and
            // without counting this as another failure.
            return new \WP_Error(
                self::ERR_BREAKER_OPEN,
                'Failure breaker is open.',
                array( 'retry_after' => (int) $state['open_until'] - $now )
            );
        }

        if ( $now < (int) $state['next_at'] ) {
            // A premature retry is a fast-fail too: the caller was told
            // when the next attempt is allowed.
            return new \WP_Error(
                self::ERR_BACKOFF_WAIT,
                'Retry scheduled later.',
                array( 'retry_after' => (int) $state['next_at'] - $now )
            );
        }

        $args['timeout'] = min( self::TIMEOUT, (int) ( $args['timeout'] ?? self::TIMEOUT ) );

        if ( 'GET' === $method ) {
            $response = wp_safe_remote_get( $url, $args );
        } else {
            $response = wp_safe_remote_post( $url, $args );
        }

        if ( is_wp_error( $response ) ) {
            return self::record_failure( $service, (string) $response->get_error_message(), 0 );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );

        if ( 429 === $code ) {
            // The server's own Retry-After replaces the ladder position.
            $after = (int) wp_remote_retrieve_header( $response, 'Retry-After' );

            return self::record_failure( $service, 'Rate limited.', $after, true );
        }

        if ( $code < 200 || $code > 299 ) {
            return self::record_failure( $service, 'HTTP ' . $code . ' response.', 0 );
        }

        // Success closes the breaker and cancels the pending backoff.
        delete_transient( 'gr_http_' . $service );

        return array(
            'code' => $code,
            'body' => (string) wp_remote_retrieve_body( $response ),
        );
    }

    /**
     * Failure bookkeeping: the ladder climb, the breaker, the give-up.
     *
     * @param string $service Service key constant.
     * @param string $message Wire-level reason, for logs only.
     * @param int    $retry_after Server-sent Retry-After, 0 when absent.
     * @param bool   $rate_limited Whether this failure was a 429.
     * @return \WP_Error The error the caller should act on.
     */
    private static function record_failure( string $service, string $message, int $retry_after = 0, bool $rate_limited = false ) {
        $state = self::read_state( $service );
        $fails = (int) $state['fails'] + 1;

        if ( $fails > count( self::BACKOFF ) ) {
            // The budget is spent: stop rescheduling, leave one audit
            // row, and start the next owner action from a clean slate.
            delete_transient( 'gr_http_' . $service );

            $audit = new Gr_Audit_Repository();
            $audit->log(
                'http_give_up',
                'http_service',
                $service,
                array(),
                array( 'consecutive_failures' => $fails ),
                0
            );

            return new \WP_Error( self::ERR_GIVE_UP, 'Outbound retries exhausted.' );
        }

        $delay = $rate_limited ? $retry_after : (int) self::BACKOFF[ $fails - 1 ];
        if ( $delay <= 0 ) {
            // A 429 without a usable Retry-After still waits one step.
            $delay = (int) self::BACKOFF[ $fails - 1 ];
        }
        if ( $delay > self::RETRY_CAP ) {
            $delay = self::RETRY_CAP;
        }

        $state['fails']      = $fails;
        $state['next_at']    = time() + $delay;
        $state['open_until'] = $fails >= self::BREAKER_THRESHOLD
            ? time() + self::BREAKER_SECONDS
            : (int) $state['open_until'];

        set_transient( 'gr_http_' . $service, $state, self::RETRY_CAP + self::BREAKER_SECONDS );

        $code = $rate_limited ? self::ERR_RATE_LIMITED : self::ERR_FAILED;

        return new \WP_Error(
            $code,
            $message,
            array(
                'retry_after' => $delay,
                'fails'       => $fails,
            )
        );
    }

    /**
     * Reads one service's state transient with the neutral default.
     *
     * @param string $service Service key constant.
     * @return array{fails: int, open_until: int, next_at: int}
     */
    private static function read_state( string $service ): array {
        $state = get_transient( 'gr_http_' . $service );

        if ( ! is_array( $state ) ) {
            return array(
                'fails'      => 0,
                'open_until' => 0,
                'next_at'    => 0,
            );
        }

        return array(
            'fails'      => (int) ( $state['fails'] ?? 0 ),
            'open_until' => (int) ( $state['open_until'] ?? 0 ),
            'next_at'    => (int) ( $state['next_at'] ?? 0 ),
        );
    }

    /**
     * Whether the owner has enabled the service and stored its full
     * credential pair (docs/07 §1: opt-in defaults, no half-configured
     * dispatch — a missing half reads as not configured, never as a
     * guess).
     *
     * @param string $service Service key constant.
     * @return bool
     */
    private static function configured( string $service ): bool {
        if ( self::SERVICE_DBIP === $service ) {
            // The data refresh has no credentials; the only gate is the
            // owner's explicit click, which is the caller's job.
            return true;
        }

        $settings = new Gr_Settings();

        if ( self::SERVICE_META_CAPI === $service ) {
            return 1 === (int) $settings->get( 'capi_meta_enabled' )
                && '' !== Gr_Secrets::reveal( Gr_Secrets::META_PIXEL_OPTION )
                && '' !== Gr_Secrets::reveal( Gr_Secrets::META_TOKEN_OPTION );
        }

        if ( self::SERVICE_GA4_MP === $service ) {
            return 1 === (int) $settings->get( 'capi_ga4_enabled' )
                && '' !== Gr_Secrets::reveal( Gr_Secrets::GA4_ID_OPTION )
                && '' !== Gr_Secrets::reveal( Gr_Secrets::GA4_SECRET_OPTION );
        }

        // Unknown service keys never dispatch: the vocabulary is the
        // const list, and anything else is a programming error.
        return false;
    }
}
