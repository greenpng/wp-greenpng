<?php
/**
 * Contact Form 7 bridge (docs/13 C11). Main hook wpcf7_mail_sent
 * (WPCF7_ContactForm) fires inside successful processing; the paired
 * fallback wpcf7_submit ($contact_form, $result) closes every
 * submission attempt and carries the status in $result, so the
 * fallback only stands armed when status says the mail went out.
 * CF7 core keeps no persisted submission id, so the source id is
 * minted per request and shared by both hooks (the gr_conversions
 * UNIQUE key absorbs the rare cross-request coincidence).
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Integrations\Ecosystem;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One-hook-plus-fallback Contact Form 7 integration.
 */
final class Gr_Cf7_Adapter extends Gr_Form_Adapter_Base {

    /**
     * Adapter identifier; gr_conversions source_type value.
     *
     * @return string
     */
    public static function get_id(): string {
        return 'cf7';
    }

    /**
     * The target's own bootstrap function: defined at plugin boot and
     * the same probe ecosystem add-ons use (iron rule 6).
     *
     * @return bool
     */
    public static function is_available(): bool {
        return function_exists( 'wpcf7' );
    }

    /**
     * Main hook name.
     *
     * @return string
     */
    protected function main_hook(): string {
        return 'wpcf7_mail_sent';
    }

    /**
     * Fallback hook name.
     *
     * @return string
     */
    protected function fallback_hook(): string {
        return 'wpcf7_submit';
    }

    /**
     * Main hook arg count.
     *
     * @return int
     */
    protected function main_arg_count(): int {
        return 1;
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
     * Mail-sent shape: (contact_form).
     *
     * @param array<int, mixed> $args Positional hook arguments.
     * @return array{source: int, payload: array<string, mixed>>}|null
     */
    protected function translate_main( array $args ) {
        if ( empty( $args[0] ) || ! is_object( $args[0] ) ) {
            return null;
        }

        return $this->from_contact_form( $args[0] );
    }

    /**
     * Submit shape: (contact_form, result) — armed only when the
     * result says the mail was sent.
     *
     * @param array<int, mixed> $args Positional hook arguments.
     * @return array{source: int, payload: array<string, mixed>>}|null
     */
    protected function translate_fallback( array $args ) {
        if ( empty( $args[0] ) || ! is_object( $args[0] ) ) {
            return null;
        }

        $result = isset( $args[1] ) && is_array( $args[1] ) ? $args[1] : array();
        if ( 'mail_sent' !== (string) ( $result['status'] ?? '' ) ) {
            return null;
        }

        return $this->from_contact_form( $args[0] );
    }

    /**
     * Posted data via the target's public submission API, with the
     * form's own id() riding along for the per-request source key.
     *
     * @param object $contact_form WPCF7_ContactForm or a stand-in.
     * @return array{source: int, payload: array<string, mixed>>}|null
     */
    private function from_contact_form( $contact_form ) {
        if ( ! method_exists( $contact_form, 'id' ) ) {
            return null;
        }

        $posted = array();
        if ( class_exists( 'WPCF7_Submission', false ) ) {
            $submission = \WPCF7_Submission::get_instance();
            if ( null !== $submission && method_exists( $submission, 'get_posted_data' ) ) {
                $fetched = $submission->get_posted_data();
                if ( is_array( $fetched ) ) {
                    $posted = $fetched;
                }
            }
        }

        return array(
            'source'  => $this->synthetic_source_id( 'cf7:' . (int) $contact_form->id() ),
            'payload' => $posted,
        );
    }
}
