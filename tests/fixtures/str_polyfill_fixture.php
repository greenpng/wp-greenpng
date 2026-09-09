<?php
/**
 * Fixture: PHP 8.0 string functions polyfilled by WordPress core (compat.php,
 * verified in docs/14 §1). The PHPCompatibilityWP run (AGENTS §3.3 check #3)
 * must report zero errors for this file at testVersion 7.4-.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Exercises the three polyfilled functions so the compatibility sniffs see them.
 *
 * @param string $haystack Subject string.
 * @param string $needle   Search string.
 * @return array<string, bool>
 */
function gr_fixture_str_checks( string $haystack, string $needle ): array {
    return array(
        'contains' => str_contains( $haystack, $needle ),
        'starts'   => str_starts_with( $haystack, $needle ),
        'ends'     => str_ends_with( $haystack, $needle ),
    );
}
