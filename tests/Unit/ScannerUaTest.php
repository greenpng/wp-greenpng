<?php
/**
 * Scanner-UA engine (docs/13 W2, docs/07 §3): seed verdicts, the
 * browser-token short-circuit, fail-open on unreadable data, the
 * shipped-file integrity, and the first detector on the W1 frame.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Security\Gr_Request_Inspector;
use GreenPNG\Security\Gr_Scanner_Ua;
use PHPUnit\Framework\TestCase;

final class ScannerUaTest extends TestCase {

    /** Scanner agents the seed must flag (extraction-parity sample). */
    private const SCANNERS = array(
        'sqlmap/1.7.2#pip (stable Python3.11)',
        'Nuclei - Open-source project (github.com/projectdiscovery/nuclei)',
        'Googlebot/2.1 (+http://www.google.com/bot.html)',
        'bingbot/2.0; +http://www.bing.com/bingbot.htm',
        'curl/8.4.0',
        'Wget/1.21.4',
        'python-requests/2.31.0',
        'Go-http-client/2.0',
        'WordPress/6.5; https://example.com',
        'Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)',
        'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
        'Zend\Http\Client',
    );

    /** Ordinary browsers the exclusions must never flag. */
    private const BROWSERS = array(
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:124.0) Gecko/20100101 Firefox/124.0',
        'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.2210.91',
    );

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    public function testScannerAgentsAreFlagged(): void {
        foreach ( self::SCANNERS as $ua ) {
            $this->assertTrue(
                Gr_Scanner_Ua::is_scanner( $ua ),
                "seed must flag: {$ua}"
            );
        }
    }

    public function testOrdinaryBrowsersAreNotFlagged(): void {
        foreach ( self::BROWSERS as $ua ) {
            $this->assertFalse(
                Gr_Scanner_Ua::is_scanner( $ua ),
                "exclusions must clear: {$ua}"
            );
        }
    }

    public function testFacadeMatchesTheEngineVerdict(): void {
        $this->assertTrue( gr_is_scanner_ua( 'sqlmap/1.7.2#pip' ) );
        $this->assertFalse( gr_is_scanner_ua( self::BROWSERS[0] ) );
    }

    public function testEmptyAndTokenlessAgentsAreNotScanners(): void {
        $this->assertFalse( Gr_Scanner_Ua::is_scanner( '' ) );
        $this->assertFalse( Gr_Scanner_Ua::is_scanner( '   ' ) );

        // Pure browser tokens strip to nothing — the crawler regex
        // never runs, so a lone Mozilla token can never match.
        $this->assertFalse( Gr_Scanner_Ua::is_scanner( 'Mozilla/5.0' ) );
        $this->assertSame( '', Gr_Scanner_Ua::matched_token( 'Mozilla/5.0' ) );
    }

    public function testMatchedTokenCarriesTheHitFragment(): void {
        $token = Gr_Scanner_Ua::matched_token( 'sqlmap/1.7.2#pip (stable Python3.11)' );

        $this->assertNotSame( '', $token );
        $this->assertStringContainsStringIgnoringCase( 'sqlmap', $token );

        // A clean agent answers with a fragment of itself, not a
        // synthesized label.
        $google = Gr_Scanner_Ua::matched_token( 'Googlebot/2.1 (+http://www.google.com/bot.html)' );
        $this->assertStringContainsStringIgnoringCase( 'googlebot', $google );
    }

    public function testUnreadableDataFailsOpenToHuman(): void {
        Gr_Scanner_Ua::reset_for_tests( '/nonexistent/data/dir' );

        $this->assertFalse( Gr_Scanner_Ua::is_scanner( 'sqlmap/1.7.2#pip' ) );
        $this->assertSame( '', Gr_Scanner_Ua::matched_token( 'sqlmap/1.7.2#pip' ) );
    }

    public function testDataOverrideDrivesTheVerdict(): void {
        // A one-rule fixture dir proves the engine reads its patterns
        // from the data dir rather than from hardcoded knowledge.
        $dir = sys_get_temp_dir() . '/gr-scanner-ua-test-' . uniqid();
        mkdir( $dir, 0755, true );
        file_put_contents( $dir . '/gr-ua-crawlers.txt', "^GreenPNGTestProbe\n" );
        file_put_contents( $dir . '/gr-ua-exclusions.txt', "Mozilla\n" );

        Gr_Scanner_Ua::reset_for_tests( $dir );

        $this->assertTrue( Gr_Scanner_Ua::is_scanner( 'GreenPNGTestProbe/1.0' ) );
        $this->assertFalse( Gr_Scanner_Ua::is_scanner( 'sqlmap/1.7.2#pip' ) );
        $this->assertFalse( Gr_Scanner_Ua::is_scanner( 'Mozilla/5.0 GreenPNGElsewhere/1.0' ) );

        unlink( $dir . '/gr-ua-crawlers.txt' );
        unlink( $dir . '/gr-ua-exclusions.txt' );
        rmdir( $dir );
    }

    public function testShippedDataFilesCarrySeedAndLicense(): void {
        $dir = constant( 'GR_PLUGIN_DIR' ) . 'assets/data/';

        $this->assertSame( 1468, $this->pattern_count( $dir . 'gr-ua-crawlers.txt' ) );
        $this->assertSame( 52, $this->pattern_count( $dir . 'gr-ua-exclusions.txt' ) );

        $header = (string) file_get_contents( $dir . 'gr-ua-crawlers.txt', false, null, 0, 400 );
        $this->assertStringContainsString( 'Crawler-Detect', $header );
        $this->assertStringContainsString( 'MIT', $header );
        $this->assertStringContainsString( 'Mark Beech', $header );
        $this->assertStringContainsString( '2026-09-10', $header );

        $notice = (string) file_get_contents( constant( 'GR_PLUGIN_DIR' ) . 'NOTICE' );
        $this->assertStringContainsString( 'Crawler-Detect', $notice );
        $this->assertStringContainsString( 'Mark Beech', $notice );
        $this->assertStringContainsString( 'Permission is hereby granted', $notice );
    }

    public function testDetectorRunsOnTheInspectorFrame(): void {
        Gr_Scanner_Ua::register_detector();

        $_SERVER['HTTP_USER_AGENT'] = 'sqlmap/1.7.2#pip (stable Python3.11)';
        $findings                   = ( new Gr_Request_Inspector() )->inspect();

        $this->assertCount( 1, $findings );
        $this->assertSame( 'scanner_ua', $findings[0]['rule_id'] );
        $this->assertStringStartsWith( 'ua:', $findings[0]['reason'] );

        // An ordinary browser produces no finding at all.
        $_SERVER['HTTP_USER_AGENT'] = self::BROWSERS[0];
        $this->assertSame( array(), ( new Gr_Request_Inspector() )->inspect() );
    }

    public function testPluginRegistersTheDetector(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        $checks = apply_filters( Gr_Request_Inspector::CHECKS_FILTER, array() );

        $this->assertArrayHasKey( 'scanner_ua', $checks );
        $this->assertIsCallable( $checks['scanner_ua'] );
    }

    /**
     * Non-comment, non-empty pattern lines in one data file.
     *
     * @param string $file Absolute file path.
     * @return int
     */
    private function pattern_count( string $file ): int {
        $raw = (string) file_get_contents( $file );
        $this->assertNotSame( '', $raw );

        $count = 0;
        foreach ( explode( "\n", $raw ) as $line ) {
            $line = rtrim( $line, "\r" );
            if ( '' !== $line && '#' !== $line[0] ) {
                ++$count;
            }
        }

        return $count;
    }
}
