<?php
/**
 * RFC 8414 authorization-server metadata.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_OAuth;

final class MetadataTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
	}

	public function testTheRevocationEndpointIsAdvertised(): void {
		$meta = AB_MCP_OAuth::metadata();

		self::assertArrayHasKey(
			'revocation_endpoint',
			$meta,
			'A hub finds the revoke endpoint through discovery, never by guessing the path.'
		);
		self::assertSame(
			'https://example.test/wp-json/alphabridge/v1/oauth/revoke',
			$meta['revocation_endpoint']
		);
	}

	public function testTheRevocationEndpointNeedsNoClientAuthentication(): void {
		self::assertSame(
			array( 'none' ),
			AB_MCP_OAuth::metadata()['revocation_endpoint_auth_methods_supported'] ?? null,
			'RFC 7009 with public clients: the presented token is the credential.'
		);
	}

	public function testTheOtherEndpointsAreStillAdvertised(): void {
		$meta = AB_MCP_OAuth::metadata();

		foreach ( array( 'issuer', 'authorization_endpoint', 'token_endpoint', 'registration_endpoint' ) as $key ) {
			self::assertArrayHasKey( $key, $meta, "Adding revocation must not drop $key." );
		}
		self::assertSame( array( 'S256' ), $meta['code_challenge_methods_supported'], 'PKCE S256 stays mandatory.' );
	}
}
