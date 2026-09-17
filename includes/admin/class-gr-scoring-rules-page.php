<?php
/**
 * Scoring Rules page (docs/06 Audience tree, ADR-0013 D3): the
 * site owner's points table for lead scoring. The whole ruleset is
 * one nonce-carrying form — every existing row stays editable in
 * place, one blank row at the bottom adds, one checkbox per row
 * removes — and a single Save writes it. Every write passes the
 * same double gate as every other page: manage_options capability
 * AND a valid admin referer nonce. Saving also queues the
 * full-population score recompute, because stale scores behind a
 * new ruleset are worse than slow ones.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\CRM\Gr_Scoring_Engine;
use GreenPNG\CRM\Gr_Scoring_Rules;
use GreenPNG\Core\Gr_Queue;
use GreenPNG\Storage\Gr_Audit_Repository;

/**
 * Owner-facing points table.
 */
final class Gr_Scoring_Rules_Page {

    /** Menu slug. */
    public const SLUG = 'greenpng-scoring';

    /** Nonce action name for the one write on this page. */
    public const NONCE_ACTION = 'gr_scoring_rules';

    /** Nonce field name. */
    public const NONCE_FIELD = '_gr_scoring_nonce';

    /** Save POST marker. */
    public const ACTION_SAVE = 'save';

    /**
     * The error of a refused save, kept for the fall-through render
     * so the submitted rows stay on screen for correcting. A static
     * is safe here because admin pages are one request per render.
     *
     * @var string
     */
    private static $save_error = '';

    /**
     * Test seam: forget a refused save's error.
     *
     * @return void
     */
    public static function reset_for_tests(): void {
        self::$save_error = '';
    }

