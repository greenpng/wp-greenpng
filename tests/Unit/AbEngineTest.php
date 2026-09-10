<?php
/**
 * A/B assignment engine (docs/13 C14): stable bucketing, the URL
 * force parameter, definition validation, and the fail-open
 * shortcode.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Funnel\Gr_Ab_Engine;
use GreenPNG\Funnel\Gr_Ab_Experiments;
use GreenPNG\Funnel\Gr_Ab_Shortcode;
use PHPUnit\Framework\TestCase;

final class AbEngineTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();

        Gr_Ab_Experiments::save( 'hero', array( 'control', 'treatment' ), true );
        Gr_Ab_Experiments::save( 'three-way', array( 'a', 'b', 'c' ), true );
        Gr_Ab_Experiments::save( 'paused', array( 'control', 'treatment' ), false );
    }

    protected function tearDown(): void {
        // Assign, never unset: superglobals must stay defined for other
        // tests' isset() checks.
        $_GET    = array();
        $_COOKIE = array();
        parent::tearDown();
    }

    public function testAssignmentIsStableAcrossRepeatedCalls(): void {
        $visitor = str_repeat( '9', 32 );

        $first  = gr_ab_assign_variant( 'hero', $visitor );
        $second = gr_ab_assign_variant( 'hero', $visitor );
        $third  = Gr_Ab_Engine::assign( 'hero', $visitor );

        self::assertNotSame( '', $first );
        self::assertSame( $first, $second );
        self::assertSame( $first, $third );
        self::assertContains( $first, array( 'control', 'treatment' ) );
    }

    public function testPopulationSplitsAcrossBothVariants(): void {
        $counts = array( 'control' => 0, 'treatment' => 0 );
        for ( $i = 0; $i < 1000; $i++ ) {
            $variant = Gr_Ab_Engine::assign( 'hero', str_pad( (string) $i, 32, 'v' ) );
            $counts[ $variant ]++;
        }

        // A consistent hash spreads a thousand visitors across both
        // buckets with room to spare; the exact split is deterministic
        // for this fixed population, so the band only guards against
        // degenerate all-in-one-bucket hashing.
        self::assertGreaterThan( 350, $counts['control'] );
        self::assertGreaterThan( 350, $counts['treatment'] );
        self::assertSame( 1000, $counts['control'] + $counts['treatment'] );
    }

    public function testUnknownOrInactiveExperimentsDoNotAssign(): void {
        self::assertSame( '', gr_ab_assign_variant( 'nope', str_repeat( 'a', 32 ) ) );
        self::assertSame( '', gr_ab_assign_variant( 'paused', str_repeat( 'a', 32 ) ) );
    }

    public function testUrlParameterForcesOntoDeclaredVariantsOnly(): void {
        $visitor = str_repeat( '7', 32 );
        $natural = Gr_Ab_Engine::assign( 'hero', $visitor );

        $_GET = array( 'gr_variant' => 'treatment' );
        self::assertSame( 'treatment', Gr_Ab_Engine::assign( 'hero', $visitor ) );

        $_GET = array( 'gr_variant' => 'control' );
        self::assertSame( 'control', Gr_Ab_Engine::assign( 'hero', $visitor ) );

        // Undeclared values are ignored: the natural bucket stands.
        $_GET = array( 'gr_variant' => 'evil-variant' );
        self::assertSame( $natural, Gr_Ab_Engine::assign( 'hero', $visitor ) );

        $_GET = array( 'gr_variant' => "<script>x</script>" );
        self::assertSame( $natural, Gr_Ab_Engine::assign( 'hero', $visitor ) );
    }

    public function testDefinitionsValidateAndPersistNonAutoloaded(): void {
        // The option was created with autoload no.
        self::assertSame( 'no', $GLOBALS['gr_stub_options']['autoload'][ Gr_Ab_Experiments::OPTION_KEY ] );

        // Duplicates collapse, casing slugifies, the list caps.
        self::assertTrue( Gr_Ab_Experiments::save( 'dupes', array( 'A', 'a ', 'B', 'b' ) ) );
        $definition = Gr_Ab_Experiments::get( 'dupes' );
        self::assertSame( array( 'a', 'b' ), $definition['variants'] );

        // Fewer than two variants is not an experiment.
        self::assertFalse( Gr_Ab_Experiments::save( 'single', array( 'only' ) ) );
        self::assertNull( Gr_Ab_Experiments::get( 'single' ) );

        // Corrupted stored batches are sanitized on read.
        $GLOBALS['gr_stub_options']['data'][ Gr_Ab_Experiments::OPTION_KEY ] = array(
            'good'    => array( 'active' => 1, 'variants' => array( 'x', 'y' ) ),
            'bad'     => 'not-an-array',
            'worst'   => array( 'active' => 1, 'variants' => array( 'one' ) ),
            'UPPER'   => array( 'active' => 1, 'variants' => array( 'm', 'n' ) ),
        );
        Gr_Ab_Experiments::reset_memo_for_tests();
        $all = Gr_Ab_Experiments::all();
        self::assertArrayHasKey( 'good', $all );
        self::assertArrayNotHasKey( 'bad', $all );
        self::assertArrayNotHasKey( 'worst', $all );
        self::assertArrayHasKey( 'upper', $all );

        // Delete round-trips.
        self::assertTrue( Gr_Ab_Experiments::delete( 'good' ) );
        self::assertNull( Gr_Ab_Experiments::get( 'good' ) );
        self::assertFalse( Gr_Ab_Experiments::delete( 'good' ) );

        // Emptying the batch removes the option row entirely instead
        // of parking an empty array in storage.
        $GLOBALS['gr_stub_options']['data'][ Gr_Ab_Experiments::OPTION_KEY ] = array(
            'solo' => array( 'active' => 1, 'variants' => array( 'x', 'y' ) ),
        );
        Gr_Ab_Experiments::reset_memo_for_tests();
        self::assertTrue( Gr_Ab_Experiments::delete( 'solo' ) );
        self::assertArrayNotHasKey( Gr_Ab_Experiments::OPTION_KEY, $GLOBALS['gr_stub_options']['data'] );
    }

    public function testShortcodeRendersAssignedVariantContent(): void {
        $GLOBALS['gr_stub_consent']['marketing'] = true;
        $visitor = str_repeat( '5', 32 );
        $_COOKIE = array( \GreenPNG\Attribution\Gr_Identity::COOKIE => \GreenPNG\Attribution\Gr_Identity::cookie_value( $visitor ) );

        $assigned = Gr_Ab_Engine::assign( 'hero', gr()->identity()->visitor_id() );
        $expected = 'treatment' === $assigned ? 'Half off today' : 'Buy now';

        $out = Gr_Ab_Shortcode::render(
            array(
                'experiment' => 'hero',
                'control'    => 'Buy now',
                'treatment'  => 'Half off today',
            )
        );
        self::assertSame( $expected, $out );
    }

    public function testShortcodeFailsOpenToControlContent(): void {
        // Unknown experiment: control content still renders.
        $out = Gr_Ab_Shortcode::render(
            array(
                'experiment' => 'never-heard-of',
                'control'    => 'Safe default',
                'treatment'  => 'Risky copy',
            )
        );
        self::assertSame( 'Safe default', $out );

        // No experiment attribute at all: nothing to render.
        self::assertSame( '', Gr_Ab_Shortcode::render( array( 'control' => 'x' ) ) );

        // The 'a' attribute reads as control when the author used it.
        $a_style = Gr_Ab_Shortcode::render(
            array(
                'experiment' => 'never-heard-of',
                'a'          => 'Version A copy',
                'b'          => 'Version B copy',
            )
        );
        self::assertSame( 'Version A copy', $a_style );
    }

    public function testPluginRegistersTheShortcode(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        self::assertArrayHasKey( Gr_Ab_Shortcode::TAG, $GLOBALS['gr_stub_shortcodes'] );
    }
}
