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

// The entry file (greenpng.php) owns these in production; the suite
// boots classes directly, so the same constants get stand-in values
// here — the URL is synthetic and the version mirrors the entry file.
if ( ! defined( 'GR_VERSION' ) ) {
    define( 'GR_VERSION', '1.0.0' );
}
if ( ! defined( 'GR_PLUGIN_URL' ) ) {
    define( 'GR_PLUGIN_URL', 'https://stub.example/wp-content/plugins/greenpng/' );
}
if ( ! defined( 'GR_PLUGIN_FILE' ) ) {
    define( 'GR_PLUGIN_FILE', dirname( __DIR__ ) . '/plugin/greenpng.php' );
}

if ( ! defined( 'GR_META_API_VERSION' ) ) {
    define( 'GR_META_API_VERSION', 'v23.0' );
}

require_once __DIR__ . '/stubs/wp-functions.php';
require_once __DIR__ . '/stubs/wpdb-stub.php';
require_once __DIR__ . '/stubs/rest-stubs.php';
require_once __DIR__ . '/stubs/woo-stubs.php';
require_once __DIR__ . '/stubs/attribution-overrides.php';
require_once __DIR__ . '/stubs/dns-overrides.php';
require_once __DIR__ . '/stubs/wpcli-stub.php';
require_once __DIR__ . '/stubs/wp-list-table-stub.php';
gr_stub_reset_options();

require_once dirname( __DIR__ ) . '/plugin/includes/core/class-gr-autoloader.php';
spl_autoload_register( array( 'GreenPNG\Core\Gr_Autoloader', 'load' ) );

// The global facades are plain functions, so the autoloader cannot reach
// them; tests exercise them exactly like the entry file does.
require_once dirname( __DIR__ ) . '/plugin/includes/gr-functions.php';
