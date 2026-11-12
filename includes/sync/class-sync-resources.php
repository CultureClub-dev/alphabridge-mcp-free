<?php
/**
 * The check of the means before a run (concept «Echtes Staging», section
 * 15.11; Z8 branch 8): nothing begins that the means of the site do not
 * carry to its end. Before a run is planned and again in its first step
 * (the check of decision 16), the site's limits are measured, each against
 * what one step of the run needs, in three levels: ok, tight (under 1.5
 * times the need, TIGHT) and insufficient. One insufficient criterion
 * refuses the run before anything is written, with the two ways of the
 * concept: the change at the host (each value and what it should be), or
 * the work on the live site with the protection as before (the setting
 * «Staging first» of this site). A tight one lets the run go with a note.
 *
 * CRITERIA (table of 15.11), as a run needs them:
 *  - space: free space on the disk of the protected store against the
 *    bytes the run books (its backups with a reserve of 20 %, decision 8),
 *    and the test write of a megabyte AB_MCP_Store_Budget::book() makes;
 *  - time: max_execution_time after the raise every executing call makes
 *    (raise(): set_time_limit() to WANTED_TIME where the limit is below
 *    and the host lets it take), where it is a limit (0 is none), against
 *    MIN_TIME seconds a step needs at most; the work of a call is derived
 *    from the limit it has (time_for_call(), budget()), and a limit under
 *    MIN_TIME runs no write step (carries_write(); GitHub Codex round 3 on
 *    free#137);
 *  - memory: memory_limit (-1 is none) against MIN_MEMORY;
 *  - tempo: the seconds a write and read of TEMPO_BYTES in the folder of
 *    the store takes, and a read of one page of post ids, projected onto the
 *    bytes of the run (tight over TEMPO_MINUTES).
 * Database rights and the criteria of a clone are those of Z9a; a run
 * writes no table of its own.
 *
 * Refusals and errors are gettext texts in the language of the site, each
 * naming the way; codes, states, kinds and parameters stay as the tools
 * name them.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-protected-store.php';
require_once dirname( __DIR__ ) . '/class-store-budget.php';
require_once __DIR__ . '/class-sync-rows.php';

/**
 * Class AB_MCP_Sync_Resources
 */
final class AB_MCP_Sync_Resources {

	/** Seconds a step of a run needs at most: a request limit below it is insufficient, and a write step is not run under it (carries_write()). */
	const MIN_TIME = 30;

	/**
	 * Seconds set_time_limit() is asked for at the start of every executing
	 * call (time_for_call()): twice the work of a call and its reserve, so a
	 * host whose limit takes the request keeps room for the writes of
	 * WordPress the call ends with.
	 */
	const WANTED_TIME = 120;

	/** Seconds of work one call does at most, with no limit or one well above (the loops of the check and the verification stop here). */
	const WORK_MAX = 50;

	/** Seconds a call keeps for the step it writes after its work, and for its answer. */
	const WORK_RESERVE = 10;

	/** Bytes of memory a run needs: 128 MiB. */
	const MIN_MEMORY = 134217728;

	/** Below this many times the need a criterion is tight. */
	const TIGHT = 1.5;

	/** Bytes of the tempo measurement. */
	const TEMPO_BYTES = 1048576;

	/** Minutes the projected duration of the run passes to count as tight. */
	const TEMPO_MINUTES = 30;

	/** The reserve on the booked bytes (decision 8). */
	const RESERVE = 0.2;

	/** The levels. */
	const OK           = 'ok';
	const TIGHT_LEVEL  = 'tight';
	const INSUFFICIENT = 'insufficient';

	/**
	 * The measurements, set by tests only: a callable returning the array
	 * measure() returns, or null for the real ones.
	 *
	 * @var callable|null
	 */
	public static $probe = null;

	/**
	 * The time limit of a call, set by tests only: a callable returning
	 * array{before:int,after:int} as raise() measures them, or null for the
	 * real limit of the request.
	 *
	 * @var callable|null
	 */
	public static $time = null;

