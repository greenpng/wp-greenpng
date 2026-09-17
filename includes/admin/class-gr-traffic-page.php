<?php
/**
 * Traffic & Security page (docs/13 U5, docs/06 §1): four tabs over
 * native components — the live stream (server-rendered table that
 * the datagrid enhances by polling), threat events (fold rows with
 * display-masked addresses, the storage form stays complete), the
 * fraud audit (bot conclusions as they landed on the event
 * stream), and the visitor session list (operational session rows
 * with date range, search, and the CSV export, docs/12 G4/G6 — no
 * IP, no user agent). Everything is read-only; every surface is
 * owner-gated by the menu capability.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Storage\Gr_Event_Repository;
use GreenPNG\Storage\Gr_Security_Log_Repository;
use GreenPNG\Storage\Gr_Session_Repository;

/**
 * Tabbed read surface for the security operations data.
 */
final class Gr_Traffic_Page {

    /** Menu slug. */
    public const SLUG = 'greenpng-traffic';

    /** Grid mount id on the live tab. */
    public const GRID_MOUNT = 'gr-live-grid';

    /** Rows per page on the sessions tab. */
    public const SESSIONS_PER_PAGE = 20;

    /** Tab keys in display order. */
    public const TAB_LIVE     = 'live';
    public const TAB_THREAT   = 'threats';
    public const TAB_FRAUD    = 'fraud';
    public const TAB_SESSIONS = 'sessions';

