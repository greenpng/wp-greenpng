<?php
/**
 * RFM segmentation engine (ADR-0013 D4): recency, frequency and net
 * monetary value per contact, quintiled in PHP because MySQL 5.7 has
 * no NTILE and the contact population is site-owner sized. The same
 * daily queue pass that rescores leads also refreshes segments; the
 * profile page recomputes on demand from the same code path. r/f/m
 * quintile scores are never stored — only the eight-word segment and
 * the net value land on the row. Small populations degrade honestly:
 * a solo contact is trivially the most recent and floor-scored on
 * frequency and value, which reads as the 'new' segment.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\CRM;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Queue;
use GreenPNG\Storage\Gr_Contact_Repository;
use GreenPNG\Storage\Gr_Conversion_Repository;

/**
 * RFM segmentation engine: the three axes per contact, quintiled in
 * PHP over the whole population and collapsed onto the eight-word
 * vocabulary, with movement-only writes on the daily pass.
 */
final class Gr_Rfm_Engine {

    /**
     * The eight-segment vocabulary, exactly the words the rfm_segment
     * column accepts.
     *
     * @var array<int, string>
     */
    public const SEGMENTS = array(
        'champions',
        'loyal',
        'potential',
        'new',
        'needs-attention',
        'at-risk',
        'hibernating',
        'lost',
    );

    /**
     * Hook registration: the RFM rider runs directly after lead
     * scoring (priority 8) and before retention trimming (priority 10)
     * on the shared daily pass.
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( Gr_Queue::DAILY_HOOK, array( self::class, 'run_daily' ), 9, 0 );
    }

    /**
     * Daily pass: recompute every segment; quintiles are population
     * relative, so the whole population refreshes together.
     *
     * @return void
     */
    public static function run_daily(): void {
        self::compute_all();
    }

    /**
     * Computes segments and net values for the whole population and
     * stores the ones that moved. Returns the full result map so the
     * profile page can show one contact's r/f/m without a second
     * compute.
     *
     * @return array<int, array{segment: string, ltv: float, r: int, f: int, m: int}>
     *         contact id => the computed state.
     */
    public static function compute_all(): array {
        $computed = self::population_axes();
        $result   = $computed['axes'];
        $stored   = $computed['stored'];
        if ( array() === $result ) {
            return array();
        }

        $contacts = new Gr_Contact_Repository();

        foreach ( $result as $id => $axes ) {
            // Store only movements: unchanged rows keep their one
            // statement and the pass stays cheap on a quiet day.
            $ltv_now = number_format( round( $axes['ltv'], 2 ), 2, '.', '' );
            $before  = isset( $stored[ $id ] ) ? $stored[ $id ] : array(
                'segment' => '',
                'ltv'     => '',
            );
            if ( $axes['segment'] !== $before['segment'] || $ltv_now !== $before['ltv'] ) {
                $contacts->set_segment_and_ltv( $id, $axes['segment'], $axes['ltv'] );
            }
        }

        return $result;
    }

    /**
     * One contact's computed axes, null when unknown — the profile
     * page's on-demand display. Quintiles are population relative,
     * so the population is computed and this contact is picked out
     * of it; nothing is stored from this path.
     *
     * @param int $contact_id Contact row id.
     * @return array{segment: string, ltv: float, r: int, f: int, m: int}|null
     */
    public static function axes_for( int $contact_id ) {
        $axes = self::population_axes()['axes'];

        return isset( $axes[ $contact_id ] ) ? $axes[ $contact_id ] : null;
    }

