<?php
/**
 * Webhooks page (ADR-0016 D1/D2): the gated CRUD arms, the refusal
 * notices for the storage rules (https, secret length, events, cap),
 * the masked-secret display, and the receiver-side verification
 * contract the page carries.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Admin\Gr_Webhooks_Page;
use GreenPNG\Integrations\Webhook\Gr_Webhook_Repository;
use PHPUnit\Framework\TestCase;

final class WebhooksPageTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        unset( $_POST, $_GET );
        $GLOBALS['gr_stub_caps']    = array( 'manage_options' );
        $GLOBALS['gr_stub_user_id'] = 7;
    }

    protected function tearDown(): void {
        unset( $_POST, $_GET );
        delete_option( Gr_Webhook_Repository::OPTION );
        parent::tearDown();
    }

    /**
     * A well-formed add POST.
     *
     * @return void
     */
    private function post_add(): void {
        $_POST = array(
            'gr_webhooks_action' => Gr_Webhooks_Page::ACTION_ADD,
            'gr_wh_url'          => 'https://receiver.example.test/hook',
            'gr_wh_secret'       => 'unit-test-secret-0123456789',
            'gr_wh_events'       => array( 'conversion', 'lead' ),
            'gr_wh_active'       => '1',
            Gr_Webhooks_Page::NONCE_FIELD => 'gr-stub-nonce-' . md5( Gr_Webhooks_Page::NONCE_ACTION ),
        );
        $_REQUEST = $_POST;
    }

    /**
     * The last redirect location.
     *
     * @return string
     */
    private function redirected_to(): string {
        $last = end( $GLOBALS['gr_stub_redirects'] );

        return false === $last ? '' : (string) $last['location'];
    }

    /**
     * Audit rows this page wrote.
     *
     * @return array<int, array<string, mixed>>
     */
    private function audit_rows(): array {
        $rows = array();
        foreach ( $GLOBALS['wpdb']->inserts as $insert ) {
            if ( 'wp_gr_audit_logs' === $insert['table'] ) {
                $rows[] = $insert;
            }
        }

        return $rows;
    }

    /**
     * Stores one endpoint through the repository for arm tests.
     *
     * @return int Endpoint id.
     */
    private function stored(): int {
        $error = '';
        $id    = Gr_Webhook_Repository::add( 'https://receiver.example.test/hook', 'unit-test-secret-0123456789', array( 'conversion' ), true, $error );
        self::assertGreaterThan( 0, $id, (string) $error );

        return $id;
    }

    public function testRenderCarriesTheVerificationContractAndEmptyState(): void {
        ob_start();
        Gr_Webhooks_Page::render();
        $html = (string) ob_get_clean();

        // The empty state says the honest thing: nothing goes out
        // without an owner-added endpoint.
        self::assertStringContainsString( 'No endpoints configured yet', $html );

        // The receiver-side contract: the four headers, the
        // constant-time comparison, and the replay window.
        self::assertStringContainsString( 'X-Gr-Signature', $html );
        self::assertStringContainsString( 'X-Gr-Delivery', $html );
        self::assertStringContainsString( 'X-Gr-Timestamp', $html );
        self::assertStringContainsString( 'hash_equals', $html );
        self::assertStringContainsString( '300-second window', $html );
        self::assertStringContainsString( 'retry twice', $html );
        self::assertStringContainsString( 'No email addresses', $html );
    }

    public function testAValidAddPersistsAuditsAndRedirects(): void {
        $this->post_add();

        Gr_Webhooks_Page::handle_actions();

        self::assertCount( 1, Gr_Webhook_Repository::all() );
        self::assertStringContainsString( 'gr_wh=added', $this->redirected_to() );

        $rows = $this->audit_rows();
        self::assertCount( 1, $rows );
        self::assertSame( 'add', $rows[0]['data']['action'] );
        self::assertSame( 'webhook_endpoint', $rows[0]['data']['object_type'] );
        self::assertSame( 7, (int) $rows[0]['data']['user_id'] );
        // The JSON escapes the URL's slashes; the host segment is
        // the stable substring to assert.
        self::assertStringContainsString( 'receiver.example.test', (string) $rows[0]['data']['diff_json'] );

        // The audit trail never carries the secret, not even masked.
        self::assertStringNotContainsString( 'unit-test-secret-0123456789', (string) $rows[0]['data']['diff_json'] );
    }

    public function testTheStorageRulesRefuseWithTheirOwnNoticeWords(): void {
        // http URL.
        $this->post_add();
        $_POST['gr_wh_url'] = 'http://receiver.example.test/hook';
        Gr_Webhooks_Page::handle_actions();
        self::assertStringContainsString( 'gr_wh=http_url', $this->redirected_to() );
        self::assertSame( array(), Gr_Webhook_Repository::all() );

        // Too-short secret.
        $this->post_add();
        $_POST['gr_wh_secret'] = 'short-secret';
        Gr_Webhooks_Page::handle_actions();
        self::assertStringContainsString( 'gr_wh=secret_short', $this->redirected_to() );
        self::assertSame( array(), Gr_Webhook_Repository::all() );

        // No event survived the vocabulary intersection.
        $this->post_add();
        $_POST['gr_wh_events'] = array( 'signal' );
        Gr_Webhooks_Page::handle_actions();
        self::assertStringContainsString( 'gr_wh=no_events', $this->redirected_to() );
        self::assertSame( array(), Gr_Webhook_Repository::all() );

        // The refusals leave no audit rows behind.
        self::assertSame( array(), $this->audit_rows() );
    }

    public function testCapabilityAndNonceAreBothHardGates(): void {
        // No capability: no write, no redirect, no audit.
        $this->post_add();
        $GLOBALS['gr_stub_caps'] = array();
        Gr_Webhooks_Page::handle_actions();
        self::assertSame( array(), Gr_Webhook_Repository::all() );
        self::assertSame( array(), $GLOBALS['gr_stub_redirects'] );
        self::assertSame( array(), $this->audit_rows() );

        // Forged nonce: same refusal.
        $this->post_add();
        $_POST[ Gr_Webhooks_Page::NONCE_FIELD ] = 'forged';
        Gr_Webhooks_Page::handle_actions();
        self::assertSame( array(), Gr_Webhook_Repository::all() );
        self::assertSame( array(), $GLOBALS['gr_stub_redirects'] );
        self::assertSame( array(), $this->audit_rows() );
    }

    public function testUpdateRotatesOrKeepsTheSecretByItsOwnAuditWord(): void {
        $id = $this->stored();

        $_POST = array(
            'gr_webhooks_action' => Gr_Webhooks_Page::ACTION_UPDATE,
            'endpoint_id'        => (string) $id,
            'gr_wh_url'          => 'https://moved.example.test/hook',
            'gr_wh_secret'       => '',
            'gr_wh_events'       => array( 'lead' ),
            'gr_wh_active'       => '1',
            Gr_Webhooks_Page::NONCE_FIELD => 'gr-stub-nonce-' . md5( Gr_Webhooks_Page::NONCE_ACTION ),
        );
        $_REQUEST = $_POST;

        Gr_Webhooks_Page::handle_actions();

        self::assertStringContainsString( 'gr_wh=updated', $this->redirected_to() );
        $rows = $this->audit_rows();
        self::assertCount( 1, $rows );
        self::assertStringContainsString( '"secret":"kept"', (string) $rows[0]['data']['diff_json'] );

        // The secret rotation leaves its word, not its value.
        $_POST['gr_wh_secret'] = 'rotated-secret-0123456789';
        $_REQUEST = $_POST;
        Gr_Webhooks_Page::handle_actions();
        $rows = $this->audit_rows();
        self::assertCount( 2, $rows );
        self::assertStringContainsString( '"secret":"rotated"', (string) $rows[1]['data']['diff_json'] );
        self::assertStringNotContainsString( 'rotated-secret-0123456789', (string) $rows[1]['data']['diff_json'] );
    }

    public function testDeleteToggleAndResetArms(): void {
        $id = $this->stored();

        // Toggle flips the active word.
        $_POST = array(
            'gr_webhooks_action' => Gr_Webhooks_Page::ACTION_TOGGLE,
            'endpoint_id'        => (string) $id,
            Gr_Webhooks_Page::NONCE_FIELD => 'gr-stub-nonce-' . md5( Gr_Webhooks_Page::NONCE_ACTION ),
        );
        $_REQUEST = $_POST;
        Gr_Webhooks_Page::handle_actions();
        self::assertSame( 0, (int) Gr_Webhook_Repository::find( $id )['active'] );
        self::assertStringContainsString( 'gr_wh=toggled', $this->redirected_to() );

        // Reset closes an open circuit.
        for ( $i = 0; $i < Gr_Webhook_Repository::CIRCUIT_THRESHOLD; $i++ ) {
            Gr_Webhook_Repository::record_delivery( $id, false, 'HTTP 500' );
        }
        $_POST['gr_webhooks_action'] = Gr_Webhooks_Page::ACTION_RESET;
        $_REQUEST = $_POST;
        Gr_Webhooks_Page::handle_actions();
        self::assertSame( 0, (int) Gr_Webhook_Repository::find( $id )['circuit_open_since'] );
        self::assertStringContainsString( 'gr_wh=reset', $this->redirected_to() );

        // Delete removes the endpoint and audits what left.
        $_POST['gr_webhooks_action'] = Gr_Webhooks_Page::ACTION_DELETE;
        $_REQUEST = $_POST;
        Gr_Webhooks_Page::handle_actions();
        self::assertNull( Gr_Webhook_Repository::find( $id ) );
        self::assertStringContainsString( 'gr_wh=deleted', $this->redirected_to() );
        self::assertCount( 3, $this->audit_rows() );
    }

    public function testTheSecretDisplaysMaskedAndThePlaintextNeverRenders(): void {
        $this->stored();

        ob_start();
        Gr_Webhooks_Page::render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString( 'un****89', $html );
        self::assertStringNotContainsString( 'unit-test-secret-0123456789', $html );
        self::assertStringContainsString( 'receiver.example.test', $html );
    }
}
