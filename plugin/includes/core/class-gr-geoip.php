<?php
/**
 * Local country lookup over the bundled DB-IP data (docs/07 §5.7,
 * ADR-0007): fixed-width binary records searched with fseek binary
 * search, zero outbound, zero SQL, one L1 memo per request. The
 * bundled files under assets/data ship with plugin releases; an
 * override directory under uploads (written by the admin update arm)
 * wins when it holds a complete set, because the plugin directory is
 * not writable on WordPress.org hosting.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Country-level geolocation, purely local.
 */
final class Gr_Geoip {

    /** Bundled data directory, relative to the plugin root. */
    private const BUNDLED_DIR = 'assets/data/';

    /** Override directory under uploads. */
    public const UPLOAD_SUBDIR = 'gr-geoip';

    /** Result memo ceiling; beyond it the memo resets, so a shared
     *  FPM worker can never grow it without bound. */
    private const MEMO_MAX = 512;

    /**
     * Resolved data directory (uploads override or bundled), or null
     * when no complete set exists. Memoized per request.
     *
     * @var string|null
     */
    private static ?string $data_dir = null;

    /**
     * Parsed meta of the resolved set.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $meta = null;

    /**
     * Open bin handles per family, reused across lookups.
     *
     * @var array<string, resource|false>
     */
    private static $handles = array();

    /**
     * Looked-up countries keyed by address text.
     *
     * @var array<string, string>
     */
    private static array $memo = array();

