<?php
/**
 * Menus and user accounts.
 *
 * Free since 4.7.0 (the menus and the reading of accounts), since 4.9.0 also
 * creating, changing and deleting accounts. Until then these tools came with
 * AlphaBridge MCP Pro only; the code is Pro's (4.8.11), unchanged in what it
 * does, with its guards: the last administrator, the roles an account may
 * give, the right to the one user, and the lock that keeps role changes and
 * deletions apart. Pro registers its own copy of the same names over these
 * (as it does with wp_get_post and others), so a site with Pro runs Pro's.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tools-base.php';
require_once dirname( __DIR__ ) . '/undo/class-undo-terms.php';
require_once dirname( __DIR__ ) . '/undo/class-undo-menu-items.php';

/**
 * Class AB_MCP_Tools_Menus_Users
 */
class AB_MCP_Tools_Menus_Users extends AB_MCP_Tools_Base {

	/**
	 * Whether this request holds the lock around a role change or a user
	 * deletion.
	 *
	 * Counting the administrators and changing or deleting one of them is one
	 * step under this lock. Without it two calls running at the same time could
	 * each count two administrators and each demote or delete one of them,
	 * leaving none (Codex, 23.10.2026). It serialises AlphaBridge's own calls;
	 * a change made at the same moment in wp-admin does not take it.
	 *
	 * The lock is a named lock of the database server (GET_LOCK), held by this
	 * request's database connection: as long as that connection lasts, and
	 * given up by the server when it ends, also when the request dies. wpdb
	 * quietly opens a new connection when the server has gone away (error
	 * 2006), and the lock stays behind with the old one; so right before the
	 * write the request asks whether its own connection still holds it
	 * (IS_USED_LOCK() = CONNECTION_ID(), still_hold_role_lock()) and changes
	 * nothing when not (Codex, round 5). A call made from within a held lock
	 * (a plugin's hook calling the tool again, on the same connection, which
	 * GET_LOCK would simply grant once more) is refused, and only the outer
	 * call releases it. A lock kept in a row instead needs an expiry for a
	 * request that died, and any expiry lets a request that is merely slow
	 * keep writing after another one has taken its lock over (Codex, round 4:
	 * WP_Upgrader::create_lock() first, then a row with compare-and-swap).
	 * GET_LOCK exists in every MySQL and MariaDB WordPress runs on; this
	 * request holds no other named lock, so the one-lock-per-connection rule
	 * of MySQL before 5.7 does not bite.
	 *
	 * A database layer that sends SELECTs to replicas (HyperDB, LudicrousDB)
	 * could take the lock on a replica; such a site then has the protection only
	 * as far as its layer sends these queries to one server.
	 *
	 * What stays open: the lock is checked right before the one short write
	 * that takes the administrator role (the role of a role change, written by
	 * itself after the other fields, see write_role(), or the role taken away
	 * before a deletion, see delete_user()); a connection lost exactly between
	 * that check and that write (WordPress's own write of the role: a query
	 * for its row and the hooks on update_user_meta) is not covered. Changes
	 * made in wp-admin take no lock at all.
	 *
	 * @var bool
	 */
	private static $role_lock_held = false;

	/**
	 * The name of the lock: one per WordPress site, so two sites sharing a
	 * database server, or two sites of one network, do not wait for each
	 * other. A hash keeps it within the 64 characters MySQL allows.
	 *
	 * The same name as Pro's copy of these tools takes, prefix included: a
	 * role change through this plugin and a deletion through Pro on the same
	 * site (an update of one of the two in between, say) wait for one lock.
	 *
	 * @return string
	 */
	protected static function role_lock_name() {
		global $wpdb;
		$db = isset( $wpdb->dbname ) ? (string) $wpdb->dbname : ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' );
		return 'ab_mcp_pro_roles_' . md5( $db . '|' . (string) $wpdb->prefix );
	}

