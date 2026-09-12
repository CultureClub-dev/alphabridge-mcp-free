<?php
/**
 * Per-tool on/off overrides survive a rename.
 *
 * The override map is keyed by tool name. After 4.3.0 renamed the two SEO
 * tools, an admin who had deliberately switched one off would have found it
 * back on: the old key pointed at nothing and the tool fell back to its
 * default. Nothing would have shown an error, and the settings screen would
 * have looked as if it had always been that way.
 *
 * Silently re-enabling something somebody switched off is the kind of
 * regression nobody reports.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AB_MCP_Settings;

final class ToolRenameMigrationTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
	}

	public function testASwitchedOffToolStaysSwitchedOffUnderItsNewName(): void {
		update_option( AB_MCP_Settings::OPT_TOOLSTATE, array( 'seo_get' => false ) );

		AB_MCP_Settings::install_defaults();

		$state = AB_MCP_Settings::get_tool_state();
		$this->assertArrayNotHasKey( 'seo_get', $state, 'the old key should be gone' );
		$this->assertArrayHasKey( 'wp_seo_get', $state );
		$this->assertFalse( $state['wp_seo_get'], 'the tool was off and must stay off' );
	}

	public function testAnExplicitlyEnabledToolKeepsThatToo(): void {
		update_option( AB_MCP_Settings::OPT_TOOLSTATE, array( 'seo_detect' => true ) );

		AB_MCP_Settings::install_defaults();

		$state = AB_MCP_Settings::get_tool_state();
		$this->assertTrue( $state['wp_seo_detect'] );
	}

	public function testADecisionUnderTheNewNameWins(): void {
		// Both keys present: the new one is the more recent decision, and
		// overwriting it with the old one would undo whatever the admin did
		// after updating.
		update_option(
			AB_MCP_Settings::OPT_TOOLSTATE,
			array(
				'seo_get'    => false,
				'wp_seo_get' => true,
			)
		);

		AB_MCP_Settings::install_defaults();

		$state = AB_MCP_Settings::get_tool_state();
		$this->assertTrue( $state['wp_seo_get'] );
		$this->assertArrayNotHasKey( 'seo_get', $state );
	}

	public function testUntouchedToolsAreLeftAlone(): void {
		update_option( AB_MCP_Settings::OPT_TOOLSTATE, array( 'wp_delete_post' => true ) );

		AB_MCP_Settings::install_defaults();

		$this->assertSame( array( 'wp_delete_post' => true ), AB_MCP_Settings::get_tool_state() );
	}
}
