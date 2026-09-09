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
         * Canned rows returned by get_results(); tests set these per case.
         *
         * @var array<int, mixed>
         */
        public array $results = array();

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
         * Prepare stand-in: sprintf-style substitution, recorded.
         *
         * @param string $query Query template with %s/%d placeholders.
         * @param mixed  ...$args Replacement values.
         * @return string
         */
        public function prepare( $query, ...$args ) {
            if ( array() === $args ) {
                return $query;
            }

            $sql           = vsprintf( $query, $args );
            $this->queries[] = (string) $sql;

            return (string) $sql;
        }

        /**
         * Read stand-in: records the SQL and returns the canned rows.
         *
         * @param string $query SQL to run.
         * @param string $output Output type constant; ignored.
         * @return array<int, mixed>
         */
        public function get_results( $query, $output = 'OBJECT' ) {
            $this->queries[] = (string) $query;

            return $this->results;
        }
    }
}
