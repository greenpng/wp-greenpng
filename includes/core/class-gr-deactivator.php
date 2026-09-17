<?php
/**
 * Deactivation routine: removes scheduled work but never data — deletion
 * is an uninstall-time, owner-opt-in action only (docs/05 §5).
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bound to register_deactivation_hook in the plugin entry file.
 */
final class Gr_Deactivator {

    /**
     * Clears the queue's schedules, mutex, and pending actions: a
     * deactivated plugin must leave no work behind, while its tables and
     * options survive for re-activation.
     *
     * @return void
     */
    public static function deactivate(): void {
        Gr_Queue::teardown();
    }
}
