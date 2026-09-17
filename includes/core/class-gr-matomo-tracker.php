<?php
/**
 * Matomo tracking forwarder (docs/07 §1, docs/19 V19): the paid order
 * and the form lead as tracking events on the owner's own Matomo
 * instance. The capture-side gate stack matches the GA4 forwarder —
 * marketing consent, the service configuration, and the
 * traffic-quality verdict, all evaluated inside the visitor's
 * request — and the queue job owns the wire attempt and its retry
 * ladder. Nothing about the instance is assumed: the owner types the
 * endpoint and the token, and the tracking request is exactly the
 * Tracking HTTP API's own vocabulary.
 *
 * Matomo takes no PII in any form: the visitor id rides out only as
 * a 16-hex derived hash, the order as its number, the lead as the
 * contact's numeric id — the email itself never leaves the contact
 * row. The instance is the owner's own server, so the privacy story
 * is theirs alone, and the plugin keeps it that way.
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
 * Server-side tracking events to a self-hosted Matomo instance.
 */
final class Gr_Matomo_Tracker {

    /** Queue hook for one outbound tracking event. */
    public const SEND_HOOK = 'gr_matomo_send';

    /** Event category every track rides under (e_c). */
    public const CATEGORY = 'GreenPNG';

    /** Tracking action for a paid order (e_a). */
    public const EVENT_PURCHASE = 'purchase';

    /** Tracking action for a captured lead (e_a). */
    public const EVENT_LEAD = 'lead';

