<?php
/**
 * Repository build tool: generates the bundled datacenter CIDR list
 * (ADR-0011 D1) under plugin/assets/data/ and packs it for runtime.
 * Run from the repository root with the local PHP:
 *
 *   php tools/pack-dch.php --cloud
 *   php tools/pack-dch.php --cloud --px-csv /path/IP2PROXY-LITE-PX1.CSV
 *
 * Sources:
 *   --cloud   Official service segment tables, no account, no auth:
 *             AWS ip-ranges.json, the Azure ServiceTags JSON (its
 *             dated URL is discovered from the stable download page),
 *             Google goog.json. Retrieved at generation time; the
 *             data date is recorded in the file header.
 *   --px-csv  Optional IP2Proxy LITE PX CSV (ip_from,ip_to,proxy_type,
 *             ...); only proxy_type 'DCH' rows merge. LITE is CC BY-SA
 *             4.0: redistribution of one copy is allowed with
 *             attribution plus a notice telling users to register at
 *             lite.ip2location.com for updates. IPv6 rows here need
 *             gmp or bcmath; IPv4 never does.
 *
 * Not shipped in the plugin package (build-zip excludes tools/).
 *
 * @package GreenPNG
 */

define( 'ABSPATH', '/tmp/' );

require __DIR__ . '/../plugin/includes/core/class-gr-dch-packer.php';

use GreenPNG\Core\Gr_Dch_Packer;

/**
 * Fetches a URL, honoring allow_url_fopen, else curl.
 *
 * @param string $url Source URL.
 * @return string Body or '' on failure.
 */
function gr_dch_fetch( string $url ): string {
    $ctx = stream_context_create(
        array(
            'http' => array(
                'timeout'         => 120,
                'follow_location' => 1,
                'user_agent'      => 'greenpng-build/1.0',
            ),
        )
    );
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- dev-time build tool, not the plugin runtime.
    $body = @file_get_contents( $url, false, $ctx );
    if ( false !== $body && '' !== $body ) {
        return $body;
    }

    if ( function_exists( 'curl_init' ) ) {
        $ch = curl_init( $url );
        curl_setopt_array(
            $ch,
            array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT       => 120,
                CURLOPT_USERAGENT     => 'greenpng-build/1.0',
            )
        );
        $body = curl_exec( $ch );
        curl_close( $ch );

        return is_string( $body ) ? $body : '';
    }

    return '';
}

/**
 * The minimal CIDR list covering one inclusive numeric range.
 *
 * @param string $from  Start as decimal string (any width).
 * @param string $to    End as decimal string.
 * @param bool   $is_v4 The range is IPv4 (fits in PHP int).
 * @return array<int, string> CIDR strings.
 */
function gr_dch_range_to_cidrs( string $from, string $to, bool $is_v4 ): array {
    $out = array();

    if ( $is_v4 ) {
        $start = (int) $from;
        $end   = (int) $to;
        while ( $start <= $end ) {
            // Largest power-of-two block aligned at start and fitting end.
            $max_size = $start & ( 0 - $start );
            if ( 0 === $max_size ) {
                $max_size = 1;
            }
            $diff = $end - $start + 1;
            while ( $max_size > $diff ) {
                $max_size >>= 1;
            }
            $prefix = 32 - (int) log( $max_size, 2 );
            $out[]  = long2ip( $start ) . '/' . $prefix;
            $start += $max_size;
        }

        return $out;
    }

    if ( ! function_exists( 'gmp_init' ) && ! function_exists( 'bcadd' ) ) {
        fwrite( STDERR, "IPv6 PX rows need gmp or bcmath; skipping them\n" );

        return $out;
    }

    if ( function_exists( 'gmp_init' ) ) {
        $start = gmp_init( $from );
        $end   = gmp_init( $to );
        $one   = gmp_init( 1 );
        $two   = gmp_init( 2 );
        while ( gmp_cmp( $start, $end ) <= 0 ) {
            $low  = gmp_and( $start, gmp_neg( $start ) );
            if ( 0 === gmp_sign( $low ) ) {
                $low = $one;
            }
            $diff = gmp_add( gmp_sub( $end, $start ), 1 );
            while ( gmp_cmp( $low, $diff ) > 0 ) {
                $low = gmp_div_q( $low, $two );
            }
            $bits  = gmp_strval( $low, 2 );
            $prefix = 128 - strlen( $bits );
            $hex    = str_pad( gmp_strval( $start, 16 ), 32, '0', STR_PAD_LEFT );
            $text   = @inet_ntop( pack( 'H*', $hex ) );
            if ( false === $text || '' === $text ) {
                break;
            }
            $out[]  = $text . '/' . $prefix;
            $start  = gmp_add( $start, $low );
        }

        return $out;
    }

    fwrite( STDERR, "bcmath v6 expansion not implemented; use gmp\n" );

    return $out;
}

$args        = $argv;
$use_cloud   = in_array( '--cloud', $args, true );
$px_path     = '';
$px_index    = array_search( '--px-csv', $args, true );
$out_dir     = dirname( __DIR__ ) . '/plugin/assets/data';
$date        = date( 'Y-m-d' );

$at = array_search( '--out-dir', $args, true );
if ( false !== $at && isset( $args[ (int) $at + 1 ] ) ) {
    $out_dir = $args[ (int) $at + 1 ];
}
if ( false !== $px_index && isset( $args[ (int) $px_index + 1 ] ) ) {
    $px_path = $args[ (int) $px_index + 1 ];
}
$at = array_search( '--date', $args, true );
if ( false !== $at && isset( $args[ (int) $at + 1 ] ) ) {
    $date = $args[ (int) $at + 1 ];
}

