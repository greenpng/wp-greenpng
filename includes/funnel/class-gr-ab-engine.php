<?php
/**
 * A/B assignment engine (docs/03 §5): consistent-hash bucketing that
 * is stable for a visitor across requests, with a URL parameter
 * (?gr_variant=) to force a bucket for testing. The assignment is a
 * pure function of the experiment definition, the visitor id, and an
 * optional force — no writes, no identity work, no consent coupling
 * (the exposure event in the record phase owns that).
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Funnel;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Stable variant assignment.
 */
final class Gr_Ab_Engine {

    /** URL parameter that forces a variant for testability. */
    public const FORCE_PARAM = 'gr_variant';

    /**
     * Assigns the visitor's variant for one experiment.
     *
     * @param string $experiment Experiment key.
     * @param string $visitor_id Visitor identity (either track).
     * @return string Variant slug, or '' when the experiment is absent
     *                or inactive.
     */
    public static function assign( string $experiment, string $visitor_id ): string {
        $definition = Gr_Ab_Experiments::get( $experiment );
        if ( null === $definition || empty( $definition['active'] ) ) {
            return '';
        }

        $variants = $definition['variants'];
        if ( ! is_array( $variants ) || array() === $variants ) {
            return '';
        }

        // The force parameter short-circuits the hash, but only onto a
        // variant this experiment actually declares.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display routing: the value is slugified and matched against the experiment's own variant list before use; no state is written and no private data is read.
        $forced = isset( $_GET[ self::FORCE_PARAM ] )
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same read-only force parameter, see above.
            ? sanitize_key( (string) wp_unslash( $_GET[ self::FORCE_PARAM ] ) )
            : '';
        if ( '' !== $forced && in_array( $forced, $variants, true ) ) {
            return $forced;
        }

        return self::pick_variant( $experiment, $visitor_id, $variants );
    }

    /**
     * The pure consistent-hash split, exposed separately from assign()
     * because the URL force parameter belongs to the browsing admin,
     * not to the visitors an admin page previews: a distribution
     * preview must answer the hash question alone.
     *
     * @param string             $experiment Experiment key.
     * @param string             $visitor_id Visitor identity (either track).
     * @param array<int, string> $variants   Declared variant slugs.
     * @return string Variant slug, '' for an empty variant list.
     */
    public static function pick_variant( string $experiment, string $visitor_id, array $variants ): string {
        if ( array() === $variants ) {
            return '';
        }

        // Consistent hash over experiment + visitor: the same pair
        // lands in the same bucket forever, on every request. crc32 is
        // normalized through %u so 32- and 64-bit PHP builds agree on
        // the unsigned reading before the modulo.
        $bucket = (int) sprintf( '%u', crc32( $experiment . '|' . $visitor_id ) ) % count( $variants );

        return (string) $variants[ $bucket ];
    }
}