	/**
	 * The time of this request for one executing call (next, step of
	 * wp_site_push and wp_site_revert), GitHub Codex round 3 on free#137
	 * (P2): the limit raised to WANTED_TIME where set_time_limit() takes
	 * (raise()), then read as it is, and the work of the call derived from
	 * it (derived()): with no limit (0) or one above, WORK_MAX seconds; with
	 * a limit under that, half of it, never more than WORK_MAX, at least 1
	 * (the check and the verification do at least one unit per call and go
	 * on in the next). A limit under MIN_TIME carries no write step (writes
	 * false): the engine does not run a write or a step of the way back
	 * under it, with the way (the host, or set_time_limit() let take); the
	 * check and the verification read and go on with the smaller work.
	 * Before, measure() asked set_time_limit() for the limit it had, and no
	 * call raised it: on a host with 30 s the loops worked 50 s and the
	 * cursor was never saved.
	 *
	 * @return array{limit:int,before:int,raised:bool,budget:int,writes:bool}
	 */
	public static function time_for_call() {
		$pair = null !== self::$time ? (array) call_user_func( self::$time ) : self::raise();
		return self::derived( (int) ( $pair['before'] ?? 0 ), (int) ( $pair['after'] ?? 0 ) );
	}

	/**
	 * Try to raise the time limit of this request to WANTED_TIME where it
	 * has one below (set_time_limit() restarts the clock of the request as
	 * well); the limit before and after.
	 *
	 * @return array{before:int,after:int}
	 */
	public static function raise() {
		$before = (int) ini_get( 'max_execution_time' );
		if ( $before > 0 && $before < self::WANTED_TIME && function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::WANTED_TIME ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a host that forbids it answers false; the limit is read afterwards.
		}
		return array(
			'before' => $before,
			'after'  => (int) ini_get( 'max_execution_time' ),
		);
	}

	/**
	 * The time of a call from the limit before and after the raise (the
	 * calculation of time_for_call(), pure).
	 *
	 * @param int $before The limit before the raise (0 none).
	 * @param int $after  The limit after it (0 none).
	 * @return array{limit:int,before:int,raised:bool,budget:int,writes:bool}
	 */
	public static function derived( $before, $after ) {
		$after = max( 0, (int) $after );
		return array(
			'limit'  => $after,
			'before' => max( 0, (int) $before ),
			'raised' => $after !== max( 0, (int) $before ),
			'budget' => self::budget( $after ),
			'writes' => self::carries_write( $after ),
		);
	}

	/**
	 * Seconds of work one call does within a time limit: WORK_MAX with no
	 * limit (0); else half the limit (the other half stays for the writes of
	 * WordPress the call ends with, and for its answer), never more than
	 * WORK_MAX, at least 1.
	 *
	 * @param int $limit The limit of the request in seconds, 0 for none.
	 * @return int
	 */
	public static function budget( $limit ) {
		$limit = (int) $limit;
		if ( $limit <= 0 ) {
			return self::WORK_MAX;
		}
		return max( 1, min( self::WORK_MAX, intdiv( $limit, 2 ) ) );
	}

	/**
	 * Whether a time limit carries one write step (a boundary, or a step of
	 * the way back): none, or at least MIN_TIME seconds.
	 *
	 * @param int $limit The limit of the request in seconds, 0 for none.
	 * @return bool
	 */
	public static function carries_write( $limit ) {
		return (int) $limit <= 0 || (int) $limit >= self::MIN_TIME;
	}

