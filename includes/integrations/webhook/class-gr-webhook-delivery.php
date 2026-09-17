<?php
/**
 * Webhook delivery job (ADR-0016 D2/D3): the queue body that owns
 * every outbound wire attempt. The body is the event DTO in JSON —
 * visitor and session travel in the plugin's own hashed forms, and
 * no PII key is ever added here (hash-only, the CAPI discipline).
 * The signature covers the exact body bytes with the endpoint's
 * secret; the four headers carry everything a receiver needs to
 * verify. Failures retry twice on the queue (60s, then 300s); an
 * exhausted delivery counts against the endpoint circuit, and the
 * streak is visible on the status page — nothing drops silently.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Integrations\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Http_Client;
use GreenPNG\Core\Gr_Queue;
use GreenPNG\Core\Gr_Secrets;

/**
 * One signed delivery attempt for one endpoint.
 */
final class Gr_Webhook_Delivery {

    /** Queue hook for one delivery attempt. */
    public const HOOK = 'gr_webhook_deliver';

    /** Retry delays in seconds, indexed by the attempt just failed (ADR-0016 D3: 60s then 300s, two retries). */
    private const RETRY_DELAYS = array( 60, 300 );

    /** Signature header, GitHub-style with the algorithm named. */
    public const HEADER_SIGNATURE = 'X-Gr-Signature';

    /** Event name header. */
    public const HEADER_EVENT = 'X-Gr-Event';

    /** Delivery id header, 32 hex, unique per attempt. */
    public const HEADER_DELIVERY = 'X-Gr-Delivery';

    /** Unix timestamp header, receivers enforce ±300s against replay. */
    public const HEADER_TIMESTAMP = 'X-Gr-Timestamp';

    /** Maximum attempts: the first delivery plus two retries. */
    public const MAX_ATTEMPT = 2;

    /**
     * The queue body: deliver, retry, or park the failure on the
     * endpoint's health record.
     *
     * @param array<string, mixed> $job Enqueued job: endpoint id, attempt, event snapshot.
     * @return void
     */
    public static function run( array $job ): void {
        $endpoint_id = (int) ( $job['endpoint'] ?? 0 );
        $attempt     = max( 0, (int) ( $job['attempt'] ?? 0 ) );
        $snapshot    = is_array( $job['event'] ?? null ) ? (array) $job['event'] : array();

        $endpoint = Gr_Webhook_Repository::find( $endpoint_id );
        if ( null === $endpoint || ! Gr_Webhook_Repository::deliverable( $endpoint ) ) {
            // Deleted, paused, or open-circuit endpoints take no
            // wire attempt; a paused circuit is the owner's explicit
            // state, not a silent drop — it is on the status page.
            return;
        }

        $secret = Gr_Webhook_Repository::secret_of( $endpoint );
        if ( '' === $secret ) {
            // The stored envelope no longer decrypts: refusing is
            // the honest outcome, recorded where the owner sees it.
            Gr_Webhook_Repository::record_delivery( $endpoint_id, false, 'secret_unreadable' );
            return;
        }

        // The kind picks the payload shape: generic keeps the signed
        // JSON contract, the four platform kinds send the card the
        // receiver renders. DingTalk's robot verification rides the
        // query string, the others ride the body.
        $kind = Gr_Webhook_Repository::kind_of( $endpoint );
        $url  = (string) $endpoint['url'];
        if ( Gr_Webhook_Repository::KIND_DINGTALK === $kind ) {
            $url = Gr_Webhook_Formatter::url( $url, $secret );
        }

        if ( Gr_Webhook_Repository::KIND_GENERIC === $kind ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the wire body must be byte-stable: the signature covers exactly these bytes, so the plain encoder is the contract, not a preference.
            $body = json_encode( $snapshot );
        } else {
            $body = Gr_Webhook_Formatter::body( $kind, $snapshot, $secret );
        }
        if ( false === $body || '' === $body ) {
            Gr_Webhook_Repository::record_delivery( $endpoint_id, false, 'body_unencodable' );
            return;
        }

        $result = Gr_Http_Client::post_raw(
            self::service( $endpoint_id ),
            $url,
            $body,
            self::headers( (string) ( $snapshot['name'] ?? '' ), $body, $secret )
        );

        if ( is_wp_error( $result ) ) {
            // Two ladders run side by side: this queue's retry ladder
            // and the client's per-service backoff and breaker. A
            // refusal from the client's timing layer (the queue
            // fired inside the client's wait window) never reached
            // the wire, so the delivery ladder must not burn one of
            // its two retries on it — the re-enqueue waits for the
            // window the client itself named.
            $code = (string) $result->get_error_code();
            if ( Gr_Http_Client::ERR_BACKOFF_WAIT === $code || Gr_Http_Client::ERR_BREAKER_OPEN === $code ) {
                $data           = $result->get_error_data();
                $wait           = is_array( $data ) ? max( 1, (int) ( $data['retry_after'] ?? 1 ) ) : 1;
                $job['attempt'] = $attempt;
                Gr_Queue::enqueue( self::HOOK, array( $job ), $wait );

                return;
            }

            self::retry_or_park( $endpoint_id, $attempt, $job, (string) $result->get_error_message() );
            return;
        }

        if ( ! Gr_Webhook_Formatter::accepted( $kind, $result ) ) {
            // Three of the platforms refuse inside an HTTP 200: the
            // verdict lives in the body, so a bare status code would
            // lie. The refusal rides the same queue ladder as a wire
            // miss, and the exhaustion word says what happened.
            self::retry_or_park( $endpoint_id, $attempt, $job, 'platform_refused' );
            return;
        }

        Gr_Webhook_Repository::record_delivery( $endpoint_id, true, (string) $result['code'] );
    }

    /**
     * The four signature headers for one body (ADR-0016 D2).
     *
     * @param string $event Event name.
     * @param string $body  Exact body bytes.
     * @param string $secret Signing secret.
     * @return array<string, string>
     */
    public static function headers( string $event, string $body, string $secret ): array {
        return array(
            self::HEADER_SIGNATURE => 'sha256=' . Gr_Secrets::sign_hmac( $body, $secret ),
            self::HEADER_EVENT     => $event,
            self::HEADER_DELIVERY  => Gr_Secrets::generate_event_id( '' ),
            self::HEADER_TIMESTAMP => (string) time(),
        );
    }

    /**
     * One failed attempt: the ladder re-enqueues while attempts
     * remain, and the exhaustion parks the failure on the endpoint
     * record — never a silent drop (ADR-0016 D3/D4).
     *
     * @param int                  $endpoint_id Endpoint id.
     * @param int                  $attempt     Attempt just failed.
     * @param array<string, mixed> $job The job for re-enqueue.
     * @param string               $reason      Wire-level reason word.
     * @return void
     */
    private static function retry_or_park( int $endpoint_id, int $attempt, array $job, string $reason ): void {
        if ( $attempt < self::MAX_ATTEMPT && array_key_exists( $attempt, self::RETRY_DELAYS ) ) {
            $job['attempt'] = $attempt + 1;
            Gr_Queue::enqueue( self::HOOK, array( $job ), self::RETRY_DELAYS[ $attempt ] );

            return;
        }

        Gr_Webhook_Repository::record_delivery( $endpoint_id, false, $reason );
    }

    /**
     * The client service key for one endpoint: the wire discipline
     * (5s timeout, breaker, backoff) applies per endpoint, not
     * shared, so one dead receiver cannot cool down another.
     *
     * @param int $endpoint_id Endpoint id.
     * @return string
     */
    public static function service( int $endpoint_id ): string {
        return 'webhook_' . $endpoint_id;
    }
}
