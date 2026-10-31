<?php
/**
 * The must-use loader of the preview protection, run as wp-activate.php
 * runs it: WordPress under WP_INSTALLING, no plugin loaded, the classes of
 * this plugin not there. Started by PreviewProtectionTest in a process of
 * its own, since the classes are loaded in the test process from the start.
 *
 * Arguments: the loader file, then the mode: «fresh» (nothing loaded: the
 * loader has to load the classes and hook the gates), «off» (the
 * protection off: nothing) or «gone» (a loader whose plugin folder is gone,
 * the plugin deleted over FTP: nothing, and no fatal error).
 *
 * Prints one line per check, «LOADER-CHILD ok» at the end.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

error_reporting( E_ALL );

$ab_loader = (string) ( $argv[1] ?? '' );
$ab_mode   = (string) ( $argv[2] ?? 'fresh' );

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['ab_hooks']   = array();
$GLOBALS['ab_options'] = array(
	'active_plugins' => array( 'alphabridge-mcp/alphabridge-mcp.php' ),
	'ab_mcp_options' => array(
		'preview_protection' => 'off' === $ab_mode
			? array( 'on' => false )
			: array( 'on' => true, 'hash' => password_hash( 'x', PASSWORD_DEFAULT ), 'blog_public' => '1' ),
	),
);

function wp_installing( $set = null ) { return true; }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['ab_options'] ) ? $GLOBALS['ab_options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['ab_options'][ $name ] = $value; return true; }
function get_site_option( $name, $default = false ) { return $default; }
function is_multisite() { return false; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( (array) $defaults, (array) $args ); }
function add_action( $tag, $fn, $prio = 10, $args = 1 ) { $GLOBALS['ab_hooks'][ $tag ][] = array( $fn, $prio ); return true; }
function add_filter( $tag, $fn, $prio = 10, $args = 1 ) { return add_action( $tag, $fn, $prio, $args ); }
function has_action( $tag, $fn = false ) {
	foreach ( $GLOBALS['ab_hooks'][ $tag ] ?? array() as $entry ) {
		if ( false === $fn || $entry[0] === $fn ) { return $entry[1]; }
	}
	return false;
}
function has_filter( $tag, $fn = false ) { return has_action( $tag, $fn ); }
function do_action( $tag ) {
	$hooks = $GLOBALS['ab_hooks'][ $tag ] ?? array();
	usort( $hooks, static fn( $a, $b ) => $a[1] <=> $b[1] );
	foreach ( $hooks as $entry ) { call_user_func( $entry[0] ); }
}

$fails = 0;
function ok( $cond, $label ) {
	global $fails;
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) { ++$fails; }
}

ok( is_file( $ab_loader ), 'A the loader file is there' );
ok( ! class_exists( 'AB_MCP_Preview_Protection', false ) && ! class_exists( 'AB_MCP_Settings', false ), 'B nothing of the plugin is loaded, as under WP_INSTALLING' );
include $ab_loader;
ok( false !== has_action( 'plugins_loaded' ), 'C the loader waits for plugins_loaded, after the network plugins' );
ok( ! class_exists( 'AB_MCP_Preview_Protection', false ), 'D and loads nothing before it' );
do_action( 'plugins_loaded' );
if ( 'off' === $ab_mode || 'gone' === $ab_mode ) {
	ok( ! class_exists( 'AB_MCP_Preview_Protection', false ), 'E ' . $ab_mode . ': the loader loads nothing' );
	ok( false === has_action( 'wp' ) && false === has_filter( 'rest_authentication_errors' ), 'F ' . $ab_mode . ': no gate, no fatal error' );
} else {
	ok( class_exists( 'AB_MCP_Preview_Protection', false ) && class_exists( 'AB_MCP_Settings', false ) && class_exists( 'AB_MCP_Page_Cache', false ), 'E the loader loaded the three classes' );
	ok( 10 === has_action( 'wp', array( 'AB_MCP_Preview_Protection', 'gate_without_themes' ) ), 'F the gate of the ways without themes hangs' );
	ok( 110 === has_filter( 'rest_authentication_errors', array( 'AB_MCP_Preview_Protection', 'gate_rest' ) ), 'G the REST gate hangs' );
	ok( 1 === has_action( 'template_redirect', array( 'AB_MCP_Preview_Protection', 'gate_frontend' ) ), 'H the front-end gate hangs' );
	ok( 1 === has_action( 'parse_request', array( 'AB_MCP_Preview_Protection', 'gate_parse_request' ) ), 'H2 the first line on parse_request hangs' );
	ok( AB_MCP_Preview_Protection::is_on(), 'I and reads the state from the option' );
}
echo 0 === $fails ? "LOADER-CHILD ok\n" : "LOADER-CHILD $fails failed\n";
exit( 0 === $fails ? 0 : 1 );
