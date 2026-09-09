<?php
/**
 * Table-name resolution and the container accessor (docs/13 C2): names come
 * from the DDL-derived list, unknown keys throw, and gr() returns the
 * shared controller instance.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Database;
use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Storage\Gr_Schema;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    public function testResolvesShortKeysToPrefixedNames(): void {
        self::assertSame( 'wp_gr_events', Gr_Database::table( 'events' ) );
        self::assertSame( 'wp_gr_security_logs', Gr_Database::table( 'security_logs' ) );
        self::assertSame( 'wp_gr_daily_stats', Gr_Database::table( 'daily_stats' ) );
    }

    public function testToleratesALeadingGrPrefixOnTheKey(): void {
        self::assertSame(
            Gr_Database::table( 'events' ),
            Gr_Database::table( 'gr_events' )
        );
    }

    public function testResolvedNamesAlwaysComeFromTheSchemaDdl(): void {
        global $wpdb;

        foreach ( array( 'events', 'sessions', 'conversions', 'audit_logs' ) as $key ) {
            self::assertContains(
                Gr_Database::table( $key ),
                Gr_Schema::table_names( $wpdb->prefix )
            );
        }
    }

    public function testUnknownKeyThrowsInsteadOfBuildingAWrongName(): void {
        $this->expectException( \InvalidArgumentException::class );

        Gr_Database::table( 'evnts' );
    }

    public function testSchemaResolveAcceptsAnInjectablePrefix(): void {
        self::assertSame(
            'custom_gr_events',
            Gr_Schema::resolve_table( 'events', 'custom_' )
        );
    }

    public function testSchemaResolveRejectsUnknownKeysToo(): void {
        $this->expectException( \InvalidArgumentException::class );

        Gr_Schema::resolve_table( 'nope', 'custom_' );
    }

    public function testGrAccessorReturnsTheSharedController(): void {
        Gr_Plugin::run();

        self::assertInstanceOf( Gr_Plugin::class, gr() );
        self::assertSame( gr(), gr() );
        self::assertSame( Gr_Plugin::instance(), gr() );
    }
}
