<?php
/**
 * Meta Conversions API forwarder (docs/13 I2, docs/07 §5.1): the pure
 * payload builder with its provider-side digests, the capture-side
 * gate stack (consent, configuration, traffic quality), the queue
 * contract on the send side (reschedule exactly when the door says
 * when), and the standing rule that an unconfigured or unwilling
 * site manufactures no work at all.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Http_Client;
use GreenPNG\Core\Gr_Meta_Capi;
use GreenPNG\Core\Gr_Secrets;
use GreenPNG\Core\Gr_Settings;
use PHPUnit\Framework\TestCase;

final class MetaCapiTest extends TestCase {

    /** The endpoint the enabled fixture answers for. */
    private const URL = 'https://graph.facebook.com/v23.0/123456789012345/events';

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        $GLOBALS['wpdb']->var_result = null;
        $GLOBALS['wpdb']->inserts    = array();
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb']->var_result = null;
        parent::tearDown();
    }

    /**
     * Enables the Meta service with a full credential pair.
     *
     * @return void
     */
    private function enable_meta(): void {
        $settings = new Gr_Settings();
        $settings->set( 'capi_meta_enabled', 1 );
        Gr_Secrets::store( Gr_Secrets::META_PIXEL_OPTION, '123456789012345' );
        Gr_Secrets::store( Gr_Secrets::META_TOKEN_OPTION, 'EAAG-metacapitesttoken' );
    }

    /**
     * Seeds one paid order in the stub registry.
     *
     * @param int                  $id   Order id.
     * @param array<string, mixed> $meta Order meta (visitor binding, …).
     * @return \WC_Order
     */
    private function seed_order( int $id, array $meta = array() ): \WC_Order {
        $order = new \WC_Order( $id );
        $order->total         = 129.99;
        $order->currency      = 'USD';
        $order->billing_email = 'Buyer@Example.com';
        foreach ( $meta as $key => $value ) {
            $order->update_meta_data( $key, $value );
        }
        $GLOBALS['gr_stub_wc_orders'][ $id ] = $order;

        return $order;
    }

    /**
     * A capture-side fixture that passes every gate except the ones
     * the test is about to fail.
     *
     * @param int $order_id Order id.
     * @return void
     */
    private function stage_capture( int $order_id = 5 ): void {
        $this->enable_meta();
        $GLOBALS['gr_stub_consent']['marketing'] = true;
        $this->seed_order( $order_id, array( '_gr_visitor_id' => 'v-clean' ) );
        $GLOBALS['wpdb']->var_result = '0';
    }

    /**
     * The queued cron entries for the send hook.
     *
     * @return array<int, array<string, mixed>>
     */
    private function queued(): array {
        $jobs = array();
        foreach ( $GLOBALS['gr_stub_cron'] as $event ) {
            if ( Gr_Meta_Capi::SEND_HOOK === (string) $event['hook'] ) {
                $jobs[] = $event;
            }
        }

        return $jobs;
    }

    public function testBuildPurchasePayloadIsPureAndHashesPii(): void {
        $payload = Gr_Meta_Capi::build_purchase_payload( array(
            'event_id'   => 'capi_deadbeef',
            'email'      => '  Buyer@Example.COM ',
            'phone'      => '+1 555 0100',
            'value'      => 12.3456,
            'currency'   => 'usd',
            'source_url' => 'https://shop.example/checkout/thanks/',
            'event_time' => 1700000000,
        ) );

        self::assertSame( 'Purchase', $payload['event_name'] );
        self::assertSame( 1700000000, $payload['event_time'] );
        self::assertSame( 'capi_deadbeef', $payload['event_id'] );
        self::assertSame( 'website', $payload['action_source'] );

        // Provider-side digest: sha-256 of the normalized value with
        // no local prefix, or Meta could never match its own hash.
        self::assertSame( hash( 'sha256', 'buyer@example.com' ), $payload['user_data']['em'] );
        self::assertSame( hash( 'sha256', strtolower( trim( '+1 555 0100' ) ) ), $payload['user_data']['ph'] );

        self::assertSame( '12.35', $payload['custom_data']['value'] );
        self::assertSame( 'USD', $payload['custom_data']['currency'] );
        self::assertSame( 'https://shop.example/checkout/thanks/', $payload['event_source_url'] );

        // No PII leaves the builder in the clear.
        self::assertStringNotContainsString( 'buyer@example.com', var_export( $payload, true ) );
    }

    public function testBuildPayloadOmitsEmptyPiecesRatherThanEmptyKeys(): void {
        $payload = Gr_Meta_Capi::build_purchase_payload( array(
            'event_id' => 'capi_x',
            'email'    => '',
            'value'    => 0,
        ) );

        self::assertArrayNotHasKey( 'user_data', $payload );
        self::assertArrayNotHasKey( 'custom_data', $payload );
        self::assertArrayNotHasKey( 'event_source_url', $payload );
    }

    public function testUnconfiguredServiceManufacturesNoWork(): void {
        $GLOBALS['gr_stub_consent']['marketing'] = true;
        $this->seed_order( 5, array( '_gr_visitor_id' => 'v-clean' ) );

        Gr_Meta_Capi::on_payment_complete( 5 );

        self::assertSame( array(), $GLOBALS['gr_stub_cron'] );
        self::assertSame( array(), $GLOBALS['gr_stub_http_calls'] );
    }

    public function testNoMarketingConsentMeansNoQueueEntry(): void {
        $this->enable_meta();
        $this->seed_order( 5, array( '_gr_visitor_id' => 'v-clean' ) );
        $GLOBALS['wpdb']->var_result = '0';

        Gr_Meta_Capi::on_payment_complete( 5 );

        self::assertSame( array(), $this->queued() );
    }

    public function testBotVisitorNeverReachesTheQueue(): void {
        $this->enable_meta();
        $GLOBALS['gr_stub_consent']['marketing'] = true;
        $this->seed_order( 5, array( '_gr_visitor_id' => 'v-bot' ) );
        $GLOBALS['wpdb']->var_result = '1';

        Gr_Meta_Capi::on_payment_complete( 5 );

        self::assertSame( array(), $this->queued() );
        self::assertSame( array(), $GLOBALS['gr_stub_http_calls'] );
    }

    public function testCleanOrderQueuesOneJobCarryingOnlyHashes(): void {
        $this->stage_capture( 5 );

        Gr_Meta_Capi::on_payment_complete( 5 );

        $jobs = $this->queued();
        self::assertCount( 1, $jobs );
        self::assertGreaterThanOrEqual( time(), (int) $jobs[0]['timestamp'] );

        $payload = $jobs[0]['args'][0];
        self::assertSame( 'Purchase', $payload['event_name'] );
        self::assertSame( hash( 'sha256', 'buyer@example.com' ), $payload['user_data']['em'] );
        self::assertSame( '129.99', $payload['custom_data']['value'] );
        self::assertSame( 'USD', $payload['custom_data']['currency'] );

        // The deterministic id: a replayed capture converges here.
        self::assertSame( gr_generate_event_id( 'capi', 'order|5' ), $payload['event_id'] );

        // The queue carries digests, never the email itself.
        self::assertStringNotContainsString( 'buyer@example.com', var_export( $jobs, true ) );
    }

    public function testUnknownOrMalformedOrderIdsAreIgnored(): void {
        $this->stage_capture( 5 );

        Gr_Meta_Capi::on_payment_complete( 999 );
        Gr_Meta_Capi::on_payment_complete( 'not-an-id' );

        self::assertSame( array(), $this->queued() );
    }

    public function testReplayedCaptureConvergesOnOneEventId(): void {
        $this->stage_capture( 5 );

        Gr_Meta_Capi::on_payment_complete( 5 );
        Gr_Meta_Capi::on_payment_complete( 5 );

        $jobs = $this->queued();
        self::assertCount( 2, $jobs );
        self::assertSame( $jobs[0]['args'][0]['event_id'], $jobs[1]['args'][0]['event_id'] );
    }

    public function testExplodingOrderNeverBreaksCheckout(): void {
        $this->stage_capture( 5 );
        $GLOBALS['gr_stub_wc_orders'][5]->explode = true;

        $seen = array();
        add_action( 'gr_adapter_error', static function ( $id, $error ) use ( &$seen ): void {
            $seen[] = array( (string) $id, $error );
        }, 10, 2 );

        Gr_Meta_Capi::on_payment_complete( 5 );

        self::assertSame( array(), $this->queued() );
        self::assertCount( 1, $seen );
        self::assertSame( 'meta_capi', $seen[0][0] );
    }

    public function testSendSuccessIsOneCallAndNoReschedule(): void {
        $this->enable_meta();
        $GLOBALS['gr_stub_http'][ self::URL ] = array(
            'response' => array( 'code' => 200 ),
            'body'     => '{"events_received":1}',
        );
        $payload = Gr_Meta_Capi::build_purchase_payload( array(
            'event_id' => 'capi_ok',
            'email'    => 'ok@example.com',
        ) );

        Gr_Meta_Capi::send( $payload );

        self::assertCount( 1, $GLOBALS['gr_stub_http_calls'] );
        self::assertSame( 'POST', $GLOBALS['gr_stub_http_calls'][0]['method'] );
        self::assertSame( array(), $this->queued() );
        self::assertFalse( get_transient( 'gr_http_' . Gr_Http_Client::SERVICE_META_CAPI ) );
    }

    public function testSendWireFailureReschedulesOnTheLadder(): void {
        $this->enable_meta();
        $GLOBALS['gr_stub_http'][ self::URL ] = new \WP_Error( 'http_request_failed', 'Connection refused.' );
        $payload = Gr_Meta_Capi::build_purchase_payload( array( 'event_id' => 'capi_retry' ) );

        Gr_Meta_Capi::send( $payload );

        self::assertCount( 1, $GLOBALS['gr_stub_http_calls'] );

        $jobs = $this->queued();
        self::assertCount( 1, $jobs );
        self::assertSame( $payload, $jobs[0]['args'][0] );
        self::assertGreaterThanOrEqual( time() + 25, (int) $jobs[0]['timestamp'] );
    }

    public function testSendGiveUpStopsReschedulingAndLeavesOneAuditRow(): void {
        $this->enable_meta();
        $GLOBALS['gr_stub_http'][ self::URL ] = new \WP_Error( 'http_request_failed', 'Connection refused.' );
        set_transient( 'gr_http_' . Gr_Http_Client::SERVICE_META_CAPI, array(
            'fails'      => 3,
            'open_until' => 0,
            'next_at'    => 0,
        ) );
        $payload = Gr_Meta_Capi::build_purchase_payload( array( 'event_id' => 'capi_last' ) );

        Gr_Meta_Capi::send( $payload );

        self::assertSame( array(), $this->queued() );

        $audit = 0;
        foreach ( $GLOBALS['wpdb']->inserts as $insert ) {
            if ( 'wp_gr_audit_logs' === (string) $insert['table'] && 'http_give_up' === (string) $insert['data']['action'] ) {
                $audit++;
            }
        }
        self::assertSame( 1, $audit );
    }

    public function testSendDropsEverythingWhenUnconfiguredAtSendTime(): void {
        // Captured earlier, credentials removed since: the send-side
        // re-check keeps the network quiet.
        $payload = Gr_Meta_Capi::build_purchase_payload( array( 'event_id' => 'capi_gone' ) );

        Gr_Meta_Capi::send( $payload );

        self::assertSame( array(), $GLOBALS['gr_stub_http_calls'] );
        self::assertSame( array(), $this->queued() );
    }

    public function testSendIgnoresPayloadsTheBuilderWouldNeverMake(): void {
        $this->enable_meta();

        Gr_Meta_Capi::send( array( 'event_time' => time() ) );

        self::assertSame( array(), $GLOBALS['gr_stub_http_calls'] );
    }

    public function testVersionFilterSteersTheEndpoint(): void {
        $this->enable_meta();
        add_filter( 'gr_meta_api_version', static function (): string {
            return 'v24.0';
        } );
        $GLOBALS['gr_stub_http']['https://graph.facebook.com/v24.0/123456789012345/events'] = array(
            'response' => array( 'code' => 200 ),
            'body'     => '{}',
        );
        $payload = Gr_Meta_Capi::build_purchase_payload( array( 'event_id' => 'capi_v24' ) );

        Gr_Meta_Capi::send( $payload );

        self::assertCount( 1, $GLOBALS['gr_stub_http_calls'] );
        self::assertStringContainsString( '/v24.0/', (string) $GLOBALS['gr_stub_http_calls'][0]['url'] );
    }

    public function testRegisterWiresSendHookAndGatesThePaymentHookOnTheTarget(): void {
        // No WooCommerce marker class is defined yet in this process,
        // and a later test file depends on that staying true: its
        // absent-target precondition needs the probe unanswered. So
        // this side proves the gated arm here, and the present-target
        // arm is covered where the marker class already exists.
        Gr_Meta_Capi::register();

        $hooks = array();
        foreach ( $GLOBALS['gr_stub_actions'] as $entry ) {
            if ( Gr_Meta_Capi::SEND_HOOK === (string) $entry['hook'] ) {
                $hooks[] = (string) $entry['hook'] . '@' . (string) $entry['priority'];
            }
            self::assertStringStartsNotWith( 'woocommerce_', (string) $entry['hook'] );
        }

        self::assertContains( Gr_Meta_Capi::SEND_HOOK . '@10', $hooks );
    }
}
