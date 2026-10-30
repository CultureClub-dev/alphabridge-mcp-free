<?php
/**
 * wp_delete_plugin / wp_delete_theme hand only installed identifiers to the
 * WordPress delete helpers, which would otherwise delete whatever path the
 * string names. No WordPress: the helpers are stubs that record the call.
 * And wp_install_plugin answers an unknown slug with a sentence of its own,
 * in the site's language, not with WordPress.org's English one.
 *
 * wp_delete_plugin and wp_deactivate_plugin leave AlphaBridge itself alone
 * (Pro 4.7.4; free since 4.11.0): the free plugin, Pro, the owner drop-in
 * and a renamed folder of the running plugin (here this one; in Pro's
 * version of this test, Pro),
 * recognised in real folders by identity as wp_update_plugin does. A plugin
 * whose name only resembles it is not caught. wp_deactivate_plugin takes only
 * the identifier of an installed plugin, and says whether it was active.
 *
 * Run:  php tests/standalone-lifecycle/lifecycle-delete-test.php
 */
error_reporting( E_ALL );
$tmp = sys_get_temp_dir() . '/ab-lifecycle-' . getmypid();
foreach ( array( 'file', 'misc', 'plugin', 'update', 'plugin-install', 'theme', 'theme-install', 'class-wp-upgrader' ) as $inc ) {
	@mkdir( $tmp . '/wp-admin/includes', 0755, true );
	file_put_contents( $tmp . "/wp-admin/includes/$inc.php", "<?php\n" );
}
define( 'ABSPATH', $tmp . '/' );
// The running plugin: this one, by its constant, as on a site.
define( 'AB_MCP_DIR', dirname( __DIR__, 2 ) . '/' );
// Real folders: AlphaBridge is recognised by what is on disk.
define( 'WP_PLUGIN_DIR', $tmp . '/wp-content/plugins' );
foreach ( array( 'akismet/akismet.php', 'alphabridge-mcp-pro/alphabridge-mcp-pro.php', 'alphabridge-mcp/alphabridge-mcp.php', 'alphabridge-mcp-owner/alphabridge-mcp-owner.php', 'alphabridge-helper/alphabridge-helper.php' ) as $f ) {
	@mkdir( WP_PLUGIN_DIR . '/' . dirname( $f ), 0755, true );
	file_put_contents( WP_PLUGIN_DIR . '/' . $f, "<?php\n" );
}
file_put_contents( WP_PLUGIN_DIR . '/hello.php', "<?php\n" );
// This plugin in a folder of another name: the same folder as AB_MCP_DIR.
symlink( realpath( AB_MCP_DIR ), WP_PLUGIN_DIR . '/bridge-renamed' );
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { public $code; public $message; public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } }
}
$GLOBALS['t_tr'] = array();
function __( $s, $d = null ) { return $GLOBALS['t_tr'][ $s ] ?? $s; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
// WordPress.org's answer to a slug it does not know, as plugins_api() passes it on.
function plugins_api( $action, $args ) { return 'akismet' === $args['slug'] ? new WP_Error( 'plugins_api_failed', 'An unexpected error occurred.' ) : new WP_Error( 'plugins_api_failed', 'Plugin not found.' ); }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function apply_filters( $h, $v, ...$a ) { return $v; }
// WordPress' validate_file() as of 7.x, verbatim in behaviour: it only
// objects to "../" in the middle or more than once, to a file outside an
// explicit allow-list (3) and to a Windows drive letter (2). ".", "..",
// "/etc" and "x/.." all pass with 0 — the membership check has to do the work.
function wp_normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }
function validate_file( $file, $allowed_files = array() ) {
	if ( ! is_scalar( $file ) || '' === $file ) { return 0; }
	$file = wp_normalize_path( $file );
	if ( '../' === $file ) { return 1; }
	if ( preg_match_all( '#\.\./#', $file, $matches, PREG_SET_ORDER ) && ( count( $matches ) > 1 ) ) { return 1; }
	if ( str_contains( $file, '../' ) && '../' !== mb_substr( $file, -3, 3 ) ) { return 1; }
	if ( ! empty( $allowed_files ) && ! in_array( $file, $allowed_files, true ) ) { return 3; }
	if ( ':' === substr( $file, 1, 1 ) ) { return 2; }
	return 0;
}
function get_plugins() {
	return array(
		'akismet/akismet.php'                             => array( 'Name' => 'Akismet' ),
		'hello.php'                                       => array( 'Name' => 'Hello' ),
		'alphabridge-mcp-pro/alphabridge-mcp-pro.php'     => array( 'Name' => 'AlphaBridge MCP Pro' ),
		'alphabridge-mcp/alphabridge-mcp.php'             => array( 'Name' => 'AlphaBridge MCP' ),
		'alphabridge-mcp-owner/alphabridge-mcp-owner.php' => array( 'Name' => 'AlphaBridge MCP – Owner Unlock' ),
		'bridge-renamed/alphabridge-mcp.php'              => array( 'Name' => 'AlphaBridge MCP' ),
		'alphabridge-helper/alphabridge-helper.php'       => array( 'Name' => 'Not AlphaBridge' ),
	);
}
$GLOBALS['t_active']      = array();
$GLOBALS['t_deactivated'] = array();
function is_plugin_active( $p ) { return in_array( $p, $GLOBALS['t_active'], true ); }
function deactivate_plugins( $plugins ) {
	foreach ( (array) $plugins as $p ) {
		$GLOBALS['t_deactivated'][] = $p;
		$GLOBALS['t_active']        = array_values( array_diff( $GLOBALS['t_active'], array( $p ) ) );
	}
}
$GLOBALS['t_deleted'] = array();
function delete_plugins( $plugins ) { $GLOBALS['t_deleted'][] = array( 'plugin', $plugins ); return true; }
function delete_theme( $s ) { $GLOBALS['t_deleted'][] = array( 'theme', $s ); return true; }
// WP_Theme::exists() is true for any existing directory (".", ".." included);
// only wp_get_themes() lists real themes by directory name.
class AB_Test_Theme { private $s; public function __construct( $s ) { $this->s = $s; } public function exists() { return true; } public function get_stylesheet() { return $this->s; } }
function wp_get_theme( $s = null ) { return new AB_Test_Theme( (string) $s ); }
// A broken theme (child without parent) is listed only with errors => null.
function wp_get_themes( $args = array() ) {
	$themes = array( 'twentytwentyfour' => new AB_Test_Theme( 'twentytwentyfour' ), 'child-theme' => new AB_Test_Theme( 'child-theme' ), 'another-theme' => new AB_Test_Theme( 'another-theme' ) );
	if ( array_key_exists( 'errors', $args ) && null === $args['errors'] ) { $themes['broken-child'] = new AB_Test_Theme( 'broken-child' ); }
	return $themes;
}
function get_stylesheet() { return 'child-theme'; }
function get_template() { return 'twentytwentyfour'; }
class AB_MCP_Tool_Registry {}
require_once AB_MCP_DIR . 'includes/tools/class-tools-base.php';
require_once AB_MCP_DIR . 'includes/class-lifecycle-confirm.php';
require_once AB_MCP_DIR . 'includes/tools/class-tools-install-update.php';
require_once AB_MCP_DIR . 'includes/tools/class-tools-plugins-themes-manage.php';

