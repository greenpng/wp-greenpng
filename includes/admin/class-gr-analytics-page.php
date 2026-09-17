<?php
/**
 * Analytics & CAPI page (docs/06 Integrations first entry, docs/13 U15,
 * docs/07 §5): Meta CAPI, GA4 Measurement Protocol, the
 * self-hosted Matomo instance, and the TikTok Events API, all
 * default off and strictly opt-in.
 * Credentials are only ever stored through Gr_Secrets (AES-256-GCM,
 * autoload=no options) and never rendered back in plaintext — the
 * page shows the real state ("not configured" is an explicit state,
 * not a blank) plus a masked preview. The Matomo site id is a plain
 * number, not a credential, so its field shows the stored value.
 *
 * The local self-check tests decryption and shape on what is
 * actually stored. The GA4 block additionally offers the outbound
 * connectivity probe: one owner-clicked validation request to
 * Google's debug endpoint, executed by the GA4 service class through
 * Http_Client — this page still makes no network call of its own.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Ga4_Mp;
use GreenPNG\Core\Gr_Secrets;
use GreenPNG\Core\Gr_Settings;
use GreenPNG\Storage\Gr_Audit_Repository;

/**
 * Credential entry and opt-in management for the outbound analytics.
 */
final class Gr_Analytics_Page {

    /** Menu slug under the top-level menu, Integrations section. */
    public const SLUG = 'greenpng-analytics';

    /** Nonce action for every write arm. */
    public const NONCE_ACTION = 'gr-analytics-save';

    /** Nonce field name. */
    public const NONCE_FIELD = '_gr_analytics_nonce';

    /** POST action: save the Meta block. */
    public const ACTION_SAVE_META = 'save_meta';

    /** POST action: save the GA4 block. */
    public const ACTION_SAVE_GA4 = 'save_ga4';

    /** POST action: local self-check of the Meta block. */
    public const ACTION_CHECK_META = 'check_meta';

    /** POST action: local self-check of the GA4 block. */
    public const ACTION_CHECK_GA4 = 'check_ga4';

    /** POST action: owner-clicked GA4 debug-endpoint connectivity probe. */
    public const ACTION_DEBUG_GA4 = 'debug_ga4';

    /** POST action: save the Matomo block. */
    public const ACTION_SAVE_MATOMO = 'save_matomo';

    /** POST action: local self-check of the Matomo block. */
    public const ACTION_CHECK_MATOMO = 'check_matomo';

    /** POST action: save the TikTok block. */
    public const ACTION_SAVE_TIKTOK = 'save_tiktok';

    /** POST action: local self-check of the TikTok block. */
    public const ACTION_CHECK_TIKTOK = 'check_tiktok';

    /** Secret option names, owned by Gr_Secrets; the page only mirrors them. */
    public const META_PIXEL_OPTION   = Gr_Secrets::META_PIXEL_OPTION;
    public const META_TOKEN_OPTION   = Gr_Secrets::META_TOKEN_OPTION;
    public const GA4_ID_OPTION       = Gr_Secrets::GA4_ID_OPTION;
    public const GA4_SECRET_OPTION   = Gr_Secrets::GA4_SECRET_OPTION;
    public const MATOMO_URL_OPTION   = Gr_Secrets::MATOMO_URL_OPTION;
    public const MATOMO_TOKEN_OPTION = Gr_Secrets::MATOMO_TOKEN_OPTION;

    /**
     * Hook registration; the write arms ride admin_init like every
     * other admin page.
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
    }

    /**
     * Dispatches the POST arms. All four share one nonce: they are
     * one page's buttons, and the gate is the page, not the button.
     *
     * @return void
     */
    public static function handle_actions(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce gate below is the real check; this line only routes the request.
        $action = isset( $_POST['gr_analytics_action'] ) ? sanitize_key( (string) wp_unslash( $_POST['gr_analytics_action'] ) ) : '';
        if ( '' === $action ) {
            return;
        }

        if ( ! self::may_write() ) {
            return;
        }

        switch ( $action ) {
            case self::ACTION_SAVE_META:
                self::save_service( 'meta' );
                break;
            case self::ACTION_SAVE_GA4:
                self::save_service( 'ga4' );
                break;
            case self::ACTION_SAVE_MATOMO:
                self::save_matomo();
                break;
            case self::ACTION_SAVE_TIKTOK:
                self::save_tiktok();
                break;
            case self::ACTION_CHECK_META:
            case self::ACTION_CHECK_GA4:
                self::check_service( self::ACTION_CHECK_META === $action ? 'meta' : 'ga4' );
                break;
            case self::ACTION_CHECK_MATOMO:
                wp_safe_redirect(
                    self::page_url(
                        array(
                            'gr_check'  => 'matomo',
                            'gr_result' => self::local_check( 'matomo' ),
                        )
                    )
                );
                break;
            case self::ACTION_CHECK_TIKTOK:
                wp_safe_redirect(
                    self::page_url(
                        array(
                            'gr_check'  => 'tiktok',
                            'gr_result' => self::local_check( 'tiktok' ),
                        )
                    )
                );
                break;
            case self::ACTION_DEBUG_GA4:
                self::debug_ga4();
                break;
        }
    }

