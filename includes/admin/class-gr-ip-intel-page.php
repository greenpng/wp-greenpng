<?php
/**
 * IP Intelligence page (docs/13 U16, docs/06 §1, docs/07 §5.7, docs/19
 * V7): the GeoIP management surface — attribution disclosure, the live
 * data version, and the one explicit update button whose click is the
 * only thing that ever sends a request to db-ip.com. Nothing on the
 * page dispatches by itself: the button enqueues the refresh job, the
 * job owns the download. The v1.2 credential blocks (AbuseIPDB,
 * MaxMind) follow the Analytics page's storage contract: keys ride
 * Gr_Secrets encrypted in autoload=no options, never echo back, and
 * the local self-check reports state words, never values.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Abuseipdb;
use GreenPNG\Core\Gr_Dch_Refresh;
use GreenPNG\Core\Gr_Geoip;
use GreenPNG\Core\Gr_Geoip_Refresh;
use GreenPNG\Core\Gr_Ip_Quality;
use GreenPNG\Core\Gr_Maxmind_Refresh;
use GreenPNG\Core\Gr_Secrets;
use GreenPNG\Core\Gr_Settings;
use GreenPNG\Core\Gr_Spider_Segments;
use GreenPNG\Core\Gr_Spiders_Refresh;
use GreenPNG\Storage\Gr_Audit_Repository;

/**
 * Read-and-one-write GeoIP management page, plus the v1.2 credential
 * blocks for the opt-in threat-intelligence and MaxMind services.
 */
final class Gr_Ip_Intel_Page {

    /** Menu slug under the top-level menu, Integrations section. */
    public const SLUG = 'greenpng-ipintel';

    /** Nonce action for the update button. */
    public const NONCE_ACTION = 'gr-ipintel-update';

    /** Nonce field name for the update button. */
    public const NONCE_FIELD = '_gr_ipintel_nonce';

    /** POST action: queue the data refresh. */
    public const ACTION_UPDATE = 'update';

    /** Nonce action for the datacenter-range button. */
    public const NONCE_ACTION_DCH = 'gr-ipintel-dch-update';

    /** Nonce field name for the datacenter-range button. */
    public const NONCE_FIELD_DCH = '_gr_ipintel_dch_nonce';

    /** POST action: queue the datacenter-range refresh. */
    public const ACTION_DCH = 'dch_update';

    /** Nonce action for the credential arms; the gate is the page, not the button. */
    public const NONCE_ACTION_CREDS = 'gr-ipintel-creds';

    /** Nonce field name for the credential arms. */
    public const NONCE_FIELD_CREDS = '_gr_ipintel_creds_nonce';

    /** POST action: save the AbuseIPDB block. */
    public const ACTION_SAVE_ABUSEIPDB = 'save_abuseipdb';

    /** POST action: save the MaxMind block. */
    public const ACTION_SAVE_MAXMIND = 'save_maxmind';

    /** POST action: local self-check of the AbuseIPDB block. */
    public const ACTION_CHECK_ABUSEIPDB = 'check_abuseipdb';

    /** POST action: local self-check of the MaxMind block. */
    public const ACTION_CHECK_MAXMIND = 'check_maxmind';

    /** POST action: queue one GeoLite2-Country download. */
    public const ACTION_MAXMIND_DOWNLOAD = 'download_maxmind';

    /** POST action: remove the downloaded GeoLite2 database. */
    public const ACTION_MAXMIND_REMOVE = 'remove_maxmind';

    /** Secret option names, owned by Gr_Secrets; the page only mirrors them. */
    public const ABUSEIPDB_KEY_OPTION = Gr_Secrets::ABUSEIPDB_KEY_OPTION;
    public const MAXMIND_KEY_OPTION   = Gr_Secrets::MAXMIND_KEY_OPTION;

    /** Nonce action for the spider-segment arms; the gate is the page, not the button. */
    public const NONCE_ACTION_SPIDERS = 'gr-ipintel-spiders';

    /** Nonce field name for the spider-segment arms. */
    public const NONCE_FIELD_SPIDERS = '_gr_ipintel_spiders_nonce';

    /** POST action: queue the spider-segment refresh. */
    public const ACTION_SPIDERS_REFRESH = 'spiders_refresh';

    /** POST action: save the weekly spider-segment schedule toggle. */
    public const ACTION_SPIDERS_SCHEDULE = 'spiders_schedule';

