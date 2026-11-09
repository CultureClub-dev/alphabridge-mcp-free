<?php
/**
 * What a person confirmed on the page «Staging», bound to what the page
 * showed (Z8, branch 12; Codex rounds 1 and 2: «Close as checked» and
 * «Discard»). One helper for both actions, with two sides, each from
 * exactly one reading:
 *
 * - SHOWN (shown()): one reading gives what the page shows (the journal of
 *   an interrupted run, a row of a pair) and, from that same reading in
 *   memory, the print its form carries. No second reading for the print.
 * - DONE (done()): under the lock of the write boundary
 *   (AB_MCP_Sync_Run::under_lock(), the lock every step of a run takes),
 *   one reading, compared with the print of the form, and the action taken
 *   on exactly that reading: it writes conditionally on what it read (the
 *   state of a run or step it read, the bytes of the row of a pair it
 *   read), and reads nothing of it again in between. Where the print does
 *   not hold, nothing happens, and the refusal names the way.
 *
 * The readings, prints and actions are the callers' own
 * (AB_MCP_Sync_Run::close_checked(), AB_MCP_Sync_Pairs_Tool::
 * discard_as_shown(); AB_MCP_Transfer for the page); this class only holds
 * them to that order.
 *
 * Messages are English without gettext, as the other classes of the
 * takeover; each names the way.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-run.php';

/**
 * Class AB_MCP_Sync_Shown
 */
final class AB_MCP_Sync_Shown {

	/**
	 * What the page shows and the print of its form, from one reading.
	 *
	 * @param callable $read  function(): mixed|WP_Error, the one reading.
	 * @param callable $print function( mixed $read ): mixed, the print of the form, from it.
	 * @return array{read:mixed,print:mixed}|WP_Error
	 */
	public static function shown( callable $read, callable $print ) {
		$data = call_user_func( $read );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return array(
			'read'  => $data,
			'print' => call_user_func( $print, $data ),
		);
	}

	/**
	 * The action on what the person saw: under the lock, one reading,
	 * compared with the print of the form, then the action on that reading.
	 * A print of null (a caller of the tool that confirmed nothing on a
	 * page) skips the comparison, not the reading or the lock.
	 *
	 * @param callable    $read     function(): mixed|WP_Error, the one reading under the lock.
	 * @param callable    $print    function( mixed $read ): string, its print.
	 * @param string|null $expected The print of the form; null for none.
	 * @param callable    $act      function( mixed $read ): mixed|WP_Error, the action on that reading.
	 * @param callable    $refusal  function( mixed $read ): WP_Error, where the print does not hold.
	 * @param string      $what     What does not happen while another request holds the lock.
	 * @return mixed|WP_Error
	 */
	public static function done( callable $read, callable $print, $expected, callable $act, callable $refusal, $what ) {
		return AB_MCP_Sync_Run::under_lock(
			static function () use ( $read, $print, $expected, $act, $refusal ) {
				$data = call_user_func( $read );
				if ( is_wp_error( $data ) ) {
					return $data;
				}
				if ( null !== $expected && ( ! is_string( $expected ) || ! hash_equals( (string) call_user_func( $print, $data ), $expected ) ) ) {
					return call_user_func( $refusal, $data );
				}
				return call_user_func( $act, $data );
			},
			$what
		);
	}
}
