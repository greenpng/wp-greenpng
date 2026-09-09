<?php
/**
 * Settings service behavior (S4): central defaults, single autoload=yes
 * install, default-merging reads, write-through sets.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    public function testDefaultsMatchRecordedDecisions(): void {
        $defaults = Gr_Settings::defaults();

        // Security track: on, observe-only, full IP by default (ADR-0007).
        self::assertSame( 1, $defaults['security_enabled'] );
        self::assertSame( 'log', $defaults['security_action_mode'] );
        self::assertSame( 0, $defaults['security_log_anonymize'] );
        self::assertSame( 0, $defaults['trust_proxy_headers'] );
        self::assertSame( array(), $defaults['trusted_proxies'] );

        // Probe: default on (safety conclusions only, toggle + disclosure).
        self::assertSame( 1, $defaults['probe_enabled'] );

        // Attribution: consent-gated, 30-day cookie window.
        self::assertSame( 1, $defaults['attribution_enabled'] );
        self::assertSame( 30, $defaults['attribution_cookie_days'] );
        self::assertSame( 'last', $defaults['attribution_default_model'] );

        // Privacy: marketing-track anonymization on by default.
        self::assertSame( 1, $defaults['marketing_ip_anonymize'] );

        // Retention ceilings exist for every high-growth table.
        self::assertSame( 30, $defaults['retention_days']['events'] );
        self::assertSame( 90, $defaults['retention_days']['sessions'] );
        self::assertSame( 365, $defaults['retention_days']['daily_stats'] );
    }

    public function testDefaultsSerializeUnder8kb(): void {
        self::assertLessThan( 8192, strlen( serialize( Gr_Settings::defaults() ) ) );
    }

    public function testInstallCreatesSingleAutoloadOption(): void {
        self::assertTrue( Gr_Settings::install() );
        self::assertArrayHasKey( 'gr_settings', $GLOBALS['gr_stub_options']['autoload'] );
        self::assertSame( 'yes', $GLOBALS['gr_stub_options']['autoload']['gr_settings'] );
    }

    public function testInstallIsIdempotentAndPreservesValues(): void {
        Gr_Settings::install();

        $settings = new Gr_Settings();
        $settings->set( 'probe_enabled', 0 );

        // A second activation must not reset the site owner's choice.
        self::assertTrue( Gr_Settings::install() );
        self::assertSame( 0, $settings->get( 'probe_enabled' ) );
    }

    public function testGetMergesDefaultsForMissingKeys(): void {
        $GLOBALS['gr_stub_options']['data']['gr_settings'] = array( 'probe_enabled' => 0 );

        $settings = new Gr_Settings();
        self::assertSame( 0, $settings->get( 'probe_enabled' ) );
        self::assertSame( 30, $settings->get( 'attribution_cookie_days' ) );
        self::assertSame( 'fallback', $settings->get( 'not_a_gr_key', 'fallback' ) );
    }

    public function testSetPersistsAndInvalidatesMemo(): void {
        Gr_Settings::install();

        $settings = new Gr_Settings();
        self::assertTrue( $settings->set( 'attribution_cookie_days', 60 ) );

        $fresh = new Gr_Settings();
        self::assertSame( 60, $fresh->get( 'attribution_cookie_days' ) );
        self::assertSame( 1, $fresh->get( 'probe_enabled' ) );
    }
}
