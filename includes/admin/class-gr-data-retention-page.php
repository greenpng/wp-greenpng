<?php
/**
 * Data Retention page (docs/06 §1 Tools section, docs/05 §5): the
 * owner-facing surface for both retention rails and the manual-only
 * table rebuild. Every write passes the double gate; the cron rider
 * reads the same settings, so what this page saves is what tonight's
 * pass trims — but never optimizes, that button is human-only.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Settings;
use GreenPNG\Storage\Gr_Audit_Repository;
use GreenPNG\Storage\Gr_Retention;

/**
 * Retention settings and manual maintenance.
 */
final class Gr_Data_Retention_Page {

    /** Menu slug under the top-level menu's Tools section. */
    public const SLUG = 'greenpng-retention';

    /** Nonce action for every write on this page. */
    public const NONCE_ACTION = 'gr_data_retention';

    /** Nonce field name. */
    public const NONCE_FIELD = '_gr_retention_nonce';

    /** Save-settings POST marker. */
    public const ACTION_SAVE = 'save';

    /** Manual optimize POST marker. */
    public const ACTION_OPTIMIZE = 'optimize';

    /** Ceiling for the days rail input. */
    public const MAX_DAYS = 3650;

    /** Ceiling for the rows rail input. */
    public const MAX_ROWS = 100000000;

    /**
     * Hook registration: writes are handled on admin_init so the
     * post-write redirect is legal.
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
    }

    /**
     * The double gate: capability AND nonce.
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
     * Dispatches the two POST actions, both post-redirect-get.
     *
     * @return void
     */
    public static function handle_actions(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce gate below is the real check; this line only routes the request.
        $action = isset( $_POST['gr_retention_action'] ) ? sanitize_key( (string) wp_unslash( $_POST['gr_retention_action'] ) ) : '';
        if ( '' === $action ) {
            return;
        }

        if ( ! self::may_write() ) {
            return;
        }

        $flags = array( 'page' => self::SLUG );
        if ( self::ACTION_SAVE === $action ) {
            self::save_settings();
            $flags['gr_saved'] = 1;
        } elseif ( self::ACTION_OPTIMIZE === $action ) {
            self::optimize_tables();
        }

        wp_safe_redirect(
            add_query_arg( $flags, admin_url( 'admin.php' ) )
        );
    }

