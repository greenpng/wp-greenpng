<?php
/**
 * Campaigns page (docs/13 U8, docs/06 §1): the breakdown read shapes,
 * the four server-rendered tabs, and the acceptance row — the model
 * comparison shows only values computed from recorded conversions.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Admin\Gr_Campaigns_Page;
use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Storage\Gr_Conversion_Repository;
use GreenPNG\Storage\Gr_Touchpoint_Repository;
use PHPUnit\Framework\TestCase;

final class CampaignsPageTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        unset( $_GET );
    }

    protected function tearDown(): void {
        unset( $_GET );
        parent::tearDown();
    }

    /**
     * Renders one tab and returns the HTML.
     *
     * @param string $tab Tab key.
     * @return string
     */
    private function render_tab( string $tab ): string {
        $_GET = array( 'tab' => $tab );
        ob_start();
        Gr_Campaigns_Page::render();

        return (string) ob_get_clean();
    }

    public function testCampaignBreakdownShapesTheSql(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, "utm_campaign <> ''" ) ) {
                return array(
                    array(
                        'utm_campaign' => 'spring_sale',
                        'channel'      => 'cpc',
                        'utm_source'   => 'google',
                        'utm_medium'   => 'cpc',
                        'entries'      => '7',
                        'visitors'     => '3',
                        'first_seen'   => '2026-09-01 08:00:00',
                        'last_seen'    => '2026-09-12 09:00:00',
                    ),
                );
            }

            return array();
        };

        $rows = ( new Gr_Touchpoint_Repository() )->campaign_breakdown( 30, 30 );

        $sql = (string) $GLOBALS['wpdb']->queries[0];
        $this->assertStringContainsString( "utm_campaign <> ''", $sql );
        $this->assertStringContainsString( 'GROUP BY utm_campaign, channel, utm_source, utm_medium', $sql );
        $this->assertStringContainsString( 'ORDER BY entries DESC', $sql );
        $this->assertSame( 'spring_sale', (string) $rows[0]['utm_campaign'] );
        $this->assertSame( '7', (string) $rows[0]['entries'] );
    }

    public function testUtmBreakdownShapesTheSql(): void {
        ( new Gr_Touchpoint_Repository() )->utm_breakdown( 30, 30 );

        $sql = (string) $GLOBALS['wpdb']->queries[0];
        $this->assertStringContainsString( "utm_source <> '' OR utm_medium <> ''", $sql );
        $this->assertStringContainsString( 'GROUP BY utm_source, utm_medium, utm_campaign, utm_term, utm_content', $sql );
    }

    public function testClickIdBreakdownShapesTheSql(): void {
        ( new Gr_Touchpoint_Repository() )->click_id_breakdown( 30, 30 );

        $sql = (string) $GLOBALS['wpdb']->queries[0];
        $this->assertStringContainsString( "click_id <> ''", $sql );
        $this->assertStringContainsString( 'GROUP BY click_id, channel', $sql );
        $this->assertStringContainsString( 'COUNT(DISTINCT visitor_id) AS visitors', $sql );
    }

    public function testCampaignsForIdsResolvesLabelsAndSkipsEmptyInput(): void {
        $this->assertSame( array(), ( new Gr_Touchpoint_Repository() )->campaigns_for_ids( array() ) );
        $this->assertSame( array(), $GLOBALS['wpdb']->queries );

        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'id IN' ) ) {
                return array(
                    array( 'id' => '12', 'utm_campaign' => 'spring_sale', 'utm_source' => 'google', 'utm_medium' => 'cpc' ),
                    array( 'id' => '13', 'utm_campaign' => '', 'utm_source' => '', 'utm_medium' => '' ),
                );
            }

            return array();
        };

        $labels = ( new Gr_Touchpoint_Repository() )->campaigns_for_ids( array( 12, 13, 12 ) );

        $sql = (string) $GLOBALS['wpdb']->queries[0];
        $this->assertStringContainsString( 'id IN (12,13)', $sql );
        $this->assertSame( 'spring_sale', $labels[12]['campaign'] );
        $this->assertSame( '', $labels[13]['campaign'] );
        $this->assertCount( 2, $labels ); // the duplicate id never doubled.
    }

    public function testConversionRecentShapesTheSql(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'ORDER BY created_at DESC' ) ) {
                return array( array( 'id' => '1', 'amount' => '129.90', 'currency' => 'USD', 'model_weights' => '{}' ) );
            }

            return array();
        };

        $rows = ( new Gr_Conversion_Repository() )->recent( 30, 200 );

        $sql = (string) $GLOBALS['wpdb']->queries[0];
        $this->assertStringContainsString( 'ORDER BY created_at DESC', $sql );
        $this->assertStringContainsString( 'LIMIT 200', $sql );
        $this->assertSame( '129.90', (string) $rows[0]['amount'] );
    }

    public function testCampaignsTabRendersVolumeRows(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, "utm_campaign <> ''" ) ) {
                return array(
                    array(
                        'utm_campaign' => 'spring_sale',
                        'channel'      => 'cpc',
                        'utm_source'   => 'google',
                        'utm_medium'   => 'cpc',
                        'entries'      => '7',
                        'visitors'     => '3',
                        'first_seen'   => '2026-09-01 08:00:00',
                        'last_seen'    => '2026-09-12 09:00:00',
                    ),
                );
            }

            return array();
        };

        $html = $this->render_tab( Gr_Campaigns_Page::TAB_CAMPAIGNS );

        $this->assertStringContainsString( 'nav-tab-wrapper', $html );
        $this->assertStringContainsString( 'tab=utm', $html );
        $this->assertStringContainsString( 'tab=clickids', $html );
        $this->assertStringContainsString( 'tab=models', $html );
        $this->assertStringContainsString( 'widefat', $html );
        $this->assertStringContainsString( 'spring_sale', $html );
        $this->assertStringContainsString( '>7</td>', $html );
        $this->assertStringContainsString( '>3</td>', $html );
    }

    public function testCampaignsTabEmptyState(): void {
        $GLOBALS['wpdb']->results = static function (): array {
            return array();
        };

        $html = $this->render_tab( Gr_Campaigns_Page::TAB_CAMPAIGNS );

        $this->assertStringContainsString( 'No campaign entries recorded yet', $html );
        $this->assertStringNotContainsString( 'widefat', $html );
    }

    public function testUtmTabRendersTupleWithEmptyPlaceholder(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'GROUP BY utm_source' ) ) {
                return array(
                    array(
                        'utm_source'   => 'google',
                        'utm_medium'   => 'cpc',
                        'utm_campaign' => 'spring_sale',
                        'utm_term'     => '',
                        'utm_content'  => '',
                        'entries'      => '4',
                        'visitors'     => '2',
                        'last_seen'    => '2026-09-12 09:00:00',
                    ),
                );
            }

            return array();
        };

        $html = $this->render_tab( Gr_Campaigns_Page::TAB_UTM );

        $this->assertStringContainsString( 'spring_sale', $html );
        $this->assertStringContainsString( '<td>google</td>', $html );
        // Empty parameter cells read as a dash, never as a blank cell.
        $this->assertStringContainsString( '<td>—</td>', $html );
    }

    public function testClickIdsTabRendersCarriers(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, "click_id <> ''" ) ) {
                return array(
                    array(
                        'click_id'   => 'EAIaIQobChMI',
                        'channel'    => 'cpc',
                        'entries'    => '2',
                        'visitors'   => '1',
                        'first_seen' => '2026-09-10 08:00:00',
                        'last_seen'  => '2026-09-12 09:00:00',
                    ),
                );
            }

            return array();
        };

        $html = $this->render_tab( Gr_Campaigns_Page::TAB_CLICKIDS );

        $this->assertStringContainsString( 'EAIaIQobChMI', $html );
        $this->assertStringContainsString( '<td>cpc</td>', $html );
    }

    public function testClickIdsTabEmptyStateMentionsTheFamily(): void {
        $GLOBALS['wpdb']->results = static function (): array {
            return array();
        };

        $html = $this->render_tab( Gr_Campaigns_Page::TAB_CLICKIDS );

        $this->assertStringContainsString( 'No click IDs recorded yet', $html );
        $this->assertStringContainsString( 'gclid', $html );
        $this->assertStringNotContainsString( 'widefat', $html );
    }

    public function testModelsTabShowsComputedSplitsOnly(): void {
        $split_one = wp_json_encode(
            array(
                'first'      => array( '12' => array( 'weight' => 1.0, 'amount' => 100.0 ) ),
                'last'       => array( '13' => array( 'weight' => 1.0, 'amount' => 100.0 ) ),
                'linear'     => array(
                    '12' => array( 'weight' => 0.5, 'amount' => 50.0 ),
                    '13' => array( 'weight' => 0.5, 'amount' => 50.0 ),
                ),
                'position'   => array(
                    '12' => array( 'weight' => 0.4, 'amount' => 40.0 ),
                    '13' => array( 'weight' => 0.6, 'amount' => 60.0 ),
                ),
                'time_decay' => array(
                    '12' => array( 'weight' => 0.3, 'amount' => 30.0 ),
                    '13' => array( 'weight' => 0.6, 'amount' => 60.0 ),
                    '99' => array( 'weight' => 0.1, 'amount' => 10.0 ),
                ),
            )
        );

        $GLOBALS['wpdb']->results = static function ( string $sql ) use ( $split_one ): array {
            if ( false !== strpos( $sql, 'ORDER BY created_at DESC' ) ) {
                return array(
                    array(
                        'id'             => '1',
                        'source_type'    => 'woocommerce',
                        'source_id'      => '10',
                        'visitor_id'     => 'v1',
                        'amount'         => '100.00',
                        'currency'       => 'USD',
                        'model_weights'  => $split_one,
                        'created_at'     => '2026-09-12 09:00:00',
                    ),
                    array(
                        'id'             => '2',
                        'source_type'    => 'woocommerce',
                        'source_id'      => '11',
                        'visitor_id'     => 'v2',
                        'amount'         => '40.00',
                        'currency'       => 'USD',
                        'model_weights'  => '{"first":[],"last":[],"linear":[],"position":[],"time_decay":[]}',
                        'created_at'     => '2026-09-12 10:00:00',
                    ),
                );
            }

            if ( false !== strpos( $sql, 'id IN' ) ) {
                return array(
                    array( 'id' => '12', 'utm_campaign' => 'spring_sale', 'utm_source' => 'google', 'utm_medium' => 'cpc' ),
                    array( 'id' => '13', 'utm_campaign' => 'summer_sale', 'utm_source' => 'meta', 'utm_medium' => 'social' ),
                );
            }

            return array();
        };

        $html = $this->render_tab( Gr_Campaigns_Page::TAB_MODELS );

        // Five model columns in the fixed order.
        $this->assertStringContainsString( 'First touch', $html );
        $this->assertStringContainsString( 'Last touch', $html );
        $this->assertStringContainsString( 'Position-based', $html );
        $this->assertStringContainsString( 'Time-decay', $html );

        // Campaigns as rows with their credited cells.
        $this->assertStringContainsString( 'spring_sale', $html );
        $this->assertStringContainsString( 'summer_sale', $html );
        $this->assertStringContainsString( '100.00 (100.0%)', $html );
        $this->assertStringContainsString( '50.00 (50.0%)', $html );
        $this->assertStringContainsString( '40.00 (40.0%)', $html );

        // The split naming a touchpoint the sweep removed still
        // reports, under the deleted label.
        $this->assertStringContainsString( '(deleted touchpoint)', $html );
        $this->assertStringContainsString( '10.00 (10.0%)', $html );

        // The direct conversion is counted, not invented into a row.
        $this->assertStringContainsString( 'carry no model credit', $html );
    }

    public function testModelsTabEmptyStateNeverInventsNumbers(): void {
        $GLOBALS['wpdb']->results = static function (): array {
            return array();
        };

        $html = $this->render_tab( Gr_Campaigns_Page::TAB_MODELS );

        $this->assertStringContainsString( 'No conversions recorded yet', $html );
        $this->assertStringNotContainsString( 'widefat', $html );
        $this->assertStringNotContainsString( '%)', $html );
    }

    public function testMenuRegistersCampaignsUnderTheTopLevel(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        do_action( 'admin_menu' );

        $slugs = array();
        foreach ( $GLOBALS['gr_stub_submenu_pages'] as $page ) {
            $slugs[] = $page['parent_slug'] . ':' . $page['menu_slug'];
        }
        $this->assertContains( 'greenpng-dashboard:greenpng-campaigns', $slugs );
    }

    public function testInvalidTabRendersSharesNotVerdicts(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'SUM(s.is_bot)' ) ) {
                return array(
                    array(
                        'campaign' => 'spring',
                        'sessions' => '9',
                        'bots'     => '3',
                        'hosting'  => '2',
                    ),
                );
            }

            if ( false !== strpos( $sql, 'touchpoints' ) ) {
                return array( array( 'campaign' => 'spring', 'converted' => '1' ) );
            }

            return array();
        };

        $html = $this->render_tab( Gr_Campaigns_Page::TAB_INVALID );

        // The fifth tab is in the nav, the window is honest, and the
        // disclosure says signal, not verdict.
        $this->assertStringContainsString( 'tab=invalid', $html );
        $this->assertStringContainsString( 'Campaign quality in the last 90 days', $html );
        $this->assertStringContainsString( 'never an automatic verdict', $html );

        // Shares, not raw counts: bots and hosting each render with
        // their percentage of the campaign's sessions, and the
        // last-touch conversion merge lands in its own column.
        $this->assertStringContainsString( 'spring', $html );
        $this->assertStringContainsString( '3 (33.3%)', $html );
        $this->assertStringContainsString( '2 (22.2%)', $html );
        $this->assertStringContainsString( 'Suspected bot', $html );
        $this->assertStringContainsString( 'Hosting range', $html );
    }

    public function testInvalidTabEmptyStateNamesWhatIsMissing(): void {
        $GLOBALS['wpdb']->results = static function (): array {
            return array();
        };

        $html = $this->render_tab( Gr_Campaigns_Page::TAB_INVALID );

        $this->assertStringContainsString( 'No visitor sessions recorded yet', $html );
        $this->assertStringNotContainsString( 'widefat', $html );
    }
}
