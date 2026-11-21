<?php

namespace Disembark;

/**
 * Table fingerprints, so a catch-up pull can leave tables alone that
 * haven't changed on either side since the last pull.
 *
 * A fingerprint is a hash of a table's structure (SHOW CREATE TABLE, its
 * AUTO_INCREMENT counter included on purpose: a counter that moved means
 * rows came and went) and of every row. Rows are read in primary-key
 * slices of about 50,000; each slice gives COUNT(*) and two independent
 * 60-bit sums over the rows' MD5 (the PK and every column, each with an
 * ISNULL marker, since CONCAT_WS skips NULLs), and the slices' counts and
 * sums are added up, so the result doesn't depend on where slices fall
 * (a slice made smaller after a timeout gives the same fingerprint).
 * The values only mean something compared with a fingerprint the same
 * server made earlier: two servers can print numbers and text differently,
 * so a source's fingerprint is never compared with a destination's.
 *
 * Fingerprinting works until a deadline and carries on from a cursor, like
 * the paced steps (see Pace). Anything it can't vouch for (no primary key,
 * a view, an SQL error, a slice that won't finish in time) comes back as
 * unknown with a reason, and an unknown table is always copied.
 */
class Fingerprint {

    /**
     * Tables smaller than this (data_length + index_length) are copied
     * without fingerprinting: copying them costs about what comparing
     * them would.
     */
    const MIN_BYTES = 4194304;

    /** Rows per slice, and the smallest a slice gets when one won't finish in time. */
    const SLICE     = 50000;
    const MIN_SLICE = 1000;

