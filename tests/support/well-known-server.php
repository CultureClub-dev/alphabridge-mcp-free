<?php
/**
 * The well-known documents over real HTTP, for WellKnownHttpTest.
 *
 * Served by PHP's built-in server (php -S, this file as the router). The test
 * bootstrap stands in for WordPress as in the unit tests. Which site answers
 * is set per request by headers, so one server plays every site:
 *
 * - X-AB-Test-Home:  the site's home address (default https://example.test);
 * - X-AB-Test-Rest:  its REST base (default <home>/wp-json/; with plain
 *                    permalinks <home>/?rest_route=/);
 * - X-AB-Test-OAuth: "off" switches OAuth sign-in off;
 * - X-AB-Test-Abspath: where the site's core lies on disk (ABSPATH; the
 *                    plugin takes the document root from it and the path of
 *                    site_url, and fetches only for paths with a wp-load.php
 *                    under that root); defined before the bootstrap;
 * - X-AB-Test-Fetch: how the other site on this host answers when the plugin
 *                    fetches a document from it (wp_safe_remote_get()):
 *                    "site" (default) as an AlphaBridge site whose home is the
 *                    path before /.well-known/, or one of the failures in
 *                    ab_well_known_test_answer() below.
 *
 * The plugin either sends a JSON document and ends the request itself, or
 * refuses (then it has set the status, here reported as X-AB-Test-Status,
 * and switched off canonical redirects and guessed permalinks, reported as
 * X-AB-Test-Canonical and X-AB-Test-Guess), or leaves the request alone;
 * WordPress would then go on to its page, here the body "wordpress".
 * Every fetch is reported while it happens: X-AB-Test-Fetches (how many),
 * X-AB-Test-Fetch-Url and X-AB-Test-Fetch-Args (of the first).
 *
 * The same router serves record-well-known-main.php, which records the
 * answers of main before the hotfix (e6220a6); it asks only the plugin's
 * handler (AB_MCP_OAuth::serve_well_known()), which both have.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

if ( isset( $_SERVER['HTTP_X_AB_TEST_ABSPATH'] ) ) {
	define( 'ABSPATH', rtrim( (string) $_SERVER['HTTP_X_AB_TEST_ABSPATH'], '/' ) . '/' );
}
require __DIR__ . '/../bootstrap.php';
ab_test_reset();

$ab_home = isset( $_SERVER['HTTP_X_AB_TEST_HOME'] ) ? (string) $_SERVER['HTTP_X_AB_TEST_HOME'] : 'https://example.test';
$GLOBALS['ab_test_home']      = $ab_home;
$GLOBALS['ab_test_rest_base'] = isset( $_SERVER['HTTP_X_AB_TEST_REST'] ) ? (string) $_SERVER['HTTP_X_AB_TEST_REST'] : $ab_home . '/wp-json/';
if ( isset( $_SERVER['HTTP_X_AB_TEST_OAUTH'] ) && 'off' === $_SERVER['HTTP_X_AB_TEST_OAUTH'] ) {
	AB_MCP_Settings::set( 'oauth_enabled', false );
}
$ab_mode = isset( $_SERVER['HTTP_X_AB_TEST_FETCH'] ) ? (string) $_SERVER['HTTP_X_AB_TEST_FETCH'] : 'site';

require_once __DIR__ . '/well-known-answers.php';

$GLOBALS['ab_test_http_answer'] = static function ( string $url, array $args ) use ( $ab_mode ) {
	header( 'X-AB-Test-Fetches: ' . count( $GLOBALS['ab_test_http'] ) );
	if ( 1 === count( $GLOBALS['ab_test_http'] ) ) {
		header( 'X-AB-Test-Fetch-Url: ' . $url );
		header( 'X-AB-Test-Fetch-Args: ' . wp_json_encode( array_intersect_key( $args, array_flip( array( 'timeout', 'redirection', 'limit_response_size', 'headers' ) ) ) ) );
	}
	return ab_well_known_test_answer( $ab_mode, $url );
};

AB_MCP_OAuth::serve_well_known();
// Not answered by the plugin: what WordPress would go on to.
http_response_code( (int) ( $GLOBALS['ab_test_status'] ?? 200 ) );
header( 'X-AB-Test-Status: ' . ( isset( $GLOBALS['ab_test_status'] ) ? (string) $GLOBALS['ab_test_status'] : 'unset' ) );
header( 'X-AB-Test-Canonical: ' . ( false === has_filter( 'redirect_canonical', '__return_false' ) ? 'on' : 'off' ) );
header( 'X-AB-Test-Guess: ' . ( false === has_filter( 'do_redirect_guess_404_permalink', '__return_false' ) ? 'on' : 'off' ) );
echo 'wordpress';
