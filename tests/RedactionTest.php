<?php
/**
 * Reading tools answer with [REDACTED] where another plugin's data holds a
 * credential (AB_MCP_Redactor, applied in AB_MCP_REST_Controller::run_tool()).
 *
 * What must hold:
 *
 * - A value under a key the meta guard takes for a credential, in any of
 *   its usual spellings, reads as [REDACTED] at any depth: in arrays,
 *   objects, serialized data and JSON inside a value; the key stays, and so
 *   does everything else, byte for byte where it is text.
 * - Harmless keys (token_count, co_author, password_hint,
 *   password_protected) and values that only say whether one is set (true,
 *   false, null, '') stay.
 * - The error log's text loses the credentials it holds: Authorization
 *   headers after the scheme, Bearer tokens, password=, token=, api_key=
 *   pairs, print_r and var_export entries and whole blocks; ordinary lines
 *   stay.
 * - What cannot be judged (too deep, too large, a pattern that gives up, an
 *   object that throws) reads as [REDACTED] as a whole and is listed as
 *   unchecked: fail-closed.
 * - A writing tool's answer is as it was (wp_create_user's one-time
 *   password); of wp_run_ability's answer only result is searched.
 * - The answer names every place in redacted, as a JSON pointer, never with
 *   the value; an answer with nothing redacted has no such field.
 * - The filter ab_mcp_redact_keys adds keys and cannot take the built-in
 *   ones away.
 * - restore_placeholders() puts the stored value back where a value to be
 *   written is exactly [REDACTED], and the stored key where a key reads
 *   name=[REDACTED].
 * - A writing tool refuses the placeholder as an answer holds it: the whole
 *   value, inside JSON or serialized data at any level of escaping, a key
 *   name=[REDACTED], found by decoding as the reader decodes. A text that
 *   only mentions or quotes it passes, and so does what people write
 *   (content, captions, alt texts, descriptions, a text widget's text),
 *   unless the reader redacts that key (the filter's names included).
 * - Every rule of the error log reads every line: a Bearer token before a
 *   pair is redacted too; a value in brackets that a credential's name
 *   opens (pretty-printed JSON) to its closing bracket, fail-closed to the
 *   end of the entry.
 * - The fields of a page-builder element are what people wrote
 *   (AB_MCP_Redactor::TEXT_ARGS), unless the reader redacts the field.
 *
 * The cases named «Codex #n» are the counterexamples of the review of
 * 30.10.2026. The values below are made-up placeholders, not credentials.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Redactor;
use AB_MCP_REST_Controller;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Content;
use AB_MCP_Tools_System;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use WP_REST_Request;

require_once dirname( __DIR__ ) . '/includes/tools/class-tools-base.php';
require_once dirname( __DIR__ ) . '/includes/tools/class-tools-content.php';
require_once dirname( __DIR__ ) . '/includes/tools/class-tools-system.php';

final class RedactionTest extends TestCase {

	private const R = AB_MCP_Redactor::MARK;

	protected function setUp(): void {
		ab_test_reset();
	}

	/**
	 * A reading tool's result as its answer carries it.
	 *
	 * @param mixed $result Result.
	 * @return mixed
	 */
	private function read( $result, string $tool = 'wp_get_post_meta' ) {
		return AB_MCP_Redactor::tool_result( $tool, array(), $result );
	}

	/* ------------------------------------------------------------ structure */

	public function testValuesUnderCredentialKeysInNestedMetaAreRedactedAndTheRestStays(): void {
		$out = $this->read(
			array(
				'id'   => 7,
				'meta' => array(
					'color'    => 'red',
					'settings' => array(
						'api_key' => 'PLACEHOLDER-1',
						'url'     => 'https://example.org',
						'deep'    => array( array( 'client_secret' => 'PLACEHOLDER-2', 'label' => 'Shop' ) ),
					),
				),
			)
		);

		self::assertSame( self::R, $out['meta']['settings']['api_key'] );
		self::assertSame( self::R, $out['meta']['settings']['deep'][0]['client_secret'] );
		self::assertSame( 'red', $out['meta']['color'] );
		self::assertSame( 'https://example.org', $out['meta']['settings']['url'] );
		self::assertSame( 'Shop', $out['meta']['settings']['deep'][0]['label'] );
		self::assertSame( 7, $out['id'] );
		self::assertSame( array( '/meta/settings/api_key', '/meta/settings/deep/0/client_secret' ), $out['redacted'] );
		self::assertArrayNotHasKey( 'redacted_unchecked', $out );
	}

	public function testAWholeArrayUnderACredentialKeyIsRedacted(): void {
		$out = $this->read( array( 'meta' => array( 'credentials' => array( 'user' => 'a', 'pass' => 'PLACEHOLDER' ) ) ) );

		self::assertSame( self::R, $out['meta']['credentials'] );
		self::assertSame( array( '/meta/credentials' ), $out['redacted'] );
	}

	public function testObjectsAreSearchedByTheirPublicPropertiesAndStayObjects(): void {
		$inner               = new \stdClass();
		$inner->access_token = 'PLACEHOLDER-OBJ';
		$inner->expires      = 3600;
		$json                = new class() implements \JsonSerializable {
			public function jsonSerialize(): array {
				return array( 'refresh_token' => 'PLACEHOLDER-JS', 'scope' => 'read' );
			}
		};

		$out = $this->read( array( 'meta' => array( 'conn' => $inner, 'other' => $json, 'empty' => new \stdClass() ) ) );

		self::assertSame( self::R, $out['meta']['conn']->access_token );
		self::assertSame( 3600, $out['meta']['conn']->expires );
		self::assertSame( 'PLACEHOLDER-OBJ', $inner->access_token, 'The object the tool returned is not changed in place.' );
		self::assertSame( array( 'refresh_token' => self::R, 'scope' => 'read' ), $out['meta']['other'] );
		self::assertSame( '{"conn":{"access_token":"[REDACTED]","expires":3600},"other":{"refresh_token":"[REDACTED]","scope":"read"},"empty":{}}', wp_json_encode( $out['meta'], JSON_UNESCAPED_SLASHES ) );
	}

	public function testJsonSerializableIsAskedOnceAndItsAnswerKept(): void {
		// Codex #2: harmless at the check, a secret at the encoding.
		$fickle = new class() implements \JsonSerializable {
			public int $calls = 0;
			public function jsonSerialize(): array {
				++$this->calls;
				return 1 === $this->calls ? array( 'ok' => true ) : array( 'password' => 'PLACEHOLDER-LATE' );
			}
		};

		$out = $this->read( array( 'meta' => array( 'x' => $fickle ) ) );

		self::assertSame( array( 'ok' => true ), $out['meta']['x'] );
		self::assertSame( 1, $fickle->calls );
		self::assertStringNotContainsString( 'PLACEHOLDER', (string) wp_json_encode( $out ) );
	}

	public function testAnObjectThatThrowsIsNotJudgedAndReadsAsRedacted(): void {
		$broken = new class() implements \JsonSerializable {
			public function jsonSerialize(): array {
				throw new \RuntimeException( 'no' );
			}
		};

		$out = $this->read( array( 'meta' => array( 'x' => $broken ) ) );

		self::assertSame( self::R, $out['meta']['x'] );
		self::assertSame( array( '/meta/x' ), $out['redacted_unchecked'] );
	}

	public function testPrivateAndProtectedPropertiesAreJudgedByTheirName(): void {
		// Codex #4: unserialize() without the class keeps "\0Class\0password" and "\0*\0token".
		$stored = serialize( new RedactionPrivateProbe() );

		$out = $this->read( array( 'meta' => array( 'blob' => $stored, 'object' => unserialize( $stored, array( 'allowed_classes' => false ) ) ) ) );

		self::assertStringNotContainsString( 'PLACEHOLDER', $out['meta']['blob'] );
		self::assertStringContainsString( 'visible', $out['meta']['blob'] );
		self::assertNotFalse( unserialize( $out['meta']['blob'], array( 'allowed_classes' => false ) ), 'Still valid serialized data.' );
		self::assertStringNotContainsString( 'PLACEHOLDER', (string) wp_json_encode( $out['meta']['object'] ) );
	}

	public function testReferenceCyclesEndInTheBudget(): void {
		// Codex #1: 30 bytes that branch at every level.
		$cycle = unserialize( 'a:2:{s:1:"a";R:1;s:1:"b";R:1;}' );
		$start = microtime( true );

		$out = $this->read( array( 'meta' => array( 'loop' => $cycle ) ) );

		self::assertLessThan( 10.0, microtime( true ) - $start );
		self::assertNotEmpty( $out['redacted_unchecked'] );
		self::assertNotFalse( wp_json_encode( $out ), 'The answer can be encoded.' );
	}

	public function testBeyondTheBudgetEverythingIsReplacedAndListed(): void {
		$many = array();
		for ( $i = 0; $i < AB_MCP_Redactor::BUDGET + 100; $i++ ) {
			$many[] = array( 'n' => 'text ' . $i );
		}

		$out = $this->read( array( 'meta' => array( 'list' => $many ) ) );

		// The answer, meta and list count as three.
		self::assertSame( array( 'n' => 'text 0' ), $out['meta']['list'][0] );
		self::assertSame( array( 'n' => 'text ' . ( AB_MCP_Redactor::BUDGET - 4 ) ), $out['meta']['list'][ AB_MCP_Redactor::BUDGET - 4 ] );
		self::assertSame( self::R, $out['meta']['list'][ AB_MCP_Redactor::BUDGET - 3 ] );
		self::assertSame( self::R, $out['meta']['list'][ AB_MCP_Redactor::BUDGET + 99 ] );
		self::assertSame( '/meta/list/' . ( AB_MCP_Redactor::BUDGET - 3 ), $out['redacted_unchecked'][0] );
		self::assertCount( AB_MCP_Redactor::MAX_PATHS, $out['redacted_unchecked'] );
		self::assertCount( AB_MCP_Redactor::MAX_PATHS, $out['redacted'] );
		self::assertSame( 103, $out['redacted_count'] );
	}

	public function testTooDeepIsReplacedAndListed(): void {
		$deep = 'PLACEHOLDER';
		for ( $i = 0; $i < AB_MCP_Redactor::MAX_DEPTH + 5; $i++ ) {
			$deep = array( 'x' => $deep );
		}

		$out = $this->read( array( 'meta' => $deep ) );

		self::assertStringNotContainsString( 'PLACEHOLDER', (string) wp_json_encode( $out ) );
		self::assertCount( 1, $out['redacted_unchecked'] );
	}

	/* ------------------------------------------------------------------ text */

	public function testSerializedDataInsideAValueIsRedactedWhereItStands(): void {
		$stored = serialize( array( 'license_key' => 'PLACEHOLDER-SER', 'seats' => 3, 'note' => 'a "quoted" word', 'token' => '', 'secret' => null, 'otp' => false, 'tokens' => array() ) );

		$out = $this->read( array( 'meta' => array( 'plugin_settings' => $stored ) ) );

		self::assertSame( 'a:7:{s:11:"license_key";s:10:"[REDACTED]";s:5:"seats";i:3;s:4:"note";s:15:"a "quoted" word";s:5:"token";s:0:"";s:6:"secret";N;s:3:"otp";b:0;s:6:"tokens";a:0:{}}', $out['meta']['plugin_settings'] );
		self::assertSame( array( '/meta/plugin_settings/license_key' ), $out['redacted'] );
	}

	public function testNestedSerializedTextKeepsValidLengths(): void {
		// Codex #5: serialize( serialize( … ) ).
		$stored = serialize( serialize( array( 'password' => 'PLACEHOLDER', 'n' => 1 ) ) );

		$out = $this->read( array( 'meta' => array( 'twice' => $stored ) ) );

		$inner = unserialize( $out['meta']['twice'] );
		self::assertIsString( $inner );
		self::assertSame( array( 'password' => self::R, 'n' => 1 ), unserialize( $inner ) );
	}

	public function testASerializedObjectIsReadWithoutRunningItsClass(): void {
		$probe                       = new RedactionWakeupProbe();
		$probe->secret               = 'PLACEHOLDER';
		$stored                      = serialize( $probe );
		RedactionWakeupProbe::$woken = 0;

		$out = $this->read( array( 'meta' => array( 'blob' => $stored, 'unknown' => 'O:19:"AB_Test_Not_A_Class":1:{s:6:"secret";s:11:"PLACEHOLDER";}' ) ) );

		self::assertStringNotContainsString( 'PLACEHOLDER', $out['meta']['blob'] );
		self::assertStringNotContainsString( 'PLACEHOLDER', $out['meta']['unknown'] );
		self::assertSame( 0, RedactionWakeupProbe::$woken, 'No code of the stored class ran.' );
		self::assertFalse( class_exists( 'AB_Test_Not_A_Class', false ) );
	}

	public function testATextThatDoesNotDecodeStaysAsItIs(): void {
		$cut = 'a:2:{s:5:"token";s:11:"PLACEHOLDER";s:4:"rest";s:200:"cut sho';

		self::assertSame( array( 'meta' => array( 'damaged' => $cut ) ), $this->read( array( 'meta' => array( 'damaged' => $cut ) ) ) );
	}

	public function testJsonInsideAValueIsDecodedAndEncodedAgainOnlyWhereSomethingWasRedacted(): void {
		$doc   = "{\n  \"smtp\": {\"host\":\"mail.example.org\", \"smtp_pass\" : \"PLACEHOLDER-JSON\"},\n  \"empty\": {},\n  \"map\": {\"0\":\"zero\",\"1\":\"one\"},\n  \"big\": 123456789012345678901234567890,\n  \"api_key\": [\"PLACEHOLDER-A\", {\"b\": \"]\"}],\n  \"pin\": 4711,\n  \"otp\": 123456,\n  \"token\": \"\",\n  \"secret\": null\n}";
		$clean = "{\n  \"empty\": {},\n  \"map\": {\"0\":\"zero\"},\n  \"big\": 123456789012345678901234567890\n}";

		$out = $this->read( array( 'meta' => array( 'config' => $doc, 'clean' => $clean ) ) );

		self::assertSame( '{"smtp":{"host":"mail.example.org","smtp_pass":"[REDACTED]"},"empty":{},"map":{"0":"zero","1":"one"},"big":"123456789012345678901234567890","api_key":"[REDACTED]","pin":4711,"otp":"[REDACTED]","token":"","secret":null}', $out['meta']['config'] );
		self::assertSame( $clean, $out['meta']['clean'], 'Nothing redacted: byte for byte.' );
		self::assertSame( array( '/meta/config/smtp/smtp_pass', '/meta/config/api_key', '/meta/config/otp' ), $out['redacted'] );
	}

	public function testEscapedNamesAndBlanksInJsonDoNotHideAKey(): void {
		// Codex 2 #1.
		$out = $this->read( array( 'meta' => array( 'a' => '{"password":"PLACEHOLDER"}', 'b' => '{"password"' . str_repeat( ' ', 65 ) . ':"PLACEHOLDER"}' ) ) );

		self::assertStringNotContainsString( 'PLACEHOLDER', (string) wp_json_encode( $out ) );
	}

	public function testThePlaceholderDoesNotShieldOtherSecrets(): void {
		// Codex 2 #2.
		$out = $this->read( array( 'meta' => array( 'a' => '{"credentials":{"old":"[REDACTED]","current":"PLACEHOLDER"}}', 'b' => '{"password":"PLACEHOLDER[REDACTED]"}', 'c' => array( 'old' => '[REDACTED]', 'token' => 'PLACEHOLDER' ) ) ) );

		self::assertStringNotContainsString( 'PLACEHOLDER', (string) wp_json_encode( $out ) );
	}

	public function testPairsKeysAndContentFieldsInsideEncodedDataFollowTheSameRules(): void {
		// Codex 2 #3, #10, #13.
		$out = $this->read(
			array(
				'meta' => array(
					'pair_json' => json_encode( array( 'key' => 'password', 'value' => 'PLACEHOLDER-1' ) ),
					'pair_ser'  => serialize( array( 'key' => 'password', 'value' => 'PLACEHOLDER-2' ) ),
					'key_json'  => '{"api_key=PLACEHOLDER-3":"x"}',
					'key_ser'   => serialize( array( 'api_key=PLACEHOLDER-4' => 'x' ) ),
					'layout'    => serialize( array( 'content' => '{"password":"tutorial"}' ) ),
				),
			)
		);

		self::assertStringNotContainsString( 'PLACEHOLDER', (string) wp_json_encode( $out ) );
		self::assertSame( serialize( array( 'content' => '{"password":"tutorial"}' ) ), $out['meta']['layout'], 'A text people wrote stays, inside encoded data too.' );
	}

	public function testEncodingsInsideEachOtherAreDecodedInTurn(): void {
		// Codex 2 #5, #6.
		$deep = '{"password":"PLACEHOLDER"}';
		for ( $i = 0; $i < 101; $i++ ) {
			$deep = serialize( $deep );
		}

		$out = $this->read( array( 'meta' => array( 'mixed' => json_encode( array( 'data' => serialize( array( 'password' => 'PLACEHOLDER' ) ) ) ), 'deep' => $deep ) ) );

		self::assertSame( array( 'password' => self::R ), unserialize( json_decode( $out['meta']['mixed'], true )['data'] ) );
		self::assertSame( self::R, $out['meta']['deep'] );
		self::assertSame( array( '/meta/deep' ), $out['redacted_unchecked'] );
	}

	public function testJsonTooDeepToDecodeIsNotHandedOut(): void {
		$deep = '"PLACEHOLDER"';
		for ( $i = 0; $i < AB_MCP_Redactor::MAX_DEPTH + 5; $i++ ) {
			$deep = '{"password":' . $deep . '}';
		}

		$out = $this->read( array( 'meta' => array( 'deep' => $deep ) ) );

		self::assertSame( self::R, $out['meta']['deep'] );
		self::assertSame( array( '/meta/deep' ), $out['redacted_unchecked'] );
	}

	public function testALargeTextIsReadInLinearTime(): void {
		// Codex 2 #7.
		$start = microtime( true );

		$out = $this->read( array( 'meta' => array( 'x' => str_repeat( '{"token":[', 4000 ) ) ) );

		self::assertLessThan( 1.0, microtime( true ) - $start );
		self::assertSame( str_repeat( '{"token":[', 4000 ), $out['meta']['x'], 'Not JSON: as it is.' );
	}

	public function testReferencesAreNotBrokenByARedaction(): void {
		// Codex 2 #8.
		$x      = 'visible';
		$stored = serialize( array( 'password' => array( 'x' => 'PLACEHOLDER' ), 'a' => &$x, 'b' => &$x ) );

		$back = unserialize( $this->read( array( 'meta' => array( 'v' => $stored ) ) )['meta']['v'] );

		self::assertSame( array( 'password' => self::R, 'a' => 'visible', 'b' => 'visible' ), $back );
	}

	public function testOtherObjectsAreJudgedAsTheClientGetsThem(): void {
		// Codex 2 #9, Codex 3 #3.
		$date     = new \DateTimeImmutable( '2026-10-30T12:00:00+01:00' );
		$settings = new \ArrayObject( array( 'password' => 'PLACEHOLDER', 'color' => 'blue' ) );
		$broken   = new class() {
			/** @var resource A value JSON has no form for. */
			public $handle;
		};
		$broken->handle = fopen( 'php://memory', 'r' );

		$out = $this->read( array( 'meta' => array( 'date' => $date, 'settings' => $settings, 'broken' => $broken ) ) );

		self::assertSame( json_encode( $date ), json_encode( $out['meta']['date'] ) );
		self::assertSame( '{"password":"[REDACTED]","color":"blue"}', json_encode( $out['meta']['settings'] ) );
		self::assertSame( self::R, $out['meta']['broken'] );
		self::assertSame( array( '/meta/broken' ), $out['redacted_unchecked'] );
		self::assertSame( array( '/meta/settings/password', '/meta/broken' ), $out['redacted'] );
	}

	public function testJsonInsideJsonIsFoundAtEveryLevelOfEscaping(): void {
		// Codex #5: json_encode( json_encode( … ) ), and four layers.
		$doc = array( 'password' => 'PLACEHOLDER', 'keep' => 'me' );
		for ( $i = 0; $i < 4; $i++ ) {
			$doc = array( 'layer' . $i => json_encode( $doc ) );
		}
		$twice = json_encode( json_encode( array( 'password' => 'PLACEHOLDER-2', 'pin' => 1234 ) ) );

		$out = $this->read( array( 'meta' => array( 'four' => json_encode( $doc ), 'twice' => $twice ) ) );

		self::assertStringNotContainsString( 'PLACEHOLDER', $out['meta']['four'] );
		self::assertStringContainsString( 'me', $out['meta']['four'] );
		$back = json_decode( $out['meta']['four'], true );
		for ( $i = 3; $i >= 0; $i-- ) {
			$back = json_decode( $back[ 'layer' . $i ], true );
		}
		self::assertSame( array( 'password' => self::R, 'keep' => 'me' ), $back, 'Still valid JSON at every layer.' );
		self::assertSame( array( 'password' => self::R, 'pin' => 1234 ), json_decode( json_decode( $out['meta']['twice'] ), true ) );
	}

	public function testThroughWpGetPostMetaAsWordPressStoresIt(): void {
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		ab_test_add_post( 5 );
		add_post_meta( 5, 'shop_settings', array( 'currency' => 'CHF', 'gateway' => array( 'Secret_Key' => 'PLACEHOLDER-A' ) ) );
		// A value that already looks serialized is serialized a second time
		// on its way in (maybe_serialize()); the reader returns it as text.
		add_post_meta( 5, 'legacy', serialize( array( 'apikey' => 'PLACEHOLDER-B' ) ) );
		add_post_meta( 5, 'api_key', 'PLACEHOLDER-C' );

		$def = array( 'callback' => array( AB_MCP_Tools_Content::class, 'get_post_meta_tool' ) );
		$out = AB_MCP_Redactor::tool_result( 'wp_get_post_meta', $def, AB_MCP_Tools_Content::get_post_meta_tool( array( 'id' => 5 ) ) );

		self::assertArrayNotHasKey( 'api_key', $out['meta'], 'A credential key is still left out by the meta guard, not redacted.' );
		self::assertSame( 'CHF', $out['meta']['shop_settings']['currency'] );
		self::assertSame( self::R, $out['meta']['shop_settings']['gateway']['Secret_Key'] );
		self::assertSame( array( 'apikey' => self::R ), unserialize( $out['meta']['legacy'] ) );
		self::assertSame( array( '/meta/shop_settings/gateway/Secret_Key', '/meta/legacy/apikey' ), $out['redacted'] );
		self::assertStringNotContainsString( 'PLACEHOLDER', (string) wp_json_encode( $out ) );
	}

	/* ----------------------------------------------------------------- keys */

	public function testTheUsualSpellingsOfACredentialNameAreRedacted(): void {
		// Codex #10.
		$out = $this->read(
			array(
				'meta' => array(
					'API_KEY'             => 'x1',
					' Secret '            => 'x2',
					'Password'            => 'x3',
					'Stripe_Access_Token' => 'x4',
					'accessToken'         => 'x5',
					'client-secret'       => 'x6',
					'api key'             => 'x7',
					"password\u{00A0}"    => 'x8',
					'X-API-Key'           => 'x9',
					'APIKey'              => 'x10',
					'refreshToken'        => 'x11',
					'PassWD'              => 'x12',
					'oAuth'               => 'x13',
					'APISecret'           => 'x14',
					'SMTPPassword'        => 'x15',
					'userPassword'        => 'x16',
					'pass'                => 'x17',
					'mailPass'            => 'x18',
					'db_pass'             => 'x19',
				),
			)
		);

		foreach ( $out['meta'] as $key => $value ) {
			self::assertSame( self::R, $value, (string) $key );
		}
	}

	public function testHarmlessKeysStay(): void {
		$meta = array(
			'token_count'        => 12,
			'tokenCount'         => 13,
			'totalTokens'        => 25,
			'hasPassword'        => 1,
			'showPassword'       => 'yes',
			'sessionTokenCount'  => 4,
			'userPasswordHint'   => 'the dog',
			'bypass'             => 'cache',
			'passed'             => 3,
			'compass'            => 'north',
			'passCount'          => 2,
			'author'             => 'Anna',
			'co_author'          => 'Ben',
			'Co-Author'          => 'Cleo',
			'password_hint'      => 'the usual',
			'passwordHint'       => 'the other',
			'password_protected' => true,
			'credential_type'    => 'oauth2',
			'key'                => 'color',
			'post_password'      => '',
			'secret'             => false,
			'access_token'       => null,
			'tokens'             => array(),
			'fields'             => array(
				array( 'key' => 'api_key', 'value' => '' ),
				array( 'meta_key' => 'access_token', 'meta_value' => null ),
				array( 'name' => 'password', 'value' => false ),
			),
		);

		$out = $this->read( array( 'meta' => $meta ) );

		self::assertSame( array( 'meta' => $meta ), $out, 'Nothing changed, and no redacted field.' );
	}

	public function testAResultWithNothingToRedactIsReturnedAsItWas(): void {
		$result = array( 'id' => 1, 'title' => 'Hello', 'meta' => array( 'color' => 'red', 'html' => '<a href="x" style="color:red">"quoted": text</a>' ) );

		self::assertSame( $result, $this->read( $result ) );
		self::assertSame( 'plain text', $this->read( 'plain text' ) );
	}

	public function testPairsWithTheNameInOneFieldAreRedactedByThatName(): void {
		$out = $this->read(
			array(
				'meta_data' => array(
					array( 'id' => 1, 'key' => '_stripe_api_key', 'value' => 'PLACEHOLDER-P1' ),
					array( 'id' => 2, 'key' => 'gift_note', 'value' => 'Happy birthday' ),
				),
				'rows'      => array(
					array( 'meta_key' => 'refresh_token', 'meta_value' => 'PLACEHOLDER-P2' ),
					array( 'option_name' => 'my_license_key', 'option_value' => 'PLACEHOLDER-P3' ),
				),
				'fields'    => array( array( 'name' => 'password', 'type' => 'string' ) ),
			)
		);

		self::assertSame( self::R, $out['meta_data'][0]['value'] );
		self::assertSame( '_stripe_api_key', $out['meta_data'][0]['key'] );
		self::assertSame( 'Happy birthday', $out['meta_data'][1]['value'] );
		self::assertSame( self::R, $out['rows'][0]['meta_value'] );
		self::assertSame( self::R, $out['rows'][1]['option_value'] );
		self::assertSame( array( 'name' => 'password', 'type' => 'string' ), $out['fields'][0], 'A field that names a password without holding one stays.' );
		self::assertSame( array( '/meta_data/0/value', '/rows/0/meta_value', '/rows/1/option_value' ), $out['redacted'] );
	}

	public function testTheAbilitySchemasAreNotSearchedAndNothingElseIsExempt(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array( 'password' => array( 'type' => 'string' ), 'api_key' => array( 'type' => 'string' ) ),
		);

		$list = $this->read( array( 'abilities' => array( array( 'name' => 'x/login', 'input_schema' => $schema, 'output_schema' => $schema ) ) ), 'wp_list_abilities' );
		$one  = $this->read( array( 'ability' => array( 'name' => 'x/login', 'input_schema' => $schema ) ), 'wp_list_abilities' );

		self::assertSame( $schema, $list['abilities'][0]['input_schema'] );
		self::assertSame( $schema, $list['abilities'][0]['output_schema'] );
		self::assertSame( $schema, $one['ability']['input_schema'] );
		self::assertArrayNotHasKey( 'redacted', $list );
		// Codex #6: the name alone exempts nothing.
		self::assertSame( self::R, $this->read( array( 'meta' => array( 'input_schema' => array( 'password' => 'PLACEHOLDER' ) ) ) )['meta']['input_schema']['password'] );
		$run = AB_MCP_Redactor::tool_result( 'wp_run_ability', array(), array( 'result' => array( 'input_schema' => array( 'password' => 'PLACEHOLDER' ) ) ) );
		self::assertSame( self::R, $run['result']['input_schema']['password'] );
	}

	public function testTheReadingToolsOwnTokensAndListsStay(): void {
		$undo = array( 'undo_points' => array( array( 'token' => 'undo_abc', 'tool' => 'wp_update_post' ) ), 'count' => 1 );
		$pw   = array( 'user_id' => 3, 'passwords' => array( array( 'uuid' => 'u-1', 'name' => 'Laptop' ) ) );

		self::assertSame( $undo, $this->read( $undo, 'wp_undo_list' ) );
		self::assertSame( $pw, $this->read( $pw, 'wp_list_application_passwords' ) );
		// The exemption belongs to the tool and to that path, nowhere else.
		self::assertSame( self::R, $this->read( $undo, 'wp_get_cron' )['undo_points'][0]['token'] );
		self::assertSame( self::R, $this->read( $pw, 'wp_get_cron' )['passwords'] );
		$nested = array( 'undo_points' => array( array( 'meta' => array( 'token' => 'PLACEHOLDER' ) ) ) );
		self::assertSame( self::R, $this->read( $nested, 'wp_undo_list' )['undo_points'][0]['meta']['token'] );
		// Below a kept key, every key is judged again.
		$inside = array( 'passwords' => array( array( 'uuid' => 'u-1', 'secret' => 'PLACEHOLDER' ) ) );
		self::assertSame( self::R, $this->read( $inside, 'wp_list_application_passwords' )['passwords'][0]['secret'] );
	}

	public function testTheExtraNamesAreRedactedButNotRefusedByTheMetaGuard(): void {
		$out = $this->read(
			array(
				'headers' => array( 'Authorization' => 'Basic PLACEHOLDER', 'Proxy-Authorization' => 'PLACEHOLDER', 'Accept' => 'text/html' ),
				'auth'    => 'PLACEHOLDER',
				'rows'    => array( array( 'ID' => 1, 'user_login' => 'anna', 'user_pass' => '$P$PLACEHOLDER', 'user_activation_key' => 'PLACEHOLDER' ) ),
			)
		);

		self::assertSame( self::R, $out['headers']['Authorization'] );
		self::assertSame( self::R, $out['headers']['Proxy-Authorization'] );
		self::assertSame( 'text/html', $out['headers']['Accept'] );
		self::assertSame( self::R, $out['auth'] );
		self::assertSame( array( 'ID' => 1, 'user_login' => 'anna', 'user_pass' => self::R, 'user_activation_key' => self::R ), $out['rows'][0] );
		$guard = new ReflectionMethod( 'AB_MCP_Tools_Base', 'is_sensitive_meta_key' );
		foreach ( AB_MCP_Redactor::EXTRA_NAMES as $name ) {
			self::assertFalse( $guard->invoke( null, $name ), $name );
		}
	}

	public function testTheMetaGuardKeepsItsRule(): void {
		$guard = new ReflectionMethod( 'AB_MCP_Tools_Base', 'is_sensitive_meta_key' );
		foreach ( array( 'pass', 'token', 'Tokens', ' secret ', 'PASSWORD', 'pwd', 'otp', 'credentials', 'my_api_key', 'apikey', 'x-api-key', 'stripe_secret_key', 'oauth_state', 'smtp_password', 'smtp_pass', 'passwd_old', 'license_key' ) as $key ) {
			self::assertTrue( $guard->invoke( null, $key ), $key );
		}
		// As written: the spellings only redaction reads are no refusal.
		foreach ( array( '', ' ', 'token_count', 'password_hint', 'credential_type', 'tokens2', 'pwd2', 'otpx', 'bypass', 'passed', 'color', 'author', 'password_protected', 'co_author', 'accessToken', 'client-secret' ) as $key ) {
			self::assertFalse( $guard->invoke( null, $key ), "'$key'" );
		}
		require_once dirname( __DIR__ ) . '/includes/tools/class-tools-search-replace.php';
		self::assertSame( AB_MCP_Redactor::CREDENTIAL_EXACT, \AB_MCP_Tools_Search_Replace::SENSITIVE_META_EXACT, 'The SQL mirror reads the same list.' );
		self::assertSame( AB_MCP_Redactor::CREDENTIAL_NEEDLES, \AB_MCP_Tools_Search_Replace::SENSITIVE_META_NEEDLES );
	}

	/* ------------------------------------------------------------- the log */

	public function testTheErrorLogLosesTheCredentialsInItsText(): void {
		$log = implode(
			"\n",
			array(
				'[30-Oct-2026 10:00:00 UTC] HTTP request headers: Authorization: Bearer PLACEHOLDER.tok-1',
				'[30-Oct-2026 10:00:01 UTC] curl: > Proxy-Authorization: Basic UExBQ0VIT0xERVI=',
				'[30-Oct-2026 10:00:02 UTC] sent bearer PLACEHOLDERtok2 to the API',
				'[30-Oct-2026 10:00:03 UTC] GET https://api.example.org/v1/items?page=2&api_key=PLACEHOLDER3&lang=de',
				'[30-Oct-2026 10:00:04 UTC] callback https://example.org/cb?code=1&access_token=PLACEHOLDER4',
				'[30-Oct-2026 10:00:05 UTC] login failed for user=anna password=PLACEHOLDER5',
				'[30-Oct-2026 10:00:06 UTC] response {"Token":"PLACEHOLDER 6","ok":true}',
				'[30-Oct-2026 10:00:07 UTC] Array',
				'(',
				'    [user] => anna',
				'    [smtp_pass] => PLACEHOLDER 7 with blanks',
				')',
				"[30-Oct-2026 10:00:08 UTC] array ( 'client_secret' => 'PLACEHOLDER\\'8', 'ttl' => 60 )",
				'[30-Oct-2026 10:00:09 UTC] Authorization: Digest username="anna", nonce="PLACEHOLDER9", response="PLACEHOLDER10"',
			)
		);

		$out  = $this->read( array( 'available' => true, 'lines' => 14, 'log' => $log ), 'wp_get_error_log' );
		$text = $out['log'];

		self::assertStringNotContainsString( 'PLACEHOLDER', $text );
		self::assertStringNotContainsString( 'UExBQ0VIT0xERVI', $text );
		self::assertStringContainsString( 'Authorization: Bearer [REDACTED]', $text );
		self::assertStringContainsString( 'Proxy-Authorization: Basic [REDACTED]', $text );
		self::assertStringContainsString( 'sent bearer [REDACTED] to the API', $text );
		self::assertStringContainsString( '?page=2&api_key=[REDACTED]', $text );
		self::assertStringContainsString( 'code=1&access_token=[REDACTED]', $text );
		self::assertStringContainsString( 'user=anna password=[REDACTED]', $text );
		self::assertStringContainsString( '{"Token":[REDACTED]', $text );
		self::assertStringContainsString( '[user] => anna', $text );
		self::assertStringContainsString( '[smtp_pass] => [REDACTED]', $text );
		self::assertStringContainsString( "'client_secret' => [REDACTED]", $text );
		self::assertStringContainsString( 'Authorization: Digest [REDACTED]', $text );
		self::assertSame( 14, count( explode( "\n", $text ) ), 'Line by line: no line is lost.' );
		self::assertSame( array( '/log' ), $out['redacted'] );
		self::assertSame( 14, $out['lines'] );
	}

	public function testDumpsOfHeadersPrivatePropertiesAndLongValuesLoseThemWhole(): void {
		// Codex 2 #11, #12.
		$log = "[30-Oct-2026] " . var_export( array( 'Authorization' => 'Basic PLACEHOLDER-B', 'Digest' => 'x' ), true )
			. "\n[30-Oct-2026] " . print_r( array( 'password' => "PLACEHOLDER_A\nPLACEHOLDER_B", 'x' => 'kept' ), true )
			. "\n[30-Oct-2026] Foo Object\n(\n    [password:Foo:private] => PLACEHOLDER-P\n    [token:protected] => PLACEHOLDER-T\n    [label] => shown\n)\n";

		$text = $this->read( array( 'log' => $log ), 'wp_get_error_log' )['log'];

		self::assertStringNotContainsString( 'PLACEHOLDER', $text );
		self::assertStringContainsString( "'Authorization' => [REDACTED]", $text );
		self::assertStringContainsString( '[x] => kept', $text );
		self::assertStringContainsString( '[label] => shown', $text );
		self::assertStringContainsString( '[password:Foo:private] => [REDACTED]', $text );
		self::assertStringContainsString( '[token:protected] => [REDACTED]', $text );
	}

	public function testDumpedArraysUnderACredentialsNameAreRedactedToTheirEnd(): void {
		// Codex #7: print_r and var_export of array( 'token' => array( … ) ).
		$value = array( 'token' => array( 'PLACEHOLDER-PR', array( 'deep' => 'PLACEHOLDER-DEEP' ) ), 'after' => 'visible' );
		$log   = '[30-Oct-2026] ' . print_r( $value, true ) . "\n[30-Oct-2026] " . var_export( $value, true ) . "\n[30-Oct-2026] next line";

		$text = $this->read( array( 'log' => $log ), 'wp_get_error_log' )['log'];

		self::assertStringNotContainsString( 'PLACEHOLDER', $text );
		self::assertSame( 2, substr_count( $text, 'visible' ), 'What follows the block stays.' );
		self::assertStringContainsString( '[token] => [REDACTED]', $text );
		self::assertStringContainsString( "'token' => [REDACTED]", $text );
		self::assertStringContainsString( 'next line', $text );
	}

	public function testAPrivateKeyInTheLogIsRedactedWhole(): void {
		// Codex 3 #5.
		$pem = "-----BEGIN PRIVATE KEY-----\nPLACEHOLDERAAAA\nPLACEHOLDERBBBB\n-----END PRIVATE KEY-----";
		$log = "[30-Oct-2026] private_key=" . $pem . "\n[30-Oct-2026] next\n[30-Oct-2026] key " . str_replace( 'PRIVATE KEY', 'RSA PRIVATE KEY', $pem ) . "\n-----BEGIN CERTIFICATE-----\nPUBLIC\n-----END CERTIFICATE-----\n-----BEGIN EC PRIVATE KEY-----\nPLACEHOLDERCUT";

		$text = $this->read( array( 'log' => $log ), 'wp_get_error_log' )['log'];

		self::assertStringNotContainsString( 'PLACEHOLDER', $text );
		self::assertStringContainsString( "[30-Oct-2026] next\n[30-Oct-2026] key [REDACTED]\n-----BEGIN CERTIFICATE-----\nPUBLIC", $text, 'A certificate is public and stays.' );
	}

	public function testAPrivateKeyCutAtItsStartIsRedactedToItsEnd(): void {
		// Codex 4 #5: the last lines of the log hold its END line, not its BEGIN.
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			// As tests/menus-shop-stubs.php defines it.
			define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/ab-mcp-test-content-' . getmypid() );
		}
		$log  = WP_CONTENT_DIR . '/debug.log';
		$keep = is_file( $log ) ? file_get_contents( $log ) : null;
		$made = ! is_dir( WP_CONTENT_DIR ) && mkdir( WP_CONTENT_DIR, 0700, true );
		file_put_contents( $log, "[30-Oct-2026] key -----BEGIN PRIVATE KEY-----\nPLACEHOLDERAAAA\nPLACEHOLDERBBBB\n-----END PRIVATE KEY-----\n" );
		try {
			$two = AB_MCP_Tools_System::error_log( array( 'lines' => 2 ) );
			file_put_contents( $log, "-----BEGIN RSA PRIVATE KEY-----\nPLACEHOLDERCCCC\nPLACEHOLDERDDDD\n-----END RSA PRIVATE KEY-----\n[30-Oct-2026] next\n" );
			$three = AB_MCP_Tools_System::error_log( array( 'lines' => 4 ) );
		} finally {
			null === $keep ? unlink( $log ) : file_put_contents( $log, $keep );
			if ( $made ) {
				rmdir( WP_CONTENT_DIR );
			}
		}
		self::assertSame( "PLACEHOLDERBBBB\n-----END PRIVATE KEY-----", $two['log'], 'The tool cuts before the redactor reads.' );

		$out = $this->read( $two, 'wp_get_error_log' );
		self::assertSame( self::R, $out['log'] );
		self::assertSame( array( '/log' ), $out['redacted'] );
		self::assertSame( self::R . "\n[30-Oct-2026] next", $this->read( $three, 'wp_get_error_log' )['log'] );
		$public = "PUBLIC\n-----END CERTIFICATE-----\n[30-Oct-2026] next";
		self::assertSame( $public, $this->read( array( 'log' => $public ), 'wp_get_error_log' )['log'], 'The end of a certificate stays.' );
	}

	public function testEveryBearerTokenOfALineIsRedacted(): void {
		// Codex 4 #6.
		$out = $this->read( array( 'log' => "old Bearer PLACEHOLDER1 new Bearer PLACEHOLDER2 end\nsent bearer PLACEHOLDER3" ), 'wp_get_error_log' );

		self::assertSame( "old Bearer [REDACTED] new Bearer [REDACTED] end\nsent bearer [REDACTED]", $out['log'] );
	}

	public function testAPrettyPrintedValueUnderACredentialsNameIsRedactedToItsEnd(): void {
		// Codex 6 #2: JSON_PRETTY_PRINT puts the value on the next lines.
		$flags   = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
		$headers = json_encode( array( 'Authorization' => array( 'Basic PLACEHOLDERB64==' ), 'Accept' => array( 'text/html' ) ), $flags );
		$body    = json_encode( array( 'auth' => array( 'user' => 'anna', 'nested' => array( 'k' => 'PLACEHOLDER-N' ) ), 'ok' => true ), $flags );
		$log     = "[30-Oct-2026 10:00:00 UTC] headers " . $headers . "\n[30-Oct-2026 10:00:01 UTC] body " . $body . "\n[30-Oct-2026 10:00:02 UTC] next";

		$out  = $this->read( array( 'log' => $log ), 'wp_get_error_log' );
		$text = $out['log'];

		self::assertStringNotContainsString( 'PLACEHOLDER', $text );
		self::assertStringContainsString( '"Authorization": [REDACTED]', $text );
		self::assertStringContainsString( '"Accept": [', $text );
		self::assertStringContainsString( '"text/html"', $text );
		self::assertStringContainsString( '"auth": [REDACTED]', $text );
		self::assertStringContainsString( '"ok": true', $text );
		self::assertStringContainsString( "\n[30-Oct-2026 10:00:02 UTC] next", $text );
		self::assertSame( array( '/log' ), $out['redacted'] );

		// Without its end in the log, to the end of the entry: fail-closed.
		$cut  = "[30-Oct-2026] token " . substr( json_encode( array( 'token' => array( 'a' => 'PLACEHOLDER-C1', 'b' => 'PLACEHOLDER-C2' ) ), $flags ), 0, -8 ) . "\n[30-Oct-2026] after";
		// Beyond BLOCK_LINES, to the end of the entry though its end follows.
		$long = "[30-Oct-2026] secret " . json_encode( array( 'secret' => range( 1, 400 ) ), $flags ) . "\nLINE-AFTER-THE-VALUE\n[30-Oct-2026] after";
		// A bracket inside a string does not close the value.
		$quoted = "[30-Oct-2026] headers " . json_encode( array( 'Authorization' => array( 'x]', 'Basic PLACEHOLDER-Q' ) ), $flags ) . "\n[30-Oct-2026] after";
		foreach ( array( $cut, $long, substr( $cut, 0, strrpos( $cut, "\n" ) ), $quoted ) as $i => $case ) {
			$text = $this->read( array( 'log' => $case ), 'wp_get_error_log' )['log'];
			self::assertStringNotContainsString( 'PLACEHOLDER', $text, (string) $i );
			self::assertStringNotContainsString( '399', $text, (string) $i );
			self::assertStringNotContainsString( 'LINE-AFTER', $text, (string) $i );
			self::assertSame( 2 === $i ? 0 : 1, substr_count( $text, 'after' ), (string) $i );
		}
	}

	public function testPairsInJsonEncodedInALineAreFoundAtEveryLevelOfEscaping(): void {
		// Codex 7: error_log( 'Request: ' . wp_json_encode( array( 'body' => wp_json_encode( … ) ) ) ).
		$one   = '[31-Oct-2026 10:00:00 UTC] Request: ' . wp_json_encode( array( 'body' => wp_json_encode( array( 'client_secret' => 'PLACEHOLDER-1', 'scope' => 'read' ) ) ) );
		$two   = '[31-Oct-2026 10:00:01 UTC] Request: ' . wp_json_encode( array( 'body' => wp_json_encode( array( 'inner' => wp_json_encode( array( 'api_key' => 'PLACEHOLDER-2', 'n' => 1 ) ) ) ) ) );
		$deep  = '[31-Oct-2026 10:00:02 UTC] Request: ' . wp_json_encode( array( 'body' => wp_json_encode( array( 'token' => wp_json_encode( array( 'k' => 'PLACEHOLDER-3' ) ), 'after' => 'shown' ) ) ) );
		$num   = '[31-Oct-2026 10:00:03 UTC] Request: ' . wp_json_encode( array( 'body' => wp_json_encode( array( 'otp' => 987654, 'ok' => true ) ) ) );
		$plain = '[31-Oct-2026 10:00:04 UTC] Request: ' . wp_json_encode( array( 'body' => wp_json_encode( array( 'color' => 'red', 'password_hint' => 'x', 'token' => '', 'secret' => null ) ) ) );

		$out   = $this->read( array( 'log' => implode( "\n", array( $one, $two, $deep, $num, $plain ) ) ), 'wp_get_error_log' );
		$lines = explode( "\n", $out['log'] );

		self::assertStringNotContainsString( 'PLACEHOLDER', $out['log'] );
		self::assertStringNotContainsString( '987654', $out['log'] );
		self::assertSame( str_replace( 'PLACEHOLDER-1', self::R, $one ), $lines[0], 'The value to its closing quote, the rest of the line stays.' );
		self::assertSame( str_replace( 'PLACEHOLDER-2', self::R, $two ), $lines[1] );
		self::assertStringContainsString( '\\"token\\":\\"[REDACTED]\\",\\"after\\":\\"shown\\"', $lines[2], 'Quotes of a deeper level do not end the value.' );
		self::assertStringEndsWith( '\\"otp\\":[REDACTED]', $lines[3], 'A value without quotes: to the end of the line.' );
		self::assertSame( $plain, $lines[4], 'Ordinary pairs, an empty value and null stay.' );
		self::assertSame( array( '/log' ), $out['redacted'] );

		// An encoded value without quotes before a value in brackets that a
		// credential's name opens: the lines of that value go too.
		$both = '[31-Oct-2026] ' . wp_json_encode( array( 'b' => wp_json_encode( array( 'otp' => 1 ) ) ) ) . ' "token": [' . "\n" . '"PLACEHOLDER-4"' . "\n" . ']' . "\n" . '[31-Oct-2026] next';
		self::assertSame( '[31-Oct-2026] {"b":"{\\"otp\\":[REDACTED]' . "\n" . '[31-Oct-2026] next', $this->read( array( 'log' => $both ), 'wp_get_error_log' )['log'] );
	}

	public function testEveryRuleReadsEveryLineInEitherOrder(): void {
		// Codex 5 #5: a Bearer token before a pair, and after it.
		$out = $this->read( array( 'log' => "Retry Bearer PLACEHOLDERTK1 api_key=PLACEHOLDER2\napi_key=PLACEHOLDER3 Bearer PLACEHOLDERTK4\nAuthorization: Bearer PLACEHOLDERTK5" ), 'wp_get_error_log' );

		self::assertSame( "Retry Bearer [REDACTED] api_key=[REDACTED]\napi_key=[REDACTED]\nAuthorization: Bearer [REDACTED]", $out['log'] );
	}

	public function testALongValueIsRedactedWithoutThePatternGivingUp(): void {
		// Codex #3: a value of 10'000 characters.
		$long = str_repeat( 'x', 10000 );
		$log  = 'password="' . $long . '" and token=' . $long . "\ntoken=" . $long . "\nAuthorization: Bearer " . $long . "\nsent Bearer " . $long . ' ok';

		$out = $this->read( array( 'log' => $log ), 'wp_get_error_log' );

		self::assertSame( "password=[REDACTED]\ntoken=[REDACTED]\nAuthorization: Bearer [REDACTED]\nsent Bearer [REDACTED] ok", $out['log'] );
		self::assertArrayNotHasKey( 'redacted_unchecked', $out );
	}

	public function testAPatternThatGivesUpLeavesNothingVisible(): void {
		// Codex #3: preg_* answering null must not hand on the original.
		$limit = ini_get( 'pcre.backtrack_limit' );
		$jit   = ini_get( 'pcre.jit' );
		ini_set( 'pcre.jit', '0' );
		ini_set( 'pcre.backtrack_limit', '1' );
		try {
			$out  = $this->read( array( 'log' => 'login failed: password=PLACEHOLDER for "user": "x"' ), 'wp_get_error_log' );
			$text = $this->read( array( 'log' => 'login failed: password=PLACEHOLDER' ), 'wp_get_error_log' );
		} finally {
			ini_set( 'pcre.backtrack_limit', (string) $limit );
			ini_set( 'pcre.jit', (string) $jit );
		}

		self::assertSame( self::R, $out['log'], 'Where the JSON pattern gives up.' );
		self::assertSame( array( '/log' ), $out['redacted_unchecked'] );
		self::assertSame( self::R, $text['log'], 'Where a free-text pattern gives up.' );
		self::assertSame( array( '/log' ), $text['redacted_unchecked'] );
	}

	public function testOrdinaryLogLinesStay(): void {
		$log = implode(
			"\n",
			array(
				'PHP Warning:  Undefined array key "password" in /var/www/wp-content/plugins/x/x.php on line 12',
				'PHP Notice: token_count=5, author=anna, password_hint: none',
				'Missing Bearer token in request',
				'password=',
				'token: null',
				'secret=true',
				'https://example.org/?p=12&preview=true',
				'Authorization: Bearer',
			)
		);

		$out = $this->read( array( 'available' => true, 'lines' => 8, 'log' => $log ), 'wp_get_error_log' );

		self::assertSame( $log, $out['log'] );
		self::assertArrayNotHasKey( 'redacted', $out );
	}

	public function testWhatPeopleWroteStaysByteForByte(): void {
		$post = array(
			'id'           => 12,
			'title'        => '{"token":"title-example"}',
			'content'      => '{"password":"x"}',
			'excerpt'      => 'a:1:{s:6:"secret";s:1:"y";}',
			'post_content' => '<!-- wp:acme/form {"apiKey":"block-attribute"} /-->' . "\n" . 'Set "api_key": "YOUR-KEY" in config.json.',
			'rendered'     => array( 'raw' => '{"secret":"r"}', 'rendered' => '<p>{"secret":"r"}</p>' ),
			'meta'         => array( 'config' => '{"password":"x"}' ),
		);

		$out = $this->read( $post, 'wp_get_post' );

		foreach ( array( 'title', 'content', 'excerpt', 'post_content', 'rendered' ) as $field ) {
			self::assertSame( $post[ $field ], $out[ $field ], $field );
		}
		self::assertSame( '{"password":"[REDACTED]"}', $out['meta']['config'], 'The same JSON in a meta value is redacted.' );
		self::assertSame( array( '/meta/config/password' ), $out['redacted'] );
		$comment = array( 'id' => 3, 'comment_content' => 'my config: {"client_secret":"abc"}' );
		self::assertSame( $comment, $this->read( $comment, 'wp_get_comment' ) );
		// A key below a content field is still judged by its name.
		self::assertSame( self::R, $this->read( array( 'content' => array( 'api_key' => 'PLACEHOLDER' ) ), 'wp_get_post' )['content']['api_key'] );
	}

	public function testABuilderLayoutKeepsItsTextsAndLosesItsKeys(): void {
		// Codex 3 #6.
		$layout = array(
			'builder'  => 'elementor',
			'elements' => array(
				array( 'id' => 'a1', 'fields' => array( 'editor' => array( 'value' => '{"password":"example"}' ) ) ),
				array( 'id' => 'b2', 'settings' => array( 'api_key' => 'PLACEHOLDER', 'list_id' => '42' ) ),
			),
		);

		$out = $this->read( $layout, 'wp_get_builder_layout' );

		self::assertSame( '{"password":"example"}', $out['elements'][0]['fields']['editor']['value'] );
		self::assertSame( array( 'api_key' => self::R, 'list_id' => '42' ), $out['elements'][1]['settings'] );
		self::assertSame( array( '/elements/1/settings/api_key' ), $out['redacted'] );
	}

	public function testTheMetaReaderRedactsTheSameJson(): void {
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		ab_test_add_post( 6 );
		add_post_meta( 6, 'settings_json', '{"password":"x"}' );

		$out = AB_MCP_Redactor::tool_result( 'wp_get_post_meta', array(), AB_MCP_Tools_Content::get_post_meta_tool( array( 'id' => 6 ) ) );

		self::assertSame( '{"password":"[REDACTED]"}', $out['meta']['settings_json'] );
	}

	public function testAnExportIsHandedOutAsItIsStored(): void {
		$export = array( 'format' => 'blueprint', 'settings' => '{"api_key":"x"}' );

		// Read-only where a definition says so; the free core's registry does not.
		self::assertSame( $export, AB_MCP_Redactor::tool_result( 'wp_export_content', array( 'readonly' => true ), $export ) );
		self::assertNotSame( $export, AB_MCP_Redactor::tool_result( 'wp_export_other', array( 'readonly' => true ), $export ) );
	}

	public function testFreeTextIsSearchedInTheErrorLogOnly(): void {
		$content = 'Our tutorial: call https://api.example.org/?api_key=YOUR-KEY with Authorization: Bearer YOUR-TOKEN-HERE';

		self::assertSame( array( 'content' => $content ), $this->read( array( 'content' => $content ), 'wp_get_post' ) );
	}

	public function testAFileIsHandedOutAsItIsStored(): void {
		// Under a field name of its own, so the content-field rule does not cover it.
		$file = array( 'path' => 'composer.json', 'data' => '{"config":{"github-oauth":{"github.com":"PLACEHOLDER"}}}' );

		self::assertSame( $file, $this->read( $file, 'wp_read_plugin_file' ) );
		self::assertStringNotContainsString( 'PLACEHOLDER', $this->read( $file )['data'], 'The same answer of another reader is searched.' );
	}

	/* ------------------------------------------------------------ answers */

	public function testAWritingToolsAnswerIsAsItWas(): void {
		$created = array( 'id' => 9, 'username' => 'anna', 'generated_password' => 'PLACEHOLDER-ONCE', 'token' => 'PLACEHOLDER' );

		self::assertSame( $created, AB_MCP_Redactor::tool_result( 'wp_create_user', array(), $created ) );
		self::assertSame( $created, AB_MCP_Redactor::tool_result( 'wp_get_something', array( 'readonly' => false ), $created ), 'The definition decides, not the name.' );
		self::assertSame( self::R, AB_MCP_Redactor::tool_result( 'wp_make_report', array( 'readonly' => true ), $created )['token'] );
	}

	public function testOfWpRunAbilityOnlyTheOtherPluginsResultIsSearched(): void {
		$answer = array(
			'dry_run'    => false,
			'applied'    => true,
			'result'     => array( 'connected' => true, 'api_key' => 'PLACEHOLDER-AB', 'nested' => array( 'webhook_secret' => 'PLACEHOLDER-WH' ) ),
			'undo_token' => 'undo_123',
			'readback'   => array( array( 'post' => 4, 'secret' => 'not the plugin\'s' ) ),
		);

		$out = AB_MCP_Redactor::tool_result( 'wp_run_ability', array(), $answer );

		self::assertSame( array( 'connected' => true, 'api_key' => self::R, 'nested' => array( 'webhook_secret' => self::R ) ), $out['result'] );
		self::assertSame( 'undo_123', $out['undo_token'] );
		self::assertSame( $answer['readback'], $out['readback'] );
		self::assertSame( array( '/result/api_key', '/result/nested/webhook_secret' ), $out['redacted'] );
	}

	public function testTheAnswerNamesPathsAndNeverValues(): void {
		$out = $this->read( array( 'meta' => array( 'a' => array( 'token' => 'PLACEHOLDER-VALUE-1' ), 'secret' => 'PLACEHOLDER-VALUE-2' ) ) );

		self::assertSame( array( '/meta/a/token', '/meta/secret' ), $out['redacted'] );
		self::assertStringNotContainsString( 'PLACEHOLDER', (string) wp_json_encode( $out['redacted'] ) );
	}

	public function testAKeyThatCarriesAValueLosesItInTheAnswerAndInThePath(): void {
		// Codex #11.
		$out = $this->read( array( 'meta' => array( 'api_key=PLACEHOLDER-K' => 'PLACEHOLDER-V', 'note: hello' => 'x' ) ) );

		self::assertStringNotContainsString( 'PLACEHOLDER', (string) wp_json_encode( $out ) );
		self::assertArrayHasKey( 'api_key=' . self::R, $out['meta'] );
		self::assertSame( 'x', $out['meta']['note: hello'] );
		self::assertSame( array( '/meta/api_key=…' ), array_values( array_unique( $out['redacted'] ) ) );
	}

	public function testAKeyThatCarriesAValueUnderAnExtraNameLosesItInThePathToo(): void {
		// Codex 4 #4: the filter's names cut the path as they cut the key.
		add_filter( 'ab_mcp_redact_keys', static fn() => array( 'connection' ) );

		$out = $this->read( array( 'meta' => array( 'connection=PLACEHOLDER-C' => 'on', 'color' => 'red' ) ) );

		self::assertStringNotContainsString( 'PLACEHOLDER', (string) wp_json_encode( $out ) );
		self::assertSame( array( 'connection=' . self::R => 'on', 'color' => 'red' ), $out['meta'] );
		self::assertSame( array( '/meta/connection=…' ), $out['redacted'] );
	}

	public function testPathsAreUnambiguousAndNoFindIsMerged(): void {
		// Codex #12: a.b/token and a/b/token are two places.
		$out = $this->read(
			array(
				'meta' => array(
					'a.b' => array( 'token' => 'PLACEHOLDER-1' ),
					'a'   => array( 'b' => array( 'token' => 'PLACEHOLDER-2' ) ),
					'c/d' => array( 'token' => 'PLACEHOLDER-3' ),
					'e~f' => array( 'token' => 'PLACEHOLDER-4' ),
				),
			)
		);

		self::assertSame( array( '/meta/a.b/token', '/meta/a/b/token', '/meta/c~1d/token', '/meta/e~0f/token' ), $out['redacted'] );
		// Codex 2 #15: names with a colon stay whole, keys inside a JSON text are part of the path.
		$out = $this->read( array( 'meta' => array( 'cfg:a' => array( 'token' => 'one' ), 'cfg:b' => array( 'token' => 'two' ), 'config' => '{"a":{"token":"one"},"b":{"token":"two"}}' ) ) );
		self::assertSame( array( '/meta/cfg:a/token', '/meta/cfg:b/token', '/meta/config/a/token', '/meta/config/b/token' ), $out['redacted'] );
	}

	public function testManyPathsAreCappedAndCounted(): void {
		$meta = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$meta[ "k$i" ] = array( 'token' => "PLACEHOLDER-$i" );
		}

		$out = $this->read( array( 'meta' => $meta ) );

		self::assertCount( AB_MCP_Redactor::MAX_PATHS, $out['redacted'] );
		self::assertSame( 60, $out['redacted_count'] );
		self::assertStringNotContainsString( 'PLACEHOLDER', (string) wp_json_encode( $out ) );
	}

	public function testAListIsRedactedInPlaceWithoutAField(): void {
		$out = $this->read( array( array( 'name' => 'a', 'api_key' => 'PLACEHOLDER' ), array( 'name' => 'b' ) ) );

		self::assertSame( array( array( 'name' => 'a', 'api_key' => self::R ), array( 'name' => 'b' ) ), $out );
	}

	public function testTheFilterAddsKeysAndCannotTakeTheBuiltInOnesAway(): void {
		add_filter( 'ab_mcp_redact_keys', static fn( $keys ) => array_merge( (array) $keys, array( ' MyPluginLicense ', 42, '' ) ) );

		$out = $this->read( array( 'meta' => array( 'my_plugin_license' => 'PLACEHOLDER-F', 'myPluginLicense' => 'PLACEHOLDER-H', 'my_plugin_license_type' => 'pro', 'api_key' => 'PLACEHOLDER-G' ) ) );

		self::assertSame( array( 'my_plugin_license' => self::R, 'myPluginLicense' => self::R, 'my_plugin_license_type' => 'pro', 'api_key' => self::R ), $out['meta'] );
		self::assertSame( array( 'my_plugin_license' ), AB_MCP_Redactor::extra_keys() );
		$hits = 0;
		self::assertSame( 'my_plugin_license=' . self::R, AB_MCP_Redactor::text( 'my_plugin_license=PLACEHOLDER', $hits, AB_MCP_Redactor::extra_keys() ) );

		ab_test_reset();
		add_filter( 'ab_mcp_redact_keys', static fn() => 'not a list' );
		self::assertSame( self::R, $this->read( array( 'meta' => array( 'api_key' => 'PLACEHOLDER' ) ) )['meta']['api_key'] );
		self::assertSame( array(), AB_MCP_Redactor::extra_keys() );
	}

	public function testToolsCallAnswersWithTheRedactedResult(): void {
		( new ReflectionProperty( \AB_MCP_Auth::class, 'current_scope' ) )->setValue( null, 'full' );
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'content', 'Posts & Pages' );
		$r->register(
			'wp_get_settings_of_x',
			array(
				'description' => 'Return the settings of plugin X.',
				'callback'    => static fn(): array => array( 'settings' => array( 'endpoint' => 'https://x.example', 'api_key' => 'PLACEHOLDER-RPC' ) ),
			)
		);
		$request = new WP_REST_Request();
		$request->set_json_params(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array( 'name' => 'wp_get_settings_of_x', 'arguments' => array() ),
			)
		);

		$data = ( new AB_MCP_REST_Controller( $r ) )->handle( $request )->get_data();

		self::assertFalse( $data['result']['isError'] );
		self::assertSame( self::R, $data['result']['structuredContent']['settings']['api_key'] );
		self::assertSame( array( '/settings/api_key' ), $data['result']['structuredContent']['redacted'] );
		self::assertStringNotContainsString( 'PLACEHOLDER', $data['result']['content'][0]['text'] );
		self::assertStringContainsString( 'https://x.example', $data['result']['content'][0]['text'] );
	}

	/* ------------------------------------------------- writing it back */

	public function testRestorePlaceholdersPutsTheStoredValueBackAtTheSamePath(): void {
		$stored = array( 'host' => 'old.example', 'api_key' => 'STORED-1', 'list' => array( 'STORED-2', 'b' ), 'obj' => (object) array( 'token' => 'STORED-3' ) );
		$sent   = array( 'host' => 'new.example', 'api_key' => self::R, 'list' => array( self::R, 'c' ), 'obj' => (object) array( 'token' => self::R ) );

		$out = AB_MCP_Redactor::restore_placeholders( $sent, $stored, $left );

		self::assertSame( 'new.example', $out['host'] );
		self::assertSame( 'STORED-1', $out['api_key'] );
		self::assertSame( array( 'STORED-2', 'c' ), $out['list'] );
		self::assertSame( 'STORED-3', $out['obj']->token );
		self::assertSame( self::R, $sent['obj']->token, 'The object sent is not changed in place.' );
		self::assertFalse( $left );
		self::assertSame( 'STORED', AB_MCP_Redactor::restore_placeholders( self::R, 'STORED' ) );
	}

	public function testRestorePlaceholdersSaysWhereAPlaceholderIsLeft(): void {
		// Codex 2 #4, #16.
		foreach (
			array(
				array( array( 'new_secret' => self::R ), array() ),
				array( array( 'x' => self::R ), 'not an array' ),
				array( '{"api_key":"[REDACTED]"}', '{"api_key":"STORED"}' ),
				// Codex 4 #2: a key read as name=[REDACTED] without its stored one.
				array( array( 'token=[REDACTED]' => 'on' ), array( 'color' => 'red' ) ),
				array( array( 'note' => '{"inner":"{\\"api_key\\":\\"[REDACTED]\\"}"}' ), array( 'note' => 'old' ) ),
			) as $i => $case
		) {
			AB_MCP_Redactor::restore_placeholders( $case[0], $case[1], $left );
			self::assertTrue( $left, (string) $i );
		}
		$cycle = unserialize( 'a:2:{s:1:"a";R:1;s:1:"b";R:1;}' );
		$start = microtime( true );
		AB_MCP_Redactor::restore_placeholders( $cycle, $cycle, $left );
		self::assertLessThan( 5.0, microtime( true ) - $start );
		self::assertTrue( $left, 'Not looked through: the writer refuses.' );
	}

	/**
	 * A tools/call of a writing tool with these arguments.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array{0: array, 1: int}
	 */
	private function write( array $args, string $tool = 'wp_update_thing' ): array {
		( new ReflectionProperty( \AB_MCP_Auth::class, 'current_scope' ) )->setValue( null, 'full' );
		update_option( 'ab_mcp_options', array( 'site_mode' => 'full' ) );
		$calls = 0;
		$r     = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'content', 'Posts & Pages' );
		$r->register(
			$tool,
			array(
				'description' => 'Change a thing.',
				'callback'    => static function () use ( &$calls ): array {
					++$calls;
					return array( 'updated' => true );
				},
			)
		);
		$request = new WP_REST_Request();
		$request->set_json_params(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array( 'name' => $tool, 'arguments' => $args ),
			)
		);
		return array( ( new AB_MCP_REST_Controller( $r ) )->handle( $request )->get_data()['result'], $calls );
	}

	public function testAWritingToolRefusesThePlaceholderSentBack(): void {
		foreach (
			array(
				array( 'value' => array( 'api_key' => self::R, 'color' => 'red' ) ),
				array( 'value' => '{"api_key":"[REDACTED]"}' ),
				array( 'value' => 'a:1:{s:7:"api_key";s:10:"[REDACTED]";}' ),
				array( 'meta' => (object) array( 'deep' => array( self::R ) ) ),
				// Codex 3 #1: JSON in JSON, the placeholder escaped.
				array( 'value' => json_encode( array( 'config' => json_encode( array( 'password' => self::R ) ) ) ) ),
				// Three levels of JSON, and serialized data inside JSON.
				array( 'value' => json_encode( array( 'a' => json_encode( array( 'b' => json_encode( array( 'token' => self::R ) ) ) ) ) ) ),
				array( 'value' => json_encode( array( 'cfg' => serialize( array( 'secret' => self::R ) ) ) ) ),
				// Codex 4 #2: a key read as name=[REDACTED].
				array( 'value' => array( 'password=' . self::R => 'on' ) ),
				// Codex 3 #2: an array under a content field is no text people wrote.
				array( 'value' => array( 'content' => array( 'password' => self::R ), 'color' => 'blue' ) ),
			) as $i => $args
		) {
			[ $result, $calls ] = $this->write( $args );
			self::assertTrue( $result['isError'], (string) $i );
			self::assertSame( 0, $calls, 'Nothing ran.' );
			self::assertStringContainsString( 'Send the real value, or leave this field out', $result['content'][0]['text'] );
		}
		[ $result ] = $this->write( array( 'value' => array( 'api_key' => self::R ) ) );
		self::assertStringContainsString( '/value/api_key', $result['content'][0]['text'] );
	}

	public function testTextPeopleWroteAndOrdinaryValuesPassTheWriteGuard(): void {
		foreach (
			array(
				array( 'content' => 'Our values read as [REDACTED] in the answer: {"password":"[REDACTED]"}' ),
				array( 'title' => self::R ),
				array( 'value' => array( 'content' => 'a quote: {"password":"[REDACTED]"}' ) ),
				array( 'value' => array( 'color' => 'red' ) ),
				// Codex 4 #8: a text that mentions the placeholder, as on main.
				array( 'value' => 'a note that mentions [REDACTED] in passing' ),
				array( 'label' => 'Name: [REDACTED]', 'list' => array( 'Bearer [REDACTED]', 'password=[REDACTED] in a sentence' ) ),
				array( 'value' => array( 'Name:[REDACTED]' => 'a key no answer masks so' ) ),
			) as $i => $args
		) {
			[ $result, $calls ] = $this->write( $args );
			self::assertFalse( $result['isError'], (string) $i );
			self::assertSame( 1, $calls );
		}
		self::assertNull( AB_MCP_Redactor::placeholder_in( array( 'post_content' => self::R, 'x' => array( 'rendered' => self::R ) ) ) );
		self::assertSame( '/content/0', AB_MCP_Redactor::placeholder_in( array( 'content' => array( self::R ) ) ) );
		// Codex 3 #7: search and replace takes patterns, not values read back.
		[ $result, $calls ] = $this->write( array( 'search' => self::R, 'replace' => 'anonymised' ), 'wp_search_replace' );
		self::assertFalse( $result['isError'] );
		self::assertSame( 1, $calls );
		// A reading tool reads: what it is asked with is no write.
		[ $result, $calls ] = $this->write( array( 'search' => self::R ), 'wp_get_thing' );
		self::assertFalse( $result['isError'] );
		self::assertSame( 1, $calls );
		self::assertSame( '/a/0', AB_MCP_Redactor::placeholder_in( array( 'a' => array( self::R ) ) ) );
	}

	public function testTheTextOfATextWidgetIsWhatPeopleWrote(): void {
		// Codex 5 #4: the text widget keeps its content in settings.text.
		$widgets = array( 'widgets' => array( array( 'id' => 'text-4', 'settings' => array( 'title' => 'Help', 'text' => '{"password":"tutorial example"}' ) ) ) );

		self::assertSame( $widgets, $this->read( $widgets, 'wp_get_widgets' ), 'Read as stored, nothing redacted.' );
		[ $result, $calls ] = $this->write( array( 'id' => 'text-4', 'settings' => array( 'text' => self::R, 'content' => self::R ) ), 'wp_update_widget' );
		self::assertFalse( $result['isError'] );
		self::assertSame( 1, $calls );
	}

	public function testTheFieldsOfABuilderElementAreWhatPeopleWrote(): void {
		// Codex 6 #3: wp_get_builder_layout hands its texts out as stored.
		foreach (
			array(
				array( 'id' => 5, 'element_id' => 'e1', 'fields' => array( 'editor' => self::R, 'title' => 'Name: [REDACTED]', 'html' => '{"password":"[REDACTED]"}' ) ),
				array( 'id' => 5, 'element_id' => 'e1', 'fields' => array( 'items' => array( 'label' => '{"token":"[REDACTED]"}' ) ) ),
				array( 'id' => 5, 'element_id' => 'e1', 'fields' => '{"editor":"[REDACTED]"}' ),
			) as $i => $args
		) {
			[ $result, $calls ] = $this->write( $args, 'wp_update_builder_element' );
			self::assertFalse( $result['isError'], (string) $i );
			self::assertSame( 1, $calls );
		}
		// A field the reader redacts, a list below a field (as below a content
		// field), a key read as name=[REDACTED], another argument, another
		// tool: guarded as before.
		foreach (
			array(
				array( 'wp_update_builder_element', array( 'id' => 5, 'fields' => array( 'password' => self::R ) ) ),
				array( 'wp_update_builder_element', array( 'id' => 5, 'fields' => array( 'items' => array( self::R ) ) ) ),
				array( 'wp_update_builder_element', array( 'id' => 5, 'fields' => array( 'items' => array( 'token=' . self::R => 'x' ) ) ) ),
				array( 'wp_update_builder_element', array( 'id' => 5, 'element_id' => self::R, 'fields' => array() ) ),
				array( 'wp_update_thing', array( 'id' => 5, 'fields' => array( 'editor' => self::R ) ) ),
			) as $i => [ $tool, $args ]
		) {
			[ $result, $calls ] = $this->write( $args, $tool );
			self::assertTrue( $result['isError'], (string) $i );
			self::assertSame( 0, $calls );
		}
	}

	public function testCaptionsAltTextsAndDescriptionsPassTheWriteGuard(): void {
		// Codex 4 #9: what people write about a file or a term, as on main.
		foreach (
			array(
				array( 'id' => 5, 'caption' => 'Name: [REDACTED]' ),
				array( 'id' => 5, 'caption' => self::R, 'alt' => self::R ),
				array( 'id' => 5, 'description' => self::R ),
			) as $i => $args
		) {
			[ $result, $calls ] = $this->write( $args, 'wp_update_media' );
			self::assertFalse( $result['isError'], (string) $i );
			self::assertSame( 1, $calls );
		}
		[ $result, $calls ] = $this->write( array( 'id' => 5, 'label' => self::R ), 'wp_update_media' );
		self::assertTrue( $result['isError'], 'Another field is guarded as before.' );
		self::assertSame( 0, $calls );
	}
}

/**
 * A class whose code runs when PHP restores an object of it: counts it.
 */
final class RedactionWakeupProbe {

	/** @var int How often an object was restored. */
	public static int $woken = 0;

	/** @var string A field under a credential's name. */
	public string $secret = '';

	public function __wakeup(): void {
		++self::$woken;
	}
}

/**
 * Credentials in a private and a protected property.
 */
final class RedactionPrivateProbe {

	/** @var string Private. */
	private string $password = 'PLACEHOLDER-PRIVATE';

	/** @var string Protected. */
	protected string $token = 'PLACEHOLDER-PROTECTED';

	/** @var string Public. */
	public string $label = 'visible';
}
