<?php
/**
 * Touchpoints repository (docs/13 C7): insert shape with width clamps,
 * and the ordered windowed read.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Storage\Gr_Touchpoint_Repository;
use PHPUnit\Framework\TestCase;

final class TouchpointRepositoryTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    public function testRecordWritesTheFullColumnSetWithClamps(): void {
        global $wpdb;

        $repository = new Gr_Touchpoint_Repository();

        $id = $repository->record(
            str_repeat( 'a', 64 ) . 'excess',
            array(
                'channel'       => 'cpc',
                'utm_source'    => 'google',
                'utm_medium'    => 'cpc',
                'utm_campaign'  => str_repeat( 'c', 300 ),
                'utm_term'      => 'shoes',
                'utm_content'   => '',
                'click_id'      => 'eaia123',
                'referrer_host' => 'partner.example',
            ),
            'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
            'https://stub.example/landing/?utm_source=google'
        );

        self::assertSame( 1, $id );
        self::assertCount( 1, $wpdb->inserts );

        $write = $wpdb->inserts[0];
        self::assertSame( 'wp_gr_touchpoints', $write['table'] );
        self::assertSame( str_repeat( 'a', 64 ), $write['data']['visitor_id'] );
        self::assertSame( 'cpc', $write['data']['channel'] );
        self::assertSame( 'google', $write['data']['utm_source'] );
        self::assertSame( 191, strlen( $write['data']['utm_campaign'] ) );
        self::assertSame( 'eaia123', $write['data']['click_id'] );
        self::assertSame( 'partner.example', $write['data']['referrer_host'] );
        self::assertSame( '2026-09-10 00:00:00', $write['data']['created_at'] );
    }

    public function testRecordFillsDefaultsForMissingKeys(): void {
        global $wpdb;

        $repository = new Gr_Touchpoint_Repository();
        $repository->record( str_repeat( 'a', 64 ), array() );

        $write = $wpdb->inserts[0]['data'];
        self::assertSame( 'direct', $write['channel'] );
        self::assertSame( '', $write['utm_source'] );
        self::assertSame( '', $write['click_id'] );
        self::assertSame( '', $write['landing_url'] );
    }

    public function testSequenceReadIsWindowedOrderedAndBounded(): void {
        global $wpdb;
        $wpdb->results = array( array( 'id' => 1, 'channel' => 'cpc' ) );

        $repository = new Gr_Touchpoint_Repository();

        $rows = $repository->get_for_visitor( str_repeat( 'a', 64 ), 30 );

        self::assertSame( array( array( 'id' => 1, 'channel' => 'cpc' ) ), $rows );

        $sql = (string) end( $wpdb->queries );
        self::assertStringContainsString( 'FROM wp_gr_touchpoints', $sql );
        self::assertStringContainsString( 'visitor_id = ', $sql );
        self::assertStringContainsString( 'created_at >= ', $sql );
        self::assertStringContainsString( 'ORDER BY created_at ASC, id ASC', $sql );
        self::assertStringContainsString( 'LIMIT 500', $sql );
        self::assertStringContainsString( "'" . str_repeat( 'a', 64 ) . "'", $sql );
    }
}
