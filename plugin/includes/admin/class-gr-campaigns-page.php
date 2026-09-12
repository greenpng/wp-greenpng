<?php
/**
 * Campaigns page (docs/13 U8, docs/06 §1 tree): four read-only tabs
 * over the attribution tables — campaign volume, the UTM tuple
 * breakdown, click-id carriers, and the five-model comparison. The
 * comparison reads the split recorded with each conversion binding
 * (the permanent snapshot), so credit never shifts when later
 * touches arrive; and every number on the page is computed from
 * recorded rows, never a demonstration value.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Storage\Gr_Conversion_Repository;
use GreenPNG\Storage\Gr_Touchpoint_Repository;

/**
 * Campaigns & attribution reporting surface.
 */
final class Gr_Campaigns_Page {

    /** Menu slug under the top-level greenpng menu. */
    public const SLUG = 'greenpng-campaigns';

    /** Campaign volume tab key. */
    public const TAB_CAMPAIGNS = 'campaigns';

    /** UTM tuple tab key. */
    public const TAB_UTM = 'utm';

    /** Click-id tab key. */
    public const TAB_CLICKIDS = 'clickids';

    /** Attribution comparison tab key. */
    public const TAB_MODELS = 'models';

    /**
     * Read window: the cookie window the attribution chain itself
     * uses, so the reports and the models speak about the same past.
     *
     * @var int
     */
    private const WINDOW_DAYS = 30;

    /**
     * Model column order, matching the calculate() vocabulary.
     *
     * @var array<int, string>
     */
    private const MODELS = array( 'first', 'last', 'linear', 'position', 'time_decay' );

