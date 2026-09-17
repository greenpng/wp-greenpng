<?php
/**
 * Master security fuse (docs/13 W11, docs/10 §4): every security
 * module asks this one question before doing any work. Two switches,
 * one answer — the GR_SECURITY_OFF constant in wp-config is the
 * emergency escape that outranks everything (a site can be repaired
 * without the database), and the security_enabled setting is the
 * owner's day-to-day switch. When the answer is false, inspections
 * do not run, forms carry no traps, login gates stand down, and
 * queue work skips itself; a kill is always complete, never partial.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Static choke point for the whole security stack.
 */
final class Gr_Security_Gate {

    /**
     * Emergency-fuse override for tests: null reads the real constant,
     * true/false forces the verdict. Mirrors the data-dir override
     * seam on the scanner engine — process isolation is unreliable on
     * some PHP builds, and the fuse must stay testable everywhere.
     *
     * @var bool|null
     */
    private static ?bool $emergency_override = null;

    /**
     * Whether any security module may work right now.
     *
     * @return bool
     */
    public static function active(): bool {
        if ( self::emergency_off() ) {
            return false;
        }

        return 1 === (int) gr()->settings()->get( 'security_enabled' );
    }

    /**
     * Whether the emergency constant fuse is pulled. Kept separate so
     * the status page can say WHY the stack is down: an owner switch
     * is a preference, an emergency constant is a repair in progress.
     *
     * @return bool
     */
    public static function emergency_off(): bool {
        if ( null !== self::$emergency_override ) {
            return self::$emergency_override;
        }

        return defined( 'GR_SECURITY_OFF' ) && (bool) constant( 'GR_SECURITY_OFF' );
    }

    /**
     * Test seam: clear the override (null = read the real constant).
     *
     * @param bool|null $emergency Forced verdict, null for production.
     * @return void
     */
    public static function reset_for_tests( ?bool $emergency = null ): void {
        self::$emergency_override = $emergency;
    }
}
