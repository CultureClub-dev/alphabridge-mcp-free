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
 * (and main up to e6220a6) the root answered that with its own metadata, and
 * the client signed in at the wrong site. A redirect does not help every client (Claude's servers do
 * not follow it), so the root fetches the other site's document and relays
 * it, when it is exactly the document asked for (hotfix 4.10.1, carried into
 * main: release/4.10 = ed880e5).
 *
 * What must hold:
 *
 * - A path that belongs to the site (none, its home path, its resource path)
 *   gets the same document as main gave before the hotfix came in (recorded
 *   at e6220a6, tests/fixtures/well-known-main.json), byte for byte, and
 *   nothing is fetched.
 * - A path that names another site on the host gets that site's document:
 *   fetched from <host><path>/.well-known/… with 3 s, no redirects, 64 KiB at
 *   most, and relayed with the fields AlphaBridge gives it.
 * - Every failure of that fetch, every document that is not exactly the one
 *   asked for, and every path that is not clean: a 404 the plugin sets
 *   itself, with canonical redirects and guessed permalinks switched off,
 *   never the site's own metadata.
 * - A fetch never makes the site fetch again.
 * - OAuth switched off and other names: nothing changes.
 *
 * The site runs under PHP's built-in server with the test bootstrap
 * (support/well-known-server.php); without it the test is skipped and says
 * so. How fetched documents are kept is in WellKnownRelayCacheTest; the same
 * addresses on a site with the preview protection on in
 * PreviewWellKnownHttpTest.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_OAuth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/well-known-docroot.php';
