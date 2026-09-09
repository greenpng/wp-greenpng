<?php
/**
 * WP-CLI surface: lets a real system cron drive the daily maintenance
 * routine instead of relying on visitor-triggered WP-Cron (docs/02 §2.4).
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers the `wp greenpng` command namespace when WP-CLI is present.
 */
final class Gr_Cli {

    /**
     * Adds the command; a no-op outside CLI context.
     *
     * @return void
     */
    public static function register(): void {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
            return;
        }

        WP_CLI::add_command( 'greenpng', self::class );
    }

    /**
     * Runs the daily maintenance routine immediately under the queue mutex.
     *
     * Intended for a system cron entry so low-traffic sites get maintenance
     * at fixed times instead of whenever a visitor happens to trigger
     * WP-Cron. Zero-parameter on purpose: WP-CLI invokes command methods
     * with ( $args, $assoc_args ) regardless, and PHP ignores arguments a
     * method does not declare.
     *
     * ## EXAMPLES
     *
     *     # Drive maintenance from a real system cron entry.
     *     wp greenpng maintenance
     *
     * @return void
     */
    public function maintenance(): void {
        $ran = Gr_Queue::run_daily();

        if ( false === $ran ) {
            WP_CLI::warning( __( 'Skipped: another maintenance run holds the lock and started less than 5 minutes ago.', 'greenpng' ) );
            return;
        }

        WP_CLI::success( __( 'Daily maintenance finished.', 'greenpng' ) );
    }
}
