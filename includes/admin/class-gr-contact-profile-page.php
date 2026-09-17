<?php
/**
 * Contact Profile page (docs/06 Audience tree, ADR-0013 D5): one
 * captured contact, opened inline from the Contacts list. The email
 * stays masked everywhere; the plaintext appears only behind the
 * reveal write, which passes the same double gate as every write in
 * the plugin (manage_options capability AND a valid admin referer
 * nonce) and lands in the audit log before anything is shown. The
 * rescore write recomputes this contact's lead score and the RFM
 * population on demand, using exactly the engines the nightly pass
 * uses. The timeline is the visitor binding's last 30 events, every
 * bound conversion, and every recorded session.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\CRM\Gr_Rfm_Engine;
use GreenPNG\CRM\Gr_Scoring_Engine;
use GreenPNG\Core\Gr_Secrets;
use GreenPNG\Storage\Gr_Audit_Repository;
use GreenPNG\Storage\Gr_Contact_Repository;
use GreenPNG\Storage\Gr_Conversion_Repository;
use GreenPNG\Storage\Gr_Event_Repository;
use GreenPNG\Storage\Gr_Session_Repository;

/**
 * One contact, full profile.
 */
final class Gr_Contact_Profile_Page {

    /** Menu slug, nested under Contacts (docs/06: inline entry). */
    public const SLUG = 'greenpng-contact-profile';

    /** Nonce action name for every write on this page. */
    public const NONCE_ACTION = 'gr_contact_profile';

    /** Nonce field name. */
    public const NONCE_FIELD = '_gr_contact_nonce';

    /** Reveal POST marker. */
    public const ACTION_REVEAL = 'reveal';

