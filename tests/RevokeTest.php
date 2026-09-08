<?php
/**
 * RFC 7009 token revocation.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_OAuth;
use AB_MCP_Settings;
use WP_REST_Request;

final class RevokeTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 7 );
	}

	/**
	 * @return array{0:string,1:string} Plaintext token and its stored hash.
	 */
	private function makeToken(): array {
		$plain = AB_MCP_Settings::add_token( 7, 'Test connection', 'content', 0 );
		return array( $plain, hash( 'sha256', $plain ) );
	}

	private function request( array $body ): WP_REST_Request {
		$r = new WP_REST_Request();
		$r->set_body_params( $body );
		return $r;
	}

	public function testRevokingAKnownTokenRemovesIt(): void {
		list( $plain, $hash ) = $this->makeToken();
		self::assertCount( 1, AB_MCP_Settings::get_tokens() );

		self::assertTrue( AB_MCP_OAuth::revoke_token( $plain ) );

		self::assertSame( array(), AB_MCP_Settings::get_tokens(), 'The stored entry is gone.' );
		self::assertFalse( \AB_MCP_Auth::verify_token( $plain ), 'The token authenticates no one afterwards.' );
		self::assertNotSame( '', $hash );
	}

	public function testRevokingAnUnknownTokenChangesNothing(): void {
		$this->makeToken();
		$before = AB_MCP_Settings::get_tokens();

		self::assertFalse( AB_MCP_OAuth::revoke_token( 'abmcp_' . str_repeat( 'a', 48 ) ) );

		self::assertSame( $before, AB_MCP_Settings::get_tokens(), 'A stranger cannot delete somebody else\'s connection.' );
	}

	public function testRevokingTwiceIsANoOp(): void {
		list( $plain ) = $this->makeToken();

		self::assertTrue( AB_MCP_OAuth::revoke_token( $plain ), 'First call removes it.' );
		self::assertFalse( AB_MCP_OAuth::revoke_token( $plain ), 'Second call finds nothing left.' );
		self::assertSame( array(), AB_MCP_Settings::get_tokens() );
	}

	public function testRevokingOneTokenLeavesTheOthers(): void {
		list( $first )  = $this->makeToken();
		list( $second ) = $this->makeToken();

		AB_MCP_OAuth::revoke_token( $first );

		$rest = AB_MCP_Settings::get_tokens();
		self::assertCount( 1, $rest );
		self::assertSame( hash( 'sha256', $second ), $rest[0]['hash'] );
	}

	public function testEndpointAnswers200AndEmptyForAKnownToken(): void {
		list( $plain ) = $this->makeToken();

		$response = AB_MCP_OAuth::rest_revoke( $this->request( array( 'token' => $plain ) ) );

		self::assertSame( 200, $response->get_status() );
		self::assertNull( $response->get_data(), 'RFC 7009 section 2.2: empty body.' );
		self::assertSame( 'no-store', $response->headers['Cache-Control'] ?? '' );
		self::assertSame( array(), AB_MCP_Settings::get_tokens() );
	}

	public function testEndpointAnswers200AndEmptyForAnUnknownToken(): void {
		$response = AB_MCP_OAuth::rest_revoke( $this->request( array( 'token' => 'abmcp_' . str_repeat( 'b', 48 ) ) ) );

		self::assertSame( 200, $response->get_status(), 'An unknown token must be indistinguishable from a known one.' );
		self::assertNull( $response->get_data() );
	}

	public function testEndpointIgnoresAWrongTokenTypeHint(): void {
		list( $plain ) = $this->makeToken();

		$response = AB_MCP_OAuth::rest_revoke(
			$this->request(
				array(
					'token'           => $plain,
					'token_type_hint' => 'refresh_token',
				)
			)
		);

		self::assertSame( 200, $response->get_status() );
		self::assertSame( array(), AB_MCP_Settings::get_tokens(), 'RFC 7009 section 2.1: the hint is advisory, the server keeps searching.' );
	}

	public function testEndpointRejectsAMissingTokenParameter(): void {
		$response = AB_MCP_OAuth::rest_revoke( $this->request( array() ) );

		self::assertSame( 400, $response->get_status(), 'RFC 7009 section 2.1: token is REQUIRED.' );
		self::assertSame( 'invalid_request', $response->get_data()['error'] ?? '' );
	}

	public function testEndpointReadsAJsonBodyToo(): void {
		list( $plain ) = $this->makeToken();

		$request = new WP_REST_Request();
		$request->set_json_params( array( 'token' => $plain ) );

		self::assertSame( 200, AB_MCP_OAuth::rest_revoke( $request )->get_status() );
		self::assertSame( array(), AB_MCP_Settings::get_tokens() );
	}

	public function testAnUnknownTokenDoesNotRewriteTheTokenOption(): void {
		$this->makeToken();
		$before = ab_test_writes( 'ab_mcp_tokens' );

		AB_MCP_OAuth::revoke_token( 'abmcp_' . str_repeat( 'c', 48 ) );

		self::assertSame(
			$before,
			ab_test_writes( 'ab_mcp_tokens' ),
			'A revoke for a token this site never issued must not rewrite the token option — otherwise anyone can make the site write on every request.'
		);
	}

	public function testTheEndpointAlsoLeavesTheTokenOptionAloneForAnUnknownToken(): void {
		$this->makeToken();
		$before = ab_test_writes( 'ab_mcp_tokens' );

		AB_MCP_OAuth::rest_revoke( $this->request( array( 'token' => 'abmcp_' . str_repeat( 'd', 48 ) ) ) );

		self::assertSame(
			$before,
			ab_test_writes( 'ab_mcp_tokens' ),
			'The guard has to hold through the public endpoint, not only in the helper.'
		);
	}

	public function testATokenParameterThatIsNotAStringIsRejected(): void {
		$this->makeToken();

		$response = AB_MCP_OAuth::rest_revoke( $this->request( array( 'token' => array( 'x' ) ) ) );

		self::assertSame( 400, $response->get_status(), 'token[]=x must not be cast to the string "Array".' );
		self::assertCount( 1, AB_MCP_Settings::get_tokens() );
	}

	public function testAnAbsurdlyLongTokenIsRejected(): void {
		$this->makeToken();

		$response = AB_MCP_OAuth::rest_revoke( $this->request( array( 'token' => str_repeat( 'a', 100000 ) ) ) );

		self::assertSame( 400, $response->get_status(), 'An unauthenticated request must not make the site hash megabytes.' );
		self::assertCount( 1, AB_MCP_Settings::get_tokens() );
	}

	public function testAKnownTokenDoesCauseExactlyOneWrite(): void {
		list( $plain ) = $this->makeToken();
		$before        = ab_test_writes( 'ab_mcp_tokens' );

		AB_MCP_OAuth::revoke_token( $plain );

		self::assertSame( $before + 1, ab_test_writes( 'ab_mcp_tokens' ) );
	}

	public function testEmptyStringRevokesNothing(): void {
		$this->makeToken();
		self::assertFalse( AB_MCP_OAuth::revoke_token( '' ) );
		self::assertCount( 1, AB_MCP_Settings::get_tokens(), 'An empty token must never match a stored entry.' );
	}
}
