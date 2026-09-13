<?php
/**
 * Action Scheduler symbol stubs — STATIC ANALYSIS ONLY.
 *
 * The plugin calls these behind function_exists() and adapts to
 * whichever backend the host loaded (Gr_Queue::uses_action_scheduler(),
 * ADR-0007). The phpunit runtime deliberately never loads this file:
 * with the symbols absent, the suite exercises the WP-Cron fallback
 * path, which is the environment every non-WooCommerce host runs. The
 * WooCommerce-host path is covered live against the dev site instead.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

if ( ! function_exists( 'as_schedule_single_action' ) ) {
    /**
     * Action Scheduler single-action enqueue.
     *
     * @param int                 $timestamp When to run.
     * @param string              $hook      Hook name.
     * @param array<array-key, mixed> $args  Hook arguments.
     * @param string              $group     Group key.
     * @return int Action id.
     */
    function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '' ): int {
        return 0;
    }
}

if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
    /**
     * Action Scheduler bulk unschedule.
     *
     * @param string              $hook  Hook name.
     * @param array<array-key, mixed> $args Hook arguments.
     * @param string              $group Group key.
     * @return void
     */
    function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): void {
    }
}
