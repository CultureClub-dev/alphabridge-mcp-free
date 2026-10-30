<?php
/**
 * The connection check: what stops an app from connecting to this site, and
 * how to fix it.
 *
 * Most requests for help with an MCP plugin for WordPress are about
 * connecting, and the causes repeat: the site is not on HTTPS, the REST API
 * is switched off or rewritten, the server answers /.well-known/ itself, it
 * drops the Authorization header on the way to PHP, or a firewall in front
 * of the site (ModSecurity, Cloudflare, LiteSpeed, Wordfence) answers in its
 * place. The site owner sees only that Claude or ChatGPT “cannot connect”.
 * This check looks at each cause and says, for every one it finds, what is
 * the case in one sentence and the steps that fix it.
 *
 * Every request goes from the site to itself (a loopback), sent the way the
 * firewall self-test sends its own (AB_MCP_OAuth::run_firewall_check()): with
 * a browser's user agent, without following redirects or sending cookies,
 * with the certificate check WordPress uses for requests to itself, and with
 * the HTTP Basic login of the current request where a staging site is behind
 * one. A block that applies only to requests from outside cannot be seen
 * that way — Cloudflare's bot rules for AI crawlers are the typical case —
 * and the result says so (limits()).
 *
 * The judgement of each answer is a pure function of what came back (status,
 * headers, the start of the body) and of the site's settings, so every case
 * can be tested without a network: the interpret_*() methods. run() only
 * sends the requests and hands the answers over.
 *
 * Nothing on the site is changed. The one thing kept is the result itself,
 * by the button on the settings page (run_and_store()), and the firewall
 * self-test keeps its own finding as it always does.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Connection_Doctor
 */
class AB_MCP_Connection_Doctor {

	/**
	 * Where the settings page keeps the last result (autoload off: only the
	 * settings page reads it).
	 */
	const OPTION = 'ab_mcp_connection_check';

	/**
	 * Seconds each loopback may take, as the firewall self-test.
	 */
	const TIMEOUT = 5;

	/**
	 * How much of an answer's body is kept for judging it.
	 */
	const BODY_EXCERPT = 2000;

	/**
	 * The statuses a check can end with, in the order the summary counts them.
	 */
	const STATUSES = array( 'fail', 'warn', 'ok', 'info', 'skip' );

	/**
	 * Plugins known to restrict the REST API for requests without a login,
	 * by their folder name. Only folders whose name is certain are listed;
	 * the setting is described by its kind, since the wording in each plugin
	 * changes between versions. Name => whether the plugin has a setting for
	 * it (true) or closes the REST API as a whole (false).
	 */
	const REST_PLUGINS = array(
		'disable-json-api'                   => array( 'Disable REST API', true ),
		'disable-wp-rest-api'                => array( 'Disable WP REST API', false ),
		'better-wp-security'                 => array( 'Solid Security', true ),
		'ithemes-security-pro'               => array( 'Solid Security Pro', true ),
		'all-in-one-wp-security-and-firewall' => array( 'All-In-One Security (AIOS)', true ),
	);

	/* ---------------------------------------------------------------- run */

	/**
	 * Run every check now.
	 *
	 * @return array{checks:array,summary:array,checked_at:string,checked:int,limits:string}
	 */
	public static function run() {
		$oauth    = class_exists( 'AB_MCP_OAuth' ) && AB_MCP_OAuth::enabled();
		$endpoint = self::endpoint_url();
		$plain    = '' === (string) get_option( 'permalink_structure', '' );
		$basic    = isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
		$answers  = array();

		$checks   = array();
		$checks[] = self::interpret_https( home_url( '/' ), $endpoint );
		$checks[] = self::interpret_permalinks( $plain );

		// 3. The endpoint, without a token: it must answer 401 itself.
		$answers['endpoint'] = self::send( 'POST', $endpoint, self::mcp_headers( null ) );
		$checks[]            = self::interpret_endpoint( $answers['endpoint'], $oauth );
		$reached             = self::is_own_answer( $answers['endpoint'] );

		// 4. The same with a made-up token: does the header arrive? Behind an
		// HTTP login the header is taken by the login, so it is not sent.
		if ( $reached && ! $basic ) {
			$answers['authorization'] = self::send( 'POST', $endpoint, self::mcp_headers( 'Bearer abmcp-check-' . bin2hex( random_bytes( 12 ) ) ) );
			$checks[]                 = self::interpret_authorization( $answers['authorization'], true, false );
		} else {
			$checks[] = self::interpret_authorization( null, $reached, $basic );
		}

		// 5. The sign-in description below /.well-known/.
		if ( $oauth ) {
			$answers['well_known'] = self::send( 'GET', AB_MCP_OAuth::resource_metadata_url(), array( 'Accept' => 'application/json' ) );
			$checks[]              = self::interpret_well_known( $answers['well_known'], true, $plain );
		} else {
			$checks[] = self::interpret_well_known( null, false, $plain );
		}

		// 6. The firewall self-test, as the settings page runs it; only where
		// the direct sign-in it tests is offered at all.
		$direct   = $oauth && AB_MCP_OAuth::cimd_enabled();
		$checks[] = self::interpret_firewall( $direct ? AB_MCP_OAuth::run_firewall_check() : null, $oauth, $direct );

		// 7. Cloudflare, by its header in any of the answers above.
		$checks[] = self::interpret_cloudflare( array_column( $answers, 'headers' ) );

		// 8. Plugins known to restrict the REST API.
		$checks[] = self::interpret_rest_plugins( self::active_plugins() );

		$now = time();
		return array(
			'checks'     => $checks,
			'summary'    => self::summary( $checks ),
			'checked_at' => gmdate( 'Y-m-d\TH:i:s\Z', $now ),
			'checked'    => $now,
			'limits'     => self::limits(),
		);
	}

