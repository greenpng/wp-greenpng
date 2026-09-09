<?php
/**
 * Main entry header contract (S1 regression; parses the file, never executes it).
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EntryHeaderTest extends TestCase {

    /** @var string */
    private $entry_source = '';

    protected function setUp(): void {
        parent::setUp();
        $this->entry_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/plugin/greenpng.php' );
        $this->assertNotSame( '', $this->entry_source );
    }

    public function testDeclaresVersionFloors(): void {
        self::assertMatchesRegularExpression( '/^\s*\*\s*Requires at least:\s*6\.0$/m', $this->entry_source );
        self::assertMatchesRegularExpression( '/^\s*\*\s*Requires PHP:\s*7\.4$/m', $this->entry_source );
    }

    public function testDeclaresGreenpngIdentity(): void {
        self::assertStringContainsString( 'Plugin Name:       GreenPNG', $this->entry_source );
        self::assertStringContainsString( 'Text Domain:       greenpng', $this->entry_source );
        self::assertStringContainsString( 'License:           GPLv2 or later', $this->entry_source );
    }

    public function testHeaderVersionMatchesConstant(): void {
        self::assertSame( 1, preg_match( '/^\s*\*\s*Version:\s*([^\s]+)/m', $this->entry_source, $header_match ) );
        self::assertSame( 1, preg_match( "/define\( 'GR_VERSION', '([^']+)' \)/", $this->entry_source, $constant_match ) );
        self::assertSame( $header_match[1], $constant_match[1] );
    }

    public function testHasAbspathGuard(): void {
        self::assertStringContainsString( "if ( ! defined( 'ABSPATH' ) ) {", $this->entry_source );
    }

    public function testDegradesToNoticeInsteadOfFatal(): void {
        self::assertStringContainsString( "'admin_notices'", $this->entry_source );
        self::assertStringContainsString( 'current_user_can', $this->entry_source );
        self::assertStringNotContainsString( 'wp_die', $this->entry_source );
    }
}
