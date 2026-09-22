<?php
/**
 * The consent record that carries user, scope and PKCE challenge from the
 * consent page to the token endpoint lives in WordPress' transient store —
 * shared with every plugin and with generic "set transient" tools. Only a
 * record the consent step wrote may become a token.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_OAuth;
use AB_MCP_Settings;

final class OAuthCodeSignatureTest extends TestCase {

	private const CLIENT   = 'client-under-test';
	private const REDIRECT = 'https://client.example/callback';
	private const VERIFIER = 'verifier-verifier-verifier-verifier-verifier-1234';

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 7 );
		ab_test_add_user( 9 );
		update_option(
			AB_MCP_OAuth::OPT_CLIENTS,
			array(
				self::CLIENT => array(
					'name'          => 'Claude',
					'redirect_uris' => array( self::REDIRECT ),
				),
			)
		);
	}

	private static function challenge(): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', self::VERIFIER, true ) ), '+/', '-_' ), '=' );
	}

	/** The transient key create_auth_code() files a code under. */
	private static function key( string $code ): string {
		return 'ab_mcp_oac_' . substr( hash( 'sha256', $code ), 0, 40 );
	}

	private function redeem( string $code ): array {
		return AB_MCP_OAuth::redeem_code(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => self::CLIENT,
				'redirect_uri'  => self::REDIRECT,
				'code_verifier' => self::VERIFIER,
			)
		);
	}

	public function testAGenuineConsentRecordBecomesAToken(): void {
		$code = AB_MCP_OAuth::create_auth_code( self::CLIENT, self::REDIRECT, 7, 'content', self::challenge() );
		self::assertArrayHasKey( 'sig', $GLOBALS['ab_test_transients'][ self::key( $code ) ] );

		$out = $this->redeem( $code );
		self::assertArrayHasKey( 'access_token', $out, print_r( $out, true ) );

		$tokens = AB_MCP_Settings::get_tokens();
		self::assertCount( 1, $tokens );
		self::assertSame( 7, $tokens[0]['user_id'] );
		self::assertSame( 'content', $tokens[0]['scope'] );
	}

	public function testAChangedUserIsRefused(): void {
		$code = AB_MCP_OAuth::create_auth_code( self::CLIENT, self::REDIRECT, 7, 'content', self::challenge() );
		$GLOBALS['ab_test_transients'][ self::key( $code ) ]['user_id'] = 9;

		self::assertSame( array( 'error' => 'invalid_grant' ), $this->redeem( $code ) );
		self::assertSame( array(), AB_MCP_Settings::get_tokens(), 'No token for anyone.' );
	}

	public function testAWidenedScopeIsRefused(): void {
		$code = AB_MCP_OAuth::create_auth_code( self::CLIENT, self::REDIRECT, 7, 'read', self::challenge() );
		$GLOBALS['ab_test_transients'][ self::key( $code ) ]['scope'] = 'full';

		self::assertSame( array( 'error' => 'invalid_grant' ), $this->redeem( $code ) );
		self::assertSame( array(), AB_MCP_Settings::get_tokens() );
	}

	public function testARecordWrittenWithoutTheConsentStepIsRefused(): void {
		// What a generic transient writer could plant: every field the token
		// endpoint checks, chosen to pass — but no signature it could compute.
		$code = 'abac_' . str_repeat( 'ab', 32 );
		set_transient(
			self::key( $code ),
			array(
				'client_id'    => self::CLIENT,
				'redirect_uri' => self::REDIRECT,
				'user_id'      => 9,
				'scope'        => 'full',
				'challenge'    => self::challenge(),
				'created'      => time(),
			),
			120
		);
		self::assertSame( array( 'error' => 'invalid_grant' ), $this->redeem( $code ) );
		self::assertSame( array(), AB_MCP_Settings::get_tokens() );
	}

	public function testASignatureFromAnotherSaltIsRefused(): void {
		$code   = 'abac_' . str_repeat( 'cd', 32 );
		$key    = self::key( $code );
		$fields = array(
			'client_id'    => self::CLIENT,
			'redirect_uri' => self::REDIRECT,
			'user_id'      => 9,
			'scope'        => 'full',
			'challenge'    => self::challenge(),
			'created'      => time(),
		);
		$canonical     = json_encode( array_merge( array( 'key' => $key ), $fields ) );
		$fields['sig'] = hash_hmac( 'sha256', $canonical, 'some-other-salt' );
		set_transient( $key, $fields, 120 );

		self::assertSame( array( 'error' => 'invalid_grant' ), $this->redeem( $code ) );
		self::assertSame( array(), AB_MCP_Settings::get_tokens() );

		// Same bytes signed with the site's salt do verify — so it is the salt
		// that made the difference, nothing else about the record.
		$fields['sig'] = hash_hmac( 'sha256', $canonical, wp_salt( 'auth' ) );
		set_transient( $key, $fields, 120 );
		self::assertArrayHasKey( 'access_token', $this->redeem( $code ) );
	}

	public function testAnUnencodableRecordIsNeitherStoredNorRedeemed(): void {
		$GLOBALS['ab_test_json_fail'] = true;
		$code = AB_MCP_OAuth::create_auth_code( self::CLIENT, self::REDIRECT, 7, 'content', self::challenge() );
		self::assertSame( '', $code, 'No code without a signature.' );
		self::assertSame( array(), $GLOBALS['ab_test_transients'], 'Nothing was stored.' );

		// A genuine record met by an encoding failure at redemption time is
		// refused too — never verified against an empty canonical form.
		$GLOBALS['ab_test_json_fail'] = false;
		$code = AB_MCP_OAuth::create_auth_code( self::CLIENT, self::REDIRECT, 7, 'content', self::challenge() );
		$GLOBALS['ab_test_json_fail'] = true;
		self::assertSame( array( 'error' => 'invalid_grant' ), $this->redeem( $code ) );
		self::assertSame( array(), AB_MCP_Settings::get_tokens() );
	}

	public function testTheCodeStaysSingleUseEvenWhenRefused(): void {
		$code = AB_MCP_OAuth::create_auth_code( self::CLIENT, self::REDIRECT, 7, 'content', self::challenge() );
		$GLOBALS['ab_test_transients'][ self::key( $code ) ]['scope'] = 'full';
		$this->redeem( $code );
		self::assertArrayNotHasKey( self::key( $code ), $GLOBALS['ab_test_transients'], 'Burned on first use.' );
	}
	public function testEveryTrustedFieldIsBound(): void {
		$changes = array(
			'client_id'    => 'other-client',
			'redirect_uri' => 'https://client.example/other',
			'user_id'      => 9,
			'scope'        => 'full',
			'challenge'    => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
			'created'      => time() + 3600,
		);
		foreach ( $changes as $field => $value ) {
			ab_test_reset();
			ab_test_add_user( 7 );
			ab_test_add_user( 9 );
			$this->setUp();
			$code = AB_MCP_OAuth::create_auth_code( self::CLIENT, self::REDIRECT, 7, 'read', self::challenge() );
			$GLOBALS['ab_test_transients'][ self::key( $code ) ][ $field ] = $value;

			self::assertSame( 'invalid_grant', $this->redeem( $code )['error'] ?? 'no error', "Field $field changed" );
			self::assertSame( array(), AB_MCP_Settings::get_tokens(), "Field $field changed, no token" );
		}
	}

	public function testFieldsCannotBeResplitUnderTheSameSignature(): void {
		// A separator join would sign the same bytes for
		//   redirect_uri = "...callback\x1f9", user_id = 7, scope = "read"   and
		//   redirect_uri = "...callback",      user_id = 9, scope = "7\x1fread".
		// The consent step registers the first; the store is then rewritten to
		// the second. JSON serialisation keeps the boundaries.
		$odd = self::REDIRECT . "\x1f9";
		update_option(
			AB_MCP_OAuth::OPT_CLIENTS,
			array( self::CLIENT => array( 'name' => 'Claude', 'redirect_uris' => array( $odd, self::REDIRECT ) ) )
		);
		$code = AB_MCP_OAuth::create_auth_code( self::CLIENT, $odd, 7, 'read', self::challenge() );
		$key  = self::key( $code );
		$GLOBALS['ab_test_transients'][ $key ]['redirect_uri'] = self::REDIRECT;
		$GLOBALS['ab_test_transients'][ $key ]['user_id']      = 9;
		$GLOBALS['ab_test_transients'][ $key ]['scope']        = "7\x1fread";

		self::assertSame( array( 'error' => 'invalid_grant' ), $this->redeem( $code ) );
		self::assertSame( array(), AB_MCP_Settings::get_tokens() );
	}

	public function testARecordMovedUnderAnotherCodeIsRefused(): void {
		$code   = AB_MCP_OAuth::create_auth_code( self::CLIENT, self::REDIRECT, 7, 'content', self::challenge() );
		$record = $GLOBALS['ab_test_transients'][ self::key( $code ) ];
		$other  = 'abac_' . str_repeat( 'ef', 32 );
		set_transient( self::key( $other ), $record, 120 );

		self::assertSame( array( 'error' => 'invalid_grant' ), $this->redeem( $other ) );
		self::assertSame( array(), AB_MCP_Settings::get_tokens() );
	}

	public function testAnOutlivedRecordIsRefusedEvenWithAValidSignature(): void {
		// A rewritten transient can carry any expiry; the signed timestamp
		// decides. The old record is re-signed here with the site's salt, as
		// someone who knows the salt could.
		$code = AB_MCP_OAuth::create_auth_code( self::CLIENT, self::REDIRECT, 7, 'content', self::challenge() );
		$key  = self::key( $code );
		$rec  = $GLOBALS['ab_test_transients'][ $key ];
		$rec['created'] = time() - AB_MCP_OAuth::CODE_TTL - 1;
		$rec['sig']     = hash_hmac(
			'sha256',
			json_encode( array( 'key' => $key, 'client_id' => self::CLIENT, 'redirect_uri' => self::REDIRECT, 'user_id' => 7, 'scope' => 'content', 'challenge' => self::challenge(), 'created' => $rec['created'] ) ),
			wp_salt( 'auth' )
		);
		$GLOBALS['ab_test_transients'][ $key ] = $rec;

		self::assertSame( array( 'error' => 'invalid_grant' ), $this->redeem( $code ) );
	}
	public function testAnUnknownScopeIsRefusedEvenWithAValidSignature(): void {
		$code = AB_MCP_OAuth::create_auth_code( self::CLIENT, self::REDIRECT, 7, 'content', self::challenge() );
		$key  = self::key( $code );
		$rec  = $GLOBALS['ab_test_transients'][ $key ];
		$rec['scope'] = 'everything';
		$rec['sig']   = hash_hmac(
			'sha256',
			json_encode( array( 'key' => $key, 'client_id' => self::CLIENT, 'redirect_uri' => self::REDIRECT, 'user_id' => 7, 'scope' => 'everything', 'challenge' => self::challenge(), 'created' => $rec['created'] ) ),
			wp_salt( 'auth' )
		);
		$GLOBALS['ab_test_transients'][ $key ] = $rec;

		self::assertSame( array( 'error' => 'invalid_grant' ), $this->redeem( $code ) );
		self::assertSame( array(), AB_MCP_Settings::get_tokens(), 'sanitize_scope() must not get the chance to turn it into read.' );
	}
}
