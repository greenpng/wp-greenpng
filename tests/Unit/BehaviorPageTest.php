<?php
/**
 * Behavior Insights page (docs/06 Audience tree, ADR-0012 D4): the
 * two-tab reporting surface — tiles read the daily summary, the
 * drill-down lists read the event stream with their payloads, and
 * the menu entry carries the owner capability.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Admin\Gr_Admin_Menu;
use GreenPNG\Admin\Gr_Behavior_Page;
use PHPUnit\Framework\TestCase;

final class BehaviorPageTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        $_GET = $_GET ?? array();
        unset( $_GET['tab'] );
    }

    protected function tearDown(): void {
        $_GET = $_GET ?? array();
        unset( $_GET['tab'] );
        parent::tearDown();
    }

    /**
     * Canned reads: the summary dimension and the event stream.
     *
     * @return void
     */
    private function seed(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'behavior_events' ) ) {
                return array(
                    array( 'metric_key' => 'dwell', 'total' => '40.0000' ),
                    array( 'metric_key' => 'scroll_depth', 'total' => '10.0000' ),
                    array( 'metric_key' => 'rage_click', 'total' => '3.0000' ),
                    array( 'metric_key' => 'dead_click', 'total' => '7.0000' ),
                );
            }

            if ( false !== strpos( $sql, "event_name = 'dwell'" ) ) {
                return array(
                    array(
                        'visitor_id'   => 'abcdef1234567890abcdef1234567890',
                        'event_name'   => 'dwell',
                        'payload_json' => '{"bucket":"60-180","path":"/pricing/"}',
                        'created_at'   => '2026-09-13 10:00:00',
                    ),
                );
            }

            if ( false !== strpos( $sql, "event_name = 'scroll_depth'" ) ) {
                return array(
                    array(
                        'visitor_id'   => 'abcdef1234567890abcdef1234567890',
                        'event_name'   => 'scroll_depth',
                        'payload_json' => '{"milestone":75,"path":"/pricing/"}',
                        'created_at'   => '2026-09-13 10:00:00',
                    ),
                );
            }

            if ( false !== strpos( $sql, "event_name = 'rage_click'" ) ) {
                return array(
                    array(
                        'visitor_id'   => 'abcdef1234567890abcdef1234567890',
                        'event_name'   => 'rage_click',
                        'payload_json' => '{"clicks":4,"locator":"button.buy-now","path":"/pricing/"}',
                        'created_at'   => '2026-09-13 10:00:00',
                    ),
                );
            }

            if ( false !== strpos( $sql, "event_name = 'dead_click'" ) ) {
                return array(
                    array(
                        'visitor_id'   => 'abcdef1234567890abcdef1234567890',
                        'event_name'   => 'dead_click',
                        'payload_json' => '{"locator":"div.hero","path":"/pricing/"}',
                        'created_at'   => '2026-09-13 10:00:00',
                    ),
                );
            }

            return array();
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
        Gr_Behavior_Page::render();

        return (string) ob_get_clean();
    }

    public function testBehaviorInsightsSubmenuRegistersWithOwnerCapability(): void {
        Gr_Admin_Menu::register();

        $found = null;
        foreach ( $GLOBALS['gr_stub_submenu_pages'] as $entry ) {
            if ( Gr_Behavior_Page::SLUG === $entry['menu_slug'] ) {
                $found = $entry;
            }
        }

        $this->assertNotNull( $found );
        $this->assertSame( 'manage_options', $found['capability'] );
        $this->assertSame( array( Gr_Behavior_Page::class, 'render' ), $found['callback'] );
    }

    public function testEngagementTabRendersTilesAndBothLists(): void {
        $html = $this->render();

        // Tiles over the summary: the four metric keys with their
        // totals, and the deep-reader share note.
        $this->assertStringContainsString( 'gr-kpi-value', $html );
        $this->assertStringContainsString( '>40</span>', $html );
        $this->assertStringContainsString( '>10</span>', $html );
        $this->assertStringContainsString( 'Deep readers: 25', $html );

        // Both engagement lists with their payload values.
        $this->assertStringContainsString( 'Dwell times, newest first', $html );
        $this->assertStringContainsString( '<td>60-180 seconds</td>', $html );
        $this->assertStringContainsString( 'Scroll milestones, newest first', $html );
        $this->assertStringContainsString( '<td>75%</td>', $html );
        $this->assertStringContainsString( '<td>/pricing/</td>', $html );
        $this->assertStringContainsString( '<td>abcdef12…</td>', $html );

        // The tab bar carries both tabs, engagement active.
        $this->assertSame( 2, substr_count( $html, '<a class="nav-tab' ) );
        $this->assertStringContainsString( 'nav-tab-active', $html );
    }

    public function testFrictionTabRendersClickListsWithLocators(): void {
        $html = $this->render( 'friction' );

        $this->assertStringContainsString( 'Rage clicks, newest first', $html );
        $this->assertStringContainsString( '<td>4</td>', $html );
        $this->assertStringContainsString( '<td>button.buy-now</td>', $html );

        $this->assertStringContainsString( 'Dead clicks, newest first', $html );
        $this->assertStringContainsString( '<td>div.hero</td>', $html );

        // The locator column exists only on the friction lists.
        $this->assertStringContainsString( '<th>Locator</th>', $html );
    }

    public function testUnknownTabFallsBackToEngagement(): void {
        $html = $this->render( 'nonsense' );

        $this->assertStringContainsString( 'Dwell times, newest first', $html );
    }

    public function testEmptyStreamRendersHonestEmptyStates(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            return array();
        };

        ob_start();
        Gr_Behavior_Page::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'No events recorded yet.', $html );
        // Tiles keep their shape at zero rather than disappearing.
        $this->assertStringContainsString( '>0</span>', $html );
        $this->assertStringNotContainsString( 'Deep readers:', $html );
    }
}
