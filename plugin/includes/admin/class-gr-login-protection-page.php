<?php
/**
 * Login Protection page (docs/13 U7, docs/06 §1 tree): the audit tab
 * reads the folded security log for the two login rules, the session
 * tab lists the addresses that currently hold a live lock and offers
 * the release action. Recovery guidance — the CLI unlock command and
 * the allow-list pointer — is part of the page surface, not a hidden
 * doc, so a locked-out owner always sees the way back in.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Security\Gr_Login_Protection;
use GreenPNG\Security\Gr_Temp_Bans;
use GreenPNG\Storage\Gr_Security_Log_Repository;

/**
 * Two tabs over the W7 engine's output: audit (history) and sessions
 * (live locks, with the release write).
 */
final class Gr_Login_Protection_Page {

    /** Menu slug under the Traffic & Security parent. */
    public const SLUG = 'greenpng-login';

    /** Audit tab key. */
    public const TAB_AUDIT = 'audit';

    /** Session tab key. */
    public const TAB_SESSIONS = 'sessions';

    /** Nonce action for the release write. */
    public const NONCE_ACTION = 'gr_login_admin';

    /** Nonce field name for the release write. */
    public const NONCE_FIELD = '_gr_login_nonce';

    /** POST action value: release one address's lock. */
    private const ACTION_RELEASE = 'release';

    /**
     * Candidate window: the lock store accepts at most a 30-day TTL,
     * so any live lock our own modules placed has a log row inside
     * this window (Gr_Temp_Bans::TTL_CEILING).
     *
     * @var int
     */
    private const CANDIDATE_HOURS = 720;

