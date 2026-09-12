<?php
/**
 * Minimal REST-API stand-ins for unit tests (docs/11: domain-layer tests
 * run without WordPress). Signatures mirror wp-includes/rest-api.php and
 * class-wp-rest-request.php closely enough for the collect controller;
 * route registrations and nocache calls are recorded so tests can assert
 * the wiring without a REST server.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

if ( ! function_exists( '__' ) ) {
    /**
     * Translation stand-in: identity for the domain.
     *
     * @param string $text   Text to translate.
     * @param string $domain Text domain.
     * @return string
     */
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}

if ( ! function_exists( 'esc_url_raw' ) ) {
    /**
     * Database-safe URL escape stand-in.
     *
     * @param string $url    Raw URL.
     * @param array  $protocols Allowed protocols (ignored).
     * @return string
     */
    function esc_url_raw( $url, $protocols = array() ) {
        return $url;
    }
}

if ( ! function_exists( 'rest_url' ) ) {
    /**
     * REST URL builder for a known site root.
     *
     * @param string $path Route path.
     * @return string
     */
    function rest_url( $path = '' ) {
        return 'https://stub.example/wp-json/' . ltrim( (string) $path, '/' );
    }
}

if ( ! function_exists( 'register_rest_route' ) ) {
    /**
     * Route registration recorder.
     *
     * @param string              $namespace Route namespace.
     * @param string              $route     Route path.
     * @param array<string,mixed> $args      Route configuration.
     * @return bool
     */
    function register_rest_route( $namespace, $route, $args = array() ) {
        $GLOBALS['gr_stub_rest_routes'][] = array(
            'namespace' => $namespace,
            'route'     => $route,
            'args'      => $args,
        );

        return true;
    }
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
    /**
     * Response wrap stand-in.
     *
     * @param mixed $data Response data.
     * @return WP_REST_Response
     */
    function rest_ensure_response( $data ) {
        return new WP_REST_Response( $data );
    }
}

if ( ! function_exists( 'nocache_headers' ) ) {
    /**
     * Cache-avoidance header recorder.
     *
     * @return void
     */
    function nocache_headers() {
        $GLOBALS['gr_stub_nocache'] = true;
    }
}

if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
    /**
     * Object-cache availability, overridable via gr_stub_ext_cache.
     *
     * @return bool
     */
    function wp_using_ext_object_cache() {
        return (bool) ( $GLOBALS['gr_stub_ext_cache'] ?? false );
    }
}

if ( ! function_exists( 'wp_cache_get' ) ) {
    /**
     * In-memory cache lookup.
     *
     * @param string $key   Cache key.
     * @param string $group Cache group.
     * @return mixed Stored value or false when missing.
     */
    function wp_cache_get( $key, $group = '' ) {
        return $GLOBALS['gr_stub_cache'][ $group . ':' . $key ] ?? false;
    }
}

if ( ! function_exists( 'wp_cache_add' ) ) {
    /**
     * In-memory cache add; existing keys are left untouched. TTL is not
     * tracked in memory.
     *
     * @param string $key   Cache key.
     * @param mixed  $data  Value to store.
     * @param string $group Cache group.
     * @param int    $ttl   Lifetime in seconds (ignored).
     * @return bool
     */
    function wp_cache_add( $key, $data, $group = '', $ttl = 0 ) {
        if ( false !== wp_cache_get( $key, $group ) ) {
            return false;
        }

        $GLOBALS['gr_stub_cache'][ $group . ':' . $key ] = $data;

        return true;
    }
}

if ( ! function_exists( 'wp_cache_incr' ) ) {
    /**
     * In-memory counter increment.
     *
     * @param string $key    Cache key.
     * @param int    $offset Increment amount.
     * @param string $group  Cache group.
     * @return int|false New value, or false when the key is missing.
     */
    function wp_cache_incr( $key, $offset = 1, $group = '' ) {
        $current = wp_cache_get( $key, $group );
        if ( false === $current ) {
            return false;
        }

        $GLOBALS['gr_stub_cache'][ $group . ':' . $key ] = (int) $current + (int) $offset;

        return (int) $current + (int) $offset;
    }
}

