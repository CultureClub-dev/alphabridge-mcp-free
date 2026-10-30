<?php
/**
 * Why an install did not happen: wp_install_plugin, wp_install_plugin_zip and
 * wp_install_theme_zip take the reason from what the upgrader and its skin
 * hold ($skin->result, the upgrade messages). They asked
 * Automatic_Upgrader_Skin for get_errors(), which only WP_Ajax_Upgrader_Skin
 * has, and every refusal ended as "internal error" (measured 02.10.2026,
 * zip_ueberschreiben.json). A folder that exists already gets a clear
 * refusal with the version installed, and none of them advises
 * wp_delete_plugin any more (it runs the plugin's uninstall routine).
 *
 * Run:  php tests/standalone-lifecycle/lifecycle-errors-test.php
 */

require __DIR__ . '/../standalone-lib/support/update-harness.php';

function wp_tempnam( $name = '' ) { return tempnam( $GLOBALS['ab_root'], 'pkg' ); }
function plugins_api( $action, $args ) { return (object) array( 'download_link' => 'https://downloads.wordpress.org/plugin/' . $args['slug'] . '.zip', 'version' => '2.0.0' ); }
function activate_plugin( $p ) { return null; }
function switch_theme( $s ) {}

$zip = base64_encode( "PK\x03\x04" . str_repeat( 'x', 40 ) );
/** What the upgrader gives back for an existing folder (WP_Upgrader::install_package()). */
function ab_folder_exists( $where ) {
	return static function ( $up, $pkg ) use ( $where ) {
		$up->skin->set_result( new WP_Error( 'folder_exists', 'Destination folder already exists.', $where ) );
		return array(); // Plugin_Upgrader::install() hands back $this->result, which stays array() then.
	};
}

ab_reset();
ab_install_plugin( 'good', '1.0.0' );
ab_install_theme( 'calm', '1.4.2' );

echo "E1 a plugin ZIP over an installed plugin\n";
$GLOBALS['ab_install'] = ab_folder_exists( WP_PLUGIN_DIR . '/good/' );
$r = AB_MCP_Tools_Install_Update::install_plugin_zip( array( 'content' => $zip ) );
ok( 'ab_mcp_install_exists' === ab_code( $r ), 'E1 a clear refusal: ' . ab_code( $r ) );
$m = ab_msg( $r );
ok( false !== strpos( $m, 'plugin folder good exists already' ) && false !== strpos( $m, 'Test good 1.0.0, good/good.php' ), 'E1 it names the folder and what is installed: ' . $m );
ok( false !== strpos( $m, 'Nothing was changed' ) && false !== strpos( $m, 'wp_update_plugin' ), 'E1 and the way to a newer version' );
ok( false === strpos( $m, 'wp_delete_plugin' ) && false === stripos( $m, 'internal' ), 'E1 no advice to delete, no internal error' );

echo "E2 the same through wp_install_plugin (wordpress.org)\n";
$r = AB_MCP_Tools_Install_Update::install_plugin( array( 'slug' => 'good' ) );
ok( 'ab_mcp_install_exists' === ab_code( $r ) && false !== strpos( ab_msg( $r ), 'good/good.php' ), 'E2 the same refusal' );

echo "E3 a theme ZIP over an installed theme\n";
$GLOBALS['ab_install'] = ab_folder_exists( get_theme_root() . '/calm/' );
$r = AB_MCP_Tools_Install_Update::install_theme_zip( array( 'content' => $zip ) );
ok( 'ab_mcp_install_exists' === ab_code( $r ) && false !== strpos( ab_msg( $r ), 'theme folder calm exists already (installed: Test calm 1.4.2)' ), 'E3 names the theme and its version: ' . ab_msg( $r ) );
ok( false === strpos( ab_msg( $r ), 'wp_delete_theme' ) && false !== strpos( ab_msg( $r ), 'wp_update_theme' ), 'E3 no advice to delete' );

