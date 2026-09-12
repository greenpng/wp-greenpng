<?php
/**
 * Honeypot trap for the login and registration forms (docs/13 W8,
 * docs/03 §3, docs/06 §4, PEER-01): stateless dynamic field names —
 * the trap and its carrier are ordinary-looking inputs whose link is
 * sealed in an AES-GCM envelope only this plugin can open, so nothing
 * in the markup tells a bot which field is bait. A submission is
 * automated when the trap field comes back filled or the form returns
 * faster than any human could complete it. The judgement itself is
 * pure (no settings reads, no storage); the hooks own the opt-in
 * gate, the record-only default, and the optional block escalation.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Request;
use GreenPNG\Core\Gr_Secrets;

/**
 * Static trap engine behind gr_render_honeypot() and
 * gr_check_honeypot(), plus the login/registration form wiring.
 */
final class Gr_Honeypot {

    /**
     * Carrier value prefix: the one fixed marker in the whole scheme.
     * The carrier FIELD name stays dynamic; only a bot that reads
     * every value could even find it, and stripping it just leaves
     * the submission unjudged — never a false positive.
     */
    private const CARRIER_PREFIX = 'gr1.';

    /**
     * Seconds below which a round trip is automation; two seconds
     * covers paste-and-submit but not typing a password.
     */
    private const MIN_ELAPSED = 2;

    /**
     * Plausible decoy stems: a bot that fills every text input it
     * finds bites, while the stems keep the markup looking like an
     * ordinary business form.
     */
    private const DECOYS = array( 'url', 'company', 'phone', 'fax', 'zip' );

    /** Logged rule id. */
    public const RULE_ID = 'honeypot';

