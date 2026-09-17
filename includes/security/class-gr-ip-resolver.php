<?php
/**
 * Client IP resolution (docs/10 §1, fixing reference flaw S1): proxy
 * headers are client-forgeable, so by default only REMOTE_ADDR is
 * trusted. Forwarded headers are read at all only when the site owner has
 * enabled proxy trust AND the direct peer is inside the configured
 * trusted ranges; even then the XFF chain is walked right-to-left so the
 * first address our trusted proxy did not vouch for wins.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Single source of client-IP truth for security logging, rate limits, and
 * bans; every consumer goes through gr_get_client_ip().
 */
final class Gr_Ip_Resolver {

    /**
     * Resolves the client IP under the trust policy (docs/10 §1):
     * REMOTE_ADDR alone unless proxy trust is enabled and the direct peer
     * is a configured trusted proxy, in which case the XFF chain is
     * right-scanned past trusted hops.
     *
     * @return string Validated IP, or '0.0.0.0' when nothing valid exists.
     */
    public static function resolve(): string {
        $remote = filter_var(
            wp_unslash( isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '' ),
            FILTER_VALIDATE_IP
        );
        if ( false === $remote ) {
            return '0.0.0.0';
        }

        $settings = gr()->settings();
        if ( 1 !== (int) $settings->get( 'trust_proxy_headers' ) ) {
            return $remote;
        }

        $trusted = $settings->get( 'trusted_proxies' );
        if ( ! is_array( $trusted ) ) {
            return $remote;
        }

        if ( ! Gr_Ip_Matcher::match( $remote, $trusted ) ) {
            return $remote;
        }

        return self::walk_forwarded_for( $remote, $trusted );
    }

    /**
     * Right-scan of X-Forwarded-For: each proxy appends the address it
     * saw, so the rightmost hops are proxy-vouched; the first untrusted
     * hop from the right is the real client. A hop that is not a valid IP
     * ends the scan (the header is lying at that point) and an
     * all-trusted chain falls back to the socket value — both return
     * REMOTE_ADDR, the one address that cannot be forged.
     *
     * @param string             $remote  Direct peer address.
     * @param array<int, string> $trusted Trusted CIDR list.
     * @return string
     */
    private static function walk_forwarded_for( string $remote, array $trusted ): string {
        if ( ! isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            return $remote;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitizing the whole header would corrupt the comma-separated chain; every hop is FILTER_VALIDATE_IP-gated immediately below.
        $header = (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] );
        if ( '' === trim( $header ) ) {
            return $remote;
        }

        $hops = array_map( 'trim', explode( ',', $header ) );

        for ( $i = count( $hops ) - 1; $i >= 0; $i-- ) {
            $hop = (string) $hops[ $i ];

            if ( ! filter_var( $hop, FILTER_VALIDATE_IP ) ) {
                return $remote;
            }

            if ( ! Gr_Ip_Matcher::match( $hop, $trusted ) ) {
                return $hop;
            }
        }

        return $remote;
    }
}
