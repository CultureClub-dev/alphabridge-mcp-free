<?php
/**
 * functions.php of ab-it-eltern, version 1.1.0.
 *
 * @package AlphaBridge_MCP
 */

$GLOBALS['ab_it_fassung']['ab-it-eltern'] = '1.1.0';
if ( ! headers_sent() ) {
	header( 'X-AB-IT-Fassung-ab-it-eltern: 1.1.0' );
}

add_action(
	'after_setup_theme',
	static function () {
		register_nav_menus( array( 'haupt' => 'Hauptmenü' ) );
	}
);
