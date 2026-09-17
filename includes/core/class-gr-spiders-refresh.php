<?php
/**
 * Spider-segment refresh job (docs/07 §5.4, docs/19 V16): the one
 * outbound this layer ever makes, and only because the owner asked —
 * the manual button enqueues this job, the weekly cron (an explicit
 * opt-in that registers when armed and cancels when disarmed) enqueues
 * the same job, and the job pulls the three official crawler segment
 * tables through the unified client, merges them into the uploads
 * copy, and leaves the previous table untouched on every failure
 * path. All three official tables speak the same ipv4Prefix/ipv6Prefix
 * vocabulary (verified on the wire 2026-09-16, endpoints in the URL
 * constants; the documented Bing generation with bare ipv4/ipv6 keys
 * is retired), and the per-source parse stays strict: a key swap
 * means lost segments, never a crash — and the audit counts say so.
 *
 * The lookup side never runs here: Gr_Spider_Segments answers from
 * the published file, and the confidence consumer demands the double
 * proof (segment hit plus the agent naming the same engine), so a
 * subscription alone can never mint a crawler verdict.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Storage\Gr_Audit_Repository;

/**
 * Owner-asked segment refresh, dispatched through Gr_Queue.
 */
final class Gr_Spiders_Refresh {

    /** Queue hook the manual job runs under. */
    public const HOOK = 'gr_spiders_refresh';

    /** The recurring wp-cron event the weekly opt-in owns; separate from HOOK so a manual job survives a toggle-off. */
    public const WEEKLY = 'gr_spiders_weekly';

    /** Dedupe flag while a queued refresh has not finished. */
    public const PENDING = 'gr_spiders_refresh_pending';

    /** The pending flag self-heals after an hour, so a lost job cannot pin the button. */
    private const PENDING_TTL = 3600;

    /** Google's official crawler segment table (googlebot.json, the documented Googlebot list). */
    public const URL_GOOGLE = 'https://developers.google.com/search/apis/ipranges/googlebot.json';

    /** Bing's official crawler segment table. */
    public const URL_BING = 'https://www.bing.com/toolbox/bingbot.json';

    /** Apple's official crawler segment table, linked from Apple's Applebot page. */
    public const URL_APPLE = 'http://search.developer.apple.com/applebot.json';

    /** Settings key of the weekly opt-in. */
    public const TOGGLE = 'spider_segments_weekly';

    /**
     * Registers both job callbacks and heals a lost weekly schedule
     * for an armed site; a disarmed site is healed by the toggle
     * write instead, because a per-request cron clear would be a
     * write on every front-end request.
     *
     * @return void
     */
    public static function register(): void {
        add_action( self::HOOK, array( self::class, 'run' ) );
        add_action( self::WEEKLY, array( self::class, 'run_weekly' ) );

        if ( 1 === (int) gr()->settings()->get( self::TOGGLE ) ) {
            self::ensure_weekly();
        }
    }

    /**
     * Schedules the weekly event when missing.
     *
     * @return void
     */
    private static function ensure_weekly(): void {
        if ( ! wp_next_scheduled( self::WEEKLY ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::WEEKLY );
        }
    }

    /**
     * The toggle write's schedule arm: register on arm, cancel on
     * disarm. The cron event only ever enqueues the job, so the wire
     * call itself still rides the queue either way.
     *
     * @param int $on 1 or 0.
     * @return void
     */
    public static function set_weekly( int $on ): void {
        if ( 1 === $on ) {
            self::ensure_weekly();
            return;
        }

        wp_clear_scheduled_hook( self::WEEKLY );
    }

    /**
     * The weekly event body: the opt-in gates its own schedule, so a
     * disarmed site heals a stale schedule instead of downloading.
     *
     * @return void
     */
    public static function run_weekly(): void {
        if ( 1 !== (int) gr()->settings()->get( self::TOGGLE ) ) {
            wp_clear_scheduled_hook( self::WEEKLY );

            return;
        }

        self::enqueue();
    }

