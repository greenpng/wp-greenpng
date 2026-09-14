<?php
/**
 * Scoring Rules admin page (ADR-0013 D3, docs/06 Audience tree): the
 * one-form ruleset write behind the double gate, the recompute
 * dispatch that follows a save, the refused-save fall-through, and
 * the native-component render.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Admin\Gr_Scoring_Rules_Page;
use GreenPNG\CRM\Gr_Scoring_Engine;
use GreenPNG\CRM\Gr_Scoring_Rules;
use PHPUnit\Framework\TestCase;

final class ScoringRulesPageTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        Gr_Scoring_Rules_Page::reset_for_tests();

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
        Gr_Scoring_Rules_Page::reset_for_tests();
        unset( $_POST, $_GET );
        parent::tearDown();
    }

    /**
     * A valid, gate-passing save POST body: one kept row, one
     * remove-marked row, one untouched blank add-row, one kept but
     * inactive row.
     *
     * @return void
     */
    private function post_save(): void {
        $_POST = array(
            'gr_scoring_action' => Gr_Scoring_Rules_Page::ACTION_SAVE,
            'gr_rule'           => array(
                array(
                    'event_name' => 'pageview',
                    'points'     => '5',
                    'daily_cap'  => '3',
                    'active'     => '1',
                ),
                array(
                    'event_name' => 'dwell',
                    'points'     => '2',
                    'daily_cap'  => '1',
                    'active'     => '1',
                    'remove'     => '1',
                ),
                array(
                    'event_name' => '',
                    'points'     => '1',
                    'daily_cap'  => '3',
                    'active'     => '1',
                ),
                array(
                    'event_name' => 'conversion',
                    'points'     => '25',
                    'daily_cap'  => '1',
                    'active'     => '0',
                ),
            ),
            Gr_Scoring_Rules_Page::NONCE_FIELD => 'gr-stub-nonce-' . md5( Gr_Scoring_Rules_Page::NONCE_ACTION ),
        );
    }

    public function testSaveWithoutCapabilityDoesNothing(): void {
        $this->post_save();
        $GLOBALS['gr_stub_caps'] = false;

        Gr_Scoring_Rules_Page::handle_actions();

        $this->assertSame( array(), Gr_Scoring_Rules::all() );
        $this->assertSame( array(), $GLOBALS['gr_stub_redirects'] );
        $this->assertSame( array(), $GLOBALS['gr_stub_cron'] );
        $this->assertSame( array(), $GLOBALS['wpdb']->inserts );
    }

    public function testSaveWithoutNonceDoesNothing(): void {
        $this->post_save();
        $GLOBALS['gr_stub_nonce_bad'] = true;

        Gr_Scoring_Rules_Page::handle_actions();

        $this->assertSame( array(), Gr_Scoring_Rules::all() );
        $this->assertSame( array(), $GLOBALS['gr_stub_redirects'] );
        $this->assertSame( array(), $GLOBALS['gr_stub_cron'] );
        $this->assertSame( array(), $GLOBALS['wpdb']->inserts );
    }

    public function testValidSavePersistsTheRulesetQueuesRecomputeAndAudits(): void {
        $this->post_save();

        Gr_Scoring_Rules_Page::handle_actions();

        // The remove-marked row and the blank add-row dropped out;
        // the inactive row kept its flag.
        $rules = Gr_Scoring_Rules::all();
        $this->assertCount( 2, $rules );
        $this->assertSame(
            array(
                array(
                    'event_name' => 'pageview',
                    'points'     => 5,
                    'daily_cap'  => 3,
                    'active'     => true,
                ),
                array(
                    'event_name' => 'conversion',
                    'points'     => 25,
                    'daily_cap'  => 1,
                    'active'     => false,
                ),
            ),
            $rules
        );

        // A saved ruleset recomputes the stored scores in the queue,
        // never serves them stale.
        $this->assertNotEmpty( $GLOBALS['gr_stub_cron'] );
        $this->assertSame( Gr_Scoring_Engine::RECOMPUTE_HOOK, $GLOBALS['gr_stub_cron'][0]['hook'] );
        $this->assertSame( array( 0 ), $GLOBALS['gr_stub_cron'][0]['args'] );

        // Post-redirect-get back with the success flag.
        $this->assertCount( 1, $GLOBALS['gr_stub_redirects'] );
        $this->assertStringContainsString( 'page=greenpng-scoring', $GLOBALS['gr_stub_redirects'][0]['location'] );
        $this->assertStringContainsString( 'saved=1', $GLOBALS['gr_stub_redirects'][0]['location'] );

        // The save is audited with the persisted ruleset as the
        // after-picture.
        $audit = null;
        foreach ( $GLOBALS['wpdb']->inserts as $insert ) {
            if ( 'wp_gr_audit_logs' === $insert['table'] && 'save' === $insert['data']['action'] ) {
                $audit = $insert['data'];
            }
        }
        $this->assertNotNull( $audit );
        $this->assertSame( 'scoring_rules', $audit['object_type'] );
        $this->assertSame( 7, $audit['user_id'] );
    }

    public function testRefusedSaveFallsThroughWithTheReasonOnScreen(): void {
        $this->post_save();
        $_POST['gr_rule'][0]['points'] = '250';

        Gr_Scoring_Rules_Page::handle_actions();

        // Nothing persisted, no redirect: the submission stays for
        // correcting.
        $this->assertSame( array(), Gr_Scoring_Rules::all() );
        $this->assertSame( array(), $GLOBALS['gr_stub_redirects'] );

        ob_start();
        Gr_Scoring_Rules_Page::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'notice-error', $html );
        $this->assertStringContainsString( 'row 1', $html );
        // The corrected-for rows stay on screen, not the stored set.
        $this->assertStringContainsString( 'value="250"', $html );
    }

    public function testRenderShowsTheRulesetFormAndTheHonestWindow(): void {
        Gr_Scoring_Rules::save(
            array(
                array(
                    'event_name' => 'pageview',
                    'points'     => 5,
                    'daily_cap'  => 3,
                    'active'     => true,
                ),
            )
        );

        ob_start();
        Gr_Scoring_Rules_Page::render();
        $html = (string) ob_get_clean();

        // Native furniture: the widefat table, the nonce-carrying
        // form, the submit button.
        $this->assertStringContainsString( 'widefat striped', $html );
        $this->assertStringContainsString( 'name="' . Gr_Scoring_Rules_Page::NONCE_FIELD . '"', $html );
        $this->assertStringContainsString( 'p class="submit"', $html );

        // The stored row renders as inputs; the vocabulary select
        // carries every event name; one blank add-row is offered.
        $this->assertStringContainsString( 'value="5"', $html );
        foreach ( Gr_Scoring_Rules::VOCAB as $name ) {
            $this->assertStringContainsString( 'value="' . $name . '"', $html );
        }
        $this->assertStringContainsString( '— add a rule —', $html );
        $this->assertStringContainsString( 'Remove', $html );

        // The honest window: scores read the same 30-day window
        // retention keeps, and the bot verdict outranks the rules.
        $this->assertStringContainsString( 'last 30 days', $html );
        $this->assertStringContainsString( 'suspected bot', $html );
    }

    public function testSavedFlagRendersTheNotice(): void {
        $_GET['saved'] = '1';

        ob_start();
        Gr_Scoring_Rules_Page::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'notice-success', $html );
        $this->assertStringContainsString( 'background recompute', $html );
    }
}
