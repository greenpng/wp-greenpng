<?php
/**
 * Contacts repository for the gr_contacts table (docs/05 §2, ADR-0013
 * D1/D2): the lead-capture upsert, the tag vocabulary, and the reads
 * the scoring, RFM and profile surfaces consume. Email is the identity
 * — a normalized hash upserts the row and the encrypted envelope rides
 * along — while the visitor binding only ever carries the cookie-track
 * identity, latest capture winning, because the fallback track's
 * daily-rotated hash has no cross-day linkage value (ADR-0005).
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Storage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Database;
use GreenPNG\Core\Gr_Secrets;

/**
 * Read/write access to gr_contacts and its tag vocabulary; the only
 * layer permitted to hold $wpdb (docs/02 §2.3).
 */
final class Gr_Contact_Repository {

    /**
     * Captures one lead, idempotent on the email hash: a fresh email
     * inserts with first_seen and last_seen both stamped now; a known
     * email slides last_seen and fills only the still-empty fields, so
     * a blank re-submission never erases a previously captured name,
     * user binding or visitor binding. The stored envelope is replaced
     * only when a new one could be produced (an unprotectable host
     * keeps the last good one rather than blanking the column).
     *
     * @param string $email      Contact email, any case.
     * @param string $first_name First name, truncated to the column.
     * @param string $last_name  Last name, truncated to the column.
     * @param string $visitor_id Cookie-track visitor identity, '' when
     *                           the submission arrived on the fallback
     *                           track (never stored in that case).
     * @param int    $user_id    WordPress user id, 0 when anonymous.
     * @return int Contact row id, existing id on recapture, 0 on failure.
     */
    public function capture( string $email, string $first_name, string $last_name, string $visitor_id, int $user_id = 0 ): int {
        global $wpdb;

        $email = strtolower( trim( $email ) );
        if ( '' === $email || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
            return 0;
        }

        $envelope = Gr_Secrets::encrypt( $email );
        $now      = current_time( 'mysql' );

        $table = Gr_Database::table( 'contacts' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lead capture happens once per consented submission; the UNIQUE email_hash is the idempotency boundary (docs/05 §3.4).
        $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input.
                "INSERT INTO {$table}
                    (email_hash, email_enc, first_name, last_name, user_id, visitor_id, first_seen, last_seen)
                VALUES (%s, %s, %s, %s, %d, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    email_enc = IF(VALUES(email_enc) != '', VALUES(email_enc), email_enc),
                    first_name = IF(VALUES(first_name) != '', VALUES(first_name), first_name),
                    last_name = IF(VALUES(last_name) != '', VALUES(last_name), last_name),
                    user_id = IF(VALUES(user_id) != 0, VALUES(user_id), user_id),
                    visitor_id = IF(VALUES(visitor_id) != '', VALUES(visitor_id), visitor_id),
                    last_seen = VALUES(last_seen)",
                array(
                    Gr_Secrets::hash_pii_sha256( $email ),
                    $envelope,
                    substr( trim( $first_name ), 0, 191 ),
                    substr( trim( $last_name ), 0, 191 ),
                    $user_id,
                    substr( $visitor_id, 0, 64 ),
                    $now,
                    $now,
                )
            )
        );

        $id = (int) $wpdb->insert_id;
        if ( $id > 0 ) {
            return $id;
        }

        // Known email (the UNIQUE key collapsed the insert): the
        // existing row decides, exactly like the conversion binding.
        return $this->id_for_email( $email );
    }

