<?php
/**
 * Activation routine: the only sanctioned place for first-install side
 * effects (iron rule 3). Every step is idempotent so re-activation never
 * resets owner choices or rewrites matching tables; on multisite each
 * site's own activation runs against that site's prefix (docs/05 §7).
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Storage\Gr_Schema;

/**
 * Bound to register_activation_hook in the plugin entry file; runs in
 * admin or CLI context, never on a front-end request.
 */
final class Gr_Activator {

    /**
     * Installs the settings option and the physical schema.
     *
     * @return void
     */
    public static function activate(): void {
        Gr_Settings::install();
        Gr_Schema::install();
    }
}
