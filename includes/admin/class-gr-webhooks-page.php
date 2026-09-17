<?php
/**
 * Webhooks page (ADR-0016 D1/D2, docs/06 tree): the owner-configured
 * outbound notification surface. Endpoint CRUD over native
 * components, the delivery type selector (generic JSON or one of the
 * four messaging card kinds), the signing secret masked on display
 * and stored as an encryption envelope, and the receiver-side
 * verification contract written next to the controls it explains.
 * Every write passes the capability and nonce double gate and leaves
 * an audit row.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Secrets;
use GreenPNG\Integrations\Webhook\Gr_Webhook_Repository;
use GreenPNG\Storage\Gr_Audit_Repository;

/**
 * Owner-facing webhook endpoint management.
 */
final class Gr_Webhooks_Page {

    /** Menu slug under the top-level menu, Integrations section. */
    public const SLUG = 'greenpng-webhooks';

    /** Nonce action for every write on this page. */
    public const NONCE_ACTION = 'gr_webhooks';

    /** Nonce field name. */
    public const NONCE_FIELD = '_gr_webhooks_nonce';

    /** Add-endpoint POST marker. */
    public const ACTION_ADD = 'add';

    /** Update-endpoint POST marker. */
    public const ACTION_UPDATE = 'update';

    /** Delete-endpoint POST marker. */
    public const ACTION_DELETE = 'delete';

    /** Toggle-endpoint POST marker. */
    public const ACTION_TOGGLE = 'toggle';

    /** Circuit-reset POST marker. */
    public const ACTION_RESET = 'reset';

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
     * Dispatches POST writes with the post-redirect-get pattern; the
     * outcome lands in the query string and renders as a notice.
     *
     * @return void
     */
    public static function handle_actions(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- routing only; the nonce gate below is the real check.
        $action = isset( $_POST['gr_webhooks_action'] ) ? sanitize_key( (string) wp_unslash( $_POST['gr_webhooks_action'] ) ) : '';
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
        $id = isset( $_POST['endpoint_id'] ) ? absint( (int) wp_unslash( $_POST['endpoint_id'] ) ) : 0;

        if ( self::ACTION_ADD === $action || self::ACTION_UPDATE === $action ) {
            $outcome = self::write_endpoint( $id, $action, $audit, $user_id );
        } elseif ( self::ACTION_DELETE === $action ) {
            $endpoint = Gr_Webhook_Repository::find( $id );
            if ( null !== $endpoint && Gr_Webhook_Repository::delete( $id ) ) {
                $audit->log( 'delete', 'webhook_endpoint', (string) $id, array( 'url' => (string) $endpoint['url'] ), array(), $user_id );
                $outcome = 'deleted';
            } else {
                $outcome = 'not_found';
            }
        } elseif ( self::ACTION_TOGGLE === $action ) {
            if ( Gr_Webhook_Repository::toggle( $id ) ) {
                $endpoint = Gr_Webhook_Repository::find( $id );
                $audit->log( 'toggle', 'webhook_endpoint', (string) $id, array(), array( 'active' => (int) ( $endpoint['active'] ?? 0 ) ), $user_id );
                $outcome = 'toggled';
            } else {
                $outcome = 'not_found';
            }
        } elseif ( self::ACTION_RESET === $action ) {
            if ( Gr_Webhook_Repository::reset_circuit( $id ) ) {
                $audit->log( 'reset', 'webhook_endpoint', (string) $id, array(), array( 'circuit' => 'closed' ), $user_id );
                $outcome = 'reset';
            } else {
                $outcome = 'not_found';
            }
        }

        if ( '' !== $outcome ) {
            wp_safe_redirect(
                add_query_arg(
                    array(
                        'page'  => self::SLUG,
                        'gr_wh' => $outcome,
                    ),
                    admin_url( 'admin.php' )
                )
            );
        }
    }

