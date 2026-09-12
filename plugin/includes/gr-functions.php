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
use GreenPNG\Funnel\Gr_Ab_Engine;
use GreenPNG\Funnel\Gr_Ab_Recorder;
use GreenPNG\Funnel\Gr_Ab_Significance;
use GreenPNG\Integrations\Gr_Ecosystem_Detector;
use GreenPNG\Integrations\Gr_Semantic_Extractor;
use GreenPNG\Security\Gr_Access_Rules;
use GreenPNG\Security\Gr_Crawler_Verify;
use GreenPNG\Security\Gr_Honeypot;
use GreenPNG\Security\Gr_Ip_Mask;
use GreenPNG\Security\Gr_Ip_Matcher;
use GreenPNG\Security\Gr_Ip_Resolver;
use GreenPNG\Security\Gr_Login_Protection;
use GreenPNG\Security\Gr_Payload_Inspector;
use GreenPNG\Security\Gr_Scanner_Ua;
use GreenPNG\Security\Gr_Temp_Bans;
use GreenPNG\Storage\Gr_Security_Log_Repository;
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

if ( ! function_exists( 'gr_bind_conversion' ) ) {
    /**
     * Permanent conversion binding facade (docs/03 §4): idempotent by
     * the gr_conversions source_unique key; replays return the bound
     * row's id. Callers own the consent gate (v1.0: cookie track only).
     *
     * @param int    $order_id    Order or form submission id.
     * @param string $visitor_id  Visitor identity (cookie track).
     * @param float  $amount      Conversion amount.
     * @param string $currency    Three-letter currency code.
     * @param string $source_type 'woocommerce' or a form adapter id.
     * @return int
     */
    function gr_bind_conversion( int $order_id, string $visitor_id, float $amount, string $currency, string $source_type = 'woocommerce' ): int {
        return gr()->attribution()->bind( $order_id, $visitor_id, $amount, $currency, $source_type );
    }
}

if ( ! function_exists( 'gr_uif_extract_fields' ) ) {
    /**
     * Semantic field extraction facade (docs/03 §7): walks any payload
     * shape and returns the normalized identity/commerce fields. This
     * is what the auto:email / auto:name / auto:amount expression
     * family resolves to.
     *
     * @param mixed $payload Submission data in source-plugin shape.
     * @return array<string, mixed> Recognized fields, detected_keys,
     *                              custom_fields.
     */
    function gr_uif_extract_fields( $payload ): array {
        return Gr_Semantic_Extractor::extract( $payload );
    }
}

if ( ! function_exists( 'gr_uif_detect_ecosystem' ) ) {
    /**
     * Ecosystem detection facade (docs/03 §7): reports which bridges
     * can activate from one active_plugins read.
     *
     * @return array<string, array<string, mixed>> bridge_id => plugin,
     *        active.
     */
    function gr_uif_detect_ecosystem(): array {
        return Gr_Ecosystem_Detector::detect();
    }
}

if ( ! function_exists( 'gr_ab_assign_variant' ) ) {
    /**
     * A/B assignment facade (docs/03 §5): consistent-hash bucketing,
     * stable per visitor, with the ?gr_variant= force parameter.
     *
     * @param string $experiment Experiment key.
     * @param string $visitor_id Visitor identity (either track).
     * @return string Variant slug, '' when the experiment cannot run.
     */
    function gr_ab_assign_variant( string $experiment, string $visitor_id ): string {
        return Gr_Ab_Engine::assign( $experiment, $visitor_id );
    }
}

if ( ! function_exists( 'gr_ab_record' ) ) {
    /**
     * A/B exposure/conversion record facade (docs/03 §5): the event
     * lands in gr_events with the dedicated ab_* columns.
     *
     * @param string $experiment Experiment key.
     * @param string $variant    Variant slug.
     * @param string $type       'impression' or 'conversion'.
     * @return bool
     */
    function gr_ab_record( string $experiment, string $variant, string $type ): bool {
        return Gr_Ab_Recorder::record( $experiment, $variant, $type );
    }
}

