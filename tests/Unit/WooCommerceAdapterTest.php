<?php
/**
 * WooCommerce adapter (docs/13 C10): availability gating, both capture
 * paths, the payment-complete binding with its meta lock, and
 * Throwable isolation.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Attribution\Gr_Attribution_Service;
use GreenPNG\Attribution\Gr_Identity;
use GreenPNG\Core\Gr_Meta_Capi;
use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Core\Gr_Settings;
use GreenPNG\Integrations\Ecosystem\Gr_Woocommerce_Adapter;
use GreenPNG\Storage\Gr_Conversion_Repository;
use GreenPNG\Storage\Gr_Touchpoint_Repository;
use PHPUnit\Framework\TestCase;

final class WooCommerceAdapterTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();

        $_SERVER['REMOTE_ADDR']      = '10.0.0.9';
        $_SERVER['HTTP_USER_AGENT'] = 'UnitTestAgent/1.0';

        $settings = new Gr_Settings();
        $GLOBALS['gr_adapter']      = new Gr_Woocommerce_Adapter(
            new Gr_Identity( $settings ),
            new Gr_Attribution_Service( new Gr_Touchpoint_Repository(), new Gr_Conversion_Repository() )
        );
        $GLOBALS['gr_settings_obj'] = $settings;
    }

    protected function tearDown(): void {
        unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'], $GLOBALS['gr_adapter'], $GLOBALS['gr_settings_obj'] );
        // Assign, never unset: superglobals must stay defined for other
        // tests' isset() checks.
        $_COOKIE = array();
        parent::tearDown();
    }

    /**
     * Arms the cookie track: consent on plus a signed gr_attr cookie.
     *
     * @return string The visitor id on the cookie track.
     */
    private function arm_cookie_track(): string {
        $GLOBALS['gr_stub_consent']['marketing'] = true;

        $visitor = str_repeat( 'a', 32 );
        $_COOKIE = array( Gr_Identity::COOKIE => Gr_Identity::cookie_value( $visitor ) );

        return $visitor;
    }

    /**
     * Builds one stub order in the registry.
     *
     * @param int $id Order id.
     * @return \WC_Order
     */
    private function order( int $id ): \WC_Order {
        $order = new \WC_Order( $id );
        $order->total    = 120.0;
        $order->currency = 'USD';

        return $GLOBALS['gr_stub_wc_orders'][ $id ] = $order;
    }

    public function testAbsentTargetRegistersNoHooksAndThePluginSkipsTheAdapter(): void {
        // Test order matters: the WooCommerce marker class is not
        // defined yet in this process, so this exercises the absent
        // target on a real site.
        self::assertFalse( Gr_Woocommerce_Adapter::is_available() );

        $GLOBALS['gr_adapter']->register_hooks();

        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            self::assertStringStartsNotWith( 'woocommerce_', (string) $registration['hook'] );
        }

        // The plugin-level gate: without the target class the adapter
        // never registers anything either.
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            self::assertStringStartsNotWith( 'woocommerce_', (string) $registration['hook'] );
        }
    }

    public function testPresentTargetRegistersAllEightMounts(): void {
        if ( ! class_exists( 'WooCommerce', false ) ) {
            eval( 'final class WooCommerce {}' );
        }

        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        $hooks = array();
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            if ( 0 === strpos( (string) $registration['hook'], 'woocommerce_' ) ) {
                $hooks[] = (string) $registration['hook'];
            }
        }

        self::assertContains( 'woocommerce_checkout_update_order_meta', $hooks );
        self::assertContains( 'woocommerce_store_api_checkout_update_order_from_request', $hooks );
        self::assertContains( 'woocommerce_payment_complete', $hooks );
        // The offline-gateway hole (ADR-0009 D4): Store API orders on
        // offline gateways are born directly in a paid status, so
        // payment_complete never fires for them — the status
        // transitions are the binding path that does.
        self::assertContains( 'woocommerce_order_status_processing', $hooks );
        self::assertContains( 'woocommerce_order_status_completed', $hooks );
        // Refund reversal (ADR-0010): full refund and cancellation
        // soft-mark the bound row, partial refunds converge the amount.
        self::assertContains( 'woocommerce_order_status_refunded', $hooks );
        self::assertContains( 'woocommerce_order_status_cancelled', $hooks );
        self::assertContains( 'woocommerce_order_partially_refunded', $hooks );
    }

    public function testPresentTargetAlsoRegistersTheCapiPaymentForwarder(): void {
        // The marker class is already defined by this point in the
        // process, so the CAPI forwarder's own gate resolves present —
        // proving the wiring joins exactly when the target does.
        if ( ! class_exists( 'WooCommerce', false ) ) {
            eval( 'final class WooCommerce {}' );
        }

        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        $capi_payment = null;
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            // The adapter registers the same hook with an instance
            // callback; only the class-based registration is the
            // forwarder's.
            if ( 'woocommerce_payment_complete' === (string) $registration['hook']
                && is_array( $registration['callback'] )
                && is_string( $registration['callback'][0] )
                && Gr_Meta_Capi::class === $registration['callback'][0] ) {
                $capi_payment = $registration;
            }
        }

        self::assertNotNull( $capi_payment );
        self::assertSame( 11, (int) $capi_payment['priority'] );
        self::assertSame( 'on_payment_complete', (string) $capi_payment['callback'][1] );

        // The browser-side half of the deduplication pair joins with
        // the payment hook when the target is present.
        $capi_thankyou = null;
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            if ( 'woocommerce_thankyou' === (string) $registration['hook']
                && is_array( $registration['callback'] )
                && is_string( $registration['callback'][0] )
                && Gr_Meta_Capi::class === $registration['callback'][0] ) {
                $capi_thankyou = $registration;
            }
        }
        self::assertNotNull( $capi_thankyou );
        self::assertSame( 'print_event_id', (string) $capi_thankyou['callback'][1] );

        Gr_Plugin::reset_instance();
    }

    public function testClassicCapturePersistsTheCookieTrackVisitor(): void {
        if ( ! class_exists( 'WooCommerce', false ) ) {
            eval( 'final class WooCommerce {}' );
        }

        $visitor = $this->arm_cookie_track();
        $order   = $this->order( 501 );

        $GLOBALS['gr_adapter']->capture_classic( 501, array( 'billing_email' => 'x@example.com' ) );

        self::assertSame( $visitor, $order->get_meta( Gr_Woocommerce_Adapter::VISITOR_META ) );
    }

    public function testStoreApiCaptureHandlesTheOrderObjectShape(): void {
        if ( ! class_exists( 'WooCommerce', false ) ) {
            eval( 'final class WooCommerce {}' );
        }

        $visitor = $this->arm_cookie_track();
        $order   = $this->order( 502 );

        $GLOBALS['gr_adapter']->capture_store_api( $order, null );

        self::assertSame( $visitor, $order->get_meta( Gr_Woocommerce_Adapter::VISITOR_META ) );
    }

    public function testCaptureIsWriteOnceForBothPaths(): void {
        if ( ! class_exists( 'WooCommerce', false ) ) {
            eval( 'final class WooCommerce {}' );
        }

        $visitor = $this->arm_cookie_track();
        $order   = $this->order( 503 );

        $GLOBALS['gr_adapter']->capture_classic( 503, array() );
        // Second path fires later with a different identity available:
        // the first capture must win.
        $_COOKIE = array( Gr_Identity::COOKIE => Gr_Identity::cookie_value( str_repeat( 'b', 32 ) ) );
        $GLOBALS['gr_adapter']->capture_store_api( $order, null );

        self::assertSame( $visitor, $order->get_meta( Gr_Woocommerce_Adapter::VISITOR_META ) );
    }

    public function testFallbackTrackIsNeverPersistedToAnOrder(): void {
        if ( ! class_exists( 'WooCommerce', false ) ) {
            eval( 'final class WooCommerce {}' );
        }

        // No consent, no cookie: identity is the daily fallback.
        $order = $this->order( 504 );

        $GLOBALS['gr_adapter']->capture_classic( 504, array() );

        self::assertSame( '', $order->get_meta( Gr_Woocommerce_Adapter::VISITOR_META ) );
    }

    public function testPaymentCompleteBindsOnceAndLocksTheOrder(): void {
        global $wpdb;

        if ( ! class_exists( 'WooCommerce', false ) ) {
            eval( 'final class WooCommerce {}' );
        }

        $visitor = $this->arm_cookie_track();
        $order   = $this->order( 505 );
        $order->update_meta_data( Gr_Woocommerce_Adapter::VISITOR_META, $visitor );

        $wpdb->results   = array();
        $wpdb->insert_id = 31;

        $GLOBALS['gr_adapter']->complete_payment( 505 );

        self::assertSame( '1', $order->get_meta( Gr_Woocommerce_Adapter::ATTRIBUTED_META ) );
        self::assertStringContainsString( 'INSERT IGNORE INTO wp_gr_conversions', implode( ' ', $wpdb->queries ) );

        // Replayed payment-complete: the meta lock short-circuits
        // before any new write.
        $wpdb->queries   = array();
        $wpdb->insert_id = 32;

        $GLOBALS['gr_adapter']->complete_payment( 505 );

        self::assertStringNotContainsString( 'wp_gr_conversions', implode( ' ', $wpdb->queries ) );
    }

    public function testPaymentCompleteWithoutConsentSkipsTheBinding(): void {
        global $wpdb;

        if ( ! class_exists( 'WooCommerce', false ) ) {
            eval( 'final class WooCommerce {}' );
        }

        // Cookie track exists but consent is denied: no binding, and
        // the order stays unlocked so a later granted consent can bind.
        $visitor = str_repeat( 'c', 32 );
        $_COOKIE = array( Gr_Identity::COOKIE => Gr_Identity::cookie_value( $visitor ) );
        $order   = $this->order( 506 );
        $order->update_meta_data( Gr_Woocommerce_Adapter::VISITOR_META, $visitor );

        $GLOBALS['gr_adapter']->complete_payment( 506 );

        self::assertSame( '', $order->get_meta( Gr_Woocommerce_Adapter::ATTRIBUTED_META ) );
        self::assertStringNotContainsString( 'wp_gr_conversions', implode( ' ', $wpdb->queries ) );
    }

    public function testOutBandPaymentCompletionBindsThroughTheCapturedConsent(): void {
        global $wpdb;

        if ( ! class_exists( 'WooCommerce', false ) ) {
            eval( 'final class WooCommerce {}' );
        }

        // Checkout happens with consent granted; the payment then
        // completes in a request carrying no consent state at all (a
        // gateway webhook, the owner's CLI). The snapshot on the
        // order governs, so the binding still lands.
        $visitor = $this->arm_cookie_track();
        $order   = $this->order( 508 );

        $GLOBALS['gr_adapter']->capture_classic( 508, array() );
        self::assertSame( '1', $order->get_meta( Gr_Woocommerce_Adapter::CONSENT_META ) );

        $_COOKIE                    = array();
        $GLOBALS['gr_stub_consent'] = array( 'marketing' => false );

        $wpdb->queries   = array();
        $wpdb->results   = array();
        $wpdb->insert_id = 41;

        $GLOBALS['gr_adapter']->complete_payment( 508 );

        self::assertSame( '1', $order->get_meta( Gr_Woocommerce_Adapter::ATTRIBUTED_META ) );
        self::assertStringContainsString( 'INSERT IGNORE INTO wp_gr_conversions', implode( ' ', $wpdb->queries ) );
    }

    public function testThrownErrorsAreIsolatedAndReported(): void {
        if ( ! class_exists( 'WooCommerce', false ) ) {
            eval( 'final class WooCommerce {}' );
        }

        $this->arm_cookie_track();
        $order = $this->order( 507 );
        $order->explode = true;

        // Neither capture nor payment may let the exception escape.
        $GLOBALS['gr_adapter']->capture_classic( 507, array() );
        $GLOBALS['gr_adapter']->complete_payment( 507 );

        $reported = false;
        foreach ( $GLOBALS['gr_stub_fired_action_args'] as $record ) {
            if ( 'gr_adapter_error' === $record['hook'] ) {
                $reported = true;
                self::assertSame( 'woocommerce', $record['args'][0] );
                self::assertInstanceOf( \Throwable::class, $record['args'][1] );
            }
        }
        self::assertTrue( $reported );
    }

    public function testRefundedOrderSoftMarksTheBindingReversed(): void {
        global $wpdb;

        if ( ! class_exists( 'WooCommerce', false ) ) {
            eval( 'final class WooCommerce {}' );
        }

        $GLOBALS['gr_adapter']->register_hooks();

        $wpdb->results = array(
            array(
                'id'         => '31',
                'status'     => 'active',
                'amount'     => '120.00',
                'created_at' => '2026-08-01 10:00:00',
            ),
        );
        $wpdb->query_result = 1;

        do_action( 'woocommerce_order_status_refunded', 509 );

        $updates = array_filter(
            $wpdb->queries,
            static function ( $sql ): bool {
                return is_string( $sql ) && 0 === strpos( $sql, 'UPDATE wp_gr_conversions' );
            }
        );
        self::assertNotSame( array(), $updates );
        self::assertStringContainsString( "status = 'reversed'", implode( ' ', $updates ) );

        // The adapter wired the service's recompute queue for the
        // conversion's own date (ADR-0010 D3).
        $queued = false;
        foreach ( $GLOBALS['gr_stub_cron'] as $event ) {
            if ( 'gr_recompute_conversion_date' === $event['hook'] ) {
                self::assertSame( array( '2026-08-01' ), $event['args'] );
                $queued = true;
            }
        }
        self::assertTrue( $queued );

        // A replayed refund hook is a no-op at the business layer: it
        // issued its guarded UPDATE, matched nothing (the stub returns
        // 0 affected rows), and the service treated that as the no-op
        // it is — no second audit row, no second recompute.
        $wpdb->query_result = 0;
        do_action( 'woocommerce_order_status_refunded', 509 );

        $audit_rows = 0;
        foreach ( $wpdb->inserts as $insert ) {
            if ( 'wp_gr_audit_logs' === $insert['table'] && 'conversion_reversed' === $insert['data']['action'] ) {
                ++$audit_rows;
            }
        }
        $recompute_events = 0;
        foreach ( $GLOBALS['gr_stub_cron'] as $event ) {
            if ( 'gr_recompute_conversion_date' === $event['hook'] ) {
                ++$recompute_events;
            }
        }
        self::assertSame( 1, $audit_rows );
        self::assertSame( 1, $recompute_events );
    }

    public function testCancelledOrderNeverBoundLeavesNothingToReverse(): void {
        global $wpdb;

        if ( ! class_exists( 'WooCommerce', false ) ) {
            eval( 'final class WooCommerce {}' );
        }

        $GLOBALS['gr_adapter']->register_hooks();

        // No binding row: an order cancelled before payment was never
        // attributed (ADR-0010 D2's in-flight exclusion).
        $wpdb->results = array();

        do_action( 'woocommerce_order_status_cancelled', 510 );

        foreach ( $wpdb->queries as $query ) {
            self::assertStringNotContainsString( 'UPDATE wp_gr_conversions', (string) $query );
        }
    }

    public function testPartialRefundConvergesToTheOrderRemainingTotal(): void {
        global $wpdb;

        if ( ! class_exists( 'WooCommerce', false ) ) {
            eval( 'final class WooCommerce {}' );
        }

        $GLOBALS['gr_adapter']->register_hooks();

        $order = $this->order( 511 );
        $order->total           = 120.0;
        $order->total_refunded  = 60.0;

        $wpdb->results = array(
            array(
                'id'         => '32',
                'status'     => 'active',
                'amount'     => '120.00',
                'created_at' => '2026-08-02 10:00:00',
            ),
        );
        $wpdb->query_result = 1;

        do_action( 'woocommerce_order_partially_refunded', 511, 9001 );

        $updates = array_filter(
            $wpdb->queries,
            static function ( $sql ): bool {
                return is_string( $sql ) && 0 === strpos( $sql, 'UPDATE wp_gr_conversions' );
            }
        );
        self::assertNotSame( array(), $updates );
        $sql = implode( ' ', $updates );
        // The order is the single source of truth: replayed refund
        // hooks converge on the same terminal value (ADR-0010 D2).
        self::assertStringContainsString( "amount = '60.00'", $sql );
        self::assertStringNotContainsString( "status = 'reversed'", $sql );
    }

    public function testBornPaidStatusTransitionsBindAndStayIdempotent(): void {
        global $wpdb;

        if ( ! class_exists( 'WooCommerce', false ) ) {
            eval( 'final class WooCommerce {}' );
        }

        $visitor = $this->arm_cookie_track();
        $order   = $this->order( 509 );

        $GLOBALS['gr_adapter']->register_hooks();

        $wpdb->results   = array();
        $wpdb->insert_id = 31;

        // A Store API order on an offline gateway is born directly in
        // processing: payment_complete never fires, so the status hook
        // is the only binding path (ADR-0009 D4).
        do_action( 'woocommerce_order_status_processing', 509 );

        self::assertSame( '1', $order->get_meta( Gr_Woocommerce_Adapter::ATTRIBUTED_META ) );

        $binding_queries = array_filter(
            $wpdb->queries,
            static function ( $sql ): bool {
                return false !== strpos( (string) $sql, 'INSERT IGNORE INTO wp_gr_conversions' );
            }
        );
        self::assertNotSame( array(), $binding_queries );
        self::assertStringContainsString( "'" . $visitor . "'", implode( ' ', $binding_queries ) );

        // The meta lock collapses the replay: payment_complete and the
        // completed transition both arrive later and neither writes a
        // second record.
        do_action( 'woocommerce_payment_complete', 509 );
        do_action( 'woocommerce_order_status_completed', 509 );

        $after = array_filter(
            $wpdb->queries,
            static function ( $sql ): bool {
                return false !== strpos( (string) $sql, 'INSERT IGNORE INTO wp_gr_conversions' );
            }
        );
        self::assertCount( count( $binding_queries ), $after );
    }
}
