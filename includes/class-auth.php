<?php
/**
 * Authentication for the MCP endpoint.
 *
 * Supports a bearer token (recommended) delivered via the Authorization header,
 * an X-Api-Key header (for clients that strip Authorization), or — only when the
 * admin has opted in — a token in the connector URL path (/mcp/<token>).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Auth
 */
class AB_MCP_Auth {

	/**
	 * Verify a request and return the mapped WordPress user id, or false.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int|false
	 */
	public static function verify_request( $request ) {
		$token = self::extract_token( $request );
		if ( '' === $token ) {
			self::$token_presence = 'missing';
			return false;
		}
		$user_id              = self::verify_token( $token );
		self::$token_presence = $user_id ? 'accepted' : 'rejected';
		return $user_id;
	}

	/**
	 * What the last verify_request() found of the token: 'missing' when no
	 * token reached PHP at all, 'rejected' when one arrived and was not
	 * valid, 'accepted' when it authenticated; '' before any check.
	 *
	 * Recorded where the token is read, so nothing reads the request a
	 * second time. The REST controller names the first two on a 401
	 * (AB_MCP_REST_Controller::AUTH_HEADER): the connection check sends a
	 * made-up token to the site itself and learns from «rejected» that the
	 * Authorization header arrives, and from «missing» that the server
	 * dropped it on the way — the most common reason why a site answers
	 * every app with 401. It reveals only whether a header arrived, never
	 * anything about a token.
	 *
	 * @var string
	 */
	private static $token_presence = '';

	/**
	 * What the last token check of this request found; see $token_presence.
	 *
	 * @return string 'missing', 'rejected', 'accepted' or ''.
	 */
	public static function token_presence() {
		return self::$token_presence;
	}

	/**
	 * Pull the presented token out of the request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	public static function extract_token( $request ) {
		$header = (string) $request->get_header( 'authorization' );

		if ( '' !== $header ) {
			if ( 0 === stripos( $header, 'bearer ' ) ) {
				return trim( substr( $header, 7 ) );
			}
			return trim( $header );
		}

		$api_key = (string) $request->get_header( 'x_api_key' );
		if ( '' !== $api_key ) {
			return trim( $api_key );
		}

		// Token embedded in the connector URL path: /mcp/<token>. Accepted ONLY when
		// the admin has explicitly enabled connector-URL authentication; header auth
		// is always available and is the default. Tokens in URLs leak more easily
		// (referrers, proxy/access logs, browser history), so this is opt-in.
		// Read from the route's own path parameter only: get_param() would also
		// take ?token= from the query string or a "token" field in the body,
		// which are never accepted.
		if ( AB_MCP_Settings::get( 'connector_url_auth_enabled', false ) ) {
			$url_params = $request->get_url_params();
			$path_token = isset( $url_params['token'] ) ? $url_params['token'] : '';
			if ( is_string( $path_token ) && '' !== $path_token ) {
				return trim( $path_token );
			}
		}

		return '';
	}

	/**
	 * Scope of the token that authenticated the current request. Captured by
	 * verify_token() and read by AB_MCP_Security::authorize(). 'full' unless a
	 * narrower-scoped token authenticated. Reset on every verify.
	 *
	 * @var string
	 */
	private static $current_scope = 'full';

	/**
	 * The scope of the token that authenticated the current request.
	 *
	 * @return string 'read' | 'content' | 'full'
	 */
	public static function current_scope() {
		return self::$current_scope;
	}

	/**
	 * The stored hash of the token that authenticated the current request,
	 * '' while none did. Captured by verify_token() next to the scope: the
	 * token entry is the connection, and «Staging first» keeps its
	 * acknowledgement there (AB_MCP_Staging_Gate). Reset on every verify.
	 *
	 * @var string
	 */
	private static $current_token_hash = '';