    /**
     * Fingerprints $tables (names as this site has them), carrying on from
     * $cur, until $deadline (a microtime; at least one slice runs). $cur:
     * [ t: table index, key: last primary key of the last slice (values in
     * key order) or null, rows, s1, s2: the sums so far, struct, slice,
     * tries ]. $before_slice($cur) runs before each slice (to save the
     * cursor, with tries counted, so a slice that kills its request is
     * made smaller next time). Returns [ done, results: { table: [ 'fp' ]
     * or [ 'unknown' => reason ] }, cur ].
     */
    public static function run( $tables, array $cur, $deadline, $before_slice = null ) {
        global $wpdb;
        $tables  = array_values( array_map( 'strval', (array) $tables ) );
        $cur    += [ 't' => 0, 'key' => null, 'rows' => 0, 's1' => '0', 's2' => '0', 'struct' => '', 'slice' => self::SLICE, 'tries' => 0 ];
        $results = [];
        $worked  = false;
        $wpdb->query( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
        $wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' );
        while ( $cur['t'] < count( $tables ) ) {
            if ( $worked && microtime( true ) >= $deadline ) {
                break;
            }
            $table = $tables[ $cur['t'] ];
            $next  = function () use ( &$cur ) {
                $cur = [ 't' => $cur['t'] + 1, 'key' => null, 'rows' => 0, 's1' => '0', 's2' => '0', 'struct' => '', 'slice' => self::SLICE, 'tries' => 0 ];
            };
            // A slice that already killed requests twice: smaller, and once
            // it can't get smaller, the table is unknown.
            if ( $cur['tries'] >= 2 ) {
                if ( $cur['slice'] <= self::MIN_SLICE ) {
                    $results[ $table ] = [ 'unknown' => 'fingerprinting it didn\'t finish in time' ];
                    $next();
                    continue;
                }
                $cur['slice'] = max( self::MIN_SLICE, (int) ( $cur['slice'] / 2 ) );
                $cur['tries'] = 0;
            }
            $one = self::slice( $table, $cur, $before_slice );
            $worked = true;
            if ( isset( $one['unknown'] ) ) {
                $results[ $table ] = $one;
                $next();
            } elseif ( ! empty( $one['done'] ) ) {
                $results[ $table ] = [ 'fp' => md5( $cur['struct'] . '|' . $cur['rows'] . '|' . $cur['s1'] . '|' . $cur['s2'] ) ];
                $next();
            }
        }
        $wpdb->query( 'COMMIT' );
        return [ 'done' => $cur['t'] >= count( $tables ), 'results' => $results, 'cur' => $cur ];
    }

    /** One slice of $table at $cur (which it moves on). Returns [ done ] or [ unknown => reason ]. */
    private static function slice( $table, array &$cur, $before_slice ) {
        global $wpdb;
        $ident = Backup::ident( $table );
        if ( $cur['struct'] === '' ) {
            $create = $wpdb->get_results( 'SHOW CREATE TABLE ' . $ident, ARRAY_N );
            if ( $wpdb->last_error || empty( $create[0][1] ) ) {
                return [ 'unknown' => 'SQL error: ' . substr( (string) $wpdb->last_error ?: 'no such table', 0, 120 ) ];
            }
            if ( Backup::is_view_definition( $create[0][1] ) ) {
                return [ 'unknown' => 'a view (always copied)' ];
            }
            $cur['struct'] = md5( $create[0][1] );
        }
        $keys = Backup::primary_key_columns( $table );
        if ( ! $keys ) {
            return [ 'unknown' => 'no primary key' ];
        }
        $col_rows = (array) $wpdb->get_results( 'SHOW COLUMNS FROM ' . $ident, ARRAY_A );
        if ( $wpdb->last_error || ! $col_rows ) {
            return [ 'unknown' => 'SQL error: ' . substr( (string) $wpdb->last_error, 0, 120 ) ];
        }
        $columns   = [];
        $col_types = [];
        foreach ( $col_rows as $col_row ) {
            $field = (string) ( $col_row['Field'] ?? '' );
            if ( $field !== '' ) {
                $columns[]                         = $field;
                $col_types[ strtolower( $field ) ] = (string) ( $col_row['Type'] ?? '' );
            }
        }
        if ( ! $columns ) {
            return [ 'unknown' => 'SQL error: no columns' ];
        }
        foreach ( $keys as $key_col ) {
            $type = $col_types[ strtolower( (string) $key_col ) ] ?? '';
            if ( $type !== '' && preg_match( '/^(?:binary|varbinary|(?:tiny|medium|long)?blob|bit)\b/i', trim( $type ) ) ) {
                return [ 'unknown' => 'binary primary key' ];
            }
        }
        if ( $before_slice ) {
            $cur['tries']++;
            $before_slice( $cur );
        }
        $key_list = implode( ', ', array_map( [ Backup::class, 'ident' ], $keys ) );
        $tuple    = count( $keys ) > 1 ? "({$key_list})" : $key_list;
        $after    = '';
        if ( $cur['key'] !== null ) {
            $after = ' WHERE ' . $tuple . ' > ' . self::values( (array) $cur['key'] );
        }
        // Where this slice ends: the key slice rows on (none: it's the last).
        $end = $wpdb->get_row( "SELECT {$key_list} FROM {$ident}{$after} ORDER BY {$key_list} LIMIT 1 OFFSET " . ( (int) $cur['slice'] - 1 ), ARRAY_N );
        if ( $wpdb->last_error ) {
            return [ 'unknown' => 'SQL error: ' . substr( $wpdb->last_error, 0, 120 ) ];
        }
        $where = $after;
        if ( $end ) {
            $where .= ( $where === '' ? ' WHERE ' : ' AND ' ) . $tuple . ' <= ' . self::values( $end );
        }
        $parts = [];
        foreach ( $columns as $col ) {
            $c       = Backup::ident( $col );
            $parts[] = "ISNULL({$c})";
            $parts[] = "COALESCE(CAST({$c} AS BINARY), '')";
        }
        $row_hash = 'MD5(CONCAT_WS(0x1f, ' . implode( ', ', $parts ) . '))';
        $sums     = $wpdb->get_row( "SELECT COUNT(*), SUM(CAST(CONV(SUBSTRING(h, 1, 15), 16, 10) AS UNSIGNED)), SUM(CAST(CONV(SUBSTRING(h, 16, 15), 16, 10) AS UNSIGNED)) FROM (SELECT {$row_hash} AS h FROM {$ident}{$where}) AS dsk_rows", ARRAY_N );
        if ( $wpdb->last_error || ! $sums ) {
            return [ 'unknown' => 'SQL error: ' . substr( (string) $wpdb->last_error, 0, 120 ) ];
        }
        $cur['rows'] += (int) $sums[0];
        $cur['s1']    = self::add( (string) $cur['s1'], (string) $sums[1] );
        $cur['s2']    = self::add( (string) $cur['s2'], (string) $sums[2] );
        $cur['tries'] = 0;
        if ( ! $end ) {
            return [ 'done' => true ];
        }
        $cur['key'] = array_values( $end );
        return [];
    }

    /** $a + $b, both whole numbers written out in decimal (they outgrow PHP's integers). */
    private static function add( $a, $b ) {
        $a = preg_replace( '/\D/', '', $a ) ?: '0';
        $b = preg_replace( '/\D/', '', $b ) ?: '0';
        if ( function_exists( 'bcadd' ) ) {
            return bcadd( $a, $b, 0 );
        }
        $out   = '';
        $carry = 0;
        for ( $i = strlen( $a ) - 1, $j = strlen( $b ) - 1; $i >= 0 || $j >= 0 || $carry; $i--, $j-- ) {
            $sum   = ( $i >= 0 ? (int) $a[ $i ] : 0 ) + ( $j >= 0 ? (int) $b[ $j ] : 0 ) + $carry;
            $out   = ( $sum % 10 ) . $out;
            $carry = intdiv( $sum, 10 );
        }
        return ltrim( $out, '0' ) ?: '0';
    }

    /** A key as an SQL value list: ('a', 'b'), or 'a' for one column. */
    private static function values( $key ) {
        global $wpdb;
        $quoted = array_map( function ( $v ) use ( $wpdb ) { return $v === null ? 'NULL' : "'" . $wpdb->_real_escape( (string) $v ) . "'"; }, array_values( (array) $key ) );
        return count( $quoted ) > 1 ? '(' . implode( ', ', $quoted ) . ')' : $quoted[0];
    }

    /** This site's tables with their size (data_length + index_length) and whether each is a view. */
    public static function sizes() {
        global $wpdb;
        $out = [];
        foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME AS n, TABLE_TYPE AS k, COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0) AS s FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s', DB_NAME ), ARRAY_A ) as $row ) {
            $out[ (string) $row['n'] ] = [ 'size' => (int) $row['s'], 'view' => strtoupper( (string) $row['k'] ) === 'VIEW' ];
        }
        return $out;
    }
}
