<?php
/**
 * Display mask (docs/13 W14, ADR-0007 dual-track): the last segment of
 * every address a human sees is hidden, the stored form never changes.
 * The two storage modes are separate concerns (SecurityLogTest covers
 * them); these tests pin the presentation contract: family shapes, the
 * compressed-tail marker, the never-echo-unparseable rule, and the
 * binary round-trip flow the admin list will use.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Security\Gr_Ip_Mask;
use PHPUnit\Framework\TestCase;

final class IpMaskTest extends TestCase {

    public function testIpv4LosesItsLastOctet(): void {
        $this->assertSame( '203.0.113.*', Gr_Ip_Mask::mask( '203.0.113.99' ) );
        $this->assertSame( '127.0.0.*', Gr_Ip_Mask::mask( '127.0.0.1' ) );
    }

    public function testIpv6LosesItsLastGroup(): void {
        $this->assertSame( '2001:db8:abcd:1234::*', Gr_Ip_Mask::mask( '2001:db8:abcd:1234::1' ) );
        $this->assertSame( '2001:db8:1:2:3:4:5:*', Gr_Ip_Mask::mask( '2001:db8:1:2:3:4:5:6' ) );
    }

    public function testCompressedTailKeepsAMarker(): void {
        // A /48-truncated network read back from storage: the canonical
        // form already elided the tail; the reader still sees that.
        $this->assertSame( '2001:db8:abcd::*', Gr_Ip_Mask::mask( '2001:db8:abcd::' ) );
        $this->assertSame( '::*', Gr_Ip_Mask::mask( '::' ) );
    }

    public function testIpv4MappedIpv6MasksTheEmbeddedTail(): void {
        $this->assertSame( '::ffff:*', Gr_Ip_Mask::mask( '::ffff:203.0.113.99' ) );
    }

    public function testUnparseableInputYieldsNothing(): void {
        // A display layer never echoes an address it cannot verify —
        // the empty string is the safe answer, not the raw input.
        $this->assertSame( '', Gr_Ip_Mask::mask( 'not-an-address' ) );
        $this->assertSame( '', Gr_Ip_Mask::mask( '' ) );
        $this->assertSame( '', Gr_Ip_Mask::mask( '999.1.1.1' ) );
        $this->assertSame( '', Gr_Ip_Mask::mask( '203.0.113' ) );
    }

    public function testMaskedFormNeverLeavesTheDisplayLayer(): void {
        // The admin list flow: binary column -> inet_ntop -> mask.
        // The masked form must not survive a round-trip back into the
        // storage/matching world.
        $masked = Gr_Ip_Mask::mask( '203.0.113.99' );

        $this->assertSame( '203.0.113.*', $masked );
        $this->assertSame( '', Gr_Ip_Mask::mask( $masked ) );
        $this->assertFalse( filter_var( $masked, FILTER_VALIDATE_IP ) );
    }

    public function testStorageBinaryRoundTripsIntoTheMask(): void {
        // The exact pipeline the U-phase list will run on gr_security_logs.ip.
        $packed = inet_pton( '203.0.113.99' );
        $masked = Gr_Ip_Mask::mask( (string) inet_ntop( $packed ) );

        $this->assertSame( '203.0.113.*', $masked );

        $packed6 = inet_pton( '2001:db8:abcd:1234::1' );
        $masked6 = Gr_Ip_Mask::mask( (string) inet_ntop( $packed6 ) );

        $this->assertSame( '2001:db8:abcd:1234::*', $masked6 );
    }
}
