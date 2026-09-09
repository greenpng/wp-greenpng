<?php
/**
 * Runtime class loader for the GreenPNG root namespace.
 *
 * Maps GreenPNG\<Module>(\<Sub>)…<Class> to
 * includes/<module>(/<sub>)…/class-gr-<slug>.php (docs/04 §2.1), where the
 * slug is the class name without its Gr_ prefix, lowercased, underscores as
 * hyphens. Hand-written because the plugin ships with zero Composer runtime
 * dependencies (ADR-0003).
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SPL autoloader for the GreenPNG root namespace.
 *
 * Being silent on unresolvable names keeps it chain-safe alongside
 * autoloaders registered by the site or other plugins.
 */
final class Gr_Autoloader {

    /**
     * Loads a GreenPNG class when the mapped file exists; anything outside
     * the GreenPNG root namespace is left to the rest of the SPL chain.
     *
     * @param string $class_name Fully qualified class name as passed by SPL.
     * @param string $base_dir   Base dir overriding GR_PLUGIN_DIR; exists so
     *                            tests can point the loader at a fixture tree.
     * @return void
     */
    public static function load( string $class_name, string $base_dir = '' ): void {
        $file = self::resolve_file( $class_name, $base_dir );
        if ( null === $file ) {
            return;
        }

        require_once $file;
    }

    /**
     * Resolves a fully qualified class name to its file path.
     *
     * @param string $class_name Fully qualified class name.
     * @param string $base_dir   Base dir overriding GR_PLUGIN_DIR.
     * @return string|null Readable path, or null when the name is outside the
     *                     plugin, malformed, or maps to no file.
     */
    private static function resolve_file( string $class_name, string $base_dir = '' ): ?string {
        if ( '' === $base_dir ) {
            if ( ! defined( 'GR_PLUGIN_DIR' ) ) {
                return null;
            }
            $base_dir = (string) constant( 'GR_PLUGIN_DIR' );
        }

        $parts = explode( '\\', ltrim( $class_name, '\\' ) );
        if ( count( $parts ) < 3 ) {
            return null;
        }

        if ( 'greenpng' !== strtolower( (string) $parts[0] ) ) {
            return null;
        }

        $class = (string) array_pop( $parts );
        array_shift( $parts );

        $slug = self::slug( $class );
        if ( null === $slug ) {
            return null;
        }

        $segments = array();
        foreach ( $parts as $namespace_segment ) {
            $directory = strtolower( str_replace( '_', '-', (string) $namespace_segment ) );
            if ( preg_match( '/^[a-z0-9-]+$/', $directory ) !== 1 ) {
                return null;
            }
            $segments[] = $directory;
        }

        $relative = 'includes/' . implode( '/', $segments ) . '/class-gr-' . $slug . '.php';
        $path     = rtrim( $base_dir, '/\\' ) . '/' . $relative;

        return is_readable( $path ) ? $path : null;
    }

    /**
     * Builds the file slug for a class name: strips the Gr_/GR_ prefix,
     * lowercases, and turns underscores into hyphens.
     *
     * @param string $name Class name without namespace.
     * @return string|null Slug, or null on unexpected characters.
     */
    private static function slug( string $name ): ?string {
        $stripped = (string) preg_replace( '/^gr_/i', '', $name );
        $slug     = strtolower( str_replace( '_', '-', $stripped ) );

        return ( preg_match( '/^[a-z0-9-]+$/', $slug ) === 1 ) ? $slug : null;
    }
}
