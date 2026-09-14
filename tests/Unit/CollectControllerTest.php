<?php
/**
 * Collect controller (docs/13 C6): the three permission-stage gates, the
 * strict event/field schema, server-side identity, and the dispatch plus
 * session-touch write path.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Behavior\Gr_Behavior;
use GreenPNG\Core\Gr_Event;
use GreenPNG\Core\Gr_Settings;
use GreenPNG\Rest\Gr_Collect_Controller;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class CollectControllerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();

        $_SERVER['REMOTE_ADDR']      = '10.0.0.7';
        $_SERVER['HTTP_USER_AGENT'] = 'UnitTestAgent/1.0';
    }

    protected function tearDown(): void {
        unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] );
        parent::tearDown();
    }

    /**
     * Builds a request whose JSON body carries the given fields, exactly
     * like a sendBeacon payload would.
     *
     * @param array<string, mixed> $fields Body fields.
     * @return WP_REST_Request
     */
    private function request( array $fields ): WP_REST_Request {
        $request = new WP_REST_Request();
        $request->set_body( (string) wp_json_encode( $fields ) );

        return $request;
    }

    public function testTokenIsDeterministicWithinTheDay(): void {
        $token = Gr_Collect_Controller::token();

        self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $token );
        self::assertSame( $token, Gr_Collect_Controller::token() );
    }

    public function testTokenRotatesAcrossDays(): void {
        $today = Gr_Collect_Controller::token();

        $GLOBALS['gr_stub_now'] = '2026-09-11 00:00:01';

        self::assertNotSame( $today, Gr_Collect_Controller::token() );
    }

    public function testGateRejectsAMissingTokenWith401(): void {
        $controller = new Gr_Collect_Controller();

        $result = $controller->gate( $this->request( array( 'name' => 'pageview' ) ) );

        self::assertInstanceOf( WP_Error::class, $result );
        self::assertSame( 'gr_collect_token', $result->get_error_code() );
        self::assertSame( 401, $result->get_error_data()['status'] );
    }

    public function testGateRejectsAWrongTokenWith401(): void {
        $controller = new Gr_Collect_Controller();

        $result = $controller->gate(
            $this->request( array( 'token' => str_repeat( '0', 64 ), 'name' => 'pageview' ) )
        );

        self::assertInstanceOf( WP_Error::class, $result );
        self::assertSame( 'gr_collect_token', $result->get_error_code() );
        self::assertSame( 401, $result->get_error_data()['status'] );
    }

    public function testGateRejectsAnExhaustedRateWindowWith429(): void {
        $GLOBALS['gr_stub_transients'][ 'gr_rl_collect_' . md5( '10.0.0.7' ) ] = array(
            'value'      => Gr_Collect_Controller::RATE_LIMIT,
            'expires_at' => gr_stub_clock() + 60,
        );

        $controller = new Gr_Collect_Controller();

        $result = $controller->gate(
            $this->request( array( 'token' => Gr_Collect_Controller::token(), 'name' => 'pageview' ) )
        );

        self::assertInstanceOf( WP_Error::class, $result );
        self::assertSame( 'gr_collect_rate', $result->get_error_code() );
        self::assertSame( 429, $result->get_error_data()['status'] );
    }

    public function testGateRejectsAnOversizedBodyWith413(): void {
        $request = new WP_REST_Request();
        $request->set_param( 'token', Gr_Collect_Controller::token() );
        $request->set_body( str_repeat( 'a', Gr_Collect_Controller::BODY_LIMIT + 1 ) );

        $controller = new Gr_Collect_Controller();

        $result = $controller->gate( $request );

        self::assertInstanceOf( WP_Error::class, $result );
        self::assertSame( 'gr_collect_body', $result->get_error_code() );
        self::assertSame( 413, $result->get_error_data()['status'] );
    }

    public function testGatePassesATokenedRequestUnderTheLimit(): void {
        $controller = new Gr_Collect_Controller();

        $result = $controller->gate(
            $this->request( array( 'token' => Gr_Collect_Controller::token(), 'name' => 'pageview' ) )
        );

        self::assertTrue( $result );
        self::assertSame(
            1,
            $GLOBALS['gr_stub_transients'][ 'gr_rl_collect_' . md5( '10.0.0.7' ) ]['value']
        );
    }

    public function testHandleRejectsAnUnregisteredEventNameWith400(): void {
        $controller = new Gr_Collect_Controller();

        $result = $controller->handle(
            $this->request( array( 'token' => Gr_Collect_Controller::token(), 'name' => 'impression' ) )
        );

        self::assertInstanceOf( WP_Error::class, $result );
        self::assertSame( 'gr_collect_event', $result->get_error_code() );
        self::assertSame( 400, $result->get_error_data()['status'] );
    }

    public function testHandleRejectsUnknownFieldsIncludingClientAssertedIdentity(): void {
        $controller = new Gr_Collect_Controller();

        $result = $controller->handle(
            $this->request(
                array(
                    'token'      => Gr_Collect_Controller::token(),
                    'name'       => 'pageview',
                    'visitor_id' => str_repeat( 'f', 64 ),
                )
            )
        );

        self::assertInstanceOf( WP_Error::class, $result );
        self::assertSame( 'gr_collect_field', $result->get_error_code() );
        self::assertSame( 'visitor_id', $result->get_error_data()['field'] );
    }

    public function testHandleRequiresAnIntegerBotScoreForSignals(): void {
        $controller = new Gr_Collect_Controller();

        $result = $controller->handle(
            $this->request(
                array(
                    'token' => Gr_Collect_Controller::token(),
                    'name'  => 'signal',
                    'path'  => '/',
                )
            )
        );

        self::assertInstanceOf( WP_Error::class, $result );
        self::assertSame( 'gr_collect_score', $result->get_error_code() );
        self::assertSame( 400, $result->get_error_data()['status'] );
    }

    public function testHandleRejectsNonBooleanSignalFlags(): void {
        $controller = new Gr_Collect_Controller();

        $result = $controller->handle(
            $this->request(
                array(
                    'token'     => Gr_Collect_Controller::token(),
                    'name'      => 'signal',
                    'bot_score' => 10,
                    'webdriver' => 'yes',
                )
            )
        );

        self::assertInstanceOf( WP_Error::class, $result );
        self::assertSame( 'gr_collect_flag', $result->get_error_code() );
    }

    public function testHandleRejectsSignalsWhenTheProbeIsDisabled(): void {
        update_option( 'gr_settings', array( 'probe_enabled' => 0 ) );

        $controller = new Gr_Collect_Controller();

        $result = $controller->handle(
            $this->request(
                array(
                    'token'     => Gr_Collect_Controller::token(),
                    'name'      => 'signal',
                    'bot_score' => 10,
                )
            )
        );

        self::assertInstanceOf( WP_Error::class, $result );
        self::assertSame( 'gr_collect_probe', $result->get_error_code() );
    }

    public function testHandleRejectsAnOverlongPath(): void {
        $controller = new Gr_Collect_Controller();

        $result = $controller->handle(
            $this->request(
                array(
                    'token' => Gr_Collect_Controller::token(),
                    'name'  => 'pageview',
                    'path'  => '/' . str_repeat( 'x', 191 ),
                )
            )
        );

        self::assertInstanceOf( WP_Error::class, $result );
        self::assertSame( 'gr_collect_path', $result->get_error_code() );
    }

    public function testHandleStoresAPageviewWithServerDerivedIdentity(): void {
        global $wpdb;

        $controller = new Gr_Collect_Controller();

        $result = $controller->handle(
            $this->request(
                array(
                    'token'    => Gr_Collect_Controller::token(),
                    'name'     => 'pageview',
                    'path'     => '/landing/',
                    'event_id' => 'evt-1',
                )
            )
        );

        self::assertInstanceOf( WP_REST_Response::class, $result );
        self::assertTrue( $result->get_data()['stored'] );
        self::assertGreaterThan( 0, $result->get_data()['id'] );

        // Exactly one gr_event hook firing, carrying the persisted DTO.
        $firing = null;
        foreach ( $GLOBALS['gr_stub_fired_action_args'] as $record ) {
            if ( 'gr_event' === $record['hook'] ) {
                $firing = $record['args'][0];
            }
        }
        self::assertInstanceOf( Gr_Event::class, $firing );
        self::assertSame( 'pageview', $firing->name() );
        self::assertSame( 'web', $firing->group() );
        self::assertSame( 'evt-1', $firing->event_id() );
        self::assertSame( '/landing/', $firing->payload()['path'] );

        // Identity is the server's own dual-track resolution, never the
        // client's; context keys are lifted, so they never reach payload.
        self::assertSame( gr()->identity()->visitor_id(), $firing->visitor_id() );
        self::assertSame( gr()->identity()->session_id(), $firing->session_id() );
        self::assertArrayNotHasKey( 'visitor_id', $firing->payload() );

        // The session activity slide follows the event insert.
        $sql = implode( ' ', $wpdb->queries );
        self::assertStringContainsString( 'INSERT INTO wp_gr_sessions', $sql );
        self::assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $sql );

        // Public write surface: never cacheable.
        self::assertTrue( $GLOBALS['gr_stub_nocache'] );
    }

    public function testHandleStoresASignalWithItsConclusionFlags(): void {
        $controller = new Gr_Collect_Controller();

        $result = $controller->handle(
            $this->request(
                array(
                    'token'             => Gr_Collect_Controller::token(),
                    'name'              => 'signal',
                    'bot_score'         => 87,
                    'webdriver'         => true,
                    'software_renderer' => false,
                    'headless_window'   => true,
                    'language_anomaly'  => false,
                )
            )
        );

        self::assertInstanceOf( WP_REST_Response::class, $result );
        self::assertTrue( $result->get_data()['stored'] );

        $firing = null;
        foreach ( $GLOBALS['gr_stub_fired_action_args'] as $record ) {
            if ( 'gr_event' === $record['hook'] ) {
                $firing = $record['args'][0];
            }
        }
        self::assertSame( 'signal', $firing->name() );
        self::assertSame( 'probe', $firing->group() );
        self::assertSame( 87, $firing->payload()['bot_score'] );
        self::assertTrue( $firing->payload()['webdriver'] );
        self::assertFalse( $firing->payload()['software_renderer'] );
        self::assertTrue( $firing->payload()['headless_window'] );
        self::assertFalse( $firing->payload()['language_anomaly'] );
    }

    public function testTheEventVocabularyIsExtensibleThroughTheFilter(): void {
        add_filter(
            'gr_collect_events',
            static function ( array $events ): array {
                $events['partner_hit'] = 'web';

                return $events;
            }
        );

        $controller = new Gr_Collect_Controller();

        $result = $controller->handle(
            $this->request(
                array(
                    'token' => Gr_Collect_Controller::token(),
                    'name'  => 'partner_hit',
                )
            )
        );

        self::assertInstanceOf( WP_REST_Response::class, $result );
        self::assertTrue( $result->get_data()['stored'] );

        $firing = null;
        foreach ( $GLOBALS['gr_stub_fired_action_args'] as $record ) {
            if ( 'gr_event' === $record['hook'] ) {
                $firing = $record['args'][0];
            }
        }
        self::assertSame( 'partner_hit', $firing->name() );
        self::assertSame( 'web', $firing->group() );
    }

    public function testRoutesAreRegisteredUnderTheGreenpngNamespace(): void {
        Gr_Collect_Controller::register_routes();

        $routes = $GLOBALS['gr_stub_rest_routes'];
        self::assertCount( 1, $routes );
        self::assertSame( 'greenpng/v1', $routes[0]['namespace'] );
        self::assertSame( '/collect', $routes[0]['route'] );
        self::assertSame( 'POST', $routes[0]['args']['methods'] );
        // Not required in the args schema on purpose: core checks required
        // params before the permission stage, and the gate owns the 401.
        self::assertArrayNotHasKey( 'required', $routes[0]['args']['args']['token'] );
        self::assertContains( 'pageview', $routes[0]['args']['args']['name']['enum'] );
        self::assertContains( 'signal', $routes[0]['args']['args']['name']['enum'] );
        self::assertIsCallable( $routes[0]['args']['permission_callback'] );
        self::assertIsCallable( $routes[0]['args']['callback'] );
    }

    public function testScriptDataExposesTheEndpointAndTodaysToken(): void {
        $data = Gr_Collect_Controller::script_data();

        self::assertStringEndsWith( 'greenpng/v1/collect', $data['url'] );
        self::assertSame( Gr_Collect_Controller::token(), $data['token'] );
    }

    public function testSignalPersistsItsScoreAndVerdictOntoTheSessionRow(): void {
        global $wpdb;

        ( new Gr_Collect_Controller() )->handle(
            $this->request(
                array(
                    'token'     => Gr_Collect_Controller::token(),
                    'name'      => 'signal',
                    'bot_score' => 87,
                )
            )
        );

        // 87 crosses the default threshold of 70 (ADR-0009 D1): the
        // server, not the client, reaches the verdict and writes both
        // columns.
        $sql = implode( ' ', $wpdb->queries );
        self::assertStringContainsString( 'bot_score = GREATEST(bot_score, 87)', $sql );
        self::assertStringContainsString( 'is_bot = IF(1 = 1, 1, is_bot)', $sql );
    }

    public function testSignalBelowTheThresholdCarriesTheScoreWithoutTheVerdict(): void {
        global $wpdb;

        // One webdriver flag (40) stays under 70: the score is kept,
        // the session is not convicted.
        ( new Gr_Collect_Controller() )->handle(
            $this->request(
                array(
                    'token'     => Gr_Collect_Controller::token(),
                    'name'      => 'signal',
                    'bot_score' => 40,
                )
            )
        );

        $sql = implode( ' ', $wpdb->queries );
        self::assertStringContainsString( 'bot_score = GREATEST(bot_score, 40)', $sql );
        self::assertStringContainsString( 'is_bot = IF(0 = 1, 1, is_bot)', $sql );
    }

    public function testPageviewNeverTouchesTheVerdictColumns(): void {
        global $wpdb;

        ( new Gr_Collect_Controller() )->handle(
            $this->request(
                array(
                    'token' => Gr_Collect_Controller::token(),
                    'name'  => 'pageview',
                    'path'  => '/',
                )
            )
        );

        // The touch upsert slides activity only; the conclusion
        // columns stay out of the pageview path entirely.
        $sql = implode( ' ', $wpdb->queries );
        self::assertStringNotContainsString( 'GREATEST(bot_score', $sql );
        self::assertStringNotContainsString( 'is_bot = IF(', $sql );
    }

    public function testTheVerdictThresholdIsTheOwnersDial(): void {
        global $wpdb;

        ( new Gr_Settings() )->set( 'bot_verdict_threshold', 90 );

        ( new Gr_Collect_Controller() )->handle(
            $this->request(
                array(
                    'token'     => Gr_Collect_Controller::token(),
                    'name'      => 'signal',
                    'bot_score' => 80,
                )
            )
        );

        // Same band as the crossing test, but the owner demanded 90:
        // no verdict this time.
        $sql = implode( ' ', $wpdb->queries );
        self::assertStringContainsString( 'bot_score = GREATEST(bot_score, 80)', $sql );
        self::assertStringContainsString( 'is_bot = IF(0 = 1, 1, is_bot)', $sql );
    }

    // ------------------------------------------------------------------
    // Behavior module (ADR-0012): batch envelope, purpose gates.
    // ------------------------------------------------------------------

    /**
     * Arms the module exactly as the plugin wiring does: the setting
     * on, marketing consent granted, the vocabulary filter attached.
     *
     * @return void
     */
    private function arm_behavior(): void {
        ( new Gr_Settings() )->set( 'behavior_enabled', 1 );
        $GLOBALS['gr_stub_consent']['marketing'] = true;
        add_filter( 'gr_collect_events', array( Gr_Behavior::class, 'vocabulary' ) );
    }

    /**
     * The gr_event firings this request produced, in order.
     *
     * @return array<int, Gr_Event>
     */
    private function fired_events(): array {
        $events = array();
        foreach ( $GLOBALS['gr_stub_fired_action_args'] as $record ) {
            if ( 'gr_event' === $record['hook'] ) {
                $events[] = $record['args'][0];
            }
        }

        return $events;
    }

    public function testBehaviorNamesStayUnknownWhileTheModuleIsOff(): void {
        $GLOBALS['gr_stub_consent']['marketing'] = true;
        add_filter( 'gr_collect_events', array( Gr_Behavior::class, 'vocabulary' ) );

        $result = ( new Gr_Collect_Controller() )->handle(
            $this->request(
                array(
                    'token'  => Gr_Collect_Controller::token(),
                    'name'   => 'behavior',
                    'events' => array(
                        array( 'name' => 'dwell', 'bucket' => '15-60', 'path' => '/' ),
                    ),
                )
            )
        );

        // behavior_enabled defaults to 0: the vocabulary added nothing,
        // so the envelope itself is an unknown name.
        self::assertInstanceOf( WP_Error::class, $result );
        self::assertSame( 'gr_collect_event', $result->get_error_code() );
        self::assertSame( 400, $result->get_error_data()['status'] );
    }

    public function testBehaviorBatchStoresEveryInnerEventAsItsOwnRow(): void {
        global $wpdb;

        $this->arm_behavior();

        $result = ( new Gr_Collect_Controller() )->handle(
            $this->request(
                array(
                    'token' => Gr_Collect_Controller::token(),
                    'name'  => 'behavior',
                    'events' => array(
                        array( 'name' => 'dwell', 'bucket' => '60-180', 'path' => '/pricing/' ),
                        array( 'name' => 'scroll_depth', 'milestone' => 75, 'path' => '/pricing/' ),
                        array( 'name' => 'rage_click', 'clicks' => 4, 'locator' => 'button.buy-now', 'path' => '/pricing/' ),
                        array( 'name' => 'dead_click', 'locator' => 'div.hero', 'path' => '/pricing/' ),
                    ),
                )
            )
        );

        self::assertInstanceOf( WP_REST_Response::class, $result );
        self::assertTrue( $result->get_data()['stored'] );
        self::assertGreaterThan( 0, $result->get_data()['id'] );

        $events = $this->fired_events();
        self::assertCount( 4, $events );
        self::assertSame( array( 'dwell', 'scroll_depth', 'rage_click', 'dead_click' ), array_map( static function ( Gr_Event $event ) {
            return $event->name();
        }, $events ) );

        foreach ( $events as $event ) {
            self::assertSame( 'behavior', $event->group() );
            self::assertSame( '/pricing/', $event->payload()['path'] );
        }

        self::assertSame( '60-180', $events[0]->payload()['bucket'] );
        self::assertSame( 75, $events[1]->payload()['milestone'] );
        self::assertSame( 4, $events[2]->payload()['clicks'] );
        self::assertSame( 'button.buy-now', $events[2]->payload()['locator'] );
        self::assertSame( 'div.hero', $events[3]->payload()['locator'] );

        // One beacon, one session slide: exactly one unique touch
        // statement (prepare and query each record an identical line).
        $touches = array_values(
            array_unique(
                array_filter(
                    $wpdb->queries,
                    static function ( $line ): bool {
                        return false !== strpos( (string) $line, 'INSERT INTO wp_gr_sessions' );
                    }
                )
            )
        );
        self::assertCount( 1, $touches );
    }

    public function testBehaviorBatchNeedsMarketingConsent(): void {
        ( new Gr_Settings() )->set( 'behavior_enabled', 1 );
        add_filter( 'gr_collect_events', array( Gr_Behavior::class, 'vocabulary' ) );

        // No consent granted: the third gate refuses before any row.
        $result = ( new Gr_Collect_Controller() )->handle(
            $this->request(
                array(
                    'token'  => Gr_Collect_Controller::token(),
                    'name'   => 'behavior',
                    'events' => array(
                        array( 'name' => 'dwell', 'bucket' => '15-60', 'path' => '/' ),
                    ),
                )
            )
        );

        self::assertInstanceOf( WP_Error::class, $result );
        self::assertSame( 'gr_collect_consent', $result->get_error_code() );
        self::assertSame( 400, $result->get_error_data()['status'] );
    }

    public function testBehaviorBatchRefusesPrefetchFetches(): void {
        $this->arm_behavior();

        $request = $this->request(
            array(
                'token'  => Gr_Collect_Controller::token(),
                'name'   => 'behavior',
                'events' => array(
                    array( 'name' => 'dwell', 'bucket' => '15-60', 'path' => '/' ),
                ),
            )
        );
        $request->set_header( 'Sec-Purpose', 'prefetch' );

        $result = ( new Gr_Collect_Controller() )->handle( $request );

        self::assertInstanceOf( WP_Error::class, $result );
        self::assertSame( 'gr_collect_prefetch', $result->get_error_code() );
        self::assertSame( 400, $result->get_error_data()['status'] );
    }

    public function testBehaviorBatchValidatesEveryInnerValue(): void {
        $this->arm_behavior();
        $controller = new Gr_Collect_Controller();

        $cases = array(
            array( 'gr_collect_bucket', array( 'name' => 'dwell', 'bucket' => '999', 'path' => '/' ) ),
            array( 'gr_collect_milestone', array( 'name' => 'scroll_depth', 'milestone' => 30, 'path' => '/' ) ),
            array( 'gr_collect_clicks', array( 'name' => 'rage_click', 'clicks' => 2, 'locator' => 'div.x', 'path' => '/' ) ),
            array( 'gr_collect_locator', array( 'name' => 'dead_click', 'locator' => '<>', 'path' => '/' ) ),
            array( 'gr_collect_field', array( 'name' => 'dead_click', 'locator' => 'div.x', 'path' => '/', 'text' => 'never accepted' ) ),
            array( 'gr_collect_event', array( 'name' => 'behavior', 'events' => array() ) ),
            array( 'gr_collect_event', array( 'name' => 'signal', 'bot_score' => 10 ) ),
        );

        foreach ( $cases as $case ) {
            $result = $controller->handle(
                $this->request(
                    array(
                        'token'  => Gr_Collect_Controller::token(),
                        'name'   => 'behavior',
                        'events' => array( $case[1] ),
                    )
                )
            );

            self::assertInstanceOf( WP_Error::class, $result, 'Inner event must be rejected: ' . wp_json_encode( $case[1] ) );
            self::assertSame( $case[0], $result->get_error_code() );
        }
    }

    public function testBehaviorBatchCapsAtTwentyInnerEvents(): void {
        $this->arm_behavior();

        $inner = array();
        for ( $i = 0; $i < 21; $i++ ) {
            $inner[] = array( 'name' => 'dwell', 'bucket' => '0-15', 'path' => '/' );
        }

        $result = ( new Gr_Collect_Controller() )->handle(
            $this->request(
                array(
                    'token'  => Gr_Collect_Controller::token(),
                    'name'   => 'behavior',
                    'events' => $inner,
                )
            )
        );

        self::assertInstanceOf( WP_Error::class, $result );
        self::assertSame( 'gr_collect_batch', $result->get_error_code() );
    }

    public function testDirectBehaviorPostsRideTheSameValidation(): void {
        $this->arm_behavior();
        $controller = new Gr_Collect_Controller();

        $result = $controller->handle(
            $this->request(
                array(
                    'token'  => Gr_Collect_Controller::token(),
                    'name'   => 'dwell',
                    'bucket' => '15-60',
                    'path'   => '/about/',
                )
            )
        );

        self::assertInstanceOf( WP_REST_Response::class, $result );
        self::assertTrue( $result->get_data()['stored'] );

        $events = $this->fired_events();
        $last   = end( $events );
        self::assertSame( 'dwell', $last->name() );
        self::assertSame( 'behavior', $last->group() );
        self::assertSame( '15-60', $last->payload()['bucket'] );

        // The transport keys never reach the stored payload.
        self::assertArrayNotHasKey( 'token', $last->payload() );
        self::assertArrayNotHasKey( 'name', $last->payload() );

        // Off-vocabulary value on the direct path: same code as the
        // batch inner check.
        gr_stub_reset_options();
        $this->arm_behavior();
        $bad = ( new Gr_Collect_Controller() )->handle(
            $this->request(
                array(
                    'token'  => Gr_Collect_Controller::token(),
                    'name'   => 'dwell',
                    'bucket' => 'three-minutes',
                )
            )
        );
        self::assertInstanceOf( WP_Error::class, $bad );
        self::assertSame( 'gr_collect_bucket', $bad->get_error_code() );
    }

    public function testLocatorLosesAnythingOutsideTheStructuralCharset(): void {
        $this->arm_behavior();

        ( new Gr_Collect_Controller() )->handle(
            $this->request(
                array(
                    'token'  => Gr_Collect_Controller::token(),
                    'name'   => 'behavior',
                    'events' => array(
                        array( 'name' => 'dead_click', 'locator' => 'button#buy"><svg onload=x>', 'path' => '/' ),
                    ),
                )
            )
        );

        $events = $this->fired_events();
        $stored = (string) end( $events )->payload()['locator'];

        // The markup never survives: only the structural charset does.
        self::assertStringStartsWith( 'button#buy', $stored );
        self::assertStringNotContainsString( '<', $stored );
        self::assertStringNotContainsString( '>', $stored );
        self::assertStringNotContainsString( '"', $stored );
        self::assertLessThanOrEqual( 64, strlen( $stored ) );
    }
}
