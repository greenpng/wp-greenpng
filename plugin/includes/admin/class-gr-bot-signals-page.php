<?php
/**
 * Bot & Device Signals page (docs/06 §1 tree, docs/13 U14): the
 * v1.0 crawler-verification tab — FCrDNS verdicts straight from the
 * DNS walks (record-only, never a block), the scanner-UA engine's
 * folded statistics, and the probe's bot_score distribution. All
 * three are read-only summaries of what the engines actually
 * recorded; the device-signals tab is a v1.3 delivery and this page
 * does not preview it.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Security\Gr_Crawler_Verify;
use GreenPNG\Storage\Gr_Security_Log_Repository;
use GreenPNG\Storage\Gr_Session_Repository;

/**
 * Crawler-verification reporting surface.
 */
final class Gr_Bot_Signals_Page {

    /** Menu slug under the Traffic & Security parent. */
    public const SLUG = 'greenpng-bot';

    /** FCrDNS verdict look-back, hours. */
    public const FCRDNS_HOURS = 72;

    /** Scanner-UA statistics look-back, hours. */
    public const UA_HOURS = 168;

    /** Look-back for the bot_score distribution, days. */
    public const SCORE_DAYS = 30;

    /** Row cap for the FCrDNS table. */
    public const FCRDNS_LIMIT = 25;

    /** Row cap for the UA table. */
    public const UA_LIMIT = 15;

    /**
     * Page output. No write arms: everything here is a summary read.
     *
     * @return void
     */
    public static function render(): void {
        $repo         = new Gr_Security_Log_Repository();
        $summary      = $repo->fcrdns_summary( self::FCRDNS_HOURS );
        $verdicts     = $repo->fcrdns_recent( self::FCRDNS_HOURS, self::FCRDNS_LIMIT );
        $agents       = $repo->ua_engine_stats( self::UA_HOURS, self::UA_LIMIT );
        $distribution = ( new Gr_Session_Repository() )->bot_score_distribution( self::SCORE_DAYS );

        $verified   = $summary[ Gr_Crawler_Verify::STATUS_VERIFIED ]['walks'] ?? 0;
        $unverified = $summary[ Gr_Crawler_Verify::STATUS_UNVERIFIED ]['walks'] ?? 0;
        $humans     = $distribution['total'] - $distribution['bots'];
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Bot & Device Signals', 'greenpng' ); ?></h1>
            <hr class="wp-header-end" />

            <p><?php echo esc_html__( 'Read-only summaries from the crawler-verification engines. Verification never blocks anyone: a crawler claim it cannot prove is merely unproven. Device signals arrive in a later version.', 'greenpng' ); ?></p>

            <h2><?php echo esc_html__( 'FCrDNS verification', 'greenpng' ); ?></h2>
            <?php if ( 0 < (int) $verified + (int) $unverified ) : ?>
                <p>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: 1: verified count, 2: unverified count, 3: hours. */
                            __( '%1$d verified and %2$d unverified verdicts in the last %3$d hours. Forward-confirmed reverse DNS: a PTR hostname counts only when resolving it forward again contains the original address, A and AAAA alike.', 'greenpng' ),
                            (int) $verified,
                            (int) $unverified,
                            self::FCRDNS_HOURS
                        )
                    );
                    ?>
                </p>
            <?php endif; ?>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php echo esc_html__( 'Address (masked)', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Claimed agent', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'PTR hostname', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Verdict', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Walks', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Last seen', 'greenpng' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php if ( array() === $verdicts ) : ?>
                        <tr><td colspan="6"><?php echo esc_html__( 'No crawler claims verified yet.', 'greenpng' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $verdicts as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( gr_mask_ip( (string) ( $row['ip'] ?? '' ) ) ); ?></td>
                                <td><?php echo esc_html( (string) ( $row['user_agent'] ?? '' ) ); ?></td>
                                <td><?php echo esc_html( '' === (string) ( $row['host'] ?? '' ) ? '—' : (string) $row['host'] ); ?></td>
                                <td><?php echo esc_html( (string) ( $row['action_taken'] ?? '' ) ); ?></td>
                                <td><?php echo esc_html( (string) ( $row['hit_count'] ?? '' ) ); ?></td>
                                <td><?php echo esc_html( (string) ( $row['last_seen'] ?? '' ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2><?php echo esc_html__( 'Scanner-UA engine', 'greenpng' ); ?></h2>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php echo esc_html__( 'User agent', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Hits', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Fold rows', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Last seen', 'greenpng' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php if ( array() === $agents ) : ?>
                        <tr><td colspan="4"><?php echo esc_html__( 'No scanner agents recorded in the window.', 'greenpng' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $agents as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( (string) ( $row['user_agent'] ?? '' ) ); ?></td>
                                <td><?php echo esc_html( (string) ( $row['hits'] ?? '' ) ); ?></td>
                                <td><?php echo esc_html( (string) ( $row['fold_rows'] ?? '' ) ); ?></td>
                                <td><?php echo esc_html( (string) ( $row['last_seen'] ?? '' ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2><?php echo esc_html__( 'bot_score distribution', 'greenpng' ); ?></h2>
            <?php if ( 0 < (int) $distribution['total'] ) : ?>
                <p>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: 1: human sessions, 2: bot sessions, 3: days. */
                            __( '%1$d human and %2$d bot-flagged sessions in the last %3$d days, by the client probe score the session carries.', 'greenpng' ),
                            (int) $humans,
                            (int) $distribution['bots'],
                            self::SCORE_DAYS
                        )
                    );
                    ?>
                </p>
            <?php endif; ?>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php echo esc_html__( 'Score band', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Sessions', 'greenpng' ); ?></th>
                    <th><?php echo esc_html__( 'Share', 'greenpng' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php if ( 0 === (int) $distribution['total'] ) : ?>
                        <tr><td colspan="3"><?php echo esc_html__( 'No scored sessions in the window.', 'greenpng' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $distribution['bands'] as $band => $count ) : ?>
                            <tr>
                                <td><?php echo esc_html( (string) $band ); ?></td>
                                <td><?php echo esc_html( number_format( (float) $count ) ); ?></td>
                                <td>
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            /* translators: %s: percentage. */
                                            __( '%s%%', 'greenpng' ),
                                            number_format( $count * 100 / max( 1, (int) $distribution['total'] ), 1 )
                                        )
                                    );
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
