<?php
/**
 * WP-CLI stand-in: records command registrations and the outcome
 * messages, and simulates error()'s process halt with an exception so
 * tests can assert the failure path without ending the run.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_CLI' ) ) {
    define( 'WP_CLI', true );
}

if ( ! isset( $GLOBALS['gr_stub_cli_commands'] ) ) {
    $GLOBALS['gr_stub_cli_commands'] = array();
}
if ( ! isset( $GLOBALS['gr_stub_cli_messages'] ) ) {
    $GLOBALS['gr_stub_cli_messages'] = array(
        'success' => array(),
        'warning' => array(),
        'error'   => array(),
    );
}

if ( ! class_exists( 'Gr_Cli_Error_Halt' ) ) {
    /**
     * Thrown by the stub's error() to stand in for the real exit.
     */
    final class Gr_Cli_Error_Halt extends \Exception {
    }
}

if ( ! class_exists( 'WP_CLI' ) ) {
    /**
     * WP-CLI surface recorder.
     */
    final class WP_CLI {

        /**
         * Registers a command name to callable.
         *
         * @param string $name     Command name.
         * @param mixed  $callable Class or callable behind it.
         * @return bool
         */
        public static function add_command( $name, $callable ) {
            $GLOBALS['gr_stub_cli_commands'][ (string) $name ] = $callable;

            return true;
        }

        /**
         * Records a success message.
         *
         * @param string $message Text.
         * @return void
         */
        public static function success( $message ) {
            $GLOBALS['gr_stub_cli_messages']['success'][] = (string) $message;
        }

        /**
         * Records a warning message.
         *
         * @param string $message Text.
         * @return void
         */
        public static function warning( $message ) {
            $GLOBALS['gr_stub_cli_messages']['warning'][] = (string) $message;
        }

        /**
         * Records an error message; the default exit behavior is
         * simulated by throwing, which callers may catch to assert.
         *
         * @param string $message Text.
         * @param bool   $exit    Whether the real command would halt.
         * @return void
         * @throws Gr_Cli_Error_Halt When $exit is true.
         */
        public static function error( $message, $exit = true ) {
            $GLOBALS['gr_stub_cli_messages']['error'][] = (string) $message;

            if ( $exit ) {
                throw new Gr_Cli_Error_Halt( (string) $message );
            }
        }
    }
}
