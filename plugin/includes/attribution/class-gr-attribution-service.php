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
     * amount and currency are recorded as given by the source.
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

        return $this->conversions->bind(
            $source_type,
            $source_id,
            $visitor_id,
            gr()->identity()->session_id(),
            $amount,
            $currency,
            $first,
            $last,
            (string) wp_json_encode( $models )
        );
    }
}
