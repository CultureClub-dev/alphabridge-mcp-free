<?php
/**
 * The routines of other plugins and themes that AlphaBridge runs find the
 * files of wp-admin loaded, as in wp-admin (AB_MCP_Admin_Files): the
 * activation, the deactivation, the uninstall of a delete, the switch_theme
 * callback of the theme left, and the hooks on the upgrader's runs.
 *
 * Measured 31.10.2026 on video.alphabridge-mcp.com (WordPress 7.1.4):
 * wp_deactivate_plugin on WP Super Cache ended with "The tool failed with an
 * internal error" and left the plugin active. Its deactivation calls
 * get_home_path() (wp-admin/includes/file.php) and insert_with_markers()
 * (misc.php), which a REST request does not load. The stub plugin here does
 * the same in its activation, deactivation and uninstall routine and in its
 * callback on upgrader_process_complete: it writes its block in .htaccess
 * through these two, without asking whether they are there.
 *
 * Runs the REAL tools and guards against tests/standalone-lib/support/
 * update-harness.php, with the test requests switched off (the guards then
 * activate and update "not checked"). Each scenario runs in a process of its
 * own (LifecycleStandaloneTest), so one path's loading cannot stand in for
 * another's: the two functions exist only in the stand-ins of file.php and
 * misc.php under ABSPATH, nothing loads them before the call, and each
 * scenario checks that first. Every stand-in notes that it was loaded.
 *
 * Proves:
 *  - list: load() loads exactly the files of its list, in admin.php's order,
 *    ms.php and ms-deprecated.php on a network as well, never admin.php,
 *    admin-filters.php or ms-admin-filters.php; it adds no hook, prints
 *    nothing, and skips a file that is not there;
 *  - deactivate: wp_deactivate_plugin runs the deactivation routine to its
 *    end and the plugin is off (the case of 31.10.2026);
 *  - activate: wp_activate_plugin runs the activation routine and the plugin
 *    is on;
 *  - network: on a network, a plugin active network-wide that
 *    wp_activate_plugin puts on this site's list runs its activation routine
 *    there too;
 *  - delete: wp_delete_plugin runs the deactivation and uninstall.php, and
 *    the folder is gone;
 *  - theme: a theme switch handed to the guard as switch_to() hands it runs
 *    the switch_theme callback of the theme left, and the answer names no
 *    error of it (without the files the switch stayed, with the error as a
 *    warning);
 *  - update and install: the callback on upgrader_process_complete runs.
 *
 * Mutation probe (31.10.2026): without AB_MCP_Admin_Files::load() in
 * switch_on(), activate and theme fail; in plugin_locked(), network; in
 * deactivate_plugin(), deactivate; in boot_upgrader(), delete, update and
 * install.
 *
 * Run:  php tests/standalone-lifecycle/admin-files-test.php list
 *       (and deactivate, activate, network, delete, theme, update, install)
 */

$ab_scenario = isset( $argv[1] ) ? (string) $argv[1] : '';
if ( 'network' === $ab_scenario ) {
	$GLOBALS['ab_multisite'] = true;
}

require __DIR__ . '/../standalone-lib/support/update-harness.php';
require_once $GLOBALS['ab_free'] . 'tools/class-tools-plugins-themes-manage.php';

/* ---------------------------------------------- wp-admin/includes/, stand-ins */

/* Every file the list names, and those it leaves out. Each notes that it was
 * loaded; file.php and misc.php hold the two functions of the routines. */
