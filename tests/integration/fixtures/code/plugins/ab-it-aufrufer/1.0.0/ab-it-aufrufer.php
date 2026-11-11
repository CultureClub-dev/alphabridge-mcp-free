<?php
/**
 * Plugin Name: AB IT Aufrufer
 * Description: Calls a function of ab-it-dienst on the home page and fails there without it (F48). Inactive at the start: activated only where a case wants it.
 * Version: 1.0.0
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
$GLOBALS['ab_it_fassung']['ab-it-aufrufer'] = '1.0.0';
if ( ! headers_sent() ) {
	header( 'X-AB-IT-Fassung-ab-it-aufrufer: 1.0.0' );
}

if ( ! function_exists( 'ab_it_aufrufer_zaehle' ) ) {
	/**
	 * Count a routine in the option ab_it_zaehler_ab-it-aufrufer (F48, F49, F51, F52).
	 *
	 * @param string $was aktiviert, deaktiviert or deinstalliert.
	 */
	function ab_it_aufrufer_zaehle( $was ) {
		$zaehler          = get_option( 'ab_it_zaehler_ab-it-aufrufer', array() );
		$zaehler          = is_array( $zaehler ) ? $zaehler : array();
		$zaehler[ $was ] = ( isset( $zaehler[ $was ] ) ? (int) $zaehler[ $was ] : 0 ) + 1;
		update_option( 'ab_it_zaehler_ab-it-aufrufer', $zaehler, false );
	}
}
register_activation_hook(
	__FILE__,
	static function () {
		ab_it_aufrufer_zaehle( 'aktiviert' );
	}
);
register_deactivation_hook(
	__FILE__,
	static function () {
		ab_it_aufrufer_zaehle( 'deaktiviert' );
	}
);

add_action(
	'wp_head',
	static function () {
		if ( is_front_page() || is_home() ) {
			// No function_exists(): without ab-it-dienst the home page fails
			// here, as a plugin that relies on another one fails.
			echo '<meta name="ab-it-gruss" content="' . esc_attr( ab_it_dienst_gruss() ) . '">' . "\n";
		}
	}
);
