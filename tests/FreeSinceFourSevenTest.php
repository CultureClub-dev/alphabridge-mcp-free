<?php
/**
 * The 17 tools that came with AlphaBridge MCP Pro only until 4.7.0 and are
 * free since: menus, reading user accounts, the installed plugins and themes,
 * the cron events, the error log and WooCommerce read only.
 *
 * They are Pro's code, so these checks are the ones that matter when code
 * changes hands: each tool is registered here, in Pro's group, with the
 * same capability and flags, and it does what it did. The menu tools still
 * ask for the right to the one menu or item (assign_term, delete_post), and
 * write nothing when it is withheld; a link scheme WordPress does not allow
 * in menus is refused, not saved empty; values go to WordPress slashed. The
 * shop tools answer with the way to WooCommerce where it is missing, and an
 * order status the shop does not know with the ones it does. The error log
 * waits for write access like the reader of user profile fields.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Site_Mode;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Menus_Users;
use AB_MCP_Tools_Plugins_Themes_List;
use AB_MCP_Tools_Shop;
use AB_MCP_Tools_System;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/menus-shop-stubs.php';
require_once __DIR__ . '/../includes/tools/class-tools-system.php';
require_once __DIR__ . '/../includes/tools/class-tools-menus-users.php';
require_once __DIR__ . '/../includes/tools/class-tools-plugins-themes-list.php';
require_once __DIR__ . '/../includes/tools/class-tools-shop.php';

final class FreeSinceFourSevenTest extends TestCase {

	/** The tools, their group and capability, as Pro registered them. */
	private const MOVED = array(
		'wp_list_users'        => array( 'users', 'list_users' ),
		'wp_get_user'          => array( 'users', 'list_users' ),
		'wp_list_menus'        => array( 'users', 'edit_theme_options' ),
		'wp_get_menu'          => array( 'users', 'edit_theme_options' ),
		'wp_create_menu'       => array( 'users', 'edit_theme_options' ),
		'wp_add_menu_item'     => array( 'users', 'edit_theme_options' ),
		'wp_delete_menu_item'  => array( 'users', 'edit_theme_options' ),
		'wp_list_plugins'      => array( 'plugins-themes', 'activate_plugins' ),
		'wp_list_themes'       => array( 'plugins-themes', 'edit_theme_options' ),
		'wp_get_cron'          => array( 'system', 'manage_options' ),
		'wp_get_error_log'     => array( 'system', 'manage_options' ),
		'wp_wc_list_orders'    => array( 'woocommerce', 'manage_woocommerce' ),
		'wp_wc_get_order'      => array( 'woocommerce', 'manage_woocommerce' ),
		'wp_wc_list_products'  => array( 'woocommerce', 'manage_woocommerce' ),
		'wp_wc_get_product'    => array( 'woocommerce', 'manage_woocommerce' ),
		'wp_wc_list_coupons'   => array( 'woocommerce', 'manage_woocommerce' ),
		'wp_wc_list_customers' => array( 'woocommerce', 'manage_woocommerce' ),
	);

	protected function setUp(): void {
		ab_test_reset();
		$GLOBALS['ab_test_menu_writes'] = array();
		$GLOBALS['ab_test_nav_menus']   = array(
			5 => (object) array( 'term_id' => 5, 'name' => 'Main', 'slug' => 'main', 'count' => 2 ),
			6 => (object) array( 'term_id' => 6, 'name' => 'Footer', 'slug' => 'footer', 'count' => 0 ),
		);
		$GLOBALS['ab_test_nav_items']    = array(
			5 => array(
				(object) array( 'ID' => 30, 'title' => 'Home', 'url' => 'https://example.test/', 'menu_item_parent' => '0', 'menu_order' => 1 ),
				(object) array( 'ID' => 31, 'title' => 'Team', 'url' => 'https://example.test/team', 'menu_item_parent' => '30', 'menu_order' => 2 ),
			),
		);
		$GLOBALS['ab_test_nav_item_ids'] = array( 30, 31 );
		$GLOBALS['ab_test_can']          = static fn( string $cap, array $args ): bool => true;
	}

	private function registry(): AB_MCP_Tool_Registry {
		$r = new AB_MCP_Tool_Registry();
		foreach ( array( AB_MCP_Tools_Menus_Users::class => 'users', AB_MCP_Tools_Plugins_Themes_List::class => 'plugins-themes', AB_MCP_Tools_System::class => 'system', AB_MCP_Tools_Shop::class => 'woocommerce' ) as $class => $group ) {
			$r->set_current_group( $group, $group );
			$class::register( $r );
		}
		return $r;
	}

	public function testTheSeventeenAreRegisteredAsProRegisteredThem(): void {
		$r = $this->registry();
		foreach ( self::MOVED as $name => [ $group, $cap ] ) {
			$def = $r->get( $name );
			self::assertIsArray( $def, $name );
			self::assertSame( $group, $def['group'], $name );
			self::assertSame( $cap, $def['capability'], $name );
			self::assertArrayNotHasKey( 'tier', $def, $name . ' is a tool of this plugin now.' );
		}
		self::assertCount( 17, self::MOVED );
		foreach ( array( 'wp_create_menu', 'wp_add_menu_item', 'wp_delete_menu_item', 'wp_get_error_log' ) as $name ) {
			self::assertTrue( ! empty( $r->get( $name )['dangerous'] ), $name . ' keeps its flag.' );
		}
		self::assertTrue( AB_MCP_Tool_Registry::is_destructive( 'wp_add_menu_item', $r->get( 'wp_add_menu_item' ) ) );
		self::assertTrue( AB_MCP_Tool_Registry::is_open_world( 'wp_add_menu_item', $r->get( 'wp_add_menu_item' ) ) );
		self::assertTrue( AB_MCP_Tool_Registry::is_destructive( 'wp_delete_menu_item', $r->get( 'wp_delete_menu_item' ) ) );
		self::assertFalse( AB_MCP_Tool_Registry::is_destructive( 'wp_create_menu', $r->get( 'wp_create_menu' ) ) );
		foreach ( array( 'wp_list_users', 'wp_get_user', 'wp_list_menus', 'wp_get_menu', 'wp_list_plugins', 'wp_list_themes', 'wp_get_cron', 'wp_wc_list_orders', 'wp_wc_get_order', 'wp_wc_list_products', 'wp_wc_get_product', 'wp_wc_list_coupons', 'wp_wc_list_customers' ) as $name ) {
			self::assertTrue( AB_MCP_Tool_Registry::is_read_only( $name, $r->get( $name ) ), $name . ' only reads.' );
			self::assertTrue( AB_MCP_Site_Mode::runs_in_read( $name, $r->get( $name ) ), $name . ' runs with write access off.' );
		}
		self::assertFalse( AB_MCP_Site_Mode::runs_in_read( 'wp_get_error_log', $r->get( 'wp_get_error_log' ) ), 'The error log waits for write access.' );
	}

	public function testProRegisteringTheSameNameReplacesTheDefinition(): void {
		// Pro registers its copy of these names over the free ones, as it does
		// with wp_get_post: one definition per name, the last one counts.
		$r = $this->registry();
		$r->register( 'wp_list_menus', array( 'description' => 'Pro', 'capability' => 'edit_theme_options', 'tier' => 'pro', 'callback' => '__return_true' ) );
		self::assertSame( 'Pro', $r->get( 'wp_list_menus' )['description'] );
		self::assertSame( 1, count( array_filter( array_keys( $r->all() ), static fn( $n ) => 'wp_list_menus' === $n ) ) );
	}

	/* ------------------------------------------------------------- menus */

	public function testMenusAreListedAndReadWithTheirItems(): void {
		self::assertSame(
			array(
				array( 'id' => 5, 'name' => 'Main', 'slug' => 'main', 'count' => 2 ),
				array( 'id' => 6, 'name' => 'Footer', 'slug' => 'footer', 'count' => 0 ),
			),
			AB_MCP_Tools_Menus_Users::list_menus()['menus']
		);
		$menu = AB_MCP_Tools_Menus_Users::get_menu( array( 'menu' => 'main' ) );
		self::assertSame( array( 'id' => 5, 'name' => 'Main' ), $menu['menu'] );
		self::assertSame( array( 'item_id' => 31, 'title' => 'Team', 'url' => 'https://example.test/team', 'parent' => 30, 'order' => 2 ), $menu['items'][1] );
		$none = AB_MCP_Tools_Menus_Users::get_menu( array( 'menu' => 'nowhere' ) );
		self::assertInstanceOf( \WP_Error::class, $none );
		self::assertSame( 'ab_mcp_not_found', $none->get_error_code() );
		self::assertStringContainsString( 'wp_list_menus', $none->get_error_message() );
	}

	public function testANewMenuGoesToWordPressSlashed(): void {
		$res = AB_MCP_Tools_Menus_Users::create_menu( array( 'name' => 'C:\\Docs' ) );
		self::assertSame( array( 'created' => true, 'menu_id' => 77 ), $res );
		self::assertSame( array( array( 'create', 'C:\\\\Docs' ) ), $GLOBALS['ab_test_menu_writes'] );
	}

	public function testAnItemNeedsTheRightToThatOneMenu(): void {
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => ! ( 'assign_term' === $cap && 5 === (int) ( $args[0] ?? 0 ) );
		$res = AB_MCP_Tools_Menus_Users::add_menu_item( array( 'menu' => 'main', 'title' => 'Contact', 'url' => 'https://example.test/contact' ) );
		self::assertInstanceOf( \WP_Error::class, $res );
		self::assertSame( 'ab_mcp_forbidden', $res->get_error_code() );
		self::assertStringContainsString( 'cannot add items to this specific menu', $res->get_error_message() );
		self::assertStringContainsString( 'under Users', $res->get_error_message(), 'The refusal leads to the role, like every refusal for the role.' );
		self::assertSame( array(), $GLOBALS['ab_test_menu_writes'], 'Refused, nothing is written.' );

		$ok = AB_MCP_Tools_Menus_Users::add_menu_item( array( 'menu' => 'footer', 'title' => 'Contact', 'url' => 'https://example.test/contact' ) );
		self::assertSame( array( 'added' => true, 'item_id' => 900 ), $ok, 'Another menu of the same account is open.' );
	}

	public function testAnItemIsACustomLinkOrALinkToAPost(): void {
		AB_MCP_Tools_Menus_Users::add_menu_item( array( 'menu' => 'main', 'title' => 'C:\\Docs', 'url' => 'https://example.test/docs', 'parent' => 30 ) );
		AB_MCP_Tools_Menus_Users::add_menu_item( array( 'menu' => 'main', 'title' => 'About', 'object_id' => 12 ) );
		[ $custom, $post ] = $GLOBALS['ab_test_menu_writes'];
		self::assertSame( 5, $custom[1] );
		self::assertSame( 'custom', $custom[2]['menu-item-type'] );
		self::assertSame( 'https://example.test/docs', $custom[2]['menu-item-url'] );
		self::assertSame( 'C:\\\\Docs', $custom[2]['menu-item-title'], 'Slashed, as wp_insert_post() unslashes.' );
		self::assertSame( 30, $custom[2]['menu-item-parent-id'] );
		self::assertSame( 'publish', $custom[2]['menu-item-status'] );
		self::assertSame( 'post_type', $post[2]['menu-item-type'] );
		self::assertSame( 12, $post[2]['menu-item-object-id'] );
		self::assertSame( 'page', $post[2]['menu-item-object'], 'A page unless the call names the type.' );
		self::assertArrayNotHasKey( 'menu-item-url', $post[2] );
	}

	public function testALinkSchemeWordPressDoesNotAllowIsRefusedNotSavedEmpty(): void {
		foreach ( array( 'javascript:alert(1)', 'data:text/html,<b>x</b>' ) as $url ) {
			$res = AB_MCP_Tools_Menus_Users::add_menu_item( array( 'menu' => 'main', 'title' => 'X', 'url' => $url ) );
			self::assertInstanceOf( \WP_Error::class, $res, $url );
			self::assertSame( 'ab_mcp_bad_url', $res->get_error_code(), $url );
		}
		self::assertSame( array(), $GLOBALS['ab_test_menu_writes'] );
		$none = AB_MCP_Tools_Menus_Users::add_menu_item( array( 'menu' => 'nowhere', 'title' => 'X', 'url' => 'https://example.test/' ) );
		self::assertSame( 'ab_mcp_not_found', $none->get_error_code() );
	}

	public function testAnItemIsDeletedOnlyWithTheRightToIt(): void {
		ab_test_add_post( 31, array( 'post_type' => 'nav_menu_item' ) );
		ab_test_add_post( 30, array( 'post_type' => 'nav_menu_item' ) );
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => ! ( 'delete_post' === $cap && 31 === (int) ( $args[0] ?? 0 ) );
		$res = AB_MCP_Tools_Menus_Users::delete_menu_item( array( 'item_id' => 31 ) );
		self::assertSame( 'ab_mcp_forbidden', $res->get_error_code() );
		self::assertStringContainsString( 'cannot delete this specific menu item', $res->get_error_message() );
		self::assertSame( array(), $GLOBALS['ab_test_deleted'], 'Refused, nothing is deleted.' );

		self::assertSame( array( 'deleted' => true, 'item_id' => 30 ), AB_MCP_Tools_Menus_Users::delete_menu_item( array( 'item_id' => 30 ) ) );
		self::assertSame( array( array( 30, true ) ), $GLOBALS['ab_test_deleted'], 'For good: a menu item has no trash.' );

		$not_an_item = AB_MCP_Tools_Menus_Users::delete_menu_item( array( 'item_id' => 12 ) );
		self::assertSame( 'ab_mcp_delete_failed', $not_an_item->get_error_code() );
		self::assertCount( 1, $GLOBALS['ab_test_deleted'], 'An id of no menu item is never handed to wp_delete_post().' );
	}

	/* ------------------------------------------------------------- users */

	public function testUsersAreListedWithTheSearchAroundTheTerm(): void {
		$GLOBALS['ab_test_user_rows'] = array(
			(object) array( 'ID' => 2, 'user_login' => 'anna', 'user_email' => 'anna@example.test', 'display_name' => 'Anna', 'roles' => array( 'editor' ) ),
		);
		$GLOBALS['ab_test_user_more'] = 40;
		$res = AB_MCP_Tools_Menus_Users::list_users( array( 'role' => 'editor', 'search' => 'ann', 'per_page' => 500, 'page' => 2 ) );
		self::assertSame( array( array( 'id' => 2, 'login' => 'anna', 'email' => 'anna@example.test', 'name' => 'Anna', 'roles' => array( 'editor' ) ) ), $res['users'] );
		self::assertSame( 41, $res['total'], 'The matches across all pages.' );
		self::assertSame( array( 'role' => 'editor', 'search' => '*ann*', 'number' => 100, 'paged' => 2 ), $GLOBALS['ab_test_user_query'] );
	}

	public function testOneUserIsReadWithTheRegistrationInSiteTime(): void {
		$GLOBALS['ab_test_users'][7] = (object) array(
			'ID'              => 7,
			'user_login'      => 'ben',
			'user_email'      => 'ben@example.test',
			'display_name'    => 'Ben B.',
			'first_name'      => 'Ben',
			'last_name'       => 'B.',
			'roles'           => array( 'author' ),
			'user_registered' => '2026-09-21 10:00:00',
		);
		$res = AB_MCP_Tools_Menus_Users::get_user( array( 'id' => 7 ) );
		self::assertSame( 'ben', $res['login'] );
		self::assertSame( array( 'author' ), $res['roles'] );
		self::assertSame( \AB_MCP_Tools_Base::site_time( '2026-09-21 10:00:00' ), $res['registered'] );
		$none = AB_MCP_Tools_Menus_Users::get_user( array( 'id' => 8 ) );
		self::assertSame( 'ab_mcp_not_found', $none->get_error_code() );
		self::assertStringContainsString( 'wp_list_users', $none->get_error_message() );
	}

	/* --------------------------------------------------- plugins, themes */

	public function testPluginsAndThemesAreListedWithWhatIsActive(): void {
		$GLOBALS['ab_test_plugins']             = array(
			'akismet/akismet.php' => array( 'Name' => 'Akismet', 'Version' => '5.5' ),
			'hello.php'           => array( 'Name' => 'Hello Dolly', 'Version' => '1.7.2' ),
		);
		$GLOBALS['ab_test_options']['active_plugins'] = array( 'hello.php' );
		self::assertSame(
			array(
				array( 'file' => 'akismet/akismet.php', 'name' => 'Akismet', 'version' => '5.5', 'active' => false ),
				array( 'file' => 'hello.php', 'name' => 'Hello Dolly', 'version' => '1.7.2', 'active' => true ),
			),
			AB_MCP_Tools_Plugins_Themes_List::list_plugins()['plugins']
		);
		$GLOBALS['ab_test_themes']     = array( 'twentytwentysix' => array( 'Twenty Twenty-Six', '1.2' ), 'child' => array( 'Child', '1.0' ) );
		$GLOBALS['ab_test_stylesheet'] = 'child';
		$themes = AB_MCP_Tools_Plugins_Themes_List::list_themes()['themes'];
		self::assertSame( array( false, true ), array_column( $themes, 'active' ), 'Only the theme in use, not its parent.' );
		self::assertSame( 'Twenty Twenty-Six', $themes[0]['name'] );
	}

	/* ------------------------------------------------------ cron, error log */

	public function testCronEventsCarryTheirScheduleOrOnce(): void {
		$GLOBALS['ab_test_cron'] = array(
			1790000000 => array( 'wp_version_check' => array( 'k1' => array( 'schedule' => 'twicedaily' ) ) ),
			1790000600 => array( 'my_job' => array( 'k2' => array( 'schedule' => false ) ) ),
			1790000900 => array( 'old_job' => array( 'k3' => array() ) ),
		);
		$res = AB_MCP_Tools_System::cron();
		self::assertSame( 3, $res['count'] );
		self::assertSame( array( 'hook' => 'wp_version_check', 'next_run' => gmdate( 'c', 1790000000 ), 'schedule' => 'twicedaily' ), $res['events'][0] );
		self::assertFalse( $res['events'][1]['schedule'], 'A single event, as WordPress stores it and the description says.' );
		self::assertSame( 'once', $res['events'][2]['schedule'], 'An event without the key at all.' );

		$many = array();
		for ( $i = 0; $i < 230; $i++ ) {
			$many[ 1790000000 + $i ] = array( 'job' . $i => array( 'k' => array() ) );
		}
		$GLOBALS['ab_test_cron'] = $many;
		$res = AB_MCP_Tools_System::cron();
		self::assertSame( 230, $res['count'] );
		self::assertCount( 200, $res['events'] );
	}

	public function testTheErrorLogReturnsItsLastLinesOrSaysThereIsNone(): void {
		$log = WP_CONTENT_DIR . '/debug.log';
		if ( is_file( $log ) ) {
			unlink( $log );
		}
		$none = AB_MCP_Tools_System::error_log( array() );
		self::assertFalse( $none['available'] );
		self::assertStringContainsString( 'WP_DEBUG_LOG', $none['message'] );

		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0700, true );
		}
		file_put_contents( $log, "one\ntwo\nthree\nfour\n" );
		try {
			$tail = AB_MCP_Tools_System::error_log( array( 'lines' => 2 ) );
			self::assertSame( array( 'available' => true, 'lines' => 2, 'log' => "three\nfour" ), $tail );
			self::assertSame( 4, AB_MCP_Tools_System::error_log( array( 'lines' => 9999 ) )['lines'], 'Clamped to 500, the file has 4.' );
			self::assertSame( 1, AB_MCP_Tools_System::error_log( array( 'lines' => 0 ) )['lines'], 'At least one line.' );
		} finally {
			unlink( $log );
		}
	}

	/* ---------------------------------------------------------- WooCommerce */

	public function testTheShopToolsLeadToWooCommerceWhereItIsMissing(): void {
		if ( class_exists( 'WooCommerce', false ) ) {
			self::markTestSkipped( 'WooCommerce was loaded by an earlier test; this case runs first in a fresh process.' );
		}
		foreach ( array( 'list_orders', 'get_order', 'list_products', 'get_product', 'list_coupons', 'list_customers' ) as $method ) {
			$res = AB_MCP_Tools_Shop::$method( array( 'id' => 1 ) );
			self::assertInstanceOf( \WP_Error::class, $res, $method );
			self::assertSame( 'ab_mcp_no_woocommerce', $res->get_error_code(), $method );
			self::assertStringContainsString( 'Ask an administrator to activate it under Plugins', $res->get_error_message() );
		}
	}

	public function testAnOrderStatusTheShopDoesNotKnowIsRefusedWithTheOnesItDoes(): void {
		require_once __DIR__ . '/woo-stubs.php';
		$GLOBALS['ab_test_wc'] = array( 'orders' => array() );
		$res = AB_MCP_Tools_Shop::list_orders( array( 'status' => 'shipped' ) );
		self::assertSame( 'ab_mcp_bad_status', $res->get_error_code() );
		self::assertStringContainsString( 'pending, processing, on-hold, completed, cancelled, refunded, failed', $res->get_error_message() );
		self::assertArrayNotHasKey( 'order_query', $GLOBALS['ab_test_wc'], 'Nothing is asked for a status that does not exist.' );

		AB_MCP_Tools_Shop::list_orders( array( 'status' => 'completed' ) );
		self::assertSame( 'wc-completed', $GLOBALS['ab_test_wc']['order_query']['status'] );
		AB_MCP_Tools_Shop::list_orders( array( 'status' => 'Trash' ) );
		self::assertSame( 'trash', $GLOBALS['ab_test_wc']['order_query']['status'] );
		AB_MCP_Tools_Shop::list_orders( array( 'per_page' => 999 ) );
		self::assertArrayNotHasKey( 'status', $GLOBALS['ab_test_wc']['order_query'], 'No status: the registered ones.' );
		self::assertSame( 100, $GLOBALS['ab_test_wc']['order_query']['limit'] );
		self::assertSame( array( 'date' => 'DESC', 'ID' => 'DESC' ), $GLOBALS['ab_test_wc']['order_query']['orderby'] );
	}

	public function testOrdersProductsCouponsAndCustomersAreRead(): void {
		require_once __DIR__ . '/woo-stubs.php';
		$GLOBALS['ab_test_wc'] = array(
			'orders'       => array(
				5 => array( 'id' => 5, 'status' => 'processing', 'total' => '89.00', 'currency' => 'CHF', 'billing_first_name' => 'Anna', 'billing_last_name' => 'Muster', 'billing_email' => 'anna@example.test', 'date' => '2026-10-27T09:00:00+01:00', 'payment_method_title' => 'Twint', 'customer_note' => '', 'items' => array( array( 'name' => 'City bike', 'quantity' => 1, 'total' => '89.00' ) ) ),
			),
			'products'     => array(
				9 => array( 'id' => 9, 'name' => 'City bike', 'sku' => 'CB-1', 'price' => '690', 'regular_price' => '690', 'sale_price' => '', 'stock' => 12, 'stock_status' => 'instock', 'status' => 'publish' ),
			),
			'coupons'      => array(
				40 => array( 'code' => 'blackfriday', 'amount' => '15', 'discount_type' => 'percent', 'expires' => '2026-12-01T00:00:00+01:00' ),
			),
			'order_counts' => array( 2 => 3 ),
		);
		$order = AB_MCP_Tools_Shop::get_order( array( 'id' => 5 ) );
		self::assertSame( 'Anna Muster', $order['customer'] );
		self::assertSame( array( array( 'name' => 'City bike', 'quantity' => 1, 'total' => '89.00' ) ), $order['items'] );
		self::assertSame( 'Twint', $order['payment_method'] );
		self::assertSame( 'ab_mcp_not_found', AB_MCP_Tools_Shop::get_order( array( 'id' => 6 ) )->get_error_code() );
		self::assertSame( 1, AB_MCP_Tools_Shop::list_orders( array() )['pagination']['total'] );

		$product = AB_MCP_Tools_Shop::get_product( array( 'id' => 9 ) );
		self::assertSame( array( 'id' => 9, 'name' => 'City bike', 'sku' => 'CB-1', 'price' => '690', 'regular_price' => '690', 'sale_price' => '', 'stock' => 12, 'stock_status' => 'instock', 'status' => 'publish' ), $product );
		self::assertSame( 'ab_mcp_not_found', AB_MCP_Tools_Shop::get_product( array( 'id' => 10 ) )->get_error_code() );
		AB_MCP_Tools_Shop::list_products( array( 'search' => 'bike', 'status' => 'draft' ) );
		self::assertSame( 'bike', $GLOBALS['ab_test_wc']['product_query']['s'] );
		self::assertSame( 'draft', $GLOBALS['ab_test_wc']['product_query']['status'] );

		self::assertSame( array( array( 'id' => 40, 'code' => 'blackfriday', 'amount' => '15', 'discount_type' => 'percent', 'expires' => '2026-12-01' ) ), AB_MCP_Tools_Shop::list_coupons( array() )['coupons'] );
		self::assertSame( 'publish', $GLOBALS['ab_test_wc']['coupon_query']['post_status'], 'Published coupons only.' );

		$GLOBALS['ab_test_user_rows'] = array(
			(object) array( 'ID' => 2, 'user_email' => 'anna@example.test', 'first_name' => 'Anna', 'last_name' => 'Muster', 'display_name' => 'anna' ),
			(object) array( 'ID' => 3, 'user_email' => 'ben@example.test', 'first_name' => '', 'last_name' => '', 'display_name' => 'ben' ),
		);
		$customers = AB_MCP_Tools_Shop::list_customers( array( 'search' => 'example' ) );
		self::assertSame( array( 'Anna Muster', 'ben' ), array_column( $customers['customers'], 'name' ), 'The display name where first and last name are empty.' );
		self::assertSame( array( 3, 0 ), array_column( $customers['customers'], 'order_count' ) );
		self::assertSame( 'customer', $GLOBALS['ab_test_user_query']['role'] );
		self::assertSame( '*example*', $GLOBALS['ab_test_user_query']['search'] );
	}
}