    /**
     * Page output: the notice of the previous write, the endpoint
     * table, the add-or-edit form, and the verification contract.
     *
     * @return void
     */
    public static function render(): void {
        $endpoints = Gr_Webhook_Repository::all();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display hint produced by this page's own PRG, never an action input.
        $result = isset( $_GET['gr_wh'] ) ? sanitize_key( (string) wp_unslash( $_GET['gr_wh'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing to the prefilled edit form of one endpoint.
        $edit_id = isset( $_GET['edit'] ) ? absint( (int) wp_unslash( $_GET['edit'] ) ) : 0;

        $editing = null;
        if ( $edit_id > 0 ) {
            foreach ( $endpoints as $endpoint ) {
                if ( $edit_id === (int) $endpoint['id'] ) {
                    $editing = $endpoint;
                }
            }
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Webhooks', 'greenpng' ); ?></h1>
            <hr class="wp-header-end" />

            <?php if ( 'added' === $result ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html__( 'Endpoint added. Deliveries begin with the next matching event.', 'greenpng' ); ?></p></div>
            <?php elseif ( 'updated' === $result ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html__( 'Endpoint updated.', 'greenpng' ); ?></p></div>
            <?php elseif ( 'deleted' === $result ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html__( 'Endpoint deleted. Its queued deliveries stop with it.', 'greenpng' ); ?></p></div>
            <?php elseif ( 'toggled' === $result ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html__( 'Endpoint state changed.', 'greenpng' ); ?></p></div>
            <?php elseif ( 'reset' === $result ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html__( 'Circuit reset. The endpoint takes deliveries again.', 'greenpng' ); ?></p></div>
            <?php elseif ( 'http_url' === $result ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html__( 'The URL was refused: endpoints must be https.', 'greenpng' ); ?></p></div>
            <?php elseif ( 'secret_short' === $result ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html__( 'The signing secret was refused: at least 16 characters.', 'greenpng' ); ?></p></div>
            <?php elseif ( 'no_events' === $result ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html__( 'Choose at least one event to subscribe to.', 'greenpng' ); ?></p></div>
            <?php elseif ( 'cap' === $result ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html__( 'The endpoint list is full (10). Remove one to add another.', 'greenpng' ); ?></p></div>
            <?php elseif ( 'not_found' === $result ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html__( 'That endpoint no longer exists.', 'greenpng' ); ?></p></div>
            <?php endif; ?>

            <h2><?php echo esc_html__( 'Endpoints', 'greenpng' ); ?></h2>
            <?php if ( array() === $endpoints ) : ?>
                <p><?php echo esc_html__( 'No endpoints configured yet. Deliveries only ever go to endpoints you add and activate below.', 'greenpng' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th scope="col"><?php echo esc_html__( 'URL', 'greenpng' ); ?></th>
                            <th scope="col"><?php echo esc_html__( 'Type', 'greenpng' ); ?></th>
                            <th scope="col"><?php echo esc_html__( 'Events', 'greenpng' ); ?></th>
                            <th scope="col"><?php echo esc_html__( 'Secret', 'greenpng' ); ?></th>
                            <th scope="col"><?php echo esc_html__( 'State', 'greenpng' ); ?></th>
                            <th scope="col"><?php echo esc_html__( 'Last delivery', 'greenpng' ); ?></th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $endpoints as $endpoint ) : ?>
                            <?php
                            $id       = (int) $endpoint['id'];
                            $circuit  = 0 !== (int) $endpoint['circuit_open_since'];
                            $paused   = 1 !== (int) $endpoint['active'];
                            $failures = (int) $endpoint['consecutive_failures'];
                            ?>
                            <tr>
                                <td>
                                    <?php echo esc_html( (string) $endpoint['url'] ); ?>
                                    <?php if ( $circuit ) : ?>
                                        <div class="notice notice-error inline" style="margin:6px 0 0;"><p><?php echo esc_html__( 'Circuit open: deliveries stopped after repeated failures. Check the receiver, then reset.', 'greenpng' ); ?></p></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( self::kind_label( Gr_Webhook_Repository::kind_of( $endpoint ) ) ); ?></td>
                                <td><?php echo esc_html( implode( ', ', array_map( 'esc_html', (array) $endpoint['events'] ) ) ); ?></td>
                                <td><code><?php echo esc_html( Gr_Secrets::mask( Gr_Webhook_Repository::secret_of( $endpoint ) ) ); ?></code></td>
                                <td><?php echo $paused ? esc_html__( 'Paused', 'greenpng' ) : esc_html__( 'Active', 'greenpng' ); ?></td>
                                <td>
                                    <?php
                                    $last = (string) $endpoint['last_delivery_at'];
                                    echo esc_html( '' === $last ? __( 'Never', 'greenpng' ) : $last . ' · ' . (string) $endpoint['last_status'] );
                                    if ( $failures > 0 && ! $circuit ) {
                                        echo esc_html( ' · ' . sprintf( /* translators: %d: failed delivery count. */ __( '%d failing streak', 'greenpng' ), $failures ) );
                                    }
                                    ?>
                                </td>
                                <td>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                                        <input type="hidden" name="endpoint_id" value="<?php echo esc_attr( (string) $id ); ?>" />
                                        <button type="submit" class="button" name="gr_webhooks_action" value="<?php echo esc_attr( self::ACTION_TOGGLE ); ?>"><?php echo $paused ? esc_html__( 'Activate', 'greenpng' ) : esc_html__( 'Pause', 'greenpng' ); ?></button>
                                    </form>
                                    <?php if ( $circuit ) : ?>
                                        <form method="post" style="display:inline;">
                                            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                                            <input type="hidden" name="endpoint_id" value="<?php echo esc_attr( (string) $id ); ?>" />
                                            <button type="submit" class="button" name="gr_webhooks_action" value="<?php echo esc_attr( self::ACTION_RESET ); ?>"><?php echo esc_html__( 'Reset circuit', 'greenpng' ); ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <a class="button" href="
                                    <?php
                                    echo esc_url(
                                        add_query_arg(
                                            array(
                                                'page' => self::SLUG,
                                                'edit' => $id,
                                            ),
                                            admin_url( 'admin.php' )
                                        )
                                    );
                                    ?>
                                                            "><?php echo esc_html__( 'Edit', 'greenpng' ); ?></a>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                                        <input type="hidden" name="endpoint_id" value="<?php echo esc_attr( (string) $id ); ?>" />
                                        <button type="submit" class="button button-link-delete" name="gr_webhooks_action" value="<?php echo esc_attr( self::ACTION_DELETE ); ?>"><?php echo esc_html__( 'Delete', 'greenpng' ); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2><?php echo $editing ? esc_html__( 'Edit endpoint', 'greenpng' ) : esc_html__( 'Add endpoint', 'greenpng' ); ?></h2>
            <?php if ( ! $editing && count( $endpoints ) >= Gr_Webhook_Repository::CAP ) : ?>
                <p><?php echo esc_html__( 'The endpoint list is full (10). Remove one to add another.', 'greenpng' ); ?></p>
            <?php else : ?>
                <form method="post">
                    <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                    <input type="hidden" name="endpoint_id" value="<?php echo esc_attr( (string) ( $editing['id'] ?? 0 ) ); ?>" />
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="gr_wh_url"><?php echo esc_html__( 'Endpoint URL', 'greenpng' ); ?></label></th>
                            <td>
                                <input type="url" id="gr_wh_url" name="gr_wh_url" class="regular-text" value="<?php echo esc_attr( (string) ( $editing['url'] ?? '' ) ); ?>" required />
                                <p class="description"><?php echo esc_html__( 'https only. Each matching event posts one JSON delivery here.', 'greenpng' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gr_wh_secret"><?php echo esc_html__( 'Signing secret', 'greenpng' ); ?></label></th>
                            <td>
                                <input type="password" id="gr_wh_secret" name="gr_wh_secret" class="regular-text" autocomplete="new-password" <?php echo $editing ? '' : 'required'; ?> />
                                <p class="description">
                                    <?php
                                    echo esc_html(
                                        $editing
                                            ? __( 'Leave empty to keep the current secret. At least 16 characters when set.', 'greenpng' )
                                            : __( 'At least 16 characters. It never leaves this site and displays only masked.', 'greenpng' )
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gr_wh_kind"><?php echo esc_html__( 'Type', 'greenpng' ); ?></label></th>
                            <td>
                                <select id="gr_wh_kind" name="gr_wh_kind">
                                    <?php foreach ( Gr_Webhook_Repository::KINDS as $kind ) : ?>
                                        <option value="<?php echo esc_attr( $kind ); ?>" <?php selected( $kind, self::editing_kind( $editing ) ); ?>><?php echo esc_html( self::kind_label( $kind ) ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php echo esc_html__( 'Generic posts one JSON document (the shape documented below). The Feishu, WeCom, DingTalk, and Slack types post the platform\'s own message-card shape instead; the four signature headers ride along on every type.', 'greenpng' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Events', 'greenpng' ); ?></th>
                            <td>
                                <fieldset>
                                    <?php foreach ( Gr_Webhook_Repository::EVENTS as $event ) : ?>
                                        <?php $on = $editing && in_array( $event, (array) $editing['events'], true ); ?>
                                        <label style="display:inline-block;margin-right:16px;">
                                            <input type="checkbox" name="gr_wh_events[]" value="<?php echo esc_attr( $event ); ?>" <?php checked( $on ); ?> />
                                            <?php echo esc_html( $event ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </fieldset>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__( 'Active', 'greenpng' ); ?></th>
                            <td><label><input type="checkbox" name="gr_wh_active" value="1" <?php checked( 1, (int) ( $editing['active'] ?? 1 ) ); ?> /> <?php echo esc_html__( 'Deliver to this endpoint', 'greenpng' ); ?></label></td>
                        </tr>
                    </table>
                    <button type="submit" class="button button-primary" name="gr_webhooks_action" value="<?php echo esc_attr( $editing ? self::ACTION_UPDATE : self::ACTION_ADD ); ?>">
                        <?php echo $editing ? esc_html__( 'Save endpoint', 'greenpng' ) : esc_html__( 'Add endpoint', 'greenpng' ); ?>
                    </button>
                    <?php if ( $editing ) : ?>
                        <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( 'Cancel', 'greenpng' ); ?></a>
                    <?php endif; ?>
                </form>
            <?php endif; ?>

            <h2><?php echo esc_html__( 'Verifying deliveries (for the receiving side)', 'greenpng' ); ?></h2>
            <p><?php echo esc_html__( 'Every delivery is a POST with a JSON body and four headers:', 'greenpng' ); ?></p>
            <table class="widefat striped" style="max-width:720px;">
                <tbody>
                    <tr><th scope="row"><code>X-Gr-Signature</code></th><td><?php echo esc_html__( 'sha256= followed by the HMAC-SHA256 of the raw request body, keyed with the endpoint\'s signing secret. Compare with a constant-time function.', 'greenpng' ); ?></td></tr>
                    <tr><th scope="row"><code>X-Gr-Event</code></th><td><?php echo esc_html__( 'The event name (for example conversion, lead, dwell, ab).', 'greenpng' ); ?></td></tr>
                    <tr><th scope="row"><code>X-Gr-Delivery</code></th><td><?php echo esc_html__( 'A unique 32-hex id for this delivery; use it to deduplicate retries.', 'greenpng' ); ?></td></tr>
                    <tr><th scope="row"><code>X-Gr-Timestamp</code></th><td><?php echo esc_html__( 'Unix time of the send. Reject deliveries outside a ±300-second window to stop replays.', 'greenpng' ); ?></td></tr>
                </tbody>
            </table>
            <p>
                <?php
                echo esc_html__( 'Receiver-side verification in one line:', 'greenpng' );
                ?>
                <code><?php echo esc_html( 'hash_equals( \'sha256=\' . hash_hmac( \'sha256\', $request_body, $secret ), $_SERVER[\'HTTP_X_GR_SIGNATURE\'] ?? \'\' )' ); ?></code>
            </p>
            <p><?php echo esc_html__( 'The body carries the event name, group, the visitor and session identifiers in their hashed forms, and the event parameters. No email addresses, raw IP addresses, or other personal identifiers are ever sent: what a delivery carries is exactly what the event reports already show.', 'greenpng' ); ?></p>
            <p><?php echo esc_html__( 'Deliveries retry twice (60 seconds, then 300 seconds) before the delivery is recorded as failed; five consecutive failures pause the endpoint until you reset its circuit on this page. Nothing is delivered by any greenpng-operated service, and none exists.', 'greenpng' ); ?></p>

            <h2><?php echo esc_html__( 'Messaging platform types', 'greenpng' ); ?></h2>
            <p><?php echo esc_html__( 'Endpoints typed Feishu, WeCom, DingTalk, or Slack post the platform\'s own message-card shape instead of the generic JSON document, so a group bot renders a real card. The same event fields travel in every type; the four signature headers above ride along on every delivery regardless of type.', 'greenpng' ); ?></p>
            <table class="widefat striped" style="max-width:720px;">
                <tbody>
                    <tr><th scope="row"><?php echo esc_html__( 'Feishu / Lark card', 'greenpng' ); ?></th><td><?php echo esc_html__( 'An interactive card (msg_type interactive) with a green header and short field pairs, plus the timestamp and sign values in the body that a bot with signature verification requires. The sign is Base64 HMAC-SHA256 over the timestamp, keyed with this endpoint\'s signing secret.', 'greenpng' ); ?></td></tr>
                    <tr><th scope="row"><?php echo esc_html__( 'WeCom markdown', 'greenpng' ); ?></th><td><?php echo esc_html__( 'A markdown message (msgtype markdown): one quoted block with the event fields. Paste the webhook key the WeCom group bot shows into the endpoint URL.', 'greenpng' ); ?></td></tr>
                    <tr><th scope="row"><?php echo esc_html__( 'DingTalk action card', 'greenpng' ); ?></th><td><?php echo esc_html__( 'An action card (msgtype actionCard) with one button into this site\'s dashboard. The robot verification appends a millisecond timestamp and a sign to the URL query; the sign is Base64 HMAC-SHA256 over the timestamp, keyed with this endpoint\'s signing secret, so store the robot\'s own secret here.', 'greenpng' ); ?></td></tr>
                    <tr><th scope="row"><?php echo esc_html__( 'Slack blocks', 'greenpng' ); ?></th><td><?php echo esc_html__( 'A text fallback plus one header block and one section with the event fields in Slack mrkdwn. The endpoint URL is the incoming webhook URL Slack shows when you create the app.', 'greenpng' ); ?></td></tr>
                </tbody>
            </table>
            <p><?php echo esc_html__( 'A platform may answer with its own refusal inside an HTTP 200 (for example Feishu code 19001 on a signature mismatch). Such answers retry on the same ladder as any failed delivery and surface in the endpoint\'s last status.', 'greenpng' ); ?></p>
        </div>
        <?php
    }

    /**
     * The add/update write arm with the full validation ladder and
     * its audit row.
     *
     * @param int                 $id      Endpoint id (0 on add).
     * @param string              $action  ACTION_ADD or ACTION_UPDATE.
     * @param Gr_Audit_Repository $audit   Audit trail.
     * @param int                 $user_id Acting user.
     * @return string Outcome word for the redirect.
     */
    private static function write_endpoint( int $id, string $action, Gr_Audit_Repository $audit, int $user_id ): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in may_write().
        $url = isset( $_POST['gr_wh_url'] ) ? esc_url_raw( (string) wp_unslash( $_POST['gr_wh_url'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified; the secret is validated by length below and stored as an envelope, never echoed.
        $secret = isset( $_POST['gr_wh_secret'] ) ? (string) wp_unslash( $_POST['gr_wh_secret'] ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified; the list is intersected with the closed vocabulary in the repository.
        $events = isset( $_POST['gr_wh_events'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['gr_wh_events'] ) ) : array();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified; the kind is intersected with the closed vocabulary in the repository.
        $kind = isset( $_POST['gr_wh_kind'] ) ? sanitize_key( (string) wp_unslash( $_POST['gr_wh_kind'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified.
        $active = isset( $_POST['gr_wh_active'] );

        if ( self::ACTION_ADD === $action ) {
            $error  = '';
            $new_id = Gr_Webhook_Repository::add( $url, $secret, $events, $active, $error, $kind );
            if ( 0 === $new_id ) {
                return self::refusal_word( $error );
            }

            $audit->log(
                'add',
                'webhook_endpoint',
                (string) $new_id,
                array(),
                array(
                    'url'    => $url,
                    'kind'   => Gr_Webhook_Repository::kind_of( (array) Gr_Webhook_Repository::find( $new_id ) ),
                    'events' => $events,
                    'active' => $active ? 1 : 0,
                ),
                $user_id
            );

            return 'added';
        }

        if ( Gr_Webhook_Repository::update( $id, $url, $secret, $events, $active, $kind ) ) {
            $audit->log(
                'update',
                'webhook_endpoint',
                (string) $id,
                array(),
                array(
                    'url'    => $url,
                    'kind'   => Gr_Webhook_Repository::kind_of( (array) Gr_Webhook_Repository::find( $id ) ),
                    'events' => $events,
                    'active' => $active ? 1 : 0,
                    'secret' => '' !== $secret ? 'rotated' : 'kept',
                ),
                $user_id
            );

            return 'updated';
        }

        return 'not_found';
    }

    /**
     * Maps a repository refusal to its notice word.
     *
     * @param string $error Repository reason word.
     * @return string
     */
    private static function refusal_word( string $error ): string {
        switch ( $error ) {
            case 'url':
                return 'http_url';
            case 'secret':
                return 'secret_short';
            case 'events':
                return 'no_events';
            case 'cap':
                return 'cap';
            default:
                return 'not_found';
        }
    }

    /**
     * The kind of the row the edit form is prefilled from, generic
     * for the add form.
     *
     * @param array<string, mixed>|null $editing Row being edited, null on add.
     * @return string
     */
    private static function editing_kind( ?array $editing ): string {
        return null === $editing ? Gr_Webhook_Repository::KIND_GENERIC : Gr_Webhook_Repository::kind_of( $editing );
    }

    /**
     * One display label per kind word.
     *
     * @param string $kind Kind word.
     * @return string
     */
    private static function kind_label( string $kind ): string {
        switch ( $kind ) {
            case Gr_Webhook_Repository::KIND_FEISHU:
                return __( 'Feishu / Lark card', 'greenpng' );
            case Gr_Webhook_Repository::KIND_WECOM:
                return __( 'WeCom markdown', 'greenpng' );
            case Gr_Webhook_Repository::KIND_DINGTALK:
                return __( 'DingTalk action card', 'greenpng' );
            case Gr_Webhook_Repository::KIND_SLACK:
                return __( 'Slack blocks', 'greenpng' );
            default:
                return __( 'Generic JSON', 'greenpng' );
        }
    }
}