if ( ! $use_cloud && '' === $px_path ) {
    fwrite( STDERR, "usage: php tools/pack-dch.php [--cloud] [--px-csv <ip2proxy-lite.csv>] [--out-dir <dir>] [--date YYYY-MM-DD]\n" );
    exit( 1 );
}

$lines   = array();
$sources = array();

if ( $use_cloud ) {
    // AWS: one JSON, both families.
    $aws = json_decode( gr_dch_fetch( 'https://ip-ranges.amazonaws.com/ip-ranges.json' ), true );
    if ( ! is_array( $aws ) ) {
        fwrite( STDERR, "AWS fetch failed\n" );
        exit( 1 );
    }
    $aws_v4 = 0;
    $aws_v6 = 0;
    foreach ( (array) ( $aws['prefixes'] ?? array() ) as $row ) {
        if ( isset( $row['ip_prefix'] ) ) { $lines[] = $row['ip_prefix']; ++$aws_v4; }
    }
    foreach ( (array) ( $aws['ipv6_prefixes'] ?? array() ) as $row ) {
        if ( isset( $row['ipv6_prefix'] ) ) { $lines[] = $row['ipv6_prefix']; ++$aws_v6; }
    }
    echo "aws: v4={$aws_v4} v6={$aws_v6}\n";
    $sources[] = 'AWS official ip-ranges.json';

    // Azure: the dated ServiceTags URL is discovered from the stable page.
    $page = gr_dch_fetch( 'https://www.microsoft.com/en-us/download/details.aspx?id=56519' );
    if ( 1 !== preg_match( '#https://download\.microsoft\.com/[^"\']*ServiceTags_Public_[0-9]+\.json#', $page, $m ) ) {
        fwrite( STDERR, "Azure ServiceTags URL discovery failed; continuing without Azure\n" );
    } else {
        $azure = json_decode( gr_dch_fetch( $m[0] ), true );
        if ( ! is_array( $azure ) ) {
            fwrite( STDERR, "Azure fetch failed; continuing without Azure\n" );
        } else {
            $az = 0;
            foreach ( (array) ( $azure['values'] ?? array() ) as $value ) {
                foreach ( (array) ( $value['properties']['addressPrefixes'] ?? array() ) as $prefix ) {
                    if ( is_string( $prefix ) && '' !== $prefix ) { $lines[] = $prefix; ++$az; }
                }
            }
            echo "azure: {$az} prefixes\n";
            $sources[] = 'Microsoft Azure ServiceTags Public JSON';
        }
    }

    // Google: one JSON, both families.
    $goog = json_decode( gr_dch_fetch( 'https://www.gstatic.com/ipranges/goog.json' ), true );
    if ( ! is_array( $goog ) ) {
        fwrite( STDERR, "Google fetch failed\n" );
        exit( 1 );
    }
    $g = 0;
    foreach ( (array) ( $goog['prefixes'] ?? array() ) as $row ) {
        foreach ( array( 'ipv4Prefix', 'ipv6Prefix' ) as $key ) {
            if ( isset( $row[ $key ] ) ) { $lines[] = $row[ $key ]; ++$g; }
        }
    }
    echo "google: {$g} prefixes\n";
    $sources[] = 'Google official goog.json';
}

if ( '' !== $px_path ) {
    if ( ! is_readable( $px_path ) ) {
        fwrite( STDERR, "cannot read {$px_path}\n" );
        exit( 1 );
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- dev-time build tool.
    $in   = fopen( $px_path, 'rb' );
    $rows = 0;
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets -- dev-time build tool.
    while ( false !== ( $line = fgets( $in ) ) ) {
        $parts = explode( ',', trim( $line ) );
        if ( count( $parts ) < 3 ) {
            continue;
        }
        if ( 'DCH' !== strtoupper( trim( $parts[2] ) ) ) {
            continue;
        }
        $is_v4 = bccomp( trim( $parts[1] ), '4294967295' ) <= 0;
        foreach ( gr_dch_range_to_cidrs( trim( $parts[0] ), trim( $parts[1] ), $is_v4 ) as $cidr ) {
            $lines[] = $cidr;
            ++$rows;
        }
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- dev-time build tool.
    fclose( $in );
    echo "ip2proxy DCH: {$rows} cidrs\n";
    $sources[] = 'IP2Proxy LITE PX DCH rows (CC BY-SA 4.0, https://lite.ip2location.com)';
}

$lines = array_values( array_unique( $lines ) );

$source_sentence = implode( '; ', $sources );

$header  = "# greenpng datacenter CIDR list (ADR-0011 D1) — generated, do not edit.\n";
$header .= "# Built: {$date}\n";
$header .= "# Sources: {$source_sentence}\n";
$header .= "# IP2Proxy LITE (when present here) is CC BY-SA 4.0 — attribution in\n";
$header .= "# the plugin NOTICE; updates require registering at lite.ip2location.com.\n";
$header .= "# One CIDR per line; '#' lines are provenance, never data.\n";

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- dev-time build tool.
file_put_contents( $out_dir . '/' . Gr_Dch_Packer::TEXT_FILE, $header . implode( "\n", $lines ) . "\n" );

$result = Gr_Dch_Packer::pack( $out_dir . '/' . Gr_Dch_Packer::TEXT_FILE, $out_dir, $date, $source_sentence );

echo 'text_lines=' . count( $lines ) . "\n";
echo 'v4_ranges=' . $result['v4_ranges'] . "\n";
echo 'v6_ranges=' . $result['v6_ranges'] . "\n";
echo 'skipped=' . $result['skipped'] . "\n";
echo 'out=' . $out_dir . "\n";