$fail = 0;
function ok( $cond, $msg ) { global $fail; if ( $cond ) { echo "  ok   $msg\n"; } else { $fail++; echo "  FAIL $msg\n"; } }

echo "plugins\n";
foreach ( array( '../../uploads/x.php', '/etc/x.php', 'not-installed/plugin.php', 'akismet', '', 'akismet/../akismet/akismet.php', '.', '..', './akismet/akismet.php', 'akismet/akismet.php/' ) as $bad ) {
	$r = AB_MCP_Tools_Install_Update::delete_plugin( array( 'plugin' => $bad ) );
	ok( is_wp_error( $r ) && 'ab_mcp_bad_plugin' === $r->code, "refuses '$bad'" );
}
ok( array() === $GLOBALS['t_deleted'], 'nothing was handed to delete_plugins()' );
$r = AB_MCP_Tools_Install_Update::delete_plugin( array( 'plugin' => 'akismet/akismet.php' ) );
ok( ! is_wp_error( $r ) && array( 'plugin', array( 'akismet/akismet.php' ) ) === end( $GLOBALS['t_deleted'] ), 'an installed plugin is deleted' );

echo "themes\n";
$GLOBALS['t_deleted'] = array();
foreach ( array( '../uploads', '/etc', 'no-such-theme', '', 'twentytwentyfour/..', '.', '..', '/', './..', './twentytwentyfour', 'twentytwentyfour/' ) as $bad ) {
	$r = AB_MCP_Tools_Install_Update::delete_theme( array( 'stylesheet' => $bad ) );
	ok( is_wp_error( $r ) && 'ab_mcp_bad_theme' === $r->code, "refuses '$bad'" );
}
$r = AB_MCP_Tools_Install_Update::delete_theme( array( 'stylesheet' => 'child-theme' ) );
ok( is_wp_error( $r ) && 'ab_mcp_active_theme' === $r->code, 'the active theme is refused' );
$r = AB_MCP_Tools_Install_Update::delete_theme( array( 'stylesheet' => 'twentytwentyfour' ) );
ok( is_wp_error( $r ) && 'ab_mcp_active_theme' === $r->code, 'its parent is refused' );
ok( array() === $GLOBALS['t_deleted'], 'nothing was handed to delete_theme()' );

