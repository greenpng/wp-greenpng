<?php
/**
 * Form bridges (docs/13 C11): availability gating, the main-hook
 * binding with semantic extraction, the fallback drift sentinel, the
 * per-request synthetic id, and Throwable isolation. Marker probing
 * (constant, functions) is process-irreversible, so every absent
 * case runs before the first present marker is defined.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Attribution\Gr_Attribution_Service;
use GreenPNG\Attribution\Gr_Identity;
use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Core\Gr_Settings;
use GreenPNG\Integrations\Adapter_Interface;
use GreenPNG\Integrations\Ecosystem\Gr_Cf7_Adapter;
use GreenPNG\Integrations\Ecosystem\Gr_Fluentforms_Adapter;
use GreenPNG\Integrations\Ecosystem\Gr_Form_Adapter_Base;
use GreenPNG\Integrations\Ecosystem\Gr_Wpforms_Adapter;
use GreenPNG\Storage\Gr_Conversion_Repository;
use GreenPNG\Storage\Gr_Touchpoint_Repository;
use PHPUnit\Framework\TestCase;

final class FormAdaptersTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();

        $_SERVER['REMOTE_ADDR']      = '10.0.0.9';
        $_SERVER['HTTP_USER_AGENT'] = 'UnitTestAgent/1.0';

        $settings   = new Gr_Settings();
        $service    = new Gr_Attribution_Service( new Gr_Touchpoint_Repository(), new Gr_Conversion_Repository() );
        $GLOBALS['gr_form_adapters'] = array(
            'fluentform' => new Gr_Fluentforms_Adapter( new Gr_Identity( $settings ), $service ),
            'cf7'        => new Gr_Cf7_Adapter( new Gr_Identity( $settings ), $service ),
            'wpforms'    => new Gr_Wpforms_Adapter( new Gr_Identity( $settings ), $service ),
        );
    }

    protected function tearDown(): void {
        unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'], $GLOBALS['gr_form_adapters'] );
        // Assign, never unset: superglobals must stay defined for other
        // tests' isset() checks.
        $_COOKIE = array();
        parent::tearDown();
    }

    /**
     * Arms the cookie track: consent on plus a signed gr_attr cookie.
     *
     * @return string The visitor id on the cookie track.
     */
    private function arm_cookie_track(): string {
        $GLOBALS['gr_stub_consent']['marketing'] = true;

        $visitor = str_repeat( 'a', 32 );
        $_COOKIE = array( Gr_Identity::COOKIE => Gr_Identity::cookie_value( $visitor ) );

        return $visitor;
    }

    /**
     * Defines the three target markers (idempotent).
     *
     * @return void
     */
    private function define_targets(): void {
        if ( ! defined( 'FLUENTFORM' ) ) {
            define( 'FLUENTFORM', true );
        }
        if ( ! function_exists( 'wpcf7' ) ) {
            eval( 'function wpcf7() {}' );
        }
        if ( ! function_exists( 'wpforms' ) ) {
            eval( 'function wpforms() {}' );
        }
    }

    /**
     * The last INSERT IGNORE statement recorded by the wpdb stub.
     *
     * @return string
     */
    private function last_conversion_insert(): string {
        global $wpdb;

        $sql = '';
        foreach ( $wpdb->queries as $query ) {
            if ( false !== strpos( (string) $query, 'INSERT IGNORE INTO wp_gr_conversions' ) ) {
                $sql = (string) $query;
            }
        }

        return $sql;
    }

    public function testAbsentTargetsRegisterNoHooksAndThePluginSkipsFormAdapters(): void {
        // Declaration order matters: no marker exists in this process
        // yet, so this is the absent-target reality.
        self::assertFalse( Gr_Fluentforms_Adapter::is_available() );
        self::assertFalse( Gr_Cf7_Adapter::is_available() );
        self::assertFalse( Gr_Wpforms_Adapter::is_available() );

        foreach ( $GLOBALS['gr_form_adapters'] as $adapter ) {
            $adapter->register_hooks();
        }

        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            $hook = (string) $registration['hook'];
            self::assertStringStartsNotWith( 'fluentform', $hook );
            self::assertStringStartsNotWith( 'wpcf7', $hook );
            self::assertStringStartsNotWith( 'wpforms', $hook );
        }

        // The plugin-level gates: without the targets, run() wires no
        // form bridge either.
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            $hook = (string) $registration['hook'];
            self::assertStringStartsNotWith( 'fluentform', $hook );
            self::assertStringStartsNotWith( 'wpcf7', $hook );
            self::assertStringStartsNotWith( 'wpforms', $hook );
        }
    }

    public function testFluentformsRegistersMainAndFallback(): void {
        $this->define_targets();

        self::assertTrue( Gr_Fluentforms_Adapter::is_available() );
        $GLOBALS['gr_form_adapters']['fluentform']->register_hooks();

        $hooks = array();
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            $hooks[] = (string) $registration['hook'];
        }
        self::assertContains( 'fluentform/submission_inserted', $hooks );
        self::assertContains( 'fluentform_submission_inserted', $hooks );
    }

    public function testFluentformsMainBindsWithExtractedAmountAndCurrency(): void {
        global $wpdb;

        $this->define_targets();
        $visitor = $this->arm_cookie_track();

        $wpdb->results   = array();
        $wpdb->insert_id = 71;

        $GLOBALS['gr_form_adapters']['fluentform']->on_main(
            9001,
            array(
                'names'          => array( 'first_name' => 'Ming', 'last_name' => 'Li' ),
                'email'          => 'ming@example-cn.com',
                'input_total'    => '$1,250.00',
                'input_currency' => 'eur',
            ),
            null
        );

        $sql = $this->last_conversion_insert();
        self::assertNotSame( '', $sql );
        self::assertStringContainsString( "'fluentform'", $sql );
        self::assertStringContainsString( '9001', $sql );
        self::assertStringContainsString( "'1250.00'", $sql );
        self::assertStringContainsString( "'EUR'", $sql );
        self::assertStringContainsString( $visitor, $sql );
    }

    public function testFluentformsDoubleFireStaysIdempotentWithNoDrift(): void {
        global $wpdb;

        $this->define_targets();
        $this->arm_cookie_track();

        // The target fires the legacy hook immediately before the main
        // one: park, then claim.
        $GLOBALS['gr_form_adapters']['fluentform']->on_fallback(
            9002,
            array( 'email' => 'x@example.com', 'input_total' => '10' ),
            null
        );
        $GLOBALS['gr_form_adapters']['fluentform']->on_main( 9002, array( 'input_total' => '10' ), null );

        $wpdb->queries = array();
        $wpdb->insert_id = 72;
        $GLOBALS['gr_form_adapters']['fluentform']->resolve_drift();

        // Main served the submission: shutdown resolves nothing.
        self::assertStringNotContainsString( 'wp_gr_conversions', implode( ' ', $wpdb->queries ) );
        foreach ( $GLOBALS['gr_stub_fired_action_args'] as $record ) {
            self::assertNotSame( 'gr_bridge_drift', $record['hook'] );
        }
    }

    public function testFluentformsFallbackAloneBindsAndReportsDrift(): void {
        global $wpdb;

        $this->define_targets();
        $this->arm_cookie_track();

        $wpdb->results   = array();
        $wpdb->insert_id = 73;

        $GLOBALS['gr_form_adapters']['fluentform']->on_fallback( 9003, array( 'input_total' => '5' ), null );

        // The drift watch mounted on shutdown.
        $hooks = array();
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            $hooks[] = (string) $registration['hook'];
        }
        self::assertContains( 'shutdown', $hooks );

        $GLOBALS['gr_form_adapters']['fluentform']->resolve_drift();

        self::assertNotSame( '', $this->last_conversion_insert() );

        $drifted = false;
        foreach ( $GLOBALS['gr_stub_fired_action_args'] as $record ) {
            if ( 'gr_bridge_drift' === $record['hook'] ) {
                $drifted = true;
                self::assertSame( 'fluentform', $record['args'][0] );
                self::assertSame( 9003, $record['args'][1] );
            }
        }
        self::assertTrue( $drifted );
    }

    public function testCf7MailSentBindsViaThePublicSubmissionApi(): void {
        global $wpdb;

        $this->define_targets();
        $this->arm_cookie_track();
        $GLOBALS['gr_stub_rand'] = 555001;

        eval( 'class WPCF7_Submission_TestStub { public static $posted = array(); public static function get_instance() { return new self(); } public function get_posted_data() { return self::$posted; } }' );
        if ( ! class_exists( 'WPCF7_Submission', false ) ) {
            eval( 'class WPCF7_Submission extends WPCF7_Submission_TestStub {}' );
        }
        \WPCF7_Submission::$posted = array(
            'your-name'  => 'Ada Lovelace',
            'your-email' => 'ada@example.com',
            'calc_total' => '$12,500.00',
            'currency'   => 'eur',
        );

        $form = new class() {
            /**
             * Form post id.
             *
             * @return int
             */
            public function id(): int {
                return 33;
            }
        };

        $wpdb->results   = array();
        $wpdb->insert_id = 74;

        self::assertTrue( Gr_Cf7_Adapter::is_available() );
        $GLOBALS['gr_form_adapters']['cf7']->on_main( $form );

        $sql = $this->last_conversion_insert();
        self::assertNotSame( '', $sql );
        self::assertStringContainsString( "'cf7'", $sql );
        self::assertStringContainsString( '555001', $sql );
        self::assertStringContainsString( "'12500.00'", $sql );
        self::assertStringContainsString( "'EUR'", $sql );
    }

    public function testCf7SubmitFallbackOnlyStandsArmedOnMailSent(): void {
        global $wpdb;

        $this->define_targets();
        $this->arm_cookie_track();
        $GLOBALS['gr_stub_rand'] = 555002;

        $form = new class() {
            /**
             * Form post id.
             *
             * @return int
             */
            public function id(): int {
                return 34;
            }
        };

        $wpdb->results   = array();
        $wpdb->insert_id = 75;

        // Failed submissions never park.
        $wpdb->queries = array();
        $GLOBALS['gr_form_adapters']['cf7']->on_fallback( $form, array( 'status' => 'validation_failed' ) );
        self::assertStringNotContainsString( 'shutdown', implode( ' ', array_column( $GLOBALS['gr_stub_actions'], 'hook' ) ) );

        // Successful ones park, and the shutdown check binds plus
        // reports the drift (the main hook never fired).
        $GLOBALS['gr_form_adapters']['cf7']->on_fallback( $form, array( 'status' => 'mail_sent' ) );
        self::assertStringContainsString( 'shutdown', implode( ' ', array_column( $GLOBALS['gr_stub_actions'], 'hook' ) ) );

        $GLOBALS['gr_form_adapters']['cf7']->resolve_drift();

        self::assertNotSame( '', $this->last_conversion_insert() );
        $drifted = false;
        foreach ( $GLOBALS['gr_stub_fired_action_args'] as $record ) {
            if ( 'gr_bridge_drift' === $record['hook'] ) {
                $drifted = true;
                self::assertSame( 'cf7', $record['args'][0] );
                self::assertSame( 555002, $record['args'][1] );
            }
        }
        self::assertTrue( $drifted );
    }

    public function testCf7SyntheticIdIsStableAcrossBothHooks(): void {
        global $wpdb;

        $this->define_targets();
        $this->arm_cookie_track();
        $GLOBALS['gr_stub_rand'] = 555003;

        $form = new class() {
            /**
             * Form post id.
             *
             * @return int
             */
            public function id(): int {
                return 35;
            }
        };

        $wpdb->results   = array();
        $wpdb->insert_id = 76;

        $GLOBALS['gr_form_adapters']['cf7']->on_fallback( $form, array( 'status' => 'mail_sent' ) );
        $GLOBALS['gr_form_adapters']['cf7']->on_main( $form );

        // Same submission, same minted id: the main claim means the
        // shutdown check neither rebinds nor reports drift.
        $wpdb->queries    = array();
        $wpdb->insert_id  = 77;
        $GLOBALS['gr_form_adapters']['cf7']->resolve_drift();

        self::assertStringNotContainsString( 'wp_gr_conversions', implode( ' ', $wpdb->queries ) );
        foreach ( $GLOBALS['gr_stub_fired_action_args'] as $record ) {
            self::assertNotSame( 'gr_bridge_drift', $record['hook'] );
        }
    }

    public function testWpformsCompleteBindsWithLabelKeyedSemantics(): void {
        global $wpdb;

        $this->define_targets();
        $this->arm_cookie_track();

        $wpdb->results   = array();
        $wpdb->insert_id = 78;

        self::assertTrue( Gr_Wpforms_Adapter::is_available() );
        $GLOBALS['gr_form_adapters']['wpforms']->on_main(
            array(
                1 => 'j@example.com',
                2 => 'Ann',
                3 => '$99.50',
            ),
            array(),
            array(
                'id'     => 7,
                'fields' => array(
                    array( 'id' => 1, 'label' => 'Email' ),
                    array( 'id' => 2, 'label' => 'First Name' ),
                    array( 'id' => 3, 'label' => 'Total' ),
                ),
            ),
            4451
        );

        $sql = $this->last_conversion_insert();
        self::assertNotSame( '', $sql );
        self::assertStringContainsString( "'wpforms'", $sql );
        self::assertStringContainsString( '4451', $sql );
        self::assertStringContainsString( "'99.50'", $sql );
    }

    public function testWpformsWithoutEntryIdMintsThePerRequestSource(): void {
        global $wpdb;

        $this->define_targets();
        $this->arm_cookie_track();
        $GLOBALS['gr_stub_rand'] = 777001;

        $wpdb->results   = array();
        $wpdb->insert_id = 79;

        $GLOBALS['gr_form_adapters']['wpforms']->on_main(
            array( 1 => 'k@example.com' ),
            array(),
            array(
                'id'     => 8,
                'fields' => array( array( 'id' => 1, 'label' => 'Email' ) ),
            ),
            0
        );

        $sql = $this->last_conversion_insert();
        self::assertNotSame( '', $sql );
        self::assertStringContainsString( '777001', $sql );
    }

    public function testWpformsEntrySavedAloneBindsAndReportsDrift(): void {
        global $wpdb;

        $this->define_targets();
        $this->arm_cookie_track();

        $wpdb->results   = array();
        $wpdb->insert_id = 80;

        // Entry storage on but the main hook gone: the fallback sees
        // the entry id and binds with the honestly-empty payload.
        $GLOBALS['gr_form_adapters']['wpforms']->on_fallback(
            4452,
            array( 'id' => 9, 'fields' => array( array( 'id' => 1, 'label' => 'Email' ) ) )
        );
        $GLOBALS['gr_form_adapters']['wpforms']->resolve_drift();

        $sql = $this->last_conversion_insert();
        self::assertNotSame( '', $sql );
        self::assertStringContainsString( "'wpforms'", $sql );
        self::assertStringContainsString( '4452', $sql );
        // No field values on the fallback shape: a zero-amount
        // conversion, no invented currency.
        self::assertStringContainsString( "'0.00'", $sql );
        self::assertStringContainsString( "''", $sql );

        $drifted = false;
        foreach ( $GLOBALS['gr_stub_fired_action_args'] as $record ) {
            if ( 'gr_bridge_drift' === $record['hook'] ) {
                $drifted = true;
                self::assertSame( 'wpforms', $record['args'][0] );
                self::assertSame( 4452, $record['args'][1] );
            }
        }
        self::assertTrue( $drifted );
    }

    public function testNeitherConsentNorCookieTrackMeansNoWrite(): void {
        global $wpdb;

        $this->define_targets();

        // Cookie present but consent denied.
        $_COOKIE = array( Gr_Identity::COOKIE => Gr_Identity::cookie_value( str_repeat( 'b', 32 ) ) );
        $wpdb->queries = array();
        $GLOBALS['gr_form_adapters']['fluentform']->on_main( 9004, array( 'input_total' => '1' ), null );
        self::assertStringNotContainsString( 'wp_gr_conversions', implode( ' ', $wpdb->queries ) );

        // Consent granted but the identity is the daily fallback: not
        // worth a conversion row (ADR-0005, same discipline as Woo).
        $GLOBALS['gr_stub_consent']['marketing'] = true;
        $_COOKIE = array();
        $wpdb->queries = array();
        $GLOBALS['gr_form_adapters']['fluentform']->on_main( 9005, array( 'input_total' => '1' ), null );
        self::assertStringNotContainsString( 'wp_gr_conversions', implode( ' ', $wpdb->queries ) );
    }

    public function testThrownErrorsAreIsolatedAndReported(): void {
        $this->define_targets();

        $exploding = new class( new Gr_Identity( new Gr_Settings() ), new Gr_Attribution_Service( new Gr_Touchpoint_Repository(), new Gr_Conversion_Repository() ) ) extends Gr_Form_Adapter_Base {

            /**
             * Adapter identifier.
             *
             * @return string
             */
            public static function get_id(): string {
                return 'boom';
            }

            /**
             * Always available in tests.
             *
             * @return bool
             */
            public static function is_available(): bool {
                return true;
            }

            /**
             * Main hook name.
             *
             * @return string
             */
            protected function main_hook(): string {
                return 'boom_main';
            }

            /**
             * Fallback hook name.
             *
             * @return string
             */
            protected function fallback_hook(): string {
                return 'boom_fallback';
            }

            /**
             * Main hook arg count.
             *
             * @return int
             */
            protected function main_arg_count(): int {
                return 1;
            }

            /**
             * Fallback hook arg count.
             *
             * @return int
             */
            protected function fallback_arg_count(): int {
                return 1;
            }

            /**
             * Explodes on purpose.
             *
             * @param array<int, mixed> $args Hook arguments.
             * @return array{source: int, payload: array<string, mixed>>}|null
             */
            protected function translate_main( array $args ): ?array {
                throw new \RuntimeException( 'boom' );
            }

            /**
             * Explodes on purpose.
             *
             * @param array<int, mixed> $args Hook arguments.
             * @return array{source: int, payload: array<string, mixed>>}|null
             */
            protected function translate_fallback( array $args ): ?array {
                throw new \RuntimeException( 'boom' );
            }
        };

        $exploding->on_main( 'anything' );
        $exploding->on_fallback( 'anything' );

        $reported = 0;
        foreach ( $GLOBALS['gr_stub_fired_action_args'] as $record ) {
            if ( 'gr_adapter_error' === $record['hook'] ) {
                $reported++;
                self::assertSame( 'boom', $record['args'][0] );
                self::assertInstanceOf( \Throwable::class, $record['args'][1] );
            }
        }
        self::assertSame( 2, $reported );
    }

    public function testPluginWiresAllThreeBridgesWhenTargetsPresent(): void {
        $this->define_targets();

        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        $hooks = array();
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            $hooks[] = (string) $registration['hook'];
        }

        self::assertContains( 'fluentform/submission_inserted', $hooks );
        self::assertContains( 'fluentform_submission_inserted', $hooks );
        self::assertContains( 'wpcf7_mail_sent', $hooks );
        self::assertContains( 'wpcf7_submit', $hooks );
        self::assertContains( 'wpforms_process_complete', $hooks );
        self::assertContains( 'wpforms_entry_saved', $hooks );
    }
}
