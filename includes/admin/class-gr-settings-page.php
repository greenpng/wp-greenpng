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

use GreenPNG\Cart\Gr_Cart_Recovery;
use GreenPNG\Core\Gr_Secrets;
use GreenPNG\Core\Gr_Settings;
use GreenPNG\Core\Gr_Smtp_Manager;
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

    /** Notifications tab key. */
    public const TAB_NOTIFICATIONS = 'notifications';

    /** Attribution model vocabulary (the five documented models). */
    public const MODELS = array( 'first', 'last', 'linear', 'position', 'time_decay' );

    /** Action-mode vocabulary. */
    public const ACTION_MODES = array( 'log', 'block' );

    /** Encryption modes for SMTP. */
    public const ENCRYPTION_MODES = array( 'none', 'ssl', 'tls' );

    /** Available notification events. */
    public const NOTIFICATION_EVENTS = array( 'conversion', 'lead', 'security_lockout' );

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
        } elseif ( self::TAB_NOTIFICATIONS === $tab ) {
            self::save_notifications();
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
            'cart_recovery_enabled',
            'cart_recovery_delay',
            'cart_recovery_subject',
            'smtp_enabled',
            'smtp_host',
            'smtp_port',
            'smtp_encryption',
            'smtp_auth',
            'smtp_user',
            'smtp_from_email',
            'smtp_from_name',
            'notify_email_enabled',
            'notify_email_recipients',
            'notify_email_events',
        );

        $out = array();
        foreach ( $keys as $key ) {
            $out[ $key ] = $settings->get( $key );
        }
        $out['delete_data_on_uninstall'] = get_option( Gr_Uninstall::DELETE_FLAG_OPTION, '0' );
        // The mail body template is an option this page can move, so
        // it rides the audit diff like every other movable value.
        $out[ Gr_Cart_Recovery::TEMPLATE_OPTION ] = get_option( Gr_Cart_Recovery::TEMPLATE_OPTION, '' );

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
     * Attribution tab: enablement, cookie window, default model, and
     * the cart-recovery controls (ADR-0015 D4).
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

        // Cart recovery: the master ask. Off means no checkbox on
        // checkout, no capture, no mail — the whole feature, one arm.
        $settings->set( 'cart_recovery_enabled', self::checkbox( 'cart_recovery_enabled' ) );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in may_write(); absint coerces before the clamp.
        $delay = isset( $_POST['cart_recovery_delay'] ) ? absint( (int) wp_unslash( $_POST['cart_recovery_delay'] ) ) : 15;
        $settings->set( 'cart_recovery_delay', max( Gr_Cart_Recovery::DELAY_MIN, min( Gr_Cart_Recovery::DELAY_MAX, $delay ) ) );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); sanitize_text_field runs below.
        $subject = isset( $_POST['cart_recovery_subject'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['cart_recovery_subject'] ) ) : '';
        $subject = substr( $subject, 0, 191 );
        $settings->set( 'cart_recovery_subject', '' !== $subject ? $subject : Gr_Settings::defaults()['cart_recovery_subject'] );

        // The mail body: owner markup through wp_kses_post, bounded,
        // and only stored when both links survive — a recovery mail
        // without its recovery and unsubscribe links cannot do its
        // job, so an invalid template falls back to the built-in
        // default rather than reaching a mailbox.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); wp_kses_post runs below.
        $template = isset( $_POST['cart_recovery_template'] ) ? (string) wp_unslash( $_POST['cart_recovery_template'] ) : '';
        $template = substr( wp_kses_post( $template ), 0, Gr_Cart_Recovery::TEMPLATE_MAX );

        if ( Gr_Cart_Recovery::template_is_valid( $template ) ) {
            if ( '' !== get_option( Gr_Cart_Recovery::TEMPLATE_OPTION, '' ) ) {
                update_option( Gr_Cart_Recovery::TEMPLATE_OPTION, $template );
            } else {
                add_option( Gr_Cart_Recovery::TEMPLATE_OPTION, $template, '', 'no' );
            }
        } else {
            // Veto to the default: an absent option reads as the
            // built-in default on every render.
            delete_option( Gr_Cart_Recovery::TEMPLATE_OPTION );
        }
    }

    /**
     * Notifications tab: SMTP server configurations and event subscriptions.
     *
     * @return void
     */
    private static function save_notifications(): void {
        $settings = new Gr_Settings();

        // 1. SMTP Settings
        $settings->set( 'smtp_enabled', self::checkbox( 'smtp_enabled' ) );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); sanitized below.
        $host = isset( $_POST['smtp_host'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['smtp_host'] ) ) : '';
        $settings->set( 'smtp_host', substr( $host, 0, 191 ) );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); absint coerces before clamp.
        $port = isset( $_POST['smtp_port'] ) ? absint( (int) wp_unslash( $_POST['smtp_port'] ) ) : 465;
        $settings->set( 'smtp_port', max( 1, min( 65535, $port ) ) );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); checked against whitelist.
        $encryption = isset( $_POST['smtp_encryption'] ) ? sanitize_key( (string) wp_unslash( $_POST['smtp_encryption'] ) ) : 'ssl';
        $settings->set( 'smtp_encryption', in_array( $encryption, self::ENCRYPTION_MODES, true ) ? $encryption : 'ssl' );

        $settings->set( 'smtp_auth', self::checkbox( 'smtp_auth' ) );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); sanitized below.
        $user = isset( $_POST['smtp_user'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['smtp_user'] ) ) : '';
        $settings->set( 'smtp_user', substr( $user, 0, 191 ) );

        // Password stored in Gr_Secrets (AES-256-GCM encrypted), never in gr_settings option. The password is a raw secret that must reach the secrets manager unmodified; its storage IS the sealing, so no sanitizing layer touches it.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); raw secret by design, sealed by Gr_Secrets.
        $entered_pass = isset( $_POST['smtp_pass'] ) ? (string) wp_unslash( $_POST['smtp_pass'] ) : '';

        if ( 1 === self::checkbox( 'smtp_pass_clear' ) ) {
            Gr_Secrets::forget( Gr_Secrets::SMTP_PASS_OPTION );
        } elseif ( '' !== trim( $entered_pass ) ) {
            Gr_Secrets::store( Gr_Secrets::SMTP_PASS_OPTION, $entered_pass );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); sanitize_email runs below.
        $from_email = isset( $_POST['smtp_from_email'] ) ? sanitize_email( (string) wp_unslash( $_POST['smtp_from_email'] ) ) : '';
        $settings->set( 'smtp_from_email', $from_email );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); sanitize_text_field runs below.
        $from_name = isset( $_POST['smtp_from_name'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['smtp_from_name'] ) ) : '';
        $settings->set( 'smtp_from_name', substr( $from_name, 0, 191 ) );

        // 2. Notification Hub Subscriptions
        $settings->set( 'notify_email_enabled', self::checkbox( 'notify_email_enabled' ) );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); parsed and sanitized email array below.
        $raw_recipients   = isset( $_POST['notify_email_recipients'] ) ? (string) wp_unslash( $_POST['notify_email_recipients'] ) : '';
        $recipients_list  = preg_split( '/[\r\n,]+/', $raw_recipients );
        $clean_recipients = array();
        if ( is_array( $recipients_list ) ) {
            foreach ( $recipients_list as $email ) {
                $email = sanitize_email( trim( (string) $email ) );
                if ( '' !== $email && is_email( $email ) ) {
                    $clean_recipients[] = $email;
                }
            }
        }
        $settings->set( 'notify_email_recipients', implode( ', ', array_unique( $clean_recipients ) ) );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); every entry passes sanitize_key and the closed whitelist below.
        $raw_events   = isset( $_POST['notify_email_events'] ) && is_array( $_POST['notify_email_events'] ) ? (array) wp_unslash( $_POST['notify_email_events'] ) : array();
        $clean_events = array();
        foreach ( $raw_events as $event ) {
            $event_str = sanitize_key( (string) $event );
            if ( in_array( $event_str, self::NOTIFICATION_EVENTS, true ) ) {
                $clean_events[] = $event_str;
            }
        }
        $settings->set( 'notify_email_events', array_values( array_unique( $clean_events ) ) );

        // 3. Test Email Trigger
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in may_write(); the button press itself is the owner's word.
        if ( isset( $_POST['send_test_email'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in may_write(); fallback to admin_email.
            $test_to = isset( $_POST['test_email_recipient'] ) ? sanitize_email( (string) wp_unslash( $_POST['test_email_recipient'] ) ) : '';
            if ( '' === $test_to || ! is_email( $test_to ) ) {
                $test_to = (string) get_option( 'admin_email', 'admin@example.com' );
            }
            $result = Gr_Smtp_Manager::test_connection( $test_to );
            set_transient( 'gr_smtp_test_result_' . get_current_user_id(), $result, 60 );
        }
    }

    /**
     * Page output: tab bar plus the active tab's form.
     *
     * @return void
     */
    public static function render(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch on an owner-gated screen.
        $tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : self::TAB_GENERAL;
        if ( ! in_array( $tab, array( self::TAB_GENERAL, self::TAB_SECURITY, self::TAB_ATTRIBUTION, self::TAB_NOTIFICATIONS ), true ) ) {
            $tab = self::TAB_GENERAL;
        }

        $settings = new Gr_Settings();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only PRG flag on an owner-gated screen.
        $saved = isset( $_GET['gr_saved'] ) ? 1 : 0;

        // The one-shot test verdict rides the redirected GET only:
        // the PRG flow's POST-side render (the response the browser
        // never shows) also reaches this code, and a naked
        // read-and-delete there would consume the verdict before the
        // gr_saved GET can display it. The flag is what separates
        // the two renders.
        $test_result = null;
        if ( $saved ) {
            $test_result = get_transient( 'gr_smtp_test_result_' . get_current_user_id() );
            if ( false !== $test_result && is_array( $test_result ) ) {
                delete_transient( 'gr_smtp_test_result_' . get_current_user_id() );
            } else {
                $test_result = null;
            }
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Settings', 'greenpng' ); ?></h1>
            <hr class="wp-header-end" />

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Settings saved.', 'greenpng' ); ?></p></div>
            <?php endif; ?>

            <?php if ( null !== $test_result ) : ?>
                <?php $is_success = ! empty( $test_result['success'] ); ?>
                <div class="notice notice-<?php echo $is_success ? 'success' : 'error'; ?> is-dismissible">
                    <p><strong><?php echo esc_html__( 'SMTP Test Result:', 'greenpng' ); ?></strong> <?php echo esc_html( (string) ( $test_result['message'] ?? '' ) ); ?></p>
                </div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper">
                <?php
                $tabs = array(
                    self::TAB_GENERAL       => __( 'General', 'greenpng' ),
                    self::TAB_SECURITY      => __( 'Security', 'greenpng' ),
                    self::TAB_ATTRIBUTION   => __( 'Attribution', 'greenpng' ),
                    self::TAB_NOTIFICATIONS => __( 'Notifications & SMTP', 'greenpng' ),
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
                    <?php elseif ( self::TAB_ATTRIBUTION === $tab ) : ?>
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
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Cart recovery', 'greenpng' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="cart_recovery_enabled" value="1" <?php checked( 1, (int) $settings->get( 'cart_recovery_enabled' ) ); ?> />
                                    <?php echo esc_html__( 'Offer shoppers a recovery link by mail when a consented checkout is left behind (off by default; the mail rides this site\'s own wp_mail, never a third-party service).', 'greenpng' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gr-cart-delay"><?php echo esc_html__( 'Recovery delay', 'greenpng' ); ?></label></th>
                            <td>
                                <input type="number" name="cart_recovery_delay" id="gr-cart-delay" class="small-text" min="<?php echo esc_attr( (string) Gr_Cart_Recovery::DELAY_MIN ); ?>" max="<?php echo esc_attr( (string) Gr_Cart_Recovery::DELAY_MAX ); ?>"
                                    value="<?php echo esc_attr( (string) (int) $settings->get( 'cart_recovery_delay' ) ); ?>" />
                                <?php echo esc_html__( 'minutes after capture before the abandonment check runs (5 to 120, default 15).', 'greenpng' ); ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gr-cart-subject"><?php echo esc_html__( 'Recovery mail subject', 'greenpng' ); ?></label></th>
                            <td>
                                <input type="text" name="cart_recovery_subject" id="gr-cart-subject" class="regular-text"
                                    value="<?php echo esc_attr( (string) $settings->get( 'cart_recovery_subject' ) ); ?>" />
                                <p class="description"><?php echo esc_html__( '{site} becomes the site name; empty falls back to the default subject.', 'greenpng' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gr-cart-template"><?php echo esc_html__( 'Recovery mail body', 'greenpng' ); ?></label></th>
                            <td>
                                <textarea name="cart_recovery_template" id="gr-cart-template" class="large-text code" rows="6" cols="50"><?php echo esc_textarea( (string) get_option( Gr_Cart_Recovery::TEMPLATE_OPTION, '' ) ); ?></textarea>
                                <p class="description">
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            /* translators: 1: required link placeholders, 2: available placeholders, 3: length ceiling. */
                                            __( 'Both links are required: %1$s. Also available: %2$s. At most %3$d characters; empty or invalid falls back to the built-in default. Basic markup only.', 'greenpng' ),
                                            '{recover_url} {unsubscribe}',
                                            '{site} {items} {total}',
                                            Gr_Cart_Recovery::TEMPLATE_MAX
                                        )
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                    <?php elseif ( self::TAB_NOTIFICATIONS === $tab ) : ?>
                        <!-- Section 1: Built-in SMTP Dispatcher -->
                        <tr>
                            <th colspan="2" style="padding-top: 10px; padding-bottom: 5px;">
                                <h2 style="margin: 0; font-size: 1.2em; font-weight: 600; color: #1d2327;">
                                    <?php echo esc_html__( 'Built-in SMTP Engine (Native Dispatcher)', 'greenpng' ); ?>
                                </h2>
                                <p class="description" style="margin-top: 4px; font-weight: normal;">
                                    <?php echo esc_html__( 'GreenPNG intercepts WordPress wp_mail via the native phpmailer_init hook. You do NOT need to install any external SMTP plugins (such as WP Mail SMTP).', 'greenpng' ); ?>
                                </p>
                            </th>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Enable SMTP dispatch', 'greenpng' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="smtp_enabled" value="1" <?php checked( 1, (int) $settings->get( 'smtp_enabled' ) ); ?> />
                                    <?php echo esc_html__( 'Use built-in SMTP to deliver all outgoing emails from this WordPress site.', 'greenpng' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gr-smtp-host"><?php echo esc_html__( 'SMTP Host', 'greenpng' ); ?></label></th>
                            <td>
                                <input type="text" name="smtp_host" id="gr-smtp-host" class="regular-text"
                                    placeholder="smtp.example.com"
                                    value="<?php echo esc_attr( (string) $settings->get( 'smtp_host' ) ); ?>" />
                                <p class="description"><?php echo esc_html__( 'Hostname of your SMTP provider (e.g., smtp.gmail.com, smtp.feishu.cn, smtp.office365.com).', 'greenpng' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gr-smtp-port"><?php echo esc_html__( 'SMTP Port', 'greenpng' ); ?></label></th>
                            <td>
                                <input type="number" name="smtp_port" id="gr-smtp-port" class="small-text" min="1" max="65535"
                                    value="<?php echo esc_attr( (string) (int) $settings->get( 'smtp_port' ) ); ?>" />
                                <p class="description"><?php echo esc_html__( 'Common ports: 465 (SSL/TLS), 587 (STARTTLS), 25 (Plain).', 'greenpng' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gr-smtp-encryption"><?php echo esc_html__( 'Encryption', 'greenpng' ); ?></label></th>
                            <td>
                                <select name="smtp_encryption" id="gr-smtp-encryption">
                                    <option value="ssl" <?php selected( 'ssl', (string) $settings->get( 'smtp_encryption' ) ); ?>>SSL / TLS (Port 465 recommended)</option>
                                    <option value="tls" <?php selected( 'tls', (string) $settings->get( 'smtp_encryption' ) ); ?>>STARTTLS (Port 587 recommended)</option>
                                    <option value="none" <?php selected( 'none', (string) $settings->get( 'smtp_encryption' ) ); ?>>None / Plain (Port 25)</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Authentication', 'greenpng' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="smtp_auth" value="1" <?php checked( 1, (int) $settings->get( 'smtp_auth' ) ); ?> />
                                    <?php echo esc_html__( 'Enable SMTP Authentication (required by almost all mail services).', 'greenpng' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gr-smtp-user"><?php echo esc_html__( 'SMTP Username', 'greenpng' ); ?></label></th>
                            <td>
                                <input type="text" name="smtp_user" id="gr-smtp-user" class="regular-text" autocomplete="off"
                                    value="<?php echo esc_attr( (string) $settings->get( 'smtp_user' ) ); ?>" />
                                <p class="description"><?php echo esc_html__( 'Your mail account username or full email address.', 'greenpng' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gr-smtp-pass"><?php echo esc_html__( 'SMTP Password', 'greenpng' ); ?></label></th>
                            <td>
                                <?php
                                $has_pass = '' !== Gr_Secrets::reveal( Gr_Secrets::SMTP_PASS_OPTION );
                                ?>
                                <input type="password" name="smtp_pass" id="gr-smtp-pass" class="regular-text" autocomplete="new-password"
                                    placeholder="<?php echo $has_pass ? esc_attr( (string) __( '●●●●●●●● (Password saved, leave empty to keep unchanged)', 'greenpng' ) ) : ''; ?>" />
                                <?php if ( $has_pass ) : ?>
                                    <label style="margin-left: 10px;">
                                        <input type="checkbox" name="smtp_pass_clear" value="1" />
                                        <?php echo esc_html__( 'Clear saved password', 'greenpng' ); ?>
                                    </label>
                                <?php endif; ?>
                                <p class="description"><?php echo esc_html__( 'Encrypted via AES-256-GCM and stored securely in protected storage.', 'greenpng' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gr-smtp-from-email"><?php echo esc_html__( 'From Email', 'greenpng' ); ?></label></th>
                            <td>
                                <input type="email" name="smtp_from_email" id="gr-smtp-from-email" class="regular-text"
                                    placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>"
                                    value="<?php echo esc_attr( (string) $settings->get( 'smtp_from_email' ) ); ?>" />
                                <p class="description"><?php echo esc_html__( 'The sender email address. Empty falls back to WordPress admin email.', 'greenpng' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gr-smtp-from-name"><?php echo esc_html__( 'From Name', 'greenpng' ); ?></label></th>
                            <td>
                                <input type="text" name="smtp_from_name" id="gr-smtp-from-name" class="regular-text"
                                    placeholder="<?php echo esc_attr( (string) get_bloginfo( 'name' ) ); ?>"
                                    value="<?php echo esc_attr( (string) $settings->get( 'smtp_from_name' ) ); ?>" />
                                <p class="description"><?php echo esc_html__( 'The sender display name. Empty falls back to site title.', 'greenpng' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Test Email Dispatch', 'greenpng' ); ?></th>
                            <td>
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <input type="email" name="test_email_recipient" class="regular-text"
                                        placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" />
                                    <button type="submit" name="send_test_email" value="1" class="button button-secondary">
                                        <?php echo esc_html__( 'Send Test Email', 'greenpng' ); ?>
                                    </button>
                                </div>
                                <p class="description"><?php echo esc_html__( 'Saves your changes and sends a test email with diagnostic log if connection fails.', 'greenpng' ); ?></p>
                            </td>
                        </tr>

                        <!-- Section 2: Notification Hub & Event Subscriptions -->
                        <tr>
                            <th colspan="2" style="padding-top: 30px; padding-bottom: 5px;">
                                <h2 style="margin: 0; font-size: 1.2em; font-weight: 600; color: #1d2327;">
                                    <?php echo esc_html__( 'Modular Notification Hub (Event Subscriptions)', 'greenpng' ); ?>
                                </h2>
                                <p class="description" style="margin-top: 4px; font-weight: normal;">
                                    <?php echo esc_html__( 'Subscribe channels to critical system events. Dispatched asynchronously via background queue with sensitive PII automatically masked.', 'greenpng' ); ?>
                                </p>
                            </th>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Enable email notifications', 'greenpng' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="notify_email_enabled" value="1" <?php checked( 1, (int) $settings->get( 'notify_email_enabled' ) ); ?> />
                                    <?php echo esc_html__( 'Send instant email notifications to subscribers when selected events occur.', 'greenpng' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gr-notify-recipients"><?php echo esc_html__( 'Notification Recipients', 'greenpng' ); ?></label></th>
                            <td>
                                <input type="text" name="notify_email_recipients" id="gr-notify-recipients" class="large-text"
                                    placeholder="owner@example.com, alerts@example.com"
                                    value="<?php echo esc_attr( (string) $settings->get( 'notify_email_recipients' ) ); ?>" />
                                <p class="description"><?php echo esc_html__( 'Comma-separated recipient email addresses who will receive alerts.', 'greenpng' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Subscribed Events', 'greenpng' ); ?></th>
                            <td>
                                <?php
                                $subscribed_events = (array) $settings->get( 'notify_email_events' );
                                ?>
                                <fieldset>
                                    <legend class="screen-reader-text"><span><?php echo esc_html__( 'Subscribed Events', 'greenpng' ); ?></span></legend>
                                    <label style="display: block; margin-bottom: 8px;">
                                        <input type="checkbox" name="notify_email_events[]" value="conversion" <?php checked( in_array( 'conversion', $subscribed_events, true ) ); ?> />
                                        <strong><?php echo esc_html__( 'Order & Conversion', 'greenpng' ); ?></strong>
                                        <span class="description"> &mdash; <?php echo esc_html__( 'Triggered when an order is completed or a high-value conversion is registered.', 'greenpng' ); ?></span>
                                    </label>
                                    <label style="display: block; margin-bottom: 8px;">
                                        <input type="checkbox" name="notify_email_events[]" value="lead" <?php checked( in_array( 'lead', $subscribed_events, true ) ); ?> />
                                        <strong><?php echo esc_html__( 'Lead & Contact Submission', 'greenpng' ); ?></strong>
                                        <span class="description"> &mdash; <?php echo esc_html__( 'Triggered when a visitor submits a contact or inquiry form.', 'greenpng' ); ?></span>
                                    </label>
                                    <label style="display: block; margin-bottom: 8px;">
                                        <input type="checkbox" name="notify_email_events[]" value="security_lockout" <?php checked( in_array( 'security_lockout', $subscribed_events, true ) ); ?> />
                                        <strong><?php echo esc_html__( 'Security Lockout', 'greenpng' ); ?></strong>
                                        <span class="description"> &mdash; <?php echo esc_html__( 'Triggered when an abusive IP or brute-force attempt is banned/locked out.', 'greenpng' ); ?></span>
                                    </label>
                                </fieldset>
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