if ( ! function_exists( 'gr_ab_significance' ) ) {
    /**
     * A/B significance facade (docs/03 §5): two-proportion Z-test,
     * control versus every other variant; n<30 arms read insufficient.
     *
     * @param string $experiment Experiment key.
     * @return array<string, mixed> status/variants/pairs.
     */
    function gr_ab_significance( string $experiment ): array {
        return Gr_Ab_Significance::calculate( $experiment );
    }
}

if ( ! function_exists( 'gr_is_scanner_ua' ) ) {
    /**
     * Scanner-UA facade (docs/07 §3): the self-maintained CrawlerDetect
     * seed decides crawler verdicts locally; unreadable data or a PCRE
     * failure reads as an ordinary visitor.
     *
     * @param string $ua Raw user agent.
     * @return bool True when the agent matches a crawler pattern.
     */
    function gr_is_scanner_ua( string $ua ): bool {
        return Gr_Scanner_Ua::is_scanner( $ua );
    }
}

if ( ! function_exists( 'gr_inspect_request_payload' ) ) {
    /**
     * Payload-inspection facade (docs/03 §3, docs/10 §4): the rewritten
     * conservative ruleset — SQL UNION injection shapes and multi-hop
     * path traversal chains only, on whatever data the caller chooses.
     * Findings are markers, never blocks.
     *
     * @param array<int|string, mixed> $data Parameter name => raw value.
     * @return array<int, array<string, string>> rule_id + reason rows.
     */
    function gr_inspect_request_payload( array $data ): array {
        return Gr_Payload_Inspector::inspect( $data );
    }
}

if ( ! function_exists( 'gr_verify_crawler' ) ) {
    /**
     * FCrDNS facade (docs/03 §3): forward-confirmed reverse DNS over
     * both A and AAAA, 24h transient-cached; every failure reads as
     * "unverified", never as "forged". Does real DNS work — the
     * front-end path enqueues instead of calling here (iron rule 3).
     *
     * @param string $ip Client address as text.
     * @param string $ua Claimed user agent.
     * @return array{status: string, host: string, ip: string, ua: string, checked_at: int}
     */
    function gr_verify_crawler( string $ip, string $ua ): array {
        return Gr_Crawler_Verify::verify( $ip, $ua );
    }
}

if ( ! function_exists( 'gr_match_cidr' ) ) {
    /**
     * CIDR facade (docs/03 §3): one address against one CIDR (or bare
     * IP); IPv4 and IPv6 share the byte-prefix comparison, and the two
     * families never intermatch.
     *
     * @param string $ip   Candidate address.
     * @param string $cidr CIDR ('a.b.c.d/nn', 'x::/nn') or bare IP.
     * @return bool
     */
    function gr_match_cidr( string $ip, string $cidr ): bool {
        return Gr_Ip_Matcher::match_cidr( $ip, $cidr );
    }
}

if ( ! function_exists( 'gr_mask_ip' ) ) {
    /**
     * Display mask facade (docs/03 §3): last segment hidden, storage
     * form untouched.
     *
     * @param string $ip Textual address.
     * @return string
     */
    function gr_mask_ip( string $ip ): string {
        return Gr_Ip_Mask::mask( $ip );
    }
}

if ( ! function_exists( 'gr_is_trusted_ip' ) ) {
    /**
     * Allow-list facade (docs/03 §3): active allow rules whose IP
     * value (CIDR or bare) covers the address.
     *
     * @param string $ip Candidate address.
     * @return bool
     */
    function gr_is_trusted_ip( string $ip ): bool {
        return Gr_Access_Rules::is_trusted_ip( $ip );
    }
}

if ( ! function_exists( 'gr_is_ip_blocked' ) ) {
    /**
     * Ban facade (docs/03 §3): active ban rules covering the address.
     * The allow list is evaluated first and always wins, so a trusted
     * address never reads as blocked.
     *
     * @param string $ip Candidate address.
     * @return bool
     */
    function gr_is_ip_blocked( string $ip ): bool {
        return Gr_Access_Rules::is_ip_blocked( $ip );
    }
}

