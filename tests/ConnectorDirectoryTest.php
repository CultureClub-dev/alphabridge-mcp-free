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
use AB_MCP_Settings;
use ReflectionMethod;

final class ConnectorDirectoryTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
	}

	/** The top of the connector box, with OAuth set as the site has it stored. */
	private function intro( bool $oauth ): string {
		AB_MCP_Settings::set( 'oauth_enabled', $oauth );
		$method = new ReflectionMethod( AB_MCP_Admin::class, 'connector_intro_html' );
		return (string) $method->invoke( new AB_MCP_Admin() );
	}

	public function testWithOAuthTheDirectoryEntryComesFirstWithThisSitesAddress(): void {
		$html = $this->intro( true );

		self::assertStringContainsString( 'AlphaBridge MCP for WordPress', $html );
		// The hub asks for the site's address, not for the endpoint, which merely
		// starts with it: the address must stand on its own, as the site has it.
		self::assertStringContainsString( 'address, ' . home_url() . ' —', $html );
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

	public function testOAuthIsOnUnlessTheSiteSwitchedItOff(): void {
		// A new site has no stored value: OAuth is on by default, so the entry shows.
		$method = new ReflectionMethod( AB_MCP_Admin::class, 'connector_intro_html' );

		self::assertStringContainsString( 'AlphaBridge MCP for WordPress', (string) $method->invoke( new AB_MCP_Admin() ) );
	}
}
