<?php
/**
 * Security-to-quality conclusions channel (docs/13 W13, docs/07 §4):
 * the pure tier mapper, the frame subscriber, the direct feeders, and
 * above all the payload contract — conclusions only, never raw
 * signals, the clause that closes the reference project's
 * fingerprint dual-use defect.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Event;
use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Security\Gr_Blackhole;
use GreenPNG\Security\Gr_Honeypot;
use GreenPNG\Security\Gr_Request_Inspector;
use GreenPNG\Security\Gr_Security_Conclusions;
use PHPUnit\Framework\TestCase;

final class SecurityConclusionsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();

        $_SERVER['REMOTE_ADDR'] = '10.0.0.9';
        $_SERVER['REQUEST_URI'] = '/some/path/';
    }

    protected function tearDown(): void {
        unset( $_SERVER['REMOTE_ADDR'], $_SERVER['REQUEST_URI'] );
        parent::tearDown();
    }

    /**
     * Feeds an event capture in, runs the callable, hands the events
     * back. The array must live in THIS scope: a capture closure
     * returned out of a helper would alias a dead local (the
     * reference-capture-through-return trap).
     *
     * @param callable $work Probe body.
     * @return array<int, Gr_Event>
     */
    private function capture_events( callable $work ): array {
        $events = array();

        $GLOBALS['gr_stub_actions'][] = array(
            'hook'     => 'gr_event',
            'callback' => static function ( Gr_Event $event ) use ( &$events ): void {
                $events[] = $event;
            },
            'priority' => 10,
        );

        $work();

        return $events;
    }

    public function testHighConfidenceRulesSettleTheHighTier(): void {
        foreach ( array( 'scanner_ua', 'sqli_union', 'lfi_traversal', 'honeypot', 'blackhole' ) as $rule ) {
            $conclusion = Gr_Security_Conclusions::conclude(
                array( array( 'rule_id' => $rule, 'reason' => 'x' ) )
            );

            $this->assertSame(
                array( 'suspected_bot' => true, 'bot_tier' => 'high' ),
                $conclusion,
                "rule should settle high: {$rule}"
            );
        }
    }

    public function testOneHighRuleSettlesTheWholeRequest(): void {
        $conclusion = Gr_Security_Conclusions::conclude(
            array(
                array( 'rule_id' => 'unclassified_thing', 'reason' => 'x' ),
                array( 'rule_id' => 'sqli_union', 'reason' => 'x' ),
            )
        );

        $this->assertSame( 'high', $conclusion['bot_tier'] );
    }

    public function testUnclassifiedRulesReadAsMedium(): void {
        $conclusion = Gr_Security_Conclusions::conclude(
            array( array( 'rule_id' => 'future_detector', 'reason' => 'x' ) )
        );

        $this->assertSame( array( 'suspected_bot' => true, 'bot_tier' => 'medium' ), $conclusion );
    }

    public function testHumanRequestsMapToNoConclusionAtAll(): void {
        $this->assertNull( Gr_Security_Conclusions::conclude( array() ) );
        $this->assertNull(
            Gr_Security_Conclusions::conclude( array( 'not-a-row', array( 'reason' => 'missing rule' ), null ) )
        );
    }

    public function testFrameFindingsDispatchOneSchemaCleanEvent(): void {
        $events = $this->capture_events(
            static function (): void {
                Gr_Security_Conclusions::handle_findings(
                    array( array( 'rule_id' => 'scanner_ua', 'reason' => 'ua:Googlebot/' ) )
                );
            }
        );

        $this->assertCount( 1, $events );
        $this->assertSame( Gr_Security_Conclusions::EVENT_NAME, $events[0]->name() );

        // THE contract (docs/07 §4): conclusions only. The payload
        // shape is asserted exactly — no rule id, no reason, no UA,
        // no address, no fingerprint fragment may ever cross.
        $this->assertSame(
            array( 'suspected_bot', 'bot_tier' ),
            array_keys( $events[0]->payload() )
        );
        $this->assertTrue( $events[0]->payload()['suspected_bot'] );
        $this->assertSame( 'high', $events[0]->payload()['bot_tier'] );

        // The event persisted with exactly that payload, nothing else.
        $this->assertNotEmpty( $GLOBALS['wpdb']->inserts );
        $row = end( $GLOBALS['wpdb']->inserts );
        $this->assertSame( Gr_Security_Conclusions::EVENT_NAME, $row['data']['event_name'] );
        $this->assertStringContainsString( 'suspected_bot', (string) $row['data']['payload_json'] );
        $this->assertStringNotContainsString( 'ua:Googlebot', (string) $row['data']['payload_json'] );
    }

    public function testHumanFindingsDispatchNothing(): void {
        $events = $this->capture_events(
            static function (): void {
                Gr_Security_Conclusions::handle_findings( array() );
            }
        );

        $this->assertSame( array(), $events );
    }

    public function testDirectFeederDispatchesForModuleVerdicts(): void {
        $events = $this->capture_events(
            static function (): void {
                Gr_Security_Conclusions::record( 'honeypot' );
            }
        );

        $this->assertCount( 1, $events );
        $this->assertSame( 'high', $events[0]->payload()['bot_tier'] );

        $none = $this->capture_events(
            static function (): void {
                Gr_Security_Conclusions::record();
            }
        );
        $this->assertSame( array(), $none );
    }

    public function testHoneypotGateFeedsTheChannelOnAVerdict(): void {
        gr()->settings()->set( 'honeypot_enabled', 1 );

        $html = Gr_Honeypot::render( 'login' );
        preg_match( '/type="text" name="([a-z]+_[0-9a-f]{6})"/', $html, $m1 );
        preg_match( '/type="hidden" name="([a-z]+_[0-9a-f]{6})" value="(gr1\.[^"]+)"/', $html, $m2 );
        $_POST = array(
            $m1[1] => 'http://spam.example/x',
            $m2[1] => $m2[2],
        );

        $out = null;
        $events = $this->capture_events(
            static function () use ( &$out ): void {
                $out = Gr_Honeypot::gate_login( null );
            }
        );

        $this->assertNull( $out ); // log mode passes the visitor through.
        $this->assertCount( 1, $events );
        $this->assertSame( Gr_Security_Conclusions::EVENT_NAME, $events[0]->name() );
        $this->assertSame( 'high', $events[0]->payload()['bot_tier'] );

        $_POST = array();
    }

    public function testBlackholeHitFeedsTheChannel(): void {
        gr()->settings()->set( 'blackhole_enabled', 1 );
        $_SERVER['REQUEST_URI'] = '/gr-blackhole/';

        $events = $this->capture_events(
            static function (): void {
                Gr_Blackhole::handle_hit();
            }
        );

        $this->assertCount( 1, $events );
        $this->assertSame( 'high', $events[0]->payload()['bot_tier'] );
    }

    public function testPluginRegistersTheFrameSubscriber(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        $found = false;
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            if ( Gr_Request_Inspector::FINDINGS_HOOK === (string) $registration['hook']
                && array( Gr_Security_Conclusions::class, 'handle_findings' ) === $registration['callback'] ) {
                $found = true;
            }
        }
        $this->assertTrue( $found );
    }

    public function testHighTierConclusionsArmTheRequestEndSessionMarker(): void {
        Gr_Security_Conclusions::handle_findings(
            array( array( 'rule_id' => 'scanner_ua', 'reason' => 'ua:sqlmap' ) )
        );

        $armed = false;
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            if ( 'shutdown' === (string) $registration['hook']
                && array( Gr_Security_Conclusions::class, 'mark_current_session' ) === $registration['callback'] ) {
                $armed = true;
            }
        }
        $this->assertTrue( $armed );
    }

    public function testMediumAndHumanConclusionsNeverArmTheMarker(): void {
        Gr_Security_Conclusions::handle_findings(
            array( array( 'rule_id' => 'future_detector', 'reason' => 'x' ) )
        );
        Gr_Security_Conclusions::handle_findings( array() );
        Gr_Security_Conclusions::record();

        $hooks = array();
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            $hooks[] = (string) $registration['hook'];
        }
        $this->assertNotContains( 'shutdown', $hooks );
    }

    public function testTheMarkerArmsOnceAndMarksOnlyTheVerdictColumn(): void {
        global $wpdb;
        $wpdb->query_result = 1;

        // One request convicting through several rules: the marker
        // arms once, not once per rule.
        Gr_Security_Conclusions::handle_findings(
            array(
                array( 'rule_id' => 'scanner_ua', 'reason' => 'x' ),
                array( 'rule_id' => 'sqli_union', 'reason' => 'y' ),
            )
        );
        Gr_Security_Conclusions::handle_findings(
            array( array( 'rule_id' => 'blackhole', 'reason' => 'z' ) )
        );

        $armed = 0;
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            if ( 'shutdown' === (string) $registration['hook'] ) {
                $armed++;
            }
        }
        $this->assertSame( 1, $armed );

        Gr_Security_Conclusions::mark_current_session();

        $sql = (string) end( $wpdb->queries );
        $this->assertStringContainsString( 'UPDATE wp_gr_sessions SET is_bot = 1', $sql );
        // The detector mark leaves the probe's measured score alone.
        $this->assertStringNotContainsString( 'bot_score', $sql );
    }
}
