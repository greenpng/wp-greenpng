<?php
/**
 * Cart abandonment repository (ADR-0015 D1/D3): one row per checkout
 * session, keyed by session_id, with the email dual-tracked (hash for
 * joins, envelope for the one place a mail needs the plaintext) and
 * the cart as line items only — never addresses or payment details.
 * The recovery token is issued once at capture and never rewritten,
 * so a link already in a mailbox keeps its meaning for the row's
 * whole life.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Storage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Database;
use GreenPNG\Core\Gr_Secrets;

/**
 * CRUD and state transitions over gr_cart_abandonments.
 */
final class Gr_Cart_Abandonment_Repository {

    /** Status: an email (and optionally a cart) is on record. */
    public const STATUS_CAPTURED = 'captured';

    /** Status: the delayed check judged the checkout abandoned. */
    public const STATUS_ABANDONED = 'abandoned';

    /** Status: the recovery link was clicked and the cart restored. */
    public const STATUS_ATTEMPTED = 'attempted';

    /** Status: a bound conversion proved the cart came back. */
    public const STATUS_RECOVERED = 'recovered';

    /** Status: the recovery mail could not be delivered twice. */
    public const STATUS_FAILED = 'failed';

    /** Option holding unsubscribed email hashes (autoload=no). */
    public const UNSUBSCRIBE_OPTION = 'gr_cart_unsubscribes';

    /**
     * Ceiling on the unsubscribe list. Never-again is a discipline,
     * not a bounded cache, but a single option cannot be allowed to
     * grow without end on a mail-heavy site; the oldest entry loses
     * when the ceiling is reached, and the site's own privacy tools
     * can erase the person entirely.
     */
    private const UNSUBSCRIBE_CAP = 10000;

    /** Line items one snapshot may carry; beyond this is junk, not a cart. */
    private const ITEM_CAP = 50;

