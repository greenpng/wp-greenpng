<?php
/**
 * Forward-confirmed reverse DNS (docs/13 W10, docs/03 §3, docs/10 §4):
 * a crawler's PTR hostname is only trusted when resolving that
 * hostname forward again — A AND AAAA, the reference project's IPv6
 * blind spot — contains the original address. Every failure mode
 * (no PTR, no resolver, resolver error, forward mismatch) reads as
 * "unverified", never as "forged": an address that cannot prove
 * itself is merely unproven, and unproven is never punished. DNS
 * work happens here alone — the front-end path only enqueues
 * (iron rule 3), the queue worker and the daily sweep call verify().
 * Results are transient-cached for 24h, both verdicts alike, so an
 * unverifiable address cannot be re-queried into a load.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Queue;
use GreenPNG\Core\Gr_Request;
use GreenPNG\Storage\Gr_Security_Log_Repository;

/**
 * Static engine behind gr_verify_crawler() and the FCrDNS job paths.
 */
final class Gr_Crawler_Verify {

    /** A forward-confirmed crawler. */
    public const STATUS_VERIFIED = 'verified';

    /** Anything else, including every failure mode. */
    public const STATUS_UNVERIFIED = 'unverified';

    /** Queue hook the worker answers. */
    public const JOB_HOOK = 'gr_crawler_verify';

    /** Result cache lifetime. */
    public const CACHE_TTL = 86400;

    /** How long a queued-but-unrun job suppresses re-enqueueing. */
    public const PENDING_TTL = 900;

    /** Daily sweep row cap: newest claims first, one day's work. */
    public const SWEEP_LIMIT = 25;

    /** Look-back for the daily sweep. */
    public const SWEEP_HOURS = 24;

    /**
     * Verifies one crawler claim, through the 24h cache.
     *
     * @param string $ip Client address as text.
     * @param string $ua Claimed user agent.
     * @return array{status: string, host: string, ip: string, ua: string, checked_at: int}
     */
    public static function verify( string $ip, string $ua ): array {
        $key   = self::cache_key( $ip, $ua );
        $cache = get_transient( $key );

        if ( is_array( $cache ) && isset( $cache['status'] ) ) {
            return $cache;
        }

        $result = self::resolve( $ip, $ua );
        set_transient( $key, $result, self::CACHE_TTL );

        return $result;
    }

    /**
     * The uncached DNS walk. Pure in its inputs, impure only in the
     * resolver calls; kept separate from verify() so the cache layer
     * is the only thing between a caller and a network lookup.
     *
     * @param string $ip Client address as text.
     * @param string $ua Claimed user agent.
     * @return array{status: string, host: string, ip: string, ua: string, checked_at: int}
     */
    public static function resolve( string $ip, string $ua ): array {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return self::result( self::STATUS_UNVERIFIED, '', $ip, $ua );
        }

        // gethostbyaddr() hands back the input on failure, which is
        // indistinguishable from (and means the same as) no PTR.
        $host = gethostbyaddr( $ip );
        if ( ! is_string( $host ) || '' === $host || $host === $ip ) {
            return self::result( self::STATUS_UNVERIFIED, '', $ip, $ua );
        }

        if ( ! function_exists( 'dns_get_record' ) ) {
            return self::result( self::STATUS_UNVERIFIED, $host, $ip, $ua );
        }

        // Both forward families always run: an IPv6 crawler whose
        // confirmation lives only in AAAA must verify, and an IPv4
        // crawler gains nothing from skipping A.
        $records = array_merge(
            self::records( $host, \DNS_A ),
            self::records( $host, \DNS_AAAA )
        );

        foreach ( $records as $record ) {
            $address = isset( $record['ip'] ) && is_scalar( $record['ip'] )
                ? (string) $record['ip']
                : ( isset( $record['ipv6'] ) && is_scalar( $record['ipv6'] ) ? (string) $record['ipv6'] : '' );

            // Byte comparison through the matcher: IPv6 textual
            // variants of the same address must confirm each other.
            if ( '' !== $address && Gr_Ip_Matcher::match_cidr( $ip, $address ) ) {
                return self::result( self::STATUS_VERIFIED, $host, $ip, $ua );
            }
        }

