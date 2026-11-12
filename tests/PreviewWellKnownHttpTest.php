<?php
/**
 * The well-known documents on a site with the preview protection on, over
 * real HTTP (point 2 of the hotfix 4.10.1 in main).
 *
 * The protection answers a visitor's request on parse_request itself and
 * ends it; the documents below /.well-known/ are among what a visitor may
 * have, so the gate hands them to the plugin's OAuth handler. Since the
 * hotfix that handler gives one of three answers (AB_MCP_OAuth::
 * well_known_answer()): its own document, the relayed document of another
 * site on the domain, or a refusal. The gate and the handler carry out the
 * same answer (AB_MCP_OAuth::respond_well_known()). What must hold:
 *
 * - Own and relayed documents (public by RFC 8414 and RFC 9728) are served
 *   to a visitor at once and the request ends: no later handler prints
 *   anything (a Yoast-like sitemap on pre_get_posts for ?sitemap=1, a
 *   WooCommerce-like dispatcher on template_redirect at 0 for ?wc-ajax=).
 * - A refusal ends a visitor's request at once with a 404 without content:
 *   no theme (its 404 page has menus and sidebars the protection hides), no
 *   later handler, no password page.
 * - Everything else stays with the protection's decision as before (the
 *   password page with 401), and signed-in users and cookie holders pass to
 *   the handler as without the protection.
 * - The headers the gate sends on every answer (no-cache and X-Robots-Tag)
 *   are unchanged; what main answered at its own addresses before the hotfix
 *   (recorded at e6220a6, tests/fixtures/well-known-main.json) is answered
 *   byte for byte.
 *
 * The site runs under PHP's built-in server with the test bootstrap
 * (support/preview-well-known-server.php, which runs parse_request,
 * pre_get_posts and template_redirect as WP::main() does); without it the
 * test is skipped and says so.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_OAuth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/well-known-docroot.php';

final class PreviewWellKnownHttpTest extends TestCase {

	private const ROUTER = __DIR__ . '/support/preview-well-known-server.php';
	private const ROOT   = 'https://example.test';
	private const SUB    = 'https://example.test/staging/123';
	private const AS     = '/.well-known/oauth-authorization-server';
	private const PRM    = '/.well-known/oauth-protected-resource';
	private const WC     = 'wc-ajax=get_refreshed_fragments';

	/** The headers compared with what main sent before the hotfix. */
	public const KEEP = array( 'access-control-allow-origin', 'cache-control', 'content-type', 'expires', 'x-ab-mcp', 'x-robots-tag' );

	/** @var resource|null */
	private static $proc = null;
	/** @var array<int,resource> */
	private static array $pipes = array();
	private static int $port    = 0;
	/** The web directory on disk: a WordPress under /staging/123. */
	private static string $docroot = '';

	public static function setUpBeforeClass(): void {
		self::$docroot = ab_test_wk_dir( array( '/staging/123' ) );
		$probe         = @stream_socket_server( 'tcp://127.0.0.1:0' );
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
			self::markTestSkipped( '"' . PHP_BINARY . ' -S" could not serve the router on this machine; the protection is checked against a real site instead.' );
		}
	}

	/* ------------------------------------------------- as main answered */

	/**
	 * Answers with the protection on that the hotfix must leave as they were
	 * (recorded at e6220a6): own documents for a visitor (also with a
	 * sitemap or wc-ajax parameter), for a signed-in user and a cookie
	 * holder, and addresses that are not the plugin's.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:bool}> path, home, who, OAuth on
	 */
	public static function protectedCases(): array {
		return array(
			'visitor: server metadata'                          => array( self::AS, self::ROOT, 'visitor', true ),
			'visitor: server metadata with sitemap'             => array( self::AS . '?sitemap=1', self::ROOT, 'visitor', true ),
			'visitor: server metadata with wc-ajax'             => array( self::AS . '?' . self::WC, self::ROOT, 'visitor', true ),
			'visitor: resource metadata'                        => array( self::PRM . '/wp-json/alphabridge/v1/mcp', self::ROOT, 'visitor', true ),
			'visitor: resource metadata with sitemap, wc-ajax'  => array( self::PRM . '/wp-json/alphabridge/v1/mcp?sitemap=1&' . self::WC, self::ROOT, 'visitor', true ),
			'visitor, subfolder: server metadata under home'    => array( '/staging/123' . self::AS, self::SUB, 'visitor', true ),
			'visitor, subfolder: resource metadata of its path' => array( '/staging/123' . self::PRM . '/staging/123/wp-json/alphabridge/v1/mcp', self::SUB, 'visitor', true ),
			'signed in: server metadata'                        => array( self::AS, self::ROOT, 'logged-in', true ),
			'cookie: resource metadata'                         => array( self::PRM . '/wp-json/alphabridge/v1/mcp', self::ROOT, 'cookie', true ),
			'visitor: another document below .well-known'      => array( '/.well-known/alphabridge.json', self::ROOT, 'visitor', true ),
			'visitor: a longer name'                            => array( self::AS . '-x', self::ROOT, 'visitor', true ),
			'visitor, sign-in off: server metadata'             => array( self::AS, self::ROOT, 'visitor', false ),
			'visitor, sign-in off: with sitemap'                => array( self::AS . '?sitemap=1', self::ROOT, 'visitor', false ),
		);
	}

	#[DataProvider( 'protectedCases' )]
	public function testWhatMainAnsweredWithTheProtectionOnStaysTheSame( string $path, string $home, string $who, bool $oauth ): void {
		$recorded = WellKnownHttpTest::recorded()['protected'][ $this->dataName() ] ?? null;
		self::assertNotNull( $recorded, 'Recorded for main: ' . $this->dataName() );

		$answer = $this->request( $path, self::lines( $home, $who, self::$docroot, 'site', $oauth ) );

		self::assertSame( $recorded['status'], $answer['status'], $answer['raw'] );
		self::assertSame( $recorded['headers'], $answer['kept'], 'No-cache, X-Robots-Tag, CORS and the mark as main sent them.' );
		self::assertSame( $recorded['body'], $answer['body'], 'Byte for byte as main.' );
		self::assertSame( '0', $answer['headers']['x-ab-test-fetches'] ?? null, 'Nothing fetched for these.' );
		self::assertStringNotContainsString( 'LATE-', $answer['body'] );
	}

	/* ------------------------------------------ another site's document */

	/**
	 * @return array<string,array{0:string,1:string}> path, document name
	 */
	public static function relayedCases(): array {
		$prm = self::PRM . '/staging/123/wp-json/alphabridge/v1/mcp';
		return array(
			'server metadata of the subfolder site'           => array( self::AS . '/staging/123', 'oauth-authorization-server' ),
			'with sitemap'                                    => array( self::AS . '/staging/123?sitemap=1', 'oauth-authorization-server' ),
			'with wc-ajax'                                    => array( self::AS . '/staging/123?' . self::WC, 'oauth-authorization-server' ),
			'resource metadata of the subfolder site'         => array( $prm, 'oauth-protected-resource' ),
			'resource metadata with sitemap and wc-ajax'      => array( $prm . '?sitemap=1&' . self::WC, 'oauth-protected-resource' ),
		);
	}

	#[DataProvider( 'relayedCases' )]
	public function testAnotherSitesDocumentIsServedToAVisitorAndEndsTheRequest( string $path, string $name ): void {
		$answer = $this->request( $path, self::lines( self::ROOT, 'visitor', self::$docroot ) );

		self::assertSame( 200, $answer['status'], $answer['raw'] );
		self::assertSame( self::document( $name, self::SUB ), $answer['body'], 'The other site\'s document and nothing else: ' . $answer['raw'] );
		self::assertSame( '1', $answer['headers']['x-ab-test-fetches'] ?? null );
		self::assertSame( self::ownDocumentHeaders(), $answer['kept'], 'The headers of an own document on this site, as main sent them.' );
	}

	/* ---------------------------------------------------------- refusals */

	/**
	 * @return array<string,array{0:string,1:string,2:string}> path, how the other site answers, fetches
	 */
	public static function refusedCases(): array {
		return array(
			'no WordPress on disk under the path'           => array( self::AS . '/nowp/9', 'site', '0' ),
			'no WordPress on disk, with sitemap'            => array( self::AS . '/nowp/9?sitemap=1', 'site', '0' ),
			'no WordPress on disk, with wc-ajax'            => array( self::AS . '/nowp/9?' . self::WC, 'site', '0' ),
			'request to itself blocked'                     => array( self::AS . '/staging/123', 'blocked', '1' ),
			'request to itself blocked, sitemap and wc-ajax' => array( self::AS . '/staging/123?sitemap=1&' . self::WC, 'blocked', '1' ),
			'another issuer'                                => array( self::AS . '/staging/123', 'foreign-issuer', '1' ),
			'resource metadata without a resource path'     => array( self::PRM . '/staging/123', 'site', '0' ),
			'two slashes after the name'                    => array( self::AS . '//', 'site', '0' ),
			'.well-known after the name'                    => array( self::AS . '/.well-known/oauth-authorization-server/x', 'site', '0' ),
		);
	}

	#[DataProvider( 'refusedCases' )]
	public function testARefusalEndsAVisitorsRequestWithA404WithoutContent( string $path, string $fetch, string $fetches ): void {
		$answer = $this->request( $path, self::lines( self::ROOT, 'visitor', self::$docroot, $fetch ) );

		self::assertSame( 404, $answer['status'], $answer['raw'] );
		self::assertSame( '', $answer['body'], 'No content: neither the theme nor the password page nor a later handler.' );
		self::assertSame( $fetches, $answer['headers']['x-ab-test-fetches'] ?? null );
		$page = WellKnownHttpTest::recorded()['protected']['visitor: another document below .well-known']['headers'];
		foreach ( array( 'cache-control', 'expires', 'x-robots-tag' ) as $header ) {
			self::assertSame( $page[ $header ], $answer['kept'][ $header ] ?? null, $header . ' as the gate sends it on every answer.' );
		}
		self::assertArrayNotHasKey( 'x-ab-mcp', $answer['kept'], 'Not the password page.' );
		self::assertArrayNotHasKey( 'access-control-allow-origin', $answer['kept'], 'Not a document.' );
	}

	public function testOnTheSecondLineARefusalEndsTheSameWay(): void {
		// A plugin filters do_parse_request to false: no parse_request, the
		// handler at template_redirect 0 runs first and only sets its 404;
		// the gate at 1 then ends the request the same way.
		$answer = $this->request( self::AS . '/nowp/9', array_merge( self::lines( self::ROOT, 'visitor', self::$docroot ), array( 'X-AB-Test-No-Parse: 1' ) ) );

		self::assertSame( 404, $answer['status'], $answer['raw'] );
		self::assertSame( '', $answer['body'] );
		self::assertSame( 'off', $answer['headers']['x-ab-test-guess'] ?? null, 'The handler ran first.' );

		$answer = $this->request( self::AS . '/staging/123', array_merge( self::lines( self::ROOT, 'visitor', self::$docroot ), array( 'X-AB-Test-No-Parse: 1' ) ) );

		self::assertSame( 200, $answer['status'], $answer['raw'] );
		self::assertSame( self::document( 'oauth-authorization-server', self::SUB ), $answer['body'] );
	}

	/* ------------------------------------- the rest stays as it was */

	public function testWithTheSignInOffAnotherSitesPathIsThePasswordPage(): void {
		$answer = $this->request( self::AS . '/staging/123', self::lines( self::ROOT, 'visitor', self::$docroot, 'site', false ) );

		self::assertSame( 401, $answer['status'], $answer['raw'] );
		self::assertSame( 'preview-protection', $answer['kept']['x-ab-mcp'] ?? null );
		self::assertSame( '0', $answer['headers']['x-ab-test-fetches'] ?? null );
	}

	/**
	 * Signed-in users and cookie holders pass the gate; the handler answers
	 * them on template_redirect as on a site without the protection.
	 *
	 * @return array<string,array{0:string,1:string,2:int,3:string}> who, path, status, body
	 */
	public static function passing(): array {
		$doc = 'document';
		return array(
			'signed in: another site\'s document' => array( 'logged-in', self::AS . '/staging/123', 200, $doc ),
			'cookie: another site\'s document'    => array( 'cookie', self::AS . '/staging/123', 200, $doc ),
			'signed in: refused'                  => array( 'logged-in', self::AS . '/nowp/9', 404, 'THEME' ),
			'cookie: refused'                     => array( 'cookie', self::AS . '/nowp/9', 404, 'THEME' ),
		);
	}

	#[DataProvider( 'passing' )]
	public function testSignedInUsersAndCookieHoldersGetWhatTheHandlerGivesEveryone( string $who, string $path, int $status, string $body ): void {
		$answer = $this->request( $path, self::lines( self::ROOT, $who, self::$docroot ) );

		self::assertSame( $status, $answer['status'], $answer['raw'] );
		self::assertSame( 'document' === $body ? self::document( 'oauth-authorization-server', self::SUB ) : $body, $answer['body'] );
		if ( 404 === $status ) {
			self::assertSame( 'off', $answer['headers']['x-ab-test-canonical'] ?? null, 'The refusal of the handler: no canonical redirect.' );
			self::assertSame( 'off', $answer['headers']['x-ab-test-guess'] ?? null, 'No guessed permalink.' );
		}
	}

	/**
	 * Signed-in users and cookie holders pass the gate on parse_request,
	 * which has decided already; the handler on template_redirect and the
	 * gate's second line ask again. They carry out the answer of this
	 * request, not one decided anew: with the persistent cache failing
	 * (every read and write gives false) and the other site answering only
	 * the first fetch, the document of that one fetch is served, not a 404
	 * (Codex round 1 of the port, B1).
	 *
	 * @return array<string,array{0:string,1:array<int,string>}> who, extra header lines
	 */
	public static function passingWithoutCache(): array {
		return array(
			'signed in'       => array( 'logged-in', array() ),
			'with the cookie' => array( 'cookie', array() ),
		);
	}

	#[DataProvider( 'passingWithoutCache' )]
	public function testOnePassingRequestFetchesOnceAndServesThatDocumentWhenTheCacheFails( string $who, array $extra ): void {
		$lines  = array_merge( self::lines( self::ROOT, $who, self::$docroot, 'once' ), array( 'X-AB-Test-Cache: off' ), $extra );
		$answer = $this->request( self::AS . '/staging/123', $lines );

		self::assertSame( 200, $answer['status'], $answer['raw'] );
		self::assertSame( self::document( 'oauth-authorization-server', self::SUB ), $answer['body'] );
		self::assertSame( '1', $answer['headers']['x-ab-test-fetches'] ?? null, 'One fetch for the request.' );
		self::assertSame( '1', $answer['headers']['x-ab-test-reads'] ?? null, 'One decision that reached the relay.' );
	}

	/**
	 * Without the protection: the handler alone, as in the hotfix 4.10.1.
	 *
	 * @return array<string,array{0:string,1:int,2:string}> path, status, body
	 */
	public static function unprotected(): array {
		return array(
			'own document'             => array( self::AS, 200, 'own' ),
			'another site\'s document' => array( self::AS . '/staging/123', 200, 'document' ),
			'refused: the theme\'s 404' => array( self::AS . '/nowp/9', 404, 'THEME' ),
		);
	}

	#[DataProvider( 'unprotected' )]
	public function testWithoutTheProtectionTheHandlerAnswersAlone( string $path, int $status, string $body ): void {
		$answer = $this->request( $path, array_merge( self::lines( self::ROOT, 'visitor', self::$docroot ), array( 'X-AB-Test-Protection: off' ) ) );

		self::assertSame( $status, $answer['status'], $answer['raw'] );
		$want = array(
			'own'      => self::document( 'oauth-authorization-server', self::ROOT ),
			'document' => self::document( 'oauth-authorization-server', self::SUB ),
		);
		self::assertSame( $want[ $body ] ?? $body, $answer['body'] );
		self::assertArrayNotHasKey( 'x-robots-tag', $answer['kept'], 'The protection is off.' );
	}

	/* ------------------------------------------------------------- helpers */

	/**
	 * The header lines of a request: the site's home, who asks, where its
	 * core lies (under the web directory with a WordPress at /staging/123),
	 * how the other site answers, OAuth on or off.
	 *
	 * @return string[]
	 */
	public static function lines( string $home, string $who, string $docroot, string $fetch = 'site', bool $oauth = true ): array {
		$lines = array( 'X-AB-Test-Home: ' . $home, 'X-AB-Test-Visitor: ' . $who, 'X-AB-Test-Fetch: ' . $fetch );
		if ( '' !== $docroot ) {
			$lines[] = 'X-AB-Test-Abspath: ' . $docroot . rtrim( (string) parse_url( $home, PHP_URL_PATH ), '/' ) . '/';
		}
		if ( ! $oauth ) {
			$lines[] = 'X-AB-Test-OAuth: off';
		}
		return $lines;
	}

	/** The headers main sent with its own server metadata to a visitor. */
	private static function ownDocumentHeaders(): array {
		return WellKnownHttpTest::recorded()['protected']['visitor: server metadata']['headers'];
	}

	/** A document as a site with this home builds it, encoded as send_json() encodes it. */
	private static function document( string $name, string $home ): string {
		ab_test_reset();
		$GLOBALS['ab_test_home']      = $home;
		$GLOBALS['ab_test_rest_base'] = $home . '/wp-json/';
		$data                         = 'oauth-authorization-server' === $name ? AB_MCP_OAuth::metadata() : AB_MCP_OAuth::resource_metadata();
		ab_test_reset();
		return (string) wp_json_encode( $data );
	}

	/**
	 * One raw GET, the path sent exactly as given.
	 *
	 * @param string[] $lines
	 * @return array{status:int,headers:array<string,string>,kept:array<string,string>,body:string,raw:string}
	 */
	private function request( string $path, array $lines ): array {
		$sock = fsockopen( '127.0.0.1', self::$port, $errno, $errstr, 5.0 );
		self::assertNotFalse( $sock, $errstr );
		fwrite( $sock, implode( "\r\n", array_merge( array( 'GET ' . $path . ' HTTP/1.0', 'Host: example.test' ), $lines ) ) . "\r\n\r\n" );
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
		$kept = array_intersect_key( $headers, array_flip( self::KEEP ) );
		ksort( $kept );
		return array(
			'status'  => $status,
			'headers' => $headers,
			'kept'    => $kept,
			'body'    => $body,
			'raw'     => $path . "\n" . substr( $raw, 0, 2000 ),
		);
	}
}
