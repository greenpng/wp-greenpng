<?php
/**
 * Attribution listener (docs/13 C7): consent gating of cookies and
 * touchpoints, landing attributes on the session row, and the
 * cross-day stable visitor identity on the cookie track.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Attribution\Gr_Attribution_Listener;
use GreenPNG\Attribution\Gr_Identity;
use GreenPNG\Core\Gr_Settings;
use GreenPNG\Storage\Gr_Session_Repository;
use GreenPNG\Storage\Gr_Touchpoint_Repository;
use PHPUnit\Framework\TestCase;

final class AttributionListenerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();

        $_SERVER['REMOTE_ADDR']      = '10.0.0.9';
        $_SERVER['HTTP_USER_AGENT'] = 'UnitTestAgent/1.0';
        $_SERVER['REQUEST_URI']     = '/landing/?utm_source=google&utm_medium=cpc&gclid=eaia123';
    }

    protected function tearDown(): void {
        unset(
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'],
            $_SERVER['REQUEST_URI'],
            $_SERVER['HTTP_REFERER'],
            $_SERVER['HTTP_DNT']
        );
        // Reset, never unset: other tests' isset() checks on these
        // superglobals must not hit an undefined-variable state.
        $_GET    = array();
        $_COOKIE = array();
        parent::tearDown();
    }

    /**
     * Builds the listener with real services over the stub stores.
     *
     * @return Gr_Attribution_Listener
     */
    private function listener(): Gr_Attribution_Listener {
        $settings = new Gr_Settings();

        return new Gr_Attribution_Listener(
            new Gr_Identity( $settings ),
            new Gr_Session_Repository(),
            new Gr_Touchpoint_Repository(),
            $settings
        );
    }

    /**
     * The one gr_attr cookie value sent during the run, if any.
     *
     * @return string
     */
    private function sent_attr_cookie(): string {
        foreach ( $GLOBALS['gr_stub_cookies'] as $cookie ) {
            if ( Gr_Identity::COOKIE === $cookie['name'] ) {
                return (string) $cookie['value'];
            }
        }

        return '';
    }

    public function testWithoutConsentNoCookieAndNoTouchpointAreWritten(): void {
        global $wpdb;

        $_GET = array(
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'gclid'      => 'eaia123',
        );

        $this->listener()->handle();

        // Acceptance: without consent, no cookie, no touchpoint row.
        self::assertSame( array(), $GLOBALS['gr_stub_cookies'] );
        self::assertSame( array(), $wpdb->inserts );

        // The technical session slide still happens, but with default
        // landing attributes: nothing marketing is stored.
        $sql = implode( ' ', $wpdb->queries );
        self::assertStringContainsString( 'INSERT INTO wp_gr_sessions', $sql );
        self::assertStringContainsString( "'direct'", $sql );
        self::assertStringNotContainsString( "'google'", $sql );
        self::assertStringNotContainsString( "'eaia123'", $sql );
    }

    public function testWithConsentCookiesAreIssuedAndTheTouchpointLands(): void {
        global $wpdb;

        $GLOBALS['gr_stub_consent']['marketing'] = true;

        $_GET = array(
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'gclid'      => 'eaia123',
        );

        $this->listener()->handle();

        // Acceptance: with UTM and consent, the touchpoint row lands.
        self::assertCount( 1, $wpdb->inserts );
        $write = $wpdb->inserts[0];
        self::assertSame( 'wp_gr_touchpoints', $write['table'] );
        self::assertSame( 'cpc', $write['data']['channel'] );
        self::assertSame( 'google', $write['data']['utm_source'] );
        self::assertSame( 'eaia123', $write['data']['click_id'] );
        self::assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $write['data']['visitor_id'] );
        self::assertStringContainsString( '/landing/', $write['data']['landing_url'] );

        // Both identity cookies went out, HttpOnly by policy.
        $names = array_column( $GLOBALS['gr_stub_cookies'], 'name' );
        self::assertContains( Gr_Identity::COOKIE, $names );
        self::assertContains( Gr_Identity::SESSION_COOKIE, $names );

        // The session row carries the landing attributes this time.
        $sql = implode( ' ', $wpdb->queries );
        self::assertStringContainsString( "'google'", $sql );
        self::assertStringContainsString( "'eaia123'", $sql );
    }

    public function testConsentedDirectVisitIssuesCookiesButNoTouchpoint(): void {
        global $wpdb;

        $GLOBALS['gr_stub_consent']['marketing'] = true;
        $_SERVER['REQUEST_URI'] = '/plain-page/';
        $_GET                   = array();

        $this->listener()->handle();

        self::assertSame( array(), $wpdb->inserts );
        self::assertContains( Gr_Identity::COOKIE, array_column( $GLOBALS['gr_stub_cookies'], 'name' ) );
        self::assertStringContainsString( 'INSERT INTO wp_gr_sessions', implode( ' ', $wpdb->queries ) );
    }

    public function testDntVetoesEvenWithTheFallbackToggleOn(): void {
        global $wpdb;

        $GLOBALS['gr_stub_consent']['marketing'] = true;
        $_SERVER['HTTP_DNT'] = '1';
        $_GET                = array( 'utm_source' => 'google' );

        $this->listener()->handle();

        self::assertSame( array(), $GLOBALS['gr_stub_cookies'] );
        self::assertSame( array(), $wpdb->inserts );
    }

    public function testDisabledAttributionSuppressesAllMarketingWrites(): void {
        global $wpdb;

        $GLOBALS['gr_stub_consent']['marketing'] = true;
        update_option( 'gr_settings', array( 'attribution_enabled' => 0 ) );
        $_GET = array( 'utm_source' => 'google', 'gclid' => 'x' );

        $this->listener()->handle();

        self::assertSame( array(), $GLOBALS['gr_stub_cookies'] );
        self::assertSame( array(), $wpdb->inserts );
        self::assertStringContainsString( 'INSERT INTO wp_gr_sessions', implode( ' ', $wpdb->queries ) );
    }

    public function testExternalReferrerAloneQualifiesAsReferralTouchpoint(): void {
        global $wpdb;

        $GLOBALS['gr_stub_consent']['marketing'] = true;
        $_SERVER['HTTP_REFERER'] = 'https://partner.example/some-post';
        $_SERVER['REQUEST_URI']  = '/came-from-link/';
        $_GET                    = array();

        $this->listener()->handle();

        self::assertCount( 1, $wpdb->inserts );
        self::assertSame( 'referral', $wpdb->inserts[0]['data']['channel'] );
        self::assertSame( 'partner.example', $wpdb->inserts[0]['data']['referrer_host'] );
    }

    public function testOwnSiteReferrerIsNotACampaignEntry(): void {
        global $wpdb;

        $GLOBALS['gr_stub_consent']['marketing'] = true;
        $_SERVER['HTTP_REFERER'] = 'https://stub.example/menu/';
        $_GET                    = array();

        $this->listener()->handle();

        self::assertSame( array(), $wpdb->inserts );
    }

    public function testCookieTrackVisitorIdStaysStableAcrossDays(): void {
        global $wpdb;

        $GLOBALS['gr_stub_consent']['marketing'] = true;
        $_GET = array( 'utm_source' => 'google', 'utm_medium' => 'cpc' );

        $this->listener()->handle();

        $attr_cookie = $this->sent_attr_cookie();
        self::assertNotSame( '', $attr_cookie );

        // Day two: the browser replays the signed cookie; the salt day
        // changed, so only a signature-verified identity can stay equal.
        $GLOBALS['gr_stub_now'] = '2026-09-11 08:00:00';
        $_COOKIE                = array( Gr_Identity::COOKIE => $attr_cookie );

        $this->listener()->handle();

        self::assertCount( 2, $wpdb->inserts );
        self::assertSame(
            $wpdb->inserts[0]['data']['visitor_id'],
            $wpdb->inserts[1]['data']['visitor_id']
        );
    }
}
