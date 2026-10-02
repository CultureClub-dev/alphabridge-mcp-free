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
 *  - Client ID Metadata Documents (CIMD, the registration MCP 2026-07-28
 *    prefers): a client id that is an HTTPS URL is resolved by fetching the
 *    JSON document there. This is the only outbound request the OAuth flow
 *    makes: on the consent page only once an approver is logged in, at the
 *    token endpoint only for the client id an approver consented to. The
 *    address is the app's choice either way, so cimd_fetch() limits where
 *    the request may go.
 *  - Authorization-code grant with PKCE (S256 REQUIRED, RFC 7636) and
 *    resource indication (RFC 8707); the issuer in every authorization
 *    response (RFC 9207).
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

	/**
	 * Wie lange ein Zaehlfenster gilt. Fest: kein Versuch verlaengert es.
	 *
	 * Eine Ausnahme, weil sie sonst niemand kennt: laeuft die Uhr rueckwaerts
	 * (NTP-Sprung, VM-Schnappschuss), greift der Ablauf bei einem negativen
	 * Abstand nicht, und die Restlaufzeit faellt um die Sprunggroesse zu gross
	 * aus — das Fenster gilt dann laenger als eine Stunde. Es heilt von selbst
	 * und ist nach oben durch den Sprung begrenzt, aber «nicht verlaengerbar»
	 * waere eine Zusage, die der Code nicht einloest.
	 *
	 * Ein festes Fenster hat einen bekannten Randeffekt: wer das Fenster
	 * ausschoepft und gleich nach seinem Ende ein neues ausschoepft, bringt in
	 * wenigen Sekunden das Doppelte durch — bei dreissig also sechzig. Das ist in Kauf genommen. Ein gleitendes Fenster waere genauer,
	 * braeuchte aber je Absender eine Liste von Zeitpunkten in wp_options —
	 * teurer als das, was es abwehrt. Und die Transienten von WordPress duerfen
	 * ohnehin frueher verschwinden; die Ablaufzeit ist eine Hoechstdauer, keine
	 * Zusage. Codex, 15.09.2026.
	 */
	const RATE_WINDOW_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Registrierungen je Absender und Fenster.
	 *
	 * Zehn waren zu wenig. Die Bremse ist nicht der Schutz — eine Registrierung
	 * gewaehrt fuer sich nichts, und gegen das Vollschreiben des Speichers hilft
	 * MAX_CLIENTS mit Verdraengung. Getroffen wurde fast nur ehrliche Nutzung:
	 * wer eine Verbindung herstellt, trennt und neu herstellt, war nach zehn
	 * Anlaeufen draussen. Ueber einen gehosteten Hub kommen ausserdem alle
	 * Verbindungen einer Site von wenigen Adressen. Am 15.09.2026 zweimal
	 * dagegengelaufen, beim blossen Pruefen.
	 */
	const MAX_REGISTRATIONS_PER_WINDOW = 30;
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
	 * A client that connects with a metadata document (CIMD) instead is tied to
	 * the domain that serves the document, and the consent screen names that
	 * domain; the name inside the document is still whatever that domain says.
	 */
	const CONNECT_CLIENT_NAME = 'AlphaBridge Connect';

	/**
	 * Limits for fetching a client metadata document. 5 KB is the maximum the
	 * CIMD draft recommends; a real document is a few hundred bytes. Five
	 * seconds bound the wait of the person on the consent screen.
	 */
	const CIMD_MAX_BYTES = 5120;
	const CIMD_TIMEOUT   = 5;

	/**
	 * How long a fetched document is reused, in seconds, when its response
	 * names no lifetime (Cache-Control max-age), and the most it is ever kept.
	 * One connection takes three requests within a few minutes (consent page,
	 * approval, token), so five minutes cover it without a second fetch; the
	 * upper bound keeps a withdrawn redirect address from living on for long.
	 */
	const CIMD_DEFAULT_TTL = 300;
	const CIMD_MAX_TTL     = DAY_IN_SECONDS;

	/**
	 * Address ranges a metadata document is never fetched from: this machine,
	 * private and shared networks, link-local (cloud metadata services live at
	 * 169.254.169.254), documentation, benchmarking, multicast and reserved
	 * space, and the IPv6 forms that can carry one of those inside (mapped,
	 * translated, IPv4-compatible, NAT64 with the well-known and the local
	 * prefix, 6to4, Teredo). WordPress's own check for safe requests misses
	 * link-local, shared address space and IPv6.
	 */
	const NON_PUBLIC_RANGES = array(
		'0.0.0.0/8',
		'10.0.0.0/8',
		'100.64.0.0/10',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'172.16.0.0/12',
		'192.0.0.0/24',
		'192.0.2.0/24',
		'192.168.0.0/16',
		'198.18.0.0/15',
		'198.51.100.0/24',
		'203.0.113.0/24',
		'224.0.0.0/4',
		'240.0.0.0/4',
		'::/96',
		'::ffff:0:0/96',
		'::ffff:0:0:0/96',
		'64:ff9b::/96',
		'64:ff9b:1::/48',
		'100::/64',
		'2001::/32',
		'2001:2::/48',
		'2001:db8::/32',
		'2002::/16',
		'3fff::/20',
		'fc00::/7',
		'fe80::/10',
		'fec0::/10',
		'ff00::/8',
	);

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
		$meta = array(
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
		// Dynamic registration stays: clients that only know it keep working.
		if ( self::cimd_enabled() ) {
			$meta['client_id_metadata_document_supported'] = true;
		}
		return $meta;
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

		$clean_uris = self::clean_redirect_uris( isset( $body['redirect_uris'] ) ? $body['redirect_uris'] : null );
		if ( isset( $clean_uris['error'] ) ) {
			return $clean_uris;
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
	 * The redirect addresses a client may use, checked the same way for a
	 * registration and for a metadata document: at most 20, each https (or
	 * http on a loopback host, which native and development clients use by
	 * spec), without a fragment, at most 2000 characters.
	 *
	 * @param mixed $uris redirect_uris as the client sent them.
	 * @return array The addresses as a list, or array( 'error' => …, 'error_description' => … ).
	 */
	private static function clean_redirect_uris( $uris ) {
		$uris = is_array( $uris ) ? $uris : array();
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
			$uri   = is_string( $uri ) ? $uri : '';
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
		return $clean_uris;
	}

	/**
	 * Look up a client: a registered one, or one named by the URL of its
	 * metadata document.
	 *
	 * @param string $client_id Client id.
	 * @return array|false Name and redirect_uris, or false.
	 */
	public static function get_client( $client_id ) {
		$client = self::resolve_client( $client_id );
		return is_wp_error( $client ) ? false : $client;
	}

	/**
	 * Look up a client, with the reason when there is none.
	 *
	 * A client id that is an HTTPS URL is a Client ID Metadata Document: the
	 * document at that URL describes the client, no registration needed.
	 * Every other id must have been registered here.
	 *
	 * @param string $client_id Client id.
	 * @return array|WP_Error Name and redirect_uris ('cimd' => true for a
	 *                        document), or what went wrong, said so that the
	 *                        person on the consent screen can act on it.
	 */
	public static function resolve_client( $client_id ) {
		$client_id = (string) $client_id;

		if ( self::is_cimd_client_id( $client_id ) ) {
			if ( ! self::cimd_enabled() ) {
				return new WP_Error( 'ab_mcp_cimd_off', __( 'This site does not accept apps that identify themselves with a metadata document (switched off under Settings → AlphaBridge MCP → Your connections → "Connect from Claude (OAuth, advanced)" → "Accept apps with a metadata document", by WP_HTTP_BLOCK_EXTERNAL or by the ab_mcp_oauth_cimd filter). The app can register with the site instead.', 'alphabridge-mcp' ) );
			}
			return self::cimd_client( $client_id );
		}

		$clients = get_option( self::OPT_CLIENTS, array() );
		if ( is_array( $clients ) && isset( $clients[ $client_id ] ) ) {
			return $clients[ $client_id ];
		}
		return new WP_Error( 'ab_mcp_unknown_client', __( 'Unknown client. Ask the connecting app to register again.', 'alphabridge-mcp' ) );
	}

	/* ------------------------------------- client id metadata documents */

	/**
	 * Whether this site resolves client ids that are URLs. On by default; off
	 * by the setting, or while WordPress blocks outgoing requests. Off leaves
	 * dynamic registration as the only way in, as before CIMD existed.
	 *
	 * Advertising CIMD where the documents cannot be loaded would cost
	 * connections: a client prefers CIMD over registration as soon as the
	 * server metadata offers it, and would then fail on every attempt.
	 *
	 * @return bool
	 */
	public static function cimd_enabled() {
		$enabled = (bool) AB_MCP_Settings::get( 'oauth_cimd', true ) && ! self::outbound_blocked();

		/**
		 * Whether OAuth clients may identify themselves with a Client ID
		 * Metadata Document (an HTTPS URL as client_id). Off, such a client id
		 * is unknown, as before, and the discovery metadata stops advertising
		 * it. The setting and WP_HTTP_BLOCK_EXTERNAL provide the starting
		 * value; the filter has the last word, for example on a site that
		 * blocks outgoing requests but lists the app hosts in
		 * WP_ACCESSIBLE_HOSTS.
		 *
		 * @param bool $enabled Starting value.
		 */
		return (bool) apply_filters( 'ab_mcp_oauth_cimd', $enabled );
	}

	/**
	 * Whether WordPress refuses requests to other servers
	 * (WP_HTTP_BLOCK_EXTERNAL). Which app hosts WP_ACCESSIBLE_HOSTS lets
	 * through cannot be known in advance, so a block counts as a block.
	 *
	 * @return bool
	 */
	public static function outbound_blocked() {
		return defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL;
	}

	/**
	 * Whether a client id names a metadata document. Registered ids start
	 * with CLIENT_PREFIX, so the two can never be confused.
	 *
	 * @param mixed $client_id Client id.
	 * @return bool
	 */
	public static function is_cimd_client_id( $client_id ) {
		return is_string( $client_id ) && 0 === strpos( $client_id, 'https://' );
	}

	/**
	 * The client a metadata document describes, from the cache or fetched.
	 *
	 * @param string $url Client id, the document's URL.
	 * @return array|WP_Error
	 */
	private static function cimd_client( $url ) {
		$problem = self::cimd_url_problem( $url );
		if ( null !== $problem ) {
			return self::cimd_error( $url, $problem );
		}

		$key    = 'ab_mcp_cimd_' . substr( hash( 'sha256', $url ), 0, 40 );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && self::cimd_cache_valid( $key, $url, $cached ) ) {
			return $cached['client'];
		}

		$fetched = self::cimd_fetch( $url );
		if ( is_wp_error( $fetched ) ) {
			return $fetched;
		}

		if ( $fetched['ttl'] > 0 ) {
			$expires = time() + $fetched['ttl'];
			$sig     = self::cimd_sig( $key, $url, $fetched['client'], $expires );
			if ( null !== $sig ) {
				set_transient(
					$key,
					array(
						'client'  => $fetched['client'],
						'expires' => $expires,
						'sig'     => $sig,
					),
					$fetched['ttl']
				);
			}
		}
		return $fetched['client'];
	}

	/**
	 * What is wrong with a client id URL by the CIMD rules: https, a host, a
	 * path, no fragment, no user or password, no dot segments.
	 *
	 * @param string $url Client id.
	 * @return string|null The problem, or null.
	 */
	private static function cimd_url_problem( $url ) {
		if ( strlen( $url ) > 2000 || 1 === preg_match( '/[\x00-\x20\x7F]/', $url ) ) {
			return 'the address is too long or contains spaces or control characters';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || 'https' !== $parts['scheme'] ) {
			return 'the address is not a valid https URL';
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return 'the address must not contain a user name, a password or a fragment';
		}
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		if ( '' === $path || '/' === $path ) {
			return 'the address has no path; a client id names a document, for example https://app.example/client.json';
		}
		if ( 1 === preg_match( '#(^|/)\.{1,2}(/|$)#', $path ) ) {
			return 'the address contains "." or ".." path segments';
		}
		return null;
	}

	/**
	 * Fetch and check a metadata document. The request goes only to a public
	 * address, through WordPress's safe HTTP API, without following
	 * redirects, for at most CIMD_TIMEOUT seconds and CIMD_MAX_BYTES.
	 *
	 * The connection goes to the addresses checked here and nowhere else:
	 * cURL would otherwise look the name up again, and a name that answers
	 * differently the second time (DNS rebinding) could lead the request to
	 * an address never checked. CURLOPT_RESOLVE hands cURL the checked
	 * answer; the name still serves for TLS, so the certificate must match
	 * it. Without cURL (WordPress then falls back to fsockopen) and through
	 * a proxy, the name is looked up again on connecting, as before.
	 *
	 * @param string $url Document URL.
	 * @return array|WP_Error array( 'client' => …, 'ttl' => seconds to keep it ).
	 */
	private static function cimd_fetch( $url ) {
		$host      = (string) wp_parse_url( $url, PHP_URL_HOST );
		$addresses = self::cimd_host_addresses( $host );
		if ( is_string( $addresses ) ) {
			return self::cimd_error( $url, $addresses );
		}

		// The entry names host and port, so a request to any other host that
		// might run while the hook is in place keeps its own lookup.
		$resolve = self::curl_resolve_entry( $url, $addresses );
		$pin     = static function ( $handle ) use ( $resolve ) {
			if ( null !== $resolve ) {
				curl_setopt( $handle, CURLOPT_RESOLVE, array( $resolve ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- the WordPress HTTP API offers no other way to fix the address a name connects to.
			}
		};
		add_action( 'http_api_curl', $pin );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => self::CIMD_TIMEOUT,
				// The document must sit at its own URL (its client_id says so);
				// a redirect could only lead somewhere this check did not see.
				'redirection'         => 0,
				// One byte over the limit, so that an oversized document shows
				// as such instead of as a cut-off one.
				'limit_response_size' => self::CIMD_MAX_BYTES + 1,
				'headers'             => array( 'Accept' => 'application/json' ),
				// WordPress's default user agent names this site's address; the
				// document's host has no need to learn it.
				'user-agent'          => 'AlphaBridge-MCP/' . AB_MCP_VERSION,
			)
		);
		remove_action( 'http_api_curl', $pin );
		if ( is_wp_error( $response ) ) {
			return self::cimd_error( $url, 'it could not be loaded (' . $response->get_error_message() . ')' );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return self::cimd_error( $url, 'it answered HTTP ' . $code . ' instead of 200 (redirects are not followed)' );
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::CIMD_MAX_BYTES ) {
			return self::cimd_error( $url, 'it is larger than ' . self::CIMD_MAX_BYTES . ' bytes' );
		}
		$doc = json_decode( $body, true );
		if ( ! is_array( $doc ) ) {
			return self::cimd_error( $url, 'it is not a JSON object' );
		}

		$client = self::cimd_validate( $url, $doc );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$cache_control = wp_remote_retrieve_header( $response, 'cache-control' );
		return array(
			'client' => $client,
			'ttl'    => self::cimd_ttl( is_array( $cache_control ) ? implode( ',', $cache_control ) : (string) $cache_control ),
		);
	}

	/**
	 * Check a fetched document and take from it what the flow needs.
	 *
	 * @param string $url Document URL.
	 * @param array  $doc Decoded document.
	 * @return array|WP_Error Name, redirect_uris and 'cimd' => true.
	 */
	private static function cimd_validate( $url, array $doc ) {
		// Exact string comparison, as the draft requires: the document
		// vouches for this very URL, not for a variant of it.
		if ( ! isset( $doc['client_id'] ) || ! is_string( $doc['client_id'] ) || $doc['client_id'] !== $url ) {
			return self::cimd_error( $url, 'its client_id does not match its own address' );
		}
		if ( array_key_exists( 'client_secret', $doc ) || array_key_exists( 'client_secret_expires_at', $doc ) ) {
			return self::cimd_error( $url, 'it contains a client secret, which a public document must never carry' );
		}
		if ( isset( $doc['token_endpoint_auth_method'] ) && 'none' !== $doc['token_endpoint_auth_method'] ) {
			return self::cimd_error( $url, 'it asks for client authentication at the token endpoint; this site serves public clients ("none") only' );
		}
		if ( isset( $doc['grant_types'] ) && ( ! is_array( $doc['grant_types'] ) || ! in_array( 'authorization_code', $doc['grant_types'], true ) ) ) {
			return self::cimd_error( $url, 'its grant_types do not include authorization_code' );
		}
		if ( isset( $doc['response_types'] ) && ( ! is_array( $doc['response_types'] ) || ! in_array( 'code', $doc['response_types'], true ) ) ) {
			return self::cimd_error( $url, 'its response_types do not include code' );
		}

		$uris = self::clean_redirect_uris( isset( $doc['redirect_uris'] ) ? $doc['redirect_uris'] : null );
		if ( isset( $uris['error'] ) ) {
			return self::cimd_error( $url, 'its redirect_uris are missing or invalid (' . $uris['error_description'] . ')' );
		}

		$name = isset( $doc['client_name'] ) && is_string( $doc['client_name'] ) ? substr( sanitize_text_field( $doc['client_name'] ), 0, 80 ) : '';
		if ( '' === $name ) {
			return self::cimd_error( $url, 'it has no client_name' );
		}

		return array(
			'name'          => $name,
			'redirect_uris' => $uris,
			'cimd'          => true,
		);
	}

	/**
	 * The addresses a metadata host may be fetched from, or why it may not.
	 *
	 * Every address the host resolves to must be public, IPv4 and IPv6 alike,
	 * since the connection may use either. cimd_fetch() then connects to
	 * exactly these addresses, so a name that changes its answer in between
	 * (DNS rebinding) gains nothing.
	 *
	 * @param string $host Host of the document URL.
	 * @return string[]|string The addresses, or the problem.
	 */
	private static function cimd_host_addresses( $host ) {
		$host = strtolower( trim( (string) $host, '[]' ) );
		if ( '' === $host || 'localhost' === $host || '.localhost' === substr( $host, -10 ) ) {
			return 'its host is this machine';
		}

		/**
		 * The addresses a metadata host resolves to, before the plugin looks
		 * them up itself. Return a list to answer from elsewhere (a resolver
		 * of your own, a fixed mapping); null leaves the lookup to the plugin.
		 * Every address is checked either way: a filter cannot open the way
		 * to a private one. The request then connects to these addresses.
		 *
		 * @param string[]|null $addresses Null.
		 * @param string        $host      Host name.
		 */
		$addresses = apply_filters( 'ab_mcp_oauth_cimd_addresses', null, $host );
		if ( null === $addresses ) {
			$addresses = false !== filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : self::resolve_host( $host );
		}
		if ( ! is_array( $addresses ) || empty( $addresses ) ) {
			return 'its host name could not be resolved';
		}
		$checked = array();
		foreach ( $addresses as $address ) {
			if ( ! self::is_public_ip( (string) $address ) ) {
				return 'its host resolves to an address that is not public; documents are fetched only from public addresses';
			}
			$checked[] = (string) $address;
		}
		return $checked;
	}

	/**
	 * The CURLOPT_RESOLVE entry that ties a URL's host to checked addresses:
	 * "host:port:address,address". None for a host that is an address
	 * already, since nothing is looked up for it.
	 *
	 * The host is written as the URL spells it, which is what older cURL
	 * versions compare the entry with; every version reads a bare IPv6
	 * address here.
	 *
	 * @param string   $url       Document URL.
	 * @param string[] $addresses Checked addresses.
	 * @return string|null
	 */
	private static function curl_resolve_entry( $url, array $addresses ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $host || false !== filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) || empty( $addresses ) ) {
			return null;
		}
		$port = (int) wp_parse_url( $url, PHP_URL_PORT );
		return $host . ':' . ( $port > 0 ? $port : 443 ) . ':' . implode( ',', $addresses );
	}

	/**
	 * IPv4 and IPv6 addresses of a host name.
	 *
	 * @param string $host Host name.
	 * @return string[]
	 */
	private static function resolve_host( $host ) {
		$addresses = gethostbynamel( $host );
		$addresses = is_array( $addresses ) ? $addresses : array();
		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed lookup is handled as "no address", not as a PHP warning on the consent page.
			foreach ( is_array( $records ) ? $records : array() as $record ) {
				if ( isset( $record['ipv6'] ) ) {
					$addresses[] = (string) $record['ipv6'];
				}
			}
		}
		return $addresses;
	}

	/**
	 * Whether an address is a public one: valid, and in none of the
	 * NON_PUBLIC_RANGES.
	 *
	 * @param string $ip Address.
	 * @return bool
	 */
	public static function is_public_ip( $ip ) {
		$packed = false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? inet_pton( $ip ) : false;
		if ( false === $packed ) {
			return false;
		}
		foreach ( self::NON_PUBLIC_RANGES as $range ) {
			list( $network, $bits ) = explode( '/', $range );
			$net                    = inet_pton( $network );
			if ( false === $net || strlen( $net ) !== strlen( $packed ) ) {
				continue;
			}
			$bits  = (int) $bits;
			$bytes = intdiv( $bits, 8 );
			$rest  = $bits % 8;
			if ( substr( $packed, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
				continue;
			}
			if ( 0 === $rest ) {
				return false;
			}
			$mask = chr( ( 0xff << ( 8 - $rest ) ) & 0xff );
			if ( ( $packed[ $bytes ] & $mask ) === ( $net[ $bytes ] & $mask ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Seconds to keep a fetched document, from its Cache-Control header:
	 * no-store, no-cache and max-age=0 keep nothing, max-age is honoured up to
	 * CIMD_MAX_TTL, and without either CIMD_DEFAULT_TTL applies.
	 *
	 * @param string $cache_control Header value.
	 * @return int
	 */
	private static function cimd_ttl( $cache_control ) {
		$cache_control = strtolower( $cache_control );
		if ( false !== strpos( $cache_control, 'no-store' ) || false !== strpos( $cache_control, 'no-cache' ) ) {
			return 0;
		}
		if ( 1 === preg_match( '/(?:^|[,\s])max-age\s*=\s*"?(\d+)/', $cache_control, $m ) ) {
			return min( (int) $m[1], self::CIMD_MAX_TTL );
		}
		return self::CIMD_DEFAULT_TTL;
	}

	/**
	 * Signature over a cached document. The transient store is shared with
	 * every plugin and with generic "set transient" tools, like the consent
	 * records (see code_sig()): a cache entry written any other way could
	 * slip a foreign redirect address into a trusted client id.
	 *
	 * @param string $key     Transient key.
	 * @param string $url     Client id.
	 * @param array  $client  Cached client.
	 * @param int    $expires Unix time the entry ends.
	 * @return string|null
	 */
	private static function cimd_sig( $key, $url, array $client, $expires ) {
		$canonical = wp_json_encode(
			array(
				'key'           => (string) $key,
				'url'           => (string) $url,
				'name'          => isset( $client['name'] ) ? (string) $client['name'] : '',
				'redirect_uris' => isset( $client['redirect_uris'] ) && is_array( $client['redirect_uris'] ) ? array_values( $client['redirect_uris'] ) : array(),
				'expires'       => (int) $expires,
			)
		);
		if ( ! is_string( $canonical ) || '' === $canonical ) {
			return null;
		}
		return hash_hmac( 'sha256', $canonical, wp_salt( 'auth' ) );
	}

	/**
	 * Whether a cache entry is one cimd_client() wrote for this URL and is
	 * still within its lifetime (checked here too, as a rewritten transient
	 * can carry any expiry). Only name and redirect_uris are signed, so only
	 * those are taken from it.
	 *
	 * @param string $key   Transient key.
	 * @param string $url   Client id.
	 * @param array  $entry Cached entry.
	 * @return bool
	 */
	private static function cimd_cache_valid( $key, $url, array $entry ) {
		if ( ! isset( $entry['client'], $entry['expires'], $entry['sig'] ) || ! is_array( $entry['client'] ) || ! is_string( $entry['sig'] ) ) {
			return false;
		}
		if ( (int) $entry['expires'] < time() ) {
			return false;
		}
		if ( array_diff( array_keys( $entry['client'] ), array( 'name', 'redirect_uris', 'cimd' ) ) ) {
			return false;
		}
		$expected = self::cimd_sig( $key, $url, $entry['client'], (int) $entry['expires'] );
		return null !== $expected && hash_equals( $expected, $entry['sig'] );
	}

	/**
	 * An error about a metadata document that says where it lives and what
	 * is wrong, so the person approving can tell the app's maker.
	 *
	 * @param string $url     Document URL.
	 * @param string $problem What is wrong.
	 * @return WP_Error
	 */
	private static function cimd_error( $url, $problem ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return new WP_Error(
			'ab_mcp_cimd',
			sprintf(
				/* translators: 1: host name, 2: what is wrong (English, technical). */
				__( 'The app identifies itself with a metadata document on %1$s, and this site cannot use it: %2$s. If the document is at fault, the app\'s maker can fix it. Until then, or if this site cannot reach other servers, an administrator can switch off "Accept apps with a metadata document" under Settings → AlphaBridge MCP → Your connections → "Connect from Claude (OAuth, advanced)"; an app that can register with the site then does that instead.', 'alphabridge-mcp' ),
				'' !== $host ? $host : substr( $url, 0, 200 ),
				$problem
			)
		);
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
		$data = array(
			'client_id'    => (string) $client_id,
			'redirect_uri' => (string) $redirect_uri,
			'user_id'      => (int) $user_id,
			'scope'        => AB_MCP_Settings::sanitize_scope( $scope ),
			'challenge'    => (string) $challenge,
			'created'      => time(),
		);
		$key = self::code_key( $code );
		$sig = self::code_sig( $key, $data );
		if ( null === $sig ) {
			return ''; // Nothing stored: a record that cannot be signed cannot be redeemed either.
		}
		$data['sig'] = $sig;
		set_transient( $key, $data, self::CODE_TTL );
		return $code;
	}

	/**
	 * Signature over a consent record. The transient store is shared with every
	 * plugin on the site and with generic "set transient" tools, so a record
	 * found under a code key is not by itself proof that the consent step wrote
	 * it. The HMAC binds every field that redeem_code() trusts, plus the key the
	 * record is filed under, to this site's auth salt: a record written any
	 * other way, or moved under another code, does not verify.
	 *
	 * The fields are serialised as JSON with a fixed key order. A separator
	 * join would not do: a redirect URI may legally contain any character the
	 * client registered, so two different field splits could produce the same
	 * bytes. JSON escapes the boundaries.
	 *
	 * @param string $key  Transient key the record lives under.
	 * @param array  $data Consent record without its signature.
	 * @return string|null The signature, or null when the record cannot be
	 *                     serialised — signing an empty string instead would
	 *                     give every unencodable record the same signature.
	 */
	private static function code_sig( $key, array $data ) {
		$canonical = wp_json_encode(
			array(
				'key'          => (string) $key,
				'client_id'    => isset( $data['client_id'] ) ? (string) $data['client_id'] : '',
				'redirect_uri' => isset( $data['redirect_uri'] ) ? (string) $data['redirect_uri'] : '',
				'user_id'      => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
				'scope'        => isset( $data['scope'] ) ? (string) $data['scope'] : '',
				'challenge'    => isset( $data['challenge'] ) ? (string) $data['challenge'] : '',
				'created'      => isset( $data['created'] ) ? (int) $data['created'] : 0,
			)
		);
		if ( ! is_string( $canonical ) || '' === $canonical ) {
			return null;
		}
		return hash_hmac( 'sha256', $canonical, wp_salt( 'auth' ) );
	}

	/**
	 * Whether a stored consent record is one the consent step wrote for this
	 * key, is still within its lifetime, and names a scope the registry knows.
	 * The lifetime is checked here and not only through the transient's own
	 * expiry, because a rewritten transient can carry any expiry.
	 *
	 * @param string $key  Transient key the record was loaded from.
	 * @param array  $data Record as loaded from the transient store.
	 * @return bool
	 */
	private static function code_record_valid( $key, array $data ) {
		if ( ! isset( $data['sig'] ) || ! is_string( $data['sig'] ) ) {
			return false;
		}
		$expected = self::code_sig( $key, $data );
		if ( null === $expected || ! hash_equals( $expected, $data['sig'] ) ) {
			return false;
		}
		$created = isset( $data['created'] ) ? (int) $data['created'] : 0;
		if ( $created <= 0 || $created + self::CODE_TTL < time() ) {
			return false;
		}
		$scope = isset( $data['scope'] ) ? (string) $data['scope'] : '';
		if ( ! array_key_exists( $scope, AB_MCP_Tool_Registry::scopes() ) ) {
			return false;
		}
		return isset( $data['user_id'] ) && (int) $data['user_id'] > 0;
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
		// A record that did not come from create_auth_code() for this very key,
		// or that has outlived a code's two minutes, must not become a token,
		// whatever it says about user and scope.
		if ( ! self::code_record_valid( $key, $data ) ) {
			return array( 'error' => 'invalid_grant' );
		}

		// The id is compared before the client is looked up: looking up an id
		// that is a URL fetches it, and the id in this request is the
		// caller's choice, while the one in the record is what an approver
		// consented to.
		$client_id = isset( $p['client_id'] ) ? (string) $p['client_id'] : '';
		if ( ! hash_equals( (string) $data['client_id'], $client_id ) ) {
			return array( 'error' => 'invalid_client' );
		}
		$client = self::get_client( $client_id );
		if ( false === $client ) {
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
		$token = AB_MCP_Settings::add_token( (int) $data['user_id'], $label, (string) $data['scope'], $expires, $client_id );

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
	public static function within_rate_limit( $bucket, $max ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- used only hashed, for a counter key.
		$key = 'ab_mcp_o' . $bucket . '_' . md5( $ip );
		$now = time();

		// Zaehler und Fensteranfang gehoeren zusammen: ohne Anfang ist ein
		// Zaehler wertlos, weil niemand weiss, wann er ablaeuft. Darum werden
		// beide nur gemeinsam uebernommen. Eine aeltere Fassung legte hier eine
		// blosse Zahl ab; die faellt damit weg und kostet einmalig eine
		// Schonfrist — die harmlosere Seite des Irrtums.
		$data  = get_transient( $key );
		$start = 0;
		$count = 0;
		if ( is_array( $data ) && isset( $data['start'], $data['count'] ) ) {
			$start = (int) $data['start'];
			$count = (int) $data['count'];
		}

		if ( 0 === $start || $now - $start >= self::RATE_WINDOW_SECONDS ) {
			$start = $now;
			$count = 0;
		}

		if ( $count >= $max ) {
			return false;
		}

		// Die Laufzeit ist der REST des Fensters, nicht eine neue volle Stunde.
		// Mit einer vollen Stunde je Schreibvorgang begann die Zaehlung erst nach
		// einer Stunde ohne zugelassene Anfrage von vorn: zehn Anfragen mit
		// jeweils weniger als einer Stunde Abstand erreichten die Grenze, auch
		// ueber einen Nachmittag verteilt. Abgewiesene Anfragen schreiben nicht
		// (siehe oben), eine Sperre lief also eine Stunde nach der zehnten
		// zugelassenen Anfrage ab. Eine fruehere Fassung dieses Kommentars
		// behauptete «kommt nie wieder heraus» - das stimmte nicht.
		$rest = self::RATE_WINDOW_SECONDS - ( $now - $start );
		set_transient( $key, array( 'count' => $count + 1, 'start' => $start ), max( 1, $rest ) );
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
		if ( ! self::within_rate_limit( 'reg', self::MAX_REGISTRATIONS_PER_WINDOW ) ) {
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
	 * client that created it: the client's name and "OAuth", for example
	 * "ChatGPT · OAuth".
	 *
	 * The suffix names the way the connection was made, not a product. It used
	 * to read "· Claude Connect", which put Claude's name on every client that
	 * connects this way — "ChatGPT · Claude Connect" on 26.09.2026. "OAuth" is
	 * true for all of them and reads the same in every language; the label is
	 * stored as it is, it is not translated later.
	 *
	 * A connection made through the AlphaBridge Connect hub is labelled with the
	 * hub's name alone: the site owner should see at a glance that it came
	 * through the hub. A registration without a name is stored as "MCP client"
	 * (register_client()), so an empty name only reaches this helper for a client
	 * record that has none; it then reads "OAuth" alone rather than a dangling
	 * separator.
	 *
	 * @param string $client_name RFC 7591 `client_name` of the registered client.
	 * @return string
	 */
	public static function connection_label( $client_name ) {
		$client_name = trim( (string) $client_name );

		if ( 0 === strcasecmp( $client_name, self::CONNECT_CLIENT_NAME ) ) {
			return self::CONNECT_CLIENT_NAME;
		}

		if ( '' === $client_name ) {
			return 'OAuth';
		}

		return $client_name . ' · OAuth';
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

		// A client id that is a URL makes this site fetch it. Only a logged-in
		// approver may set that off, the order the MCP specification draws
		// (authenticate, then fetch): otherwise any visitor could have the site
		// request addresses of their choosing through this page.
		if ( self::is_cimd_client_id( $client_id ) ) {
			self::require_approver();
		}

		$client = self::resolve_client( $client_id );
		if ( is_wp_error( $client ) ) {
			self::error_page( $client->get_error_message() );
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

		self::require_approver();

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
			// An absent field means the choice the page opened with: the full
			// access level. Whether the connection may write is decided by the
			// switch for write access on the site, off until an administrator
			// confirms it (AB_MCP_Site_Mode), not by the access level.
			$scope = isset( $_POST['ab_scope'] ) ? sanitize_text_field( wp_unslash( $_POST['ab_scope'] ) ) : self::default_scope( $scopes );
			$code  = self::create_auth_code( $client_id, $redirect, get_current_user_id(), $scope, $challenge );
			if ( '' === $code ) {
				self::error_page( __( 'The connection could not be started. Please try again.', 'alphabridge-mcp' ) );
			}

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
	 * Stop unless a logged-in user who may approve connections is here: send
	 * a visitor to wp-login (and back), refuse anyone else.
	 */
	private static function require_approver() {
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
	 * The access level checked when the consent screen opens: the full one.
	 * Whoever connects an assistant wants it to do everything they ask; what
	 * can harm the site is held back by the switch for write access, which
	 * every site starts with off and only an administrator switches on, with
	 * a confirmed notice (AB_MCP_Site_Mode). The screen says so next to the
	 * choice. A narrower level stays a deliberate choice; without a full
	 * level among the scopes, the narrowest one.
	 *
	 * @param array $scopes Scope => sentence.
	 * @return string
	 */
	private static function default_scope( array $scopes ) {
		return isset( $scopes['full'] ) ? 'full' : 'read';
	}

	/**
	 * The choice of access level on the consent screen: one radio button per
	 * scope, narrowest first, each with its name and one sentence on what the
	 * connection may then do and what not (AB_MCP_Tool_Registry::scopes()).
	 *
	 * Posts ab_scope as the select before it did; the approval reads it the
	 * same way and clamps it fail-closed.
	 *
	 * @param array  $scopes  Scope => sentence.
	 * @param string $default The scope checked when the page opens.
	 * @return string HTML, escaped.
	 */
	private static function scope_choices_html( array $scopes, $default ) {
		$labels = AB_MCP_Tool_Registry::scope_labels();
		$html   = '<fieldset class="scopes"><legend>' . esc_html__( 'Access level', 'alphabridge-mcp' ) . '</legend>';
		foreach ( $scopes as $key => $sentence ) {
			$name  = isset( $labels[ $key ] ) ? $labels[ $key ] : (string) $key;
			$html .= '<label class="scope"><input type="radio" name="ab_scope" value="' . esc_attr( (string) $key ) . '"' . checked( (string) $key, (string) $default, false ) . '>';
			$html .= '<span><b>' . esc_html( $name ) . '</b><span class="scope-text">' . esc_html( (string) $sentence ) . '</span></span></label>';
		}
		return $html . '</fieldset>';
	}

	/**
	 * Send the consent screen and end the request.
	 *
	 * @param array  $client Registered client (name, redirect_uris).
	 * @param array  $get    Validated request parameters.
	 * @param string $state  Opaque state.
	 * @param array  $scopes scope => description.
	 */
	private static function consent_page( $client, $get, $state, $scopes ) {
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		self::send_frame_deny();
		self::render_consent( $client, $get, $state, $scopes );
		exit;
	}

	/**
	 * Echo the consent screen. Self-contained styling — no theme involved.
	 *
	 * Apart from consent_page(), which sends the headers and ends the request,
	 * so that the page itself, the access level checked when it opens
	 * included, can be rendered and looked at on its own.
	 *
	 * Above the access levels the page says that the switch for write
	 * access on the site decides whether assistants may write, whatever level
	 * is chosen, and whether it is on: the level is granted as chosen, but
	 * with write access off the connection reads only, and approving should
	 * not suggest otherwise.
	 *
	 * @param array  $client Registered client (name, redirect_uris).
	 * @param array  $get    Validated request parameters.
	 * @param string $state  Opaque state.
	 * @param array  $scopes scope => description.
	 */
	private static function render_consent( $client, $get, $state, $scopes ) {
		$user = wp_get_current_user();
		$site = get_bloginfo( 'name' );
		$icon = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 96 ) : '';

		// The destination host of the (already exact-match-validated) redirect_uri.
		// Shown prominently so the approver can see WHERE the authorization goes —
		// the client name alone is attacker-chosen and not trustworthy.
		$dest_host = (string) wp_parse_url( (string) $get['redirect_uri'], PHP_URL_HOST );

		$default_scope = self::default_scope( $scopes );

		$self_url = home_url( '/?ab_mcp_oauth=authorize' );
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
	fieldset.scopes{border:0;margin:0 0 6px;padding:0;min-width:0}
	fieldset.scopes legend{font-weight:600;margin:0 0 6px;padding:0}
	label.scope{display:flex;gap:10px;align-items:flex-start;margin:0 0 8px;padding:10px 12px;border:1px solid #dcdcde;border-radius:6px;cursor:pointer}
	label.scope:has(input:checked){border-color:#2271b1;background:#f0f6fc}
	label.scope input{margin:4px 0 0;flex:none}
	label.scope b{display:block}
	label.scope span.scope-text{display:block;font-size:13px;color:#50575e}
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
	<?php if ( in_array( strtolower( $dest_host ), array( 'localhost', '127.0.0.1', '[::1]', '::1' ), true ) ) : ?>
		<div class="dest"><?php esc_html_e( 'That address is on your own computer, where any program can use it, so the app\'s name proves nothing here. Only continue if you started this connection yourself just now.', 'alphabridge-mcp' ); ?></div>
	<?php endif; ?>
	<?php if ( ! empty( $client['cimd'] ) ) : ?>
		<div class="who">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: host name serving the app's metadata document */
				__( 'The app identifies itself with a document on %s; its name above comes from there.', 'alphabridge-mcp' ),
				(string) wp_parse_url( (string) $get['client_id'], PHP_URL_HOST )
			)
		);
		?>
		</div>
	<?php endif; ?>
	<div class="<?php echo esc_attr( AB_MCP_Site_Mode::is_full() ? 'who mode-full' : 'dest mode-read' ); ?>" role="note"><?php echo esc_html( AB_MCP_Site_Mode::consent_text() ); ?></div>
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
		<?php echo self::scope_choices_html( $scopes, $default_scope ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside scope_choices_html(). ?>
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
	}
}
