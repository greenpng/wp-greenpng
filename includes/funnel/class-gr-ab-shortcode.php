<?php
/**
 * A/B content shortcode (docs/13 C14): splits content by the
 * visitor's assigned variant. Attribute names are the variant slugs
 * of the experiment, so the admin decides the vocabulary once:
 *
 *     [gr_ab experiment="hero" control="Buy now" treatment="Half off"]
 *
 * Fail-open: an unknown or inactive experiment, or a missing variant
 * attribute, renders the control attribute's content when present —
 * a page must never break because of a test definition.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Funnel;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shortcode-driven variant rendering.
 */
final class Gr_Ab_Shortcode {

    /** Shortcode tag. */
    public const TAG = 'gr_ab';

    /**
     * Registers the shortcode.
     *
     * @return void
     */
    public static function register(): void {
        add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
    }

    /**
     * Renders the content of the visitor's variant.
     *
     * The attribute set is open-ended (one attribute per variant slug,
     * decided by the experiment definition), so core's
     * shortcode_atts() cannot mediate here: it keeps only the keys a
     * static defaults array declares. The raw attribute array is
     * parsed directly instead, exactly like other dynamic-attribute
     * shortcodes in the wild.
     *
     * @param array<string, mixed>|string $atts Shortcode attributes.
     * @return string
     */
    public static function render( $atts ): string {
        $atts = (array) $atts;

        $experiment = isset( $atts['experiment'] )
            ? sanitize_key( (string) wp_unslash( $atts['experiment'] ) )
            : '';
        if ( '' === $experiment ) {
            return '';
        }

        $definition = Gr_Ab_Experiments::get( $experiment );
        if ( null === $definition || empty( $definition['active'] ) || ! is_array( $definition['variants'] ) ) {
            // The experiment cannot run: fall back to whatever control
            // content the author supplied, so the page still reads.
            return self::attribute_content( $atts, '' );
        }

        $variant = Gr_Ab_Engine::assign( $experiment, gr()->identity()->visitor_id() );

        return self::attribute_content( $atts, $variant );
    }

    /**
     * Picks the attribute matching the assigned variant; an
     * assignment without a matching attribute degrades to the
     * author's control content.
     *
     * @param array<string, mixed> $atts    Shortcode attributes.
     * @param string               $variant Assigned variant slug.
     * @return string
     */
    private static function attribute_content( array $atts, string $variant ): string {
        $raw = isset( $atts[ $variant ] ) ? (string) $atts[ $variant ] : '';
        if ( '' !== $raw ) {
            return $raw;
        }

        // Control rescue: prefer an attribute that reads as the
        // control, else the first variant attribute the author gave.
        $control = '';
        foreach ( $atts as $key => $value ) {
            if ( 'experiment' === $key ) {
                continue;
            }
            if ( '' === $control || self::is_control_key( (string) $key ) ) {
                $control = (string) $value;
            }
        }

        return $control;
    }

    /**
     * Whether an attribute name reads as the control variant.
     *
     * @param string $key Attribute name.
     * @return bool
     */
    private static function is_control_key( string $key ): bool {
        return 'control' === $key || 'a' === $key;
    }
}
