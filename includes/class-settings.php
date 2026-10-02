<?php
/**
 * Settings + token storage.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Settings
 */
class AB_MCP_Settings {

	const OPT_OPTIONS   = 'ab_mcp_options';
	const OPT_TOKENS    = 'ab_mcp_tokens';
	const OPT_TOOLSTATE = 'ab_mcp_tool_state';

	/**
	 * Key in OPT_OPTIONS: true while the notice about the update to the site
	 * mode is due (see AB_MCP_Admin::mode_notice()).
	 */
	const KEY_MODE_NOTICE = 'mode_notice';

	/**
	 * Key in OPT_OPTIONS: true while OPT_TOOLSTATE still holds switches
	 * saved before the site mode existed, on a site updated from such a
	 * version (see is_tool_enabled()). Saving the switches clears it.
	 */
	const KEY_LEGACY_SWITCHES = 'tool_state_legacy';

	/**
	 * Default options. These are fixed product defaults; the abuse-protection
	 * limits and the always-on behaviours are intentionally not exposed in the
	 * admin UI (owners can still override them via the documented filters).
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'rate_limit_per_min'         => 120,
			'audit_enabled'              => true,
			// Read or Full, see AB_MCP_Site_Mode. Read unless an
			// administrator confirmed Full; it replaces the read-only
			// switch of earlier versions.
			'site_mode'                  => 'read',
			// Token-in-URL authentication is OFF by default. Header auth
			// (Authorization / X-Api-Key) is always available; putting the token in
			// the connector URL path is a convenience an admin must opt into, because
			// URLs leak more easily (referrers, history, logs, shoulder-surfing).
			'connector_url_auth_enabled' => false,
			// MCP revision 2026-07-28 next to the older ones. On: clients of
			// either generation are served. See
			// AB_MCP_REST_Controller::modern_enabled().
			'modern_protocol'            => true,
			// OAuth clients that identify themselves with a client metadata
			// document (an https URL as client_id). See
			// AB_MCP_OAuth::cimd_enabled(), which also honours
			// WP_HTTP_BLOCK_EXTERNAL and a filter.
			'oauth_cimd'                 => true,
		);
	}

	/**
	 * Ensure the default options and the (empty) token store exist, and bring
	 * the options of an earlier version up to date.
	 *
	 * A site whose options exist but carry no site mode was installed before
	 * the mode existed. It starts in Read like a new one, whatever its
	 * read-only switch said; the switch itself is dropped, and the
	 * administrators see a notice once (KEY_MODE_NOTICE). Its tool switches
	 * stay, read by the rule for switches saved before (KEY_LEGACY_SWITCHES).
	 */
	public static function install_defaults() {
		$stored   = get_option( self::OPT_OPTIONS, false );
		$existing = is_array( $stored ) ? $stored : array();
		// An earlier version wrote its options on activation, and on a site of
		// a network where it ran without being activated there, at the latest
		// with the first connection or saved switch.
		$upgrade = is_array( $stored )
			? ! empty( $stored ) && ! array_key_exists( 'site_mode', $stored )
			: false !== get_option( self::OPT_TOKENS ) || false !== get_option( self::OPT_TOOLSTATE );

		if ( $upgrade ) {
			unset( $existing['read_only'] );
			$existing['site_mode']                 = 'read';
			$existing[ self::KEY_MODE_NOTICE ]     = true;
			$existing[ self::KEY_LEGACY_SWITCHES ] = true;
		}
		update_option( self::OPT_OPTIONS, wp_parse_args( $existing, self::defaults() ) );

		if ( false === get_option( self::OPT_TOKENS ) ) {
			update_option( self::OPT_TOKENS, array() );
		}

		// One-time migration: a pre-1.2 Safe-Mode allow-list becomes per-tool
		// "enabled" overrides so previously allowed dangerous tools stay enabled.
		if ( false === get_option( self::OPT_TOOLSTATE ) ) {
			$state   = array();
			$allowed = isset( $existing['allowed_dangerous'] ) && is_array( $existing['allowed_dangerous'] ) ? $existing['allowed_dangerous'] : array();
			foreach ( $allowed as $name ) {
				$state[ (string) $name ] = true;
			}
			update_option( self::OPT_TOOLSTATE, $state );
		}

		self::rename_tool_state_keys();
	}

