<?php
/**
 * Stdio bridge for Glama's build check. Not part of the plugin: excluded from
 * every release through .gitattributes and .distignore.
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

/**
 * Stop with a message on stderr.
 *
 * @param string $message What went wrong.
 */
function ab_bridge_fail( $message ) {
	fwrite( STDERR, 'stdio-bridge: ' . $message . "\n" );
	exit( 1 );
}

/**
 * Whether something answers on the port.
 *
 * @param int $port Port on 127.0.0.1.
 * @return bool
 */
function ab_bridge_port_open( $port ) {
	$socket = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.2 );
	if ( $socket ) {
		fclose( $socket );
		return true;
	}
	return false;
}

/**
 * A JSON-RPC error answer for one request id.
 *
 * @param mixed  $id      The request's id.
 * @param string $message Error message.
 * @return object
 */
function ab_bridge_error( $id, $message ) {
	return (object) array(
		'jsonrpc' => '2.0',
		'id'      => $id,
		'error'   => (object) array(
			'code'    => -32603,
			'message' => $message,
		),
	);
}

/**
 * Whether a decoded value is a JSON-RPC answer. Answers stay objects, so an
 * empty JSON object ({}) goes out as {} and not as [].
 *
 * @param mixed $value JSON decoded without the associative flag.
 * @return bool
 */
function ab_bridge_is_answer( $value ) {
	return is_object( $value ) && isset( $value->jsonrpc ) && '2.0' === $value->jsonrpc
		&& property_exists( $value, 'id' ) && ( property_exists( $value, 'result' ) || property_exists( $value, 'error' ) );
}

$token = is_readable( $token_file ) ? trim( (string) file_get_contents( $token_file ) ) : '';
if ( '' === $token ) {
	ab_bridge_fail( "no connection key in $token_file" );
}
// The key goes only to a server this script started itself.
if ( ab_bridge_port_open( $port ) ) {
	ab_bridge_fail( "port $port is already in use" );
}

// The built-in web server, logging to stderr so stdout carries only MCP.
$server = proc_open(
	array( PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:' . $port, '-t', $root ),
	array( 0 => array( 'file', '/dev/null', 'r' ), 1 => STDERR, 2 => STDERR ),
	$pipes
);
if ( ! is_resource( $server ) ) {
	ab_bridge_fail( 'could not start the PHP web server' );
}
register_shutdown_function(
	static function () use ( $server ) {
		proc_terminate( $server );
		proc_close( $server );
	}
);
if ( function_exists( 'pcntl_async_signals' ) ) {
	pcntl_async_signals( true );
	foreach ( array( SIGTERM, SIGINT, SIGHUP ) as $signal ) {
		pcntl_signal(
			$signal,
			static function () {
				exit( 0 ); // Runs the shutdown function, which stops the web server.
			}
		);
	}
}

// Wait until the web server answers, at most ten seconds.
$ready = false;
for ( $i = 0; $i < 100; $i++ ) {
	$status = proc_get_status( $server );
	if ( ! $status['running'] ) {
		ab_bridge_fail( 'the PHP web server stopped with exit code ' . $status['exitcode'] );
	}
	if ( ab_bridge_port_open( $port ) ) {
		$ready = true;
		break;
	}
	usleep( 100000 );
}
if ( ! $ready ) {
	ab_bridge_fail( "the PHP web server did not answer on port $port" );
}

$protocol = '';
$session  = '';

// stdin is read without blocking, into a buffer that is cut at line ends: a
// part of a line never stalls the bridge.
stream_set_blocking( STDIN, false );
$buffer = '';
$open   = true;

while ( $open || false !== strpos( $buffer, "\n" ) ) {
	if ( false === strpos( $buffer, "\n" ) ) {
		// Wait for input in one-second steps, so a signal is handled and a
		// web server that stopped is noticed.
		$read   = array( STDIN );
		$write  = null;
		$except = null;
		$ready  = @stream_select( $read, $write, $except, 1 );
		if ( ! $ready ) {
			$status = proc_get_status( $server );
			if ( ! $status['running'] ) {
				ab_bridge_fail( 'the PHP web server stopped with exit code ' . $status['exitcode'] );
			}
			continue;
		}
		$chunk = fread( STDIN, 65536 );
		if ( false === $chunk || ( '' === $chunk && feof( STDIN ) ) ) {
			$open    = false; // stdin closed: the client is done.
			$buffer .= "\n";  // A last line without a line end still counts.
			continue;
		}
		$buffer .= $chunk;
		continue;
	}
	$end    = strpos( $buffer, "\n" );
	$line   = trim( substr( $buffer, 0, $end ) );
	$buffer = (string) substr( $buffer, $end + 1 );
	if ( '' === $line ) {
		continue;
	}
	$message = json_decode( $line, true );

	// The ids that expect an answer: none for a notification, one per request in a batch.
	$is_batch = is_array( $message ) && array_is_list( $message ) && array() !== $message;
	$ids      = array();
	foreach ( $is_batch ? $message : array( $message ) as $item ) {
		if ( is_array( $item ) && array_key_exists( 'id', $item ) && isset( $item['method'] ) ) {
			$ids[ json_encode( $item['id'] ) ] = $item['id'];
		}
	}

	$headers = array(
		'Content-Type: application/json',
		'Accept: application/json',
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
	$body   = curl_exec( $curl );
	$failed = false === $body ? curl_error( $curl ) : '';

	if ( array() === $ids ) {
		continue; // Only notifications: nothing goes to stdout.
	}

	// Take only answers to ids that were asked, each once.
	$decoded = is_string( $body ) ? json_decode( $body ) : null;
	$found   = array();
	foreach ( is_array( $decoded ) ? $decoded : array( $decoded ) as $item ) {
		if ( ! ab_bridge_is_answer( $item ) ) {
			continue;
		}
		$key = json_encode( $item->id );
		if ( isset( $ids[ $key ] ) && ! isset( $found[ $key ] ) ) {
			$found[ $key ] = $item;
		}
	}

	// Every request id gets an answer, from WordPress or as an error, in the order asked.
	$reason  = '' !== $failed ? 'No answer from the local WordPress: ' . $failed : 'The local WordPress did not answer in JSON-RPC.';
	$answers = array();
	foreach ( $ids as $key => $id ) {
		$answers[] = isset( $found[ $key ] ) ? $found[ $key ] : ab_bridge_error( $id, $reason );
	}

	foreach ( $answers as $answer ) {
		if ( isset( $answer->result ) && is_object( $answer->result ) && isset( $answer->result->protocolVersion ) ) {
			$protocol = (string) $answer->result->protocolVersion;
		}
	}
	$out = $is_batch ? $answers : $answers[0];
	fwrite( STDOUT, json_encode( $out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
	fflush( STDOUT );
}
