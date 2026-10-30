<?php
/**
 * The test requests of the update and activation tools (AB_MCP_Php_Probe),
 * free since 4.11.0. Taken over from Pro's test/php-probe-test.php, where
 * they also run after a PHP file is written with Pro's file tools; those
 * tools and their cases (writing, putting the previous file back, the undo
 * of a file, allow_without_probe, their descriptions and readme) stay in Pro.
 * The test requests go to a stand-in that does what core does with them
 * (wp_start_scraping_edited_file_errors(), wp-includes/load.php) and "loads"
 * the PHP files of the active plugins and themes, failing, redirecting or
 * hanging where a file carries one of the markers below.
 *
 * Proves:
 *  - the answer of one test request is judged by the markers and by what the
 *    target gives when it runs through: reached and ok (this MCP endpoint
 *    only with its own 401), reached with a fatal error (also an unreadable
 *    report), reached but ended early ("stopped": a redirect, wp_die(), any
 *    other answer of this MCP endpoint), not reached (network error, no
 *    markers, markers of another key, a key WordPress did not accept) (B);
 *  - the requests take no redirects through the HTTP API, send no cookies,
 *    follow https_local_ssl_verify, and the filter cannot change any of
 *    that; asked for the state, every request carries ab-mcp-state (C);
 *  - the home page follows at most two redirects, only on this site's own
 *    host, and a redirect that reports a fatal error is judged as it is (J);
 *  - OPcache: an entry is cleared where it can be, "not_possible" where the
 *    host keeps this request from it, "not_active" where a file cache checks
 *    file times (K, child processes);
 *  - the dashboard is asked logged in, with a session of its own that ends
 *    with the round; a login the site refuses leaves it out and named (L,
 *    child process).
 *
 * Run:  php tests/standalone-lifecycle/php-probe-test.php
 */

error_reporting( E_ALL );

$ab_mode = isset( $argv[1] ) ? (string) $argv[1] : 'main';

/* -------------------------------------------- a session held by this request */

// Started before anything is printed (CLI sends "headers" with the first
// output): the probe must close it before it asks the site.
if ( 'main' === $ab_mode ) {
	$GLOBALS['ab_session_dir'] = sys_get_temp_dir() . '/ab-probe-sess-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
	mkdir( $GLOBALS['ab_session_dir'], 0700 );
	ini_set( 'session.save_path', $GLOBALS['ab_session_dir'] );
	ini_set( 'session.use_cookies', '0' );
	ini_set( 'session.cache_limiter', '' );
	$GLOBALS['ab_session_started'] = @session_start();
}

/* ---------------------------------------------------------------- the tree */

$GLOBALS['ab_root'] = sys_get_temp_dir() . '/ab-probe-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
function ab_rm_tree( $dir ) {
	if ( 0 !== strpos( (string) $dir, sys_get_temp_dir() . '/ab-probe-' ) || ! is_dir( $dir ) || is_link( $dir ) ) {
		return;
	}
	@chmod( $dir, 0755 );
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
	foreach ( $it as $f ) {
		if ( ! $f->isLink() ) {
			@chmod( $f->getPathname(), 0755 );
		}
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		( $f->isDir() && ! $f->isLink() ) ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
	}
	@rmdir( $dir );
}
register_shutdown_function(
	static function () {
		ab_rm_tree( $GLOBALS['ab_root'] );
		if ( isset( $GLOBALS['ab_session_dir'] ) ) {
			if ( PHP_SESSION_ACTIVE === session_status() ) {
				session_write_close();
			}
			ab_rm_tree( $GLOBALS['ab_session_dir'] );
		}
	}
);

$root = $GLOBALS['ab_root'];
foreach ( array( 'wp/wp-content/plugins/hello', 'wp/wp-content/plugins/other', 'wp/wp-content/plugins/net', 'wp/wp-content/plugins/real-target', 'wp/wp-content/mu-plugins', 'wp/wp-content/themes/calm', 'wp/wp-content/themes/base', 'wp/wp-content/themes/spare', 'wp/wp-content/uploads' ) as $d ) {
	mkdir( "$root/$d", 0777, true );
}
file_put_contents( "$root/wp/wp-content/plugins/hello/hello.php", "<?php\n// Plugin Name: Hello\n" );
file_put_contents( "$root/wp/wp-content/plugins/other/other.php", "<?php\n// Plugin Name: Other\n" );
file_put_contents( "$root/wp/wp-content/plugins/net/net.php", "<?php\n// Plugin Name: Net\n" );
file_put_contents( "$root/wp/wp-content/plugins/real-target/real-target.php", "<?php\n// Plugin Name: Linked\n" );
file_put_contents( "$root/wp/wp-content/plugins/solo.php", "<?php\n// Plugin Name: Solo\n" );
file_put_contents( "$root/wp/wp-content/themes/calm/functions.php", "<?php\n// child theme\n" );
file_put_contents( "$root/wp/wp-content/themes/base/functions.php", "<?php\n// parent theme\n" );
file_put_contents( "$root/wp/wp-content/themes/spare/functions.php", "<?php\n// not active\n" );
// The plugin folder "linked" is a symlink; WordPress lists it as active under
// its own name, the tool is called with the target's name.
symlink( "$root/wp/wp-content/plugins/real-target", "$root/wp/wp-content/plugins/linked" );

define( 'ABSPATH', "$root/wp/" );
define( 'WP_CONTENT_DIR', "$root/wp/wp-content" );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
define( 'WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins' );
define( 'AB_MCP_DIR', "$root/elsewhere/free/" );
define( 'AB_MCP_REST_NAMESPACE', 'alphabridge/v1' );
define( 'AB_MCP_REST_ROUTE', '/mcp' );
define( 'KB_IN_BYTES', 1024 );
define( 'MB_IN_BYTES', 1048576 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

/* --------------------------------------------------------------- the stubs */

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_messages() { return array( $this->message ); }
	public function get_error_data( $code = '' ) { return $this->data; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
$GLOBALS['ab_filters'] = array(
	// php -l is covered by php-write-safety-test.php; off here, so every
	// write below is about the test requests only.
	'ab_mcp_php_cli_binary' => false,
	'ab_mcp_undo_enabled'   => true,
);
function apply_filters( $tag, $value, ...$args ) { return array_key_exists( $tag, $GLOBALS['ab_filters'] ) ? $GLOBALS['ab_filters'][ $tag ] : $value; }
function has_filter( $tag, $cb = false ) { return array_key_exists( $tag, $GLOBALS['ab_filters'] ); }
function size_format( $bytes, $decimals = 0 ) { return number_format( $bytes / 1024, $decimals ) . ' KB'; }
function number_format_i18n( $n, $d = 0 ) { return number_format( $n, $d ); }
function trailingslashit( $p ) { return rtrim( $p, '/\\' ) . '/'; }
function wp_mkdir_p( $p ) { return is_dir( $p ) || @mkdir( $p, 0777, true ); }
function wp_json_encode( $v, $o = 0, $d = 512 ) { return json_encode( $v, $o, $d ); }
function wp_upload_dir() { return array( 'basedir' => WP_CONTENT_DIR . '/uploads', 'baseurl' => 'http://site.test/u', 'error' => false ); }
function wp_delete_file( $f ) { @unlink( $f ); }
function wp_salt( $s = 'auth' ) { return 'salt'; }
function wp_rand( $a = 0, $b = 0 ) { return random_int( $a, $b ); }
function wp_cache_delete( $k, $g = '' ) { return true; }
function get_current_user_id() { return 1; }
function current_user_can( $cap, ...$a ) { return true; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function wp_convert_hr_to_bytes( $value ) {
	$value = strtolower( trim( $value ) );
	$bytes = (int) $value;
	if ( false !== strpos( $value, 'g' ) ) { $bytes *= 1073741824; } elseif ( false !== strpos( $value, 'm' ) ) { $bytes *= 1048576; } elseif ( false !== strpos( $value, 'k' ) ) { $bytes *= 1024; }
	return min( $bytes, PHP_INT_MAX );
}
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

/* options, network options, transients */
$GLOBALS['ab_opts']      = array( 'active_plugins' => array( 'hello/hello.php', 'linked/real-target.php', 'solo.php' ) );
$GLOBALS['ab_site_opts'] = array( 'active_sitewide_plugins' => array( 'net/net.php' => time() ) );
$GLOBALS['ab_multisite'] = false;
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['ab_opts'] ) ? $GLOBALS['ab_opts'][ $k ] : $d; }
function add_option( $k, $v, $x = '', $a = 'yes' ) { if ( array_key_exists( $k, $GLOBALS['ab_opts'] ) ) { return false; } $GLOBALS['ab_opts'][ $k ] = $v; return true; }
function update_option( $k, $v, $a = null ) { $GLOBALS['ab_opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['ab_opts'][ $k ] ); return true; }
function is_multisite() { return $GLOBALS['ab_multisite']; }
function get_site_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['ab_site_opts'] ) ? $GLOBALS['ab_site_opts'][ $k ] : $d; }
$GLOBALS['ab_transients']    = array();
$GLOBALS['ab_transient_ttl'] = array();
$GLOBALS['ab_transient_fail'] = false;
function set_transient( $k, $v, $ttl = 0 ) {
	if ( $GLOBALS['ab_transient_fail'] ) { return false; }
	$GLOBALS['ab_transients'][ $k ]    = $v;
	$GLOBALS['ab_transient_ttl'][ $k ] = $ttl;
	return true;
}
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['ab_transients'] ) ? $GLOBALS['ab_transients'][ $k ] : false; }
function delete_transient( $k ) { unset( $GLOBALS['ab_transients'][ $k ] ); return true; }

