<?php
/**
 * A stand-in for the option guard of AlphaBridge MCP Pro
 * (AB_MCP_Tools_Options_Settings_Pro::option_write_refused()), which the
 * widget tools ask whether the row widget_<base> may be written: here a
 * name with "token" in it is refused, as Pro's credential needle refuses it.
 *
 * Declares a class of Pro's, so it is loaded only inside a test that runs
 * in a process of its own; the rest of the suite never sees it.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

if ( ! class_exists( 'AB_MCP_Tools_Options_Settings_Pro', false ) ) {
	class AB_MCP_Tools_Options_Settings_Pro {
		public static function option_write_refused( $name ) {
			$GLOBALS['ab_test_option_guard_asked'][] = (string) $name;
			return false !== strpos( (string) $name, 'token' ) ? new WP_Error( 'ab_mcp_blocked', 'This option is protected: ' . $name ) : false;
		}
	}
}
