<?php
/**
 * Cart recovery engine (ADR-0015 D2/D3): the four-gate check matrix,
 * the guarded-transition mutex semantics, the one-retry mail
 * discipline, template rendering, and the never-again unsubscribe
 * arm. The target-present order gates and the cart-restore redemption
 * live in WooCommerceAdapterTest: the marker class those paths gate
 * on is process-global once defined, so only the adapter file —
 * which owns that ordering discipline — may define it.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Cart\Gr_Cart_Recovery;
use GreenPNG\Core\Gr_Secrets;
use GreenPNG\Core\Gr_Settings;
use GreenPNG\Storage\Gr_Cart_Abandonment_Repository;
use PHPUnit\Framework\TestCase;

final class CartRecoveryTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();

        $_SERVER['REMOTE_ADDR']      = '10.0.0.7';
        $_SERVER['HTTP_USER_AGENT'] = 'UnitTestAgent/1.0';

        $GLOBALS['wpdb']->insert_id  = 0;
        $GLOBALS['wpdb']->query_result = 0;
    }

    protected function tearDown(): void {
        unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] );
        parent::tearDown();
    }

    /**
     * One abandonment row as the repository would have stored it.
     *
     * @param array<string, mixed> $overrides Column overrides.
     * @return array<string, mixed>
     */
    private function row( array $overrides = array() ): array {
        $email = 'shopper@example.com';

        return array_merge(
            array(
                'id'             => 5,
                'session_id'     => '11111111-2222-4333-8444-555555555555',
                'email_hash'     => Gr_Secrets::hash_pii_sha256( $email ),
                'email_enc'      => Gr_Secrets::encrypt( $email ),
                'cart_json'      => (string) wp_json_encode(
                    array(
                        array( 'product_id' => 10, 'variation_id' => 0, 'quantity' => 2, 'name' => 'Widget' ),
                        array( 'product_id' => 11, 'variation_id' => 3, 'quantity' => 1, 'name' => 'Gizmo' ),
                    )
                ),
                'total'          => '25.50',
                'currency'       => 'USD',
                'consent'        => 1,
                'status'         => Gr_Cart_Abandonment_Repository::STATUS_CAPTURED,
                'recovery_token' => str_repeat( 'b', 64 ),
                'order_id'       => 0,
                'captured_at'    => '2026-09-15 09:00:00',
                'abandoned_at'   => null,
                'recovered_at'   => null,
            ),
            $overrides
        );
    }

    /**
     * Answers the row read for id 5 from the canned store.
     *
     * @param array<string, mixed> $row The row to serve.
     * @return void
     */
    private function serve_row( array $row ): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ) use ( $row ) {
            if ( false !== strpos( $sql, 'wp_gr_cart_abandonments WHERE id' ) ) {
                return array( $row );
            }

            return array();
        };
    }

    /**
     * Arms the happy-path store: row 5 readable, no conversion for
     * its session, mutex UPDATE wins, mail accepted.
     *
     * @param array<string, mixed> $row Row to serve.
     * @return void
     */
    private function arm_happy_path( array $row ): void {
        $this->serve_row( $row );
        $GLOBALS['wpdb']->var_result   = '0';
        $GLOBALS['wpdb']->query_result = 1;
    }

    public function testRedeemWithoutTheTargetIsAnHonestRefusal(): void {
        // First test in the file on purpose: the marker class must
        // still be absent here for this assertion to mean anything.
        self::assertFalse( class_exists( 'WooCommerce', false ) );

        self::assertFalse( Gr_Cart_Recovery::redeem_row( $this->row() ) );
    }

    public function testDelayClampsIntoTheRecordedBoundsOnEveryRead(): void {
        $settings = new Gr_Settings();

        self::assertSame( 15, Gr_Cart_Recovery::delay_minutes() );

        $settings->set( 'cart_recovery_delay', 2 );
        self::assertSame( 5, Gr_Cart_Recovery::delay_minutes() );

        $settings->set( 'cart_recovery_delay', 999 );
        self::assertSame( 120, Gr_Cart_Recovery::delay_minutes() );
    }

    public function testScheduleEnqueuesOneDelayedEventPerRow(): void {
        ( new Gr_Settings() )->set( 'cart_recovery_delay', 30 );

        Gr_Cart_Recovery::schedule( 5 );

        $event = $GLOBALS['gr_stub_cron'][0] ?? null;
        self::assertNotNull( $event );
        self::assertSame( Gr_Cart_Recovery::CHECK_HOOK, $event['hook'] );
        self::assertSame( array( 5 ), $event['args'] );
        // The queue rides the real clock, so the delay asserts as a
        // window around now, not one fixed timestamp.
        self::assertLessThanOrEqual( 2, abs( (int) $event['timestamp'] - ( time() + 1800 ) ) );

        // A junk id schedules nothing.
        $before = count( $GLOBALS['gr_stub_cron'] );
        Gr_Cart_Recovery::schedule( 0 );
        self::assertCount( $before, $GLOBALS['gr_stub_cron'] );
    }

    public function testCheckRowMailsThroughEveryOpenGate(): void {
        $this->arm_happy_path( $this->row() );

        Gr_Cart_Recovery::check_row( 5 );

        // The mutex UPDATE ran, and exactly one mail left the building.
        self::assertStringContainsString( "SET status = 'abandoned'", end( $GLOBALS['wpdb']->queries ) );
        self::assertCount( 1, $GLOBALS['gr_stub_mails'] );
        self::assertSame( 'shopper@example.com', $GLOBALS['gr_stub_mails'][0]['to'] );
        self::assertStringContainsString( 'Your cart at', $GLOBALS['gr_stub_mails'][0]['subject'] );
        self::assertStringContainsString( 'Content-Type: text/html', (string) $GLOBALS['gr_stub_mails'][0]['headers'] );
        self::assertStringContainsString( '?gr_recover=' . str_repeat( 'b', 64 ), $GLOBALS['gr_stub_mails'][0]['message'] );
    }

    public function testCheckRowRefusesWhenAnyGateCloses(): void {
        $cases = array(
            'status moved on'      => $this->row( array( 'status' => Gr_Cart_Abandonment_Repository::STATUS_ABANDONED ) ),
            'consent withdrawn'    => $this->row( array( 'consent' => 0 ) ),
        );

        foreach ( $cases as $row ) {
            $this->arm_happy_path( $row );
            $GLOBALS['gr_stub_mails'] = array();

            Gr_Cart_Recovery::check_row( 5 );

            self::assertSame( array(), $GLOBALS['gr_stub_mails'], 'a closed gate must never mail' );
            self::assertStringNotContainsString( "SET status = 'abandoned'", end( $GLOBALS['wpdb']->queries ) );
        }
    }

    public function testCheckRowRefusesWhenTheSessionAlreadyConverted(): void {
        $this->arm_happy_path( $this->row() );
        $GLOBALS['wpdb']->var_result = '88';
        $GLOBALS['gr_stub_mails']    = array();

        Gr_Cart_Recovery::check_row( 5 );

        self::assertSame( array(), $GLOBALS['gr_stub_mails'] );
        self::assertStringContainsString( 'wp_gr_conversions WHERE session_id', end( $GLOBALS['wpdb']->queries ) );
    }

    public function testCheckRowParksUndecryptableRowsAsFailed(): void {
        $row = $this->row( array( 'email_enc' => 'not-an-envelope' ) );
        $this->arm_happy_path( $row );
        $GLOBALS['gr_stub_mails'] = array();

        Gr_Cart_Recovery::check_row( 5 );

        self::assertSame( array(), $GLOBALS['gr_stub_mails'] );
        self::assertStringContainsString( "SET status = 'failed'", end( $GLOBALS['wpdb']->queries ) );
    }

    public function testAMutexLossNeverMails(): void {
        $this->arm_happy_path( $this->row() );
        // The guarded UPDATE answers zero: another check won the row.
        $GLOBALS['wpdb']->query_result = 0;

        Gr_Cart_Recovery::check_row( 5 );

        self::assertSame( array(), $GLOBALS['gr_stub_mails'] );
    }

    public function testUnsubscribedEmailsNeverMailAgain(): void {
        $row = $this->row();
        $this->arm_happy_path( $row );
        ( new Gr_Cart_Abandonment_Repository() )->add_unsubscribed( $row['email_hash'] );
        $GLOBALS['gr_stub_mails'] = array();

        Gr_Cart_Recovery::check_row( 5 );

        self::assertSame( array(), $GLOBALS['gr_stub_mails'] );
    }

    public function testAMissingRowIsASilentNoOp(): void {
        $GLOBALS['wpdb']->results = array();

        Gr_Cart_Recovery::check_row( 404 );

        self::assertSame( array(), $GLOBALS['gr_stub_mails'] );
    }

    public function testRefusedMailRetriesOnceThenParksAsFailed(): void {
        $this->arm_happy_path( $this->row() );
        $GLOBALS['gr_stub_mail_result'] = false;

        // First refusal: the premature flip returns to captured, then
        // one retry is scheduled six hours out.
        Gr_Cart_Recovery::check_row( 5 );

        self::assertCount( 1, $GLOBALS['gr_stub_cron'] );
        $retry = $GLOBALS['gr_stub_cron'][0];
        self::assertSame( Gr_Cart_Recovery::CHECK_HOOK, $retry['hook'] );
        self::assertSame( array( 5, 2 ), $retry['args'] );
        self::assertLessThanOrEqual( 2, abs( (int) $retry['timestamp'] - ( time() + Gr_Cart_Recovery::RETRY_SECONDS ) ) );
        $after_first = end( $GLOBALS['wpdb']->queries );
        self::assertStringContainsString( "SET status = 'captured'", $after_first );
        self::assertStringContainsString( "status = 'abandoned'", $after_first );
        self::assertStringNotContainsString( "SET status = 'failed'", $after_first );

        // Second refusal: no third event, the row parks as failed.
        $cron_before = count( $GLOBALS['gr_stub_cron'] );
        Gr_Cart_Recovery::check_row( 5, 2 );

        self::assertCount( $cron_before, $GLOBALS['gr_stub_cron'] );
        self::assertStringContainsString( "SET status = 'failed'", end( $GLOBALS['wpdb']->queries ) );
    }

    /**
     * A stray retry action on a row whose mail did go out (the state
     * a delivered row keeps) must send nothing and touch nothing: the
     * still-captured gate is what keeps one abandonment at one mail.
     */
    public function testAStrayRetryOnADeliveredRowSendsNothing(): void {
        $row = $this->row( array( 'status' => Gr_Cart_Abandonment_Repository::STATUS_ABANDONED ) );
        $this->serve_row( $row );
        $GLOBALS['wpdb']->var_result   = '0';
        $GLOBALS['wpdb']->query_result = 1;
        $GLOBALS['gr_stub_mails']      = array();

        Gr_Cart_Recovery::check_row( 5, 2 );

        self::assertSame( array(), $GLOBALS['gr_stub_mails'] );
        foreach ( $GLOBALS['wpdb']->queries as $sql ) {
            self::assertStringNotContainsString( 'SET status', (string) $sql );
        }
    }

    public function testRetryStillRunsEveryGate(): void {
        $this->arm_happy_path( $this->row( array( 'consent' => 0 ) ) );
        $GLOBALS['gr_stub_mail_result'] = false;

        Gr_Cart_Recovery::check_row( 5, 2 );

        // A gate that closed between the attempts stops the retry
        // without parking the row: consent withdrawal is a choice, not
        // a delivery failure.
        self::assertSame( array(), $GLOBALS['gr_stub_mails'] );
        self::assertStringNotContainsString( "SET status = 'failed'", end( $GLOBALS['wpdb']->queries ) );
    }

    public function testBodyRendersEveryPlaceholderAndKeepsTheLinks(): void {
        $row  = $this->row();
        $body = Gr_Cart_Recovery::render_body( $row );

        self::assertStringContainsString( '?gr_recover=' . str_repeat( 'b', 64 ), $body );
        self::assertStringContainsString( '?gr_unsubscribe=' . str_repeat( 'b', 64 ), $body );
        self::assertStringContainsString( 'USD 25.50', $body );
        self::assertStringContainsString( '2 × Widget', $body );
        self::assertStringContainsString( '1 × Gizmo', $body );
        // The default template's fixed copy rides through translation.
        self::assertStringContainsString( 'Finish your purchase', $body );
    }

    public function testCustomTemplateRendersWhenItCarriesBothLinks(): void {
        update_option(
            Gr_Cart_Recovery::TEMPLATE_OPTION,
            '<p>Custom {site}</p><a href="{recover_url}">back</a><a href="{unsubscribe}">out</a>',
            '',
            'no'
        );

        $body = Gr_Cart_Recovery::render_body( $this->row() );

        self::assertStringContainsString( 'Custom', $body );
        self::assertStringContainsString( '?gr_recover=', $body );
        self::assertStringContainsString( '?gr_unsubscribe=', $body );
    }

    public function testTemplateValidityRequiresBothLinksAndBounds(): void {
        self::assertFalse( Gr_Cart_Recovery::template_is_valid( '' ) );
        self::assertFalse( Gr_Cart_Recovery::template_is_valid( '<p>no links</p>' ) );
        self::assertFalse( Gr_Cart_Recovery::template_is_valid( '<a href="{recover_url}">only one</a>' ) );
        self::assertFalse( Gr_Cart_Recovery::template_is_valid( str_repeat( 'x ', 1100 ) . '{recover_url} {unsubscribe}' ) );
        self::assertTrue( Gr_Cart_Recovery::template_is_valid( '<a href="{recover_url}">a</a> <a href="{unsubscribe}">b</a>' ) );
    }

    public function testAnInvalidStoredTemplateFallsBackToTheDefault(): void {
        update_option( Gr_Cart_Recovery::TEMPLATE_OPTION, 'broken: no links here', '', 'no' );

        $body = Gr_Cart_Recovery::render_body( $this->row() );

        self::assertStringContainsString( 'Finish your purchase', $body );
        self::assertStringNotContainsString( 'broken: no links here', $body );
    }

    public function testItemsSummaryKeepsOnlyRealLines(): void {
        $row = $this->row( array( 'cart_json' => (string) wp_json_encode( array( array( 'quantity' => 0, 'name' => 'x' ), array( 'quantity' => 3, 'name' => 'Real <i>thing</i>' ) ) ) ) );

        $body = Gr_Cart_Recovery::render_body( $row );

        self::assertStringContainsString( '3 × Real', $body );
        self::assertStringNotContainsString( '<i>', $body );
    }

    public function testUnsubscribeRecordsForeverAndTagsTheContactOnce(): void {
        $row = $this->row();

        // The contact exists for the email; the same canned scalar
        // answers the tag vocabulary lookup after the tag insert.
        $GLOBALS['wpdb']->var_result = '12';

        Gr_Cart_Recovery::unsubscribe_row( $row );

        self::assertTrue( ( new Gr_Cart_Abandonment_Repository() )->is_unsubscribed( $row['email_hash'] ) );

        $sql = implode( "\n", $GLOBALS['wpdb']->queries );
        self::assertStringContainsString( "INSERT IGNORE INTO wp_gr_tags", $sql );
        self::assertStringContainsString( Gr_Cart_Recovery::UNSUB_TAG, $sql );
        self::assertStringContainsString( 'INSERT IGNORE INTO wp_gr_contact_tags', $sql );
        self::assertStringContainsString( '12', $sql );

        // A second unsubscribe from an older mail in the same mailbox:
        // the hash was already on record, so the system tag is never
        // re-attached after the owner removed it.
        $queries = count( $GLOBALS['wpdb']->queries );
        Gr_Cart_Recovery::unsubscribe_row( $row );
        self::assertCount( $queries, $GLOBALS['wpdb']->queries );
    }

    public function testUnsubscribeWithoutAContactStillRecordsForever(): void {
        $row = $this->row();

        $GLOBALS['wpdb']->var_result = '0';

        Gr_Cart_Recovery::unsubscribe_row( $row );

        self::assertTrue( ( new Gr_Cart_Abandonment_Repository() )->is_unsubscribed( $row['email_hash'] ) );
    }
}