/* URLs */
$GLOBALS['ab_throw_home_url'] = false;
function home_url( $p = '' ) {
	if ( $GLOBALS['ab_throw_home_url'] ) { throw new LogicException( 'home_url filter of another plugin failed' ); }
	return 'http://site.test' . ( '' === $p ? '' : '/' . ltrim( $p, '/' ) );
}
function rest_url( $p = '' ) { return 'http://site.test/wp-json/' . ltrim( $p, '/' ); }
function admin_url( $p = '' ) { return 'http://site.test/wp-admin/' . ltrim( $p, '/' ); }
function add_query_arg( array $args, $url ) {
	$parts = explode( '?', $url, 2 );
	$q     = array();
	if ( isset( $parts[1] ) ) { parse_str( $parts[1], $q ); }
	return $parts[0] . '?' . http_build_query( array_merge( $q, $args ) );
}
function remove_query_arg( $keys, $url ) {
	$parts = explode( '?', $url, 2 );
	if ( ! isset( $parts[1] ) ) { return $url; }
	parse_str( $parts[1], $q );
	foreach ( (array) $keys as $k ) { unset( $q[ $k ] ); }
	return $parts[0] . ( $q ? '?' . http_build_query( $q ) : '' );
}
/* core's WP_Http::make_absolute_url(), for the cases the site below gives */
class WP_Http {
	public static function make_absolute_url( $maybe_relative, $url ) {
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $maybe_relative ) ) { return $maybe_relative; }
		$u    = parse_url( $url );
		$base = $u['scheme'] . '://' . $u['host'] . ( isset( $u['port'] ) ? ':' . $u['port'] : '' );
		if ( '?' === substr( $maybe_relative, 0, 1 ) ) { return $base . ( $u['path'] ?? '/' ) . $maybe_relative; }
		return $base . '/' . ltrim( $maybe_relative, '/' );
	}
}

/* sequence of events: OPcache calls and requests, in order */
$GLOBALS['ab_seq']     = 0;
$GLOBALS['ab_opcache'] = array();
function wp_opcache_invalidate( $filepath, $force = false ) {
	$GLOBALS['ab_opcache'][] = array( 'path' => $filepath, 'seq' => ++$GLOBALS['ab_seq'] );
	// In the children, as on a host with opcache.restrict_api, or with only
	// the file cache (Playground): refused.
	return 0 === strpos( $GLOBALS['ab_mode'], 'opcache_' ) ? false : file_exists( $filepath );
}

/* themes: calm is the active (child) theme, base its parent */
$GLOBALS['ab_themes']     = array( 'calm' => WP_CONTENT_DIR . '/themes/calm', 'base' => WP_CONTENT_DIR . '/themes/base', 'spare' => WP_CONTENT_DIR . '/themes/spare' );
$GLOBALS['ab_stylesheet'] = 'calm';
$GLOBALS['ab_template']   = 'base';
class AB_Test_Theme {
	public $slug;
	public function __construct( $s ) { $this->slug = (string) $s; }
	public function exists() { return isset( $GLOBALS['ab_themes'][ $this->slug ] ); }
	public function get_stylesheet_directory() { return $GLOBALS['ab_themes'][ $this->slug ]; }
	public function get_theme_root() { return dirname( $GLOBALS['ab_themes'][ $this->slug ] ); }
}
function wp_get_theme( $s = null ) { return new AB_Test_Theme( null === $s ? $GLOBALS['ab_stylesheet'] : $s ); }
function get_stylesheet_directory() { return $GLOBALS['ab_themes'][ $GLOBALS['ab_stylesheet'] ]; }
function get_template_directory() { return $GLOBALS['ab_themes'][ $GLOBALS['ab_template'] ]; }
function get_stylesheet() { return $GLOBALS['ab_stylesheet']; }
/* The theme file tools take a name only from the list WordPress keeps
 * (wp_get_themes() with errors => null) and only under a registered theme
 * directory; every theme above lies in wp-content/themes. A theme whose
 * folder is gone is not listed. test/theme-jail-test.php models the scan. */
$GLOBALS['wp_theme_directories'] = array( WP_CONTENT_DIR . '/themes' );
function wp_get_themes( $args = array() ) {
	$out = array();
	foreach ( $GLOBALS['ab_themes'] as $slug => $dir ) {
		if ( is_dir( $dir ) ) {
			$out[ $slug ] = new AB_Test_Theme( $slug );
		}
	}
	return $out;
}
function wp_clean_themes_cache( $clear_update_cache = true ) {}

/* ------------------------------------------------- the site being requested */

/*
 * $GLOBALS['ab_net'][target] says how a target answers, whatever the files
 * say (the site, not the code):
 *   'wp'      WordPress answers (default), with the scrape mechanism of core
 *   'down'    the connection fails (WP_Error, as WP_Http gives it)
 *   'cache'   something in front of WordPress answers 200 without markers
 *   'garbled' WordPress answers, but nothing readable between the markers
 *   'guest401' (mcp) WordPress answers a request without a token with 401
 *             invalid_username, as with an HTTP login on a staging site
 * $GLOBALS['ab_on_request'] runs inside a request (to change the disk, or
 * to throw like another plugin's HTTP filter).
 */
$GLOBALS['ab_net']        = array();
$GLOBALS['ab_requests']   = array();
$GLOBALS['ab_on_request'] = null;
function ab_target_of( $url ) {
	$path = (string) parse_url( $url, PHP_URL_PATH );
	if ( '/wp-json/alphabridge/v1/mcp' === $path ) { return 'mcp'; }
	if ( '/wp-admin/admin-post.php' === $path ) { return 'admin_post'; }
	if ( '/wp-login.php' === $path ) { return 'login'; }
	if ( '/wp-admin/index.php' === $path ) { return 'admin'; }
	return '/' === $path ? 'home' : 'other';
}
/* The PHP files the stand-in site loads: those of the active plugins (in a
 * folder) and of the active theme and its parent. */
function ab_loaded_files() {
	$dirs = array();
	$active = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}
	foreach ( $active as $p ) {
		if ( false !== strpos( $p, '/' ) ) {
			$dirs[] = WP_PLUGIN_DIR . '/' . dirname( $p );
		}
	}
	$dirs[] = get_stylesheet_directory();
	$dirs[] = get_template_directory();
	$files  = array();
	foreach ( array_unique( $dirs ) as $d ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $d, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( 'php' === strtolower( $f->getExtension() ) ) {
				$files[] = $f->getPathname();
			}
		}
	}
	sort( $files );
	return $files;
}
/* What breaks where: a marker in a loaded file and the targets it stops. */
$GLOBALS['ab_fatal_markers'] = array(
	'AB_FATAL_LOAD'       => array( 'home', 'mcp', 'admin_post', 'login', 'admin' ),
	'AB_FATAL_FRONT'      => array( 'home' ),
	'AB_FATAL_REST'       => array( 'mcp' ),
	'AB_FATAL_ADMIN_INIT' => array( 'admin_post', 'admin' ),
	// admin_menu only runs inside wp-admin/admin.php: the logged-in dashboard.
	'AB_FATAL_ADMIN_MENU' => array( 'admin' ),
	// Only on the dashboard (a dashboard widget).
	'AB_FATAL_ADMIN'      => array( 'admin' ),
	// A function renamed here: PHP reports the error in its caller, hello.php.
	'AB_FATAL_ELSEWHERE'  => array( 'home', 'mcp', 'admin_post', 'login', 'admin' ),
	// A message that does not carry the line, like "Cannot redeclare".
	'AB_FATAL_NOLINE'     => array( 'home' ),
	'AB_FATAL_AFTER_REDIRECT' => array( 'home' ),
);
/* Markers that end a request early without a fatal error, or hang it. */
function ab_marked( $marker ) {
	foreach ( ab_loaded_files() as $file ) {
		if ( false !== strpos( (string) file_get_contents( $file ), $marker ) ) {
			return true;
		}
	}
	return false;
}
/* The session of the dashboard request: one this site holds, and a login the
 * site accepts ($GLOBALS['ab_login_ok'] false: bound to the browser, say). */
