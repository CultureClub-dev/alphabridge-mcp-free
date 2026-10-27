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

	public function testAWpErrorCodeIsPassedOnOnlyAsTheIdentifierItIsMeantToBe(): void {
		self::assertSame( 'tool:ab_mcp_bad_status', AB_MCP_REST_Controller::error_code( 'tool', 'ab_mcp_bad_status' ) );
		self::assertSame( 'params:rest_invalid_param', AB_MCP_REST_Controller::error_code( 'params', 'rest_invalid_param' ) );
		self::assertSame( 'unknown_tool', AB_MCP_REST_Controller::error_code( 'unknown_tool' ) );
		// Whoever raised the error wrote the code: text, a mail address, upper
		// case or a number are not cleaned up and passed on, they are left out.
		foreach ( array( 'Download denied for anna@example.org', 'anna@example.org', 'AB_MCP_BAD_STATUS', 'a b', 'rest.x', 404, '', str_repeat( 'x', 49 ) ) as $foreign ) {
			self::assertSame( 'tool:other', AB_MCP_REST_Controller::error_code( 'tool', $foreign ), (string) $foreign );
		}
	}

	public function testAForeignErrorCodeWithTextReachesNoHeader(): void {
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'content', 'Posts & Pages' );
		$r->register(
			'wp_get_download',
			array(
				'description' => 'Pass on what an HTTP filter answered.',
				'callback'    => static fn() => new WP_Error( 'Download denied for anna@example.org', 'Download denied.' ),
			)
		);
		$request = new WP_REST_Request();
		$request->set_json_params( $this->call( 'wp_get_download' ) );
		$response = ( new AB_MCP_REST_Controller( $r ) )->handle( $request );

		self::assertSame( 'tool:other', $this->header( $response ) );
	}

	public function testARequestAToolAnswersInsideAnotherKeepsBothCodesApart(): void {
		$controller = null;
		$inner      = null;
		$r          = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'content', 'Posts & Pages' );
		$r->register(
			'wp_get_nested',
			array(
				'description' => 'Answer an MCP request of its own through the same controller.',
				'callback'    => static function () use ( &$controller, &$inner ): array {
					$request = new WP_REST_Request();
					$request->set_json_params( array( 'jsonrpc' => '2.0', 'id' => 9, 'method' => 'ping' ) );
					$inner = $controller->handle( $request );
					return array( 'ok' => true );
				},
			)
		);
		$controller = new AB_MCP_REST_Controller( $r );
		$request    = new WP_REST_Request();
		$request->set_json_params( array( $this->call( 'wp_no_such_tool', array(), 1 ), $this->call( 'wp_get_nested', array(), 2 ) ) );

		$outer = $controller->handle( $request );

		self::assertSame( 'unknown_tool', $this->header( $outer ), 'The outer batch keeps its first error.' );
		self::assertNull( $this->header( $inner ), 'The inner ping went well and carries no code.' );
	}
}
