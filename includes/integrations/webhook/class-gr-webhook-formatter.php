<?php
/**
 * Messaging card formatter (docs/07 §1 messaging row, ADR-0016
 * extension, mock/07): pure local translation of one queued event
 * snapshot into the payload shape a Feishu, WeCom, DingTalk, or
 * Slack bot endpoint expects. No credentials of any platform are
 * handled here beyond the endpoint's own signing secret, which
 * doubles as the robot's signing key on the two platforms that
 * verify one; formatting never touches the wire — the delivery job
 * owns every outbound attempt.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Integrations\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Admin\Gr_Admin_Menu;

/**
 * One snapshot, four platform payload shapes, one verdict per answer.
 */
final class Gr_Webhook_Formatter {

    /**
     * The DingTalk signed query: the official robot verification is
     * timestamp + sign on the URL, not in the body.
     *
     * @param string $url    The stored endpoint URL.
     * @param string $secret The endpoint's signing secret.
     * @return string The URL with timestamp and sign appended.
     */
    public static function url( string $url, string $secret ): string {
        // The official contract carries a millisecond timestamp and
        // a sign over "timestamp\nsecret", HMAC-SHA256 keyed with the
        // secret, Base64 then URL-encoded.
        $millis = (string) (int) round( microtime( true ) * 1000 );
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the DingTalk sign contract is literally Base64 HMAC-SHA256; there is no other encoding.
        $sign = rawurlencode( base64_encode( hash_hmac( 'sha256', $millis . "\n" . $secret, $secret, true ) ) );

        return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'timestamp=' . $millis . '&sign=' . $sign;
    }

