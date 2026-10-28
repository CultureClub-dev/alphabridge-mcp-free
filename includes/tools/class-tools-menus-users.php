<?php
/**
 * Menus and the reading of user accounts.
 *
 * Free since 4.7.0. Until then these tools came with AlphaBridge MCP Pro only;
 * the code is Pro's, unchanged in what it does. Pro registers its own copy of
 * the same names over these (as it does with wp_get_post and others), so a
 * site with Pro runs Pro's; creating, changing and deleting accounts stays in
 * Pro.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tools-base.php';

/**
 * Class AB_MCP_Tools_Menus_Users
 */
class AB_MCP_Tools_Menus_Users extends AB_MCP_Tools_Base {

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
				'description' => 'Create an empty navigation menu. Returns created and menu_id. A name another menu already has is refused by WordPress. Items are added by wp_add_menu_item; assigning the menu to a theme location is not part of this tool. Needs edit_theme_options.',
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
				'description' => 'Add a published item to a navigation menu, at the end: a link to a post, page or entry of another post type when object_id is given, otherwise a custom link to url. Returns added and item_id. Each call adds a new item, also when one with the same target exists. A URL with a scheme WordPress does not allow in menus (javascript:, data:) is refused. Needs edit_theme_options and the right to assign this menu (assign_term), which a site can withhold for single menus. No undo point is recorded; wp_delete_menu_item removes an item.',
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
				'description' => 'Remove one item from a navigation menu by its item_id; the linked post or page itself is not touched. Sub-items of the removed item are not removed: they keep it as their parent, and WordPress usually shows them at the menu\'s top level. Returns deleted and item_id. An id that is not a menu item is refused, so a repeated call changes nothing. Needs edit_theme_options and the right to delete this item (delete_post), which a site can withhold for single items. Permanent: the item is deleted, not trashed, and no undo point is recorded.',
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
		return array(
			'created' => true,
			'menu_id' => (int) $id,
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
		return array(
			'added'   => true,
			'item_id' => (int) $item_id,
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
			'deleted' => true,
			'item_id' => self::i( $a, 'item_id' ),
		);
	}
}
