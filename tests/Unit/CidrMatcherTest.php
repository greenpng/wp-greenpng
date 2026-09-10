<?php
/**
 * CIDR matching acceptance (docs/13 W3, docs/11 §3.1): the boundary
 * spread for both families — /32 exact, /0 everything, /24 crossing,
 * partial-byte masks, IPv6 prefixes, and the facade.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Security\Gr_Ip_Matcher;
use PHPUnit\Framework\TestCase;

final class CidrMatcherTest extends TestCase {

    public function testIPv4ExactHostBoundary(): void {
        // /32: the whole address is the network.
        $this->assertTrue( Gr_Ip_Matcher::match_cidr( '203.0.113.7', '203.0.113.7/32' ) );
        $this->assertFalse( Gr_Ip_Matcher::match_cidr( '203.0.113.8', '203.0.113.7/32' ) );
        $this->assertFalse( Gr_Ip_Matcher::match_cidr( '203.0.113.6', '203.0.113.7/32' ) );

        // A bare IP entry is the same /32 without the digits.
        $this->assertTrue( Gr_Ip_Matcher::match_cidr( '203.0.113.7', '203.0.113.7' ) );
        $this->assertFalse( Gr_Ip_Matcher::match_cidr( '203.0.113.8', '203.0.113.7' ) );
    }

    public function testIPv4ZeroPrefixMatchesEverything(): void {
        $this->assertTrue( Gr_Ip_Matcher::match_cidr( '203.0.113.7', '0.0.0.0/0' ) );
        $this->assertTrue( Gr_Ip_Matcher::match_cidr( '10.0.0.1', '0.0.0.0/0' ) );
        $this->assertTrue( Gr_Ip_Matcher::match_cidr( '255.255.255.255', '0.0.0.0/0' ) );
        $this->assertTrue( Gr_Ip_Matcher::match_cidr( '0.0.0.0', '0.0.0.0/0' ) );
    }

    public function testIPv4SlashTwentyFourCrossesAtTheThirdOctet(): void {
        // Last address inside the /24, first address outside it.
        $this->assertTrue( Gr_Ip_Matcher::match_cidr( '203.0.113.255', '203.0.113.0/24' ) );
        $this->assertTrue( Gr_Ip_Matcher::match_cidr( '203.0.113.0', '203.0.113.0/24' ) );
        $this->assertFalse( Gr_Ip_Matcher::match_cidr( '203.0.114.0', '203.0.113.0/24' ) );
        $this->assertFalse( Gr_Ip_Matcher::match_cidr( '203.0.112.255', '203.0.113.0/24' ) );

        // Host bits in the network half are tolerated, matching the
        // lenient reading real allow-list entries produce.
        $this->assertTrue( Gr_Ip_Matcher::match_cidr( '203.0.113.9', '203.0.113.99/24' ) );
    }

    public function testIPv4PartialByteMasks(): void {
        // /25: the boundary falls inside the fourth octet.
        $this->assertTrue( Gr_Ip_Matcher::match_cidr( '10.0.0.127', '10.0.0.0/25' ) );
        $this->assertFalse( Gr_Ip_Matcher::match_cidr( '10.0.0.128', '10.0.0.0/25' ) );
        $this->assertTrue( Gr_Ip_Matcher::match_cidr( '10.0.0.128', '10.0.0.128/25' ) );
        $this->assertFalse( Gr_Ip_Matcher::match_cidr( '10.0.0.127', '10.0.0.128/25' ) );

        // /31: a two-address network.
        $this->assertTrue( Gr_Ip_Matcher::match_cidr( '10.0.0.4', '10.0.0.4/31' ) );
        $this->assertTrue( Gr_Ip_Matcher::match_cidr( '10.0.0.5', '10.0.0.4/31' ) );
        $this->assertFalse( Gr_Ip_Matcher::match_cidr( '10.0.0.6', '10.0.0.4/31' ) );
    }

    public function testIPv6PrefixBoundaries(): void {
        // /128: exact address, written in both compressed and full form.
        $this->assertTrue( Gr_Ip_Matcher::match_cidr( '2001:db8::1', '2001:db8::1/128' ) );
        $this->assertTrue(
            Gr_Ip_Matcher::match_cidr(
                '2001:0db8:0000:0000:0000:0000:0000:0001',
                '2001:db8::1/128'
            )
        );
        $this->assertFalse( Gr_Ip_Matcher::match_cidr( '2001:db8::2', '2001:db8::1/128' ) );

        // /0: every IPv6 address.
        $this->assertTrue( Gr_Ip_Matcher::match_cidr( '2001:db8::5', '::/0' ) );
        $this->assertTrue( Gr_Ip_Matcher::match_cidr( '::1', '::/0' ) );

        // /48: the last hextet boundary stays inside.
        $this->assertTrue( Gr_Ip_Matcher::match_cidr( '2001:db8:abcd:ffff::1', '2001:db8:abcd::/48' ) );
        $this->assertFalse( Gr_Ip_Matcher::match_cidr( '2001:db8:abce::1', '2001:db8:abcd::/48' ) );

        // /64: whole-byte boundary at the interface identifier.
        $this->assertTrue( Gr_Ip_Matcher::match_cidr( '2001:db8:abcd:12ff:ffff::1', '2001:db8:abcd:12ff::/64' ) );
        $this->assertFalse( Gr_Ip_Matcher::match_cidr( '2001:db8:abcd:1300::1', '2001:db8:abcd:12ff::/64' ) );

        // /52: nibble boundary inside the seventh byte.
        $this->assertTrue( Gr_Ip_Matcher::match_cidr( '2001:db8:abcd:0fff::', '2001:db8:abcd::/52' ) );
        $this->assertFalse( Gr_Ip_Matcher::match_cidr( '2001:db8:abcd:1000::', '2001:db8:abcd::/52' ) );

        // /33: one bit into the fifth byte.
        $this->assertTrue( Gr_Ip_Matcher::match_cidr( '2001:db8:8000::', '2001:db8:8000::/33' ) );
        $this->assertFalse( Gr_Ip_Matcher::match_cidr( '2001:db8:0fff::', '2001:db8:8000::/33' ) );
    }

    public function testTheTwoFamiliesNeverIntermatch(): void {
        // Even the v4-mapped spelling is a 16-byte address, not a v4.
        $this->assertFalse( Gr_Ip_Matcher::match_cidr( '10.0.0.7', '::ffff:a00:7/128' ) );
        $this->assertFalse( Gr_Ip_Matcher::match_cidr( '10.0.0.7', '::/0' ) );
        $this->assertFalse( Gr_Ip_Matcher::match_cidr( '2001:db8::1', '0.0.0.0/0' ) );
        $this->assertFalse( Gr_Ip_Matcher::match_cidr( '2001:db8::1', '10.0.0.7' ) );
    }

    public function testMalformedInputNeverMatches(): void {
        $bad = array(
            array( 'not-an-ip', '10.0.0.0/24' ),
            array( '999.1.1.1', '10.0.0.0/24' ),
            array( '10.0.0.1', 'not-an-ip/24' ),
            array( '10.0.0.1', '10.0.0.0/abc' ),
            array( '10.0.0.1', '10.0.0.0/-1' ),
            array( '10.0.0.1', '10.0.0.0/33' ),
            array( '2001:db8::1', '2001:db8::/129' ),
            array( '', '' ),
        );

        foreach ( $bad as $case ) {
            $this->assertFalse(
                Gr_Ip_Matcher::match_cidr( $case[0], $case[1] ),
                "malformed pair must not match: {$case[0]} in {$case[1]}"
            );
        }
    }

    public function testListMatcherStopsAtFirstHitAndSkipsNoise(): void {
        $this->assertTrue(
            Gr_Ip_Matcher::match( '203.0.113.7', array( 'not a string', '10.0.0.0/8', '203.0.113.0/24' ) )
        );
        $this->assertTrue( Gr_Ip_Matcher::match( '203.0.113.7', array( '203.0.113.0/24' ) ) );
        $this->assertFalse( Gr_Ip_Matcher::match( '203.0.113.7', array( '10.0.0.0/8' ) ) );
        $this->assertFalse( Gr_Ip_Matcher::match( '203.0.113.7', array() ) );
    }

    public function testFacadeMatchesTheClass(): void {
        $this->assertTrue( gr_match_cidr( '203.0.113.7', '203.0.113.0/24' ) );
        $this->assertFalse( gr_match_cidr( '203.0.114.0', '203.0.113.0/24' ) );
        $this->assertTrue( gr_match_cidr( '2001:db8:abcd:ffff::1', '2001:db8:abcd::/48' ) );
        $this->assertFalse( gr_match_cidr( '2001:db8:abce::1', '2001:db8:abcd::/48' ) );
    }
}
