<?php
/**
 * Classic-checkout front-end for cart recovery (ADR-0015 D1): the
 * opt-in checkbox and the blur watcher that posts the billing email
 * to the collect route. The checkbox is ours, not the gateway's, and
 * it renders after the form closes — nothing about it rides the
 * checkout POST. Blocks checkouts cannot host it (no public hook
 * inside the block form); those sites ride the order path alone, and
 * the readme says so.
 *
 * The script sends only when the box is checked; the server still
 * re-gates on the feature switch, marketing consent, and the opt-in
 * flag before anything is stored — the client assertion alone never
 * captures an email.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Cart;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Rest\Gr_Collect_Controller;

/**
 * Checkbox and script output on the classic checkout.
 */
final class Gr_Cart_Checkout {

    /** Script handle. */
    public const HANDLE = 'gr-cart-email';

    /**
     * Hook registration; every mount re-gates itself, so a switched-off
     * feature costs one autoloaded read and nothing else. The target's
     * bootstrap class gates registration itself: a site without the
     * target never sees a single woocommerce_ mount from this plugin
     * (iron rule 6), and Blocks sites keep riding the order path.
     *
     * @return void
     */
    public static function register_hooks(): void {
        if ( ! class_exists( 'WooCommerce', false ) ) {
            return;
        }

        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'woocommerce_after_checkout_form', array( __CLASS__, 'render_opt_in' ), 20, 0 );
    }

    /**
     * Whether the owner has the feature on.
     *
     * @return bool
     */
    private static function enabled(): bool {
        return 1 === (int) gr()->settings()->get( 'cart_recovery_enabled' );
    }

    /**
     * Enqueues the watcher on checkout pages only. is_checkout() is
     * the target's own public conditional, so no template sniffing,
     * no version lock (iron rule 6).
     *
     * @return void
     */
    public static function enqueue(): void {
        if ( is_admin() || ! self::enabled() ) {
            return;
        }

        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
            return;
        }

        wp_enqueue_script( self::HANDLE, GR_PLUGIN_URL . 'assets/js/gr-cart-email.js', array(), GR_VERSION, true );

        // Same endpoint data the probe rides: URL plus the daily
        // token, localized in front of the file.
        wp_add_inline_script(
            self::HANDLE,
            'window.GreenPNGCart=' . (string) wp_json_encode( Gr_Collect_Controller::script_data() ) . ';',
            'before'
        );
    }

    /**
     * Renders the opt-in checkbox under the classic checkout form.
     * The hook only fires with the target present, so no availability
     * probe is needed here.
     *
     * @return void
     */
    public static function render_opt_in(): void {
        if ( ! self::enabled() ) {
            return;
        }
        ?>
        <p class="form-row gr-cart-opt-in" id="gr-cart-opt-in-row">
            <label class="checkbox" for="gr-cart-opt-in">
                <input type="checkbox" id="gr-cart-opt-in" name="gr_cart_opt_in" value="1" />
                <?php echo esc_html__( 'Send me a link to finish this purchase if I do not complete checkout.', 'greenpng' ); ?>
            </label>
        </p>
        <?php
    }
}
