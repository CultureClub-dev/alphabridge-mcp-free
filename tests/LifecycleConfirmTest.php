<?php
/**
 * What the confirmation token covers for the five tools without a way back:
 * the state each fingerprint reads, so that a token from an earlier preview
 * fails once what it covers changed, and previews that say what would
 * happen and that undo does not cover it.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace {
	// Stubs of the wp-admin readers these fingerprints use, only where the
	// shared bootstrap does not bring them.
	// get_plugins() comes from the shared tests/wp-admin/includes/plugin.php
	// ($GLOBALS['ab_test_plugins']), as wp_list_plugins loads it.
	require_once __DIR__ . '/wp-admin/includes/plugin.php';
	if ( ! function_exists( 'is_plugin_active' ) ) {
		function is_plugin_active( $plugin ) {
			return in_array( $plugin, (array) get_option( 'active_plugins', array() ), true );
		}
	}
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		function is_plugin_active_for_network( $plugin ) {
			return false;
		}
	}
	if ( ! function_exists( 'wp_get_themes' ) ) {
		function wp_get_themes( $args = array() ) {
			return $GLOBALS['ab_test_lc_themes'] ?? array();
		}
	}
	if ( ! function_exists( 'get_core_updates' ) ) {
		function get_core_updates( $options = array() ) {
			return $GLOBALS['ab_test_lc_core'] ?? array();
		}
	}
	if ( ! function_exists( 'wp_version_check' ) ) {
		function wp_version_check( $extra_stats = array(), $force_check = false ) {
			$GLOBALS['ab_test_lc_checked'] = ( $GLOBALS['ab_test_lc_checked'] ?? 0 ) + 1;
		}
	}
	require_once __DIR__ . '/../includes/class-lifecycle-confirm.php';
}

namespace AlphaBridge\Tests {

	use AB_MCP_Lifecycle_Confirm;
	use PHPUnit\Framework\TestCase;

	/** A theme as wp_get_themes() returns it, reduced to get(). */
	final class LcTheme {
		/** @param array<string,string> $headers */
		public function __construct( private array $headers ) {}
		public function get( string $key ): string {
			return $this->headers[ $key ] ?? '';
		}
	}

	final class LifecycleConfirmTest extends TestCase {

		protected function setUp(): void {
			ab_test_reset();
			$GLOBALS['ab_test_plugins'] = array(
				'hello/hello.php'       => array( 'Name' => 'Hello Dolly', 'Version' => '1.7.2' ),
				'akismet/akismet.php'   => array( 'Name' => 'Akismet', 'Version' => '5.3' ),
			);
			update_option( 'active_plugins', array( 'akismet/akismet.php' ) );
			$GLOBALS['ab_test_lc_themes']  = array(
				'twentytwentyfive' => new LcTheme( array( 'Name' => 'Twenty Twenty-Five', 'Version' => '1.2' ) ),
				'astra'            => new LcTheme( array( 'Name' => 'Astra', 'Version' => '4.8' ) ),
			);
			// The shared stub of tests/menus-shop-stubs.php, when another test loaded it first.
			$GLOBALS['ab_test_themes']     = array(
				'twentytwentyfive' => array( 'Twenty Twenty-Five', '1.2' ),
				'astra'            => array( 'Astra', '4.8' ),
			);
			$GLOBALS['ab_test_template']   = 'twentytwentyfive';
			$GLOBALS['ab_test_stylesheet'] = 'twentytwentyfive';
			$GLOBALS['ab_test_lc_core']    = array();
			$GLOBALS['ab_test_lc_checked'] = 0;
		}

		/** @return array{state:callable,preview:callable} */
		private static function def( string $tool ): array {
			$def = AB_MCP_Lifecycle_Confirm::definition( $tool );
			self::assertIsCallable( $def['state'] );
			self::assertIsCallable( $def['preview'] );
			return $def;
		}

		public function testEachOfTheFiveToolsHasStateAndPreviewAndNoOtherToolHasThem(): void {
			foreach ( array( 'wp_update_core', 'wp_install_plugin_zip', 'wp_install_theme_zip', 'wp_delete_plugin', 'wp_delete_theme' ) as $tool ) {
				self::def( $tool );
			}
			foreach ( array( 'wp_install_plugin', 'wp_update_plugin', 'wp_activate_theme', 'wp_deactivate_plugin' ) as $tool ) {
				self::assertSame( array(), AB_MCP_Lifecycle_Confirm::definition( $tool ), $tool );
			}
		}

		public function testDeletingAPluginIsBoundToItsVersionAndWhetherItIsActive(): void {
			$def    = self::def( 'wp_delete_plugin' );
			$args   = array( 'plugin' => 'akismet/akismet.php' );
			$before = ( $def['state'] )( $args );

			$GLOBALS['ab_test_plugins']['akismet/akismet.php']['Version'] = '5.4';
			self::assertNotSame( $before, ( $def['state'] )( $args ), 'An update in between' );

			$GLOBALS['ab_test_plugins']['akismet/akismet.php']['Version'] = '5.3';
			update_option( 'active_plugins', array() );
			self::assertNotSame( $before, ( $def['state'] )( $args ), 'Deactivated in between' );

			unset( $GLOBALS['ab_test_plugins']['akismet/akismet.php'] );
			self::assertStringEndsWith( '|absent', ( $def['state'] )( $args ) );
		}

		public function testThePluginPreviewNamesItAndSaysThereIsNoUndo(): void {
			$p = ( self::def( 'wp_delete_plugin' )['preview'] )( array( 'plugin' => 'akismet/akismet.php' ) );

			self::assertSame( 'Akismet', $p['name'] );
			self::assertSame( '5.3', $p['version'] );
			self::assertTrue( $p['active'] );
			self::assertStringContainsString( 'Deactivates', $p['effect'] );
			self::assertStringContainsString( 'Undo does not cover', $p['no_way_back'] );

			$none = ( self::def( 'wp_delete_plugin' )['preview'] )( array( 'plugin' => 'gone/gone.php' ) );
			self::assertStringContainsString( 'wp_list_plugins', $none['effect'] );
		}

		public function testDeletingAThemeIsBoundToItsVersionAndItsRole(): void {
			$def    = self::def( 'wp_delete_theme' );
			$args   = array( 'stylesheet' => 'astra' );
			$before = ( $def['state'] )( $args );

			$GLOBALS['ab_test_stylesheet'] = 'astra';
			self::assertNotSame( $before, ( $def['state'] )( $args ), 'Activated in between' );
			self::assertSame( 'active', ( $def['preview'] )( $args )['role'] );
			self::assertStringContainsString( 'wp_activate_theme', ( $def['preview'] )( $args )['effect'] );

			$GLOBALS['ab_test_stylesheet'] = 'twentytwentyfive';
			self::assertSame( $before, ( $def['state'] )( $args ), 'Back as it was: the same state' );
		}

		public function testAZipInstallIsBoundToItsContentItsAddressAndWhatIsInstalled(): void {
			$def  = self::def( 'wp_install_plugin_zip' );
			$zip  = array( 'content' => base64_encode( 'PK fake' ) );
			$base = ( $def['state'] )( $zip );

			self::assertNotSame( $base, ( $def['state'] )( array( 'content' => base64_encode( 'PK other' ) ) ), 'Other bytes' );
			self::assertNotSame( $base, ( $def['state'] )( $zip + array( 'activate' => true ) ), 'Activate or not' );
			self::assertNotSame( $base, ( $def['state'] )( array( 'url' => 'https://example.org/x.zip' ) ), 'Address instead of content' );

			$GLOBALS['ab_test_plugins']['new/new.php'] = array( 'Name' => 'New', 'Version' => '1.0' );
			self::assertNotSame( $base, ( $def['state'] )( $zip ), 'A plugin appeared in between' );

			$theme = self::def( 'wp_install_theme_zip' );
			self::assertNotSame( ( $def['state'] )( $zip ), ( $theme['state'] )( $zip ), 'Plugin and theme never share a token' );
		}

		public function testTheZipPreviewShowsTheSourceAndThatTheAddressIsNotThePackage(): void {
			$p = ( self::def( 'wp_install_plugin_zip' )['preview'] )( array( 'content' => base64_encode( 'PK fake' ), 'activate' => true ) );
			self::assertSame( 'content', $p['source']['kind'] );
			self::assertSame( hash( 'sha256', base64_encode( 'PK fake' ) ), $p['source']['sha256'] );
			self::assertTrue( $p['activate'] );
			self::assertStringContainsString( 'wp_delete_plugin', $p['no_way_back'] );

			$u = ( self::def( 'wp_install_theme_zip' )['preview'] )( array( 'url' => 'https://example.org/t.zip' ) );
			self::assertSame( 'url', $u['source']['kind'] );
			self::assertStringContainsString( 'not what the address serves', $u['source']['note'] );
		}

		public function testTheZipSourceIsTheOneTheToolTakesASessionBeforeContentBeforeUrl(): void {
			$p = ( self::def( 'wp_install_plugin_zip' )['preview'] )( array( 'session' => 'up-7', 'content' => base64_encode( 'PK' ), 'url' => 'https://example.org/x.zip' ) );
			self::assertSame( 'session', $p['source']['kind'] );
			$c = ( self::def( 'wp_install_plugin_zip' )['preview'] )( array( 'content' => base64_encode( 'PK' ), 'url' => 'https://example.org/x.zip' ) );
			self::assertSame( 'content', $c['source']['kind'] );
		}

		public function testAnyRegistrationOfTheFiveKeepsTheConfirmationAnOlderProIncluded(): void {
			$r = new \AB_MCP_Tool_Registry();
			$r->set_current_group( 'lifecycle', 'Lifecycle' );
			foreach ( array( 'wp_update_core', 'wp_install_plugin_zip', 'wp_install_theme_zip', 'wp_delete_plugin', 'wp_delete_theme' ) as $tool ) {
				// As Pro up to 4.10 registers them: no confirm key.
				$r->register( $tool, array( 'description' => 'Pro copy.', 'callback' => static fn(): string => 'ran' ) );
				self::assertTrue( \AB_MCP_Confirm_Token::required( $r->get( $tool ) ), $tool );
				self::assertArrayHasKey( 'confirm_token', (array) $r->get( $tool )['inputSchema']['properties'], $tool );
			}
			$r->register( 'wp_install_plugin', array( 'description' => 'Pro copy.', 'callback' => static fn(): string => 'ran' ) );
			self::assertFalse( \AB_MCP_Confirm_Token::required( $r->get( 'wp_install_plugin' ) ) );
		}

		public function testTheCoreUpdateIsBoundToTheRunningVersionAndTheOfferAskedForAgain(): void {
			$def = self::def( 'wp_update_core' );

			$none = ( $def['state'] )( array() );
			self::assertSame( 1, $GLOBALS['ab_test_lc_checked'], 'The state asks wordpress.org, as the tool does' );
			self::assertStringEndsWith( '|none', $none );
			self::assertNull( ( $def['preview'] )( array() )['to'] );

			$GLOBALS['ab_test_lc_core'] = array( (object) array( 'response' => 'upgrade', 'current' => '6.9.1', 'locale' => 'de_DE' ) );
			$offer = ( $def['state'] )( array() );
			self::assertNotSame( $none, $offer );
			$p = ( $def['preview'] )( array() );
			self::assertSame( '6.9.1', $p['to'] );
			self::assertStringContainsString( 'backup', $p['no_way_back'] );

			$GLOBALS['ab_test_lc_core'][0]->current = '6.9.2';
			self::assertNotSame( $offer, ( $def['state'] )( array() ), 'A release in between makes the token fail' );
		}
	}
}
