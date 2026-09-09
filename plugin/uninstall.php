<?php
/**
 * WordPress uninstall entry point. WordPress loads this file with the
 * plugin inactive and no autoloader registered, so the classes it needs
 * are required directly and in dependency order. Data is kept by default
 * (docs/05 §5).
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once __DIR__ . '/includes/storage/class-gr-schema.php';
require_once __DIR__ . '/includes/storage/class-gr-uninstall.php';

GreenPNG\Storage\Gr_Uninstall::run();