function ab_admin_login_ok( $args ) {
	if ( ! defined( 'LOGGED_IN_COOKIE' ) || empty( $GLOBALS['ab_login_ok'] ) || ! isset( $args['cookies'][ LOGGED_IN_COOKIE ] ) ) {
		return false;
	}
	$c = explode( '|', (string) $args['cookies'][ LOGGED_IN_COOKIE ] );
	return 4 === count( $c ) && isset( $GLOBALS['ab_sessions'][ $c[3] ] );
}
function ab_timeout() { return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 10000 milliseconds with 0 bytes received' ); }
function wp_remote_get( $url, $args = array() ) {
	$target = ab_target_of( $url );
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
	$GLOBALS['ab_requests'][] = array(
		'target'           => $target,
		'url'              => $url,
		'host'             => (string) parse_url( $url, PHP_URL_HOST ),
		'args'             => $args,
		'seq'              => ++$GLOBALS['ab_seq'],
		'transient_at_run' => isset( $q['wp_scrape_key'] ) ? get_transient( 'scrape_key_' . $q['wp_scrape_key'] ) : false,
		'query'            => $q,
		'session'          => session_status(),
	);
	if ( is_callable( $GLOBALS['ab_on_request'] ) ) {
		call_user_func( $GLOBALS['ab_on_request'], $target );
	}
	$mode = isset( $GLOBALS['ab_net'][ $target ] ) ? $GLOBALS['ab_net'][ $target ] : 'wp';
	if ( 'down' === $mode ) {
		return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to site.test port 80' );
	}
	if ( 'cache' === $mode ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => '<html>cached page</html>' );
	}
	if ( ab_marked( 'AB_HANG_ALL' ) || ( 'home' === $target && ab_marked( 'AB_HANG_FRONT' ) ) ) {
		return ab_timeout();
	}
	$page    = ( 'mcp' === $target ) ? '{"code":"ab_mcp_unauthorized","message":"Invalid or missing bearer token.","data":{"status":401}}' : '<html>page</html>';
	$code    = ( 'mcp' === $target ) ? 401 : 200;
	$headers = array();
	if ( 'mcp' === $target && 'guest401' === $mode ) {
		$page = '{"code":"invalid_username","message":"Unknown username.","data":{"status":401}}';
	}
	// Code that ends the answer itself before WordPress has finished
	// (fastcgi_finish_request(), exit in the shutdown action): the target
	// answers, without core's report; AB_ENDS_FRONT before the page, which
	// stays blank.
	if ( isset( $q['wp_scrape_key'] ) && ( ab_marked( 'AB_ENDS_ALL' ) || ( 'home' === $target && ab_marked( 'AB_ENDS_FRONT' ) ) ) ) {
		return array( 'response' => array( 'code' => $code ), 'headers' => array(), 'body' => ab_marked( 'AB_ENDS_ALL' ) ? $page : '' );
	}
	// A fatal error while code loads that WordPress loads before it starts
	// its report (a must-use or network-active plugin): its error page as
	// WordPress 7.1.2 answered (support/probe-early/), without the markers,
	// and the entry PHP writes where it keeps an error log.
	if ( isset( $q['wp_scrape_key'] ) && ab_marked( 'AB_EARLY_FATAL' ) ) {
		if ( ! empty( $GLOBALS['ab_error_log'] ) ) {
			file_put_contents( $GLOBALS['ab_error_log'], '[' . gmdate( 'd-M-Y H:i:s' ) . " UTC] PHP Fatal error:  Uncaught Error: Call to undefined function ab_early() in /srv/wp-content/mu-plugins/early.php:2\nStack trace:\n#0 /srv/wp-settings.php(508): include_once()\n#1 {main}\n  thrown in /srv/wp-content/mu-plugins/early.php on line 2\n", FILE_APPEND );
		}
		return array( 'response' => array( 'code' => 500 ), 'headers' => array(), 'body' => (string) file_get_contents( __DIR__ . '/../standalone-lib/support/probe-early/' . ( 'mcp' === $target ? 'error-json.txt' : 'error-page.html' ) ) );
	}
	// Code that ends the request early, before the target runs through.
	// The dashboard without a session WordPress accepts: auth_redirect().
	if ( 'admin' === $target && ! ab_admin_login_ok( $args ) ) {
		$code    = 302;
		$page    = '';
		$headers = array( 'location' => 'http://site.test/wp-login.php?redirect_to=http%3A%2F%2Fsite.test%2Fwp-admin%2F&reauth=1' );
	} elseif ( ab_marked( 'AB_MAINTENANCE' ) ) {
		$code = 500;
		$page = ( 'mcp' === $target ) ? '{"code":"wp_die","message":"Maintenance","data":{"status":500}}' : '<html>Maintenance</html>';
	} elseif ( ab_marked( 'AB_LOGIN_WALL' ) && 'login' !== $target ) {
		$code    = 302;
		$page    = '';
		$headers = array( 'location' => 'http://site.test/wp-login.php?redirect_to=x' );
	} elseif ( 'admin' === $target && ab_marked( 'AB_DENY_ADMIN' ) ) {
		// wp_die( ..., 403 ) in admin_init: WordPress still prints its report.
		$code = 403;
		$page = '<html>Sorry, you are not allowed to access this page.</html>';
	} elseif ( 'mcp' === $target && ab_marked( 'AB_CLOSE_REST' ) ) {
		$page = '{"code":"rest_not_logged_in","message":"You are not currently logged in.","data":{"status":401}}';
	} elseif ( 'home' === $target && ab_marked( 'AB_FRONT_OFFSITE' ) ) {
		$code    = 302;
		$page    = '';
		$headers = array( 'location' => 'https://elsewhere.test/landing?from=site' );
	} elseif ( 'home' === $target && ab_marked( 'AB_FRONT_DIE' ) ) {
		$code = 503;
		$page = '<html>Back soon</html>';
	} elseif ( 'home' === $target && ab_marked( 'AB_REDIRECT_LOOP' ) ) {
		$code    = 302;
		$page    = '';
		$headers = array( 'location' => '/?hop=' . ( isset( $q['hop'] ) ? (int) $q['hop'] + 1 : 1 ) );
	} elseif ( 'home' === $target && ab_marked( 'AB_FATAL_AFTER_REDIRECT' ) ) {
		// A redirect, and a fatal error in a shutdown hook after it.
		$code    = 302;
		$page    = '';
		$headers = array( 'location' => '/?after=1' );
	} elseif ( 'home' === $target && ab_marked( 'AB_LANG_REDIRECT' ) && ! isset( $q['lang'] ) ) {
		// A language plugin: "/" goes on to the visitor's language, keeping
		// the query (as redirect_canonical does), before any template runs.
		$code    = 302;
		$page    = '';
		$headers = array( 'location' => '/?' . http_build_query( $q + array( 'lang' => 'de' ) ) );
	}
	// Core: wp_start_scraping_edited_file_errors().
	if ( ! isset( $q['wp_scrape_key'], $q['wp_scrape_nonce'] ) ) {
		return array( 'response' => array( 'code' => $code ), 'headers' => $headers, 'body' => $page );
	}
	$key   = substr( sanitize_key( $q['wp_scrape_key'] ), 0, 32 );
	$saved = get_transient( 'scrape_key_' . $key );
	if ( false === $saved ) {
		return array( 'response' => array( 'code' => $code ), 'headers' => $headers, 'body' => $page );
	}
	if ( $saved !== $q['wp_scrape_nonce'] ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => "###### wp_scraping_result_start:$key ######" . json_encode( array( 'code' => 'scrape_nonce_failure', 'message' => 'Scrape key check failed. Please try again.' ) ) . "###### wp_scraping_result_end:$key ######" );
	}
	// The site loads its active code; the first marker that stops this target
	// wins. A redirect ends the request before the templates run, so a
	// front-end marker only counts on a page that is rendered.
	$error = null;
	$hit   = '';
	foreach ( ab_loaded_files() as $file ) {
		$src = (string) file_get_contents( $file );
		foreach ( $GLOBALS['ab_fatal_markers'] as $marker => $targets ) {
			$pos = strpos( $src, $marker );
			if ( false === $pos || ! in_array( $target, $targets, true ) || ( ! in_array( $marker, array( 'AB_FATAL_LOAD', 'AB_FATAL_ELSEWHERE', 'AB_FATAL_AFTER_REDIRECT' ), true ) && 302 === $code ) ) {
				continue;
			}
			$rel  = str_replace( ABSPATH, '', $file );
			$line = substr_count( substr( $src, 0, $pos ), "\n" ) + 1;
			if ( 'AB_FATAL_ELSEWHERE' === $marker ) {
				$rel  = 'wp-content/plugins/hello/hello.php';
				$line = 2;
			}
			$text  = ( 'AB_FATAL_NOLINE' === $marker ) ? 'Cannot redeclare function ab_dup()' : "Uncaught Error: Call to undefined function ab_missing() in $rel:$line\nStack trace:\n#0 {main}\n  thrown";
			$error = array( 'type' => 1, 'message' => $text, 'file' => $rel, 'line' => $line );
			$hit   = $marker;
			break 2;
		}
	}
	// A fatal error after the redirect was sent keeps the redirect's status.
	if ( null !== $error && 'AB_FATAL_AFTER_REDIRECT' !== $hit ) {
		$code    = 500;
		$headers = array();
		$page    = ( 'mcp' === $target ) ? '{"code":"internal_server_error","message":"There has been a critical error on this website."}' : '<html>There has been a critical error on this website.</html>';
	}
	$report = ( 'garbled' === $mode ) ? '' : json_encode( null === $error ? true : $error );
	// Core: wp_finalize_scraping_edited_file_errors(), at shutdown, after the page.
	return array(
		'response' => array( 'code' => $code ),
		'headers'  => $headers,
		'body'     => $page . "\n###### wp_scraping_result_start:$key ######\n" . $report . "\n###### wp_scraping_result_end:$key ######\n",
	);
}
function wp_remote_retrieve_body( $r ) { return is_array( $r ) && isset( $r['body'] ) ? (string) $r['body'] : ''; }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) && isset( $r['response']['code'] ) ? (int) $r['response']['code'] : ''; }
function wp_remote_retrieve_header( $r, $h ) { return is_array( $r ) && isset( $r['headers'][ strtolower( $h ) ] ) ? $r['headers'][ strtolower( $h ) ] : ''; }

