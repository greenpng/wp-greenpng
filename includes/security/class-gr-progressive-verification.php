<?php
/**
 * Progressive human verification (docs/07 §5.3, docs/19 V9-V12,
 * ADR-0018): an address only ever sees a challenge after it failed
 * twice inside the decay window — normal visitors load nothing, see
 * nothing, and pay nothing. Failures count per address across the
 * login, registration, and form-bridge surfaces; the widget renders
 * and its script loads only on the login and registration pages, and
 * only while a provider is switched on with its full key pair; never
 * site-wide. Verification of a challenged submission runs
 * synchronously through the siteverify client under the ADR-0018
 * carve-out; an unreachable provider fails closed.
 *
 * The module stays independent of the W7 lockout engine: the per-pair
 * lockout keeps its own thresholds, the challenge keeps its own
 * counter, and one does not answer for the other.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Request;
use GreenPNG\Core\Gr_Siteverify;
use GreenPNG\Core\Gr_Secrets;
use GreenPNG\Core\Gr_Settings;
use GreenPNG\Storage\Gr_Session_Repository;

/**
 * Counter, trigger, provider switch, and the gated surfaces of the
 * progressive challenge.
 */
final class Gr_Progressive_Verification {

    /** Failure-counter key namespace. */
    private const FAILS_PREFIX = 'gr_challenge_fails_';

    /** Sliding decay window for the failure count, in seconds. */
    private const FAILS_WINDOW = 3600;

    /** Failures that arm the challenge (docs/07 §1: two). */
    private const THRESHOLD = 2;

    /** Logged rule id for every challenge event. */
    public const RULE_ID = 'challenge';

    /** Denial code for unverified submissions from a triggered address. */
    public const ERR_CHALLENGE = 'gr_challenge_required';

    /**
     * Hook wiring: failures feed the counter from the core events,
     * the widget renders inside the login and registration forms, its
     * script enqueues only on those pages, and the two gates sit at
     * priority 25 — after the honeypot verdict (20), before the W7
     * lock gate (30).
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( 'wp_login_failed', array( self::class, 'note_login_failure' ), 10, 1 );
        add_action( 'login_form', array( self::class, 'render_widget' ) );
        add_action( 'register_form', array( self::class, 'render_widget' ) );
        add_action( 'login_enqueue_scripts', array( self::class, 'enqueue_widget_script' ) );
        add_filter( 'authenticate', array( self::class, 'gate_login' ), 25, 1 );
        add_filter( 'registration_errors', array( self::class, 'gate_register' ), 25, 1 );
    }

    /**
     * The provider switched on with its full key pair, '' when none:
     * the module's arm condition and the single provider discipline
     * (ADR-0018 D6). Turnstile wins the deterministic order when the
     * stored state somehow holds both.
     *
     * @return string Provider word constant, or ''.
     */
    public static function active_provider(): string {
        $settings = gr()->settings();

        if ( 1 === (int) $settings->get( 'turnstile_enabled' )
            && '' !== Gr_Secrets::reveal( Gr_Secrets::TURNSTILE_SITE_OPTION )
            && '' !== Gr_Secrets::reveal( Gr_Secrets::TURNSTILE_SECRET_OPTION ) ) {
            return Gr_Siteverify::PROVIDER_TURNSTILE;
        }

        if ( 1 === (int) $settings->get( 'hcaptcha_enabled' )
            && '' !== Gr_Secrets::reveal( Gr_Secrets::HCAPTCHA_SITE_OPTION )
            && '' !== Gr_Secrets::reveal( Gr_Secrets::HCAPTCHA_SECRET_OPTION ) ) {
            return Gr_Siteverify::PROVIDER_HCAPTCHA;
        }

        return '';
    }

    /**
     * The widget's public site key for one provider, '' when absent.
     *
     * @param string $provider Provider word constant.
     * @return string
     */
    public static function site_key( string $provider ): string {
        $option = Gr_Siteverify::PROVIDER_TURNSTILE === $provider
            ? Gr_Secrets::TURNSTILE_SITE_OPTION
            : Gr_Secrets::HCAPTCHA_SITE_OPTION;

        return Gr_Secrets::reveal( $option );
    }

    /**
     * The wp_login_failed listener: each failed sign-in counts for
     * the live address, whatever the reason — a challenge denial fires
     * the same core event, so no double counting exists anywhere. The
     * hook passes the username that failed; the counting is keyed to
     * the address alone, so the name never reaches anything here.
     *
     * @return void
     */
    public static function note_login_failure(): void {
        if ( ! self::armed() ) {
            return;
        }

        self::mark_failure( 'login', Gr_Ip_Resolver::resolve() );
    }

