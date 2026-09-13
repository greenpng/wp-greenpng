<?php
/**
 * GA4 Measurement Protocol forwarder (docs/07 §5.2, docs/13 I3): the
 * paid order as a server-side purchase event — but only when the
 * visitor's own _ga cookie supplies a real client id, because a
 * fabricated id stitches phantom sessions into the property (the
 * reference archive's mistake). The capture-side gate stack matches
 * the Meta forwarder: marketing consent, the service configuration,
 * and the traffic-quality verdict, all evaluated inside the buyer's
 * request. The queue job owns the wire attempt and its retry ladder.
 *
 * GA4 takes no PII in any form — its payload vocabulary is client id
 * and event parameters only — so nothing here hashes or queues an
 * email at all.
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
 * Server-side purchase events to the GA4 Measurement Protocol.
 */
final class Gr_Ga4_Mp {

    /** Queue hook for one outbound event. */
    public const SEND_HOOK = 'gr_ga4_mp_send';

    /** GA4 event name for a paid order (GA4 vocabulary: snake_case). */
    public const EVENT_PURCHASE = 'purchase';

    /** GA4 event name for the owner-clicked connectivity probe. */
    public const EVENT_SELF_CHECK = 'gr_self_check';

    /**
     * Hook registration. The payment hook joins only when the target
     * runs on the site, keeping the adapter contract that an absent
     * target leaves no woocommerce_* hooks behind; the queue hook
     * must exist on every request path.
     *
     * @return void
     */
    public static function register(): void {
        if ( Gr_Woocommerce_Adapter::is_available() ) {
            // After the Meta forwarder (priority 11): both forwarders
            // read the visitor binding the adapter wrote at 10.
            add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ), 12, 1 );
        }

        add_action( self::SEND_HOOK, array( __CLASS__, 'send' ) );
    }

    /**
     * Capture-side producer. Every gate is evaluated here, in the
     * buyer's request; the queue job runs in some later request where
     * consent, session state, and the cookie no longer apply.
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

            if ( ! Gr_Consent::allows( 'marketing' ) ) {
                return;
            }

            if ( ! Gr_Http_Client::is_configured( Gr_Http_Client::SERVICE_GA4_MP ) ) {
                return;
            }

            // The traffic-quality gate: the same verdict, the same
            // reason — robots never reach the property's data.
            $visitor = (string) $order->get_meta( Gr_Woocommerce_Adapter::VISITOR_META );
            if ( true === ( new Gr_Session_Repository() )->visitor_bot_verdict( $visitor ) ) {
                return;
            }

            // The _ga gate: without the visitor's own client id there
            // is nothing to stitch the event to, and inventing one is
            // how garbage sessions get born.
            $client_id = self::client_id_from_cookie();
            if ( '' === $client_id ) {
                return;
            }

            $payload = self::build_purchase_payload(
                array(
                    'client_id' => $client_id,
                    'order_id'  => $id,
                    'value'     => (float) $order->get_total(),
                    'currency'  => (string) $order->get_currency(),
                )
            );

            Gr_Queue::enqueue( self::SEND_HOOK, array( $payload ) );
        } catch ( \Throwable $error ) {
            do_action( 'gr_adapter_error', 'ga4_mp', $error );
        }
    }

    /**
     * The client id from the visitor's own _ga cookie, or '' when the
     * cookie is absent or not the classic GA1.x shape. Structurally
     * parsed: the id portion must be digits.digits, so a mangled or
     * hostile cookie value can never ride into a payload.
     *
     * @return string
     */
    public static function client_id_from_cookie(): string {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed structurally below; only a digits.digits shape survives.
        $raw = isset( $_COOKIE['_ga'] ) ? (string) wp_unslash( $_COOKIE['_ga'] ) : '';
        if ( '' === $raw ) {
            return '';
        }

        $parts = explode( '.', $raw );
        if ( count( $parts ) < 4 ) {
            return '';
        }

        $client_id = $parts[2] . '.' . $parts[3];

        return 1 === preg_match( '/^\d+\.\d+$/', $client_id ) ? $client_id : '';
    }

    /**
     * Pure, local payload construction — no order objects, no cookie
     * reads, no I/O. GA4's vocabulary carries no PII at all: a client
     * id and event parameters. The transaction id is the order id, so
     * a replayed capture converges on one purchase in the property
     * (GA4 deduplicates purchase by transaction_id).
     *
     * @param array<string, mixed> $input client_id, order_id, value, currency.
     * @return array<string, mixed> One Measurement Protocol body.
     */
    public static function build_purchase_payload( array $input ): array {
        $currency = strtoupper( sanitize_key( (string) ( $input['currency'] ?? '' ) ) );

        $params = array(
            'transaction_id' => (string) (int) ( $input['order_id'] ?? 0 ),
        );
        if ( '' !== $currency ) {
            $params['currency'] = $currency;
        }
        $value = round( (float) ( $input['value'] ?? 0 ), 2 );
        if ( $value > 0 ) {
            $params['value'] = $value;
        }

        return array(
            'client_id' => (string) ( $input['client_id'] ?? '' ),
            'events'    => array(
                array(
                    'name'   => self::EVENT_PURCHASE,
                    'params' => $params,
                ),
            ),
        );
    }

    /**
     * The queue job: one wire attempt through the only sanctioned
     * door, and a reschedule exactly when the door says when. Queue
     * jobs fail silently, like every job the queue owns.
     *
     * @param array<string, mixed> $payload The event body queued at capture.
     * @return void
     */
    public static function send( array $payload ): void {
        // The shape guard doubles as the garbage-session defense on
        // the send side: no client id, no request, ever.
        if ( '' === (string) ( $payload['client_id'] ?? '' ) ) {
            return;
        }

        $measurement_id = Gr_Secrets::reveal( Gr_Secrets::GA4_ID_OPTION );
        $api_secret     = Gr_Secrets::reveal( Gr_Secrets::GA4_SECRET_OPTION );

        // The owner may have turned the service off between capture
        // and send: nothing leaves.
        if ( '' === $measurement_id || '' === $api_secret ) {
            return;
        }

        $url      = self::collect_url( $measurement_id, $api_secret, false );
        $response = Gr_Http_Client::post( Gr_Http_Client::SERVICE_GA4_MP, $url, $payload );

        if ( ! is_wp_error( $response ) ) {
            return;
        }

        $data        = $response->get_error_data();
        $retry_after = is_array( $data ) && isset( $data['retry_after'] )
            ? (int) $data['retry_after']
            : 0;

        if ( $retry_after > 0 ) {
            Gr_Queue::enqueue( self::SEND_HOOK, array( $payload ), $retry_after );
        }
    }

    /**
     * The owner-clicked connectivity probe: one validation request to
     * Google's debug endpoint, which answers with validation messages
     * and ingests nothing — a sentinel client id can therefore never
     * pollute a real session. Result words only, never values.
     *
     * @return array{result: string, messages?: int} ok, validation,
     *         not_configured, or unreachable.
     */
    public static function debug_check(): array {
        $measurement_id = Gr_Secrets::reveal( Gr_Secrets::GA4_ID_OPTION );
        $api_secret     = Gr_Secrets::reveal( Gr_Secrets::GA4_SECRET_OPTION );

        if ( '' === $measurement_id || '' === $api_secret ) {
            return array( 'result' => 'not_configured' );
        }

        $payload = array(
            'client_id' => '555.555',
            'events'    => array(
                array(
                    'name'   => self::EVENT_SELF_CHECK,
                    'params' => array( 'source' => 'plugin' ),
                ),
            ),
        );

        $url      = self::collect_url( $measurement_id, $api_secret, true );
        $response = Gr_Http_Client::post( Gr_Http_Client::SERVICE_GA4_MP, $url, $payload );

        if ( is_wp_error( $response ) ) {
            // Wire failure, breaker, backoff, or an HTTP error status:
            // the endpoint did not answer a readable validation.
            return array( 'result' => 'unreachable' );
        }

        $body     = json_decode( (string) $response['body'], true );
        $messages = is_array( $body ) && isset( $body['validationMessages'] ) && is_array( $body['validationMessages'] )
            ? $body['validationMessages']
            : null;

        if ( null === $messages ) {
            // A 2xx body without the validation shape is not a claim
            // of health this plugin is willing to make.
            return array( 'result' => 'unreachable' );
        }

        if ( array() === $messages ) {
            return array( 'result' => 'ok' );
        }

        return array(
            'result'   => 'validation',
            'messages' => count( $messages ),
        );
    }

    /**
     * The collect URL for one credential pair. GA4's protocol carries
     * both credentials as query parameters — that is the endpoint's
     * own contract, and the door never persists URLs.
     *
     * @param string $measurement_id G-… property id.
     * @param string $api_secret     Measurement Protocol secret.
     * @param bool   $debug          True for the validating debug endpoint.
     * @return string
     */
    private static function collect_url( string $measurement_id, string $api_secret, bool $debug ): string {
        $base = $debug
            ? 'https://www.google-analytics.com/debug/mp/collect'
            : 'https://www.google-analytics.com/mp/collect';

        return $base . '?measurement_id=' . rawurlencode( $measurement_id )
            . '&api_secret=' . rawurlencode( $api_secret );
    }
}
