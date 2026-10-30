<?php
/**
 * Test requests after a PHP file of an active plugin or theme was written:
 * does the site still run?
 *
 * The parse and compile checks cannot see errors that only appear when the
 * code runs — a missing function, a name another plugin already declares.
 * Such an error stops every request that loads the file, this MCP endpoint's
 * too (measured on Playground, 02.10.2026), and with it the way back.
 *
 * WordPress's own file editor solves this with its scrape mechanism, and this
 * class uses exactly that: a random key and nonce are stored in a
 * short-lived transient (wp-admin/includes/file.php), the key travels in the
 * query of a request to the site itself, and core's
 * wp_start_scraping_edited_file_errors() — called in wp-settings.php before
 * the plugins load, without looking at who is logged in — prints the last
 * fatal error of that request between two markers at shutdown.
 *
 * Four places are asked, because a fatal error can hide on any of them: the
 * home page (front-end hooks), this MCP endpoint (rest_api_init; without a
 * token it answers its own 401, which is the expected answer),
 * wp-admin/admin-post.php (admin_init runs there before the login is
 * checked) and the dashboard in wp-admin, logged in as the account of this
 * connection (admin_menu, the screens' hooks and the dashboard's widgets
 * run only there). The editor of WordPress sends the cookies of the person
 * who saves; an MCP request has none, so for the dashboard this class opens
 * a session of its own for that account (WP_Session_Tokens), valid for a few
 * minutes, sends its cookies with that one request only and destroys the
 * session right after the round. The other three requests go without
 * cookie, token or user. Where the dashboard sends the request to the login
 * page instead (a security plugin that wants a second factor, say), that
 * target is left out of the round and the report says why. Not covered:
 * other wp-admin screens, cron, AJAX and code that runs only on some pages
 * or for other users.
 *
 * A request can also end early without a fatal error: a redirect, wp_die(),
 * a REST block for guests. The markers alone would call that "ok" although
 * the code after that point never ran — and at this MCP endpoint it means
 * the connection is cut (measured on Playground, 02.10.2026). So each answer
 * is also judged by what the target gives when it runs through.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-probe-state.php';

/**
 * Class AB_MCP_Php_Probe
 */
class AB_MCP_Php_Probe {

	/**
	 * Seconds one test request may take. WordPress's editor waits up to 100;
	 * here the MCP request waits for all of them, and the new file is live on
	 * the site the whole time, so a broken one must go again soon. The home
	 * page of the measurement site answered in under 2 s.
	 */
	const TIMEOUT = 10;

	/** Never wait longer than this per request, whatever a filter says. */
	const TIMEOUT_MAX = 60;

	/**
	 * Redirects the request to the home page follows, each only to this
	 * site's own host: a language plugin that sends "/" on to "/de/" (and a
	 * canonical slash after that) would otherwise keep the front end, and
	 * every error in its templates, out of sight (measured: a fatal error in
	 * wp_head went unseen behind a redirect, 02.10.2026). Other targets do
	 * not follow: a redirect there ends the code the target is asked for.
	 */
	const HOME_REDIRECTS = 2;

	/** What the answer says is not looked at. */
	const NOT_CHECKED = 'wp-admin screens other than the dashboard (the editors, settings pages), cron, AJAX, code that runs only on some pages or for other users, the dashboard where its login is not accepted (see logged_in), and the front end where the home page redirects to another host or ends early';

	/**
	 * Seconds the session of the dashboard request is valid at most. It is
	 * destroyed right after the round; this only bounds a session left by a
	 * request that died in between.
	 */
	const SESSION_SECONDS = 300;

	/**
	 * May the probe run on this site? An administrator can switch it off with
	 * the ab_mcp_php_probe_enabled filter, e.g. where the site cannot reach
	 * itself and every write would otherwise need allow_without_probe.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) apply_filters( 'ab_mcp_php_probe_enabled', true );
	}

	/**
	 * Folders of the code this site loads: active plugins that live in a
	 * folder (a single-file plugin in plugins/ is skipped — its folder would
	 * be all of plugins/, and the file tools only write inside a plugin's
	 * folder), the active theme and its parent.
	 *
	 * @return string[] Folders, as WordPress names them.
	 */
	public static function active_dirs() {
		$plugins = (array) get_option( 'active_plugins', array() );
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$dirs = array();
		foreach ( $plugins as $plugin ) {
			$folder = dirname( str_replace( '\\', '/', (string) $plugin ) );
			if ( '' === $folder || '.' === $folder ) {
				continue;
			}
			$dirs[] = WP_PLUGIN_DIR . '/' . $folder;
		}
		$dirs[] = get_stylesheet_directory();
		$dirs[] = get_template_directory();
		return array_values( array_unique( $dirs ) );
	}

	/**
	 * The places a test request goes to, in the order they are asked.
	 *
	 * @return array<string,string> name => URL
	 */
	public static function targets() {
		$route = ( defined( 'AB_MCP_REST_NAMESPACE' ) && defined( 'AB_MCP_REST_ROUTE' ) )
			? AB_MCP_REST_NAMESPACE . AB_MCP_REST_ROUTE
			: 'alphabridge/v1/mcp';
		$out = array(
			'home'       => home_url( '/' ),
			'mcp'        => rest_url( $route ),
			'admin_post' => admin_url( 'admin-post.php' ),
		);
		if ( self::logged_in_possible() ) {
			$out['admin'] = admin_url( 'index.php' );
		}
		return $out;
	}

