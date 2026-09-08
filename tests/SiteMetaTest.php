<?php
/**
 * The `_meta` self-description of the AlphaBridge Site Contract.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use AB_MCP_REST_Controller;
use AB_MCP_Tool_Registry;

final class SiteMetaTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
	}

	private function controller(): AB_MCP_REST_Controller {
		return new AB_MCP_REST_Controller( new AB_MCP_Tool_Registry() );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function meta(): array {
		$m = new ReflectionMethod( AB_MCP_REST_Controller::class, 'site_meta' );
		return $m->invoke( $this->controller() );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function initializeResult(): array {
		$m = new ReflectionMethod( AB_MCP_REST_Controller::class, 'initialize' );
		return $m->invoke( $this->controller(), array( 'protocolVersion' => '2025-06-18' ) );
	}

	public function testFreeWithoutProReportsTheFreeEdition(): void {
		$meta = $this->meta();

		self::assertSame( 1, $meta['contract'], 'Contract version 1.' );
		self::assertSame( 'wordpress', $meta['cms'] );
		self::assertSame( '6.9.1', $meta['cms_version'], 'Taken from get_bloginfo("version").' );
		self::assertSame( 'free', $meta['edition'] );
		self::assertSame( AB_MCP_VERSION, $meta['plugin_version'] );
	}

	public function testEveryContractFieldIsPresent(): void {
		self::assertSame(
			array( 'contract', 'cms', 'cms_version', 'edition', 'plugin_version', 'scope' ),
			array_keys( $this->meta() ),
			'A hub reads these six fields; none may silently disappear.'
		);
	}

	public function testScopeMirrorsTheAuthenticatedToken(): void {
		ab_test_add_user( 3 );
		$token = \AB_MCP_Settings::add_token( 3, 'Read-only client', 'read', 0 );
		self::assertSame( 3, \AB_MCP_Auth::verify_token( $token ) );

		self::assertSame( 'read', $this->meta()['scope'], 'The hub learns what the presented token may do.' );
	}

	public function testProFilterCanRaiseTheEditionToPro(): void {
		add_filter( 'ab_mcp_site_edition', static fn( string $e ): string => 'pro' );
		self::assertSame( 'pro', $this->meta()['edition'] );
	}

	public function testProFilterCanRaiseTheEditionToAgency(): void {
		add_filter( 'ab_mcp_site_edition', static fn( string $e ): string => 'agency' );
		self::assertSame( 'agency', $this->meta()['edition'] );
	}

	public function testAnUnknownEditionFallsBackToFree(): void {
		add_filter( 'ab_mcp_site_edition', static fn( string $e ): string => 'enterprise' );
		self::assertSame(
			'free',
			$this->meta()['edition'],
			'Fail closed: a typo in an add-on must never hand out a higher edition.'
		);
	}

	public function testAnEmptyEditionFallsBackToFree(): void {
		add_filter( 'ab_mcp_site_edition', static fn( string $e ): string => '' );
		self::assertSame( 'free', $this->meta()['edition'] );
	}

	public function testInitializeCarriesTheMetaUnderTheNamespacedKey(): void {
		$result = $this->initializeResult();

		self::assertArrayHasKey( '_meta', $result );
		self::assertArrayHasKey( 'com.alphabridge-mcp/site', $result['_meta'], 'Reverse-DNS key, so it cannot collide.' );
		self::assertSame( 1, $result['_meta']['com.alphabridge-mcp/site']['contract'] );
	}

	public function testInitializeStillCarriesServerInfo(): void {
		$result = $this->initializeResult();

		self::assertSame( 'AlphaBridge MCP', $result['serverInfo']['name'], 'The hub falls back to serverInfo when _meta is absent; it must not be renamed.' );
		self::assertSame( AB_MCP_VERSION, $result['serverInfo']['version'] );
	}
}
