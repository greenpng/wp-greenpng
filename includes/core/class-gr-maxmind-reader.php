<?php
/**
 * Minimal MMDB reader (ADR-0003 no-Composer rule, docs/19 V23): the
 * official MaxMind DB binary format, country-level only. The format
 * truth is the public file-format specification v2.0: a 16-byte NULL
 * separator follows the search tree, IPv4 networks sit in the lowest
 * 32 bits of the 128-bit key space (96 zero bits walked first), a
 * record equal to node_count is the official miss, and a record
 * above it names a data pointer whose offset is
 * (record - node_count) - 16 from the data section start.
 *
 * The decoder keeps the specification's own resource limits — a
 * nesting ceiling, a decoded-value ceiling, and a payload budget —
 * because a crafted or corrupt data section could otherwise make a
 * handful of pointers describe an unbounded structure. A file that
 * trips any limit, or fails the metadata battery, answers as if the
 * address were unknown; the caller falls back to the DB-IP set.
 *
 * @package GreenPNG
 */

declare( strict_types = 1 );

namespace GreenPNG\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Country lookups over one GeoLite2-Country database file.
 */
final class Gr_Maxmind_Reader {

    /** Nesting ceiling per decoded entry (official guidance: 512). */
    private const MAX_DEPTH = 512;

    /** Decoded-value ceiling per entry (official guidance: 2^16). */
    private const MAX_VALUES = 65536;

    /** Bytes of string/bytes payload one entry may materialize. */
    private const MAX_PAYLOAD = 1048576;

    /** The metadata section's own size ceiling (official: 128 KiB). */
    private const META_TAIL = 131072;

    /** The metadata marker, searched for its last occurrence. */
    private const MARKER = "\xab\xcd\xefMaxMind.com";

    /**
     * Open read handles per file path, reused across lookups.
     *
     * @var array<string, resource|false>
     */
    private static $handles = array();

    /**
     * Metadata battery for one candidate database file.
     *
     * @param string $path Absolute file path.
     * @return array<string, mixed>|null Parsed metadata, or null when the
     *                                    file is not a readable v2 database.
     */
    public static function validate( string $path ): ?array {
        if ( ! is_file( $path ) || ! is_readable( $path ) ) {
            return null;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- read-only metadata of a data file under uploads.
        $size = filesize( $path );
        if ( false === $size || $size < 64 ) {
            return null;
        }

        $tail = self::read( $path, max( 0, $size - self::META_TAIL ), min( (int) $size, self::META_TAIL ) );
        $mark = '' === $tail ? false : strrpos( $tail, self::MARKER );
        if ( false === $mark ) {
            return null;
        }

        $meta_start = max( 0, $size - self::META_TAIL ) + $mark + strlen( self::MARKER );
        $cursor     = $meta_start;
        $state      = self::fresh_state();
        $meta       = self::decode_field( $path, $meta_start, $cursor, $state );

        if ( ! is_array( $meta ) ) {
            return null;
        }

        $node_count = $meta['node_count'] ?? null;
        if ( ! is_int( $node_count ) || $node_count <= 0 ) {
            return null;
        }

        $record_size = $meta['record_size'] ?? null;
        if ( ! is_int( $record_size ) || ! in_array( $record_size, array( 24, 28, 32 ), true ) ) {
            return null;
        }

        if ( 2 !== (int) ( $meta['binary_format_major_version'] ?? 0 ) ) {
            return null;
        }

        $ip_version = (int) ( $meta['ip_version'] ?? 0 );
        if ( 4 !== $ip_version && 6 !== $ip_version ) {
            return null;
        }

        if ( ! is_string( $meta['database_type'] ?? null ) || '' === $meta['database_type'] ) {
            return null;
        }

        return array(
            'node_count'    => $node_count,
            'record_size'   => $record_size,
            'ip_version'    => $ip_version,
            'build_epoch'   => (int) ( $meta['build_epoch'] ?? 0 ),
            'database_type' => (string) $meta['database_type'],
        );
    }

    /**
     * One country lookup. An unreadable, malformed, or resource-hungry
     * file answers '' so the caller can fall back to the DB-IP set.
     *
     * @param string $path Database file path.
     * @param string $ip   Textual address.
     * @return string Two-letter ISO code, or ''.
     */
    public static function lookup( string $path, string $ip ): string {
        $meta = self::validate( $path );
        if ( null === $meta ) {
            return '';
        }

        $key = self::key( $ip );
        if ( '' === $key ) {
            return '';
        }

        $node_count = $meta['node_count'];
        $node       = 0;

        for ( $bit = 0; $bit < 128; $bit++ ) {
            $one = 1 === ( ( ord( $key[ $bit >> 3 ] ) >> ( 7 - ( $bit & 7 ) ) ) & 1 );
            $rec = self::record( $path, $meta, $node, $one );
            if ( null === $rec ) {
                return '';
            }

            if ( $rec === $node_count ) {
                return ''; // The official miss encoding.
            }

            if ( $rec > $node_count ) {
                // Official formula: the pointer names a data-section
                // offset of (record - node_count) - 16.
                $offset = $rec - $node_count - 16;
                if ( $offset < 0 ) {
                    return ''; // node_count+1..+15 are reserved, never data.
                }

                $data_start = self::data_start( $meta );
                $cursor     = $data_start + $offset;
                $state      = self::fresh_state();
                $record     = self::decode_field( $path, $data_start, $cursor, $state );
                $country    = is_array( $record ) ? ( $record['country'] ?? null ) : null;
                $code       = is_array( $country ) ? ( $country['iso_code'] ?? null ) : null;

                return is_string( $code ) ? $code : '';
            }

            $node = $rec;
        }

        // The walk ran out of address bits on a node pointer: no data.
        return '';
    }

    /**
     * The 128-bit walk key. An IPv4 address rides the lowest 32 bits
     * (96 zero bits walked first); IPv4-mapped IPv6 addresses are
     * normalized to plain IPv4 the way the official readers do.
     *
     * @param string $ip Textual address.
     * @return string 16 raw bytes, or '' when the input is no address.
     */
    private static function key( string $ip ): string {
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
            $packed = (string) inet_pton( $ip );

            // 80 zero bytes then ffff: IPv4-mapped, so walk the v4 bits.
            if ( "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" === substr( $packed, 0, 12 ) ) {
                return "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" . substr( $packed, 12, 4 );
            }

            return $packed;
        }

        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            return "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" . (string) inet_pton( $ip );
        }

