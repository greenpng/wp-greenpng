<?php
/**
 * Behavior module (ADR-0012): the delivery gates — the setting
 * defaults to off, the script only reaches consenting visitors, the
 * vocabulary joins the collect route only while on — plus the
 * enforceable static contracts of the client file itself: size
 * budget, sendBeacon-only transport, no storage beyond the consent
 * cookie read, and the locator clip.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Behavior\Gr_Behavior;
use GreenPNG\Core\Gr_Settings;
use GreenPNG\Rest\Gr_Collect_Controller;
use PHPUnit\Framework\TestCase;

final class BehaviorModuleTest extends TestCase {

    /** Project-relative script path. */
    private const SCRIPT = 'plugin/assets/js/gr-probe-behavior.js';

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['gr_stub_is_admin'] );
        parent::tearDown();
    }

    /**
     * The script file's raw contents.
     *
     * @return string
     */
    private function source(): string {
        $root = dirname( __DIR__, 2 );

        return (string) file_get_contents( $root . '/' . self::SCRIPT );
    }

    public function testBehaviorDefaultsToOff(): void {
        $this->assertSame( 0, Gr_Settings::defaults()['behavior_enabled'] );
    }

    public function testVocabularyAddsNothingWhileTheModuleIsOff(): void {
        $core = array( 'pageview' => 'web', 'signal' => 'probe' );

        $this->assertSame( $core, Gr_Behavior::vocabulary( $core ) );
    }

    public function testVocabularyAddsTheBatchAndTheFourNamesWhileOn(): void {
        ( new Gr_Settings() )->set( 'behavior_enabled', 1 );

        $merged = Gr_Behavior::vocabulary( array( 'pageview' => 'web', 'signal' => 'probe' ) );

        $this->assertSame(
            array(
                'pageview'     => 'web',
                'signal'       => 'probe',
                'behavior'     => 'behavior',
                'dwell'        => 'behavior',
                'scroll_depth' => 'behavior',
                'rage_click'   => 'behavior',
                'dead_click'   => 'behavior',
            ),
            $merged
        );
    }

    public function testEnqueueOutputsNothingByDefault(): void {
        Gr_Behavior::enqueue();

        $this->assertArrayNotHasKey( Gr_Behavior::HANDLE, $GLOBALS['gr_stub_enqueued_scripts'] );
        $this->assertSame( array(), $GLOBALS['gr_stub_inline_scripts'] );
    }

    public function testEnqueueOutputsTheFileWhenOnAndConsenting(): void {
        ( new Gr_Settings() )->set( 'behavior_enabled', 1 );
        $GLOBALS['gr_stub_consent']['marketing'] = true;

        Gr_Behavior::enqueue();

        $script = $GLOBALS['gr_stub_enqueued_scripts'][ Gr_Behavior::HANDLE ];
        $this->assertSame( GR_PLUGIN_URL . 'assets/js/gr-probe-behavior.js', $script['src'] );
        $this->assertTrue( $script['footer'] );

        $inline = '';
        foreach ( $GLOBALS['gr_stub_inline_scripts'] as $record ) {
            if ( Gr_Behavior::HANDLE === $record['handle'] && 'before' === $record['position'] ) {
                $inline = $record['text'];
            }
        }
        $this->assertStringContainsString( 'window.GreenPNGBehavior=', $inline );
        $this->assertStringContainsString( '"consent":true', $inline );
        $this->assertStringContainsString( '"token"', $inline );
    }

    public function testEnqueueHoldsBackWithoutConsent(): void {
        ( new Gr_Settings() )->set( 'behavior_enabled', 1 );

        // No marketing consent in the server's view: zero script
        // output, not a silent script.
        Gr_Behavior::enqueue();

        $this->assertArrayNotHasKey( Gr_Behavior::HANDLE, $GLOBALS['gr_stub_enqueued_scripts'] );
        $this->assertSame( array(), $GLOBALS['gr_stub_inline_scripts'] );
    }

    public function testEnqueueHoldsBackWhenTheSecurityProbeIsOff(): void {
        ( new Gr_Settings() )->set( 'behavior_enabled', 1 );
        ( new Gr_Settings() )->set( 'probe_enabled', 0 );
        $GLOBALS['gr_stub_consent']['marketing'] = true;

        Gr_Behavior::enqueue();

        $this->assertArrayNotHasKey( Gr_Behavior::HANDLE, $GLOBALS['gr_stub_enqueued_scripts'] );
    }

    public function testAdminScreensNeverSeeTheBehaviorFile(): void {
        ( new Gr_Settings() )->set( 'behavior_enabled', 1 );
        $GLOBALS['gr_stub_consent']['marketing'] = true;
        $GLOBALS['gr_stub_is_admin']             = true;

        Gr_Behavior::enqueue();

        $this->assertArrayNotHasKey( Gr_Behavior::HANDLE, $GLOBALS['gr_stub_enqueued_scripts'] );

        unset( $GLOBALS['gr_stub_is_admin'] );
    }

    public function testDeferAttributeAppliesToTheBehaviorHandleOnly(): void {
        $tag = Gr_Behavior::add_defer_attribute(
            "<script src='https://stub.example/assets/js/gr-probe-behavior.js'></script>" . "\n",
            Gr_Behavior::HANDLE
        );
        $this->assertStringContainsString( ' defer src=', $tag );

        $other = Gr_Behavior::add_defer_attribute(
            "<script src='https://stub.example/other.js'></script>" . "\n",
            'gr-probe'
        );
        $this->assertStringNotContainsString( 'defer', $other );

        $inline = Gr_Behavior::add_defer_attribute( '<script>console.log(1);</script>', Gr_Behavior::HANDLE );
        $this->assertSame( '<script>console.log(1);</script>', $inline );
    }

    public function testScriptDataCarriesTheCollectPairPlusTheConsentView(): void {
        $GLOBALS['gr_stub_consent']['marketing'] = true;

        $data = Gr_Behavior::script_data();

        $this->assertSame( array( 'url', 'token', 'consent' ), array_keys( $data ) );
        $this->assertSame( Gr_Collect_Controller::script_data()['url'], $data['url'] );
        $this->assertSame( Gr_Collect_Controller::token(), $data['token'] );
        $this->assertTrue( $data['consent'] );

        $GLOBALS['gr_stub_consent']['marketing'] = false;
        $this->assertFalse( Gr_Behavior::script_data()['consent'] );
    }

    public function testThePluginWiringExtendsTheCollectVocabulary(): void {
        ( new Gr_Settings() )->set( 'behavior_enabled', 1 );

        \GreenPNG\Core\Gr_Plugin::reset_instance();
        \GreenPNG\Core\Gr_Plugin::run();

        // The filter the plugin registered extends the map the
        // controller reads.
        $events = apply_filters( 'gr_collect_events', array( 'pageview' => 'web', 'signal' => 'probe' ) );
        $this->assertArrayHasKey( 'behavior', $events );
        $this->assertSame( 'behavior', $events['dwell'] );

        // Off again: the same wiring adds nothing.
        ( new Gr_Settings() )->set( 'behavior_enabled', 0 );
        $events = apply_filters( 'gr_collect_events', array( 'pageview' => 'web', 'signal' => 'probe' ) );
        $this->assertArrayNotHasKey( 'behavior', $events );
    }

    public function testScriptBudgetAndStaticContracts(): void {
        $source = $this->source();
        $this->assertNotSame( '', $source );

        // Size budget: its own file, its own budget (ADR-0012 D1) —
        // the security probe's pinned file stays untouched.
        self::assertLessThan( 8192, strlen( $source ) );
        self::assertStringNotContainsString( 'gr-probe.js', $source );
        if ( function_exists( 'gzencode' ) ) {
            $gzipped = (string) gzencode( $source, 9 );
            self::assertLessThan( 8192, strlen( $gzipped ) );
        }

        // Transport contract: sendBeacon only.
        self::assertStringContainsString( 'sendBeacon', $source );
        self::assertStringNotContainsString( 'XMLHttpRequest', $source );
        self::assertStringNotContainsString( 'fetch(', $source );

        // No persistent identifiers, no storage APIs: the consent
        // cookie read is the file's only storage access, and it is a
        // read — nothing ever writes a cookie.
        self::assertStringNotContainsString( 'localStorage', $source );
        self::assertStringNotContainsString( 'sessionStorage', $source );
        self::assertStringNotContainsString( 'indexedDB', $source );
        self::assertStringContainsString( 'wp_consent_marketing', $source );
        self::assertStringNotContainsString( 'document.cookie =', $source );

        // The vocabulary and the caps are structural: dwell buckets,
        // the milestone steps, the page cap, the locator clip.
        self::assertStringContainsString( "'180+'", $source );
        self::assertStringContainsString( 'Math.min( Math.max( 100 * bottom / height, 0 ), 100 )', $source );
        self::assertStringContainsString( 'MAX_EVENTS = 20', $source );
        self::assertStringContainsString( 'slice( 0, 64 )', $source );
    }
}
