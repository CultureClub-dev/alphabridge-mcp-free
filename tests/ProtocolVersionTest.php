<?php
/**
 * MCP protocol version: the MCP-Protocol-Version header gate and the
 * negotiation in initialize.
 *
 * This is the spot that decides what happens when hub and site, or client and
 * site, speak different MCP revisions. Every rule below has its own red
 * proof, so a later revision (2026-07-28 drops initialize altogether) cannot
 * loosen the gate or the fallback by accident.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_REST_Controller;
use AB_MCP_Tool_Registry;
use WP_REST_Request;
use WP_REST_Response;

final class ProtocolVersionTest extends TestCase {

	private const SUPPORTED = array( '2024-11-05', '2025-03-26', '2025-06-18' );

	protected function setUp(): void {
		ab_test_reset();
	}

	/**
	 * One POST through the real handler, the way a Streamable-HTTP client
	 * sends it. Null means the header is not sent at all.
	 *
	 * @param array<string,mixed> $message JSON-RPC message.
	 */
	private function post( array $message, ?string $version_header = null ): WP_REST_Response {
		$request = new WP_REST_Request();
		$request->set_json_params( $message );
		if ( null !== $version_header ) {
			$request->set_header( 'MCP-Protocol-Version', $version_header );
		}
		return ( new AB_MCP_REST_Controller( new AB_MCP_Tool_Registry() ) )->handle( $request );
	}

	/**
	 * @param array<string,mixed> $params initialize params.
	 */
	private function negotiated( array $params ): string {
		$response = $this->post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => $params,
			)
		);
		self::assertSame( 200, $response->get_status() );
		return $response->get_data()['result']['protocolVersion'];
	}

	private function ping( ?string $version_header ): WP_REST_Response {
		return $this->post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 7,
				'method'  => 'ping',
			),
			$version_header
		);
	}

	public function testTheSupportedListIsPinned(): void {
		self::assertSame(
			self::SUPPORTED,
			AB_MCP_REST_Controller::SUPPORTED_PROTOCOL_VERSIONS,
			'Adding a revision is a deliberate change: 2026-07-28 has no initialize and needs server/discover and resultType. Change this list only together with that implementation.'
		);
	}

	public function testTheFallbackOfThePluginFileIsTheNewestSupportedVersion(): void {
		// The test bootstrap defines its own copy of the constant, so read the
		// value the plugin really ships with.
		$main = (string) file_get_contents( dirname( __DIR__ ) . '/alphabridge-mcp.php' );
		self::assertSame( 1, preg_match( "/define\\(\\s*'AB_MCP_PROTOCOL_VERSION',\\s*'([^']+)'\\s*\\);/", $main, $m ), 'The constant is defined in alphabridge-mcp.php.' );

		self::assertSame( self::SUPPORTED[ array_key_last( self::SUPPORTED ) ], $m[1], 'A client asking for an unknown version must get a version this server speaks, the newest one.' );
		self::assertSame( $m[1], AB_MCP_PROTOCOL_VERSION, 'The bootstrap copy must match the shipped value, or every other test here measures the wrong fallback.' );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function supported(): array {
		$cases = array();
		foreach ( self::SUPPORTED as $version ) {
			$cases[ $version ] = array( $version );
		}
		return $cases;
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'supported' )]
	public function testInitializeEchoesASupportedVersion( string $version ): void {
		self::assertSame( $version, $this->negotiated( array( 'protocolVersion' => $version ) ), 'Older clients keep the revision they asked for.' );
	}

	/**
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function unsupported(): array {
		return array(
			'newer revision'   => array( array( 'protocolVersion' => '2026-07-28' ) ),
			'unknown string'   => array( array( 'protocolVersion' => 'latest' ) ),
			'empty string'     => array( array( 'protocolVersion' => '' ) ),
			'not a string'     => array( array( 'protocolVersion' => 20250618 ) ),
			'missing'          => array( array() ),
		);
	}

	/**
	 * @param array<string,mixed> $params initialize params.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'unsupported' )]
	public function testInitializeAnswersAnUnsupportedVersionWithTheFallback( array $params ): void {
		self::assertSame( '2025-06-18', $this->negotiated( $params ), 'Never echo a version this server does not speak.' );
	}

	public function testAnUnsupportedHeaderIsRefusedWith400(): void {
		$response = $this->ping( '2026-07-28' );
		$data     = $response->get_data();

		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'ab_mcp_bad_protocol_version', $data['code'] );
		self::assertArrayNotHasKey( 'jsonrpc', $data, 'An HTTP-level refusal, not a JSON-RPC answer: the message is never dispatched.' );
		self::assertStringContainsString( 'Supported: ' . implode( ', ', self::SUPPORTED ) . '.', $data['message'], 'The refusal names the versions that work.' );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'supported' )]
	public function testASupportedHeaderPasses( string $version ): void {
		$response = $this->ping( $version );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 7, $response->get_data()['id'] );
		self::assertArrayHasKey( 'result', $response->get_data() );
	}

	public function testAMissingHeaderPasses(): void {
		// Clients send the header only after initialize, so the handshake
		// itself and every older client arrive without it.
		$response = $this->ping( null );

		self::assertSame( 200, $response->get_status() );
		self::assertArrayHasKey( 'result', $response->get_data() );
	}

	public function testAnEmptyHeaderPasses(): void {
		$response = $this->ping( '' );

		self::assertSame( 200, $response->get_status() );
		self::assertArrayHasKey( 'result', $response->get_data() );
	}
}
