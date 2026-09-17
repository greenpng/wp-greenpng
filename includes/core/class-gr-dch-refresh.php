<?php
/**
 * Datacenter-range refresh job (ADR-0011 D2): the one outbound this
 * layer ever makes, and only because the owner clicked the button on
 * the IP Intelligence page — the click enqueues this job, the job
 * pulls the official AWS/Azure/Google service segment tables through
 * the unified client, repacks them into the uploads override, and
 * leaves the old dataset untouched on every failure path. The Azure
 * URL is date-stamped, so the stable download page is fetched first
 * and the current JSON link discovered from it — no account, no
 * auth, no schedule, nothing silent anywhere.
 *
 * A source that fails does not sink the others: the refresh packs
 * whichever official tables answered, and the audit row names them.
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
 * Owner-clicked segment refresh, dispatched through Gr_Queue.
 */
final class Gr_Dch_Refresh {

    /** Queue hook the job runs under. */
    public const HOOK = 'gr_dch_refresh';

    /** Dedupe flag while a queued refresh has not finished. */
    public const PENDING = 'gr_dch_refresh_pending';

    /** The pending flag self-heals after an hour, so a lost job cannot pin the button. */
    private const PENDING_TTL = 3600;

    /** AWS official segment table. */
    public const URL_AWS = 'https://ip-ranges.amazonaws.com/ip-ranges.json';

    /** Google official segment table. */
    public const URL_GOOG = 'https://www.gstatic.com/ipranges/goog.json';

