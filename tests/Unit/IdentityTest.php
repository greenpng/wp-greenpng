<?php
/**
 * Dual-track identity (docs/13 C5): signed-cookie track, daily-fallback
 * track, cookie issuing under consent, and the consent/anonymization
 * primitives behind them.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Attribution\Gr_Identity;
use GreenPNG\Core\Gr_Settings;
use GreenPNG\Privacy\Gr_Consent;
use GreenPNG\Privacy\Gr_Privacy;
use PHPUnit\Framework\TestCase;

final class IdentityTest extends TestCase {

    /**
     * Superglobal snapshots restored per test.
     *
     * @var array<string, mixed>
     */
    private array $cookie_backup = array();

    /**
     * @var array<string, mixed>
     */
    private array $server_backup = array();

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        $this->cookie_backup = $_COOKIE;
        $this->server_backup = $_SERVER;
    }

    protected function tearDown(): void {
        $_COOKIE = $this->cookie_backup;
        $_SERVER = $this->server_backup;
        parent::tearDown();
    }

    /**
     * Fresh identity service over the stub stores.
     *
     * @return Gr_Identity
     */
    private function identity(): Gr_Identity {
        return new Gr_Identity( new Gr_Settings() );
    }

    public function testConsentFollowsTheConsentApiWhenPresent(): void {
        $GLOBALS['gr_stub_consent']['marketing'] = true;
        self::assertTrue( Gr_Consent::allows( 'marketing' ) );

        $GLOBALS['gr_stub_consent']['marketing'] = false;
        self::assertFalse( Gr_Consent::allows( 'marketing' ) );
    }

    public function testDntAndSecGpcOverrideStoredConsent(): void {
        $GLOBALS['gr_stub_consent']['marketing'] = true;

        $_SERVER['HTTP_DNT'] = '1';
        self::assertFalse( Gr_Consent::allows( 'marketing' ) );
        unset( $_SERVER['HTTP_DNT'] );

        $_SERVER['HTTP_SEC_GPC'] = '1';
        self::assertFalse( Gr_Consent::allows( 'marketing' ) );
        unset( $_SERVER['HTTP_SEC_GPC'] );

        $_SERVER['HTTP_DNT'] = '0';
        self::assertTrue( Gr_Consent::allows( 'marketing' ) );
    }

    public function testAnonymizeIpTruncatesToSlash24AndSlash48(): void {
        self::assertSame( '203.0.113.0', Gr_Privacy::anonymize_ip( '203.0.113.7' ) );
        self::assertSame( '2001:db8:abcd::', Gr_Privacy::anonymize_ip( '2001:db8:abcd:1:2:3:4:5' ) );
        self::assertSame( 'not-an-ip', Gr_Privacy::anonymize_ip( 'not-an-ip' ) );
    }

    public function testCookieTrackReturnsTheVerifiedVisitorId(): void {
        $visitor_id = str_repeat( 'ab', 16 );

        $_COOKIE[ Gr_Identity::COOKIE ] = Gr_Identity::cookie_value( $visitor_id );

        $identity = $this->identity();
        self::assertSame( $visitor_id, $identity->visitor_id() );
        self::assertSame( $visitor_id, $identity->visitor_id() );
        self::assertTrue( $identity->has_cookie_identity() );
    }

    public function testTamperedOrMalformedCookiesFallBack(): void {
        $visitor_id = str_repeat( 'ab', 16 );
        $valid      = Gr_Identity::cookie_value( $visitor_id );

        $tampered = substr( $valid, 0, -1 ) . ( '0' === $valid[ -1 ] ? '1' : '0' );

        foreach ( array( $tampered, 'no-dot-here', 'zz.' . str_repeat( 'c', 64 ), $valid . 'x' ) as $bad ) {
            $_COOKIE[ Gr_Identity::COOKIE ] = $bad;
            $identity = $this->identity();

            self::assertFalse( $identity->has_cookie_identity(), "cookie accepted: {$bad}" );
            self::assertNotSame( $visitor_id, $identity->visitor_id() );
        }
    }

    public function testSessionCookieWinsWhenWellFormed(): void {
        $_COOKIE[ Gr_Identity::SESSION_COOKIE ] = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';

        self::assertSame( 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d', $this->identity()->session_id() );
    }

    public function testFallbackTrackIsStableWithinTheDayAndDomainSeparated(): void {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_USER_AGENT'] = 'TestUA/1.0';

        $identity  = $this->identity();
        $visitor_a = $identity->visitor_id();
        $visitor_b = $identity->visitor_id();
        $session   = $identity->session_id();

        self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $visitor_a );
        self::assertSame( $visitor_a, $visitor_b );
        self::assertMatchesRegularExpression( '/^[0-9a-f]{36}$/', $session );
        self::assertNotSame( $visitor_a, $session );
        self::assertFalse( $identity->has_cookie_identity() );
    }

    public function testFallbackRotatesAcrossDays(): void {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_USER_AGENT'] = 'TestUA/1.0';

        $GLOBALS['gr_stub_now'] = '2026-09-10 12:00:00';
        $day_one = $this->identity()->visitor_id();

        $GLOBALS['gr_stub_now'] = '2026-09-11 12:00:00';
        $day_two = $this->identity()->visitor_id();

        self::assertNotSame( $day_one, $day_two );
    }

    public function testFallbackHashesTheAnonymizedAddressByDefault(): void {
        $_SERVER['HTTP_USER_AGENT'] = 'TestUA/1.0';

        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $first = $this->identity()->visitor_id();

        // Same /24, different host: identical fallback identity.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.99';
        self::assertSame( $first, $this->identity()->visitor_id() );

        // Different /24: different identity.
        $_SERVER['REMOTE_ADDR'] = '203.0.114.7';
        self::assertNotSame( $first, $this->identity()->visitor_id() );

        // Anonymization off: the host octet now matters.
        $settings = new Gr_Settings();
        $settings->set( 'marketing_ip_anonymize', 0 );
        $identity = new Gr_Identity( $settings );

        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $raw_first = $identity->visitor_id();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.99';
        self::assertNotSame( $raw_first, $identity->visitor_id() );
    }

    public function testFallbackIncludesTheUserAgent(): void {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        $_SERVER['HTTP_USER_AGENT'] = 'TestUA/1.0';
        $ua_one = $this->identity()->visitor_id();

        $_SERVER['HTTP_USER_AGENT'] = 'OtherUA/2.0';
        self::assertNotSame( $ua_one, $this->identity()->visitor_id() );
    }

    public function testIssueWithoutConsentSendsNothing(): void {
        $GLOBALS['gr_stub_consent']['marketing'] = false;

        $this->identity()->issue();

        self::assertSame( array(), $GLOBALS['gr_stub_cookies'] );
    }

    public function testIssueUnderConsentSendsBothCookiesWithSafeFlags(): void {
        $GLOBALS['gr_stub_consent']['marketing'] = true;
        $GLOBALS['gr_stub_is_ssl']               = true;

        $this->identity()->issue();

        self::assertCount( 2, $GLOBALS['gr_stub_cookies'] );

        $attr = $GLOBALS['gr_stub_cookies'][0];
        self::assertSame( Gr_Identity::COOKIE, $attr['name'] );
        self::assertMatchesRegularExpression( '/^[0-9a-f]{32}\.[0-9a-f]{64}$/', $attr['value'] );
        self::assertTrue( $attr['options']['httponly'] );
        self::assertSame( 'Lax', $attr['options']['samesite'] );
        self::assertSame( '/', $attr['options']['path'] );
        self::assertTrue( $attr['options']['secure'] );
        self::assertGreaterThan( time() + 29 * DAY_IN_SECONDS, $attr['options']['expires'] );

        $session = $GLOBALS['gr_stub_cookies'][1];
        self::assertSame( Gr_Identity::SESSION_COOKIE, $session['name'] );
        self::assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $session['value'] );
        self::assertGreaterThan( time() + 25 * 60, $session['options']['expires'] );
    }

    public function testIssueKeepsAnExistingVerifiedVisitorId(): void {
        $GLOBALS['gr_stub_consent']['marketing'] = true;

        $visitor_id = str_repeat( 'cd', 16 );
        $_COOKIE[ Gr_Identity::COOKIE ] = Gr_Identity::cookie_value( $visitor_id );

        $this->identity()->issue();

        self::assertStringStartsWith( $visitor_id . '.', $GLOBALS['gr_stub_cookies'][0]['value'] );
    }

    public function testCookieDaysSettingDrivesExpiry(): void {
        $GLOBALS['gr_stub_consent']['marketing'] = true;

        $settings = new Gr_Settings();
        $settings->set( 'attribution_cookie_days', 7 );

        ( new Gr_Identity( $settings ) )->issue();

        $expires = $GLOBALS['gr_stub_cookies'][0]['options']['expires'];
        self::assertGreaterThan( time() + 6 * DAY_IN_SECONDS, $expires );
        self::assertLessThan( time() + 8 * DAY_IN_SECONDS, $expires );
    }
}