    /**
     * The contact id bound to an email, 0 when never captured.
     *
     * @param string $email Contact email, any case.
     * @return int
     */
    public function id_for_email( string $email ): int {
        global $wpdb;

        $email = strtolower( trim( $email ) );
        if ( '' === $email ) {
            return 0;
        }

        $table = Gr_Database::table( 'contacts' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- point lookup on the UNIQUE email_hash; stable for a captured email by definition.
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input.
                "SELECT id FROM {$table} WHERE email_hash = %s",
                Gr_Secrets::hash_pii_sha256( $email )
            )
        );
    }

    /**
     * Attaches a tag, creating the tag vocabulary row on first use.
     * System tags (is_system=1) follow the attach-only contract
     * (ADR-0013 D3): removal is the site owner's manual act and no
     * automation re-adds what they removed.
     *
     * @param int    $contact_id Contact row id.
     * @param string $slug       Stable tag slug, e.g. sys:suspected_bot.
     * @param string $label      Display name, stored on first use.
     * @param bool   $is_system  True for the sys: namespace.
     * @return int Tag row id, 0 when the contact or write is invalid.
     */
    public function attach_tag( int $contact_id, string $slug, string $label, bool $is_system = false ): int {
        global $wpdb;

        $slug = trim( $slug );
        if ( $contact_id < 1 || '' === $slug ) {
            return 0;
        }

        $tags   = Gr_Database::table( 'tags' );
        $links  = Gr_Database::table( 'contact_tags' );
        $tag_id = 0;
        $now    = current_time( 'mysql' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tag vocabulary write, once per new slug; the UNIQUE slug is the dedupe boundary.
        $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are DDL-validated identifiers from Gr_Database, not user input.
                "INSERT IGNORE INTO {$tags} (name, slug, is_system, created_at)
                VALUES (%s, %s, %d, %s)",
                array(
                    substr( trim( $label ), 0, 191 ),
                    substr( $slug, 0, 191 ),
                    $is_system ? 1 : 0,
                    $now,
                )
            )
        );

        $tag_id = (int) $wpdb->insert_id;
        if ( $tag_id < 1 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- point lookup on the UNIQUE slug key.
            $tag_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a DDL-validated identifier from Gr_Database, not user input.
                    "SELECT id FROM {$tags} WHERE slug = %s",
                    substr( $slug, 0, 191 )
                )
            );
        }

        if ( $tag_id < 1 ) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- link write; the UNIQUE (contact_id, tag_id) absorbs repeats, which is the attach-only contract's idempotent form.
        $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are DDL-validated identifiers from Gr_Database, not user input.
                "INSERT IGNORE INTO {$links} (contact_id, tag_id, created_at)
                VALUES (%d, %d, %s)",
                array( $contact_id, $tag_id, $now )
            )
        );

        return $tag_id;
    }

    /**
     * One contact row for the profile page, null when the id is
     * unknown. The encrypted envelope travels as stored; the reveal is
     * the caller's audited, capability-gated decision.
     *
     * @param int $contact_id Contact row id.
     * @return array<string, string>|null Row keyed by column.
     */
    public function row_for_id( int $contact_id ): ?array {
        global $wpdb;

        if ( $contact_id < 1 ) {
            return null;
        }

        $table = Gr_Database::table( 'contacts' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin profile point read.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; it sits on this first string line on purpose, within the ignore's reach.
                "SELECT id, email_enc, first_name, last_name, user_id, visitor_id, lead_score, ltv, rfm_segment, first_seen, last_seen FROM {$table} WHERE id = %d",
                $contact_id
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : null;
    }

    /**
     * Contact ids whose last_seen moved at or after a cutoff — the
     * scoring pass's worklist. Ordered by id for deterministic queue
     * behavior.
     *
     * @param string $datetime Cutoff, Y-m-d H:i:s.
     * @return array<int, int>
     */
    public function ids_active_since( string $datetime ): array {
        global $wpdb;

        $table = Gr_Database::table( 'contacts' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- queue-pass worklist read, bounded by the cutoff.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input.
                "SELECT id FROM {$table} WHERE last_seen >= %s ORDER BY id ASC",
                $datetime
            ),
            ARRAY_A
        );

        if ( ! is_array( $rows ) ) {
            return array();
        }

        $ids = array();
        foreach ( $rows as $row ) {
            if ( is_array( $row ) && isset( $row['id'] ) ) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    /**
     * Every contact id, oldest first — the rule-save recompute's
     * worklist. Bounded by the list-table ceiling; a site with more
     * contacts than that recomputes in waves across queue retries.
     *
     * @param int $limit Row ceiling, clamped 1..5000.
     * @param int $after Last id of the previous wave, 0 from the start.
     * @return array<int, int>
     */
    public function all_ids( int $limit = 5000, int $after = 0 ): array {
        global $wpdb;

        $limit = max( 1, min( $limit, 5000 ) );
        $table = Gr_Database::table( 'contacts' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- queue-pass worklist read, keyset-paginated on the primary key.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input.
                "SELECT id FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
                array( $after, $limit )
            ),
            ARRAY_A
        );

        if ( ! is_array( $rows ) ) {
            return array();
        }

        $ids = array();
        foreach ( $rows as $row ) {
            if ( is_array( $row ) && isset( $row['id'] ) ) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    /**
     * Stores a recomputed lead score (ADR-0013 D3). The engine is the
     * only caller; 0..100 is enforced here so a drifted rule can never
     * park an out-of-range number on the row.
     *
     * @param int $contact_id Contact row id.
     * @param int $score      Lead score, clamped 0..100.
     * @return bool True when the row changed.
     */
    public function set_score( int $contact_id, int $score ): bool {
        global $wpdb;

        if ( $contact_id < 1 ) {
            return false;
        }

        $score = max( 0, min( 100, $score ) );
        $table = Gr_Database::table( 'contacts' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- queue or admin-profile write; the engine recomputes from source, so no staleness concern.
        return (int) $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input.
                "UPDATE {$table} SET lead_score = %d WHERE id = %d",
                array( $score, $contact_id )
            )
        ) > 0;
    }

    /**
     * The RFM population read (ADR-0013 D4): every contact's identity
     * binding, recency stamp and current segment/ltv state, oldest
     * first. One statement feeds the whole pass; the quintiles need
     * the full population by definition.
     *
     * @return array<int, array<string, string>> Rows keyed by column.
     */
    public function rfm_population(): array {
        global $wpdb;

        $table = Gr_Database::table( 'contacts' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- queue-pass population read; site-owner scale, off every front-end path.
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input.
            "SELECT id, visitor_id, last_seen, rfm_segment, ltv FROM {$table} ORDER BY id ASC",
            ARRAY_A
        );

        return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
    }

    /**
     * Stores a recomputed RFM segment and its net value (ADR-0013 D4):
     * the segment vocabulary and the decimal shape are enforced here.
     *
     * @param int    $contact_id Contact row id.
     * @param string $segment    One of the eight vocabulary words.
     * @param float  $ltv        Net conversion value, >= 0.
     * @return bool True when the row changed.
     */
    public function set_segment_and_ltv( int $contact_id, string $segment, float $ltv ): bool {
        global $wpdb;

        if ( $contact_id < 1 ) {
            return false;
        }

        $segment = substr( $segment, 0, 16 );
        $value   = number_format( round( max( 0.0, $ltv ), 2 ), 2, '.', '' );
        $table   = Gr_Database::table( 'contacts' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- queue-pass write; identical values are a no-op signal, so unchanged rows cost one statement and zero drift.
        return (int) $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input.
                "UPDATE {$table} SET rfm_segment = %s, ltv = %s WHERE id = %d",
                array( $segment, $value, $contact_id )
            )
        ) > 0;
    }

    /**
     * The contacts list page's page read (ADR-0013 D5): newest
     * captures first, two statements (rows + total), filters narrowed
     * to the RFM segment and one tag. The email envelope travels as
     * stored — the list shows the mask, never the plaintext.
     *
     * @param int    $per_page Row ceiling, clamped to [1, 200].
     * @param int    $offset   Zero-based row offset.
     * @param string $segment  RFM segment filter, '' for all.
     * @param int    $tag_id   Tag filter, 0 for all.
     * @return array{rows: array<int, array<string, string>>, total: int}
     */
    public function paged( int $per_page, int $offset, string $segment = '', int $tag_id = 0 ): array {
        global $wpdb;

        $table = Gr_Database::table( 'contacts' );
        $links = Gr_Database::table( 'contact_tags' );

        $per_page = max( 1, min( $per_page, 200 ) );
        $offset   = max( 0, $offset );

        $where  = '1=1';
        $params = array();
        if ( '' !== $segment ) {
            $where   .= ' AND rfm_segment = %s';
            $params[] = substr( $segment, 0, 16 );
        }
        if ( $tag_id > 0 ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $links is a DDL-validated identifier from Gr_Database, not user input.
            $where   .= " AND EXISTS (SELECT 1 FROM {$links} l WHERE l.contact_id = {$table}.id AND l.tag_id = %d)";
            $params[] = $tag_id;
        }

        // An unfiltered list has no placeholders, and core's prepare()
        // refuses a placeholder-less query with a notice — the plain
        // statement is the honest form for that branch.
        if ( array() === $params ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin list read, off every front-end path.
            $total = (int) $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input.
                "SELECT COUNT(*) FROM {$table}"
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin list read, off every front-end path.
            $total = (int) $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the placeholders live inside $where, invisible to the static count.
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $table is a DDL-validated identifier and $where a placeholder-only clause built above, neither carries user input; the placeholders live inside $where, invisible to the static count.
                    "SELECT COUNT(*) FROM {$table} WHERE {$where}",
                    $params
                )
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin list read; the id order keeps pagination stable.
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where's placeholders sit in $params ahead of the paging pair; the static count only sees the pair.
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier and $where a placeholder-only clause built above, neither carries user input.
                "SELECT id, email_enc, first_name, last_name, lead_score, rfm_segment, ltv, first_seen, last_seen FROM {$table} WHERE {$where}
                ORDER BY id DESC LIMIT %d OFFSET %d",
                array_merge( $params, array( $per_page, $offset ) )
            ),
            ARRAY_A
        );

        return array(
            'rows'  => is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array(),
            'total' => $total,
        );
    }

    /**
     * The whole tag vocabulary with contact counts (ADR-0013 D5):
     * owner-created and sys: system tags together, for the Tags tab
     * and the filter dropdown.
     *
     * @return array<int, array<string, string>>
     */
    public function tag_vocabulary(): array {
        global $wpdb;

        $tags  = Gr_Database::table( 'tags' );
        $links = Gr_Database::table( 'contact_tags' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin vocabulary read; the count walks the tag_contacts index.
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are DDL-validated identifiers from Gr_Database, not user input; both sit on this first string line on purpose, within the ignore's reach.
            "SELECT t.id, t.slug, t.name, t.is_system, COUNT(l.contact_id) AS contacts FROM {$tags} t LEFT JOIN {$links} l ON l.tag_id = t.id
            GROUP BY t.id, t.slug, t.name, t.is_system
            ORDER BY t.is_system ASC, t.slug ASC",
            ARRAY_A
        );

        return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
    }

    /**
     * The tag slugs attached to one contact, for the profile header.
     *
     * @param int $contact_id Contact row id.
     * @return array<int, string>
     */
    public function tag_slugs_for_contact( int $contact_id ): array {
        global $wpdb;

        if ( $contact_id < 1 ) {
            return array();
        }

        $tags  = Gr_Database::table( 'tags' );
        $links = Gr_Database::table( 'contact_tags' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- profile point read on the tag_contacts index.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are DDL-validated identifiers from Gr_Database, not user input; both sit on this first string line on purpose, within the ignore's reach.
                "SELECT t.slug FROM {$tags} t INNER JOIN {$links} l ON l.tag_id = t.id
                WHERE l.contact_id = %d ORDER BY t.slug ASC",
                $contact_id
            ),
            ARRAY_A
        );

        if ( ! is_array( $rows ) ) {
            return array();
        }

        $slugs = array();
        foreach ( $rows as $row ) {
            if ( is_array( $row ) && isset( $row['slug'] ) ) {
                $slugs[] = (string) $row['slug'];
            }
        }

        return $slugs;
    }
}
