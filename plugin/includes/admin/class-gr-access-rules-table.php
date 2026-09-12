<?php
/**
 * Access rules list (docs/13 U6, docs/06 §2.1): one WP_List_Table
 * subclass serves both tabs — the columns are the same, the rows
 * differ by rule_type. The real parent class loads from wp-admin on
 * admin requests; the class_exists guard keeps the file includable
 * everywhere else without a screen object.
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
 * Renders one rule_type's rows.
 */
final class Gr_Access_Rules_Table extends \WP_List_Table {

    /**
     * Rows injected by the page (the repository read already ran).
     *
     * @param array<string, mixed>              $args Core screen arguments.
     * @param array<int, array<string, string>> $rows Full-column rule rows.
     */
    public function __construct( $args = array(), array $rows = array() ) {
        parent::__construct( $args );
        $this->items = $rows;
    }

    /**
     * Column vocabulary; 'cb' is core's checkbox column.
     *
     * @return array<string, string>
     */
    public function get_columns() {
        return array(
            'cb'          => '<input type="checkbox" />',
            'match_value' => __( 'Value', 'greenpng' ),
            'match_kind'  => __( 'Matches', 'greenpng' ),
            'note'        => __( 'Note', 'greenpng' ),
            'is_active'   => __( 'Active', 'greenpng' ),
            'created_at'  => __( 'Added', 'greenpng' ),
        );
    }

    /**
     * Checkbox cell: bulk actions address rule ids through it.
     *
     * @param array<string, string> $item Row.
     * @return void
     */
    protected function column_cb( $item ) {
        printf(
            '<input type="checkbox" name="rule[]" value="%s" />',
            esc_attr( (string) ( $item['id'] ?? '' ) )
        );
    }

    /**
     * Every other cell renders as text; the active flag reads as a
     * localized yes/no instead of a bare 1/0.
     *
     * @param array<string, string> $item   Row.
     * @param string                $column Column key.
     * @return void
     */
    protected function column_default( $item, $column ) {
        if ( 'is_active' === $column ) {
            echo esc_html( ! empty( $item['is_active'] ) ? __( 'Yes', 'greenpng' ) : __( 'No', 'greenpng' ) );

            return;
        }

        echo esc_html( (string) ( $item[ $column ] ?? '' ) );
    }

    /**
     * Empty-list message per tab vocabulary.
     *
     * @return void
     */
    public function no_items() {
        echo esc_html__( 'No rules yet.', 'greenpng' );
    }
}
