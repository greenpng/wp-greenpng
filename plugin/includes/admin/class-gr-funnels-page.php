<?php
/**
 * Funnels & Goals page (docs/06 §1 tree, docs/13 U17): the v1.0
 * window is the A/B experiments tab — every experiment with its
 * assignment split preview and its two-proportion Z-test verdict,
 * read straight from the engines that already own those answers.
 * Funnels, step loss, and goals are a v1.1 delivery; this page does
 * not preview them. Read-only throughout: definitions are not
 * authored here, so there is no form, no nonce, and no write arm.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Funnel\Gr_Ab_Engine;
use GreenPNG\Funnel\Gr_Ab_Experiments;
use GreenPNG\Funnel\Gr_Ab_Significance;

/**
 * A/B experiment reporting surface.
 */
final class Gr_Funnels_Page {

    /** Menu slug under the top-level menu. */
    public const SLUG = 'greenpng-funnels';

    /** Sample visitor ids for the assignment preview. */
    public const PREVIEW_IDS = array(
        'preview-01',
        'preview-02',
        'preview-03',
        'preview-04',
        'preview-05',
        'preview-06',
        'preview-07',
        'preview-08',
    );

    /**
     * Page output. No write arms: everything here is a read over the
     * experiment definitions and the event aggregation.
     *
     * @return void
     */
    public static function render(): void {
        $experiments = Gr_Ab_Experiments::all();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Funnels & Goals', 'greenpng' ); ?></h1>
            <hr class="wp-header-end" />

            <p><?php echo esc_html__( 'A/B experiments: the variant split each visitor lands in, and the significance verdict over what was actually recorded. Funnel, step-loss, and goal tracking arrive in a later version.', 'greenpng' ); ?></p>

            <?php if ( array() === $experiments ) : ?>
                <p><?php echo esc_html__( 'No experiments defined yet. Experiment definitions are created through the experiments repository (the gr_ab_experiments option); content then splits per visitor with the [gr_ab] shortcode. This page reports on them and creates nothing.', 'greenpng' ); ?></p>
            <?php else : ?>
                <?php foreach ( $experiments as $key => $definition ) : ?>
                    <?php self::render_experiment( (string) $key, $definition ); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * One experiment block: state, variants, split preview, counts,
     * and the Z-test verdict.
     *
     * @param string               $key        Experiment slug.
     * @param array<string, mixed> $definition active + variants (control first).
     * @return void
     */
    private static function render_experiment( string $key, array $definition ): void {
        $active   = ! empty( $definition['active'] );
        $variants = isset( $definition['variants'] ) && is_array( $definition['variants'] )
            ? $definition['variants']
            : array();
        $result   = Gr_Ab_Significance::calculate( $key );
        ?>
        <h2>
            <?php
            echo esc_html(
                sprintf(
                    /* translators: 1: experiment key, 2: state word. */
                    __( 'Experiment: %1$s (%2$s)', 'greenpng' ),
                    $key,
                    $active ? __( 'running', 'greenpng' ) : __( 'paused', 'greenpng' )
                )
            );
            ?>
        </h2>

        <p>
            <?php
            echo esc_html(
                sprintf(
                    /* translators: %s: comma-separated variant slugs. */
                    __( 'Variants (first is control): %s', 'greenpng' ),
                    implode( ', ', array_map( 'strval', $variants ) )
                )
            );
            ?>
        </p>

        <?php if ( ! $active ) : ?>
            <p><?php echo esc_html__( 'Assignment is paused: the shortcode falls back to control content and the engine assigns nothing. Recorded data stays readable below.', 'greenpng' ); ?></p>
        <?php else : ?>
            <?php self::render_preview( $key, $variants ); ?>
        <?php endif; ?>

        <?php self::render_results( $result ); ?>
        <?php
    }

    /**
     * Assignment split preview over fixed sample visitor ids: the pure
     * consistent-hash answer for each, so the owner can see how the
     * engine spreads visitors before any traffic arrives. The engine's
     * pure seam is used directly — the URL force parameter belongs to
     * the person browsing this page, not to the previewed visitors.
     *
     * @param string             $key      Experiment slug.
     * @param array<int, string> $variants Declared variant slugs.
     * @return void
     */
    private static function render_preview( string $key, array $variants ): void {
        $tally = array_fill_keys( $variants, 0 );
        ?>
        <h3><?php echo esc_html__( 'Assignment split preview', 'greenpng' ); ?></h3>
        <p><?php echo esc_html__( 'The same visitor always lands in the same variant on every request; the preview below is the pure hash split for eight fixed sample ids.', 'greenpng' ); ?></p>
        <table class="widefat striped">
            <thead><tr>
                <th><?php echo esc_html__( 'Sample visitor', 'greenpng' ); ?></th>
                <th><?php echo esc_html__( 'Assigned variant', 'greenpng' ); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ( self::PREVIEW_IDS as $sample ) : ?>
                    <?php
                    $variant = Gr_Ab_Engine::pick_variant( $key, $sample, $variants );
                    if ( array_key_exists( $variant, $tally ) ) {
                        ++$tally[ $variant ];
                    }
                    ?>
                    <tr>
                        <td><?php echo esc_html( $sample ); ?></td>
                        <td><?php echo esc_html( $variant ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p>
            <?php
            echo esc_html(
                sprintf(
                    /* translators: %s: comma-separated variant=count pairs. */
                    __( 'Sample tally: %s', 'greenpng' ),
                    implode(
                        ', ',
                        array_map(
                            static function ( string $variant ) use ( $tally ): string {
                                return $variant . '=' . (int) $tally[ $variant ];
                            },
                            $variants
                        )
                    )
                )
            );
            ?>
        </p>
        <?php
    }

    /**
     * Recorded counts and the significance verdict, straight from the
     * Z-test calculation.
     *
     * @param array<string, mixed> $result Gr_Ab_Significance::calculate output.
     * @return void
     */
    private static function render_results( array $result ): void {
        $status  = (string) $result['status'];
        $state   = array(
            'insufficient' => __( 'Insufficient sample: each arm needs at least 30 impressions before the comparison is readable.', 'greenpng' ),
            'inconclusive' => __( 'Inconclusive: no difference proven at 95% confidence yet.', 'greenpng' ),
            'significant'  => __( 'Significant at 95% confidence.', 'greenpng' ),
        );
        $message = isset( $state[ $status ] ) ? $state[ $status ] : $state['insufficient'];
        ?>
        <h3><?php echo esc_html__( 'Recorded counts and significance', 'greenpng' ); ?></h3>
        <table class="widefat striped">
            <thead><tr>
                <th><?php echo esc_html__( 'Variant', 'greenpng' ); ?></th>
                <th><?php echo esc_html__( 'Impressions', 'greenpng' ); ?></th>
                <th><?php echo esc_html__( 'Conversions', 'greenpng' ); ?></th>
                <th><?php echo esc_html__( 'CVR', 'greenpng' ); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ( (array) $result['variants'] as $variant => $arm ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $variant ); ?></td>
                        <td><?php echo esc_html( number_format( (float) $arm['impressions'] ) ); ?></td>
                        <td><?php echo esc_html( number_format( (float) $arm['conversions'] ) ); ?></td>
                        <td>
                            <?php
                            echo esc_html(
                                sprintf(
                                    /* translators: %s: percentage. */
                                    __( '%s%%', 'greenpng' ),
                                    number_format( (float) $arm['cvr'] * 100, 2 )
                                )
                            );
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p><?php echo esc_html( $message ); ?></p>
        <?php if ( array() !== (array) $result['pairs'] ) : ?>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php echo esc_html__( 'Comparison (control vs)', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'z', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'State', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Winner', 'greenpng' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ( (array) $result['pairs'] as $pair ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) $pair['variant'] ); ?></td>
                            <td><?php echo esc_html( number_format( (float) $pair['z'], 3 ) ); ?></td>
                            <td><?php echo esc_html( (string) $pair['state'] ); ?></td>
                            <td><?php echo esc_html( '' === (string) $pair['winner'] || 'tie' === (string) $pair['winner'] ? '—' : (string) $pair['winner'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        endif;
    }
}
