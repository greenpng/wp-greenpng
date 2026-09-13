<?php
/**
 * Dashboard live panels endpoint (docs/12 G5, docs/06 §2.2): the
 * capability gate denies by default, the payload carries the online
 * count and today's split from one bounded read each, and the
 * page-localized labels stay on the server — the JS carries no copy
 * of its own.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Rest\Gr_Panels_Controller;
use WP_REST_Request;
use PHPUnit\Framework\TestCase;

final class PanelsControllerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    /**
     * Canned aggregate for the device split; the online count reads
     * var_result.
     *
     * @return void
     */
    private function seed(): void {
        $GLOBALS['wpdb']->var_result = '5';
        $GLOBALS['wpdb']->results    = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'gr_sessions' ) ) {
                return array(
                    array( 'device_type' => 'mobile', 'sessions' => '7', 'bots' => '2' ),
                    array( 'device_type' => 'desktop', 'sessions' => '5', 'bots' => '0' ),
                    array( 'device_type' => 'car', 'sessions' => '1', 'bots' => '1' ),
                );
            }

            return array();
        };
    }

    public function testRouteRegistersUnderThePluginNamespace(): void {
        Gr_Panels_Controller::register_routes();

        $route  = $GLOBALS['gr_stub_rest_routes'][0];
        $config = $route['args'];

        $this->assertSame( 'greenpng/v1', $route['namespace'] );
        $this->assertSame( '/panels', $route['route'] );
        $this->assertSame( 'GET', $config['methods'] );
        $this->assertArrayHasKey( 'permission_callback', $config );
        $this->assertArrayHasKey( 'callback', $config );
    }

    public function testGateDeniesWithoutTheCapability(): void {
        $controller = new Gr_Panels_Controller();
        $verdict    = $controller->gate( new WP_REST_Request() );

        $this->assertInstanceOf( 'WP_Error', $verdict );
        $this->assertSame( 'gr_panels_forbidden', $verdict->get_error_code() );
        $this->assertSame( 403, $verdict->get_error_data()['status'] );
    }

    public function testGateAllowsTheOwner(): void {
        $GLOBALS['gr_stub_caps'] = array( 'manage_options' );

        $controller = new Gr_Panels_Controller();
        $this->assertTrue( $controller->gate( new WP_REST_Request() ) );
    }

    public function testHandlerServesTheLivePayloadShape(): void {
        $this->seed();

        $data = ( new Gr_Panels_Controller() )->handle( new WP_REST_Request() )->get_data();

        $this->assertSame( 5, $data['online'] );
        $this->assertSame( 300, $data['window'] );

        // One aggregate read: the split totals and the device ranking
        // in the order the SQL ordered them.
        $this->assertSame( 13, $data['sessionsToday'] );
        $this->assertSame( 3, $data['botsToday'] );
        $this->assertSame(
            array(
                array( 'key' => 'mobile', 'value' => 7 ),
                array( 'key' => 'desktop', 'value' => 5 ),
                array( 'key' => 'car', 'value' => 1 ),
            ),
            $data['devices']
        );
    }

    public function testHandlerRunsBoundedReadsWithoutCaching(): void {
        $this->seed();
        $GLOBALS['wpdb']->queries = array();

        ( new Gr_Panels_Controller() )->handle( new WP_REST_Request() );

        $sql = implode( ' ', $GLOBALS['wpdb']->queries );

        // Live metrics compute per call (docs/05 §3.2): the online
        // range on last_active, today's split on started_at.
        $this->assertStringContainsString( 'SELECT COUNT(*) FROM wp_gr_sessions WHERE last_active >', $sql );
        $this->assertStringContainsString( 'GROUP BY device_type', $sql );
        $this->assertStringContainsString( 'WHERE started_at >=', $sql );

        // No transient ever enters the picture.
        $transients = 0;
        foreach ( array_keys( $GLOBALS['gr_stub_options']['data'] ?? array() ) as $key ) {
            if ( false !== strpos( (string) $key, 'transient' ) ) {
                ++$transients;
            }
        }
        $this->assertSame( 0, $transients, 'Live panel metrics must not write shared transients (docs/05 §3.2).' );
    }

    public function testHandlerDegradesToZeroWhenTheReadFails(): void {
        $GLOBALS['wpdb']->var_result = null;
        $GLOBALS['wpdb']->results    = array();

        $data = ( new Gr_Panels_Controller() )->handle( new WP_REST_Request() )->get_data();

        $this->assertSame( 0, $data['online'] );
        $this->assertSame( 0, $data['sessionsToday'] );
        $this->assertSame( array(), $data['devices'] );
    }

    public function testScriptDataCarriesThePollAndTheLabels(): void {
        $data = Gr_Panels_Controller::script_data();

        $this->assertStringContainsString( '/wp-json/greenpng/v1/panels', $data['panelsEndpoint'] );
        $this->assertSame( 30000, $data['panelPollMs'] );
        $this->assertArrayHasKey( 'online', $data['panelsLabels'] );
        $this->assertArrayHasKey( 'noneToday', $data['panelsLabels'] );
        $this->assertSame(
            array( 'desktop', 'mobile', 'tablet', 'other' ),
            array_keys( $data['panelsLabels']['devices'] )
        );
    }
}
