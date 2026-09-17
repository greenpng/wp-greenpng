<?php
/**
 * Funnels & Goals page (docs/06 §1 tree, ADR-0014 D3/D4/D5): four
 * tabs over one menu entry — the v1.0 A/B experiment reporting, the
 * v1.1 funnel definition CRUD, the step-loss staircase, and the
 * goals roll-up. Every write (experiment create/pause/delete,
 * funnel save/delete/pause) passes the same double gate as every
 * other page: manage_options capability AND a valid admin referer
 * nonce, carried by one nonce field shared across the tab's forms.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Funnel\Gr_Ab_Engine;
use GreenPNG\Funnel\Gr_Ab_Experiments;
use GreenPNG\Funnel\Gr_Ab_Significance;
use GreenPNG\Storage\Gr_Audit_Repository;
use GreenPNG\Storage\Gr_Conversion_Repository;
use GreenPNG\Storage\Gr_Funnel_Repository;

/**
 * A/B reporting plus funnel definition and reporting surface.
 */
final class Gr_Funnels_Page {

    /** Menu slug under the top-level menu. */
    public const SLUG = 'greenpng-funnels';

    /** Nonce action name for every write on this page. */
    public const NONCE_ACTION = 'gr_funnels';

    /** Nonce field name. */
    public const NONCE_FIELD = '_gr_funnels_nonce';

    /** A/B experiments tab key. */
    public const TAB_AB = 'ab';

    /** Funnel definitions tab key. */
    public const TAB_FUNNELS = 'funnels';

    /** Step-loss tab key. */
    public const TAB_STEPS = 'steps';

    /** Goals tab key. */
    public const TAB_GOALS = 'goals';

    /** Experiment save POST marker. */
    public const ACTION_AB_SAVE = 'ab_save';

    /** Experiment delete POST marker. */
    public const ACTION_AB_DELETE = 'ab_delete';

    /** Funnel save POST marker. */
    public const ACTION_FUNNEL_SAVE = 'funnel_save';

    /** Funnel delete POST marker. */
    public const ACTION_FUNNEL_DELETE = 'funnel_delete';

    /** Funnel pause/resume POST marker. */
    public const ACTION_FUNNEL_TOGGLE = 'funnel_toggle';

    /** Sample visitor ids for the assignment preview. */
    public const PREVIEW_IDS = array(
        'preview-01',
        'preview-02',
        'preview-03',
        'preview-04',
        'preview-05',
        'preview-06',
        'preview-07',
        'preview-08',
    );

    /** Report window shared by the step-loss and goals tabs, days. */
    public const REPORT_DAYS = 30;

    /**
     * The error of a refused funnel save, kept for the fall-through
     * render so the submitted steps stay on screen for correcting.
     * A static is safe here because admin pages are one request per
     * render.
     *
     * @var string
     */
    private static $funnel_error = '';

    /**
     * The submitted step rows of a refused funnel save, same reason.
     *
     * @var array<int, array<string, string>>
     */
    private static $funnel_submitted = array();

    /**
     * The submitted name of a refused funnel save.
     *
     * @var string
     */
    private static $funnel_submitted_name = '';

    /**
     * The error of a refused experiment save.
     *
     * @var string
     */
    private static $ab_error = '';

    /**
     * Test seam: forget a refused save's error and rows.
     *
     * @return void
     */
    public static function reset_for_tests(): void {
        self::$funnel_error          = '';
        self::$funnel_submitted      = array();
        self::$funnel_submitted_name = '';
        self::$ab_error              = '';
    }

    /**
     * Hook registration: writes are handled on admin_init, before
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
     * Dispatches the tab writes. A valid write redirects (post-
     * redirect-get); an invalid one falls through with the reason on
     * screen and the form as submitted.
     *
     * @return void
     */
    public static function handle_actions(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce gate below is the real check; this line only routes the request.
        $action = isset( $_POST['gr_funnels_action'] ) ? sanitize_key( (string) wp_unslash( $_POST['gr_funnels_action'] ) ) : '';
        if ( '' === $action ) {
            return;
        }

        if ( ! self::may_write() ) {
            return;
        }

        $audit   = new Gr_Audit_Repository();
        $user_id = get_current_user_id();

        switch ( $action ) {
            case self::ACTION_AB_SAVE:
                self::handle_ab_save( $audit, $user_id );
                return;
            case self::ACTION_AB_DELETE:
                self::handle_ab_delete( $audit, $user_id );
                return;
            case self::ACTION_FUNNEL_SAVE:
                self::handle_funnel_save( $audit, $user_id );
                return;
            case self::ACTION_FUNNEL_DELETE:
                self::handle_funnel_delete( $audit, $user_id );
                return;
            case self::ACTION_FUNNEL_TOGGLE:
                self::handle_funnel_toggle( $audit, $user_id );
                return;
        }
    }

