<?php
/**
 * Integration adapter contract (docs/02 §2.6): every ecosystem adapter
 * implements this surface and is only asked to register hooks when its
 * target plugin actually runs on the site. The three generality
 * principles (public hooks only, no signature-sample detection, main
 * hook plus fallback with visible drift warnings) are enforced by
 * review, not by this interface.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contract for ecosystem adapters (WooCommerce, form plugins, …).
 */
interface Adapter_Interface {

    /**
     * Stable adapter identifier, e.g. 'woocommerce'.
     *
     * @return string
     */
    public static function get_id(): string;

    /**
     * Whether the target plugin is present and active. Must be a cheap
     * capability probe on the target's public surface, never a version
     * lock or a code-signature match (iron rule 6).
     *
     * @return bool
     */
    public static function is_available(): bool;

    /**
     * Hook registration; the caller invokes it only after
     * is_available() returned true.
     *
     * @return void
     */
    public function register_hooks(): void;
}
