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
}
