<?php
/**
 * Free since 4.11.0, before in AlphaBridge MCP Pro only: installing,
 * updating and deleting plugins and themes, updating WordPress core, and
 * switching plugins and themes on and off — the nine tools of Pro's
 * AB_MCP_Tools_Lifecycle_Pro (here AB_MCP_Tools_Install_Update) and three of
 * its AB_MCP_Tools_Plugins_Themes (here AB_MCP_Tools_Plugins_Themes_Manage),
 * with the guards they run through (includes/lifecycle/).
 *
 * They are Pro's code, so these checks are the ones that matter when code
 * changes hands: each tool is registered here, in Pro's group, with the same
 * capability, the Mighty flag and the annotations clients receive; the undo
 * of a theme switch is restored here; and nothing of it offers a tool that
 * stays in Pro as the way. The tools themselves are checked by the scripts
 * under tests/standalone-lifecycle/ (LifecycleStandaloneTest).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Guidance;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Install_Update;
use AB_MCP_Tools_Plugins_Themes_Manage;
use AB_MCP_Undo_Restore;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__ . '/undo-stubs.php';
require_once __DIR__ . '/../includes/class-page-cache.php';
require_once __DIR__ . '/../includes/class-post-status.php';
require_once __DIR__ . '/../includes/undo/class-undo.php';
require_once __DIR__ . '/../includes/undo/class-undo-restore.php';
require_once __DIR__ . '/../includes/tools/class-tools-install-update.php';
require_once __DIR__ . '/../includes/tools/class-tools-plugins-themes-manage.php';

final class FreeSinceFourElevenTest extends TestCase {

	/**
	 * The twelve as Pro 4.10.0 registers them: class, group, capability,
	 * required arguments, and the open-world hint a client receives.
	 */
	private const TOOLS = array(
		'wp_install_plugin'     => array( 'install', 'lifecycle', 'install_plugins', array( 'slug' ), true ),
		'wp_install_plugin_zip' => array( 'install', 'lifecycle', 'install_plugins', array(), true ),
		'wp_install_theme'      => array( 'install', 'lifecycle', 'install_themes', array( 'slug' ), true ),
		'wp_install_theme_zip'  => array( 'install', 'lifecycle', 'install_themes', array(), true ),
		'wp_update_plugin'      => array( 'install', 'lifecycle', 'update_plugins', array( 'plugin' ), true ),
		'wp_update_theme'       => array( 'install', 'lifecycle', 'update_themes', array( 'stylesheet' ), true ),
		'wp_update_core'        => array( 'install', 'lifecycle', 'update_core', array(), true ),
		'wp_delete_plugin'      => array( 'install', 'lifecycle', 'delete_plugins', array( 'plugin' ), false ),
		'wp_delete_theme'       => array( 'install', 'lifecycle', 'delete_themes', array( 'stylesheet' ), false ),
		'wp_activate_plugin'    => array( 'manage', 'plugins-themes', 'activate_plugins', array( 'plugin' ), false ),
		'wp_deactivate_plugin'  => array( 'manage', 'plugins-themes', 'activate_plugins', array( 'plugin' ), false ),
		'wp_activate_theme'     => array( 'manage', 'plugins-themes', 'switch_themes', array( 'theme' ), false ),
	);

	protected function setUp(): void {
		ab_test_reset();
	}

	private static function registry(): AB_MCP_Tool_Registry {
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'lifecycle', 'Install & Update' );
		AB_MCP_Tools_Install_Update::register( $r );
		$r->set_current_group( 'plugins-themes', 'Plugins & Themes' );
		AB_MCP_Tools_Plugins_Themes_Manage::register( $r );
		return $r;
	}

	/* ------------------------------------------------------- registration */

	public function testTheTwelveAreRegisteredAsProRegisteredThem(): void {
		$r = self::registry();
		self::assertSame( array_keys( self::TOOLS ), array_values( array_intersect( array_keys( self::TOOLS ), array_keys( $r->all() ) ) ) );
		self::assertCount( 12, $r->all(), 'The two classes register these twelve and nothing else.' );
		foreach ( self::TOOLS as $name => list( , $group, $cap, $required, $open ) ) {
			$def = $r->get( $name );
			self::assertSame( $group, $def['group'], $name );
			self::assertSame( $cap, $def['capability'], $name );
			self::assertTrue( $def['dangerous'], $name . ' is Mighty: it runs only with write access on.' );
			self::assertArrayNotHasKey( 'tier', $def, $name . ' is a tool of this plugin now.' );
			self::assertSame( $required, $def['inputSchema']['required'] ?? array(), $name );
			self::assertIsCallable( $def['callback'], $name );

			$hints = AB_MCP_Tool_Registry::annotations( $name, $def, $name );
			self::assertFalse( $hints['readOnlyHint'], $name );
			self::assertTrue( $hints['destructiveHint'], $name . ' replaces, removes or switches code.' );
			self::assertSame( $open, $hints['openWorldHint'], $name );
		}
		foreach ( array( 'wp_install_plugin_zip', 'wp_install_theme_zip' ) as $name ) {
			self::assertTrue( $r->get( $name )['open_world'], $name . ' says it with its flag: it downloads from the url it is given.' );
			foreach ( array( 'content', 'url', 'session', 'activate' ) as $param ) {
				self::assertArrayHasKey( $param, $r->get( $name )['inputSchema']['properties'], $name . '.' . $param );
			}
		}
		self::assertSame( array( 'confirm_token' ), array_keys( (array) $r->get( 'wp_update_core' )['inputSchema']['properties'] ), 'wp_update_core takes no arguments but the confirmation.' );
		foreach ( array( 'wp_update_core', 'wp_install_plugin_zip', 'wp_install_theme_zip', 'wp_delete_plugin', 'wp_delete_theme' ) as $name ) {
			self::assertTrue( \AB_MCP_Confirm_Token::required( $r->get( $name ) ), $name . ' runs only with the token from its preview.' );
			self::assertArrayHasKey( 'confirm_token', (array) $r->get( $name )['inputSchema']['properties'], $name );
		}
		foreach ( array( 'wp_install_plugin', 'wp_install_theme', 'wp_update_plugin', 'wp_update_theme', 'wp_activate_plugin', 'wp_deactivate_plugin', 'wp_activate_theme' ) as $name ) {
			self::assertFalse( \AB_MCP_Confirm_Token::required( $r->get( $name ) ), $name . ' has its own way back or none is needed, so no confirmation.' );
		}
	}

	public function testThisPluginNowHas100Tools(): void {
		foreach ( glob( dirname( __DIR__ ) . '/includes/tools/class-tools-*.php' ) as $file ) {
			require_once $file;
		}
		$r = new AB_MCP_Tool_Registry();
		foreach ( get_declared_classes() as $class ) {
			if ( 0 === strpos( $class, 'AB_MCP_Tools_' ) && ! ( new \ReflectionClass( $class ) )->isAbstract() && method_exists( $class, 'register' ) ) {
				$class::register( $r );
			}
		}
		self::assertCount( 100, $r->all(), '82 until 4.10.0, the twelve and wp_connection_doctor (4.11.0), wp_find_media, wp_list_abilities and wp_run_ability (4.12.0), wp_set_preview_protection and wp_staging_status (4.15.0).' );
		foreach ( array( 'wp_find_media', 'wp_list_abilities', 'wp_run_ability', 'wp_set_preview_protection', 'wp_staging_status' ) as $name ) {
			self::assertNotNull( $r->get( $name ), $name );
		}
		foreach ( array_keys( self::TOOLS ) as $name ) {
			self::assertNotNull( $r->get( $name ), $name );
		}
	}

	public function testNoneOfThemIsAnsweredAsAToolOfPro(): void {
		foreach ( array_keys( self::TOOLS ) as $name ) {
			self::assertNotContains( $name, AB_MCP_Guidance::PRO_TOOLS, $name );
			self::assertSame( '', AB_MCP_Guidance::edition_of( $name ), $name );
			self::assertNull( AB_MCP_Guidance::unavailable_tool( $name ), $name );
		}
		self::assertStringNotContainsString( 'installing and updating', AB_MCP_Guidance::instructions_paragraph() );
	}

	/**
	 * No text of the twelve and their guards offers a tool that stays in Pro
	 * (its file tools, its uploads, wp_network_activate_plugin) as the way:
	 * on a site without Pro that way does not exist. Every string literal of
	 * the code is read, the descriptions and the messages alike.
	 */
	public function testNoTextOffersAToolThatStaysInPro(): void {
		$files = array_merge(
			glob( dirname( __DIR__ ) . '/includes/lifecycle/*.php' ),
			array(
				dirname( __DIR__ ) . '/includes/tools/class-tools-install-update.php',
				dirname( __DIR__ ) . '/includes/tools/class-tools-plugins-themes-manage.php',
			)
		);
		self::assertGreaterThanOrEqual( 11, count( $files ) );
		$found = array();
		foreach ( $files as $file ) {
			foreach ( token_get_all( (string) file_get_contents( $file ) ) as $t ) {
				if ( ! is_array( $t ) || T_CONSTANT_ENCAPSED_STRING !== $t[0] ) {
					continue;
				}
				foreach ( array_merge( AB_MCP_Guidance::PRO_TOOLS, AB_MCP_Guidance::AGENCY_TOOLS ) as $pro ) {
					if ( preg_match( '/\b' . preg_quote( $pro, '/' ) . '\b/', $t[1] ) ) {
						$found[] = basename( $file ) . ':' . $t[2] . ' ' . $pro;
					}
				}
			}
		}
		// The undo of a theme switch drops the file points of Pro's file tools
		// in the shared store after a kept update: names of tools there, no way.
		self::assertSame( array(), $found );
	}

	/* ------------------------------------------------ the ZIP without Pro */

	public function testASessionWithoutProIsRefusedWithTheWayToContentOrUrl(): void {
		self::assertFalse( class_exists( 'AB_MCP_Pro_Tools_Transfer', false ) );
		$m   = new ReflectionMethod( AB_MCP_Tools_Install_Update::class, 'package_from_args' );
		$res = $m->invoke( null, array( 'session' => 'abc123', 'content' => base64_encode( "PK\x03\x04zip" ) ) );
		self::assertInstanceOf( \WP_Error::class, $res );
		self::assertSame( 'ab_mcp_no_session', $res->get_error_code() );
		self::assertStringContainsString( 'AlphaBridge MCP Pro', $res->get_error_message() );
		self::assertStringContainsString( '"content"', $res->get_error_message() );
		self::assertStringContainsString( '"url"', $res->get_error_message() );
		self::assertStringNotContainsString( 'wp_upload_', $res->get_error_message() );

		$res = $m->invoke( null, array() );
		self::assertSame( 'ab_mcp_need_package', $res->get_error_code() );
		self::assertStringNotContainsString( 'session', $res->get_error_message(), 'Without Pro there is no session to name.' );
	}

	/* ------------------------------------------------------ the undo */

	public function testASwitchOfTheThemeIsUndoneHere(): void {
		$p = AB_MCP_Undo_Restore::policy();
		self::assertSame( array( 'kind' => 'theme', 'cap' => 'switch_themes' ), $p['wp_activate_theme'] );
		self::assertSame( 'site', AB_MCP_Undo_Restore::page_cache_scope()['theme'] );
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		( new \ReflectionProperty( \AB_MCP_Auth::class, 'current_scope' ) )->setValue( null, 'full' );
		self::assertSame( 'ab_mcp_undo_corrupt', AB_MCP_Undo_Restore::plan( array( 'tool' => 'wp_activate_theme', 'kind' => 'theme' ), array( 'stylesheet' => '../x/y' ) )->get_error_code() );
		self::assertSame( 'ab_mcp_undo_kind_mismatch', AB_MCP_Undo_Restore::plan( array( 'tool' => 'wp_activate_theme', 'kind' => 'site_settings' ), array( 'blogname' => 'x' ) )->get_error_code(), 'The tool may claim no other kind.' );

		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/tools/class-tools-undo-core.php' );
		self::assertGreaterThanOrEqual( 2, substr_count( $src, 'wp_activate_theme' ), 'wp_undo_list and wp_undo name the theme switch.' );
	}

	/* ------------------------------------------------------ the loader */

	public function testThePluginLoadsTheToolsAndHooksWhatRunsOnEveryRequest(): void {
		$plugin = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-plugin.php' );
		self::assertStringContainsString( "'AB_MCP_Tools_Install_Update'        => array( 'lifecycle',", $plugin );
		self::assertStringContainsString( "'AB_MCP_Tools_Plugins_Themes_Manage' => array( 'plugins-themes',", $plugin );
		foreach ( array( "'install-update',", "'plugins-themes-manage'," ) as $file ) {
			self::assertStringContainsString( $file, $plugin );
		}

		$main = (string) file_get_contents( dirname( __DIR__ ) . '/alphabridge-mcp.php' );
		foreach ( array(
			"add_filter( 'auto_update_plugin', array( 'AB_MCP_Update_Log', 'auto_update_plugin' ), 100, 2 );",
			"add_filter( 'auto_update_theme', array( 'AB_MCP_Update_Log', 'auto_update_theme' ), 100, 2 );",
			"add_action( AB_MCP_Update_Log::RESUME, array( 'AB_MCP_Update_Log', 'resume_auto_updates' ), 10, 0 );",
			"add_action( 'init', array( 'AB_MCP_Update_Log', 'keep_resume_scheduled' ), 20, 0 );",
			'AB_MCP_Probe_State::boot();',
			'AB_MCP_Theme_Switch_State::hook();',
		) as $line ) {
			self::assertStringContainsString( $line, $main );
		}
	}

	public function testTheSharedStateKeepsTheNamesProGaveIt(): void {
		// Free and Pro read and write the same rows: a hold written by one
		// is answered by the other, and one lock covers both.
		self::assertSame( 'ab_mcp_pro_update_log', \AB_MCP_Update_Log::OPTION );
		self::assertSame( 'ab_mcp_pro_update_holds', \AB_MCP_Update_Log::HOLDS );
		self::assertSame( 'ab_mcp_pro_update_running', \AB_MCP_Update_Log::RUNNING );
		self::assertSame( 'ab_mcp_pro_update_replaced', \AB_MCP_Update_Log::REPLACED );
		self::assertSame( 'ab_mcp_pro_resume_auto_updates', \AB_MCP_Update_Log::RESUME );
		self::assertSame( 'ab_mcp_pro_code_change.lock', \AB_MCP_Change_Lock::OPTION );
		self::assertSame( 'auto_updater', \AB_MCP_Update_Guard::LOCK );
		self::assertSame( 'ab-mcp-state', \AB_MCP_Probe_State::PARAM );
		self::assertStringStartsWith( '###### ab_mcp_state_start:k ######', \AB_MCP_Probe_State::block( 'k' ) );
	}

	public function testNoClassNameCollidesWithOneOfPro(): void {
		// Pro declares these without a _Pro suffix, and its lifecycle class
		// extends AB_MCP_Tools_Lifecycle with private helpers of its own: a
		// class of the same name, or one of those helpers in the parent,
		// would end every request with a fatal error once Pro loads.
		foreach ( array( 'Plugins_Themes', 'Undo', 'Files', 'Php_Snippets', 'Database', 'Blueprint', 'Migration', 'Multisite', 'Users_Menus', 'Woocommerce' ) as $name ) {
			self::assertFalse( class_exists( 'AB_MCP_Tools_' . $name, false ), $name );
		}
		require_once dirname( __DIR__ ) . '/includes/tools/class-tools-lifecycle.php';
		foreach ( array( 'file_mods_allowed', 'package_from_args', 'activate_installed_plugin', 'activate_installed_theme', 'installed_first', 'own_update_refusal', 'own_delete_refusal', 'no_package', 'install_failed', 'exists_refusal', 'boot_upgrader', 'update_core' ) as $method ) {
			self::assertFalse( method_exists( 'AB_MCP_Tools_Lifecycle', $method ), 'AB_MCP_Tools_Lifecycle::' . $method );
		}
		foreach ( array( 'name_for_message', 'not_installed_message', 'switch_locked', 'theme_options', 'switch_back', 'wordpress_folders', 'resolve_within', 'read_slice', 'write_bytes' ) as $method ) {
			self::assertFalse( method_exists( 'AB_MCP_Tools_Base', $method ), 'AB_MCP_Tools_Base::' . $method );
		}
		self::assertSame( 'AB_MCP_Tools_Base', get_parent_class( AB_MCP_Tools_Install_Update::class ) );
		self::assertSame( 'AB_MCP_Tools_Base', get_parent_class( AB_MCP_Tools_Plugins_Themes_Manage::class ) );
	}
}
