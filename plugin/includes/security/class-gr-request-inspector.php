<?php
/**
 * Request inspector skeleton (docs/13 W1, docs/02 §2.7): the single
 * front-door frame the Phase 3 detectors plug into. It builds one
 * request context, runs every registered check in per-check Throwable
 * isolation, and hands findings to the gr_security_findings hook —
 * the surge-fold logger (W6) subscribes there. The whole walk itself
 * is guarded: an inspector failure is silently skipped, never a
 * front-end failure. Default posture is record-only (docs/10 §4).
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Request;

/**
 * The init@10 request inspection frame.
 */
final class Gr_Request_Inspector {

    /** Filter key the Phase 3 detectors register their checks under. */
    public const CHECKS_FILTER = 'gr_inspection_checks';

    /** Fired once per request with the collected findings. */
    public const FINDINGS_HOOK = 'gr_security_findings';

    /** Fired when a check or the frame itself throws. */
    public const ERROR_HOOK = 'gr_inspector_error';

    /**
     * Hook registration: the frame runs at init@10, before the theme
     * renders, on every non-admin request.
     *
     * @return void
     */
    public function register_hooks(): void {
        add_action( 'init', array( $this, 'run' ), 10, 0 );
    }

    /**
     * The init callback. Admin panels are out of scope (their
     * protections live in later W tasks); the master gate being down
     * means zero work; any Throwable is reported and swallowed.
     *
     * @return void
     */
    public function run(): void {
        if ( is_admin() ) {
            return;
        }

        if ( ! Gr_Security_Gate::active() ) {
            return;
        }

        try {
            $this->inspect();
        } catch ( \Throwable $error ) {
            do_action( self::ERROR_HOOK, 'inspector', $error );
        }
    }

    /**
     * Builds the shared request context and runs every registered
     * check. A check throwing never stops the others (docs/02 §2.7:
     * per-unit isolation); a check returns one finding or a list of
     * findings, and everything is normalized here so no detector can
     * smuggle arbitrary shapes downstream.
     *
     * @return array<int, array<string, string>> rule_id + reason rows.
     */
    public function inspect(): array {
        $context = array(
            'ip'     => Gr_Ip_Resolver::resolve(),
            'ua'     => Gr_Request::user_agent(),
            'method' => isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '',
            'path'   => Gr_Request::path(),
        );

        $checks = apply_filters( self::CHECKS_FILTER, array() );
        if ( ! is_array( $checks ) ) {
            $checks = array();
        }

        $findings = array();
        foreach ( $checks as $id => $check ) {
            if ( ! is_callable( $check ) ) {
                continue;
            }

            try {
                $finding = $check( $context );
            } catch ( \Throwable $error ) {
                do_action( self::ERROR_HOOK, (string) $id, $error );
                continue;
            }

            foreach ( $this->normalize_many( $finding ) as $normalized ) {
                $findings[] = $normalized;
            }
        }

        if ( array() !== $findings ) {
            do_action( self::FINDINGS_HOOK, $findings );
        }

        return $findings;
    }

    /**
     * A check may settle for one finding or return a numeric-keyed
     * list of them (a payload scan can hit several parameters at
     * once); anything else is treated as no finding. The two shapes
     * are told apart by the rule_id key a single finding always has.
     *
     * @param mixed $finding Check return value.
     * @return array<int, array<string, string>> Zero or more rows.
     */
    private function normalize_many( $finding ): array {
        if ( ! is_array( $finding ) ) {
            return array();
        }

        if ( isset( $finding['rule_id'] ) ) {
            $one = $this->normalize( $finding );

            return null === $one ? array() : array( $one );
        }

        $rows = array();
        foreach ( $finding as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $one = $this->normalize( $item );
            if ( null !== $one ) {
                $rows[] = $one;
            }
        }

        return $rows;
    }

    /**
     * One finding down to the two fields the log row accepts; anything
     * else a check returned is treated as no finding.
     *
     * @param mixed $finding Check return value.
     * @return array<string, string>|null
     */
    private function normalize( $finding ) {
        if ( ! is_array( $finding ) || ! isset( $finding['rule_id'] ) ) {
            return null;
        }

        $rule_id = sanitize_key( (string) $finding['rule_id'] );
        if ( '' === $rule_id ) {
            return null;
        }

        $reason = isset( $finding['reason'] ) && is_scalar( $finding['reason'] )
            ? substr( sanitize_text_field( (string) $finding['reason'] ), 0, 191 )
            : '';

        return array(
            'rule_id' => substr( $rule_id, 0, 64 ),
            'reason'  => $reason,
        );
    }
}
