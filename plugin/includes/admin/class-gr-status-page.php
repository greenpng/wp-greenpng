<?php
/**
 * Status & Diagnostics page (docs/06 §1 Tools section, docs/09 §4):
 * the environment snapshot, queue posture with the low-traffic cron
 * guidance, adapter mounts, and table capacity — plus the sanitized
 * diagnostics export button. Read-only apart from the export, whose
 * payload is whitelisted field by field (Gr_Diagnostics).
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Diagnostics;
use GreenPNG\Core\Gr_Queue;
use GreenPNG\Integrations\Gr_Ecosystem_Detector;
use GreenPNG\Storage\Gr_Audit_Repository;
use GreenPNG\Storage\Gr_Cart_Abandonment_Repository;
use GreenPNG\Storage\Gr_Table_Stats;

/**
 * Owner-facing status surface.
 */
final class Gr_Status_Page {

    /** Menu slug under the top-level menu's Tools section. */
    public const SLUG = 'greenpng-status';

    /** Nonce action for the export write. */
    public const NONCE_ACTION = 'gr_status_export';

    /** Nonce field name. */
    public const NONCE_FIELD = '_gr_status_nonce';

    /** Export POST marker. */
    public const ACTION_EXPORT = 'export';

    /**
     * Hook registration: the export is handled on admin_init so the
     * download headers are legal.
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
    }

    /**
     * The double gate for the one write this page has.
     *
     * @return bool
     */
    public static function may_write(): bool {
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        return (bool) check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );
    }

    /**
     * Dispatches the export: audit trail first, then the download
     * stream ends the request.
     *
     * @return void
     */
    public static function handle_actions(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce gate below is the real check; this line only routes the request.
        $action = isset( $_POST['gr_status_action'] ) ? sanitize_key( (string) wp_unslash( $_POST['gr_status_action'] ) ) : '';
        if ( self::ACTION_EXPORT !== $action ) {
            return;
        }

        if ( ! self::may_write() ) {
            return;
        }

        ( new Gr_Audit_Repository() )->log(
            'export',
            'diagnostics',
            'json',
            array(),
            array( 'fields' => 'plugin,environment,queue,adapters,tables' ),
            get_current_user_id()
        );
        Gr_Table_Stats::flush();

        self::stream( Gr_Diagnostics::export() );
    }

    /**
     * Sends the snapshot as a download. The exit is the point: the
     * response body is the file and nothing else.
     *
     * @param array<string, mixed> $data The export payload.
     * @return void
     */
    private static function stream( array $data ): void {
        $json = (string) wp_json_encode( $data );

        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="gr-diagnostics-' . gmdate( 'Ymd-His' ) . '.json"' );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JSON download body, not markup.
        echo $json;
        exit;
    }

    /**
     * Page output.
     *
     * @return void
     */
    public static function render(): void {
        $diagnostics = Gr_Diagnostics::export();
        $env         = $diagnostics['environment'];
        $queue       = $diagnostics['queue'];
        $tables      = $diagnostics['tables'];
        $adapters    = $diagnostics['adapters'];
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Status & Diagnostics', 'greenpng' ); ?></h1>
            <hr class="wp-header-end" />

            <h2><?php echo esc_html__( 'Environment', 'greenpng' ); ?></h2>
            <table class="widefat striped">
                <tbody>
                    <tr><th><?php echo esc_html__( 'Plugin version', 'greenpng' ); ?></th><td><?php echo esc_html( (string) $diagnostics['plugin']['version'] ); ?></td></tr>
                    <tr><th><?php echo esc_html__( 'PHP', 'greenpng' ); ?></th><td><?php echo esc_html( (string) $env['php'] ); ?></td></tr>
                    <tr><th><?php echo esc_html__( 'WordPress', 'greenpng' ); ?></th><td><?php echo esc_html( (string) $env['wp'] ); ?></td></tr>
                    <tr><th><?php echo esc_html__( 'Database', 'greenpng' ); ?></th><td><?php echo esc_html( (string) $env['db'] ); ?></td></tr>
                    <tr><th><?php echo esc_html__( 'Multisite', 'greenpng' ); ?></th><td><?php echo $env['multisite'] ? esc_html__( 'Yes', 'greenpng' ) : esc_html__( 'No', 'greenpng' ); ?></td></tr>
                </tbody>
            </table>

            <h2><?php echo esc_html__( 'Queue', 'greenpng' ); ?></h2>
            <p>
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %s: queue backend name. */
                        __( 'Backend: %s', 'greenpng' ),
                        'action-scheduler' === $queue['backend'] ? 'Action Scheduler' : 'WP-Cron'
                    )
                );
                ?>
                <?php if ( (int) $queue['next_daily'] > 0 ) : ?>
                    —
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %s: UTC timestamp. */
                            __( 'next daily pass at %s (server UTC).', 'greenpng' ),
                            gmdate( 'Y-m-d H:i', (int) $queue['next_daily'] )
                        )
                    );
                    ?>
                <?php else : ?>
                    — <?php echo esc_html__( 'no daily pass scheduled yet.', 'greenpng' ); ?>
                <?php endif; ?>
            </p>
            <?php if ( 'wp-cron' === $queue['backend'] ) : ?>
                <div class="notice notice-info inline"><p>
                    <?php echo esc_html__( 'On low-traffic sites WP-Cron only fires when a visitor arrives. Point a real system cron at the WP-CLI entry to keep maintenance on a fixed clock:', 'greenpng' ); ?>
                    <code>wp greenpng maintenance</code>
                </p></div>
            <?php endif; ?>

            <h2><?php echo esc_html__( 'Cart recovery', 'greenpng' ); ?></h2>
            <?php $failed = ( new Gr_Cart_Abandonment_Repository() )->failed_summary(); ?>
            <?php if ( (int) $failed['count'] > 0 ) : ?>
                <div class="notice notice-warning inline"><p>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: 1: number of failed mails, 2: timestamp of the newest attempt. */
                            __( '%1$s recovery mail(s) could not be delivered (newest attempt: %2$s). Check this site\'s mail delivery; the rows stay marked failed until retention removes them.', 'greenpng' ),
                            (int) $failed['count'],
                            '' !== (string) $failed['last'] ? (string) $failed['last'] : '—'
                        )
                    );
                    ?>
                </p></div>
            <?php else : ?>
                <p><?php echo esc_html__( 'No failed recovery sends on record.', 'greenpng' ); ?></p>
            <?php endif; ?>

            <h2><?php echo esc_html__( 'Ecosystem adapters', 'greenpng' ); ?></h2>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php echo esc_html__( 'Bridge', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Status', 'greenpng' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $adapters as $bridge => $adapter ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( (string) $bridge ); ?></code></td>
                            <td>
                                <?php
                                echo $adapter['mounted']
                                    ? esc_html__( 'Mounted', 'greenpng' )
                                    : esc_html__( 'Not installed', 'greenpng' );
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2><?php echo esc_html__( 'Table capacity', 'greenpng' ); ?></h2>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php echo esc_html__( 'Table', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Rows (estimate)', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Data', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Index', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Total', 'greenpng' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $tables as $key => $table ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( 'gr_' . (string) $key ); ?></code></td>
                            <td><?php echo esc_html( number_format( (float) $table['rows'] ) ); ?></td>
                            <td><?php echo esc_html( self::bytes( (int) $table['data_bytes'] ) ); ?></td>
                            <td><?php echo esc_html( self::bytes( (int) $table['index_bytes'] ) ); ?></td>
                            <td><?php echo esc_html( self::bytes( (int) $table['total_bytes'] ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2><?php echo esc_html__( 'Diagnostics export', 'greenpng' ); ?></h2>
            <p><?php echo esc_html__( 'A JSON snapshot of everything on this page: versions, queue posture, adapter mounts, table capacity. It carries no credentials, no addresses, and no content data.', 'greenpng' ); ?></p>
            <form method="post">
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                <input type="hidden" name="gr_status_action" value="<?php echo esc_attr( self::ACTION_EXPORT ); ?>" />
                <?php submit_button( __( 'Download diagnostics', 'greenpng' ), 'secondary' ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Byte formatting without a core dependency.
     *
     * @param int $bytes Byte count.
     * @return string
     */
    private static function bytes( int $bytes ): string {
        if ( $bytes >= 1048576 ) {
            return number_format( $bytes / 1048576, 1 ) . ' MB';
        }
        if ( $bytes >= 1024 ) {
            return number_format( $bytes / 1024, 1 ) . ' KB';
        }
        return $bytes . ' B';
    }
}
