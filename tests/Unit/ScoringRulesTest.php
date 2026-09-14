<?php
/**
 * Scoring rules store (ADR-0013 D3): validation boundaries, the
 * closed vocabulary, duplicate collapsing, the row ceiling, and the
 * autoload=no option discipline.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\CRM\Gr_Scoring_Rules;
use PHPUnit\Framework\TestCase;

final class ScoringRulesTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        $GLOBALS['gr_stub_cron'] = array();
    }

    protected function tearDown(): void {
        parent::tearDown();
    }

    public function testAbsentOptionReadsAsNoRules(): void {
        self::assertSame( array(), Gr_Scoring_Rules::all() );
        self::assertSame( array(), Gr_Scoring_Rules::active() );
    }

    public function testSavePersistsTheNormalizedRuleset(): void {
        $saved = Gr_Scoring_Rules::save(
            array(
                array( 'event_name' => ' pageview ', 'points' => '5', 'daily_cap' => '2', 'active' => '1' ),
                array( 'event_name' => 'conversion', 'points' => -20, 'daily_cap' => 10, 'active' => 0 ),
            )
        );

        self::assertTrue( $saved );
        self::assertSame(
            array(
                array(
                    'event_name' => 'pageview',
                    'points'     => 5,
                    'daily_cap'  => 2,
                    'active'     => true,
                ),
                array(
                    'event_name' => 'conversion',
                    'points'     => -20,
                    'daily_cap'  => 10,
                    'active'     => false,
                ),
            ),
            Gr_Scoring_Rules::all()
        );
        self::assertSame(
            array( 'pageview' ),
            array_column( Gr_Scoring_Rules::active(), 'event_name' ),
            'Only active rules reach the engine.'
        );
    }

    public function testDuplicateEventNamesCollapseOntoTheLastRow(): void {
        $saved = Gr_Scoring_Rules::save(
            array(
                array( 'event_name' => 'dwell', 'points' => 1, 'daily_cap' => 3, 'active' => 1 ),
                array( 'event_name' => 'dwell', 'points' => 9, 'daily_cap' => 4, 'active' => 1 ),
            )
        );

        self::assertTrue( $saved );
        $rules = Gr_Scoring_Rules::all();
        self::assertCount( 1, $rules );
        self::assertSame( 9, $rules[0]['points'] );
    }

    public function testTheRowCeilingIsAHardError(): void {
        $rules = array();
        for ( $i = 0; $i < 31; $i++ ) {
            $rules[] = array( 'event_name' => 'pageview', 'points' => 1, 'daily_cap' => 1, 'active' => 1 );
        }

        $saved = Gr_Scoring_Rules::save( $rules );

        self::assertInstanceOf( \WP_Error::class, $saved );
        self::assertSame( 'gr_scoring_rules_count', $saved->get_error_code() );
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function invalidRuleProvider(): array {
        return array(
            'name outside the vocabulary' => array( array( 'event_name' => 'newsletter_open', 'points' => 5, 'daily_cap' => 2, 'active' => 1 ), 'gr_scoring_rules_name' ),
            'points above 100'            => array( array( 'event_name' => 'pageview', 'points' => 101, 'daily_cap' => 2, 'active' => 1 ), 'gr_scoring_rules_invalid' ),
            'points below -100'           => array( array( 'event_name' => 'pageview', 'points' => -101, 'daily_cap' => 2, 'active' => 1 ), 'gr_scoring_rules_invalid' ),
            'cap above 10'                => array( array( 'event_name' => 'pageview', 'points' => 5, 'daily_cap' => 11, 'active' => 1 ), 'gr_scoring_rules_invalid' ),
            'cap negative'                => array( array( 'event_name' => 'pageview', 'points' => 5, 'daily_cap' => -1, 'active' => 1 ), 'gr_scoring_rules_invalid' ),
            'empty name'                  => array( array( 'event_name' => '', 'points' => 5, 'daily_cap' => 2, 'active' => 1 ), 'gr_scoring_rules_invalid' ),
            'non-array row'               => array( 'not-a-rule', 'gr_scoring_rules_shape' ),
        );
    }

    /**
     * @dataProvider invalidRuleProvider
     *
     * @param mixed  $rule Raw rule row.
     * @param string $code Expected error code.
     */
    public function testInvalidRowsAreRefusedWithErrorAndRow( $rule, string $code ): void {
        $saved = Gr_Scoring_Rules::save( array( $rule ) );

        self::assertInstanceOf( \WP_Error::class, $saved );
        self::assertSame( $code, $saved->get_error_code() );
        self::assertSame( 1, $saved->get_error_data()['row'], 'The error names the offending row.' );
    }

    public function testTheReadPathSkipsMalformedStoredRows(): void {
        update_option(
            Gr_Scoring_Rules::OPTION,
            array(
                array( 'event_name' => 'dwell', 'points' => 2, 'daily_cap' => 1, 'active' => true ),
                'garbage',
                array( 'event_name' => 'pageview', 'points' => 999, 'daily_cap' => 1, 'active' => true ),
            )
        );

        $rules = Gr_Scoring_Rules::all();

        self::assertCount( 1, $rules );
        self::assertSame( 'dwell', $rules[0]['event_name'] );
    }
}
