<?php
/**
 * IP Intelligence page (docs/13 U16, docs/06 §1, docs/07 §5.7): the
 * GeoIP management surface — attribution disclosure, the live data
 * version, and the one explicit update button whose click is the only
 * thing that ever sends a request to db-ip.com. Nothing on the page
 * dispatches by itself: the button enqueues the refresh job, the job
 * owns the download. The AbuseIPDB block arrives with v1.2 and is
 * noted as a roadmap line, not a placeholder control.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Dch_Refresh;
use GreenPNG\Core\Gr_Geoip;
use GreenPNG\Core\Gr_Geoip_Refresh;
use GreenPNG\Core\Gr_Ip_Quality;
use GreenPNG\Storage\Gr_Audit_Repository;

/**
 * Read-and-one-write GeoIP management page.
 */
final class Gr_Ip_Intel_Page {

    /** Menu slug under the top-level menu, Integrations section. */
    public const SLUG = 'greenpng-ipintel';

    /** Nonce action for the update button. */
    public const NONCE_ACTION = 'gr-ipintel-update';

    /** Nonce field name. */
    public const NONCE_FIELD = '_gr_ipintel_nonce';

    /** POST action: queue the data refresh. */
    public const ACTION_UPDATE = 'update';

    /** Nonce action for the datacenter-range button. */
    public const NONCE_ACTION_DCH = 'gr-ipintel-dch-update';

    /** Nonce field name for the datacenter-range button. */
    public const NONCE_FIELD_DCH = '_gr_ipintel_dch_nonce';

    /** POST action: queue the datacenter-range refresh. */
    public const ACTION_DCH = 'dch_update';

    /**
     * Registers the write arm; the menu entry lives in Gr_Admin_Menu.
     *
     * @return void
     */
    public static function register_hooks(): void {
        add_action( 'admin_init', array( self::class, 'handle_actions' ) );
    }

    /**
     * The gated update arms: capability and nonce both required, then
     * one queue dispatch each — the wire call itself happens inside
     * the job, never in this request.
     *
     * @return void
     */
    public static function handle_actions(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which action was posted, before the capability and nonce gates that follow immediately.
        if ( ! isset( $_POST['gr_ipintel_action'] ) ) {
            return;
        }

        $action = sanitize_key( wp_unslash( $_POST['gr_ipintel_action'] ) );
        if ( self::ACTION_UPDATE !== $action && self::ACTION_DCH !== $action ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce_ok = self::ACTION_UPDATE === $action
            ? check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD )
            : check_admin_referer( self::NONCE_ACTION_DCH, self::NONCE_FIELD_DCH );

        if ( ! $nonce_ok ) {
            // Core's real check_admin_referer terminates here; the
            // stub records the verdict and returns, so nothing below
            // may run in either world.
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
     * Renders the page: disclosure, data version, the update button,
     * and the result notice of the previous click.
     *
     * @return void
     */
    public static function render(): void {
        $state   = Gr_Geoip::state();
        $pending = false !== get_transient( Gr_Geoip_Refresh::PENDING );

        $dch         = Gr_Ip_Quality::describe();
        $dch_source  = Gr_Ip_Quality::source();
        $dch_pending = false !== get_transient( Gr_Dch_Refresh::PENDING );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the value is a display hint produced by this page's own PRG, never an action input.
        $result = isset( $_GET['gr_update'] ) ? sanitize_key( wp_unslash( $_GET['gr_update'] ) ) : '';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'IP Intelligence', 'greenpng' ); ?></h1>

            <?php if ( 'queued' === $result ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html__( 'Data refresh queued. The version below updates once the download completes.', 'greenpng' ); ?></p></div>
            <?php elseif ( 'pending' === $result ) : ?>
                <div class="notice notice-warning"><p><?php echo esc_html__( 'A data refresh is already queued.', 'greenpng' ); ?></p></div>
            <?php endif; ?>

            <h2><?php echo esc_html__( 'Country database (DB-IP)', 'greenpng' ); ?></h2>
            <p><?php echo esc_html__( 'Country-level lookups run entirely on this site against the bundled DB-IP Lite database; no lookup ever leaves the server.', 'greenpng' ); ?></p>

            <?php if ( ! $state['available'] ) : ?>
                <p><?php echo esc_html__( 'No local database found. Reinstalling the plugin restores the bundled copy.', 'greenpng' ); ?></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:520px;">
                    <tbody>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Data origin', 'greenpng' ); ?></th>
                            <td><?php echo esc_html( 'override' === $state['source'] ? __( 'Owner-refreshed copy (uploads)', 'greenpng' ) : __( 'Bundled with the plugin', 'greenpng' ) ); ?></td>
                        </tr>
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
            <p><?php echo esc_html__( 'AbuseIPDB reputation checks ship with v1.2, default off, and only with your own API key.', 'greenpng' ); ?></p>
        </div>
        <?php
    }
}
