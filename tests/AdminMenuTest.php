<?php
/**
 * The menu «AlphaBridge» (since 4.16.0), registered with WordPress' own menu
 * functions: tests/support/admin-menu-functions.php is a fixed copy of them
 * from 7.1.3, so the first entry WordPress adds under a menu of its own, the
 * hook names, the entries a user may not open and the access check are
 * WordPress' own, not a stand-in's.
 *
 * What must hold:
 *
 * - An administrator sees the menu «AlphaBridge» right below Settings with
 *   exactly two entries, «AI Connection» and «Staging»; the menu links to
 *   the first. An editor sees none of it and is refused every address of
 *   it, the old one included.
 * - The old page under Settings is registered, stays in the menu until the
 *   end of admin_menu (the SDK of a Pro add-on before 4.16.0 looks for it at
 *   999999999) and is out of it afterwards.
 * - The old address and the menu's own address lead on to «AI Connection»
 *   with every parameter, unslashed as WordPress slashes them and encoded so
 *   that wp_redirect() strips nothing: a GET with 302, a POST with 307 and
 *   nothing of the form in the address. The target is always this site's own.
 * - Where an older SDK put its licence screen in place of the old page, the
 *   entry stays and the address does not lead on.
 * - Script, stylesheet and abMcpAdmin load on both pages and nowhere else;
 *   an older Pro add-on gets its loaders called with the old hook, a newer
 *   one does not. abMcpAdmin tells admin.js the page it is on, the anchors of
 *   «Staging» (the table of settings_url()) and the address of «Staging»,
 *   for an old address whose anchor names a part of the card «Staging».
 *
 * Each test runs in a process of its own: the copied functions must not
 * reach the other tests (see the head of the copy), and each request starts
 * with an empty menu.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Admin;
use AB_MCP_Tool_Registry;
use AbTestExit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class AdminMenuTest extends TestCase {

	private const CONNECTION = 'https://example.test/wp-admin/admin.php?page=alphabridge-connection';

	protected function setUp(): void {
		require_once __DIR__ . '/support/admin-menu-functions.php';
		ab_test_reset();
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
	}

	/**
	 * One admin request up to the access check, as wp-admin/admin.php runs
	 * it: the menu of the core (Dashboard and Settings with its pages, as
	 * wp-admin/menu.php lines 405-413 and 428-439 set them), the steps of
	 * wp-admin/includes/menu.php before admin_menu, the action admin_menu
	 * with the plugin's admin on it, the steps after it.
	 *
	 * @param bool          $admin   An administrator (else an editor: edit_posts and read).
	 * @param callable|null $sdk     Hung on admin_menu at 999999999, where the SDK of the Pro add-on works.
	 */
	private function request( bool $admin, ?callable $sdk = null ): AB_MCP_Admin {
		global $menu, $submenu, $compat, $admin_page_hooks, $_registered_pages, $_parent_pages, $_wp_real_parent_file;

		ab_test_add_user( 3 );
		$GLOBALS['ab_test_current_user'] = 3;
		$GLOBALS['ab_test_can']          = $admin
			? static fn( string $cap ): bool => true
			: static fn( string $cap ): bool => in_array( $cap, array( 'read', 'edit_posts' ), true );
		// wp-includes/default-filters.php line 309, which ab_test_reset() took away.
		add_filter( 'sanitize_title', 'sanitize_title_with_dashes', 10, 3 );
		$GLOBALS['wp_plugin_paths'] = array();

		$menu                 = array();
		$submenu              = array();
		$admin_page_hooks     = array();
		$_registered_pages    = array();
		$_parent_pages        = array();
		$_wp_real_parent_file = array();
		$menu[2]                            = array( 'Dashboard', 'read', 'index.php', '', 'menu-top menu-top-first menu-icon-dashboard', 'menu-dashboard', 'dashicons-dashboard' );
		$submenu['index.php'][0]            = array( 'Home', 'read', 'index.php' );
		$menu[80]                           = array( 'Settings', 'manage_options', 'options-general.php', '', 'menu-top menu-icon-settings', 'menu-settings', 'dashicons-admin-settings' );
		$submenu['options-general.php'][10] = array( 'General', 'manage_options', 'options-general.php' );
		$submenu['options-general.php'][12] = array( 'Connectors', 'manage_options', 'options-connectors.php' );
		$submenu['options-general.php'][15] = array( 'Writing', 'manage_options', 'options-writing.php' );
		$submenu['options-general.php'][20] = array( 'Reading', 'manage_options', 'options-reading.php' );
		$submenu['options-general.php'][25] = array( 'Discussion', 'manage_options', 'options-discussion.php' );
		$submenu['options-general.php'][30] = array( 'Media', 'manage_options', 'options-media.php' );
		$submenu['options-general.php'][40] = array( 'Permalinks', 'manage_options', 'options-permalink.php' );
		$submenu['options-general.php'][45] = array( 'Privacy', 'manage_privacy_options', 'options-privacy.php' );
		$compat                             = array(
			'index'           => 'dashboard',
			'edit'            => 'posts',
			'post'            => 'posts',
			'upload'          => 'media',
			'link-manager'    => 'links',
			'edit-pages'      => 'pages',
			'page'            => 'pages',
			'edit-comments'   => 'comments',
			'options-general' => 'settings',
			'themes'          => 'appearance',
		);
		ab_test_core_menu_before_admin_menu();

		$plugin = new AB_MCP_Admin( new AB_MCP_Tool_Registry() );
		if ( null !== $sdk ) {
			add_action( 'admin_menu', $sdk, 999999999 );
		}
		do_action( 'admin_menu', '' );
		ab_test_core_menu_after_admin_menu();
		return $plugin;
	}

	/** user_can_access_admin_page() for one address, as wp-admin/includes/menu.php line 375 asks it. */
	private static function may_open( string $pagenow, string $page ): bool {
		$GLOBALS['pagenow']     = $pagenow;
		$GLOBALS['plugin_page'] = $page;
		unset( $GLOBALS['parent_file'], $GLOBALS['typenow'] );
		return user_can_access_admin_page();
	}

	/** The page hook wp-admin/admin.php lines 183-199 finds for one address, or null. */
	private static function page_hook( string $pagenow, string $page ): ?string {
		$GLOBALS['pagenow']     = $pagenow;
		$GLOBALS['plugin_page'] = $page;
		unset( $GLOBALS['parent_file'], $GLOBALS['typenow'] );
		$hook = get_plugin_page_hook( $page, $pagenow );
		if ( ! $hook ) {
			$hook = get_plugin_page_hook( $page, $page );
		}
		return $hook;
	}

	/**
	 * The request $query (as PHP parsed it from the address) to $pagenow,
	 * up to its load- hook (wp-admin/admin.php line 242), slashed as
	 * wp_magic_quotes() slashes it. The redirect it ends with, or null.
	 *
	 * @param array<string,mixed> $query
	 * @param array<string,mixed> $post
	 */
	private function load( string $pagenow, array $query, string $method = 'GET', array $post = array() ): ?AbTestExit {
		$_GET                      = add_magic_quotes( $query );
		$_POST                     = add_magic_quotes( $post );
		$_REQUEST                  = array_merge( $_GET, $_POST );
		$_SERVER['REQUEST_METHOD'] = $method;
		self::assertTrue( self::may_open( $pagenow, (string) $query['page'] ), 'The access check lets the administrator through.' );
		$hook = self::page_hook( $pagenow, (string) $query['page'] );
		self::assertNotNull( $hook, 'The page has a callback, so admin.php reaches its load- hook.' );
		try {
			do_action( 'load-' . $hook );
		} catch ( AbTestExit $exit ) {
			return $exit;
		}
		return null;
	}

	/**
	 * The Location header WordPress would send for a wp_safe_redirect() to
	 * $location (wp-includes/pluggable.php: wp_safe_redirect() sanitizes and
	 * validates, wp_redirect() sanitizes again before header()).
	 */
	private static function header_location( string $location ): string {
		$location = wp_sanitize_redirect( $location );
		$location = wp_validate_redirect( $location, 'https://example.test/wp-admin/' );
		return wp_sanitize_redirect( $location );
	}

	/** @return array<string,string|false> The hooks the plugin kept. */
	private static function hooks(): array {
		return ( new ReflectionProperty( AB_MCP_Admin::class, 'hooks' ) )->getValue();
	}

	/* ---------------------------------------------------------------- menu */

	public function testAnAdministratorSeesTheMenuWithItsTwoPagesRightBelowSettings(): void {
		$this->request( true );
		global $menu, $submenu;

		$item = null;
		foreach ( $menu as $position => $entry ) {
			if ( 'alphabridge' === $entry[2] ) {
				$item = array( $position, $entry );
			}
		}
		self::assertNotNull( $item, 'The menu is there.' );
		self::assertSame( 81, $item[0], 'Right below Settings (80).' );
		self::assertSame( 'AlphaBridge', $item[1][0] );
		self::assertSame( 'manage_options', $item[1][1] );
		self::assertStringStartsWith( 'data:image/svg+xml;base64,', $item[1][6], 'An SVG, which WordPress colours (svg-painter.js).' );
		$svg = (string) base64_decode( substr( $item[1][6], strlen( 'data:image/svg+xml;base64,' ) ), true );
		self::assertStringStartsWith( '<svg xmlns="http://www.w3.org/2000/svg"', $svg );
		self::assertStringContainsString( 'fill="', $svg, 'svg-painter.js replaces fill attributes; without one the icon stays black.' );
		$order = array_keys( $menu );
		sort( $order );
		self::assertSame( 81, $order[ array_search( 80, $order, true ) + 1 ], 'Nothing between Settings and AlphaBridge.' );

		self::assertSame(
			array(
				array( 'AI Connection', 'manage_options', 'alphabridge-connection', 'AI Connection' ),
				array( 'Staging', 'manage_options', 'alphabridge-staging', 'Staging' ),
			),
			array_values( $submenu['alphabridge'] ),
			'Exactly two entries: the one WordPress adds by itself for the menu is gone.'
		);
	}

	public function testTheMenuLinksToItsFirstPageAndEveryPageHasItsHook(): void {
		$plugin = $this->request( true );
		global $submenu;

		self::assertSame(
			array(
				'menu'       => 'toplevel_page_alphabridge',
				'connection' => 'alphabridge_page_alphabridge-connection',
				'staging'    => 'alphabridge_page_alphabridge-staging',
				'old'        => 'settings_page_alphabridge-mcp',
			),
			self::hooks()
		);
		// wp-admin/menu-header.php lines 155-170: a menu with entries links
		// to the first one, through admin.php where that one has a hook.
		$first = array_values( $submenu['alphabridge'] )[0];
		self::assertSame( 'alphabridge-connection', $first[2] );
		self::assertSame( 'alphabridge_page_alphabridge-connection', get_plugin_page_hook( $first[2], 'alphabridge' ) );

		// Each address finds its page hook with a callback (else «Cannot load»).
		self::assertSame( 'toplevel_page_alphabridge', self::page_hook( 'admin.php', 'alphabridge' ) );
		self::assertSame( 'alphabridge_page_alphabridge-connection', self::page_hook( 'admin.php', 'alphabridge-connection' ) );
		self::assertSame( 'alphabridge_page_alphabridge-staging', self::page_hook( 'admin.php', 'alphabridge-staging' ) );
		self::assertSame( 'settings_page_alphabridge-mcp', self::page_hook( 'options-general.php', 'alphabridge-mcp' ) );
		self::assertSame( 10, has_action( 'alphabridge_page_alphabridge-connection', array( $plugin, 'render' ) ) );
		self::assertSame( 10, has_action( 'alphabridge_page_alphabridge-staging', array( $plugin, 'render_staging' ) ) );
	}

	public function testTheCoreRegistersTheMenuAt10AndTakesTheOldPageAwayLast(): void {
		$plugin = new AB_MCP_Admin( new AB_MCP_Tool_Registry() );
		self::assertSame( 10, has_action( 'admin_menu', array( $plugin, 'menu' ) ), 'Before the safeguard of the Pro add-on (20) and its SDK (999999999).' );
		self::assertSame( PHP_INT_MAX, has_action( 'admin_menu', array( $plugin, 'hide_old_page' ) ) );
		self::assertSame( 'alphabridge', AB_MCP_ADMIN_MENU, 'The sign for the Pro add-on, with the menu\'s slug.' );
	}

	public function testTheOldPageStaysUntilTheSdkHasRunAndThenLeavesTheMenu(): void {
		$seen = null;
		$this->request(
			true,
			static function () use ( &$seen ) {
				$seen = in_array( 'alphabridge-mcp', array_column( $GLOBALS['submenu']['options-general.php'] ?? array(), 2 ), true );
			}
		);
		self::assertTrue( $seen, 'The SDK of an older Pro finds the old entry under Settings.' );
		self::assertNotContains( 'alphabridge-mcp', array_column( $GLOBALS['submenu']['options-general.php'], 2 ), 'Afterwards it is out of the menu.' );
		self::assertArrayHasKey( 'settings_page_alphabridge-mcp', $GLOBALS['_registered_pages'], 'And still registered.' );
	}

	public function testAnEditorSeesNoneOfItAndMayOpenNoneOfItsAddresses(): void {
		$plugin = $this->request( false );

		self::assertNotContains( 'alphabridge', array_column( $GLOBALS['menu'], 2 ), 'No menu.' );
		self::assertArrayNotHasKey( 'alphabridge', $GLOBALS['submenu'] );
		foreach ( array( array( 'options-general.php', 'alphabridge-mcp' ), array( 'admin.php', 'alphabridge' ), array( 'admin.php', 'alphabridge-connection' ), array( 'admin.php', 'alphabridge-staging' ) ) as $address ) {
			self::assertFalse( self::may_open( $address[0], $address[1] ), implode( '?page=', $address ) . ': WordPress refuses it with 403 before admin_init.' );
		}
		self::assertFalse( self::hooks()['connection'] );
		self::assertFalse( self::hooks()['staging'] );
		self::assertFalse( AB_MCP_Admin::is_own_screen( 'alphabridge_page_alphabridge-connection' ) );

		// And what leads on does nothing for an editor, should it be reached.
		$_GET = array( 'page' => 'alphabridge-mcp' );
		$plugin->forward_old_page();
		$plugin->forward_menu();
		self::assertTrue( true, 'No redirect.' );
	}

	public function testAnAdministratorMayOpenEveryAddress(): void {
		$this->request( true );
		self::assertTrue( self::may_open( 'options-general.php', 'alphabridge-mcp' ) );
		self::assertTrue( self::may_open( 'admin.php', 'alphabridge' ) );
		self::assertTrue( self::may_open( 'admin.php', 'alphabridge-connection' ) );
		self::assertTrue( self::may_open( 'admin.php', 'alphabridge-staging' ) );
	}

	/* ------------------------------------------------------------ leading on */

	/**
	 * Queries as PHP parses them from the address of the request.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function queries(): array {
		return array(
			'nothing more'             => array( 'page=alphabridge-mcp' ),
			'an apostrophe'            => array( 'page=alphabridge-mcp&q=O%27Reilly' ),
			'a backslash'              => array( 'page=alphabridge-mcp&path=C%3A%5Cdir%5Cfile&double=a%5C%5Cb' ),
			'a quote'                  => array( 'page=alphabridge-mcp&say=%22hi%22' ),
			'ampersand, hash, space'   => array( 'page=alphabridge-mcp&a=b%26c&h=x%23y&s=two%20words&plus=1%2B1' ),
			'umlauts'                  => array( 'page=alphabridge-mcp&u=%C3%BCber%20%C3%84rger&%C3%B6=%C3%9F' ),
			'nested arrays'            => array( 'page=alphabridge-mcp&tab[a][b]=O%27Reilly&tab[a][c][]=1&tab[a][c][]=2%5C3&tab[d]=x%26y' ),
			'keys with odd characters' => array( 'page=alphabridge-mcp&k%26y=v&k%27z=w&n%5B%5D=1&it%23em=2' ),
			'page given twice'         => array( 'page=elsewhere&x=1&page=alphabridge-mcp' ),
			'an address in the query'  => array( 'page=alphabridge-mcp&redirect_to=https%3A%2F%2Fevil.example%2F&_wp_http_referer=%2F%2Fevil.example' ),
		);
	}

	#[DataProvider( 'queries' )]
	public function testTheOldAddressLeadsOnWithEveryParameterAsItCame( string $raw ): void {
		$this->request( true );
		parse_str( $raw, $query );
		$exit = $this->load( 'options-general.php', $query );

		self::assertNotNull( $exit );
		self::assertSame( 'redirect', $exit->kind );
		self::assertSame( 302, $exit->status, 'A GET: 302, so no browser keeps the step for good.' );
		self::assertSame( $exit->detail, self::header_location( $exit->detail ), 'wp_redirect() strips nothing: every character of the query is encoded.' );
		self::assertStringStartsWith( self::CONNECTION, $exit->detail, 'Always this site\'s own page.' );
		$parts = parse_url( $exit->detail );
		self::assertSame( 'example.test', $parts['host'] );
		self::assertSame( '/wp-admin/admin.php', $parts['path'] );
		self::assertArrayNotHasKey( 'fragment', $parts, 'The anchor stays with the browser.' );
		parse_str( $parts['query'], $sent );
		$want         = $query;
		$want['page'] = 'alphabridge-connection';
		self::assertSame( 'alphabridge-connection', $sent['page'] );
		unset( $want['page'], $sent['page'] );
		self::assertSame( $want, $sent, 'Every other parameter as it came, unslashed.' );
		self::assertSame( 1, preg_match_all( '/(?:^|&)page=/', $parts['query'] ), 'One page.' );
	}

	public function testTheMenusOwnAddressLeadsOnToo(): void {
		$this->request( true );
		$exit = $this->load( 'admin.php', array( 'page' => 'alphabridge', 'q' => "O'Reilly" ) );

		self::assertNotNull( $exit );
		self::assertSame( 302, $exit->status );
		self::assertSame( self::CONNECTION . '&q=O%27Reilly', $exit->detail );
	}

	public function testAPostIsSentOnWith307AndNothingOfTheFormInTheAddress(): void {
		$this->request( true );
		$form = array(
			'fs_action' => 'deactivate_license',
			'_wpnonce'  => 'abc123',
			'reason'    => "O'Reilly \\ & #",
		);
		$exit = $this->load( 'options-general.php', array( 'page' => 'alphabridge-mcp', 'tab' => 'x' ), 'POST', $form );

		self::assertNotNull( $exit );
		self::assertSame( 307, $exit->status, 'The browser sends the same form again, with its method and its fields.' );
		self::assertSame( self::CONNECTION . '&tab=x', $exit->detail );
		foreach ( array( 'fs_action', 'deactivate_license', '_wpnonce', 'abc123', 'reason', 'Reilly' ) as $field ) {
			self::assertStringNotContainsString( $field, $exit->detail );
		}
		self::assertSame( add_magic_quotes( $form ), $_POST, 'The form itself is left as it was.' );
	}

	public function testTheRuleItselfTakesTheTargetOfTheCaller(): void {
		$_GET                      = add_magic_quotes(
			array(
				'page'        => 'https://evil.example/',
				'redirect_to' => 'https://evil.example/',
			)
		);
		$_SERVER['REQUEST_METHOD'] = 'GET';
		try {
			AB_MCP_Admin::forward( 'https://example.test/wp-admin/admin.php?page=alphabridge-staging' );
			self::fail( 'No redirect.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'https://example.test/wp-admin/admin.php?page=alphabridge-staging&redirect_to=https%3A%2F%2Fevil.example%2F', $exit->detail );
		}
	}

	/* -------------------------------------------- an older SDK takes it over */

	/**
	 * What the SDK of a Pro add-on before 4.16.0 does in activation mode
	 * (freemius/includes/managers/class-fs-admin-menu-manager.php,
	 * override_submenu_action(), lines 502-535 of SDK 2.13.4): it finds the
	 * old entry under Settings, takes every callback off the page hook
	 * (remove_all_actions(), here on the store of the test's filter API) and
	 * hangs the licence screen on it.
	 */
	/** The screen the SDK hangs on a page of its own; the same callback every time. */
	private static function licence(): \Closure {
		static $screen = null;
		if ( null === $screen ) {
			$screen = static function () {
			};
		}
		return $screen;
	}

	private static function licence_screen(): \Closure {
		return static function () {
			foreach ( $GLOBALS['submenu']['options-general.php'] ?? array() as $item ) {
				if ( 'alphabridge-mcp' === $item[2] ) {
					$hook = get_plugin_page_hookname( 'alphabridge-mcp', 'options-general.php' );
					unset( $GLOBALS['ab_test_filters'][ $hook ] );
					add_action( $hook, self::licence() );
					return;
				}
			}
		};
	}

	public function testWhereAnOlderSdkShowsItsLicenceScreenTheEntryStaysAndNothingLeadsOn(): void {
		$this->request( true, self::licence_screen() );

		self::assertContains( 'alphabridge-mcp', array_column( $GLOBALS['submenu']['options-general.php'], 2 ), 'The licence screen stays in the menu, under Settings.' );
		self::assertNull( $this->load( 'options-general.php', array( 'page' => 'alphabridge-mcp' ) ), 'No redirect: the licence screen shows.' );
		self::assertSame( 10, has_action( 'settings_page_alphabridge-mcp', self::licence() ) );
		self::assertSame( 1, count( $GLOBALS['ab_test_filters']['settings_page_alphabridge-mcp'] ), 'Only the licence screen hangs there.' );
		// The new menu stands beside it, unchanged.
		self::assertSame( array( 'alphabridge-connection', 'alphabridge-staging' ), array_column( $GLOBALS['submenu']['alphabridge'], 2 ) );
	}

	public function testThePagesAnOlderSdkSortsBehindTheOldEntryStay(): void {
		// With an activated licence the older SDK adds its pages under
		// Settings and sorts them behind the old entry
		// (class-freemius.php, order_sub_submenu_items()).
		$this->request(
			true,
			static function () {
				add_options_page( 'Account', 'Account', 'manage_options', 'alphabridge-mcp-account', self::licence() );
			}
		);
		self::assertNotContains( 'alphabridge-mcp', array_column( $GLOBALS['submenu']['options-general.php'], 2 ) );
		self::assertContains( 'alphabridge-mcp-account', array_column( $GLOBALS['submenu']['options-general.php'], 2 ), 'The account stays under Settings.' );
		self::assertTrue( self::may_open( 'options-general.php', 'alphabridge-mcp-account' ) );
		self::assertNotNull( $this->load( 'options-general.php', array( 'page' => 'alphabridge-mcp' ) ), 'The old page itself leads on.' );
	}

	/* --------------------------------------------------------------- assets */

	public function testScriptStylesheetAndSettingsLoadOnBothPagesOnly(): void {
		$plugin = $this->request( true );

		foreach ( array( 'index.php', 'options-general.php', 'toplevel_page_alphabridge', 'settings_page_alphabridge-mcp', 'alphabridge_page_alphabridge-elsewhere', '' ) as $hook ) {
			$plugin->enqueue_assets( $hook );
			self::assertSame( array(), $GLOBALS['ab_test_styles'], $hook );
			self::assertSame( array(), $GLOBALS['ab_test_scripts'], $hook );
		}
		foreach ( array( 'alphabridge_page_alphabridge-connection', 'alphabridge_page_alphabridge-staging' ) as $hook ) {
			$GLOBALS['ab_test_styles']    = array();
			$GLOBALS['ab_test_scripts']   = array();
			$GLOBALS['ab_test_localized'] = array();
			$plugin->enqueue_assets( $hook );
			self::assertSame( AB_MCP_URL . 'assets/admin.css', $GLOBALS['ab_test_styles']['ab-mcp-admin'] ?? null, $hook );
			self::assertSame( AB_MCP_URL . 'assets/admin.js', $GLOBALS['ab_test_scripts']['ab-mcp-admin'] ?? null, $hook );
			$cfg = $GLOBALS['ab_test_localized']['ab-mcp-admin']['abMcpAdmin'] ?? array();
			self::assertSame( 'https://example.test/wp-admin/admin-ajax.php', $cfg['ajaxUrl'] ?? null, $hook . ': the ajax address the preview protection posts to.' );
			self::assertSame( 'testnonce', $cfg['nonce'] ?? null, $hook );
		}
		self::assertTrue( AB_MCP_Admin::is_own_screen( 'alphabridge_page_alphabridge-staging' ) );
		self::assertFalse( AB_MCP_Admin::is_own_screen( 'settings_page_alphabridge-mcp' ) );
	}

	/**
	 * admin.js leads an anchor of «Staging» on «AI Connection» on to
	 * «Staging» (an address of the page before 4.16.0, whose anchor the
	 * server never sees): with exactly the table of settings_url(), the
	 * address of «Staging», and the page it is on.
	 */
	public function testThePagesTellTheScriptTheAnchorsAndTheAddressOfStaging(): void {
		$plugin = $this->request( true );

		foreach ( array(
			'alphabridge_page_alphabridge-connection' => 'connection',
			'alphabridge_page_alphabridge-staging'    => 'staging',
		) as $hook => $screen ) {
			$GLOBALS['ab_test_localized'] = array();
			$plugin->enqueue_assets( $hook );
			$cfg = $GLOBALS['ab_test_localized']['ab-mcp-admin']['abMcpAdmin'] ?? array();
			self::assertSame( $screen, $cfg['screen'] ?? null, $hook );
			self::assertSame( \AB_MCP_Guidance::STAGING_ANCHORS, $cfg['stagingAnchors'] ?? null, $hook . ': the table itself, as a list (a JSON array for the browser).' );
			self::assertTrue( array_is_list( $cfg['stagingAnchors'] ) );
			self::assertSame( 'https://example.test/wp-admin/admin.php?page=alphabridge-staging', $cfg['stagingUrl'] ?? null, $hook . ': without an anchor; the script adds the one it has.' );
		}
	}

	public function testAnOlderProGetsItsStylesheetsWithTheOldHook(): void {
		require_once __DIR__ . '/pro-assets-old-stubs.php';
		$plugin = $this->request( true );

		$plugin->enqueue_assets( 'index.php' );
		self::assertSame( array(), $GLOBALS['ab_test_old_pro_assets'], 'Not on other screens.' );
		foreach ( array( 'alphabridge_page_alphabridge-connection', 'alphabridge_page_alphabridge-staging' ) as $hook ) {
			$GLOBALS['ab_test_old_pro_assets'] = array();
			$plugin->enqueue_assets( $hook );
			self::assertSame(
				array(
					array( 'ab_mcp_pro_undo_assets', 'settings_page_alphabridge-mcp', true ),
					array( 'ab_mcp_pro_deploy_assets', 'settings_page_alphabridge-mcp', true ),
					array( 'ab_mcp_pro_abilities_assets', 'settings_page_alphabridge-mcp', true ),
					array( 'ab_mcp_pro_aff_assets', 'settings_page_alphabridge-mcp', true ),
				),
				$GLOBALS['ab_test_old_pro_assets'],
				$hook . ': each loader, with the hook it compares with, after the stylesheet it builds on.'
			);
		}
	}

	public function testANewerProIsNotCalled(): void {
		require_once __DIR__ . '/pro-assets-old-stubs.php';
		require_once __DIR__ . '/pro-core-screen-stub.php';
		$plugin = $this->request( true );
		$plugin->enqueue_assets( 'alphabridge_page_alphabridge-connection' );
		self::assertSame( array(), $GLOBALS['ab_test_old_pro_assets'] );
		self::assertArrayHasKey( 'ab-mcp-admin', $GLOBALS['ab_test_styles'] );
	}
}
