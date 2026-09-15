<?php
/**
 * Funnel repository (ADR-0014 D1): step-flow validation against the
 * closed event vocabulary, definition CRUD over gr_funnels with
 * stable slugs, and the step-loss aggregation the staircase reads.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Storage\Gr_Funnel_Repository;
use PHPUnit\Framework\TestCase;

final class FunnelRepositoryTest extends TestCase {

    /** Two-step flow used as the legal baseline. */
    private const STEPS = array(
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
        $wpdb->queries     = array();
        $wpdb->results     = array();
        $wpdb->var_result  = null;
        $wpdb->insert_id   = 0;
        $wpdb->query_result = 0;

        Gr_Funnel_Repository::reset_memo_for_tests();
    }

    /**
     * All recorded SQL, deduped (prepare and the runner each record an
     * identical line).
     *
     * @return array<int, string>
     */
    private function sql(): array {
        global $wpdb;

        return array_values( array_unique( $wpdb->queries ) );
    }

    /**
     * Raw (non-deduped) query line count, for memoization assertions.
     *
     * @param string $needle Fragment to count.
     * @return int
     */
    private function query_count( string $needle ): int {
        global $wpdb;

        return substr_count( implode( "\n", $wpdb->queries ), $needle );
    }

    public function testValidateStepsAcceptsTheLegalBaseline(): void {
        self::assertTrue( Gr_Funnel_Repository::validate_steps( self::STEPS ) );
    }

    public function testValidateStepsRejectsShortAndLongFlows(): void {
        $error = Gr_Funnel_Repository::validate_steps( array( self::STEPS[0] ) );
        self::assertInstanceOf( \WP_Error::class, $error );
        self::assertSame( 'gr_funnel_min_steps', $error->get_error_code() );

        $eleven = array();
        for ( $i = 1; $i <= 11; $i++ ) {
            $eleven[] = array(
                /* translators: %d: step number. */
                'name'  => sprintf( 'Step %d', $i ),
                'match' => array( 'kind' => 'event', 'value' => 'pageview', 'compare' => 'exact' ),
            );
        }
        $error = Gr_Funnel_Repository::validate_steps( $eleven );
        self::assertInstanceOf( \WP_Error::class, $error );
        self::assertSame( 'gr_funnel_max_steps', $error->get_error_code() );
    }

    public function testValidateStepsEnforcesUniqueNamesAndRowAttribution(): void {
        $dupe = array(
            array( 'name' => 'Same', 'match' => array( 'kind' => 'event', 'value' => 'pageview', 'compare' => 'exact' ) ),
            array( 'name' => 'Same', 'match' => array( 'kind' => 'event', 'value' => 'dwell', 'compare' => 'exact' ) ),
        );

        $error = Gr_Funnel_Repository::validate_steps( $dupe );
        self::assertInstanceOf( \WP_Error::class, $error );
        self::assertSame( 'gr_funnel_step_unique', $error->get_error_code() );
        self::assertSame( 2, $error->get_error_data()['step'], 'The error names the offending row.' );
    }

    public function testValidateStepsClosesTheMatchVocabulary(): void {
        $bad_kind = array(
            array( 'name' => 'A', 'match' => array( 'kind' => 'funnel', 'value' => '/x', 'compare' => 'exact' ) ),
            array( 'name' => 'B', 'match' => array( 'kind' => 'url', 'value' => '/y', 'compare' => 'exact' ) ),
        );
        self::assertSame( 'gr_funnel_match_kind', Gr_Funnel_Repository::validate_steps( $bad_kind )->get_error_code() );
        $bad_compare = array(
            array( 'name' => 'A', 'match' => array( 'kind' => 'url', 'value' => '/x', 'compare' => 'contains' ) ),
            array( 'name' => 'B', 'match' => array( 'kind' => 'url', 'value' => '/y', 'compare' => 'exact' ) ),
        );
        self::assertSame( 'gr_funnel_match_compare', Gr_Funnel_Repository::validate_steps( $bad_compare )->get_error_code() );

        $bad_event = array(
            array( 'name' => 'A', 'match' => array( 'kind' => 'event', 'value' => 'signal', 'compare' => 'exact' ) ),
            array( 'name' => 'B', 'match' => array( 'kind' => 'event', 'value' => 'pageview', 'compare' => 'exact' ) ),
        );
        self::assertSame(
            'gr_funnel_match_event',
            Gr_Funnel_Repository::validate_steps( $bad_event )->get_error_code(),
            'Probe conclusions are not journey markers and stay outside the vocabulary.'
        );
    }

    public function testValidateStepsRequiresRootedUrlPaths(): void {
        $hosted = array(
            array( 'name' => 'A', 'match' => array( 'kind' => 'url', 'value' => 'https://example.com/checkout', 'compare' => 'exact' ) ),
            array( 'name' => 'B', 'match' => array( 'kind' => 'url', 'value' => '/y', 'compare' => 'exact' ) ),
        );
        self::assertSame( 'gr_funnel_match_url', Gr_Funnel_Repository::validate_steps( $hosted )->get_error_code() );

        $relative = array(
            array( 'name' => 'A', 'match' => array( 'kind' => 'url', 'value' => 'checkout', 'compare' => 'exact' ) ),
            array( 'name' => 'B', 'match' => array( 'kind' => 'url', 'value' => '/y', 'compare' => 'exact' ) ),
        );
        self::assertSame( 'gr_funnel_match_url', Gr_Funnel_Repository::validate_steps( $relative )->get_error_code() );

        $prefix_legal = array(
            array( 'name' => 'A', 'match' => array( 'kind' => 'url', 'value' => '/checkout/', 'compare' => 'prefix' ) ),
            array( 'name' => 'B', 'match' => array( 'kind' => 'url', 'value' => '/y', 'compare' => 'exact' ) ),
        );
        self::assertTrue( Gr_Funnel_Repository::validate_steps( $prefix_legal ) );
    }

    public function testSaveInsertsWithDerivedSlugAndFlowJson(): void {
        global $wpdb;
        $wpdb->results   = array(); // No existing slugs.
        $wpdb->insert_id = 7;

        $id = ( new Gr_Funnel_Repository() )->save( 'Checkout Flow', self::STEPS, true );

        self::assertSame( 7, $id );
        $sql = implode( ' ', $this->sql() );
        self::assertStringContainsString( 'INSERT INTO wp_gr_funnels', $sql );
        self::assertStringContainsString( "'checkout-flow'", $sql, 'Spaces become dashes in the derived slug.' );
        // The stub's prepare() addslashes-escapes the JSON quotes, so
        // the literal carries the backslashes.
        self::assertStringContainsString( "'" . addslashes( (string) wp_json_encode( self::STEPS ) ) . "'", $sql, 'The flow lands as JSON exactly as validated.' );
        self::assertStringContainsString( ', 1,', $sql, 'The active flag rides the insert.' );
    }

    public function testSaveSuffixesCollidingSlugs(): void {
        global $wpdb;
        $wpdb->results   = array( array( 'slug' => 'checkout-flow' ) );
        $wpdb->insert_id = 9;

        ( new Gr_Funnel_Repository() )->save( 'Checkout Flow', self::STEPS, true );

        $sql = implode( ' ', $this->sql() );
        self::assertStringContainsString( "'checkout-flow-2'", $sql );
    }

    public function testSaveUpdateKeepsIdentityAndTouchesUpdatedOnly(): void {
        global $wpdb;
        $wpdb->results = array(
            array( 'id' => '4', 'name' => 'Checkout Flow', 'slug' => 'checkout-flow', 'flow_json' => '[]', 'is_active' => '1' ),
        );

        $id = ( new Gr_Funnel_Repository() )->save( 'Renamed Flow', self::STEPS, false, 4 );

        self::assertSame( 4, $id );
        $update = '';
        foreach ( $this->sql() as $line ) {
            if ( str_contains( $line, 'UPDATE wp_gr_funnels' ) ) {
                $update = $line;
            }
        }
        self::assertStringContainsString( 'UPDATE wp_gr_funnels', $update );
        self::assertStringContainsString( 'WHERE id = 4', $update );
        self::assertStringNotContainsString( 'slug', $update, 'The slug is identity and never rewritten.' );
    }

    public function testSaveUpdateRefusesAMissingRow(): void {
        global $wpdb;
        $wpdb->results = array();

        $error = ( new Gr_Funnel_Repository() )->save( 'Ghost', self::STEPS, true, 99 );

        self::assertInstanceOf( \WP_Error::class, $error );
        self::assertSame( 'gr_funnel_missing', $error->get_error_code() );
    }

    public function testSaveRejectsBadNamesBeforeTouchingTheTable(): void {
        global $wpdb;

        $error = ( new Gr_Funnel_Repository() )->save( '', self::STEPS, true );
        self::assertInstanceOf( \WP_Error::class, $error );
        self::assertSame( 'gr_funnel_name', $error->get_error_code() );
        self::assertSame( array(), $wpdb->queries, 'Nothing reached the database.' );
    }

    public function testAllDecodesTheFlowIntoSteps(): void {
        global $wpdb;
        $wpdb->results = array(
            array( 'id' => '3', 'name' => 'Checkout Flow', 'slug' => 'checkout-flow', 'flow_json' => (string) wp_json_encode( self::STEPS ), 'is_active' => '1', 'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00' ),
            array( 'id' => '4', 'name' => 'Broken', 'slug' => 'broken', 'flow_json' => 'not json', 'is_active' => '0', 'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00' ),
        );

        $rows = ( new Gr_Funnel_Repository() )->all();

        self::assertCount( 2, $rows );
        self::assertSame( 3, $rows[0]['id'] );
        self::assertTrue( $rows[0]['is_active'] );
        self::assertSame( self::STEPS, $rows[0]['steps'] );
        self::assertFalse( $rows[1]['is_active'] );
        self::assertSame( array(), $rows[1]['steps'], 'A corrupted flow degrades to an empty step list.' );
    }

    public function testActiveIsMemoizedPerRequestAndFiltersInactive(): void {
        global $wpdb;
        $wpdb->results = array(
            array( 'id' => '3', 'name' => 'Checkout Flow', 'slug' => 'checkout-flow', 'flow_json' => (string) wp_json_encode( self::STEPS ) ),
        );

        $repo   = new Gr_Funnel_Repository();
        $first  = $repo->active();
        $second = $repo->active();

        self::assertSame( $first, $second );
        self::assertCount( 1, $first );
        $sql = implode( ' ', $this->sql() );
        self::assertStringContainsString( 'WHERE is_active = 1', $sql );
        self::assertSame( 1, $this->query_count( 'WHERE is_active = 1' ), 'The second read is memoized, not re-queried.' );
    }

    public function testDeleteRemovesTheDefinitionAndItsJourneys(): void {
        global $wpdb;
        $wpdb->query_result = 1;

        self::assertTrue( ( new Gr_Funnel_Repository() )->delete( 5 ) );

        $sql = implode( ' ', $this->sql() );
        self::assertStringContainsString( 'DELETE FROM wp_gr_funnels WHERE id = 5', $sql );
        self::assertStringContainsString( 'DELETE FROM wp_gr_funnel_sessions WHERE funnel_id = 5', $sql, 'Orphaned journey rows go with their definition.' );
    }

    public function testDeleteReportsNothingRemoved(): void {
        global $wpdb;
        $wpdb->query_result = 0;

        self::assertFalse( ( new Gr_Funnel_Repository() )->delete( 5 ) );
    }

    public function testStepCountsAggregatesMonotonicallyOverMaxStep(): void {
        global $wpdb;
        $wpdb->results = function ( string $query ): array {
            if ( str_contains( $query, 'FROM wp_gr_funnels' ) ) {
                return array( array( 'id' => '3', 'name' => 'F', 'slug' => 'f', 'flow_json' => (string) wp_json_encode( self::STEPS ), 'is_active' => '1' ) );
            }

            // Three sessions stopped at step 1; two reached step 2,
            // one of them completing (the conversion step).
            return array(
                array( 'max_step' => '1', 'sessions' => '3', 'completed' => '0' ),
                array( 'max_step' => '2', 'sessions' => '2', 'completed' => '1' ),
            );
        };

        $counts = ( new Gr_Funnel_Repository() )->step_counts( 3 );

        self::assertSame( array( 1 => 5, 2 => 2 ), $counts['steps'] );
        self::assertSame( 1, $counts['completed'] );
    }

    public function testStepCountsSurvivesAFunnelWithNoJourneys(): void {
        global $wpdb;
        $wpdb->results = function ( string $query ): array {
            if ( str_contains( $query, 'FROM wp_gr_funnels' ) ) {
                return array( array( 'id' => '3', 'name' => 'F', 'slug' => 'f', 'flow_json' => (string) wp_json_encode( self::STEPS ), 'is_active' => '1' ) );
            }

            return array();
        };

        $counts = ( new Gr_Funnel_Repository() )->step_counts( 3 );

        self::assertSame( array( 1 => 0, 2 => 0 ), $counts['steps'] );
        self::assertSame( 0, $counts['completed'] );
    }

    public function testCompletedCountsGroupsPerFunnel(): void {
        global $wpdb;
        $wpdb->results = array(
            array( 'funnel_id' => '3', 'completed' => '4' ),
            array( 'funnel_id' => '5', 'completed' => '1' ),
        );

        $counts = ( new Gr_Funnel_Repository() )->completed_counts( 30 );

        self::assertSame( array( 3 => 4, 5 => 1 ), $counts );
        $sql = implode( ' ', $this->sql() );
        self::assertStringContainsString( 'completed_at IS NOT NULL', $sql );
    }
}
