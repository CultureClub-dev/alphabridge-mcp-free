<?php
/**
 * How the other site on the host answers the relay's fetch of its
 * well-known document, for the two routers over HTTP
 * (well-known-server.php, preview-well-known-server.php): as an AlphaBridge
 * site whose home is the path before /.well-known/, or one of the failures
 * below. Copied from the hotfix 4.10.1 (release/4.10, ed880e5) and adapted
 * to the bootstrap of main ($GLOBALS['ab_test_home']).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

/**
 * The document an AlphaBridge site with this home serves under this
 * well-known name, encoded as it sends it.
 */
function ab_well_known_test_document( string $home, string $name ): string {
	$saved = array( $GLOBALS['ab_test_home'] ?? null, $GLOBALS['ab_test_rest_base'] ?? null );
	$GLOBALS['ab_test_home']      = $home;
	$GLOBALS['ab_test_rest_base'] = $home . '/wp-json/';
	$data                         = 'oauth-authorization-server' === $name ? AB_MCP_OAuth::metadata() : AB_MCP_OAuth::resource_metadata();
	$GLOBALS['ab_test_home']      = $saved[0];
	if ( null === $saved[1] ) {
		unset( $GLOBALS['ab_test_rest_base'] );
	} else {
		$GLOBALS['ab_test_rest_base'] = $saved[1];
	}
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
		case 'no-type':
			return ab_test_http_response( 200, $doc );
		case 'json-404':
			return ab_test_http_response( 404, $doc, $json );
		case 'two-types':
			return ab_test_http_response( 200, $doc, array( 'Content-Type' => array( 'application/json', 'text/html' ) ) );
		case 'type-upper':
			return ab_test_http_response( 200, $doc, array( 'Content-Type' => ' Application/JSON ; charset=utf-8' ) );
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
		case 'endpoint-dotdot':
			$data['token_endpoint'] = $home . '/../../oauth/token';
			break;
		case 'endpoint-encoded-dotdot':
			$data['token_endpoint'] = $home . '/%2e%2e/%2e%2e/oauth/token';
			break;
		case 'endpoint-dot-segment':
			$data['registration_endpoint'] = $home . '/./wp-json/alphabridge/v1/oauth/register';
			break;
		case 'endpoint-empty-segment':
			$data['authorization_endpoint'] = $home . '//?ab_mcp_oauth=authorize';
			break;
		case 'endpoint-fragment':
			$data['token_endpoint'] = $home . '/wp-json/alphabridge/v1/oauth/token#x';
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
