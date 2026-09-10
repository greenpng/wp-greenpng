<?php
/**
 * Surge-fold security log (docs/13 W6, docs/05 §3.1): fold-key
 * grouping, the atomic upsert shape, the anonymize switch, column
 * bounds, and the findings-channel subscriber.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Security\Gr_Request_Inspector;
use GreenPNG\Security\Gr_Scanner_Ua;
use GreenPNG\Security\Gr_Security_Logger;
use GreenPNG\Storage\Gr_Security_Log_Repository;
use PHPUnit\Framework\TestCase;

final class SecurityLogTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();

        $_SERVER['REMOTE_ADDR']     = '10.0.0.9';
        $_SERVER['HTTP_USER_AGENT'] = 'UnitTestAgent/1.0';
        $_SERVER['REQUEST_METHOD']  = 'GET';
        $_SERVER['REQUEST_URI']     = '/probe/path/?q=1';
    }

    protected function tearDown(): void {
        unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'], $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'] );
        $_COOKIE = array();
        parent::tearDown();
    }

    /**
     * Every INSERT..ON DUPLICATE record the repository issued this
     * test, in order; prepare() and query() each record one entry, so
     * one log() call leaves two identical lines.
     *
     * @return array<int, string>
     */
    private function fold_inserts(): array {
        global $wpdb;

        $found = array();
        foreach ( $wpdb->queries as $sql ) {
            if ( false !== strpos( (string) $sql, 'INSERT INTO wp_gr_security_logs' ) ) {
                $found[] = (string) $sql;
            }
        }

        return $found;
    }

    /**
     * Distinct upsert shapes, collapsing the prepare/query duplicates.
     *
     * @return array<int, string>
     */
    private function fold_distinct(): array {
        return array_values( array_unique( $this->fold_inserts() ) );
    }

    public function testFoldKeyGroupsAddressRuleAndHourWindow(): void {
        $repo = new Gr_Security_Log_Repository();

        $repo->log( '203.0.113.7', 'scanner_ua', '/x', 'sqlmap/1.0', 'ua:sqlmap', 700 );
        $repo->log( '203.0.113.7', 'scanner_ua', '/x', 'sqlmap/1.0', 'ua:sqlmap', 700 );
        $repo->log( '203.0.113.7', 'scanner_ua', '/x', 'sqlmap/1.0', 'ua:sqlmap', 701 );
        $repo->log( '203.0.113.8', 'scanner_ua', '/x', 'sqlmap/1.0', 'ua:sqlmap', 700 );
        $repo->log( '203.0.113.7', 'honeypot', '/x', 'sqlmap/1.0', 'trap', 700 );

        // Five calls, each leaving one prepare and one query record.
        $inserts = $this->fold_inserts();
        $this->assertCount( 10, $inserts );

        // The same fold twice issues byte-identical upserts — the row
        // count under the fold key is what the upsert collapses.
        $this->assertSame( $inserts[0], $inserts[2] );

        $same_fold = md5( '203.0.113.7|scanner_ua|700' );
        $this->assertStringContainsString( "'{$same_fold}'", $inserts[0] );

        // A new window, address, or rule each folds under its own key.
        $shapes = $this->fold_distinct();
        $this->assertCount( 4, $shapes );
        $this->assertStringContainsString( "'" . md5( '203.0.113.7|scanner_ua|701' ) . "'", $shapes[1] );
        $this->assertStringContainsString( "'" . md5( '203.0.113.8|scanner_ua|700' ) . "'", $shapes[2] );
        $this->assertStringContainsString( "'" . md5( '203.0.113.7|honeypot|700' ) . "'", $shapes[3] );
    }

    public function testUpsertShapeIsTheAtomicFold(): void {
        ( new Gr_Security_Log_Repository() )->log( '203.0.113.7', 'scanner_ua', '/p', 'sqlmap/1.0', 'ua:sqlmap', 700 );

        $sql = $this->fold_inserts()[0];

        $this->assertStringContainsString( 'INSERT INTO wp_gr_security_logs', $sql );
        // The address travels as text and the column is filled in SQL,
        // so raw binary never crosses the escaping layer.
        $this->assertStringContainsString( 'INET6_ATON(\'203.0.113.7\')', $sql );
        // The fold: counter bump and last_seen slide, nothing else.
        $this->assertStringContainsString(
            'ON DUPLICATE KEY UPDATE hit_count = hit_count + 1, last_seen = VALUES(last_seen)',
            $sql
        );
        $this->assertStringContainsString( "'logged'", $sql );
        $this->assertStringContainsString( "'scanner_ua'", $sql );
    }

    public function testAnonymizeSwitchTruncatesBeforeStorage(): void {
        $repo = new Gr_Security_Log_Repository();

        // Default off: the full address reaches the column.
        $repo->log( '203.0.113.99', 'r', '', '', '', 700 );
        $this->assertStringContainsString( 'INET6_ATON(\'203.0.113.99\')', $this->fold_inserts()[0] );

        gr()->settings()->set( 'security_log_anonymize', 1 );

        // IPv4 collapses to its /24; IPv6 to its /48.
        $repo->log( '203.0.113.99', 'r', '', '', '', 700 );
        $repo->log( '2001:db8:abcd:1234::1', 'r', '', '', '', 700 );
        $shapes = $this->fold_distinct();

        $this->assertStringContainsString( 'INET6_ATON(\'203.0.113.0\')', $shapes[1] );
        $this->assertStringContainsString( 'INET6_ATON(\'2001:db8:abcd::\')', $shapes[2] );
    }

    public function testInvalidAddressFallsBackToUnspecified(): void {
        ( new Gr_Security_Log_Repository() )->log( 'garbage', 'r', '', '', '', 700 );

        $this->assertStringContainsString( 'INET6_ATON(\'0.0.0.0\')', $this->fold_inserts()[0] );
    }

    public function testColumnBoundsAreTruncated(): void {
        ( new Gr_Security_Log_Repository() )->log(
            '203.0.113.7',
            str_repeat( 'r', 80 ),
            str_repeat( 'p', 300 ),
            str_repeat( 'u', 300 ),
            str_repeat( 'x', 300 ),
            700
        );

        $sql = $this->fold_inserts()[0];

        $this->assertStringContainsString( "'" . str_repeat( 'r', 64 ) . "'", $sql );
        $this->assertStringNotContainsString( "'" . str_repeat( 'r', 65 ) . "'", $sql );
        $this->assertStringContainsString( "'" . str_repeat( 'p', 191 ) . "'", $sql );
        $this->assertStringContainsString( "'" . str_repeat( 'u', 191 ) . "'", $sql );
        $this->assertStringContainsString( "'" . str_repeat( 'x', 191 ) . "'", $sql );
    }

    public function testFacadeLogsThroughTheRepository(): void {
        gr_log_security_event( '203.0.113.7', 'scanner_ua', '/f', 'curl/8', 'ua:curl' );

        $shapes = $this->fold_distinct();
        $this->assertCount( 1, $shapes );
        $this->assertStringContainsString( 'INET6_ATON(\'203.0.113.7\')', $shapes[0] );
    }

    public function testLoggerSubscribesToFindingsAndWritesPerFinding(): void {
        ( new Gr_Security_Logger( new Gr_Security_Log_Repository() ) )->register_hooks();

        do_action(
            Gr_Request_Inspector::FINDINGS_HOOK,
            array(
                array(
                    'rule_id' => 'scanner_ua',
                    'reason'  => 'ua:sqlmap',
                ),
                array(
                    'rule_id' => 'honeypot',
                    'reason'  => 'trap touched',
                ),
                'not a finding',
            )
        );

        // Two findings, two upsert shapes; the malformed row is skipped.
        $shapes = $this->fold_distinct();
        $this->assertCount( 2, $shapes );
        $this->assertStringContainsString( "'scanner_ua'", $shapes[0] );
        $this->assertStringContainsString( 'ua:sqlmap', $shapes[0] );
        $this->assertStringContainsString( "'honeypot'", $shapes[1] );

        // Request context joined the rows: resolver address, live path,
        // live agent from the stubbed request.
        $this->assertStringContainsString( 'INET6_ATON(\'10.0.0.9\')', $shapes[0] );
        $this->assertStringContainsString( "'/probe/path/?q=1'", $shapes[0] );
        $this->assertStringContainsString( "'UnitTestAgent/1.0'", $shapes[0] );
    }

    public function testLoggerFeedRunsEndToEndFromTheRealDetector(): void {
        // The W2 detector through the real frame, with the W6 logger
        // subscribed: one scanner request lands as one fold row.
        Gr_Scanner_Ua::register_detector();
        ( new Gr_Security_Logger( new Gr_Security_Log_Repository() ) )->register_hooks();

        $_SERVER['HTTP_USER_AGENT'] = 'sqlmap/1.7.2#pip (stable Python3.11)';
        $findings                   = gr()->inspector()->inspect();

        $this->assertSame( 'scanner_ua', $findings[0]['rule_id'] );

        $shapes = $this->fold_distinct();
        $this->assertCount( 1, $shapes );
        $this->assertStringContainsString( "'scanner_ua'", $shapes[0] );
        $this->assertStringContainsString( 'INET6_ATON(\'10.0.0.9\')', $shapes[0] );
    }

    public function testPluginRegistersTheLoggerOnTheFindingsChannel(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        $hook       = Gr_Request_Inspector::FINDINGS_HOOK;
        $registered = false;

        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            $callback = $registration['callback'];
            if ( $hook === (string) $registration['hook']
                && is_array( $callback )
                && $callback[0] instanceof Gr_Security_Logger ) {
                $registered = true;
            }
        }

        $this->assertTrue( $registered );
    }
}
