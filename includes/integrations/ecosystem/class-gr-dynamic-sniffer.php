<?php
/**
 * Dynamic hook sniffer (docs/03 §9, docs/12 v1.2): mounts the owner
 * configured hook rules into the admin context only — a front-end or
 * REST request never loads a rule, never registers a listener, and
 * spends nothing. A hit lands in two bounded faces: one audit row and
 * one event on the greenpng bus (skipped for audit-only rules), with
 * a per-request ceiling so a hot admin hook cannot flood either.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Integrations\Ecosystem;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Storage\Gr_Audit_Repository;
use GreenPNG\Storage\Gr_Dynamic_Event_Repository;

/**
 * Mounts active rules as admin-request listeners.
 */
final class Gr_Dynamic_Sniffer {

    /** Hits either face may carry per admin request. */
    public const HIT_CEILING = 50;

    /** Audit action for one observed hit. */
    public const AUDIT_HIT = 'dynamic_event_hit';

    /** Audit action for the ceiling note. */
    public const AUDIT_OVERFLOW = 'dynamic_event_overflow';

    /**
     * Hits this request has already produced.
     *
     * @var int
     */
    private static int $hits = 0;

    /**
     * Whether the overflow row was already written.
     *
     * @var bool
     */
    private static bool $overflowed = false;

    /**
     * Active rules as loaded at mount time; the request keeps one
     * snapshot so a hit never re-reads the store.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private static ?array $rules = null;

    /**
     * Forced CLI-arm verdict; null means the real constant check.
     * Test-only seam: the unit environment stubs WP_CLI on, so the
     * off-arm cannot be exercised through the constant alone.
     *
     * @var bool|null
     */
    private static ?bool $cli_arm = null;

    /**
     * Hook registration: the mount gate decides everything. Admin
     * requests (and WP-CLI, a back-end tool by definition) load the
     * active rules and register one listener per hook; every other
     * request registers nothing and reads nothing.
     *
     * @return void
     */
    public static function register_hooks(): void {
        if ( ! self::mounts_here() ) {
            return;
        }

        $rules = Gr_Dynamic_Event_Repository::active();
        if ( array() === $rules ) {
            return;
        }

        self::$rules = $rules;

        $hooks = array();
        foreach ( $rules as $rule ) {
            $hook = (string) $rule['hook_name'];
            if ( '' === $hook || isset( $hooks[ $hook ] ) ) {
                continue;
            }
            $hooks[ $hook ] = true;
            add_action(
                $hook,
                static function ( ...$hook_args ) use ( $hook ): void {
                    self::on_hook( $hook, $hook_args );
                },
                10,
                8
            );
        }
    }

    /**
     * The mount gate: admin requests and CLI. Front-end, REST, and
     * cron requests mount nothing — the zero-front-end rule is this
     * return, not a convention somewhere else.
     *
     * @return bool
     */
    public static function mounts_here(): bool {
        if ( null === self::$cli_arm ) {
            // WP-CLI announces itself by defining the constant; the
            // marker alone is the verdict, the value carries nothing.
            self::$cli_arm = (bool) defined( 'WP_CLI' );
        }

        return is_admin() || self::$cli_arm;
    }

    /**
     * Test seam: forces the CLI-arm verdict for this process.
     *
     * @param bool|null $on Verdict, or null to re-enable detection.
     * @return void
     */
    public static function cli_arm( ?bool $on ): void {
        self::$cli_arm = $on;
    }

    /**
     * One hook occurrence: every active rule on that hook fires once.
     *
     * @param string            $hook      Hook name.
     * @param array<int, mixed> $hook_args Hook arguments.
     * @return void
     */
    public static function on_hook( string $hook, array $hook_args ): void {
        if ( null === self::$rules ) {
            self::$rules = Gr_Dynamic_Event_Repository::active();
        }

        foreach ( self::$rules as $rule ) {
            if ( $hook !== (string) $rule['hook_name'] ) {
                continue;
            }
            self::hit( $rule, $hook, $hook_args );
        }
    }

    /**
     * One rule hit: resolve the param map, write the audit row,
     * bridge the event onto the bus.
     *
     * @param array<string,mixed> $rule      Rule row.
     * @param string              $hook      Hook name.
     * @param array<int, mixed>   $hook_args Hook arguments.
     * @return void
     */
    private static function hit( array $rule, string $hook, array $hook_args ): void {
        ++self::$hits;

        if ( self::$hits > self::HIT_CEILING ) {
            if ( ! self::$overflowed ) {
                self::$overflowed = true;
                self::audit(
                    self::AUDIT_OVERFLOW,
                    $hook,
                    array( 'ceiling' => self::HIT_CEILING )
                );
            }

            return;
        }

        $params = array();
        foreach ( (array) $rule['param_map'] as $key => $expression ) {
            $value = Gr_Param_Resolver::resolve( (string) $expression, $hook_args );
            if ( null !== $value ) {
                $params[ (string) $key ] = $value;
            }
        }

        self::audit(
            self::AUDIT_HIT,
            $hook,
            array(
                'event'  => (string) $rule['event_name'],
                'rule'   => (int) $rule['id'],
                'params' => self::view_safe( $params ),
            )
        );

        $event = trim( (string) $rule['event_name'] );
        if ( '' === $event ) {
            return;
        }

        $payload = $params;
        // Context keys the event DTO lifts into columns: the group
        // marks the bridge, the id carries the hit. The id is random
        // per hit on purpose — recurring hooks would collapse under
        // one deterministic id wherever the downstream dedups by it.
        $payload['event_group'] = 'dynamic';
        $payload['event_id']    = gr_generate_event_id( 'hook' );

        try {
            gr_dispatch_event( $event, $payload );
        } catch ( \Throwable $error ) {
            // A broken event name or payload shape must never break
            // the merchant's own hook; report and carry on.
            do_action( 'gr_adapter_error', 'dynamic_event', $error );
        }
    }

    /**
     * Truncates values for the audit face; complex values collapse
     * to a marker so diff_json stays a bounded, readable snapshot.
     *
     * @param array<string, mixed> $params Resolved params.
     * @return array<string, string>
     */
    private static function view_safe( array $params ): array {
        $out = array();
        foreach ( $params as $key => $value ) {
            if ( is_scalar( $value ) ) {
                $out[ (string) $key ] = mb_substr( (string) $value, 0, 120 );
                continue;
            }
            $out[ (string) $key ] = '[complex]';
        }

        return $out;
    }

    /**
     * One audit row, object id clamped to the column width.
     *
     * @param string              $action Audit action word.
     * @param string              $hook   Hook name.
     * @param array<string,mixed> $after  Observed state.
     * @return void
     */
    private static function audit( string $action, string $hook, array $after ): void {
        $audit = new Gr_Audit_Repository();
        $audit->log( $action, 'hook', substr( $hook, 0, 64 ), array(), $after, get_current_user_id() );
    }

    /**
     * Test seam: resets the per-request counters.
     *
     * @return void
     */
    public static function reset(): void {
        self::$hits       = 0;
        self::$overflowed = false;
        self::$rules      = null;
        self::$cli_arm    = null;
    }
}
