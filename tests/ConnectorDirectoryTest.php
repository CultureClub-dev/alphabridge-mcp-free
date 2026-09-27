<?php
/**
 * The connector box names the entry in Claude's connector directory first.
 * That entry connects through the AlphaBridge Connect hub, and the hub signs in
 * with this site's own login and consent — the OAuth flow. With OAuth switched
 * off the entry cannot work, so the box must not offer it then.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Admin;
use ReflectionMethod;

final class ConnectorDirectoryTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
	}

	private function intro( bool $oauth ): string {
		$method = new ReflectionMethod( AB_MCP_Admin::class, 'connector_intro_html' );
		return (string) $method->invoke( new AB_MCP_Admin(), $oauth );
	}

	public function testWithOAuthTheDirectoryEntryComesFirstWithThisSitesAddress(): void {
		$html = $this->intro( true );

		self::assertStringContainsString( 'AlphaBridge MCP for WordPress', $html );
		self::assertStringContainsString( home_url(), $html, 'The hub asks for the site address; the box says which one to enter.' );
		self::assertStringContainsString( 'AlphaBridge Connect hub', $html, 'The box says that this way runs through the hub.' );
		self::assertLessThan(
			strpos( $html, 'Or directly, without the hub' ),
			strpos( $html, 'AlphaBridge MCP for WordPress' ),
			'The directory entry comes before the direct way.'
		);
	}

	public function testWithoutOAuthTheDirectoryEntryIsNotOffered(): void {
		$html = $this->intro( false );

		self::assertStringNotContainsString( 'AlphaBridge MCP for WordPress', $html );
		self::assertStringNotContainsString( 'hub', $html );
		self::assertStringContainsString( 'Copy the endpoint', $html );
	}
}
