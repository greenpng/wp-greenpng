<?php
/**
 * Dashboard REST endpoint (docs/13 U3, docs/06 §2.2): owner-only read
 * surface — the capability gate denies by default, the payload
 * carries the dense series and the country ranking, and the window
 * argument is bounded.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Rest\Gr_Dashboard_Controller;
use WP_REST_Request;
use PHPUnit\Framework\TestCase;

final class DashboardRestControllerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    /**
     * Canned summary reads for the handler.
     *
     * @return void
     */
    private function seed(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'sessions_by_country' ) ) {
                return array( array( 'metric_key' => 'US', 'total' => '9' ) );
            }

            return array(
                array( 'stat_date' => '2026-09-10', 'metric_type' => 'sessions', 'metric_value' => '7.0000' ),
                array( 'stat_date' => '2026-09-10', 'metric_type' => 'visitors', 'metric_value' => '3.0000' ),
                array( 'stat_date' => '2026-09-10', 'metric_type' => 'pageviews', 'metric_value' => '11.0000' ),
                array( 'stat_date' => '2026-09-10', 'metric_type' => 'conversions', 'metric_value' => '2.0000' ),
                array( 'stat_date' => '2026-09-10', 'metric_type' => 'revenue', 'metric_value' => '129.9000' ),
            );
        };
    }

    public function testRouteRegistersUnderThePluginNamespace(): void {
        Gr_Dashboard_Controller::register_routes();

        $route = $GLOBALS['gr_stub_rest_routes'][0];
        $this->assertSame( 'greenpng/v1', $route['namespace'] );
        $this->assertSame( '/dashboard', $route['route'] );

        // The recorder stores the whole route config under 'args'.
        $config = $route['args'];
        $this->assertSame( 'GET', $config['methods'] );
        $this->assertArrayHasKey( 'permission_callback', $config );
        $this->assertArrayHasKey( 'callback', $config );
        $this->assertArrayHasKey( 'days', $config['args'] );
        $this->assertSame( 90, $config['args']['days']['maximum'] );
    }

    public function testGateDeniesWithoutTheCapability(): void {
        $controller = new Gr_Dashboard_Controller();
        $verdict    = $controller->gate( new WP_REST_Request() );

        $this->assertInstanceOf( 'WP_Error', $verdict );
        $this->assertSame( 'gr_dashboard_forbidden', $verdict->get_error_code() );
        $this->assertSame( 403, $verdict->get_error_data()['status'] );
    }

    public function testGateAllowsTheOwner(): void {
        $GLOBALS['gr_stub_caps'] = array( 'manage_options' );

        $controller = new Gr_Dashboard_Controller();
        $this->assertTrue( $controller->gate( new WP_REST_Request() ) );
    }

    public function testHandlerServesThePayloadShape(): void {
        $this->seed();

        $request = new WP_REST_Request();
        $request->set_param( 'days', 7 );

        $response = ( new Gr_Dashboard_Controller() )->handle( $request );
        $data     = $response->get_data();

        $this->assertCount( 7, $data['dates'] );
        $this->assertSame( '2026-09-04', $data['dates'][0] );
        $this->assertSame( '2026-09-10', $data['dates'][6] );

        $this->assertCount( 7, $data['series']['sessions'] );
        $this->assertSame( 7.0, $data['series']['sessions'][6] );
        $this->assertSame( 0.0, $data['series']['sessions'][0] );

        $this->assertSame(
            array( array( 'key' => 'US', 'value' => 9.0 ) ),
            $data['countries']
        );
    }

    public function testHandlerDefaultsAndBoundsTheWindow(): void {
        $this->seed();
        $GLOBALS['wpdb']->queries = array();

        // No days param: the default window.
        ( new Gr_Dashboard_Controller() )->handle( new WP_REST_Request() );
        $sql = implode( ' ', $GLOBALS['wpdb']->queries );
        $this->assertStringContainsString( "'2026-08-28'", $sql, '14-day default from 2026-09-10' );

        // Out-of-range days fall in line rather than trusting the caller.
        $request = new WP_REST_Request();
        $request->set_param( 'days', 5000 );
        ( new Gr_Dashboard_Controller() )->handle( $request );
        $sql = implode( ' ', $GLOBALS['wpdb']->queries );
        $this->assertStringContainsString( "'2026-06-13'", $sql, '90-day ceiling: window includes today' );
    }

    public function testScriptDataCarriesLocalizedLabels(): void {
        $data = Gr_Dashboard_Controller::script_data();

        $this->assertStringContainsString( '/wp-json/greenpng/v1/dashboard', $data['endpoint'] );
        $this->assertSame( 14, $data['trendDays'] );
        $this->assertSame(
            array( 'sessions', 'visitors', 'pageviews', 'conversions' ),
            array_keys( $data['labels'] )
        );
    }
}
