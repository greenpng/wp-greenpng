<?php
/**
 * Owner-clicked datacenter-range refresh (ADR-0011 D2): queue
 * dispatch and dedupe, the three-source pull through the unified
 * client, the partial-refresh honesty (a failed source does not sink
 * the others, and the audit names who answered), and the
 * failure-keeps-old-data guarantees.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Dch_Packer;
use GreenPNG\Core\Gr_Dch_Refresh;
use GreenPNG\Core\Gr_Ip_Quality;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class DchRefreshTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        Gr_Ip_Quality::reset_for_tests();
    }

    protected function tearDown(): void {
        Gr_Ip_Quality::reset_for_tests();
        delete_transient( Gr_Dch_Refresh::PENDING );
        unset( $GLOBALS['gr_stub_uploads']['basedir'] );
        parent::tearDown();
    }

    /**
     * A fresh uploads parent plus override directory.
     *
     * @return array{parent: string, override: string}
     */
    private function uploads(): array {
        $parent   = sys_get_temp_dir() . '/gr-dch-refresh-' . uniqid();
        $override = $parent . '/' . Gr_Ip_Quality::UPLOAD_SUBDIR;
        mkdir( $override, 0777, true );
        $GLOBALS['gr_stub_uploads']['basedir'] = $parent;

        return array(
            'parent'   => $parent,
            'override' => $override,
        );
    }

    /**
     * Stubs the three official segment answers with valid payloads.
     *
     * @return void
     */
    private function stub_sources(): void {
        $GLOBALS['gr_stub_http'][ Gr_Dch_Refresh::URL_AWS ] = array(
            'response' => array( 'code' => 200 ),
            'body'    => (string) wp_json_encode(
                array(
                    'prefixes'       => array( array( 'ip_prefix' => '3.5.140.0/22' ) ),
                    'ipv6_prefixes'  => array( array( 'ipv6_prefix' => '2600:1f18::/32' ) ),
                )
            ),
        );
        $GLOBALS['gr_stub_http'][ Gr_Dch_Refresh::URL_GOOG ] = array(
            'response' => array( 'code' => 200 ),
            'body'    => (string) wp_json_encode(
                array( 'prefixes' => array( array( 'ipv4Prefix' => '8.8.4.0/24' ) ) )
            ),
        );
        $GLOBALS['gr_stub_http'][ Gr_Dch_Refresh::URL_AZURE_PAGE ] = array(
            'response' => array( 'code' => 200 ),
            'body'    => 'download <a href="https://download.microsoft.com/download/g/1/ServiceTags_Public_20260915.json">json</a>',
        );
        $GLOBALS['gr_stub_http']['https://download.microsoft.com/download/g/1/ServiceTags_Public_20260915.json'] = array(
            'response' => array( 'code' => 200 ),
            'body'    => (string) wp_json_encode(
                array(
                    'values' => array(
                        array( 'properties' => array( 'addressPrefixes' => array( '13.107.6.0/24' ) ) ),
                    ),
                )
            ),
        );
    }

    /**
     * The most recent audit row the stub store received, if any.
     *
     * @return array<string, mixed>
     */
    private function last_audit(): array {
        global $wpdb;
        $rows = $wpdb->inserts;

        return is_array( $rows ) && array() !== $rows ? (array) end( $rows ) : array();
    }

    public function testEnqueueIsGatedByThePendingFlag(): void {
        self::assertTrue( Gr_Dch_Refresh::enqueue() );
        self::assertCount( 1, $GLOBALS['gr_stub_cron'] );
        self::assertSame( Gr_Dch_Refresh::HOOK, $GLOBALS['gr_stub_cron'][0]['hook'] );

        // The pending flag is up and the second click is refused.
        self::assertFalse( Gr_Dch_Refresh::enqueue() );
        self::assertCount( 1, $GLOBALS['gr_stub_cron'] );

        delete_transient( Gr_Dch_Refresh::PENDING );
        self::assertTrue( Gr_Dch_Refresh::enqueue() );
    }

    public function testRunPacksAllAnsweringSourcesIntoTheOverride(): void {
        $dirs = $this->uploads();
        $this->stub_sources();

        Gr_Dch_Refresh::run();

        self::assertSame( 'override', Gr_Ip_Quality::source() );
        self::assertSame( 'hosting', Gr_Ip_Quality::category( '3.5.140.1' ) );
        self::assertSame( 'hosting', Gr_Ip_Quality::category( '8.8.4.1' ) );
        self::assertSame( 'hosting', Gr_Ip_Quality::category( '13.107.6.1' ) );
        self::assertSame( 'hosting', Gr_Ip_Quality::category( '2600:1f18::1' ) );
        self::assertSame( '', Gr_Ip_Quality::category( '9.9.9.1' ) );

        self::assertFileExists( $dirs['override'] . '/' . Gr_Dch_Packer::OUT_FILE );
        self::assertFalse( get_transient( Gr_Dch_Refresh::PENDING ) );

        $audit = $this->last_audit();
        self::assertSame( 'wp_gr_audit_logs', (string) ( $audit['table'] ?? '' ) );
        $data = (array) ( $audit['data'] ?? array() );
        self::assertSame( 'refresh', (string) ( $data['action'] ?? '' ) );
        self::assertSame( 'ip_quality_data', (string) ( $data['object_type'] ?? '' ) );
        $diff = (string) ( $data['diff_json'] ?? '' );
        self::assertStringContainsString( 'refreshed', $diff );
        self::assertStringContainsString( 'AWS, Google, Azure', $diff );
        self::assertStringNotContainsString( 'amazonaws.com', $diff );
    }

    public function testAFailedSourceDoesNotSinkTheOthersAndTheAuditNamesWhoAnswered(): void {
        $this->uploads();
        $this->stub_sources();
        $GLOBALS['gr_stub_http'][ Gr_Dch_Refresh::URL_AZURE_PAGE ] = new WP_Error( 'gr_stub_http_missing', 'no stub answer' );

        Gr_Dch_Refresh::run();

        self::assertSame( 'override', Gr_Ip_Quality::source() );
        self::assertSame( 'hosting', Gr_Ip_Quality::category( '8.8.4.1' ) );

        $data = (array) ( $this->last_audit()['data'] ?? array() );
        $diff = (string) ( $data['diff_json'] ?? '' );
        self::assertStringContainsString( 'refreshed', $diff );
        self::assertStringContainsString( 'AWS, Google', $diff );
        self::assertStringNotContainsString( 'Azure', $diff );
    }

    public function testWhenNoSourceAnswersTheOldDatasetSurvivesAndTheFailureIsAudited(): void {
        $paths  = $this->uploads();
        $parent = $paths['parent'];
        $this->stub_sources();

        // An older override that must survive a total failure.
        file_put_contents( $parent . '/old.txt', "9.9.9.0/24\n" );
        Gr_Dch_Packer::pack( $parent . '/old.txt', $paths['override'], '2026-08-01', 'old fixture' );
        unlink( $parent . '/old.txt' );
        Gr_Ip_Quality::reset_for_tests();
        self::assertSame( 'hosting', Gr_Ip_Quality::category( '9.9.9.1' ) );

        foreach ( array( Gr_Dch_Refresh::URL_AWS, Gr_Dch_Refresh::URL_GOOG, Gr_Dch_Refresh::URL_AZURE_PAGE ) as $url ) {
            $GLOBALS['gr_stub_http'][ $url ] = new WP_Error( 'gr_stub_http_missing', 'no stub answer' );
        }

        Gr_Dch_Refresh::run();

        self::assertSame( 'hosting', Gr_Ip_Quality::category( '9.9.9.1' ) );
        $data = (array) ( $this->last_audit()['data'] ?? array() );
        $diff = (string) ( $data['diff_json'] ?? '' );
        self::assertStringContainsString( 'failed', $diff );
        self::assertStringContainsString( 'no_source', $diff );
        self::assertFalse( get_transient( Gr_Dch_Refresh::PENDING ) );
    }

    public function testAnEmptyPackRefusesToPromote(): void {
        $this->uploads();
        $this->stub_sources();

        // Every source answers with JSON that carries no ranges.
        $GLOBALS['gr_stub_http'][ Gr_Dch_Refresh::URL_AWS ] = array(
            'response' => array( 'code' => 200 ),
            'body'    => '{"prefixes": []}',
        );
        $GLOBALS['gr_stub_http'][ Gr_Dch_Refresh::URL_GOOG ] = array(
            'response' => array( 'code' => 200 ),
            'body'    => '{"prefixes": []}',
        );
        $GLOBALS['gr_stub_http'][ Gr_Dch_Refresh::URL_AZURE_PAGE ] = new WP_Error( 'gr_stub_http_missing', 'no stub answer' );

        Gr_Dch_Refresh::run();

        // No packed override landed, so the bundled copy still rules.
        self::assertSame( 'bundled', Gr_Ip_Quality::source() );
        $data = (array) ( $this->last_audit()['data'] ?? array() );
        $diff = (string) ( $data['diff_json'] ?? '' );
        self::assertStringContainsString( 'empty_pack', $diff );
    }
}
