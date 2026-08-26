<?php
/**
 * Uninstall cleanup.
 *
 * @package AlphaBridge_MCP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// The Pro plugin (distributed separately) bundles this same core and shares this
// core data (connections/tokens, tool state, settings, audit log). If Pro is
// still installed, deleting the shared data here would silently wipe Pro's
// connections and configuration. So: leave the data alone while Pro exists —
// Pro's own uninstall cleans up its data, and uninstalling THIS plugin last (or
// alone) removes the shared core data as usual.
if ( file_exists( WP_PLUGIN_DIR . '/alphabridge-mcp-pro/alphabridge-mcp-pro.php' ) ) {
	return;
}

$ab_mcp_options = array(
	'ab_mcp_options',
	'ab_mcp_tokens',
	'ab_mcp_tool_state',
	'ab_mcp_audit',
);

foreach ( $ab_mcp_options as $ab_mcp_option ) {
	delete_option( $ab_mcp_option );
}

// Multisite: clean per-site options too.
if ( is_multisite() ) {
	$ab_mcp_sites = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $ab_mcp_sites as $ab_mcp_site_id ) {
		switch_to_blog( $ab_mcp_site_id );
		foreach ( $ab_mcp_options as $ab_mcp_option ) {
			delete_option( $ab_mcp_option );
		}
		restore_current_blog();
	}
}
