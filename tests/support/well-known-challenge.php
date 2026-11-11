<?php
/**
 * The 401 of the MCP endpoint, through the emulated REST server: a POST
 * without a token to /alphabridge/v1/mcp, with the routes and filters the
 * controller registers (register_routes(): the permission check that refuses
 * it, add_security_headers() on rest_post_dispatch). Used by
 * WellKnownHttpTest and by record-well-known-4100.php, so 4.10.0 and today
 * are asked the same way.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

/**
 * Status and the headers that matter of the 401 on a site with this home
 * and REST base (null: <home>/wp-json/).
 *
 * @return array{status:int,headers:array<string,string>}
 */
function ab_test_wk_challenge( string $home, ?string $rest ): array {
	ab_test_reset();
	$GLOBALS['ab_test_home_url']  = $home;
	$GLOBALS['ab_test_rest_base'] = $rest ?? $home . '/wp-json/';
	( new AB_MCP_REST_Controller( new AB_MCP_Tool_Registry() ) )->register_routes();
	$request = new WP_REST_Request();
	$request->set_method( 'POST' );
	$request->set_route( '/alphabridge/v1/mcp' );
	$request->set_json_params(
		array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => array(),
		)
	);
	$response = ab_test_rest_dispatch( $request );
	$headers  = array_change_key_case( $response->get_headers(), CASE_LOWER );
	ab_test_reset();
	return array(
		'status'  => $response->get_status(),
		'headers' => array_intersect_key( $headers, array_flip( array( 'www-authenticate', 'cache-control', 'referrer-policy' ) ) ),
	);
}