	/**
	 * Run every check and keep the result for the settings page.
	 *
	 * @return array The result, as run() returns it.
	 */
	public static function run_and_store() {
		$result = self::run();
		update_option( self::OPTION, $result, false );
		return $result;
	}

	/**
	 * The kept result, or null before the first run.
	 *
	 * @return array|null
	 */
	public static function stored() {
		$result = get_option( self::OPTION );
		if ( ! is_array( $result ) || ! isset( $result['checks'], $result['checked'] ) || ! is_array( $result['checks'] ) ) {
			return null;
		}
		return $result;
	}

	/**
	 * The tool wp_connection_doctor: run the checks and return the result.
	 * Nothing is kept; the settings page keeps only what its own button ran.
	 *
	 * @return array
	 */
	public static function tool() {
		$result = self::run();
		unset( $result['checked'] );
		return $result;
	}

	/**
	 * What a request from the site to itself cannot see. Part of every result.
	 *
	 * @return string
	 */
	public static function limits() {
		return __( 'Every check sends its requests from this site to itself. A block that applies only to requests from outside — Cloudflare’s bot rules for AI crawlers are the typical case, or a firewall that lets the server’s own address through — does not show up here. If Claude or ChatGPT still cannot connect while these checks pass, look at such rules first.', 'alphabridge-mcp' );
	}

	/**
	 * Counts per status and one line that sums them up.
	 *
	 * @param array $checks Results of the checks.
	 * @return array
	 */
	public static function summary( array $checks ) {
		$counts = array_fill_keys( self::STATUSES, 0 );
		foreach ( $checks as $check ) {
			if ( isset( $check['status'], $counts[ $check['status'] ] ) ) {
				++$counts[ $check['status'] ];
			}
		}
		if ( $counts['fail'] > 0 ) {
			/* translators: %d: number of checks that found a problem. */
			$text = sprintf( _n( '%d check found a problem that stops apps from connecting.', '%d checks found problems that stop apps from connecting.', $counts['fail'], 'alphabridge-mcp' ), $counts['fail'] );
		} elseif ( $counts['warn'] > 0 ) {
			/* translators: %d: number of checks with a warning. */
			$text = sprintf( _n( 'Nothing stops apps from connecting, but %d check has a warning.', 'Nothing stops apps from connecting, but %d checks have a warning.', $counts['warn'], 'alphabridge-mcp' ), $counts['warn'] );
		} else {
			$text = __( 'Nothing that these checks can see stops apps from connecting.', 'alphabridge-mcp' );
		}
		$counts['text'] = $text;
		return $counts;
	}

	/* ------------------------------------------------------ the requests */

	/**
	 * The MCP endpoint, as the settings page shows it.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		return untrailingslashit( rest_url( AB_MCP_REST_NAMESPACE . AB_MCP_REST_ROUTE ) );
	}

	/**
	 * Where this plugin's REST routes begin: the endpoint and the OAuth
	 * routes (token, registration) both lie below it.
	 *
	 * @return string
	 */
	private static function rest_base() {
		return untrailingslashit( rest_url( AB_MCP_REST_NAMESPACE ) ) . '/';
	}

	/**
	 * Where the sign-in descriptions lie.
	 *
	 * @return string
	 */
	private static function well_known_base() {
		return untrailingslashit( home_url() ) . '/.well-known/';
	}

