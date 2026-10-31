<?php
/**
 * A request to another site, tied to the addresses that were checked
 * (free since 4.15.0).
 *
 * wp_http_validate_url() and wp_safe_remote_get() look a host name up once,
 * IPv4 only (gethostbyname()), and the connection is made later by its own
 * lookup: a name that answers with a public address at the check and with
 * an internal one at the connection (DNS rebinding), or whose AAAA record
 * points inside, reaches what the check was to keep out. get() closes
 * that: it looks every A and AAAA address of the host up, refuses the
 * request when any of them is not public (AB_MCP_OAuth::is_public_ip(),
 * IPv4, IPv6 and IPv4 mapped into IPv6), and has cURL connect to exactly
 * these addresses for this one request (CURLOPT_RESOLVE on http_api_curl,
 * hooked for the call and taken off after it), as the fetch of a client
 * metadata document does (AB_MCP_OAuth::cimd_fetch()). Where WordPress
 * would send the request without cURL, it is refused before a connection
 * is made (the fsockopen transport's first hook), and an answer that came
 * any other way without the binding is discarded. No redirect is followed:
 * its target would be looked up unbound. Nor is a request sent where
 * WordPress would send it through a proxy (WP_PROXY_HOST): the proxy looks
 * the name up itself, which no binding reaches; a host in
 * WP_PROXY_BYPASS_HOSTS goes direct and is tied as above.
 *
 * Used by wp_staging_status to ask the other site of a staging pair
 * (AB_MCP_Staging::check_partner()).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Outbound
 */
class AB_MCP_Outbound {

	/**
	 * The message of the refusal on the fsockopen transport, by which get()
	 * knows it again in the WP_Error WordPress makes of it.
	 */
	const UNBOUND = 'Not sent: without cURL the request cannot be tied to the checked addresses.';