    /**
     * Hook registration: the write is handled on admin_init, before
     * any page output, so the post-write redirect is legal.
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
     * Dispatches the Save write. A valid save redirects (post-
     * redirect-get); an invalid one falls through with the submitted
     * rows and the reason on screen.
     *
     * @return void
     */
    public static function handle_actions(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce gate below is the real check; this line only routes the request.
        $action = isset( $_POST['gr_scoring_action'] ) ? sanitize_key( (string) wp_unslash( $_POST['gr_scoring_action'] ) ) : '';
        if ( self::ACTION_SAVE !== $action ) {
            return;
        }

        if ( ! self::may_write() ) {
            return;
        }

        $rules = self::rules_from_post();
        $saved = Gr_Scoring_Rules::save( $rules );

        if ( is_wp_error( $saved ) ) {
            $data = $saved->get_error_data();
            $row  = ( is_array( $data ) && isset( $data['row'] ) ) ? (int) $data['row'] : 0;

            self::$save_error = ( $row > 0 )
                /* translators: %d: one-based rule row number on the form. */
                ? sprintf( __( '%1$s (row %2$d)', 'greenpng' ), $saved->get_error_message(), $row )
                : $saved->get_error_message();

            return;
        }

        $audit   = new Gr_Audit_Repository();
        $user_id = get_current_user_id();
        $audit->log(
            'save',
            'scoring_rules',
            '0',
            array(),
            array(
                'rules' => Gr_Scoring_Rules::all(),
                'count' => count( $rules ),
            ),
            $user_id
        );

        // The stored scores were produced by the previous ruleset;
        // recompute rather than serve stale points.
        Gr_Queue::enqueue( Gr_Scoring_Engine::RECOMPUTE_HOOK, array( 0 ) );

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'  => self::SLUG,
                    'saved' => '1',
                ),
                admin_url( 'admin.php' )
            )
        );
    }

    /**
     * Page output: the ruleset form with one blank row to add.
     *
     * @return void
     */
    public static function render(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag on an owner-gated screen.
        $saved = isset( $_GET['saved'] ) ? '1' === sanitize_key( (string) wp_unslash( $_GET['saved'] ) ) : false;

        $rules = ( '' !== self::$save_error )
            // The refused submission stays on screen for correcting,
            // the stored ruleset is untouched until one passes.
            ? self::rules_from_post()
            : Gr_Scoring_Rules::all();

        // One blank row is always offered, so adding never needs a
        // second form.
        $rules[] = array(
            'event_name' => '',
            'points'     => 1,
            'daily_cap'  => 3,
            'active'     => true,
        );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Scoring Rules', 'greenpng' ); ?></h1>
            <hr class="wp-header-end" />

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php
                    /* translators: %d: length of the retention window in days, the same window the scores see. */
                    printf( esc_html__( 'Rules saved. A background recompute of every contact score has been queued; scores always read the last %d days of events, the same window retention keeps.', 'greenpng' ), (int) Gr_Scoring_Engine::WINDOW_DAYS );
                    ?>
                </p></div>
            <?php endif; ?>

            <?php if ( '' !== self::$save_error ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html( self::$save_error ); ?></p></div>
            <?php endif; ?>

            <p>
                <?php
                /* translators: %d: length of the scoring window in days, the same window retention keeps. */
                printf( esc_html__( 'Points are earned per event name, capped per day. A suspected bot verdict outranks every rule: the contact scores zero and is tagged for review. Scores recompute nightly and always read the last %d days of events, the same window retention keeps.', 'greenpng' ), (int) Gr_Scoring_Engine::WINDOW_DAYS );
                ?>
            </p>

            <form method="post">
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                <input type="hidden" name="gr_scoring_action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>" />
                <table class="widefat striped" role="presentation">
                    <thead>
                        <tr>
                            <th scope="col"><?php echo esc_html__( 'Event', 'greenpng' ); ?></th>
                            <th scope="col"><?php echo esc_html__( 'Points', 'greenpng' ); ?></th>
                            <th scope="col"><?php echo esc_html__( 'Daily cap', 'greenpng' ); ?></th>
                            <th scope="col"><?php echo esc_html__( 'Active', 'greenpng' ); ?></th>
                            <th scope="col"><?php echo esc_html__( 'Remove', 'greenpng' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rules as $index => $rule ) : ?>
                            <tr>
                                <td>
                                    <select name="gr_rule[<?php echo esc_attr( (string) $index ); ?>][event_name]" aria-label="<?php echo esc_attr( __( 'Event', 'greenpng' ) ); ?>">
                                        <option value=""><?php echo esc_html__( '— add a rule —', 'greenpng' ); ?></option>
                                        <?php foreach ( Gr_Scoring_Rules::VOCAB as $name ) : ?>
                                            <option value="<?php echo esc_attr( $name ); ?>"<?php selected( $name, $rule['event_name'] ); ?>><?php echo esc_html( $name ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="number" name="gr_rule[<?php echo esc_attr( (string) $index ); ?>][points]"
                                        value="<?php echo esc_attr( (string) $rule['points'] ); ?>" min="-100" max="100" step="1"
                                        class="small-text" aria-label="<?php echo esc_attr( __( 'Points', 'greenpng' ) ); ?>" />
                                </td>
                                <td>
                                    <input type="number" name="gr_rule[<?php echo esc_attr( (string) $index ); ?>][daily_cap]"
                                        value="<?php echo esc_attr( (string) $rule['daily_cap'] ); ?>" min="0" max="10" step="1"
                                        class="small-text" aria-label="<?php echo esc_attr( __( 'Daily cap', 'greenpng' ) ); ?>" />
                                </td>
                                <td>
                                    <input type="checkbox" name="gr_rule[<?php echo esc_attr( (string) $index ); ?>][active]" value="1"
                                        <?php checked( ! empty( $rule['active'] ) ); ?> aria-label="<?php echo esc_attr( __( 'Active', 'greenpng' ) ); ?>" />
                                </td>
                                <td>
                                    <input type="checkbox" name="gr_rule[<?php echo esc_attr( (string) $index ); ?>][remove]" value="1"
                                        aria-label="<?php echo esc_attr( __( 'Remove', 'greenpng' ) ); ?>" />
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button( __( 'Save rules', 'greenpng' ) ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * The submitted ruleset: rows marked for removal and untouched
     * blank add-rows drop out before validation.
     *
     * @return array<int, array{event_name: string, points: int, daily_cap: int, active: bool}>
     */
    private static function rules_from_post(): array {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write() on the write path; the ruleset's fields are sanitized field-by-field below before anything is kept.
        $posted = isset( $_POST['gr_rule'] ) && is_array( $_POST['gr_rule'] ) ? wp_unslash( $_POST['gr_rule'] ) : array();

        $rules = array();
        foreach ( $posted as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            if ( isset( $row['remove'] ) && '1' === (string) $row['remove'] ) {
                continue;
            }

            $name = isset( $row['event_name'] ) ? sanitize_key( (string) $row['event_name'] ) : '';
            if ( '' === $name ) {
                continue;
            }

            $rules[] = array(
                'event_name' => $name,
                'points'     => isset( $row['points'] ) ? (int) $row['points'] : 0,
                'daily_cap'  => isset( $row['daily_cap'] ) ? (int) $row['daily_cap'] : 0,
                'active'     => isset( $row['active'] ) && '1' === (string) $row['active'],
            );
        }

        return $rules;
    }
}