// an installed, inactive theme is deleted
$r = AB_MCP_Tools_Install_Update::delete_theme( array( 'stylesheet' => 'another-theme' ) );
ok( ! is_wp_error( $r ) && array( 'theme', 'another-theme' ) === end( $GLOBALS['t_deleted'] ), 'an installed inactive theme is deleted' );
$r = AB_MCP_Tools_Install_Update::delete_theme( array( 'stylesheet' => 'broken-child' ) );
ok( ! is_wp_error( $r ) && array( 'theme', 'broken-child' ) === end( $GLOBALS['t_deleted'] ), 'a broken theme (child without parent) stays deletable' );
foreach ( array( 'akismet\\akismet.php' ) as $bad ) {
	$r = AB_MCP_Tools_Install_Update::delete_plugin( array( 'plugin' => $bad ) );
	ok( is_wp_error( $r ), "refuses backslash spelling '$bad'" );
}

echo "AlphaBridge itself\n";
$own = array( 'alphabridge-mcp-pro/alphabridge-mcp-pro.php', 'alphabridge-mcp/alphabridge-mcp.php', 'alphabridge-mcp-owner/alphabridge-mcp-owner.php', 'bridge-renamed/alphabridge-mcp.php' );
$GLOBALS['t_deleted']     = array();
$GLOBALS['t_active']      = $own;
$GLOBALS['t_deactivated'] = array();
foreach ( $own as $p ) {
	$r = AB_MCP_Tools_Install_Update::delete_plugin( array( 'plugin' => $p ) );
	ok( is_wp_error( $r ) && 'ab_mcp_delete_own' === $r->code && false !== strpos( $r->message, 'wp-admin' ), "D1 not deleted: $p" );
}
ok( array() === $GLOBALS['t_deleted'] && array() === $GLOBALS['t_deactivated'] && $own === $GLOBALS['t_active'], 'D1 nothing was deactivated or handed to delete_plugins()' );
$GLOBALS['t_active'] = array( 'alphabridge-helper/alphabridge-helper.php' );
$r = AB_MCP_Tools_Install_Update::delete_plugin( array( 'plugin' => 'alphabridge-helper/alphabridge-helper.php' ) );
ok( ! is_wp_error( $r ) && array( 'plugin', array( 'alphabridge-helper/alphabridge-helper.php' ) ) === end( $GLOBALS['t_deleted'] ) && array( 'alphabridge-helper/alphabridge-helper.php' ) === $GLOBALS['t_deactivated'], 'D2 a plugin that only resembles the name: deactivated, then deleted' );

