<?php
/**
 * Cart recovery engine (ADR-0015 D2/D3): one delayed queue event per
 * captured row, no loop scans. At fire time the row must still earn
 * the mail — status still captured, the session never converted, no
 * settled order under the email (the row's own order included: a
 * bank transfer in transit is a person mid-purchase, not an
 * abandonment), the consent snapshot still true, and the email never
 * said never again. The guarded UPDATE from captured to abandoned is
 * the mutex: two queued checks racing on one row send one mail.
 *
 * Delivery rides wp_mail — the site's own transactional mail, never
 * a third-party endpoint. One retry after six hours; a second refusal
 * parks the row as failed, where the status page says so instead of
 * silence.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Cart;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Queue;
use GreenPNG\Core\Gr_Secrets;
use GreenPNG\Storage\Gr_Cart_Abandonment_Repository;
use GreenPNG\Storage\Gr_Contact_Repository;
use GreenPNG\Storage\Gr_Conversion_Repository;

/**
 * Scheduling, the four-gate check, the mail, and link redemption.
 */
final class Gr_Cart_Recovery {

    /** Queue work hook: one captured row, one delayed event. */
    public const CHECK_HOOK = 'gr_cart_recover_check';

    /** Mail template option (autoload=no; content, not a switch). */
    public const TEMPLATE_OPTION = 'gr_cart_recovery_template';

    /** Delay bounds in minutes; every read clamps into them. */
    public const DELAY_MIN = 5;
    public const DELAY_MAX = 120;

    /** Template ceiling in characters. */
    public const TEMPLATE_MAX = 2000;

    /** Retry delay for a refused mail. */
    public const RETRY_SECONDS = 21600;

    /** CRM system tag attached on unsubscribe (ADR-0013 vocabulary). */
    public const UNSUB_TAG = 'sys:unsubscribed';

    /** The URL parameter carrying the 64hex row token. */
    public const RECOVER_PARAM = 'gr_recover';

    /** The URL parameter carrying the same token for unsubscribes. */
    public const UNSUBSCRIBE_PARAM = 'gr_unsubscribe';

    /**
     * Hook registration: the queue consumer and the front-end link
     * router.
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( self::CHECK_HOOK, array( self::class, 'check_row' ), 10, 2 );
        add_action( 'template_redirect', array( self::class, 'handle_link' ), 5, 0 );
    }

    /**
     * Schedules the delayed check for one freshly captured row.
     *
     * @param int $row_id Abandonment row id.
     * @return void
     */
    public static function schedule( int $row_id ): void {
        if ( $row_id < 1 ) {
            return;
        }

        Gr_Queue::enqueue( self::CHECK_HOOK, array( $row_id ), self::delay_minutes() * 60 );
    }

    /**
     * The owner's delay, clamped into the recorded bounds at every
     * read so a stale stored value cannot reach the queue.
     *
     * @return int Minutes.
     */
    public static function delay_minutes(): int {
        $minutes = (int) gr()->settings()->get( 'cart_recovery_delay', 15 );

        return max( self::DELAY_MIN, min( self::DELAY_MAX, $minutes ) );
    }

