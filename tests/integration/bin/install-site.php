<?php
/**
 * One step of the setup of one integration site, in a process of its own
 * (WordPress loads once per process).
 *
 *   php install-site.php <state.json> <a|b> install
 *       Writes wp-config.php, links the plugin of the working tree into
 *       wp-content/plugins, drops every table of the site's prefix and its
 *       kept copies, and installs WordPress (wp_install()).
 *   php install-site.php <state.json> <a|b> activate
 *       Loads the installed site, activates the plugin as WordPress does
 *       (activate_plugin(), with its activation hook), makes the
 *       administrator's credentials for the tests (an application password
 *       and a connection token of the plugin), writes them to the state and
 *       keeps a copy of every table for AB_MCP_IT_Sites::reset().
 *
 * Called by bin/setup.php and AB_MCP_IT_Sites::install().
 *
 * @package AlphaBridge_MCP
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require dirname( __DIR__ ) . '/support/class-it-sites.php';

if ( 4 !== count( $argv ) || ! in_array( $argv[3], array( 'install', 'activate' ), true ) ) {
	fwrite( STDERR, "Usage: php install-site.php <state.json> <a|b> <install|activate>\n" );
	exit( 2 );
}
putenv( 'AB_IT_STATE=' . $argv[1] );
$ab_it_key  = $argv[2];
$ab_it_step = $argv[3];
$ab_it_site = AB_MCP_IT_Sites::site( $ab_it_key );
$ab_it_repo = AB_MCP_IT_Sites::state()['repo'];

if ( 'install' === $ab_it_step ) {
	ab_it_write_config( $ab_it_key, $ab_it_site, AB_MCP_IT_Sites::state() );
	$ab_it_link = $ab_it_site['docroot'] . '/wp-content/plugins/alphabridge-mcp';
	if ( is_link( $ab_it_link ) ) {
		unlink( $ab_it_link );
	}
	if ( file_exists( $ab_it_link ) || ! symlink( $ab_it_repo, $ab_it_link ) ) {
		fwrite( STDERR, "Could not link the working tree to $ab_it_link.\n" );
		exit( 1 );
	}
}

$_SERVER = array_merge( $_SERVER, AB_MCP_IT_Sites::server_vars( $ab_it_key ) );
if ( 'install' === $ab_it_step ) {
	define( 'WP_INSTALLING', true );
}
define( 'WP_USE_THEMES', false );
require $ab_it_site['docroot'] . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( 'install' === $ab_it_step ) {
	global $wpdb;
	foreach ( array( AB_MCP_IT_Sites::SNAPSHOT . $ab_it_site['prefix'], $ab_it_site['prefix'] ) as $ab_it_prefix ) {
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $ab_it_prefix ) . '%' ) ) as $ab_it_table ) {
			$wpdb->query( "DROP TABLE `$ab_it_table`" );
		}
	}
	// wp_install() caches what it reads; nothing of a former install may stay.
	wp_cache_flush();
	$ab_it_password = wp_generate_password( 32, false );
	$ab_it_result   = wp_install( 'Integration site ' . strtoupper( $ab_it_key ), 'admin', 'admin@site-' . $ab_it_key . '.test', true, '', $ab_it_password );
	if ( empty( $ab_it_result['user_id'] ) ) {
		fwrite( STDERR, "wp_install() did not create the administrator of site $ab_it_key.\n" );
		exit( 1 );
	}
	$ab_it_state                                = AB_MCP_IT_Sites::state( true );
	$ab_it_state['sites'][ $ab_it_key ]['admin'] = array(
		'login'    => 'admin',
		'password' => $ab_it_password,
		'user_id'  => (int) $ab_it_result['user_id'],
	);
	AB_MCP_IT_Sites::save( $ab_it_state );
	echo "site $ab_it_key: WordPress " . get_bloginfo( 'version' ) . ' installed under ' . $ab_it_site['prefix'] . "\n";
	exit( 0 );
}

// activate
$ab_it_user = (int) AB_MCP_IT_Sites::site( $ab_it_key )['admin']['user_id'];
wp_set_current_user( $ab_it_user );
$ab_it_result = activate_plugin( AB_MCP_IT_Sites::PLUGIN );
if ( is_wp_error( $ab_it_result ) ) {
	fwrite( STDERR, 'The plugin did not activate on site ' . $ab_it_key . ': ' . $ab_it_result->get_error_message() . "\n" );
	exit( 1 );
}
if ( ! is_plugin_active( AB_MCP_IT_Sites::PLUGIN ) || ! defined( 'AB_MCP_DIR' ) || realpath( AB_MCP_DIR ) !== realpath( $ab_it_repo ) ) {
	fwrite( STDERR, "Site $ab_it_key does not run the plugin of the working tree $ab_it_repo.\n" );
	exit( 1 );
}
$ab_it_app = WP_Application_Passwords::create_new_application_password( $ab_it_user, array( 'name' => 'Integration tests' ) );
if ( is_wp_error( $ab_it_app ) ) {
	fwrite( STDERR, 'No application password on site ' . $ab_it_key . ': ' . $ab_it_app->get_error_message() . "\n" );
	exit( 1 );
}
$ab_it_state                                                = AB_MCP_IT_Sites::state( true );
$ab_it_state['sites'][ $ab_it_key ]['admin']['app_password'] = $ab_it_app[0];
$ab_it_state['sites'][ $ab_it_key ]['admin']['mcp_token']    = AB_MCP_Settings::add_token( $ab_it_user, 'Integration tests', 'full' );
AB_MCP_IT_Sites::save( $ab_it_state );
AB_MCP_IT_Sites::snapshot( $ab_it_key );
echo "site $ab_it_key: AlphaBridge MCP " . AB_MCP_VERSION . ' active from ' . realpath( AB_MCP_DIR ) . "\n";
exit( 0 );

/**
 * The wp-config.php of a site: the database of the state, the site's prefix
 * and address, debug output to the site's log, and three settings of the
 * test environment that the shipped plugin never sees as such: no mail
 * leaves (pre_wp_mail), application passwords work over http (the sites
 * have no TLS), and WordPress asks no host but the two sites
 * (WP_HTTP_BLOCK_EXTERNAL: update checks and the like stay local).
 *
 * @param string $key   Site.
 * @param array  $site  Its entry of the state.
 * @param array  $state The state.
 */
