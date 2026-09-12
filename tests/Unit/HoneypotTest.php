<?php
/**
 * Honeypot trap (docs/13 W8, docs/06 §4, docs/11): the accessibility
 * markup contract, dynamic decoy names, the sealed carrier, the
 * trap/time-delta judgements and their boundaries, purity, and the
 * opt-in gate wiring for both core forms.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Security\Gr_Honeypot;
use PHPUnit\Framework\TestCase;

final class HoneypotTest extends TestCase {

    /** Probe address, kept out of the loopback ranges. */
    private const IP = '203.0.113.77';

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();

        $_SERVER['REMOTE_ADDR']     = self::IP;
        $_SERVER['HTTP_USER_AGENT'] = 'UnitTestAgent/1.0';
        $_SERVER['REQUEST_METHOD']  = 'POST';
        $_SERVER['REQUEST_URI']     = '/wp-login.php';
    }

    protected function tearDown(): void {
        unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'], $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'] );
        $_POST = array();

        parent::tearDown();
    }

    /**
     * Turns the module on for one test.
     *
     * @return void
     */
    private function enable(): void {
        gr()->settings()->set( 'honeypot_enabled', 1 );
    }

    /**
     * One rendered login form, parsed into its moving parts.
     *
     * @return array{html: string, trap: string, carrier_name: string, carrier_value: string}
     */
    private function render_parts(): array {
        $this->enable();
        $html = gr_render_honeypot( 'login' );

        $this->assertNotSame( '', $html );

        $this->assertSame(
            1,
            preg_match( '/<input type="text" name="((?:url|company|phone|fax|zip)_[0-9a-f]{6})" value=""/', $html, $text_input ),
            'trap input markup shape'
        );
        $this->assertSame(
            1,
            preg_match( '/<input type="hidden" name="((?:url|company|phone|fax|zip)_[0-9a-f]{6})" value="(gr1\.[A-Za-z0-9+\/=]+)" \/>/', $html, $hidden_input ),
            'carrier input markup shape'
        );

        return array(
            'html'          => $html,
            'trap'          => $text_input[1],
            'carrier_name'  => $hidden_input[1],
            'carrier_value' => $hidden_input[2],
        );
    }

    /**
     * A bot-shaped POST: the trap filled plus the sealed carrier, as a
     * form filler would submit the rendered page.
     *
     * @return array<int|string, string>
     */
    private function bot_post(): array {
        $parts = $this->render_parts();

        return array(
            'log'                    => 'admin',
            'pwd'                    => 'password',
            $parts['trap']           => 'http://spam.example/offer',
            $parts['carrier_name']   => $parts['carrier_value'],
        );
    }

    public function testDefaultOffRendersNothingAnywhere(): void {
        $this->assertSame( '', gr_render_honeypot( 'login' ) );

        Gr_Honeypot::register_hooks();

        ob_start();
        do_action( 'login_form' );
        $login_out = ob_get_clean();
        $this->assertSame( '', $login_out );

        ob_start();
        do_action( 'register_form' );
        $register_out = ob_get_clean();
        $this->assertSame( '', $register_out );
    }

    public function testBothCoreFormsInjectTheTrapWhenEnabled(): void {
        $this->enable();
        Gr_Honeypot::register_hooks();

        ob_start();
        do_action( 'login_form' );
        $login_out = ob_get_clean();

        ob_start();
        do_action( 'register_form' );
        $register_out = ob_get_clean();

        $this->assertStringContainsString( 'aria-hidden="true"', $login_out );
        $this->assertStringContainsString( 'aria-hidden="true"', $register_out );
    }

    public function testMarkupHidesFromAssistiveTechAndKeyboards(): void {
        $html = $this->render_parts()['html'];

        // docs/06 §4: aria-hidden + tabindex carry the hiding contract;
        // display:none is exactly what some readers still announce.
        $this->assertStringContainsString( 'aria-hidden="true"', $html );
        $this->assertStringContainsString( 'tabindex="-1"', $html );
        $this->assertStringContainsString( 'autocomplete="off"', $html );
        $this->assertStringNotContainsString( 'display:none', $html );
        $this->assertStringNotContainsString( 'visibility:hidden', $html );
    }

    public function testFieldNamesAreDynamicAndPlausible(): void {
        $first  = $this->render_parts();
        $second = $this->render_parts();

        $this->assertNotSame( $first['trap'], $second['trap'], 'two renders draw different trap names' );
        $this->assertNotSame( $first['carrier_name'], $first['trap'], 'carrier and trap never share a name' );
        $this->assertMatchesRegularExpression( '/^(?:url|company|phone|fax|zip)_[0-9a-f]{6}$/', $first['trap'] );
        $this->assertMatchesRegularExpression( '/^(?:url|company|phone|fax|zip)_[0-9a-f]{6}$/', $first['carrier_name'] );
        $this->assertStringStartsWith( 'gr1.', $first['carrier_value'] );
    }

    public function testTrapFilledIsAutomation(): void {
        $post   = $this->bot_post();
        $verdict = Gr_Honeypot::evaluate( $post );

        $this->assertNotNull( $verdict );
        $this->assertSame( 'trap', $verdict['reason'] );
        $this->assertSame( 'login', $verdict['context'] );
        $this->assertTrue( gr_check_honeypot( $post ) );
    }

    public function testWhitespaceOnlyTrapReadsAsUntouched(): void {
        $this->enable();
        $trap    = 'url_aa11bb';
        $carrier = Gr_Honeypot::render_carrier( $trap, 'login', time() - 10 );

        $post = array(
            $trap             => '   ',
            'anything'        => $carrier,
        );

        $this->assertFalse( gr_check_honeypot( $post ) );
    }

    public function testSlowSubmissionWithEmptyTrapIsHuman(): void {
        $this->enable();
        $trap    = 'url_aabbcc';
        $carrier = Gr_Honeypot::render_carrier( $trap, 'login', time() - 10 );

        $post = array(
            'log'             => 'admin',
            'pwd'             => 'password',
            $trap             => '',
            'carrier_field'   => $carrier,
        );

        $this->assertFalse( gr_check_honeypot( $post ) );
    }

    public function testFastSubmissionIsAutomationWithTwoSecondBoundary(): void {
        $this->enable();
        $trap = 'url_ccdd01';

        $one_second = Gr_Honeypot::render_carrier( $trap, 'login', time() - 1 );
        $two_second = Gr_Honeypot::render_carrier( $trap, 'login', time() - 2 );

        $this->assertTrue( gr_check_honeypot( array( $trap => '', 'c' => $one_second ) ) );
        $this->assertFalse( gr_check_honeypot( array( $trap => '', 'c' => $two_second ) ) );
    }

    public function testImmediateRoundTripOfARenderedFormIsAutomation(): void {
        $parts = $this->render_parts();

        // A real visitor cannot even paste-and-submit inside the same
        // second the page was rendered; a scraper posts back instantly.
        $post = array(
            'log'                  => 'admin',
            'pwd'                  => 'password',
            $parts['trap']         => '',
            $parts['carrier_name'] => $parts['carrier_value'],
        );

        $verdict = Gr_Honeypot::evaluate( $post );

        $this->assertNotNull( $verdict );
        $this->assertSame( 'fast', $verdict['reason'] );
        $this->assertTrue( gr_check_honeypot( $post ) );
    }

    public function testTamperedCarrierIsNotJudged(): void {
        $this->enable();
        $trap    = 'url_112233';
        $carrier = Gr_Honeypot::render_carrier( $trap, 'login', time() );

        // Flip one character inside the sealed envelope: GCM
        // authentication fails, so the submission stays unjudged even
        // with the trap filled — evasion, not evidence.
        $tampered = substr( $carrier, 0, 10 )
            . ( 'A' === substr( $carrier, 10, 1 ) ? 'B' : 'A' )
            . substr( $carrier, 11 );

        $this->assertFalse( gr_check_honeypot( array( $trap => 'filled', 'c' => $tampered ) ) );
    }

    public function testMissingCarrierIsNotJudged(): void {
        $this->assertSame( false, gr_check_honeypot( array( 'url_445566' => 'filled', 'log' => 'admin' ) ) );
        $this->assertFalse( gr_check_honeypot( array() ) );
    }

    public function testJudgementTouchesNoStorage(): void {
        global $wpdb;

        $this->enable();
        $post = $this->bot_post();

        $before = count( $wpdb->queries );
        $this->assertTrue( gr_check_honeypot( $post ) );

        $this->assertSame( $before, count( $wpdb->queries ), 'the judgement is CPU-only' );
    }

    public function testLoginGateRecordsFoldRowAndPassesInLogMode(): void {
        global $wpdb;

        $this->enable();
        $_POST = $this->bot_post();
        Gr_Honeypot::register_hooks();

        $user = (object) array( 'id' => 1 );

        $this->assertSame( $user, apply_filters( 'authenticate', $user ) );

        $honeypot_inserts = array_filter(
            $wpdb->queries,
            static function ( $sql ): bool {
                return false !== strpos( (string) $sql, 'INSERT INTO wp_gr_security_logs' )
                    && false !== strpos( (string) $sql, 'honeypot' );
            }
        );

        $this->assertNotEmpty( $honeypot_inserts, 'the finding folded into the W6 log' );
        $this->assertStringContainsString( 'honeypot trap filled (login)', (string) reset( $honeypot_inserts ) );
    }

    public function testLoginGateDeniesInBlockMode(): void {
        $this->enable();
        gr()->settings()->set( 'security_action_mode', 'block' );

        $_POST = $this->bot_post();
        Gr_Honeypot::register_hooks();

        $denied = apply_filters( 'authenticate', (object) array( 'id' => 1 ) );

        $this->assertInstanceOf( 'WP_Error', $denied );
        $this->assertSame( 'gr_honeypot', $denied->get_error_code() );
    }

    public function testRegisterGateRecordOnlyThenBlock(): void {
        $this->enable();

        $_POST = $this->bot_post();
        Gr_Honeypot::register_hooks();

        // Default log mode: the verdict is recorded, the value passes.
        $this->assertNull( apply_filters( 'registration_errors', null ) );

        gr()->settings()->set( 'security_action_mode', 'block' );

        $denied = apply_filters( 'registration_errors', null );

        $this->assertInstanceOf( 'WP_Error', $denied );
        $this->assertContains( 'gr_honeypot', $denied->get_error_codes() );
    }

    public function testDisabledGateIsInert(): void {
        global $wpdb;

        // The bot captured its form HTML while the module was on; the
        // gate must stay inert once the site owner has turned it off.
        $_POST = $this->bot_post();
        gr()->settings()->set( 'honeypot_enabled', 0 );
        Gr_Honeypot::register_hooks();

        $user = (object) array( 'id' => 1 );

        $this->assertSame( $user, apply_filters( 'authenticate', $user ) );

        $honeypot_inserts = array_filter(
            $wpdb->queries,
            static function ( $sql ): bool {
                return false !== strpos( (string) $sql, 'INSERT INTO wp_gr_security_logs' )
                    && false !== strpos( (string) $sql, 'honeypot' );
            }
        );

        $this->assertEmpty( $honeypot_inserts );
    }
}
