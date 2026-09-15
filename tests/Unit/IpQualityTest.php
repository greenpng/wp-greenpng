<?php
/**
 * Datacenter-range pipeline (ADR-0011 D1/D4): the packer's merge
 * discipline (adjacent and overlapping ranges fold, aligned prefixes
 * keep their full end — the regression the bundled build itself
 * caught), the classifier's binary search over the packed ranges, the
 * override-before-bundled resolution, and the honest-unknown
 * degradation when no dataset reads.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Dch_Packer;
use GreenPNG\Core\Gr_Ip_Quality;
use PHPUnit\Framework\TestCase;

final class IpQualityTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();
        Gr_Ip_Quality::reset_for_tests();
    }

    protected function tearDown(): void {
        Gr_Ip_Quality::reset_for_tests();
        unset( $GLOBALS['gr_stub_uploads']['basedir'] );
        parent::tearDown();
    }

    /**
     * A fresh uploads parent and the override directory layout.
     *
     * @return array{parent: string, override: string}
     */
    private function uploads(): array {
        $parent   = sys_get_temp_dir() . '/gr-ipq-' . uniqid();
        $override = $parent . '/' . Gr_Ip_Quality::UPLOAD_SUBDIR;
        mkdir( $override, 0777, true );
        $GLOBALS['gr_stub_uploads']['basedir'] = $parent;

        return array(
            'parent'   => $parent,
            'override' => $override,
        );
    }

    /**
     * Packs one fixture text into the override directory and reloads.
     *
     * @param string $parent Uploads parent.
     * @param string $text   CIDR text (may carry '#' provenance lines).
     * @return array{v4_ranges: int, v6_ranges: int, skipped: int}
     */
    private function pack_override( string $parent, string $text ): array {
        $list = $parent . '/list.txt';
        file_put_contents( $list, $text );
        $result = Gr_Dch_Packer::pack( $list, $parent . '/' . Gr_Ip_Quality::UPLOAD_SUBDIR, '2026-09-15', 'test fixture' );
        unlink( $list );
        Gr_Ip_Quality::reset_for_tests();

        return $result;
    }

    public function testAlignedPrefixesKeepTheirFullEndAndAdjacentRangesMerge(): void {
        $parent = $this->uploads()['parent'];
        $result = $this->pack_override(
            $parent,
            "# provenance header line\n"
            . "192.0.2.0/24\n"
            . "192.0.2.64/26\n"     // overlaps inside the /24
            . "192.0.3.0/24\n"      // adjacent to the /24
            . "192.0.5.0/24\n"      // separate
            . "198.51.100.7/32\n"   // bare-ish single address
            . "2001:db8:1::/48\n"
            . "2001:db8:2::/48\n"
        );

        // 192.0.2.0/24 + the covered /26 + the adjacent 192.0.3.0/24
        // fold into one range, the /32 and the /24 on 192.0.5 stay
        // separate; the two adjacent v6 /48s fold into one range.
        // The aligned /24 regression the bundled build caught is
        // asserted by the classification below.
        self::assertSame( 3, $result['v4_ranges'] );
        self::assertSame( 1, $result['v6_ranges'] );
        self::assertSame( 0, $result['skipped'] );

        self::assertSame( 'hosting', Gr_Ip_Quality::category( '192.0.2.130' ) );
        self::assertSame( 'hosting', Gr_Ip_Quality::category( '192.0.3.255' ) );
        self::assertSame( 'hosting', Gr_Ip_Quality::category( '198.51.100.7' ) );
        self::assertSame( '', Gr_Ip_Quality::category( '192.0.4.1' ) );
        self::assertSame( 'hosting', Gr_Ip_Quality::category( '2001:db8:1::1' ) );
        self::assertSame( 'hosting', Gr_Ip_Quality::category( '2001:db8:2::ffff' ) );
        self::assertSame( '', Gr_Ip_Quality::category( '2001:db8:3::1' ) );
        self::assertSame( 'override', Gr_Ip_Quality::source() );
    }

    public function testUnalignedPrefixesMaskStartAndFillEnd(): void {
        $parent = $this->uploads()['parent'];
        $this->pack_override( $parent, "203.0.113.77/25\n" );

        // A non-network start masks down to the true network and the
        // end fills to the block's last address.
        self::assertSame( 'hosting', Gr_Ip_Quality::category( '203.0.113.0' ) );
        self::assertSame( 'hosting', Gr_Ip_Quality::category( '203.0.113.127' ) );
        self::assertSame( '', Gr_Ip_Quality::category( '203.0.113.128' ) );
    }

    public function testMalformedLinesAreSkippedNotFatal(): void {
        $parent = $this->uploads()['parent'];
        $result = $this->pack_override(
            $parent,
            "not-a-cidr\n"
            . "192.0.2.0/33\n"
            . "192.0.2.0/24\n"
        );

        self::assertSame( 1, $result['v4_ranges'] );
        self::assertSame( 2, $result['skipped'] );
        self::assertSame( 'hosting', Gr_Ip_Quality::category( '192.0.2.9' ) );
    }

    public function testAMissingOrEmptyDatasetAnswersUnknownWithoutGuessing(): void {
        // No override, and a bundled copy the fixture cannot reach:
        // an empty pack refuses to promote, so the old state answers.
        $parent   = $this->uploads()['parent'];
        $override = $parent . '/' . Gr_Ip_Quality::UPLOAD_SUBDIR;
        file_put_contents( $parent . '/list.txt', "192.0.2.0/24\n" );
        Gr_Dch_Packer::pack( $parent . '/list.txt', $override, '2026-09-15', 'test fixture' );
        Gr_Ip_Quality::reset_for_tests();
        self::assertSame( 'hosting', Gr_Ip_Quality::category( '192.0.2.9' ) );

        // Overwrite the override with a non-array body: the shape
        // audit must read it as absent and degrade to unknown, with
        // the bundled copy still resolving (the repo copy exists).
        file_put_contents( $override . '/' . Gr_Dch_Packer::OUT_FILE, "<?php return 'broken';\n" );
        Gr_Ip_Quality::reset_for_tests();
        self::assertSame( '', Gr_Ip_Quality::category( '192.0.2.9' ) );

        // Malformed input is an unknown too, never a crash.
        self::assertSame( '', Gr_Ip_Quality::category( 'not-an-ip' ) );
        self::assertSame( '', Gr_Ip_Quality::category( '' ) );
    }

    public function testUnpairedArraysReadAsAbsent(): void {
        $parent   = $this->uploads()['parent'];
        $override = $parent . '/' . Gr_Ip_Quality::UPLOAD_SUBDIR;
        file_put_contents(
            $override . '/' . Gr_Dch_Packer::OUT_FILE,
            "<?php\nreturn array('built' => '2026-09-15', 'v4_starts' => array(1, 2, 3), 'v4_ends' => array(1), 'v6_starts' => array(), 'v6_ends' => array());\n"
        );
        Gr_Ip_Quality::reset_for_tests();

        self::assertSame( 'bundled', Gr_Ip_Quality::source() );
    }

    public function testBundledDatasetServesAndDescribesItself(): void {
        // The repo's own bundled copy: the real merged cloud ranges.
        self::assertSame( 'bundled', Gr_Ip_Quality::source() );
        $describe = Gr_Ip_Quality::describe();
        self::assertSame( '2026-09-15', $describe['built'] );
        self::assertGreaterThan( 100, $describe['v4'] );
        self::assertGreaterThan( 100, $describe['v6'] );

        // A known AWS address inside, a private address outside: the
        // binary search against the shipped data.
        self::assertSame( 'hosting', Gr_Ip_Quality::category( '3.5.140.1' ) );
        self::assertSame( '', Gr_Ip_Quality::category( '10.0.0.9' ) );
        self::assertSame( 'hosting', Gr_Ip_Quality::category( '2600:1f18::1' ) );
    }
}
