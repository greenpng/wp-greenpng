<?php
/**
 * Funnel definition repository (ADR-0014 D1): definitions live in the
 * gr_funnels table with their step flow as JSON, bounded by the same
 * closed event vocabulary the stream owns. Validation is a static seam
 * so the admin form and the tests share one truth about what a legal
 * funnel is; save() is the only writer.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Storage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Core\Gr_Database;

/**
 * Funnel CRUD and aggregate reads over gr_funnels.
 */
final class Gr_Funnel_Repository {

    /** Upper bound on steps per funnel. */
    public const MAX_STEPS = 10;

    /**
     * Closed event-name vocabulary usable as an event-kind step match
     * (docs/05 gr_events; 'signal' probe conclusions are deliberately
     * absent — they are risk verdicts, not journey markers).
     */
    public const EVENT_STEP_NAMES = array(
        'pageview',
        'dwell',
        'scroll_depth',
        'rage_click',
        'dead_click',
        'conversion',
        'ab',
    );

    /** Step match kinds. */
    public const MATCH_KINDS = array( 'url', 'event' );

    /** Step match comparison modes. */
    public const COMPARES = array( 'exact', 'prefix' );

    /** Longest accepted funnel name (name column width). */
    private const NAME_MAX = 191;

    /** Longest accepted step label. */
    private const STEP_NAME_MAX = 64;

    /**
     * Memoized active definitions for this request; the tracker reads
     * them on every bus event, so one query per request is the budget.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private static ?array $active_memo = null;

    /**
     * Validates a step flow exactly as save() will accept it, so the
     * form can attribute errors to a row and tests can probe the
     * boundaries. A step is {name, match: {kind, value, compare}}.
     *
     * @param array<int, mixed> $steps Raw step list.
     * @return true|\WP_Error True when legal, else the first problem.
     */
    public static function validate_steps( array $steps ) {
        $count = count( $steps );
        if ( $count < 2 ) {
            return new \WP_Error( 'gr_funnel_min_steps', __( 'A funnel needs at least two steps.', 'greenpng' ) );
        }
        if ( $count > self::MAX_STEPS ) {
            /* translators: %d: maximum step count. */
            return new \WP_Error( 'gr_funnel_max_steps', sprintf( __( 'A funnel can have at most %d steps.', 'greenpng' ), self::MAX_STEPS ) );
        }

        $seen = array();
        foreach ( $steps as $index => $step ) {
            $row = $index + 1;

            if ( ! is_array( $step ) ) {
                /* translators: %d: one-based step number. */
                return new \WP_Error( 'gr_funnel_step_shape', sprintf( __( 'Step %d is not a step definition.', 'greenpng' ), $row ), array( 'step' => $row ) );
            }

            $name = isset( $step['name'] ) ? trim( (string) $step['name'] ) : '';
            if ( '' === $name || strlen( $name ) > self::STEP_NAME_MAX ) {
                /* translators: %d: one-based step number. */
                return new \WP_Error( 'gr_funnel_step_name', sprintf( __( 'Step %d needs a name of at most 64 characters.', 'greenpng' ), $row ), array( 'step' => $row ) );
            }
            if ( isset( $seen[ $name ] ) ) {
                /* translators: %d: one-based step number. */
                return new \WP_Error( 'gr_funnel_step_unique', sprintf( __( 'Step %d repeats a name already used in this funnel.', 'greenpng' ), $row ), array( 'step' => $row ) );
            }
            $seen[ $name ] = true;

            $match = isset( $step['match'] ) ? $step['match'] : null;
            if ( ! is_array( $match ) ) {
                /* translators: %d: one-based step number. */
                return new \WP_Error( 'gr_funnel_match_shape', sprintf( __( 'Step %d needs a match rule.', 'greenpng' ), $row ), array( 'step' => $row ) );
            }

            $kind    = isset( $match['kind'] ) ? (string) $match['kind'] : '';
            $value   = isset( $match['value'] ) ? trim( (string) $match['value'] ) : '';
            $compare = isset( $match['compare'] ) ? (string) $match['compare'] : '';

            if ( ! in_array( $kind, self::MATCH_KINDS, true ) ) {
                /* translators: %d: one-based step number. */
                return new \WP_Error( 'gr_funnel_match_kind', sprintf( __( 'Step %d match kind must be url or event.', 'greenpng' ), $row ), array( 'step' => $row ) );
            }
            if ( '' === $value || strlen( $value ) > self::NAME_MAX ) {
                /* translators: %d: one-based step number. */
                return new \WP_Error( 'gr_funnel_match_value', sprintf( __( 'Step %d needs a match value of at most 191 characters.', 'greenpng' ), $row ), array( 'step' => $row ) );
            }
            if ( ! in_array( $compare, self::COMPARES, true ) ) {
                /* translators: %d: one-based step number. */
                return new \WP_Error( 'gr_funnel_match_compare', sprintf( __( 'Step %d comparison must be exact or prefix.', 'greenpng' ), $row ), array( 'step' => $row ) );
            }
            if ( 'event' === $kind && ! in_array( $value, self::EVENT_STEP_NAMES, true ) ) {
                /* translators: 1: one-based step number, 2: comma-separated event names. */
                return new \WP_Error( 'gr_funnel_match_event', sprintf( __( 'Step %1$d event must be one of: %2$s.', 'greenpng' ), $row, implode( ', ', self::EVENT_STEP_NAMES ) ), array( 'step' => $row ) );
            }
            if ( 'url' === $kind && ! self::is_rooted_path( $value ) ) {
                /* translators: %d: one-based step number. */
                return new \WP_Error( 'gr_funnel_match_url', sprintf( __( 'Step %d URL must be a site-rooted path like /checkout (no host or scheme).', 'greenpng' ), $row ), array( 'step' => $row ) );
            }
        }

        return true;
    }

