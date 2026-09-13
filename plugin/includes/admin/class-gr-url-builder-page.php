<?php
/**
 * URL Builder page (docs/13 U9, docs/06 §1 tree): a local UTM link
 * composer. Everything happens in PHP string arithmetic — the page
 * never fetches, pings, or otherwise calls out to validate anything
 * (the acceptance row: zero outbound calls), and the result is
 * printed through esc_url.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UTM link builder surface.
 */
final class Gr_Url_Builder_Page {

    /** Menu slug under the top-level greenpng menu. */
    public const SLUG = 'greenpng-url';

    /**
     * Composes the campaign URL from plain strings; pure — no
     * superglobals, no storage, no network. The landing URL must be
     * an absolute http(s) address; utm_source, utm_medium, and
     * utm_campaign must all be present, because a link missing any
     * of the trio is a link this plugin's own attribution could not
     * read back as a campaign entry.
     *
     * @param string $landing  Absolute landing URL.
     * @param string $source   utm_source value.
     * @param string $medium   utm_medium value.
     * @param string $campaign utm_campaign value.
     * @param string $term     utm_term value, optional.
     * @param string $content  utm_content value, optional.
     * @return array{url: string, error: string} URL on success; on
     *               failure an empty url plus an error key
     *               ('landing' or 'trio').
     */
    public static function build( string $landing, string $source, string $medium, string $campaign, string $term = '', string $content = '' ): array {
        $result = array(
            'url'   => '',
            'error' => '',
        );

        $parts = wp_parse_url( $landing );
        if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] )
            || ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
            $result['error'] = 'landing';

            return $result;
        }

        if ( '' === $source || '' === $medium || '' === $campaign ) {
            $result['error'] = 'trio';

            return $result;
        }

        // Core's add_query_arg composes the query WITHOUT encoding
        // values (build_query passes urlencode=false), so the values
        // are encoded here, at the only layer that knows they are
        // arbitrary admin-typed text.
        $params = array(
            'utm_source'   => rawurlencode( $source ),
            'utm_medium'   => rawurlencode( $medium ),
            'utm_campaign' => rawurlencode( $campaign ),
        );
        if ( '' !== $term ) {
            $params['utm_term'] = rawurlencode( $term );
        }
        if ( '' !== $content ) {
            $params['utm_content'] = rawurlencode( $content );
        }

        $result['url'] = add_query_arg( $params, esc_url_raw( $landing ) );

        return $result;
    }

    /**
     * Page output: the GET form and, when it carries values, the
     * composed link. GET on purpose — building a link changes no
     * state, so the filled form stays shareable and no nonce is
     * involved on a screen already gated by manage_options.
     *
     * @return void
     */
    public static function render(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only form echo on an owner-gated screen; the array cast only shapes the input, every member is sanitized on its own line below.
        $raw      = isset( $_GET['gr_url'] ) ? (array) wp_unslash( $_GET['gr_url'] ) : array();
        $landing  = isset( $raw['landing'] ) ? esc_url_raw( trim( (string) $raw['landing'] ) ) : '';
        $source   = isset( $raw['utm_source'] ) ? sanitize_text_field( (string) $raw['utm_source'] ) : '';
        $medium   = isset( $raw['utm_medium'] ) ? sanitize_text_field( (string) $raw['utm_medium'] ) : '';
        $campaign = isset( $raw['utm_campaign'] ) ? sanitize_text_field( (string) $raw['utm_campaign'] ) : '';
        $term     = isset( $raw['utm_term'] ) ? sanitize_text_field( (string) $raw['utm_term'] ) : '';
        $content  = isset( $raw['utm_content'] ) ? sanitize_text_field( (string) $raw['utm_content'] ) : '';

        $built = array(
            'url'   => '',
            'error' => '',
        );
        if ( '' !== $landing || '' !== $source || '' !== $medium || '' !== $campaign || '' !== $term || '' !== $content ) {
            $built = self::build( $landing, $source, $medium, $campaign, $term, $content );
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'URL Builder', 'greenpng' ); ?></h1>
            <hr class="wp-header-end" />

            <p class="description"><?php echo esc_html__( 'Compose a campaign link with UTM parameters. Everything runs locally in this page; no address is fetched or validated over the network.', 'greenpng' ); ?></p>

            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="gr-url-landing"><?php echo esc_html__( 'Landing URL', 'greenpng' ); ?></label></th>
                        <td>
                            <input type="text" name="gr_url[landing]" id="gr-url-landing" class="regular-text code"
                                value="<?php echo esc_attr( $landing ); ?>" placeholder="https://example.test/landing" />
                            <p class="description"><?php echo esc_html__( 'The absolute http(s) address the campaign lands on.', 'greenpng' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gr-url-source"><?php echo esc_html__( 'Campaign source', 'greenpng' ); ?></label></th>
                        <td><input type="text" name="gr_url[utm_source]" id="gr-url-source" class="regular-text" value="<?php echo esc_attr( $source ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gr-url-medium"><?php echo esc_html__( 'Campaign medium', 'greenpng' ); ?></label></th>
                        <td><input type="text" name="gr_url[utm_medium]" id="gr-url-medium" class="regular-text" value="<?php echo esc_attr( $medium ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gr-url-campaign"><?php echo esc_html__( 'Campaign name', 'greenpng' ); ?></label></th>
                        <td><input type="text" name="gr_url[utm_campaign]" id="gr-url-campaign" class="regular-text" value="<?php echo esc_attr( $campaign ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gr-url-term"><?php echo esc_html__( 'Campaign term', 'greenpng' ); ?></label></th>
                        <td>
                            <input type="text" name="gr_url[utm_term]" id="gr-url-term" class="regular-text" value="<?php echo esc_attr( $term ); ?>" />
                            <p class="description"><?php echo esc_html__( 'Optional.', 'greenpng' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gr-url-content"><?php echo esc_html__( 'Campaign content', 'greenpng' ); ?></label></th>
                        <td>
                            <input type="text" name="gr_url[utm_content]" id="gr-url-content" class="regular-text" value="<?php echo esc_attr( $content ); ?>" />
                            <p class="description"><?php echo esc_html__( 'Optional.', 'greenpng' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Build link', 'greenpng' ) ); ?>
            </form>

            <?php if ( 'trio' === $built['error'] ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html__( 'A campaign link needs a landing URL plus source, medium, and campaign name.', 'greenpng' ); ?></p></div>
            <?php elseif ( 'landing' === $built['error'] ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html__( 'The landing URL must be an absolute http(s) address.', 'greenpng' ); ?></p></div>
            <?php elseif ( '' !== $built['url'] ) : ?>
                <h2><?php echo esc_html__( 'Your campaign link', 'greenpng' ); ?></h2>
                <p><code><?php echo esc_url( $built['url'] ); ?></code></p>
                <p>
                    <input type="text" readonly class="large-text code"
                        value="<?php echo esc_attr( $built['url'] ); ?>"
                        aria-label="<?php echo esc_attr( __( 'Campaign link, ready to copy', 'greenpng' ) ); ?>" />
                </p>
                <p class="description"><?php echo esc_html__( 'Copy the link into your ad or newsletter; visits landing on it are attributed to this campaign.', 'greenpng' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }
}