    /**
     * Registers the write arm; the menu entry lives in Gr_Admin_Menu.
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( 'admin_init', array( self::class, 'handle_actions' ) );
    }

    /**
     * The gated write arms: capability and nonce both required. The
     * refresh arms enqueue one job each — the wire call itself happens
     * inside the job, never in this request; the credential arms stay
     * local: Gr_Secrets storage, a settings write, and one audit row.
     *
     * @return void
     */
    public static function handle_actions(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which action was posted, before the capability and nonce gates that follow immediately.
        if ( ! isset( $_POST['gr_ipintel_action'] ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which action was posted, before the capability and nonce gates that follow immediately.
        $action = sanitize_key( wp_unslash( $_POST['gr_ipintel_action'] ) );
        if ( ! self::is_write_action( $action ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( self::ACTION_UPDATE === $action ) {
            $nonce_ok = check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );
        } elseif ( self::ACTION_DCH === $action ) {
            $nonce_ok = check_admin_referer( self::NONCE_ACTION_DCH, self::NONCE_FIELD_DCH );
        } elseif ( self::ACTION_SPIDERS_REFRESH === $action || self::ACTION_SPIDERS_SCHEDULE === $action ) {
            $nonce_ok = check_admin_referer( self::NONCE_ACTION_SPIDERS, self::NONCE_FIELD_SPIDERS );
        } else {
            $nonce_ok = check_admin_referer( self::NONCE_ACTION_CREDS, self::NONCE_FIELD_CREDS );
        }

        if ( ! $nonce_ok ) {
            // Core's real check_admin_referer terminates here; the
            // stub records the verdict and returns, so nothing below
            // may run in either world.
            return;
        }

        if ( self::ACTION_SAVE_ABUSEIPDB === $action || self::ACTION_SAVE_MAXMIND === $action ) {
            self::save_credentials( self::ACTION_SAVE_ABUSEIPDB === $action ? 'abuseipdb' : 'maxmind' );

            return;
        }

        if ( self::ACTION_CHECK_ABUSEIPDB === $action || self::ACTION_CHECK_MAXMIND === $action ) {
            self::check_credentials( self::ACTION_CHECK_ABUSEIPDB === $action ? 'abuseipdb' : 'maxmind' );

            return;
        }

        if ( self::ACTION_MAXMIND_DOWNLOAD === $action ) {
            self::queue_maxmind_download();

            return;
        }

        if ( self::ACTION_MAXMIND_REMOVE === $action ) {
            self::remove_maxmind_database();

            return;
        }

        if ( self::ACTION_SPIDERS_SCHEDULE === $action ) {
            self::save_spiders_schedule();

            return;
        }

        if ( self::ACTION_SPIDERS_REFRESH === $action ) {
            self::queue_spiders_refresh();

            return;
        }

        $queued = self::ACTION_UPDATE === $action ? Gr_Geoip_Refresh::enqueue() : Gr_Dch_Refresh::enqueue();

        // The click itself is the authorization for the outbound, so
        // it lands in the audit trail with the acting user; the diff
        // states whether this click queued a job or found one
        // already pending.
        $audit = new Gr_Audit_Repository();
        $audit->log(
            'update_requested',
            self::ACTION_UPDATE === $action ? 'geoip_data' : 'ip_quality_data',
            self::ACTION_UPDATE === $action ? 'dbip_country' : 'cloud_segments',
            array( 'queued' => 'no' ),
            array( 'queued' => $queued ? 'yes' : 'already_pending' ),
            get_current_user_id()
        );

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'      => self::SLUG,
                    'gr_update' => $queued ? 'queued' : 'pending',
                ),
                admin_url( 'admin.php' )
            )
        );
    }

    /**
     * Whether an action value belongs to this page's write arms;
     * anything else in the field is not this page's business.
     *
     * @param string $action Candidate action.
     * @return bool
     */
    private static function is_write_action( string $action ): bool {
        return in_array(
            $action,
            array(
                self::ACTION_UPDATE,
                self::ACTION_DCH,
                self::ACTION_SAVE_ABUSEIPDB,
                self::ACTION_SAVE_MAXMIND,
                self::ACTION_CHECK_ABUSEIPDB,
                self::ACTION_CHECK_MAXMIND,
                self::ACTION_MAXMIND_DOWNLOAD,
                self::ACTION_MAXMIND_REMOVE,
                self::ACTION_SPIDERS_REFRESH,
                self::ACTION_SPIDERS_SCHEDULE,
            ),
            true
        );
    }

    /**
     * The weekly-schedule arm: the opt-in is the only thing that can
     * arm the recurring cron, and the write is what registers or
     * cancels it — no other path schedules the subscription. The
     * audit diff carries the toggle state words, never a schedule
     * timestamp.
     *
     * @return void
     */
    private static function save_spiders_schedule(): void {
        $settings = new Gr_Settings();
        $before   = array( 'weekly' => (int) $settings->get( Gr_Spiders_Refresh::TOGGLE ) );
        $after    = array( 'weekly' => self::checkbox( Gr_Spiders_Refresh::TOGGLE ) );

        if ( $before !== $after ) {
            $settings->set( Gr_Spiders_Refresh::TOGGLE, $after['weekly'] );
            Gr_Spiders_Refresh::set_weekly( $after['weekly'] );

            ( new Gr_Audit_Repository() )->log(
                'save',
                'ip_intel',
                'spiders',
                $before,
                $after,
                get_current_user_id()
            );
        }

        wp_safe_redirect( self::page_url( array( 'gr_saved' => 'spiders' ) ) );
    }

    /**
     * The manual refresh arm: one click, one queued job; the wire
     * call itself happens inside the job. The click itself is the
     * authorization for the outbound, so it lands in the audit
     * trail with the acting user.
     *
     * @return void
     */
    private static function queue_spiders_refresh(): void {
        $queued = Gr_Spiders_Refresh::enqueue();

        ( new Gr_Audit_Repository() )->log(
            'update_requested',
            'spider_segments',
            'spider_segments',
            array( 'queued' => 'no' ),
            array( 'queued' => $queued ? 'yes' : 'already_pending' ),
            get_current_user_id()
        );

        wp_safe_redirect( self::page_url( array( 'gr_spiders' => $queued ? 'queued' : 'pending' ) ) );
    }