    /**
     * Page output: tab bar plus the active tab's table.
     *
     * @return void
     */
    public static function render(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch on an owner-gated screen.
        $tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : self::TAB_CAMPAIGNS;
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Campaigns', 'greenpng' ); ?></h1>
            <hr class="wp-header-end" />

            <nav class="nav-tab-wrapper">
                <?php
                $tabs = array(
                    self::TAB_CAMPAIGNS => __( 'Campaigns', 'greenpng' ),
                    self::TAB_UTM       => __( 'UTM parameters', 'greenpng' ),
                    self::TAB_CLICKIDS  => __( 'Click IDs', 'greenpng' ),
                    self::TAB_MODELS    => __( 'Attribution models', 'greenpng' ),
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

            <?php if ( self::TAB_UTM === $tab ) : ?>
                <?php self::render_utm(); ?>
            <?php elseif ( self::TAB_CLICKIDS === $tab ) : ?>
                <?php self::render_clickids(); ?>
            <?php elseif ( self::TAB_MODELS === $tab ) : ?>
                <?php self::render_models(); ?>
            <?php else : ?>
                <?php self::render_campaigns(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Campaign volume: named campaigns and their carriers.
     *
     * @return void
     */
    private static function render_campaigns(): void {
        $rows = ( new Gr_Touchpoint_Repository() )->campaign_breakdown( self::WINDOW_DAYS, 30 );
        ?>
        <h2>
        <?php
        echo esc_html(
            sprintf(
                /* translators: %d: number of days. */
                __( 'Campaign entries in the last %d days', 'greenpng' ),
                self::WINDOW_DAYS
            )
        );
        ?>
        </h2>
        <?php if ( array() === $rows ) : ?>
            <p><?php echo esc_html__( 'No campaign entries recorded yet. A row appears when a visit lands with a utm_campaign parameter.', 'greenpng' ); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col"><?php echo esc_html__( 'Campaign', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Channel', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Source', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Medium', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Entries', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Visitors', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'First seen', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Last seen', 'greenpng' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) ( $row['utm_campaign'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['channel'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['utm_source'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['utm_medium'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['entries'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['visitors'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['first_seen'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['last_seen'] ?? '' ) ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description"><?php echo esc_html__( 'Entries are campaign landings, not pageviews; a visitor counted once per campaign row.', 'greenpng' ); ?></p>
            <?php
        endif;
    }

    /**
     * UTM tuple breakdown: every parameter combination that carried
     * at least one parameter.
     *
     * @return void
     */
    private static function render_utm(): void {
        $rows = ( new Gr_Touchpoint_Repository() )->utm_breakdown( self::WINDOW_DAYS, 30 );
        ?>
        <h2>
        <?php
        echo esc_html(
            sprintf(
                /* translators: %d: number of days. */
                __( 'UTM parameter combinations in the last %d days', 'greenpng' ),
                self::WINDOW_DAYS
            )
        );
        ?>
        </h2>
        <?php if ( array() === $rows ) : ?>
            <p><?php echo esc_html__( 'No UTM parameters recorded yet.', 'greenpng' ); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col"><?php echo esc_html__( 'Source', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Medium', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Campaign', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Term', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Content', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Entries', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Visitors', 'greenpng' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( self::cell( $row, 'utm_source' ) ); ?></td>
                            <td><?php echo esc_html( self::cell( $row, 'utm_medium' ) ); ?></td>
                            <td><?php echo esc_html( self::cell( $row, 'utm_campaign' ) ); ?></td>
                            <td><?php echo esc_html( self::cell( $row, 'utm_term' ) ); ?></td>
                            <td><?php echo esc_html( self::cell( $row, 'utm_content' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['entries'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['visitors'] ?? '' ) ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        endif;
    }

    /**
     * Click-id carriers with the channel each produced.
     *
     * @return void
     */
    private static function render_clickids(): void {
        $rows = ( new Gr_Touchpoint_Repository() )->click_id_breakdown( self::WINDOW_DAYS, 30 );
        ?>
        <h2>
        <?php
        echo esc_html(
            sprintf(
                /* translators: %d: number of days. */
                __( 'Click IDs in the last %d days', 'greenpng' ),
                self::WINDOW_DAYS
            )
        );
        ?>
        </h2>
        <?php if ( array() === $rows ) : ?>
            <p><?php echo esc_html__( 'No click IDs recorded yet. A row appears when a visit lands with gclid, fbclid, or another click parameter.', 'greenpng' ); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col"><?php echo esc_html__( 'Click ID', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Channel', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Entries', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Visitors', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Last seen', 'greenpng' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) ( $row['click_id'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['channel'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['entries'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['visitors'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['last_seen'] ?? '' ) ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        endif;
    }

    /**
     * The five-model comparison: rows are campaigns (plus currency),
     * columns are the models, cells are the credit each model gave
     * that campaign across recorded conversions. The split is the
     * snapshot bound with the conversion, so a campaign's credit here
     * is what the models actually computed at conversion time — the
     * page recomputes nothing and invents nothing.
     *
     * @return void
     */
    private static function render_models(): void {
        $conversions = ( new Gr_Conversion_Repository() )->recent( self::WINDOW_DAYS, 200 );

        ?>
        <h2><?php echo esc_html__( 'Model comparison over recorded conversions', 'greenpng' ); ?></h2>
        <?php if ( array() === $conversions ) : ?>
            <p><?php echo esc_html__( 'No conversions recorded yet. The comparison fills from real orders and form conversions as they arrive.', 'greenpng' ); ?></p>
            <?php
            return;
        endif;

        // Resolve the touchpoint ids the stored splits name.
        $ids = array();
        foreach ( $conversions as $conversion ) {
            $split = self::decode_split( (string) ( $conversion['model_weights'] ?? '' ) );
            foreach ( self::MODELS as $model ) {
                foreach ( array_keys( $split[ $model ] ?? array() ) as $id ) {
                    $ids[] = (int) $id;
                }
            }
        }
        $labels = ( new Gr_Touchpoint_Repository() )->campaigns_for_ids( $ids );

        // Aggregate: model => campaign|currency => credited amount.
        $credited = array();
        $totals   = array();
        $direct   = 0;
        foreach ( $conversions as $conversion ) {
            $split    = self::decode_split( (string) ( $conversion['model_weights'] ?? '' ) );
            $currency = (string) ( $conversion['currency'] ?? '' );
            $touched  = false;

            foreach ( self::MODELS as $model ) {
                foreach ( $split[ $model ] ?? array() as $id => $cell ) {
                    $amount = (float) ( $cell['amount'] ?? 0 );
                    if ( $amount <= 0 ) {
                        continue;
                    }
                    $campaign                   = self::campaign_label( (int) $id, $labels );
                    $key                        = $campaign . '|' . $currency;
                    $credited[ $model ][ $key ] = ( $credited[ $model ][ $key ] ?? 0.0 ) + $amount;
                    $totals[ $model ]           = ( $totals[ $model ] ?? 0.0 ) + $amount;
                    $touched                    = true;
                }
            }

            if ( ! $touched ) {
                ++$direct;
            }
        }

        $keys = array();
        foreach ( self::MODELS as $model ) {
            foreach ( array_keys( $credited[ $model ] ?? array() ) as $key ) {
                $keys[ $key ] = true;
            }
        }
        $keys = array_keys( $keys );
        usort(
            $keys,
            static function ( string $a, string $b ) use ( $credited ): int {
                $sum = static function ( string $key ) use ( $credited ): float {
                    $total = 0.0;
                    foreach ( $credited as $map ) {
                        $total += (float) ( $map[ $key ] ?? 0.0 );
                    }

                    return $total;
                };

                return $sum( $b ) <=> $sum( $a );
            }
        );
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th scope="col"><?php echo esc_html__( 'Campaign', 'greenpng' ); ?></th>
                    <th scope="col"><?php echo esc_html__( 'Currency', 'greenpng' ); ?></th>
                    <?php foreach ( self::model_labels() as $label ) : ?>
                        <th scope="col"><?php echo esc_html( (string) $label ); ?></th>
                        <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $keys as $key ) : ?>
                    <?php list($campaign, $currency) = explode( '|', $key, 2 ); ?>
                    <tr>
                        <td><?php echo esc_html( $campaign ); ?></td>
                        <td><?php echo esc_html( $currency ); ?></td>
                        <?php foreach ( self::MODELS as $model ) : ?>
                            <?php $amount = (float) ( $credited[ $model ][ $key ] ?? 0.0 ); ?>
                            <td><?php echo esc_html( self::amount_cell( $amount, (float) ( $totals[ $model ] ?? 0.0 ) ) ); ?></td>
                            <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description"><?php echo esc_html__( 'Each cell is the credit the model gave that campaign across recorded conversions; every model column sums to the same total conversion amount.', 'greenpng' ); ?></p>
        <?php if ( $direct > 0 ) : ?>
            <p>
            <?php
            printf(
                /* translators: %s: number of conversions. */
                esc_html__( '%s conversion(s) arrived without campaign touchpoints and carry no model credit.', 'greenpng' ),
                esc_html( (string) $direct )
            );
            ?>
            </p>
            <?php
        endif;
    }

    /**
     * Model column labels in the fixed column order.
     *
     * @return array<int, string>
     */
    private static function model_labels(): array {
        return array(
            __( 'First touch', 'greenpng' ),
            __( 'Last touch', 'greenpng' ),
            __( 'Linear', 'greenpng' ),
            __( 'Position-based', 'greenpng' ),
            __( 'Time-decay', 'greenpng' ),
        );
    }

    /**
     * Stored split as an array; unreadable payloads read as empty.
     *
     * @param string $json The model_weights column.
     * @return array<string, mixed>
     */
    private static function decode_split( string $json ): array {
        if ( '' === $json ) {
            return array();
        }

        $decoded = json_decode( $json, true );

        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * Campaign label for one credited touchpoint: the recorded
     * campaign, or "(no campaign)" when the touch carried none, or
     * "(deleted touchpoint)" when retention already removed the row
     * the split names.
     *
     * @param int                                                                 $id     Touchpoint id.
     * @param array<int, array{campaign: string, source: string, medium: string}> $labels Resolved labels.
     * @return string
     */
    private static function campaign_label( int $id, array $labels ): string {
        $campaign = $labels[ $id ]['campaign'] ?? null;
        if ( null === $campaign ) {
            return __( '(deleted touchpoint)', 'greenpng' );
        }

        if ( '' === $campaign ) {
            return __( '(no campaign name)', 'greenpng' );
        }

        return $campaign;
    }

    /**
     * Amount cell with its share of the model total; zero credit is
     * a computed zero, stated as such.
     *
     * @param float $amount Credited amount.
     * @param float $total  Model total.
     * @return string
     */
    private static function amount_cell( float $amount, float $total ): string {
        if ( $total > 0 ) {
            return sprintf( '%s (%.1f%%)', self::money( $amount ), $amount / $total * 100.0 );
        }

        return self::money( $amount );
    }

    /**
     * Two-decimal amount, thousands-unseparated so the value stays
     * copy-safe in any locale.
     *
     * @param float $amount Amount.
     * @return string
     */
    private static function money( float $amount ): string {
        return number_format( $amount, 2, '.', '' );
    }

    /**
     * Table cell text: empty parameter values read as an em dash
     * placeholder so an empty tuple cell is visibly empty.
     *
     * @param array<string, string|int> $row     Row.
     * @param string                    $column  Column key.
     * @return string
     */
    private static function cell( array $row, string $column ): string {
        $value = (string) ( $row[ $column ] ?? '' );

        return '' === $value ? '—' : $value;
    }
}
