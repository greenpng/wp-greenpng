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
     * Memoized merged view of defaults + stored values, invalidated on set().
     *
     * @var array<string, mixed>|null
     */
    private ?array $values = null;

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

            // Client probe: default on (safety signals only, Recital 49
            // basis; toggle + readme disclosure per ADR-0007).
            'probe_enabled'              => 1,

            // Attribution (consent-gated at runtime; 30-day signed cookie).
            'attribution_enabled'        => 1,
            'attribution_cookie_days'    => 30,
            'attribution_default_model'  => 'last',

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
        if ( null === $this->values ) {
            $stored = get_option( self::OPTION_KEY, array() );
            if ( ! is_array( $stored ) ) {
                $stored = array();
            }
            $this->values = array_merge( self::defaults(), $stored );
        }

        return $this->values;
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
     * service is storage, not authorization.
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
        $this->values   = null;

        return (bool) $result;
    }
}
