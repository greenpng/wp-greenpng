<?php
/**
 * Email notification channel (ADR-0019, docs/21): formats and dispatches
 * transactional HTML card emails to site administrators via wp_mail.
 *
 * Runs inside background queue jobs (Gr_Queue) to keep visitor requests
 * 100% free of outbound mail latency (铁律 3), and enforces strict PII
 * masking on customer emails and client IP addresses (铁律 1).
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Notification;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Secrets;
use GreenPNG\Core\Gr_Settings;
use GreenPNG\Storage\Gr_Contact_Repository;

/**
 * Transactional email notification channel.
 */
final class Gr_Email_Channel {

    /** Action hook fired by Gr_Queue for async email sending. */
    public const HOOK_SEND = 'gr_notification_email_send';

    /**
     * Registers queue worker hook.
     *
     * @return void
     */
    public static function register(): void {
        add_action( self::HOOK_SEND, array( __CLASS__, 'send' ), 10, 1 );
    }

    /**
     * Dispatches one queued email notification.
     *
     * @param array<string, mixed> $job_args Queued payload containing event, payload, and recipients.
     * @return bool True when mail was accepted by wp_mail.
     */
    public static function send( array $job_args ): bool {
        $event_name = (string) ( $job_args['event'] ?? '' );
        $payload    = (array) ( $job_args['payload'] ?? array() );
        $recipients = (array) ( $job_args['recipients'] ?? array() );

        // The event object lifts the visitor reference out of the
        // payload as context, so the queue job carries it beside the
        // payload; the render copy takes it back in — the CRM
        // walk-back resolves by it, and it is the same reference the
        // event stream's own columns already hold.
        $job_visitor = trim( (string) ( $job_args['visitor_id'] ?? '' ) );
        if ( '' !== $job_visitor && ! isset( $payload['visitor_id'] ) ) {
            $payload['visitor_id'] = $job_visitor;
        }

        if ( empty( $recipients ) ) {
            $settings   = function_exists( 'gr' ) ? gr()->settings() : new Gr_Settings();
            $recip_raw  = (string) $settings->get( 'notify_email_recipients', '' );
            $recipients = Gr_Notification_Hub::parse_recipients( $recip_raw );
        }

        if ( empty( $recipients ) ) {
            return false;
        }

        $site_name = get_bloginfo( 'name' );
        $subject   = self::format_subject( $site_name, $event_name, $payload );
        $html_body = self::render_html( $event_name, $payload );
        $to_string = implode( ', ', $recipients );
        $headers   = "Content-Type: text/html; charset=UTF-8\r\n";

        return (bool) wp_mail( $to_string, $subject, $html_body, $headers );
    }

    /**
     * Constructs a human-readable email subject line.
     *
     * @param string               $site       Site name.
     * @param string               $event_name Event name.
     * @param array<string, mixed> $payload    Event payload.
     * @return string
     */
    public static function format_subject( string $site, string $event_name, array $payload ): string {
        switch ( $event_name ) {
            case Gr_Notification_Hub::EVENT_CONVERSION:
                $order_ref = self::order_ref( $payload );
                $amount    = (string) ( $payload['amount'] ?? '0.00' );
                $currency  = (string) ( $payload['currency'] ?? 'USD' );
                return sprintf( '[%s] 🎉 %s%s ($%s %s)', $site, __( 'New Order', 'greenpng' ), $order_ref, $amount, $currency );

            case Gr_Notification_Hub::EVENT_LEAD:
                list( , $name ) = self::contact_display( self::resolve_contact( $payload ) );
                return sprintf( '[%s] 👤 %s: %s', $site, __( 'New Contact Lead', 'greenpng' ), '' !== $name ? $name : __( 'Anonymous', 'greenpng' ) );

            case Gr_Notification_Hub::EVENT_LOCKOUT:
            case Gr_Notification_Hub::EVENT_LOCKOUT_ALT:
                $ip = \gr_mask_ip( (string) ( $payload['ip'] ?? '' ) );
                return sprintf( '[%s] 🚨 %s: %s', $site, __( 'Security Lockout Alert', 'greenpng' ), $ip );

            default:
                return sprintf( '[%s] %s: %s', $site, __( 'Notification', 'greenpng' ), ucfirst( $event_name ) );
        }
    }