/* the registry, collecting what the tools register */
class AB_MCP_Tool_Registry {
	public $defs = array();
	public function register( $name, $def ) { $this->defs[ $name ] = $def; }
}

require dirname( __DIR__, 2 ) . '/includes/lifecycle/class-own-files.php';

/* ---------------------------------------------------------------- helpers */

$fails = 0;
$count = 0;
function ok( $c, $label ) {
	global $fails, $count;
	$count++;
	if ( ! $c ) { $fails++; echo "  FAIL: $label\n"; }
}
function code_of( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : '(no error)'; }
function msg_of( $r ) { return is_wp_error( $r ) ? $r->get_error_message() : ''; }
function data_of( $r ) { return is_wp_error( $r ) ? (array) $r->get_error_data() : array(); }
function warn_of( $r ) { return is_array( $r ) && isset( $r['warning'] ) ? (string) $r['warning'] : ''; }
function hello( $file ) { return WP_PLUGIN_DIR . '/hello/' . $file; }
function fresh() { $GLOBALS['ab_requests'] = array(); $GLOBALS['ab_opcache'] = array(); $GLOBALS['ab_net'] = array(); $GLOBALS['ab_on_request'] = null; }
function asked() { return array_column( $GLOBALS['ab_requests'], 'target' ); }
function results( $report ) { return array_column( (array) $report['targets'], 'result', 'target' ); }
function previous_results( $report ) { $o = array(); foreach ( (array) $report['targets'] as $t ) { if ( isset( $t['previous'] ) ) { $o[ $t['target'] ] = $t['previous']['result']; } } return $o; }
/** Inode of this file at every test request: a rename gives the file a new one, a write in place keeps it. */
function watch_inode( $path ) { $GLOBALS['ab_seen_ino'] = array(); $GLOBALS['ab_on_request'] = static function () use ( $path ) { clearstatcache(); $GLOBALS['ab_seen_ino'][] = fileinode( $path ); }; }
function inode_now( $path ) { clearstatcache(); return fileinode( $path ); }
function leftovers( $dir ) { return array_values( preg_grep( '/^\.ab-mcp-tmp-/i', (array) @scandir( $dir ) ) ); }
function last_request_seq() { $r = end( $GLOBALS['ab_requests'] ); return $r ? $r['seq'] : 0; }
function first_request_seq() { return $GLOBALS['ab_requests'] ? $GLOBALS['ab_requests'][0]['seq'] : 0; }
function opcache_seqs( $path ) {
	$out = array();
	foreach ( $GLOBALS['ab_opcache'] as $c ) {
		if ( realpath( dirname( $c['path'] ) ) . '/' . basename( $c['path'] ) === realpath( dirname( $path ) ) . '/' . basename( $path ) ) {
			$out[] = $c['seq'];
		}
	}
	return $out;
}

$OLD  = "<?php\n// version one\n";
$GOOD = "<?php\n// version two\nadd_action( 'init', function () {} );\n";

/* ================ children: OPcache on, invalidation refused */

// In Pro these wrote a file through wp_write_plugin_file and read what its
// answer says about OPcache; here the call the update guard makes after an
// update (AB_MCP_Own_Files::invalidate_opcache()) is asked directly.
if ( 'opcache_blocked' === $ab_mode ) {
	ok( function_exists( 'opcache_invalidate' ) && '1' === (string) ini_get( 'opcache.enable_cli' ), 'K0 the child runs with OPcache on in the command line' );
	file_put_contents( hello( 'inc.php' ), $GOOD );
	ok( 'not_possible' === AB_MCP_Own_Files::invalidate_opcache( hello( 'inc.php' ) ), 'K1 the invalidation refused: not_possible' );
	file_put_contents( hello( 'inc.inc' ), $GOOD );
	$GLOBALS['ab_filters']['wp_opcache_invalidate_file'] = false;
	ok( 'not_possible' === AB_MCP_Own_Files::invalidate_opcache( hello( 'inc.inc' ) ), 'K2 another extension PHP runs gets the same call, and the same filter refuses it' );
	unset( $GLOBALS['ab_filters']['wp_opcache_invalidate_file'] );
	echo "CHILD $count $fails\n";
	exit( 0 === $fails ? 0 : 1 );
}
if ( 'opcache_filecache' === $ab_mode || 'opcache_filecache_stale' === $ab_mode ) {
	$st = opcache_get_status( false );
	ok( is_array( $st ) && empty( $st['opcache_enabled'] ) && '1' === (string) ini_get( 'opcache.file_cache_only' ), 'K5 the child runs OPcache with its file cache only, as WordPress Playground does' );
	file_put_contents( hello( 'inc.php' ), $GOOD );
	$res = AB_MCP_Own_Files::invalidate_opcache( hello( 'inc.php' ) );
	if ( 'opcache_filecache' === $ab_mode ) {
		ok( 'not_active' === $res, 'K6 a file cache that checks file times holds nothing to clear: not_active (' . $res . ')' );
	} else {
		ok( 'not_possible' === $res, 'K7 a file cache that does not check file times keeps the old code: not_possible (' . $res . ')' );
	}
	echo "CHILD $count $fails\n";
	exit( 0 === $fails ? 0 : 1 );
}


/* ============== child: the dashboard, logged in with a session of its own */

