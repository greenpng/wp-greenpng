<?php
/**
 * Behavior Insights page (docs/06 §1 tree, ADR-0012 D4): the
 * engagement and friction vocabulary over the behavior stream. The
 * tiles read the daily summary (never the raw table); the drill-down
 * lists read the event stream itself, which retention keeps bounded
 * to 30 days — the same drill-down semantics as the Bot & Device
 * Signals page. Everything is read-only and consent-gated upstream:
 * rows only exist for visitors who allowed marketing.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Storage\Gr_Daily_Stats_Repository;
use GreenPNG\Storage\Gr_Event_Repository;

/**
 * Behavior reporting surface.
 */
final class Gr_Behavior_Page {

    /** Menu slug under the top-level greenpng menu. */
    public const SLUG = 'greenpng-behavior';

    /** Summary window, days. */
    public const DAYS = 30;

    /** Drill-down rows per event name. */
    public const LIST_LIMIT = 25;

    /** Engagement tab key. */
    public const TAB_ENGAGEMENT = 'engagement';

    /** Friction tab key. */
    public const TAB_FRICTION = 'friction';

    /**
     * Page output: tab bar plus the active tab's tiles and lists.
     *
     * @return void
     */
    public static function render(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch on an owner-gated screen.
        $tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : self::TAB_ENGAGEMENT;
        if ( ! in_array( $tab, array( self::TAB_ENGAGEMENT, self::TAB_FRICTION ), true ) ) {
            $tab = self::TAB_ENGAGEMENT;
        }

        $tiles = self::tile_totals();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Behavior Insights', 'greenpng' ); ?></h1>
            <hr class="wp-header-end" />

            <p><?php echo esc_html__( 'Engagement and friction signals from consenting visitors: dwell time, scroll depth, rage clicks, and dead clicks. Rows exist only for visitors who allowed marketing use, and the module is off until the site owner switches it on.', 'greenpng' ); ?></p>

            <nav class="nav-tab-wrapper">
                <?php
                $tabs = array(
                    self::TAB_ENGAGEMENT => __( 'Dwell &amp; scroll', 'greenpng' ),
                    self::TAB_FRICTION   => __( 'Rage &amp; dead clicks', 'greenpng' ),
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

            <div class="gr-kpi-grid">
                <?php foreach ( $tiles as $tile ) : ?>
                    <div class="postbox">
                        <h2 class="hndle"><?php echo esc_html( $tile['label'] ); ?></h2>
                        <div class="inside">
                            <span class="gr-kpi-value"><?php echo esc_html( number_format( (float) $tile['value'] ) ); ?></span>
                            <?php if ( '' !== (string) $tile['note'] ) : ?>
                                <p class="description"><?php echo esc_html( (string) $tile['note'] ); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ( self::TAB_ENGAGEMENT === $tab ) : ?>
                <?php self::render_list( 'dwell', __( 'Dwell times, newest first', 'greenpng' ), __( 'Bucket', 'greenpng' ) ); ?>
                <?php self::render_list( 'scroll_depth', __( 'Scroll milestones, newest first', 'greenpng' ), __( 'Milestone', 'greenpng' ) ); ?>
            <?php else : ?>
                <?php self::render_list( 'rage_click', __( 'Rage clicks, newest first', 'greenpng' ), __( 'Clicks', 'greenpng' ), true ); ?>
                <?php self::render_list( 'dead_click', __( 'Dead clicks, newest first', 'greenpng' ), __( 'Locator', 'greenpng' ), true ); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * The four tiles over the summary window; names missing from the
     * summary read as zero rather than disappearing, so the page keeps
     * its shape on a fresh install.
     *
     * @return array<int, array{label: string, value: int, note: string}>
     */
    private static function tile_totals(): array {
        $dimension = ( new Gr_Daily_Stats_Repository() )->dimension( 'behavior_events', self::DAYS, 10 );
        $totals    = array();
        foreach ( $dimension as $row ) {
            $totals[ (string) $row['key'] ] = (int) $row['value'];
        }

        $dwell  = $totals['dwell'] ?? 0;
        $scroll = $totals['scroll_depth'] ?? 0;

        return array(
            array(
                'label' => __( 'Dwell events, last 30 days', 'greenpng' ),
                'value' => $dwell,
                'note'  => __( 'Buckets: 0-15, 15-60, 60-180, 180+ seconds of active time.', 'greenpng' ),
            ),
            array(
                'label' => __( 'Scroll milestones, last 30 days', 'greenpng' ),
                'value' => $scroll,
                // translators: %d: share of dwell events that reached a milestone.
                'note'  => $dwell > 0 ? sprintf( __( 'Deep readers: %d%% of dwell pages reported a milestone.', 'greenpng' ), (int) round( 100 * $scroll / max( $dwell, 1 ) ) ) : '',
            ),
            array(
                'label' => __( 'Rage clicks, last 30 days', 'greenpng' ),
                'value' => $totals['rage_click'] ?? 0,
                'note'  => __( 'Three or more clicks within one second inside a 20px radius.', 'greenpng' ),
            ),
            array(
                'label' => __( 'Dead clicks, last 30 days', 'greenpng' ),
                'value' => $totals['dead_click'] ?? 0,
                'note'  => __( 'Clicks on non-interactive elements with no follow-up change for 600ms.', 'greenpng' ),
            ),
        );
    }

    /**
     * One drill-down list over the event stream, newest first; the
     * events table holds at most its retention window, so the limit
     * is the boundary.
     *
     * @param string $name        Event name.
     * @param string $heading     Section heading.
     * @param string $value_label Column label for the payload value.
     * @param bool   $with_locator Whether the locator column joins.
     * @return void
     */
    private static function render_list( string $name, string $heading, string $value_label, bool $with_locator = false ): void {
        $rows = ( new Gr_Event_Repository() )->recent( $name, self::LIST_LIMIT );
        ?>
        <h2><?php echo esc_html( $heading ); ?></h2>
        <table class="widefat striped">
            <thead><tr>
                <th><?php echo esc_html__( 'Time', 'greenpng' ); ?></th>
                <th><?php echo esc_html__( 'Visitor', 'greenpng' ); ?></th>
                <th><?php echo esc_html__( 'Path', 'greenpng' ); ?></th>
                <th><?php echo esc_html( $value_label ); ?></th>
                <?php if ( $with_locator ) : ?>
                    <th><?php echo esc_html__( 'Locator', 'greenpng' ); ?></th>
                <?php endif; ?>
            </tr></thead>
            <tbody>
                <?php if ( array() === $rows ) : ?>
                    <tr><td colspan="<?php echo esc_attr( (string) ( $with_locator ? 5 : 4 ) ); ?>"><?php echo esc_html__( 'No events recorded yet.', 'greenpng' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $rows as $row ) : ?>
                        <?php
                        $payload = isset( $row['payload'] ) && is_array( $row['payload'] ) ? $row['payload'] : array();
                        $visitor = (string) ( $row['visitor_id'] ?? '' );
                        $value   = '';
                        if ( 'dwell' === $name ) {
                            $value = (string) ( $payload['bucket'] ?? '' ) . ' ' . __( 'seconds', 'greenpng' );
                        } elseif ( 'scroll_depth' === $name ) {
                            $value = (string) ( $payload['milestone'] ?? '' ) . '%';
                        } elseif ( 'rage_click' === $name ) {
                            $value = (string) ( $payload['clicks'] ?? '' );
                        }
                        ?>
                        <tr>
                            <td><?php echo esc_html( (string) ( $row['created_at'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( '' === $visitor ? '' : substr( $visitor, 0, 8 ) . '…' ); ?></td>
                            <td><?php echo esc_html( (string) ( $payload['path'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( $value ); ?></td>
                            <?php if ( $with_locator ) : ?>
                                <td><?php echo esc_html( (string) ( $payload['locator'] ?? '' ) ); ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }
}
