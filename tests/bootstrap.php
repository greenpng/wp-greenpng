<?php
/**
 * PHPUnit bootstrap.
 *
 * Unit tests must not need WordPress: the plugin files guard on ABSPATH, so
 * a stand-in is defined here together with the plugin-dir constant the
 * autoloader falls back to. Integration tests boot the main verification
 * site separately (docs/11 §3).
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__ ) . '/plugin/' );
}

if ( ! defined( 'GR_PLUGIN_DIR' ) ) {
    define( 'GR_PLUGIN_DIR', dirname( __DIR__ ) . '/plugin/' );
}

require_once __DIR__ . '/stubs/wp-functions.php';
require_once __DIR__ . '/stubs/wpdb-stub.php';
require_once __DIR__ . '/stubs/rest-stubs.php';
require_once __DIR__ . '/stubs/attribution-overrides.php';
gr_stub_reset_options();

require_once dirname( __DIR__ ) . '/plugin/includes/core/class-gr-autoloader.php';
spl_autoload_register( array( 'GreenPNG\Core\Gr_Autoloader', 'load' ) );

// The global facades are plain functions, so the autoloader cannot reach
// them; tests exercise them exactly like the entry file does.
require_once dirname( __DIR__ ) . '/plugin/includes/gr-functions.php';
