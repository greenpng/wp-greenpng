<?php
/**
 * Settings page (docs/06 §1 first submenu, OQ-1 decision): the
 * owner-facing switches over the settings row every module already
 * reads. Three tabs — General, Security, Attribution — each saving
 * behind the double gate with an audit row carrying the recursive
 * diff of what moved.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Settings;
use GreenPNG\Security\Gr_Access_Rules;
use GreenPNG\Storage\Gr_Audit_Repository;
use GreenPNG\Storage\Gr_Uninstall;

/**
 * Global switches, grouped by tab.
 */
final class Gr_Settings_Page {

    /** Menu slug; the first submenu entry under the top-level menu. */
    public const SLUG = 'greenpng-settings';

    /** Nonce action for every save on this page. */
    public const NONCE_ACTION = 'gr_settings_save';

    /** Nonce field name. */
    public const NONCE_FIELD = '_gr_settings_nonce';

    /** Save POST marker. */
    public const ACTION_SAVE = 'save';

    /** General tab key. */
    public const TAB_GENERAL = 'general';

    /** Security tab key. */
    public const TAB_SECURITY = 'security';

    /** Attribution tab key. */
    public const TAB_ATTRIBUTION = 'attribution';

    /** Attribution model vocabulary (the five documented models). */
    public const MODELS = array( 'first', 'last', 'linear', 'position', 'time_decay' );

    /** Action-mode vocabulary. */
    public const ACTION_MODES = array( 'log', 'block' );

    /** Cookie window ceiling, days. */
    public const MAX_COOKIE_DAYS = 365;

