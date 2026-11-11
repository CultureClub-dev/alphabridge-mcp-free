<?php
/**
 * The well-known documents over real HTTP, for WellKnownHttpTest.
 *
 * Served by PHP's built-in server (php -S, this file as the router). The test
 * bootstrap stands in for WordPress as in the unit tests. Which site answers
 * is set per request by three headers, so one server plays every site:
 *
 * - X-AB-Test-Home:  the site's home address (default https://example.test);
 * - X-AB-Test-Rest:  its REST base (default <home>/wp-json/; with plain
 *                    permalinks <home>/?rest_route=/);
 * - X-AB-Test-OAuth: "off" switches OAuth sign-in off.
 *
 * The plugin either sends its JSON document and ends the request itself,
 * redirects (the bootstrap's wp_redirect() throws; this script turns that
 * into the status and Location WordPress would send), or does nothing; then
 * WordPress would go on to its 404, and this script answers 404 with the
 * body "wordpress-404". X-AB-Test-Guess tells whether the plugin switched
 * off WordPress' guessing of a permalink for that 404.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

require __DIR__ . '/../bootstrap.php';
ab_test_reset();

$ab_home = isset( $_SERVER['HTTP_X_AB_TEST_HOME'] ) ? (string) $_SERVER['HTTP_X_AB_TEST_HOME'] : 'https://example.test';
$GLOBALS['ab_test_home_url']  = $ab_home;
$GLOBALS['ab_test_rest_base'] = isset( $_SERVER['HTTP_X_AB_TEST_REST'] ) ? (string) $_SERVER['HTTP_X_AB_TEST_REST'] : $ab_home . '/wp-json/';
if ( isset( $_SERVER['HTTP_X_AB_TEST_OAUTH'] ) && 'off' === $_SERVER['HTTP_X_AB_TEST_OAUTH'] ) {
	AB_MCP_Settings::set( 'oauth_enabled', false );
}

try {
	AB_MCP_OAuth::serve_well_known();
	http_response_code( 404 );
	header( 'X-AB-Test-Guess: ' . ( false === has_filter( 'do_redirect_guess_404_permalink', '__return_false' ) ? 'on' : 'off' ) );
	echo 'wordpress-404';
} catch ( AbTestExit $exit ) {
	if ( 'redirect' === $exit->kind ) {
		http_response_code( (int) ( $GLOBALS['ab_test_redirect_status'] ?? 302 ) );
		header( 'Location: ' . $exit->detail );
	} else {
		http_response_code( 500 );
	}
	echo $exit->kind;
}
