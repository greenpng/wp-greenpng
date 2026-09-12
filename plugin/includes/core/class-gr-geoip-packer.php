<?php
/**
 * GeoIP data packer (docs/07 §5.7, ADR-0007): converts a DB-IP
 * country-lite CSV into the plugin's fixed-width binary format so the
 * runtime lookup is a binary search over 9-byte (IPv4) and 33-byte
 * (IPv6) records with no parse step and no full-file load. One class
 * serves both the repository build tool and the admin update arm, so
 * the shipped format and the refreshed format can never drift apart.
 *
 * The source CSV is "start,end,cc" one range per line, sorted by
 * start address (DB-IP's published order). Adjacent ranges of the
 * same country merge into one record while streaming.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Streaming converter: CSV in, .bin/.json out.
 */
final class Gr_Geoip_Packer {

    /** IPv4 record: 4B start + 4B end + 1B country index. */
    public const V4_RECORD = 9;

    /** IPv6 record: 16B start + 16B end + 1B country index. */
    public const V6_RECORD = 33;

    /** IPv4 output file name. */
    public const V4_FILE = 'gr-geoip-v4.bin';

    /** IPv6 output file name. */
    public const V6_FILE = 'gr-geoip-v6.bin';

    /** Meta output file name. */
    public const META_FILE = 'gr-geoip.json';

