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
					$data['token_endpoint'] = $home . '4/wp-json/alphabridge/v1/oauth/token';
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