if ( 'logged_in' === $ab_mode ) {
	define( 'AUTH_COOKIE', 'wordpress_x' );
	define( 'SECURE_AUTH_COOKIE', 'wordpress_sec_x' );
	define( 'LOGGED_IN_COOKIE', 'wordpress_logged_in_x' );
	/** WordPress's sessions, in memory: token => [ user, expiry ]. */
	class WP_Session_Tokens {
		public static $fail = false;
		private $user = 0;
		public static function get_instance( $user ) { $o = new self(); $o->user = (int) $user; return $o; }
		public function create( $expiry ) {
			if ( self::$fail ) { return ''; }
			$t = bin2hex( random_bytes( 8 ) );
			$GLOBALS['ab_sessions'][ $t ] = array( $this->user, (int) $expiry );
			return $t;
		}
		public function destroy( $token ) { unset( $GLOBALS['ab_sessions'][ $token ] ); }
	}
	function wp_generate_auth_cookie( $user, $expiry, $scheme = 'auth', $token = '' ) { return "$scheme|$user|$expiry|$token"; }
	$GLOBALS['ab_sessions'] = array();
	$GLOBALS['ab_login_ok'] = true;
	ok( isset( AB_MCP_Php_Probe::targets()['admin'] ) && 'http://site.test/wp-admin/index.php' === AB_MCP_Php_Probe::targets()['admin'], 'L0 with an account and WordPress\'s sessions, the dashboard is a target' );

	fresh();
	$rep = AB_MCP_Php_Probe::run( array(), false );
	ok( 'passed' === $rep['result'] && array( 'home', 'mcp', 'admin_post', 'admin' ) === asked() && 'asked' === ( $rep['logged_in']['result'] ?? '' ), 'L1 four targets, the dashboard last, logged in; all passed' );
	$adm = array_values( array_filter( $GLOBALS['ab_requests'], static function ( $q ) { return 'admin' === $q['target']; } ) );
	$ck  = $adm[0]['args']['cookies'] ?? array();
	ok( 3 === count( $ck ) && isset( $ck[ AUTH_COOKIE ], $ck[ SECURE_AUTH_COOKIE ], $ck[ LOGGED_IN_COOKIE ] ) && 0 === strpos( $ck[ LOGGED_IN_COOKIE ], 'logged_in|1|' ), 'L1 the dashboard request carries the three cookies of a session of this account' );
	$others = array_filter( $GLOBALS['ab_requests'], static function ( $q ) { return 'admin' !== $q['target']; } );
	ok( array() === array_filter( array_map( static function ( $q ) { return $q['args']['cookies'] ?? array(); }, $others ) ), 'L1 the other requests carry none' );
	ok( array() === $GLOBALS['ab_sessions'], 'L1 the session is destroyed after the round' );
	$parts = explode( '|', (string) ( $ck[ LOGGED_IN_COOKIE ] ?? '' ) );
	ok( 4 === count( $parts ) && (int) $parts[2] > time() && (int) $parts[2] <= time() + AB_MCP_Php_Probe::SESSION_SECONDS, 'L1 and it would have ended by itself after SESSION_SECONDS' );

	// In Pro: a PHP file written with an error only there is refused and put
	// back. Here the round itself is asked: the error is found there.
	foreach ( array( 'AB_FATAL_ADMIN' => 'a fatal error only in the dashboard (a widget)', 'AB_FATAL_ADMIN_MENU' => 'one in admin_menu, which only wp-admin runs' ) as $marker => $what ) {
		file_put_contents( hello( 'inc.php' ), "<?php\n// $marker\n" );
		fresh();
		$rep = AB_MCP_Php_Probe::run( array(), false );
		ok( 'fatal' === ( results( $rep )['admin'] ?? '' ) && 'ok' === results( $rep )['home'], "L2 $what: found at the dashboard only" );
		ok( false !== strpos( AB_MCP_Own_Files::summary( $rep['targets'] ), 'the dashboard in wp-admin (logged in): fatal error' ) && false !== strpos( AB_MCP_Own_Files::summary( $rep['targets'] ), 'wp-content/plugins/hello/inc.php on line 2' ), 'L2 the summary names the dashboard, file and line: ' . AB_MCP_Own_Files::summary( $rep['targets'] ) );
		ok( array() === $GLOBALS['ab_sessions'], 'L2 no session left behind' );
	}

	file_put_contents( hello( 'inc.php' ), $OLD );
	fresh();
	$GLOBALS['ab_login_ok'] = false;
	$rep = AB_MCP_Php_Probe::run( array(), false );
	ok( 'passed' === $rep['result'] && array( 'home', 'mcp', 'admin_post' ) === array_column( $rep['targets'], 'target' ) && 'refused' === ( $rep['logged_in']['result'] ?? '' ) && false !== strpos( (string) ( $rep['logged_in']['reason'] ?? '' ), 'sent the request to the login page' ), 'L3 a login the site does not accept (a session bound to the browser): the dashboard is left out and named, not failed' );
	ok( array() === $GLOBALS['ab_sessions'], 'L3 the session is destroyed all the same' );
	file_put_contents( hello( 'inc.php' ), "<?php\n// AB_FATAL_ADMIN\n" );
	fresh();
	$rep = AB_MCP_Php_Probe::run( array(), false );
	ok( 'passed' === $rep['result'] && ! isset( results( $rep )['admin'] ), 'L3 counter-check: then an error only there is not seen' );
	$GLOBALS['ab_login_ok'] = true;

	file_put_contents( hello( 'inc.php' ), "<?php\n// AB_DENY_ADMIN\n" );
	fresh();
	$rep = AB_MCP_Php_Probe::run( array(), false );
	$adm = array_values( array_filter( (array) $rep['targets'], static function ( $row ) { return 'admin' === $row['target']; } ) );
	ok( 1 === count( $adm ) && 'stopped' === $adm[0]['result'] && 403 === $adm[0]['http_status'] && 'partial' === $rep['result'], 'L6 code that has the dashboard refuse the account (403): the dashboard did not run through, not ok' );
	ok( false !== strpos( AB_MCP_Own_Files::summary( $rep['targets'] ), 'the dashboard in wp-admin (logged in): did not run through (answered HTTP 403' ) && false !== strpos( AB_MCP_Own_Files::summary( $rep['targets'] ), 'make sure wp-admin still opens' ), 'L6 the summary names it' );
	ok( array() === $GLOBALS['ab_sessions'], 'L6 no session left behind' );

	file_put_contents( hello( 'inc.php' ), $OLD );
	$GLOBALS['ab_filters']['ab_mcp_php_probe_logged_in'] = false;
	fresh();
	$rep = AB_MCP_Php_Probe::run( array(), false );
	ok( array( 'home', 'mcp', 'admin_post' ) === asked() && ! isset( $rep['logged_in'] ) && array() === $GLOBALS['ab_sessions'], 'L4 switched off with ab_mcp_php_probe_logged_in: three targets, no session made' );
	unset( $GLOBALS['ab_filters']['ab_mcp_php_probe_logged_in'] );

	WP_Session_Tokens::$fail = true;
	fresh();
	$rep = AB_MCP_Php_Probe::run( array(), false );
	ok( array( 'home', 'mcp', 'admin_post' ) === asked() && 'no_session' === ( $rep['logged_in']['result'] ?? '' ), 'L5 no session could be made: the dashboard is not asked, and the report says so' );
	WP_Session_Tokens::$fail = false;

	echo "CHILD $count $fails\n";
	exit( 0 === $fails ? 0 : 1 );
}

/* =================================================== B) judging one answer */

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $fn, $prio = 10, $args = 1 ) { $GLOBALS['ab_actions'][ $tag ][] = array( $fn, $prio ); return true; }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $v ) { return $v; }
}


