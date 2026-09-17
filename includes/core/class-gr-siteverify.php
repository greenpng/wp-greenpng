<?php
/**
 * Human-challenge siteverify client (docs/07 §5.3, docs/19 V11,
 * ADR-0018): one synchronous validation request per challenged
 * submission, through the only sanctioned door, for the provider the
 * site owner switched on. Result words only — ok, fail, duplicate,
 * not_configured, unreachable — so nothing downstream ever branches
 * on a provider's raw vocabulary, and no token or secret ever enters
 * an audit row or a log line.
 *
 * Token one-time consumption is enforced locally before the wire: a
 * replayed token is answered as a duplicate without its doomed round
 * trip, and the provider's own replay words (which differ per
 * provider, mock/06 §2.4) map onto the same local word.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Storage\Gr_Audit_Repository;

/**
 * Provider-site siteverify for the progressive challenge.
 */
final class Gr_Siteverify {

    /** Provider word: Cloudflare Turnstile. */
    public const PROVIDER_TURNSTILE = 'turnstile';

    /** Provider word: hCaptcha. */
    public const PROVIDER_HCAPTCHA = 'hcaptcha';

    /** Turnstile siteverify endpoint. */
    public const ENDPOINT_TURNSTILE = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** The hCaptcha siteverify endpoint. */
    public const ENDPOINT_HCAPTCHA = 'https://api.hcaptcha.com/siteverify';

    /** Consumed-token key namespace. */
    private const USED_PREFIX = 'gr_siteverify_used_';

    /** How long a seen token stays dead locally, in seconds. */
    private const USED_WINDOW = 3600;

    /** Verification-state key namespace, one entry per address. */
    private const STATE_PREFIX = 'gr_challenge_state_';

    /** How long the last verification state stays readable. */
    private const STATE_WINDOW = 3600;

    /**
     * Validates one challenge token with the provider.
     *
     * @param string $provider  Provider word constant.
     * @param string $token     One-time widget token from the submission.
     * @param string $remote_ip Connecting address, forwarded for the
     *                           provider's own cross-check.
     * @return string Result word: ok, fail, duplicate, not_configured,
     *                or unreachable.
     */
    public static function verify( string $provider, string $token, string $remote_ip ): string {
        $secret = self::secret( $provider );
        if ( '' === $secret ) {
            // No stored secret, no outbound, no fake success (S4).
            return self::record( $provider, 'not_configured', $remote_ip );
        }

        if ( '' === $token ) {
            return self::record( $provider, 'fail', $remote_ip );
        }

        $used_key = self::used_key( $token );
        if ( false !== get_transient( $used_key ) ) {
            // One-time consumption is enforced locally first: the
            // replay never gets its doomed wire round trip.
            return self::record( $provider, 'duplicate', $remote_ip );
        }

        $response = Gr_Http_Client::post_raw(
            self::PROVIDER_TURNSTILE === $provider ? Gr_Http_Client::SERVICE_TURNSTILE : Gr_Http_Client::SERVICE_HCAPTCHA,
            self::endpoint( $provider ),
            self::body( $secret, $token, $remote_ip ),
            array( 'Content-Type' => 'application/x-www-form-urlencoded' )
        );

        if ( is_wp_error( $response ) ) {
            // Wire failure, breaker open, backoff wait, or an HTTP
            // error status: the caller treats it as fail-closed
            // (ADR-0018 D3).
            return self::record( $provider, 'unreachable', $remote_ip );
        }

        $decoded = json_decode( (string) $response['body'], true );

        // The token is spent the moment the provider has seen it —
        // verdict either way; a second submit is a replay from here on.
        set_transient( $used_key, 1, self::USED_WINDOW );

        if ( ! is_array( $decoded ) ) {
            return self::record( $provider, 'unreachable', $remote_ip );
        }

        if ( ! empty( $decoded['success'] ) ) {
            return self::record( $provider, 'ok', $remote_ip );
        }

        return self::record( $provider, self::map_error( $provider, $decoded ), $remote_ip );
    }

    /**
     * The POST field the provider's widget writes its token into.
     *
     * @param string $provider Provider word constant.
     * @return string
     */
    public static function token_field( string $provider ): string {
        return self::PROVIDER_TURNSTILE === $provider ? 'cf-turnstile-response' : 'h-captcha-response';
    }

