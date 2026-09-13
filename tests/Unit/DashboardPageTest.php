<?php
/**
 * Dashboard page and menu (docs/13 U3): the top-level menu with the
 * right capability, page-scoped asset loading (chart library only on
 * the dashboard screen), and the rendered markup — KPI values from
 * the summary table, chart mount, screen-reader-text table, country
 * distribution, all escaped and all copy localized.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Admin\Gr_Admin_Menu;
use GreenPNG\Admin\Gr_Chart_Assets;
use GreenPNG\Admin\Gr_Dashboard_Page;
use GreenPNG\Core\Gr_Plugin;
use PHPUnit\Framework\TestCase;

final class DashboardPageTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    /**
     * Renders the page with canned summary reads and returns the HTML.
     *
     * @return string
     */
    private function render_page(): string {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'sessions_by_country' ) ) {
                return array(
                    array( 'metric_key' => 'US', 'total' => '9' ),
                    array( 'metric_key' => '', 'total' => '4' ),
                );
            }

            if ( false !== strpos( $sql, 'gr_sessions' ) ) {
                return array(
                    array( 'device_type' => 'desktop', 'sessions' => '6', 'bots' => '1' ),
                    array( 'device_type' => 'mobile', 'sessions' => '4', 'bots' => '0' ),
                );
            }

            return array(
                array( 'stat_date' => '2026-09-10', 'metric_type' => 'sessions', 'metric_value' => '7.0000' ),
                array( 'stat_date' => '2026-09-10', 'metric_type' => 'visitors', 'metric_value' => '3.0000' ),
                array( 'stat_date' => '2026-09-10', 'metric_type' => 'pageviews', 'metric_value' => '11.0000' ),
                array( 'stat_date' => '2026-09-10', 'metric_type' => 'conversions', 'metric_value' => '2.0000' ),
                array( 'stat_date' => '2026-09-10', 'metric_type' => 'revenue', 'metric_value' => '129.9000' ),
            );
        };

        ob_start();
        Gr_Dashboard_Page::render();

        return (string) ob_get_clean();
    }

    public function testMenuRegistersTopLevelEntryWithOwnerCapability(): void {
        Gr_Admin_Menu::register();

        $top = $GLOBALS['gr_stub_admin_pages'][0];
        $this->assertSame( 'manage_options', $top['capability'] );
        $this->assertSame( Gr_Admin_Menu::SLUG, $top['menu_slug'] );
        $this->assertSame( 'dashicons-chart-area', $top['icon_url'] );
        $this->assertSame( 30, $top['position'] );

        // Settings is the first submenu entry (docs/13 U13); the
        // dashboard mirror follows right behind it.
        $sub = $GLOBALS['gr_stub_submenu_pages'][1];
        $this->assertSame( 'manage_options', $sub['capability'] );
        $this->assertSame( Gr_Admin_Menu::SLUG, $sub['menu_slug'] );
        $this->assertSame( array( Gr_Dashboard_Page::class, 'render' ), $sub['callback'] );
    }

    public function testAssetsLoadOnlyOnTheDashboardScreen(): void {
        Gr_Admin_Menu::register();

        // The real suffix add_menu_page() returned, captured by the
        // menu class itself — no hand-built hook strings.
        $hook = 'toplevel_page_' . Gr_Admin_Menu::SLUG;

        Gr_Admin_Menu::enqueue_assets( 'some_other_plugin_page' );
        $this->assertArrayNotHasKey( Gr_Chart_Assets::HANDLE, $GLOBALS['gr_stub_enqueued_scripts'] );
        $this->assertArrayNotHasKey( 'gr-dashboard', $GLOBALS['gr_stub_enqueued_scripts'] );

        Gr_Admin_Menu::enqueue_assets( $hook );
        $this->assertArrayHasKey( Gr_Chart_Assets::HANDLE, $GLOBALS['gr_stub_enqueued_scripts'] );
        $this->assertArrayHasKey( 'gr-dashboard', $GLOBALS['gr_stub_enqueued_scripts'] );

        // The dashboard script depends on the chart library and gets
        // its localized config injected before the file.
        $this->assertSame(
            array( Gr_Chart_Assets::HANDLE ),
            $GLOBALS['gr_stub_enqueued_scripts']['gr-dashboard']['deps'] ?? null
        );
        $inline = '';
        foreach ( $GLOBALS['gr_stub_inline_scripts'] as $entry ) {
            if ( 'gr-dashboard' === $entry['handle'] ) {
                $inline = $entry['text'];
            }
        }
        $this->assertStringContainsString( 'window.GreenPNGDashboard=', $inline );
        $this->assertStringContainsString( '"nonce":"gr-stub-nonce-', $inline );

        // The panels config rides the same inline script, merged
        // beside the chart config without key collisions; the URL
        // rides in json_encode form, so compare against the same
        // encoding instead of a hand-written literal.
        $this->assertStringContainsString( (string) wp_json_encode( 'https://stub.example/wp-json/greenpng/v1/panels' ), $inline );
        $this->assertStringContainsString( '"panelPollMs":30000', $inline );
    }

    public function testRenderCarriesEverySurfaceWithRealNumbers(): void {
        $html = $this->render_page();

        // Native page furniture.
        $this->assertStringContainsString( '<div class="wrap">', $html );
        $this->assertStringContainsString( 'wp-header-end', $html );

        // KPI strip: postboxes with the summary-table values.
        $this->assertStringContainsString( 'postbox', $html );
        $this->assertStringContainsString( '>7</span>', $html );   // sessions today.
        $this->assertStringContainsString( '>3</span>', $html );   // visitors today.
        $this->assertStringContainsString( '>11</span>', $html );  // page views today.
        $this->assertStringContainsString( '>129.90</span>', $html ); // revenue today.

        // Trend chart mount plus its screen-reader-text equivalent.
        $this->assertStringContainsString( 'id="gr-dashboard-trend"', $html );
        $this->assertStringContainsString( 'screen-reader-text', $html );
        $this->assertStringContainsString( '<th scope="row">2026-09-10</th>', $html );

        // Country distribution table with the unknown bucket labeled.
        $this->assertStringContainsString( 'widefat striped', $html );
        $this->assertStringContainsString( '<td>US</td>', $html );
        $this->assertStringContainsString( '>9</td>', $html );
        $this->assertStringContainsString( '<td>Unknown</td>', $html );

        // The dense frame: every window day renders its own table row.
        $this->assertSame( 14, substr_count( $html, '<th scope="row">' ) );
    }

    public function testRenderCarriesTheLivePanelsWithServerValues(): void {
        $GLOBALS['wpdb']->var_result = '2';
        $html = $this->render_page();

        // Every panel value renders server-side first, so the page is
        // complete with JavaScript off; the mounts exist for refresh.
        $this->assertStringContainsString( 'id="gr-panel-online"', $html );
        $this->assertStringContainsString( 'id="gr-panel-sessions-today"', $html );
        $this->assertStringContainsString( 'id="gr-panel-bots-today"', $html );
        $this->assertStringContainsString( 'id="gr-panel-devices"', $html );
        $this->assertStringContainsString( 'Sessions today (live)', $html );

        // Online from the indexed count, the split from the canned
        // aggregate: 6+4 sessions, 1 suspected bot.
        $this->assertStringContainsString( '<span class="gr-kpi-value" id="gr-panel-online">2</span>', $html );
        $this->assertStringContainsString( '<span class="gr-kpi-value" id="gr-panel-sessions-today">10</span>', $html );
        $this->assertStringContainsString( '<span class="gr-kpi-value" id="gr-panel-bots-today">1</span>', $html );

        // Device codes render through the shared label vocabulary.
        $this->assertStringContainsString( '<td>Desktop</td>', $html );
        $this->assertStringContainsString( '<td>Mobile</td>', $html );
        $this->assertStringContainsString( '<td>6</td>', $html );
        $this->assertStringContainsString( '<td>4</td>', $html );
    }

    public function testRenderHandlesEmptyData(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            return array();
        };

        ob_start();
        Gr_Dashboard_Page::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'No country data yet.', $html );
        $this->assertStringContainsString( '>0</span>', $html ); // zero KPIs, still dense.
    }

    public function testPluginWiresMenuAndDashboardRoute(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        $hooks = array();
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            $hooks[] = (string) $registration['hook'];
        }
        $this->assertContains( 'admin_menu', $hooks );
        $this->assertContains( 'admin_enqueue_scripts', $hooks );
        $this->assertContains( 'rest_api_init', $hooks );

        // Registration happens when core fires the hook, as on a real
        // request; firing it proves the wiring, not just the intent.
        do_action( 'rest_api_init' );

        $routes = array();
        foreach ( $GLOBALS['gr_stub_rest_routes'] as $route ) {
            $routes[] = $route['namespace'] . $route['route'];
        }
        $this->assertContains( 'greenpng/v1/collect', $routes );
        $this->assertContains( 'greenpng/v1/dashboard', $routes );
    }
}
