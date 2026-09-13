<?php
/**
 * WP_List_Table stand-in: the real class lives in wp-admin and needs
 * a screen object; this one carries just the mechanics our subclass
 * rides on — the explicit header list, items, and a display() that
 * assembles markup from the overridden pieces. get_column_info()
 * mirrors core's contract: without explicitly declared headers the
 * table consults the current screen, and our pages register no list
 * screen, so the render is empty — the stand-in refuses to hide that
 * failure mode.
 *
 * @package GreenPNG\Tests
 */

if ( ! class_exists( 'WP_List_Table' ) ) {

	/**
	 * Minimal parent for Gr_Access_Rules_Table and Gr_Audit_Log_Table.
	 */
	class WP_List_Table {

		/**
		 * Rows to render.
		 *
		 * @var array<int, array<string, mixed>>
		 */
		public $items = array();

		/**
		 * Explicit header tuple set by the subclass: columns, hidden,
		 * sortable, primary. Core resolves this from the registered
		 * screen when it is empty, which on our pages yields nothing.
		 *
		 * @var array<int, mixed>
		 */
		protected $_column_headers = array();

		/**
		 * Constructor accepting core's args shape.
		 *
		 * @param array<string, mixed> $args Screen arguments (recorded, unused).
		 */
		public function __construct( $args = array() ) {
			unset( $args );
		}

		/**
		 * Core's header resolution: the explicit tuple, or the
		 * screen-less empty state a real admin request outside a list
		 * screen produces.
		 *
		 * @return array<int, mixed>
		 */
		public function get_column_info() {
			if ( empty( $this->_column_headers ) ) {
				return array( array(), array(), array(), null );
			}

			return $this->_column_headers;
		}

		/**
		 * Renders the table from the resolved header list, like core:
		 * no headers means an empty table, never a lucky fallback to
		 * get_columns().
		 *
		 * @return void
		 */
		public function display() {
			$columns = $this->get_column_info()[0];

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
		 * One body row: each resolved column through the subclass
		 * hooks, in core's order — the checkbox column, then a
		 * column_{$key} method when the subclass defines one, then
		 * column_default().
		 *
		 * @param array<string, mixed> $item Row data.
		 * @return void
		 */
		protected function single_row( $item ) {
			$columns = $this->get_column_info()[0];

			echo '<tr>';
			foreach ( $columns as $key => $label ) {
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
