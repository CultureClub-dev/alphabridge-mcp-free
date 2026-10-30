<?php
/**
 * The connection check (AB_MCP_Connection_Doctor), the tool
 * wp_connection_doctor and the card «Connection check».
 *
 * What must hold:
 *
 * - Every check's judgement is a pure function of the answer (status,
 *   headers, start of the body) and the site's settings; the table below
 *   covers each case: this plugin's own 401 (with and without the pointer
 *   to the sign-in), no answer (never «blocked»), 404, ModSecurity's 406,
 *   Cloudflare, LiteSpeed, Wordfence, an HTTP login, a plugin that restricts
 *   the REST API, a redirect, a server error, anything else.
 * - Every result that is not ok names at least one step; an ok names none.
 * - The endpoint says on a 401 whether a token reached PHP (X-AB-MCP-Auth:
 *   missing or rejected), and nothing more; the check tells a server that
 *   drops the Authorization header from a wrong token by it.
 * - The requests go to the site itself, without a token for the endpoint,
 *   with a made-up one for the header check, without redirects or cookies.
 * - The tool is read-only, not destructive, runs with write access off and
 *   for a read-only connection, needs manage_options and has a short name.
 * - The card runs nothing on load; its button runs the check behind the
 *   nonce and the capability, keeps the result (autoload off) and lands on
 *   the card; the card shows each check with its status as a word, the
 *   finding and the steps, escaped.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Admin;
use AB_MCP_Connection_Doctor;
use AB_MCP_OAuth;
use AB_MCP_REST_Controller;
use AB_MCP_Settings;
use AB_MCP_Site_Mode;
use AB_MCP_Tool_Labels;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_System;
use AbTestExit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_REST_Request;

require_once __DIR__ . '/../includes/tools/class-tools-system.php';

final class ConnectionDoctorTest extends TestCase {

	private const ENDPOINT   = 'https://example.test/wp-json/alphabridge/v1/mcp';
	private const WELL_KNOWN = 'https://example.test/.well-known/oauth-protected-resource/wp-json/alphabridge/v1/mcp';
	private const POINTER    = 'Bearer resource_metadata="https://example.test/.well-known/oauth-protected-resource/wp-json/alphabridge/v1/mcp"';

	protected function setUp(): void {
		ab_test_reset();
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' === $cap;
	}

	protected function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
	}

	/**
	 * An answer in the shape the checks take.
	 *
	 * @param array<string,string> $headers
	 */
	private static function answer( ?int $status, array $headers = array(), string $body = '', string $error = '' ): array {
		return array(
			'status'  => $status,
			'headers' => array_change_key_case( $headers, CASE_LOWER ),
			'body'    => $body,
			'error'   => $error,
		);
	}

	private static function assertStepsFit( array $check ): void {
		if ( 'ok' === $check['status'] ) {
			self::assertSame( array(), $check['steps'], $check['id'] . ': an ok names no steps.' );
			return;
		}
		if ( in_array( $check['status'], array( 'warn', 'fail' ), true ) ) {
			self::assertNotEmpty( $check['steps'], $check['id'] . ': a problem names the way.' );
		}
		foreach ( $check['steps'] as $step ) {
			self::assertIsString( $step );
			self::assertNotSame( '', trim( $step ) );
		}
	}

	private static function assertShape( array $check, string $id ): void {
		self::assertSame( array( 'id', 'status', 'title', 'finding', 'steps' ), array_keys( $check ) );
		self::assertSame( $id, $check['id'] );
		self::assertContains( $check['status'], AB_MCP_Connection_Doctor::STATUSES );
		self::assertNotSame( '', $check['title'] );
		self::assertNotSame( '', $check['finding'] );
		self::assertStepsFit( $check );
	}

	/* ------------------------------------------------- 1, 2: HTTPS, permalinks */

	public function testHttpsIsRequiredForTheSiteAndTheEndpoint(): void {
		$ok = AB_MCP_Connection_Doctor::interpret_https( 'https://example.test/', self::ENDPOINT );
		self::assertShape( $ok, 'https' );
		self::assertSame( 'ok', $ok['status'] );

		$home = AB_MCP_Connection_Doctor::interpret_https( 'http://example.test/', self::ENDPOINT );
		self::assertShape( $home, 'https' );
		self::assertSame( 'fail', $home['status'] );
		self::assertStringContainsString( 'http://example.test/', $home['finding'] );
		self::assertStringContainsString( 'connect only to https:// addresses', $home['finding'] );
		self::assertStringContainsString( 'certificate', $home['steps'][0] );
		self::assertStringContainsString( '“WordPress Address (URL)” and “Site Address (URL)” under Settings → General', $home['steps'][1] );

		$endpoint = AB_MCP_Connection_Doctor::interpret_https( 'https://example.test/', 'http://example.test/wp-json/alphabridge/v1/mcp' );
		self::assertSame( 'fail', $endpoint['status'] );
	}

	public function testPlainPermalinksWarnWithTheSetting(): void {
		$ok = AB_MCP_Connection_Doctor::interpret_permalinks( false );
		self::assertShape( $ok, 'permalinks' );
		self::assertSame( 'ok', $ok['status'] );

		$plain = AB_MCP_Connection_Doctor::interpret_permalinks( true );
		self::assertShape( $plain, 'permalinks' );
		self::assertSame( 'warn', $plain['status'] );
		self::assertStringContainsString( '/.well-known/', $plain['finding'] );
		self::assertSame( 'Under Settings → Permalinks choose “Post name” and click “Save Changes”.', $plain['steps'][0] );
	}

	/* ----------------------------------------------------- 3: the endpoint */

	/**
	 * Status, headers, body, sign-in on, expected status, a phrase of the
	 * finding, a phrase of the steps.
	 *
	 * @return array<string,array{0:int|null,1:array,2:string,3:bool,4:string,5:string,6:string}>
	 */
	public static function endpointAnswers(): array {
		$own = array(
			'X-AB-MCP-Auth'    => 'missing',
			'WWW-Authenticate' => self::POINTER,
		);
		return array(
			'own 401 with the pointer'            => array( 401, $own, '{"code":"ab_mcp_unauthorized"}', true, 'ok', 'with 401 and the pointer to the sign-in', '' ),
			'own 401, sign-in off'                => array( 401, array( 'X-AB-MCP-Auth' => 'missing' ), '{"code":"ab_mcp_unauthorized"}', false, 'ok', 'with 401, as it should', '' ),
			'own 401 by its code alone'           => array( 401, array( 'WWW-Authenticate' => self::POINTER ), '{"code":"ab_mcp_unauthorized"}', true, 'ok', 'as it should', '' ),
			'own 401 without the pointer'         => array( 401, array( 'X-AB-MCP-Auth' => 'missing' ), '{"code":"ab_mcp_unauthorized"}', true, 'warn', 'without the WWW-Authenticate header', 'connect with a token' ),
			'no answer'                           => array( null, array(), '', true, 'skip', 'could not reach itself', 'Tools → Site Health' ),
			'404 page'                            => array( 404, array( 'Content-Type' => 'text/html' ), '<html>Not found</html>', true, 'fail', 'answers 404, so the WordPress REST API does not answer', 'Settings → Permalinks' ),
			'404 of the REST API'                 => array( 404, array(), '{"code":"rest_no_route"}', true, 'fail', 'answers 404', 'restricts the REST API to logged-in users' ),
			'406 of ModSecurity'                  => array( 406, array( 'Server' => 'Apache' ), '<html>Not Acceptable</html>', true, 'fail', 'ModSecurity rule', 'POST requests with a JSON body to ' . self::ENDPOINT ),
			'403 of Cloudflare'                   => array( 403, array( 'Server' => 'cloudflare', 'CF-RAY' => '8a1b2c3d4e5f6789-ZRH' ), '<html>Attention Required! | Cloudflare</html>', true, 'fail', 'Cloudflare answered in place of the MCP endpoint (status 403)', 'security events' ),
			'Cloudflare challenge'                => array( 403, array( 'cf-mitigated' => 'challenge', 'Server' => 'cloudflare' ), '<html>Just a moment...</html>', true, 'fail', 'Cloudflare answered', 'skips Cloudflare’s security features' ),
			'403 of LiteSpeed'                    => array( 403, array( 'Server' => 'LiteSpeed', 'X-LiteSpeed-Cache-Control' => 'no-cache' ), '<html>403 Forbidden</html>', true, 'fail', 'LiteSpeed web server or its CDN QUIC.cloud', 'ModSecurity on LiteSpeed' ),
			'406 of LiteSpeed'                    => array( 406, array( 'X-LiteSpeed-Tag' => 'x' ), '', true, 'fail', 'LiteSpeed', 'QUIC.cloud' ),
			'403 of Wordfence'                    => array( 403, array(), '<html>Your access to this site has been limited by the site owner. Generated by Wordfence</html>', true, 'fail', 'Wordfence’s firewall answered', 'Wordfence → Firewall' ),
			'503 of Wordfence'                    => array( 503, array(), '<html>Generated by Wordfence</html>', true, 'fail', 'Wordfence', 'allowlist' ),
			'403 of something in front'           => array( 403, array( 'Server' => 'nginx' ), '<html>403 Forbidden</html>', true, 'fail', 'Something in front of WordPress answered 403', 'log of blocked requests' ),
			'401 of an HTTP login'                => array( 401, array( 'WWW-Authenticate' => 'Basic realm="Staging"' ), '', true, 'fail', 'HTTP login', 'password protection' ),
			'401 of a REST restriction'           => array( 401, array(), '{"code":"rest_login_required","message":"x"}', true, 'fail', 'WordPress refused the request before it reached the MCP endpoint (status 401, rest_login_required)', 'restricts the REST API to logged-in users' ),
			'403 of WordPress behind Cloudflare'  => array( 403, array( 'Server' => 'cloudflare' ), '{"code":"rest_forbidden"}', true, 'fail', 'WordPress refused the request', '/alphabridge/v1/' ),
			'redirect'                            => array( 301, array( 'Location' => 'https://www.example.test/wp-json/alphabridge/v1/mcp' ), '', true, 'fail', 'redirect (301) to https://www.example.test/wp-json/alphabridge/v1/mcp', 'Settings → General' ),
			'server error'                        => array( 500, array(), '<html>Critical error</html>', true, 'fail', 'server error 500', 'wp_get_error_log' ),
			'server error behind Cloudflare'      => array( 502, array( 'Server' => 'cloudflare' ), '<html>Bad gateway</html>', true, 'fail', 'server error 502', 'error log' ),
			'a cached page'                       => array( 200, array( 'Content-Type' => 'text/html' ), '<html>Home</html>', true, 'fail', 'status 200 instead of 401', 'exclude the addresses' ),
			'too many requests'                   => array( 429, array(), '', true, 'fail', 'status 429 instead of 401', 'with status 429' ),
		);
	}

	#[DataProvider( 'endpointAnswers' )]
	public function testTheEndpointAnswerIsReadAsTheTableSays( ?int $status, array $headers, string $body, bool $oauth, string $expected, string $finding, string $step ): void {
		$check = AB_MCP_Connection_Doctor::interpret_endpoint( self::answer( $status, $headers, $body, null === $status ? 'cURL error 28: Operation timed out' : '' ), $oauth );
		self::assertShape( $check, 'mcp_endpoint' );
		self::assertSame( $expected, $check['status'], $check['finding'] );
		self::assertStringContainsString( $finding, $check['finding'] );
		if ( '' !== $step ) {
			self::assertStringContainsString( $step, implode( ' ', $check['steps'] ) );
		}
	}

	public function testNoAnswerIsNeverCalledABlock(): void {
		$check = AB_MCP_Connection_Doctor::interpret_endpoint( self::answer( null, array(), '', 'cURL error 7: Failed to connect' ), true );
		self::assertSame( 'skip', $check['status'] );
		self::assertStringContainsString( 'cURL error 7: Failed to connect', $check['finding'] );
		self::assertStringContainsString( 'says nothing about whether apps can reach it', $check['finding'] );
		self::assertStringNotContainsStringIgnoringCase( 'block', $check['finding'] );
	}

	/* ------------------------------------------- 4: the Authorization header */

	public function testTheHeaderArrivesWhenTheTokenIsRejected(): void {
		$check = AB_MCP_Connection_Doctor::interpret_authorization( self::answer( 401, array( 'X-AB-MCP-Auth' => 'rejected' ) ), true, false );
		self::assertShape( $check, 'authorization_header' );
		self::assertSame( 'ok', $check['status'] );
	}

	public function testAMissingHeaderNamesTheServerFixAndTheTokenAddress(): void {
		$check = AB_MCP_Connection_Doctor::interpret_authorization( self::answer( 401, array( 'X-AB-MCP-Auth' => 'missing' ) ), true, false );
		self::assertShape( $check, 'authorization_header' );
		self::assertSame( 'fail', $check['status'] );
		self::assertStringContainsString( 'removes the Authorization header before WordPress sees it', $check['finding'] );
		self::assertStringContainsString( 'CGIPassAuth On', $check['steps'][0] );
		self::assertStringContainsString( 'above the line # BEGIN WordPress', $check['steps'][0] );
		self::assertStringContainsString( 'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]', $check['steps'][0] );
		self::assertStringContainsString( 'nginx', $check['steps'][1] );
		self::assertStringContainsString( '“Also offer an address that contains the token.”', $check['steps'][2] );
		// The hub sends the token in the same header: it is no way round.
		self::assertStringNotContainsString( 'hub', implode( ' ', $check['steps'] ) );
	}

	/**
	 * @return array<string,array{0:array|null,1:bool,2:bool,3:string}>
	 */
	public static function unclearHeaderAnswers(): array {
		return array(
			'endpoint not reached'     => array( null, false, false, 'needs the plugin’s own answer' ),
			'behind an HTTP login'     => array( null, true, true, 'HTTP login' ),
			'no answer'                => array( self::answer( null, array(), '', 'timed out' ), true, false, 'could not reach itself' ),
			'an answer without a word' => array( self::answer( 403 ), true, false, 'status 403' ),
		);
	}

	#[DataProvider( 'unclearHeaderAnswers' )]
	public function testWithoutAClearAnswerTheHeaderCheckSaysNothing( ?array $answer, bool $reached, bool $basic, string $finding ): void {
		$check = AB_MCP_Connection_Doctor::interpret_authorization( $answer, $reached, $basic );
		self::assertShape( $check, 'authorization_header' );
		self::assertSame( 'skip', $check['status'] );
		self::assertStringContainsString( $finding, $check['finding'] );
	}

	/* ----------------------------------------------------- 5: .well-known */

	/**
	 * @return array<string,array{0:array|null,1:bool,2:bool,3:string,4:string,5:string}>
	 */
	public static function wellKnownAnswers(): array {
		$doc = '{"resource":"https://example.test/wp-json/alphabridge/v1/mcp","authorization_servers":["https://example.test"]}';
		return array(
			'sign-in off'              => array( null, false, false, 'info', 'sign-in for apps is off', 'How Claude signs in' ),
			'the description'          => array( self::answer( 200, array( 'Content-Type' => 'application/json' ), $doc ), true, false, 'ok', 'answers as it should', '' ),
			'someone else’s page'      => array( self::answer( 200, array(), '<html>Home</html>' ), true, false, 'fail', 'not with this plugin’s sign-in description', 'OAuth or MCP' ),
			'404'                      => array( self::answer( 404 ), true, false, 'fail', 'answers 404: the server answers the addresses below /.well-known/ itself', '/.well-known/oauth- to WordPress’s index.php' ),
			'404 with plain permalinks' => array( self::answer( 404 ), true, true, 'fail', 'answers 404', 'Post name' ),
			'403'                      => array( self::answer( 403 ), true, false, 'fail', 'Something in front of the site answered 403', 'log of blocked requests' ),
			'redirect'                 => array( self::answer( 302, array( 'Location' => 'https://www.example.test/.well-known/x' ) ), true, false, 'warn', 'redirect (302)', 'Settings → General' ),
			'server error'             => array( self::answer( 500 ), true, false, 'fail', 'status 500', 'with status 500' ),
			'no answer'                => array( self::answer( null, array(), '', 'timed out' ), true, false, 'skip', 'could not reach itself', 'Site Health' ),
		);
	}

	#[DataProvider( 'wellKnownAnswers' )]
	public function testTheWellKnownAnswerIsReadAsTheTableSays( ?array $answer, bool $oauth, bool $plain, string $expected, string $finding, string $step ): void {
		$check = AB_MCP_Connection_Doctor::interpret_well_known( $answer, $oauth, $plain );
		self::assertShape( $check, 'well_known' );
		self::assertSame( $expected, $check['status'], $check['finding'] );
		self::assertStringContainsString( $finding, $check['finding'] );
		if ( '' !== $step ) {
			self::assertStringContainsString( $step, implode( ' ', $check['steps'] ) );
		}
	}

	public function testPlainPermalinksComeFirstOnA404(): void {
		$plain = AB_MCP_Connection_Doctor::interpret_well_known( self::answer( 404 ), true, true );
		self::assertStringContainsString( 'Post name', $plain['steps'][0] );
		$pretty = AB_MCP_Connection_Doctor::interpret_well_known( self::answer( 404 ), true, false );
		self::assertStringStartsWith( 'Ask your host', $pretty['steps'][0] );
		// The hub reads the same addresses, so it is no way round a 404 here.
		self::assertStringContainsString( 'the AlphaBridge hub cannot find the sign-in', $pretty['finding'] );
		self::assertStringContainsString( 'connect with a token', $pretty['steps'][1] );
	}

	/* ------------------------------------------- 6, 7, 8: firewall, Cloudflare, plugins */

	public function testTheFirewallFindingInTheWordsOfTheSettingsPage(): void {
		$open = AB_MCP_Connection_Doctor::interpret_firewall( array( 'verdict' => 'open', 'status' => 302, 'checked' => 1 ), true );
		self::assertShape( $open, 'firewall' );
		self::assertSame( 'ok', $open['status'] );

		$blocked = AB_MCP_Connection_Doctor::interpret_firewall( array( 'verdict' => 'blocked', 'status' => 406, 'checked' => 1 ), true );
		self::assertShape( $blocked, 'firewall' );
		self::assertSame( 'warn', $blocked['status'] );
		self::assertSame( 'Your host’s firewall blocks the direct sign-in. ChatGPT and Codex register with the site instead.', $blocked['finding'] );
		self::assertSame( 'In the app, remove the connector for this site and add it again. The sign-in now goes through.', $blocked['steps'][0] );
		self::assertStringContainsString( AB_MCP_Admin::HUB_ADDRESS, $blocked['steps'][1] );

		$unknown = AB_MCP_Connection_Doctor::interpret_firewall( array( 'verdict' => 'unknown', 'status' => null, 'checked' => 1 ), true );
		self::assertSame( 'skip', $unknown['status'] );
		self::assertStringContainsString( 'no answer', $unknown['finding'] );

		self::assertSame( 'skip', AB_MCP_Connection_Doctor::interpret_firewall( null, false )['status'] );
		$off = AB_MCP_Connection_Doctor::interpret_firewall( null, true, false );
		self::assertSame( 'skip', $off['status'] );
		self::assertStringContainsString( 'switched off', $off['finding'] );
	}

	public function testCloudflareIsANoteNeverTheCause(): void {
		$seen = AB_MCP_Connection_Doctor::interpret_cloudflare( array( array( 'server' => 'nginx' ), array( 'cf-ray' => '8a1b2c3d4e5f6789-ZRH' ) ) );
		self::assertShape( $seen, 'cloudflare' );
		self::assertSame( 'info', $seen['status'] );
		self::assertStringContainsString( 'bot settings for AI crawlers are the usual cause', $seen['finding'] );
		self::assertStringContainsString( 'cannot see them', $seen['finding'] );
		self::assertStringNotContainsString( 'is the cause', $seen['finding'] );
		self::assertCount( 2, $seen['steps'] );
		self::assertStringContainsString( self::ENDPOINT, $seen['steps'][0] );

		$none = AB_MCP_Connection_Doctor::interpret_cloudflare( array( array( 'server' => 'nginx' ) ) );
		self::assertSame( 'skip', $none['status'] );
		self::assertStringContainsString( 'can still be behind it', $none['finding'] );
	}

	public function testPluginsThatRestrictTheRestApiAreNamedWithTheKindOfSetting(): void {
		$none = AB_MCP_Connection_Doctor::interpret_rest_plugins( array( 'akismet/akismet.php', 'hello.php' ) );
		self::assertShape( $none, 'rest_plugins' );
		self::assertSame( 'ok', $none['status'] );

		$found = AB_MCP_Connection_Doctor::interpret_rest_plugins( array( 'disable-json-api/disable-json-api.php', 'disable-wp-rest-api/disable-wp-rest-api.php', 'better-wp-security/better-wp-security.php', 'all-in-one-wp-security-and-firewall/wp-security.php' ) );
		self::assertShape( $found, 'rest_plugins' );
		self::assertSame( 'info', $found['status'] );
		self::assertStringContainsString( 'Disable REST API, Disable WP REST API, Solid Security, All-In-One Security (AIOS)', $found['finding'] );
		self::assertCount( 4, $found['steps'] );
		self::assertStringContainsString( 'the setting that restricts the REST API to logged-in users', $found['steps'][0] );
		self::assertStringContainsString( 'deactivate Disable WP REST API under Plugins', $found['steps'][1] );
	}

	/* ---------------------------------------------- the endpoint's header */

	/**
	 * One request to the MCP route through the emulated REST server.
	 *
	 * @param array<string,string> $headers
	 */
	private static function dispatch( array $headers ): \WP_REST_Response {
		$GLOBALS['ab_test_routes'] = array();
		( new AB_MCP_REST_Controller( new AB_MCP_Tool_Registry() ) )->register_routes();
		$request = new WP_REST_Request();
		$request->set_method( 'POST' );
		$request->set_route( '/alphabridge/v1/mcp' );
		$request->set_json_params(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'ping',
			)
		);
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		return ab_test_rest_dispatch( $request );
	}

	public function testA401SaysWhetherATokenArrived(): void {
		ab_test_add_user( 7 );
		$valid = AB_MCP_Settings::add_token( 7, 'Test', 'full', 0 );

		$missing = self::dispatch( array() );
		self::assertSame( 401, $missing->get_status() );
		self::assertSame( 'missing', $missing->get_headers()[ AB_MCP_REST_Controller::AUTH_HEADER ] );
		self::assertStringContainsString( 'resource_metadata=', $missing->get_headers()['WWW-Authenticate'] );

		$rejected = self::dispatch( array( 'Authorization' => 'Bearer abmcp-check-0123456789abcdef' ) );
		self::assertSame( 401, $rejected->get_status() );
		self::assertSame( 'rejected', $rejected->get_headers()[ AB_MCP_REST_Controller::AUTH_HEADER ] );

		$api_key = self::dispatch( array( 'X-Api-Key' => 'abmcp_wrong_but_long_enough' ) );
		self::assertSame( 'rejected', $api_key->get_headers()[ AB_MCP_REST_Controller::AUTH_HEADER ] );

		$ok = self::dispatch( array( 'Authorization' => 'Bearer ' . $valid ) );
		self::assertSame( 200, $ok->get_status() );
		self::assertArrayNotHasKey( AB_MCP_REST_Controller::AUTH_HEADER, $ok->get_headers() );
	}

	/* ------------------------------------------------------- the whole run */

	/**
	 * The site answers its own requests: the MCP endpoint through the
	 * emulated REST server (with the Authorization header dropped on the way
	 * when $strip), the sign-in description and the firewall probe as this
	 * plugin answers them.
	 */
	private static function site( bool $strip ): void {
		$GLOBALS['ab_test_routes'] = array();
		( new AB_MCP_REST_Controller( new AB_MCP_Tool_Registry() ) )->register_routes();
		$GLOBALS['ab_test_http_answer'] = static function ( string $url, array $args ) use ( $strip ): array {
			if ( self::ENDPOINT === $url ) {
				$request = new WP_REST_Request();
				$request->set_method( 'POST' );
				$request->set_route( '/alphabridge/v1/mcp' );
				$request->set_json_params( (array) json_decode( (string) $args['body'], true ) );
				foreach ( (array) $args['headers'] as $name => $value ) {
					if ( ! ( $strip && 'authorization' === strtolower( (string) $name ) ) ) {
						$request->set_header( (string) $name, (string) $value );
					}
				}
				$response = ab_test_rest_dispatch( $request );
				return ab_test_http_response( $response->get_status(), (string) wp_json_encode( $response->get_data() ), $response->get_headers() + array( 'CF-RAY' => '8a1b2c3d4e5f6789-ZRH' ) );
			}
			if ( self::WELL_KNOWN === $url ) {
				return ab_test_http_response( 200, (string) wp_json_encode( array( 'resource' => self::ENDPOINT ) ), array( 'Content-Type' => 'application/json' ) );
			}
			if ( false !== strpos( $url, 'ab_mcp_oauth=authorize' ) ) {
				return ab_test_http_response( 302, '', array( AB_MCP_OAuth::MARK_HEADER => AB_MCP_OAuth::MARK_AUTHORIZE ) );
			}
			return ab_test_http_response( 404, '' );
		};
	}

	/** @return array<string,array> */
	private static function byId( array $result ): array {
		return array_column( $result['checks'], null, 'id' );
	}

	public function testAHealthySitePassesEveryCheck(): void {
		update_option( 'permalink_structure', '/%postname%/' );
		self::site( false );

		$result = AB_MCP_Connection_Doctor::run();

		self::assertSame( array( 'https', 'permalinks', 'mcp_endpoint', 'authorization_header', 'well_known', 'firewall', 'cloudflare', 'rest_plugins' ), array_column( $result['checks'], 'id' ) );
		foreach ( $result['checks'] as $check ) {
			self::assertShape( $check, $check['id'] );
		}
		$by = self::byId( $result );
		foreach ( array( 'https', 'permalinks', 'mcp_endpoint', 'authorization_header', 'well_known', 'firewall', 'rest_plugins' ) as $id ) {
			self::assertSame( 'ok', $by[ $id ]['status'], $id . ': ' . $by[ $id ]['finding'] );
		}
		self::assertSame( 'info', $by['cloudflare']['status'] );
		self::assertSame( 7, $result['summary']['ok'] );
		self::assertSame( 1, $result['summary']['info'] );
		self::assertSame( 0, $result['summary']['fail'] );
		self::assertSame( 'Nothing that these checks can see stops apps from connecting.', $result['summary']['text'] );
		self::assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $result['checked_at'] );
		self::assertStringContainsString( 'from this site to itself', $result['limits'] );
		self::assertStringContainsString( 'Cloudflare’s bot rules for AI crawlers', $result['limits'] );
	}

	public function testTheRequestsGoToTheSiteItselfLikeTheFirewallProbe(): void {
		self::site( false );
		AB_MCP_Connection_Doctor::run();

		$calls = $GLOBALS['ab_test_http'];
		self::assertSame( array( self::ENDPOINT, self::ENDPOINT, self::WELL_KNOWN ), array_slice( array_column( $calls, 'url' ), 0, 3 ) );
		self::assertStringContainsString( 'ab_mcp_oauth=authorize', $calls[3]['url'] );
		foreach ( array_slice( $calls, 0, 3 ) as $call ) {
			self::assertSame( 0, $call['args']['redirection'] );
			self::assertSame( array(), $call['args']['cookies'] );
			self::assertSame( AB_MCP_OAuth::FIREWALL_USER_AGENT, $call['args']['user-agent'] );
			self::assertSame( AB_MCP_Connection_Doctor::TIMEOUT, $call['args']['timeout'] );
		}
		// The endpoint without a token, then with a made-up one.
		self::assertSame( 'POST', $calls[0]['args']['method'] );
		self::assertArrayNotHasKey( 'Authorization', $calls[0]['args']['headers'] );
		self::assertSame( 'initialize', json_decode( $calls[0]['args']['body'], true )['method'] );
		self::assertMatchesRegularExpression( '/^Bearer abmcp-check-[0-9a-f]{24}$/', $calls[1]['args']['headers']['Authorization'] );
		self::assertArrayNotHasKey( 'method', $calls[2]['args'], 'The description is fetched with GET.' );
	}

	public function testAServerThatDropsTheHeaderIsFound(): void {
		update_option( 'permalink_structure', '/%postname%/' );
		self::site( true );

		$by = self::byId( AB_MCP_Connection_Doctor::run() );

		self::assertSame( 'ok', $by['mcp_endpoint']['status'] );
		self::assertSame( 'fail', $by['authorization_header']['status'] );
	}

	public function testWithoutTheEndpointTheHeaderCheckIsSkipped(): void {
		$GLOBALS['ab_test_http_answer'] = static fn(): array => ab_test_http_response( 406, '<html>Not Acceptable</html>' );

		$result = AB_MCP_Connection_Doctor::run();
		$by     = self::byId( $result );

		self::assertSame( 'fail', $by['mcp_endpoint']['status'] );
		self::assertSame( 'skip', $by['authorization_header']['status'] );
		self::assertCount( 3, $GLOBALS['ab_test_http'], 'No request with a token: the endpoint, the description, the firewall probe.' );
		self::assertSame( 'warn', $by['firewall']['status'], 'The firewall probe saw 406 too.' );
		self::assertGreaterThanOrEqual( 2, $result['summary']['fail'] );
		self::assertStringContainsString( 'found problems', $result['summary']['text'] );
	}

	public function testBehindAnHttpLoginTheTokenIsNotSent(): void {
		$_SERVER['PHP_AUTH_USER'] = 'staging';
		$_SERVER['PHP_AUTH_PW']   = 'secret';
		self::site( false );

		$by = self::byId( AB_MCP_Connection_Doctor::run() );

		self::assertSame( 'skip', $by['authorization_header']['status'] );
		self::assertSame( 'Basic ' . base64_encode( 'staging:secret' ), $GLOBALS['ab_test_http'][0]['args']['headers']['Authorization'] );
		foreach ( $GLOBALS['ab_test_http'] as $call ) {
			self::assertStringNotContainsString( 'abmcp-check-', (string) ( $call['args']['headers']['Authorization'] ?? '' ) );
		}
	}

	public function testWithTheSignInOffThereIsNothingToFetchBelowWellKnown(): void {
		AB_MCP_Settings::set( 'oauth_enabled', false );
		self::site( false );

		$by = self::byId( AB_MCP_Connection_Doctor::run() );

		self::assertSame( 'ok', $by['mcp_endpoint']['status'] );
		self::assertSame( 'info', $by['well_known']['status'] );
		self::assertSame( 'skip', $by['firewall']['status'] );
		self::assertSame( array( self::ENDPOINT, self::ENDPOINT ), array_column( $GLOBALS['ab_test_http'], 'url' ) );
	}

	/* ------------------------------------------------------------- the tool */

	public function testTheToolIsAReadOnlySystemTool(): void {
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'system', 'System & Maintenance' );
		AB_MCP_Tools_System::register( $r );
		$def = $r->get( 'wp_connection_doctor' );

		self::assertIsArray( $def );
		self::assertSame( 'system', $def['group'] );
		self::assertSame( 'manage_options', $def['capability'] );
		self::assertFalse( (bool) $def['dangerous'] );
		self::assertSame( array(), (array) $def['inputSchema']['properties'], 'Takes no arguments.' );
		self::assertSame(
			array(
				'title'           => 'Check the connection',
				'readOnlyHint'    => true,
				'destructiveHint' => false,
				'idempotentHint'  => true,
				'openWorldHint'   => false,
			),
			AB_MCP_Tool_Registry::annotations( 'wp_connection_doctor', $def, AB_MCP_Tool_Labels::get( 'wp_connection_doctor' ) )
		);
		self::assertTrue( AB_MCP_Site_Mode::runs_in_read( 'wp_connection_doctor', $def ), 'Runs with write access off.' );
		self::assertTrue( AB_MCP_Tool_Registry::scope_allows( 'read', 'wp_connection_doctor', $def ), 'Runs for a read-only connection.' );
		self::assertStringContainsString( 'from the site to itself', $def['description'] );
		self::assertStringContainsString( 'Changes nothing on the site', $def['description'] );
	}

	public function testTheToolReturnsChecksSummaryTimeAndLimits(): void {
		self::site( false );
		$out = call_user_func( array( 'AB_MCP_Connection_Doctor', 'tool' ), array() );

		self::assertSame( array( 'checks', 'summary', 'checked_at', 'limits' ), array_keys( $out ) );
		self::assertCount( 8, $out['checks'] );
		self::assertArrayNotHasKey( AB_MCP_Connection_Doctor::OPTION, $GLOBALS['ab_test_options'], 'The tool keeps nothing.' );
	}

	/* ------------------------------------------------------------ the card */

	/** @param mixed ...$args */
	private function call( string $method, ...$args ): string {
		$m = new ReflectionMethod( AB_MCP_Admin::class, $method );
		ob_start();
		$out    = $m->invoke( new AB_MCP_Admin( new AB_MCP_Tool_Registry() ), ...$args );
		$echoed = (string) ob_get_clean();
		return $echoed . ( is_string( $out ) ? $out : '' );
	}

	private function post( array $post ): AbTestExit {
		$_POST    = $post;
		$_REQUEST = $post;
		try {
			( new AB_MCP_Admin( new AB_MCP_Tool_Registry() ) )->handle_connection_check();
		} catch ( AbTestExit $exit ) {
			return $exit;
		}
		self::fail( 'handle_connection_check ended without a redirect or a refusal.' );
	}

	public function testTheButtonRunsTheCheckKeepsItAndLandsOnTheCard(): void {
		self::site( false );

		$exit = $this->post( array( '_wpnonce' => 'nonce-ab_mcp_connection_check' ) );

		self::assertSame( 'redirect', $exit->kind );
		self::assertStringContainsString( 'ab_notice=connection_checked', $exit->detail );
		self::assertStringEndsWith( '#ab-doctor', $exit->detail );
		self::assertNotNull( AB_MCP_Connection_Doctor::stored() );
		self::assertFalse( $GLOBALS['ab_test_autoload'][ AB_MCP_Connection_Doctor::OPTION ], 'Kept without autoload.' );
	}

	/**
	 * @return array<string,array{0:array<string,string>,1:bool}>
	 */
	public static function refusedPosts(): array {
		return array(
			'no nonce'                => array( array(), true ),
			'the firewall’s nonce'    => array( array( '_wpnonce' => 'nonce-ab_mcp_firewall_check' ), true ),
			'no right'                => array( array( '_wpnonce' => 'nonce-ab_mcp_connection_check' ), false ),
		);
	}

	#[DataProvider( 'refusedPosts' )]
	public function testTheButtonNeedsTheNonceAndTheCapability( array $post, bool $admin ): void {
		if ( ! $admin ) {
			$GLOBALS['ab_test_can'] = static fn(): bool => false;
		}
		self::site( false );

		$exit = $this->post( $post );

		self::assertSame( 'die', $exit->kind );
		self::assertSame( array(), $GLOBALS['ab_test_http'], 'No request was sent.' );
		self::assertNull( AB_MCP_Connection_Doctor::stored() );
	}

	public function testTheCardRunsNothingOnLoadAndOffersTheButton(): void {
		self::site( false );
		$html = $this->call( 'doctor_card_html' );

		self::assertSame( array(), $GLOBALS['ab_test_http'] );
		self::assertStringContainsString( 'id="ab-doctor"', $html );
		self::assertStringContainsString( '>Connection check</h2>', $html );
		self::assertStringContainsString( 'Not run yet.', $html );
		self::assertStringContainsString( '<input type="hidden" name="_wpnonce" value="nonce-ab_mcp_connection_check">', $html );
		self::assertStringContainsString( '<input type="hidden" name="action" value="ab_mcp_connection_check">', $html );
		self::assertStringContainsString( '<button type="submit" class="ab-btn">Run check</button>', $html );
	}

	public function testTheCardShowsEachCheckWithItsStepsEscaped(): void {
		update_option(
			AB_MCP_Connection_Doctor::OPTION,
			array(
				'checks'     => array(
					array(
						'id'      => 'mcp_endpoint',
						'status'  => 'fail',
						'title'   => 'MCP endpoint',
						'finding' => 'Answered <script>alert(1)</script>.',
						'steps'   => array( 'First <b>this</b>.', 'Then “that”.' ),
					),
					array(
						'id'      => 'https',
						'status'  => 'ok',
						'title'   => 'HTTPS',
						'finding' => 'Fine.',
						'steps'   => array(),
					),
				),
				'summary'    => array( 'text' => 'One problem.' ),
				'checked_at' => '2026-10-30T10:00:00Z',
				'checked'    => time() - 120,
				'limits'     => AB_MCP_Connection_Doctor::limits(),
			)
		);

		$html = $this->call( 'doctor_card_html' );

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( 'Answered &lt;script&gt;alert(1)&lt;/script&gt;.', $html );
		self::assertStringContainsString( '<ol class="ab-direct__steps"><li>First &lt;b&gt;this&lt;/b&gt;.</li><li>Then “that”.</li></ol>', $html );
		self::assertStringContainsString( '<span class="ab-pill ab-pill--off">Problem</span> MCP endpoint', $html );
		self::assertStringContainsString( '<span class="ab-pill ab-pill--on">Passed</span> HTTPS', $html );
		self::assertSame( 1, substr_count( $html, '<ol class="ab-direct__steps">' ), 'An ok has no steps.' );
		self::assertStringContainsString( '<b>One problem.</b>', $html );
		self::assertStringContainsString( '120 seconds ago', $html );
		self::assertStringContainsString( 'from this site to itself', $html );
	}

	public function testTheCardFollowsTheConnectCardOnThePage(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-admin.php' );
		$connect = strpos( $src, 'echo $this->connect_card_html();' );
		$doctor  = strpos( $src, 'echo $this->doctor_card_html();' );
		self::assertNotFalse( $connect );
		self::assertNotFalse( $doctor );
		self::assertGreaterThan( $connect, $doctor );
	}

	public function testTheCheckedNoticeIsShown(): void {
		$_GET['ab_notice'] = 'connection_checked';
		$html              = $this->call( 'notice' );
		self::assertStringContainsString( 'Checked. The result is shown under “Connection check”.', $html );
	}
}
