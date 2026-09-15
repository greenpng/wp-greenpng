<?php
/**
 * Datacenter-range packer (ADR-0011 D1): converts the text CIDR list
 * into the packed PHP dataset the runtime classifier loads once per
 * request. Ranges are merged and sorted per family so the match is a
 * binary search — the same discipline the GeoIP binary format uses,
 * in a form opcache can hold compiled. One class serves the
 * repository build tool and the admin refresh arm, so the shipped
 * dataset and the refreshed dataset can never drift apart.
 *
 * The source list is one CIDR (or bare IP) per line; '#' lines are
 * provenance headers, never data.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Streaming converter: CIDR text in, packed ranges out.
 */
final class Gr_Dch_Packer {

    /** Packed dataset file name. */
    public const OUT_FILE = 'gr-dch.php';

    /** Text CIDR list file name (the provenance-bearing deliverable). */
    public const TEXT_FILE = 'gr-dch.txt';

    /**
     * Packs one CIDR list into out_dir as the packed dataset.
     * Nothing is written under its final name until the whole list
     * parsed and merged cleanly, so a truncated refresh can never
     * shadow a good dataset.
     *
     * @param string $txt_path     Source CIDR list path.
     * @param string $out_dir      Destination directory.
     * @param string $build_date   Data build label, e.g. '2026-09-15'.
     * @param string $source_label Provenance sentence for the header.
     * @return array{v4_ranges: int, v6_ranges: int, skipped: int}
     */
    public static function pack( string $txt_path, string $out_dir, string $build_date = '', string $source_label = '' ): array {
        $empty = array(
            'v4_ranges' => 0,
            'v6_ranges' => 0,
            'skipped'   => 0,
        );

        if ( ! is_readable( $txt_path ) ) {
            return $empty;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- pure-PHP packer running in a build tool or the admin refresh arm, never during a front-end request.
        $in = fopen( $txt_path, 'rb' );
        if ( false === $in ) {
            return $empty;
        }

        $v4      = array();
        $v6      = array();
        $skipped = 0;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets -- pure-PHP packer, build-time only.
        $line = fgets( $in );
        while ( false !== $line ) {
            $cidr = trim( $line );
            if ( '' === $cidr || '#' === $cidr[0] ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets -- pure-PHP packer, build-time only.
                $line = fgets( $in );
                continue;
            }

            $range = self::cidr_range( $cidr );
            if ( array() === $range ) {
                ++$skipped;
            } elseif ( 4 === $range['width'] ) {
                $v4[] = $range;
            } else {
                $v6[] = $range;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets -- pure-PHP packer, build-time only.
            $line = fgets( $in );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pure-PHP packer, build-time only.
        fclose( $in );

        $v4 = self::merge( $v4 );
        $v6 = self::merge( $v6 );

        $starts4 = array();
        $ends4   = array();
        foreach ( $v4 as $range ) {
            $starts4[] = (int) $range['start'];
            $ends4[]   = (int) $range['end'];
        }

        $starts6 = array();
        $ends6   = array();
        foreach ( $v6 as $range ) {
            $starts6[] = (string) $range['start'];
            $ends6[]   = (string) $range['end'];
        }

        $body  = self::header_comment( $build_date, $source_label, count( $v4 ), count( $v6 ) );
        $body .= "return array(\n";
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- var_export IS the pack format: the dataset is a PHP array file, opcache-friendly by design.
        $body .= "    'built' => " . var_export( $build_date, true ) . ",\n";
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- var_export IS the pack format.
        $body .= "    'source' => " . var_export( $source_label, true ) . ",\n";
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- var_export IS the pack format.
        $body .= "    'v4_starts' => " . var_export( $starts4, true ) . ",\n";
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- var_export IS the pack format.
        $body .= "    'v4_ends' => " . var_export( $ends4, true ) . ",\n";
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- var_export IS the pack format.
        $body .= "    'v6_starts' => " . var_export( $starts6, true ) . ",\n";
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- var_export IS the pack format.
        $body .= "    'v6_ends' => " . var_export( $ends6, true ) . ",\n";
        $body .= ");\n";

        $tmp = $out_dir . '/' . self::OUT_FILE . '.tmp';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_put_contents -- build tool or admin refresh arm, never a front-end request; the temp-then-rename keeps a partial write from shadowing a good dataset.
        $written = file_put_contents( $tmp, $body );
        if ( false === $written || ! is_dir( $out_dir ) ) {
            if ( false !== $written ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing our own just-written temp file on the failed-publish path.
                unlink( $tmp );
            }
            return array(
                'v4_ranges' => 0,
                'v6_ranges' => 0,
                'skipped'   => $skipped,
            );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- build tool or admin refresh arm; the rename is the atomic publish.
        if ( ! rename( $tmp, $out_dir . '/' . self::OUT_FILE ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing our own temp file after a failed publish.
            unlink( $tmp );
            return array(
                'v4_ranges' => 0,
                'v6_ranges' => 0,
                'skipped'   => $skipped,
            );
        }

        return array(
            'v4_ranges' => count( $v4 ),
            'v6_ranges' => count( $v6 ),
            'skipped'   => $skipped,
        );
    }

    /**
     * One CIDR (or bare IP) into its merged-range atom: family width
     * and inclusive start/end. IPv4 stays numeric, IPv6 as 32-char
     * lowercase hex so the family sorts and compares as strings.
     *
     * @param string $cidr CIDR or bare IP.
     * @return array{width: int, start: int|string, end: int|string}|array{} Merged-range atom, or the empty array on any malformed input.
     */
    private static function cidr_range( string $cidr ): array {
        $slash = strpos( $cidr, '/' );
        if ( false === $slash ) {
            $net    = $cidr;
            $prefix = null;
        } else {
            $net    = substr( $cidr, 0, $slash );
            $digits = substr( $cidr, (int) $slash + 1 );
            if ( ! ctype_digit( $digits ) ) {
                return array();
            }
            $prefix = (int) $digits;
        }

        $bin = filter_var( $net, FILTER_VALIDATE_IP ) ? inet_pton( $net ) : false;
        if ( false === $bin ) {
            return array();
        }

        $width = strlen( (string) $bin );
        $bits  = $width * 8;

        if ( null === $prefix ) {
            $prefix = $bits;
        }

        if ( $prefix < 0 || $prefix > $bits ) {
            return array();
        }

        $whole = intdiv( $prefix, 8 );
        $rest  = $prefix % 8;

        $start_bin = (string) $bin;
        $end_bin   = $start_bin;

        if ( 0 !== $rest ) {
            $mask              = 0xFF << ( 8 - $rest ) & 0xFF;
            $end_bin[ $whole ] = chr( ord( $start_bin[ $whole ] ) | ( 0xFF & ~$mask ) );
            $fill_from         = $whole + 1;
        } else {
            $fill_from = $whole;
        }
        for ( $byte = $fill_from; $byte < $width; $byte++ ) {
            $end_bin[ $byte ] = "\xFF";
        }
        if ( 0 !== $rest ) {
            $start_bin[ $whole ] = chr( ord( $start_bin[ $whole ] ) & $mask );
        }

        if ( 4 === $width ) {
            $unpack = unpack( 'N2', $start_bin . $end_bin );
            if ( false === $unpack ) {
                return array();
            }

            return array(
                'width' => 4,
                'start' => (int) ( $unpack[1] & 0xFFFFFFFF ),
                'end'   => (int) ( $unpack[2] & 0xFFFFFFFF ),
            );
        }

        return array(
            'width' => 16,
            'start' => bin2hex( $start_bin ),
            'end'   => bin2hex( $end_bin ),
        );
    }

    /**
     * Sorts by start and folds overlapping or touching ranges into
     * the minimal union: fewer ranges, same coverage, and every
     * address matches exactly one range — the binary-search
     * precondition.
     *
     * @param array<int, array{width: int, start: int|string, end: int|string}> $ranges Raw ranges.
     * @return array<int, array{width: int, start: int|string, end: int|string}> Merged ranges.
     */
    private static function merge( array $ranges ): array {
        usort(
            $ranges,
            static function ( array $a, array $b ): int {
                if ( is_int( $a['start'] ) && is_int( $b['start'] ) ) {
                    return $a['start'] <=> $b['start'];
                }

                return strcmp( (string) $a['start'], (string) $b['start'] );
            }
        );

        $out = array();
        foreach ( $ranges as $range ) {
            if ( array() === $out ) {
                $out[] = $range;
                continue;
            }

            $last = &$out[ count( $out ) - 1 ];
            if ( self::adjacent( $last, $range ) ) {
                if ( self::after( $range['end'], $last['end'] ) ) {
                    $last['end'] = $range['end'];
                }
                unset( $last );
                continue;
            }
            unset( $last );

            $out[] = $range;
        }

        return $out;
    }

    /**
     * Whether two ranges touch or overlap.
     *
     * @param array{width: int, start: int|string, end: int|string} $a    Earlier range.
     * @param array{width: int, start: int|string, end: int|string} $b    Candidate range.
     * @return bool
     */
    private static function adjacent( array $a, array $b ): bool {
        if ( is_int( $a['end'] ) && is_int( $b['start'] ) ) {
            return $b['start'] <= $a['end'] + 1 && $a['start'] <= $b['end'] + 1;
        }

        // String families never mix: the caller keeps one family per
        // merge batch, so both operands are the 32-hex form here.
        return strcmp( (string) $b['start'], self::hex_plus_one( (string) $a['end'] ) ) <= 0;
    }

    /**
     * Whether one bound sorts strictly after another.
     *
     * @param int|string $a Left bound.
     * @param int|string $b Right bound.
     * @return bool
     */
    private static function after( $a, $b ): bool {
        if ( is_int( $a ) && is_int( $b ) ) {
            return $a > $b;
        }

        return strcmp( (string) $a, (string) $b ) > 0;
    }

    /**
     * 32-hex string plus one, for the adjacency test — pure PHP so
     * the admin refresh arm never depends on an extension. A
     * full-ones edge carries over and stays put; no later range can
     * touch it anyway.
     *
     * @param string $hex 32-char lowercase hex.
     * @return string
     */
    private static function hex_plus_one( string $hex ): string {
        $bytes = hex2bin( $hex );
        if ( false === $bytes ) {
            return $hex;
        }

        for ( $i = strlen( $bytes ) - 1; $i >= 0; $i-- ) {
            $value = ord( $bytes[ $i ] ) + 1;
            if ( $value <= 0xFF ) {
                $bytes[ $i ] = chr( $value );
                break;
            }
            $bytes[ $i ] = "\x00";
        }

        return bin2hex( $bytes );
    }

    /**
     * The provenance header stamped into every packed dataset.
     *
     * @param string $build_date   Data build label.
     * @param string $source_label Provenance sentence.
     * @param int    $v4           IPv4 range count.
     * @param int    $v6           IPv6 range count.
     * @return string
     */
    private static function header_comment( string $build_date, string $source_label, int $v4, int $v6 ): string {
        return '<?php' . "\n" . '/**' . "\n"
            . ' * Packed datacenter ranges (ADR-0011 D1) — generated, do not edit.' . "\n"
            . ' * Built: ' . $build_date . "\n"
            . ' * Source: ' . $source_label . "\n"
            . ' * Ranges: ' . $v4 . ' IPv4, ' . $v6 . ' IPv6 (merged).' . "\n"
            . ' *' . "\n"
            . ' * The runtime loader is Gr_Ip_Quality; the text CIDR list with the' . "\n"
            . ' * full provenance header ships alongside as gr-dch.txt.' . "\n"
            . ' */' . "\n";
    }
}