    /**
     * Saves one service block: the opt-in toggle into gr_settings and
     * the credential pair into Gr_Secrets. An empty field means
     * "unchanged" — saving the form without retyping a secret must
     * never blank it; the remove checkbox is the only way out, and it
     * takes the toggle down with the credentials. Invalid shapes are
     * refused whole: nothing is stored, the error flag names the
     * field. The redirect ends the request in production; the test
     * stub records it and returns.
     *
     * @param string $service 'meta' or 'ga4'.
     * @return void
     */
    private static function save_service( string $service ): void {
        $settings   = new Gr_Settings();
        $toggle_key = 'meta' === $service ? 'capi_meta_enabled' : 'capi_ga4_enabled';
        $remove_key = 'meta' === $service ? 'meta_remove' : 'ga4_remove';

        $before = self::service_snapshot( $service );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); the remove flag is a strict '1' comparison.
        $remove = isset( $_POST[ $remove_key ] ) && '1' === (string) wp_unslash( $_POST[ $remove_key ] );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); the values are shape-checked below before any store().
        $id_field = isset( $_POST[ $service . '_id' ] ) ? trim( (string) wp_unslash( $_POST[ $service . '_id' ] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); the values are shape-checked below before any store().
        $secret_field = isset( $_POST[ $service . '_secret' ] ) ? trim( (string) wp_unslash( $_POST[ $service . '_secret' ] ) ) : '';

        if ( $remove ) {
            Gr_Secrets::forget( self::id_option( $service ) );
            Gr_Secrets::forget( self::secret_option( $service ) );
            $settings->set( $toggle_key, 0 );
        } else {
            if ( '' !== $id_field || '' !== $secret_field ) {
                // Half-entered pairs are refused whole: a lone pixel id
                // with no token would look configured to the reader.
                if ( '' === $id_field || '' === $secret_field ) {
                    wp_safe_redirect( self::page_url( array( 'gr_error' => $service . '_pair' ) ) );

                    return;
                }
                if ( ! self::valid_id( $service, $id_field ) ) {
                    wp_safe_redirect( self::page_url( array( 'gr_error' => $service . '_id' ) ) );

                    return;
                }
                if ( ! self::valid_secret( $service, $secret_field ) ) {
                    wp_safe_redirect( self::page_url( array( 'gr_error' => $service . '_secret' ) ) );

                    return;
                }

                Gr_Secrets::store( self::id_option( $service ), $id_field );
                Gr_Secrets::store( self::secret_option( $service ), $secret_field );
            }

            $settings->set( $toggle_key, self::checkbox( $toggle_key ) );
        }

        $after = self::service_snapshot( $service );

        // The audit trail records the state words, never the values:
        // the diff says what happened to a credential, not what it is.
        if ( $before !== $after ) {
            ( new Gr_Audit_Repository() )->log(
                'save',
                'analytics',
                $service,
                $before,
                $after,
                get_current_user_id()
            );
        }

        wp_safe_redirect( self::page_url( array( 'gr_saved' => $service ) ) );
    }

    /**
     * Local self-check of one service block: does the stored pair
     * decrypt back, and does it hold the expected shape. Reports
     * words, never values; the outbound probe is a later delivery.
     *
     * @param string $service 'meta' or 'ga4'.
     * @return void
     */
    private static function check_service( string $service ): void {
        wp_safe_redirect(
            self::page_url(
                array(
                    'gr_check'  => $service,
                    'gr_result' => self::local_check( $service ),
                )
            )
        );
    }

    /**
     * Saves the Matomo block: the opt-in toggle into gr_settings, the
     * instance URL and token_auth into Gr_Secrets, and the site id —
     * a plain number, not a credential — into gr_settings. An empty
     * secret field means "unchanged"; the remove switch forgets all
     * three and takes the toggle down with them. Half-entered pairs
     * are refused whole, and the audit diff carries state words
     * only, never the instance URL or the token.
     *
     * @return void
     */
    private static function save_matomo(): void {
        $settings = new Gr_Settings();

        $before = array(
            'enabled' => (int) $settings->get( 'capi_matomo_enabled' ),
            'url'     => self::credential_word( self::MATOMO_URL_OPTION ),
            'token'   => self::credential_word( self::MATOMO_TOKEN_OPTION ),
            'site'    => (int) $settings->get( 'matomo_site_id', 0 ),
        );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); the remove flag is a strict '1' comparison.
        $remove = isset( $_POST['matomo_remove'] ) && '1' === (string) wp_unslash( $_POST['matomo_remove'] );

        if ( $remove ) {
            Gr_Secrets::forget( self::MATOMO_URL_OPTION );
            Gr_Secrets::forget( self::MATOMO_TOKEN_OPTION );
            $settings->set( 'capi_matomo_enabled', 0 );
            $settings->set( 'matomo_site_id', 0 );
        } else {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); the values are shape-checked below before any store().
            $url_field = isset( $_POST['matomo_url'] ) ? trim( (string) wp_unslash( $_POST['matomo_url'] ) ) : '';
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); the values are shape-checked below before any store().
            $token_field = isset( $_POST['matomo_token'] ) ? trim( (string) wp_unslash( $_POST['matomo_token'] ) ) : '';

            if ( '' !== $url_field || '' !== $token_field ) {
                // Half-entered pairs are refused whole: a lone URL with
                // no token would look configured to the reader.
                if ( '' === $url_field || '' === $token_field ) {
                    wp_safe_redirect( self::page_url( array( 'gr_error' => 'matomo_pair' ) ) );

                    return;
                }
                if ( ! self::valid_matomo_url( $url_field ) ) {
                    wp_safe_redirect( self::page_url( array( 'gr_error' => 'matomo_url' ) ) );

                    return;
                }
                if ( ! self::valid_matomo_token( $token_field ) ) {
                    wp_safe_redirect( self::page_url( array( 'gr_error' => 'matomo_token' ) ) );

                    return;
                }

                Gr_Secrets::store( self::MATOMO_URL_OPTION, rtrim( $url_field, '/' ) );
                Gr_Secrets::store( self::MATOMO_TOKEN_OPTION, $token_field );
            }

            $settings->set( 'matomo_site_id', self::site_id_field() );
            $settings->set( 'capi_matomo_enabled', self::checkbox( 'capi_matomo_enabled' ) );
        }

        $after = array(
            'enabled' => (int) $settings->get( 'capi_matomo_enabled' ),
            'url'     => self::credential_word( self::MATOMO_URL_OPTION ),
            'token'   => self::credential_word( self::MATOMO_TOKEN_OPTION ),
            'site'    => (int) $settings->get( 'matomo_site_id', 0 ),
        );

        if ( $before !== $after ) {
            ( new Gr_Audit_Repository() )->log(
                'save',
                'analytics',
                'matomo',
                $before,
                $after,
                get_current_user_id()
            );
        }

        wp_safe_redirect( self::page_url( array( 'gr_saved' => 'matomo' ) ) );
    }

    /**
     * The site id field: a plain non-negative number, so a cleared
     * field means zero rather than a refusal.
     *
     * @return int
     */
    private static function site_id_field(): int {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); the value is cast to int before any store().
        $raw = isset( $_POST['matomo_site_id'] ) ? wp_unslash( $_POST['matomo_site_id'] ) : 0;

        return max( 0, absint( is_scalar( $raw ) ? (int) $raw : 0 ) );
    }

    /**
     * Shape gate for the instance URL: an http(s) URL with a host and
     * no embedded credentials, so the owner's own server address can
     * never smuggle auth into a link.
     *
     * @param string $value Candidate URL.
     * @return bool
     */
    private static function valid_matomo_url( string $value ): bool {
        $parts = wp_parse_url( trim( $value ) );

        if ( ! is_array( $parts ) ) {
            return false;
        }

        if ( ! isset( $parts['scheme'], $parts['host'] ) ) {
            return false;
        }
        if ( ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
            return false;
        }
        if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
            return false;
        }

        return true;
    }

    /**
     * The TikTok block's save arm: the opt-in toggle, the pixel code
     * and the access token as one credential pair, and the remove
     * switch that forgets both at once. State words only in the
     * audit diff, never the values.
     *
     * @return void
     */
    private static function save_tiktok(): void {
        $settings = new Gr_Settings();

        $before = array(
            'enabled' => (int) $settings->get( 'capi_tiktok_enabled' ),
            'pixel'   => self::credential_word( Gr_Secrets::TIKTOK_PIXEL_OPTION ),
            'token'   => self::credential_word( Gr_Secrets::TIKTOK_TOKEN_OPTION ),
        );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); the remove flag is a strict '1' comparison.
        $remove = isset( $_POST['tiktok_remove'] ) && '1' === (string) wp_unslash( $_POST['tiktok_remove'] );

        if ( $remove ) {
            Gr_Secrets::forget( Gr_Secrets::TIKTOK_PIXEL_OPTION );
            Gr_Secrets::forget( Gr_Secrets::TIKTOK_TOKEN_OPTION );
            $settings->set( 'capi_tiktok_enabled', 0 );
        } else {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); the values are shape-checked below before any store().
            $pixel_field = isset( $_POST['tiktok_pixel'] ) ? trim( (string) wp_unslash( $_POST['tiktok_pixel'] ) ) : '';
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); the values are shape-checked below before any store().
            $token_field = isset( $_POST['tiktok_token'] ) ? trim( (string) wp_unslash( $_POST['tiktok_token'] ) ) : '';

            if ( '' !== $pixel_field || '' !== $token_field ) {
                // Half-entered pairs are refused whole: a lone pixel
                // code with no token would look configured to the
                // reader.
                if ( '' === $pixel_field || '' === $token_field ) {
                    wp_safe_redirect( self::page_url( array( 'gr_error' => 'tiktok_pair' ) ) );

                    return;
                }
                if ( ! self::valid_tiktok_pixel( $pixel_field ) ) {
                    wp_safe_redirect( self::page_url( array( 'gr_error' => 'tiktok_pixel' ) ) );

                    return;
                }
                if ( ! self::valid_tiktok_token( $token_field ) ) {
                    wp_safe_redirect( self::page_url( array( 'gr_error' => 'tiktok_token' ) ) );

                    return;
                }

                Gr_Secrets::store( Gr_Secrets::TIKTOK_PIXEL_OPTION, $pixel_field );
                Gr_Secrets::store( Gr_Secrets::TIKTOK_TOKEN_OPTION, $token_field );
            }

            $settings->set( 'capi_tiktok_enabled', self::checkbox( 'capi_tiktok_enabled' ) );
        }

        $after = array(
            'enabled' => (int) $settings->get( 'capi_tiktok_enabled' ),
            'pixel'   => self::credential_word( Gr_Secrets::TIKTOK_PIXEL_OPTION ),
            'token'   => self::credential_word( Gr_Secrets::TIKTOK_TOKEN_OPTION ),
        );

        if ( $before !== $after ) {
            ( new Gr_Audit_Repository() )->log(
                'save',
                'analytics',
                'tiktok',
                $before,
                $after,
                get_current_user_id()
            );
        }

        wp_safe_redirect( self::page_url( array( 'gr_saved' => 'tiktok' ) ) );
    }

    /**
     * Shape gate for the TikTok pixel code: Events Manager issues it
     * as a C-prefixed uppercase alphanumeric id, long enough that a
     * pasted fragment cannot pass as the whole code.
     *
     * @param string $value Candidate pixel code.
     * @return bool
     */
    private static function valid_tiktok_pixel( string $value ): bool {
        return 1 === preg_match( '/^C[A-Z0-9]{16,24}$/', $value );
    }

    /**
     * Shape gate for the TikTok access token: the Events Manager's
     * opaque token charset, long enough that a pasted fragment
     * cannot pass as the whole credential.
     *
     * @param string $value Candidate token.
     * @return bool
     */
    private static function valid_tiktok_token( string $value ): bool {
        if ( strlen( $value ) < 20 || strlen( $value ) > 500 ) {
            return false;
        }

        return 1 === preg_match( '/^[A-Za-z0-9_.-]+$/', $value );
    }

    /**
     * Shape gate for the token_auth: the instance's own opaque token
     * charset, long enough that a pasted fragment cannot pass as the
     * whole credential.
     *
     * @param string $value Candidate token.
     * @return bool
     */
    private static function valid_matomo_token( string $value ): bool {
        return 1 === preg_match( '/^[A-Za-z0-9_-]{16,200}$/', $value );
    }

    /**
     * The GA4 connectivity probe: one validation request to Google's
     * debug endpoint, owner-clicked and this click only. The service
     * class owns the wire call through the door — this page still
     * makes no network request of its own. The audit trail carries
     * the outcome word and nothing else: no endpoint, no credential.
     *
     * @return void
     */
    private static function debug_ga4(): void {
        $outcome = Gr_Ga4_Mp::debug_check();
        $result  = isset( $outcome['result'] ) ? sanitize_key( (string) $outcome['result'] ) : 'unreachable';

        ( new Gr_Audit_Repository() )->log(
            'ga4_debug_check',
            'analytics',
            'ga4',
            array(),
            array( 'result' => $result ),
            get_current_user_id()
        );

        wp_safe_redirect(
            self::page_url(
                array(
                    'gr_debug'  => 'ga4',
                    'gr_result' => $result,
                )
            )
        );
    }

    /**
     * The local check battery: absent (nothing stored), broken (an
     * envelope that no longer decrypts — a changed salt or a mangled
     * row), shape (readable but not a plausible value), or ok.
     *
     * @param string $service 'meta', 'ga4', or 'matomo'.
     * @return string Result word.
     */
    private static function local_check( string $service ): string {
        if ( 'tiktok' === $service ) {
            $pixel = get_option( Gr_Secrets::TIKTOK_PIXEL_OPTION, '' );
            $token = get_option( Gr_Secrets::TIKTOK_TOKEN_OPTION, '' );

            if ( ! is_string( $pixel ) || '' === $pixel || ! is_string( $token ) || '' === $token ) {
                return 'absent';
            }

            $pixel_value = Gr_Secrets::reveal( Gr_Secrets::TIKTOK_PIXEL_OPTION );
            $token_value = Gr_Secrets::reveal( Gr_Secrets::TIKTOK_TOKEN_OPTION );

            if ( '' === $pixel_value || '' === $token_value ) {
                return 'broken';
            }
            if ( ! self::valid_tiktok_pixel( $pixel_value ) || ! self::valid_tiktok_token( $token_value ) ) {
                return 'shape';
            }

            return 'ok';
        }

        if ( 'matomo' === $service ) {
            $url   = get_option( self::MATOMO_URL_OPTION, '' );
            $token = get_option( self::MATOMO_TOKEN_OPTION, '' );
            $site  = (int) ( new Gr_Settings() )->get( 'matomo_site_id', 0 );

            if ( ! is_string( $url ) || '' === $url || ! is_string( $token ) || '' === $token || 0 >= $site ) {
                return 'absent';
            }

            $url_value   = Gr_Secrets::reveal( self::MATOMO_URL_OPTION );
            $token_value = Gr_Secrets::reveal( self::MATOMO_TOKEN_OPTION );

            if ( '' === $url_value || '' === $token_value ) {
                return 'broken';
            }
            if ( ! self::valid_matomo_url( $url_value ) || ! self::valid_matomo_token( $token_value ) ) {
                return 'shape';
            }

            return 'ok';
        }

        foreach ( array( self::id_option( $service ), self::secret_option( $service ) ) as $key ) {
            if ( '' === get_option( $key, '' ) ) {
                return 'absent';
            }
        }

        $id     = Gr_Secrets::reveal( self::id_option( $service ) );
        $secret = Gr_Secrets::reveal( self::secret_option( $service ) );

        if ( '' === $id || '' === $secret ) {
            return 'broken';
        }
        if ( ! self::valid_id( $service, $id ) || ! self::valid_secret( $service, $secret ) ) {
            return 'shape';
        }

        return 'ok';
    }

    /**
     * The state words for the audit diff: configured-ness of each
     * credential as a vocabulary, never the value.
     *
     * @param string $service 'meta' or 'ga4'.
     * @return array<string, string|int>
     */
    private static function service_snapshot( string $service ): array {
        $toggle_key = 'meta' === $service ? 'capi_meta_enabled' : 'capi_ga4_enabled';

        return array(
            'enabled' => (int) ( new Gr_Settings() )->get( $toggle_key ),
            'id'      => self::credential_word( self::id_option( $service ) ),
            'secret'  => self::credential_word( self::secret_option( $service ) ),
        );
    }

    /**
     * One credential's state word.
     *
     * @param string $option Secret option name.
     * @return string 'absent', 'stored', or 'broken'.
     */
    private static function credential_word( string $option ): string {
        $envelope = get_option( $option, '' );
        if ( ! is_string( $envelope ) || '' === $envelope ) {
            return 'absent';
        }

        return '' === Gr_Secrets::reveal( $option ) ? 'broken' : 'stored';
    }

    /**
     * Shape gate for the public identifiers: a Meta pixel id is a
     * plain number, a GA4 measurement id is G- plus uppercase
     * alphanumerics.
     *
     * @param string $service 'meta' or 'ga4'.
     * @param string $value   Candidate identifier.
     * @return bool
     */
    private static function valid_id( string $service, string $value ): bool {
        if ( 'meta' === $service ) {
            return 1 === preg_match( '/^[0-9]{5,20}$/', $value );
        }

        return 1 === preg_match( '/^G-[A-Z0-9]{4,12}$/', $value );
    }

    /**
     * Shape gate for the secrets: opaque token charset, long enough
     * that a pasted fragment cannot pass as the whole credential.
     *
     * @param string $service 'meta' or 'ga4'.
     * @param string $value   Candidate secret.
     * @return bool
     */
    private static function valid_secret( string $service, string $value ): bool {
        if ( strlen( $value ) < 20 || strlen( $value ) > 500 ) {
            return false;
        }

        return 1 === preg_match( '/^[A-Za-z0-9_|.-]+$/', $value );
    }

    /**
     * Reads a checkbox the way unchecked boxes POST: absent means 0.
     *
     * @param string $key POST key.
     * @return int 1 or 0.
     */
    private static function checkbox( string $key ): int {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); value is a strict '1' comparison.
        return isset( $_POST[ $key ] ) && '1' === (string) wp_unslash( $_POST[ $key ] ) ? 1 : 0;
    }

    /**
     * Double gate: owner capability AND nonce, both required.
     *
     * @return bool
     */
    private static function may_write(): bool {
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        return (bool) check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );
    }

    /**
     * Option name of one service's identifier credential.
     *
     * @param string $service 'meta' or 'ga4'.
     * @return string
     */
    private static function id_option( string $service ): string {
        return 'meta' === $service ? self::META_PIXEL_OPTION : self::GA4_ID_OPTION;
    }

    /**
     * Option name of one service's shared-secret credential.
     *
     * @param string $service 'meta' or 'ga4'.
     * @return string
     */
    private static function secret_option( string $service ): string {
        return 'meta' === $service ? self::META_TOKEN_OPTION : self::GA4_SECRET_OPTION;
    }

    /**
     * Page URL with added query flags, for the post-redirect-get.
     *
     * @param array<string, string> $args Query additions.
     * @return string
     */
    private static function page_url( array $args ): string {
        return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
    }

    /**
     * Page output: the standing disclosure, then the two service
     * blocks with their real states.
     *
     * @return void
     */
    public static function render(): void {
        $settings = new Gr_Settings();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- PRG result flags are words from our own redirects, read-only display.
        $saved = isset( $_GET['gr_saved'] ) ? sanitize_key( (string) wp_unslash( $_GET['gr_saved'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- PRG result flags are words from our own redirects, read-only display.
        $error = isset( $_GET['gr_error'] ) ? sanitize_key( (string) wp_unslash( $_GET['gr_error'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- PRG result flags are words from our own redirects, read-only display.
        $check = isset( $_GET['gr_check'] ) ? sanitize_key( (string) wp_unslash( $_GET['gr_check'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- PRG result flags are words from our own redirects, read-only display.
        $result = isset( $_GET['gr_result'] ) ? sanitize_key( (string) wp_unslash( $_GET['gr_result'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- PRG result flag is a word from our own redirect, read-only display.
        $debug = isset( $_GET['gr_debug'] ) ? sanitize_key( (string) wp_unslash( $_GET['gr_debug'] ) ) : '';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Analytics & CAPI', 'greenpng' ); ?></h1>
            <hr class="wp-header-end" />

            <?php if ( '' !== $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %s: service name, meta or ga4. */
                            __( 'Saved. The %s block now holds exactly what you entered.', 'greenpng' ),
                            $saved
                        )
                    );
                    ?>
                </p></div>
            <?php endif; ?>

            <?php if ( '' !== $error ) : ?>
                <div class="notice notice-error"><p>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %s: field flag from the refused save. */
                            __( 'Refused: %s. Credentials are stored whole or not at all, so nothing was saved.', 'greenpng' ),
                            $error
                        )
                    );
                    ?>
                </p></div>
            <?php endif; ?>

            <?php if ( '' !== $check ) : ?>
                <div class="notice <?php echo esc_attr( 'ok' === $result ? 'notice-success' : 'notice-warning' ); ?>"><p>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: 1: service name, 2: check result word. */
                            __( 'Local self-check (%1$s): %2$s.', 'greenpng' ),
                            $check,
                            '' === $result ? 'unknown' : $result
                        )
                    );
                    ?>
                </p></div>
            <?php endif; ?>

            <?php if ( '' !== $debug ) : ?>
                <div class="notice <?php echo esc_attr( 'ok' === $result ? 'notice-success' : 'notice-warning' ); ?>"><p>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %s: probe result word. */
                            __( 'Connectivity probe (GA4 debug endpoint): %s. The probe validates one test event and ingests nothing; it sends only on this click and never on a schedule.', 'greenpng' ),
                            '' === $result ? 'unknown' : $result
                        )
                    );
                    ?>
                </p></div>
            <?php endif; ?>

            <p><?php echo esc_html__( 'All four services stay off until you turn them on, and none sends anything without credentials. Dispatch is consent-gated: events travel only after the visitor gave marketing consent, PII leaves only as hashes, and every data request goes through the plugin queue, never during a page view. The local self-check tests decryption and shape; the GA4 block also offers a connectivity probe that sends one validation request to Google\'s debug endpoint when you click it, and nothing ever on its own.', 'greenpng' ); ?></p>

            <?php
            self::render_service_block(
                'meta',
                __( 'Meta Conversions API', 'greenpng' ),
                (int) $settings->get( 'capi_meta_enabled' ),
                __( 'Pixel ID', 'greenpng' ),
                __( 'The numeric id of the pixel this site already runs.', 'greenpng' ),
                __( 'Access token', 'greenpng' ),
                __( "A system-user token with the pixel's events permission.", 'greenpng' )
            );

            self::render_service_block(
                'ga4',
                __( 'GA4 Measurement Protocol', 'greenpng' ),
                (int) $settings->get( 'capi_ga4_enabled' ),
                __( 'Measurement ID', 'greenpng' ),
                __( "The G-… id of this site's GA4 property.", 'greenpng' ),
                __( 'API secret', 'greenpng' ),
                __( "Created in GA4 under the property's Measurement Protocol settings.", 'greenpng' )
            );

            self::render_matomo_block( (int) $settings->get( 'capi_matomo_enabled' ), (int) $settings->get( 'matomo_site_id', 0 ) );

            self::render_tiktok_block( (int) $settings->get( 'capi_tiktok_enabled' ) );
            ?>
        </div>
        <?php
    }

    /**
     * The TikTok block: the same state discipline as the Matomo
     * block, with the pixel code and the access token never
     * echoing back. The token in particular is a long-lived
     * credential, so it gets the password field and the same
     * whole-pair-or-nothing storage rule.
     *
     * @param int $enabled Opt-in state.
     * @return void
     */
    private static function render_tiktok_block( int $enabled ): void {
        $pixel_word = self::credential_word( Gr_Secrets::TIKTOK_PIXEL_OPTION );
        $token_word = self::credential_word( Gr_Secrets::TIKTOK_TOKEN_OPTION );
        ?>
        <h2><?php echo esc_html__( 'TikTok Events API', 'greenpng' ); ?></h2>

        <p>
            <?php
            if ( 'stored' === $pixel_word && 'stored' === $token_word ) {
                echo esc_html(
                    sprintf(
                        /* translators: %s: masked pixel code preview. */
                        __( 'Configured (pixel %s). Fields left empty keep the stored values.', 'greenpng' ),
                        Gr_Secrets::mask( Gr_Secrets::reveal( Gr_Secrets::TIKTOK_PIXEL_OPTION ) )
                    )
                );
            } elseif ( 'broken' === $pixel_word || 'broken' === $token_word ) {
                echo esc_html__( 'Stored values no longer decrypt (a changed salt or a mangled row). Enter them again or remove the block.', 'greenpng' );
            } else {
                echo esc_html__( 'Not configured. Nothing is sent for this service, and its toggle has no effect until the pixel code and the access token exist.', 'greenpng' );
            }
            ?>
        </p>

        <form method="post">
            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
            <input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Enabled', 'greenpng' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="capi_tiktok_enabled" value="1" <?php checked( 1, $enabled ); ?> />
                            <?php echo esc_html__( 'Send conversion events (consent-gated, opt-in)', 'greenpng' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tiktok_pixel"><?php echo esc_html__( 'Pixel code', 'greenpng' ); ?></label></th>
                    <td>
                        <?php if ( 'stored' === $pixel_word ) : ?>
                            <code><?php echo esc_html( Gr_Secrets::mask( Gr_Secrets::reveal( Gr_Secrets::TIKTOK_PIXEL_OPTION ) ) ); ?></code>
                            <?php echo esc_html__( '— stored. Type a new value to replace it.', 'greenpng' ); ?>
                            <br />
                        <?php endif; ?>
                        <input type="text" id="tiktok_pixel" name="tiktok_pixel" value="" class="regular-text" autocomplete="off" />
                        <p class="description"><?php echo esc_html__( 'The pixel code of this site\'s pixel in TikTok Events Manager (a C-prefixed id).', 'greenpng' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tiktok_token"><?php echo esc_html__( 'Access token', 'greenpng' ); ?></label></th>
                    <td>
                        <input type="password" id="tiktok_token" name="tiktok_token" value="" class="regular-text" autocomplete="new-password" />
                        <p class="description"><?php echo esc_html__( 'The long-term access token Events Manager generates under your pixel\'s API settings.', 'greenpng' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Remove', 'greenpng' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="tiktok_remove" value="1" />
                            <?php echo esc_html__( 'Forget the pixel code and the access token entirely; the toggle turns off with them', 'greenpng' ); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary" name="gr_analytics_action" value="<?php echo esc_attr( self::ACTION_SAVE_TIKTOK ); ?>"><?php echo esc_html__( 'Save', 'greenpng' ); ?></button>
                <button type="submit" class="button" name="gr_analytics_action" value="<?php echo esc_attr( self::ACTION_CHECK_TIKTOK ); ?>"><?php echo esc_html__( 'Self-check', 'greenpng' ); ?></button>
            </p>
        </form>
        <?php
    }

    /**
     * The Matomo block: the same state discipline as the service
     * blocks, with the two secrets (instance URL, token_auth) never
     * echoing back and the plain site id always visible — it is a
     * number, not a credential, and the owner needs to see it to
     * check it against the instance.
     *
     * @param int $enabled Opt-in state.
     * @param int $site_id Stored site id.
     * @return void
     */
    private static function render_matomo_block( int $enabled, int $site_id ): void {
        $url_word   = self::credential_word( self::MATOMO_URL_OPTION );
        $token_word = self::credential_word( self::MATOMO_TOKEN_OPTION );
        ?>
        <h2><?php echo esc_html__( 'Matomo (self-hosted instance)', 'greenpng' ); ?></h2>

        <p>
            <?php
            if ( 'stored' === $url_word && 'stored' === $token_word ) {
                echo esc_html(
                    sprintf(
                        /* translators: %s: masked instance URL preview. */
                        __( 'Configured (instance %s). Fields left empty keep the stored values.', 'greenpng' ),
                        Gr_Secrets::mask( Gr_Secrets::reveal( self::MATOMO_URL_OPTION ) )
                    )
                );
            } elseif ( 'broken' === $url_word || 'broken' === $token_word ) {
                echo esc_html__( 'Stored values no longer decrypt (a changed salt or a mangled row). Enter them again or remove the block.', 'greenpng' );
            } else {
                echo esc_html__( 'Not configured. Nothing is sent for this service, and its toggle has no effect until the instance URL and the token exist.', 'greenpng' );
            }
            ?>
        </p>

        <form method="post">
            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
            <input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Enabled', 'greenpng' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="capi_matomo_enabled" value="1" <?php checked( 1, $enabled ); ?> />
                            <?php echo esc_html__( 'Send tracking events (consent-gated, opt-in)', 'greenpng' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="matomo_url"><?php echo esc_html__( 'Instance URL', 'greenpng' ); ?></label></th>
                    <td>
                        <?php if ( 'stored' === $url_word ) : ?>
                            <code><?php echo esc_html( Gr_Secrets::mask( Gr_Secrets::reveal( self::MATOMO_URL_OPTION ) ) ); ?></code>
                            <?php echo esc_html__( '— stored. Type a new value to replace it.', 'greenpng' ); ?>
                            <br />
                        <?php endif; ?>
                        <input type="text" id="matomo_url" name="matomo_url" value="" class="regular-text" autocomplete="off" />
                        <p class="description"><?php echo esc_html__( 'Your own Matomo server, e.g. https://matomo.example.com — the matomo.php endpoint path is appended when missing.', 'greenpng' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="matomo_token"><?php echo esc_html__( 'token_auth', 'greenpng' ); ?></label></th>
                    <td>
                        <input type="password" id="matomo_token" name="matomo_token" value="" class="regular-text" autocomplete="new-password" />
                        <p class="description"><?php echo esc_html__( 'The tracking token of your Matomo instance, under Settings → Personal → API Authentication.', 'greenpng' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="matomo_site_id"><?php echo esc_html__( 'Site id', 'greenpng' ); ?></label></th>
                    <td>
                        <input type="number" id="matomo_site_id" name="matomo_site_id" value="<?php echo esc_attr( (string) $site_id ); ?>" class="small-text" min="0" autocomplete="off" />
                        <p class="description"><?php echo esc_html__( 'The numeric idsite this site tracks under on your instance; 0 means none.', 'greenpng' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Remove', 'greenpng' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="matomo_remove" value="1" />
                            <?php echo esc_html__( 'Forget the instance URL, the token, and the site id entirely; the toggle turns off with them', 'greenpng' ); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary" name="gr_analytics_action" value="<?php echo esc_attr( self::ACTION_SAVE_MATOMO ); ?>"><?php echo esc_html__( 'Save', 'greenpng' ); ?></button>
                <button type="submit" class="button" name="gr_analytics_action" value="<?php echo esc_attr( self::ACTION_CHECK_MATOMO ); ?>"><?php echo esc_html__( 'Self-check', 'greenpng' ); ?></button>
            </p>
        </form>
        <?php
    }

    /**
     * One service block: state line (an explicit "not configured" when
     * nothing is stored), a masked preview when configured, the opt-in
     * toggle, credential fields that never echo a stored value back,
     * the remove switch, and the two buttons. Two submit buttons share
     * one name and carry their action as the value, the core-native
     * way to offer more than one action per form.
     *
     * @param string $service       Service key.
     * @param string $title         Block heading.
     * @param int    $enabled       Opt-in state.
     * @param string $id_label      Identifier field label.
     * @param string $id_note       Identifier field note.
     * @param string $secret_label  Secret field label.
     * @param string $secret_note   Secret field note.
     * @return void
     */
    private static function render_service_block( string $service, string $title, int $enabled, string $id_label, string $id_note, string $secret_label, string $secret_note ): void {
        $id_word     = self::credential_word( self::id_option( $service ) );
        $secret_word = self::credential_word( self::secret_option( $service ) );
        $toggle_name = 'meta' === $service ? 'capi_meta_enabled' : 'capi_ga4_enabled';
        ?>
        <h2><?php echo esc_html( $title ); ?></h2>

        <p>
            <?php
            if ( 'stored' === $id_word && 'stored' === $secret_word ) {
                echo esc_html(
                    sprintf(
                        /* translators: %s: masked credential preview. */
                        __( 'Configured (secret %s). Fields left empty keep the stored credentials.', 'greenpng' ),
                        Gr_Secrets::mask( Gr_Secrets::reveal( self::secret_option( $service ) ) )
                    )
                );
            } elseif ( 'broken' === $id_word || 'broken' === $secret_word ) {
                echo esc_html__( 'Stored credentials no longer decrypt (a changed salt or a mangled row). Enter them again or remove the block.', 'greenpng' );
            } else {
                echo esc_html__( 'Not configured. Nothing is sent for this service, and its toggle has no effect until credentials exist.', 'greenpng' );
            }
            ?>
        </p>

        <form method="post">
            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
            <input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Enabled', 'greenpng' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr( $toggle_name ); ?>" value="1" <?php checked( 1, $enabled ); ?> />
                            <?php echo esc_html__( 'Send conversion events (consent-gated, opt-in)', 'greenpng' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr( $service . '_id' ); ?>"><?php echo esc_html( $id_label ); ?></label></th>
                    <td>
                        <?php if ( 'stored' === $id_word ) : ?>
                            <code><?php echo esc_html( Gr_Secrets::mask( Gr_Secrets::reveal( self::id_option( $service ) ) ) ); ?></code>
                            <?php echo esc_html__( '— stored. Type a new value to replace it.', 'greenpng' ); ?>
                            <br />
                        <?php endif; ?>
                        <input type="text" id="<?php echo esc_attr( $service . '_id' ); ?>" name="<?php echo esc_attr( $service . '_id' ); ?>" value="" class="regular-text" autocomplete="off" />
                        <p class="description"><?php echo esc_html( $id_note ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr( $service . '_secret' ); ?>"><?php echo esc_html( $secret_label ); ?></label></th>
                    <td>
                        <input type="password" id="<?php echo esc_attr( $service . '_secret' ); ?>" name="<?php echo esc_attr( $service . '_secret' ); ?>" value="" class="regular-text" autocomplete="new-password" />
                        <p class="description"><?php echo esc_html( $secret_note ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Remove', 'greenpng' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr( $service . '_remove' ); ?>" value="1" />
                            <?php echo esc_html__( 'Forget the credentials of this service entirely; the toggle turns off with them', 'greenpng' ); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary" name="gr_analytics_action" value="<?php echo esc_attr( 'meta' === $service ? self::ACTION_SAVE_META : self::ACTION_SAVE_GA4 ); ?>"><?php echo esc_html__( 'Save', 'greenpng' ); ?></button>
                <button type="submit" class="button" name="gr_analytics_action" value="<?php echo esc_attr( 'meta' === $service ? self::ACTION_CHECK_META : self::ACTION_CHECK_GA4 ); ?>"><?php echo esc_html__( 'Self-check', 'greenpng' ); ?></button>
                <?php if ( 'ga4' === $service ) : ?>
                    <button type="submit" class="button" name="gr_analytics_action" value="<?php echo esc_attr( self::ACTION_DEBUG_GA4 ); ?>"><?php echo esc_html__( 'Test connection (one validation request to Google, this click only)', 'greenpng' ); ?></button>
                <?php endif; ?>
            </p>
        </form>
        <?php
    }
}
