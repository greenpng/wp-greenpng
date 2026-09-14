<?php
/**
 * Contact Profile admin page (ADR-0013 D5, docs/06 Audience tree):
 * the double gate on the reveal and rescore writes, the audit rows
 * they leave, the masked-versus-revealed email display, and the
 * visitor timeline render.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Admin\Gr_Contact_Profile_Page;
use GreenPNG\Core\Gr_Secrets;
use GreenPNG\CRM\Gr_Scoring_Rules;
use PHPUnit\Framework\TestCase;

final class ContactProfilePageTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();

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

        // One scoring rule so the rescore path has real input.
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
    }

    protected function tearDown(): void {
        unset( $_POST, $_GET );
        parent::tearDown();
    }

    /**
     * The staged contact row for every read on this page.
     *
     * @return array<int, array<string, string>>
     */
    private function contact_row_set(): array {
        return array(
            array(
                'id'          => '9',
                'email_enc'   => Gr_Secrets::encrypt( 'ada@example.com' ),
                'first_name'  => 'Ada',
                'last_name'   => 'Lovelace',
                'user_id'     => '0',
                'visitor_id'  => 'v-ada',
                'lead_score'  => '42',
                'ltv'         => '0.00',
                'rfm_segment' => 'new',
                'first_seen'  => '2026-09-10 09:00:00',
                'last_seen'   => '2026-09-12 09:00:00',
            ),
        );
    }

    /**
     * A gate-passing POST body for one of the two writes.
     *
     * @param string $action Reveal or rescore.
     * @return void
     */
    private function post_action( string $action ): void {
        $_POST = array(
            'gr_profile_action' => $action,
            'contact_id'        => '9',
            Gr_Contact_Profile_Page::NONCE_FIELD => 'gr-stub-nonce-' . md5( Gr_Contact_Profile_Page::NONCE_ACTION ),
        );
        $GLOBALS['wpdb']->results = $this->contact_row_set();
    }

    public function testRevealWithoutCapabilityDoesNothing(): void {
        $this->post_action( Gr_Contact_Profile_Page::ACTION_REVEAL );
        $GLOBALS['gr_stub_caps'] = false;

        Gr_Contact_Profile_Page::handle_actions();

        $this->assertSame( array(), $GLOBALS['wpdb']->inserts );
        $this->assertSame( array(), $GLOBALS['gr_stub_redirects'] );
    }

    public function testRevealWithoutNonceDoesNothing(): void {
        $this->post_action( Gr_Contact_Profile_Page::ACTION_REVEAL );
        $GLOBALS['gr_stub_nonce_bad'] = true;

        Gr_Contact_Profile_Page::handle_actions();

        $this->assertSame( array(), $GLOBALS['wpdb']->inserts );
        $this->assertSame( array(), $GLOBALS['gr_stub_redirects'] );
    }

    public function testValidRevealAuditsTheMaskAndRedirects(): void {
        $this->post_action( Gr_Contact_Profile_Page::ACTION_REVEAL );

        Gr_Contact_Profile_Page::handle_actions();

        // The audit row proves who revealed; it carries the mask,
        // never the plaintext.
        $audit = null;
        foreach ( $GLOBALS['wpdb']->inserts as $insert ) {
            if ( 'wp_gr_audit_logs' === $insert['table'] && 'reveal' === $insert['data']['action'] ) {
                $audit = $insert['data'];
            }
        }
        $this->assertNotNull( $audit );
        $this->assertSame( 'contact_email', $audit['object_type'] );
        $this->assertSame( '9', (string) $audit['object_id'] );
        $diff = json_decode( (string) $audit['diff_json'], true );
        $this->assertSame( Gr_Secrets::mask( 'ada@example.com' ), $diff['added']['masked'] );

        $this->assertCount( 1, $GLOBALS['gr_stub_redirects'] );
        $this->assertStringContainsString( 'contact_id=9', $GLOBALS['gr_stub_redirects'][0]['location'] );
        $this->assertStringContainsString( 'revealed=1', $GLOBALS['gr_stub_redirects'][0]['location'] );
    }

    public function testValidRescoreRunsTheEnginesAndAudits(): void {
        $this->post_action( Gr_Contact_Profile_Page::ACTION_RESCORE );

        // Per-query staging: the contact sits unsegmented so the RFM
        // movement write is real, and two pageviews on one day feed
        // the rule (5 points, cap 3).
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'WHERE id = 9' ) ) {
                return array(
                    array(
                        'id'          => '9',
                        'email_enc'   => Gr_Secrets::encrypt( 'ada@example.com' ),
                        'first_name'  => 'Ada',
                        'last_name'   => 'Lovelace',
                        'user_id'     => '0',
                        'visitor_id'  => 'v-ada',
                        'lead_score'  => '42',
                        'ltv'         => '0.00',
                        'rfm_segment' => '',
                        'first_seen'  => '2026-09-10 09:00:00',
                        'last_seen'   => '2026-09-12 09:00:00',
                    ),
                );
            }

            if ( false !== strpos( $sql, 'FROM wp_gr_events' ) ) {
                return array(
                    array(
                        'event_name' => 'pageview',
                        'day'        => '2026-09-12',
                        'n'          => '2',
                    ),
                );
            }

            if ( false !== strpos( $sql, 'FROM wp_gr_conversions' ) ) {
                return array();
            }

            // The RFM population read.
            if ( false !== strpos( $sql, 'ORDER BY id ASC' ) ) {
                return array(
                    array( 'id' => '9', 'visitor_id' => 'v-ada', 'last_seen' => '2026-09-12 09:00:00', 'rfm_segment' => '', 'ltv' => '0.00' ),
                );
            }

            return array();
        };

        Gr_Contact_Profile_Page::handle_actions();

        $audit = null;
        foreach ( $GLOBALS['wpdb']->inserts as $insert ) {
            if ( 'wp_gr_audit_logs' === $insert['table'] && 'rescore' === $insert['data']['action'] ) {
                $audit = $insert['data'];
            }
        }
        $this->assertNotNull( $audit );
        $this->assertSame( 'contact_score', $audit['object_type'] );

        // The score write carries the recomputed points; the RFM
        // movement write lands the segment.
        $updates = '';
        foreach ( $GLOBALS['wpdb']->queries as $sql ) {
            if ( 0 === strpos( (string) $sql, 'UPDATE wp_gr_contacts' ) ) {
                $updates .= (string) $sql . "\n";
            }
        }
        $this->assertStringContainsString( 'lead_score = 10', $updates );
        $this->assertStringContainsString( "rfm_segment = 'new'", $updates );

        $this->assertCount( 1, $GLOBALS['gr_stub_redirects'] );
        $this->assertStringContainsString( 'rescored=1', $GLOBALS['gr_stub_redirects'][0]['location'] );
    }

    public function testUnknownContactRenderExplainsItself(): void {
        $_GET['contact_id'] = '404';

        ob_start();
        Gr_Contact_Profile_Page::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'No contact matches this id', $html );
        $this->assertStringContainsString( 'Back to Contacts', $html );
    }

    public function testRenderShowsMaskedEmailScoreAxesAndTimeline(): void {
        $_GET['contact_id'] = '9';

        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            // The profile header row.
            if ( false !== strpos( $sql, 'WHERE id = 9' ) ) {
                return array(
                    array(
                        'id'          => '9',
                        'email_enc'   => Gr_Secrets::encrypt( 'ada@example.com' ),
                        'first_name'  => 'Ada',
                        'last_name'   => 'Lovelace',
                        'user_id'     => '0',
                        'visitor_id'  => 'v-ada',
                        'lead_score'  => '42',
                        'ltv'         => '0.00',
                        'rfm_segment' => 'new',
                        'first_seen'  => '2026-09-10 09:00:00',
                        'last_seen'   => '2026-09-12 09:00:00',
                    ),
                );
            }

            // The RFM population: a solo contact.
            if ( false !== strpos( $sql, 'ORDER BY id ASC' ) ) {
                return array(
                    array( 'id' => '9', 'visitor_id' => 'v-ada', 'last_seen' => '2026-09-12 09:00:00', 'rfm_segment' => 'new', 'ltv' => '0.00' ),
                );
            }

            // Conversion value counts: none.
            if ( false !== strpos( $sql, 'FROM wp_gr_conversions' ) && false !== strpos( $sql, 'GROUP BY' ) ) {
                return array();
            }

            // Tag slugs.
            if ( false !== strpos( $sql, 'INNER JOIN wp_gr_contact_tags' ) ) {
                return array( array( 'slug' => 'sys:form:fluentform' ) );
            }

            // Timeline events.
            if ( false !== strpos( $sql, 'FROM wp_gr_events' ) ) {
                return array(
                    array(
                        'id'         => '101',
                        'event_name' => 'pageview',
                        'created_at' => '2026-09-12 09:00:01',
                    ),
                );
            }

            // Conversions.
            if ( false !== strpos( $sql, 'FROM wp_gr_conversions' ) && false !== strpos( $sql, 'WHERE visitor_id' ) ) {
                return array(
                    array(
                        'id'         => '31',
                        'source_type' => 'fluentform',
                        'source_id'  => '1',
                        'amount'     => '0.00',
                        'currency'   => '',
                        'status'     => 'active',
                        'created_at' => '2026-09-12 09:01:00',
                    ),
                );
            }

            // Sessions.
            if ( false !== strpos( $sql, 'FROM wp_gr_sessions' ) ) {
                return array(
                    array(
                        'started_at'  => '2026-09-12 09:00:00',
                        'last_active' => '2026-09-12 09:05:00',
                        'device_type' => 'desktop',
                        'country_code' => 'US',
                    ),
                );
            }

            return array();
        };

        ob_start();
        Gr_Contact_Profile_Page::render();
        $html = (string) ob_get_clean();

        // Masked by default, with the audited reveal offer.
        $this->assertStringContainsString( Gr_Secrets::mask( 'ada@example.com' ), $html );
        $this->assertStringNotContainsString( 'ada@example.com', $html );
        $this->assertStringContainsString( 'Reveal email', $html );

        // Name, score with its window note, and the RFM axes.
        $this->assertStringContainsString( 'Ada Lovelace', $html );
        $this->assertStringContainsString( '42 of 100', $html );
        $this->assertStringContainsString( 'r 5 / f 1 / m 1 — new', $html );
        $this->assertStringContainsString( 'Recompute now', $html );

        // The tag and the binding.
        $this->assertStringContainsString( 'sys:form:fluentform', $html );
        $this->assertStringContainsString( 'v-ada', $html );

        // The three timeline sections with their rows.
        $this->assertStringContainsString( 'Events, newest 30', $html );
        $this->assertStringContainsString( 'pageview', $html );
        $this->assertStringContainsString( 'fluentform #1', $html );
        $this->assertStringContainsString( 'desktop', $html );
    }

    public function testRevealedFlagShowsThePlaintextForOneView(): void {
        $_GET['contact_id'] = '9';
        $_GET['revealed']   = '1';
        $GLOBALS['wpdb']->results = $this->contact_row_set();

        ob_start();
        Gr_Contact_Profile_Page::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'ada@example.com', $html );
        $this->assertStringContainsString( 'Revealed for this view only', $html );
        $this->assertStringContainsString( 'audit log', $html );
    }
}
