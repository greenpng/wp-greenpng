<?php
/**
 * Login Protection page (docs/13 U7, docs/06 §1 tree, docs/19 V9):
 * the audit tab reads the folded security log for the two login
 * rules, the session tab lists the addresses that currently hold a
 * live lock and offers the release action, and the providers tab
 * holds the progressive-verification credential blocks (Turnstile,
 * hCaptcha). Recovery guidance — the CLI unlock command and the
 * allow-list pointer — is part of the page surface, not a hidden
 * doc, so a locked-out owner always sees the way back in.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Abuseipdb;
use GreenPNG\Core\Gr_Secrets;
use GreenPNG\Core\Gr_Settings;
use GreenPNG\Core\Gr_Siteverify;
use GreenPNG\Security\Gr_Login_Protection;
use GreenPNG\Security\Gr_Progressive_Verification;
use GreenPNG\Security\Gr_Temp_Bans;
use GreenPNG\Storage\Gr_Audit_Repository;
use GreenPNG\Storage\Gr_Security_Log_Repository;

/**
 * Three tabs over the W7 engine's output plus the challenge
 * provider settings: audit (history), sessions (live locks, with the
 * release write), providers (Turnstile / hCaptcha keys).
 */
final class Gr_Login_Protection_Page {

    /** Menu slug under the Traffic & Security parent. */
    public const SLUG = 'greenpng-login';

    /** Audit tab key. */
    public const TAB_AUDIT = 'audit';

    /** Session tab key. */
    public const TAB_SESSIONS = 'sessions';

    /** Provider tab key. */
    public const TAB_PROVIDERS = 'providers';

    /** Nonce action for the release write. */
    public const NONCE_ACTION = 'gr_login_admin';

    /** Nonce field name for the release write. */
    public const NONCE_FIELD = '_gr_login_nonce';

    /** Nonce action for the provider arms; the gate is the page, not the button. */
    public const NONCE_ACTION_PROVIDERS = 'gr-login-providers';

    /** Nonce field name for the provider arms. */
    public const NONCE_FIELD_PROVIDERS = '_gr_login_providers_nonce';

    /** POST action value: release one address's lock. */
    private const ACTION_RELEASE = 'release';

    /** POST action: save the Turnstile block. */
    public const ACTION_SAVE_TURNSTILE = 'save_turnstile';

    /** POST action: save the hCaptcha block. */
    public const ACTION_SAVE_HCAPTCHA = 'save_hcaptcha';

    /** POST action: local self-check of the Turnstile block. */
    public const ACTION_CHECK_TURNSTILE = 'check_turnstile';

    /** POST action: local self-check of the hCaptcha block. */
    public const ACTION_CHECK_HCAPTCHA = 'check_hcaptcha';

    /** Secret option names, owned by Gr_Secrets; the page only mirrors them. */
    public const TURNSTILE_SITE_OPTION   = Gr_Secrets::TURNSTILE_SITE_OPTION;
    public const TURNSTILE_SECRET_OPTION = Gr_Secrets::TURNSTILE_SECRET_OPTION;
    public const HCAPTCHA_SITE_OPTION    = Gr_Secrets::HCAPTCHA_SITE_OPTION;
    public const HCAPTCHA_SECRET_OPTION  = Gr_Secrets::HCAPTCHA_SECRET_OPTION;

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
     * The release gate: capability AND nonce, for the release arm.
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
     * The provider arms' gate: the same double gate, its own nonce.
     *
     * @return bool
     */
    public static function may_write_providers(): bool {
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        return (bool) check_admin_referer( self::NONCE_ACTION_PROVIDERS, self::NONCE_FIELD_PROVIDERS );
    }

