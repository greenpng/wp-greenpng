<?php
/**
 * Funnel journey tracker (ADR-0014 D2): mounted on the 'gr_event'
 * bus, it advances per-session journeys through the active funnel
 * definitions. The match work happens in memory over the memoized
 * definitions; the only statement per event per funnel is one
 * guarded upsert, so the bus never grows a read before its write.
 * Progression is strictly sequential and forward-only: a step
 * counts only for the session that reached the step before it, and
 * nothing can move a session backwards.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Funnel;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Database;
use GreenPNG\Core\Gr_Event;
use GreenPNG\Storage\Gr_Funnel_Repository;

/**
 * Journey-state writer over gr_funnel_sessions, mounted on the event bus.
 */
final class Gr_Funnel_Tracker {

    /**
     * Hook registration. Priority 10 keeps the tracker alongside
     * every other subscriber; the event row has already landed by
     * the time the bus fires.
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( 'gr_event', array( __CLASS__, 'on_event' ), 10, 1 );
    }

    /**
     * One bus event: match it against every active funnel and upsert
     * the session's journey for each match. Events without a session
     * identity have no journey to advance.
     *
     * @param Gr_Event $event The dispatched event.
     * @return void
     */
    public static function on_event( Gr_Event $event ): void {
        $session = $event->session_id();
        if ( '' === $session ) {
            return;
        }

        $funnels = ( new Gr_Funnel_Repository() )->active();
        if ( array() === $funnels ) {
            return;
        }

        $name    = $event->name();
        $payload = $event->payload();
        $path    = isset( $payload['path'] ) && is_scalar( $payload['path'] ) ? (string) $payload['path'] : '';

        foreach ( $funnels as $funnel ) {
            $steps = isset( $funnel['steps'] ) && is_array( $funnel['steps'] ) ? $funnel['steps'] : array();
            if ( array() === $steps ) {
                continue;
            }

            $matched = self::first_matching_step( $steps, $name, $path );
            if ( 0 === $matched ) {
                continue;
            }

            self::upsert(
                (int) $funnel['id'],
                $session,
                $event->visitor_id(),
                $matched,
                count( $steps ),
                $event->created_at()
            );
        }
    }

    /**
     * The earliest step this event satisfies. Steps should match
     * distinct conditions; when one event matches several, the
     * earliest is the honest pick — a later step still needs its own
     * event after the session advances.
     *
     * Event-kind steps compare by name: the event vocabulary is
     * closed, so a prefix compare adds nothing beyond the exact
     * match. URL-kind steps only apply to pageviews, compared
     * against the payload path exactly as the collect endpoint
     * recorded it.
     *
     * @param array<int, mixed> $steps Step flow.
     * @param string            $name  Event name.
     * @param string            $path  Pageview path, '' otherwise.
     * @return int One-based step number, 0 when nothing matches.
     */
    private static function first_matching_step( array $steps, string $name, string $path ): int {
        foreach ( $steps as $index => $step ) {
            if ( ! is_array( $step ) ) {
                continue;
            }

            $match = isset( $step['match'] ) && is_array( $step['match'] ) ? $step['match'] : array();
            $kind  = isset( $match['kind'] ) ? (string) $match['kind'] : '';
            $value = isset( $match['value'] ) ? (string) $match['value'] : '';

            if ( '' === $value ) {
                continue;
            }

            if ( 'event' === $kind ) {
                if ( $name === $value ) {
                    return $index + 1;
                }
                continue;
            }

            if ( 'url' !== $kind || 'pageview' !== $name || '' === $path ) {
                continue;
            }

            $compare = isset( $match['compare'] ) ? (string) $match['compare'] : 'exact';
            $hit     = 'prefix' === $compare ? str_starts_with( $path, $value ) : ( $path === $value );

            if ( $hit ) {
                return $index + 1;
            }
        }

        return 0;
    }

    /**
     * The one-statement journey upsert. The guard is the sequential
     * rule itself: the incoming step advances the session only when
     * it is exactly current + 1; anything else (a re-hit, a skip, a
     * regression) leaves the position alone. completed_at is
     * assigned first so its guard reads the position as it was
     * before this statement, and max_step reads it after — MySQL
     * evaluates ON DUPLICATE KEY UPDATE assignments in order.
     *
     * @param int    $funnel_id   Funnel definition id.
     * @param string $session_id  Session identity.
     * @param string $visitor_id  Visitor identity, '' when absent.
     * @param int    $step        One-based matched step.
     * @param int    $total       Total steps in the funnel.
     * @param string $when        Event timestamp, site time.
     * @return void
     */
    private static function upsert( int $funnel_id, string $session_id, string $visitor_id, int $step, int $total, string $when ): void {
        global $wpdb;

        $table = Gr_Database::table( 'funnel_sessions' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bus write bounded by active funnel count; the guarded upsert is the idempotency boundary.
        $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; the SQL is the prepare() output below.
                "INSERT INTO {$table}
                    (funnel_id, session_id, visitor_id, current_step, max_step, entered_at, last_step_at, completed_at)
                VALUES (%d, %s, %s, %d, %d, %s, %s, IF(%d = %d, %s, NULL))
                ON DUPLICATE KEY UPDATE
                    completed_at = COALESCE(completed_at, IF(%d = %d AND %d = current_step + 1, %s, NULL)),
                    visitor_id = IF(VALUES(visitor_id) <> '', VALUES(visitor_id), visitor_id),
                    current_step = IF(VALUES(current_step) = current_step + 1, VALUES(current_step), current_step),
                    max_step = GREATEST(max_step, current_step),
                    last_step_at = VALUES(last_step_at)",
                array(
                    $funnel_id,
                    $session_id,
                    $visitor_id,
                    $step,
                    $step,
                    $when,
                    $when,
                    $total,
                    $step,
                    $when,
                    $total,
                    $step,
                    $step,
                    $when,
                )
            )
        );
    }
}
