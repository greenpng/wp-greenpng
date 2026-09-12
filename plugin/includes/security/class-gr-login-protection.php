<?php
/**
 * Login brute-force protection (docs/13 W7, docs/10 §4): failures are
 * counted per address+username pair in a transient; reaching the
 * threshold locks the address with a gradient duration that doubles
 * per lockout round and caps at a day. The default action mode is
 * record-only — counting, locking, and logging all happen, but a
 * locked address is only denied when the site owner escalates the
 * security_action_mode setting; the allow list and the W5 CLI stay
 * the recovery valves either way.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Request;

/**
 * Static counter/lock engine behind gr_check_login_lockout() and
 * gr_record_login_failure(), plus the wp_login_failed wiring.
 */
final class Gr_Login_Protection {

    /** Failure-counter key namespace. */
    private const FAILS_PREFIX = 'gr_login_fails_';

    /** Lockout-round key namespace. */
    private const ROUNDS_PREFIX = 'gr_login_locks_';

    /** Failure window: how long one address+username count survives. */
    private const FAILS_WINDOW = 900;

    /** Gradient cap per lockout. */
    private const LOCK_CEILING = 86400;

    /** Round-memory horizon: gradient relaxes after a quiet day. */
    private const ROUNDS_WINDOW = 86400;

    /** Logged rule id for failed attempts. */
    public const RULE_FAIL = 'login_fail';

    /** Logged rule id for lockout triggers and locked-out attempts. */
    public const RULE_LOCKOUT = 'login_lockout';

    /**
     * Hook wiring: failures are recorded from wp_login_failed, and the
     * authenticate gate runs after core's own checks (priority 30) so
     * the record-only default never interferes with credentials.
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( 'wp_login_failed', array( self::class, 'handle_failure' ), 10, 1 );
        add_filter( 'authenticate', array( self::class, 'gate' ), 30, 1 );
    }

    /**
     * The wp_login_failed callback: count the failure for the live
     * request's address. An empty username (core fires it for blank
     * submits) still counts — the address is the protection target.
     *
     * @param string $username The name that failed.
     * @return void
     */
    public static function handle_failure( $username ): void {
        if ( ! self::enabled() ) {
            return;
        }

        self::record_failure( is_scalar( $username ) ? (string) $username : '', Gr_Ip_Resolver::resolve() );
    }

    /**
     * The authenticate gate, registered with one accepted argument
     * because the lock judges the request's address, not the
     * credentials. In the default 'log' mode it is a pass-through
     * that only records attempts made while locked (they fold under
     * the lockout row); 'block' mode denies them.
     *
     * @param mixed $user User object or error from earlier filters.
     * @return mixed Unchanged $user, or a denial WP_Error in block mode.
     */
    public static function gate( $user ) {
        if ( ! self::enabled() || ! self::is_locked_now() ) {
            return $user;
        }

        $ip = Gr_Ip_Resolver::resolve();

        gr_log_security_event(
            $ip,
            self::RULE_LOCKOUT,
            Gr_Request::path(),
            Gr_Request::user_agent(),
            'attempt while locked'
        );

        if ( 'block' === (string) gr()->settings()->get( 'security_action_mode' ) ) {
            return new \WP_Error(
                'gr_login_locked',
                __( 'Too many failed sign-in attempts. Please try again later.', 'greenpng' ),
                array( 'status' => 403 )
            );
        }

        return $user;
    }

    /**
     * Lockout judgment for one address+username pair. The allow list
     * wins first, so a trusted address is never reported locked even
     * mid-lockout.
     *
     * @param string $username Attempted username.
     * @param string $ip       Client address as text.
     * @return array{locked: bool, remaining: int, failures: int, threshold: int, round: int}
     */
    public static function check_lockout( string $username, string $ip ): array {
        $threshold = self::threshold();

        $state = array(
            'locked'    => false,
            'remaining' => 0,
            'failures'  => 0,
            'threshold' => $threshold,
            'round'     => (int) self::round_number( $ip ),
        );

        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return $state;
        }

        $state['failures'] = self::transient_int( self::fails_key( $ip, $username ) );

        if ( Gr_Access_Rules::is_trusted_ip( $ip ) ) {
            return $state;
        }