    /**
     * A URL-step value must be a path the tracker can compare against
     * the collect payload's path: rooted at /, no scheme, no host, no
     * traversal segments. Prefix matches apply to this exact shape.
     *
     * @param string $value Raw match value.
     * @return bool
     */
    private static function is_rooted_path( string $value ): bool {
        if ( ! str_starts_with( $value, '/' ) || str_contains( $value, '://' ) || str_contains( $value, '..' ) ) {
            return false;
        }

        return '' !== trim( $value );
    }

    /**
     * Inserts or updates one funnel definition. The slug is derived
     * from the name at insert and never changes afterwards — it is the
     * definition's identity, not a display field.
     *
     * @param string            $name     Display name.
     * @param array<int, mixed> $steps    Step flow (validated here).
     * @param bool              $is_active Whether the tracker follows it.
     * @param int               $id       Row id to update, 0 to insert.
     * @return int|\WP_Error Row id, else the validation or lookup error.
     */
    public function save( string $name, array $steps, bool $is_active = true, int $id = 0 ) {
        global $wpdb;

        $name = trim( $name );
        if ( '' === $name || strlen( $name ) > self::NAME_MAX ) {
            return new \WP_Error( 'gr_funnel_name', __( 'The funnel needs a name of at most 191 characters.', 'greenpng' ) );
        }

        $valid = self::validate_steps( $steps );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        $table = Gr_Database::table( 'funnels' );
        $now   = current_time( 'mysql' );

        if ( $id > 0 ) {
            $existing = $this->row_for_id( $id );
            if ( null === $existing ) {
                return new \WP_Error( 'gr_funnel_missing', __( 'That funnel no longer exists.', 'greenpng' ) );
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin write arm, one row by primary key.
            $updated = $wpdb->query(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; the SQL is the prepare() output below.
                    "UPDATE {$table} SET name = %s, flow_json = %s, is_active = %d, updated_at = %s WHERE id = %d",
                    array( $name, (string) wp_json_encode( $steps ), $is_active ? 1 : 0, $now, $id )
                )
            );

            self::reset_memo();

            // An identical rewrite reports zero affected rows; the row
            // is what it should be, so the save still succeeded.
            return $id;
        }

        $slug = $this->unique_slug( $name );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin write arm, bounded by funnel count.
        $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; the SQL is the prepare() output below.
                "INSERT INTO {$table} (name, slug, flow_json, is_active, created_at, updated_at) VALUES (%s, %s, %s, %d, %s, %s)",
                array( $name, $slug, (string) wp_json_encode( $steps ), $is_active ? 1 : 0, $now, $now )
            )
        );

        self::reset_memo();

        // The insert id is the success signal, like the conversion
        // binding: a rejected write leaves it untouched.
        $id = (int) $wpdb->insert_id;
        if ( $id < 1 ) {
            return new \WP_Error( 'gr_funnel_write', __( 'The funnel could not be saved.', 'greenpng' ) );
        }

        return $id;
    }

    /**
     * Derives a slug unique among the stored funnels. Collisions
     * (two funnels named the same) resolve with a numeric suffix
     * rather than failing the save.
     *
     * @param string $name Display name.
     * @return string
     */
    private function unique_slug( string $name ): string {
        global $wpdb;

        // Spaces become dashes before sanitize_key so multi-word names
        // read as slugs instead of one glued word.
        $base = sanitize_key( str_replace( ' ', '-', strtolower( $name ) ) );
        if ( '' === $base ) {
            $base = 'funnel';
        }

        $table = Gr_Database::table( 'funnels' );
        $slugs = array();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin write-arm read; bounded by funnel count.
        $result = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input.
            "SELECT slug FROM {$table}"
        );
        if ( is_array( $result ) ) {
            $slugs = array_map( 'strval', $result );
        }

        if ( ! in_array( $base, $slugs, true ) ) {
            return $base;
        }

        $suffix = 2;
        while ( in_array( $base . '-' . $suffix, $slugs, true ) ) {
            ++$suffix;
        }

        return $base . '-' . $suffix;
    }

    /**
     * All definitions, oldest first, with the flow decoded into a
     * steps array under the 'steps' key.
     *
     * @return array<int, array<string, mixed>> Rows keyed by column.
     */
    public function all(): array {
        global $wpdb;

        $table = Gr_Database::table( 'funnels' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin list read; bounded by funnel count.
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input.
            "SELECT id, name, slug, flow_json, is_active, created_at, updated_at FROM {$table} ORDER BY id ASC",
            ARRAY_A
        );

        return self::decode_rows( is_array( $rows ) ? $rows : array() );
    }

    /**
     * Active definitions for the tracker, memoized per request: the
     * 'gr_event' bus fires this read once, not once per event.
     *
     * @return array<int, array<string, mixed>> Rows keyed by column.
     */
    public function active(): array {
        if ( null !== self::$active_memo ) {
            return self::$active_memo;
        }

        global $wpdb;

        $table = Gr_Database::table( 'funnels' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bus-mount read, memoized per request so the per-event cost is the loop, not the query.
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input.
            "SELECT id, name, slug, flow_json FROM {$table} WHERE is_active = 1 ORDER BY id ASC",
            ARRAY_A
        );

        self::$active_memo = self::decode_rows( is_array( $rows ) ? $rows : array() );

        return self::$active_memo;
    }

    /**
     * One definition by id or null.
     *
     * @param int $id Row id.
     * @return array<string, mixed>|null
     */
    public function row_for_id( int $id ) {
        global $wpdb;

        if ( $id < 1 ) {
            return null;
        }

        $table = Gr_Database::table( 'funnels' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin page point read by primary key.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; the SQL is the prepare() output below.
                "SELECT id, name, slug, flow_json, is_active, created_at, updated_at FROM {$table} WHERE id = %d",
                array( $id )
            ),
            ARRAY_A
        );

        if ( ! is_array( $row ) ) {
            return null;
        }

        return self::decode_rows( array( $row ) )[0];
    }

    /**
     * Deletes a definition and its session rows together: orphaned
     * journey rows would report step counts for a funnel nobody can
     * see anymore.
     *
     * @param int $id Row id.
     * @return bool
     */
    public function delete( int $id ): bool {
        global $wpdb;

        if ( $id < 1 ) {
            return false;
        }

        $funnels  = Gr_Database::table( 'funnels' );
        $sessions = Gr_Database::table( 'funnel_sessions' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin write arm, one row by primary key; the session sweep is the definition's own data.
        $deleted = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; the SQL is the prepare() output below.
                "DELETE FROM {$funnels} WHERE id = %d",
                array( $id )
            )
        );

        if ( ! is_int( $deleted ) || $deleted < 1 ) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cascade of the delete above; retention would trim these rows eventually anyway.
        $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; the SQL is the prepare() output below.
                "DELETE FROM {$sessions} WHERE funnel_id = %d",
                array( $id )
            )
        );

        self::reset_memo();

        return true;
    }

    /**
     * Flips a definition's active flag; pausing stops the tracker
     * from following it while keeping every recorded journey.
     *
     * @param int  $id     Row id.
     * @param bool $active New state.
     * @return bool
     */
    public function set_active( int $id, bool $active ): bool {
        global $wpdb;

        if ( $id < 1 ) {
            return false;
        }

        $table = Gr_Database::table( 'funnels' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin write arm, one row by primary key.
        $updated = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; the SQL is the prepare() output below.
                "UPDATE {$table} SET is_active = %d, updated_at = %s WHERE id = %d",
                array( $active ? 1 : 0, current_time( 'mysql' ), $id )
            )
        );

        self::reset_memo();

        return is_int( $updated ) && $updated > 0;
    }

    /**
     * Step-loss counts (ADR-0014 D3): sessions that reached each step
     * within the window, aggregated over max_step so the numbers stay
     * monotonic down the staircase, plus how many completed the last
     * step. Entered-at windows the read because a journey is counted
     * from the day it began.
     *
     * @param int $funnel_id Funnel id.
     * @param int $days      Window in days, clamped 1..90.
     * @return array{steps: array<int, int>, completed: int} Step number => session count.
     */
    public function step_counts( int $funnel_id, int $days = 30 ): array {
        global $wpdb;

        $funnel = $this->row_for_id( $funnel_id );
        if ( null === $funnel || array() === $funnel['steps'] ) {
            return array(
                'steps'     => array(),
                'completed' => 0,
            );
        }

        $days  = max( 1, min( $days, 90 ) );
        $table = Gr_Database::table( 'funnel_sessions' );
        $since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin report read over the entered-at index.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; the SQL is the prepare() output below.
                "SELECT max_step, COUNT(*) AS sessions, SUM(completed_at IS NOT NULL) AS completed FROM {$table} WHERE funnel_id = %d AND entered_at >= %s GROUP BY max_step",
                array( $funnel_id, $since )
            ),
            ARRAY_A
        );

        $total  = count( (array) $funnel['steps'] );
        $by_max = array();
        $done   = 0;
        foreach ( (array) $rows as $row ) {
            $max_step            = (int) $row['max_step'];
            $by_max[ $max_step ] = (int) $row['sessions'];
            $done               += (int) $row['completed'];
        }

        $steps = array();
        for ( $step = 1; $step <= $total; $step++ ) {
            $reached = 0;
            foreach ( $by_max as $max_step => $sessions ) {
                if ( $max_step >= $step ) {
                    $reached += $sessions;
                }
            }
            $steps[ $step ] = $reached;
        }

        return array(
            'steps'     => $steps,
            'completed' => $done,
        );
    }

    /**
     * Completed-journey counts per funnel (goals tab): the same
     * completed_at marker the tracker sets on the final step.
     *
     * @param int $days Window in days, clamped 1..90.
     * @return array<int, int> Funnel id => completed sessions.
     */
    public function completed_counts( int $days = 30 ): array {
        global $wpdb;

        $days  = max( 1, min( $days, 90 ) );
        $table = Gr_Database::table( 'funnel_sessions' );
        $since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin report read over the completed_at marker.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; the SQL is the prepare() output below.
                "SELECT funnel_id, COUNT(*) AS completed FROM {$table} WHERE completed_at IS NOT NULL AND entered_at >= %s GROUP BY funnel_id",
                array( $since )
            ),
            ARRAY_A
        );

        $counts = array();
        foreach ( (array) $rows as $row ) {
            $counts[ (int) $row['funnel_id'] ] = (int) $row['completed'];
        }

        return $counts;
    }

    /**
     * Deletes every journey row for one visitor — the privacy
     * erasure arm. Definitions stay; only the visitor's progress
     * through them is their own data.
     *
     * @param string $visitor_id Visitor identity.
     * @return int Rows removed.
     */
    public function delete_journeys_for_visitor( string $visitor_id ): int {
        global $wpdb;

        if ( '' === $visitor_id ) {
            return 0;
        }

        $table = Gr_Database::table( 'funnel_sessions' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy erasure arm, point deletes over the visitor index.
        $removed = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $table is a DDL-validated identifier from Gr_Database, not user input; the SQL is the prepare() output below.
                "DELETE FROM {$table} WHERE visitor_id = %s",
                array( $visitor_id )
            )
        );

        return is_int( $removed ) ? $removed : 0;
    }

    /**
     * Decodes flow_json into the steps array every reader expects;
     * a corrupted flow degrades to an empty step list rather than
     * a fatal on an admin page.
     *
     * @param array<int, mixed> $rows Raw rows.
     * @return array<int, array<string, mixed>>
     */
    private static function decode_rows( array $rows ): array {
        $decoded = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $row['id'] = (int) $row['id'];
            // wpdb hands back strings: '1' is truthy, '0' is falsy,
            // so the empty check is the whole story.
            $row['is_active'] = ! empty( $row['is_active'] );
            $steps            = isset( $row['flow_json'] ) ? json_decode( (string) $row['flow_json'], true ) : null;
            $row['steps']     = is_array( $steps ) ? $steps : array();
            $decoded[]        = $row;
        }

        return $decoded;
    }

    /**
     * Test seam: drops the memoized active set so the next read sees
     * the table again. Every write path already resets it.
     *
     * @return void
     */
    public static function reset_memo_for_tests(): void {
        self::reset_memo();
    }

    /**
     * Memo reset shared by every writer.
     *
     * @return void
     */
    private static function reset_memo(): void {
        self::$active_memo = null;
    }
}
