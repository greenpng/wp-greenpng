<?php
/**
 * Attribution parameter parsing (docs/03 §4): normalizes the marketing
 * query vocabulary into the touchpoint column set. Channel derivation
 * lives here so the listener and any future caller share one taxonomy;
 * unknown media never leak raw values into the channel column.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Attribution;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Pure extractor: query params in, touchpoint columns out.
 */
final class Gr_Attribution_Params {

    /**
     * Click ids that mark paid search, in precedence order.
     *
     * @var array<int, string>
     */
    private const SEARCH_CLICK_IDS = array( 'gclid', 'gbraid', 'wbraid', 'msclkid' );

    /**
     * Click ids that mark paid social.
     *
     * @var array<int, string>
     */
    private const SOCIAL_CLICK_IDS = array( 'fbclid', 'ttclid' );

    /**
     * Channel column width (docs/05 gr_touchpoints).
     */
    private const CHANNEL_WIDTH = 32;

    /**
     * Parses raw query params into touchpoint columns. Values are
     * trimmed and lowercased (attribution vocabulary is case-insensitive
     * by convention); one click id survives, in the order above.
     *
     * @param array<string, mixed> $params Raw query params (already unslashed).
     * @return array<string, string> channel, utm_*, click_id.
     */
    public static function parse( array $params ): array {
        $utm = array();
        foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ) as $key ) {
            $utm[ $key ] = self::clean( $params[ $key ] ?? '' );
        }

        $click_id  = '';
        $click_key = '';
        foreach ( array_merge( self::SEARCH_CLICK_IDS, self::SOCIAL_CLICK_IDS ) as $key ) {
            $value = self::clean( $params[ $key ] ?? '' );
            if ( '' !== $value ) {
                $click_id  = $value;
                $click_key = $key;
                break;
            }
        }

        return array_merge(
            $utm,
            array(
                'channel'  => self::channel( $utm, $click_key ),
                'click_id' => $click_id,
            )
        );
    }

    /**
     * Upgrades a would-be direct entry to referral when an external
     * referrer host is present; channels already decided by utm or click
     * id stay untouched.
     *
     * @param array<string, string> $parsed       Output of parse().
     * @param string                $referer_host External referrer host, '' when none.
     * @return array<string, string>
     */
    public static function apply_referrer( array $parsed, string $referer_host ): array {
        if ( '' !== $referer_host && 'direct' === $parsed['channel'] ) {
            $parsed['channel'] = 'referral';
        }

        return $parsed;
    }

    /**
     * Whether the parsed set represents a marketing entry worth a
     * touchpoint row: any tracked parameter present, or a referral
     * channel. Plain direct visits never qualify, keeping the touchpoint
     * chain to real campaign entries.
     *
     * @param array<string, string> $parsed Output of parse(), after apply_referrer().
     * @return bool
     */
    public static function is_campaign_entry( array $parsed ): bool {
        foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'click_id' ) as $key ) {
            if ( '' !== (string) ( $parsed[ $key ] ?? '' ) ) {
                return true;
            }
        }

        return 'referral' === $parsed['channel'];
    }

    /**
     * Channel taxonomy: the winning click id's origin decides first,
     * then the medium vocabulary, then direct. Unknown non-empty media
     * collapse to 'other' so the column stays a closed vocabulary.
     *
     * @param array<string, string> $utm       Parsed utm values.
     * @param string                $click_key Param name the click id came from.
     * @return string
     */
    private static function channel( array $utm, string $click_key ): string {
        if ( '' !== $click_key ) {
            return in_array( $click_key, self::SEARCH_CLICK_IDS, true ) ? 'cpc' : 'social';
        }

        $medium = $utm['utm_medium'];
        if ( '' === $medium ) {
            return 'direct';
        }

        $known = array(
            'cpc'        => 'cpc',
            'ppc'        => 'cpc',
            'paidsearch' => 'cpc',
            'paid'       => 'cpc',
            'email'      => 'email',
            'social'     => 'social',
            'affiliate'  => 'affiliate',
            'display'    => 'display',
            'cpm'        => 'display',
            'banner'     => 'display',
            'referral'   => 'referral',
            'organic'    => 'organic',
        );

        if ( isset( $known[ $medium ] ) ) {
            return $known[ $medium ];
        }

        return 'other';
    }

    /**
     * One value's normalization: scalar, trimmed, lowercased.
     *
     * @param mixed $value Raw value.
     * @return string
     */
    private static function clean( $value ): string {
        if ( ! is_scalar( $value ) ) {
            return '';
        }

        $text = trim( (string) $value );

        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
    }
}
