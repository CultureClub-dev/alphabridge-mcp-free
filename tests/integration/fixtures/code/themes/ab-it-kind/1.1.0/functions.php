<?php
/**
 * functions.php of ab-it-kind, version 1.1.0.
 *
 * @package AlphaBridge_MCP
 */

$GLOBALS['ab_it_fassung']['ab-it-kind'] = '1.1.0';
if ( ! headers_sent() ) {
	header( 'X-AB-IT-Fassung-ab-it-kind: 1.1.0' );
}

// New in 1.1.0 (F9): the child adds a class to the body.
add_filter(
	'body_class',
	static function ( $classes ) {
		$classes[] = 'ab-it-kind-neu';
		return $classes;
	}
);