    /**
     * Queues one refresh unless one is already on its way. Called
     * only from the page's gated POST arm and the weekly event.
     *
     * @return bool False when a refresh is already pending.
     */
    public static function enqueue(): bool {
        if ( false !== get_transient( self::PENDING ) ) {
            return false;
        }

        set_transient( self::PENDING, time(), self::PENDING_TTL );
        Gr_Queue::enqueue( self::HOOK, array(), 0 );

        return true;
    }

    /**
     * The job body: pull the three official tables, merge per
     * engine, publish atomically, and report. Failures keep the
     * current table and never retry beyond the client's own ladder.
     *
     * @return void
     */
    public static function run(): void {
        $before = Gr_Spider_Segments::status();

        $merged   = array(
            'google' => array(),
            'bing'   => array(),
            'apple'  => array(),
        );
        $answered = array();

        foreach ( self::source_urls() as $source => $url ) {
            $json = self::fetch_json( $url );
            if ( array() === $json ) {
                continue;
            }

            // A source that answers counts, even when its table holds
            // zero valid segments: an honest empty is a contribution,
            // and the audit distinguishes it from a failed fetch.
            $merged[ $source ] = self::parse_json( $json );
            $answered[]        = $source;
        }

        if ( array() === $answered ) {
            // Nothing answered: keep the current table and let the
            // button say so. The breaker state lives in the client.
            self::finish(
                $before,
                array(
                    'result' => 'failed',
                    'reason' => 'no_source',
                )
            );
            delete_transient( self::PENDING );

            return;
        }

        $total = 0;
        foreach ( $merged as $segments ) {
            $total += count( $segments );
        }

        if ( 0 === $total ) {
            // Every answer parsed to zero valid segments, which means
            // the publishers moved their vocabulary; wiping a good
            // table on that day would be worse than keeping it.
            self::finish(
                $before,
                array(
                    'result' => 'failed',
                    'reason' => 'empty',
                )
            );
            delete_transient( self::PENDING );

            return;
        }

        $table = array(
            'built'    => gmdate( 'Y-m-d' ),
            'segments' => $merged,
        );

        if ( false === self::publish( $table ) ) {
            self::finish(
                $before,
                array(
                    'result' => 'failed',
                    'reason' => 'publish',
                )
            );
            delete_transient( self::PENDING );

            return;
        }

        Gr_Spider_Segments::reset_for_tests();

        $after = array( 'result' => 'refreshed' );
        foreach ( $answered as $source ) {
            $after[ $source ] = count( $merged[ $source ] );
        }
        $after['total'] = $total;

        self::finish( $before, $after );
        delete_transient( self::PENDING );
    }

    /**
     * The three official source URLs, behind one filter so a probe or
     * an owner with strong opinions can re-point them without
     * patching; the merge semantics do not care where the tables come
     * from, only that they parse.
     *
     * @return array<string, string> Source word to URL.
     */
    private static function source_urls(): array {
        return (array) apply_filters(
            'gr_spiders_source_urls',
            array(
                'google' => self::URL_GOOGLE,
                'bing'   => self::URL_BING,
                'apple'  => self::URL_APPLE,
            )
        );
    }

    /**
     * One JSON fetch through the unified client; failures return an
     * empty array and leave the retry ladder to the client's own
     * backoff bookkeeping.
     *
     * @param string $url Source URL.
     * @return array<string, mixed> Parsed body, or an empty array.
     */
    private static function fetch_json( string $url ): array {
        $response = Gr_Http_Client::get( Gr_Http_Client::SERVICE_SPIDERS, $url );

        if ( is_wp_error( $response ) ) {
            return array();
        }

        $parsed = json_decode( (string) $response['body'], true );

        return is_array( $parsed ) ? $parsed : array();
    }

