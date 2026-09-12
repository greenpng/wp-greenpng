<?php
/**
 * Access rules write side and admin page (docs/13 U6, docs/06 §2.1):
 * the repository CRUD shapes, the double gate (capability AND nonce)
 * on every write, input validation, and the native-component render
 * (WP_List_Table plus a nonce-carrying form).
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Admin\Gr_Access_Rules_Page;
use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Security\Gr_Access_Rules;
use GreenPNG\Storage\Gr_Access_Rules_Repository;
use PHPUnit\Framework\TestCase;

final class AccessRulesPageTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        unset( $_POST, $_GET['tab'] );
    }

    protected function tearDown(): void {
        unset( $_POST, $_GET['tab'] );
        parent::tearDown();
    }

    /**
     * A valid, gate-passing add POST body.
     *
     * @param string $type Rule type.
     * @return void
     */
    private function post_add( string $type ): void {
        $_POST = array(
            'gr_access_action' => 'add',
            'tab'              => $type,
            'match_kind'       => 'ip',
            'match_value'      => '203.0.113.0/24',
            'note'             => 'probe network',
            Gr_Access_Rules_Page::NONCE_FIELD => 'gr-stub-nonce-' . md5( Gr_Access_Rules_Page::NONCE_ACTION ),
        );
        $GLOBALS['gr_stub_caps'] = array( 'manage_options' );
    }

    public function testRepositoryAddWritesTheFullRow(): void {
        $GLOBALS['gr_stub_user_id'] = 7;
        ( new Gr_Access_Rules_Repository() )->add( Gr_Access_Rules::TYPE_BAN, Gr_Access_Rules::KIND_IP, '203.0.113.0/24', 'probe network' );

        $row = $GLOBALS['wpdb']->inserts[0];
        $this->assertSame( 'wp_gr_access_rules', $row['table'] );
        $this->assertSame( 'ban', $row['data']['rule_type'] );
        $this->assertSame( 'ip', $row['data']['match_kind'] );
        $this->assertSame( '203.0.113.0/24', $row['data']['match_value'] );
        $this->assertSame( 7, $row['data']['created_by'] );
        $this->assertSame( 1, $row['data']['is_active'] );
    }

    public function testRepositoryDeleteAndToggleCarryTheType(): void {
        $repo = new Gr_Access_Rules_Repository();

        $repo->delete( 5, Gr_Access_Rules::TYPE_BAN );
        $this->assertStringContainsString( 'DELETE FROM wp_gr_access_rules', $GLOBALS['wpdb']->queries[0] );
        $this->assertStringContainsString( "rule_type = 'ban'", $GLOBALS['wpdb']->queries[0] );

        $repo->set_active( 9, Gr_Access_Rules::TYPE_ALLOW, false );
        $this->assertStringContainsString( 'UPDATE wp_gr_access_rules', end( $GLOBALS['wpdb']->queries ) );
        $this->assertStringContainsString( "rule_type = 'allow'", end( $GLOBALS['wpdb']->queries ) );
    }

    public function testWriteWithoutCapabilityDoesNothing(): void {
        $this->post_add( Gr_Access_Rules::TYPE_BAN );
        $GLOBALS['gr_stub_caps'] = false; // no capability, valid nonce shape.

        Gr_Access_Rules_Page::handle_actions();

        $this->assertSame( array(), $GLOBALS['wpdb']->inserts );
        $this->assertSame( array(), $GLOBALS['gr_stub_redirects'] );
    }

    public function testWriteWithoutNonceDoesNothing(): void {
        $this->post_add( Gr_Access_Rules::TYPE_BAN );
        $GLOBALS['gr_stub_caps'] = array( 'manage_options' );
        $GLOBALS['gr_stub_nonce_bad'] = true; // capability, bad nonce.

        Gr_Access_Rules_Page::handle_actions();

        $this->assertSame( array(), $GLOBALS['wpdb']->inserts );
        $this->assertSame( array(), $GLOBALS['gr_stub_redirects'] );
    }

    public function testValidAddWritesAndRedirects(): void {
        $this->post_add( Gr_Access_Rules::TYPE_BAN );

        Gr_Access_Rules_Page::handle_actions();

        $this->assertNotEmpty( $GLOBALS['wpdb']->inserts );
        $this->assertSame( 'ban', $GLOBALS['wpdb']->inserts[0]['data']['rule_type'] );

        // Post-redirect-get back to the same tab.
        $this->assertCount( 1, $GLOBALS['gr_stub_redirects'] );
        $this->assertStringContainsString( 'page=greenpng-access', $GLOBALS['gr_stub_redirects'][0]['location'] );
        $this->assertStringContainsString( 'tab=ban', $GLOBALS['gr_stub_redirects'][0]['location'] );
    }

    public function testInvalidValuesAreRefused(): void {
        $this->post_add( Gr_Access_Rules::TYPE_BAN );
        $_POST['match_value'] = 'not-an-ip-or-cidr';

        Gr_Access_Rules_Page::handle_actions();
        $this->assertSame( array(), $GLOBALS['wpdb']->inserts );

        // Family-wrong prefix refused: /64 is meaningless for IPv4.
        gr_stub_reset_options();
        $this->post_add( Gr_Access_Rules::TYPE_BAN );
        $_POST['match_value'] = '203.0.113.0/64';
        Gr_Access_Rules_Page::handle_actions();
        $this->assertSame( array(), $GLOBALS['wpdb']->inserts );

        // Ban + URL kind refused: URL exemptions only exist on allow.
        gr_stub_reset_options();
        $this->post_add( Gr_Access_Rules::TYPE_BAN );
        $_POST['match_kind'] = 'url';
        $_POST['match_value'] = '/checkout';
        Gr_Access_Rules_Page::handle_actions();
        $this->assertSame( array(), $GLOBALS['wpdb']->inserts );

        // Traversal refused on the allow side.
        gr_stub_reset_options();
        $this->post_add( Gr_Access_Rules::TYPE_ALLOW );
        $_POST['match_kind'] = 'url';
        $_POST['match_value'] = '/../wp-config';
        Gr_Access_Rules_Page::handle_actions();
        $this->assertSame( array(), $GLOBALS['wpdb']->inserts );

        // The same shape on the right terms passes: allow + rooted path.
        gr_stub_reset_options();
        $this->post_add( Gr_Access_Rules::TYPE_ALLOW );
        $_POST['match_kind'] = 'url';
        $_POST['match_value'] = '/checkout';
        Gr_Access_Rules_Page::handle_actions();
        $this->assertNotEmpty( $GLOBALS['wpdb']->inserts );
    }

    public function testBulkDeleteAddressesOnlyItsOwnType(): void {
        $this->post_add( Gr_Access_Rules::TYPE_BAN );
        $_POST['gr_access_action'] = 'delete';
        $_POST['rule']              = array( '4', '0', '7' );

        Gr_Access_Rules_Page::handle_actions();

        $deletes = array();
        foreach ( $GLOBALS['wpdb']->queries as $sql ) {
            if ( false !== strpos( (string) $sql, 'DELETE FROM wp_gr_access_rules' ) ) {
                $deletes[] = (string) $sql;
            }
        }
        // The stub records the prepared line and the executed line as
        // identical strings; one statement per id is the real count.
        $deletes = array_values( array_unique( $deletes ) );

        // The zero id never became a statement.
        $this->assertCount( 2, $deletes );
        $this->assertStringContainsString( 'id = 4', $deletes[0] );
        $this->assertStringContainsString( 'id = 7', $deletes[1] );
    }

    public function testRenderCarriesNativeComponentsAndNonceFields(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, "rule_type = 'ban'" ) ) {
                return array(
                    array(
                        'id'          => '3',
                        'match_value' => '198.51.100.0/24',
                        'match_kind'  => 'ip',
                        'note'        => 'probe range',
                        'is_active'   => '1',
                        'created_at'  => '2026-09-12 09:00:00',
                    ),
                );
            }

            return array();
        };

        ob_start();
        Gr_Access_Rules_Page::render();
        $html = (string) ob_get_clean();

        // Native furniture: tabs, list table, form-table, buttons.
        $this->assertStringContainsString( 'nav-tab-wrapper', $html );
        $this->assertStringContainsString( 'wp-list-table', $html );
        $this->assertStringContainsString( 'form-table', $html );
        $this->assertStringContainsString( 'p class="submit"', $html );

        // The rule row renders as text.
        $this->assertStringContainsString( '<td class="column-match_value">198.51.100.0/24</td>', $html );
        $this->assertStringContainsString( '<td class="column-is_active">Yes</td>', $html );

        // Both forms carry the nonce; the delete form carries the
        // checkbox column values.
        $this->assertSame( 2, substr_count( $html, 'name="_gr_access_nonce"' ) );
        $this->assertStringContainsString( 'name="rule[]" value="3"', $html );
    }

    public function testAllowTabRendersItsOwnList(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, "rule_type = 'allow'" ) ) {
                return array( array( 'id' => '1', 'match_value' => '/checkout', 'match_kind' => 'url', 'note' => '', 'is_active' => '1', 'created_at' => '2026-09-12 08:00:00' ) );
            }

            return array();
        };

        $_GET['tab'] = 'allow';
        ob_start();
        Gr_Access_Rules_Page::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( '<td class="column-match_value">/checkout</td>', $html );
        $this->assertStringContainsString( '<td class="column-match_kind">url</td>', $html );
    }

    public function testPluginRegistersWriteHandlerAndSubmenu(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        $hooks = array();
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            $hooks[] = (string) $registration['hook'];
        }
        $this->assertContains( 'admin_init', $hooks );

        // The submenu lands under the traffic parent on admin_menu.
        do_action( 'admin_menu' );
        $slugs = array();
        foreach ( $GLOBALS['gr_stub_submenu_pages'] as $page ) {
            $slugs[] = $page['parent_slug'] . ':' . $page['menu_slug'];
        }
        $this->assertContains( 'greenpng-traffic:greenpng-access', $slugs );
    }

    public function testEngineMemoInvalidatesAfterWrites(): void {
        // A write through the page must not leave the same request
        // matching against the stale rule set.
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            return array( array( 'rule_type' => 'ban', 'match_kind' => 'ip', 'match_value' => '203.0.113.0/24' ) );
        };

        $this->assertTrue( Gr_Access_Rules::is_ip_blocked( '203.0.113.9' ) );

        // A new allow rule appears behind the memo; only a fresh read
        // can honor it.
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            return array(
                array( 'rule_type' => 'allow', 'match_kind' => 'ip', 'match_value' => '203.0.113.9' ),
                array( 'rule_type' => 'ban', 'match_kind' => 'ip', 'match_value' => '203.0.113.0/24' ),
            );
        };

        $this->assertTrue( Gr_Access_Rules::is_ip_blocked( '203.0.113.9' ), 'memo still holds' );

        Gr_Access_Rules::invalidate();
        $this->assertFalse( Gr_Access_Rules::is_ip_blocked( '203.0.113.9' ), 'allow-over-ban after reload' );
    }
}
