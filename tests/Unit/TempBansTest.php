<?php
/**
 * Temporary IP locks (docs/13 W5): TTL transients, expiry, recovery
 * paths, the is_ip_blocked integration, and the WP-CLI release
 * command.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Cli;
use GreenPNG\Security\Gr_Access_Rules;
use GreenPNG\Security\Gr_Temp_Bans;
use PHPUnit\Framework\TestCase;

final class TempBansTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    public function testBlockPlacesATtlTransientWithAuditFields(): void {
        $this->assertTrue( Gr_Temp_Bans::block( '203.0.113.7', 'login burst', 3600 ) );

        $this->assertTrue( Gr_Temp_Bans::is_locked( '203.0.113.7' ) );
        $this->assertSame( 'login burst', Gr_Temp_Bans::lock_reason( '203.0.113.7' ) );

        // The stored entry carries the clamped ttl and the deadline the
        // stub clock set 3600 seconds out.
        $entry = $GLOBALS['gr_stub_transients'][ 'gr_block_' . md5( '203.0.113.7' ) ];
        $this->assertSame( 3600, $entry['value']['ttl'] );
        $this->assertSame( gr_stub_clock() + 3600, $entry['expires_at'] );
    }

    public function testExpiryReleasesTheLock(): void {
        Gr_Temp_Bans::block( '203.0.113.7', 'surge', 300 );

        // One second before the deadline the lock still holds.
        $GLOBALS['gr_stub_epoch'] = gr_stub_clock() + 299;
        $this->assertTrue( Gr_Temp_Bans::is_locked( '203.0.113.7' ) );

        // At the deadline the transient is gone, like core's expired
        // read, so the address frees itself without any CLI action.
        $GLOBALS['gr_stub_epoch'] = gr_stub_clock() + 1;
        $this->assertFalse( Gr_Temp_Bans::is_locked( '203.0.113.7' ) );
        $this->assertSame( '', Gr_Temp_Bans::lock_reason( '203.0.113.7' ) );
    }

    public function testUnblockIsIdempotentAndReports(): void {
        Gr_Temp_Bans::block( '203.0.113.7', 'login burst', 3600 );

        $this->assertTrue( Gr_Temp_Bans::unblock( '203.0.113.7' ) );
        $this->assertFalse( Gr_Temp_Bans::is_locked( '203.0.113.7' ) );

        // Re-running the recovery command is a no-op, not a failure.
        $this->assertFalse( Gr_Temp_Bans::unblock( '203.0.113.7' ) );
    }

    public function testInvalidAddressesAreRefusedNowhereStored(): void {
        $this->assertFalse( Gr_Temp_Bans::block( 'not-an-ip', 'x', 60 ) );
        $this->assertFalse( Gr_Temp_Bans::block( '999.1.1.1', 'x', 60 ) );
        $this->assertFalse( Gr_Temp_Bans::is_locked( 'not-an-ip' ) );
        $this->assertFalse( Gr_Temp_Bans::unblock( 'not-an-ip' ) );
        $this->assertSame( array(), $GLOBALS['gr_stub_transients'] );
    }

    public function testTtlIsClampedToTheTransientRange(): void {
        Gr_Temp_Bans::block( '203.0.113.7', 'a', 0 );
        $entry = $GLOBALS['gr_stub_transients'][ 'gr_block_' . md5( '203.0.113.7' ) ];
        $this->assertSame( 1, $entry['value']['ttl'] );

        Gr_Temp_Bans::block( '203.0.113.8', 'b', 999999999 );
        $entry = $GLOBALS['gr_stub_transients'][ 'gr_block_' . md5( '203.0.113.8' ) ];
        $this->assertSame( 2592000, $entry['value']['ttl'] );
    }

    public function testIsIpBlockedConsumesTemporaryLocks(): void {
        // No static rules at all: the transient alone decides.
        $GLOBALS['wpdb']->results = array();

        $this->assertFalse( gr_is_ip_blocked( '203.0.113.7' ) );
        gr_block_ip( '203.0.113.7', 'login burst', 3600 );
        $this->assertTrue( gr_is_ip_blocked( '203.0.113.7' ) );
        gr_unblock_ip( '203.0.113.7' );
        $this->assertFalse( gr_is_ip_blocked( '203.0.113.7' ) );
    }

    public function testAllowRulesWinOverTemporaryLocksToo(): void {
        $GLOBALS['wpdb']->results = array(
            array(
                'rule_type'   => 'allow',
                'match_kind'  => 'ip',
                'match_value' => '203.0.113.7',
            ),
        );

        gr_block_ip( '203.0.113.7', 'lockout', 3600 );

        // The allow list is the recovery valve even mid-lockout.
        $this->assertFalse( gr_is_ip_blocked( '203.0.113.7' ) );
    }

    public function testBlockAndUnbanActionsFire(): void {
        Gr_Temp_Bans::block( '203.0.113.7', 'login burst', 600 );
        Gr_Temp_Bans::unblock( '203.0.113.7' );

        $ban   = null;
        $unban = null;
        foreach ( $GLOBALS['gr_stub_fired_action_args'] as $record ) {
            if ( 'gr_temp_ban' === $record['hook'] ) {
                $ban = $record['args'];
            }
            if ( 'gr_temp_unban' === $record['hook'] ) {
                $unban = $record['args'];
            }
        }

        $this->assertNotNull( $ban );
        $this->assertSame( '203.0.113.7', $ban[0] );
        $this->assertSame( 'login burst', $ban[1] );
        $this->assertSame( 600, $ban[2] );
        $this->assertNotNull( $unban );
        $this->assertSame( array( '203.0.113.7' ), $unban );
    }

    public function testCliRegistersAndReleasesLocks(): void {
        Gr_Cli::register();

        $this->assertSame( Gr_Cli::class, $GLOBALS['gr_stub_cli_commands']['greenpng'] );

        // No address: the real CLI halts, the stub throws.
        gr_block_ip( '203.0.113.7', 'login burst', 600 );
        $cli = new Gr_Cli();
        $cli->unblock( array( '203.0.113.7' ) );

        $this->assertFalse( Gr_Temp_Bans::is_locked( '203.0.113.7' ) );
        $this->assertNotEmpty( $GLOBALS['gr_stub_cli_messages']['success'] );

        // Idempotent repeat: a warning, never a failure.
        $cli->unblock( array( '203.0.113.7' ) );
        $this->assertNotEmpty( $GLOBALS['gr_stub_cli_messages']['warning'] );
        $this->assertSame( array(), $GLOBALS['gr_stub_cli_messages']['error'] );
    }

    public function testCliWithoutAnAddressHalts(): void {
        $cli = new Gr_Cli();

        try {
            $cli->unblock( array() );
            $this->fail( 'the missing-address call must halt like a real CLI error' );
        } catch ( \Gr_Cli_Error_Halt $halt ) {
            $this->assertNotSame( '', $halt->getMessage() );
        }

        $this->assertNotEmpty( $GLOBALS['gr_stub_cli_messages']['error'] );
    }
}
