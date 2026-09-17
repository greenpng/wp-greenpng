<?php
/**
 * The five attribution models (docs/03 §4): first, last, linear,
 * position-based 40/20/40, and time-decay with a 7-day half-life. Pure
 * arithmetic over an ordered touchpoint sequence; persistence and
 * consent live elsewhere. Cents are reconciled per model so every
 * split sums to the exact amount.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Attribution;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Multi-touch credit distribution.
 */
final class Gr_Attribution_Models {

    /** Position-based: first and last share, middles evenly. */
    private const POSITION_END = 0.4;

    /** Time-decay half-life in days. */
    private const HALF_LIFE_DAYS = 7.0;

    /** Seconds per day, as float for the decay exponent. */
    private const DAY_SECONDS = 86400.0;

    /**
     * Computes every model's split for one conversion amount.
     *
     * Touchpoints are rows as get_for_visitor() returns them (id,
     * created_at, …); they are sorted here by created_at then id, so
     * caller order cannot change the outcome. Each model maps
     * touchpoint id => {weight, amount}, and the amounts always sum to
     * the conversion amount to the cent.
     *
     * @param array<int, array<string, mixed>> $touchpoints Touchpoint rows.
     * @param float                            $amount     Conversion amount.
     * @return array<string, array<int, array{weight: float, amount: float}>>
     */
    public static function calculate( array $touchpoints, float $amount ): array {
        $ordered = self::ordered( $touchpoints );

        return array(
            'first'      => self::first( $ordered, $amount ),
            'last'       => self::last( $ordered, $amount ),
            'linear'     => self::linear( $ordered, $amount ),
            'position'   => self::position( $ordered, $amount ),
            'time_decay' => self::time_decay( $ordered, $amount ),
        );
    }

    /**
     * Sorts the sequence by created_at then id, mirroring the
     * repository's read order.
     *
     * @param array<int, array<string, mixed>> $touchpoints Touchpoint rows.
     * @return array<int, array<string, mixed>>
     */
    private static function ordered( array $touchpoints ): array {
        usort(
            $touchpoints,
            static function ( array $a, array $b ): int {
                $time = strcmp( (string) ( $a['created_at'] ?? '' ), (string) ( $b['created_at'] ?? '' ) );
                if ( 0 !== $time ) {
                    return $time;
                }

                return (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 );
            }
        );

        return $touchpoints;
    }

    /**
     * First touch takes everything.
     *
     * @param array<int, array<string, mixed>> $ordered Ordered rows.
     * @param float                            $amount  Conversion amount.
     * @return array<int, array{weight: float, amount: float}>
     */
    private static function first( array $ordered, float $amount ): array {
        return self::single_winner( $ordered, 0, $amount );
    }

    /**
     * Last touch takes everything.
     *
     * @param array<int, array<string, mixed>> $ordered Ordered rows.
     * @param float                            $amount  Conversion amount.
     * @return array<int, array{weight: float, amount: float}>
     */
    private static function last( array $ordered, float $amount ): array {
        return self::single_winner( $ordered, count( $ordered ) - 1, $amount );
    }

    /**
     * Even split.
     *
     * @param array<int, array<string, mixed>> $ordered Ordered rows.
     * @param float                            $amount  Conversion amount.
     * @return array<int, array{weight: float, amount: float}>
     */
    private static function linear( array $ordered, float $amount ): array {
        $count = count( $ordered );
        if ( 0 === $count ) {
            return array();
        }

        return self::distribute( $ordered, array_fill( 0, $count, 1.0 / $count ), $amount );
    }

    /**
     * Position-based 40/20/40: single touch 100%, two touches 50/50,
     * otherwise first and last 40% each and the middles share 20%.
     *
     * @param array<int, array<string, mixed>> $ordered Ordered rows.
     * @param float                            $amount  Conversion amount.
     * @return array<int, array{weight: float, amount: float}>
     */
    private static function position( array $ordered, float $amount ): array {
        $count = count( $ordered );
        if ( 0 === $count ) {
            return array();
        }

        if ( 1 === $count ) {
            return self::single_winner( $ordered, 0, $amount );
        }

        if ( 2 === $count ) {
            return self::distribute( $ordered, array( 0.5, 0.5 ), $amount );
        }

        $middle               = ( 1.0 - 2.0 * self::POSITION_END ) / ( $count - 2 );
        $shares               = array_fill( 0, $count, $middle );
        $shares[0]            = self::POSITION_END;
        $shares[ $count - 1 ] = self::POSITION_END;

        return self::distribute( $ordered, $shares, $amount );
    }