        return self::result( self::STATUS_UNVERIFIED, $host, $ip, $ua );
    }

    /**
     * Hook wiring: the queue worker, the daily sweep, and the
     * first-seen enqueue on the shared findings hook.
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( self::JOB_HOOK, array( self::class, 'handle_job' ), 10, 2 );
        add_action( Gr_Queue::DAILY_HOOK, array( self::class, 'sweep' ) );
        add_action( Gr_Request_Inspector::FINDINGS_HOOK, array( self::class, 'handle_findings' ) );
    }

    /**
     * Front-door subscriber: a scanner-UA finding is a crawler claim
     * worth confirming, so the first claim inside the cache horizon
     * queues one verification job. Payload findings make no crawler
     * claim and enqueue nothing. The request itself never resolves
     * anything (iron rule 3).
     *
     * @param array<int, mixed> $findings Normalized findings rows.
     * @return void
     */
    public static function handle_findings( array $findings ): void {
        foreach ( $findings as $finding ) {
            if ( is_array( $finding ) && 'scanner_ua' === (string) ( $finding['rule_id'] ?? '' ) ) {
                self::queue_if_first_seen( Gr_Ip_Resolver::resolve(), Gr_Request::user_agent() );
                return;
            }
        }
    }

    /**
     * Queue worker: run the verification and let the 24h cache land.
     *
     * @param string $ip Client address as text.
     * @param string $ua Claimed user agent.
     * @return void
     */
    public static function handle_job( string $ip, string $ua ): void {
        self::verify( $ip, $ua );
        delete_transient( 'gr_fcrdns_pending_' . md5( $ip . '|' . $ua ) );
    }

    /**
     * Daily catch-up: recent scanner claims that no cache entry covers
     * get their verification enqueued — the safety net for first-seen
     * enqueues that never ran (a crashed cron, a lost schedule).
     * Newest claims first, capped at one day's work.
     *
     * @return void
     */
    public static function sweep(): void {
        $rows = ( new Gr_Security_Log_Repository() )->recent_scanner_ips( self::SWEEP_HOURS, self::SWEEP_LIMIT );

        foreach ( $rows as $row ) {
            // Length first, then decode: a malformed column value is
            // skipped without ever waking the resolver's warning path.
            $raw = (string) $row['ip'];
            if ( 4 !== strlen( $raw ) && 16 !== strlen( $raw ) ) {
                continue;
            }

            $ip = inet_ntop( $raw );
            if ( false === $ip || '' === $ip ) {
                continue;
            }

            self::queue_if_first_seen( $ip, $row['user_agent'] );
        }
    }

    /**
     * Enqueue gate: an existing 24h verdict or a pending job means no
     * new work; otherwise the job goes out behind a pending flag that
     * collapses repeat claims before the worker has run.
     *
     * @param string $ip Client address as text.
     * @param string $ua Claimed user agent.
     * @return bool True when a job was queued.
     */
    public static function queue_if_first_seen( string $ip, string $ua ): bool {
        $key = self::cache_key( $ip, $ua );
        if ( false !== get_transient( $key ) ) {
            return false;
        }

        $pending = 'gr_fcrdns_pending_' . md5( $ip . '|' . $ua );
        if ( false !== get_transient( $pending ) ) {
            return false;
        }

        set_transient( $pending, time(), self::PENDING_TTL );
        Gr_Queue::enqueue( self::JOB_HOOK, array( $ip, $ua ) );

        return true;
    }

    /**
     * Cache key: the claim is the (address, agent) pair, so two agents
     * from one address are two verifications — the DNS facts repeat,
     * but the verdict rows stay honest about what was claimed.
     *
     * @param string $ip Client address as text.
     * @param string $ua Claimed user agent.
     * @return string
     */
    private static function cache_key( string $ip, string $ua ): string {
        return 'gr_fcrdns_' . md5( $ip . '|' . $ua );
    }

    /**
     * One record family for one hostname, false and odd shapes aside.
     *
     * @param string $host Hostname to resolve.
     * @param int    $type \DNS_A or \DNS_AAAA.
     * @return array<int, array<string, mixed>>
     */
    private static function records( string $host, int $type ): array {
        $found = dns_get_record( $host, $type );
        if ( ! is_array( $found ) ) {
            return array();
        }

        $records = array();
        foreach ( $found as $record ) {
            if ( is_array( $record ) ) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * The fixed result shape: every path returns the same fields, the
     * status vocabulary has exactly two members, and the agent is
     * stored sanitized and capped the way the request layer caps it.
     *
     * @param string $status One of the STATUS_ constants.
     * @param string $host   PTR hostname, '' when none.
     * @param string $ip     Client address as text.
     * @param string $ua     Claimed user agent.
     * @return array{status: string, host: string, ip: string, ua: string, checked_at: int}
     */
    private static function result( string $status, string $host, string $ip, string $ua ): array {
        return array(
            'status'     => $status,
            'host'       => substr( sanitize_text_field( $host ), 0, 253 ),
            'ip'         => $ip,
            'ua'         => substr( sanitize_text_field( $ua ), 0, 512 ),
            'checked_at' => time(),
        );
    }
}