        if ( Gr_Temp_Bans::is_locked( $ip ) ) {
            $state['locked']    = true;
            $state['remaining'] = Gr_Temp_Bans::lock_remaining( $ip );
        }

        return $state;
    }

    /**
     * Records one failed attempt: the per-pair counter climbs within
     * its window, and reaching the threshold locks the address for the
     * gradient duration of its next lockout round, logs the trigger,
     * and clears the counter so the next round starts fresh.
     *
     * @param string $username Attempted username.
     * @param string $ip       Client address as text.
     * @return void
     */
    public static function record_failure( string $username, string $ip ): void {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return;
        }

        $threshold = self::threshold();
        $count     = self::transient_int( self::fails_key( $ip, $username ) ) + 1;

        gr_log_security_event(
            $ip,
            self::RULE_FAIL,
            Gr_Request::path(),
            Gr_Request::user_agent(),
            'failed sign-in for ' . $username . ' (' . $count . '/' . $threshold . ')'
        );

        if ( $count < $threshold ) {
            set_transient( self::fails_key( $ip, $username ), $count, self::FAILS_WINDOW );

            return;
        }

        // Threshold reached: lock the address for its gradient duration.
        // A trusted address skips the whole machinery — no lock, no
        // round climb — so testing from an allowed address never primes
        // a steeper gradient for later.
        if ( Gr_Access_Rules::is_trusted_ip( $ip ) ) {
            delete_transient( self::fails_key( $ip, $username ) );

            return;
        }

        $round = (int) self::round_number( $ip ) + 1;
        $ttl   = min( self::base_seconds() * 2 ** ( $round - 1 ), self::LOCK_CEILING );

        Gr_Temp_Bans::block(
            $ip,
            'login lockout round ' . $round,
            $ttl
        );

        gr_log_security_event(
            $ip,
            self::RULE_LOCKOUT,
            Gr_Request::path(),
            Gr_Request::user_agent(),
            'lockout round ' . $round . ' (' . $threshold . ' failures for ' . $username . ')'
        );

        set_transient( self::rounds_key( $ip ), $round, self::ROUNDS_WINDOW );
        delete_transient( self::fails_key( $ip, $username ) );
    }

    /**
     * Whether the live request's address is currently locked; a lock
     * placed through any username pair gates the address.
     *
     * @return bool
     */
    private static function is_locked_now(): bool {
        $ip = Gr_Ip_Resolver::resolve();

        if ( Gr_Access_Rules::is_trusted_ip( $ip ) ) {
            return false;
        }

        return Gr_Temp_Bans::is_locked( $ip );
    }

    /**
     * The module runs under the master security fuse (W11).
     *
     * @return bool
     */
    private static function enabled(): bool {
        return Gr_Security_Gate::active();
    }

    /**
     * Failure threshold from settings, clamped to at least 2.
     *
     * @return int
     */
    private static function threshold(): int {
        return max( 2, (int) gr()->settings()->get( 'login_fail_threshold', 5 ) );
    }

    /**
     * Base lockout seconds from settings, clamped to at least 1.
     *
     * @return int
     */
    private static function base_seconds(): int {
        return max( 1, (int) gr()->settings()->get( 'login_lockout_base', 300 ) );
    }

    /**
     * Completed lockout rounds for an address inside the memory window.
     *
     * @param string $ip Address.
     * @return int
     */
    private static function round_number( string $ip ): int {
        return self::transient_int( self::rounds_key( $ip ) );
    }

    /**
     * Numeric transient read: missing, expired, or non-scalar entries
     * read as zero, the only sane floor for counters.
     *
     * @param string $key Transient key.
     * @return int
     */
    private static function transient_int( string $key ): int {
        $value = get_transient( $key );

        if ( false === $value || ! is_scalar( $value ) ) {
            return 0;
        }

        return (int) $value;
    }

    /**
     * Failure-counter key: the pair hash keeps both the address and the
     * username out of the option name.
     *
     * @param string $ip       Address.
     * @param string $username Username.
     * @return string
     */
    private static function fails_key( string $ip, string $username ): string {
        return self::FAILS_PREFIX . md5( $ip . '|' . $username );
    }

    /**
     * Lockout-round key for one address.
     *
     * @param string $ip Address.
     * @return string
     */
    private static function rounds_key( string $ip ): string {
        return self::ROUNDS_PREFIX . md5( $ip );
    }
}
