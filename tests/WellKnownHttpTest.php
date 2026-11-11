<?php
/**
 * Which well-known documents a site answers, and with what, over real HTTP.
 *
 * MCP clients find the OAuth server of a site through two documents: the
 * protected-resource metadata (RFC 9728) and the authorization-server
 * metadata (RFC 8414). Both may carry a path after their name: the path of
 * the resource or of the issuer they are about. On one domain with
 * AlphaBridge at the root and a second site in a subfolder (a staging copy
 * under /staging/123), the client asks the ROOT for the subfolder site's
 * server: /.well-known/oauth-authorization-server/staging/123. Up to 4.10.0
 * the root answered that with its own metadata, and the client signed in at
 * the wrong site.
 *
 * What must hold:
 *
 * - A path that belongs to the site (none, its home path, its resource path)
 *   gets the same document as before, byte for byte.
 * - A path that belongs to another site on the same domain gets a 307 to the
 *   address where that site serves the document itself, on this site's
 *   scheme and host, never on its own address.
 * - A path that cannot be mapped, or that is not a clean path, gets no
 *   answer from the plugin: WordPress goes on to its 404, without guessing a
 *   permalink.
 * - OAuth switched off: no answer, as before.
 *
 * The site runs under PHP's built-in server with the test bootstrap
 * (support/well-known-server.php); without it the test is skipped and says so.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_OAuth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WellKnownHttpTest extends TestCase {

	private const ROUTER = __DIR__ . '/support/well-known-server.php';
	private const ROOT   = 'https://example.test';
	private const SUB    = 'https://example.test/staging/123';
	private const AS     = 'oauth-authorization-server';
	private const PRM    = 'oauth-protected-resource';

	/** @var resource|null */
	private static $proc = null;
	/** @var array<int,resource> */
	private static array $pipes = array();
	private static int $port    = 0;

	public static function setUpBeforeClass(): void {
		$probe = @stream_socket_server( 'tcp://127.0.0.1:0' );
		if ( false === $probe ) {
			return;
		}
		$port = (int) substr( (string) stream_socket_get_name( $probe, false ), strlen( '127.0.0.1:' ) );
		fclose( $probe );
		$proc = proc_open(
			array( PHP_BINARY, '-d', 'display_errors=stderr', '-S', '127.0.0.1:' . $port, self::ROUTER ),
			array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			self::$pipes,
			dirname( __DIR__ )
		);
		if ( ! is_resource( $proc ) ) {
			return;
		}
		self::$proc = $proc;
		for ( $i = 0; $i < 100; $i++ ) {
			usleep( 50000 );
			$sock = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.2 );
			if ( false !== $sock ) {
				fclose( $sock );
				self::$port = $port;
				return;
			}
		}
	}

	public static function tearDownAfterClass(): void {
		if ( is_resource( self::$proc ) ) {
			proc_terminate( self::$proc );
			foreach ( self::$pipes as $pipe ) {
				fclose( $pipe );
			}
			proc_close( self::$proc );
		}
		self::$proc  = null;
		self::$pipes = array();
		self::$port  = 0;
	}

	protected function setUp(): void {
		ab_test_reset();
		if ( 0 === self::$port ) {
			self::markTestSkipped( '"' . PHP_BINARY . ' -S" could not serve the well-known router on this machine; the discovery is checked against a real site instead.' );
		}
	}

	/* ------------------------------------------------------------ own paths */

	/**
	 * Paths that belong to the site: the answers of 4.10.0, unchanged.
	 *
	 * @return array<string,array{0:string,1:string,2:?string,3:string}> path, home, REST base (null = pretty permalinks), document
	 */
	public static function ownPaths(): array {
		$plain_root = self::ROOT . '/?rest_route=/';
		$plain_sub  = self::SUB . '/?rest_route=/';
		return array(
			'root: server metadata'                               => array( '/.well-known/oauth-authorization-server', self::ROOT, null, self::AS ),
			'root: server metadata, trailing slash'               => array( '/.well-known/oauth-authorization-server/', self::ROOT, null, self::AS ),
			'root: server metadata, with a query'                 => array( '/.well-known/oauth-authorization-server?x=1', self::ROOT, null, self::AS ),
			'root: server metadata after the MCP path (old SDK)'  => array( '/.well-known/oauth-authorization-server/wp-json/alphabridge/v1/mcp', self::ROOT, null, self::AS ),
			'root: resource metadata'                             => array( '/.well-known/oauth-protected-resource', self::ROOT, null, self::PRM ),
			'root: resource metadata, trailing slash'             => array( '/.well-known/oauth-protected-resource/', self::ROOT, null, self::PRM ),
			'root: resource metadata of the MCP path'             => array( '/.well-known/oauth-protected-resource/wp-json/alphabridge/v1/mcp', self::ROOT, null, self::PRM ),
			'root: resource metadata of the MCP path, slash'      => array( '/.well-known/oauth-protected-resource/wp-json/alphabridge/v1/mcp/', self::ROOT, null, self::PRM ),
			'root, plain permalinks: server metadata'             => array( '/.well-known/oauth-authorization-server', self::ROOT, $plain_root, self::AS ),
			'root, plain permalinks: resource metadata, slash'    => array( '/.well-known/oauth-protected-resource/', self::ROOT, $plain_root, self::PRM ),
			'subfolder: server metadata under home'               => array( '/staging/123/.well-known/oauth-authorization-server', self::SUB, null, self::AS ),
			'subfolder: server metadata under home, slash'        => array( '/staging/123/.well-known/oauth-authorization-server/', self::SUB, null, self::AS ),
			'subfolder: server metadata after its own home path'  => array( '/staging/123/.well-known/oauth-authorization-server/staging/123', self::SUB, null, self::AS ),
			'subfolder: resource metadata under home'             => array( '/staging/123/.well-known/oauth-protected-resource', self::SUB, null, self::PRM ),
			'subfolder: resource metadata of its MCP path'        => array( '/staging/123/.well-known/oauth-protected-resource/staging/123/wp-json/alphabridge/v1/mcp', self::SUB, null, self::PRM ),
			'subfolder: resource metadata of its MCP path, slash' => array( '/staging/123/.well-known/oauth-protected-resource/staging/123/wp-json/alphabridge/v1/mcp/', self::SUB, null, self::PRM ),
			'subfolder, plain permalinks: server metadata'        => array( '/staging/123/.well-known/oauth-authorization-server', self::SUB, $plain_sub, self::AS ),
			'subfolder, plain permalinks: resource metadata'      => array( '/staging/123/.well-known/oauth-protected-resource/staging/123/', self::SUB, $plain_sub, self::PRM ),
		);
	}

	#[DataProvider( 'ownPaths' )]
	public function testAnOwnPathGetsTheSameDocument( string $path, string $home, ?string $rest, string $name ): void {
		$answer = $this->request( $path, $home, $rest );

		self::assertSame( 200, $answer['status'], $answer['raw'] );
		self::assertSame( $this->document( $name, $home, $rest ), $answer['body'], 'Byte for byte the document of 4.10.0.' );
	}

	/**
	 * The address the 401 names (WWW-Authenticate resource_metadata) is
	 * served by the site itself, with pretty and with plain permalinks.
	 *
	 * @return array<string,array{0:string,1:?string}>
	 */
	public static function sites(): array {
		return array(
			'root'                        => array( self::ROOT, null ),
			'root, plain permalinks'      => array( self::ROOT, self::ROOT . '/?rest_route=/' ),
			'subfolder'                   => array( self::SUB, null ),
			'subfolder, plain permalinks' => array( self::SUB, self::SUB . '/?rest_route=/' ),
		);
	}

	#[DataProvider( 'sites' )]
	public function testTheAddressInTheChallengeIsServed( string $home, ?string $rest ): void {
		$this->site( $home, $rest );
		$url = AB_MCP_OAuth::resource_metadata_url();
		ab_test_reset();
		self::assertStringStartsWith( self::ROOT . '/', $url );

		$answer = $this->request( substr( $url, strlen( self::ROOT ) ), $home, $rest );

		self::assertSame( 200, $answer['status'], $url . "\n" . $answer['raw'] );
		self::assertSame( $this->document( self::PRM, $home, $rest ), $answer['body'] );
	}

	/* -------------------------------------------------------- foreign paths */

	/**
	 * Paths of another site on the same domain: a 307 to where that site
	 * answers itself.
	 *
	 * @return array<string,array{0:string,1:string,2:?string,3:string}> path, home, REST base, Location
	 */
	public static function foreignPaths(): array {
		$plain_root = self::ROOT . '/?rest_route=/';
		$sub_prm    = self::ROOT . '/staging/123/.well-known/oauth-protected-resource/staging/123/wp-json/alphabridge/v1/mcp';
		return array(
			'root: server metadata of the subfolder issuer'        => array( '/.well-known/oauth-authorization-server/staging/123', self::ROOT, null, self::ROOT . '/staging/123/.well-known/oauth-authorization-server' ),
			'root: server metadata, trailing slash'                => array( '/.well-known/oauth-authorization-server/staging/123/', self::ROOT, null, self::ROOT . '/staging/123/.well-known/oauth-authorization-server' ),
			'root: server metadata with a query (dropped)'         => array( '/.well-known/oauth-authorization-server/staging/123?x=1', self::ROOT, null, self::ROOT . '/staging/123/.well-known/oauth-authorization-server' ),
			'root: path boundary, /staging/1234 is its own issuer' => array( '/.well-known/oauth-authorization-server/staging/1234', self::ROOT, null, self::ROOT . '/staging/1234/.well-known/oauth-authorization-server' ),
			'root: upper case kept as sent'                        => array( '/.well-known/oauth-authorization-server/Staging/123', self::ROOT, null, self::ROOT . '/Staging/123/.well-known/oauth-authorization-server' ),
			'root: resource metadata of the subfolder MCP path'    => array( '/.well-known/oauth-protected-resource/staging/123/wp-json/alphabridge/v1/mcp', self::ROOT, null, $sub_prm ),
			'root: resource metadata, trailing slash'              => array( '/.well-known/oauth-protected-resource/staging/123/wp-json/alphabridge/v1/mcp/', self::ROOT, null, $sub_prm ),
			'root, plain permalinks: server metadata'              => array( '/.well-known/oauth-authorization-server/staging/123', self::ROOT, $plain_root, self::ROOT . '/staging/123/.well-known/oauth-authorization-server' ),
			'root, plain permalinks: resource metadata'            => array( '/.well-known/oauth-protected-resource/staging/123/wp-json/alphabridge/v1/mcp', self::ROOT, $plain_root, $sub_prm ),
			'subfolder: server metadata of another issuer'         => array( '/staging/123/.well-known/oauth-authorization-server/other', self::SUB, null, self::ROOT . '/other/.well-known/oauth-authorization-server' ),
			'subfolder: resource metadata of the root MCP path'    => array( '/staging/123/.well-known/oauth-protected-resource/wp-json/alphabridge/v1/mcp', self::SUB, null, self::ROOT . '/.well-known/oauth-protected-resource/wp-json/alphabridge/v1/mcp' ),
		);
	}

	#[DataProvider( 'foreignPaths' )]
	public function testAForeignPathIsRedirectedOnTheSameDomain( string $path, string $home, ?string $rest, string $location ): void {
		$answer = $this->request( $path, $home, $rest );

		self::assertSame( 307, $answer['status'], $answer['raw'] );
		self::assertSame( $location, $answer['headers']['location'] ?? null, $answer['raw'] );
		self::assertSame( 'no-store', $answer['headers']['cache-control'] ?? null, 'A redirect for one site must not be cached for another.' );
		self::assertSame( '*', $answer['headers']['access-control-allow-origin'] ?? null, 'A browser-based client follows the redirect only with CORS.' );
	}

	#[DataProvider( 'foreignPaths' )]
	public function testTheTargetDoesNotRedirectAgain( string $path, string $home, ?string $rest, string $location ): void {
		$target = (string) wp_parse_url( $location, PHP_URL_PATH );
		self::assertNotSame( (string) wp_parse_url( $path, PHP_URL_PATH ), $target );

		$answer = $this->request( $target, $home, $rest );

		self::assertNotSame( 307, $answer['status'], 'No loop when the target lands on the same site: ' . $answer['raw'] );
	}

	/* ------------------------------------------------------- refused paths */

	/**
	 * Paths of another site that cannot be mapped, and paths that are not
	 * clean: no answer from the plugin, WordPress' 404 without a guessed
	 * permalink.
	 *
	 * @return array<string,array{0:string,1:string}> path, home
	 */
	public static function refusedPaths(): array {
		return array(
			'resource metadata without a known resource path' => array( '/.well-known/oauth-protected-resource/staging/123', self::ROOT ),
			'resource path in another case'                   => array( '/.well-known/oauth-protected-resource/staging/123/WP-JSON/alphabridge/v1/mcp', self::ROOT ),
			'empty segment, never protocol-relative'          => array( '/.well-known/oauth-authorization-server//evil.example', self::ROOT ),
			'encoded dot segment'                             => array( '/.well-known/oauth-authorization-server/%2e%2e/x', self::ROOT ),
			'dot segment'                                     => array( '/.well-known/oauth-authorization-server/x/./y', self::ROOT ),
			'encoded slash'                                   => array( '/.well-known/oauth-authorization-server/a%2Fb', self::ROOT ),
			'encoded backslash'                               => array( '/.well-known/oauth-authorization-server/a%5Cb', self::ROOT ),
			'encoded control character'                       => array( '/.well-known/oauth-authorization-server/a%00b', self::ROOT ),
			'broken percent sign'                             => array( '/.well-known/oauth-authorization-server/a%zzb', self::ROOT ),
			'character outside the redirect set'              => array( '/.well-known/oauth-authorization-server/a$b', self::ROOT ),
			'another well-known name after the name'          => array( '/.well-known/oauth-authorization-server/.well-known/oauth-authorization-server', self::ROOT ),
			'overlong path'                                   => array( '/.well-known/oauth-authorization-server/' . str_repeat( 'a', 600 ), self::ROOT ),
			'subfolder: the root path on the subfolder site'  => array( '/.well-known/oauth-protected-resource/wp-json/alphabridge/v1/mcp', self::SUB ),
		);
	}

	#[DataProvider( 'refusedPaths' )]
	public function testAPathThatCannotBeMappedGetsNoAnswer( string $path, string $home ): void {
		$answer = $this->request( $path, $home, null );

		self::assertSame( 404, $answer['status'], $answer['raw'] );
		self::assertSame( 'wordpress-404', $answer['body'] );
		self::assertSame( 'off', $answer['headers']['x-ab-test-guess'] ?? null, 'WordPress must not answer with a guessed page (HTML 200) instead of the 404.' );
	}

	/* --------------------------------------------------- not ours, OAuth off */

	/**
	 * @return array<string,array{0:string,1:string,2:bool}> path, home, OAuth on
	 */
	public static function notOurs(): array {
		return array(
			'openid-configuration at the root'          => array( '/.well-known/openid-configuration', self::ROOT, true ),
			'openid-configuration after the issuer'     => array( '/.well-known/openid-configuration/staging/123', self::ROOT, true ),
			'openid-configuration under the subfolder'  => array( '/staging/123/.well-known/openid-configuration', self::SUB, true ),
			'the name in another case'                  => array( '/.well-known/OAuth-Authorization-Server', self::ROOT, true ),
			'a longer name'                             => array( '/.well-known/oauth-authorization-serverX', self::ROOT, true ),
			'OAuth off: server metadata'                => array( '/.well-known/oauth-authorization-server', self::ROOT, false ),
			'OAuth off: resource metadata'              => array( '/.well-known/oauth-protected-resource/wp-json/alphabridge/v1/mcp', self::ROOT, false ),
			'OAuth off: server metadata of a subfolder' => array( '/.well-known/oauth-authorization-server/staging/123', self::ROOT, false ),
		);
	}

	#[DataProvider( 'notOurs' )]
	public function testOtherPathsAreLeftToWordPress( string $path, string $home, bool $oauth ): void {
		$answer = $this->request( $path, $home, null, $oauth );

		self::assertSame( 404, $answer['status'], $answer['raw'] );
		self::assertSame( 'wordpress-404', $answer['body'] );
		self::assertSame( 'on', $answer['headers']['x-ab-test-guess'] ?? null, 'Nothing changes for addresses the plugin does not serve.' );
	}

	/* ------------------------------------------------------------- helpers */

	private function site( string $home, ?string $rest ): void {
		ab_test_reset();
		$GLOBALS['ab_test_home_url']  = $home;
		$GLOBALS['ab_test_rest_base'] = $rest ?? $home . '/wp-json/';
	}

	/** The document as the site builds it, encoded as send_json() encodes it. */
	private function document( string $name, string $home, ?string $rest ): string {
		$this->site( $home, $rest );
		$data = self::AS === $name ? AB_MCP_OAuth::metadata() : AB_MCP_OAuth::resource_metadata();
		ab_test_reset();
		return (string) wp_json_encode( $data );
	}

	/**
	 * One raw GET, the path sent exactly as given.
	 *
	 * @return array{status:int,headers:array<string,string>,body:string,raw:string}
	 */
	private function request( string $path, string $home, ?string $rest, bool $oauth = true ): array {
		$sock = fsockopen( '127.0.0.1', self::$port, $errno, $errstr, 5.0 );
		self::assertNotFalse( $sock, $errstr );
		$lines = array(
			'GET ' . $path . ' HTTP/1.0',
			'Host: example.test',
			'X-AB-Test-Home: ' . $home,
		);
		if ( null !== $rest ) {
			$lines[] = 'X-AB-Test-Rest: ' . $rest;
		}
		if ( ! $oauth ) {
			$lines[] = 'X-AB-Test-OAuth: off';
		}
		fwrite( $sock, implode( "\r\n", $lines ) . "\r\n\r\n" );
		stream_set_timeout( $sock, 10 );
		$raw = (string) stream_get_contents( $sock );
		fclose( $sock );

		$split   = strpos( $raw, "\r\n\r\n" );
		$head    = false === $split ? $raw : substr( $raw, 0, $split );
		$body    = false === $split ? '' : substr( $raw, $split + 4 );
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
			'body'    => $body,
			'raw'     => $path . "\n" . $raw,
		);
	}
}
