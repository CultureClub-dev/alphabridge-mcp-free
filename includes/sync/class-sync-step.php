<?php
/**
 * The helpers of the steps of a reading under a deadline (Z8b branch 5,
 * R12b, section 3.1 of the order: the one place of the promise (a)). For
 * each function of a step that a reading outside the chain of the check of
 * names calls (a read from the disk) there is one static method of the same
 * name here, with the deadline as its first parameter and otherwise the
 * parameters of the function:
 *
 * - it checks the deadline first (AB_MCP_Sync_Deadline::left()); where that
 *   is not true, it answers with that stop, and the step does not run;
 * - else it calls the function with its arguments unchanged and answers with
 *   its result unchanged;
 * - without a deadline (null) it is the bare call, without a reading of the
 *   clock. It sets no default value the caller does not set: an optional
 *   parameter of the function is passed on only where the caller gives it
 *   (the variadic tail ...$more).
 *
 * Not here (R12b, part 3.1): fopen(), fstat() and fread(), steps only of the
 * atomic seams of AB_MCP_Sync_File_Manifest_Calls, which stay direct calls
 * (section 3.1 of the order); idn_to_ascii(), called through the callable of
 * AB_MCP_Sync_Key::idn() (part 4). The steps of the chain stay direct calls.
 *
 * $watch is the seam of the tests (as AB_MCP_Sync_Env::$clock): told of each
 * check ('check') and of each step that then runs ('run'), so a test tells
 * the check of the deadline from the operation run; a call refused is no
 * step run. Null outside the tests.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-deadline.php';

/**
 * Class AB_MCP_Sync_Step
 */
final class AB_MCP_Sync_Step {

	/** @var callable|null Tests only: told ( 'check' or 'run', the step ). */
	public static $watch = null;

	/* ------------------------------------------------------------ the disk */

	/**
	 * is_file() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $filename As for is_file().
	 * @return bool|WP_Error
	 */
	public static function is_file( $deadline, $filename ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return is_file( $filename );
	}

	/**
	 * is_readable() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $filename As for is_readable().
	 * @return bool|WP_Error
	 */
	public static function is_readable( $deadline, $filename ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return is_readable( $filename );
	}

	/**
	 * filesize() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $filename As for filesize().
	 * @return int|false|WP_Error
	 */
	public static function filesize( $deadline, $filename ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return filesize( $filename );
	}

	/**
	 * file_get_contents() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $filename As for file_get_contents().
	 * @param mixed  ...$more  Its optional arguments, where the caller gives them.
	 * @return string|false|WP_Error
	 */
	public static function file_get_contents( $deadline, $filename, ...$more ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return file_get_contents( $filename, ...$more ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the step of the caller, unchanged.
	}

	/**
	 * scandir() under the deadline.
	 *
	 * @param mixed  $deadline  Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $directory As for scandir().
	 * @param mixed  ...$more   Its optional arguments, where the caller gives them.
	 * @return array|false|WP_Error
	 */
	public static function scandir( $deadline, $directory, ...$more ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return scandir( $directory, ...$more );
	}

	/**
	 * lstat() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $filename As for lstat().
	 * @return array|false|WP_Error
	 */
	public static function lstat( $deadline, $filename ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return lstat( $filename );
	}

	/**
	 * stat() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $filename As for stat().
	 * @return array|false|WP_Error
	 */
	public static function stat( $deadline, $filename ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return stat( $filename );
	}

	/**
	 * realpath() under the deadline.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $path     As for realpath().
	 * @return string|false|WP_Error
	 */
	public static function realpath( $deadline, $path ) {
		$left = self::left( $deadline, __FUNCTION__ );
		if ( true !== $left ) {
			return $left;
		}
		return realpath( $path );
	}

	/* ----------------------------------------------------------- the check */

	/**
	 * The check of a helper: the deadline (true, or the stop), its seam told
	 * of the check and, where the step runs, of the step.
	 *
	 * @param mixed  $deadline Null, or a deadline of AB_MCP_Sync_Deadline::at().
	 * @param string $step     The step (the name of the helper).
	 * @return true|WP_Error
	 */
	private static function left( $deadline, $step ) {
		self::tell( 'check', $step );
		$left = AB_MCP_Sync_Deadline::left( $deadline );
		if ( true === $left ) {
			self::tell( 'run', $step );
		}
		return $left;
	}

	/**
	 * Tells the seam of the tests, where one is set.
	 *
	 * @param string $what  'check' or 'run'.
	 * @param string $step  The step.
	 */
	private static function tell( $what, $step ) {
		if ( null !== self::$watch ) {
			call_user_func( self::$watch, $what, $step );
		}
	}
}
