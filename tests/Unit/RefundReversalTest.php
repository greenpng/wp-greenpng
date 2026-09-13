<?php
/**
 * Refund reversal (ADR-0010): the guarded soft-mark, the partial
 * convergence against the order's remaining total, the service's
 * queue recompute + audit trail, and the aggregator's targeted
 * old-date recompute that must never touch slimmed metric families.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Attribution\Gr_Attribution_Service;
use GreenPNG\Storage\Gr_Conversion_Repository;
use GreenPNG\Storage\Gr_Daily_Aggregator;
use GreenPNG\Storage\Gr_Touchpoint_Repository;
use PHPUnit\Framework\TestCase;

final class RefundReversalTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();

        $_SERVER['REMOTE_ADDR']      = '10.0.0.9';
        $_SERVER['HTTP_USER_AGENT'] = 'UnitTestAgent/1.0';
    }

    protected function tearDown(): void {
        unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] );
        parent::tearDown();
    }

    /**
     * Service over the stub stores.
     *
     * @return Gr_Attribution_Service
     */
    private function service(): Gr_Attribution_Service {
        return new Gr_Attribution_Service( new Gr_Touchpoint_Repository(), new Gr_Conversion_Repository() );
    }

    /**
     * Seeds the reversal-state read with one bound row.
     *
     * @param string $status 'active' or 'reversed'.
     * @return void
     */
    private function seed_state( string $status ): void {
        global $wpdb;

        $wpdb->results = array(
            array(
                'id'         => '31',
                'status'     => $status,
                'amount'     => '120.00',
                'created_at' => '2026-08-01 10:00:00',
            ),
        );
    }

    /**
     * @return array<int, string> UPDATE statements against conversions.
     */
    private function conversion_updates(): array {
        global $wpdb;

        return array_values(
            array_filter(
                $wpdb->queries,
                static function ( $sql ): bool {
                    return is_string( $sql ) && 0 === strpos( $sql, 'UPDATE wp_gr_conversions' );
                }
            )
        );
    }

    /**
     * The last recorded conversions UPDATE.
     *
     * @return string
     */
    private function last_update_sql(): string {
        $updates = $this->conversion_updates();
        $last    = end( $updates );

        return (string) $last;
    }

    public function testRepositoryRunsTheGuardedReversalUpdate(): void {
        global $wpdb;
        $wpdb->query_result = 1;

        $reversed = ( new Gr_Conversion_Repository() )->reverse_for_source( 'woocommerce', 43 );

        self::assertTrue( $reversed );
        $sql = $this->last_update_sql();
        self::assertStringContainsString( "status = 'reversed'", $sql );
        self::assertStringContainsString( 'reversed_at =', $sql );
        self::assertStringContainsString( "WHERE source_type = 'woocommerce' AND source_id = 43", $sql );
        // The guard is the idempotency line: only an active row passes.
        self::assertStringContainsString( "AND status = 'active'", $sql );
    }

    public function testRepositoryReversalNoOpsOnAReplayedHook(): void {
        global $wpdb;
        // MySQL counts changed rows: the guard matches nothing on a
        // replay, so 0 affected is exactly the no-op signal.
        $wpdb->query_result = 0;

        self::assertFalse( ( new Gr_Conversion_Repository() )->reverse_for_source( 'woocommerce', 43 ) );
    }

    public function testRepositoryConvergesToTheRemainingTotal(): void {
        global $wpdb;
        $wpdb->query_result = 1;

        $changed = ( new Gr_Conversion_Repository() )->converge_amount_for_source( 'woocommerce', 43, 42.5 );

        self::assertTrue( $changed );
        $sql = $this->last_update_sql();
        self::assertStringContainsString( "amount = '42.50'", $sql );
        // A partial refund keeps the row active: amount is the only
        // SET column (the status word that remains is the WHERE guard).
        self::assertStringContainsString( "SET amount = '42.50' WHERE", $sql );
    }

    public function testRepositoryZeroRemainingFlipsToReversed(): void {
        global $wpdb;
        $wpdb->query_result = 1;

        ( new Gr_Conversion_Repository() )->converge_amount_for_source( 'woocommerce', 43, 0.0 );

        $sql = $this->last_update_sql();
        self::assertStringContainsString( "amount = '0.00'", $sql );
        self::assertStringContainsString( "status = 'reversed'", $sql );
        self::assertStringContainsString( 'reversed_at =', $sql );
    }

    public function testRepositoryReversalStateReadsTheBoundRow(): void {
        $this->seed_state( 'active' );

        $state = ( new Gr_Conversion_Repository() )->reversal_state_for_source( 'woocommerce', 43 );

        self::assertNotNull( $state );
        self::assertSame( 31, $state['id'] );
        self::assertSame( 'active', $state['status'] );
        self::assertSame( '2026-08-01 10:00:00', $state['created_at'] );
    }

    public function testRepositoryReversalStateIsNullForUnboundSource(): void {
        global $wpdb;
        $wpdb->results = array();

        self::assertNull( ( new Gr_Conversion_Repository() )->reversal_state_for_source( 'woocommerce', 404 ) );
    }

    public function testServiceReversalQueuesTheTargetedDateRecomputeAndAudits(): void {
        global $wpdb;
        $this->seed_state( 'active' );
        $wpdb->query_result = 1;

        $reversed = $this->service()->reverse_source( 'woocommerce', 43 );

        self::assertTrue( $reversed );
        self::assertNotSame( array(), $this->conversion_updates() );

        // The recompute is queued for the conversion's own date, so a
        // refund years later still corrects that date's revenue.
        $found = false;
        foreach ( $GLOBALS['gr_stub_cron'] as $event ) {
            if ( 'gr_recompute_conversion_date' === $event['hook'] ) {
                self::assertSame( array( '2026-08-01' ), $event['args'] );
                $found = true;
            }
        }
        self::assertTrue( $found );

        // The audit trail carries the before/after state (docs/05 #13).
        $audit = null;
        foreach ( $wpdb->inserts as $insert ) {
            if ( 'wp_gr_audit_logs' === $insert['table'] ) {
                $audit = $insert;
            }
        }
        self::assertNotNull( $audit );
        self::assertSame( 'conversion_reversed', $audit['data']['action'] );
        self::assertSame( 'conversion', $audit['data']['object_type'] );
        self::assertSame( '31', $audit['data']['object_id'] );
    }

    public function testServiceReversalNoOpsOnAnAlreadyReversedRow(): void {
        global $wpdb;
        $this->seed_state( 'reversed' );

        self::assertFalse( $this->service()->reverse_source( 'woocommerce', 43 ) );

        // Read only: no update, nothing queued, no audit row.
        self::assertSame( array(), $this->conversion_updates() );
        self::assertSame( array(), $GLOBALS['gr_stub_cron'] );
        self::assertSame( array(), $wpdb->inserts );
    }

    public function testServiceReversalNoOpsOnAnUnboundSource(): void {
        global $wpdb;
        $wpdb->results = array();

        // A cancelled-before-payment order was never bound: reversal
        // has nothing to touch (ADR-0010 D2's in-flight exclusion).
        self::assertFalse( $this->service()->reverse_source( 'woocommerce', 404 ) );
        self::assertSame( array(), $this->conversion_updates() );
        self::assertSame( array(), $GLOBALS['gr_stub_cron'] );
    }

    public function testServicePartialRefundConvergesAndAudits(): void {
        global $wpdb;
        $this->seed_state( 'active' );
        $wpdb->query_result = 1;

        $changed = $this->service()->apply_partial_refund( 'woocommerce', 43, 42.5 );

        self::assertTrue( $changed );
        $sql = $this->last_update_sql();
        self::assertStringContainsString( "amount = '42.50'", $sql );

        $audit = null;
        foreach ( $wpdb->inserts as $insert ) {
            if ( 'wp_gr_audit_logs' === $insert['table'] ) {
                $audit = $insert;
            }
        }
        self::assertNotNull( $audit );
        self::assertSame( 'conversion_partial_refund', $audit['data']['action'] );

        $found = false;
        foreach ( $GLOBALS['gr_stub_cron'] as $event ) {
            if ( 'gr_recompute_conversion_date' === $event['hook'] ) {
                $found = true;
            }
        }
        self::assertTrue( $found );
    }

    public function testAggregatorRevenueSumsOnlyActiveRows(): void {
        global $wpdb;

        $wpdb->results = static function ( $sql ) {
            if ( false !== strpos( (string) $sql, 'wp_gr_conversions' ) ) {
                return array( array( 'conversions' => '2', 'revenue' => '99.99' ) );
            }
            return array();
        };

        $written = Gr_Daily_Aggregator::recompute_conversions_for_date( '2026-08-01' );

        self::assertSame( 2, $written );

        $read = '';
        foreach ( $wpdb->queries as $query ) {
            if ( is_string( $query ) && false !== strpos( $query, 'FROM wp_gr_conversions' ) ) {
                $read = $query;
            }
        }
        self::assertStringContainsString( "CASE WHEN status = 'active' THEN amount ELSE 0 END", $read );
        // The count stays over every bound row: the conversion rate
        // must not move on a refund (ADR-0010 D3).
        self::assertStringContainsString( 'COUNT(*) AS conversions', $read );

        $upsert = '';
        foreach ( $wpdb->queries as $query ) {
            if ( is_string( $query ) && false !== strpos( $query, 'INSERT INTO wp_gr_daily_stats' ) ) {
                $upsert = $query;
            }
        }
        self::assertStringContainsString( "('2026-08-01', 'conversions', '', 2.000000)", $upsert );
        self::assertStringContainsString( "('2026-08-01', 'revenue', '', 99.990000)", $upsert );
    }

    public function testTargetedRecomputeNeverTouchesOtherMetricFamilies(): void {
        global $wpdb;

        $wpdb->results = static function ( $sql ) {
            if ( false !== strpos( (string) $sql, 'wp_gr_conversions' ) ) {
                return array( array( 'conversions' => '1', 'revenue' => '10.00' ) );
            }
            return array();
        };

        Gr_Daily_Aggregator::recompute_conversions_for_date( '2026-05-01' );

        // An old date's session/event/security rows are slimmed; the
        // targeted path must not re-read them, or it would overwrite
        // settled history with zeros (ADR-0010 D3).
        foreach ( $wpdb->queries as $query ) {
            self::assertStringNotContainsString( 'wp_gr_sessions', (string) $query );
            self::assertStringNotContainsString( 'wp_gr_events', (string) $query );
            self::assertStringNotContainsString( 'wp_gr_security_logs', (string) $query );
        }
    }

    public function testTargetedRecomputeRejectsMalformedDates(): void {
        global $wpdb;

        self::assertSame( 0, Gr_Daily_Aggregator::recompute_conversions_for_date( 'yesterday' ) );
        self::assertSame( array(), $wpdb->queries );
    }
}
