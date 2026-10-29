<?php
/**
 * The six tools that came with AlphaBridge MCP Pro only until 4.9.0 and are
 * free since: creating, changing and deleting user accounts, setting an
 * order's status, changing a product and creating a coupon.
 *
 * They are Pro's code (4.8.11), so these checks are the ones that matter when
 * code changes hands: each tool is registered here, in Pro's group, with
 * Pro's capability and flags, and it does what it did. The checks are those
 * of Pro's test scripts for these tools (user-role-lock-test.php,
 * user-role-empty-test.php, object-rights-test.php R3/R4, wc-input-test.php),
 * on the stage of tests/users-stubs.php and tests/woo-write-stubs.php:
 *
 *  - the last administrator is neither demoted nor deleted, also when two
 *    calls run at the same time (the lock, L1–L21 of Pro's test);
 *  - a role is given only where the account may give it: an unknown role, a
 *    role editable_roles keeps out of reach, the administrator role from
 *    anyone but an administrator (on multisite a super admin), a role with
 *    capabilities the account lacks;
 *  - the right to the one user, order or product is asked before anything is
 *    written (edit_user, promote_user, delete_user, edit_shop_order,
 *    edit_product);
 *  - an empty role leaves the role alone;
 *  - an order status the shop does not know is refused with the ones it
 *    knows, prices, statuses, codes and dates are checked before anything is
 *    written;
 *  - without WooCommerce the shop tools answer with the way to it.
 *
 * WooCommerce cannot be unloaded, and FreeSinceFourSevenTest checks the
 * answer without it in the main process; so every test that loads
 * WooCommerce here runs in a process of its own. Two checks need a
 * WordPress the bootstrap does not give (the post types of WooCommerce, a
 * wp_unslash() that strips): tests/shop-probe.php runs them without the
 * bootstrap, and the last test reads its answer.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Guidance;
use AB_MCP_Site_Mode;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Menus_Users;
use AB_MCP_Tools_Shop;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

require_once __DIR__ . '/users-stubs.php';
require_once __DIR__ . '/../includes/tools/class-tools-menus-users.php';
require_once __DIR__ . '/../includes/tools/class-tools-shop.php';

final class FreeSinceFourNineUsersShopTest extends TestCase {

	/**
	 * The tools as Pro 4.8.11 registers them: group, capability, open world,
	 * destructive, required arguments, all arguments.
	 */
	private const MOVED = array(
		'wp_create_user'            => array( 'users', 'create_users', false, false, array( 'username', 'email' ), array( 'username', 'email', 'password', 'role', 'first_name', 'last_name' ) ),
		'wp_update_user'            => array( 'users', 'edit_users', true, true, array( 'id' ), array( 'id', 'email', 'role', 'first_name', 'last_name', 'password' ) ),
		'wp_delete_user'            => array( 'users', 'delete_users', false, true, array( 'id' ), array( 'id', 'reassign_to' ) ),
		'wp_wc_update_order_status' => array( 'woocommerce', 'manage_woocommerce', true, true, array( 'id', 'status' ), array( 'id', 'status', 'note' ) ),
		'wp_wc_update_product'      => array( 'woocommerce', 'manage_woocommerce', true, true, array( 'id' ), array( 'id', 'regular_price', 'sale_price', 'stock', 'status' ) ),
		'wp_wc_create_coupon'       => array( 'woocommerce', 'manage_woocommerce', false, false, array( 'code', 'amount' ), array( 'code', 'amount', 'discount_type', 'expires' ) ),
	);

	/** The roles of the site: slug => capabilities. */
	private const ROLES = array(
		'administrator'  => array(
			'manage_options' => true,
			'edit_users'     => true,
			'promote_users'  => true,
			'edit_posts'     => true,
			'read'           => true,
		),
		'editor'         => array(
			'edit_posts'        => true,
			'edit_others_posts' => true,
			'publish_posts'     => true,
			'read'              => true,
		),
		'author'         => array(
			'edit_posts'    => true,
			'publish_posts' => true,
			'read'          => true,
		),
		'subscriber'     => array( 'read' => true ),
		'network_helper' => array(
			'read'                 => true,
			'manage_network_users' => true,
		),
	);

	/** What an administrator of a single site may, the per-user meta capabilities included. */
	private const ADMIN = array( 'manage_options', 'create_users', 'edit_users', 'edit_user', 'promote_users', 'promote_user', 'delete_users', 'delete_user' );

	protected function setUp(): void {
		ab_test_reset();
		$GLOBALS['ab_test_roles'] = self::ROLES;
		ab_test_user_site( array( 1 => array( 'administrator' ), 2 => array( 'administrator' ) ) );
		$GLOBALS['wpdb'] = new \AB_Test_Lock_Wpdb();
		self::allow( self::ADMIN );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		( new ReflectionProperty( AB_MCP_Tools_Menus_Users::class, 'role_lock_held' ) )->setValue( null, false );
	}

	/** The caller may what $caps lists; "cap:id" in $deny is refused for that one object. */
	private static function allow( array $caps, array $deny = array() ): void {
		$GLOBALS['ab_test_can'] = static function ( string $cap, array $args ) use ( $caps, $deny ): bool {
			if ( $args && in_array( $cap . ':' . (int) $args[0], $deny, true ) ) {
				return false;
			}
			return in_array( $cap, $caps, true );
		};
	}

	private static function code( $res ): string {
		return $res instanceof \WP_Error ? (string) $res->get_error_code() : 'ok';
	}

	private static function demote( int $id ) {
		return AB_MCP_Tools_Menus_Users::update_user( array( 'id' => $id, 'role' => 'editor' ) );
	}

	private static function remove( int $id ) {
		return AB_MCP_Tools_Menus_Users::delete_user( array( 'id' => $id ) );
	}

	private function registry(): AB_MCP_Tool_Registry {
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'users', 'Users & Menus' );
		AB_MCP_Tools_Menus_Users::register( $r );
		$r->set_current_group( 'woocommerce', 'WooCommerce' );
		AB_MCP_Tools_Shop::register( $r );
		return $r;
	}

	/* ------------------------------------------------------ registration */

	public function testTheSixAreRegisteredAsProRegisteredThem(): void {
		$r = $this->registry();
		self::assertCount( 6, self::MOVED );
		foreach ( self::MOVED as $name => [ $group, $cap, $open, $destructive, $required, $props ] ) {
			$def = $r->get( $name );
			self::assertIsArray( $def, $name );
			self::assertSame( $group, $def['group'], $name );
			self::assertSame( $cap, $def['capability'], $name );
			self::assertArrayNotHasKey( 'tier', $def, $name . ' is a tool of this plugin now.' );
			self::assertTrue( ! empty( $def['dangerous'] ), $name . ' keeps its flag.' );
			self::assertSame( $open, AB_MCP_Tool_Registry::is_open_world( $name, $def ), $name . ': open world as in Pro.' );
			self::assertSame( $destructive, AB_MCP_Tool_Registry::is_destructive( $name, $def ), $name . ': destructive as in Pro.' );
			self::assertFalse( AB_MCP_Tool_Registry::is_read_only( $name, $def ), $name );
			self::assertFalse( AB_MCP_Site_Mode::runs_in_read( $name, $def ), $name . ' waits for write access.' );
			self::assertFalse( AB_MCP_Tool_Registry::scope_allows( 'read', $name, $def ), $name );
			self::assertSame( $required, $def['inputSchema']['required'], $name );
			self::assertSame( $props, array_keys( $def['inputSchema']['properties'] ), $name );
			self::assertIsCallable( $def['callback'], $name );
			self::assertSame( '', AB_MCP_Guidance::edition_of( $name ), $name . ' is no longer answered as a tool of Pro.' );
		}
		self::assertNull( AB_MCP_Guidance::unavailable_tool( 'wp_wc_create_coupon' ) );
	}

	public function testProRegisteringTheSameNameReplacesTheDefinition(): void {
		// Pro registers its copy of these names over the free ones, as it does
		// with wp_get_post: one definition per name, the last one counts.
		$r = $this->registry();
		$r->register( 'wp_update_user', array( 'description' => 'Pro', 'capability' => 'edit_users', 'tier' => 'pro', 'callback' => '__return_true' ) );
		self::assertSame( 'Pro', $r->get( 'wp_update_user' )['description'] );
		self::assertSame( 1, count( array_filter( array_keys( $r->all() ), static fn( $n ) => 'wp_update_user' === $n ) ) );
	}

	/* ------------------------------------------------- the last administrator */

	public function testTheLastAdministratorIsNeitherDemotedNorDeleted(): void {
		ab_test_user_site( array( 1 => array( 'administrator' ), 2 => array( 'editor' ) ) );
		$res = self::demote( 1 );
		self::assertSame( 'ab_mcp_last_admin', self::code( $res ) );
		self::assertStringContainsString( 'wp_update_user', $res->get_error_message(), 'The way: make another account administrator.' );
		self::assertSame( array(), $GLOBALS['ab_test_user_updates'], 'Nothing is written.' );
		self::assertSame( array( 'administrator' ), $GLOBALS['ab_test_user_db'][1] );

		$res = self::remove( 1 );
		self::assertSame( 'ab_mcp_last_admin', self::code( $res ) );
		self::assertSame( array(), $GLOBALS['ab_test_deleted_users'] );
		self::assertSame( 'not called', $GLOBALS['ab_test_reassigned'], 'wp_delete_user() is never reached.' );
		self::assertSame( array(), $GLOBALS['ab_test_locks'], 'And the lock is released.' );

		// With a second administrator both go through.
		ab_test_user_site( array( 1 => array( 'administrator' ), 2 => array( 'administrator' ) ) );
		self::assertSame( array( 'updated' => true, 'id' => 1 ), self::demote( 1 ) );
		self::assertSame( array( 'editor' ), $GLOBALS['ab_test_user_db'][1] );
		ab_test_user_site( array( 1 => array( 'administrator' ), 2 => array( 'administrator' ) ) );
		self::assertSame( array( 'deleted' => true, 'id' => 1 ), self::remove( 1 ) );
		self::assertArrayNotHasKey( 1, $GLOBALS['ab_test_user_db'] );
		// Keeping the administrator role is no demotion.
		ab_test_user_site( array( 1 => array( 'administrator' ) ) );
		self::assertSame( 'ok', self::code( AB_MCP_Tools_Menus_Users::update_user( array( 'id' => 1, 'role' => 'administrator' ) ) ) );
	}

	/* ---------------------------------------------- the role one may give */

	public function testARoleWithMoreRightsThanTheCallersOwnIsRefused(): void {
		// An account that may manage users but is no administrator, with the
		// capabilities of an author.
		ab_test_user_site( array( 1 => array( 'administrator' ), 7 => array( 'subscriber' ) ), array( 'read', 'edit_posts', 'publish_posts' ) );
		self::allow( array( 'create_users', 'edit_users', 'edit_user', 'promote_users', 'promote_user' ) );

		$res = AB_MCP_Tools_Menus_Users::update_user( array( 'id' => 7, 'role' => 'editor' ) );
		self::assertSame( 'ab_mcp_forbidden_role', self::code( $res ) );
		self::assertStringContainsString( 'more capabilities than your own', $res->get_error_message() );
		self::assertStringContainsString( 'under Users', $res->get_error_message(), 'The refusal leads to the role, like every refusal for the role.' );
		$res = AB_MCP_Tools_Menus_Users::create_user( array( 'username' => 'neu', 'email' => 'neu@example.test', 'role' => 'editor' ) );
		self::assertSame( 'ab_mcp_forbidden_role', self::code( $res ) );
		$res = AB_MCP_Tools_Menus_Users::update_user( array( 'id' => 7, 'role' => 'administrator' ) );
		self::assertSame( 'ab_mcp_forbidden_role', self::code( $res ) );
		self::assertStringContainsString( 'not allowed to assign the administrator role', $res->get_error_message() );
		self::assertSame( array(), $GLOBALS['ab_test_user_updates'], 'Refused, nothing is written.' );
		self::assertSame( array(), $GLOBALS['ab_test_user_inserts'] );
		self::assertSame( array( 'subscriber' ), $GLOBALS['ab_test_user_db'][7] );

		// A role within the account's own capabilities is given.
		self::assertSame( 'ok', self::code( AB_MCP_Tools_Menus_Users::update_user( array( 'id' => 7, 'role' => 'author' ) ) ) );
		self::assertSame( array( 'author' ), $GLOBALS['ab_test_user_db'][7] );
	}

	public function testOnMultisiteOnlyASuperAdminGivesTheAdministratorRole(): void {
		$GLOBALS['ab_test_multisite'] = true;
		ab_test_user_site( array( 1 => array( 'administrator' ), 7 => array( 'subscriber' ) ), array( 'read', 'edit_posts', 'manage_options' ) );
		$res = AB_MCP_Tools_Menus_Users::update_user( array( 'id' => 7, 'role' => 'administrator' ) );
		self::assertSame( 'ab_mcp_forbidden_role', self::code( $res ) );
		self::assertStringContainsString( 'Only a network super admin may assign the administrator role on multisite.', $res->get_error_message() );
		self::assertStringContainsString( 'Network Admin → Users', $res->get_error_message() );
		self::assertStringContainsString( 'Direct link: https://example.test/wp-admin/network/users.php', $res->get_error_message() );
		// manage_options of one site is no full trust on a network: the role
		// may hold no capability the account lacks.
		$res = AB_MCP_Tools_Menus_Users::update_user( array( 'id' => 7, 'role' => 'network_helper' ) );
		self::assertSame( 'ab_mcp_forbidden_role', self::code( $res ) );
		self::assertSame( array(), $GLOBALS['ab_test_user_updates'] );

		$GLOBALS['ab_test_super_admins'] = array( 90 );
		self::assertSame( 'ok', self::code( AB_MCP_Tools_Menus_Users::update_user( array( 'id' => 7, 'role' => 'administrator' ) ) ) );
		self::assertSame( array( 'administrator' ), $GLOBALS['ab_test_user_db'][7] );
	}

	public function testAnUnknownRoleOrOneOutOfReachIsRefused(): void {
		ab_test_user_site( array( 1 => array( 'administrator' ), 7 => array( 'subscriber' ) ) );
		$res = AB_MCP_Tools_Menus_Users::update_user( array( 'id' => 7, 'role' => 'boss' ) );
		self::assertSame( 'ab_mcp_bad_role', self::code( $res ) );
		self::assertStringContainsString( 'administrator, editor, author, contributor and subscriber', $res->get_error_message() );

		// editable_roles keeps author out of reach.
		$GLOBALS['ab_test_editable'] = array( 'editor' => array( 'name' => 'Editor' ) );
		self::assertSame( 'ab_mcp_forbidden_role', self::code( AB_MCP_Tools_Menus_Users::update_user( array( 'id' => 7, 'role' => 'author' ) ) ) );
		// E6: filtered to nothing, nothing is assignable (an empty list used to skip the check).
		$GLOBALS['ab_test_editable'] = array();
		self::assertSame( 'ab_mcp_forbidden_role', self::code( AB_MCP_Tools_Menus_Users::update_user( array( 'id' => 7, 'role' => 'author' ) ) ) );
		self::assertSame( 'ab_mcp_forbidden_role', self::code( AB_MCP_Tools_Menus_Users::create_user( array( 'username' => 'neu', 'email' => 'neu@example.test', 'role' => 'editor' ) ) ) );
		self::assertSame( array(), $GLOBALS['ab_test_user_updates'] );
		self::assertSame( array(), $GLOBALS['ab_test_user_inserts'] );
		self::assertSame( array( 'subscriber' ), $GLOBALS['ab_test_user_db'][7] );
	}

	/* ----------------------------------------------- the right to the one user */

	public function testTheRightToTheOneUserIsAskedBeforeAnythingIsWritten(): void {
		ab_test_user_site( array( 1 => array( 'administrator' ), 7 => array( 'editor' ) ) );
		self::allow( self::ADMIN, array( 'edit_user:7', 'delete_user:7' ) );
		$res = AB_MCP_Tools_Menus_Users::update_user( array( 'id' => 7, 'first_name' => 'Eva' ) );
		self::assertSame( 'ab_mcp_forbidden', self::code( $res ) );
		self::assertStringContainsString( 'not allowed to edit this user', $res->get_error_message() );
		self::assertStringContainsString( 'under Users', $res->get_error_message() );
		$res = self::remove( 7 );
		self::assertSame( 'ab_mcp_forbidden', self::code( $res ) );
		self::assertStringContainsString( 'not allowed to delete this user', $res->get_error_message() );
		self::assertSame( array(), $GLOBALS['ab_test_user_updates'], 'Refused, nothing is written.' );
		self::assertSame( array(), $GLOBALS['ab_test_deleted_users'] );
		self::assertSame( 0, $GLOBALS['ab_test_lock_seen'], 'Asked before the lock.' );

		// E7: promote_users without promote_user for this user.
		self::allow( self::ADMIN, array( 'promote_user:7' ) );
		self::assertSame( 'ab_mcp_forbidden', self::code( AB_MCP_Tools_Menus_Users::update_user( array( 'id' => 7, 'role' => 'author' ) ) ) );
		self::assertSame( array( 'editor' ), $GLOBALS['ab_test_user_db'][7] );
		self::assertSame( array(), $GLOBALS['ab_test_user_updates'] );
		// A change without a role does not ask for it.
		self::assertSame( 'ok', self::code( AB_MCP_Tools_Menus_Users::update_user( array( 'id' => 7, 'first_name' => 'Eva' ) ) ) );
		self::assertSame( 'Eva', $GLOBALS['ab_test_user_updates'][0]['first_name'] );
		// E3: a named role without promote_users.
		self::allow( array_values( array_diff( self::ADMIN, array( 'promote_users' ) ) ) );
		self::assertSame( 'ab_mcp_forbidden', self::code( AB_MCP_Tools_Menus_Users::update_user( array( 'id' => 7, 'role' => 'author' ) ) ) );
		self::assertSame( array( 'editor' ), $GLOBALS['ab_test_user_db'][7] );

		self::assertSame( 'ab_mcp_not_found', self::code( AB_MCP_Tools_Menus_Users::update_user( array( 'id' => 8, 'first_name' => 'X' ) ) ) );
		self::assertSame( 'ab_mcp_not_found', self::code( self::remove( 8 ) ) );
	}

	/* -------------------------------------------------------- creating */

	public function testCreatingAUser(): void {
		// The caller can read, as every account can; a subscriber holds no more.
		ab_test_user_site( array( 1 => array( 'administrator' ) ), array( 'read' ) );
		$res = AB_MCP_Tools_Menus_Users::create_user( array( 'username' => 'neu', 'email' => 'not-an-address' ) );
		self::assertSame( 'ab_mcp_bad_email', self::code( $res ) );
		self::assertSame( array(), $GLOBALS['ab_test_user_inserts'] );

		// A role needs promote_users; without one the user is a subscriber.
		self::allow( array( 'create_users' ) );
		$res = AB_MCP_Tools_Menus_Users::create_user( array( 'username' => 'neu', 'email' => 'neu@example.test', 'role' => 'editor' ) );
		self::assertSame( 'ab_mcp_forbidden', self::code( $res ) );
		self::assertStringContainsString( 'requires the promote_users capability', $res->get_error_message() );
		self::assertSame( array(), $GLOBALS['ab_test_user_inserts'] );
		$res = AB_MCP_Tools_Menus_Users::create_user( array( 'username' => '<b>anna</b>!', 'email' => 'anna@example.test', 'first_name' => 'Anna' ) );
		self::assertSame( array( 'created' => true, 'id' => 50, 'generated_password' => 'Gen3rated!Gen3rated!' ), $res, 'A 20-character password, made up and shown once.' );
		self::assertSame( 'anna', $GLOBALS['ab_test_user_inserts'][0]['user_login'], 'WordPress strips what it does not allow in a username.' );
		self::assertSame( 'subscriber', $GLOBALS['ab_test_user_inserts'][0]['role'] );
		self::assertSame( 'Gen3rated!Gen3rated!', $GLOBALS['ab_test_user_inserts'][0]['user_pass'] );
		// E5: role "" is the default, never a user without a role.
		AB_MCP_Tools_Menus_Users::create_user( array( 'username' => 'leer', 'email' => 'leer@example.test', 'role' => '' ) );
		self::assertSame( 'subscriber', $GLOBALS['ab_test_user_inserts'][1]['role'] );

		self::allow( self::ADMIN );
		$res = AB_MCP_Tools_Menus_Users::create_user( array( 'username' => 'ed', 'email' => 'ed@example.test', 'role' => 'editor', 'password' => 'own-secret' ) );
		self::assertNull( $res['generated_password'], 'A password given is not repeated.' );
		self::assertSame( 'own-secret', $GLOBALS['ab_test_user_inserts'][2]['user_pass'] );
		self::assertSame( 'editor', $GLOBALS['ab_test_user_inserts'][2]['role'] );

		// What WordPress refuses (a login in use) is passed on as it comes.
		$GLOBALS['ab_test_insert_answer'] = new \WP_Error( 'existing_user_login', 'Sorry, that username already exists!' );
		self::assertSame( 'existing_user_login', self::code( AB_MCP_Tools_Menus_Users::create_user( array( 'username' => 'ed', 'email' => 'ed2@example.test' ) ) ) );
	}

	/* ------------------------------------------- E1–E4: an empty role */

	public function testAnEmptyRoleLeavesTheRoleAlone(): void {
		foreach ( array( '""' => '', 'null' => null, '[]' => array() ) as $label => $empty ) {
			ab_test_user_site( array( 1 => array( 'administrator' ), 2 => array( 'editor' ) ) );
			$res = AB_MCP_Tools_Menus_Users::update_user( array( 'id' => 1, 'role' => $empty, 'first_name' => 'Anna' ) );
			self::assertSame( array( 'updated' => true, 'id' => 1 ), $res, "E1 role $label: the other fields go through" );
			self::assertCount( 1, $GLOBALS['ab_test_user_updates'] );
			self::assertArrayNotHasKey( 'role', $GLOBALS['ab_test_user_updates'][0], "E1 role $label: no role reaches wp_update_user()" );
			self::assertSame( 'Anna', $GLOBALS['ab_test_user_updates'][0]['first_name'] );
			self::assertSame( array(), $GLOBALS['ab_test_set_roles'], "E1 role $label: nor set_role()" );
			self::assertSame( array( 'administrator' ), $GLOBALS['ab_test_user_db'][1], "E1 role $label: the last administrator keeps the role" );
		}
		// E2: an account without promote_users sending role "".
		ab_test_user_site( array( 1 => array( 'administrator' ), 2 => array( 'editor' ) ) );
		self::allow( array( 'edit_users', 'edit_user' ) );
		self::assertSame( 'ok', self::code( AB_MCP_Tools_Menus_Users::update_user( array( 'id' => 2, 'role' => '', 'last_name' => 'Muster' ) ) ) );
		self::assertSame( array( 'editor' ), $GLOBALS['ab_test_user_db'][2] );
	}

	/* --------------------------------- the lock: two calls at the same time */

	public function testTwoCallsAtTheSameTimeLeaveAnAdministrator(): void {
		// L1: without the lock (every lock granted) the interleaving empties
		// the site: the test can see the race.
		$GLOBALS['ab_test_lock_off']  = true;
		$GLOBALS['ab_test_meanwhile'] = static fn() => self::demote( 2 );
		self::assertSame( 'ok', self::code( self::demote( 1 ) ) );
		self::assertSame( 'ok', self::code( $GLOBALS['ab_test_second'] ) );
		self::assertSame( 0, ab_test_admins(), 'L1 the race is real without the lock.' );

		// L2: with the lock the second demotion is refused.
		ab_test_user_site( array( 1 => array( 'administrator' ), 2 => array( 'administrator' ) ) );
		$GLOBALS['ab_test_meanwhile'] = static fn() => self::demote( 2 );
		self::assertSame( 'ok', self::code( self::demote( 1 ) ) );
		self::assertSame( 'ab_mcp_busy', self::code( $GLOBALS['ab_test_second'] ) );
		self::assertSame( 1, ab_test_admins() );
		self::assertSame( array( 'administrator' ), $GLOBALS['ab_test_user_db'][2], 'L2 the one the refused call meant to demote stays.' );
		self::assertSame( array(), $GLOBALS['ab_test_locks'], 'L2 released after the write.' );
		self::assertSame( 'ab_mcp_last_admin', self::code( self::demote( 2 ) ), 'L2 sent again, it meets the last-administrator refusal.' );
		self::assertSame( array(), $GLOBALS['ab_test_locks'], 'L4 released after the last-administrator refusal.' );

		// L3: two deletions, and a deletion beside a demotion.
		ab_test_user_site( array( 1 => array( 'administrator' ), 2 => array( 'administrator' ) ) );
		$GLOBALS['ab_test_meanwhile'] = static fn() => self::remove( 2 );
		self::assertSame( 'ok', self::code( self::remove( 1 ) ) );
		self::assertSame( 'ab_mcp_busy', self::code( $GLOBALS['ab_test_second'] ) );
		self::assertSame( 1, ab_test_admins() );
		self::assertArrayHasKey( 2, $GLOBALS['ab_test_user_db'] );
		self::assertSame( array(), $GLOBALS['ab_test_locks'], 'L4 released after a delete.' );
		ab_test_user_site( array( 1 => array( 'administrator' ), 2 => array( 'administrator' ) ) );
		$GLOBALS['ab_test_meanwhile'] = static fn() => self::demote( 2 );
		self::assertSame( 'ok', self::code( self::remove( 1 ) ) );
		self::assertSame( 'ab_mcp_busy', self::code( $GLOBALS['ab_test_second'] ) );
		self::assertSame( 1, ab_test_admins() );
	}

	public function testTheLockIsReleasedOnEveryWayOut(): void {
		$GLOBALS['ab_test_delete_ok'] = false;
		self::assertSame( 'ab_mcp_delete_failed', self::code( self::remove( 1 ) ) );
		self::assertSame( array(), $GLOBALS['ab_test_locks'], 'L4 after a failed delete.' );

		ab_test_user_site( array( 1 => array( 'administrator' ), 2 => array( 'administrator' ) ) );
		$GLOBALS['ab_test_update_throws'] = true;
		try {
			self::demote( 1 );
			self::fail( 'The hook\'s exception goes on.' );
		} catch ( RuntimeException $e ) {
			self::assertSame( 'a plugin hook failed', $e->getMessage() );
		}
		self::assertSame( array(), $GLOBALS['ab_test_locks'], 'L4 when the write throws.' );
		self::assertFalse( ( new ReflectionProperty( AB_MCP_Tools_Menus_Users::class, 'role_lock_held' ) )->getValue() );
	}

	public function testTheUserIsReadAgainUnderTheLock(): void {
		// L5: account 2 was read as an editor; meanwhile another request made
		// it administrator and demoted account 1. Judged by the old copy, 2
		// would not count as an administrator and could be demoted.
		ab_test_user_site( array( 1 => array( 'editor' ), 2 => array( 'editor' ) ) );
		$GLOBALS['ab_test_user_db'][2] = array( 'administrator' );
		self::assertSame( 'ab_mcp_last_admin', self::code( self::demote( 2 ) ) );
		self::assertSame( 1, ab_test_admins() );
	}

	public function testAChangeWithoutARoleTakesNoLock(): void {
		// L6.
		self::assertSame( 'ok', self::code( AB_MCP_Tools_Menus_Users::update_user( array( 'id' => 1, 'first_name' => 'Anna' ) ) ) );
		self::assertSame( 0, $GLOBALS['ab_test_lock_seen'] );
	}

	public function testNoAnswerFromTheServerIsNoLock(): void {
		// L7: GET_LOCK answering NULL.
		$GLOBALS['ab_test_lock_null'] = true;
		$res = self::demote( 1 );
		self::assertSame( 'ab_mcp_busy', self::code( $res ) );
		self::assertStringContainsString( 'granted no lock', $res->get_error_message() );
		self::assertSame( 'ab_mcp_busy', self::code( self::remove( 2 ) ) );
		self::assertSame( 2, ab_test_admins() );
		self::assertCount( 2, $GLOBALS['ab_test_user_db'] );
		self::assertSame( array(), $GLOBALS['ab_test_locks'] );
		$GLOBALS['ab_test_lock_null'] = false;
		self::assertSame( 'ok', self::code( self::demote( 1 ) ), 'L7 nothing was noted as held from the NULL.' );
		self::assertSame( array(), $GLOBALS['ab_test_locks'] );
	}

	public function testALockOfAnotherConnectionIsNeverTakenOverAndTheNameIsOnePerSite(): void {
		// L8.
		self::demote( 2 );
		$name = (string) end( $GLOBALS['ab_test_lock_names'] );
		ab_test_user_site( array( 1 => array( 'administrator' ), 2 => array( 'administrator' ) ) );
		$GLOBALS['ab_test_locks'][ $name ] = 'slow';
		$res = self::demote( 1 );
		self::assertSame( 'ab_mcp_busy', self::code( $res ) );
		self::assertStringContainsString( 'Another role change or user deletion through AlphaBridge is running', $res->get_error_message() );
		self::assertSame( 2, ab_test_admins() );
		self::assertSame( 'slow', $GLOBALS['ab_test_locks'][ $name ] );
		unset( $GLOBALS['ab_test_locks'][ $name ] ); // That connection ends; the server frees its locks.
		self::assertSame( 'ok', self::code( self::demote( 1 ) ) );

		// L9: the name Pro's copy takes too, one per site, within 64 characters.
		self::assertMatchesRegularExpression( '/^ab_mcp_pro_roles_[0-9a-f]{32}$/', $name );
		self::assertSame( 'ab_mcp_pro_roles_' . md5( 'wordpress|wp_' ), $name );
		self::assertLessThanOrEqual( 64, strlen( $name ) );
		$GLOBALS['wpdb']->prefix = 'wp_2_';
		ab_test_user_site( array( 1 => array( 'administrator' ), 2 => array( 'administrator' ) ) );
		self::demote( 1 );
		$other_site              = (string) end( $GLOBALS['ab_test_lock_names'] );
		$GLOBALS['wpdb']->prefix = 'wp_';
		$GLOBALS['wpdb']->dbname = 'zweite';
		ab_test_user_site( array( 1 => array( 'administrator' ), 2 => array( 'administrator' ) ) );
		self::demote( 1 );
		$other_db = (string) end( $GLOBALS['ab_test_lock_names'] );
		self::assertNotSame( $name, $other_site );
		self::assertNotSame( $name, $other_db );
		self::assertNotSame( $other_site, $other_db );
	}

	public function testALockLostBeforeTheWriteChangesNothing(): void {
		// L10: wpdb connected anew after the count.
		$GLOBALS['ab_test_reconnect'] = true;
		$res = self::demote( 1 );
		self::assertSame( 'ab_mcp_busy', self::code( $res ) );
		self::assertStringContainsString( 'no longer held', $res->get_error_message() );
		self::assertSame( 2, ab_test_admins() );
		self::assertSame( array(), $GLOBALS['ab_test_locks'] );

		ab_test_user_site( array( 1 => array( 'administrator' ), 2 => array( 'administrator' ) ) );
		$GLOBALS['ab_test_reconnect'] = true;
		self::assertSame( 'ab_mcp_busy', self::code( AB_MCP_Tools_Menus_Users::update_user( array( 'id' => 1, 'role' => 'editor', 'first_name' => 'Anna' ) ) ) );
		self::assertSame( array(), $GLOBALS['ab_test_user_updates'], 'L10 refused before anything is saved, the other fields included.' );

		ab_test_user_site( array( 1 => array( 'administrator' ), 2 => array( 'administrator' ) ) );
		$GLOBALS['ab_test_reconnect'] = true;
		self::assertSame( 'ab_mcp_busy', self::code( self::remove( 1 ) ) );
		self::assertCount( 2, $GLOBALS['ab_test_user_db'], 'L10 nothing deleted.' );
	}

	public function testACallFromWithinAHeldLockIsRefused(): void {
		// L11: a plugin's hook calls the tool again, on the same connection.
		$GLOBALS['ab_test_in_hook'] = static fn() => self::demote( 2 );
		self::assertSame( 'ok', self::code( self::demote( 1 ) ) );
		self::assertSame( 'ab_mcp_busy', self::code( $GLOBALS['ab_test_inner'] ) );
		self::assertSame( 1, ab_test_admins() );
		self::assertSame( array( 'administrator' ), $GLOBALS['ab_test_user_db'][2] );
		self::assertSame( array(), $GLOBALS['ab_test_locks'], 'L11 the outer call released the lock.' );
	}

	/* ------------------------------ deleting an administrator: the role first */

	public function testAnAdministratorLosesTheRoleBeforeTheDeletion(): void {
		// L12: the lock lost during WordPress's long clean-up; a second
		// deletion then counts the first account as no administrator any more.
		$GLOBALS['ab_test_during_cleanup'] = static fn() => self::remove( 2 );
		self::assertSame( 'ok', self::code( self::remove( 1 ) ) );
		self::assertSame( 'ab_mcp_last_admin', self::code( $GLOBALS['ab_test_second'] ) );
		self::assertSame( 1, ab_test_admins() );
		self::assertArrayHasKey( 2, $GLOBALS['ab_test_user_db'] );
		self::assertArrayNotHasKey( 1, $GLOBALS['ab_test_user_db'] );
	}

	public function testAFailedDeletionGivesTheRoleBack(): void {
		// L13.
		ab_test_user_site( array( 1 => array( 'administrator', 'editor' ), 2 => array( 'administrator' ) ) );
		$GLOBALS['ab_test_delete_ok'] = false;
		$res = self::remove( 1 );
		self::assertSame( 'ab_mcp_delete_failed', self::code( $res ) );
		self::assertStringContainsString( 'has been given back', $res->get_error_message() );
		self::assertContains( 'administrator', $GLOBALS['ab_test_user_db'][1] );
		self::assertContains( 'editor', $GLOBALS['ab_test_user_db'][1], 'The other roles untouched.' );
		self::assertSame( array(), $GLOBALS['ab_test_locks'] );

		// L14: the role cannot be given back, and the error says so.
		ab_test_user_site( array( 1 => array( 'administrator' ), 2 => array( 'administrator' ) ) );
		$GLOBALS['ab_test_delete_ok']      = false;
		$GLOBALS['ab_test_add_role_fails'] = true;
		$res = self::remove( 1 );
		self::assertSame( 'ab_mcp_delete_failed', self::code( $res ) );
		self::assertStringContainsString( 'could not be given back', $res->get_error_message() );
		self::assertArrayHasKey( 1, $GLOBALS['ab_test_user_db'] );
		self::assertSame( 1, ab_test_admins() );

		// L14: a non-administrator keeps the roles untouched.
		ab_test_user_site( array( 1 => array( 'editor' ), 2 => array( 'administrator' ) ) );
		$GLOBALS['ab_test_delete_ok'] = false;
		$res = self::remove( 1 );
		self::assertSame( 'ab_mcp_delete_failed', self::code( $res ) );
		self::assertStringStartsWith( 'Delete failed.', $res->get_error_message() );
		self::assertSame( array( 'editor' ), $GLOBALS['ab_test_user_db'][1] );
	}

	public function testAHookAroundTheRoleIsReadBack(): void {
		// L15: a hook failing after the role was taken: nothing deleted, the
		// role given back and said so.
		$GLOBALS['ab_test_remove_throws'] = 'after';
		$res = self::remove( 1 );
		self::assertSame( 'ab_mcp_delete_failed', self::code( $res ) );
		self::assertStringContainsString( 'has been given back', $res->get_error_message() );
		self::assertSame( 2, ab_test_admins() );
		self::assertSame( array(), $GLOBALS['ab_test_deleted_users'] );
		self::assertSame( array(), $GLOBALS['ab_test_locks'] );

		// L15: failing before the role was taken.
		ab_test_user_site( array( 1 => array( 'administrator' ), 2 => array( 'administrator' ) ) );
		$GLOBALS['ab_test_remove_throws'] = 'before';
		$res = self::remove( 1 );
		self::assertStringContainsString( 'nothing was deleted and the account keeps the role', $res->get_error_message() );
		self::assertSame( 2, ab_test_admins() );

		// L16: a role a plugin keeps.
		ab_test_user_site( array( 1 => array( 'administrator' ), 2 => array( 'administrator' ) ) );
		$GLOBALS['ab_test_remove_ignored'] = true;
		$res = self::remove( 1 );
		self::assertSame( 'ab_mcp_delete_failed', self::code( $res ) );
		self::assertStringContainsString( 'nothing was deleted', $res->get_error_message() );
		self::assertSame( array(), $GLOBALS['ab_test_deleted_users'] );
		self::assertSame( 2, ab_test_admins() );
	}

	public function testAnAccountGoneBeforeAHookFailedIsReportedDeleted(): void {
		// L17 multisite: off the site, then a deleted_user hook fails.
		$GLOBALS['ab_test_multisite']           = true;
		$GLOBALS['ab_test_deleted_hook_throws'] = true;
		$res = self::remove( 1 );
		self::assertIsArray( $res );
		self::assertTrue( $res['deleted'] );
		self::assertArrayHasKey( 'warning', $res );
		self::assertSame( array(), $GLOBALS['ab_test_user_db'][1], 'The role NOT given back on this site.' );
		self::assertSame( 1, ab_test_admins() );

		// L17 single site.
		$GLOBALS['ab_test_multisite'] = false;
		ab_test_user_site( array( 1 => array( 'administrator' ), 2 => array( 'administrator' ) ) );
		$GLOBALS['ab_test_deleted_hook_throws'] = true;
		$res = self::remove( 1 );
		self::assertTrue( $res['deleted'] );
		self::assertArrayHasKey( 'warning', $res );
		self::assertArrayNotHasKey( 1, $GLOBALS['ab_test_user_db'] );

		// L17 multisite, a deletion that did not happen gives the role back.
		$GLOBALS['ab_test_multisite'] = true;
		ab_test_user_site( array( 1 => array( 'administrator' ), 2 => array( 'administrator' ) ) );
		$GLOBALS['ab_test_delete_ok'] = false;
		self::assertSame( 'ab_mcp_delete_failed', self::code( self::remove( 1 ) ) );
		self::assertContains( 'administrator', $GLOBALS['ab_test_user_db'][1] );
	}

	public function testTheHeirMustBeAnotherAccountOfThisSite(): void {
		// L18.
		foreach ( array( 'the account itself' => 1, 'an id no account has' => 99, 'a negative id' => -3 ) as $what => $heir ) {
			ab_test_user_site( array( 1 => array( 'editor' ), 2 => array( 'administrator' ) ) );
			$res = AB_MCP_Tools_Menus_Users::delete_user( array( 'id' => 1, 'reassign_to' => $heir ) );
			self::assertSame( 'ab_mcp_bad_reassign', self::code( $res ), $what );
			self::assertArrayHasKey( 1, $GLOBALS['ab_test_user_db'], $what );
			self::assertSame( 0, $GLOBALS['ab_test_lock_seen'], $what . ': refused before anything.' );
		}
		ab_test_user_site( array( 1 => array( 'editor' ), 2 => array( 'administrator' ), 3 => array( 'author' ) ) );
		$GLOBALS['ab_test_multisite'] = true;
		$GLOBALS['ab_test_gone']      = array( 3 => true );
		self::assertSame( 'ab_mcp_bad_reassign', self::code( AB_MCP_Tools_Menus_Users::delete_user( array( 'id' => 1, 'reassign_to' => 3 ) ) ), 'An heir who is no member of this site.' );
		self::assertSame( array(), $GLOBALS['ab_test_deleted_users'] );
		$GLOBALS['ab_test_multisite'] = false;

		ab_test_user_site( array( 1 => array( 'editor' ), 2 => array( 'administrator' ) ) );
		self::assertSame( 'ok', self::code( AB_MCP_Tools_Menus_Users::delete_user( array( 'id' => 1, 'reassign_to' => 2 ) ) ) );
		self::assertSame( 2, $GLOBALS['ab_test_reassigned'], 'Another existing user is handed to WordPress.' );
		ab_test_user_site( array( 1 => array( 'editor' ), 2 => array( 'administrator' ) ) );
		self::remove( 1 );
		self::assertNull( $GLOBALS['ab_test_reassigned'], 'None: WordPress deletes or trashes the content.' );

		// L19: the heir deleted between the check and the lock.
		ab_test_user_site( array( 1 => array( 'editor' ), 2 => array( 'author' ), 3 => array( 'administrator' ) ) );
		$GLOBALS['ab_test_on_lock'] = static function (): void {
			unset( $GLOBALS['ab_test_user_db'][2] );
		};
		self::assertSame( 'ab_mcp_bad_reassign', self::code( AB_MCP_Tools_Menus_Users::delete_user( array( 'id' => 1, 'reassign_to' => 2 ) ) ) );
		self::assertSame( 'not called', $GLOBALS['ab_test_reassigned'] );
		self::assertArrayHasKey( 1, $GLOBALS['ab_test_user_db'] );
		// L19 multisite: taken off this site in between.
		ab_test_user_site( array( 1 => array( 'editor' ), 2 => array( 'author' ), 3 => array( 'administrator' ) ) );
		$GLOBALS['ab_test_multisite'] = true;
		$GLOBALS['ab_test_on_lock']   = static function (): void {
			$GLOBALS['ab_test_gone'][2] = true;
		};
		self::assertSame( 'ab_mcp_bad_reassign', self::code( AB_MCP_Tools_Menus_Users::delete_user( array( 'id' => 1, 'reassign_to' => 2 ) ) ) );
		self::assertSame( 'not called', $GLOBALS['ab_test_reassigned'] );
	}

	/* -------------------------- L20, L21: the role written by itself, last */

	public function testTheRoleIsWrittenByItselfAfterTheOtherFields(): void {
		self::assertSame( 'ok', self::code( AB_MCP_Tools_Menus_Users::update_user( array( 'id' => 1, 'role' => 'editor', 'first_name' => 'Anna', 'email' => 'anna@example.test', 'password' => '' ) ) ) );
		self::assertCount( 1, $GLOBALS['ab_test_user_updates'] );
		self::assertSame( array( 'ID' => 1, 'user_email' => 'anna@example.test', 'first_name' => 'Anna' ), $GLOBALS['ab_test_user_updates'][0], 'Without the role, and an empty password leaves the password alone.' );
		self::assertSame( array( 'editor' ), $GLOBALS['ab_test_set_roles'], 'The role is set once, by itself.' );
		self::assertSame( array( 'editor' ), $GLOBALS['ab_test_user_db'][1] );
		self::assertSame( array(), $GLOBALS['ab_test_locks'] );
		self::assertSame( array( 'editor' ), get_user_by( 'id', 1 )->roles, 'L21 read afterwards with the new role, its cache cleaned.' );

		// L20: the lock lost while the other fields are written.
		ab_test_user_site( array( 1 => array( 'administrator' ), 2 => array( 'administrator' ) ) );
		$GLOBALS['ab_test_reconnect_in_update'] = true;
		$res = AB_MCP_Tools_Menus_Users::update_user( array( 'id' => 1, 'role' => 'editor', 'first_name' => 'Anna' ) );
		self::assertSame( 'ab_mcp_busy', self::code( $res ) );
		self::assertStringContainsString( 'The role was not changed', $res->get_error_message() );
		self::assertStringContainsString( 'The account was saved before that, with the other fields of this call', $res->get_error_message() );
		self::assertSame( array( 'administrator' ), $GLOBALS['ab_test_user_db'][1] );
		self::assertSame( array(), $GLOBALS['ab_test_set_roles'] );
		self::assertSame( 'Anna', $GLOBALS['ab_test_user_updates'][0]['first_name'] );
		self::assertSame( array(), $GLOBALS['ab_test_locks'] );
		// A role change without other fields: no claim that nothing changed.
		ab_test_user_site( array( 1 => array( 'administrator' ), 2 => array( 'administrator' ) ) );
		$GLOBALS['ab_test_reconnect_in_update'] = true;
		$res = self::demote( 1 );
		self::assertStringContainsString( 'The role was not changed', $res->get_error_message() );
		self::assertStringNotContainsString( 'nothing was changed', $res->get_error_message() );
		self::assertSame( array( 'administrator' ), $GLOBALS['ab_test_user_db'][1] );
	}

	public function testTheRoleIsWrittenForTheAccountAsItIsNow(): void {
		// L21: a hook on profile_update reads the account; then wp-admin,
		// which takes no lock, deletes it. No role is written for it.
		$GLOBALS['ab_test_after_update'] = static function (): void {
			get_user_by( 'id', 1 );
			unset( $GLOBALS['ab_test_user_db'][1] );
		};
		self::assertSame( 'ab_mcp_not_found', self::code( self::demote( 1 ) ) );
		self::assertSame( array(), $GLOBALS['ab_test_set_roles'] );
		self::assertSame( array(), $GLOBALS['ab_test_locks'] );

		// The read itself renews the connection: the check comes after it.
		ab_test_user_site( array( 1 => array( 'administrator' ), 2 => array( 'administrator' ) ) );
		$GLOBALS['ab_test_after_update'] = static function (): void {
			$GLOBALS['ab_test_reconnect_on_read'] = true;
		};
		self::assertSame( 'ab_mcp_busy', self::code( self::demote( 1 ) ) );
		self::assertSame( array( 'administrator' ), $GLOBALS['ab_test_user_db'][1] );
		self::assertSame( array(), $GLOBALS['ab_test_set_roles'] );

		// Lost, and taken by another request meanwhile: its lock is not ours.
		ab_test_user_site( array( 1 => array( 'administrator' ), 2 => array( 'administrator' ) ) );
		$name                            = 'ab_mcp_pro_roles_' . md5( 'wordpress|wp_' );
		$GLOBALS['ab_test_after_update'] = static function () use ( $name ): void {
			ab_test_renew_connection();
			$GLOBALS['ab_test_locks'][ $name ] = 'second';
		};
		self::assertSame( 'ab_mcp_busy', self::code( self::demote( 1 ) ) );
		self::assertSame( array( 'administrator' ), $GLOBALS['ab_test_user_db'][1] );
		self::assertSame( 'second', $GLOBALS['ab_test_locks'][ $name ], 'The other request keeps its lock.' );
	}

	/* ------------------------------------------------------------ WooCommerce */

	/** WooCommerce for the writing tools, in this process only; any account may edit. */
	private static function woo(): void {
		// PHP binds the functions of a file before it runs it: loaded next to
		// woo-stubs.php, the file would fail on a function declared twice.
		if ( function_exists( 'wc_get_customer_order_count' ) ) {
			throw new RuntimeException( 'woo-write-stubs.php belongs in a process of its own, without tests/woo-stubs.php.' );
		}
		require_once __DIR__ . '/woo-write-stubs.php';
		ab_test_wc_reset();
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => true;
	}

	private static function set_status( string $status, string $note = '' ) {
		return AB_MCP_Tools_Shop::update_order_status( array( 'id' => 5, 'status' => $status, 'note' => $note ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testTheShopWritesLeadToWooCommerceWhereItIsMissing(): void {
		self::assertFalse( class_exists( 'WooCommerce', false ) );
		foreach ( array( 'update_order_status', 'update_product', 'create_coupon' ) as $method ) {
			$res = AB_MCP_Tools_Shop::$method( array( 'id' => 5, 'status' => 'completed', 'code' => 'x', 'amount' => '1' ) );
			self::assertSame( 'ab_mcp_no_woocommerce', self::code( $res ), $method );
			self::assertStringContainsString( 'Ask an administrator to activate it under Plugins', $res->get_error_message(), $method );
			self::assertStringNotContainsString( 'wp_activate_plugin', $res->get_error_message(), 'No tool of Pro is offered as the way.' );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testAnOrderStatusTheShopDoesNotKnowIsRefusedWithTheOnesItDoes(): void {
		self::woo();
		$order = $GLOBALS['ab_test_wc_order'];
		foreach ( array( 'complete', 'shipped-now', 'Done', '', 'wc-', 'pendingx', 'completed x', 'packed' ) as $bad ) {
			$res = self::set_status( $bad, 'n' );
			self::assertSame( 'ab_mcp_bad_status', self::code( $res ), "'$bad'" );
			self::assertSame( array(), $order->calls, "nothing handed to WooCommerce for '$bad'" );
			self::assertSame( array(), $order->notes );
		}
		$msg = self::set_status( 'nonsense' )->get_error_message();
		self::assertStringContainsString( 'This shop knows: pending, processing, on-hold, completed, cancelled, refunded, failed, shipped, wc-packed.', $msg, 'Only statuses that would be taken: the key without its prefix is left out, the odd one that works is named.' );

		// Handed to WooCommerce as the registered key, as a manual update, with
		// the note as an entry of its own.
		foreach ( array(
			'completed'    => array( 'wc-completed', 'completed' ),
			'wc-completed' => array( 'wc-completed', 'completed' ),
			' Completed '  => array( 'wc-completed', 'completed' ),
			"completed\n"  => array( 'wc-completed', 'completed' ),
			'WC-On-Hold'   => array( 'wc-on-hold', 'on-hold' ),
			'shipped'      => array( 'wc-shipped', 'shipped' ),
			'wc-packed'    => array( 'wc-wc-packed', 'wc-packed' ),
			'wc-wc-packed' => array( 'wc-wc-packed', 'wc-packed' ),
		) as $in => [ $key, $status ] ) {
			ab_test_wc_reset();
			$order = $GLOBALS['ab_test_wc_order'];
			$res   = self::set_status( $in, 'n' );
			self::assertSame( array( array( $key, '', true ) ), $order->calls, "'$in'" );
			self::assertSame( array( array( 'n', 0, true ) ), $order->notes, "'$in'" );
			self::assertSame( array( 'updated' => true, 'id' => 5, 'status' => $status, 'status_unchanged' => false ), $res, "'$in'" );
		}

		// A note asked for is written also when the status already is set.
		ab_test_wc_reset();
		$res = self::set_status( 'processing', 'Kunde informiert' );
		self::assertTrue( $res['status_unchanged'] );
		self::assertSame( array( array( 'Kunde informiert', 0, true ) ), $GLOBALS['ab_test_wc_order']->notes );
		// No note: none besides WooCommerce's own.
		ab_test_wc_reset();
		self::set_status( 'completed' );
		self::assertSame( array(), $GLOBALS['ab_test_wc_order']->notes );

		// A display filter that shows two statuses under one name does not make
		// a real change read as none.
		ab_test_wc_reset();
		$GLOBALS['ab_test_wc_status_filter'] = true;
		$res = self::set_status( 'completed', 'Versandt' );
		self::assertFalse( $res['status_unchanged'] );
		self::assertSame( array( array( 'wc-completed', '', true ) ), $GLOBALS['ab_test_wc_order']->calls );
		// ... and no repeat of the stored status reads as a change: both sides
		// are read as stored.
		ab_test_wc_reset();
		$GLOBALS['ab_test_wc_status_filter'] = true;
		self::assertTrue( self::set_status( 'processing' )['status_unchanged'] );

		// What WooCommerce did not save is no "updated".
		ab_test_wc_reset();
		$GLOBALS['ab_test_wc_save_fails'] = true;
		$res = self::set_status( 'completed', 'n' );
		self::assertSame( 'ab_mcp_not_saved', self::code( $res ) );
		self::assertSame( array(), $GLOBALS['ab_test_wc_order']->notes, 'No note on a change that did not happen.' );

		self::assertSame( 'ab_mcp_not_found', self::code( AB_MCP_Tools_Shop::update_order_status( array( 'id' => 6, 'status' => 'completed' ) ) ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testTheRightToTheOneOrderAndProductIsAskedBeforeAnythingIsWritten(): void {
		self::woo();
		// The bootstrap knows no post type of WooCommerce, so here the right
		// asked is edit_post; its name for a registered type (edit_shop_order,
		// edit_product) is checked by tests/shop-probe.php.
		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => ! ( 'edit_post' === $cap && in_array( (int) ( $args[0] ?? 0 ), array( 5, 7 ), true ) );
		$res = self::set_status( 'completed', 'Versandt' );
		self::assertSame( 'ab_mcp_forbidden', self::code( $res ) );
		self::assertStringContainsString( 'cannot edit this specific order', $res->get_error_message() );
		self::assertStringContainsString( 'under Users', $res->get_error_message() );
		self::assertSame( array(), $GLOBALS['ab_test_wc_order']->calls, 'Neither the status ...' );
		self::assertSame( array(), $GLOBALS['ab_test_wc_order']->notes, '... nor the note is written.' );
		self::assertSame( 'processing', $GLOBALS['ab_test_wc_order']->status );

		$res = AB_MCP_Tools_Shop::update_product( array( 'id' => 7, 'regular_price' => '12.50', 'stock' => 4 ) );
		self::assertSame( 'ab_mcp_forbidden', self::code( $res ) );
		self::assertStringContainsString( 'cannot edit this specific product', $res->get_error_message() );
		self::assertSame( array(), $GLOBALS['ab_test_wc_product']->set, 'No setter runs, nothing is saved.' );

		$GLOBALS['ab_test_can'] = static fn( string $cap, array $args ): bool => true;
		self::assertSame( 'ok', self::code( self::set_status( 'completed', 'Versandt' ) ) );
		self::assertSame( 'completed', $GLOBALS['ab_test_wc_order']->status );
		self::assertSame( 'ok', self::code( AB_MCP_Tools_Shop::update_product( array( 'id' => 7, 'regular_price' => '12.50' ) ) ) );
		self::assertTrue( $GLOBALS['ab_test_wc_product']->set['saved'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testAProductIsCheckedWholeBeforeAnythingIsWritten(): void {
		self::woo();
		$p = $GLOBALS['ab_test_wc_product'];
		foreach ( array( 'abc', '1,5x', '-1', 'NaN', '1e999x', '1e2', '1e999', '1,5', '+3', '9999999999999999', str_repeat( '9', 309 ) ) as $bad ) {
			$p->set = array();
			$res    = AB_MCP_Tools_Shop::update_product( array( 'id' => 7, 'regular_price' => $bad, 'stock' => 4 ) );
			self::assertSame( 'ab_mcp_bad_price', self::code( $res ), "'$bad'" );
			self::assertSame( array(), $p->set, "'$bad' leaves the product untouched" );
		}
		self::assertStringContainsString( 'regular_price must be a plain number of at least 0, written with "." or this shop\'s decimal separator "."', $res->get_error_message() );
		self::assertStringContainsString( 'at most 15 digits before the separator and 30 after it', $res->get_error_message() );
		$p->set = array();
		self::assertSame( 'ab_mcp_bad_price', self::code( AB_MCP_Tools_Shop::update_product( array( 'id' => 7, 'sale_price' => array( '1' ) ) ) ), 'A price that is no single value.' );
		self::assertSame( array(), $p->set );
		foreach ( array( '12.50' => '12.50', ' 7 ' => '7', '' => '', '0' => '0', '0.5' => '0.5', '1000000' => '1000000', '123456789012345' => '123456789012345', '0.123456' => '0.123456' ) as $in => $want ) {
			$p->set = array();
			$res    = AB_MCP_Tools_Shop::update_product( array( 'id' => 7, 'sale_price' => (string) $in ) );
			self::assertSame( 'ok', self::code( $res ), "'$in'" );
			self::assertSame( $want, $p->set['sale_price'], "'$in'" );
			self::assertTrue( $p->set['saved'] );
		}
		foreach ( array( 'trash', 'auto-draft', 'Publish', '', 'request-pending', 'wc-completed', 'wc-refunded' ) as $bad ) {
			$p->set = array();
			$res    = AB_MCP_Tools_Shop::update_product( array( 'id' => 7, 'status' => $bad, 'regular_price' => '5' ) );
			self::assertSame( 'ab_mcp_bad_product_status', self::code( $res ), "'$bad'" );
			self::assertSame( array(), $p->set, "'$bad' leaves the product untouched" );
		}
		self::assertStringContainsString( 'This site knows: publish, draft, pending, private, future.', $res->get_error_message() );
		foreach ( array( 'publish', 'draft', 'pending', 'private', 'future' ) as $good ) {
			$p->set = array();
			self::assertSame( 'ok', self::code( AB_MCP_Tools_Shop::update_product( array( 'id' => 7, 'status' => $good ) ) ) );
			self::assertSame( $good, $p->set['status'] );
		}

		// Only the fields given change; the answer is the product as saved.
		$p->set = array();
		$res    = AB_MCP_Tools_Shop::update_product( array( 'id' => 7, 'regular_price' => '20', 'stock' => 4 ) );
		self::assertSame( array( 'regular_price' => '20', 'manage_stock' => true, 'stock' => 4, 'saved' => true ), $p->set );
		self::assertSame( array( 'id' => 7, 'name' => 'Widget', 'sku' => 'W-1', 'price' => '20', 'regular_price' => '20', 'sale_price' => '', 'stock' => 4, 'stock_status' => 'instock', 'status' => 'publish' ), $res['product'] );
		self::assertTrue( $res['updated'] );

		// In a shop that writes decimals with a comma, 12,50 is twelve and a half.
		$GLOBALS['ab_test_wc_dec'] = ',';
		$p->set                    = array();
		AB_MCP_Tools_Shop::update_product( array( 'id' => 7, 'regular_price' => '12,50' ) );
		self::assertSame( '12.50', $p->set['regular_price'] );
		self::assertSame( 'ab_mcp_bad_price', self::code( AB_MCP_Tools_Shop::update_product( array( 'id' => 7, 'regular_price' => '1.234,50' ) ) ), 'Two separators are a grouped number.' );
		$GLOBALS['ab_test_wc_dec'] = '.';

		$GLOBALS['ab_test_wc_save_fails'] = true;
		self::assertSame( 'ab_mcp_not_saved', self::code( AB_MCP_Tools_Shop::update_product( array( 'id' => 7, 'stock' => 1 ) ) ) );
		$GLOBALS['ab_test_wc_save_fails'] = false;
		self::assertSame( 'ab_mcp_not_found', self::code( AB_MCP_Tools_Shop::update_product( array( 'id' => 8, 'stock' => 1 ) ) ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testACouponIsCheckedBeforeItExists(): void {
		self::woo();
		$create = static fn( array $a ) => AB_MCP_Tools_Shop::create_coupon( $a + array( 'code' => 'spring', 'amount' => '10' ) );

		self::assertSame( 'ab_mcp_bad_code', self::code( $create( array( 'code' => '  ' ) ) ), 'An empty code.' );
		self::assertSame( 'ab_mcp_bad_code', self::code( $create( array( 'code' => '<b></b>' ) ) ), 'Empty once WooCommerce has normalised it.' );
		self::assertSame( 'ab_mcp_bad_code', self::code( $create( array( 'code' => '&amp;lt;b&amp;gt;' ) ) ), 'A code the lookup would normalise again into something else could never be found.' );
		self::assertSame( 'ab_mcp_duplicate', self::code( $create( array( 'code' => ' EXISTING ' ) ) ), 'The duplicate check sees the normalised code.' );
		self::assertSame( 'ab_mcp_duplicate', self::code( $create( array( 'code' => 'existing' ) ) ) );
		$GLOBALS['ab_test_wc_coupon_prefix'] = 'shown-';
		self::assertSame( 'ab_mcp_duplicate', self::code( $create( array( 'code' => 'existing' ) ) ), 'Judged as stored, not as a display filter shows it.' );
		self::assertSame( 'ab_mcp_bad_code', self::code( $create( array( 'code' => '<b></b>' ) ) ) );
		$GLOBALS['ab_test_wc_coupon_prefix'] = null;
		foreach ( array( 'ten', '-5', '', '10%', '1e2', str_repeat( '9', 309 ) ) as $bad ) {
			self::assertSame( 'ab_mcp_bad_amount', self::code( $create( array( 'amount' => $bad ) ) ), "amount '$bad'" );
		}
		$res = $create( array( 'discount_type' => 'bogus' ) );
		self::assertSame( 'ab_mcp_bad_type', self::code( $res ) );
		self::assertStringContainsString( 'This shop knows: percent, fixed_cart, fixed_product.', $res->get_error_message() );
		self::assertSame( 'ab_mcp_bad_type', self::code( $create( array( 'discount_type' => '0' ) ) ), '"0" is a value, not a missing one.' );
		foreach ( array( '2026-13-01', '2026-02-30', '31.12.2026', 'tomorrow', '2026-12-31 x', '2026-12-311' ) as $bad ) {
			self::assertSame( 'ab_mcp_bad_date', self::code( $create( array( 'expires' => $bad ) ) ), "expires '$bad'" );
		}
		$over = $create( array( 'code' => 'zuviel', 'amount' => '101' ) );
		self::assertSame( 'ab_mcp_bad_amount', self::code( $over ) );
		self::assertStringContainsString( '100 percent', $over->get_error_message(), 'What WooCommerce refuses about the amount comes back in its own words.' );
		self::assertSame( 'ab_mcp_bad_amount', self::code( $create( array( 'code' => 'zuvielestellen', 'amount' => '0.' . str_repeat( '1', 31 ) ) ) ) );
		self::assertSame( array(), \WC_Coupon::$saved, 'Nothing was saved by any refused call.' );

		$res = $create( array( 'amount' => ' 10 ', 'discount_type' => 'fixed_cart', 'expires' => '2026-12-31' ) );
		self::assertSame( array( 'created' => true, 'id' => 99, 'code' => 'spring' ), $res );
		self::assertSame( array( 'code' => 'spring', 'discount_type' => 'fixed_cart', 'amount' => '10', 'expires' => '2026-12-31' ), \WC_Coupon::$saved[0] );
		$create( array( 'code' => 'summer', 'amount' => '5' ) );
		self::assertSame( array( 'code' => 'summer', 'discount_type' => 'percent', 'amount' => '5' ), \WC_Coupon::$saved[1], 'Defaults: percent, no expiry.' );
		$create( array( 'code' => ' Winter<i></i> ', 'amount' => '5' ) );
		self::assertSame( 'winter', \WC_Coupon::$saved[2]['code'], 'Stored normalised.' );
		$create( array( 'code' => 'autumn', 'amount' => '5', 'expires' => "2026-12-31\n" ) );
		self::assertSame( '2026-12-31', \WC_Coupon::$saved[3]['expires'], 'A trailing newline is trimmed.' );
		// The date check itself is anchored with \z, not $: a newline that
		// reached it would not slip through.
		$date_ok = new \ReflectionMethod( AB_MCP_Tools_Shop::class, 'date_ok' );
		self::assertTrue( $date_ok->invoke( null, '2026-12-31' ) );
		self::assertFalse( $date_ok->invoke( null, "2026-12-31\n" ) );
		$create( array( 'code' => 'alias', 'amount' => '5', 'discount_type' => 'percent_product' ) );
		self::assertSame( 'percent', \WC_Coupon::$saved[4]['discount_type'], 'WooCommerce\'s own alias.' );
		$create( array( 'code' => 'vielestellen', 'amount' => '0.123456789' ) );
		self::assertSame( '0.123456789', \WC_Coupon::$saved[5]['amount'] );
		foreach ( array( '.5', '5.' ) as $edge ) {
			self::assertSame( 'ok', self::code( $create( array( 'code' => 'rand' . md5( $edge ), 'amount' => $edge ) ) ), "'$edge'" );
		}
		$GLOBALS['ab_test_wc_coupon_prefix'] = 'shown-';
		self::assertSame( 'antwort', $create( array( 'code' => 'antwort', 'amount' => '5' ) )['code'], 'The answer names the code as stored.' );
		$GLOBALS['ab_test_wc_coupon_prefix'] = null;

		// The shop's own writing.
		$GLOBALS['ab_test_wc_dec'] = ',';
		self::assertSame( '12.50', $create( array( 'code' => 'komma', 'amount' => '12,50' ) ) ? end( \WC_Coupon::$saved )['amount'] : null );
		$create( array( 'code' => 'punkt', 'amount' => '12.50' ) );
		self::assertSame( '12.50', end( \WC_Coupon::$saved )['amount'], 'A lone point is the decimal point in every shop.' );
		self::assertSame( 'ab_mcp_bad_amount', self::code( $create( array( 'code' => 'tausender', 'amount' => '1.234,50' ) ) ) );
		$GLOBALS['ab_test_wc_dec'] = '.';
		self::assertSame( 'ab_mcp_bad_amount', self::code( $create( array( 'code' => 'kommainpunkt', 'amount' => '12,50' ) ) ), 'A comma where the shop writes a point.' );

		$GLOBALS['ab_test_wc_save_fails'] = true;
		self::assertSame( 'ab_mcp_not_saved', self::code( $create( array( 'code' => 'nichtgespeichert', 'amount' => '5' ) ) ) );
	}

	public function testTheRightsAreAskedUnderTheTypesNameAndABackslashIsRefused(): void {
		$proc = proc_open( array( PHP_BINARY, __DIR__ . '/shop-probe.php' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $proc );
		$out  = (string) stream_get_contents( $pipes[1] );
		$err  = (string) stream_get_contents( $pipes[2] );
		$exit = proc_close( $proc );
		self::assertSame( 0, $exit, $err );
		$r = json_decode( $out, true );
		self::assertIsArray( $r, $out . $err );

		// R3: edit_shop_order for the order itself, under that name.
		self::assertSame( 'ab_mcp_forbidden', $r['order_denied']['code'] );
		self::assertSame( array( 'edit_shop_order:5' ), $r['order_denied']['asked'] );
		self::assertSame( array(), $r['order_denied']['calls'] );
		self::assertSame( array(), $r['order_denied']['notes'] );
		self::assertSame( 'ok', $r['order_allowed']['code'] );
		self::assertSame( array( 'edit_shop_order:5' ), $r['order_allowed']['asked'] );
		self::assertSame( array( 'Versandt' ), $r['order_allowed']['notes'] );
		self::assertSame( 'ab_mcp_forbidden', $r['order_type_gone']['code'], 'edit_post where the type is not registered, and its refusal holds.' );
		self::assertSame( array( 'edit_post:5' ), $r['order_type_gone']['asked'] );
		self::assertSame( array(), $r['order_type_gone']['calls'] );
		// R4: edit_product, for a variation too.
		self::assertSame( 'ab_mcp_forbidden', $r['product_denied']['code'] );
		self::assertSame( array(), $r['product_denied']['set'] );
		self::assertSame( array( 'edit_product:7' ), $r['product_allowed']['asked'] );
		self::assertTrue( $r['product_allowed']['set']['saved'] );
		self::assertSame( 'ab_mcp_forbidden', $r['variation_denied']['code'] );
		self::assertSame( array( 'edit_product:8' ), $r['variation_denied']['asked'] );
		self::assertSame( array(), $r['variation_denied']['set'] );

		// A backslash would be unslashed away by wp_insert_post().
		foreach ( array( 'coupon_backslash', 'coupon_lone', 'coupon_double' ) as $case ) {
			self::assertSame( 'ab_mcp_bad_code', $r[ $case ]['code'], $case );
			self::assertSame( array(), $r[ $case ]['coupons'], $case );
		}
		self::assertSame( 'ok', $r['coupon_plain']['code'] );
		self::assertSame( 'sale10', $r['coupon_plain']['coupons'][0]['code'] );
	}
}
