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

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $file ) {
		@unlink( $file );
	}
}