echo "E4 other reasons\n";
$GLOBALS['ab_install'] = static function ( $up, $pkg ) {
	$e = new WP_Error( 'incompatible_archive_no_plugins', 'The package could not be installed.', 'No valid plugins were found.' );
	$up->skin->set_result( $e );
	return $e;
};
$r = AB_MCP_Tools_Install_Update::install_plugin_zip( array( 'content' => $zip ) );
ok( 'incompatible_archive_no_plugins' === ab_code( $r ), 'E4 a WP_Error of the upgrader is handed on' );
$GLOBALS['ab_install'] = static function ( $up, $pkg ) {
	$up->skin->set_result( new WP_Error( 'download_failed', 'Download failed.' ) );
	return null;
};
$r = AB_MCP_Tools_Install_Update::install_plugin( array( 'slug' => 'other' ) );
ok( 'download_failed' === ab_code( $r ), 'E4 the skin\'s result where install() gave null' );
$GLOBALS['ab_install'] = static function ( $up, $pkg ) {
	$up->skin->feedback( 'Unpacking the package&#8230;' );
	$up->skin->feedback( '<strong>Could not copy file.</strong>' );
	return false;
};
$r = AB_MCP_Tools_Install_Update::install_theme_zip( array( 'content' => $zip ) );
ok( 'ab_mcp_install_failed' === ab_code( $r ) && false !== strpos( ab_msg( $r ), 'Could not copy file.' ) && false === strpos( ab_msg( $r ), '<strong>' ), 'E4 the upgrade messages where there is nothing else: ' . ab_msg( $r ) );
$GLOBALS['ab_install'] = static function ( $up, $pkg ) { return false; };
$r = AB_MCP_Tools_Install_Update::install_plugin_zip( array( 'content' => $zip ) );
ok( 'ab_mcp_install_failed' === ab_code( $r ) && false !== strpos( ab_msg( $r ), 'gave no reason' ) && false === strpos( ab_msg( $r ), 'wp_delete_plugin' ), 'E4 and a plain reason where WordPress gave none' );

echo "E5 the source\n";
$src = (string) file_get_contents( $GLOBALS['ab_free'] . 'tools/class-tools-install-update.php' );
ok( 0 === preg_match( '/->get_errors\s*\(/', $src ), 'E5 nothing asks a skin for get_errors() any more' );
ok( 0 === preg_match( "/__\(\s*'[^']*delete it first/", $src ), 'E5 no message advises deleting first' );

echo "E6 a plugin ZIP that installs\n";
$GLOBALS['ab_install'] = static function ( $up, $pkg ) { return true; };
$r = AB_MCP_Tools_Install_Update::install_plugin_zip( array( 'content' => $zip ) );
ok( ! is_wp_error( $r ) && true === $r['installed'], 'E6 an install that works still reports installed' );

echo "E7 a core update the upgrader answers with false (Codex, 30.10.2026)\n";
/* Core_Upgrader::upgrade() (WordPress 7.0.2): false where fs_connect() cannot
 * reach the files, before anything is copied; the new version otherwise. */
class Core_Upgrader extends WP_Upgrader {
	public function upgrade( $current, $args = array() ) { return $GLOBALS['ab_core_result']; }
}
function wp_version_check( $extra = array(), $force = false ) {}
function get_core_updates( $options = array() ) { return array( (object) array( 'response' => 'upgrade', 'current' => '7.0.3' ) ); }
$GLOBALS['ab_core_result'] = false;
$r = AB_MCP_Tools_Install_Update::update_core();
ok( 'ab_mcp_update_failed' === ab_code( $r ) && false !== strpos( ab_msg( $r ), 'Nothing was changed' ) && false !== strpos( ab_msg( $r ), 'Dashboard › Updates' ), 'E7 false is a failure, not "updated": ' . ( is_wp_error( $r ) ? ab_msg( $r ) : json_encode( $r ) ) );
$GLOBALS['ab_core_result'] = new WP_Error( 'up_to_date', 'WordPress is at the latest version.' );
ok( 'up_to_date' === ab_code( AB_MCP_Tools_Install_Update::update_core() ), 'E7 an error of the upgrader comes through as it is' );
$GLOBALS['ab_core_result'] = '7.0.3';
$r = AB_MCP_Tools_Install_Update::update_core();
ok( ! is_wp_error( $r ) && true === $r['updated'] && '7.0.3' === $r['new_version'], 'E7 the new version as a string: updated' );

ab_finish( 'LIFECYCLE-ERRORS' );
