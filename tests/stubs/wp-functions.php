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

if ( ! function_exists( 'delete_option' ) ) {
    /**
     * Option delete.
     *
     * @param string $name Option name.
     * @return bool
     */
    function delete_option( $name ) {
        $existed = array_key_exists( $name, $GLOBALS['gr_stub_options']['data'] );
        unset( $GLOBALS['gr_stub_options']['data'][ $name ], $GLOBALS['gr_stub_options']['autoload'][ $name ] );

        return $existed;
    }
}

if ( ! function_exists( 'wp_salt' ) ) {
    /**
     * Salt stand-in, deterministic per scheme.
     *
     * @param string $scheme Salt scheme.
     * @return string
     */
    function wp_salt( $scheme = 'auth' ) {
        return 'stub-salt-' . $scheme;
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    /**
     * Text sanitization stand-in: strips tags and collapses whitespace.
     *
     * @param string $value Raw text.
     * @return string
     */
    function sanitize_text_field( $value ) {
        $clean = trim( strip_tags( (string) $value ) );

        return (string) preg_replace( '/[\r\n\t ]+/', ' ', $clean );
    }
}

if ( ! function_exists( 'wp_has_consent' ) ) {
    /**
     * Consent lookup stand-in backed by $GLOBALS['gr_stub_consent'].
     *
     * @param string $purpose Purpose key.
     * @return bool
     */
    function wp_has_consent( $purpose ) {
        return (bool) ( $GLOBALS['gr_stub_consent'][ $purpose ] ?? false );
    }
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
    /**
     * UUID stand-in, overridable via $GLOBALS['gr_stub_uuid'].
     *
     * @return string
     */
    function wp_generate_uuid4() {
        return $GLOBALS['gr_stub_uuid'] ?? '11111111-2222-4333-8444-555555555555';
    }
}

if ( ! function_exists( 'is_ssl' ) ) {
    /**
     * TLS detection stand-in, overridable via $GLOBALS['gr_stub_is_ssl'].
     *
     * @return bool
     */
    function is_ssl() {
        return (bool) ( $GLOBALS['gr_stub_is_ssl'] ?? false );
    }
}

if ( ! function_exists( 'gr_stub_reset_options' ) ) {
    /**
     * Resets the stub stores between tests.
     *
     * @return void
     */
    function gr_stub_reset_options(): void {
        $GLOBALS['gr_stub_options']           = array(
            'data'     => array(),
            'autoload' => array(),
        );
        $GLOBALS['gr_stub_transients']        = array();
        $GLOBALS['gr_stub_cron']              = array();
        $GLOBALS['gr_stub_dns']               = array(
            'ptr'     => array(),
            'forward' => array(),
            'calls'   => array(),
        );
        $GLOBALS['gr_stub_actions']           = array();
        $GLOBALS['gr_stub_fired_actions']     = array();
        $GLOBALS['gr_stub_fired_action_args'] = array();
        $GLOBALS['gr_stub_filters']           = array();
        $GLOBALS['gr_stub_consent']           = array();
        $GLOBALS['gr_stub_cookies']           = array();
        $GLOBALS['gr_stub_rest_routes']       = array();
        $GLOBALS['gr_stub_cache']             = array();
        $GLOBALS['gr_stub_wc_orders']         = array();
        $GLOBALS['gr_stub_enqueued_scripts']  = array();
        $GLOBALS['gr_stub_inline_scripts']    = array();
        $GLOBALS['gr_stub_shortcodes']        = array();
        $GLOBALS['gr_stub_cli_commands']      = array();
        $GLOBALS['gr_stub_cli_messages']      = array(
            'success' => array(),
            'warning' => array(),
            'error'   => array(),
        );
        $GLOBALS['wpdb']                      = new Gr_Stub_Wpdb();
        unset( $GLOBALS['gr_stub_nocache'], $GLOBALS['gr_stub_is_admin'] );

        // Overridable knobs (clock, uuid, tls, ext-cache, rand) reset to
        // their defaults so one test's override never leaks into the next.
        unset(
            $GLOBALS['gr_stub_now'],
            $GLOBALS['gr_stub_uuid'],
            $GLOBALS['gr_stub_is_ssl'],
            $GLOBALS['gr_stub_ext_cache'],
            $GLOBALS['gr_stub_rand'],
            $GLOBALS['gr_stub_epoch']
        );

        // Services memoize their view of the stub stores, so the container
        // itself restarts with them; harmless when nothing was built yet.
        if ( class_exists( 'GreenPNG\Core\Gr_Plugin' ) ) {
            GreenPNG\Core\Gr_Plugin::reset_instance();
        }
        if ( class_exists( 'GreenPNG\Funnel\Gr_Ab_Experiments' ) ) {
            GreenPNG\Funnel\Gr_Ab_Experiments::reset_memo_for_tests();
        }
        if ( class_exists( 'GreenPNG\Security\Gr_Scanner_Ua' ) ) {
            GreenPNG\Security\Gr_Scanner_Ua::reset_for_tests();
        }
        if ( class_exists( 'GreenPNG\Security\Gr_Access_Rules' ) ) {
            GreenPNG\Security\Gr_Access_Rules::reset_for_tests();
        }
        if ( class_exists( 'GreenPNG\Security\Gr_Security_Gate' ) ) {
            GreenPNG\Security\Gr_Security_Gate::reset_for_tests();
        }
    }
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! function_exists( 'esc_attr' ) ) {
    /**
     * Attribute-escaping stand-in mirroring core's contract closely
     * enough for markup assertions.
     *
     * @param mixed $text Value to escape.
     * @return string
     */
    function esc_attr( $text ) {
        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
    }
}

if ( ! function_exists( 'wp_unslash' ) ) {
    /**
     * Slash-stripping stand-in mirroring core's recursive behavior.
     *
     * @param string|array $value Value to unslash.
     * @return string|array
     */
    function wp_unslash( $value ) {
        if ( is_array( $value ) ) {
            return array_map( 'wp_unslash', $value );
        }

        return stripslashes( (string) $value );
    }
}

if ( ! function_exists( 'current_time' ) ) {
    /**
     * Clock stand-in, overridable via $GLOBALS['gr_stub_now'] so tests can
     * cross day boundaries deterministically.
     *
     * @param string $type Time format type ('mysql' expected).
     * @return string
     */
    function current_time( $type ) {
        return $GLOBALS['gr_stub_now'] ?? '2026-09-10 00:00:00';
    }
}

if ( ! function_exists( 'wp_rand' ) ) {
    /**
     * Random int stand-in, overridable via $GLOBALS['gr_stub_rand'] so
     * minted ids stay deterministic in tests.
     *
     * @param int $min Lower bound.
     * @param int $max Upper bound.
     * @return int
     */
    function wp_rand( $min = 0, $max = 0 ) {
        if ( isset( $GLOBALS['gr_stub_rand'] ) ) {
            return (int) $GLOBALS['gr_stub_rand'];
        }

        return mt_rand( (int) $min, (int) $max );
    }
}

if ( ! function_exists( 'is_admin' ) ) {
    /**
     * Admin-context stand-in, toggleable via $GLOBALS['gr_stub_is_admin'].
     *
     * @return bool
     */
    function is_admin() {
        return ! empty( $GLOBALS['gr_stub_is_admin'] );
    }
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
    /**
     * Script enqueue recorder.
     *
     * @param string           $handle Script handle.
     * @param string           $src    Script URL.
     * @param array<int,mixed> $deps   Dependencies.
     * @param string|bool      $ver    Version.
     * @param bool             $footer Footer placement.
     * @return bool
     */
    function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $footer = false ) {
        $GLOBALS['gr_stub_enqueued_scripts'][ $handle ] = array(
            'src'    => (string) $src,
            'ver'    => $ver,
            'footer' => $footer ? true : false,
        );

        return true;
    }
}

