<?php
/**
 * Attribution models (docs/13 C8): the five credit splits against
 * hand-computed sequences, cents reconciliation included.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Attribution\Gr_Attribution_Models;
use PHPUnit\Framework\TestCase;

final class AttributionModelsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    /**
     * The canonical three-touch sequence: 14 days, 7 days, and 0 days
     * of age, so the decay halves line up exactly.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sequence3(): array {
        return array(
            array( 'id' => 11, 'created_at' => '2026-09-01 12:00:00' ),
            array( 'id' => 12, 'created_at' => '2026-09-08 12:00:00' ),
            array( 'id' => 13, 'created_at' => '2026-09-15 12:00:00' ),
        );
    }

    /**
     * Sums one model's amounts.
     *
     * @param array<int, array{weight: float, amount: float}> $split Model split.
     * @return float
     */
    private function total( array $split ): float {
        return round( array_sum( array_column( $split, 'amount' ) ), 2 );
    }

    public function testFirstTouchTakesEverything(): void {
        $split = Gr_Attribution_Models::calculate( $this->sequence3(), 100.0 )['first'];

        self::assertSame( 100.0, $split[11]['amount'] );
        self::assertSame( 1.0, $split[11]['weight'] );
        self::assertArrayNotHasKey( 12, $split );
        self::assertArrayNotHasKey( 13, $split );
    }

    public function testLastTouchTakesEverything(): void {
        $split = Gr_Attribution_Models::calculate( $this->sequence3(), 100.0 )['last'];

        self::assertSame( 100.0, $split[13]['amount'] );
        self::assertArrayNotHasKey( 11, $split );
        self::assertEqualsWithDelta( 100.0, $this->total( $split ), 0.001 );
    }

    public function testLinearSplitsEvenlyWithExactCents(): void {
        $split = Gr_Attribution_Models::calculate( $this->sequence3(), 100.0 )['linear'];

        // 100/3 floors to 33.33 x3; the missing cent goes to the largest
        // credit, tie broken to the later touch (13).
        self::assertSame( 33.33, $split[11]['amount'] );
        self::assertSame( 33.33, $split[12]['amount'] );
        self::assertSame( 33.34, $split[13]['amount'] );
        self::assertSame( 100.0, $this->total( $split ) );
        self::assertEqualsWithDelta( 1.0, array_sum( array_column( $split, 'weight' ) ), 0.0001 );
    }

    public function testPositionBasedIsFortyTwentyForty(): void {
        $split = Gr_Attribution_Models::calculate( $this->sequence3(), 100.0 )['position'];

        self::assertSame( 40.0, $split[11]['amount'] );
        self::assertSame( 20.0, $split[12]['amount'] );
        self::assertSame( 40.0, $split[13]['amount'] );

        $four = Gr_Attribution_Models::calculate(
            array(
                array( 'id' => 1, 'created_at' => '2026-09-01 00:00:00' ),
                array( 'id' => 2, 'created_at' => '2026-09-05 00:00:00' ),
                array( 'id' => 3, 'created_at' => '2026-09-10 00:00:00' ),
                array( 'id' => 4, 'created_at' => '2026-09-15 00:00:00' ),
            ),
            200.0
        )['position'];

        self::assertSame( 80.0, $four[1]['amount'] );
        self::assertSame( 20.0, $four[2]['amount'] );
        self::assertSame( 20.0, $four[3]['amount'] );
        self::assertSame( 80.0, $four[4]['amount'] );
    }

    public function testPositionBasedDegenerateCases(): void {
        $one = Gr_Attribution_Models::calculate(
            array( array( 'id' => 7, 'created_at' => '2026-09-01 00:00:00' ) ),
            99.0
        );

        foreach ( array( 'first', 'last', 'linear', 'position', 'time_decay' ) as $model ) {
            self::assertSame( 99.0, $one[ $model ][7]['amount'], "model={$model}" );
        }

        $two = Gr_Attribution_Models::calculate(
            array(
                array( 'id' => 1, 'created_at' => '2026-09-01 00:00:00' ),
                array( 'id' => 2, 'created_at' => '2026-09-08 00:00:00' ),
            ),
            100.0
        );

        self::assertSame( 50.0, $two['position'][1]['amount'] );
        self::assertSame( 50.0, $two['position'][2]['amount'] );
    }

    public function testTimeDecayHalvesPerHalfLife(): void {
        // Ages relative to the newest touch: 14d, 7d, 0d. Weights
        // 0.25 / 0.5 / 1.0, normalized 1/7, 2/7, 4/7.
        $split = Gr_Attribution_Models::calculate( $this->sequence3(), 100.0 )['time_decay'];

        self::assertEqualsWithDelta( 1.0 / 7.0, $split[11]['weight'], 0.0001 );
        self::assertEqualsWithDelta( 2.0 / 7.0, $split[12]['weight'], 0.0001 );
        self::assertEqualsWithDelta( 4.0 / 7.0, $split[13]['weight'], 0.0001 );

        // 100 * 1/7 = 14.2857 -> 14.28; 2/7 -> 28.57; 4/7 -> 57.14;
        // the missing cent goes to the largest credit (13).
        self::assertSame( 14.28, $split[11]['amount'] );
        self::assertSame( 28.57, $split[12]['amount'] );
        self::assertSame( 57.15, $split[13]['amount'] );
        self::assertSame( 100.0, $this->total( $split ) );
    }

    public function testEmptySequenceYieldsEmptySplits(): void {
        $result = Gr_Attribution_Models::calculate( array(), 100.0 );

        foreach ( array( 'first', 'last', 'linear', 'position', 'time_decay' ) as $model ) {
            self::assertSame( array(), $result[ $model ], "model={$model}" );
        }
    }

    public function testInputOrderCannotChangeTheOutcome(): void {
        $shuffled = array(
            array( 'id' => 13, 'created_at' => '2026-09-15 12:00:00' ),
            array( 'id' => 11, 'created_at' => '2026-09-01 12:00:00' ),
            array( 'id' => 12, 'created_at' => '2026-09-08 12:00:00' ),
        );

        $from_shuffled = Gr_Attribution_Models::calculate( $shuffled, 100.0 );
        $from_ordered  = Gr_Attribution_Models::calculate( $this->sequence3(), 100.0 );

        self::assertSame( $from_ordered, $from_shuffled );
    }

    public function testSameTimestampTiesBreakByTouchpointId(): void {
        $split = Gr_Attribution_Models::calculate(
            array(
                array( 'id' => 21, 'created_at' => '2026-09-10 00:00:00' ),
                array( 'id' => 20, 'created_at' => '2026-09-10 00:00:00' ),
            ),
            100.0
        )['first'];

        // Lower id sorts first, so it is the "first touch".
        self::assertSame( 100.0, $split[20]['amount'] );
        self::assertArrayNotHasKey( 21, $split );
    }

    public function testFacadeForwardsToTheModel(): void {
        $result = gr_calculate_attribution( $this->sequence3(), 100.0 );

        self::assertSame( 100.0, $result['first'][11]['amount'] );
        self::assertSame( 57.15, $result['time_decay'][13]['amount'] );
    }
}
