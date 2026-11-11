<?php
/**
 * Plugin Name: AB IT Einzeln
 * Description: A plugin of a single file (F6).
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
$GLOBALS['ab_it_fassung']['ab-it-einzeln'] = '1.1.0';
if ( ! headers_sent() ) {
	header( 'X-AB-IT-Fassung-ab-it-einzeln: 1.1.0' );
}

if ( ! function_exists( 'ab_it_einzeln_zaehle' ) ) {
	/**
	 * Count a routine in the option ab_it_zaehler_ab-it-einzeln (F48, F49, F51, F52).
	 *
	 * @param string $was aktiviert, deaktiviert or deinstalliert.
	 */
	function ab_it_einzeln_zaehle( $was ) {
		$zaehler          = get_option( 'ab_it_zaehler_ab-it-einzeln', array() );
		$zaehler          = is_array( $zaehler ) ? $zaehler : array();
		$zaehler[ $was ] = ( isset( $zaehler[ $was ] ) ? (int) $zaehler[ $was ] : 0 ) + 1;
		update_option( 'ab_it_zaehler_ab-it-einzeln', $zaehler, false );
	}
}
register_activation_hook(
	__FILE__,
	static function () {
		ab_it_einzeln_zaehle( 'aktiviert' );
	}
);
register_deactivation_hook(
	__FILE__,
	static function () {
		ab_it_einzeln_zaehle( 'deaktiviert' );
	}
);
