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

if ( ! function_exists( 'is_wp_error' ) ) {
    /**
     * WP_Error predicate; the class itself comes from rest-stubs.php.
     *
     * @param mixed $thing Any value.
     * @return bool
     */
    function is_wp_error( $thing ) {
        return $thing instanceof WP_Error;
    }
}

if ( ! function_exists( 'wp_safe_remote_get' ) ) {
    /**
     * Outbound GET stand-in: answers from gr_stub_http keyed by URL,
     * records every call in gr_stub_http_calls so tests can prove a
     * gate refused to touch the network.
     *
     * @param string $url  Request URL.
     * @param array<string, mixed> $args Request arguments.
     * @return array<string, mixed>|WP_Error
     */
    function wp_safe_remote_get( $url, $args = array() ) {
        return gr_stub_http_answer( 'GET', $url, $args );
    }
}

if ( ! function_exists( 'wp_safe_remote_post' ) ) {
    /**
     * Outbound POST stand-in, same store as GET.
     *
     * @param string $url  Request URL.
     * @param array<string, mixed> $args Request arguments.
     * @return array<string, mixed>|WP_Error
     */
    function wp_safe_remote_post( $url, $args = array() ) {
        return gr_stub_http_answer( 'POST', $url, $args );
    }
}

if ( ! function_exists( 'gr_stub_http_answer' ) ) {
    /**
     * Shared responder for the remote stubs.
     *
     * @param string $method Request method.
     * @param string $url    Request URL.
     * @param array<string, mixed> $args Request arguments.
     * @return array<string, mixed>|WP_Error
     */
    function gr_stub_http_answer( $method, $url, $args ) {
        $GLOBALS['gr_stub_http_calls'][] = array(
            'method' => $method,
            'url'    => $url,
            'args'   => $args,
        );

        if ( ! array_key_exists( $url, $GLOBALS['gr_stub_http'] ?? array() ) ) {
            return new WP_Error( 'gr_stub_http_missing', "no stub answer for {$url}" );
        }

        $answer = $GLOBALS['gr_stub_http'][ $url ];

        return is_object( $answer ) ? $answer : (array) $answer;
    }
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    /**
     * Status code reader over the stub response shape.
     *
     * @param array<string, mixed>|WP_Error $response Response.
     * @return int
     */
    function wp_remote_retrieve_response_code( $response ) {
        if ( is_wp_error( $response ) || ! is_array( $response ) ) {
            return 0;
        }

        return (int) ( $response['response']['code'] ?? 0 );
    }
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
    /**
     * Header reader over the stub response shape.
     *
     * @param array<string, mixed>|WP_Error $response Response.
     * @param string $name                   Header name.
     * @return string
     */
    function wp_remote_retrieve_header( $response, $name ) {
        if ( is_wp_error( $response ) || ! is_array( $response ) ) {
            return '';
        }

        return (string) ( $response['headers'][ $name ] ?? '' );
    }
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    /**
     * Body reader over the stub response shape.
     *
     * @param array<string, mixed>|WP_Error $response Response.
     * @return string
     */
    function wp_remote_retrieve_body( $response ) {
        if ( is_wp_error( $response ) || ! is_array( $response ) ) {
            return '';
        }

        return (string) ( $response['body'] ?? '' );
    }
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
    /**
     * Uploads directory stand-in: GeoIP override resolution reads it.
     * The default basedir does not exist, so tests fall back to the
     * bundled data unless a test points the knob at a fixture
     * directory.
     *
     * @return array<string, string>
     */
    function wp_upload_dir() {
        $basedir = $GLOBALS['gr_stub_uploads']['basedir'] ?? '/gr-stub-uploads-absent';

        return array(
            'basedir' => (string) $basedir,
            'baseurl' => 'http://stub.example/wp-content/uploads',
        );
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
        $GLOBALS['gr_stub_wp_die']            = array();
        $GLOBALS['gr_stub_actions']           = array();
        $GLOBALS['gr_stub_fired_actions']     = array();
        $GLOBALS['gr_stub_fired_action_args'] = array();
        $GLOBALS['gr_stub_filters']           = array();
        $GLOBALS['gr_stub_consent']           = array();
        $GLOBALS['gr_stub_cookies']           = array();
        $GLOBALS['gr_stub_uploads']           = array();
        $GLOBALS['gr_stub_http']              = array();
        $GLOBALS['gr_stub_http_calls']        = array();
        $GLOBALS['gr_stub_rest_routes']       = array();
        $GLOBALS['gr_stub_privacy_policy']    = array();
        $GLOBALS['gr_stub_cache']             = array();
        $GLOBALS['gr_stub_wc_orders']         = array();
        $GLOBALS['gr_stub_enqueued_scripts']  = array();
        $GLOBALS['gr_stub_registered_scripts'] = array();
        $GLOBALS['gr_stub_registered_styles'] = array();
        $GLOBALS['gr_stub_enqueued_styles']   = array();
        $GLOBALS['gr_stub_inline_scripts']    = array();
        $GLOBALS['gr_stub_admin_pages']       = array();
        $GLOBALS['gr_stub_submenu_pages']     = array();
        $GLOBALS['gr_stub_nonce_fields']      = array();
        $GLOBALS['gr_stub_redirects']         = array();
        $GLOBALS['gr_stub_submit_buttons']    = array();
        $GLOBALS['gr_stub_paginate_calls']    = array();
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
            $GLOBALS['gr_stub_ts'],
            $GLOBALS['gr_stub_uuid'],
            $GLOBALS['gr_stub_is_ssl'],
            $GLOBALS['gr_stub_ext_cache'],
            $GLOBALS['gr_stub_rand'],
            $GLOBALS['gr_stub_epoch']
        );
        unset( $GLOBALS['gr_stub_caps'] );
        unset( $GLOBALS['gr_stub_nonce_bad'], $GLOBALS['gr_stub_user_id'] );

        // Services memoize their view of the stub stores, so the container
        // itself restarts with them; harmless when nothing was built yet.
        if ( class_exists( 'GreenPNG\Core\Gr_Plugin' ) ) {
            GreenPNG\Core\Gr_Plugin::reset_instance();
        }
        if ( class_exists( 'GreenPNG\Core\Gr_Settings' ) ) {
            GreenPNG\Core\Gr_Settings::reset_for_tests();
        }
        if ( class_exists( 'GreenPNG\Core\Gr_Geoip' ) ) {
            GreenPNG\Core\Gr_Geoip::reset_for_tests();
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
        if ( class_exists( 'GreenPNG\Admin\Gr_Admin_Menu' ) ) {
            GreenPNG\Admin\Gr_Admin_Menu::reset_for_tests();
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

if ( ! function_exists( 'esc_url' ) ) {
    /**
     * URL display escaping: http/https or rooted-relative only, and
     * ampersands entity-encoded like core composes for output.
     *
     * @param string $url Candidate URL.
     * @return string
     */
    function esc_url( $url ) {
        $clean = gr_stub_clean_url( (string) $url );

        return str_replace( '&', '&amp;', $clean );
    }
}

if ( ! function_exists( 'esc_url_raw' ) ) {
    /**
     * URL storage escaping: the same scheme gate without entity
     * encoding, for URLs headed to storage or redirects.
     *
     * @param string $url Candidate URL.
     * @return string
     */
    function esc_url_raw( $url ) {
        return gr_stub_clean_url( (string) $url );
    }
}

if ( ! function_exists( 'gr_stub_clean_url' ) ) {
    /**
     * Shared scheme gate for the URL escapers: http/https or a
     * site-rooted path survives; anything else reads as empty.
     *
     * @param string $url Candidate URL.
     * @return string
     */
    function gr_stub_clean_url( $url ) {
        if ( '' === $url ) {
            return '';
        }

        if ( '/' === $url[0] ) {
            return $url;
        }

        $parts = parse_url( $url );
        if ( false === $parts || ! isset( $parts['scheme'], $parts['host'] ) ) {
            return '';
        }

        return in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ? $url : '';
    }
}

if ( ! function_exists( 'esc_html__' ) ) {
    /**
     * Translate-and-escape stand-in: identity translation plus HTML
     * escaping, the same contract core composes.
     *
     * @param string $text   Text to translate.
     * @param string $domain Text domain.
     * @return string
     */
    function esc_html__( $text, $domain = 'default' ) {
        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
    }
}

if ( ! function_exists( 'esc_html' ) ) {
    /**
     * Output escaping stand-in; same contract core composes for
     * esc_html__() minus the translation layer.
     *
     * @param string $text Text to escape.
     * @return string
     */
    function esc_html( $text ) {
        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
    }
}

if ( ! function_exists( 'wp_parse_url' ) ) {
    /**
     * URL parsing stand-in delegating to parse_url, matching core's
     * wrapper contract.
     *
     * @param string $url      The URL to parse.
     * @param int    $component The specific component.
     * @return mixed
     */
    function wp_parse_url( $url, $component = -1 ) {
        return parse_url( (string) $url, $component );
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
     * Clock stand-in, overridable via $GLOBALS['gr_stub_now'] (string)
     * and $GLOBALS['gr_stub_ts'] (epoch) so tests can cross day
     * boundaries deterministically; the two defaults describe the
     * same moment.
     *
     * @param string $type Time format type ('mysql' or 'timestamp').
     * @return string|int
     */
    function current_time( $type ) {
        if ( 'timestamp' === $type ) {
            return isset( $GLOBALS['gr_stub_ts'] )
                ? (int) $GLOBALS['gr_stub_ts']
                : (int) strtotime( '2026-09-10 00:00:00 UTC' );
        }

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
     * Script enqueue recorder. With an empty $src (the handle-only
     * form pages use after registration) it copies the registered
     * entry, mirroring core's registry resolution.
     *
     * @param string           $handle Script handle.
     * @param string           $src    Script URL.
     * @param array<int,mixed> $deps   Dependencies.
     * @param string|bool      $ver    Version.
     * @param bool             $footer Footer placement.
     * @return bool
     */
    function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $footer = false ) {
        if ( '' === $src && isset( $GLOBALS['gr_stub_registered_scripts'][ $handle ] ) ) {
            $registered = $GLOBALS['gr_stub_registered_scripts'][ $handle ];

            $GLOBALS['gr_stub_enqueued_scripts'][ $handle ] = array(
                'src'    => (string) $registered['src'],
                'deps'   => $registered['deps'],
                'ver'    => $registered['ver'],
                'footer' => $footer ? true : false,
            );

            return true;
        }

        $GLOBALS['gr_stub_enqueued_scripts'][ $handle ] = array(
            'src'    => (string) $src,
            'deps'   => $deps,
            'ver'    => $ver,
            'footer' => $footer ? true : false,
        );

        return true;
    }
}

if ( ! function_exists( 'wp_register_script' ) ) {
    /**
     * Script registration recorder.
     *
     * @param string           $handle Script handle.
     * @param string           $src    Script URL.
     * @param array<int,mixed> $deps   Dependencies.
     * @param string|bool      $ver    Version.
     * @param bool             $footer Footer placement.
     * @return bool
     */
    function wp_register_script( $handle, $src = '', $deps = array(), $ver = false, $footer = false ) {
        $GLOBALS['gr_stub_registered_scripts'][ $handle ] = array(
            'src'    => (string) $src,
            'deps'   => $deps,
            'ver'    => $ver,
            'footer' => $footer ? true : false,
        );

        return true;
    }
}

if ( ! function_exists( 'wp_register_style' ) ) {    /**
     * Style registration recorder.
     *
     * @param string           $handle Style handle.
     * @param string           $src    Style URL.
     * @param array<int,mixed> $deps   Dependencies.
     * @param string|bool      $ver    Version.
     * @return bool
     */
    function wp_register_style( $handle, $src = '', $deps = array(), $ver = false ) {
        $GLOBALS['gr_stub_registered_styles'][ $handle ] = array(
            'src'    => (string) $src,
            'ver'    => $ver,
        );

        return true;
    }
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
    /**
     * Style enqueue recorder; handle-only form resolves through the
     * registration store like core does.
     *
     * @param string           $handle Style handle.
     * @param string           $src    Style URL.
     * @param array<int,mixed> $deps   Dependencies.
     * @param string|bool      $ver    Version.
     * @return bool
     */
    function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) {
        if ( '' === $src && isset( $GLOBALS['gr_stub_registered_styles'][ $handle ] ) ) {
            $registered = $GLOBALS['gr_stub_registered_styles'][ $handle ];

            $GLOBALS['gr_stub_enqueued_styles'][ $handle ] = array(
                'src'    => (string) $registered['src'],
                'ver'    => $registered['ver'],
            );

            return true;
        }

        $GLOBALS['gr_stub_enqueued_styles'][ $handle ] = array(
            'src'    => (string) $src,
            'ver'    => $ver,
        );

        return true;
    }
}

if ( ! function_exists( 'add_menu_page' ) ) {
    /**
     * Top-level admin menu recorder; returns a deterministic hook
     * suffix like core does.
     *
     * @param string          $page_title Page title.
     * @param string          $menu_title Menu title.
     * @param string          $capability Capability gate.
     * @param string          $menu_slug  Slug.
     * @param callable|string $callback   Renderer.
     * @param string          $icon_url   Icon.
     * @param int|float       $position   Position.
     * @return string Hook suffix.
     */
    function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) {
        $GLOBALS['gr_stub_admin_pages'][] = array(
            'page_title' => (string) $page_title,
            'menu_title' => (string) $menu_title,
            'capability' => (string) $capability,
            'menu_slug'  => (string) $menu_slug,
            'callback'   => $callback,
            'icon_url'   => (string) $icon_url,
            'position'   => $position,
        );

        return 'toplevel_page_' . (string) $menu_slug;
    }
}

if ( ! function_exists( 'add_submenu_page' ) ) {
    /**
     * Submenu recorder; returns a deterministic hook suffix.
     *
     * @param string          $parent_slug Parent slug.
     * @param string          $page_title  Page title.
     * @param string          $menu_title  Menu title.
     * @param string          $capability  Capability gate.
     * @param string          $menu_slug   Slug.
     * @param callable|string $callback    Renderer.
     * @return string Hook suffix.
     */
    function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '' ) {
        $GLOBALS['gr_stub_submenu_pages'][] = array(
            'parent_slug' => (string) $parent_slug,
            'page_title'  => (string) $page_title,
            'menu_title'  => (string) $menu_title,
            'capability'  => (string) $capability,
            'menu_slug'   => (string) $menu_slug,
            'callback'    => $callback,
        );

        return (string) $parent_slug . '_page_' . (string) $menu_slug;
    }
}

if ( ! function_exists( 'current_user_can' ) ) {
    /**
     * Capability stand-in. Denies by default so permission tests opt
     * IN to access; $GLOBALS['gr_stub_caps'] may be true (grant all)
     * or an array of granted capability names.
     *
     * @param string $capability Capability name.
     * @return bool
     */
    function current_user_can( $capability ) {
        if ( isset( $GLOBALS['gr_stub_caps'] ) ) {
            if ( is_bool( $GLOBALS['gr_stub_caps'] ) ) {
                return $GLOBALS['gr_stub_caps'];
            }

            return in_array( $capability, (array) $GLOBALS['gr_stub_caps'], true );
        }

        return false;
    }
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
    /**
     * Deterministic nonce stand-in.
     *
     * @param string $action Action name.
     * @return string
     */
    function wp_create_nonce( $action = -1 ) {
        return 'gr-stub-nonce-' . md5( (string) $action );
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

if ( ! function_exists( 'admin_url' ) ) {
    /**
     * Admin URL stand-in for a fixed host root.
     *
     * @param string $path Optional path.
     * @return string
     */
    function admin_url( $path = '' ) {
        return 'https://stub.example/wp-admin/' . ltrim( (string) $path, '/' );
    }
}

if ( ! function_exists( 'submit_button' ) ) {
    /**
     * Submit button emitter: echoes like core does — the $wrap flag
     * only controls the submit paragraph around the input — records
     * for assertions, and returns the markup.
     *
     * @param string   $text  Button text.
     * @param string   $type  Button type class.
     * @param string   $name  Field name.
     * @param bool     $wrap  Wrap in a submit paragraph.
     * @param string[] $other Other attributes.
     * @return string
     */
    function submit_button( $text = '', $type = 'primary', $name = 'submit', $wrap = true, $other = array() ) {
        unset( $other );

        $text  = ( '' === (string) $text ) ? 'Save Changes' : (string) $text;
        $input = '<input type="submit" name="' . esc_attr( (string) $name ) . '" class="button button-' . esc_attr( (string) $type ) . '" value="' . esc_attr( $text ) . '" />';
        $html  = $wrap ? '<p class="submit">' . $input . '</p>' : $input;

        $GLOBALS['gr_stub_submit_buttons'][] = $text;

        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- stand-in echoes prebuilt escaped markup.

        return $html;
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

if ( ! function_exists( 'wp_verify_nonce' ) ) {
    /**
     * Nonce check stand-in: a nonce minted by wp_create_nonce for the
     * same action verifies; $GLOBALS['gr_stub_nonce_bad'] forces
     * failure so tests can exercise the reject arm.
     *
     * @param string $nonce  Nonce value.
     * @param string $action Action name.
     * @return int|bool 1 valid, 2 valid late, false invalid.
     */
    function wp_verify_nonce( $nonce, $action = -1 ) {
        if ( ! empty( $GLOBALS['gr_stub_nonce_bad'] ) ) {
            return false;
        }

        return ( 'gr-stub-nonce-' . md5( (string) $action ) === (string) $nonce ) ? 1 : false;
    }
}

if ( ! function_exists( 'check_admin_referer' ) ) {
    /**
     * Admin nonce gate stand-in: reads the field from $_POST (or the
     * query string) and verifies; the stub never terminates, it just
     * returns the verdict so tests can assert the reject arm.
     *
     * @param string      $action Action name.
     * @param string|null $query_key Field name carrying the nonce.
     * @return bool
     */
    function check_admin_referer( $action = -1, $query_key = '_wpnonce' ) {
        $field = ( null === $query_key ) ? '_wpnonce' : (string) $query_key;
        $nonce = isset( $_POST[ $field ] ) ? (string) wp_unslash( $_POST[ $field ] ) : '';

        return false !== wp_verify_nonce( $nonce, $action );
    }
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
    /**
     * Nonce field emitter: records and returns the hidden input.
     *
     * @param string|int $action Action name.
     * @param string     $name   Field name.
     * @param bool       $refer  Whether to add the referer field.
     * @param bool       $echo   Whether to print.
     * @return string
     */
    function wp_nonce_field( $action = -1, $name = '_wpnonce', $refer = true, $echo = true ) {
        $html = '<input type="hidden" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';

        $GLOBALS['gr_stub_nonce_fields'][] = array(
            'action' => (string) $action,
            'name'   => (string) $name,
        );

        if ( $echo ) {
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- stand-in echoes prebuilt escaped markup.
        }

        return $html;
    }
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
    /**
     * Redirect recorder; never terminates the test process.
     *
     * @param string $location Target URL.
     * @param int    $status   HTTP status.
     * @return bool
     */
    function wp_safe_redirect( $location, $status = 302 ) {
        $GLOBALS['gr_stub_redirects'][] = array(
            'location' => (string) $location,
            'status'   => (int) $status,
        );

        return true;
    }
}

if ( ! function_exists( 'add_query_arg' ) ) {
    /**
     * Query-string builder: accepts either (key, value, url) or an
     * array of pairs plus url, like core.
     *
     * @param mixed ...$args Key/value/url triple or array+url.
     * @return string
     */
    function add_query_arg( ...$args ) {
        $url = '';
        $pairs = array();

        if ( is_array( $args[0] ) ) {
            $pairs = $args[0];
            $url   = isset( $args[1] ) ? (string) $args[1] : '';
        } elseif ( count( $args ) >= 2 ) {
            $pairs = array( (string) $args[0] => $args[1] );
            $url   = isset( $args[2] ) ? (string) $args[2] : '';
        }

        // Core composes query strings without encoding values
        // (build_query passes urlencode=false), so the stand-in does
        // the same: pairs join verbatim, in the given order.
        $query = implode(
            '&',
            array_map(
                static function ( $key, $value ): string {
                    return $key . '=' . $value;
                },
                array_keys( $pairs ),
                $pairs
            )
        );
        if ( '' === $url ) {
            return '?' . $query;
        }

        return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $query;
    }
}

if ( ! function_exists( 'get_current_user_id' ) ) {
    /**
     * Current user id stand-in, overridable via gr_stub_user_id.
     *
     * @return int
     */
    function get_current_user_id() {
        return (int) ( $GLOBALS['gr_stub_user_id'] ?? 0 );
    }
}

if ( ! function_exists( 'absint' ) ) {
    /**
     * Core's absolute-integer cast.
     *
     * @param mixed $value Candidate number.
     * @return int
     */
    function absint( $value ) {
        return abs( (int) $value );
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

if ( ! function_exists( 'wp_die' ) ) {
    /**
     * Termination stand-in: records the message and response args and
     * returns — the production contract (no-cache headers, status,
     * exit) is the caller's business, tests assert what was sent.
     *
     * @param string                       $message Message body.
     * @param string                       $title   Page title.
     * @param array<string, mixed>|string $args    Response args.
     * @return void
     */
    function wp_die( $message, $title = '', $args = array() ) {
        $GLOBALS['gr_stub_wp_die'][] = array(
            'message' => $message,
            'title'   => $title,
            'args'    => $args,
        );
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

if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
    /**
     * Suggested policy content recorder: core stores it for the
     * policy page; tests assert the recorded plugin name and text.
     *
     * @param string $plugin_name Suggesting plugin name.
     * @param string $content     Policy text.
     * @return void
     */
    function wp_add_privacy_policy_content( $plugin_name, $content ) {
        $GLOBALS['gr_stub_privacy_policy'][ (string) $plugin_name ] = (string) $content;
    }
}

if ( ! function_exists( 'wp_print_inline_script_tag' ) ) {
    /**
     * Inline script emitter stand-in, mirroring core's shape closely
     * enough for format-independent assertions.
     *
     * @param string                       $javascript Inline code.
     * @param array<string, string|bool>   $attributes Tag attributes.
     * @return void
     */
    function wp_print_inline_script_tag( $javascript, $attributes = array() ) {
        $attrs = '';
        foreach ( (array) $attributes as $name => $value ) {
            $attrs .= ' ' . (string) $name . '="' . esc_attr( (string) $value ) . '"';
        }
        echo '<script type="text/javascript"' . $attrs . ">\n" . (string) $javascript . "\n</script>\n";
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

if ( ! function_exists( 'checked' ) ) {
    /**
     * Checked attribute stand-in, mirroring core's strict first-arg
     * comparison then the echoed markup.
     *
     * @param mixed $checked One of the compared values.
     * @param mixed $current The other compared value.
     * @return string
     */
    function checked( $checked, $current = true ) {
        $out = ( (string) $checked === (string) $current ) ? ' checked="checked"' : '';
        echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- stand-in echoes its own prebuilt attribute markup, core shape.
        return $out;
    }
}

if ( ! function_exists( 'selected' ) ) {
    /**
     * Selected attribute stand-in, mirroring core's comparison shape.
     *
     * @param mixed $selected One of the compared values.
     * @param mixed $current  The other compared value.
     * @return string
     */
    function selected( $selected, $current = true ) {
        $out = ( (string) $selected === (string) $current ) ? ' selected="selected"' : '';
        echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- stand-in echoes its own prebuilt attribute markup, core shape.
        return $out;
    }
}

if ( ! function_exists( 'get_bloginfo' ) ) {
    /**
     * Site info stand-in; the version knob keeps environment exports
     * assertable.
     *
     * @param string $show Info key; only 'version' is modeled.
     * @return string
     */
    function get_bloginfo( $show = '' ) {
        if ( 'version' === (string) $show ) {
            return (string) ( $GLOBALS['gr_stub_wp_version'] ?? '6.0' );
        }

        return '';
    }
}

if ( ! function_exists( 'get_locale' ) ) {
    /**
     * Locale stand-in.
     *
     * @return string
     */
    function get_locale() {
        return (string) ( $GLOBALS['gr_stub_locale'] ?? 'en_US' );
    }
}

if ( ! function_exists( 'is_multisite' ) ) {
    /**
     * Multisite flag stand-in.
     *
     * @return bool
     */
    function is_multisite() {
        return (bool) ( $GLOBALS['gr_stub_multisite'] ?? false );
    }
}

if ( ! function_exists( 'paginate_links' ) ) {
    /**
     * Pagination link list stand-in: core builds anchor markup from
     * base/current/total; the stand-in keeps the same contract —
     * each page one anchor (the href carries the page number where
     * the caller's base put %#%), the current page a span — and
     * records the call for assertions.
     *
     * @param array<string, mixed> $args base/format/current/total.
     * @return string|void
     */
    function paginate_links( $args = array() ) {
        $args    = is_array( $args ) ? $args : array();
        $base    = (string) ( $args['base'] ?? '' );
        $current = max( 1, (int) ( $args['current'] ?? 1 ) );
        $total   = max( 1, (int) ( $args['total'] ?? 1 ) );

        $GLOBALS['gr_stub_paginate_calls'][] = array(
            'base'    => $base,
            'current' => $current,
            'total'   => $total,
        );

        if ( $total <= 1 ) {
            return '';
        }

        $links = array();
        for ( $page = 1; $page <= $total; $page++ ) {
            $href = str_replace( '%#%', (string) $page, $base );
            if ( $page === $current ) {
                $links[] = '<span aria-current="page" class="page-numbers current">' . (string) $page . '</span>';
            } else {
                $links[] = '<a class="page-numbers" href="' . esc_attr( $href ) . '">' . (string) $page . '</a>';
            }
        }

        return implode( "\n", $links );
    }
}
