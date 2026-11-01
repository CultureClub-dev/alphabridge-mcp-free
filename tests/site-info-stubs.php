<?php
/**
 * What wp_site_info reads of WordPress beyond the bootstrap: the theme, the
 * upload limit, HTTPS and the memory limit. Loaded only by a test that runs
 * in a process of its own, so no other test sees these stand-ins.
 *
 * @package AlphaBridge_MCP
 */

if ( ! function_exists( 'wp_get_theme' ) ) {
	function wp_get_theme() {
		return new class() {
			public function get( $key ) {
				return array(
					'Name'    => 'Twenty Twenty-Six',
					'Version' => '1.2',
				)[ $key ] ?? '';
			}
		};
	}
}

if ( ! function_exists( 'wp_max_upload_size' ) ) {
	function wp_max_upload_size() {
		return 8388608;
	}
}

if ( ! function_exists( 'is_ssl' ) ) {
	function is_ssl() {
		return true;
	}
}

if ( ! function_exists( 'size_format' ) ) {
	function size_format( $bytes, $decimals = 0 ) {
		return number_format( (float) $bytes, (int) $decimals ) . ' B';
	}
}

if ( ! defined( 'WP_MEMORY_LIMIT' ) ) {
	define( 'WP_MEMORY_LIMIT', '256M' );
}
