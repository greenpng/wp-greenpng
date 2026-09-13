<?php
/**
 * Audit trail (docs/03 §8, docs/05 #13): the recursive diff battery,
 * the write-once log row shape, the filtered paginated read, the
 * page render with server-side pagination, and the access-rules
 * writes producing audit rows.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Admin\Gr_Access_Rules_Page;
use GreenPNG\Admin\Gr_Audit_Log_Page;
use GreenPNG\Core\Gr_Audit_Diff;
use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Security\Gr_Access_Rules;
use GreenPNG\Storage\Gr_Audit_Repository;
use PHPUnit\Framework\TestCase;

final class AuditLogTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        unset( $_POST, $_GET );
    }

    protected function tearDown(): void {
        unset( $_POST, $_GET );
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Recursive diff (docs/03 §8 acceptance battery).
    // ------------------------------------------------------------------

    public function testDiffReportsAddedModifiedRemovedAtTheRoot(): void {
        $old = array( 'keep' => 'a', 'gone' => 'b', 'changed' => 'b' );
        $new = array( 'keep' => 'a', 'changed' => 'c', 'fresh' => 'd' );

        $diff = Gr_Audit_Diff::diff( $old, $new );

        $this->assertSame( array( 'fresh' => 'd' ), $diff['added'] );
        $this->assertSame( array( 'changed' => array( 'old' => 'b', 'new' => 'c' ) ), $diff['modified'] );
        $this->assertSame( array( 'gone' => 'b' ), $diff['removed'] );
    }

    public function testDiffRecursesIntoSharedArrayKeys(): void {
        $old = array(
            'rule' => array(
                'value' => '1.2.3.4',
                'flags' => array( 'active' => 1, 'audit' => 1 ),
            ),
        );
        $new = array(
            'rule' => array(
                'value' => '1.2.3.4',
                'flags' => array( 'active' => 0, 'audit' => 1 ),
            ),
        );

        $diff = Gr_Audit_Diff::diff( $old, $new );

        $this->assertSame( array(), $diff['added'] );
        $this->assertSame( array(), $diff['removed'] );
        $this->assertSame( array( 'rule.flags.active' => array( 'old' => 1, 'new' => 0 ) ), $diff['modified'] );
    }

    public function testDiffNestsAdditionsAndRemovalsUnderTheirParents(): void {
        $old = array( 'a' => array( 'x' => 1, 'y' => 2 ) );
        $new = array( 'a' => array( 'x' => 1, 'z' => 3 ) );

        $diff = Gr_Audit_Diff::diff( $old, $new );

        $this->assertSame( array( 'a.z' => 3 ), $diff['added'] );
        $this->assertSame( array( 'a.y' => 2 ), $diff['removed'] );
        $this->assertSame( array(), $diff['modified'] );
    }

    public function testDiffTreatsArrayTypeTransitionsAsOneModification(): void {
        $old = array( 'v' => array( 'x' => 1 ) );
        $new = array( 'v' => 'text' );

        $diff = Gr_Audit_Diff::diff( $old, $new );

        $this->assertSame( array(), $diff['added'] );
        $this->assertSame( array(), $diff['removed'] );
        $this->assertSame(
            array( 'v' => array( 'old' => array( 'x' => 1 ), 'new' => 'text' ) ),
            $diff['modified']
        );
    }

    public function testDiffIsStrictAboutTypes(): void {
        $diff = Gr_Audit_Diff::diff( array( 'n' => '1' ), array( 'n' => 1 ) );

        $this->assertSame( array(), $diff['added'] );
        $this->assertSame( array(), $diff['removed'] );
        $this->assertSame( array( 'n' => array( 'old' => '1', 'new' => 1 ) ), $diff['modified'] );
    }

    public function testIdenticalStatesProduceAnEmptyDiff(): void {
        $state = array( 'a' => 1, 'b' => array( 'c' => 'x' ) );

        $diff = Gr_Audit_Diff::diff( $state, $state );

        $this->assertSame( array( 'added' => array(), 'modified' => array(), 'removed' => array() ), $diff );
    }

    public function testDiffFacadeMatchesTheClass(): void {
        $old = array( 'n' => 1 );
        $new = array( 'n' => 2, 'k' => true );

        $this->assertSame( Gr_Audit_Diff::diff( $old, $new ), gr_audit_diff( $old, $new ) );
    }

    // ------------------------------------------------------------------
    // Repository: the write-once row.
    // ------------------------------------------------------------------

    public function testLogComputesTheDiffOnceAtWriteTime(): void {
        $GLOBALS['gr_stub_user_id'] = 7;
        $GLOBALS['gr_stub_now']     = '2026-09-20 10:00:00';

        $id = ( new Gr_Audit_Repository() )->log(
            'toggle',
            'access_rule',
            '12',
            array( 'is_active' => 1 ),
            array( 'is_active' => 0 ),
            7
        );

        $this->assertSame( 1, $id );

        $row = $GLOBALS['wpdb']->inserts[0];
        $this->assertSame( 'wp_gr_audit_logs', $row['table'] );
        $this->assertSame( 7, $row['data']['user_id'] );
        $this->assertSame( 'toggle', $row['data']['action'] );
        $this->assertSame( 'access_rule', $row['data']['object_type'] );
        $this->assertSame( '12', $row['data']['object_id'] );
        $this->assertSame( '2026-09-20 10:00:00', $row['data']['created_at'] );

        // diff_json carries exactly the stored diff, not raw states.
        $decoded = json_decode( (string) $row['data']['diff_json'], true );
        $this->assertSame( array(), $decoded['added'] );
        $this->assertSame( array(), $decoded['removed'] );
        $this->assertSame( array( 'is_active' => array( 'old' => 1, 'new' => 0 ) ), $decoded['modified'] );
    }

    public function testLogFacadeMatchesTheRepository(): void {
        gr_stub_reset_options();
        gr_audit_log( 'add', 'access_rule', '3', array(), array( 'k' => 'v' ), 5 );
        $via_facade = $GLOBALS['wpdb']->inserts;

        gr_stub_reset_options();
        ( new Gr_Audit_Repository() )->log( 'add', 'access_rule', '3', array(), array( 'k' => 'v' ), 5 );
        $via_class = $GLOBALS['wpdb']->inserts;

        $this->assertSame( $via_class, $via_facade );
    }

    // ------------------------------------------------------------------
    // Repository: the filtered, paginated read.
    // ------------------------------------------------------------------

    public function testQueryWithoutFiltersCountsUnpreparedAndPages(): void {
        $GLOBALS['wpdb']->var_result = '45';
        $GLOBALS['wpdb']->results    = static function ( string $sql ): array {
            return array( array( 'id' => '1', 'action' => 'add' ) );
        };

        $result = ( new Gr_Audit_Repository() )->query( array(), 20, 20 );

        // The count ran without placeholders.
        $this->assertStringContainsString( 'SELECT COUNT(*) FROM wp_gr_audit_logs', $GLOBALS['wpdb']->queries[0] );
        $this->assertSame( 45, $result['total'] );

        // The page read carries ORDER and the LIMIT/OFFSET pair.
        $page_sql = $GLOBALS['wpdb']->queries[1];
        $this->assertStringContainsString( 'FROM wp_gr_audit_logs', $page_sql );
        $this->assertStringContainsString( 'ORDER BY created_at DESC, id DESC', $page_sql );
        $this->assertStringContainsString( 'LIMIT 20 OFFSET 20', $page_sql );
        $this->assertSame( array( array( 'id' => '1', 'action' => 'add' ) ), $result['rows'] );
    }

    public function testQueryBuildsWhitelistedFilterClausesOnly(): void {
        $GLOBALS['wpdb']->var_result = '2';

        ( new Gr_Audit_Repository() )->query(
            array(
                'object_type' => 'access_rule',
                'action'      => 'toggle',
                'user_id'     => 7,
                'intruder'    => 'DROP TABLE',
            )
        );

        $count_sql = $GLOBALS['wpdb']->queries[0];
        // prepare() and get_var() each record the statement, so the
        // page read is the third recorded line.
        $page_sql  = $GLOBALS['wpdb']->queries[2];

        foreach ( array( $count_sql, $page_sql ) as $sql ) {
            $this->assertStringContainsString( "user_id = 7", $sql );
            $this->assertStringContainsString( "object_type = 'access_rule'", $sql );
            $this->assertStringContainsString( "action = 'toggle'", $sql );
            $this->assertStringNotContainsString( 'intruder', $sql );
        }

        // The page read always appends its pagination pair.
        $this->assertStringContainsString( 'LIMIT 50 OFFSET 0', $page_sql );
    }

    public function testQueryClampsItsPaginationArguments(): void {
        $GLOBALS['wpdb']->var_result = '0';

        ( new Gr_Audit_Repository() )->query( array(), 9999, -5 );

        // The count ran unprepared (no filters), the page read behind it.
        $this->assertStringContainsString( 'SELECT COUNT(*) FROM wp_gr_audit_logs', $GLOBALS['wpdb']->queries[0] );
        $this->assertStringContainsString( 'LIMIT 5000 OFFSET 0', $GLOBALS['wpdb']->queries[1] );
    }

    public function testQueryBuildsTheDateRangeOnCreatedAt(): void {
        $GLOBALS['wpdb']->var_result = '3';

        ( new Gr_Audit_Repository() )->query(
            array(
                'from' => '2026-09-01',
                'to'   => '2026-09-30',
            )
        );

        $count_sql = $GLOBALS['wpdb']->queries[0];
        $this->assertStringContainsString( "created_at >= '2026-09-01 00:00:00'", $count_sql );
        $this->assertStringContainsString( "created_at <= '2026-09-30 23:59:59'", $count_sql );

        // Malformed dates stay out of the WHERE entirely.
        $GLOBALS['wpdb']->queries = array();
        ( new Gr_Audit_Repository() )->query( array( 'from' => 'yesterday', 'to' => '' ) );
        $this->assertStringNotContainsString( 'created_at >=', $GLOBALS['wpdb']->queries[0] );
    }

    public function testQuerySearchesTheActionableObjectColumns(): void {
        $GLOBALS['wpdb']->var_result = '1';

        ( new Gr_Audit_Repository() )->query( array( 's' => 'rule' ) );

        $count_sql = $GLOBALS['wpdb']->queries[0];
        $this->assertStringContainsString( 'action LIKE', $count_sql );
        $this->assertStringContainsString( 'object_type LIKE', $count_sql );
        $this->assertStringContainsString( 'object_id LIKE', $count_sql );
        $this->assertSame( 3, substr_count( $count_sql, "'%rule%'" ) );

        // The stored diff never enters the search vocabulary.
        $this->assertStringNotContainsString( 'diff_json LIKE', $count_sql );
    }

    public function testQueryFacadeMatchesTheRepository(): void {
        $GLOBALS['wpdb']->var_result = '1';
        $GLOBALS['wpdb']->results    = static function ( string $sql ): array {
            return array( array( 'id' => '9' ) );
        };

        $filters = array( 'object_type' => 'access_rule' );

        $this->assertSame(
            ( new Gr_Audit_Repository() )->query( $filters, 20, 0 ),
            gr_audit_query( $filters, 20, 0 )
        );
    }

    // ------------------------------------------------------------------
    // Page render with server-side pagination.
    // ------------------------------------------------------------------

    public function testRenderEmptyStateInventsNothingAndSkipsPagination(): void {
        $GLOBALS['wpdb']->var_result = '0';

        ob_start();
        Gr_Audit_Log_Page::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'Audit Log', $html );
        $this->assertStringContainsString( 'No audited changes recorded yet.', $html );
        $this->assertStringNotContainsString( 'page-numbers', $html );
        $this->assertSame( array(), $GLOBALS['gr_stub_paginate_calls'] );
    }

    public function testRenderPaginatesBeyondOnePage(): void {
        $GLOBALS['wpdb']->var_result = '45';

        ob_start();
        Gr_Audit_Log_Page::render();
        $html = (string) ob_get_clean();

        // Server-side math: 45 rows over 20 per page = 3 pages.
        $calls = $GLOBALS['gr_stub_paginate_calls'];
        $this->assertCount( 1, $calls );
        $this->assertSame( 3, $calls[0]['total'] );
        $this->assertSame( 1, $calls[0]['current'] );
        $this->assertStringContainsString( '?paged=%#%', $calls[0]['base'] );

        // Core-built anchors reached the page, current marked.
        $this->assertStringContainsString( 'page-numbers current">1<', $html );
        $this->assertStringContainsString( 'href="?paged=2"', $html );
    }

    public function testRenderHonorsThePageNumberAndFilters(): void {
        $GLOBALS['wpdb']->var_result = '25';
        $_GET['paged']       = '2';
        $_GET['object_type'] = 'access_rule';
        $_GET['user_id']     = '7';
        $_GET['action']      = 'toggle';

        ob_start();
        Gr_Audit_Log_Page::render();
        $html = (string) ob_get_clean();

        // The read narrowed and moved to the second window (the count
        // line records twice: once from prepare, once from get_var).
        $page_sql = $GLOBALS['wpdb']->queries[2];
        $this->assertStringContainsString( 'LIMIT 20 OFFSET 20', $page_sql );
        $this->assertStringContainsString( "object_type = 'access_rule'", $page_sql );
        $this->assertStringContainsString( 'user_id = 7', $page_sql );
        $this->assertStringContainsString( "action = 'toggle'", $page_sql );

        // The current page followed, and the filters ride the form.
        $this->assertSame( 2, $GLOBALS['gr_stub_paginate_calls'][0]['current'] );
        $this->assertStringContainsString( 'value="access_rule"', $html );
        $this->assertStringContainsString( 'value="7"', $html );
        $this->assertStringContainsString( 'value="toggle"', $html );

        // The shared date-range and search controls joined the form,
        // and the CSV export mirrors every active filter.
        $this->assertStringContainsString( 'id="gr-filter-from"', $html );
        $this->assertStringContainsString( 'id="gr-filter-search"', $html );
        $this->assertStringContainsString( 'Export CSV', $html );
        $this->assertStringContainsString( '/wp-json/greenpng/v1/export/audit', $html );
        $this->assertStringContainsString( 'object_type=access_rule', $html );
        $this->assertStringContainsString( 'action=toggle', $html );
    }

    public function testRenderExpandsTheStoredDiffIntoLines(): void {
        $GLOBALS['wpdb']->var_result = '1';
        $GLOBALS['wpdb']->results    = static function ( string $sql ): array {
            return array(
                array(
                    'id'          => '1',
                    'user_id'     => '7',
                    'action'      => 'toggle',
                    'object_type' => 'access_rule',
                    'object_id'   => '12',
                    'created_at'  => '2026-09-20 10:00:00',
                    'diff_json'   => (string) wp_json_encode(
                        array(
                            'added'    => array( 'note' => 'from tests' ),
                            'modified' => array( 'is_active' => array( 'old' => 1, 'new' => 0 ) ),
                            'removed'  => array( 'legacy' => 'x' ),
                        )
                    ),
                ),
            );
        };

        ob_start();
        Gr_Audit_Log_Page::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( '<td class="column-created_at">2026-09-20 10:00:00</td>', $html );
        $this->assertStringContainsString( '<td class="column-user_id">#7</td>', $html );
        $this->assertStringContainsString( 'access_rule #12', $html );
        $this->assertStringContainsString( '+ note = from tests', $html );
        $this->assertStringContainsString( '~ is_active: 1 → 0', $html );
        $this->assertStringContainsString( '- legacy', $html );
    }

    public function testMenuRegistersTheAuditSubmenu(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        do_action( 'admin_menu' );
        $slugs = array();
        foreach ( $GLOBALS['gr_stub_submenu_pages'] as $page ) {
            $slugs[] = $page['parent_slug'] . ':' . $page['menu_slug'];
        }
        $this->assertContains( 'greenpng-dashboard:greenpng-audit', $slugs );
    }

    // ------------------------------------------------------------------
    // Access-rules writes produce audit rows.
    // ------------------------------------------------------------------

    /**
     * A valid, gate-passing add POST body.
     *
     * @return void
     */
    private function post_add(): void {
        $_POST = array(
            'gr_access_action' => 'add',
            'tab'              => 'ban',
            'match_kind'       => 'ip',
            'match_value'      => '203.0.113.0/24',
            'note'             => 'probe network',
            Gr_Access_Rules_Page::NONCE_FIELD => 'gr-stub-nonce-' . md5( Gr_Access_Rules_Page::NONCE_ACTION ),
        );
        $GLOBALS['gr_stub_caps'] = array( 'manage_options' );
    }

    public function testAccessRuleAddWritesAnAuditRow(): void {
        $GLOBALS['gr_stub_user_id'] = 7;
        $this->post_add();

        Gr_Access_Rules_Page::handle_actions();

        // Rule row first, audit row behind it.
        $audit = array();
        foreach ( $GLOBALS['wpdb']->inserts as $insert ) {
            if ( 'wp_gr_audit_logs' === $insert['table'] ) {
                $audit[] = $insert;
            }
        }
        $this->assertCount( 1, $audit );
        $this->assertSame( 'add', $audit[0]['data']['action'] );
        $this->assertSame( 'access_rule', $audit[0]['data']['object_type'] );
        $this->assertSame( '1', (string) $audit[0]['data']['object_id'] );
        $this->assertSame( 7, $audit[0]['data']['user_id'] );

        // The row records exactly the semantic fields of the add.
        $decoded = json_decode( (string) $audit[0]['data']['diff_json'], true );
        $this->assertSame( array(), $decoded['modified'] );
        $this->assertSame( array(), $decoded['removed'] );
        $this->assertSame( 'ban', $decoded['added']['rule_type'] );
        $this->assertSame( '203.0.113.0/24', $decoded['added']['match_value'] );
        $this->assertSame( 1, $decoded['added']['is_active'] );
    }

    public function testAccessRuleToggleWritesBeforeAndAfterStates(): void {
        $GLOBALS['gr_stub_user_id'] = 7;
        $this->post_add();
        $_POST['gr_access_action'] = 'toggle';
        $_POST['rule_id']          = '5';
        $_POST['to_active']        = '0';

        // The pre-write snapshot read answers with the old state.
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'WHERE id = 5' ) ) {
                return array(
                    array(
                        'id'          => '5',
                        'rule_type'   => 'ban',
                        'match_kind'  => 'ip',
                        'match_value' => '203.0.113.0/24',
                        'note'        => '',
                        'is_active'   => '1',
                        'created_by'  => '7',
                        'created_at'  => '2026-09-12 09:00:00',
                        'updated_at'  => '2026-09-12 09:00:00',
                    ),
                );
            }

            return array();
        };
        $GLOBALS['wpdb']->query_result = 1;

        Gr_Access_Rules_Page::handle_actions();

        $audit = array();
        foreach ( $GLOBALS['wpdb']->inserts as $insert ) {
            if ( 'wp_gr_audit_logs' === $insert['table'] ) {
                $audit[] = $insert;
            }
        }
        $this->assertCount( 1, $audit );
        $this->assertSame( 'toggle', $audit[0]['data']['action'] );
        $this->assertSame( '5', (string) $audit[0]['data']['object_id'] );

        $decoded = json_decode( (string) $audit[0]['data']['diff_json'], true );
        $this->assertSame(
            array( 'is_active' => array( 'old' => 1, 'new' => 0 ) ),
            $decoded['modified']
        );
    }

    public function testAccessRuleDeleteSnapshotsTheRemovedRow(): void {
        $GLOBALS['gr_stub_user_id'] = 7;
        $this->post_add();
        $_POST['gr_access_action'] = 'delete';
        $_POST['rule']             = array( '4' );

        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'WHERE id = 4' ) ) {
                return array(
                    array(
                        'id'          => '4',
                        'rule_type'   => 'ban',
                        'match_kind'  => 'ip',
                        'match_value' => '198.51.100.0/24',
                        'note'        => 'old range',
                        'is_active'   => '1',
                        'created_by'  => '1',
                        'created_at'  => '2026-09-01 09:00:00',
                        'updated_at'  => '2026-09-01 09:00:00',
                    ),
                );
            }

            return array();
        };
        $GLOBALS['wpdb']->query_result = 1;

        Gr_Access_Rules_Page::handle_actions();

        $audit = array();
        foreach ( $GLOBALS['wpdb']->inserts as $insert ) {
            if ( 'wp_gr_audit_logs' === $insert['table'] ) {
                $audit[] = $insert;
            }
        }
        $this->assertCount( 1, $audit );
        $this->assertSame( 'delete', $audit[0]['data']['action'] );
        $this->assertSame( '4', (string) $audit[0]['data']['object_id'] );

        // The full removed state is the snapshot: every field read as
        // a removal entry, nothing added, nothing modified.
        $decoded = json_decode( (string) $audit[0]['data']['diff_json'], true );
        $this->assertSame( array(), $decoded['added'] );
        $this->assertSame( array(), $decoded['modified'] );
        $this->assertSame( '198.51.100.0/24', $decoded['removed']['match_value'] );
        $this->assertSame( 'old range', $decoded['removed']['note'] );
    }

    public function testGateFailuresLeaveNoAuditRow(): void {
        $this->post_add();
        $GLOBALS['gr_stub_caps'] = false;

        Gr_Access_Rules_Page::handle_actions();

        $this->assertSame( array(), $GLOBALS['wpdb']->inserts );
    }
}
