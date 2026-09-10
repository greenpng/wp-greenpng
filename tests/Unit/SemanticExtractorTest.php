<?php
/**
 * Semantic extraction and ecosystem detection tests (docs/03 §7).
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Integrations\Gr_Ecosystem_Detector;
use GreenPNG\Integrations\Gr_Semantic_Extractor;
use PHPUnit\Framework\TestCase;

/**
 * Fixture payload -> semantics, plus the active_plugins bridge report.
 *
 * @coversDefaultClass \GreenPNG\Integrations\Gr_Semantic_Extractor
 */
final class SemanticExtractorTest extends TestCase {

    /**
     * Resets the stub stores so option writes never leak across tests.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    /**
     * @covers ::extract
     */
    public function test_cf7_shape_yields_email_and_name(): void {
        // Classic CF7 posted-data shape.
        $out = Gr_Semantic_Extractor::extract(
            array(
                'your-name'  => 'Ada Lovelace',
                'your-email' => 'ADA@EXAMPLE.COM',
                'your-subject' => 'Quote',
                'your-message' => 'Please send a quote',
            )
        );

        $this->assertSame( 'ada@example.com', $out['email'] );
        $this->assertSame( 'Ada Lovelace', $out['full_name'] );
        $this->assertSame( 'Ada', $out['first_name'] );
        $this->assertSame( 'Lovelace', $out['last_name'] );
        $this->assertSame( 'your-email', $out['detected_keys']['email'] );
        $this->assertSame( 'your-name', $out['detected_keys']['full_name'] );
    }

    /**
     * @covers ::extract
     */
    public function test_fluentforms_nested_shape(): void {
        // Fluent Forms namespaced input names arrive as nested arrays.
        $out = Gr_Semantic_Extractor::extract(
            array(
                'names'     => array(
                    'first_name' => 'Grace',
                    'last_name'  => 'Hopper',
                ),
                'email'     => 'grace@navy.mil',
                'input_tagline' => 'Keep it simple',
            )
        );

        $this->assertSame( 'grace@navy.mil', $out['email'] );
        $this->assertSame( 'Grace', $out['first_name'] );
        $this->assertSame( 'Hopper', $out['last_name'] );
        $this->assertSame( 'Grace Hopper', $out['full_name'] );
        $this->assertSame( 'names.first_name', $out['detected_keys']['first_name'] );
        // First/last detected: full_name is derived, not detected.
        $this->assertArrayNotHasKey( 'full_name', $out['detected_keys'] );
        $this->assertSame( 'Keep it simple', $out['custom_fields']['input_tagline'] );
    }

    /**
     * @covers ::extract
     */
    public function test_amount_with_symbols_and_currency(): void {
        // "$12,500.00" under a calc-style key, currency 3 letters.
        $out = Gr_Semantic_Extractor::extract(
            array(
                'calc_total' => '$12,500.00',
                'currency'   => 'eur',
                'phone'      => '+1 (555) 010-2030',
            )
        );

        $this->assertSame( 12500.0, $out['amount'] );
        $this->assertSame( 'EUR', $out['currency'] );
        $this->assertSame( '+15550102030', $out['phone'] );
        $this->assertSame( 'calc_total', $out['detected_keys']['amount'] );
    }

    /**
     * @covers ::extract
     */
    public function test_email_adopted_without_key_hint_but_not_from_urls(): void {
        // No email key at all: the RFC-valid leaf is adopted (the
        // auto:email behavior for unlabeled inputs).
        $adopted = Gr_Semantic_Extractor::extract(
            array( 'client_mail' => 'estimator@client.net' )
        );
        $this->assertSame( 'estimator@client.net', $adopted['email'] );
        $this->assertSame( 'client_mail', $adopted['detected_keys']['email'] );

        // A bare RFC-valid email under a url/id-ish key is refused: the
        // key says the value is something else.
        $refused = Gr_Semantic_Extractor::extract(
            array( 'invoice_url' => 'billing@ex.com' )
        );
        $this->assertNull( $refused['email'] );
        $this->assertSame( 'billing@ex.com', $refused['custom_fields']['invoice_url'] );

        // Also refused: the value is not a bare email.
        $mixed = Gr_Semantic_Extractor::extract(
            array( 'note' => 'mail me at me@x.io tomorrow' )
        );
        $this->assertNull( $mixed['email'] );
        $this->assertSame( 'mail me at me@x.io tomorrow', $mixed['custom_fields']['note'] );
    }

