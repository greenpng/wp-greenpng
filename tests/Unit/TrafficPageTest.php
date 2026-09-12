<?php
/**
 * Traffic & Security page (docs/13 U5): the three-tab surface over
 * native components — tab bar, live stream with its server-rendered
 * degradation table, threat events with display-masked addresses
 * (the full form never reaches the markup), and the fraud audit
 * reading conclusion payloads exactly as the channel wrote them.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Admin\Gr_Admin_Menu;
use GreenPNG\Admin\Gr_Traffic_Page;
use GreenPNG\Core\Gr_Plugin;
use PHPUnit\Framework\TestCase;

final class TrafficPageTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        unset( $_GET['tab'] );
    }

    protected function tearDown(): void {
        unset( $_GET['tab'] );
        parent::tearDown();
    }

    /**
     * Canned reads for the three tabs; the event queries return
     * stream rows, the log query returns fold rows.
     *
     * @return void
     */
    private function seed(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'security_conclusion' ) ) {
                return array(
                    array(
                        'id'           => '2',
                        'created_at'   => '2026-09-12 10:01:00',
                        'visitor_id'   => 'abcdef1234567890abcdef',
                        'event_name'   => 'security_conclusion',
                        'event_group'  => 'core',
                        'payload_json' => '{"suspected_bot":true,"bot_tier":"high"}',
                    ),
                );
            }

            if ( false !== strpos( $sql, 'gr_security_logs' ) ) {
                return array(
                    array(
                        'rule_id'      => 'scanner_ua',
                        'ip'           => '203.0.113.77',
                        'request_path' => '/wp-login.php',
                        'hit_count'    => '3',
                        'action_taken' => 'logged',
                        'last_seen'    => '2026-09-12 09:58:00',
                    ),
                );
            }

            return array(
                array(
                    'id'           => '5',
                    'created_at'   => '2026-09-12 10:02:00',
                    'visitor_id'   => 'abcdef1234567890abcdef',
                    'event_name'   => 'pageview',
                    'event_group'  => 'web',
                    'payload_json' => '{"path":"/home"}',
                ),
            );
        };
    }

    /**
     * Renders the page with a tab selected.
     *
     * @param string $tab Tab key.
     * @return string
     */
    private function render( string $tab = '' ): string {
        if ( '' !== $tab ) {
            $_GET['tab'] = $tab;
        }

        $this->seed();
        ob_start();
        Gr_Traffic_Page::render();

        return (string) ob_get_clean();
    }

    public function testTrafficSubmenuRegistersUnderTheTopLevelMenu(): void {
        Gr_Admin_Menu::register();

        $sub = $GLOBALS['gr_stub_submenu_pages'][1];
        $this->assertSame( Gr_Traffic_Page::SLUG, $sub['menu_slug'] );
        $this->assertSame( 'manage_options', $sub['capability'] );
        $this->assertSame( array( Gr_Traffic_Page::class, 'render' ), $sub['callback'] );
    }

    public function testTabBarCarriesAllThreeTabsWithNativeClasses(): void {
        $html = $this->render();

        $this->assertStringContainsString( 'nav-tab-wrapper', $html );
        $this->assertStringContainsString( 'nav-tab-active', $html );
        $this->assertSame( 3, substr_count( $html, '<a class="nav-tab' ) );

        // Default tab is the live stream, with its grid mount and the
        // server-rendered degradation table inside.
        $this->assertStringContainsString( 'id="gr-live-grid"', $html );
        $this->assertStringContainsString( 'widefat striped', $html );
        $this->assertStringContainsString( '<td>pageview</td>', $html );
        $this->assertStringContainsString( '<td>abcdef12…</td>', $html );
    }

    public function testThreatTabMasksAddressesForDisplay(): void {
        $html = $this->render( 'threats' );

        // The masked form renders; the complete address never appears
        // anywhere in the markup (docs/05 §3.1 display rule).
        $this->assertStringContainsString( '<td>203.0.113.*</td>', $html );
        $this->assertStringNotContainsString( '203.0.113.77', $html );
        $this->assertStringContainsString( '<td>scanner_ua</td>', $html );
        $this->assertStringContainsString( 'Addresses are masked for display', $html );
    }

    public function testFraudTabReadsConclusionPayloadsOnly(): void {
        $html = $this->render( 'fraud' );

        $this->assertStringContainsString( '<td>Yes</td>', $html );
        $this->assertStringContainsString( '<td>high</td>', $html );
        // Conclusions carry visitor context, never raw signals.
        $this->assertStringNotContainsString( 'user_agent', $html );
    }

    public function testUnknownTabFallsBackToLive(): void {
        $html = $this->render( 'nonsense' );

        $this->assertStringContainsString( 'id="gr-live-grid"', $html );
    }

    public function testGridLoadsOnlyOnTheTrafficScreen(): void {
        Gr_Admin_Menu::register();

        $traffic_hook = 'greenpng-dashboard_page_' . Gr_Traffic_Page::SLUG;
        $dash_hook    = 'toplevel_page_' . Gr_Admin_Menu::SLUG;

        Gr_Admin_Menu::enqueue_assets( $traffic_hook );
        $this->assertArrayHasKey( 'gr-datagrid', $GLOBALS['gr_stub_enqueued_scripts'] );

        $inline = '';
        foreach ( $GLOBALS['gr_stub_inline_scripts'] as $entry ) {
            if ( 'gr-datagrid' === $entry['handle'] ) {
                $inline .= $entry['text'];
            }
        }
        $this->assertStringContainsString( 'new window.GrDataGrid(', $inline );
        $this->assertStringContainsString( 'containerId:"gr-live-grid"', $inline );
        // The endpoint rides in json_encode form, so compare against
        // the same encoding instead of a hand-written literal.
        $this->assertStringContainsString( (string) wp_json_encode( 'https://stub.example/wp-json/greenpng/v1/live' ), $inline );
        $this->assertStringContainsString( 'pollMs:15000', $inline );

        gr_stub_reset_options();
        Gr_Admin_Menu::register();
        Gr_Admin_Menu::enqueue_assets( $dash_hook );
        $this->assertArrayNotHasKey( 'gr-datagrid', $GLOBALS['gr_stub_enqueued_scripts'] );
    }

    public function testPluginRegistersTheLiveRoute(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();
        do_action( 'rest_api_init' );

        $routes = array();
        foreach ( $GLOBALS['gr_stub_rest_routes'] as $route ) {
            $routes[] = $route['namespace'] . $route['route'];
        }
        $this->assertContains( 'greenpng/v1/live', $routes );
    }
}
