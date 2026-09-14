<?php
/**
 * Contacts page (docs/06 Audience tree, ADR-0013 D5): the captured
 * lead list with masked emails, the tag vocabulary, and the RFM
 * segment distribution. Read-only — every write in the CRM lives on
 * the profile page or in the queue engines, so this page registers
 * no write handler and carries no nonce. Filters narrow by RFM
 * segment and tag, all whitelisted before they reach the repository.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\CRM\Gr_Rfm_Engine;
use GreenPNG\Core\Gr_Secrets;
use GreenPNG\Storage\Gr_Contact_Repository;

/**
 * Contacts reporting surface, read-only.
 */
final class Gr_Contacts_Page {

    /** Menu slug under the top-level greenpng menu. */
    public const SLUG = 'greenpng-contacts';

    /** List tab key. */
    public const TAB_LIST = 'list';

    /** Tags tab key. */
    public const TAB_TAGS = 'tags';

    /** RFM tab key. */
    public const TAB_RFM = 'rfm';

    /** Rows per page. */
    public const PER_PAGE = 20;

    /**
     * Page output: tab bar, the active tab's content.
     *
     * @return void
     */
    public static function render(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch on an owner-gated screen.
        $raw = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : self::TAB_LIST;
        $tab = in_array( $raw, array( self::TAB_LIST, self::TAB_TAGS, self::TAB_RFM ), true ) ? $raw : self::TAB_LIST;
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Contacts', 'greenpng' ); ?></h1>
            <hr class="wp-header-end" />

            <nav class="nav-tab-wrapper">
                <?php
                $tabs = array(
                    self::TAB_LIST => __( 'List', 'greenpng' ),
                    self::TAB_TAGS => __( 'Tags', 'greenpng' ),
                    self::TAB_RFM  => __( 'RFM segments', 'greenpng' ),
                );
                foreach ( $tabs as $key => $label ) :
                    $class = ( $key === $tab ) ? ' nav-tab-active' : '';
                    ?>
                    <a class="nav-tab<?php echo esc_attr( $class ); ?>"
                        href="<?php echo esc_attr( '?page=' . self::SLUG . '&amp;tab=' . $key ); ?>">
                        <?php echo esc_html( (string) $label ); ?>
                    </a>
                    <?php endforeach; ?>
            </nav>

            <?php
            if ( self::TAB_TAGS === $tab ) {
                self::render_tags();
            } elseif ( self::TAB_RFM === $tab ) {
                self::render_rfm();
            } else {
                self::render_list();
            }
            ?>
        </div>
        <?php
    }

