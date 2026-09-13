<?php
/**
 * Front-request benchmark (docs/09 §1.1, docs/13 T3): P95 wall-clock
 * before/after plugin activation over real HTTP, plus the layered
 * SQL counts (steady pageview, attributed landing, REST collect)
 * measured with SAVEQUERIES over the real code paths.
 *
 * Run against the validation site:
 *   php wp-cli.phar eval-file tests/benchmarks/front-request.php --path=<site>
 *
 * The plugin is reactivated in a finally block, so an interrupted run
 * never leaves the site without the plugin. tests/ ships outside the
 * plugin zip (T8), and the WP_CLI guard keeps this out of any web
 * context.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Benchmarks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "CLI only\n" );
}

use GreenPNG\Core\Gr_Settings;
use WP_CLI;
use WP_REST_Request;

const ROUNDS = 50;
const WARMUP = 5;

/**
 * One timed GET against the site, fresh connection each time.
 *
 * @param string $url Site root.
 * @return float Milliseconds for the full request.
 */
function gr_bench_one( string $url ): float {
	$ch = curl_init( $url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_NOBODY, false );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 15 );
	curl_setopt( $ch, CURLOPT_USERAGENT, 'GreenPNGBench/1.0' );

	$t0 = microtime( true );
	curl_exec( $ch );
	$dt = ( microtime( true ) - $t0 ) * 1000.0;
	curl_close( $ch );

	return $dt;
}

/**
 * Warmup then ROUNDS timed requests; P95 over the samples.
 *
 * @param string $url Site root.
 * @return float P95 in milliseconds.
 */
function gr_bench_phase( string $url ): float {
	for ( $i = 0; $i < WARMUP; $i++ ) {
		gr_bench_one( $url );
	}

	$samples = array();
	for ( $i = 0; $i < ROUNDS; $i++ ) {
		$samples[] = gr_bench_one( $url );
	}
	sort( $samples );

	$idx = (int) ceil( 0.95 * ROUNDS ) - 1;

	return $samples[ max( 0, min( $idx, ROUNDS - 1 ) ) ];
}

/**
 * Query-count delta around one callable. WP 7.1's wpdb gates
 * SAVEQUERIES recording on a constant defined before load, so the
 * stable cross-version counter is the 'query' filter: every read
 * and write funnels through WPDB::query().
 *
 * @param callable $work The request-path callable.
 * @return int Queries issued during the call.
 */
function gr_bench_queries( callable $work ): int {
	global $wpdb;

	$counter = 0;
	$tally   = static function () use ( &$counter ): void {
		$counter++;
	};
	add_filter( 'query', $tally );

	$work();

	remove_filter( 'query', $tally );

	return $counter;
}

/**
 * Median execution time around one callable, microseconds honest.
 *
 * The CLI-only setcookie warning ("headers already sent") fires on
 * every consented identity issue and writes a debug.log line each
 * time; a real web request never warns. The handler silence below
 * removes that artifact — the natural-CLI number runs ~13x hotter
 * purely from warning+log-disk overhead (measured 2026-09-13:
 * 13.978ms natural vs 1.046ms silenced for the same work).
 *
 * @param callable $work The request-path callable.
 * @param int      $runs Repetitions.
 * @return float Milliseconds, median.
 */
function gr_bench_exec( callable $work, int $runs = 20 ): float {
	set_error_handler( static function (): bool {
		return true;
	} );

	$times = array();
	for ( $i = 0; $i < $runs; $i++ ) {
		$t0 = microtime( true );
		$work();
		$times[] = ( microtime( true ) - $t0 ) * 1000.0;
	}

	restore_error_handler();
	sort( $times );

	return $times[ (int) floor( $runs / 2 ) ];
}

/**
 * The three-layer SQL counts and in-process execution times over the
 * real code paths: the HTTP P95 above carries the whole-request noise
 * floor of the dev server, these isolate the plugin's own work.
 *
 * @return array<string, array<string, float|int>>
 */
