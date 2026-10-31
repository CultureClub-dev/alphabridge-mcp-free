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
 * hooked for the call and taken off after it). Where WordPress would send
 * the request without cURL, it is refused before a connection is made (the
 * fsockopen transport's first hook), and an answer that came any other way
 * without the binding is discarded. A redirect is not left to WordPress,
 * whose transport would look the target up unbound: get() follows it
 * itself where the caller allows redirects, and checks and ties every
 * target as it did the first address; the cookies an answer sets go with
 * the next hop where they hold for it, as the transport sends them when
 * it follows. Nor is a request sent where
 * WordPress would send it through a proxy (WP_PROXY_HOST): the proxy looks
 * the name up itself, which no binding reaches; a host in
 * WP_PROXY_BYPASS_HOSTS goes direct and is tied as above, and a proxy from
 * the environment of PHP is switched off for the bound request
 * (curl_options()).
 *
 * Used for every address the site fetches on request: the other site of a
 * staging pair (AB_MCP_Staging::check_partner(), no redirect), a file for
 * the media library (AB_MCP_Tools_Media, from an address or from the chat,
 * three redirects), a ZIP to install (AB_MCP_Tools_Install_Update, three
 * redirects) and the client metadata document of an app that signs in
 * (AB_MCP_OAuth::cimd_fetch(), no redirect).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Outbound
 */
class AB_MCP_Outbound {

	/**
	 * The message of the refusal on the fsockopen transport, by which send()
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
		 * to (the other site of a staging pair, a file or ZIP to download,
		 * the metadata document of an app), before the plugin looks them up
		 * itself. Return a list to answer from elsewhere (a resolver of your
		 * own, a fixed mapping); null leaves the lookup to the plugin. Every
		 * address is checked either way: a filter cannot open the way to a
		 * private one. The request then connects to these addresses.
		 *
		 * @param string[]|null $addresses Null.
		 * @param string        $host      Host name.
		 */
		$addresses = apply_filters( 'ab_mcp_outbound_addresses', null, $host );
		if ( null === $addresses ) {
			$addresses = self::resolve( $host, $dns, $fallback );
		}
		return self::checked( $addresses );
	}

	/**
	 * A list of addresses, each checked: the list as strings, 'unresolved'
	 * for no list or an empty one, 'local' when any address is not public.
	 *
	 * @param mixed $addresses What a lookup, a filter or a caller handed over.
	 * @return string[]|string
	 */
	private static function checked( $addresses ) {
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
	 * The host is written as the address spells it, which is what older
	 * cURL versions compare the entry with; every version reads a bare IPv6
	 * address here.
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
	 * What a request of get() sets on its cURL handle: the checked addresses
	 * for a host name (CURLOPT_RESOLVE; none for an address as host, which
	 * is looked up nowhere), and in every case no proxy. A proxy from the
	 * environment of PHP (http_proxy, HTTPS_PROXY, all_proxy), which cURL
	 * uses without WordPress knowing, would look the name up itself; an
	 * empty CURLOPT_PROXY «explicitly disables the use of a proxy, even if
	 * there is an environment variable set for it» (libcurl,
	 * CURLOPT_PROXY(3)). WordPress's own proxy (WP_PROXY_HOST) never gets
	 * here: get() does not send what would go through it.
	 *
	 * @param string|null $entry CURLOPT_RESOLVE entry (resolve_entry()), or null.
	 * @return array<int,mixed>
	 */
	public static function curl_options( $entry ) {
		$options                  = null !== $entry ? array( CURLOPT_RESOLVE => array( (string) $entry ) ) : array();
		$options[ CURLOPT_PROXY ] = '';
		return $options;
	}

	/**
	 * The address a Location header names, as an absolute address: as given
	 * when it has a scheme (sent as it is, as WordPress's transport sends
	 * it), otherwise resolved against the address of the answer that carried
	 * it by RFC 3986, section 5.2: a network-path reference (//host/path)
	 * takes the scheme of the base, an absolute-path reference its scheme and
	 * host, a relative-path reference is merged with the base path (5.2.3), a
	 * query alone replaces the query of the base path; "." and ".." segments
	 * are removed (5.2.4), every other character of the path, a doubled "/"
	 * included, stays; a fragment is dropped. Null for an empty header, a
	 * fragment alone, or an answer address that is no absolute address.
	 *
	 * @param string $location The header.
	 * @param string $base     Address of the answer.
	 * @return string|null
	 */
	public static function absolute_url( $location, $base ) {
		$location = trim( (string) $location );
		if ( '' === $location || '#' === $location[0] ) {
			return null;
		}
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $location ) ) {
			return $location;
		}
		$parts = wp_parse_url( (string) $base );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return null;
		}
		list( $location ) = explode( '#', $location, 2 );
		$query            = '';
		if ( false !== strpos( $location, '?' ) ) {
			list( $location, $query ) = explode( '?', $location, 2 );
			$query                    = '?' . $query;
		}
		if ( 0 === strpos( $location, '//' ) ) {
			// Network-path reference: its own authority, the scheme of the base.
			$slash     = strpos( $location, '/', 2 );
			$authority = false === $slash ? substr( $location, 2 ) : substr( $location, 2, $slash - 2 );
			$path      = false === $slash ? '' : substr( $location, $slash );
			return $parts['scheme'] . '://' . $authority . self::remove_dot_segments( $path ) . $query;
		}
		$origin    = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$base_path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		if ( '' === $location ) {
			// A query alone: the path of the base, the new query.
			return $origin . $base_path . $query;
		}
		if ( '/' !== $location[0] ) {
			// Merge (5.2.3): after the last "/" of the base path; at the
			// root where the base has no path.
			$location = ( '' === $base_path ? '/' : substr( $base_path, 0, (int) strrpos( $base_path, '/' ) + 1 ) ) . $location;
		}
		return $origin . self::remove_dot_segments( $location ) . $query;
	}

	/**
	 * A path without its "." and ".." segments: the algorithm of RFC 3986,
	 * section 5.2.4, step by step, so that nothing else changes (a doubled
	 * "/" stays, ".." never goes above the root).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function remove_dot_segments( $path ) {
		$input  = (string) $path;
		$output = '';
		while ( '' !== $input ) {
			if ( 0 === strpos( $input, '../' ) ) {
				$input = substr( $input, 3 );
			} elseif ( 0 === strpos( $input, './' ) ) {
				$input = substr( $input, 2 );
			} elseif ( 0 === strpos( $input, '/./' ) ) {
				$input = substr( $input, 2 );
			} elseif ( '/.' === $input ) {
				$input = '/';
			} elseif ( 0 === strpos( $input, '/../' ) ) {
				$input  = substr( $input, 3 );
				$output = self::without_last_segment( $output );
			} elseif ( '/..' === $input ) {
				$input  = '/';
				$output = self::without_last_segment( $output );
			} elseif ( '.' === $input || '..' === $input ) {
				$input = '';
			} else {
				// The first segment, with its leading "/" where it has one,
				// up to but not including the next "/".
				$next = strpos( $input, '/', '/' === $input[0] ? 1 : 0 );
				if ( false === $next ) {
					$output .= $input;
					$input   = '';
				} else {
					$output .= substr( $input, 0, $next );
					$input   = substr( $input, $next );
				}
			}
		}
		return $output;
	}

	/**
	 * The output of remove_dot_segments() without its last segment and the
	 * "/" before it.
	 *
	 * @param string $output Path so far.
	 * @return string
	 */
	private static function without_last_segment( $output ) {
		$slash = strrpos( $output, '/' );
		return false === $slash ? '' : substr( $output, 0, $slash );
	}

	/**
	 * A jar of the cookies to carry along, WP_Http_Cookie objects by name:
	 * what a caller gives as WordPress takes it ('cookies': objects, arrays
	 * or name => value, the value kept as given), and the cookies of each
	 * answer (cookies_set()); a later one of the same name replaces an
	 * earlier, as the cookie jar of WordPress's transport keeps them.
	 *
	 * @param mixed                      $cookies Cookies to add.
	 * @param array<string,WP_Http_Cookie> $jar     Jar so far.
	 * @return array<string,WP_Http_Cookie>
	 */
	private static function cookie_jar( $cookies, array $jar = array() ) {
		foreach ( is_array( $cookies ) ? $cookies : array() as $name => $cookie ) {
			if ( ! $cookie instanceof WP_Http_Cookie ) {
				$cookie = new WP_Http_Cookie( is_array( $cookie ) ? $cookie : array( 'name' => (string) $name, 'value' => $cookie ) );
			}
			if ( null !== $cookie->name && '' !== (string) $cookie->name ) {
				$jar[ (string) $cookie->name ] = $cookie;
			}
		}
		return $jar;
	}

	/**
	 * The cookies an answer set, each with the value as its Set-Cookie
	 * header carries it. WordPress hands the cookies of an answer over
	 * URL-decoded (WP_HTTP_Requests_Response::get_cookies()), while its
	 * transport, following a redirect itself, sends the value on as it came;
	 * a value with "%2F", "+" or "=" would otherwise reach the next hop
	 * changed. The value is the part of the header before the first ";"
	 * and after the first "=", trimmed, as the transport reads it; the
	 * attributes (domain, path, expiry, host-only) stay those WordPress
	 * read. A cookie without a header to take the value from (a transport
	 * that hands none over) keeps WordPress's value.
	 *
	 * @param array|WP_Error $response The answer.
	 * @return WP_Http_Cookie[]
	 */
	private static function cookies_set( $response ) {
		$headers = wp_remote_retrieve_header( $response, 'set-cookie' );
		$headers = is_array( $headers ) ? $headers : ( '' !== (string) $headers ? array( (string) $headers ) : array() );
		$raw     = array();
		foreach ( $headers as $header ) {
			list( $pair ) = explode( ';', (string) $header, 2 );
			if ( false === strpos( $pair, '=' ) ) {
				continue;
			}
			list( $name, $value ) = explode( '=', $pair, 2 );
			$raw[ trim( $name ) ]  = trim( $value );
		}
		$cookies = array();
		foreach ( wp_remote_retrieve_cookies( $response ) as $cookie ) {
			if ( ! $cookie instanceof WP_Http_Cookie || null === $cookie->name ) {
				continue;
			}
			$cookie = clone $cookie;
			if ( isset( $raw[ (string) $cookie->name ] ) ) {
				$cookie->value = $raw[ (string) $cookie->name ];
			}
			$cookies[] = $cookie;
		}
		return $cookies;
	}

	/**
	 * The cookies of the jar that go with a request to an address, as
	 * WordPress's transport sends them when it follows a redirect itself
	 * (a value as it is, with no encoding or decoding, as
	 * WP_Http::normalize_cookies() hands it to the transport):
	 * those that hold for the address (WP_Http_Cookie::test(): domain,
	 * path, port, expiry), and one set without a Domain only to the very
	 * host that set it (host-only, as the transport's cookie jar keeps it).
	 *
	 * @param array<string,WP_Http_Cookie> $jar Jar.
	 * @param string                      $url Address of the request.
	 * @return WP_Http_Cookie[]
	 */
	private static function cookies_for( array $jar, $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$sent = array();
		foreach ( $jar as $cookie ) {
			if ( ! $cookie->test( $url ) ) {
				continue;
			}
			if ( ! empty( $cookie->host_only ) && strtolower( (string) $cookie->domain ) !== $host ) {
				continue;
			}
			$sent[] = $cookie;
		}
		return $sent;
	}

	/**
	 * GET an http(s) address with the addresses of its host checked and the
	 * connection tied to them. The arguments go to wp_safe_remote_get(),
	 * except 'redirection': how many redirects get() follows itself, none
	 * by default. Each target is read from the Location header, made
	 * absolute against the address that redirected (absolute_url()), must
	 * be an http(s) address, and is resolved, checked and tied like the
	 * first; one redirect more than allowed ends the request. A 3xx answer
	 * without a Location header, or any 3xx where no redirect is allowed, is
	 * returned as the answer, as WordPress returns it. The cookies of
	 * 'cookies' and those each answer sets go with every hop they hold for
	 * (cookies_for()), as WordPress's transport sends them when it follows.
	 *
	 * @param string        $url       Address.
	 * @param array         $args      Request arguments.
	 * @param string[]|null $addresses The addresses of the host of $url a
	 *                                 caller checked already (checked again
	 *                                 here); null to look them up.
	 * @return array|WP_Error The answer, or WP_Error with the code
	 *                        ab_mcp_outbound_local, ab_mcp_outbound_unresolved,
	 *                        ab_mcp_outbound_proxy, ab_mcp_outbound_unbound,
	 *                        ab_mcp_outbound_redirects, ab_mcp_outbound_redirect
	 *                        or the transport's. The data of the plugin's own
	 *                        errors names the address it was about ('url') and
	 *                        which hop it was ('hop': 0 for the address asked
	 *                        for, n for the n-th redirect target).
	 */
	public static function get( $url, array $args, $addresses = null ) {
		$hops = isset( $args['redirection'] ) ? max( 0, (int) $args['redirection'] ) : 0;
		$jar  = self::cookie_jar( isset( $args['cookies'] ) ? $args['cookies'] : array() );
		unset( $args['redirection'], $args['cookies'] );
		for ( $hop = 0; ; $hop++ ) {
			$hop_args = $args;
			$cookies  = self::cookies_for( $jar, (string) $url );
			if ( array() !== $cookies ) {
				$hop_args['cookies'] = $cookies;
			}
			$response = self::send( (string) $url, $hop_args, 0 === $hop ? $addresses : null, $hop );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$jar      = self::cookie_jar( self::cookies_set( $response ), $jar );
			$status   = (int) wp_remote_retrieve_response_code( $response );
			$location = wp_remote_retrieve_header( $response, 'location' );
			$location = is_array( $location ) ? (string) reset( $location ) : (string) $location;
			if ( 0 === $hops || $status < 300 || $status >= 400 || '' === trim( $location ) ) {
				return $response;
			}
			$target = self::absolute_url( $location, $url );
			// Reasons for the caller, which says what to do (English: they
			// never reach an assistant as they are).
			if ( $hop >= $hops ) {
				return new WP_Error( 'ab_mcp_outbound_redirects', 'The address was not followed further: it redirected more often than allowed.', array( 'url' => null !== $target ? $target : $location, 'hop' => $hop + 1 ) );
			}
			if ( null === $target || ! preg_match( '#^https?://#i', $target ) ) {
				return new WP_Error( 'ab_mcp_outbound_redirect', 'The address was not followed: it redirects to something that is not an http(s) address.', array( 'url' => $location, 'hop' => $hop + 1 ) );
			}
			$url = $target;
		}
	}

	/**
	 * One request, without a redirect: the host checked, the connection
	 * tied, nothing sent through a proxy or without cURL.
	 *
	 * @param string        $url       Address.
	 * @param array         $args      Request arguments, without 'redirection'.
	 * @param string[]|null $addresses Addresses a caller checked, or null.
	 * @param int           $hop       Which hop this is, for the error data.
	 * @return array|WP_Error
	 */
	private static function send( $url, array $args, $addresses, $hop ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$checked = 'local';
		} elseif ( null !== $addresses ) {
			// A caller's list is checked again: it cannot open the way to a
			// private address either.
			$checked = self::checked( $addresses );
		} else {
			$checked = self::public_addresses( $host );
		}
		$data = array(
			'url' => $url,
			'hop' => (int) $hop,
		);
		if ( 'unresolved' === $checked ) {
			return new WP_Error( 'ab_mcp_outbound_unresolved', 'The address was not asked: its host name has no address.', $data );
		}
		if ( ! is_array( $checked ) ) {
			return new WP_Error( 'ab_mcp_outbound_local', 'The address was not asked: it is local or not public.', $data );
		}
		// The same question WP_Http::request() asks before it hands the
		// request to a proxy.
		$proxy = new WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			return new WP_Error( 'ab_mcp_outbound_proxy', 'The address was not asked: the request would go through the proxy, which looks the name up itself.', $data );
		}
		// Null for an address as host: nothing is looked up, so there is
		// nothing to tie; the proxy of the environment is switched off all
		// the same (curl_options()).
		$entry = self::resolve_entry( $url, $checked );
		$bound = false;
		$pin   = static function ( $handle ) use ( $entry, &$bound ) {
			curl_setopt_array( $handle, self::curl_options( $entry ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- the WordPress HTTP API offers no other way to fix the address a name connects to.
			$bound = true;
		};
		// The fsockopen transport cannot be tied to an address; its first
		// hook runs before it connects, and an exception of Requests there
		// ends the request as a WP_Error (WP_Http::request()). Only for a
		// host name: an address as host needs no tie, and fsockopen reads no
		// proxy from the environment.
		$refuse = static function () {
			throw new WpOrg\Requests\Exception( self::UNBOUND, 'ab_mcp_outbound_unbound' );
		};
		add_action( 'http_api_curl', $pin );
		if ( null !== $entry ) {
			add_action( 'requests-fsockopen.before_request', $refuse );
		}
		try {
			$response = wp_safe_remote_get( $url, array_merge( $args, array( 'redirection' => 0 ) ) );
		} finally {
			remove_action( 'http_api_curl', $pin );
			remove_action( 'requests-fsockopen.before_request', $refuse );
		}
		if ( is_wp_error( $response ) && self::UNBOUND === $response->get_error_message() ) {
			return new WP_Error( 'ab_mcp_outbound_unbound', self::UNBOUND, $data );
		}
		if ( null !== $entry && ! is_wp_error( $response ) && ! $bound ) {
			return new WP_Error( 'ab_mcp_outbound_unbound', 'The answer was discarded: the request went out without cURL, so it was not tied to the checked addresses.', $data );
		}
		return $response;
	}
}
