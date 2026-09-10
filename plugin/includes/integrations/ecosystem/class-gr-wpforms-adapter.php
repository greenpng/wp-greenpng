<?php
/**
 * WPForms bridge (docs/13 C11). Main hook wpforms_process_complete
 * ($fields, $entry, $form_data, $entry_id) fires on every completed
 * submission; the paired fallback wpforms_entry_saved ($entry_id,
 * $form_data) only fires when entry storage is on, so the fallback
 * covers the drift case where the main hook disappears and entries
 * are being kept. Entry ids exist only with storage enabled: without
 * them the source id is minted per request and shared by both hooks.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Integrations\Ecosystem;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One-hook-plus-fallback WPForms integration.
 */
final class Gr_Wpforms_Adapter extends Gr_Form_Adapter_Base {

    /**
     * Adapter identifier; gr_conversions source_type value.
     *
     * @return string
     */
    public static function get_id(): string {
        return 'wpforms';
    }

    /**
     * The target's own bootstrap function, defined by both editions
     * (Lite and full): a public existence probe (iron rule 6).
     *
     * @return bool
     */
    public static function is_available(): bool {
        return function_exists( 'wpforms' );
    }

    /**
     * Main hook name.
     *
     * @return string
     */
    protected function main_hook(): string {
        return 'wpforms_process_complete';
    }

    /**
     * Fallback hook name.
     *
     * @return string
     */
    protected function fallback_hook(): string {
        return 'wpforms_entry_saved';
    }

    /**
     * Main hook arg count.
     *
     * @return int
     */
    protected function main_arg_count(): int {
        return 4;
    }

    /**
     * Fallback hook arg count.
     *
     * @return int
     */
    protected function fallback_arg_count(): int {
        return 2;
    }

    /**
     * Complete shape: (fields, entry, form_data, entry_id). Field
     * values arrive keyed by numeric field id; the labels from the
     * public form_data shape become the semantic keys, so the
     * extractor sees names/emails instead of digits.
     *
     * @param array<int, mixed> $args Positional hook arguments.
     * @return array{source: int, payload: array<string, mixed>>}|null
     */
    protected function translate_main( array $args ) {
        $fields    = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : array();
        $form_data = isset( $args[2] ) && is_array( $args[2] ) ? $args[2] : array();
        $entry_id  = isset( $args[3] ) && is_numeric( $args[3] ) ? (int) $args[3] : 0;

        if ( array() === $fields && array() === $form_data ) {
            return null;
        }

        return array(
            'source'  => $this->source_for( $entry_id, $form_data ),
            'payload' => $this->payload_for( $fields, $form_data ),
        );
    }

    /**
     * Entry-saved shape: (entry_id, form_data) — entry values are not
     * on the arguments, so the drift binding extracts from the
     * form_data field settings only (labels and any option pricing),
     * which is all the fallback can honestly see.
     *
     * @param array<int, mixed> $args Positional hook arguments.
     * @return array{source: int, payload: array<string, mixed>>}|null
     */
    protected function translate_fallback( array $args ) {
        $entry_id  = isset( $args[0] ) && is_numeric( $args[0] ) ? (int) $args[0] : 0;
        $form_data = isset( $args[1] ) && is_array( $args[1] ) ? $args[1] : array();

        if ( array() === $form_data ) {
            return null;
        }

        return array(
            'source'  => $this->source_for( $entry_id, $form_data ),
            'payload' => array(),
        );
    }

    /**
     * Real entry id when the target provides one, per-request minted
     * otherwise, keyed by the form id so both hooks agree.
     *
     * @param int                      $entry_id  Entry id, 0 when absent.
     * @param array<int|string, mixed> $form_data Public form data shape.
     * @return int
     */
    private function source_for( int $entry_id, array $form_data ): int {
        if ( $entry_id > 0 ) {
            return $entry_id;
        }

        return $this->synthetic_source_id( 'wpforms:' . (int) ( $form_data['id'] ?? 0 ) );
    }

    /**
     * Field values re-keyed by normalized label, raw values under
     * their numeric ids kept alongside so nothing is lost.
     *
     * @param array<int|string, mixed> $fields    Values keyed by field id.
     * @param array<int|string, mixed> $form_data Public form data shape.
     * @return array<string, mixed>
     */
    private function payload_for( array $fields, array $form_data ): array {
        $payload = array();

        $settings = isset( $form_data['fields'] ) && is_array( $form_data['fields'] ) ? $form_data['fields'] : array();
        foreach ( $settings as $field ) {
            if ( ! is_array( $field ) || ! isset( $field['id'], $field['label'] ) ) {
                continue;
            }

            $key = trim( (string) $field['label'] );
            if ( '' === $key || ! array_key_exists( $field['id'], $fields ) ) {
                continue;
            }

            $payload[ strtolower( str_replace( ' ', '_', $key ) ) ] = $fields[ $field['id'] ];
        }

        return $payload + $fields;
    }
}
