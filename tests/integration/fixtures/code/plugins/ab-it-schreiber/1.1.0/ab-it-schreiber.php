<?php
/**
 * Plugin Name: AB IT Schreiber
 * Description: Writes a log into its own folder on every request (F46).
 * Version: 1.1.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: AlphaBridge integration tests
 * License: GPL-2.0-or-later
 *
 * Test plugin of the integration suite (Z8b branch 1, E21). Never shipped:
 * tests/integration/fixtures/code/ is copied into the test sites by
 * AB_MCP_IT_Code; katalog.php there says what each version is for.
 */

defined( 'ABSPATH' ) || exit;

// The mark of this version, set as it loads (F39): readable in this process
// and in the answer to every request of the site.
$GLOBALS['ab_it_fassung']['ab-it-schreiber'] = '1.1.0';
if ( ! headers_sent() ) {
	header( 'X-AB-IT-Fassung-ab-it-schreiber: 1.1.0' );
}

add_action(
	'init',
	static function () {
		if ( ! is_dir( __DIR__ . '/log' ) ) {
			mkdir( __DIR__ . '/log', 0755 );
		}
		file_put_contents( __DIR__ . '/log/zugriffe.log', gmdate( 'c' ) . ' ' . ( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '' ) . "\n", FILE_APPEND | LOCK_EX );
	}
);
