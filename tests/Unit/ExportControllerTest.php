<?php
/**
 * CSV export endpoint (docs/12 G6, docs/11 §3): the closed dataset
 * vocabulary, the owner-only gate on every dataset, the CSV document
 * shape with fputcsv quoting, and the response headers that make the
 * browser download rather than render.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Rest\Gr_Export_Controller;
use WP_REST_Request;
use PHPUnit\Framework\TestCase;

final class ExportControllerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    public function testRouteRegistersWithTheClosedDatasetVocabulary(): void {
        Gr_Export_Controller::register_routes();

        $route = $GLOBALS['gr_stub_rest_routes'][0];

        $this->assertSame( 'greenpng/v1', $route['namespace'] );
        $this->assertSame( '/export/(?P<dataset>sessions|audit|access_rules)', $route['route'] );
        $config = $route['args'];
        $this->assertSame( 'GET', $config['methods'] );
        $this->assertArrayHasKey( 'permission_callback', $config );
        $this->assertArrayHasKey( 'from', $config['args'] );
        $this->assertArrayHasKey( 'to', $config['args'] );
        $this->assertArrayHasKey( 's', $config['args'] );
        $this->assertArrayHasKey( 'object_type', $config['args'] );
        $this->assertArrayHasKey( 'rule_type', $config['args'] );
    }

    public function testGateDeniesWithoutTheCapability(): void {
        $controller = new Gr_Export_Controller();
        $verdict    = $controller->gate( new WP_REST_Request() );

        $this->assertInstanceOf( 'WP_Error', $verdict );
        $this->assertSame( 'gr_export_forbidden', $verdict->get_error_code() );
        $this->assertSame( 403, $verdict->get_error_data()['status'] );
    }

    public function testGateAllowsTheOwner(): void {
        $GLOBALS['gr_stub_caps'] = array( 'manage_options' );

        $controller = new Gr_Export_Controller();
        $this->assertTrue( $controller->gate( new WP_REST_Request() ) );
    }

    /**
     * Runs one export and returns the CSV body.
     *
     * @param string $dataset Dataset key.
     * @param array<string, mixed> $params Request parameters.
     * @return array{0: string, 1: \WP_REST_Response}
     */
    private function export( string $dataset, array $params = array() ): array {
        $request = new WP_REST_Request();
        $request->set_param( 'dataset', $dataset );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }

        $response = ( new Gr_Export_Controller() )->handle( $request );

        return array( (string) $response->get_data(), $response );
    }

    public function testSessionsExportStreamsTheColumnVocabulary(): void {
        $GLOBALS['wpdb']->var_result = '1';
        $GLOBALS['wpdb']->results    = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'SELECT visitor_id' ) ) {
                return array(
                    array(
                        'started_at'   => '2026-09-13 06:00:00',
                        'last_active'  => '2026-09-13 07:00:00',
                        'visitor_id'   => 'abcdef1234567890abcdef1234567890',
                        'session_id'   => 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
                        'channel'      => 'organic',
                        'utm_campaign' => 'spring, "big" sale',
                        'landing_path' => '/offer/?x=1',
                        'referrer_host'=> 'google.example',
                        'device_type'  => 'mobile',
                        'country_code' => 'US',
                        'pageviews'    => '4',
                        'is_bot'       => '0',
                    ),
                );
            }

            return array();
        };

        list( $csv, $response ) = $this->export( 'sessions', array( 'from' => '2026-09-01', 'to' => '2026-09-30' ) );

        // Header row plus the data row, commas inside the campaign
        // quoted by fputcsv's own rules.
        $lines = array_filter( explode( "\n", $csv ) );
        $this->assertCount( 2, $lines );
        $this->assertSame( 'started_at,last_active,visitor_id,session_id,channel,utm_campaign,landing_path,referrer_host,device_type,country_code,pageviews,is_bot', trim( (string) $lines[0] ) );
        $this->assertStringContainsString( '"spring, ""big"" sale"', $csv );

        // The read honors the filters and the export rides the row cap.
        $sql = implode( ' ', $GLOBALS['wpdb']->queries );
        $this->assertStringContainsString( "started_at >= '2026-09-01 00:00:00'", $sql );
        $this->assertStringContainsString( "started_at <= '2026-09-30 23:59:59'", $sql );
        $this->assertStringContainsString( 'LIMIT 5000', $sql );

        // Download headers, not inline rendering.
        $this->assertSame( 'text/csv; charset=utf-8', $response->get_headers()['Content-Type'] ?? '' );
        $this->assertSame( 'nosniff', $response->get_headers()['X-Content-Type-Options'] ?? '' );
        $this->assertStringContainsString( 'attachment; filename="greenpng-sessions-', (string) $response->get_headers()['Content-Disposition'] );
    }

    public function testAuditExportMirrorsTheTableFilters(): void {
        $GLOBALS['wpdb']->var_result = '0';
        $GLOBALS['wpdb']->results    = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'SELECT id, user_id' ) ) {
                return array(
                    array(
                        'id'          => '9',
                        'created_at'  => '2026-09-13 07:00:00',
                        'user_id'     => '1',
                        'action'      => 'add',
                        'object_type' => 'access_rule',
                        'object_id'   => '7',
                        'diff_json'   => '{"added":{"match_value":"198.51.100.7"}}',
                    ),
                );
            }

            return array();
        };

        list( $csv ) = $this->export(
            'audit',
            array(
                'object_type' => 'access_rule',
                'action'      => 'add',
                's'           => 'rule',
            )
        );

        $this->assertStringContainsString( 'id,created_at,user_id,action,object_type,object_id,diff_json', $csv );
        $this->assertStringContainsString( '198.51.100.7', $csv );

        // Every table filter reached the WHERE.
        $sql = implode( ' ', $GLOBALS['wpdb']->queries );
        $this->assertStringContainsString( "object_type = 'access_rule'", $sql );
        $this->assertStringContainsString( "action = 'add'", $sql );
        $this->assertStringContainsString( 'action LIKE', $sql );
    }

    public function testAccessRulesExportStreamsBothLists(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'gr_access_rules' ) ) {
                return array(
                    array(
                        'id'          => '7',
                        'rule_type'   => 'ban',
                        'match_kind'  => 'ip',
                        'match_value' => '198.51.100.7',
                        'note'        => 'scanner ban',
                        'is_active'   => '1',
                        'created_by'  => '1',
                        'created_at'  => '2026-09-13 07:00:00',
                        'updated_at'  => '2026-09-13 07:00:00',
                    ),
                );
            }

            return array();
        };

        list( $csv ) = $this->export( 'access_rules', array( 'rule_type' => 'ban' ) );

        $this->assertStringContainsString( 'id,rule_type,match_kind,match_value,note,is_active,created_by,created_at,updated_at', $csv );
        $this->assertStringContainsString( 'scanner ban', $csv );

        $sql = implode( ' ', $GLOBALS['wpdb']->queries );
        $this->assertStringContainsString( "rule_type = 'ban'", $sql );

        // Off-vocabulary types fall back to the ban list rather than
        // trusting the caller.
        $GLOBALS['wpdb']->queries = array();
        $this->export( 'access_rules', array( 'rule_type' => 'nonsense' ) );
        $sql = implode( ' ', $GLOBALS['wpdb']->queries );
        $this->assertStringContainsString( "rule_type = 'ban'", $sql );
    }

    public function testUnknownDatasetFallsBackToTheRulesList(): void {
        // The route pattern already blocks off-vocabulary datasets;
        // the handler still refuses to trust the value on its own.
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'gr_access_rules' ) ) {
                return array();
            }

            return array();
        };

        list( $csv, $response ) = $this->export( 'nonsense' );

        $this->assertStringContainsString( 'id,rule_type,match_kind,match_value,note,is_active,created_by,created_at,updated_at', $csv );
        $this->assertStringContainsString( 'greenpng-nonsense-', (string) $response->get_headers()['Content-Disposition'] );
    }
}
