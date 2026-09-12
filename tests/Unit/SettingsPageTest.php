<?php
/**
 * Settings page (docs/13 U13, docs/06 Settings tabs): the three-tab
 * render, the per-tab field whitelists and clamps behind the double
 * gate, the uninstall flag as its own option, and the audit row
 * carrying what moved.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Admin\Gr_Settings_Page;
use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Core\Gr_Settings;
use GreenPNG\Storage\Gr_Uninstall;
use PHPUnit\Framework\TestCase;

final class SettingsPageTest extends TestCase {

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
     * A valid, gate-passing save POST body for one tab.
     *
     * @param string $tab Tab key.
     * @return void
     */
    private function post( string $tab ): void {
        $_POST = array(
            'gr_settings_action' => 'save',
            'tab'                => $tab,
            Gr_Settings_Page::NONCE_FIELD => 'gr-stub-nonce-' . md5( Gr_Settings_Page::NONCE_ACTION ),
        );
        $GLOBALS['gr_stub_caps'] = array( 'manage_options' );
    }

    /**
     * Audit rows this page's save wrote.
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

    public function testGeneralTabRendersPrivacyDefaultsAndEntries(): void {
        ob_start();
        Gr_Settings_Page::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'nav-tab-wrapper', $html );
        $this->assertStringContainsString( 'name="marketing_ip_anonymize"', $html );
        $this->assertStringContainsString( 'checked="checked"', $html );
        $this->assertStringContainsString( 'page=greenpng-retention', $html );
        $this->assertStringContainsString( 'name="delete_data_on_uninstall"', $html );
        $this->assertStringContainsString( 'name="_gr_settings_nonce"', $html );
    }

    public function testSecurityTabRendersEverySwitch(): void {
        $_GET = array( 'tab' => 'security' );

        ob_start();
        Gr_Settings_Page::render();
        $html = (string) ob_get_clean();

        foreach ( array( 'security_enabled', 'security_log_anonymize', 'trust_proxy_headers', 'trusted_proxies', 'probe_enabled', 'security_action_mode' ) as $field ) {
            $this->assertStringContainsString( 'name="' . $field . '"', $html );
        }
        $this->assertStringContainsString( 'value="block"', $html );
    }

    public function testAttributionTabRendersTheModelVocabulary(): void {
        $_GET = array( 'tab' => 'attribution' );

        ob_start();
        Gr_Settings_Page::render();
        $html = (string) ob_get_clean();

        foreach ( Gr_Settings_Page::MODELS as $model ) {
            $this->assertStringContainsString( 'value="' . $model . '"', $html );
        }
        $this->assertStringContainsString( 'name="attribution_cookie_days"', $html );
    }

    public function testGeneralSavePersistsSwitchesAndTheUninstallFlag(): void {
        $this->post( 'general' );
        $_POST['marketing_consent_fallback'] = '1';
        $_POST['delete_data_on_uninstall']   = '1';

        Gr_Settings_Page::handle_actions();

        $settings = new Gr_Settings();
        $this->assertSame( 1, (int) $settings->get( 'marketing_consent_fallback' ) );
        // Absent checkbox means off: the anonymize default flips.
        $this->assertSame( 0, (int) $settings->get( 'marketing_ip_anonymize' ) );
        $this->assertSame( '1', get_option( Gr_Uninstall::DELETE_FLAG_OPTION, '0' ) );

        // Audit: the flat snapshot diff records both moves.
        $rows = $this->audit_rows();
        $this->assertCount( 1, $rows );
        $decoded = json_decode( (string) $rows[0]['data']['diff_json'], true );
        $this->assertSame( array( 'old' => 0, 'new' => 1 ), $decoded['modified']['marketing_consent_fallback'] );
        $this->assertSame( array( 'old' => 1, 'new' => 0 ), $decoded['modified']['marketing_ip_anonymize'] );

        // PRG carries the tab and the flag.
        $this->assertCount( 1, $GLOBALS['gr_stub_redirects'] );
        $this->assertStringContainsString( 'tab=general', $GLOBALS['gr_stub_redirects'][0]['location'] );
        $this->assertStringContainsString( 'gr_saved=1', $GLOBALS['gr_stub_redirects'][0]['location'] );
    }

    public function testUninstallFlagOffRemovesTheOptionRow(): void {
        update_option( Gr_Uninstall::DELETE_FLAG_OPTION, '1' );

        $this->post( 'general' );
        // Every checkbox absent: defaults ride along, flag off.

        Gr_Settings_Page::handle_actions();

        $this->assertSame( '0', get_option( Gr_Uninstall::DELETE_FLAG_OPTION, '0' ) );
        // Absent checkbox is off: both privacy switches went to 0.
        $this->assertSame( 0, (int) ( new Gr_Settings() )->get( 'marketing_ip_anonymize' ) );
    }

    public function testSecuritySaveCleansTheProxyListAndVetoesForeignModes(): void {
        $this->post( 'security' );
        $_POST['security_enabled']         = '1';
        $_POST['security_action_mode']     = 'bogus-mode';
        $_POST['trusted_proxies']          = ' 10.0.0.1 , 192.168.0.0/24 , ,  ';
        $_POST['probe_enabled']            = '1';
        $_POST['security_log_anonymize']   = '1';
        $_POST['trust_proxy_headers']      = '1';

        Gr_Settings_Page::handle_actions();

        $settings = new Gr_Settings();
        $this->assertSame(
            array( '10.0.0.1', '192.168.0.0/24' ),
            (array) $settings->get( 'trusted_proxies' )
        );
        $this->assertSame( 'log', (string) $settings->get( 'security_action_mode' ) );
        $this->assertSame( 1, (int) $settings->get( 'probe_enabled' ) );
    }

    public function testSecuritySaveCanTurnTheProbeOffAndTheSwitchSticks(): void {
        $this->post( 'security' );
        $_POST['security_enabled']     = '1';
        $_POST['probe_enabled']        = '1';
        Gr_Settings_Page::handle_actions();

        // Off arm: the checkbox is absent from the POST.
        gr_stub_reset_options();
        $this->post( 'security' );
        $_POST['security_enabled'] = '1';
        Gr_Settings_Page::handle_actions();

        $settings = new Gr_Settings();
        $this->assertSame( 0, (int) $settings->get( 'probe_enabled' ) );
    }

    public function testAttributionSaveClampsTheWindowAndVetoesForeignModels(): void {
        $this->post( 'attribution' );
        $_POST['attribution_enabled']       = '1';
        $_POST['attribution_cookie_days']   = '400';
        $_POST['attribution_default_model'] = 'made-up-model';

        Gr_Settings_Page::handle_actions();

        $settings = new Gr_Settings();
        $this->assertSame( 365, (int) $settings->get( 'attribution_cookie_days' ) );
        $this->assertSame( 'last', (string) $settings->get( 'attribution_default_model' ) );
    }

    public function testUnchangedSaveWritesNoAuditRow(): void {
        // A save that moves nothing is a no-op in the trail: the
        // before/after snapshots match.
        $this->post( 'attribution' );
        $_POST['attribution_enabled']       = '1';
        $_POST['attribution_cookie_days']   = '30';
        $_POST['attribution_default_model'] = 'last';

        Gr_Settings_Page::handle_actions();

        $this->assertSame( array(), $this->audit_rows() );
    }

    public function testGateFailuresTouchNothing(): void {
        $this->post( 'general' );
        $_POST['marketing_consent_fallback'] = '1';
        $GLOBALS['gr_stub_caps'] = false;

        Gr_Settings_Page::handle_actions();

        $this->assertSame( array(), $GLOBALS['wpdb']->inserts );
        $this->assertSame( array(), $GLOBALS['gr_stub_redirects'] );
        $this->assertSame( 0, (int) ( new Gr_Settings() )->get( 'marketing_consent_fallback' ) );
    }

    public function testMenuPutsSettingsFirst(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        do_action( 'admin_menu' );
        $slugs = array();
        foreach ( $GLOBALS['gr_stub_submenu_pages'] as $page ) {
            if ( 'greenpng-dashboard' === (string) $page['parent_slug'] ) {
                $slugs[] = (string) $page['menu_slug'];
            }
        }
        $this->assertSame( 'greenpng-settings', $slugs[0] );

        $hooks = array();
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            $hooks[] = (string) $registration['hook'];
        }
        $this->assertContains( 'admin_init', $hooks );
    }
}
