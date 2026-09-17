<?php
/**
 * Third-party adapter registry (docs/19 V32, iron rule 6): other
 * plugins register their adapter classes on the
 * 'gr_registered_adapters' filter; this class is the defensive
 * mounting path between them and the site. A class that does not
 * exist, does not implement the contract, or throws anywhere in its
 * probe or mount never breaks the request — the failure rides the
 * existing gr_adapter_error bus and the posture stays visible.
 *
 * Built-in bridges keep their own mount in Gr_Plugin: they carry
 * constructor dependencies and are versioned with the plugin. This
 * registry exists so third parties get the same lifecycle (probe
 * first, mount only when the target plugin actually runs) without
 * the plugin ever loading their code by hand.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Filter seam for third-party ecosystem adapters.
 */
final class Gr_Adapter_Registry {

    /** The filter third-party plugins return their class list on. */
    private const FILTER = 'gr_registered_adapters';

    /**
     * Mount posture this request: adapter id => mounted, available,
     * error, class. Only third-party entries appear here; built-in
     * posture stays the ecosystem detector's vocabulary.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $posture = array();

    /**
     * Ids whose class already mounted (or failed) this request, so a
     * repeated mount call cannot double-register hooks.
     *
     * @var array<string, bool>
     */
    private static array $settled = array();

    /**
     * Mounts every third-party adapter the filter offers. Safe to
     * call on every request: the gate is cheap and the filter
     * returns nothing until a plugin registers something.
     *
     * @return void
     */
    public static function register(): void {
        $classes = apply_filters( self::FILTER, array() );
        if ( ! is_array( $classes ) ) {
            return;
        }

        foreach ( $classes as $class ) {
            self::mount_one( $class );
        }
    }

    /**
     * The third-party mount posture for the ecosystem page.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function posture(): array {
        return self::$posture;
    }

    /**
     * Mounts one candidate class defensively.
     *
     * @param mixed $candidate Candidate class string.
     * @return void
     */
    private static function mount_one( $candidate ): void {
        if ( ! is_string( $candidate ) || '' === $candidate ) {
            return;
        }

        try {
            if ( ! class_exists( $candidate ) ) {
                return;
            }

            $implements = class_implements( $candidate );
            if ( ! is_array( $implements ) || ! in_array( Adapter_Interface::class, $implements, true ) ) {
                return;
            }

            $id = self::settle_id( $candidate );
            if ( '' === $id ) {
                return;
            }

            if ( ! $candidate::is_available() ) {
                return;
            }

            self::$posture[ $id ]['available'] = true;
            ( new $candidate() )->register_hooks();
            self::$posture[ $id ]['mounted'] = true;
        } catch ( \Throwable $error ) {
            self::fail( $candidate, $error );
        }
    }

    /**
     * Reserves the posture row for one adapter id; '' when the id is
     * blank or already settled this request.
     *
     * @param string $candidate Candidate class.
     * @return string
     */
    private static function settle_id( string $candidate ): string {
        $id = $candidate::get_id();
        $id = is_string( $id ) ? trim( $id ) : '';
        if ( '' === $id || isset( self::$settled[ $id ] ) ) {
            return '';
        }

        self::$settled[ $id ] = true;
        self::$posture[ $id ] = array(
            'class'     => $candidate,
            'mounted'   => false,
            'available' => false,
            'error'     => '',
        );

        return $id;
    }

    /**
     * Reports a throw to the posture row (when one exists) and the
     * error bus, never rethrowing into the site.
     *
     * @param string     $candidate Candidate class.
     * @param \Throwable $error What broke.
     * @return void
     */
    private static function fail( string $candidate, \Throwable $error ): void {
        foreach ( self::$posture as $id => $entry ) {
            if ( $candidate === $entry['class'] && '' === (string) $entry['error'] ) {
                self::$posture[ $id ]['error'] = $error->getMessage();
                do_action( 'gr_adapter_error', (string) $id, $error );

                return;
            }
        }

        do_action( 'gr_adapter_error', $candidate, $error );
    }

    /**
     * Test seam: clears the request posture and settled ids.
     *
     * @return void
     */
    public static function reset(): void {
        self::$posture = array();
        self::$settled = array();
    }
}
