<?php
/**
 * TikTok Events API forwarder (docs/07 §1 messaging row, mock/08,
 * docs/19 V29): the paid order and the captured lead, and only those
 * two events, offered to TikTok — after the marketing-consent gate,
 * after the traffic-quality verdict, and only when the owner
 * configured the pixel code and access token. The event id shares
 * the Meta track's single derivation point, so the thank-you page's
 * existing browser-pixel global collapses both server events with
 * their browser twins in TikTok's deduplication window. The queue
 * job owns the wire attempt; a 200 that carries a non-zero TikTok
 * code is a refusal, not a success.
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
 * Server-side conversion events to the TikTok Events API.
 */
final class Gr_Tiktok_Capi {

    /** Queue hook for one outbound event. */
    public const SEND_HOOK = 'gr_tiktok_capi_send';

    /** TikTok event name for a paid order. */
    public const EVENT_PURCHASE = 'CompletePayment';

    /** TikTok event name for a captured lead. */
    public const EVENT_LEAD = 'SubmitForm';

    /**
     * Hook registration. The payment hook joins only when the target
     * runs on the site, keeping the adapter contract that an absent
     * target leaves no woocommerce_* hooks behind; the bus lead
     * subscription and the queue hook exist on every request path.
     *
     * @return void
     */
    public static function register(): void {
        if ( Gr_Woocommerce_Adapter::is_available() ) {
            // After the Matomo forwarder (priority 13): every
            // forwarder reads the visitor binding the adapter wrote
            // at 10.
            add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ), 14, 1 );
        }

        add_action( 'gr_event', array( __CLASS__, 'on_event' ), 10, 1 );
        add_action( self::SEND_HOOK, array( __CLASS__, 'send' ), 10, 1 );
    }

    /**
     * The server-side event id for one lead: deterministic in the
     * contact row, so a replayed capture converges on one TikTok
     * event instead of many.
     *
     * @param int $contact_id Contact row id.
     * @return string Deterministic event id.
     */
    public static function lead_event_id( int $contact_id ): string {
        return gr_generate_event_id( 'capi', 'lead|' . $contact_id );
    }

    /**
     * Capture-side producer for the paid order. Every gate is
     * evaluated here, in the buyer's request, because the queue job
     * runs in some later request where consent and session state no
     * longer apply.
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
            if ( ! Gr_Http_Client::is_configured( Gr_Http_Client::SERVICE_TIKTOK ) ) {
                return;
            }

            // The traffic-quality gate: a visitor whose latest
            // session is a known bot never reaches the ad algorithm.
            $visitor = (string) $order->get_meta( Gr_Woocommerce_Adapter::VISITOR_META );
            if ( true === ( new Gr_Session_Repository() )->visitor_bot_verdict( $visitor ) ) {
                return;
            }

            $payload = self::build_purchase_payload(
                array(
                    // The single derivation point the Meta track
                    // already owns: one id, every server event, the
                    // browser twin on the thank-you page.
                    'event_id'   => Gr_Meta_Capi::order_event_id( $id ),
                    'email'      => (string) $order->get_billing_email(),
                    'visitor_id' => $visitor,
                    'value'      => (float) $order->get_total(),
                    'currency'   => (string) $order->get_currency(),
                    'event_time' => time(),
                )
            );

            // A single positional argument: the queue delivers each
            // args element as one callback parameter.
            Gr_Queue::enqueue( self::SEND_HOOK, array( $payload ) );
        } catch ( \Throwable $error ) {
            // Checkout must never break because a third-party send
            // failed to queue; the error channel stays observable.
            do_action( 'gr_adapter_error', 'tiktok_capi', $error );
        }
    }

    /**
     * Capture-side producer for a lead event off the bus. The bus
     * producer already gates on marketing consent, but this is a
     * marketing send making its own decision: the gate is re-read
     * here, in the same request where it still applies.
     *
     * @param \GreenPNG\Core\Gr_Event $event Bus event value object.
     * @return void
     */
    public static function on_event( \GreenPNG\Core\Gr_Event $event ): void {
        try {
            if ( 'lead' !== $event->name() ) {
                return;
            }

            if ( ! Gr_Consent::allows( 'marketing' ) ) {
                return;
            }

            if ( ! Gr_Http_Client::is_configured( Gr_Http_Client::SERVICE_TIKTOK ) ) {
                return;
            }

            $visitor = $event->visitor_id();
            if ( '' === $visitor || true === ( new Gr_Session_Repository() )->visitor_bot_verdict( $visitor ) ) {
                return;
            }

            $contact_id = (int) ( $event->payload()['contact_id'] ?? 0 );
            if ( 0 === $contact_id ) {
                return;
            }

            Gr_Queue::enqueue(
                self::SEND_HOOK,
                array(
                    self::build_lead_payload(
                        array(
                            'event_id'   => self::lead_event_id( $contact_id ),
                            'visitor_id' => $visitor,
                            'event_time' => time(),
                        )
                    ),
                )
            );
        } catch ( \Throwable $error ) {
            do_action( 'gr_adapter_error', 'tiktok_capi', $error );
        }
    }

    /**
     * Pure, local payload construction — no order objects, no
     * consent reads, no I/O, so the exact wire shape is
     * unit-testable. PII leaves this method only as sha-256
     * digests, and the visitor identifier rides as the plugin's
     * own hashed visitor id.
     *
     * @param array<string, mixed> $input event_id, email, visitor_id,
     *        value, currency, event_time.
     * @return array<string, mixed> One TikTok event object.
     */
    public static function build_purchase_payload( array $input ): array {
        return self::build_event(
            self::EVENT_PURCHASE,
            array(
                'event_id'   => (string) ( $input['event_id'] ?? '' ),
                'email'      => (string) ( $input['email'] ?? '' ),
                'visitor_id' => (string) ( $input['visitor_id'] ?? '' ),
                'value'      => (float) ( $input['value'] ?? 0 ),
                'currency'   => (string) ( $input['currency'] ?? '' ),
                'event_time' => (int) ( $input['event_time'] ?? time() ),
            )
        );
    }

    /**
     * Pure, local payload construction for the captured lead: the
     * contact's numeric id names it, never the email.
     *
     * @param array<string, mixed> $input event_id, visitor_id, event_time.
     * @return array<string, mixed> One TikTok event object.
     */
    public static function build_lead_payload( array $input ): array {
        return self::build_event(
            self::EVENT_LEAD,
            array(
                'event_id'   => (string) ( $input['event_id'] ?? '' ),
                'email'      => '',
                'visitor_id' => (string) ( $input['visitor_id'] ?? '' ),
                'value'      => 0.0,
                'currency'   => '',
                'event_time' => (int) ( $input['event_time'] ?? time() ),
            )
        );
    }

    /**
     * The queue job: one wire attempt through the only sanctioned
     * door, and a reschedule exactly when the door says when. The
     * success verdict is double-checked — TikTok answers errors as
     * HTTP 200 with a non-zero code, so the status code alone would
     * lie about success; a 200 refusal is deterministic and gets no
     * retry.
     *
     * @param array<string, mixed> $payload The event object queued at capture.
     * @return void
     */
    public static function send( array $payload ): void {
        // A shape the builders never produce (corrupt or replayed
        // job) is dropped, not thrown at the network.
        if ( '' === (string) ( $payload['event'] ?? '' ) ) {
            return;
        }

        $pixel = Gr_Secrets::reveal( Gr_Secrets::TIKTOK_PIXEL_OPTION );
        $token = Gr_Secrets::reveal( Gr_Secrets::TIKTOK_TOKEN_OPTION );

        // The owner may have turned the service off between capture
        // and send: the triple is re-read here, and nothing leaves.
        if ( '' === $pixel || '' === $token ) {
            return;
        }
        if ( ! Gr_Http_Client::is_configured( Gr_Http_Client::SERVICE_TIKTOK ) ) {
            return;
        }

        $version = (string) apply_filters( 'gr_tiktok_api_version', GR_TIKTOK_API_VERSION );
        if ( '' === $version ) {
            return;
        }

        $url = 'https://business-api.tiktok.com/open_api/' . rawurlencode( $version ) . '/event/track/';

        // The whole-URL seam, the same re-point surface the data
        // refreshes use: the version filter shapes the official
        // endpoint, this filter lets a probe or a future proxy own
        // the destination outright.
        $url = (string) apply_filters( 'gr_tiktok_track_url', $url );

        $response = Gr_Http_Client::post_raw(
            Gr_Http_Client::SERVICE_TIKTOK,
            $url,
            (string) wp_json_encode(
                array(
                    'event_source'    => 'web',
                    'event_source_id' => $pixel,
                    'data'            => array( $payload ),
                )
            ),
            array( 'Access-Token' => $token )
        );

        if ( is_wp_error( $response ) ) {
            $data        = $response->get_error_data();
            $retry_after = is_array( $data ) && isset( $data['retry_after'] )
                ? (int) $data['retry_after']
                : 0;

            if ( $retry_after > 0 ) {
                Gr_Queue::enqueue( self::SEND_HOOK, array( $payload ), $retry_after );
            }

            // retry_after of 0 is the not-configured, breaker, and
            // give-up family: the caller stops, per the door's
            // contract.
            return;
        }

        // The second half of the double check: HTTP 200 is not
        // success until the body's own code is zero.
        $body = json_decode( (string) $response['body'], true );
        if ( ! is_array( $body ) || 0 !== (int) ( $body['code'] ?? -1 ) ) {
            return;
        }
    }

    /**
     * One TikTok event object: identifiers only in their wrapped
     * or hashed forms, properties only as rounded value + currency.
     *
     * @param string               $event TikTok event name.
     * @param array<string, mixed> $input event_id, email, visitor_id,
     *        value, currency, event_time.
     * @return array<string, mixed>
     */
    private static function build_event( string $event, array $input ): array {
        $payload = array(
            'event'      => $event,
            'event_time' => (int) $input['event_time'],
            'event_id'   => (string) $input['event_id'],
        );

        $user = array();

        $email_digest = Gr_Secrets::hash_pii_sha256( (string) $input['email'] );
        if ( '' !== $email_digest ) {
            // TikTok's wrapped shape: the digest travels inside a
            // {"sha256": …} object, never as a bare string.
            $user['email'] = array( 'sha256' => $email_digest );
        }

        $visitor = (string) $input['visitor_id'];
        if ( '' !== $visitor ) {
            // The plugin's own visitor identifier is already a hash
            // form, the same external_id the other tracks carry.
            $user['external_id'] = $visitor;
        }

        if ( array() !== $user ) {
            $payload['user'] = $user;
        }

        $properties = array();
        $value      = round( (float) $input['value'], 2 );
        if ( $value > 0 ) {
            $properties['value'] = $value;
        }

        $currency = strtoupper( sanitize_key( (string) $input['currency'] ) );
        if ( '' !== $currency ) {
            $properties['currency'] = $currency;
        }

        if ( array() !== $properties ) {
            $payload['properties'] = $properties;
        }

        return $payload;
    }
}
