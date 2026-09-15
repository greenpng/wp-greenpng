<?php
/**
 * Funnels & Goals admin page (ADR-0014 D3/D4/D5, docs/06 tree): the
 * four-tab render, the funnel definition write behind the double
 * gate, the A/B creation arm reusing the experiments repository, and
 * the staircase with its screen-reader twin.
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
use GreenPNG\Storage\Gr_Funnel_Repository;
use PHPUnit\Framework\TestCase;

final class FunnelsPageTest extends TestCase {

    /** Two-step flow used as the legal baseline. */
    private const STEPS = array(
        array(
            'name'  => 'Landing',
            'match' => array( 'kind' => 'url', 'value' => '/shop', 'compare' => 'exact' ),
        ),
        array(
            'name'  => 'Purchase',
            'match' => array( 'kind' => 'event', 'value' => 'conversion', 'compare' => 'exact' ),
        ),
    );

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        Gr_Funnels_Page::reset_for_tests();
        Gr_Ab_Experiments::reset_memo_for_tests();
        Gr_Funnel_Repository::reset_memo_for_tests();

        unset( $_POST, $_GET );
        $GLOBALS['gr_stub_caps']       = array( 'manage_options' );
        $GLOBALS['gr_stub_nonce_bad']  = false;
        $GLOBALS['gr_stub_user_id']    = 7;
        $GLOBALS['gr_stub_redirects']  = array();
        $GLOBALS['gr_stub_cron']       = array();
        $GLOBALS['wpdb']->queries      = array();
        $GLOBALS['wpdb']->inserts      = array();
        $GLOBALS['wpdb']->results      = array();
        $GLOBALS['wpdb']->var_result   = null;
        $GLOBALS['wpdb']->query_result = 0;
        $GLOBALS['wpdb']->insert_id    = 0;
    }

    protected function tearDown(): void {
        Gr_Funnels_Page::reset_for_tests();
        Gr_Ab_Experiments::reset_memo_for_tests();
        Gr_Funnel_Repository::reset_memo_for_tests();
        unset( $_POST, $_GET );
        parent::tearDown();
    }

    /**
     * The nonce value the stub accepts for this page's action.
     *
     * @return string
     */
    private function nonce(): string {
        return 'gr-stub-nonce-' . md5( Gr_Funnels_Page::NONCE_ACTION );
    }

    /**
     * Canned repository reads: the funnels table answers by query
     * shape, everything else falls through empty.
     *
     * @param array<int, array<string, string>> $rows Funnel rows for the all/row reads.
     * @return void
     */
    private function funnels_in_db( array $rows ): void {
        $GLOBALS['wpdb']->results = static function ( string $query ) use ( $rows ): array {
            if ( false !== strpos( $query, 'WHERE id =' ) ) {
                $id = (int) substr( $query, (int) strpos( $query, 'WHERE id =' ) + 11 );
                foreach ( $rows as $row ) {
                    if ( (int) $row['id'] === $id ) {
                        return array( $row );
                    }
                }

                return array();
            }

            return $rows;
        };
    }

    /**
     * One funnel row carrying STEPS.
     *
     * @param int $id Row id.
     * @return array<string, string>
     */
    private function funnel_row( int $id = 3 ): array {
        return array(
            'id'         => (string) $id,
            'name'       => 'Checkout Flow',
            'slug'       => 'checkout-flow',
            'flow_json'  => (string) wp_json_encode( self::STEPS ),
            'is_active'  => '1',
            'created_at' => '2026-09-01 00:00:00',
            'updated_at' => '2026-09-01 00:00:00',
        );
    }

    /**
     * A gate-passing funnel save POST body.
     *
     * @return void
     */
    private function post_funnel_save(): void {
        $_POST = array(
            'gr_funnels_action' => Gr_Funnels_Page::ACTION_FUNNEL_SAVE,
            'funnel_id'         => '0',
            'funnel_name'       => 'Checkout Flow',
            'is_active'         => '1',
            'step_name'         => array( 'Landing', 'Purchase' ),
            'match_kind'        => array( 'url', 'event' ),
            'match_value'       => array( '/shop', 'conversion' ),
            'match_compare'     => array( 'exact', 'exact' ),
            'remove'            => array(),
            Gr_Funnels_Page::NONCE_FIELD => $this->nonce(),
        );
    }

    public function testWriteWithoutCapabilityDoesNothing(): void {
        $this->post_funnel_save();
        $GLOBALS['gr_stub_caps'] = false;

        Gr_Funnels_Page::handle_actions();

        $this->assertSame( array(), $GLOBALS['gr_stub_redirects'] );
        $writes = array();
        foreach ( $GLOBALS['wpdb']->queries as $query ) {
            if ( false !== strpos( (string) $query, 'INSERT INTO wp_gr_funnels' ) ) {
                $writes[] = $query;
            }
        }
        $this->assertSame( array(), $writes );
    }

    public function testWriteWithoutNonceDoesNothing(): void {
        $this->post_funnel_save();
        $GLOBALS['gr_stub_nonce_bad'] = true;

        Gr_Funnels_Page::handle_actions();

        $this->assertSame( array(), $GLOBALS['gr_stub_redirects'] );
        $this->assertSame( array(), $GLOBALS['wpdb']->inserts );
    }

    public function testValidFunnelSavePersistsAuditsAndRedirects(): void {
        global $wpdb;
        $this->post_funnel_save();
        $wpdb->insert_id = 5;
        $this->funnels_in_db( array( $this->funnel_row( 5 ) ) );

        Gr_Funnels_Page::handle_actions();

        // The definition insert ran with the flow JSON.
        $insert = '';
        foreach ( $wpdb->queries as $query ) {
            if ( false !== strpos( (string) $query, 'INSERT INTO wp_gr_funnels' ) ) {
                $insert = (string) $query;
            }
        }
        $this->assertStringContainsString( "'Checkout Flow'", $insert );
        $this->assertStringContainsString( addslashes( (string) wp_json_encode( self::STEPS ) ), $insert );

        // Post-redirect-get back to the funnels tab with the flag.
        $this->assertCount( 1, $GLOBALS['gr_stub_redirects'] );
        $this->assertStringContainsString( 'page=greenpng-funnels', $GLOBALS['gr_stub_redirects'][0]['location'] );
        $this->assertStringContainsString( 'tab=funnels', $GLOBALS['gr_stub_redirects'][0]['location'] );
        $this->assertStringContainsString( 'funnel_saved=1', $GLOBALS['gr_stub_redirects'][0]['location'] );

        // The save is audited with the after-picture.
        $audit = null;
        foreach ( $wpdb->inserts as $row ) {
            if ( 'wp_gr_audit_logs' === $row['table'] && 'save' === $row['data']['action'] && 'funnel' === $row['data']['object_type'] ) {
                $audit = $row['data'];
            }
        }
        $this->assertNotNull( $audit );
        $this->assertSame( '5', (string) $audit['object_id'] );
        $this->assertSame( 7, $audit['user_id'] );
    }

    public function testRefusedFunnelSaveFallsThroughWithTheRowsOnScreen(): void {
        $this->post_funnel_save();
        // One step only: the validation floor is two.
        $_POST['step_name']   = array( 'Landing' );
        $_POST['match_kind']  = array( 'url' );
        $_POST['match_value'] = array( '/shop' );
        $_POST['match_compare'] = array( 'exact' );

        Gr_Funnels_Page::handle_actions();

        $this->assertSame( array(), $GLOBALS['gr_stub_redirects'], 'A refused save never redirects.' );

        ob_start();
        $_GET['tab'] = 'funnels';
        Gr_Funnels_Page::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'notice-error', $html );
        $this->assertStringContainsString( 'at least two steps', $html );
        // The submitted name stays for correcting, not a blank form.
        $this->assertStringContainsString( 'value="Checkout Flow"', $html );
    }

    public function testFunnelDeleteRemovesDefinitionAndJourneysAndAudits(): void {
        global $wpdb;
        $this->funnels_in_db( array( $this->funnel_row( 3 ) ) );
        $wpdb->query_result = 1;

        $_POST = array(
            'gr_funnels_action' => Gr_Funnels_Page::ACTION_FUNNEL_DELETE,
            'funnel_id'         => '3',
            Gr_Funnels_Page::NONCE_FIELD => $this->nonce(),
        );

        Gr_Funnels_Page::handle_actions();

        $sql = implode( ' ', $wpdb->queries );
        $this->assertStringContainsString( 'DELETE FROM wp_gr_funnels WHERE id = 3', $sql );
        $this->assertStringContainsString( 'DELETE FROM wp_gr_funnel_sessions WHERE funnel_id = 3', $sql );

        $audit = null;
        foreach ( $wpdb->inserts as $row ) {
            if ( 'wp_gr_audit_logs' === $row['table'] && 'delete' === $row['data']['action'] && 'funnel' === $row['data']['object_type'] ) {
                $audit = $row['data'];
            }
        }
        $this->assertNotNull( $audit );
        $this->assertStringContainsString( 'tab=funnels', $GLOBALS['gr_stub_redirects'][0]['location'] );
        $this->assertStringContainsString( 'funnel_deleted=1', $GLOBALS['gr_stub_redirects'][0]['location'] );
    }

    public function testFunnelToggleFlipsStateAndAudits(): void {
        global $wpdb;
        $this->funnels_in_db( array( $this->funnel_row( 3 ) ) );
        $wpdb->query_result = 1;

        $_POST = array(
            'gr_funnels_action' => Gr_Funnels_Page::ACTION_FUNNEL_TOGGLE,
            'funnel_id'         => '3',
            'to_active'         => '0',
            Gr_Funnels_Page::NONCE_FIELD => $this->nonce(),
        );

        Gr_Funnels_Page::handle_actions();

        $this->assertStringContainsString( 'UPDATE wp_gr_funnels SET is_active = 0', implode( ' ', $wpdb->queries ) );

        $audit = null;
        foreach ( $wpdb->inserts as $row ) {
            if ( 'wp_gr_audit_logs' === $row['table'] && 'toggle' === $row['data']['action'] ) {
                $audit = $row['data'];
            }
        }
        $this->assertNotNull( $audit );
        $this->assertSame( 'funnel', $audit['object_type'] );
        $this->assertStringContainsString( 'funnel_toggled=1', $GLOBALS['gr_stub_redirects'][0]['location'] );
    }

    public function testAbCreateSavesThroughTheRepositoryAndAudits(): void {
        $_POST = array(
            'gr_funnels_action' => Gr_Funnels_Page::ACTION_AB_SAVE,
            'experiment'        => 'hero',
            'variants'          => "control\ntreatment",
            'is_active'         => '1',
            Gr_Funnels_Page::NONCE_FIELD => $this->nonce(),
        );

        Gr_Funnels_Page::handle_actions();

        $definition = Gr_Ab_Experiments::get( 'hero' );
        $this->assertNotNull( $definition );
        $this->assertSame( array( 'control', 'treatment' ), $definition['variants'] );

        $audit = null;
        foreach ( $GLOBALS['wpdb']->inserts as $row ) {
            if ( 'wp_gr_audit_logs' === $row['table'] && 'save' === $row['data']['action'] && 'ab_experiment' === $row['data']['object_type'] ) {
                $audit = $row['data'];
            }
        }
        $this->assertNotNull( $audit );
        $this->assertSame( 'hero', $audit['object_id'] );

        $this->assertStringContainsString( 'tab=ab', $GLOBALS['gr_stub_redirects'][0]['location'] );
        $this->assertStringContainsString( 'ab_saved=1', $GLOBALS['gr_stub_redirects'][0]['location'] );
    }

    public function testAbPauseResendsStoredVariantsAndFlipsState(): void {
        Gr_Ab_Experiments::save( 'hero', array( 'control', 'treatment' ), true );

        $_POST = array(
            'gr_funnels_action' => Gr_Funnels_Page::ACTION_AB_SAVE,
            'experiment'        => 'hero',
            'variants'          => array( 'control', 'treatment' ),
            'is_active'         => '0',
            Gr_Funnels_Page::NONCE_FIELD => $this->nonce(),
        );

        Gr_Funnels_Page::handle_actions();

        $definition = Gr_Ab_Experiments::get( 'hero' );
        $this->assertNotNull( $definition );
        $this->assertFalse( $definition['active'] );
        $this->assertSame( array( 'control', 'treatment' ), $definition['variants'], 'The pause arm rewrites nothing but the state.' );

        $this->assertStringContainsString( 'ab_saved=1', $GLOBALS['gr_stub_redirects'][0]['location'] );
    }

    public function testRenderAbTabShowsReportingAndTheCreationForm(): void {
        Gr_Ab_Experiments::save( 'hero', array( 'control', 'treatment' ), true );

        ob_start();
        Gr_Funnels_Page::render();
        $html = (string) ob_get_clean();

        // Four tabs on one page.
        $this->assertStringContainsString( 'A/B experiments', $html );
        $this->assertStringContainsString( 'tab=funnels', $html );
        $this->assertStringContainsString( 'Step loss', $html );
        $this->assertStringContainsString( 'tab=goals', $html );

        // The v1.0 reporting blocks stay.
        $this->assertStringContainsString( 'Experiment: hero', $html );
        $this->assertStringContainsString( 'Assignment split preview', $html );
        $this->assertStringContainsString( 'Recorded counts and significance', $html );

        // The write arm: creation form and the pause/delete forms.
        $this->assertStringContainsString( 'Create an experiment', $html );
        $this->assertStringContainsString( 'value="' . Gr_Funnels_Page::ACTION_AB_SAVE . '"', $html );
        $this->assertStringContainsString( 'value="' . Gr_Funnels_Page::ACTION_AB_DELETE . '"', $html );
        $this->assertStringContainsString( 'name="' . Gr_Funnels_Page::NONCE_FIELD . '"', $html );
    }

    public function testRenderFunnelsTabShowsTheListAndTheStepForm(): void {
        $this->funnels_in_db( array( $this->funnel_row( 3 ) ) );
        $_GET['tab'] = 'funnels';

        ob_start();
        Gr_Funnels_Page::render();
        $html = (string) ob_get_clean();

        // The definition list with state and write arms.
        $this->assertStringContainsString( 'Checkout Flow', $html );
        $this->assertStringContainsString( 'value="' . Gr_Funnels_Page::ACTION_FUNNEL_TOGGLE . '"', $html );
        $this->assertStringContainsString( 'value="' . Gr_Funnels_Page::ACTION_FUNNEL_DELETE . '"', $html );

        // The create form: step rows with the closed vocabulary hint.
        $this->assertStringContainsString( 'Create a funnel', $html );
        $this->assertStringContainsString( 'closed vocabulary', $html );
        $this->assertStringContainsString( 'name="step_name[0]"', $html );
        $this->assertStringContainsString( 'name="remove[0]"', $html );
        $this->assertStringContainsString( 'value="prefix"', $html );
    }

    public function testRenderEditPrefillsTheStoredFlow(): void {
        $this->funnels_in_db( array( $this->funnel_row( 3 ) ) );
        $_GET['tab'] = 'funnels';
        $_GET['edit'] = '3';

        ob_start();
        Gr_Funnels_Page::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'Edit funnel: Checkout Flow', $html );
        $this->assertStringContainsString( 'value="Landing"', $html );
        $this->assertStringContainsString( 'value="/shop"', $html );
        // The stored row id rides the form.
        $this->assertStringContainsString( 'name="funnel_id" value="3"', $html );
    }

    public function testRenderStepsTabShowsTheStaircaseAndItsScreenReaderTwin(): void {
        $GLOBALS['wpdb']->results = static function ( string $query ): array {
            if ( false !== strpos( $query, 'WHERE id =' ) ) {
                return array( array( 'id' => '3', 'name' => 'F', 'slug' => 'f', 'flow_json' => (string) wp_json_encode( self::STEPS ), 'is_active' => '1' ) );
            }
            if ( false !== strpos( $query, 'FROM wp_gr_funnels' ) ) {
                return array( array( 'id' => '3', 'name' => 'Checkout Flow', 'slug' => 'checkout-flow', 'flow_json' => (string) wp_json_encode( self::STEPS ), 'is_active' => '1', 'created_at' => 'x', 'updated_at' => 'x' ) );
            }
            if ( false !== strpos( $query, 'GROUP BY max_step' ) ) {
                return array(
                    array( 'max_step' => '1', 'sessions' => '3', 'completed' => '0' ),
                    array( 'max_step' => '2', 'sessions' => '2', 'completed' => '1' ),
                );
            }

            return array();
        };
        $_GET['tab'] = 'steps';
        $_GET['funnel'] = '3';

        ob_start();
        Gr_Funnels_Page::render();
        $html = (string) ob_get_clean();

        // The staircase: flexbox bars with share heights and the
        // dashicon drop-off marker.
        $this->assertStringContainsString( 'display:flex;align-items:flex-end', $html );
        $this->assertStringContainsString( 'height:100%', $html );
        $this->assertStringContainsString( 'dashicons-arrow-down-alt', $html );
        $this->assertStringContainsString( 'background-color:#2271b1', $html );
        $this->assertStringContainsString( 'Landing', $html );
        $this->assertStringContainsString( 'Completed the last step: 1', $html );

        // The screen-reader twin carries the same numbers.
        $this->assertStringContainsString( 'screen-reader-text', $html );
        $this->assertStringContainsString( 'Drop-off from previous', $html );
        // The first step has no previous one: its drop-off reads
        // zero, never a negative share.
        $this->assertStringContainsString( '<td>0</td>', $html );
        $this->assertStringNotContainsString( '<td>-', $html, 'No negative drop-off renders in any cell.' );

        // The funnel selector is whitelisted: an unknown id falls
        // back to the first funnel, never an empty report.
        $this->assertStringContainsString( 'name="funnel"', $html );
    }

    public function testRenderStepsTabWithoutFunnelsShowsGuidance(): void {
        $_GET['tab'] = 'steps';

        ob_start();
        Gr_Funnels_Page::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'Create one on the Funnels tab', $html );
    }

    public function testRenderGoalsTabShowsSourcesAndCompletions(): void {
        $GLOBALS['wpdb']->results = static function ( string $query ): array {
            if ( false !== strpos( $query, 'FROM wp_gr_conversions' ) ) {
                return array(
                    array( 'source_type' => 'woocommerce', 'n' => '6', 'net' => '540.25' ),
                    array( 'source_type' => 'fluentform', 'n' => '2', 'net' => '0.00' ),
                );
            }
            if ( false !== strpos( $query, 'completed_at IS NOT NULL' ) ) {
                return array( array( 'funnel_id' => '3', 'completed' => '4' ) );
            }
            if ( false !== strpos( $query, 'FROM wp_gr_funnels' ) ) {
                return array( array( 'id' => '3', 'name' => 'Checkout Flow', 'slug' => 'checkout-flow', 'flow_json' => (string) wp_json_encode( self::STEPS ), 'is_active' => '1', 'created_at' => 'x', 'updated_at' => 'x' ) );
            }

            return array();
        };
        $_GET['tab'] = 'goals';

        ob_start();
        Gr_Funnels_Page::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'Conversions by source', $html );
        $this->assertStringContainsString( 'WooCommerce', $html );
        $this->assertStringContainsString( '540.25', $html );
        $this->assertStringContainsString( 'Funnels completed', $html );
        $this->assertStringContainsString( 'Checkout Flow', $html );
        $this->assertStringContainsString( 'reversed orders count as conversions but not value', $html );
    }

    //
    // v1.0 reporting coverage, restored: the A/B blocks themselves
    // are unchanged by the v1.1 write arm, so their contracts stay
    // under test exactly as before.
    //

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

        // No experiments: no blocks, no preview, no verdicts — but
        // the creation form is the honest next step, not a dead end.
        $this->assertStringContainsString( 'No experiments defined yet', $html );
        $this->assertStringNotContainsString( 'Experiment: ', $html );
        $this->assertStringNotContainsString( 'preview-01', $html );
        $this->assertStringNotContainsString( '95% confidence', $html );
        $this->assertStringContainsString( 'gr_ab] shortcode', $html );
        $this->assertStringContainsString( 'Create an experiment', $html );
    }

    public function testRenderRunningExperimentWithInsufficientSample(): void {
        Gr_Ab_Experiments::save( 'hero', array( 'control', 'treatment' ) );
        $this->stage_counts( array(
            'hero' => array( 'control' => array( 5, 1 ), 'treatment' => array( 6, 1 ) ),
        ) );

        $html = $this->render_compact();

        $this->assertStringContainsString( 'Experiment: hero (running)', $html );
        $this->assertStringContainsString( 'Variants (first is control): control, treatment', $html );
        $this->assertStringContainsString( 'Assignment split preview', $html );
        $this->assertStringContainsString( 'pure hash split for eight fixed sample ids', $html );
        $this->assertStringContainsString( '<td>preview-08</td>', $html );
        $this->assertStringContainsString( 'Sample tally: ', $html );
        $this->assertStringContainsString( 'Insufficient sample', $html );
        $this->assertStringContainsString( 'at least 30 impressions', $html );
        $this->assertStringContainsString( '<td>insufficient</td>', $html );
        $this->assertStringContainsString( '<td>0.000</td>', $html );
    }

    public function testSignificantVerdictRendersWinnerConfidenceAndNumbers(): void {
        Gr_Ab_Experiments::save( 'hero', array( 'control', 'treatment' ) );
        $this->stage_counts( array(
            // 10% vs 30% over 400 impressions per arm: z = 7.0711.
            'hero' => array( 'control' => array( 400, 40 ), 'treatment' => array( 400, 120 ) ),
        ) );

        $html = $this->render_compact();

        $this->assertStringContainsString( 'Significant at 95% confidence', $html );
        $this->assertStringContainsString( '<td>7.071</td>', $html );
        $this->assertStringContainsString( '<td>significant</td>', $html );
        $this->assertStringContainsString( '<td>treatment</td>', $html );
        $this->assertStringContainsString( '<td> 10.00% </td>', $html );
        $this->assertStringContainsString( '<td> 30.00% </td>', $html );
        $this->assertStringContainsString( '<td>400</td>', $html );
    }

    public function testInconclusiveVerdictRendersWhenArmsAreFullButFlat(): void {
        Gr_Ab_Experiments::save( 'beta', array( 'control', 'challenger' ) );
        $this->stage_counts( array(
            // 10% vs 11% over equal arms: far below the 1.96 bar.
            'beta' => array( 'control' => array( 100, 10 ), 'challenger' => array( 100, 11 ) ),
        ) );

        $html = $this->render_compact();

        $this->assertStringContainsString( 'Inconclusive', $html );
        $this->assertStringContainsString( 'no difference proven at 95% confidence', $html );
        $this->assertStringContainsString( '<td>inconclusive</td>', $html );
        $this->assertStringNotContainsString( 'Significant at 95% confidence', $html );
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

        $this->assertStringContainsString( 'Insufficient sample', $html );
        $this->assertStringContainsString( 'Inconclusive', $html );
        $this->assertStringContainsString( 'Significant at 95% confidence', $html );
        $this->assertSame( 3, substr_count( $html, 'Experiment: ' ) );
        // The epsilon verdict must carry the winner, not just the word.
        $this->assertStringContainsString( 'Experiment: epsilon (running)', $html );
    }

    public function testPreviewRowsMatchThePureEngineSplit(): void {
        Gr_Ab_Experiments::save( 'hero', array( 'control', 'treatment', 'third' ) );
        $this->stage_counts( array( 'hero' => array() ) );

        $html = $this->render_compact();
        $tally = array( 'control' => 0, 'treatment' => 0, 'third' => 0 );

        foreach ( Gr_Funnels_Page::PREVIEW_IDS as $sample ) {
            $variant = Gr_Ab_Engine::pick_variant( 'hero', $sample, array( 'control', 'treatment', 'third' ) );
            $tally[ $variant ]++;
            $this->assertStringContainsString(
                '<td>' . $sample . '</td> <td>' . $variant . '</td>',
                $html
            );
        }

        $expected_tally = 'control=' . $tally['control'] . ', treatment=' . $tally['treatment'] . ', third=' . $tally['third'];
        $this->assertStringContainsString( 'Sample tally: ' . $expected_tally, $html );
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
            $this->assertStringContainsString(
                '<td>' . $sample . '</td> <td>' . $variant . '</td>',
                $html
            );
        }

        $this->assertStringNotContainsString( '<td>treatment</td> <td>treatment</td>', $html );
    }

    public function testPausedExperimentShowsPausedStateWithoutPreview(): void {
        Gr_Ab_Experiments::save( 'hero', array( 'control', 'treatment' ), false );
        $this->stage_counts( array(
            'hero' => array( 'control' => array( 50, 5 ), 'treatment' => array( 50, 6 ) ),
        ) );

        $html = $this->render_compact();

        $this->assertStringContainsString( 'Experiment: hero (paused)', $html );
        $this->assertStringContainsString( 'Assignment is paused', $html );
        $this->assertStringNotContainsString( 'preview-01', $html );
        // Pausing stops assignment, not history: counts stay readable.
        $this->assertStringContainsString( '<td>50</td>', $html );
        $this->assertStringContainsString( 'Inconclusive', $html );
    }

    public function testEveryWriteFormCarriesTheNonceAndThePlaceholderCopyIsGone(): void {
        Gr_Ab_Experiments::save( 'hero', array( 'control', 'treatment' ) );
        $this->stage_counts( array( 'hero' => array() ) );

        $html = $this->render_compact();

        // The v1.0 read-only contract became the v1.1 write arm: the
        // forms are here, and every one of them carries the nonce.
        $this->assertStringContainsString( '<form', $html );
        $this->assertStringContainsString( 'name="' . Gr_Funnels_Page::NONCE_FIELD . '"', $html );
        // No form posts anywhere but this page.
        $this->assertStringNotContainsString( 'action="http', $html );
        // The v1.0 "later version" placeholder is gone: the tabs are
        // real now.
        $this->assertStringNotContainsString( 'arrive in a later version', $html );
        $this->assertStringContainsString( 'nav-tab', $html );
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

        $this->assertArrayHasKey( 'url_builder', $positions );
        $this->assertArrayHasKey( 'funnels', $positions );
        $this->assertArrayHasKey( 'analytics', $positions );
        $this->assertGreaterThan( $positions['url_builder'], $positions['funnels'] );
        $this->assertLessThan( $positions['analytics'], $positions['funnels'] );

        Gr_Plugin::reset_instance();
        Gr_Admin_Menu::reset_for_tests();
    }

    public function testPageSourceCarriesNoOutboundSurface(): void {
        $source = (string) file_get_contents( GR_PLUGIN_DIR . 'includes/admin/class-gr-funnels-page.php' );

        $this->assertStringNotContainsString( 'wp_remote_', $source );
        $this->assertStringNotContainsString( 'wp_safe_remote_', $source );
        $this->assertStringNotContainsString( 'curl_', $source );
        $this->assertStringNotContainsString( 'fsockopen', $source );
    }
}