    /**
     * Extracts one table's CIDR vocabulary. All three official
     * sources speak the same ipv4Prefix/ipv6Prefix family (verified
     * on the wire), and the parse stays strict: a vendor that drifts
     * to a new vocabulary drops rows here, which the audit counts
     * and the empty-failure guard surface, instead of half-parsing
     * into the published table.
     *
     * @param array<string, mixed> $json Parsed body.
     * @return array<int, string> CIDR lines, deduped.
     */
    private static function parse_json( array $json ): array {
        $keys = array( 'ipv4Prefix', 'ipv6Prefix' );

        $out = array();
        foreach ( (array) ( $json['prefixes'] ?? array() ) as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            foreach ( $keys as $key ) {
                if ( isset( $row[ $key ] ) && is_string( $row[ $key ] ) && self::is_cidr( $row[ $key ] ) ) {
                    $out[] = trim( $row[ $key ] );
                }
            }
        }

        return array_values( array_unique( $out ) );
    }

    /**
     * Shape gate for one published segment: an address and a family-
     * sane prefix. The matcher still validates strictly at lookup
     * time, so this gate exists for the published file, which an
     * owner can open — it should read like a segment table.
     *
     * @param string $candidate Raw value from a source table.
     * @return bool
     */
    private static function is_cidr( string $candidate ): bool {
        $candidate = trim( $candidate );
        $slash     = strpos( $candidate, '/' );
        if ( false === $slash ) {
            return false;
        }

        $net    = substr( $candidate, 0, (int) $slash );
        $prefix = substr( $candidate, (int) $slash + 1 );

        // ctype_digit() answers false for the empty string too, so
        // one check carries both the non-numeric and the
        // missing-prefix cases.
        if ( ! ctype_digit( $prefix ) ) {
            return false;
        }

        if ( false === filter_var( $net, FILTER_VALIDATE_IP ) ) {
            return false;
        }

        return (int) $prefix <= ( false === strpos( $net, ':' ) ? 32 : 128 );
    }

    /**
     * Atomic publish: write the return-array file beside its target,
     * then rename over it, so a lookup never sees a half-written
     * table. True on success.
     *
     * @param array{built: string, segments: array<string, array<int, string>>} $data The merged table.
     * @return bool
     */
    private static function publish( array $data ): bool {
        $uploads = wp_upload_dir();
        $dir     = rtrim( (string) $uploads['basedir'], '/' ) . '/' . Gr_Spider_Segments::UPLOAD_SUBDIR;

        if ( ! is_dir( $dir ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- the uploads segment directory is this feature's own data location, created once per site by an owner-asked job.
            mkdir( $dir, 0755, true );
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- var_export IS the file format: the table is a PHP return-array, the same publish contract as the packed cloud ranges.
        $body = '<?php // Generated by GreenPNG: the search-engine spider segment table. Do not edit; the next refresh replaces this file.' . "\nreturn " . var_export( $data, true ) . ";\n";

        $tmp = $dir . '/' . Gr_Spider_Segments::OUT_FILE . '.tmp';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_put_contents -- admin refresh arm, never a front-end request; the temp-then-rename publish keeps a partial write from shadowing a good table.
        $written = file_put_contents( $tmp, $body );
        if ( false === $written || ! is_dir( $dir ) ) {
            if ( false !== $written ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing our own just-written temp file on the failed-publish path.
                unlink( $tmp );
            }

            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- the rename is the atomic publish.
        if ( ! rename( $tmp, $dir . '/' . Gr_Spider_Segments::OUT_FILE ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing our own temp file after a failed publish.
            unlink( $tmp );

            return false;
        }

        return true;
    }

    /**
     * One audit row per finished refresh attempt; counts and outcome
     * words only, never endpoints.
     *
     * @param array<string, mixed>      $before Pre-refresh status summary.
     * @param array<string, int|string> $after  Outcome vocabulary.
     * @return void
     */
    private static function finish( array $before, array $after ): void {
        $audit = new Gr_Audit_Repository();
        $audit->log(
            'refresh',
            'spider_segments',
            'spider_segments',
            $before,
            $after,
            0
        );
    }
}
