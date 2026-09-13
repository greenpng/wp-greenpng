<?php
/**
 * Child process for the queue's Action-Scheduler-presence tests.
 *
 * The suite's single process cannot host an as_schedule_single_action()
 * definition without poisoning the AS-absent world every other test
 * exercises, and PHPUnit's own @runInSeparateProcess machinery is
 * unusable on this repo's local runtime (the spawned child re-executes
 * phpunit itself and self-forks without bound). This script is the
 * isolation instead: a plain CLI child, booted from the suite
 * bootstrap, with the AS stand-in installed before one enqueue runs.
 * It reports the observable state on stdout; the parent test asserts
 * on the report.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

// Deliberately NO namespace: the stand-in must land in the global
// symbol table, where the plugin's unqualified call resolves it.

require dirname( __DIR__ ) . '/bootstrap.php';

if ( ! function_exists( 'as_schedule_single_action' ) ) {
	/**
	 * AS stand-in whose verdict the parent test controls by argument.
	 *
	 * @param int                     $timestamp When to run.
	 * @param string                  $hook      Hook name.
	 * @param array<int|string,mixed> $args      Hook arguments.
	 * @param string                  $group     Group key.
	 * @return int Action id; 0 models the zero-id refusal a fresh
	 *                WooCommerce host reports before its installer
	 *                creates the scheduler tables.
	 * @throws RuntimeException When the throw mode is armed: AS 3.x's
	 *                           DB store answers a failed insert by
	 *                           throwing, not by returning.
	 */
	function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ) {
		if ( ! empty( $GLOBALS['gr_stub_as_throw'] ) ) {
			throw new RuntimeException( 'Error saving action: wp_actionscheduler_actions does not exist' );
		}
		return (int) ( $GLOBALS['gr_stub_as_return'] ?? 0 );
	}
}

$mode = ( isset( $argv[1] ) ) ? (string) $argv[1] : 'refuse';
$GLOBALS['gr_stub_as_return'] = ( 'accept' === $mode ) ? 41 : 0;
$GLOBALS['gr_stub_as_throw']  = ( 'throw' === $mode );

\GreenPNG\Core\Gr_Queue::enqueue( 'gr_crawler_verify', array( '127.0.0.1', 'Googlebot' ) );

echo 'backend=' . \GreenPNG\Core\Gr_Queue::backend() . "\n";
echo 'cron_events=' . count( $GLOBALS['gr_stub_cron'] ) . "\n";
foreach ( $GLOBALS['gr_stub_cron'] as $event ) {
	echo 'cron_hook=' . (string) $event['hook'] . "\n";
	echo 'cron_args=' . json_encode( $event['args'] ) . "\n";
}