if ( ! function_exists( 'wp_add_inline_script' ) ) {
    /**
     * Inline script recorder.
     *
     * @param string $handle Script handle.
     * @param string $text   Inline code.
     * @param string $position Before/after.
     * @return bool
     */
    function wp_add_inline_script( $handle, $text, $position = 'after' ) {
        $GLOBALS['gr_stub_inline_scripts'][] = array(
            'handle'   => (string) $handle,
            'text'     => (string) $text,
            'position' => (string) $position,
        );

        return true;
    }
}

if ( ! function_exists( 'sanitize_key' ) ) {
    /**
     * Key slugifier mirroring core: lowercase, keep alnum dash underscore.
     *
     * @param string $key Raw key.
     * @return string
     */
    function sanitize_key( $key ) {
        return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
    }
}

if ( ! function_exists( 'add_shortcode' ) ) {
    /**
     * Shortcode registration recorder.
     *
     * @param string                $tag      Shortcode tag.
     * @param callable|string|array $callback Handler.
     * @return void
     */
    function add_shortcode( $tag, $callback ) {
        $GLOBALS['gr_stub_shortcodes'][ (string) $tag ] = $callback;
    }
}

if ( ! function_exists( 'shortcode_atts' ) ) {
    /**
     * Attribute merge mirroring core: only keys present in the
     * defaults survive, extras are dropped (core behavior — dynamic
     * attribute shortcodes must parse $atts directly, not through
     * this helper).
     *
     * @param array<string, mixed> $defaults Default attributes.
     * @param array<string, mixed> $atts     Given attributes.
     * @param string               $tag      Shortcode tag, unused.
     * @return array<string, mixed>
     */
    function shortcode_atts( $defaults, $atts, $tag = '' ) {
        $merged = array();
        foreach ( $defaults as $key => $value ) {
            $merged[ $key ] = array_key_exists( $key, $atts ) ? $atts[ $key ] : $value;
        }

        return $merged;
    }
}

