<?php
/**
 * Global function facades (docs/03): thin forwards only, three statements
 * of body at most; all logic lives in the classes these functions point
 * at. Loaded once from the entry file right after the autoloader because
 * plain functions cannot be autoloaded.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Attribution\Gr_Attribution_Models;
use GreenPNG\Attribution\Gr_Attribution_Params;
use GreenPNG\Core\Gr_Event;
use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Core\Gr_Request;
use GreenPNG\Core\Gr_Secrets;
use GreenPNG\Security\Gr_Ip_Resolver;
use GreenPNG\Storage\Gr_Touchpoint_Repository;

if ( ! function_exists( 'gr' ) ) {
    /**
     * Main container accessor (docs/03 §1).
     *
     * @return Gr_Plugin
     */
    function gr(): Gr_Plugin {
        return Gr_Plugin::instance();
    }
}

if ( ! function_exists( 'gr_get_client_ip' ) ) {
    /**
     * Client IP under the trust policy (docs/03 §1, docs/10 §1).
     *
     * @return string
     */
    function gr_get_client_ip(): string {
        return Gr_Ip_Resolver::resolve();
    }
}

if ( ! function_exists( 'gr_get_user_agent' ) ) {
    /**
     * Sanitized user agent, 512-char cap (docs/03 §1).
     *
     * @return string
     */
    function gr_get_user_agent(): string {
        return Gr_Request::user_agent();
    }
}

if ( ! function_exists( 'gr_generate_event_id' ) ) {
    /**
     * Idempotency-safe event id (docs/03 §1).
     *
     * @param string $prefix  Id prefix.
     * @param string $entropy Determinism seed for replay convergence.
     * @return string
     */
    function gr_generate_event_id( string $prefix = 'gr', string $entropy = '' ): string {
        return Gr_Secrets::generate_event_id( $prefix, $entropy );
    }
}

if ( ! function_exists( 'gr_hash_pii' ) ) {
    /**
     * Normalized SHA-256 for PII joins (docs/03 §1).
     *
     * @param string $value Raw value.
     * @param string $type  PII kind.
     * @return string
     */
    function gr_hash_pii( string $value, string $type ): string {
        return Gr_Secrets::hash_pii( $value, $type );
    }
}

if ( ! function_exists( 'gr_sign_hmac' ) ) {
    /**
     * HMAC-SHA256 signature (docs/03 §1).
     *
     * @param string $data   Payload being signed.
     * @param string $secret Shared secret.
     * @return string
     */
    function gr_sign_hmac( string $data, string $secret ): string {
        return Gr_Secrets::sign_hmac( $data, $secret );
    }
}

if ( ! function_exists( 'gr_dispatch_event' ) ) {
    /**
     * Event dispatch facade (docs/03 §2).
     *
     * @param string               $name    Event name.
     * @param array<string, mixed> $payload Event payload.
     * @return Gr_Event
     */
    function gr_dispatch_event( string $name, array $payload = array() ): Gr_Event {
        return gr()->events()->dispatch( $name, $payload );
    }
}

if ( ! function_exists( 'gr_get_recent_events' ) ) {
    /**
     * Recent event feed facade (docs/03 §2).
     *
     * @param string $name  Optional event-name filter.
     * @param int    $limit Row ceiling.
     * @return array<int, array<string, mixed>>
     */
    function gr_get_recent_events( string $name = '', int $limit = 50 ): array {
        return gr()->events()->recent( $name, $limit );
    }
}

if ( ! function_exists( 'gr_parse_attribution_params' ) ) {
    /**
     * Attribution parameter extraction (docs/03 §4).
     *
     * @param array<string, mixed> $params Raw query params.
     * @return array<string, string>
     */
    function gr_parse_attribution_params( array $params ): array {
        return Gr_Attribution_Params::parse( $params );
    }
}

if ( ! function_exists( 'gr_record_touchpoint' ) ) {
    /**
     * Touchpoint write facade (docs/03 §4).
     *
     * @param string               $visitor_id Visitor identity.
     * @param array<string, mixed> $params     Parsed attribution columns.
     * @param string               $session_id Visit identity.
     * @param string               $url        Landing URL.
     * @return int
     */
    function gr_record_touchpoint( string $visitor_id, array $params, string $session_id = '', string $url = '' ): int {
        return ( new Gr_Touchpoint_Repository() )->record( $visitor_id, $params, $session_id, $url );
    }
}

if ( ! function_exists( 'gr_get_touchpoints' ) ) {
    /**
     * Touchpoint sequence facade (docs/03 §4).
     *
     * @param string $visitor_id Visitor identity.
     * @param int    $days       Window in days.
     * @return array<int, array<string, mixed>>
     */
    function gr_get_touchpoints( string $visitor_id, int $days = 30 ): array {
        return ( new Gr_Touchpoint_Repository() )->get_for_visitor( $visitor_id, $days );
    }
}

if ( ! function_exists( 'gr_calculate_attribution' ) ) {
    /**
     * Five-model attribution split facade (docs/03 §4).
     *
     * @param array<int, array<string, mixed>> $touchpoints Touchpoint rows.
     * @param float                            $amount      Conversion amount.
     * @return array<string, array<int, array{weight: float, amount: float}>>
     */
    function gr_calculate_attribution( array $touchpoints, float $amount ): array {
        return Gr_Attribution_Models::calculate( $touchpoints, $amount );
    }
}
