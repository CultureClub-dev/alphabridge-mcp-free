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
$ab_mode = isset( $_SERVER['HTTP_X_AB_TEST_FETCH'] ) ? (string) $_SERVER['HTTP_X_AB_TEST_FETCH'] : 'site';

/**
 * The document an AlphaBridge site with this home serves under this
 * well-known name, encoded as it sends it.
 */
function ab_well_known_test_document( string $home, string $name ): string {
	$saved = array( $GLOBALS['ab_test_home_url'], $GLOBALS['ab_test_rest_base'] );
	$GLOBALS['ab_test_home_url']  = $home;
	$GLOBALS['ab_test_rest_base'] = $home . '/wp-json/';
	$data                         = 'oauth-authorization-server' === $name ? AB_MCP_OAuth::metadata() : AB_MCP_OAuth::resource_metadata();
	list( $GLOBALS['ab_test_home_url'], $GLOBALS['ab_test_rest_base'] ) = $saved;
	return (string) wp_json_encode( $data );
}

/**
 * How the other site answers the plugin's fetch of $url.
 *
 * @return array|WP_Error
 */
function ab_well_known_test_answer( string $mode, string $url ) {
	$json  = array( 'Content-Type' => 'application/json; charset=utf-8' );
	$parts = (array) parse_url( $url );
	$path  = (string) ( $parts['path'] ?? '' );
	if ( ! preg_match( '#\A(.*)/\.well-known/(oauth-authorization-server|oauth-protected-resource)#', $path, $m ) ) {
		return ab_test_http_response( 404, 'not found', array( 'Content-Type' => 'text/html' ) );
	}
	$home = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . $m[1];
	$name = $m[2];
	$doc  = ab_well_known_test_document( $home, $name );
	$data = json_decode( $doc, true );
	switch ( $mode ) {
		case 'site':
			return ab_test_http_response( 200, $doc, $json );
		case 'blocked':
			return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to the host' );
		case 'timeout':
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 3001 milliseconds' );
		case 'tls':
			return new WP_Error( 'http_request_failed', 'cURL error 35: TLS connect error' );
		case 'not-found':
			return ab_test_http_response( 404, '<html>not found</html>', array( 'Content-Type' => 'text/html' ) );
		case 'server-error':
			return ab_test_http_response( 500, '', array( 'Content-Type' => 'text/html' ) );
		case 'redirect':
			return ab_test_http_response( 302, '', array( 'Location' => $home . '/.well-known/' . $name . '?x=1' ) );
		case 'html':
			return ab_test_http_response( 200, '<html><body>' . $doc . '</body></html>', array( 'Content-Type' => 'text/html; charset=UTF-8' ) );
		case 'json-as-text':
			return ab_test_http_response( 200, $doc, array( 'Content-Type' => 'text/plain' ) );
		case 'broken-json':
			return ab_test_http_response( 200, substr( $doc, 0, -5 ), $json );
		case 'json-list':
			return ab_test_http_response( 200, '[' . $doc . ']', $json );
		case 'too-big':
			$data['padding'] = str_repeat( 'x', AB_MCP_OAuth::WELL_KNOWN_MAX_BYTES );
			return ab_test_http_response( 200, (string) wp_json_encode( $data ), $json );
		case 'at-limit':
			$data['padding'] = '';
			$short           = strlen( (string) wp_json_encode( $data ) );
			$data['padding'] = str_repeat( 'x', AB_MCP_OAuth::WELL_KNOWN_MAX_BYTES - $short );
			return ab_test_http_response( 200, (string) wp_json_encode( $data ), $json );
		case 'below-limit':
			$data['padding'] = '';
			$short           = strlen( (string) wp_json_encode( $data ) );
			$data['padding'] = str_repeat( 'x', AB_MCP_OAuth::WELL_KNOWN_MAX_BYTES - 1 - $short );
			return ab_test_http_response( 200, (string) wp_json_encode( $data ), $json );
		case 'foreign-issuer':
			$data['issuer'] = $home . '9';
			break;
		case 'issuer-with-slash':
			$data['issuer'] = $home . '/';
			break;
		case 'foreign-endpoint':
			$data['authorization_endpoint'] = 'https://elsewhere.example/authorize';
			break;
		case 'endpoint-outside-the-site':
			$data['token_endpoint'] = substr( $home, 0, (int) strrpos( $home, '/' ) ) . '/wp-json/alphabridge/v1/oauth/token';
			break;
		case 'no-registration':
			unset( $data['registration_endpoint'] );
			break;
		case 'no-pkce':
			$data['code_challenge_methods_supported'] = array( 'plain' );
			break;
		case 'extra-field':
			$data['x_extra'] = 'not relayed';
			break;
		case 'foreign-resource':
			$data['resource'] = $home . '/wp-json/other/v1/mcp';
			break;
		case 'two-servers':
			$data['authorization_servers'][] = 'https://elsewhere.example';
			break;
	}
	return ab_test_http_response( 200, (string) wp_json_encode( $data ), $json );
}

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
