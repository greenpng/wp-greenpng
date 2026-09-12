<?php
/**
 * Dashboard page (docs/13 U3, docs/06 §1/§2.3): KPI strip, trend
 * chart, and country distribution — every number read from
 * gr_daily_stats, never from a raw table. The trend chart is a
 * progressive enhancement: the server also renders a
 * screen-reader-text table with the same data, so the page is
 * complete with JavaScript off.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Storage\Gr_Daily_Stats_Repository;

/**
 * Renders the dashboard; no capability logic here — the menu slug's
 * capability gate already did that on every entry path.
 */
final class Gr_Dashboard_Page {

    /** Trend window in days. */
    public const TREND_DAYS = 14;

    /** Country-distribution window in days. */
    public const DIMENSION_DAYS = 30;

    /** Chart mount id; the dashboard script binds to it. */
    public const TREND_MOUNT = 'gr-dashboard-trend';

    /**
     * Page output.
     *
     * @return void
     */
    public static function render(): void {
        $repo      = new Gr_Daily_Stats_Repository();
        $series    = $repo->series( self::TREND_DAYS );
        $countries = $repo->dimension( 'sessions_by_country', self::DIMENSION_DAYS, 10 );
        $today     = array();
        if ( array() !== $series ) {
            $today = (array) end( $series );
        }
        $kpis = array(
            array( __( 'Sessions today', 'greenpng' ), self::num( $today['sessions'] ?? 0 ) ),
            array( __( 'Visitors today', 'greenpng' ), self::num( $today['visitors'] ?? 0 ) ),
            array( __( 'Page views today', 'greenpng' ), self::num( $today['pageviews'] ?? 0 ) ),
            array( __( 'Conversions today', 'greenpng' ), self::num( $today['conversions'] ?? 0 ) ),
            array( __( 'Revenue today', 'greenpng' ), self::num( $today['revenue'] ?? 0, 2 ) ),
        );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Dashboard', 'greenpng' ); ?></h1>
            <hr class="wp-header-end" />

            <div class="gr-kpi-grid">
                <?php foreach ( $kpis as $kpi ) : ?>
                    <div class="postbox">
                        <h2 class="hndle"><?php echo esc_html( $kpi[0] ); ?></h2>
                        <div class="inside"><span class="gr-kpi-value"><?php echo esc_html( $kpi[1] ); ?></span></div>
                    </div>
                    <?php endforeach; ?>
            </div>

            <h2><?php echo esc_html__( 'Sessions and page views, last 14 days', 'greenpng' ); ?></h2>
            <div id="<?php echo esc_attr( self::TREND_MOUNT ); ?>" class="gr-chart"></div>

            <table class="screen-reader-text">
                <caption><?php echo esc_html__( 'Daily figures, last 14 days', 'greenpng' ); ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?php echo esc_html__( 'Date', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Sessions', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Visitors', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Page views', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Conversions', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Revenue', 'greenpng' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $series as $day => $row ) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html( (string) $day ); ?></th>
                            <td><?php echo esc_html( self::num( $row['sessions'] ) ); ?></td>
                            <td><?php echo esc_html( self::num( $row['visitors'] ) ); ?></td>
                            <td><?php echo esc_html( self::num( $row['pageviews'] ) ); ?></td>
                            <td><?php echo esc_html( self::num( $row['conversions'] ) ); ?></td>
                            <td><?php echo esc_html( self::num( $row['revenue'], 2 ) ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                </tbody>
            </table>

            <h2><?php echo esc_html__( 'Visitors by country, last 30 days', 'greenpng' ); ?></h2>
            <?php if ( array() === $countries ) : ?>
                <p><?php echo esc_html__( 'No country data yet.', 'greenpng' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th scope="col"><?php echo esc_html__( 'Country', 'greenpng' ); ?></th>
                            <th scope="col"><?php echo esc_html__( 'Sessions', 'greenpng' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $countries as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( self::country_label( (string) $row['key'] ) ); ?></td>
                                <td><?php echo esc_html( self::num( $row['value'] ) ); ?></td>
                            </tr>
                            <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Number formatting; decimals only where cents matter.
     *
     * @param int|float $value   Value.
     * @param int       $decimals Decimal places.
     * @return string
     */
    private static function num( $value, int $decimals = 0 ): string {
        return number_format( (float) $value, $decimals );
    }

    /**
     * Country code display; the empty code is the unknown bucket
     * (GeoIP fills codes from the IP Intelligence task on).
     *
     * @param string $code Country code.
     * @return string
     */
    private static function country_label( string $code ): string {
        if ( '' === $code ) {
            return __( 'Unknown', 'greenpng' );
        }

        return $code;
    }
}
