<?php
/**
 * Plugin Name:       GreenPNG
 * Description:       Traffic security and anti-bot signals, marketing attribution, conversion funnels, A/B testing, CRM scoring, and local analytics. All local, no cloud, no telemetry.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            GreenPNG
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       greenpng
 * Domain Path:       /languages
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'GR_VERSION' ) ) {
    define( 'GR_VERSION', '1.0.0' );
    define( 'GR_PLUGIN_FILE', __FILE__ );
    define( 'GR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
    define( 'GR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

/*
 * The "Requires PHP / Requires at least" header only gates activation through
 * the admin UI. CLI and programmatic activation bypass it, so the floors are
 * re-checked at runtime and degrade to an admin notice instead of a fatal;
 * everything the plugin would otherwise register is skipped after the return.
 */
$gr_php_version_ok = version_compare( PHP_VERSION, '7.4', '>=' );
$gr_wp_version_ok   = isset( $GLOBALS['wp_version'] ) && version_compare( (string) $GLOBALS['wp_version'], '6.0', '>=' );

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
