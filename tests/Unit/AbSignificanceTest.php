<?php
/**
 * A/B recording and significance (docs/13 C15): the ab_* columns on
 * the event stream, and the two-proportion Z-test with hand-computed
 * expected values for all three states.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Funnel\Gr_Ab_Experiments;
use GreenPNG\Funnel\Gr_Ab_Recorder;
use GreenPNG\Funnel\Gr_Ab_Significance;
use PHPUnit\Framework\TestCase;

final class AbSignificanceTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();

        $_SERVER['REMOTE_ADDR']      = '10.0.0.9';
        $_SERVER['HTTP_USER_AGENT'] = 'UnitTestAgent/1.0';

        Gr_Ab_Experiments::save( 'hero', array( 'control', 'treatment' ), true );
        Gr_Ab_Experiments::save( 'three-way', array( 'control', 'b', 'c' ), true );
        Gr_Ab_Experiments::save( 'paused', array( 'control', 'treatment' ), false );
    }

    protected function tearDown(): void {
        unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] );
        $_COOKIE = array();
        parent::tearDown();
    }

    /**
     * Stages stream counts for one experiment.
     *
     * @param string                        $experiment Experiment key.
     * @param array<string, array<int,int>> $arms       variant => [impressions, conversions].
     * @return void
     */
    private function stage( string $experiment, array $arms ): void {
        global $wpdb;

        $rows = array();
        foreach ( $arms as $variant => $counts ) {
            $rows[] = array( 'ab_variant' => $variant, 'ab_type' => 'impression', 'n' => (string) $counts[0] );
            $rows[] = array( 'ab_variant' => $variant, 'ab_type' => 'conversion', 'n' => (string) $counts[1] );
        }
        $wpdb->results = $rows;
    }

    public function testRecordWritesStreamColumnsForBothTypes(): void {
        global $wpdb;

        $this->assertTrue( gr_ab_record( 'hero', 'treatment', 'impression' ) );

        $last = end( $wpdb->inserts );
        self::assertSame( 'wp_gr_events', $last['table'] );
        self::assertSame( 'ab', $last['data']['event_name'] );
        self::assertSame( 'funnel', $last['data']['event_group'] );
        self::assertSame( 'hero', $last['data']['ab_experiment'] );
        self::assertSame( 'treatment', $last['data']['ab_variant'] );
        self::assertSame( 'impression', $last['data']['ab_type'] );

        $this->assertTrue( gr_ab_record( 'hero', 'control', 'conversion' ) );
        $last = end( $wpdb->inserts );
        self::assertSame( 'conversion', $last['data']['ab_type'] );
        self::assertSame( 'control', $last['data']['ab_variant'] );
    }

    public function testRecordRejectsInvalidInput(): void {
        // Types outside the two-word vocabulary.
        self::assertFalse( gr_ab_record( 'hero', 'treatment', 'click' ) );
        self::assertFalse( gr_ab_record( 'hero', 'treatment', '' ) );

        // Variants the experiment never declared.
        self::assertFalse( gr_ab_record( 'hero', 'evil-variant', 'impression' ) );

        // Experiments that do not exist.
        self::assertFalse( gr_ab_record( 'ghost', 'control', 'impression' ) );

        // The definition table stays clean of rejected writes.
        self::assertSame( array(), $GLOBALS['wpdb']->inserts );
    }

    public function testPausedExperimentStillRecordsInFlightVisitors(): void {
        // A paused experiment keeps counting: bucketed visitors still
        // convert after the pause, and dropping them would understate
        // every arm.
        $this->assertTrue( gr_ab_record( 'paused', 'control', 'conversion' ) );
    }

    public function testSignificantPairMatchesHandComputedZ(): void {
        // Hand-computed: control 10/100 (0.10), treatment 20/100 (0.20).
        // Pooled p = 30/200 = 0.15; SE = sqrt(0.15*0.85*0.02) =
        // sqrt(0.00255) = 0.050498; z = 0.10/0.050498 = 1.9803 >= 1.96.
        $this->stage(
            'hero',
            array(
                'control'   => array( 100, 10 ),
                'treatment' => array( 100, 20 ),
            )
        );

        $out = gr_ab_significance( 'hero' );

        self::assertSame( 'significant', $out['status'] );
        self::assertCount( 1, $out['pairs'] );
        $pair = $out['pairs'][0];
        self::assertSame( 'control', $pair['control'] );
        self::assertSame( 'treatment', $pair['variant'] );
        self::assertSame( 1.9803, $pair['z'] );
        self::assertSame( 'treatment', $pair['winner'] );
        self::assertSame( 95, $pair['confidence'] );
        self::assertSame( 'significant', $pair['state'] );

        self::assertSame( 0.1, $out['variants']['control']['cvr'] );
        self::assertSame( 0.2, $out['variants']['treatment']['cvr'] );
    }

    public function testSmallDifferenceIsInconclusive(): void {
        // Hand-computed: 12/100 vs 14/100. Pooled p = 0.13; SE =
        // sqrt(0.13*0.87*0.02) = sqrt(0.002262) = 0.047560;
        // z = 0.02/0.047560 = 0.4205 < 1.96.
        $this->stage(
            'hero',
            array(
                'control'   => array( 100, 12 ),
                'treatment' => array( 100, 14 ),
            )
        );

        $out = gr_ab_significance( 'hero' );

        self::assertSame( 'inconclusive', $out['status'] );
        self::assertSame( 0.4205, $out['pairs'][0]['z'] );
        self::assertSame( 'tie', $out['pairs'][0]['winner'] );
        self::assertSame( 0, $out['pairs'][0]['confidence'] );
    }

    public function testBelowThirtyPerArmIsInsufficient(): void {
        // A strong lift on tiny arms: 5/20 vs 8/20 would look
        // dramatic, but neither arm reaches 30 impressions.
        $this->stage(
            'hero',
            array(
                'control'   => array( 20, 5 ),
                'treatment' => array( 20, 8 ),
            )
        );

        $out = gr_ab_significance( 'hero' );

        self::assertSame( 'insufficient', $out['status'] );
        self::assertSame( 'insufficient', $out['pairs'][0]['state'] );
        self::assertSame( 0.0, $out['pairs'][0]['z'] );
        self::assertSame( '', $out['pairs'][0]['winner'] );
    }

    public function testIdenticalAbsoluteRatesNeverReachSignificance(): void {
        // Nobody converts on either arm: pooled p = 0, SE = 0, the
        // difference is unreadable, and the verdict is a tie rather
        // than a fabricated z.
        $this->stage(
            'hero',
            array(
                'control'   => array( 100, 0 ),
                'treatment' => array( 100, 0 ),
            )
        );

        $out = gr_ab_significance( 'hero' );

        self::assertSame( 'inconclusive', $out['status'] );
        self::assertSame( 'tie', $out['pairs'][0]['winner'] );
    }

    public function testThreeWayExperimentComparesControlToEach(): void {
        $this->stage(
            'three-way',
            array(
                'control' => array( 200, 30 ),
                'b'       => array( 200, 60 ),
                'c'       => array( 200, 33 ),
            )
        );

        $out = gr_ab_significance( 'three-way' );

        self::assertCount( 2, $out['pairs'] );
        self::assertSame( 'b', $out['pairs'][0]['variant'] );
        self::assertSame( 'c', $out['pairs'][1]['variant'] );

        // control vs b: pooled 90/400 = 0.225; SE = sqrt(0.225*0.775*0.01)
        // = sqrt(0.00174375) = 0.0417582; z = (0.30-0.15)/0.0417582 = 3.5921.
        self::assertSame( 3.5921, $out['pairs'][0]['z'] );
        self::assertSame( 'significant', $out['pairs'][0]['state'] );
        self::assertSame( 'b', $out['pairs'][0]['winner'] );

        // control vs c: pooled 63/400 = 0.1575; SE =
        // sqrt(0.1575*0.8425*0.01) = sqrt(0.0013269375) = 0.0364270;
        // z = (0.165-0.15)/0.0364270 = 0.4118.
        self::assertSame( 0.4118, $out['pairs'][1]['z'] );
        self::assertSame( 'inconclusive', $out['pairs'][1]['state'] );

        // One significant pair lifts the whole experiment.
        self::assertSame( 'significant', $out['status'] );
    }

    public function testUnknownExperimentReportsInsufficientQuietly(): void {
        $out = gr_ab_significance( 'never-defined' );

        self::assertSame( 'never-defined', $out['experiment'] );
        self::assertSame( 'insufficient', $out['status'] );
        self::assertSame( array(), $out['variants'] );
        self::assertSame( array(), $out['pairs'] );
    }
}