    /**
     * One platform payload body for one snapshot.
     *
     * @param string               $kind     One of the platform kind words.
     * @param array<string, mixed> $snapshot The queued event snapshot.
     * @param string               $secret   The endpoint's signing secret.
     * @return string JSON bytes, '' when the payload cannot encode.
     */
    public static function body( string $kind, array $snapshot, string $secret ): string {
        $payload = self::payload( $kind, $snapshot, $secret );
        if ( null === $payload ) {
            return '';
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the wire body must be byte-stable: the signature header covers exactly these bytes, so the plain encoder is the contract, not a preference.
        $body = json_encode( $payload );

        return false === $body ? '' : $body;
    }

    /**
     * The platform verdict for a 2xx answer: Feishu, WeCom, and
     * DingTalk refuse inside an HTTP 200 body, so the status code
     * alone would lie about success.
     *
     * @param string               $kind   One of the kind words.
     * @param array<string, mixed> $result Client result: code, body.
     * @return bool True when the platform accepted the delivery.
     */
    public static function accepted( string $kind, array $result ): bool {
        $body = (string) ( $result['body'] ?? '' );

        switch ( $kind ) {
            case Gr_Webhook_Repository::KIND_FEISHU:
                $decoded = json_decode( $body, true );
                return is_array( $decoded ) && 0 === (int) ( $decoded['code'] ?? -1 );
            case Gr_Webhook_Repository::KIND_WECOM:
            case Gr_Webhook_Repository::KIND_DINGTALK:
                $decoded = json_decode( $body, true );
                return is_array( $decoded ) && 0 === (int) ( $decoded['errcode'] ?? -1 );
            case Gr_Webhook_Repository::KIND_SLACK:
                return 'ok' === trim( $body );
            default:
                // Generic receivers answer with anything; the 2xx
                // the client already verified is the whole verdict.
                return true;
        }
    }

    /**
     * The platform-specific payload array for one snapshot.
     *
     * @param string               $kind     One of the platform kind words.
     * @param array<string, mixed> $snapshot The queued event snapshot.
     * @param string               $secret   The endpoint's signing secret.
     * @return array<string, mixed>|null
     */
    private static function payload( string $kind, array $snapshot, string $secret ) {
        switch ( $kind ) {
            case Gr_Webhook_Repository::KIND_FEISHU:
                return self::feishu( $snapshot, $secret );
            case Gr_Webhook_Repository::KIND_WECOM:
                return self::wecom( $snapshot );
            case Gr_Webhook_Repository::KIND_DINGTALK:
                return self::dingtalk( $snapshot );
            case Gr_Webhook_Repository::KIND_SLACK:
                return self::slack( $snapshot );
            default:
                return null;
        }
    }

    /**
     * Feishu interactive card: the snapshot as short field pairs
     * under a green header, the optional sign + timestamp the bots
     * that enable verification require.
     *
     * @param array<string, mixed> $snapshot Event snapshot.
     * @param string               $secret   Signing secret.
     * @return array<string, mixed>
     */
    private static function feishu( array $snapshot, string $secret ): array {
        $timestamp = (string) time();
        $fields    = self::fields( $snapshot );
        $total     = count( $fields );

        $pairs = array();
        for ( $i = 0; $i < $total; $i += 2 ) {
            $row = array(
                self::field( $fields[ $i ] ),
            );
            if ( isset( $fields[ $i + 1 ] ) ) {
                $row[] = self::field( $fields[ $i + 1 ] );
            }
            $pairs[] = array(
                'tag'    => 'div',
                'fields' => $row,
            );
        }

        return array(
            'timestamp' => $timestamp,
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the Feishu sign contract is literally Base64 HMAC-SHA256; there is no other encoding.
            'sign'      => base64_encode( hash_hmac( 'sha256', $timestamp . "\n" . $secret, $secret, true ) ),
            'msg_type'  => 'interactive',
            'card'      => array(
                'header'   => array(
                    'title'    => array(
                        'tag'     => 'plain_text',
                        'content' => self::title( $snapshot ),
                    ),
                    'template' => 'green',
                ),
                'elements' => array_merge(
                    $pairs,
                    array(
                        array( 'tag' => 'hr' ),
                        array(
                            'tag'      => 'note',
                            'elements' => array(
                                array(
                                    'tag'     => 'plain_text',
                                    'content' => 'greenpng · ' . self::site_host(),
                                ),
                            ),
                        ),
                    )
                ),
            ),
        );
    }

    /**
     * WeCom markdown message: the snapshot as one quoted block the
     * group renders as rich text.
     *
     * @param array<string, mixed> $snapshot Event snapshot.
     * @return array<string, mixed>
     */
    private static function wecom( array $snapshot ): array {
        $lines = array( '> **' . self::title( $snapshot ) . '**' );
        foreach ( self::fields( $snapshot ) as $field ) {
            $lines[] = '> **' . $field[0] . ':** ' . $field[1];
        }

        return array(
            'msgtype'  => 'markdown',
            'markdown' => array(
                'content' => implode( "\n", $lines ),
            ),
        );
    }

    /**
     * DingTalk action card: the snapshot as a markdown card with one
     * button into the admin, per the mock/07 contract.
     *
     * @param array<string, mixed> $snapshot Event snapshot.
     * @return array<string, mixed>
     */
    private static function dingtalk( array $snapshot ): array {
        $lines = array( '### ' . self::title( $snapshot ) );
        foreach ( self::fields( $snapshot ) as $field ) {
            $lines[] = '- **' . $field[0] . ':** ' . $field[1];
        }

        return array(
            'msgtype'    => 'actionCard',
            'actionCard' => array(
                'title'          => self::title( $snapshot ),
                'text'           => implode( "\n\n", $lines ),
                'btnOrientation' => '0',
                'singleTitle'    => __( 'Open the dashboard', 'greenpng' ),
                'singleURL'      => admin_url( 'admin.php?page=' . Gr_Admin_Menu::SLUG ),
            ),
        );
    }

    /**
     * Slack message: a text fallback plus one header block and one
     * section carrying the snapshot fields.
     *
     * @param array<string, mixed> $snapshot Event snapshot.
     * @return array<string, mixed>
     */
    private static function slack( array $snapshot ): array {
        $fields = array();
        foreach ( self::fields( $snapshot ) as $field ) {
            $fields[] = array(
                'type' => 'mrkdwn',
                'text' => '*' . $field[0] . ':* ' . $field[1],
            );
        }

        return array(
            'text'   => self::title( $snapshot ),
            'blocks' => array(
                array(
                    'type' => 'header',
                    'text' => array(
                        'type' => 'plain_text',
                        'text' => self::title( $snapshot ),
                    ),
                ),
                array(
                    'type'   => 'section',
                    'fields' => $fields,
                ),
            ),
        );
    }

    /**
     * One lark_md short field.
     *
     * @param array{0: string, 1: string} $field Key and rendered value.
     * @return array<string, mixed>
     */
    private static function field( array $field ): array {
        return array(
            'is_short' => true,
            'text'     => array(
                'tag'     => 'lark_md',
                'content' => '**' . $field[0] . ':** ' . $field[1],
            ),
        );
    }

    /**
     * The snapshot as display fields: event, group, the hashed
     * visitor and session, then the payload's scalar entries in
     * their stored order, capped so a payload burst cannot make a
     * card of absurd size.
     *
     * @param array<string, mixed> $snapshot Event snapshot.
     * @return array<int, array{0: string, 1: string}>
     */
    private static function fields( array $snapshot ): array {
        $fields = array(
            array( __( 'Event', 'greenpng' ), (string) ( $snapshot['name'] ?? '' ) ),
            array( __( 'Group', 'greenpng' ), (string) ( $snapshot['group'] ?? '' ) ),
        );

        if ( '' !== (string) ( $snapshot['visitor_id'] ?? '' ) ) {
            $fields[] = array( __( 'Visitor', 'greenpng' ), (string) $snapshot['visitor_id'] );
        }
        if ( '' !== (string) ( $snapshot['session_id'] ?? '' ) ) {
            $fields[] = array( __( 'Session', 'greenpng' ), (string) $snapshot['session_id'] );
        }

        $payload = is_array( $snapshot['payload'] ?? null ) ? (array) $snapshot['payload'] : array();
        $scalars = 0;
        foreach ( $payload as $key => $value ) {
            if ( $scalars >= 6 ) {
                break;
            }
            if ( is_scalar( $value ) ) {
                $fields[] = array( (string) $key, (string) $value );
                ++$scalars;
            }
        }

        return $fields;
    }

    /**
     * The card title for one event: the two funnel events get their
     * own words, everything else rides one pattern.
     *
     * @param array<string, mixed> $snapshot Event snapshot.
     * @return string
     */
    private static function title( array $snapshot ): string {
        $name = (string) ( $snapshot['name'] ?? '' );

        if ( 'conversion' === $name ) {
            return __( 'New conversion recorded', 'greenpng' );
        }
        if ( 'lead' === $name ) {
            return __( 'New lead captured', 'greenpng' );
        }

        return sprintf(
            /* translators: %s: machine event name. */
            __( 'New %s event', 'greenpng' ),
            $name
        );
    }

    /**
     * The site's own host for the card footnote, a local fact the
     * receiver cannot infer otherwise.
     *
     * @return string
     */
    private static function site_host(): string {
        $host = wp_parse_url( home_url(), PHP_URL_HOST );

        return is_string( $host ) ? $host : '';
    }
}
