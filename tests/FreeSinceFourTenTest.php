<?php
/**
 * Free since 4.10.0, before in Pro only: wp_update_builder_element and
 * wp_search_replace, as Pro registered them, plus what 4.10.0 adds to the
 * replace — post_ids, expected_count and an undo point of its own for the
 * option rows it changes (kind option_rows) — and the guard on option names
 * those rows pass (AB_MCP_Option_Guard). The tools themselves are checked by
 * the scripts under tests/standalone-builders/ (BuilderStandaloneTest).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Auth;
use AB_MCP_Option_Guard;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Builder_Write;
use AB_MCP_Tools_Search_Replace;
use AB_MCP_Tools_Undo_Core;
use AB_MCP_Undo;
use AB_MCP_Undo_Restore;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

require_once __DIR__ . '/undo-stubs.php';
require_once __DIR__ . '/../includes/class-page-cache.php';
require_once __DIR__ . '/../includes/class-post-status.php';
require_once __DIR__ . '/../includes/class-option-guard.php';
require_once __DIR__ . '/../includes/undo/class-undo.php';
require_once __DIR__ . '/../includes/undo/class-undo-restore.php';
require_once __DIR__ . '/../includes/tools/class-tools-options-settings.php';
require_once __DIR__ . '/../includes/tools/class-tools-undo-core.php';
require_once __DIR__ . '/../includes/tools/class-tools-builder-write.php';
require_once __DIR__ . '/../includes/tools/class-tools-search-replace.php';

final class FreeSinceFourTenTest extends TestCase {

	private string $uploads = '';

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		$GLOBALS['ab_test_can']          = static fn(): bool => true;
		( new ReflectionProperty( AB_MCP_Auth::class, 'current_scope' ) )->setValue( null, 'full' );
		$this->uploads                     = sys_get_temp_dir() . '/ab-free-410-phpunit-' . getmypid() . '-' . bin2hex( random_bytes( 6 ) );
		$GLOBALS['ab_test_upload_basedir'] = $this->uploads;
		$GLOBALS['wpdb']                   = new \AbTestUndoWpdb();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['ab_test_upload_basedir'] );
		$dir = $this->uploads . '/ab-mcp-undo';
		foreach ( (array) glob( $dir . '/{,.}*', GLOB_BRACE ) as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
		@rmdir( $dir );
		@rmdir( $this->uploads );
	}

	private function registry(): AB_MCP_Tool_Registry {
		$r = new AB_MCP_Tool_Registry();
		foreach ( array( AB_MCP_Tools_Builder_Write::class => 'builders', AB_MCP_Tools_Search_Replace::class => 'search', AB_MCP_Tools_Undo_Core::class => 'undo' ) as $class => $group ) {
			$r->set_current_group( $group, $group );
			$class::register( $r );
		}
		return $r;
	}

	/* ------------------------------------------------------- registration */

	public function testBothAreRegisteredAsProRegisteredThem(): void {
		$r = $this->registry();
		$b = $r->get( 'wp_update_builder_element' );
		$s = $r->get( 'wp_search_replace' );
		self::assertIsArray( $b );
		self::assertIsArray( $s );
		self::assertSame( array( 'builders', 'edit_posts', true ), array( $b['group'], $b['capability'], $b['dangerous'] ) );
		self::assertSame( array( 'search', 'manage_options', true ), array( $s['group'], $s['capability'], $s['dangerous'] ) );
		self::assertArrayNotHasKey( 'tier', $b, 'A tool of this plugin now.' );
		self::assertArrayNotHasKey( 'tier', $s, 'A tool of this plugin now.' );
		self::assertSame( array( 'id', 'element_id', 'fields' ), $b['inputSchema']['required'] );
		foreach ( array( 'post_ids', 'expected_count', 'scope', 'allow_without_undo', 'after' ) as $param ) {
			self::assertArrayHasKey( $param, $s['inputSchema']['properties'], $param );
		}
		self::assertStringContainsString( 'options_undo_token', $s['description'] );
		self::assertArrayHasKey( 'force', $r->get( 'wp_undo' )['inputSchema']['properties'], 'wp_undo restores page-builder pages changed since only with force.' );
	}

	/* ------------------------------------------- the undo of option rows */

	/** An undo point of the replace for option rows, as write_options() records it. */
	private static function option_point( array $rows ): string {
		$token = AB_MCP_Undo::record_result(
			array(
				'tool'    => 'wp_search_replace',
				'cap'     => 'manage_options',
				'kind'    => 'option_rows',
				'summary' => count( $rows ) . ' options',
				'items'   => count( $rows ),
				'data'    => $rows,
			)
		);
		self::assertIsString( $token );
		return $token;
	}

	public function testTheOldValuesComeBackAndTheTokenIsUsedUp(): void {
		update_option( 'blogdescription', 'new tagline' );
		update_option( 'my_plugin_label', array( 'title' => 'new' ) );
		$token = self::option_point(
			array(
				array( 'name' => 'blogdescription', 'value' => 'old tagline', 'autoload' => 'yes', 'existed' => true ),
				array( 'name' => 'my_plugin_label', 'value' => array( 'title' => 'old' ), 'autoload' => 'no', 'existed' => true ),
			)
		);
		$out = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $token ) );
		self::assertIsArray( $out );
		self::assertSame( 2, $out['undone'] );
		self::assertSame( 'old tagline', get_option( 'blogdescription' ) );
		self::assertSame( array( 'title' => 'old' ), get_option( 'my_plugin_label' ) );
		self::assertSame( 'ab_mcp_undo_not_found', AB_MCP_Tools_Undo_Core::undo( array( 'token' => $token ) )->get_error_code() );
	}

	public function testAnOptionGoneSinceComesBackWithItsAutoloadFlag(): void {
		$token = self::option_point(
			array(
				array( 'name' => 'my_plugin_cache', 'value' => 'old', 'autoload' => 'no', 'existed' => true ),
				array( 'name' => 'my_plugin_label', 'value' => 'old label', 'autoload' => 'yes', 'existed' => true ),
			)
		);
		// Both rows were deleted after the replace changed them.
		$out = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $token ) );
		self::assertSame( 2, $out['undone'] );
		self::assertSame( 'old', get_option( 'my_plugin_cache' ) );
		self::assertSame( 'no', $GLOBALS['ab_test_autoload']['my_plugin_cache'], 'A row that was not autoloaded is not autoloaded on every request after the undo.' );
		self::assertSame( 'yes', $GLOBALS['ab_test_autoload']['my_plugin_label'] );
	}

	public function testTheUndoReadsThePointAgainOnceItIsReserved(): void {
		// A replace still running under the token may append a part after the
		// first read; the restore uses what is stored once nobody can append.
		$src   = (string) file_get_contents( __DIR__ . '/../includes/tools/class-tools-undo-core.php' );
		$begin = strpos( $src, '$reserved = AB_MCP_Undo::begin_apply( $token );' );
		$again = strpos( $src, '$claim = AB_MCP_Undo::claim( $token );', (int) $begin );
		$apply = strpos( $src, 'AB_MCP_Undo_Restore::apply( $claim[\'entry\'], $claim[\'data\'], $opts )' );
		self::assertNotFalse( $begin );
		self::assertNotFalse( $again, 'The point is read again after begin_apply().' );
		self::assertGreaterThan( $again, $apply, 'And the restore uses that second read.' );
		self::assertStringContainsString( "\$opts['parts'] = isset( \$claim['parts'] )", substr( $src, (int) $again, (int) $apply - (int) $again ), 'With the parts of the second read.' );
	}

	public function testAnOptionProtectedNowIsBlockedAndTheRestKeepsAPoint(): void {
		update_option( 'blogdescription', 'new tagline' );
		update_option( 'siteurl', 'https://new.example' );
		$token = self::option_point(
			array(
				array( 'name' => 'blogdescription', 'value' => 'old tagline', 'autoload' => 'yes', 'existed' => true ),
				// A hand-edited point naming a protected row is never written back.
				array( 'name' => 'siteurl', 'value' => 'https://evil.example', 'autoload' => 'yes', 'existed' => true ),
			)
		);
		$dry = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $token, 'dry_run' => true ) );
		self::assertSame( array( 'siteurl' ), array_column( $dry['blocked'], 'ref' ) );
		$out = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $token ) );
		self::assertSame( 'old tagline', get_option( 'blogdescription' ) );
		self::assertSame( 'https://new.example', get_option( 'siteurl' ), 'The protected row stays as it is.' );
		self::assertSame( 'protected_now', $out['blocked'][0]['reason'] );
		self::assertIsString( $out['rest_token'] ?? null, 'What did not come back keeps a point of its own.' );
	}

	public function testAPointThatIsNotAListOfExistingRowsIsRefused(): void {
		foreach ( array(
			array( 'blogdescription' => 'x' ),
			array( array( 'name' => 'blogdescription', 'value' => 'x', 'existed' => false ) ),
			array( array( 'name' => 'blogdescription', 'existed' => true ) ),
			array( array( 'name' => '', 'value' => 'x', 'existed' => true ) ),
			array( array( 'name' => 'a', 'value' => 1, 'existed' => true ), array( 'name' => 'a', 'value' => 2, 'existed' => true ) ),
		) as $i => $data ) {
			$plan = AB_MCP_Undo_Restore::plan( array( 'tool' => 'wp_search_replace', 'kind' => 'option_rows' ), $data );
			self::assertInstanceOf( \WP_Error::class, $plan, (string) $i );
			self::assertSame( 'ab_mcp_undo_corrupt', $plan->get_error_code(), (string) $i );
		}
	}

	public function testTheReplaceMayRecordBothKindsButNoOther(): void {
		$p = AB_MCP_Undo_Restore::policy()['wp_search_replace'];
		self::assertSame( 'builder_data', $p['kind'] );
		self::assertSame( array( 'option_rows' ), $p['also'] );
		$plan = AB_MCP_Undo_Restore::plan( array( 'tool' => 'wp_search_replace', 'kind' => 'site_settings' ), array( 'blogname' => 'x' ) );
		self::assertSame( 'ab_mcp_undo_kind_mismatch', $plan->get_error_code() );
		$plan = AB_MCP_Undo_Restore::plan( array( 'tool' => 'wp_update_site_settings', 'kind' => 'option_rows' ), array( array( 'name' => 'blogname', 'value' => 'x', 'existed' => true ) ) );
		self::assertSame( 'ab_mcp_undo_kind_mismatch', $plan->get_error_code(), 'Another tool may not claim the kind.' );
		self::assertSame( 'site', AB_MCP_Undo_Restore::page_cache_scope()['option_rows'] );
	}

	/* ------------------------------------------------------ the option guard */

	public function testTheGuardRefusesTheRowsThatRunTheSiteAndItsSecrets(): void {
		foreach ( array( 'siteurl', 'home', 'active_plugins', 'template', 'stylesheet', 'default_role', 'users_can_register', 'cron', 'mailserver_pass', 'auth_key', 'ab_mcp_tokens', 'ab_mcp_tool_state', 'ab_mcp_options', 'ab_mcp_pro_deploy', 'stripe_secret_key', 'my_api_key', 'smtp_password', 'oauth_client', 'wp_user_roles', ' SiteURL ' ) as $name ) {
			self::assertInstanceOf( \WP_Error::class, AB_MCP_Option_Guard::option_write_refused( $name ), $name );
		}
		self::assertInstanceOf( \WP_Error::class, AB_MCP_Option_Guard::option_write_refused( str_repeat( 'a', 192 ) ), 'Longer than the column.' );
		self::assertInstanceOf( \WP_Error::class, AB_MCP_Option_Guard::option_write_refused( "site\u{200B}url" ), 'An invisible character.' );
		foreach ( array( 'blogdescription', 'blogname', 'posts_per_page', 'my_plugin_label', 'woocommerce_store_address' ) as $name ) {
			self::assertFalse( AB_MCP_Option_Guard::option_write_refused( $name ), $name );
		}
	}

	public function testTheReplaceAsksTheGuardAndHasNoListOfItsOwn(): void {
		$src = (string) file_get_contents( __DIR__ . '/../includes/tools/class-tools-search-replace.php' );
		self::assertStringContainsString( 'AB_MCP_Option_Guard::option_write_refused( $name )', $src );
		self::assertStringNotContainsString( 'AB_MCP_Tools_Options_Settings_Pro', $src, 'No fallback for a missing Pro class: the guard is part of this plugin.' );
		self::assertStringNotContainsString( "'actiue_plugins', 'actiue_sitewide_plugins'", $src, 'No list of its own beside the guard.' );
	}
}
