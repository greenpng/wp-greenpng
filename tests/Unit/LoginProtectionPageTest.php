<?php
/**
 * Login Protection page (docs/13 U7, docs/06 §1): the audit read
 * shapes, the release write's double gate, the display-masked /
 * stored-form split, and the recovery guidance the acceptance row
 * demands ("CLI unlock instructions visible on the page").
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Admin\Gr_Login_Protection_Page;
use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Security\Gr_Temp_Bans;
use GreenPNG\Storage\Gr_Security_Log_Repository;
use PHPUnit\Framework\TestCase;

final class LoginProtectionPageTest extends TestCase {

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
     * A valid, gate-passing release POST body for one address.
     *
     * @param string $ip Address to release.
     * @return void
     */
    private function post_release( string $ip ): void {
        $_POST = array(
            'gr_login_action' => 'release',
            'lock_ip'         => $ip,
            Gr_Login_Protection_Page::NONCE_FIELD => 'gr-stub-nonce-' . md5( Gr_Login_Protection_Page::NONCE_ACTION ),
        );
        $GLOBALS['gr_stub_caps'] = array( 'manage_options' );
    }

    public function testRecentByRulesShapesTheSql(): void {
        ( new Gr_Security_Log_Repository() )->recent_by_rules( array( 'login_fail', 'login_lockout' ), 30 );

        $sql = (string) $GLOBALS['wpdb']->queries[0];
        $this->assertStringContainsString( "rule_id IN ('login_fail','login_lockout')", $sql );
        $this->assertStringContainsString( 'ORDER BY last_seen DESC', $sql );
        $this->assertStringContainsString( 'LIMIT 30', $sql );
    }

    public function testRecentByRulesSkipsTheQueryWithoutRules(): void {
        $this->assertSame( array(), ( new Gr_Security_Log_Repository() )->recent_by_rules( array(), 30 ) );
        $this->assertSame( array(), $GLOBALS['wpdb']->queries );
    }

    public function testDistinctRecentIpsGroupsByAddress(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'GROUP BY ip' ) ) {
                return array( array( 'ip' => '203.0.113.9', 'last_seen' => '2026-09-12 10:00:00' ) );
            }

            return array();
        };

        $rows = ( new Gr_Security_Log_Repository() )->distinct_recent_ips( 720, 100 );

        $sql = (string) $GLOBALS['wpdb']->queries[0];
        $this->assertStringContainsString( 'GROUP BY ip', $sql );
        $this->assertStringContainsString( 'ORDER BY last_seen DESC', $sql );
        $this->assertSame( '203.0.113.9', $rows[0]['ip'] );
        $this->assertSame( '2026-09-12 10:00:00', $rows[0]['last_seen'] );
    }

    public function testReleaseWithoutCapabilityDoesNothing(): void {
        Gr_Temp_Bans::block( '203.0.113.5', 'login lockout round 1', 300 );
        $this->post_release( '203.0.113.5' );
        $GLOBALS['gr_stub_caps'] = false; // no capability, valid nonce shape.

        Gr_Login_Protection_Page::handle_actions();

        $this->assertTrue( Gr_Temp_Bans::is_locked( '203.0.113.5' ) );
        $this->assertSame( array(), $GLOBALS['gr_stub_redirects'] );
    }

    public function testReleaseWithoutNonceDoesNothing(): void {
        Gr_Temp_Bans::block( '203.0.113.5', 'login lockout round 1', 300 );
        $this->post_release( '203.0.113.5' );
        $GLOBALS['gr_stub_nonce_bad'] = true; // capability, bad nonce.

        Gr_Login_Protection_Page::handle_actions();

        $this->assertTrue( Gr_Temp_Bans::is_locked( '203.0.113.5' ) );
        $this->assertSame( array(), $GLOBALS['gr_stub_redirects'] );
    }

    public function testValidReleaseUnlocksAndRedirects(): void {
        Gr_Temp_Bans::block( '203.0.113.5', 'login lockout round 1', 300 );
        $this->post_release( '203.0.113.5' );

        Gr_Login_Protection_Page::handle_actions();

        $this->assertFalse( Gr_Temp_Bans::is_locked( '203.0.113.5' ) );
        $this->assertCount( 1, $GLOBALS['gr_stub_redirects'] );
        $this->assertStringContainsString( 'page=greenpng-login', $GLOBALS['gr_stub_redirects'][0]['location'] );
        $this->assertStringContainsString( 'tab=sessions', $GLOBALS['gr_stub_redirects'][0]['location'] );
        $this->assertStringContainsString( 'gr_released=1', $GLOBALS['gr_stub_redirects'][0]['location'] );
    }

    public function testStaleReleaseRedirectsWithTheWarningFlag(): void {
        $this->post_release( '203.0.113.5' ); // no lock was ever placed.

        Gr_Login_Protection_Page::handle_actions();

        $this->assertCount( 1, $GLOBALS['gr_stub_redirects'] );
        $this->assertStringContainsString( 'gr_released=0', $GLOBALS['gr_stub_redirects'][0]['location'] );
    }

    public function testReleaseWithInvalidAddressTouchesNothing(): void {
        Gr_Temp_Bans::block( '203.0.113.5', 'login lockout round 1', 300 );
        $this->post_release( 'not-an-ip' );

        Gr_Login_Protection_Page::handle_actions();

        // The other lock survives and no release flag is carried.
        $this->assertTrue( Gr_Temp_Bans::is_locked( '203.0.113.5' ) );
        $this->assertStringNotContainsString( 'gr_released=1', $GLOBALS['gr_stub_redirects'][0]['location'] );
        $this->assertStringNotContainsString( 'gr_released=0', $GLOBALS['gr_stub_redirects'][0]['location'] );
    }

    public function testAuditTabRendersMaskedRowsAndRecoveryGuidance(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'login_fail' ) ) {
                return array(
                    array(
                        'rule_id'    => 'login_fail',
                        'ip'         => '203.0.113.55',
                        'hit_count'  => '4',
                        'reason'     => 'failed sign-in for admin (4/5)',
                        'last_seen'  => '2026-09-12 10:15:00',
                    ),
                );
            }

            return array();
        };

        ob_start();
        Gr_Login_Protection_Page::render();
        $html = (string) ob_get_clean();

        // Native furniture and the localized event label.
        $this->assertStringContainsString( 'nav-tab-wrapper', $html );
        $this->assertStringContainsString( 'widefat', $html );
        $this->assertStringContainsString( 'Failed sign-in', $html );

        // Display masked; the audit tab has no form, so the complete
        // address must not appear anywhere in the page.
        $this->assertStringContainsString( '203.0.113.*', $html );
        $this->assertStringNotContainsString( '203.0.113.55', $html );
        $this->assertStringContainsString( 'failed sign-in for admin (4/5)', $html );

        // The acceptance row: the CLI unlock instruction is visible.
        $this->assertStringContainsString( 'wp greenpng unblock', $html );
        $this->assertStringContainsString( 'page=greenpng-access', $html );
    }

    public function testSessionsTabRendersLiveLocksWithReleaseForms(): void {
        Gr_Temp_Bans::block( '203.0.113.9', 'login lockout round 1', 7500 );
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'GROUP BY ip' ) ) {
                return array( array( 'ip' => '203.0.113.9', 'last_seen' => '2026-09-12 10:00:00' ) );
            }

            return array();
        };

        $_GET = array( 'tab' => 'sessions' );
        ob_start();
        Gr_Login_Protection_Page::render();
        $html = (string) ob_get_clean();

        // Masked display plus the stored form in the hidden field.
        $this->assertStringContainsString( '203.0.113.*', $html );
        $this->assertStringContainsString( 'name="lock_ip" value="203.0.113.9"', $html );

        // Live lock facts: reason from the lock value, hour-scale
        // remaining phrase, nonce field, release button.
        $this->assertStringContainsString( 'login lockout round 1', $html );
        $this->assertStringContainsString( '2h ', $html );
        $this->assertStringContainsString( 'name="_gr_login_nonce"', $html );
        $this->assertStringContainsString( 'value="Release"', $html );
    }

    public function testSessionsTabShowsEmptyStateWithoutLiveLocks(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'GROUP BY ip' ) ) {
                return array( array( 'ip' => '203.0.113.9', 'last_seen' => '2026-09-12 10:00:00' ) );
            }

            return array();
        };

        $_GET = array( 'tab' => 'sessions' );
        ob_start();
        Gr_Login_Protection_Page::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'No active locks', $html );
        $this->assertStringNotContainsString( 'name="lock_ip"', $html );
    }

    public function testPluginRegistersHandlerAndSubmenu(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        $hooks = array();
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            $hooks[] = (string) $registration['hook'];
        }
        $this->assertContains( 'admin_init', $hooks );

        // The submenu lands beside Access Rules under the traffic
        // parent on admin_menu.
        do_action( 'admin_menu' );
        $slugs = array();
        foreach ( $GLOBALS['gr_stub_submenu_pages'] as $page ) {
            $slugs[] = $page['parent_slug'] . ':' . $page['menu_slug'];
        }
        $this->assertContains( 'greenpng-traffic:greenpng-login', $slugs );
    }
}
