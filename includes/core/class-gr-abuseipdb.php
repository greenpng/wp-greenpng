<?php
/**
 * AbuseIPDB reputation client (docs/07 §5.5, docs/12, mock/06 §3):
 * lookups only ever leave through the unified HTTP client, only for
 * an address that repeatedly failed a login, a registration, or a
 * form post, and only from the queue — never inside the front-end
 * request that observed the failures (iron rule 3).
 *
 * The verdict is advisory by design: it is displayed and audited, and
 * nothing downstream blocks on it by itself. Allow-listed addresses
 * are never queried — a trusted address cannot burn the owner's
 * quota against itself — and one answer caches for 24 hours, so a
 * hammering address costs one request per day at most. The provider's
 * own 429 is the authoritative budget signal: the day's lookups stop
 * locally until the stop transient expires, per docs/07's
 * self-quota rule.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Security\Gr_Access_Rules;
use GreenPNG\Security\Gr_Ip_Resolver;
use GreenPNG\Storage\Gr_Audit_Repository;
use GreenPNG\Storage\Gr_Session_Repository;

/**
 * The one reputation channel: trigger counting, the queued lookup,
 * the 24-hour cache, and the stop-for-the-day budget.
 */
final class Gr_Abuseipdb {

    /** Queue hook for one address reputation lookup. */
    public const LOOKUP_HOOK = 'gr_abuseipdb_lookup';

    /** Reputation answers the audit log can find. */
    public const AUDIT_TYPE = 'reputation';

    /** Official check endpoint, behind the per-service filter. */
    public const ENDPOINT = 'https://api.abuseipdb.com/api/v2/check';

    /** Report window the provider is asked about, in days. */
    public const MAX_AGE_DAYS = 30;

    /** Failure-counter key namespace. */
    private const FAILS_PREFIX = 'gr_abuseipdb_fails_';

    /** How long one failure counts toward the trigger, in seconds. */
    private const FAILS_WINDOW = 3600;

    /** Failures that arm one lookup. */
    private const FAILS_THRESHOLD = 2;

    /** Answer-cache key namespace, one entry per address. */
    private const CACHE_PREFIX = 'gr_abuseipdb_cache_';

    /** How long one answer stays usable, in seconds. */
    private const CACHE_WINDOW = 86400;

    /** The stop-for-the-day budget transient. */
    private const STOP_KEY = 'gr_abuseipdb_budget_stop';

    /**
     * Hook wiring: the login and registration surfaces count from
     * core's own events, the queued lookup runs the wire, and the
     * form surface feeds in from the bridge adapter.
     *
     * @return void
     */
    public static function register(): void {
        add_action( 'wp_login_failed', array( self::class, 'note_login_failure' ), 10, 0 );
        add_filter( 'registration_errors', array( self::class, 'note_register_failure' ), 15, 1 );
        add_action( self::LOOKUP_HOOK, array( self::class, 'run_lookup' ), 10, 1 );
    }

    /**
     * The arm condition: the owner's toggle plus the stored key. A
     * site without credentials never counts, never schedules, and
     * never answers anything.
     *
     * @return bool
     */
    public static function armed(): bool {
        if ( 1 !== (int) gr()->settings()->get( 'abuseipdb_enabled' ) ) {
            return false;
        }

        return '' !== Gr_Secrets::reveal( Gr_Secrets::ABUSEIPDB_KEY_OPTION );
    }

    /**
     * The wp_login_failed listener: one failure counts for the live
     * address while the service is armed. The hook passes the
     * username; the reputation track is keyed to the address alone.
     *
     * @return void
     */
    public static function note_login_failure(): void {
        if ( ! self::armed() ) {
            return;
        }

        self::note_failure( Gr_Ip_Resolver::resolve() );
    }

    /**
     * The registration listener: core's own validation errors count
     * while armed, the same pressure the challenge counter reads.
     *
     * @param mixed $errors WP_Error or null from earlier filters.
     * @return mixed The same value, untouched: counting never amends.
     */
    public static function note_register_failure( $errors ) {
        if ( self::armed() && $errors instanceof \WP_Error && array() !== $errors->get_error_codes() ) {
            self::note_failure( Gr_Ip_Resolver::resolve() );
        }

        return $errors;
    }

