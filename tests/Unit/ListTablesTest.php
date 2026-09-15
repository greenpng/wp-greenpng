<?php
/**
 * WP_List_Table subclasses (docs/06): the access-rules and audit-log
 * tables must render their rows on pages that register no list screen.
 * The live E2E round caught both rendering empty — core resolves
 * column headers from the registered screen when none are declared,
 * and our pages register none — so the constructors declare the
 * header tuple explicitly and these tests pin the render end to end.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Admin\Gr_Access_Rules_Table;
use GreenPNG\Admin\Gr_Audit_Log_Table;
use GreenPNG\Admin\Gr_Sessions_Table;
use PHPUnit\Framework\TestCase;

final class ListTablesTest extends TestCase {

    /**
     * Renders a table to a string.
     *
     * @param \WP_List_Table $table Table instance.
     * @return string Captured markup.
     */
    private function render( \WP_List_Table $table ): string {
        ob_start();
        $table->display();

        return (string) ob_get_clean();
    }

    public function testAccessRulesTableRendersRowsWithoutAScreen(): void {
        $table = new Gr_Access_Rules_Table(
            array( 'singular' => 'rule', 'plural' => 'rules', 'ajax' => false ),
            array(
                array(
                    'id'          => '7',
                    'match_value' => '198.51.100.7',
                    'match_kind'  => 'ip',
                    'note'        => 'CI ban fixture',
                    'is_active'   => '1',
                    'created_at'  => '2026-09-13 07:00:00',
                ),
            )
        );

        $html = $this->render( $table );

        self::assertStringContainsString( '198.51.100.7', $html, 'The rule value must reach the markup.' );
        self::assertStringContainsString( 'CI ban fixture', $html, 'The note cell must reach the markup.' );
        self::assertStringContainsString( 'value="7"', $html, 'The checkbox column must address the row id.' );
        self::assertStringContainsString( '>Value<', $html, 'The header row must render the column vocabulary.' );
    }

    public function testAccessRulesTableEmptyListKeepsItsMessage(): void {
        $table = new Gr_Access_Rules_Table( array(), array() );

        self::assertStringContainsString( 'No rules yet.', $this->render( $table ) );
    }

    public function testAuditLogTableRendersRowsWithoutAScreen(): void {
        $table = new Gr_Audit_Log_Table(
            array( 'singular' => 'entry', 'plural' => 'entries', 'ajax' => false ),
            array(
                array(
                    'created_at'  => '2026-09-13 07:00:00',
                    'user_id'     => '1',
                    'action'      => 'add',
                    'object_type' => 'access_rule',
                    'object_id'   => '7',
                    'changes'     => array( 'rule_type: ban', 'match_value: 198.51.100.7' ),
                ),
            )
        );

        $html = $this->render( $table );

        self::assertStringContainsString( 'access_rule #7', $html, 'The object cell joins family and identifier.' );
        self::assertStringContainsString( 'rule_type: ban', $html, 'The changes cell renders the prepared lines.' );
        self::assertStringContainsString( '>Time<', $html, 'The header row must render the column vocabulary.' );
    }

    public function testSessionsTableRendersRowsWithoutAScreen(): void {
        $table = new Gr_Sessions_Table(
            array( 'singular' => 'session_row', 'plural' => 'session_rows', 'ajax' => false ),
            array(
                array(
                    'started_at'   => '2026-09-13 06:00:00',
                    'last_active'  => '2026-09-13 07:00:00',
                    'visitor_id'   => 'abcdef1234567890abcdef1234567890',
                    'channel'      => 'organic',
                    'utm_campaign' => '',
                    'landing_path' => '/pricing/',
                    'device_type'  => 'desktop',
                    'pageviews'    => '3',
                    'is_bot'       => '0',
                    'ip_quality'   => 'hosting',
                ),
            )
        );

        $html = $this->render( $table );

        self::assertStringContainsString( '>Visitor<', $html, 'The header row must render the column vocabulary.' );
        self::assertStringContainsString( '>Landing<', $html, 'The header row must render the column vocabulary.' );
        self::assertStringContainsString( '>Hosting<', $html, 'The hosting column joins the vocabulary.' );
        self::assertStringContainsString( '<td class="column-ip_quality">Yes</td>', $html, 'A datacenter session renders the shared Yes/No vocabulary.' );
        self::assertStringContainsString( '<td class="column-visitor_id"><span title="abcdef1234567890abcdef1234567890">abcdef12…</span></td>', $html, 'The short form renders with the full identity on the title.' );
        self::assertStringContainsString( '<td class="column-landing_path">/pricing/</td>', $html, 'The landing path cell renders as text.' );
        self::assertStringContainsString( '<td class="column-device_type">desktop</td>', $html, 'The device cell renders the code.' );
        self::assertStringContainsString( '<td class="column-pageviews">3</td>', $html, 'The pageview depth renders.' );
        self::assertStringContainsString( 'title="2026-09-13 07:00:00"', $html, 'Time-ago cells carry the exact stamp for assistive tech.' );
        self::assertStringNotContainsString( '<th scope="col">IP', $html, 'No IP column exists in the vocabulary.' );
        self::assertStringNotContainsString( 'ua_family', $html, 'No user-agent column exists in the vocabulary.' );
    }

    public function testSessionsTableBotVerdictAndEmptyState(): void {
        $table = new Gr_Sessions_Table(
            array(),
            array(
                array(
                    'started_at'   => '2026-09-13 06:00:00',
                    'last_active'  => '2026-09-13 07:00:00',
                    'visitor_id'   => 'abcdef1234567890abcdef1234567890',
                    'channel'      => 'direct',
                    'utm_campaign' => '',
                    'landing_path' => '/',
                    'device_type'  => 'other',
                    'pageviews'    => '1',
                    'is_bot'       => '1',
                ),
            )
        );

        $html = $this->render( $table );
        self::assertStringContainsString( '<td class="column-is_bot">Yes</td>', $html, 'The bot verdict renders the same vocabulary as the fraud tab.' );
        self::assertStringContainsString( '<td class="column-ip_quality">No</td>', $html, 'A residential session renders No without a verdict of its own.' );

        $empty = new Gr_Sessions_Table( array(), array() );
        self::assertStringContainsString( 'No visitor sessions recorded yet.', $this->render( $empty ) );
    }
}
