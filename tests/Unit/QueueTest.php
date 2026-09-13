<?php
/**
 * Queue contracts: backend sniffing, dispatch routing and delay clamping,
 * daily schedule healing, mutex re-entry protection, and teardown.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Queue;
use PHPUnit\Framework\TestCase;

final class QueueTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    public function testBackendIsWpCronWhenActionSchedulerIsAbsent(): void {
        self::assertFalse( function_exists( 'as_schedule_single_action' ) );
        self::assertSame( 'wp-cron', Gr_Queue::backend() );
    }

    public function testEnqueueDispatchesToWpCronWithArgsAndDelay(): void {
        Gr_Queue::enqueue( 'gr_capi_dispatch', array( 'id' => 7 ), 30 );

        $events = $GLOBALS['gr_stub_cron'];
        self::assertCount( 1, $events );
        self::assertSame( 'gr_capi_dispatch', $events[0]['hook'] );
        self::assertSame( array( 'id' => 7 ), $events[0]['args'] );
        self::assertEqualsWithDelta( time() + 30, $events[0]['timestamp'], 2 );
    }

    public function testEnqueueClampsNegativeDelayToNow(): void {
        $before = time();
        Gr_Queue::enqueue( 'gr_work', array(), -600 );

        self::assertGreaterThanOrEqual( $before, $GLOBALS['gr_stub_cron'][0]['timestamp'] );
    }

    /**
     * Runs one enqueue inside a plain CLI child with the AS stand-in
     * installed, and returns its stdout report.
     *
     * @param string $mode 'refuse' or 'accept'.
     * @return string Report lines joined with newlines.
     */
    private function enqueue_child( string $mode ): string {
        // The interpreter is resolved from PATH, like every other PHP
        // invocation in this repo's checks: this machine's PHP build
        // reports a PHP_BINARY of argv[0] (inside phpunit that is the
        // phpunit script itself), so spawning PHP_BINARY would rerun
        // phpunit against the child script instead of executing it.
        $command = 'php ' . escapeshellarg( __DIR__ . '/as-presence-child.php' ) . ' ' . escapeshellarg( $mode );

        exec( $command, $output, $exit );
        $report = implode( "\n", $output );

        self::assertSame( 0, $exit, "child exited {$exit}: {$report}" );

        return $report;
    }

    public function testEnqueueFallsBackToWpCronWhenActionSchedulerRefuses(): void {
        // A fresh WooCommerce host: AS's API is loaded, but its
        // tables are not created yet, so every dispatch is refused
        // with a zero id — the work must ride wp-cron, not vanish.
        // The stand-in lives in a child process only, so the rest of
        // this suite keeps exercising the AS-absent world.
        $report = $this->enqueue_child( 'refuse' );

        self::assertStringContainsString( 'backend=action-scheduler', $report );
        self::assertStringContainsString( 'cron_events=1', $report );
        self::assertStringContainsString( 'cron_hook=gr_crawler_verify', $report );
        self::assertStringContainsString( 'cron_args=["127.0.0.1","Googlebot"]', $report );
    }

    public function testEnqueueFallsBackToWpCronWhenActionSchedulerThrows(): void {
        // AS 3.x's DB store answers a failed insert by throwing
        // RuntimeException, not by returning: the same refusal in a
        // different shape, and equally not allowed to kill the
        // queueing request or lose the dispatch.
        $report = $this->enqueue_child( 'throw' );

        self::assertStringContainsString( 'backend=action-scheduler', $report );
        self::assertStringContainsString( 'cron_events=1', $report );
        self::assertStringContainsString( 'cron_hook=gr_crawler_verify', $report );
    }

    public function testEnqueueStaysOnActionSchedulerWhenItAccepts(): void {
        // An id came back: the dispatch stays on AS and no wp-cron
        // event is written behind its back.
        $report = $this->enqueue_child( 'accept' );

        self::assertStringContainsString( 'backend=action-scheduler', $report );
        self::assertStringContainsString( 'cron_events=0', $report );
    }

    public function testEnsureDailySchedulesOneRecurringEventAndHealsOnlyWhenMissing(): void {
        Gr_Queue::ensure_daily();

        self::assertCount( 1, $GLOBALS['gr_stub_cron'] );
        self::assertSame( Gr_Queue::EVENT_HOOK, $GLOBALS['gr_stub_cron'][0]['hook'] );
        self::assertSame( 'daily', $GLOBALS['gr_stub_cron'][0]['recurrence'] );
        self::assertEqualsWithDelta( time() + HOUR_IN_SECONDS, $GLOBALS['gr_stub_cron'][0]['timestamp'], 2 );

        Gr_Queue::ensure_daily();
        self::assertCount( 1, $GLOBALS['gr_stub_cron'] );
    }

    public function testBootRegistersDailyRunnerAndHealsSchedule(): void {
        Gr_Queue::boot();

        $runner_registered = false;
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            if ( Gr_Queue::EVENT_HOOK === $registration['hook'] ) {
                $runner_registered = true;
            }
        }
        self::assertTrue( $runner_registered );
        self::assertCount( 1, $GLOBALS['gr_stub_cron'] );
    }

    public function testRunDailyFiresPublicHookOnceAndReleasesTheLock(): void {
        self::assertTrue( Gr_Queue::run_daily() );

        self::assertSame( array( Gr_Queue::DAILY_HOOK ), $GLOBALS['gr_stub_fired_actions'] );
        self::assertArrayNotHasKey( 'gr_queue_lock_daily', $GLOBALS['gr_stub_transients'] );
    }

    public function testMutexBlocksReentryWhileHeldAndResumesAfterRelease(): void {
        // Hold the lock the way a concurrent or crashed run would.
        set_transient( 'gr_queue_lock_daily', time(), Gr_Queue::LOCK_TTL );

        self::assertFalse( Gr_Queue::run_daily() );
        self::assertSame( array(), $GLOBALS['gr_stub_fired_actions'] );

        delete_transient( 'gr_queue_lock_daily' );
        self::assertTrue( Gr_Queue::run_daily() );
        self::assertSame( array( Gr_Queue::DAILY_HOOK ), $GLOBALS['gr_stub_fired_actions'] );
    }

    public function testWithMutexRunsArbitraryWorkAndCleansUpAfterThrowables(): void {
        $calls = 0;
        self::assertTrue(
            Gr_Queue::with_mutex(
                'scope_a',
                static function () use ( &$calls ): void {
                    $calls++;
                }
            )
        );
        self::assertSame( 1, $calls );
        self::assertArrayNotHasKey( 'gr_queue_lock_scope_a', $GLOBALS['gr_stub_transients'] );

        try {
            Gr_Queue::with_mutex(
                'scope_b',
                static function (): void {
                    throw new \RuntimeException( 'work exploded' );
                }
            );
            self::fail( 'Expected the throwable to propagate out of the mutex.' );
        } catch ( \RuntimeException $e ) {
            self::assertSame( 'work exploded', $e->getMessage() );
        }
        self::assertArrayNotHasKey( 'gr_queue_lock_scope_b', $GLOBALS['gr_stub_transients'] );
    }

    public function testTeardownClearsSchedulesMutexAndPendingEvents(): void {
        Gr_Queue::ensure_daily();
        wp_schedule_single_event( time(), Gr_Queue::DAILY_HOOK );
        // Pending work events run under caller-owned gr_ hooks and
        // carry args; core's wp_clear_scheduled_hook() would not touch
        // them with a hook-only clear, so the sweep must unschedule
        // per instance. Foreign hooks survive untouched either way.
        wp_schedule_single_event( time() + 60, 'gr_crawler_verify_job', array( '203.0.113.9', 'curl/8' ) );
        wp_schedule_single_event( time() + 61, 'gr_ga4_mp_send', array( array( 'id' => 7 ) ) );
        wp_schedule_single_event( time() + 120, 'wp_scheduled_delete' );
        set_transient( 'gr_queue_lock_daily', time(), Gr_Queue::LOCK_TTL );

        Gr_Queue::teardown();

        self::assertSame(
            array( 'wp_scheduled_delete' ),
            array_column( $GLOBALS['gr_stub_cron'], 'hook' ),
            'Only foreign cron work may survive deactivation.'
        );
        self::assertArrayNotHasKey( 'gr_queue_lock_daily', $GLOBALS['gr_stub_transients'] );
    }

    public function testClearPluginCronRemovesArgfulWorkEventsButNotForeignOnes(): void {
        wp_schedule_single_event( time() + 30, 'gr_crawler_verify_job', array( '198.51.100.4', 'bot/1' ) );
        wp_schedule_single_event( time() + 40, 'wp_scheduled_delete' );

        Gr_Queue::clear_plugin_cron();

        self::assertSame(
            array( 'wp_scheduled_delete' ),
            array_column( $GLOBALS['gr_stub_cron'], 'hook' ),
            'The namespace sweep must take arg-carrying gr_ events and leave foreign work alone.'
        );
    }
}