    /** Rescore POST marker. */
    public const ACTION_RESCORE = 'rescore';

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
     * Dispatches the reveal and rescore writes. Both redirect
     * (post-redirect-get) so a refresh never re-submits; a gate
     * failure falls through to rendering untouched.
     *
     * @return void
     */
    public static function handle_actions(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce gate below is the real check; this line only routes the request.
        $action = isset( $_POST['gr_profile_action'] ) ? sanitize_key( (string) wp_unslash( $_POST['gr_profile_action'] ) ) : '';
        if ( '' === $action ) {
            return;
        }

        if ( ! self::may_write() ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in may_write(); the contact the write belongs to.
        $contact_id = isset( $_POST['contact_id'] ) ? absint( (int) wp_unslash( $_POST['contact_id'] ) ) : 0;
        $row        = ( new Gr_Contact_Repository() )->row_for_id( $contact_id );
        if ( null === $row ) {
            return;
        }

        $audit   = new Gr_Audit_Repository();
        $user_id = get_current_user_id();
        $visitor = (string) ( $row['visitor_id'] ?? '' );

        if ( self::ACTION_REVEAL === $action ) {
            // The audit row carries the mask, never the plaintext:
            // the trail proves who revealed, not what they saw.
            $email = Gr_Secrets::decrypt( (string) ( $row['email_enc'] ?? '' ) );
            $audit->log(
                'reveal',
                'contact_email',
                (string) $contact_id,
                array(),
                array(
                    'masked' => is_string( $email ) ? Gr_Secrets::mask( $email ) : '',
                ),
                $user_id
            );

            self::redirect( $contact_id, 'revealed' );
        }

        if ( self::ACTION_RESCORE === $action ) {
            // The same engines the nightly pass runs; RFM quintiles
            // are population relative, so the population refreshes
            // rather than one row in isolation.
            Gr_Scoring_Engine::recompute_contact( $contact_id );
            Gr_Rfm_Engine::compute_all();
            $audit->log(
                'rescore',
                'contact_score',
                (string) $contact_id,
                array(),
                array( 'visitor_id' => $visitor ),
                $user_id
            );

            self::redirect( $contact_id, 'rescored' );
        }
    }

    /**
     * Page output: the contact header, the score and RFM state, the
     * tags, and the timeline.
     *
     * @return void
     */
    public static function render(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flags on an owner-gated screen.
        $revealed = isset( $_GET['revealed'] ) ? '1' === sanitize_key( (string) wp_unslash( $_GET['revealed'] ) ) : false;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flags on an owner-gated screen.
        $rescored = isset( $_GET['rescored'] ) ? '1' === sanitize_key( (string) wp_unslash( $_GET['rescored'] ) ) : false;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the contact to show; absint bounds it.
        $contact_id = isset( $_GET['contact_id'] ) ? absint( (int) wp_unslash( $_GET['contact_id'] ) ) : 0;

        $contacts = new Gr_Contact_Repository();
        $row      = $contacts->row_for_id( $contact_id );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Contact Profile', 'greenpng' ); ?></h1>
            <hr class="wp-header-end" />

            <p>
                <a class="button" href="<?php echo esc_attr( '?page=' . Gr_Contacts_Page::SLUG ); ?>">
                    <?php echo esc_html__( 'Back to Contacts', 'greenpng' ); ?>
                </a>
            </p>

            <?php if ( null === $row ) : ?>
                <div class="notice notice-warning"><p>
                    <?php echo esc_html__( 'No contact matches this id. Open a profile from the Contacts list.', 'greenpng' ); ?>
                </p></div>
            </div>
                <?php
                return;
            endif;
            ?>

            <?php if ( $rescored ) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php echo esc_html__( 'Recomputed: the lead score and the RFM segments refreshed from the same engines the nightly pass runs.', 'greenpng' ); ?>
                </p></div>
            <?php endif; ?>

            <?php
            $email    = Gr_Secrets::decrypt( (string) ( $row['email_enc'] ?? '' ) );
            $email_ok = is_string( $email ) && '' !== $email;
            $masked   = $email_ok ? Gr_Secrets::mask( $email ) : '****';
            $visitor  = (string) ( $row['visitor_id'] ?? '' );
            $axes     = Gr_Rfm_Engine::axes_for( $contact_id );
            ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Email', 'greenpng' ); ?></th>
                    <td>
                        <?php if ( $revealed && $email_ok ) : ?>
                            <strong><?php echo esc_html( $email ); ?></strong>
                            <p class="description"><?php echo esc_html__( 'Revealed for this view only. The reveal is in the audit log; the mask returns on the next page load.', 'greenpng' ); ?></p>
                        <?php else : ?>
                            <strong><?php echo esc_html( $masked ); ?></strong>
                            <form method="post" style="display:inline-block">
                                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                                <input type="hidden" name="gr_profile_action" value="<?php echo esc_attr( self::ACTION_REVEAL ); ?>" />
                                <input type="hidden" name="contact_id" value="<?php echo esc_attr( (string) $contact_id ); ?>" />
                                <?php submit_button( __( 'Reveal email', 'greenpng' ), 'secondary', 'gr_reveal', false ); ?>
                            </form>
                            <p class="description"><?php echo esc_html__( 'The reveal needs your confirmation and lands in the audit log.', 'greenpng' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Name', 'greenpng' ); ?></th>
                    <td><?php echo esc_html( trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) ) ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Lead score', 'greenpng' ); ?></th>
                    <td>
                        <?php
                        // translators: %d: the stored lead score, 0..100.
                        printf( esc_html__( '%d of 100', 'greenpng' ), (int) ( $row['lead_score'] ?? 0 ) );
                        ?>
                        <form method="post" style="display:inline-block">
                            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                            <input type="hidden" name="gr_profile_action" value="<?php echo esc_attr( self::ACTION_RESCORE ); ?>" />
                            <input type="hidden" name="contact_id" value="<?php echo esc_attr( (string) $contact_id ); ?>" />
                            <?php submit_button( __( 'Recompute now', 'greenpng' ), 'secondary', 'gr_rescore', false ); ?>
                        </form>
                        <p class="description">
                            <?php
                            /* translators: %d: length of the scoring window in days, the same window retention keeps. */
                            printf( esc_html__( 'Points come from the active scoring rules over the last %d days of events; a suspected bot verdict outranks every rule and scores zero.', 'greenpng' ), (int) Gr_Scoring_Engine::WINDOW_DAYS );
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'RFM', 'greenpng' ); ?></th>
                    <td>
                        <?php if ( null === $axes ) : ?>
                            <?php echo esc_html__( 'No axes yet.', 'greenpng' ); ?>
                        <?php else : ?>
                            <?php
                            // translators: 1: recency quintile, 2: frequency quintile, 3: monetary quintile, 4: the segment word, 5: net value.
                            printf( esc_html__( 'r %1$d / f %2$d / m %3$d — %4$s, net value %5$s', 'greenpng' ), (int) $axes['r'], (int) $axes['f'], (int) $axes['m'], esc_html( $axes['segment'] ), esc_html( number_format( $axes['ltv'], 2 ) ) );
                            ?>
                            <p class="description"><?php echo esc_html__( 'Quintiles are population relative and refresh with the nightly pass; r/f/m themselves are never stored.', 'greenpng' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Tags', 'greenpng' ); ?></th>
                    <td>
                        <?php
                        $tags = $contacts->tag_slugs_for_contact( $contact_id );
                        if ( array() === $tags ) {
                            echo esc_html__( 'No tags attached.', 'greenpng' );
                        } else {
                            foreach ( $tags as $slug ) {
                                echo '<span class="button button-small" style="margin-right:4px">' . esc_html( (string) $slug ) . '</span>';
                            }
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Visitor binding', 'greenpng' ); ?></th>
                    <td><?php echo esc_html( '' !== $visitor ? $visitor : __( 'none (fallback track capture)', 'greenpng' ) ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'First seen', 'greenpng' ); ?></th>
                    <td><?php echo esc_html( (string) ( $row['first_seen'] ?? '' ) ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Last seen', 'greenpng' ); ?></th>
                    <td><?php echo esc_html( (string) ( $row['last_seen'] ?? '' ) ); ?></td>
                </tr>
            </table>

            <h2><?php echo esc_html__( 'Timeline', 'greenpng' ); ?></h2>
            <?php if ( '' === $visitor ) : ?>
                <p><?php echo esc_html__( 'This contact arrived on the fallback track, which is deliberately not linked across days — there is no visitor timeline to show.', 'greenpng' ); ?></p>
            </div>
                <?php
                return;
            endif;
            ?>

            <?php
            self::render_events( $visitor );
            self::render_conversions( $visitor );
            self::render_sessions( $visitor );
            ?>
        </div>
        <?php
    }

    /**
     * The events section of the timeline: the visitor's newest 30.
     *
     * @param string $visitor Visitor identity.
     * @return void
     */
    private static function render_events( string $visitor ): void {
        $events = ( new Gr_Event_Repository() )->recent_for_visitor( $visitor, 30 );
        ?>
        <h3><?php echo esc_html__( 'Events, newest 30', 'greenpng' ); ?></h3>
        <table class="widefat striped" role="presentation">
            <thead>
                <tr>
                    <th scope="col"><?php echo esc_html__( 'Time', 'greenpng' ); ?></th>
                    <th scope="col"><?php echo esc_html__( 'Event', 'greenpng' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( array() === $events ) : ?>
                    <tr><td colspan="2"><?php echo esc_html__( 'No events recorded for this visitor.', 'greenpng' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $events as $event ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) ( $event['created_at'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $event['event_name'] ?? '' ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * The conversions section of the timeline: every binding.
     *
     * @param string $visitor Visitor identity.
     * @return void
     */
    private static function render_conversions( string $visitor ): void {
        $rows = ( new Gr_Conversion_Repository() )->rows_for_visitor( $visitor );
        ?>
        <h3><?php echo esc_html__( 'Conversions', 'greenpng' ); ?></h3>
        <table class="widefat striped" role="presentation">
            <thead>
                <tr>
                    <th scope="col"><?php echo esc_html__( 'Time', 'greenpng' ); ?></th>
                    <th scope="col"><?php echo esc_html__( 'Source', 'greenpng' ); ?></th>
                    <th scope="col"><?php echo esc_html__( 'Amount', 'greenpng' ); ?></th>
                    <th scope="col"><?php echo esc_html__( 'Status', 'greenpng' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( array() === $rows ) : ?>
                    <tr><td colspan="4"><?php echo esc_html__( 'No conversions bound to this visitor.', 'greenpng' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $rows as $conversion ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) ( $conversion['created_at'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $conversion['source_type'] ?? '' ) . ' #' . (string) ( $conversion['source_id'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $conversion['amount'] ?? '' ) . ' ' . (string) ( $conversion['currency'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $conversion['status'] ?? '' ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * The sessions section of the timeline: every recorded session.
     *
     * @param string $visitor Visitor identity.
     * @return void
     */
    private static function render_sessions( string $visitor ): void {
        $rows = ( new Gr_Session_Repository() )->rows_for_visitor( $visitor );
        ?>
        <h3><?php echo esc_html__( 'Sessions', 'greenpng' ); ?></h3>
        <table class="widefat striped" role="presentation">
            <thead>
                <tr>
                    <th scope="col"><?php echo esc_html__( 'Started', 'greenpng' ); ?></th>
                    <th scope="col"><?php echo esc_html__( 'Last active', 'greenpng' ); ?></th>
                    <th scope="col"><?php echo esc_html__( 'Device', 'greenpng' ); ?></th>
                    <th scope="col"><?php echo esc_html__( 'Country', 'greenpng' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( array() === $rows ) : ?>
                    <tr><td colspan="4"><?php echo esc_html__( 'No sessions recorded for this visitor.', 'greenpng' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $rows as $session ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) ( $session['started_at'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $session['last_active'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $session['device_type'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $session['country_code'] ?? '' ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Post-write redirect helper: back to this profile with a flag.
     *
     * @param int    $contact_id Contact row id.
     * @param string $flag       Query flag name.
     * @return void
     */
    private static function redirect( int $contact_id, string $flag ): void {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'       => self::SLUG,
                    'contact_id' => $contact_id,
                    $flag        => '1',
                ),
                admin_url( 'admin.php' )
            )
        );
    }
}
