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
	'ab_mcp_oauth_clients',
	'ab_mcp_firewall',
	'ab_mcp_connection_check',
	'ab_mcp_review',
	'ab_mcp_review_dismissed',
	'ab_mcp_mode_history',
	// Undo (free since 4.9.0; the names Pro gave them): the index, its lock
	// and the window set on Pro's screen.
	'ab_mcp_pro_undo_index',
	'ab_mcp_pro_undo_lock',
	'ab_mcp_pro_undo_ttl_days',
);

// Remove the snapshot files of the undo points: the folder ab-mcp-undo in
// the uploads of the current site, with what is in it (the snapshots, its
// index.php and .htaccess). Nothing below it is a folder. A closure, not a
// function: this file can be read more than once in one request.
$ab_mcp_undo_files = static function () {
	$ab_mcp_up = wp_upload_dir( null, false );
	if ( ! empty( $ab_mcp_up['error'] ) || empty( $ab_mcp_up['basedir'] ) ) {
		return;
	}
	$ab_mcp_dir = rtrim( (string) $ab_mcp_up['basedir'], '/\\' ) . '/ab-mcp-undo';
	if ( ! is_dir( $ab_mcp_dir ) || is_link( $ab_mcp_dir ) ) {
		return;
	}
	foreach ( (array) glob( $ab_mcp_dir . '/{,.}*', GLOB_BRACE ) as $ab_mcp_file ) {
		if ( is_file( $ab_mcp_file ) || is_link( $ab_mcp_file ) ) {
			wp_delete_file( $ab_mcp_file );
		}
	}
	@rmdir( $ab_mcp_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- a folder left behind is harmless.
};

foreach ( $ab_mcp_options as $ab_mcp_option ) {
	delete_option( $ab_mcp_option );
}
$ab_mcp_undo_files();

// Multisite: clean per-site options too — on every site. get_sites() stops
// at 100 unless told otherwise, and 'number' => 0 is "no limit".
if ( is_multisite() ) {
	$ab_mcp_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $ab_mcp_sites as $ab_mcp_site_id ) {
		switch_to_blog( $ab_mcp_site_id );
		foreach ( $ab_mcp_options as $ab_mcp_option ) {
			delete_option( $ab_mcp_option );
		}
		$ab_mcp_undo_files();
		restore_current_blog();
	}
}
