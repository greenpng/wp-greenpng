<?php
/**
 * Visitor session list (docs/12 G4, docs/06 §1): the WP_List_Table
 * subclass for gr_sessions rows. Columns stay on the operational
 * vocabulary — when, landing, channel, device, depth, bot verdict —
 * and never render an IP or user agent (the IAWP Journeys shape:
 * behavior over identification). Pagination is served by the page
 * layer, so the table only renders the rows it is handed.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders session rows.
 */
final class Gr_Sessions_Table extends \WP_List_Table {

    /**
     * Rows injected by the page (the filtered read already ran).
     *
     * @param array<string, mixed>              $args Core screen arguments.
     * @param array<int, array<string, string>> $rows Session rows keyed by column.
     */
    public function __construct( $args = array(), array $rows = array() ) {
        parent::__construct( $args );
        $this->items = $rows;

        // Explicit header tuple: no list screen is registered for this
        // page, so core's screen-based resolution would render nothing.
        $this->_column_headers = array(
            $this->get_columns(),
            array(),
            array(),
            'landing_path',
        );
    }

    /**
     * Column vocabulary.
     *
     * @return array<string, string>
     */
    public function get_columns() {
        return array(
            'started_at'   => __( 'Started', 'greenpng' ),
            'last_active'  => __( 'Last active', 'greenpng' ),
            'visitor_id'   => __( 'Visitor', 'greenpng' ),
            'channel'      => __( 'Channel', 'greenpng' ),
            'landing_path' => __( 'Landing', 'greenpng' ),
            'device_type'  => __( 'Device', 'greenpng' ),
            'pageviews'    => __( 'Views', 'greenpng' ),
            'ip_quality'   => __( 'Hosting', 'greenpng' ),
            'is_bot'       => __( 'Suspected bot', 'greenpng' ),
        );
    }

    /**
     * The started cell: time-ago reading with the exact stamp on the
     * title for hovering and assistive tech.
     *
     * @param array<string, string> $item Row.
     * @return void
     */
    protected function column_started_at( $item ) {
        self::time_ago( (string) ( $item['started_at'] ?? '' ) );
    }

    /**
     * The last-active cell, same shape as started.
     *
     * @param array<string, string> $item Row.
     * @return void
     */
    protected function column_last_active( $item ) {
        self::time_ago( (string) ( $item['last_active'] ?? '' ) );
    }

    /**
     * The visitor cell: short display form — the full identity never
     * widens the table.
     *
     * @param array<string, string> $item Row.
     * @return void
     */
    protected function column_visitor_id( $item ) {
        $visitor = (string) ( $item['visitor_id'] ?? '' );
        if ( '' === $visitor ) {
            echo esc_html__( 'Unknown', 'greenpng' );

            return;
        }

        // The short form keeps the table narrow; the title carries the
        // full identity so an owner can copy it into the privacy tools.
        echo '<span title="' . esc_attr( $visitor ) . '">' . esc_html( substr( $visitor, 0, 8 ) . '…' ) . '</span>';
    }

    /**
     * The channel cell: the channel code with the campaign beneath
     * it when one was captured.
     *
     * @param array<string, string> $item Row.
     * @return void
     */
    protected function column_channel( $item ) {
        echo esc_html( (string) ( $item['channel'] ?? '' ) );
        $campaign = (string) ( $item['utm_campaign'] ?? '' );
        if ( '' !== $campaign ) {
            echo '<div>' . esc_html( $campaign ) . '</div>';
        }
    }

    /**
     * Every other cell renders as text.
     *
     * @param array<string, string> $item   Row.
     * @param string                $column Column key.
     * @return void
     */
    protected function column_default( $item, $column ) {
        if ( 'is_bot' === $column ) {
            echo esc_html( ! empty( $item['is_bot'] ) ? __( 'Yes', 'greenpng' ) : __( 'No', 'greenpng' ) );

            return;
        }

        if ( 'ip_quality' === $column ) {
            // The category word reads as a plain marker; the empty
            // state is an honest "not known to be hosting", not a
            // claim the address is residential.
            echo esc_html( 'hosting' === (string) ( $item['ip_quality'] ?? '' ) ? __( 'Yes', 'greenpng' ) : __( 'No', 'greenpng' ) );

            return;
        }

        echo esc_html( (string) ( $item[ $column ] ?? '' ) );
    }

    /**
     * Empty-list message.
     *
     * @return void
     */
    public function no_items() {
        echo esc_html__( 'No visitor sessions recorded yet.', 'greenpng' );
    }

    /**
     * Time-ago cell content: human_time_diff against the site clock,
     * both stamps parsed under the same clock so the difference is
     * exact, raw stamp on the title attribute.
     *
     * @param string $stamp 'Y-m-d H:i:s' site-local stamp.
     * @return void
     */
    private static function time_ago( string $stamp ) {
        if ( '' === $stamp ) {
            echo esc_html__( 'Unknown', 'greenpng' );

            return;
        }

        $then = strtotime( $stamp );
        $now  = strtotime( (string) current_time( 'mysql' ) );
        if ( false === $then || false === $now ) {
            echo esc_html( $stamp );

            return;
        }

        // translators: %s: human-readable time difference, e.g. "5 mins".
        echo '<span title="' . esc_attr( $stamp ) . '">' . esc_html( sprintf( __( '%s ago', 'greenpng' ), human_time_diff( $then, $now ) ) ) . '</span>';
    }
}
