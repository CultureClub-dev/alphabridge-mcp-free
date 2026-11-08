<?php
/**
 * What the unit tests of the kinds of the structure of a site (Z8 branch 11,
 * tests/SyncStructureWritersTest.php) need beyond the bootstrap and the
 * stubs of the run: sanitize_option(), the positions of menus, the theme
 * mods, the classes and addresses of menu items. Each as a test sets it.
 *
 * @package AlphaBridge_MCP
 */

if ( ! function_exists( 'sanitize_option' ) ) {
	/** As a test sets it ($ab_test_sanitize_option), else the value as it is. */
	function sanitize_option( $option, $value ) {
		$fn = $GLOBALS['ab_test_sanitize_option'] ?? null;
		return is_callable( $fn ) ? $fn( $option, $value ) : $value;
	}
}

if ( ! function_exists( 'get_registered_nav_menus' ) ) {
	/** The positions a test registers ($ab_test_nav_menus). */
	function get_registered_nav_menus() {
		return $GLOBALS['ab_test_nav_menus'] ?? array();
	}
}

if ( ! function_exists( 'set_theme_mod' ) ) {
	/** Recorded ($ab_test_theme_mods). */
	function set_theme_mod( $name, $value ) {
		$GLOBALS['ab_test_theme_mods'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_update_custom_css_post' ) ) {
	/** Recorded ($ab_test_css_writes); answers the post 7 of the CSS. */
	function wp_update_custom_css_post( $css, $args = array() ) {
		$GLOBALS['ab_test_css_writes'][] = array( (string) $css, (array) $args );
		return (object) array( 'ID' => 7 );
	}
}

if ( ! function_exists( 'sanitize_html_class' ) ) {
	/** As WordPress (wp-includes/formatting.php), without the filter. */
	function sanitize_html_class( $classname, $fallback = '' ) {
		$sanitized = preg_replace( '|%[a-fA-F0-9][a-fA-F0-9]|', '', (string) $classname );
		$sanitized = preg_replace( '/[^A-Za-z0-9_-]/', '', $sanitized );
		return '' === $sanitized && $fallback ? sanitize_html_class( $fallback ) : $sanitized;
	}
}

if ( ! function_exists( 'sanitize_url' ) ) {
	/** A scheme WordPress does not allow in menus comes back empty. */
	function sanitize_url( $url ) {
		return 0 === strpos( (string) $url, 'javascript:' ) ? '' : (string) $url;
	}
}