	/**
	 * The hash of the token entry that authenticated the current request;
	 * '' without one (a call through the abilities adapter, or before the
	 * check). It names the connection and grants nothing.
	 *
	 * @return string
	 */
	public static function current_token_hash() {
		return self::$current_token_hash;
	}

	/**
	 * Validate a token against stored tokens (constant-time). Also enforces the
	 * token's expiry (an expired token authenticates no one) and records its
	 * scope for the current request.
	 *
	 * @param string $token Presented token.
	 * @return int|false Mapped user id or false.
	 */
	public static function verify_token( $token ) {
		self::$current_scope      = 'full'; // Fail-safe default for this request.
		self::$current_token_hash = '';

		$tokens = AB_MCP_Settings::get_tokens();
		if ( empty( $tokens ) ) {
			return false;
		}

		$presented = (string) $token;
		if ( strlen( $presented ) < 16 ) {
			return false;
		}

		$presented_hash = hash( 'sha256', $presented );

		foreach ( $tokens as $entry ) {
			if ( empty( $entry['hash'] ) || empty( $entry['user_id'] ) ) {
				continue;
			}
			if ( hash_equals( (string) $entry['hash'], $presented_hash ) ) {
				// Expiry: a token past its expires timestamp authenticates no one.
				// Tokens created before 4.1 have no 'expires' key (0 = never).
				$expires = isset( $entry['expires'] ) ? (int) $entry['expires'] : 0;
				if ( $expires > 0 && time() > $expires ) {
					return false;
				}
				// Scope handling is fail-closed. A token with NO scope key predates
				// scopes (4.1) and keeps the historical full behaviour. A token
				// that HAS a scope key but whose value is not a known scope is
				// treated as tampered and authenticates no one — it never silently
				// widens to full.
				if ( array_key_exists( 'scope', $entry ) ) {
					$scope = (string) $entry['scope'];
					if ( ! array_key_exists( $scope, AB_MCP_Tool_Registry::scopes() ) ) {
						return false;
					}
				} else {
					$scope = 'full';
				}
				$user_id = (int) $entry['user_id'];
				$user    = get_user_by( 'id', $user_id );
				if ( $user instanceof WP_User ) {
					self::$current_scope      = $scope;
					self::$current_token_hash = (string) $entry['hash'];
					if ( method_exists( 'AB_MCP_Settings', 'touch_token' ) ) {
						AB_MCP_Settings::touch_token( (string) $entry['hash'] );
					}
					return $user_id;
				}
			}
		}

		return false;
	}

	/**
	 * Whether the current user may create (or rotate) a connection that acts as
	 * $target_id.
	 *
	 * A connection runs every tool as its mapped user, so issuing one for
	 * somebody else is delegation of that person's authority. `manage_options`
	 * covers the settings page, not that: on a multisite network a site
	 * administrator holds it and must still not mint a token in the name of a
	 * network administrator. WordPress already encodes who may act for whom in
	 * `edit_user` (on multisite that requires `manage_network_users`, and only a
	 * super admin passes it for a super admin), so that is the rule applied
	 * here. The explicit super-admin clause repeats what map_meta_cap does for
	 * `edit_user`, so a `map_meta_cap` filter that widens `edit_user` cannot
	 * widen this.
	 *
	 * @param int $target_id User the connection would act as.
	 * @return bool
	 */
	public static function may_issue_token_for( $target_id ) {
		$target_id = (int) $target_id;
		$issuer_id = (int) get_current_user_id();
		if ( $target_id <= 0 || $issuer_id <= 0 ) {
			return false;
		}
		if ( $target_id === $issuer_id ) {
			return true;
		}
		if ( ! current_user_can( 'edit_user', $target_id ) ) {
			return false;
		}
		if ( is_multisite() && is_super_admin( $target_id ) && ! is_super_admin( $issuer_id ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Generate a fresh cryptographically strong token.
	 *
	 * @return string
	 */
	public static function generate_token() {
		return 'abmcp_' . bin2hex( random_bytes( 24 ) );
	}
}
