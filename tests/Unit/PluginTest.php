<?php
/**
 * Controller contracts: plugins_loaded wiring, queue boot through the
 * controller, the admin_init schema gate, translation loading at init,
 * and entry-file hook registration.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Core\Gr_Queue;
use GreenPNG\Storage\Gr_Schema;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    /**
     * @return array<int, string> Hook names registered so far.
     */
    private function registered_hooks(): array {
        $hooks = array();
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            $hooks[] = $registration['hook'];
        }

        return $hooks;
    }

    public function testControllerResolvesAndRunsIdempotently(): void {
        self::assertTrue( class_exists( Gr_Plugin::class, true ) );

        Gr_Plugin::run();
        Gr_Plugin::run();

        $hooks = $this->registered_hooks();
        self::assertContains( Gr_Queue::EVENT_HOOK, $hooks );
        self::assertContains( 'admin_init', $hooks );
        self::assertContains( 'init', $hooks );

        // The daily schedule heals once, not once per run() call.
        self::assertCount( 1, $GLOBALS['gr_stub_cron'] );
    }

    public function testSchemaGateMountsMaybeUpgradeOnAdminInit(): void {
        Gr_Plugin::run();

        $gate = null;
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            if ( 'admin_init' === $registration['hook'] ) {
                $gate = $registration['callback'];
            }
        }

        self::assertNotNull( $gate );
        self::assertSame( array( Gr_Schema::class, 'maybe_upgrade' ), $gate );
    }

    public function testEntryFileHooksControllerAtPluginsLoaded(): void {
        $source = (string) file_get_contents( GR_PLUGIN_DIR . 'greenpng.php' );
        $flat   = (string) preg_replace( '/\s+/', ' ', $source );

        self::assertStringContainsString(
            "add_action( 'plugins_loaded', array( GreenPNG\Core\Gr_Plugin::class, 'run' ), 10, 0 )",
            $flat
        );
    }
}
