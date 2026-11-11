<?php
/**
 * Bootstrap of the integration suite: WordPress 7.1.3 of site A, loaded
 * into the test process with its database and the plugin of the working
 * tree, and the test version of the partner fetcher.
 *
 * The unit suite (tests/bootstrap.php) runs against stand-ins without a
 * database and leaves this directory out (phpunit.xml.dist). Here nothing
 * is stood in for: the rows are MariaDB's, the hooks WordPress's, and site B
 * answers over HTTP from its own `php -S`. bin/setup.php and bin/serve.sh
 * prepare both sites; README.md says how, in CI and on a Mac.
 *
 * The only code these tests add to what the sites run is
 * AB_MCP_Test_Sync_Fetcher, required below and nowhere else (Entscheid 34
 * of the Z8 plan): the product code takes no filter or switch for it.
 * Z8b branch 1 adds, the same way: the stand-in for downloads.wordpress.org
 * (AB_MCP_Test_Wporg), the test seams (AB_MCP_Test_Seam), the hard abort in
 * a process of its own (AB_MCP_Test_Abort, AB_MCP_IT_Process) and the test
 * plugins and themes (AB_MCP_IT_Code), which the sites get as copies only
 * while a case asks for them.
 *
 * @package AlphaBridge_MCP
 */

// PHPUnit includes this file inside a method. WordPress declares most of
// its globals itself; these are the ones its own test suite declares
// before it loads, so that they are global here as well.
global $wpdb, $current_site, $current_blog, $wp_rewrite, $shortcode_tags, $wp, $phpmailer, $wp_theme_directories, $table_prefix;

require __DIR__ . '/support/class-it-sites.php';

try {
	AB_MCP_IT_Sites::state();
} catch ( RuntimeException $e ) {
	fwrite( STDERR, $e->getMessage() . "\n" );
	exit( 1 );
}

// No test plugin, theme or must-use plugin of a run that ended early stays
// on the sites: removed before WordPress loads (files only; the tables go
// back with AB_MCP_IT_Sites::reset()).
require_once __DIR__ . '/support/class-it-code.php';
AB_MCP_IT_Code::clear();

$_SERVER = array_merge( $_SERVER, AB_MCP_IT_Sites::server_vars( 'a' ) );
define( 'WP_USE_THEMES', false );
require AB_MCP_IT_Sites::docroot( 'a' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

// What these tests exercise must be the code of this working tree, active
// as WordPress activated it: a site that runs another copy would turn every
// result (and every mutation run) into a statement about that copy.
$ab_it_repo = realpath( dirname( __DIR__, 2 ) );
if ( ! is_plugin_active( AB_MCP_IT_Sites::PLUGIN ) || ! defined( 'AB_MCP_DIR' ) || realpath( AB_MCP_DIR ) !== $ab_it_repo ) {
	fwrite( STDERR, "Site A does not run the plugin of $ab_it_repo: run tests/integration/bin/setup.php from this working tree.\n" );
	exit( 1 );
}

require __DIR__ . '/support/class-test-sync-fetcher.php';
// Steps on a site in a process of its own, for the canonical form (Z8
// branch 4): each site computes it with its own WordPress.
require __DIR__ . '/support/class-it-sync-steps.php';
// Z8b branch 1: the stand-in for wordpress.org, the seams, the abort, the
// shell run of a manual way back (class-it-code.php is loaded above).
require_once __DIR__ . '/support/class-test-wporg.php';
require_once __DIR__ . '/support/class-it-process.php';
require_once __DIR__ . '/support/class-it-handweg.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-db-kinds.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-media.php';
