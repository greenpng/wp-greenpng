<?php
/**
 * Webhook endpoint store (ADR-0016 D1): one bounded, non-autoload
 * option holding every owner-configured delivery target. The list
 * never grows past the cap, secrets are stored as encryption
 * envelopes and never leave the site in any response, and the
 * per-endpoint health word lives beside the endpoint so the status
 * page can report delivery state without a new table (docs/05 §6
 * discipline: bounded option, not growing data).
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Integrations\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Secrets;

/**
 * Read/write access to the gr_webhooks option.
 */
final class Gr_Webhook_Repository {

    /** Option key, non-autoload by design (docs/05 §6). */
    public const OPTION = 'gr_webhooks';

    /** Hard endpoint cap (ADR-0016 D1). */
    public const CAP = 10;

    /** Consecutive exhausted deliveries that open the endpoint circuit (ADR-0016 D4). */
    public const CIRCUIT_THRESHOLD = 5;

    /**
     * The closed event vocabulary an endpoint may subscribe to
     * (ADR-0016 D1): the funnel step words plus the lead capture
     * event. Probe internals (signal, security_conclusion) and
     * high-noise names (pageview, cart_email) stay out on purpose:
     * an outbound subscription must be worth a delivery.
     */
    public const EVENTS = array( 'conversion', 'lead', 'dwell', 'scroll_depth', 'rage_click', 'dead_click', 'ab' );

