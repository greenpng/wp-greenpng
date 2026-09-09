<?php
/**
 * Autoloader fixture: underscore class name mapping to a hyphenated slug.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Gr_Probe_Two {

    /**
     * Identifies the fixture.
     *
     * @return string
     */
    public static function id(): string {
        return 'core-probe-two';
    }
}
