<?php
/**
 * WordPress privacy API integration (docs/13 V2, ADR-0005 §4): the
 * email → orders → visitor binding mapping chain, the four exporters
 * over the marketing rail, the erasers including the order binding
 * meta, the honest empty arms, and the suggested policy content.
 *
 * The file name sorts after WooCommerceAdapterTest on purpose: the
 * mapping chain needs the present-target posture, which the marker
 * class provides process-wide once evaled, and the adapter test's
 * absent-target arm depends on running before that happens.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Integrations\Ecosystem\Gr_Woocommerce_Adapter;
use GreenPNG\Privacy\Gr_Privacy_Api;
use PHPUnit\Framework\TestCase;

final class WpPrivacyApiTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        $GLOBALS['wpdb']->results    = array();
        $GLOBALS['wpdb']->queries    = array();
        $GLOBALS['wpdb']->var_result = null;

        // The mapping chain needs the target present; the marker
        // class is process-global, so this file owns it explicitly
        // and every test here assumes the present-target posture.
        if ( ! class_exists( 'WooCommerce', false ) ) {
            eval( 'final class WooCommerce {}' );
        }
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb']->results    = array();
        $GLOBALS['wpdb']->var_result = null;
        parent::tearDown();
    }

    /**
     * Seeds one order under the probe email carrying a visitor
     * binding, in the registry wc_get_orders reads.
     *
     * @param string $email   Billing email.
     * @param string $visitor Visitor binding.
     * @param int    $id      Order id.
     * @return \WC_Order
     */
    private function seed_order( string $email, string $visitor, int $id ): \WC_Order {
        $order                = new \WC_Order( $id );
        $order->billing_email = $email;
        $order->update_meta_data( Gr_Woocommerce_Adapter::VISITOR_META, $visitor );
        $GLOBALS['gr_stub_wc_orders'][ $id ] = $order;

        return $order;
    }

    /**
     * Stages one session/touchpoint/conversion row set for a visitor.
     *
     * @param string $visitor Visitor id.
     * @return void
     */
    private function stage_rows( string $visitor ): void {
        $GLOBALS['wpdb']->results = static function ( string $query ) use ( $visitor ): array {
            if ( false === strpos( $query, "'" . $visitor . "'" ) ) {
                return array();
            }

            if ( false !== strpos( $query, 'FROM wp_gr_sessions' ) ) {
                return array(
                    array(
                        'id'          => '11',
                        'session_id'  => 's-' . $visitor,
                        'channel'     => 'search',
                        'utm_source'  => 'google',
                        'is_bot'      => '0',
                        'bot_score'   => '0',
                        'pageviews'   => '3',
                        'started_at'  => '2026-09-01 10:00:00',
                        'last_active' => '2026-09-01 10:05:00',
                    ),
                );
            }

            if ( false !== strpos( $query, 'FROM wp_gr_touchpoints' ) ) {
                return array(
                    array(
                        'id'          => '21',
                        'channel'     => 'search',
                        'utm_source'  => 'google',
                        'click_id'    => 'gclid-1',
                        'landing_url' => 'https://shop.example/land',
                        'created_at'  => '2026-09-01 10:00:00',
                    ),
                );
            }

            if ( false !== strpos( $query, 'FROM wp_gr_conversions' ) ) {
                return array(
                    array(
                        'id'            => '31',
                        'source_type'   => 'woocommerce',
                        'source_id'     => '5',
                        'session_id'    => 's-' . $visitor,
                        'amount'        => '129.99',
                        'currency'      => 'USD',
                        'model_weights' => '{}',
                        'created_at'    => '2026-09-01 10:10:00',
                    ),
                );
            }

            return array();
        };
    }

    public function testMappingChainJoinsEmailOrdersAndVisitorBindings(): void {
        $this->seed_order( 'person@example.com', 'v-person-1', 5 );
        $this->seed_order( 'person@example.com', 'v-person-2', 6 );
        $this->seed_order( 'other@example.com', 'v-other', 7 );

        $ids = Gr_Privacy_Api::visitor_ids_for_email( 'person@example.com' );

        self::assertSame( array( 'v-person-1', 'v-person-2' ), $ids );
        self::assertSame( array(), Gr_Privacy_Api::visitor_ids_for_email( 'nobody@example.com' ) );
        self::assertSame( array(), Gr_Privacy_Api::visitor_ids_for_email( '' ) );
    }

    public function testMappingChainIncludesTheCapturedContactBinding(): void {
        // A form-lead with no order at all still owns their sessions:
        // the contact's own visitor binding feeds the chain.
        $GLOBALS['wpdb']->results = array(
            array(
                'id'         => '9',
                'visitor_id' => 'v-lead-1',
            ),
        );

        $ids = Gr_Privacy_Api::visitor_ids_for_email( 'lead@example.com' );

        self::assertSame( array( 'v-lead-1' ), $ids );

        // And a captured binding merges with the order bindings,
        // deduplicated.
        $this->seed_order( 'lead@example.com', 'v-lead-1', 5 );
        $this->seed_order( 'lead@example.com', 'v-order-1', 6 );
        self::assertSame(
            array( 'v-lead-1', 'v-order-1' ),
            Gr_Privacy_Api::visitor_ids_for_email( 'lead@example.com' )
        );
    }

    public function testExporterRegistryAddsFourFamilies(): void {
        $exporters = Gr_Privacy_Api::register_exporters( array( array( 'callback' => 'core' ) ) );

        self::assertCount( 6, $exporters );
        $names = array();
        foreach ( $exporters as $exporter ) {
            if ( isset( $exporter['callback'] ) && is_array( $exporter['callback'] ) ) {
                $names[] = (string) $exporter['callback'][1];
            }
        }
        self::assertContains( 'export_sessions', $names );
        self::assertContains( 'export_touchpoints', $names );
        self::assertContains( 'export_conversions', $names );
        self::assertContains( 'export_contact', $names );
        self::assertContains( 'export_cart_rows', $names );
    }

    public function testEraserRegistryAddsFiveFamilies(): void {
        $erasers = Gr_Privacy_Api::register_erasers( array() );

        self::assertCount( 6, $erasers );
        foreach ( $erasers as $eraser ) {
            self::assertArrayHasKey( 'eraser_friendly_name', $eraser );
            self::assertArrayHasKey( 'callback', $eraser );
        }
    }

    public function testExportSessionsPagesOneVisitorPerCall(): void {
        $this->seed_order( 'person@example.com', 'v-person-1', 5 );
        $this->seed_order( 'person@example.com', 'v-person-2', 6 );
        $this->stage_rows( 'v-person-1' );

        $page1 = Gr_Privacy_Api::export_sessions( 'person@example.com', 1 );
        self::assertFalse( $page1['done'] );
        self::assertCount( 1, $page1['data'] );
        $item = $page1['data'][0];
        self::assertStringStartsWith( Gr_Privacy_Api::EXPORTER_SESSIONS . '-', (string) $item['item_key'] );
        $fields = array();
        foreach ( $item['data'] as $pair ) {
            $fields[ (string) $pair['name'] ] = (string) $pair['value'];
        }
        self::assertSame( 'search', $fields['channel'] );
        self::assertSame( 'google', $fields['utm_source'] );
        self::assertSame( '2026-09-01 10:00:00', $fields['started_at'] );

        // Page 2 is the second visitor (no rows staged for it): done
        // only on the page after the last visitor.
        $page2 = Gr_Privacy_Api::export_sessions( 'person@example.com', 2 );
        self::assertFalse( $page2['done'] );
        self::assertSame( array(), $page2['data'] );

        $page3 = Gr_Privacy_Api::export_sessions( 'person@example.com', 3 );
        self::assertTrue( $page3['done'] );
    }

    public function testExportsWithoutOrdersAreHonestAndDone(): void {
        foreach ( array( 'export_sessions', 'export_touchpoints', 'export_conversions' ) as $method ) {
            $result = Gr_Privacy_Api::$method( 'nobody@example.com', 1 );
            self::assertSame( array(), $result['data'], $method );
            self::assertTrue( $result['done'], $method );
        }
    }

    public function testTouchpointAndConversionExportsCarryTheirColumns(): void {
        $this->seed_order( 'person@example.com', 'v-person-1', 5 );
        $this->stage_rows( 'v-person-1' );

        $touches = Gr_Privacy_Api::export_touchpoints( 'person@example.com', 1 );
        self::assertCount( 1, $touches['data'] );
        $touch_fields = array();
        foreach ( $touches['data'][0]['data'] as $pair ) {
            $touch_fields[ (string) $pair['name'] ] = (string) $pair['value'];
        }
        self::assertSame( 'gclid-1', $touch_fields['click_id'] );
        self::assertSame( 'https://shop.example/land', $touch_fields['landing_url'] );

        $convs = Gr_Privacy_Api::export_conversions( 'person@example.com', 1 );
        self::assertCount( 1, $convs['data'] );
        $conv_fields = array();
        foreach ( $convs['data'][0]['data'] as $pair ) {
            $conv_fields[ (string) $pair['name'] ] = (string) $pair['value'];
        }
        self::assertSame( 'woocommerce', $conv_fields['source_type'] );
        self::assertSame( '129.99', $conv_fields['amount'] );
        self::assertSame( 'USD', $conv_fields['currency'] );
    }

    public function testContactExportIsHonestWhenNoRowExists(): void {
        // An email that was never captured answers no data, not a
        // fabricated row — and the lookup hashes with the same
        // unprefixed sha-256 the form bridges capture under.
        $GLOBALS['wpdb']->results = array();

        $result = Gr_Privacy_Api::export_contact( 'person@example.com', 1 );

        self::assertSame( array(), $result['data'] );
        self::assertTrue( $result['done'] );

        $looked = '';
        foreach ( $GLOBALS['wpdb']->queries as $query ) {
            if ( false !== strpos( (string) $query, 'FROM wp_gr_contacts' ) ) {
                $looked = (string) $query;
            }
        }
        self::assertStringContainsString( "'" . hash( 'sha256', 'person@example.com' ) . "'", $looked );
    }

    public function testContactExportMapsARealRowWhenPresent(): void {
        $GLOBALS['wpdb']->results = array(
            array(
                'id'          => '9',
                'first_name'  => 'Ann',
                'last_name'   => 'Example',
                'lead_score'  => '42',
                'ltv'         => '199.50',
                'rfm_segment' => 'champions',
                'first_seen'  => '2026-08-01 00:00:00',
                'last_seen'   => '2026-09-01 00:00:00',
            ),
        );

        $result = Gr_Privacy_Api::export_contact( 'person@example.com', 1 );

        self::assertCount( 1, $result['data'] );
        $fields = array();
        foreach ( $result['data'][0]['data'] as $pair ) {
            $fields[ (string) $pair['name'] ] = (string) $pair['value'];
        }
        self::assertSame( 'Ann', $fields['First name'] );
        self::assertSame( 'champions', $fields['RFM segment'] );
        // The hash and the encrypted envelope never ride into an
        // export item.
        self::assertStringNotContainsString( 'email', implode( ' ', array_keys( $fields ) ) );
    }

    public function testEraseConversionsRemovesRowsAndKeepsTheBindingUntilTheFinalizer(): void {
        $order                         = $this->seed_order( 'person@example.com', 'v-person-1', 5 );
        $GLOBALS['wpdb']->query_result = 2;

        $result = Gr_Privacy_Api::erase_conversions( 'person@example.com' );

        self::assertTrue( $result['done'] );
        self::assertSame( 2, (int) $result['items_removed'] );
        // The binding survives: the contact eraser strips it last so
        // every other family can still find the visitor.
        self::assertSame( 'v-person-1', $order->get_meta( Gr_Woocommerce_Adapter::VISITOR_META ) );
        self::assertStringContainsString( 'rows removed', (string) $result['messages'][0] );
    }

    public function testEraseConversionsBeforeSessionsStillLetsSessionsErase(): void {
        // Live-stack regression: the core tool can run the families
        // out of order on retries. Stripping the order binding inside
        // the conversions eraser made the sessions eraser unable to
        // find the visitor at all — the binding removal belongs to
        // the last family, so this adversarial order must still work.
        $this->seed_order( 'person@example.com', 'v-person-1', 5 );
        $GLOBALS['wpdb']->query_result = 2;

        Gr_Privacy_Api::erase_conversions( 'person@example.com' );
        $GLOBALS['wpdb']->query_result = 3;
        $result = Gr_Privacy_Api::erase_sessions( 'person@example.com' );

        self::assertSame( 3, (int) $result['items_removed'] );
        $deleted = '';
        foreach ( $GLOBALS['wpdb']->queries as $query ) {
            if ( 0 === strpos( (string) $query, 'DELETE FROM wp_gr_sessions' ) ) {
                $deleted = (string) $query;
            }
        }
        self::assertStringContainsString( "'v-person-1'", $deleted );
    }

    public function testErasersWithoutOrdersRemoveNothing(): void {
        foreach ( array( 'erase_sessions', 'erase_touchpoints', 'erase_conversions', 'erase_funnel_journeys', 'erase_contact' ) as $method ) {
            $result = Gr_Privacy_Api::$method( 'nobody@example.com' );
            self::assertSame( 0, (int) $result['items_removed'], $method );
            self::assertTrue( $result['done'], $method );
        }
    }

    public function testEraseFunnelJourneysIssuesTheVisitorScopedDelete(): void {
        $this->seed_order( 'person@example.com', 'v-person-1', 5 );
        $GLOBALS['wpdb']->query_result = 2;

        $result = Gr_Privacy_Api::erase_funnel_journeys( 'person@example.com' );

        self::assertSame( 2, (int) $result['items_removed'] );
        $deleted = '';
        foreach ( $GLOBALS['wpdb']->queries as $query ) {
            if ( 0 === strpos( (string) $query, 'DELETE FROM wp_gr_funnel_sessions' ) ) {
                $deleted = (string) $query;
            }
        }
        self::assertStringContainsString( "'v-person-1'", $deleted );
        self::assertStringNotContainsString( 'wp_gr_funnels', $deleted, 'Definitions are the owner\'s configuration and never erased.' );
        self::assertStringContainsString( 'funnel journeys', (string) $result['messages'][0] );
    }

    public function testEraseSessionsIssuesTheVisitorScopedDelete(): void {
        $this->seed_order( 'person@example.com', 'v-person-1', 5 );
        $GLOBALS['wpdb']->query_result = 4;

        $result = Gr_Privacy_Api::erase_sessions( 'person@example.com' );

        self::assertSame( 4, (int) $result['items_removed'] );
        $deleted = '';
        foreach ( $GLOBALS['wpdb']->queries as $query ) {
            if ( 0 === strpos( (string) $query, 'DELETE FROM wp_gr_sessions' ) ) {
                $deleted = (string) $query;
            }
        }
        self::assertStringContainsString( "'v-person-1'", $deleted );
    }

    public function testContactEraserDeletesTheRowAndFinalizesTheOrderBindings(): void {
        $order                         = $this->seed_order( 'person@example.com', 'v-person-1', 5 );
        $GLOBALS['wpdb']->results      = array( array( 'id' => '9' ) );
        $GLOBALS['wpdb']->query_result = 1;

        $result = Gr_Privacy_Api::erase_contact( 'person@example.com' );

        // 1 tag-link sweep + 1 contact row + 1 order binding.
        self::assertSame( 3, (int) $result['items_removed'] );
        self::assertSame( '', $order->get_meta( Gr_Woocommerce_Adapter::VISITOR_META ) );
        self::assertStringContainsString( 'order bindings', (string) $result['messages'][0] );

        $deleted_links   = '';
        $deleted_contact = '';
        foreach ( $GLOBALS['wpdb']->queries as $query ) {
            if ( 0 === strpos( (string) $query, 'DELETE FROM wp_gr_contact_tags' ) ) {
                $deleted_links = (string) $query;
            }
            if ( 0 === strpos( (string) $query, 'DELETE FROM wp_gr_contacts' ) ) {
                $deleted_contact = (string) $query;
            }
        }
        // The links sweep first, scoped to the contact id.
        self::assertStringContainsString( 'contact_id', $deleted_links );
        // The row delete keys on the capture hash, the same one the
        // form bridges write — a drifted hash here would erase
        // nothing and report success.
        self::assertStringContainsString( 'email_hash', $deleted_contact );
        self::assertStringContainsString( "'" . hash( 'sha256', 'person@example.com' ) . "'", $deleted_contact );
    }

    public function testContactEraserFinalizesBindingsEvenWithoutACrmRow(): void {
        $order = $this->seed_order( 'person@example.com', 'v-person-1', 5 );
        $GLOBALS['wpdb']->results = array();

        $result = Gr_Privacy_Api::erase_contact( 'person@example.com' );

        // No captured contact, but the binding is real person data
        // and must still go.
        self::assertSame( 1, (int) $result['items_removed'] );
        self::assertSame( '', $order->get_meta( Gr_Woocommerce_Adapter::VISITOR_META ) );
    }

    public function testPolicyContentRegistersSuggestedText(): void {
        Gr_Privacy_Api::policy_content();

        $this->assertArrayHasKey( 'greenpng', $GLOBALS['gr_stub_privacy_policy'] );
        $text = (string) $GLOBALS['gr_stub_privacy_policy']['greenpng'];
        self::assertStringContainsString( 'anonymized IP addresses', $text );
        self::assertStringContainsString( 'legitimate-interest', $text );
        self::assertStringContainsString( 'client probe', $text );
        self::assertStringContainsString( 'no fingerprint data', $text );
        self::assertStringContainsString( 'export and erasure', $text );
        self::assertStringContainsString( 'cart recovery', $text );
        self::assertStringContainsString( 'unsubscribe', $text );
    }

    public function testCartRowsExporterCarriesTheSnapshotWithoutSecretMaterial(): void {
        $GLOBALS['wpdb']->results = array(
            array(
                'id'          => '31',
                'status'      => 'recovered',
                'cart_json'   => '[{"product_id":10,"quantity":2,"name":"Widget"}]',
                'total'       => '25.50',
                'currency'    => 'USD',
                'captured_at' => '2026-09-14 09:00:00',
                'abandoned_at' => '2026-09-14 09:20:00',
                'recovered_at' => '2026-09-14 10:05:00',
            ),
        );

        $result = Gr_Privacy_Api::export_cart_rows( 'person@example.com', 1 );

        self::assertTrue( $result['done'] );
        self::assertCount( 1, $result['data'] );
        $item = $result['data'][0];
        self::assertStringStartsWith( Gr_Privacy_Api::EXPORTER_CARTS . '-', (string) $item['item_key'] );

        $fields = array();
        foreach ( $item['data'] as $pair ) {
            $fields[ (string) $pair['name'] ] = (string) $pair['value'];
        }
        self::assertSame( 'recovered', $fields['Status'] );
        self::assertStringContainsString( 'Widget', $fields['Cart items'] );
        self::assertSame( 'USD 25.50', $fields['Total'] );

        // The lookup and the read never select the secret columns.
        foreach ( $GLOBALS['wpdb']->queries as $query ) {
            self::assertStringNotContainsString( 'email_enc', (string) $query );
        }

        // Later pages answer done immediately, and an empty email
        // honestly reports no data.
        self::assertSame( array(), Gr_Privacy_Api::export_cart_rows( 'person@example.com', 2 )['data'] );
        self::assertSame( array(), Gr_Privacy_Api::export_cart_rows( '', 1 )['data'] );
    }

    public function testCartRowsEraserDeletesByTheEmailHash(): void {
        $GLOBALS['wpdb']->results      = array();
        $GLOBALS['wpdb']->query_result = 2;

        $result = Gr_Privacy_Api::erase_cart_rows( 'person@example.com' );

        self::assertSame( 2, (int) $result['items_removed'] );
        self::assertTrue( $result['done'] );
        self::assertStringContainsString( 'cart recovery', (string) $result['messages'][0] );

        $deleted = '';
        foreach ( $GLOBALS['wpdb']->queries as $query ) {
            if ( 0 === strpos( (string) $query, 'DELETE FROM wp_gr_cart_abandonments' ) ) {
                $deleted = (string) $query;
            }
        }
        self::assertStringContainsString( "'" . hash( 'sha256', 'person@example.com' ) . "'", $deleted );

        // An empty email erases nothing and says so.
        $empty = Gr_Privacy_Api::erase_cart_rows( '' );
        self::assertSame( 0, (int) $empty['items_removed'] );
    }

    public function testPluginWiringRegistersThePrivacyFilters(): void {
        \GreenPNG\Core\Gr_Plugin::reset_instance();
        \GreenPNG\Core\Gr_Plugin::run();

        $hooks = array();
        foreach ( $GLOBALS['gr_stub_filters'] as $hook => $callbacks ) {
            foreach ( (array) $callbacks as $callback ) {
                $hooks[] = (string) $hook;
            }
        }

        self::assertContains( 'wp_privacy_personal_data_exporters', $hooks );
        self::assertContains( 'wp_privacy_personal_data_erasers', $hooks );

        \GreenPNG\Core\Gr_Plugin::reset_instance();
    }
}
