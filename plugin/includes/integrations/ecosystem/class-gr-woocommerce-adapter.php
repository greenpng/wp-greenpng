<?php
/**
 * WooCommerce adapter (docs/13 C10): captures attribution state at
 * checkout through both order-creation paths — classic checkout and
 * the Store API (Blocks) — and binds the conversion when payment
 * completes or an order settles into a paid status (offline gateways,
 * ADR-0009 D4). Every callback is Throwable-isolated: a failure inside
 * the adapter must never break checkout or payment (docs/02 §2.7
 * fail-open).
 *
 * HPOS-safe by construction: order access goes through wc_get_order()
 * plus the CRUD meta API (update_meta_data/save), never post meta
 * helpers, so the adapter works identically with and without HPOS.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Integrations\Ecosystem;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Attribution\Gr_Attribution_Service;
use GreenPNG\Attribution\Gr_Identity;
use GreenPNG\Integrations\Adapter_Interface;
use GreenPNG\Privacy\Gr_Consent;
use WC_Order;

/**
 * Three-hook WooCommerce integration.
 */
final class Gr_Woocommerce_Adapter implements Adapter_Interface {

    /** Order meta: the cookie-track visitor id captured at checkout. */
    public const VISITOR_META = '_gr_visitor_id';

    /** Order meta: the attribution idempotency lock. */
    public const ATTRIBUTED_META = '_gr_attributed';

    /**
     * Order meta: the visitor's marketing-consent choice snapshotted
     * at checkout, so a payment completing out-of-band (a gateway
     * webhook, the owner's CLI) binds under the consent the visitor
     * actually gave, not whatever the completing request carries.
     */
    public const CONSENT_META = '_gr_marketing_consent';

    /**
     * Identity service (dual-track).
     *
     * @var Gr_Identity
     */
    private Gr_Identity $identity;

    /**
     * Attribution binding service.
     *
     * @var Gr_Attribution_Service
     */
    private Gr_Attribution_Service $attribution;

    /**
     * Wires the collaborators.
     *
     * @param Gr_Identity            $identity    Identity service.
     * @param Gr_Attribution_Service $attribution Binding service.
     */
    public function __construct( Gr_Identity $identity, Gr_Attribution_Service $attribution ) {
        $this->identity    = $identity;
        $this->attribution = $attribution;
    }

    /**
     * Adapter identifier.
     *
     * @return string
     */
    public static function get_id(): string {
        return 'woocommerce';
    }

    /**
     * The target's own bootstrap class is the cheap, public probe —
     * no version lock, no signature matching (iron rule 6).
     *
     * @return bool
     */
    public static function is_available(): bool {
        return class_exists( 'WooCommerce', false );
    }

