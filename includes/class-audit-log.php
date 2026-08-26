<?php
/**
 * Lightweight audit log (option-backed ring buffer – no schema, very stable).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Audit_Log
 */
class AB_MCP_Audit_Log {

	const OPTION = 'ab_mcp_audit';
	const MAX    = 200;

	/**
	 * No-op kept for the activation hook (option storage needs no table).
	 */
	public static function install_table() {
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, array(), '', false );
		}
	}

	/**
	 * Record one tool call.
	 *
	 * @param string      $tool    Tool name.
	 * @param array       $args    Arguments (not stored verbatim – summarized).
	 * @param string      $status  ok | error | denied.
	 * @param string|null $message Optional message.
	 */
	public static function record( $tool, $args, $status, $message = null ) {
		if ( ! AB_MCP_Settings::get( 'audit_enabled', true ) ) {
			return;
		}

		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = array(
			'ts'      => time(),
			'user'    => get_current_user_id(),
			'tool'    => (string) $tool,
			'status'  => (string) $status,
			'message' => $message ? self::clip( (string) $message, 300 ) : '',
			'keys'    => is_array( $args ) ? implode( ',', array_slice( array_keys( $args ), 0, 12 ) ) : '',
		);

		if ( count( $log ) > self::MAX ) {
			$log = array_slice( $log, -self::MAX );
		}

		update_option( self::OPTION, $log, false );
	}

	/**
	 * Clip a string to a maximum length, multibyte-aware where possible.
	 * mbstring is nearly always present but not guaranteed; fall back to substr
	 * so logging never fatals on a minimal PHP build. A log line clipped a few
	 * bytes early on a rare no-mbstring host is harmless.
	 *
	 * @param string $s   String.
	 * @param int    $max Maximum length.
	 * @return string
	 */
	private static function clip( $s, $max ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $s, 0, $max );
		}
		return substr( $s, 0, $max );
	}

	/**
	 * Recent entries, newest first.
	 *
	 * @param int $limit Max entries.
	 * @return array
	 */
	public static function recent( $limit = 50 ) {
		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			return array();
		}
		$log = array_reverse( $log );
		return array_slice( $log, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Clear the log.
	 */
	public static function clear() {
		update_option( self::OPTION, array(), false );
	}
}
