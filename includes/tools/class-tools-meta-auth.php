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
				'description' => 'Get standard profile fields (user meta) of one user: one field, or all of them. Only a fixed list of non-sensitive keys is available: first_name, last_name, nickname, description, locale, rich_editing, syntax_highlighting, show_admin_bar_front and admin_color; any other key is refused with that list. Returns {user_id, key, value} or {user_id, meta: {key: value}}. Needs list_users and the right to edit that user and each field (in the standard roles: administrators). An unknown user_id returns a not-found error. Read-only.',
				'capability'  => 'list_users',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'user_id' ),
					'properties' => array(
						'user_id' => array( 'type' => 'integer', 'description' => 'User id; authors\' ids appear in the results of wp_list_posts and wp_get_post.' ),
						'key'     => array( 'type' => 'string', 'description' => 'One field from the list above. When omitted, all of them are read.' ),
					),
				),
				'callback'    => array( __CLASS__, 'get_user_meta_tool' ),
			)
		);

		$r->register(
			'wp_get_term_meta',
			array(
				'description' => 'Read custom meta of one term: one key, or all public keys. Keys starting with "_", keys WordPress marks as protected and credential-like keys are not available; without a key, keys the account may not edit are left out too. Returns {term_id, key, value} (with several stored values, the first) or {term_id, meta: {key: value}} (a key with several values gives a list). Needs the right to assign terms of the term\'s taxonomy; one key needs the right to edit the term. An unknown term_id returns a not-found error. Read-only; the term\'s own fields come from wp_get_term.',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'term_id' ),
					'properties' => array(
						'term_id' => array( 'type' => 'integer', 'description' => 'Term id, as wp_list_terms returns it; the taxonomy is not needed.' ),
						'key'     => array( 'type' => 'string', 'description' => 'One meta key to read. When omitted, all public keys are read. A key the term does not have returns an empty value, or the default a plugin registered for it.' ),
					),
				),
				'callback'    => array( __CLASS__, 'get_term_meta_tool' ),
			)
		);

		$r->register(
			'wp_list_application_passwords',
			array(
				'description' => 'List the application passwords of one user: {user_id, passwords: [{uuid, name, created, last_used}]}, dates in ISO 8601 UTC, last_used null when never used; the password itself is not returned. Suited to reviewing which applications can sign in as that user. An empty list means the user has none. Needs list_users and the right to edit that user (in the standard roles: administrators). Where WordPress does not offer application passwords (it needs HTTPS or a local environment), an error says so. Read-only.',
				'capability'  => 'list_users',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'user_id' ),
					'properties' => array( 'user_id' => array( 'type' => 'integer', 'description' => 'WordPress user id; an unknown id returns a not-found error.' ) ),
				),
				'callback'    => array( __CLASS__, 'list_app_passwords' ),
			)
		);

		// The two meta writers came with AlphaBridge MCP Pro only until 4.9.0.
		// Pro registers its own copy of these names over them; the definitions
		// here are Pro's, without its tier. Creating and revoking application
		// passwords stays in Pro.
		$r->register(
			'wp_update_user_meta',
			array(
				'description' => 'Create or overwrite one user meta value (all stored values of the key are replaced). Refused: keys starting with "_", role and capability keys of any site (…capabilities, …user_level), session_tokens, default_password_nonce, use_ssl and credential-like keys; role and password are changed by wp_update_user. Needs edit_users, the right to edit this user and to edit this key; an unknown user is a not-found error. WordPress strips backslashes from the value. Returns updated, user_id and key. No undo point is recorded; wp_get_user_meta reads the value.',
				'capability'  => 'edit_users',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'user_id', 'key' ),
					'properties' => array(
						'user_id' => array( 'type' => 'integer', 'description' => 'User id, as wp_list_users returns it.' ),
						'key'     => array( 'type' => 'string', 'description' => 'Meta key; stored lower-cased with characters other than a-z, 0-9, "_" and "-" removed (sanitize_key).' ),
						'value'   => array( 'description' => 'Scalar or array of scalars (arrays may nest); an array is stored serialized. Omitted: an empty string.' ),
					),
				),
				'callback'    => array( __CLASS__, 'update_user_meta_tool' ),
			)
		);

		$r->register(
			'wp_update_term_meta',
			array(
				'description' => 'Create or overwrite one meta value of a term (category, tag or any taxonomy); all stored values of the key are replaced. Keys starting with "_" and credential-like keys are refused. Needs manage_categories and the right to edit this term\'s meta. WordPress strips backslashes from the value. Returns updated, term_id and key; an unknown term is a not-found error. No undo point is recorded; the term\'s name, slug and description change with wp_update_term.',
				'open_world'  => true, // term fields, as wp_update_term changes the term itself.
				'capability'  => 'manage_categories',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'term_id', 'key' ),
					'properties' => array(
						'term_id' => array( 'type' => 'integer', 'description' => 'Term id, as wp_list_terms returns it.' ),
						'key'     => array( 'type' => 'string', 'description' => 'Meta key; stored lower-cased with characters other than a-z, 0-9, "_" and "-" removed (sanitize_key).' ),
						'value'   => array( 'description' => 'Scalar or array of scalars (arrays may nest); an array is stored serialized. Omitted: an empty string.' ),
					),
				),
				'callback'    => array( __CLASS__, 'update_term_meta_tool' ),
			)
		);
	}

	/**
	 * Only scalars or (nested) arrays of scalars; no objects.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function safe_value( $value ) {
		if ( is_scalar( $value ) || null === $value ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $v ) {
				if ( ! self::safe_value( $v ) ) {
					return false;
				}
			}
			return true;
		}
		return false;
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
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot read this user\'s meta: that takes the right to edit this user, which every user has for their own profile and administrators for other users (on a multisite network, super admins). Connect with such an account.', 'alphabridge-mcp' ) );
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
				return AB_MCP_Guidance::role_refusal( __( 'Your account cannot access this meta key: WordPress says it may not edit it, usually because the plugin that registered the key restricts it. Connect with an account that may edit it, such as an administrator.', 'alphabridge-mcp' ) );
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

	/**
	 * Does this user meta key carry privileges, authentication state or a
	 * stored credential? Writing to any of these over MCP is refused outright,
	 * independently of what the calling user may edit: the roles and
	 * capabilities array is a privilege-escalation path, the session and
	 * password-reset keys are an account-takeover path.
	 *
	 * The key arrives sanitised by sanitize_key(), so it is already lowercase
	 * and free of separators other than "-" and "_".
	 *
	 * AB_MCP_Tools_Meta_Auth_Pro of every Pro release since 4.5.3 declares
	 * this method with the same signature; Pro's tool runs its own.
	 *
	 * @param string $key User meta key.
	 * @return bool
	 */
	protected static function protected_user_meta( $key ) {
		global $wpdb;

		$key = strtolower( trim( (string) $key ) );
		if ( '' === $key ) {
			return true;
		}

		// Underscore-prefixed keys are WordPress's convention for meta that is
		// not meant to be edited through a generic field editor, and plugins put
		// authentication state there — the official Two Factor plugin stores its
		// TOTP secret and backup codes as _two_factor_*. The post and term write
		// tools already refuse these; the user tool must not be the exception.
		if ( '_' === substr( $key, 0, 1 ) ) {
			return true;
		}

		// Roles and capabilities are stored per site as
		// {base_prefix}{blog_id}_capabilities — and without the id on the first
		// site of a network. Matching only the CURRENT site's prefix would let a
		// write set roles on another site of the network, so every site's key is
		// refused. The base prefix is not necessarily "wp_".
		$base = '';
		if ( isset( $wpdb->base_prefix ) ) {
			$base = strtolower( (string) $wpdb->base_prefix );
		} elseif ( isset( $wpdb->prefix ) ) {
			// No base prefix available: derive it by dropping a trailing blog id,
			// so that wp_3_ still protects wp_capabilities and wp_2_capabilities.
			$base = (string) preg_replace( '/[0-9]+_$/', '', strtolower( (string) $wpdb->prefix ) );
		}
		if ( '' === $base ) {
			$base = 'wp_';
		}
		if ( preg_match( '/^' . preg_quote( $base, '/' ) . '(?:[0-9]+_)?(?:capabilities|user_level)$/', $key ) ) {
			return true;
		}

		// Authentication state, regardless of table prefix. use_ssl is included
		// deliberately: it is a security preference (force SSL for this user's
		// admin), so it should not be flipped through an automated tool.
		$fixed = array(
			'session_tokens',
			'_application_passwords',
			'default_password_nonce',
			'use_ssl',
		);
		if ( in_array( $key, $fixed, true ) ) {
			return true;
		}

		// The credential-shaped keys the meta readers refuse as well.
		return self::is_sensitive_meta_key( $key );
	}

	/**
	 * Update user meta.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function update_user_meta_tool( $a ) {
		$id = self::i( $a, 'user_id' );
		if ( ! get_user_by( 'id', $id ) ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'User not found. wp_list_users shows the users and their ids.', 'alphabridge-mcp' ) );
		}
		if ( ! current_user_can( 'edit_user', $id ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot edit this user. Ask an administrator.', 'alphabridge-mcp' ) );
		}
		$key = sanitize_key( self::s( $a, 'key' ) );
		if ( self::protected_user_meta( $key ) ) {
			return new WP_Error( 'ab_mcp_protected_meta', __( 'This user meta key is protected (privilege/auth). Role and password change with wp_update_user.', 'alphabridge-mcp' ) );
		}
		// Per-key meta capability: honours is_protected_meta() and any registered
		// auth_user_meta_{$key} callback, not just the object-level edit_user check.
		if ( ! current_user_can( 'edit_user_meta', $id, $key ) ) {
			return new WP_Error( 'ab_mcp_protected_meta', __( 'This user meta key cannot be edited (protected by an auth callback). The plugin that registered the key decides who may change it; use that plugin\'s screen to change it.', 'alphabridge-mcp' ) );
		}
		$value = isset( $a['value'] ) ? $a['value'] : '';
		if ( ! self::safe_value( $value ) ) {
			return new WP_Error( 'ab_mcp_bad_value', __( 'Value must be a scalar or array of scalars. Pass text, a number, true, false, null or a list of these (lists may nest).', 'alphabridge-mcp' ) );
		}
		update_user_meta( $id, $key, $value );
		return array(
			'updated' => true,
			'user_id' => $id,
			'key'     => $key,
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
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot read the meta of terms in this taxonomy: that takes the right to assign its terms. Connect with an account that has it, such as an editor or an administrator.', 'alphabridge-mcp' ) );
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
				return AB_MCP_Guidance::role_refusal( __( 'Your account cannot access this meta key: that takes the right to edit the term itself (for categories and tags, the manage_categories capability, which editors and administrators have), and the plugin that registered the key can restrict it further. Connect with an account that may edit the term and the key, such as an administrator.', 'alphabridge-mcp' ) );
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

	/**
	 * Update term meta.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function update_term_meta_tool( $a ) {
		$id   = self::i( $a, 'term_id' );
		$term = get_term( $id );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Term not found. wp_list_terms shows the terms and their ids.', 'alphabridge-mcp' ) );
		}
		$key = sanitize_key( self::s( $a, 'key' ) );
		if ( '_' === substr( $key, 0, 1 ) || self::is_sensitive_meta_key( $key ) ) {
			return new WP_Error( 'ab_mcp_protected_meta', __( 'Protected meta keys are not allowed. Keys starting with "_" belong to WordPress or to the plugin that owns them and are changed through them.', 'alphabridge-mcp' ) );
		}
		// Per-key meta capability: honours any registered auth_term_meta_{$key}
		// callback and the taxonomy's term-editing rights, not just the coarse
		// manage_categories gate.
		if ( ! current_user_can( 'edit_term_meta', $id, $key ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot edit meta for this term. Ask an administrator.', 'alphabridge-mcp' ) );
		}
		$value = isset( $a['value'] ) ? $a['value'] : '';
		if ( ! self::safe_value( $value ) ) {
			return new WP_Error( 'ab_mcp_bad_value', __( 'Value must be a scalar or array of scalars. Pass text, a number, true, false, null or a list of these (lists may nest).', 'alphabridge-mcp' ) );
		}
		update_term_meta( $id, $key, $value );
		return array(
			'updated' => true,
			'term_id' => $id,
			'key'     => $key,
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
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot view this user\'s application passwords: that takes the right to edit this user, which every user has for their own profile and administrators for other users (on a multisite network, super admins). Connect with such an account.', 'alphabridge-mcp' ) );
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