    /**
     * The delayed check: gates first, then the mutex, then the mail.
     * Every refusal is silent by design — an in-transit order or a
     * withdrawn consent is a person, not a send we missed.
     *
     * @param int $row_id  Abandonment row id.
     * @param int $attempt 1 on the first fire, 2 on the retry.
     * @return void
     */
    public static function check_row( int $row_id, int $attempt = 1 ): void {
        $repo = new Gr_Cart_Abandonment_Repository();
        $row  = $repo->row_for_id( $row_id );

        if ( array() === $row ) {
            return;
        }

        // Gate 1: still captured. Anything else — abandoned, failed,
        // recovered, or a link already clicked — is not ours to mail.
        if ( Gr_Cart_Abandonment_Repository::STATUS_CAPTURED !== (string) $row['status'] ) {
            return;
        }

        // Gate 2: the session never converted. A bound conversion is
        // the purchase, whatever the checkout looked like.
        if ( ( new Gr_Conversion_Repository() )->bound_for_session( (string) $row['session_id'] ) > 0 ) {
            return;
        }

        // Gate 3: no settled order under the email. The row's own
        // order must still be pending — on-hold is a bank transfer in
        // transit, processing is paid — and no other order for the
        // address may have settled either. Queue context, so the
        // front-end query budget does not apply.
        $email = Gr_Secrets::decrypt( (string) $row['email_enc'] );

        if ( ! is_string( $email ) || '' === $email ) {
            // The envelope no longer opens (a rotated salt): there is
            // no address to deliver to, so the row parks as failed
            // where the owner can see why.
            $repo->mark_failed( $row_id );
            return;
        }

        if ( ! self::no_settled_order( $row, $email ) ) {
            return;
        }

        // Gate 4: the consent snapshot taken at capture still says yes.
        if ( 1 !== (int) $row['consent'] ) {
            return;
        }

        // Gate 5: never again means never again (ADR-0015 D4).
        if ( $repo->is_unsubscribed( (string) $row['email_hash'] ) ) {
            return;
        }

        // The mutex: only a still-captured row flips, and exactly one
        // racing check wins the flip.
        if ( ! $repo->mark_abandoned( $row_id ) ) {
            return;
        }

        if ( self::send( $row, $email ) ) {
            return;
        }

        if ( 1 === $attempt ) {
            // The flip was the send's permission and the send never
            // happened, so the row returns to captured: an honest
            // not-yet-mailed state the retry can win again. Left as
            // abandoned, the still-captured gate would refuse the
            // retry forever and the row could never park as failed.
            $repo->revert_abandoned( $row_id );

            // One retry, six hours out; the gates will re-run against
            // whatever changed in between.
            Gr_Queue::enqueue( self::CHECK_HOOK, array( $row_id, 2 ), self::RETRY_SECONDS );
            return;
        }

        $repo->mark_failed( $row_id );
    }

    /**
     * Gate 3: the row's own order (when the order path captured) is
     * still pending, and no order for the email has settled. "Settled"
     * is deliberately wide — paid, completed, in transit, refunded, or
     * cancelled all mean the person finished (or consciously ended) a
     * checkout, and none of them is an abandonment to mail about.
     *
     * @param array<string, mixed> $row   Abandonment row.
     * @param string               $email Decrypted billing email.
     * @return bool True when no settled order stands in the way.
     */
    private static function no_settled_order( array $row, string $email ): bool {
        if ( ! class_exists( 'WooCommerce', false ) || ! function_exists( 'wc_get_orders' ) ) {
            // No target, no order path, nothing to check: the blur
            // capture alone decides.
            return true;
        }

        try {
            $own_id = (int) $row['order_id'];
            if ( $own_id > 0 && function_exists( 'wc_get_order' ) ) {
                $order = wc_get_order( $own_id );
                if ( false !== $order && ! $order->has_status( 'pending' ) ) {
                    return false;
                }
            }

            $orders = wc_get_orders(
                array(
                    'billing_email' => $email,
                    'status'        => array( 'processing', 'completed', 'on-hold', 'refunded', 'cancelled' ),
                    'limit'         => 1,
                )
            );

            return array() === $orders;
        } catch ( \Throwable $error ) {
            do_action( 'gr_adapter_error', 'cart_recovery', $error );

            // A broken order store must not manufacture an abandonment
            // mail; the retry gives the store time to heal.
            return false;
        }
    }

    /**
     * One recovery mail through the site's own wp_mail.
     *
     * @param array<string, mixed> $row   Abandonment row (post-mutex read state).
     * @param string               $email Decrypted recipient.
     * @return bool
     */
    private static function send( array $row, string $email ): bool {
        $subject = str_replace( '{site}', self::site_name(), (string) gr()->settings()->get( 'cart_recovery_subject', '' ) );
        $subject = '' === trim( $subject ) ? self::default_subject() : $subject;

        $body = self::render_body( $row );

        return (bool) wp_mail(
            $email,
            $subject,
            $body,
            'Content-Type: text/html; charset=UTF-8'
        );
    }

