<?php
/**
 * Secret material service (docs/13 C4): envelope round-trip, tamper
 * rejection, no-plaintext-at-rest option storage, and the deterministic
 * hash/HMAC/id primitives.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Request;
use GreenPNG\Core\Gr_Secrets;
use PHPUnit\Framework\TestCase;

final class SecretsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    public function testEncryptDecryptRoundTrip(): void {
        foreach ( array( 'meta-token-abc', '', 'ünïcödé 密钥', str_repeat( 'x', 4096 ) ) as $plaintext ) {
            $envelope = Gr_Secrets::encrypt( $plaintext );

            self::assertNotSame( '', $envelope, "empty envelope for plaintext #{$plaintext}" );
            self::assertSame( $plaintext, Gr_Secrets::decrypt( $envelope ) );
        }
    }

    public function testCiphertextNeverCarriesThePlaintext(): void {
        $envelope = Gr_Secrets::encrypt( 'super-secret-bearer-token' );

        self::assertStringNotContainsString( 'super-secret-bearer-token', $envelope );
        self::assertStringNotContainsString( base64_encode( 'super-secret-bearer-token' ), $envelope );
    }

    public function testGarbageAndTamperedEnvelopesAreRejected(): void {
        self::assertFalse( Gr_Secrets::decrypt( 'not-base64!!' ) );
        self::assertFalse( Gr_Secrets::decrypt( base64_encode( 'far-too-short' ) ) );

        $envelope = Gr_Secrets::encrypt( 'payload' );
        $raw      = base64_decode( $envelope, true );
        $raw[0]   = $raw[0] === 'A' ? 'B' : 'A';

        self::assertFalse( Gr_Secrets::decrypt( base64_encode( $raw ) ) );
    }

    public function testEnvelopesAreUniquePerCall(): void {
        // Same plaintext, fresh nonce each time.
        self::assertNotSame( Gr_Secrets::encrypt( 'same' ), Gr_Secrets::encrypt( 'same' ) );
    }

    public function testStorePersistsEncryptedAndRevealReadsBack(): void {
        self::assertTrue( Gr_Secrets::store( 'gr_probe_token', 'tok-123' ) );

        $stored = get_option( 'gr_probe_token', '' );
        self::assertNotSame( 'tok-123', $stored );
        self::assertStringNotContainsString( 'tok-123', (string) $stored );
        self::assertSame( 'no', $GLOBALS['gr_stub_options']['autoload']['gr_probe_token'] );
        self::assertSame( 'tok-123', Gr_Secrets::reveal( 'gr_probe_token' ) );

        self::assertTrue( Gr_Secrets::forget( 'gr_probe_token' ) );
        self::assertSame( '', Gr_Secrets::reveal( 'gr_probe_token' ) );
    }

    public function testRevealOfAnUnconfiguredKeyIsEmptyNeverMocked(): void {
        self::assertSame( '', Gr_Secrets::reveal( 'gr_never_stored' ) );
    }

    public function testHashPiiIsNormalizedTypedAndEmptySafe(): void {
        self::assertSame(
            Gr_Secrets::hash_pii( '  User@Example.COM ', 'email' ),
            Gr_Secrets::hash_pii( 'user@example.com', 'email' )
        );
        // The type namespaces the hash input, so one value cannot be
        // linked across PII kinds.
        self::assertNotSame(
            Gr_Secrets::hash_pii( '1.2.3.4', 'ip' ),
            Gr_Secrets::hash_pii( '1.2.3.4', 'email' )
        );
        self::assertSame( '', Gr_Secrets::hash_pii( '', 'email' ) );
    }

    public function testSignHmacIsDeterministicAndSecretDependent(): void {
        $first  = Gr_Secrets::sign_hmac( 'timestamp.body', 'shared-secret' );
        $second = Gr_Secrets::sign_hmac( 'timestamp.body', 'shared-secret' );

        self::assertSame( $first, $second );
        self::assertSame( 64, strlen( $first ) );
        self::assertNotSame( $first, Gr_Secrets::sign_hmac( 'timestamp.body', 'other-secret' ) );
    }

    public function testGenerateEventIdRandomFormMatchesTheRegistryShape(): void {
        self::assertMatchesRegularExpression( '/^gr_[0-9a-f]{32}$/', Gr_Secrets::generate_event_id() );
        self::assertNotSame( Gr_Secrets::generate_event_id(), Gr_Secrets::generate_event_id() );
        self::assertMatchesRegularExpression( '/^order_[0-9a-f]{32}$/', Gr_Secrets::generate_event_id( 'order' ) );
    }

    public function testGenerateEventIdWithEntropyConvergesForReplays(): void {
        $a = Gr_Secrets::generate_event_id( 'gr', 'order-42|cart-recovery' );
        $b = Gr_Secrets::generate_event_id( 'gr', 'order-42|cart-recovery' );

        self::assertSame( $a, $b );
        self::assertNotSame( $a, Gr_Secrets::generate_event_id( 'gr', 'order-43|cart-recovery' ) );
    }

    public function testUserAgentIsSanitizedAndCapped(): void {
        $this->with_user_agent( '' );
        self::assertSame( '', Gr_Request::user_agent() );

        $this->with_user_agent( "Mozilla/5.0\r\nX-Injected: 1\t\t\tExtra   Spaces" );
        self::assertSame( 'Mozilla/5.0 X-Injected: 1 Extra Spaces', Gr_Request::user_agent() );

        $this->with_user_agent( str_repeat( 'U', 700 ) );
        self::assertSame( 512, strlen( Gr_Request::user_agent() ) );
    }

    /**
     * @return void
     */
    protected function tearDown(): void {
        unset( $_SERVER['HTTP_USER_AGENT'] );
        parent::tearDown();
    }

    /**
     * Sets the stub user agent header.
     *
     * @param string $value Header value; '' unsets it.
     * @return void
     */
    private function with_user_agent( string $value ): void {
        if ( '' === $value ) {
            unset( $_SERVER['HTTP_USER_AGENT'] );
            return;
        }

        $_SERVER['HTTP_USER_AGENT'] = $value;
    }
}