	/**
	 * Run install_defaults() where an update left it undone.
	 *
	 * WordPress runs the activation hook on activation only, not when a
	 * plugin is updated in place, so the plugin checks on every load whether
	 * its options know the site mode. That costs one read of an option that
	 * is loaded anyway; it writes only the one time it finds them out of date
	 * or missing (a core bundled by an add-on is never activated itself).
	 */
	public static function maybe_upgrade() {
		$stored = get_option( self::OPT_OPTIONS, false );
		if ( is_array( $stored ) && array_key_exists( 'site_mode', $stored ) ) {
			return;
		}
		self::install_defaults();
	}

	/**
	 * Tools renamed in 4.3.0, old name => new name.
	 *
	 * @var array<string,string>
	 */
	const RENAMED_TOOLS = array(
		'seo_detect' => 'wp_seo_detect',
		'seo_get'    => 'wp_seo_get',
		// A Pro tool. The map lives here because the Pro plugin embeds this
		// file, and one place for the whole family beats two that drift apart.
		// A free installation simply never holds that key.
		'seo_set'    => 'wp_seo_set',
		// The WooCommerce tools, also Pro. Same rule, same reason.
		'wc_create_coupon'       => 'wp_wc_create_coupon',
		'wc_get_order'           => 'wp_wc_get_order',
		'wc_get_product'         => 'wp_wc_get_product',
		'wc_list_coupons'        => 'wp_wc_list_coupons',
		'wc_list_customers'      => 'wp_wc_list_customers',
		'wc_list_orders'         => 'wp_wc_list_orders',
		'wc_list_products'       => 'wp_wc_list_products',
		'wc_update_order_status' => 'wp_wc_update_order_status',
		'wc_update_product'      => 'wp_wc_update_product',
	);

	/**
	 * Carry per-tool on/off overrides across a rename.
	 *
	 * The override map is keyed by tool name. After a rename the old key points
	 * at nothing and the tool falls back to its default — which for these two
	 * is "on". An admin who had deliberately switched the SEO tool off would
	 * find it back after updating, with no error and nothing to see in the
	 * settings. Silently re-enabling something somebody switched off is the
	 * kind of regression nobody reports, because it looks like it was always
	 * that way.
	 *
	 * Runs with install_defaults(): on activation, and through
	 * maybe_upgrade() once on the update that brought the site mode. A new
	 * name already present wins: it is the more recent decision.
	 */
	private static function rename_tool_state_keys() {
		$state = get_option( self::OPT_TOOLSTATE );
		if ( ! is_array( $state ) ) {
			return;
		}

		$changed = false;
		foreach ( self::RENAMED_TOOLS as $old => $new ) {
			if ( ! array_key_exists( $old, $state ) ) {
				continue;
			}
			if ( ! array_key_exists( $new, $state ) ) {
				$state[ $new ] = $state[ $old ];
			}
			unset( $state[ $old ] );
			$changed = true;
		}

		if ( $changed ) {
			update_option( self::OPT_TOOLSTATE, $state );
		}
	}

	/* -------------------------------------------------------------- Tool state */

