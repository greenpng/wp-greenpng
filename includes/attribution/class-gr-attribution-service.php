<?php
/**
 * Attribution service (docs/02 §4): composes the permanent conversion
 * binding — the visitor's touchpoint chain feeds the five models, the
 * split travels with the conversion row, and the repository's UNIQUE
 * key makes replays harmless. Callers own the consent gate; in v1.0
 * only cookie-track visitor ids reach this service (ADR-0005).
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Attribution;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Queue;
use GreenPNG\Storage\Gr_Audit_Repository;
use GreenPNG\Storage\Gr_Conversion_Repository;
use GreenPNG\Storage\Gr_Touchpoint_Repository;

/**
 * Conversion binding composition.
 */
final class Gr_Attribution_Service {

    /** Lookback window for the binding chain, matching the cookie life. */
    private const LOOKBACK_DAYS = 30;

    /**
     * Touchpoints repository.
     *
     * @var Gr_Touchpoint_Repository
     */
    private Gr_Touchpoint_Repository $touchpoints;

    /**
     * Conversions repository.
     *
     * @var Gr_Conversion_Repository
     */
    private Gr_Conversion_Repository $conversions;

    /**
     * Wires the repositories.
     *
     * @param Gr_Touchpoint_Repository $touchpoints Touchpoints repository.
     * @param Gr_Conversion_Repository $conversions Conversions repository.
     */
    public function __construct( Gr_Touchpoint_Repository $touchpoints, Gr_Conversion_Repository $conversions ) {
        $this->touchpoints = $touchpoints;
        $this->conversions = $conversions;
    }

    /**
     * Binds one conversion source to the visitor's attribution state.
     * The current session identity rides along for funnel joins; the
     * amount and currency are recorded as given by the source. A
     * successful bind also lands a 'conversion' event on the bus —
     * the event vocabulary's funnel arm — so funnel journeys can end
     * on a conversion step exactly as ADR-0014 D1 draws the closed
     * vocabulary. Replayed callbacks dispatch again: the binding
     * table dedupes by source, the event row is the honest record
     * that the callback arrived, and the funnel upsert is guarded.
     *
     * @param int    $source_id   Order or form submission id.
     * @param string $visitor_id  Visitor identity (cookie track).
     * @param float  $amount      Conversion amount.
     * @param string $currency    Three-letter currency code.
     * @param string $source_type 'woocommerce' or a form adapter id.
     * @return int Bound row id, existing id on replay, 0 on failure.
     */
    public function bind( int $source_id, string $visitor_id, float $amount, string $currency, string $source_type = 'woocommerce' ): int {
        $sequence = $this->touchpoints->get_for_visitor( $visitor_id, self::LOOKBACK_DAYS );
        $models   = Gr_Attribution_Models::calculate( $sequence, $amount );

        $first = array() !== $sequence ? (int) $sequence[0]['id'] : 0;
        $last  = array() !== $sequence ? (int) end( $sequence )['id'] : 0;

        $session = gr()->identity()->session_id();
        $bound   = $this->conversions->bind(
            $source_type,
            $source_id,
            $visitor_id,
            $session,
            $amount,
            $currency,
            $first,
            $last,
            (string) wp_json_encode( $models )
        );

        if ( $bound > 0 ) {
            gr_dispatch_event(
                'conversion',
                array(
                    'event_group' => 'funnel',
                    'visitor_id'  => $visitor_id,
                    'session_id'  => $session,
                    'source_type' => $source_type,
                    'source_id'   => $source_id,
                    'amount'      => $amount,
                    'currency'    => $currency,
                )
            );
        }

        return $bound;
    }

    /**
     * Reverses a bound conversion (ADR-0010): soft-mark only, the
     * amount stays as bound. When this call performed the reversal,
     * the conversion date's aggregate rows are queued for a targeted
     * recompute — old dates are safe because gr_conversions keeps its
     * rows permanently while the recompute itself only touches the
     * conversions/revenue metrics.
     *
     * @param string $source_type Source type.
     * @param int    $source_id   Source id.
     * @return bool True when this call reversed the row.
     */
    public function reverse_source( string $source_type, int $source_id ): bool {
        $state = $this->conversions->reversal_state_for_source( $source_type, $source_id );

        if ( null === $state || 'reversed' === $state['status'] ) {
            return false;
        }

        if ( ! $this->conversions->reverse_for_source( $source_type, $source_id ) ) {
            return false;
        }

        $this->after_reversal_change( $source_type, $source_id, $state, 'reversed' );

        return true;
    }

    /**
     * Converges a partially refunded order's stored amount to the
     * order's remaining total (ADR-0010 D2). Convergence means a
     * replayed refund hook can never compound; only a real change
     * triggers the recompute and audit trail.
     *
     * @param string $source_type Source type.
     * @param int    $source_id   Source id.
     * @param float  $remaining   Order's remaining total, >= 0.
     * @return bool True when the stored amount changed.
     */
    public function apply_partial_refund( string $source_type, int $source_id, float $remaining ): bool {
        $state = $this->conversions->reversal_state_for_source( $source_type, $source_id );

        if ( null === $state || 'reversed' === $state['status'] ) {
            return false;
        }

        if ( ! $this->conversions->converge_amount_for_source( $source_type, $source_id, $remaining ) ) {
            return false;
        }

        $this->after_reversal_change( $source_type, $source_id, $state, 0.0 === $remaining ? 'reversed' : 'partial' );

        return true;
    }

    /**
     * Shared post-change work: queue the targeted date recompute and
     * leave the audit trail. The date comes from the pre-change state
     * read, so a later re-read cannot drift under a concurrent write.
     *
     * @param string                                                             $source_type Source type.
     * @param int                                                                $source_id   Source id.
     * @param array{id: int, status: string, amount: string, created_at: string} $before Pre-change state.
     * @param string                                                             $kind        'reversed' or 'partial'.
     * @return void
     */
    private function after_reversal_change( string $source_type, int $source_id, array $before, string $kind ): void {
        Gr_Queue::enqueue( 'gr_recompute_conversion_date', array( substr( $before['created_at'], 0, 10 ) ) );

        $after = array(
            'status' => 'reversed' === $kind ? 'reversed' : $before['status'],
            'amount' => 'reversed' === $kind ? $before['amount'] : null,
        );

        ( new Gr_Audit_Repository() )->log(
            'reversed' === $kind ? 'conversion_reversed' : 'conversion_partial_refund',
            'conversion',
            (string) $before['id'],
            array(
                'source_type' => $source_type,
                'source_id'   => $source_id,
                'status'      => $before['status'],
                'amount'      => $before['amount'],
            ),
            array(
                'source_type' => $source_type,
                'source_id'   => $source_id,
                'status'      => $after['status'],
                'amount'      => null === $after['amount'] ? '(converged to remaining)' : $after['amount'],
            ),
            get_current_user_id()
        );
    }
}