if ( ! class_exists( 'WP_Error' ) ) {
    /**
     * Error stand-in with core's multi-error semantics: several codes
     * can accumulate (registration_errors grows one entry per veto),
     * accessors read the first unless a code is named.
     */
    final class WP_Error {

        /**
         * Messages per code.
         *
         * @var array<string|int, list<string>>
         */
        private $errors = array();

        /**
         * Data per code.
         *
         * @var array<string|int, mixed>
         */
        private $error_data = array();

        /**
         * Constructor.
         *
         * @param string|int $code    Error code.
         * @param string     $message Error message.
         * @param mixed      $data    Extra data.
         */
        public function __construct( $code = '', $message = '', $data = null ) {
            if ( null === $code || '' === $code ) {
                return;
            }

            $this->add( $code, $message, $data );
        }

        /**
         * Appends one more code/message pair, like core.
         *
         * @param string|int $code    Error code.
         * @param string     $message Error message.
         * @param mixed      $data    Extra data.
         * @return void
         */
        public function add( $code, $message = '', $data = null ) {
            $this->errors[ $code ][] = $message;

            if ( null !== $data ) {
                $this->error_data[ $code ] = $data;
            }
        }

        /**
         * Every accumulated code, in insertion order.
         *
         * @return array<int, string|int>
         */
        public function get_error_codes() {
            return array_keys( $this->errors );
        }

        /**
         * First code, or '' when the error is empty.
         *
         * @return string|int
         */
        public function get_error_code() {
            $codes = $this->get_error_codes();

            return empty( $codes ) ? '' : $codes[0];
        }

        /**
         * Message for one code, or the first code's.
         *
         * @param string|int $code Error code.
         * @return string
         */
        public function get_error_message( $code = '' ) {
            if ( '' === $code ) {
                $code = $this->get_error_code();
            }

            return isset( $this->errors[ $code ] ) ? (string) $this->errors[ $code ][0] : '';
        }

        /**
         * Data for one code, or the first code's.
         *
         * @param string|int $code Error code.
         * @return mixed
         */
        public function get_error_data( $code = '' ) {
            if ( '' === $code ) {
                $code = $this->get_error_code();
            }

            return $this->error_data[ $code ] ?? null;
        }
    }
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
    /**
     * Response stand-in exposing only the payload.
     */
    final class WP_REST_Response {

        /**
         * Response payload.
         *
         * @var mixed
         */
        private $data;

        /**
         * Constructor.
         *
         * @param mixed $data    Payload.
         * @param int   $status  HTTP status (ignored).
         * @param array $headers Headers (ignored).
         */
        public function __construct( $data = null, $status = 200, $headers = array() ) {
            $this->data = $data;
        }

        /**
         * Payload accessor.
         *
         * @return mixed
         */
        public function get_data() {
            return $this->data;
        }
    }
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
    /**
     * Request stand-in: params set directly, or a JSON body that both
     * get_param() and get_json_params() read like core does after parsing.
     */
    final class WP_REST_Request {

        /**
         * Explicitly set params.
         *
         * @var array<string, mixed>
         */
        private $params = array();

        /**
         * Raw body, when set.
         *
         * @var string
         */
        private $body = '';

        /**
         * Sets one param.
         *
         * @param string $key   Param name.
         * @param mixed  $value Param value.
         * @return void
         */
        public function set_param( $key, $value ) {
            $this->params[ $key ] = $value;
        }

        /**
         * Param lookup: explicit params first, then the JSON body.
         *
         * @param string $key Param name.
         * @return mixed Null when absent.
         */
        public function get_param( $key ) {
            if ( array_key_exists( $key, $this->params ) ) {
                return $this->params[ $key ];
            }

            $json = $this->get_json_params();
            if ( is_array( $json ) && array_key_exists( $key, $json ) ) {
                return $json[ $key ];
            }

            return null;
        }

        /**
         * Sets the raw body.
         *
         * @param string $body Raw body.
         * @return void
         */
        public function set_body( $body ) {
            $this->body = (string) $body;
        }

        /**
         * Raw body accessor.
         *
         * @return string
         */
        public function get_body() {
            return $this->body;
        }

        /**
         * Decoded JSON body, or null when unset or not an object.
         *
         * @return array<string, mixed>|null
         */
        public function get_json_params() {
            if ( '' === $this->body ) {
                return null;
            }

            $decoded = json_decode( $this->body, true );

            return is_array( $decoded ) ? $decoded : null;
        }
    }
}
