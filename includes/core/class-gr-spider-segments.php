<?php
/**
 * Search-engine spider segment store (docs/07 §5.4, docs/19 V17): the
 * one place that answers "does this address sit inside an official
 * crawler segment" from the uploads copy the refresh job publishes.
 * The lookup is a local read against at most a few dozen ranges, so
 * it needs no packing and never touches the wire. The per-source
 * attribution survives the merge because the confidence consumer
 * needs more than "some engine": a segment that names the same
 * engine the user agent claims is the double proof, and the store
 * hands out source words, not just a boolean.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use GreenPNG\Security\Gr_Ip_Matcher;

/**
 * Segment table: load once per request, answer from memory.
 */
final class Gr_Spider_Segments {

    /** Uploads subdirectory holding the published segment file. */
    public const UPLOAD_SUBDIR = 'gr-spiders';

    /** The published data file, a plain PHP return-array. */
    public const OUT_FILE = 'gr-spiders.php';

    /** The three subscribed engines, in stable display order. */
    public const SOURCES = array( 'google', 'bing', 'apple' );

    /**
     * Per-request memo of the published table, or its empty shape.
     *
     * @var array{built: string, segments: array<string, array<int, string>>}|null
     */
    private static $memo = null;

    /**
     * Whether a published table exists at all, so the page can tell
     * "never refreshed" from "refreshed but empty".
     *
     * @return bool
     */
    public static function available(): bool {
        return is_readable( self::file_path() );
    }

    /**
     * The current table summary for the page: build date, per-source
     * segment counts, and the total.
     *
     * @return array{built: string, sources: array<string, int>, total: int}
     */
    public static function status(): array {
        $data = self::data();

        return array(
            'built'   => $data['built'],
            'sources' => array_map( 'count', $data['segments'] ),
            'total'   => array_sum( array_map( 'count', $data['segments'] ) ),
        );
    }

    /**
     * Every engine whose table contains the address. Source order is
     * stable and the answer is empty whenever no table exists, so a
     * missing file can never look like a verdict.
     *
     * @param string $ip Client address as text.
     * @return array<int, string> Matching source words.
     */
    public static function sources_for( string $ip ): array {
        $segments = self::data()['segments'];
        $hit      = array();

        foreach ( self::SOURCES as $source ) {
            if ( array() !== $segments[ $source ] && Gr_Ip_Matcher::match( $ip, $segments[ $source ] ) ) {
                $hit[] = $source;
            }
        }

        return $hit;
    }

    /**
     * Drops the memo so a freshly published table is read on the
     * next call instead of the one this request already loaded.
     *
     * @return void
     */
    public static function reset_for_tests(): void {
        self::$memo = null;
    }

    /**
     * The published table, validated to its empty shape when the
     * file is missing or not the array this class writes.
     *
     * @return array{built: string, segments: array<string, array<int, string>>}
     */
    private static function data(): array {
        if ( null !== self::$memo ) {
            return self::$memo;
        }

        $memo = array(
            'built'    => '',
            'segments' => array(
                'google' => array(),
                'bing'   => array(),
                'apple'  => array(),
            ),
        );

        $file = self::file_path();
        if ( is_readable( $file ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the segment file is this plugin's own generated return-array contract, the same load discipline as the packed cloud ranges.
            $loaded = include $file;

            if ( is_array( $loaded ) && is_string( $loaded['built'] ?? null ) && is_array( $loaded['segments'] ?? null ) ) {
                $memo['built'] = $loaded['built'];

                foreach ( self::SOURCES as $source ) {
                    $list = $loaded['segments'][ $source ] ?? null;
                    if ( is_array( $list ) ) {
                        $memo['segments'][ $source ] = array_values(
                            array_filter(
                                $list,
                                static function ( $line ): bool {
                                    return is_string( $line ) && '' !== $line;
                                }
                            )
                        );
                    }
                }
            }
        }

        self::$memo = $memo;

        return $memo;
    }

    /**
     * Absolute path of the published table inside uploads.
     *
     * @return string
     */
    private static function file_path(): string {
        $uploads = wp_upload_dir();

        return rtrim( (string) $uploads['basedir'], '/' ) . '/' . self::UPLOAD_SUBDIR . '/' . self::OUT_FILE;
    }
}
