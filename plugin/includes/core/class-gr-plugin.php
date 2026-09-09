<?php
/**
 * Main controller: created once at plugins_loaded@10 with every service
 * wired explicitly — no DI container (docs/02 §2.1), so the composition
 * stays statically analyzable and testable. Instance services join the
 * constructor as their phases land; static facades are wired here.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Storage\Gr_Schema;

/**
 * Owns the plugin's hook registrations; contains no business logic.
 */
final class Gr_Plugin {

    /**
     * Entry point wired from the plugin file at plugins_loaded@10: by then
     * every plugin file has loaded, so service wiring sees the full
     * runtime, including any Action Scheduler the host provides.
     *
     * @return void
     */
    public static function run(): void {
        $plugin = new self();

        $plugin->register_hooks();
    }

    /**
     * Registers every plugin-level hook. The schema upgrade gate mounts on
     * admin_init so steady-state front-end requests do zero DDL
     * (docs/05 §4); translations load at init@10 for WP 6.5+ JIT
     * compliance (docs/04 §3.11.5).
     *
     * @return void
     */
    private function register_hooks(): void {
        Gr_Queue::boot();

        add_action( 'admin_init', array( Gr_Schema::class, 'maybe_upgrade' ) );
        add_action( 'init', array( $this, 'load_translations' ) );

        Gr_Cli::register();
    }

    /**
     * Loads the textdomain; a no-op until languages/*.mo exist.
     *
     * @return void
     */
    public function load_translations(): void {
        load_plugin_textdomain(
            'greenpng',
            false,
            dirname( plugin_basename( GR_PLUGIN_FILE ) ) . '/languages'
        );
    }
}