    /**
     * The stored provider secret, '' when absent.
     *
     * @param string $provider Provider word constant.
     * @return string
     */
    public static function secret( string $provider ): string {
        $option = self::PROVIDER_TURNSTILE === $provider
            ? Gr_Secrets::TURNSTILE_SECRET_OPTION
            : Gr_Secrets::HCAPTCHA_SECRET_OPTION;

        return Gr_Secrets::reveal( $option );
    }

    /**
     * The last verification state for one address: words only.
     *
     * @param string $remote_ip Connecting address.
     * @return array{provider: string, result: string, at: int}
     */
    public static function state( string $remote_ip ): array {
        $state = get_transient( self::STATE_PREFIX . md5( $remote_ip ) );

        if ( is_array( $state ) && isset( $state['provider'], $state['result'], $state['at'] ) ) {
            return array(
                'provider' => (string) $state['provider'],
                'result'   => (string) $state['result'],
                'at'       => (int) $state['at'],
            );
        }

        return array(
            'provider' => '',
            'result'   => '',
            'at'       => 0,
        );
    }

    /**
     * The siteverify URL for one provider, behind the per-provider
     * filter so an owner can point the request at an inspection proxy
     * without touching the code — the same discipline as the
     * GR_META_API_VERSION constant-plus-filter.
     *
     * @param string $provider Provider word constant.
     * @return string
     */
    private static function endpoint( string $provider ): string {
        $url = self::PROVIDER_TURNSTILE === $provider ? self::ENDPOINT_TURNSTILE : self::ENDPOINT_HCAPTCHA;

        $filtered = apply_filters( 'gr_' . $provider . '_siteverify_url', $url );

        return is_string( $filtered ) && '' !== $filtered ? $filtered : $url;
    }

    /**
     * The form-encoded request body both providers accept.
     *
     * @param string $secret    Provider secret.
     * @param string $token     One-time widget token.
     * @param string $remote_ip Connecting address.
     * @return string
     */
    private static function body( string $secret, string $token, string $remote_ip ): string {
        $fields = array(
            'secret'   => $secret,
            'response' => $token,
        );
        if ( '' !== $remote_ip && filter_var( $remote_ip, FILTER_VALIDATE_IP ) ) {
            $fields['remoteip'] = $remote_ip;
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- http_build_query is the canonical form-encoder for the wire, not a string mangler.
        return http_build_query( $fields );
    }

    /**
     * Maps a provider failure onto the local vocabulary. The two
     * providers word the same failure differently (mock/06 §2.4) —
     * replayed tokens especially — so the words are per-provider and
     * never mixed.
     *
     * @param string               $provider Provider word constant.
     * @param array<string, mixed> $decoded  Provider response body.
     * @return string Result word.
     */
    private static function map_error( string $provider, array $decoded ): string {
        $replay = self::PROVIDER_TURNSTILE === $provider
            ? 'timeout-or-duplicate'
            : 'invalid-or-already-seen-response';

        $codes = isset( $decoded['error-codes'] ) && is_array( $decoded['error-codes'] ) ? $decoded['error-codes'] : array();
        foreach ( $codes as $code ) {
            if ( ! is_string( $code ) ) {
                continue;
            }
            if ( $replay === $code ) {
                return 'duplicate';
            }
            if ( 'invalid-input-secret' === $code ) {
                // The provider rejected our stored secret: the
                // credentials are unusable, which reads as a
                // configuration failure, not a bot verdict.
                return 'not_configured';
            }
        }

        return 'fail';
    }

    /**
     * Writes the result words where they belong: one short-lived
     * state entry for the address, one audit row with the outcome word
     * and never the token, the secret, or the endpoint.
     *
     * @param string $provider  Provider word constant.
     * @param string $result    Result word.
     * @param string $remote_ip Connecting address.
     * @return string The same result word, for the caller's branch.
     */
    private static function record( string $provider, string $result, string $remote_ip ): string {
        set_transient(
            self::STATE_PREFIX . md5( $remote_ip ),
            array(
                'provider' => $provider,
                'result'   => $result,
                'at'       => time(),
            ),
            self::STATE_WINDOW
        );

        ( new Gr_Audit_Repository() )->log(
            'siteverify',
            'challenge',
            $provider,
            array(),
            array( 'result' => $result ),
            0
        );

        return $result;
    }

    /**
     * Consumed-token key: the token's digest, never the token itself.
     *
     * @param string $token One-time widget token.
     * @return string
     */
    private static function used_key( string $token ): string {
        return self::USED_PREFIX . md5( $token );
    }
}