    /**
     * Hook wiring: the two core form hooks inject the fields, and the
     * authenticate gate (priority 20, ahead of the W7 lock gate at 30)
     * and the registration_errors gate judge the submission. All four
     * early-return unless the module is opted in.
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( 'login_form', array( self::class, 'render_login' ) );
        add_action( 'register_form', array( self::class, 'render_register' ) );
        add_filter( 'authenticate', array( self::class, 'gate_login' ), 20, 1 );
        add_filter( 'registration_errors', array( self::class, 'gate_register' ), 10, 1 );
    }

    /**
     * Login form injection callback.
     *
     * @return void
     */
    public static function render_login(): void {
        echo self::render( 'login' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every dynamic piece is escaped where the markup is assembled.
    }

    /**
     * Registration form injection callback.
     *
     * @return void
     */
    public static function render_register(): void {
        echo self::render( 'register' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every dynamic piece is escaped where the markup is assembled.
    }

    /**
     * The trap markup for one form context: a text input no assistive
     * technology announces and no keyboard can reach (docs/06 §4:
     * aria-hidden + tabindex only, never display:none, which some
     * readers still expose), plus the hidden carrier that seals the
     * trap's name and the render time.
     *
     * @param string $form_context Where the form lives, e.g. 'login'.
     * @return string Empty when the module is off or the site's crypto
     *                cannot seal the carrier — a trap whose carrier
     *                cannot be opened later would only misjudge.
     */
    public static function render( string $form_context ): string {
        if ( ! self::enabled() ) {
            return '';
        }

        $trap    = self::decoy_name();
        $carrier = self::render_carrier( $trap, $form_context, time() );

        if ( '' === $carrier ) {
            return '';
        }

        return sprintf(
            '<input type="text" name="%1$s" value="" autocomplete="off" aria-hidden="true" tabindex="-1" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;" />'
            . '<input type="hidden" name="%2$s" value="%3$s" />',
            esc_attr( $trap ),
            esc_attr( self::decoy_name( $trap ) ),
            esc_attr( $carrier )
        );
    }

    /**
     * Seals the trap name, the render time, and the form context into
     * one carrier value. Public because it is also the fixture minter
     * for time-delta tests — the envelope is authenticated by GCM, so
     * nothing outside the plugin can craft one.
     *
     * @param string $trap        Trap field name.
     * @param string $form_context Form context.
     * @param int    $rendered_at Render epoch.
     * @return string
     */
    public static function render_carrier( string $trap, string $form_context, int $rendered_at ): string {
        $payload = wp_json_encode(
            array(
                'd' => $trap,
                't' => $rendered_at,
                'c' => substr( $form_context, 0, 32 ),
            )
        );

        if ( ! is_string( $payload ) ) {
            return '';
        }

        $envelope = Gr_Secrets::encrypt( $payload );

        return '' === $envelope ? '' : self::CARRIER_PREFIX . $envelope;
    }

    /**
     * Pure judgement for one submitted payload: the first carrier that
     * opens settles the verdict — trap filled means automation,
     * otherwise a round trip quicker than MIN_ELAPSED does. A payload
     * without a readable carrier is never judged (a stripped carrier
     * is evasion, not evidence).
     *
     * @param array<int|string, mixed> $post Submitted fields.
     * @return array{reason: string, context: string, elapsed: int}|null Null when the submission reads as human.
     */
    public static function evaluate( array $post ): ?array {
        foreach ( $post as $value ) {
            if ( ! is_string( $value ) || ! str_starts_with( $value, self::CARRIER_PREFIX ) ) {
                continue;
            }

            $sealed = Gr_Secrets::decrypt( substr( $value, strlen( self::CARRIER_PREFIX ) ) );
            if ( false === $sealed || '' === $sealed ) {
                continue;
            }

            $payload = json_decode( $sealed, true );
            if ( ! is_array( $payload ) || ! isset( $payload['d'], $payload['t'] ) ) {
                continue;
            }
            if ( ! is_string( $payload['d'] ) || ! is_int( $payload['t'] ) ) {
                continue;
            }

            $trap    = $payload['d'];
            $elapsed = time() - $payload['t'];
            $context = isset( $payload['c'] ) && is_string( $payload['c'] ) ? $payload['c'] : '';

            if ( array_key_exists( $trap, $post ) && ! self::is_empty_submission( $post[ $trap ] ) ) {
                return array(
                    'reason'  => 'trap',
                    'context' => $context,
                    'elapsed' => $elapsed,
                );
            }

            if ( $elapsed < self::MIN_ELAPSED ) {
                return array(
                    'reason'  => 'fast',
                    'context' => $context,
                    'elapsed' => $elapsed,
                );
            }

            return null;
        }

        return null;
    }

    /**
     * Boolean judgement facade body: true means the submission reads
     * as automated.
     *
     * @param array<int|string, mixed> $post Submitted fields.
     * @return bool
     */
    public static function check( array $post ): bool {
        return null !== self::evaluate( $post );
    }

    /**
     * The authenticate gate: in the default 'log' mode a detected bot
     * is recorded and the credentials still get their normal hearing;
     * 'block' mode denies before any credential work happens.
     *
     * @param mixed $user User object or error from earlier filters.
     * @return mixed Unchanged $user, or a denial WP_Error in block mode.
     */
    public static function gate_login( $user ) {
        if ( ! self::enabled() ) {
            return $user;
        }

        $verdict = self::evaluate( self::post_array() );

        if ( null === $verdict ) {
            return $user;
        }

        self::report( $verdict );

        if ( 'block' === (string) gr()->settings()->get( 'security_action_mode' ) ) {
            return new \WP_Error(
                'gr_honeypot',
                __( 'Your submission could not be processed. Please try again.', 'greenpng' ),
                array( 'status' => 403 )
            );
        }

        return $user;
    }

    /**
     * The registration_errors gate: record-only by default, and in
     * 'block' mode the error the filter carries grows one entry so
     * core never creates the account.
     *
     * @param mixed $errors WP_Error or null from earlier filters.
     * @return mixed The same value, or an amended WP_Error in block mode.
     */
    public static function gate_register( $errors ) {
        if ( ! self::enabled() ) {
            return $errors;
        }

        $verdict = self::evaluate( self::post_array() );

        if ( null === $verdict ) {
            return $errors;
        }

        self::report( $verdict );

        if ( 'block' !== (string) gr()->settings()->get( 'security_action_mode' ) ) {
            return $errors;
        }

        $error = $errors instanceof \WP_Error ? $errors : new \WP_Error();
        $error->add( 'gr_honeypot', __( 'Your submission could not be processed. Please try again.', 'greenpng' ) );

        return $error;
    }

    /**
     * Folds one finding into the security log (W6 channel, same shape
     * as the login rules).
     *
     * @param array{reason: string, context: string, elapsed: int} $verdict From evaluate().
     * @return void
     */
    private static function report( array $verdict ): void {
        $reason = 'trap' === $verdict['reason']
            ? 'honeypot trap filled (' . $verdict['context'] . ')'
            : 'honeypot submitted in ' . $verdict['elapsed'] . 's (' . $verdict['context'] . ')';

        gr_log_security_event(
            Gr_Ip_Resolver::resolve(),
            self::RULE_ID,
            Gr_Request::path(),
            Gr_Request::user_agent(),
            $reason
        );
    }

    /**
     * The live POST payload, normalized to an array; the login and
     * registration forms carry no nonce for anonymous visitors, and
     * the honeypot only judges raw bot submits.
     *
     * @return array<int|string, mixed>
     */
    private static function post_array(): array {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- anonymous forms have no nonce to verify; nothing here trusts the payload beyond trap judgement.
        return isset( $_POST ) && is_array( $_POST ) ? $_POST : array();
    }

    /**
     * Opt-in contract: the global security switch AND the module's own
     * setting (default off, docs/13 W8).
     *
     * @return bool
     */
    private static function enabled(): bool {
        $settings = gr()->settings();

        return 1 === (int) $settings->get( 'security_enabled' ) && 1 === (int) $settings->get( 'honeypot_enabled' );
    }

    /**
     * One decoy field name: stem + six hex. The except guard keeps the
     * trap and the carrier on different names.
     *
     * @param string $except Name the new draw must not equal.
     * @return string
     */
    private static function decoy_name( string $except = '' ): string {
        do {
            $name = self::DECOYS[ random_int( 0, count( self::DECOYS ) - 1 ) ] . '_' . bin2hex( random_bytes( 3 ) );
        } while ( $name === $except );

        return $name;
    }

    /**
     * Whether a submitted trap value reads as untouched: empty (or
     * whitespace) scalars and empty arrays are human; anything else
     * was filled in.
     *
     * @param mixed $value Submitted trap value.
     * @return bool
     */
    private static function is_empty_submission( $value ): bool {
        if ( is_string( $value ) ) {
            return '' === trim( $value );
        }

        if ( is_array( $value ) ) {
            return array() === $value;
        }

        return null === $value;
    }
}
