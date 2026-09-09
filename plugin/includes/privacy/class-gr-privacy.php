<?php
/**
 * Marketing-track IP anonymization (ADR-0005 §2): IPv4 truncated to /24
 * and IPv6 to /48 before any marketing storage or hashing. The security
 * track reuses the same primitive when its own anonymize toggle is on
 * (docs/05 §3.1).
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Privacy;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Address truncation for the privacy dual-rail.
 */
final class Gr_Privacy {

    /**
     * Truncates one address: IPv4 to /24, IPv6 to /48. Anything that is
     * not a valid address passes through unchanged so callers keep
     * deterministic behavior.
     *
     * @param string $ip Address, e.g. from gr_get_client_ip().
     * @return string
     */
    public static function anonymize_ip( string $ip ): string {
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            $parts    = explode( '.', $ip );
            $parts[3] = '0';

            return implode( '.', $parts );
        }

        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
            $binary = inet_pton( $ip );
            $masked = substr( (string) $binary, 0, 6 ) . str_repeat( "\0", 10 );

            return (string) inet_ntop( $masked );
        }

        return $ip;
    }
}
