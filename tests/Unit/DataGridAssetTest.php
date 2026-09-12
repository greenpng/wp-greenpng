<?php
/**
 * gr-datagrid.js packaging (docs/13 U4, docs/06 §2.2): the JS-level
 * behavior (XSS battery, authenticated fetch, degradation) is proven
 * by tests/js/gr-datagrid-test.js under node; this test keeps the
 * file's safety contract inside the standard phpunit battery — the
 * component file exists and carries none of the HTML-string sinks
 * the prototype's version was built on.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DataGridAssetTest extends TestCase {

    public function testComponentFileShipsWithThePlugin(): void {
        $this->assertFileExists( GR_PLUGIN_DIR . 'assets/js/gr-datagrid.js' );
    }

    public function testSourceCarriesNoHtmlStringSinks(): void {
        $src = (string) file_get_contents( GR_PLUGIN_DIR . 'assets/js/gr-datagrid.js' );

        foreach ( array( 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write' ) as $sink ) {
            $this->assertStringNotContainsString( $sink, $src, "the datagrid must never assemble markup strings ({$sink})" );
        }
    }

    public function testSourceShowsTheFixObligations(): void {
        $src = (string) file_get_contents( GR_PLUGIN_DIR . 'assets/js/gr-datagrid.js' );

        // Fix 1: authenticated fetch, not inline arrays.
        $this->assertStringContainsString( 'X-WP-Nonce', $src );
        $this->assertStringContainsString( 'fetch', $src );

        // Fix 2: cells render through DOM text nodes.
        $this->assertStringContainsString( 'textContent', $src );

        // The component exports under the plugin namespace.
        $this->assertStringContainsString( 'GrDataGrid', $src );
    }

    public function testNodeHarnessShipsBesideIt(): void {
        // The behavioral battery lives in the node harness; keeping
        // it in the repo is part of the deliverable, not a dev extra.
        $this->assertFileExists( dirname( __DIR__, 2 ) . '/tests/js/gr-datagrid-test.js' );
    }
}
