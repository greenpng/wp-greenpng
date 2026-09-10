<?php
/**
 * Ecosystem detection (docs/03 §7): one cheap get_option() read of
 * active_plugins mapped against the bridge catalog, so status screens
 * and diagnostics can say which form/e-commerce bridges can bind
 * without loading any of their code.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin-to-bridge availability reporter.
 */
final class Gr_Ecosystem_Detector {

    /**
     * Known plugin files per bridge. Paths are the canonical entry
     * files each plugin ships; wpforms ships two editions.
     *
     * @var array<string, string[]>
     */
    private const FILES = array(
        'woocommerce' => array( 'woocommerce/woocommerce.php' ),
        'fluentform'  => array( 'fluentform/fluentform.php' ),
        'cf7'         => array( 'contact-form-7/wp-contact-form-7.php' ),
        'wpforms'     => array( 'wpforms/wpforms.php', 'wpforms-lite/wpforms.php' ),
    );

    /**
     * Detects which bridges the current site can activate.
     *
     * @return array<string, array<string, mixed>> bridge_id =>
     *        plugin (matched entry file), active (bool).
     */
    public static function detect(): array {
        $active = get_option( 'active_plugins', array() );
        if ( ! is_array( $active ) ) {
            $active = array();
        }

        $report = array();
        foreach ( self::FILES as $bridge => $files ) {
            $matched = '';
            foreach ( $files as $file ) {
                if ( in_array( $file, $active, true ) ) {
                    $matched = $file;
                    break;
                }
            }
            $report[ $bridge ] = array(
                'plugin' => $matched,
                'active' => ( '' !== $matched ),
            );
        }

        return $report;
    }
}
