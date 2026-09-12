<?php
/**
 * Unified outbound channel (docs/13 I1, docs/07 §2): the not-configured
 * gate, the timeout cap, the backoff ladder, the breaker, 429
 * Retry-After, the give-up audit row, and the standing rule that no
 * other file in the plugin touches the remote functions directly.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Http_Client;
use GreenPNG\Core\Gr_Secrets;
use GreenPNG\Core\Gr_Settings;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class HttpClientTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    /**
     * Enables the Meta service with a full credential pair.
     *
     * @return void
     */
    private function enable_meta(): void {
        $settings = new Gr_Settings();
        $settings->set( 'capi_meta_enabled', 1 );
        Gr_Secrets::store( Gr_Secrets::META_PIXEL_OPTION, '123456789012345' );
        Gr_Secrets::store( Gr_Secrets::META_TOKEN_OPTION, 'EAAG-' . str_repeat( 'x', 40 ) );
    }

    /**
     * Stubs one URL answer.
     *
     * @param string $url      URL to answer.
     * @param array<string, mixed>|WP_Error $answer Response or wire error.
     * @return void
     */
    private function answer( string $url, $answer ): void {
        $GLOBALS['gr_stub_http'][ $url ] = $answer;
    }

    public function testUnconfiguredServiceNeverTouchesTheNetwork(): void {
        $result = Gr_Http_Client::post( Gr_Http_Client::SERVICE_META_CAPI, 'https://graph.example/v1/event' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( Gr_Http_Client::ERR_NOT_CONFIGURED, $result->get_error_code() );
        $this->assertSame( array(), $GLOBALS['gr_stub_http_calls'] );

        $state = Gr_Http_Client::service_state( Gr_Http_Client::SERVICE_META_CAPI );
        $this->assertFalse( $state['configured'] );
        $this->assertSame( 0, $state['fails'] );
    }

    public function testHalfConfiguredIsStillNotConfigured(): void {
        // Toggle on but only one of the two credentials stored: a
        // half pair must not dispatch on the stored half.
        $settings = new Gr_Settings();
        $settings->set( 'capi_meta_enabled', 1 );
        Gr_Secrets::store( Gr_Secrets::META_PIXEL_OPTION, '123456789012345' );

        $result = Gr_Http_Client::post( Gr_Http_Client::SERVICE_META_CAPI, 'https://graph.example/v1/event' );

        $this->assertSame( Gr_Http_Client::ERR_NOT_CONFIGURED, $result->get_error_code() );
        $this->assertSame( array(), $GLOBALS['gr_stub_http_calls'] );
    }

    public function testSuccessReturnsTheAnswerAndClearsTheLedger(): void {
        $this->enable_meta();
        $this->answer( 'https://graph.example/v1/event', array(
            'response' => array( 'code' => 200 ),
            'body'     => '{"ok":true}',
        ) );

        // Seed leftover failure state: a success must clear it.
        set_transient( 'gr_http_' . Gr_Http_Client::SERVICE_META_CAPI, array(
            'fails'      => 2,
            'open_until' => 0,
            'next_at'    => 0,
        ) );

        $result = Gr_Http_Client::post( Gr_Http_Client::SERVICE_META_CAPI, 'https://graph.example/v1/event', array( 'event' => 'Purchase' ) );

        $this->assertIsArray( $result );
        $this->assertSame( 200, $result['code'] );
        $this->assertSame( '{"ok":true}', $result['body'] );

        // The wire call carried the capped timeout and the JSON body.
        $this->assertCount( 1, $GLOBALS['gr_stub_http_calls'] );
        $call = $GLOBALS['gr_stub_http_calls'][0];
        $this->assertSame( 'POST', $call['method'] );
        $this->assertSame( 5, $call['args']['timeout'] );
        $this->assertSame( 'application/json', $call['args']['headers']['Content-Type'] );
        $this->assertStringContainsString( '"Purchase"', (string) $call['args']['body'] );

        // Success closed the breaker and cancelled the backoff.
        $this->assertFalse( get_transient( 'gr_http_' . Gr_Http_Client::SERVICE_META_CAPI ) );
    }

    public function testGetCarriesNoBodyAndTheCappedTimeout(): void {
        $this->answer( 'https://db-ip.example/lite.csv', array(
            'response' => array( 'code' => 200 ),
            'body'     => 'csv',
        ) );

        $result = Gr_Http_Client::get( Gr_Http_Client::SERVICE_DBIP, 'https://db-ip.example/lite.csv' );

        $this->assertSame( array( 'code' => 200, 'body' => 'csv' ), $result );
        $call = $GLOBALS['gr_stub_http_calls'][0];
        $this->assertSame( 'GET', $call['method'] );
        $this->assertSame( 5, $call['args']['timeout'] );
        $this->assertArrayNotHasKey( 'body', $call['args'] );
    }

    /**
     * Simulates the queue rescheduling the job after its backoff: the
     * next attempt becomes due without erasing the failure ledger.
     *
     * @param string $key State transient key.
     * @return void
     */
    private function allow_next_attempt( string $key ): void {
        $state = get_transient( $key );
        if ( is_array( $state ) ) {
            $state['next_at'] = 0;
            set_transient( $key, $state, 7200 );
        }
    }

    public function testFailureLadderClimbsBackoffAndOpensTheBreaker(): void {
        $this->enable_meta();
        $url = 'https://graph.example/v1/event';
        $key = 'gr_http_' . Gr_Http_Client::SERVICE_META_CAPI;
        $this->answer( $url, array( 'response' => array( 'code' => 500 ), 'body' => '' ) );

        $first = Gr_Http_Client::post( Gr_Http_Client::SERVICE_META_CAPI, $url );
        $this->allow_next_attempt( $key );
        $second = Gr_Http_Client::post( Gr_Http_Client::SERVICE_META_CAPI, $url );
        $this->allow_next_attempt( $key );
        $third = Gr_Http_Client::post( Gr_Http_Client::SERVICE_META_CAPI, $url );

        // Every attempt went out: the gates only hold between them.
        $this->assertCount( 3, $GLOBALS['gr_stub_http_calls'] );

        $ladder = array( $first, $second, $third );
        foreach ( array( 30, 120, 900 ) as $step => $delay ) {
            $this->assertSame( Gr_Http_Client::ERR_FAILED, $ladder[ $step ]->get_error_code() );
            $this->assertSame( $delay, $ladder[ $step ]->get_error_data()['retry_after'] );
        }

        // Three consecutive failures opened the breaker.
        $state = get_transient( $key );
        $this->assertIsArray( $state );
        $this->assertSame( 3, $state['fails'] );
        $this->assertGreaterThan( time(), (int) $state['open_until'] );
    }

    public function testFourthFailureGivesUpAndRecordsOneAuditRow(): void {
        global $wpdb;

        $this->enable_meta();
        $url = 'https://graph.example/v1/event';
        $this->answer( $url, array( 'response' => array( 'code' => 500 ), 'body' => '' ) );
        $key = 'gr_http_' . Gr_Http_Client::SERVICE_META_CAPI;

        // Pre-climb: three failures spent the ladder.
        set_transient( $key, array( 'fails' => 3, 'open_until' => 0, 'next_at' => 0 ) );

        $result = Gr_Http_Client::post( Gr_Http_Client::SERVICE_META_CAPI, $url );

        $this->assertSame( Gr_Http_Client::ERR_GIVE_UP, $result->get_error_code() );

        // The ledger is cleared so the next owner action starts fresh.
        $this->assertFalse( get_transient( $key ) );

        // One audit row records the give-up; the diff carries counts,
        // never credentials or endpoints.
        $this->assertCount( 1, $wpdb->inserts );
        $write = $wpdb->inserts[0];
        $this->assertSame( 'wp_gr_audit_logs', $write['table'] );
        $this->assertSame( 'http_give_up', $write['data']['action'] );
        $this->assertSame( 'http_service', $write['data']['object_type'] );
        $this->assertSame( Gr_Http_Client::SERVICE_META_CAPI, $write['data']['object_id'] );
        $this->assertSame( 0, (int) $write['data']['user_id'] );
        $this->assertStringContainsString( 'consecutive_failures', (string) $write['data']['diff_json'] );
        $this->assertStringNotContainsString( 'EAAG', (string) $write['data']['diff_json'] );
    }

    public function testOpenBreakerFailsFastWithoutTouchingTheNetwork(): void {
        $this->enable_meta();
        set_transient( 'gr_http_' . Gr_Http_Client::SERVICE_META_CAPI, array(
            'fails'      => 3,
            'open_until' => time() + 100,
            'next_at'    => 0,
        ) );

        $result = Gr_Http_Client::post( Gr_Http_Client::SERVICE_META_CAPI, 'https://graph.example/v1/event' );

        $this->assertSame( Gr_Http_Client::ERR_BREAKER_OPEN, $result->get_error_code() );
        $this->assertGreaterThan( 0, $result->get_error_data()['retry_after'] );
        $this->assertSame( array(), $GLOBALS['gr_stub_http_calls'] );

        // The fast-fail did not count as another failure.
        $state = get_transient( 'gr_http_' . Gr_Http_Client::SERVICE_META_CAPI );
        $this->assertSame( 3, (int) $state['fails'] );
    }

    public function testPrematureRetryIsHeldBack(): void {
        $this->enable_meta();
        set_transient( 'gr_http_' . Gr_Http_Client::SERVICE_META_CAPI, array(
            'fails'      => 1,
            'open_until' => 0,
            'next_at'    => time() + 50,
        ) );

        $result = Gr_Http_Client::post( Gr_Http_Client::SERVICE_META_CAPI, 'https://graph.example/v1/event' );

        $this->assertSame( Gr_Http_Client::ERR_BACKOFF_WAIT, $result->get_error_code() );
        $this->assertSame( array(), $GLOBALS['gr_stub_http_calls'] );
    }

    public function testRateLimitReadsTheServersRetryAfter(): void {
        $this->enable_meta();
        $url = 'https://graph.example/v1/event';
        $this->answer( $url, array(
            'response' => array( 'code' => 429 ),
            'headers'  => array( 'Retry-After' => '120' ),
            'body'     => '',
        ) );

        $result = Gr_Http_Client::post( Gr_Http_Client::SERVICE_META_CAPI, $url );

        $this->assertSame( Gr_Http_Client::ERR_RATE_LIMITED, $result->get_error_code() );
        $this->assertSame( 120, $result->get_error_data()['retry_after'] );

        // The server's deadline is what the next attempt waits for.
        $state = get_transient( 'gr_http_' . Gr_Http_Client::SERVICE_META_CAPI );
        $this->assertGreaterThanOrEqual( time() + 118, (int) $state['next_at'] );
        $this->assertLessThanOrEqual( time() + 122, (int) $state['next_at'] );
    }

    public function testRateLimitWithoutHeaderFallsBackToTheLadder(): void {
        $this->enable_meta();
        $url = 'https://graph.example/v1/event';
        $this->answer( $url, array( 'response' => array( 'code' => 429 ), 'headers' => array(), 'body' => '' ) );

        $result = Gr_Http_Client::post( Gr_Http_Client::SERVICE_META_CAPI, $url );

        $this->assertSame( Gr_Http_Client::ERR_RATE_LIMITED, $result->get_error_code() );
        $this->assertSame( 30, $result->get_error_data()['retry_after'] );
    }

    public function testWireErrorCountsAsAFailure(): void {
        $this->enable_meta();
        $url = 'https://graph.example/v1/event';
        $this->answer( $url, new WP_Error( 'http_request_failed', 'Connection timed out.' ) );

        $result = Gr_Http_Client::post( Gr_Http_Client::SERVICE_META_CAPI, $url );

        $this->assertSame( Gr_Http_Client::ERR_FAILED, $result->get_error_code() );
        $state = get_transient( 'gr_http_' . Gr_Http_Client::SERVICE_META_CAPI );
        $this->assertSame( 1, (int) $state['fails'] );
    }

    public function testSuccessResetsTheLadderForTheNextFailure(): void {
        $this->enable_meta();
        $url = 'https://graph.example/v1/event';
        $key = 'gr_http_' . Gr_Http_Client::SERVICE_META_CAPI;

        // Two failures already on the ledger, then a success, then a
        // new failure: the new failure starts from the first step.
        set_transient( $key, array( 'fails' => 2, 'open_until' => 0, 'next_at' => 0 ) );
        $this->answer( $url, array( 'response' => array( 'code' => 200 ), 'body' => 'ok' ) );
        Gr_Http_Client::post( Gr_Http_Client::SERVICE_META_CAPI, $url );
        $this->assertFalse( get_transient( $key ) );

        $this->answer( $url, array( 'response' => array( 'code' => 500 ), 'body' => '' ) );
        $result = Gr_Http_Client::post( Gr_Http_Client::SERVICE_META_CAPI, $url );

        $this->assertSame( 30, $result->get_error_data()['retry_after'] );
        $state = get_transient( $key );
        $this->assertSame( 1, (int) $state['fails'] );
    }

    public function testNoFileBypassesTheClientForOutbound(): void {
        // docs/07 §2 as a machine assertion: the direct remote call
        // family appears nowhere in the plugin's code (docblocks may
        // discuss the ban; wp_remote_retrieve_* readers are not
        // calls), and the safe family appears only inside the client.
        $banned = array( 'wp_remote_get(', 'wp_remote_post(', 'wp_remote_request(', 'wp_remote_head(' );

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( GR_PLUGIN_DIR, \FilesystemIterator::SKIP_DOTS )
        );

        $safe_seen = array();
        foreach ( $files as $file ) {
            if ( 'php' !== $file->getExtension() ) {
                continue;
            }
            $source = (string) file_get_contents( $file->getPathname() );
            $code   = '';
            foreach ( token_get_all( $source ) as $token ) {
                if ( is_string( $token ) ) {
                    $code .= $token;
                    continue;
                }
                if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
                    continue;
                }
                $code .= $token[1];
            }

            foreach ( $banned as $needle ) {
                $this->assertStringNotContainsString( $needle, $code, $file->getPathname() . ' must route outbound through Gr_Http_Client' );
            }

            if ( false !== strpos( $code, 'wp_safe_remote_' ) ) {
                $safe_seen[] = $file->getPathname();
            }
        }

        $this->assertSame(
            array( GR_PLUGIN_DIR . 'includes/core/class-gr-http-client.php' ),
            $safe_seen
        );
    }
}
