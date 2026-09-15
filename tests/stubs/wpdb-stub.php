<?php
/**
 * Minimal $wpdb stand-in for unit tests (docs/11: domain tests run without
 * WordPress). Records writes and queries so repository tests can assert
 * what SQL was issued, and serves canned result rows for reads. Signatures
 * mirror the small core subset the plugin's repositories use.
 *
 * @package GreenPNG\Tests
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Gr_Stub_Wpdb' ) ) {

    /**
     * In-memory $wpdb replacement.
     */
    final class Gr_Stub_Wpdb {

        /**
         * Table prefix used by Gr_Schema/Gr_Database during tests.
         *
         * @var string
         */
        public string $prefix = 'wp_';

        /**
         * Incrementing row id returned after insert(), starting above zero.
         *
         * @var int
         */
        public int $insert_id = 0;

        /**
         * Recorded insert() calls: table, data, format.
         *
         * @var array<int, array<string, mixed>>
         */
        public array $inserts = array();

        /**
         * Recorded prepare()/get_results() SQL strings.
         *
         * @var array<int, string>
         */
        public array $queries = array();

        /**
         * Canned rows returned by get_results(); tests set these per
         * case. A Closure is called with the query string instead, so
         * per-query row sets are possible.
         *
         * @var array<int, mixed>|\Closure
         */
        public $results = array();

        /**
         * Canned scalar returned by get_var(); tests set these per case.
         *
         * @var string|null
         */
        public $var_result = null;

        /**
         * Canned return of query(); tests set these per case.
         *
         * @var int|false
         */
        public $query_result = 0;

        /**
         * Server family flag mirroring core's property.
         *
         * @var bool
         */
        public $is_mariadb = false;

        /**
         * Server version text behind db_version().
         *
         * @var string
         */
        public $server_version = '12.3';

        /**
         * Server identity string behind db_server_info(); models
         * core's shape, including "version-family" outputs where the
         * family is not spelled out (CLI contexts).
         *
         * @var string
         */
        public $server_info = '12.3.3-MariaDB';

        /**
         * Insert stand-in: records the call and fakes a successful write.
         *
         * @param string              $table  Table name.
         * @param array<string, mixed> $data   Column => value map.
         * @param array<int, string>  $format Value formats.
         * @return bool
         */
        public function insert( $table, $data, $format = array() ) {
            $this->inserts[] = array(
                'table'  => $table,
                'data'   => $data,
                'format' => $format,
            );

            $this->insert_id++;

            return true;
        }

        /**
         * Prepare stand-in: mirrors core's quoting for %s and int casting
         * for %d, then sprintf-substitutes; the built SQL is recorded.
         * Accepts variadic args or one array of args, like core.
         *
         * @param string $query Query template with %s/%d placeholders.
         * @param mixed  ...$args Replacement values.
         * @return string
         */
        public function prepare( $query, ...$args ) {
            if ( array() === $args ) {
                return $query;
            }

            if ( 1 === count( $args ) && is_array( $args[0] ) ) {
                $args = $args[0];
            }

            $values = array();
            foreach ( $args as $arg ) {
                if ( is_int( $arg ) || is_float( $arg ) ) {
                    $values[] = $arg;
                } else {
                    $values[] = "'" . addslashes( (string) $arg ) . "'";
                }
            }

            $sql             = vsprintf( $query, $values );
            $this->queries[] = (string) $sql;

            return (string) $sql;
        }

        /**
         * Write stand-in: records the SQL and returns the canned result.
         *
         * @param string $query SQL to run.
         * @return int|false
         */
        public function query( $query ) {
            $this->queries[] = (string) $query;

            return $this->query_result;
        }

        /**
         * Read stand-in: records the SQL and returns the canned rows.
         * When $results holds a Closure, it is called with the query
         * string so multi-query code paths (per-metric aggregation) can
         * answer each statement with its own rows.
         *
         * @param string $query SQL to run.
         * @param string $output Output type constant; ignored.
         * @return array<int, mixed>
         */
        public function get_results( $query, $output = 'OBJECT' ) {
            $this->queries[] = (string) $query;

            if ( $this->results instanceof \Closure ) {
                return (array) call_user_func( $this->results, (string) $query );
            }

            return $this->results;
        }

        /**
         * Single-row read stand-in: resolves through the same results
         * store (Closure included) and hands back the first row.
         *
         * @param string $query SQL to run.
         * @param string $output Output type constant; ignored.
         * @return array<string, mixed>|null
         */
        public function get_row( $query, $output = 'OBJECT', $y = 0 ) {
            $rows = $this->get_results( $query, $output );

            return isset( $rows[0] ) && is_array( $rows[0] ) ? $rows[0] : null;
        }

        /**
         * Scalar read stand-in: records the SQL and returns the canned value.
         *
         * @param string $query   SQL to run.
         * @param int    $column  Column offset; ignored.
         * @param int    $row     Row offset; ignored.
         * @return string|null
         */
        public function get_var( $query, $column = 0, $row = 0 ) {
            $this->queries[] = (string) $query;

            return $this->var_result;
        }

        /**
         * Column read stand-in: resolves through the same results store
         * (Closure included) and flattens the rows to their first
         * column, mirroring core's contract of an empty array when
         * nothing matched.
         *
         * @param string $query SQL to run.
         * @param int    $column Column offset; ignored beyond the first.
         * @return array<int, string>
         */
        public function get_col( $query, $column = 0 ) {
            $rows = $this->get_results( $query );

            $col = array();
            foreach ( $rows as $row ) {
                if ( is_object( $row ) ) {
                    $values = get_object_vars( $row );
                    if ( array() !== $values ) {
                        $col[] = (string) reset( $values );
                    }
                    continue;
                }
                if ( is_array( $row ) && array() !== $row ) {
                    $col[] = (string) reset( $row );
                }
            }

            return $col;
        }

        /**
         * LIKE-escape stand-in, mirroring core: backslashes before the
         * wildcard and escape characters so user input never becomes
         * pattern syntax.
         *
         * @param string $text Raw text.
         * @return string Escaped text.
         */
        public function esc_like( $text ) {
            return addcslashes( (string) $text, '%_\\' );
        }

        /**
         * Server version stand-in, mirroring core's method shape.
         *
         * @return string
         */
        public function db_version() {
            return $this->server_version;
        }

        /**
         * Server identity stand-in, mirroring core's method shape.
         *
         * @return string
         */
        public function db_server_info() {
            return $this->server_info;
        }
    }
}
