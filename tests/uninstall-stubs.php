<?php
/**
 * What uninstall.php needs beyond the bootstrap: the uploads folder of the
 * site, where the undo snapshots lie, and the call that deletes a file.
 *
 * @package AlphaBridge_MCP
 */

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir( $time = null, $create_dir = true, $refresh_cache = false ) {
		return array(
			'basedir' => $GLOBALS['ab_test_upload_basedir'] ?? sys_get_temp_dir() . '/ab-mcp-uploads-' . getmypid(),
			'baseurl' => 'https://example.test/wp-content/uploads',
			'error'   => false,
		);
	}
}

// Network options and WP-Cron events of the update tools (free since 4.11.0).
if ( ! function_exists( 'delete_site_option' ) ) {
	function delete_site_option( $name ) {
		unset( $GLOBALS['ab_test_site_options'][ $name ] );
		return true;
	}
}

if ( ! function_exists( 'update_site_option' ) ) {
	function update_site_option( $name, $value ) {
		$GLOBALS['ab_test_site_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook ) {
		$GLOBALS['ab_test_cleared_hooks'][] = array( (string) $hook, (int) ( $GLOBALS['ab_test_blog'] ?? 1 ) );
		return 1;
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $file ) {
		@unlink( $file );
	}
}