    /**
     * Registers the mounts. The Store API hook coexists with the
     * classic one; both write-once the same meta, so whichever fires
     * first wins and the other no-ops. The two status transitions
     * close the offline hole (ADR-0009 D4): Store API orders on
     * offline gateways are born directly in a paid status, so
     * payment_complete never fires for them — the status hooks are
     * the path that does. The lock and the conversions UNIQUE key
     * collapse the double fire on orders that hit both.
     *
     * @return void
     */
    public function register_hooks(): void {
        if ( ! self::is_available() ) {
            return;
        }

        add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'capture_classic' ), 10, 2 );
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'capture_store_api' ), 10, 2 );
        add_action( 'woocommerce_payment_complete', array( $this, 'complete_payment' ), 10, 1 );
        add_action( 'woocommerce_order_status_processing', array( $this, 'complete_payment' ), 10, 1 );
        add_action( 'woocommerce_order_status_completed', array( $this, 'complete_payment' ), 10, 1 );
    }

    /**
     * Classic checkout order creation.
     *
     * @param int|string               $order_id Order id.
     * @param array<int|string, mixed> $posted   Posted checkout data (unused).
     * @return void
     */
    public function capture_classic( $order_id, $posted = array() ): void {
        $id = is_numeric( $order_id ) ? (int) $order_id : 0;

        $this->guarded(
            function () use ( $id ): void {
                $this->capture_visitor( $id );
            }
        );
    }

    /**
     * Store API (Blocks) checkout order creation.
     *
     * @param mixed $order   Order object or id.
     * @param mixed $request Store API request (unused).
     * @return void
     */
    public function capture_store_api( $order, $request = null ): void {
        $id = $this->order_id( $order );

        $this->guarded(
            function () use ( $id ): void {
                $this->capture_visitor( $id );
            }
        );
    }

    /**
     * Payment completion: the attribution binding moment.
     *
     * @param int|string $order_id Order id.
     * @return void
     */
    public function complete_payment( $order_id ): void {
        $id = is_numeric( $order_id ) ? (int) $order_id : 0;

        $this->guarded(
            function () use ( $id ): void {
                $this->bind_order( $id );
            }
        );
    }

    /**
     * Persists the visitor identity onto the order, once. Only the
     * cookie track is worth storing: the daily fallback id cannot
     * correlate across days, so persisting it would write marketing
     * data with no attribution value (ADR-0005).
     *
     * @param int $order_id Order id.
     * @return void
     */
    private function capture_visitor( int $order_id ): void {
        if ( ! $this->identity->has_cookie_identity() ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        if ( '' !== (string) $order->get_meta( self::VISITOR_META ) ) {
            return;
        }

        $order->update_meta_data( self::VISITOR_META, $this->identity->visitor_id() );
        // The consent choice rides with the visitor: payment often
        // completes out-of-band, where the completing request carries
        // no consent state at all.
        $order->update_meta_data( self::CONSENT_META, Gr_Consent::allows( 'marketing' ) ? '1' : '0' );
        $order->save();
    }

    /**
     * Binds the paid order to its captured visitor. The meta lock and
     * the gr_conversions UNIQUE key are the two idempotency lines
     * (docs/05 §3.3): whichever trips first, a replayed
     * payment-complete callback can never produce a second record.
     *
     * @param int $order_id Order id.
     * @return void
     */
    private function bind_order( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        if ( '1' === (string) $order->get_meta( self::ATTRIBUTED_META ) ) {
            return;
        }

        $visitor = (string) $order->get_meta( self::VISITOR_META );
        if ( '' === $visitor && $this->identity->has_cookie_identity() ) {
            // Orders created outside our checkout capture still get
            // bound when the paying request carries the cookie.
            $visitor = $this->identity->visitor_id();
        }

        // The captured snapshot covers out-of-band completion; the
        // current request's consent covers everything else. Either
        // one green-lights the binding, neither forces it.
        $captured = '1' === (string) $order->get_meta( self::CONSENT_META );
        if ( '' === $visitor || ! ( $captured || Gr_Consent::allows( 'marketing' ) ) ) {
            return;
        }

        $bound = $this->attribution->bind(
            $order_id,
            $visitor,
            (float) $order->get_total(),
            (string) $order->get_currency()
        );

        if ( $bound > 0 ) {
            $order->update_meta_data( self::ATTRIBUTED_META, '1' );
            $order->save();
        }
    }

    /**
     * Runs one unit of adapter work; any Throwable is reported on the
     * gr_adapter_error hook and swallowed (docs/02 §2.7).
     *
     * @param callable $work The adapter step.
     * @return void
     */
    private function guarded( callable $work ): void {
        try {
            $work();
        } catch ( \Throwable $error ) {
            do_action( 'gr_adapter_error', self::get_id(), $error );
        }
    }

    /**
     * Order id from either hook's argument shape.
     *
     * @param mixed $order Order object or numeric id.
     * @return int
     */
    private function order_id( $order ): int {
        if ( is_numeric( $order ) ) {
            return (int) $order;
        }

        if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
            return (int) $order->get_id();
        }

        return 0;
    }
}
