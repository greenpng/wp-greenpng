<?php
/**
 * Consent gate for the marketing track (ADR-0005 §1): one checkpoint that
 * every marketing-path feature consults. Integrates the WP Consent API
 * when the host provides it; without a CMP the plugin's own settings
 * toggle decides, defaulting to off. An explicit browser do-not-track
 * signal beats both, because it is the visitor's own statement.
 *
 * The security track never passes through here; it runs on legitimate
 * interest (ADR-0007 dual-rail).
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Privacy;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Purpose-scoped consent decisions for marketing data.
 */
final class Gr_Consent {

    /**
     * Whether the given tracking purpose is currently allowed.
     *
     * @param string $purpose Purpose key, 'marketing' in v1.0.
     * @return bool
     */
    public static function allows( string $purpose ): bool {
        if ( 'marketing' === $purpose && self::browser_signals_do_not_track() ) {
            return false;
        }

        if ( function_exists( 'wp_has_consent' ) ) {
            return (bool) wp_has_consent( $purpose );
        }

        // No Consent API on this host (below the API's core merge): the
        // site owner's plugin toggle stands in, defaulting to off
        // (ADR-0005 §1).
        return 1 === (int) gr()->settings()->get( 'marketing_consent_fallback' );
    }

    /**
     * DNT and Sec-GPC are the visitor's own signals, so they override any
     * stored consent decision (ADR-0005 §5).
     *
     * @return bool
     */
    private static function browser_signals_do_not_track(): bool {
        $dnt = isset( $_SERVER['HTTP_DNT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_DNT'] ) ) : '';
        $gpc = isset( $_SERVER['HTTP_SEC_GPC'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) ) : '';

        return '1' === $dnt || '1' === $gpc;
    }
}
