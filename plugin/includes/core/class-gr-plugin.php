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

use GreenPNG\Attribution\Gr_Attribution_Service;
use GreenPNG\Attribution\Gr_Attribution_Listener;
use GreenPNG\Attribution\Gr_Identity;
use GreenPNG\Integrations\Ecosystem\Gr_Cf7_Adapter;
use GreenPNG\Integrations\Ecosystem\Gr_Fluentforms_Adapter;
use GreenPNG\Integrations\Ecosystem\Gr_Wpforms_Adapter;
use GreenPNG\Integrations\Ecosystem\Gr_Woocommerce_Adapter;
use GreenPNG\Rest\Gr_Collect_Controller;
use GreenPNG\Storage\Gr_Conversion_Repository;
use GreenPNG\Storage\Gr_Event_Repository;
use GreenPNG\Storage\Gr_Schema;
use GreenPNG\Storage\Gr_Session_Repository;
use GreenPNG\Storage\Gr_Touchpoint_Repository;

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
     * Visitor/session identity service, wired at construction.
     *
     * @var Gr_Identity
     */
    private Gr_Identity $identity;

    /**
     * Sessions repository, wired at construction.
     *
     * @var Gr_Session_Repository
     */
    private Gr_Session_Repository $sessions;

    /**
     * Attribution listener, wired at construction.
     *
     * @var Gr_Attribution_Listener
     */
    private Gr_Attribution_Listener $listener;

    /**
     * Conversion binding service, wired at construction.
     *
     * @var Gr_Attribution_Service
     */
    private Gr_Attribution_Service $attribution;

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
        $this->events      = new Gr_Event_Dispatcher( new Gr_Event_Repository() );
        $this->settings    = new Gr_Settings();
        $this->identity    = new Gr_Identity( $this->settings );
        $this->sessions    = new Gr_Session_Repository();
        $this->attribution = new Gr_Attribution_Service( new Gr_Touchpoint_Repository(), new Gr_Conversion_Repository() );
        $this->listener    = new Gr_Attribution_Listener(
            $this->identity,
            $this->sessions,
            new Gr_Touchpoint_Repository(),
            $this->settings
        );
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
     * Visitor/session identity (docs/05 §3.2 dual-track), for the
     * attribution listener and the collect endpoint.
     *
     * @return Gr_Identity
     */
    public function identity(): Gr_Identity {
        return $this->identity;
    }

    /**
     * Sessions repository, for session upserts and the online count.
     *
     * @return Gr_Session_Repository
     */
    public function sessions(): Gr_Session_Repository {
        return $this->sessions;
    }

    /**
     * Attribution listener (docs/02 §4), for the template_redirect hook
     * and tests.
     *
     * @return Gr_Attribution_Listener
     */
    public function listener(): Gr_Attribution_Listener {
        return $this->listener;
    }

    /**
     * Conversion binding service (docs/02 §4), for adapters and the
     * gr_bind_conversion facade.
     *
     * @return Gr_Attribution_Service
     */
    public function attribution(): Gr_Attribution_Service {
        return $this->attribution;
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
        add_action( 'rest_api_init', array( Gr_Collect_Controller::class, 'register_routes' ) );
        add_action( 'template_redirect', array( $this->listener, 'handle' ), 10, 0 );

        // Ecosystem adapters register only when their target plugin
        // actually boots on this site; each public-surface gate runs
        // BEFORE our adapter class is referenced, so sites without the
        // target never even load the adapter file (docs/02 §2.6).
        if ( class_exists( 'WooCommerce', false ) ) {
            ( new Gr_Woocommerce_Adapter( $this->identity, $this->attribution ) )->register_hooks();
        }
        if ( defined( 'FLUENTFORM' ) ) {
            ( new Gr_Fluentforms_Adapter( $this->identity, $this->attribution ) )->register_hooks();
        }
        if ( function_exists( 'wpcf7' ) ) {
            ( new Gr_Cf7_Adapter( $this->identity, $this->attribution ) )->register_hooks();
        }
        if ( function_exists( 'wpforms' ) ) {
            ( new Gr_Wpforms_Adapter( $this->identity, $this->attribution ) )->register_hooks();
        }

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
