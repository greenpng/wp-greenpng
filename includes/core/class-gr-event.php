<?php
/**
 * Event DTO (docs/02 §2.2): one plain value object carried on the native
 * 'gr_event' hook. Context keys are lifted out of the payload into
 * dedicated columns at construction so payload_json never duplicates
 * column data. PHP 7.4 has no readonly, so immutability is private
 * properties plus getters, with one documented package-internal setter
 * for the persisted row id.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Event value object traveling on the 'gr_event' hook (docs/03 §2): the
 * return type of gr_dispatch_event() and the single argument every
 * subscriber receives.
 */
final class Gr_Event {

    /**
     * Payload keys lifted into dedicated columns (docs/05 gr_events); the
     * same list drives the width table below, which a unit test keeps in
     * lockstep.
     */
    private const CONTEXT_KEYS = array(
        'visitor_id',
        'session_id',
        'event_id',
        'event_group',
        'ab_experiment',
        'ab_variant',
        'ab_type',
    );

    /**
     * Column widths (docs/05 gr_events). Values are clamped to these so
     * one oversized field can never fail the whole insert under a strict
     * SQL mode; the REST collect schema re-validates lengths upstream.
     */
    private const CONTEXT_WIDTHS = array(
        'event_name'    => 64,
        'visitor_id'    => 64,
        'session_id'    => 36,
        'event_id'      => 64,
        'event_group'   => 32,
        'ab_experiment' => 64,
        'ab_variant'    => 32,
        'ab_type'       => 16,
    );

    /**
     * Event name; the primary key of the event vocabulary.
     *
     * @var string
     */
    private string $name = '';

    /**
     * Event group ('core' unless the payload says otherwise).
     *
     * @var string
     */
    private string $group = 'core';

    /**
     * Payload minus the lifted context keys; JSON-encoded at the
     * repository boundary only.
     *
     * @var array<string, mixed>
     */
    private array $payload = array();

    /**
     * Visitor identity, '' until the identity phase supplies one.
     *
     * @var string
     */
    private string $visitor_id = '';

    /**
     * Session identity, '' until the session phase supplies one.
     *
     * @var string
     */
    private string $session_id = '';

    /**
     * Client-supplied idempotency key, '' when absent.
     *
     * @var string
     */
    private string $event_id = '';

    /**
     * A/B experiment the event belongs to, '' outside experiments.
     *
     * @var string
     */
    private string $ab_experiment = '';

    /**
     * A/B variant, '' outside experiments.
     *
     * @var string
     */
    private string $ab_variant = '';

    /**
     * A/B record type (impression/conversion), '' outside experiments.
     *
     * @var string
     */
    private string $ab_type = '';

    /**
     * Creation stamp in site time ('Y-m-d H:i:s').
     *
     * @var string
     */
    private string $created_at = '';

    /**
     * Row id after a successful insert; 0 while unpersisted.
     *
     * @var int
     */
    private int $persisted_id = 0;

    /**
     * Constructs the DTO from a dispatch call, normalizing context keys
     * into columns and clamping every string to its column width.
     *
     * @param string               $name    Event name.
     * @param array<string, mixed> $payload Event payload.
     * @return Gr_Event
     * @throws \InvalidArgumentException When the name is empty after trim.
     */
    public static function create( string $name, array $payload = array() ): self {
        $event       = new self();
        $event->name = substr( trim( $name ), 0, self::CONTEXT_WIDTHS['event_name'] );

        if ( '' === $event->name ) {
            throw new \InvalidArgumentException( 'Event name must not be empty' );
        }

        $context        = array_intersect_key( $payload, array_flip( self::CONTEXT_KEYS ) );
        $event->payload = array_diff_key( $payload, $context );

        $event->visitor_id    = self::context_value( $context, 'visitor_id' );
        $event->session_id    = self::context_value( $context, 'session_id' );
        $event->event_id      = self::context_value( $context, 'event_id' );
        $event->ab_experiment = self::context_value( $context, 'ab_experiment' );
        $event->ab_variant    = self::context_value( $context, 'ab_variant' );
        $event->ab_type       = self::context_value( $context, 'ab_type' );

        $group             = self::context_value( $context, 'event_group' );
        $event->group      = ( '' === $group ) ? 'core' : $group;
        $event->created_at = current_time( 'mysql' );

        return $event;
    }

    /**
     * Event name.
     *
     * @return string
     */
    public function name(): string {
        return $this->name;
    }

    /**
     * Event group.
     *
     * @return string
     */
    public function group(): string {
        return $this->group;
    }

    /**
     * Payload with the lifted context keys removed.
     *
     * @return array<string, mixed>
     */
    public function payload(): array {
        return $this->payload;
    }

    /**
     * Visitor identity.
     *
     * @return string
     */
    public function visitor_id(): string {
        return $this->visitor_id;
    }

    /**
     * Session identity.
     *
     * @return string
     */
    public function session_id(): string {
        return $this->session_id;
    }

    /**
     * Client-supplied idempotency key.
     *
     * @return string
     */
    public function event_id(): string {
        return $this->event_id;
    }

    /**
     * A/B experiment name.
     *
     * @return string
     */
    public function ab_experiment(): string {
        return $this->ab_experiment;
    }

    /**
     * A/B variant.
     *
     * @return string
     */
    public function ab_variant(): string {
        return $this->ab_variant;
    }

    /**
     * A/B record type.
     *
     * @return string
     */
    public function ab_type(): string {
        return $this->ab_type;
    }

    /**
     * Creation stamp, site time.
     *
     * @return string
     */
    public function created_at(): string {
        return $this->created_at;
    }

    /**
     * Row id after a successful insert; 0 while unpersisted.
     *
     * @return int
     */
    public function persisted_id(): int {
        return $this->persisted_id;
    }

    /**
     * Records the row id after insert. Package-internal by convention:
     * only the dispatcher calls this, right after a successful insert.
     *
     * @param int $id Inserted row id.
     * @return void
     */
    public function mark_persisted( int $id ): void {
        $this->persisted_id = $id;
    }

    /**
     * Normalizes one lifted context value: non-scalars fall back to ''
     * (the REST schema upstream decides what is acceptable), scalars are
     * stringified and clamped to the column width.
     *
     * @param array<string, mixed> $context Lifted context values.
     * @param string               $key     Context key.
     * @return string
     */
    private static function context_value( array $context, string $key ): string {
        if ( ! isset( $context[ $key ] ) || ! is_scalar( $context[ $key ] ) ) {
            return '';
        }

        return substr( (string) $context[ $key ], 0, self::CONTEXT_WIDTHS[ $key ] );
    }
}
