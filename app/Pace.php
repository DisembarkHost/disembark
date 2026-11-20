<?php

namespace Disembark;

/**
 * Pace to the host. Cheap hosts kill PHP after 20 to 60 seconds whatever
 * set_time_limit() says, and fail some requests at random. So the heavy
 * steps of a move (placing files, importing the database, rewriting
 * addresses, the restore point, the pull's database) work until a time
 * budget, save where they are, and answer { done: false }; the client
 * calls again. The client also sizes the pull's batches to the host.
 *
 * A step only works this way when the request asks for it (a "paced"
 * parameter, sent by a client that saw "pacing" in the preflight). An
 * older client gets the old behavior: the whole step in one request.
 *
 * Progress is kept two ways. A checkpoint (an exit-guarded .php file) is
 * written now and then, and a journal (one line per statement, file or
 * row, appended) says how far the work got since. A request that is
 * killed mid-work loses nothing: the next call reads the journal and
 * carries on from there, so nothing runs twice.
 */
class Pace {

    /** The largest budget, in seconds. */
    const MAX_BUDGET = 20;

    /** The smallest budget a client can ask for. */
    const MIN_BUDGET = 2;

    /**
     * The time budget for this request, in seconds: what the client asked
     * for, never more than min(20, half of max_execution_time when set).
     */
    public static function budget( $requested = null ) {
        $budget = self::default_budget();
        if ( $requested !== null && $requested !== '' && is_numeric( $requested ) ) {
            $budget = min( $budget, max( self::MIN_BUDGET, (float) $requested ) );
        }
        return $budget;
    }

    public static function default_budget() {
        $budget = self::MAX_BUDGET;
        $max    = (int) ini_get( 'max_execution_time' );
        if ( $max > 0 ) {
            $budget = min( $budget, max( self::MIN_BUDGET, $max / 2 ) );
        }
        return (float) $budget;
    }

    /** When a request with $budget seconds must stop (counted from when it started). */
    public static function deadline( $budget ) {
        $start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
        // A long-running CLI process started long ago: count from now.
        if ( $start < microtime( true ) - 3600 ) {
            $start = microtime( true );
        }
        return $start + (float) $budget;
    }

    /** Seconds left before $deadline (never below zero). */
    public static function left( $deadline ) {
        return max( 0, $deadline - microtime( true ) );
    }

    /** Once per file Disembark zips, extracts or places. Lets a slow-host simulator hook in; cheap otherwise. */
    public static function tick() {
        do_action( 'disembark_file_tick' );
    }

    /* ------------------------------------------------------------------
     * Journals: an exit-guarded .php file of JSON lines, appended to.
     * ---------------------------------------------------------------- */

    /** Opens $file for appending (created with the exit line). Returns a handle or false. */
    public static function journal_open( $file ) {
        $new = ! is_file( $file ) || filesize( $file ) === 0;
        $h   = @fopen( $file, 'ab' );
        if ( $h && $new ) {
            fwrite( $h, Import::PHP_EXIT );
        }
        return $h;
    }

    /** Appends one record. */
    public static function journal_add( $h, $record ) {
        if ( $h ) {
            fwrite( $h, wp_json_encode( $record ) . "\n" );
        }
    }

    /** Every complete record in $file, in order. */
    public static function journal_read( $file ) {
        $out = [];
        $raw = Import::read_protected( $file );
        if ( $raw === null || $raw === '' ) {
            return $out;
        }
        $lines = explode( "\n", $raw );
        array_pop( $lines ); // the last piece has no newline yet: incomplete or empty
        foreach ( $lines as $line ) {
            $rec = json_decode( $line, true );
            if ( $rec !== null ) {
                $out[] = $rec;
            }
        }
        return $out;
    }

