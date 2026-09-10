<?php
/**
 * Rate limiter (docs/13 C6): transient and object-cache counting paths,
 * scattered per-key storage, and the read-only blocked window.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Rate_Limiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    public function testTransientPathAllowsUpToTheLimitThenBlocks(): void {
        $name = 'gr_rl_collect_' . md5( '10.0.0.7' );

        self::assertTrue( Gr_Rate_Limiter::allowed( 'collect', '10.0.0.7', 2, 60 ) );
        self::assertSame( 1, $GLOBALS['gr_stub_transients'][ $name ]['value'] );

        self::assertTrue( Gr_Rate_Limiter::allowed( 'collect', '10.0.0.7', 2, 60 ) );
        self::assertSame( 2, $GLOBALS['gr_stub_transients'][ $name ]['value'] );

        self::assertFalse( Gr_Rate_Limiter::allowed( 'collect', '10.0.0.7', 2, 60 ) );
        // Blocked hits stop after the read: the counter never climbs past
        // the limit, so floods do not multiply option writes.
        self::assertSame( 2, $GLOBALS['gr_stub_transients'][ $name ]['value'] );
    }

    public function testTransientKeysAreScatteredPerCallerKey(): void {
        Gr_Rate_Limiter::allowed( 'collect', '10.0.0.7', 5, 60 );
        Gr_Rate_Limiter::allowed( 'collect', '10.0.0.8', 5, 60 );

        self::assertCount( 2, $GLOBALS['gr_stub_transients'] );
        self::assertArrayHasKey( 'gr_rl_collect_' . md5( '10.0.0.7' ), $GLOBALS['gr_stub_transients'] );
        self::assertArrayHasKey( 'gr_rl_collect_' . md5( '10.0.0.8' ), $GLOBALS['gr_stub_transients'] );
    }

    public function testObjectCachePathCountsInsideTheGreenpngGroup(): void {
        $GLOBALS['gr_stub_ext_cache'] = true;
        $entry = 'greenpng:gr_rl_collect_' . md5( '10.0.0.7' );

        self::assertTrue( Gr_Rate_Limiter::allowed( 'collect', '10.0.0.7', 2, 60 ) );
        self::assertSame( 1, $GLOBALS['gr_stub_cache'][ $entry ] );

        self::assertTrue( Gr_Rate_Limiter::allowed( 'collect', '10.0.0.7', 2, 60 ) );
        self::assertSame( 2, $GLOBALS['gr_stub_cache'][ $entry ] );

        self::assertFalse( Gr_Rate_Limiter::allowed( 'collect', '10.0.0.7', 2, 60 ) );
        self::assertSame( 3, $GLOBALS['gr_stub_cache'][ $entry ] );
        self::assertSame( array(), $GLOBALS['gr_stub_transients'] );
    }

    public function testZeroLimitIsClampedToASingleHit(): void {
        self::assertTrue( Gr_Rate_Limiter::allowed( 'collect', '10.0.0.7', 0, 60 ) );
        self::assertFalse( Gr_Rate_Limiter::allowed( 'collect', '10.0.0.7', 0, 60 ) );
    }
}
