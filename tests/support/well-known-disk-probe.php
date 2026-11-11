<?php
/**
 * One relay request in a PHP process of its own, for the test with
 * open_basedir (WellKnownRelayCacheTest): the web directory is the first
 * argument, with a WordPress under /staging/123 (made by the test). Prints
 * the kind of the answer and the number of fetches as JSON; warnings go to
 * stderr, where the test expects none.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

require __DIR__ . '/../bootstrap.php';
ab_test_reset();
$GLOBALS['ab_test_home_path']   = rtrim( (string) $argv[1], '/' ) . '/';
$fetches                        = 0;
$GLOBALS['ab_test_http_answer'] = static function ( string $url ) use ( &$fetches ) {
	++$fetches;
	$saved                        = array( $GLOBALS['ab_test_home_url'] ?? null, $GLOBALS['ab_test_rest_base'] ?? null );
	$GLOBALS['ab_test_home_url']  = 'https://example.test/staging/123';
	$GLOBALS['ab_test_rest_base'] = 'https://example.test/staging/123/wp-json/';
	$data                         = AB_MCP_OAuth::metadata();
	unset( $GLOBALS['ab_test_home_url'], $GLOBALS['ab_test_rest_base'] );
	return ab_test_http_response( 200, (string) wp_json_encode( $data ), array( 'Content-Type' => 'application/json' ) );
};
$answer = AB_MCP_OAuth::well_known_answer( '/.well-known/oauth-authorization-server/staging/123' );
echo json_encode(
	array(
		'kind'    => $answer['kind'],
		'fetches' => $fetches,
	)
);
