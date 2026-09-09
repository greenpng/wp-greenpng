<?php
/**
 * Namespaced function overrides for unit tests. setcookie() is a PHP
 * built-in, so it cannot be redefined in the global namespace; a
 * same-namespace override is found first by unqualified calls from
 * GreenPNG\Attribution code, which is exactly the surface that sends
 * cookies. Production resolves the real setcookie().
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Attribution;

if ( ! function_exists( 'GreenPNG\Attribution\setcookie' ) ) {

    /**
     * Cookie send stand-in: records name/value/options, never emits.
     *
     * @param string               $name    Cookie name.
     * @param string               $value   Cookie value.
     * @param array<string, mixed> $options Cookie options.
     * @return bool
     */
    function setcookie( $name, $value = '', $options = array() ) {
        $GLOBALS['gr_stub_cookies'][] = array(
            'name'    => $name,
            'value'   => $value,
            'options' => $options,
        );

        return true;
    }
}
