<?php
/**
 * AlphaBridge Connect — a minimal OAuth 2.1 authorization server for this
 * site's own MCP endpoint, so MCP clients (Claude.ai, Claude Desktop, …) can
 * connect with their native "Connect" button: the user is sent to THIS site's
 * normal WordPress login, approves on a consent screen, and the client receives
 * an ordinary AlphaBridge connection token. No new account anywhere — the
 * authorization is the site's own wp-login.
 *
 * Implements exactly what that flow needs and nothing more:
 *  - RFC 9728 protected-resource metadata + RFC 8414 server metadata, served
 *    under /.well-known/… (and referenced from 401s via WWW-Authenticate).
 *  - RFC 7591 dynamic client registration (public clients, no secrets).
 *  - Authorization-code grant with PKCE (S256 REQUIRED, RFC 7636) and
 *    resource indication (RFC 8707).
 *
 * Issued access tokens ARE the existing abmcp_ connection tokens
 * (AB_MCP_Settings::add_token) — hashed at rest, scoped, revocable in the
 * connections table like any manually created connection.
 *
 * Security invariants:
 *  - Never redirect to a redirect_uri that is not an exact, pre-registered
 *    match (invalid requests get an error PAGE, not a redirect).
 *  - Authorization codes are single-use (deleted before validation completes),
 *    expire after 120 seconds and are bound to client + redirect_uri + user +
 *    scope + PKCE challenge.
 *  - Consent requires a logged-in user with the ab_mcp_oauth_capability
 *    (default manage_options) and a CSRF nonce.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_OAuth
 */
class AB_MCP_OAuth {

	const OPT_CLIENTS   = 'ab_mcp_oauth_clients';
	const CODE_TTL      = 120;
	const MAX_CLIENTS   = 100;
	const CODE_PREFIX   = 'abac_';
	const CLIENT_PREFIX = 'abc_';

	/**
	 * Client name the AlphaBridge Connect hub registers itself under (RFC 7591
	 * `client_name`). Connections created by it are labelled with this name
	 * alone, so a site owner sees at a glance that the connection came through
	 * the hub rather than from a client that talks to this site directly.
	 *
	 * The name is SELF-ASSERTED. Dynamic client registration lets any client pick
	 * any name, so this label says "this client called itself AlphaBridge Connect",
	 * not "this is verified to be AlphaBridge Connect". That is true of every
	 * client name in the list, and the label grants nothing — it is a reading aid.
	 * Verifiable provenance needs CIMD, where the client id is a URL this site can
	 * fetch and check; that is planned and not available here yet.
	 */
	const CONNECT_CLIENT_NAME = 'AlphaBridge Connect';

	/* ---------------------------------------------------------------- state */

	/**
	 * Is Claude Connect switched on? Default yes — nothing is ever issued
	 * without an admin approving on the consent screen, so the endpoints being
	 * reachable grants nothing by themselves.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) AB_MCP_Settings::get( 'oauth_enabled', true );
	}

	/**
	 * REST namespace (falls back for standalone test harnesses).
	 *
	 * @return string
	 */
	private static function ns() {
		return defined( 'AB_MCP_REST_NAMESPACE' ) ? AB_MCP_REST_NAMESPACE : 'alphabridge/v1';
	}

	/**
	 * The OAuth issuer: this site.
	 *
	 * @return string
	 */
	public static function issuer() {
		return untrailingslashit( home_url() );
	}

	/**
	 * The protected resource: this site's MCP endpoint.
	 *
	 * @return string
	 */
	public static function resource() {
		$route = defined( 'AB_MCP_REST_ROUTE' ) ? AB_MCP_REST_ROUTE : '/mcp';
		return untrailingslashit( rest_url( self::ns() . $route ) );
	}

	/**
	 * Where a 401 should point clients for discovery (RFC 9728 path-insertion
	 * form, so the metadata is specific to the MCP endpoint).
	 *
	 * @return string
	 */
	public static function resource_metadata_url() {
		$path = (string) wp_parse_url( self::resource(), PHP_URL_PATH );
		return self::issuer() . '/.well-known/oauth-protected-resource' . $path;
	}

	/* ------------------------------------------------------------- metadata */

	/**
	 * RFC 8414 authorization-server metadata.
	 *
	 * @return array
	 */
	public static function metadata() {
		return array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => home_url( '/?ab_mcp_oauth=authorize' ),
			'token_endpoint'                        => rest_url( self::ns() . '/oauth/token' ),
			'registration_endpoint'                 => rest_url( self::ns() . '/oauth/register' ),
			'revocation_endpoint'                   => rest_url( self::ns() . '/oauth/revoke' ),
			'revocation_endpoint_auth_methods_supported' => array( 'none' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'scopes_supported'                      => array_keys( AB_MCP_Tool_Registry::scopes() ),
			'authorization_response_iss_parameter_supported' => true,
		);
	}

