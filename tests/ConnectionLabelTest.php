<?php
/**
 * How a connection is labelled in the settings list.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_OAuth;

final class ConnectionLabelTest extends TestCase {

	public function testTheHubIsLabelledWithItsNameAlone(): void {
		self::assertSame(
			'AlphaBridge Connect',
			AB_MCP_OAuth::connection_label( 'AlphaBridge Connect' ),
			'No "· OAuth" suffix: the site owner should see at a glance that it came through the hub.'
		);
	}

	public function testSurroundingWhitespaceDoesNotHideTheHub(): void {
		self::assertSame( 'AlphaBridge Connect', AB_MCP_OAuth::connection_label( '  AlphaBridge Connect ' ) );
	}

	public function testTheComparisonIgnoresCase(): void {
		self::assertSame( 'AlphaBridge Connect', AB_MCP_OAuth::connection_label( 'alphabridge connect' ) );
	}

	public function testEveryOtherClientGetsItsNameAndOAuth(): void {
		self::assertSame( 'Claude · OAuth', AB_MCP_OAuth::connection_label( 'Claude' ) );
		self::assertSame( 'Cursor · OAuth', AB_MCP_OAuth::connection_label( 'Cursor' ) );
	}

	public function testNoClientIsLabelledWithAnotherVendorsProduct(): void {
		// On 26.09.2026 the connections list of a live site read
		// "ChatGPT · Claude Connect".
		self::assertSame( 'ChatGPT · OAuth', AB_MCP_OAuth::connection_label( 'ChatGPT' ) );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function exchanges(): array {
		return array(
			'ChatGPT'             => array( 'ChatGPT', 'ChatGPT · OAuth' ),
			'AlphaBridge Connect' => array( 'AlphaBridge Connect', 'AlphaBridge Connect' ),
		);
	}

	/**
	 * The helper is only half of it: the label that matters is the one a code
	 * exchange stores with the new token.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'exchanges' )]
	public function testACodeExchangeStoresThatLabel( string $client_name, string $expected ): void {
		ab_test_reset();
		ab_test_add_user( 7 );
		$redirect = 'https://client.example/callback';
		$verifier = 'verifier-verifier-verifier-verifier-verifier-1234';
		update_option(
			AB_MCP_OAuth::OPT_CLIENTS,
			array( 'client-under-test' => array( 'name' => $client_name, 'redirect_uris' => array( $redirect ) ) )
		);
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		$code      = AB_MCP_OAuth::create_auth_code( 'client-under-test', $redirect, 7, 'read', $challenge );

		$out = AB_MCP_OAuth::redeem_code(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => 'client-under-test',
				'redirect_uri'  => $redirect,
				'code_verifier' => $verifier,
			)
		);

		self::assertArrayHasKey( 'access_token', $out, print_r( $out, true ) );
		$tokens = \AB_MCP_Settings::get_tokens();
		self::assertCount( 1, $tokens );
		self::assertSame( $expected, $tokens[0]['label'] );
	}

	public function testALookalikeNameIsNotTreatedAsTheHub(): void {
		self::assertSame(
			'AlphaBridge Connector · OAuth',
			AB_MCP_OAuth::connection_label( 'AlphaBridge Connector' ),
			'Only the exact name counts; a similar one must stay distinguishable.'
		);
	}

	public function testANamelessClientIsLabelledOAuthWithoutADanglingSeparator(): void {
		self::assertSame( 'OAuth', AB_MCP_OAuth::connection_label( '' ) );
		self::assertSame( 'OAuth', AB_MCP_OAuth::connection_label( "  \t " ), 'Whitespace alone is no name.' );
	}
}
