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

use GreenPNG\Storage\Gr_Event_Repository;
use GreenPNG\Storage\Gr_Schema;

/**
 * Owns the plugin's hook registrations; contains no business logic. The
 * shared instance behind gr() (docs/02 §2.3) is created lazily so the
 * accessor is valid for any caller after the entry file has loaded, while
 * hook registration still happens exactly once per request at
 * plugins_loaded@10.
 */
final class Gr_Plugin {

    /**
     * Shared controller instance returned by gr().
     *
     * @var Gr_Plugin|null
     */
    private static ?Gr_Plugin $instance = null;

    /**
     * Event dispatch service, wired at construction.
     *
     * @var Gr_Event_Dispatcher
     */
    private Gr_Event_Dispatcher $events;

    /**
     * Settings service, wired at construction.
     *
     * @var Gr_Settings
     */
    private Gr_Settings $settings;

    /**
     * Entry point wired from the plugin file at plugins_loaded@10: by then
     * every plugin file has loaded, so service wiring sees the full
     * runtime, including any Action Scheduler the host provides.
     *
     * @return void
     */
    public static function run(): void {
        $plugin = self::instance();

        $plugin->register_hooks();
    }

    /**
     * The controller instance behind the gr() accessor. Lazily constructed:
     * building services has no side effects, and hooks register only from
     * run(), so an early caller receives services without double wiring.
     *
     * @return Gr_Plugin
     */
    public static function instance(): Gr_Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Test seam: drops the shared instance so the next instance() call
     * rebuilds every service against fresh stub state. Production code
     * never calls this — the container is request-scoped.
     *
     * @return void
     */
    public static function reset_instance(): void {
        self::$instance = null;
    }

    /**
     * Private on purpose: the only sanctioned construction path is
     * instance(), which keeps gr() a true container accessor. Services
     * join here as their phases land (docs/02 §2.1 explicit wiring).
     */
    private function __construct() {
        $this->events   = new Gr_Event_Dispatcher( new Gr_Event_Repository() );
        $this->settings = new Gr_Settings();
    }

    /**
     * Event dispatch service (docs/03 §2), exposed for the gr_dispatch_event
     * and gr_get_recent_events facades.
     *
     * @return Gr_Event_Dispatcher
     */
    public function events(): Gr_Event_Dispatcher {
        return $this->events;
    }

    /**
     * Settings service (docs/05 §6), exposed for gr() callers such as the
     * IP resolver.
     *
     * @return Gr_Settings
     */
    public function settings(): Gr_Settings {
        return $this->settings;
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