    /**
     * The mail body from the owner's template (or the default), every
     * placeholder filled and the whole result passed through
     * wp_kses_post: the owner writes markup, the person reads markup,
     * and nothing else rides along.
     *
     * @param array<string, mixed> $row Abandonment row.
     * @return string
     */
    public static function render_body( array $row ): string {
        $template = (string) get_option( self::TEMPLATE_OPTION, '' );
        if ( ! self::template_is_valid( $template ) ) {
            $template = self::default_template();
        }

        $token = (string) $row['recovery_token'];

        $replacements = array(
            '{site}'        => esc_html( self::site_name() ),
            '{items}'       => self::items_summary( (string) $row['cart_json'] ),
            '{total}'       => esc_html( (string) $row['currency'] . ' ' . (string) $row['total'] ),
            '{recover_url}' => esc_url( home_url( '/?' . self::RECOVER_PARAM . '=' . $token ) ),
            '{unsubscribe}' => esc_url( home_url( '/?' . self::UNSUBSCRIBE_PARAM . '=' . $token ) ),
        );

        return wp_kses_post( str_replace( array_keys( $replacements ), $replacements, $template ) );
    }

    /**
     * A template is usable when it is short enough and carries both
     * links: a recovery mail without its two links is a letter that
     * cannot do its job or be declined, so it never reaches a mailbox.
     *
     * @param string $template Candidate template.
     * @return bool
     */
    public static function template_is_valid( string $template ): bool {
        if ( '' === trim( $template ) || strlen( $template ) > self::TEMPLATE_MAX ) {
            return false;
        }

        return false !== strpos( $template, '{recover_url}' ) && false !== strpos( $template, '{unsubscribe}' );
    }

    /**
     * The default body: honest, short, both links, no urgency theater.
     *
     * @return string
     */
    public static function default_template(): string {
        return '<p>' . esc_html__( 'You left items in your cart at {site}.', 'greenpng' ) . '</p>' .
            '<p>{items} — {total}</p>' .
            '<p><a href="{recover_url}">' . esc_html__( 'Finish your purchase', 'greenpng' ) . '</a></p>' .
            '<p>' . esc_html__( 'Prefer no emails like this?', 'greenpng' ) . ' <a href="{unsubscribe}">' . esc_html__( 'Unsubscribe', 'greenpng' ) . '</a></p>';
    }

    /**
     * The default subject.
     *
     * @return string
     */
    public static function default_subject(): string {
        return __( 'Your cart at {site}', 'greenpng' );
    }

    /**
     * The site's own name, for the {site} placeholder.
     *
     * @return string
     */
    private static function site_name(): string {
        return (string) get_bloginfo( 'name' );
    }

    /**
     * The stored cart as a one-line item list for the mail.
     *
     * @param string $cart_json Stored line-item JSON.
     * @return string
     */
    private static function items_summary( string $cart_json ): string {
        if ( '' === $cart_json ) {
            return '';
        }

        $items = json_decode( $cart_json, true );
        if ( ! is_array( $items ) || array() === $items ) {
            return '';
        }

        $parts = array();
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $quantity = isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
            $name     = isset( $item['name'] ) ? (string) $item['name'] : '';

            if ( '' === $name || $quantity < 1 ) {
                continue;
            }

            $parts[] = esc_html( $quantity . ' × ' . $name );
        }

