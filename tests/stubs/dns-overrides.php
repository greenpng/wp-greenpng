<?php
/**
 * Namespaced DNS overrides for unit tests. gethostbyaddr() and
 * dns_get_record() are PHP built-ins, so they cannot be redefined in
 * the global namespace; same-namespace overrides are found first by
 * the unqualified calls from GreenPNG\Security code, which is exactly
 * the surface that resolves crawler claims. Production resolves the
 * real functions. State lives in $GLOBALS['gr_stub_dns'].
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Security;

if ( ! function_exists( 'GreenPNG\Security\gethostbyaddr' ) ) {

    /**
     * PTR stand-in: reads gr_stub_dns['ptr'][ip]; an unconfigured
     * address returns the input unchanged, exactly like a failed
     * lookup in production.
     *
     * @param string $ip Address to reverse.
     * @return string
     */
    function gethostbyaddr( $ip ) {
        $GLOBALS['gr_stub_dns']['calls'][] = array( 'ptr', (string) $ip );

        return $GLOBALS['gr_stub_dns']['ptr'][ (string) $ip ] ?? (string) $ip;
    }
}

if ( ! function_exists( 'GreenPNG\Security\dns_get_record' ) ) {

    /**
     * Forward-lookup stand-in: reads gr_stub_dns['forward'][type][host]
     * and returns record arrays the engine reads 'ip'/'ipv6' from; the
     * 'disabled' knob makes every family fail like a host without the
     * resolver extension.
     *
     * @param string $host Hostname to resolve.
     * @param int    $type DNS_* family.
     * @return array<int, array<string, mixed>>|false
     */
    function dns_get_record( $host, $type = \DNS_ANY ) {
        if ( ! empty( $GLOBALS['gr_stub_dns']['disabled'] ) ) {
            return false;
        }

        $GLOBALS['gr_stub_dns']['calls'][] = array( (int) $type, (string) $host );

        return $GLOBALS['gr_stub_dns']['forward'][ (int) $type ][ (string) $host ] ?? false;
    }
}
