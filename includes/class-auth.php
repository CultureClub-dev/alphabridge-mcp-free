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
			return false;
		}
		return self::verify_token( $token );
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
		if ( AB_MCP_Settings::get( 'connector_url_auth_enabled', false ) ) {
			$path_token = $request->get_param( 'token' );
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
	 * Validate a token against stored tokens (constant-time). Also enforces the
	 * token's expiry (an expired token authenticates no one) and records its
	 * scope for the current request.
	 *
	 * @param string $token Presented token.
	 * @return int|false Mapped user id or false.
	 */
	public static function verify_token( $token ) {
		self::$current_scope = 'full'; // Fail-safe default for this request.

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
					self::$current_scope = $scope;
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
	 * Generate a fresh cryptographically strong token.
	 *
	 * @return string
	 */
	public static function generate_token() {
		return 'abmcp_' . bin2hex( random_bytes( 24 ) );
	}
}
