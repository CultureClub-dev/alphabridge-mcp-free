<?php
/**
 * The header that names why a tool reported an error (ERROR_CODE_HEADER):
 * a short code for every kind of tool error, never its text, once per
 * request, and nothing on a call that went well.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use AB_MCP_REST_Controller;
use AB_MCP_Tool_Registry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ErrorCodeHeaderTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
		( new ReflectionProperty( \AB_MCP_Auth::class, 'current_scope' ) )->setValue( null, 'full' );
	}

	private function controller(): AB_MCP_REST_Controller {
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
		$r->register(
			'wp_get_refused',
			array(
				'description' => 'Refuse with a WP_Error.',
				'callback'    => static fn() => new WP_Error( 'ab_mcp_bad_status', 'Invalid status for post 12: «Entwurf»' ),
			)
		);
		$r->register(
			'wp_get_broken',
			array(
				'description' => 'Throw.',
				'callback'    => static function (): void {
					throw new \RuntimeException( 'secret path /var/www/x' );
				},
			)
		);
		$r->register(
			'wp_get_by_id',
			array(
				'description' => 'Needs an id.',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array( 'id' => array( 'type' => 'integer' ) ),
				),
				'callback'    => static fn(): array => array( 'ok' => true ),
			)
		);
		return new AB_MCP_REST_Controller( $r );
	}

	/** @param mixed $body Decoded JSON body. */
	private function post( $body ): WP_REST_Response {
		$request = new WP_REST_Request();
		$request->set_json_params( $body );
		return $this->controller()->handle( $request );
	}

	/** @param array<string,mixed> $arguments Arguments. */
	private function call( string $tool, array $arguments = array(), int $id = 1 ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'method'  => 'tools/call',
			'params'  => array(
				'name'      => $tool,
				'arguments' => $arguments,
			),
		);
	}

	private function header( WP_REST_Response $response ): ?string {
		return $response->get_headers()[ AB_MCP_REST_Controller::ERROR_CODE_HEADER ] ?? null;
	}

	public function testTheToolsOwnErrorIsNamedByItsCodeAndNeverByItsText(): void {
		$response = $this->post( $this->call( 'wp_get_refused' ) );

		self::assertTrue( $response->get_data()['result']['isError'] );
		self::assertSame( 'tool:ab_mcp_bad_status', $this->header( $response ) );
		self::assertStringNotContainsString( 'Entwurf', (string) $this->header( $response ) );
	}

	public function testEveryKindOfToolErrorHasItsCode(): void {
		self::assertSame( 'unknown_tool', $this->header( $this->post( $this->call( 'wp_no_such_tool' ) ) ) );
		self::assertSame( 'internal', $this->header( $this->post( $this->call( 'wp_get_broken' ) ) ), 'An exception is named, its message stays in the audit log.' );
		self::assertStringStartsWith( 'params:', (string) $this->header( $this->post( $this->call( 'wp_get_by_id' ) ) ) );
		// A writing tool on a site in read mode is refused before it runs.
		self::assertSame( 'auth:ab_mcp_read_mode', $this->header( $this->post( $this->call( 'wp_update_answer' ) ) ) );
	}

	public function testACallThatWentWellCarriesNoCode(): void {
		$response = $this->post( $this->call( 'wp_get_answer' ) );

		self::assertFalse( $response->get_data()['result']['isError'] );
		self::assertNull( $this->header( $response ) );
	}

	public function testABatchNamesItsFirstError(): void {
		$response = $this->post(
			array(
				$this->call( 'wp_get_answer', array(), 1 ),
				$this->call( 'wp_get_refused', array(), 2 ),
				$this->call( 'wp_no_such_tool', array(), 3 ),
			)
		);

		self::assertSame( 'tool:ab_mcp_bad_status', $this->header( $response ) );
	}

	public function testTheCodeIsShortLowerCaseAndBeginsWithALetter(): void {
		self::assertSame( 'tool:ab_mcp_bad_status', AB_MCP_REST_Controller::error_code( 'tool:AB_MCP_BAD_STATUS' ) );
		self::assertSame( 'tool:a_b', AB_MCP_REST_Controller::error_code( 'tool:a b' ) );
		self::assertSame( 64, strlen( AB_MCP_REST_Controller::error_code( 'tool:' . str_repeat( 'x', 100 ) ) ) );
		self::assertSame( '', AB_MCP_REST_Controller::error_code( 'Über' ) );
		self::assertSame( '', AB_MCP_REST_Controller::error_code( '' ) );
		self::assertSame( '', AB_MCP_REST_Controller::error_code( ':tool' ) );
	}
}
