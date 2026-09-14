<?php
/**
 * Lead scoring engine (ADR-0013 D3): deterministic recomputation from
 * sources. A contact's score is always derivable — rules times
 * per-day capped event counts over the 30-day window — so rule edits
 * apply retroactively, repeated passes converge, and there is no
 * incremental drift to audit. The bot verdict outranks every rule: a
 * visitor with any bot-flagged session scores 0 and carries the
 * sys:suspected_bot system tag (attach-only; v1.1 ships no in-plugin
 * removal for system tags, and the label stands while the verdict
 * stands — the page copy says exactly that).
 *
 * Three trigger faces share one code path: the daily queue rider over
 * contacts whose last_seen moved, the rule-save batch recompute
 * (queue waves, keyset-paginated), and the profile page's on-demand
 * single computation.
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
use GreenPNG\Storage\Gr_Event_Repository;
use GreenPNG\Storage\Gr_Session_Repository;

/**
 * Deterministic lead scoring: rules times per-day capped event counts
 * over the 30-day window, recomputed from sources so rule edits apply
 * retroactively and no incremental drift can build up.
 */
final class Gr_Scoring_Engine {

    /** Scoring window, aligned with the event retention window (ADR-0013 D3 note). */
    public const WINDOW_DAYS = 30;

    /** Queue hook for the rule-save batch recompute. */
    public const RECOMPUTE_HOOK = 'gr_scoring_recompute_all';

    /** Daily rider over recently active contacts: a slightly padded day absorbs cron drift. */
    public const ACTIVE_WINDOW_HOURS = 25;

    /** Wave size for the batch recompute. */
    public const WAVE = 500;

    /** System tag slug for the bot verdict (ADR-0013 D3). */
    public const BOT_TAG = 'sys:suspected_bot';

    /**
     * Hook registration: the daily rider runs after the aggregator
     * (priority 5) and before retention trimming (priority 10), so the
     * pass always sees the full 30-day window it scores over.
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( Gr_Queue::DAILY_HOOK, array( self::class, 'run_daily' ), 8, 0 );
        add_action( self::RECOMPUTE_HOOK, array( self::class, 'recompute_all' ), 10, 1 );
    }

    /**
     * Daily pass: every contact whose last_seen moved inside the
     * padded day. Contacts that stay quiet keep their last score —
     * the window only shrinks under them through rule edits (which
     * trigger the full recompute) or their next activity.
     *
     * @return void
     */
    public static function run_daily(): void {
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - self::ACTIVE_WINDOW_HOURS * HOUR_IN_SECONDS );
        $ids    = ( new Gr_Contact_Repository() )->ids_active_since( $cutoff );

        foreach ( $ids as $id ) {
            self::recompute_contact( $id );
        }
    }

    /**
     * Batch recompute after a ruleset change, in queue waves: each run
     * scores one wave and re-enqueues from the last id, so a large
     * contact table never blocks one queue slot.
     *
     * @param int $after Keyset cursor: the last contact id of the
     *                   previous wave, 0 from the start.
     * @return void
     */
    public static function recompute_all( int $after = 0 ): void {
        $ids = ( new Gr_Contact_Repository() )->all_ids( self::WAVE, $after );

        foreach ( $ids as $id ) {
            self::recompute_contact( $id );
        }

        if ( self::WAVE === count( $ids ) ) {
            Gr_Queue::enqueue( self::RECOMPUTE_HOOK, array( (int) end( $ids ) ) );
        }
    }

    /**
     * Recomputes and stores one contact's lead score. The return is
     * the stored value, so the profile page can show what landed.
     *
     * @param int $contact_id Contact row id.
     * @return int The stored score.
     */
    public static function recompute_contact( int $contact_id ): int {
        $contacts = new Gr_Contact_Repository();
        $row      = $contacts->row_for_id( $contact_id );
        if ( null === $row ) {
            return 0;
        }

        $visitor = isset( $row['visitor_id'] ) ? (string) $row['visitor_id'] : '';

        // No cookie-track linkage: nothing to score from, and nothing
        // to force — an honest 0 rather than a guess.
        if ( '' === $visitor ) {
            $contacts->set_score( $contact_id, 0 );

            return 0;
        }

        // The bot verdict outranks every rule (ADR-0013 D3, docs/12).
        if ( ( new Gr_Session_Repository() )->is_bot_for_visitor( $visitor ) ) {
            $contacts->attach_tag(
                $contact_id,
                self::BOT_TAG,
                __( 'Suspected bot', 'greenpng' ),
                true
            );
            $contacts->set_score( $contact_id, 0 );

            return 0;
        }

        $rules = Gr_Scoring_Rules::active();
        if ( array() === $rules ) {
            $contacts->set_score( $contact_id, 0 );

            return 0;
        }

        $events = ( new Gr_Event_Repository() )->daily_counts_for_visitor( $visitor, self::WINDOW_DAYS );
        $sales  = ( new Gr_Conversion_Repository() )->daily_counts_for_visitor( $visitor, self::WINDOW_DAYS );

        $raw = 0;
        foreach ( $rules as $rule ) {
            $days = ( 'conversion' === $rule['event_name'] )
                ? $sales
                : ( isset( $events[ $rule['event_name'] ] ) ? $events[ $rule['event_name'] ] : array() );

            $capped = 0;
            foreach ( $days as $count ) {
                $capped += min( (int) $count, $rule['daily_cap'] );
            }

            $raw += $rule['points'] * $capped;
        }

        $score = max( 0, min( 100, $raw ) );
        $contacts->set_score( $contact_id, $score );

        return $score;
    }
}
