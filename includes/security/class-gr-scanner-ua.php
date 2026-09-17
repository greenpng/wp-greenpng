<?php
/**
 * Scanner user-agent engine (docs/07 §3): the self-maintained data
 * files under assets/data/ are compiled once per request into two
 * alternation regexes. Browser-token exclusions strip the agent
 * first — a remainder of nothing means an ordinary browser and the
 * crawler regex never runs; a hit means a crawler/scanner agent.
 * Every failure mode (missing data, PCRE error) fails open: an
 * unreadable rule set must never turn ordinary visitors into
 * findings, and this engine performs zero network calls.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Static engine behind gr_is_scanner_ua() and the first W-phase
 * detector on the request inspector.
 */
final class Gr_Scanner_Ua {

    /** Crawler pattern file, relative to the plugin data dir. */
    private const CRAWLER_FILE = 'gr-ua-crawlers.txt';

    /** Exclusion pattern file, relative to the plugin data dir. */
    private const EXCLUSION_FILE = 'gr-ua-exclusions.txt';

    /**
     * Compiled crawler alternation, null until the first call.
     *
     * @var string|null
     */
    private static ?string $crawler_regex = null;

    /**
     * Compiled exclusion alternation, null until the first call.
     *
     * @var string|null
     */
    private static ?string $exclusion_regex = null;

    /**
     * Data dir override for tests; null means the shipped plugin dir.
     *
     * @var string|null
     */
    private static ?string $data_dir_override = null;

    /**
     * Whether the one-time compile already ran (a failed load stays
     * failed instead of re-reading the file every request).
     *
     * @var bool
     */
    private static bool $compiled = false;

    /**
     * Scanner verdict for a user agent.
     *
     * @param string $ua Raw user agent.
     * @return bool True when the agent matches a crawler pattern.
     */
    public static function is_scanner( string $ua ): bool {
        return '' !== self::matched_token( $ua );
    }

    /**
     * The crawler substring that matched, after exclusions stripped
     * the browser tokens. Empty string means no scanner verdict —
     * which is also the answer for unreadable data, a PCRE failure,
     * and an agent that strips to nothing. This is the single match
     * path: is_scanner() is a verdict on its return value, so the
     * inspector detector pays one crawler match per request.
     *
     * @param string $ua Raw user agent.
     * @return string Matched fragment, '' when not a scanner.
     */
    public static function matched_token( string $ua ): string {
        if ( '' === trim( $ua ) ) {
            return '';
        }

        self::compile();
        if ( null === self::$crawler_regex || null === self::$exclusion_regex ) {
            return '';
        }

        $stripped = preg_replace( self::$exclusion_regex, '', $ua );
        if ( null === $stripped || '' === trim( $stripped ) ) {
            return '';
        }

        $hit = preg_match( self::$crawler_regex, trim( $stripped ), $matches );
        if ( 1 !== $hit ) {
            return '';
        }

        return $matches[0];
    }

    /**
     * Inspector detector registration (docs/02 §2.7): appends the
     * scanner-UA check under the public filter so the frame runs it
     * on every inspected front-end request. The frame itself gates
     * on security_enabled, so a disabled switch means this engine's
     * regex never compiles.
     *
     * @return void
     */
    public static function register_detector(): void {
        add_filter(
            Gr_Request_Inspector::CHECKS_FILTER,
            static function ( array $checks ) {
                $checks['scanner_ua'] = static function ( array $context ) {
                    $ua = isset( $context['ua'] ) && is_scalar( $context['ua'] ) ? (string) $context['ua'] : '';
                    if ( '' === $ua ) {
                        return null;
                    }

                    $token = self::matched_token( $ua );
                    if ( '' === $token ) {
                        return null;
                    }

                    return array(
                        'rule_id' => 'scanner_ua',
                        'reason'  => 'ua:' . $token,
                    );
                };

                return $checks;
            }
        );
    }

    /**
     * One-time per request: reads both data files and compiles the
     * alternations. Header (#) and blank lines are skipped; a
     * leading space in a pattern is meaningful and survives. A file
     * that cannot be read or yields no patterns leaves the regex
     * null — every verdict then fails open to human.
     *
     * @return void
     */
    private static function compile(): void {
        if ( self::$compiled ) {
            return;
        }
        self::$compiled = true;

        $crawlers   = self::load_patterns( self::CRAWLER_FILE );
        $exclusions = self::load_patterns( self::EXCLUSION_FILE );

        if ( array() === $crawlers || array() === $exclusions ) {
            return;
        }

        self::$crawler_regex   = '/(?:' . implode( '|', $crawlers ) . ')/i';
        self::$exclusion_regex = '/(?:' . implode( '|', $exclusions ) . ')/i';
    }

    /**
     * Pattern lines from one data file.
     *
     * @param string $file File name inside the data dir.
     * @return array<int, string> Patterns, possibly empty.
     */
    private static function load_patterns( string $file ): array {
        $dir = self::data_dir();
        if ( '' === $dir ) {
            return array();
        }

        // file()+FILE_IGNORE_NEW_LINE is avoided on purpose: this
        // reads identically on every PHP build without depending on
        // optional file() flag constants.
        $path = $dir . $file;
        if ( ! is_readable( $path ) ) {
            return array();
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local read of a bundled data file; wp_remote_get is for remote URLs and the sniff misfires on filesystem access.
        $raw = file_get_contents( $path );
        if ( ! is_string( $raw ) ) {
            return array();
        }

        $patterns = array();
        foreach ( explode( "\n", $raw ) as $line ) {
            $line = rtrim( $line, "\r" );
            if ( '' === $line || '#' === $line[0] ) {
                continue;
            }
            $patterns[] = $line;
        }

        return $patterns;
    }

    /**
     * Data dir: test override when set, otherwise the shipped plugin
     * assets dir; '' when the constant is unavailable.
     *
     * @return string
     */
    private static function data_dir(): string {
        if ( null !== self::$data_dir_override ) {
            return rtrim( self::$data_dir_override, '/\\' ) . '/';
        }

        if ( ! defined( 'GR_PLUGIN_DIR' ) ) {
            return '';
        }

        return constant( 'GR_PLUGIN_DIR' ) . 'assets/data/';
    }

    /**
     * Test seam: memo and data-dir override reset, so tests can point
     * the engine at fixture files or prove fail-open on a missing dir.
     *
     * @param string|null $dir Data dir override, null for the shipped dir.
     * @return void
     */
    public static function reset_for_tests( ?string $dir = null ): void {
        self::$crawler_regex     = null;
        self::$exclusion_regex   = null;
        self::$compiled          = false;
        self::$data_dir_override = $dir;
    }
}