$ab_inc    = ABSPATH . 'wp-admin/includes/';
$ab_others = array( 'admin.php', 'admin-filters.php', 'ms-admin-filters.php', 'plugin-install.php', 'theme-install.php', 'class-wp-upgrader.php', 'class-automatic-upgrader-skin.php' );
$GLOBALS['ab_admin_loaded'] = array();
foreach ( array_merge( AB_MCP_Admin_Files::FILES, AB_MCP_Admin_Files::NETWORK_FILES, $ab_others ) as $ab_name ) {
	$ab_src = "<?php\n\$GLOBALS['ab_admin_loaded'][] = '$ab_name';\n";
	if ( 'file.php' === $ab_name ) {
		// Core's computes it from home and siteurl.
		$ab_src .= "function get_home_path() { return ABSPATH; }\n";
	}
	if ( 'misc.php' === $ab_name ) {
		// Core's keeps the rest of the file; the block alone is enough here.
		$ab_src .= <<<'PHP'
function insert_with_markers( $filename, $marker, $insertion ) {
	$block = "# BEGIN $marker\n";
	foreach ( (array) $insertion as $line ) { $block .= $line . "\n"; }
	return false !== file_put_contents( $filename, $block . "# END $marker\n" );
}

PHP;
	}
	if ( 'admin.php' === $ab_name ) {
		// As core's: its hooks, then the files of the list.
		$ab_src .= "require_once ABSPATH . 'wp-admin/includes/admin-filters.php';\n";
	}
	if ( 'admin-filters.php' === $ab_name || 'ms-admin-filters.php' === $ab_name ) {
		$ab_src .= "add_action( 'upgrader_process_complete', 'wp_update_plugins', 10, 0 );\n";
	}
	file_put_contents( $ab_inc . $ab_name, $ab_src );
}
unset( $ab_name, $ab_src );

/** The files loaded so far, in order. */
function ab_loaded() { return $GLOBALS['ab_admin_loaded']; }

/** After a call: every file of the list, never admin.php or a file of hooks. */
function ab_check_loaded( $label ) {
	$loaded = ab_loaded();
	ok( array() === array_values( array_diff( AB_MCP_Admin_Files::FILES, $loaded ) ), "$label every file of the list was loaded: missing " . implode( ', ', array_diff( AB_MCP_Admin_Files::FILES, $loaded ) ) );
	ok( array() === array_values( array_intersect( array( 'admin.php', 'admin-filters.php', 'ms-admin-filters.php' ), $loaded ) ), "$label neither admin.php nor its hooks" );
}

/* -------------------------------------------- what core does around it */

function validate_plugin( $p ) {
	return ( '' !== (string) $p && is_file( WP_PLUGIN_DIR . '/' . $p ) ) ? 0 : new WP_Error( 'plugin_not_found', 'Plugin file does not exist.' );
}
function validate_plugin_requirements( $p ) { return true; }
/* Core's is_network_only_plugin(): the "Network" header, true only as "true". */
function is_network_only_plugin( $plugin ) {
	$h = get_file_data( WP_PLUGIN_DIR . '/' . $plugin, array( 'Network' => 'Network' ) );
	return 'true' === strtolower( $h['Network'] );
}
function register_activation_hook( $file, $cb ) { add_action( 'activate_' . plugin_basename( $file ), $cb ); }
function register_deactivation_hook( $file, $cb ) { add_action( 'deactivate_' . plugin_basename( $file ), $cb ); }
/* Core's activate_plugin() (wp-admin/includes/plugin.php, 7.1.3), on one
 * site: the main file loaded in this request (plugin_sandbox_scrape()), the
 * activation hook, then the list. */
function activate_plugin( $plugin, $redirect = '', $network_wide = false, $silent = false ) {
	$current = (array) get_option( 'active_plugins', array() );
	if ( in_array( $plugin, $current, true ) ) {
		return null;
	}
	ob_start();
	include_once WP_PLUGIN_DIR . '/' . $plugin;
	if ( ! $silent ) {
		do_action( 'activate_plugin', $plugin, $network_wide );
		do_action( "activate_{$plugin}", $network_wide );
	}
	$current[] = $plugin;
	sort( $current );
	update_option( 'active_plugins', $current );
	if ( ob_get_length() > 0 ) {
		$out = ob_get_clean();
		return new WP_Error( 'unexpected_output', 'The plugin generated unexpected output.', $out );
	}
	ob_end_clean();
	return null;
}
/* Core's deactivate_plugins() (7.1.3), on one site: the deactivation hook
 * runs before the list is written, so a fatal error there leaves the plugin
 * active. */
