<?php
/**
 * How long a relayed well-known document, or the failure to get one, is kept.
 *
 * When a site relays the well-known document of another site on its host
 * (AB_MCP_OAuth::well_known_answer(), see WellKnownHttpTest), it fetches it
 * from that site. Each answer, and each failure, is kept five minutes per
 * site path and per own address, so a client trying again, or random paths
 * asked again, do not make the site fetch from itself each time; a change of
 * the site's own address starts afresh.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_OAuth;
use PHPUnit\Framework\TestCase;

final class WellKnownRelayCacheTest extends TestCase {

	private const AS_PATH = '/.well-known/oauth-authorization-server/staging/123';

	/** @var int */
	private int $fetches = 0;

	protected function setUp(): void {
		ab_test_reset();
		$this->fetches = 0;
		$this->answerAsASite();
	}

	public function testAFetchedDocumentIsKeptFiveMinutes(): void {
		$first  = AB_MCP_OAuth::well_known_answer( self::AS_PATH );
		$second = AB_MCP_OAuth::well_known_answer( self::AS_PATH );

		self::assertSame( 'document', $first['kind'] );
		self::assertSame( 'https://example.test/staging/123', $first['document']['issuer'] );
		self::assertSame( $first, $second, 'The same document the second time.' );
		self::assertSame( 1, $this->fetches, 'Fetched once.' );
		self::assertSame( array( 300 ), array_values( $this->relayTtls() ) );
	}

	public function testAFailureIsKeptFiveMinutesToo(): void {
		$GLOBALS['ab_test_http_answer'] = function () {
			++$this->fetches;
			return new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		};

		self::assertSame( array( 'kind' => 'refused' ), AB_MCP_OAuth::well_known_answer( self::AS_PATH ) );
		self::assertSame( array( 'kind' => 'refused' ), AB_MCP_OAuth::well_known_answer( self::AS_PATH ) );
		self::assertSame( 1, $this->fetches, 'A slow or blocked site costs one fetch per five minutes, not one per request.' );
		self::assertSame( array( 300 ), array_values( $this->relayTtls() ) );
	}

	public function testEachSitePathIsKeptOnItsOwn(): void {
		AB_MCP_OAuth::well_known_answer( self::AS_PATH );
		AB_MCP_OAuth::well_known_answer( '/.well-known/oauth-authorization-server/staging/456' );
		AB_MCP_OAuth::well_known_answer( '/.well-known/oauth-protected-resource/staging/123/wp-json/alphabridge/v1/mcp' );

		self::assertSame( 3, $this->fetches );
		self::assertCount( 3, $this->relayTtls() );
	}

	public function testANewOwnAddressStartsAfresh(): void {
		AB_MCP_OAuth::well_known_answer( self::AS_PATH );
		$GLOBALS['ab_test_home_url']  = 'https://tunnel.example';
		$GLOBALS['ab_test_rest_base'] = 'https://tunnel.example/wp-json/';

		$answer = AB_MCP_OAuth::well_known_answer( self::AS_PATH );

		self::assertSame( 2, $this->fetches, 'Not the document kept for the old address.' );
		self::assertSame( 'https://tunnel.example/staging/123', $answer['document']['issuer'] );
	}

	public function testAfterFiveMinutesItIsFetchedAgain(): void {
		AB_MCP_OAuth::well_known_answer( self::AS_PATH );
		foreach ( array_keys( $this->relayTtls() ) as $key ) {
			delete_transient( $key );
		}

		AB_MCP_OAuth::well_known_answer( self::AS_PATH );

		self::assertSame( 2, $this->fetches );
	}

	public function testOwnPathsNeitherFetchNorKeepAnything(): void {
		AB_MCP_OAuth::well_known_answer( '/.well-known/oauth-authorization-server' );
		AB_MCP_OAuth::well_known_answer( '/.well-known/oauth-protected-resource/wp-json/alphabridge/v1/mcp' );

		self::assertSame( 0, $this->fetches );
		self::assertSame( array(), $this->relayTtls() );
	}

	public function testUncleanPathsNeitherFetchNorKeepAnything(): void {
		AB_MCP_OAuth::well_known_answer( '/.well-known/oauth-authorization-server/.well-known/oauth-authorization-server/x' );
		AB_MCP_OAuth::well_known_answer( '/.well-known/oauth-authorization-server/a/b/c/d/e' );

		self::assertSame( 0, $this->fetches );
		self::assertSame( array(), $this->relayTtls() );
	}

	/** The other site answers as an AlphaBridge site whose home is the path before /.well-known/. */
	private function answerAsASite(): void {
		$GLOBALS['ab_test_http_answer'] = function ( string $url ) {
			++$this->fetches;
			preg_match( '#\A(https?://[^/]+)(.*)/\.well-known/(oauth-authorization-server|oauth-protected-resource)#', $url, $m );
			$saved                        = array( $GLOBALS['ab_test_home_url'] ?? null, $GLOBALS['ab_test_rest_base'] ?? null );
			$GLOBALS['ab_test_home_url']  = $m[1] . $m[2];
			$GLOBALS['ab_test_rest_base'] = $m[1] . $m[2] . '/wp-json/';
			$data                         = 'oauth-authorization-server' === $m[3] ? AB_MCP_OAuth::metadata() : AB_MCP_OAuth::resource_metadata();
			if ( null === $saved[0] ) {
				unset( $GLOBALS['ab_test_home_url'], $GLOBALS['ab_test_rest_base'] );
			} else {
				list( $GLOBALS['ab_test_home_url'], $GLOBALS['ab_test_rest_base'] ) = $saved;
			}
			return ab_test_http_response( 200, (string) wp_json_encode( $data ), array( 'Content-Type' => 'application/json; charset=utf-8' ) );
		};
	}

	/**
	 * The relay's transients and their lifetimes.
	 *
	 * @return array<string,int>
	 */
	private function relayTtls(): array {
		return array_filter(
			$GLOBALS['ab_test_transient_ttl'],
			static fn( $key ): bool => 0 === strpos( (string) $key, 'ab_mcp_wk_' ),
			ARRAY_FILTER_USE_KEY
		);
	}
}