if ( ! function_exists( 'home_url' ) ) {
    /**
     * Site home URL stand-in for a fixed host root.
     *
     * @param string $path Optional path.
     * @return string
     */
    function home_url( $path = '' ) {
        return 'https://stub.example/' . ltrim( (string) $path, '/' );
    }
}

if ( ! function_exists( 'wp_parse_url' ) ) {
    /**
     * URL parsing stand-in delegating to PHP's parse_url.
     *
     * @param string $url  The URL to parse.
     * @param int    $component Component to return.
     * @return mixed
     */
    function wp_parse_url( $url, $component = -1 ) {
        return parse_url( (string) $url, $component );
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    /**
     * JSON encoding stand-in mirroring the core signature.
     *
     * @param mixed $data    Value to encode.
     * @param int   $options json_encode options.
     * @param int   $depth   Maximum depth.
     * @return string|false
     */
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}

if ( ! function_exists( 'gr_stub_clock' ) ) {
    /**
     * Numeric epoch stand-in, overridable via $GLOBALS['gr_stub_epoch']
     * so TTL expiry tests advance time deterministically.
     *
     * @return int
     */
    function gr_stub_clock(): int {
        return $GLOBALS['gr_stub_epoch'] ?? 1757462400; // 2026-09-10 00:00:00Z.
    }
}

if ( ! function_exists( 'get_transient' ) ) {
    /**
     * Transient lookup with real TTL semantics: an expired entry is
     * removed and reads as false, exactly like core.
     *
     * @param string $transient Transient name.
     * @return mixed Stored value or false when missing or expired.
     */
    function get_transient( $transient ) {
        $entry = $GLOBALS['gr_stub_transients'][ $transient ] ?? null;

        if ( ! is_array( $entry ) || ! array_key_exists( 'value', $entry ) ) {
            return false;
        }

        $expires_at = isset( $entry['expires_at'] ) && is_int( $entry['expires_at'] ) ? $entry['expires_at'] : 0;
        if ( $expires_at > 0 && gr_stub_clock() >= $expires_at ) {
            unset( $GLOBALS['gr_stub_transients'][ $transient ] );
            return false;
        }

        return $entry['value'];
    }
}

if ( ! function_exists( 'set_transient' ) ) {
    /**
     * Transient write recording the expiry deadline against the stub
     * clock; 0 means no expiry.
     *
     * @param string $transient  Transient name.
     * @param mixed  $value      Value to store.
     * @param int    $expiration Lifetime in seconds.
     * @return bool
     */
    function set_transient( $transient, $value, $expiration = 0 ) {
        $GLOBALS['gr_stub_transients'][ $transient ] = array(
            'value'      => $value,
            'expires_at' => $expiration > 0 ? gr_stub_clock() + $expiration : 0,
        );

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
     * @param int                      $timestamp Unix timestamp to run at.
     * @param string                   $hook      Hook to fire.
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
     * @param int                      $timestamp First-run Unix timestamp.
     * @param string                   $recurrence Recurrence identifier.
     * @param string                   $hook       Hook to fire.
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
     * @param string                   $hook Hook to look up.
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

if ( ! defined( 'DNS_A' ) ) {
    // Real PHP constant value; the resolver engine references these
    // global-scope names, so the stub environment must provide them.
    define( 'DNS_A', 1 );
}

if ( ! defined( 'DNS_AAAA' ) ) {
    define( 'DNS_AAAA', 28 );
}

if ( ! defined( 'DNS_ANY' ) ) {
    define( 'DNS_ANY', 268435456 );
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

if ( ! function_exists( 'add_filter' ) ) {
    /**
     * Filter registration (recorded, priority ignored).
     *
     * @param string                $hook_name     Hook to observe.
     * @param callable|string|array $callback      Callback.
     * @param int                   $priority      Priority.
     * @param int                   $accepted_args Accepted argument count.
     * @return bool
     */
    function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
        $GLOBALS['gr_stub_filters'][ $hook_name ][] = $callback;

        return true;
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    /**
     * Filter execution: callbacks run in registration order with the
     * accumulated value.
     *
     * @param string $hook_name Hook to apply.
     * @param mixed  $value     Initial value.
     * @param mixed  ...$extra_args Optional extra arguments.
     * @return mixed
     */
    function apply_filters( $hook_name, $value, ...$extra_args ) {
        foreach ( $GLOBALS['gr_stub_filters'][ $hook_name ] ?? array() as $callback ) {
            $value = $callback( $value, ...$extra_args );
        }

        return $value;
    }
}

if ( ! function_exists( 'do_action' ) ) {
    /**
     * Hook execution: the firing is recorded AND the registered
     * callbacks run, in priority order (stable for equal priorities,
     * like core). Callbacks receive every argument; PHP ignores the
     * extras, matching core's accepted_args behavior for tests that
     * do not declare variadics.
     *
     * @param string $hook_name Hook to fire.
     * @param mixed  ...$extra_args Optional hook arguments.
     * @return void
     */
    function do_action( $hook_name, ...$extra_args ) {
        $GLOBALS['gr_stub_fired_actions'][]     = $hook_name;
        $GLOBALS['gr_stub_fired_action_args'][] = array(
            'hook' => $hook_name,
            'args' => $extra_args,
        );

        $matched = array();
        $seq     = 0;
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            if ( $hook_name === (string) $registration['hook'] ) {
                $matched[] = array( (int) $registration['priority'], $seq++, $registration['callback'] );
            }
        }

        // Decorated sort keeps equal priorities in registration order
        // (usort alone is not stable before PHP 8.0).
        usort(
            $matched,
            static function ( $a, $b ) {
                return array( $a[0], $a[1] ) <=> array( $b[0], $b[1] );
            }
        );

        foreach ( $matched as $registration ) {
            if ( is_callable( $registration[2] ) ) {
                $registration[2]( ...$extra_args );
            }
        }
    }
}
