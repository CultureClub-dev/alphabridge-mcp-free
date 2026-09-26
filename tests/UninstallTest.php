<?php
/**
 * What uninstall.php removes.
 *
 * The list in uninstall.php is kept by hand, and it drifted once: the apps that
 * register for OAuth are stored under ab_mcp_oauth_clients since 4.3.0, and that
 * row stayed in the database after an uninstall until 4.3.5. So the first test
 * does not repeat the list. It asks the plugin's own classes which option names
 * they declare and checks that uninstall.php deletes every one of them — a new
 * option that is not added to the list fails here the day it is introduced.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use AB_MCP_Audit_Log;
use AB_MCP_OAuth;
use AB_MCP_Review_Notice;
use AB_MCP_Settings;

final class UninstallTest extends TestCase {

	private const PRO_FILE = '/alphabridge-mcp-pro/alphabridge-mcp-pro.php';

	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			\define( 'WP_UNINSTALL_PLUGIN', 'alphabridge-mcp/alphabridge-mcp.php' );
		}
		if ( ! \defined( 'WP_PLUGIN_DIR' ) ) {
			\define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/ab-mcp-uninstall-test-' . getmypid() );
		}
		// Every class the plugin ships, not only the ones the bootstrap needs:
		// an option declared in a class no other test loads must count too.
		$root = dirname( __DIR__ );
		foreach ( array_merge( glob( $root . '/includes/*.php' ), glob( $root . '/includes/tools/*.php' ) ) as $file ) {
			require_once $file;
		}
	}

	protected function setUp(): void {
		ab_test_reset();
		$this->removeProFile();
	}

	protected function tearDown(): void {
		$this->removeProFile();
	}

	/**
	 * Option names the plugin's classes declare: every class constant whose
	 * value is an ab_mcp_ name.
	 *
	 * @return string[]
	 */
	private static function declaredOptions(): array {
		$names = array();
		foreach ( get_declared_classes() as $class ) {
			if ( 0 !== strpos( $class, 'AB_MCP_' ) ) {
				continue;
			}
			foreach ( ( new ReflectionClass( $class ) )->getConstants() as $value ) {
				if ( is_string( $value ) && preg_match( '/^ab_mcp_[a-z_]+\z/', $value ) ) {
					$names[] = $value;
				}
			}
		}
		sort( $names );
		return array_values( array_unique( $names ) );
	}

	private function runUninstall(): void {
		include dirname( __DIR__ ) . '/uninstall.php';
	}

	private function removeProFile(): void {
		$file = WP_PLUGIN_DIR . self::PRO_FILE;
		if ( is_file( $file ) ) {
			unlink( $file );
		}
		if ( is_dir( dirname( $file ) ) ) {
			rmdir( dirname( $file ) );
		}
		if ( is_dir( WP_PLUGIN_DIR ) ) {
			rmdir( WP_PLUGIN_DIR );
		}
	}

	public function testTheScanSeesTheOptionsThePluginIsKnownToKeep(): void {
		// Without this the next test would pass on an empty scan.
		$declared = self::declaredOptions();
		foreach ( array(
			AB_MCP_Settings::OPT_OPTIONS,
			AB_MCP_Settings::OPT_TOKENS,
			AB_MCP_Settings::OPT_TOOLSTATE,
			AB_MCP_Audit_Log::OPTION,
			AB_MCP_OAuth::OPT_CLIENTS,
			AB_MCP_Review_Notice::OPTION,
			AB_MCP_Review_Notice::OPTION_DISMISSED,
		) as $known ) {
			self::assertContains( $known, $declared );
		}
	}

	public function testUninstallDeletesEveryOptionThePluginDeclares(): void {
		$declared = self::declaredOptions();
		foreach ( $declared as $name ) {
			update_option( $name, array( 'from' => 'this test' ) );
		}
		update_option( 'blogname', 'Not ours' );

		$this->runUninstall();

		foreach ( $declared as $name ) {
			self::assertFalse( get_option( $name ), $name . ' is still in the database after uninstall' );
		}
		self::assertSame( 'Not ours', get_option( 'blogname' ), 'only the plugin\'s own rows go' );
	}

	public function testTheRegisteredAppsGoWithTheRest(): void {
		// The row that stayed behind until 4.3.5: names, return addresses and
		// times of the apps that registered for OAuth.
		update_option(
			AB_MCP_OAuth::OPT_CLIENTS,
			array(
				'abmcp_client_1' => array(
					'name'          => 'ChatGPT',
					'redirect_uris' => array( 'https://chatgpt.com/connector/oauth/callback' ),
					'created'       => 1790000000,
					'used'          => 1790000100,
				),
			)
		);

		$this->runUninstall();

		self::assertFalse( get_option( AB_MCP_OAuth::OPT_CLIENTS ) );
	}

	public function testNothingIsDeletedWhileProIsStillInstalled(): void {
		// Pro bundles this core and shares its data; its own uninstall removes
		// the shared rows when it goes last.
		mkdir( dirname( WP_PLUGIN_DIR . self::PRO_FILE ), 0777, true );
		file_put_contents( WP_PLUGIN_DIR . self::PRO_FILE, '<?php' );
		$declared = self::declaredOptions();
		foreach ( $declared as $name ) {
			update_option( $name, 'kept' );
		}

		$this->runUninstall();

		foreach ( $declared as $name ) {
			self::assertSame( 'kept', get_option( $name ), $name . ' was deleted although Pro is installed' );
		}
	}
}