	/**
	 * RFC 9728 protected-resource metadata.
	 *
	 * @return array
	 */
	public static function resource_metadata() {
		return array(
			'resource'                 => self::resource(),
			'authorization_servers'    => array( self::issuer() ),
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => get_bloginfo( 'name' ),
		);
	}

	/* ------------------------------------------- dynamic client registration */

	/**
	 * Register a public OAuth client (RFC 7591). Pure logic — no HTTP here.
	 *
	 * @param array $body Parsed registration request body.
	 * @return array RFC 7591 client response, or array( 'error' => …, 'error_description' => … ).
	 */
	public static function register_client( $body ) {
		$body = is_array( $body ) ? $body : array();

		$uris = isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] ) ? $body['redirect_uris'] : array();
		if ( empty( $uris ) ) {
			return array(
				'error'             => 'invalid_client_metadata',
				'error_description' => 'redirect_uris is required.',
			);
		}
		if ( count( $uris ) > 20 ) {
			return array(
				'error'             => 'invalid_client_metadata',
				'error_description' => 'Too many redirect_uris.',
			);
		}

		$clean_uris = array();
		foreach ( $uris as $uri ) {
			$uri   = (string) $uri;
			$parts = wp_parse_url( $uri );
			$valid = is_array( $parts )
				&& isset( $parts['scheme'], $parts['host'] )
				&& ! isset( $parts['fragment'] )
				&& strlen( $uri ) <= 2000
				&& (
					'https' === strtolower( $parts['scheme'] )
					// Loopback redirects stay http by spec (native/dev clients).
					|| ( 'http' === strtolower( $parts['scheme'] )
						&& in_array( strtolower( $parts['host'] ), array( 'localhost', '127.0.0.1', '[::1]', '::1' ), true ) )
				);
			if ( ! $valid ) {
				return array(
					'error'             => 'invalid_redirect_uri',
					'error_description' => 'redirect_uris must be https (or http on localhost) without fragments.',
				);
			}
			$clean_uris[] = $uri;
		}

		$name = isset( $body['client_name'] ) ? sanitize_text_field( (string) $body['client_name'] ) : '';
		$name = substr( $name, 0, 80 );
		if ( '' === $name ) {
			$name = 'MCP client';
		}

		$client_id = self::CLIENT_PREFIX . bin2hex( random_bytes( 16 ) );

		$clients = get_option( self::OPT_CLIENTS, array() );
		$clients = is_array( $clients ) ? $clients : array();
		// Cap storage; registration is unauthenticated by spec, so the list must
		// not grow without bound. Eviction is USED-AWARE: a flood of dormant
		// registrations can never push out a client that has actually completed a
		// connection (its 'used' timestamp is set in redeem_code). Never-used
		// clients go first (oldest created first), only then the least-recently
		// used — so an active client such as Claude survives a registration flood.
		if ( count( $clients ) >= self::MAX_CLIENTS ) {
			uasort(
				$clients,
				static function ( $a, $b ) {
					$au = (int) ( $a['used'] ?? 0 );
					$bu = (int) ( $b['used'] ?? 0 );
					// Unused (used === 0) sort before used; within each group, oldest first.
					if ( ( 0 === $au ) !== ( 0 === $bu ) ) {
						return 0 === $au ? -1 : 1;
					}
					$ak = 0 !== $au ? $au : (int) ( $a['created'] ?? 0 );
					$bk = 0 !== $bu ? $bu : (int) ( $b['created'] ?? 0 );
					return $ak <=> $bk;
				}
			);
			$clients = array_slice( $clients, count( $clients ) - self::MAX_CLIENTS + 1, null, true );
		}
		$clients[ $client_id ] = array(
			'name'          => $name,
			'redirect_uris' => $clean_uris,
			'created'       => time(),
			'used'          => 0,
		);
		update_option( self::OPT_CLIENTS, $clients );

		return array(
			'client_id'                  => $client_id,
			'client_id_issued_at'        => time(),
			'client_name'                => $name,
			'redirect_uris'              => $clean_uris,
			'token_endpoint_auth_method' => 'none',
			'grant_types'                => array( 'authorization_code' ),
			'response_types'             => array( 'code' ),
		);
	}

	/**
	 * Look up a registered client.
	 *
	 * @param string $client_id Client id.
	 * @return array|false
	 */
	public static function get_client( $client_id ) {
		$clients = get_option( self::OPT_CLIENTS, array() );
		return ( is_array( $clients ) && isset( $clients[ $client_id ] ) ) ? $clients[ $client_id ] : false;
	}

	/* --------------------------------------------------- codes and redeeming */

	/**
	 * Transient key for a code — the code itself is never stored.
	 *
	 * @param string $code Authorization code.
	 * @return string
	 */
	private static function code_key( $code ) {
		return 'ab_mcp_oac_' . substr( hash( 'sha256', (string) $code ), 0, 40 );
	}

	/**
	 * Create a single-use authorization code after consent.
	 *
	 * @param string $client_id    Registered client.
	 * @param string $redirect_uri Exact redirect target the code is bound to.
	 * @param int    $user_id      Approving user.
	 * @param string $scope        Requested scope (clamped fail-closed).
	 * @param string $challenge    PKCE S256 code challenge.
	 * @return string The code.
	 */
	public static function create_auth_code( $client_id, $redirect_uri, $user_id, $scope, $challenge ) {
		$code = self::CODE_PREFIX . bin2hex( random_bytes( 32 ) );
		set_transient(
			self::code_key( $code ),
			array(
				'client_id'    => (string) $client_id,
				'redirect_uri' => (string) $redirect_uri,
				'user_id'      => (int) $user_id,
				'scope'        => AB_MCP_Settings::sanitize_scope( $scope ),
				'challenge'    => (string) $challenge,
				'created'      => time(),
			),
			self::CODE_TTL
		);
		return $code;
	}

	/**
	 * Exchange an authorization code for an access token. Pure logic — the REST
	 * callback wraps this. Returns the RFC 6749 token response, or an error
	 * array with an RFC error code.
	 *
	 * @param array $p grant_type, code, redirect_uri, client_id, code_verifier, resource?.
	 * @return array
	 */
	public static function redeem_code( $p ) {
		$p = is_array( $p ) ? $p : array();

		if ( ! isset( $p['grant_type'] ) || 'authorization_code' !== $p['grant_type'] ) {
			return array( 'error' => 'unsupported_grant_type' );
		}

		$code = isset( $p['code'] ) ? (string) $p['code'] : '';
		if ( '' === $code || 0 !== strpos( $code, self::CODE_PREFIX ) ) {
			return array( 'error' => 'invalid_grant' );
		}

		// Single use: fetch and burn BEFORE any validation, so even a failed
		// attempt consumes the code and it can never be retried or replayed.
		$key  = self::code_key( $code );
		$data = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $data ) ) {
			return array( 'error' => 'invalid_grant' );
		}

		$client_id = isset( $p['client_id'] ) ? (string) $p['client_id'] : '';
		$client    = self::get_client( $client_id );
		if ( false === $client || ! hash_equals( (string) $data['client_id'], $client_id ) ) {
			return array( 'error' => 'invalid_client' );
		}

		$redirect = isset( $p['redirect_uri'] ) ? (string) $p['redirect_uri'] : '';
		if ( ! hash_equals( (string) $data['redirect_uri'], $redirect ) ) {
			return array( 'error' => 'invalid_grant' );
		}

		// PKCE S256, mandatory: challenge = BASE64URL( SHA256( verifier ) ). The
		// verifier must be 43-128 chars of the RFC 7636 unreserved set.
		$verifier = isset( $p['code_verifier'] ) ? (string) $p['code_verifier'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $verifier ) ) {
			return array( 'error' => 'invalid_grant' );
		}
		// BASE64URL( SHA256( verifier ) ) — the PKCE S256 transform (RFC 7636), not obfuscation.
		$computed = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- required PKCE encoding.
		if ( ! hash_equals( (string) $data['challenge'], $computed ) ) {
			return array( 'error' => 'invalid_grant' );
		}

		// Resource indication (RFC 8707): if the client names an audience it
		// must be THIS site's MCP endpoint — tokens are never minted for a
		// different resource.
		if ( isset( $p['resource'] ) && '' !== (string) $p['resource'] ) {
			if ( untrailingslashit( (string) $p['resource'] ) !== self::resource() ) {
				return array( 'error' => 'invalid_target' );
			}
		}

		$user = get_user_by( 'id', (int) $data['user_id'] );
		if ( ! $user ) {
			return array( 'error' => 'invalid_grant' );
		}

		// Mark the client as used, so a flood of dormant registrations can never
		// evict a client that has actually completed a connection (see the
		// used-aware eviction in register_client()).
		$all = get_option( self::OPT_CLIENTS, array() );
		if ( is_array( $all ) && isset( $all[ $client_id ] ) ) {
			$all[ $client_id ]['used'] = time();
			update_option( self::OPT_CLIENTS, $all );
		}

		/**
		 * Lifetime (seconds) for a token issued through Claude Connect, or 0 for
		 * no expiry (the default — the connection is revocable in the settings
		 * list at any time, like a manually created one). Return a positive
		 * number to force connect-issued tokens to expire.
		 *
		 * @param int    $ttl   Seconds until expiry, 0 = never.
		 * @param string $scope Approved scope.
		 */
		$ttl     = (int) apply_filters( 'ab_mcp_oauth_token_ttl', 0, (string) $data['scope'] );
		$expires = $ttl > 0 ? time() + $ttl : 0;

		$label = self::connection_label( isset( $client['name'] ) ? (string) $client['name'] : '' );
		$token = AB_MCP_Settings::add_token( (int) $data['user_id'], $label, (string) $data['scope'], $expires );

		return array(
			'access_token' => $token,
			'token_type'   => 'Bearer',
			'scope'        => (string) $data['scope'],
		);
	}

	/* ------------------------------------------------------------ rate limit */

	/**
	 * Sliding per-IP counter. Registration and token endpoints are public by
	 * spec; this keeps them from being hammered.
	 *
	 * @param string $bucket 'reg' or 'tok'.
	 * @param int    $max    Allowed hits per hour.
	 * @return bool True when the request is still within the limit.
	 */
	private static function within_rate_limit( $bucket, $max ) {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- used only hashed, for a counter key.
		$key   = 'ab_mcp_o' . $bucket . '_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return false;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/* ------------------------------------------------------------------ HTTP */

	/**
	 * Wire the HTTP surface. Called once from the plugin bootstrap.
	 */
	public static function boot() {
		// serve_well_known runs on template_redirect (priority 0, before
		// redirect_canonical), NOT on init: the core boots itself on init, so an
		// init callback registered here would be added mid-init and never fire for
		// the current request. template_redirect fires after init on every
		// front-end request — including the 404 that /.well-known/… would be — so
		// the handler reliably intercepts before WordPress renders a 404.
		add_action( 'template_redirect', array( __CLASS__, 'serve_well_known' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_authorize' ), 0 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Serve /.well-known/oauth-authorization-server and
	 * /.well-known/oauth-protected-resource[/…] as JSON. These live at the site
	 * root, outside the REST prefix, so they are intercepted on template_redirect
	 * (before WordPress would render a 404 for the unknown URL).
	 */
	public static function serve_well_known() {
		if ( ! self::enabled() || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- path is only matched, never echoed.

		// When WordPress lives in a subdirectory, .well-known sits under the
		// HOME path — strip that prefix before matching.
		$home_path = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		if ( '' !== $home_path && '/' !== $home_path && 0 === strpos( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}

		if ( preg_match( '#^/\.well-known/oauth-authorization-server(/|$)#', $path ) ) {
			self::send_json( self::metadata() );
		}
		if ( preg_match( '#^/\.well-known/oauth-protected-resource(/|$)#', $path ) ) {
			self::send_json( self::resource_metadata() );
		}
	}

	/**
	 * Emit a public JSON document and stop. Metadata is public by definition
	 * (RFC 8414), so CORS is wide open on purpose.
	 *
	 * @param array $data Payload.
	 */
	private static function send_json( $data ) {
		// We intercept on template_redirect, where WordPress has already resolved
		// the unknown /.well-known/ URL to a 404 and queued that status. Force 200
		// so the metadata is served as a normal document, not an error.
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( $data ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON endpoint, wp_json_encode output.
		exit;
	}

	/**
	 * REST endpoints for registration and token exchange. Both are public by
	 * OAuth specification (the "client" is not a WordPress user); abuse is
	 * limited per IP, and neither grants anything without a consented code.
	 */
	public static function register_routes() {
		if ( ! self::enabled() ) {
			return;
		}
		register_rest_route(
			self::ns(),
			'/oauth/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_register' ),
				'permission_callback' => '__return_true', // Public by RFC 7591; rate-limited, grants no access by itself.
			)
		);
		register_rest_route(
			self::ns(),
			'/oauth/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_token' ),
				'permission_callback' => '__return_true', // Public by RFC 6749; PKCE + single-use consented codes gate it.
			)
		);
		register_rest_route(
			self::ns(),
			'/oauth/revoke',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_revoke' ),
				'permission_callback' => '__return_true', // Public by RFC 7009: the presented token IS the credential.
			)
		);
	}

	/**
	 * POST /oauth/register.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_register( $request ) {
		if ( ! self::within_rate_limit( 'reg', 10 ) ) {
			return self::rest_json(
				array(
					'error'             => 'invalid_client_metadata',
					'error_description' => 'Too many registrations, try later.',
				),
				429
			);
		}
		$body = $request->get_json_params();
		$out  = self::register_client( is_array( $body ) ? $body : array() );
		return self::rest_json( $out, isset( $out['error'] ) ? 400 : 201 );
	}

	/**
	 * POST /oauth/token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_token( $request ) {
		if ( ! self::within_rate_limit( 'tok', 60 ) ) {
			return self::rest_json(
				array(
					'error'             => 'invalid_grant',
					'error_description' => 'Too many attempts, try later.',
				),
				429
			);
		}
		$params = $request->get_body_params();
		if ( empty( $params ) ) {
			$json   = $request->get_json_params();
			$params = is_array( $json ) ? $json : array();
		}
		$out = self::redeem_code( $params );
		if ( isset( $out['error'] ) ) {
			return self::rest_json( $out, 'invalid_client' === $out['error'] ? 401 : 400 );
		}
		return self::rest_json( $out, 200 );
	}

	/**
	 * POST /oauth/revoke — RFC 7009 token revocation.
	 *
	 * The presented token is itself the credential, so the endpoint needs no
	 * further authentication. RFC 7009 section 2.2 requires HTTP 200 with an
	 * empty body whenever the request is well formed, whether or not the token
	 * existed: an attacker must not be able to tell a valid token from an
	 * invalid one by the response. The only non-200 answers are a missing
	 * `token` parameter (400, per section 2.1) and the rate limit.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_revoke( $request ) {
		// Defence in depth only. Guessing a token is infeasible (192 bits), but a
		// public endpoint that touches stored state gets the same brake as the
		// other two. The bound is generous: a hub revokes on disconnect, rarely.
		if ( ! self::within_rate_limit( 'rev', 60 ) ) {
			return self::rest_json(
				array(
					'error'             => 'invalid_request',
					'error_description' => 'Too many attempts, try later.',
				),
				429
			);
		}

		$params = $request->get_body_params();
		if ( empty( $params ) ) {
			$json   = $request->get_json_params();
			$params = is_array( $json ) ? $json : array();
		}

		// `token` must be a string: a caller can post token[]=x, and casting an
		// array raises a PHP warning and yields the literal "Array". A length cap
		// keeps an unauthenticated request from making the site hash megabytes.
		// Issued tokens are 54 characters ("abmcp_" + 48 hex); 512 is generous.
		$token = isset( $params['token'] ) && is_string( $params['token'] ) ? $params['token'] : '';
		if ( strlen( $token ) > 512 ) {
			$token = '';
		}
		if ( '' === $token ) {
			// RFC 7009 section 2.1: `token` is REQUIRED. A request without it is
			// malformed, not "a token that happens not to exist".
			return self::rest_json(
				array(
					'error'             => 'invalid_request',
					'error_description' => 'Parameter token is required.',
				),
				400
			);
		}

		// token_type_hint (section 2.1) is optional and advisory. This server
		// issues exactly one kind of token, so an unknown or wrong hint must not
		// change the outcome — RFC 7009 requires the server to keep searching.
		self::revoke_token( $token );

		$response = new WP_REST_Response( null, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/**
	 * Revoke one access token. Pure logic — no HTTP, so it is testable and can
	 * be called from elsewhere.
	 *
	 * @param string $token Presented plaintext token.
	 * @return bool True when a stored token was removed, false when none matched.
	 */
	public static function revoke_token( $token ) {
		$token = (string) $token;
		if ( '' === $token ) {
			return false;
		}

		// Tokens are stored as SHA-256 hashes only (see AB_MCP_Settings::add_token),
		// so revocation works on the hash of what was presented.
		$hash  = hash( 'sha256', $token );
		$known = false;
		foreach ( AB_MCP_Settings::get_tokens() as $entry ) {
			// hash_equals for the comparison, and no early break: the loop costs
			// the same whether the match sits first or last, so its duration says
			// nothing about WHERE a token is stored.
			if ( ! empty( $entry['hash'] ) && hash_equals( (string) $entry['hash'], $hash ) ) {
				$known = true;
			}
		}

		// One residual difference is accepted knowingly: a hit writes the option,
		// a miss does not, and that is measurable. The alternative — writing on
		// every request — would turn a public endpoint into a write amplifier that
		// anyone can aim at the database with junk tokens. Guessing a token to
		// exploit the timing means guessing 192 bits; the write amplifier needs
		// nothing but a loop. The cheaper attack is the one that gets closed.
		if ( $known ) {
			AB_MCP_Settings::delete_token( $hash );
		}

		return $known;
	}

	/**
	 * The label a connection gets in the settings list, derived from the OAuth
	 * client that created it.
	 *
	 * A connection made through the AlphaBridge Connect hub is labelled with the
	 * hub's name alone. Appending "· Claude Connect" as well would read
	 * "AlphaBridge Connect · Claude Connect", which says the same thing twice and
	 * hides which of the two the site owner is actually looking at.
	 *
	 * @param string $client_name RFC 7591 `client_name` of the registered client.
	 * @return string
	 */
	public static function connection_label( $client_name ) {
		$client_name = trim( (string) $client_name );

		if ( 0 === strcasecmp( $client_name, self::CONNECT_CLIENT_NAME ) ) {
			return self::CONNECT_CLIENT_NAME;
		}

		return $client_name . ' · Claude Connect';
	}

	/**
	 * JSON REST response with no-store (token responses carry the secret).
	 *
	 * @param array $data   Payload.
	 * @param int   $status HTTP status.
	 * @return WP_REST_Response
	 */
	private static function rest_json( $data, $status ) {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/* ----------------------------------------------------- authorize/consent */

	/**
	 * The authorization endpoint: /?ab_mcp_oauth=authorize. GET renders the
	 * consent screen (behind wp-login), POST (nonce-checked) issues the code
	 * and redirects back to the client.
	 */
	public static function handle_authorize() {
		if ( ! isset( $_GET['ab_mcp_oauth'] ) || 'authorize' !== $_GET['ab_mcp_oauth'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only; the consent POST below is nonce-verified.
			return;
		}
		if ( ! self::enabled() ) {
			self::error_page( __( 'Connecting from Claude is disabled on this site.', 'alphabridge-mcp' ) );
		}

		// Read the OAuth request parameters from $_REQUEST so the SAME fields are
		// seen on both legs: the initial GET (Claude sends them as query params on
		// the authorization_endpoint) and the approval POST (the consent form
		// resubmits them as hidden fields; only ab_mcp_oauth is in the action URL).
		// Every value is validated strictly against the registered client below
		// and never reaches output or a redirect unvalidated; the state-changing
		// POST branch is additionally gated by the nonce + login + capability.
		$get = wp_unslash( $_REQUEST ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth params validated against the registered client; the mutation below is nonce-verified.

		$client_id = isset( $get['client_id'] ) ? (string) $get['client_id'] : '';
		$client    = self::get_client( $client_id );
		if ( false === $client ) {
			self::error_page( __( 'Unknown client. Ask the connecting app to register again.', 'alphabridge-mcp' ) );
		}

		$redirect = isset( $get['redirect_uri'] ) ? (string) $get['redirect_uri'] : '';
		if ( ! in_array( $redirect, (array) $client['redirect_uris'], true ) ) {
			// Unregistered target: error PAGE — never redirect to it.
			self::error_page( __( 'The redirect address is not registered for this client.', 'alphabridge-mcp' ) );
		}

		$state = isset( $get['state'] ) ? substr( (string) $get['state'], 0, 512 ) : '';

		$response_type = isset( $get['response_type'] ) ? (string) $get['response_type'] : '';
		if ( 'code' !== $response_type ) {
			self::client_error_redirect( $redirect, 'unsupported_response_type', $state );
		}

		$challenge = isset( $get['code_challenge'] ) ? (string) $get['code_challenge'] : '';
		$method    = isset( $get['code_challenge_method'] ) ? (string) $get['code_challenge_method'] : '';
		if ( '' === $challenge || 'S256' !== $method || ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $challenge ) ) {
			self::client_error_redirect( $redirect, 'invalid_request', $state );
		}

		// The audience, when named, must be this site's MCP endpoint.
		if ( isset( $get['resource'] ) && '' !== (string) $get['resource']
			&& untrailingslashit( (string) $get['resource'] ) !== self::resource() ) {
			self::client_error_redirect( $redirect, 'invalid_target', $state );
		}

		if ( ! is_user_logged_in() ) {
			auth_redirect(); // Sends to wp-login and returns here afterwards.
			exit;
		}

		/**
		 * Capability required to approve a Claude Connect authorization.
		 * Connections act as the approving user, so this defaults to admins —
		 * the same audience that can create tokens manually.
		 *
		 * @param string $capability Capability name.
		 */
		$cap = apply_filters( 'ab_mcp_oauth_capability', 'manage_options' );
		if ( ! current_user_can( $cap ) ) {
			self::error_page( __( 'Your account is not allowed to approve connections on this site. Ask an administrator.', 'alphabridge-mcp' ) );
		}

		$scopes = AB_MCP_Tool_Registry::scopes();

		// Approval POST: issue the code and send the user back to the client.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'ab_mcp_oauth_approve' ) ) {
				self::error_page( __( 'The approval expired. Please try connecting again.', 'alphabridge-mcp' ) );
			}
			if ( isset( $_POST['ab_deny'] ) ) {
				self::client_error_redirect( $redirect, 'access_denied', $state );
			}
			// Clamped fail-closed to a known scope inside create_auth_code().
			// Absent field falls back to the narrower "content", never "full".
			$scope = isset( $_POST['ab_scope'] ) ? sanitize_text_field( wp_unslash( $_POST['ab_scope'] ) ) : 'content';
			$code  = self::create_auth_code( $client_id, $redirect, get_current_user_id(), $scope, $challenge );

			// RFC 9207: identify the issuer in the authorization response so the
			// client can detect a mix-up if it talks to several servers.
			$location = $redirect . ( false === strpos( $redirect, '?' ) ? '?' : '&' )
				. 'code=' . rawurlencode( $code )
				. '&iss=' . rawurlencode( self::issuer() )
				. ( '' !== $state ? '&state=' . rawurlencode( $state ) : '' );
			wp_redirect( $location ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- target is an exact pre-registered client redirect_uri, validated above.
			exit;
		}

		self::consent_page( $client, $get, $state, $scopes );
	}

	/**
	 * Redirect an OAuth protocol error back to the (already validated) client.
	 *
	 * @param string $redirect Registered redirect_uri.
	 * @param string $error    RFC 6749 error code.
	 * @param string $state    Opaque client state.
	 */
	private static function client_error_redirect( $redirect, $error, $state ) {
		$location = $redirect . ( false === strpos( $redirect, '?' ) ? '?' : '&' )
			. 'error=' . rawurlencode( $error )
			. '&iss=' . rawurlencode( self::issuer() ) // RFC 9207 issuer identification.
			. ( '' !== $state ? '&state=' . rawurlencode( $state ) : '' );
		wp_redirect( $location ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- target is an exact pre-registered client redirect_uri.
		exit;
	}

	/**
	 * Minimal, dependency-free error page (never leaks request data unescaped).
	 *
	 * @param string $message Human message.
	 */
	private static function error_page( $message ) {
		status_header( 400 );
		nocache_headers();
		self::send_frame_deny();
		wp_die( esc_html( $message ), esc_html__( 'AlphaBridge Connect', 'alphabridge-mcp' ), array( 'response' => 400 ) );
	}

	/**
	 * Refuse framing on the interactive pages. WordPress only emits frame
	 * protection on wp-admin / wp-login; the consent and error pages render on
	 * the front end, so we must send it ourselves — otherwise the consent screen
	 * could be clickjacked (UI-redressed) into an approval, since the CSRF nonce
	 * lives inside the real framed page and would pass.
	 */
	private static function send_frame_deny() {
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );
	}

	/**
	 * Render the consent screen. Self-contained styling — no theme involved.
	 *
	 * @param array  $client Registered client (name, redirect_uris).
	 * @param array  $get    Validated request parameters.
	 * @param string $state  Opaque state.
	 * @param array  $scopes scope => description.
	 */
	private static function consent_page( $client, $get, $state, $scopes ) {
		$user = wp_get_current_user();
		$site = get_bloginfo( 'name' );
		$icon = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 96 ) : '';

		// The destination host of the (already exact-match-validated) redirect_uri.
		// Shown prominently so the approver can see WHERE the authorization goes —
		// the client name alone is attacker-chosen and not trustworthy.
		$dest_host = (string) wp_parse_url( (string) $get['redirect_uri'], PHP_URL_HOST );

		// Default access level: never the widest. "content" lets Claude do the
		// common content work immediately; granting "full" is a deliberate choice.
		$default_scope = isset( $scopes['content'] ) ? 'content' : 'read';

		$self_url = home_url( '/?ab_mcp_oauth=authorize' );

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		self::send_frame_deny();
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'Connect to %s', 'alphabridge-mcp' ), $site ) ); ?></title>
<style>
	body{margin:0;font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f0f0f1;color:#1d2327;display:flex;min-height:100vh;align-items:center;justify-content:center}
	.card{background:#fff;border:1px solid #dcdcde;border-radius:10px;max-width:440px;width:calc(100% - 32px);padding:32px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
	.icon{width:56px;height:56px;border-radius:12px;display:block;margin:0 auto 14px}
	h1{font-size:19px;margin:0 0 6px;text-align:center}
	p.sub{margin:0 0 20px;text-align:center;color:#50575e}
	.who{background:#f6f7f7;border:1px solid #e2e4e7;border-radius:6px;padding:10px 14px;margin:0 0 10px;font-size:13px;color:#3c434a}
	.dest{border:1px solid #dba617;background:#fcf9e8;border-radius:6px;padding:10px 14px;margin:0 0 16px;font-size:13px;color:#3c434a}
	.dest b{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;word-break:break-all}
	label{display:block;font-weight:600;margin:0 0 6px}
	select{width:100%;padding:8px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;margin:0 0 6px;background:#fff}
	p.scope-hint{font-size:12px;color:#646970;margin:0 0 20px}
	.actions{display:flex;gap:10px}
	button{flex:1;padding:10px 0;border-radius:6px;font-size:15px;font-weight:600;cursor:pointer}
	.allow{background:#2271b1;border:1px solid #2271b1;color:#fff}
	.allow:hover{background:#135e96}
	.deny{background:#fff;border:1px solid #8c8f94;color:#3c434a}
	p.fine{font-size:12px;color:#646970;margin:18px 0 0;text-align:center}
</style>
</head>
<body>
<div class="card">
		<?php
		if ( $icon ) :
			?>
			<img class="icon" src="<?php echo esc_url( $icon ); ?>" alt=""><?php endif; ?>
	<h1><?php echo esc_html( sprintf( /* translators: 1: client name, 2: site name */ __( '%1$s wants to connect to %2$s', 'alphabridge-mcp' ), $client['name'], $site ) ); ?></h1>
	<p class="sub"><?php esc_html_e( 'It will act on this website through the AlphaBridge MCP connection you approve here.', 'alphabridge-mcp' ); ?></p>
	<div class="who"><?php echo esc_html( sprintf( /* translators: %s: user login */ __( 'Approving as: %s — the connection can do at most what this account can do.', 'alphabridge-mcp' ), $user->user_login ) ); ?></div>
	<div class="dest">
		<?php
		echo wp_kses(
			sprintf(
				/* translators: %s: destination host name the user is redirected to */
				__( 'On approval you are sent to <b>%s</b>. Only continue if you recognise it as the app you are connecting.', 'alphabridge-mcp' ),
				esc_html( '' === $dest_host ? '(unknown)' : $dest_host )
			),
			array( 'b' => array() )
		);
		?>
	</div>
	<form method="post" action="<?php echo esc_url( $self_url ); ?>">
		<?php wp_nonce_field( 'ab_mcp_oauth_approve' ); ?>
		<input type="hidden" name="client_id" value="<?php echo esc_attr( (string) $get['client_id'] ); ?>">
		<input type="hidden" name="redirect_uri" value="<?php echo esc_attr( (string) $get['redirect_uri'] ); ?>">
		<input type="hidden" name="response_type" value="code">
		<input type="hidden" name="code_challenge" value="<?php echo esc_attr( (string) $get['code_challenge'] ); ?>">
		<input type="hidden" name="code_challenge_method" value="S256">
		<?php
		if ( isset( $get['resource'] ) ) :
			?>
			<input type="hidden" name="resource" value="<?php echo esc_attr( (string) $get['resource'] ); ?>"><?php endif; ?>
		<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>">
		<label for="ab_scope"><?php esc_html_e( 'Access level', 'alphabridge-mcp' ); ?></label>
		<select name="ab_scope" id="ab_scope">
			<?php foreach ( array( 'content', 'read', 'full' ) as $key ) : ?>
				<?php if ( isset( $scopes[ $key ] ) ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $default_scope ); ?>><?php echo esc_html( $scopes[ $key ] ); ?></option>
				<?php endif; ?>
			<?php endforeach; ?>
		</select>
		<p class="scope-hint"><?php esc_html_e( 'You can revoke this connection at any time under Settings → AlphaBridge MCP.', 'alphabridge-mcp' ); ?></p>
		<div class="actions">
			<button type="submit" class="allow" name="ab_allow" value="1"><?php esc_html_e( 'Allow', 'alphabridge-mcp' ); ?></button>
			<button type="submit" class="deny" name="ab_deny" value="1"><?php esc_html_e( 'Cancel', 'alphabridge-mcp' ); ?></button>
		</div>
	</form>
	<p class="fine"><?php esc_html_e( 'Powered by AlphaBridge MCP — the connection token is stored only as a hash and never shown again.', 'alphabridge-mcp' ); ?></p>
</div>
</body>
</html>
		<?php
		exit;
	}
}