    /**
     * Persists both rails. Only prunable table keys are read; every
     * value is absint-clamped, so storage only ever sees integers
     * inside the documented ceilings.
     *
     * @return void
     */
    private static function save_settings(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); keys are whitelisted below and values absint-cast.
        $days_in = isset( $_POST['ret_days'] ) && is_array( $_POST['ret_days'] ) ? wp_unslash( $_POST['ret_days'] ) : array();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); keys are whitelisted below and values absint-cast.
        $rows_in = isset( $_POST['ret_rows'] ) && is_array( $_POST['ret_rows'] ) ? wp_unslash( $_POST['ret_rows'] ) : array();

        $settings = new Gr_Settings();
        $old_days = (array) $settings->get( 'retention_days', array() );
        $old_rows = (array) $settings->get( 'retention_rows', array() );

        $days = $old_days;
        $rows = $old_rows;
        foreach ( Gr_Retention::prunable_tables() as $key ) {
            if ( isset( $days_in[ $key ] ) ) {
                $days[ $key ] = max( 0, min( self::MAX_DAYS, absint( (int) $days_in[ $key ] ) ) );
            }
            if ( isset( $rows_in[ $key ] ) ) {
                $rows[ $key ] = max( 0, min( self::MAX_ROWS, absint( (int) $rows_in[ $key ] ) ) );
            }
        }

        $settings->set( 'retention_days', $days );
        $settings->set( 'retention_rows', $rows );

        ( new Gr_Audit_Repository() )->log(
            'save',
            'retention',
            'rails',
            array(
                'retention_days' => $old_days,
                'retention_rows' => $old_rows,
            ),
            array(
                'retention_days' => $days,
                'retention_rows' => $rows,
            ),
            get_current_user_id()
        );
    }

    /**
     * Manual OPTIMIZE over the checked tables, each rebuild recorded
     * in the audit trail.
     *
     * @return void
     */
    private static function optimize_tables(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); keys are whitelist-checked and sanitized below.
        $tables = isset( $_POST['optimize_tables'] ) && is_array( $_POST['optimize_tables'] ) ? wp_unslash( $_POST['optimize_tables'] ) : array();
        $audit  = new Gr_Audit_Repository();
        $user   = get_current_user_id();

        foreach ( $tables as $key ) {
            $key = sanitize_key( (string) $key );
            if ( '' === $key || ! in_array( $key, Gr_Retention::prunable_tables(), true ) ) {
                continue;
            }

            if ( Gr_Retention::optimize( $key ) ) {
                $audit->log( 'optimize', 'table', $key, array(), array( 'rebuilt' => 1 ), $user );
            }
        }
    }

    /**
     * Page output: both rails as one settings table, the manual
     * rebuild block, and the standing rule about what cron never does.
     *
     * @return void
     */
    public static function render(): void {
        $settings = new Gr_Settings();
        $days     = (array) $settings->get( 'retention_days', array() );
        $rows     = (array) $settings->get( 'retention_rows', array() );
        $counts   = Gr_Retention::counts();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only PRG flag on an owner-gated screen.
        $saved = isset( $_GET['gr_saved'] ) ? 1 : 0;
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Data Retention', 'greenpng' ); ?></h1>
            <hr class="wp-header-end" />

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Retention settings saved. The nightly pass trims from here on.', 'greenpng' ); ?></p></div>
            <?php endif; ?>

            <p><?php echo esc_html__( 'Two independent rails, whichever fires first: an age in days, and a row ceiling. Zero disables a rail. Reports read the daily summary table, so trimming never eats a chart.', 'greenpng' ); ?></p>

            <form method="post">
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                <input type="hidden" name="gr_retention_action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>" />
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__( 'Table', 'greenpng' ); ?></th>
                            <th><?php echo esc_html__( 'Rows now', 'greenpng' ); ?></th>
                            <th><?php echo esc_html__( 'Keep days', 'greenpng' ); ?></th>
                            <th><?php echo esc_html__( 'Row ceiling', 'greenpng' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( Gr_Retention::prunable_tables() as $key ) : ?>
                            <tr>
                                <td><code><?php echo esc_html( 'gr_' . $key ); ?></code></td>
                                <td><?php echo esc_html( number_format( (float) ( $counts[ $key ] ?? 0 ) ) ); ?></td>
                                <td>
                                    <input type="number" min="0" max="<?php echo esc_attr( (string) self::MAX_DAYS ); ?>"
                                        name="ret_days[<?php echo esc_attr( $key ); ?>]"
                                        value="<?php echo esc_attr( (string) (int) ( $days[ $key ] ?? 0 ) ); ?>" class="small-text" />
                                </td>
                                <td>
                                    <input type="number" min="0" max="<?php echo esc_attr( (string) self::MAX_ROWS ); ?>"
                                        name="ret_rows[<?php echo esc_attr( $key ); ?>]"
                                        value="<?php echo esc_attr( (string) (int) ( $rows[ $key ] ?? 0 ) ); ?>" class="regular-text" />
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button( __( 'Save retention settings', 'greenpng' ) ); ?>
            </form>

            <h2><?php echo esc_html__( 'Manual table rebuild', 'greenpng' ); ?></h2>
            <p><?php echo esc_html__( 'OPTIMIZE rewrites a table and locks it while it runs. It is button-only on purpose: the nightly pass never optimizes on its own.', 'greenpng' ); ?></p>
            <form method="post">
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                <input type="hidden" name="gr_retention_action" value="<?php echo esc_attr( self::ACTION_OPTIMIZE ); ?>" />
                <?php foreach ( Gr_Retention::prunable_tables() as $key ) : ?>
                    <label>
                        <input type="checkbox" name="optimize_tables[]" value="<?php echo esc_attr( $key ); ?>" />
                        <code><?php echo esc_html( 'gr_' . $key ); ?></code>
                    </label>
                    <br />
                <?php endforeach; ?>
                <?php submit_button( __( 'Optimize selected tables', 'greenpng' ), 'delete' ); ?>
            </form>
        </div>
        <?php
    }
}
