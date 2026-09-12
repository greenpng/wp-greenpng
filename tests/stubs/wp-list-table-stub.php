<?php
/**
 * WP_List_Table stand-in: the real class lives in wp-admin and needs
 * a screen object; this one carries just the mechanics our subclass
 * rides on — column headers, items, and a display() that assembles
 * markup from the overridden pieces, so list rendering is assertable
 * outside a WP admin request.
 *
 * @package GreenPNG\Tests
 */

if ( ! class_exists( 'WP_List_Table' ) ) {

	/**
	 * Minimal parent for Gr_Access_Rules_Table.
	 */
	class WP_List_Table {

		/**
		 * Rows to render.
		 *
		 * @var array<int, array<string, mixed>>
		 */
		public $items = array();

		/**
		 * Column header list from the subclass.
		 *
		 * @var array<int, string>
		 */
		protected $column_headers = array();

		/**
		 * Constructor accepting core's args shape.
		 *
		 * @param array<string, mixed> $args Screen arguments (recorded, unused).
		 */
		public function __construct( $args = array() ) {
			unset( $args );
		}

		/**
		 * Header setter core's prepare_items flow uses.
		 *
		 * @param array<int, string> $headers Column keys.
		 * @return void
		 */
		protected function _column_headers( $headers ) {
			$this->column_headers = $headers;
		}

		/**
		 * Column header list accessor.
		 *
		 * @return array<int, string>
		 */
		public function get_column_headers(): array {
			return $this->column_headers;
		}

		/**
		 * Renders the table: header row from get_columns(), body rows
		 * through column_cb()/column_default() on the subclass.
		 *
		 * @return void
		 */
		public function display() {
			$columns = $this->get_columns();

			echo '<table class="wp-list-table widefat striped">';
			echo '<thead><tr>';
			foreach ( $columns as $key => $label ) {
				echo '<th scope="col" class="manage-column column-' . esc_attr( (string) $key ) . '">' . esc_html( (string) $label ) . '</th>';
			}
			echo '</tr></thead>';
			echo '<tbody id="the-list">';

			if ( array() === $this->items ) {
				$this->no_items();
			} else {
				foreach ( $this->items as $item ) {
					$this->single_row( $item );
				}
			}

			echo '</tbody>';
			echo '</table>';
		}

		/**
		 * One body row: each column through the subclass hooks, in
		 * core's order — the checkbox column, then a column_{$key}
		 * method when the subclass defines one, then column_default().
		 *
		 * @param array<string, mixed> $item Row data.
		 * @return void
		 */
		protected function single_row( $item ) {
			echo '<tr>';
			foreach ( $this->get_columns() as $key => $label ) {
				echo '<td class="column-' . esc_attr( (string) $key ) . '">';
				if ( 'cb' === $key ) {
					$this->column_cb( $item );
				} elseif ( method_exists( $this, 'column_' . $key ) ) {
					$this->{ 'column_' . $key }( $item );
				} else {
					$this->column_default( $item, (string) $key );
				}
				echo '</td>';
			}
			echo '</tr>';
		}

		/**
		 * Subclass hooks (overridden).
		 *
		 * @return array<string, string>
		 */
		public function get_columns() {
			return array();
		}

		/**
		 * Checkbox column (overridden).
		 *
		 * @param array<string, mixed> $item Row data.
		 * @return void
		 */
		protected function column_cb( $item ) {
			unset( $item );
		}

		/**
		 * Default cell (overridden).
		 *
		 * @param array<string, mixed> $item Row data.
		 * @param string               $column Column key.
		 * @return void
		 */
		protected function column_default( $item, $column ) {
			unset( $item, $column );
		}

		/**
		 * Empty-list message (overridden).
		 *
		 * @return void
		 */
		public function no_items() {
			echo 'no items';
		}
	}
}
