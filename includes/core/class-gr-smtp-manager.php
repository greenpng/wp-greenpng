<?php
/**
 * Native built-in SMTP engine (ADR-0019, docs/21): provides high-reliability
 * mail transport through the standard WordPress phpmailer_init action hook.
 *
 * Eliminates the need for any third-party SMTP plugin, while ensuring both
 * GreenPNG's notifications and all WordPress transactional emails (orders,
 * password resets, user registrations) are delivered smoothly through the
 * site owner's configured SMTP server (163, QQ, Enterprise Mail, Gmail, etc.).
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;

/**
 * Built-in SMTP manager hooked into phpmailer_init.
 */
final class Gr_Smtp_Manager {

    /**
     * Registers the WordPress hooks: the phpmailer_init configuration
     * and the wp_mail_from seat. The filter is not decoration — core
     * validates the From address in setFrom() BEFORE phpmailer_init
     * ever fires, so a site on a single-label host (a localhost or
     * intranet install, where core's default is wordpress@localhost)
     * dies inside setFrom() before any phpmailer_init override can
     * run. The owner's configured From must therefore enter through
     * the one door core opens that early: the wp_mail_from filter.
     *
     * @return void
     */
    public static function register(): void {
        add_action( 'phpmailer_init', array( __CLASS__, 'configure_phpmailer' ) );
        add_filter( 'wp_mail_from', array( __CLASS__, 'filter_from_email' ) );
    }

    /**
     * The From address core should validate at setFrom() time: the
     * owner's configured sender when it is a valid address, core's
     * own default otherwise.
     *
     * @param string $core_from Core's own default From (wordpress@host).
     * @return string
     */
    public static function filter_from_email( string $core_from ): string {
        $settings   = function_exists( 'gr' ) ? gr()->settings() : new Gr_Settings();
        $from_email = trim( (string) $settings->get( 'smtp_from_email', '' ) );

        if ( '' !== $from_email && is_email( $from_email ) ) {
            return $from_email;
        }

        return $core_from;
    }

    /**
     * Checks if built-in SMTP is configured and enabled by the site owner.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        $settings = function_exists( 'gr' ) ? gr()->settings() : new Gr_Settings();
        $enabled  = 1 === (int) $settings->get( 'smtp_enabled', 0 );
        $host     = trim( (string) $settings->get( 'smtp_host', '' ) );

        return $enabled && '' !== $host;
    }

    /**
     * Configures the PHPMailer instance before WordPress dispatches a mail.
     *
     * @param object $phpmailer PHPMailer instance passed by reference.
     * @return void
     */
    public static function configure_phpmailer( object $phpmailer ): void {
        if ( ! self::is_enabled() ) {
            return;
        }

        $settings   = function_exists( 'gr' ) ? gr()->settings() : new Gr_Settings();
        $host       = trim( (string) $settings->get( 'smtp_host', '' ) );
        $port       = (int) $settings->get( 'smtp_port', 465 );
        $encryption = (string) $settings->get( 'smtp_encryption', 'ssl' );
        $auth       = 1 === (int) $settings->get( 'smtp_auth', 1 );
        $user       = trim( (string) $settings->get( 'smtp_user', '' ) );
        $pass       = Gr_Secrets::reveal( Gr_Secrets::SMTP_PASS_OPTION );
        $from_email = trim( (string) $settings->get( 'smtp_from_email', '' ) );
        $from_name  = trim( (string) $settings->get( 'smtp_from_name', '' ) );

        if ( method_exists( $phpmailer, 'isSMTP' ) ) {
            $phpmailer->isSMTP();
        }

        // PHPMailer's own public property names, not ours; the naming
        // sniff has no jurisdiction over the vendored interop surface.
        // phpcs:disable WordPress.NamingConventions.ValidVariableName -- PHPMailer interop properties.
        $phpmailer->Host = $host;
        $phpmailer->Port = $port > 0 ? $port : 465;

        if ( 'ssl' === $encryption ) {
            $phpmailer->SMTPSecure = 'ssl';
        } elseif ( 'tls' === $encryption ) {
            $phpmailer->SMTPSecure = 'tls';
        } else {
            $phpmailer->SMTPSecure  = '';
            $phpmailer->SMTPAutoTLS = false;
        }

        $phpmailer->SMTPAuth = $auth;
        if ( $auth ) {
            $phpmailer->Username = $user;
            $phpmailer->Password = $pass;
        }

        if ( '' !== $from_email && is_email( $from_email ) ) {
            $phpmailer->From = $from_email;
        } elseif ( '' !== $user && is_email( $user ) ) {
            $phpmailer->From = $user;
        }

        if ( '' !== $from_name ) {
            $phpmailer->FromName = $from_name;
        }

        $phpmailer->Timeout = 10;
        // phpcs:enable WordPress.NamingConventions.ValidVariableName -- PHPMailer interop properties.
    }

