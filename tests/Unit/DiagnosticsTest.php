<?php
/**
 * Status & diagnostics (docs/03 §10, docs/09 §4): the catalog stats
 * read and its cache, the whitelisted export payload with the
 * no-secrets machine assertion, the page render, and the export gate.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Admin\Gr_Status_Page;
use GreenPNG\Core\Gr_Diagnostics;
use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Storage\Gr_Table_Stats;
use PHPUnit\Framework\TestCase;

final class DiagnosticsTest extends TestCase {

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
     * Canned catalog rows: one plugin table, one foreign table that
     * must be filtered out by the registry check.
     *
     * @return void
     */
    private function seed_catalog(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false === strpos( $sql, 'information_schema' ) ) {
                return array();
            }

            return array(
                array(
                    'table_name'   => 'wp_gr_sessions',
                    'table_rows'   => '1234',
                    'data_length'  => '1572864',
                    'index_length' => '524288',
                ),
                array(
                    'table_name'   => 'wp_posts',
                    'table_rows'   => '9999',
                    'data_length'  => '999999',
                    'index_length' => '0',
                ),
            );
        };
    }

    // ------------------------------------------------------------------
    // Table stats.
    // ------------------------------------------------------------------

    public function testStatsReadsTheCatalogAndFiltersForeignTables(): void {
        $this->seed_catalog();

        $stats = Gr_Table_Stats::stats();

        $this->assertStringContainsString( 'FROM information_schema.TABLES', $GLOBALS['wpdb']->queries[0] );
        $this->assertStringContainsString( 'table_schema = DATABASE()', $GLOBALS['wpdb']->queries[0] );
        $this->assertStringContainsString( 'LIKE ', $GLOBALS['wpdb']->queries[0] );

        // The plugin table lands with the four fields; the foreign
        // table never enters (registry validation).
        $this->assertArrayHasKey( 'sessions', $stats );
        $this->assertSame( 1234, $stats['sessions']['rows'] );
        $this->assertSame( 1572864, $stats['sessions']['data_bytes'] );
        $this->assertSame( 524288, $stats['sessions']['index_bytes'] );
        $this->assertSame( 2097152, $stats['sessions']['total_bytes'] );
        $this->assertArrayNotHasKey( 'posts', $stats );
        $this->assertArrayNotHasKey( 'wp_posts', $stats );

        // The read is cached: a second call adds no query.
        $before = count( $GLOBALS['wpdb']->queries );
        Gr_Table_Stats::stats();
        $this->assertSame( $before, count( $GLOBALS['wpdb']->queries ) );
    }

    public function testStatsFlushDropsTheCache(): void {
        $this->seed_catalog();
        Gr_Table_Stats::stats();
        Gr_Table_Stats::flush();

        Gr_Table_Stats::stats();
        $catalog_records = 0;
        foreach ( $GLOBALS['wpdb']->queries as $sql ) {
            if ( false !== strpos( (string) $sql, 'information_schema' ) ) {
                $catalog_records++;
            }
        }
        // Each catalog read records twice (prepare + execute), so
        // two flush-separated reads leave four records.
        $this->assertSame( 4, $catalog_records );
    }

    public function testStatsFacadeMatchesTheService(): void {
        $this->seed_catalog();

        $this->assertSame( Gr_Table_Stats::stats(), gr_get_table_stats() );
    }

    // ------------------------------------------------------------------
    // The export payload.
    // ------------------------------------------------------------------

    public function testExportCarriesTheWhitelistedFieldSet(): void {
        $this->seed_catalog();
        $GLOBALS['gr_stub_wp_version'] = '6.7';

        $export = Gr_Diagnostics::export();

        $this->assertSame( GR_VERSION, $export['plugin']['version'] );
        $this->assertSame( PHP_VERSION, $export['environment']['php'] );
        $this->assertSame( '6.7', $export['environment']['wp'] );
        $this->assertSame( 'MariaDB 12.3', $export['environment']['db'] );
        $this->assertFalse( $export['environment']['multisite'] );

        // Queue posture: no Action Scheduler in the test environment,
        // and the daily event schedule is surfaced.
        $this->assertSame( 'wp-cron', $export['queue']['backend'] );

        // Adapter vocabulary comes from the detector, mounted=false
        // when nothing is installed.
        $this->assertArrayHasKey( 'woocommerce', $export['adapters'] );
        $this->assertFalse( $export['adapters']['woocommerce']['mounted'] );

        // Table capacity rides along.
        $this->assertSame( 1234, $export['tables']['sessions']['rows'] );
    }

    public function testDbFamilyPrefersTheIdentityStringOverTheFlag(): void {
        $this->seed_catalog();

        // A version-only identity string and a null flag (the CLI
        // shape) still read as MariaDB when the string says so.
        $GLOBALS['wpdb']->server_info = '12.3.3-MariaDB';
        $this->assertSame( 'MariaDB 12.3', Gr_Diagnostics::export()['environment']['db'] );

        // A MySQL host spells neither signal.
        $GLOBALS['wpdb']->server_info   = '8.0.36';
        $GLOBALS['wpdb']->server_version = '8.0.36';
        $GLOBALS['wpdb']->is_mariadb    = false;
        $this->assertSame( 'MySQL 8.0.36', Gr_Diagnostics::export()['environment']['db'] );
    }

    public function testExportCarriesNoSecretsOrAddresses(): void {
        $this->seed_catalog();

        // Land poisoned values everywhere a lazy implementation
        // might read from: options, settings, and a secret-shaped key.
        add_option( 'gr_secret_meta', 'TOPSECRET-TOKEN-VALUE', '', 'no' );
        $settings = new \GreenPNG\Core\Gr_Settings();
        $settings->set( 'api_password', 'TOPSECRET-PASSWORD-VALUE' );
        update_option( 'gr_settings', array_merge( \GreenPNG\Core\Gr_Settings::defaults(), array( 'license' => 'TOPSECRET-LICENSE' ) ) );

        $json = (string) wp_json_encode( gr_export_diagnostics() );

        // Machine assertion for the acceptance: no credential value
        // and no address ever leaves.
        $this->assertStringNotContainsString( 'TOPSECRET', $json );
        $this->assertStringNotContainsString( 'secret', strtolower( $json ) );
        $this->assertStringNotContainsString( 'password', strtolower( $json ) );
        $this->assertStringNotContainsString( 'license', strtolower( $json ) );
        $this->assertStringNotContainsString( 'token', strtolower( $json ) );
        $this->assertDoesNotMatchRegularExpression( '/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', $json );

        // Cleanup the poisoned rows.
        delete_option( 'gr_secret_meta' );
        update_option( 'gr_settings', \GreenPNG\Core\Gr_Settings::defaults() );
    }

    public function testExportFacadeMatchesTheService(): void {
        $this->seed_catalog();

        $this->assertSame( Gr_Diagnostics::export(), gr_export_diagnostics() );
    }

    // ------------------------------------------------------------------
    // The page.
    // ------------------------------------------------------------------

    public function testRenderShowsEnvironmentQueueAdaptersAndBytes(): void {
        $this->seed_catalog();
        $GLOBALS['gr_stub_wp_version'] = '6.7';

        ob_start();
        Gr_Status_Page::render();
        $html = (string) ob_get_clean();

        // Environment rows with the real values.
        $this->assertStringContainsString( PHP_VERSION, $html );
        $this->assertStringContainsString( '>6.7<', $html );
        $this->assertStringContainsString( 'MariaDB 12.3', $html );

        // Queue posture in human words, with the low-traffic guidance.
        $this->assertStringContainsString( 'WP-Cron', $html );
        $this->assertStringContainsString( 'wp greenpng maintenance', $html );

        // Adapter roster and byte formatting.
        $this->assertStringContainsString( 'Not installed', $html );
        $this->assertStringContainsString( '1.5 MB', $html );
        $this->assertStringContainsString( '512.0 KB', $html );

        // The export form carries the nonce.
        $this->assertStringContainsString( 'name="_gr_status_nonce"', $html );
        $this->assertStringContainsString( 'Download diagnostics', $html );
    }

    public function testExportGateHoldsForAnonymousOrBadNonce(): void {
        $_POST = array(
            'gr_status_action'  => 'export',
            Gr_Status_Page::NONCE_FIELD => 'gr-stub-nonce-' . md5( Gr_Status_Page::NONCE_ACTION ),
        );
        $GLOBALS['gr_stub_caps'] = false;

        Gr_Status_Page::handle_actions();

        // Nothing ran: no audit row, no catalog read.
        $this->assertSame( array(), $GLOBALS['wpdb']->inserts );
        $this->assertSame( array(), $GLOBALS['wpdb']->queries );
    }

    public function testOtherActionsAreNotRouted(): void {
        $_POST = array( 'gr_status_action' => 'something-else' );
        $GLOBALS['gr_stub_caps'] = array( 'manage_options' );

        Gr_Status_Page::handle_actions();

        $this->assertSame( array(), $GLOBALS['wpdb']->inserts );
    }

    public function testMenuRegistersTheStatusSubmenu(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        do_action( 'admin_menu' );
        $slugs = array();
        foreach ( $GLOBALS['gr_stub_submenu_pages'] as $page ) {
            $slugs[] = $page['parent_slug'] . ':' . $page['menu_slug'];
        }
        $this->assertContains( 'greenpng-dashboard:greenpng-status', $slugs );

        // The export handler rides admin_init alongside the other
        // write pages.
        $hooks = array();
        foreach ( $GLOBALS['gr_stub_actions'] as $registration ) {
            $hooks[] = (string) $registration['hook'];
        }
        $this->assertContains( 'admin_init', $hooks );
    }
}