function deactivate_plugins( $plugins, $silent = false, $network_wide = null ) {
	$current = (array) get_option( 'active_plugins', array() );
	$do_blog = false;
	foreach ( (array) $plugins as $plugin ) {
		if ( ! is_plugin_active( $plugin ) ) {
			continue;
		}
		if ( ! $silent ) {
			do_action( 'deactivate_plugin', $plugin, false );
		}
		$key = array_search( $plugin, $current, true );
		if ( false !== $key ) {
			$do_blog = true;
			unset( $current[ $key ] );
		}
		if ( ! $silent ) {
			do_action( "deactivate_{$plugin}", false );
			do_action( 'deactivated_plugin', $plugin, false );
		}
	}
	if ( $do_blog ) {
		update_option( 'active_plugins', array_values( $current ) );
	}
}
/* Core's delete_plugins() (7.1.3): per plugin its uninstall.php, through
 * uninstall_plugin() with WP_UNINSTALL_PLUGIN, then its folder. */
function delete_plugins( $plugins, $deprecated = '' ) {
	foreach ( (array) $plugins as $plugin_file ) {
		$uninstall = WP_PLUGIN_DIR . '/' . dirname( $plugin_file ) . '/uninstall.php';
		if ( file_exists( $uninstall ) ) {
			define( 'WP_UNINSTALL_PLUGIN', $plugin_file );
			include_once $uninstall;
		}
		ab_rm_any( WP_PLUGIN_DIR . '/' . dirname( $plugin_file ) );
	}
	return true;
}
/* Core's switch_theme() as far as it goes here: the options, then the
 * switch_theme action, which the theme left still has hooked. */
function switch_theme( $stylesheet ) {
	$old = get_stylesheet();
	update_option( 'template', $stylesheet );
	update_option( 'stylesheet', $stylesheet );
	update_option( 'theme_switched', $old );
	do_action( 'switch_theme', $stylesheet, null, null );
}
function plugins_api( $action, $args ) {
	return (object) array( 'download_link' => 'https://downloads.wordpress.org/plugin/fresh.1.0.0.zip', 'version' => '1.0.0' );
}

/* ------------------------------------------------------- the stub plugin */

/* As WP Super Cache (wpsupercache_activate(), wpsupercache_deactivate() →
 * wp_cache_disable_plugin() → wpsc_get_htaccess_info()): its routines write
 * its block in .htaccess through get_home_path() and insert_with_markers(),
 * with no function_exists() before; each notes itself once it is through. */
const AB_CACHE_SRC = <<<'PHP'
<?php
/**
 * Plugin Name: Test cache
 * Version: 1.0.0
 */
register_activation_hook( __FILE__, static function () {
	insert_with_markers( get_home_path() . '.htaccess', 'Test cache', array( 'RewriteEngine On' ) );
	$GLOBALS['ab_routines'][] = 'activate';
} );
register_deactivation_hook( __FILE__, static function () {
	insert_with_markers( get_home_path() . '.htaccess', 'Test cache', array() );
	$GLOBALS['ab_routines'][] = 'deactivate';
} );
// After every install and update its rules are written again.
add_action( 'upgrader_process_complete', static function () {
	insert_with_markers( get_home_path() . '.htaccess', 'Test cache', array( 'RewriteEngine On' ) );
	$GLOBALS['ab_routines'][] = 'upgrader_process_complete';
} );

PHP;
const AB_CACHE_UNINSTALL = <<<'PHP'
<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
insert_with_markers( get_home_path() . '.htaccess', 'Test cache', array() );
$GLOBALS['ab_routines'][] = 'uninstall';

PHP;

/**
 * The stub plugin installed; active, it is loaded as WordPress loads the
 * active plugins at the start of a request, and its block is in .htaccess.
 */
function ab_cache_plugin( $active ) {
	@mkdir( WP_PLUGIN_DIR . '/cache', 0777, true );
	file_put_contents( WP_PLUGIN_DIR . '/cache/cache.php', AB_CACHE_SRC );
	file_put_contents( WP_PLUGIN_DIR . '/cache/uninstall.php', AB_CACHE_UNINSTALL );
	@unlink( ABSPATH . '.htaccess' );
	if ( $active ) {
		file_put_contents( ABSPATH . '.htaccess', "# BEGIN Test cache\nRewriteEngine On\n# END Test cache\n" );
		update_option( 'active_plugins', array_values( array_unique( array_merge( (array) get_option( 'active_plugins', array() ), array( 'cache/cache.php' ) ) ) ) );
		include_once WP_PLUGIN_DIR . '/cache/cache.php';
	}
}

