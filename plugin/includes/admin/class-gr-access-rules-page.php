<?php
/**
 * Access Rules page (docs/13 U6, docs/06 §2.1): the ban and allow
 * lists over native components — WP_List_Table for the rows, one
 * form-table for adding, both wrapped in a nonce-carrying form.
 * Every write (add, delete, toggle) passes the same double gate:
 * manage_options capability AND a valid admin referer nonce; either
 * missing means the request did nothing.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Security\Gr_Access_Rules;
use GreenPNG\Storage\Gr_Access_Rules_Repository;
use GreenPNG\Storage\Gr_Audit_Repository;

/**
 * Owner-facing rule management.
 */
final class Gr_Access_Rules_Page {

    /** Menu slug. */
    public const SLUG = 'greenpng-access';

    /** Nonce action name for every write on this page. */
    public const NONCE_ACTION = 'gr_access_rules';

    /** Nonce field name. */
    public const NONCE_FIELD = '_gr_access_nonce';

    /** Add-rule POST marker. */
    public const ACTION_ADD = 'add';

    /** Bulk delete POST marker. */
    public const ACTION_DELETE = 'delete';

    /** Toggle POST marker. */
    public const ACTION_TOGGLE = 'toggle';

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
     * The double gate: capability AND nonce. One gate for every
     * write operation on this page.
     *
     * @return bool
     */
    public static function may_write(): bool {
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        // Core's check_admin_referer returns 1 or false, not bool.
        return (bool) check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );
    }

    /**
     * Dispatches POST writes. On success the request redirects to
     * itself (post-redirect-get, so a refresh never re-submits);
     * on a gate failure it falls through to rendering untouched.
     *
     * @return void
     */
    public static function handle_actions(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce gate below is the real check; this line only routes the request.
        $action = isset( $_POST['gr_access_action'] ) ? sanitize_key( (string) wp_unslash( $_POST['gr_access_action'] ) ) : '';
        if ( '' === $action ) {
            return;
        }

        if ( ! self::may_write() ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in may_write(); reading the tab the write belongs to.
        $tab     = isset( $_POST['tab'] ) ? sanitize_key( (string) wp_unslash( $_POST['tab'] ) ) : '';
        $type    = ( Gr_Access_Rules::TYPE_ALLOW === $tab ) ? Gr_Access_Rules::TYPE_ALLOW : Gr_Access_Rules::TYPE_BAN;
        $repo    = new Gr_Access_Rules_Repository();
        $audit   = new Gr_Audit_Repository();
        $user_id = get_current_user_id();

        if ( self::ACTION_ADD === $action ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in may_write().
            $kind = isset( $_POST['match_kind'] ) ? sanitize_key( (string) wp_unslash( $_POST['match_kind'] ) ) : '';
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); value is whitelist-validated in valid_value() before storage.
            $value = isset( $_POST['match_value'] ) ? trim( (string) wp_unslash( $_POST['match_value'] ) ) : '';
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); note is a free-text label stored via prepare() and escaped on output.
            $note = isset( $_POST['note'] ) ? trim( (string) wp_unslash( $_POST['note'] ) ) : '';

            // URL exemptions only exist on the allow side; a ban+URL
            // rule could never match anything, so it is refused here.
            $kind_ok = self::valid_kind( $kind )
                && ( Gr_Access_Rules::KIND_IP === $kind || Gr_Access_Rules::TYPE_ALLOW === $type );

            if ( $kind_ok && self::valid_value( $kind, $value ) ) {
                $new_id = $repo->add( $type, $kind, $value, $note );
                if ( $new_id > 0 ) {
                    $audit->log(
                        'add',
                        'access_rule',
                        (string) $new_id,
                        array(),
                        array(
                            'rule_type'   => $type,
                            'match_kind'  => $kind,
                            'match_value' => $value,
                            'note'        => $note,
                            'is_active'   => 1,
                        ),
                        $user_id
                    );
                }
            }
        } elseif ( self::ACTION_DELETE === $action ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in may_write(); ids are absint-cast.
            $ids = isset( $_POST['rule'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['rule'] ) ) : array();
            foreach ( $ids as $id ) {
                if ( $id > 0 ) {
                    $before = $repo->row_of( (int) $id, $type );
                    if ( $repo->delete( (int) $id, $type ) ) {
                        $audit->log( 'delete', 'access_rule', (string) $id, $before, array(), $user_id );
                    }
                }
            }
        } elseif ( self::ACTION_TOGGLE === $action ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in may_write().
            $id = isset( $_POST['rule_id'] ) ? absint( (int) wp_unslash( $_POST['rule_id'] ) ) : 0;
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); value is a strict '1' comparison.
            $to = isset( $_POST['to_active'] ) ? ( '1' === (string) wp_unslash( $_POST['to_active'] ) ) : false;
            if ( $id > 0 ) {
                $before = $repo->row_of( $id, $type );
                if ( array() !== $before && $repo->set_active( $id, $type, $to ) ) {
                    $audit->log(
                        'toggle',
                        'access_rule',
                        (string) $id,
                        array( 'is_active' => (int) $before['is_active'] ),
                        array( 'is_active' => $to ? 1 : 0 ),
                        $user_id
                    );
                }
            }
        }

        // Fresh rules for the rest of this request, then the redirect
        // ends it (post-redirect-get).
        Gr_Access_Rules::invalidate();
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => self::SLUG,
                    'tab'  => $type,
                ),
                admin_url( 'admin.php' )
            )
        );
    }

    /**
     * Page output: tab bar, the active tab's list, and the add form.
     *
     * @return void
     */
    public static function render(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch on an owner-gated screen.
        $tab  = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : Gr_Access_Rules::TYPE_BAN;
        $type = ( Gr_Access_Rules::TYPE_ALLOW === $tab ) ? Gr_Access_Rules::TYPE_ALLOW : Gr_Access_Rules::TYPE_BAN;
        $rows = ( new Gr_Access_Rules_Repository() )->rules_of_type( $type );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Access Rules', 'greenpng' ); ?></h1>
            <hr class="wp-header-end" />

            <nav class="nav-tab-wrapper">
                <?php
                $tabs = array(
                    Gr_Access_Rules::TYPE_BAN   => __( 'Ban list', 'greenpng' ),
                    Gr_Access_Rules::TYPE_ALLOW => __( 'Allow list', 'greenpng' ),
                );
                foreach ( $tabs as $key => $label ) :
                    $class = ( $key === $type ) ? ' nav-tab-active' : '';
                    ?>
                    <a class="nav-tab<?php echo esc_attr( $class ); ?>"
                        href="<?php echo esc_attr( '?page=' . self::SLUG . '&amp;tab=' . $key ); ?>">
                        <?php echo esc_html( (string) $label ); ?>
                    </a>
                    <?php endforeach; ?>
            </nav>

            <form method="post">
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                <input type="hidden" name="gr_access_action" value="<?php echo esc_attr( self::ACTION_DELETE ); ?>" />
                <input type="hidden" name="tab" value="<?php echo esc_attr( $type ); ?>" />
                <?php
                $table = new Gr_Access_Rules_Table(
                    array(
                        'singular' => 'rule',
                        'plural'   => 'rules',
                        'ajax'     => false,
                    ),
                    $rows
                );
                $table->display();
                ?>
                <?php submit_button( __( 'Delete selected', 'greenpng' ), 'delete' ); ?>
            </form>

            <h2><?php echo esc_html__( 'Add a rule', 'greenpng' ); ?></h2>
            <form method="post">
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                <input type="hidden" name="gr_access_action" value="<?php echo esc_attr( self::ACTION_ADD ); ?>" />
                <input type="hidden" name="tab" value="<?php echo esc_attr( $type ); ?>" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="gr-match-kind"><?php echo esc_html__( 'Matches', 'greenpng' ); ?></label></th>
                        <td>
                            <select name="match_kind" id="gr-match-kind">
                                <option value="ip"><?php echo esc_html__( 'IP address or range', 'greenpng' ); ?></option>
                                <option value="url"><?php echo esc_html__( 'URL path (allow list only)', 'greenpng' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gr-match-value"><?php echo esc_html__( 'Value', 'greenpng' ); ?></label></th>
                        <td><input type="text" name="match_value" id="gr-match-value" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gr-note"><?php echo esc_html__( 'Note', 'greenpng' ); ?></label></th>
                        <td><input type="text" name="note" id="gr-note" class="regular-text" /></td>
                    </tr>
                </table>
                <?php submit_button( __( 'Add rule', 'greenpng' ) ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Kind whitelist.
     *
     * @param string $kind Candidate kind.
     * @return bool
     */
    private static function valid_kind( string $kind ): bool {
        return in_array( $kind, array( Gr_Access_Rules::KIND_IP, Gr_Access_Rules::KIND_URL ), true );
    }

    /**
     * Value validation per kind: an IP rule must parse as an address
     * or a family-correct CIDR; a URL rule must be a rooted path
     * without traversal.
     *
     * @param string $kind  Match kind.
     * @param string $value Match value.
     * @return bool
     */
    private static function valid_value( string $kind, string $value ): bool {
        if ( '' === $value ) {
            return false;
        }

        if ( Gr_Access_Rules::KIND_IP === $kind ) {
            if ( filter_var( $value, FILTER_VALIDATE_IP ) ) {
                return true;
            }

            $parts = explode( '/', $value );
            if ( 2 !== count( $parts ) || '' === $parts[1] || ! ctype_digit( $parts[1] ) ) {
                return false;
            }

            $is_v4  = (bool) filter_var( $parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
            $is_any = (bool) filter_var( $parts[0], FILTER_VALIDATE_IP );
            $prefix = (int) $parts[1];

            return $is_any && $prefix <= ( $is_v4 ? 32 : 128 );
        }

        return '/' === $value[0] && false === strpos( $value, '..' );
    }
}
