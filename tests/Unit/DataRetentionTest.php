<?php
/**
 * Data retention (docs/05 §5): the dual-rail rider's SQL shapes and
 * budget wall, the manual-only OPTIMIZE separation (a machine
 * assertion — the rider code never optimizes), the settings
 * persistence through the page's double gate, and the audit rows both
 * writes leave behind.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Admin\Gr_Data_Retention_Page;
use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Core\Gr_Settings;
use GreenPNG\Storage\Gr_Retention;
use PHPUnit\Framework\TestCase;

final class DataRetentionTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        unset( $_POST, $_GET );
    }

    protected function tearDown(): void {
        unset( $_POST, $_GET );
        parent::tearDown();
    }

    /**
     * A valid, gate-passing POST body for one action.
     *
     * @param string $action Action marker.
     * @return void
     */
    private function post( string $action ): void {
        $_POST = array(
            'gr_retention_action' => $action,
            Gr_Data_Retention_Page::NONCE_FIELD => 'gr-stub-nonce-' . md5( Gr_Data_Retention_Page::NONCE_ACTION ),
        );
        $GLOBALS['gr_stub_caps'] = array( 'manage_options' );
    }

    // ------------------------------------------------------------------
    // Age rail.
    // ------------------------------------------------------------------

    public function testPruneDeletesOldestFirstInBoundedBatches(): void {
        $GLOBALS['wpdb']->query_result = 0;

        $removed = Gr_Retention::prune( 'events', 'created_at', 30 );

        $this->assertSame( 0, $removed );
        $sql = $GLOBALS['wpdb']->queries[0];
        $this->assertStringContainsString( 'DELETE FROM wp_gr_events', $sql );
        $this->assertStringContainsString( 'WHERE created_at <', $sql );
        $this->assertStringContainsString( 'ORDER BY id ASC LIMIT 2000', $sql );
    }

    public function testPruneRefusesZeroDaysAndForeignColumns(): void {
        // Zero days = keep everything: not one statement.
        $this->assertSame( 0, Gr_Retention::prune( 'events', 'created_at', 0 ) );
        $this->assertSame( array(), $GLOBALS['wpdb']->queries );

        // Anything but an identifier shape is refused before SQL.
        $this->assertSame( 0, Gr_Retention::prune( 'events', 'x; DROP TABLE', 30 ) );
        $this->assertSame( array(), $GLOBALS['wpdb']->queries );
    }

    public function testPruneBatchLoopStopsOnShortBatch(): void {
        // A short batch (fewer rows than the batch size) ends the
        // loop after one statement.
        $GLOBALS['wpdb']->query_result = 7;

        $removed = Gr_Retention::prune( 'events', 'created_at', 30, 2000 );

        $this->assertSame( 7, $removed );
        // The stub records the prepared line and the executed line as
        // identical strings; one statement per batch is the real count.
        $deletes = array();
        foreach ( $GLOBALS['wpdb']->queries as $sql ) {
            if ( false !== strpos( (string) $sql, 'DELETE FROM wp_gr_events' ) ) {
                $deletes[] = (string) $sql;
            }
        }
        $this->assertCount( 1, array_unique( $deletes ) );
    }

    public function testPruneFacadeMatchesTheService(): void {
        $GLOBALS['wpdb']->query_result = 0;

        $this->assertSame(
            Gr_Retention::prune( 'audit_logs', 'created_at', 365 ),
            gr_prune_table( 'audit_logs', 'created_at', 365 )
        );
        $this->assertStringContainsString( 'DELETE FROM wp_gr_audit_logs', $GLOBALS['wpdb']->queries[0] );
    }

    // ------------------------------------------------------------------
    // Row-ceiling rail.
    // ------------------------------------------------------------------

    public function testPruneRowsFindsTheBoundaryThenDeletesBelowIt(): void {
        $GLOBALS['wpdb']->var_result    = '5000';
        $GLOBALS['wpdb']->query_result  = 0;

        $removed = Gr_Retention::prune_rows( 'sessions', 500000 );

        $this->assertSame( 0, $removed );
        $this->assertStringContainsString(
            'SELECT id FROM wp_gr_sessions ORDER BY id DESC LIMIT 500000, 1',
            $GLOBALS['wpdb']->queries[0]
        );
        // The boundary SELECT records twice (prepare + get_var), so
        // the DELETE follows at the third recorded line. The boundary
        // row itself is the first ejected row: the delete carries <=,
        // otherwise a table at ceiling+1 rows would trim zero.
        $this->assertStringContainsString( 'DELETE FROM wp_gr_sessions WHERE id <= 5000', $GLOBALS['wpdb']->queries[2] );
        $this->assertStringContainsString( 'ORDER BY id ASC LIMIT 2000', $GLOBALS['wpdb']->queries[2] );
    }

    public function testPruneRowsSkipsWhenUnderTheCeiling(): void {
        // No boundary row = the table is at or under its ceiling.
        $GLOBALS['wpdb']->var_result = null;

        $this->assertSame( 0, Gr_Retention::prune_rows( 'sessions', 500000 ) );
        $this->assertStringNotContainsString( 'DELETE', implode( ' ', $GLOBALS['wpdb']->queries ) );
    }

    // ------------------------------------------------------------------
    // The daily rider.
    // ------------------------------------------------------------------

    public function testDailyRiderRunsBothRailsFromStoredSettings(): void {
        $settings = new Gr_Settings();
        $settings->set( 'retention_days', array( 'events' => 30, 'sessions' => 90 ) );
        $settings->set( 'retention_rows', array( 'sessions' => 500000 ) );

        // Short batches and an under-ceiling boundary keep the pass
        // to one statement per table.
        $GLOBALS['wpdb']->query_result = 0;
        $GLOBALS['wpdb']->var_result   = null;

        Gr_Retention::run_daily( 60.0 );

        $sql = implode( "\n", $GLOBALS['wpdb']->queries );
        $this->assertStringContainsString( 'DELETE FROM wp_gr_events WHERE created_at <', $sql );
        $this->assertStringContainsString( 'DELETE FROM wp_gr_sessions WHERE started_at <', $sql );
        $this->assertStringContainsString( 'SELECT id FROM wp_gr_sessions ORDER BY id DESC LIMIT 500000, 1', $sql );

        // Untouched rails stay out of the pass.
        $this->assertStringNotContainsString( 'wp_gr_audit_logs', $sql );
        $this->assertStringNotContainsString( 'wp_gr_daily_stats', $sql );
    }

    public function testDailyRiderRespectsTheTimeBudget(): void {
        // A zero budget means the wall is already behind us: the pass
        // plans nothing and the database stays untouched.
        Gr_Retention::run_daily( 0.0 );

        $this->assertSame( array(), $GLOBALS['wpdb']->queries );
    }

    public function testDailyRiderNeverOptimizes(): void {
        // Machine assertion for the standing rule (docs/05 §5): the
        // rider's own code paths carry no OPTIMIZE — rebuilds are
        // reachable only through the human-pressed button.
        foreach ( array( 'run_daily', 'prune', 'prune_rows' ) as $method ) {
            $reflection = new \ReflectionMethod( Gr_Retention::class, $method );
            $start      = $reflection->getStartLine();
            $end        = $reflection->getEndLine();
            $source     = implode( "\n", array_slice( file( $reflection->getFileName() ), $start - 1, $end - $start + 1 ) );

            $this->assertStringNotContainsString( 'OPTIMIZE', $source, $method . ' must never optimize' );
        }
    }

    public function testOptimizeIsTheManualOnlyRebuild(): void {
        $GLOBALS['wpdb']->query_result = 0;

        $this->assertTrue( Gr_Retention::optimize( 'sessions' ) );
        $this->assertStringContainsString( 'OPTIMIZE TABLE wp_gr_sessions', $GLOBALS['wpdb']->queries[0] );

        // A refused statement reports failure, not a quiet skip.
        $GLOBALS['wpdb']->query_result = false;
        $this->assertFalse( Gr_Retention::optimize( 'events' ) );
    }

    // ------------------------------------------------------------------
    // Settings defaults and the page.
    // ------------------------------------------------------------------

    public function testBothRailsHaveDefaultsForEveryPrunableTable(): void {
        $settings = new Gr_Settings();
        $days     = (array) $settings->get( 'retention_days', array() );
        $rows     = (array) $settings->get( 'retention_rows', array() );

        foreach ( Gr_Retention::prunable_tables() as $key ) {
            $this->assertArrayHasKey( $key, $days );
            $this->assertArrayHasKey( $key, $rows );
            $this->assertGreaterThan( 0, (int) $days[ $key ] );
        }

        // The summary table rides the age rail only.
        $this->assertSame( 0, (int) $rows['daily_stats'] );
    }

    public function testRenderShowsRailsCountsAndTheStandingRule(): void {
        $GLOBALS['wpdb']->var_result = '12345';

        ob_start();
        Gr_Data_Retention_Page::render();
        $html = (string) ob_get_clean();

        // One row per prunable table: name, live count, both inputs.
        foreach ( Gr_Retention::prunable_tables() as $key ) {
            $this->assertStringContainsString( 'gr_' . $key, $html );
            $this->assertStringContainsString( 'name="ret_days[' . $key . ']"', $html );
            $this->assertStringContainsString( 'name="ret_rows[' . $key . ']"', $html );
        }
        $this->assertStringContainsString( '12,345', $html );

        // The rebuild block: checkboxes, button, the rule in words.
        $this->assertStringContainsString( 'name="optimize_tables[]"', $html );
        $this->assertSame( 8, substr_count( $html, 'name="optimize_tables[]"' ) );
        $this->assertStringContainsString( 'never optimizes on its own', $html );

        // Both forms carry the nonce.
        $this->assertSame( 2, substr_count( $html, 'name="_gr_retention_nonce"' ) );
    }

    public function testSavePersistsClampedRailsAndAuditsTheChange(): void {
        $GLOBALS['gr_stub_user_id'] = 7;
        $this->post( Gr_Data_Retention_Page::ACTION_SAVE );
        $_POST['ret_days'] = array(
            'events' => '14',
            'bogus'  => '1',
        );
        $_POST['ret_rows'] = array(
            'events' => '5000',
            'events_injected' => '999999999999',
        );

        Gr_Data_Retention_Page::handle_actions();

        $settings = new Gr_Settings();
        $days     = (array) $settings->get( 'retention_days', array() );
        $rows     = (array) $settings->get( 'retention_rows', array() );

        // The whitelisted key saved; the foreign key never landed.
        $this->assertSame( 14, $days['events'] );
        $this->assertSame( 5000, $rows['events'] );
        $this->assertArrayNotHasKey( 'bogus', $days );
        $this->assertArrayNotHasKey( 'events_injected', $rows );

        // Other keys keep their stored values.
        $this->assertSame( 30, $days['security_logs'] );

        // PRG carries the saved flag.
        $this->assertCount( 1, $GLOBALS['gr_stub_redirects'] );
        $this->assertStringContainsString( 'page=greenpng-retention', $GLOBALS['gr_stub_redirects'][0]['location'] );
        $this->assertStringContainsString( 'gr_saved=1', $GLOBALS['gr_stub_redirects'][0]['location'] );

        // The audit row records the rail change through the recursive
        // diff: events moved on both rails.
        $audit = array();
        foreach ( $GLOBALS['wpdb']->inserts as $insert ) {
            if ( 'wp_gr_audit_logs' === $insert['table'] ) {
                $audit[] = $insert;
            }
        }
        $this->assertCount( 1, $audit );
        $this->assertSame( 'save', $audit[0]['data']['action'] );
        $this->assertSame( 'retention', $audit[0]['data']['object_type'] );

        $decoded = json_decode( (string) $audit[0]['data']['diff_json'], true );
        $this->assertSame(
            array( 'old' => 30, 'new' => 14 ),
            $decoded['modified']['retention_days.events']
        );
        $this->assertSame(
            array( 'old' => 1000000, 'new' => 5000 ),
            $decoded['modified']['retention_rows.events']
        );
    }

    public function testSaveClampsOverCeilingValues(): void {
        $this->post( Gr_Data_Retention_Page::ACTION_SAVE );
        $_POST['ret_days'] = array( 'events' => '99999999' );
        $_POST['ret_rows'] = array( 'events' => '999999999999' );

        Gr_Data_Retention_Page::handle_actions();

        $settings = new Gr_Settings();
        $this->assertSame( Gr_Data_Retention_Page::MAX_DAYS, (int) $settings->get( 'retention_days' )['events'] );
        $this->assertSame( Gr_Data_Retention_Page::MAX_ROWS, (int) $settings->get( 'retention_rows' )['events'] );
    }

    public function testOptimizePostRebuildsCheckedTablesOnlyAndAuditsEach(): void {
        $GLOBALS['gr_stub_user_id'] = 7;
        $GLOBALS['wpdb']->query_result = 0;
        $this->post( Gr_Data_Retention_Page::ACTION_OPTIMIZE );
        $_POST['optimize_tables'] = array( 'sessions', 'not_a_table' );

        Gr_Data_Retention_Page::handle_actions();

        $sql = implode( "\n", $GLOBALS['wpdb']->queries );
        $this->assertStringContainsString( 'OPTIMIZE TABLE wp_gr_sessions', $sql );
        $this->assertSame( 1, substr_count( $sql, 'OPTIMIZE TABLE' ) );

        $audit = array();
        foreach ( $GLOBALS['wpdb']->inserts as $insert ) {
            if ( 'wp_gr_audit_logs' === $insert['table'] ) {
                $audit[] = $insert;
            }
        }
        $this->assertCount( 1, $audit );
        $this->assertSame( 'optimize', $audit[0]['data']['action'] );
        $this->assertSame( 'table', $audit[0]['data']['object_type'] );
        $this->assertSame( 'sessions', $audit[0]['data']['object_id'] );
    }

    public function testGateFailuresLeaveSettingsAndTablesUntouched(): void {
        $this->post( Gr_Data_Retention_Page::ACTION_OPTIMIZE );
        $_POST['optimize_tables'] = array( 'sessions' );
        $GLOBALS['gr_stub_caps'] = false;

        Gr_Data_Retention_Page::handle_actions();

        $this->assertSame( array(), $GLOBALS['wpdb']->queries );
        $this->assertSame( array(), $GLOBALS['wpdb']->inserts );
        $this->assertSame( array(), $GLOBALS['gr_stub_redirects'] );
    }

    public function testMenuRegistersTheRetentionSubmenu(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        do_action( 'admin_menu' );
        $slugs = array();
        foreach ( $GLOBALS['gr_stub_submenu_pages'] as $page ) {
            $slugs[] = $page['parent_slug'] . ':' . $page['menu_slug'];
        }
        $this->assertContains( 'greenpng-dashboard:greenpng-retention', $slugs );

        // The rider rides the daily maintenance hook behind the
        // aggregator.
        $hooks = array();
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            if ( 'gr_cron_daily_maintenance' === (string) $registration['hook'] ) {
                $hooks[] = (int) $registration['priority'];
            }
        }
        $this->assertContains( 10, $hooks );
    }
}