$k = str_repeat( 'a', 32 );
$m = static function ( $inner, $key = null ) use ( $k ) {
	$key = null === $key ? $k : $key;
	return "\n###### wp_scraping_result_start:$key ######\n$inner\n###### wp_scraping_result_end:$key ######\n";
};
$j = AB_MCP_Php_Probe::judge( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ), $k );
ok( 'unreachable' === $j['result'] && false !== strpos( $j['reason'], 'timed out' ) && ! isset( $j['no_report'] ), 'B1 a network error: not reached, with the reason' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 200 ), 'body' => '<html>cached</html>' ), $k );
ok( 'unreachable' === $j['result'] && 200 === $j['http_status'] && false !== strpos( $j['reason'], 'without the test markers' ) && true === ( $j['no_report'] ?? null ), 'B2 an answer without markers: not reached, whatever its status, marked as answered without a report' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 200 ), 'body' => '<html></html>' . $m( 'true' ) ), $k );
ok( 'ok' === $j['result'], 'B3 markers around true: ok' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 401 ), 'body' => '{"code":"ab_mcp_unauthorized","message":"x"}' . $m( 'true' ) ), $k, 'mcp' );
ok( 'ok' === $j['result'] && 401 === $j['http_status'], 'B4 this MCP endpoint\'s own 401 with markers around true: ok' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 401 ), 'body' => '{"code":"rest_not_logged_in","message":"x"}' . $m( 'true' ) ), $k, 'mcp' );
ok( 'stopped' === $j['result'] && 'rest_not_logged_in' === $j['code'] && false !== strpos( $j['reason'], 'cuts the connection' ), 'B17 a 401 of another code there (a REST block for guests): stopped, with the code' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 302 ), 'headers' => array( 'location' => 'http://site.test/wp-login.php' ), 'body' => $m( 'true' ) ), $k, 'mcp' );
ok( 'stopped' === $j['result'] && 302 === $j['http_status'], 'B18 a redirect there (a login wall): stopped' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 500 ), 'body' => '{"code":"wp_die","message":"Maintenance"}' . $m( 'true' ) ), $k, 'mcp' );
ok( 'stopped' === $j['result'] && 'wp_die' === $j['code'], 'B19 wp_die() there (a maintenance mode): stopped' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 200 ), 'body' => '<html>hello</html>' . $m( 'true' ) ), $k, 'mcp' );
ok( 'stopped' === $j['result'] && '' === $j['code'], 'B20 a page instead of JSON there: stopped' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 403 ), 'body' => '{"code":"ab_mcp_unauthorized","message":"x"}' . $m( 'true' ) ), $k, 'mcp' );
ok( 'stopped' === $j['result'], 'B33 its code under another status (a proxy rewriting it): stopped' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 401 ), 'body' => '{"code":"x"}' . $m( 'true' ) ), $k );
ok( 'ok' === $j['result'], 'B21 a 401 elsewhere (a members-only page) counts as run through' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 403 ), 'body' => '<html>Sorry, you are not allowed to access this page.</html>' . $m( 'true' ) ), $k, 'admin' );
ok( 'stopped' === $j['result'] && 403 === $j['http_status'] && false !== strpos( $j['reason'], 'answered HTTP 403: the account of this connection was refused before the dashboard ran' ) && false !== strpos( $j['reason'], 'make sure wp-admin still opens' ), 'B34 the dashboard refusing the account (wp_die() with 403 in admin_init): stopped, not ok' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 401 ), 'body' => '<html>x</html>' . $m( 'true' ) ), $k, 'admin' );
ok( 'stopped' === $j['result'] && 401 === $j['http_status'], 'B35 a 401 there: stopped as well' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 404 ), 'body' => '<html>x</html>' . $m( 'true' ) ), $k, 'admin' );
ok( 'stopped' === $j['result'] && 404 === $j['http_status'], 'B36 a 404 there (wp-admin hidden): stopped as well' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 403 ), 'body' => '<html>x</html>' . $m( 'true' ) ), $k, 'home' );
ok( 'ok' === $j['result'], 'B37 counter-check: a 403 at the home page (a members-only page) still counts as run through' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 200 ), 'body' => '<html>dashboard</html>' . $m( 'true' ) ), $k, 'admin' );
ok( 'ok' === $j['result'], 'B38 counter-check: the dashboard with 200: ok' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 302 ), 'headers' => array( 'location' => "http://other.test/?wp_scrape_key=$k" ), 'body' => $m( 'true' ) ), $k, 'home' );
ok( 'stopped' === $j['result'] && false !== strpos( $j['reason'], 'HTTP 302 to http://other.test/' ) && false === strpos( $j['reason'], $k ), 'B22 a redirect at the home page: stopped, naming where to (without the key)' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 503 ), 'body' => '<html>soon</html>' . $m( 'true' ) ), $k, 'home' );
ok( 'stopped' === $j['result'] && false !== strpos( $j['reason'], 'wp_die()' ), 'B23 a 5xx without a fatal error at the home page: stopped' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 302 ), 'body' => $m( 'true' ) ), $k, 'admin_post' );
ok( 'stopped' === $j['result'], 'B24 a redirect at admin-post.php: stopped' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 404 ), 'body' => '<html>404</html>' . $m( 'true' ) ), $k, 'home' );
ok( 'ok' === $j['result'], 'B25 a 404 page is rendered: ok' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 500 ), 'body' => $m( '{"type":1,"message":"Cannot redeclare function wp_die()","file":"wp-content\/plugins\/hello\/x.php","line":3}' ) ), $k );
ok( 'fatal' === $j['result'] && 'Cannot redeclare function wp_die()' === $j['error']['message'] && 'wp-content/plugins/hello/x.php' === $j['error']['file'] && 3 === $j['error']['line'], 'B5 an error report: fatal, with message, file and line' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 302 ), 'body' => $m( '{"type":1,"message":"x","file":"f","line":1}' ) ), $k, 'mcp' );
ok( 'fatal' === $j['result'], 'B6 a redirect that carries an error report is fatal' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 200 ), 'body' => $m( '' ) ), $k );
ok( 'fatal' === $j['result'] && false !== strpos( $j['error']['message'], 'could not be read' ), 'B7 markers around nothing readable: fatal (as in WordPress\'s editor)' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 200 ), 'body' => $m( '{"code":"scrape_nonce_failure","message":"Scrape key check failed."}' ) ), $k );
ok( 'unreachable' === $j['result'] && false !== strpos( $j['reason'], 'did not accept' ) && ! isset( $j['no_report'] ), 'B8 a key WordPress did not accept: not reached, not fatal' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 200 ), 'body' => $m( 'true', str_repeat( 'b', 32 ) ) ), $k );
ok( 'unreachable' === $j['result'], 'B9 markers of another key: not reached' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 500 ), 'body' => "page says ###### wp_scraping_result_start:$k ######\ntrue\n###### wp_scraping_result_end:$k ###### and then" . $m( '{"type":64,"message":"late","file":"f","line":9}' ) ), $k );
ok( 'fatal' === $j['result'] && 'late' === $j['error']['message'], 'B10 the last start marker counts: core prints its report at shutdown, after the page' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 200 ), 'body' => "<html>###### wp_scraping_result_start:$k ######\ntrue" ), $k );
ok( 'unreachable' === $j['result'], 'B11 a start marker without an end marker: not reached' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 500 ), 'body' => $m( json_encode( array( 'type' => 1, 'message' => str_repeat( 'x', 5000 ), 'file' => 'f', 'line' => 1 ) ) ) ), $k );
ok( 2000 === strlen( $j['error']['message'] ), 'B12 a long error message is cut to 2000 characters' );

/* a fatal error before WordPress starts its report: its pages as WordPress 7.1.2 answered (support/probe-early/) */
$fx = static function ( $name ) { return (string) file_get_contents( __DIR__ . '/../standalone-lib/support/probe-early/' . $name ); };
$j  = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 500 ), 'body' => $fx( 'error-page.html' ) ), $k, 'home' );
ok( 'fatal' === $j['result'] && ! empty( $j['early'] ) && '' === $j['error']['file'] && 0 === $j['error']['line'] && ! isset( $j['no_report'] ), 'EF1 WordPress\'s critical error page without the markers: a fatal error before the report, file and line unknown, not an answer without the report' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 500 ), 'body' => $fx( 'error-page-display.html' ) ), $k, 'home' );
ok( 'fatal' === $j['result'] && 'Uncaught Error: Call to undefined function ab_live_missing_function()' === $j['error']['message'] && '/home/tester/wplive/site/wp-content/mu-plugins/boom.php' === $j['error']['file'] && 3 === $j['error']['line'], 'EF2 where the site displays errors: message, file and line from what PHP printed before the page' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 500 ), 'body' => $fx( 'error-json.txt' ) ), $k, 'mcp' );
ok( 'fatal' === $j['result'] && ! empty( $j['early'] ) && '' === $j['error']['file'], 'EF3 the same as JSON (code internal_server_error), as the REST route answers it' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 500 ), 'body' => $fx( 'error-json-display.txt' ) ), $k, 'mcp' );
ok( 'fatal' === $j['result'] && 'Uncaught Error: Call to undefined function ab_live_missing_function()' === $j['error']['message'] && '/home/tester/wplive/site/wp-content/mu-plugins/boom.php' === $j['error']['file'] && 3 === $j['error']['line'], 'EF4 with WP_DEBUG_DISPLAY the JSON carries file and line; the message is its first line, without the stack trace' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 500 ), 'body' => '<html><body>Internal Server Error</body></html>' ), $k, 'home' );
ok( 'unreachable' === $j['result'] && ! empty( $j['no_report'] ), 'EF5 counter-check: a 500 that is not WordPress\'s page (the web server\'s) stays an answer without the report' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 200 ), 'body' => $fx( 'error-page.html' ) ), $k, 'home' );
ok( 'unreachable' === $j['result'] && ! empty( $j['no_report'] ), 'EF5 and so does WordPress\'s error page under another status than 500' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 500 ), 'body' => "<br />\n<b>Fatal error</b>:  Cannot redeclare ab_x() (previously declared in /srv/a.php:2) in <b>/srv/b.php</b> on line <b>4</b><br />\n" . $fx( 'error-page.html' ) ), $k, 'home' );
ok( 'Cannot redeclare ab_x() (previously declared in /srv/a.php:2)' === $j['error']['message'] && '/srv/b.php' === $j['error']['file'] && 4 === $j['error']['line'], 'EF6 a message that names another file keeps it whole; the location is the one at its end' );
$plog    = sys_get_temp_dir() . '/ab-probe-log-' . getmypid() . '.log';
$was_log = (string) ini_get( 'error_log' );
file_put_contents( $plog, '[' . gmdate( 'd-M-Y H:i:s', time() - 600 ) . " UTC] PHP Fatal error:  Uncaught Error: old in /srv/old.php:9\nStack trace:\n#0 {main}\n  thrown in /srv/old.php on line 9\n[" . gmdate( 'd-M-Y H:i:s' ) . " UTC] PHP Warning:  something in /srv/w.php on line 1\n[" . gmdate( 'd-M-Y H:i:s' ) . " UTC] PHP Fatal error:  Uncaught Error: Call to undefined function nope() in /srv/new.php:5\nStack trace:\n#0 /srv/wp-settings.php(526): include_once()\n#1 {main}\n  thrown in /srv/new.php on line 5\n" );
ini_set( 'error_log', $plog );
$e = AB_MCP_Php_Probe::logged_fatal( time() - 5 );
ok( is_array( $e ) && '/srv/new.php' === $e['file'] && 5 === $e['line'] && 'Uncaught Error: Call to undefined function nope()' === $e['message'], 'EF7 the PHP error log: the last fatal error since the test began, with file and line' );
ok( null === AB_MCP_Php_Probe::logged_fatal( time() + 60 ), 'EF7 none since then: null; the older entry and the warning are not taken' );
ini_set( 'error_log', $plog . '.missing' );
ok( false === AB_MCP_Php_Probe::logged_fatal( time() - 5 ), 'EF7 no log to read: false' );
ini_set( 'error_log', $was_log );
unlink( $plog );

