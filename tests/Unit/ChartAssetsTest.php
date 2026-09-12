<?php
/**
 * Chart library packaging (docs/13 U2, docs/06 §2.3): the vendored
 * uPlot files exist with their source and license beside the wire
 * build, the gzip size honors the lightweight budget, the handles
 * only ever REGISTER on admin screens, and enqueue is a page's
 * explicit decision — no chart-less page loads the library.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Admin\Gr_Chart_Assets;
use GreenPNG\Core\Gr_Plugin;
use PHPUnit\Framework\TestCase;

final class ChartAssetsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    public function testVendoredBuildIsPackagedWithSourceAndLicense(): void {
        $min     = GR_PLUGIN_DIR . 'assets/js/vendor/uplot.iife.min.js';
        $source  = GR_PLUGIN_DIR . 'assets/js/vendor/uplot.iife.js';
        $license = GR_PLUGIN_DIR . 'assets/js/vendor/uplot-license.txt';
        $css     = GR_PLUGIN_DIR . 'assets/css/uplot.min.css';

        $this->assertFileExists( $min );
        $this->assertFileExists( $source );
        $this->assertFileExists( $license );
        $this->assertFileExists( $css );

        // The wire build carries the version banner for traceability.
        $this->assertStringContainsString( 'uPlot', (string) file_get_contents( $min ) );

        // The MIT license travels with the code, not in a distant doc.
        $this->assertStringContainsString( 'MIT License', (string) file_get_contents( $license ) );
    }

    public function testMinifiedBuildHonorsTheSizeBudget(): void {
        $raw = (string) file_get_contents( GR_PLUGIN_DIR . 'assets/js/vendor/uplot.iife.min.js' );

        // The docs/06 §2.3 budget ("uPlot 级, ≤20KB") is a wire-size
        // claim, so the gzipped payload is what counts; 20KB read
        // strictly as 20,000 bytes.
        $gz = (int) strlen( (string) gzencode( $raw ) );

        $this->assertLessThan( 20000, $gz, "gzipped uPlot build exceeds the chart budget: {$gz} bytes" );
    }

    public function testRegistrationNeverEnqueues(): void {
        Gr_Chart_Assets::register();

        $this->assertArrayHasKey( Gr_Chart_Assets::HANDLE, $GLOBALS['gr_stub_registered_scripts'] );
        $this->assertArrayHasKey( Gr_Chart_Assets::STYLE_HANDLE, $GLOBALS['gr_stub_registered_styles'] );
        $this->assertArrayNotHasKey( Gr_Chart_Assets::HANDLE, $GLOBALS['gr_stub_enqueued_scripts'] );
        $this->assertArrayNotHasKey( Gr_Chart_Assets::STYLE_HANDLE, $GLOBALS['gr_stub_enqueued_styles'] );
    }

    public function testRegisteredUrlsAreLocalNotCdn(): void {
        Gr_Chart_Assets::register();

        $js  = (string) $GLOBALS['gr_stub_registered_scripts'][ Gr_Chart_Assets::HANDLE ]['src'];
        $css = (string) $GLOBALS['gr_stub_registered_styles'][ Gr_Chart_Assets::STYLE_HANDLE ]['src'];

        // Zero CDN (iron rule 5): every asset URL is the plugin's own
        // directory, and the referenced files actually exist on disk.
        $this->assertStringStartsWith( GR_PLUGIN_URL, $js );
        $this->assertStringStartsWith( GR_PLUGIN_URL, $css );
        $this->assertFileExists( GR_PLUGIN_DIR . 'assets/js/vendor/uplot.iife.min.js' );
        $this->assertFileExists( GR_PLUGIN_DIR . 'assets/css/uplot.min.css' );
    }

    public function testEnqueueIsThePagesExplicitDecision(): void {
        Gr_Chart_Assets::register();
        Gr_Chart_Assets::enqueue();

        $this->assertArrayHasKey( Gr_Chart_Assets::HANDLE, $GLOBALS['gr_stub_enqueued_scripts'] );
        $this->assertArrayHasKey( Gr_Chart_Assets::STYLE_HANDLE, $GLOBALS['gr_stub_enqueued_styles'] );

        // The handle-only enqueue resolved through the registry, so the
        // wire URL is the registered local one.
        $this->assertSame(
            $GLOBALS['gr_stub_registered_scripts'][ Gr_Chart_Assets::HANDLE ]['src'],
            $GLOBALS['gr_stub_enqueued_scripts'][ Gr_Chart_Assets::HANDLE ]['src']
        );
    }

    public function testPluginRegistersTheLibraryOnAdminEnqueue(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        $found = false;
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            if ( 'admin_enqueue_scripts' === (string) $registration['hook']
                && array( Gr_Chart_Assets::class, 'register' ) === $registration['callback'] ) {
                $found = true;
            }
        }

        $this->assertTrue( $found, 'the library must be registered where core expects admin assets' );
    }
}