function ab_it_write_config( $key, array $site, array $state ) {
	$define = static function ( $name, $value ) {
		return 'define( ' . var_export( $name, true ) . ', ' . var_export( $value, true ) . " );\n";
	};
	$config  = "<?php\n// Written by tests/integration/bin/install-site.php for integration site " . strtoupper( $key ) . ". Rewritten by every setup.\n";
	$config .= $define( 'DB_NAME', $state['db']['name'] );
	$config .= $define( 'DB_USER', $state['db']['user'] );
	$config .= $define( 'DB_PASSWORD', $state['db']['password'] );
	$config .= $define( 'DB_HOST', $state['db']['host'] );
	$config .= $define( 'DB_CHARSET', 'utf8mb4' );
	$config .= $define( 'DB_COLLATE', '' );
	foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $salt ) {
		$config .= $define( $salt, bin2hex( random_bytes( 32 ) ) );
	}
	$config .= '$table_prefix = ' . var_export( $site['prefix'], true ) . ";\n";
	$config .= $define( 'WP_HOME', $site['url'] );
	$config .= $define( 'WP_SITEURL', $site['url'] );
	$config .= $define( 'WP_DEBUG', true );
	$config .= $define( 'WP_DEBUG_LOG', $site['debug_log'] );
	$config .= $define( 'WP_DEBUG_DISPLAY', false );
	$config .= $define( 'DISABLE_WP_CRON', true );
	$config .= $define( 'AUTOMATIC_UPDATER_DISABLED', true );
	$config .= $define( 'WP_HTTP_BLOCK_EXTERNAL', true );
	$config .= "// The test environment: hooks WordPress takes from here before any plugin loads.\n";
	$config .= "\$GLOBALS['wp_filter']['pre_wp_mail'][10]['ab_it_no_mail'] = array( 'function' => static function () { return true; }, 'accepted_args' => 1 );\n";
	$config .= "\$GLOBALS['wp_filter']['wp_is_application_passwords_available'][10]['ab_it_app_passwords'] = array( 'function' => '__return_true', 'accepted_args' => 1 );\n";
	$config .= "if ( ! defined( 'ABSPATH' ) ) {\n\tdefine( 'ABSPATH', __DIR__ . '/' );\n}\n";
	$config .= "require_once ABSPATH . 'wp-settings.php';\n";
	if ( false === file_put_contents( $site['docroot'] . '/wp-config.php', $config ) ) {
		fwrite( STDERR, "Could not write the wp-config.php of site $key.\n" );
		exit( 1 );
	}
	chmod( $site['docroot'] . '/wp-config.php', 0600 );
}