	/**
	 * Check the means for a run that needs $bytes in the store.
	 *
	 * @param int $bytes Bytes the run books (its backups, as planned).
	 * @return array{level:string,criteria:array[],booked:int} Each criterion
	 *               with name, measured, needed, level, and the change at the
	 *               host where it is not ok.
	 */
	public static function check( $bytes ) {
		$m        = self::measure();
		$needed   = (int) ceil( max( 0, (int) $bytes ) * ( 1 + self::RESERVE ) ) + AB_MCP_Store_Budget::TEST_WRITE;
		$criteria = array();

		// Space on the disk of the store.
		$free = $m['free'];
		if ( null === $free ) {
			$criteria[] = self::criterion( 'space', 'unknown', $needed, self::INSUFFICIENT, __( 'Let PHP ask the free space of the disk (disk_free_space()), which this server refuses.', 'alphabridge-mcp' ) );
		} else {
			/* translators: 1: space needed (such as 1.5 GB), 2: space free now. */
			$criteria[] = self::criterion( 'space', (int) $free, $needed + AB_MCP_Store_Budget::RESERVE, self::level( (float) $free, (float) ( $needed + AB_MCP_Store_Budget::RESERVE ) ), sprintf( __( 'Free at least %1$s on the disk of the site (%2$s free now).', 'alphabridge-mcp' ), self::human( $needed + AB_MCP_Store_Budget::RESERVE ), self::human( (int) $free ) ) );
		}

		// Time per request.
		$limit = (int) $m['time_limit'];
		if ( 0 === $limit ) {
			$criteria[] = self::criterion( 'time', 0, self::MIN_TIME, self::OK, '' );
		} else {
			$level = self::level( (float) $limit, (float) self::MIN_TIME );
			// A limit set_time_limit() can raise is no limit of the step.
			if ( self::INSUFFICIENT === $level && $m['time_limit_settable'] ) {
				$level = self::TIGHT_LEVEL;
			}
			/* translators: 1: seconds needed, 2: seconds of the limit today. */
			$criteria[] = self::criterion( 'time', $limit, self::MIN_TIME, $level, sprintf( __( 'Raise the PHP time limit (max_execution_time) to at least %1$d s (today %2$d s); at Infomaniak under Advanced → PHP | Apache.', 'alphabridge-mcp' ), self::MIN_TIME, $limit ) );
		}

		// Memory.
		$memory = (int) $m['memory_limit'];
		if ( $memory < 0 ) {
			$criteria[] = self::criterion( 'memory', -1, self::MIN_MEMORY, self::OK, '' );
		} else {
			/* translators: 1: memory needed (such as 256 MB), 2: memory limit today. */
			$criteria[] = self::criterion( 'memory', $memory, self::MIN_MEMORY, self::level( (float) $memory, (float) self::MIN_MEMORY ), sprintf( __( 'Raise the PHP memory limit (memory_limit) to at least %1$s (today %2$s).', 'alphabridge-mcp' ), self::human( self::MIN_MEMORY ), self::human( $memory ) ) );
		}

		// Tempo: the projected duration of the run's backups and reads.
		$seconds = (float) $m['write_seconds'] + (float) $m['read_seconds'];
		if ( $seconds <= 0 ) {
			$criteria[] = self::criterion( 'tempo', 0, self::TEMPO_MINUTES * 60, self::OK, '' );
		} else {
			$projected  = (int) ceil( $seconds * max( 1, (int) $bytes ) / self::TEMPO_BYTES ) + (int) ceil( (float) $m['rows_seconds'] );
			/* translators: %d: seconds. */
			$criteria[] = self::criterion( 'tempo', $projected, self::TEMPO_MINUTES * 60, $projected > self::TEMPO_MINUTES * 60 ? self::TIGHT_LEVEL : self::OK, sprintf( __( 'The run is projected to take %d s on this server; it goes on over several calls.', 'alphabridge-mcp' ), $projected ) );
		}

		$level = self::OK;
		foreach ( $criteria as $c ) {
			if ( self::INSUFFICIENT === $c['level'] ) {
				$level = self::INSUFFICIENT;
			} elseif ( self::TIGHT_LEVEL === $c['level'] && self::OK === $level ) {
				$level = self::TIGHT_LEVEL;
			}
		}
		return array(
			'level'    => $level,
			'criteria' => $criteria,
			'booked'   => $needed,
		);
	}

	/**
	 * The refusal of a run whose means are insufficient: the two ways of
	 * the concept (15.11), the changes at the host each with its value.
	 *
	 * @param array $check check().
	 * @return WP_Error
	 */
	public static function refusal( array $check ) {
		$changes = array();
		foreach ( $check['criteria'] as $c ) {
			if ( self::INSUFFICIENT === $c['level'] ) {
				$changes[] = $c['change'];
			}
		}
		return new WP_Error(
			'ab_mcp_sync_run_resources',
			/* translators: %s: the changes at the host, each a whole sentence. */
			sprintf( __( 'Nothing began: the means of this site do not carry a takeover run to its end, and a run that stops halfway would leave the site in between. Two ways: 1. Set staging up with the host first: %s Then call wp_site_push again (action=plan). 2. Work directly on the live site with the protection as before (undo, confirmation): switch «Staging first» off for this site on the page AlphaBridge → Staging, or answer the question of «Staging first» with staging_acknowledged.', 'alphabridge-mcp' ), implode( ' ', $changes ) ),
			array( 'resources' => $check )
		);
	}

