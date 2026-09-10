<?php
/**
 * Attribution parameter parsing (docs/13 C7): extraction, channel
 * taxonomy, referral upgrade, and the campaign-entry decision.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Attribution\Gr_Attribution_Params;
use PHPUnit\Framework\TestCase;

final class AttributionParamsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    public function testExtractsTheFullUtmVocabularyAndNormalizesCase(): void {
        $parsed = Gr_Attribution_Params::parse(
            array(
                'utm_source'   => '  Google ',
                'utm_medium'   => 'CPC',
                'utm_campaign' => 'spring_sale',
                'utm_term'     => 'shoes',
                'utm_content'  => 'banner_a',
                'noise'        => 'dropped elsewhere',
            )
        );

        self::assertSame( 'google', $parsed['utm_source'] );
        self::assertSame( 'cpc', $parsed['utm_medium'] );
        self::assertSame( 'spring_sale', $parsed['utm_campaign'] );
        self::assertSame( 'shoes', $parsed['utm_term'] );
        self::assertSame( 'banner_a', $parsed['utm_content'] );
        self::assertSame( 'cpc', $parsed['channel'] );
        self::assertArrayNotHasKey( 'noise', $parsed );
    }

    public function testClickIdsMapToTheirPaidChannelsInPrecedence(): void {
        $gclid = Gr_Attribution_Params::parse( array( 'utm_source' => 'google', 'gclid' => 'EAIa123' ) );
        self::assertSame( 'cpc', $gclid['channel'] );
        self::assertSame( 'eaia123', $gclid['click_id'] );

        $msclkid = Gr_Attribution_Params::parse( array( 'msclkid' => 'abc' ) );
        self::assertSame( 'cpc', $msclkid['channel'] );

        $fbclid = Gr_Attribution_Params::parse( array( 'fbclid' => 'IwAR2' ) );
        self::assertSame( 'social', $fbclid['channel'] );
        self::assertSame( 'iwar2', $fbclid['click_id'] );
    }

    public function testMediumVocabularyCoversTheKnownChannels(): void {
        $cases = array(
            'email'     => 'email',
            'social'    => 'social',
            'affiliate' => 'affiliate',
            'display'   => 'display',
            'cpm'       => 'display',
            'referral'  => 'referral',
            'organic'   => 'organic',
            'banner'    => 'display',
            'wechat'    => 'other',
        );

        foreach ( $cases as $medium => $channel ) {
            $parsed = Gr_Attribution_Params::parse( array( 'utm_medium' => $medium ) );
            self::assertSame( $channel, $parsed['channel'], "medium={$medium}" );
        }

        self::assertSame( 'direct', Gr_Attribution_Params::parse( array() )['channel'] );
    }

    public function testExternalReferrerUpgradesDirectToReferralButNotOwnHost(): void {
        $parsed = Gr_Attribution_Params::parse( array() );

        $upgraded = Gr_Attribution_Params::apply_referrer( $parsed, 'partner.example' );
        self::assertSame( 'referral', $upgraded['channel'] );

        // apply_referrer() is pure and host-agnostic: any non-empty host
        // upgrades a would-be direct entry. Deciding which hosts are the
        // site's own is the listener's job (see AttributionListenerTest).
        $own = Gr_Attribution_Params::apply_referrer( $parsed, 'stub.example' );
        self::assertSame( 'referral', $own['channel'] );

        $decided = Gr_Attribution_Params::apply_referrer(
            Gr_Attribution_Params::parse( array( 'utm_medium' => 'email' ) ),
            'partner.example'
        );
        self::assertSame( 'email', $decided['channel'] );
    }

    public function testCampaignEntryDecision(): void {
        self::assertTrue( Gr_Attribution_Params::is_campaign_entry( Gr_Attribution_Params::parse( array( 'utm_source' => 'google' ) ) ) );
        self::assertTrue( Gr_Attribution_Params::is_campaign_entry( Gr_Attribution_Params::parse( array( 'gclid' => 'x' ) ) ) );
        self::assertTrue(
            Gr_Attribution_Params::is_campaign_entry(
                Gr_Attribution_Params::apply_referrer( Gr_Attribution_Params::parse( array() ), 'partner.example' )
            )
        );
        self::assertFalse( Gr_Attribution_Params::is_campaign_entry( Gr_Attribution_Params::parse( array() ) ) );
    }

    public function testNonScalarValuesAreDropped(): void {
        $parsed = Gr_Attribution_Params::parse(
            array(
                'utm_source' => array( 'not', 'a', 'string' ),
                'utm_medium' => 'email',
            )
        );

        self::assertSame( '', $parsed['utm_source'] );
        self::assertSame( 'email', $parsed['utm_medium'] );
    }
}
