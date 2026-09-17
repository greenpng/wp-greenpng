<?php
/**
 * Param-map expression resolver (docs/03 §9): turns the rule's
 * bounded expressions into payload values at hit time. Two forms are
 * documented: an args path (`args[0].total`, bracket or dot style)
 * walking the hook arguments, and an `auto:` key resolving the
 * admin-request context. Everything else is a literal string.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Integrations\Ecosystem;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One expression -> one payload value.
 */
final class Gr_Param_Resolver {

    /** Context keys the auto: form may resolve. */
    public const AUTO_KEYS = array( 'user_id', 'email', 'login', 'ip' );

    /**
     * Resolves one expression against the hook args and the admin
     * request context.
     *
     * @param string               $expression Rule expression.
     * @param array<int, mixed>    $args       Hook arguments.
     * @param array<string, mixed> $context    Overrides for the auto keys.
     * @return mixed The resolved value, or null when the path misses.
     */
    public static function resolve( string $expression, array $args, array $context = array() ) {
        $expression = trim( $expression );

        if ( '' === $expression ) {
            return null;
        }

        if ( 0 === strpos( $expression, 'auto:' ) ) {
            return self::auto( substr( $expression, 5 ), $context );
        }

        $segments = self::segments( $expression );

        // Anything that is not a full args path (root plus at least
        // one segment) or an auto key is a literal value: the
        // merchant's payload says what it says.
        if ( count( $segments ) < 2 || 'args' !== $segments[0] ) {
            return $expression;
        }

        return self::walk( $args, array_slice( $segments, 1 ) );
    }

    /**
     * Splits an expression into path segments; bracket and dot
     * styles mean the same thing.
     *
     * @param string $expression Raw expression.
     * @return array<int, string>
     */
    private static function segments( string $expression ): array {
        $flat = str_replace( array( '[', ']' ), array( '.', '' ), $expression );
        $flat = trim( $flat, '.' );
        if ( '' === $flat ) {
            return array();
        }

        $segments = array();
        foreach ( explode( '.', $flat ) as $segment ) {
            $segment = trim( $segment );
            if ( '' === $segment ) {
                return array();
            }
            $segments[] = $segment;
        }

        return $segments;
    }

    /**
     * Walks arrays (and only arrays) along the remaining path.
     *
     * @param mixed              $node     Current node.
     * @param array<int, string> $segments Remaining path segments.
     * @return mixed
     */
    private static function walk( $node, array $segments ) {
        foreach ( $segments as $segment ) {
            if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
                return null;
            }
            $node = $node[ $segment ];
        }

        return $node;
    }

    /**
     * Resolves one auto: context key; an override in the context
     * array wins, the built-ins come from the admin request itself.
     *
     * @param string              $key     Auto key.
     * @param array<string,mixed> $context Caller overrides.
     * @return mixed
     */
    private static function auto( string $key, array $context ) {
        $key = trim( $key );
        if ( '' === $key || ! in_array( $key, self::AUTO_KEYS, true ) ) {
            return null;
        }

        if ( array_key_exists( $key, $context ) ) {
            return $context[ $key ];
        }

        if ( 'user_id' === $key ) {
            return get_current_user_id();
        }

        if ( 'ip' === $key ) {
            return gr_get_client_ip();
        }

        $user = wp_get_current_user();
        if ( 0 === (int) $user->ID ) {
            return '';
        }

        if ( 'email' === $key ) {
            return (string) $user->user_email;
        }

        return (string) $user->user_login;
    }
}
