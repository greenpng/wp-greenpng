<?php
/**
 * Request-derived values under the strict input discipline: superglobal
 * reads happen here exactly once each, unslashed and sanitized at the
 * point of read, so no consumer ever touches $_SERVER directly.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Static accessors for the current request's client-provided context.
 */
final class Gr_Request {

    /**
     * Sanitized user agent, truncated to 512 characters (docs/03 §1);
     * shorter per-column truncation happens at the write boundary.
     *
     * @return string
     */
    public static function user_agent(): string {
        if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
            return '';
        }

        return substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 512 );
    }

    /**
     * Sanitized Referer header value, truncated to 512 characters; the
     * host extraction and external-link decision belong to the caller.
     *
     * @return string
     */
    public static function referrer(): string {
        if ( ! isset( $_SERVER['HTTP_REFERER'] ) ) {
            return '';
        }

        return substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 512 );
    }
}
