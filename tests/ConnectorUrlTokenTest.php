<?php
/**
 * Where a token is accepted. Header authentication always; a token in the
 * connector URL path (/mcp/<token>) only when the admin switched that on; a
 * token in the query string (?token=) or in a "token" body field never — also
 * not with the connector URL switched on.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Auth;
use AB_MCP_Settings;
use WP_REST_Request;

final class ConnectorUrlTokenTest extends TestCase {

	/** @var string */
	private $plain = '';

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 7 );
		$this->plain = AB_MCP_Settings::add_token( 7, 'Test connection', 'content', 0 );
	}

	private function connectorUrl( bool $on ): void {
		AB_MCP_Settings::set( 'connector_url_auth_enabled', $on );
	}

	public function testHeaderTokenIsAccepted(): void {
		$r = new WP_REST_Request();
		$r->set_header( 'Authorization', 'Bearer ' . $this->plain );
		self::assertSame( 7, AB_MCP_Auth::verify_request( $r ) );
	}

	public function testPathTokenIsAcceptedOnlyWithTheConnectorUrlOn(): void {
		$r = new WP_REST_Request();
		$r->set_url_params( array( 'token' => $this->plain ) );

		$this->connectorUrl( false );
		self::assertFalse( AB_MCP_Auth::verify_request( $r ), 'Off by default.' );

		$this->connectorUrl( true );
		self::assertSame( 7, AB_MCP_Auth::verify_request( $r ) );
	}

	public function testQueryTokenIsRefusedEvenWithTheConnectorUrlOn(): void {
		$this->connectorUrl( true );
		$r = new WP_REST_Request();
		$r->set_query_params( array( 'token' => $this->plain ) );
		self::assertSame( '', AB_MCP_Auth::extract_token( $r ) );
		self::assertFalse( AB_MCP_Auth::verify_request( $r ) );
	}

	public function testBodyTokenIsRefusedEvenWithTheConnectorUrlOn(): void {
		$this->connectorUrl( true );
		$r = new WP_REST_Request();
		$r->set_body_params( array( 'token' => $this->plain ) );
		self::assertSame( '', AB_MCP_Auth::extract_token( $r ) );
		self::assertFalse( AB_MCP_Auth::verify_request( $r ) );
	}

	public function testAQueryTokenDoesNotOverrideThePathToken(): void {
		$this->connectorUrl( true );
		$r = new WP_REST_Request();
		$r->set_url_params( array( 'token' => $this->plain ) );
		$r->set_query_params( array( 'token' => 'abmcp_' . str_repeat( 'b', 48 ) ) );
		self::assertSame( $this->plain, AB_MCP_Auth::extract_token( $r ), 'The path decides, as the route says.' );
	}
}