	/**
	 * Every IPv4 and IPv6 address of a host name: its A and AAAA records,
	 * and where the DNS query brings none (no dns_get_record(), a resolver
	 * that refuses the query), the IPv4 addresses the system resolver knows.
	 *
	 * @param string        $host     Host name.
	 * @param callable|null $dns      dns_get_record() or a stand-in.
	 * @param callable|null $fallback gethostbynamel() or a stand-in.
	 * @return string[]
	 */
	public static function resolve( $host, $dns = null, $fallback = null ) {
		$dns       = is_callable( $dns ) ? $dns : ( function_exists( 'dns_get_record' ) ? 'dns_get_record' : null );
		$fallback  = is_callable( $fallback ) ? $fallback : 'gethostbynamel';
		$addresses = array();
		if ( null !== $dns ) {
			$records = @call_user_func( $dns, $host, DNS_A | DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed lookup is handled as "no address", not as a PHP warning.
			foreach ( is_array( $records ) ? $records : array() as $record ) {
				if ( isset( $record['ip'] ) ) {
					$addresses[] = (string) $record['ip'];
				} elseif ( isset( $record['ipv6'] ) ) {
					$addresses[] = (string) $record['ipv6'];
				}
			}
		}
		if ( array() === $addresses ) {
			$found     = call_user_func( $fallback, $host );
			$addresses = is_array( $found ) ? array_map( 'strval', $found ) : array();
		}
		return array_values( array_unique( $addresses ) );
	}

	/**
	 * The checked addresses of a host, or why it may not be asked:
	 * 'local' for this machine or an address that is not public, 'unresolved'
	 * for a name without an address. A host that is an address is checked
	 * as it is.
	 *
	 * @param string        $host     Host of the address.
	 * @param callable|null $dns      See resolve().
	 * @param callable|null $fallback See resolve().
	 * @return string[]|string The addresses, or the problem.
	 */
	public static function public_addresses( $host, $dns = null, $fallback = null ) {
		$host = strtolower( trim( (string) $host, '[]' ) );
		if ( '' === $host || 'localhost' === $host || '.localhost' === substr( $host, -10 ) ) {
			return 'local';
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return AB_MCP_OAuth::is_public_ip( $host ) ? array( $host ) : 'local';
		}

		/**
		 * The addresses a host of an outgoing request of this plugin resolves
		 * to (the other site of a staging pair), before the plugin looks them
		 * up itself. Return a list to answer from elsewhere (a resolver of
		 * your own, a fixed mapping); null leaves the lookup to the plugin.
		 * Every address is checked either way: a filter cannot open the way
		 * to a private one. The request then connects to these addresses.
		 *
		 * @param string[]|null $addresses Null.
		 * @param string        $host      Host name.
		 */
		$addresses = apply_filters( 'ab_mcp_outbound_addresses', null, $host );
		if ( null === $addresses ) {
			$addresses = self::resolve( $host, $dns, $fallback );
		}
		if ( ! is_array( $addresses ) || array() === $addresses ) {
			return 'unresolved';
		}
		foreach ( $addresses as $address ) {
			if ( ! AB_MCP_OAuth::is_public_ip( (string) $address ) ) {
				return 'local';
			}
		}
		return array_values( array_map( 'strval', $addresses ) );
	}

	/**
	 * The CURLOPT_RESOLVE entry that ties the host of an address to checked
	 * addresses: "host:port:address,address", the port the address names or
	 * the one of its scheme. None for a host that is an address already,
	 * since nothing is looked up for it.
	 *
	 * @param string   $url       Address of the request.
	 * @param string[] $addresses Checked addresses.
	 * @return string|null
	 */
	public static function resolve_entry( $url, array $addresses ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $host || false !== filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) || array() === $addresses ) {
			return null;
		}
		$port = (int) wp_parse_url( $url, PHP_URL_PORT );
		if ( $port <= 0 ) {
			$port = 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ? 443 : 80;
		}
		return $host . ':' . $port . ':' . implode( ',', $addresses );
	}

	/**
	 * GET an http(s) address with the addresses of its host checked and the
	 * connection tied to them. The arguments go to wp_safe_remote_get(),
	 * with no redirect followed.
	 *
	 * @param string $url  Address.
	 * @param array  $args Request arguments.
	 * @return array|WP_Error The answer, or WP_Error with the code
	 *                        ab_mcp_outbound_local, ab_mcp_outbound_unresolved,
	 *                        ab_mcp_outbound_proxy, ab_mcp_outbound_unbound,
	 *                        or the transport's.
	 */
	public static function get( $url, array $args ) {
		$host    = (string) wp_parse_url( $url, PHP_URL_HOST );
		$checked = preg_match( '#^https?://#i', (string) $url ) ? self::public_addresses( $host ) : 'local';
		// Reasons for the caller, which says what to do (English: they
		// never reach an assistant as they are).
		if ( 'unresolved' === $checked ) {
			return new WP_Error( 'ab_mcp_outbound_unresolved', 'The address was not asked: its host name has no address.' );
		}
		if ( ! is_array( $checked ) ) {
			return new WP_Error( 'ab_mcp_outbound_local', 'The address was not asked: it is local or not public.' );
		}
		// The same question WP_Http::request() asks before it hands the
		// request to a proxy.
		$proxy = new WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			return new WP_Error( 'ab_mcp_outbound_proxy', 'The address was not asked: the request would go through the proxy, which looks the name up itself.' );
		}
		$entry = self::resolve_entry( $url, $checked );
		if ( null === $entry ) {
			return wp_safe_remote_get( $url, array_merge( $args, array( 'redirection' => 0 ) ) );
		}

		$bound = false;
		$pin   = static function ( $handle ) use ( $entry, &$bound ) {
			curl_setopt( $handle, CURLOPT_RESOLVE, array( $entry ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- the WordPress HTTP API offers no other way to fix the address a name connects to.
			$bound = true;
		};
		// The fsockopen transport cannot be tied to an address; its first
		// hook runs before it connects, and an exception of Requests there
		// ends the request as a WP_Error (WP_Http::request()).
		$refuse = static function () {
			throw new WpOrg\Requests\Exception( self::UNBOUND, 'ab_mcp_outbound_unbound' );
		};
		add_action( 'http_api_curl', $pin );
		add_action( 'requests-fsockopen.before_request', $refuse );
		try {
			$response = wp_safe_remote_get( $url, array_merge( $args, array( 'redirection' => 0 ) ) );
		} finally {
			remove_action( 'http_api_curl', $pin );
			remove_action( 'requests-fsockopen.before_request', $refuse );
		}
		if ( is_wp_error( $response ) && self::UNBOUND === $response->get_error_message() ) {
			return new WP_Error( 'ab_mcp_outbound_unbound', self::UNBOUND );
		}
		if ( ! is_wp_error( $response ) && ! $bound ) {
			return new WP_Error( 'ab_mcp_outbound_unbound', 'The answer was discarded: the request went out without cURL, so it was not tied to the checked addresses.' );
		}
		return $response;
	}
}
