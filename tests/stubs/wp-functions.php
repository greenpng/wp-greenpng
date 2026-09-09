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
     * Resets the stub store between tests.
     *
     * @return void
     */
    function gr_stub_reset_options(): void {
        $GLOBALS['gr_stub_options'] = array(
            'data'     => array(),
            'autoload' => array(),
        );
    }
}