    /**
     * Dispatches the write arms. The release arm redirects with a
     * flag the page turns into a notice: 1 = released, 0 = the lock
     * was already gone when the form landed (stale form, worth
     * saying). The provider arms carry their own post-redirect-get
     * flags under the same contract as the other credential pages.
     *
     * @return void
     */
    public static function handle_actions(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce gate below is the real check; this line only routes the request.
        $action = isset( $_POST['gr_login_action'] ) ? sanitize_key( (string) wp_unslash( $_POST['gr_login_action'] ) ) : '';
        if ( '' === $action ) {
            return;
        }

        if ( self::ACTION_SAVE_TURNSTILE === $action || self::ACTION_SAVE_HCAPTCHA === $action ) {
            if ( ! self::may_write_providers() ) {
                return;
            }

            self::save_provider( self::ACTION_SAVE_TURNSTILE === $action ? Gr_Siteverify::PROVIDER_TURNSTILE : Gr_Siteverify::PROVIDER_HCAPTCHA );

            return;
        }

        if ( self::ACTION_CHECK_TURNSTILE === $action || self::ACTION_CHECK_HCAPTCHA === $action ) {
            if ( ! self::may_write_providers() ) {
                return;
            }

            self::check_provider( self::ACTION_CHECK_TURNSTILE === $action ? Gr_Siteverify::PROVIDER_TURNSTILE : Gr_Siteverify::PROVIDER_HCAPTCHA );

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
     * Saves one provider's block: the key pair into Gr_Secrets and the
     * opt-in toggle into gr_settings, under the single-provider
     * discipline (ADR-0018 D6) — switching one provider on switches
     * the other off, so the audit snapshot carries both toggle words.
     * An empty field means "unchanged"; the remove checkbox is the
     * only way out and takes the toggle down with the pair. Invalid
     * shapes are refused whole.
     *
     * @param string $provider Provider word constant.
     * @return void
     */
    private static function save_provider( string $provider ): void {
        $settings   = new Gr_Settings();
        $toggle_key = self::toggle_key( $provider );
        $before     = self::provider_snapshot( $provider );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write_providers(); the remove flag is a strict '1' comparison.
        $remove = isset( $_POST[ $provider . '_remove' ] ) && '1' === (string) wp_unslash( $_POST[ $provider . '_remove' ] );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write_providers(); the values are shape-checked below before any store().
        $site_field = isset( $_POST[ $provider . '_site' ] ) ? trim( (string) wp_unslash( $_POST[ $provider . '_site' ] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write_providers(); the values are shape-checked below before any store().
        $secret_field = isset( $_POST[ $provider . '_secret' ] ) ? trim( (string) wp_unslash( $_POST[ $provider . '_secret' ] ) ) : '';

        if ( $remove ) {
            Gr_Secrets::forget( self::site_option( $provider ) );
            Gr_Secrets::forget( self::secret_option( $provider ) );
            $settings->set( $toggle_key, 0 );
        } else {
            if ( '' !== $site_field || '' !== $secret_field ) {
                // Half-entered pairs are refused whole: a lone site key
                // with no secret would look configured to the reader.
                if ( '' === $site_field || '' === $secret_field ) {
                    wp_safe_redirect( self::page_url( array( 'gr_error' => $provider . '_pair' ) ) );

                    return;
                }
                if ( ! self::valid_provider_value( $site_field ) ) {
                    wp_safe_redirect( self::page_url( array( 'gr_error' => $provider . '_site' ) ) );

                    return;
                }
                if ( ! self::valid_provider_value( $secret_field ) ) {
                    wp_safe_redirect( self::page_url( array( 'gr_error' => $provider . '_secret' ) ) );

                    return;
                }

                Gr_Secrets::store( self::site_option( $provider ), $site_field );
                Gr_Secrets::store( self::secret_option( $provider ), $secret_field );
            }

            $enabled = self::checkbox( $toggle_key );
            $settings->set( $toggle_key, $enabled );

            if ( 1 === $enabled ) {
                // One provider at a time: the switch is itself the
                // act of choosing, and the audit diff records both
                // sides through the snapshot below.
                $settings->set( self::toggle_key( Gr_Siteverify::PROVIDER_TURNSTILE === $provider ? Gr_Siteverify::PROVIDER_HCAPTCHA : Gr_Siteverify::PROVIDER_TURNSTILE ), 0 );
            }
        }

        $after = self::provider_snapshot( $provider );

        // State words, never values: the diff says what happened to a
        // credential and both toggles, not what either is.
        if ( $before !== $after ) {
            ( new Gr_Audit_Repository() )->log(
                'save',
                'challenge_provider',
                $provider,
                $before,
                $after,
                get_current_user_id()
            );
        }

        wp_safe_redirect( self::page_url( array( 'gr_saved' => $provider ) ) );
    }

    /**
     * Local self-check of one provider block: does the stored pair
     * decrypt back, and does it hold the expected shape. Reports
     * words, never values.
     *
     * @param string $provider Provider word constant.
     * @return void
     */
    private static function check_provider( string $provider ): void {
        wp_safe_redirect(
            self::page_url(
                array(
                    'gr_check'  => $provider,
                    'gr_result' => self::local_check( $provider ),
                )
            )
        );
    }

    /**
     * The local check battery: absent (nothing stored), broken (an
     * envelope that no longer decrypts), shape (readable but not a
     * plausible key), or ok.
     *
     * @param string $provider Provider word constant.
     * @return string Result word.
     */
    private static function local_check( string $provider ): string {
        foreach ( array( self::site_option( $provider ), self::secret_option( $provider ) ) as $option ) {
            $envelope = get_option( $option, '' );
            if ( ! is_string( $envelope ) || '' === $envelope ) {
                return 'absent';
            }
        }

        $site   = Gr_Secrets::reveal( self::site_option( $provider ) );
        $secret = Gr_Secrets::reveal( self::secret_option( $provider ) );

        if ( '' === $site || '' === $secret ) {
            return 'broken';
        }
        if ( ! self::valid_provider_value( $site ) || ! self::valid_provider_value( $secret ) ) {
            return 'shape';
        }

        return 'ok';
    }

    /**
     * Shape gate for the provider keys: opaque alphanumeric charset
     * (both providers issue keys in that family) and long enough that
     * a pasted fragment cannot pass as the whole key.
     *
     * @param string $value Candidate site or secret key.
     * @return bool
     */
    private static function valid_provider_value( string $value ): bool {
        return 1 === preg_match( '/^[A-Za-z0-9_-]{20,200}$/', $value );
    }

    /**
     * Reads a checkbox the way unchecked boxes POST: absent means 0.
     *
     * @param string $key POST key.
     * @return int 1 or 0.
     */
    private static function checkbox( string $key ): int {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write_providers(); value is a strict '1' comparison.
        return isset( $_POST[ $key ] ) && '1' === (string) wp_unslash( $_POST[ $key ] ) ? 1 : 0;
    }

    /**
     * The settings toggle behind one provider.
     *
     * @param string $provider Provider word constant.
     * @return string
     */
    private static function toggle_key( string $provider ): string {
        return Gr_Siteverify::PROVIDER_TURNSTILE === $provider ? 'turnstile_enabled' : 'hcaptcha_enabled';
    }

    /**
     * Option name of one provider's public site key.
     *
     * @param string $provider Provider word constant.
     * @return string
     */
    private static function site_option( string $provider ): string {
        return Gr_Siteverify::PROVIDER_TURNSTILE === $provider ? self::TURNSTILE_SITE_OPTION : self::HCAPTCHA_SITE_OPTION;
    }

    /**
     * Option name of one provider's secret key.
     *
     * @param string $provider Provider word constant.
     * @return string
     */
    private static function secret_option( string $provider ): string {
        return Gr_Siteverify::PROVIDER_TURNSTILE === $provider ? self::TURNSTILE_SECRET_OPTION : self::HCAPTCHA_SECRET_OPTION;
    }

    /**
     * One credential's state word.
     *
     * @param string $option Secret option name.
     * @return string 'absent', 'stored', or 'broken'.
     */
    private static function credential_word( string $option ): string {
        $envelope = get_option( $option, '' );
        if ( ! is_string( $envelope ) || '' === $envelope ) {
            return 'absent';
        }

        return '' === Gr_Secrets::reveal( $option ) ? 'broken' : 'stored';
    }

    /**
     * The state words for the audit diff: the provider's own toggle
     * and pair, plus the other provider's toggle — the single-provider
     * switch lands in the same diff (ADR-0018 D6).
     *
     * @param string $provider Provider word constant.
     * @return array<string, string|int>
     */
    private static function provider_snapshot( string $provider ): array {
        $settings = new Gr_Settings();
        $other    = Gr_Siteverify::PROVIDER_TURNSTILE === $provider ? Gr_Siteverify::PROVIDER_HCAPTCHA : Gr_Siteverify::PROVIDER_TURNSTILE;

        return array(
            'enabled'       => (int) $settings->get( self::toggle_key( $provider ) ),
            'other_enabled' => (int) $settings->get( self::toggle_key( $other ) ),
            'site_key'      => self::credential_word( self::site_option( $provider ) ),
            'secret_key'    => self::credential_word( self::secret_option( $provider ) ),
        );
    }

    /**
     * Page URL with added query flags, for the provider arms' PRG.
     *
     * @param array<string, string> $args Query additions.
     * @return string
     */
    private static function page_url( array $args ): string {
        return add_query_arg(
            $args + array(
                'page' => self::SLUG,
                'tab'  => self::TAB_PROVIDERS,
            ),
            admin_url( 'admin.php' )
        );
    }

    /**
     * Page output: recovery guidance first (it must survive every
     * tab), then the tab bar and the active tab's table.
     *
     * @return void
     */
    public static function render(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch and notice flag on an owner-gated screen.
        $tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : self::TAB_AUDIT;
        $tab = in_array( $tab, array( self::TAB_SESSIONS, self::TAB_PROVIDERS ), true ) ? $tab : self::TAB_AUDIT;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the flag only picks a notice; the write itself was gated on POST.
        $released = isset( $_GET['gr_released'] ) ? sanitize_key( (string) wp_unslash( $_GET['gr_released'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- PRG result flags are words from this page's own redirects, read-only display.
        $saved = isset( $_GET['gr_saved'] ) ? sanitize_key( (string) wp_unslash( $_GET['gr_saved'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- PRG result flags are words from this page's own redirects, read-only display.
        $error = isset( $_GET['gr_error'] ) ? sanitize_key( (string) wp_unslash( $_GET['gr_error'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- PRG result flags are words from this page's own redirects, read-only display.
        $check = isset( $_GET['gr_check'] ) ? sanitize_key( (string) wp_unslash( $_GET['gr_check'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- PRG result flags are words from this page's own redirects, read-only display.
        $check_result = isset( $_GET['gr_result'] ) ? sanitize_key( (string) wp_unslash( $_GET['gr_result'] ) ) : '';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Login Protection', 'greenpng' ); ?></h1>
            <hr class="wp-header-end" />

            <?php if ( '1' === $released ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Lock released. The address can sign in again.', 'greenpng' ); ?></p></div>
            <?php elseif ( '0' === $released ) : ?>
                <div class="notice notice-warning is-dismissible"><p><?php echo esc_html__( 'No active lock was found for that address. It may have expired while the page was open.', 'greenpng' ); ?></p></div>
            <?php endif; ?>

            <?php if ( '' !== $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %s: provider name, turnstile or hcaptcha. */
                            __( 'Saved. The %s block now holds exactly what you entered.', 'greenpng' ),
                            $saved
                        )
                    );
                    ?>
                </p></div>
            <?php endif; ?>

            <?php if ( '' !== $error ) : ?>
                <div class="notice notice-error"><p>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %s: field flag from the refused save. */
                            __( 'Refused: %s. Keys are stored whole or not at all, so nothing was saved.', 'greenpng' ),
                            $error
                        )
                    );
                    ?>
                </p></div>
            <?php endif; ?>

            <?php if ( '' !== $check ) : ?>
                <div class="notice <?php echo esc_attr( 'ok' === $check_result ? 'notice-success' : 'notice-warning' ); ?>"><p>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: 1: provider name, 2: check result word. */
                            __( 'Local self-check (%1$s): %2$s.', 'greenpng' ),
                            $check,
                            '' === $check_result ? 'unknown' : $check_result
                        )
                    );
                    ?>
                </p></div>
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
                    self::TAB_AUDIT     => __( 'Brute-force audit', 'greenpng' ),
                    self::TAB_SESSIONS  => __( 'Active locks', 'greenpng' ),
                    self::TAB_PROVIDERS => __( 'Challenge providers', 'greenpng' ),
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
            <?php elseif ( self::TAB_PROVIDERS === $tab ) : ?>
                <?php self::render_providers(); ?>
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
            array( Gr_Login_Protection::RULE_FAIL, Gr_Login_Protection::RULE_LOCKOUT, Gr_Progressive_Verification::RULE_ID ),
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
                            <td><?php echo esc_html( self::address_with_reputation( (string) ( $row['ip'] ?? '' ) ) ); ?></td>
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
                            <td><?php echo esc_html( self::address_with_reputation( (string) $lock['ip'] ) ); ?></td>
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
     * Provider tab: the progressive-verification disclosure and the
     * two credential blocks.
     *
     * @return void
     */
    private static function render_providers(): void {
        ?>
        <h2><?php echo esc_html__( 'Challenge providers (progressive verification)', 'greenpng' ); ?></h2>
        <p><?php echo esc_html__( 'A challenge loads for an address only after it failed twice, only on the login and registration pages, and never site-wide: normal visitors see nothing and load nothing. Turnstile and hCaptcha are alternatives — switching one on switches the other off — and each needs the site key and the secret key from its own dashboard. The site key is the widget\'s public identifier and appears in the page markup by design; the secret key never leaves the server.', 'greenpng' ); ?></p>
        <?php
        self::render_provider_block(
            Gr_Siteverify::PROVIDER_TURNSTILE,
            __( 'Cloudflare Turnstile', 'greenpng' ),
            __( 'Created at dash.cloudflare.com under Turnstile → Add site.', 'greenpng' )
        );

        self::render_provider_block(
            Gr_Siteverify::PROVIDER_HCAPTCHA,
            __( 'hCaptcha', 'greenpng' ),
            __( 'Created at dashboard.hcaptcha.com under Settings → Sites.', 'greenpng' )
        );
    }

    /**
     * One provider block: state line, the opt-in toggle with the
     * single-provider switch semantics, the key pair fields that
     * never echo a stored value back, the remove switch, and the two
     * buttons. Two submit buttons share one name and carry their
     * action as the value, the core-native way to offer more than one
     * action per form.
     *
     * @param string $provider  Provider word constant.
     * @param string $title     Block heading.
     * @param string $site_note Site key field note.
     * @return void
     */
    private static function render_provider_block( string $provider, string $title, string $site_note ): void {
        $site_word   = self::credential_word( self::site_option( $provider ) );
        $secret_word = self::credential_word( self::secret_option( $provider ) );
        $toggle_name = self::toggle_key( $provider );
        $enabled     = (int) ( new Gr_Settings() )->get( $toggle_name );
        ?>
        <h3><?php echo esc_html( $title ); ?></h3>

        <p>
            <?php
            if ( 'stored' === $site_word && 'stored' === $secret_word ) {
                echo esc_html(
                    sprintf(
                        /* translators: %s: masked site key preview. */
                        __( 'Configured (site key %s). Fields left empty keep the stored keys.', 'greenpng' ),
                        Gr_Secrets::mask( Gr_Secrets::reveal( self::site_option( $provider ) ) )
                    )
                );
            } elseif ( 'broken' === $site_word || 'broken' === $secret_word ) {
                echo esc_html__( 'Stored keys no longer decrypt (a changed salt or a mangled row). Enter them again or remove the block.', 'greenpng' );
            } else {
                echo esc_html__( 'Not configured. Nothing is sent for this provider, and its toggle has no effect until both keys exist.', 'greenpng' );
            }
            ?>
        </p>

        <form method="post">
            <?php wp_nonce_field( self::NONCE_ACTION_PROVIDERS, self::NONCE_FIELD_PROVIDERS ); ?>
            <input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Enabled', 'greenpng' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr( $toggle_name ); ?>" value="1" <?php checked( 1, $enabled ); ?> />
                            <?php echo esc_html__( 'Use this provider for the challenge; switching it on switches the other provider off', 'greenpng' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr( $provider . '_site' ); ?>"><?php echo esc_html__( 'Site key', 'greenpng' ); ?></label></th>
                    <td>
                        <?php if ( 'stored' === $site_word ) : ?>
                            <code><?php echo esc_html( Gr_Secrets::mask( Gr_Secrets::reveal( self::site_option( $provider ) ) ) ); ?></code>
                            <?php echo esc_html__( '— stored. Type a new value to replace it.', 'greenpng' ); ?>
                            <br />
                        <?php endif; ?>
                        <input type="text" id="<?php echo esc_attr( $provider . '_site' ); ?>" name="<?php echo esc_attr( $provider . '_site' ); ?>" value="" class="regular-text" autocomplete="off" />
                        <p class="description"><?php echo esc_html( $site_note ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr( $provider . '_secret' ); ?>"><?php echo esc_html__( 'Secret key', 'greenpng' ); ?></label></th>
                    <td>
                        <input type="password" id="<?php echo esc_attr( $provider . '_secret' ); ?>" name="<?php echo esc_attr( $provider . '_secret' ); ?>" value="" class="regular-text" autocomplete="new-password" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Remove', 'greenpng' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr( $provider . '_remove' ); ?>" value="1" />
                            <?php echo esc_html__( 'Forget the keys of this provider entirely; its toggle turns off with them', 'greenpng' ); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary" name="gr_login_action" value="<?php echo esc_attr( Gr_Siteverify::PROVIDER_TURNSTILE === $provider ? self::ACTION_SAVE_TURNSTILE : self::ACTION_SAVE_HCAPTCHA ); ?>"><?php echo esc_html__( 'Save', 'greenpng' ); ?></button>
                <button type="submit" class="button" name="gr_login_action" value="<?php echo esc_attr( Gr_Siteverify::PROVIDER_TURNSTILE === $provider ? self::ACTION_CHECK_TURNSTILE : self::ACTION_CHECK_HCAPTCHA ); ?>"><?php echo esc_html__( 'Self-check', 'greenpng' ); ?></button>
            </p>
        </form>
        <?php
    }

    /**
     * One address cell: the masked form, plus the cached reputation
     * band when the AbuseIPDB track holds a fresh answer for this
     * address — an advisory annotation only, never a block reason.
     *
     * @param string $ip Complete stored address.
     * @return string Display text, safe for esc_html.
     */
    private static function address_with_reputation( string $ip ): string {
        $text = gr_mask_ip( $ip );

        if ( '' === $ip ) {
            return $text;
        }

        $verdict = Gr_Abuseipdb::verdict( $ip );
        if ( '' !== $verdict['band'] && $verdict['score'] >= 0 ) {
            $text .= sprintf( ' — %s (%d)', $verdict['band'], $verdict['score'] );
        }

        return $text;
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

        if ( Gr_Progressive_Verification::RULE_ID === $rule_id ) {
            return __( 'Challenge', 'greenpng' );
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
