<?php
/**
 * Lead scoring engine (ADR-0013 D3/D4 boundaries): the bot verdict
 * outranking every rule with its attach-only system tag, the per-day
 * capped windowed computation, the 0..100 clamp, and the three
 * trigger faces (daily rider, wave recompute, on-demand single).
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\CRM\Gr_Scoring_Engine;
use GreenPNG\CRM\Gr_Scoring_Rules;
use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Core\Gr_Queue;
use PHPUnit\Framework\TestCase;

final class ScoringEngineTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        $GLOBALS['gr_stub_cron']  = array();
        $GLOBALS['gr_stub_actions'] = array();

        global $wpdb;
        $wpdb->queries     = array();
        $wpdb->results     = array();
        $wpdb->var_result  = null;
        $wpdb->insert_id   = 0;
        $wpdb->query_result = 0;
    }

    protected function tearDown(): void {
        Gr_Plugin::reset_instance();
        parent::tearDown();
    }

    /**
     * Seeds the read closure for one contact's full recompute chain.
     *
     * @param string  $visitor       Visitor id on the contact row.
     * @param bool    $bot           Whether the session read says bot.
     * @param array   $event_counts  name => day => count.
     * @param array   $sale_counts   day => count.
     * @return void
     */
    private function seed( string $visitor, bool $bot = false, array $event_counts = array(), array $sale_counts = array() ): void {
        global $wpdb;

        // The bot read is a get_var: the stub answers those from
        // var_result, not from the results closure.
        $wpdb->var_result = $bot ? '1' : null;

        $wpdb->results = static function ( string $sql ) use ( $visitor, $bot, $event_counts, $sale_counts ): array {
            if ( false !== strpos( $sql, 'FROM wp_gr_contacts WHERE id =' ) ) {
                return array(
                    array(
                        'id'          => '7',
                        'visitor_id'  => $visitor,
                        'lead_score'  => '0',
                        'rfm_segment' => '',
                    ),
                );
            }
            if ( false !== strpos( $sql, 'is_bot = 1' ) ) {
                return $bot ? array( array( '1' ) ) : array();
            }
            if ( false !== strpos( $sql, 'DATE(created_at)' ) && false !== strpos( $sql, 'event_name' ) ) {
                $rows = array();
                foreach ( $event_counts as $name => $days ) {
                    foreach ( $days as $day => $n ) {
                        $rows[] = array( 'event_name' => $name, 'day' => $day, 'n' => (string) $n );
                    }
                }
                return $rows;
            }
            if ( false !== strpos( $sql, 'DATE(created_at)' ) ) {
                $rows = array();
                foreach ( $sale_counts as $day => $n ) {
                    $rows[] = array( 'day' => $day, 'n' => (string) $n );
                }
                return $rows;
            }
            return array();
        };
    }

    /**
     * The score UPDATE statements recorded against contacts.
     *
     * @return array<int, string>
     */
    private function score_updates(): array {
        global $wpdb;

        return array_values(
            array_unique(
                array_filter(
                    $wpdb->queries,
                    static function ( $sql ): bool {
                        return is_string( $sql ) && false !== strpos( $sql, 'UPDATE wp_gr_contacts SET lead_score' );
                    }
                )
            )
        );
    }

    public function testUnknownContactScoresNothingWithoutWriting(): void {
        global $wpdb;

        self::assertSame( 0, Gr_Scoring_Engine::recompute_contact( 999 ) );
        self::assertStringNotContainsString( 'UPDATE wp_gr_contacts', implode( ' ', $wpdb->queries ) );
    }

    public function testContactWithoutVisitorBindingHoldsAnHonestZero(): void {
        $this->seed( '' );

        self::assertSame( 0, Gr_Scoring_Engine::recompute_contact( 7 ) );
        $updates = $this->score_updates();
        self::assertCount( 1, $updates );
        self::assertStringContainsString( 'lead_score = 0', $updates[0] );
    }

    public function testBotVerdictOutranksEveryRuleAndAttachesTheSystemTag(): void {
        global $wpdb;

        Gr_Scoring_Rules::save(
            array( array( 'event_name' => 'pageview', 'points' => 100, 'daily_cap' => 10, 'active' => 1 ) )
        );
        $this->seed( 'botvisitor', true, array( 'pageview' => array( '2026-09-14' => 50 ) ) );

        self::assertSame( 0, Gr_Scoring_Engine::recompute_contact( 7 ) );

        $sql = implode( ' ', $wpdb->queries );
        self::assertStringContainsString( "sys:suspected_bot", $sql );
        self::assertStringContainsString( 'INSERT IGNORE INTO wp_gr_contact_tags', $sql );
        self::assertStringContainsString( 'UPDATE wp_gr_contacts SET lead_score = 0', $sql );
        // The verdict short-circuits: no event counting ever ran.
        self::assertStringNotContainsString( 'FROM wp_gr_events', $sql );
    }

    public function testWindowedCappedComputationLandsTheExactScore(): void {
        global $wpdb;

        Gr_Scoring_Rules::save(
            array(
                array( 'event_name' => 'pageview', 'points' => 5, 'daily_cap' => 2, 'active' => 1 ),
                array( 'event_name' => 'conversion', 'points' => 20, 'daily_cap' => 1, 'active' => 1 ),
                array( 'event_name' => 'dwell', 'points' => 3, 'daily_cap' => 4, 'active' => 0 ),
            )
        );
        $this->seed(
            'visitor-1',
            false,
            array(
                'pageview' => array( '2026-09-13' => 3, '2026-09-14' => 1 ),
                'dwell'    => array( '2026-09-14' => 9 ),
            ),
            array( '2026-09-14' => 2 )
        );

        // pageview: min(3,2)+min(1,2)=3 -> 15; conversion: min(2,1)=1 -> 20;
        // dwell inactive despite 9 events. 15+20 = 35.
        self::assertSame( 35, Gr_Scoring_Engine::recompute_contact( 7 ) );
        self::assertStringContainsString( 'UPDATE wp_gr_contacts SET lead_score = 35', implode( ' ', $wpdb->queries ) );
    }

    public function testRawScoresClampIntoTheZeroToHundredBand(): void {
        Gr_Scoring_Rules::save(
            array( array( 'event_name' => 'rage_click', 'points' => 100, 'daily_cap' => 10, 'active' => 1 ) )
        );
        $this->seed( 'visitor-2', false, array( 'rage_click' => array( '2026-09-14' => 10 ) ) );
        self::assertSame( 100, Gr_Scoring_Engine::recompute_contact( 7 ) );

        gr_stub_reset_options();
        Gr_Scoring_Rules::save(
            array( array( 'event_name' => 'dead_click', 'points' => -100, 'daily_cap' => 10, 'active' => 1 ) )
        );
        $this->seed( 'visitor-3', false, array( 'dead_click' => array( '2026-09-14' => 10 ) ) );
        self::assertSame( 0, Gr_Scoring_Engine::recompute_contact( 7 ) );
    }

    public function testZeroCapRuleCountsNothing(): void {
        Gr_Scoring_Rules::save(
            array( array( 'event_name' => 'pageview', 'points' => 10, 'daily_cap' => 0, 'active' => 1 ) )
        );
        $this->seed( 'visitor-4', false, array( 'pageview' => array( '2026-09-14' => 7 ) ) );

        self::assertSame( 0, Gr_Scoring_Engine::recompute_contact( 7 ) );
    }

    public function testNoRulesMeansAZeroScore(): void {
        global $wpdb;

        $this->seed( 'visitor-5', false, array( 'pageview' => array( '2026-09-14' => 5 ) ) );

        self::assertSame( 0, Gr_Scoring_Engine::recompute_contact( 7 ) );
        self::assertStringContainsString( 'UPDATE wp_gr_contacts SET lead_score = 0', implode( ' ', $wpdb->queries ) );
    }

    public function testEngineRegistersTheDailyRiderAndTheRecomputeJob(): void {
        Gr_Scoring_Engine::register_hooks();

        $registered = array();
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            if ( Gr_Queue::DAILY_HOOK === (string) $registration['hook'] ) {
                $registered[] = 'daily@' . $registration['priority'];
            }
            if ( Gr_Scoring_Engine::RECOMPUTE_HOOK === (string) $registration['hook'] ) {
                $registered[] = 'recompute';
            }
        }

        self::assertContains( 'daily@8', $registered, 'Scoring runs after the aggregator (5), before retention (10).' );
        self::assertContains( 'recompute', $registered );
    }

    public function testDailyRiderRescoresRecentlyActiveContacts(): void {
        global $wpdb;

        Gr_Scoring_Rules::save(
            array( array( 'event_name' => 'pageview', 'points' => 5, 'daily_cap' => 1, 'active' => 1 ) )
        );

        $wpdb->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'last_seen >=' ) ) {
                return array( array( 'id' => '7' ), array( 'id' => '8' ) );
            }
            if ( false !== strpos( $sql, 'FROM wp_gr_contacts WHERE id =' ) ) {
                return array( array( 'id' => '7', 'visitor_id' => 'v7', 'lead_score' => '0', 'rfm_segment' => '' ) );
            }
            if ( false !== strpos( $sql, 'is_bot = 1' ) ) {
                return array();
            }
            if ( false !== strpos( $sql, 'DATE(created_at)' ) && false !== strpos( $sql, 'event_name' ) ) {
                return array( array( 'event_name' => 'pageview', 'day' => '2026-09-14', 'n' => '2' ) );
            }
            return array();
        };

        $wpdb->var_result = null;

        Gr_Scoring_Engine::run_daily();

        $sql = implode( ' ', $wpdb->queries );
        self::assertMatchesRegularExpression( "/last_seen >= '20\d\d-\d\d-\d\d \d\d:\d\d:\d\d'/", $sql );
        // Deduped statements (prepare and the runner record identical
        // lines): one UPDATE per rescored contact.
        self::assertCount( 2, $this->score_updates() );
        foreach ( $this->score_updates() as $update ) {
            self::assertStringContainsString( 'lead_score = 5', $update );
        }
    }

    public function testRecomputeAllRunsInWavesAndReEnqueues(): void {
        global $wpdb;

        Gr_Scoring_Rules::save( array() );

        $ids = array();
        for ( $i = 1; $i <= Gr_Scoring_Engine::WAVE; $i++ ) {
            $ids[] = array( 'id' => (string) $i );
        }
        $wpdb->results = static function ( string $sql ) use ( $ids ): array {
            if ( false !== strpos( $sql, 'WHERE id >' ) ) {
                return $ids;
            }
            if ( false !== strpos( $sql, 'FROM wp_gr_contacts WHERE id =' ) ) {
                return array();
            }
            return array();
        };

        Gr_Scoring_Engine::recompute_all( 0 );

        // A full wave re-enqueues from its last id.
        $follow_ups = array();
        foreach ( $GLOBALS['gr_stub_cron'] as $job ) {
            if ( Gr_Scoring_Engine::RECOMPUTE_HOOK === (string) $job['hook'] ) {
                $follow_ups[] = $job['args'];
            }
        }
        self::assertCount( 1, $follow_ups );
        self::assertSame( array( Gr_Scoring_Engine::WAVE ), $follow_ups[0] );

        // A short wave is the end of the job: no follow-up.
        $GLOBALS['gr_stub_cron'] = array();
        $wpdb->queries = array();
        $wpdb->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'WHERE id >' ) ) {
                return array( array( 'id' => '9000' ) );
            }
            if ( false !== strpos( $sql, 'FROM wp_gr_contacts WHERE id =' ) ) {
                return array();
            }
            return array();
        };

        Gr_Scoring_Engine::recompute_all( 500 );

        foreach ( $GLOBALS['gr_stub_cron'] as $job ) {
            self::assertNotSame( Gr_Scoring_Engine::RECOMPUTE_HOOK, (string) $job['hook'] );
        }
    }
}
