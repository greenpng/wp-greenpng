<?php
/**
 * Event dispatch service (docs/02 §2.2, docs/03 §2): constructs the DTO,
 * persists it when persistence is enabled for the event, and always fires
 * the native 'gr_event' hook with the DTO as its single argument. Business
 * logic subscribes with plain add_action, never through this class.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Storage\Gr_Event_Repository;

/**
 * Orchestrates one event dispatch: DTO, optional persistence, native hook.
 * Business logic never calls this class directly; it arrives through the
 * gr_dispatch_event()/gr_get_recent_events() facades or subscribes to
 * 'gr_event' with add_action (docs/02 §2.2).
 */
final class Gr_Event_Dispatcher {

    /**
     * Write side of the event stream.
     *
     * @var Gr_Event_Repository
     */
    private Gr_Event_Repository $repository;

    /**
     * Wires the write side of the event stream.
     *
     * @param Gr_Event_Repository $repository Injected for testability.
     */
    public function __construct( Gr_Event_Repository $repository ) {
        $this->repository = $repository;
    }

    /**
     * Dispatches one event: DTO construction, optional persistence, then
     * the native hook — in that order, so subscribers observe the event
     * exactly as persisted (row id included when it was written).
     *
     * Persistence is enabled unless the 'gr_persist_event' filter opts
     * out for the event type (docs/03 §2 "enabled for persistence").
     *
     * @param string               $name    Event name.
     * @param array<string, mixed> $payload Event payload.
     * @return Gr_Event The dispatched event.
     */
    public function dispatch( string $name, array $payload = array() ): Gr_Event {
        $event = Gr_Event::create( $name, $payload );

        if ( apply_filters( 'gr_persist_event', true, $event ) ) {
            $event->mark_persisted( $this->repository->insert( $event ) );
        }

        do_action( 'gr_event', $event );

        return $event;
    }

    /**
     * Recent events, newest first; payload_json comes back decoded under
     * a 'payload' key.
     *
     * @param string $name  Optional event-name filter.
     * @param int    $limit Row ceiling, clamped to a sane range.
     * @return array<int, array<string, mixed>>
     */
    public function recent( string $name = '', int $limit = 50 ): array {
        return $this->repository->recent( $name, $limit );
    }
}
