<?php
/**
 * Owner-clicked DB-IP refresh job (docs/13 U16, docs/07 §5.7): the
 * queue dispatch and dedupe, the download-to-override chain through
 * the unified client, the failure keeps-old-data guarantees, and the
 * audit vocabulary of every finished attempt.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Geoip;
use GreenPNG\Core\Gr_Geoip_Packer;
use GreenPNG\Core\Gr_Geoip_Refresh;
use GreenPNG\Core\Gr_Http_Client;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class GeoipRefreshTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    protected function tearDown(): void {
        Gr_Geoip::reset_for_tests();
        parent::tearDown();
    }

    /**
     * Builds a fresh uploads parent plus override directory and points
     * the uploads knob at the parent.
     *
     * @return array{parent: string, override: string}
     */
    private function uploads(): array {
        $parent   = sys_get_temp_dir() . '/gr-geoip-refresh-' . uniqid();
        $override = $parent . '/' . Gr_Geoip::UPLOAD_SUBDIR;
        mkdir( $override, 0777, true );
        $GLOBALS['gr_stub_uploads']['basedir'] = $parent;

        return array(
            'parent'   => $parent,
            'override' => $override,
        );
    }

    /**
     * Stubs the DB-IP download with the given wire answer.
     *
     * @param array<string, mixed>|WP_Error $answer Wire answer.
     * @return void
     */
    private function download( $answer ): void {
        $GLOBALS['gr_stub_http'][ Gr_Geoip_Refresh::url() ] = $answer;
    }

    /**
     * A tiny valid DB-IP CSV, gzipped like the real wire payload.
     *
     * @return string
     */
    private function gz_csv(): string {
        return (string) gzencode(
            "9.9.9.0,9.9.9.255,DE\n10.0.0.0,10.0.0.255,DE\n11.0.0.5,11.0.0.6,AQ\n"
        );
    }

    /**
     * Seeds one older override data set (build label 2026-08) that the
     * failure arms must prove survives a bad refresh.
     *
     * @param string $parent Uploads parent directory.
     * @return void
     */
    private function seed_old_override( string $parent ): void {
        file_put_contents(
            $parent . '/seed.csv',
            "9.9.9.0,9.9.9.255,DE\n10.0.0.0,10.0.0.255,DE\n"
        );
        Gr_Geoip_Packer::pack( $parent . '/seed.csv', $parent . '/' . Gr_Geoip::UPLOAD_SUBDIR, '2026-08' );
        Gr_Geoip::reset_for_tests();
    }

    public function testEnqueueIsGatedByThePendingFlag(): void {
        $this->assertTrue( Gr_Geoip_Refresh::enqueue() );
        $this->assertCount( 1, $GLOBALS['gr_stub_cron'] );
        $this->assertSame( Gr_Geoip_Refresh::HOOK, $GLOBALS['gr_stub_cron'][0]['hook'] );

        // The pending flag is up and the second click is refused.
        $this->assertFalse( Gr_Geoip_Refresh::enqueue() );
        $this->assertCount( 1, $GLOBALS['gr_stub_cron'] );

        // A finished job lowers the flag, and the button works again.
        delete_transient( Gr_Geoip_Refresh::PENDING );
        $this->assertTrue( Gr_Geoip_Refresh::enqueue() );
        $this->assertCount( 2, $GLOBALS['gr_stub_cron'] );
    }

    public function testSuccessfulRunPacksTheOverrideAndReports(): void {
        global $wpdb;

        $paths    = $this->uploads();
        $override = $paths['override'];
        $this->download( array(
            'response' => array( 'code' => 200 ),
            'body'     => $this->gz_csv(),
        ) );

        Gr_Geoip_Refresh::run();

        // The override holds the downloaded data set.
        $this->assertFileExists( $override . '/' . Gr_Geoip_Packer::V4_FILE );
        $this->assertFileDoesNotExist( $override . '/download.csv.tmp' );
        $state = Gr_Geoip::state();
        $this->assertSame( 'override', $state['source'] );
        $this->assertSame( gmdate( 'Y-m' ), $state['build_date'] );
        $this->assertSame( 3, $state['v4_ranges'] );

        // The engine now answers from the refreshed copy.
        $this->assertSame( 'DE', Gr_Geoip::country( '10.0.0.5' ) );

        // The pending flag dropped with the job's completion.
        $this->assertFalse( get_transient( Gr_Geoip_Refresh::PENDING ) );

        // One audit row: after is the outcome vocabulary, and no
        // endpoint appears anywhere in it.
        $this->assertCount( 1, $wpdb->inserts );
        $write = $wpdb->inserts[0];
        $this->assertSame( 'wp_gr_audit_logs', $write['table'] );
        $this->assertSame( 'refresh', $write['data']['action'] );
        $this->assertSame( 'geoip_data', $write['data']['object_type'] );
        $diff = (string) $write['data']['diff_json'];
        $this->assertStringContainsString( 'refreshed', $diff );
        $this->assertStringNotContainsString( 'db-ip.com', $diff );
    }

    public function testWireFailureReschedulesByTheClientLadder(): void {
        // The flag the original click raised; a failing job keeps it
        // up so the button cannot double-dispatch mid-sequence.
        set_transient( Gr_Geoip_Refresh::PENDING, time(), 3600 );

        $this->download( new WP_Error( 'http_request_failed', 'Connection timed out.' ) );

        Gr_Geoip_Refresh::run();

        // The client wrapped the wire error as a first ladder step,
        // and the job re-queued itself with that delay.
        $this->assertCount( 1, $GLOBALS['gr_stub_cron'] );
        $this->assertSame( Gr_Geoip_Refresh::HOOK, $GLOBALS['gr_stub_cron'][0]['hook'] );
        $this->assertGreaterThanOrEqual( time() + 29, $GLOBALS['gr_stub_cron'][0]['timestamp'] );
        $this->assertNotFalse( get_transient( Gr_Geoip_Refresh::PENDING ) );
    }

    public function testGiveUpStopsTheJobAndLowersTheFlag(): void {
        global $wpdb;

        // Three failures already spent the client's ladder, so this
        // wire error is the give-up: no retry hint, one audit row
        // from the client itself, and the job stops rescheduling.
        set_transient( Gr_Geoip_Refresh::PENDING, time(), 3600 );
        set_transient( 'gr_http_' . Gr_Http_Client::SERVICE_DBIP, array(
            'fails'      => 3,
            'open_until' => 0,
            'next_at'    => 0,
        ) );
        $this->download( array( 'response' => array( 'code' => 500 ), 'body' => '' ) );

        Gr_Geoip_Refresh::run();

        $this->assertSame( array(), $GLOBALS['gr_stub_cron'] );
        $this->assertFalse( get_transient( Gr_Geoip_Refresh::PENDING ) );

        // The client's give-up row is the only trace, and its diff
        // carries counts, not endpoints.
        $this->assertCount( 1, $wpdb->inserts );
        $this->assertStringContainsString( 'http_give_up', (string) $wpdb->inserts[0]['data']['action'] );
        $this->assertStringNotContainsString( 'db-ip.com', (string) $wpdb->inserts[0]['data']['diff_json'] );
    }

    public function testUnpackFailureKeepsTheOldDataAndAuditsTheReason(): void {
        global $wpdb;

        $paths = $this->uploads();
        $this->seed_old_override( $paths['parent'] );

        $this->download( array( 'response' => array( 'code' => 200 ), 'body' => 'this is not gzip' ) );

        Gr_Geoip_Refresh::run();

        // The old override still answers; nothing new was promoted.
        $state = Gr_Geoip::state();
        $this->assertSame( 'override', $state['source'] );
        $this->assertSame( '2026-08', $state['build_date'] );

        $this->assertCount( 1, $wpdb->inserts );
        $diff = (string) $wpdb->inserts[0]['data']['diff_json'];
        $this->assertStringContainsString( 'unpack', $diff );
        $this->assertFalse( get_transient( Gr_Geoip_Refresh::PENDING ) );
    }

    public function testEmptyPackKeepsTheOldDataAndAuditsTheReason(): void {
        global $wpdb;

        $paths = $this->uploads();
        $this->seed_old_override( $paths['parent'] );

        // A valid gzip of a useless payload: the packer refuses to
        // promote anything, and the previous data keeps answering.
        $this->download( array( 'response' => array( 'code' => 200 ), 'body' => (string) gzencode( "garbage,line,three\n" ) ) );

        Gr_Geoip_Refresh::run();

        $state = Gr_Geoip::state();
        $this->assertSame( 'override', $state['source'] );
        $this->assertSame( '2026-08', $state['build_date'] );

        $this->assertCount( 1, $wpdb->inserts );
        $diff = (string) $wpdb->inserts[0]['data']['diff_json'];
        $this->assertStringContainsString( 'empty_pack', $diff );
        $this->assertFalse( get_transient( Gr_Geoip_Refresh::PENDING ) );
    }
}
