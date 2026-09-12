<?php
/**
 * URL Builder page (docs/13 U9, docs/06 §1): the pure build()
 * contract — trio required, http(s) landing only, optional params
 * omitted when empty — the form surface, and the acceptance rows:
 * zero outbound calls (a source-level machine assertion) and the
 * result printed through esc_url.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Admin\Gr_Url_Builder_Page;
use GreenPNG\Core\Gr_Plugin;
use PHPUnit\Framework\TestCase;

final class UrlBuilderPageTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        unset( $_GET );
    }

    protected function tearDown(): void {
        unset( $_GET );
        parent::tearDown();
    }

    public function testBuildComposesTheFullLink(): void {
        $built = Gr_Url_Builder_Page::build(
            'https://example.test/landing',
            'google',
            'cpc',
            'spring_sale',
            'running shoes',
            'banner-a'
        );

        $this->assertSame( '', $built['error'] );
        $this->assertStringContainsString( 'https://example.test/landing', $built['url'] );
        $this->assertStringContainsString( 'utm_source=google', $built['url'] );
        $this->assertStringContainsString( 'utm_medium=cpc', $built['url'] );
        $this->assertStringContainsString( 'utm_campaign=spring_sale', $built['url'] );
        // Values are URL-encoded here, never raw spaces.
        $this->assertStringContainsString( 'utm_term=running%20shoes', $built['url'] );
        $this->assertStringContainsString( 'utm_content=banner-a', $built['url'] );
    }

    public function testBuildPreservesExistingQuery(): void {
        $built = Gr_Url_Builder_Page::build( 'https://example.test/?p=42', 'google', 'cpc', 'spring_sale' );

        $this->assertSame( '', $built['error'] );
        $this->assertStringContainsString( 'p=42', $built['url'] );
        $this->assertStringContainsString( 'utm_campaign=spring_sale', $built['url'] );
    }

    public function testBuildOmitsEmptyOptionalParameters(): void {
        $built = Gr_Url_Builder_Page::build( 'https://example.test/', 'google', 'cpc', 'spring_sale', '', '' );

        $this->assertSame( '', $built['error'] );
        $this->assertStringNotContainsString( 'utm_term', $built['url'] );
        $this->assertStringNotContainsString( 'utm_content', $built['url'] );
    }

    public function testBuildRequiresTheTrio(): void {
        $built = Gr_Url_Builder_Page::build( 'https://example.test/', '', 'cpc', 'spring_sale' );
        $this->assertSame( 'trio', $built['error'] );
        $this->assertSame( '', $built['url'] );

        $built = Gr_Url_Builder_Page::build( 'https://example.test/', 'google', '', 'spring_sale' );
        $this->assertSame( 'trio', $built['error'] );

        $built = Gr_Url_Builder_Page::build( 'https://example.test/', 'google', 'cpc', '' );
        $this->assertSame( 'trio', $built['error'] );
    }

    public function testBuildRefusesNonHttpLandings(): void {
        foreach ( array( 'javascript:alert(1)', 'data:text/html,hi', 'ftp://example.test/x', '//example.test/x', 'not a url' ) as $landing ) {
            $built = Gr_Url_Builder_Page::build( $landing, 'google', 'cpc', 'spring_sale' );
            $this->assertSame( 'landing', $built['error'], "landing refused: {$landing}" );
            $this->assertSame( '', $built['url'] );
        }
    }

    public function testRenderShowsEmptyFormWithoutInput(): void {
        ob_start();
        Gr_Url_Builder_Page::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'form-table', $html );
        $this->assertStringContainsString( 'method="get"', $html );
        $this->assertStringContainsString( 'name="page" value="greenpng-url"', $html );
        $this->assertStringContainsString( 'value="Build link"', $html );
        // No result panel before the form is used.
        $this->assertStringNotContainsString( 'utm_campaign=', $html );
        $this->assertStringNotContainsString( 'notice-error', $html );
    }

    public function testRenderEchoesBuiltUrlThroughEscUrl(): void {
        $_GET = array(
            'gr_url' => array(
                'landing'      => 'https://example.test/landing',
                'utm_source'   => 'google',
                'utm_medium'   => 'cpc',
                'utm_campaign' => 'spring_sale',
            ),
        );

        ob_start();
        Gr_Url_Builder_Page::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'utm_campaign=spring_sale', $html );
        // The copy-ready input carries the same composed value.
        $this->assertStringContainsString( 'value="https://example.test/landing', $html );
        // The form re-echoes what the admin typed.
        $this->assertStringContainsString( 'value="spring_sale"', $html );
    }

    public function testRenderShowsTrioNoticeNotALink(): void {
        $_GET = array(
            'gr_url' => array(
                'landing'      => 'https://example.test/landing',
                'utm_source'   => 'google',
                'utm_medium'   => '',
                'utm_campaign' => 'spring_sale',
            ),
        );

        ob_start();
        Gr_Url_Builder_Page::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'notice-error', $html );
        $this->assertStringContainsString( 'source, medium, and campaign name', $html );
        $this->assertStringNotContainsString( 'utm_campaign=spring_sale', $html );
    }

    public function testPageSourceCarriesNoOutboundCallSurface(): void {
        // The acceptance row as a machine assertion: the builder is
        // local string composition only.
        $source = (string) file_get_contents( GR_PLUGIN_DIR . 'includes/admin/class-gr-url-builder-page.php' );

        $this->assertStringNotContainsString( 'wp_remote_', $source );
        $this->assertStringNotContainsString( 'curl_', $source );
        $this->assertStringNotContainsString( 'fsockopen', $source );
        $this->assertStringNotContainsString( 'dns_get_record', $source );
    }

    public function testMenuRegistersUrlBuilderUnderTheTopLevel(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        do_action( 'admin_menu' );

        $slugs = array();
        foreach ( $GLOBALS['gr_stub_submenu_pages'] as $page ) {
            $slugs[] = $page['parent_slug'] . ':' . $page['menu_slug'];
        }
        $this->assertContains( 'greenpng-dashboard:greenpng-url', $slugs );
    }
}