/** The lines of its block in .htaccess, or null where there is none. */
function ab_block() {
	$src = is_file( ABSPATH . '.htaccess' ) ? (string) file_get_contents( ABSPATH . '.htaccess' ) : '';
	if ( 1 !== preg_match( "/^# BEGIN Test cache\n(.*)# END Test cache\n/ms", $src, $m ) ) {
		return null;
	}
	return '' === $m[1] ? array() : explode( "\n", rtrim( $m[1], "\n" ) );
}

/** A call, and what it threw: the REST layer turns that into "internal error". */
function ab_call( callable $fn ) {
	try {
		return $fn();
	} catch ( \Throwable $e ) {
		return $e;
	}
}
function ab_why( $r ) {
	if ( $r instanceof \Throwable ) {
		return get_class( $r ) . ': ' . $r->getMessage();
	}
	return is_wp_error( $r ) ? ab_code( $r ) . ': ' . ab_msg( $r ) : 'no error';
}

/* --------------------------------------------------------------- the runs */

ab_reset();
// The test requests off: the guards activate and update without them.
$GLOBALS['ab_filters']['ab_mcp_php_probe_enabled'] = false;
$GLOBALS['ab_routines']                          = array();

// Nothing knows the two functions before the call does, and no file of
// wp-admin is loaded yet: otherwise the checks below would prove nothing.
ok( ! function_exists( 'get_home_path' ) && ! function_exists( 'insert_with_markers' ), 'B0 get_home_path() and insert_with_markers() are not there before the call' );
ok( array() === ab_loaded(), 'B0 no file of wp-admin is loaded before the call: ' . implode( ', ', ab_loaded() ) );