    /** The last complete record in $file, or null (reads only its tail). */
    public static function journal_last( $file ) {
        if ( ! is_file( $file ) ) {
            return null;
        }
        $size = filesize( $file );
        $h    = @fopen( $file, 'rb' );
        if ( ! $h ) {
            return null;
        }
        $take = min( $size, 65536 );
        fseek( $h, $size - $take );
        $tail = (string) fread( $h, $take );
        fclose( $h );
        $lines = explode( "\n", $tail );
        array_pop( $lines );
        while ( $lines ) {
            $rec = json_decode( (string) array_pop( $lines ), true );
            if ( $rec !== null ) {
                return $rec;
            }
        }
        return null;
    }

    /* ------------------------------------------------------------------
     * The learned pace, kept with the move so a resume starts there.
     * ---------------------------------------------------------------- */

    /** Keeps the client's pace (batch sizes, budget, parallel batches) for import $import_id. */
    public static function remember( $import_id, $pace ) {
        $import_id = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $import_id ) );
        if ( $import_id === '' || ! is_array( $pace ) ) {
            return;
        }
        $keep = [];
        foreach ( [ 'files', 'bytes', 'db', 'budget', 'par' ] as $key ) {
            if ( isset( $pace[ $key ] ) && is_numeric( $pace[ $key ] ) && $pace[ $key ] > 0 ) {
                $keep[ $key ] = $pace[ $key ] + 0;
            }
        }
        $dir = Storage::base_dir() . "/_import_{$import_id}";
        if ( ! $keep || ! is_dir( $dir ) ) {
            return;
        }
        $file = $dir . '/pace.php';
        $now  = wp_json_encode( $keep );
        if ( Import::read_protected( $file ) !== $now ) {
            Import::write_protected( $file, (string) $now );
        }
    }

    /** The pace kept for import $import_id, or null. */
    public static function recall( $import_id ) {
        $import_id = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $import_id ) );
        $pace      = json_decode( (string) Import::read_protected( Storage::base_dir() . "/_import_{$import_id}/pace.php" ), true );
        return is_array( $pace ) ? $pace : null;
    }

    /* ------------------------------------------------------------------
     * Ranges of file indexes: [ [start, end), ... ], sorted and merged.
     * ---------------------------------------------------------------- */

    public static function ranges_add( $ranges, $start, $end ) {
        $ranges[] = [ (int) $start, (int) $end ];
        usort( $ranges, function ( $a, $b ) { return $a[0] - $b[0]; } );
        $out = [];
        foreach ( $ranges as $r ) {
            if ( $r[1] <= $r[0] ) {
                continue;
            }
            $last = count( $out ) - 1;
            if ( $last >= 0 && $r[0] <= $out[ $last ][1] ) {
                $out[ $last ][1] = max( $out[ $last ][1], $r[1] );
            } else {
                $out[] = [ (int) $r[0], (int) $r[1] ];
            }
        }
        return $out;
    }

    public static function ranges_count( $ranges ) {
        $n = 0;
        foreach ( (array) $ranges as $r ) {
            $n += max( 0, (int) $r[1] - (int) $r[0] );
        }
        return $n;
    }

    /** Whether $ranges cover all of [$start, $end). */
    public static function ranges_cover( $ranges, $start, $end ) {
        foreach ( (array) $ranges as $r ) {
            if ( $r[0] <= $start && $r[1] >= $end ) {
                return true;
            }
        }
        return false;
    }

    /** The first stretch of [0, $total) not covered by $ranges or $busy: [start, end) or null. */
    public static function ranges_gap( $ranges, $total, $busy = [] ) {
        $all = $ranges;
        foreach ( (array) $busy as $b ) {
            $all = self::ranges_add( $all, $b[0], $b[1] );
        }
        $at = 0;
        foreach ( $all as $r ) {
            if ( $r[0] > $at ) {
                return [ $at, min( (int) $r[0], (int) $total ) ];
            }
            $at = max( $at, (int) $r[1] );
        }
        return $at < $total ? [ $at, (int) $total ] : null;
    }
}
