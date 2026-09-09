<?php
/**
 * Minimal in-memory WordPress option-API stubs for unit tests (docs/11: the
 * domain layer tests run without WordPress). Signatures mirror core's
 * wp-includes/option.php; autoload flags are tracked so tests can verify the
 * plugin's single-autoload contract (docs/05 §6).
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

if ( ! function_exists( 'get_option' ) ) {
    /**
     * Option lookup.
     *
     * @param string $name    Option name.
     * @param mixed  $default Default when missing.
     * @return mixed
     */
    function get_option( $name, $default = false ) {
        if ( ! array_key_exists( $name, $GLOBALS['gr_stub_options']['data'] ) ) {
            return $default;
        }

        return $GLOBALS['gr_stub_options']['data'][ $name ];
    }
}

if ( ! function_exists( 'add_option' ) ) {
    /**
     * Option creation.
     *
     * @param string $name       Option name.
     * @param mixed  $value      Option value.
     * @param string $unused     Deprecated core parameter.
     * @param string $autoload   'yes' or 'no' (WP 6.0 form).
     * @return bool
     */
    function add_option( $name, $value, $unused = '', $autoload = 'yes' ) {
        if ( array_key_exists( $name, $GLOBALS['gr_stub_options']['data'] ) ) {
            return false;
        }

        $GLOBALS['gr_stub_options']['data'][ $name ]     = $value;
        $GLOBALS['gr_stub_options']['autoload'][ $name ] = $autoload;

        return true;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    /**
     * Option update.
     *
     * @param string $name  Option name.
     * @param mixed  $value New value.
     * @return bool
     */
    function update_option( $name, $value ) {
        $GLOBALS['gr_stub_options']['data'][ $name ] = $value;

        return true;
    }
}

if ( ! function_exists( 'gr_stub_reset_options' ) ) {
    /**
     * Resets the stub stores between tests.
     *
     * @return void
     */
    function gr_stub_reset_options(): void {
        $GLOBALS['gr_stub_options'] = array(
            'data'     => array(),
            'autoload' => array(),
        );
        $GLOBALS['gr_stub_transients']    = array();
        $GLOBALS['gr_stub_cron']          = array();
        $GLOBALS['gr_stub_actions']       = array();
        $GLOBALS['gr_stub_fired_actions'] = array();
        $GLOBALS['wpdb']                  = new Gr_Stub_Wpdb();
    }
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! function_exists( 'get_transient' ) ) {
    /**
     * Transient lookup.
     *
     * @param string $transient Transient name.
     * @return mixed Stored value or false when missing.
     */
    function get_transient( $transient ) {
        return $GLOBALS['gr_stub_transients'][ $transient ] ?? false;
    }
}

if ( ! function_exists( 'set_transient' ) ) {
    /**
     * Transient write. Expiration is not tracked in memory.
     *
     * @param string $transient  Transient name.
     * @param mixed  $value      Value to store.
     * @param int    $expiration Lifetime in seconds (ignored).
     * @return bool
     */
    function set_transient( $transient, $value, $expiration = 0 ) {
        $GLOBALS['gr_stub_transients'][ $transient ] = $value;

        return true;
    }
}

if ( ! function_exists( 'delete_transient' ) ) {
    /**
     * Transient delete.
     *
     * @param string $transient Transient name.
     * @return bool
     */
    function delete_transient( $transient ) {
        unset( $GLOBALS['gr_stub_transients'][ $transient ] );

        return true;
    }
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
    /**
     * One-shot cron scheduling.
     *
     * @param int              $timestamp Unix timestamp to run at.
     * @param string           $hook      Hook to fire.
     * @param array<int|string, mixed> $args Hook arguments.
     * @return bool
     */
    function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
        $GLOBALS['gr_stub_cron'][] = array(
            'timestamp'  => (int) $timestamp,
            'hook'       => $hook,
            'args'       => $args,
            'recurrence' => '',
        );

        return true;
    }
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
    /**
     * Recurring cron scheduling.
     *
     * @param int              $timestamp First-run Unix timestamp.
     * @param string           $recurrence Recurrence identifier.
     * @param string           $hook       Hook to fire.
     * @param array<int|string, mixed> $args Hook arguments.
     * @return bool
     */
    function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
        $GLOBALS['gr_stub_cron'][] = array(
            'timestamp'  => (int) $timestamp,
            'hook'       => $hook,
            'args'       => $args,
            'recurrence' => $recurrence,
        );

        return true;
    }
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
    /**
     * Next scheduled run for a hook.
     *
     * @param string           $hook Hook to look up.
     * @param array<int|string, mixed> $args Hook arguments.
     * @return int|false Timestamp or false when not scheduled.
     */
    function wp_next_scheduled( $hook, $args = array() ) {
        foreach ( $GLOBALS['gr_stub_cron'] as $event ) {
            if ( $event['hook'] === $hook && $event['args'] === $args ) {
                return $event['timestamp'];
            }
        }

        return false;
    }
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
    /**
     * Removes every scheduled instance of a hook.
     *
     * @param string $hook Hook to clear.
     * @return bool
     */
    function wp_clear_scheduled_hook( $hook ) {
        $GLOBALS['gr_stub_cron'] = array_values(
            array_filter(
                $GLOBALS['gr_stub_cron'],
                static function ( $event ) use ( $hook ): bool {
                    return $event['hook'] !== $hook;
                }
            )
        );

        return true;
    }
}

if ( ! function_exists( 'add_action' ) ) {
    /**
     * Hook registration (recorded, never executed).
     *
     * @param string                $hook_name     Hook to observe.
     * @param callable|string|array $callback      Callback.
     * @param int                   $priority      Priority.
     * @param int                   $accepted_args Accepted argument count.
     * @return bool
     */
    function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
        $GLOBALS['gr_stub_actions'][] = array(
            'hook'     => $hook_name,
            'callback' => $callback,
            'priority' => $priority,
        );

        return true;
    }
}

if ( ! function_exists( 'do_action' ) ) {
    /**
     * Hook execution (recorded only; callbacks are not invoked).
     *
     * @param string $hook_name Hook to fire.
     * @param mixed  ...$extra_args Optional hook arguments.
     * @return void
     */
    function do_action( $hook_name, ...$extra_args ) {
        $GLOBALS['gr_stub_fired_actions'][] = $hook_name;
    }
}
