<?php
/**
 * Autoloader fixture: core-module class with the Gr_ prefix.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Gr_Probe {

    /**
     * Identifies the fixture.
     *
     * @return string
     */
    public static function id(): string {
        return 'core-probe';
    }
}
