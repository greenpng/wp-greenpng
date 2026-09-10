<?php
/**
 * Request inspector frame (docs/13 W1): wiring, gates, per-check
 * Throwable isolation, finding normalization, and the silent-skip
 * contract for inspector failures.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Core\Gr_Settings;
use GreenPNG\Security\Gr_Request_Inspector;
use PHPUnit\Framework\TestCase;

final class RequestInspectorTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();

        $_SERVER['REMOTE_ADDR']      = '10.0.0.9';
        $_SERVER['HTTP_USER_AGENT'] = 'UnitTestAgent/1.0';
        $_SERVER['REQUEST_METHOD']  = 'GET';
        $_SERVER['REQUEST_URI']     = '/some/path/?q=1';
    }

    protected function tearDown(): void {
        unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'], $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'] );
        $_COOKIE = array();
        parent::tearDown();
    }

    /**
     * Registers one or more checks the way a real target plugin would:
     * a filter callback returning the amended check list.
     *
     * @param array<int|string, callable> $checks Checks to register.
     * @return void
     */
    private function register_checks( array $checks ): void {
        $GLOBALS['gr_stub_filters'][ Gr_Request_Inspector::CHECKS_FILTER ] = array(
            function ( $value ) use ( $checks ) {
                return $checks;
            },
        );
    }

    public function testPluginWiresTheFrameAtInitPriorityTen(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        $found = null;
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            if ( 'init' === (string) $registration['hook'] ) {
                $found = $registration;
            }
        }
        $this->assertNotNull( $found );
        $this->assertSame( 10, $found['priority'] );
        $this->assertSame( array( gr()->inspector(), 'run' ), $found['callback'] );
    }

    public function testRunBuildsContextAndCollectsFindings(): void {
        $inspector = new Gr_Request_Inspector( new Gr_Settings() );

        $this->register_checks(
            array(
                function ( array $context ): array {
                    // The context the frame built is the one every
                    // check sees.
                    if ( '10.0.0.9' !== $context['ip'] || 'UnitTestAgent/1.0' !== $context['ua'] ) {
                        throw new \RuntimeException( 'bad context' );
                    }

                    return array( 'rule_id' => 'test_rule', 'reason' => 'matched fixture' );
                },
            )
        );

        $findings = $inspector->inspect();

        $this->assertCount( 1, $findings );
        $this->assertSame( 'test_rule', $findings[0]['rule_id'] );
        $this->assertSame( 'matched fixture', $findings[0]['reason'] );

        // Findings fire the hook the W6 logger will subscribe to.
        $fired = false;
        foreach ( $GLOBALS['gr_stub_fired_action_args'] as $record ) {
            if ( Gr_Request_Inspector::FINDINGS_HOOK === $record['hook'] ) {
                $fired = true;
                $this->assertSame( $findings, $record['args'][0] );
            }
        }
        $this->assertTrue( $fired );
    }

    public function testContextCarriesRequestBasics(): void {
        $seen = array();
        $this->register_checks(
            array(
                function ( array $context ) use ( &$seen ) {
                    $seen = $context;

                    return null;
                },
            )
        );

        ( new Gr_Request_Inspector( new Gr_Settings() ) )->inspect();

        $this->assertSame( 'GET', $seen['method'] );
        $this->assertSame( '/some/path/?q=1', $seen['path'] );
    }

    public function testThrowingCheckIsIsolatedAndReported(): void {
        $inspector = new Gr_Request_Inspector( new Gr_Settings() );

        $this->register_checks(
            array(
                'boom' => function (): array {
                    throw new \RuntimeException( 'detector exploded' );
                },
                'fine' => function (): array {
                    return array( 'rule_id' => 'still-ran', 'reason' => 'other check survived' );
                },
            )
        );

        $findings = $inspector->inspect();

        // The surviving check's finding still landed.
        $this->assertSame( 'still-ran', $findings[0]['rule_id'] );

        // The exploded one is reported on the error hook with its id.
        $reported = null;
        foreach ( $GLOBALS['gr_stub_fired_action_args'] as $record ) {
            if ( Gr_Request_Inspector::ERROR_HOOK === $record['hook'] ) {
                $reported = $record['args'];
            }
        }
        $this->assertNotNull( $reported );
        $this->assertSame( 'boom', $reported[0] );
        $this->assertInstanceOf( \Throwable::class, $reported[1] );
    }

    public function testFindingsAreNormalizedToRuleAndReason(): void {
        $inspector = new Gr_Request_Inspector( new Gr_Settings() );

        $this->register_checks(
            array(
                function (): array {
                    return array( 'rule_id' => 'Rule_With_Odd_Chars!', 'reason' => str_repeat( 'x', 300 ) );
                },
                function () {
                    return 'not-a-finding';
                },
                function (): array {
                    return array( 'reason' => 'missing rule id' );
                },
                function () {
                    return null;
                },
            )
        );

        $findings = $inspector->inspect();

        // Only the first check produced a finding, normalized: rule id
        // slugified, reason clamped to the column width.
        $this->assertCount( 1, $findings );
        $this->assertSame( 'rule_with_odd_chars', $findings[0]['rule_id'] );
        $this->assertSame( 191, strlen( $findings[0]['reason'] ) );
    }

    public function testRunRespectsTheEnabledAndAdminGates(): void {
        // Default on: the checks filter runs.
        $ran = false;
        $this->register_checks(
            array(
                function () use ( &$ran ): array {
                    $ran = true;

                    return null;
                },
            )
        );
        ( new Gr_Request_Inspector( new Gr_Settings() ) )->run();
        $this->assertTrue( $ran );

        // Switched off: zero work, the filter is never even applied.
        gr()->settings()->set( 'security_enabled', 0 );
        $GLOBALS['gr_stub_fired_action_args'] = array();
        $ran = false;
        ( new Gr_Request_Inspector( gr()->settings() ) )->run();
        $this->assertFalse( $ran );
        $this->assertSame( array(), $GLOBALS['gr_stub_fired_action_args'] );

        // Admin context: the frame stays out of admin panels.
        gr()->settings()->set( 'security_enabled', 1 );
        $GLOBALS['gr_stub_is_admin'] = true;
        $ran = false;
        ( new Gr_Request_Inspector( gr()->settings() ) )->run();
        $this->assertFalse( $ran );
        unset( $GLOBALS['gr_stub_is_admin'] );
    }

    public function testFrameFailureIsSilentlySkipped(): void {
        // A broken filter callback explodes while the checks are being
        // collected; run() swallows it and reports on the error hook —
        // the front end never notices (docs/02 §2.7).
        $GLOBALS['gr_stub_filters'][ Gr_Request_Inspector::CHECKS_FILTER ] = array(
            function () {
                throw new \RuntimeException( 'filter broke' );
            },
        );

        $inspector = new Gr_Request_Inspector( new Gr_Settings() );

        try {
            $inspector->run();
            $this->addToAssertionCount( 1 );
        } catch ( \Throwable $error ) {
            $this->fail( 'inspector failure escaped run(): ' . $error->getMessage() );
        }

        $reported = null;
        foreach ( $GLOBALS['gr_stub_fired_action_args'] as $record ) {
            if ( Gr_Request_Inspector::ERROR_HOOK === $record['hook'] ) {
                $reported = $record['args'];
            }
        }
        $this->assertNotNull( $reported );
        $this->assertSame( 'inspector', $reported[0] );
        $this->assertInstanceOf( \Throwable::class, $reported[1] );
    }
}
