<?php
/**
 * Central Notification Hub (ADR-0019, docs/21): routes bus events (gr_event)
 * to subscribed delivery channels (Email with built-in native SMTP, plus
 * Webhooks / Messaging bots).
 *
 * Provides a clean separation of concerns between business event producers
 * and outbound delivery transports, enforcing asynchronous queue execution
 * and user-defined subscription filters.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Notification;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Event;
use GreenPNG\Core\Gr_Queue;
use GreenPNG\Core\Gr_Settings;

/**
 * Event-to-channel subscription router.
 */
final class Gr_Notification_Hub {

    /** Supported notification events. */
    public const EVENT_CONVERSION  = 'conversion';
    public const EVENT_LEAD        = 'lead';
    public const EVENT_LOCKOUT     = 'security_lockout';
    public const EVENT_LOCKOUT_ALT = 'lockout';

    /** Closed vocabulary of subscribable notification events. */
    public const VOCABULARY = array(
        self::EVENT_CONVERSION,
        self::EVENT_LEAD,
        self::EVENT_LOCKOUT,
        self::EVENT_LOCKOUT_ALT,
    );

    /**
     * Registers the event bus listener and initializes delivery channels.
     *
     * @return void
     */
    public static function register(): void {
        add_action( 'gr_event', array( __CLASS__, 'on_event' ), 20, 1 );
        Gr_Email_Channel::register();
    }

    /**
     * Handles one event from the native 'gr_event' bus.
     *
     * @param Gr_Event $event Event DTO.
     * @return void
     */
    public static function on_event( Gr_Event $event ): void {
        $raw_name = $event->name();
        if ( ! in_array( $raw_name, self::VOCABULARY, true ) ) {
            return;
        }

        $event_name = ( self::EVENT_LOCKOUT_ALT === $raw_name ) ? self::EVENT_LOCKOUT : $raw_name;
        $settings   = function_exists( 'gr' ) ? gr()->settings() : new Gr_Settings();

        // 1. Email Channel Dispatch
        $email_enabled     = 1 === (int) $settings->get( 'notify_email_enabled', 0 );
        $subscribed_events = (array) $settings->get( 'notify_email_events', array( self::EVENT_CONVERSION, self::EVENT_LOCKOUT ) );

        $is_subscribed = in_array( $event_name, $subscribed_events, true )
            || ( self::EVENT_LOCKOUT === $event_name && in_array( self::EVENT_LOCKOUT_ALT, $subscribed_events, true ) );

        if ( $email_enabled && $is_subscribed ) {
            $raw_recipients = (string) $settings->get( 'notify_email_recipients', '' );
            $recipients     = self::parse_recipients( $raw_recipients );

            if ( ! empty( $recipients ) ) {
                // One positional argument carrying the whole job, the
                // queue's positional convention (webhook delivery
                // rides the same shape): a keyed args array would be
                // spread into positional hook values and the worker
                // would receive the event name string instead of the
                // job array. The visitor reference rides the job
                // because the event object lifts it out of the
                // payload as context — the card's CRM walk-back
                // needs it, and the bus's own columns already carry
                // it.
                Gr_Queue::enqueue(
                    Gr_Email_Channel::HOOK_SEND,
                    array(
                        array(
                            'event'      => $event_name,
                            'payload'    => $event->payload(),
                            'visitor_id' => $event->visitor_id(),
                            'recipients' => $recipients,
                        ),
                    ),
                    0
                );
            }
        }
    }

    /**
     * Worker proxy for email queue processing.
     *
     * @param array<string, mixed> $job_args Queued email arguments.
     * @return bool
     */
    public static function process_email_queue( array $job_args ): bool {
        return Gr_Email_Channel::send( $job_args );
    }

    /**
     * Parses a comma-separated string into an array of validated email addresses.
     *
     * @param string $input Comma-separated emails.
     * @return array<int, string>
     */
    public static function parse_recipients( string $input ): array {
        $raw_list = explode( ',', $input );
        $clean    = array();

        foreach ( $raw_list as $email ) {
            $email = sanitize_email( trim( $email ) );
            if ( '' !== $email && is_email( $email ) ) {
                $clean[] = $email;
            }
        }

        if ( empty( $clean ) ) {
            $admin_email = sanitize_email( (string) get_option( 'admin_email' ) );
            if ( '' !== $admin_email && is_email( $admin_email ) ) {
                $clean[] = $admin_email;
            }
        }

        return array_values( array_unique( $clean ) );
    }
}