	/**
	 * The measurements: free space of the store's disk (null where PHP
	 * cannot ask), the PHP time limit and whether set_time_limit() takes a
	 * value, the memory limit in bytes (-1 for none), the seconds of a
	 * write and a read of TEMPO_BYTES in the store's folder, the seconds of
	 * one page of post ids.
	 *
	 * @return array{free:int|null,time_limit:int,time_limit_settable:bool,memory_limit:int,write_seconds:float,read_seconds:float,rows_seconds:float}
	 */
	public static function measure() {
		if ( null !== self::$probe ) {
			return (array) call_user_func( self::$probe );
		}
		$out  = array(
			'free'                => null,
			'time_limit'          => (int) ini_get( 'max_execution_time' ),
			'time_limit_settable' => false,
			'memory_limit'        => self::bytes_of( (string) ini_get( 'memory_limit' ) ),
			'write_seconds'       => 0.0,
			'read_seconds'        => 0.0,
			'rows_seconds'        => 0.0,
		);
		$root = AB_MCP_Protected_Store::root();
		if ( ! is_wp_error( $root ) ) {
			$free        = function_exists( 'disk_free_space' ) ? @disk_free_space( $root ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$out['free'] = is_int( $free ) || is_float( $free ) ? (int) $free : null;
			// A temp file of the store (Z37): made in its area tmp/, written
			// through write_file(), removed through delete_path() as made by
			// this request.
			$path                 = AB_MCP_Protected_Store::temp_file( $root );
			$data                 = str_repeat( 'x', self::TEMPO_BYTES );
			$start                = microtime( true );
			$written              = ! is_wp_error( $path ) && true === AB_MCP_Protected_Store::write_file( $root, $path, $data );
			$out['write_seconds'] = microtime( true ) - $start;
			if ( $written ) {
				$start = microtime( true );
				@file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$out['read_seconds'] = microtime( true ) - $start;
			}
			if ( ! is_wp_error( $path ) ) {
				AB_MCP_Protected_Store::delete_path( $root, $path );
			}
		}
		// The limit raised where set_time_limit() takes (raise(), as every
		// executing call does; GitHub Codex round 3 on free#137): what is
		// measured is the limit the calls of the run will have.
		$pair                       = self::raise();
		$out['time_limit']          = (int) $pair['after'];
		$out['time_limit_settable'] = (int) $pair['after'] !== (int) $pair['before'];
		$start = microtime( true );
		AB_MCP_Sync_Rows::post_ids( null, 0, AB_MCP_Sync_Rows::MAX_PAGE );
		$out['rows_seconds'] = microtime( true ) - $start;
		return $out;
	}

	/**
	 * Bytes of a PHP size (128M, 1G, 2048); -1 for no limit.
	 *
	 * @param string $size Size.
	 * @return int
	 */
	public static function bytes_of( $size ) {
		$size = trim( (string) $size );
		if ( '' === $size || '-1' === $size ) {
			return -1;
		}
		$unit  = strtolower( substr( $size, -1 ) );
		$value = (int) $size;
		switch ( $unit ) {
			case 'g':
				return $value * 1073741824;
			case 'm':
				return $value * 1048576;
			case 'k':
				return $value * 1024;
			default:
				return $value;
		}
	}

	/**
	 * @param float $have Measured.
	 * @param float $need Needed.
	 * @return string Level.
	 */
	public static function level( $have, $need ) {
		if ( $have < $need ) {
			return self::INSUFFICIENT;
		}
		return $have < $need * self::TIGHT ? self::TIGHT_LEVEL : self::OK;
	}

	/**
	 * @param string $name     Criterion.
	 * @param mixed  $measured Measured.
	 * @param mixed  $needed   Needed.
	 * @param string $level    Level.
	 * @param string $change   The change at the host, where not ok.
	 * @return array
	 */
	private static function criterion( $name, $measured, $needed, $level, $change ) {
		return array(
			'name'     => $name,
			'measured' => $measured,
			'needed'   => $needed,
			'level'    => $level,
			'change'   => self::OK === $level ? '' : $change,
		);
	}

	/**
	 * @param int $bytes Bytes.
	 * @return string Such as 1.5 GB.
	 */
	private static function human( $bytes ) {
		$bytes = (int) $bytes;
		if ( $bytes >= 1073741824 ) {
			return sprintf( '%.1f GB', $bytes / 1073741824 );
		}
		if ( $bytes >= 1048576 ) {
			return sprintf( '%.0f MB', $bytes / 1048576 );
		}
		return sprintf( '%d bytes', $bytes );
	}
}
