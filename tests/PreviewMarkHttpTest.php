<?php
/**
 * The mark on the preview protection's password page, delivered over HTTP.
 *
 * The check after a change of the site address (Pro) takes a 401 of the home
 * page as this site's own only with the header X-AB-MCP: preview-protection.
 * PreviewProtectionTest sees the action the gate fires with that line; this
 * test sees the header itself, the way that check and a browser see it: the
 * gates run under PHP's built-in web server (or php-cgi) with the router
 * tests/support/preview-mark-server.php, real requests go in, and the
 * response headers are read off the wire.
 *
 * What must hold, with the protection on:
 *
 * - The password page carries exactly one X-AB-MCP header, with the value
 *   preview-protection, and the status 401: for a visitor, after a wrong
 *   password, on admin-post.php and on wp-links-opml.php.
 * - No other answer of the protection carries it: not the «0» that answers
 *   admin-ajax.php (a 401 as well), not robots.txt it serves, not the reload
 *   after the right password, not the page the cookie of that reload passes.
 * - Every one of these answers carries X-Robots-Tag, which the gates send as
 *   soon as the protection is on: so an answer without the mark is one where
 *   the gate ran and could still send headers, not one it never reached.
 *
 * Both ways need only the PHP that runs the suite (PHP_BINARY) or a php-cgi
 * next to it; without either the test is skipped and says so.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Preview_Protection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-page-cache.php';
require_once __DIR__ . '/../includes/class-preview-protection.php';

final class PreviewMarkHttpTest extends TestCase {

	private const ROUTER = __DIR__ . '/support/preview-mark-server.php';

	/** The password of this run; the router gets its hash only. */
	private static string $password = '';

	private static string $hash = '';

	/**
	 * The built-in server of this class, started once; null where it does not
	 * start (then every request goes to php-cgi).
	 *
	 * @var array{proc:resource,pipes:array<int,resource>,port:int}|null
	 */
	private static ?array $server = null;

	/** What the server wrote to stdout and stderr, for the failure messages. */
	private static string $log = '';

	public static function setUpBeforeClass(): void {
		self::$password = bin2hex( random_bytes( 8 ) );
		// bcrypt as hash_password() makes it, at the lowest cost: the gate
		// checks it with password_verify(), which reads the cost from the
		// hash, and PHP's default cost would take a fifth of a second on
		// every POST.
		self::$hash = (string) password_hash( self::$password, PASSWORD_BCRYPT, array( 'cost' => 4 ) );
		self::$server   = self::startServer();
	}

	public static function tearDownAfterClass(): void {
		self::stopServer();
	}

	/**
	 * @return array<string,array{0:string,1:string,2:?string,3:int,4:bool}>
	 */
	public static function answers(): array {
		return array(
			'the password page for a visitor'              => array( 'GET', '/about/', null, 401, true ),
			'the password page again after a wrong one'    => array( 'POST', '/about/', AB_MCP_Preview_Protection::FIELD . '=nope', 401, true ),
			'the password page of admin-post.php'          => array( 'GET', '/wp-admin/admin-post.php?action=export_csv', null, 401, true ),
			'the password page of wp-links-opml.php'       => array( 'GET', '/wp-links-opml.php', null, 401, true ),
			'the «0» of admin-ajax.php, a 401 without it'  => array( 'GET', '/wp-admin/admin-ajax.php?action=load_more', null, 401, false ),
			'robots.txt, which the gate serves'            => array( 'GET', '/robots.txt', null, 200, false ),
		);
	}

	#[DataProvider( 'answers' )]
	public function testOnlyThePasswordPageCarriesTheMark( string $method, string $target, ?string $body, int $status, bool $marked ): void {
		$answer = $this->request( $method, $target, $body );

		self::assertSame( $status, $answer['status'], self::context( $answer ) );
		self::assertTheGateRan( $answer );
		self::assertMark( $marked, $answer );
	}

	public function testTheReloadAfterTheRightPasswordAndThePageItsCookiePassesCarryNoMark(): void {
		$reload = $this->request( 'POST', '/about/', AB_MCP_Preview_Protection::FIELD . '=' . rawurlencode( self::$password ) );
		self::assertSame( 303, $reload['status'], self::context( $reload ) );
		self::assertTheGateRan( $reload );
		self::assertMark( false, $reload );
		$cookie = self::cookie( $reload, AB_MCP_Preview_Protection::COOKIE );
		self::assertNotSame( '', $cookie, 'The reload sets the cookie. ' . self::context( $reload ) );

		// The same address again with that cookie, as the browser follows the
		// reload: the gate passes, the page is served, without the mark.
		$page = $this->request( 'GET', '/about/', null, $cookie );
		self::assertSame( 200, $page['status'], self::context( $page ) );
		self::assertSame( 'page', $page['body'], 'The gate passed and the site answered. ' . self::context( $page ) );
		self::assertTheGateRan( $page );
		self::assertMark( false, $page );
	}

	/* ---------------------------------------------------------- assertions */

	/**
	 * Exactly one X-AB-MCP header with the value preview-protection, or none.
	 *
	 * @param array{status:int,headers:string[],body:string,raw:string} $answer
	 */
	private static function assertMark( bool $marked, array $answer ): void {
		$marks = self::values( $answer, 'X-AB-MCP' );
		if ( $marked ) {
			self::assertSame( array( 'preview-protection' ), $marks, 'One mark, with the value of the protection. ' . self::context( $answer ) );
		} else {
			self::assertSame( array(), $marks, 'No mark. ' . self::context( $answer ) );
		}
	}

	/**
	 * X-Robots-Tag, which every gate sends while on, where headers can still
	 * be sent: the request reached the gate, and an answer without the mark
	 * is not one where nothing could be sent any more.
	 *
	 * @param array{status:int,headers:string[],body:string,raw:string} $answer
	 */
	private static function assertTheGateRan( array $answer ): void {
		self::assertSame( array( 'noindex, nofollow' ), self::values( $answer, 'X-Robots-Tag' ), 'The gate ran and sent its header. ' . self::context( $answer ) );
	}

	/* ------------------------------------------------------------ reading */

	/**
	 * The values of every header line with this name, in the order sent.
	 *
	 * @param array{status:int,headers:string[],body:string,raw:string} $answer
	 * @return string[]
	 */
	private static function values( array $answer, string $name ): array {
		$out = array();
		foreach ( $answer['headers'] as $line ) {
			$colon = strpos( $line, ':' );
			if ( false !== $colon && 0 === strcasecmp( trim( substr( $line, 0, $colon ) ), $name ) ) {
				$out[] = trim( substr( $line, $colon + 1 ) );
			}
		}
		return $out;
	}

	/**
	 * The cookie a Set-Cookie line of the answer sets under this name, as
	 * name=value for the Cookie header of the next request; '' without one.
	 *
	 * @param array{status:int,headers:string[],body:string,raw:string} $answer
	 */
	private static function cookie( array $answer, string $name ): string {
		foreach ( self::values( $answer, 'Set-Cookie' ) as $value ) {
			$pair = trim( (string) strtok( $value, ';' ) );
			if ( 0 === strpos( $pair, $name . '=' ) && strlen( $pair ) > strlen( $name ) + 1 ) {
				return $pair;
			}
		}
		return '';
	}

	/**
	 * @param array{status:int,headers:string[],body:string,raw:string} $answer
	 */
	private static function context( array $answer ): string {
		self::drainLog();
		return "Answer:\n" . $answer['raw'] . ( '' !== self::$log ? "\n\nServer log:\n" . self::$log : '' );
	}

	/* ---------------------------------------------------------- requesting */

	/**
	 * One request to the gates, over the built-in server where it started,
	 * else over php-cgi; skipped where neither can run.
	 *
	 * @return array{status:int,headers:string[],body:string,raw:string}
	 */
	private function request( string $method, string $target, ?string $body = null, string $cookie = '' ): array {
		if ( null !== self::$server ) {
			$answer = self::overSocket( self::$server['port'], $method, $target, $body, $cookie );
			if ( null === $answer ) {
				self::drainLog();
				self::fail( 'The built-in server started but gave no answer to ' . $method . ' ' . $target . ".\nServer log:\n" . self::$log );
			}
			return $answer;
		}
		$answer = self::overCgi( $method, $target, $body, $cookie );
		if ( null !== $answer ) {
			return $answer;
		}
		self::markTestSkipped( 'Neither "' . PHP_BINARY . ' -S" nor php-cgi could run the gates of the preview protection on this machine; the mark header is checked live on a site instead.' );
	}

	/**
	 * Start PHP's built-in server with the router on a free port of
	 * 127.0.0.1, with the hash in its environment.
	 *
	 * @return array{proc:resource,pipes:array<int,resource>,port:int}|null Null when it does not start.
	 */
	private static function startServer(): ?array {
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
			dirname( __DIR__ ),
			array_merge( getenv(), array( 'AB_TEST_PREVIEW_HASH' => self::$hash ) )
		);
		if ( ! is_resource( $proc ) ) {
			return null;
		}
		foreach ( $pipes as $pipe ) {
			stream_set_blocking( $pipe, false );
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
			if ( ! proc_get_status( $proc )['running'] ) {
				break;
			}
		}
		self::$server = $server;
		self::stopServer();
		return null;
	}

	/** End the server this class started: its own process, by its handle. */
	private static function stopServer(): void {
		if ( null === self::$server ) {
			return;
		}
		proc_terminate( self::$server['proc'] );
		self::drainLog();
		foreach ( self::$server['pipes'] as $pipe ) {
			fclose( $pipe );
		}
		proc_close( self::$server['proc'] );
		self::$server = null;
	}

	/** Read what the server wrote so far, so that its pipes never fill up. */
	private static function drainLog(): void {
		if ( null === self::$server ) {
			return;
		}
		foreach ( self::$server['pipes'] as $pipe ) {
			$chunk = stream_get_contents( $pipe );
			if ( is_string( $chunk ) ) {
				self::$log .= $chunk;
			}
		}
	}

	/**
	 * One request over a socket, read as it comes off the wire: every header
	 * line as sent, none merged or dropped.
	 *
	 * @return array{status:int,headers:string[],body:string,raw:string}|null Null without an answer.
	 */
	private static function overSocket( int $port, string $method, string $target, ?string $body, string $cookie ): ?array {
		$sock = @stream_socket_client( 'tcp://127.0.0.1:' . $port, $errno, $errstr, 5.0 );
		if ( false === $sock ) {
			return null;
		}
		stream_set_timeout( $sock, 10 );
		$lines = array(
			$method . ' ' . $target . ' HTTP/1.0',
			'Host: 127.0.0.1:' . $port,
			'User-Agent: Mozilla/5.0 (AlphaBridge MCP test)',
			'Connection: close',
		);
		if ( '' !== $cookie ) {
			$lines[] = 'Cookie: ' . $cookie;
		}
		if ( null !== $body ) {
			$lines[] = 'Content-Type: application/x-www-form-urlencoded';
			$lines[] = 'Content-Length: ' . strlen( $body );
		}
		fwrite( $sock, implode( "\r\n", $lines ) . "\r\n\r\n" . (string) $body );
		$raw = (string) stream_get_contents( $sock );
		fclose( $sock );
		self::drainLog();
		return self::parse( $raw, true );
	}

	/**
	 * One request under php-cgi, with the CGI environment a web server sets.
	 *
	 * @return array{status:int,headers:string[],body:string,raw:string}|null Null without a php-cgi.
	 */
	private static function overCgi( string $method, string $target, ?string $body, string $cookie ): ?array {
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
		$env = array(
			'REQUEST_METHOD'       => $method,
			'REQUEST_URI'          => $target,
			'QUERY_STRING'         => (string) parse_url( $target, PHP_URL_QUERY ),
			'SCRIPT_FILENAME'      => self::ROUTER,
			'REDIRECT_STATUS'      => '200',
			'SERVER_PROTOCOL'      => 'HTTP/1.1',
			'AB_TEST_PREVIEW_HASH' => self::$hash,
		);
		if ( '' !== $cookie ) {
			$env['HTTP_COOKIE'] = $cookie;
		}
		if ( null !== $body ) {
			$env['CONTENT_TYPE']   = 'application/x-www-form-urlencoded';
			$env['CONTENT_LENGTH'] = (string) strlen( $body );
		}
		$pipes = array();
		$proc  = proc_open(
			array( $cgi, '-d', 'display_errors=stderr', '-f', self::ROUTER ),
			array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes,
			dirname( __DIR__ ),
			$env
		);
		if ( ! is_resource( $proc ) ) {
			return null;
		}
		fwrite( $pipes[0], (string) $body );
		fclose( $pipes[0] );
		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $proc );
		self::$log .= $err;
		return self::parse( $out, false );
	}

	/**
	 * Status, header lines and body of a raw answer: from a status line over
	 * HTTP, from the Status header under CGI (200 without one).
	 *
	 * @return array{status:int,headers:string[],body:string,raw:string}|null
	 */
	private static function parse( string $raw, bool $status_line ): ?array {
		$end = strpos( $raw, "\r\n\r\n" );
		if ( false === $end ) {
			return null;
		}
		$headers = explode( "\r\n", substr( $raw, 0, $end ) );
		$status  = 200;
		if ( $status_line ) {
			if ( ! preg_match( '#^HTTP/\S+\s+(\d{3})#', (string) array_shift( $headers ), $m ) ) {
				return null;
			}
			$status = (int) $m[1];
		} else {
			foreach ( $headers as $header ) {
				if ( 0 === stripos( $header, 'status:' ) ) {
					$status = (int) trim( substr( $header, strlen( 'status:' ) ) );
				}
			}
		}
		return array(
			'status'  => $status,
			'headers' => $headers,
			'body'    => substr( $raw, $end + 4 ),
			'raw'     => $raw,
		);
	}
}
