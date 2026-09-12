<?php
/**
 * Analytics & CAPI page (docs/13 U15, docs/07 §5, docs/10 §2): the
 * explicit not-configured state, credential storage through Gr_Secrets
 * only (encrypted envelope, autoload=no, never echoed back), the
 * empty-means-unchanged save semantics, whole-pair refusal, shape
 * gates, the remove arm, the local self-check battery, and the audit
 * trail carrying state words instead of values.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Admin\Gr_Analytics_Page;
use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Core\Gr_Secrets;
use GreenPNG\Core\Gr_Settings;
use PHPUnit\Framework\TestCase;

final class AnalyticsPageTest extends TestCase {

    /** A well-formed Meta credential pair. */
    private const META_PIXEL = '1234567890123456';

    /** A well-formed Meta token shape. */
    private const META_TOKEN = 'EAAG-system-user-token-0123456789abcdef';

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
     * A valid, gate-passing POST body.
     *
     * @param string $action One of the page actions.
     * @return void
     */
    private function post( string $action ): void {
        $_POST = array(
            'gr_analytics_action' => $action,
            Gr_Analytics_Page::NONCE_FIELD => 'gr-stub-nonce-' . md5( Gr_Analytics_Page::NONCE_ACTION ),
        );
        $GLOBALS['gr_stub_caps'] = array( 'manage_options' );
    }

    /**
     * The last redirect the page issued, as 'location'.
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

    public function testUnconfiguredRenderStatesItExplicitly(): void {
        ob_start();
        Gr_Analytics_Page::render();
        $html = (string) ob_get_clean();

        // Both blocks declare their state instead of leaving a blank.
        $this->assertSame( 2, substr_count( $html, 'Not configured.' ) );
        $this->assertStringContainsString( 'Meta Conversions API', $html );
        $this->assertStringContainsString( 'GA4 Measurement Protocol', $html );

        // The standing disclosure: opt-in, consent-gated, queued, and
        // the honest scope of the v1.0 self-check.
        $this->assertStringContainsString( 'consent', $html );
        $this->assertStringContainsString( 'local', $html );

        // All four credential fields, both toggles, both removes, and
        // the two action buttons per block.
        foreach ( array( 'meta_id', 'meta_secret', 'ga4_id', 'ga4_secret' ) as $field ) {
            $this->assertStringContainsString( 'name="' . $field . '"', $html );
        }
        $this->assertStringContainsString( 'name="capi_meta_enabled"', $html );
        $this->assertStringContainsString( 'name="capi_ga4_enabled"', $html );
        $this->assertStringContainsString( 'value="save_meta"', $html );
        $this->assertStringContainsString( 'value="check_ga4"', $html );
        $this->assertStringContainsString( 'name="' . Gr_Analytics_Page::NONCE_FIELD . '"', $html );
    }

    public function testSaveStoresEncryptedNeverPlaintext(): void {
        $this->post( 'save_meta' );
        $_POST['capi_meta_enabled'] = '1';
        $_POST['meta_id']           = self::META_PIXEL;
        $_POST['meta_secret']       = self::META_TOKEN;

        Gr_Analytics_Page::handle_actions();

        // Both credentials landed, autoload=no, as envelopes.
        $this->assertSame( 'no', $GLOBALS['gr_stub_options']['autoload'][ Gr_Analytics_Page::META_PIXEL_OPTION ] );
        $this->assertSame( 'no', $GLOBALS['gr_stub_options']['autoload'][ Gr_Analytics_Page::META_TOKEN_OPTION ] );
        $this->assertNotSame( self::META_PIXEL, $GLOBALS['gr_stub_options']['data'][ Gr_Analytics_Page::META_PIXEL_OPTION ] );
        $this->assertStringNotContainsString( self::META_TOKEN, (string) $GLOBALS['gr_stub_options']['data'][ Gr_Analytics_Page::META_TOKEN_OPTION ] );

        // The round-trip still reads the values back.
        $this->assertSame( self::META_PIXEL, Gr_Secrets::reveal( Gr_Analytics_Page::META_PIXEL_OPTION ) );
        $this->assertSame( self::META_TOKEN, Gr_Secrets::reveal( Gr_Analytics_Page::META_TOKEN_OPTION ) );

        // The toggle moved with the save.
        $this->assertSame( 1, (int) ( new Gr_Settings() )->get( 'capi_meta_enabled' ) );

        // Post-redirect-get carries the service word.
        $this->assertStringContainsString( 'gr_saved=meta', $this->redirected_to() );

        // The audit row says what happened in words; the token never
        // enters any recorded statement.
        $rows = $this->audit_rows();
        $this->assertCount( 1, $rows );
        foreach ( $GLOBALS['wpdb']->inserts as $insert ) {
            $this->assertStringNotContainsString( self::META_TOKEN, (string) json_encode( $insert ) );
        }
    }

    public function testEmptyFieldsKeepStoredCredentials(): void {
        Gr_Secrets::store( Gr_Analytics_Page::GA4_ID_OPTION, 'G-ABC123DEFG' );
        Gr_Secrets::store( Gr_Analytics_Page::GA4_SECRET_OPTION, 'ga4-api-secret-0123456789abcdef' );
        ( new Gr_Settings() )->set( 'capi_ga4_enabled', 0 );

        $this->post( 'save_ga4' );
        $_POST['capi_ga4_enabled'] = '1';
        // No ga4_id / ga4_secret keys at all: untouched means kept.

        Gr_Analytics_Page::handle_actions();

        $this->assertSame( 'G-ABC123DEFG', Gr_Secrets::reveal( Gr_Analytics_Page::GA4_ID_OPTION ) );
        $this->assertSame( 'ga4-api-secret-0123456789abcdef', Gr_Secrets::reveal( Gr_Analytics_Page::GA4_SECRET_OPTION ) );
        $this->assertSame( 1, (int) ( new Gr_Settings() )->get( 'capi_ga4_enabled' ) );

        // The diff records only the toggle move.
        $rows = $this->audit_rows();
        $this->assertCount( 1, $rows );
        $this->assertStringContainsString( 'gr_saved=ga4', $this->redirected_to() );
    }

    public function testHalfEnteredPairIsRefusedWhole(): void {
        $this->post( 'save_meta' );
        $_POST['meta_id'] = self::META_PIXEL;
        // No secret: the pair would look configured while unusable.

        Gr_Analytics_Page::handle_actions();

        $this->assertStringContainsString( 'gr_error=meta_pair', $this->redirected_to() );
        $this->assertArrayNotHasKey( Gr_Analytics_Page::META_PIXEL_OPTION, $GLOBALS['gr_stub_options']['data'] );
        $this->assertSame( array(), $this->audit_rows() );
    }

    public function testShapeGatesRefuseBadValues(): void {
        $this->post( 'save_meta' );
        $_POST['meta_id']     = 'not-numeric';
        $_POST['meta_secret'] = self::META_TOKEN;

        Gr_Analytics_Page::handle_actions();
        $this->assertStringContainsString( 'gr_error=meta_id', $this->redirected_to() );

        gr_stub_reset_options();
        unset( $_POST, $_GET );
        $this->post( 'save_meta' );
        $_POST['meta_id']     = self::META_PIXEL;
        $_POST['meta_secret'] = 'short';

        Gr_Analytics_Page::handle_actions();
        $this->assertStringContainsString( 'gr_error=meta_secret', $this->redirected_to() );

        gr_stub_reset_options();
        unset( $_POST, $_GET );
        $this->post( 'save_ga4' );
        $_POST['ga4_id']     = 'g-lowercase';
        $_POST['ga4_secret'] = 'ga4-api-secret-0123456789abcdef';

        Gr_Analytics_Page::handle_actions();
        $this->assertStringContainsString( 'gr_error=ga4_id', $this->redirected_to() );
        $this->assertArrayNotHasKey( Gr_Analytics_Page::GA4_ID_OPTION, $GLOBALS['gr_stub_options']['data'] );
    }

    public function testRemoveForgetsCredentialsAndTheToggle(): void {
        Gr_Secrets::store( Gr_Analytics_Page::META_PIXEL_OPTION, self::META_PIXEL );
        Gr_Secrets::store( Gr_Analytics_Page::META_TOKEN_OPTION, self::META_TOKEN );
        ( new Gr_Settings() )->set( 'capi_meta_enabled', 1 );

        $this->post( 'save_meta' );
        $_POST['meta_remove'] = '1';
        // The stale enabled checkbox travels along; remove wins.

        Gr_Analytics_Page::handle_actions();

        $this->assertArrayNotHasKey( Gr_Analytics_Page::META_PIXEL_OPTION, $GLOBALS['gr_stub_options']['data'] );
        $this->assertArrayNotHasKey( Gr_Analytics_Page::META_TOKEN_OPTION, $GLOBALS['gr_stub_options']['data'] );
        $this->assertSame( 0, (int) ( new Gr_Settings() )->get( 'capi_meta_enabled' ) );

        // The audit row records the removal as state words.
        $rows = $this->audit_rows();
        $this->assertCount( 1, $rows );
    }

    public function testLocalCheckBattery(): void {
        // Absent: nothing stored yet.
        $this->post( 'check_meta' );
        Gr_Analytics_Page::handle_actions();
        $this->assertStringContainsString( 'gr_result=absent', $this->redirected_to() );

        // OK: a stored, well-shaped pair.
        gr_stub_reset_options();
        unset( $_POST, $_GET );
        Gr_Secrets::store( Gr_Analytics_Page::META_PIXEL_OPTION, self::META_PIXEL );
        Gr_Secrets::store( Gr_Analytics_Page::META_TOKEN_OPTION, self::META_TOKEN );
        $this->post( 'check_meta' );
        Gr_Analytics_Page::handle_actions();
        $this->assertStringContainsString( 'gr_result=ok', $this->redirected_to() );

        // Broken: an envelope that no longer decrypts.
        gr_stub_reset_options();
        unset( $_POST, $_GET );
        update_option( Gr_Analytics_Page::META_PIXEL_OPTION, 'not-an-envelope' );
        update_option( Gr_Analytics_Page::META_TOKEN_OPTION, 'not-an-envelope' );
        $this->post( 'check_meta' );
        Gr_Analytics_Page::handle_actions();
        $this->assertStringContainsString( 'gr_result=broken', $this->redirected_to() );

        // Shape: readable but implausible (stored directly, past the
        // save gate, the way a manual option edit would leave them).
        gr_stub_reset_options();
        unset( $_POST, $_GET );
        Gr_Secrets::store( Gr_Analytics_Page::GA4_ID_OPTION, 'g-shape-typo' );
        Gr_Secrets::store( Gr_Analytics_Page::GA4_SECRET_OPTION, 'ga4-api-secret-0123456789abcdef' );
        $this->post( 'check_ga4' );
        Gr_Analytics_Page::handle_actions();
        $this->assertStringContainsString( 'gr_result=shape', $this->redirected_to() );
    }

    public function testConfiguredRenderMasksBothCredentials(): void {
        Gr_Secrets::store( Gr_Analytics_Page::META_PIXEL_OPTION, self::META_PIXEL );
        Gr_Secrets::store( Gr_Analytics_Page::META_TOKEN_OPTION, self::META_TOKEN );

        ob_start();
        Gr_Analytics_Page::render();
        $html = (string) ob_get_clean();

        // The configured state with the masked preview.
        $this->assertStringContainsString( 'Configured', $html );
        $this->assertStringContainsString( Gr_Secrets::mask( self::META_TOKEN ), $html );
        $this->assertStringContainsString( Gr_Secrets::mask( self::META_PIXEL ), $html );

        // The plaintext of either credential never appears, and the
        // inputs stay empty: no echo-back, ever.
        $this->assertStringNotContainsString( self::META_TOKEN, $html );
        $this->assertStringNotContainsString( self::META_PIXEL, $html );
        $this->assertStringNotContainsString( 'value="' . self::META_PIXEL . '"', $html );

        // The unconfigured twin still states itself.
        $this->assertStringContainsString( 'Not configured.', $html );
    }

    public function testGateFailuresWriteNothing(): void {
        // No capability: nothing stored, no redirect.
        $_POST = array(
            'gr_analytics_action' => 'save_meta',
            Gr_Analytics_Page::NONCE_FIELD => 'gr-stub-nonce-' . md5( Gr_Analytics_Page::NONCE_ACTION ),
            'meta_id'     => self::META_PIXEL,
            'meta_secret' => self::META_TOKEN,
        );

        Gr_Analytics_Page::handle_actions();

        $this->assertSame( array(), $GLOBALS['gr_stub_redirects'] );
        $this->assertArrayNotHasKey( Gr_Analytics_Page::META_PIXEL_OPTION, $GLOBALS['gr_stub_options']['data'] );

        // Bad nonce: same refusal.
        gr_stub_reset_options();
        unset( $_POST, $_GET );
        $GLOBALS['gr_stub_caps'] = array( 'manage_options' );
        $_POST = array(
            'gr_analytics_action' => 'save_meta',
            Gr_Analytics_Page::NONCE_FIELD => 'wrong-nonce',
            'meta_id'     => self::META_PIXEL,
            'meta_secret' => self::META_TOKEN,
        );

        Gr_Analytics_Page::handle_actions();

        $this->assertSame( array(), $GLOBALS['gr_stub_redirects'] );
        $this->assertArrayNotHasKey( Gr_Analytics_Page::META_PIXEL_OPTION, $GLOBALS['gr_stub_options']['data'] );
        $this->assertSame( array(), $this->audit_rows() );
    }

    public function testCheckResultNoticeRendersFromPrgFlags(): void {
        $_GET = array( 'gr_check' => 'ga4', 'gr_result' => 'ok' );

        ob_start();
        Gr_Analytics_Page::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'notice-success', $html );
        $this->assertStringContainsString( 'ga4', $html );
        $this->assertStringContainsString( 'ok', $html );

        $_GET = array( 'gr_error' => 'meta_pair' );
        ob_start();
        Gr_Analytics_Page::render();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString( 'notice-error', $html );
        $this->assertStringContainsString( 'meta_pair', $html );
    }

    public function testMaskKeepsOnlyTheEdges(): void {
        $this->assertSame( 'EA****ef', Gr_Secrets::mask( 'EAbcdefghijklmnef' ) );
        $this->assertSame( '****', Gr_Secrets::mask( 'short' ) );
        $this->assertSame( '', Gr_Secrets::mask( '' ) );
    }

    public function testMenuRegistersAnalyticsInTheIntegrationsSection(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        do_action( 'admin_menu' );

        $found = false;
        foreach ( $GLOBALS['gr_stub_submenu_pages'] as $page ) {
            if ( Gr_Analytics_Page::SLUG === (string) $page['menu_slug'] ) {
                $found = true;
                $this->assertSame( 'greenpng-dashboard', $page['parent_slug'] );
                $this->assertSame( 'manage_options', $page['capability'] );
                $this->assertSame( array( Gr_Analytics_Page::class, 'render' ), $page['callback'] );
            }
        }
        $this->assertTrue( $found );

        // The write arms ride admin_init with the other write pages.
        $hooks = array();
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            $hooks[] = (string) $registration['hook'];
        }
        $this->assertContains( 'admin_init', $hooks );
    }
}
