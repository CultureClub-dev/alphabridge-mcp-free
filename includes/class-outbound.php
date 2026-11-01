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
 * target as it did the first address; the cookies go along in the cookie
 * jar of Requests, the HTTP library of WordPress, exactly as its transport
 * carries them when it follows. Nor is a request sent where
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
 * get_bound() is a second mode, for an address whose name may not point
 * anywhere yet: the request that proves this server serves a new site
 * address before the DNS changes (wp_change_site_address of the Pro
 * add-on), tied to addresses the caller hands over. It leaves out the
 * lookup of WordPress, so it takes only a DNS name as host and checks the
 * address itself; its contract is in its docblock.
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
	 * A host as get_bound() takes it: a DNS name. At least one dot, every
	 * label one character or more of a-z, 0-9 and "-", the last label
	 * starting with a letter; so no IPv4 address in any spelling (a number
	 * as the last label, or no dot at all), no name without a dot, no
	 * bracket and no colon.
	 * Matched without regard to case: get_bound() refuses capitals on their
	 * own, with a message that says so. \z, not $: "$" would also match
	 * before a final line break.
	 */
	const DNS_NAME = '/^(?:[a-z0-9-]+\.)+[a-z][a-z0-9-]*\z/i';

	/**
	 * A port as get_bound() takes it: 1 to 65535 in digits, no leading zero
	 * (checked against 65535 as a number besides).
	 */
	const PORT = '/^[1-9][0-9]{0,4}\z/';

	/**
	 * What get() says when the proxy, the missing cURL or an answer that
	 * came without the binding stops a request (transmit()).
	 */
	const SAY_GET = array(
		'proxy'     => 'The address was not asked: the request would go through the proxy, which looks the name up itself.',
		'unbound'   => self::UNBOUND,
		'discarded' => 'The answer was discarded: the request went out without cURL, so it was not tied to the checked addresses.',
	);

	/**
	 * The same for get_bound(), each with the step that helps: its caller
	 * hands the message on (English, as every message of this class).
	 */
	const SAY_BOUND = array(
		'proxy'     => 'The address was not asked: this site sends its requests through a proxy (WP_PROXY_HOST), which looks the name up itself, so the request could not be tied to the checked addresses. Add the host to WP_PROXY_BYPASS_HOSTS in wp-config.php, then try again.',
		'unbound'   => 'Not sent: without cURL the request cannot be tied to the checked addresses. Ask the host to enable the curl extension of PHP, then try again.',
		'discarded' => 'The answer was discarded: the request went out without cURL, so it was not tied to the checked addresses. Ask the host to enable the curl extension of PHP, or look for a plugin that answers outgoing requests itself (the filter pre_http_request); then try again.',
	);

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
	 * The Cookie header of a hop, from the jar of the request: what the
	 * transport of WordPress (Requests) sends when it follows a redirect
	 * itself, WpOrg\Requests\Cookie\Jar::before_request(): every cookie of
	 * the jar that is not expired (Expires, Max-Age) and whose domain
	 * matches the host of the address (lower case; a host-only cookie only
	 * for the very host), the value as it came (Cookie::format_for_header()),
	 * joined with "; ". Empty where nothing goes with the hop.
	 *
	 * @param WpOrg\Requests\Cookie\Jar $jar Jar of the request.
	 * @param string                    $url Address of the hop.
	 * @return string
	 */
	private static function cookie_header( WpOrg\Requests\Cookie\Jar $jar, $url ) {
		$headers = array();
		$data    = null;
		$type    = null;
		$options = array();
		$jar->before_request( $url, $headers, $data, $type, $options );
		return isset( $headers['Cookie'] ) ? (string) $headers['Cookie'] : '';
	}

	/**
	 * What an answer sets, into the jar: as the transport learns it when it
	 * follows a redirect itself (Jar::before_redirect_check()):
	 * WpOrg\Requests\Cookie::parse_from_headers() reads the Set-Cookie
	 * headers of the answer for its address, the domain and the path of the
	 * address where a header names none, host-only without a Domain, a
	 * Domain the address is not in refused, Expires and Max-Age read, the
	 * value as it came; a cookie of a name the jar holds replaces it.
	 * Nothing else of the answer is read: wp_remote_retrieve_cookies() would
	 * list the carried cookies too, decoded.
	 *
	 * @param WpOrg\Requests\Cookie\Jar $jar      Jar of the request.
	 * @param array|WP_Error            $response The answer.
	 * @param string                    $url      Address of the answer.
	 */
	private static function learn( WpOrg\Requests\Cookie\Jar $jar, $response, $url ) {
		$values  = wp_remote_retrieve_header( $response, 'set-cookie' );
		$values  = is_array( $values ) ? $values : ( '' !== (string) $values ? array( (string) $values ) : array() );
		$headers = new WpOrg\Requests\Response\Headers();
		foreach ( $values as $value ) {
			$headers['Set-Cookie'] = (string) $value;
		}
		foreach ( WpOrg\Requests\Cookie::parse_from_headers( $headers, new WpOrg\Requests\Iri( $url ) ) as $name => $cookie ) {
			$jar[ $name ] = $cookie;
		}
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
	 * returned as the answer, as WordPress returns it. The cookies go in the
	 * cookie jar of Requests, the transport of WordPress: filled from
	 * 'cookies' as WP_Http::request() fills it (WP_Http::normalize_cookies():
	 * WP_Http_Cookie objects or name => value, the value as given), sent
	 * with each hop as a Cookie header (cookie_header()) and fed from each
	 * answer (learn()) exactly as the transport does when it follows.
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
		$jar  = WP_Http::normalize_cookies( isset( $args['cookies'] ) && is_array( $args['cookies'] ) ? $args['cookies'] : array() );
		unset( $args['redirection'], $args['cookies'] );
		for ( $hop = 0; ; $hop++ ) {
			$hop_args = $args;
			$cookie   = self::cookie_header( $jar, (string) $url );
			if ( '' !== $cookie ) {
				// Over a Cookie header of the caller, as the transport sets it.
				$hop_args['headers'] = array_merge( isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array(), array( 'Cookie' => $cookie ) );
			}
			$response = self::send( (string) $url, $hop_args, 0 === $hop ? $addresses : null, $hop );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			self::learn( $jar, $response, (string) $url );
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
	 * GET one address in the bound mode: the host a DNS name, the connection
	 * tied to the addresses the caller hands over (pin_to) or, without them,
	 * to those the name resolves to, and the address sent exactly as given.
	 *
	 * Why a mode of its own. get() sends through wp_safe_remote_get(), and
	 * WordPress hands that address to wp_http_validate_url() first, which
	 * looks the host up with gethostbyname() and refuses a name without an
	 * A record (wp-includes/http.php) before CURLOPT_RESOLVE could tie it. A
	 * site that moves to a new name switches its address before the DNS
	 * changes, and the request that proves this server serves the new name
	 * must reach it while the name points nowhere yet. So this mode sends
	 * through wp_remote_get() with reject_unsafe_urls false, which leaves
	 * the lookup out, and checks everything itself.
	 *
	 * The contract, each condition with a refusal of its own (a WP_Error
	 * whose message says what happened and what to do; data array( 'url' =>
	 * the address )):
	 *
	 * 1. The scheme is http or https (in any case), followed by "//":
	 *    ab_mcp_outbound_scheme.
	 * 2. The authority is read from the address as it is written: the text
	 *    between "//" and the first "/", "?" or "#" (RFC 3986). It may hold a
	 *    host of a-z, 0-9, "-" and "." and a port of digits, nothing else, so
	 *    a parser that ends an authority at more characters (the WHATWG URL
	 *    standard also at "\") or reads a user part differently finds the
	 *    same host and port: none of them is in it. parse_url(), for one,
	 *    takes b.example for the host of "https://a.example\@b.example/",
	 *    behind a user part; here that authority has an "@" and is refused.
	 *    - No user part (an "@" in the authority): ab_mcp_outbound_userinfo.
	 *    - The host, up to the first ":", is a DNS name (DNS_NAME): at least
	 *      one dot, labels of a-z, 0-9 and "-", the last label starting with
	 *      a letter; no bracket, no colon. Refused with
	 *      ab_mcp_outbound_not_dns_name: IPv4 and IPv6 addresses
	 *      (127.0.0.1, [::1], ::1, an IPv4 address inside IPv6 such as
	 *      [::ffff:127.0.0.1]), every numeric spelling (0177.0.0.1,
	 *      2130706433, 0x7f.0.0.1), a name without a dot (localhost,
	 *      example), any other character (a name in another script goes in
	 *      its xn-- form, which is taken: xn--bcher-kva.example).
	 *      Nothing is normalized, because the two readers disagree: inet_pton()
	 *      reads 0177.0.0.1 as 177.0.0.1 and gives false for 2130706433 and
	 *      0x7f.0.0.1, where libcurl connects to 127.0.0.1 for all three
	 *      (measured with PHP 8.5 and libcurl 8.22, 01.11.2026); a check on a
	 *      normalized form could clear another address than the one cURL
	 *      reaches. A DNS name leaves nothing to read two ways, and it is
	 *      always bound: resolve_entry() binds nothing for an address as
	 *      host, this mode never has one.
	 *    - No capital letter in the host: ab_mcp_outbound_host_case. Requests,
	 *      the HTTP library of WordPress, writes the host in lower case before
	 *      cURL gets the address (WpOrg\Requests\Iri::set_host()), while the
	 *      CURLOPT_RESOLVE entry carries the host as given, and cURL versions
	 *      that compare the entry as spelled would then miss it and look the
	 *      name up themselves. Refusing capitals keeps the name checked, the
	 *      name bound and the name cURL connects to one string. DNS names do
	 *      not depend on case (RFC 4343): a caller that holds one in capitals
	 *      passes it in lower case.
	 *    - The port, where the address names one, is 1 to 65535 in digits
	 *      without a leading zero (PORT): ab_mcp_outbound_port. Without one,
	 *      the port of the scheme (80, 443). The CURLOPT_RESOLVE entry carries
	 *      this port. No list of allowed ports as wp_http_validate_url() keeps
	 *      one (80, 443, 8080): a site may answer on any port, and every
	 *      address the request can reach is a checked public one.
	 * 3. No fragment (a "#" after the authority): ab_mcp_outbound_fragment.
	 *    A query is fine.
	 * 4. The addresses the connection is tied to. With $pin_to: a list of at
	 *    least one IP address (ab_mcp_outbound_pin_empty), each a string and
	 *    public by AB_MCP_OAuth::is_public_ip(), IPv4 mapped into IPv6
	 *    included (ab_mcp_outbound_pin_local); the name is not looked up at
	 *    all. Without: every A and AAAA address of the name (public_addresses(),
	 *    the filter ab_mcp_outbound_addresses included), at least one
	 *    (ab_mcp_outbound_unresolved), each public (ab_mcp_outbound_local).
	 * 5. No proxy: where WordPress would send the request through its proxy
	 *    (WP_PROXY_HOST, a host not in WP_PROXY_BYPASS_HOSTS), it is not sent
	 *    (ab_mcp_outbound_proxy); a proxy from the environment of PHP is
	 *    switched off (curl_options()).
	 * 6. Nothing without cURL: refused before the fsockopen transport
	 *    connects, and an answer that came any other way without the binding
	 *    is discarded (ab_mcp_outbound_unbound).
	 * 7. 'redirection' is 0 and 'reject_unsafe_urls' false whatever $args
	 *    say: a 3xx is the answer, for the caller to read; nothing is
	 *    followed, since a target would be looked up unbound.
	 * 8. The address goes to wp_remote_get() exactly as given, with every
	 *    other argument of $args.
	 *
	 * @param string        $url    Address.
	 * @param array         $args   Request arguments of wp_remote_get().
	 * @param string[]|null $pin_to Public IP addresses to tie the connection
	 *                              to, checked here; null to tie it to the
	 *                              addresses the host name resolves to.
	 * @return array|WP_Error The answer, whatever its status, or WP_Error
	 *                        with one of the codes above or the transport's
	 *                        (http_request_failed: a timeout, a TLS error).
	 */
	public static function get_bound( $url, array $args = array(), $pin_to = null ) {
		$url  = (string) $url;
		$data = array( 'url' => $url );
		if ( ! preg_match( '~^(https?)://([^/?#]*)(.*)\z~is', $url, $parts ) ) {
			return new WP_Error( 'ab_mcp_outbound_scheme', 'The address was not asked: it does not start with http:// or https://. Give the address with its scheme, for example https://www.example.com/.', $data );
		}
		$scheme    = strtolower( $parts[1] );
		$authority = $parts[2];
		if ( false !== strpos( $authority, '@' ) ) {
			return new WP_Error( 'ab_mcp_outbound_userinfo', 'The address was not asked: it carries a user part (a name before "@" in front of the host). Give the address without it, for example https://www.example.com/.', $data );
		}
		$colon = strpos( $authority, ':' );
		$host  = false === $colon ? $authority : substr( $authority, 0, $colon );
		$port  = false === $colon ? null : substr( $authority, $colon + 1 );
		if ( ! preg_match( self::DNS_NAME, $host ) ) {
			return new WP_Error( 'ab_mcp_outbound_not_dns_name', 'The address was not asked: its host must be a DNS name such as www.example.com, not an IP address, a number or a name without a dot. Give the address with the domain name of the site (a name in another script in its xn-- form).', $data );
		}
		if ( strtolower( $host ) !== $host ) {
			return new WP_Error( 'ab_mcp_outbound_host_case', 'The address was not asked: its host name contains capital letters. Give the host name in lower case, for example https://www.example.com/ (a DNS name does not depend on case).', $data );
		}
		if ( null !== $port && ( ! preg_match( self::PORT, $port ) || (int) $port > 65535 ) ) {
			return new WP_Error( 'ab_mcp_outbound_port', 'The address was not asked: its port is not a number from 1 to 65535. Give the port as digits after the host, for example https://www.example.com:8443/, or leave it out.', $data );
		}
		$port = null !== $port ? (int) $port : ( 'https' === $scheme ? 443 : 80 );
		if ( false !== strpos( $parts[3], '#' ) ) {
			return new WP_Error( 'ab_mcp_outbound_fragment', 'The address was not asked: it carries a fragment (a part after "#"), which a request never sends. Give the address without it.', $data );
		}

		if ( null !== $pin_to ) {
			// The caller's list: every entry a public address, and the name
			// itself is not looked up.
			if ( ! is_array( $pin_to ) || array() === $pin_to ) {
				return new WP_Error( 'ab_mcp_outbound_pin_empty', 'The address was not asked: no address to tie the request to was given (pin_to is empty or not a list). Give the public IP addresses of the server, or leave pin_to out to use the addresses the host name points to.', $data );
			}
			// A string each, before checked() reads it as one.
			$checked = count( array_filter( $pin_to, 'is_string' ) ) === count( $pin_to ) ? self::checked( $pin_to ) : 'local';
			if ( ! is_array( $checked ) ) {
				return new WP_Error( 'ab_mcp_outbound_pin_local', 'The address was not asked: an address to tie the request to (pin_to) is not a public IP address. Give only public IPv4 or IPv6 addresses of the server.', $data );
			}
		} else {
			$checked = self::public_addresses( $host );
			if ( 'unresolved' === $checked ) {
				return new WP_Error( 'ab_mcp_outbound_unresolved', 'The address was not asked: its host name has no address. Check the spelling of the host, or give the IP addresses of the server to tie the request to (pin_to).', $data );
			}
			if ( ! is_array( $checked ) ) {
				return new WP_Error( 'ab_mcp_outbound_local', 'The address was not asked: its host name points to an address that is local or not public. Give a host name whose every address is public, or the public IP addresses of the server (pin_to).', $data );
			}
		}

		// Host and port as checked above, the address untouched.
		$entry = $host . ':' . $port . ':' . implode( ',', $checked );
		return self::transmit( 'wp_remote_get', $url, array_merge( $args, array( 'reject_unsafe_urls' => false ) ), $entry, $data, self::SAY_BOUND );
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
		// Null for an address as host: nothing is looked up, so there is
		// nothing to tie; the proxy of the environment is switched off all
		// the same (curl_options()).
		return self::transmit( 'wp_safe_remote_get', $url, $args, self::resolve_entry( $url, $checked ), $data, self::SAY_GET );
	}

	/**
	 * The request itself, for get() and get_bound(): not through the proxy of
	 * WordPress, tied by CURLOPT_RESOLVE to the entry on the request's own
	 * cURL handle (hooked for the call and taken off after it), refused
	 * before the fsockopen transport connects, and an answer that came
	 * without the binding discarded. 'redirection' is 0: WordPress follows
	 * nothing.
	 *
	 * @param callable    $send  wp_safe_remote_get (get()) or wp_remote_get (get_bound()).
	 * @param string      $url   Address.
	 * @param array       $args  Request arguments.
	 * @param string|null $entry CURLOPT_RESOLVE entry, or null for an address as host.
	 * @param array       $data  Data of the plugin's own errors.
	 * @param string[]    $say   The messages of the refusals: SAY_GET or SAY_BOUND.
	 * @return array|WP_Error
	 */
	private static function transmit( $send, $url, array $args, $entry, array $data, array $say ) {
		// The same question WP_Http::request() asks before it hands the
		// request to a proxy.
		$proxy = new WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			return new WP_Error( 'ab_mcp_outbound_proxy', $say['proxy'], $data );
		}
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
			$response = call_user_func( $send, $url, array_merge( $args, array( 'redirection' => 0 ) ) );
		} finally {
			remove_action( 'http_api_curl', $pin );
			remove_action( 'requests-fsockopen.before_request', $refuse );
		}
		// The exception carries UNBOUND whichever mode refused; what the
		// caller reads is the mode's own message.
		if ( is_wp_error( $response ) && self::UNBOUND === $response->get_error_message() ) {
			return new WP_Error( 'ab_mcp_outbound_unbound', $say['unbound'], $data );
		}
		if ( null !== $entry && ! is_wp_error( $response ) && ! $bound ) {
			return new WP_Error( 'ab_mcp_outbound_unbound', $say['discarded'], $data );
		}
		return $response;
	}
}