    /**
     * The form-bridge feeder: a submission from a visitor the security
     * track already convicted counts for the connecting address. The
     * bridge owns no rejection of its own on this surface — the
     * counted pressure is what arms the challenge the address then
     * meets on the login and registration pages (ADR-0018 D5).
     *
     * @param string $ip         Connecting address as text.
     * @param string $visitor_id Dual-track visitor identity.
     * @return void
     */
    public static function note_bridge_submission( string $ip, string $visitor_id ): void {
        if ( ! self::armed() || '' === $visitor_id ) {
            return;
        }

        if ( ! ( new Gr_Session_Repository() )->is_bot_for_visitor( $visitor_id ) ) {
            return;
        }

        self::mark_failure( 'bridge', $ip );
    }

    /**
     * Records one failure for an address inside the decay window.
     * Allow-listed addresses never count: a trusted address cannot
     * arm a challenge against itself, the same way it never locks.
     *
     * @param string $surface 'login', 'register', or 'bridge'.
     * @param string $ip      Client address as text.
     * @return void
     */
    public static function mark_failure( string $surface, string $ip ): void {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return;
        }

        if ( Gr_Access_Rules::is_trusted_ip( $ip ) ) {
            return;
        }

        $key   = self::fails_key( $ip );
        $count = self::transient_int( $key ) + 1;
        set_transient( $key, $count, self::FAILS_WINDOW );