	/**
	 * Take the lock for a role change or a user deletion, or say why not.
	 * Never waits: a lock held by another request is an answer, not a pause.
	 *
	 * @return true|WP_Error
	 */
	protected static function take_role_lock() {
		global $wpdb;
		if ( self::$role_lock_held ) {
			// A call from within the one holding the lock: GET_LOCK would
			// grant it again on the same connection, and the inner release
			// would end the outer call's lock before its write.
			return new WP_Error( 'ab_mcp_busy', __( 'Another role change or user deletion through AlphaBridge is running on this site right now; nothing was changed. Wait a moment, then send the call again.', 'alphabridge-mcp' ) );
		}
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::role_lock_name() ) );
		if ( '1' === (string) $got ) {
			self::$role_lock_held = true;
			return true;
		}
		if ( '0' === (string) $got ) {
			return new WP_Error( 'ab_mcp_busy', __( 'Another role change or user deletion through AlphaBridge is running on this site right now; nothing was changed. Wait a moment, then send the call again.', 'alphabridge-mcp' ) );
		}
		// NULL or no answer: the server gave no lock. Going on without one
		// would bring back the very race the lock is there for.
		return new WP_Error( 'ab_mcp_busy', __( 'The database server granted no lock to keep role changes and user deletions apart, so nothing was changed. Send the call again; if this keeps happening, ask the host to check the database connection and its support for named locks (GET_LOCK).', 'alphabridge-mcp' ) );
	}

	/**
	 * The user as the database holds them now, read under the lock. The copy
	 * read before it may be older than a change another call made meanwhile:
	 * judged by it, an account made administrator in between would not count
	 * as one here. clean_user_cache() also renews the users' last_changed, so
	 * the count that follows does not come from the query cache either.
	 *
	 * @param int $id User id.
	 * @return WP_User|false
	 */
	protected static function fresh_user( $id ) {
		clean_user_cache( $id );
		return get_user_by( 'id', $id );
	}

	/**
	 * Does this request's connection still hold the lock? Asked right before
	 * the write: a connection wpdb renewed in between holds none.
	 *
	 * @return true|WP_Error
	 */
	protected static function still_hold_role_lock() {
		global $wpdb;
		$mine = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', self::role_lock_name() ) );
		if ( '1' === (string) $mine ) {
			return true;
		}
		return new WP_Error( 'ab_mcp_busy', __( 'The lock that keeps role changes and user deletions apart was no longer held when the change was due (the database connection may have been renewed), so nothing was changed. Send the call again; if this keeps happening, ask the host to check the database connection.', 'alphabridge-mcp' ) );
	}

	/**
	 * Take the administrator role from an account about to be deleted, and
	 * make sure it is gone: read back, since a plugin hooked to the change can
	 * fail or keep the role (Codex, round 7).
	 *
	 * @param WP_User $user The account, read under the lock.
	 * @return true|array|WP_Error True when the role is gone; otherwise the answer.
	 */
	private static function take_admin_role( $user ) {
		try {
			$user->remove_role( 'administrator' );
		} catch ( Throwable $e ) {
			return self::admin_delete_outcome( (int) $user->ID, false );
		}
		$now = self::fresh_user( (int) $user->ID );
		if ( $now && in_array( 'administrator', (array) $now->roles, true ) ) {
			return self::admin_delete_outcome( (int) $user->ID, false );
		}
		return true;
	}

	/**
	 * The answer when deleting an administrator did not run to its end: the
	 * state the site is in, read back, decides it, never what was meant to
	 * happen.
	 *
	 *  - The account is gone (on multisite: no longer a member of this site):
	 *    the deletion happened and something after it failed, a plugin's hook
	 *    on deleted_user for instance. Nothing is rolled back; giving the role
	 *    back would make a network account a member of this site again.
	 *  - The account still holds the administrator role: nothing was deleted.
	 *  - Otherwise the role taken before the deletion is given back, and the
	 *    answer says whether it took.
	 *
	 * @param int  $id           User id.
	 * @param bool $deletion_ran Whether wp_delete_user() was called.
	 * @return array|WP_Error
	 */
	private static function admin_delete_outcome( $id, $deletion_ran ) {
		$user = self::fresh_user( $id );
		$gone = ! $user || ( is_multisite() && ! is_user_member_of_blog( $id ) );
		if ( $deletion_ran && $gone ) {
			return array(
				'deleted' => true,
				'id'      => $id,
				'warning'   => __( 'The account was removed, but code that runs after a deletion (a plugin\'s hook) failed; what that code should have done may be missing.', 'alphabridge-mcp' ),
				'undo'      => null,
				'undo_note' => AB_MCP_Undo_Objects::deleted_for_good( 'wp_delete_user' ),
			);
		}
		if ( $user && in_array( 'administrator', (array) $user->roles, true ) ) {
			return new WP_Error( 'ab_mcp_delete_failed', __( 'Delete failed: the administrator role could not be taken from the account first (a plugin keeps it, or failed while it was taken), so nothing was deleted and the account keeps the role. Check which plugin acts on role changes, then try again.', 'alphabridge-mcp' ) );
		}
		if ( ! self::give_back_admin( $id ) ) {
			return new WP_Error( 'ab_mcp_delete_failed', __( 'Delete failed, and the administrator role taken from the account before deleting it could not be given back: the account still exists, without the administrator role. Give it back under Users in wp-admin.', 'alphabridge-mcp' ) );
		}
		return new WP_Error( 'ab_mcp_delete_failed', __( 'Delete failed. The administrator role taken from the account before deleting it has been given back. Try again, or delete the account under Users in wp-admin.', 'alphabridge-mcp' ) );
	}

	/**
	 * Give the administrator role back to an account delete_user() took it
	 * from before a deletion that did not happen, and say whether it holds
	 * it again.
	 *
	 * @param int $id User id.
	 * @return bool
	 */
	private static function give_back_admin( $id ) {
		$user = self::fresh_user( $id );
		if ( ! $user ) {
			return false;
		}
		try {
			$user->add_role( 'administrator' );
		} catch ( Throwable $e ) {
			// Read back below all the same: the role may have been written
			// before a plugin's hook on it failed.
			unset( $e );
		}
		$user = self::fresh_user( $id );
		return $user && in_array( 'administrator', (array) $user->roles, true );
	}

	/**
	 * Release the lock taken by take_role_lock(). Only a lock this request
	 * holds: RELEASE_LOCK() frees no other connection's lock anyway.
	 */
	protected static function release_role_lock() {
		global $wpdb;
		if ( ! self::$role_lock_held ) {
			return;
		}
		self::$role_lock_held = false;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::role_lock_name() ) );
	}

	/**
	 * Register tools.
	 *
	 * @param AB_MCP_Tool_Registry $r Registry.
	 */
	public static function register( AB_MCP_Tool_Registry $r ) {

		/* ---------------- Users ---------------- */

		$r->register(
			'wp_list_users',
			array(
				'description' => 'List the user accounts of this site, one page at a time, optionally filtered by role or a search term. Returns users (id, login, email, name = display name, roles) and total, the number of matches across all pages. Needs list_users. One account with first and last name and registration date: wp_get_user; profile fields: wp_get_user_meta.',
				'capability'  => 'list_users',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'role'     => array( 'type' => 'string', 'description' => 'Only users with this role slug, e.g. administrator, editor, author, contributor, subscriber or a role a plugin added. Omitted: every role.' ),
						'search'   => array( 'type' => 'string', 'description' => 'WordPress picks the fields by the term: with "@" the email, starting with http:// or https:// the URL (on a large multisite network the general fields instead), a number the login (or the exact id), otherwise login, email, URL, nicename and display name; matched anywhere in the field. Omitted: no filter.' ),
						'per_page' => array( 'type' => 'integer', 'description' => 'Users per page, 1 to 100 (values outside are clamped). Default 20.' ),
						'page'     => array( 'type' => 'integer', 'description' => 'Page number, starting at 1. Default 1.' ),
					),
				),
				'callback'    => array( __CLASS__, 'list_users' ),
			)
		);

		$r->register(
			'wp_get_user',
			array(
				'description' => 'Get one user account by id, login or email. Returns id, login, email, name (display name), first_name, last_name, roles and registered (site time with UTC offset). Needs list_users. No match, or none of the three given, is a not-found error; an account known only by part of its name is found with wp_list_users (search). User meta is read by wp_get_user_meta.',
				'capability'  => 'list_users',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'    => array( 'type' => 'integer', 'description' => 'User id. Takes precedence over login and email.' ),
						'login' => array( 'type' => 'string', 'description' => 'Username (user_login), exact. Used when id is not given.' ),
						'email' => array( 'type' => 'string', 'description' => 'Email address, exact. Used when neither id nor login is given.' ),
					),
				),
				'callback'    => array( __CLASS__, 'get_user' ),
			)
		);

		$r->register(
			'wp_create_user',
			array(
				'description' => 'Create a new user account with a role. Returns created, id and generated_password (the password made up when none was given, shown only in this answer; null otherwise). A login or email already in use is refused by WordPress, so a repeated call creates no second account. Needs create_users; naming a role needs promote_users, and the role has to be one the account may assign (editable_roles; an unknown role is refused): on multisite only a super admin gives administrator, and an account that is neither a single-site administrator nor a super admin cannot give a role with capabilities it lacks itself. Without a role the user becomes a subscriber. Existing accounts change with wp_update_user. No undo point is recorded.',
				'capability'  => 'create_users',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'username', 'email' ),
					'properties' => array(
						'username'   => array( 'type' => 'string', 'description' => 'Login name; WordPress strips characters it does not allow in usernames.' ),
						'email'      => array( 'type' => 'string', 'description' => 'Email address; an invalid one is refused.' ),
						'password'   => array( 'type' => 'string', 'description' => 'Password. Omitted: a 20-character password is generated and returned once as generated_password.' ),
						'role'       => array( 'type' => 'string', 'description' => 'Role slug, e.g. editor. Default "subscriber".' ),
						'first_name' => array( 'type' => 'string', 'description' => 'Optional first name.' ),
						'last_name'  => array( 'type' => 'string', 'description' => 'Optional last name.' ),
					),
				),
				'callback'    => array( __CLASS__, 'create_user' ),
			)
		);

		$r->register(
			'wp_update_user',
			array(
				'description' => 'Update an existing user account: email, first and last name, role or password. Only the fields given change. Returns updated and id. Needs edit_users and the right to edit this user; a role change needs promote_users and the right to promote this user, takes only roles the account may assign (editable_roles) and, for an account that is neither a single-site administrator nor a super admin, cannot give rights the account lacks; demoting the last administrator is refused. A call that sets a role is refused (ab_mcp_busy) and changes nothing while another role change or user deletion runs through AlphaBridge; a call that loses that lock on the way (a renewed database connection) is refused too: noticed before the other fields are saved, it changes nothing, noticed after them, it leaves only the role unchanged, and the answer says which. These checks cover calls through AlphaBridge, not changes made in wp-admin at the same moment. When the password changes, WordPress e-mails a notice to the user\'s address; when the email changes, it e-mails the previous address, naming the new one (the filters send_password_change_email and send_email_change_email can switch these off). No undo point is recorded.',
				'open_world'  => true, // WordPress mails the user when the password or e-mail changes.
				'capability'  => 'edit_users',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id'         => array( 'type' => 'integer', 'description' => 'User id, as wp_list_users or wp_get_user return it.' ),
						'email'      => array( 'type' => 'string', 'description' => 'New email address.' ),
						'role'       => array( 'type' => 'string', 'description' => 'New role slug; replaces the user\'s roles on this site. Empty or omitted: the role stays.' ),
						'first_name' => array( 'type' => 'string', 'description' => 'New first name; an empty string clears it.' ),
						'last_name'  => array( 'type' => 'string', 'description' => 'New last name; an empty string clears it.' ),
						'password'   => array( 'type' => 'string', 'description' => 'New password. Empty or omitted: the password stays.' ),
					),
				),
				'callback'    => array( __CLASS__, 'update_user' ),
			)
		);

		$r->register(
			'wp_delete_user',
			array(
				'description' => 'Delete a user account. With reassign_to, the user\'s posts and links pass to that user; without it, WordPress deletes the user\'s links and entries of post types removed with their author (posts and pages among them; these go to the trash where it is on, the rest is deleted). On multisite the account is only removed from this site, its content handled the same way. Needs delete_users and the right to delete this user; the last administrator is refused. An administrator loses that role first and is deleted after; if the deletion fails, the role is given back and the error says whether that worked; if the account was removed and only a plugin\'s code after it failed, the answer is deleted with a warning. The call is refused (ab_mcp_busy) while another role change or user deletion runs through AlphaBridge; changes made in wp-admin are not part of these checks. Returns deleted and id, and undo: null with undo_note: the account deletion is permanent, so note an account that may be needed again with wp_get_user and wp_get_user_meta first.',
				'capability'  => 'delete_users',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id'          => array( 'type' => 'integer', 'description' => 'Id of the user to delete.' ),
						'reassign_to' => array( 'type' => 'integer', 'description' => 'Id of the user who inherits the deleted user\'s content: another existing user of this site, else the call is refused and nothing is deleted. Omitted or 0: WordPress deletes or trashes it as described above.' ),
					),
				),
				'callback'    => array( __CLASS__, 'delete_user' ),
			)
		);

		/* ---------------- Menus ---------------- */

		$r->register(
			'wp_list_menus',
			array(
				'description' => 'List the navigation menus of this site, all at once: id, name, slug and count (number of items) per menu, under menus. Read-only; needs edit_theme_options. The items of one menu are returned by wp_get_menu.',
				'capability'  => 'edit_theme_options',
				'callback'    => array( __CLASS__, 'list_menus' ),
			)
		);

		$r->register(
			'wp_get_menu',
			array(
				'description' => 'Get one navigation menu with its items, in menu order. Returns menu (id, name) and items, each with item_id, title, url, parent (item_id of the parent item, 0 at top level) and order. Read-only; needs edit_theme_options. An unknown menu is a not-found error; wp_list_menus lists the menus.',
				'capability'  => 'edit_theme_options',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'menu' ),
					'properties' => array( 'menu' => array( 'type' => 'string', 'description' => 'Menu id, slug or name, as wp_list_menus returns them.' ) ),
				),
				'callback'    => array( __CLASS__, 'get_menu' ),
			)
		);

		$r->register(
			'wp_create_menu',
			array(
				'description' => 'Create an empty navigation menu. Returns created, menu_id, saved_fields (the name as stored, read again; saved_differs and warning where WordPress stored something else) and undo: the token for wp_undo, which deletes the menu again while it has no items, no theme location and its name, or null with undo_note saying why. A name another menu already has is refused by WordPress. Items are added by wp_add_menu_item; assigning the menu to a theme location is not part of this tool. Needs edit_theme_options.',
				'capability'  => 'edit_theme_options',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'name' ),
					'properties' => array( 'name' => array( 'type' => 'string', 'description' => 'Menu name. WordPress sanitises it like a term name (HTML is filtered); backslashes are kept.' ) ),
				),
				'callback'    => array( __CLASS__, 'create_menu' ),
			)
		);

		$r->register(
			'wp_add_menu_item',
			array(
				'description' => 'Add a published item to a navigation menu, at the end: a link to a post, page or entry of another post type when object_id is given, otherwise a custom link to url. Returns added, item_id, saved_fields (title, url and parent as wp_get_menu shows them, read again; saved_differs and warning where WordPress stored something else) and undo: the token for wp_undo, which deletes the item again while it is unchanged and has no sub-items, or null with undo_note saying why. Each call adds a new item, also when one with the same target exists. A URL with a scheme WordPress does not allow in menus (javascript:, data:) is refused. Needs edit_theme_options and the right to assign this menu (assign_term), which a site can withhold for single menus. wp_delete_menu_item removes an item.',
				'destructive' => true, // what is published cannot be taken back (PUBLISHING in the core).
				'open_world'  => true, // a published item in an assigned menu is public at once.
				'capability'  => 'edit_theme_options',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'menu', 'title' ),
					'properties' => array(
						'menu'      => array( 'type' => 'string', 'description' => 'Menu id, slug or name, as wp_list_menus returns them.' ),
						'title'     => array( 'type' => 'string', 'description' => 'Label shown in the menu.' ),
						'url'       => array( 'type' => 'string', 'description' => 'Target of a custom link, e.g. https://example.com/, mailto:, tel: or a relative path. Ignored when object_id is given.' ),
						'object_id' => array( 'type' => 'integer', 'description' => 'Post/page id for a content link; its URL follows the post.' ),
						'object'    => array( 'type' => 'string', 'description' => 'Post type of object_id, e.g. page or post. Default "page". Only used with object_id.' ),
						'parent'    => array( 'type' => 'integer', 'description' => 'item_id of the parent item, as wp_get_menu returns it, for a submenu entry. Default 0 (top level).' ),
					),
				),
				'callback'    => array( __CLASS__, 'add_menu_item' ),
			)
		);

		$r->register(
			'wp_delete_menu_item',
			array(
				'description' => 'Remove one item from a navigation menu by its item_id; the linked post or page itself is not touched. Sub-items of the removed item are not removed: they keep it as their parent, and WordPress usually shows them at the menu\'s top level. Returns deleted and item_id. An id that is not a menu item is refused, so a repeated call changes nothing. Needs edit_theme_options and the right to delete this item (delete_post), which a site can withhold for single items. Permanent: the item is deleted, not trashed; the answer carries undo: null with undo_note, so note an item that may be needed again with wp_get_menu first.',
				'capability'  => 'edit_theme_options',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'item_id' ),
					'properties' => array( 'item_id' => array( 'type' => 'integer', 'description' => 'Menu item id, as wp_get_menu returns it.' ) ),
				),
				'callback'    => array( __CLASS__, 'delete_menu_item' ),
			)
		);
	}

	/* --------------------------------------------------------------- users */

	/**
	 * Guard against privilege escalation when assigning a role. Only an
	 * administrator (manage_options) may appoint an administrator, and a
	 * non-administrator may never grant a role that holds capabilities beyond
	 * their own. On multisite, appointing an administrator additionally requires
	 * network super-admin rights, and the role must be one the current context
	 * actually allows (get_editable_roles(), i.e. the editable_roles filter).
	 * Runs as the mapped WordPress user.
	 *
	 * @param string $role Role slug being assigned.
	 * @return true|WP_Error True when allowed, WP_Error otherwise.
	 */
	protected static function can_assign_role( $role ) {
		$role = (string) $role;
		if ( '' === $role ) {
			return true; // No role change requested.
		}
		$role_obj = get_role( $role );
		if ( ! $role_obj ) {
			return new WP_Error( 'ab_mcp_bad_role', __( 'Unknown role. WordPress\' own roles are administrator, editor, author, contributor and subscriber; plugins may add more. Choose one of these, or one a plugin added.', 'alphabridge-mcp' ) );
		}
		// Multisite: only network super admins may appoint an administrator —
		// manage_options on a single site of the network is not enough.
		if ( is_multisite() && ! is_super_admin() && 'administrator' === $role ) {
			return AB_MCP_Guidance::role_refusal(
				__( 'Only a network super admin may assign the administrator role on multisite. Connect with a super admin\'s account.', 'alphabridge-mcp' ),
				array(
					'code'  => 'ab_mcp_forbidden_role',
					'steps' => __( 'A super admin of the network can assign it, or make this account a super admin under Network Admin → Users.', 'alphabridge-mcp' ),
					'url'   => network_admin_url( 'users.php' ),
				)
			);
		}
		// Only roles that are actually editable in this context (honours the
		// editable_roles filter of other plugins), not every registered role.
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$editable = function_exists( 'get_editable_roles' ) ? get_editable_roles() : array();
		// Checked against the list as it is, an empty one included: a plugin
		// whose editable_roles filter leaves nothing assignable means exactly
		// that, as on WordPress's own user screens (Codex, round 8). An empty
		// list used to skip the check and open every role.
		if ( ! isset( $editable[ $role ] ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'This role is not editable in this context. WordPress or a plugin (filter editable_roles) keeps it out of reach for your account; ask an administrator.', 'alphabridge-mcp' ), array( 'code' => 'ab_mcp_forbidden_role' ) );
		}
		// Full trust only for a single-site administrator or a network super
		// admin. A per-site admin on multisite has manage_options but is NOT a
		// super admin, so they must still pass the capability-subset check below
		// — otherwise they could assign a custom role carrying network-level or
		// other capabilities they do not themselves hold.
		if ( current_user_can( 'manage_options' ) && ( ! is_multisite() || is_super_admin() ) ) {
			return true;
		}
		// A non-administrator may never appoint an administrator.
		if ( 'administrator' === $role ) {
			return AB_MCP_Guidance::role_refusal( __( 'You are not allowed to assign the administrator role. Ask an administrator.', 'alphabridge-mcp' ), array( 'code' => 'ab_mcp_forbidden_role' ) );
		}
		// A non-administrator may not grant a role holding capabilities they lack.
		$me = wp_get_current_user();
		foreach ( (array) $role_obj->capabilities as $cap => $granted ) {
			if ( $granted && ! $me->has_cap( $cap ) ) {
				return AB_MCP_Guidance::role_refusal( __( 'You are not allowed to assign a role with more capabilities than your own. Ask an administrator.', 'alphabridge-mcp' ), array( 'code' => 'ab_mcp_forbidden_role' ) );
			}
		}
		return true;
	}

	/**
	 * Is this user the site's only administrator? Used to prevent lockout when
	 * deleting or demoting an account.
	 *
	 * @param WP_User $user User object.
	 * @return bool
	 */
	protected static function is_last_administrator( $user ) {
		if ( ! in_array( 'administrator', (array) $user->roles, true ) ) {
			return false;
		}
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
				'number' => 2,
			)
		);
		return count( $admins ) <= 1;
	}

	/**
	 * List users.
	 *
	 * @param array $a Args.
	 * @return array
	 */
	public static function list_users( $a ) {
		$per_page = self::clamp( self::i( $a, 'per_page', 20 ), 1, 100 );
		$q        = new WP_User_Query(
			array(
				'role'   => self::s( $a, 'role', '' ),
				'search' => self::s( $a, 'search', '' ) ? '*' . self::s( $a, 'search' ) . '*' : '',
				'number' => $per_page,
				'paged'  => max( 1, self::i( $a, 'page', 1 ) ),
			)
		);
		$out = array();
		foreach ( $q->get_results() as $u ) {
			$out[] = array(
				'id'    => (int) $u->ID,
				'login' => $u->user_login,
				'email' => $u->user_email,
				'name'  => $u->display_name,
				'roles' => $u->roles,
			);
		}
		return array(
			'users' => $out,
			'total' => (int) $q->get_total(),
		);
	}

	/**
	 * Get user.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function get_user( $a ) {
		$user = false;
		if ( self::i( $a, 'id', 0 ) ) {
			$user = get_user_by( 'id', self::i( $a, 'id' ) );
		} elseif ( self::s( $a, 'login', '' ) ) {
			$user = get_user_by( 'login', self::s( $a, 'login' ) );
		} elseif ( self::s( $a, 'email', '' ) ) {
			$user = get_user_by( 'email', self::s( $a, 'email' ) );
		}
		if ( ! $user ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'User not found. wp_list_users shows the users and their ids.', 'alphabridge-mcp' ) );
		}
		return array(
			'id'         => (int) $user->ID,
			'login'      => $user->user_login,
			'email'      => $user->user_email,
			'name'       => $user->display_name,
			'first_name' => $user->first_name,
			'last_name'  => $user->last_name,
			'roles'      => $user->roles,
			// Site time with its UTC offset, like every other date the tools
			// return; user_registered is stored in UTC.
			'registered' => self::site_time( $user->user_registered ),
		);
	}

	/**
	 * Create user.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function create_user( $a ) {
		$email = self::s( $a, 'email' );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'ab_mcp_bad_email', __( 'Invalid email. Give an address such as name@example.com.', 'alphabridge-mcp' ) );
		}
		// An explicit role choice requires the promote_users capability; the
		// implicit "subscriber" default does not.
		$role_requested = array_key_exists( 'role', $a ) ? self::s( $a, 'role', '' ) : '';
		if ( '' !== $role_requested && ! current_user_can( 'promote_users' ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Assigning a role requires the promote_users capability. Leave role out to create the user as a subscriber, or ask an administrator.', 'alphabridge-mcp' ) );
		}
		$role = $role_requested ?: 'subscriber';
		// Privilege-escalation guard: never let the caller grant a role above their own.
		$role_check = self::can_assign_role( $role );
		if ( is_wp_error( $role_check ) ) {
			return $role_check;
		}
		$password = self::s( $a, 'password', '' ) ?: wp_generate_password( 20, true );
		$id       = wp_insert_user(
			array(
				'user_login' => sanitize_user( self::s( $a, 'username' ) ),
				'user_email' => $email,
				'user_pass'  => $password,
				'role'       => $role,
				'first_name' => self::s( $a, 'first_name', '' ),
				'last_name'  => self::s( $a, 'last_name', '' ),
			)
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return array(
			'created'            => true,
			'id'                 => (int) $id,
			'generated_password' => self::s( $a, 'password', '' ) ? null : $password,
		);
	}

	/**
	 * Update user.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function update_user( $a ) {
		$id   = self::i( $a, 'id' );
		$user = get_user_by( 'id', $id );
		if ( ! $user ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'User not found. wp_list_users shows the users and their ids.', 'alphabridge-mcp' ) );
		}
		// Per-object capability check (honours meta-cap mapping and multisite rules).
		if ( ! current_user_can( 'edit_user', $id ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'You are not allowed to edit this user. Ask an administrator.', 'alphabridge-mcp' ) );
		}
		$role_requested = array_key_exists( 'role', $a ) ? self::s( $a, 'role' ) : '';
		if ( '' !== $role_requested ) {
			// Changing a user's role requires the promote_users capability, and
			// for this user the meta capability promote_user, which WordPress
			// maps per user (on multisite, for instance, not for a user who is
			// not a member of this site), as its own screens and REST API ask.
			if ( ! current_user_can( 'promote_users' ) || ! current_user_can( 'promote_user', $id ) ) {
				return AB_MCP_Guidance::role_refusal( __( 'You are not allowed to change user roles. Ask an administrator.', 'alphabridge-mcp' ) );
			}
			// Privilege-escalation guard on the requested role.
			$role_check = self::can_assign_role( $role_requested );
			if ( is_wp_error( $role_check ) ) {
				return $role_check;
			}
			// Counting the administrators and writing the role are one step:
			// the lock keeps a second call from demoting the other one between
			// the two (see $role_lock_held). Released below, after the write.
			$lock = self::take_role_lock();
			if ( is_wp_error( $lock ) ) {
				return $lock;
			}
			try {
				$user = self::fresh_user( $id );
				if ( ! $user ) {
					return new WP_Error( 'ab_mcp_not_found', __( 'User not found. wp_list_users shows the users and their ids.', 'alphabridge-mcp' ) );
				}
				// Lockout protection: never demote the last administrator.
				if ( 'administrator' !== $role_requested && self::is_last_administrator( $user ) ) {
					return new WP_Error( 'ab_mcp_last_admin', __( 'Refusing to demote the last administrator account. Make another account administrator first (wp_update_user with role administrator).', 'alphabridge-mcp' ) );
				}
				$held = self::still_hold_role_lock();
				if ( is_wp_error( $held ) ) {
					return $held;
				}
				return self::write_user( $id, $a, $role_requested );
			} finally {
				self::release_role_lock();
			}
		}
		return self::write_user( $id, $a, '' );
	}

	/**
	 * The write of update_user(), once its checks have passed.
	 *
	 * @param int    $id             User id.
	 * @param array  $a              Args.
	 * @param string $role_requested Role to set, '' for none.
	 * @return array|WP_Error
	 */
	private static function write_user( $id, $a, $role_requested ) {
		$data = array( 'ID' => $id );
		foreach ( array(
			'email'      => 'user_email',
			'first_name' => 'first_name',
			'last_name'  => 'last_name',
		) as $in => $out ) {
			if ( array_key_exists( $in, $a ) ) {
				$data[ $out ] = self::s( $a, $in );
			}
		}
		if ( array_key_exists( 'password', $a ) && self::s( $a, 'password' ) ) {
			$data['user_pass'] = self::s( $a, 'password' );
		}
		// The role never goes to wp_update_user(): write_role() sets it after
		// the other fields. An empty role (also null or an array, which
		// self::s() turns into "") skipped the checks above as "no role
		// change" but used to reach wp_update_user() all the same, and
		// WP_User::set_role('') removes every role: role:"" stripped the last
		// administrator of theirs past the promote_users and
		// last-administrator checks. Empty means "leave the role alone", as an
		// empty password does.
		$res = wp_update_user( $data );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( '' !== $role_requested ) {
			$set = self::write_role( $id, $role_requested );
			if ( is_wp_error( $set ) ) {
				return $set;
			}
		}
		return array(
			'updated' => true,
			'id'      => $id,
		);
	}

	/**
	 * The role of update_user(), once wp_update_user() has saved the account
	 * with the other fields: one short write right after the lock is checked,
	 * as delete_user() takes the administrator role. WordPress's REST API, too,
	 * adds the roles after wp_update_user() (it empties them inside it, which
	 * this does not). Handed to wp_update_user(), the role came last, after
	 * the account's row, every meta field and the hooks on them, all after
	 * the check: a connection renewed meanwhile, and the lock gone with it,
	 * did not stop the role (Codex, 24.10.2026). profile_update runs once,
	 * as before, now before the role changes; set_user_role once where the
	 * role does change (not where it is refused here, nor where the account
	 * has that role alone already, as WP_User::set_role() returns early).
	 *
	 * The account is read again first (its cache may hold a copy a hook of
	 * wp_update_user() read), and the lock is checked after that read, which
	 * can renew the connection too.
	 *
	 * @param int    $id   User id.
	 * @param string $role The role, past the checks of update_user().
	 * @return true|WP_Error
	 */
	private static function write_role( $id, $role ) {
		$user = self::fresh_user( $id );
		if ( ! $user ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'User not found. wp_list_users shows the users and their ids.', 'alphabridge-mcp' ) );
		}
		$held = self::still_hold_role_lock();
		if ( is_wp_error( $held ) ) {
			// wp_update_user() has run: the account is saved, also where the
			// call named no other field (WordPress fills in what is missing,
			// a nickname for one).
			return new WP_Error( 'ab_mcp_busy', __( 'The role was not changed: the lock that keeps role changes and user deletions apart was no longer held when it was due (the database connection may have been renewed). The account was saved before that, with the other fields of this call. Send the call again with the role; if this keeps happening, ask the host to check the database connection.', 'alphabridge-mcp' ) );
		}
		$user->set_role( $role );
		clean_user_cache( $id );
		return true;
	}

	/**
	 * Delete user.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function delete_user( $a ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$id   = self::i( $a, 'id' );
		$user = get_user_by( 'id', $id );
		if ( ! $user ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'User not found. wp_list_users shows the users and their ids.', 'alphabridge-mcp' ) );
		}
		// Per-object capability check (honours meta-cap mapping and multisite rules).
		if ( ! current_user_can( 'delete_user', $id ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'You are not allowed to delete this user. Ask an administrator.', 'alphabridge-mcp' ) );
		}
		// The heir of the content, checked before anything changes, as the
		// REST API of WordPress checks it: another account that exists (on
		// multisite: a member of this site). Without this a mistyped id, the
		// account's own or a negative one, left the content with an author
		// that does not exist (Codex, round 8). 0 or none: no heir.
		$reassign = self::i( $a, 'reassign_to', 0 );
		if ( 0 !== $reassign ) {
			if ( $reassign < 0 || $reassign === $id || ! get_user_by( 'id', $reassign ) || ( is_multisite() && ! is_user_member_of_blog( $reassign ) ) ) {
				return new WP_Error( 'ab_mcp_bad_reassign', __( 'reassign_to has to name another existing user of this site, or be left out; nothing was deleted. wp_list_users shows the users and their ids.', 'alphabridge-mcp' ) );
			}
		}
		// Counting the administrators and deleting are one step, under the
		// lock a role change takes too (see $role_lock_held).
		$lock = self::take_role_lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			$user = self::fresh_user( $id );
			if ( ! $user ) {
				return new WP_Error( 'ab_mcp_not_found', __( 'User not found. wp_list_users shows the users and their ids.', 'alphabridge-mcp' ) );
			}
			// The heir again, read fresh under the lock: a second call through
			// AlphaBridge can have deleted it between the check above and the
			// lock, and WordPress would hand the content to an id nobody has
			// (Codex, review of 24.10.2026).
			if ( 0 !== $reassign && ( ! self::fresh_user( $reassign ) || ( is_multisite() && ! is_user_member_of_blog( $reassign ) ) ) ) {
				return new WP_Error( 'ab_mcp_bad_reassign', __( 'reassign_to has to name another existing user of this site, or be left out; nothing was deleted. wp_list_users shows the users and their ids.', 'alphabridge-mcp' ) );
			}
			// Never let the last administrator be deleted (lockout protection).
			if ( self::is_last_administrator( $user ) ) {
				return new WP_Error( 'ab_mcp_last_admin', __( 'Refusing to delete the last administrator account. Make another account administrator first (wp_update_user with role administrator).', 'alphabridge-mcp' ) );
			}
			$held = self::still_hold_role_lock();
			if ( is_wp_error( $held ) ) {
				return $held;
			}
			// An administrator loses the role first, in one short write right
			// after the check above, and only then is deleted. WordPress
			// removes the account, and the role with it, only after it has
			// deleted or reassigned the content and run every plugin's hooks,
			// which can take long; had the connection, and the lock with it,
			// gone in that time, a second call could have counted this account
			// as an administrator still and removed the last other one (Codex,
			// round 6). Without the role it no longer counts from the start. On
			// multisite the role is this site's, as the count is; a super
			// admin stays one, as wp_delete_user() leaves that alone too.
			$was_admin = in_array( 'administrator', (array) $user->roles, true );
			if ( $was_admin ) {
				$taken = self::take_admin_role( $user );
				if ( true !== $taken ) {
					return $taken;
				}
			}
			try {
				$res = wp_delete_user( $id, 0 !== $reassign ? $reassign : null );
			} catch ( Throwable $e ) {
				if ( ! $was_admin ) {
					throw $e;
				}
				return self::admin_delete_outcome( $id, true );
			}
			if ( ! $res && $was_admin ) {
				return self::admin_delete_outcome( $id, true );
			}
		} finally {
			self::release_role_lock();
		}
		if ( ! $res ) {
			return new WP_Error( 'ab_mcp_delete_failed', __( 'Delete failed. Try again, or delete the account under Users in wp-admin.', 'alphabridge-mcp' ) );
		}
		return array(
			'deleted'   => true,
			'id'        => $id,
			'undo'      => null,
			'undo_note' => AB_MCP_Undo_Objects::deleted_for_good( 'wp_delete_user' ),
		);
	}

	/* --------------------------------------------------------------- menus */

	/**
	 * List menus.
	 *
	 * @return array
	 */
	public static function list_menus() {
		$menus = wp_get_nav_menus();
		$out   = array();
		foreach ( $menus as $m ) {
			$out[] = array(
				'id'    => (int) $m->term_id,
				'name'  => $m->name,
				'slug'  => $m->slug,
				'count' => (int) $m->count,
			);
		}
		return array( 'menus' => $out );
	}

	/**
	 * Get menu with items.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function get_menu( $a ) {
		$menu = wp_get_nav_menu_object( self::s( $a, 'menu' ) );
		if ( ! $menu ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Menu not found. wp_list_menus shows the menus.', 'alphabridge-mcp' ) );
		}
		$items = wp_get_nav_menu_items( $menu->term_id );
		$out   = array();
		if ( $items ) {
			foreach ( $items as $it ) {
				$out[] = array(
					'item_id' => (int) $it->ID,
					'title'   => $it->title,
					'url'     => $it->url,
					'parent'  => (int) $it->menu_item_parent,
					'order'   => (int) $it->menu_order,
				);
			}
		}
		return array(
			'menu'  => array(
				'id'   => (int) $menu->term_id,
				'name' => $menu->name,
			),
			'items' => $out,
		);
	}

	/**
	 * Create menu.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function create_menu( $a ) {
		// Slashed, like add_menu_item: wp_create_nav_menu() ends in
		// wp_insert_term(), which unslashes what it is given. Without this a
		// name like "C:\Docs" is stored as "C:Docs".
		$id = wp_create_nav_menu( wp_slash( self::s( $a, 'name' ) ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		// The read-back and an undo point that deletes the menu again
		// (since 4.13.0): a menu is a term of nav_menu.
		return array_merge(
			array(
				'created' => true,
				'menu_id' => (int) $id,
			),
			AB_MCP_Undo_Terms::created( 'wp_create_menu', (int) $id, 'nav_menu', array( 'name' => self::s( $a, 'name' ) ), 'wp_get_menu', sprintf( 'new menu #%d', (int) $id ) )
		);
	}

	/**
	 * Add menu item.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function add_menu_item( $a ) {
		$menu = wp_get_nav_menu_object( self::s( $a, 'menu' ) );
		if ( ! $menu ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Menu not found. wp_list_menus shows the menus.', 'alphabridge-mcp' ) );
		}
		// And the right to assign this one menu, as the REST API asks it for
		// the menu a new item goes into (WP_REST_Posts_Controller::
		// check_assign_terms_permission(): assign_term on the nav_menu term).
		// It goes through map_meta_cap, where a site can withhold single menus
		// from an account that may edit the others; without such a rule it is
		// edit_theme_options, as the tool asks already.
		if ( ! current_user_can( 'assign_term', (int) $menu->term_id ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot add items to this specific menu. Ask someone who may edit it, or an administrator.', 'alphabridge-mcp' ) );
		}
		$object_id = self::i( $a, 'object_id', 0 );
		// wp_update_nav_menu_item() hands its array to wp_insert_post(), which
		// unslashes it — so the values have to go in SLASHED. Without this a
		// title like "C:\Docs" is stored as "C:Docs", silently different from
		// what the caller asked for.
		$data = array(
			'menu-item-title'     => wp_slash( self::s( $a, 'title' ) ),
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => self::i( $a, 'parent', 0 ),
		);
		if ( $object_id ) {
			$data['menu-item-object-id'] = $object_id;
			$data['menu-item-object']    = self::s( $a, 'object', 'page' ) ?: 'page';
			$data['menu-item-type']      = 'post_type';
		} else {
			$raw   = self::s( $a, 'url' );
			$clean = esc_url_raw( $raw );
			// A scheme WordPress does not allow (javascript:, data:, …) comes
			// back empty. Creating the item anyway would leave a visibly broken
			// entry and report it as a success — refuse instead of pretending.
			if ( '' !== $raw && '' === $clean ) {
				return new WP_Error(
					'ab_mcp_bad_url',
					__( 'That link uses a scheme WordPress does not allow in menus, so the item would have no URL at all. Use a scheme WordPress allows, such as https, mailto or tel, or a relative link.', 'alphabridge-mcp' )
				);
			}
			$data['menu-item-url']  = wp_slash( $clean );
			$data['menu-item-type'] = 'custom';
		}
		$item_id = wp_update_nav_menu_item( $menu->term_id, 0, $data );
		if ( is_wp_error( $item_id ) ) {
			return $item_id;
		}
		// What was sent, as WordPress was asked to store it; the read-back
		// and an undo point that deletes the item again (since 4.13.0).
		$asked = array(
			'title'  => self::s( $a, 'title' ),
			'parent' => self::i( $a, 'parent', 0 ),
		);
		if ( $object_id ) {
			$asked['object_id'] = $object_id;
			$asked['object']    = $data['menu-item-object'];
		} else {
			$asked['url'] = $clean;
		}
		return array_merge(
			array(
				'added'   => true,
				'item_id' => (int) $item_id,
			),
			AB_MCP_Undo_Menu_Items::added( (int) $item_id, $asked, sprintf( 'menu item #%d in menu #%d', (int) $item_id, (int) $menu->term_id ) )
		);
	}

	/**
	 * Delete menu item.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function delete_menu_item( $a ) {
		// And the right to delete this one item, as the REST API asks it
		// (WP_REST_Posts_Controller::check_delete_permission(): delete_post),
		// through map_meta_cap like assign_term in add_menu_item().
		if ( is_nav_menu_item( self::i( $a, 'item_id' ) ) && ! current_user_can( 'delete_post', self::i( $a, 'item_id' ) ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot delete this specific menu item. Ask someone who may edit the menu, or an administrator.', 'alphabridge-mcp' ) );
		}
		$res = is_nav_menu_item( self::i( $a, 'item_id' ) ) ? wp_delete_post( self::i( $a, 'item_id' ), true ) : false;
		if ( ! $res ) {
			return new WP_Error( 'ab_mcp_delete_failed', __( 'Menu item not found or delete failed. wp_get_menu shows the items of a menu with their ids.', 'alphabridge-mcp' ) );
		}
		return array(
			'deleted'   => true,
			'item_id'   => self::i( $a, 'item_id' ),
			'undo'      => null,
			'undo_note' => AB_MCP_Undo_Objects::deleted_for_good( 'wp_delete_menu_item' ),
		);
	}
}
