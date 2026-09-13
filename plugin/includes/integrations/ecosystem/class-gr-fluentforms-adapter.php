<?php
/**
 * Fluent Forms bridge (docs/13 C11). Main hook
 * fluentform/submission_inserted ($insertId, $formData, $form) is the
 * modern slashed form; fluentform_submission_inserted fires
 * immediately before it in the same process and is kept by the target
 * as the backward-compatibility form, which makes it a natural
 * fallback sentinel: if the main hook ever drifts away, the legacy
 * hook's submission still binds and is reported on gr_bridge_drift.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Integrations\Ecosystem;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One-hook-plus-fallback Fluent Forms integration.
 */
final class Gr_Fluentforms_Adapter extends Gr_Form_Adapter_Base {

    /**
     * Adapter identifier; gr_conversions source_type value.
     *
     * @return string
     */
    public static function get_id(): string {
        return 'fluentform';
    }

    /**
     * The target's own bootstrap constant: defined at plugin boot,
     * stable across versions, no signature matching (iron rule 6).
     *
     * @return bool
     */
    public static function is_available(): bool {
        return defined( 'FLUENTFORM' );
    }

    /**
     * Main hook name.
     *
     * @return string
     */
    protected function main_hook(): string {
        return 'fluentform/submission_inserted';
    }

    /**
     * Fallback hook name (the target's legacy unslashed form).
     *
     * @return string
     */
    protected function fallback_hook(): string {
        return 'fluentform_submission_inserted';
    }

    /**
     * Main hook arg count.
     *
     * @return int
     */
    protected function main_arg_count(): int {
        return 3;
    }

    /**
     * Fallback hook arg count.
     *
     * @return int
     */
    protected function fallback_arg_count(): int {
        return 3;
    }

    /**
     * Both hooks share one shape: entry id, processed form data array.
     *
     * @param array<int, mixed> $args Positional hook arguments.
     * @return array{source: int, payload: array<string, mixed>}|null
     */
    protected function translate_main( array $args ): ?array {
        return $this->translate( $args );
    }

    /**
     * Same shape as the main hook.
     *
     * @param array<int, mixed> $args Positional hook arguments.
     * @return array{source: int, payload: array<string, mixed>}|null
     */
    protected function translate_fallback( array $args ): ?array {
        return $this->translate( $args );
    }

    /**
     * Positional shape both hooks emit: (insertId, formData, form).
     * The form object is deliberately unused: the bridge reads only
     * the submission payload, never target internals.
     *
     * @param array<int, mixed> $args Positional hook arguments.
     * @return array{source: int, payload: array<string, mixed>}|null
     */
    private function translate( array $args ): ?array {
        if ( count( $args ) < 2 || ! is_numeric( $args[0] ) || ! is_array( $args[1] ) ) {
            return null;
        }

        return array(
            'source'  => (int) $args[0],
            'payload' => $args[1],
        );
    }
}
