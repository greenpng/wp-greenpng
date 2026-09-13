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
use GreenPNG\Security\Gr_Access_Rules;
use GreenPNG\Security\Gr_Request_Inspector;
use GreenPNG\Security\Gr_Temp_Bans;
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

        // Match by callback, not by hook: other modules ride init too
        // (W12's trap at 5), and any-init-matching would find the
        // wrong registration.
        $found = null;
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            if ( 'init' === (string) $registration['hook']
                && array( gr()->inspector(), 'run' ) === $registration['callback'] ) {
                $found = $registration;
            }
        }
        $this->assertNotNull( $found );
        $this->assertSame( 10, $found['priority'] );
    }

    public function testRunBuildsContextAndCollectsFindings(): void {
        $inspector = new Gr_Request_Inspector();

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

        ( new Gr_Request_Inspector() )->inspect();

        $this->assertSame( 'GET', $seen['method'] );
        $this->assertSame( '/some/path/?q=1', $seen['path'] );
    }

    public function testThrowingCheckIsIsolatedAndReported(): void {
        $inspector = new Gr_Request_Inspector();

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

    public function testCheckMayReturnAListOfFindings(): void {
        $inspector = new Gr_Request_Inspector();

        $this->register_checks(
            array(
                function (): array {
                    // A payload-style check settles several parameters
                    // at once: one list, garbage entries dropped.
                    return array(
                        array( 'rule_id' => 'sqli_union', 'reason' => 'cat:UNION SELECT' ),
                        'not-a-finding',
                        array( 'rule_id' => 'lfi_traversal', 'reason' => 'template:../../' ),
                    );
                },
            )
        );

        $findings = $inspector->inspect();

        $this->assertCount( 2, $findings );
        $this->assertSame( 'sqli_union', $findings[0]['rule_id'] );
        $this->assertSame( 'lfi_traversal', $findings[1]['rule_id'] );
    }

    public function testFindingsAreNormalizedToRuleAndReason(): void {
        $inspector = new Gr_Request_Inspector();

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
        ( new Gr_Request_Inspector() )->run();
        $this->assertTrue( $ran );

        // Switched off: zero work, the filter is never even applied.
        gr()->settings()->set( 'security_enabled', 0 );
        $GLOBALS['gr_stub_fired_action_args'] = array();
        $ran = false;
        ( new Gr_Request_Inspector() )->run();
        $this->assertFalse( $ran );
        $this->assertSame( array(), $GLOBALS['gr_stub_fired_action_args'] );

        // Admin context: the frame stays out of admin panels.
        gr()->settings()->set( 'security_enabled', 1 );
        $GLOBALS['gr_stub_is_admin'] = true;
        $ran = false;
        ( new Gr_Request_Inspector() )->run();
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

        $inspector = new Gr_Request_Inspector();

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

    /**
     * Seeds the active access-rule rows the way the repository reads
     * them, for the front-door tests.
     *
     * @param array<int, array<string, string>> $rules Active rows.
     * @return void
     */
    private function seed_rules( array $rules ): void {
        global $wpdb;
        $wpdb->results = $rules;
    }

    public function testRunRefusesAStaticallyBannedAddressBeforeTheDetectors(): void {
        global $wpdb;
        $wpdb->query_result = 1;
        $this->seed_rules(
            array(
                array(
                    'rule_type'   => 'ban',
                    'match_kind'  => 'ip',
                    'match_value' => '10.0.0.9',
                ),
            )
        );

        $ran = false;
        $this->register_checks(
            array(
                static function ( array $context ) use ( &$ran ): array {
                    $ran = true;
                    return array();
                },
            )
        );

        ( new Gr_Request_Inspector() )->run();

        // Refused before the detectors ever ran (ADR-0009 D3): an
        // owner-written ban is enforced unconditionally, in log mode
        // too, because the tier model's heuristic caution never
        // applied to a hand-written rule.
        $this->assertFalse( $ran );
        $this->assertNotSame( array(), $GLOBALS['gr_stub_wp_die'] );
        $this->assertSame( 403, $GLOBALS['gr_stub_wp_die'][0]['args']['response'] );

        // The hit rides the fold log straight to the repository under
        // the 'blocked' action word; a refused address never feeds
        // the conclusions channel.
        $sql = implode( ' ', $wpdb->queries );
        $this->assertStringContainsString( 'ip_ban', $sql );
        $this->assertStringContainsString( 'blocked', $sql );

        $fired_findings = false;
        foreach ( $GLOBALS['gr_stub_fired_action_args'] as $record ) {
            if ( Gr_Request_Inspector::FINDINGS_HOOK === $record['hook'] ) {
                $fired_findings = true;
            }
        }
        $this->assertFalse( $fired_findings );
    }

    public function testALockedAddressIsOnlyRefusedInBlockMode(): void {
        Gr_Temp_Bans::block( '10.0.0.9', 'login gradient', 300 );

        $ran = false;
        $this->register_checks(
            array(
                static function ( array $context ) use ( &$ran ): array {
                    $ran = true;
                    return array();
                },
            )
        );

        // Log mode: the front door stays a pure observer, the
        // detectors still see the request.
        ( new Gr_Request_Inspector() )->run();
        $this->assertTrue( $ran );
        $this->assertSame( array(), $GLOBALS['gr_stub_wp_die'] );

        // Block mode: the same lock now refuses before the detectors.
        ( new Gr_Settings() )->set( 'security_action_mode', 'block' );
        ( new Gr_Request_Inspector() )->run();

        $this->assertNotSame( array(), $GLOBALS['gr_stub_wp_die'] );
        $this->assertSame( 403, $GLOBALS['gr_stub_wp_die'][0]['args']['response'] );
    }

    public function testUrlAllowRulesSkipTheDetectorsButNeverTheBan(): void {
        global $wpdb;
        $wpdb->query_result = 1;
        $this->seed_rules(
            array(
                array(
                    'rule_type'   => 'allow',
                    'match_kind'  => 'url',
                    'match_value' => '/some/path',
                ),
            )
        );

        $ran = false;
        $this->register_checks(
            array(
                static function ( array $context ) use ( &$ran ): array {
                    $ran = true;
                    return array();
                },
            )
        );

        // An exempted URI: the detectors never run, nothing is
        // refused.
        ( new Gr_Request_Inspector() )->run();
        $this->assertFalse( $ran );
        $this->assertSame( array(), $GLOBALS['gr_stub_wp_die'] );

        // The same exempted URI from a banned address: still refused.
        // The URL axis exempts detectors, never the ban arms.
        $this->seed_rules(
            array(
                array(
                    'rule_type'   => 'allow',
                    'match_kind'  => 'url',
                    'match_value' => '/some/path',
                ),
                array(
                    'rule_type'   => 'ban',
                    'match_kind'  => 'ip',
                    'match_value' => '10.0.0.9',
                ),
            )
        );
        Gr_Access_Rules::reset_for_tests();

        ( new Gr_Request_Inspector() )->run();

        $this->assertNotSame( array(), $GLOBALS['gr_stub_wp_die'] );
        $this->assertSame( 403, $GLOBALS['gr_stub_wp_die'][0]['args']['response'] );
    }

    public function testAFrontDoorFailureFailsOpenToTheDetectors(): void {
        global $wpdb;

        // The rule store throwing stands in for any guard-layer
        // failure: the frame must neither block the page nor silence
        // the detectors (docs/02 §2.7 fail-open).
        $wpdb->results = static function ( string $sql ): array {
            throw new \RuntimeException( 'rules store down' );
        };

        $ran = false;
        $this->register_checks(
            array(
                static function ( array $context ) use ( &$ran ): array {
                    $ran = true;
                    return array();
                },
            )
        );

        ( new Gr_Request_Inspector() )->run();

        $this->assertTrue( $ran );
        $this->assertSame( array(), $GLOBALS['gr_stub_wp_die'] );

        $reported = null;
        foreach ( $GLOBALS['gr_stub_fired_action_args'] as $record ) {
            if ( Gr_Request_Inspector::ERROR_HOOK === $record['hook'] ) {
                $reported = $record['args'];
            }
        }
        $this->assertNotNull( $reported );
        $this->assertSame( 'front_door', $reported[0] );
        $this->assertInstanceOf( \Throwable::class, $reported[1] );
    }
}
