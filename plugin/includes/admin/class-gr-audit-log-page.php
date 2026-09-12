<?php
/**
 * Audit Log page (docs/06 §1 tree): the newest audit
 * rows with server-side pagination — the page owns the page size,
 * the offset math, and the pagination links; the table renders only
 * what the query returned. Filters narrow by user, object family,
 * and action, all through the repository's whitelisted vocabulary.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Storage\Gr_Audit_Repository;

/**
 * Audit trail reporting surface, read-only.
 */
final class Gr_Audit_Log_Page {

    /** Menu slug under the top-level greenpng menu. */
    public const SLUG = 'greenpng-audit';

    /** Rows per page. */
    public const PER_PAGE = 20;

    /**
     * Page output: filter form, table, pagination links.
     *
     * @return void
     */
    public static function render(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page state (page number and filters) on an owner-gated screen.
        $paged = isset( $_GET['paged'] ) ? absint( (int) wp_unslash( $_GET['paged'] ) ) : 1;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter on an owner-gated screen; the repository whitelists keys and prepares values.
        $object_type = isset( $_GET['object_type'] ) ? sanitize_key( (string) wp_unslash( $_GET['object_type'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter on an owner-gated screen.
        $user_id = isset( $_GET['user_id'] ) ? absint( (int) wp_unslash( $_GET['user_id'] ) ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter on an owner-gated screen.
        $action = isset( $_GET['action'] ) ? sanitize_key( (string) wp_unslash( $_GET['action'] ) ) : '';

        $paged  = max( 1, $paged );
        $result = ( new Gr_Audit_Repository() )->query(
            array(
                'object_type' => $object_type,
                'user_id'     => $user_id,
                'action'      => $action,
            ),
            self::PER_PAGE,
            ( $paged - 1 ) * self::PER_PAGE
        );

        $rows  = $result['rows'];
        $total = (int) $result['total'];
        $pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );

        foreach ( $rows as &$row ) {
            $row['changes'] = self::diff_lines( (string) ( $row['diff_json'] ?? '' ) );
        }
        unset( $row );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Audit Log', 'greenpng' ); ?></h1>
            <hr class="wp-header-end" />

            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
                <p>
                    <label for="gr-audit-type"><?php echo esc_html__( 'Object type', 'greenpng' ); ?></label>
                    <input type="text" name="object_type" id="gr-audit-type" class="regular-text" value="<?php echo esc_attr( $object_type ); ?>" />
                    <label for="gr-audit-user"><?php echo esc_html__( 'User id', 'greenpng' ); ?></label>
                    <input type="number" name="user_id" id="gr-audit-user" class="small-text" min="0" value="<?php echo esc_attr( (string) $user_id ); ?>" />
                    <label for="gr-audit-action"><?php echo esc_html__( 'Action', 'greenpng' ); ?></label>
                    <input type="text" name="action" id="gr-audit-action" class="regular-text" value="<?php echo esc_attr( $action ); ?>" />
                    <?php submit_button( __( 'Filter', 'greenpng' ), 'secondary', 'filter', false ); ?>
                </p>
            </form>

            <?php
            $table = new Gr_Audit_Log_Table(
                array(
                    'singular' => 'audit_row',
                    'plural'   => 'audit_rows',
                    'ajax'     => false,
                ),
                $rows
            );
            $table->display();
            ?>

            <?php if ( $pages > 1 ) : ?>
                <nav class="tablenav"><div class="tablenav-pages">
                    <?php
                    // translators: %d: number of pages.
                    echo esc_html( sprintf( __( '%d pages', 'greenpng' ), $pages ) );
                    ?>
                    <?php
                    $links = paginate_links(
                        array(
                            'base'    => add_query_arg( 'paged', '%#%' ),
                            'format'  => '',
                            'current' => $paged,
                            'total'   => $pages,
                        )
                    );
                    if ( is_string( $links ) && '' !== $links ) {
                        echo $links; // phpcs:ignore WordPress.Security.EscapeOutput -- paginate_links() returns core-built anchor markup from our own arguments.
                    }
                    ?>
                </div></nav>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * The stored diff as plain text lines: "+ path = value" for
     * additions, "~ path: old → new" for modifications, "- path" for
     * removals. Values are clipped for display; the row keeps the
     * full diff.
     *
     * @param string $json The diff_json column.
     * @return array<int, string>
     */
    private static function diff_lines( string $json ): array {
        if ( '' === $json ) {
            return array();
        }

        $decoded = json_decode( $json, true );
        if ( ! is_array( $decoded ) ) {
            return array();
        }

        $lines = array();
        foreach ( array( 'added', 'modified', 'removed' ) as $kind ) {
            $entries = isset( $decoded[ $kind ] ) && is_array( $decoded[ $kind ] ) ? $decoded[ $kind ] : array();
            foreach ( $entries as $path => $entry ) {
                if ( 'modified' === $kind && is_array( $entry ) ) {
                    $lines[] = '~ ' . (string) $path . ': ' . self::clip( (string) ( $entry['old'] ?? '' ) ) . ' → ' . self::clip( (string) ( $entry['new'] ?? '' ) );
                    continue;
                }

                $prefix = ( 'added' === $kind ) ? '+ ' : '- ';
                if ( is_array( $entry ) ) {
                    $entry = '(array)';
                }
                $lines[] = $prefix . (string) $path . ( 'added' === $kind ? ' = ' . self::clip( (string) $entry ) : '' );
            }
        }

        return $lines;
    }

    /**
     * Display clip for one value.
     *
     * @param string $value Raw value text.
     * @return string
     */
    private static function clip( string $value ): string {
        return strlen( $value ) > 60 ? substr( $value, 0, 57 ) . '…' : $value;
    }
}
