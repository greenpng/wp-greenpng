<?php
/**
 * GeoIP out-of-the-box (docs/13 I5, docs/07 §5.7, ADR-0007): the
 * streaming packer (merge, validation, meta), the binary-search
 * engine over both families (bundled data, uploads override, misses,
 * memo), the facade, the consent-gated landing wiring, and the
 * standing zero-outbound rule for the whole path.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Attribution\Gr_Attribution_Listener;
use GreenPNG\Attribution\Gr_Identity;
use GreenPNG\Core\Gr_Geoip;
use GreenPNG\Core\Gr_Geoip_Packer;
use GreenPNG\Core\Gr_Settings;
use GreenPNG\Storage\Gr_Session_Repository;
use GreenPNG\Storage\Gr_Touchpoint_Repository;
use PHPUnit\Framework\TestCase;

final class GeoipTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        unset( $_GET, $_SERVER['REQUEST_URI'] );
    }

    protected function tearDown(): void {
        unset( $_GET, $_SERVER['REQUEST_URI'] );
        parent::tearDown();
    }

    /**
     * Packs a small fixture CSV into a fresh temp directory.
     *
     * @return array{dir: string, csv: string}
     */
    private function pack_fixture(): array {
        $dir = sys_get_temp_dir() . '/gr-geoip-test-' . uniqid();
        mkdir( $dir, 0777, true );

        $csv = $dir . '/fixture.csv';
        // 9.9.9.0/24 DE, 10.0.0.0-10.0.0.255 DE, 10.0.1.0-10.0.1.255
        // DE (the last two are adjacent: they merge into one record),
        // 11.0.0.5-11.0.0.6 AQ (a deliberately tiny range for boundary
        // probing), two adjacent v6 DE ranges (2001:db8::fffe + 1 is
        // 2001:db8::ffff: they merge across the boundary), and one
        // malformed line the packer must skip and count.
        file_put_contents(
            $csv,
            "9.9.9.0,9.9.9.255,DE\n" .
            "10.0.0.0,10.0.0.255,DE\n" .
            "10.0.1.0,10.0.1.255,DE\n" .
            "11.0.0.5,11.0.0.6,AQ\n" .
            "2001:db8::,2001:db8::fffe,DE\n" .
            "2001:db8::ffff,2001:db8:1::,DE\n" .
            "not,a,line\n"
        );

        return array(
            'dir' => $dir,
            'csv' => $csv,
        );
    }

    public function testPackerStreamsMergesAndRecordsMeta(): void {
        $fixture = $this->pack_fixture();

        $result = Gr_Geoip_Packer::pack( $fixture['csv'], $fixture['dir'], '2026-09' );

        // Two DE v4 ranges merged into one (10.0.0.0-10.0.1.255),
        // plus 9.9.9.0/24 and the tiny AQ range: 3 v4 records.
        $this->assertSame( 3, $result['v4_ranges'] );
        // Two adjacent v6 DE ranges merged into one record.
        $this->assertSame( 1, $result['v6_ranges'] );
        $this->assertSame( 2, $result['countries'] );
        $this->assertSame( 1, $result['skipped'] );

        // The meta carries the provenance and the country table.
        $meta = json_decode( (string) file_get_contents( $fixture['dir'] . '/' . Gr_Geoip_Packer::META_FILE ), true );
        $this->assertSame( 'DB-IP Country Lite', $meta['source'] );
        $this->assertSame( 'CC BY 4.0', $meta['license'] );
        $this->assertSame( '2026-09', $meta['build_date'] );
        $this->assertSame( 3, $meta['v4_ranges'] );
        $this->assertContains( 'DE', $meta['countries'] );
        $this->assertContains( 'AQ', $meta['countries'] );

        // Record width is the contract between packer and engine.
        $this->assertSame( 3 * Gr_Geoip_Packer::V4_RECORD, filesize( $fixture['dir'] . '/' . Gr_Geoip_Packer::V4_FILE ) );
        $this->assertSame( 1 * Gr_Geoip_Packer::V6_RECORD, filesize( $fixture['dir'] . '/' . Gr_Geoip_Packer::V6_FILE ) );
    }

    public function testPackerRefusesEmptyAndUnreadableInput(): void {
        $dir = sys_get_temp_dir() . '/gr-geoip-test-' . uniqid();
        mkdir( $dir, 0777, true );

        // Unreadable source: nothing written.
        $result = Gr_Geoip_Packer::pack( $dir . '/no-such.csv', $dir );
        $this->assertSame( 0, $result['v4_ranges'] + $result['v6_ranges'] );
        $this->assertFileDoesNotExist( $dir . '/' . Gr_Geoip_Packer::V4_FILE );

        // No usable rows: temp files removed, no final files.
        file_put_contents( $dir . '/empty.csv', "garbage,line,three\n" );
        $result = Gr_Geoip_Packer::pack( $dir . '/empty.csv', $dir );
        $this->assertSame( 0, $result['v4_ranges'] + $result['v6_ranges'] );
        $this->assertFileDoesNotExist( $dir . '/' . Gr_Geoip_Packer::META_FILE );
    }

    public function testOverrideDirectoryWinsOverBundledData(): void {
        $fixture = $this->pack_fixture();

        // The engine looks for the override under
        // <uploads>/gr-geoip, mirroring the update arm's layout.
        $override = $fixture['dir'] . '/' . Gr_Geoip::UPLOAD_SUBDIR;
        mkdir( $override, 0777, true );
        Gr_Geoip_Packer::pack( $fixture['csv'], $override, '2026-09' );
        $GLOBALS['gr_stub_uploads']['basedir'] = $fixture['dir'];

        // The fixture answers DE for private-range addresses the
        // real bundled database does not, and AQ for an address no
        // real allocation points at: only the override can answer.
        $this->assertSame( 'DE', Gr_Geoip::country( '10.0.0.5' ) );
        $this->assertSame( 'AQ', Gr_Geoip::country( '11.0.0.5' ) );

        $state = Gr_Geoip::state();
        $this->assertTrue( $state['available'] );
        $this->assertSame( 'override', $state['source'] );
        $this->assertSame( 3, $state['v4_ranges'] );

        // Boundary probes against the fixture: inside the tiny AQ
        // range, one past its end, and outside every record.
        $this->assertSame( 'AQ', Gr_Geoip::country( '11.0.0.5' ) );
        $this->assertSame( 'AQ', Gr_Geoip::country( '11.0.0.6' ) );
        $this->assertSame( '', Gr_Geoip::country( '11.0.0.7' ) );
        $this->assertSame( '', Gr_Geoip::country( '1.2.3.4' ) );
        $this->assertSame( '', Gr_Geoip::country( '12.0.0.1' ) );

        // The merged v6 record answers across the whole merged span,
        // including the seam between the two source ranges, and one
        // past its end reads ''.
        $this->assertSame( 'DE', Gr_Geoip::country( '2001:db8::1' ) );
        $this->assertSame( 'DE', Gr_Geoip::country( '2001:db8::fffe' ) );
        $this->assertSame( 'DE', Gr_Geoip::country( '2001:db8::ffff' ) );
        $this->assertSame( 'DE', Gr_Geoip::country( '2001:db8:1::' ) );
        $this->assertSame( '', Gr_Geoip::country( '2001:db8:1::1' ) );
        $this->assertSame( '', Gr_Geoip::country( '2001:db8:2::1' ) );

        // Non-addresses answer '' without touching any file.
        $this->assertSame( '', Gr_Geoip::country( 'not-an-address' ) );
        $this->assertSame( '', Gr_Geoip::country( '' ) );
    }

    public function testBundledRealDataAnswersKnownAddresses(): void {
        // No override in place: the shipped database must answer.
        $state = Gr_Geoip::state();
        $this->assertTrue( $state['available'] );
        $this->assertSame( 'bundled', $state['source'] );
        $this->assertMatchesRegularExpression( '/^\d{4}-\d{2}$/', $state['build_date'] );
        $this->assertGreaterThan( 100000, $state['v4_ranges'] );
        $this->assertGreaterThan( 100000, $state['v6_ranges'] );

        // Addresses whose countries come straight from the shipped
        // CSV lines (1.0.0.0/24 AU is the file's second line; the
        // reserved blocks read ZZ).
        $this->assertSame( 'AU', Gr_Geoip::country( '1.0.0.1' ) );
        $this->assertSame( 'US', Gr_Geoip::country( '8.8.8.8' ) );
        $this->assertSame( 'ZZ', Gr_Geoip::country( '192.0.2.1' ) );

        // Memoized lookups answer identically.
        $this->assertSame( 'AU', Gr_Geoip::country( '1.0.0.1' ) );
    }

    public function testFacadeMatchesTheEngine(): void {
        $this->assertSame( Gr_Geoip::country( '8.8.8.8' ), gr_geoip_country( '8.8.8.8' ) );
        $this->assertSame( '', gr_geoip_country( 'nope' ) );
    }

    public function testListenerCarriesCountryOnlyWithConsent(): void {
        global $wpdb;

        $_SERVER['REMOTE_ADDR'] = '1.0.0.1';
        $_SERVER['REQUEST_URI'] = '/plain-page/';
        $_GET                   = array();

        $build = function () {
            $settings = new Gr_Settings();

            return new Gr_Attribution_Listener(
                new Gr_Identity( $settings ),
                new Gr_Session_Repository(),
                new Gr_Touchpoint_Repository(),
                $settings
            );
        };

        // Without consent: the technical slide keeps default landing
        // attributes — no country code on the row.
        $build()->handle();
        $sql = implode( ' ', $wpdb->queries );
        $this->assertStringContainsString( 'INSERT INTO wp_gr_sessions', $sql );
        $this->assertStringNotContainsString( "'AU'", $sql );

        // With consent: the country joins the landing write.
        gr_stub_reset_options();
        $_SERVER['REMOTE_ADDR'] = '1.0.0.1';
        $_SERVER['REQUEST_URI'] = '/plain-page/';
        $_GET                   = array();
        $GLOBALS['gr_stub_consent']['marketing'] = true;

        $build()->handle();

        $sql = implode( ' ', $wpdb->queries );
        $this->assertStringContainsString( 'INSERT INTO wp_gr_sessions', $sql );
        $this->assertStringContainsString( "'AU'", $sql );

        unset( $_SERVER['REMOTE_ADDR'] );
    }

    public function testBundledFilesAndNoticeShipTogether(): void {
        $base = GR_PLUGIN_DIR . 'assets/data/';

        $this->assertFileExists( $base . Gr_Geoip_Packer::V4_FILE );
        $this->assertFileExists( $base . Gr_Geoip_Packer::V6_FILE );
        $meta = json_decode( (string) file_get_contents( $base . Gr_Geoip_Packer::META_FILE ), true );
        $this->assertIsArray( $meta );
        $this->assertSame( 'CC BY 4.0', $meta['license'] ?? '' );

        $notice = (string) file_get_contents( GR_PLUGIN_DIR . 'NOTICE' );
        $this->assertStringContainsString( 'DB-IP', $notice );
        $this->assertStringContainsString( 'CC BY 4.0', $notice );
    }

    public function testTheWholePathMakesNoOutboundCalls(): void {
        foreach ( array( GR_PLUGIN_DIR . 'includes/core/class-gr-geoip.php', GR_PLUGIN_DIR . 'includes/core/class-gr-geoip-packer.php' ) as $file ) {
            $source = (string) file_get_contents( $file );
            $this->assertStringNotContainsString( 'wp_remote_', $source, $file );
            $this->assertStringNotContainsString( 'curl_', $source, $file );
            $this->assertStringNotContainsString( 'fsockopen', $source, $file );
        }
    }
}
