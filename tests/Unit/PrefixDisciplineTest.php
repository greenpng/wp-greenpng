<?php
/**
 * Prefix discipline (docs/04 §1): constants GR_*, global functions gr_*,
 * hooks we create gr_*, entry-file top-level variables $gr_*, classes
 * namespaced under GreenPNG\.
 *
 * Why this is a test and not the WPCS sniff: the WordPress
 * PrefixAllGlobals sniff hard-rejects prefixes shorter than an
 * unconfigurable class constant and drops them from its valid list, so the
 * sniff is excluded in phpcs.xml.dist and its job is done here instead,
 * where the documented short-prefix decision (docs/04 §1.1, uniqueness
 * verified against 226 plugins) is the baseline.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PrefixDisciplineTest extends TestCase {

    /**
     * Plugin PHP files under test.
     *
     * @var array<int, string>
     */
    private $php_files = [];

    protected function setUp(): void {
        parent::setUp();
        $this->php_files = self::collect_plugin_php_files();
        $this->assertNotEmpty( $this->php_files );
    }

    public function testConstantsUseGrPrefix(): void {
        foreach ( $this->php_files as $file ) {
            $source = (string) file_get_contents( $file );
            preg_match_all( "/define\(\s*'([^']+)'/", $source, $define_matches );
            preg_match_all( '/^\s*const\s+([A-Z_][A-Z0-9_]*)/m', $source, $const_matches );
            foreach ( array_merge( $define_matches[1], $const_matches[1] ) as $constant ) {
                self::assertStringStartsWith( 'GR_', $constant, "constant {$constant} in {$file}" );
            }
        }
    }

    public function testGlobalFunctionsUseGrPrefix(): void {
        foreach ( $this->php_files as $file ) {
            $source = (string) file_get_contents( $file );
            preg_match_all( '/^function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', $source, $matches );
            foreach ( $matches[1] as $function ) {
                self::assertStringStartsWith( 'gr_', $function, "global function {$function}() in {$file}" );
            }
        }
    }

    public function testCreatedHooksUseGrPrefix(): void {
        foreach ( $this->php_files as $file ) {
            $source = (string) file_get_contents( $file );
            $pattern = '/(?:do_action|apply_filters|do_action_ref_array|apply_filters_ref_array)\(\s*\'([^\']+)\'/';
            preg_match_all( $pattern, $source, $matches );
            foreach ( $matches[1] as $hook ) {
                self::assertStringStartsWith( 'gr_', $hook, "hook {$hook} created in {$file}" );
            }
        }
    }

    public function testEntryTopLevelVariablesUseGrPrefix(): void {
        $entry = dirname( __DIR__, 2 ) . '/plugin/greenpng.php';
        $source = (string) file_get_contents( $entry );
        preg_match_all( '/^\$([a-zA-Z_][a-zA-Z0-9_]*)\s*=/m', $source, $matches );
        $this->assertNotEmpty( $matches[1], 'entry file should have its top-level state variables' );
        foreach ( $matches[1] as $variable ) {
            self::assertStringStartsWith( 'gr_', $variable, "top-level variable \${$variable} in entry" );
        }
    }

    public function testClassesAreNamespacedUnderGreenpng(): void {
        foreach ( $this->php_files as $file ) {
            $source = (string) file_get_contents( $file );
            if ( preg_match( '/\b(?:class|interface|trait)\s+[A-Za-z_]/', $source ) === 1 ) {
                self::assertMatchesRegularExpression(
                    '/^namespace\s+GreenPNG\b/m',
                    $source,
                    "OO code in {$file} must live under the GreenPNG root namespace (docs/04 §1.2)"
                );
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private static function collect_plugin_php_files(): array {
        $plugin_dir = dirname( __DIR__, 2 ) . '/plugin';
        $files = array();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $plugin_dir, \FilesystemIterator::SKIP_DOTS )
        );
        foreach ( $iterator as $file_info ) {
            if ( $file_info->isFile() && $file_info->getExtension() === 'php' ) {
                $files[] = $file_info->getPathname();
            }
        }
        sort( $files );
        return $files;
    }
}
