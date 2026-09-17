<?php
/**
 * A/B exposure and conversion recording (docs/03 §5): one facade over
 * the event stream — records land in gr_events with the dedicated
 * ab_* columns, exactly like every other event, so A/B data inherits
 * the stream's retention and aggregation story instead of growing a
 * private counter table.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Funnel;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Impression/conversion event writer for running experiments.
 */
final class Gr_Ab_Recorder {

    /** Record types the stream accepts. */
    public const TYPES = array( 'impression', 'conversion' );

    /** Event name for both record types; ab_type carries the kind. */
    public const EVENT_NAME = 'ab';

    /** Event group for A/B records. */
    public const EVENT_GROUP = 'funnel';

    /**
     * Records one exposure or conversion against a defined
     * experiment. Paused experiments keep recording: visitors already
     * bucketed before a pause still convert, and losing those events
     * would silently understate every variant.
     *
     * @param string $experiment Experiment key.
     * @param string $variant    Variant slug.
     * @param string $type       'impression' or 'conversion'.
     * @return bool True when the event row landed.
     */
    public static function record( string $experiment, string $variant, string $type ): bool {
        if ( ! in_array( $type, self::TYPES, true ) ) {
            return false;
        }

        $definition = Gr_Ab_Experiments::get( $experiment );
        if ( null === $definition || ! is_array( $definition['variants'] ) ) {
            return false;
        }

        $key  = sanitize_key( $experiment );
        $slug = sanitize_key( $variant );
        if ( '' === $key || '' === $slug || ! in_array( $slug, $definition['variants'], true ) ) {
            return false;
        }

        $identity = gr()->identity();
        $event    = gr_dispatch_event(
            self::EVENT_NAME,
            array(
                'event_group'   => self::EVENT_GROUP,
                'visitor_id'    => $identity->visitor_id(),
                'session_id'    => $identity->session_id(),
                'ab_experiment' => $key,
                'ab_variant'    => $slug,
                'ab_type'       => $type,
            )
        );

        return $event->persisted_id() > 0;
    }
}
