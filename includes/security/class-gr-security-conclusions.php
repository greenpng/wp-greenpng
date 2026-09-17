<?php
/**
 * Security-to-quality conclusions channel (docs/13 W13, docs/07 §4,
 * ADR-0007): the one bridge between the security track and the CRM
 * side, and it carries conclusions only — a boolean and a tier. The
 * raw signals behind them (which rule fired, what UA, which address,
 * any fingerprint detail) never cross: the CRM's purpose-bridging
 * permission ends at the verdict (docs/07 §4 clause 3, closing the
 * reference project's fingerprint_hash dual-use defect). Login
 * failures deliberately feed nothing here — humans mistype
 * passwords; a lockout says the address is under attack, not that a
 * visitor is a bot.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Static conclusions mapper and dispatcher behind the event channel.
 */
final class Gr_Security_Conclusions {

    /** Event name on the gr_event bus. */
    public const EVENT_NAME = 'security_conclusion';

    /** High-confidence automation tier. */
    private const TIER_HIGH = 'high';

    /** Suspected-but-unclassified tier. */
    private const TIER_MEDIUM = 'medium';

    /**
     * Rules whose hit alone settles a high-confidence verdict. Only
     * rules the feeders can actually send are listed — the frame's
     * detectors, the honeypot, and the blackhole trap.
     */
    private const HIGH_RULES = array( 'scanner_ua', 'sqli_union', 'lfi_traversal', 'honeypot', 'blackhole' );

    /**
     * Whether the request-end session marker is already armed; one
     * request convicting through several rules still marks once.
     *
     * @var bool
     */
    private static bool $marker_armed = false;

    /**
     * Hook wiring: the frame's findings are the per-request surface.
     * Honeypot and blackhole conclusions are fed directly where those
     * modules judge, on paths that never reach the frame.
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( Gr_Request_Inspector::FINDINGS_HOOK, array( self::class, 'handle_findings' ), 10, 1 );
    }

    /**
     * Frame subscriber: findings rows in, one conclusion event out —
     * or nothing at all when the request read as human, because the
     * channel carries conclusions, not reassurances.
     *
     * @param array<int, mixed> $findings Normalized findings rows.
     * @return void
     */
    public static function handle_findings( array $findings ): void {
        $conclusion = self::conclude( $findings );
        if ( null === $conclusion ) {
            return;
        }

        gr_dispatch_event( self::EVENT_NAME, $conclusion );

        self::arm_marker( $conclusion );
    }

    /**
     * Direct feeder for modules whose verdicts happen off the frame:
     * rule ids in, one conclusion event out. No rules means no event.
     *
     * @param string ...$rule_ids Rule identifiers that fired.
     * @return void
     */
    public static function record( string ...$rule_ids ): void {
        if ( array() === $rule_ids ) {
            return;
        }

        $findings = array();
        foreach ( $rule_ids as $rule_id ) {
            $findings[] = array( 'rule_id' => $rule_id );
        }

        $conclusion = self::conclude( $findings );
        if ( null !== $conclusion ) {
            gr_dispatch_event( self::EVENT_NAME, $conclusion );
            self::arm_marker( $conclusion );
        }
    }

    /**
     * Arms the request-end session marker for a high-tier conclusion
     * (ADR-0009 D2). Findings fire at init, but the session row is
     * written by the attribution listener at template_redirect, which
     * runs later — the mark has to land after both. WP reaches the
     * shutdown action through a PHP shutdown function, which also runs
     * after wp_die, so the trap's own verdict still gets its chance
     * to stick.
     *
     * @param array{suspected_bot: bool, bot_tier: string} $conclusion Dispatched conclusion.
     * @return void
     */
    private static function arm_marker( array $conclusion ): void {
        if ( self::$marker_armed || self::TIER_HIGH !== $conclusion['bot_tier'] ) {
            return;
        }

        self::$marker_armed = true;
        add_action( 'shutdown', array( self::class, 'mark_current_session' ), 10, 0 );
    }

    /**
     * Request-end marker: flags the current dual-track identity's
     * session as a known bot so the outbound quality gate and the bot
     * reports read a real verdict instead of the column default.
     * Detector paths that never created a session row — login and
     * registration posts, the trap's wp_die — mark nothing, which is
     * the honest outcome: there was no visit row to convict
     * (ADR-0009 D2).
     *
     * @return void
     */
    public static function mark_current_session(): void {
        try {
            $identity = gr()->identity();
            gr()->sessions()->mark_session_bot(
                (string) $identity->visitor_id(),
                (string) $identity->session_id()
            );
        } catch ( \Throwable $error ) {
            do_action( Gr_Request_Inspector::ERROR_HOOK, 'conclusions_marker', $error );
        }
    }

    /**
     * Test seam: forget the armed marker.
     *
     * @return void
     */
    public static function reset_for_tests(): void {
        self::$marker_armed = false;
    }

    /**
     * Pure mapper: findings rows down to the two-field conclusion.
     * One high-confidence rule settles the tier; anything unclassified
     * reads as suspected at the lower band; no readable rows at all
     * reads as human and maps to null.
     *
     * @param array<int, mixed> $findings Normalized findings rows.
     * @return array{suspected_bot: bool, bot_tier: string}|null
     */
    public static function conclude( array $findings ): ?array {
        $tier = null;

        foreach ( $findings as $finding ) {
            if ( ! is_array( $finding ) || ! isset( $finding['rule_id'] ) || ! is_scalar( $finding['rule_id'] ) ) {
                continue;
            }

            if ( in_array( (string) $finding['rule_id'], self::HIGH_RULES, true ) ) {
                $tier = self::TIER_HIGH;
                break;
            }

            $tier = self::TIER_MEDIUM;
        }

        if ( null === $tier ) {
            return null;
        }

        return array(
            'suspected_bot' => true,
            'bot_tier'      => $tier,
        );
    }
}