	/**
	 * Per-tool enable overrides: tool name => bool. A tool NOT present here is
	 * on, except on a site updated from before the site mode (see
	 * is_tool_enabled()).
	 *
	 * @return array<string,bool>
	 */
	public static function get_tool_state() {
		$state = get_option( self::OPT_TOOLSTATE, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Persist the full per-tool override map. What is written here follows
	 * the current rule, so the rule for switches saved before the site mode
	 * existed no longer applies.
	 *
	 * @param array $state Map of tool name => bool.
	 */
	public static function set_tool_state( array $state ) {
		$clean = array();
		foreach ( $state as $name => $on ) {
			$clean[ (string) $name ] = (bool) $on;
		}
		update_option( self::OPT_TOOLSTATE, $clean );
		if ( self::get( self::KEY_LEGACY_SWITCHES, false ) ) {
			self::set( self::KEY_LEGACY_SWITCHES, false );
		}
	}

	/**
	 * Is a tool switched on (exposed via MCP + REST)? Every tool is on until
	 * an administrator switches it off under Fine-tuning. Whether it may run
	 * is a separate question, answered by the site mode (AB_MCP_Site_Mode):
	 * in Read every writing tool and every Mighty one is refused, switched on
	 * or not, so a switch decides only in Full.
	 *
	 * Switches saved before the site mode existed held the powerful
	 * ("dangerous") tools off by default, and saving the form wrote that off
	 * for every one of them. Until the switches are saved again, such an off
	 * of a site updated from that version does not hold a powerful tool that
	 * writes: it records the old default, not a decision, and the tool runs
	 * only after an administrator confirmed Full, where every tool is on by
	 * default. A powerful tool that only reads keeps a stored off: Full opens
	 * reading code, files, the database, logs or credentials, and a site
	 * whose administrator kept the database query or a file reader off before
	 * keeps it off there. A switched-off tool that was on by default stays off
	 * either way.
	 *
	 * @param string $name Tool name.
	 * @param array  $def  Tool definition.
	 * @return bool
	 */
	public static function is_tool_enabled( $name, $def ) {
		$name  = (string) $name;
		$def   = is_array( $def ) ? $def : array();
		$state = self::get_tool_state();
		if ( ! array_key_exists( $name, $state ) || $state[ $name ] ) {
			return true;
		}
		return AB_MCP_Tool_Registry::is_mighty( $def )
			&& self::get( self::KEY_LEGACY_SWITCHES, false )
			&& ! AB_MCP_Tool_Registry::is_read_only( $name, $def );
	}

	/**
	 * Get one option value.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$opts = get_option( self::OPT_OPTIONS, array() );
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}
		$opts = wp_parse_args( $opts, self::defaults() );
		return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
	}

	/**
	 * Set one option value.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public static function set( $key, $value ) {
		$opts = get_option( self::OPT_OPTIONS, array() );
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}
		$opts         = wp_parse_args( $opts, self::defaults() );
		$opts[ $key ] = $value;
		update_option( self::OPT_OPTIONS, $opts );
	}

	/* ------------------------------------------------------------------ Tokens */

	/**
	 * All tokens.
	 *
	 * @return array
	 */
	public static function get_tokens() {
		$tokens = get_option( self::OPT_TOKENS, array() );
		return is_array( $tokens ) ? $tokens : array();
	}

	/**
	 * Create and store a token bound to a user.
	 *
	 * @param int    $user_id User id.
	 * @param string $label   Human label.
	 * @param string $scope   Token scope: 'read' | 'content' | 'full' (default).
	 * @param int    $expires   Unix timestamp after which the token stops working,
	 *                          or 0 for no expiry.
	 * @param string $client_id OAuth client the token was issued to, '' for a
	 *                          connection an admin created by hand. Lets the
	 *                          list show which connections belong to the same
	 *                          app (older_of_same_app()); it grants nothing.
	 * @return string The plaintext token.
	 */
	public static function add_token( $user_id, $label = '', $scope = 'full', $expires = 0, $client_id = '' ) {
		$tokens = self::get_tokens();
		$token  = AB_MCP_Auth::generate_token();

		// Only the SHA-256 hash and a short prefix are stored. The token itself is
		// shown to the admin exactly once, at creation — there is no reversible
		// copy at rest, so a database leak cannot recover a usable token.
		$tokens[] = array(
			'hash'      => hash( 'sha256', $token ),
			'prefix'    => substr( $token, 0, 12 ),
			'user_id'   => (int) $user_id,
			'label'     => sanitize_text_field( $label ),
			'scope'     => self::sanitize_scope( $scope ),
			'expires'   => max( 0, (int) $expires ),
			'created'   => time(),
			'last_used' => 0,
			'client_id' => sanitize_text_field( (string) $client_id ),
		);

		update_option( self::OPT_TOKENS, $tokens );
		return $token;
	}

	/**
	 * The connections a newer one of the same app has followed, for the same
	 * user: another entry with the same client_id and user_id is newer.
	 *
	 * A client that connects again gets a new token and need not hand back the
	 * old one — ChatGPT's «reconnect» leaves the earlier connection working
	 * (seen on 26.09.2026). Nothing is revoked here, on purpose: a second
	 * connection of the same app can be wanted, one for reading and one for
	 * writing, or two people sharing an app registration and a WordPress
	 * account. This only tells the admin which entries to look at; "older" does
	 * not mean "unused", which is what the Last used column is for.
	 *
	 * Only tokens the OAuth flow issued from 4.3.5 on carry a client_id.
	 * Connections made by hand and older entries are never grouped. "Newer"
	 * means a later `created`; on a tie the entry further down the list wins,
	 * because add_token() appends.
	 *
	 * @param array $tokens Stored token entries, in stored order.
	 * @return array<string,bool> Hash => true for every older entry.
	 */
	public static function older_of_same_app( array $tokens ) {
		$groups = array();
		foreach ( $tokens as $i => $t ) {
			// empty() on the offset of anything that is not an entry is true,
			// so a stray value is skipped here as well.
			if ( empty( $t['hash'] ) || empty( $t['client_id'] ) ) {
				continue;
			}
			$key              = (int) ( isset( $t['user_id'] ) ? $t['user_id'] : 0 ) . "\n" . (string) $t['client_id'];
			$groups[ $key ][] = array( (int) ( isset( $t['created'] ) ? $t['created'] : 0 ), $i, (string) $t['hash'] );
		}

		$older = array();
		foreach ( $groups as $members ) {
			$newest = $members[0];
			foreach ( $members as $m ) {
				if ( $m[0] >= $newest[0] ) {
					$newest = $m;
				}
			}
			foreach ( $members as $m ) {
				if ( $m[1] !== $newest[1] ) {
					$older[ $m[2] ] = true;
				}
			}
		}
		return $older;
	}

	/**
	 * Clamp a scope to a known value; anything unknown becomes the safe default.
	 *
	 * @param string $scope Requested scope.
	 * @return string
	 */
	public static function sanitize_scope( $scope ) {
		$scope = (string) $scope;
		// Fail closed: an unrecognised scope becomes the most restrictive one
		// (read), never the widest one. A caller who wants full access must ask
		// for it by name.
		return array_key_exists( $scope, AB_MCP_Tool_Registry::scopes() ) ? $scope : 'read';
	}

	/**
	 * The stored entry for a token hash, or null when there is none. Used to
	 * learn whose connection an admin is about to rotate.
	 *
	 * @param string $hash Token hash.
	 * @return array|null
	 */
	public static function get_token_by_hash( $hash ) {
		foreach ( self::get_tokens() as $entry ) {
			if ( ! empty( $entry['hash'] ) && hash_equals( (string) $entry['hash'], (string) $hash ) ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Rotate a token: issue a fresh secret for an existing entry, keeping its
	 * user, label, scope and expiry. The old secret stops working immediately.
	 * Returns the new plaintext token, or '' if the hash was not found.
	 *
	 * @param string $hash Existing token hash.
	 * @return string
	 */
	public static function rotate_token( $hash ) {
		$tokens = self::get_tokens();
		$new    = '';
		foreach ( $tokens as $i => $entry ) {
			if ( ! empty( $entry['hash'] ) && hash_equals( (string) $entry['hash'], (string) $hash ) ) {
				$new                = AB_MCP_Auth::generate_token();
				$entry['hash']      = hash( 'sha256', $new );
				$entry['prefix']    = substr( $new, 0, 12 );
				$entry['created']   = time();
				$entry['last_used'] = 0;
				// Move the rotated entry to the end so it becomes the "primary"
				// connection whose new URL the post-rotate notice shows.
				unset( $tokens[ $i ] );
				$tokens   = array_values( $tokens );
				$tokens[] = $entry;
				break;
			}
		}
		if ( '' !== $new ) {
			update_option( self::OPT_TOKENS, $tokens );
		}
		return $new;
	}

	/**
	 * Stamp a token's last-used time (called on successful authentication).
	 *
	 * @param string $hash Token hash.
	 */
	public static function touch_token( $hash ) {
		$tokens  = self::get_tokens();
		$changed = false;
		foreach ( $tokens as &$entry ) {
			if ( ! empty( $entry['hash'] ) && hash_equals( (string) $entry['hash'], (string) $hash ) ) {
				// Throttle: last_used is informational; rewriting the whole token
				// option on every request would hammer the DB on busy sites.
				if ( time() - (int) $entry['last_used'] > 5 * MINUTE_IN_SECONDS ) {
					$entry['last_used'] = time();
					$changed            = true;
				}
				break;
			}
		}
		unset( $entry );
		if ( $changed ) {
			update_option( self::OPT_TOKENS, $tokens );
		}
	}

	/**
	 * Rename a token by its hash.
	 *
	 * @param string $hash  Token hash.
	 * @param string $label New label.
	 */
	public static function rename_token( $hash, $label ) {
		$tokens = self::get_tokens();
		foreach ( $tokens as &$entry ) {
			if ( ! empty( $entry['hash'] ) && hash_equals( (string) $entry['hash'], (string) $hash ) ) {
				$entry['label'] = sanitize_text_field( $label );
				break;
			}
		}
		unset( $entry );
		update_option( self::OPT_TOKENS, $tokens );
	}

	/**
	 * Delete a token by its stored hash.
	 *
	 * @param string $hash Token hash.
	 */
	public static function delete_token( $hash ) {
		$tokens = self::get_tokens();
		$tokens = array_values(
			array_filter(
				$tokens,
				static function ( $entry ) use ( $hash ) {
					return empty( $entry['hash'] ) || ! hash_equals( (string) $entry['hash'], (string) $hash );
				}
			)
		);
		update_option( self::OPT_TOKENS, $tokens );
	}
}
