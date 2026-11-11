<?php
/**
 * A plan of AB_MCP_IT_Child on one integration site, in a process of its own
 * (Z8b branch 1, points 4 and 5): the site's WordPress loaded as a request
 * to its home page, its administrator the current user, the admin includes
 * for plugins and files loaded; then seams, aborts at hooks and the calls.
 *
 *   php site-call.php <state.json> <a|b>     plan as JSON on stdin
 *
 * Unlike bin/sync-site.php (left as it is), every result is checked by the
 * parent (AB_MCP_IT_Process): a run counts only with its result line, an
 * abort only with its marker.
 *
 * @package AlphaBridge_MCP
 */

if ( 'cli' !== PHP_SAPI || 3 !== count( $argv ) ) {
	exit( 2 );
}

require dirname( __DIR__ ) . '/support/class-it-sites.php';
require dirname( __DIR__ ) . '/support/class-it-child.php';
// For a plan that hooks the stand-in for wordpress.org (AB_MCP_Test_Wporg::hook_all).
require dirname( __DIR__ ) . '/support/class-test-wporg.php';

putenv( 'AB_IT_STATE=' . $argv[1] );
$ab_it_key  = $argv[2];
$ab_it_plan = AB_MCP_IT_Child::plan();
$_SERVER    = array_merge( $_SERVER, AB_MCP_IT_Sites::server_vars( $ab_it_key ), array( 'DOCUMENT_ROOT' => AB_MCP_IT_Sites::docroot( $ab_it_key ) ) );
define( 'WP_USE_THEMES', false );
require AB_MCP_IT_Sites::docroot( $ab_it_key ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';

wp_set_current_user( (int) AB_MCP_IT_Sites::site( $ab_it_key )['admin']['user_id'] );

AB_MCP_IT_Child::prepare( $ab_it_plan );
AB_MCP_IT_Child::run( $ab_it_plan );