echo "deactivate\n";
$GLOBALS['t_active']      = array_merge( array( 'akismet/akismet.php' ), $own );
$GLOBALS['t_deactivated'] = array();
foreach ( array( '', 'akismet', 'not-installed/plugin.php', '../../uploads/x.php', 'akismet/../akismet/akismet.php', 'akismet\\akismet.php', '.', '..' ) as $bad ) {
	$r = AB_MCP_Tools_Plugins_Themes_Manage::deactivate_plugin( array( 'plugin' => $bad ) );
	ok( is_wp_error( $r ) && 'ab_mcp_bad_plugin' === $r->code, "A1 refuses '$bad'" );
}
foreach ( $own as $p ) {
	$r = AB_MCP_Tools_Plugins_Themes_Manage::deactivate_plugin( array( 'plugin' => $p ) );
	ok( is_wp_error( $r ) && 'ab_mcp_deactivate_own' === $r->code && false !== strpos( $r->message, 'wp-admin' ), "A2 not deactivated: $p" );
}
ok( array() === $GLOBALS['t_deactivated'] && array_merge( array( 'akismet/akismet.php' ), $own ) === $GLOBALS['t_active'], 'A1 A2 nothing was handed to deactivate_plugins()' );
$r = AB_MCP_Tools_Plugins_Themes_Manage::deactivate_plugin( array( 'plugin' => 'akismet/akismet.php' ) );
ok( ! is_wp_error( $r ) && true === $r['deactivated'] && true === $r['was_active'] && array( 'akismet/akismet.php' ) === $GLOBALS['t_deactivated'] && ! is_plugin_active( 'akismet/akismet.php' ), 'A3 an active plugin is deactivated, was_active true' );
$r = AB_MCP_Tools_Plugins_Themes_Manage::deactivate_plugin( array( 'plugin' => 'hello.php' ) );
ok( ! is_wp_error( $r ) && true === $r['deactivated'] && false === $r['was_active'], 'A4 an inactive plugin: was_active false' );

echo "install\n";
$r = AB_MCP_Tools_Install_Update::install_plugin( array( 'slug' => 'no-such-plugin' ) );
ok( is_wp_error( $r ) && 'plugins_api_failed' === $r->code && 'WordPress.org has no plugin with the slug "no-such-plugin". The slug is the plugin\'s name in its wordpress.org address, e.g. "akismet".' === $r->message, 'an unknown slug: a sentence of its own' );
$GLOBALS['t_tr'] = array( 'WordPress.org has no plugin with the slug "%s". The slug is the plugin\'s name in its wordpress.org address, e.g. "akismet".' => 'WordPress.org hat kein Plugin mit dem Slug „%s“.' );
$r = AB_MCP_Tools_Install_Update::install_plugin( array( 'slug' => 'no-such-plugin' ) );
ok( is_wp_error( $r ) && 'WordPress.org hat kein Plugin mit dem Slug „no-such-plugin“.' === $r->message, 'translated on a German site' );
$GLOBALS['t_tr'] = array();
$r = AB_MCP_Tools_Install_Update::install_plugin( array( 'slug' => 'akismet' ) );
ok( is_wp_error( $r ) && 'An unexpected error occurred.' === $r->message, 'another error of WordPress.org stays as WordPress gives it' );

// The link first and by itself: it points at the plugin's own folder.
@unlink( WP_PLUGIN_DIR . '/bridge-renamed' );
foreach ( array( 'akismet/akismet.php', 'alphabridge-mcp-pro/alphabridge-mcp-pro.php', 'alphabridge-mcp/alphabridge-mcp.php', 'alphabridge-mcp-owner/alphabridge-mcp-owner.php', 'alphabridge-helper/alphabridge-helper.php' ) as $f ) {
	@unlink( WP_PLUGIN_DIR . '/' . $f );
	@rmdir( WP_PLUGIN_DIR . '/' . dirname( $f ) );
}
@unlink( WP_PLUGIN_DIR . '/hello.php' );
@rmdir( WP_PLUGIN_DIR ); @rmdir( $tmp . '/wp-content' );
foreach ( glob( $tmp . '/wp-admin/includes/*.php' ) as $f ) { @unlink( $f ); }
@rmdir( $tmp . '/wp-admin/includes' ); @rmdir( $tmp . '/wp-admin' ); @rmdir( $tmp );
echo $fail ? "FAILED: $fail\n" : "lifecycle-delete-test: all passed\n";
exit( $fail ? 1 : 0 );
