<?php
/**
 * Central settings service.
 *
 * The gr_settings option is the plugin's single autoload=yes row (docs/05
 * §6); every default value lives in defaults() so no module invents its own
 * or drifts from the recorded decisions (ADR-0007).
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Storage for the plugin's one autoloaded settings row; reads merge in the
 * central defaults, writes go straight back to the option.
 */
final class Gr_Settings {

    /** Option name; also the only autoload=yes row the plugin may create. */
    public const OPTION_KEY = 'gr_settings';

    /**
     * Memoized merged view of defaults + stored values. Static on
     * purpose: the option row is process-wide truth, so every
     * instance — including ones built before a write — must see the
     * same invalidation, or a same-request reader serves the value
     * from before the write.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $values = null;

    /**
     * Plugin-wide defaults. Groups: security / probe / attribution / privacy,
     * matching the decision record in ADR-0007.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array {
        return array(
            // Security track (legitimate interest; full IP storage by default).
            'security_enabled'           => 1,
            'security_action_mode'       => 'log',
            'security_log_anonymize'     => 0,
            'trust_proxy_headers'        => 0,
            'trusted_proxies'            => array(),

            // Login brute-force protection (docs/13 W7): failures counted
            // per address+username pair over a 15-minute window; reaching
            // the threshold locks the address with a gradient duration.
            'login_fail_threshold'       => 5,
            'login_lockout_base'         => 300,

            // Honeypot traps (docs/13 W8): opt-in — the trap and its
            // carrier appear in the login/registration forms only when
            // the site owner turns this on.
            'honeypot_enabled'           => 0,

            // Blackhole trap (docs/13 W12): opt-in — the virtual path is
            // declared in robots.txt and answered only when enabled.
            'blackhole_enabled'          => 0,

            // Client probe: default on (safety signals only, Recital 49
            // basis; toggle + readme disclosure per ADR-0007).
            'probe_enabled'              => 1,

            // Behavior probe (ADR-0012): the marketing-track file —
            // dwell, scroll, rage and dead clicks. Off by default and
            // additionally consent-gated on both ends; on only when the
            // owner opts in AND the visitor allows marketing.
            'behavior_enabled'           => 0,

            // Probe verdict threshold (ADR-0009 D1): the score at which
            // a session reads as a known bot. 70 asks for two
            // corroborating signals, so one automation flag on a real
            // developer's browser never costs them their conversions.
            'bot_verdict_threshold'      => 70,

            // Attribution (consent-gated at runtime; 30-day signed cookie).
            'attribution_enabled'        => 1,
            'attribution_cookie_days'    => 30,
            'attribution_default_model'  => 'last',

            // Cart recovery (ADR-0015): off by default — the checkbox
            // on checkout is an ask, and it only appears when the owner
            // asks first. Delay is minutes, clamped 5..120 at every
            // read; the mail body template lives in its own
            // autoload=no option, not here, because it is content, not
            // a switch.
            'cart_recovery_enabled'      => 0,
            'cart_recovery_delay'        => 15,
            'cart_recovery_subject'      => 'Your cart at {site}',

            // Outbound analytics (docs/13 U15, docs/07 §5): both
            // default off, opt-in only. The toggles live here; the
            // credentials never do — they ride Gr_Secrets options.
            'capi_meta_enabled'          => 0,
            'capi_ga4_enabled'           => 0,

            // Third-party security services (v1.2, ADR-0017): the
            // challenge widget and the reputation check stay off
            // until the owner opts in; their keys ride Gr_Secrets.
            'turnstile_enabled'          => 0,
            'hcaptcha_enabled'           => 0,
            'abuseipdb_enabled'          => 0,

            // Outbound analytics additions (v1.2, ADR-0017): TikTok and
            // Matomo follow the same opt-in contract as the pair above.
            // The Matomo site id is a plain number, not a credential;
            // its instance URL and token_auth ride Gr_Secrets.
            'capi_tiktok_enabled'        => 0,
            'capi_matomo_enabled'        => 0,
            'matomo_site_id'             => 0,

            // Spider segment subscription (v1.2, docs/07 §5.4): the
            // weekly refresh schedule stays off until the owner opts
            // in; the manual refresh button needs no toggle — the
            // click itself is the owner's word.
            'spider_segments_weekly'     => 0,

            // Built-in native SMTP engine (v1.2.1+ / ADR-0019).
            'smtp_enabled'               => 0,
            'smtp_host'                  => '',
            'smtp_port'                  => 465,
            'smtp_encryption'            => 'ssl',
            'smtp_auth'                  => 1,
            'smtp_user'                  => '',
            'smtp_from_email'            => '',
            'smtp_from_name'             => '',

            // Modular Email Notifications (v1.2.1+ / ADR-0019).
            'notify_email_enabled'       => 0,
            'notify_email_recipients'    => '',
            'notify_email_events'        => array( 'conversion', 'security_lockout' ),

            // Privacy: marketing-track IP anonymization on by default;
            // when the host has no Consent API, this stand-in toggle
            // decides marketing tracking, defaulting to off (ADR-0005 §1).
            'marketing_ip_anonymize'     => 1,
            'marketing_consent_fallback' => 0,

            // Retention ceilings per table (docs/05 §5 dual-rail policy).
            'retention_days'             => array(
                'security_logs'     => 30,
                'sessions'          => 90,
                'events'            => 30,
                'touchpoints'       => 90,
                'funnel_sessions'   => 30,
                'cart_abandonments' => 90,
                'audit_logs'        => 365,
                'daily_stats'       => 365,
            ),

            // Row ceilings per table, the second rail: 0 = the age
            // rail alone decides. Wide by default — a ceiling is a
            // runaway guard, not the primary trim.
            'retention_rows'             => array(
                'security_logs'     => 100000,
                'sessions'          => 500000,
                'events'            => 1000000,
                'touchpoints'       => 200000,
                'funnel_sessions'   => 500000,
                'cart_abandonments' => 100000,
                'audit_logs'        => 200000,
                'daily_stats'       => 0,
            ),
        );
    }

    /**
     * Creates the option at activation. Idempotent on purpose: a second
     * activation must never reset a site owner's stored choices.
     *
     * @return bool True when the option exists afterwards.
     */
    public static function install(): bool {
        if ( false !== get_option( self::OPTION_KEY, false ) ) {
            return true;
        }

        return add_option( self::OPTION_KEY, self::defaults(), '', 'yes' );
    }

