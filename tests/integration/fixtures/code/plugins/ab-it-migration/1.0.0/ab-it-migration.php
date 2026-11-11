<?php
/**
 * Plugin Name: AB IT Migration
 * Description: A migration of its data, also without a new version number (E21, F34).
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
$GLOBALS['ab_it_fassung']['ab-it-migration'] = '1.0.0';
if ( ! headers_sent() ) {
	header( 'X-AB-IT-Fassung-ab-it-migration: 1.0.0' );
}

if ( ! function_exists( 'ab_it_migration_zaehle' ) ) {
	/**
	 * Count a routine in the option ab_it_zaehler_ab-it-migration (F48, F49, F51, F52).
	 *
	 * @param string $was aktiviert, deaktiviert or deinstalliert.
	 */
	function ab_it_migration_zaehle( $was ) {
		$zaehler          = get_option( 'ab_it_zaehler_ab-it-migration', array() );
		$zaehler          = is_array( $zaehler ) ? $zaehler : array();
		$zaehler[ $was ] = ( isset( $zaehler[ $was ] ) ? (int) $zaehler[ $was ] : 0 ) + 1;
		update_option( 'ab_it_zaehler_ab-it-migration', $zaehler, false );
	}
}
register_activation_hook(
	__FILE__,
	static function () {
		ab_it_migration_zaehle( 'aktiviert' );
	}
);
register_deactivation_hook(
	__FILE__,
	static function () {
		ab_it_migration_zaehle( 'deaktiviert' );
	}
);

// Schema 1 on the first run; this version understands schemas 1 and 2 and
// fails on a later one (F34: after the migration of 2.0.0-bruch the old
// version fails as well).
add_action(
	'init',
	static function () {
		$schema = (int) get_option( 'ab_it_migration_schema', 0 );
		if ( 0 === $schema ) {
			update_option( 'ab_it_migration_schema', 1, false );
			update_option( 'ab_it_migration_daten', 'v1', false );
		} elseif ( $schema > 2 ) {
			ab_it_migration_schema_unbekannt( $schema );
		}
	}
);
