<?php
/**
 * Global function facades (docs/03): thin forwards only, three statements
 * of body at most; all logic lives in the classes these functions point
 * at. Loaded once from the entry file right after the autoloader because
 * plain functions cannot be autoloaded.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Event;
use GreenPNG\Core\Gr_Plugin;

if ( ! function_exists( 'gr' ) ) {
    /**
     * Main container accessor (docs/03 §1).
     *
     * @return Gr_Plugin
     */
    function gr(): Gr_Plugin {
        return Gr_Plugin::instance();
    }
}

if ( ! function_exists( 'gr_dispatch_event' ) ) {
    /**
     * Event dispatch facade (docs/03 §2).
     *
     * @param string               $name    Event name.
     * @param array<string, mixed> $payload Event payload.
     * @return Gr_Event
     */
    function gr_dispatch_event( string $name, array $payload = array() ): Gr_Event {
        return gr()->events()->dispatch( $name, $payload );
    }
}

if ( ! function_exists( 'gr_get_recent_events' ) ) {
    /**
     * Recent event feed facade (docs/03 §2).
     *
     * @param string $name  Optional event-name filter.
     * @param int    $limit Row ceiling.
     * @return array<int, array<string, mixed>>
     */
    function gr_get_recent_events( string $name = '', int $limit = 50 ): array {
        return gr()->events()->recent( $name, $limit );
    }
}
