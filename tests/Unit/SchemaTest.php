<?php
/**
 * Schema DDL invariants (S5): 15 objects in docs/05 §2 order, dbDelta
 * formatting rules, the three added indexes, and the version-option flow.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Storage\Gr_Schema;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SchemaTest extends TestCase {

    /**
     * Expected table names in docs/05 §2 order.
     *
     * @var array<int, string>
     */
    private const EXPECTED_TABLES = array(
        'gr_security_logs',
        'gr_access_rules',
        'gr_sessions',
        'gr_events',
        'gr_touchpoints',
        'gr_conversions',
        'gr_funnels',
        'gr_funnel_sessions',
        'gr_cart_abandonments',
        'gr_contacts',
        'gr_tags',
        'gr_contact_tags',
        'gr_audit_logs',
        'gr_dynamic_events',
        'gr_daily_stats',
    );

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    /**
     * @return array<int, string>
     */
    private function statements(): array {
        return Gr_Schema::tables( 'wp_test_', 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci' );
    }

    /**
     * @return array<string, string> DDL keyed by table name.
     */
    private function statements_by_table(): array {
        $by_table = array();
        foreach ( $this->statements() as $ddl ) {
            // No trailing $ anchor: the DDL is a multi-line string, so an
            // unanchored-end pattern would only match the final line.
            preg_match( '/^CREATE TABLE wp_test_(gr_[a-z_]+) \(/', $ddl, $match );
            $by_table[ $match[1] ] = $ddl;
        }
        return $by_table;
    }

    public function testDeclaresFifteenObjectsInDocumentedOrder(): void {
        self::assertCount( 15, $this->statements() );
        self::assertSame( self::EXPECTED_TABLES, array_keys( $this->statements_by_table() ) );
    }

    public function testPrimaryKeyUsesDoubleSpaceInsurance(): void {
        foreach ( $this->statements() as $ddl ) {
            self::assertStringContainsString( 'PRIMARY KEY  (id)', $ddl );
        }
    }

    public function testStringColumnsStayWithinIndexLimit(): void {
        $lengths = array();
        foreach ( $this->statements() as $ddl ) {
            preg_match_all( '/VARCHAR\((\d+)\)/', $ddl, $matches );
            foreach ( $matches[1] as $length ) {
                $lengths[] = (int) $length;
            }
        }

        // gr_contact_tags and gr_funnel_sessions legitimately carry no
        // VARCHAR column, so the guard is aggregate, not per-table.
        self::assertNotEmpty( $lengths );
        foreach ( $lengths as $length ) {
            self::assertLessThanOrEqual( 191, $length );
        }
    }

    public function testNoForeignKeysAnywhere(): void {
        foreach ( $this->statements() as $ddl ) {
            self::assertFalse( strpos( $ddl, 'FOREIGN KEY' ) );
        }
    }

    public function testEveryLineMatchesDbdeltaTemplate(): void {
        // The trailing ,? mirrors the DDL assembly: every line but the last
        // carries a comma because dbDelta runs the CREATE text verbatim.
        $line_pattern = '/^(PRIMARY KEY  \(id\)|UNIQUE KEY [a-z_]+ \([^)]*\)|KEY [a-z_]+ \([^)]*\)|[a-z_]+ (BIGINT|INT|TINYINT|CHAR|VARCHAR|VARBINARY|DATETIME|DATE|DECIMAL|TEXT|MEDIUMTEXT)[^;]*),?$/';

        foreach ( $this->statements() as $ddl ) {
            $lines = explode( "\n", $ddl );
            self::assertMatchesRegularExpression( '/^CREATE TABLE wp_test_gr_[a-z_]+ \($/', $lines[0] );
            self::assertSame( ') DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;', $lines[ count( $lines ) - 1 ] );

            $middle = array_slice( $lines, 1, count( $lines ) - 2 );
            self::assertNotSame( array(), $middle );
            $last_index = count( $middle ) - 1;
            foreach ( $middle as $index => $line ) {
                self::assertMatchesRegularExpression( $line_pattern, $line );
                if ( $last_index === $index ) {
                    self::assertStringEndsNotWith( ',', $line );
                } else {
                    self::assertStringEndsWith( ',', $line );
                }
            }
        }
    }

    public function testThreeAddedIndexesAreRegistered(): void {
        $by_table = $this->statements_by_table();

        self::assertStringContainsString( 'KEY last_active (last_active)', $by_table['gr_sessions'] );
        self::assertStringContainsString( 'KEY tag_contacts (tag_id, contact_id)', $by_table['gr_contact_tags'] );
        self::assertStringContainsString(
            'UNIQUE KEY stat_unique (stat_date, metric_type, metric_key)',
            $by_table['gr_daily_stats']
        );
    }

    public function testSpecifiedTablesMatchDocs05Baselines(): void {
        $by_table = $this->statements_by_table();

        // Spot anchors from the docs/05 §3.1–§3.3 baseline DDLs.
        self::assertStringContainsString( 'ip VARBINARY(16) NOT NULL', $by_table['gr_security_logs'] );
        self::assertStringContainsString( 'UNIQUE KEY fold_key (fold_key)', $by_table['gr_security_logs'] );
        self::assertStringContainsString( 'bot_score TINYINT(3) UNSIGNED NOT NULL DEFAULT 0', $by_table['gr_sessions'] );
        self::assertStringContainsString( 'model_weights TEXT NULL', $by_table['gr_conversions'] );
        self::assertStringContainsString( 'UNIQUE KEY source_unique (source_type, source_id)', $by_table['gr_conversions'] );
    }

    public function testTableNamesDeriveFromDdlInOrder(): void {
        $expected = array();
        foreach ( self::EXPECTED_TABLES as $table ) {
            $expected[] = 'wp_test_' . $table;
        }

        self::assertSame( $expected, Gr_Schema::table_names( 'wp_test_' ) );
    }

    public function testVersionConstants(): void {
        self::assertSame( 1, Gr_Schema::DB_VERSION );
        self::assertSame( 'gr_db_version', Gr_Schema::VERSION_OPTION );
    }

    public function testStoreVersionCreatesAutoloadNoAndUpdatesInPlace(): void {
        $store = new ReflectionMethod( Gr_Schema::class, 'store_version' );
        if ( PHP_VERSION_ID < 80100 ) {
            // No-op since 8.1 and deprecated as of 8.5; only 7.4 needs it.
            $store->setAccessible( true );
        }

        $store->invoke( null, 3 );
        self::assertSame( 3, get_option( 'gr_db_version' ) );
        self::assertSame( 'no', $GLOBALS['gr_stub_options']['autoload']['gr_db_version'] );

        $store->invoke( null, 4 );
        self::assertSame( 4, get_option( 'gr_db_version' ) );
        self::assertSame( 'no', $GLOBALS['gr_stub_options']['autoload']['gr_db_version'] );
    }
}