    /**
     * Packs one CSV into out_dir: two bins plus the meta json.
     * Nothing is written under its final name until both passes
     * succeed, so a half-written refresh can never shadow a good
     * data set (the temp files are removed on failure).
     *
     * @param string $csv_path   Source CSV path.
     * @param string $out_dir    Destination directory.
     * @param string $build_date Data build label, e.g. '2026-09'.
     * @return array{v4_ranges: int, v6_ranges: int, countries: int, skipped: int}
     */
    public static function pack( string $csv_path, string $out_dir, string $build_date = '' ): array {
        $countries = array();
        $skipped   = 0;

        // Pass 1: the country table and a shape audit of every line.
        // The readable guard keeps fopen from warning on paths the
        // update arm may hand it after a failed download.
        if ( ! is_readable( $csv_path ) ) {
            return array(
                'v4_ranges' => 0,
                'v6_ranges' => 0,
                'countries' => 0,
                'skipped'   => 0,
            );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- pure-PHP packer running in a tool or an admin update arm, never during a front-end request.
        $in = fopen( $csv_path, 'rb' );
        if ( false === $in ) {
            return array(
                'v4_ranges' => 0,
                'v6_ranges' => 0,
                'countries' => 0,
                'skipped'   => 0,
            );
        }

        $line = fgets( $in );
        while ( false !== $line ) {
            $parts = explode( ',', trim( $line ) );
            if ( 3 !== count( $parts ) || ! preg_match( '/^[A-Z]{2}$/', $parts[2] ) ) {
                ++$skipped;
            } elseif ( ! isset( $countries[ $parts[2] ] ) ) {
                $countries[ $parts[2] ] = count( $countries );
            }
            $line = fgets( $in );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pure-PHP packer running in a tool or an admin update arm, never during a front-end request.
        fclose( $in );

        if ( array() === $countries || count( $countries ) > 256 ) {
            // More than 256 distinct codes cannot fit the 1-byte
            // index; an empty table means the input is not the
            // expected file at all.
            return array(
                'v4_ranges' => 0,
                'v6_ranges' => 0,
                'countries' => 0,
                'skipped'   => $skipped,
            );
        }

        // Pass 2: stream rows into the temp bins, merging adjacent
        // same-country ranges on the fly (one-row lookahead buffer).
        $tmp_v4 = $out_dir . '/' . self::V4_FILE . '.tmp';
        $tmp_v6 = $out_dir . '/' . self::V6_FILE . '.tmp';

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- pure-PHP packer running in a tool or an admin update arm, never during a front-end request.
        $v4 = fopen( $tmp_v4, 'wb' );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- pure-PHP packer running in a tool or an admin update arm, never during a front-end request.
        $v6 = fopen( $tmp_v6, 'wb' );
        if ( false === $v4 || false === $v6 ) {
            if ( is_resource( $v4 ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pure-PHP packer running in a tool or an admin update arm, never during a front-end request.
                fclose( $v4 );
            }
            if ( is_resource( $v6 ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pure-PHP packer running in a tool or an admin update arm, never during a front-end request.
                fclose( $v6 );
            }
            self::remove( $tmp_v4 );
            self::remove( $tmp_v6 );

            return array(
                'v4_ranges' => 0,
                'v6_ranges' => 0,
                'countries' => 0,
                'skipped'   => $skipped,
            );
        }

        $prev4    = null;
        $prev6    = null;
        $v4_count = 0;
        $v6_count = 0;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- pure-PHP packer running in a tool or an admin update arm, never during a front-end request.
        $in   = fopen( $csv_path, 'rb' );
        $line = fgets( $in );
        while ( false !== $line ) {
            $parts = explode( ',', trim( $line ) );
            if ( 3 === count( $parts ) && isset( $countries[ $parts[2] ] ) ) {
                $index = $countries[ $parts[2] ];

                if ( false !== strpos( $parts[0], ':' ) ) {
                    $start = inet_pton( $parts[0] );
                    $end   = inet_pton( $parts[1] );
                    if ( false === $start || false === $end || 16 !== strlen( $start ) || 16 !== strlen( $end ) ) {
                        ++$skipped;
                    } elseif ( null !== $prev6 && $prev6[2] === $index && self::inc16( $prev6[1] ) === $start ) {
                        // Adjacent same-country v6 range: extend the
                        // pending record instead of emitting two.
                        $prev6[1] = $end;
                    } else {
                        if ( null !== $prev6 ) {
                            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- pure-PHP packer running in a tool or an admin update arm, never during a front-end request.
                            fwrite( $v6, $prev6[0] . $prev6[1] . chr( $prev6[2] ) );
                            ++$v6_count;
                        }
                        $prev6 = array( $start, $end, $index );
                    }
                } else {
                    $start = ip2long( $parts[0] );
                    $end   = ip2long( $parts[1] );
                    if ( false === $start || false === $end || $start < 0 || $end < $start ) {
                        ++$skipped;
                    } elseif ( null !== $prev4 && $prev4[2] === $index && $prev4[1] + 1 === $start ) {
                        // Adjacent same-country v4 range: extend the
                        // pending record instead of emitting two.
                        $prev4[1] = $end;
                    } else {
                        if ( null !== $prev4 ) {
                            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- pure-PHP packer running in a tool or an admin update arm, never during a front-end request.
                            fwrite( $v4, pack( 'NN', $prev4[0], $prev4[1] ) . chr( $prev4[2] ) );
                            ++$v4_count;
                        }
                        $prev4 = array( $start, $end, $index );
                    }
                }
            }

            $line = fgets( $in );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pure-PHP packer running in a tool or an admin update arm, never during a front-end request.
        fclose( $in );

        if ( null !== $prev4 ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- pure-PHP packer running in a tool or an admin update arm, never during a front-end request.
            fwrite( $v4, pack( 'NN', $prev4[0], $prev4[1] ) . chr( $prev4[2] ) );
            ++$v4_count;
        }
        if ( null !== $prev6 ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- pure-PHP packer running in a tool or an admin update arm, never during a front-end request.
            fwrite( $v6, $prev6[0] . $prev6[1] . chr( $prev6[2] ) );
            ++$v6_count;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pure-PHP packer running in a tool or an admin update arm, never during a front-end request.
        fclose( $v4 );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pure-PHP packer running in a tool or an admin update arm, never during a front-end request.
        fclose( $v6 );

        if ( 0 === $v4_count + $v6_count ) {
            self::remove( $tmp_v4 );
            self::remove( $tmp_v6 );

            return array(
                'v4_ranges' => 0,
                'v6_ranges' => 0,
                'countries' => count( $countries ),
                'skipped'   => $skipped,
            );
        }

        // Everything streamed: promote under the final names and
        // record what the data is.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic promotion of this run's own temp files; never during a front-end request.
        rename( $tmp_v4, $out_dir . '/' . self::V4_FILE );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic promotion of this run's own temp files; never during a front-end request.
        rename( $tmp_v6, $out_dir . '/' . self::V6_FILE );

        $meta = array(
            'source'       => 'DB-IP Country Lite',
            'license'      => 'CC BY 4.0',
            'url'          => 'https://db-ip.com/',
            'build_date'   => $build_date,
            'generated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'countries'    => array_values( array_flip( $countries ) ),
            'v4_ranges'    => $v4_count,
            'v6_ranges'    => $v6_count,
            'skipped'      => $skipped,
        );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure-PHP packer running in a tool or an admin update arm, never during a front-end request; the tool context has no WordPress loaded, so json_encode rather than wp_json_encode.
        file_put_contents( $out_dir . '/' . self::META_FILE, json_encode( $meta ) . "\n" );

        return array(
            'v4_ranges' => $v4_count,
            'v6_ranges' => $v6_count,
            'countries' => count( $countries ),
            'skipped'   => $skipped,
        );
    }

    /**
     * 128-bit big-endian increment, the adjacency test for IPv6
     * range merging.
     *
     * @param string $bytes 16 raw bytes.
     * @return string The incremented address.
     */
    private static function inc16( string $bytes ): string {
        for ( $i = 15; $i >= 0; $i-- ) {
            $ord         = ord( $bytes[ $i ] ) + 1;
            $bytes[ $i ] = chr( $ord % 256 );
            if ( $ord < 256 ) {
                return $bytes;
            }
        }

        return $bytes;
    }

    /**
     * Drops a temp file when it exists; failure paths call this for
     * files the same run may never have created.
     *
     * @param string $path Temp file path.
     * @return void
     */
    private static function remove( string $path ): void {
        if ( is_file( $path ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing this run's own temp file.
            unlink( $path );
        }
    }
}
