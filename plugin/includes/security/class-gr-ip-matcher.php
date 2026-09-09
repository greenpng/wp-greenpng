<?php
/**
 * CIDR matching primitive for IP allow/trust lists (docs/10 §1): binary
 * comparison over inet_pton bytes, so IPv4 uses exact bit masks and IPv6
 * prefix matching falls out of the same loop. Also accepts bare IPs as
 * /32 or /128, which is what real allow-list entries look like.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tells whether an address falls inside CIDR ranges; used by the IP
 * resolver now and by the Phase 3 rule engine through gr_match_cidr().
 */
final class Gr_Ip_Matcher {

    /**
     * Whether the address matches any entry of the list.
     *
     * @param string             $ip    Candidate address.
     * @param array<int, string> $cidrs CIDR strings or bare IPs.
     * @return bool
     */
    public static function match( string $ip, array $cidrs ): bool {
        foreach ( $cidrs as $cidr ) {
            if ( is_string( $cidr ) && self::match_cidr( $ip, $cidr ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the address falls inside one CIDR (or equals one bare IP).
     *
     * @param string $ip   Candidate address.
     * @param string $cidr CIDR ('a.b.c.d/nn', 'x::/nn') or bare IP.
     * @return bool
     */
    public static function match_cidr( string $ip, string $cidr ): bool {
        $cidr  = trim( $cidr );
        $slash = strpos( $cidr, '/' );
        if ( false === $slash ) {
            $net    = $cidr;
            $prefix = null;
        } else {
            $net = trim( substr( $cidr, 0, $slash ) );
            $raw = trim( substr( $cidr, (int) $slash + 1 ) );
            if ( ! ctype_digit( $raw ) ) {
                return false;
            }
            $prefix = (int) $raw;
        }

        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) || ! filter_var( $net, FILTER_VALIDATE_IP ) ) {
            return false;
        }

        $ip_bin  = inet_pton( $ip );
        $net_bin = inet_pton( $net );

        $width = strlen( (string) $ip_bin );
        if ( $width !== strlen( (string) $net_bin ) ) {
            return false; // v4 and v6 never intermatch.
        }

        if ( null === $prefix ) {
            return $ip_bin === $net_bin; // bare IP entry.
        }

        if ( $prefix < 0 || $prefix > $width * 8 ) {
            return false;
        }

        $whole = intdiv( $prefix, 8 );
        for ( $byte = 0; $byte < $whole; $byte++ ) {
            if ( $ip_bin[ $byte ] !== $net_bin[ $byte ] ) {
                return false;
            }
        }

        $rest = $prefix % 8;
        if ( 0 !== $rest ) {
            $mask = 0xFF << ( 8 - $rest ) & 0xFF;
            if ( ( ord( $ip_bin[ $whole ] ) & $mask ) !== ( ord( $net_bin[ $whole ] ) & $mask ) ) {
                return false;
            }
        }

        return true;
    }
}
