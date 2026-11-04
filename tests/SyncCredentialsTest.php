<?php
/**
 * The credential check of the takeover (AB_MCP_Sync_Credentials, Z8
 * decision 9): by the names AB_MCP_Redactor::redacts_key() redacts, over
 * the unpacked value of the smallest unit — the name of the unit makes it
 * B9, a field inside it (serialized, nested, JSON in a text, a list of name
 * and value) mixed; what does not unpack is unreadable, never clean.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Credentials;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/sync/class-sync-credentials.php';

final class SyncCredentialsTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
	}

	public static function units(): array {
		return array(
			'a name: exact'                 => array( 'password', 'x', 'b9', array() ),
			'a name: compound'              => array( 'stripe_secret_key', 'x', 'b9', array() ),
			'a name: camelCase'             => array( 'accessToken', 'x', 'b9', array() ),
			'a name: dashes'                => array( 'client-secret', 'x', 'b9', array() ),
			'a name about one'              => array( 'hasPassword', '1', 'clean', array() ),
			'a field, serialized'           => array( 'acme_settings', serialize( array( 'title' => 'x', 'apiKey' => 'y' ) ), 'mixed', array( 'apiKey' ) ),
			'a field, nested'               => array( 'acme_settings', serialize( array( 'mail' => array( 'smtp' => array( 'smtp_pass' => 'y' ) ) ) ), 'mixed', array( 'mail.smtp.smtp_pass' ) ),
			'a field, nested serialized'    => array( 'acme_settings', serialize( array( 'raw' => serialize( array( 'token' => 'y' ) ) ) ), 'mixed', array( 'raw.token' ) ),
			'a field, JSON in a text'       => array( 'acme_settings', '{"service":{"refresh_token":"y"}}', 'mixed', array( 'service.refresh_token' ) ),
			'a field, JSON list'            => array( 'acme_settings', '[{"x":1},{"client_secret":"y"}]', 'mixed', array( '1.client_secret' ) ),
			'a list of name and value'      => array( 'acme_form', serialize( array( array( 'name' => 'api_key', 'value' => 'y' ) ) ), 'mixed', array( '0.api_key' ) ),
			'a list of key and value'       => array( 'acme_form', serialize( array( array( 'key' => 'Authorization', 'value' => 'y' ) ) ), 'mixed', array( '0.Authorization' ) ),
			'two fields'                    => array( 'acme', serialize( array( 'password' => 'a', 'secret' => 'b', 'title' => 'c' ) ), 'mixed', array( 'password', 'secret' ) ),
			'harmless words'                => array( 'acme', serialize( array( 'token_count' => 3, 'password_hint' => 'x', 'showPassword' => true ) ), 'clean', array() ),
			'plain text'                    => array( 'acme', 'my password is not here as a name', 'clean', array() ),
			'an object'                     => array( 'acme', 'O:8:"stdClass":1:{s:5:"token";s:1:"y";}', 'unreadable', array() ),
			'a broken value'                => array( 'acme', 'a:1:{i:0;i:1;i:1;i:2;}', 'unreadable', array() ),
			'an int key'                    => array( 'acme', serialize( array( 0 => 'x' ) ), 'clean', array() ),
			'a JSON string literal'         => array( 'acme', json_encode( json_encode( array( 'api_key' => 'y' ) ) ), 'mixed', array( 'api_key' ) ),
			'JSON literal in serialized'    => array( 'acme', serialize( array( 'raw' => json_encode( json_encode( array( 'nested' => array( 'clientSecret' => 'y' ) ) ) ) ) ), 'mixed', array( 'raw.nested.clientSecret' ) ),
			'a plain JSON string literal'   => array( 'acme', '"nur Text"', 'clean', array() ),
		);
	}

	#[DataProvider( 'units' )]
	public function testEachUnitIsCleanB9MixedOrUnreadable( string $name, string $value, string $state, array $fields ): void {
		$check = AB_MCP_Sync_Credentials::unit( $name, $value );
		self::assertSame( $state, $check['state'] );
		self::assertSame( $fields, $check['fields'] );
		self::assertStringNotContainsString( '"y"', json_encode( $check ), 'Never a value.' );
	}

	public function testTooDeepJsonIsUnreadable(): void {
		$json = str_repeat( '[', 600 ) . str_repeat( ']', 600 );
		self::assertSame( 'unreadable', AB_MCP_Sync_Credentials::unit( 'acme', $json )['state'] );
	}

	public function testWhatIsNotReadToItsEndLocksAndIsNeverClean(): void {
		// 66 nested arrays, the name of a credential at the bottom: deeper
		// than the walk reads (MAX_DEPTH 64), within what json_decode() reads.
		$deep = '{"api_key":"y"}';
		for ( $i = 0; $i < 66; $i++ ) {
			$deep = '[' . $deep . ']';
		}
		self::assertIsArray( json_decode( $deep, true ) );
		$check = AB_MCP_Sync_Credentials::unit( 'acme', $deep );
		self::assertSame( 'unreadable', $check['state'] );
		$acc = AB_MCP_Sync_Canon::acc();
		self::assertTrue( AB_MCP_Sync_Credentials::note( $acc, 'post', 'meta:acme', $check ) );
		self::assertSame( array( '*:unreadable_value' ), array_column( $acc['locks'], 'key' ) );
		self::assertSame( 'meta:acme', $acc['locks'][0]['at'] );

		$acc = AB_MCP_Sync_Canon::acc();
		AB_MCP_Sync_Credentials::note( $acc, 'post', 'meta:obj', AB_MCP_Sync_Credentials::unit( 'obj', 'O:8:"stdClass":1:{s:5:"token";s:1:"y";}' ) );
		self::assertSame( array( '*:php_object' ), array_column( $acc['locks'], 'key' ), 'An object locks as one.' );
	}

	public function testANameASiteRedactsOnTopCounts(): void {
		self::assertSame( 'clean', AB_MCP_Sync_Credentials::unit( 'acme_pin', 'x', array() )['state'] );
		self::assertSame( 'b9', AB_MCP_Sync_Credentials::unit( 'acme_pin', 'x', array( 'acme_pin' ) )['state'] );
	}

	public function testB9IsCountedAndLeftOutMixedLocks(): void {
		$acc = AB_MCP_Sync_Canon::acc();
		self::assertFalse( AB_MCP_Sync_Credentials::note( $acc, 'post', 'meta:api_key', AB_MCP_Sync_Credentials::unit( 'api_key', 'x' ) ) );
		self::assertTrue( AB_MCP_Sync_Credentials::note( $acc, 'post', 'meta:acme', AB_MCP_Sync_Credentials::unit( 'acme', serialize( array( 'token' => 'y' ) ) ) ) );
		self::assertTrue( AB_MCP_Sync_Credentials::note( $acc, 'post', 'meta:title', AB_MCP_Sync_Credentials::unit( 'title', 'x' ) ) );
		self::assertSame( array( 'meta:api_key' ), $acc['credentials']['b9'] );
		self::assertSame( array( array( 'unit' => 'meta:acme', 'fields' => array( 'token' ) ) ), $acc['credentials']['mixed'] );
		self::assertSame( array( '*:credential_field' ), array_column( $acc['locks'], 'key' ) );
		self::assertSame( 'meta:acme', $acc['locks'][0]['at'] );
	}

	public function testAtMostFiftyPathsAreNamed(): void {
		$value = array();
		for ( $i = 0; $i < 80; $i++ ) {
			$value[ "k$i" ] = array( 'token' => 'y' );
		}
		self::assertCount( AB_MCP_Sync_Credentials::MAX_PATHS, AB_MCP_Sync_Credentials::unit( 'acme', serialize( $value ) )['fields'] );
	}
}
