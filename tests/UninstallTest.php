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
 * The scan sees class constants whose value is an ab_mcp_ name; an option
 * name written inline or built at runtime is not seen, so option names live
 * in constants.
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

require_once __DIR__ . '/uninstall-stubs.php';

final class UninstallTest extends TestCase {

	private const PRO_FILE = '/alphabridge-mcp-pro/alphabridge-mcp-pro.php';

	/**
	 * Constants with an ab_mcp_ name that is no option: the WP-Cron event of
	 * the update tools (AB_MCP_Update_Log::RESUME, free since 4.11.0), which
	 * the uninstall clears with wp_clear_scheduled_hook(), and the cookie and
	 * form field of the preview protection.
	 */
	private const NOT_OPTIONS = array(
		'ab_mcp_pro_resume_auto_updates',
		// The cookie and the form field of the preview protection
		// (AB_MCP_Preview_Protection::COOKIE and FIELD, free since 4.15.0);
		// its state lives inside ab_mcp_options.
		'ab_mcp_preview',
		'ab_mcp_preview_password',
		// The filter over the tools «Staging first» never holds (AB_MCP_Staging_Gate::FILTER, free since 4.15.0).
		'ab_mcp_staging_gate_exempt',
		// The user meta of a folded card «Staging» (AB_MCP_Admin::STAGING_CARD_FOLDED,
		// free since 4.15.0), removed from every user (testTheFoldOfTheStagingCardGoesFromEveryUser).
		'ab_mcp_staging_card_folded',
	);

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
		foreach ( array_merge( glob( $root . '/includes/*.php' ), glob( $root . '/includes/tools/*.php' ), glob( $root . '/includes/undo/*.php' ), glob( $root . '/includes/lifecycle/*.php' ) ) as $file ) {
			// The upgraders extend WordPress's, loaded only with them; they
			// declare no option.
			if ( 'class-update-upgraders.php' === basename( $file ) ) {
				continue;
			}
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
				if ( is_string( $value ) && preg_match( '/^ab_mcp_[a-z_]+\z/', $value ) && ! in_array( $value, self::NOT_OPTIONS, true ) ) {
					$names[] = $value;
				}
			}
		}
		sort( $names );
		return array_values( array_unique( $names ) );
	}

	/** Whether the row exists at all — get_option() would also say false for a row set to false. */
	private static function stored( string $name ): bool {
		return array_key_exists( $name, $GLOBALS['ab_test_options'] );
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
			self::assertFalse( self::stored( $name ), $name . ' is still in the database after uninstall' );
		}
		self::assertSame( 'Not ours', get_option( 'blogname' ), 'only the plugin\'s own rows go' );
	}

	public function testTheUndoSnapshotsGoWithTheRest(): void {
		// Free since 4.9.0: copies of posts and settings, in the uploads.
		$base = sys_get_temp_dir() . '/ab-mcp-uninstall-uploads-' . getmypid();
		$dir  = $base . '/ab-mcp-undo';
		$GLOBALS['ab_test_upload_basedir'] = $base;
		try {
			foreach ( array( false, true ) as $pro ) {
				if ( ! is_dir( $dir ) ) {
					mkdir( $dir, 0777, true );
				}
				file_put_contents( $dir . '/index.php', '<?php' );
				file_put_contents( $dir . '/.htaccess', 'Require all denied' );
				file_put_contents( $dir . '/' . str_repeat( 'a', 40 ) . '.bin', 'snapshot' );
				file_put_contents( $base . '/photo.jpg', 'not ours' );
				if ( $pro ) {
					mkdir( dirname( WP_PLUGIN_DIR . self::PRO_FILE ), 0777, true );
					file_put_contents( WP_PLUGIN_DIR . self::PRO_FILE, '<?php' );
				}

				$this->runUninstall();

				if ( $pro ) {
					self::assertFileExists( $dir . '/' . str_repeat( 'a', 40 ) . '.bin', 'Pro shares the store and keeps it.' );
				} else {
					self::assertDirectoryDoesNotExist( $dir, 'The folder goes with its snapshots, index.php and .htaccess.' );
				}
				self::assertFileExists( $base . '/photo.jpg', 'Only the undo folder goes.' );
				$this->removeProFile();
			}
		} finally {
			unset( $GLOBALS['ab_test_upload_basedir'] );
			foreach ( (array) glob( $dir . '/{,.}*', GLOB_BRACE ) as $f ) {
				if ( is_file( $f ) ) {
					unlink( $f );
				}
			}
			if ( is_dir( $dir ) ) {
				rmdir( $dir );
			}
			if ( is_file( $base . '/photo.jpg' ) ) {
				unlink( $base . '/photo.jpg' );
			}
			if ( is_dir( $base ) ) {
				rmdir( $base );
			}
		}
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

		self::assertFalse( self::stored( AB_MCP_OAuth::OPT_CLIENTS ) );
	}

	public function testTheFoldOfTheStagingCardGoesFromEveryUser(): void {
		update_user_meta( 7, \AB_MCP_Admin::STAGING_CARD_FOLDED, '1' );
		update_user_meta( 9, \AB_MCP_Admin::STAGING_CARD_FOLDED, '1' );
		update_user_meta( 9, 'nickname', 'Not ours' );

		$this->runUninstall();

		self::assertSame( '', get_user_meta( 7, \AB_MCP_Admin::STAGING_CARD_FOLDED, true ) );
		self::assertSame( '', get_user_meta( 9, \AB_MCP_Admin::STAGING_CARD_FOLDED, true ) );
		self::assertSame( 'Not ours', get_user_meta( 9, 'nickname', true ), 'only the plugin\'s own key goes' );
	}

	public function testEverySiteOfANetworkIsCleanedNotOnlyTheFirstHundred(): void {
		// get_sites() answers 100 sites unless told otherwise; the loop in
		// uninstall.php stopped there and left tokens and app lists on the rest.
		$GLOBALS['ab_test_multisite'] = true;
		$GLOBALS['ab_test_sites']     = range( 1, 101 );
		$declared                     = self::declaredOptions();
		foreach ( $GLOBALS['ab_test_sites'] as $site ) {
			switch_to_blog( $site );
			foreach ( $declared as $name ) {
				update_option( $name, 'site ' . $site );
			}
			update_option( 'blogname', 'Site ' . $site );
			restore_current_blog();
		}

		$this->runUninstall();

		self::assertSame( 1, $GLOBALS['ab_test_blog'], 'uninstall ends on the site it started on' );
		foreach ( $GLOBALS['ab_test_sites'] as $site ) {
			switch_to_blog( $site );
			foreach ( $declared as $name ) {
				self::assertFalse( self::stored( $name ), $name . ' is still stored on site ' . $site );
			}
			self::assertSame( 'Site ' . $site, get_option( 'blogname' ), 'only the plugin\'s own rows go, on site ' . $site );
			restore_current_blog();
		}
	}

	public function testTheStateOfTheUpdateToolsGoesOnEverySiteAndTheNetwork(): void {
		// Free since 4.11.0: the update log, the holds, the note on the
		// update lock and the replaced folders are network options on a
		// network, the lock of code changes an option of the main site, and
		// the WP-Cron event that switches automatic updates on again is
		// planned per site. The names are Pro's, as both share them.
		$GLOBALS['ab_test_multisite']    = true;
		$GLOBALS['ab_test_sites']        = array( 1, 2 );
		$GLOBALS['ab_test_cleared_hooks'] = array();
		$network = array( \AB_MCP_Update_Log::OPTION, \AB_MCP_Update_Log::HOLDS, \AB_MCP_Update_Log::RUNNING, \AB_MCP_Update_Log::REPLACED );
		foreach ( $network as $name ) {
			$GLOBALS['ab_test_site_options'][ $name ] = array( 'from' => 'this test' );
		}
		$GLOBALS['ab_test_site_options']['auto_update_plugins'] = array( 'akismet/akismet.php' );
		update_option( \AB_MCP_Change_Lock::OPTION, 1790000000 );

		$this->runUninstall();

		foreach ( $network as $name ) {
			self::assertArrayNotHasKey( $name, $GLOBALS['ab_test_site_options'], $name );
		}
		self::assertSame( array( 'akismet/akismet.php' ), $GLOBALS['ab_test_site_options']['auto_update_plugins'], 'WordPress\'s own list stays.' );
		self::assertFalse( self::stored( \AB_MCP_Change_Lock::OPTION ) );
		self::assertSame( array( array( \AB_MCP_Update_Log::RESUME, 1 ), array( \AB_MCP_Update_Log::RESUME, 1 ), array( \AB_MCP_Update_Log::RESUME, 2 ) ), $GLOBALS['ab_test_cleared_hooks'], 'The event goes on every site.' );
	}

	public function testAutomaticUpdatesAlphaBridgeSwitchedOffGoBackOnBeforeTheHoldsGo(): void {
		// A failed update on a network where AlphaBridge does not run on the
		// main site takes the package off WordPress's list (auto_off); the
		// hold and the event RESUME would switch it on again. Uninstalled,
		// both are gone, so the uninstall switches it on (Codex, 30.10.2026).
		// A package someone had switched off (no auto_off) stays off.
		$GLOBALS['ab_test_site_options'][ \AB_MCP_Update_Log::HOLDS ] = array(
			'plugin:bad/bad.php'      => array( 'version' => '2.0', 'auto_off' => true ),
			'plugin:manual/manual.php' => array( 'version' => '3.0', 'auto_off' => false ),
			'theme:shaky'             => array( 'version' => '1.1', 'auto_off' => true ),
			'theme:listed'            => array( 'version' => '1.0', 'auto_off' => true ),
			'nonsense'                => array( 'auto_off' => true ),
		);
		$GLOBALS['ab_test_site_options']['auto_update_plugins'] = array( 'akismet/akismet.php' );
		$GLOBALS['ab_test_site_options']['auto_update_themes']  = array( 'listed' );

		$this->runUninstall();

		self::assertSame( array( 'akismet/akismet.php', 'bad/bad.php' ), $GLOBALS['ab_test_site_options']['auto_update_plugins'] );
		self::assertSame( array( 'listed', 'shaky' ), $GLOBALS['ab_test_site_options']['auto_update_themes'], 'Once on the list, not twice.' );
		self::assertArrayNotHasKey( \AB_MCP_Update_Log::HOLDS, $GLOBALS['ab_test_site_options'] );
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
