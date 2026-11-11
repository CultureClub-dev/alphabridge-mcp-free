<?php
/**
 * Records what AlphaBridge 4.10.0 answered at its own well-known addresses,
 * for WellKnownHttpTest (tests/fixtures/well-known-4.10.0.json): status,
 * Content-Type, Cache-Control, CORS and body of each own path, and per site
 * the WWW-Authenticate of the 401 with the answer at the address it names.
 *
 * Run it only with the 4.10.0 code in place, from the repository root:
 *
 *     git show v4.10.0:includes/class-oauth.php > includes/class-oauth.php
 *     php tests/support/record-well-known-4100.php
 *     git checkout includes/class-oauth.php
 *
 * It refuses to record with any later code (AB_MCP_OAuth::well_known_answer()
 * exists only from 4.10.1 on). The cases are the data providers of the test.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

$root = dirname( __DIR__, 2 );
require $root . '/vendor/autoload.php';
require $root . '/tests/bootstrap.php';
require $root . '/tests/WellKnownHttpTest.php';

if ( method_exists( 'AB_MCP_OAuth', 'well_known_answer' ) ) {
	fwrite( STDERR, "includes/class-oauth.php is not the code of 4.10.0; see the head of this file.\n" );
	exit( 1 );
}

$probe = stream_socket_server( 'tcp://127.0.0.1:0' );
$port  = (int) substr( (string) stream_socket_get_name( $probe, false ), strlen( '127.0.0.1:' ) );
fclose( $probe );
$pipes = array();
$proc  = proc_open(
	array( PHP_BINARY, '-d', 'display_errors=stderr', '-S', '127.0.0.1:' . $port, $root . '/tests/support/well-known-server.php' ),
	array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
	$pipes,
	$root
);
for ( $i = 0; $i < 100; $i++ ) {
	usleep( 50000 );
	$sock = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.2 );
	if ( false !== $sock ) {
		fclose( $sock );
		break;
	}
}

$request = static function ( string $path, string $home, ?string $rest ) use ( $port ): array {
	$sock  = fsockopen( '127.0.0.1', $port, $errno, $errstr, 5.0 );
	$lines = array( 'GET ' . $path . ' HTTP/1.0', 'Host: example.test', 'X-AB-Test-Home: ' . $home );
	if ( null !== $rest ) {
		$lines[] = 'X-AB-Test-Rest: ' . $rest;
	}
	fwrite( $sock, implode( "\r\n", $lines ) . "\r\n\r\n" );
	$raw = (string) stream_get_contents( $sock );
	fclose( $sock );
	list( $head, $body ) = explode( "\r\n\r\n", $raw, 2 ) + array( '', '' );
	$rows    = explode( "\r\n", $head );
	$status  = (int) preg_replace( '#^HTTP/\S+\s+(\d{3}).*$#', '$1', (string) array_shift( $rows ) );
	$headers = array();
	foreach ( $rows as $row ) {
		$name = strtolower( trim( (string) strstr( $row, ':', true ) ) );
		if ( in_array( $name, array( 'content-type', 'cache-control', 'access-control-allow-origin' ), true ) ) {
			$headers[ $name ] = trim( substr( (string) strstr( $row, ':' ), 1 ) );
		}
	}
	ksort( $headers );
	return array(
		'status'  => $status,
		'headers' => $headers,
		'body'    => $body,
	);
};

$out = array(
	'recorded_with' => 'includes/class-oauth.php of v4.10.0 (26375bca1409ecf4ad6817d0edb173b2ff3b55d4), tests/support/record-well-known-4100.php',
	'own'           => array(),
	'challenge'     => array(),
);
foreach ( AlphaBridge\Tests\WellKnownHttpTest::ownPaths() as $label => $case ) {
	$out['own'][ $label ] = $request( $case[0], $case[1], $case[2] );
}
foreach ( AlphaBridge\Tests\WellKnownHttpTest::sites() as $label => $case ) {
	ab_test_reset();
	$GLOBALS['ab_test_home_url']  = $case[0];
	$GLOBALS['ab_test_rest_base'] = $case[1] ?? $case[0] . '/wp-json/';
	$rest_request                 = new WP_REST_Request();
	$rest_request->set_route( '/alphabridge/v1/mcp' );
	$response  = ( new AB_MCP_REST_Controller( new AB_MCP_Tool_Registry() ) )->add_security_headers( new WP_REST_Response( null, 401 ), null, $rest_request );
	$challenge = (string) ( $response->get_headers()['WWW-Authenticate'] ?? '' );
	ab_test_reset();
	preg_match( '/resource_metadata="https:\/\/example\.test([^"]+)"/', $challenge, $m );
	$out['challenge'][ $label ] = array(
		'www-authenticate' => $challenge,
		'answer'           => $request( (string) ( $m[1] ?? '' ), $case[0], $case[1] ),
	);
}

proc_terminate( $proc );
foreach ( $pipes as $pipe ) {
	fclose( $pipe );
}
proc_close( $proc );

file_put_contents( $root . '/tests/fixtures/well-known-4.10.0.json', json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
echo 'Recorded ' . count( $out['own'] ) . ' own paths and ' . count( $out['challenge'] ) . " challenges.\n";
