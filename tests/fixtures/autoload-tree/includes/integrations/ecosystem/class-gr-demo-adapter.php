<?php
/**
 * Autoloader fixture: deep module path under a sub-namespace.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Integrations\Ecosystem;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Gr_Demo_Adapter {

    /**
     * Identifies the fixture.
     *
     * @return string
     */
    public static function id(): string {
        return 'ecosystem-demo-adapter';
    }
}
