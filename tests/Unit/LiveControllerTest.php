<?php
/**
 * Live stream endpoint (docs/13 U5): owner-only read whose payload
 * is display-shaped — the grid renders values as text, and the
 * visitor ids arrive shortened.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Rest\Gr_Live_Controller;
use WP_REST_Request;
use PHPUnit\Framework\TestCase;

final class LiveControllerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    public function testRouteRegistersWithTheOwnerGate(): void {
        Gr_Live_Controller::register_routes();

        $route = $GLOBALS['gr_stub_rest_routes'][0];
        $this->assertSame( 'greenpng/v1', $route['namespace'] );
        $this->assertSame( '/live', $route['route'] );
        $this->assertSame( 'GET', $route['args']['methods'] );
        $this->assertArrayHasKey( 'permission_callback', $route['args'] );
    }

    public function testGateDeniesWithoutTheCapability(): void {
        $verdict = ( new Gr_Live_Controller() )->gate();
        $this->assertInstanceOf( 'WP_Error', $verdict );
        $this->assertSame( 'gr_live_forbidden', $verdict->get_error_code() );
        $this->assertSame( 403, $verdict->get_error_data()['status'] );
    }

    public function testGateAllowsTheOwner(): void {
        $GLOBALS['gr_stub_caps'] = array( 'manage_options' );
        $this->assertTrue( ( new Gr_Live_Controller() )->gate() );
    }

    public function testHandlerServesDisplayShapedRows(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            return array(
                array(
                    'id'           => '5',
                    'created_at'   => '2026-09-12 10:02:00',
                    'visitor_id'   => 'abcdef1234567890abcdef',
                    'event_name'   => 'pageview',
                    'event_group'  => 'web',
                    'payload_json' => '{"path":"/home"}',
                ),
                array(
                    'id'           => '6',
                    'created_at'   => '2026-09-12 10:03:00',
                    'visitor_id'   => '',
                    'event_name'   => 'signal',
                    'event_group'  => 'probe',
                    'payload_json' => '{}',
                ),
            );
        };

        $response = ( new Gr_Live_Controller() )->handle( new WP_REST_Request() );
        $data     = $response->get_data();

        $this->assertSame(
            array(
                array(
                    'time'    => '2026-09-12 10:02:00',
                    'name'    => 'pageview',
                    'group'   => 'web',
                    'visitor' => 'abcdef12…',
                ),
                array(
                    'time'    => '2026-09-12 10:03:00',
                    'name'    => 'signal',
                    'group'   => 'probe',
                    'visitor' => '',
                ),
            ),
            $data['rows']
        );

        // No raw row leaks: the payload_json column never crosses.
        $encoded = (string) wp_json_encode( $data );
        $this->assertStringNotContainsString( 'payload_json', $encoded );
        $this->assertStringNotContainsString( 'session_id', $encoded );
    }

    public function testScriptDataCarriesLocalizedLabels(): void {
        $data = Gr_Live_Controller::script_data();

        $this->assertStringContainsString( '/wp-json/greenpng/v1/live', $data['endpoint'] );
        $this->assertSame( 15000, $data['pollMs'] );
        $this->assertSame(
            array( 'time', 'name', 'group', 'visitor' ),
            array_keys( $data['labels'] )
        );
    }
}