    /**
     * Time-decay: weights are 0.5 ** (age_days / 7) relative to the
     * newest touchpoint, so a touch a half-life old counts half as much
     * as the newest one.
     *
     * @param array<int, array<string, mixed>> $ordered Ordered rows.
     * @param float                            $amount  Conversion amount.
     * @return array<int, array{weight: float, amount: float}>
     */
    private static function time_decay( array $ordered, float $amount ): array {
        $count = count( $ordered );
        if ( 0 === $count ) {
            return array();
        }

        if ( 1 === $count ) {
            return self::single_winner( $ordered, 0, $amount );
        }

        $newest = (float) strtotime( (string) end( $ordered )['created_at'] );

        $weights = array();
        foreach ( $ordered as $row ) {
            $age       = max( 0.0, $newest - (float) strtotime( (string) $row['created_at'] ) );
            $weights[] = pow( 0.5, $age / ( self::HALF_LIFE_DAYS * self::DAY_SECONDS ) );
        }

        return self::distribute( $ordered, $weights, $amount );
    }

    /**
     * One touchpoint takes the full amount.
     *
     * @param array<int, array<string, mixed>> $ordered Ordered rows.
     * @param int                              $index    Winner position.
     * @param float                            $amount   Conversion amount.
     * @return array<int, array{weight: float, amount: float}>
     */
    private static function single_winner( array $ordered, int $index, float $amount ): array {
        if ( ! isset( $ordered[ $index ] ) ) {
            return array();
        }

        $id = (int) $ordered[ $index ]['id'];

        return array(
            $id => array(
                'weight' => 1.0,
                'amount' => round( $amount, 2 ),
            ),
        );
    }

    /**
     * Turns relative weights into exact cents: floor to the cent per
     * touchpoint, then hand the missing cents to the largest credits,
     * ties going to the later touch. Weights come back normalized.
     *
     * @param array<int, array<string, mixed>> $ordered Ordered rows.
     * @param array<int, float>                $weights  Relative weights.
     * @param float                            $amount   Conversion amount.
     * @return array<int, array{weight: float, amount: float}>
     */
    private static function distribute( array $ordered, array $weights, float $amount ): array {
        $total = array_sum( $weights );
        if ( $total <= 0 ) {
            return array();
        }

        $cents_total = (int) round( $amount * 100.0 );

        $cents = array();
        foreach ( $weights as $i => $weight ) {
            // The round() first strips binary float dust (a 0.4+0.1+0.1+0.4
            // split can floor to 19.99 without it); the floor still keeps
            // genuine fractions below the cent.
            $cents[ $i ] = (int) floor( round( $amount * 100.0 * ( $weight / $total ), 6 ) );
        }

        // Reconcile: the missing cents go to the largest credits, ties
        // to the later touchpoint, so the split always sums exactly.
        $missing = $cents_total - array_sum( $cents );
        $order   = array_keys( $weights );
        usort(
            $order,
            static function ( int $a, int $b ) use ( $weights ): int {
                $by_weight = $weights[ $b ] <=> $weights[ $a ];
                if ( 0 !== $by_weight ) {
                    return $by_weight;
                }

                return $b <=> $a;
            }
        );
        while ( $missing > 0 ) {
            foreach ( $order as $i ) {
                if ( $missing <= 0 ) {
                    break;
                }
                ++$cents[ $i ];
                --$missing;
            }
        }

        $result = array();
        foreach ( $ordered as $i => $row ) {
            $id            = (int) $row['id'];
            $result[ $id ] = array(
                'weight' => round( $weights[ $i ] / $total, 6 ),
                'amount' => round( $cents[ $i ] / 100.0, 2 ),
            );
        }

        return $result;
    }
}
