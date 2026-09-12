<?php
/**
 * FCrDNS engine (docs/13 W10, docs/03 §3): the forward-confirmed
 * verdict over both record families, the never-forged contract, the
 * 24h cache for both verdicts, the first-seen enqueue on the findings
 * hook, the queue worker, and the daily sweep.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Core\Gr_Queue;
use GreenPNG\Security\Gr_Crawler_Verify;
use GreenPNG\Security\Gr_Request_Inspector;
use PHPUnit\Framework\TestCase;

final class CrawlerVerifyTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();

        $_SERVER['REMOTE_ADDR']      = '66.249.66.1';
        $_SERVER['HTTP_USER_AGENT']  = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
    }

    protected function tearDown(): void {
        unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] );
        parent::tearDown();
    }

    /**
     * Seeds a fully confirmable Googlebot claim.
     *
     * @return void
     */
    private function seed_googlebot(): void {
        $GLOBALS['gr_stub_dns']['ptr']['66.249.66.1'] = 'crawl-66-249-66-1.googlebot.com';
        $GLOBALS['gr_stub_dns']['forward'][ DNS_A ]['crawl-66-249-66-1.googlebot.com'] = array(
            array( 'ip' => '66.249.66.1' ),
        );
    }

    public function testForwardConfirmedV4IsVerified(): void {
        $this->seed_googlebot();

        $result = Gr_Crawler_Verify::resolve( '66.249.66.1', 'Googlebot/2.1' );

        $this->assertSame( Gr_Crawler_Verify::STATUS_VERIFIED, $result['status'] );
        $this->assertSame( 'crawl-66-249-66-1.googlebot.com', $result['host'] );
    }

    public function testForwardConfirmedV6ThroughAaaaAndTextualVariant(): void {
        // The reference project's IPv6 blind spot: the confirmation
        // lives only in AAAA, and the record spells the address in a
        // different-but-equal textual form. Both must verify.
        $GLOBALS['gr_stub_dns']['ptr']['2607:f8b0:4005:80a::200e'] = 'x200e.googlebot.com';
        $GLOBALS['gr_stub_dns']['forward'][ DNS_AAAA ]['x200e.googlebot.com'] = array(
            array( 'ipv6' => '2607:f8b0:4005:80a:0:0:0:200e' ),
        );

        $result = Gr_Crawler_Verify::resolve( '2607:f8b0:4005:80a::200e', 'Googlebot/2.1' );

        $this->assertSame( Gr_Crawler_Verify::STATUS_VERIFIED, $result['status'] );
        $this->assertSame( 'x200e.googlebot.com', $result['host'] );
    }

    public function testMissingPtrIsUnverified(): void {
        $result = Gr_Crawler_Verify::resolve( '203.0.113.9', 'Googlebot/2.1' );

        $this->assertSame( Gr_Crawler_Verify::STATUS_UNVERIFIED, $result['status'] );
        $this->assertSame( '', $result['host'] );
    }

    public function testForwardMismatchIsUnverifiedNeverForged(): void {
        $GLOBALS['gr_stub_dns']['ptr']['203.0.113.9'] = 'pretend.googlebot.com';
        $GLOBALS['gr_stub_dns']['forward'][ DNS_A ]['pretend.googlebot.com'] = array(
            array( 'ip' => '192.0.2.1' ),
        );

        $result = Gr_Crawler_Verify::resolve( '203.0.113.9', 'Googlebot/2.1' );

        // Any failure reads as unverified; the vocabulary has no
        // "forged" member to punish an address with (docs/03 §3).
        $this->assertSame( Gr_Crawler_Verify::STATUS_UNVERIFIED, $result['status'] );
        $this->assertSame( 'pretend.googlebot.com', $result['host'] );
    }

    public function testResolverDisabledFailsOpen(): void {
        $GLOBALS['gr_stub_dns']['ptr']['66.249.66.1'] = 'crawl-66-249-66-1.googlebot.com';
        $GLOBALS['gr_stub_dns']['disabled']           = true;

        $result = Gr_Crawler_Verify::resolve( '66.249.66.1', 'Googlebot/2.1' );

        $this->assertSame( Gr_Crawler_Verify::STATUS_UNVERIFIED, $result['status'] );
    }

    public function testInvalidAddressNeverTouchesDns(): void {
        Gr_Crawler_Verify::resolve( 'not-an-address', 'Googlebot/2.1' );

        $this->assertSame( array(), $GLOBALS['gr_stub_dns']['calls'] );
    }

    public function testResultCarriesTheContractShape(): void {
        $this->seed_googlebot();

        $result = Gr_Crawler_Verify::resolve( '66.249.66.1', 'Googlebot/2.1' );

        $this->assertSame(
            array( 'status', 'host', 'ip', 'ua', 'checked_at' ),
            array_keys( $result )
        );
        $this->assertSame( '66.249.66.1', $result['ip'] );
        $this->assertSame( 'Googlebot/2.1', $result['ua'] );
    }

    public function testVerifyCachesBothVerdictsFor24h(): void {
        $this->seed_googlebot();

        $first = Gr_Crawler_Verify::verify( '66.249.66.1', 'Googlebot/2.1' );
        $this->assertCount( 3, $GLOBALS['gr_stub_dns']['calls'] ); // PTR + A + AAAA.

        $second = Gr_Crawler_Verify::verify( '66.249.66.1', 'Googlebot/2.1' );
        $this->assertCount( 3, $GLOBALS['gr_stub_dns']['calls'] ); // cached, zero DNS.
        $this->assertSame( $first, $second );

        // The cache row really carries the 24h horizon.
        $key     = 'gr_fcrdns_' . md5( '66.249.66.1|Googlebot/2.1' );
        $entry   = $GLOBALS['gr_stub_transients'][ $key ];
        $this->assertSame( gr_stub_clock() + 86400, $entry['expires_at'] );

        // Unverifiable addresses cache just the same — a negative
        // verdict must not become a re-query load.
        $before = count( $GLOBALS['gr_stub_dns']['calls'] );
        Gr_Crawler_Verify::verify( '203.0.113.7', 'Googlebot/2.1' );
        Gr_Crawler_Verify::verify( '203.0.113.7', 'Googlebot/2.1' );
        $this->assertSame( $before + 1, count( $GLOBALS['gr_stub_dns']['calls'] ) ); // PTR only, once.
    }

    public function testFacadeMatchesTheEngine(): void {
        $this->seed_googlebot();

        $this->assertSame(
            Gr_Crawler_Verify::verify( '66.249.66.1', 'Googlebot/2.1' ),
            gr_verify_crawler( '66.249.66.1', 'Googlebot/2.1' )
        );
    }

    public function testFindingsQueueTheFirstScannerClaim(): void {
        Gr_Crawler_Verify::register_hooks();

        do_action(
            Gr_Request_Inspector::FINDINGS_HOOK,
            array( array( 'rule_id' => 'scanner_ua', 'reason' => 'ua:Googlebot/' ) )
        );

        $jobs = $this->queued_jobs();
        $this->assertCount( 1, $jobs );
        $this->assertSame(
            array( '66.249.66.1', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ),
            $jobs[0]['args']
        );

        // The pending flag suppresses a second claim until the worker
        // has run.
        do_action(
            Gr_Request_Inspector::FINDINGS_HOOK,
            array( array( 'rule_id' => 'scanner_ua', 'reason' => 'ua:Googlebot/' ) )
        );
        $this->assertCount( 1, $this->queued_jobs() );
    }

    public function testFindingsIgnoreNonScannerRules(): void {
        Gr_Crawler_Verify::register_hooks();

        do_action(
            Gr_Request_Inspector::FINDINGS_HOOK,
            array( array( 'rule_id' => 'sqli_union', 'reason' => 'cat:UNION SELECT' ) )
        );

        $this->assertSame( array(), $this->queued_jobs() );
    }

    public function testFindingsSkipAlreadyVerifiedClaims(): void {
        Gr_Crawler_Verify::register_hooks();
        set_transient(
            'gr_fcrdns_' . md5( '66.249.66.1|Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ),
            array( 'status' => Gr_Crawler_Verify::STATUS_UNVERIFIED ),
            86400
        );

        do_action(
            Gr_Request_Inspector::FINDINGS_HOOK,
            array( array( 'rule_id' => 'scanner_ua', 'reason' => 'ua:Googlebot/' ) )
        );

        $this->assertSame( array(), $this->queued_jobs() );
    }

    public function testWorkerHookVerifiesAndClearsPending(): void {
        Gr_Crawler_Verify::register_hooks();
        $this->seed_googlebot();

        do_action( Gr_Crawler_Verify::JOB_HOOK, '66.249.66.1', 'Googlebot/2.1' );

        $cached = get_transient( 'gr_fcrdns_' . md5( '66.249.66.1|Googlebot/2.1' ) );
        $this->assertIsArray( $cached );
        $this->assertSame( Gr_Crawler_Verify::STATUS_VERIFIED, $cached['status'] );

        $pending = 'gr_fcrdns_pending_' . md5( '66.249.66.1|Googlebot/2.1' );
        $this->assertFalse( get_transient( $pending ) );
    }

    public function testSweepEnqueuesUncachedClaimsOnly(): void {
        $cached_ip = '10.0.0.1';
        $cached_ua = 'curl/8.4.0';
        set_transient( 'gr_fcrdns_' . md5( $cached_ip . '|' . $cached_ua ), array( 'status' => 'unverified' ), 86400 );

        $GLOBALS['wpdb']->results = array(
            array( 'ip' => inet_pton( '66.249.66.1' ), 'user_agent' => 'Googlebot/2.1' ),
            array( 'ip' => inet_pton( $cached_ip ), 'user_agent' => $cached_ua ),
            array( 'ip' => 'not-binary-at-all', 'user_agent' => 'x' ),
        );

        Gr_Crawler_Verify::sweep();

        // Only the uncached, readable claim was queued.
        $jobs = $this->queued_jobs();
        $this->assertCount( 1, $jobs );
        $this->assertSame( array( '66.249.66.1', 'Googlebot/2.1' ), $jobs[0]['args'] );

        // The sweep asked for scanner claims, grouped, bounded.
        $sql = (string) end( $GLOBALS['wpdb']->queries );
        $this->assertStringContainsString( "rule_id = 'scanner_ua'", $sql );
        $this->assertStringContainsString( 'GROUP BY ip, user_agent', $sql );
        $this->assertStringContainsString( 'LIMIT 25', $sql );
    }

    public function testPluginWiresAllThreePaths(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        $hooks = array();
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            $hooks[] = (string) $registration['hook'];
        }

        $this->assertContains( Gr_Crawler_Verify::JOB_HOOK, $hooks );
        $this->assertContains( Gr_Queue::DAILY_HOOK, $hooks );
        $this->assertContains( Gr_Request_Inspector::FINDINGS_HOOK, $hooks );
    }

    /**
     * Queue jobs booked for the FCrDNS worker hook.
     *
     * @return array<int, array<string, mixed>>
     */
    private function queued_jobs(): array {
        $jobs = array();
        foreach ( $GLOBALS['gr_stub_cron'] as $event ) {
            if ( Gr_Crawler_Verify::JOB_HOOK === $event['hook'] ) {
                $jobs[] = $event;
            }
        }

        return $jobs;
    }
}
