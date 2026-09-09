<?php
/**
 * Secret material service (docs/10 §2, fixing reference flaws S3/S4):
 * third-party credentials are encrypted with AES-256-GCM under a key
 * derived from wp_salt() and stored in autoload=no options — plaintext
 * never lands in the database, and there are no mock defaults: an
 * unavailable cipher or a missing option reads back as "not configured".
 *
 * Also hosts the deterministic hash/HMAC/id primitives behind the
 * gr_hash_pii(), gr_sign_hmac(), and gr_generate_event_id() facades.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Encryption, keyed hashing, and id generation for the whole plugin.
 */
final class Gr_Secrets {

    /** Cipher per docs/10 §2. */
    private const CIPHER = 'aes-256-gcm';

    /** GCM nonce length. */
    private const IV_BYTES = 12;

    /** GCM authentication tag length. */
    private const TAG_BYTES = 16;

    /**
     * Encrypts one secret into a self-contained base64 envelope
     * (iv . tag . ciphertext). The key is derived per request from
     * wp_salt() and is never persisted anywhere.
     *
     * @param string $plaintext Value to protect.
     * @return string Envelope, or '' when the cipher is unavailable (the
     *                caller must then treat the credential as unstoreable
     *                rather than fall back to plaintext).
     */
    public static function encrypt( string $plaintext ): string {
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            return '';
        }

        $iv  = random_bytes( self::IV_BYTES );
        $tag = '';

        $ciphertext = openssl_encrypt( $plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
        if ( false === $ciphertext ) {
            return '';
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64 is the transport encoding of the binary envelope, not code obfuscation.
        return base64_encode( $iv . $tag . $ciphertext );
    }

    /**
     * Decrypts an encrypt() envelope.
     *
     * @param string $envelope Value from encrypt().
     * @return string|false Plaintext, or false when the value is not a
     *                      valid envelope or fails authentication.
     */
    public static function decrypt( string $envelope ) {
        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return false;
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- base64 is the transport encoding of the binary envelope, not code obfuscation.
        $raw = base64_decode( $envelope, true );
        // Strictly-shorter rejects garbage; an exactly iv+tag-sized raw
        // value is a legitimate envelope of the empty string.
        if ( false === $raw || strlen( $raw ) < self::IV_BYTES + self::TAG_BYTES ) {
            return false;
        }

        $iv         = substr( $raw, 0, self::IV_BYTES );
        $tag        = substr( $raw, self::IV_BYTES, self::TAG_BYTES );
        $ciphertext = substr( $raw, self::IV_BYTES + self::TAG_BYTES );

        $plaintext = openssl_decrypt( $ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );

        return false === $plaintext ? false : $plaintext;
    }

    /**
     * Stores one secret encrypted in an autoload=no option.
     *
     * @param string $option_key Option name, e.g. gr_capi_meta_token.
     * @param string $plaintext  Value to protect.
     * @return bool False (and nothing stored) when encryption is
     *              unavailable — an unprotectable secret is never written.
     */
    public static function store( string $option_key, string $plaintext ): bool {
        $envelope = self::encrypt( $plaintext );
        if ( '' === $envelope ) {
            return false;
        }

        if ( false !== get_option( $option_key, false ) ) {
            update_option( $option_key, $envelope );

            return true;
        }

        return add_option( $option_key, $envelope, '', 'no' );
    }

    /**
     * Reads one stored secret back; '' means "not configured" (docs/10
     * §2: no mock defaults, the settings page shows the real state).
     *
     * @param string $option_key Option name.
     * @return string
     */
    public static function reveal( string $option_key ): string {
        $envelope = get_option( $option_key, '' );
        if ( ! is_string( $envelope ) || '' === $envelope ) {
            return '';
        }

        $plaintext = self::decrypt( $envelope );

        return is_string( $plaintext ) ? $plaintext : '';
    }

    /**
     * Removes one stored secret.
     *
     * @param string $option_key Option name.
     * @return bool
     */
    public static function forget( string $option_key ): bool {
        return delete_option( $option_key );
    }

    /**
     * Normalized SHA-256 for PII joins (docs/03 §1): lowercase + trim so
     * the same email/IP hash identically wherever it is captured; the
     * type prefixes the hash input so one value cannot be linked across
     * PII kinds. Empty in, empty out.
     *
     * @param string $value Raw value.
     * @param string $type  PII kind, e.g. 'email' or 'ip'.
     * @return string
     */
    public static function hash_pii( string $value, string $type ): string {
        if ( '' === $value ) {
            return '';
        }

        // strtolower rather than mb_strtolower: PII values are ASCII in
        // practice and the extension is not guaranteed on every host.
        return hash( 'sha256', $type . '|' . strtolower( trim( $value ) ) );
    }

    /**
     * HMAC-SHA256 for webhook signing (docs/03 §1); deterministic so the
     * receiving side can verify with hash_equals().
     *
     * @param string $data   Payload being signed.
     * @param string $secret Shared secret.
     * @return string
     */
    public static function sign_hmac( string $data, string $secret ): string {
        return hash_hmac( 'sha256', $data, $secret );
    }

    /**
     * Idempotency-safe event ids (docs/03 §1): "prefix_" + 32 hex. With
     * caller entropy the id is a deterministic hash of that entropy, so
     * replayed callbacks converge on the same id; without it, 16 random
     * bytes.
     *
     * @param string $prefix  Id prefix.
     * @param string $entropy Caller-supplied determinism seed.
     * @return string
     */
    public static function generate_event_id( string $prefix = 'gr', string $entropy = '' ): string {
        if ( '' !== $entropy ) {
            $hex = substr( hash( 'sha256', $entropy ), 0, 32 );
        } else {
            $hex = bin2hex( random_bytes( 16 ) );
        }

        return ( '' === $prefix ? '' : $prefix . '_' ) . $hex;
    }

    /**
     * 32 raw key bytes derived from the site's auth salt; deriving per
     * call keeps the key out of every code path that could persist it.
     *
     * @return string
     */
    private static function key(): string {
        return hash( 'sha256', wp_salt( 'auth' ) . '|greenpng-secrets', true );
    }
}
