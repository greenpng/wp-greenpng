<?php
/**
 * Fixed-window rate limiter for public write surfaces (docs/02 §2.5):
 * object-cache counters where the host has one (atomic incr, zero SQL),
 * per-key transients otherwise (docs/09: scattered short-TTL keys, never
 * one shared array). Blocked requests on the transient path stop after
 * the read, so floods do not multiply option writes.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Per-key counters with a shared cache-group discipline.
 */
final class Gr_Rate_Limiter {

    /**
     * Whether one more hit is allowed in the current window.
     *
     * @param string $bucket Bucket name, e.g. 'collect'.
     * @param string $key    Caller key, usually the client IP.
     * @param int    $limit  Allowed hits per window.
     * @param int    $window Window seconds.
     * @return bool
     */
    public static function allowed( string $bucket, string $key, int $limit, int $window ): bool {
        $limit  = max( 1, $limit );
        $window = max( 10, $window );
        $name   = 'gr_rl_' . $bucket . '_' . md5( $key );

        if ( wp_using_ext_object_cache() ) {
            $count = wp_cache_get( $name, 'greenpng' );
            if ( false === $count ) {
                wp_cache_add( $name, 1, 'greenpng', $window );

                return true;
            }

            return (int) wp_cache_incr( $name, 1, 'greenpng' ) <= $limit;
        }

        $count = (int) get_transient( $name );
        if ( $count >= $limit ) {
            return false;
        }

        set_transient( $name, $count + 1, $window );

        return true;
    }
}
