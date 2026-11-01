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

if ( false === strpos( $path, '..' ) && '/' !== $path && is_file( $root . $path ) ) {
	return false;
}

$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
chdir( $root );
require $root . '/index.php';
