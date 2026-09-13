<?php
/**
 * GA4 Measurement Protocol forwarder (docs/13 I3, docs/07 §5.2): the
 * _ga cookie gate (no client id, no event — fabricated ids are how
 * garbage sessions get born), the pure payload builder in GA4's
 * no-PII vocabulary, the capture-side gate stack, the queue retry
 * contract, and the owner-clicked debug-endpoint probe with its
 * result words.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Ga4_Mp;
use GreenPNG\Core\Gr_Http_Client;
use GreenPNG\Core\Gr_Secrets;
use GreenPNG\Core\Gr_Settings;
use PHPUnit\Framework\TestCase;

final class Ga4MpTest extends TestCase {

    /** Enabled fixture credentials. */
    private const MID = 'G-ABC1234567';

    /** Enabled fixture secret. */
    private const SECRET = 'TESTSECRET32CHARSXXXXXXXXXXXX';

    /** The collect endpoint for the enabled fixture. */
    private const COLLECT_URL = 'https://www.google-analytics.com/mp/collect?measurement_id=G-ABC1234567&api_secret=TESTSECRET32CHARSXXXXXXXXXXXX';

    /** The debug endpoint for the enabled fixture. */
    private const DEBUG_URL = 'https://www.google-analytics.com/debug/mp/collect?measurement_id=G-ABC1234567&api_secret=TESTSECRET32CHARSXXXXXXXXXXXX';

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        // Emptied, never unset: a missing cookie is an absent key, and
        // later tests in the suite read the superglobal directly.
        $_COOKIE                      = array();
        $GLOBALS['wpdb']->var_result  = null;
        $GLOBALS['wpdb']->inserts     = array();
    }

    protected function tearDown(): void {
        $_COOKIE                     = array();
        $GLOBALS['wpdb']->var_result = null;
        parent::tearDown();
    }

    /**
     * Enables the GA4 service with a full credential pair.
     *
     * @return void
     */
    private function enable_ga4(): void {
        $settings = new Gr_Settings();
        $settings->set( 'capi_ga4_enabled', 1 );
        Gr_Secrets::store( Gr_Secrets::GA4_ID_OPTION, self::MID );
        Gr_Secrets::store( Gr_Secrets::GA4_SECRET_OPTION, self::SECRET );
    }

    /**
     * Seeds one paid order with a clean visitor binding.
     *
     * @param int $order_id Order id.
     * @return \WC_Order
     */
    private function seed_order( int $order_id ): \WC_Order {
        $order                = new \WC_Order( $order_id );
        $order->total         = 129.99;
        $order->currency      = 'USD';
        $order->billing_email = 'Buyer@Example.com';
        $order->update_meta_data( '_gr_visitor_id', 'v-clean' );
        $GLOBALS['gr_stub_wc_orders'][ $order_id ] = $order;

        return $order;
    }

    /**
     * A capture fixture that passes every gate except the ones the
     * test is about to fail.
     *
     * @return void
     */
    private function stage_capture(): void {
        $this->enable_ga4();
        $GLOBALS['gr_stub_consent']['marketing'] = true;
        $this->seed_order( 5 );
        $GLOBALS['wpdb']->var_result = '0';
        $_COOKIE                     = array( '_ga' => 'GA1.1.987654321.1700000000' );
    }

    /**
     * Queued cron entries for the send hook.
     *
     * @return array<int, array<string, mixed>>
     */
    private function queued(): array {
        $jobs = array();
        foreach ( $GLOBALS['gr_stub_cron'] as $event ) {
            if ( Gr_Ga4_Mp::SEND_HOOK === (string) $event['hook'] ) {
                $jobs[] = $event;
            }
        }

        return $jobs;
    }

    public function testBuildPurchasePayloadIsPureAndCarriesNoPii(): void {
        $payload = Gr_Ga4_Mp::build_purchase_payload(
            array(
                'client_id' => '987654321.1700000000',
                'order_id'  => 15,
                'value'     => 129.999,
                'currency'  => 'usd',
            )
        );

        self::assertSame( '987654321.1700000000', $payload['client_id'] );
        self::assertCount( 1, $payload['events'] );
        self::assertSame( 'purchase', $payload['events'][0]['name'] );

        $params = $payload['events'][0]['params'];
        self::assertSame( '15', $params['transaction_id'] );
        self::assertSame( 'USD', $params['currency'] );
        self::assertSame( 130.0, $params['value'] );

        // GA4's vocabulary takes no PII in any form: no digest keys,
        // no email anywhere in the body.
        self::assertArrayNotHasKey( 'em', $payload );
        self::assertArrayNotHasKey( 'user_data', $payload );
        self::assertStringNotContainsString( 'example.com', var_export( $payload, true ) );
    }

    public function testBuildPayloadOmitsZeroValueAndEmptyCurrency(): void {
        $payload = Gr_Ga4_Mp::build_purchase_payload(
            array(
                'client_id' => '1.2',
                'order_id'  => 7,
                'value'     => 0,
                'currency'  => '',
            )
        );

        $params = $payload['events'][0]['params'];
        self::assertArrayNotHasKey( 'value', $params );
        self::assertArrayNotHasKey( 'currency', $params );
        self::assertSame( '7', $params['transaction_id'] );
    }

    public function testClientIdFromCookieParsesTheClassicShape(): void {
        $_COOKIE = array( '_ga' => 'GA1.1.987654321.1700000000' );
        self::assertSame( '987654321.1700000000', Gr_Ga4_Mp::client_id_from_cookie() );

        $_COOKIE = array( '_ga' => 'GA1.2.111.222' );
        self::assertSame( '111.222', Gr_Ga4_Mp::client_id_from_cookie() );
    }

    public function testClientIdFromCookieRefusesGarbage(): void {
        $_COOKIE = array( '_ga' => 'garbage' );
        self::assertSame( '', Gr_Ga4_Mp::client_id_from_cookie() );

        $_COOKIE = array( '_ga' => 'GA1.1.abc.def' );
        self::assertSame( '', Gr_Ga4_Mp::client_id_from_cookie() );

        $_COOKIE = array( '_ga' => 'GA1.1.123' );
        self::assertSame( '', Gr_Ga4_Mp::client_id_from_cookie() );

        $_COOKIE = array();
        self::assertSame( '', Gr_Ga4_Mp::client_id_from_cookie() );
    }

    public function testNoClientCookieMeansNoQueueEntry(): void {
        $this->enable_ga4();
        $GLOBALS['gr_stub_consent']['marketing'] = true;
        $this->seed_order( 5 );
        $GLOBALS['wpdb']->var_result = '0';

        Gr_Ga4_Mp::on_payment_complete( 5 );

        self::assertSame( array(), $this->queued() );
        self::assertSame( array(), $GLOBALS['gr_stub_http_calls'] );
    }

    public function testUnconfiguredServiceManufacturesNoWork(): void {
        $GLOBALS['gr_stub_consent']['marketing'] = true;
        $this->seed_order( 5 );
        $_COOKIE = array( '_ga' => 'GA1.1.987654321.1700000000' );

        Gr_Ga4_Mp::on_payment_complete( 5 );

        self::assertSame( array(), $GLOBALS['gr_stub_cron'] );
        self::assertSame( array(), $GLOBALS['gr_stub_http_calls'] );
    }

    public function testNoMarketingConsentMeansNoQueueEntry(): void {
        $this->enable_ga4();
        $this->seed_order( 5 );
        $GLOBALS['wpdb']->var_result = '0';
        $_COOKIE                     = array( '_ga' => 'GA1.1.987654321.1700000000' );

        Gr_Ga4_Mp::on_payment_complete( 5 );

        self::assertSame( array(), $this->queued() );
    }

    public function testBotVisitorNeverReachesTheQueue(): void {
        $this->enable_ga4();
        $GLOBALS['gr_stub_consent']['marketing'] = true;
        $this->seed_order( 5 );
        $GLOBALS['wpdb']->var_result = '1';
        $_COOKIE                     = array( '_ga' => 'GA1.1.987654321.1700000000' );

        Gr_Ga4_Mp::on_payment_complete( 5 );

        self::assertSame( array(), $this->queued() );
    }

    public function testCleanOrderWithCookieQueuesOneJob(): void {
        $this->stage_capture();

        Gr_Ga4_Mp::on_payment_complete( 5 );

        $jobs = $this->queued();
        self::assertCount( 1, $jobs );

        $payload = $jobs[0]['args'][0];
        self::assertSame( '987654321.1700000000', $payload['client_id'] );
        self::assertSame( 'purchase', $payload['events'][0]['name'] );
        self::assertSame( '5', $payload['events'][0]['params']['transaction_id'] );
        self::assertSame( 129.99, $payload['events'][0]['params']['value'] );
        self::assertSame( 'USD', $payload['events'][0]['params']['currency'] );
    }

    public function testReplayedCaptureConvergesOnOneTransactionId(): void {
        $this->stage_capture();

        Gr_Ga4_Mp::on_payment_complete( 5 );
        Gr_Ga4_Mp::on_payment_complete( 5 );

        $jobs = $this->queued();
        self::assertCount( 2, $jobs );
        self::assertSame(
            $jobs[0]['args'][0]['events'][0]['params']['transaction_id'],
            $jobs[1]['args'][0]['events'][0]['params']['transaction_id']
        );
    }

    public function testExplodingOrderNeverBreaksCheckout(): void {
        $this->stage_capture();
        $GLOBALS['gr_stub_wc_orders'][5]->explode = true;

        $seen = array();
        add_action(
            'gr_adapter_error',
            static function ( $id, $error ) use ( &$seen ): void {
                $seen[] = (string) $id;
            },
            10,
            2
        );

        Gr_Ga4_Mp::on_payment_complete( 5 );

        self::assertSame( array(), $this->queued() );
        self::assertSame( array( 'ga4_mp' ), $seen );
    }

    public function testSendSuccessIsOneCallAndNoReschedule(): void {
        $this->enable_ga4();
        $GLOBALS['gr_stub_http'][ self::COLLECT_URL ] = array(
            'response' => array( 'code' => 200 ),
            'body'     => '',
        );
        $payload                                      = Gr_Ga4_Mp::build_purchase_payload(
            array(
                'client_id' => '1.2',
                'order_id'  => 9,
            )
        );

        Gr_Ga4_Mp::send( $payload );

        self::assertCount( 1, $GLOBALS['gr_stub_http_calls'] );
        self::assertSame( 'POST', $GLOBALS['gr_stub_http_calls'][0]['method'] );
        self::assertSame( array(), $this->queued() );
        self::assertFalse( get_transient( 'gr_http_' . Gr_Http_Client::SERVICE_GA4_MP ) );
    }

    public function testSendWireFailureReschedulesOnTheLadder(): void {
        $this->enable_ga4();
        $GLOBALS['gr_stub_http'][ self::COLLECT_URL ] = new \WP_Error( 'http_request_failed', 'Connection refused.' );
        $payload                                      = Gr_Ga4_Mp::build_purchase_payload(
            array(
                'client_id' => '1.2',
                'order_id'  => 9,
            )
        );

        Gr_Ga4_Mp::send( $payload );

        self::assertCount( 1, $GLOBALS['gr_stub_http_calls'] );

        $jobs = $this->queued();
        self::assertCount( 1, $jobs );
        self::assertSame( $payload, $jobs[0]['args'][0] );
        self::assertGreaterThanOrEqual( time() + 25, (int) $jobs[0]['timestamp'] );
    }

    public function testSendGiveUpStopsReschedulingAndLeavesOneAuditRow(): void {
        $this->enable_ga4();
        $GLOBALS['gr_stub_http'][ self::COLLECT_URL ] = new \WP_Error( 'http_request_failed', 'Connection refused.' );
        set_transient(
            'gr_http_' . Gr_Http_Client::SERVICE_GA4_MP,
            array(
                'fails'      => 3,
                'open_until' => 0,
                'next_at'    => 0,
            )
        );
        $payload = Gr_Ga4_Mp::build_purchase_payload(
            array(
                'client_id' => '1.2',
                'order_id'  => 9,
            )
        );

        Gr_Ga4_Mp::send( $payload );

        self::assertSame( array(), $this->queued() );

        $audit = 0;
        foreach ( $GLOBALS['wpdb']->inserts as $insert ) {
            if ( 'wp_gr_audit_logs' === (string) $insert['table'] && 'http_give_up' === (string) $insert['data']['action'] ) {
                ++$audit;
            }
        }
        self::assertSame( 1, $audit );
    }

    public function testSendDropsEverythingWhenUnconfiguredOrCidless(): void {
        $payload = Gr_Ga4_Mp::build_purchase_payload( array( 'order_id' => 9 ) );

        // No credentials stored: the send-side re-check holds.
        Gr_Ga4_Mp::send( $payload );
        self::assertSame( array(), $GLOBALS['gr_stub_http_calls'] );

        // Configured but the payload lost its client id (a corrupt or
        // hand-made job): the garbage-session defense again.
        $this->enable_ga4();
        Gr_Ga4_Mp::send( $payload );
        self::assertSame( array(), $GLOBALS['gr_stub_http_calls'] );
    }

    public function testDebugCheckRefusesUnconfiguredWithoutTouchingTheNetwork(): void {
        $outcome = Gr_Ga4_Mp::debug_check();

        self::assertSame( 'not_configured', $outcome['result'] );
        self::assertSame( array(), $GLOBALS['gr_stub_http_calls'] );
    }

    public function testDebugCheckOkOnEmptyValidationMessages(): void {
        $this->enable_ga4();
        $GLOBALS['gr_stub_http'][ self::DEBUG_URL ] = array(
            'response' => array( 'code' => 200 ),
            'body'     => '{"validationMessages":[]}',
        );

        $outcome = Gr_Ga4_Mp::debug_check();

        self::assertSame( 'ok', $outcome['result'] );
        self::assertCount( 1, $GLOBALS['gr_stub_http_calls'] );
        self::assertStringContainsString( '/debug/mp/collect', (string) $GLOBALS['gr_stub_http_calls'][0]['url'] );
    }

    public function testDebugCheckReportsValidationMessagesByCount(): void {
        $this->enable_ga4();
        $GLOBALS['gr_stub_http'][ self::DEBUG_URL ] = array(
            'response' => array( 'code' => 200 ),
            'body'     => '{"validationMessages":[{"field":"events"} ,{"field":"client_id"}]}',
        );

        $outcome = Gr_Ga4_Mp::debug_check();

        self::assertSame( 'validation', $outcome['result'] );
        self::assertSame( 2, $outcome['messages'] );
    }

    public function testDebugCheckIsUnreachableOnWireFailureAndOnAlienBodies(): void {
        $this->enable_ga4();
        $GLOBALS['gr_stub_http'][ self::DEBUG_URL ] = new \WP_Error( 'http_request_failed', 'Connection refused.' );
        self::assertSame( 'unreachable', Gr_Ga4_Mp::debug_check()['result'] );

        // A 2xx body without the validation shape is not a health
        // claim this plugin is willing to make.
        $GLOBALS['gr_stub_http'][ self::DEBUG_URL ] = array(
            'response' => array( 'code' => 200 ),
            'body'     => '{"unrelated":true}',
        );
        self::assertSame( 'unreachable', Gr_Ga4_Mp::debug_check()['result'] );
    }

    public function testRegisterWiresSendHookAndGatesThePaymentHookOnTheTarget(): void {
        // The marker class stays undefined in this process (a later
        // test file depends on that); the present-target arm lives in
        // the adapter test file where the marker already exists.
        Gr_Ga4_Mp::register();

        $send_hooks = 0;
        foreach ( $GLOBALS['gr_stub_actions'] as $entry ) {
            if ( Gr_Ga4_Mp::SEND_HOOK === (string) $entry['hook'] ) {
                ++$send_hooks;
            }
            self::assertStringStartsNotWith( 'woocommerce_', (string) $entry['hook'] );
        }

        self::assertSame( 1, $send_hooks );
    }
}