if ( ! function_exists( 'gr_is_url_allowed' ) ) {
    /**
     * URL allow-list facade (docs/03 §3): plain values match one whole
     * path segment onward, wildcard values match the whole URI with
     * '*' as any run of characters.
     *
     * @param string $uri Request path, optionally with query.
     * @return bool
     */
    function gr_is_url_allowed( string $uri ): bool {
        return Gr_Access_Rules::is_url_allowed( $uri );
    }
}

if ( ! function_exists( 'gr_block_ip' ) ) {
    /**
     * Temporary-ban facade (docs/03 §3): transient lock with a TTL,
     * bounded to 30 days — permanent bans belong in the rules table.
     *
     * @param string $ip     Address to lock.
     * @param string $reason Free-text cause, kept for audit display.
     * @param int    $ttl    Seconds.
     * @return void
     */
    function gr_block_ip( string $ip, string $reason = '', int $ttl = DAY_IN_SECONDS ): void {
        Gr_Temp_Bans::block( $ip, $reason, $ttl );
    }
}

if ( ! function_exists( 'gr_unblock_ip' ) ) {
    /**
     * Temporary-ban release facade (docs/03 §3); the allow list stays
     * the recovery valve that works without CLI access.
     *
     * @param string $ip Address to free.
     * @return void
     */
    function gr_unblock_ip( string $ip ): void {
        Gr_Temp_Bans::unblock( $ip );
    }
}

if ( ! function_exists( 'gr_log_security_event' ) ) {
    /**
     * Surge-fold log facade (docs/03 §3): one atomic upsert per hit —
     * the fold collapses row count under md5(ip|rule|hour), never the
     * number of writes.
     *
     * @param string $ip      Client address as text.
     * @param string $rule_id Rule identifier.
     * @param string $url     Request path.
     * @param string $ua      User agent.
     * @param string $reason  Free-text cause.
     * @return void
     */
    function gr_log_security_event( string $ip, string $rule_id, string $url = '', string $ua = '', string $reason = '' ): void {
        ( new Gr_Security_Log_Repository() )->log( $ip, $rule_id, $url, $ua, $reason );
    }
}

if ( ! function_exists( 'gr_check_login_lockout' ) ) {
    /**
     * Login lockout judgment facade (docs/03 §3): failure count,
     * threshold, gradient round, and remaining seconds for one
     * address+username pair; the allow list reads as never locked.
     *
     * @param string $username Attempted username.
     * @param string $ip       Client address as text.
     * @return array<string, int|bool> locked/remaining/failures/threshold/round.
     */
    function gr_check_login_lockout( string $username, string $ip ): array {
        return Gr_Login_Protection::check_lockout( $username, $ip );
    }
}

if ( ! function_exists( 'gr_record_login_failure' ) ) {
    /**
     * Login failure record facade (docs/03 §3): climbs the per-pair
     * counter and locks the address at the threshold with a gradient
     * duration.
     *
     * @param string $username Attempted username.
     * @param string $ip       Client address as text.
     * @return void
     */
    function gr_record_login_failure( string $username, string $ip ): void {
        Gr_Login_Protection::record_failure( $username, $ip );
    }
}

if ( ! function_exists( 'gr_render_honeypot' ) ) {
    /**
     * Honeypot render facade (docs/03 §3): the trap and carrier inputs
     * for one form context; empty when the module is off or the site
     * crypto cannot seal the carrier.
     *
     * @param string $form_context Form context, e.g. 'login'.
     * @return string
     */
    function gr_render_honeypot( string $form_context ): string {
        return Gr_Honeypot::render( $form_context );
    }
}

if ( ! function_exists( 'gr_check_honeypot' ) ) {
    /**
     * Honeypot judgement facade (docs/03 §3): true when the submitted
     * payload reads as automated — trap filled or round trip under
     * two seconds. Pure: no settings reads, no storage.
     *
     * @param array<int|string, mixed> $post Submitted fields.
     * @return bool
     */
    function gr_check_honeypot( array $post ): bool {
        return Gr_Honeypot::check( $post );
    }
}
