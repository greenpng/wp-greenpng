<?php
/**
 * Contacts and Contact Profile admin pages (ADR-0013 D5, docs/06
 * Audience tree): the masked list with segment/tag filters, the tag
 * vocabulary tab, the RFM distribution tab, the audited reveal and
 * rescore writes behind the double gate, and the timeline render.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Admin\Gr_Contact_Profile_Page;
use GreenPNG\Admin\Gr_Contacts_Page;
use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Core\Gr_Secrets;
use GreenPNG\Storage\Gr_Contact_Repository;
use PHPUnit\Framework\TestCase;

final class ContactsPageTest extends TestCase {

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
    }

    protected function tearDown(): void {
        unset( $_POST, $_GET );
        parent::tearDown();
    }

    /**
     * One staged contact row shape for list reads.
     *
     * @return array<int, array<string, string>>
     */
    private function contact_rows(): array {
        return array(
            array(
                'id'          => '9',
                'email_enc'   => Gr_Secrets::encrypt( 'ada@example.com' ),
                'first_name'  => 'Ada',
                'last_name'   => 'Lovelace',
                'lead_score'  => '42',
                'rfm_segment' => 'new',
                'ltv'         => '0.00',
                'first_seen'  => '2026-09-10 09:00:00',
                'last_seen'   => '2026-09-12 09:00:00',
            ),
        );
    }

    public function testListTabRendersMaskedRowsAndFilters(): void {
        $GLOBALS['wpdb']->var_result = '7';
        $GLOBALS['wpdb']->results    = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'FROM wp_gr_tags' ) ) {
                return array(
                    array(
                        'id'        => '2',
                        'slug'      => 'sys:form:fluentform',
                        'name'      => 'Fluent Forms lead',
                        'is_system' => '1',
                        'contacts'  => '1',
                    ),
                );
            }

            // The list page query: newest first.
            if ( false !== strpos( $sql, 'ORDER BY id DESC' ) ) {
                return array(
                    array(
                        'id'          => '9',
                        'email_enc'   => Gr_Secrets::encrypt( 'ada@example.com' ),
                        'first_name'  => 'Ada',
                        'last_name'   => 'Lovelace',
                        'lead_score'  => '42',
                        'rfm_segment' => 'new',
                        'ltv'         => '0.00',
                        'first_seen'  => '2026-09-10 09:00:00',
                        'last_seen'   => '2026-09-12 09:00:00',
                    ),
                );
            }

            return array();
        };

        ob_start();
        Gr_Contacts_Page::render();
        $html = (string) ob_get_clean();

        // Native furniture: tabs, the filter form, the list table.
        $this->assertStringContainsString( 'nav-tab-wrapper', $html );
        $this->assertStringContainsString( 'wp-list-table', $html );
        $this->assertStringContainsString( 'gr-segment-filter', $html );
        $this->assertStringContainsString( 'gr-tag-filter', $html );

        // The email renders masked; the plaintext never leaves the
        // server on this page.
        $this->assertStringContainsString( Gr_Secrets::mask( 'ada@example.com' ), $html );
        $this->assertStringNotContainsString( 'ada@example.com', $html );

        // The tag filter carries the vocabulary; the inline entry
        // into the profile is the row action.
        $this->assertStringContainsString( 'sys:form:fluentform', $html );
        $this->assertStringContainsString( 'Open profile', $html );
        $this->assertStringContainsString( 'contact_id=9', $html );

        // The score, segment, and display-name cells render.
        $this->assertStringContainsString( '>42<', $html );
        $this->assertStringContainsString( '>new<', $html );
        $this->assertStringContainsString( '>Ada Lovelace<', $html );
    }

    public function testSegmentFilterIsWhitelistedBeforeTheRepository(): void {
        $_GET['segment'] = 'drop table';

        $GLOBALS['wpdb']->var_result = '0';
        $GLOBALS['wpdb']->results    = array();

        ob_start();
        Gr_Contacts_Page::render();
        (string) ob_get_clean();

        // A word outside the eight-word vocabulary reads as "all":
        // no unknown segment reaches the query.
        $filtered = '';
        foreach ( $GLOBALS['wpdb']->queries as $sql ) {
            if ( false !== strpos( (string) $sql, 'rfm_segment =' ) ) {
                $filtered = (string) $sql;
            }
        }
        $this->assertSame( '', $filtered );
    }

    public function testTagsTabRendersTheVocabularyAndTheContract(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'FROM wp_gr_tags' ) ) {
                return array(
                    array(
                        'id'        => '1',
                        'slug'      => 'newsletter',
                        'name'      => 'Newsletter',
                        'is_system' => '0',
                        'contacts'  => '12',
                    ),
                    array(
                        'id'        => '2',
                        'slug'      => 'sys:suspected_bot',
                        'name'      => 'Suspected bot',
                        'is_system' => '1',
                        'contacts'  => '1',
                    ),
                );
            }

            return array();
        };

        $_GET['tab'] = 'tags';
        ob_start();
        Gr_Contacts_Page::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'sys:suspected_bot', $html );
        $this->assertStringContainsString( 'Newsletter', $html );
        $this->assertStringContainsString( 'System', $html );
        $this->assertStringContainsString( 'Custom', $html );
        // The attach-only contract is stated where the owner reads it.
        $this->assertStringContainsString( 'removal is your call', $html );
    }

    public function testRfmTabCountsTheStoredSegments(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'ORDER BY id ASC' ) ) {
                return array(
                    array( 'id' => '1', 'visitor_id' => 'v1', 'last_seen' => '2026-09-12 09:00:00', 'rfm_segment' => 'new', 'ltv' => '0.00' ),
                    array( 'id' => '2', 'visitor_id' => 'v2', 'last_seen' => '2026-09-11 09:00:00', 'rfm_segment' => 'new', 'ltv' => '0.00' ),
                    array( 'id' => '3', 'visitor_id' => 'v3', 'last_seen' => '2026-09-10 09:00:00', 'rfm_segment' => '', 'ltv' => '0.00' ),
                );
            }

            return array();
        };

        $_GET['tab'] = 'rfm';
        ob_start();
        Gr_Contacts_Page::render();
        $html = (string) ob_get_clean();

        // The count is computed, not hard-coded.
        $this->assertStringContainsString( '<td>new</td>', $html );
        $this->assertStringContainsString( '<td>2</td>', $html );
        $this->assertStringContainsString( '<td>champions</td>', $html );
        $this->assertStringContainsString( '<td>0</td>', $html );
        // Unsegmented rows are named, not silently dropped.
        $this->assertStringContainsString( 'Not yet segmented', $html );
        $this->assertStringContainsString( '<td>1</td>', $html );
    }

    public function testAudienceMenuWiringFollowsTheDocsTree(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        do_action( 'admin_menu' );
        $slugs = array();
        foreach ( $GLOBALS['gr_stub_submenu_pages'] as $page ) {
            $slugs[] = $page['parent_slug'] . ':' . $page['menu_slug'];
        }

        // Contacts opens the Audience section, the profile nests
        // under it, and Behavior Insights closes it.
        $this->assertContains( 'greenpng-dashboard:greenpng-contacts', $slugs );
        $this->assertContains( 'greenpng-contacts:greenpng-contact-profile', $slugs );
        $this->assertContains( 'greenpng-dashboard:greenpng-scoring', $slugs );
        $this->assertContains( 'greenpng-dashboard:greenpng-behavior', $slugs );

        $contacts  = array_search( 'greenpng-dashboard:greenpng-contacts', $slugs, true );
        $scoring   = array_search( 'greenpng-dashboard:greenpng-scoring', $slugs, true );
        $behavior  = array_search( 'greenpng-dashboard:greenpng-behavior', $slugs, true );
        $this->assertLessThan( $scoring, $contacts );
        $this->assertLessThan( $behavior, $scoring );

        Gr_Plugin::reset_instance();
    }
}
