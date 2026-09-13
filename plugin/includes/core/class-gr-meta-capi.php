<?php
/**
 * Meta Conversions API forwarder (docs/07 §5.1, docs/13 I2): the
 * paid-order event, and only that event, offered to Meta — after the
 * marketing-consent gate, after the traffic-quality verdict (never
 * feed robots to an ad platform's algorithm), and only when the owner
 * configured the pixel and token. The producer runs inside the
 * payment-complete request and must never be able to break checkout:
 * every gate is local, and the only thing it queues is a payload that
 * already holds hashes instead of PII. The queue job owns the wire
 * attempt and its retry ladder.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Integrations\Ecosystem\Gr_Woocommerce_Adapter;
use GreenPNG\Privacy\Gr_Consent;
use GreenPNG\Storage\Gr_Session_Repository;

/**
 * Server-side Purchase events to the Meta Conversions API.
 */
final class Gr_Meta_Capi {

    /** Queue hook for one outbound event. */
    public const SEND_HOOK = 'gr_meta_capi_send';

    /** Meta standard event name for a paid order. */
    public const EVENT_PURCHASE = 'Purchase';

    /**
     * Hook registration. The payment hook joins only when the target
     * actually runs on the site, keeping the adapter contract that an
     * absent target leaves no woocommerce_* hooks behind; the queue
     * hook must exist on every request path because the enqueueing
     * request is long gone by the time the job runs.
     *
     * @return void
     */
    public static function register(): void {
        if ( Gr_Woocommerce_Adapter::is_available() ) {
            // After the adapter's visitor binding (priority 10): the
            // quality verdict reads the visitor id that binding wrote.
            add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ), 11, 1 );
        }

        add_action( self::SEND_HOOK, array( __CLASS__, 'send' ) );
    }

    /**
     * Capture-side producer. Every gate is evaluated here, in the
     * buyer's request, because the queue job runs in some later
     * request where consent and session state no longer apply.
     *
     * @param int|string $order_id Paid order id.
     * @return void
     */
    public static function on_payment_complete( $order_id ): void {
        try {
            $id = is_numeric( $order_id ) ? (int) $order_id : 0;
            if ( 0 === $id ) {
                return;
            }

            $order = wc_get_order( $id );
            if ( ! $order instanceof \WC_Order ) {
                return;
            }

            // The marketing gate: no consent, no third-party send —
            // checked now, because the job cannot check it later.
            if ( ! Gr_Consent::allows( 'marketing' ) ) {
                return;
            }

            // An off or half-configured service manufactures no work.
            if ( ! Gr_Http_Client::is_configured( Gr_Http_Client::SERVICE_META_CAPI ) ) {
                return;
            }

            // The traffic-quality gate: the product's differentiation
            // is exactly here — a visitor whose latest session is a
            // known bot never reaches the ad algorithm. A visitor with
            // no sessions has no evidence against them.
            $visitor = (string) $order->get_meta( Gr_Woocommerce_Adapter::VISITOR_META );
            if ( true === ( new Gr_Session_Repository() )->visitor_bot_verdict( $visitor ) ) {
                return;
            }

            $payload = self::build_purchase_payload(
                array(
                    'event_id'   => gr_generate_event_id( 'capi', 'order|' . $id ),
                    'email'      => (string) $order->get_billing_email(),
                    'phone'      => '',
                    'value'      => (float) $order->get_total(),
                    'currency'   => (string) $order->get_currency(),
                    'source_url' => '',
                    'event_time' => time(),
                )
            );

            // A single positional argument: the queue delivers each
            // args element as one callback parameter.
            Gr_Queue::enqueue( self::SEND_HOOK, array( $payload ) );
        } catch ( \Throwable $error ) {
            // Checkout must never break because a third-party send
            // failed to queue; the error channel stays observable.
            do_action( 'gr_adapter_error', 'meta_capi', $error );
        }
    }

    /**
     * Pure, local payload construction — no order objects, no consent
     * reads, no I/O, so the exact wire shape is unit-testable. PII
     * leaves this method only as sha-256 digests; a replayed capture
     * converges on the same event_id, which is the deduplication
     * story the browser Pixel and this server event share.
     *
     * @param array<string, mixed> $input event_id, email, phone, value,
     *        currency, source_url, event_time.
     * @return array<string, mixed> One Meta event object.
     */
    public static function build_purchase_payload( array $input ): array {
        $payload = array(
            'event_name'    => self::EVENT_PURCHASE,
            'event_time'    => (int) ( $input['event_time'] ?? time() ),
            'event_id'      => (string) ( $input['event_id'] ?? '' ),
            'action_source' => 'website',
        );

        $email_digest = Gr_Secrets::hash_pii_sha256( (string) ( $input['email'] ?? '' ) );
        $phone_digest = Gr_Secrets::hash_pii_sha256( (string) ( $input['phone'] ?? '' ) );

        $user_data = array();
        if ( '' !== $email_digest ) {
            $user_data['em'] = $email_digest;
        }
        if ( '' !== $phone_digest ) {
            $user_data['ph'] = $phone_digest;
        }
        if ( array() !== $user_data ) {
            $payload['user_data'] = $user_data;
        }

        $value    = round( (float) ( $input['value'] ?? 0 ), 2 );
        $currency = strtoupper( sanitize_key( (string) ( $input['currency'] ?? '' ) ) );

        $custom_data = array();
        if ( $value > 0 ) {
            $custom_data['value'] = number_format( $value, 2, '.', '' );
        }
        if ( '' !== $currency ) {
            $custom_data['currency'] = $currency;
        }
        if ( array() !== $custom_data ) {
            $payload['custom_data'] = $custom_data;
        }

        $source_url = esc_url_raw( (string) ( $input['source_url'] ?? '' ) );
        if ( '' !== $source_url ) {
            $payload['event_source_url'] = $source_url;
        }

        return $payload;
    }

    /**
     * The queue job: one wire attempt through the only sanctioned
     * door, and a reschedule exactly when the door says when. Queue
     * jobs fail silently — the client has already audited what it
     * audits, and an audit row per abandoned marketing event would
     * drown the audit log's security purpose.
     *
     * @param array<string, mixed> $payload The event object queued at capture.
     * @return void
     */
    public static function send( array $payload ): void {
        // A shape the builder never produces (corrupt or replayed job)
        // is dropped, not thrown at the network.
        if ( '' === (string) ( $payload['event_name'] ?? '' ) ) {
            return;
        }

        $pixel = Gr_Secrets::reveal( Gr_Secrets::META_PIXEL_OPTION );
        $token = Gr_Secrets::reveal( Gr_Secrets::META_TOKEN_OPTION );

        // The owner may have turned the service off between capture
        // and send: the request path re-checks, and nothing leaves.
        if ( '' === $pixel || '' === $token ) {
            return;
        }

        $version = (string) apply_filters( 'gr_meta_api_version', GR_META_API_VERSION );
        if ( '' === $version ) {
            return;
        }

        $url = 'https://graph.facebook.com/' . rawurlencode( $version )
            . '/' . rawurlencode( $pixel ) . '/events';

        $response = Gr_Http_Client::post(
            Gr_Http_Client::SERVICE_META_CAPI,
            $url,
            array(
                'data'         => array( $payload ),
                'access_token' => $token,
            )
        );

        if ( ! is_wp_error( $response ) ) {
            // Success: the client cleared its ledger; no per-event
            // audit noise.
            return;
        }

        $data        = $response->get_error_data();
        $retry_after = is_array( $data ) && isset( $data['retry_after'] )
            ? (int) $data['retry_after']
            : 0;

        if ( $retry_after > 0 ) {
            Gr_Queue::enqueue( self::SEND_HOOK, array( $payload ), $retry_after );
        }

        // retry_after of 0 is the not-configured, breaker, and give-up
        // family: the caller stops, per the door's contract.
    }
}