    /**
     * Experiment save: the creation form and the pause/resume arm
     * both land here, because both write the same definition shape
     * through the repository's own validation (ADR-0014 D4 — no
     * second validation layer beside it).
     *
     * @param Gr_Audit_Repository $audit   Audit trail.
     * @param int                 $user_id Acting admin.
     * @return void
     */
    private static function handle_ab_save( Gr_Audit_Repository $audit, int $user_id ): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); the key is slugified by the repository.
        $raw_key = isset( $_POST['experiment'] ) ? (string) wp_unslash( $_POST['experiment'] ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); variants arrive as the creation textarea or as the pause arm's hidden inputs, and every line is slugified by the repository's own save().
        $raw_variants = isset( $_POST['variants'] ) ? wp_unslash( $_POST['variants'] ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); strict '1' comparison.
        $active = isset( $_POST['is_active'] ) && '1' === (string) wp_unslash( $_POST['is_active'] );

        $key = sanitize_key( $raw_key );

        // The creation form sends a textarea (one slug per line); the
        // pause/resume arm resends the stored variants as hidden
        // inputs, so the definition is written back unchanged except
        // for its state.
        $lines = array();
        if ( is_array( $raw_variants ) ) {
            $lines = array_values( array_map( 'strval', $raw_variants ) );
        } else {
            $split = preg_split( '/\r\n|\r|\n/', (string) $raw_variants );
            $lines = is_array( $split ) ? $split : array();
        }

        $variants = array();
        foreach ( $lines as $line ) {
            $line = trim( (string) $line );
            if ( '' !== $line ) {
                $variants[] = $line;
            }
        }

        $before = Gr_Ab_Experiments::get( $key );

        if ( '' === $key || count( $variants ) < 2 || ! Gr_Ab_Experiments::save( $key, $variants, $active ) ) {
            self::$ab_error = __( 'The experiment needs a key and at least two distinct variant slugs (first is control). The repository enforces the 20-experiment and 8-variant bounds.', 'greenpng' );
            return;
        }

        $after = Gr_Ab_Experiments::get( $key );
        $audit->log(
            'save',
            'ab_experiment',
            $key,
            null === $before ? array() : $before,
            null === $after ? array() : $after,
            $user_id
        );

        self::redirect( self::TAB_AB, array( 'ab_saved' => '1' ) );
    }

    /**
     * Experiment delete: the definition and its future assignment
     * go; the recorded events stay readable in the stream's retention.
     *
     * @param Gr_Audit_Repository $audit   Audit trail.
     * @param int                 $user_id Acting admin.
     * @return void
     */
    private static function handle_ab_delete( Gr_Audit_Repository $audit, int $user_id ): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in may_write(); the key is slugified.
        $key = isset( $_POST['experiment'] ) ? sanitize_key( (string) wp_unslash( $_POST['experiment'] ) ) : '';

        $before = Gr_Ab_Experiments::get( $key );
        if ( '' === $key || null === $before ) {
            self::redirect( self::TAB_AB );
            return;
        }

        Gr_Ab_Experiments::delete( $key );
        $audit->log( 'delete', 'ab_experiment', $key, $before, array(), $user_id );

        self::redirect( self::TAB_AB, array( 'ab_deleted' => '1' ) );
    }

    /**
     * Funnel save: the create and edit arms share one form. A refused
     * validation falls through with the submitted rows for correcting
     * instead of redirecting.
     *
     * @param Gr_Audit_Repository $audit   Audit trail.
     * @param int                 $user_id Acting admin.
     * @return void
     */
    private static function handle_funnel_save( Gr_Audit_Repository $audit, int $user_id ): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); free-text name, trimmed and stored via prepare(), escaped on output.
        $name = isset( $_POST['funnel_name'] ) ? trim( (string) wp_unslash( $_POST['funnel_name'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in may_write(); absint bounds the row id.
        $id = isset( $_POST['funnel_id'] ) ? absint( (int) wp_unslash( $_POST['funnel_id'] ) ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); strict '1' comparison.
        $active = isset( $_POST['is_active'] ) && '1' === (string) wp_unslash( $_POST['is_active'] );

        $steps = self::steps_from_post();
        $repo  = new Gr_Funnel_Repository();
        $saved = $repo->save( $name, $steps, $active, $id );

        if ( is_wp_error( $saved ) ) {
            self::$funnel_error          = $saved->get_error_message();
            self::$funnel_submitted_name = $name;
            self::$funnel_submitted      = self::submitted_rows();
            return;
        }

        $before = $id > 0 ? $repo->row_for_id( $id ) : null;
        $after  = $repo->row_for_id( (int) $saved );
        $audit->log(
            'save',
            'funnel',
            (string) $saved,
            null === $before ? array() : array(
                'name'      => (string) $before['name'],
                'is_active' => $before['is_active'] ? 1 : 0,
                'steps'     => count( (array) $before['steps'] ),
            ),
            null === $after ? array() : array(
                'name'      => (string) $after['name'],
                'is_active' => $after['is_active'] ? 1 : 0,
                'steps'     => count( (array) $after['steps'] ),
            ),
            $user_id
        );

        self::redirect( self::TAB_FUNNELS, array( 'funnel_saved' => '1' ) );
    }

    /**
     * Funnel delete: the definition and its journey rows go together.
     *
     * @param Gr_Audit_Repository $audit   Audit trail.
     * @param int                 $user_id Acting admin.
     * @return void
     */
    private static function handle_funnel_delete( Gr_Audit_Repository $audit, int $user_id ): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in may_write(); absint bounds the row id.
        $id = isset( $_POST['funnel_id'] ) ? absint( (int) wp_unslash( $_POST['funnel_id'] ) ) : 0;

        $repo   = new Gr_Funnel_Repository();
        $before = $repo->row_for_id( $id );

        if ( $id < 1 || null === $before || ! $repo->delete( $id ) ) {
            self::redirect( self::TAB_FUNNELS );
            return;
        }

        $audit->log(
            'delete',
            'funnel',
            (string) $id,
            array(
                'name'      => (string) $before['name'],
                'is_active' => $before['is_active'] ? 1 : 0,
                'steps'     => count( (array) $before['steps'] ),
            ),
            array(),
            $user_id
        );

        self::redirect( self::TAB_FUNNELS, array( 'funnel_deleted' => '1' ) );
    }

    /**
     * Funnel pause/resume: the flag flips, the recorded journeys stay.
     *
     * @param Gr_Audit_Repository $audit   Audit trail.
     * @param int                 $user_id Acting admin.
     * @return void
     */
    private static function handle_funnel_toggle( Gr_Audit_Repository $audit, int $user_id ): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in may_write(); absint bounds the row id.
        $id = isset( $_POST['funnel_id'] ) ? absint( (int) wp_unslash( $_POST['funnel_id'] ) ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); strict '1' comparison.
        $to = isset( $_POST['to_active'] ) && '1' === (string) wp_unslash( $_POST['to_active'] );

        $repo   = new Gr_Funnel_Repository();
        $before = $repo->row_for_id( $id );

        if ( $id < 1 || null === $before || ! $repo->set_active( $id, $to ) ) {
            self::redirect( self::TAB_FUNNELS );
            return;
        }

        $audit->log(
            'toggle',
            'funnel',
            (string) $id,
            array( 'is_active' => $before['is_active'] ? 1 : 0 ),
            array( 'is_active' => $to ? 1 : 0 ),
            $user_id
        );

        self::redirect( self::TAB_FUNNELS, array( 'funnel_toggled' => '1' ) );
    }

    /**
     * Step rows from the POST shape: indexed fields per row plus a
     * remove checkbox. A row with neither a name nor a match value is
     * a blank the owner left in place, not a step.
     *
     * @return array<int, array{name: string, match: array{kind: string, value: string, compare: string}}>
     */
    private static function steps_from_post(): array {
        $steps = array();
        foreach ( self::submitted_rows() as $row ) {
            if ( '' === $row['name'] && '' === $row['value'] ) {
                continue;
            }

            $steps[] = array(
                'name'  => $row['name'],
                'match' => array(
                    'kind'    => $row['kind'],
                    'value'   => $row['value'],
                    'compare' => $row['compare'],
                ),
            );
        }

        return $steps;
    }

    /**
     * Raw submitted rows, kept in submission order so a refused save
     * can put them back on screen exactly as the owner typed them.
     *
     * @return array<int, array{name: string, kind: string, value: string, compare: string}>
     */
    private static function submitted_rows(): array {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in may_write(); every field is whitelisted or free text stored via prepare().
        $posted = array();
        foreach ( array( 'step_name', 'match_kind', 'match_value', 'match_compare' ) as $field ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); the arrays are raw row-indexed input, sanitized field-by-field in the loop below.
            $posted[ $field ] = isset( $_POST[ $field ] ) && is_array( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : array();
        }

        $rows = array();
        $max  = 0;
        foreach ( $posted as $values ) {
            $max = max( $max, count( (array) $values ) );
        }

        for ( $i = 0; $i < $max; $i++ ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); the remove box is compared against a strict '1'.
            $remove = isset( $_POST['remove'][ $i ] ) && '1' === (string) wp_unslash( $_POST['remove'][ $i ] );
            if ( $remove ) {
                continue;
            }

            $kind    = isset( $posted['match_kind'][ $i ] ) ? sanitize_key( (string) $posted['match_kind'][ $i ] ) : '';
            $compare = isset( $posted['match_compare'][ $i ] ) ? sanitize_key( (string) $posted['match_compare'][ $i ] ) : '';

            $rows[] = array(
                'name'    => isset( $posted['step_name'][ $i ] ) ? trim( (string) $posted['step_name'][ $i ] ) : '',
                'kind'    => in_array( $kind, Gr_Funnel_Repository::MATCH_KINDS, true ) ? $kind : 'url',
                'value'   => isset( $posted['match_value'][ $i ] ) ? trim( (string) $posted['match_value'][ $i ] ) : '',
                'compare' => in_array( $compare, Gr_Funnel_Repository::COMPARES, true ) ? $compare : 'exact',
            );
        }

        return $rows;
    }

    /**
     * Post-write redirect back to the page with the tab and its
     * success flag.
     *
     * @param string                    $tab  Tab key.
     * @param array<string, string|int> $args Extra query args.
     * @return void
     */
    private static function redirect( string $tab, array $args = array() ): void {
        wp_safe_redirect(
            add_query_arg(
                array_merge(
                    array(
                        'page' => self::SLUG,
                        'tab'  => $tab,
                    ),
                    $args
                ),
                admin_url( 'admin.php' )
            )
        );
    }

    /**
     * Page output: tab bar and the active tab's content.
     *
     * @return void
     */
    public static function render(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch on an owner-gated screen.
        $raw = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : self::TAB_AB;
        $tab = in_array( $raw, array( self::TAB_AB, self::TAB_FUNNELS, self::TAB_STEPS, self::TAB_GOALS ), true ) ? $raw : self::TAB_AB;
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Funnels & Goals', 'greenpng' ); ?></h1>
            <hr class="wp-header-end" />

            <nav class="nav-tab-wrapper">
                <?php
                $tabs = array(
                    self::TAB_AB      => __( 'A/B experiments', 'greenpng' ),
                    self::TAB_FUNNELS => __( 'Funnels', 'greenpng' ),
                    self::TAB_STEPS   => __( 'Step loss', 'greenpng' ),
                    self::TAB_GOALS   => __( 'Goals', 'greenpng' ),
                );
                foreach ( $tabs as $key => $label ) :
                    $class = ( $key === $tab ) ? ' nav-tab-active' : '';
                    ?>
                    <a class="nav-tab<?php echo esc_attr( $class ); ?>"
                        href="<?php echo esc_attr( '?page=' . self::SLUG . '&amp;tab=' . $key ); ?>">
                        <?php echo esc_html( (string) $label ); ?>
                    </a>
                    <?php endforeach; ?>
            </nav>

            <?php
            if ( self::TAB_FUNNELS === $tab ) {
                self::render_funnels();
            } elseif ( self::TAB_STEPS === $tab ) {
                self::render_steps();
            } elseif ( self::TAB_GOALS === $tab ) {
                self::render_goals();
            } else {
                self::render_ab();
            }
            ?>
        </div>
        <?php
    }

    /**
     * The A/B tab: every experiment with its split preview and
     * significance verdict (the v1.0 reporting, unchanged), plus the
     * definition write arm — creation, pause/resume, delete.
     *
     * @return void
     */
    private static function render_ab(): void {
        $experiments = Gr_Ab_Experiments::all();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- one-view success flag after the post-write redirect.
        $ab_saved = isset( $_GET['ab_saved'] ) ? '1' : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- one-view success flag after the post-write redirect.
        $ab_deleted = isset( $_GET['ab_deleted'] ) ? '1' : '';
        ?>
        <h2><?php echo esc_html__( 'A/B experiments', 'greenpng' ); ?></h2>
        <p><?php echo esc_html__( 'The variant split each visitor lands in, and the two-proportion Z-test verdict over what was actually recorded. Content splits per visitor with the [gr_ab] shortcode; recording happens through the gr_ab_record() template facade.', 'greenpng' ); ?></p>

        <?php if ( '' !== self::$ab_error ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( self::$ab_error ); ?></p></div>
        <?php endif; ?>
        <?php if ( '' !== $ab_saved ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Experiment saved.', 'greenpng' ); ?></p></div>
        <?php endif; ?>
        <?php if ( '' !== $ab_deleted ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Experiment deleted.', 'greenpng' ); ?></p></div>
        <?php endif; ?>

        <?php if ( array() === $experiments ) : ?>
            <p><?php echo esc_html__( 'No experiments defined yet. Create one below; the first variant is the control.', 'greenpng' ); ?></p>
        <?php else : ?>
            <?php foreach ( $experiments as $key => $definition ) : ?>
                <?php self::render_experiment( (string) $key, $definition ); ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <h2><?php echo esc_html__( 'Create an experiment', 'greenpng' ); ?></h2>
        <form method="post">
            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
            <input type="hidden" name="gr_funnels_action" value="<?php echo esc_attr( self::ACTION_AB_SAVE ); ?>" />
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="gr-ab-key"><?php echo esc_html__( 'Experiment key', 'greenpng' ); ?></label></th>
                    <td>
                        <input type="text" name="experiment" id="gr-ab-key" class="regular-text" />
                        <p class="description"><?php echo esc_html__( 'A short slug, e.g. hero. The shortcode references it: [gr_ab experiment="hero" control="..." treatment="..."].', 'greenpng' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="gr-ab-variants"><?php echo esc_html__( 'Variants', 'greenpng' ); ?></label></th>
                    <td>
                        <textarea name="variants" id="gr-ab-variants" class="large-text code" rows="4"></textarea>
                        <p class="description"><?php echo esc_html__( 'One variant slug per line, at most 8, first line is the control. Each slug matches one attribute of the shortcode.', 'greenpng' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Running', 'greenpng' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="is_active" value="1" checked="checked" />
                            <?php echo esc_html__( 'Assign visitors to variants', 'greenpng' ); ?>
                        </label>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Save experiment', 'greenpng' ) ); ?>
        </form>
        <?php
    }

    /**
     * One experiment block: state, variants, write arm, split
     * preview, counts, and the Z-test verdict.
     *
     * @param string               $key        Experiment slug.
     * @param array<string, mixed> $definition active + variants (control first).
     * @return void
     */
    private static function render_experiment( string $key, array $definition ): void {
        $active   = ! empty( $definition['active'] );
        $variants = isset( $definition['variants'] ) && is_array( $definition['variants'] )
            ? $definition['variants']
            : array();
        $result   = Gr_Ab_Significance::calculate( $key );
        ?>
        <h2>
            <?php
            echo esc_html(
                sprintf(
                    /* translators: 1: experiment key, 2: state word. */
                    __( 'Experiment: %1$s (%2$s)', 'greenpng' ),
                    $key,
                    $active ? __( 'running', 'greenpng' ) : __( 'paused', 'greenpng' )
                )
            );
            ?>
        </h2>

        <p>
            <?php
            echo esc_html(
                sprintf(
                    /* translators: %s: comma-separated variant slugs. */
                    __( 'Variants (first is control): %s', 'greenpng' ),
                    implode( ', ', array_map( 'strval', $variants ) )
                )
            );
            ?>
        </p>

        <form method="post" style="display:inline-block;margin-right:8px;">
            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
            <input type="hidden" name="gr_funnels_action" value="<?php echo esc_attr( self::ACTION_AB_SAVE ); ?>" />
            <input type="hidden" name="experiment" value="<?php echo esc_attr( $key ); ?>" />
            <?php foreach ( $variants as $variant ) : ?>
                <input type="hidden" name="variants[]" value="<?php echo esc_attr( (string) $variant ); ?>" />
            <?php endforeach; ?>
            <input type="hidden" name="is_active" value="<?php echo esc_attr( $active ? '0' : '1' ); ?>" />
            <?php submit_button( $active ? __( 'Pause', 'greenpng' ) : __( 'Resume', 'greenpng' ), 'small', 'submit', false ); ?>
        </form>
        <form method="post" style="display:inline-block;">
            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
            <input type="hidden" name="gr_funnels_action" value="<?php echo esc_attr( self::ACTION_AB_DELETE ); ?>" />
            <input type="hidden" name="experiment" value="<?php echo esc_attr( $key ); ?>" />
            <?php submit_button( __( 'Delete', 'greenpng' ), 'delete small', 'submit', false ); ?>
        </form>

        <?php if ( ! $active ) : ?>
            <p><?php echo esc_html__( 'Assignment is paused: the shortcode falls back to control content and the engine assigns nothing. Recorded data stays readable below.', 'greenpng' ); ?></p>
        <?php else : ?>
            <?php self::render_preview( $key, $variants ); ?>
        <?php endif; ?>

        <?php self::render_results( $result ); ?>
        <?php
    }

    /**
     * Assignment split preview over fixed sample visitor ids: the pure
     * consistent-hash answer for each, so the owner can see how the
     * engine spreads visitors before any traffic arrives.
     *
     * @param string             $key      Experiment slug.
     * @param array<int, string> $variants Declared variant slugs.
     * @return void
     */
    private static function render_preview( string $key, array $variants ): void {
        $tally = array_fill_keys( $variants, 0 );
        ?>
        <h3><?php echo esc_html__( 'Assignment split preview', 'greenpng' ); ?></h3>
        <p><?php echo esc_html__( 'The same visitor always lands in the same variant on every request; the preview below is the pure hash split for eight fixed sample ids.', 'greenpng' ); ?></p>
        <table class="widefat striped">
            <thead><tr>
                <th><?php echo esc_html__( 'Sample visitor', 'greenpng' ); ?></th>
                <th><?php echo esc_html__( 'Assigned variant', 'greenpng' ); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ( self::PREVIEW_IDS as $sample ) : ?>
                    <?php
                    $variant = Gr_Ab_Engine::pick_variant( $key, $sample, $variants );
                    if ( array_key_exists( $variant, $tally ) ) {
                        ++$tally[ $variant ];
                    }
                    ?>
                    <tr>
                        <td><?php echo esc_html( $sample ); ?></td>
                        <td><?php echo esc_html( $variant ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p>
            <?php
            echo esc_html(
                sprintf(
                    /* translators: %s: comma-separated variant=count pairs. */
                    __( 'Sample tally: %s', 'greenpng' ),
                    implode(
                        ', ',
                        array_map(
                            static function ( string $variant ) use ( $tally ): string {
                                return $variant . '=' . (int) $tally[ $variant ];
                            },
                            $variants
                        )
                    )
                )
            );
            ?>
        </p>
        <?php
    }

    /**
     * Recorded counts and the significance verdict, straight from the
     * Z-test calculation.
     *
     * @param array<string, mixed> $result Gr_Ab_Significance::calculate output.
     * @return void
     */
    private static function render_results( array $result ): void {
        $status  = (string) $result['status'];
        $state   = array(
            'insufficient' => __( 'Insufficient sample: each arm needs at least 30 impressions before the comparison is readable.', 'greenpng' ),
            'inconclusive' => __( 'Inconclusive: no difference proven at 95% confidence yet.', 'greenpng' ),
            'significant'  => __( 'Significant at 95% confidence.', 'greenpng' ),
        );
        $message = isset( $state[ $status ] ) ? $state[ $status ] : $state['insufficient'];
        ?>
        <h3><?php echo esc_html__( 'Recorded counts and significance', 'greenpng' ); ?></h3>
        <table class="widefat striped">
            <thead><tr>
                <th><?php echo esc_html__( 'Variant', 'greenpng' ); ?></th>
                <th><?php echo esc_html__( 'Impressions', 'greenpng' ); ?></th>
                <th><?php echo esc_html__( 'Conversions', 'greenpng' ); ?></th>
                <th><?php echo esc_html__( 'CVR', 'greenpng' ); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ( (array) $result['variants'] as $variant => $arm ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $variant ); ?></td>
                        <td><?php echo esc_html( number_format( (float) $arm['impressions'] ) ); ?></td>
                        <td><?php echo esc_html( number_format( (float) $arm['conversions'] ) ); ?></td>
                        <td>
                            <?php
                            echo esc_html(
                                sprintf(
                                    /* translators: %s: percentage. */
                                    __( '%s%%', 'greenpng' ),
                                    number_format( (float) $arm['cvr'] * 100, 2 )
                                )
                            );
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p><?php echo esc_html( $message ); ?></p>
        <?php if ( array() !== (array) $result['pairs'] ) : ?>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php echo esc_html__( 'Comparison (control vs)', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'z', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'State', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Winner', 'greenpng' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ( (array) $result['pairs'] as $pair ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) $pair['variant'] ); ?></td>
                            <td><?php echo esc_html( number_format( (float) $pair['z'], 3 ) ); ?></td>
                            <td><?php echo esc_html( (string) $pair['state'] ); ?></td>
                            <td><?php echo esc_html( '' === (string) $pair['winner'] || 'tie' === (string) $pair['winner'] ? '—' : (string) $pair['winner'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        endif;
    }

    /**
     * The Funnels tab: the definition list with pause/delete arms,
     * and the one-form create/edit surface for the step flow.
     *
     * @return void
     */
    private static function render_funnels(): void {
        $repo = new Gr_Funnel_Repository();
        $rows = $repo->all();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only edit-target id on an owner-gated screen; absint bounds it.
        $edit    = isset( $_GET['edit'] ) ? absint( (int) wp_unslash( $_GET['edit'] ) ) : 0;
        $editing = $edit > 0 ? $repo->row_for_id( $edit ) : null;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- one-view success flags after the post-write redirect.
        $funnel_saved = isset( $_GET['funnel_saved'] ) ? '1' : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- one-view success flags after the post-write redirect.
        $funnel_deleted = isset( $_GET['funnel_deleted'] ) ? '1' : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- one-view success flags after the post-write redirect.
        $funnel_toggled = isset( $_GET['funnel_toggled'] ) ? '1' : '';
        ?>
        <h2><?php echo esc_html__( 'Funnel definitions', 'greenpng' ); ?></h2>
        <p><?php echo esc_html__( 'A funnel is an ordered list of steps. Each step matches either a pageview path (site-rooted, exact or prefix) or one event from the closed vocabulary: pageview, dwell, scroll_depth, rage_click, dead_click, conversion, ab. The tracker advances a session only step by step, so the counts answer "how far did they get in order".', 'greenpng' ); ?></p>

        <?php if ( '' !== self::$funnel_error ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( self::$funnel_error ); ?></p></div>
        <?php endif; ?>
        <?php if ( '' !== $funnel_saved ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Funnel saved.', 'greenpng' ); ?></p></div>
        <?php endif; ?>
        <?php if ( '' !== $funnel_deleted ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Funnel deleted, including its recorded journeys.', 'greenpng' ); ?></p></div>
        <?php endif; ?>
        <?php if ( '' !== $funnel_toggled ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Funnel state updated.', 'greenpng' ); ?></p></div>
        <?php endif; ?>

        <?php if ( array() === $rows ) : ?>
            <p><?php echo esc_html__( 'No funnels defined yet. Create one below; tracking starts with the next event after a funnel is active.', 'greenpng' ); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php echo esc_html__( 'Name', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Steps', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'State', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Actions', 'greenpng' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $rows as $row ) : ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_attr( '?page=' . self::SLUG . '&amp;tab=' . self::TAB_FUNNELS . '&amp;edit=' . (int) $row['id'] ); ?>">
                                    <?php echo esc_html( (string) $row['name'] ); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html( (string) count( (array) $row['steps'] ) ); ?></td>
                            <td><?php echo esc_html( $row['is_active'] ? __( 'active', 'greenpng' ) : __( 'paused', 'greenpng' ) ); ?></td>
                            <td>
                                <form method="post" style="display:inline-block;margin-right:6px;">
                                    <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                                    <input type="hidden" name="gr_funnels_action" value="<?php echo esc_attr( self::ACTION_FUNNEL_TOGGLE ); ?>" />
                                    <input type="hidden" name="funnel_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>" />
                                    <input type="hidden" name="to_active" value="<?php echo esc_attr( $row['is_active'] ? '0' : '1' ); ?>" />
                                    <?php submit_button( $row['is_active'] ? __( 'Pause', 'greenpng' ) : __( 'Resume', 'greenpng' ), 'small', 'submit', false ); ?>
                                </form>
                                <form method="post" style="display:inline-block;">
                                    <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                                    <input type="hidden" name="gr_funnels_action" value="<?php echo esc_attr( self::ACTION_FUNNEL_DELETE ); ?>" />
                                    <input type="hidden" name="funnel_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>" />
                                    <?php submit_button( __( 'Delete', 'greenpng' ), 'delete small', 'submit', false ); ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php self::render_funnel_form( $editing ); ?>
        <?php
    }

    /**
     * The create/edit form: one form, every step row editable in
     * place, one blank row to add, a remove box per row. A refused
     * save renders the submitted rows back; an edit renders the
     * stored flow.
     *
     * @param array<string, mixed>|null $editing Row being edited, null to create.
     * @return void
     */
    private static function render_funnel_form( ?array $editing ): void {
        $fallthrough = array() !== self::$funnel_submitted;
        $name        = $fallthrough ? self::$funnel_submitted_name : ( null === $editing ? '' : (string) $editing['name'] );
        $id          = $fallthrough ? 0 : ( null === $editing ? 0 : (int) $editing['id'] );
        $active      = $fallthrough || null === $editing ? true : (bool) $editing['is_active'];

        $rows = array();
        if ( $fallthrough ) {
            $rows = self::$funnel_submitted;
        } elseif ( null !== $editing ) {
            foreach ( (array) $editing['steps'] as $step ) {
                if ( ! is_array( $step ) ) {
                    continue;
                }
                $match  = isset( $step['match'] ) && is_array( $step['match'] ) ? $step['match'] : array();
                $rows[] = array(
                    'name'    => (string) ( $step['name'] ?? '' ),
                    'kind'    => (string) ( $match['kind'] ?? 'url' ),
                    'value'   => (string) ( $match['value'] ?? '' ),
                    'compare' => (string) ( $match['compare'] ?? 'exact' ),
                );
            }
        }
        $blank = array(
            'name'    => '',
            'kind'    => 'url',
            'value'   => '',
            'compare' => 'exact',
        );
        $pad   = 2 - count( $rows );
        for ( $i = 0; $i < $pad; $i++ ) {
            $rows[] = $blank;
        }
        // One blank row is always offered, so adding never needs a
        // second form.
        $rows[] = $blank;
        $rows   = array_slice( $rows, 0, Gr_Funnel_Repository::MAX_STEPS );
        ?>
        <h2>
            <?php
            echo esc_html(
                $id > 0
                    /* translators: %s: funnel name. */
                    ? sprintf( __( 'Edit funnel: %s', 'greenpng' ), $name )
                    : __( 'Create a funnel', 'greenpng' )
            );
            ?>
        </h2>
        <form method="post">
            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
            <input type="hidden" name="gr_funnels_action" value="<?php echo esc_attr( self::ACTION_FUNNEL_SAVE ); ?>" />
            <input type="hidden" name="funnel_id" value="<?php echo esc_attr( (string) $id ); ?>" />
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="gr-funnel-name"><?php echo esc_html__( 'Funnel name', 'greenpng' ); ?></label></th>
                    <td>
                        <input type="text" name="funnel_name" id="gr-funnel-name" class="regular-text" value="<?php echo esc_attr( $name ); ?>" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Active', 'greenpng' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="is_active" value="1"<?php checked( $active ); ?> />
                            <?php echo esc_html__( 'Track journeys through this funnel', 'greenpng' ); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <h3><?php echo esc_html__( 'Steps', 'greenpng' ); ?></h3>
            <p><?php echo esc_html__( 'Steps run in order. Leave a row blank to skip it; tick Remove to delete an existing step. A new blank row appears at the end for adding.', 'greenpng' ); ?></p>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php echo esc_html__( 'Remove', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Step name', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Match kind', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Match value', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Comparison', 'greenpng' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $rows as $i => $row ) : ?>
                        <tr>
                            <td><input type="checkbox" name="remove[<?php echo esc_attr( (string) $i ); ?>]" value="1" /></td>
                            <td><input type="text" name="step_name[<?php echo esc_attr( (string) $i ); ?>]" class="regular-text" value="<?php echo esc_attr( (string) $row['name'] ); ?>" /></td>
                            <td>
                                <select name="match_kind[<?php echo esc_attr( (string) $i ); ?>]">
                                    <option value="url"<?php selected( 'url', (string) $row['kind'] ); ?>><?php echo esc_html__( 'Pageview path', 'greenpng' ); ?></option>
                                    <option value="event"<?php selected( 'event', (string) $row['kind'] ); ?>><?php echo esc_html__( 'Event name', 'greenpng' ); ?></option>
                                </select>
                            </td>
                            <td><input type="text" name="match_value[<?php echo esc_attr( (string) $i ); ?>]" class="regular-text" value="<?php echo esc_attr( (string) $row['value'] ); ?>" /></td>
                            <td>
                                <select name="match_compare[<?php echo esc_attr( (string) $i ); ?>]">
                                    <option value="exact"<?php selected( 'exact', (string) $row['compare'] ); ?>><?php echo esc_html__( 'exact', 'greenpng' ); ?></option>
                                    <option value="prefix"<?php selected( 'prefix', (string) $row['compare'] ); ?>><?php echo esc_html__( 'prefix', 'greenpng' ); ?></option>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php submit_button( __( 'Save funnel', 'greenpng' ) ); ?>
        </form>
        <?php
    }

    /**
     * The Step-loss tab (ADR-0014 D3): the staircase — one bar per
     * step, its height the share of journeys that reached it, the
     * drop-off between bars called out — with the same numbers in a
     * screen-reader table underneath.
     *
     * @return void
     */
    private static function render_steps(): void {
        $repo    = new Gr_Funnel_Repository();
        $funnels = $repo->all();

        if ( array() === $funnels ) {
            ?>
            <h2><?php echo esc_html__( 'Step loss', 'greenpng' ); ?></h2>
            <p><?php echo esc_html__( 'No funnels defined yet. Create one on the Funnels tab first; the staircase appears once journeys are recorded.', 'greenpng' ); ?></p>
            <?php
            return;
        }

        $ids = array();
        foreach ( $funnels as $funnel ) {
            $ids[] = (int) $funnel['id'];
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only funnel selector; the value is whitelisted against the definition ids.
        $raw      = isset( $_GET['funnel'] ) ? absint( (int) wp_unslash( $_GET['funnel'] ) ) : 0;
        $selected = in_array( $raw, $ids, true ) ? $raw : $ids[0];

        $current = null;
        foreach ( $funnels as $funnel ) {
            if ( (int) $funnel['id'] === $selected ) {
                $current = $funnel;
            }
        }

        $counts = $repo->step_counts( $selected, self::REPORT_DAYS );
        ?>
        <h2><?php echo esc_html__( 'Step loss', 'greenpng' ); ?></h2>
        <p><?php echo esc_html__( 'Journeys that began in the last 30 days, by how far they got in order. A session counts for a step only when it reached every step before it.', 'greenpng' ); ?></p>

        <form method="get">
            <input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
            <input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB_STEPS ); ?>" />
            <label for="gr-steps-funnel"><?php echo esc_html__( 'Funnel:', 'greenpng' ); ?></label>
            <select name="funnel" id="gr-steps-funnel">
                <?php foreach ( $funnels as $funnel ) : ?>
                    <option value="<?php echo esc_attr( (string) $funnel['id'] ); ?>"<?php selected( (int) $funnel['id'], $selected ); ?>>
                        <?php echo esc_html( (string) $funnel['name'] ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php submit_button( __( 'View', 'greenpng' ), 'secondary', 'submit', false ); ?>
        </form>

        <?php self::render_staircase( $current, $counts ); ?>
        <?php
    }

    /**
     * The staircase itself: a Flexbox row of step bars sized by the
     * share of journeys that reached each step, with the drop-off
     * between consecutive steps. Pure inline styles on native admin
     * markup — no stylesheet of the plugin's own to ship or load.
     *
     * @param array<string, mixed>|null                     $funnel Selected definition.
     * @param array{steps: array<int, int>, completed: int} $counts Aggregated counts.
     * @return void
     */
    private static function render_staircase( ?array $funnel, array $counts ): void {
        $steps   = null === $funnel ? array() : (array) $funnel['steps'];
        $reached = $counts['steps'];

        if ( array() === $steps ) {
            return;
        }

        $first = (int) ( $reached[1] ?? 0 );
        $max   = 0;
        foreach ( $reached as $count ) {
            $max = max( $max, (int) $count );
        }

        // Bar heights are shares of the busiest step, so the tallest
        // bar is full height and emptier steps read as loss. The
        // first step has no previous one, so its drop-off is zero by
        // definition, never a negative number.
        $total   = count( $steps );
        $columns = array();
        $i       = 0;
        foreach ( $steps as $index => $step ) {
            ++$i;
            $name      = is_array( $step ) ? (string) ( $step['name'] ?? '' ) : '';
            $count     = (int) ( $reached[ $i ] ?? 0 );
            $height    = $max > 0 ? (int) round( $count / $max * 100 ) : 0;
            $share     = $first > 0 ? $count / $first * 100 : 0.0;
            $prev      = $i > 1 ? (int) ( $reached[ $i - 1 ] ?? 0 ) : $count;
            $columns[] = array(
                'name'   => $name,
                'count'  => $count,
                'height' => $height,
                'share'  => $share,
                'lost'   => max( 0, $prev - $count ),
                'last'   => $i === $total,
            );
        }
        ?>
        <div style="display:flex;align-items:flex-end;gap:2px;margin-top:16px;height:220px;">
            <?php foreach ( $columns as $column ) : ?>
                <?php if ( $column['lost'] > 0 ) : ?>
                    <div style="flex:0 0 64px;display:flex;flex-direction:column;justify-content:center;align-items:center;color:#d63638;">
                        <span class="dashicons dashicons-arrow-down-alt" aria-hidden="true"></span>
                        <strong><?php echo esc_html( number_format( (float) $column['lost'] ) ); ?></strong>
                    </div>
                <?php else : ?>
                    <?php // A gap column always renders, so the bars keep one width whether or not a step lost anyone. ?>
                    <div style="flex:0 0 64px;"></div>
                <?php endif; ?>
                <div style="flex:1 1 0;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;height:100%;min-width:72px;">
                    <strong><?php echo esc_html( number_format( (float) $column['count'] ) ); ?></strong>
                    <div
                        role="img"
                        <?php // translators: 1: step name, 2: session count, 3: percentage of step-one entrants. ?>
                        aria-label="<?php echo esc_attr( sprintf( __( 'Step %1$s: %2$s sessions, %3$s%% of entrants', 'greenpng' ), $column['name'], number_format( (float) $column['count'] ), number_format( $column['share'], 1 ) ) ); ?>"
                        style="width:80%;height:<?php echo esc_attr( (string) max( 2, $column['height'] ) ); ?>%;background-color:#2271b1;margin-bottom:8px;<?php echo $column['last'] ? 'background-color:#00a32a;' : ''; ?>">
                    </div>
                    <span style="text-align:center;"><?php echo esc_html( $column['name'] ); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <p>
            <?php
            echo esc_html(
                sprintf(
                    /* translators: %d: completed journey count. */
                    __( 'Completed the last step: %d journeys.', 'greenpng' ),
                    (int) $counts['completed']
                )
            );
            ?>
        </p>

        <table class="screen-reader-text">
            <thead><tr>
                <th scope="col"><?php echo esc_html__( 'Step', 'greenpng' ); ?></th>
                <th scope="col"><?php echo esc_html__( 'Reached', 'greenpng' ); ?></th>
                <th scope="col"><?php echo esc_html__( 'Share of entrants', 'greenpng' ); ?></th>
                <th scope="col"><?php echo esc_html__( 'Drop-off from previous', 'greenpng' ); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ( $columns as $index => $column ) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html( (string) ( $index + 1 ) . '. ' . $column['name'] ); ?></th>
                        <td><?php echo esc_html( (string) $column['count'] ); ?></td>
                        <td><?php echo esc_html( number_format( $column['share'], 1 ) ); ?>%</td>
                        <td><?php echo esc_html( (string) $column['lost'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * The Goals tab (ADR-0014 D5): conversions and net value per
     * source over the report window, plus how many journeys completed
     * each funnel.
     *
     * @return void
     */
    private static function render_goals(): void {
        $totals      = ( new Gr_Conversion_Repository() )->totals_by_source( self::REPORT_DAYS );
        $funnel_repo = new Gr_Funnel_Repository();
        $funnels     = $funnel_repo->all();
        $completed   = $funnel_repo->completed_counts( self::REPORT_DAYS );
        ?>
        <h2><?php echo esc_html__( 'Goals', 'greenpng' ); ?></h2>
        <p><?php echo esc_html__( 'The last 30 days: conversions and net value per source (reversed orders count as conversions but not value), and the journeys that completed each funnel.', 'greenpng' ); ?></p>

        <h3><?php echo esc_html__( 'Conversions by source', 'greenpng' ); ?></h3>
        <?php if ( array() === $totals ) : ?>
            <p><?php echo esc_html__( 'No conversions recorded in the window yet.', 'greenpng' ); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php echo esc_html__( 'Source', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Conversions', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Net value', 'greenpng' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $totals as $source => $stats ) : ?>
                        <tr>
                            <td><?php echo esc_html( 'woocommerce' === $source ? __( 'WooCommerce', 'greenpng' ) : (string) $source ); ?></td>
                            <td><?php echo esc_html( number_format( (float) $stats['conversions'] ) ); ?></td>
                            <td><?php echo esc_html( number_format( (float) $stats['net'], 2 ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3><?php echo esc_html__( 'Funnels completed', 'greenpng' ); ?></h3>
        <?php if ( array() === $funnels ) : ?>
            <p><?php echo esc_html__( 'No funnels defined yet; the Goals tab reports funnel completions once a funnel exists.', 'greenpng' ); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php echo esc_html__( 'Funnel', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Completed journeys', 'greenpng' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $funnels as $funnel ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) $funnel['name'] ); ?></td>
                            <td><?php echo esc_html( number_format( (float) ( $completed[ (int) $funnel['id'] ] ?? 0 ) ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }
}
