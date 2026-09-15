<?php
/**
 * Status & Diagnostics page: the cart-recovery delivery signal. The
 * page is otherwise a read-only surface over Gr_Diagnostics (covered
 * by DiagnosticsTest); what this file owns is the failed-mail notice.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Admin\Gr_Status_Page;
use GreenPNG\Integrations\Webhook\Gr_Webhook_Repository;
use GreenPNG\Storage\Gr_Cart_Abandonment_Repository;
use PHPUnit\Framework\TestCase;

final class StatusPageTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();

        $GLOBALS['wpdb']->results = array();
    }

    /**
     * Renders the page and returns its HTML.
     *
     * @return string
     */
    private function render(): string {
        ob_start();
        Gr_Status_Page::render();

        return (string) ob_get_clean();
    }

    public function testHealthyDeliveryReadsAsNoFailedSends(): void {
        $GLOBALS['wpdb']->results = array();

        $html = $this->render();

        self::assertStringContainsString( 'Cart recovery', $html );
        self::assertStringContainsString( 'No failed recovery sends on record.', $html );
        self::assertStringNotContainsString( 'notice-warning', $html );

        // The signal reads the failed status through the status_time
        // index, nothing broader.
        $queried = false;
        foreach ( $GLOBALS['wpdb']->queries as $query ) {
            if ( is_string( $query ) && false !== strpos( $query, "status = 'failed'" ) ) {
                $queried = true;
            }
        }
        self::assertTrue( $queried );
    }

    public function testFailedSendsSurfaceAsAWarningWithTheirCount(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ) {
            if ( false !== strpos( $sql, 'COUNT(*) AS failed' ) ) {
                return array( array( 'failed' => '2', 'last_failed' => '2026-09-15 10:00:00' ) );
            }

            return array();
        };

        $html = $this->render();

        self::assertStringContainsString( 'notice-warning', $html );
        self::assertStringContainsString( '2', $html );
        self::assertStringContainsString( '2026-09-15 10:00:00', $html );
        self::assertStringContainsString( 'mail delivery', $html );
    }

    public function testTheSignalReflectsTheRepositoryVocabulary(): void {
        // The page and the engine share one status word: whatever the
        // retry path parks, the status page must be able to see.
        self::assertSame( 'failed', Gr_Cart_Abandonment_Repository::STATUS_FAILED );
    }

    public function testWebhookSectionReadsTheEndpointStates(): void {
        $error = '';
        $id    = Gr_Webhook_Repository::add( 'https://receiver.example.test/hook', 'unit-test-secret-0123456789', array( 'conversion' ), true, $error );
        self::assertGreaterThan( 0, $id, (string) $error );

        // Delivering normally: the receiver host and the quiet word.
        $html = $this->render();
        self::assertStringContainsString( 'receiver.example.test', $html );
        self::assertStringContainsString( 'Delivering normally', $html );
        self::assertStringContainsString( 'Never', $html );

        // An opened circuit surfaces as its own alarm word.
        for ( $i = 0; $i < Gr_Webhook_Repository::CIRCUIT_THRESHOLD; $i++ ) {
            Gr_Webhook_Repository::record_delivery( $id, false, 'HTTP 500' );
        }
        $html = $this->render();
        self::assertStringContainsString( 'Circuit open', $html );

        delete_option( Gr_Webhook_Repository::OPTION );
    }

    public function testWebhookSectionHonestEmptyState(): void {
        $html = $this->render();

        self::assertStringContainsString( 'No webhook endpoints configured', $html );
    }
}
