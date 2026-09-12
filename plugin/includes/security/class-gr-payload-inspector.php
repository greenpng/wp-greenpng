<?php
/**
 * Payload inspection ruleset (docs/13 W9, docs/03 §3, docs/10 §4): the
 * conservative rewrite of the reference project's 30-regex WAF. Two
 * high-confidence families only — SQL UNION injection shapes and
 * multi-hop path traversal chains — because those byte sequences do
 * not occur in text a human writes. Every lookalike that plausibly
 * appears in honest content (lone `<?php`, `eval(`, hex blobs,
 * `document.cookie`, `wp-config.php` mentions, single `../` hops) is
 * deliberately out of scope: a missed scan costs one log line, a
 * wrong hit costs a wrongly judged visitor. Findings are record-only
 * and can never escalate to a block — docs/10 §4 reserves tier 3 for
 * scanner UA, honeypot, and explicit bans.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Static engine behind gr_inspect_request_payload() and the payload
 * detector on the request inspector frame.
 */
final class Gr_Payload_Inspector {

    /** SQL UNION injection family. */
    public const RULE_SQLI = 'sqli_union';

    /** Multi-hop path traversal family. */
    public const RULE_LFI = 'lfi_traversal';

    /**
     * Front-door parameter names that are never attack surface: the
     * search query is where tutorial text about SQL lives, and the
     * marketing tags are attribution data, not scanner input.
     */
    private const SKIP_NAMES = array( 's', 'gclid', 'fbclid', 'msclkid', 'mc_cid', 'mc_eid' );

    /** Front-door parameter name prefixes skipped for the same reason. */
    private const SKIP_PREFIXES = array( 'utm_' );

    /**
     * Scanned bytes per value — the match cost is bounded by the
     * input, and the input is bounded here.
     */
    private const VALUE_MAX = 4096;

    /**
     * Reason cap below the log column width; the frame clamps again
     * at the write boundary.
     */
    private const REASON_MAX = 120;

    /**
     * Pure engine: inspects one name => value array and returns the
     * findings. First rule family that hits a parameter settles that
     * parameter — one row per param keeps the fold log honest while
     * a noisy multi-rule payload still lands as one row per family.
     *
     * @param array<int|string, mixed> $data Parameter name => raw value.
     * @return array<int, array<string, string>> rule_id + reason rows.
     */
    public static function inspect( array $data ): array {
        $findings = array();

        foreach ( $data as $name => $value ) {
            if ( ! is_scalar( $value ) ) {
                continue;
            }

            $value = (string) $value;
            if ( '' === $value ) {
                continue;
            }

            foreach ( self::rules() as $rule_id => $pattern ) {
                // A PCRE failure counts as no match: the engine fails
                // open exactly like every other detector.
                if ( 1 !== preg_match( $pattern, substr( $value, 0, self::VALUE_MAX ), $matches ) ) {
                    continue;
                }

                $findings[] = array(
                    'rule_id' => $rule_id,
                    'reason'  => self::reason( (string) $name, $matches[0] ),
                );
                break;
            }
        }

        return $findings;
    }

    /**
     * Inspector detector registration (docs/02 §2.7): appends the
     * payload check under the public filter. The front-door scope is
     * GET parameters only — POST bodies are where commenters and
     * form users submit content, the exact false-positive class
     * docs/10 §4 exists to prevent, while scanner recon and attack
     * traffic overwhelmingly arrives as crafted query strings. The
     * frame gates on security_enabled, so a disabled switch means
     * this engine never runs.
     *
     * @return void
     */
    public static function register_detector(): void {
        add_filter(
            Gr_Request_Inspector::CHECKS_FILTER,
            static function ( array $checks ) {
                $checks['payload_rules'] = static function () {
                    return self::inspect( self::front_door_params() );
                };

                return $checks;
            }
        );
    }

    /**
     * The current request's inspectable GET parameters: scalars,
     * unslashed, minus the search and marketing names. Names are
     * attacker-chosen too, so the skip comparison runs on a
     * lowercased copy while the original spelling survives into the
     * finding reason.
     *
     * @return array<int|string, mixed>
     */
    private static function front_door_params(): array {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only judgement of passive query parameters; no state change and no output ever depends on them, matching the attribution listener's stance on the same superglobal.
        if ( ! isset( $_GET ) || ! is_array( $_GET ) ) {
            return array();
        }

        $params = array();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same read-only pass over the query string; see the guard above.
        foreach ( $_GET as $name => $value ) {
            if ( ! is_scalar( $value ) ) {
                continue;
            }

            $lower = strtolower( (string) $name );
            if ( in_array( $lower, self::SKIP_NAMES, true ) ) {
                continue;
            }

            $skipped = false;
            foreach ( self::SKIP_PREFIXES as $prefix ) {
                if ( 0 === strpos( $lower, $prefix ) ) {
                    $skipped = true;
                    break;
                }
            }
            if ( $skipped ) {
                continue;
            }

            $params[ $name ] = wp_unslash( $value );
        }

        return $params;
    }

    /**
     * The two rule families, fixed order. Separator class between
     * UNION and SELECT covers the shapes scanners actually send:
     * whitespace, the MySQL comment splice, and URL-encoded space.
     * The traversal hop covers literal and percent-encoded dots with
     * both slash flavors; two hops minimum because one `../` is a
     * routine relative path.
     *
     * @return array<string, string>
     */
    private static function rules(): array {
        return array(
            self::RULE_SQLI => '~\bunion(?:(?:\s+)|/\*\*/|\+)+(?:all(?:(?:\s+)|/\*\*/|\+)+)?select\b~i',
            self::RULE_LFI  => '~(?:(?:\.\.|%2e%2e)(?:[/\\\\]|%2f|%5c)){2,}~i',
        );
    }

    /**
     * Finding reason: parameter name plus matched fragment, sanitized
     * as one string because both halves are attacker-controlled and
     * that joined string is what the log row stores.
     *
     * @param string $name  Parameter name.
     * @param string $token Matched fragment.
     * @return string
     */
    private static function reason( string $name, string $token ): string {
        return substr( sanitize_text_field( $name . ':' . substr( $token, 0, 60 ) ), 0, self::REASON_MAX );
    }
}
