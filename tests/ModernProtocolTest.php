<?php
/**
 * MCP revision 2026-07-28 next to the legacy revisions ("dual-era").
 *
 * A request is modern exactly when params._meta names a protocol version.
 * Every method is exercised in both generations: the legacy answer must not
 * change, the modern one must carry what the revision requires. The required
 * fields below are copied from the official schema.json of 2026-07-28
 * ($defs.<Type>.required), so a field the schema requires cannot go missing
 * unnoticed.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use AB_MCP_REST_Controller;
use AB_MCP_Settings;
use AB_MCP_Tool_Registry;
use WP_REST_Request;
use WP_REST_Response;

final class ModernProtocolTest extends TestCase {

	private const MODERN = '2026-07-28';

	/**
	 * $defs.<Type>.required from schema.json 2026-07-28, per method.
	 */
	private const REQUIRED = array(
		'server/discover'          => array( 'cacheScope', 'capabilities', 'resultType', 'supportedVersions', 'ttlMs' ),
		'ping'                     => array( 'resultType' ),
		'tools/list'               => array( 'cacheScope', 'resultType', 'tools', 'ttlMs' ),
		'tools/call'               => array( 'content', 'resultType' ),
		'resources/list'           => array( 'cacheScope', 'resources', 'resultType', 'ttlMs' ),
		'resources/templates/list' => array( 'cacheScope', 'resourceTemplates', 'resultType', 'ttlMs' ),
		'prompts/list'             => array( 'cacheScope', 'prompts', 'resultType', 'ttlMs' ),
		'prompts/get'              => array( 'messages', 'resultType' ),
	);

	/** The fields only the modern revision adds to a result. */
	private const MODERN_FIELDS = array( 'resultType', 'ttlMs', 'cacheScope' );

	protected function setUp(): void {
		ab_test_reset();
		// The token scope is static per request in the plugin; earlier tests
		// may have left a narrower one behind.
		( new ReflectionProperty( \AB_MCP_Auth::class, 'current_scope' ) )->setValue( null, 'full' );

		add_filter(
			'ab_mcp_prompts',
			static fn(): array => array(
				array(
					'name'        => 'draft',
					'description' => 'Draft a post.',
					'arguments'   => array(
						array(
							'name'     => 'topic',
							'required' => true,
						),
					),
					'text'        => 'Write about {{topic}}.',
				),
			)
		);
	}

	private function registry(): AB_MCP_Tool_Registry {
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'content', 'Posts & Pages' );
		$r->register(
			'wp_get_answer',
			array(
				'description' => 'Return the answer.',
				'callback'    => static fn(): array => array( 'answer' => 42 ),
			)
		);
		$r->register(
			'wp_update_answer',
			array(
				'description' => 'Change the answer.',
				'callback'    => static fn(): string => 'changed',
			)
		);
		return $r;
	}

	private function controller(): AB_MCP_REST_Controller {
		return new AB_MCP_REST_Controller( $this->registry() );
	}

	/**
	 * @param mixed                $body    Decoded JSON body.
	 * @param array<string,string> $headers Request headers as a client writes them.
	 */
	private function post( $body, array $headers = array() ): WP_REST_Response {
		$request = new WP_REST_Request();
		$request->set_json_params( $body );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		return $this->controller()->handle( $request );
	}

	/**
	 * A request the way a 2026-07-28 client sends it: _meta in the body, the
	 * mirrored headers on the request.
	 *
	 * @param array<string,mixed>  $params  Params without _meta.
	 * @param array<string,string> $headers Headers to add or override; '' drops one.
	 */
	private function modern( string $method, array $params = array(), array $headers = array(), $id = 1 ): WP_REST_Response {
		$params['_meta'] = array(
			'io.modelcontextprotocol/protocolVersion'    => self::MODERN,
			'io.modelcontextprotocol/clientInfo'         => array(
				'name'    => 'TestClient',
				'version' => '1.0.0',
			),
			'io.modelcontextprotocol/clientCapabilities' => array(),
		);
		$sent = array(
			'MCP-Protocol-Version' => self::MODERN,
			'Mcp-Method'           => $method,
		);
		if ( isset( $params['name'] ) && is_string( $params['name'] ) && in_array( $method, array( 'tools/call', 'prompts/get' ), true ) ) {
			$sent['Mcp-Name'] = $params['name'];
		}
		if ( isset( $params['uri'] ) && is_string( $params['uri'] ) && 'resources/read' === $method ) {
			$sent['Mcp-Name'] = $params['uri'];
		}
		$sent = array_filter( array_merge( $sent, $headers ), static fn( string $v ): bool => '' !== $v );

		return $this->post(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'method'  => $method,
				'params'  => $params,
			),
			$sent
		);
	}

	/**
	 * @param array<string,mixed> $params Params.
	 */
	private function legacy( string $method, array $params = array() ): WP_REST_Response {
		$message = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => $method,
		);
		if ( array() !== $params ) {
			$message['params'] = $params;
		}
		return $this->post( $message );
	}

	/** @return array<string,mixed> */
	private function resultOf( WP_REST_Response $response ): array {
		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertIsArray( $data );
		self::assertArrayHasKey( 'result', $data, (string) wp_json_encode( $data ) );
		return (array) $data['result'];
	}

	/** @return array{code:int,message:string,data?:mixed} */
	private function errorOf( WP_REST_Response $response, int $status ): array {
		self::assertSame( $status, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		self::assertSame( '2.0', $data['jsonrpc'] ?? null, 'A JSON-RPC error body: that is what marks a modern server to a client that speaks both generations.' );
		self::assertArrayHasKey( 'error', $data );
		self::assertArrayNotHasKey( 'result', $data );
		return $data['error'];
	}

	/**
	 * Every method in both generations, with params that make it succeed.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>}>
	 */
	public static function methods(): array {
		return array(
			'ping'                     => array( 'ping', array() ),
			'tools/list'               => array( 'tools/list', array() ),
			'tools/call'               => array( 'tools/call', array( 'name' => 'wp_get_answer', 'arguments' => array() ) ),
			'tools/call isError'       => array( 'tools/call', array( 'name' => 'wp_no_such_tool', 'arguments' => array() ) ),
			'resources/list'           => array( 'resources/list', array() ),
			'resources/templates/list' => array( 'resources/templates/list', array() ),
			'prompts/list'             => array( 'prompts/list', array() ),
			'prompts/get'              => array( 'prompts/get', array( 'name' => 'draft', 'arguments' => array( 'topic' => 'bees' ) ) ),
		);
	}

	/* ------------------------------------------------------ legacy unchanged */

	/**
	 * @param array<string,mixed> $params Params.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'methods' )]
	public function testALegacyAnswerCarriesNoModernField( string $method, array $params ): void {
		$result = $this->resultOf( $this->legacy( $method, $params ) );

		foreach ( self::MODERN_FIELDS as $field ) {
			self::assertArrayNotHasKey( $field, $result, "$method: older clients get the answer they always got." );
		}
		self::assertArrayNotHasKey( '_meta', $result, "$method: no serverInfo for older clients." );
	}

	/**
	 * @param array<string,mixed> $params Params.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'methods' )]
	public function testTheSwitchDoesNotTouchALegacyAnswer( string $method, array $params ): void {
		$on = $this->legacy( $method, $params );
		AB_MCP_Settings::set( 'modern_protocol', false );
		$off = $this->legacy( $method, $params );

		self::assertSame( wp_json_encode( $off->get_data() ), wp_json_encode( $on->get_data() ), $method );
		self::assertSame( $off->get_status(), $on->get_status() );
		self::assertSame( $off->get_headers(), $on->get_headers() );
	}

	public function testInitializeStaysLegacyEvenWithModernMetadata(): void {
		$response = $this->post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => self::MODERN,
					'_meta'           => array( 'io.modelcontextprotocol/protocolVersion' => self::MODERN ),
				),
			)
		);
		$result = $this->resultOf( $response );

		self::assertSame( '2025-06-18', $result['protocolVersion'], 'initialize never answers with a modern revision.' );
		self::assertArrayNotHasKey( 'resultType', $result );
	}

	public function testABatchIsAlwaysLegacy(): void {
		$message = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/list',
			'params'  => array( '_meta' => array( 'io.modelcontextprotocol/protocolVersion' => self::MODERN ) ),
		);
		$response = $this->post( array( $message ) );

		self::assertSame( 200, $response->get_status() );
		self::assertArrayNotHasKey( 'resultType', $response->get_data()[0]['result'], '2026-07-28 has no batches; a batch is served as before.' );
	}

	public function testOlderAndNewerClientsAtTheSameTimeEachGetTheirAnswer(): void {
		// The field report behind this: a server whose modern branch dropped
		// resultType from tool results while legacy clients worked.
		$call = array(
			'name'      => 'wp_get_answer',
			'arguments' => array(),
		);
		$old1 = $this->resultOf( $this->legacy( 'tools/call', $call ) );
		$new1 = $this->resultOf( $this->modern( 'tools/call', $call ) );
		$old2 = $this->resultOf( $this->legacy( 'tools/call', $call ) );
		$new2 = $this->resultOf( $this->modern( 'tools/call', $call ) );

		self::assertArrayNotHasKey( 'resultType', $old1 );
		self::assertArrayNotHasKey( 'resultType', $old2 );
		self::assertSame( 'complete', $new1['resultType'] );
		self::assertSame( 'complete', $new2['resultType'] );
		self::assertSame( $old1['content'], $new1['content'], 'The tool answers the same; only the envelope differs.' );
	}

	/* ------------------------------------------------- modern, every method */

	/**
	 * @param array<string,mixed> $params Params.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'methods' )]
	public function testAModernAnswerHasEveryFieldTheSchemaRequires( string $method, array $params ): void {
		$result = $this->resultOf( $this->modern( $method, $params ) );

		foreach ( self::REQUIRED[ $method ] as $field ) {
			self::assertArrayHasKey( $field, $result, "$method: required by schema.json 2026-07-28." );
		}
		self::assertSame( 'complete', $result['resultType'], "$method: this server never asks for input mid-request." );
		self::assertSame(
			array(
				'name'    => 'AlphaBridge MCP',
				'version' => AB_MCP_VERSION,
			),
			$result['_meta']['io.modelcontextprotocol/serverInfo'] ?? null,
			"$method: the server names itself in every result."
		);
	}

	/**
	 * @param array<string,mixed> $params Params.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'methods' )]
	public function testAModernAnswerEncodesAsAJsonObject( string $method, array $params ): void {
		$json = (string) wp_json_encode( $this->modern( $method, $params )->get_data() );
		$back = json_decode( $json );

		self::assertIsObject( $back->result, "$method: a result is an object, ping's empty one included." );
		self::assertIsObject( $back->result->_meta );
	}

	public function testAToolErrorIsACompleteResultWithIsError(): void {
		$result = $this->resultOf(
			$this->modern(
				'tools/call',
				array(
					'name'      => 'wp_no_such_tool',
					'arguments' => array(),
				)
			)
		);

		self::assertTrue( $result['isError'] );
		self::assertSame( 'complete', $result['resultType'], 'isError results need resultType too.' );
		self::assertStringContainsString( 'Unknown tool', $result['content'][0]['text'] );
	}

	public function testAToolBlockedByReadOnlyModeIsACompleteResultWithIsError(): void {
		AB_MCP_Settings::set( 'read_only', true );
		$result = $this->resultOf(
			$this->modern(
				'tools/call',
				array(
					'name'      => 'wp_update_answer',
					'arguments' => array(),
				)
			)
		);

		self::assertTrue( $result['isError'] );
		self::assertSame( 'complete', $result['resultType'] );
	}

	/**
	 * @return array<string,array{0:string,1:int}>
	 */
	public static function cacheable(): array {
		return array(
			'server/discover'          => array( 'server/discover', 60000 ),
			'tools/list'               => array( 'tools/list', 60000 ),
			'prompts/list'             => array( 'prompts/list', 60000 ),
			'resources/list'           => array( 'resources/list', 3600000 ),
			'resources/templates/list' => array( 'resources/templates/list', 3600000 ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'cacheable' )]
	public function testCacheableResultsCarryPrivateHints( string $method, int $ttl ): void {
		$result = $this->resultOf( $this->modern( $method ) );

		self::assertSame( $ttl, $result['ttlMs'] );
		self::assertSame( 'private', $result['cacheScope'], 'Lists differ per token scope; a shared cache must never hand one connection\'s list to another.' );
	}

	public function testTheCacheLifetimeCanBeAdjustedButNotBelowZero(): void {
		add_filter( 'ab_mcp_cache_ttl_ms', static fn( int $ttl, string $method ): int => 'tools/list' === $method ? 5000 : -1, 10, 2 );

		self::assertSame( 5000, $this->resultOf( $this->modern( 'tools/list' ) )['ttlMs'] );
		self::assertSame( 0, $this->resultOf( $this->modern( 'prompts/list' ) )['ttlMs'], 'The schema requires ttlMs >= 0.' );
	}

	public function testResultsThatAreNotCacheableCarryNoHints(): void {
		$call = $this->resultOf(
			$this->modern(
				'tools/call',
				array(
					'name'      => 'wp_get_answer',
					'arguments' => array(),
				)
			)
		);
		$ping = $this->resultOf( $this->modern( 'ping' ) );

		self::assertArrayNotHasKey( 'ttlMs', $call );
		self::assertArrayNotHasKey( 'cacheScope', $ping );
	}

	public function testDiscoverDescribesTheServer(): void {
		$result = $this->resultOf( $this->modern( 'server/discover' ) );

		self::assertSame( array( self::MODERN ), $result['supportedVersions'], 'A client picks from this list for its _meta; a legacy revision does not work there.' );
		self::assertSame( array( 'listChanged' => false ), $result['capabilities']['tools'] );
		self::assertSame( array( 'listChanged' => false ), $result['capabilities']['prompts'], 'Prompts are offered, as in initialize.' );
		self::assertStringContainsString( '2 tools available.', $result['instructions'] );
		self::assertArrayNotHasKey( 'serverInfo', $result, 'serverInfo moved into _meta in 2026-07-28; at the top level a client rejected it.' );
		self::assertArrayNotHasKey( 'protocolVersion', $result );
	}

	public function testDiscoverCarriesTheSiteContractForTheHub(): void {
		$site = $this->resultOf( $this->modern( 'server/discover' ) )['_meta']['com.alphabridge-mcp/site'] ?? null;

		self::assertIsArray( $site, 'Without initialize, discover is where a hub learns about the site.' );
		self::assertSame( 1, $site['contract'] );
		self::assertSame( array( '2024-11-05', '2025-03-26', '2025-06-18', self::MODERN ), $site['protocol_versions'] );
	}

	public function testDiscoverPassesOnTheReadOnlyNotice(): void {
		AB_MCP_Settings::set( 'read_only', true );

		self::assertStringContainsString( 'READ-ONLY MODE is active', $this->resultOf( $this->modern( 'server/discover' ) )['instructions'] );
	}

	public function testDiscoverAndInitializeAgreeOnInstructionsAndCapabilities(): void {
		$discover   = $this->resultOf( $this->modern( 'server/discover' ) );
		$initialize = $this->resultOf( $this->legacy( 'initialize', array( 'protocolVersion' => '2025-06-18' ) ) );

		self::assertSame( $initialize['instructions'], $discover['instructions'] );
		self::assertSame( $initialize['capabilities'], $discover['capabilities'] );
		self::assertSame( $initialize['serverInfo'], $discover['_meta']['io.modelcontextprotocol/serverInfo'] );
	}

	public function testAnUnknownPromptIsInvalidParams(): void {
		$error = $this->errorOf(
			$this->modern(
				'prompts/get',
				array(
					'name'      => 'nope',
					'arguments' => array(),
				)
			),
			200
		);

		self::assertSame( -32602, $error['code'] );
	}

	public function testReadingAResourceSaysItDoesNotExist(): void {
		$error = $this->errorOf( $this->modern( 'resources/read', array( 'uri' => 'file:///etc/passwd' ) ), 200 );

		self::assertSame( -32602, $error['code'], '2026-07-28 answers a missing resource with -32602, no longer -32002.' );
		self::assertStringContainsString( 'tools/list', $error['message'], 'The refusal names the way: this server offers tools.' );
	}

	public function testResourcesReadStaysUnknownForOlderClients(): void {
		$error = $this->errorOf( $this->legacy( 'resources/read', array( 'uri' => 'file:///x' ) ), 200 );

		self::assertSame( -32601, $error['code'], 'As before.' );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function unknownMethods(): array {
		return array(
			'removed logging/setLevel' => array( 'logging/setLevel' ),
			'no completions'           => array( 'completion/complete' ),
			'made up'                  => array( 'tools/explode' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'unknownMethods' )]
	public function testAnUnknownMethodIs404WithMethodNotFound( string $method ): void {
		$error = $this->errorOf( $this->modern( $method ), 404 );

		self::assertSame( -32601, $error['code'] );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'unknownMethods' )]
	public function testAnUnknownMethodStays200ForOlderClients( string $method ): void {
		self::assertSame( -32601, $this->errorOf( $this->legacy( $method ), 200 )['code'] );
	}

	public function testAModernNotificationIsAcceptedWithoutAnAnswer(): void {
		$response = $this->post(
			array(
				'jsonrpc' => '2.0',
				'method'  => 'notifications/cancelled',
				'params'  => array( '_meta' => array( 'io.modelcontextprotocol/protocolVersion' => self::MODERN ) ),
			)
		);

		self::assertSame( 202, $response->get_status() );
		self::assertNull( $response->get_data() );
	}

	public function testNoSessionIsNeededOrMade(): void {
		// 2026-07-28 has no sessions: a request without any initialize works,
		// and leftovers of the older transport are ignored, never echoed.
		$response = $this->modern(
			'tools/call',
			array(
				'name'      => 'wp_get_answer',
				'arguments' => array(),
			),
			array(
				'Mcp-Session-Id' => 'leftover-session',
				'Last-Event-ID'  => '17',
			)
		);

		self::assertFalse( $this->resultOf( $response )['isError'] );
		self::assertSame( array( 'Referrer-Policy', 'Cache-Control' ), array_keys( $response->get_headers() ), 'No session id is minted or echoed.' );
	}

	/* --------------------------------------------------------- refusals */

	public function testAnUnsupportedVersionIs400WithTheVersionsThatWork(): void {
		$response = $this->post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 'r1',
				'method'  => 'tools/list',
				'params'  => array(
					'_meta' => array(
						'io.modelcontextprotocol/protocolVersion'    => '1900-01-01',
						'io.modelcontextprotocol/clientCapabilities' => array(),
					),
				),
			),
			array(
				'MCP-Protocol-Version' => '1900-01-01',
				'Mcp-Method'           => 'tools/list',
			)
		);
		$error = $this->errorOf( $response, 400 );

		self::assertSame( -32022, $error['code'] );
		self::assertSame( '1900-01-01', $error['data']['requested'] );
		self::assertSame( array( '2024-11-05', '2025-03-26', '2025-06-18', self::MODERN ), $error['data']['supported'] );
		self::assertSame( 'r1', $response->get_data()['id'] );
	}

	public function testALegacyVersionInMetadataIsUnsupportedThere(): void {
		$response = $this->post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/list',
				'params'  => array(
					'_meta' => array(
						'io.modelcontextprotocol/protocolVersion'    => '2025-06-18',
						'io.modelcontextprotocol/clientCapabilities' => array(),
					),
				),
			),
			array(
				'MCP-Protocol-Version' => '2025-06-18',
				'Mcp-Method'           => 'tools/list',
			)
		);
		$error = $this->errorOf( $response, 400 );

		self::assertSame( -32022, $error['code'] );
		self::assertStringContainsString( 'initialize', $error['message'], 'The way to a legacy revision is the handshake.' );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function badVersions(): array {
		return array(
			'null'   => array( null ),
			'empty'  => array( '' ),
			'number' => array( 20260728 ),
			'object' => array( array( 'v' => self::MODERN ) ),
		);
	}

	/**
	 * @param mixed $version Value of protocolVersion in _meta.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'badVersions' )]
	public function testAMalformedVersionFieldIsInvalidParams( $version ): void {
		$response = $this->post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/list',
				'params'  => array(
					'_meta' => array(
						'io.modelcontextprotocol/protocolVersion'    => $version,
						'io.modelcontextprotocol/clientCapabilities' => array(),
					),
				),
			),
			array(
				'MCP-Protocol-Version' => self::MODERN,
				'Mcp-Method'           => 'tools/list',
			)
		);

		self::assertSame( -32602, $this->errorOf( $response, 400 )['code'] );
	}

	/**
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function badCapabilities(): array {
		return array(
			'missing' => array( array() ),
			'string'  => array( array( 'io.modelcontextprotocol/clientCapabilities' => 'all' ) ),
			'null'    => array( array( 'io.modelcontextprotocol/clientCapabilities' => null ) ),
		);
	}

	/**
	 * @param array<string,mixed> $extra Capabilities entry of _meta.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'badCapabilities' )]
	public function testMissingClientCapabilitiesAreInvalidParams( array $extra ): void {
		$response = $this->post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/list',
				'params'  => array( '_meta' => array( 'io.modelcontextprotocol/protocolVersion' => self::MODERN ) + $extra ),
			),
			array(
				'MCP-Protocol-Version' => self::MODERN,
				'Mcp-Method'           => 'tools/list',
			)
		);
		$error = $this->errorOf( $response, 400 );

		self::assertSame( -32602, $error['code'] );
		self::assertStringContainsString( 'clientCapabilities', $error['message'] );
	}

	public function testTheVersionIsCheckedBeforeTheCapabilities(): void {
		// A later revision may require other fields; its client should learn
		// which versions work, not read about a field it does not know.
		$response = $this->post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/list',
				'params'  => array( '_meta' => array( 'io.modelcontextprotocol/protocolVersion' => '2027-01-01' ) ),
			),
			array(
				'MCP-Protocol-Version' => '2027-01-01',
				'Mcp-Method'           => 'tools/list',
			)
		);

		self::assertSame( -32022, $this->errorOf( $response, 400 )['code'] );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function badIds(): array {
		return array(
			'null'     => array( null ),
			'fraction' => array( 1.5 ),
			'boolean'  => array( true ),
			'list'     => array( array( 1 ) ),
		);
	}

	/**
	 * @param mixed $id Request id.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'badIds' )]
	public function testAnIdThatIsNeitherStringNorIntegerIsAnInvalidRequest( $id ): void {
		$error = $this->errorOf( $this->modern( 'tools/list', array(), array(), $id ), 400 );

		self::assertSame( -32600, $error['code'] );
	}

	public function testAWrongJsonrpcMemberIsAnInvalidRequest(): void {
		$response = $this->post(
			array(
				'jsonrpc' => '1.0',
				'id'      => 1,
				'method'  => 'tools/list',
				'params'  => array(
					'_meta' => array(
						'io.modelcontextprotocol/protocolVersion'    => self::MODERN,
						'io.modelcontextprotocol/clientCapabilities' => array(),
					),
				),
			),
			array(
				'MCP-Protocol-Version' => self::MODERN,
				'Mcp-Method'           => 'tools/list',
			)
		);

		self::assertSame( -32600, $this->errorOf( $response, 400 )['code'] );
	}

	/* ------------------------------------------------------ header check */

	/**
	 * @return array<string,array{0:string,1:array<string,mixed>,2:array<string,string>}>
	 */
	public static function headerMismatches(): array {
		$call = array(
			'name'      => 'wp_get_answer',
			'arguments' => array(),
		);
		return array(
			'version header missing'      => array( 'tools/list', array(), array( 'MCP-Protocol-Version' => '' ) ),
			'version header differs'      => array( 'tools/list', array(), array( 'MCP-Protocol-Version' => '2025-06-18' ) ),
			'method header missing'       => array( 'tools/list', array(), array( 'Mcp-Method' => '' ) ),
			'method header differs'       => array( 'tools/list', array(), array( 'Mcp-Method' => 'prompts/list' ) ),
			'method header other case'    => array( 'tools/list', array(), array( 'Mcp-Method' => 'Tools/List' ) ),
			'name header missing'         => array( 'tools/call', $call, array( 'Mcp-Name' => '' ) ),
			'name header differs'         => array( 'tools/call', $call, array( 'Mcp-Name' => 'wp_update_answer' ) ),
			'name header base64 differs'  => array( 'tools/call', $call, array( 'Mcp-Name' => '=?base64?' . base64_encode( 'wp_update_answer' ) . '?=' ) ),
			'name header bad base64'      => array( 'tools/call', $call, array( 'Mcp-Name' => '=?base64?not*base64?=' ) ),
			// Decoded leniently, the stray "*" is skipped and the value reads
			// wp_get_answer, the name in the body. Only strict decoding sees
			// the invalid character.
			'name header base64 with invalid char' => array( 'tools/call', $call, array( 'Mcp-Name' => '=?base64?d3Bf*Z2V0X2Fuc3dlcg==?=' ) ),
			'name header non-ASCII'       => array( 'tools/call', array( 'name' => 'wp_get_änswer' ), array( 'Mcp-Name' => 'wp_get_änswer' ) ),
			'prompt name header differs'  => array( 'prompts/get', array( 'name' => 'draft' ), array( 'Mcp-Name' => 'other' ) ),
			'resource uri header differs' => array( 'resources/read', array( 'uri' => 'file:///a' ), array( 'Mcp-Name' => 'file:///b' ) ),
			'name in body is not a string' => array( 'tools/call', array( 'name' => array( 'wp_get_answer' ) ), array( 'Mcp-Name' => 'wp_get_answer' ) ),
		);
	}

	/**
	 * @param array<string,mixed>  $params  Params.
	 * @param array<string,string> $headers Header overrides.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'headerMismatches' )]
	public function testAHeaderThatDoesNotMatchTheBodyIsRefused( string $method, array $params, array $headers ): void {
		$error = $this->errorOf( $this->modern( $method, $params, $headers ), 400 );

		self::assertSame( -32020, $error['code'] );
		self::assertStringStartsWith( 'Header mismatch', $error['message'] );
	}

	public function testARefusedToolCallRunsNothing(): void {
		$ran = false;
		$r   = new AB_MCP_Tool_Registry();
		$r->register(
			'wp_update_answer',
			array(
				'callback' => static function () use ( &$ran ): string {
					$ran = true;
					return 'changed';
				},
			)
		);
		$request = new WP_REST_Request();
		$request->set_json_params(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'wp_update_answer',
					'arguments' => array(),
					'_meta'     => array(
						'io.modelcontextprotocol/protocolVersion'    => self::MODERN,
						'io.modelcontextprotocol/clientCapabilities' => array(),
					),
				),
			)
		);
		$request->set_header( 'MCP-Protocol-Version', self::MODERN );
		$request->set_header( 'Mcp-Method', 'tools/call' );
		$request->set_header( 'Mcp-Name', 'wp_get_answer' );

		$response = ( new AB_MCP_REST_Controller( $r ) )->handle( $request );

		self::assertSame( 400, $response->get_status() );
		self::assertFalse( $ran, 'A proxy may have allowed wp_get_answer by its header; the body must not run another tool.' );
	}

	public function testAnEncodedNameThatMatchesIsAccepted(): void {
		$result = $this->resultOf(
			$this->modern(
				'tools/call',
				array(
					'name'      => 'wp_get_answer',
					'arguments' => array(),
				),
				array( 'Mcp-Name' => '=?base64?' . base64_encode( 'wp_get_answer' ) . '?=' )
			)
		);

		self::assertFalse( $result['isError'] );
	}

	public function testANonAsciiNameIsComparedAfterDecoding(): void {
		$error = $this->errorOf(
			$this->modern(
				'prompts/get',
				array( 'name' => 'dräft' ),
				array( 'Mcp-Name' => '=?base64?' . base64_encode( 'dräft' ) . '?=' )
			),
			200
		);

		self::assertSame( -32602, $error['code'], 'The header matched, so the request got through to prompts/get, which does not know the prompt.' );
	}

	public function testOlderClientsAreNotAskedForTheNewHeaders(): void {
		$result = $this->resultOf(
			$this->legacy(
				'tools/call',
				array(
					'name'      => 'wp_get_answer',
					'arguments' => array(),
				)
			)
		);

		self::assertFalse( $result['isError'], 'No Mcp-Method, no Mcp-Name, as older clients send them.' );
	}

	/* -------------------------------------------------- subscriptions/listen */

	public function testListenAcknowledgesNothingAndEndsGracefully(): void {
		$response = $this->modern( 'subscriptions/listen', array( 'notifications' => array( 'toolsListChanged' => true ) ), array(), 'sub-7' );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'text/event-stream', $response->get_headers()['Content-Type'] ?? null );
		self::assertSame( 'no', $response->get_headers()['X-Accel-Buffering'] ?? null );
		self::assertSame( 'no-store', $response->get_headers()['Cache-Control'] ?? null );

		list( $ack, $end ) = $response->get_data();

		self::assertSame( 'notifications/subscriptions/acknowledged', $ack['method'] );
		self::assertArrayNotHasKey( 'id', $ack, 'A notification.' );
		self::assertSame( 'sub-7', $ack['params']['_meta']['io.modelcontextprotocol/subscriptionId'] );
		self::assertSame( '{}', wp_json_encode( $ack['params']['notifications'] ), 'Nothing is honoured, so nothing is acknowledged: this server announces no changes.' );

		self::assertSame( 'sub-7', $end['id'] );
		self::assertSame( 'complete', $end['result']['resultType'] );
		self::assertSame( 'sub-7', $end['result']['_meta']['io.modelcontextprotocol/subscriptionId'], 'Required by SubscriptionsListenResultMetaObject.' );
	}

	public function testListenIsWrittenAsServerSentEvents(): void {
		$controller = $this->controller();
		$request    = new WP_REST_Request();
		$request->set_route( '/alphabridge/v1/mcp' );
		$response = $this->modern( 'subscriptions/listen', array( 'notifications' => array() ), array(), 3 );

		ob_start();
		$served = $controller->serve_event_stream( false, $response, $request );
		$out    = (string) ob_get_clean();

		self::assertTrue( $served, 'The REST server must not echo the data as JSON as well.' );
		$events = explode( "\n\n", rtrim( $out, "\n" ) );
		self::assertCount( 2, $events );
		foreach ( $events as $i => $event ) {
			$lines = explode( "\n", $event );
			self::assertSame( 'event: message', $lines[0] );
			self::assertStringStartsWith( 'data: ', $lines[1] );
			self::assertCount( 2, $lines, 'One data line per message.' );
			self::assertSame( wp_json_encode( $response->get_data()[ $i ] ), substr( $lines[1], 6 ) );
		}
	}

	public function testOtherResponsesAreLeftToTheRestServer(): void {
		$controller = $this->controller();
		$ours       = new WP_REST_Request();
		$ours->set_route( '/alphabridge/v1/mcp' );
		$other = new WP_REST_Request();
		$other->set_route( '/wp/v2/posts' );
		$stream = new WP_REST_Response( array( array( 'x' => 1 ) ), 200 );
		$stream->header( 'Content-Type', 'text/event-stream' );

		ob_start();
		$json    = $controller->serve_event_stream( false, $this->modern( 'tools/list' ), $ours );
		$foreign = $controller->serve_event_stream( false, $stream, $other );
		$already = $controller->serve_event_stream( true, $stream, $ours );
		$out     = (string) ob_get_clean();

		self::assertFalse( $json, 'A JSON answer goes out as JSON.' );
		self::assertFalse( $foreign, 'Another plugin\'s route is not ours to write.' );
		self::assertTrue( $already );
		self::assertSame( '', $out );
	}

	public function testListenIsUnknownToOlderClients(): void {
		self::assertSame( -32601, $this->errorOf( $this->legacy( 'subscriptions/listen' ), 200 )['code'], 'As before.' );
	}

	/* ------------------------------------------------------------ switch */

	/**
	 * @return array<string,array{0:callable}>
	 */
	public static function switchedOff(): array {
		return array(
			'setting' => array( static function (): void {
				AB_MCP_Settings::set( 'modern_protocol', false );
			} ),
			'filter'  => array( static function (): void {
				add_filter( 'ab_mcp_modern_protocol', '__return_false' );
			} ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'switchedOff' )]
	public function testSwitchedOffAModernRequestIsRefusedAsBefore( callable $off ): void {
		$off();
		$response = $this->modern( 'tools/list' );
		$data     = $response->get_data();

		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'ab_mcp_bad_protocol_version', $data['code'] );
		self::assertArrayNotHasKey( 'jsonrpc', $data, 'Not a modern error: a client that speaks both generations falls back to initialize.' );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'switchedOff' )]
	public function testSwitchedOffNoModernErrorCodeIsEverSent( callable $off ): void {
		$off();
		$probes = array(
			$this->modern( 'server/discover', array(), array( 'MCP-Protocol-Version' => '' ) ),
			$this->modern( 'tools/list', array(), array( 'Mcp-Method' => '' ) ),
			$this->post(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/list',
					'params'  => array( '_meta' => array( 'io.modelcontextprotocol/protocolVersion' => '1900-01-01' ) ),
				)
			),
		);

		foreach ( $probes as $response ) {
			$code = $response->get_data()['error']['code'] ?? null;
			self::assertNotContains( $code, array( -32020, -32021, -32022 ), (string) wp_json_encode( $response->get_data() ) );
			self::assertArrayNotHasKey( 'resultType', $response->get_data()['result'] ?? array() );
		}
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'switchedOff' )]
	public function testSwitchedOffDiscoverIsUnknownAsBefore( callable $off ): void {
		$off();
		$response = $this->modern( 'server/discover', array(), array( 'MCP-Protocol-Version' => '' ) );

		self::assertSame( -32601, $this->errorOf( $response, 200 )['code'] );
	}

	public function testTheFilterCanAlsoSwitchItOnAgainstTheSetting(): void {
		AB_MCP_Settings::set( 'modern_protocol', false );
		add_filter( 'ab_mcp_modern_protocol', '__return_true' );

		self::assertSame( 'complete', $this->resultOf( $this->modern( 'ping' ) )['resultType'] );
	}

	/* ----------------------------------------------------------- routes */

	/**
	 * @return array<int,array{0:string,1:string}>
	 */
	private function routes(): array {
		$GLOBALS['ab_test_routes'] = array();
		$this->controller()->register_routes();
		$methods = array();
		foreach ( $GLOBALS['ab_test_routes'] as $route ) {
			foreach ( $route['args'] as $handler ) {
				$methods[] = array( $route['route'], $handler['methods'] );
			}
		}
		return $methods;
	}

	/**
	 * One request through the emulated REST server (ab_test_rest_dispatch),
	 * which adds the Allow header the way WordPress does.
	 */
	private function dispatch( string $method, string $route = '/alphabridge/v1/mcp', bool $signed_in = true ): WP_REST_Response {
		$this->routes();
		$request = new WP_REST_Request();
		$request->set_method( $method );
		$request->set_route( $route );
		$request->set_json_params(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'ping',
			)
		);
		if ( $signed_in ) {
			ab_test_add_user( 7 );
			$request->set_header( 'Authorization', 'Bearer ' . AB_MCP_Settings::add_token( 7, 'Test', 'full', 0 ) );
		}
		return ab_test_rest_dispatch( $request );
	}

	/**
	 * @return array<string,array{0:bool}>
	 */
	public static function bothModes(): array {
		return array(
			'modern on'  => array( true ),
			'modern off' => array( false ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'bothModes' )]
	public function testTheRoutesKeepTheirHandlers( bool $modern ): void {
		AB_MCP_Settings::set( 'modern_protocol', $modern );

		self::assertSame( array( 'POST', 'GET', 'POST', 'GET' ), array_column( $this->routes(), 1 ), 'Both endpoints, the plain one and the one with the token in the path, as before.' );
	}

	/**
	 * WordPress writes Allow on every answer of a route from the route's
	 * handlers. These are the values the endpoint has always sent, in both
	 * modes: a further handler would add itself to each of them.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'bothModes' )]
	public function testTheAllowHeaderOfEveryOtherAnswerIsAsBefore( bool $modern ): void {
		AB_MCP_Settings::set( 'modern_protocol', $modern );

		$post = $this->dispatch( 'POST' );
		self::assertSame( 200, $post->get_status() );
		self::assertSame( 'POST, GET', $post->get_headers()['Allow'] ?? null );

		$get = $this->dispatch( 'GET' );
		self::assertSame( 405, $get->get_status() );
		self::assertSame( 'POST, GET', $get->get_headers()['Allow'] ?? null, 'WordPress replaces the handler\'s own "POST" here, and always has.' );

		$anonymous = $this->dispatch( 'POST', '/alphabridge/v1/mcp', false );
		self::assertSame( 401, $anonymous->get_status() );
		self::assertArrayNotHasKey( 'Allow', $anonymous->get_headers() );
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function deletes(): array {
		return array(
			'plain endpoint, signed in'  => array( '/alphabridge/v1/mcp', true ),
			'plain endpoint, anonymous'  => array( '/alphabridge/v1/mcp', false ),
			'token in the path'          => array( '/alphabridge/v1/mcp/abmcp_0123456789abcdef', false ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'deletes' )]
	public function testDeleteIsAnswered405WhileTheModernRevisionIsOn( string $route, bool $signed_in ): void {
		$answer = $this->dispatch( 'DELETE', $route, $signed_in );

		self::assertSame( 405, $answer->get_status() );
		self::assertSame( 'ab_mcp_method_not_allowed', $answer->get_data()['code'] );
		self::assertStringContainsString( 'POST', $answer->get_data()['message'], 'The refusal names the way in.' );
		self::assertSame( 'POST', $answer->get_headers()['Allow'] ?? null, 'Matched no route, so WordPress leaves this Allow alone.' );
		self::assertSame( 'no-store', $answer->get_headers()['Cache-Control'] ?? null );
	}

	public function testDeleteFindsThePathTheWayWordPressDoes(): void {
		self::assertSame( 405, $this->dispatch( 'DELETE', '/AlphaBridge/v1/MCP', false )->get_status(), 'WordPress matches routes without regard to case.' );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'switchedOff' )]
	public function testDeleteIsLeftToWordPressWhenTheModernRevisionIsOff( callable $off ): void {
		$off();
		$answer = $this->dispatch( 'DELETE' );

		self::assertSame( 404, $answer->get_status(), 'As before: no route for DELETE.' );
		self::assertSame( 'rest_no_route', $answer->get_data()['code'] );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function notOurs(): array {
		return array(
			'another route of the plugin' => array( '/alphabridge/v1/oauth/token' ),
			'a longer path'               => array( '/alphabridge/v1/mcpx' ),
			'a deeper path'               => array( '/alphabridge/v1/mcp/abc/def' ),
			'another plugin'              => array( '/wp/v2/posts/1' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'notOurs' )]
	public function testDeleteElsewhereIsLeftToWordPress( string $route ): void {
		$request = new WP_REST_Request();
		$request->set_method( 'DELETE' );
		$request->set_route( $route );

		self::assertNull( $this->controller()->refuse_delete( null, null, $request ) );
	}

	public function testAnAnswerAnotherFilterChoseIsKept(): void {
		$request = new WP_REST_Request();
		$request->set_method( 'DELETE' );
		$request->set_route( '/alphabridge/v1/mcp' );
		$chosen = new WP_REST_Response( null, 418 );

		self::assertSame( $chosen, $this->controller()->refuse_delete( $chosen, null, $request ) );
	}

	public function testTheEventStreamWriterIsHookedWithTheRoutes(): void {
		$this->routes();

		self::assertTrue( has_filter( 'rest_pre_serve_request' ) );
	}
}