    /** The stable Azure download page; its dated JSON link is discovered from it. */
    public const URL_AZURE_PAGE = 'https://www.microsoft.com/en-us/download/details.aspx?id=56519';

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
     * The job body: pull, merge, pack into the uploads override, and
     * report. Failures keep the current dataset and never retry
     * beyond the client's own ladder.
     *
     * @return void
     */
    public static function run(): void {
        $before = array(
            'source' => Gr_Ip_Quality::source(),
            'built'  => Gr_Ip_Quality::describe()['built'],
        );

        $lines   = array();
        $sources = array();

        // A source counts as answered when the fetch parsed at all —
        // an answered-but-empty table is an honest contribution of
        // zero, and the audit distinguishes it from a failed fetch.
        $aws_json = self::fetch_json( self::URL_AWS );
        if ( array() !== $aws_json ) {
            $lines     = array_merge( $lines, self::parse_json( 'aws', $aws_json ) );
            $sources[] = 'AWS';
        }

        $goog_json = self::fetch_json( self::URL_GOOG );
        if ( array() !== $goog_json ) {
            $lines     = array_merge( $lines, self::parse_json( 'google', $goog_json ) );
            $sources[] = 'Google';
        }

        $azure_page = self::fetch_body( self::URL_AZURE_PAGE );
        if ( '' !== $azure_page && 1 === preg_match( '#https://download\.microsoft\.com/[^"\' ]*ServiceTags_Public_[0-9]+\.json#', $azure_page, $m ) ) {
            $tags = self::fetch_json( $m[0] );
            $az   = array();
            foreach ( (array) ( $tags['values'] ?? array() ) as $value ) {
                foreach ( (array) ( $value['properties']['addressPrefixes'] ?? array() ) as $prefix ) {
                    if ( is_string( $prefix ) && '' !== $prefix ) {
                        $az[] = $prefix;
                    }
                }
            }
            if ( array() !== $az ) {
                $lines     = array_merge( $lines, $az );
                $sources[] = 'Azure';
            }
        }

        if ( array() === $sources ) {
            // Nothing answered: keep the current dataset and let the
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

        $lines = array_values( array_unique( $lines ) );

        $dir = self::override_dir();
        if ( ! is_dir( $dir ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- the uploads override directory is this feature's own data location, created once per site by an admin-clicked job.
            mkdir( $dir, 0755, true );
        }

        $tmp = $dir . '/' . Gr_Dch_Packer::TEXT_FILE . '.tmp';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the merged segment list lands in this feature's own uploads directory, never during a front-end request.
        file_put_contents( $tmp, implode( "\n", $lines ) . "\n" );

        $source_sentence = implode( ', ', $sources ) . ' official service segment tables';
        $result          = Gr_Dch_Packer::pack( $tmp, $dir, gmdate( 'Y-m-d' ), $source_sentence );

        if ( is_file( $tmp ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing this run's own temp file.
            unlink( $tmp );
        }

        if ( 0 === (int) $result['v4_ranges'] + (int) $result['v6_ranges'] ) {
            // The packer refused to promote anything, so the previous
            // dataset still answers lookups.
            self::finish(
                $before,
                array(
                    'result' => 'failed',
                    'reason' => 'empty_pack',
                )
            );
            delete_transient( self::PENDING );

            return;
        }

        Gr_Ip_Quality::reset_for_tests();

        self::finish(
            $before,
            array(
                'result'     => 'refreshed',
                'build_date' => gmdate( 'Y-m-d' ),
                'sources'    => implode( ', ', $sources ),
                'v4_ranges'  => (int) $result['v4_ranges'],
                'v6_ranges'  => (int) $result['v6_ranges'],
            )
        );
        delete_transient( self::PENDING );
    }

    /**
     * One URL's body through the unified client; '' on any failure.
     *
     * @param string $url Source URL.
     * @return string
     */
    private static function fetch_body( string $url ): string {
        $response = Gr_Http_Client::get( Gr_Http_Client::SERVICE_DCH_RANGES, $url );

        return is_wp_error( $response ) ? '' : (string) $response['body'];
    }

    /**
     * One JSON fetch through the unified client; failures return an
     * empty array and leave the retry ladder to the client's own
     * backoff bookkeeping — a partial refresh never reschedules
     * itself, the button is the owner's word.
     *
     * @param string $url Source URL.
     * @return array<string, mixed> Parsed body, or an empty array.
     */
    private static function fetch_json( string $url ): array {
        $body   = self::fetch_body( $url );
        $parsed = '' === $body ? null : json_decode( $body, true );

        return is_array( $parsed ) ? $parsed : array();
    }

    /**
     * Extracts the CIDR vocabulary of one cloud JSON.
     *
     * @param string               $which 'aws' or 'google'.
     * @param array<string, mixed> $json  Parsed body, or an empty array.
     * @return array<int, string> CIDR lines.
     */
    private static function parse_json( string $which, array $json ): array {
        if ( array() === $json ) {
            return array();
        }

        $out = array();

        if ( 'aws' === $which ) {
            foreach ( (array) ( $json['prefixes'] ?? array() ) as $row ) {
                if ( isset( $row['ip_prefix'] ) && is_string( $row['ip_prefix'] ) ) {
                    $out[] = $row['ip_prefix'];
                }
            }
            foreach ( (array) ( $json['ipv6_prefixes'] ?? array() ) as $row ) {
                if ( isset( $row['ipv6_prefix'] ) && is_string( $row['ipv6_prefix'] ) ) {
                    $out[] = $row['ipv6_prefix'];
                }
            }

            return $out;
        }

        foreach ( (array) ( $json['prefixes'] ?? array() ) as $row ) {
            foreach ( array( 'ipv4Prefix', 'ipv6Prefix' ) as $key ) {
                if ( isset( $row[ $key ] ) && is_string( $row[ $key ] ) ) {
                    $out[] = $row[ $key ];
                }
            }
        }

        return $out;
    }

    /**
     * One audit row per finished refresh attempt; counts and outcome
     * words only, never endpoints or credentials.
     *
     * @param array<string, mixed>      $before Pre-refresh state summary.
     * @param array<string, int|string> $after  Outcome vocabulary.
     * @return void
     */
    private static function finish( array $before, array $after ): void {
        $audit = new Gr_Audit_Repository();
        $audit->log(
            'refresh',
            'ip_quality_data',
            'cloud_segments',
            array(
                'source' => $before['source'],
                'built'  => (string) $before['built'],
            ),
            $after,
            0
        );
    }

    /**
     * The uploads override directory, the same path the classifier
     * resolves and this job fills.
     *
     * @return string
     */
    private static function override_dir(): string {
        $uploads = wp_upload_dir();

        return rtrim( (string) $uploads['basedir'], '/' ) . '/' . Gr_Ip_Quality::UPLOAD_SUBDIR;
    }
}
