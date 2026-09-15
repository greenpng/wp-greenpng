<?php
/**
 * Cart abandonment repository (ADR-0015 D1/D3): the session-keyed
 * capture upsert with first-email-wins folding, the guarded state
 * transitions, the 64hex token, and the never-again list.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Secrets;
use GreenPNG\Storage\Gr_Cart_Abandonment_Repository;
use PHPUnit\Framework\TestCase;

final class CartAbandonmentRepositoryTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    protected function tearDown(): void {
        parent::tearDown();
    }

    /**
     * Three line items in the shape the collect and order paths build.
     *
     * @return array<int, array<string, int|string>>
     */
    private function items(): array {
        return array(
            array(
                'product_id'   => 10,
                'variation_id' => 0,
                'quantity'     => 2,
                'name'         => 'Widget',
            ),
            array(
                'product_id'   => 11,
                'variation_id' => 3,
                'quantity'     => 1,
                'name'         => 'Gizmo <b>pro</b>',
            ),
        );
    }

    public function testFreshCaptureInsertsOneRowWithDualTrackAndToken(): void {
        $repo = new Gr_Cart_Abandonment_Repository();

        $GLOBALS['wpdb']->results = array();

        $id = $repo->capture( '11111111-2222-4333-8444-555555555555', 'Shopper@Example.com', $this->items(), 25.5, 'usd', true, 0 );

        self::assertGreaterThan( 0, $id );
        self::assertCount( 1, $GLOBALS['wpdb']->inserts );
        $insert = $GLOBALS['wpdb']->inserts[0];
        self::assertSame( 'wp_gr_cart_abandonments', $insert['table'] );

        $data = $insert['data'];
        self::assertSame( Gr_Secrets::hash_pii_sha256( 'Shopper@Example.com' ), $data['email_hash'] );
        self::assertNotSame( '', (string) $data['email_enc'] );
        self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', (string) $data['recovery_token'] );
        self::assertSame( 'captured', $data['status'] );
        self::assertSame( 1, (int) $data['consent'] );
        self::assertSame( 'USD', $data['currency'] );
        self::assertSame( '25.50', $data['total'] );

        $stored = json_decode( (string) $data['cart_json'], true );
        self::assertSame( 10, (int) $stored[0]['product_id'] );
        self::assertSame( 3, (int) $stored[1]['variation_id'] );
        // Names ride as plain text: the markup a product name carried
        // never survives into the stored cart.
        self::assertSame( 'Gizmo pro', $stored[1]['name'] );
    }

    public function testRecaptureFoldsIntoTheSessionRowWithEmailFirstWins(): void {
        $repo    = new Gr_Cart_Abandonment_Repository();
        $session = '11111111-2222-4333-8444-555555555555';

        $GLOBALS['wpdb']->results = array(
            array( 'id' => '7', 'email_hash' => 'first-hash' ),
        );

        $id = $repo->capture( $session, 'second@example.com', $this->items(), 30.0, 'USD', false, 42 );

        self::assertSame( 7, $id );
        self::assertCount( 0, $GLOBALS['wpdb']->inserts );

        $update = end( $GLOBALS['wpdb']->queries );
        self::assertStringContainsString( 'UPDATE wp_gr_cart_abandonments', (string) $update );
        // The fold refreshes the cart state, the freshest consent
        // verdict, and the greater order id — and never touches the
        // email columns: first email wins.
        self::assertStringContainsString( 'cart_json =', (string) $update );
        self::assertStringNotContainsString( 'email_hash =', (string) $update );
        self::assertStringNotContainsString( 'email_enc', (string) $update );
        self::assertStringContainsString( 'consent = 0', (string) $update );
        self::assertStringContainsString( 'GREATEST(order_id, 42)', (string) $update );
        self::assertStringContainsString( 'WHERE id = 7', (string) $update );
    }

    public function testCaptureWithoutAnEmailRecordsNothing(): void {
        $repo = new Gr_Cart_Abandonment_Repository();

        $GLOBALS['wpdb']->results = array();

        self::assertSame( 0, $repo->capture( 'session', '', $this->items(), 10.0, 'USD', true, 0 ) );
        self::assertSame( 0, $repo->capture( '', 'a@example.com', $this->items(), 10.0, 'USD', true, 0 ) );
        self::assertCount( 0, $GLOBALS['wpdb']->inserts );
    }

    public function testJunkItemsFallOutOfTheStoredCart(): void {
        $repo = new Gr_Cart_Abandonment_Repository();

        $GLOBALS['wpdb']->results = array();

        $repo->capture(
            '11111111-2222-4333-8444-555555555555',
            'a@example.com',
            array(
                'not-an-item',
                array( 'product_id' => 0, 'quantity' => 1 ),
                array( 'product_id' => 5, 'quantity' => 0 ),
                array( 'product_id' => 5, 'quantity' => 4 ),
            ),
            10.0,
            'USD',
            true,
            0
        );

        $data = $GLOBALS['wpdb']->inserts[0]['data'];
        $cart = json_decode( (string) $data['cart_json'], true );

        self::assertCount( 1, $cart );
        self::assertSame( 5, (int) $cart[0]['product_id'] );
        self::assertSame( 4, (int) $cart[0]['quantity'] );
    }

    public function testRowForTokenRefusesAnyShapeBut64Hex(): void {
        $repo = new Gr_Cart_Abandonment_Repository();

        $GLOBALS['wpdb']->results = array();
        $queries_before = count( $GLOBALS['wpdb']->queries );

        self::assertSame( array(), $repo->row_for_token( 'short' ) );
        self::assertSame( array(), $repo->row_for_token( '../wp-config' ) );
        self::assertSame( array(), $repo->row_for_token( strtoupper( str_repeat( 'a', 64 ) ) ) );

        self::assertCount( $queries_before, $GLOBALS['wpdb']->queries );

        $token = str_repeat( 'a', 64 );
        $repo->row_for_token( $token );
        self::assertStringContainsString( 'recovery_token = \'' . $token . '\'', end( $GLOBALS['wpdb']->queries ) );
    }

    public function testMarkAbandonedIsAGuardedTransition(): void {
        $repo = new Gr_Cart_Abandonment_Repository();

        $GLOBALS['wpdb']->query_result = 1;
        self::assertTrue( $repo->mark_abandoned( 9 ) );
        self::assertStringContainsString( "status = 'abandoned'", end( $GLOBALS['wpdb']->queries ) );
        self::assertStringContainsString( "status = 'captured'", end( $GLOBALS['wpdb']->queries ) );
        self::assertStringContainsString( 'WHERE id = 9', end( $GLOBALS['wpdb']->queries ) );

        // The loser of a race reads zero affected rows: no mail, no
        // second state change.
        $GLOBALS['wpdb']->query_result = 0;
        self::assertFalse( $repo->mark_abandoned( 9 ) );
    }

    public function testMarkAttemptedAcceptsAbandonedAndAttemptedOnly(): void {
        $repo = new Gr_Cart_Abandonment_Repository();

        $GLOBALS['wpdb']->query_result = 1;
        self::assertTrue( $repo->mark_attempted( 9 ) );
        self::assertStringContainsString( "'abandoned', 'attempted'", end( $GLOBALS['wpdb']->queries ) );

        $GLOBALS['wpdb']->query_result = 0;
        self::assertFalse( $repo->mark_failed( 9 ) );
        $GLOBALS['wpdb']->query_result = 1;
        self::assertTrue( $repo->mark_failed( 9 ) );
        self::assertStringContainsString( "SET status = 'failed'", end( $GLOBALS['wpdb']->queries ) );
        // Captured rows park here too: an envelope that no longer
        // opens fails before the mutex ever flips, and has nothing
        // to retry.
        self::assertStringContainsString( "'captured', 'abandoned'", end( $GLOBALS['wpdb']->queries ) );
    }

    public function testRevertAbandonedReturnsARefusedSendToCaptured(): void {
        $repo = new Gr_Cart_Abandonment_Repository();

        $GLOBALS['wpdb']->query_result = 1;
        self::assertTrue( $repo->revert_abandoned( 9 ) );
        $sql = end( $GLOBALS['wpdb']->queries );
        self::assertStringContainsString( "SET status = 'captured'", $sql );
        self::assertStringContainsString( "status = 'abandoned'", $sql );
        self::assertStringContainsString( 'WHERE id = 9', $sql );

        // Only an abandoned row returns: a captured row was never
        // flipped, and the zero keeps the retry from manufacturing a
        // second send permission.
        $GLOBALS['wpdb']->query_result = 0;
        self::assertFalse( $repo->revert_abandoned( 9 ) );
    }

    public function testMarkRecoveredClosesEveryOpenStateForTheEmail(): void {
        $repo  = new Gr_Cart_Abandonment_Repository();
        $email = 'a@example.com';
        $hash  = Gr_Secrets::hash_pii_sha256( $email );

        $GLOBALS['wpdb']->query_result = 2;
        self::assertSame( 2, $repo->mark_recovered( $hash, 55 ) );

        $sql = end( $GLOBALS['wpdb']->queries );
        self::assertStringContainsString( 'recovered_at', (string) $sql );
        self::assertStringContainsString( "'captured', 'abandoned', 'attempted', 'failed'", (string) $sql );
        self::assertStringContainsString( "'" . $hash . "'", (string) $sql );

        // A malformed hash is a no-op, never a broad write.
        $before = count( $GLOBALS['wpdb']->queries );
        self::assertSame( 0, $repo->mark_recovered( 'zz', 55 ) );
        self::assertCount( $before, $GLOBALS['wpdb']->queries );
    }

    public function testFailedSummaryReadsCountAndNewestAttempt(): void {
        $repo = new Gr_Cart_Abandonment_Repository();

        $GLOBALS['wpdb']->results = array(
            array( 'failed' => '3', 'last_failed' => '2026-09-15 10:00:00' ),
        );

        self::assertSame(
            array( 'count' => 3, 'last' => '2026-09-15 10:00:00' ),
            $repo->failed_summary()
        );
        self::assertStringContainsString( "status = 'failed'", end( $GLOBALS['wpdb']->queries ) );
    }

    public function testUnsubscribeListIsHashKeyedAndDeduplicated(): void {
        $repo = new Gr_Cart_Abandonment_Repository();
        $hash = Gr_Secrets::hash_pii_sha256( 'gone@example.com' );

        self::assertFalse( $repo->is_unsubscribed( $hash ) );

        self::assertTrue( $repo->add_unsubscribed( $hash ) );
        self::assertTrue( $repo->is_unsubscribed( $hash ) );

        // A second verdict is a no-op — and the signal the caller uses
        // to keep the system tag from re-attaching.
        self::assertFalse( $repo->add_unsubscribed( $hash ) );

        $list = get_option( Gr_Cart_Abandonment_Repository::UNSUBSCRIBE_OPTION, array() );
        self::assertSame( array( $hash ), $list );
        self::assertSame( 'no', $GLOBALS['gr_stub_options']['autoload'][ Gr_Cart_Abandonment_Repository::UNSUBSCRIBE_OPTION ] );

        // A malformed hash is neither recorded nor matched.
        self::assertFalse( $repo->is_unsubscribed( 'zz' ) );
        $repo->add_unsubscribed( 'zz' );
        self::assertSame( array( $hash ), get_option( Gr_Cart_Abandonment_Repository::UNSUBSCRIBE_OPTION, array() ) );
    }

    public function testUnsubscribeListTrimsAtItsCeilingOldestFirst(): void {
        $repo = new Gr_Cart_Abandonment_Repository();

        for ( $i = 0; $i < 10001; $i++ ) {
            $repo->add_unsubscribed( str_pad( dechex( $i ), 64, '0', STR_PAD_LEFT ) );
        }

        $list = get_option( Gr_Cart_Abandonment_Repository::UNSUBSCRIBE_OPTION, array() );
        self::assertCount( 10000, $list );
        // The first (oldest) entry lost its seat; the newest stayed.
        self::assertNotContains( str_pad( '0', 64, '0', STR_PAD_LEFT ), $list );
        self::assertContains( str_pad( dechex( 10001 - 1 ), 64, '0', STR_PAD_LEFT ), $list );
    }

    public function testPrivacyRowsAndErasureKeyByEmailHash(): void {
        $repo  = new Gr_Cart_Abandonment_Repository();
        $email = 'person@example.com';
        $hash  = Gr_Secrets::hash_pii_sha256( $email );

        $GLOBALS['wpdb']->results = array(
            array( 'id' => '5', 'status' => 'recovered', 'cart_json' => '[]', 'total' => '9.00', 'currency' => 'USD' ),
        );

        $rows = $repo->rows_for_email_hash( $hash );
        self::assertCount( 1, $rows );
        self::assertSame( 'recovered', $rows[0]['status'] );
        // The export read never selects the hash or the envelope.
        self::assertStringNotContainsString( 'email_enc', end( $GLOBALS['wpdb']->queries ) );

        $GLOBALS['wpdb']->query_result = 1;
        self::assertSame( 1, $repo->erase_for_email_hash( $hash ) );
        self::assertStringContainsString( 'DELETE FROM wp_gr_cart_abandonments', end( $GLOBALS['wpdb']->queries ) );

        $before = count( $GLOBALS['wpdb']->queries );
        self::assertSame( array(), $repo->rows_for_email_hash( 'zz' ) );
        self::assertSame( 0, $repo->erase_for_email_hash( 'zz' ) );
        self::assertCount( $before, $GLOBALS['wpdb']->queries );
    }
}
