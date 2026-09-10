<?php
/**
 * Conversion binding (docs/13 C9): the composed bind against the
 * repositories, the model split riding along, and the idempotent
 * replay contract.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Attribution\Gr_Attribution_Service;
use GreenPNG\Storage\Gr_Conversion_Repository;
use GreenPNG\Storage\Gr_Touchpoint_Repository;
use PHPUnit\Framework\TestCase;

final class ConversionBindingTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();

        $_SERVER['REMOTE_ADDR']      = '10.0.0.9';
        $_SERVER['HTTP_USER_AGENT'] = 'UnitTestAgent/1.0';
    }

    protected function tearDown(): void {
        unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] );
        parent::tearDown();
    }

    /**
     * Service over the stub stores.
     *
     * @return Gr_Attribution_Service
     */
    private function service(): Gr_Attribution_Service {
        return new Gr_Attribution_Service( new Gr_Touchpoint_Repository(), new Gr_Conversion_Repository() );
    }

    public function testFreshBindingInsertsAndReturnsTheRowId(): void {
        global $wpdb;

        // The touchpoint chain the binding composes over.
        $wpdb->results = array(
            array( 'id' => 11, 'created_at' => '2026-09-01 12:00:00', 'channel' => 'email' ),
            array( 'id' => 12, 'created_at' => '2026-09-08 12:00:00', 'channel' => 'cpc' ),
            array( 'id' => 13, 'created_at' => '2026-09-15 12:00:00', 'channel' => 'referral' ),
        );

        // INSERT IGNORE via query(): the stub leaves insert_id alone, so
        // a fresh binding is proven by setting it like the real wpdb
        // would after a successful insert.
        $wpdb->insert_id = 42;

        $id = $this->service()->bind( 901, str_repeat( 'a', 32 ), 100.0, 'usd' );

        self::assertSame( 42, $id );

        $sql = '';
        foreach ( $wpdb->queries as $query ) {
            if ( false !== strpos( (string) $query, 'INSERT IGNORE INTO wp_gr_conversions' ) ) {
                $sql = (string) $query;
            }
        }
        self::assertNotSame( '', $sql );
        self::assertStringContainsString( "'woocommerce'", $sql );
        self::assertStringContainsString( '901', $sql );
        self::assertStringContainsString( "'100.00'", $sql );
        self::assertStringContainsString( "'USD'", $sql );
        self::assertStringContainsString( '11, 13', $sql );

        // The five-model split rides along, first/last touch ids bound.
        // (The stub's prepare() addslashes-escapes the JSON quotes, so
        // the literal carries the backslashes.)
        self::assertStringContainsString( '\"first\"', $sql );
        self::assertStringContainsString( '\"time_decay\"', $sql );

        // One touchpoint read, one write, and no existence lookup: the
        // insert id came back directly. (Each statement is recorded
        // twice by the stub: prepare() plus the executing call.)
        self::assertCount( 4, $wpdb->queries );
        foreach ( $wpdb->queries as $query ) {
            self::assertStringNotContainsString( 'SELECT id FROM wp_gr_conversions', (string) $query );
        }
    }

    public function testReplayedCallbackReturnsTheExistingRowId(): void {
        global $wpdb;
        $wpdb->results    = array();
        $wpdb->var_result = '7';

        // insert_id stays 0: the UNIQUE key swallowed the insert.
        $first  = $this->service()->bind( 902, str_repeat( 'b', 32 ), 50.0, 'eur' );
        $second = $this->service()->bind( 902, str_repeat( 'b', 32 ), 50.0, 'eur' );

        self::assertSame( 7, $first );
        self::assertSame( 7, $second );

        // Both calls went through the INSERT IGNORE; both fell back to
        // the source lookup — and only one row can sit behind id 7.
        // (Counts are doubled: the stub records each statement once from
        // prepare() and once from the executing call.)
        $inserts = 0;
        $lookups = 0;
        foreach ( $wpdb->queries as $query ) {
            if ( false !== strpos( (string) $query, 'INSERT IGNORE INTO wp_gr_conversions' ) ) {
                $inserts++;
            }
            if ( false !== strpos( (string) $query, 'SELECT id FROM wp_gr_conversions' ) ) {
                $lookups++;
            }
        }
        self::assertSame( 4, $inserts );
        self::assertSame( 4, $lookups );
    }

    public function testDirectVisitorBindsWithZeroTouchIdsAndEmptyModels(): void {
        global $wpdb;
        $wpdb->results   = array();
        $wpdb->insert_id = 9;

        $id = $this->service()->bind( 903, str_repeat( 'c', 32 ), 10.0, 'jpy', 'fluentform' );

        self::assertSame( 9, $id );

        $sql = '';
        foreach ( $wpdb->queries as $query ) {
            if ( false !== strpos( (string) $query, 'INSERT IGNORE INTO wp_gr_conversions' ) ) {
                $sql = (string) $query;
            }
        }
        self::assertNotSame( '', $sql );
        self::assertStringContainsString( "'fluentform'", $sql );
        self::assertStringContainsString( '0, 0', $sql );
        self::assertStringContainsString( '\"first\":[]', $sql );
    }

    public function testFacadeForwardsWithTheDocumentedSignature(): void {
        global $wpdb;
        $wpdb->results   = array();
        $wpdb->insert_id = 5;

        $id = gr_bind_conversion( 904, str_repeat( 'd', 32 ), 75.5, 'USD' );

        self::assertSame( 5, $id );
        $sql = '';
        foreach ( $wpdb->queries as $query ) {
            if ( false !== strpos( (string) $query, 'INSERT IGNORE INTO wp_gr_conversions' ) ) {
                $sql = (string) $query;
            }
        }
        self::assertStringContainsString( 'INSERT IGNORE INTO wp_gr_conversions', $sql );
        self::assertStringContainsString( "'75.50'", $sql );
    }
}