    /**
     * The form-bridge feeder: a submission from a visitor the security
     * track already convicted counts for the connecting address, the
     * same evidence the challenge counter uses.
     *
     * @param string $ip         Connecting address as text.
     * @param string $visitor_id Dual-track visitor identity.
     * @return void
     */
    public static function note_form_submission( string $ip, string $visitor_id ): void {
        if ( ! self::armed() || '' === $visitor_id ) {
            return;
        }

        if ( ! ( new Gr_Session_Repository() )->is_bot_for_visitor( $visitor_id ) ) {
            return;
        }

        self::note_failure( $ip );
    }

    /**
     * One failure closer to the lookup: addresses on the allow list
     * never count (a trusted address cannot burn the quota against
     * itself), and reaching the threshold schedules exactly one
     * queued lookup, then clears the pressure — the 24-hour answer
     * cache is what keeps a hammering address from re-arming it.
     *
     * @param string $ip Client address as text.
     * @return void
     */
    public static function note_failure( string $ip ): void {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return;
        }

        if ( Gr_Access_Rules::is_trusted_ip( $ip ) ) {
            return;
        }

        $key   = self::fails_key( $ip );
        $count = self::transient_int( $key ) + 1;

        if ( self::FAILS_THRESHOLD > $count ) {
            set_transient( $key, $count, self::FAILS_WINDOW );

            return;
        }

