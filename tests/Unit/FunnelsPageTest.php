<?php
/**
 * Funnels & Goals page (docs/13 U17, docs/06 §1): the A/B reporting
 * surface over the real engines — honest empty state, running and
 * paused experiment blocks, the pure-hash split preview including
 * its immunity to the URL force parameter, the three Z-test verdict
 * states with hand-checkable numbers, the read-only contract, and
 * the menu position.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Admin\Gr_Admin_Menu;
use GreenPNG\Admin\Gr_Analytics_Page;
use GreenPNG\Admin\Gr_Funnels_Page;
use GreenPNG\Admin\Gr_Url_Builder_Page;
use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Funnel\Gr_Ab_Engine;
use GreenPNG\Funnel\Gr_Ab_Experiments;
use PHPUnit\Framework\TestCase;

final class FunnelsPageTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        unset( $_POST, $_GET );
        Gr_Ab_Experiments::reset_memo_for_tests();
        $GLOBALS['wpdb']->results = array();
        $GLOBALS['wpdb']->queries = array();
    }

    protected function tearDown(): void {
        unset( $_POST, $_GET );
        delete_option( Gr_Ab_Experiments::OPTION_KEY );
        Gr_Ab_Experiments::reset_memo_for_tests();
        $GLOBALS['wpdb']->results = array();
        parent::tearDown();
    }

    /**
     * Renders the page and returns whitespace-compacted HTML so cell
     * adjacency assertions survive the template's newlines.
     *
     * @return string
     */
    private function render_compact(): string {
        ob_start();
        Gr_Funnels_Page::render();
        $html = (string) ob_get_clean();

        return (string) preg_replace( '/\s+/', ' ', $html );
    }

    /**
     * Seeds arm counts per experiment: experiment => variant =>
     * [impressions, conversions]. A closure answers each ab_counts
     * query with its own experiment's rows.
     *
     * @param array<string, array<string, array<int, int>>> $by_experiment Arms per experiment.
     * @return void
     */
    private function stage_counts( array $by_experiment ): void {
        $GLOBALS['wpdb']->results = static function ( string $query ) use ( $by_experiment ): array {
            foreach ( $by_experiment as $experiment => $arms ) {
                if ( false === strpos( $query, "'" . $experiment . "'" ) ) {
                    continue;
                }
                $rows = array();
                foreach ( $arms as $variant => $counts ) {
                    $rows[] = array( 'ab_variant' => $variant, 'ab_type' => 'impression', 'n' => (string) $counts[0] );
                    $rows[] = array( 'ab_variant' => $variant, 'ab_type' => 'conversion', 'n' => (string) $counts[1] );
                }

                return $rows;
            }

            return array();
        };
    }

    public function testRenderEmptyStateIsHonest(): void {
        $html = $this->render_compact();

        self::assertStringContainsString( 'No experiments defined yet', $html );
        self::assertStringNotContainsString( '<table', $html );
        self::assertStringNotContainsString( 'preview-01', $html );
        self::assertStringNotContainsString( '95% confidence', $html );
        self::assertStringContainsString( 'gr_ab] shortcode', $html );
    }

    public function testRenderRunningExperimentWithInsufficientSample(): void {
        Gr_Ab_Experiments::save( 'hero', array( 'control', 'treatment' ) );
        $this->stage_counts( array(
            'hero' => array( 'control' => array( 5, 1 ), 'treatment' => array( 6, 1 ) ),
        ) );

        $html = $this->render_compact();

        self::assertStringContainsString( 'Experiment: hero (running)', $html );
        self::assertStringContainsString( 'Variants (first is control): control, treatment', $html );
        self::assertStringContainsString( 'Assignment split preview', $html );
        self::assertStringContainsString( 'pure hash split for eight fixed sample ids', $html );
        self::assertStringContainsString( '<td>preview-08</td>', $html );
        self::assertStringContainsString( 'Sample tally: ', $html );
        self::assertStringContainsString( 'Insufficient sample', $html );
        self::assertStringContainsString( 'at least 30 impressions', $html );
        self::assertStringContainsString( '<td>insufficient</td>', $html );
        self::assertStringContainsString( '<td>0.000</td>', $html );
    }

    public function testSignificantVerdictRendersWinnerConfidenceAndNumbers(): void {
        Gr_Ab_Experiments::save( 'hero', array( 'control', 'treatment' ) );
        $this->stage_counts( array(
            // 10% vs 30% over 400 impressions per arm: z = 7.0711.
            'hero' => array( 'control' => array( 400, 40 ), 'treatment' => array( 400, 120 ) ),
        ) );

        $html = $this->render_compact();

        self::assertStringContainsString( 'Significant at 95% confidence', $html );
        self::assertStringContainsString( '<td>7.071</td>', $html );
        self::assertStringContainsString( '<td>significant</td>', $html );
        self::assertStringContainsString( '<td>treatment</td>', $html );
        self::assertStringContainsString( '<td> 10.00% </td>', $html );
        self::assertStringContainsString( '<td> 30.00% </td>', $html );
        self::assertStringContainsString( '<td>400</td>', $html );
    }

    public function testInconclusiveVerdictRendersWhenArmsAreFullButFlat(): void {
        Gr_Ab_Experiments::save( 'beta', array( 'control', 'challenger' ) );
        $this->stage_counts( array(
            // 10% vs 11% over equal arms: far below the 1.96 bar.
            'beta' => array( 'control' => array( 100, 10 ), 'challenger' => array( 100, 11 ) ),
        ) );

        $html = $this->render_compact();

        self::assertStringContainsString( 'Inconclusive', $html );
        self::assertStringContainsString( 'no difference proven at 95% confidence', $html );
        self::assertStringContainsString( '<td>inconclusive</td>', $html );
        self::assertStringNotContainsString( 'Significant at 95% confidence', $html );
    }

    public function testAllThreeVerdictStatesRenderAcrossExperiments(): void {
        Gr_Ab_Experiments::save( 'gamma', array( 'control', 'lift' ) );
        Gr_Ab_Experiments::save( 'delta', array( 'control', 'lift' ) );
        Gr_Ab_Experiments::save( 'epsilon', array( 'control', 'lift' ) );
        $this->stage_counts( array(
            'gamma'   => array( 'control' => array( 8, 1 ), 'lift' => array( 9, 1 ) ),
            'delta'   => array( 'control' => array( 200, 20 ), 'lift' => array( 200, 21 ) ),
            'epsilon' => array( 'control' => array( 300, 30 ), 'lift' => array( 300, 90 ) ),
        ) );

        $html = $this->render_compact();

        self::assertStringContainsString( 'Insufficient sample', $html );
        self::assertStringContainsString( 'Inconclusive', $html );
        self::assertStringContainsString( 'Significant at 95% confidence', $html );
        self::assertSame( 3, substr_count( $html, 'Experiment: ' ) );
        // The epsilon verdict must carry the winner, not just the word.
        self::assertStringContainsString( 'Experiment: epsilon (running)', $html );
    }

    public function testPreviewRowsMatchThePureEngineSplit(): void {
        Gr_Ab_Experiments::save( 'hero', array( 'control', 'treatment', 'third' ) );
        $this->stage_counts( array( 'hero' => array() ) );

        $html = $this->render_compact();
        $tally = array( 'control' => 0, 'treatment' => 0, 'third' => 0 );

        foreach ( Gr_Funnels_Page::PREVIEW_IDS as $sample ) {
            $variant = Gr_Ab_Engine::pick_variant( 'hero', $sample, array( 'control', 'treatment', 'third' ) );
            $tally[ $variant ]++;
            self::assertStringContainsString(
                '<td>' . $sample . '</td> <td>' . $variant . '</td>',
                $html
            );
        }

        $expected_tally = 'control=' . $tally['control'] . ', treatment=' . $tally['treatment'] . ', third=' . $tally['third'];
        self::assertStringContainsString( 'Sample tally: ' . $expected_tally, $html );
    }

    public function testPreviewIgnoresTheUrlForceParameter(): void {
        Gr_Ab_Experiments::save( 'hero', array( 'control', 'treatment' ) );
        $this->stage_counts( array( 'hero' => array() ) );

        // The force parameter steers the browsing admin's own
        // assignment; the preview must answer the hash question.
        $_GET = array( Gr_Ab_Engine::FORCE_PARAM => 'treatment' );

        $html = $this->render_compact();

        foreach ( Gr_Funnels_Page::PREVIEW_IDS as $sample ) {
            $variant = Gr_Ab_Engine::pick_variant( 'hero', $sample, array( 'control', 'treatment' ) );
            self::assertStringContainsString(
                '<td>' . $sample . '</td> <td>' . $variant . '</td>',
                $html
            );
        }

        self::assertStringNotContainsString( '<td>treatment</td> <td>treatment</td>', $html );
    }

    public function testPausedExperimentShowsPausedStateWithoutPreview(): void {
        Gr_Ab_Experiments::save( 'hero', array( 'control', 'treatment' ), false );
        $this->stage_counts( array(
            'hero' => array( 'control' => array( 50, 5 ), 'treatment' => array( 50, 6 ) ),
        ) );

        $html = $this->render_compact();

        self::assertStringContainsString( 'Experiment: hero (paused)', $html );
        self::assertStringContainsString( 'Assignment is paused', $html );
        self::assertStringNotContainsString( 'preview-01', $html );
        // Pausing stops assignment, not history: counts stay readable.
        self::assertStringContainsString( '<td>50</td>', $html );
        self::assertStringContainsString( 'Inconclusive', $html );
    }

    public function testReadOnlyOutputCarriesNoFormsOrPlaceholderTabs(): void {
        Gr_Ab_Experiments::save( 'hero', array( 'control', 'treatment' ) );
        $this->stage_counts( array( 'hero' => array() ) );

        $html = $this->render_compact();

        self::assertStringNotContainsString( '<form', $html );
        self::assertStringNotContainsString( 'wpnonce', $html );
        self::assertStringNotContainsString( 'name="action"', $html );
        self::assertStringNotContainsString( 'nav-tab', $html );
        self::assertStringContainsString( 'arrive in a later version', $html );
    }

    public function testMenuEntrySitsBetweenUrlBuilderAndAnalytics(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        do_action( 'admin_menu' );

        $positions = array();
        foreach ( $GLOBALS['gr_stub_submenu_pages'] as $entry ) {
            if ( Gr_Admin_Menu::SLUG !== (string) $entry['parent_slug'] ) {
                continue;
            }
            if ( Gr_Funnels_Page::SLUG === (string) $entry['menu_slug'] ) {
                $positions['funnels'] = count( $positions );
                $this->assertSame( 'manage_options', $entry['capability'] );
                $this->assertSame( array( Gr_Funnels_Page::class, 'render' ), $entry['callback'] );
            } elseif ( Gr_Url_Builder_Page::SLUG === (string) $entry['menu_slug'] ) {
                $positions['url_builder'] = count( $positions );
            } elseif ( Gr_Analytics_Page::SLUG === (string) $entry['menu_slug'] ) {
                $positions['analytics'] = count( $positions );
            }
        }

        self::assertArrayHasKey( 'url_builder', $positions );
        self::assertArrayHasKey( 'funnels', $positions );
        self::assertArrayHasKey( 'analytics', $positions );
        self::assertGreaterThan( $positions['url_builder'], $positions['funnels'] );
        self::assertLessThan( $positions['analytics'], $positions['funnels'] );

        Gr_Plugin::reset_instance();
        Gr_Admin_Menu::reset_for_tests();
    }

    public function testPageSourceCarriesNoOutboundSurface(): void {
        $source = (string) file_get_contents( GR_PLUGIN_DIR . 'includes/admin/class-gr-funnels-page.php' );

        self::assertStringNotContainsString( 'wp_remote_', $source );
        self::assertStringNotContainsString( 'wp_safe_remote_', $source );
        self::assertStringNotContainsString( 'curl_', $source );
        self::assertStringNotContainsString( 'fsockopen', $source );
    }
}
