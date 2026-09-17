<?php
/**
 * Plugin Ecosystem page (docs/06 tree, Integrations last entry,
 * docs/19 V32/V33): the local openness surface. Two faces: the
 * adapter posture (built-in bridges through the ecosystem detector,
 * third-party adapters through the gr_registered_adapters registry)
 * and the owner's dynamic hook rules, mounted only in admin
 * requests. The developer contract sits next to the controls it
 * explains. Every write passes the capability and nonce double gate
 * and leaves an audit row.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Integrations\Gr_Adapter_Registry;
use GreenPNG\Integrations\Gr_Ecosystem_Detector;
use GreenPNG\Storage\Gr_Audit_Repository;
use GreenPNG\Storage\Gr_Dynamic_Event_Repository;

/**
 * Ecosystem posture and dynamic sniffing rules.
 */
final class Gr_Ecosystem_Page {

    /** Menu slug under the top-level menu, Integrations section. */
    public const SLUG = 'greenpng-ecosystem';

    /** Nonce action for every write on this page. */
    public const NONCE_ACTION = 'gr_ecosystem';

    /** Nonce field name. */
    public const NONCE_FIELD = '_gr_ecosystem_nonce';

    /** Add-rule POST marker. */
    public const ACTION_ADD = 'add_rule';

    /** Toggle-rule POST marker. */
    public const ACTION_TOGGLE = 'toggle_rule';

    /** Delete-rule POST marker. */
    public const ACTION_DELETE = 'delete_rule';

    /**
     * Hook registration: writes are handled on admin_init, before
     * any output, so the post-write redirect is legal.
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
    }

    /**
     * The double gate: capability AND nonce, one gate for every
     * write on this page.
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
     * Dispatches POST writes with the post-redirect-get pattern.
     *
     * @return void
     */
    public static function handle_actions(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- routing only; the nonce gate below is the real check.
        $action = isset( $_POST['gr_ecosystem_action'] ) ? sanitize_key( (string) wp_unslash( $_POST['gr_ecosystem_action'] ) ) : '';
        if ( '' === $action ) {
            return;
        }

        if ( ! self::may_write() ) {
            return;
        }

        $audit   = new Gr_Audit_Repository();
        $user_id = get_current_user_id();
        $outcome = '';

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in may_write().
        $id = isset( $_POST['rule_id'] ) ? absint( (int) wp_unslash( $_POST['rule_id'] ) ) : 0;

        if ( self::ACTION_ADD === $action ) {
            $outcome = self::write_rule( $audit, $user_id );
        } elseif ( self::ACTION_TOGGLE === $action ) {
            $rule = Gr_Dynamic_Event_Repository::find( $id );
            if ( null !== $rule && Gr_Dynamic_Event_Repository::toggle( $id ) ) {
                $fresh = Gr_Dynamic_Event_Repository::find( $id );
                $audit->log( 'toggle', 'dynamic_event_rule', (string) $id, array( 'is_active' => (int) $rule['is_active'] ), array( 'is_active' => (int) $fresh['is_active'] ), $user_id );
                $outcome = 'toggled';
            } else {
                $outcome = 'not_found';
            }
        } elseif ( self::ACTION_DELETE === $action ) {
            $rule = Gr_Dynamic_Event_Repository::find( $id );
            if ( null !== $rule && Gr_Dynamic_Event_Repository::delete( $id ) ) {
                $audit->log(
                    'delete',
                    'dynamic_event_rule',
                    (string) $id,
                    array(
                        'hook_name'  => (string) $rule['hook_name'],
                        'event_name' => (string) $rule['event_name'],
                    ),
                    array(),
                    $user_id
                );
                $outcome = 'deleted';
            } else {
                $outcome = 'not_found';
            }
        }

        if ( '' !== $outcome ) {
            wp_safe_redirect(
                add_query_arg(
                    array(
                        'page'  => self::SLUG,
                        'gr_ec' => $outcome,
                    ),
                    admin_url( 'admin.php' )
                )
            );
        }
    }

