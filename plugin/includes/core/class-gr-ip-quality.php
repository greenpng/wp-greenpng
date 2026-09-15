<?php
/**
 * Datacenter-range classifier (ADR-0011 D1/D4): one address against
 * the packed hosting ranges, in memory, zero SQL, so the landing
 * upsert stays at its documented query count. The answer is a
 * category word ('hosting') or '' — a display-and-report signal, never
 * a bot verdict: hosting addresses carry corporate proxies, compliant
 * crawlers, and real people, so nothing downstream may act on it
 * automatically.
 *
 * The dataset is the uploads override when the admin refresh arm
 * wrote one, otherwise the copy bundled with the plugin.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Whether one address sits in a known datacenter range.
 */
final class Gr_Ip_Quality {

    /** The one category this layer can answer. */
    public const CATEGORY_HOSTING = 'hosting';

    /** Bundled data directory, relative to the plugin root. */
    private const BUNDLED_DIR = 'assets/data/';

    /** Override directory under uploads. */
    public const UPLOAD_SUBDIR = 'gr-dch';

    /**
     * Packed dataset per family, memoized per request.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $dataset = null;

    /**
     * Looked-up categories keyed by address text, bounded so a
     * shared FPM worker can never grow it without bound.
     *
     * @var array<string, string>
     */
    private static array $memo = array();

    /**
     * The category for one address: 'hosting' inside a known
     * datacenter range, '' otherwise — including malformed input and
     * a missing dataset, which are unknowns, not negatives.
     *
     * @param string $ip Textual address.
     * @return string
     */
    public static function category( string $ip ): string {
        if ( isset( self::$memo[ $ip ] ) ) {
            return self::$memo[ $ip ];
        }

        $answer = self::CATEGORY_HOSTING;

        if ( ! self::in_dataset( $ip ) ) {
            $answer = '';
        }

        if ( count( self::$memo ) > 512 ) {
            self::$memo = array();
        }
        self::$memo[ $ip ] = $answer;

        return $answer;
    }

    /**
     * Clears the per-request dataset and memo. A refresh swaps the
     * dataset on disk; this hands the next lookup the fresh copy
     * without waiting for the request to end. Test seams call it too.
     *
     * @return void
     */
    public static function reset_for_tests(): void {
        self::$dataset = null;
        self::$memo    = array();
    }

    /**
     * Which dataset is live: 'override' after an admin refresh,
     * 'bundled' with the plugin copy, 'none' when no dataset reads.
     *
     * @return string
     */
    public static function source(): string {
        self::load();

        return is_array( self::$dataset ) ? (string) ( self::$dataset['source_kind'] ?? 'none' ) : 'none';
    }

    /**
     * The resolved dataset's build label and range counts, for the
     * admin display; empty strings when nothing reads.
     *
     * @return array{built: string, v4: int, v6: int}
     */
    public static function describe(): array {
        self::load();

        if ( ! is_array( self::$dataset ) ) {
            return array(
                'built' => '',
                'v4'    => 0,
                'v6'    => 0,
            );
        }

        return array(
            'built' => (string) ( self::$dataset['built'] ?? '' ),
            'v4'    => (int) count( (array) ( self::$dataset['v4_starts'] ?? array() ) ),
            'v6'    => (int) count( (array) ( self::$dataset['v6_starts'] ?? array() ) ),
        );
    }

    /**
     * The binary search: merged sorted ranges, so the last start at
     * or below the address is the only range that can hold it.
     *
     * @param string $ip Textual address.
     * @return bool
     */
    private static function in_dataset( string $ip ): bool {
        self::load();

        if ( ! is_array( self::$dataset ) ) {
            return false;
        }

        $bin = filter_var( $ip, FILTER_VALIDATE_IP ) ? inet_pton( $ip ) : false;
        if ( false === $bin ) {
            return false;
        }

        if ( 4 === strlen( (string) $bin ) ) {
            $unpack = unpack( 'N', (string) $bin );
            if ( false === $unpack ) {
                return false;
            }

            return self::search(
                (int) $unpack[1],
                (array) ( self::$dataset['v4_starts'] ?? array() ),
                (array) ( self::$dataset['v4_ends'] ?? array() )
            );
        }

        return self::search(
            bin2hex( (string) $bin ),
            (array) ( self::$dataset['v6_starts'] ?? array() ),
            (array) ( self::$dataset['v6_ends'] ?? array() )
        );
    }

