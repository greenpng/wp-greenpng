<?php
/**
 * Master security fuse (docs/13 W11): the single choke point every
 * module consults. The owner switch and the emergency constant are
 * two distinct reasons to be down, and the emergency verdict —
 * wp-config, above the database — must stop the frame, the forms,
 * the login gates, and the queue work all at once. The constant path
 * itself is driven through the override seam because process
 * isolation hangs on this PHP build (NOTES W11).
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Security\Gr_Crawler_Verify;
use GreenPNG\Security\Gr_Honeypot;
use GreenPNG\Security\Gr_Login_Protection;
use GreenPNG\Security\Gr_Request_Inspector;
use GreenPNG\Security\Gr_Security_Gate;
use PHPUnit\Framework\TestCase;

final class SecurityGateTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();

        $_SERVER['REMOTE_ADDR']     = '10.0.0.9';
        $_SERVER['HTTP_USER_AGENT'] = 'UnitTestAgent/1.0';
    }

    protected function tearDown(): void {
        unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] );
        parent::tearDown();
    }

    public function testGateIsActiveByDefault(): void {
        $this->assertTrue( Gr_Security_Gate::active() );
        $this->assertFalse( Gr_Security_Gate::emergency_off() );
    }

    public function testOwnerSettingStandsTheStackDown(): void {
        gr()->settings()->set( 'security_enabled', 0 );

        $this->assertFalse( Gr_Security_Gate::active() );
        // A settings-driven stand-down is not an emergency.
        $this->assertFalse( Gr_Security_Gate::emergency_off() );
    }

    public function testEmergencyVerdictOutranksTheOwnerSwitch(): void {
        gr()->settings()->set( 'security_enabled', 1 );
        Gr_Security_Gate::reset_for_tests( true );

        $this->assertTrue( Gr_Security_Gate::emergency_off() );
        $this->assertFalse( Gr_Security_Gate::active() );
    }

    public function testEmergencyVerdictStopsTheFrame(): void {
        Gr_Security_Gate::reset_for_tests( true );

        $ran = false;
        $GLOBALS['gr_stub_filters'][ Gr_Request_Inspector::CHECKS_FILTER ] = array(
            static function () use ( &$ran ) {
                $ran = true;

                return array();
            },
        );

        ( new Gr_Request_Inspector() )->run();

        $this->assertFalse( $ran, 'checks filter must not even be applied' );
        $this->assertSame( array(), $GLOBALS['gr_stub_fired_action_args'] );
    }

    public function testEmergencyVerdictStopsFormsAndLoginGates(): void {
        gr()->settings()->set( 'honeypot_enabled', 1 );
        Gr_Security_Gate::reset_for_tests( true );

        // Forms carry no traps, even with the module's own opt-in on.
        $this->assertSame( '', Gr_Honeypot::render( 'login' ) );

        // Login gates stand down: a locked-out address passes through
        // untouched, and the failure hook records nothing.
        set_transient( 'gr_login_fails_' . md5( '10.0.0.9|admin' ), 99, 3600 );
        $out = Gr_Login_Protection::gate( null );
        $this->assertNull( $out );

        Gr_Login_Protection::handle_failure( 'admin' );
        $this->assertFalse( get_transient( 'gr_login_locks_' . md5( '10.0.0.9' ) ) );
    }

    public function testEmergencyVerdictStopsQueueWork(): void {
        Gr_Security_Gate::reset_for_tests( true );

        $GLOBALS['gr_stub_dns']['ptr']['10.0.0.9'] = 'x.test';
        Gr_Crawler_Verify::handle_job( '10.0.0.9', 'Googlebot/2.1' );

        $this->assertSame( array(), $GLOBALS['gr_stub_dns']['calls'], 'worker must not resolve anything' );
        $this->assertFalse( get_transient( 'gr_fcrdns_' . md5( '10.0.0.9|Googlebot/2.1' ) ) );

        $GLOBALS['wpdb']->results = array(
            array( 'ip' => inet_pton( '10.0.0.9' ), 'user_agent' => 'Googlebot/2.1' ),
        );
        Gr_Crawler_Verify::sweep();
        $this->assertSame( array(), $GLOBALS['gr_stub_cron'] );
    }

    public function testOverrideSeamClearsBackToTheConstantPath(): void {
        Gr_Security_Gate::reset_for_tests( true );
        $this->assertFalse( Gr_Security_Gate::active() );

        Gr_Security_Gate::reset_for_tests( null );
        $this->assertTrue( Gr_Security_Gate::active() );
        $this->assertFalse( Gr_Security_Gate::emergency_off() );
    }
}
