<?php
/**
 * The authorization endpoint over real HTTP, for the test of the mark the
 * firewall self-test looks for (AuthorizeMarkHttpTest).
 *
 * Served by PHP's built-in server (php -S, this file as the router) or run
 * under php-cgi. The test bootstrap stands in for WordPress exactly as in
 * the unit tests; its stubs end a request by throwing AbTestExit where
 * WordPress would redirect or die, so this script turns that into the
 * status WordPress would send. What matters is what is delivered BEFORE
 * that: a header set by header() in AB_MCP_OAuth::handle_authorize()
 * reaches the client here, or it does not.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

require __DIR__ . '/../bootstrap.php';
ab_test_reset();

// The plugin reads the OAuth parameters from $_REQUEST, as on a real site.
$_REQUEST = $_GET;

try {
	AB_MCP_OAuth::handle_authorize();
	// Not the authorization endpoint: the plugin did nothing.
	http_response_code( 204 );
} catch ( AbTestExit $exit ) {
	if ( 'login' === $exit->kind ) {
		http_response_code( 302 );
		header( 'Location: /wp-login.php' );
	} elseif ( 'redirect' === $exit->kind ) {
		http_response_code( 302 );
		header( 'Location: ' . $exit->detail );
	} else {
		http_response_code( 400 );
	}
	echo $exit->kind;
}
