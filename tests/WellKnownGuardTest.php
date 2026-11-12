<?php
/**
 * The guards of the well-known answer (hotfix 4.10.1 in main, points 2 and
 * 3): there is one decision and one fetch, and nothing goes around them.
 *
 * - One decision: AB_MCP_OAuth::well_known_answer() is the only code under
 *   includes/ that matches a request against the two well-known names, the
 *   only place where the own documents become an answer, and what the
 *   plugin's handler (serve_well_known()) and the preview protection's
 *   clause well_known carry out, through the one executor
 *   respond_well_known(). The older routing question well_known_document()
 *   is gone. Read from the tokens of the shipped files, and checked by
 *   behaviour: for every address of a matrix the protection's context holds
 *   exactly the answer of well_known_answer(), and decides to serve it
 *   exactly when that answer is not «none».
 * - One fetch: wp_safe_remote_get() occurs once in class-oauth.php, in the
 *   calls object of the relay, and its fetch() is called once, in
 *   well_known_from_other_site(). Scheme, host and port of every fetched
 *   address are those of home_url(), whatever host the request names.
 * - Once per request, counted while the request runs (Codex round 1 of the
 *   port, B1): whoever asks (a visitor, a signed-in user, a cookie holder),
 *   with or without parse_request, with the protection on or off, and with
 *   the persistent cache failing, one request reaches the relay at most once
 *   and fetches at most once, and carries out the answer of that fetch.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_OAuth;
use AB_MCP_Preview_Protection;
use AB_MCP_Settings;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;

require_once __DIR__ . '/../includes/class-page-cache.php';
require_once __DIR__ . '/../includes/class-preview-protection.php';
require_once __DIR__ . '/support/well-known-docroot.php';
require_once __DIR__ . '/support/well-known-http.php';

final class WellKnownGuardTest extends TestCase {

	private const OAUTH   = 'includes/class-oauth.php';
	private const PREVIEW = 'includes/class-preview-protection.php';

	private string $dir = '';
	private string $p   = '';
	/** @var string[] */
	private array $fetched = array();
	/** @var callable The other site, as an AlphaBridge site whose home is the path before /.well-known/. */
	private $site;

	protected function setUp(): void {
		$this->dir     = ab_test_wk_dir( array( '/staging/123' ), rtrim( ABSPATH, '/' ) );
		$this->p       = '/' . basename( $this->dir );
		$this->fetched = array();
		$this->site    = function ( string $url ) {
			$this->fetched[] = $url;
			preg_match( '#\A(https?://[^/]+)(.*)/\.well-known/(oauth-authorization-server|oauth-protected-resource)#', $url, $m );
			$saved                        = array( $GLOBALS['ab_test_home'], $GLOBALS['ab_test_rest_base'] ?? null );
			$GLOBALS['ab_test_home']      = $m[1] . $m[2];
			$GLOBALS['ab_test_rest_base'] = $m[1] . $m[2] . '/wp-json/';
			$data                         = 'oauth-authorization-server' === $m[3] ? AB_MCP_OAuth::metadata() : AB_MCP_OAuth::resource_metadata();
			$GLOBALS['ab_test_home']      = $saved[0];
			if ( null === $saved[1] ) {
				unset( $GLOBALS['ab_test_rest_base'] );
			} else {
				$GLOBALS['ab_test_rest_base'] = $saved[1];
			}
			return ab_test_http_response( 200, (string) wp_json_encode( $data ), array( 'Content-Type' => 'application/json' ) );
		};
		$this->reset();
	}

	/** Every store emptied; the other site answers. */
	private function reset(): void {
		ab_test_reset();
		$GLOBALS['ab_test_http_answer'] = $this->site;
	}

	protected function tearDown(): void {
		ab_test_wk_remove( $this->dir );
		unset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
	}

	/* -------------------------------------------------------- one decision */

	public function testTheOlderRoutingQuestionIsGone(): void {
		foreach ( self::shipped() as $file ) {
			self::assertStringNotContainsString( 'well_known_document', (string) file_get_contents( $file ), $file );
		}
		self::assertFalse( method_exists( AB_MCP_OAuth::class, 'well_known_document' ) );
	}

	public function testOnlyTheAnswerMatchesARequestAgainstTheTwoNames(): void {
		$found = array();
		foreach ( self::shipped() as $file ) {
			foreach ( self::calls( $file, 'preg_match' ) as $call ) {
				if ( false !== stripos( $call['args'], 'well-known' ) ) {
					$found[] = self::relative( $file ) . ':' . $call['line'];
				}
			}
		}
		$answer = self::lines( AB_MCP_OAuth::class, 'well_known_answer' );
		self::assertCount( 1, $found, 'One match of the two names in the shipped code: ' . implode( ', ', $found ) );
		self::assertStringStartsWith( self::OAUTH . ':', $found[0] );
		$line = (int) substr( $found[0], strlen( self::OAUTH ) + 1 );
		self::assertTrue( $line >= $answer[0] && $line <= $answer[1], 'Inside well_known_answer(): ' . $found[0] );
	}

	public function testTheOwnDocumentsBecomeAnAnswerInTheAnswerOnly(): void {
		$file   = dirname( __DIR__ ) . '/' . self::OAUTH;
		$answer = self::lines( AB_MCP_OAuth::class, 'well_known_answer' );
		foreach ( array( 'metadata', 'resource_metadata' ) as $method ) {
			$calls = array_filter( self::calls( $file, $method ), static fn( array $c ): bool => 'self::' === $c['on'] );
			self::assertNotEmpty( $calls, $method );
			foreach ( $calls as $call ) {
				self::assertTrue( $call['line'] >= $answer[0] && $call['line'] <= $answer[1], "self::$method() outside well_known_answer(), line {$call['line']}." );
			}
		}
		$send = self::calls( $file, 'send_json' );
		$exec = self::lines( AB_MCP_OAuth::class, 'respond_well_known' );
		self::assertCount( 1, array_filter( $send, static fn( array $c ): bool => 'self::' === $c['on'] ), 'One call of send_json().' );
		foreach ( $send as $call ) {
			if ( 'self::' === $call['on'] ) {
				self::assertTrue( $call['line'] >= $exec[0] && $call['line'] <= $exec[1], 'send_json() is called by respond_well_known() alone.' );
			}
		}
	}

	public function testTheHandlerAndTheProtectionCarryOutTheAnswerThroughOneExecutor(): void {
		$sites = array();
		foreach ( self::shipped() as $file ) {
			foreach ( self::calls( $file, 'respond_well_known' ) as $call ) {
				$sites[] = array( self::relative( $file ), $call['line'], $call['args'], $call['on'] );
			}
		}
		self::assertCount( 2, $sites, 'The handler and the protection: ' . json_encode( $sites ) );

		$handler = self::lines( AB_MCP_OAuth::class, 'serve_well_known' );
		$serve   = self::lines( AB_MCP_Preview_Protection::class, 'serve' );
		$by_file = array();
		foreach ( $sites as $site ) {
			$by_file[ $site[0] ] = $site;
		}
		self::assertArrayHasKey( self::OAUTH, $by_file );
		self::assertArrayHasKey( self::PREVIEW, $by_file );
		self::assertTrue( $by_file[ self::OAUTH ][1] >= $handler[0] && $by_file[ self::OAUTH ][1] <= $handler[1], 'In serve_well_known().' );
		self::assertStringStartsWith( 'self::well_known_answer_of_request(', $by_file[ self::OAUTH ][2], 'The handler carries out the answer of this request.' );
		self::assertSame( ', false', substr( $by_file[ self::OAUTH ][2], -7 ), 'For everyone: not the protection\'s end.' );
		self::assertTrue( $by_file[ self::PREVIEW ][1] >= $serve[0] && $by_file[ self::PREVIEW ][1] <= $serve[1], 'In AB_MCP_Preview_Protection::serve().' );
		self::assertSame( 'AB_MCP_OAuth::', $by_file[ self::PREVIEW ][3] );

		// The context holds the answer of this request, asked once.
		$context = self::lines( AB_MCP_Preview_Protection::class, 'frontend_context' );
		$asked   = array_values( array_filter( self::calls( dirname( __DIR__ ) . '/' . self::PREVIEW, 'well_known_answer_of_request' ), static fn( array $c ): bool => 'AB_MCP_OAuth::' === $c['on'] ) );
		self::assertCount( 1, $asked, 'The protection asks the decision once.' );
		self::assertTrue( $asked[0]['line'] >= $context[0] && $asked[0]['line'] <= $context[1], 'In frontend_context().' );
		self::assertSame( '$uri', $asked[0]['args'] );

		// Both ask the answer of the request, never the decision itself: it
		// is called in the shipped code only by well_known_answer_of_request().
		$of_request = self::lines( AB_MCP_OAuth::class, 'well_known_answer_of_request' );
		$decisions  = array();
		$requests   = array();
		foreach ( self::shipped() as $file ) {
			foreach ( self::calls( $file, 'well_known_answer' ) as $call ) {
				$decisions[] = array( self::relative( $file ), $call['line'] );
			}
			foreach ( self::calls( $file, 'well_known_answer_of_request' ) as $call ) {
				$requests[] = self::relative( $file );
			}
		}
		self::assertNotEmpty( $decisions );
		foreach ( $decisions as $decision ) {
			self::assertSame( self::OAUTH, $decision[0] );
			self::assertTrue( $decision[1] >= $of_request[0] && $decision[1] <= $of_request[1], 'The decision is called in well_known_answer_of_request() only, line ' . $decision[1] . '.' );
		}
		sort( $requests );
		self::assertSame( array( self::OAUTH, self::PREVIEW ), $requests, 'The handler and the protection, nobody else.' );
	}

	public function testTheProtectionsClauseFollowsTheAnswerForEveryAddress(): void {
		$state = array(
			'on'   => true,
			'hash' => 'h',
		);
		$sub   = $this->p . '/staging/123';
		$uris  = array(
			'/.well-known/oauth-authorization-server',
			'/.well-known/oauth-authorization-server/',
			'/.well-known/oauth-authorization-server?sitemap=1',
			'/.well-known/oauth-protected-resource/wp-json/alphabridge/v1/mcp',
			'/.well-known/oauth-authorization-server' . $sub,
			'/.well-known/oauth-protected-resource' . $sub . '/wp-json/alphabridge/v1/mcp',
			'/.well-known/oauth-authorization-server/nowp/9',
			'/.well-known/oauth-protected-resource' . $sub,
			'/.well-known/oauth-authorization-server//',
			'/.well-known/oauth-authorization-server/.well-known/x',
			'/.well-known/oauth-authorization-server-x',
			'/.well-known//oauth-authorization-server',
			'//.well-known/oauth-authorization-server',
			'/.well-known/OAuth-Authorization-Server',
			'/.well-known/alphabridge.json',
			'/hello/',
		);
		$seen = array();
		foreach ( array( true, false ) as $oauth ) {
			foreach ( $uris as $uri ) {
				$this->reset();
				AB_MCP_Settings::set( 'oauth_enabled', $oauth );
				$_SERVER['REQUEST_URI'] = $uri;

				$answer = AB_MCP_OAuth::well_known_answer( $uri );
				$ctx    = AB_MCP_Preview_Protection::frontend_context();

				self::assertSame( $answer, $ctx['well_known_answer'], $uri );
				self::assertSame( 'none' !== $answer['kind'], $ctx['well_known'], $uri );
				$decision = AB_MCP_Preview_Protection::frontend_decision( $ctx, $state, time() );
				self::assertSame( 'none' !== $answer['kind'], array( 'action' => 'serve', 'reason' => 'well_known' ) === $decision, $uri . ' ' . json_encode( $decision ) );
				$seen[ $answer['kind'] ] = true;
			}
		}
		ksort( $seen );
		self::assertSame( array( 'document', 'none', 'refused' ), array_keys( $seen ), 'The matrix reaches every kind of answer.' );
	}

	public function testEveryRequestDecidesOnceAndFetchesAtMostOnce(): void {
		$docroot = ab_test_wk_dir( array( '/staging/123' ) );
		$server  = ab_test_wk_serve( __DIR__ . '/support/preview-well-known-server.php' );
		if ( null === $server ) {
			ab_test_wk_remove( $docroot );
			self::markTestSkipped( '"' . PHP_BINARY . ' -S" could not serve the router on this machine.' );
		}
		$this->reset();
		$GLOBALS['ab_test_home']      = 'https://example.test/staging/123';
		$GLOBALS['ab_test_rest_base'] = 'https://example.test/staging/123/wp-json/';
		$document                     = (string) wp_json_encode( AB_MCP_OAuth::metadata() );
		$this->reset();
		// path, how the other site answers, status, fetches and reads of the relay.
		$cases = array(
			'relayed'           => array( '/.well-known/oauth-authorization-server/staging/123', 'once', 200, 1 ),
			'the fetch fails'   => array( '/.well-known/oauth-authorization-server/staging/123', 'blocked', 404, 1 ),
			'no site on disk'   => array( '/.well-known/oauth-authorization-server/nowp/9', 'once', 404, 0 ),
			'its own document'  => array( '/.well-known/oauth-authorization-server', 'once', 200, 0 ),
		);
		$seen = 0;
		try {
			foreach ( array( 'visitor', 'logged-in', 'cookie' ) as $who ) {
				foreach ( array( true, false ) as $parse ) {
					foreach ( array( true, false ) as $protected ) {
						foreach ( $cases as $label => list( $path, $fetch, $status, $count ) ) {
							$lines = array( 'X-AB-Test-Home: https://example.test', 'X-AB-Test-Visitor: ' . $who, 'X-AB-Test-Fetch: ' . $fetch, 'X-AB-Test-Abspath: ' . $docroot . '/', 'X-AB-Test-Cache: off' );
							if ( ! $parse ) {
								$lines[] = 'X-AB-Test-No-Parse: 1';
							}
							if ( ! $protected ) {
								$lines[] = 'X-AB-Test-Protection: off';
							}
							$where  = "$label, $who, " . ( $parse ? 'parse_request' : 'no parse_request' ) . ', protection ' . ( $protected ? 'on' : 'off' );
							$answer = ab_test_wk_get( $server['port'], $path, $lines );

							self::assertSame( (string) $count, $answer['headers']['x-ab-test-fetches'] ?? null, 'Fetches of one request: ' . $where . "\n" . $answer['raw'] );
							self::assertSame( (string) $count, $answer['headers']['x-ab-test-reads'] ?? null, 'Decisions that reached the relay: ' . $where );
							self::assertSame( $status, $answer['status'], $where . "\n" . $answer['raw'] );
							if ( 'relayed' === $label ) {
								self::assertSame( $document, $answer['body'], 'The document of the one fetch: ' . $where );
							}
							++$seen;
						}
					}
				}
			}
		} finally {
			ab_test_wk_stop( $server );
			ab_test_wk_remove( $docroot );
		}
		self::assertSame( 48, $seen );
	}

	/* ------------------------------------------------------------ one fetch */

	public function testTheFetchIsOneCallInTheRelay(): void {
		$file  = dirname( __DIR__ ) . '/' . self::OAUTH;
		$wp    = self::calls( $file, 'wp_safe_remote_get' );
		$fetch = self::lines( 'AB_MCP_OAuth_Well_Known_Calls', 'fetch' );
		self::assertCount( 1, $wp, 'One wp_safe_remote_get() in class-oauth.php.' );
		self::assertTrue( $wp[0]['line'] >= $fetch[0] && $wp[0]['line'] <= $fetch[1], 'In the calls object of the relay.' );

		$relay = self::lines( AB_MCP_OAuth::class, 'well_known_from_other_site' );
		$calls = array_values( array_filter( self::calls( $file, 'fetch' ), static fn( array $c ): bool => '->' === $c['on'] ) );
		self::assertCount( 1, $calls, 'One fetch() of the calls object.' );
		self::assertTrue( $calls[0]['line'] >= $relay[0] && $calls[0]['line'] <= $relay[1], 'In well_known_from_other_site().' );
		self::assertStringStartsWith( '$url,', $calls[0]['args'] );
	}

	/**
	 * @return array<string,array{0:string,1:string}> home, scheme://host:port expected
	 */
	public static function homes(): array {
		return array(
			'https'             => array( 'https://example.test', 'https://example.test' ),
			'http on a port'    => array( 'http://example.test:8461', 'http://example.test:8461' ),
			'https in a folder' => array( 'https://example.test/blog', 'https://example.test' ),
			'another host'      => array( 'https://site.example', 'https://site.example' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'homes' )]
	public function testSchemeHostAndPortOfTheFetchAreThoseOfHomeUrl( string $home, string $origin ): void {
		$GLOBALS['ab_test_home']      = $home;
		$GLOBALS['ab_test_rest_base'] = $home . '/wp-json/';
		// The core at the root of the host: the document root is ABSPATH.
		$GLOBALS['ab_test_site_url'] = $origin;
		$_SERVER['HTTP_HOST']        = 'evil.example:9999';
		$home_path                    = (string) parse_url( $home, PHP_URL_PATH );
		$sub                          = $this->p . '/staging/123';
		$requests                     = array(
			$home_path . '/.well-known/oauth-authorization-server' . $sub,
			$home_path . '/.well-known/oauth-protected-resource' . $sub . '/wp-json/alphabridge/v1/mcp',
			$home_path . '/.well-known/oauth-authorization-server//evil.example' . $sub,
			$home_path . '/.well-known/oauth-authorization-server/evil.example:9999' . $sub,
			'https://evil.example' . $home_path . '/.well-known/oauth-authorization-server' . $sub,
		);
		foreach ( $requests as $uri ) {
			AB_MCP_OAuth::well_known_answer( $uri );
		}

		self::assertNotEmpty( $this->fetched );
		foreach ( $this->fetched as $url ) {
			$parts = (array) parse_url( $url );
			self::assertSame( $origin, $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ), $url );
			self::assertStringStartsWith( $origin . $sub . '/.well-known/', $url );
		}
	}

	/* ------------------------------------------------------------- helpers */

	/** Every PHP file a release carries under includes/, and the main file. */
	private static function shipped(): array {
		$root = dirname( __DIR__ );
		$out  = array( $root . '/alphabridge-mcp.php', $root . '/uninstall.php' );
		$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes', RecursiveDirectoryIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$out[] = $file->getPathname();
			}
		}
		sort( $out );
		self::assertGreaterThan( 100, count( $out ), 'The scan sees the plugin.' );
		return $out;
	}

	private static function relative( string $file ): string {
		return substr( $file, strlen( dirname( __DIR__ ) ) + 1 );
	}

	/** First and last line of a method. */
	private static function lines( string $class, string $method ): array {
		$m = new ReflectionMethod( $class, $method );
		return array( (int) $m->getStartLine(), (int) $m->getEndLine() );
	}

	/**
	 * The calls of a function or method of this name in a file, read from its
	 * tokens (comments are not code): the line, how it is called ('' for a
	 * function, 'self::', 'AB_MCP_OAuth::', '->') and its arguments as written.
	 *
	 * @return array<int,array{line:int,on:string,args:string}>
	 */
	private static function calls( string $file, string $name ): array {
		$tokens = token_get_all( (string) file_get_contents( $file ) );
		$count  = count( $tokens );
		$out    = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$t = $tokens[ $i ];
			if ( ! is_array( $t ) || T_STRING !== $t[0] || $name !== $t[1] ) {
				continue;
			}
			$next = self::skip( $tokens, $i + 1, 1 );
			if ( '(' !== ( $tokens[ $next ] ?? null ) ) {
				continue;
			}
			$prev = self::skip( $tokens, $i - 1, -1 );
			$on   = '';
			if ( is_array( $tokens[ $prev ] ?? null ) && T_DOUBLE_COLON === $tokens[ $prev ][0] ) {
				$owner = self::skip( $tokens, $prev - 1, -1 );
				$on    = ( is_array( $tokens[ $owner ] ) ? $tokens[ $owner ][1] : $tokens[ $owner ] ) . '::';
			} elseif ( is_array( $tokens[ $prev ] ?? null ) && in_array( $tokens[ $prev ][0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ), true ) ) {
				$on = '->';
			} elseif ( is_array( $tokens[ $prev ] ?? null ) && T_FUNCTION === $tokens[ $prev ][0] ) {
				continue; // The declaration.
			}
			$depth = 0;
			$args  = '';
			for ( $k = $next; $k < $count; $k++ ) {
				$text = is_array( $tokens[ $k ] ) ? $tokens[ $k ][1] : $tokens[ $k ];
				if ( '(' === $text ) {
					++$depth;
					if ( 1 === $depth ) {
						continue;
					}
				}
				if ( ')' === $text ) {
					--$depth;
					if ( 0 === $depth ) {
						break;
					}
				}
				if ( is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				$args .= $text;
			}
			$out[] = array(
				'line' => (int) $t[2],
				'on'   => $on,
				'args' => trim( (string) preg_replace( '/\s+/', ' ', $args ) ),
			);
		}
		return $out;
	}

	/** The index of the next token that is not whitespace or a comment, in a direction. */
	private static function skip( array $tokens, int $i, int $step ): int {
		while ( isset( $tokens[ $i ] ) && is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			$i += $step;
		}
		return $i;
	}
}