        return '';
    }

    /**
     * One node record: the left (bit 0) or right (bit 1) pointer in
     * the 24/28/32-bit layouts the format defines.
     *
     * @param string               $path  Database file path.
     * @param array<string, mixed> $meta  Parsed metadata.
     * @param int                  $node  Node index.
     * @param bool                 $right True for the right record.
     * @return int|null Record value, or null on a short read.
     */
    private static function record( string $path, array $meta, int $node, bool $right ) {
        $size       = (int) $meta['record_size'];
        $node_bytes = (int) ( ( $size * 2 ) / 8 );
        $raw        = self::read( $path, $node * $node_bytes, $node_bytes );
        if ( strlen( $raw ) !== $node_bytes ) {
            return null;
        }

        if ( 24 === $size ) {
            $part = substr( $raw, $right ? 3 : 0, 3 );

            return unpack( 'N', "\x00" . $part )[1];
        }

        if ( 32 === $size ) {
            return unpack( 'N', substr( $raw, $right ? 4 : 0, 4 ) )[1];
        }

        // 28-bit layout: 7 bytes per node; the middle byte's top nibble
        // completes the left pointer and its bottom nibble the right one.
        $middle = ord( $raw[3] );
        if ( $right ) {
            return ( ( $middle & 0x0f ) << 24 ) | unpack( 'N', "\x00" . substr( $raw, 4, 3 ) )[1];
        }

        return unpack( 'N', "\x00" . substr( $raw, 0, 3 ) )[1] * 16 + ( $middle >> 4 );
    }

    /**
     * The data section start: tree bytes plus the 16-byte separator.
     *
     * @param array<string, mixed> $meta Parsed metadata.
     * @return int
     */
    private static function data_start( array $meta ): int {
        $node_bytes = (int) ( ( (int) $meta['record_size'] * 2 ) / 8 );

        return $node_bytes * (int) $meta['node_count'] + 16;
    }

    /**
     * Decode one field at the cursor. Pointers resolve against the
     * section base they live in (data pointers against the data
     * section, metadata pointers against the metadata section), and
     * the cursor ends past the whole decoded field so sequential
     * containers can walk their children.
     *
     * @param string             $path   Database file path.
     * @param int                $base   Section base, in file bytes.
     * @param int                $cursor Read/write position, in file bytes.
     * @param array<string, int> $state  Live resource counters.
     * @return mixed The decoded value, or null when refused.
     */
    private static function decode_field( string $path, int $base, int &$cursor, array &$state ) {
        if ( $state['depth'] >= self::MAX_DEPTH || $state['values'] >= self::MAX_VALUES || $state['payload'] >= self::MAX_PAYLOAD ) {
            return null;
        }

        ++$state['values'];

        $ctrl = self::read( $path, $cursor, 1 );
        if ( '' === $ctrl ) {
            return null;
        }
        ++$cursor;

        $type = ( ord( $ctrl ) >> 5 ) & 7;
        $size = ord( $ctrl ) & 31;

        if ( 0 === $type ) {
            $ext = self::read( $path, $cursor, 1 );
            if ( '' === $ext ) {
                return null;
            }
            $type = ord( $ext ) + 7;
            ++$cursor;
        }

        if ( 1 === $type ) {
            return self::decode_pointer( $path, $base, $cursor, $size, $state );
        }

        $size = self::payload_size( $path, $size, $cursor );
        if ( null === $size ) {
            return null;
        }

        switch ( $type ) {
            case 2: // UTF-8 string.
                $state['payload'] += $size;
                $value             = self::read( $path, $cursor, $size );
                $cursor           += $size;

                return $value;
            case 4: // bytes.
                $state['payload'] += $size;
                $value             = self::read( $path, $cursor, $size );
                $cursor           += $size;

                return $value;
            case 3: // double.
            case 5: // uint16.
            case 6: // uint32.
            case 8: // int32.
            case 9: // uint64.
            case 10: // uint128: country metadata never carries one; the
                // battery below refuses widths no country file uses.
                $value = self::decode_uint( $path, $cursor, $size, 8 === $type );
                if ( null === $value ) {
                    return null;
                }
                $cursor += $size;

                return $value;
            case 7: // map.
                return self::decode_map( $path, $base, $cursor, $size, $state );
            case 11: // array.
                return self::decode_array( $path, $base, $cursor, $size, $state );
            case 14: // boolean: the size bits are the value, no payload.
                return 1 === $size;
            case 15: // float: raw payload bytes, never in country data.
                $value   = self::read( $path, $cursor, $size );
                $cursor += $size;

                return $value;
            default:
                return null; // 12/13 are deprecated; nothing to decode.
        }
    }

    /**
     * Pointer decode: the five size bits split as 001SSVVV, with the
     * S bits the size class and the V bits the pointer's top bits.
     *
     * @param string             $path   Database file path.
     * @param int                $base   Section base, in file bytes.
     * @param int                $cursor Position after the control byte.
     * @param int                $bits   The five size bits.
     * @param array<string, int> $state  Live resource counters.
     * @return mixed The pointed-at value, or null when refused.
     */
    private static function decode_pointer( string $path, int $base, int &$cursor, int $bits, array &$state ) {
        $class = $bits >> 3;
        $top   = $bits & 7;

        if ( 3 === $class ) {
            $raw = self::read( $path, $cursor, 4 );
            if ( 4 !== strlen( $raw ) ) {
                return null;
            }
            $cursor += 4;
            $target  = unpack( 'N', $raw )[1];
        } else {
            $raw = self::read( $path, $cursor, $class + 1 );
            if ( $class + 1 !== strlen( $raw ) ) {
                return null;
            }
            $cursor += $class + 1;

            $target = $top << ( 8 * ( $class + 1 ) );
            for ( $i = 0; $i <= $class; $i++ ) {
                $target = $target | ( ord( $raw[ $i ] ) << ( 8 * ( $class - $i ) ) );
            }

            if ( 1 === $class ) {
                $target += 2048;
            }
            if ( 2 === $class ) {
                $target += 526336;
            }
        }

        if ( $target < 0 || $target >= self::MAX_PAYLOAD * 64 ) {
            return null; // Far past any data section a country file has.
        }

        // The cursor stays past the pointer bytes: sequential fields
        // follow the pointer, not the value it points at.
        ++$state['depth'];
        $target_cursor = $base + $target;
        $value         = self::decode_field( $path, $base, $target_cursor, $state );
        --$state['depth'];

        return $value;
    }

    /**
     * The payload size, applying the 29/30/31 extension rule.
     *
     * @param string $path   Database file path.
     * @param int    $size   Raw five size bits.
     * @param int    $cursor Read/write position.
     * @return int|null Size in bytes (or pair/item count), or null.
     */
    private static function payload_size( string $path, int $size, int &$cursor ) {
        if ( $size < 29 ) {
            return $size;
        }

        if ( 29 === $size ) {
            $ext = self::read( $path, $cursor, 1 );
            if ( '' === $ext ) {
                return null;
            }
            ++$cursor;

            return 29 + ord( $ext );
        }

        if ( 30 === $size ) {
            $ext = self::read( $path, $cursor, 2 );
            if ( 2 !== strlen( $ext ) ) {
                return null;
            }
            $cursor += 2;

            return 285 + unpack( 'n', $ext )[1];
        }

        $ext = self::read( $path, $cursor, 3 );
        if ( 3 !== strlen( $ext ) ) {
            return null;
        }
        $cursor += 3;

        return 65821 + unpack( 'N', "\x00" . $ext )[1];
    }

    /**
     * Big-endian integer decode at variable length; a zero length is
     * the number zero. Widths wider than the platform's integer only
     * occur for uint64 build_epoch, whose value stays display-sized,
     * so it is clamped rather than wrapped.
     *
     * @param string $path   Database file path.
     * @param int    $cursor Payload start.
     * @param int    $size   Payload length in bytes.
     * @param bool   $signed Whether the type is signed 32-bit.
     * @return int|null
     */
    private static function decode_uint( string $path, int $cursor, int $size, bool $signed ) {
        if ( $size > 8 ) {
            return null; // uint128 is not country metadata; refuse it.
        }

        if ( 0 === $size ) {
            return 0;
        }

        $raw = self::read( $path, $cursor, $size );
        if ( strlen( $raw ) !== $size ) {
            return null;
        }

        $value = 0;
        for ( $i = 0; $i < $size; $i++ ) {
            $value = $value * 256 + ord( $raw[ $i ] );
        }

        if ( $signed && 4 === $size && $value > 2147483647 ) {
            $value -= 4294967296;
        }

        if ( $value > PHP_INT_MAX ) {
            // Only a uint64 build epoch can land here, and only on
            // 32-bit PHP: the display value is clamped, never wrapped.
            $value = PHP_INT_MAX;
        }

        return (int) $value;
    }

    /**
     * Map decode: the size is the pair count, keys are UTF-8 strings.
     *
     * @param string             $path   Database file path.
     * @param int                $base   Section base, in file bytes.
     * @param int                $cursor Read/write position.
     * @param int                $count  Pair count.
     * @param array<string, int> $state  Live resource counters.
     * @return array<string, mixed>|null
     */
    private static function decode_map( string $path, int $base, int &$cursor, int $count, array &$state ) {
        if ( $count > self::MAX_VALUES - $state['values'] ) {
            return null; // Reserve the declared children before decoding them.
        }

        ++$state['depth'];
        $map = array();
        for ( $i = 0; $i < $count; $i++ ) {
            $name = self::decode_field( $path, $base, $cursor, $state );
            if ( ! is_string( $name ) ) {
                --$state['depth'];

                return null;
            }

            $value = self::decode_field( $path, $base, $cursor, $state );
            if ( null === $value ) {
                // decode_field() answers null only on refusal; a
                // legitimate null never exists in this format.
                --$state['depth'];

                return null;
            }

            $map[ $name ] = $value;
        }
        --$state['depth'];

        return $map;
    }

    /**
     * Array decode: the size is the item count.
     *
     * @param string             $path   Database file path.
     * @param int                $base   Section base, in file bytes.
     * @param int                $cursor Read/write position.
     * @param int                $count  Item count.
     * @param array<string, int> $state  Live resource counters.
     * @return array<int, mixed>|null
     */
    private static function decode_array( string $path, int $base, int &$cursor, int $count, array &$state ) {
        if ( $count > self::MAX_VALUES - $state['values'] ) {
            return null;
        }

        ++$state['depth'];
        $list = array();
        for ( $i = 0; $i < $count; $i++ ) {
            $value = self::decode_field( $path, $base, $cursor, $state );
            if ( null === $value ) {
                --$state['depth'];

                return null;
            }
            $list[] = $value;
        }
        --$state['depth'];

        return $list;
    }

    /**
     * Fresh resource counters.
     *
     * @return array<string, int>
     */
    private static function fresh_state(): array {
        return array(
            'depth'   => 0,
            'values'  => 0,
            'payload' => 0,
        );
    }

    /**
     * A bounded read at one file offset through the memoized handle.
     *
     * @param string $path   Database file path.
     * @param int    $offset Read start.
     * @param int    $length Bytes to read.
     * @return string The bytes read, '' on any short read.
     */
    private static function read( string $path, int $offset, int $length ) {
        if ( $offset < 0 || $length <= 0 ) {
            return '';
        }

        $handle = self::handle( $path );
        if ( false === $handle ) {
            return '';
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek, WordPress.WP.AlternativeFunctions.file_system_operations_fread -- read-only access to a data file under uploads; no WordPress filesystem API on a pure binary walk.
        if ( 0 !== fseek( $handle, $offset ) ) {
            return '';
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- read-only access to a data file under uploads.
        $raw = fread( $handle, $length );

        return ( false === $raw || strlen( $raw ) !== $length ) ? '' : $raw;
    }

    /**
     * Open (and reuse) one file's read handle.
     *
     * @param string $path Database file path.
     * @return resource|false
     */
    private static function handle( string $path ) {
        if ( ! array_key_exists( $path, self::$handles ) ) {
            self::$handles[ $path ] = is_readable( $path )
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- read-only access to a data file under uploads.
                ? fopen( $path, 'rb' )
                : false;
        }

        return self::$handles[ $path ];
    }

    /**
     * Test seam: forget the memoized handles.
     *
     * @return void
     */
    public static function reset_for_tests(): void {
        foreach ( self::$handles as $handle ) {
            if ( is_resource( $handle ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the read-only handle this class opened itself.
                fclose( $handle );
            }
        }
        self::$handles = array();
    }
}
