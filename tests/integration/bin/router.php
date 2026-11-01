<?php
/**
 * Router of `php -S` for the two integration sites.
 *
 * The built-in server serves a file that exists and runs a PHP file that
 * exists; every other path (/wp-json/…, a pretty permalink) goes to the
 * index.php of the document root, as the rewrite rules of a web server would
 * send it. The sites use plain permalinks and the helpers ask the REST API
 * through ?rest_route=, so this only matters for a test that asks a path.
 * The path is not resolved: the plugin directory is a link to the working
 * tree, and its files are served through that link.
 *
 * @package AlphaBridge_MCP
 */

$root = (string) $_SERVER['DOCUMENT_ROOT'];
$path = rawurldecode( (string) parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );

// `php -S` reads no .htaccess, so the self-check of the protected store
// (AB_MCP_Protected_Store) finds its test files served: the case without a
// proof. While <AB_IT_ROOT>/run/apache-<site-x> exists (ProtectedStoreTest
// makes and removes it), this router answers as an Apache that reads
// .htaccess would: 403 for a path below a folder whose .htaccess denies
// everyone ("Require all denied" or "Deny from all"). Test code only; the
// plugin has no switch for it.
if ( false === strpos( $path, '..' ) && is_file( dirname( $root ) . '/run/apache-' . basename( $root ) ) ) {
	for ( $dir = dirname( $root . $path ); strlen( $dir ) > strlen( $root ); $dir = dirname( $dir ) ) {
		if ( is_file( $dir . '/.htaccess' ) && preg_match( '/^\s*(?:Require all denied|Deny from all)\s*$/mi', (string) file_get_contents( $dir . '/.htaccess' ) ) ) {
			http_response_code( 403 );
			echo 'Forbidden';
			return true;
		}
	}
}

if ( false === strpos( $path, '..' ) && '/' !== $path && is_file( $root . $path ) ) {
	return false;
}

$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
chdir( $root );
require $root . '/index.php';
