<?php
/**
 * Adaptive async queue facade (ADR-0007): work is dispatched through
 * Action Scheduler when the host already provides it, and through WP-Cron
 * with a transient mutex otherwise, so no scheduler is ever bundled.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The only async dispatch path in the plugin (iron rule 3 forbids
 * synchronous wp_remote_* during front-end requests).
 */
final class Gr_Queue {

    /**
     * Public daily maintenance hook (docs/05 §5); maintenance job
     * subscribers attach here and always run inside the mutex.
     */
    public const DAILY_HOOK = 'gr_cron_daily_maintenance';

    /**
     * Internal recurring event that triggers the daily routine. Kept
     * separate from DAILY_HOOK so the routine always runs inside the
     * mutex regardless of whether cron or the CLI triggered it, and so
     * firing the public hook can never recurse.
     */
    public const EVENT_HOOK = 'gr_queue_daily_event';

    /** Action Scheduler group for every plugin action, for traceability and bulk cleanup. */
    public const AS_GROUP = 'greenpng';

    /** Mutex lifetime: a crashed holder may block re-entry at most this long. */
    public const LOCK_TTL = 300;

    /**
     * Registers the daily runner and heals a lost schedule; called on
     * every request, front-end safe because the schedule check reads an
     * already-autoloaded option and re-scheduling only happens when the
     * event is missing.
     *
     * @return void
     */
    public static function boot(): void {
        add_action( self::EVENT_HOOK, array( self::class, 'run_daily' ) );
        self::ensure_daily();
    }

    /**
     * Schedules the recurring daily event when missing.
     *
     * @return void
     */
    public static function ensure_daily(): void {
        if ( wp_next_scheduled( self::EVENT_HOOK ) ) {
            return;
        }

        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::EVENT_HOOK );
    }

    /**
     * Dispatches one unit of async work.
     *
     * @param string                  $hook  Work hook fired on execution.
     * @param array<array-key, mixed> $args  Arguments delivered with the hook; action arguments are positional lists by convention, so both list and keyed shapes are accepted.
     * @param int                     $delay Seconds from now; retry backoff uses this.
     * @return void
     */
    public static function enqueue( string $hook, array $args = array(), int $delay = 0 ): void {
        $when = time() + max( 0, $delay );

        if ( self::uses_action_scheduler() ) {
            as_schedule_single_action( $when, $hook, $args, self::AS_GROUP );
            return;
        }

        wp_schedule_single_event( $when, $hook, $args );
    }

    /**
     * Which backend a dispatch would use right now; surfaced on the status
     * page so the owner can see why dispatch timing differs between sites.
     *
     * @return string 'action-scheduler' or 'wp-cron'.
     */
    public static function backend(): string {
        return self::uses_action_scheduler() ? 'action-scheduler' : 'wp-cron';
    }

    /**
     * Whether the host already loaded Action Scheduler (every WooCommerce
     * site does). The plugin never bundles it (GPLv3, docs/02 §2.4), so
     * this is a runtime fact, not a dependency.
     *
     * @return bool
     */
    private static function uses_action_scheduler(): bool {
        return function_exists( 'as_schedule_single_action' );
    }

    /**
     * Runs the daily routine once under the mutex; the cron event and the
     * CLI command both land here, so the routine cannot overlap itself.
     *
     * @return bool True when the routine ran, false when the lock was busy.
     */
    public static function run_daily(): bool {
        return self::with_mutex(
            'daily',
            static function (): void {
                do_action( self::DAILY_HOOK );
            }
        );
    }

    /**
     * Runs the callable while holding a transient mutex. WP-Cron lacks
     * Action Scheduler's concurrency locks, so the fallback backend needs
     * its own guard; a crashed holder self-releases after LOCK_TTL.
     *
     * @param string   $scope Mutex scope, e.g. 'daily'.
     * @param callable $work  Executed only while the lock is held.
     * @return bool True when $work ran; false when the lock was busy.
     */
    public static function with_mutex( string $scope, callable $work ): bool {
        $lock = 'gr_queue_lock_' . $scope;

        if ( false !== get_transient( $lock ) ) {
            return false;
        }

        set_transient( $lock, time(), self::LOCK_TTL );

        try {
            $work();
        } finally {
            delete_transient( $lock );
        }

        return true;
    }

    /**
     * Removes every trace the queue can leave behind on deactivation:
     * both schedules, the mutex, and the plugin's pending AS actions.
     *
     * @return void
     */
    public static function teardown(): void {
        wp_clear_scheduled_hook( self::DAILY_HOOK );
        wp_clear_scheduled_hook( self::EVENT_HOOK );
        delete_transient( 'gr_queue_lock_daily' );

        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( '', array(), self::AS_GROUP );
        }
    }
}
