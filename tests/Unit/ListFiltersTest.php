<?php
/**
 * Shared list controls (docs/12 G6): one parse/render/URL vocabulary
 * across the three admin tables — malformed dates never pass, the
 * search clips, the controls render their current values, and the
 * export URL mirrors exactly what the screen shows, nonce-gated for
 * a plain browser link.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Admin\Gr_List_Filters;
use PHPUnit\Framework\TestCase;

final class ListFiltersTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        $_GET = $_GET ?? array();
    }

    protected function tearDown(): void {
        $_GET = $_GET ?? array();
        unset( $_GET['from'], $_GET['to'], $_GET['s'] );
        parent::tearDown();
    }

    public function testParseKeepsShapedDatesAndClipsTheSearch(): void {
        $_GET['from'] = '2026-09-01';
        $_GET['to']   = '2026-09-30';
        $_GET['s']    = '  spring sale  ';

        $this->assertSame(
            array(
                'from' => '2026-09-01',
                'to'   => '2026-09-30',
                's'    => 'spring sale',
            ),
            Gr_List_Filters::parse()
        );
    }

    public function testParseDropsMalformedDatesAndClipsLongSearches(): void {
        $_GET['from'] = '09/01/2026';
        $_GET['to']   = "2026-09-30' OR '1'='1";
        $_GET['s']    = str_repeat( 'x', 100 );

        $parsed = Gr_List_Filters::parse();

        $this->assertSame( '', $parsed['from'] );
        $this->assertSame( '', $parsed['to'] );
        $this->assertSame( 64, strlen( $parsed['s'] ) );
    }

    public function testControlsRenderTheCurrentValues(): void {
        ob_start();
        Gr_List_Filters::controls(
            array(
                'from' => '2026-09-01',
                'to'   => '',
                's'    => 'spring sale',
            )
        );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'name="from" id="gr-filter-from" value="2026-09-01"', $html );
        $this->assertStringContainsString( 'name="to" id="gr-filter-to" value=""', $html );
        $this->assertStringContainsString( 'name="s" id="gr-filter-search" class="regular-text" value="spring sale"', $html );
    }

    public function testExportUrlMirrorsFiltersAndCarriesTheRestNonce(): void {
        $url = Gr_List_Filters::export_url(
            'sessions',
            array(
                'from' => '2026-09-01',
                'to'   => '',
                's'    => 'spring sale',
            )
        );

        $this->assertStringContainsString( '/wp-json/greenpng/v1/export/sessions', $url );
        $this->assertStringContainsString( '_wpnonce=', $url );
        $this->assertStringContainsString( 'from=2026-09-01', $url );
        $this->assertStringContainsString( 's=spring%20sale', $url );

        // Empty filters stay out of the URL.
        $this->assertStringNotContainsString( 'to=', $url );
    }

    public function testExportUrlCarriesDatasetExtrasForTheAuditTable(): void {
        $url = Gr_List_Filters::export_url(
            'audit',
            array(
                'from'        => '',
                'to'          => '',
                's'           => '',
                'object_type' => 'access_rule',
                'user_id'     => '7',
                'action'      => 'toggle',
            )
        );

        $this->assertStringContainsString( '/wp-json/greenpng/v1/export/audit', $url );
        $this->assertStringContainsString( 'object_type=access_rule', $url );
        $this->assertStringContainsString( 'user_id=7', $url );
        $this->assertStringContainsString( 'action=toggle', $url );
    }
}
