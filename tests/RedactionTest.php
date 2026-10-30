<?php
/**
 * Reading tools answer with [REDACTED] where another plugin's data holds a
 * credential (AB_MCP_Redactor, applied in AB_MCP_REST_Controller::run_tool()).
 *
 * What must hold:
 *
 * - A value under a key the meta guard takes for a credential reads as
 *   [REDACTED] at any depth: in arrays, objects, serialized data and JSON
 *   inside a value; the key stays, and so does everything else.
 * - Letter case and surrounding blanks of a key do not matter; harmless
 *   keys (token_count, author, password_hint, password_protected) and
 *   values that only say whether one is set (true, false, null, '') stay.
 * - The error log's text loses the credentials it holds: Authorization
 *   headers, Bearer tokens, password=, token=, api_key= pairs, print_r and
 *   var_export lines; ordinary lines stay.
 * - A writing tool's answer is as it was (wp_create_user's one-time
 *   password); of wp_run_ability's answer only result, the other plugin's
 *   part, is searched.
 * - The answer names every place in redacted, as a path, never with the
 *   value; an answer with nothing redacted has no such field.
 * - The filter ab_mcp_redact_keys adds keys and cannot take the built-in
 *   ones away.
 *
 * The values below are made-up placeholders, not credentials.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Redactor;
use AB_MCP_REST_Controller;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Content;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use WP_REST_Request;

require_once dirname( __DIR__ ) . '/includes/tools/class-tools-base.php';
require_once dirname( __DIR__ ) . '/includes/tools/class-tools-content.php';

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
		self::assertSame( array( 'meta.settings.api_key', 'meta.settings.deep.0.client_secret' ), $out['redacted'] );
	}

	public function testAWholeArrayUnderACredentialKeyIsRedacted(): void {
		$out = $this->read( array( 'meta' => array( 'credentials' => array( 'user' => 'a', 'pass' => 'PLACEHOLDER' ) ) ) );

		self::assertSame( self::R, $out['meta']['credentials'] );
		self::assertSame( array( 'meta.credentials' ), $out['redacted'] );
	}

	public function testObjectsAreSearchedByTheirPublicProperties(): void {
		$inner               = new \stdClass();
		$inner->access_token = 'PLACEHOLDER-OBJ';
		$inner->expires      = 3600;
		$json                = new class() implements \JsonSerializable {
			public function jsonSerialize(): array {
				return array( 'refresh_token' => 'PLACEHOLDER-JS', 'scope' => 'read' );
			}
		};

		$out = $this->read( array( 'meta' => array( 'conn' => $inner, 'other' => $json ) ) );

		self::assertSame( self::R, $out['meta']['conn']->access_token );
		self::assertSame( 3600, $out['meta']['conn']->expires );
		self::assertSame( 'PLACEHOLDER-OBJ', $inner->access_token, 'The object the tool returned is not changed in place.' );
		self::assertSame( array( 'refresh_token' => self::R, 'scope' => 'read' ), $out['meta']['other'] );
		self::assertStringNotContainsString( 'PLACEHOLDER', (string) wp_json_encode( $out ) );
	}

	public function testSerializedDataInsideAValueIsSearchedAndKeptSerialized(): void {
		$stored = serialize( array( 'license_key' => 'PLACEHOLDER-SER', 'seats' => 3 ) );

		$out = $this->read( array( 'meta' => array( 'plugin_settings' => $stored ) ) );

		self::assertIsString( $out['meta']['plugin_settings'] );
		self::assertSame( array( 'license_key' => self::R, 'seats' => 3 ), unserialize( $out['meta']['plugin_settings'] ) );
		self::assertSame( array( 'meta.plugin_settings.license_key' ), $out['redacted'] );
	}

	public function testASerializedObjectIsReadWithoutRunningItsClass(): void {
		$probe          = new RedactionWakeupProbe();
		$probe->secret  = 'PLACEHOLDER';
		$stored         = serialize( $probe );
		RedactionWakeupProbe::$woken = 0;

		$out = $this->read( array( 'meta' => array( 'blob' => $stored, 'unknown' => 'O:19:"AB_Test_Not_A_Class":1:{s:6:"secret";s:11:"PLACEHOLDER";}' ) ) );

		self::assertStringNotContainsString( 'PLACEHOLDER', $out['meta']['blob'] );
		self::assertStringNotContainsString( 'PLACEHOLDER', $out['meta']['unknown'] );
		self::assertSame( 0, RedactionWakeupProbe::$woken, 'No code of the stored class ran.' );
		self::assertFalse( class_exists( 'AB_Test_Not_A_Class', false ) );
	}

	public function testJsonInsideAValueIsSearchedAndKeptJson(): void {
		$out = $this->read( array( 'meta' => array( 'config' => '{"smtp":{"host":"mail.example.org","smtp_pass":"PLACEHOLDER-JSON"}}' ) ) );

		self::assertSame( array( 'smtp' => array( 'host' => 'mail.example.org', 'smtp_pass' => self::R ) ), json_decode( $out['meta']['config'], true ) );
		self::assertSame( array( 'meta.config.smtp.smtp_pass' ), $out['redacted'] );
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
		self::assertSame( array( 'meta.shop_settings.gateway.Secret_Key', 'meta.legacy.apikey' ), $out['redacted'] );
		self::assertStringNotContainsString( 'PLACEHOLDER', (string) wp_json_encode( $out ) );
	}

	public function testLetterCaseAndBlanksOfAKeyDoNotMatter(): void {
		$out = $this->read( array( 'meta' => array( 'API_KEY' => 'x1', ' Secret ' => 'x2', 'Password' => 'x3', 'Stripe_Access_Token' => 'x4' ) ) );

		self::assertSame( array( 'API_KEY' => self::R, ' Secret ' => self::R, 'Password' => self::R, 'Stripe_Access_Token' => self::R ), $out['meta'] );
	}

	public function testHarmlessKeysStay(): void {
		$meta = array(
			'token_count'        => 12,
			'author'             => 'Anna',
			'co_author'          => 'Ben',
			'password_hint'      => 'the usual',
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
		$result = array( 'id' => 1, 'title' => 'Hello', 'meta' => array( 'color' => 'red' ) );

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
		self::assertSame( array( 'meta_data.0.value', 'rows.0.meta_value', 'rows.1.option_value' ), $out['redacted'] );
	}

	public function testSchemasAreNotSearched(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array( 'password' => array( 'type' => 'string' ), 'api_key' => array( 'type' => 'string' ) ),
		);

		$out = $this->read( array( 'abilities' => array( array( 'name' => 'x/login', 'input_schema' => $schema, 'output_schema' => $schema ) ) ), 'wp_list_abilities' );

		self::assertSame( $schema, $out['abilities'][0]['input_schema'] );
		self::assertSame( $schema, $out['abilities'][0]['output_schema'] );
		self::assertArrayNotHasKey( 'redacted', $out );
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
				'headers' => array( 'Authorization' => 'Basic PLACEHOLDER', 'Accept' => 'text/html' ),
				'auth'    => 'PLACEHOLDER',
				'rows'    => array( array( 'ID' => 1, 'user_login' => 'anna', 'user_pass' => '$P$PLACEHOLDER', 'user_activation_key' => 'PLACEHOLDER' ) ),
			)
		);

		self::assertSame( self::R, $out['headers']['Authorization'] );
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
		foreach ( array( 'token', 'Tokens', ' secret ', 'PASSWORD', 'pwd', 'otp', 'credentials', 'my_api_key', 'apikey', 'x-api-key', 'stripe_secret_key', 'oauth_state', 'smtp_password', 'smtp_pass', 'passwd_old', 'license_key' ) as $key ) {
			self::assertTrue( $guard->invoke( null, $key ), $key );
		}
		foreach ( array( '', ' ', 'token_count', 'password_hint', 'credential_type', 'tokens2', 'pwd2', 'otpx', 'color', 'author', 'password_protected', 'co_author' ) as $key ) {
			self::assertFalse( $guard->invoke( null, $key ), "'$key'" );
		}
		require_once dirname( __DIR__ ) . '/includes/tools/class-tools-search-replace.php';
		self::assertSame( AB_MCP_Redactor::CREDENTIAL_EXACT, \AB_MCP_Tools_Search_Replace::SENSITIVE_META_EXACT, 'The SQL mirror reads the same list.' );
		self::assertSame( AB_MCP_Redactor::CREDENTIAL_NEEDLES, \AB_MCP_Tools_Search_Replace::SENSITIVE_META_NEEDLES );
	}

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
				'    [credentials] => Array',
				')',
				"[30-Oct-2026 10:00:08 UTC] array ( 'client_secret' => 'PLACEHOLDER\\'8', 'ttl' => 60 )",
			)
		);

		$out  = $this->read( array( 'available' => true, 'lines' => 14, 'log' => $log ), 'wp_get_error_log' );
		$text = $out['log'];

		self::assertStringNotContainsString( 'PLACEHOLDER', $text );
		self::assertStringNotContainsString( 'UExBQ0VIT0xERVI', $text );
		self::assertStringContainsString( 'Authorization: Bearer [REDACTED]', $text );
		self::assertStringContainsString( 'Proxy-Authorization: Basic [REDACTED]', $text );
		self::assertStringContainsString( 'sent bearer [REDACTED] to the API', $text );
		self::assertStringContainsString( '?page=2&api_key=[REDACTED]&lang=de', $text );
		self::assertStringContainsString( 'code=1&access_token=[REDACTED]', $text );
		self::assertStringContainsString( 'user=anna password=[REDACTED]', $text );
		self::assertStringContainsString( '{"Token":"[REDACTED]","ok":true}', $text );
		self::assertStringContainsString( '[user] => anna', $text );
		self::assertStringContainsString( '[smtp_pass] => [REDACTED]', $text );
		self::assertStringContainsString( '[credentials] => Array', $text );
		self::assertStringContainsString( "'client_secret' => '[REDACTED]', 'ttl' => 60", $text );
		self::assertSame( array( 'log' ), $out['redacted'] );
		self::assertSame( 14, $out['lines'] );
	}

	public function testOrdinaryLogLinesStay(): void {
		$log = implode(
			"\n",
			array(
				'PHP Warning:  Undefined array key "password" in /var/www/wp-content/plugins/x/x.php on line 12',
				'PHP Notice: token_count=5, author=anna, password_hint: none',
				'Missing Bearer token in request',
				'password=, token: null, secret=true',
				'https://example.org/?p=12&preview=true',
			)
		);

		$out = $this->read( array( 'available' => true, 'lines' => 5, 'log' => $log ), 'wp_get_error_log' );

		self::assertSame( $log, $out['log'] );
		self::assertArrayNotHasKey( 'redacted', $out );
	}

	public function testTextIsSearchedInTheErrorLogOnly(): void {
		$content = 'Our tutorial: call https://api.example.org/?api_key=YOUR-KEY with Authorization: Bearer YOUR-TOKEN-HERE';

		self::assertSame( array( 'content' => $content ), $this->read( array( 'content' => $content ), 'wp_get_post' ) );
	}

	public function testAFileIsHandedOutAsItIsStored(): void {
		$file = array( 'path' => 'composer.json', 'content' => '{"config":{"github-oauth":{"github.com":"PLACEHOLDER"}}}' );

		self::assertSame( $file, $this->read( $file, 'wp_read_plugin_file' ) );
		self::assertStringNotContainsString( 'PLACEHOLDER', $this->read( $file, 'wp_get_post' )['content'], 'The same text in another reader is searched.' );
	}

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
		self::assertSame( array( 'result.api_key', 'result.nested.webhook_secret' ), $out['redacted'] );
	}

	public function testTheAnswerNamesPathsAndNeverValues(): void {
		$out = $this->read( array( 'meta' => array( 'a' => array( 'token' => 'PLACEHOLDER-VALUE-1' ), 'secret' => 'PLACEHOLDER-VALUE-2' ) ) );

		self::assertSame( array( 'meta.a.token', 'meta.secret' ), $out['redacted'] );
		self::assertStringNotContainsString( 'PLACEHOLDER', (string) wp_json_encode( $out['redacted'] ) );
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
		add_filter( 'ab_mcp_redact_keys', static fn( $keys ) => array_merge( (array) $keys, array( ' My_Plugin_License ', 42, '' ) ) );

		$out = $this->read( array( 'meta' => array( 'my_plugin_license' => 'PLACEHOLDER-F', 'my_plugin_license_type' => 'pro', 'api_key' => 'PLACEHOLDER-G' ) ) );

		self::assertSame( array( 'my_plugin_license' => self::R, 'my_plugin_license_type' => 'pro', 'api_key' => self::R ), $out['meta'] );
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
		self::assertSame( array( 'settings.api_key' ), $data['result']['structuredContent']['redacted'] );
		self::assertStringNotContainsString( 'PLACEHOLDER', $data['result']['content'][0]['text'] );
		self::assertStringContainsString( 'https://x.example', $data['result']['content'][0]['text'] );
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
