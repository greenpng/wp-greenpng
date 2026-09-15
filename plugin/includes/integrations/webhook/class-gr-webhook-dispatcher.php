<?php
/**
 * Webhook bus subscriber (ADR-0016 D3): the one place a bus event
 * fans out to owner-configured endpoints. The subscriber never
 * touches the wire — it snapshots the event DTO into the queue, so
 * the outbound attempt happens in the job's own request, outside
 * the visitor's page load (铁律 3). Endpoints whose circuit is open
 * or that are paused simply do not match; a deleted endpoint's
 * pending jobs no-op in the delivery body.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Integrations\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Event;
use GreenPNG\Core\Gr_Queue;

/**
 * Fan-out from gr_event to per-endpoint queue snapshots.
 */
final class Gr_Webhook_Dispatcher {

    /**
     * Hook registration: the bus subscription plus the delivery hook
     * the queue fires.
     *
     * @return void
     */
    public static function register(): void {
        add_action( 'gr_event', array( self::class, 'on_event' ) );
        add_action( Gr_Webhook_Delivery::HOOK, array( Gr_Webhook_Delivery::class, 'run' ) );
    }

    /**
     * Fans one bus event out to every matching endpoint. Events
     * outside every endpoint's vocabulary cost one option read and
     * nothing else.
     *
     * @param Gr_Event $event Bus DTO.
     * @return void
     */
    public static function on_event( Gr_Event $event ): void {
        $endpoints = Gr_Webhook_Repository::matching( $event->name() );
        if ( array() === $endpoints ) {
            return;
        }

        // The snapshot is the whole delivery: the queue job may run
        // in a later request where the bus, the consent, and the
        // visitor state are all gone, so nothing may be looked up
        // from the live request again (same discipline the GA4 and
        // Meta forwarders follow).
        $snapshot = array(
            'name'       => $event->name(),
            'group'      => $event->group(),
            'visitor_id' => $event->visitor_id(),
            'session_id' => $event->session_id(),
            'payload'    => $event->payload(),
        );

        foreach ( $endpoints as $endpoint ) {
            Gr_Queue::enqueue(
                Gr_Webhook_Delivery::HOOK,
                array(
                    array(
                        'endpoint' => (int) $endpoint['id'],
                        'attempt'  => 0,
                        'event'    => $snapshot,
                    ),
                ),
                0
            );
        }
    }
}
