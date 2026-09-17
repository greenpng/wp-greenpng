<?php
/**
 * GeoLite2 download job (docs/07 §1/§3, docs/19 V22): the only path a
 * MaxMind database can enter this site, and only because the owner
 * clicked the download button on the IP Intelligence page. The
 * database is never bundled — the GeoLite2 EULA forbids
 * redistribution — so the owner's license key fetches it from
 * download.maxmind.com, the tarball is verified before anything is
 * promoted, and the publish is one atomic rename inside the uploads
 * override directory. Failures keep the current source answering.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Owner-clicked GeoLite2-Country download, dispatched through Gr_Queue.
 */
final class Gr_Maxmind_Refresh {

    /** Queue hook the job runs under. */
    public const HOOK = 'gr_maxmind_refresh';

    /** Dedupe flag while a queued download has not finished. */
    public const PENDING = 'gr_maxmind_refresh_pending';

    /** The official download endpoint, path only; the query is built per run. */
    private const URL = 'https://download.maxmind.com/app/geoip_download';

    /** The one edition the reader and the page speak of. */
    private const EDITION = 'GeoLite2-Country';

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
     * The download URL for this run. The owner's probe or a future
     * proxy re-points the whole URL through one filter, the same
     * seam the spider sources use.
     *
     * @param string $license_key The owner's license key.
     * @return string
     */
    public static function url( string $license_key ): string {
        $url = add_query_arg(
            array(
                'edition_id'  => self::EDITION,
                'license_key' => $license_key,
                'suffix'      => 'tar.gz',
            ),
            self::URL
        );

        return (string) apply_filters( 'gr_maxmind_download_url', $url );
    }