    /**
     * @covers ::extract
     */
    public function test_object_with_get_data_and_depth_cap(): void {
        $order   = new class() {
            /**
             * Data exposed the WC CRUD way.
             *
             * @return array<string, mixed>
             */
            public function get_data(): array {
                return array(
                    'billing' => array(
                        'email' => 'buyer@shop.io',
                        'phone' => '555-867-5309',
                    ),
                    'total'  => '49.99',
                );
            }
        };
        $regular = Gr_Semantic_Extractor::extract( $order );
        $this->assertSame( 'buyer@shop.io', $regular['email'] );
        $this->assertSame( '5558675309', $regular['phone'] );
        $this->assertSame( 49.99, $regular['amount'] );

        // Beyond the depth cap nothing is read: arrays nested deeper
        // than eight levels contribute nothing.
        $deep    = array( 'email' => 'deep@x.io' );
        for ( $level = 9; $level >= 1; $level-- ) {
            $deep = array( 'l' . $level => $deep );
        }
        $capped = Gr_Semantic_Extractor::extract( $deep );
        $this->assertNull( $capped['email'] );

        // Eight levels of nesting is still read.
        $edge = array( 'email' => 'edge@x.io' );
        for ( $level = 7; $level >= 1; $level-- ) {
            $edge = array( 'l' . $level => $edge );
        }
        $read = Gr_Semantic_Extractor::extract( $edge );
        $this->assertSame( 'edge@x.io', $read['email'] );
        $this->assertSame( 'l1.l2.l3.l4.l5.l6.l7.email', $read['detected_keys']['email'] );
    }

    /**
     * @covers ::extract
     */
    public function test_empty_and_degenerate_payloads(): void {
        $empty = Gr_Semantic_Extractor::extract( array() );
        $this->assertNull( $empty['email'] );
        $this->assertSame( array(), $empty['detected_keys'] );
        $this->assertSame( array(), $empty['custom_fields'] );

        $this->assertSame(
            array( 'email', 'phone', 'first_name', 'last_name', 'full_name', 'amount', 'currency', 'detected_keys', 'custom_fields' ),
            array_keys( $empty )
        );

        $scalar = Gr_Semantic_Extractor::extract( 'just a string' );
        $this->assertNull( $scalar['email'] );

        $no_pii = Gr_Semantic_Extractor::extract( array( 'color' => 'green', 'qty' => '3' ) );
        $this->assertNull( $no_pii['email'] );
        $this->assertNull( $no_pii['amount'] );
        $this->assertSame( 'green', $no_pii['custom_fields']['color'] );
        // qty is not a total/price vocabulary: stays custom, not amount.
        $this->assertNull( $no_pii['amount'] );
    }

    /**
     * @covers ::extract
     */
    public function test_custom_fields_bounded_and_sanitized(): void {
        // The script field travels first so the bound's eviction of
        // later leaves is what gets exercised, not its own survival.
        $payload = array( 'field_script' => "<b>bold</b>\nline2" );
        for ( $i = 0; $i < 70; $i++ ) {
            $payload[ 'field_' . $i ] = 'v' . $i;
        }
        $out = Gr_Semantic_Extractor::extract( $payload );

        $this->assertCount( 64, $out['custom_fields'] );
        // sanitize_text_field: tags stripped, line break collapsed.
        $this->assertSame( 'bold line2', $out['custom_fields']['field_script'] );
        // The leaves beyond the bound are dropped, oldest kept.
        $this->assertArrayNotHasKey( 'field_69', $out['custom_fields'] );
        $this->assertSame( 'v0', $out['custom_fields']['field_0'] );
    }

    /**
     * @coversDefaultClass \GreenPNG\Integrations\Gr_Ecosystem_Detector
     * @covers \GreenPNG\Integrations\Gr_Ecosystem_Detector::detect
     */
    public function test_ecosystem_detector_reports_active_plugins(): void {
        $GLOBALS['gr_stub_options']['data']['active_plugins'] = array(
            'fluentform/fluentform.php',
            'woocommerce/woocommerce.php',
        );

        $report = Gr_Ecosystem_Detector::detect();

        $this->assertTrue( $report['fluentform']['active'] );
        $this->assertSame( 'fluentform/fluentform.php', $report['fluentform']['plugin'] );
        $this->assertTrue( $report['woocommerce']['active'] );
        $this->assertFalse( $report['cf7']['active'] );
        $this->assertSame( '', $report['cf7']['plugin'] );
        $this->assertFalse( $report['wpforms']['active'] );

        // The report always covers the whole catalog.
        $this->assertSame(
            array( 'woocommerce', 'fluentform', 'cf7', 'wpforms' ),
            array_keys( $report )
        );

        // wpforms-lite counts as wpforms.
        $GLOBALS['gr_stub_options']['data']['active_plugins'] = array( 'wpforms-lite/wpforms.php' );
        $lite = Gr_Ecosystem_Detector::detect();
        $this->assertTrue( $lite['wpforms']['active'] );
        $this->assertSame( 'wpforms-lite/wpforms.php', $lite['wpforms']['plugin'] );

        // Garbage option type degrades to an empty catalog read.
        $GLOBALS['gr_stub_options']['data']['active_plugins'] = 'nonsense';
        $degraded = Gr_Ecosystem_Detector::detect();
        $this->assertFalse( $degraded['fluentform']['active'] );
    }

    /**
     * @covers ::extract
     */
    public function test_first_detected_email_wins(): void {
        // Two email candidates: the first in traversal order wins and
        // the second becomes a custom field.
        $out = Gr_Semantic_Extractor::extract(
            array(
                'billing_email' => 'first@x.io',
                'shipping_email' => 'second@x.io',
            )
        );
        $this->assertSame( 'first@x.io', $out['email'] );
        $this->assertSame( 'second@x.io', $out['custom_fields']['shipping_email'] );
    }
}