    /**
     * Captures or refreshes the row for one checkout session. A
     * second visit through the same session folds into the existing
     * row: the cart and total follow the latest state, the first
     * email wins (a typo correction is a different person's mail),
     * and the token stays as issued.
     *
     * @param string            $session_id Checkout session id.
     * @param string            $email      Billing email.
     * @param array<int, mixed> $items      Line items: product_id, variation_id, quantity, name.
     * @param float             $total      Cart or order total.
     * @param string            $currency   Currency code.
     * @param bool              $consent    Marketing consent at capture.
     * @param int               $order_id   Source order id when the order path captured, else 0.
     * @return int Row id, 0 when the email is empty (no mail, no row).
     */
    public function capture( string $session_id, string $email, array $items, float $total, string $currency, bool $consent, int $order_id = 0 ): int {
        global $wpdb;

        if ( '' === $email || '' === $session_id ) {
            return 0;
        }

        $table = Gr_Database::table( 'cart_abandonments' );
        $now   = current_time( 'mysql' );
        $row   = $this->session_row( $session_id );

        if ( array() !== $row ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- REST or checkout-capture write; the row is per-session and the following read is the same request's own state.
            $wpdb->query(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                    "UPDATE {$table}
                        SET cart_json = %s, total = %s, currency = %s, consent = %d, order_id = GREATEST(order_id, %d)
                        WHERE id = %d",
                    array(
                        $this->cart_json( $items ),
                        number_format( round( max( 0.0, $total ), 2 ), 2, '.', '' ),
                        strtoupper( substr( $currency, 0, 3 ) ),
                        $consent ? 1 : 0,
                        $order_id,
                        (int) $row['id'],
                    )
                )
            );

            return (int) $row['id'];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- checkout-capture write, once per session.
        $wpdb->insert(
            $table,
            array(
                'session_id'     => substr( $session_id, 0, 36 ),
                'email_hash'     => Gr_Secrets::hash_pii_sha256( $email ),
                'email_enc'      => Gr_Secrets::encrypt( $email ),
                'cart_json'      => $this->cart_json( $items ),
                'total'          => number_format( round( max( 0.0, $total ), 2 ), 2, '.', '' ),
                'currency'       => strtoupper( substr( $currency, 0, 3 ) ),
                'consent'        => $consent ? 1 : 0,
                'status'         => self::STATUS_CAPTURED,
                'recovery_token' => $this->new_token(),
                'order_id'       => $order_id,
                'captured_at'    => $now,
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s' )
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * One row by id, with cart_json and the fields the recovery
     * worker's gates read.
     *
     * @param int $id Row id.
     * @return array<string, mixed> Row or an empty array.
     */
    public function row_for_id( int $id ): array {
        global $wpdb;

        if ( $id < 1 ) {
            return array();
        }

        $table = Gr_Database::table( 'cart_abandonments' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- queue-context primary-key read.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "SELECT * FROM {$table} WHERE id = %d",
                $id
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : array();
    }

    /**
     * One row by its recovery token. The token is the only bearer
     * credential in the link, so the lookup refuses any shape but the
     * 64 hex it issued — no wildcard, no partial.
     *
     * @param string $token Recovery token from the link.
     * @return array<string, mixed> Row or an empty array.
     */
    public function row_for_token( string $token ): array {
        global $wpdb;

        if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $token ) ) {
            return array();
        }

        $table = Gr_Database::table( 'cart_abandonments' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- link redemption read on the recovery_token index.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "SELECT * FROM {$table} WHERE recovery_token = %s",
                $token
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : array();
    }

    /**
     * The abandoned-check mutex: only a still-captured row may become
     * abandoned, and the WHERE clause is the lock — two queued checks
     * racing on one row produce exactly one winner (ADR-0015 D2).
     *
     * @param int $id Row id.
     * @return bool True when this call won the transition.
     */
    public function mark_abandoned( int $id ): bool {
        global $wpdb;

        $table = Gr_Database::table( 'cart_abandonments' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- guarded state transition; the affected-rows count is the mutex verdict.
        return 1 === (int) $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "UPDATE {$table} SET status = %s, abandoned_at = %s WHERE id = %d AND status = %s",
                array( self::STATUS_ABANDONED, current_time( 'mysql' ), $id, self::STATUS_CAPTURED )
            )
        );
    }

    /**
     * The send-failure half of the mutex: a flip whose mail never
     * went out returns the row to captured, so the one delayed retry
     * can win the flip again like any other check. Without the
     * return, the row would read abandoned while no mail exists and
     * the still-captured gate would starve the retry forever.
     *
     * @param int $id Row id.
     * @return bool True when this call returned an abandoned row.
     */
    public function revert_abandoned( int $id ): bool {
        global $wpdb;

        $table = Gr_Database::table( 'cart_abandonments' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- guarded send-failure transition; the affected-rows count is the verdict.
        return 1 === (int) $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "UPDATE {$table} SET status = %s WHERE id = %d AND status = %s",
                array( self::STATUS_CAPTURED, $id, self::STATUS_ABANDONED )
            )
        );
    }

    /**
     * Link redemption: abandoned (or already-attempted — a re-click)
     * rows record the restore attempt. The final word stays with the
     * order writeback; a click is not a recovery.
     *
     * @param int $id Row id.
     * @return bool True when the row moved to (or stayed at) attempted.
     */
    public function mark_attempted( int $id ): bool {
        global $wpdb;

        $table = Gr_Database::table( 'cart_abandonments' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- idempotent link-redemption transition.
        return 1 === (int) $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "UPDATE {$table} SET status = %s WHERE id = %d AND status IN (%s, %s)",
                array( self::STATUS_ATTEMPTED, $id, self::STATUS_ABANDONED, self::STATUS_ATTEMPTED )
            )
        );
    }

