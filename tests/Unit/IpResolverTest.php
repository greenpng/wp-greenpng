<?php
/**
 * Client IP resolution under the trust policy (docs/13 C3): the four
 * acceptance scenarios plus the CIDR matcher primitive and the deeper
 * right-scan edges.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Security\Gr_Ip_Matcher;
use GreenPNG\Security\Gr_Ip_Resolver;
use PHPUnit\Framework\TestCase;

final class IpResolverTest extends TestCase {

    /**
     * $_SERVER snapshot restored per test so probe values never leak.
     *
     * @var array<string, mixed>
     */
    private array $server_backup = array();

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        $this->server_backup = $_SERVER;
    }

    protected function tearDown(): void {
        $_SERVER = $this->server_backup;
        parent::tearDown();
    }

    /**
     * Enables proxy trust with the given ranges, as the settings page will.
     *
     * @param array<int, string> $trusted Trusted CIDR list.
     * @return void
     */
    private function trust_proxies( array $trusted ): void {
        update_option(
            'gr_settings',
            array(
                'trust_proxy_headers' => 1,
                'trusted_proxies'     => $trusted,
            )
        );
    }

    public function testUnconfiguredProxyTrustIgnoresTheForwardedHeader(): void {
        $_SERVER['REMOTE_ADDR']             = '203.0.113.7';
        $_SERVER['HTTP_X_FORWARDED_FOR']    = '198.51.100.9';

        self::assertSame( '203.0.113.7', Gr_Ip_Resolver::resolve() );
        self::assertSame( '203.0.113.7', gr_get_client_ip() );
    }

    public function testTrustedProxyRightScanRecoversTheClient(): void {
        $this->trust_proxies( array( '10.0.0.0/24' ) );

        $_SERVER['REMOTE_ADDR']          = '10.0.0.2';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9, 10.0.0.2';

        self::assertSame( '198.51.100.9', Gr_Ip_Resolver::resolve() );
    }

    public function testUnlistedRemoteAddressCannotActivateTheHeader(): void {
        $this->trust_proxies( array( '10.0.0.0/24' ) );

        $_SERVER['REMOTE_ADDR']          = '203.0.113.99';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

        self::assertSame( '203.0.113.99', Gr_Ip_Resolver::resolve() );
    }

    public function testMissingOrInvalidRemoteAddressFallsBackToTheZeroAddress(): void {
        unset( $_SERVER['REMOTE_ADDR'] );
        self::assertSame( '0.0.0.0', Gr_Ip_Resolver::resolve() );

        $_SERVER['REMOTE_ADDR'] = 'not-an-ip';
        self::assertSame( '0.0.0.0', Gr_Ip_Resolver::resolve() );
    }

    public function testRightScanSkipsSeveralTrustedHops(): void {
        $this->trust_proxies( array( '10.0.0.0/24' ) );

        $_SERVER['REMOTE_ADDR']          = '10.0.0.2';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9, 10.0.0.3, 10.0.0.2';

        self::assertSame( '198.51.100.9', Gr_Ip_Resolver::resolve() );
    }

    public function testAllTrustedChainFallsBackToTheSocketAddress(): void {
        $this->trust_proxies( array( '10.0.0.0/24' ) );

        $_SERVER['REMOTE_ADDR']          = '10.0.0.2';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.5, 10.0.0.3, 10.0.0.2';

        self::assertSame( '10.0.0.2', Gr_Ip_Resolver::resolve() );
    }

    public function testBrokenForwardedChainFallsBackToTheSocketAddress(): void {
        $this->trust_proxies( array( '10.0.0.0/24' ) );

        $_SERVER['REMOTE_ADDR']          = '10.0.0.2';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9, definitely-not-an-ip';

        self::assertSame( '10.0.0.2', Gr_Ip_Resolver::resolve() );
    }

    public function testMatcherHandlesIpv4RangesIncludingPartialBytes(): void {
        self::assertTrue( Gr_Ip_Matcher::match_cidr( '10.0.0.7', '10.0.0.0/25' ) );
        self::assertFalse( Gr_Ip_Matcher::match_cidr( '10.0.0.200', '10.0.0.0/25' ) );
        self::assertTrue( Gr_Ip_Matcher::match_cidr( '203.0.113.5', '203.0.113.0/24' ) );
        self::assertFalse( Gr_Ip_Matcher::match_cidr( '203.0.114.5', '203.0.113.0/24' ) );
        self::assertTrue( Gr_Ip_Matcher::match_cidr( '1.2.3.4', '0.0.0.0/0' ) );
    }

    public function testMatcherHandlesIpv6Prefixes(): void {
        self::assertTrue( Gr_Ip_Matcher::match_cidr( '2001:db8:abcd::1', '2001:db8:abcd::/48' ) );
        self::assertTrue( Gr_Ip_Matcher::match_cidr( '2001:db8:abcd:ffff::1', '2001:db8:abcd::/48' ) );
        self::assertFalse( Gr_Ip_Matcher::match_cidr( '2001:db8:abce::1', '2001:db8:abcd::/48' ) );
        // /64 differs from /48 exactly at the free 4th hextet boundary.
        self::assertFalse( Gr_Ip_Matcher::match_cidr( '2001:db8:abcd:1::1', '2001:db8:abcd::/64' ) );
        self::assertTrue( Gr_Ip_Matcher::match_cidr( '2001:db8:abcd:0:ffff::1', '2001:db8:abcd::/64' ) );
    }

    public function testMatcherNeverIntermatchesFamilies(): void {
        self::assertFalse( Gr_Ip_Matcher::match_cidr( '1.2.3.4', '2001:db8::/32' ) );
        self::assertFalse( Gr_Ip_Matcher::match_cidr( '2001:db8::1', '1.2.3.0/24' ) );
    }

    public function testMatcherAcceptsBareIpsAsFullPrefixes(): void {
        self::assertTrue( Gr_Ip_Matcher::match_cidr( '10.0.0.2', '10.0.0.2' ) );
        self::assertFalse( Gr_Ip_Matcher::match_cidr( '10.0.0.3', '10.0.0.2' ) );
    }

    public function testMatcherRejectsGarbageWithoutWarnings(): void {
        self::assertFalse( Gr_Ip_Matcher::match_cidr( ' nonsense ', '10.0.0.0/24' ) );
        self::assertFalse( Gr_Ip_Matcher::match_cidr( '10.0.0.7', '10.0.0.0/twentyfour' ) );
        self::assertFalse( Gr_Ip_Matcher::match_cidr( '10.0.0.7', '10.0.0.0/99' ) );
    }

    public function testMatcherListFormMatchesAnyEntry(): void {
        self::assertTrue( Gr_Ip_Matcher::match( '198.51.100.9', array( '10.0.0.0/24', '198.51.100.0/24' ) ) );
        self::assertFalse( Gr_Ip_Matcher::match( '203.0.113.1', array( '10.0.0.0/24', '198.51.100.0/24' ) ) );
        self::assertFalse( Gr_Ip_Matcher::match( '203.0.113.1', array( 42, 'not-a-cidr' ) ) );
    }
}