    /**
     * Page output: tab bar plus the active tab's content.
     *
     * @return void
     */
    public static function render(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch on an owner-gated screen; no state changes anywhere on this page.
        $tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : self::TAB_LIVE;
        if ( ! in_array( $tab, array( self::TAB_LIVE, self::TAB_THREAT, self::TAB_FRAUD, self::TAB_SESSIONS ), true ) ) {
            $tab = self::TAB_LIVE;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Traffic &amp; Security', 'greenpng' ); ?></h1>
            <hr class="wp-header-end" />

            <nav class="nav-tab-wrapper">
                <?php
                $tabs = array(
                    self::TAB_LIVE     => __( 'Live stream', 'greenpng' ),
                    self::TAB_THREAT   => __( 'Threat events', 'greenpng' ),
                    self::TAB_FRAUD    => __( 'Fraud audit', 'greenpng' ),
                    self::TAB_SESSIONS => __( 'Visitor sessions', 'greenpng' ),
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

            <?php if ( self::TAB_LIVE === $tab ) : ?>
                <?php self::render_live(); ?>
            <?php elseif ( self::TAB_THREAT === $tab ) : ?>
                <?php self::render_threats(); ?>
            <?php elseif ( self::TAB_SESSIONS === $tab ) : ?>
                <?php self::render_sessions(); ?>
            <?php else : ?>
                <?php self::render_fraud(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Live tab: the server-rendered table IS the content; the grid
     * script replaces it in place when it runs (docs/06 §2.2
     * degradation contract).
     *
     * @return void
     */
    private static function render_live(): void {
        $rows = ( new Gr_Event_Repository() )->recent( '', 30 );
        ?>
        <h2><?php echo esc_html__( 'Newest events', 'greenpng' ); ?></h2>
        <div id="<?php echo esc_attr( self::GRID_MOUNT ); ?>">
            <?php if ( array() === $rows ) : ?>
                <p><?php echo esc_html__( 'No events recorded yet.', 'greenpng' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th scope="col"><?php echo esc_html__( 'Time', 'greenpng' ); ?></th>
                            <th scope="col"><?php echo esc_html__( 'Event', 'greenpng' ); ?></th>
                            <th scope="col"><?php echo esc_html__( 'Group', 'greenpng' ); ?></th>
                            <th scope="col"><?php echo esc_html__( 'Visitor', 'greenpng' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( (string) ( $row['created_at'] ?? '' ) ); ?></td>
                                <td><?php echo esc_html( (string) ( $row['event_name'] ?? '' ) ); ?></td>
                                <td><?php echo esc_html( (string) ( $row['event_group'] ?? '' ) ); ?></td>
                                <td><?php echo esc_html( self::short( (string) ( $row['visitor_id'] ?? '' ) ) ); ?></td>
                            </tr>
                            <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Threat events tab: fold rows, addresses masked for display —
     * the display rule from docs/05 §3.1, never stored back.
     *
     * @return void
     */
    private static function render_threats(): void {
        $rows = ( new Gr_Security_Log_Repository() )->recent( 30 );
        ?>
        <h2><?php echo esc_html__( 'Threat events, newest first', 'greenpng' ); ?></h2>
        <?php if ( array() === $rows ) : ?>
            <p><?php echo esc_html__( 'No security findings recorded yet.', 'greenpng' ); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col"><?php echo esc_html__( 'Last seen', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Rule', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Address', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Path', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Hits', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Action', 'greenpng' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) ( $row['last_seen'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['rule_id'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( gr_mask_ip( (string) ( $row['ip'] ?? '' ) ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['request_path'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['hit_count'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['action_taken'] ?? '' ) ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                </tbody>
            </table>
            <p><?php echo esc_html__( 'Addresses are masked for display; the ban tooling works on the complete stored form.', 'greenpng' ); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Visitor sessions tab (docs/12 G4): operational session rows —
     * newest activity first, server-side pagination, date range and
     * search through the shared filter vocabulary, and the CSV
     * download honoring the same filters. The column set stays on
     * behavior, never identification: no IP, no user agent.
     *
     * @return void
     */
    private static function render_sessions(): void {
        $filters = Gr_List_Filters::parse();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page number on an owner-gated screen.
        $paged = isset( $_GET['paged'] ) ? absint( (int) wp_unslash( $_GET['paged'] ) ) : 1;
        $paged = max( 1, $paged );

        $result = ( new Gr_Session_Repository() )->paged(
            $filters,
            self::SESSIONS_PER_PAGE,
            ( $paged - 1 ) * self::SESSIONS_PER_PAGE
        );

        $rows  = $result['rows'];
        $total = (int) $result['total'];
        $pages = max( 1, (int) ceil( $total / self::SESSIONS_PER_PAGE ) );
        ?>
        <h2><?php echo esc_html__( 'Visitor sessions, newest activity first', 'greenpng' ); ?></h2>

        <form method="get">
            <input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
            <input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB_SESSIONS ); ?>" />
            <p>
                <?php Gr_List_Filters::controls( $filters ); ?>
                <?php submit_button( __( 'Filter', 'greenpng' ), 'secondary', 'filter', false ); ?>
                <a class="button" href="<?php echo esc_attr( Gr_List_Filters::export_url( 'sessions', $filters ) ); ?>">
                    <?php echo esc_html__( 'Export CSV', 'greenpng' ); ?>
                </a>
            </p>
        </form>

        <?php
        $table = new Gr_Sessions_Table(
            array(
                'singular' => 'session_row',
                'plural'   => 'session_rows',
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
        <?php
    }

    /**
     * Fraud audit tab: bot conclusions exactly as they crossed the
     * channel (docs/13 W13) — conclusions, never the raw signals.
     *
     * @return void
     */
    private static function render_fraud(): void {
        $rows = ( new Gr_Event_Repository() )->recent( 'security_conclusion', 30 );
        ?>
        <h2><?php echo esc_html__( 'Bot conclusions, newest first', 'greenpng' ); ?></h2>
        <?php if ( array() === $rows ) : ?>
            <p><?php echo esc_html__( 'No bot conclusions recorded yet.', 'greenpng' ); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col"><?php echo esc_html__( 'Time', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Visitor', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Suspected bot', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Tier', 'greenpng' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $row ) : ?>
                        <?php
                        $payload = is_array( $row['payload'] ?? null ) ? $row['payload'] : array();
                        $bot     = ! empty( $payload['suspected_bot'] );
                        $tier    = (string) ( $payload['bot_tier'] ?? '' );
                        ?>
                        <tr>
                            <td><?php echo esc_html( (string) ( $row['created_at'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( self::short( (string) ( $row['visitor_id'] ?? '' ) ) ); ?></td>
                            <td><?php echo esc_html( $bot ? __( 'Yes', 'greenpng' ) : __( 'No', 'greenpng' ) ); ?></td>
                            <td><?php echo esc_html( $tier ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    /**
     * Short display form for visitor ids.
     *
     * @param string $visitor Full id.
     * @return string
     */
    private static function short( string $visitor ): string {
        if ( '' === $visitor ) {
            return '';
        }

        return substr( $visitor, 0, 8 ) . '…';
    }
}
