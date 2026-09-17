<?php
/**
 * Scoring rule store (ADR-0013 D3): the site owner's points table for
 * lead scoring, kept in one non-autoload option. Rules are validated
 * against a closed event vocabulary and hard boundaries on every
 * field, so a drifted or hand-edited option can never push an
 * out-of-range number onto a contact row.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\CRM;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The site owner's points table: six event names, points, daily caps
 * and an active flag, persisted in one non-autoload option with hard
 * boundaries on every field so a drifted option can never push an
 * out-of-range number onto a contact row.
 */
final class Gr_Scoring_Rules {

    /** Option key, non-autoload by design (docs/05 §6). */
    public const OPTION = 'gr_scoring_rules';

    /** Row ceiling: the vocabulary is six names, thirty is generous headroom. */
    public const MAX_RULES = 30;

    /**
     * Closed event vocabulary the rules may score: the one web event,
     * the four behavior signals (ADR-0012) and the conversion binding.
     *
     * @var array<int, string>
     */
    public const VOCAB = array( 'pageview', 'dwell', 'scroll_depth', 'rage_click', 'dead_click', 'conversion' );

    /**
     * Every stored rule, normalized; an absent or malformed option
     * reads as no rules at all.
     *
     * @return array<int, array{event_name: string, points: int, daily_cap: int, active: bool}>
     */
    public static function all(): array {
        $stored = get_option( self::OPTION, array() );
        if ( ! is_array( $stored ) ) {
            return array();
        }

        $rules = array();
        foreach ( $stored as $rule ) {
            if ( ! is_array( $rule ) ) {
                continue;
            }

            $normalized = self::normalize( $rule );
            if ( null !== $normalized ) {
                $rules[] = $normalized;
            }
        }

        return $rules;
    }

    /**
     * Only the active rules; the engine never reads anything else.
     *
     * @return array<int, array{event_name: string, points: int, daily_cap: int, active: bool}>
     */
    public static function active(): array {
        return array_values(
            array_filter(
                self::all(),
                static function ( array $rule ): bool {
                    return $rule['active'];
                }
            )
        );
    }

    /**
     * Validates and persists a full ruleset. Duplicate event names
     * collapse onto their last occurrence (an edited row and a new row
     * colliding is an edit), and the row ceiling is a hard error.
     *
     * @param array<int, mixed> $rules Raw ruleset from the admin form.
     * @return true|\WP_Error True on success; the error carries which
     *                        row and which field broke.
     */
    public static function save( array $rules ) {
        if ( count( $rules ) > self::MAX_RULES ) {
            return new \WP_Error( 'gr_scoring_rules_count', __( 'Too many scoring rules.', 'greenpng' ) );
        }

        $normalized = array();
        $by_name    = array();

        foreach ( $rules as $index => $rule ) {
            if ( ! is_array( $rule ) ) {
                return new \WP_Error(
                    'gr_scoring_rules_shape',
                    __( 'Every scoring rule must be a set of fields.', 'greenpng' ),
                    array( 'row' => $index + 1 )
                );
            }

            $clean = self::normalize( $rule );
            if ( null === $clean ) {
                return new \WP_Error(
                    'gr_scoring_rules_invalid',
                    __( 'The scoring rule is not valid.', 'greenpng' ),
                    array( 'row' => $index + 1 )
                );
            }

            if ( ! in_array( $clean['event_name'], self::VOCAB, true ) ) {
                return new \WP_Error(
                    'gr_scoring_rules_name',
                    __( 'Unknown event name for a scoring rule.', 'greenpng' ),
                    array(
                        'row'  => $index + 1,
                        'name' => $clean['event_name'],
                    )
                );
            }

            $by_name[ $clean['event_name'] ] = $clean;
        }

        $normalized = array_values( $by_name );

        if ( false !== get_option( self::OPTION, false ) ) {
            update_option( self::OPTION, $normalized );

            return true;
        }

        add_option( self::OPTION, $normalized, '', 'no' );

        return true;
    }

    /**
     * One rule into its canonical shape, or null when a field is
     * structurally unusable (the caller decides whether that is an
     * error or a skip; the read path skips, the write path errors).
     *
     * @param array<string, mixed> $rule Raw rule.
     * @return array{event_name: string, points: int, daily_cap: int, active: bool}|null
     */
    private static function normalize( array $rule ): ?array {
        $name = isset( $rule['event_name'] ) ? trim( (string) $rule['event_name'] ) : '';
        if ( '' === $name || strlen( $name ) > 32 ) {
            return null;
        }

        $points = isset( $rule['points'] ) ? (int) $rule['points'] : 0;
        if ( $points < -100 || $points > 100 ) {
            return null;
        }

        $cap = isset( $rule['daily_cap'] ) ? (int) $rule['daily_cap'] : 0;
        if ( $cap < 0 || $cap > 10 ) {
            return null;
        }

        return array(
            'event_name' => $name,
            'points'     => $points,
            'daily_cap'  => $cap,
            'active'     => ! empty( $rule['active'] ),
        );
    }
}
