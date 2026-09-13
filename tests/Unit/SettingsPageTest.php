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

    public function testSecurityTabRendersTheEngineDials(): void {
        $_GET = array( 'tab' => 'security' );

        ob_start();
        Gr_Settings_Page::render();
        $html = (string) ob_get_clean();

        // The five keys that were settings with no writer until
        // v1.0.1 (the G1 gap, ADR-0009 D5).
        foreach ( array( 'bot_verdict_threshold', 'login_fail_threshold', 'login_lockout_base', 'honeypot_enabled', 'blackhole_enabled' ) as $field ) {
            $this->assertStringContainsString( 'name="' . $field . '"', $html );
        }

        // The defaults are visible as values, not mysteries.
        $this->assertStringContainsString( 'value="70"', $html );
        $this->assertStringContainsString( 'value="5"', $html );
        $this->assertStringContainsString( 'value="300"', $html );
    }

    public function testSecuritySaveClampsTheEngineDialsAndFlipsTheTraps(): void {
        $this->post( 'security' );
        $_POST['security_enabled']      = '1';
        $_POST['login_fail_threshold']  = '1';
        $_POST['login_lockout_base']    = '999999';
        $_POST['bot_verdict_threshold'] = '250';
        $_POST['honeypot_enabled']      = '1';
        $_POST['blackhole_enabled']     = '1';

        Gr_Settings_Page::handle_actions();

        $settings = new Gr_Settings();
        $this->assertSame( 2, (int) $settings->get( 'login_fail_threshold' ) );
        $this->assertSame( 86400, (int) $settings->get( 'login_lockout_base' ) );
        $this->assertSame( 100, (int) $settings->get( 'bot_verdict_threshold' ) );
        $this->assertSame( 1, (int) $settings->get( 'honeypot_enabled' ) );
        $this->assertSame( 1, (int) $settings->get( 'blackhole_enabled' ) );

        // The audit diff carries the dial moves like any other save.
        $rows = $this->audit_rows();
        $this->assertCount( 1, $rows );
        $decoded = json_decode( (string) $rows[0]['data']['diff_json'], true );
        $this->assertArrayHasKey( 'bot_verdict_threshold', $decoded['modified'] );
    }

    public function testAbsentDialsFallBackToTheirDefaults(): void {
        // Dials first set away from default, so the fallback is
        // distinguishable from "nothing happened".
        ( new Gr_Settings() )->set( 'login_fail_threshold', 9 );

        $this->post( 'security' );
        $_POST['security_enabled'] = '1';

        Gr_Settings_Page::handle_actions();

        // A foreign POST shape without the dial fields lands on the
        // recorded defaults, never on a zero.
        $settings = new Gr_Settings();
        $this->assertSame( 5, (int) $settings->get( 'login_fail_threshold' ) );
        $this->assertSame( 300, (int) $settings->get( 'login_lockout_base' ) );
        $this->assertSame( 70, (int) $settings->get( 'bot_verdict_threshold' ) );
        $this->assertSame( 0, (int) $settings->get( 'honeypot_enabled' ) );
    }

    public function testEveryScalarSettingKeyHasAnAdminWriter(): void {
        // The G1 invariant (ADR-0009 D5): a settings key no admin page
        // can write is a dial the owner cannot turn. Keys owned by
        // other pages are the documented exceptions.
        $owned_elsewhere = array( 'retention_days', 'retention_rows', 'capi_meta_enabled', 'capi_ga4_enabled' );

        $method   = new \ReflectionMethod( Gr_Settings_Page::class, 'snapshot' );
        if ( PHP_VERSION_ID < 80100 ) {
            // No effect (and no complaint) from 8.1 on; still required
            // to invoke a private method on the PHP 7.4 floor.
            $method->setAccessible( true );
        }
        $snapshot = $method->invoke( null );
        $snapshot = is_array( $snapshot ) ? $snapshot : array();

        $missing = array();
        foreach ( Gr_Settings::defaults() as $key => $value ) {
            if ( is_array( $value ) || in_array( $key, $owned_elsewhere, true ) ) {
                continue;
            }
            if ( ! array_key_exists( $key, $snapshot ) ) {
                $missing[] = (string) $key;
            }
        }

        $this->assertSame(
            array(),
            $missing,
            'settings keys with no admin writer: ' . implode( ', ', $missing )
        );
    }
}