    /**
     * Hook registration. The payment hook joins only when the target
     * runs on the site, keeping the adapter contract; the event bus
     * subscription mirrors the webhook fan-out: the snapshot is the
     * whole delivery, and nothing may be looked up from the live
     * request again once the queue owns it.
     *
     * @return void
     */
    public static function register(): void {
        if ( Gr_Woocommerce_Adapter::is_available() ) {
            // After the GA4 forwarder (priority 12): every forwarder
            // reads the visitor binding the adapter wrote at 10.
            add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ), 13, 1 );
        }

        add_action( 'gr_event', array( __CLASS__, 'on_event' ), 10, 1 );
        add_action( self::SEND_HOOK, array( __CLASS__, 'send' ), 10, 1 );
    }

    /**
     * Capture-side producer for a paid order. Every gate is evaluated
     * here, in the buyer's request; the queue job runs in some later
     * request where consent, session state, and the order are gone.
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

            if ( ! Gr_Http_Client::is_configured( Gr_Http_Client::SERVICE_MATOMO ) ) {
                return;
            }

            $visitor = (string) $order->get_meta( Gr_Woocommerce_Adapter::VISITOR_META );
            if ( true === ( new Gr_Session_Repository() )->visitor_bot_verdict( $visitor ) ) {
                return;
            }

            Gr_Queue::enqueue(
                self::SEND_HOOK,
                array(
                    self::build_purchase_track(
                        array(
                            'visitor_id' => $visitor,
                            'order_id'   => $id,
                            'value'      => (float) $order->get_total(),
                            'currency'   => (string) $order->get_currency(),
                        )
                    ),
                )
            );
        } catch ( \Throwable $error ) {
            do_action( 'gr_adapter_error', 'matomo', $error );
        }
    }

    /**
     * Capture-side producer for a lead event off the bus. The
     * producer already gates on marketing consent, but this is a
     * marketing track making its own decision: the gate is re-read
     * here, in the same request where it still applies.
     *
     * @param \GreenPNG\Core\Gr_Event $event Bus event value object.
     * @return void
     */
    public static function on_event( \GreenPNG\Core\Gr_Event $event ): void {
        try {
            if ( self::EVENT_LEAD !== $event->name() ) {
                return;
            }

            if ( ! Gr_Consent::allows( 'marketing' ) ) {
                return;
            }

            if ( ! Gr_Http_Client::is_configured( Gr_Http_Client::SERVICE_MATOMO ) ) {
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
                array( self::build_lead_track( $visitor, $contact_id ) )
            );
        } catch ( \Throwable $error ) {
            do_action( 'gr_adapter_error', 'matomo', $error );
        }
    }

    /**
     * Pure track construction for a paid order. The e-commerce
     * vocabulary carries the order as its number twice — e_n names
     * it, and idgoal 0 with the same ec_id makes the order
     * idempotent on the instance (Matomo's server-side dedup).
     *
     * @param array<string, mixed> $input visitor_id, order_id, value, currency.
     * @return array<string, string|float> One queued track snapshot.
     */
    public static function build_purchase_track( array $input ): array {
        $order_id = (int) ( $input['order_id'] ?? 0 );
        $name     = 0 === $order_id ? '' : 'order-' . $order_id;

        $track = array(
            'event' => self::EVENT_PURCHASE,
            '_id'   => self::visitor_hash( (string) ( $input['visitor_id'] ?? '' ) ),
            'name'  => $name,
        );

        if ( '' === $name ) {
            return $track;
        }

        $track['idgoal'] = '0';
        $track['ec_id']  = $name;

        $value = round( (float) ( $input['value'] ?? 0 ), 2 );
        if ( $value > 0 ) {
            $track['revenue'] = $value;
        }

        $currency = strtoupper( sanitize_key( (string) ( $input['currency'] ?? '' ) ) );
        if ( '' !== $currency ) {
            $track['currency'] = $currency;
        }

        return $track;
    }

    /**
     * Pure track construction for a captured lead: the contact's
     * numeric id names it, never the email.
     *
     * @param string $visitor_id Visitor binding.
     * @param int    $contact_id Contact row id.
     * @return array<string, string> One queued track snapshot.
     */
    public static function build_lead_track( string $visitor_id, int $contact_id ): array {
        return array(
            'event' => self::EVENT_LEAD,
            '_id'   => self::visitor_hash( $visitor_id ),
            'name'  => 0 === $contact_id ? '' : 'contact-' . $contact_id,
        );
    }

    /**
     * The 16-hex Matomo visitor id: a double hash of the plugin's own
     * visitor binding. The binding is already a salted hash, but it
     * is also cookie-borne input, so deriving a fresh digest means
     * only an opaque id that cannot be walked back rides out.
     *
     * @param string $visitor_id Visitor binding.
     * @return string 16 lowercase hex chars.
     */
    public static function visitor_hash( string $visitor_id ): string {
        return substr( md5( 'matomo|' . $visitor_id ), 0, 16 );
    }

    /**
     * The queue job: one wire attempt through the only sanctioned
     * door, and a reschedule exactly when the door says when. The
     * credentials are read here, never queued: an owner disarming
     * the service between capture and send stops the track cold.
     * A 2xx body that is not the GIF is not a claim of ingestion —
     * the endpoint's own success shape is the verdict.
     *
     * @param array<string, string|float> $track Queued track snapshot.
     * @return void
     */
    public static function send( array $track ): void {
        $event = sanitize_key( (string) ( $track['event'] ?? '' ) );
        if ( self::EVENT_PURCHASE !== $event && self::EVENT_LEAD !== $event ) {
            return;
        }

        $instance = Gr_Secrets::reveal( Gr_Secrets::MATOMO_URL_OPTION );
        $token    = Gr_Secrets::reveal( Gr_Secrets::MATOMO_TOKEN_OPTION );
        $site_id  = (int) ( new Gr_Settings() )->get( 'matomo_site_id', 0 );

        // The owner may have disarmed the service between capture and
        // send: nothing leaves.
        if ( '' === $instance || '' === $token || 0 >= $site_id ) {
            return;
        }

        $name = (string) ( $track['name'] ?? '' );
        if ( '' === $name ) {
            return;
        }

        $params = array(
            'idsite'     => (string) $site_id,
            'rec'        => '1',
            'token_auth' => $token,
            '_id'        => (string) ( $track['_id'] ?? '' ),
            'e_c'        => self::CATEGORY,
            'e_a'        => $event,
            'e_n'        => $name,
        );

        if ( self::EVENT_PURCHASE === $event ) {
            $params['idgoal'] = (string) ( $track['idgoal'] ?? '0' );
            $params['ec_id']  = (string) ( $track['ec_id'] ?? '' );

            if ( isset( $track['revenue'] ) && (float) $track['revenue'] > 0 ) {
                $params['revenue'] = (string) (float) $track['revenue'];
            }
            if ( isset( $track['currency'] ) && '' !== (string) $track['currency'] ) {
                $params['currency'] = (string) $track['currency'];
            }
        }

        $response = Gr_Http_Client::post_raw(
            Gr_Http_Client::SERVICE_MATOMO,
            self::endpoint_url( $instance ),
            http_build_query( $params ),
            array( 'Content-Type' => 'application/x-www-form-urlencoded' )
        );

        if ( is_wp_error( $response ) ) {
            $data        = $response->get_error_data();
            $retry_after = is_array( $data ) && isset( $data['retry_after'] )
                ? (int) $data['retry_after']
                : 0;

            if ( $retry_after > 0 ) {
                Gr_Queue::enqueue( self::SEND_HOOK, array( $track ), $retry_after );
            }

            return;
        }

        // The GIF is Matomo's success shape; a 2xx that is not the
        // GIF is no verdict at all, and the track dies without a
        // reschedule — the ladder is the client's bookkeeping.
        if ( 0 !== strpos( (string) $response['body'], 'GIF8' ) ) {
            return;
        }
    }

    /**
     * The matomo.php endpoint of one instance: the stored URL wins
     * as-is when it already names the endpoint, otherwise the
     * canonical path is appended, so both "https://host" and
     * "https://host/matomo.php" mean the same thing.
     *
     * @param string $instance Owner-stored instance URL.
     * @return string
     */
    public static function endpoint_url( string $instance ): string {
        $instance = rtrim( trim( $instance ), '/' );

        if ( 1 !== preg_match( '#/matomo\.php$#i', $instance ) ) {
            return $instance . '/matomo.php';
        }

        return (string) preg_replace( '#/matomo\.php$#i', '/matomo.php', $instance );
    }
}
