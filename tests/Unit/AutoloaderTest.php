<?php
/**
 * Autoloader mapping and loading (S2 acceptance). The fixture tree mirrors
 * the real layout, so these tests exercise real file resolution and
 * require_once behavior, not just string mapping.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Autoloader;
use GreenPNG\Core\Gr_Probe;
use GreenPNG\Core\Gr_Probe_Two;
use GreenPNG\Integrations\Ecosystem\Gr_Demo_Adapter;
use PHPUnit\Framework\TestCase;

final class AutoloaderTest extends TestCase {

    /**
     * Fixture tree root that mirrors the plugin layout.
     *
     * @var string
     */
    private $fixture_root = '';

    protected function setUp(): void {
        parent::setUp();
        $this->fixture_root = dirname( __DIR__, 2 ) . '/tests/fixtures/autoload-tree';
        require_once dirname( __DIR__, 2 ) . '/plugin/includes/core/class-gr-autoloader.php';
    }

    public function testLoadsCoreClassFromMappedFile(): void {
        Gr_Autoloader::load( 'GreenPNG\Core\Gr_Probe', $this->fixture_root );
        self::assertTrue( class_exists( 'GreenPNG\Core\Gr_Probe', false ) );
        self::assertSame( 'core-probe', Gr_Probe::id() );
    }

    public function testMapsUnderscoresToHyphensInFileSlug(): void {
        Gr_Autoloader::load( 'GreenPNG\Core\Gr_Probe_Two', $this->fixture_root );
        self::assertSame( 'core-probe-two', Gr_Probe_Two::id() );
    }

    public function testLoadsDeepModulePath(): void {
        Gr_Autoloader::load( 'GreenPNG\Integrations\Ecosystem\Gr_Demo_Adapter', $this->fixture_root );
        self::assertSame( 'ecosystem-demo-adapter', Gr_Demo_Adapter::id() );
    }

    public function testDefaultBaseDirFallsBackToGrPluginDir(): void {
        Gr_Autoloader::load( 'GreenPNG\Core\Gr_Autoloader' );
        self::assertTrue( class_exists( 'GreenPNG\Core\Gr_Autoloader', false ) );
    }

    public function testIgnoresForeignNamespacesSilently(): void {
        Gr_Autoloader::load( 'Some\Other\Package\Thing', $this->fixture_root );
        self::assertFalse( class_exists( 'Some\Other\Package\Thing', false ) );
    }

    public function testIgnoresRootLevelClassWithoutModule(): void {
        Gr_Autoloader::load( 'GreenPNG\Lonely', $this->fixture_root );
        self::assertFalse( class_exists( 'GreenPNG\Lonely', false ) );
    }

    public function testMissingFileIsSilentNoop(): void {
        Gr_Autoloader::load( 'GreenPNG\Core\Gr_Nope', $this->fixture_root );
        self::assertFalse( class_exists( 'GreenPNG\Core\Gr_Nope', false ) );
    }
}