	/**
	 * Headers of a JSON-RPC POST to the endpoint, with the given
	 * Authorization header or, without one, the Basic login of the current
	 * request where there is one.
	 *
	 * @param string|null $authorization Value of the Authorization header.
	 * @return array
	 */
	private static function mcp_headers( $authorization ) {
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json, text/event-stream',
		);
		if ( null !== $authorization ) {
			$headers['Authorization'] = $authorization;
		}
		return $headers;
	}

	/**
	 * The JSON-RPC initialize a client sends first, without a token.
	 *
	 * @return string
	 */
	private static function initialize_body() {
		return (string) wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => defined( 'AB_MCP_PROTOCOL_VERSION' ) ? AB_MCP_PROTOCOL_VERSION : '2025-06-18',
					'capabilities'    => (object) array(),
					'clientInfo'      => array(
						'name'    => 'AlphaBridge connection check',
						'version' => defined( 'AB_MCP_VERSION' ) ? AB_MCP_VERSION : '',
					),
				),
			)
		);
	}

	/**
	 * Send one request to the site itself, the way the firewall self-test
	 * does (AB_MCP_OAuth::firewall_probe_args()), and keep what came back.
	 *
	 * @param string $method  GET or POST.
	 * @param string $url     Address on this site.
	 * @param array  $headers Request headers.
	 * @return array{status:int|null,headers:array,body:string,error:string}
	 */
	private static function send( $method, $url, array $headers ) {
		if ( ! isset( $headers['Authorization'] ) && isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) . ':' . wp_unslash( $_SERVER['PHP_AUTH_PW'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- passed on to this site itself, as WordPress's own loopback test does.
		}
		$headers['Cache-Control'] = 'no-cache';
		$args                     = array(
			'timeout'             => self::TIMEOUT,
			'redirection'         => 0,
			/** This filter is documented in wp-includes/class-wp-http-streams.php */
			'sslverify'           => apply_filters( 'https_local_ssl_verify', false ),
			'user-agent'          => AB_MCP_OAuth::FIREWALL_USER_AGENT,
			'headers'             => $headers,
			'cookies'             => array(),
			'limit_response_size' => 65536,
		);
		if ( 'POST' === $method ) {
			$args['body'] = self::initialize_body();
			$response     = wp_remote_post( $url, $args );
		} else {
			$response = wp_remote_get( $url, $args );
		}
		if ( is_wp_error( $response ) ) {
			return array(
				'status'  => null,
				'headers' => array(),
				'body'    => '',
				'error'   => (string) $response->get_error_message(),
			);
		}
		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => self::header_list( wp_remote_retrieve_headers( $response ) ),
			'body'    => substr( (string) wp_remote_retrieve_body( $response ), 0, self::BODY_EXCERPT ),
			'error'   => '',
		);
	}

	/**
	 * The headers of an answer as name (lower case) => value; a header sent
	 * more than once is joined with commas. WordPress hands them over as a
	 * case-insensitive dictionary, which iterates like an array.
	 *
	 * @param mixed $raw Headers as wp_remote_retrieve_headers() returns them.
	 * @return array<string,string>
	 */
	public static function header_list( $raw ) {
		$out = array();
		if ( ! is_array( $raw ) && ! $raw instanceof Traversable ) {
			return $out;
		}
		foreach ( $raw as $name => $value ) {
			$out[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
		}
		return $out;
	}

	/**
	 * Active plugins of this site and of the network, as plugin basenames.
	 *
	 * @return string[]
	 */
	private static function active_plugins() {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return array_values( array_filter( array_map( 'strval', $active ) ) );
	}

	/* ------------------------------------------------- reading an answer */

	/**
	 * Did the answer come from this plugin? Its 401 carries AUTH_HEADER,
	 * and every refusal of its own has a code that starts with ab_mcp_.
	 *
	 * @param array|null $answer Answer as send() returns it.
	 * @return bool
	 */
	public static function is_own_answer( $answer ) {
		if ( ! is_array( $answer ) || null === $answer['status'] ) {
			return false;
		}
		if ( isset( $answer['headers']['x-ab-mcp-auth'] ) ) {
			return true;
		}
		$code = self::wp_error_code( $answer['body'] );
		return null !== $code && 0 === strpos( $code, 'ab_mcp_' );
	}

	/**
	 * The code of a WordPress REST error in a body, or null when the body is
	 * none: such a body means WordPress itself answered.
	 *
	 * @param string $body Start of the body.
	 * @return string|null
	 */
	private static function wp_error_code( $body ) {
		$data = json_decode( (string) $body, true );
		return is_array( $data ) && isset( $data['code'] ) && is_string( $data['code'] ) ? $data['code'] : null;
	}

	/**
	 * A check's result.
	 *
	 * @param string   $id      Check id.
	 * @param string   $status  ok, warn, fail, info or skip.
	 * @param string   $title   Name of the check.
	 * @param string   $finding One sentence on what is the case.
	 * @param string[] $steps   What to do, in order; empty when ok.
	 * @return array
	 */
	private static function result( $id, $status, $title, $finding, array $steps = array() ) {
		return array(
			'id'      => $id,
			'status'  => $status,
			'title'   => $title,
			'finding' => $finding,
			'steps'   => 'ok' === $status ? array() : array_values( $steps ),
		);
	}

	/**
	 * The finding for a loopback without an answer, and the way to find out
	 * why. Never “blocked”: no answer says nothing about a block.
	 *
	 * @param string $id     Check id.
	 * @param string $title  Name of the check.
	 * @param string $url    Address that was asked.
	 * @param array  $answer Answer as send() returns it.
	 * @return array
	 */
	private static function no_answer( $id, $title, $url, array $answer ) {
		return self::result(
			$id,
			'skip',
			$title,
			/* translators: 1: address, 2: the error the HTTP request reported. */
			sprintf( __( 'The site could not reach itself at %1$s (%2$s), so this check has no answer; that alone says nothing about whether apps can reach it.', 'alphabridge-mcp' ), $url, '' !== $answer['error'] ? $answer['error'] : __( 'no answer', 'alphabridge-mcp' ) ),
			array(
				__( 'Check under Tools → Site Health whether WordPress reports a failed loopback request; if it does, ask your host to allow the server to send requests to its own address.', 'alphabridge-mcp' ),
			)
		);
	}

	/**
	 * The step that connects with a token while the sign-in cannot work.
	 *
	 * @return string
	 */
	private static function token_way() {
		return __( 'Until it is fixed, connect with a token instead: under Settings → AlphaBridge MCP choose “Other”, create a connection and enter its token in the app (for an app without a field for it, tick “Also offer an address that contains the token.” there and use that address).', 'alphabridge-mcp' );
	}

	/**
	 * The step for a site address that does not match the server's own.
	 *
	 * @return string
	 */
	private static function address_step() {
		return __( 'Under Settings → General make “WordPress Address (URL)” and “Site Address (URL)” the exact address the server answers at — https://, with or without www — and save.', 'alphabridge-mcp' );
	}

	/* --------------------------------------------------------- the checks */

	/**
	 * 1. HTTPS: Claude and ChatGPT connect only to https:// addresses.
	 *
	 * @param string $home     The site address.
	 * @param string $endpoint The MCP endpoint.
	 * @return array
	 */
	public static function interpret_https( $home, $endpoint ) {
		$title = __( 'HTTPS', 'alphabridge-mcp' );
		foreach ( array( $home, $endpoint ) as $url ) {
			if ( 'https' !== strtolower( (string) wp_parse_url( (string) $url, PHP_URL_SCHEME ) ) ) {
				return self::result(
					'https',
					'fail',
					$title,
					/* translators: %s: the address without HTTPS. */
					sprintf( __( 'The address %s does not use https://, and Claude and ChatGPT connect only to https:// addresses.', 'alphabridge-mcp' ), $url ),
					array(
						__( 'Get a TLS certificate for this domain from your host; many hosting panels offer a free one (Let’s Encrypt).', 'alphabridge-mcp' ),
						__( 'Once the site opens with https:// in the browser, change “WordPress Address (URL)” and “Site Address (URL)” under Settings → General to the https:// address and save.', 'alphabridge-mcp' ),
					)
				);
			}
		}
		return self::result( 'https', 'ok', $title, __( 'The site and its MCP endpoint use https://.', 'alphabridge-mcp' ) );
	}

	/**
	 * 2. Permalinks: with “Plain” many servers never hand addresses below
	 * /.well-known/ to WordPress.
	 *
	 * @param bool $plain Whether the permalinks are set to “Plain”.
	 * @return array
	 */
	public static function interpret_permalinks( $plain ) {
		$title = __( 'Permalinks', 'alphabridge-mcp' );
		if ( ! $plain ) {
			return self::result( 'permalinks', 'ok', $title, __( 'The permalinks are not set to “Plain”, so the server passes addresses that are no files to WordPress.', 'alphabridge-mcp' ) );
		}
		return self::result(
			'permalinks',
			'warn',
			$title,
			__( 'The permalinks are set to “Plain”, so on many servers the addresses below /.well-known/ that the sign-in needs do not reach WordPress.', 'alphabridge-mcp' ),
			array(
				__( 'Under Settings → Permalinks choose “Post name” and click “Save Changes”.', 'alphabridge-mcp' ),
				__( 'Run this check again: the check of the sign-in addresses shows whether they reach WordPress now.', 'alphabridge-mcp' ),
			)
		);
	}

	/**
	 * 3. The MCP endpoint without a token. Expected: this plugin's own 401,
	 * with the pointer to the sign-in while the sign-in is on. Anything else
	 * is named by who answered: WordPress (a plugin that restricts the REST
	 * API), a firewall in front of the site (Wordfence, Cloudflare,
	 * LiteSpeed, ModSecurity), an HTTP login, a redirect, a server error.
	 *
	 * @param array|null $answer Answer as send() returns it; null status without one.
	 * @param bool       $oauth  Whether the sign-in for apps is on.
	 * @return array
	 */
	public static function interpret_endpoint( $answer, $oauth ) {
		$id       = 'mcp_endpoint';
		$title    = __( 'MCP endpoint', 'alphabridge-mcp' );
		$url      = self::endpoint_url();
		$answer   = self::normalize( $answer );
		$status   = $answer['status'];
		$headers  = $answer['headers'];
		$body     = $answer['body'];
		$wp_code  = self::wp_error_code( $body );
		$base     = self::rest_base();
		$wk       = self::well_known_base();
		$rest_way = __( 'Find the setting that restricts the REST API to logged-in users in your security or performance plugin (the check “Plugins that restrict the REST API” names the ones it knows), and allow the routes that begin with /alphabridge/v1/ or switch the restriction off.', 'alphabridge-mcp' );

		if ( null === $status ) {
			return self::no_answer( $id, $title, $url, $answer );
		}

		if ( 401 === $status && self::is_own_answer( $answer ) ) {
			$challenge = isset( $headers['www-authenticate'] ) ? $headers['www-authenticate'] : '';
			if ( $oauth && false === stripos( $challenge, 'resource_metadata=' ) ) {
				return self::result(
					$id,
					'warn',
					$title,
					__( 'The MCP endpoint answers 401 as it should, but without the WWW-Authenticate header that points Claude and ChatGPT to the sign-in, so something between WordPress and the app removes it.', 'alphabridge-mcp' ),
					array(
						/* translators: %s: the MCP endpoint. */
						sprintf( __( 'Ask your host whether a proxy, cache or security rule removes the WWW-Authenticate header from the answers of %s, and to let it through.', 'alphabridge-mcp' ), $url ),
						self::token_way(),
					)
				);
			}
			return self::result(
				$id,
				'ok',
				$title,
				$oauth
					? __( 'The MCP endpoint answers a request without a token with 401 and the pointer to the sign-in, as it should.', 'alphabridge-mcp' )
					: __( 'The MCP endpoint answers a request without a token with 401, as it should.', 'alphabridge-mcp' )
			);
		}

		$own = self::is_own_answer( $answer );
		if ( ! $own && $status >= 400 ) {
			if ( self::mentions( $headers, $body, 'wordfence' ) ) {
				return self::result(
					$id,
					'fail',
					$title,
					/* translators: %d: HTTP status. */
					sprintf( __( 'Wordfence’s firewall answered in place of the MCP endpoint (status %d).', 'alphabridge-mcp' ), $status ),
					array(
						/* translators: %s: the MCP endpoint. */
						sprintf( __( 'In wp-admin open Wordfence → Firewall, find this blocked request to %s among the blocked requests and allowlist it, or add the address to the firewall’s allowlisted URLs.', 'alphabridge-mcp' ), $url ),
						__( 'Run this check again; it passes once Wordfence lets the request through.', 'alphabridge-mcp' ),
					)
				);
			}
			$server = isset( $headers['server'] ) ? strtolower( $headers['server'] ) : '';
			if ( isset( $headers['cf-mitigated'] ) || ( 403 === $status && false !== strpos( $server, 'cloudflare' ) && null === $wp_code ) ) {
				return self::result(
					$id,
					'fail',
					$title,
					/* translators: %d: HTTP status. */
					sprintf( __( 'Cloudflare answered in place of the MCP endpoint (status %d): a Cloudflare security rule or bot setting blocks the request.', 'alphabridge-mcp' ), $status ),
					array(
						/* translators: %s: the MCP endpoint. */
						sprintf( __( 'In the Cloudflare dashboard of this domain, open the security events and look for the blocked request to %s; the event names the rule or setting that blocked it.', 'alphabridge-mcp' ), $url ),
						/* translators: 1: where this plugin's REST routes begin, 2: where the sign-in descriptions begin. */
						sprintf( __( 'Change that rule or setting, or add a rule that skips Cloudflare’s security features for addresses that begin with %1$s or %2$s.', 'alphabridge-mcp' ), $base, $wk ),
					)
				);
			}
			if ( null === $wp_code && self::has_header_prefix( $headers, 'x-litespeed-' ) && in_array( $status, array( 403, 406, 429 ), true ) ) {
				return self::result(
					$id,
					'fail',
					$title,
					/* translators: %d: HTTP status. */
					sprintf( __( 'The LiteSpeed web server or its CDN QUIC.cloud answered in place of the MCP endpoint (status %d).', 'alphabridge-mcp' ), $status ),
					array(
						/* translators: %s: the MCP endpoint. */
						sprintf( __( 'Ask your host to let POST requests with a JSON body to %s through the server’s firewall rules (ModSecurity on LiteSpeed).', 'alphabridge-mcp' ), $url ),
						__( 'If the site uses QUIC.cloud’s CDN, check the security settings of this domain in your QUIC.cloud dashboard and allow that address there too.', 'alphabridge-mcp' ),
					)
				);
			}
		}

		if ( 404 === $status ) {
			return self::result(
				$id,
				'fail',
				$title,
				/* translators: %s: the MCP endpoint. */
				sprintf( __( 'The MCP endpoint %s answers 404, so the WordPress REST API does not answer at this address.', 'alphabridge-mcp' ), $url ),
				array(
					__( 'Under Settings → Permalinks click “Save Changes” once; WordPress then writes its address rules anew.', 'alphabridge-mcp' ),
					$rest_way,
					__( 'If it still answers 404, ask your host to pass the addresses below /wp-json/ to WordPress’s index.php.', 'alphabridge-mcp' ),
				)
			);
		}

		if ( 406 === $status && ! $own ) {
			return self::result(
				$id,
				'fail',
				$title,
				__( 'Something in front of the site answered 406 Not Acceptable in its place, the typical answer of a ModSecurity rule at the host.', 'alphabridge-mcp' ),
				array(
					/* translators: %s: the MCP endpoint. */
					sprintf( __( 'Ask your host to let POST requests with a JSON body to %s through, or to switch off the ModSecurity rule that blocks them for this address; the host’s firewall log names the rule.', 'alphabridge-mcp' ), $url ),
				)
			);
		}

		if ( 401 === $status && ! $own ) {
			$challenge = isset( $headers['www-authenticate'] ) ? $headers['www-authenticate'] : '';
			if ( 0 === stripos( ltrim( $challenge ), 'basic' ) ) {
				return self::result(
					$id,
					'fail',
					$title,
					__( 'The server asks for a user name and password (an HTTP login) before WordPress answers, and AI apps cannot get past it.', 'alphabridge-mcp' ),
					array(
						/* translators: 1: where this plugin's REST routes begin, 2: where the sign-in descriptions begin. */
						sprintf( __( 'In your hosting panel, switch off the password protection of this site, or exempt the addresses that begin with %1$s and %2$s from it.', 'alphabridge-mcp' ), $base, $wk ),
					)
				);
			}
		}

		if ( ( 401 === $status || 403 === $status ) && ! $own && null !== $wp_code ) {
			return self::result(
				$id,
				'fail',
				$title,
				/* translators: 1: HTTP status, 2: the error code WordPress answered with. */
				sprintf( __( 'WordPress refused the request before it reached the MCP endpoint (status %1$d, %2$s): a plugin or a code snippet restricts the REST API.', 'alphabridge-mcp' ), $status, $wp_code ),
				array( $rest_way )
			);
		}

		if ( ( 401 === $status || 403 === $status ) && ! $own ) {
			return self::result(
				$id,
				'fail',
				$title,
				/* translators: %d: HTTP status. */
				sprintf( __( 'Something in front of WordPress answered %d in place of the MCP endpoint: a firewall at the host or a security plugin.', 'alphabridge-mcp' ), $status ),
				array(
					/* translators: %s: the MCP endpoint. */
					sprintf( __( 'If a security plugin is active, look in its log of blocked requests for the request to %s and allow it.', 'alphabridge-mcp' ), $url ),
					/* translators: %s: the MCP endpoint. */
					sprintf( __( 'Otherwise ask your host to let POST requests with a JSON body to %s through their firewall.', 'alphabridge-mcp' ), $url ),
				)
			);
		}

		if ( $status >= 300 && $status < 400 ) {
			$location = isset( $headers['location'] ) ? $headers['location'] : '?';
			return self::result(
				$id,
				'fail',
				$title,
				/* translators: 1: the MCP endpoint, 2: HTTP status, 3: the address it redirects to. */
				sprintf( __( 'The MCP endpoint %1$s answers with a redirect (%2$d) to %3$s instead of answering itself.', 'alphabridge-mcp' ), $url, $status, $location ),
				array(
					self::address_step(),
					/* translators: %s: where this plugin's REST routes begin. */
					sprintf( __( 'If they already match, check which plugin or server rule sets the redirect, and exempt the addresses that begin with %s from it.', 'alphabridge-mcp' ), $base ),
				)
			);
		}

		if ( $status >= 500 ) {
			return self::result(
				$id,
				'fail',
				$title,
				/* translators: %d: HTTP status. */
				sprintf( __( 'The MCP endpoint answers with the server error %d.', 'alphabridge-mcp' ), $status ),
				array(
					__( 'Read PHP’s error log for the time of this check: with write access on, the tool wp_get_error_log returns the end of wp-content/debug.log (WordPress writes it when WP_DEBUG_LOG is on in wp-config.php); otherwise your hosting panel shows the server’s error log.', 'alphabridge-mcp' ),
					__( 'If the log names a plugin or theme, update it or switch it off, then run this check again.', 'alphabridge-mcp' ),
				)
			);
		}

		return self::result(
			$id,
			'fail',
			$title,
			/* translators: %d: HTTP status. */
			sprintf( __( 'The MCP endpoint answered with status %d instead of 401: something between the app and WordPress — a cache, a proxy or a firewall — answered in its place.', 'alphabridge-mcp' ), $status ),
			array(
				/* translators: 1: the MCP endpoint, 2: HTTP status. */
				sprintf( __( 'Ask your host what answers POST requests to %1$s with status %2$d, and to pass them to WordPress.', 'alphabridge-mcp' ), $url, $status ),
				/* translators: %s: where this plugin's REST routes begin. */
				sprintf( __( 'If a caching plugin or a CDN is in use, exclude the addresses that begin with %s from its cache.', 'alphabridge-mcp' ), $base ),
			)
		);
	}

	/**
	 * 4. The Authorization header: sent with a made-up token, this plugin's
	 * 401 says “rejected” when the header arrived and “missing” when the
	 * server dropped it. The hub is no way round it: it sends the token in
	 * the same header.
	 *
	 * @param array|null $answer  Answer as send() returns it; null when not sent.
	 * @param bool       $reached Whether check 3 got this plugin's own answer.
	 * @param bool       $basic   Whether the site is behind an HTTP login.
	 * @return array
	 */
	public static function interpret_authorization( $answer, $reached, $basic ) {
		$id    = 'authorization_header';
		$title = __( 'Authorization header', 'alphabridge-mcp' );
		if ( ! $reached ) {
			return self::result( $id, 'skip', $title, __( 'This check needs the plugin’s own answer from the MCP endpoint, which the endpoint check did not get; fix that first.', 'alphabridge-mcp' ) );
		}
		if ( $basic ) {
			return self::result( $id, 'skip', $title, __( 'This site is behind an HTTP login, which uses the same header, so this check cannot tell whether a token would arrive.', 'alphabridge-mcp' ) );
		}
		$answer = self::normalize( $answer );
		if ( null === $answer['status'] ) {
			return self::no_answer( $id, $title, self::endpoint_url(), $answer );
		}
		$seen = isset( $answer['headers']['x-ab-mcp-auth'] ) ? strtolower( trim( $answer['headers']['x-ab-mcp-auth'] ) ) : '';
		if ( 'rejected' === $seen ) {
			return self::result( $id, 'ok', $title, __( 'The Authorization header reaches WordPress, so tokens sent as “Authorization: Bearer …” arrive.', 'alphabridge-mcp' ) );
		}
		if ( 'missing' === $seen ) {
			return self::result(
				$id,
				'fail',
				$title,
				__( 'The server removes the Authorization header before WordPress sees it, so Claude, ChatGPT and every app that sends its token there are refused with 401.', 'alphabridge-mcp' ),
				array(
					__( 'On Apache, add the line CGIPassAuth On to the .htaccess file in the WordPress folder, above the line # BEGIN WordPress. If the server does not accept that line, add these two lines there instead: “RewriteEngine On” and “RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]”.', 'alphabridge-mcp' ),
					__( 'On nginx or any other server, ask your host to pass the Authorization header on to PHP.', 'alphabridge-mcp' ),
					__( 'Until then, apps that take an address can connect with the token in it: under Settings → AlphaBridge MCP choose “Other”, tick “Also offer an address that contains the token.”, save, and create a connection.', 'alphabridge-mcp' ),
				)
			);
		}
		return self::result(
			$id,
			'skip',
			$title,
			/* translators: %d: HTTP status. */
			sprintf( __( 'The answer to a request with a token (status %d) did not say whether the header arrived, so this check has no result.', 'alphabridge-mcp' ), $answer['status'] ),
			array(
				__( 'Run this check again; if it stays without a result, check the MCP endpoint with a token from a terminal or from the app itself.', 'alphabridge-mcp' ),
			)
		);
	}

	/**
	 * 5. The sign-in description below /.well-known/ (RFC 9728), which
	 * Claude, ChatGPT and the AlphaBridge hub all read before they sign in.
	 *
	 * @param array|null $answer Answer as send() returns it; null when not sent.
	 * @param bool       $oauth  Whether the sign-in for apps is on.
	 * @param bool       $plain  Whether the permalinks are set to “Plain”.
	 * @return array
	 */
	public static function interpret_well_known( $answer, $oauth, $plain ) {
		$id    = 'well_known';
		$title = __( 'Sign-in addresses (.well-known)', 'alphabridge-mcp' );
		if ( ! $oauth ) {
			return self::result(
				$id,
				'info',
				$title,
				__( 'The sign-in for apps is off on this site, so there are no sign-in addresses to check; apps connect with a token only.', 'alphabridge-mcp' ),
				array(
					__( 'To let Claude and ChatGPT connect with their Connect button, tick “Claude and other apps can sign in with their Connect button.” under Settings → AlphaBridge MCP → How Claude signs in, and save.', 'alphabridge-mcp' ),
				)
			);
		}
		$url    = AB_MCP_OAuth::resource_metadata_url();
		$answer = self::normalize( $answer );
		$status = $answer['status'];
		if ( null === $status ) {
			return self::no_answer( $id, $title, $url, $answer );
		}
		$host_step = __( 'Ask your host to pass the addresses that begin with /.well-known/oauth- to WordPress’s index.php, as they pass every other address that is no file.', 'alphabridge-mcp' );
		$perma     = __( 'Under Settings → Permalinks choose “Post name” and click “Save Changes”.', 'alphabridge-mcp' );

		if ( 200 === $status ) {
			$data = json_decode( $answer['body'], true );
			if ( is_array( $data ) && isset( $data['resource'] ) ) {
				return self::result(
					$id,
					'ok',
					$title,
					/* translators: %s: address of the sign-in description. */
					sprintf( __( 'The sign-in description at %s answers as it should.', 'alphabridge-mcp' ), $url )
				);
			}
			return self::result(
				$id,
				'fail',
				$title,
				/* translators: %s: address of the sign-in description. */
				sprintf( __( 'The address %s answers, but not with this plugin’s sign-in description: another plugin or the server answers it.', 'alphabridge-mcp' ), $url ),
				array(
					__( 'If another plugin that offers OAuth or MCP is active, switch it off and run this check again.', 'alphabridge-mcp' ),
					$host_step,
				)
			);
		}
		if ( 404 === $status ) {
			$steps = $plain ? array( $perma ) : array();
			array_push( $steps, $host_step, self::token_way() );
			return self::result(
				$id,
				'fail',
				$title,
				/* translators: %s: address of the sign-in description. */
				sprintf( __( 'The address %s answers 404: the server answers the addresses below /.well-known/ itself instead of passing them to WordPress, so Claude, ChatGPT and the AlphaBridge hub cannot find the sign-in.', 'alphabridge-mcp' ), $url ),
				$steps
			);
		}
		if ( 403 === $status || 406 === $status ) {
			return self::result(
				$id,
				'fail',
				$title,
				/* translators: 1: HTTP status, 2: address of the sign-in description. */
				sprintf( __( 'Something in front of the site answered %1$d in place of the sign-in description at %2$s, the way a firewall does.', 'alphabridge-mcp' ), $status, $url ),
				array(
					__( 'Ask your host, or look in your security plugin’s log of blocked requests, why GET requests to the addresses that begin with /.well-known/oauth- are blocked, and allow them.', 'alphabridge-mcp' ),
					self::token_way(),
				)
			);
		}
		if ( $status >= 300 && $status < 400 ) {
			$location = isset( $answer['headers']['location'] ) ? $answer['headers']['location'] : '?';
			return self::result(
				$id,
				'warn',
				$title,
				/* translators: 1: address of the sign-in description, 2: HTTP status, 3: the address it redirects to. */
				sprintf( __( 'The sign-in description at %1$s answers with a redirect (%2$d) to %3$s, which not every app follows.', 'alphabridge-mcp' ), $url, $status, $location ),
				array( self::address_step() )
			);
		}
		return self::result(
			$id,
			'fail',
			$title,
			/* translators: 1: address of the sign-in description, 2: HTTP status. */
			sprintf( __( 'The sign-in description at %1$s answers with status %2$d.', 'alphabridge-mcp' ), $url, $status ),
			array(
				/* translators: %d: HTTP status. */
				sprintf( __( 'Ask your host what answers this address with status %d, and to pass it to WordPress.', 'alphabridge-mcp' ), $status ),
				self::token_way(),
			)
		);
	}

	/**
	 * 6. The firewall self-test (AB_MCP_OAuth::run_firewall_check()), in the
	 * words of the settings page.
	 *
	 * @param array|null $finding The finding; null when not run.
	 * @param bool       $oauth   Whether the sign-in for apps is on.
	 * @param bool       $direct  Whether the direct sign-in is offered (AB_MCP_OAuth::cimd_enabled()).
	 * @return array
	 */
	public static function interpret_firewall( $finding, $oauth, $direct = true ) {
		$id    = 'firewall';
		$title = __( 'Firewall and the direct sign-in', 'alphabridge-mcp' );
		if ( ! $oauth ) {
			return self::result( $id, 'skip', $title, __( 'The sign-in for apps is off on this site, so the direct sign-in of ChatGPT and Codex is not offered and there is nothing to test.', 'alphabridge-mcp' ) );
		}
		if ( ! $direct || ! is_array( $finding ) ) {
			return self::result( $id, 'skip', $title, __( 'The direct sign-in of ChatGPT and Codex is switched off on this site, so they register with the site and no firewall test is needed.', 'alphabridge-mcp' ) );
		}
		$verdict = isset( $finding['verdict'] ) ? (string) $finding['verdict'] : 'unknown';
		if ( 'open' === $verdict ) {
			return self::result( $id, 'ok', $title, __( 'The host’s firewall lets the direct sign-in of ChatGPT and Codex through.', 'alphabridge-mcp' ) );
		}
		if ( 'blocked' === $verdict ) {
			return self::result(
				$id,
				'warn',
				$title,
				__( 'Your host’s firewall blocks the direct sign-in. ChatGPT and Codex register with the site instead.', 'alphabridge-mcp' ),
				array(
					__( 'In the app, remove the connector for this site and add it again. The sign-in now goes through.', 'alphabridge-mcp' ),
					/* translators: %s: the hub's address, as code. */
					sprintf( __( 'If it still fails, add the connector once more, this time with this address instead of your site’s: %s. When you click Authenticate, the free AlphaBridge hub asks for your site’s address. Enter it and approve on your site as usual.', 'alphabridge-mcp' ), AB_MCP_Admin::HUB_ADDRESS ),
				)
			);
		}
		$status = isset( $finding['status'] ) && null !== $finding['status'] ? (string) (int) $finding['status'] : __( 'no answer', 'alphabridge-mcp' );
		return self::result(
			$id,
			'skip',
			$title,
			/* translators: %s: HTTP status of the answer, or “no answer”. */
			sprintf( __( 'The firewall test got no clear answer (%s), so nothing changes: the direct sign-in of ChatGPT and Codex stays on.', 'alphabridge-mcp' ), $status )
		);
	}

	/**
	 * 7. Cloudflare: seen by its cf-ray header in any of the answers. Never
	 * called the cause — only the place to look when everything here passes.
	 *
	 * @param array $header_lists Headers of each answer (name => value).
	 * @return array
	 */
	public static function interpret_cloudflare( array $header_lists ) {
		$id    = 'cloudflare';
		$title = __( 'Cloudflare', 'alphabridge-mcp' );
		foreach ( $header_lists as $headers ) {
			if ( is_array( $headers ) && isset( $headers['cf-ray'] ) ) {
				return self::result(
					$id,
					'info',
					$title,
					__( 'The site is behind Cloudflare (its answers carry the cf-ray header); if Claude or ChatGPT cannot connect while the other checks pass, Cloudflare’s bot settings for AI crawlers are the usual cause, and a request from the site to itself cannot see them.', 'alphabridge-mcp' ),
					array(
						/* translators: 1: the MCP endpoint, 2: where the sign-in descriptions begin. */
						sprintf( __( 'In the Cloudflare dashboard of this domain, open the security events and look for blocked or challenged requests to %1$s or to addresses that begin with %2$s at the time an app tried to connect.', 'alphabridge-mcp' ), self::endpoint_url(), self::well_known_base() ),
						/* translators: 1: where this plugin's REST routes begin, 2: where the sign-in descriptions begin. */
						sprintf( __( 'If you find them, change the bot setting or rule the event names, or add a rule that skips Cloudflare’s security features for addresses that begin with %1$s or %2$s.', 'alphabridge-mcp' ), self::rest_base(), self::well_known_base() ),
					)
				);
			}
		}
		return self::result( $id, 'skip', $title, __( 'The site’s answers to itself carry no Cloudflare header; a site whose requests to itself bypass Cloudflare can still be behind it.', 'alphabridge-mcp' ) );
	}

	/**
	 * 8. Active plugins known to restrict the REST API for requests without
	 * a login, named with the kind of setting to look for.
	 *
	 * @param string[] $active Active plugin basenames.
	 * @return array
	 */
	public static function interpret_rest_plugins( array $active ) {
		$id    = 'rest_plugins';
		$title = __( 'Plugins that restrict the REST API', 'alphabridge-mcp' );
		$found = array();
		foreach ( $active as $basename ) {
			$folder = strtok( (string) $basename, '/' );
			if ( isset( self::REST_PLUGINS[ $folder ] ) ) {
				$found[ $folder ] = self::REST_PLUGINS[ $folder ];
			}
		}
		if ( empty( $found ) ) {
			return self::result( $id, 'ok', $title, __( 'None of the plugins this check knows to restrict the REST API is active.', 'alphabridge-mcp' ) );
		}
		$names = array();
		$steps = array();
		foreach ( $found as $plugin ) {
			$names[] = $plugin[0];
			$steps[] = $plugin[1]
				/* translators: %s: plugin name. */
				? sprintf( __( 'If the MCP endpoint check fails, open the settings of %s, find the setting that restricts the REST API to logged-in users, and allow the routes that begin with /alphabridge/v1/ or switch that restriction off.', 'alphabridge-mcp' ), $plugin[0] )
				/* translators: %s: plugin name. */
				: sprintf( __( 'If the MCP endpoint check fails, deactivate %s under Plugins: it closes the REST API to every request without a login, and apps connect without one.', 'alphabridge-mcp' ), $plugin[0] );
		}
		return self::result(
			$id,
			'info',
			$title,
			/* translators: %s: plugin names, comma-separated. */
			sprintf( __( 'Active: %s, which can restrict the REST API for requests without a login.', 'alphabridge-mcp' ), implode( ', ', $names ) ),
			$steps
		);
	}

	/* ------------------------------------------------------------ helpers */

	/**
	 * An answer in the shape send() returns, whatever was passed.
	 *
	 * @param mixed $answer Answer or null.
	 * @return array{status:int|null,headers:array,body:string,error:string}
	 */
	private static function normalize( $answer ) {
		$answer = is_array( $answer ) ? $answer : array();
		return array(
			'status'  => isset( $answer['status'] ) && null !== $answer['status'] ? (int) $answer['status'] : null,
			'headers' => isset( $answer['headers'] ) ? self::header_list( $answer['headers'] ) : array(),
			'body'    => isset( $answer['body'] ) ? (string) $answer['body'] : '',
			'error'   => isset( $answer['error'] ) ? (string) $answer['error'] : '',
		);
	}

	/**
	 * Whether a word appears in the body or in any header name or value.
	 *
	 * @param array  $headers Headers (name => value).
	 * @param string $body    Start of the body.
	 * @param string $word    Lower-case word.
	 * @return bool
	 */
	private static function mentions( array $headers, $body, $word ) {
		if ( false !== stripos( (string) $body, $word ) ) {
			return true;
		}
		foreach ( $headers as $name => $value ) {
			if ( false !== stripos( $name . ' ' . $value, $word ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether any header name starts with a prefix.
	 *
	 * @param array  $headers Headers (name => value).
	 * @param string $prefix  Lower-case prefix.
	 * @return bool
	 */
	private static function has_header_prefix( array $headers, $prefix ) {
		foreach ( array_keys( $headers ) as $name ) {
			if ( 0 === strpos( (string) $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