    /**
     * The country for one address, '' when unknown or no data.
     *
     * @param string $ip Textual address.
     * @return string Two-letter code, or ''.
     */
    public static function country( string $ip ): string {
        if ( isset( self::$memo[ $ip ] ) ) {
            return self::$memo[ $ip ];
        }

        $code = '';
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            $code = self::lookup_v4( (float) sprintf( '%u', ip2long( $ip ) ) );
        } elseif ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
            $code = self::lookup_v6( (string) inet_pton( $ip ) );
        }

        if ( count( self::$memo ) >= self::MEMO_MAX ) {
            self::$memo = array();
        }
        self::$memo[ $ip ] = $code;

        return $code;
    }

    /**
     * What the page and diagnostics report: availability, origin,
     * build label, and counts.
     *
     * @return array{available: bool, source: string, build_date: string, v4_ranges: int, v6_ranges: int, countries: int, generated_at: string}
     */
    public static function state(): array {
        $meta = self::meta();
        if ( null === $meta ) {
            return array(
                'available'    => false,
                'source'       => 'none',
                'build_date'   => '',
                'v4_ranges'    => 0,
                'v6_ranges'    => 0,
                'countries'    => 0,
                'generated_at' => '',
            );
        }

        return array(
            'available'    => true,
            'source'       => self::$data_dir === self::uploads_dir() ? 'override' : 'bundled',
            'build_date'   => (string) ( $meta['build_date'] ?? '' ),
            'v4_ranges'    => (int) ( $meta['v4_ranges'] ?? 0 ),
            'v6_ranges'    => (int) ( $meta['v6_ranges'] ?? 0 ),
            'countries'    => count( (array) ( $meta['countries'] ?? array() ) ),
            'generated_at' => (string) ( $meta['generated_at'] ?? '' ),
        );
    }

    /**
     * IPv4 search over the 9-byte records.
     *
     * @param float $n Address as unsigned 32-bit number (float keeps
     *                 the value literal above PHP_INT_MAX on 32-bit).
     * @return string
     */
    private static function lookup_v4( float $n ): string {
        $count = self::record_count( 'v4' );
        if ( 0 === $count ) {
            return '';
        }

        $lo = 0;
        $hi = $count - 1;
        while ( $lo <= $hi ) {
            $mid = intdiv( $lo + $hi, 2 );
            $rec = self::read_record( 'v4', $mid );
            if ( null === $rec ) {
                return '';
            }

            // pack('N') is unsigned; on 32-bit PHP it reads back as a
            // signed int, so normalize through sprintf before compare.
            $start = (float) sprintf( '%u', $rec['s'] );
            $end   = (float) sprintf( '%u', $rec['e'] );

            if ( $n < $start ) {
                $hi = $mid - 1;
            } elseif ( $n > $end ) {
                $lo = $mid + 1;
            } else {
                return self::country_of( (int) $rec['c'] );
            }
        }

        return '';
    }

    /**
     * IPv6 search over the 33-byte records. Binary strings compare
     * unsigned byte-wise under strcmp, which is exactly address order.
     *
     * @param string $packed 16 raw bytes.
     * @return string
     */
    private static function lookup_v6( string $packed ): string {
        $count = self::record_count( 'v6' );
        if ( 0 === $count ) {
            return '';
        }

        $lo = 0;
        $hi = $count - 1;
        while ( $lo <= $hi ) {
            $mid = intdiv( $lo + $hi, 2 );
            $rec = self::read_record( 'v6', $mid );
            if ( null === $rec ) {
                return '';
            }

            $before = strcmp( $rec['s'], $packed );
            $after  = strcmp( $packed, $rec['e'] );
            if ( $before > 0 ) {
                $hi = $mid - 1;
            } elseif ( $after > 0 ) {
                $lo = $mid + 1;
            } else {
                return self::country_of( (int) $rec['c'] );
            }
        }

        return '';
    }

    /**
     * One record: family-shaped binary fields.
     *
     * @param string $family 'v4' or 'v6'.
     * @param int    $index  Record offset.
     * @return array<string, int|string>|null
     */
    private static function read_record( string $family, int $index ) {
        $handle = self::handle( $family );
        if ( false === $handle ) {
            return null;
        }

        $size = 'v4' === $family ? Gr_Geoip_Packer::V4_RECORD : Gr_Geoip_Packer::V6_RECORD;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek, WordPress.WP.AlternativeFunctions.file_system_operations_fread -- read-only access to a shipped data file; no WordPress filesystem API on a pure binary search path.
        if ( 0 !== fseek( $handle, $index * $size ) ) {
            return null;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- read-only access to a shipped data file; no WordPress filesystem API on a pure binary search path.
        $raw = fread( $handle, $size );
        if ( false === $raw || strlen( $raw ) !== $size ) {
            return null;
        }

        if ( 'v4' === $family ) {
            // 'C' not 'c': the country index byte is unsigned, and a
            // signed read would turn indexes above 127 negative.
            $ints = unpack( 'Ns/Ne/Cidx', $raw );
            if ( false === $ints ) {
                return null;
            }

            return array(
                's' => $ints['s'],
                'e' => $ints['e'],
                'c' => $ints['idx'],
            );
        }

        return array(
            's' => substr( $raw, 0, 16 ),
            'e' => substr( $raw, 16, 16 ),
            'c' => ord( $raw[32] ),
        );
    }

    /**
     * Record count of one family's bin, from file size.
     *
     * @param string $family 'v4' or 'v6'.
     * @return int
     */
    private static function record_count( string $family ): int {
        $dir = self::data_dir();
        if ( null === $dir ) {
            return 0;
        }

        $file = $dir . '/' . ( 'v4' === $family ? Gr_Geoip_Packer::V4_FILE : Gr_Geoip_Packer::V6_FILE );
        if ( ! is_file( $file ) ) {
            return 0;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- read-only metadata of a shipped data file.
        $size = filesize( $file );

        if ( false === $size || $size <= 0 ) {
            return 0;
        }

        $record = 'v4' === $family ? Gr_Geoip_Packer::V4_RECORD : Gr_Geoip_Packer::V6_RECORD;

        return intdiv( (int) $size, $record );
    }

    /**
     * Country code for one index of the resolved table.
     *
     * @param int $index Country index byte.
     * @return string
     */
    private static function country_of( int $index ): string {
        $meta = self::meta();
        if ( null === $meta ) {
            return '';
        }

        $table = (array) ( $meta['countries'] ?? array() );

        return isset( $table[ $index ] ) ? (string) $table[ $index ] : '';
    }

    /**
     * Open (and reuse) one family's bin handle.
     *
     * @param string $family 'v4' or 'v6'.
     * @return resource|false
     */
    private static function handle( string $family ) {
        $dir = self::data_dir();
        if ( null === $dir ) {
            return false;
        }

        if ( ! array_key_exists( $family, self::$handles ) ) {
            $file                     = $dir . '/' . ( 'v4' === $family ? Gr_Geoip_Packer::V4_FILE : Gr_Geoip_Packer::V6_FILE );
            self::$handles[ $family ] = is_readable( $file )
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- read-only access to a shipped data file.
                ? fopen( $file, 'rb' )
                : false;
        }

        return self::$handles[ $family ];
    }

    /**
     * Resolved data directory: the uploads override when it holds a
     * complete set, otherwise the bundled directory, otherwise null.
     *
     * @return string|null
     */
    private static function data_dir(): ?string {
        if ( null !== self::$data_dir ) {
            // '' is the memo for "resolved, none found".
            return '' === self::$data_dir ? null : self::$data_dir;
        }

        $candidates = array( self::uploads_dir(), GR_PLUGIN_DIR . self::BUNDLED_DIR );
        foreach ( $candidates as $dir ) {
            if ( is_readable( $dir . '/' . Gr_Geoip_Packer::META_FILE ) ) {
                self::$data_dir = $dir;
                self::$meta     = self::read_meta( $dir );

                return $dir;
            }
        }

        self::$data_dir = '';

        return null;
    }

    /**
     * Parsed meta of the resolved set, loading it once.
     *
     * @return array<string, mixed>|null
     */
    private static function meta(): ?array {
        if ( null === self::$meta && null === self::$data_dir ) {
            self::data_dir();
        }

        return self::$meta;
    }

    /**
     * Reads and parses one directory's meta file.
     *
     * @param string $dir Data directory.
     * @return array<string, mixed>|null
     */
    private static function read_meta( string $dir ): ?array {
        $file = $dir . '/' . Gr_Geoip_Packer::META_FILE;
        if ( ! is_readable( $file ) ) {
            return null;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- read-only access to a shipped data file, once per request.
        $raw = file_get_contents( $file );
        if ( false === $raw || '' === $raw ) {
            return null;
        }

        $meta = json_decode( $raw, true );

        return is_array( $meta ) ? $meta : null;
    }

    /**
     * The override directory under uploads.
     *
     * @return string
     */
    private static function uploads_dir(): string {
        $uploads = wp_upload_dir();

        return rtrim( (string) $uploads['basedir'], '/' ) . '/' . self::UPLOAD_SUBDIR;
    }

    /**
     * Test seam: forget the resolved set, memo, and handles. Also the
     * refresh hook for the update arm, which swaps the override under
     * a running request's feet.
     *
     * @return void
     */
    public static function reset_for_tests(): void {
        foreach ( self::$handles as $handle ) {
            if ( is_resource( $handle ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the read-only handle this class opened itself.
                fclose( $handle );
            }
        }
        self::$handles  = array();
        self::$data_dir = null;
        self::$meta     = null;
        self::$memo     = array();
    }
}
