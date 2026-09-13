<?php
/**
 * CSV export endpoint (docs/12 G6, docs/11 §3): one REST route over
 * a closed dataset vocabulary — visitor sessions, audit rows, access
 * rules — honoring the same filter vocabulary the admin tables use.
 * Cookie authentication with the REST nonce as a query parameter is
 * what lets the download be a plain link; the capability gate stays
 * manage_options on every dataset. Rows are capped so an export is
 * a bounded read, and the response is text/csv with no sniffing
 * room.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Rest;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Storage\Gr_Access_Rules_Repository;
use GreenPNG\Storage\Gr_Audit_Repository;
use GreenPNG\Storage\Gr_Session_Repository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /greenpng/v1/export/{dataset}.
 */
final class Gr_Export_Controller {

    /** Route pattern under the plugin namespace. */
    public const ROUTE = '/export/(?P<dataset>sessions|audit|access_rules)';

    /** Upper bound of one export; larger result sets truncate. */
    public const ROW_CAP = 5000;

    /**
     * Route registration on rest_api_init.
     *
     * @return void
     */
    public static function register_routes(): void {
        register_rest_route(
            'greenpng/v1',
            self::ROUTE,
            array(
                'methods'             => 'GET',
                'callback'            => array( new self(), 'handle' ),
                'permission_callback' => array( new self(), 'gate' ),
                'args'                => array(
                    'from'        => array(
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'to'          => array(
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    's'           => array(
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'object_type' => array(
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ),
                    'user_id'     => array(
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ),
                    'action'      => array(
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ),
                    'rule_type'   => array(
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ),
                ),
            )
        );
    }

    /**
     * Permission stage: exports carry site data, owner-only on every
     * dataset (docs/06 §2.2). The request parameter is deliberately
     * not declared: core passes it, plain PHP methods ignore extras,
     * and the gate needs nothing from it.
     *
     * @return true|WP_Error
     */
    public function gate() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error(
                'gr_export_forbidden',
                __( 'You do not have permission to export this data.', 'greenpng' ),
                array( 'status' => 403 )
            );
        }

        return true;
    }

    /**
     * Streams the dataset as CSV: header row from the dataset's
     * column vocabulary, then at most ROW_CAP data rows in the same
     * order the admin table shows.
     *
     * @param WP_REST_Request $request Incoming request.
     * @return WP_REST_Response
     */
    public function handle( WP_REST_Request $request ): WP_REST_Response {
        $dataset = sanitize_key( (string) $request->get_param( 'dataset' ) );

        $filters = array(
            'from' => (string) $request->get_param( 'from' ),
            'to'   => (string) $request->get_param( 'to' ),
            's'    => (string) $request->get_param( 's' ),
        );

        switch ( $dataset ) {
            case 'sessions':
                $result = ( new Gr_Session_Repository() )->paged( $filters, self::ROW_CAP, 0 );
                $rows   = $result['rows'];
                $header = array( 'started_at', 'last_active', 'visitor_id', 'session_id', 'channel', 'utm_campaign', 'landing_path', 'referrer_host', 'device_type', 'country_code', 'pageviews', 'is_bot' );
                break;
            case 'audit':
                $result = ( new Gr_Audit_Repository() )->query(
                    array(
                        'from'        => $filters['from'],
                        'to'          => $filters['to'],
                        's'           => $filters['s'],
                        'object_type' => (string) $request->get_param( 'object_type' ),
                        'user_id'     => (int) $request->get_param( 'user_id' ),
                        'action'      => (string) $request->get_param( 'action' ),
                    ),
                    self::ROW_CAP,
                    0
                );
                $rows   = $result['rows'];
                $header = array( 'id', 'created_at', 'user_id', 'action', 'object_type', 'object_id', 'diff_json' );
                break;
            default:
                $type   = (string) $request->get_param( 'rule_type' );
                $type   = in_array( $type, array( 'allow', 'ban' ), true ) ? $type : 'ban';
                $rows   = ( new Gr_Access_Rules_Repository() )->rules_of_type( $type );
                $header = array( 'id', 'rule_type', 'match_kind', 'match_value', 'note', 'is_active', 'created_by', 'created_at', 'updated_at' );
                break;
        }

        $csv = $this->build_csv( $header, $rows );

        $response = new WP_REST_Response( $csv, 200 );
        $response->header( 'Content-Type', 'text/csv; charset=utf-8' );
        $response->header( 'Content-Disposition', 'attachment; filename="greenpng-' . $dataset . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
        $response->header( 'X-Content-Type-Options', 'nosniff' );

        return $response;
    }

    /**
     * CSV assembly through the stream functions: fputcsv owns the
     * quoting rules — with the separator, enclosure, and escape
     * passed explicitly so the document is identical across the
     * supported PHP range and free of the 8.1+ implicit-default
     * deprecation — the temp stream bounds memory to the row cap,
     * and only scalar values ever reach a cell.
     *
     * @param array<int, string>               $header Column vocabulary.
     * @param array<int, array<string, mixed>> $rows   Dataset rows.
     * @return string Full CSV document.
     */
    private function build_csv( array $header, array $rows ): string {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is a memory stream, not a filesystem path; WP_Filesystem owns disk files only.
        $stream = fopen( 'php://temp', 'w+' );
        if ( false === $stream ) {
            return '';
        }

        fputcsv( $stream, $header, ',', '"', '\\' );

        foreach ( $rows as $row ) {
            $cells = array();
            foreach ( $header as $key ) {
                $raw     = $row[ $key ] ?? '';
                $cells[] = is_scalar( $raw ) ? (string) $raw : '';
            }
            fputcsv( $stream, $cells, ',', '"', '\\' );
        }

        rewind( $stream );
        $csv = (string) stream_get_contents( $stream );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- memory stream teardown, not a filesystem handle.
        fclose( $stream );

        return $csv;
    }
}
