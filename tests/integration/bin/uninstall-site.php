<?php
/**
 * Uninstall the plugin on one integration site as WordPress does
 * (uninstall_plugin(), which runs uninstall.php), in a process of its own:
 * the constant WP_UNINSTALL_PLUGIN and the removed options stay out of the
 * test process. For SyncSchemaTest.
 *
 *   php uninstall-site.php <state.json> <a|b>
 *
 * Prints JSON: what uninstall_plugin() returned. The site is set back by
 * AB_MCP_IT_Sites::reset() afterwards.
 *
 * @package AlphaBridge_MCP
 */

if ( 'cli' !== PHP_SAPI || 3 !== count( $argv ) ) {
	exit( 2 );
}

require dirname( __DIR__ ) . '/support/class-it-sites.php';

putenv( 'AB_IT_STATE=' . $argv[1] );
$ab_it_key = $argv[2];
$_SERVER   = array_merge( $_SERVER, AB_MCP_IT_Sites::server_vars( $ab_it_key ) );
define( 'WP_USE_THEMES', false );
require AB_MCP_IT_Sites::docroot( $ab_it_key ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

echo json_encode( array( 'uninstalled' => uninstall_plugin( AB_MCP_IT_Sites::PLUGIN ) ) );