    /**
     * The pure computation: every contact's axes over the current
     * population plus the stored segment/ltv state, no writes.
     *
     * @return array{axes: array<int, array{segment: string, ltv: float, r: int, f: int, m: int}>, stored: array<int, array{segment: string, ltv: string}>}
     */
    private static function population_axes(): array {
        $people = ( new Gr_Contact_Repository() )->rfm_population();
        if ( array() === $people ) {
            return array(
                'axes'   => array(),
                'stored' => array(),
            );
        }

        $sales = ( new Gr_Conversion_Repository() )->value_counts_by_visitor();

        // Collect the three axes, then quintile each over the
        // population. Recency uses the Unix reading of last_seen, so
        // older stamps sort lower without any date parsing risk.
        $recency = array();
        $freq    = array();
        $value   = array();
        $state   = array();
        $stored  = array();

        foreach ( $people as $row ) {
            $id      = (int) $row['id'];
            $visitor = (string) ( $row['visitor_id'] ?? '' );
            $count   = isset( $sales[ $visitor ] ) ? $sales[ $visitor ]['count'] : 0;
            $net     = isset( $sales[ $visitor ] ) ? $sales[ $visitor ]['net'] : 0.0;

            $r            = (int) strtotime( (string) ( $row['last_seen'] ?? '' ) );
            $state[ $id ] = array(
                'r_raw' => $r,
                'f_raw' => $count,
                'm_raw' => $net,
            );

            // The stored state rides the same read; the movement
            // writes in compute_all() need it as the before-picture.
            $stored[ $id ] = array(
                'segment' => (string) ( $row['rfm_segment'] ?? '' ),
                'ltv'     => (string) ( $row['ltv'] ?? '' ),
            );

            $recency[] = $r;
            $freq[]    = $count;
            $value[]   = $net;
        }

        $r_scores = self::quintile_scores( $recency );
        $f_scores = self::quintile_scores( $freq );
        $m_scores = self::quintile_scores( $value );

        $result = array();
        foreach ( $state as $id => $axes ) {
            // Zero frequency and zero value are the floor by meaning,
            // not by rank: a solo contact with no purchases can never
            // quintile into a high score by standing alone.
            $r = isset( $r_scores[ self::axis_key( $axes['r_raw'] ) ] ) ? $r_scores[ self::axis_key( $axes['r_raw'] ) ] : 1;
            $f = ( $axes['f_raw'] > 0 && isset( $f_scores[ self::axis_key( $axes['f_raw'] ) ] ) ) ? $f_scores[ self::axis_key( $axes['f_raw'] ) ] : 1;
            $m = ( $axes['m_raw'] > 0 && isset( $m_scores[ self::axis_key( $axes['m_raw'] ) ] ) ) ? $m_scores[ self::axis_key( $axes['m_raw'] ) ] : 1;

            $result[ $id ] = array(
                'segment' => self::segment_for( $r, $f, $m ),
                'ltv'     => (float) $axes['m_raw'],
                'r'       => $r,
                'f'       => $f,
                'm'       => $m,
            );
        }

        return array(
            'axes'   => $result,
            'stored' => $stored,
        );
    }

    /**
     * The segment truth table (ADR-0013 D4), ordered, first match
     * wins. Recency opens; frequency follows; the monetary quintile
     * crowns only the triple-high champions.
     *
     * @param int $r Recency quintile, 1..5.
     * @param int $f Frequency quintile, 1..5.
     * @param int $m Monetary quintile, 1..5.
     * @return string One of the eight vocabulary words.
     */
    public static function segment_for( int $r, int $f, int $m ): string {
        if ( $r >= 4 && $f >= 4 && $m >= 4 ) {
            return 'champions';
        }
        if ( $r >= 4 && $f >= 4 ) {
            return 'loyal';
        }
        if ( $r >= 4 && $f >= 2 ) {
            return 'potential';
        }
        if ( $r >= 4 ) {
            return 'new';
        }
        if ( 3 === $r ) {
            return 'needs-attention';
        }
        if ( 2 === $r ) {
            return 'at-risk';
        }
        if ( $f >= 3 ) {
            return 'hibernating';
        }

        return 'lost';
    }

    /**
     * Quintile scores for a set of raw values: ascending order, five
     * equal buckets by position, ties collapsing onto the first
     * position's score so equal values can never split across scores.
     * The position+1 form keeps the top bucket reachable at any
     * population size (a lone maximum still scores 5). Keys are
     * canonical strings: a monetary axis holds floats, and a float
     * array key silently degrades to an int on PHP 8.1+.
     *
     * @param array<int, int|float> $values Raw axis values.
     * @return array<string, int> value key => score 1..5.
     */
    private static function quintile_scores( array $values ): array {
        $count = count( $values );
        if ( 0 === $count ) {
            return array();
        }

        $sorted = $values;
        sort( $sorted, SORT_NUMERIC );

        $scores = array();
        foreach ( $sorted as $position => $raw ) {
            $key = self::axis_key( $raw );
            if ( ! array_key_exists( $key, $scores ) ) {
                $scores[ $key ] = max( 1, min( 5, (int) floor( ( $position + 1 ) * 5 / $count ) ) );
            }
        }

        return $scores;
    }

    /**
     * Canonical array key for one raw axis value: ints read as their
     * decimal string, floats as their two-decimal string, so equal
     * values always meet the same key on both the build and the
     * lookup side.
     *
     * @param int|float $raw Raw axis value.
     * @return string
     */
    private static function axis_key( $raw ): string {
        if ( is_float( $raw ) ) {
            return sprintf( '%.2F', $raw );
        }

        return (string) $raw;
    }
}