        if ( self::THRESHOLD === $count ) {
            // The arming moment is the one log-worthy event per climb;
            // the underlying failures already carry their own rows
            // under the login rules.
            gr_log_security_event(
                $ip,
                self::RULE_ID,
                Gr_Request::path(),
                Gr_Request::user_agent(),
                'challenge armed (' . $surface . ', ' . $count . ' failures)'
            );
        }
    }

    /**
     * The address's failure count inside the window.
     *
     * @param string $ip Client address as text.
     * @return int
     */
    public static function count( string $ip ): int {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return 0;
        }

        return self::transient_int( self::fails_key( $ip ) );
    }

    /**
     * Raw trigger judgment: the count reached the threshold. Provider
     * availability is deliberately NOT part of this read — callers
     * combine it with their own arm condition.
     *
     * @param string $ip Client address as text.
     * @return bool
     */
    public static function is_triggered( string $ip ): bool {
        return self::count( $ip ) >= self::THRESHOLD;
    }

    /**
     * Whether this address should meet the challenge right now: the
     * module is armed and the address is triggered (V10: triggered
     * AND configured keys, never otherwise).
     *
     * @param string $ip Client address as text.
     * @return bool
     */
    public static function should_render( string $ip ): bool {
        return self::armed() && self::is_triggered( $ip );
    }

    /**
     * Clears the failure pressure: a verified human starts over.
     *
     * @param string $ip Client address as text.
     * @return void
     */
    public static function reset( string $ip ): void {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return;
        }

        delete_transient( self::fails_key( $ip ) );
    }

    /**
     * The login gate (authenticate, priority 25): an untriggered
     * address passes with zero work; a triggered address must carry a
     * provider token that verifies, and the denial falls back into
     * core's wp_login_failed so the failure still counts.
     *
     * @param mixed $user User object or error from earlier filters.
     * @return mixed Unchanged $user, or the challenge denial.
     */
    public static function gate_login( $user ) {
        if ( ! self::armed() ) {
            return $user;
        }

        $ip = Gr_Ip_Resolver::resolve();

        if ( ! self::is_triggered( $ip ) ) {
            return $user;
        }

        if ( self::submission_verified( $ip ) ) {
            return $user;
        }

        return new \WP_Error(
            self::ERR_CHALLENGE,
            self::denial_message(),
            array( 'status' => 403 )
        );
    }

    /**
     * The registration gate (registration_errors, priority 25):
     * core's validation failures count first, then a triggered
     * address must also carry a verified token — the added error keeps
     * core from ever creating the account.
     *
     * @param mixed $errors WP_Error or null from earlier filters.
     * @return mixed The same value, or the amended WP_Error.
     */
    public static function gate_register( $errors ) {
        if ( ! self::armed() ) {
            return $errors;
        }

        $ip = Gr_Ip_Resolver::resolve();

        $has_errors = $errors instanceof \WP_Error && array() !== $errors->get_error_codes();
        if ( $has_errors ) {
            self::mark_failure( 'register', $ip );
        }

        if ( ! self::is_triggered( $ip ) ) {
            return $errors;
        }

        if ( self::submission_verified( $ip ) ) {
            return $errors;
        }

        $error = $errors instanceof \WP_Error ? $errors : new \WP_Error();
        $error->add( self::ERR_CHALLENGE, self::denial_message() );

        return $error;
    }

    /**
     * The widget markup, or nothing: only for a triggered address on
     * an armed module.
     *
     * @return void
     */
    public static function render_widget(): void {
        $ip = Gr_Ip_Resolver::resolve();

        if ( ! self::should_render( $ip ) ) {
            return;
        }

        $provider = self::active_provider();
        $site_key = self::site_key( $provider );

        if ( '' === $site_key ) {
            return;
        }

        echo self::widget_markup( $provider, $site_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every dynamic piece is escaped where the markup is assembled.
    }

    /**
     * One provider's challenge container for the login and
     * registration forms. The site key is the widget's own public
     * identifier — it belongs in the markup by design; the secret
     * never leaves the server.
     *
     * @param string $provider  Provider word constant.
     * @param string $site_key  The provider's public site key.
     * @return string
     */
    public static function widget_markup( string $provider, string $site_key ): string {
        $class = Gr_Siteverify::PROVIDER_TURNSTILE === $provider ? 'cf-turnstile' : 'h-captcha';

        return sprintf(
            '<div class="%1$s" data-sitekey="%2$s" data-theme="auto"></div>',
            esc_attr( $class ),
            esc_attr( $site_key )
        );
    }

    /**
     * The widget's script, enqueued only on the login and registration
     * pages and only while the address should see the challenge — the
     * provider's own CDN is the one sanctioned third-party script
     * load (docs/19 §2 widget boundary), and untriggered page loads
     * reference zero third-party scripts.
     *
     * @return void
     */
    public static function enqueue_widget_script(): void {
        $ip = Gr_Ip_Resolver::resolve();

        if ( ! self::should_render( $ip ) ) {
            return;
        }

        $provider = self::active_provider();
        $url      = Gr_Siteverify::PROVIDER_TURNSTILE === $provider
            ? 'https://challenges.cloudflare.com/turnstile/v0/api.js'
            : 'https://js.hcaptcha.com/1/api.js';

        wp_enqueue_script( 'gr-challenge-' . $provider, $url, array(), GR_VERSION, true );
    }

    /**
     * Verifies the submission's token when present: ok clears the
     * pressure and passes; anything else is the denial. Words only in
     * the log — never the token, never the endpoint.
     *
     * @param string $ip Client address as text.
     * @return bool
     */
    private static function submission_verified( string $ip ): bool {
        $provider = self::active_provider();
        $field    = Gr_Siteverify::token_field( $provider );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- anonymous login and registration forms carry no nonce; the token is a provider-verified one-time value, trimmed here and consumed by the siteverify client.
        $raw = isset( $_POST[ $field ] ) ? trim( (string) wp_unslash( $_POST[ $field ] ) ) : '';

        if ( '' === $raw ) {
            self::report( $ip, 'unverified submission (no token)' );

            return false;
        }

        $result = Gr_Siteverify::verify( $provider, $raw, $ip );

        if ( 'ok' === $result ) {
            self::reset( $ip );
            self::report( $ip, 'verified (' . $provider . ')' );

            return true;
        }

        self::report( $ip, 'rejected (' . $provider . ': ' . $result . ')' );

        return false;
    }

    /**
     * Folds one challenge event into the security log (the W6 channel
     * the Access Rules page reads).
     *
     * @param string $ip     Client address as text.
     * @param string $reason State word phrase.
     * @return void
     */
    private static function report( string $ip, string $reason ): void {
        gr_log_security_event(
            $ip,
            self::RULE_ID,
            Gr_Request::path(),
            Gr_Request::user_agent(),
            $reason
        );
    }

    /**
     * The module's arm condition: the master security fuse AND a
     * fully configured provider. A site without keys never counts,
     * never renders, and never denies.
     *
     * @return bool
     */
    private static function armed(): bool {
        return Gr_Security_Gate::active() && '' !== self::active_provider();
    }

    /**
     * The visitor-facing denial text.
     *
     * @return string
     */
    private static function denial_message(): string {
        return __( 'Please complete the human verification and try again.', 'greenpng' );
    }

    /**
     * Failure-counter key: the address digest keeps the raw address
     * out of the option name.
     *
     * @param string $ip Address.
     * @return string
     */
    private static function fails_key( string $ip ): string {
        return self::FAILS_PREFIX . md5( $ip );
    }

    /**
     * Numeric transient read: missing, expired, or non-scalar entries
     * read as zero, the only sane floor for counters.
     *
     * @param string $key Transient key.
     * @return int
     */
    private static function transient_int( string $key ): int {
        $value = get_transient( $key );

        if ( false === $value || ! is_scalar( $value ) ) {
            return 0;
        }

        return (int) $value;
    }
}
