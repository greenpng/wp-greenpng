<?php
/**
 * Semantic field extraction (docs/03 §7): one traversal over an
 * arbitrary submission payload — nested arrays, objects with get_data()
 * or public properties — that recognizes the handful of human meanings
 * every form plugin encodes under different keys. This is the engine
 * behind the auto:email / auto:name / auto:amount expression family
 * and the form bridges; it never stores anything, it only normalizes.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Payload-to-semantics normalizer.
 */
final class Gr_Semantic_Extractor {

    /** How deep to walk a payload before treating it as opaque. */
    private const MAX_DEPTH = 8;

    /** How many scalar leaves custom_fields keeps. */
    private const CUSTOM_LIMIT = 64;

    /**
     * Extracts the recognized semantics from any payload shape.
     *
     * @param mixed $payload Submission data in whatever shape the source
     *                       plugin emits (array, object, nested both).
     * @return array<string, mixed> email, phone, first_name, last_name,
     *                              full_name, amount, currency (null when
     *                              absent), detected_keys (semantic =>
     *                              dot path), custom_fields.
     */
    public static function extract( $payload ): array {
        $found = array(
            'email'         => null,
            'phone'         => null,
            'first_name'    => null,
            'last_name'     => null,
            'full_name'     => null,
            'amount'        => null,
            'currency'      => null,
            'detected_keys' => array(),
            'custom_fields' => array(),
        );

        self::walk( $payload, $found, '', 0 );

        // One name given: derive the other representation so callers
        // never have to guess which half is present.
        if ( null !== $found['full_name'] && null === $found['first_name'] ) {
            $parts               = preg_split( '/\s+/', trim( (string) $found['full_name'] ), 2 );
            $found['first_name'] = $parts[0];
            $found['last_name']  = isset( $parts[1] ) ? $parts[1] : null;
        } elseif ( null !== $found['first_name'] && null === $found['full_name'] ) {
            $found['full_name'] = trim( (string) $found['first_name'] . ' ' . (string) ( $found['last_name'] ?? '' ) );
        }

        return $found;
    }

    /**
     * Depth-capped recursive walk. Objects join via get_data() (the WC
     * CRUD convention) or their public properties.
     *
     * @param mixed               $node   Current node.
     * @param array<string,mixed> $found  Accumulator, by reference.
     * @param string              $path   Dot path so far.
     * @param int                 $depth  Current depth.
     * @return void
     */
    private static function walk( $node, array &$found, string $path, int $depth ): void {
        if ( $depth > self::MAX_DEPTH ) {
            return;
        }

        if ( is_object( $node ) ) {
            $node = method_exists( $node, 'get_data' ) ? (array) $node->get_data() : get_object_vars( $node );
        }

        if ( ! is_array( $node ) ) {
            return;
        }

        foreach ( $node as $raw_key => $value ) {
            $key  = is_scalar( $raw_key ) ? strtolower( trim( (string) $raw_key ) ) : '';
            $next = ( '' === $path ) ? $key : $path . '.' . $key;

            if ( is_array( $value ) || is_object( $value ) ) {
                self::walk( $value, $found, $next, $depth + 1 );
                continue;
            }

            if ( ! is_scalar( $value ) ) {
                continue;
            }

            self::classify( (string) $value, $key, $next, $found );
        }
    }

    /**
     * Tries each semantic category against one leaf value.
     *
     * @param string              $value Leaf value, raw.
     * @param string              $key   Lowercased key.
     * @param string              $path  Dot path of the leaf.
     * @param array<string,mixed> $found Accumulator, by reference.
     * @return void
     */
    private static function classify( string $value, string $key, string $path, array &$found ): void {
        $text = trim( $value );
        if ( '' === $text ) {
            return;
        }

        // Email: key hints first, then any RFC-valid leaf, so weirdly
        // named fields still surface; canonical lowercase.
        if ( null === $found['email'] && filter_var( $text, FILTER_VALIDATE_EMAIL ) ) {
            if ( preg_match( '/(^|_)e[-_]?mail($|_)|(^|\.)mail$|contact|correspondence/i', $key ) || self::looks_like_only_email( $text, $key ) ) {
                $found['email']                  = strtolower( $text );
                $found['detected_keys']['email'] = $path;
                return;
            }
        }

        // Phone: key must say so; digits+plus survive, 7+ required.
        if ( null === $found['phone'] && preg_match( '/(^|_)(phone|telephone|tel|mobile|cell)(_|$)/i', $key ) ) {
            $digits = preg_replace( '/[^0-9+]/', '', $text );
            if ( null !== $digits && strlen( preg_replace( '/[^0-9]/', '', $digits ) ) >= 7 ) {
                $found['phone']                  = $digits;
                $found['detected_keys']['phone'] = $path;
                return;
            }
        }

        // Names: explicit key vocabularies; CF7's your-name joins the
        // full-name family. Nested form shapes (Fluent Forms
        // names[first_name]) surface here as bare keys after recursion.
        if ( null === $found['first_name'] && preg_match( '/^(first[-_]?name|fname|given[-_]?name)$/', $key ) ) {
            $found['first_name']                  = sanitize_text_field( $text );
            $found['detected_keys']['first_name'] = $path;
            return;
        }
        if ( null === $found['last_name'] && preg_match( '/^(last[-_]?name|lname|surname|family[-_]?name)$/', $key ) ) {
            $found['last_name']                  = sanitize_text_field( $text );
            $found['detected_keys']['last_name'] = $path;
            return;
        }
        if ( null === $found['full_name'] && preg_match( '/^(your[-_]?name|full[-_]?name|fullname|name|author|customer[-_]?name|client[-_]?name)$/', $key ) ) {
            $found['full_name']                  = sanitize_text_field( $text );
            $found['detected_keys']['full_name'] = $path;
            return;
        }

        // Amount: total/price vocabulary, currency symbols and
        // thousands separators stripped; first hit wins.
        if ( null === $found['amount'] && preg_match( '/(total|amount|price|subtotal|fee|calc)/i', $key ) ) {
            $clean = preg_replace( '/[^0-9.]/', '', $text );
            if ( null !== $clean && '' !== $clean && is_numeric( $clean ) ) {
                $found['amount']                  = (float) $clean;
                $found['detected_keys']['amount'] = $path;
                return;
            }
        }

        // Currency: three letters under a currency-ish key.
        if ( null === $found['currency'] && preg_match( '/(currency|curr)/i', $key ) && preg_match( '/^[a-z]{3}$/i', $text ) ) {
            $found['currency']                  = strtoupper( $text );
            $found['detected_keys']['currency'] = $path;
            return;
        }

        // Everything else: sanitized custom field, bounded.
        if ( count( $found['custom_fields'] ) < self::CUSTOM_LIMIT ) {
            $clean_key                            = '' !== $key ? $key : (string) preg_replace( '/[^a-z0-9_.-]/', '_', strtolower( $path ) );
            $found['custom_fields'][ $clean_key ] = sanitize_text_field( $text );
        }
    }

    /**
     * Whether a bare RFC-valid email value should be adopted without a
     * key hint: only when nothing else is left and the value is the
     * whole field — implemented as the value check plus a permissive
     * late pass (any position) so payloads that never label their
     * email field still surface one.
     *
     * @param string $text Leaf value.
     * @param string $key  Lowercased key.
     * @return bool
     */
    private static function looks_like_only_email( string $text, string $key ): bool {
        // Keys that are clearly something else must not donate their
        // value just because it contains an email-shaped token.
        if ( preg_match( '/(url|link|href|ref|site|domain|sku|id)$/i', $key ) ) {
            return false;
        }

        return (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $text );
    }
}