    /**
     * Numeric search for IPv4 (integers) and hex search for IPv6
     * (equal-length strings compare lexicographically), one code
     * path for both because the comparators line up.
     *
     * @param int|string        $needle Start-bound key of the address.
     * @param array<int, mixed> $starts Sorted range starts.
     * @param array<int, mixed> $ends   Range ends, parallel to starts.
     * @return bool
     */
    private static function search( $needle, array $starts, array $ends ): bool {
        $count = count( $starts );
        if ( 0 === $count || $count !== count( $ends ) ) {
            return false;
        }

        $lo = 0;
        $hi = $count - 1;
        while ( $lo <= $hi ) {
            $mid = intdiv( $lo + $hi, 2 );
            $at  = $starts[ $mid ];

            if ( is_int( $at ) && is_int( $needle ) ) {
                $cmp = $needle <=> $at;
            } else {
                $cmp = strcmp( (string) $needle, (string) $at );
            }

            if ( $cmp < 0 ) {
                $hi = $mid - 1;
            } elseif ( $cmp > 0 ) {
                $lo = $mid + 1;
            } else {
                return self::within( $needle, $ends[ $mid ] );
            }
        }

        // The address fell between two starts: the range below it is
        // the only candidate.
        $candidate = $hi;
        if ( $candidate < 0 || $candidate >= $count ) {
            return false;
        }

        return self::within( $needle, $ends[ $candidate ] );
    }

    /**
     * Whether the address is at or below the candidate range's end.
     *
     * @param int|string $needle Address start-bound key.
     * @param mixed      $end    Range end bound.
     * @return bool
     */
    private static function within( $needle, $end ): bool {
        if ( is_int( $needle ) && is_int( $end ) ) {
            return $needle <= $end;
        }

        return strcmp( (string) $needle, (string) $end ) <= 0;
    }

    /**
     * Loads the dataset once per request: the uploads override when
     * a refresh wrote one, otherwise the bundled copy. A dataset that
     * does not parse or does not pair its arrays reads as absent —
     * the classifier degrades to 'unknown', never to guesses.
     *
     * @return void
     */
    private static function load(): void {
        if ( null !== self::$dataset ) {
            return;
        }

        $uploads = wp_upload_dir();
        $paths   = array(
            array( rtrim( (string) $uploads['basedir'], '/' ) . '/' . self::UPLOAD_SUBDIR . '/' . Gr_Dch_Packer::OUT_FILE, 'override' ),
            array( GR_PLUGIN_DIR . self::BUNDLED_DIR . Gr_Dch_Packer::OUT_FILE, 'bundled' ),
        );

        foreach ( $paths as $path ) {
            if ( ! is_readable( $path[0] ) ) {
                continue;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the packed file is this plugin's own generated return-array contract, the same load discipline as the bundled GeoIP data.
            $data = include $path[0];
            if ( ! is_array( $data ) ) {
                continue;
            }

            $v4_starts = isset( $data['v4_starts'] ) && is_array( $data['v4_starts'] ) ? $data['v4_starts'] : array();
            $v4_ends   = isset( $data['v4_ends'] ) && is_array( $data['v4_ends'] ) ? $data['v4_ends'] : array();
            $v6_starts = isset( $data['v6_starts'] ) && is_array( $data['v6_starts'] ) ? $data['v6_starts'] : array();
            $v6_ends   = isset( $data['v6_ends'] ) && is_array( $data['v6_ends'] ) ? $data['v6_ends'] : array();

            if ( count( $v4_starts ) !== count( $v4_ends ) || count( $v6_starts ) !== count( $v6_ends ) ) {
                continue;
            }

            $data['source_kind'] = $path[1];
            self::$dataset       = $data;

            return;
        }

        self::$dataset = array( 'source_kind' => 'none' );
    }
}
