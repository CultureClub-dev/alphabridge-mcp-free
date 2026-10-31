<?php
/**
 * A stand-in for the helper of a Pro add-on of 4.16.0 or later that asks the
 * core which screens are its own (ab_mcp_pro_is_core_screen()): with it, the
 * core no longer calls the old stylesheet loaders.
 *
 * Declares a function of Pro's, so it is loaded only inside a test that runs
 * in a process of its own; the rest of the suite never sees it.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

function ab_mcp_pro_is_core_screen( $hook ) {
	return AB_MCP_Admin::is_own_screen( $hook );
}