$row = static function ( $r ) { return array( 'result' => $r ); };
ok( 'passed' === AB_MCP_Php_Probe::verdict( array( $row( 'ok' ), $row( 'ok' ), $row( 'ok' ) ) ), 'B13 verdict: all ok is passed' );
ok( 'partial' === AB_MCP_Php_Probe::verdict( array( $row( 'ok' ), $row( 'unreachable' ), $row( 'ok' ) ) ), 'B14 verdict: some not reached is partial' );
ok( 'unreachable' === AB_MCP_Php_Probe::verdict( array( $row( 'unreachable' ), $row( 'unreachable' ), $row( 'unreachable' ) ) ), 'B15 verdict: none reached is unreachable' );
ok( 'fatal' === AB_MCP_Php_Probe::verdict( array( $row( 'unreachable' ), $row( 'fatal' ), $row( 'not_probed' ) ) ), 'B16 verdict: any fatal is fatal' );
ok( 'partial' === AB_MCP_Php_Probe::verdict( array( $row( 'stopped' ), $row( 'unreachable' ), $row( 'unreachable' ) ) ) && 'partial' === AB_MCP_Php_Probe::verdict( array( $row( 'ok' ), $row( 'stopped' ), $row( 'ok' ) ) ), 'B26 verdict: a target that stopped was reached, and is not passed' );

/* where the key may travel on a redirect */
ok( AB_MCP_Php_Probe::same_site( 'http://site.test/', 'http://SITE.test/?lang=de' ), 'B27 same host (any case): followed' );
ok( AB_MCP_Php_Probe::same_site( 'http://site.test/', 'https://site.test/' ), 'B28 http up to https on the default port: followed' );
ok( ! AB_MCP_Php_Probe::same_site( 'https://site.test/', 'http://site.test/' ), 'B29 https down to http: not followed' );
ok( ! AB_MCP_Php_Probe::same_site( 'http://site.test/', 'http://www.site.test/' ) && ! AB_MCP_Php_Probe::same_site( 'http://site.test/', 'http://site.test.evil.test/' ), 'B30 another host: not followed' );
ok( ! AB_MCP_Php_Probe::same_site( 'http://site.test/', 'http://site.test:8080/' ) && ! AB_MCP_Php_Probe::same_site( 'http://site.test/', 'https://site.test:8443/' ), 'B31 another port: not followed' );
ok( ! AB_MCP_Php_Probe::same_site( 'http://site.test:8080/', 'https://site.test/' ) && ! AB_MCP_Php_Probe::same_site( 'http://site.test:8080/', 'https://site.test:443/' ), 'B39 up to https from another port than 80: not followed, it can be another application' );
ok( AB_MCP_Php_Probe::same_site( 'http://site.test:80/', 'https://site.test:443/' ), 'B40 counter-check: from port 80 to 443, written out: followed' );
ok( ! AB_MCP_Php_Probe::same_site( 'http://site.test/', 'ftp://site.test/' ) && ! AB_MCP_Php_Probe::same_site( 'http://site.test/', '/relative' ), 'B32 no http(s) URL with a host: not followed' );

/* the state a test request reports (AB_MCP_Probe_State) */
$sb = static function ( $state, $key = null ) use ( $k ) {
	$key = null === $key ? $k : $key;
	return "###### ab_mcp_state_start:$key ######" . ( is_string( $state ) ? $state : json_encode( $state ) ) . "###### ab_mcp_state_end:$key ######";
};
$st = array( 'plugins' => array( 'a/a.php' ), 'network' => array(), 'stylesheet' => 'calm', 'template' => 'calm' );
$j  = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 401 ), 'body' => '{"code":"ab_mcp_unauthorized","message":"x"}' . $m( 'true' ) . $sb( $st ) ), $k, 'mcp' );
ok( 'ok' === $j['result'] && $st === ( $j['state'] ?? null ), 'B34 this MCP endpoint\'s own 401 with the state after the report: ok, and the state is read' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 200 ), 'body' => '<html></html>' . $m( 'true' ) . $sb( $st, str_repeat( 'b', 32 ) ) ), $k, 'home' );
ok( 'ok' === $j['result'] && ! isset( $j['state'] ), 'B35 the state of another key is not read' );
$j = AB_MCP_Php_Probe::judge( array( 'response' => array( 'code' => 200 ), 'body' => '<html></html>' . $m( 'true' ) . $sb( '{broken' ) ), $k, 'home' );
ok( 'ok' === $j['result'] && ! isset( $j['state'] ), 'B36 a garbled state is not read, and the answer is judged as without it' );
ok( 'xy' === AB_MCP_Probe_State::strip( 'x' . $sb( $st ) . 'y', $k ), 'B37 strip() takes out the block and nothing else' );
// boot(): printed only for a test key and nonce core would accept.
$GLOBALS['ab_actions'] = array();
set_transient( 'scrape_key_' . $k, 'n1' );
$_GET = array( 'wp_scrape_key' => $k, 'wp_scrape_nonce' => 'wrong', AB_MCP_Probe_State::PARAM => '1' );
AB_MCP_Probe_State::boot();
ok( empty( $GLOBALS['ab_actions']['shutdown'] ), 'B38 a nonce that is not the stored one prints nothing' );
$_GET = array( 'wp_scrape_key' => $k, 'wp_scrape_nonce' => 'n1' );
AB_MCP_Probe_State::boot();
ok( empty( $GLOBALS['ab_actions']['shutdown'] ), 'B38 without ab-mcp-state nothing either' );
$_GET = array( 'wp_scrape_key' => $k, 'wp_scrape_nonce' => 'n1', AB_MCP_Probe_State::PARAM => '1' );
AB_MCP_Probe_State::boot();
ok( 1 === count( $GLOBALS['ab_actions']['shutdown'] ?? array() ) && PHP_INT_MAX === $GLOBALS['ab_actions']['shutdown'][0][1], 'B38 with the stored key and nonce: printed at shutdown, as the last action' );
$_GET = array();
unset( $GLOBALS['ab_transients'][ 'scrape_key_' . $k ] );

/* ===================================================== C) the requests */