    /**
     * Page output: write notice, adapter posture, the rules table,
     * the add form, and the developer contract.
     *
     * @return void
     */
    public static function render(): void {
        $notices = array(
            'added'     => __( 'Rule added.', 'greenpng' ),
            'toggled'   => __( 'Rule updated.', 'greenpng' ),
            'deleted'   => __( 'Rule deleted.', 'greenpng' ),
            'not_found' => __( 'That rule no longer exists.', 'greenpng' ),
            'refused'   => __( 'The rule was refused: check the hook name, the event name, and the parameter map lines.', 'greenpng' ),
            'full'      => __( 'The rule list is full.', 'greenpng' ),
        );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only outcome flag, carries no state change on its own.
        $flag = isset( $_GET['gr_ec'] ) ? sanitize_key( (string) wp_unslash( $_GET['gr_ec'] ) ) : '';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Plugin Ecosystem', 'greenpng' ); ?></h1>
            <hr class="wp-header-end" />
            <?php if ( '' !== $flag && isset( $notices[ $flag ] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( (string) $notices[ $flag ] ); ?></p></div>
            <?php endif; ?>
            <?php self::render_adapters(); ?>
            <?php self::render_rules(); ?>
            <?php self::render_developer_docs(); ?>
        </div>
        <?php
    }

    /**
     * The adapter posture: built-in bridges and third-party
     * registrations.
     *
     * @return void
     */
    private static function render_adapters(): void {
        $detected = Gr_Ecosystem_Detector::detect();
        $extra    = Gr_Adapter_Registry::posture();
        ?>
        <h2><?php echo esc_html__( 'Adapter posture', 'greenpng' ); ?></h2>
        <p><?php echo esc_html__( 'Built-in bridges mount only when their target plugin runs on this site. Third-party adapters register through the gr_registered_adapters filter with the same probe-first lifecycle.', 'greenpng' ); ?></p>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php echo esc_html__( 'Adapter', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Origin', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Status', 'greenpng' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $detected as $bridge => $info ) : ?>
                <tr>
                    <td><?php echo esc_html( (string) $bridge ); ?></td>
                    <td><?php echo esc_html__( 'built-in', 'greenpng' ); ?></td>
                    <td><?php echo ( ! empty( $info['active'] ) ) ? esc_html__( 'mounted', 'greenpng' ) : esc_html__( 'target plugin not active', 'greenpng' ); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php foreach ( $extra as $id => $entry ) : ?>
                <tr>
                    <td><?php echo esc_html( (string) $id ); ?></td>
                    <td><?php echo esc_html__( 'third-party', 'greenpng' ); ?></td>
                    <td>
                    <?php
                    if ( '' !== (string) $entry['error'] ) {
                        echo esc_html( (string) $entry['error'] );
                    } elseif ( ! empty( $entry['mounted'] ) ) {
                        echo esc_html__( 'mounted', 'greenpng' );
                    } elseif ( ! empty( $entry['available'] ) ) {
                        echo esc_html__( 'probe passed, mount failed', 'greenpng' );
                    } else {
                        echo esc_html__( 'target plugin not active', 'greenpng' );
                    }
                    ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ( array() === $detected && array() === $extra ) : ?>
                <tr><td colspan="3"><?php echo esc_html__( 'No adapters registered on this site.', 'greenpng' ); ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * The owner's dynamic hook rules.
     *
     * @return void
     */
    private static function render_rules(): void {
        $rules = Gr_Dynamic_Event_Repository::all();
        ?>
        <h2><?php echo esc_html__( 'Dynamic hook listening', 'greenpng' ); ?></h2>
        <p><?php echo esc_html__( 'Rules mount inside admin requests only; front-end and REST traffic never loads a listener. Every hit lands in the audit log, and rules with an event name also bridge the event onto the greenpng bus.', 'greenpng' ); ?></p>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php echo esc_html__( 'Hook', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Event name', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Parameter map', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'State', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Actions', 'greenpng' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $rules as $rule ) : ?>
                <?php self::render_rule_row( $rule ); ?>
            <?php endforeach; ?>
            <?php if ( array() === $rules ) : ?>
                <tr><td colspan="5"><?php echo esc_html__( 'No rules configured yet.', 'greenpng' ); ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <h3><?php echo esc_html__( 'Add a rule', 'greenpng' ); ?></h3>
        <form method="post">
            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
            <input type="hidden" name="gr_ecosystem_action" value="<?php echo esc_attr( self::ACTION_ADD ); ?>" />
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="gr_ec_hook"><?php echo esc_html__( 'Hook name', 'greenpng' ); ?></label></th>
                    <td>
                        <input name="gr_ec_hook" id="gr_ec_hook" type="text" class="regular-text" value="" required />
                        <p class="description"><?php echo esc_html__( 'Any WordPress hook, for example save_post. Only characters used by hook names are accepted.', 'greenpng' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="gr_ec_event"><?php echo esc_html__( 'Event name', 'greenpng' ); ?></label></th>
                    <td>
                        <input name="gr_ec_event" id="gr_ec_event" type="text" class="regular-text" value="" />
                        <p class="description"><?php echo esc_html__( 'Optional. Empty means audit-only; otherwise the hit is bridged onto the greenpng event bus under this name.', 'greenpng' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="gr_ec_map"><?php echo esc_html__( 'Parameter map', 'greenpng' ); ?></label></th>
                    <td>
                        <textarea name="gr_ec_map" id="gr_ec_map" rows="4" class="large-text"></textarea>
                        <p class="description"><?php echo esc_html__( 'One line per parameter, key = expression. Expressions: args[0].total reads the first hook argument, auto:email resolves the current admin user, anything else is a literal value.', 'greenpng' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Add rule', 'greenpng' ) ); ?>
        </form>
        <?php
    }

    /**
     * One rule row with its toggle and delete forms.
     *
     * @param array<string, mixed> $rule Rule row.
     * @return void
     */
    private static function render_rule_row( array $rule ): void {
        $map = array();
        foreach ( (array) $rule['param_map'] as $key => $expr ) {
            $map[] = (string) $key . ' = ' . (string) $expr;
        }
        ?>
        <tr>
            <td><?php echo esc_html( (string) $rule['hook_name'] ); ?></td>
            <td><?php echo '' === (string) $rule['event_name'] ? esc_html__( 'audit only', 'greenpng' ) : esc_html( (string) $rule['event_name'] ); ?></td>
            <td><?php echo esc_html( implode( '; ', $map ) ); ?></td>
            <td><?php echo 1 === (int) $rule['is_active'] ? esc_html__( 'active', 'greenpng' ) : esc_html__( 'paused', 'greenpng' ); ?></td>
            <td>
                <form method="post" style="display:inline">
                    <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                    <input type="hidden" name="gr_ecosystem_action" value="<?php echo esc_attr( self::ACTION_TOGGLE ); ?>" />
                    <input type="hidden" name="rule_id" value="<?php echo esc_attr( (string) $rule['id'] ); ?>" />
                    <?php submit_button( 1 === (int) $rule['is_active'] ? __( 'Pause', 'greenpng' ) : __( 'Resume', 'greenpng' ), 'small', 'submit', false ); ?>
                </form>
                <form method="post" style="display:inline">
                    <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                    <input type="hidden" name="gr_ecosystem_action" value="<?php echo esc_attr( self::ACTION_DELETE ); ?>" />
                    <input type="hidden" name="rule_id" value="<?php echo esc_attr( (string) $rule['id'] ); ?>" />
                    <?php submit_button( __( 'Delete', 'greenpng' ), 'small', 'submit', false ); ?>
                </form>
            </td>
        </tr>
        <?php
    }

    /**
     * The developer contract this page exposes.
     *
     * @return void
     */
    private static function render_developer_docs(): void {
        ?>
        <h2><?php echo esc_html__( 'For developers', 'greenpng' ); ?></h2>
        <p><?php echo esc_html__( 'Registering an adapter from another plugin: implement Gr_Adapter_Interface with a constructor that needs no arguments, and return the class name on the filter below. The plugin probes is_available() first and mounts register_hooks() only when your target plugin runs. Any throw is caught, reported on the gr_adapter_error action, and shown in the posture table above.', 'greenpng' ); ?></p>
        <pre>add_filter( 'gr_registered_adapters', function ( array $classes ) : array {
    $classes[] = My_Adapter::class; // implements Gr_Adapter_Interface
    return $classes;
} );</pre>
        <p><?php echo esc_html__( 'Dynamic rules can also be created programmatically: gr_dynamic_event_register( array( \'hook\' => ..., \'event\' => ..., \'map\' => array( \'key\' => \'args[0].total\' ) ) ) stores one rule, gr_dynamic_events() lists them, gr_dynamic_event_delete( $id ) removes one, and gr_param_resolve( $expression, $args ) resolves one expression by hand.', 'greenpng' ); ?></p>
        <?php
    }

    /**
     * Validates and stores one rule from the POST body.
     *
     * @param Gr_Audit_Repository $audit   Audit write side.
     * @param int                 $user_id Acting admin.
     * @return string Outcome flag for the redirect.
     */
    private static function write_rule( Gr_Audit_Repository $audit, int $user_id ): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in may_write().
        $hook = isset( $_POST['gr_ec_hook'] ) ? trim( sanitize_text_field( (string) wp_unslash( $_POST['gr_ec_hook'] ) ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in may_write().
        $event = isset( $_POST['gr_ec_event'] ) ? trim( sanitize_text_field( (string) wp_unslash( $_POST['gr_ec_event'] ) ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in may_write().
        $map = isset( $_POST['gr_ec_map'] ) ? self::parse_map( sanitize_textarea_field( (string) wp_unslash( $_POST['gr_ec_map'] ) ) ) : array();

        $id = Gr_Dynamic_Event_Repository::add( $hook, $event, $map );
        if ( 0 === $id ) {
            if ( count( Gr_Dynamic_Event_Repository::all() ) >= Gr_Dynamic_Event_Repository::CAP ) {
                return 'full';
            }

            return 'refused';
        }

        $audit->log(
            'add',
            'dynamic_event_rule',
            (string) $id,
            array(),
            array(
                'hook_name'  => $hook,
                'event_name' => $event,
                'param_map'  => $map,
            ),
            $user_id
        );

        return 'added';
    }

    /**
     * Parses the textarea lines into a param map.
     *
     * @param string $raw Raw textarea body.
     * @return array<string, string>
     */
    private static function parse_map( string $raw ): array {
        $out = array();
        foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
            $line = trim( (string) $line );
            if ( '' === $line || false === strpos( $line, '=' ) ) {
                continue;
            }

            list( $key, $expr ) = array_map( 'trim', explode( '=', $line, 2 ) );
            if ( '' === $key || '' === $expr ) {
                continue;
            }

            $out[ $key ] = $expr;
        }

        return $out;
    }
}
