<?php
/**
 * The mark on the authorization endpoint's answers, delivered over HTTP.
 *
 * The firewall self-test (FirewallCheckTest) judges an answer by the header
 * X-AB-MCP: authorize. The unit tests see the action the endpoint fires
 * with that line; this test sees the header itself, the way the probe and
 * a browser see it: the endpoint runs under PHP's built-in web server (or
 * php-cgi), a real request goes in, and the response headers are read.
 *
 * What must hold:
 *
 * - The redirect to the login (the probe: an address as client id, nobody
 *   logged in) carries the header.
 * - An error answer (no client id) carries the header.
 * - Nothing else does.
 *
 * Both ways need only the PHP that runs the suite (PHP_BINARY) or a php-cgi
 * next to it; without either the test is skipped and says so.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthorizeMarkHttpTest extends TestCase {

	private const ROUTER = __DIR__ . '/support/authorize-server.php';

	/**
	 * @return array<string,array{0:string,1:int,2:bool}>
	 */
	public static function requests(): array {
		return array(
			'the probe: an address as client id, nobody logged in' => array( 'ab_mcp_oauth=authorize&client_id=https%3A%2F%2Fexample.com%2Fclient.json&redirect_uri=https%3A%2F%2Fexample.test%2F&response_type=code', 302, true ),
			'an error: no client id'                                => array( 'ab_mcp_oauth=authorize', 400, true ),
			'not the endpoint'                                      => array( 'p=1', 204, false ),
		);
	}

	#[DataProvider( 'requests' )]
	public function testTheAnswerCarriesTheMark( string $query, int $status, bool $marked ): void {
		$answer = $this->request( $query );

		self::assertSame( $status, $answer['status'], $answer['raw'] );
		$marks = array_values( array_filter( $answer['headers'], static fn( string $h ): bool => 0 === stripos( $h, 'x-ab-mcp:' ) ) );
		if ( $marked ) {
			self::assertCount( 1, $marks, "One mark in:\n" . $answer['raw'] );
			self::assertSame( 'authorize', trim( substr( $marks[0], strlen( 'x-ab-mcp:' ) ) ) );
		} else {
			self::assertSame( array(), $marks, $answer['raw'] );
		}
	}

	/**
	 * One request to the endpoint, over php -S where it starts, else over
	 * php-cgi; skipped where neither can run.
	 *
	 * @return array{status:int,headers:string[],raw:string}
	 */
	private function request( string $query ): array {
		$server = $this->builtInServer( $query );
		if ( null !== $server ) {
			return $server;
		}
		$cgi = $this->cgi( $query );
		if ( null !== $cgi ) {
			return $cgi;
		}
		self::markTestSkipped( 'Neither "' . PHP_BINARY . ' -S" nor php-cgi could run the endpoint on this machine; the mark header is checked live on a site instead.' );
	}

	/**
	 * @return array{status:int,headers:string[],raw:string}|null Null when the server does not start.
	 */
	private function builtInServer( string $query ): ?array {
		$probe = @stream_socket_server( 'tcp://127.0.0.1:0' );
		if ( false === $probe ) {
			return null;
		}
		$port = (int) substr( (string) stream_socket_get_name( $probe, false ), strlen( '127.0.0.1:' ) );
		fclose( $probe );

		$pipes = array();
		$proc  = proc_open(
			array( PHP_BINARY, '-d', 'display_errors=stderr', '-S', '127.0.0.1:' . $port, self::ROUTER ),
			array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes,
			dirname( __DIR__ )
		);
		if ( ! is_resource( $proc ) ) {
			return null;
		}
		try {
			$up = false;
			for ( $i = 0; $i < 100 && ! $up; $i++ ) {
				usleep( 50000 );
				$sock = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.2 );
				if ( false !== $sock ) {
					fclose( $sock );
					$up = true;
				}
			}
			if ( ! $up ) {
				return null;
			}
			$context = stream_context_create(
				array(
					'http' => array(
						'method'          => 'GET',
						'ignore_errors'   => true,
						'follow_location' => 0,
						'timeout'         => 10,
						'header'          => "User-Agent: Mozilla/5.0 (AlphaBridge MCP test)\r\n",
					),
				)
			);
			$http_response_header = array();
			$body                 = @file_get_contents( 'http://127.0.0.1:' . $port . '/?' . $query, false, $context );
			if ( false === $body || empty( $http_response_header ) ) {
				return null;
			}
			$status = (int) preg_replace( '#^HTTP/\S+\s+(\d{3}).*$#', '$1', (string) $http_response_header[0] );
			return array(
				'status'  => $status,
				'headers' => array_slice( $http_response_header, 1 ),
				'raw'     => implode( "\n", $http_response_header ) . "\n\n" . $body,
			);
		} finally {
			proc_terminate( $proc );
			foreach ( $pipes as $pipe ) {
				fclose( $pipe );
			}
			proc_close( $proc );
		}
	}

	/**
	 * @return array{status:int,headers:string[],raw:string}|null Null without a php-cgi.
	 */
	private function cgi( string $query ): ?array {
		$candidates = array( PHP_BINDIR . '/php-cgi', dirname( PHP_BINARY ) . '/php-cgi' );
		foreach ( explode( PATH_SEPARATOR, (string) getenv( 'PATH' ) ) as $dir ) {
			$candidates[] = rtrim( $dir, '/' ) . '/php-cgi';
		}
		$cgi = null;
		foreach ( $candidates as $candidate ) {
			if ( is_executable( $candidate ) ) {
				$cgi = $candidate;
				break;
			}
		}
		if ( null === $cgi ) {
			return null;
		}
		$pipes = array();
		$proc  = proc_open(
			array( $cgi, '-f', self::ROUTER ),
			array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes,
			dirname( __DIR__ ),
			array(
				'REQUEST_METHOD'  => 'GET',
				'QUERY_STRING'    => $query,
				'SCRIPT_FILENAME' => self::ROUTER,
				'REDIRECT_STATUS' => '200',
				'SERVER_PROTOCOL' => 'HTTP/1.1',
			)
		);
		if ( ! is_resource( $proc ) ) {
			return null;
		}
		$out = (string) stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $proc );
		$end = strpos( $out, "\r\n\r\n" );
		if ( false === $end ) {
			return null;
		}
		$headers = explode( "\r\n", substr( $out, 0, $end ) );
		$status  = 200;
		foreach ( $headers as $header ) {
			if ( 0 === stripos( $header, 'status:' ) ) {
				$status = (int) trim( substr( $header, strlen( 'status:' ) ) );
			}
		}
		return array(
			'status'  => $status,
			'headers' => $headers,
			'raw'     => $out,
		);
	}
}
