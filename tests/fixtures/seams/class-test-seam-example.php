<?php
/**
 * The example of a test seam (Z8b branch 1, point 5): how a heikel/ branch
 * of Z8b puts its calls to the outside behind one object that only the tests
 * replace (AB_MCP_Test_Seam). Here a swap of two folders with a journal, as
 * branch 5 will build it for real: journal, target to backup, staged to
 * target, journal done.
 *
 * The rules (SeamGuardTest holds them for every tagged property of the
 * shipped code; this file is its passing example):
 * - the property carries the tag in its doc comment, is private static, has
 *   no type and starts as null;
 * - it occurs in exactly two statements of its own class:
 *   «return self::$calls ?? new <the real calls class>();» (the accessor) and
 *   «self::$calls = null;»; nothing else reads or sets it, so no filter, no
 *   option, no constant, no variable chooses the object.
 *
 * Test code: not shipped, not loaded by the plugin.
 *
 * @package AlphaBridge_MCP
 */

/**
 * The calls to the outside: the real ones.
 */
final class AB_MCP_Test_Example_Calls {

	/**
	 * @param string $from From.
	 * @param string $to   To.
	 * @return bool
	 */
	public function rename( $from, $to ) {
		return @rename( $from, $to );
	}

	/**
	 * @param string $path  File.
	 * @param string $bytes Bytes.
	 * @return int|false
	 */
	public function put( $path, $bytes ) {
		return @file_put_contents( $path, $bytes );
	}

	/**
	 * @param string $path File.
	 * @return string|false
	 */
	public function get( $path ) {
		return @file_get_contents( $path );
	}
}

/**
 * The class of the branch, with its seam.
 */
final class AB_MCP_Test_Example_Swap {

	/** @ab-mcp-seam The calls to the outside; only the tests put another object here. */
	private static $calls = null;

	/**
	 * The calls object: the stand-in of a test, else the real calls.
	 *
	 * @return AB_MCP_Test_Example_Calls
	 */
	private static function calls() {
		return self::$calls ?? new AB_MCP_Test_Example_Calls();
	}

	/**
	 * Back to the real calls (the second statement a seam may occur in).
	 */
	public static function forget() {
		self::$calls = null;
	}

	/**
	 * Swap a staged folder in for a target, keeping the target as backup.
	 *
	 * @param string $target  Target folder.
	 * @param string $staged  Staged folder.
	 * @param string $backup  Where the target goes.
	 * @param string $journal Journal file.
	 * @return string swapped, journal_failed, first_rename_failed,
	 *                second_rename_failed (the target put back).
	 */
	public static function swap( $target, $staged, $backup, $journal ) {
		if ( false === self::calls()->put( $journal, 'begun' ) ) {
			return 'journal_failed';
		}
		if ( ! self::calls()->rename( $target, $backup ) ) {
			return 'first_rename_failed';
		}
		if ( ! self::calls()->rename( $staged, $target ) ) {
			self::calls()->rename( $backup, $target );
			return 'second_rename_failed';
		}
		self::calls()->put( $journal, 'done' );
		return 'swapped';
	}

	/**
	 * What the journal says.
	 *
	 * @param string $journal Journal file.
	 * @return string Its text, or 'unreadable'.
	 */
	public static function journal( $journal ) {
		$text = self::calls()->get( $journal );
		return false === $text ? 'unreadable' : $text;
	}
}
