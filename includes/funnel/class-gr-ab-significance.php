<?php
/**
 * A/B significance (docs/03 §5): the two-proportion Z-test over the
 * event stream, control versus every other variant. The three states
 * the admin surface needs — insufficient sample, inconclusive,
 * significant — come out of one calculation with hand-checkable
 * numbers, because a dashboard nobody can audit is a dashboard nobody
 * should trust.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Funnel;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Storage\Gr_Event_Repository;

/**
 * Two-proportion Z-test over recorded ab events.
 */
final class Gr_Ab_Significance {

    /** Per-arm impression floor: below this the pair is unreadable. */
    private const MIN_ARM = 30;

    /** Two-sided 95% z threshold. */
    private const Z_95 = 1.96;

    /**
     * Calculates significance for one experiment.
     *
     * @param string $experiment Experiment key.
     * @return array<string, mixed> experiment, status
     *        (insufficient|inconclusive|significant), variants
     *        (slug => impressions/conversions/cvr), pairs
     *        (control vs each other variant: z, winner, confidence).
     */
    public static function calculate( string $experiment ): array {
        $key        = sanitize_key( $experiment );
        $definition = Gr_Ab_Experiments::get( $key );

        $out = array(
            'experiment' => $key,
            'status'     => 'insufficient',
            'variants'   => array(),
            'pairs'      => array(),
        );

        if ( null === $definition || ! is_array( $definition['variants'] ) || count( $definition['variants'] ) < 2 ) {
            return $out;
        }

        $counts = ( new Gr_Event_Repository() )->ab_counts( $key );

        foreach ( $definition['variants'] as $variant ) {
            $impressions = isset( $counts[ $variant ]['impression'] ) ? (int) $counts[ $variant ]['impression'] : 0;
            $conversions = isset( $counts[ $variant ]['conversion'] ) ? (int) $counts[ $variant ]['conversion'] : 0;

            $out['variants'][ $variant ] = array(
                'impressions' => $impressions,
                'conversions' => $conversions,
                'cvr'         => $impressions > 0 ? round( $conversions / $impressions, 4 ) : 0.0,
            );
        }

        $control           = (string) $definition['variants'][0];
        $insufficient_arms = false;
        $significant       = false;

        foreach ( $definition['variants'] as $variant ) {
            if ( $variant === $control ) {
                continue;
            }

            $pair           = self::pair(
                $out['variants'][ $control ],
                $out['variants'][ $variant ],
                $control,
                $variant
            );
            $out['pairs'][] = $pair;

            if ( 'insufficient' === $pair['state'] ) {
                $insufficient_arms = true;
            }
            if ( 'significant' === $pair['state'] ) {
                $significant = true;
            }
        }

        if ( $significant ) {
            $out['status'] = 'significant';
        } elseif ( $insufficient_arms ) {
            $out['status'] = 'insufficient';
        } else {
            $out['status'] = 'inconclusive';
        }

        return $out;
    }

    /**
     * One control-versus-variant comparison.
     *
     * @param array<string, mixed> $control  Control arm counts.
     * @param array<string, mixed> $variant  Challenger arm counts.
     * @param string               $control_name  Control slug.
     * @param string               $variant_name  Challenger slug.
     * @return array<string, mixed>
     */
    private static function pair( array $control, array $variant, string $control_name, string $variant_name ): array {
        $i_a = (int) $control['impressions'];
        $i_b = (int) $variant['impressions'];
        $c_a = (int) $control['conversions'];
        $c_b = (int) $variant['conversions'];

        $pair = array(
            'control'    => $control_name,
            'variant'    => $variant_name,
            'state'      => 'insufficient',
            'z'          => 0.0,
            'winner'     => '',
            'confidence' => 0,
        );

        if ( $i_a < self::MIN_ARM || $i_b < self::MIN_ARM ) {
            return $pair;
        }

        $p_a = $c_a / $i_a;
        $p_b = $c_b / $i_b;

        // Pooled proportion and standard error: the standard
        // two-proportion test under the null of equal rates.
        $p_pool = ( $c_a + $c_b ) / ( $i_a + $i_b );
        $se     = sqrt( $p_pool * ( 1 - $p_pool ) * ( ( 1 / $i_a ) + ( 1 / $i_b ) ) );

        if ( $se <= 0 ) {
            // Identical and absolute rates (all convert or none do):
            // no measurable difference, never significance.
            $pair['state']  = 'inconclusive';
            $pair['winner'] = 'tie';

            return $pair;
        }

        $pair['z'] = round( ( $p_b - $p_a ) / $se, 4 );

        if ( abs( $pair['z'] ) >= self::Z_95 ) {
            $pair['state']      = 'significant';
            $pair['winner']     = $pair['z'] > 0 ? $variant_name : $control_name;
            $pair['confidence'] = 95;

            return $pair;
        }

        $pair['state']  = 'inconclusive';
        $pair['winner'] = 'tie';

        return $pair;
    }
}
