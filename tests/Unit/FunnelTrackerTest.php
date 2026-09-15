<?php
/**
 * Funnel tracker (ADR-0014 D2): bus matching against the closed
 * vocabulary, the guarded sequential upsert, and the budget — one
 * statement per event per active funnel, never a read before it.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Event;
use GreenPNG\Funnel\Gr_Funnel_Tracker;
use GreenPNG\Storage\Gr_Funnel_Repository;
use PHPUnit\Framework\TestCase;

final class FunnelTrackerTest extends TestCase {

    /** Landing on /shop, then converting. */
    private const FLOW = array(
        array(
            'name'  => 'Landing',
            'match' => array( 'kind' => 'url', 'value' => '/shop', 'compare' => 'exact' ),
        ),
        array(
            'name'  => 'Purchase',
            'match' => array( 'kind' => 'event', 'value' => 'conversion', 'compare' => 'exact' ),
        ),
    );

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();

        global $wpdb;
        $wpdb->queries      = array();
        $wpdb->results      = array();
        $wpdb->var_result   = null;
        $wpdb->insert_id    = 0;
        $wpdb->query_result = 0;

        Gr_Funnel_Repository::reset_memo_for_tests();
    }

    /**
     * Canned active-funnel set for the memoized read.
     *
     * @param array<int, array<string, mixed>> $rows Funnel rows.
     * @return void
     */
    private function active_funnels( array $rows ): void {
        global $wpdb;
        $wpdb->results = $rows;
    }

    /**
     * One active funnel row carrying FLOW.
     *
     * @return array<string, mixed>
     */
    private function funnel_row(): array {
        return array(
            'id'        => '3',
            'name'      => 'Checkout Flow',
            'slug'      => 'checkout-flow',
            'flow_json' => (string) wp_json_encode( self::FLOW ),
        );
    }

    /**
     * All recorded SQL, deduped.
     *
     * @return array<int, string>
     */
    private function sql(): array {
        global $wpdb;

        return array_values( array_unique( $wpdb->queries ) );
    }

    public function testEventsWithoutASessionHaveNoJourney(): void {
        $this->active_funnels( array( $this->funnel_row() ) );

        Gr_Funnel_Tracker::on_event( Gr_Event::create( 'pageview', array( 'path' => '/shop', 'visitor_id' => 'v1' ) ) );

        self::assertSame( array(), $this->sql(), 'No session identity means no journey row.' );
    }

    public function testNoActiveFunnelsMeansNoWork(): void {
        $this->active_funnels( array() );

        Gr_Funnel_Tracker::on_event( Gr_Event::create( 'pageview', array( 'path' => '/shop', 'session_id' => 's1' ) ) );

        $sql = implode( ' ', $this->sql() );
        self::assertStringNotContainsString( 'INSERT INTO wp_gr_funnel_sessions', $sql );
    }

    public function testUrlStepMatchesPageviewByExactPath(): void {
        $this->active_funnels( array( $this->funnel_row() ) );

        Gr_Funnel_Tracker::on_event(
            Gr_Event::create( 'pageview', array( 'path' => '/shop', 'visitor_id' => 'v1', 'session_id' => 's1' ) )
        );

        $sql = implode( ' ', $this->sql() );
        self::assertStringContainsString( 'INSERT INTO wp_gr_funnel_sessions', $sql );
        self::assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $sql );
        self::assertStringContainsString( "'s1'", $sql );
        self::assertStringContainsString( "'v1'", $sql );
        // Step 1 of 2, recorded as both position columns.
        self::assertStringContainsString( 'VALUES (3, ' . "'s1', 'v1', 1, 1,", $sql );
        // The sequential guard and the completion marker on step 2.
        self::assertStringContainsString( 'IF(VALUES(current_step) = current_step + 1', $sql );
        self::assertStringContainsString( 'IF(2 = 1 AND 1 = current_step + 1', $sql, 'The completion guard reads the pre-statement position.' );
    }

    public function testUrlStepPrefixMatchCoversNestedPaths(): void {
        $this->active_funnels(
            array(
                array(
                    'id'        => '3',
                    'name'      => 'Prefix Flow',
                    'slug'      => 'prefix-flow',
                    'flow_json' => (string) wp_json_encode(
                        array(
                            array( 'name' => 'Shop', 'match' => array( 'kind' => 'url', 'value' => '/shop/', 'compare' => 'prefix' ) ),
                            array( 'name' => 'Buy', 'match' => array( 'kind' => 'event', 'value' => 'conversion', 'compare' => 'exact' ) ),
                        )
                    ),
                ),
            )
        );

        Gr_Funnel_Tracker::on_event(
            Gr_Event::create( 'pageview', array( 'path' => '/shop/widget', 'session_id' => 's1' ) )
        );

        $sql = implode( ' ', $this->sql() );
        self::assertStringContainsString( 'INSERT INTO wp_gr_funnel_sessions', $sql );
    }

    public function testUrlStepNeverMatchesOtherEventNames(): void {
        $this->active_funnels( array( $this->funnel_row() ) );

        Gr_Funnel_Tracker::on_event( Gr_Event::create( 'dwell', array( 'path' => '/shop', 'session_id' => 's1' ) ) );

        $sql = implode( ' ', $this->sql() );
        self::assertStringNotContainsString( 'INSERT INTO wp_gr_funnel_sessions', $sql, 'URL steps are pageview-only by definition.' );
    }

    public function testEventStepMatchesTheClosedVocabularyName(): void {
        $this->active_funnels( array( $this->funnel_row() ) );

        Gr_Funnel_Tracker::on_event(
            Gr_Event::create( 'conversion', array( 'visitor_id' => 'v1', 'session_id' => 's1' ) )
        );

        $sql = implode( ' ', $this->sql() );
        self::assertStringContainsString( 'INSERT INTO wp_gr_funnel_sessions', $sql );
        // Step 2 of 2: the insert itself completes the journey.
        self::assertStringContainsString( 'IF(2 = 2', $sql );
    }

    public function testUnmatchedEventsStaySilent(): void {
        $this->active_funnels( array( $this->funnel_row() ) );

        Gr_Funnel_Tracker::on_event( Gr_Event::create( 'pageview', array( 'path' => '/elsewhere', 'session_id' => 's1' ) ) );
        Gr_Funnel_Tracker::on_event( Gr_Event::create( 'signal', array( 'session_id' => 's1' ) ) );

        $sql = implode( ' ', $this->sql() );
        self::assertStringNotContainsString( 'INSERT INTO wp_gr_funnel_sessions', $sql, 'Neither the path nor the probe verdict matches any step.' );
        // The definition read happened exactly once, not once per
        // event: the memo is the budget.
        $reads = substr_count( implode( "\n", $GLOBALS['wpdb']->queries ), 'WHERE is_active = 1' );
        self::assertSame( 1, $reads );
    }

    public function testEachActiveFunnelGetsItsOwnUpsert(): void {
        global $wpdb;
        $second = $this->funnel_row();
        $second['id'] = '4';
        $this->active_funnels( array( $this->funnel_row(), $second ) );

        Gr_Funnel_Tracker::on_event( Gr_Event::create( 'pageview', array( 'path' => '/shop', 'session_id' => 's1' ) ) );

        // Deduped statements (the stub records prepare and execution
        // of each upsert identically): one per active funnel.
        $upserts = array();
        foreach ( $this->sql() as $line ) {
            if ( str_contains( $line, 'INSERT INTO wp_gr_funnel_sessions' ) ) {
                $upserts[] = $line;
            }
        }
        self::assertCount( 2, $upserts, 'Two active funnels, two upserts, nothing else.' );
        self::assertStringContainsString( 'VALUES (3,', $upserts[0] );
        self::assertStringContainsString( 'VALUES (4,', $upserts[1] );
    }
}
