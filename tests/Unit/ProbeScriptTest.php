<?php
/**
 * Client probe (docs/13 C13): the enqueue gate and defer semantics on
 * the PHP side, plus the enforceable static contracts of the script
 * itself — size budget, sendBeacon-only transport, and the absence of
 * any storage or fingerprinting access.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Rest\Gr_Collect_Controller;
use GreenPNG\Rest\Gr_Probe_Script;
use PHPUnit\Framework\TestCase;

final class ProbeScriptTest extends TestCase {

    /** Project-relative script path. */
    private const SCRIPT = 'plugin/assets/js/gr-probe.js';

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    protected function tearDown(): void {
        // Assign, never unset: superglobals must stay defined for other
        // tests' isset() checks.
        $_COOKIE = array();
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

    public function testDefaultOnEnqueuesWithEndpointDataAndDefer(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();
        Gr_Probe_Script::enqueue();

        self::assertArrayHasKey( Gr_Probe_Script::HANDLE, $GLOBALS['gr_stub_enqueued_scripts'] );
        $script = $GLOBALS['gr_stub_enqueued_scripts'][ Gr_Probe_Script::HANDLE ];
        self::assertSame( GR_PLUGIN_URL . 'assets/js/gr-probe.js', $script['src'] );
        self::assertTrue( $script['footer'] );

        // The inline config lands before the file and carries the
        // collect endpoint data.
        $inline = null;
        foreach ( $GLOBALS['gr_stub_inline_scripts'] as $record ) {
            if ( Gr_Probe_Script::HANDLE === $record['handle'] && 'before' === $record['position'] ) {
                $inline = $record['text'];
            }
        }
        self::assertNotNull( $inline );
        self::assertStringContainsString( 'window.GreenPNGProbe=', $inline );
        self::assertStringContainsString( '"token"', $inline );

        // The plugin registered the enqueue hook.
        $hooks = array();
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            $hooks[] = (string) $registration['hook'];
        }
        self::assertContains( 'wp_enqueue_scripts', $hooks );
    }

    public function testSwitchedOffMeansZeroScriptOutput(): void {
        gr()->settings()->set( 'probe_enabled', 0 );

        Gr_Probe_Script::enqueue();

        self::assertArrayNotHasKey( Gr_Probe_Script::HANDLE, $GLOBALS['gr_stub_enqueued_scripts'] );
        self::assertSame( array(), $GLOBALS['gr_stub_inline_scripts'] );
    }

    public function testAdminScreensNeverSeeTheProbe(): void {
        $GLOBALS['gr_stub_is_admin'] = true;

        Gr_Probe_Script::enqueue();

        self::assertArrayNotHasKey( Gr_Probe_Script::HANDLE, $GLOBALS['gr_stub_enqueued_scripts'] );

        unset( $GLOBALS['gr_stub_is_admin'] );
    }

    public function testDeferAttributeAppliesToOurHandleOnly(): void {
        $tag = Gr_Probe_Script::add_defer_attribute(
            "<script src='https://stub.example/assets/js/gr-probe.js'></script>" . "\n",
            Gr_Probe_Script::HANDLE
        );
        self::assertStringContainsString( ' defer src=', $tag );

        // Other scripts pass through untouched.
        $other = Gr_Probe_Script::add_defer_attribute(
            "<script src='https://stub.example/other.js'></script>" . "\n",
            'some-other-handle'
        );
        self::assertStringNotContainsString( 'defer', $other );

        // Inline-printed extras without src stay untouched.
        $inline = Gr_Probe_Script::add_defer_attribute( '<script>console.log(1);</script>', Gr_Probe_Script::HANDLE );
        self::assertSame( '<script>console.log(1);</script>', $inline );
    }

    public function testScriptBudgetAndStaticContracts(): void {
        $source = $this->source();
        $this->assertNotSame( '', $source );

        // Raw size floor: the gzip budget cannot hold on a raw file
        // several times its size.
        self::assertLessThan( 8192, strlen( $source ) );

        // The gzip budget itself (docs/09 §1.1: <= 8KB).
        if ( function_exists( 'gzencode' ) ) {
            $gzipped = (string) gzencode( $source, 9 );
            self::assertLessThan( 8192, strlen( $gzipped ) );
        }

        // Transport contract: sendBeacon only.
        self::assertStringContainsString( 'sendBeacon', $source );
        self::assertStringNotContainsString( 'XMLHttpRequest', $source );
        self::assertStringNotContainsString( 'fetch(', $source );

        // No persistent identifiers, no storage, no fingerprint raw
        // strings leaving the client: the script must not touch any
        // client storage API.
        self::assertStringNotContainsString( 'localStorage', $source );
        self::assertStringNotContainsString( 'sessionStorage', $source );
        self::assertStringNotContainsString( 'document.cookie', $source );
        self::assertStringNotContainsString( 'indexedDB', $source );

        // Renderer strings are classified locally and never transmitted:
        // the only outbound payload keys are the conclusion set.
        self::assertStringContainsString( "'signal'", $source );
        self::assertStringContainsString( 'bot_score', $source );
    }

    public function testEndpointConfigShapeMatchesTheController(): void {
        // The inline data must be exactly the controller's contract:
        // URL plus daily token, nothing else riding along.
        $data = Gr_Collect_Controller::script_data();

        self::assertSame( array( 'url', 'token' ), array_keys( $data ) );
        self::assertNotSame( '', $data['token'] );
    }
}
