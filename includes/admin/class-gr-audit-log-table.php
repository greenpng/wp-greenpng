<?php
/**
 * Audit log list (docs/06 §1): the WP_List_Table
 * subclass for gr_audit_logs rows. Pagination is served by the page
 * layer (the query's LIMIT/OFFSET and the pagination links), so the
 * table only renders the rows it is handed.
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
 * Renders audit rows.
 */
final class Gr_Audit_Log_Table extends \WP_List_Table {

    /**
     * Rows injected by the page (the filtered read already ran).
     *
     * @param array<string, mixed>              $args Core screen arguments.
     * @param array<int, array<string, string>> $rows Audit rows keyed by column.
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
            'created_at',
        );
    }

    /**
     * Column vocabulary.
     *
     * @return array<string, string>
     */
    public function get_columns() {
        return array(
            'created_at' => __( 'Time', 'greenpng' ),
            'user_id'    => __( 'User', 'greenpng' ),
            'action'     => __( 'Action', 'greenpng' ),
            'object'     => __( 'Object', 'greenpng' ),
            'changes'    => __( 'Changes', 'greenpng' ),
        );
    }

    /**
     * The object cell: family plus identifier.
     *
     * @param array<string, string> $item Row.
     * @return void
     */
    protected function column_object( $item ) {
        echo esc_html( (string) ( $item['object_type'] ?? '' ) . ' #' . (string) ( $item['object_id'] ?? '' ) );
    }

    /**
     * The changes cell: the page layer already turned the stored diff
     * into plain text lines; each line escapes on its own.
     *
     * @param array<string, mixed> $item Row.
     * @return void
     */
    protected function column_changes( $item ) {
        $lines = isset( $item['changes'] ) && is_array( $item['changes'] ) ? $item['changes'] : array();
        if ( array() === $lines ) {
            echo esc_html__( 'No field changes.', 'greenpng' );

            return;
        }

        foreach ( $lines as $line ) {
            echo '<div>' . esc_html( (string) $line ) . '</div>';
        }
    }

    /**
     * Every other cell renders as text; the user reads as an id
     * reference.
     *
     * @param array<string, string> $item   Row.
     * @param string                $column Column key.
     * @return void
     */
    protected function column_default( $item, $column ) {
        if ( 'user_id' === $column ) {
            echo esc_html( '#' . (string) ( $item['user_id'] ?? '' ) );

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
        echo esc_html__( 'No audited changes recorded yet.', 'greenpng' );
    }
}
