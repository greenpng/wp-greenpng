<?php
/**
 * Bot & Device Signals (docs/13 U14, docs/06 §1 tree, docs/12): the
 * crawler-verification summaries — FCrDNS verdict rows filed by the
 * engine's fresh DNS walks, the scanner-UA engine statistics, the
 * probe's bot_score distribution, and the read-only page that
 * renders exactly what the repositories return, inventing nothing.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Admin\Gr_Admin_Menu;
use GreenPNG\Admin\Gr_Bot_Signals_Page;
use GreenPNG\Core\Gr_Plugin;
use GreenPNG\Security\Gr_Crawler_Verify;
use GreenPNG\Storage\Gr_Security_Log_Repository;
use GreenPNG\Storage\Gr_Session_Repository;
use PHPUnit\Framework\TestCase;

final class BotSignalsPageTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    /**
     * Every security-track INSERT recorded this test, with the
     * prepare/query duplicates collapsed.
     *
     * @return array<int, string>
     */
    private function insert_shapes(): array {
        global $wpdb;

        $found = array();
        foreach ( $wpdb->queries as $sql ) {
            if ( false !== strpos( (string) $sql, 'INSERT INTO wp_gr_security_logs' ) ) {
                $found[] = (string) $sql;
            }
        }

        return array_values( array_unique( $found ) );
    }

    // ------------------------------------------------------------------
    // The engine files its verdicts on the security track.
    // ------------------------------------------------------------------

    public function testVerifyFilesEachFreshVerdictRow(): void {
        $GLOBALS['gr_stub_dns']['ptr']['66.249.66.1'] = 'crawl-66-249-66-1.googlebot.com';
        $GLOBALS['gr_stub_dns']['forward'][ DNS_A ]['crawl-66-249-66-1.googlebot.com'] = array(
            array( 'ip' => '66.249.66.1' ),
        );

        Gr_Crawler_Verify::verify( '66.249.66.1', 'Googlebot/2.1' );

        $sql = $this->insert_shapes()[0];
        $this->assertStringContainsString( "'fcrdns'", $sql );
        $this->assertStringContainsString( "'verified'", $sql );
        // The PTR hostname rides the reason column: the page reads
        // what the walk found, not the ephemeral cache.
        $this->assertStringContainsString( "'crawl-66-249-66-1.googlebot.com'", $sql );

        // The 24h cache suppresses the repeat: a second claim over
        // the same pair files no second row.
        Gr_Crawler_Verify::verify( '66.249.66.1', 'Googlebot/2.1' );
        $this->assertCount( 1, $this->insert_shapes() );

        // An unprovable claim files the negative verdict just the same.
        Gr_Crawler_Verify::verify( '203.0.113.7', 'Googlebot/2.1' );
        $shapes = $this->insert_shapes();
        $this->assertCount( 2, $shapes );
        $this->assertStringContainsString( "'unverified'", $shapes[1] );
    }

    public function testLogActionParameterStaysBackwardsCompatible(): void {
        $repo = new Gr_Security_Log_Repository();

        // Six-arg calls keep the historical shape.
        $repo->log( '203.0.113.7', 'scanner_ua', '/p', 'sqlmap/1.0', 'ua:sqlmap', 700 );
        $this->assertStringContainsString( "'logged'", $this->insert_shapes()[0] );

        // The engine passes its verdict word; overlong words clip.
        $repo->log( '203.0.113.7', 'fcrdns', '', 'Googlebot/2.1', 'host.example', 700, str_repeat( 'v', 40 ) );
        $this->assertStringContainsString( "'" . str_repeat( 'v', 32 ) . "'", end( $GLOBALS['wpdb']->queries ) );
    }

    // ------------------------------------------------------------------
    // Repository reads behind the page.
    // ------------------------------------------------------------------

    public function testFcrdnsRecentReadsTheVerdictTrack(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'reason AS host' ) ) {
                return array(
                    array( 'ip' => '66.249.66.1', 'user_agent' => 'Googlebot/2.1', 'host' => 'crawl-66-249-66-1.googlebot.com', 'action_taken' => 'verified', 'hit_count' => '1', 'last_seen' => '2026-01-01 00:00:00' ),
                );
            }

            return array();
        };

        $rows = ( new Gr_Security_Log_Repository() )->fcrdns_recent( 72, 300 );

        $sql = (string) end( $GLOBALS['wpdb']->queries );
        $this->assertStringContainsString( "rule_id = 'fcrdns'", $sql );
        $this->assertStringContainsString( 'INET6_NTOA(ip)', $sql );
        $this->assertStringContainsString( 'reason AS host', $sql );
        $this->assertStringContainsString( 'ORDER BY last_seen DESC', $sql );
        // The limit clamps at 100, not the requested 300.
        $this->assertStringContainsString( 'LIMIT 100', $sql );

        $this->assertSame( 'crawl-66-249-66-1.googlebot.com', $rows[0]['host'] );
        $this->assertSame( 'verified', $rows[0]['action_taken'] );
    }

    public function testFcrdnsSummaryCountsWalksNotRows(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'GROUP BY action_taken' ) ) {
                return array(
                    array( 'action_taken' => 'verified', 'walks' => '3', 'fold_rows' => '2' ),
                    array( 'action_taken' => 'unverified', 'walks' => '1', 'fold_rows' => '1' ),
                );
            }

            return array();
        };

        $summary = ( new Gr_Security_Log_Repository() )->fcrdns_summary( 72 );

        $sql = (string) end( $GLOBALS['wpdb']->queries );
        $this->assertStringContainsString( 'SUM(hit_count) AS walks', $sql );
        // 'rows' is a reserved word on MariaDB 12 (live-caught): the
        // SQL alias is fold_rows and the PHP key stays 'rows'.
        $this->assertStringContainsString( 'COUNT(*) AS fold_rows', $sql );
        $this->assertStringContainsString( 'GROUP BY action_taken', $sql );

        // The summary line reads verdict => walk count.
        $this->assertSame( 3, $summary['verified']['walks'] );
        $this->assertSame( 1, $summary['unverified']['rows'] );
    }

    public function testUaEngineStatsFoldHitsPerAgent(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'GROUP BY user_agent' ) ) {
                return array(
                    array( 'user_agent' => 'sqlmap/1.0', 'hits' => '9', 'fold_rows' => '2', 'last_seen' => '2026-01-01 00:00:00' ),
                );
            }

            return array();
        };

        $rows = ( new Gr_Security_Log_Repository() )->ua_engine_stats( 168, 999 );

        $sql = (string) end( $GLOBALS['wpdb']->queries );
        $this->assertStringContainsString( "rule_id = 'scanner_ua'", $sql );
        $this->assertStringContainsString( 'SUM(hit_count) AS hits', $sql );
        $this->assertStringContainsString( 'GROUP BY user_agent', $sql );
        $this->assertStringContainsString( 'ORDER BY hits DESC', $sql );
        // The limit clamps at 100.
        $this->assertStringContainsString( 'LIMIT 100', $sql );

        $this->assertSame( 'sqlmap/1.0', $rows[0]['user_agent'] );
        $this->assertSame( '9', $rows[0]['hits'] );
    }

    public function testBotScoreDistributionBandsInOneAggregate(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'SUM(bot_score' ) ) {
                return array(
                    array( 'total' => '6', 'bots' => '2', 'b0' => '1', 'b1_25' => '1', 'b26_50' => '0', 'b51_75' => '0', 'b76_99' => '0', 'b100' => '4' ),
                );
            }

            return array();
        };

        $before      = time();
        $distribution = ( new Gr_Session_Repository() )->bot_score_distribution( 400 );
        $after       = time();

        $sql = (string) end( $GLOBALS['wpdb']->queries );
        $this->assertStringContainsString( 'SUM(bot_score = 0) AS b0', $sql );
        $this->assertStringContainsString( 'SUM(bot_score BETWEEN 1 AND 25) AS b1_25', $sql );
        $this->assertStringContainsString( 'SUM(bot_score BETWEEN 76 AND 99) AS b76_99', $sql );
        $this->assertStringContainsString( 'SUM(bot_score = 100) AS b100', $sql );
        $this->assertStringContainsString( 'SUM(is_bot) AS bots', $sql );
        $this->assertStringContainsString( 'WHERE started_at >=', $sql );
        // The window clamps at 365 days, not the requested 400; the
        // exact second may tick across the call, so either edge
        // string satisfying the boundary passes (both matching means
        // the call never crossed a second boundary).
        $this->assertGreaterThanOrEqual(
            1,
            count(
                array_filter(
                    array( $before, $after ),
                    static function ( int $edge ) use ( $sql ): bool {
                        return false !== strpos( $sql, "'" . gmdate( 'Y-m-d H:i:s', $edge - 365 * DAY_IN_SECONDS ) . "'" );
                    }
                )
            )
        );

        $this->assertSame( 6, $distribution['total'] );
        $this->assertSame( 2, $distribution['bots'] );
        $this->assertSame( 4, $distribution['bands']['100'] );
    }

    public function testBotScoreDistributionZeroStateInventsNothing(): void {
        // No rows at all: every number the page can show is zero.
        $GLOBALS['wpdb']->results = array();

        $distribution = ( new Gr_Session_Repository() )->bot_score_distribution( 30 );

        $this->assertSame( 0, $distribution['total'] );
        $this->assertSame( 0, $distribution['bots'] );
        $this->assertSame(
            array( '0' => 0, '1-25' => 0, '26-50' => 0, '51-75' => 0, '76-99' => 0, '100' => 0 ),
            $distribution['bands']
        );
    }

    // ------------------------------------------------------------------
    // The page renders the summaries, masked and localized.
    // ------------------------------------------------------------------

    public function testRenderSummarizesAllThreeEngines(): void {
        $GLOBALS['wpdb']->results = static function ( string $sql ): array {
            if ( false !== strpos( $sql, 'GROUP BY action_taken' ) ) {
                return array( array( 'action_taken' => 'verified', 'walks' => '3', 'rows' => '2' ) );
            }

            if ( false !== strpos( $sql, 'GROUP BY user_agent' ) ) {
                return array( array( 'user_agent' => 'sqlmap/1.0', 'hits' => '9', 'fold_rows' => '2', 'last_seen' => '2026-01-01 00:00:00' ) );
            }

            if ( false !== strpos( $sql, 'SUM(bot_score' ) ) {
                return array( array( 'total' => '4', 'bots' => '1', 'b0' => '3', 'b1_25' => '0', 'b26_50' => '0', 'b51_75' => '0', 'b76_99' => '0', 'b100' => '1' ) );
            }

            if ( false !== strpos( $sql, 'reason AS host' ) ) {
                return array( array( 'ip' => '66.249.66.1', 'user_agent' => 'Googlebot/2.1', 'host' => 'crawl-66-249-66-1.googlebot.com', 'action_taken' => 'verified', 'hit_count' => '1', 'last_seen' => '2026-01-01 00:00:00' ) );
            }

            return array();
        };

        ob_start();
        Gr_Bot_Signals_Page::render();
        $html = (string) ob_get_clean();

        // The three summary sections, in the docs/06 tree order.
        $this->assertStringContainsString( 'Bot &amp; Device Signals', $html );
        $this->assertStringContainsString( 'FCrDNS verification', $html );
        $this->assertStringContainsString( 'Scanner-UA engine', $html );
        $this->assertStringContainsString( 'bot_score distribution', $html );

        // The FCrDNS row: address masked for display, verdict and PTR
        // host rendered as recorded.
        $this->assertStringContainsString( '66.249.66.*', $html );
        $this->assertStringNotContainsString( '>66.249.66.1<', $html );
        $this->assertStringContainsString( 'crawl-66-249-66-1.googlebot.com', $html );
        $this->assertStringContainsString( 'verified', $html );

        // The summary line counts walks, not rows.
        $this->assertStringContainsString( '3 verified and 0 unverified verdicts', $html );

        // The UA engine row carries the folded hit volume.
        $this->assertStringContainsString( 'sqlmap/1.0', $html );
        $this->assertStringContainsString( '9', $html );

        // The distribution: human/bot split and the % share of a band.
        $this->assertStringContainsString( '3 human and 1 bot-flagged sessions', $html );
        $this->assertStringContainsString( '75.0%', $html );
    }

    public function testRenderEmptyWindowsInventNoNumbers(): void {
        $GLOBALS['wpdb']->results = array();

        ob_start();
        Gr_Bot_Signals_Page::render();
        $html = (string) ob_get_clean();

        // Each section states its own empty window.
        $this->assertStringContainsString( 'No crawler claims verified yet.', $html );
        $this->assertStringContainsString( 'No scanner agents recorded in the window.', $html );
        $this->assertStringContainsString( 'No scored sessions in the window.', $html );

        // Zero state means zero: no share percentages, no split counts
        // from rows that do not exist.
        $this->assertStringNotContainsString( '%)', $html );
        $this->assertStringNotContainsString( '0 human and', $html );
        $this->assertStringNotContainsString( 'verified and', $html );

        // Read-only page: no nonce field, no form.
        $this->assertStringNotContainsString( 'wp_nonce_field', $html );
        $this->assertStringNotContainsString( '<form', $html );
    }

    // ------------------------------------------------------------------
    // Menu wiring.
    // ------------------------------------------------------------------

    public function testMenuRegistersBotSignalsUnderTraffic(): void {
        Gr_Plugin::reset_instance();
        Gr_Plugin::run();

        do_action( 'admin_menu' );

        $slugs = array();
        foreach ( $GLOBALS['gr_stub_submenu_pages'] as $page ) {
            $slugs[] = $page['parent_slug'] . ':' . $page['menu_slug'];
        }

        $this->assertContains( 'greenpng-traffic:' . Gr_Bot_Signals_Page::SLUG, $slugs );

        // The entry carries the owner capability and the page renderer.
        foreach ( $GLOBALS['gr_stub_submenu_pages'] as $page ) {
            if ( Gr_Bot_Signals_Page::SLUG === (string) $page['menu_slug'] ) {
                $this->assertSame( 'greenpng-traffic', $page['parent_slug'] );
                $this->assertSame( 'manage_options', $page['capability'] );
                $this->assertSame( array( Gr_Bot_Signals_Page::class, 'render' ), $page['callback'] );
            }
        }
    }

    public function testMenuSlugIsTheStableEntryPoint(): void {
        // Menu assembly keeps the page reachable; this pins the slug
        // the deep links and future tabs build on.
        Gr_Admin_Menu::register();

        $this->assertSame( 'greenpng-bot', Gr_Bot_Signals_Page::SLUG );
    }
}
