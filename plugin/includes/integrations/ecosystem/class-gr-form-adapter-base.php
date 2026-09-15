<?php
/**
 * Shared machinery for form-plugin bridges (docs/13 C11): every form
 * adapter binds one conversion on a successful submission, with the
 * main hook doing the work and the fallback hook standing by as a
 * drift sentinel. Whichever order the two hooks fire in, a submission
 * processed by the fallback alone is a drift event: it is still bound
 * (fail-open, never silent loss) and reported on gr_bridge_drift for
 * the status page. Since v1.1 the same pass captures the lead
 * (ADR-0013 D2): a consented submission carrying an email upserts the
 * CRM contact row and tags its source bridge.
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
use GreenPNG\Storage\Gr_Contact_Repository;

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
     * Lead capture store (ADR-0013 D2); the same consent gate that
     * guards the conversion binding guards the contact upsert.
     *
     * @var Gr_Contact_Repository
     */
    private Gr_Contact_Repository $contacts;

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
     * Wires the collaborators. The contacts store is optional so
     * existing constructions keep working; lead capture rides on a
     * default instance when none is injected.
     *
     * @param Gr_Identity                $identity     Identity service.
     * @param Gr_Attribution_Service     $attribution  Binding service.
     * @param Gr_Contact_Repository|null $contacts     Lead store, null for default.
     */
    public function __construct( Gr_Identity $identity, Gr_Attribution_Service $attribution, ?Gr_Contact_Repository $contacts = null ) {
        $this->identity    = $identity;
        $this->attribution = $attribution;
        $this->contacts    = null === $contacts ? new Gr_Contact_Repository() : $contacts;
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
     * @return array{source: int, payload: array<string, mixed>}|null
     */
    abstract protected function translate_main( array $args ): ?array;

    /**
     * Normalizes the fallback hook's positional args the same way.
     *
     * @param array<int, mixed> $args Positional hook arguments.
     * @return array{source: int, payload: array<string, mixed>}|null
     */
    abstract protected function translate_fallback( array $args ): ?array;

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
     * Binds one submission as a conversion and captures the lead.
     * Idempotent end to end: the gr_conversions UNIQUE source key
     * collapses replayed hooks and the main-plus-fallback double fire
     * into one row (docs/05 §3.3), and the contact upsert collapses
     * repeated emails onto one row the same way. Lead capture
     * (ADR-0013 D2) sits inside the very same consent gate — a form
     * submission is not implicit consent (docs/15 §1) — and stores
     * only what the schema owns: email in its two tracks, names, the
     * cookie-track visitor binding, and a source tag. The extractor
     * may surface a phone; no column accepts it, so none is written.
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

        if ( null !== $semantics['email'] ) {
            $contact_id = $this->contacts->capture(
                (string) $semantics['email'],
                null === $semantics['first_name'] ? '' : (string) $semantics['first_name'],
                null === $semantics['last_name'] ? '' : (string) $semantics['last_name'],
                $this->identity->visitor_id()
            );

            if ( $contact_id > 0 ) {
                $this->contacts->attach_tag(
                    $contact_id,
                    'sys:form:' . static::get_id(),
                    sprintf(
                        /* translators: %s: form bridge identifier, e.g. fluentform. */
                        __( 'Form submission: %s', 'greenpng' ),
                        static::get_id()
                    ),
                    true
                );

                // The lead moment rides the event bus (ADR-0016 D1's
                // closed vocabulary has no 'lead' without it): the
                // contact id and the source word, never the email —
                // PII stays in the contact row, and any outbound
                // copy carries nothing it did not already show on
                // the reports.
                gr_dispatch_event(
                    'lead',
                    array(
                        'event_group' => 'crm',
                        'visitor_id'  => $this->identity->visitor_id(),
                        'session_id'  => $this->identity->session_id(),
                        'contact_id'  => $contact_id,
                        'source_type' => 'form',
                        'source_id'   => static::get_id(),
                    )
                );
            }
        }

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
