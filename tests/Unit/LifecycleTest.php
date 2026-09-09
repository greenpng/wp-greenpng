<?php
/**
 * Lifecycle contracts: entry-file hook wiring, autoloader resolution of
 * the lifecycle classes, uninstall guard ordering, and the data-deletion
 * flag semantics.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Activator;
use GreenPNG\Core\Gr_Deactivator;
use GreenPNG\Core\Gr_Queue;
use GreenPNG\Storage\Gr_Uninstall;
use PHPUnit\Framework\TestCase;

final class LifecycleTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    public function testLifecycleClassesResolveThroughTheAutoloader(): void {
        self::assertTrue( class_exists( Gr_Activator::class, true ) );
        self::assertTrue( class_exists( Gr_Deactivator::class, true ) );
        self::assertTrue( class_exists( Gr_Uninstall::class, true ) );

        self::assertTrue( method_exists( Gr_Activator::class, 'activate' ) );
        self::assertTrue( method_exists( Gr_Deactivator::class, 'deactivate' ) );
        self::assertTrue( method_exists( Gr_Uninstall::class, 'run' ) );
    }

    public function testDailyMaintenanceHookNameMatchesDocs05(): void {
        self::assertSame( 'gr_cron_daily_maintenance', Gr_Queue::DAILY_HOOK );
        // The internal trigger hook must stay distinct from the public one
        // so firing the public hook from inside the runner can never recurse.
        self::assertNotSame( Gr_Queue::DAILY_HOOK, Gr_Queue::EVENT_HOOK );
    }

    public function testEntryFileRegistersLifecycleHooks(): void {
        $source = (string) file_get_contents( GR_PLUGIN_DIR . 'greenpng.php' );
        $flat   = (string) preg_replace( '/\s+/', ' ', $source );

        self::assertStringContainsString(
            'register_activation_hook( GR_PLUGIN_FILE, array( GreenPNG\Core\Gr_Activator::class, \'activate\' ) )',
            $flat
        );
        self::assertStringContainsString(
            'register_deactivation_hook( GR_PLUGIN_FILE, array( GreenPNG\Core\Gr_Deactivator::class, \'deactivate\' ) )',
            $flat
        );
    }

    public function testUninstallEntryGuardsBeforeLoadingAnything(): void {
        $path = GR_PLUGIN_DIR . 'uninstall.php';
        self::assertFileExists( $path );

        $source = (string) file_get_contents( $path );
        $flat   = (string) preg_replace( '/\s+/', ' ', $source );

        $guard  = strpos( $flat, "if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {" );
        $schema = strpos( $flat, "require_once __DIR__ . '/includes/storage/class-gr-schema.php';" );
        $runner = strpos( $flat, 'GreenPNG\Storage\Gr_Uninstall::run();' );

        self::assertNotFalse( $guard );
        self::assertNotFalse( $schema );
        self::assertNotFalse( $runner );
        self::assertLessThan( $schema, $guard );
        self::assertLessThan( $runner, $schema );
    }

    public function testDeletionFlagDefaultsToKeepAndOptsInExplicitly(): void {
        self::assertSame( 'gr_delete_data_on_uninstall', Gr_Uninstall::DELETE_FLAG_OPTION );

        self::assertFalse( Gr_Uninstall::should_delete_data() );

        update_option( Gr_Uninstall::DELETE_FLAG_OPTION, '1' );
        self::assertTrue( Gr_Uninstall::should_delete_data() );
    }
}
