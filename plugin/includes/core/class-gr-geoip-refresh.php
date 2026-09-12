<?php
/**
 * DB-IP data refresh job (docs/07 §5.7, docs/13 U16): the one
 * outbound the GeoIP feature ever makes, and only because the owner
 * clicked the button on the IP Intelligence page — the click enqueues
 * this job, the job downloads the month-stamped CSV through the
 * unified client, repacks it into the uploads override, and leaves
 * the old data untouched on every failure path (the packer promotes
 * by rename only after both passes succeed). No schedule, no cron,
 * no silent refresh exists anywhere.
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
 * Owner-clicked data refresh, dispatched through Gr_Queue.
 */
final class Gr_Geoip_Refresh {

    /** Queue hook the job runs under. */
    public const HOOK = 'gr_geoip_refresh';

    /** Dedupe flag while a queued refresh has not finished. */
    public const PENDING = 'gr_geoip_refresh_pending';

    /** Download URL pattern; %s is the month stamp, e.g. 2026-09. */
    private const URL = 'https://download.db-ip.com/free/dbip-country-lite-%s.csv.gz';

    /** The pending flag self-heals after an hour, so a lost job cannot pin the button. */
    private const PENDING_TTL = 3600;

    /**
     * Registers the job callback; the queue fires it on every request
     * path because the click may have happened in an earlier one.
     *
     * @return void
     */
    public static function register(): void {
        add_action( self::HOOK, array( self::class, 'run' ) );
    }

    /**
     * The download URL for the current month.
     *
     * @return string
     */
    public static function url(): string {
        return sprintf( self::URL, gmdate( 'Y-m' ) );
    }

    /**
     * Queues one refresh unless one is already on its way. Called
     * only from the page's gated POST arm, which is what makes every
     * download owner-clicked (iron rule 1).
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
     * The job body: download, unpack, pack into the uploads override,
     * and report. Failures keep the current data and never retry
     * beyond the client's own ladder.
     *
     * @return void
     */
    public static function run(): void {
        // Captured before anything swaps, so the success row's before
        // state is the data set that served until this refresh.
        $before_state = Gr_Geoip::state();

        $response = Gr_Http_Client::get( Gr_Http_Client::SERVICE_DBIP, self::url() );

        if ( is_wp_error( $response ) ) {
            $data = (array) ( $response->get_error_data() ?? array() );

            if ( isset( $data['retry_after'] ) && (int) $data['retry_after'] > 0 ) {
                // The client's ladder position is the reschedule delay;
                // the give-up code carries no retry hint and already
                // left its audit row.
                Gr_Queue::enqueue( self::HOOK, array(), (int) $data['retry_after'] );

                return;
            }

            delete_transient( self::PENDING );

            return;
        }

        // The gzip magic header gates the decode: gzdecode on a
        // non-gzip payload warns before returning false, and a queue
        // job must fail quietly, not noisily.
        $body = (string) $response['body'];
        $csv  = ( 0 === strpos( $body, "\x1f\x8b" ) ) ? gzdecode( $body ) : false;

        if ( false === $csv || '' === $csv ) {
            self::finish(
                $before_state,
                array(
                    'result' => 'failed',
                    'reason' => 'unpack',
                )
            );
            delete_transient( self::PENDING );

            return;
        }

        $dir = self::override_dir();

        if ( ! is_dir( $dir ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- the uploads override directory is this feature's own data location, created once per site by an admin-clicked job.
            mkdir( $dir, 0755, true );
        }

        $tmp = $dir . '/download.csv.tmp';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the already-downloaded payload lands in this feature's own uploads directory, never during a front-end request.
        file_put_contents( $tmp, $csv );

        $result = Gr_Geoip_Packer::pack( $tmp, $dir, gmdate( 'Y-m' ) );

        if ( is_file( $tmp ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing this run's own temp file.
            unlink( $tmp );
        }

        if ( 0 === (int) $result['v4_ranges'] + (int) $result['v6_ranges'] ) {
            // The packer refused to promote anything, so the previous
            // override (or the bundled set) still answers lookups.
            self::finish(
                $before_state,
                array(
                    'result' => 'failed',
                    'reason' => 'empty_pack',
                )
            );
            delete_transient( self::PENDING );

            return;
        }

        // The engine resolves the data set once per request; the swap
        // under a running request's feet needs the explicit refresh.
        Gr_Geoip::reset_for_tests();

        self::finish(
            $before_state,
            array(
                'result'     => 'refreshed',
                'build_date' => gmdate( 'Y-m' ),
                'v4_ranges'  => (int) $result['v4_ranges'],
                'v6_ranges'  => (int) $result['v6_ranges'],
            )
        );
        delete_transient( self::PENDING );
    }

    /**
     * One audit row per finished refresh attempt; counts and outcome
     * words only, never endpoints or credentials.
     *
     * @param array<string, mixed>      $before Pre-refresh state summary.
     * @param array<string, int|string> $after Outcome vocabulary.
     * @return void
     */
    private static function finish( array $before, array $after ): void {
        $audit = new Gr_Audit_Repository();
        $audit->log(
            'refresh',
            'geoip_data',
            'dbip_country',
            array(
                'source'     => $before['available'] ? $before['source'] : 'none',
                'build_date' => (string) $before['build_date'],
            ),
            $after,
            0
        );
    }

    /**
     * The uploads override directory, the same path the engine
     * resolves and the update arm fills.
     *
     * @return string
     */
    private static function override_dir(): string {
        $uploads = wp_upload_dir();

        return rtrim( (string) $uploads['basedir'], '/' ) . '/' . Gr_Geoip::UPLOAD_SUBDIR;
    }
}
