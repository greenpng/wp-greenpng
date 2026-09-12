<?php
/**
 * Repository build tool: packs a DB-IP country-lite CSV into the
 * bundled binary format under plugin/assets/data/. Run from the
 * repository root with the local PHP:
 *
 *   php tools/pack-geoip.php /path/to/dbip-country-lite.csv 2026-09
 *
 * The data date argument lands in the shipped meta file and NOTICE;
 * it must match the release the CSV came from. Not shipped in the
 * plugin package (build-zip excludes tools/).
 *
 * @package GreenPNG
 */

define( 'ABSPATH', '/tmp/' );

require __DIR__ . '/../plugin/includes/core/class-gr-geoip-packer.php';

if ( 2 > $argc ) {
    fwrite( STDERR, "usage: php tools/pack-geoip.php <csv> [build-date]\n" );
    exit( 1 );
}

$csv   = (string) $argv[1];
$date  = $argv[2] ?? '';
$out   = dirname( __DIR__ ) . '/plugin/assets/data';

if ( ! is_readable( $csv ) ) {
    fwrite( STDERR, "cannot read {$csv}\n" );
    exit( 1 );
}

$result = GreenPNG\Core\Gr_Geoip_Packer::pack( $csv, $out, $date );

echo 'v4_ranges=' . $result['v4_ranges'] . "\n";
echo 'v6_ranges=' . $result['v6_ranges'] . "\n";
echo 'countries=' . $result['countries'] . "\n";
echo 'skipped=' . $result['skipped'] . "\n";
echo 'out=' . $out . "\n";

exit( 0 === $result['v4_ranges'] + $result['v6_ranges'] ? 1 : 0 );
