<?php
/**
 * User meta, term meta and application-password tools. Rounds out the metadata
 * surface (post meta already exists) and adds credential management for automation.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tools-base.php';

/**
 * Class AB_MCP_Tools_Meta_Auth
 */
class AB_MCP_Tools_Meta_Auth extends AB_MCP_Tools_Base {

	/**
	 * Fixed allow-list of user-meta keys this tool may expose. A positive list
	 * (rather than a blocklist) means only these standard, non-sensitive WordPress
	 * profile fields are ever readable over MCP — capabilities, roles, session
	 * tokens, hashed passwords, application passwords and any third-party secret
	 * stored in user meta are simply not on the list, so they can never leak.
	 *
	 * @return string[]
	 */
	protected static function allowed_user_meta_keys() {
		return array(
			'first_name',
			'last_name',
			'nickname',
			'description',
			'locale',
			'rich_editing',
			'syntax_highlighting',
			'show_admin_bar_front',
			'admin_color',
		);
	}

	/**
	 * Register tools.
	 *
	 * @param AB_MCP_Tool_Registry $r Registry.
	 */
	public static function register( AB_MCP_Tool_Registry $r ) {

		$r->register(
			'wp_get_user_meta',
			array(
				'description' => 'Get standard profile fields (user meta) for a user: one field, or all standard fields. Only a fixed list of non-sensitive profile keys is available.',
				'capability'  => 'list_users',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'user_id' ),
					'properties' => array(
						'user_id' => array( 'type' => 'integer' ),
						'key'     => array( 'type' => 'string' ),
					),
				),
				'callback'    => array( __CLASS__, 'get_user_meta_tool' ),
			)
		);

		$r->register(
			'wp_get_term_meta',
			array(
				'description' => 'Get term meta: one key, or all public meta for a term.',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'term_id' ),
					'properties' => array(
						'term_id' => array( 'type' => 'integer' ),
						'key'     => array( 'type' => 'string' ),
					),
				),
				'callback'    => array( __CLASS__, 'get_term_meta_tool' ),
			)
		);

		$r->register(
			'wp_list_application_passwords',
			array(
				'description' => 'List a user\'s application passwords (names, created/last-used, never the secret).',
				'capability'  => 'list_users',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'user_id' ),
					'properties' => array( 'user_id' => array( 'type' => 'integer' ) ),
				),
				'callback'    => array( __CLASS__, 'list_app_passwords' ),
			)
		);
	}

	/* --------------------------------------------------------------- user meta */

	/**
	 * Get user meta.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function get_user_meta_tool( $a ) {
		$id = self::i( $a, 'user_id' );
		if ( ! get_user_by( 'id', $id ) ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'User not found: no user has this id. The authors\' ids are in the answers of wp_list_posts and wp_get_post.', 'alphabridge-mcp' ) );
		}
		if ( ! current_user_can( 'edit_user', $id ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot read this user\'s meta: that takes the right to edit this user, which every user has for their own profile and administrators for other users (on a multisite network, super admins). Connect with such an account.', 'alphabridge-mcp' ) );
		}
		$allowed = self::allowed_user_meta_keys();
		$key     = self::s( $a, 'key', '' );
		if ( '' !== $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				return new WP_Error(
					'ab_mcp_protected_meta',
					sprintf(
						/* translators: %s: comma-separated meta keys */
						__( 'Only standard profile fields are available for user meta: %s. Pass one of them as key, or leave key out to read all of them.', 'alphabridge-mcp' ),
						implode( ', ', $allowed )
					)
				);
			}
			// Per-key capability: honours auth_callback rules from register_meta().
			if ( ! current_user_can( 'edit_user_meta', $id, $key ) ) {
				return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot access this meta key: WordPress says it may not edit it, usually because the plugin that registered the key restricts it. Connect with an account that may edit it, such as an administrator.', 'alphabridge-mcp' ) );
			}
			return array(
				'user_id' => $id,
				'key'     => $key,
				'value'   => get_user_meta( $id, $key, true ),
			);
		}
		$flat = array();
		foreach ( $allowed as $k ) {
			// Per-key capability: honours auth_callback rules from register_meta().
			if ( ! current_user_can( 'edit_user_meta', $id, $k ) ) {
				continue;
			}
			$flat[ $k ] = get_user_meta( $id, $k, true );
		}
		return array(
			'user_id' => $id,
			'meta'    => $flat,
		);
	}

	/* --------------------------------------------------------------- term meta */

	/**
	 * Get term meta.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function get_term_meta_tool( $a ) {
		$id   = self::i( $a, 'term_id' );
		$term = get_term( $id );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Term not found: no term has this id. Look it up with wp_list_terms.', 'alphabridge-mcp' ) );
		}
		$tax_obj = get_taxonomy( $term->taxonomy );
		if ( ! $tax_obj || ! current_user_can( $tax_obj->cap->assign_terms ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot read the meta of terms in this taxonomy: that takes the right to assign its terms. Connect with an account that has it, such as an editor or an administrator.', 'alphabridge-mcp' ) );
		}
		$key = self::s( $a, 'key', '' );
		if ( '' !== $key ) {
			if ( '_' === substr( $key, 0, 1 ) || is_protected_meta( $key, 'term' ) || self::is_sensitive_meta_key( $key ) ) {
				return new WP_Error( 'ab_mcp_protected_meta', __( 'This meta key is protected: keys that start with "_", that WordPress marks as protected, or that look like a credential are not read through this tool; they belong to the plugin that owns them.', 'alphabridge-mcp' ) );
			}
			// Per-key capability: honours auth_callback rules from register_meta().
			// WordPress first maps it to edit_term on the term itself (case
			// 'edit_term_meta', then 'edit_term' in wp-includes/capabilities.php),
			// while this tool lets in accounts that may only assign terms: that,
			// not a plugin, is the usual reason.
			if ( ! current_user_can( 'edit_term_meta', $id, $key ) ) {
				return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot access this meta key: that takes the right to edit the term itself (for categories and tags, the manage_categories capability, which editors and administrators have), and the plugin that registered the key can restrict it further. Connect with an account that may edit the term and the key, such as an administrator.', 'alphabridge-mcp' ) );
			}
			return array(
				'term_id' => $id,
				'key'     => $key,
				'value'   => get_term_meta( $id, $key, true ),
			);
		}
		$all  = get_term_meta( $id );
		$flat = array();
		foreach ( $all as $k => $v ) {
			if ( '_' === substr( $k, 0, 1 ) || is_protected_meta( $k, 'term' ) || self::is_sensitive_meta_key( $k )
				|| ! current_user_can( 'edit_term_meta', $id, $k ) ) {
				continue;
			}
			$flat[ $k ] = count( $v ) === 1 ? maybe_unserialize( $v[0] ) : array_map( 'maybe_unserialize', $v );
		}
		return array(
			'term_id' => $id,
			'meta'    => $flat,
		);
	}

	/* --------------------------------------------------------------- app passwords */

	/**
	 * Guard: application passwords available + editable. Its texts name no
	 * single action: the Pro add-on runs the same guard before creating and
	 * revoking application passwords.
	 *
	 * @param int $user_id User id.
	 * @return true|WP_Error
	 */
	protected static function app_pw_guard( $user_id ) {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return new WP_Error( 'ab_mcp_no_app_pw', __( 'Application passwords require WordPress 5.6 or later; update WordPress to use them.', 'alphabridge-mcp' ) );
		}
		if ( function_exists( 'wp_is_application_passwords_available' ) && ! wp_is_application_passwords_available() ) {
			return new WP_Error( 'ab_mcp_app_pw_off', __( 'Application passwords are disabled on this site: WordPress offers them only over HTTPS or in a local environment, and a plugin can switch them off with the wp_is_application_passwords_available filter.', 'alphabridge-mcp' ) );
		}
		if ( ! get_user_by( 'id', $user_id ) ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'User not found: no user has this id. The authors\' ids are in the answers of wp_list_posts and wp_get_post.', 'alphabridge-mcp' ) );
		}
		return true;
	}

	/**
	 * List application passwords.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function list_app_passwords( $a ) {
		$id = self::i( $a, 'user_id' );
		$g  = self::app_pw_guard( $id );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		if ( ! current_user_can( 'edit_user', $id ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot view this user\'s application passwords: that takes the right to edit this user, which every user has for their own profile and administrators for other users (on a multisite network, super admins). Connect with such an account.', 'alphabridge-mcp' ) );
		}
		$items = WP_Application_Passwords::get_user_application_passwords( $id );
		$out   = array();
		foreach ( (array) $items as $it ) {
			$out[] = array(
				'uuid'      => isset( $it['uuid'] ) ? $it['uuid'] : null,
				'name'      => isset( $it['name'] ) ? $it['name'] : null,
				'created'   => isset( $it['created'] ) ? gmdate( 'c', (int) $it['created'] ) : null,
				'last_used' => ! empty( $it['last_used'] ) ? gmdate( 'c', (int) $it['last_used'] ) : null,
			);
		}
		return array(
			'user_id'   => $id,
			'passwords' => $out,
		);
	}
}
