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
	// The update and activation tools (free since 4.11.0; the names Pro gave
	// them, as Pro's uninstall removes them): the update log, the versions
	// held back, the note on the update lock, the folders an update replaced
	// (network options on a network, removed below as well), and the lock
	// for changes of the code the site runs (on the main site).
	'ab_mcp_pro_update_log',
	'ab_mcp_pro_update_holds',
	'ab_mcp_pro_update_running',
	'ab_mcp_pro_update_replaced',
	'ab_mcp_pro_code_change.lock',
	// The abilities of other plugins an administrator approved (free since
	// 4.12.0, a name of its own: Pro keeps its list of switched-off ones).
	'ab_mcp_abilities_on',
	// «Staging first» (free since 4.15.0): the history of releases and the
	// state of the callers without a token (AB_MCP_Staging_Gate::OPTION);
	// the setting and the acknowledgements live in ab_mcp_options and
	// ab_mcp_tokens, removed above.
	'ab_mcp_staging_first',
);

// The WP-Cron event of the update tools (AB_MCP_Update_Log::RESUME), no
// option: once the plugin is gone nothing would take it off. Per site, as it
// is planned on the site where the hold was written.
$ab_mcp_cron = static function () {
	if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
		wp_clear_scheduled_hook( 'ab_mcp_pro_resume_auto_updates' );
	}
};

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

// A package AlphaBridge took off WordPress's list of automatic updates after
// a failed update (a hold with auto_off, AB_MCP_Update_Log) goes back on it
// before the holds go: with them and the event RESUME gone, nothing else would
// switch it on again (Codex, 30.10.2026). Only those: a package someone had
// switched off before has no auto_off. Network options on a network, plain
// options on a single site, as the holds and the lists are.
foreach ( (array) get_site_option( 'ab_mcp_pro_update_holds', array() ) as $ab_mcp_key => $ab_mcp_hold ) {
	if ( ! is_array( $ab_mcp_hold ) || empty( $ab_mcp_hold['auto_off'] ) || ! preg_match( '/^(plugin|theme):(.+)\z/s', (string) $ab_mcp_key, $ab_mcp_m ) ) {
		continue;
	}
	$ab_mcp_list = (array) get_site_option( 'auto_update_' . $ab_mcp_m[1] . 's', array() );
	if ( ! in_array( $ab_mcp_m[2], $ab_mcp_list, true ) ) {
		$ab_mcp_list[] = $ab_mcp_m[2];
		update_site_option( 'auto_update_' . $ab_mcp_m[1] . 's', array_values( $ab_mcp_list ) );
	}
}

// The preview protection (free since 4.15.0) set blog_public to 0 and kept
// the earlier value in ab_mcp_options; once that option is gone nothing
// would write it back, and the site would stay «discouraged» for search
// engines without a visible reason. Deactivation writes it back too and
// notes it (restored, AB_MCP_Preview_Protection::on_deactivate()); with the
// note the value standing now was chosen after, and stays. This is for an
// uninstall whose deactivation hook never ran. Per site, as the option is.
$ab_mcp_preview_blog_public = static function () {
	$ab_mcp_opts = get_option( 'ab_mcp_options', array() );
	$ab_mcp_pp   = ( is_array( $ab_mcp_opts ) && isset( $ab_mcp_opts['preview_protection'] ) && is_array( $ab_mcp_opts['preview_protection'] ) ) ? $ab_mcp_opts['preview_protection'] : array();
	if ( isset( $ab_mcp_pp['on'], $ab_mcp_pp['hash'] ) && true === $ab_mcp_pp['on'] && '' !== (string) $ab_mcp_pp['hash'] && empty( $ab_mcp_pp['restored'] ) && array_key_exists( 'blog_public', $ab_mcp_pp ) && is_scalar( $ab_mcp_pp['blog_public'] ) ) {
		update_option( 'blog_public', (string) $ab_mcp_pp['blog_public'] );
	}
};

// The must-use loader the preview protection keeps for the requests
// WordPress serves without plugins (AB_MCP_Preview_Protection::write_loader()):
// one file for the install, so once, before the options go.
if ( defined( 'WPMU_PLUGIN_DIR' ) && is_file( WPMU_PLUGIN_DIR . '/alphabridge-preview-protection.php' ) ) {
	wp_delete_file( WPMU_PLUGIN_DIR . '/alphabridge-preview-protection.php' );
}

$ab_mcp_preview_blog_public();
foreach ( $ab_mcp_options as $ab_mcp_option ) {
	delete_option( $ab_mcp_option );
}
$ab_mcp_undo_files();
$ab_mcp_cron();

// Whether a user folded the card «Staging» on the settings page
// (AB_MCP_Admin::STAGING_CARD_FOLDED, free since 4.15.0): user meta, of every
// user. User meta belongs to the whole network on a network, so once.
delete_metadata( 'user', 0, 'ab_mcp_staging_card_folded', '', true );

// The update log, the holds, the note on the update lock and the replaced
// folders are network options on a network (on a single site plain options,
// removed above), as Pro's uninstall removes them.
if ( function_exists( 'delete_site_option' ) ) {
	foreach ( array( 'ab_mcp_pro_update_log', 'ab_mcp_pro_update_holds', 'ab_mcp_pro_update_running', 'ab_mcp_pro_update_replaced' ) as $ab_mcp_option ) {
		delete_site_option( $ab_mcp_option );
	}
}

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
		$ab_mcp_preview_blog_public();
		foreach ( $ab_mcp_options as $ab_mcp_option ) {
			delete_option( $ab_mcp_option );
		}
		$ab_mcp_undo_files();
		$ab_mcp_cron();
		restore_current_blog();
	}
}