    /**
     * Executes a single test email delivery to verify SMTP connectivity and credentials.
     *
     * @param string $to_email Target email recipient.
     * @return array{success: bool, message: string, error: string}
     */
    public static function test_connection( string $to_email ): array {
        $to_email = sanitize_email( trim( $to_email ) );
        if ( '' === $to_email || ! is_email( $to_email ) ) {
            return array(
                'success' => false,
                'message' => __( 'Please provide a valid recipient email address for testing.', 'greenpng' ),
                'error'   => 'INVALID_RECIPIENT',
            );
        }

        if ( ! self::is_enabled() ) {
            return array(
                'success' => false,
                'message' => __( 'Built-in SMTP is currently disabled or SMTP Host is not configured.', 'greenpng' ),
                'error'   => 'SMTP_DISABLED',
            );
        }

        $error_message = '';
        $catcher       = static function ( WP_Error $wp_error ) use ( &$error_message ): void {
            $error_message = $wp_error->get_error_message();
        };

        add_action( 'wp_mail_failed', $catcher );

        $site_name = get_bloginfo( 'name' );
        $subject   = sprintf( '[%s] %s', $site_name, __( 'SMTP Test Email - GreenPNG', 'greenpng' ) );
        $settings  = function_exists( 'gr' ) ? gr()->settings() : new Gr_Settings();

        $message = sprintf(
            "%s\n\n%s\n\n%s: %s\n%s: %d\n%s: %s\n%s: %s\n\n%s",
            __( 'Hello,', 'greenpng' ),
            __( 'This is a test email sent via GreenPNG\'s built-in native SMTP engine.', 'greenpng' ),
            __( 'SMTP Host', 'greenpng' ),
            (string) $settings->get( 'smtp_host', '' ),
            __( 'Port', 'greenpng' ),
            (int) $settings->get( 'smtp_port', 465 ),
            __( 'Encryption', 'greenpng' ),
            (string) $settings->get( 'smtp_encryption', 'ssl' ),
            __( 'Sent at', 'greenpng' ),
            current_time( 'mysql' ),
            __( 'If you received this email, your WordPress SMTP transport is working properly!', 'greenpng' )
        );

        $sent = (bool) wp_mail( $to_email, $subject, $message );

        remove_action( 'wp_mail_failed', $catcher );

        if ( $sent ) {
            return array(
                'success' => true,
                'message' => sprintf(
                    /* translators: %s: recipient email address */
                    __( 'Test email sent successfully to %s!', 'greenpng' ),
                    $to_email
                ),
                'error'   => '',
            );
        }

        return array(
            'success' => false,
            'message' => __( 'Failed to send test email. Please check your SMTP Host, Port, and authentication credentials.', 'greenpng' ) . ' ' . __( 'Common causes: an expired password or authorization code, wrong encryption for the port (465 expects SSL, 587 expects STARTTLS), or the port blocked by the host firewall.', 'greenpng' ),
            'error'   => '' !== $error_message ? $error_message : __( 'Unknown mail delivery error.', 'greenpng' ),
        );
    }
}
