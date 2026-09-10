<?php
/**
 * Login brute-force protection (docs/13 W7): threshold counting,
 * gradient lockout rounds, allow-list recovery, the record-only
 * default gate, and the wp_login_failed wiring.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Security\Gr_Login_Protection;
use GreenPNG\Security\Gr_Temp_Bans;
use PHPUnit\Framework\TestCase;

final class LoginProtectionTest extends TestCase {

    /** Probe address, kept out of the loopback ranges. */
    private const IP = '203.0.113.55';

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();

        // Fast gradient for the round tests.
        gr()->settings()->set( 'login_lockout_base', 60 );
    }

    public function testFailuresBelowThresholdNeverLock(): void {
        for ( $i = 1; $i <= 4; $i++ ) {
            gr_record_login_failure( 'admin', self::IP );
        }

        $state = gr_check_login_lockout( 'admin', self::IP );

        $this->assertFalse( $state['locked'] );
        $this->assertSame( 4, $state['failures'] );
        $this->assertSame( 5, $state['threshold'] );
        $this->assertSame( 0, $state['round'] );
    }

    public function testThresholdTriggersRoundOneWithBaseDuration(): void {
        for ( $i = 1; $i <= 5; $i++ ) {
            gr_record_login_failure( 'admin', self::IP );
        }

        $state = gr_check_login_lockout( 'admin', self::IP );

        $this->assertTrue( $state['locked'] );
        $this->assertSame( 1, $state['round'] );
        $this->assertSame( 60, Gr_Temp_Bans::lock_remaining( self::IP ) );
        $this->assertSame( 'login lockout round 1', Gr_Temp_Bans::lock_reason( self::IP ) );

        // The counter reset so the next round starts from zero.
        $this->assertSame( 0, $state['failures'] );
    }

    public function testGradientDoublesPerRoundAndCapsAtADay(): void {
        // Round 1.
        for ( $i = 0; $i < 5; $i++ ) {
            gr_record_login_failure( 'admin', self::IP );
        }
        $this->assertSame( 1, gr_check_login_lockout( 'admin', self::IP )['round'] );

        // Expire the lock, then fail up to the threshold again: the
        // gradient remembers the completed round and doubles.
        Gr_Temp_Bans::unblock( self::IP );
        for ( $i = 0; $i < 5; $i++ ) {
            gr_record_login_failure( 'admin', self::IP );
        }
        $this->assertSame( 2, gr_check_login_lockout( 'admin', self::IP )['round'] );
        $this->assertSame( 120, Gr_Temp_Bans::lock_remaining( self::IP ) );

        Gr_Temp_Bans::unblock( self::IP );
        for ( $i = 0; $i < 5; $i++ ) {
            gr_record_login_failure( 'admin', self::IP );
        }
        $this->assertSame( 3, gr_check_login_lockout( 'admin', self::IP )['round'] );
        $this->assertSame( 240, Gr_Temp_Bans::lock_remaining( self::IP ) );

        // With base 60 the doubling passes a day at round 12
        // (60*2^11 = 122880 > 86400); from there the cap holds.
        for ( $round = 4; $round <= 12; $round++ ) {
            Gr_Temp_Bans::unblock( self::IP );
            for ( $i = 0; $i < 5; $i++ ) {
                gr_record_login_failure( 'admin', self::IP );
            }
        }
        $this->assertSame( 12, gr_check_login_lockout( 'admin', self::IP )['round'] );
        $this->assertSame( 86400, Gr_Temp_Bans::lock_remaining( self::IP ) );
    }

    public function testPairsAreCountedIndependently(): void {
        for ( $i = 0; $i < 5; $i++ ) {
            gr_record_login_failure( 'admin', self::IP );
        }

        // The lock is on the address, but another username pair counts
        // from its own zero; the round memory is per address.
        $state = gr_check_login_lockout( 'editor', self::IP );
        $this->assertTrue( $state['locked'] );
        $this->assertSame( 0, $state['failures'] );
        $this->assertSame( 1, $state['round'] );
    }

    public function testAllowListReadsAsNeverLockedAndNeverPrimesRounds(): void {
        $GLOBALS['wpdb']->results = array(
            array(
                'rule_type'   => 'allow',
                'match_kind'  => 'ip',
                'match_value' => self::IP,
            ),
        );

        // Failures on a trusted address count nowhere near a lock.
        for ( $i = 0; $i < 10; $i++ ) {
            gr_record_login_failure( 'admin', self::IP );
        }

        $state = gr_check_login_lockout( 'admin', self::IP );
        $this->assertFalse( $state['locked'] );
        $this->assertSame( 0, $state['round'] );
        $this->assertFalse( Gr_Temp_Bans::is_locked( self::IP ) );
    }

    public function testCliUnblockReleasesAnActiveLockout(): void {
        for ( $i = 0; $i < 5; $i++ ) {
            gr_record_login_failure( 'admin', self::IP );
        }
        $this->assertTrue( gr_check_login_lockout( 'admin', self::IP )['locked'] );

        Gr_Temp_Bans::unblock( self::IP );

        $state = gr_check_login_lockout( 'admin', self::IP );
        $this->assertFalse( $state['locked'] );
        // The round memory survives the release, keeping the gradient.
        $this->assertSame( 1, $state['round'] );
    }

    public function testDefaultModeIsRecordOnlyAtTheGate(): void {
        for ( $i = 0; $i < 5; $i++ ) {
            gr_record_login_failure( 'admin', self::IP );
        }

        // The gate judges the live request's address.
        $_SERVER['REMOTE_ADDR'] = self::IP;

        // Default 'log': the gate passes credentials through untouched.
        $user = (object) array( 'id' => 7 );
        $this->assertSame(
            $user,
            Gr_Login_Protection::gate( $user, 'admin', 'pw' )
        );

        // Escalated 'block': a locked address is denied.
        gr()->settings()->set( 'security_action_mode', 'block' );
        $denied = Gr_Login_Protection::gate( $user, 'admin', 'pw' );
        $this->assertInstanceOf( \WP_Error::class, $denied );
        $this->assertSame( 'gr_login_locked', $denied->get_error_code() );

        unset( $_SERVER['REMOTE_ADDR'] );
    }

    public function testDisabledSecuritySwitchStopsTheModule(): void {
        gr()->settings()->set( 'security_enabled', 0 );

        Gr_Login_Protection::handle_failure( 'admin' );

        $this->assertSame( array(), $GLOBALS['gr_stub_transients'] );
    }

    public function testWpLoginFailedWiringCountsForTheLiveRequest(): void {
        Gr_Login_Protection::register_hooks();

        $_SERVER['REMOTE_ADDR'] = '203.0.113.56';
        do_action( 'wp_login_failed', 'admin' );

        $state = gr_check_login_lockout( 'admin', '203.0.113.56' );
        $this->assertSame( 1, $state['failures'] );
    }

    public function testFailuresAndTriggersReachTheFoldLog(): void {
        for ( $i = 0; $i < 5; $i++ ) {
            gr_record_login_failure( 'admin', self::IP );
        }

        $rules = array();
        foreach ( $GLOBALS['wpdb']->queries as $sql ) {
            if ( false !== strpos( (string) $sql, 'INSERT INTO wp_gr_security_logs' ) ) {
                if ( false !== strpos( (string) $sql, "'login_fail'" ) ) {
                    $rules['login_fail'] = true;
                }
                if ( false !== strpos( (string) $sql, "'login_lockout'" ) ) {
                    $rules['login_lockout'] = true;
                }
            }
        }

        // Each failure wrote a folded row; the trigger wrote the
        // lockout row.
        $this->assertArrayHasKey( 'login_fail', $rules );
        $this->assertArrayHasKey( 'login_lockout', $rules );
    }

    public function testPluginRegistersTheHooks(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        $hooks = array();
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            $hooks[] = array( $registration['hook'], $registration['priority'] );
        }

        $this->assertContains( array( 'wp_login_failed', 10 ), $hooks );

        $gate_registered = false;
        foreach ( $GLOBALS['gr_stub_filters']['authenticate'] ?? array() as $callback ) {
            if ( is_array( $callback ) && Gr_Login_Protection::class === $callback[0] ) {
                $gate_registered = true;
            }
        }
        $this->assertTrue( $gate_registered );
    }
}