switch ( $ab_scenario ) {
	case 'list':
		ok( array() === array_values( array_diff( array( 'file.php', 'misc.php', 'plugin.php', 'theme.php', 'template.php' ), AB_MCP_Admin_Files::FILES ) ), 'L0 the list has file.php, misc.php, plugin.php, theme.php and template.php' );
		ok( count( AB_MCP_Admin_Files::FILES ) === count( array_unique( AB_MCP_Admin_Files::FILES ) ), 'L0 no file twice' );
		// A file this WordPress does not have is skipped, without a warning.
		unlink( $ab_inc . 'deprecated.php' );
		$hooks = $GLOBALS['ab_hooks'];
		ob_start();
		AB_MCP_Admin_Files::load();
		$out = ob_get_clean();
		ok( array_values( array_diff( AB_MCP_Admin_Files::FILES, array( 'deprecated.php' ) ) ) === ab_loaded(), 'L1 one site: exactly the files of the list, in its order, without the one that is not there: ' . implode( ', ', ab_loaded() ) );
		ok( '' === $out, 'L1 nothing printed' );
		ok( $hooks === $GLOBALS['ab_hooks'], 'L1 no hook added' );
		ok( function_exists( 'get_home_path' ) && function_exists( 'insert_with_markers' ), 'L1 the two functions are there afterwards' );
		file_put_contents( $ab_inc . 'deprecated.php', "<?php\n\$GLOBALS['ab_admin_loaded'][] = 'deprecated.php';\n" );
		AB_MCP_Admin_Files::load();
		ok( AB_MCP_Admin_Files::FILES === ab_loaded(), 'L2 called again: the file that is there now, nothing twice' );
		$GLOBALS['ab_multisite'] = true;
		AB_MCP_Admin_Files::load();
		$GLOBALS['ab_multisite'] = false;
		ok( array_merge( AB_MCP_Admin_Files::FILES, AB_MCP_Admin_Files::NETWORK_FILES ) === ab_loaded(), 'L3 on a network ms.php and ms-deprecated.php as well' );
		ab_check_loaded( 'L3' );
		ok( $hooks === $GLOBALS['ab_hooks'], 'L3 still no hook added' );
		break;

	case 'deactivate':
		ab_cache_plugin( true );
		$r = ab_call( static function () { return AB_MCP_Tools_Plugins_Themes_Manage::deactivate_plugin( array( 'plugin' => 'cache/cache.php' ) ); } );
		ok( is_array( $r ) && true === $r['deactivated'] && true === $r['was_active'], 'D1 deactivated, without an error: ' . ab_why( $r ) );
		ok( ! is_plugin_active( 'cache/cache.php' ), 'D1 the plugin is off (on 31.10.2026 it stayed active)' );
		ok( array( 'deactivate' ) === $GLOBALS['ab_routines'], 'D1 its deactivation routine ran to its end' );
		ok( array() === ab_block(), 'D1 its block in .htaccess is empty' );
		ab_check_loaded( 'D1' );
		break;

	case 'activate':
		ab_cache_plugin( false );
		$r = ab_call( static function () { return AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => 'cache/cache.php' ) ); } );
		ok( is_array( $r ) && true === $r['activated'], 'A1 activated, without an error: ' . ab_why( $r ) );
		ok( is_plugin_active( 'cache/cache.php' ), 'A1 the plugin is on' );
		ok( array( 'activate' ) === $GLOBALS['ab_routines'], 'A1 its activation routine ran to its end' );
		ok( array( 'RewriteEngine On' ) === ab_block(), 'A1 its block in .htaccess is written' );
		ok( is_array( $r ) && isset( $r['warning'] ) && false !== strpos( $r['warning'], 'ab_mcp_php_probe_enabled' ), 'A1 through the guard, "not checked" as the test requests are off' );
		ab_check_loaded( 'A1' );
		break;

	case 'network':
		// Active network-wide, so loaded with the request, not on this
		// site's list: activate_plugin() puts it there and runs its
		// activation hook for this site.
		ab_cache_plugin( false );
		update_site_option( 'active_sitewide_plugins', array( 'cache/cache.php' => time() ) );
		include_once WP_PLUGIN_DIR . '/cache/cache.php';
		$r = ab_call( static function () { return AB_MCP_Tools_Plugins_Themes_Manage::activate_plugin( array( 'plugin' => 'cache/cache.php' ) ); } );
		ok( is_array( $r ) && true === $r['activated'] && true === $r['already_active'], 'N1 activated, as already active, without an error: ' . ab_why( $r ) );
		ok( in_array( 'cache/cache.php', (array) get_option( 'active_plugins', array() ), true ), 'N1 on this site\'s list now' );
		ok( array( 'activate' ) === $GLOBALS['ab_routines'], 'N1 its activation routine ran to its end' );
		ab_check_loaded( 'N1' );
		ok( array() === array_values( array_diff( AB_MCP_Admin_Files::NETWORK_FILES, ab_loaded() ) ), 'N1 and on a network ms.php and ms-deprecated.php' );
		break;

	case 'delete':
		ab_cache_plugin( true );
		$r = ab_call( static function () { return AB_MCP_Tools_Install_Update::delete_plugin( array( 'plugin' => 'cache/cache.php' ) ); } );
		ok( is_array( $r ) && true === $r['deleted'], 'U1 deleted, without an error: ' . ab_why( $r ) );
		ok( array( 'deactivate', 'uninstall' ) === $GLOBALS['ab_routines'], 'U1 its deactivation, then its uninstall.php ran to their end: ' . implode( ', ', $GLOBALS['ab_routines'] ) );
		ok( ! is_dir( WP_PLUGIN_DIR . '/cache' ) && ! is_plugin_active( 'cache/cache.php' ), 'U1 the plugin is off and its folder gone' );
		ok( array() === ab_block(), 'U1 its block in .htaccess is empty' );
		ab_check_loaded( 'U1' );
		break;

	case 'theme':
		// The theme left takes its rules out of .htaccess (its functions.php,
		// loaded in this request, hooked switch_theme).
		file_put_contents( ABSPATH . '.htaccess', "# BEGIN Test cache\nRewriteEngine On\n# END Test cache\n" );
		add_action(
			'switch_theme',
			static function () {
				insert_with_markers( get_home_path() . '.htaccess', 'Test cache', array() );
				$GLOBALS['ab_routines'][] = 'switch_theme';
			}
		);
		// The job as switch_to() builds it, in short: switch_theme(), and
		// stylesheet and template as the options hold them.
		$r = ab_call(
			static function () {
				return AB_MCP_Activation_Guard::activate(
					array(
						'type'      => 'theme',
						'item'      => 'shiny',
						'template'  => 'shiny',
						'previous'  => 'calm',
						'activate'  => static function () {
							switch_theme( 'shiny' );
							return 'shiny' === get_stylesheet() ? true : new WP_Error( 'ab_mcp_switch_failed', 'not switched' );
						},
						'is_on'     => static function () {
							return 'shiny' === get_stylesheet() && 'shiny' === get_template();
						},
						'changed'   => static function () {
							return 'calm' !== get_stylesheet() || 'calm' !== get_template();
						},
						'take_back' => static function () {
							update_option( 'stylesheet', 'calm' );
							update_option( 'template', 'calm' );
							return true;
						},
					)
				);
			}
		);
		ok( is_array( $r ) && ! is_wp_error( $r ), 'T1 switched, without an error: ' . ab_why( $r ) );
		ok( is_array( $r ) && false === strpos( (string) ( $r['warning'] ?? '' ), 'undefined function' ), 'T1 the answer names no error of the theme\'s code: ' . ( is_array( $r ) ? (string) ( $r['warning'] ?? '' ) : '' ) );
		ok( 'shiny' === get_stylesheet() && 'shiny' === get_template(), 'T1 the new theme is active' );
		ok( array( 'switch_theme' ) === $GLOBALS['ab_routines'], 'T1 the switch_theme callback of the theme left ran to its end' );
		ok( array() === ab_block(), 'T1 its block in .htaccess is empty' );
		ab_check_loaded( 'T1' );
		break;

	case 'update':
		ab_cache_plugin( true );
		$f = ab_install_plugin( 'other', '1.0.0' );
		ab_offer( 'plugin', $f, '1.0.0', '2.0.0', ab_package_plugin( 'other', '2.0.0' ) );
		$r = ab_call( static function () use ( $f ) { return AB_MCP_Tools_Install_Update::update_plugin( array( 'plugin' => $f ) ); } );
		ok( is_array( $r ) && true === $r['updated'], 'P1 updated, without an error: ' . ab_why( $r ) );
		ok( '2.0.0' === ab_installed_version( 'plugin', $f ), 'P1 2.0.0 is on disk' );
		ok( array( 'upgrader_process_complete' ) === $GLOBALS['ab_routines'], 'P1 the callback of the cache plugin on upgrader_process_complete ran to its end' );
		ab_check_loaded( 'P1' );
		break;

	case 'install':
		ab_cache_plugin( true );
		$GLOBALS['ab_install'] = static function ( $upgrader, $package ) {
			mkdir( WP_PLUGIN_DIR . '/fresh', 0777, true );
			file_put_contents( WP_PLUGIN_DIR . '/fresh/fresh.php', ab_plugin_src( 'fresh', '1.0.0' ) );
			// Core runs it at the end of every install (WP_Upgrader::run()).
			do_action( 'upgrader_process_complete', $upgrader, array( 'action' => 'install', 'type' => 'plugin' ) );
			return true;
		};
		$r = ab_call( static function () { return AB_MCP_Tools_Install_Update::install_plugin( array( 'slug' => 'fresh' ) ); } );
		ok( is_array( $r ) && true === $r['installed'], 'I1 installed, without an error: ' . ab_why( $r ) );
		ok( array( 'upgrader_process_complete' ) === $GLOBALS['ab_routines'], 'I1 the callback of the cache plugin on upgrader_process_complete ran to its end' );
		ab_check_loaded( 'I1' );
		break;

	default:
		echo "Unknown scenario '$ab_scenario': list, deactivate, activate, network, delete, theme, update or install.\n";
		exit( 2 );
}

ab_finish( 'ADMIN-FILES ' . strtoupper( $ab_scenario ) );
