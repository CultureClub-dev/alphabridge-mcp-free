<?php
/**
 * Older connections of the same app are marked in the list, never removed.
 *
 * ChatGPT's «reconnect» (26.09.2026) issued a new token under the same client
 * id and left the earlier one working. The list now says which connection a
 * newer one of the same app has followed, for the same user.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Admin;
use AB_MCP_OAuth;
use AB_MCP_Settings;
use ReflectionMethod;

final class OlderConnectionsTest extends TestCase {

	private const REDIRECT = 'https://client.example/callback';
	private const VERIFIER = 'verifier-verifier-verifier-verifier-verifier-1234';

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 7 );
		ab_test_add_user( 9 );
	}

	private static function entry( string $hash, int $user, string $client, int $created ): array {
		return array(
			'hash'      => $hash,
			'user_id'   => $user,
			'client_id' => $client,
			'created'   => $created,
		);
	}

	/** @return array<string,array{0:array,1:array}> */
	public static function cases(): array {
		return array(
			'one connection is never older'                => array(
				array( self::entry( 'a', 7, 'abc_1', 100 ) ),
				array(),
			),
			'same app, same user: the earlier one'         => array(
				array( self::entry( 'a', 7, 'abc_1', 100 ), self::entry( 'b', 7, 'abc_1', 200 ) ),
				array( 'a' ),
			),
			'same app, different users: neither'          => array(
				array( self::entry( 'a', 7, 'abc_1', 100 ), self::entry( 'b', 9, 'abc_1', 200 ) ),
				array(),
			),
			'different apps, same user: neither'           => array(
				array( self::entry( 'a', 7, 'abc_1', 100 ), self::entry( 'b', 7, 'abc_2', 200 ) ),
				array(),
			),
			'made by hand: never grouped'                  => array(
				array( self::entry( 'a', 7, '', 100 ), self::entry( 'b', 7, '', 200 ) ),
				array(),
			),
			'entries from before 4.3.5 have no client_id'  => array(
				array( array( 'hash' => 'a', 'user_id' => 7, 'created' => 100 ), array( 'hash' => 'b', 'user_id' => 7, 'created' => 200 ) ),
				array(),
			),
			'the later created wins, wherever it is listed' => array(
				array( self::entry( 'a', 7, 'abc_1', 300 ), self::entry( 'b', 7, 'abc_1', 100 ), self::entry( 'c', 7, 'abc_1', 200 ) ),
				array( 'b', 'c' ),
			),
			'a tie goes to the entry further down'         => array(
				array( self::entry( 'a', 7, 'abc_1', 100 ), self::entry( 'b', 7, 'abc_1', 100 ) ),
				array( 'a' ),
			),
			'an entry without a hash is left out'          => array(
				array( self::entry( '', 7, 'abc_1', 300 ), self::entry( 'a', 7, 'abc_1', 100 ) ),
				array(),
			),
			'something that is not an entry is skipped'    => array(
				array( 'garbage', self::entry( 'a', 7, 'abc_1', 100 ), self::entry( 'b', 7, 'abc_1', 200 ) ),
				array( 'a' ),
			),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'cases' )]
	public function testWhichConnectionsAreOlder( array $tokens, array $expected ): void {
		$older = array_keys( AB_MCP_Settings::older_of_same_app( $tokens ) );
		sort( $older );
		self::assertSame( $expected, $older );
	}

	private function exchange( string $client_id, int $user, string $scope ): void {
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', self::VERIFIER, true ) ), '+/', '-_' ), '=' );
		$code      = AB_MCP_OAuth::create_auth_code( $client_id, self::REDIRECT, $user, $scope, $challenge );
		$out       = AB_MCP_OAuth::redeem_code(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => $client_id,
				'redirect_uri'  => self::REDIRECT,
				'code_verifier' => self::VERIFIER,
			)
		);
		self::assertArrayHasKey( 'access_token', $out, print_r( $out, true ) );
	}

	/** The connections table as the settings screen renders it. */
	private function table(): string {
		$method = new ReflectionMethod( AB_MCP_Admin::class, 'render_connection_table' );
		ob_start();
		$method->invoke( new AB_MCP_Admin(), AB_MCP_Settings::get_tokens() );
		return (string) ob_get_clean();
	}

	/** @return list<string> One entry per table row: its label cell. */
	private function labelCells( string $html ): array {
		preg_match_all( '#<tr data-hash="[^"]*"><td>(.*?)</td>#s', $html, $m );
		return $m[1];
	}

	public function testAReconnectMarksTheEarlierConnectionAndOnlyThatOne(): void {
		update_option(
			AB_MCP_OAuth::OPT_CLIENTS,
			array( 'abc_chatgpt' => array( 'name' => 'ChatGPT', 'redirect_uris' => array( self::REDIRECT ) ) )
		);

		// The sequence of 26.09.2026: read, then reconnect with content.
		$this->exchange( 'abc_chatgpt', 7, 'read' );
		$this->exchange( 'abc_chatgpt', 7, 'content' );

		$tokens = AB_MCP_Settings::get_tokens();
		self::assertCount( 2, $tokens, 'Nothing is revoked.' );
		self::assertSame( 'abc_chatgpt', $tokens[0]['client_id'] );
		self::assertSame( 'abc_chatgpt', $tokens[1]['client_id'] );

		$cells = $this->labelCells( $this->table() );
		self::assertCount( 2, $cells );
		self::assertStringContainsString( 'ab-older', $cells[0], 'The earlier connection is marked.' );
		self::assertStringNotContainsString( 'ab-older', $cells[1], 'The newest one is not.' );
	}

	public function testTwoPeopleOnTheSameAppMarkNobody(): void {
		update_option(
			AB_MCP_OAuth::OPT_CLIENTS,
			array( 'abc_chatgpt' => array( 'name' => 'ChatGPT', 'redirect_uris' => array( self::REDIRECT ) ) )
		);

		$this->exchange( 'abc_chatgpt', 7, 'read' );
		$this->exchange( 'abc_chatgpt', 9, 'read' );

		self::assertStringNotContainsString( 'ab-older', $this->table() );
	}

	public function testConnectionsMadeByHandAreNeverMarked(): void {
		AB_MCP_Settings::add_token( 7, 'Cursor', 'full', 0 );
		AB_MCP_Settings::add_token( 7, 'Script', 'read', 0 );

		foreach ( AB_MCP_Settings::get_tokens() as $t ) {
			self::assertSame( '', $t['client_id'] );
		}
		self::assertStringNotContainsString( 'ab-older', $this->table() );
	}
}