    /**
     * Queues one download unless one is already on its way. Called
     * only from the page's gated POST arm, which is what makes every
     * download owner-clicked (iron rule 1).
     *
     * @return bool False when a download is already pending.
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
     * The job body: fetch the tarball, untar, verify the database
     * against the reader's own metadata battery, then promote by one
     * rename. Every failure path leaves the serving source untouched.
     *
     * @return void
     */
    public static function run(): void {
        // Captured before anything swaps, so the result row's before
        // state is the source that served until this download.
        $before_state = Gr_Geoip::state();

        $license_key = Gr_Secrets::reveal( Gr_Secrets::MAXMIND_KEY_OPTION );
        if ( '' === $license_key ) {
            // The owner removed the key between the click and this
            // run: the honest answer is no_key, not a silent skip.
            self::finish(
                $before_state,
                array(
                    'result' => 'failed',
                    'reason' => 'no_key',
                )
            );
            delete_transient( self::PENDING );

            return;
        }

        $response = Gr_Http_Client::get( Gr_Http_Client::SERVICE_MAXMIND, self::url( $license_key ) );

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

        $body = (string) $response['body'];

        // The gzip magic gates the tar decode, the same gate the DB-IP
        // refresh rides: a 401/400 body is JSON, not an archive.
        if ( 0 !== strpos( $body, "\x1f\x8b" ) ) {
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

        $dir = self::data_dir();
        if ( ! is_dir( $dir ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- this feature's own uploads directory, created once per site by an admin-clicked job.
            mkdir( $dir, 0755, true );
        }

        // The publish target and every temp artifact live in the same
        // directory, so the final rename cannot cross a filesystem.
        $tmp_gz = $dir . '/gr-maxmind-' . uniqid( '', false ) . '.tar.gz';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the already-downloaded payload lands in this feature's own uploads directory, never during a front-end request.
        file_put_contents( $tmp_gz, $body );

        $mmdb_path = self::extract_and_publish( $tmp_gz, $dir );

        if ( is_file( $tmp_gz ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing this run's own temp file.
            unlink( $tmp_gz );
        }

        if ( is_string( $mmdb_path ) ) {
            // The engine resolves the source once per request; the
            // swap under a running request's feet needs the reset.
            Gr_Geoip::reset_for_tests();

            $meta = Gr_Maxmind_Reader::validate( $mmdb_path );

            self::finish(
                $before_state,
                array(
                    'result'     => 'refreshed',
                    'build_date' => is_array( $meta ) ? gmdate( 'Y-m-d', (int) $meta['build_epoch'] ) : '',
                    'database'   => 'geolite2_country',
                )
            );
            delete_transient( self::PENDING );

            return;
        }

        // The archive or the database inside it was refused; whatever
        // source serves now keeps serving.
        self::finish(
            $before_state,
            array(
                'result' => 'failed',
                'reason' => 'mmdb',
            )
        );
        delete_transient( self::PENDING );
    }

    /**
     * Untar one downloaded archive and promote the database it
     * carries. The official tarball nests the file under a dated
     * directory; the mock ships it at the root; both must land.
     *
     * @param string $tmp_gz The downloaded archive, already on disk.
     * @param string $dir   The uploads data directory.
     * @return string|null The published file path, or null when the
     *                     archive or the database inside it was refused.
     */
    private static function extract_and_publish( string $tmp_gz, string $dir ) {
        $extract = $dir . '/gr-maxmind-extract-' . uniqid( '', false );

        try {
            $phar = new \PharData( $tmp_gz );
            $phar->extractTo( $extract );
        } catch ( \Throwable $error ) {
            self::rmdir( $extract );

            return null;
        }

        // Locate the database by name at any depth: the real tarball
        // nests it under a dated folder.
        $found = '';
        $it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $extract, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            if ( $file->isFile() && Gr_Geoip::MMDB_FILE === $file->getBasename() ) {
                $found = (string) $file->getPathname();
                break;
            }
        }

        if ( '' === $found ) {
            self::rmdir( $extract );

            return null;
        }

        // Verify against the reader's battery before promoting: the
        // published file must be one the reader actually serves.
        $meta = Gr_Maxmind_Reader::validate( $found );
        if ( null === $meta ) {
            self::rmdir( $extract );

            return null;
        }

        $publish_tmp = $dir . '/' . Gr_Geoip::MMDB_FILE . '.tmp';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- staging inside this feature's own uploads directory for the atomic rename below.
        copy( $found, $publish_tmp );

        if ( ! is_file( $publish_tmp ) || null === Gr_Maxmind_Reader::validate( $publish_tmp ) ) {
            if ( is_file( $publish_tmp ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing this run's own staging file.
                unlink( $publish_tmp );
            }
            self::rmdir( $extract );

            return null;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- the atomic publish, same directory, same filesystem.
        rename( $publish_tmp, $dir . '/' . Gr_Geoip::MMDB_FILE );
        self::rmdir( $extract );

        return $dir . '/' . Gr_Geoip::MMDB_FILE;
    }

    /**
     * One audit row per finished download attempt; words and the
     * build date only, never the endpoint or the license key.
     *
     * @param array<string, mixed>      $before Pre-download state summary.
     * @param array<string, int|string> $after  Outcome vocabulary.
     * @return void
     */
    private static function finish( array $before, array $after ): void {
        $audit = new \GreenPNG\Storage\Gr_Audit_Repository();
        $audit->log(
            'refresh',
            'geoip_data',
            'maxmind_country',
            array(
                'source'     => $before['available'] ? $before['source'] : 'none',
                'build_date' => (string) $before['build_date'],
            ),
            $after,
            0
        );
    }

    /**
     * The uploads directory the GeoIP sources share.
     *
     * @return string
     */
    private static function data_dir(): string {
        $uploads = wp_upload_dir();

        return rtrim( (string) $uploads['basedir'], '/' ) . '/' . Gr_Geoip::UPLOAD_SUBDIR;
    }

    /**
     * Removes this run's own extraction directory, nothing else.
     *
     * @param string $path Directory path.
     * @return void
     */
    private static function rmdir( string $path ): void {
        if ( ! is_dir( $path ) ) {
            return;
        }

        $it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $it as $item ) {
            if ( $item->isDir() ) {
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- this run's own temp tree; a failure leaves a temp dir behind at worst.
                @rmdir( (string) $item->getPathname() );
            } else {
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- this run's own temp tree; a failure leaves a temp file behind at worst.
                @unlink( (string) $item->getPathname() );
            }
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- this run's own temp tree.
        @rmdir( $path );
    }
}
