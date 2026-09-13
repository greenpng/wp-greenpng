<?php
/**
 * Constants the plugin defines at runtime inside guarded blocks.
 *
 * For static analysis the guarded define() reads as maybe-defined, so
 * every later use reports "constant not found"; for the test runtime
 * the guards already prevent redefinition. This file gives the
 * analyzer the unconditional view.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

if ( ! defined( 'GR_PLUGIN_DIR' ) ) {
	define( 'GR_PLUGIN_DIR', __DIR__ . '/../../plugin/' );
}

if ( ! defined( 'GR_PLUGIN_URL' ) ) {
	define( 'GR_PLUGIN_URL', 'http://example.test/wp-content/plugins/greenpng/' );
}
