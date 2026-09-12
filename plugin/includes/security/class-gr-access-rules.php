<?php
/**
 * Access rule evaluation (docs/13 W4, docs/10 §4): the allow/ban
 * model behind gr_is_trusted_ip(), gr_is_ip_blocked(), and
 * gr_is_url_allowed(). Rules load at most once per request (the L1
 * memo; the load is lazy, so a disabled security switch or a request
 * no detector inspects pays zero SQL — docs/09 §1.1's 0~1 line).
 *
 * Precedence is allow-over-ban: an address on the allow list can
 * never read as blocked, which keeps the documented recovery valve
 * ("the site owner must never lock themselves out with no recourse")
 * true for static bans just as for the W5 transient locks.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Storage\Gr_Access_Rules_Repository;

/**
 * Static rule evaluator; the memo lives for one request.
 */
final class Gr_Access_Rules {

    /** Rule type: allow-list entries. */
    public const TYPE_ALLOW = 'allow';

    /** Rule type: ban entries. */
    public const TYPE_BAN = 'ban';

    /** Match kind: CIDR or bare IP. */
    public const KIND_IP = 'ip';

    /** Match kind: URL prefix or wildcard pattern. */
    public const KIND_URL = 'url';

    /**
     * Active rules, null until the first predicate call this request.
     *
     * @var array<int, array<string, string>>|null
     */
    private static ?array $rules = null;

    /**
     * Whether an address is on the allow list.
     *
     * @param string $ip Candidate address.
     * @return bool
     */
    public static function is_trusted_ip( string $ip ): bool {
        return self::matches_ip_rule( self::TYPE_ALLOW, $ip );
    }

    /**
     * Whether an address falls under a ban: a W5 temporary lock or a
     * static ban rule. The allow list wins first, so a trusted
     * address is never blocked by either.
     *
     * @param string $ip Candidate address.
     * @return bool
     */
    public static function is_ip_blocked( string $ip ): bool {
        if ( self::matches_ip_rule( self::TYPE_ALLOW, $ip ) ) {
            return false;
        }

        if ( Gr_Temp_Bans::is_locked( $ip ) ) {
            return true;
        }

        return self::matches_ip_rule( self::TYPE_BAN, $ip );
    }

    /**
     * Whether a URI is exempted by a URL allow rule. Values without a
     * wildcard match one whole path segment onward ('/checkout' covers
     * '/checkout' and '/checkout/thanks', never '/checkoutzone');
     * values containing '*' match the whole URI with '*' standing for
     * any run of characters.
     *
     * @param string $uri Request path, optionally with query.
     * @return bool
     */
    public static function is_url_allowed( string $uri ): bool {
        foreach ( self::rules() as $rule ) {
            if ( self::TYPE_ALLOW !== $rule['rule_type'] || self::KIND_URL !== $rule['match_kind'] ) {
                continue;
            }

            if ( self::url_matches( $uri, $rule['match_value'] ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Test seam: drops the memo so the next predicate call reloads.
     *
     * @return void
     */
    public static function reset_for_tests(): void {
        self::$rules = null;
    }

    /**
     * Drops the memo after a write: an owner who just added a rule
     * must not have the same request answer matches from the stale
     * copy (the admin redirect ends the request, but correctness
     * should not depend on that).
     *
     * @return void
     */
    public static function invalidate(): void {
        self::$rules = null;
    }

    /**
     * The memoized rule list; one repository read per request.
     *
     * @return array<int, array<string, string>>
     */
    private static function rules(): array {
        if ( null === self::$rules ) {
            self::$rules = ( new Gr_Access_Rules_Repository() )->active_rules();
        }

        return self::$rules;
    }

    /**
     * Whether any rule of one type matches an address.
     *
     * @param string $type TYPE_ALLOW or TYPE_BAN.
     * @param string $ip   Candidate address.
     * @return bool
     */
    private static function matches_ip_rule( string $type, string $ip ): bool {
        foreach ( self::rules() as $rule ) {
            if ( $type !== $rule['rule_type'] || self::KIND_IP !== $rule['match_kind'] ) {
                continue;
            }

            if ( '' === trim( $rule['match_value'] ) ) {
                continue;
            }

            if ( Gr_Ip_Matcher::match( $ip, array( $rule['match_value'] ) ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * One URL allow value against one URI. The query string is split
     * off first — an exemption must not depend on whether the request
     * carried parameters.
     *
     * @param string $uri   Request URI.
     * @param string $value Rule value (prefix or wildcard pattern).
     * @return bool
     */
    private static function url_matches( string $uri, string $value ): bool {
        $value = trim( $value );
        if ( '' === $value ) {
            return false;
        }

        $query = strpos( $uri, '?' );
        if ( false !== $query ) {
            $uri = substr( $uri, 0, $query );
        }

        if ( false === strpos( $value, '*' ) ) {
            // Segment-safe prefix: the value must be the whole URI or
            // the start of a deeper path, so sibling paths that merely
            // share leading characters stay out.
            return $uri === $value || 0 === strpos( $uri, $value . '/' );
        }

        // Wildcard values compile with every other character quoted,
        // so pattern metacharacters in a rule value stay literal; the
        // match runs on raw bytes, which is what path comparison is.
        $pattern = '/^' . str_replace( '\*', '.*', preg_quote( $value, '/' ) ) . '$/';

        return 1 === preg_match( $pattern, $uri );
    }
}
