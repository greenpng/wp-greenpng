<?php
/**
 * Blackhole trap (docs/13 W12, PEER-01, docs/10 §4): a virtual path
 * that exists only in robots.txt, declared there so every
 * robots-respecting spider skips it by design — only a crawler that
 * ignores robots.txt ever arrives, which is the finding itself. The
 * hit lands in the fold log; block mode additionally hands the
 * address to the temporary-ban layer (W5), which the login gate
 * already honors. The module is opt-in and record-only by default;
 * the master fuse (W11) stands it down like every other module.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Request;

/**
 * Static engine behind the virtual trap endpoint.
 */
final class Gr_Blackhole {

    /** The trap path: virtual, never routed, declared in robots.txt. */
    public const TRAP_PATH = '/gr-blackhole/';

    /** Fold-log rule id. */
    public const RULE_ID = 'blackhole';

    /** Block-mode ban window. */
    private const BAN_TTL = DAY_IN_SECONDS;

    /**
     * Hook wiring: the endpoint intercepts at init@5, ahead of the
     * inspection frame, and the robots.txt declaration rides the core
     * filter.
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( 'init', array( self::class, 'intercept' ), 5, 0 );
        add_filter( 'robots_txt', array( self::class, 'robots_rules' ), 10, 1 );
    }

    /**
     * The robots.txt declaration: one more group disallowing the trap
     * path. Good spiders read it and never visit; the declaration is
     * the mechanism, not decoration. The public flag core passes
     * alongside does not change anything — the trap is worth
     * declaring on private sites too, so it is not accepted.
     *
     * @param string $output Robots.txt content so far.
     * @return string
     */
    public static function robots_rules( string $output ): string {
        if ( ! self::enabled() ) {
            return $output;
        }

        return $output . "\nUser-agent: *\nDisallow: " . self::TRAP_PATH . "\n";
    }

    /**
     * The virtual endpoint: a hit is judged, logged, optionally
     * banned, and answered with a cache-proof 403 — the path has no
     * content, so the response is the trap closing behind the visitor.
     * wp_die sends the no-cache headers and terminates; the unit stub
     * records instead of dying.
     *
     * @return void
     */
    public static function intercept(): void {
        if ( ! self::is_hit() ) {
            return;
        }

        self::handle_hit();

        wp_die( esc_html__( 'Access denied.', 'greenpng' ), '', array( 'response' => 403 ) );
    }

    /**
     * Whether the current request walks into the trap. The comparison
     * runs on the path alone (query string stripped) and normalizes
     * trailing slashes, so scanners that drop or add the slash still
     * land.
     *
     * @return bool
     */
    public static function is_hit(): bool {
        if ( ! self::enabled() ) {
            return false;
        }

        $path = wp_parse_url( Gr_Request::path(), PHP_URL_PATH );
        if ( ! is_string( $path ) || '' === $path ) {
            return false;
        }

        return self::normalize( $path ) === self::normalize( self::TRAP_PATH );
    }

    /**
     * One hit: fold-log row always; a temporary ban only when the
     * owner escalated the security action mode. The ban is what the
     * login gate and every future blocking surface already honor.
     *
     * @return void
     */
    public static function handle_hit(): void {
        $ip = Gr_Ip_Resolver::resolve();

        gr_log_security_event(
            $ip,
            self::RULE_ID,
            Gr_Request::path(),
            Gr_Request::user_agent(),
            'disallowed trap path crawled'
        );

        if ( 'block' === (string) gr()->settings()->get( 'security_action_mode' ) ) {
            Gr_Temp_Bans::block( $ip, 'blackhole: disallowed path', self::BAN_TTL );
        }
    }

    /**
     * Opt-in contract: the master fuse (W11) AND the module's own
     * setting, default off.
     *
     * @return bool
     */
    private static function enabled(): bool {
        return Gr_Security_Gate::active() && 1 === (int) gr()->settings()->get( 'blackhole_enabled' );
    }

    /**
     * Path shape both sides agree on: slash-wrapped.
     *
     * @param string $path Raw path.
     * @return string
     */
    private static function normalize( string $path ): string {
        return '/' . trim( $path, '/' ) . '/';
    }
}