    /**
     * Delivery gave up: the row keeps its place on the status page so
     * the owner can see mail is failing, not silently not sending.
     * Captured rows park here too — an envelope that no longer opens
     * has nothing to send and nothing to retry — while attempted and
     * recovered rows are person outcomes this verdict never overrules.
     *
     * @param int $id Row id.
     * @return bool
     */
    public function mark_failed( int $id ): bool {
        global $wpdb;

        $table = Gr_Database::table( 'cart_abandonments' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- terminal delivery-failure transition.
        return 1 === (int) $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "UPDATE {$table} SET status = %s WHERE id = %d AND status IN (%s, %s)",
                array( self::STATUS_FAILED, $id, self::STATUS_CAPTURED, self::STATUS_ABANDONED )
            )
        );
    }

    /**
     * The honest recovery writeback: a bound conversion under the
     * email closes every open state — a mail that failed to send can
     * still end recovered, because recovery is the order, not the
     * mail (ADR-0015 D3).
     *
     * @param string $email_hash Unprefixed sha-256 of the billing email.
     * @param int    $order_id   The converting order id.
     * @return int Rows moved to recovered.
     */
    public function mark_recovered( string $email_hash, int $order_id ): int {
        global $wpdb;

        if ( 64 !== strlen( $email_hash ) ) {
            return 0;
        }

        $table = Gr_Database::table( 'cart_abandonments' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- post-binding writeback; runs at most once per open row.
        return (int) $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "UPDATE {$table} SET status = %s, order_id = %d, recovered_at = %s
                    WHERE email_hash = %s AND status IN (%s, %s, %s, %s)",
                array(
                    self::STATUS_RECOVERED,
                    $order_id,
                    current_time( 'mysql' ),
                    $email_hash,
                    self::STATUS_CAPTURED,
                    self::STATUS_ABANDONED,
                    self::STATUS_ATTEMPTED,
                    self::STATUS_FAILED,
                )
            )
        );
    }

    /**
     * Failed-delivery summary for the status page: how many recovery
     * mails gave up, and when the newest one did.
     *
     * @return array{count:int, last:string} Count and newest abandoned_at.
     */
    public function failed_summary(): array {
        global $wpdb;

        $table = Gr_Database::table( 'cart_abandonments' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin status read over the status_time index.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "SELECT COUNT(*) AS failed, COALESCE(MAX(abandoned_at), '') AS last_failed FROM {$table} WHERE status = %s",
                self::STATUS_FAILED
            ),
            ARRAY_A
        );

        return array(
            'count' => is_array( $row ) ? (int) ( $row['failed'] ?? 0 ) : 0,
            'last'  => is_array( $row ) ? (string) ( $row['last_failed'] ?? '' ) : '',
        );
    }

    /**
     * The rows one person owns, for the privacy export: status and
     * cart contents, never the hash or the encrypted envelope.
     *
     * @param string $email_hash Unprefixed sha-256 of the email.
     * @return array<int, array<string, mixed>>
     */
    public function rows_for_email_hash( string $email_hash ): array {
        global $wpdb;

        if ( 64 !== strlen( $email_hash ) ) {
            return array();
        }

        $table = Gr_Database::table( 'cart_abandonments' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy-tool read for one person's rows.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "SELECT id, status, cart_json, total, currency, captured_at, abandoned_at, recovered_at FROM {$table}
                    WHERE email_hash = %s ORDER BY captured_at DESC",
                $email_hash
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Erases every row the person owns. Unlike the visitor-chained
     * families, this one is keyed directly by the email hash the rows
     * already carry.
     *
     * @param string $email_hash Unprefixed sha-256 of the email.
     * @return int Rows removed.
     */
    public function erase_for_email_hash( string $email_hash ): int {
        global $wpdb;

        if ( 64 !== strlen( $email_hash ) ) {
            return 0;
        }

        $table = Gr_Database::table( 'cart_abandonments' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy-tool erasure, owner-initiated only.
        return (int) $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "DELETE FROM {$table} WHERE email_hash = %s",
                $email_hash
            )
        );
    }

    /**
     * Whether this email said never again. The check is explicit at
     * send time because the discipline outranks every other gate.
     *
     * @param string $email_hash Unprefixed sha-256 of the email.
     * @return bool
     */
    public function is_unsubscribed( string $email_hash ): bool {
        if ( 64 !== strlen( $email_hash ) ) {
            return false;
        }

        $list = get_option( self::UNSUBSCRIBE_OPTION, array() );

        return is_array( $list ) && in_array( $email_hash, $list, true );
    }

    /**
     * Records a never-again verdict. The hash (not the address) is
     * stored, so the list carries no readable identity.
     *
     * @param string $email_hash Unprefixed sha-256 of the email.
     * @return bool True when this call added the hash; false when it
     *              was already on record.
     */
    public function add_unsubscribed( string $email_hash ): bool {
        if ( 64 !== strlen( $email_hash ) ) {
            return false;
        }

        $list = get_option( self::UNSUBSCRIBE_OPTION, array() );
        $list = is_array( $list ) ? $list : array();

        if ( in_array( $email_hash, $list, true ) ) {
            return false;
        }

        $list[] = $email_hash;

        $size = count( $list );
        while ( $size > self::UNSUBSCRIBE_CAP ) {
            array_shift( $list );
            --$size;
        }

        if ( false === get_option( self::UNSUBSCRIBE_OPTION, false ) ) {
            add_option( self::UNSUBSCRIBE_OPTION, $list, '', 'no' );
            return true;
        }

        update_option( self::UNSUBSCRIBE_OPTION, $list );

        return true;
    }

    /**
     * The newest row for one session, or an empty array.
     *
     * @param string $session_id Session id.
     * @return array<string, mixed>
     */
    private function session_row( string $session_id ): array {
        global $wpdb;

        $table = Gr_Database::table( 'cart_abandonments' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- capture-time upsert read over the session_time index.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "SELECT id, email_hash FROM {$table} WHERE session_id = %s ORDER BY captured_at DESC, id DESC LIMIT 1",
                substr( $session_id, 0, 36 )
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : array();
    }

    /**
     * Line items into the stored cart JSON: product and variation
     * ids, quantity, and the display name — the minimum a restore
     * and a mail's item list need.
     *
     * @param array<int, mixed> $items Raw line items.
     * @return string JSON, '' when nothing survived normalization.
     */
    private function cart_json( array $items ): string {
        $out = array();
        $pad = 0;

        foreach ( $items as $item ) {
            if ( $pad >= self::ITEM_CAP || ! is_array( $item ) ) {
                continue;
            }

            $product_id   = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
            $variation_id = isset( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0;
            $quantity     = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;

            if ( $product_id < 1 || $quantity < 1 ) {
                continue;
            }

            $out[] = array(
                'product_id'   => $product_id,
                'variation_id' => $variation_id,
                'quantity'     => min( 999, $quantity ),
                'name'         => substr( sanitize_text_field( isset( $item['name'] ) ? (string) $item['name'] : '' ), 0, 191 ),
            );

            ++$pad;
        }

        return array() === $out ? '' : (string) wp_json_encode( $out );
    }

    /**
     * A fresh recovery token: 32 random bytes as 64 hex. Issued once
     * per row at capture; collisions at this width are not a thing
     * that happens.
     *
     * @return string
     */
    private function new_token(): string {
        try {
            return bin2hex( random_bytes( 32 ) );
        } catch ( \Throwable $error ) {
            // Every PHP 7.x build the plugin supports ships a CSPRNG;
            // the catch exists so a broken host degrades to a distinct
            // (if weaker) token instead of killing checkout capture.
            return hash( 'sha256', uniqid( 'gr-cart', true ) . (string) wp_rand(), false );
        }
    }
}
