<?php
/**
 * A/B experiment definitions (docs/05 §2): bounded configuration in
 * one non-autoload option, never a table of its own. Validation keeps
 * the payload small and predictable — the admin UI (U phase) writes
 * through here, tests and probes too.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Funnel;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Experiment definition repository over the gr_ab_experiments option.
 */
final class Gr_Ab_Experiments {

    /** Option name; deliberately not autoloaded (docs/05 §6). */
    public const OPTION_KEY = 'gr_ab_experiments';

    /** Upper bound on stored experiments. */
    private const MAX_EXPERIMENTS = 20;

    /** Upper bound on variants per experiment. */
    private const MAX_VARIANTS = 8;

    /**
     * Memoized definitions for this request.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $memo = null;

    /**
     * All experiment definitions, shape: key => active(bool),
     * variants(list of slug strings, first is control).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array {
        if ( null === self::$memo ) {
            $stored     = get_option( self::OPTION_KEY, array() );
            self::$memo = is_array( $stored ) ? self::sanitize_batch( $stored ) : array();
        }

        return self::$memo;
    }

    /**
     * One experiment definition or null when absent.
     *
     * @param string $experiment Experiment key.
     * @return array<string, mixed>|null
     */
    public static function get( string $experiment ) {
        $key = sanitize_key( $experiment );
        $all = self::all();

        return isset( $all[ $key ] ) ? $all[ $key ] : null;
    }

    /**
     * Test seam: drops the memoized definitions so the next read sees
     * the raw option again. Production code never calls this — every
     * write path already invalidates the memo.
     *
     * @return void
     */
    public static function reset_memo_for_tests(): void {
        self::$memo = null;
    }

    /**
     * Saves one experiment definition after validation. Returns false
     * on invalid input, never throws.
     *
     * @param string             $experiment Experiment key.
     * @param array<int, string> $variants   Variant slugs, first is control.
     * @param bool               $active     Whether the experiment runs.
     * @return bool
     */
    public static function save( string $experiment, array $variants, bool $active = true ): bool {
        $key     = sanitize_key( $experiment );
        $cleaned = self::sanitize_variants( $variants );

        if ( '' === $key || strlen( $key ) > 64 || count( $cleaned ) < 2 ) {
            return false;
        }

        $all = self::all();
        if ( ! isset( $all[ $key ] ) && count( $all ) >= self::MAX_EXPERIMENTS ) {
            return false;
        }

        $all[ $key ] = array(
            'active'   => $active,
            'variants' => $cleaned,
        );

        return self::persist( $all );
    }

    /**
     * Removes one experiment definition.
     *
     * @param string $experiment Experiment key.
     * @return bool
     */
    public static function delete( string $experiment ): bool {
        $key = sanitize_key( $experiment );
        $all = self::all();
        if ( '' === $key || ! isset( $all[ $key ] ) ) {
            return false;
        }

        unset( $all[ $key ] );

        return self::persist( $all );
    }

    /**
     * Writes the batch back, keeping the option non-autoloaded: the
     * first write goes through add_option so the autoload flag lands
     * as 'no'; a batch emptied by deletes removes the row entirely
     * rather than parking an empty array in storage.
     *
     * @param array<string, array<string, mixed>> $all Sanitized batch.
     * @return bool
     */
    private static function persist( array $all ): bool {
        if ( array() === $all ) {
            self::$memo = null;

            return delete_option( self::OPTION_KEY );
        }

        if ( false === get_option( self::OPTION_KEY, false ) ) {
            $added      = add_option( self::OPTION_KEY, $all, '', 'no' );
            self::$memo = null;

            return (bool) $added;
        }

        $updated    = update_option( self::OPTION_KEY, $all );
        self::$memo = null;

        return (bool) $updated;
    }

    /**
     * Validates a raw stored batch (defense against a corrupted or
     * hand-edited option).
     *
     * @param array<string, mixed> $batch Raw stored value.
     * @return array<string, array<string, mixed>>
     */
    private static function sanitize_batch( array $batch ): array {
        $clean = array();
        foreach ( $batch as $key => $definition ) {
            $slug = sanitize_key( (string) $key );
            if ( '' === $slug || ! is_array( $definition ) ) {
                continue;
            }

            $variants = isset( $definition['variants'] ) && is_array( $definition['variants'] )
                ? self::sanitize_variants( $definition['variants'] )
                : array();

            if ( count( $variants ) < 2 ) {
                continue;
            }

            $clean[ $slug ] = array(
                'active'   => ! empty( $definition['active'] ),
                'variants' => $variants,
            );
        }

        return $clean;
    }

    /**
     * Deduplicates, slugifies, and bounds a variant list.
     *
     * @param array<int, mixed> $variants Raw variant input.
     * @return array<int, string>
     */
    private static function sanitize_variants( array $variants ): array {
        $clean = array();
        foreach ( $variants as $variant ) {
            $slug = sanitize_key( (string) $variant );
            if ( '' === $slug || strlen( $slug ) > 32 || in_array( $slug, $clean, true ) ) {
                continue;
            }
            $clean[] = $slug;
            if ( count( $clean ) >= self::MAX_VARIANTS ) {
                break;
            }
        }

        return $clean;
    }
}
