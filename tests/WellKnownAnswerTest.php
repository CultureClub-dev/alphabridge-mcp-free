<?php
/**
 * The decision of the well-known answer and its executor, clause by clause,
 * in the test process (hotfix 4.10.1 in main; the same addresses over HTTP
 * are in WellKnownHttpTest and PreviewWellKnownHttpTest).
 *
 * - Which request is one of ours: the name anchored at the start of the path
 *   (after the home path of a WordPress in a folder) and at its very end, a
 *   path below it of any characters; a home address with a slash at the end
 *   or a request outside the home path changes nothing of that.
 * - Which fields of another site's document are relayed, and when such a
 *   document is not relayed at all: missing or wrongly typed fields, lists
 *   that are no lists of strings, endpoints beside the issuer.
 * - What the executor does with a refusal for the handler (the 404 of
 *   WordPress, without canonical redirect or guessed permalink) and with
 *   «none» (nothing), and that the handler without a request URI does
 *   nothing.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_OAuth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/well-known-docroot.php';

final class WellKnownAnswerTest extends TestCase {

	private string $dir = '';
	private string $p   = '';
	/** @var callable|null Changes the other site's document before it is sent. */
	private $change = null;

	protected function setUp(): void {
		ab_test_reset();
		$this->dir    = ab_test_wk_dir( array( '/staging/123' ), rtrim( ABSPATH, '/' ) );
		$this->p      = '/' . basename( $this->dir );
		$this->change = null;
		$GLOBALS['ab_test_http_answer'] = function ( string $url ) {
			preg_match( '#\A(https?://[^/]+)(.*)/\.well-known/(oauth-authorization-server|oauth-protected-resource)#', $url, $m );
			$GLOBALS['ab_test_home']      = $m[1] . $m[2];
			$GLOBALS['ab_test_rest_base'] = $m[1] . $m[2] . '/wp-json/';
			$data                         = 'oauth-authorization-server' === $m[3] ? AB_MCP_OAuth::metadata() : AB_MCP_OAuth::resource_metadata();
			$GLOBALS['ab_test_home']      = 'https://example.test';
			unset( $GLOBALS['ab_test_rest_base'] );
			if ( null !== $this->change ) {
				$data = ( $this->change )( $data, $m[1] . $m[2] );
			}
			return ab_test_http_response( 200, (string) wp_json_encode( $data ), array( 'Content-Type' => 'application/json' ) );
		};
	}

	protected function tearDown(): void {
		ab_test_wk_remove( $this->dir );
		unset( $GLOBALS['wp_query'], $_SERVER['REQUEST_URI'] );
	}

	/* ------------------------------------------------- which request is ours */

	/**
	 * @return array<string,array{0:string,1:string,2:string}> home, request path, kind
	 */
	public static function requests(): array {
		$as = '/.well-known/oauth-authorization-server';
		return array(
			'home with a slash at the end: its own document'       => array( 'https://example.test/', $as, 'document' ),
			'subfolder site, a request outside its home path'     => array( 'https://example.test/staging/123', $as, 'document' ),
			'the name not at the start of the path'               => array( 'https://example.test', '/foo' . $as, 'none' ),
			// wp_parse_url() makes a control character an underscore.
			'a line break after the name'                         => array( 'https://example.test', $as . "\n", 'none' ),
			'a line break in the path after the name'             => array( 'https://example.test', $as . "/a\nb", 'refused' ),
		);
	}

	#[DataProvider( 'requests' )]
	public function testWhichRequestIsOneOfOurs( string $home, string $path, string $kind ): void {
		$GLOBALS['ab_test_home'] = $home;

		self::assertSame( $kind, AB_MCP_OAuth::well_known_answer( $path )['kind'] );
	}

	/* ---------------------------------------- what of another site is relayed */

	/**
	 * Server metadata of the other site, changed: relayed (with the fields
	 * that remain) or not.
	 *
	 * @return array<string,array{0:string,1:?string}> change, the field missing from the relay (null: refused)
	 */
	public static function serverChanges(): array {
		return array(
			'no issuer'                                => array( 'no-issuer', null ),
			'no revocation endpoint: relayed without'  => array( 'no-revocation', 'revocation_endpoint' ),
			'scopes not a list: relayed without'       => array( 'scopes-map', 'scopes_supported' ),
			'scopes not strings: relayed without'      => array( 'scopes-numbers', 'scopes_supported' ),
			'a flag as text: relayed without'          => array( 'flag-text', 'authorization_response_iss_parameter_supported' ),
			'no code flow'                             => array( 'no-code', null ),
			'an endpoint that is a list'               => array( 'endpoint-list', null ),
			'an endpoint beside the issuer'            => array( 'endpoint-sibling', null ),
			'scopes as a word: relayed without'        => array( 'scopes-word', 'scopes_supported' ),
			'no grant types: relayed without'          => array( 'no-grant-types', 'grant_types_supported' ),
			'no metadata-document flag: relayed without' => array( 'no-cimd-flag', 'client_id_metadata_document_supported' ),
			'no response types'                        => array( 'no-response-types', null ),
			'no code challenge methods'                => array( 'no-challenge-methods', null ),
			'no registration endpoint'                 => array( 'no-registration', null ),
			'an endpoint with a space'                 => array( 'endpoint-space', null ),
			'an endpoint with a control character'     => array( 'endpoint-control', null ),
			'an endpoint with DEL'                     => array( 'endpoint-del', null ),
			'an endpoint with a backslash'             => array( 'endpoint-backslash', null ),
			'an endpoint with a percent sign'          => array( 'endpoint-percent', null ),
			'an endpoint with a fragment'              => array( 'endpoint-hash', null ),
		);
	}

	#[DataProvider( 'serverChanges' )]
	public function testWhatOfAnotherSitesServerMetadataIsRelayed( string $change, ?string $missing ): void {
		$this->change = static function ( array $data, string $home ) use ( $change ): array {
			switch ( $change ) {
				case 'no-issuer':
					unset( $data['issuer'] );
					break;
				case 'no-revocation':
					unset( $data['revocation_endpoint'] );
					break;
				case 'scopes-map':
					$data['scopes_supported'] = array( 'a' => 'read' );
					break;
				case 'scopes-numbers':
					$data['scopes_supported'] = array( 1, 2 );
					break;
				case 'flag-text':
					$data['authorization_response_iss_parameter_supported'] = 'yes';
					break;
				case 'no-code':
					$data['response_types_supported'] = array( 'token' );
					break;
				case 'endpoint-list':
					$data['token_endpoint'] = array( $home . '/wp-json/alphabridge/v1/oauth/token' );
					break;
				case 'endpoint-sibling':
					$data['token_endpoint'] = $home . '44/oauth/token';
					break;
				case 'scopes-word':
					$data['scopes_supported'] = 'read';
					break;
				case 'no-grant-types':
					unset( $data['grant_types_supported'] );
					break;
				case 'no-cimd-flag':
					unset( $data['client_id_metadata_document_supported'] );
					break;
				case 'no-response-types':
					unset( $data['response_types_supported'] );
					break;
				case 'no-challenge-methods':
					unset( $data['code_challenge_methods_supported'] );
					break;
				case 'no-registration':
					unset( $data['registration_endpoint'] );
					break;
				case 'endpoint-space':
					$data['token_endpoint'] = $home . '/wp-json/alphabridge/v1/oauth/to ken';
					break;
				case 'endpoint-control':
					$data['token_endpoint'] = $home . "/wp-json/alphabridge/v1/oauth/to\x01ken";
					break;
				case 'endpoint-del':
					$data['token_endpoint'] = $home . "/wp-json/alphabridge/v1/oauth/to\x7Fken";
					break;
				case 'endpoint-backslash':
					$data['token_endpoint'] = $home . '/wp-json/alphabridge/v1/oauth/to\\ken';
					break;
				case 'endpoint-percent':
					$data['token_endpoint'] = $home . '/wp-json/alphabridge/v1/oauth/to%6Ben';
					break;
				case 'endpoint-hash':
					$data['token_endpoint'] = $home . '/wp-json/alphabridge/v1/oauth/token#x';
					break;
			}
			return $data;
		};

		$answer = AB_MCP_OAuth::well_known_answer( '/.well-known/oauth-authorization-server' . $this->p . '/staging/123' );

		if ( null === $missing ) {
			self::assertSame( array( 'kind' => 'refused' ), $answer );
			return;
		}
		self::assertSame( 'document', $answer['kind'] );
		self::assertArrayNotHasKey( $missing, $answer['document'] );
		self::assertSame( 'https://example.test' . $this->p . '/staging/123', $answer['document']['issuer'] );
	}

	/**
	 * @return array<string,array{0:string,1:?string}> change, the field missing from the relay (null: refused)
	 */
	public static function resourceChanges(): array {
		return array(
			'no authorization servers'                => array( 'no-servers', null ),
			'no resource'                             => array( 'no-resource', null ),
			'bearer methods not strings: without'     => array( 'bearer-numbers', 'bearer_methods_supported' ),
			'a name that is no text: without'         => array( 'name-number', 'resource_name' ),
			'no bearer methods: without'              => array( 'no-bearer', 'bearer_methods_supported' ),
			'no name: without'                        => array( 'no-name', 'resource_name' ),
		);
	}

	#[DataProvider( 'resourceChanges' )]
	public function testWhatOfAnotherSitesResourceMetadataIsRelayed( string $change, ?string $missing ): void {
		$this->change = static function ( array $data ) use ( $change ): array {
			switch ( $change ) {
				case 'no-servers':
					unset( $data['authorization_servers'] );
					break;
				case 'no-resource':
					unset( $data['resource'] );
					break;
				case 'bearer-numbers':
					$data['bearer_methods_supported'] = array( 1 );
					break;
				case 'name-number':
					$data['resource_name'] = 5;
					break;
				case 'no-bearer':
					unset( $data['bearer_methods_supported'] );
					break;
				case 'no-name':
					unset( $data['resource_name'] );
					break;
			}
			return $data;
		};

		$answer = AB_MCP_OAuth::well_known_answer( '/.well-known/oauth-protected-resource' . $this->p . '/staging/123/wp-json/alphabridge/v1/mcp' );

		if ( null === $missing ) {
			self::assertSame( array( 'kind' => 'refused' ), $answer );
			return;
		}
		self::assertSame( 'document', $answer['kind'] );
		self::assertArrayNotHasKey( $missing, $answer['document'] );
	}

	/* ------------------------------------------------- the answer of a request */

	public function testOneRequestKeepsItsAnswerAndTheNextStartsAfresh(): void {
		$fetches = 0;
		$site    = $GLOBALS['ab_test_http_answer'];
		$GLOBALS['ab_test_http_answer'] = static function ( string $url ) use ( &$fetches, $site ) {
			++$fetches;
			return $site( $url );
		};
		$other = '/.well-known/oauth-authorization-server' . $this->p . '/staging/123';
		$own   = '/.well-known/oauth-authorization-server';
		$saved = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
		try {
			$_SERVER['REQUEST_TIME_FLOAT'] = 1000.5;
			$first = AB_MCP_OAuth::well_known_answer_of_request( $other );
			$GLOBALS['ab_test_transients'] = array();
			self::assertSame( $first, AB_MCP_OAuth::well_known_answer_of_request( $other ), 'The same request: the same answer.' );
			self::assertSame( 1, $fetches, 'Without the persistent cache, too.' );
			self::assertSame( AB_MCP_OAuth::metadata(), AB_MCP_OAuth::well_known_answer_of_request( $own )['document'], 'Another address in the same request: its own answer.' );
			self::assertSame( 'https://example.test' . $this->p . '/staging/123', AB_MCP_OAuth::well_known_answer_of_request( $other )['document']['issuer'] );

			$_SERVER['REQUEST_TIME_FLOAT'] = 1000.75;
			$GLOBALS['ab_test_transients'] = array();
			AB_MCP_OAuth::well_known_answer_of_request( $other );
			self::assertSame( 2, $fetches, 'The next request decides anew.' );
		} finally {
			if ( null === $saved ) {
				unset( $_SERVER['REQUEST_TIME_FLOAT'] );
			} else {
				$_SERVER['REQUEST_TIME_FLOAT'] = $saved;
			}
		}
	}

	/**
	 * What binds a kept answer to its request: the start of the request in
	 * full (as a float, compared exactly), the site (its id and home address)
	 * and the address asked. No reset of the store in between: each change
	 * alone must make a new decision (Codex, closed check of Behebung 1, A1).
	 */
	public function testTwoStartsAMicrosecondApartAreTwoRequests(): void {
		$fetches   = $this->countFetches();
		$precision = ini_get( 'precision' );
		$saved     = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
		try {
			ini_set( 'precision', '14' );
			$_SERVER['REQUEST_TIME_FLOAT'] = 1794480000.123451;
			AB_MCP_OAuth::well_known_answer_of_request( $this->other() );
			$GLOBALS['ab_test_transients'] = array();
			$_SERVER['REQUEST_TIME_FLOAT'] = 1794480000.123452;
			AB_MCP_OAuth::well_known_answer_of_request( $this->other() );

			self::assertSame( 2, $fetches->count, 'One microsecond later is another request, at precision 14 too.' );
		} finally {
			ini_set( 'precision', (string) $precision );
			$this->restoreStart( $saved );
		}
	}

	public function testWithoutAStartNothingIsKept(): void {
		$fetches = $this->countFetches();
		$saved   = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
		try {
			unset( $_SERVER['REQUEST_TIME_FLOAT'] );
			AB_MCP_OAuth::well_known_answer_of_request( $this->other() );
			$GLOBALS['ab_test_transients'] = array();
			AB_MCP_OAuth::well_known_answer_of_request( $this->other() );

			self::assertSame( 2, $fetches->count, 'No HTTP request start: decided each time afresh.' );
		} finally {
			$this->restoreStart( $saved );
		}
	}

	public function testAnotherHomeAddressInTheSameRequestIsDecidedAnew(): void {
		$saved = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
		try {
			$_SERVER['REQUEST_TIME_FLOAT'] = 1794480001.5;
			$GLOBALS['ab_test_home']       = 'https://first.example';
			$first                         = AB_MCP_OAuth::well_known_answer_of_request( '/.well-known/oauth-authorization-server' );
			$GLOBALS['ab_test_home']       = 'https://second.example';
			$second                        = AB_MCP_OAuth::well_known_answer_of_request( '/.well-known/oauth-authorization-server' );

			self::assertSame( 'https://first.example', $first['document']['issuer'] );
			self::assertSame( 'https://second.example', $second['document']['issuer'], 'Not the answer kept for the other site.' );
		} finally {
			$this->restoreStart( $saved );
		}
	}

	public function testAnotherSiteOfTheNetworkInTheSameRequestIsDecidedAnew(): void {
		$fetches = $this->countFetches();
		$saved   = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
		try {
			$_SERVER['REQUEST_TIME_FLOAT'] = 1794480002.5;
			AB_MCP_OAuth::well_known_answer_of_request( $this->other() );
			$GLOBALS['ab_test_transients'] = array();
			$GLOBALS['ab_test_blog']       = 2;
			AB_MCP_OAuth::well_known_answer_of_request( $this->other() );

			self::assertSame( 2, $fetches->count, 'Another site id, the same home address and path: a decision of its own.' );
		} finally {
			$GLOBALS['ab_test_blog'] = 1;
			$this->restoreStart( $saved );
		}
	}

	/** The relayed address of the other site on disk. */
	private function other(): string {
		return '/.well-known/oauth-authorization-server' . $this->p . '/staging/123';
	}

	/** Counts the fetches of the other site from now on. */
	private function countFetches(): object {
		$counter = new class() {
			/** @var int */
			public $count = 0;
		};
		$site    = $GLOBALS['ab_test_http_answer'];
		$GLOBALS['ab_test_http_answer'] = static function ( string $url ) use ( $counter, $site ) {
			++$counter->count;
			return $site( $url );
		};
		return $counter;
	}

	/** Put the start of the request back as the test process had it. */
	private function restoreStart( $saved ): void {
		if ( null === $saved ) {
			unset( $_SERVER['REQUEST_TIME_FLOAT'] );
		} else {
			$_SERVER['REQUEST_TIME_FLOAT'] = $saved;
		}
	}

	/* ------------------------------------------------------------ executor */

	public function testTheHandlersRefusalIsThe404OfWordPressWithoutRedirectOrGuess(): void {
		$GLOBALS['wp_query'] = new class() {
			/** @var int */
			public $set = 0;

			public function set_404(): void {
				++$this->set;
			}
		};

		AB_MCP_OAuth::respond_well_known( array( 'kind' => 'refused' ), false );

		self::assertSame( 1, $GLOBALS['wp_query']->set, 'The query is a 404, so the theme renders its 404 page.' );
		self::assertSame( 404, $GLOBALS['ab_test_status'] ?? null );
		self::assertSame( 1, $GLOBALS['ab_test_nocache'] );
		self::assertNotFalse( has_filter( 'redirect_canonical', '__return_false' ) );
		self::assertNotFalse( has_filter( 'do_redirect_guess_404_permalink', '__return_false' ) );
	}

	public function testNoneOfOursChangesNothing(): void {
		AB_MCP_OAuth::respond_well_known( array( 'kind' => 'none' ), false );

		self::assertArrayNotHasKey( 'ab_test_status', $GLOBALS );
		self::assertSame( 0, $GLOBALS['ab_test_nocache'] );
		self::assertFalse( has_filter( 'redirect_canonical' ) );
		self::assertFalse( has_filter( 'do_redirect_guess_404_permalink' ) );
	}

	public function testWithoutARequestUriTheHandlerDoesNothing(): void {
		unset( $_SERVER['REQUEST_URI'] );

		AB_MCP_OAuth::serve_well_known();

		self::assertArrayNotHasKey( 'ab_test_status', $GLOBALS );
		self::assertSame( array(), $GLOBALS['ab_test_http'] );
	}
}
