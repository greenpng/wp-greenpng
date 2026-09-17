<?php
/**
 * Security findings logger (docs/13 W6, docs/02 §2.7): the subscriber
 * on the W1 inspector's gr_security_findings hook. Every finding the
 * frame emits lands in gr_security_logs through the surge-fold
 * writer; because the frame only runs when security_enabled is on,
 * this subscriber costs nothing on a disabled or admin request.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Request;
use GreenPNG\Storage\Gr_Security_Log_Repository;

/**
 * Bridges the inspector frame onto the fold-log repository.
 */
final class Gr_Security_Logger {

    /**
     * Fold-log repository, wired at construction.
     *
     * @var Gr_Security_Log_Repository
     */
    private Gr_Security_Log_Repository $logs;

    /**
     * Wires the repository.
     *
     * @param Gr_Security_Log_Repository|null $logs Repository; null builds
     *                                               the default instance.
     */
    public function __construct( ?Gr_Security_Log_Repository $logs = null ) {
        $this->logs = $logs ?? new Gr_Security_Log_Repository();
    }

    /**
     * Hook registration on the findings channel.
     *
     * @return void
     */
    public function register_hooks(): void {
        add_action( Gr_Request_Inspector::FINDINGS_HOOK, array( $this, 'handle_findings' ), 10, 1 );
    }

    /**
     * Writes every finding from one request; the address, path, and
     * agent come from the live request context the frame inspected.
     * A Throwable here must not break the front end — the inspector
     * already normalized the finding shapes upstream, and a failed
     * write is reported and skipped.
     *
     * @param array<int, mixed> $findings Normalized finding rows.
     * @return void
     */
    public function handle_findings( $findings ): void {
        if ( ! is_array( $findings ) ) {
            return;
        }

        $ip   = Gr_Ip_Resolver::resolve();
        $path = Gr_Request::path();
        $ua   = Gr_Request::user_agent();

        foreach ( $findings as $finding ) {
            if ( ! is_array( $finding ) || ! isset( $finding['rule_id'] ) ) {
                continue;
            }

            try {
                $this->logs->log(
                    $ip,
                    (string) $finding['rule_id'],
                    $path,
                    $ua,
                    isset( $finding['reason'] ) && is_scalar( $finding['reason'] ) ? (string) $finding['reason'] : ''
                );
            } catch ( \Throwable $error ) {
                do_action( Gr_Request_Inspector::ERROR_HOOK, 'logger', $error );
            }
        }
    }
}
