<?php
/**
 * Recursive audit diff (docs/03 §8): compares two
 * state snapshots and reports added, modified, and removed values
 * keyed by dotted paths. Pure arithmetic over arrays; callers own
 * what the snapshots mean. The comparison is strict on purpose —
 * the diff runs once, at write time, on values the caller produced,
 * and its result is what the audit row stores verbatim.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * State comparison behind gr_audit_diff().
 */
final class Gr_Audit_Diff {

    /**
     * Diffs two snapshots.
     *
     * @param array<string, mixed> $before Earlier state.
     * @param array<string, mixed> $after Later state.
     * @return array{added: array<string, mixed>, modified: array<string, array{old: mixed, new: mixed}>, removed: array<string, mixed>}
     */
    public static function diff( array $before, array $after ): array {
        $out = array(
            'added'    => array(),
            'modified' => array(),
            'removed'  => array(),
        );

        self::walk( $before, $after, '', $out );

        return $out;
    }

    /**
     * Walks one level: shared keys recurse when both sides are
     * arrays, otherwise a strict comparison decides; keys only one
     * side carries land in added or removed. Dotted paths assume
     * identifier keys — audit snapshots come from this plugin's own
     * structured arrays, none of which use dots in key names.
     *
     * @param array<string, mixed>                $before    Earlier state at this level.
     * @param array<string, mixed>                $after    Later state at this level.
     * @param string                              $prefix Parent path, '' at the root.
     * @param array<string, array<string, mixed>> $out   Output accumulator.
     * @return void
     */
    private static function walk( array $before, array $after, string $prefix, array &$out ): void {
        foreach ( $after as $key => $value ) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . (string) $key;

            if ( ! array_key_exists( $key, $before ) ) {
                $out['added'][ $path ] = $value;
                continue;
            }

            $before_value = $before[ $key ];

            if ( is_array( $value ) && is_array( $before_value ) ) {
                self::walk( $before_value, $value, $path, $out );
                continue;
            }

            if ( $before_value !== $value ) {
                $out['modified'][ $path ] = array(
                    'old' => $before_value,
                    'new' => $value,
                );
            }
        }

        foreach ( $before as $key => $value ) {
            if ( ! array_key_exists( $key, $after ) ) {
                $path = '' === $prefix ? (string) $key : $prefix . '.' . (string) $key;

                $out['removed'][ $path ] = $value;
            }
        }
    }
}
