<?php
/**
 * Temporary IP locks (docs/13 W5, docs/10 §4): transient-backed bans
 * with a TTL, for the burst-and-recover patterns (login failures,
 * surge responses). Permanent bans belong in the gr_access_rules
 * table (W4) — the clamp here keeps the transient store transient.
 * Every observable action fires a hook so the W6 logger and the admin
 * status page can report without this layer persisting anything.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Static lock store behind gr_block_ip() / gr_unblock_ip().
 */
final class Gr_Temp_Bans {

    /** Transient key namespace (hashed address, see key()). */
    private const LOCK_PREFIX = 'gr_block_';

    /** Upper TTL clamp: beyond this a rule-table ban is the right tool. */
    private const TTL_CEILING = 2592000; // 30 days.

    /**
     * Locks an address for a bounded time.
     *
     * @param string $ip     Address to lock.
     * @param string $reason Free-text cause, kept for audit display.
     * @param int    $ttl    Seconds; clamped to 1..30 days.
     * @return bool Whether the lock was placed.
     */
    public static function block( string $ip, string $reason = '', int $ttl = DAY_IN_SECONDS ): bool {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return false;
        }

        $ttl = max( 1, min( $ttl, self::TTL_CEILING ) );

        set_transient(
            self::key( $ip ),
            array(
                'reason'     => substr( sanitize_text_field( $reason ), 0, 191 ),
                'blocked_at' => current_time( 'mysql' ),
                'expires_at' => time() + $ttl,
                'ttl'        => $ttl,
            ),
            $ttl
        );

        do_action( 'gr_temp_ban', $ip, $reason, $ttl );

        return true;
    }

    /**
     * Removes a lock; the allow list is the recovery valve that works
     * even without CLI access, so an address can also be freed by
     * adding it to the allow rules (W4 checks allow first).
     *
     * @param string $ip Address to free.
     * @return bool Whether a lock existed and was removed.
     */
    public static function unblock( string $ip ): bool {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return false;
        }

        $existed = false !== get_transient( self::key( $ip ) );

        delete_transient( self::key( $ip ) );

        if ( $existed ) {
            do_action( 'gr_temp_unban', $ip );
        }

        return $existed;
    }

    /**
     * Whether an address currently holds an unexpired lock. Any
     * non-false value under our key counts — the store shape is ours,
     * so a malformed entry still means "locked".
     *
     * @param string $ip Address to check.
     * @return bool
     */
    public static function is_locked( string $ip ): bool {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return false;
        }

        return false !== get_transient( self::key( $ip ) );
    }

    /**
     * The recorded cause of a lock, for the CLI and status surfaces.
     *
     * @param string $ip Address to look up.
     * @return string Reason text, '' when no lock exists.
     */
    public static function lock_reason( string $ip ): string {
        $lock = get_transient( self::key( $ip ) );

        if ( ! is_array( $lock ) || ! isset( $lock['reason'] ) || ! is_scalar( $lock['reason'] ) ) {
            return '';
        }

        return (string) $lock['reason'];
    }

    /**
     * Seconds left on a lock, derived from the deadline recorded in the
     * lock value at placement time (the transient API never exposes
     * remaining TTL). 0 when no lock is held.
     *
     * @param string $ip Address to look up.
     * @return int
     */
    public static function lock_remaining( string $ip ): int {
        $lock = get_transient( self::key( $ip ) );

        if ( ! is_array( $lock ) || ! isset( $lock['expires_at'] ) || ! is_int( $lock['expires_at'] ) ) {
            return 0;
        }

        return max( 0, $lock['expires_at'] - time() );
    }

    /**
     * Transient key: the hash keeps IPv6 colons out of the option name.
     *
     * @param string $ip Address.
     * @return string
     */
    private static function key( string $ip ): string {
        return self::LOCK_PREFIX . md5( $ip );
    }
}
