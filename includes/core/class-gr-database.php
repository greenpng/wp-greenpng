<?php
/**
 * Public table-name resolver (docs/02 §2.3): every query site resolves
 * names through here so no hand-spelled table-name concatenation exists
 * outside the schema DDL itself. Names are validated against the
 * DDL-derived list, so a typo throws instead of quietly querying a table
 * that does not exist.
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
 * Table-name front for all non-DDL code. Deliberately free of $wpdb: the
 * prefix lives in the storage layer (docs/02 §2.3 repository isolation)
 * and is reached through Gr_Schema.
 */
final class Gr_Database {

    /**
     * Per-request resolution cache keyed by the raw argument; the table
     * prefix cannot change within a request, so the mapping stays valid.
     *
     * @var array<string, string>
     */
    private static array $cache = array();

    /**
     * Resolves a short table key ('events') to the fully-prefixed name.
     *
     * @param string $key Short key, with or without a leading gr_.
     * @return string Fully-prefixed table name.
     * @throws \InvalidArgumentException When the key matches no schema object.
     */
    public static function table( string $key ): string {
        if ( ! isset( self::$cache[ $key ] ) ) {
            self::$cache[ $key ] = Gr_Schema::resolve_table( $key );
        }

        return self::$cache[ $key ];
    }
}