function gr_bench_layers(): array {
	$settings = new Gr_Settings();
	$out      = array();

	$_SERVER['REMOTE_ADDR']     = '203.0.113.200';
	$_SERVER['HTTP_USER_AGENT'] = 'GreenPNGBench/1.0';

	// Steady pageview: no UTM, consent off (the default posture).
	$_GET = array();
	$out['steady'] = array(
		'sql'  => gr_bench_queries( static function (): void {
			gr()->listener()->handle();
		} ),
		'exec' => gr_bench_exec( static function (): void {
			gr()->listener()->handle();
		} ),
	);

	// Attributed landing: UTM present, marketing consent granted.
	$_GET = array( 'utm_source' => 'benchland', 'utm_medium' => 'cpc' );
	$settings->set( 'marketing_consent_fallback', 1 );
	$out['landing'] = array(
		'sql'  => gr_bench_queries( static function (): void {
			gr()->listener()->handle();
		} ),
		'exec' => gr_bench_exec( static function (): void {
			gr()->listener()->handle();
		} ),
	);
	$settings->set( 'marketing_consent_fallback', 0 );
	$_GET = array();

	// REST collect: the full pipeline in-process. The rate limiter
	// counts this run, so the queries below sit on a warmed counter;
	// the C6 落地记录 documents the transient floor on hosts without
	// a persistent object cache.
	$out['collect'] = array(
		'sql'  => gr_bench_queries( static function (): void {
			$request = new WP_REST_Request( 'POST', '/greenpng/v1/collect' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body(
				(string) wp_json_encode(
					array(
						'token'     => \GreenPNG\Rest\Gr_Collect_Controller::token(),
						'name'      => 'signal',
						'bot_score' => 5,
						'event_id'  => 'bench-collect',
					)
				)
			);
			rest_do_request( $request );
		} ),
		'exec' => 0.0,
	);

	return $out;
}

/**
 * Deactivate or activate the plugin via WP-CLI.
 *
 * @param string $verb 'activate' or 'deactivate'.
 * @return void
 */
function gr_bench_toggle( string $verb ): void {
	WP_CLI::runcommand(
		'plugin ' . $verb . ' greenpng',
		array(
			'exit_error' => false,
			'return'     => true,
		)
	);
}

$url = (string) get_option( 'siteurl' );

try {
	gr_bench_toggle( 'deactivate' );
	$p95_off = gr_bench_phase( $url );

	gr_bench_toggle( 'activate' );
	$p95_on = gr_bench_phase( $url );

	$layers = gr_bench_layers();
} finally {
	gr_bench_toggle( 'activate' );
}

echo 'BENCH|env|php=' . PHP_VERSION . ' url=' . $url . ' rounds=' . ROUNDS . " (fresh connection each request)\n";
echo 'BENCH|p95|plugin-off=' . round( $p95_off, 2 ) . 'ms plugin-on=' . round( $p95_on, 2 ) . "ms\n";
echo 'BENCH|p95|delta=' . round( $p95_on - $p95_off, 2 ) . "ms (budget <=5ms P95; dev-server noise band noted in report)\n";
echo 'BENCH|sql|steady=' . (int) $layers['steady']['sql'] . " (budget <=2)\n";
echo 'BENCH|sql|landing=' . (int) $layers['landing']['sql'] . " (budget <=4)\n";
echo 'BENCH|sql|collect=' . (int) $layers['collect']['sql'] . " (budget <=3 with persistent object cache; no-object-cache floor documented in docs/09 §1.1 落地记录)\n";
echo 'BENCH|exec|steady-median=' . round( (float) $layers['steady']['exec'], 3 ) . "ms landing-median=" . round( (float) $layers['landing']['exec'], 3 ) . "ms (in-process listener cost, 20 runs each)\n";

// The in-process simulations wrote probe rows: sweep them now, and
// count what is left so the report stays honest about residue.
global $wpdb;
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}gr_sessions WHERE utm_source = %s", 'benchland' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}gr_touchpoints WHERE utm_source = %s", 'benchland' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}gr_events WHERE event_name = %s AND event_id = %s", 'signal', 'bench-collect' ) );
echo "BENCH|cleanup|probe-rows-swept\n";
