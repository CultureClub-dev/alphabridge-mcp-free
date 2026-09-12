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
			'read_only'                  => false,
			// Token-in-URL authentication is OFF by default. Header auth
			// (Authorization / X-Api-Key) is always available; putting the token in
			// the connector URL path is a convenience an admin must opt into, because
			// URLs leak more easily (referrers, history, logs, shoulder-surfing).
			'connector_url_auth_enabled' => false,
		);
	}

	/**
	 * Ensure the default options and the (empty) token store exist.
	 */
	public static function install_defaults() {
		$existing = get_option( self::OPT_OPTIONS, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
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
	 * Runs on every load and costs one option read. A new name already present
	 * wins: it is the more recent decision.
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
	 * Per-tool enable overrides: tool name => bool. A tool NOT present here uses
	 * its default (non-dangerous tools default on, dangerous/"mighty" default off).
	 *
	 * @return array<string,bool>
	 */
	public static function get_tool_state() {
		$state = get_option( self::OPT_TOOLSTATE, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Persist the full per-tool override map.
	 *
	 * @param array $state Map of tool name => bool.
	 */
	public static function set_tool_state( array $state ) {
		$clean = array();
		foreach ( $state as $name => $on ) {
			$clean[ (string) $name ] = (bool) $on;
		}
		update_option( self::OPT_TOOLSTATE, $clean );
	}

	/**
	 * Is a tool enabled (exposed via MCP + REST)? Resolves the admin override,
	 * else the default: benign tools on, dangerous ("mighty") tools off.
	 *
	 * @param string $name Tool name.
	 * @param array  $def  Tool definition.
	 * @return bool
	 */
	public static function is_tool_enabled( $name, $def ) {
		$state = self::get_tool_state();
		if ( array_key_exists( $name, $state ) ) {
			return (bool) $state[ $name ];
		}
		return empty( $def['dangerous'] );
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
	 * @param int    $expires Unix timestamp after which the token stops working,
	 *                        or 0 for no expiry.
	 * @return string The plaintext token.
	 */
	public static function add_token( $user_id, $label = '', $scope = 'full', $expires = 0 ) {
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
		);

		update_option( self::OPT_TOKENS, $tokens );
		return $token;
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