$args = AB_MCP_Php_Probe::request_args();
ok( 0 === $args['redirection'] && array() === $args['cookies'] && (float) AB_MCP_Php_Probe::TIMEOUT === (float) $args['timeout'] && false === $args['sslverify'] && 'no-cache' === $args['headers']['Cache-Control'], 'C1 defaults: no redirects, no cookies, 10 s, sslverify as https_local_ssl_verify (false), no cache' );
$GLOBALS['ab_filters']['https_local_ssl_verify'] = true;
ok( true === AB_MCP_Php_Probe::request_args()['sslverify'], 'C2 sslverify follows the https_local_ssl_verify filter' );
unset( $GLOBALS['ab_filters']['https_local_ssl_verify'] );
$GLOBALS['ab_filters']['ab_mcp_php_probe_request_args'] = array(
	'timeout'     => 500,
	'redirection' => 5,
	'cookies'     => array( 'wordpress_logged_in_x' => 'admin|1|token' ),
	'headers'     => array( 'COOKIE' => 'wordpress_logged_in_x=1', 'Authorization' => 'Basic c3RhZ2luZzpzZWNyZXQ=' ),
	'stream'      => true,
	'filename'    => '/tmp/x',
	'blocking'    => false,
);
$args = AB_MCP_Php_Probe::request_args();
ok( 0 === $args['redirection'] && array() === $args['cookies'] && ! isset( $args['headers']['COOKIE'] ), 'C3 the filter cannot make the requests follow redirects or send cookies' );
ok( (float) AB_MCP_Php_Probe::TIMEOUT_MAX === (float) $args['timeout'], 'C4 a filtered timeout is capped at 60 s' );
ok( 'Basic c3RhZ2luZzpzZWNyZXQ=' === $args['headers']['Authorization'], 'C5 it can add a header, e.g. an HTTP login in front of a staging site' );
ok( true === $args['blocking'] && false === $args['stream'] && ! isset( $args['filename'] ), 'C6 the answer is always read in full' );
$GLOBALS['ab_filters']['ab_mcp_php_probe_request_args'] = array( 'timeout' => 0 );
ok( 1.0 === (float) AB_MCP_Php_Probe::request_args()['timeout'], 'C7 at least 1 s' );
unset( $GLOBALS['ab_filters']['ab_mcp_php_probe_request_args'] );
$max = AB_MCP_Php_Probe::requests_max( AB_MCP_Php_Probe::targets() );
ok( 3 + AB_MCP_Php_Probe::HOME_REDIRECTS === $max && 2 === AB_MCP_Php_Probe::HOME_REDIRECTS, 'C8 one round sends at most one request per target and two redirects of the home page' );

$GLOBALS['ab_requests'] = array();
AB_MCP_Php_Probe::run( array(), false, array( 'state' => true ) );
$with = array_filter( $GLOBALS['ab_requests'], static function ( $q ) { return '1' === ( $q['query'][ AB_MCP_Probe_State::PARAM ] ?? null ); } );
ok( count( $GLOBALS['ab_requests'] ) > 0 && count( $with ) === count( $GLOBALS['ab_requests'] ), 'C9 asked for the state, every test request carries ab-mcp-state=1' );
$GLOBALS['ab_requests'] = array();
AB_MCP_Php_Probe::run( array(), false );
$with = array_filter( $GLOBALS['ab_requests'], static function ( $q ) { return isset( $q['query'][ AB_MCP_Probe_State::PARAM ] ); } );
ok( count( $GLOBALS['ab_requests'] ) > 0 && array() === $with, 'C9 not asked, none does' );
$GLOBALS['ab_requests'] = array();

/* ============================ J) the home page follows its own redirects */

// In Pro these ran through wp_write_plugin_file; here the round is asked
// directly, with the marker in a file of an active plugin.
file_put_contents( hello( 'lang.php' ), "<?php\n// AB_LANG_REDIRECT\n" );
file_put_contents( hello( 'inc.php' ), "<?php\n// AB_FATAL_FRONT in wp_head\n" );
fresh();
$rep = AB_MCP_Php_Probe::run( array(), false );
ok( 'fatal' === results( $rep )['home'] && false !== strpos( AB_MCP_Own_Files::summary( $rep['targets'] ), 'the home page: fatal error' ), 'J1 behind a language redirect, a fatal error in the front end is still found' );
$homes = array_values( array_filter( $GLOBALS['ab_requests'], static function ( $q ) { return 'home' === $q['target']; } ) );
ok( 2 === count( $homes ) && 'de' === ( $homes[1]['query']['lang'] ?? '' ) && $homes[0]['query']['wp_scrape_key'] === $homes[1]['query']['wp_scrape_key'] && $homes[1]['transient_at_run'] === $homes[1]['query']['wp_scrape_nonce'], 'J2 the redirect is followed once, with the key, to the page it points to' );
file_put_contents( hello( 'inc.php' ), $GOOD );
fresh();
$rep = AB_MCP_Php_Probe::run( array(), false );
ok( 'passed' === $rep['result'] && array( 'http://site.test/?lang=de' ) === ( $rep['targets'][0]['redirects'] ?? null ), 'J3 good code behind the redirect: passed, the report names the page reached (without the key)' );
unlink( hello( 'lang.php' ) );
file_put_contents( hello( 'loop.php' ), "<?php\n// AB_REDIRECT_LOOP\n" );
fresh();
$rep   = AB_MCP_Php_Probe::run( array(), false );
$homes = array_values( array_filter( $GLOBALS['ab_requests'], static function ( $q ) { return 'home' === $q['target']; } ) );
ok( 'stopped' === results( $rep )['home'] && 1 + AB_MCP_Php_Probe::HOME_REDIRECTS === count( $homes ), 'J4 at most two redirects are followed; then the home page counts as stopped' );
ok( false !== strpos( AB_MCP_Own_Files::summary( $rep['targets'] ), 'the home page: did not run through (redirected' ), 'J5 and the summary says so' );
unlink( hello( 'loop.php' ) );
file_put_contents( hello( 'inc.php' ), "<?php\n// AB_FATAL_AFTER_REDIRECT\n" );
fresh();
$rep = AB_MCP_Php_Probe::run();
ok( 'fatal' === results( $rep )['home'] && array( 'home' ) === asked(), 'J6 a redirect that reports a fatal error is judged as it is, not followed, and the round stops there' );
file_put_contents( hello( 'inc.php' ), $OLD );

/* ================= K) OPcache that cannot be cleared (child process) */

$out  = array();
$code = 1;
exec( escapeshellarg( PHP_BINARY ) . ' -d opcache.enable_cli=1 ' . escapeshellarg( __FILE__ ) . ' opcache_blocked 2>&1', $out, $code );
$last = (string) end( $out );
ok( 0 === $code && preg_match( '/^CHILD (\d+) 0$/', $last, $mm ) && (int) $mm[1] >= 3, 'K4 with OPcache on and the invalidation refused: not_possible (' . $last . ')' );
$fc = $GLOBALS['ab_root'] . '/opcache-file-cache';
mkdir( $fc, 0700 );
foreach ( array( 'opcache_filecache' => '1', 'opcache_filecache_stale' => '0' ) as $child => $vt ) {
	$out  = array();
	$code = 1;
	exec( escapeshellarg( PHP_BINARY ) . ' -d opcache.enable_cli=1 -d opcache.file_cache=' . escapeshellarg( $fc ) . ' -d opcache.file_cache_only=1 -d opcache.validate_timestamps=' . $vt . ' ' . escapeshellarg( __FILE__ ) . ' ' . $child . ' 2>&1', $out, $code );
	$last = (string) end( $out );
	ok( 0 === $code && preg_match( '/^CHILD (\d+) 0$/', $last, $mm ) && (int) $mm[1] >= 2, "K8 $child: only a file cache that does not check file times is one this request cannot clear ($last)" );
}

/* ============================== L) the dashboard, logged in (child process) */
$out  = array();
$code = 1;
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' logged_in 2>&1', $out, $code );
$last = (string) end( $out );
ok( 0 === $code && preg_match( '/^CHILD (\d+) 0$/', $last, $mm ) && (int) $mm[1] >= 20, 'L the dashboard is asked logged in, with a session of its own that ends with the round (' . $last . ')' . ( 0 === $code ? '' : ' :: ' . implode( ' | ', array_slice( $out, -10 ) ) ) );

ok( $count > 70, "the test ran its checks ($count)" );
echo 0 === $fails
	? "ALLE $count PHP-PROBE-CHECKS BESTANDEN (0 Fehler)\n"
	: "$fails von $count PHP-PROBE-CHECKS gefallen\n";
exit( 0 === $fails ? 0 : 1 );
