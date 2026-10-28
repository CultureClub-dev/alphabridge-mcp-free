<?php
/**
 * wp-admin/includes/plugin.php for the tests: wp_list_plugins loads it with
 * require_once from ABSPATH, which is the tests folder. get_plugins() answers
 * what the test put in $GLOBALS['ab_test_plugins'].
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

if ( ! function_exists( 'get_plugins' ) ) {
	function get_plugins( $plugin_folder = '' ) {
		return $GLOBALS['ab_test_plugins'] ?? array();
	}
}
