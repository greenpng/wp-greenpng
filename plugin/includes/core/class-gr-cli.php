<?php
/**
 * WP-CLI surface: lets a real system cron drive the daily maintenance
 * routine instead of relying on visitor-triggered WP-Cron (docs/02 §2.4).
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

use GreenPNG\Security\Gr_Temp_Bans;
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

    /**
     * Releases a temporary IP lock placed by gr_block_ip().
     *
     * The no-lock outcome is a warning, not an error: the recovery
     * goal is "the site owner is never stuck", so an idempotent
     * repeat must not look like a failure. Static ban rules are out
     * of scope here — they are managed from the Access Rules page.
     *
     * ## OPTIONS
     *
     * <ip>
     * : The address whose temporary lock should be released.
     *
     * ## EXAMPLES
     *
     *     # Free an address locked by the login-failure counter.
     *     wp greenpng unblock 203.0.113.7
     *
     * @param array<int, string> $args Positional args; $args[0] is the IP.
     * @return void
     */
    public function unblock( array $args ): void {
        $ip = isset( $args[0] ) ? trim( (string) $args[0] ) : '';

        if ( '' === $ip ) {
            WP_CLI::error( __( 'No address given: wp greenpng unblock <ip>', 'greenpng' ) );
        }

        if ( ! Gr_Temp_Bans::unblock( $ip ) ) {
            WP_CLI::warning(
                sprintf(
                    /* translators: %s: IP address. */
                    __( 'No temporary lock found for %s. Static ban rules are managed on the Access Rules page; the allow list also overrides every ban.', 'greenpng' ),
                    $ip
                )
            );
            return;
        }

        WP_CLI::success(
            sprintf(
                /* translators: %s: IP address. */
                __( 'Temporary lock released for %s.', 'greenpng' ),
                $ip
            )
        );
    }
}
