<?php
/**
 * A router of the well-known tests under PHP's built-in server, and one raw
 * GET against it (for WellKnownGuardTest, which needs a whole request:
 * parse_request, the handler and the protection's second line).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

/**
 * Start php -S with this router; null where it does not start.
 *
 * @return array{proc:resource,pipes:array<int,resource>,port:int}|null
 */
function ab_test_wk_serve( string $router ): ?array {
	$probe = @stream_socket_server( 'tcp://127.0.0.1:0' );
	if ( false === $probe ) {
		return null;
	}
	$port = (int) substr( (string) stream_socket_get_name( $probe, false ), strlen( '127.0.0.1:' ) );
	fclose( $probe );
	$pipes = array();
	$proc  = proc_open(
		array( PHP_BINARY, '-d', 'display_errors=stderr', '-S', '127.0.0.1:' . $port, $router ),
		array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
		$pipes,
		dirname( __DIR__, 2 )
	);
	if ( ! is_resource( $proc ) ) {
		return null;
	}
	$server = array(
		'proc'  => $proc,
		'pipes' => $pipes,
		'port'  => $port,
	);
	for ( $i = 0; $i < 100; $i++ ) {
		usleep( 50000 );
		$sock = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.2 );
		if ( false !== $sock ) {
			fclose( $sock );
			return $server;
		}
	}
	ab_test_wk_stop( $server );
	return null;
}

/** Stop a server of ab_test_wk_serve(). */
function ab_test_wk_stop( ?array $server ): void {
	if ( null === $server || ! is_resource( $server['proc'] ) ) {
		return;
	}
	proc_terminate( $server['proc'] );
	foreach ( $server['pipes'] as $pipe ) {
		fclose( $pipe );
	}
	proc_close( $server['proc'] );
}

/**
 * One raw GET, the path sent exactly as given.
 *
 * @param string[] $lines Header lines.
 * @return array{status:int,headers:array<string,string>,body:string,raw:string}
 */
function ab_test_wk_get( int $port, string $path, array $lines ): array {
	$sock = fsockopen( '127.0.0.1', $port, $errno, $errstr, 5.0 );
	if ( false === $sock ) {
		throw new RuntimeException( $errstr );
	}
	fwrite( $sock, implode( "\r\n", array_merge( array( 'GET ' . $path . ' HTTP/1.0', 'Host: example.test' ), $lines ) ) . "\r\n\r\n" );
	stream_set_timeout( $sock, 10 );
	$raw = (string) stream_get_contents( $sock );
	fclose( $sock );
	$split   = strpos( $raw, "\r\n\r\n" );
	$head    = false === $split ? $raw : substr( $raw, 0, $split );
	$rows    = explode( "\r\n", $head );
	$status  = (int) preg_replace( '#^HTTP/\S+\s+(\d{3}).*$#', '$1', (string) array_shift( $rows ) );
	$headers = array();
	foreach ( $rows as $row ) {
		$colon = strpos( $row, ':' );
		if ( false !== $colon ) {
			$headers[ strtolower( trim( substr( $row, 0, $colon ) ) ) ] = trim( substr( $row, $colon + 1 ) );
		}
	}
	return array(
		'status'  => $status,
		'headers' => $headers,
		'body'    => false === $split ? '' : substr( $raw, $split + 4 ),
		'raw'     => $path . "\n" . substr( $raw, 0, 1500 ),
	);
}