    /**
     * Hook wiring: the write handler needs admin_init, which fires
     * before the page renders; the menu entry joins Gr_Admin_Menu.
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
    }

    /**
     * The double gate: capability AND nonce, shared by every write
     * this page offers.
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
     * Dispatches the release write. On any gated outcome the request
     * redirects to itself (post-redirect-get), carrying a flag the
     * page turns into a notice: 1 = released, 0 = the lock was already
     * gone when the form landed (stale form, worth saying).
     *
     * @return void
     */
    public static function handle_actions(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce gate below is the real check; this line only routes the request.
        $action = isset( $_POST['gr_login_action'] ) ? sanitize_key( (string) wp_unslash( $_POST['gr_login_action'] ) ) : '';
        if ( '' === $action ) {
            return;
        }

        if ( ! self::may_write() ) {
            return;
        }

        $flag = '';
        if ( self::ACTION_RELEASE === $action ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); FILTER_VALIDATE_IP below is the whitelist.
            $ip = isset( $_POST['lock_ip'] ) ? trim( (string) wp_unslash( $_POST['lock_ip'] ) ) : '';

            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                // The full stored form is the release target; the page
                // only ever shows the masked form of it.
                $flag = Gr_Temp_Bans::unblock( $ip ) ? '1' : '0';
            }
        }

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'        => self::SLUG,
                    'tab'         => self::TAB_SESSIONS,
                    'gr_released' => $flag,
                ),
                admin_url( 'admin.php' )
            )
        );
    }

    /**
     * Page output: recovery guidance first (it must survive both
     * tabs), then the tab bar and the active tab's table.
     *
     * @return void
     */
    public static function render(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch and notice flag on an owner-gated screen.
        $tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : self::TAB_AUDIT;
        $tab = ( self::TAB_SESSIONS === $tab ) ? self::TAB_SESSIONS : self::TAB_AUDIT;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the flag only picks a notice; the write itself was gated on POST.
        $released = isset( $_GET['gr_released'] ) ? sanitize_key( (string) wp_unslash( $_GET['gr_released'] ) ) : '';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Login Protection', 'greenpng' ); ?></h1>
            <hr class="wp-header-end" />

            <?php if ( '1' === $released ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Lock released. The address can sign in again.', 'greenpng' ); ?></p></div>
            <?php elseif ( '0' === $released ) : ?>
                <div class="notice notice-warning is-dismissible"><p><?php echo esc_html__( 'No active lock was found for that address. It may have expired while the page was open.', 'greenpng' ); ?></p></div>
            <?php endif; ?>

            <p><?php echo esc_html__( 'If you are locked out of your own site, run the WP-CLI release command:', 'greenpng' ); ?>
                <code><?php echo esc_html( 'wp greenpng unblock <ip>' ); ?></code>
            </p>
            <p class="description">
                <?php
                printf(
                    /* translators: %s: URL path of the Access Rules allow tab. */
                    esc_html__( 'Without CLI access, adding your address to the allow list on the Access Rules page (%s) overrides every lock; the allow list always wins.', 'greenpng' ),
                    esc_html( '?page=greenpng-access&tab=allow' )
                );
                ?>
            </p>

            <nav class="nav-tab-wrapper">
                <?php
                $tabs = array(
                    self::TAB_AUDIT    => __( 'Brute-force audit', 'greenpng' ),
                    self::TAB_SESSIONS => __( 'Active locks', 'greenpng' ),
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

            <?php if ( self::TAB_SESSIONS === $tab ) : ?>
                <?php self::render_sessions(); ?>
            <?php else : ?>
                <?php self::render_audit(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Audit tab: the folded login rows, addresses masked for display.
     *
     * @return void
     */
    private static function render_audit(): void {
        $rows = ( new Gr_Security_Log_Repository() )->recent_by_rules(
            array( Gr_Login_Protection::RULE_FAIL, Gr_Login_Protection::RULE_LOCKOUT ),
            30
        );
        ?>
        <h2><?php echo esc_html__( 'Failed sign-ins and lockouts, newest first', 'greenpng' ); ?></h2>
        <?php if ( array() === $rows ) : ?>
            <p><?php echo esc_html__( 'No failed sign-ins recorded yet.', 'greenpng' ); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col"><?php echo esc_html__( 'Last seen', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Event', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Address', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Hits', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Note', 'greenpng' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) ( $row['last_seen'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( self::event_label( (string) ( $row['rule_id'] ?? '' ) ) ); ?></td>
                            <td><?php echo esc_html( gr_mask_ip( (string) ( $row['ip'] ?? '' ) ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['hit_count'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['reason'] ?? '' ) ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description"><?php echo esc_html__( 'Addresses are masked for display; one row folds all hits from the same address and rule within an hour.', 'greenpng' ); ?></p>
            <?php
        endif;
    }

    /**
     * Session tab: live locks only. Each candidate address from the
     * log window is checked against the lock store; the ones still
     * locked render with a release form. The display cell shows the
     * masked form while the form carries the complete stored address —
     * the release action needs the exact key the lock lives under.
     *
     * @return void
     */
    private static function render_sessions(): void {
        $candidates = ( new Gr_Security_Log_Repository() )->distinct_recent_ips( self::CANDIDATE_HOURS, 100 );
        $locks      = array();
        foreach ( $candidates as $candidate ) {
            $ip = (string) $candidate['ip'];
            if ( '' !== $ip && Gr_Temp_Bans::is_locked( $ip ) ) {
                $locks[] = array(
                    'ip'        => $ip,
                    'last_seen' => (string) $candidate['last_seen'],
                    'reason'    => Gr_Temp_Bans::lock_reason( $ip ),
                    'remaining' => Gr_Temp_Bans::lock_remaining( $ip ),
                );
            }
        }
        ?>
        <h2><?php echo esc_html__( 'Addresses currently locked out', 'greenpng' ); ?></h2>
        <?php if ( array() === $locks ) : ?>
            <p><?php echo esc_html__( 'No active locks. Failed sign-ins are being counted; a lock starts at the threshold set under Settings.', 'greenpng' ); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col"><?php echo esc_html__( 'Address', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Why', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Last seen', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Time left', 'greenpng' ); ?></th>
                        <th scope="col"><?php echo esc_html__( 'Action', 'greenpng' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $locks as $lock ) : ?>
                        <tr>
                            <td><?php echo esc_html( gr_mask_ip( $lock['ip'] ) ); ?></td>
                            <td><?php echo esc_html( $lock['reason'] ); ?></td>
                            <td><?php echo esc_html( $lock['last_seen'] ); ?></td>
                            <td><?php echo esc_html( self::human_remaining( (int) $lock['remaining'] ) ); ?></td>
                            <td>
                                <form method="post">
                                    <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                                    <input type="hidden" name="gr_login_action" value="<?php echo esc_attr( self::ACTION_RELEASE ); ?>" />
                                    <input type="hidden" name="lock_ip" value="<?php echo esc_attr( $lock['ip'] ); ?>" />
                                    <?php submit_button( __( 'Release', 'greenpng' ), 'secondary small', 'submit', false ); ?>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description"><?php echo esc_html__( 'The list shows the masked form; releasing acts on the complete stored address.', 'greenpng' ); ?></p>
            <?php
        endif;
    }

    /**
     * Rule id to localized label.
     *
     * @param string $rule_id Fold-row rule identifier.
     * @return string
     */
    private static function event_label( string $rule_id ): string {
        if ( Gr_Login_Protection::RULE_LOCKOUT === $rule_id ) {
            return __( 'Lockout', 'greenpng' );
        }

        return __( 'Failed sign-in', 'greenpng' );
    }

    /**
     * Remaining lock time as an hour-minute or minute-second phrase.
     *
     * @param int $seconds Seconds left, at least 1 here.
     * @return string
     */
    private static function human_remaining( int $seconds ): string {
        if ( $seconds >= HOUR_IN_SECONDS ) {
            return sprintf(
                /* translators: 1: hours, 2: minutes. */
                __( '%1$dh %2$02dm', 'greenpng' ),
                intdiv( $seconds, HOUR_IN_SECONDS ),
                intdiv( $seconds % HOUR_IN_SECONDS, 60 )
            );
        }

        return sprintf(
            /* translators: 1: minutes, 2: seconds. */
            __( '%1$dm %2$02ds', 'greenpng' ),
            intdiv( $seconds, 60 ),
            $seconds % 60
        );
    }
}
