<?php
/**
 * Blackhole trap (docs/13 W12, PEER-01): the robots.txt declaration,
 * the virtual endpoint's hit detection, the record/ban split, the
 * 403 answer, and the inert paths (module off, master fuse, ordinary
 * requests — the spiders that respect robots.txt never even arrive).
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Security\Gr_Blackhole;
use GreenPNG\Security\Gr_Security_Gate;
use PHPUnit\Framework\TestCase;

final class BlackholeTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();

        $_SERVER['REMOTE_ADDR'] = '10.0.0.9';
        $_SERVER['REQUEST_URI'] = '/ordinary-page/';
    }

    protected function tearDown(): void {
        unset( $_SERVER['REMOTE_ADDR'], $_SERVER['REQUEST_URI'] );
        parent::tearDown();
    }

    /**
     * Turns the module on for a test.
     *
     * @return void
     */
    private function enable(): void {
        gr()->settings()->set( 'blackhole_enabled', 1 );
    }

    public function testRobotsRulesDeclareTheTrapWhenEnabled(): void {
        $this->enable();

        $base   = "User-agent: *\nDisallow: /wp-admin/";
        $robots = Gr_Blackhole::robots_rules( $base, true );

        $this->assertStringStartsWith( $base, $robots );
        $this->assertStringContainsString( 'Disallow: ' . Gr_Blackhole::TRAP_PATH, $robots );
    }

    public function testRobotsRulesStayCleanWhenModuleOff(): void {
        $base = "User-agent: *\nDisallow: /wp-admin/";

        $this->assertSame( $base, Gr_Blackhole::robots_rules( $base, true ) );
    }

    public function testRobotsRulesStayCleanUnderTheMasterFuse(): void {
        $this->enable();
        Gr_Security_Gate::reset_for_tests( true );

        $base = "User-agent: *\nDisallow: /wp-admin/";

        $this->assertSame( $base, Gr_Blackhole::robots_rules( $base, true ) );
    }

    public function testHitDetectionCoversThePathShapes(): void {
        $this->enable();

        $hits = array(
            '/gr-blackhole/',
            '/gr-blackhole',
            '/gr-blackhole/?utm_source=scan',
        );
        foreach ( $hits as $uri ) {
            $_SERVER['REQUEST_URI'] = $uri;
            $this->assertTrue( Gr_Blackhole::is_hit(), "should hit: {$uri}" );
        }

        $misses = array(
            '/ordinary-page/',
            '/gr-blackhole-zone/',
            '/other/gr-blackhole/',
            '/',
            '/GR-BLACKHOLE/',
        );
        foreach ( $misses as $uri ) {
            $_SERVER['REQUEST_URI'] = $uri;
            $this->assertFalse( Gr_Blackhole::is_hit(), "should miss: {$uri}" );
        }
    }

    public function testHitDetectionInertWhenDisabled(): void {
        $_SERVER['REQUEST_URI'] = '/gr-blackhole/';

        $this->assertFalse( Gr_Blackhole::is_hit() );
    }

    public function testHandleHitLogsInRecordMode(): void {
        $this->enable();
        $_SERVER['REQUEST_URI'] = '/gr-blackhole/';

        Gr_Blackhole::handle_hit();

        $sql = implode( ' ', $GLOBALS['wpdb']->queries );
        $this->assertStringContainsString( 'blackhole', $sql );
        $this->assertStringContainsString( 'disallowed trap path crawled', $sql );

        // Record-only: no ban lock exists.
        $this->assertFalse( get_transient( 'gr_block_' . md5( '10.0.0.9' ) ) );
    }

    public function testHandleHitBansInBlockMode(): void {
        $this->enable();
        gr()->settings()->set( 'security_action_mode', 'block' );
        $_SERVER['REQUEST_URI'] = '/gr-blackhole/';

        Gr_Blackhole::handle_hit();

        $lock = get_transient( 'gr_block_' . md5( '10.0.0.9' ) );
        $this->assertIsArray( $lock );
        $this->assertSame( 'blackhole: disallowed path', $lock['reason'] );
    }

    public function testInterceptAnswers403AndLogsTheHit(): void {
        $this->enable();
        $_SERVER['REQUEST_URI'] = '/gr-blackhole/';

        Gr_Blackhole::intercept();

        $this->assertCount( 1, $GLOBALS['gr_stub_wp_die'] );
        $this->assertSame( 'Access denied.', $GLOBALS['gr_stub_wp_die'][0]['message'] );
        $this->assertSame( 403, $GLOBALS['gr_stub_wp_die'][0]['args']['response'] );

        $sql = implode( ' ', $GLOBALS['wpdb']->queries );
        $this->assertStringContainsString( 'blackhole', $sql );
    }

    public function testOrdinaryRequestsNeverTriggerTheTrap(): void {
        $this->enable();
        $_SERVER['REQUEST_URI'] = '/about-us/?p=2';

        Gr_Blackhole::intercept();

        // The robots.txt-respecting visitor never even arrives at the
        // trap path — and every other path is equally uninteresting.
        $this->assertSame( array(), $GLOBALS['gr_stub_wp_die'] );
        $this->assertSame( array(), $GLOBALS['wpdb']->queries );
    }

    public function testInterceptInertWhenDisabled(): void {
        $_SERVER['REQUEST_URI'] = '/gr-blackhole/';

        Gr_Blackhole::intercept();

        $this->assertSame( array(), $GLOBALS['gr_stub_wp_die'] );
        $this->assertSame( array(), $GLOBALS['wpdb']->queries );
    }

    public function testPluginRegistersTheTrap(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        $found = null;
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            if ( 'init' === (string) $registration['hook']
                && array( Gr_Blackhole::class, 'intercept' ) === $registration['callback'] ) {
                $found = $registration;
            }
        }
        $this->assertNotNull( $found );
        $this->assertSame( 5, $found['priority'] );

        $robots = null;
        foreach ( $GLOBALS['gr_stub_filters'] as $hook => $callbacks ) {
            if ( 'robots_txt' === (string) $hook ) {
                $robots = $callbacks;
            }
        }
        $this->assertNotNull( $robots );
    }
}
