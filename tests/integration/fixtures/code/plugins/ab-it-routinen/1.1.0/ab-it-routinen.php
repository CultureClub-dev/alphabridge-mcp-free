<?php
/**
 * Plugin Name: AB IT Routinen
 * Description: Activation, deactivation and uninstall routines with an effect on the data (F18): the activation makes a table, the deactivation writes an option, uninstall.php drops the table.
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
$GLOBALS['ab_it_fassung']['ab-it-routinen'] = '1.1.0';
if ( ! headers_sent() ) {
	header( 'X-AB-IT-Fassung-ab-it-routinen: 1.1.0' );
}

if ( ! function_exists( 'ab_it_routinen_zaehle' ) ) {
	/**
	 * Count a routine in the option ab_it_zaehler_ab-it-routinen.
	 *
	 * @param string $was aktiviert, deaktiviert or deinstalliert.
	 */
	function ab_it_routinen_zaehle( $was ) {
		$zaehler          = get_option( 'ab_it_zaehler_ab-it-routinen', array() );
		$zaehler          = is_array( $zaehler ) ? $zaehler : array();
		$zaehler[ $was ] = ( isset( $zaehler[ $was ] ) ? (int) $zaehler[ $was ] : 0 ) + 1;
		update_option( 'ab_it_zaehler_ab-it-routinen', $zaehler, false );
	}
}
register_activation_hook(
	__FILE__,
	static function () {
		global $wpdb;
		$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ab_it_routinen` ( id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, wert VARCHAR(32) NOT NULL )" );
		$wpdb->insert( $wpdb->prefix . 'ab_it_routinen', array( 'wert' => 'angelegt' ) );
		ab_it_routinen_zaehle( 'aktiviert' );
	}
);
register_deactivation_hook(
	__FILE__,
	static function () {
		update_option( 'ab_it_routinen_deaktiviert', 'ja', false );
		ab_it_routinen_zaehle( 'deaktiviert' );
	}
);
