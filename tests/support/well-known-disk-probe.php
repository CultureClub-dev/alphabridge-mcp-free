<?php
/**
 * One relay request in a PHP process of its own, for WellKnownDocumentRootTest:
 * the site's core lies at ABSPATH, which the relay takes the document root
 * from (with the path of site_url), so each case needs its own process.
 *
 * Argument: JSON with abspath, home, site (site_url) and path (the request
 * URI). The other site answers as an AlphaBridge site whose home is the path
 * before /.well-known/. Prints the kind of the answer, the issuer relayed
 * and the number of fetches as JSON; warnings go to stderr, where the test
 * expects none.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

$ab_case = json_decode( (string) $argv[1], true );
define( 'ABSPATH', rtrim( (string) $ab_case['abspath'], '/' ) . '/' );
require __DIR__ . '/../bootstrap.php';
ab_test_reset();
$GLOBALS['ab_test_home']      = (string) $ab_case['home'];
$GLOBALS['ab_test_site_url']  = (string) $ab_case['site'];
$GLOBALS['ab_test_rest_base'] = (string) $ab_case['home'] . '/wp-json/';
$fetches                      = 0;
$GLOBALS['ab_test_http_answer'] = static function ( string $url ) use ( &$fetches ) {
	++$fetches;
	preg_match( '#\A(https?://[^/]+)(.*)/\.well-known/oauth-authorization-server#', $url, $m );
	$saved                        = array( $GLOBALS['ab_test_home'], $GLOBALS['ab_test_site_url'], $GLOBALS['ab_test_rest_base'] );
	$GLOBALS['ab_test_home']      = $m[1] . $m[2];
	$GLOBALS['ab_test_site_url']  = $m[1] . $m[2];
	$GLOBALS['ab_test_rest_base'] = $m[1] . $m[2] . '/wp-json/';
	$data                         = AB_MCP_OAuth::metadata();
	list( $GLOBALS['ab_test_home'], $GLOBALS['ab_test_site_url'], $GLOBALS['ab_test_rest_base'] ) = $saved;
	return ab_test_http_response( 200, (string) wp_json_encode( $data ), array( 'Content-Type' => 'application/json' ) );
};
$answer = AB_MCP_OAuth::well_known_answer( (string) $ab_case['path'] );
echo json_encode(
	array(
		'kind'    => $answer['kind'],
		'issuer'  => $answer['document']['issuer'] ?? null,
		'fetches' => $fetches,
	)
);