	/**
	 * The names of the targets, without building their URLs: for a verdict
	 * where building them is what failed (a filter of home_url() that
	 * throws).
	 *
	 * @return string[]
	 */
	private static function target_names() {
		$names = array( 'home', 'mcp', 'admin_post' );
		try {
			if ( self::logged_in_possible() ) {
				$names[] = 'admin';
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $names;
	}

	/**
	 * Can the dashboard be asked, logged in as the account of this
	 * connection? Needs that account and WordPress's sessions and cookies;
	 * an administrator can switch it off with the
	 * ab_mcp_php_probe_logged_in filter.
	 *
	 * @return bool
	 */
	public static function logged_in_possible() {
		if ( ! function_exists( 'wp_generate_auth_cookie' ) || ! class_exists( 'WP_Session_Tokens' ) || ! function_exists( 'get_current_user_id' ) ) {
			return false;
		}
		if ( ! defined( 'AUTH_COOKIE' ) || ! defined( 'SECURE_AUTH_COOKIE' ) || ! defined( 'LOGGED_IN_COOKIE' ) ) {
			return false;
		}
		if ( get_current_user_id() <= 0 || ! current_user_can( 'read' ) ) {
			return false;
		}
		return (bool) apply_filters( 'ab_mcp_php_probe_logged_in', true );
	}

	/**
	 * A session for the dashboard request: WordPress's own, for the account
	 * of this connection, valid for SESSION_SECONDS, and the cookies that
	 * carry it. No login hook runs (wp_set_auth_cookie() is not called), so
	 * nothing is sent to a browser and no plugin sees a login.
	 *
	 * @return array|null { user, token, cookies } or null where none could be made.
	 */
	private static function open_session() {
		try {
			$user    = (int) get_current_user_id();
			$expires = time() + self::SESSION_SECONDS;
			$token   = WP_Session_Tokens::get_instance( $user )->create( $expires );
			if ( ! is_string( $token ) || '' === $token ) {
				return null;
			}
			return array(
				'user'    => $user,
				'token'   => $token,
				'cookies' => array(
					AUTH_COOKIE        => wp_generate_auth_cookie( $user, $expires, 'auth', $token ),
					SECURE_AUTH_COOKIE => wp_generate_auth_cookie( $user, $expires, 'secure_auth', $token ),
					LOGGED_IN_COOKIE   => wp_generate_auth_cookie( $user, $expires, 'logged_in', $token ),
				),
			);
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Destroy the session of the dashboard request.
	 *
	 * @param array|null $session From open_session().
	 */
	private static function close_session( $session ) {
		if ( ! is_array( $session ) ) {
			return;
		}
		try {
			WP_Session_Tokens::get_instance( (int) $session['user'] )->destroy( (string) $session['token'] );
		} catch ( \Throwable $e ) {
			// It ends by itself after SESSION_SECONDS.
			unset( $e );
		}
	}

	/**
	 * Arguments of every test request. Like WordPress's loopback: no
	 * redirects followed by the HTTP API (a redirect would carry the key to
	 * wherever it points; the home page follows its own, to this host only),
	 * certificate check as https_local_ssl_verify says, no cache.
	 * The ab_mcp_php_probe_request_args filter can add headers — an HTTP
	 * login in front of a staging site, say — or give a slow site more time;
	 * it cannot follow redirects or send cookies. The only cookies are those
	 * of the session run() opens for the dashboard request (ask()).
	 *
	 * @return array
	 */
	public static function request_args() {
		$args = array(
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
			'cookies'     => array(),
		);
		$args = apply_filters( 'ab_mcp_php_probe_request_args', $args );
		$args = is_array( $args ) ? $args : array();

		$timeout         = isset( $args['timeout'] ) ? (float) $args['timeout'] : (float) self::TIMEOUT;
		$args['timeout'] = max( 1.0, min( (float) self::TIMEOUT_MAX, $timeout ) );
		$headers         = ( isset( $args['headers'] ) && is_array( $args['headers'] ) ) ? $args['headers'] : array();
		foreach ( array_keys( $headers ) as $name ) {
			if ( 'cookie' === strtolower( trim( (string) $name ) ) ) {
				unset( $headers[ $name ] );
			}
		}
		$args['headers']     = $headers;
		$args['redirection'] = 0;
		$args['cookies']     = array();
		$args['blocking']    = true;
		$args['stream']      = false;
		unset( $args['filename'] );
		return $args;
	}

	/**
	 * The most requests one round can send to these targets: one each, and
	 * the home page's redirects.
	 *
	 * @param array $targets name => URL.
	 * @return int
	 */
	public static function requests_max( array $targets ) {
		return count( $targets ) + ( isset( $targets['home'] ) ? self::HOME_REDIRECTS : 0 );
	}

	/**
	 * Send the test requests and judge them. Never throws: a request that
	 * fails inside this site (another plugin's HTTP filter throwing, say)
	 * counts as not reached, so the caller always gets a verdict and can put
	 * a file back.
	 *
	 * @param string[] $only          Targets to ask (names); all when empty.
	 * @param bool     $stop_at_fatal Stop at the first fatal error: the new
	 *                                file is live while this runs, and a
	 *                                broken one has to be taken back as soon
	 *                                as that is known.
	 * @param array    $opts {
	 *     Optional.
	 *
	 *     @type string $key     Test key to use instead of a random one, with
	 *                           $nonce. An update holds the maintenance mode
	 *                           while it tests, and WordPress lets a request
	 *                           through it only with the key core derives
	 *                           from .maintenance (md5 of $upgrading, the
	 *                           nonce $upgrading as a string; wp-includes/
	 *                           load.php, wp_is_maintenance_mode()). The
	 *                           transient holds the nonce as a string: core
	 *                           compares it strictly with the one in the
	 *                           query (wp_start_scraping_edited_file_errors()).
	 *     @type string $nonce   Its nonce.
	 *     @type float  $timeout Seconds per request instead of what
	 *                           request_args() gives, up to TIMEOUT_MAX: an
	 *                           update asks a target that timed out once more
	 *                           with longer, as the first request after an
	 *                           update is often the slowest.
	 *     @type float  $until   When the requests have to be over, by $clock:
	 *                           every request, each redirect of the home page
	 *                           included, gets at most the time left before
	 *                           it, worked out again before each one. A
	 *                           request there is no second left for is not
	 *                           sent, and one cut short that timed out was not
	 *                           given a test request's time: both are marked
	 *                           out_of_time, not checked rather than failed.
	 *     @type callable $clock The clock of $until; microtime( true ) when
	 *                           not given.
	 *     @type bool   $state   Ask each target what it loaded (plugins,
	 *                           theme); each row that printed it gets
	 *                           "state" (AB_MCP_Probe_State).
	 * }
	 * @return array {
	 *     @type string $result  passed (every target ok) | partial (none fatal,
	 *                           some not ok) | fatal | unreachable (none
	 *                           reached).
	 *     @type array  $targets One row per target (see judge()).
	 *     @type array  $fatal   The first row with a fatal error, if any.
	 *     @type array  $logged_in Where the dashboard was meant to be asked:
	 *                           { result: asked|refused|no_session, reason? }.
	 * }
	 */
	public static function run( array $only = array(), $stop_at_fatal = true, array $opts = array() ) {
		try {
			return self::run_rounds( $only, (bool) $stop_at_fatal, $opts );
		} catch ( \Throwable $e ) {
			$names = $only ? $only : self::target_names();
			return self::none_reached( array_fill_keys( $names, '' ), self::failed_inside( $e ) );
		}
	}

	/**
	 * The body of run().
	 *
	 * @param string[] $only          Targets to ask.
	 * @param bool     $stop_at_fatal See run().
	 * @param array    $opts          See run().
	 * @return array
	 */
	private static function run_rounds( array $only, $stop_at_fatal, array $opts = array() ) {
		$targets = self::targets();
		if ( $only ) {
			$targets = array_intersect_key( $targets, array_flip( $only ) );
		}
		$args = self::request_args();
		if ( isset( $opts['timeout'] ) && is_numeric( $opts['timeout'] ) ) {
			$args['timeout'] = max( 1.0, min( (float) self::TIMEOUT_MAX, (float) $opts['timeout'] ) );
		}
		$until = ( isset( $opts['until'] ) && is_numeric( $opts['until'] ) ) ? array(
			(float) $opts['until'],
			( isset( $opts['clock'] ) && is_callable( $opts['clock'] ) ) ? $opts['clock'] : static function () {
				return microtime( true );
			},
		) : null;

		$given = isset( $opts['key'], $opts['nonce'] ) && is_string( $opts['key'] ) && is_string( $opts['nonce'] )
			&& preg_match( '/^[a-f0-9]{32}\z/', $opts['key'] ) && '' !== $opts['nonce'];
		try {
			$key   = $given ? $opts['key'] : bin2hex( random_bytes( 16 ) );
			$nonce = $given ? (string) $opts['nonce'] : bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			return self::none_reached( $targets, __( 'no random test key could be made on this server.', 'alphabridge-mcp' ) );
		}
		// The key may only work while the requests run; it is deleted right
		// after them anyway.
		$budget = (int) ceil( self::requests_max( $targets ) * $args['timeout'] );
		if ( ! set_transient( 'scrape_key_' . $key, $nonce, max( 60, $budget + 10 ) ) ) {
			return self::none_reached( $targets, __( 'the test key could not be stored (set_transient failed).', 'alphabridge-mcp' ) );
		}

		// As in WordPress's editor: a session held open by this request would
		// make the requests to the same site wait for it.
		if ( function_exists( 'session_status' ) && PHP_SESSION_ACTIVE === session_status() ) {
			session_write_close();
		}
		// A request that ran out of time between the write and the way back
		// would leave a broken file live. Only ever raised, never lowered;
		// set_time_limit() counts from now, so every round gets its budget.
		$limit = (int) ini_get( 'max_execution_time' );
		if ( $limit > 0 && function_exists( 'set_time_limit' ) ) {
			@set_time_limit( max( $limit, $budget + 30 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$state   = ! empty( $opts['state'] );
		$session = null;
		$login   = null;
		if ( isset( $targets['admin'] ) ) {
			$session = self::open_session();
			if ( null === $session ) {
				unset( $targets['admin'] );
				$login = array(
					'result' => 'no_session',
					'reason' => __( 'no session could be opened for the account of this connection, so the dashboard was not asked', 'alphabridge-mcp' ),
				);
			}
		}

		$rows  = array();
		$fatal = null;
		$begun = microtime( true );
		try {
			foreach ( $targets as $name => $url ) {
				if ( $stop_at_fatal && null !== $fatal ) {
					$rows[] = array(
						'target' => $name,
						'url'    => $url,
						'result' => 'not_probed',
					);
					continue;
				}
				$started = microtime( true );
				$extra   = array( 'state' => $state );
				if ( 'admin' === $name && null !== $session ) {
					$extra['cookies'] = $session['cookies'];
				}
				try {
					$row = self::ask( $name, $url, $key, $nonce, $args, $until, $extra );
				} catch ( \Throwable $e ) {
					$row = array(
						'target' => $name,
						'url'    => $url,
						'result' => 'unreachable',
						'reason' => self::failed_inside( $e ),
					);
				}
				$row['ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
				if ( 'admin' === $name && ! empty( $row['login_refused'] ) ) {
					// Not the code: the login of this request. Left out, so
					// that it neither counts as a failure nor hides one.
					$login = array(
						'result' => 'refused',
						'reason' => (string) $row['reason'],
					);
					continue;
				}
				$rows[] = $row;
				if ( null === $fatal && 'fatal' === $row['result'] ) {
					$fatal = $row;
				}
			}
		} finally {
			delete_transient( 'scrape_key_' . $key );
			self::close_session( $session );
		}

		// A fatal error before WordPress's report shows without file and
		// line; the PHP error log may name them (early_fatal()).
		$logged = 'unread';
		foreach ( $rows as $i => $row ) {
			if ( empty( $row['early'] ) || '' !== (string) $row['error']['file'] ) {
				continue;
			}
			if ( 'unread' === $logged ) {
				$logged = self::logged_fatal( $begun );
			}
			if ( is_array( $logged ) ) {
				$rows[ $i ]['error']  = $logged;
				$rows[ $i ]['logged'] = true;
			} else {
				$rows[ $i ]['log'] = false === $logged ? 'none' : 'silent';
			}
			if ( null !== $fatal && $fatal['target'] === $row['target'] ) {
				$fatal = $rows[ $i ];
			}
		}

		$out = array(
			'result'      => self::verdict( $rows ),
			'targets'     => $rows,
			'not_checked' => self::NOT_CHECKED,
		);
		if ( null !== $fatal ) {
			$out['fatal'] = $fatal;
		}
		if ( null !== $login ) {
			$out['logged_in'] = $login;
		} elseif ( null !== $session ) {
			$out['logged_in'] = array( 'result' => 'asked' );
		}
		return $out;
	}

	/**
	 * Ask one target. The home page follows up to HOME_REDIRECTS redirects
	 * that stay on the host it was asked on, so the key never leaves this
	 * site and never drops from https to http.
	 *
	 * @param string $name  Target name.
	 * @param string $url   Target URL.
	 * @param string $key   The test key.
	 * @param string $nonce Its nonce.
	 * @param array  $args  Request arguments.
	 * @param array|null $until { deadline, clock } of run(), or null.
	 * @param array  $extra { state: ask for the state, cookies: the
	 *                      dashboard's session (that target only) }.
	 * @return array Row.
	 */
	private static function ask( $name, $url, $key, $nonce, array $args, $until = null, array $extra = array() ) {
		$query = array(
			'wp_scrape_key'   => $key,
			'wp_scrape_nonce' => $nonce,
		);
		if ( ! empty( $extra['state'] ) ) {
			$query[ AB_MCP_Probe_State::PARAM ] = '1';
		}
		if ( 'admin' === $name && ! empty( $extra['cookies'] ) && is_array( $extra['cookies'] ) ) {
			$args['cookies'] = $extra['cookies'];
		}
		$at    = $url;
		$hops  = array();
		$usual = (float) $args['timeout'];
		while ( true ) {
			$cut = false;
			if ( null !== $until ) {
				// One time for the target and its redirects together: each
				// request gets what is left before the deadline, at most the
				// usual (Codex, 03.10.2026: three redirects of 9 s each ran
				// 15 s past it and pushed the next targets out).
				$left = $until[0] - (float) call_user_func( $until[1] );
				if ( $left < 1 ) {
					$row = array(
						'target'      => $name,
						'url'         => $url,
						'result'      => 'not_probed',
						'out_of_time' => true,
						'reason'      => __( 'not asked: this call had no time left', 'alphabridge-mcp' ),
					);
					if ( $hops ) {
						$row['redirects'] = $hops;
					}
					return $row;
				}
				$cut             = $left < $usual;
				$args['timeout'] = min( $usual, $left );
			}
			$res = wp_remote_get( add_query_arg( $query, $at ), $args );
			if ( 'home' !== $name || count( $hops ) >= self::HOME_REDIRECTS ) {
				break;
			}
			// A redirect that reports a fatal error is judged as it is.
			$found = is_wp_error( $res ) ? null : self::between_markers( (string) wp_remote_retrieve_body( $res ), $key );
			if ( null !== $found && true !== json_decode( trim( $found ), true ) ) {
				break;
			}
			$next = self::same_site_redirect( $res, $at );
			if ( '' === $next ) {
				break;
			}
			$hops[] = remove_query_arg( array_keys( $query ), $next );
			$at     = $next;
		}
		$row = array(
			'target' => $name,
			'url'    => $url,
		) + self::judge( $res, $key, $name );
		if ( $cut && 'unreachable' === $row['result'] && isset( $row['reason'] ) && preg_match( '/timed out|timeout|cURL error 28/i', (string) $row['reason'] ) ) {
			$row['result']      = 'not_probed';
			$row['out_of_time'] = true;
			$row['given']       = round( (float) $args['timeout'], 1 );
		}
		if ( $hops ) {
			$row['redirects'] = $hops;
		}
		return $row;
	}

	/**
	 * Where a redirect answer points, made absolute, if it stays on the host
	 * of the URL that answered; '' otherwise.
	 *
	 * @param array|WP_Error $res  Answer.
	 * @param string         $from URL that gave it.
	 * @return string
	 */
	private static function same_site_redirect( $res, $from ) {
		if ( is_wp_error( $res ) ) {
			return '';
		}
		$status = (int) wp_remote_retrieve_response_code( $res );
		if ( $status < 300 || $status > 399 ) {
			return '';
		}
		$location = wp_remote_retrieve_header( $res, 'location' );
		if ( is_array( $location ) ) {
			$location = (string) reset( $location );
		}
		$location = trim( (string) $location );
		if ( '' === $location ) {
			return '';
		}
		$to = WP_Http::make_absolute_url( $location, $from );
		return self::same_site( $from, (string) $to ) ? (string) $to : '';
	}

	/**
	 * May the test key travel from one URL to the other? Same host, same
	 * port, the same scheme or from http up to https — never down, and up
	 * only from port 80 to 443: another port can be another application
	 * under the same name (Codex, 06.10.2026).
	 *
	 * @param string $from URL.
	 * @param string $to   URL.
	 * @return bool
	 */
	public static function same_site( $from, $to ) {
		$a = wp_parse_url( (string) $from );
		$b = wp_parse_url( (string) $to );
		if ( ! is_array( $a ) || ! is_array( $b ) || empty( $a['host'] ) || empty( $b['host'] ) || 0 !== strcasecmp( (string) $a['host'], (string) $b['host'] ) ) {
			return false;
		}
		$sa = isset( $a['scheme'] ) ? strtolower( (string) $a['scheme'] ) : '';
		$sb = isset( $b['scheme'] ) ? strtolower( (string) $b['scheme'] ) : '';
		if ( ! in_array( $sa, array( 'http', 'https' ), true ) || ! in_array( $sb, array( 'http', 'https' ), true ) ) {
			return false;
		}
		$pa = isset( $a['port'] ) ? (int) $a['port'] : ( 'https' === $sa ? 443 : 80 );
		$pb = isset( $b['port'] ) ? (int) $b['port'] : ( 'https' === $sb ? 443 : 80 );
		if ( $sa === $sb ) {
			return $pa === $pb;
		}
		// http → https: the usual upgrade, from default port to default port.
		return 'http' === $sa && 80 === $pa && 443 === $pb;
	}

	/**
	 * The verdict over all targets. A target that answered without a fatal
	 * error but ended early ("stopped") was reached: WordPress ran.
	 *
	 * @param array $rows Rows from judge().
	 * @return string passed | partial | fatal | unreachable
	 */
	public static function verdict( array $rows ) {
		$ok      = 0;
		$reached = 0;
		foreach ( $rows as $row ) {
			if ( 'fatal' === $row['result'] ) {
				return 'fatal';
			}
			if ( 'ok' === $row['result'] ) {
				++$ok;
			}
			if ( in_array( $row['result'], array( 'ok', 'stopped' ), true ) ) {
				++$reached;
			}
		}
		if ( 0 === $reached ) {
			return 'unreachable';
		}
		return ( count( $rows ) === $ok ) ? 'passed' : 'partial';
	}

	/**
	 * Judge one answer: reached and ok, reached with a fatal error, reached
	 * but ended early without one ("stopped"), or not reached. The markers
	 * say whether WordPress ran and whether it ended with a fatal error; the
	 * answer itself says whether the target ran through: this MCP endpoint
	 * gives its own 401 to a request without a token (anything else means
	 * something now ends requests before AlphaBridge checks the token — the
	 * connection would be cut), and the home page and admin-post.php give
	 * neither a redirect nor a 5xx when they run through; the dashboard, asked
	 * logged in, no 4xx either.
	 *
	 * @param array|WP_Error $res    Answer of wp_remote_get().
	 * @param string         $key    The test key.
	 * @param string         $target Target name; '' judges like the home page.
	 * @return array { result: ok|fatal|stopped|unreachable, http_status?, error?, reason?, code? }
	 */
	public static function judge( $res, $key, $target = '' ) {
		if ( is_wp_error( $res ) ) {
			return array(
				'result' => 'unreachable',
				'reason' => $res->get_error_message(),
			);
		}
		$body  = (string) wp_remote_retrieve_body( $res );
		$state = class_exists( 'AB_MCP_Probe_State' ) ? AB_MCP_Probe_State::read( $body, $key ) : null;
		if ( null === $state ) {
			return self::judge_body( $res, $body, $key, $target );
		}
		// What the target answered is judged without the state, which only
		// this check asked for.
		$row          = self::judge_body( $res, AB_MCP_Probe_State::strip( $body, $key ), $key, $target );
		$row['state'] = $state;
		return $row;
	}

	/**
	 * judge() for a body without the state.
	 *
	 * @param array  $res    Answer of wp_remote_get().
	 * @param string $body   Its body, without the state.
	 * @param string $key    The test key.
	 * @param string $target Target name.
	 * @return array
	 */
	private static function judge_body( $res, $body, $key, $target ) {
		$status = (int) wp_remote_retrieve_response_code( $res );
		$found  = self::between_markers( $body, $key );
		if ( null === $found ) {
			$early = self::early_fatal( $status, $body );
			if ( null !== $early ) {
				return array(
					'result'      => 'fatal',
					'early'       => true,
					'http_status' => $status,
					'error'       => $early,
				);
			}
			// It answered, but without WordPress's report: no_report tells
			// that apart from no answer at all, for the messages.
			return array(
				'result'      => 'unreachable',
				'no_report'   => true,
				'http_status' => $status,
				/* translators: %d: HTTP status */
				'reason'      => sprintf( __( 'answered HTTP %d without the test markers: something in front of WordPress (a page cache, a firewall, an HTTP login) answered, or the request ended before WordPress shut down.', 'alphabridge-mcp' ), $status ),
			);
		}
		$data = json_decode( trim( $found ), true );
		if ( true === $data ) {
			return self::ran_through( $res, $status, $body, $key, (string) $target );
		}
		// The key reached WordPress but did not match the stored nonce: the
		// request did not run as a test, which says nothing about the code.
		if ( is_array( $data ) && isset( $data['code'] ) && 'scrape_nonce_failure' === $data['code'] ) {
			return array(
				'result'      => 'unreachable',
				'http_status' => $status,
				'reason'      => __( 'WordPress did not accept the test key.', 'alphabridge-mcp' ),
			);
		}
		// Anything else between the markers is an error report — or one
		// wp_json_encode() could not encode, which WordPress's editor counts
		// as a failure too.
		$error = array(
			'message' => __( 'a fatal error whose report could not be read.', 'alphabridge-mcp' ),
			'file'    => '',
			'line'    => 0,
		);
		if ( is_array( $data ) && isset( $data['message'] ) ) {
			$error = array(
				'message' => substr( (string) $data['message'], 0, 2000 ),
				'file'    => isset( $data['file'] ) ? (string) $data['file'] : '',
				'line'    => isset( $data['line'] ) ? (int) $data['line'] : 0,
			);
		}
		return array(
			'result'      => 'fatal',
			'http_status' => $status,
			'error'       => $error,
		);
	}

	/**
	 * A fatal error before WordPress starts its test report. wp-settings.php
	 * loads the must-use plugins, the drop-ins and the network-active plugins
	 * of a multisite before it calls wp_start_scraping_edited_file_errors()
	 * (WordPress 7.1.2, wp-settings.php lines 506, 524 and 568), so a fatal
	 * error while they load ends the request without the markers. WordPress's
	 * fatal error handler, registered earlier (line 69), still answers with
	 * HTTP 500 and its error page (wp_die(), body id "error-page", or as JSON
	 * the code internal_server_error). Without this, such an answer read as
	 * code that ends the answer itself, and the message had neither file nor
	 * line (review of 04.10.2026). File and line come from what PHP printed
	 * where the site displays errors, from the JSON page where WP_DEBUG_DISPLAY
	 * adds them, or later from the PHP error log (run_rounds()).
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Body.
	 * @return array|null The error { message, file, line }, or null where this
	 *                    is not WordPress's fatal error page.
	 */
	private static function early_fatal( $status, $body ) {
		if ( 500 !== (int) $status ) {
			return null;
		}
		$json = json_decode( trim( $body ), true );
		if ( is_array( $json ) && isset( $json['code'] ) && 'internal_server_error' === $json['code'] ) {
			$e = isset( $json['data']['error'] ) && is_array( $json['data']['error'] ) ? $json['data']['error'] : array();
			// The first line of PHP's message, without its own "in <file>:<n>"
			// and the stack trace after it.
			$first = isset( $e['message'] ) && is_scalar( $e['message'] ) ? (string) strtok( (string) $e['message'], "\n" ) : '';
			return array(
				'message' => substr( trim( (string) preg_replace( '/\s+in \S+?\.php(?: on line \d+|:\d+)\s*$/', '', $first ) ), 0, 2000 ),
				'file'    => isset( $e['file'] ) && is_scalar( $e['file'] ) ? (string) $e['file'] : '',
				'line'    => isset( $e['line'] ) ? (int) $e['line'] : 0,
			);
		}
		if ( false === strpos( $body, '<body id="error-page">' ) ) {
			return null;
		}
		// What PHP printed, without the page's tags, styles and scripts.
		$text  = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $body );
		$shown = self::php_error_in( html_entity_decode( strip_tags( $text ), ENT_QUOTES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- scripts and styles are out already.
		return null !== $shown ? $shown : array(
			'message' => '',
			'file'    => '',
			'line'    => 0,
		);
	}

	/**
	 * The last fatal error PHP wrote into a text, as it prints and logs it:
	 * "Fatal error: <message> in <file> on line <n>", for an uncaught
	 * exception "… in <file>:<n>" with "thrown in <file> on line <n>" after
	 * the stack trace.
	 *
	 * @param string $text Text.
	 * @return array|null { message, file, line }.
	 */
	private static function php_error_in( $text ) {
		if ( ! preg_match_all( '/(?:Fatal|Parse) error:\s*(.*)/', (string) $text, $all, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$last  = count( $all[0] ) - 1;
		$first = (string) $all[1][ $last ][0];
		$rest  = substr( (string) $text, (int) $all[0][ $last ][1] );
		$file  = '';
		$line  = 0;
		if ( preg_match_all( '/\bin (\S+?\.php) on line (\d+)/', $rest, $loc ) ) {
			$n    = count( $loc[0] ) - 1;
			$file = (string) $loc[1][ $n ];
			$line = (int) $loc[2][ $n ];
		} elseif ( preg_match( '/\bin (\S+?\.php):(\d+)/', $first, $loc ) ) {
			$file = (string) $loc[1];
			$line = (int) $loc[2];
		}
		$message = trim( (string) preg_replace( '/\s+in \S+?\.php(?: on line \d+|:\d+)\s*$/', '', $first ) );
		return array(
			'message' => substr( '' !== $message ? $message : $first, 0, 2000 ),
			'file'    => $file,
			'line'    => $line,
		);
	}

	/**
	 * The last fatal error the PHP error log of this server recorded since a
	 * moment: for a fatal error before WordPress's report, the one place that
	 * names file and line where the site does not display errors. The log is
	 * the one PHP writes in this request (error_log; WP_DEBUG_LOG points it
	 * at wp-content/debug.log), read from its last 64 KB; an entry without a
	 * time PHP's format gives is not used.
	 *
	 * @param float $since Unix time.
	 * @return array|null|false { message, file, line }; null where the log has
	 *                          none since then; false where no log can be read.
	 */
	public static function logged_fatal( $since ) {
		$path = (string) ini_get( 'error_log' );
		if ( '' === $path || 'syslog' === $path || ! @is_readable( $path ) || ! @is_file( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir may forbid asking.
			return false;
		}
		$size = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$fh   = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			return false;
		}
		fseek( $fh, max( 0, $size - 65536 ) );
		$tail = (string) stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$found = null;
		foreach ( preg_split( '/^(?=\[[^\]\n]+\] )/m', $tail ) as $entry ) {
			if ( ! preg_match( '/^\[([^\]\n]+)\] PHP (?:Fatal|Parse) error:/', $entry, $m ) ) {
				continue;
			}
			$when = strtotime( $m[1] );
			if ( false === $when || $when < (int) floor( (float) $since ) - 1 ) {
				continue;
			}
			$error = self::php_error_in( $entry );
			if ( null !== $error ) {
				$found = $error;
			}
		}
		return $found;
	}

	/**
	 * An answer without a fatal error: did the target run through?
	 *
	 * @param array  $res    Answer.
	 * @param int    $status HTTP status.
	 * @param string $body   Body.
	 * @param string $key    The test key.
	 * @param string $target Target name.
	 * @return array Row fields.
	 */
	private static function ran_through( $res, $status, $body, $key, $target ) {
		if ( 'mcp' === $target ) {
			// What this endpoint printed before WordPress's report.
			$page = substr( $body, 0, (int) strrpos( $body, "###### wp_scraping_result_start:$key ######" ) );
			$json = json_decode( trim( $page ), true );
			$code = ( is_array( $json ) && isset( $json['code'] ) && is_scalar( $json['code'] ) ) ? (string) $json['code'] : '';
			if ( 401 === $status && 'ab_mcp_unauthorized' === $code ) {
				return array(
					'result'      => 'ok',
					'http_status' => $status,
				);
			}
			return array(
				'result'      => 'stopped',
				'http_status' => $status,
				'code'        => $code,
				'reason'      => sprintf(
					/* translators: 1: HTTP status, 2: error code or "no JSON" */
					__( 'answered HTTP %1$d (%2$s) instead of its own 401 to a request without a token: something ends requests before AlphaBridge checks the token, which cuts the connection', 'alphabridge-mcp' ),
					$status,
					'' !== $code ? $code : __( 'no error code', 'alphabridge-mcp' )
				),
			);
		}
		if ( $status >= 300 && $status <= 399 ) {
			$to = wp_remote_retrieve_header( $res, 'location' );
			$to = is_array( $to ) ? (string) reset( $to ) : (string) $to;
			if ( 'admin' === $target && false !== strpos( $to, 'wp-login.php' ) ) {
				// The session was not accepted there: says nothing about the
				// code, so run() leaves this target out of the round.
				return array(
					'result'        => 'not_probed',
					'login_refused' => true,
					'http_status'   => $status,
					'reason'        => __( 'the dashboard sent the request to the login page, so its login was not accepted (a security plugin that ties a login to a browser or an IP address, or cookies that do not reach wp-admin)', 'alphabridge-mcp' ),
				);
			}
			return array(
				'result'      => 'stopped',
				'http_status' => $status,
				'reason'      => sprintf(
					/* translators: 1: HTTP status, 2: where to */
					__( 'redirected (HTTP %1$d to %2$s), so the code after the redirect did not run', 'alphabridge-mcp' ),
					$status,
					'' !== $to ? remove_query_arg( array( 'wp_scrape_key', 'wp_scrape_nonce' ), $to ) : '?'
				),
			);
		}
		if ( 'admin' === $target && $status >= 400 && $status <= 499 ) {
			// The dashboard refused this account (wp_die() with 403 in
			// admin_init, a check of its rights or IP address): its page
			// did not run, and wp-admin may not open any more (Codex,
			// 06.10.2026: it counted as run through).
			return array(
				'result'      => 'stopped',
				'http_status' => $status,
				/* translators: %d: HTTP status */
				'reason'      => sprintf( __( 'answered HTTP %d: the account of this connection was refused before the dashboard ran (wp_die() or a check of its rights or IP address), so make sure wp-admin still opens', 'alphabridge-mcp' ), $status ),
			);
		}
		if ( $status >= 500 ) {
			return array(
				'result'      => 'stopped',
				'http_status' => $status,
				/* translators: %d: HTTP status */
				'reason'      => sprintf( __( 'answered HTTP %d without a fatal error (wp_die() or a maintenance page), so the page did not run through', 'alphabridge-mcp' ), $status ),
			);
		}
		return array(
			'result'      => 'ok',
			'http_status' => $status,
		);
	}

	/**
	 * What WordPress printed between the markers of this key, or null when
	 * they are not in the body. The last start marker counts: core prints its
	 * report at shutdown, after everything the page printed.
	 *
	 * @param string $body Response body.
	 * @param string $key  The test key.
	 * @return string|null
	 */
	public static function between_markers( $body, $key ) {
		$start = "###### wp_scraping_result_start:$key ######";
		$end   = "###### wp_scraping_result_end:$key ######";
		$pos   = strrpos( $body, $start );
		if ( false === $pos ) {
			return null;
		}
		$rest = substr( $body, $pos + strlen( $start ) );
		$stop = strpos( $rest, $end );
		return ( false === $stop ) ? null : substr( $rest, 0, $stop );
	}

	/**
	 * A verdict for a probe that could not send a single request.
	 *
	 * @param array  $targets name => URL.
	 * @param string $why     Reason.
	 * @return array
	 */
	/**
	 * The report of a round that failed inside this site around its requests
	 * (a filter of home_url() that throws, while the URLs are built): every
	 * target not reached, with what was thrown. run() turns its own failures
	 * into this; a caller that builds part of a round itself uses it too.
	 *
	 * @param string[]   $names Targets of the round.
	 * @param \Throwable $e     What was thrown.
	 * @return array Report.
	 */
	public static function failed_round( array $names, \Throwable $e ) {
		return self::none_reached( array_fill_keys( array_map( 'strval', $names ), '' ), self::failed_inside( $e ) );
	}

	/**
	 * A report in which no target was reached, each for the same reason.
	 *
	 * @param array  $targets name => URL.
	 * @param string $why     Reason.
	 * @return array Report.
	 */
	private static function none_reached( array $targets, $why ) {
		$rows = array();
		foreach ( $targets as $name => $url ) {
			$rows[] = array(
				'target' => $name,
				'url'    => $url,
				'result' => 'unreachable',
				'reason' => $why,
			);
		}
		return array(
			'result'      => 'unreachable',
			'targets'     => $rows,
			'not_checked' => self::NOT_CHECKED,
		);
	}

	/**
	 * The reason for a request that threw inside this site.
	 *
	 * @param \Throwable $e What was thrown.
	 * @return string
	 */
	private static function failed_inside( \Throwable $e ) {
		return sprintf(
			/* translators: %s: class and message of what was thrown */
			__( 'the test request failed inside this site, before it was sent or while its answer was read (%s)', 'alphabridge-mcp' ),
			get_class( $e ) . ': ' . substr( $e->getMessage(), 0, 300 )
		);
	}

	/**
	 * How a target is named in a message.
	 *
	 * @param string $name Target name.
	 * @return string
	 */
	public static function label( $name ) {
		$labels = array(
			'home'       => __( 'the home page', 'alphabridge-mcp' ),
			'mcp'        => __( 'this MCP endpoint', 'alphabridge-mcp' ),
			'admin_post' => __( 'wp-admin/admin-post.php (admin_init)', 'alphabridge-mcp' ),
			'admin'      => __( 'the dashboard in wp-admin (logged in)', 'alphabridge-mcp' ),
		);
		return isset( $labels[ $name ] ) ? $labels[ $name ] : (string) $name;
	}
}
