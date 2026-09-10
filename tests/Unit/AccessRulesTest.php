<?php
/**
 * Access rule evaluation (docs/13 W4): allow/ban IP verdicts with the
 * allow-wins guard, segment-safe URL exemptions, the once-per-request
 * memo, and the degraded-open contract.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Security\Gr_Access_Rules;
use PHPUnit\Framework\TestCase;

final class AccessRulesTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
    }

    /**
     * Cans the repository read with one rule set.
     *
     * @param array<int, array<string, string>> $rules Active rule rows.
     * @return void
     */
    private function seed( array $rules ): void {
        global $wpdb;
        $wpdb->results = $rules;
    }

    public function testTrustedIpReadsAllowRulesOnly(): void {
        $this->seed(
            array(
                array(
                    'rule_type'   => 'allow',
                    'match_kind'  => 'ip',
                    'match_value' => '10.0.0.0/24',
                ),
                array(
                    'rule_type'   => 'ban',
                    'match_kind'  => 'ip',
                    'match_value' => '10.0.0.0/8',
                ),
                array(
                    'rule_type'   => 'allow',
                    'match_kind'  => 'url',
                    'match_value' => '/checkout',
                ),
            )
        );

        $this->assertTrue( Gr_Access_Rules::is_trusted_ip( '10.0.0.5' ) );
        $this->assertTrue( Gr_Access_Rules::is_trusted_ip( '10.0.0.255' ) );

        // The /8 ban row and the URL row are not allow-IP rules.
        $this->assertFalse( Gr_Access_Rules::is_trusted_ip( '10.0.1.5' ) );
        $this->assertFalse( Gr_Access_Rules::is_trusted_ip( '203.0.113.9' ) );

        // Bare-IP allow entries work like /32; the reset between seeds
        // stands in for the next request, since the memo holds the
        // first read for the whole request.
        $this->seed(
            array(
                array(
                    'rule_type'   => 'allow',
                    'match_kind'  => 'ip',
                    'match_value' => '192.0.2.44',
                ),
            )
        );
        Gr_Access_Rules::reset_for_tests();
        $this->assertTrue( Gr_Access_Rules::is_trusted_ip( '192.0.2.44' ) );
        $this->assertFalse( Gr_Access_Rules::is_trusted_ip( '192.0.2.45' ) );
    }

    public function testBlockedIpReadsBanRulesAndAllowAlwaysWins(): void {
        $this->seed(
            array(
                array(
                    'rule_type'   => 'ban',
                    'match_kind'  => 'ip',
                    'match_value' => '203.0.113.0/24',
                ),
                array(
                    'rule_type'   => 'allow',
                    'match_kind'  => 'ip',
                    'match_value' => '203.0.113.7',
                ),
                array(
                    'rule_type'   => 'allow',
                    'match_kind'  => 'ip',
                    'match_value' => '2001:db8::/32',
                ),
            )
        );

        // Inside the ban range but on the allow list: never blocked.
        $this->assertFalse( Gr_Access_Rules::is_ip_blocked( '203.0.113.7' ) );

        // Inside the ban range, not allowed: blocked.
        $this->assertTrue( Gr_Access_Rules::is_ip_blocked( '203.0.113.8' ) );
        $this->assertTrue( Gr_Access_Rules::is_ip_blocked( '203.0.113.255' ) );

        // Outside both: ordinary.
        $this->assertFalse( Gr_Access_Rules::is_ip_blocked( '198.51.100.1' ) );

        // IPv6 allow covering an IPv6 ban range (v4/v6 never mix);
        // reset stands in for the next request after re-seeding.
        $this->seed(
            array(
                array(
                    'rule_type'   => 'ban',
                    'match_kind'  => 'ip',
                    'match_value' => '2001:db8::/32',
                ),
                array(
                    'rule_type'   => 'allow',
                    'match_kind'  => 'ip',
                    'match_value' => '2001:db8:abcd::/48',
                ),
            )
        );
        Gr_Access_Rules::reset_for_tests();
        // Inside both: the allow list wins.
        $this->assertFalse( Gr_Access_Rules::is_ip_blocked( '2001:db8:abcd::1' ) );
        // Inside the ban, outside the allow: blocked.
        $this->assertTrue( Gr_Access_Rules::is_ip_blocked( '2001:db8:abce::1' ) );
    }

    public function testUrlAllowIsSegmentSafeAndIgnoresTheQuery(): void {
        $this->seed(
            array(
                array(
                    'rule_type'   => 'allow',
                    'match_kind'  => 'url',
                    'match_value' => '/checkout',
                ),
                array(
                    'rule_type'   => 'allow',
                    'match_kind'  => 'url',
                    'match_value' => '/docs/*',
                ),
            )
        );

        // Plain value: the exact path, or a deeper path under it.
        $this->assertTrue( Gr_Access_Rules::is_url_allowed( '/checkout' ) );
        $this->assertTrue( Gr_Access_Rules::is_url_allowed( '/checkout/thanks?utm=x' ) );
        $this->assertTrue( Gr_Access_Rules::is_url_allowed( '/checkout?utm=x' ) );

        // Sibling path sharing only leading characters stays out.
        $this->assertFalse( Gr_Access_Rules::is_url_allowed( '/checkoutzone' ) );
        $this->assertFalse( Gr_Access_Rules::is_url_allowed( '/' ) );

        // Wildcard value: '*' spans characters; the rest stays literal.
        $this->assertTrue( Gr_Access_Rules::is_url_allowed( '/docs/intro?a=1' ) );
        $this->assertTrue( Gr_Access_Rules::is_url_allowed( '/docs/' ) );
        $this->assertFalse( Gr_Access_Rules::is_url_allowed( '/docs' ) );
        $this->assertFalse( Gr_Access_Rules::is_url_allowed( '/docsx/intro' ) );

        // Ban and ip rows never answer a URL question; reset stands in
        // for the next request after re-seeding.
        $this->seed(
            array(
                array(
                    'rule_type'   => 'ban',
                    'match_kind'  => 'url',
                    'match_value' => '/checkout',
                ),
            )
        );
        Gr_Access_Rules::reset_for_tests();
        $this->assertFalse( Gr_Access_Rules::is_url_allowed( '/checkout' ) );
    }

    public function testWildcardValuesKeepRegexMetacharactersLiteral(): void {
        $this->seed(
            array(
                array(
                    'rule_type'   => 'allow',
                    'match_kind'  => 'url',
                    'match_value' => '/a.b/*',
                ),
            )
        );

        $this->assertTrue( Gr_Access_Rules::is_url_allowed( '/a.b/c' ) );
        $this->assertFalse( Gr_Access_Rules::is_url_allowed( '/axb/c' ) );
    }

    public function testRulesLoadAtMostOncePerRequest(): void {
        global $wpdb;
        $this->seed(
            array(
                array(
                    'rule_type'   => 'ban',
                    'match_kind'  => 'ip',
                    'match_value' => '203.0.113.0/24',
                ),
            )
        );

        Gr_Access_Rules::is_trusted_ip( '10.0.0.1' );
        Gr_Access_Rules::is_ip_blocked( '203.0.113.8' );
        Gr_Access_Rules::is_ip_blocked( '203.0.113.9' );
        Gr_Access_Rules::is_url_allowed( '/checkout' );

        // One repository read served every predicate call.
        $reads = array_filter(
            $wpdb->queries,
            static function ( $sql ) {
                return false !== strpos( (string) $sql, 'FROM wp_gr_access_rules' );
            }
        );
        $this->assertCount( 1, $reads );

        // The read is one SELECT over active rows, table via the schema.
        $this->assertStringContainsString(
            'SELECT rule_type, match_kind, match_value FROM wp_gr_access_rules WHERE is_active = 1 ORDER BY id ASC',
            (string) reset( $reads )
        );

        // A memo reset reloads exactly once more.
        Gr_Access_Rules::reset_for_tests();
        Gr_Access_Rules::is_ip_blocked( '203.0.113.9' );
        $reads = array_filter(
            $wpdb->queries,
            static function ( $sql ) {
                return false !== strpos( (string) $sql, 'FROM wp_gr_access_rules' );
            }
        );
        $this->assertCount( 2, $reads );
    }

    public function testNoRulesDegradesOpen(): void {
        $this->seed( array() );

        $this->assertFalse( Gr_Access_Rules::is_trusted_ip( '10.0.0.1' ) );
        $this->assertFalse( Gr_Access_Rules::is_ip_blocked( '10.0.0.1' ) );
        $this->assertFalse( Gr_Access_Rules::is_url_allowed( '/anything' ) );
    }

    public function testUnknownShapesAndEmptyValuesAreIgnored(): void {
        $this->seed(
            array(
                array(
                    'rule_type'   => 'weird',
                    'match_kind'  => 'ip',
                    'match_value' => '10.0.0.0/8',
                ),
                array(
                    'rule_type'   => 'ban',
                    'match_kind'  => 'ua',
                    'match_value' => 'sqlmap',
                ),
                array(
                    'rule_type'   => 'ban',
                    'match_kind'  => 'ip',
                    'match_value' => '   ',
                ),
                array(
                    'rule_type'  => 'ban',
                    'match_kind' => 'ip',
                ),
            )
        );

        $this->assertFalse( Gr_Access_Rules::is_trusted_ip( '10.0.0.1' ) );
        $this->assertFalse( Gr_Access_Rules::is_ip_blocked( '10.0.0.1' ) );
        $this->assertFalse( Gr_Access_Rules::is_url_allowed( '/x' ) );
    }

    public function testFacadesMatchTheService(): void {
        $this->seed(
            array(
                array(
                    'rule_type'   => 'allow',
                    'match_kind'  => 'ip',
                    'match_value' => '10.0.0.0/24',
                ),
                array(
                    'rule_type'   => 'ban',
                    'match_kind'  => 'ip',
                    'match_value' => '198.51.100.0/24',
                ),
                array(
                    'rule_type'   => 'allow',
                    'match_kind'  => 'url',
                    'match_value' => '/checkout',
                ),
            )
        );

        $this->assertTrue( gr_is_trusted_ip( '10.0.0.3' ) );
        $this->assertTrue( gr_is_ip_blocked( '198.51.100.3' ) );
        $this->assertFalse( gr_is_ip_blocked( '10.0.0.3' ) );
        $this->assertTrue( gr_is_url_allowed( '/checkout/step' ) );
    }
}
