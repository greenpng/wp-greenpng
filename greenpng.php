<?php
/**
 * Plugin Name:       GreenPNG
 * Description:       Traffic security and anti-bot signals, marketing attribution, conversion funnels, A/B testing, CRM scoring, and local analytics. All local, no cloud, no telemetry.
 * Version:           1.2.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            GreenPNG
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       greenpng
 * Domain Path:       /languages
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'GR_VERSION' ) ) {
    define( 'GR_VERSION', '1.2.2' );
    define( 'GR_PLUGIN_FILE', __FILE__ );
    define( 'GR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
    define( 'GR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'GR_META_API_VERSION' ) ) {
    // The reference archive hardcoded v19.0 and the version retired
    // under it; a Graph version is a moving target, so the default
    // ships with the plugin and the gr_meta_api_version filter is the
    // owner's bump path that needs no plugin update.
    define( 'GR_META_API_VERSION', 'v23.0' );
}

if ( ! defined( 'GR_TIKTOK_API_VERSION' ) ) {
    // Same discipline as the Graph version: the TikTok open API
    // version is a path segment, a moving target the default ships
    // with and the gr_tiktok_api_version filter bumps without a
    // plugin update.
    define( 'GR_TIKTOK_API_VERSION', 'v1.3' );
}

/*
 * The "Requires PHP / Requires at least" header only gates activation through
 * the admin UI. CLI and programmatic activation bypass it, so the floors are
 * re-checked at runtime and degrade to an admin notice instead of a fatal;
 * everything the plugin would otherwise register is skipped after the return.
 */
$gr_php_version_ok = version_compare( PHP_VERSION, '7.4', '>=' );
$gr_wp_version_ok  = isset( $GLOBALS['wp_version'] ) && version_compare( (string) $GLOBALS['wp_version'], '6.0', '>=' );

if ( ! $gr_php_version_ok || ! $gr_wp_version_ok ) {
    add_action(
        'admin_notices',
        static function (): void {
            if ( ! current_user_can( 'activate_plugins' ) ) {
                return;
            }

            $gr_wp_version = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '';

            echo '<div class="notice notice-error"><p>';
            printf(
                /* translators: 1: current PHP version, 2: current WordPress version */
                esc_html__( 'GreenPNG did not load because it requires PHP 7.4 or newer and WordPress 6.0 or newer. This site runs PHP %1$s and WordPress %2$s, so no GreenPNG features are active and no data is collected.', 'greenpng' ),
                esc_html( PHP_VERSION ),
                esc_html( $gr_wp_version )
            );
            echo '</p></div>';
        }
    );

    return;
}

/*
 * Zero Composer at runtime (ADR-0003): the hand-written autoloader maps
 * GreenPNG\<Module>\<Class> to includes/<module>/class-gr-<slug>.php. This
 * one file is required directly because nothing exists yet that could
 * autoload it.
 */
require_once GR_PLUGIN_DIR . 'includes/core/class-gr-autoloader.php';

spl_autoload_register( array( GreenPNG\Core\Gr_Autoloader::class, 'load' ) );

/*
 * Global gr_* facades are plain functions, so they cannot be autoloaded;
 * the one facade file loads right after the autoloader and contains
 * nothing but thin forwards (docs/03 layering discipline).
 */
require_once GR_PLUGIN_DIR . 'includes/gr-functions.php';

/*
 * Lifecycle: activation is the only sanctioned moment for first-install
 * options and DDL (iron rule 3); deactivation clears scheduled work but
 * never data. Array callbacks keep both hooks removable by other code.
 */
register_activation_hook( GR_PLUGIN_FILE, array( GreenPNG\Core\Gr_Activator::class, 'activate' ) );
register_deactivation_hook( GR_PLUGIN_FILE, array( GreenPNG\Core\Gr_Deactivator::class, 'deactivate' ) );

/*
 * The controller is constructed at plugins_loaded@10 (docs/02 §2.1): by
 * then every plugin file has loaded, so the service wiring sees the full
 * runtime, including any Action Scheduler the host provides.
 */
add_action( 'plugins_loaded', array( GreenPNG\Core\Gr_Plugin::class, 'run' ), 10, 0 );
