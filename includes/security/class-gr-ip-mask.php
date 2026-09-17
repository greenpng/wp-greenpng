<?php
/**
 * Display-side address masking (ADR-0007 IP dual-track): storage keeps
 * the full binary by default, every human-facing render masks the last
 * segment. Presentation only — the masked form is never stored back or
 * fed to matching.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One pure function; no state, no storage coupling.
 */
final class Gr_Ip_Mask {

    /**
     * Masks the last segment of a textual address.
     *
     * IPv4 loses its final octet ('203.0.113.*'), IPv6 its final group
     * ('2001:db8::1' -> '2001:db8::*'). A canonical tail '::' (digits the
     * textual form already elided, e.g. a /48-truncated network) keeps a
     * marker so the reader sees something was hidden. Unparseable input
     * returns an empty string — a display layer never echoes an address
     * it cannot verify.
     *
     * @param string $ip Textual address (usually the inet_ntop of a stored binary).
     * @return string Masked form, or '' when the input is not an address.
     */
    public static function mask( string $ip ): string {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return '';
        }

        $packed = inet_pton( $ip );
        $text   = inet_ntop( $packed );

        if ( 4 === strlen( (string) $packed ) ) {
            $parts    = explode( '.', $text );
            $parts[3] = '*';

            return implode( '.', $parts );
        }

        // Compressed tail: the last group was elided by the canonical
        // form itself; append the marker instead of mangling '::'.
        if ( '::' === substr( $text, -2 ) ) {
            return rtrim( $text, ':' ) . '::*';
        }

        $cut = strrpos( $text, ':' );
        if ( false === $cut ) {
            return '';
        }

        return substr( $text, 0, $cut + 1 ) . '*';
    }
}