    /**
     * Every endpoint row, decrypted secrets excluded.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array {
        $rows = get_option( self::OPTION, array() );

        return is_array( $rows ) ? array_values( $rows ) : array();
    }

    /**
     * One endpoint row by id, or null when the id is gone (deleted
     * meanwhile — pending queue jobs must no-op on that, not crash).
     *
     * @param int $id Endpoint id.
     * @return array<string, mixed>|null
     */
    public static function find( int $id ): ?array {
        foreach ( self::all() as $row ) {
            if ( $id === (int) ( $row['id'] ?? 0 ) ) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Whether the endpoint may take deliveries right now: exists,
     * active, and its circuit is closed.
     *
     * @param array<string, mixed> $endpoint Endpoint row.
     * @return bool
     */
    public static function deliverable( array $endpoint ): bool {
        return 1 === (int) ( $endpoint['active'] ?? 0 )
            && 0 === (int) ( $endpoint['circuit_open_since'] ?? 0 );
    }

    /**
     * The endpoint's plaintext signing secret, or '' when the stored
     * envelope no longer decrypts (an owner who rotated the site's
     * auth salt faces refused deliveries, honest and loud on the
     * status page, rather than forged ones).
     *
     * @param array<string, mixed> $endpoint Endpoint row.
     * @return string
     */
    public static function secret_of( array $endpoint ): string {
        $plain = Gr_Secrets::decrypt( (string) ( $endpoint['secret'] ?? '' ) );

        return is_string( $plain ) ? $plain : '';
    }

    /**
     * Every deliverable endpoint subscribed to an event name.
     *
     * @param string $event Event name from the bus.
     * @return array<int, array<string, mixed>>
     */
    public static function matching( string $event ): array {
        $out = array();
        foreach ( self::all() as $row ) {
            $events = $row['events'] ?? array();
            if ( is_array( $events ) && in_array( $event, $events, true ) && self::deliverable( $row ) ) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Adds an endpoint. Validation is total here so the page can
     * trust the store: https-only URLs, a secret of at least 16
     * characters, a non-empty subset of the closed vocabulary, and
     * the cap.
     *
     * @param string             $url    Delivery URL.
     * @param string             $secret Signing secret plaintext.
     * @param array<int, string> $events Event names.
     * @param bool               $active Whether deliveries run.
     * @param string             $error  Refusal reason word, set by reference ('url', 'secret', 'events', 'cap', 'cipher').
     * @return int New endpoint id, or 0 on refusal.
     */
    public static function add( string $url, string $secret, array $events, bool $active, string &$error = '' ): int {
        $url = trim( $url );

        if ( ! self::valid_url( $url ) ) {
            $error = 'url';
            return 0;
        }

        if ( strlen( $secret ) < 16 ) {
            $error = 'secret';
            return 0;
        }

        $events = self::valid_events( $events );
        if ( array() === $events ) {
            $error = 'events';
            return 0;
        }

        if ( count( self::all() ) >= self::CAP ) {
            $error = 'cap';
            return 0;
        }

        $envelope = Gr_Secrets::encrypt( $secret );
        if ( '' === $envelope ) {
            // No cipher on this host means the secret cannot be
            // protected at rest; storing it plaintext instead would
            // betray the design, so the endpoint is refused outright.
            $error = 'cipher';
            return 0;
        }

        $rows = self::raw();
        $id   = 0;
        foreach ( $rows as $row ) {
            $id = max( $id, (int) ( $row['id'] ?? 0 ) );
        }
        ++$id;

        $rows[] = self::shape( $id, $url, $envelope, $events, $active );

        self::save( $rows );

        return $id;
    }

    /**
     * Updates the mutable surface fields of one endpoint; an empty
     * secret keeps the stored envelope, a non-empty one replaces it
     * after the same length gate.
     *
     * @param int                $id     Endpoint id.
     * @param string             $url    Delivery URL.
     * @param string             $secret New signing secret, '' to keep the old.
     * @param array<int, string> $events Event names.
     * @param bool               $active Whether deliveries run.
     * @return bool
     */
    public static function update( int $id, string $url, string $secret, array $events, bool $active ): bool {
        $rows  = self::raw();
        $index = self::index_of( $rows, $id );
        if ( -1 === $index ) {
            return false;
        }

        $url = trim( $url );
        if ( ! self::valid_url( $url ) ) {
            return false;
        }

        $events = self::valid_events( $events );
        if ( array() === $events ) {
            return false;
        }

        $envelope = (string) ( $rows[ $index ]['secret'] ?? '' );
        if ( '' !== $secret ) {
            if ( strlen( $secret ) < 16 ) {
                return false;
            }
            $envelope = Gr_Secrets::encrypt( $secret );
            if ( '' === $envelope ) {
                return false;
            }
        }

        $rows[ $index ] = self::shape( $id, $url, $envelope, $events, $active, $rows[ $index ] );

        self::save( $rows );

        return true;
    }

    /**
     * Removes an endpoint; its pending queue jobs become no-ops.
     *
     * @param int $id Endpoint id.
     * @return bool
     */
    public static function delete( int $id ): bool {
        $rows  = self::raw();
        $index = self::index_of( $rows, $id );
        if ( -1 === $index ) {
            return false;
        }

        unset( $rows[ $index ] );
        self::save( array_values( $rows ) );

        return true;
    }

    /**
     * Flips an endpoint between paused and active.
     *
     * @param int $id Endpoint id.
     * @return bool
     */
    public static function toggle( int $id ): bool {
        $rows  = self::raw();
        $index = self::index_of( $rows, $id );
        if ( -1 === $index ) {
            return false;
        }

        $rows[ $index ]['active'] = 1 === (int) $rows[ $index ]['active'] ? 0 : 1;
        self::save( $rows );

        return true;
    }

    /**
     * The delivery health writeback: a delivered event resets the
     * failure streak and closes any open circuit; an exhausted one
     * (every retry spent) counts up, and the streak reaching the
     * threshold opens the circuit until the owner resets it —
     * a dead endpoint must stop hammering a receiver that is
     * refusing it (ADR-0016 D4).
     *
     * @param int    $id     Endpoint id.
     * @param bool   $delivered Whether a 2xx came back.
     * @param string $status_word Machine status word for the status page.
     * @return void
     */
    public static function record_delivery( int $id, bool $delivered, string $status_word ): void {
        $rows  = self::raw();
        $index = self::index_of( $rows, $id );
        if ( -1 === $index ) {
            return;
        }

        $row                     = $rows[ $index ];
        $row['last_delivery_at'] = (string) current_time( 'mysql', true );
        $row['last_status']      = $status_word;

        if ( $delivered ) {
            $row['consecutive_failures'] = 0;
            $row['circuit_open_since']   = 0;
        } else {
            $failures                    = (int) ( $row['consecutive_failures'] ?? 0 ) + 1;
            $row['consecutive_failures'] = $failures;

            if ( $failures >= self::CIRCUIT_THRESHOLD ) {
                $row['circuit_open_since'] = (string) current_time( 'mysql', true );
            }
        }

        $rows[ $index ] = $row;
        self::save( $rows );
    }

    /**
     * The owner's explicit circuit reset (ADR-0016 D4: an open
     * circuit never closes on its own).
     *
     * @param int $id Endpoint id.
     * @return bool
     */
    public static function reset_circuit( int $id ): bool {
        $rows  = self::raw();
        $index = self::index_of( $rows, $id );
        if ( -1 === $index ) {
            return false;
        }

        $rows[ $index ]['circuit_open_since']   = 0;
        $rows[ $index ]['consecutive_failures'] = 0;
        self::save( $rows );

        return true;
    }

    /**
     * URL gate (ADR-0016 D1): https-only storage — a signature on
     * the wire is meaningless over http, and the host must be
     * non-empty. Deliberately structural: core's
     * wp_http_validate_url() resolves the host in DNS and refuses
     * private ranges, so borrowing it here made internal and
     * not-yet-resolvable receivers unstoreable; the wire call
     * keeps that vetting at delivery time, where its failures
     * land in the retry and circuit ladder instead of the gate.
     *
     * @param string $url Candidate URL.
     * @return bool
     */
    public static function valid_url( string $url ): bool {
        if ( '' === $url || strlen( $url ) > 2048 ) {
            return false;
        }

        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return false;
        }

        return 'https' === (string) $parts['scheme'];
    }

    /**
     * Intersects the requested event list with the closed vocabulary;
     * duplicates collapse, unknown names drop, an empty result is a
     * refusal the caller interprets.
     *
     * @param array<int, string> $events Requested names.
     * @return array<int, string>
     */
    private static function valid_events( array $events ): array {
        // The page arm sanitizes before this gate; the intersection
        // with the closed vocabulary is the real filter.
        $out = array();
        foreach ( $events as $event ) {
            if ( in_array( $event, self::EVENTS, true ) ) {
                $out[ $event ] = $event;
            }
        }

        return array_values( $out );
    }

    /**
     * One stored row in its full shape, carrying the previous health
     * word forward on updates so a URL edit never resets the
     * delivery history.
     *
     * @param int                       $id      Endpoint id.
     * @param string                    $url     Delivery URL.
     * @param string                    $secret  Encryption envelope.
     * @param array<int, string>        $events  Event names.
     * @param bool                      $active  Active flag.
     * @param array<string, mixed>|null $previous Row being edited, null on add.
     * @return array<string, mixed>
     */
    private static function shape( int $id, string $url, string $secret, array $events, bool $active, ?array $previous = null ): array {
        return array(
            'id'                   => $id,
            'url'                  => $url,
            'secret'               => $secret,
            'events'               => array_values( $events ),
            'active'               => $active ? 1 : 0,
            'last_delivery_at'     => null === $previous ? '' : (string) ( $previous['last_delivery_at'] ?? '' ),
            'last_status'          => null === $previous ? '' : (string) ( $previous['last_status'] ?? '' ),
            'consecutive_failures' => null === $previous ? 0 : (int) ( $previous['consecutive_failures'] ?? 0 ),
            'circuit_open_since'   => null === $previous ? 0 : (int) ( $previous['circuit_open_since'] ?? 0 ),
        );
    }

    /**
     * Raw option rows without the re-index all() applies; internal
     * mutations must keep the array's key order intact for save().
     *
     * @return array<int, array<string, mixed>>
     */
    private static function raw(): array {
        $rows = get_option( self::OPTION, array() );

        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Row index by endpoint id, or -1.
     *
     * @param array<int, array<string, mixed>> $rows Rows.
     * @param int                              $id   Endpoint id.
     * @return int
     */
    private static function index_of( array $rows, int $id ): int {
        foreach ( $rows as $index => $row ) {
            if ( $id === (int) ( $row['id'] ?? 0 ) ) {
                return (int) $index;
            }
        }

        return -1;
    }

    /**
     * Persists the rows, non-autoloaded.
     *
     * @param array<int, array<string, mixed>> $rows Rows.
     * @return void
     */
    private static function save( array $rows ): void {
        update_option( self::OPTION, array_values( $rows ), false );
    }
}
