<?php
/**
 * Shared machinery for form-plugin bridges (docs/13 C11): every form
 * adapter binds one conversion on a successful submission, with the
 * main hook doing the work and the fallback hook standing by as a
 * drift sentinel. Whichever order the two hooks fire in, a submission
 * processed by the fallback alone is a drift event: it is still bound
 * (fail-open, never silent loss) and reported on gr_bridge_drift for
 * the status page.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Integrations\Ecosystem;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Attribution\Gr_Attribution_Service;
use GreenPNG\Attribution\Gr_Identity;
use GreenPNG\Integrations\Adapter_Interface;
use GreenPNG\Integrations\Gr_Semantic_Extractor;
use GreenPNG\Privacy\Gr_Consent;

/**
 * Main-plus-fallback form bridge base.
 */
abstract class Gr_Form_Adapter_Base implements Adapter_Interface {

    /**
     * Identity service (dual-track; only the cookie track binds).
     *
     * @var Gr_Identity
     */
    private Gr_Identity $identity;

    /**
     * Attribution binding service.
     *
     * @var Gr_Attribution_Service
     */
    private Gr_Attribution_Service $attribution;

    /**
     * Submissions the fallback hook accepted, awaiting the shutdown
     * drift check: source id => payload.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $pending = array();

    /**
     * Submissions the main hook already processed.
     *
     * @var array<int, bool>
     */
    private array $served = array();

    /**
     * Whether the shutdown drift check is mounted for this request.
     *
     * @var bool
     */
    private bool $drift_watch_armed = false;

    /**
     * Synthetic source ids minted this request, so main and fallback
     * derive the same id for plugins that expose no submission id.
     *
     * @var array<string, int>
     */
    private array $synthetic = array();

    /**
     * Wires the collaborators.
     *
     * @param Gr_Identity            $identity    Identity service.
     * @param Gr_Attribution_Service $attribution Binding service.
     */
    public function __construct( Gr_Identity $identity, Gr_Attribution_Service $attribution ) {
        $this->identity    = $identity;
        $this->attribution = $attribution;
    }

    /**
     * Adapter identifier; used as the gr_conversions source_type.
     *
     * @return string
     */
    abstract public static function get_id(): string;

    /**
     * Public-surface existence probe for the target plugin.
     *
     * @return bool
     */
    abstract public static function is_available(): bool;

    /**
     * The target's main submission-completed hook.
     *
     * @return string
     */
    abstract protected function main_hook(): string;

    /**
     * The paired fallback hook (docs/02 §2.6 principle 3).
     *
     * @return string
     */
    abstract protected function fallback_hook(): string;

    /**
     * Accepted args of the main hook.
     *
     * @return int
     */
    abstract protected function main_arg_count(): int;

    /**
     * Accepted args of the fallback hook.
     *
     * @return int
     */
    abstract protected function fallback_arg_count(): int;

    /**
     * Normalizes the main hook's positional args. Returning null
     * skips the submission (shape not recognized).
     *
     * @param array<int, mixed> $args Positional hook arguments.
     * @return array{source: int, payload: array<string, mixed>>}|null
     */
    abstract protected function translate_main( array $args );

    /**
     * Normalizes the fallback hook's positional args the same way.
     *
     * @param array<int, mixed> $args Positional hook arguments.
     * @return array{source: int, payload: array<string, mixed>>}|null
     */
    abstract protected function translate_fallback( array $args );

    /**
     * Mounts the main hook plus its fallback sentinel; no hooks at all
     * when the target plugin is absent (docs/02 §2.6).
     *
     * @return void
     */
    public function register_hooks(): void {
        if ( ! static::is_available() ) {
            return;
        }

        add_action( $this->main_hook(), array( $this, 'on_main' ), 10, $this->main_arg_count() );
        add_action( $this->fallback_hook(), array( $this, 'on_fallback' ), 10, $this->fallback_arg_count() );
    }

    /**
     * Main hook entry: processes immediately and clears any pending
     * fallback record for the same submission.
     *
     * @param mixed ...$args Positional hook arguments.
     * @return void
     */
    public function on_main( ...$args ): void {
        $this->guarded(
            function () use ( $args ): void {
                $record = static::translate_main( $args );
                if ( null === $record ) {
                    return;
                }

                $this->served[ $record['source'] ] = true;
                unset( $this->pending[ $record['source'] ] );
                $this->process( $record['source'], $record['payload'] );
            }
        );
    }

    /**
     * Fallback hook entry: only parks the submission for the shutdown
     * check, so a main hook firing later still wins cleanly.
     *
     * @param mixed ...$args Positional hook arguments.
     * @return void
     */
    public function on_fallback( ...$args ): void {
        $this->guarded(
            function () use ( $args ): void {
                $record = static::translate_fallback( $args );
                if ( null === $record ) {
                    return;
                }

                $this->pending[ $record['source'] ] = $record['payload'];

                if ( ! $this->drift_watch_armed ) {
                    add_action( 'shutdown', array( $this, 'resolve_drift' ), 10, 0 );
                    $this->drift_watch_armed = true;
                }
            }
        );
    }

    /**
     * Shutdown drift check: every fallback-parked submission the main
     * hook never claimed is bound through the fallback data and
     * reported — the never-silently-broken contract (docs/02 §2.6).
     *
     * @return void
     */
    public function resolve_drift(): void {
        $this->guarded(
            function (): void {
                foreach ( $this->pending as $source => $payload ) {
                    if ( isset( $this->served[ $source ] ) ) {
                        continue;
                    }

                    $this->process( $source, $payload );
                    do_action( 'gr_bridge_drift', static::get_id(), $source );
                }

                $this->pending = array();
            }
        );
    }

    /**
     * Binds one submission as a conversion. Idempotent end to end: the
     * gr_conversions UNIQUE source key collapses replayed hooks and
     * the main-plus-fallback double fire into one row (docs/05 §3.3).
     *
     * @param int                  $source  Submission id.
     * @param array<string, mixed> $payload Source-plugin submission data.
     * @return void
     */
    private function process( int $source, array $payload ): void {
        if ( ! $this->identity->has_cookie_identity() || ! Gr_Consent::allows( 'marketing' ) ) {
            return;
        }

        $semantics = Gr_Semantic_Extractor::extract( $payload );

        $this->attribution->bind(
            $source,
            $this->identity->visitor_id(),
            null === $semantics['amount'] ? 0.0 : (float) $semantics['amount'],
            null === $semantics['currency'] ? '' : (string) $semantics['currency'],
            static::get_id()
        );
    }

    /**
     * A stable-in-request source id for plugins that expose none:
     * minted once per cache key so main and fallback agree, leaving
     * the UNIQUE key to absorb the rare cross-request coincidence.
     *
     * @param string $cache_key Per-submission cache key.
     * @return int
     */
    protected function synthetic_source_id( string $cache_key ): int {
        if ( ! isset( $this->synthetic[ $cache_key ] ) ) {
            $this->synthetic[ $cache_key ] = wp_rand( 1, 999999999 );
        }

        return $this->synthetic[ $cache_key ];
    }

    /**
     * Runs one unit of adapter work; any Throwable is reported on the
     * gr_adapter_error hook and swallowed (docs/02 §2.7).
     *
     * @param callable $work The adapter step.
     * @return void
     */
    private function guarded( callable $work ): void {
        try {
            $work();
        } catch ( \Throwable $error ) {
            do_action( 'gr_adapter_error', static::get_id(), $error );
        }
    }
}
