<?php
/**
 * IP Intelligence page (docs/13 U16, docs/06 §1, docs/07 §5.7): the
 * disclosure and version render over the real bundled state, the
 * gated update arm that only ever enqueues the refresh job, the
 * duplicate-click dedupe, and the standing rule that the page itself
 * carries no outbound surface.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Admin\Gr_Admin_Menu;
use GreenPNG\Admin\Gr_Ip_Intel_Page;
use GreenPNG\Core\Gr_Geoip_Refresh;
use GreenPNG\Core\Gr_Plugin;
use PHPUnit\Framework\TestCase;

final class IpIntelPageTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        unset( $_POST, $_GET );
    }

    protected function tearDown(): void {
        unset( $_POST, $_GET );
        parent::tearDown();
    }

    /**
     * A valid, gate-passing update POST.
     *
     * @return void
     */
    private function post_update(): void {
        $_POST = array(
            'gr_ipintel_action' => Gr_Ip_Intel_Page::ACTION_UPDATE,
            Gr_Ip_Intel_Page::NONCE_FIELD => 'gr-stub-nonce-' . md5( Gr_Ip_Intel_Page::NONCE_ACTION ),
        );
        $_REQUEST                     = $_POST;
        $GLOBALS['gr_stub_caps']      = array( 'manage_options' );
        $GLOBALS['gr_stub_user_id']   = 7;
    }

    /**
     * The last redirect location.
     *
     * @return string
     */
    private function redirected_to(): string {
        $last = end( $GLOBALS['gr_stub_redirects'] );

        return (string) ( false === $last ? '' : $last['location'] );
    }

    /**
     * Audit rows this page wrote.
     *
     * @return array<int, array<string, mixed>>
     */
    private function audit_rows(): array {
        $rows = array();
        foreach ( $GLOBALS['wpdb']->inserts as $insert ) {
            if ( 'wp_gr_audit_logs' === $insert['table'] ) {
                $rows[] = $insert;
            }
        }

        return $rows;
    }

    public function testRenderShowsStateDisclosureAndTheGatedButton(): void {
        ob_start();
        Gr_Ip_Intel_Page::render();
        $html = (string) ob_get_clean();

        // The version block reads the real bundled data set.
        $this->assertStringContainsString( 'Bundled with the plugin', $html );
        $this->assertStringContainsString( '2026-09', $html );
        $this->assertStringContainsString( '357,325', $html );
        $this->assertStringContainsString( '359,845', $html );

        // The attribution disclosure names the source and license.
        $this->assertStringContainsString( 'DB-IP', $html );
        $this->assertStringContainsString( 'CC BY 4.0', $html );
        $this->assertStringContainsString( 'creativecommons.org/licenses/by/4.0', $html );

        // The update form is nonce-gated and says what the click does.
        $this->assertStringContainsString( 'name="' . Gr_Ip_Intel_Page::NONCE_FIELD . '"', $html );
        $this->assertStringContainsString( 'name="gr_ipintel_action"', $html );
        $this->assertStringContainsString( 'no automatic or scheduled update', $html );

        // The v1.2 note is a roadmap line, not a placeholder control.
        $this->assertStringContainsString( 'AbuseIPDB', $html );
        $this->assertStringNotContainsString( 'name="abuseipdb', $html );
    }

    public function testPendingRefreshReplacesTheButtonWithAStateLine(): void {
        set_transient( Gr_Geoip_Refresh::PENDING, time(), 3600 );

        ob_start();
        Gr_Ip_Intel_Page::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'already queued', $html );
        $this->assertStringNotContainsString( 'name="gr_ipintel_action"', $html );
    }

    public function testPrgNoticesRenderForBothOutcomes(): void {
        $_GET = array( 'gr_update' => 'queued' );
        ob_start();
        Gr_Ip_Intel_Page::render();
        $queued = (string) ob_get_clean();
        $this->assertStringContainsString( 'notice-success', $queued );
        $this->assertStringContainsString( 'refresh queued', $queued );

        $_GET = array( 'gr_update' => 'pending' );
        ob_start();
        Gr_Ip_Intel_Page::render();
        $pending = (string) ob_get_clean();
        $this->assertStringContainsString( 'notice-warning', $pending );
        $this->assertStringContainsString( 'already queued', $pending );
    }

    public function testValidClickEnqueuesOnceAndRedirects(): void {
        $this->post_update();

        Gr_Ip_Intel_Page::handle_actions();

        // One queue dispatch happened and the pending flag is up.
        $this->assertCount( 1, $GLOBALS['gr_stub_cron'] );
        $this->assertSame( Gr_Geoip_Refresh::HOOK, $GLOBALS['gr_stub_cron'][0]['hook'] );
        $this->assertNotFalse( get_transient( Gr_Geoip_Refresh::PENDING ) );

        // The click itself is audited with the acting user, and the
        // diff states the queue outcome in words.
        $rows = $this->audit_rows();
        $this->assertCount( 1, $rows );
        $this->assertSame( 'update_requested', $rows[0]['data']['action'] );
        $this->assertSame( 7, (int) $rows[0]['data']['user_id'] );
        $this->assertStringContainsString( 'queued', (string) $rows[0]['data']['diff_json'] );

        // PRG with the queued outcome.
        $this->assertStringContainsString( 'page=' . Gr_Ip_Intel_Page::SLUG, $this->redirected_to() );
        $this->assertStringContainsString( 'gr_update=queued', $this->redirected_to() );
    }

    public function testDuplicateClickIsRefusedWithoutASecondDispatch(): void {
        $this->post_update();
        set_transient( Gr_Geoip_Refresh::PENDING, time(), 3600 );

        Gr_Ip_Intel_Page::handle_actions();

        $this->assertSame( array(), $GLOBALS['gr_stub_cron'] );
        $this->assertStringContainsString( 'gr_update=pending', $this->redirected_to() );

        // The refused click is still audited, with its own word.
        $rows = $this->audit_rows();
        $this->assertCount( 1, $rows );
        $this->assertStringContainsString( 'already_pending', (string) $rows[0]['data']['diff_json'] );
    }

    public function testNoCapabilityMeansNoDispatchAndNoRedirect(): void {
        $this->post_update();
        $GLOBALS['gr_stub_caps'] = array();

        Gr_Ip_Intel_Page::handle_actions();

        $this->assertSame( array(), $GLOBALS['gr_stub_cron'] );
        $this->assertSame( array(), $GLOBALS['gr_stub_redirects'] );
        $this->assertSame( array(), $this->audit_rows() );
    }

    public function testBadNonceNeverDispatches(): void {
        $this->post_update();
        $_POST[ Gr_Ip_Intel_Page::NONCE_FIELD ] = 'forged';

        Gr_Ip_Intel_Page::handle_actions();

        $this->assertSame( array(), $GLOBALS['gr_stub_cron'] );
        $this->assertSame( array(), $GLOBALS['gr_stub_redirects'] );
        $this->assertSame( array(), $this->audit_rows() );

        // The refusal happened before any enqueue, so no pending flag.
        $this->assertFalse( get_transient( Gr_Geoip_Refresh::PENDING ) );
    }

    public function testMenuEntrySitsInTheIntegrationsSection(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        do_action( 'admin_menu' );

        $found = false;
        foreach ( $GLOBALS['gr_stub_submenu_pages'] as $entry ) {
            if ( Gr_Ip_Intel_Page::SLUG === (string) $entry['menu_slug'] ) {
                $found = true;
                $this->assertSame( Gr_Admin_Menu::SLUG, $entry['parent_slug'] );
                $this->assertSame( 'manage_options', $entry['capability'] );
                $this->assertSame( array( Gr_Ip_Intel_Page::class, 'render' ), $entry['callback'] );
            }
        }
        $this->assertTrue( $found, 'the IP Intelligence submenu entry must register' );

        Gr_Plugin::reset_instance();
        Gr_Admin_Menu::reset_for_tests();
    }

    public function testPageSourceCarriesNoOutboundSurface(): void {
        $source = (string) file_get_contents( GR_PLUGIN_DIR . 'includes/admin/class-gr-ip-intel-page.php' );

        // The page enqueues a job; it never talks to the network
        // itself (docs/07 §2: one door).
        $this->assertStringNotContainsString( 'wp_remote_', $source );
        $this->assertStringNotContainsString( 'wp_safe_remote_', $source );
        $this->assertStringNotContainsString( 'curl_', $source );
        $this->assertStringNotContainsString( 'fsockopen', $source );
    }
}