    /**
     * The list tab: filter form, table, pagination links.
     *
     * @return void
     */
    private static function render_list(): void {
        $contacts = new Gr_Contact_Repository();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page state (page number and filters) on an owner-gated screen.
        $paged = isset( $_GET['paged'] ) ? absint( (int) wp_unslash( $_GET['paged'] ) ) : 1;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter; the value is whitelisted against the segment vocabulary below.
        $segment_raw = isset( $_GET['segment'] ) ? sanitize_key( (string) wp_unslash( $_GET['segment'] ) ) : '';
        $segment     = in_array( $segment_raw, Gr_Rfm_Engine::SEGMENTS, true ) ? $segment_raw : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter; absint bounds the value.
        $tag_id = isset( $_GET['tag_id'] ) ? absint( (int) wp_unslash( $_GET['tag_id'] ) ) : 0;

        $paged  = max( 1, $paged );
        $result = $contacts->paged( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE, $segment, $tag_id );

        $rows = $result['rows'];
        foreach ( $rows as &$row ) {
            // The mask is computed once here so the table never sees
            // plaintext; a failed decrypt reads as an unreadable
            // envelope rather than a broken mask.
            $email  = Gr_Secrets::decrypt( (string) ( $row['email_enc'] ?? '' ) );
            $masked = is_string( $email ) ? Gr_Secrets::mask( $email ) : '****';
            if ( '' === $masked ) {
                $masked = '****';
            }
            $row['email_mask'] = $masked;

            // The table reads one display name, not two columns.
            $row['name'] = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
        }
        unset( $row );

        $total = (int) $result['total'];
        $pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
        ?>
        <form method="get">
            <input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
            <input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB_LIST ); ?>" />
            <p>
                <label for="gr-segment-filter"><?php echo esc_html__( 'RFM segment', 'greenpng' ); ?></label>
                <select name="segment" id="gr-segment-filter">
                    <option value=""><?php echo esc_html__( 'All segments', 'greenpng' ); ?></option>
                    <?php foreach ( Gr_Rfm_Engine::SEGMENTS as $word ) : ?>
                        <option value="<?php echo esc_attr( $word ); ?>"<?php selected( $word, $segment ); ?>><?php echo esc_html( $word ); ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="gr-tag-filter"><?php echo esc_html__( 'Tag', 'greenpng' ); ?></label>
                <select name="tag_id" id="gr-tag-filter">
                    <option value="0"><?php echo esc_html__( 'All tags', 'greenpng' ); ?></option>
                    <?php foreach ( $contacts->tag_vocabulary() as $tag ) : ?>
                        <option value="<?php echo esc_attr( (string) $tag['id'] ); ?>"<?php selected( (string) $tag['id'], (string) $tag_id ); ?>>
                            <?php echo esc_html( (string) $tag['slug'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button( __( 'Filter', 'greenpng' ), 'secondary', 'filter', false ); ?>
            </p>
        </form>

        <?php
        $table = new Gr_Contacts_Table(
            array(
                'singular' => 'contact',
                'plural'   => 'contacts',
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
            <?php
        endif;
    }

    /**
     * The tags tab: the vocabulary with contact counts.
     *
     * @return void
     */
    private static function render_tags(): void {
        $tags = ( new Gr_Contact_Repository() )->tag_vocabulary();
        ?>
        <p>
            <?php
            /* translators: %s: the system tag namespace, literally "sys:". */
            printf( esc_html__( 'System tags (%s…) are attached by the engines and never re-attached after you remove them: removal is your call and it sticks. Form bridges attach one system tag per consented submission; the suspected-bot verdict attaches its own for review.', 'greenpng' ), 'sys:' );
            ?>
        </p>

        <table class="widefat striped" role="presentation">
            <thead>
                <tr>
                    <th scope="col"><?php echo esc_html__( 'Slug', 'greenpng' ); ?></th>
                    <th scope="col"><?php echo esc_html__( 'Name', 'greenpng' ); ?></th>
                    <th scope="col"><?php echo esc_html__( 'Kind', 'greenpng' ); ?></th>
                    <th scope="col"><?php echo esc_html__( 'Contacts', 'greenpng' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( array() === $tags ) : ?>
                    <tr><td colspan="4"><?php echo esc_html__( 'No tags in use yet.', 'greenpng' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $tags as $tag ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) $tag['slug'] ); ?></td>
                            <td><?php echo esc_html( (string) $tag['name'] ); ?></td>
                            <td><?php echo '1' === (string) $tag['is_system'] ? esc_html__( 'System', 'greenpng' ) : esc_html__( 'Custom', 'greenpng' ); ?></td>
                            <td><?php echo esc_html( (string) $tag['contacts'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * The RFM tab: the segment distribution over the stored segments.
     *
     * @return void
     */
    private static function render_rfm(): void {
        $people = ( new Gr_Contact_Repository() )->rfm_population();

        $counts = array_fill_keys( Gr_Rfm_Engine::SEGMENTS, 0 );
        $none   = 0;
        foreach ( $people as $row ) {
            $segment = (string) ( $row['rfm_segment'] ?? '' );
            if ( array_key_exists( $segment, $counts ) ) {
                ++$counts[ $segment ];
            } else {
                ++$none;
            }
        }
        ?>
        <p>
            <?php echo esc_html__( 'Segments refresh in the nightly pass, next to lead scoring. Recency reads the last visit, frequency the number of conversion bindings, and value their net amount after refunds. The eight words are the whole vocabulary — nothing outside it is ever stored.', 'greenpng' ); ?>
        </p>

        <table class="widefat striped" role="presentation">
            <thead>
                <tr>
                    <th scope="col"><?php echo esc_html__( 'Segment', 'greenpng' ); ?></th>
                    <th scope="col"><?php echo esc_html__( 'Contacts', 'greenpng' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $counts as $word => $count ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $word ); ?></td>
                        <td><?php echo esc_html( (string) $count ); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( $none > 0 ) : ?>
                    <tr>
                        <td><?php echo esc_html__( 'Not yet segmented', 'greenpng' ); ?></td>
                        <td><?php echo esc_html( (string) $none ); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }
}
