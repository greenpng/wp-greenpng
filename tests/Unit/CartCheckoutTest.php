<?php
/**
 * Classic-checkout front-end (ADR-0015 D1): the opt-in checkbox and
 * the watcher script, gated by the owner's switch and the target's
 * own checkout conditional.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Cart\Gr_Cart_Checkout;
use GreenPNG\Core\Gr_Settings;
use PHPUnit\Framework\TestCase;

final class CartCheckoutTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();

        $GLOBALS['gr_stub_is_admin'] = false;
    }

    protected function tearDown(): void {
        $GLOBALS['gr_stub_is_admin'] = false;
        parent::tearDown();
    }

    /**
     * Arms the feature plus the target's checkout conditional.
     *
     * @return void
     */
    private function arm(): void {
        ( new Gr_Settings() )->set( 'cart_recovery_enabled', 1 );
        $GLOBALS['gr_stub_is_checkout'] = true;
    }

    public function testTheWatcherEnqueuesOnlyOnCheckoutWhenEnabled(): void {
        $this->arm();

        Gr_Cart_Checkout::enqueue();

        self::assertArrayHasKey( Gr_Cart_Checkout::HANDLE, $GLOBALS['gr_stub_enqueued_scripts'] );
        $script = $GLOBALS['gr_stub_enqueued_scripts'][ Gr_Cart_Checkout::HANDLE ];
        self::assertSame( GR_PLUGIN_URL . 'assets/js/gr-cart-email.js', $script['src'] );
        self::assertTrue( $script['footer'] );

        // The endpoint data rides in front of the file: URL plus the
        // daily token, same shape the probe localizes.
        $inline = null;
        foreach ( $GLOBALS['gr_stub_inline_scripts'] as $record ) {
            if ( Gr_Cart_Checkout::HANDLE === $record['handle'] && 'before' === $record['position'] ) {
                $inline = $record['text'];
            }
        }
        self::assertNotNull( $inline );
        self::assertStringStartsWith( 'window.GreenPNGCart=', (string) $inline );
        // JSON escapes the slashes; the route name is the stable part.
        self::assertStringContainsString( 'wp-json', (string) $inline );
        self::assertStringContainsString( 'greenpng', (string) $inline );
        self::assertStringContainsString( 'collect', (string) $inline );
        self::assertStringContainsString( '"token"', (string) $inline );
    }

    public function testTheWatcherStaysOffPagesThatAreNotCheckout(): void {
        ( new Gr_Settings() )->set( 'cart_recovery_enabled', 1 );
        $GLOBALS['gr_stub_is_checkout'] = false;

        Gr_Cart_Checkout::enqueue();

        self::assertSame( array(), $GLOBALS['gr_stub_enqueued_scripts'] );
        self::assertSame( array(), $GLOBALS['gr_stub_inline_scripts'] );
    }

    public function testTheWatcherStaysOffWhenTheOwnerHasNotEnabledIt(): void {
        $GLOBALS['gr_stub_is_checkout'] = true;

        Gr_Cart_Checkout::enqueue();

        self::assertSame( array(), $GLOBALS['gr_stub_enqueued_scripts'] );
        self::assertSame( array(), $GLOBALS['gr_stub_inline_scripts'] );
    }

    public function testTheOptInCheckboxRendersUnderTheFormWhenEnabled(): void {
        ( new Gr_Settings() )->set( 'cart_recovery_enabled', 1 );

        ob_start();
        Gr_Cart_Checkout::render_opt_in();
        $html = (string) ob_get_clean();

        self::assertStringContainsString( 'id="gr-cart-opt-in"', $html );
        self::assertStringContainsString( 'name="gr_cart_opt_in"', $html );
        self::assertStringContainsString( 'type="checkbox"', $html );
        // The ask is honest: it says what checking it does.
        self::assertStringContainsString( 'link to finish this purchase', $html );
    }

    public function testTheOptInCheckboxStaysAbsentWhenDisabled(): void {
        ob_start();
        Gr_Cart_Checkout::render_opt_in();
        $html = (string) ob_get_clean();

        self::assertSame( '', $html );
    }
}
