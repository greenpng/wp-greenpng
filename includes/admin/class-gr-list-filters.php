<?php
/**
 * Shared list controls for the admin tables (docs/12 G6): one date
 * range plus one free-search vocabulary, parsed from GET the same
 * way on every surface, rendered as one fragment each surface wraps
 * in its own form (pages carry their own page-specific filters
 * beside it), and mirrored into the CSV export link so the download
 * honors exactly what the screen shows. Read-only helpers; the
 * surfaces themselves stay owner-gated by their menu entries.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Filter fragment, parser, and export URL builder.
 */
final class Gr_List_Filters {

    /** GET key of the range start. */
    public const ARG_FROM = 'from';

    /** GET key of the range end. */
    public const ARG_TO = 'to';

    /** GET key of the free search. */
    public const ARG_SEARCH = 's';

    /**
     * Reads the shared vocabulary from the request: Y-m-d dates that
     * fail the shape check are dropped, the search is sanitized and
     * clipped. Pages add their own keys on top.
     *
     * @return array{from: string, to: string, s: string}
     */
    public static function parse(): array {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state on owner-gated screens; the repositories re-whitelist every key.
        $from = isset( $_GET[ self::ARG_FROM ] ) ? sanitize_text_field( (string) wp_unslash( $_GET[ self::ARG_FROM ] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state on owner-gated screens.
        $to = isset( $_GET[ self::ARG_TO ] ) ? sanitize_text_field( (string) wp_unslash( $_GET[ self::ARG_TO ] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state on owner-gated screens.
        $s = isset( $_GET[ self::ARG_SEARCH ] ) ? sanitize_text_field( (string) wp_unslash( $_GET[ self::ARG_SEARCH ] ) ) : '';

        return array(
            'from' => self::is_date( $from ) ? $from : '',
            'to'   => self::is_date( $to ) ? $to : '',
            's'    => substr( trim( $s ), 0, 64 ),
        );
    }

    /**
     * The shared filter fragment: labeled date inputs and the search
     * box. No form tags and no submit button — the surface owns the
     * form, its hidden page/tab fields, its page-specific filters,
     * and the button.
     *
     * @param array{from: string, to: string, s: string} $current Current filter values.
     * @return void
     */
    public static function controls( array $current ): void {
        ?>
        <label for="gr-filter-from"><?php echo esc_html__( 'From date', 'greenpng' ); ?></label>
        <input type="date" name="<?php echo esc_attr( self::ARG_FROM ); ?>" id="gr-filter-from" value="<?php echo esc_attr( (string) $current['from'] ); ?>" size="10" />
        <label for="gr-filter-to"><?php echo esc_html__( 'To date', 'greenpng' ); ?></label>
        <input type="date" name="<?php echo esc_attr( self::ARG_TO ); ?>" id="gr-filter-to" value="<?php echo esc_attr( (string) $current['to'] ); ?>" size="10" />
        <label for="gr-filter-search"><?php echo esc_html__( 'Search', 'greenpng' ); ?></label>
        <input type="search" name="<?php echo esc_attr( self::ARG_SEARCH ); ?>" id="gr-filter-search" class="regular-text" value="<?php echo esc_attr( (string) $current['s'] ); ?>" />
        <?php
    }

    /**
     * CSV download URL on the REST export route (REST only — no
     * admin-ajax), carrying the shared filters plus any dataset
     * extras the surface passes. Cookie authentication needs the
     * REST nonce as a query parameter when the browser follows a
     * plain link, so it is embedded in the URL.
     *
     * @param string                $dataset Export dataset key.
     * @param array<string, string> $filters Filter values to mirror.
     * @return string Escaped URL.
     */
    public static function export_url( string $dataset, array $filters = array() ): string {
        $args = array(
            '_wpnonce' => wp_create_nonce( 'wp_rest' ),
        );
        // Core's query composers join values verbatim, so the
        // encoding happens here once, for every filter value.
        foreach ( array( self::ARG_FROM, self::ARG_TO, self::ARG_SEARCH ) as $key ) {
            if ( isset( $filters[ $key ] ) && '' !== (string) $filters[ $key ] ) {
                $args[ $key ] = rawurlencode( (string) $filters[ $key ] );
            }
        }
        foreach ( $filters as $key => $value ) {
            if ( in_array( $key, array( self::ARG_FROM, self::ARG_TO, self::ARG_SEARCH ), true ) ) {
                continue;
            }
            if ( '' !== (string) $value ) {
                $args[ (string) $key ] = rawurlencode( (string) $value );
            }
        }

        return esc_url( add_query_arg( $args, rest_url( 'greenpng/v1/export/' . $dataset ) ) );
    }

    /**
     * Y-m-d shape check; the repositories run their own check on the
     * same vocabulary, so a crafted value never reaches SQL even if
     * it slipped past this gate.
     *
     * @param string $date Candidate.
     * @return bool
     */
    private static function is_date( string $date ): bool {
        return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date );
    }
}