    /**
     * The GeoLite2 download arm: one click, one queued job. The
     * license key is read here only to refuse honestly when the owner
     * removed it between saving and clicking; the key itself rides
     * inside the job, never the audit row or the redirect.
     *
     * @return void
     */
    private static function queue_maxmind_download(): void {
        if ( '' === Gr_Secrets::reveal( self::MAXMIND_KEY_OPTION ) ) {
            wp_safe_redirect( self::page_url( array( 'gr_error' => 'maxmind_key' ) ) );

            return;
        }

        $queued = Gr_Maxmind_Refresh::enqueue();

        $audit = new Gr_Audit_Repository();
        $audit->log(
            'update_requested',
            'geoip_data',
            'maxmind_country',
            array( 'queued' => 'no' ),
            array( 'queued' => $queued ? 'yes' : 'already_pending' ),
            get_current_user_id()
        );

        wp_safe_redirect( self::page_url( array( 'gr_update' => $queued ? 'queued' : 'pending' ) ) );
    }

    /**
     * The removal arm: the one way back to the DB-IP sources once a
     * database is downloaded. The published file is this feature's
     * own uploads artifact, and the audit states presence in words.
     *
     * @return void
     */
    private static function remove_maxmind_database(): void {
        $file   = Gr_Geoip::maxmind_file();
        $before = array( 'database' => is_readable( $file ) ? 'present' : 'absent' );

        if ( is_file( $file ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing this feature's own published uploads artifact, on the owner's explicit click.
            unlink( $file );
            Gr_Geoip::reset_for_tests();
        }

        $after = array( 'database' => is_readable( $file ) ? 'present' : 'absent' );

        if ( $before !== $after ) {
            ( new Gr_Audit_Repository() )->log(
                'save',
                'ip_intel',
                'maxmind',
                $before,
                $after,
                get_current_user_id()
            );
        }

        wp_safe_redirect( self::page_url( array( 'gr_maxmind' => 'removed' ) ) );
    }

    /**
     * Saves one credential block: the key into Gr_Secrets and, when
     * the block owns a toggle, the opt-in into gr_settings. An empty
     * field means "unchanged" — saving the form without retyping the
     * key must never blank it; the remove checkbox is the only way
     * out and takes the toggle down with the key. A bad shape is
     * refused whole: nothing is stored, the error flag names the
     * field. The audit diff carries state words, never values.
     *
     * @param string $service 'abuseipdb' or 'maxmind'.
     * @return void
     */
    private static function save_credentials( string $service ): void {
        $settings = new Gr_Settings();
        $before   = self::credential_snapshot( $service );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in handle_actions(); the remove flag is a strict '1' comparison.
        $remove = isset( $_POST[ $service . '_remove' ] ) && '1' === (string) wp_unslash( $_POST[ $service . '_remove' ] );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in handle_actions(); the value is shape-checked below before any store().
        $key_field = isset( $_POST[ $service . '_key' ] ) ? trim( (string) wp_unslash( $_POST[ $service . '_key' ] ) ) : '';

        $toggle_key = self::toggle_key( $service );

        if ( $remove ) {
            Gr_Secrets::forget( self::key_option( $service ) );
            if ( '' !== $toggle_key ) {
                $settings->set( $toggle_key, 0 );
            }
        } else {
            if ( '' !== $key_field ) {
                if ( ! self::valid_key( $service, $key_field ) ) {
                    wp_safe_redirect( self::page_url( array( 'gr_error' => $service . '_key' ) ) );

                    return;
                }

                Gr_Secrets::store( self::key_option( $service ), $key_field );
            }

            if ( '' !== $toggle_key ) {
                $settings->set( $toggle_key, self::checkbox( $toggle_key ) );
            }
        }

        $after = self::credential_snapshot( $service );

        if ( $before !== $after ) {
            ( new Gr_Audit_Repository() )->log(
                'save',
                'ip_intel',
                $service,
                $before,
                $after,
                get_current_user_id()
            );
        }

        wp_safe_redirect( self::page_url( array( 'gr_saved' => $service ) ) );
    }

    /**
     * Local self-check of one credential block: does the stored key
     * decrypt back, and does it hold the expected shape. Reports
     * words, never values.
     *
     * @param string $service 'abuseipdb' or 'maxmind'.
     * @return void
     */
    private static function check_credentials( string $service ): void {
        wp_safe_redirect(
            self::page_url(
                array(
                    'gr_check'  => $service,
                    'gr_result' => self::local_check( $service ),
                )
            )
        );
    }

    /**
     * The local check battery: absent (nothing stored), broken (an
     * envelope that no longer decrypts — a changed salt or a mangled
     * row), shape (readable but not a plausible key), or ok.
     *
     * @param string $service 'abuseipdb' or 'maxmind'.
     * @return string Result word.
     */
    private static function local_check( string $service ): string {
        $option   = self::key_option( $service );
        $envelope = get_option( $option, '' );
        if ( ! is_string( $envelope ) || '' === $envelope ) {
            return 'absent';
        }

        $key = Gr_Secrets::reveal( $option );
        if ( '' === $key ) {
            return 'broken';
        }
        if ( ! self::valid_key( $service, $key ) ) {
            return 'shape';
        }

        return 'ok';
    }

    /**
     * Shape gate for the two license keys: opaque alphanumeric and
     * long enough that a pasted fragment cannot pass as the whole
     * key (AbuseIPDB issues 80-character keys, MaxMind 16).
     *
     * @param string $service 'abuseipdb' or 'maxmind'.
     * @param string $value   Candidate key.
     * @return bool
     */
    private static function valid_key( string $service, string $value ): bool {
        if ( 'abuseipdb' === $service ) {
            return 1 === preg_match( '/^[A-Za-z0-9]{20,200}$/', $value );
        }

        return 1 === preg_match( '/^[A-Za-z0-9]{8,64}$/', $value );
    }

    /**
     * Option name of one block's key, mirrored from Gr_Secrets.
     *
     * @param string $service 'abuseipdb' or 'maxmind'.
     * @return string
     */
    private static function key_option( string $service ): string {
        return 'abuseipdb' === $service ? self::ABUSEIPDB_KEY_OPTION : self::MAXMIND_KEY_OPTION;
    }

    /**
     * The settings toggle behind one block, '' when the block's only
     * gate is the credential itself: the AbuseIPDB client runs on an
     * explicit opt-in, while the MaxMind license key unlocks a
     * download whose consumption is governed by the data-source
     * settings, so that block owns no toggle here.
     *
     * @param string $service 'abuseipdb' or 'maxmind'.
     * @return string
     */
    private static function toggle_key( string $service ): string {
        return 'abuseipdb' === $service ? 'abuseipdb_enabled' : '';
    }

    /**
     * One key's state word.
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
     * The state words for the audit diff: key configured-ness and,
     * when the block owns a toggle, the toggle — as a vocabulary,
     * never the value.
     *
     * @param string $service 'abuseipdb' or 'maxmind'.
     * @return array<string, string|int>
     */
    private static function credential_snapshot( string $service ): array {
        $toggle_key = self::toggle_key( $service );
        $snapshot   = array( 'key' => self::credential_word( self::key_option( $service ) ) );

        if ( '' !== $toggle_key ) {
            $snapshot['enabled'] = (int) ( new Gr_Settings() )->get( $toggle_key );
        }

        return $snapshot;
    }

    /**
     * Reads a checkbox the way unchecked boxes POST: absent means 0.
     *
     * @param string $key POST key.
     * @return int 1 or 0.
     */
    private static function checkbox( string $key ): int {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in handle_actions(); value is a strict '1' comparison.
        return isset( $_POST[ $key ] ) && '1' === (string) wp_unslash( $_POST[ $key ] ) ? 1 : 0;
    }

    /**
     * Page URL with added query flags, for the post-redirect-get of
     * the credential arms.
     *
     * @param array<string, string> $args Query additions.
     * @return string
     */
    private static function page_url( array $args ): string {
        return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
    }

    /**
     * Renders the page: disclosure, data version, the update button,
     * the result notices of the previous clicks, and the two v1.2
     * credential blocks.
     *
     * @return void
     */
    public static function render(): void {
        $state   = Gr_Geoip::state();
        $pending = false !== get_transient( Gr_Geoip_Refresh::PENDING );

        $dch         = Gr_Ip_Quality::describe();
        $dch_source  = Gr_Ip_Quality::source();
        $dch_pending = false !== get_transient( Gr_Dch_Refresh::PENDING );

        $spiders         = Gr_Spider_Segments::status();
        $spiders_pending = false !== get_transient( Gr_Spiders_Refresh::PENDING );
        $spiders_weekly  = (int) ( new Gr_Settings() )->get( Gr_Spiders_Refresh::TOGGLE );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- PRG result flags are words from this page's own redirects, read-only display.
        $result = isset( $_GET['gr_update'] ) ? sanitize_key( wp_unslash( $_GET['gr_update'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- PRG result flags are words from this page's own redirects, read-only display.
        $saved = isset( $_GET['gr_saved'] ) ? sanitize_key( wp_unslash( $_GET['gr_saved'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- PRG result flags are words from this page's own redirects, read-only display.
        $error = isset( $_GET['gr_error'] ) ? sanitize_key( wp_unslash( $_GET['gr_error'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- PRG result flags are words from this page's own redirects, read-only display.
        $check = isset( $_GET['gr_check'] ) ? sanitize_key( wp_unslash( $_GET['gr_check'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- PRG result flags are words from this page's own redirects, read-only display.
        $check_result = isset( $_GET['gr_result'] ) ? sanitize_key( wp_unslash( $_GET['gr_result'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- PRG result flags are words from this page's own redirects, read-only display.
        $spiders_result = isset( $_GET['gr_spiders'] ) ? sanitize_key( wp_unslash( $_GET['gr_spiders'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- PRG result flags are words from this page's own redirects, read-only display.
        $maxmind_flag = isset( $_GET['gr_maxmind'] ) ? sanitize_key( wp_unslash( $_GET['gr_maxmind'] ) ) : '';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'IP Intelligence', 'greenpng' ); ?></h1>

            <?php if ( 'queued' === $result ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html__( 'Data refresh queued. The version below updates once the download completes.', 'greenpng' ); ?></p></div>
            <?php elseif ( 'pending' === $result ) : ?>
                <div class="notice notice-warning"><p><?php echo esc_html__( 'A data refresh is already queued.', 'greenpng' ); ?></p></div>
            <?php endif; ?>

            <?php if ( 'queued' === $spiders_result ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html__( 'Segment refresh queued. The table below updates once the download completes.', 'greenpng' ); ?></p></div>
            <?php elseif ( 'pending' === $spiders_result ) : ?>
                <div class="notice notice-warning"><p><?php echo esc_html__( 'A spider-segment refresh is already queued.', 'greenpng' ); ?></p></div>
            <?php endif; ?>

            <?php if ( 'removed' === $maxmind_flag ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'The downloaded database was removed; country lookups fall back to the DB-IP set.', 'greenpng' ); ?></p></div>
            <?php endif; ?>

            <?php if ( '' !== $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %s: service name, abuseipdb or maxmind. */
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
                            /* translators: 1: service name, 2: check result word. */
                            __( 'Local self-check (%1$s): %2$s.', 'greenpng' ),
                            $check,
                            '' === $check_result ? 'unknown' : $check_result
                        )
                    );
                    ?>
                </p></div>
            <?php endif; ?>

            <h2><?php echo esc_html__( 'Country database', 'greenpng' ); ?></h2>
            <p><?php echo esc_html__( 'Country-level lookups run entirely on this site; no lookup ever leaves the server. A GeoLite2-Country database you downloaded below serves while it is published, otherwise the bundled DB-IP Lite database answers, with an owner-refreshed copy winning over the bundled one.', 'greenpng' ); ?></p>

            <?php if ( ! $state['available'] ) : ?>
                <p><?php echo esc_html__( 'No local database found. Reinstalling the plugin restores the bundled copy.', 'greenpng' ); ?></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:520px;">
                    <tbody>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Data origin', 'greenpng' ); ?></th>
                            <td>
                                <?php
                                if ( 'maxmind' === $state['source'] ) {
                                    echo esc_html__( 'MaxMind GeoLite2 (uploads)', 'greenpng' );
                                } elseif ( 'override' === $state['source'] ) {
                                    echo esc_html__( 'Owner-refreshed copy (uploads)', 'greenpng' );
                                } else {
                                    echo esc_html__( 'Bundled with the plugin', 'greenpng' );
                                }
                                ?>
                            </td>
                        </tr>
                        <?php if ( 'maxmind' === $state['source'] ) : ?>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Database built', 'greenpng' ); ?></th>
                                <td><?php echo esc_html( (string) $state['build_date'] ); ?></td>
                            </tr>
                        <?php else : ?>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Data date', 'greenpng' ); ?></th>
                                <td><?php echo esc_html( (string) $state['build_date'] ); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'IPv4 ranges', 'greenpng' ); ?></th>
                                <td><?php echo esc_html( number_format( (float) $state['v4_ranges'] ) ); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'IPv6 ranges', 'greenpng' ); ?></th>
                                <td><?php echo esc_html( number_format( (float) $state['v6_ranges'] ) ); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Countries', 'greenpng' ); ?></th>
                                <td><?php echo esc_html( number_format( (float) $state['countries'] ) ); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__( 'Generated', 'greenpng' ); ?></th>
                                <td><?php echo esc_html( (string) $state['generated_at'] ); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2><?php echo esc_html__( 'Attribution', 'greenpng' ); ?></h2>
            <p>
                <?php echo esc_html__( 'Country data by DB-IP, licensed under CC BY 4.0. The full attribution and data date ship in the plugin\'s NOTICE file.', 'greenpng' ); ?>
                <a href="https://creativecommons.org/licenses/by/4.0/"><?php echo esc_html__( 'View the license terms', 'greenpng' ); ?></a>
            </p>
            <p><?php echo esc_html__( 'A refresh downloads from db-ip.com into the uploads directory; it never modifies the plugin directory, and a failed refresh keeps the current data.', 'greenpng' ); ?></p>

            <h2><?php echo esc_html__( 'Update now', 'greenpng' ); ?></h2>
            <?php if ( $pending ) : ?>
                <p><?php echo esc_html__( 'A refresh is already queued; the button returns when it finishes.', 'greenpng' ); ?></p>
            <?php else : ?>
                <form method="post">
                    <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                    <p><?php echo esc_html__( 'Clicking sends one download request to db-ip.com on your explicit instruction. There is no automatic or scheduled update.', 'greenpng' ); ?></p>
                    <button type="submit" class="button button-primary" name="gr_ipintel_action" value="<?php echo esc_attr( self::ACTION_UPDATE ); ?>">
                        <?php echo esc_html__( 'Update country data now', 'greenpng' ); ?>
                    </button>
                </form>
            <?php endif; ?>

            <h2><?php echo esc_html__( 'Datacenter ranges', 'greenpng' ); ?></h2>
            <p><?php echo esc_html__( 'Sessions from known cloud-provider ranges are labelled "hosting" on the traffic reports. The label is a reporting signal only: a hosting address can be a corporate proxy, a compliant crawler, or a real person, so it never marks a session as a bot on its own.', 'greenpng' ); ?></p>

            <?php if ( '' === $dch['built'] ) : ?>
                <p><?php echo esc_html__( 'No local dataset found. Reinstalling the plugin restores the bundled copy.', 'greenpng' ); ?></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:520px;">
                    <tbody>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Data origin', 'greenpng' ); ?></th>
                            <td><?php echo esc_html( 'override' === $dch_source ? __( 'Owner-refreshed copy (uploads)', 'greenpng' ) : __( 'Bundled with the plugin', 'greenpng' ) ); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Data date', 'greenpng' ); ?></th>
                            <td><?php echo esc_html( $dch['built'] ); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'IPv4 ranges', 'greenpng' ); ?></th>
                            <td><?php echo esc_html( number_format( (float) $dch['v4'] ) ); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'IPv6 ranges', 'greenpng' ); ?></th>
                            <td><?php echo esc_html( number_format( (float) $dch['v6'] ) ); ?></td>
                        </tr>
                    </tbody>
                </table>
            <?php endif; ?>

            <p><?php echo esc_html__( 'The bundled list comes from the official AWS, Azure, and Google service segment tables plus IP2Proxy LITE DCH rows (CC BY-SA 4.0; register at lite.ip2location.com to fetch updated copies). The full attribution ships in the plugin\'s NOTICE file.', 'greenpng' ); ?></p>

            <?php if ( $dch_pending ) : ?>
                <p><?php echo esc_html__( 'A datacenter-range refresh is already queued; the button returns when it finishes.', 'greenpng' ); ?></p>
            <?php else : ?>
                <form method="post">
                    <?php wp_nonce_field( self::NONCE_ACTION_DCH, self::NONCE_FIELD_DCH ); ?>
                    <p><?php echo esc_html__( 'Clicking downloads the current official AWS, Azure, and Google segment tables on your explicit instruction; the merged copy lands in the uploads directory and a failed refresh keeps the current data. There is no automatic or scheduled update.', 'greenpng' ); ?></p>
                    <button type="submit" class="button button-primary" name="gr_ipintel_action" value="<?php echo esc_attr( self::ACTION_DCH ); ?>">
                        <?php echo esc_html__( 'Update datacenter ranges now', 'greenpng' ); ?></button>
                </form>
            <?php endif; ?>

            <h2><?php echo esc_html__( 'AbuseIPDB (threat intelligence)', 'greenpng' ); ?></h2>
            <p><?php echo esc_html__( 'Reputation checks send only the connecting address to api.abuseipdb.com, and only after the same address repeatedly failed a login, registration, or form post. Results cache for 24 hours, the provider quota stops the lookups by itself, and the verdict is an advisory signal: it never blocks on its own. Everything stays off without your own API key and an explicit opt-in.', 'greenpng' ); ?></p>
            <?php
            self::render_credential_block(
                'abuseipdb',
                __( 'API key', 'greenpng' ),
                __( 'Created at abuseipdb.com under Account → API keys.', 'greenpng' ),
                __( 'Allow reputation lookups after repeated failures (opt-in)', 'greenpng' )
            );
            self::render_reputation_rows();
            ?>

            <h2><?php echo esc_html__( 'MaxMind GeoLite2 (optional country override)', 'greenpng' ); ?></h2>
            <p><?php echo esc_html__( 'The plugin never bundles a MaxMind database. Enter your license key to enable GeoLite2 downloads; no request leaves this site until you explicitly start one, and country lookups themselves always run locally. A downloaded database serves every lookup while it is published — remove it below to fall back to the DB-IP set.', 'greenpng' ); ?></p>
            <?php
            self::render_credential_block(
                'maxmind',
                __( 'License key', 'greenpng' ),
                __( 'Created at maxmind.com under Account → Manage License Keys. The GeoLite2 EULA forbids redistributing the database.', 'greenpng' )
            );
            self::render_maxmind_data_rows( (array) $state );
            ?>

            <h2><?php echo esc_html__( 'Search-engine spider segments', 'greenpng' ); ?></h2>
            <p><?php echo esc_html__( 'The subscription pulls the official Google, Bing, and Apple crawler segment tables and merges them into a local copy in the uploads directory. A segment hit never proves a crawler on its own: the address must also carry an agent naming that same engine, and even then the match is a confidence signal beside the DNS verdict on the Bot &amp; Device Signals page — never a block.', 'greenpng' ); ?></p>

            <?php if ( '' === $spiders['built'] ) : ?>
                <p><?php echo esc_html__( 'No local segment table yet; a manual refresh or the weekly schedule lands one here.', 'greenpng' ); ?></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:520px;">
                    <tbody>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Data date', 'greenpng' ); ?></th>
                            <td><?php echo esc_html( $spiders['built'] ); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Google segments', 'greenpng' ); ?></th>
                            <td><?php echo esc_html( number_format( (float) $spiders['sources']['google'] ) ); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Bing segments', 'greenpng' ); ?></th>
                            <td><?php echo esc_html( number_format( (float) $spiders['sources']['bing'] ) ); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Apple segments', 'greenpng' ); ?></th>
                            <td><?php echo esc_html( number_format( (float) $spiders['sources']['apple'] ) ); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Total', 'greenpng' ); ?></th>
                            <td><?php echo esc_html( number_format( (float) $spiders['total'] ) ); ?></td>
                        </tr>
                    </tbody>
                </table>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( self::NONCE_ACTION_SPIDERS, self::NONCE_FIELD_SPIDERS ); ?>
                <p>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr( Gr_Spiders_Refresh::TOGGLE ); ?>" value="1" <?php checked( 1, $spiders_weekly ); ?> />
                        <?php echo esc_html__( 'Refresh the segment table weekly (opt-in schedule)', 'greenpng' ); ?>
                    </label>
                </p>
                <p class="description"><?php echo esc_html__( 'The schedule registers while the box stays on and cancels when it goes off; a manual refresh works either way.', 'greenpng' ); ?></p>
                <p>
                    <button type="submit" class="button" name="gr_ipintel_action" value="<?php echo esc_attr( self::ACTION_SPIDERS_SCHEDULE ); ?>">
                        <?php echo esc_html__( 'Save the schedule', 'greenpng' ); ?>
                    </button>
                </p>
            </form>

            <?php if ( $spiders_pending ) : ?>
                <p><?php echo esc_html__( 'A spider-segment refresh is already queued; the button returns when it finishes.', 'greenpng' ); ?></p>
            <?php else : ?>
                <form method="post">
                    <?php wp_nonce_field( self::NONCE_ACTION_SPIDERS, self::NONCE_FIELD_SPIDERS ); ?>
                    <p><?php echo esc_html__( 'Clicking downloads the three official crawler tables on your explicit instruction; the merged copy lands in the uploads directory and a failed refresh keeps the current table.', 'greenpng' ); ?></p>
                    <button type="submit" class="button button-primary" name="gr_ipintel_action" value="<?php echo esc_attr( self::ACTION_SPIDERS_REFRESH ); ?>">
                        <?php echo esc_html__( 'Update spider segments now', 'greenpng' ); ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * The reputation ledger: the recent lookups as one masked row
     * each — the address in its display form, the score, the band
     * word, and the outcome word, never the API key. An armed service
     * with no rows yet says so; a stopped day's budget says so too,
     * because the owner should never wonder why lookups went quiet.
     *
     * @return void
     */
    private static function render_reputation_rows(): void {
        if ( ! Gr_Abuseipdb::armed() ) {
            return;
        }

        $page = ( new Gr_Audit_Repository() )->query( array( 'object_type' => Gr_Abuseipdb::AUDIT_TYPE ), 10 );
        $rows = $page['rows'];
        ?>
        <h3><?php echo esc_html__( 'Recent reputation lookups', 'greenpng' ); ?></h3>
        <?php if ( Gr_Abuseipdb::budget_stopped() ) : ?>
            <p class="description"><?php echo esc_html__( 'The provider quota stopped today&#8217;s lookups; they resume automatically.', 'greenpng' ); ?></p>
        <?php endif; ?>
        <?php if ( array() === $rows ) : ?>
            <p class="description"><?php echo esc_html__( 'No lookups yet: an address has to repeatedly fail a login, a registration, or a form post before the first one is scheduled.', 'greenpng' ); ?></p>
            <?php
            return;
        endif;
        ?>
        <table class="widefat striped" role="presentation">
            <thead>
                <tr>
                    <th><?php echo esc_html__( 'Address', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Score', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Band', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Outcome', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'When', 'greenpng' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows as $row ) : ?>
                    <?php
                    $diff = json_decode( (string) ( $row['diff_json'] ?? '' ), true );
                    $diff = is_array( $diff ) ? $diff : array();
                    ?>
                    <tr>
                        <td><?php echo esc_html( gr_mask_ip( (string) ( $row['object_id'] ?? '' ) ) ); ?></td>
                        <td><?php echo esc_html( (string) ( $diff['added']['score'] ?? '—' ) ); ?></td>
                        <td><?php echo esc_html( (string) ( $diff['added']['band'] ?? '—' ) ); ?></td>
                        <td><?php echo esc_html( (string) ( $diff['added']['result'] ?? '—' ) ); ?></td>
                        <td><?php echo esc_html( (string) ( $row['created_at'] ?? '' ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * The GeoLite2 data rows: the published database's status, the
     * one-click download, and the removal arm that walks the source
     * ladder back to the DB-IP set.
     *
     * @param array<string, mixed> $state The GeoIP serving state.
     * @return void
     */
    private static function render_maxmind_data_rows( array $state ): void {
        $published  = 'maxmind' === (string) $state['source'];
        $pending    = false !== get_transient( Gr_Maxmind_Refresh::PENDING );
        $key_stored = 'stored' === self::credential_word( self::MAXMIND_KEY_OPTION );
        ?>
        <?php if ( $published ) : ?>
            <p>
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %s: the database's build date. */
                        __( 'A GeoLite2-Country database (built %s) is published in the uploads directory and answers every country lookup.', 'greenpng' ),
                        (string) $state['build_date']
                    )
                );
                ?>
            </p>
        <?php endif; ?>

        <?php if ( $pending ) : ?>
            <p><?php echo esc_html__( 'A GeoLite2 download is already queued; the status returns once it finishes.', 'greenpng' ); ?></p>
        <?php elseif ( $key_stored ) : ?>
            <form method="post">
                <?php wp_nonce_field( self::NONCE_ACTION_CREDS, self::NONCE_FIELD_CREDS ); ?>
                <p><?php echo esc_html__( 'Clicking sends one download request to download.maxmind.com on your explicit instruction, with your license key in the query the way MaxMind\'s endpoint requires it. There is no automatic or scheduled download.', 'greenpng' ); ?></p>
                <button type="submit" class="button button-primary" name="gr_ipintel_action" value="<?php echo esc_attr( self::ACTION_MAXMIND_DOWNLOAD ); ?>">
                    <?php echo esc_html__( 'Download GeoLite2-Country now', 'greenpng' ); ?>
                </button>
            </form>
        <?php endif; ?>

        <?php if ( $published ) : ?>
            <form method="post">
                <?php wp_nonce_field( self::NONCE_ACTION_CREDS, self::NONCE_FIELD_CREDS ); ?>
                <p><?php echo esc_html__( 'Removing the downloaded database deletes the one uploads file this feature owns; country lookups fall back to the DB-IP set immediately.', 'greenpng' ); ?></p>
                <button type="submit" class="button" name="gr_ipintel_action" value="<?php echo esc_attr( self::ACTION_MAXMIND_REMOVE ); ?>">
                    <?php echo esc_html__( 'Remove the downloaded database', 'greenpng' ); ?>
                </button>
            </form>
        <?php endif; ?>
        <?php
    }

    /**
     * One credential block: state line (an explicit "not configured"
     * when nothing is stored), a masked preview when configured, the
     * opt-in toggle when the block owns one, a key field that never
     * echoes a stored value back, the remove switch, and the two
     * buttons. Two submit buttons share one name and carry their
     * action as the value, the core-native way to offer more than
     * one action per form.
     *
     * @param string $service      Service key, 'abuseipdb' or 'maxmind'.
     * @param string $key_label    Key field label.
     * @param string $key_note     Key field note.
     * @param string $toggle_label Toggle label, used only when the block owns a toggle.
     * @return void
     */
    private static function render_credential_block( string $service, string $key_label, string $key_note, string $toggle_label = '' ): void {
        $key_word    = self::credential_word( self::key_option( $service ) );
        $toggle_name = self::toggle_key( $service );
        $enabled     = '' === $toggle_name ? 0 : (int) ( new Gr_Settings() )->get( $toggle_name );
        ?>
        <p>
            <?php
            if ( 'stored' === $key_word ) {
                echo esc_html(
                    sprintf(
                        /* translators: %s: masked key preview. */
                        __( 'Key stored (%s). Fields left empty keep the stored key.', 'greenpng' ),
                        Gr_Secrets::mask( Gr_Secrets::reveal( self::key_option( $service ) ) )
                    )
                );
            } elseif ( 'broken' === $key_word ) {
                echo esc_html__( 'The stored key no longer decrypts (a changed salt or a mangled row). Enter it again or remove it.', 'greenpng' );
            } else {
                echo esc_html__( 'Not configured. Nothing is sent for this service and its controls have no effect until a key exists.', 'greenpng' );
            }
            ?>
        </p>

        <form method="post">
            <?php wp_nonce_field( self::NONCE_ACTION_CREDS, self::NONCE_FIELD_CREDS ); ?>
            <input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
            <table class="form-table" role="presentation">
                <?php if ( '' !== $toggle_name ) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Enabled', 'greenpng' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( $toggle_name ); ?>" value="1" <?php checked( 1, $enabled ); ?> />
                                <?php echo esc_html( $toggle_label ); ?>
                            </label>
                        </td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr( $service . '_key' ); ?>"><?php echo esc_html( $key_label ); ?></label></th>
                    <td>
                        <input type="password" id="<?php echo esc_attr( $service . '_key' ); ?>" name="<?php echo esc_attr( $service . '_key' ); ?>" value="" class="regular-text" autocomplete="new-password" />
                        <p class="description"><?php echo esc_html( $key_note ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Remove', 'greenpng' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr( $service . '_remove' ); ?>" value="1" />
                            <?php echo esc_html__( 'Forget the stored key entirely', 'greenpng' ); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary" name="gr_ipintel_action" value="<?php echo esc_attr( 'abuseipdb' === $service ? self::ACTION_SAVE_ABUSEIPDB : self::ACTION_SAVE_MAXMIND ); ?>"><?php echo esc_html__( 'Save', 'greenpng' ); ?></button>
                <button type="submit" class="button" name="gr_ipintel_action" value="<?php echo esc_attr( 'abuseipdb' === $service ? self::ACTION_CHECK_ABUSEIPDB : self::ACTION_CHECK_MAXMIND ); ?>"><?php echo esc_html__( 'Self-check', 'greenpng' ); ?></button>
            </p>
        </form>
        <?php
    }
}
