<?php
/**
 * Contact repository (ADR-0013 D1/D2): the email-hash upsert with its
 * blank-preserving recapture semantics, the cookie-track-only visitor
 * binding, the attach-only tag vocabulary, and the reads the scoring
 * and profile surfaces consume.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

namespace GreenPNG\Tests\Unit;

use GreenPNG\Core\Gr_Secrets;
use GreenPNG\Storage\Gr_Contact_Repository;
use PHPUnit\Framework\TestCase;

final class ContactRepositoryTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        gr_stub_reset_options();

        global $wpdb;
        $wpdb->queries    = array();
        $wpdb->results    = array();
        $wpdb->var_result = null;
        $wpdb->insert_id  = 0;
    }

    /**
     * All recorded SQL against the CRM tables, deduped (prepare and
     * the runner each record an identical line).
     *
     * @return array<int, string>
     */
    private function sql(): array {
        global $wpdb;

        return array_values( array_unique( $wpdb->queries ) );
    }

    public function testCaptureInsertsTheLeadWithBothStamps(): void {
        global $wpdb;
        $wpdb->insert_id = 12;

        $id = ( new Gr_Contact_Repository() )->capture( 'Lead@Example.com', 'Ada', 'Lovelace', 'visitor-cookie-1', 7 );

        self::assertSame( 12, $id );
        $sql = implode( ' ', $this->sql() );
        self::assertStringContainsString( 'INSERT INTO wp_gr_contacts', $sql );
        self::assertStringContainsString(
            Gr_Secrets::hash_pii_sha256( 'lead@example.com' ),
            $sql,
            'The identity is the normalized lowercase hash.'
        );
        // The envelope is random-IV, so the durable property is the
        // roundtrip: whatever capture stored decrypts back to the
        // email.
        self::assertSame( 1, preg_match( "/'([a-f0-9]{64})', '([^']*)'/", $sql, $envelope_match ) );
        self::assertSame( 'lead@example.com', Gr_Secrets::decrypt( (string) $envelope_match[2] ) );
        self::assertStringContainsString( "'Ada'", $sql );
        self::assertStringContainsString( "'visitor-cookie-1'", $sql );
        self::assertStringContainsString( ", 7, 'visitor-cookie-1'", $sql );
        // Both lifetime stamps open together on a fresh capture.
        self::assertStringContainsString( 'first_seen, last_seen)', $sql );
    }

    public function testCaptureRecaptureSlidesLastSeenAndPreservesBlanks(): void {
        global $wpdb;
        $wpdb->insert_id  = 0; // UNIQUE key collapsed the insert.
        $wpdb->var_result = '31';

        $id = ( new Gr_Contact_Repository() )->capture( 'known@example.com', '', '', '' );

        self::assertSame( 31, $id, 'A recapture resolves through the existing row.' );
        $sql = implode( ' ', $this->sql() );
        self::assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $sql );
        foreach ( array( 'email_enc', 'first_name', 'last_name', 'user_id', 'visitor_id' ) as $field ) {
            self::assertStringContainsString(
                "IF(VALUES({$field})",
                $sql,
                "{$field} only fills blanks; a bare re-submission erases nothing."
            );
        }
        self::assertStringContainsString( 'last_seen = VALUES(last_seen)', $sql );
        self::assertStringNotContainsString( 'first_seen = VALUES', $sql, 'first_seen is write-once.' );
    }

    public function testCaptureRejectsNonEmailsWithoutWriting(): void {
        global $wpdb;

        self::assertSame( 0, ( new Gr_Contact_Repository() )->capture( 'not-an-email', 'A', 'B', '' ) );
        self::assertSame( 0, ( new Gr_Contact_Repository() )->capture( '', 'A', 'B', '' ) );
        self::assertSame( array(), $wpdb->queries );
    }

    public function testCaptureTruncatesNamesAndVisitorToColumnWidth(): void {
        global $wpdb;
        $wpdb->insert_id = 3;

        ( new Gr_Contact_Repository() )->capture( 'wide@example.com', str_repeat( 'x', 300 ), str_repeat( 'y', 300 ), str_repeat( 'v', 100 ) );

        $sql = implode( ' ', $this->sql() );
        self::assertStringContainsString( "'" . str_repeat( 'x', 191 ) . "'", $sql );
        self::assertStringContainsString( "'" . str_repeat( 'y', 191 ) . "'", $sql );
        self::assertStringContainsString( "'" . str_repeat( 'v', 64 ) . "'", $sql );
    }

    public function testAttachTagCreatesVocabularyThenLinks(): void {
        global $wpdb;
        $wpdb->insert_id = 5;

        $tag_id = ( new Gr_Contact_Repository() )->attach_tag( 12, 'sys:form:fluentforms', 'Form: Fluent Forms', true );

        self::assertSame( 5, $tag_id );
        $sql = implode( ' ', $this->sql() );
        self::assertStringContainsString( 'INSERT IGNORE INTO wp_gr_tags', $sql );
        self::assertStringContainsString( "'sys:form:fluentforms'", $sql );
        self::assertStringContainsString( "'Form: Fluent Forms'", $sql );
        self::assertStringContainsString( ', 1, ', $sql, 'The sys namespace stores is_system=1.' );
        self::assertStringContainsString( 'INSERT IGNORE INTO wp_gr_contact_tags', $sql );
        self::assertStringContainsString( '(12, 5,', $sql );
    }

    public function testAttachTagResolvesAnExistingSlugBeforeLinking(): void {
        global $wpdb;
        $wpdb->insert_id  = 0; // Slug already in the vocabulary.
        $wpdb->var_result = '9';

        $tag_id = ( new Gr_Contact_Repository() )->attach_tag( 12, 'sys:suspected_bot', 'Suspected bot', true );

        self::assertSame( 9, $tag_id );
        $sql = implode( ' ', $this->sql() );
        self::assertStringContainsString( "SELECT id FROM wp_gr_tags WHERE slug = 'sys:suspected_bot'", $sql );
        self::assertStringContainsString( 'INSERT IGNORE INTO wp_gr_contact_tags', $sql );
    }

    public function testAttachTagRejectsInvalidInputWithoutWriting(): void {
        global $wpdb;

        self::assertSame( 0, ( new Gr_Contact_Repository() )->attach_tag( 0, 'slug', 'Label' ) );
        self::assertSame( 0, ( new Gr_Contact_Repository() )->attach_tag( 12, '  ', 'Label' ) );
        self::assertSame( array(), $wpdb->queries );
    }

    public function testIdForEmailReadsTheHashPointLookup(): void {
        global $wpdb;
        $wpdb->var_result = '44';

        $repo = new Gr_Contact_Repository();

        self::assertSame( 44, $repo->id_for_email( 'Mixed@Example.com' ) );
        self::assertStringContainsString( 'SELECT id FROM wp_gr_contacts WHERE email_hash = ', implode( ' ', $wpdb->queries ) );
        self::assertStringContainsString( Gr_Secrets::hash_pii_sha256( 'mixed@example.com' ), implode( ' ', $wpdb->queries ) );

        $wpdb->var_result = null;
        self::assertSame( 0, $repo->id_for_email( 'unknown@example.com' ) );
    }

    public function testRowForIdReturnsTheContactOrNull(): void {
        global $wpdb;
        $wpdb->results = array(
            array(
                'id'          => '8',
                'email_enc'   => 'envelope',
                'first_name'  => 'Grace',
                'last_name'   => 'Hopper',
                'visitor_id'  => 'visitor-8',
                'lead_score'  => '0',
                'rfm_segment' => '',
            ),
        );

        $row = ( new Gr_Contact_Repository() )->row_for_id( 8 );

        self::assertIsArray( $row );
        self::assertSame( 'Grace', $row['first_name'] );
        self::assertSame( 'visitor-8', $row['visitor_id'] );

        self::assertNull( ( new Gr_Contact_Repository() )->row_for_id( 0 ) );
    }

    public function testIdsActiveSinceMapsTheWorklist(): void {
        global $wpdb;
        $wpdb->results = array(
            array( 'id' => '3' ),
            array( 'id' => '9' ),
        );

        $ids = ( new Gr_Contact_Repository() )->ids_active_since( '2026-09-14 00:00:00' );

        self::assertSame( array( 3, 9 ), $ids );
        self::assertStringContainsString( 'WHERE last_seen >=', implode( ' ', $wpdb->queries ) );

        $wpdb->results = array();
        self::assertSame( array(), ( new Gr_Contact_Repository() )->ids_active_since( '2026-09-14 00:00:00' ) );
    }
}
