<?php
/**
 * Stdio bridge for Glama's build check. Not part of the plugin: excluded from
 * every release through .gitattributes.
 *
 * Glama starts an MCP server as a stdio process inside a container and lists
 * its tools to grade them. AlphaBridge MCP is a WordPress plugin whose server
 * speaks MCP over HTTP at /wp-json/alphabridge/v1/mcp, so the container builds
 * a throwaway WordPress with this repository as its plugin (see the build
 * steps on the Glama admin page). This script starts PHP's built-in web server
 * for that WordPress, then passes every JSON-RPC message from stdin to the
 * plugin's endpoint on 127.0.0.1 and writes each answer to stdout, one per
 * line. Nothing leaves the container.
 *
 * Environment: AB_BRIDGE_WP (WordPress root, default /wp), AB_BRIDGE_PORT
 * (default 8080), AB_BRIDGE_TOKEN_FILE (connection key created at build time,
 * default <root>/.glama-token).
 */

// stdout carries only MCP messages: PHP's own notices go to stderr.
ini_set( 'display_errors', 'stderr' );

$root       = getenv( 'AB_BRIDGE_WP' ) ?: '/wp';
$port       = (int) ( getenv( 'AB_BRIDGE_PORT' ) ?: 8080 );
$token_file = getenv( 'AB_BRIDGE_TOKEN_FILE' ) ?: $root . '/.glama-token';
$endpoint   = 'http://127.0.0.1:' . $port . '/index.php?rest_route=/alphabridge/v1/mcp';

$token = is_readable( $token_file ) ? trim( (string) file_get_contents( $token_file ) ) : '';
if ( '' === $token ) {
	fwrite( STDERR, "stdio-bridge: no connection key in $token_file\n" );
	exit( 1 );
}

// The built-in web server, logging to stderr so stdout carries only MCP.
$server = proc_open(
	array( PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:' . $port, '-t', $root ),
	array( 0 => array( 'file', '/dev/null', 'r' ), 1 => STDERR, 2 => STDERR ),
	$pipes
);
if ( ! is_resource( $server ) ) {
	fwrite( STDERR, "stdio-bridge: could not start the PHP web server\n" );
	exit( 1 );
}
register_shutdown_function(
	static function () use ( $server ) {
		proc_terminate( $server );
	}
);

// Wait until WordPress answers, at most ten seconds.
for ( $i = 0; $i < 100; $i++ ) {
	$probe = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.2 );
	if ( $probe ) {
		fclose( $probe );
		break;
	}
	usleep( 100000 );
}

$protocol = '';
$session  = '';

while ( false !== ( $line = fgets( STDIN ) ) ) {
	$line = trim( $line );
	if ( '' === $line ) {
		continue;
	}
	$message = json_decode( $line, true );

	$headers = array(
		'Content-Type: application/json',
		'Accept: application/json, text/event-stream',
		'Authorization: Bearer ' . $token,
	);
	if ( '' !== $protocol ) {
		$headers[] = 'MCP-Protocol-Version: ' . $protocol;
	}
	if ( '' !== $session ) {
		$headers[] = 'Mcp-Session-Id: ' . $session;
	}

	$curl = curl_init( $endpoint );
	curl_setopt_array(
		$curl,
		array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $line,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_HEADERFUNCTION => static function ( $handle, $header ) use ( &$session ) {
				if ( 0 === stripos( $header, 'mcp-session-id:' ) ) {
					$session = trim( substr( $header, 15 ) );
				}
				return strlen( $header );
			},
		)
	);
	$body = curl_exec( $curl );

	// A notification (no id) gets no answer on stdout; a batch answers as sent.
	$is_batch = is_array( $message ) && array_is_list( $message );
	if ( ! $is_batch && ( ! is_array( $message ) || ! array_key_exists( 'id', $message ) ) ) {
		continue;
	}
	$body = is_string( $body ) ? trim( $body ) : '';
	if ( '' === $body ) {
		if ( $is_batch ) {
			continue;
		}
		$body = json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => $message['id'],
				'error'   => array(
					'code'    => -32603,
					'message' => 'No answer from the local WordPress.',
				),
			)
		);
	}
	$answer = json_decode( $body, true );
	if ( is_array( $answer ) && isset( $answer['result']['protocolVersion'] ) ) {
		$protocol = (string) $answer['result']['protocolVersion'];
	}
	fwrite( STDOUT, $body . "\n" );
	fflush( STDOUT );
}
