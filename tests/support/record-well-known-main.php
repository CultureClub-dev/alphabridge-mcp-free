<?php
/**
 * Records what main answered at its own well-known addresses BEFORE the
 * hotfix 4.10.1 came in (e6220a6), for WellKnownHttpTest and
 * PreviewWellKnownHttpTest (tests/fixtures/well-known-main.json):
 *
 * - own: status, Content-Type, Cache-Control, CORS and body of each own
 *   path (WellKnownHttpTest::ownPaths()), served by the plugin's handler;
 * - challenge: per site the 401 of the MCP endpoint (status,
 *   WWW-Authenticate, Cache-Control, Referrer-Policy) with the answer at the
 *   address it names;
 * - protected: with the preview protection on, the answers a visitor, a
 *   signed-in user and a cookie holder got at the own addresses and at
 *   addresses that are not the plugin's (PreviewWellKnownHttpTest::
 *   protectedCases()): status, the headers of caching, robots, CORS and the
 *   mark of the password page, and the body.
 *
 * It refuses to record unless includes/class-oauth.php and
 * includes/class-preview-protection.php are byte for byte those of
 * e6220a653b1b63d9e44d74303b6177c3296efbe4 (their git blob ids below), so
 * the record is main's answer before the change, not today's. Run from the
 * repository root, with the test files of this branch in place:
 *
 *     php tests/support/record-well-known-main.php
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

$root = dirname( __DIR__, 2 );

// The git blob ids of the two files at e6220a6 (git rev-parse e6220a6:<file>).
$blobs = array(
	'includes/class-oauth.php'              => '02ae44420b72698e065c0c41cef39819f5b84b54',
	'includes/class-preview-protection.php' => '18bab0d4131c7b581cec214fe7f4ab502923b7ab',
);
foreach ( $blobs as $file => $want ) {
	$bytes = (string) file_get_contents( $root . '/' . $file );
	if ( sha1( 'blob ' . strlen( $bytes ) . "\0" . $bytes ) !== $want ) {
		fwrite( STDERR, "$file is not the file of e6220a6; this records main before the hotfix only.\n" );
		exit( 1 );
	}
}

require $root . '/vendor/autoload.php';
require $root . '/tests/bootstrap.php';
require $root . '/tests/WellKnownHttpTest.php';
require $root . '/tests/PreviewWellKnownHttpTest.php';
require_once $root . '/tests/support/well-known-challenge.php';

/**
 * A php -S server with this router; returns [proc, pipes, port].
 */
function ab_record_server( string $root, string $router ): array {
	$probe = stream_socket_server( 'tcp://127.0.0.1:0' );
	$port  = (int) substr( (string) stream_socket_get_name( $probe, false ), strlen( '127.0.0.1:' ) );
	fclose( $probe );
	$pipes = array();
	$proc  = proc_open(
		array( PHP_BINARY, '-d', 'display_errors=stderr', '-S', '127.0.0.1:' . $port, $router ),
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
	return array( $proc, $pipes, $port );
}

/** Stop a server of ab_record_server(). */
function ab_record_stop( array $server ): void {
	proc_terminate( $server[0] );
	foreach ( $server[1] as $pipe ) {
		fclose( $pipe );
	}
	proc_close( $server[0] );
}

/**
 * One raw GET with these extra header lines; the headers kept are $keep.
 *
 * @param string[] $lines
 * @param string[] $keep
 */
function ab_record_get( int $port, string $path, array $lines, array $keep ): array {
	$sock = fsockopen( '127.0.0.1', $port, $errno, $errstr, 5.0 );
	fwrite( $sock, implode( "\r\n", array_merge( array( 'GET ' . $path . ' HTTP/1.0', 'Host: example.test' ), $lines ) ) . "\r\n\r\n" );
	$raw = (string) stream_get_contents( $sock );
	fclose( $sock );
	list( $head, $body ) = explode( "\r\n\r\n", $raw, 2 ) + array( '', '' );
	$rows    = explode( "\r\n", $head );
	$status  = (int) preg_replace( '#^HTTP/\S+\s+(\d{3}).*$#', '$1', (string) array_shift( $rows ) );
	$headers = array();
	foreach ( $rows as $row ) {
		$name = strtolower( trim( (string) strstr( $row, ':', true ) ) );
		if ( in_array( $name, $keep, true ) ) {
			$headers[ $name ] = trim( substr( (string) strstr( $row, ':' ), 1 ) );
		}
	}
	ksort( $headers );
	return array(
		'status'  => $status,
		'headers' => $headers,
		'body'    => $body,
	);
}

$out = array(
	'recorded_with' => 'includes/class-oauth.php and includes/class-preview-protection.php of main at e6220a653b1b63d9e44d74303b6177c3296efbe4, tests/support/record-well-known-main.php',
	'own'           => array(),
	'challenge'     => array(),
	'protected'     => array(),
);

$plain = ab_record_server( $root, $root . '/tests/support/well-known-server.php' );
$keep  = array( 'content-type', 'cache-control', 'access-control-allow-origin' );
foreach ( AlphaBridge\Tests\WellKnownHttpTest::ownPaths() as $label => $case ) {
	$lines = array( 'X-AB-Test-Home: ' . $case[1] );
	if ( null !== $case[2] ) {
		$lines[] = 'X-AB-Test-Rest: ' . $case[2];
	}
	$out['own'][ $label ] = ab_record_get( $plain[2], $case[0], $lines, $keep );
}
foreach ( AlphaBridge\Tests\WellKnownHttpTest::sites() as $label => $case ) {
	$challenge = ab_test_wk_challenge( $case[0], $case[1] );
	ksort( $challenge['headers'] );
	preg_match( '/resource_metadata="https:\/\/example\.test([^"]+)"/', $challenge['headers']['www-authenticate'] ?? '', $m );
	$lines = array( 'X-AB-Test-Home: ' . $case[0] );
	if ( null !== $case[1] ) {
		$lines[] = 'X-AB-Test-Rest: ' . $case[1];
	}
	$out['challenge'][ $label ] = array(
		'status'  => $challenge['status'],
		'headers' => $challenge['headers'],
		'answer'  => ab_record_get( $plain[2], (string) ( $m[1] ?? '' ), $lines, $keep ),
	);
}
ab_record_stop( $plain );

$guarded = ab_record_server( $root, $root . '/tests/support/preview-well-known-server.php' );
foreach ( AlphaBridge\Tests\PreviewWellKnownHttpTest::protectedCases() as $label => $case ) {
	$out['protected'][ $label ] = ab_record_get( $guarded[2], $case[0], AlphaBridge\Tests\PreviewWellKnownHttpTest::lines( $case[1], $case[2], '', 'site', $case[3] ), AlphaBridge\Tests\PreviewWellKnownHttpTest::KEEP );
}
ab_record_stop( $guarded );

file_put_contents( $root . '/tests/fixtures/well-known-main.json', json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
echo 'Recorded ' . count( $out['own'] ) . ' own paths, ' . count( $out['challenge'] ) . ' challenges and ' . count( $out['protected'] ) . " answers with the protection on.\n";