        return implode( ', ', $parts );
    }

    /**
     * Front-end link router: the recovery link restores the cart and
     * hands the shopper the checkout; the unsubscribe link records
     * never-again and answers with a plain confirmation. Both read
     * the row by its 64hex token, which the repository refuses in
     * any other shape.
     *
     * @return void
     */
    public static function handle_link(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public link redemption carries no state change beyond its own token row; there is no nonce to share with a mailbox.
        $recover = isset( $_GET[ self::RECOVER_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::RECOVER_PARAM ] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same link-borne token, same reasoning.
        $leave = isset( $_GET[ self::UNSUBSCRIBE_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::UNSUBSCRIBE_PARAM ] ) ) : '';

        $repo = new Gr_Cart_Abandonment_Repository();

        if ( '' !== $recover ) {
            $row = $repo->row_for_token( $recover );
            if ( array() !== $row && self::redeem_row( $row ) ) {
                $url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' );
                wp_safe_redirect( $url );
                exit;
            }

            return;
        }

        if ( '' !== $leave ) {
            $row = $repo->row_for_token( $leave );
            if ( array() !== $row ) {
                self::unsubscribe_row( $row );
                self::render_unsubscribe_page();
                exit;
            }
        }
    }

    /**
     * Link redemption: put the stored line items back into the
     * shopper's cart through the target's public API, and record the
     * attempt. A click is a click, not a recovery — the order
     * writeback has the final word (ADR-0015 D3).
     *
     * @param array<string, mixed> $row Abandonment row.
     * @return bool True when the cart was restored.
     */
    public static function redeem_row( array $row ): bool {
        if ( ! class_exists( 'WooCommerce', false ) || ! function_exists( 'wc_load_cart' ) || ! function_exists( 'WC' ) ) {
            return false;
        }

        try {
            wc_load_cart();

            $cart = is_object( WC() ) && isset( WC()->cart ) ? WC()->cart : null;
            if ( ! is_object( $cart ) || ! method_exists( $cart, 'add_to_cart' ) ) {
                return false;
            }

            $items = json_decode( (string) $row['cart_json'], true );
            if ( ! is_array( $items ) ) {
                $items = array();
            }

            if ( method_exists( $cart, 'empty_cart' ) ) {
                $cart->empty_cart();
            }

            $added = 0;
            foreach ( $items as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }

                $product_id   = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
                $variation_id = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
                $quantity     = isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;

                if ( $product_id < 1 || $quantity < 1 ) {
                    continue;
                }

                if ( false !== $cart->add_to_cart( $product_id, $quantity, $variation_id ) ) {
                    ++$added;
                }
            }

            if ( 0 === $added ) {
                return false;
            }

            ( new Gr_Cart_Abandonment_Repository() )->mark_attempted( (int) $row['id'] );

            return true;
        } catch ( \Throwable $error ) {
            do_action( 'gr_adapter_error', 'cart_recovery', $error );

            return false;
        }
    }

    /**
     * Unsubscribe: the hash goes on the never-again list, and on the
     * first unsubscribe only, the CRM contact (when one exists) gets
     * the system tag — never re-attached after you remove it, like
     * every system tag.
     *
     * @param array<string, mixed> $row Abandonment row.
     * @return void
     */
    public static function unsubscribe_row( array $row ): void {
        $repo = new Gr_Cart_Abandonment_Repository();
        $hash = (string) $row['email_hash'];

        if ( ! $repo->add_unsubscribed( $hash ) ) {
            return;
        }

        $email = Gr_Secrets::decrypt( (string) $row['email_enc'] );
        if ( ! is_string( $email ) || '' === $email ) {
            return;
        }

        $contact_id = ( new Gr_Contact_Repository() )->id_for_email( $email );
        if ( $contact_id > 0 ) {
            ( new Gr_Contact_Repository() )->attach_tag(
                $contact_id,
                self::UNSUB_TAG,
                __( 'Unsubscribed from recovery mail', 'greenpng' ),
                true
            );
        }
    }

    /**
     * The unsubscribe confirmation: a minimal page of the site's own
     * chrome, one sentence, nothing else.
     *
     * @return void
     */
    private static function render_unsubscribe_page(): void {
        nocache_headers();
        status_header( 200 );
        header( 'Content-Type: text/html; charset=utf-8' );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static structural markup; every dynamic piece is escaped below.
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' .
            esc_html__( 'Unsubscribed', 'greenpng' ) .
            '</title></head><body><p>' .
            esc_html__( 'You will not receive cart recovery emails from this site again.', 'greenpng' ) .
            '</p></body></html>';
    }
}
