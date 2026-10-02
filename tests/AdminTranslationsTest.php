<?php
/**
 * WordPress's own admin messages in a tool's answer.
 *
 * Tools that manage plugins, themes and files call functions of
 * wp-admin/includes, whose messages («Plugin file does not exist.») are in
 * admin-<locale>.mo. WordPress loads that file only for wp-admin screens,
 * and a tool call is a REST request: the controller loads it before a tool
 * runs, so a German site passes those messages on in German.
 *
 * What must hold:
 *
 * - On a site in German with WordPress's admin translations installed, the
 *   file is loaded into the domain default before the tool runs, once per
 *   request however many tools run.
 * - Nothing is loaded in English, without the file, or for a call that is
 *   refused before its tool runs.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use AB_MCP_REST_Controller;
use AB_MCP_Tool_Registry;
use ReflectionMethod;
use WP_Error;

final class AdminTranslationsTest extends TestCase {

	/** A tool that answers with a message of wp-admin/includes, noting when it ran. */
	private static function registry(): AB_MCP_Tool_Registry {
		$r = new AB_MCP_Tool_Registry();
		$r->register(
			'wp_test_admin_message',
			array(
				'readonly' => true,
				'callback' => static function () {
					$GLOBALS['ab_test_i18n'][] = array( 'tool ran' );
					return new WP_Error( 'plugin_not_found', 'Plugin file does not exist.' );
				},
			)
		);
		$r->register(
			'wp_test_refused',
			array(
				'readonly'   => true,
				'capability' => 'activate_plugins',
				'callback'   => static function () {
					$GLOBALS['ab_test_i18n'][] = array( 'tool ran' );
					return array();
				},
			)
		);
		return $r;
	}

	private static function call( AB_MCP_Tool_Registry $r, string $name ): array {
		$m = new ReflectionMethod( AB_MCP_REST_Controller::class, 'tools_call' );
		return $m->invoke( new AB_MCP_REST_Controller( $r ), 1, array( 'name' => $name, 'arguments' => array() ) )['result'];
	}

	/** A languages folder with WordPress's admin translations for $locales. */
	private static function languages( array $files ): string {
		$dir = sys_get_temp_dir() . '/ab-mcp-admin-l10n-' . getmypid();
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir );
		}
		foreach ( $files as $file ) {
			file_put_contents( $dir . '/' . $file, '' );
		}
		register_shutdown_function(
			static function () use ( $dir ) {
				array_map( 'unlink', glob( $dir . '/*' ) );
				rmdir( $dir );
			}
		);
		return $dir;
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testOnAGermanSiteTheAdminFileIsLoadedBeforeTheToolRunsOnce(): void {
		define( 'WP_LANG_DIR', self::languages( array( 'admin-de_DE.mo', 'admin-de_CH.l10n.php' ) ) );
		$GLOBALS['ab_test_can']    = static fn(): bool => true;
		$GLOBALS['ab_test_locale'] = 'de_DE';
		$GLOBALS['ab_test_i18n']   = array();
		$r                         = self::registry();

		$answer = self::call( $r, 'wp_test_admin_message' );
		self::assertTrue( $answer['isError'] );
		self::assertSame(
			array(
				array( 'file', 'default', WP_LANG_DIR . '/admin-de_DE.mo', 'de_DE' ),
				array( 'tool ran' ),
			),
			$GLOBALS['ab_test_i18n'],
			'The admin translations are there before the tool runs.'
		);

		// A second tool in the same request: loaded already.
		self::call( $r, 'wp_test_admin_message' );
		self::assertCount( 3, $GLOBALS['ab_test_i18n'] );
		self::assertSame( array( 'tool ran' ), $GLOBALS['ab_test_i18n'][2] );

		// A language pack of WordPress 6.5 or newer may bring the .l10n.php
		// alone; load_textdomain() is still given the .mo path and reads it.
		$GLOBALS['ab_test_locale'] = 'de_CH';
		$GLOBALS['ab_test_i18n']   = array();
		self::call( $r, 'wp_test_admin_message' );
		self::assertSame( array( 'file', 'default', WP_LANG_DIR . '/admin-de_CH.mo', 'de_CH' ), $GLOBALS['ab_test_i18n'][0] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testNothingIsLoadedInEnglishWithoutTheFileOrForARefusedCall(): void {
		define( 'WP_LANG_DIR', self::languages( array( 'admin-de_DE.mo' ) ) );
		$GLOBALS['ab_test_can']  = static fn(): bool => false;
		$GLOBALS['ab_test_i18n'] = array();
		$r                       = self::registry();

		// Refused for the account's role: no tool runs, nothing is loaded.
		$GLOBALS['ab_test_locale'] = 'de_DE';
		self::assertTrue( self::call( $r, 'wp_test_refused' )['isError'] );
		self::assertSame( array(), $GLOBALS['ab_test_i18n'] );

		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		foreach ( array( 'en_US', 'fr_FR' ) as $locale ) {
			$GLOBALS['ab_test_locale'] = $locale;
			self::call( $r, 'wp_test_admin_message' );
		}
		self::assertSame( array( array( 'tool ran' ), array( 'tool ran' ) ), $GLOBALS['ab_test_i18n'], 'English needs no file, and none is there for fr_FR.' );
	}
}
