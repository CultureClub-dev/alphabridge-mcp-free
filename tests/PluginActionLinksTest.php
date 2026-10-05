<?php
/**
 * The «Settings» link in the plugin's row of the plugin list.
 *
 * WordPress plugins usually put it before «Deactivate»: whoever has just
 * activated the plugin finds the settings page with one click. It hangs on the
 * plugin's own file name, comes first, leads to the same address as every
 * other way to the page, and shows only to those who may open that page.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Admin;
use AB_MCP_Guidance;

final class PluginActionLinksTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
	}

	/** Whether the current user may open the settings page. */
	private static function may_manage_options( bool $yes ): void {
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => $yes && 'manage_options' === $cap;
	}

	/** A row as WordPress hands it over for an active plugin. */
	private static function row(): array {
		return array( 'deactivate' => '<a href="plugins.php?action=deactivate">Deactivate</a>' );
	}

	public function test_it_hangs_on_the_plugins_own_row_only(): void {
		new AB_MCP_Admin();
		self::may_manage_options( true );
		self::assertArrayHasKey( 'settings', apply_filters( 'plugin_action_links_alphabridge-mcp/alphabridge-mcp.php', self::row() ) );
		self::assertSame( self::row(), apply_filters( 'plugin_action_links_other-plugin/other-plugin.php', self::row() ), 'another plugin’s row stays as it was' );
	}

	public function test_it_comes_first_and_leads_to_the_settings_page(): void {
		self::may_manage_options( true );
		$links = ( new AB_MCP_Admin() )->action_links( self::row() );
		self::assertSame( array( 'settings', 'deactivate' ), array_keys( $links ) );
		self::assertSame( '<a href="https://example.test/wp-admin/options-general.php?page=alphabridge-mcp">Settings</a>', $links['settings'] );
		self::assertSame( AB_MCP_Guidance::settings_url(), 'https://example.test/wp-admin/options-general.php?page=alphabridge-mcp', 'the same address as every other way to the page' );
	}

	public function test_it_is_not_shown_to_someone_who_may_not_open_the_page(): void {
		self::may_manage_options( false );
		self::assertSame( self::row(), ( new AB_MCP_Admin() )->action_links( self::row() ) );
	}
}