        delete_transient( $key );
        self::schedule( $ip );
    }

    /**
     * Whether the day's budget is spent and lookups are paused.
     *
     * @return bool
     */
    public static function budget_stopped(): bool {
        return false !== get_transient( self::STOP_KEY );
    }

    /**
     * The last cached answer for one address, or the empty verdict.
     *
     * @param string $ip Client address as text.
     * @return array{score: int, band: string, reports: int, at: int}
     */
    public static function verdict( string $ip ): array {
        $cached = get_transient( self::cache_key( $ip ) );

        if ( is_array( $cached ) && isset( $cached['score'], $cached['band'], $cached['at'] ) ) {
            return array(
                'score'   => (int) $cached['score'],
                'band'    => (string) $cached['band'],
                'reports' => (int) ( $cached['reports'] ?? 0 ),
                'at'      => (int) $cached['at'],
            );
        }

        return array(
            'score'   => -1,
            'band'    => '',
            'reports' => 0,
            'at'      => 0,
        );
    }

    /**
     * The queued lookup: the front-end request that observed the
     * failures is long gone, so this is the only place the wire ever
     * runs. The pre-chain re-validates everything, because state
     * may have changed between the schedule and the run: the
     * service was switched off, the address was allow-listed, the
     * answer arrived from another path, or the budget burned out.
     *
     * @param string $ip Client address as text.
     * @return void
     */
    public static function run_lookup( string $ip ): void {
        if ( ! self::armed() || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return;
        }

        if ( Gr_Access_Rules::is_trusted_ip( $ip ) ) {
            return;
        }

        if ( false !== get_transient( self::cache_key( $ip ) ) ) {
            // A fresh answer already exists: zero outbound, zero audit.
            return;
        }

        if ( self::budget_stopped() ) {
            return;
        }

        $response = Gr_Http_Client::get(
            Gr_Http_Client::SERVICE_ABUSEIPDB,
            self::check_url( $ip ),
            array(
                'headers' => array(
                    'Key'    => Gr_Secrets::reveal( Gr_Secrets::ABUSEIPDB_KEY_OPTION ),
                    'Accept' => 'application/json',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            if ( Gr_Http_Client::ERR_RATE_LIMITED === $response->get_error_code() ) {
                // The provider is the only one who knows the true
                // budget: its 429 stops the day's lookups locally
                // until the transient expires (docs/07 §5.5).
                set_transient( self::STOP_KEY, 1, self::CACHE_WINDOW );
                self::report( $ip, 'rate_limited', 0, '', 0 );
            }

            // Wire failures, an open breaker, or backoff wait: the
            // reputation of the address stays unknown, and the
            // trigger's own counter rebuilds from the next failures.
            return;
        }

        $decoded = json_decode( (string) $response['body'], true );

        if ( ! is_array( $decoded ) || ! isset( $decoded['data']['abuseConfidenceScore'] ) || ! is_numeric( $decoded['data']['abuseConfidenceScore'] ) ) {
            // A 200 with an unusable body answers nothing: no cache,
            // no verdict, nothing downstream could ever act on it.
            return;
        }

        $score   = (int) $decoded['data']['abuseConfidenceScore'];
        $reports = isset( $decoded['data']['totalReports'] ) && is_numeric( $decoded['data']['totalReports'] ) ? (int) $decoded['data']['totalReports'] : 0;
        $band    = self::band_word( $score );

        set_transient(
            self::cache_key( $ip ),
            array(
                'score'   => $score,
                'band'    => $band,
                'reports' => $reports,
                'at'      => time(),
            ),
            self::CACHE_WINDOW
        );

        self::report( $ip, 'ok', $score, $band, $reports );
    }

    /**
     * The band word for one score: what every display layer shows,
     * so no two pages invent their own vocabulary.
     *
     * @param int $score The provider's 0-100 confidence score.
     * @return string 'clean', 'low', 'elevated', or 'high'.
     */
    public static function band_word( int $score ): string {
        if ( $score >= 75 ) {
            return 'high';
        }
        if ( $score >= 50 ) {
            return 'elevated';
        }
        if ( $score > 0 ) {
            return 'low';
        }

        return 'clean';
    }

    /**
     * The full check URL: the endpoint behind the per-service filter
     * (the owner's inspection-proxy seam), then the query.
     *
     * @param string $ip Client address as text.
     * @return string
     */
    private static function check_url( string $ip ): string {
        $endpoint = apply_filters( 'gr_abuseipdb_check_url', self::ENDPOINT );
        $endpoint = is_string( $endpoint ) && '' !== $endpoint ? $endpoint : self::ENDPOINT;

        return add_query_arg(
            array(
                'ipAddress'    => $ip,
                'maxAgeInDays' => (string) self::MAX_AGE_DAYS,
            ),
            $endpoint
        );
    }

    /**
     * Schedules one lookup behind a short delay, so a burst of
     * failures for one address coalesces into a single job.
     *
     * @param string $ip Client address as text.
     * @return void
     */
    private static function schedule( string $ip ): void {
        if ( false !== get_transient( self::cache_key( $ip ) ) || self::budget_stopped() ) {
            return;
        }

        Gr_Queue::enqueue( self::LOOKUP_HOOK, array( $ip ), 60 );
    }

    /**
     * One reputation event into the audit log: the address, the
     * result word, the score, the band, and the report count —
     * never the API key.
     *
     * @param string $ip      Client address as text.
     * @param string $result  Result word.
     * @param int    $score   The provider's score, 0 when unknown.
     * @param string $band    Band word, '' when unknown.
     * @param int    $reports Distinct-report count from the provider.
     * @return void
     */
    private static function report( string $ip, string $result, int $score, string $band, int $reports ): void {
        ( new Gr_Audit_Repository() )->log(
            'abuseipdb',
            self::AUDIT_TYPE,
            $ip,
            array(),
            array(
                'result'  => $result,
                'score'   => $score,
                'band'    => $band,
                'reports' => $reports,
            ),
            0
        );
    }

    /**
     * Failure-counter key: the address digest keeps the raw address
     * out of the option name.
     *
     * @param string $ip Address.
     * @return string
     */
    private static function fails_key( string $ip ): string {
        return self::FAILS_PREFIX . md5( $ip );
    }

    /**
     * Answer-cache key for one address.
     *
     * @param string $ip Address.
     * @return string
     */
    private static function cache_key( string $ip ): string {
        return self::CACHE_PREFIX . md5( $ip );
    }

    /**
     * Numeric transient read: missing, expired, or non-scalar entries
     * read as zero.
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
}
