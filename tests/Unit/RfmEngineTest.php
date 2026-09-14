<?php
/**
 * RFM engine (ADR-0013 D4): PHP quintiles with tie collapsing, the
 * eight-segment truth table, the net-value M axis (refunds leave the
 * value side), movement-only writes, and the shared daily pass.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\CRM\Gr_Rfm_Engine;
use GreenPNG\Core\Gr_Queue;
use PHPUnit\Framework\TestCase;

final class RfmEngineTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        $GLOBALS['gr_stub_actions'] = array();
        $GLOBALS['gr_stub_cron']    = array();

        global $wpdb;
        $wpdb->queries      = array();
        $wpdb->results      = array();
        $wpdb->var_result   = null;
        $wpdb->insert_id    = 0;
        $wpdb->query_result = 0;
    }

    protected function tearDown(): void {
        parent::tearDown();
    }

    /**
     * Seeds one population: contacts and their per-visitor sales.
     *
     * @param array<int, array{id: int, visitor_id: string, last_seen: string, rfm_segment: string, ltv: string}> $people   Contact rows.
     * @param array<string, array{count: int, net: float}>                                                      $sales    Per-visitor stats.
     * @return void
     */
    private function seed( array $people, array $sales = array() ): void {
        global $wpdb;

        $wpdb->results = static function ( string $sql ) use ( $people, $sales ): array {
            if ( false !== strpos( $sql, 'rfm_segment, ltv FROM' ) ) {
                return $people;
            }
            if ( false !== strpos( $sql, 'GROUP BY visitor_id' ) && false !== strpos( $sql, 'COALESCE' ) ) {
                $rows = array();
                foreach ( $sales as $visitor => $stats ) {
                    $rows[] = array(
                        'visitor_id' => $visitor,
                        'n'          => (string) $stats['count'],
                        'net'        => (string) number_format( $stats['net'], 2, '.', '' ),
                    );
                }
                return $rows;
            }
            return array();
        };
    }

    /**
     * Unique segment writes (prepare and the runner record identical
     * lines).
     *
     * @return array<int, string>
     */
    private function segment_writes(): array {
        global $wpdb;

        return array_values(
            array_unique(
                array_filter(
                    $wpdb->queries,
                    static function ( $sql ): bool {
                        return is_string( $sql ) && false !== strpos( $sql, 'SET rfm_segment' );
                    }
                )
            )
        );
    }

    public function testSegmentTruthTableCoversTheVocabularyInOrder(): void {
        self::assertSame( 'champions', Gr_Rfm_Engine::segment_for( 5, 5, 5 ) );
        self::assertSame( 'champions', Gr_Rfm_Engine::segment_for( 4, 4, 4 ) );
        self::assertSame( 'loyal', Gr_Rfm_Engine::segment_for( 5, 5, 3 ), 'High R and F but low M crowns nothing.' );
        self::assertSame( 'potential', Gr_Rfm_Engine::segment_for( 4, 2, 2 ) );
        self::assertSame( 'new', Gr_Rfm_Engine::segment_for( 5, 1, 1 ) );
        self::assertSame( 'needs-attention', Gr_Rfm_Engine::segment_for( 3, 5, 5 ) );
        self::assertSame( 'at-risk', Gr_Rfm_Engine::segment_for( 2, 1, 1 ) );
        self::assertSame( 'hibernating', Gr_Rfm_Engine::segment_for( 1, 3, 3 ), 'Gone, but was engaged.' );
        self::assertSame( 'lost', Gr_Rfm_Engine::segment_for( 1, 1, 1 ) );

        $covered = array();
        foreach ( array( 1, 2, 3, 4, 5 ) as $r ) {
            foreach ( array( 1, 2, 3, 4, 5 ) as $f ) {
                foreach ( array( 1, 3, 5 ) as $m ) {
                    $covered[ Gr_Rfm_Engine::segment_for( $r, $f, $m ) ] = true;
                }
            }
        }
        self::assertEqualsCanonicalizing(
            array_fill_keys( Gr_Rfm_Engine::SEGMENTS, true ),
            $covered,
            'Every vocabulary word is reachable and nothing outside it is produced.'
        );
    }

    public function testFiveContactsSpreadAcrossAllFiveQuintiles(): void {
        $people = array();
        for ( $i = 1; $i <= 5; $i++ ) {
            $people[] = array(
                'id'          => (string) $i,
                'visitor_id'  => 'v' . $i,
                'last_seen'   => gmdate( 'Y-m-d H:i:s', strtotime( '2026-09-01' ) + $i * 86400 ),
                'rfm_segment' => '',
                'ltv'         => '0.00',
            );
        }
        $sales = array(
            'v1' => array( 'count' => 0, 'net' => 0.0 ),
            'v2' => array( 'count' => 1, 'net' => 10.0 ),
            'v3' => array( 'count' => 2, 'net' => 30.0 ),
            'v4' => array( 'count' => 3, 'net' => 60.0 ),
            'v5' => array( 'count' => 4, 'net' => 100.0 ),
        );
        $this->seed( $people, $sales );

        $result = Gr_Rfm_Engine::compute_all();

        self::assertSame( 1, $result[1]['r'] );
        self::assertSame( 5, $result[5]['r'] );
        self::assertSame( 1, $result[1]['f'] );
        self::assertSame( 5, $result[5]['f'] );
        self::assertSame( 1, $result[1]['m'] );
        self::assertSame( 5, $result[5]['m'] );
        self::assertCount( 5, $this->segment_writes(), 'Every contact moved from the empty state.' );
    }

    public function testTiesCollapseOntoOneScorePerValue(): void {
        // Four contacts, three of them with identical everything; the
        // axis scores must split by VALUE, not by position.
        $people = array();
        for ( $i = 1; $i <= 4; $i++ ) {
            $people[] = array(
                'id'          => (string) $i,
                'visitor_id'  => 'v' . $i,
                'last_seen'   => '2026-09-10 10:00:00',
                'rfm_segment' => '',
                'ltv'         => '0.00',
            );
        }
        $sales = array(
            'v1' => array( 'count' => 0, 'net' => 0.0 ),
            'v2' => array( 'count' => 0, 'net' => 0.0 ),
            'v3' => array( 'count' => 0, 'net' => 0.0 ),
            'v4' => array( 'count' => 9, 'net' => 400.0 ),
        );
        $this->seed( $people, $sales );

        $result = Gr_Rfm_Engine::compute_all();

        self::assertSame( $result[1]['f'], $result[2]['f'] );
        self::assertSame( $result[2]['f'], $result[3]['f'], 'Identical frequency never splits across scores.' );
        self::assertSame( 1, $result[1]['f'], 'Zero purchases floor to the lowest bucket.' );
        self::assertSame( 5, $result[4]['f'], 'The lone maximum still reaches the top bucket.' );
        // All four share one recency value: one shared score.
        self::assertSame( $result[1]['r'], $result[4]['r'] );
    }

    public function testSmallPopulationDegradesToTheNewSegment(): void {
        $this->seed(
            array(
                array(
                    'id'          => '1',
                    'visitor_id'  => 'solo',
                    'last_seen'   => '2026-09-12 09:00:00',
                    'rfm_segment' => '',
                    'ltv'         => '0.00',
                ),
            )
        );

        $result = Gr_Rfm_Engine::compute_all();

        // Trivially the most recent, floor-scored on the value axes.
        self::assertSame( array( 5, 1, 1 ), array( $result[1]['r'], $result[1]['f'], $result[1]['m'] ) );
        self::assertSame( 'new', $result[1]['segment'], 'A solo contact is the newest face there is.' );
    }

    public function testUnchangedRowsAreNotRewritten(): void {
        $this->seed(
            array(
                array(
                    'id'          => '1',
                    'visitor_id'  => 'v1',
                    'last_seen'   => '2026-09-12 09:00:00',
                    'rfm_segment' => 'new',
                    'ltv'         => '0.00',
                ),
            )
        );

        Gr_Rfm_Engine::compute_all();

        self::assertSame( array(), $this->segment_writes(), 'Same segment, same value: zero writes.' );
    }

    public function testNetValueAxisCarriesRefundSemantics(): void {
        // Two visitors: same purchase count, but one conversion row
        // was reversed — its net stays 0 while its frequency still
        // counts. Seeded at the repository layer's output shape.
        $people = array(
            array(
                'id'          => '1',
                'visitor_id'  => 'full',
                'last_seen'   => '2026-09-12 09:00:00',
                'rfm_segment' => '',
                'ltv'         => '0.00',
            ),
            array(
                'id'          => '2',
                'visitor_id'  => 'refunded',
                'last_seen'   => '2026-09-12 10:00:00',
                'rfm_segment' => '',
                'ltv'         => '0.00',
            ),
        );
        $sales = array(
            'full'     => array( 'count' => 2, 'net' => 240.0 ),
            'refunded' => array( 'count' => 2, 'net' => 0.0 ),
        );
        $this->seed( $people, $sales );

        $result = Gr_Rfm_Engine::compute_all();

        self::assertSame( 2, $result[2]['f'], 'The purchase happened; frequency counts it.' );
        self::assertSame( 0.0, $result[2]['ltv'], 'The reversal emptied the value side.' );
        self::assertSame( 240.0, $result[1]['ltv'] );
    }

    public function testEngineRidesTheDailyPassAfterScoring(): void {
        Gr_Rfm_Engine::register_hooks();

        $found = false;
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            if ( Gr_Queue::DAILY_HOOK === (string) $registration['hook'] && 9 === (int) $registration['priority'] ) {
                $found = true;
            }
        }

        self::assertTrue( $found, 'RFM runs at 9: after scoring (8), before retention (10).' );
    }

    public function testEmptyPopulationWritesNothing(): void {
        $this->seed( array() );

        self::assertSame( array(), Gr_Rfm_Engine::compute_all() );
        self::assertSame( array(), $this->segment_writes() );
    }
}