    /**
     * Full settings view: defaults for anything missing from storage.
     *
     * @return array<string, mixed>
     */
    public function all(): array {
        if ( null === self::$values ) {
            $stored = get_option( self::OPTION_KEY, array() );
            if ( ! is_array( $stored ) ) {
                $stored = array();
            }
            self::$values = array_merge( self::defaults(), $stored );
        }

        return self::$values;
    }

    /**
     * Reads one setting, falling back to the per-call value for keys the
     * plugin does not define.
     *
     * @param string $key      Setting key.
     * @param mixed  $fallback Value when the key is unknown.
     * @return mixed
     */
    public function get( string $key, $fallback = null ) {
        $all = $this->all();
        if ( array_key_exists( $key, $all ) ) {
            return $all[ $key ];
        }

        return $fallback;
    }

    /**
     * Persists one setting. Callers own capability + nonce checks; this
     * service is storage, not authorization. The static memo drops for
     * every instance at once — a value written now is the value any
     * same-process reader sees next.
     *
     * @param string $key   Setting key.
     * @param mixed  $value New value.
     * @return bool
     */
    public function set( string $key, $value ): bool {
        $stored = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }

        $stored[ $key ] = $value;
        $result         = update_option( self::OPTION_KEY, $stored );
        self::$values   = null;

        return (bool) $result;
    }

    /**
     * Test seam: forget the process-wide memo so the next read serves
     * whatever the (freshly reset) option store holds.
     *
     * @return void
     */
    public static function reset_for_tests(): void {
        self::$values = null;
    }
}
