<?php
/**
 * Contacts list (docs/06 Audience tree, ADR-0013 D5): the
 * WP_List_Table subclass for gr_contacts rows. Pagination is served
 * by the page layer (LIMIT/OFFSET plus the links), so the table only
 * renders the rows it is handed. The email column shows the mask the
 * page layer computed — the plaintext never enters this class.
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
 * Renders contact rows.
 */
final class Gr_Contacts_Table extends \WP_List_Table {

    /**
     * Rows injected by the page (the filtered read already ran).
     *
     * @param array<string, mixed>              $args Core screen arguments.
     * @param array<int, array<string, string>> $rows Contact rows keyed by column.
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
            'email',
        );
    }

    /**
     * Column vocabulary.
     *
     * @return array<string, string>
     */
    public function get_columns() {
        return array(
            'email'       => __( 'Email (masked)', 'greenpng' ),
            'name'        => __( 'Name', 'greenpng' ),
            'lead_score'  => __( 'Lead score', 'greenpng' ),
            'rfm_segment' => __( 'RFM segment', 'greenpng' ),
            'ltv'         => __( 'Net value', 'greenpng' ),
            'last_seen'   => __( 'Last seen', 'greenpng' ),
        );
    }

    /**
     * The email cell: the mask plus the inline entry into the
     * profile.
     *
     * @param array<string, string> $item Row.
     * @return void
     */
    protected function column_email( $item ) {
        $id      = (int) ( $item['id'] ?? 0 );
        $mask    = (string) ( $item['email_mask'] ?? '' );
        $profile = add_query_arg(
            array(
                'page'       => Gr_Contact_Profile_Page::SLUG,
                'contact_id' => $id,
            ),
            admin_url( 'admin.php' )
        );

        echo '<strong>' . esc_html( $mask ) . '</strong>';
        // phpcs:ignore WordPress.Security.EscapeOutput -- row_actions() returns core-built action markup around the anchor; the URL is built by add_query_arg() from an admin base.
        echo $this->row_actions(
            array(
                'profile' => '<a href="' . esc_url( $profile ) . '">' . esc_html__( 'Open profile', 'greenpng' ) . '</a>',
            )
        );
    }

    /**
     * Every other cell renders as text.
     *
     * @param array<string, string> $item   Row.
     * @param string                $column Column key.
     * @return void
     */
    protected function column_default( $item, $column ) {
        echo esc_html( (string) ( $item[ $column ] ?? '' ) );
    }

    /**
     * Empty-list message.
     *
     * @return void
     */
    public function no_items() {
        echo esc_html__( 'No contacts captured yet. A consented form submission creates the first one.', 'greenpng' );
    }
}