require_once __DIR__ . '/support/well-known-challenge.php';

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
	/** The web directory on disk: a WordPress under each path the tests fetch for. */
	private static string $docroot = '';

	public static function setUpBeforeClass(): void {
		self::$docroot = ab_test_wk_dir( array( '/staging/123', '/staging/1234', '/Staging/123', '/~a/b-c/d_e/f.g', '/other' ) );
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
		ab_test_wk_remove( self::$docroot );
	}

	protected function setUp(): void {
		ab_test_reset();
		if ( 0 === self::$port ) {
			self::markTestSkipped( '"' . PHP_BINARY . ' -S" could not serve the well-known router on this machine; the discovery is checked against a real site instead.' );
		}
	}

	/* ------------------------------------------------------------ own paths */

	/**
	 * Paths that belong to the site: the answers of main before the hotfix,
	 * unchanged.
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
	public function testAnOwnPathGetsTheSameAnswerAsMainBefore( string $path, string $home, ?string $rest, string $name ): void {
		$answer = $this->request( $path, $home, $rest );

		$this->assertAsInMain( self::recorded()['own'][ $this->dataName() ] ?? null, $answer );
		self::assertArrayNotHasKey( 'x-ab-test-fetches', $answer['headers'], 'Its own document is never fetched.' );
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
	public function testThe401OfTheEndpointIsAsInMainBefore( string $home, ?string $rest ): void {
		$recorded = self::recorded()['challenge'][ $this->dataName() ] ?? null;
		self::assertNotNull( $recorded );

		$answer = ab_test_wk_challenge( $home, $rest );

		self::assertSame( 401, $answer['status'] );
		self::assertSame( $recorded['status'], $answer['status'] );
		ksort( $answer['headers'] );
		self::assertSame( $recorded['headers'], $answer['headers'], 'WWW-Authenticate, Cache-Control and Referrer-Policy as main sent them before.' );
	}

	#[DataProvider( 'sites' )]
	public function testTheAddressInTheChallengeIsServedAsInMainBefore( string $home, ?string $rest ): void {
		$header = ab_test_wk_challenge( $home, $rest )['headers']['www-authenticate'] ?? '';
		self::assertSame( 1, preg_match( '/resource_metadata="([^"]+)"/', $header, $m ), $header );
		self::assertStringStartsWith( self::ROOT . '/', $m[1] );

		$answer = $this->request( substr( $m[1], strlen( self::ROOT ) ), $home, $rest );

		$this->assertAsInMain( self::recorded()['challenge'][ $this->dataName() ]['answer'] ?? null, $answer );
	}

	/* ------------------------------------------------- another site's paths */

	/**
	 * Paths of another site on the same host: that site's document, fetched
	 * from where it answers itself.
	 *
	 * @return array<string,array{0:string,1:string,2:?string,3:string,4:string,5:string}> path, home, REST base, fetched URL, other site's home, document
	 */
	public static function otherSitePaths(): array {
		$plain_root = self::ROOT . '/?rest_route=/';
		$sub_as     = self::ROOT . '/staging/123/.well-known/oauth-authorization-server';
		$sub_prm    = self::ROOT . '/staging/123/.well-known/oauth-protected-resource/staging/123/wp-json/alphabridge/v1/mcp';
		return array(
			'root: server metadata of the subfolder issuer'           => array( '/.well-known/oauth-authorization-server/staging/123', self::ROOT, null, $sub_as, self::SUB, self::AS ),
			'root: server metadata, trailing slash'                   => array( '/.well-known/oauth-authorization-server/staging/123/', self::ROOT, null, $sub_as, self::SUB, self::AS ),
			'root: server metadata with a query (dropped)'            => array( '/.well-known/oauth-authorization-server/staging/123?x=1', self::ROOT, null, $sub_as, self::SUB, self::AS ),
			'root: path boundary, /staging/1234 is its own issuer'    => array( '/.well-known/oauth-authorization-server/staging/1234', self::ROOT, null, self::ROOT . '/staging/1234/.well-known/oauth-authorization-server', self::ROOT . '/staging/1234', self::AS ),
			'root: upper case kept as sent'                           => array( '/.well-known/oauth-authorization-server/Staging/123', self::ROOT, null, self::ROOT . '/Staging/123/.well-known/oauth-authorization-server', self::ROOT . '/Staging/123', self::AS ),
			'root: four segments, ~ and -'                            => array( '/.well-known/oauth-authorization-server/~a/b-c/d_e/f.g', self::ROOT, null, self::ROOT . '/~a/b-c/d_e/f.g/.well-known/oauth-authorization-server', self::ROOT . '/~a/b-c/d_e/f.g', self::AS ),
			'root: resource metadata of the subfolder MCP path'       => array( '/.well-known/oauth-protected-resource/staging/123/wp-json/alphabridge/v1/mcp', self::ROOT, null, $sub_prm, self::SUB, self::PRM ),
			'root: resource metadata, trailing slash'                 => array( '/.well-known/oauth-protected-resource/staging/123/wp-json/alphabridge/v1/mcp/', self::ROOT, null, $sub_prm, self::SUB, self::PRM ),
			'root, plain permalinks: server metadata'                 => array( '/.well-known/oauth-authorization-server/staging/123', self::ROOT, $plain_root, $sub_as, self::SUB, self::AS ),
			'root, plain permalinks: resource metadata'               => array( '/.well-known/oauth-protected-resource/staging/123/wp-json/alphabridge/v1/mcp', self::ROOT, $plain_root, $sub_prm, self::SUB, self::PRM ),
			'subfolder: server metadata of another issuer'            => array( '/staging/123/.well-known/oauth-authorization-server/other', self::SUB, null, self::ROOT . '/other/.well-known/oauth-authorization-server', self::ROOT . '/other', self::AS ),
			'root with http: fetched with the scheme of home'         => array( '/.well-known/oauth-authorization-server/staging/123', 'http://example.test', null, 'http://example.test/staging/123/.well-known/oauth-authorization-server', 'http://example.test/staging/123', self::AS ),
			'root on a port: fetched on that port'                    => array( '/.well-known/oauth-authorization-server/staging/123', 'http://example.test:8461', null, 'http://example.test:8461/staging/123/.well-known/oauth-authorization-server', 'http://example.test:8461/staging/123', self::AS ),
		);
	}

	#[DataProvider( 'otherSitePaths' )]
	public function testAnotherSitesPathGetsThatSitesDocument( string $path, string $home, ?string $rest, string $url, string $other, string $name ): void {
		$answer = $this->request( $path, $home, $rest );

		self::assertSame( 200, $answer['status'], $answer['raw'] );
		self::assertSame( $this->document( $name, $other, null ), $answer['body'], 'The other site\'s document, as it sends it.' );
		self::assertSame( '1', $answer['headers']['x-ab-test-fetches'] ?? null, 'Fetched once: ' . $answer['raw'] );
		self::assertSame( $url, $answer['headers']['x-ab-test-fetch-url'] ?? null );
		self::assertSame(
			array(
				'timeout'             => 3,
				'redirection'         => 0,
				'limit_response_size' => 65536,
				'headers'             => array( 'Accept' => 'application/json' ),
			),
			json_decode( $answer['headers']['x-ab-test-fetch-args'] ?? '', true ),
			'3 s, no redirects, 64 KiB at most.'
		);
	}

	public function testOnlyTheFieldsAlphaBridgeGivesAreRelayed(): void {
		$answer = $this->request( '/.well-known/oauth-authorization-server/staging/123', self::ROOT, null, true, 'extra-field' );

		self::assertSame( 200, $answer['status'], $answer['raw'] );
		self::assertSame( $this->document( self::AS, self::SUB, null ), $answer['body'] );
		self::assertStringNotContainsString( 'x_extra', $answer['body'] );
	}

	public function testADocumentJustBelowTheLimitIsRelayedWithoutThePadding(): void {
		$answer = $this->request( '/.well-known/oauth-authorization-server/staging/123', self::ROOT, null, true, 'below-limit' );

		self::assertSame( 200, $answer['status'], substr( $answer['raw'], 0, 400 ) );
		self::assertSame( $this->document( self::AS, self::SUB, null ), $answer['body'] );
	}

	/* ------------------------------------------------------ failed fetches */

	/**
	 * Every way the fetch can fail, and every document that is not exactly
	 * the one asked for: a 404, never the site's own metadata.
	 *
	 * @return array<string,array{0:string,1:string}> path, how the other site answers
	 */
	public static function failedFetches(): array {
		$as  = '/.well-known/oauth-authorization-server/staging/123';
		$prm = '/.well-known/oauth-protected-resource/staging/123/wp-json/alphabridge/v1/mcp';
		return array(
			'request to itself blocked (host, firewall)'    => array( $as, 'blocked' ),
			'time limit'                                    => array( $as, 'timeout' ),
			'https home, the server cannot reach itself'    => array( $as, 'tls' ),
			'no site there (404)'                           => array( $as, 'not-found' ),
			'server error'                                  => array( $as, 'server-error' ),
			'redirect, not followed'                        => array( $as, 'redirect' ),
			'HTML with 200'                                 => array( $as, 'html' ),
			'JSON not sent as application/json'             => array( $as, 'json-as-text' ),
			'broken JSON'                                   => array( $as, 'broken-json' ),
			'a JSON list'                                   => array( $as, 'json-list' ),
			'very large answer (cut at the limit)'          => array( $as, 'too-big' ),
			'an answer exactly at the limit'                => array( $as, 'at-limit' ),
			'another issuer'                                => array( $as, 'foreign-issuer' ),
			'the issuer with a trailing slash'              => array( $as, 'issuer-with-slash' ),
			'an endpoint on another host'                   => array( $as, 'foreign-endpoint' ),
			'an endpoint outside the issuer'                => array( $as, 'endpoint-outside-the-site' ),
			'no registration endpoint (not AlphaBridge)'    => array( $as, 'no-registration' ),
			'no PKCE S256'                                  => array( $as, 'no-pkce' ),
			'an endpoint with ../.. (outside after normalising)' => array( $as, 'endpoint-dotdot' ),
			'an endpoint with %2e%2e/%2e%2e'                => array( $as, 'endpoint-encoded-dotdot' ),
			'an endpoint with a . segment'                  => array( $as, 'endpoint-dot-segment' ),
			'an endpoint with an empty segment'             => array( $as, 'endpoint-empty-segment' ),
			'an endpoint with a fragment'                   => array( $as, 'endpoint-fragment' ),
			'resource metadata: request to itself blocked'  => array( $prm, 'blocked' ),
			'resource metadata: another resource'           => array( $prm, 'foreign-resource' ),
			'resource metadata: a second server'            => array( $prm, 'two-servers' ),
			'resource metadata: HTML with 200'              => array( $prm, 'html' ),
		);
	}

	#[DataProvider( 'failedFetches' )]
	public function testAFailedFetchGetsA404( string $path, string $mode ): void {
		$answer = $this->request( $path, self::ROOT, null, true, $mode );

		$this->assertRefused( $answer );
		self::assertSame( '1', $answer['headers']['x-ab-test-fetches'] ?? null, 'Fetched once, then refused: ' . $answer['raw'] );
	}

	/* ------------------------------------------------------- refused paths */

	/**
	 * Paths that are not ours and cannot be fetched: no fetch at all.
	 *
	 * @return array<string,array{0:string,1:string,2:?string}> path, home, REST base
	 */
	public static function refusedPaths(): array {
		$plain_sub = self::SUB . '/?rest_route=/';
		return array(
			'resource metadata without a known resource path'   => array( '/.well-known/oauth-protected-resource/staging/123', self::ROOT, null ),
			'resource path in another case'                     => array( '/.well-known/oauth-protected-resource/staging/123/WP-JSON/alphabridge/v1/mcp', self::ROOT, null ),
			'empty segment, never protocol-relative'            => array( '/.well-known/oauth-authorization-server//evil.example', self::ROOT, null ),
			'two slashes at the end of another site\'s path'    => array( '/.well-known/oauth-authorization-server/staging/123//', self::ROOT, null ),
			'two slashes after the resource path'               => array( '/.well-known/oauth-protected-resource/staging/123/wp-json/alphabridge/v1/mcp//', self::ROOT, null ),
			'two slashes after the name (main: own metadata)'   => array( '/.well-known/oauth-authorization-server//', self::ROOT, null ),
			'two slashes after its own resource path'           => array( '/.well-known/oauth-protected-resource/wp-json/alphabridge/v1/mcp//', self::ROOT, null ),
			'encoded dot segment'                               => array( '/.well-known/oauth-authorization-server/%2e%2e/x', self::ROOT, null ),
			'any percent sign'                                  => array( '/.well-known/oauth-authorization-server/a%41b', self::ROOT, null ),
			'dot segment'                                       => array( '/.well-known/oauth-authorization-server/x/./y', self::ROOT, null ),
			'two dots inside a segment'                         => array( '/.well-known/oauth-authorization-server/a..b', self::ROOT, null ),
			'a segment starting with a dot'                     => array( '/.well-known/oauth-authorization-server/.hidden', self::ROOT, null ),
			'.well-known after the name: no fetch of a fetch'   => array( '/.well-known/oauth-authorization-server/.well-known/oauth-authorization-server/x', self::ROOT, null ),
			'five segments'                                     => array( '/.well-known/oauth-authorization-server/a/b/c/d/e', self::ROOT, null ),
			'character outside the set'                         => array( '/.well-known/oauth-authorization-server/a$b', self::ROOT, null ),
			'encoded backslash'                                 => array( '/.well-known/oauth-authorization-server/a%5Cb', self::ROOT, null ),
			'resource metadata of the root on a subfolder site' => array( '/staging/123/.well-known/oauth-protected-resource/wp-json/alphabridge/v1/mcp', self::SUB, null ),
			'no WordPress on disk under the path'               => array( '/.well-known/oauth-authorization-server/nowp/9', self::ROOT, null ),
			'no WordPress on disk, resource metadata'           => array( '/.well-known/oauth-protected-resource/nowp/9/wp-json/alphabridge/v1/mcp', self::ROOT, null ),
			'a directory without wp-load.php'                   => array( '/.well-known/oauth-authorization-server/staging', self::ROOT, null ),
			'its own home as the other site (plain permalinks)' => array( '/staging/123/.well-known/oauth-protected-resource/staging/123/wp-json/alphabridge/v1/mcp', self::SUB, $plain_sub ),
		);
	}

	#[DataProvider( 'refusedPaths' )]
	public function testAPathThatCannotBeFetchedGetsA404WithoutAFetch( string $path, string $home, ?string $rest ): void {
		$answer = $this->request( $path, $home, $rest );

		$this->assertRefused( $answer );
		self::assertArrayNotHasKey( 'x-ab-test-fetches', $answer['headers'], 'Nothing fetched: ' . $answer['raw'] );
	}

	/**
	 * What the site fetches is never one of its well-known paths again: the
	 * fetched address, requested from the site itself, does not make it fetch.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function fetchedAddresses(): array {
		$out = array();
		foreach ( self::otherSitePaths() as $label => $case ) {
			if ( self::ROOT === $case[1] ) {
				$out[ $label ] = array( $case[3] );
			}
		}
		return $out;
	}

	#[DataProvider( 'fetchedAddresses' )]
	public function testAFetchedAddressDoesNotFetchAgain( string $url ): void {
		$answer = $this->request( substr( $url, strlen( self::ROOT ) ), self::ROOT, null );

		self::assertArrayNotHasKey( 'x-ab-test-fetches', $answer['headers'], $answer['raw'] );
		self::assertSame( 'wordpress', $answer['body'], 'Not a well-known address of this site: left to WordPress.' );
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

		self::assertSame( 'wordpress', $answer['body'], $answer['raw'] );
		self::assertSame( 'unset', $answer['headers']['x-ab-test-status'] ?? null, 'The plugin sets no status.' );
		self::assertSame( 'on', $answer['headers']['x-ab-test-canonical'] ?? null );
		self::assertSame( 'on', $answer['headers']['x-ab-test-guess'] ?? null, 'Nothing changes for addresses the plugin does not serve.' );
		self::assertArrayNotHasKey( 'x-ab-test-fetches', $answer['headers'] );
	}

	/* ------------------------------------------------------------- helpers */

	/**
	 * A refusal: the 404 set by the plugin, WordPress neither redirecting nor
	 * guessing a permalink, and never the site's own metadata.
	 *
	 * @param array{status:int,headers:array<string,string>,body:string,raw:string} $answer
	 */
	private function assertRefused( array $answer ): void {
		self::assertSame( 404, $answer['status'], $answer['raw'] );
		self::assertSame( '404', $answer['headers']['x-ab-test-status'] ?? null, 'The plugin sets the 404 itself.' );
		self::assertSame( 'off', $answer['headers']['x-ab-test-canonical'] ?? null, 'No canonical redirect for this request.' );
		self::assertSame( 'off', $answer['headers']['x-ab-test-guess'] ?? null, 'No guessed permalink (HTML 200) instead of the 404.' );
		self::assertSame( 'wordpress', $answer['body'], 'Not the site\'s own metadata.' );
	}

	/**
	 * What main answered before the hotfix, recorded with
	 * tests/support/record-well-known-main.php against includes/class-oauth.php
	 * and includes/class-preview-protection.php of e6220a6: status, the
	 * headers that matter and the body of each own path, and per site the
	 * 401 of the MCP endpoint (support/well-known-challenge.php) with the
	 * answer at the address its WWW-Authenticate names.
	 *
	 * @return array{own:array<string,array>,challenge:array<string,array>,protected:array<string,array>}
	 */
	public static function recorded(): array {
		static $recorded = null;
		if ( null === $recorded ) {
			$recorded = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/well-known-main.json' ), true );
		}
		return $recorded;
	}

	/**
	 * Status, Content-Type, Cache-Control, CORS and body as main sent them
	 * before the hotfix.
	 *
	 * @param array|null                                                                 $recorded
	 * @param array{status:int,headers:array<string,string>,body:string,raw:string} $answer
	 */
	private function assertAsInMain( ?array $recorded, array $answer ): void {
		self::assertNotNull( $recorded, 'Recorded for main: ' . $this->dataName() );
		self::assertSame( $recorded['status'], $answer['status'], $answer['raw'] );
		foreach ( array( 'content-type', 'cache-control', 'access-control-allow-origin' ) as $header ) {
			self::assertSame( $recorded['headers'][ $header ] ?? null, $answer['headers'][ $header ] ?? null, $header );
		}
		self::assertSame( $recorded['body'], $answer['body'], 'Byte for byte the document of main before the hotfix.' );
	}

	private function site( string $home, ?string $rest ): void {
		ab_test_reset();
		$GLOBALS['ab_test_home']      = $home;
		$GLOBALS['ab_test_rest_base'] = $rest ?? $home . '/wp-json/';
	}

	/** The document as a site builds it, encoded as send_json() encodes it. */
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
	private function request( string $path, string $home, ?string $rest, bool $oauth = true, string $fetch = 'site' ): array {
		$sock = fsockopen( '127.0.0.1', self::$port, $errno, $errstr, 5.0 );
		self::assertNotFalse( $sock, $errstr );
		$lines = array(
			'GET ' . $path . ' HTTP/1.0',
			'Host: example.test',
			'X-AB-Test-Home: ' . $home,
			'X-AB-Test-Fetch: ' . $fetch,
			// The site's core lies at <web directory><home path>.
			'X-AB-Test-Abspath: ' . self::$docroot . rtrim( (string) parse_url( $home, PHP_URL_PATH ), '/' ) . '/',
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
			'raw'     => $path . "\n" . substr( $raw, 0, 2000 ),
		);
	}
}