    /**
     * Hook registration: saves are handled on admin_init so the
     * post-save redirect is legal.
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
     * Dispatches saves; each tab whitelists its own fields, so a
     * foreign POST key never reaches storage.
     *
     * @return void
     */
    public static function handle_actions(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce gate below is the real check; this line only routes the request.
        $action = isset( $_POST['gr_settings_action'] ) ? sanitize_key( (string) wp_unslash( $_POST['gr_settings_action'] ) ) : '';
        if ( self::ACTION_SAVE !== $action ) {
            return;
        }

        if ( ! self::may_write() ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in may_write(); the tab routes to its own whitelist.
        $tab = isset( $_POST['tab'] ) ? sanitize_key( (string) wp_unslash( $_POST['tab'] ) ) : '';

        $before = self::snapshot();
        if ( self::TAB_SECURITY === $tab ) {
            self::save_security();
        } elseif ( self::TAB_ATTRIBUTION === $tab ) {
            self::save_attribution();
        } else {
            self::save_general();
        }
        $after = self::snapshot();

        if ( $before !== $after ) {
            ( new Gr_Audit_Repository() )->log(
                'save',
                'settings',
                $tab,
                $before,
                $after,
                get_current_user_id()
            );
        }

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'     => self::SLUG,
                    'tab'      => $tab,
                    'gr_saved' => 1,
                ),
                admin_url( 'admin.php' )
            )
        );
    }

    /**
     * The comparable view both sides of a save: every setting this
     * page can move plus the uninstall flag, in one flat array —
     * flat keys keep the audit diff readable end to end.
     *
     * @return array<string, mixed>
     */
    private static function snapshot(): array {
        $settings = new Gr_Settings();
        $keys     = array(
            'marketing_ip_anonymize',
            'marketing_consent_fallback',
            'security_enabled',
            'security_action_mode',
            'security_log_anonymize',
            'trust_proxy_headers',
            'trusted_proxies',
            'probe_enabled',
            'behavior_enabled',
            'bot_verdict_threshold',
            'login_fail_threshold',
            'login_lockout_base',
            'honeypot_enabled',
            'blackhole_enabled',
            'attribution_enabled',
            'attribution_cookie_days',
            'attribution_default_model',
        );

        $out = array();
        foreach ( $keys as $key ) {
            $out[ $key ] = $settings->get( $key );
        }
        $out['delete_data_on_uninstall'] = get_option( Gr_Uninstall::DELETE_FLAG_OPTION, '0' );

        return $out;
    }

    /**
     * Reads a checkbox the way unchecked boxes POST: absent means 0.
     *
     * @param string $key POST key.
     * @return int 1 or 0.
     */
    private static function checkbox( string $key ): int {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); value is a strict '1' comparison.
        return isset( $_POST[ $key ] ) && '1' === (string) wp_unslash( $_POST[ $key ] ) ? 1 : 0;
    }

    /**
     * General tab: privacy defaults, the retention entry point, and
     * the uninstall behavior flag.
     *
     * @return void
     */
    private static function save_general(): void {
        $settings = new Gr_Settings();

        $settings->set( 'marketing_ip_anonymize', self::checkbox( 'marketing_ip_anonymize' ) );
        $settings->set( 'marketing_consent_fallback', self::checkbox( 'marketing_consent_fallback' ) );

        // The uninstall flag lives as its own autoload=no option; off
        // removes the row entirely so the default is absence, not a
        // stored zero someone could mistrust.
        if ( 1 === self::checkbox( 'delete_data_on_uninstall' ) ) {
            update_option( Gr_Uninstall::DELETE_FLAG_OPTION, '1' );
        } else {
            delete_option( Gr_Uninstall::DELETE_FLAG_OPTION );
        }
    }

    /**
     * Security tab: the master switch, action mode, anonymization,
     * proxy trust, and the probe switch.
     *
     * @return void
     */
    private static function save_security(): void {
        $settings = new Gr_Settings();

        $settings->set( 'security_enabled', self::checkbox( 'security_enabled' ) );
        $settings->set( 'security_log_anonymize', self::checkbox( 'security_log_anonymize' ) );
        $settings->set( 'trust_proxy_headers', self::checkbox( 'trust_proxy_headers' ) );
        $settings->set( 'probe_enabled', self::checkbox( 'probe_enabled' ) );
        $settings->set( 'behavior_enabled', self::checkbox( 'behavior_enabled' ) );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); the value is whitelist-checked below.
        $mode = isset( $_POST['security_action_mode'] ) ? sanitize_key( (string) wp_unslash( $_POST['security_action_mode'] ) ) : 'log';
        $settings->set( 'security_action_mode', in_array( $mode, self::ACTION_MODES, true ) ? $mode : 'log' );

        // Proxy list: comma-separated CIDRs and bare addresses; kept
        // as a cleaned array, every entry still validated by the
        // resolver when it is used.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); free-text list parsed and trimmed below.
        $raw  = isset( $_POST['trusted_proxies'] ) ? (string) wp_unslash( $_POST['trusted_proxies'] ) : '';
        $list = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
        $settings->set( 'trusted_proxies', array_slice( $list, 0, 20 ) );

        // Engine dials that were settings keys with no writer until
        // v1.0.1 (ADR-0009 D5); every number clamps to the same range
        // its engine enforces on read.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); absint coerces before the clamp.
        $fail_threshold = isset( $_POST['login_fail_threshold'] ) ? absint( (int) wp_unslash( $_POST['login_fail_threshold'] ) ) : 5;
        $settings->set( 'login_fail_threshold', max( 2, min( 100, $fail_threshold ) ) );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); absint coerces before the clamp.
        $lockout_base = isset( $_POST['login_lockout_base'] ) ? absint( (int) wp_unslash( $_POST['login_lockout_base'] ) ) : 300;
        $settings->set( 'login_lockout_base', max( 60, min( 86400, $lockout_base ) ) );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in may_write(); checkbox() enforces the strict '1'.
        $settings->set( 'honeypot_enabled', self::checkbox( 'honeypot_enabled' ) );
        $settings->set( 'blackhole_enabled', self::checkbox( 'blackhole_enabled' ) );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); absint coerces before the clamp.
        $verdict_threshold = isset( $_POST['bot_verdict_threshold'] ) ? absint( (int) wp_unslash( $_POST['bot_verdict_threshold'] ) ) : 70;
        $settings->set( 'bot_verdict_threshold', max( 1, min( 100, $verdict_threshold ) ) );
    }

    /**
     * Attribution tab: enablement, cookie window, default model.
     *
     * @return void
     */
    private static function save_attribution(): void {
        $settings = new Gr_Settings();

        $settings->set( 'attribution_enabled', self::checkbox( 'attribution_enabled' ) );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in may_write(); value is absint-clamped.
        $days = isset( $_POST['attribution_cookie_days'] ) ? absint( (int) wp_unslash( $_POST['attribution_cookie_days'] ) ) : 30;
        $settings->set( 'attribution_cookie_days', max( 1, min( self::MAX_COOKIE_DAYS, $days ) ) );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); the value is whitelist-checked below.
        $model = isset( $_POST['attribution_default_model'] ) ? sanitize_key( (string) wp_unslash( $_POST['attribution_default_model'] ) ) : 'last';
        $settings->set( 'attribution_default_model', in_array( $model, self::MODELS, true ) ? $model : 'last' );
    }

    /**
     * Page output: tab bar plus the active tab's form.
     *
     * @return void
     */
    public static function render(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch on an owner-gated screen.
        $tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : self::TAB_GENERAL;
        if ( ! in_array( $tab, array( self::TAB_GENERAL, self::TAB_SECURITY, self::TAB_ATTRIBUTION ), true ) ) {
            $tab = self::TAB_GENERAL;
        }

        $settings = new Gr_Settings();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only PRG flag on an owner-gated screen.
        $saved = isset( $_GET['gr_saved'] ) ? 1 : 0;
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Settings', 'greenpng' ); ?></h1>
            <hr class="wp-header-end" />

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Settings saved.', 'greenpng' ); ?></p></div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper">
                <?php
                $tabs = array(
                    self::TAB_GENERAL     => __( 'General', 'greenpng' ),
                    self::TAB_SECURITY    => __( 'Security', 'greenpng' ),
                    self::TAB_ATTRIBUTION => __( 'Attribution', 'greenpng' ),
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

            <form method="post">
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                <input type="hidden" name="gr_settings_action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>" />
                <input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
                <table class="form-table" role="presentation">
                    <?php if ( self::TAB_GENERAL === $tab ) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Anonymize marketing IPs', 'greenpng' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="marketing_ip_anonymize" value="1" <?php checked( 1, (int) $settings->get( 'marketing_ip_anonymize' ) ); ?> />
                                    <?php echo esc_html__( 'Store marketing-track addresses anonymized (on by default).', 'greenpng' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Consent fallback', 'greenpng' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="marketing_consent_fallback" value="1" <?php checked( 1, (int) $settings->get( 'marketing_consent_fallback' ) ); ?> />
                                    <?php echo esc_html__( 'Track marketing data when no Consent API is present (off by default).', 'greenpng' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Data retention', 'greenpng' ); ?></th>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=greenpng-retention' ) ); ?>"><?php echo esc_html__( 'Open the Data Retention page', 'greenpng' ); ?></a>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Delete data on uninstall', 'greenpng' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( '1', (string) get_option( Gr_Uninstall::DELETE_FLAG_OPTION, '0' ) ); ?> />
                                    <?php echo esc_html__( 'Remove every plugin table and option when the plugin is uninstalled (off by default: data belongs to the site owner).', 'greenpng' ); ?>
                                </label>
                            </td>
                        </tr>
                    <?php elseif ( self::TAB_SECURITY === $tab ) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Security engine', 'greenpng' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="security_enabled" value="1" <?php checked( 1, (int) $settings->get( 'security_enabled' ) ); ?> />
                                    <?php echo esc_html__( 'Master switch for every traffic-security engine (on by default).', 'greenpng' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gr-action-mode"><?php echo esc_html__( 'Action mode', 'greenpng' ); ?></label></th>
                            <td>
                                <select name="security_action_mode" id="gr-action-mode">
                                    <option value="log" <?php selected( 'log', (string) $settings->get( 'security_action_mode' ) ); ?>><?php echo esc_html__( 'Log only (default)', 'greenpng' ); ?></option>
                                    <option value="block" <?php selected( 'block', (string) $settings->get( 'security_action_mode' ) ); ?>><?php echo esc_html__( 'Log and block', 'greenpng' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Anonymize security logs', 'greenpng' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="security_log_anonymize" value="1" <?php checked( 1, (int) $settings->get( 'security_log_anonymize' ) ); ?> />
                                    <?php echo esc_html__( 'Store security-log addresses anonymized (off by default: full addresses on the legitimate-interest track; degrades ban precision).', 'greenpng' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Trust proxy headers', 'greenpng' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="trust_proxy_headers" value="1" <?php checked( 1, (int) $settings->get( 'trust_proxy_headers' ) ); ?> />
                                    <?php echo esc_html__( 'Read forwarded-for headers only from the trusted proxies below (off by default: REMOTE_ADDR only).', 'greenpng' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gr-trusted-proxies"><?php echo esc_html__( 'Trusted proxies', 'greenpng' ); ?></label></th>
                            <td>
                                <input type="text" name="trusted_proxies" id="gr-trusted-proxies" class="regular-text"
                                    value="<?php echo esc_attr( implode( ', ', (array) $settings->get( 'trusted_proxies' ) ) ); ?>" />
                                <p class="description"><?php echo esc_html__( 'Comma-separated addresses or CIDR ranges, at most 20.', 'greenpng' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Client probe', 'greenpng' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="probe_enabled" value="1" <?php checked( 1, (int) $settings->get( 'probe_enabled' ) ); ?> />
                                    <?php echo esc_html__( 'Collect client safety signals (on by default; disclosed in the readme).', 'greenpng' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Behavior signals', 'greenpng' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="behavior_enabled" value="1" <?php checked( 1, (int) $settings->get( 'behavior_enabled' ) ); ?> />
                                    <?php echo esc_html__( 'Collect dwell time, scroll depth, rage and dead clicks for consenting visitors only (off by default; marketing consent required).', 'greenpng' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gr-verdict-threshold"><?php echo esc_html__( 'Bot verdict threshold', 'greenpng' ); ?></label></th>
                            <td>
                                <input type="number" name="bot_verdict_threshold" id="gr-verdict-threshold" class="small-text" min="1" max="100"
                                    value="<?php echo esc_attr( (string) (int) $settings->get( 'bot_verdict_threshold' ) ); ?>" />
                                <?php echo esc_html__( 'probe score (1 to 100, default 70: two corroborating signals). At or above it a session is treated as a bot and its conversions are not forwarded to analytics.', 'greenpng' ); ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gr-fail-threshold"><?php echo esc_html__( 'Login failure threshold', 'greenpng' ); ?></label></th>
                            <td>
                                <input type="number" name="login_fail_threshold" id="gr-fail-threshold" class="small-text" min="2" max="100"
                                    value="<?php echo esc_attr( (string) (int) $settings->get( 'login_fail_threshold' ) ); ?>" />
                                <?php echo esc_html__( 'failed sign-ins before the address locks out (2 to 100, default 5).', 'greenpng' ); ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gr-lockout-base"><?php echo esc_html__( 'Lockout base duration', 'greenpng' ); ?></label></th>
                            <td>
                                <input type="number" name="login_lockout_base" id="gr-lockout-base" class="small-text" min="60" max="86400"
                                    value="<?php echo esc_attr( (string) (int) $settings->get( 'login_lockout_base' ) ); ?>" />
                                <?php echo esc_html__( 'seconds; each round doubles, capped at 24 hours (default 300).', 'greenpng' ); ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Honeypot traps', 'greenpng' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="honeypot_enabled" value="1" <?php checked( 1, (int) $settings->get( 'honeypot_enabled' ) ); ?> />
                                    <?php echo esc_html__( 'Add hidden trap fields to the login and registration forms (off by default).', 'greenpng' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Blackhole trap', 'greenpng' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="blackhole_enabled" value="1" <?php checked( 1, (int) $settings->get( 'blackhole_enabled' ) ); ?> />
                                    <?php echo esc_html__( 'Declare a disallowed trap path in robots.txt and answer crawls of it (off by default).', 'greenpng' ); ?>
                                </label>
                            </td>
                        </tr>
                    <?php else : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Attribution engine', 'greenpng' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="attribution_enabled" value="1" <?php checked( 1, (int) $settings->get( 'attribution_enabled' ) ); ?> />
                                    <?php echo esc_html__( 'Record touchpoints and bind conversions (on by default).', 'greenpng' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gr-cookie-days"><?php echo esc_html__( 'Cookie window', 'greenpng' ); ?></label></th>
                            <td>
                                <input type="number" name="attribution_cookie_days" id="gr-cookie-days" class="small-text" min="1" max="<?php echo esc_attr( (string) self::MAX_COOKIE_DAYS ); ?>"
                                    value="<?php echo esc_attr( (string) (int) $settings->get( 'attribution_cookie_days' ) ); ?>" />
                                <?php echo esc_html__( 'days (1 to 365, default 30).', 'greenpng' ); ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gr-default-model"><?php echo esc_html__( 'Default model', 'greenpng' ); ?></label></th>
                            <td>
                                <select name="attribution_default_model" id="gr-default-model">
                                    <?php foreach ( self::MODELS as $model ) : ?>
                                        <option value="<?php echo esc_attr( $model ); ?>" <?php selected( $model, (string) $settings->get( 'attribution_default_model' ) ); ?>><?php echo esc_html( $model ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php echo esc_html__( 'Reports compare all five models; this is the highlighted default.', 'greenpng' ); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </table>
                <?php submit_button( __( 'Save settings', 'greenpng' ) ); ?>
            </form>
        </div>
        <?php
    }
}