    /**
     * Renders a responsive, cross-client HTML card email body.
     *
     * @param string               $event_name Event name.
     * @param array<string, mixed> $payload    Event payload.
     * @return string Valid HTML.
     */
    public static function render_html( string $event_name, array $payload ): string {
        $site_name = esc_html( get_bloginfo( 'name' ) );
        $site_url  = esc_url( home_url( '/' ) );
        $admin_url = esc_url( admin_url( 'admin.php?page=greenpng-dashboard' ) );

        $theme_color = '#10B981'; // Green for conversions.
        $event_badge = __( 'New Conversion', 'greenpng' );
        $lead_text   = __( 'A new conversion was completed on your website.', 'greenpng' );

        if ( Gr_Notification_Hub::EVENT_LEAD === $event_name ) {
            $theme_color = '#3B82F6'; // Blue for leads.
            $event_badge = __( 'New Lead Captured', 'greenpng' );
            $lead_text   = __( 'A visitor submitted their contact details and consented to tracking.', 'greenpng' );
        } elseif ( Gr_Notification_Hub::EVENT_LOCKOUT === $event_name || Gr_Notification_Hub::EVENT_LOCKOUT_ALT === $event_name ) {
            $theme_color = '#EF4444'; // Red for lockouts.
            $event_badge = __( 'Security Alert', 'greenpng' );
            $lead_text   = __( 'A malicious or automated client has exceeded login failure thresholds and was temporarily locked out.', 'greenpng' );
            $admin_url   = esc_url( admin_url( 'admin.php?page=greenpng-login&tab=sessions' ) );
        }

        // Build key-value detail rows. PII never rides the event bus
        // (ADR-0016 discipline): names and masked addresses resolve
        // from the CRM repository through the references the payload
        // is allowed to carry, and a row with no honest source simply
        // does not render.
        $rows = array();
        if ( Gr_Notification_Hub::EVENT_CONVERSION === $event_name ) {
            $order_ref = self::order_ref( $payload );
            if ( '' !== $order_ref ) {
                $rows[ __( 'Order ID', 'greenpng' ) ] = esc_html( $order_ref );
            }
            $rows[ __( 'Amount', 'greenpng' ) ] = esc_html( sprintf( '%s %s', (string) ( $payload['amount'] ?? '0.00' ), (string) ( $payload['currency'] ?? 'USD' ) ) );
            $channel                            = (string) ( $payload['channel'] ?? ( $payload['source_type'] ?? 'Direct' ) );
            if ( '' !== $channel ) {
                $rows[ __( 'Channel', 'greenpng' ) ] = esc_html( $channel );
            }
            list( $masked_email, $lead_name ) = self::contact_display( self::resolve_contact( $payload ) );
            if ( '' !== $masked_email ) {
                $rows[ __( 'Customer Email', 'greenpng' ) ] = esc_html( $masked_email );
            }
            if ( '' !== $lead_name ) {
                $rows[ __( 'Customer Name', 'greenpng' ) ] = esc_html( $lead_name );
            }
        } elseif ( Gr_Notification_Hub::EVENT_LEAD === $event_name ) {
            $form = (string) ( $payload['form_name'] ?? '' );
            if ( '' === $form && isset( $payload['source_id'] ) ) {
                $form = (string) ( $payload['source_type'] ?? 'form' ) . ' #' . (string) $payload['source_id'];
            }
            $rows[ __( 'Form Name', 'greenpng' ) ] = esc_html( '' !== $form ? $form : __( 'Inquiry', 'greenpng' ) );
            list( $masked_email, $lead_name )      = self::contact_display( self::resolve_contact( $payload ) );
            if ( '' !== $masked_email ) {
                $rows[ __( 'Contact Email', 'greenpng' ) ] = esc_html( $masked_email );
            }
            if ( '' !== $lead_name ) {
                $rows[ __( 'Contact Name', 'greenpng' ) ] = esc_html( $lead_name );
            }
            if ( isset( $payload['message'] ) && is_scalar( $payload['message'] ) && '' !== (string) $payload['message'] ) {
                $rows[ __( 'Message', 'greenpng' ) ] = esc_html( (string) $payload['message'] );
            }
        } elseif ( Gr_Notification_Hub::EVENT_LOCKOUT === $event_name || Gr_Notification_Hub::EVENT_LOCKOUT_ALT === $event_name ) {
            $rows[ __( 'Target IP', 'greenpng' ) ]        = esc_html( \gr_mask_ip( (string) ( $payload['ip'] ?? '' ) ) );
            $rows[ __( 'Reason', 'greenpng' ) ]           = esc_html( (string) ( $payload['reason'] ?? 'Failed login attempts' ) );
            $rows[ __( 'Lockout Duration', 'greenpng' ) ] = esc_html( sprintf( '%d seconds', (int) ( $payload['duration'] ?? 300 ) ) );
            $rows[ __( 'Timestamp', 'greenpng' ) ]        = esc_html( current_time( 'mysql' ) );
        } else {
            foreach ( $payload as $k => $v ) {
                if ( is_scalar( $v ) ) {
                    $rows[ esc_html( ucfirst( str_replace( '_', ' ', (string) $k ) ) ) ] = esc_html( (string) $v );
                }
            }
        }

        $table_html = '';
        $alt        = false;
        foreach ( $rows as $label => $val ) {
            $bg          = $alt ? '#f8fafc' : '#ffffff';
            $table_html .= sprintf(
                '<tr style="background-color: %s;"><td style="padding: 10px 14px; font-weight: 600; color: #64748b; width: 38%%; font-size: 13px; border-bottom: 1px solid #f1f5f9;">%s</td><td style="padding: 10px 14px; color: #1e293b; font-size: 14px; border-bottom: 1px solid #f1f5f9;">%s</td></tr>',
                $bg,
                $label,
                $val
            );
            $alt         = ! $alt;
        }

        $cta_text = __( 'View in WordPress Dashboard', 'greenpng' );
        if ( Gr_Notification_Hub::EVENT_LOCKOUT === $event_name || Gr_Notification_Hub::EVENT_LOCKOUT_ALT === $event_name ) {
            $cta_text = __( 'Review Active Locks', 'greenpng' );
        }

        return '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . esc_html( $event_badge ) . '</title>
</head>
<body style="margin: 0; padding: 24px 12px; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;">
  <div style="max-width: 580px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); border: 1px solid #e2e8f0;">
    <div style="height: 6px; background-color: ' . esc_attr( $theme_color ) . ';"></div>
    <div style="padding: 24px 28px;">
      <div style="margin-bottom: 18px;">
        <span style="display: inline-block; padding: 4px 10px; font-size: 12px; font-weight: 700; color: #ffffff; background-color: ' . esc_attr( $theme_color ) . '; border-radius: 4px; text-transform: uppercase; letter-spacing: 0.5px;">' . esc_html( $event_badge ) . '</span>
        <span style="float: right; font-size: 13px; color: #94a3b8; line-height: 24px;">' . $site_name . '</span>
      </div>
      <p style="font-size: 15px; color: #334155; line-height: 1.5; margin: 0 0 20px 0;">' . esc_html( $lead_text ) . '</p>
      <table style="width: 100%; border-collapse: collapse; margin-bottom: 24px; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden;">
        <tbody>
          ' . $table_html . '
        </tbody>
      </table>
      <div style="text-align: center; margin-top: 24px;">
        <a href="' . $admin_url . '" style="display: inline-block; padding: 11px 24px; font-size: 14px; font-weight: 600; color: #ffffff; background-color: ' . esc_attr( $theme_color ) . '; text-decoration: none; border-radius: 6px; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);">' . esc_html( $cta_text ) . ' &rarr;</a>
      </div>
    </div>
    <div style="padding: 14px 28px; background-color: #f8fafc; border-top: 1px solid #f1f5f9; text-align: center; font-size: 12px; color: #94a3b8;">
      ' . sprintf(
            /* translators: %s: website link */
            __( 'Delivered securely by GreenPNG on %s. Addresses and customer emails are protected under GDPR compliance.', 'greenpng' ),
            '<a href="' . $site_url . '" style="color: #64748b; text-decoration: none;">' . $site_name . '</a>'
        ) . '
    </div>
  </div>
</body>
</html>';
    }

    /**
     * The order reference for a conversion, from the reference the bus
     * actually carries: an explicit order_id when a producer sent one,
     * else the source id (a WooCommerce conversion's source_id is the
     * order id). Empty when neither exists — the caller drops the
     * reference rather than printing a placeholder.
     *
     * @param array<string, mixed> $payload Event payload.
     * @return string ' #123' with the reference, '' without.
     */
    private static function order_ref( array $payload ): string {
        $order_id = trim( (string) ( $payload['order_id'] ?? '' ) );
        if ( '' === $order_id ) {
            $order_id = trim( (string) ( $payload['source_id'] ?? '' ) );
        }

        return '' !== $order_id ? ' #' . $order_id : '';
    }

    /**
     * Resolves one contact row through the references the event bus
     * is allowed to carry: a contact id when the event has one, else
     * the cookie-track visitor. The payload itself never holds PII,
     * so masked card fields walk back to the repository here.
     *
     * @param array<string, mixed> $payload Event payload.
     * @return array<string, mixed>|null Contact row, null when unresolvable.
     */
    private static function resolve_contact( array $payload ) {
        $repository = new Gr_Contact_Repository();
        $contact_id = (int) ( $payload['contact_id'] ?? 0 );
        if ( $contact_id > 0 ) {
            return $repository->row_for_id( $contact_id );
        }

        $visitor_id = trim( (string) ( $payload['visitor_id'] ?? '' ) );
        if ( '' !== $visitor_id ) {
            $contact_id = $repository->id_for_visitor( $visitor_id );
            if ( $contact_id > 0 ) {
                return $repository->row_for_id( $contact_id );
            }
        }

        return null;
    }

    /**
     * The masked display pair for one contact row: the masked address
     * and the stored name. Plaintext email never leaves this helper —
     * the card sees only the masked form, same as every admin render.
     *
     * @param array<string, mixed>|null $row Contact row.
     * @return array{0: string, 1: string} Masked email and display name.
     */
    private static function contact_display( $row ): array {
        if ( null === $row ) {
            return array( '', '' );
        }

        $email  = Gr_Secrets::decrypt( (string) ( $row['email_enc'] ?? '' ) );
        $masked = ( is_string( $email ) && '' !== $email ) ? gr_mask_email( $email ) : '';
        $name   = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );

        return array( $masked, $name );
    }
}
