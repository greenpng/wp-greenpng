<?php
/**
 * Visitor and session identity on the dual-track model (docs/05 §3.2,
 * ADR-0007): the primary track is the signed gr_attr cookie issued only
 * after marketing consent, giving a 30-day stable visitor_id for
 * cross-day attribution; everyone else falls back to a daily rotating
 * salt hash of the anonymized IP and user agent, which by construction
 * cannot correlate across days.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Attribution;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Secrets;
use GreenPNG\Core\Gr_Settings;
use GreenPNG\Privacy\Gr_Consent;
use GreenPNG\Privacy\Gr_Privacy;

/**
 * Resolves and issues the two marketing identities (visitor, session).
 */
final class Gr_Identity {

    /** Signed visitor-identity cookie (docs/04 §1). */
    public const COOKIE = 'gr_attr';

    /** Sliding-window session cookie. */
    public const SESSION_COOKIE = 'gr_session';

    /** Session window in seconds; refreshed on every issue(). */
    private const SESSION_WINDOW = 1800;

    /** Shape of a cookie visitor id: 16 random bytes as hex. */
    private const VISITOR_ID_PATTERN = '/^[0-9a-f]{32}$/';

    /** Shape of a session id: UUID form. */
    private const SESSION_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /**
     * Settings service.
     *
     * @var Gr_Settings
     */
    private Gr_Settings $settings;

    /**
     * Visitor id issued during this request, memoized so same-request
     * readers see the cookie-track identity even though $_COOKIE only
     * echoes it on the next request.
     *
     * @var string|null
     */
    private ?string $issued_visitor_id = null;

    /**
     * Session id issued during this request, memoized for the same
     * reason as the visitor id.
     *
     * @var string|null
     */
    private ?string $issued_session_id = null;

    /**
     * Wires the settings service.
     *
     * @param Gr_Settings $settings Injected for testability.
     */
    public function __construct( Gr_Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * Current visitor id: the verified cookie value, or the fallback
     * daily hash when no valid cookie exists.
     *
     * @return string
     */
    public function visitor_id(): string {
        if ( null !== $this->issued_visitor_id ) {
            return $this->issued_visitor_id;
        }

        $cookie = $this->verified_visitor_cookie();
        if ( '' !== $cookie ) {
            return $cookie;
        }

        return $this->fallback_id( 'visitor' );
    }

    /**
     * Current session id: the session cookie's UUID, or the fallback
     * daily hash truncated to the column width.
     *
     * @return string
     */
    public function session_id(): string {
        if ( null !== $this->issued_session_id ) {
            return $this->issued_session_id;
        }

        if ( isset( $_COOKIE[ self::SESSION_COOKIE ] ) ) {
            $sid = sanitize_text_field( wp_unslash( $_COOKIE[ self::SESSION_COOKIE ] ) );
            if ( preg_match( self::SESSION_ID_PATTERN, $sid ) === 1 ) {
                return $sid;
            }
        }

        return substr( $this->fallback_id( 'session' ), 0, 36 );
    }

    /**
     * Whether a valid signed cookie identity exists; callers use this to
     * tell the cross-day track from the daily-fallback track.
     *
     * @return bool
     */
    public function has_cookie_identity(): bool {
        return '' !== $this->verified_visitor_cookie();
    }

    /**
     * Issues both cookies — but only under marketing consent (ADR-0005
     * §1). An existing verified visitor id is kept, so consent given
     * mid-visit does not reset attribution history.
     *
     * @return void
     */
    public function issue(): void {
        if ( ! Gr_Consent::allows( 'marketing' ) ) {
            return;
        }

        $visitor_id = $this->verified_visitor_cookie();
        if ( '' === $visitor_id ) {
            $visitor_id = bin2hex( random_bytes( 16 ) );
        }

        // Same-request visibility: the campaign entry that triggered
        // this issue must land on the cookie track, not the daily
        // fallback (docs/05 §3.2 primary track).
        $this->issued_visitor_id = $visitor_id;

        $days = (int) $this->settings->get( 'attribution_cookie_days' );
        $days = max( 1, min( $days, 365 ) );

        $this->send_cookie( self::COOKIE, self::cookie_value( $visitor_id ), time() + $days * DAY_IN_SECONDS );

        $session_id = $this->session_id();
        if ( preg_match( self::SESSION_ID_PATTERN, $session_id ) !== 1 ) {
            $session_id = wp_generate_uuid4();
        }

        $this->issued_session_id = $session_id;

        $this->send_cookie( self::SESSION_COOKIE, $session_id, time() + self::SESSION_WINDOW );
    }

    /**
     * Formats one cookie payload: visitor id plus its HMAC. Public so
     * callers that need the exact payload (tests, diagnostics) never
     * reconstruct the format by hand.
     *
     * @param string $visitor_id Cookie visitor id.
     * @return string
     */
    public static function cookie_value( string $visitor_id ): string {
        return $visitor_id . '.' . Gr_Secrets::sign_hmac( $visitor_id, wp_salt( 'auth' ) . '|greenpng-attr' );
    }

    /**
     * The verified visitor id from the cookie, or '' when absent,
     * malformed, or failing the signature check.
     *
     * @return string
     */
    private function verified_visitor_cookie(): string {
        if ( ! isset( $_COOKIE[ self::COOKIE ] ) ) {
            return '';
        }

        $raw   = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
        $parts = explode( '.', $raw );
        if ( 2 !== count( $parts ) ) {
            return '';
        }

        list( $visitor_id, $signature ) = $parts;
        if ( preg_match( self::VISITOR_ID_PATTERN, $visitor_id ) !== 1 ) {
            return '';
        }

        $expected = Gr_Secrets::sign_hmac( $visitor_id, wp_salt( 'auth' ) . '|greenpng-attr' );

        return hash_equals( $expected, $signature ) ? $visitor_id : '';
    }

    /**
     * Fallback identity (no cookie / no consent): daily rotating salt
     * over the (by default anonymized) IP and user agent. The day token
     * makes cross-day correlation impossible by construction
     * (docs/05 §3.2), and the domain separator keeps visitor and session
     * hashes distinct.
     *
     * @param string $domain 'visitor' or 'session'.
     * @return string
     */
    private function fallback_id( string $domain ): string {
        $ip = gr_get_client_ip();
        if ( 1 === (int) $this->settings->get( 'marketing_ip_anonymize' ) ) {
            $ip = Gr_Privacy::anonymize_ip( $ip );
        }

        $day = substr( current_time( 'mysql' ), 0, 10 );

        return hash( 'sha256', wp_salt( 'auth' ) . '|' . $day . '|' . $domain . '|' . $ip . '|' . gr_get_user_agent() );
    }

    /**
     * Sends one cookie under the consent-safe flag set (HttpOnly,
     * SameSite=Lax, Secure on TLS).
     *
     * @param string $name    Cookie name.
     * @param string $value   Cookie value.
     * @param int    $expires Expiry timestamp.
     * @return void
     */
    private function send_cookie( string $name, string $value, int $expires ): void {
        setcookie(
            $name,
            $value,
            array(
                'expires'  => $expires,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            )
        );
    }
}
