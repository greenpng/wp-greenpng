<?php
/**
 * Payload inspection ruleset (docs/13 W9, docs/10 §4): the two
 * high-confidence families, the false-positive battery the reference
 * project's 30-regex WAF failed, the bounded scan, the front-door GET
 * scope, and the detector's list-shaped findings on the frame.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Security\Gr_Payload_Inspector;
use GreenPNG\Security\Gr_Request_Inspector;
use PHPUnit\Framework\TestCase;

final class PayloadInspectorTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    protected function tearDown(): void {
        $_GET = array();
        parent::tearDown();
    }

    /**
     * The check closure the front door runs, after registration.
     *
     * @return callable
     */
    private function payload_check() {
        Gr_Payload_Inspector::register_detector();
        $checks = apply_filters( Gr_Request_Inspector::CHECKS_FILTER, array() );
        $this->assertArrayHasKey( 'payload_rules', $checks );

        return $checks['payload_rules'];
    }

    public function testUnionInjectionShapesAllHit(): void {
        $payloads = array(
            '1 UNION SELECT * FROM wp_users',
            "1' UNION ALL SELECT user_login--",
            '1 union/**/select column_name',
            '1+UNION+ALL+SELECT+2,3',
            'UnIoN   SeLeCt version()',
        );

        foreach ( $payloads as $payload ) {
            $findings = Gr_Payload_Inspector::inspect( array( 'cat' => $payload ) );

            $this->assertCount( 1, $findings, "payload should hit: {$payload}" );
            $this->assertSame( Gr_Payload_Inspector::RULE_SQLI, $findings[0]['rule_id'] );
            $this->assertStringStartsWith( 'cat:', $findings[0]['reason'] );

            // The finding shape is marker-only: no action directive a
            // consumer could read as escalation (docs/10 §4 tier 1).
            $this->assertSame( array( 'rule_id', 'reason' ), array_keys( $findings[0] ) );
        }
    }

    public function testTraversalChainsAllHit(): void {
        $payloads = array(
            '../../etc/passwd',
            '../../../../var/log',
            '..%2f..%2f..%2fwp-includes',
            '%2e%2e%2f%2e%2e%2fetc%2fpasswd',
            '..\\..\\wp-includes',
        );

        foreach ( $payloads as $payload ) {
            $findings = Gr_Payload_Inspector::inspect( array( 'template' => $payload ) );

            $this->assertCount( 1, $findings, "payload should hit: {$payload}" );
            $this->assertSame( Gr_Payload_Inspector::RULE_LFI, $findings[0]['rule_id'] );
            $this->assertStringStartsWith( 'template:', $findings[0]['reason'] );
        }
    }

    public function testFalsePositiveBatteryNeverHits(): void {
        $benign = array(
            'how do I edit wp-config.php safely',
            'document.cookie is readable via JS',
            'eval( $callback ) is dangerous',
            '<?php echo "hi"; ?>',
            '0xFF 0x41 0b1010 hex values',
            "1' or '1'='1",
            'trade unions selected their delegates',
            'go up ../ one level then select a file',
            'REUNION SELECTED works too',
            'the union of two tables, then select columns',
            'SELECT * FROM wp_posts',
            'sleep(5) and benchmark(9,md5(1)) timing',
            'a tutorial about UNION types in SQL joins tables',
        );

        foreach ( $benign as $value ) {
            $this->assertSame(
                array(),
                Gr_Payload_Inspector::inspect( array( 'any' => $value ) ),
                "benign sample must not hit: {$value}"
            );
        }
    }

    public function testSingleHopIsNotTraversal(): void {
        $values = array( '../wp-content', './file.txt', '..', '..%2f' );

        foreach ( $values as $value ) {
            $this->assertSame( array(), Gr_Payload_Inspector::inspect( array( 'p' => $value ) ) );
        }
    }

    public function testNonScalarEmptyAndPlainValuesAreSkipped(): void {
        $data = array(
            'a' => array( '1 union select' ),
            'b' => null,
            'c' => '',
            'd' => 42,
            'e' => 3.14,
        );

        $this->assertSame( array(), Gr_Payload_Inspector::inspect( $data ) );
    }

    public function testNumericParamNameLandsInReason(): void {
        $findings = Gr_Payload_Inspector::inspect( array( 0 => '1 union select' ) );

        $this->assertCount( 1, $findings );
        $this->assertSame( '0:union select', $findings[0]['reason'] );
    }

    public function testReasonIsSanitizedAndCapped(): void {
        // The parameter name is attacker-chosen markup: the joined
        // reason must arrive stripped.
        $findings = Gr_Payload_Inspector::inspect( array( '<b>x</b>' => '1 UNION SELECT' ) );
        $this->assertCount( 1, $findings );
        $this->assertStringNotContainsString( '<', $findings[0]['reason'] );

        // A separator-stuffed span longer than the token cap still
        // yields a bounded reason.
        $long  = 'union' . str_repeat( ' +', 100 ) . ' select';
        $rows  = Gr_Payload_Inspector::inspect( array( 'cat' => $long ) );
        $this->assertCount( 1, $rows );
        $this->assertLessThanOrEqual( 120, strlen( $rows[0]['reason'] ) );
        $this->assertStringStartsWith( 'cat:union', $rows[0]['reason'] );
    }

    public function testFirstRuleFamilyPerParamWins(): void {
        $findings = Gr_Payload_Inspector::inspect( array( 'cat' => '1 UNION SELECT ../../..' ) );

        $this->assertCount( 1, $findings );
        $this->assertSame( Gr_Payload_Inspector::RULE_SQLI, $findings[0]['rule_id'] );
    }

    public function testMultipleParamsReportPerParam(): void {
        $findings = Gr_Payload_Inspector::inspect(
            array(
                'cat'      => '1 union select',
                'template' => '../../etc/passwd',
                'ok'       => 'hello world',
            )
        );

        $this->assertCount( 2, $findings );
        $this->assertSame( Gr_Payload_Inspector::RULE_SQLI, $findings[0]['rule_id'] );
        $this->assertSame( Gr_Payload_Inspector::RULE_LFI, $findings[1]['rule_id'] );
    }

    public function testValueScanIsBounded(): void {
        // The match lies beyond the scanned cap: a bounded engine
        // misses it by design, the same trade every budget makes.
        $value = str_repeat( 'a', 5000 ) . ' UNION SELECT';

        $this->assertSame( array(), Gr_Payload_Inspector::inspect( array( 'cat' => $value ) ) );
    }

    public function testFacadeMatchesTheEngineVerdict(): void {
        $data = array( 'cat' => '../../etc/passwd' );

        $this->assertSame(
            Gr_Payload_Inspector::inspect( $data ),
            gr_inspect_request_payload( $data )
        );
    }

    public function testDetectorScansCurrentGetParams(): void {
        $check = $this->payload_check();

        $_GET = array(
            'cat' => '1 UNION SELECT',
            'p'   => '1',
        );

        $findings = $check();
        $this->assertCount( 1, $findings );
        $this->assertSame( Gr_Payload_Inspector::RULE_SQLI, $findings[0]['rule_id'] );

        // Through the whole frame, with no UA finding in the way: the
        // payload finding lands exactly like the scanner detector's.
        $framed = ( new Gr_Request_Inspector( gr()->settings() ) )->inspect();
        $this->assertCount( 1, $framed );
        $this->assertSame( Gr_Payload_Inspector::RULE_SQLI, $framed[0]['rule_id'] );
        $this->assertStringStartsWith( 'cat:', $framed[0]['reason'] );
    }

    public function testDetectorSkipsSearchAndMarketingNames(): void {
        $check = $this->payload_check();

        $_GET = array(
            's'           => '1 union select',
            'utm_content' => '../../..',
            'gclid'       => '1 UNION ALL SELECT',
            'fbclid'      => '../../',
            'msclkid'     => '1 union select',
            'mc_cid'      => '../../',
            'mc_eid'      => '../../',
            'cat'         => 'ok',
        );

        $this->assertSame( array(), $check() );
    }

    public function testDetectorSkipIsCaseInsensitive(): void {
        $check = $this->payload_check();

        $_GET = array(
            'S'           => '1 union select',
            'UTM_Content' => '../../..',
        );

        $this->assertSame( array(), $check() );
    }

    public function testDetectorUnslashesBeforeMatching(): void {
        $check = $this->payload_check();

        // wp_magic_quotes doubles the backslashes: '..\\..\\' does not
        // match the traversal hop, the unslashed '..\..\' does — the
        // unslash at the point of read is load-bearing, not ceremony.
        $_GET = array( 'x' => addslashes( '..\\..\\wp-includes' ) );

        $findings = $check();
        $this->assertCount( 1, $findings );
        $this->assertSame( Gr_Payload_Inspector::RULE_LFI, $findings[0]['rule_id'] );
    }

    public function testDetectorIgnoresNonScalarGetValues(): void {
        $check = $this->payload_check();

        $_GET = array( 'arr' => array( '1 union select' ) );

        $this->assertSame( array(), $check() );
    }

    public function testPluginRegistersThePayloadDetector(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        $checks = apply_filters( Gr_Request_Inspector::CHECKS_FILTER, array() );

        $this->assertArrayHasKey( 'payload_rules', $checks );
        $this->assertIsCallable( $checks['payload_rules'] );
    }
}
