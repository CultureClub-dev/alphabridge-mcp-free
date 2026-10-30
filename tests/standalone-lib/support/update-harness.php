<?php
/**
 * Stand-in WordPress for the update tests (update-flow-test.php,
 * lifecycle-errors-test.php, probe-key-test.php) and the activation tests
 * (activation-guard-test.php) under tests/standalone-lifecycle/: a temporary
 * tree with plugins and themes on a real disk, the REAL tool classes, guard,
 * probe and log of this plugin (free since 4.11.0; taken over from Pro's
 * test/support/ with the class names and paths of this plugin), and stubs
 * that do what core does where the update path meets it:
 *
 *  - Plugin_Upgrader / Theme_Upgrader::bulk_upgrade() read the offer from the
 *    site transient (an item without one is "up_to_date" and true, as in
 *    core), switch the maintenance mode on for an active item, move the
 *    previous version to wp-content/upgrade-temp-backup/, copy the package
 *    (a folder here), hook delete_temp_backup at shutdown, switch the
 *    maintenance mode off and clean the update cache as $args says
 *    (class-plugin-upgrader.php, class-wp-upgrader.php);
 *  - WP_Upgrader::maintenance_mode() writes .maintenance with a new
 *    $upgrading each time (a counter on top of time(), so two writes in one
 *    second differ, as they may not in core), create_lock()/release_lock()
 *    keep a time in an option;
 *  - the site answers test requests as core does: while .maintenance is
 *    fresh, only a request with md5( $upgrading ) and $upgrading as its key
 *    and nonce gets through (wp_is_maintenance_mode()), every other one gets
 *    the 503 page without markers; then the transient must hold the nonce,
 *    compared strictly (wp_start_scraping_edited_file_errors()); then it
 *    "loads" the active plugins and themes and fails where one carries a
 *    marker (see ab_site_answer()).
 *
 * Every step leaves an event in $GLOBALS['ab_events'] (in order), so a test
 * can check the sequence.
 *
 * Attrappen sehen den Kern nicht: every path here also runs in the E2E frame
 * (test/e2e/builders/client/updates.mjs) against real WordPress.
 */

error_reporting( E_ALL );

/* ---------------------------------------------------------------- the tree */

$GLOBALS['ab_root'] = sys_get_temp_dir() . '/ab-upd-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
function ab_rm_tree( $dir ) {
	if ( 0 !== strpos( (string) $dir, sys_get_temp_dir() . '/ab-upd-' ) || ! is_dir( $dir ) || is_link( $dir ) ) {
		return;
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
	}
);
$root = $GLOBALS['ab_root'];
foreach ( array( 'wp/wp-admin/includes', 'wp/wp-content/plugins', 'wp/wp-content/themes', 'wp/wp-content/mu-plugins', 'wp/wp-content/uploads', 'packages' ) as $d ) {
	mkdir( "$root/$d", 0777, true );
}
foreach ( array( 'file', 'misc', 'plugin', 'update', 'plugin-install', 'theme', 'theme-install', 'class-wp-upgrader', 'class-automatic-upgrader-skin' ) as $inc ) {
	file_put_contents( "$root/wp/wp-admin/includes/$inc.php", "<?php\n" );
}

define( 'ABSPATH', "$root/wp/" );
define( 'WP_CONTENT_DIR', "$root/wp/wp-content" );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
define( 'WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins' );
define( 'AB_MCP_DIR', WP_PLUGIN_DIR . '/alphabridge-mcp/' );
define( 'AB_MCP_FILE', AB_MCP_DIR . 'alphabridge-mcp.php' );
define( 'AB_MCP_BASENAME', 'alphabridge-mcp/alphabridge-mcp.php' );
define( 'AB_MCP_REST_NAMESPACE', 'alphabridge/v1' );
define( 'AB_MCP_REST_ROUTE', '/mcp' );
define( 'KB_IN_BYTES', 1024 );
define( 'MB_IN_BYTES', 1048576 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
// This plugin itself sits in the plugins folder, as on a site.
mkdir( AB_MCP_DIR, 0777, true );
file_put_contents( AB_MCP_FILE, "<?php\n/**\n * Plugin Name: AlphaBridge MCP\n * Version: 4.11.0\n */\n" );

/* --------------------------------------------------------------- the stubs */

class WP_Error {
	public $errors = array();
	public $data   = array();
	public function __construct( $c = '', $m = '', $d = '' ) {
		if ( '' !== $c ) {
			$this->errors[ $c ][] = $m;
			if ( '' !== $d ) {
				$this->data[ $c ] = $d;
			}
		}
	}
	public function get_error_code() { $c = array_keys( $this->errors ); return $c ? $c[0] : ''; }
	public function get_error_codes() { return array_keys( $this->errors ); }
	public function get_error_message( $code = '' ) { $code = '' === $code ? $this->get_error_code() : $code; return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : ''; }
	public function get_error_messages( $code = '' ) {
		if ( '' !== $code ) { return isset( $this->errors[ $code ] ) ? $this->errors[ $code ] : array(); }
		$all = array();
		foreach ( $this->errors as $m ) { $all = array_merge( $all, $m ); }
		return $all;
	}
	public function get_error_data( $code = '' ) { $code = '' === $code ? $this->get_error_code() : $code; return isset( $this->data[ $code ] ) ? $this->data[ $code ] : null; }
	public function has_errors() { return ! empty( $this->errors ); }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function ab_event( $what ) { $GLOBALS['ab_events'][] = $what; }

/* hooks */
$GLOBALS['ab_filters'] = array( 'ab_mcp_php_cli_binary' => false );
$GLOBALS['ab_hooks']   = array();
function apply_filters( $tag, $value, ...$args ) {
	if ( array_key_exists( $tag, $GLOBALS['ab_filters'] ) ) {
		$f = $GLOBALS['ab_filters'][ $tag ];
		return ( $f instanceof Closure ) ? $f( $value, ...$args ) : $f;
	}
	if ( isset( $GLOBALS['ab_hooks'][ $tag ] ) ) {
		ksort( $GLOBALS['ab_hooks'][ $tag ] );
		foreach ( $GLOBALS['ab_hooks'][ $tag ] as $prio => $cbs ) {
			foreach ( $cbs as $cb ) {
				$value = call_user_func_array( $cb[0], array_slice( array_merge( array( $value ), $args ), 0, $cb[1] ) );
			}
		}
	}
	return $value;
}
function add_filter( $tag, $cb, $prio = 10, $n = 1 ) { $GLOBALS['ab_hooks'][ $tag ][ $prio ][] = array( $cb, $n ); return true; }
function add_action( $tag, $cb, $prio = 10, $n = 1 ) { return add_filter( $tag, $cb, $prio, $n ); }
function remove_action( $tag, $cb, $prio = 10 ) {
	if ( ! isset( $GLOBALS['ab_hooks'][ $tag ][ $prio ] ) ) { return false; }
	foreach ( $GLOBALS['ab_hooks'][ $tag ][ $prio ] as $i => $c ) {
		if ( $c[0] === $cb ) { unset( $GLOBALS['ab_hooks'][ $tag ][ $prio ][ $i ] ); ab_event( 'remove_action:' . $tag ); return true; }
	}
	return false;
}
function remove_filter( $tag, $cb, $prio = 10 ) { return remove_action( $tag, $cb, $prio ); }
function has_action( $tag, $cb = false ) {
	foreach ( isset( $GLOBALS['ab_hooks'][ $tag ] ) ? $GLOBALS['ab_hooks'][ $tag ] : array() as $prio => $cbs ) {
		foreach ( $cbs as $c ) {
			if ( false === $cb || $c[0] === $cb ) { return $prio; }
		}
	}
	return false;
}
function do_action( $tag, ...$args ) {
	if ( ! isset( $GLOBALS['ab_hooks'][ $tag ] ) ) { return; }
	ksort( $GLOBALS['ab_hooks'][ $tag ] );
	foreach ( array_keys( $GLOBALS['ab_hooks'][ $tag ] ) as $prio ) {
		// Read again per priority: a callback may remove a later one, as
		// WP_Hook allows (the guard takes core's delete at 100 off at 1).
		foreach ( isset( $GLOBALS['ab_hooks'][ $tag ][ $prio ] ) ? $GLOBALS['ab_hooks'][ $tag ][ $prio ] : array() as $i => $cb ) {
			if ( isset( $GLOBALS['ab_hooks'][ $tag ][ $prio ][ $i ] ) ) {
				call_user_func_array( $cb[0], array_slice( $args, 0, $cb[1] ) );
			}
		}
	}
}
/** What WordPress does at the end of the request: the shutdown action. */
function ab_run_shutdown() { do_action( 'shutdown' ); $GLOBALS['ab_hooks']['shutdown'] = array(); }

/* options, transients */
$GLOBALS['ab_opts']       = array();
$GLOBALS['ab_site_trans'] = array();
$GLOBALS['ab_trans']      = array();
/*
 * $GLOBALS['ab_opts'] is the options table. Where a test switches it on
 * ($GLOBALS['ab_ocache'] an array), this request also keeps its own copy, as
 * WordPress does: loaded whole at the first read after it was emptied (as
 * alloptions; an option that is not there is remembered as missing), fresh
 * from the table each time (two reads give two objects), written through by
 * this request and emptied by wp_cache_delete() (defined by the test that
 * switches it on). Other requests — the test requests at init, while
 * $GLOBALS['ab_other'] is set — read and write the table. With the copy on,
 * update_option() also runs pre_update_option_{name} and writes nothing
 * where the value stays the same, and delete_option() asks the table, as
 * core's do. $GLOBALS['ab_opts_stuck'][ name ] = true: writes to that option
 * do not land (a filter that keeps it).
 */
function ab_oc_on() { return isset( $GLOBALS['ab_ocache'] ) && is_array( $GLOBALS['ab_ocache'] ) && empty( $GLOBALS['ab_other'] ); }
function ab_db_copy( $v ) { return unserialize( serialize( $v ) ); }
function ab_oc_read( $k ) {
	if ( array() === $GLOBALS['ab_ocache'] ) {
		foreach ( $GLOBALS['ab_opts'] as $name => $value ) { $GLOBALS['ab_ocache'][ $name ] = array( ab_db_copy( $value ) ); }
	}
	if ( ! array_key_exists( $k, $GLOBALS['ab_ocache'] ) ) {
		$GLOBALS['ab_ocache'][ $k ] = array_key_exists( $k, $GLOBALS['ab_opts'] ) ? array( ab_db_copy( $GLOBALS['ab_opts'][ $k ] ) ) : null;
	}
	return $GLOBALS['ab_ocache'][ $k ];
}
/* get_option() runs pre_option_{name} and option_{name} as core's does — in
 * this request only: a filter a test adds stands for code loaded here, which
 * the test requests (other requests) do not load. */
/* $GLOBALS['ab_everywhere']["option_{$name}"] = callable( $value ): code that
 * runs on every request (an mu-plugin, the host's), this one and the test
 * requests alike; $GLOBALS['ab_target_now'] names the test request asking
 * ('' in this one). */
$GLOBALS['ab_everywhere'] = array();
$GLOBALS['ab_target_now'] = '';
function get_option( $k, $d = false ) {
	$here = empty( $GLOBALS['ab_other'] );
	if ( $here ) {
		$pre = apply_filters( "pre_option_{$k}", false, $k, $d );
		if ( false !== $pre ) { return $pre; }
	}
	if ( ab_oc_on() ) {
		$c = ab_oc_read( $k );
		if ( null === $c ) { return $d; }
		$v = $c[0];
	} elseif ( array_key_exists( $k, $GLOBALS['ab_opts'] ) ) {
		$v = $GLOBALS['ab_opts'][ $k ];
	} else {
		return $d;
	}
	if ( isset( $GLOBALS['ab_everywhere'][ "option_{$k}" ] ) ) {
		$v = call_user_func( $GLOBALS['ab_everywhere'][ "option_{$k}" ], $v );
	}
	return $here ? apply_filters( "option_{$k}", $v, $k ) : $v;
}
function update_option( $k, $v, $a = null ) {
	if ( ! empty( $GLOBALS['ab_opts_stuck'][ $k ] ) ) { return false; }
	if ( ab_oc_on() ) {
		// As core: the old value is what get_option() hands out, filters included.
		$old = get_option( $k );
		$v   = apply_filters( "pre_update_option_{$k}", $v, $old, $k );
		if ( $v === $old || ( ( is_array( $v ) || is_object( $v ) ) && serialize( $v ) === serialize( $old ) ) ) { return false; }
		$GLOBALS['ab_ocache'][ $k ] = array( $v );
	}
	$GLOBALS['ab_opts'][ $k ] = $v;
	return true;
}
function add_option( $k, $v, $x = '', $a = null ) {
	if ( array_key_exists( $k, $GLOBALS['ab_opts'] ) ) { return false; }
	if ( ab_oc_on() ) { $GLOBALS['ab_ocache'][ $k ] = array( $v ); }
	$GLOBALS['ab_opts'][ $k ] = $v;
	return true;
}
function delete_option( $k ) {
	if ( ! empty( $GLOBALS['ab_opts_stuck'][ $k ] ) ) { return false; }
	$had = array_key_exists( $k, $GLOBALS['ab_opts'] );
	if ( ab_oc_on() ) { $GLOBALS['ab_ocache'][ $k ] = null; }
	unset( $GLOBALS['ab_opts'][ $k ] );
	return $had;
}
function get_site_option( $k, $d = false ) { return get_option( $k, $d ); }
function get_current_network_id() { return 1; }
if ( ! function_exists( 'maybe_serialize' ) ) {
	function maybe_serialize( $v ) { return ( is_array( $v ) || is_object( $v ) ) ? serialize( $v ) : $v; }
}
if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $v ) { return ( is_string( $v ) && preg_match( '/^(a|O|s|i|b|d):/', $v ) ) ? unserialize( $v ) : $v; }
}
function update_site_option( $k, $v ) { return update_option( $k, $v, false ); }
function delete_site_option( $k ) { return delete_option( $k ); }
function get_site_transient( $k ) { return array_key_exists( $k, $GLOBALS['ab_site_trans'] ) ? $GLOBALS['ab_site_trans'][ $k ] : false; }
function set_site_transient( $k, $v, $e = 0 ) { $GLOBALS['ab_site_trans'][ $k ] = $v; return true; }
function delete_site_transient( $k ) { unset( $GLOBALS['ab_site_trans'][ $k ] ); return true; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['ab_trans'][ $k ] = $v; return true; }
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['ab_trans'] ) ? $GLOBALS['ab_trans'][ $k ] : false; }
function delete_transient( $k ) { unset( $GLOBALS['ab_trans'][ $k ] ); return true; }
$GLOBALS['ab_multisite'] = ! empty( $GLOBALS['ab_multisite'] );
$GLOBALS['ab_blog']      = 1;
function is_multisite() { return $GLOBALS['ab_multisite']; }
function is_main_site() { return 1 === $GLOBALS['ab_blog']; }
/* Where this plugin runs on a network: network-wide, or on which sites (the
 * names come from Pro, where this was Pro). */
$GLOBALS['ab_pro_network'] = true;
$GLOBALS['ab_pro_on_main'] = true;
function plugin_basename( $f ) { return basename( dirname( $f ) ) . '/' . basename( $f ); }
function is_plugin_active_for_network( $p ) { return ( $GLOBALS['ab_pro_network'] && AB_MCP_BASENAME === $p ) || array_key_exists( $p, (array) get_site_option( 'active_sitewide_plugins', array() ) ); }
function get_blog_option( $id, $k, $d = false ) { return 'active_plugins' === $k ? ( $GLOBALS['ab_pro_on_main'] ? array( AB_MCP_BASENAME ) : array() ) : $d; }
function get_main_site_id() { return 1; }
function switch_to_blog( $id ) { $GLOBALS['ab_blog_stack'][] = $GLOBALS['ab_blog']; $GLOBALS['ab_blog'] = (int) $id; ab_event( 'switch_to_blog:' . $id ); return true; }
function restore_current_blog() { $GLOBALS['ab_blog'] = array_pop( $GLOBALS['ab_blog_stack'] ); ab_event( 'restore_current_blog' ); return true; }
/* WP-Cron: every site keeps its own events (the option cron of that site). */
$GLOBALS['ab_cron'] = array();
function wp_next_scheduled( $h, $args = array() ) { return isset( $GLOBALS['ab_cron'][ $GLOBALS['ab_blog'] ][ $h ] ) ? $GLOBALS['ab_cron'][ $GLOBALS['ab_blog'] ][ $h ]['time'] : false; }
function wp_schedule_event( $t, $r, $h, $args = array(), $wp_error = false ) { $GLOBALS['ab_cron'][ $GLOBALS['ab_blog'] ][ $h ] = array( 'time' => $t, 'recurrence' => $r ); return true; }
function wp_clear_scheduled_hook( $h, $args = array(), $wp_error = false ) { $had = isset( $GLOBALS['ab_cron'][ $GLOBALS['ab_blog'] ][ $h ] ); unset( $GLOBALS['ab_cron'][ $GLOBALS['ab_blog'] ][ $h ] ); return $had ? 1 : 0; }
/** WP-Cron on one site: each event planned there runs (when does not matter here), on that site. */
function ab_run_cron( $blog ) {
	$was                = $GLOBALS['ab_blog'];
	$GLOBALS['ab_blog'] = (int) $blog;
	foreach ( array_keys( isset( $GLOBALS['ab_cron'][ $blog ] ) ? $GLOBALS['ab_cron'][ $blog ] : array() ) as $h ) {
		do_action( $h );
	}
	$GLOBALS['ab_blog'] = $was;
}

/* misc */
function get_current_user_id() { return 1; }
/* Every capability, except those a test takes away in $GLOBALS['ab_denied_caps']. */
function current_user_can( $c, ...$a ) { return ! in_array( $c, (array) ( $GLOBALS['ab_denied_caps'] ?? array() ), true ); }
function trailingslashit( $p ) { return rtrim( $p, '/\\' ) . '/'; }
function untrailingslashit( $p ) { return rtrim( $p, '/\\' ); }
function wp_normalize_path( $p ) { return str_replace( '\\', '/', (string) $p ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_json_encode( $v, $o = 0, $d = 512 ) { return json_encode( $v, $o, $d ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_upload_dir() { return array( 'basedir' => WP_CONTENT_DIR . '/uploads', 'baseurl' => 'http://site.test/u', 'error' => false ); }
function wp_delete_file( $f ) { @unlink( $f ); }
function size_format( $b, $d = 0 ) { return $b . ' B'; }
function validate_file( $file, $allowed = array() ) {
	if ( ! is_scalar( $file ) || '' === $file ) { return 0; }
	$file = wp_normalize_path( $file );
	if ( '../' === $file ) { return 1; }
	if ( preg_match_all( '#\.\./#', $file, $m, PREG_SET_ORDER ) && count( $m ) > 1 ) { return 1; }
	if ( str_contains( $file, '../' ) && '../' !== mb_substr( $file, -3, 3 ) ) { return 1; }
	if ( ':' === substr( $file, 1, 1 ) ) { return 2; }
	return 0;
}
/* get_file_data(): the header line, as core reads it */
function get_file_data( $file, $headers, $ctx = '' ) {
	$src = (string) @file_get_contents( $file, false, null, 0, 8192 );
	$out = array();
	foreach ( $headers as $field => $regex ) {
		$out[ $field ] = preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $regex, '/' ) . ':(.*)$/mi', $src, $m ) ? trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $m[1] ) ) : '';
	}
	return $out;
}
function get_plugins( $folder = '' ) {
	$base = WP_PLUGIN_DIR . ( '' !== $folder ? $folder : '' );
	$out  = array();
	foreach ( (array) glob( $base . ( '' === $folder ? '/*/*.php' : '/*.php' ) ) as $f ) {
		$h = get_file_data( $f, array( 'Name' => 'Plugin Name', 'Version' => 'Version' ) );
		if ( '' !== $h['Name'] ) {
			$out[ '' === $folder ? basename( dirname( $f ) ) . '/' . basename( $f ) : basename( $f ) ] = $h;
		}
	}
	if ( '' === $folder ) {
		foreach ( (array) glob( WP_PLUGIN_DIR . '/*.php' ) as $f ) {
			$h = get_file_data( $f, array( 'Name' => 'Plugin Name', 'Version' => 'Version' ) );
			if ( '' !== $h['Name'] ) { $out[ basename( $f ) ] = $h; }
		}
	}
	return $out;
}
function is_plugin_active( $p ) { return in_array( $p, (array) get_option( 'active_plugins', array() ), true ) || array_key_exists( $p, (array) get_site_option( 'active_sitewide_plugins', array() ) ); }
function wp_opcache_invalidate( $f, $force = false ) { return false; }
function wp_opcache_invalidate_directory( $d ) {}

/* themes */
$GLOBALS['ab_stylesheet'] = 'calm';
$GLOBALS['ab_template']   = 'calm';
function get_theme_root( $s = '' ) { return WP_CONTENT_DIR . '/themes'; }
/* The options switch_theme() writes win where they are set (the activation tests). */
function get_stylesheet() { $o = get_option( 'stylesheet' ); return false !== $o ? $o : $GLOBALS['ab_stylesheet']; }
function get_template() { $o = get_option( 'template' ); return false !== $o ? $o : $GLOBALS['ab_template']; }
function get_stylesheet_directory() { return get_theme_root() . '/' . get_stylesheet(); }
function get_template_directory() { return get_theme_root() . '/' . get_template(); }
class AB_Test_Theme {
	public $s;
	public function __construct( $s ) { $this->s = (string) $s; }
	public function exists() { return is_file( get_theme_root() . '/' . $this->s . '/style.css' ); }
	public function get( $k ) { $h = get_file_data( get_theme_root() . '/' . $this->s . '/style.css', array( 'Name' => 'Theme Name', 'Version' => 'Version' ) ); return 'Name' === $k ? $h['Name'] : $h['Version']; }
	public function get_stylesheet() { return $this->s; }
	public function get_template() { $h = get_file_data( get_theme_root() . '/' . $this->s . '/style.css', array( 'Template' => 'Template' ) ); return '' !== $h['Template'] ? $h['Template'] : $this->s; }
	public function errors() { return false; }
	public function is_allowed() { return true; }
	public function get_stylesheet_directory() { return get_theme_root() . '/' . $this->s; }
}
function wp_get_theme( $s = null ) { return new AB_Test_Theme( null === $s ? get_stylesheet() : $s ); }
function wp_get_themes( $args = array() ) {
	$out = array();
	foreach ( (array) glob( get_theme_root() . '/*/style.css' ) as $f ) {
		$out[ basename( dirname( $f ) ) ] = new AB_Test_Theme( basename( dirname( $f ) ) );
	}
	return $out;
}

/* update offers: what wordpress.org (or a vendor's updater) offers. An offer
 * stands while the installed version is the one it was made for, like the
 * offer of the E2E recipe upd-offer. */
$GLOBALS['ab_offers']       = array( 'plugin' => array(), 'theme' => array() );
$GLOBALS['ab_offers_fail']  = false;
function ab_offer( $type, $item, $from, $to, $package, array $extra = array() ) { $GLOBALS['ab_offers'][ $type ][ $item ] = array( 'from' => $from, 'to' => $to, 'package' => $package ) + $extra; }
function ab_installed_version( $type, $item ) {
	$f = ( 'theme' === $type ) ? get_theme_root() . "/$item/style.css" : WP_PLUGIN_DIR . "/$item";
	if ( ! is_readable( $f ) ) { return null; }
	$h = get_file_data( $f, array( 'Version' => 'Version' ) );
	return $h['Version'];
}
/* As core when wordpress.org does not answer: the list is written before the
 * request, with the time of the check (and what a vendor's filter adds on
 * writing, here an empty response), and not again (wp-includes/update.php). */
function ab_offers_failed( $name ) {
	$cur = get_site_transient( $name );
	if ( ! is_object( $cur ) ) { $cur = new stdClass(); }
	$cur->last_checked = time();
	if ( ! isset( $cur->response ) ) { $cur->response = array(); }
	set_site_transient( $name, $cur );
}
function wp_update_plugins( $extra = array() ) {
	ab_event( 'wp_update_plugins' );
	if ( $GLOBALS['ab_offers_fail'] ) { ab_offers_failed( 'update_plugins' ); return; }
	$t = (object) array( 'last_checked' => time(), 'response' => array(), 'checked' => array() );
	foreach ( get_plugins() as $file => $p ) { $t->checked[ $file ] = $p['Version']; }
	foreach ( $GLOBALS['ab_offers']['plugin'] as $item => $o ) {
		if ( ab_installed_version( 'plugin', $item ) === $o['from'] ) {
			$t->response[ $item ] = (object) ( array( 'plugin' => $item, 'slug' => dirname( $item ), 'new_version' => $o['to'], 'package' => $o['package'] ) + ( isset( $o['id'] ) ? array( 'id' => $o['id'] ) : array() ) );
		}
	}
	set_site_transient( 'update_plugins', $t );
}
function wp_update_themes( $extra = array() ) {
	ab_event( 'wp_update_themes' );
	if ( $GLOBALS['ab_offers_fail'] ) { ab_offers_failed( 'update_themes' ); return; }
	$t = (object) array( 'last_checked' => time(), 'response' => array(), 'checked' => array() );
	foreach ( $GLOBALS['ab_offers']['theme'] as $item => $o ) {
		if ( ab_installed_version( 'theme', $item ) === $o['from'] ) {
			$t->response[ $item ] = array( 'theme' => $item, 'new_version' => $o['to'], 'package' => $o['package'] );
		}
	}
	set_site_transient( 'update_themes', $t );
}
function wp_clean_plugins_cache( $clear = true ) { ab_event( 'wp_clean_plugins_cache:' . ( $clear ? '1' : '0' ) ); if ( $clear ) { delete_site_transient( 'update_plugins' ); } }
function wp_clean_themes_cache( $clear = true ) { ab_event( 'wp_clean_themes_cache:' . ( $clear ? '1' : '0' ) ); if ( $clear ) { delete_site_transient( 'update_themes' ); } }

/* the file system: direct, on the real disk of the tree; $GLOBALS['ab_fs_move_fail']
 * makes its move() answer false, as on a host that cannot rename there */
$GLOBALS['ab_fs_method']    = 'direct';
$GLOBALS['ab_fs_move_fail'] = false;
function get_filesystem_method( $args = array(), $context = '', $relaxed = false ) { return $GLOBALS['ab_fs_method']; }
class AB_Test_FS {
	public function wp_content_dir() { return WP_CONTENT_DIR . '/'; }
	public function find_folder( $f ) { return trailingslashit( $f ); }
	public function is_dir( $p ) { return is_dir( $p ); }
	public function exists( $p ) { return file_exists( $p ); }
	public function delete( $p, $rec = false ) { if ( is_dir( $p ) ) { ab_rm_any( $p ); return ! file_exists( $p ); } return @unlink( $p ); }
	public function move( $from, $to, $overwrite = false ) { ab_event( 'fs_move:' . basename( $to ) ); return $GLOBALS['ab_fs_move_fail'] ? false : @rename( $from, $to ); }
}
function ab_rm_any( $dir ) {
	if ( ! is_dir( $dir ) ) { return; }
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) { $f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); }
	@rmdir( $dir );
}
function ab_copy_tree( $from, $to ) {
	@mkdir( $to, 0777, true );
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $from, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
	foreach ( $it as $f ) {
		$dest = $to . '/' . substr( $f->getPathname(), strlen( $from ) + 1 );
		$f->isDir() ? @mkdir( $dest, 0777, true ) : copy( $f->getPathname(), $dest );
	}
}
/* core's move_dir(); $GLOBALS['ab_move_fail'] names a destination basename that cannot be written;
 * $GLOBALS['ab_move_partial'] names destinations => how many moves there break off half-way
 * through the copy core falls back to: the first file is copied, the source stays whole. */
$GLOBALS['ab_move_fail']    = '';
$GLOBALS['ab_move_partial'] = array();
function move_dir( $from, $to, $overwrite = false ) {
	$name = basename( dirname( $to ) ) . '/' . basename( $to );
	ab_event( 'move_dir:' . basename( dirname( $from ) ) . '/' . basename( $from ) . '>' . $name );
	if ( '' !== $GLOBALS['ab_move_fail'] && $name === $GLOBALS['ab_move_fail'] ) {
		$GLOBALS['ab_move_fail'] = ''; // once: putting the new version back works
		return new WP_Error( 'move_failed', 'Could not move.' );
	}
	if ( file_exists( $to ) ) {
		if ( ! $overwrite ) { return new WP_Error( 'destination_already_exists_move_dir', 'The destination folder already exists.' ); }
		ab_rm_any( $to );
	}
	if ( ! empty( $GLOBALS['ab_move_partial'][ $name ] ) ) {
		--$GLOBALS['ab_move_partial'][ $name ];
		@mkdir( $to, 0777, true );
		$files = glob( $from . '/*' );
		sort( $files );
		copy( $files[0], $to . '/' . basename( $files[0] ) );
		return new WP_Error( 'copy_failed_copy_dir', 'Could not copy file.' );
	}
	@mkdir( dirname( $to ), 0777, true );
	return rename( $from, $to ) ? true : new WP_Error( 'move_failed', 'Could not move.' );
}

/* the upgraders */
$GLOBALS['ab_maint_seq']   = 0;
$GLOBALS['ab_maint_fail']  = false;
$GLOBALS['ab_bulk_args']   = array();
$GLOBALS['ab_bulk_throw']  = false;
$GLOBALS['ab_bulk_result'] = null; // a WP_Error to hand back instead of installing
$GLOBALS['ab_copy_fail']   = false; // the copy fails after the previous version was moved aside
$GLOBALS['ab_in_upgrade']  = null;  // called after each step the upgrader reports
class WP_Upgrader {
	public $skin;
	public $result = array();
	public function __construct( $skin = null ) { $this->skin = $skin; $GLOBALS['ab_last_upgrader'] = $this; }
	public function maintenance_mode( $enable = false ) {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) { $wp_filesystem = new AB_Test_FS(); }
		$file = ABSPATH . '.maintenance';
		if ( $enable ) {
			if ( $GLOBALS['ab_maint_fail'] ) { ab_event( 'maintenance:on:failed' ); return; }
			$upgrading = time() + ( ++$GLOBALS['ab_maint_seq'] );
			file_put_contents( $file, '<?php $upgrading = ' . $upgrading . '; ?>' );
			ab_event( 'maintenance:on:' . $upgrading );
		} elseif ( file_exists( $file ) ) {
			unlink( $file );
			ab_event( 'maintenance:off' );
		}
	}
	public static function create_lock( $name, $timeout = null ) {
		$GLOBALS['ab_lock_blog'][] = $GLOBALS['ab_blog'];
		$timeout = $timeout ? $timeout : HOUR_IN_SECONDS;
		$since   = get_option( $name . '.lock' );
		if ( false !== $since && (int) $since > time() - $timeout ) { ab_event( 'lock:busy' ); return false; }
		update_option( $name . '.lock', time() );
		ab_event( 'lock:create' );
		return true;
	}
	public static function release_lock( $name ) { ab_event( 'lock:release' ); return delete_option( $name . '.lock' ); }
	public $moved = array();
	/* Core's (class-wp-upgrader.php, 6.3): a copy already under the folder's name goes first. */
	public function move_to_temp_backup_dir( $args ) {
		ab_event( 'temp_backup:' . $args['dir'] . '/' . $args['slug'] );
		@mkdir( WP_CONTENT_DIR . "/upgrade-temp-backup/{$args['dir']}", 0777, true );
		$dest = WP_CONTENT_DIR . "/upgrade-temp-backup/{$args['dir']}/{$args['slug']}";
		ab_rm_any( $dest );
		return rename( $args['src'] . '/' . $args['slug'], $dest ) ? true : new WP_Error( 'fs_temp_backup_move', 'Could not move the old version to the upgrade-temp-backup directory.' );
	}
	/* Core deletes by the folder's name, whoever's copy it is now. */
	public function delete_temp_backup( array $b = array() ) {
		foreach ( $this->moved as $m ) { ab_rm_any( WP_CONTENT_DIR . "/upgrade-temp-backup/{$m['dir']}/{$m['slug']}" ); }
		ab_event( 'core:delete_temp_backup' );
		return true;
	}
	public function restore_temp_backup( array $b = array() ) {
		foreach ( $this->moved as $m ) {
			$src = WP_CONTENT_DIR . "/upgrade-temp-backup/{$m['dir']}/{$m['slug']}";
			if ( is_dir( $src ) ) { ab_rm_any( $m['root'] . '/' . $m['slug'] ); rename( $src, $m['root'] . '/' . $m['slug'] ); }
		}
		ab_event( 'core:restore_temp_backup' );
		return true;
	}
	protected function upgrade_items( $type, array $items, array $args ) {
		global $wp_filesystem;
		ab_event( 'bulk_upgrade' );
		$GLOBALS['ab_bulk_args'][] = $args;
		if ( 'direct' !== $GLOBALS['ab_fs_method'] ) { return false; }
		$wp_filesystem = new AB_Test_FS();
		if ( $GLOBALS['ab_bulk_throw'] ) { throw new RuntimeException( 'thrown inside the upgrader at /secret/path' ); }
		$current = get_site_transient( 'theme' === $type ? 'update_themes' : 'update_plugins' );
		$maint   = false;
		foreach ( $items as $i ) {
			$active = ( 'theme' === $type ) ? ( get_stylesheet() === $i || get_template() === $i ) : ( is_plugin_active( $i ) && isset( $current->response[ $i ] ) );
			$maint  = $maint || $active;
		}
		if ( $maint ) { $this->maintenance_mode( true ); }
		$out = array();
		foreach ( $items as $i ) {
			if ( ! is_object( $current ) || ! isset( $current->response[ $i ] ) ) {
				$this->skin->set_result( 'up_to_date' );
				$this->skin->feedback( 'up_to_date' );
				$out[ $i ] = true;
				continue;
			}
			if ( $GLOBALS['ab_bulk_result'] instanceof WP_Error ) {
				$out[ $i ] = $GLOBALS['ab_bulk_result'];
				$this->skin->set_result( $out[ $i ] );
				continue;
			}
			$o    = ( 'theme' === $type ) ? (object) $current->response[ $i ] : $current->response[ $i ];
			// Core's steps, each reported to the skin (class-wp-upgrader.php,
			// run()); ab_in_upgrade sees each, as a slow host would spend it.
			foreach ( array( 'downloading_package', 'unpack_package', 'installing_package' ) as $stage ) {
				$this->skin->feedback( $stage );
				if ( is_callable( $GLOBALS['ab_in_upgrade'] ) ) {
					call_user_func( $GLOBALS['ab_in_upgrade'], $stage );
				}
			}
			$slug = ( 'theme' === $type ) ? $i : dirname( $i );
			$root = ( 'theme' === $type ) ? get_theme_root() : WP_PLUGIN_DIR;
			$dir  = ( 'theme' === $type ) ? 'themes' : 'plugins';
			$moved = $this->move_to_temp_backup_dir( array( 'slug' => $slug, 'src' => $root, 'dir' => $dir ) );
			if ( true !== $moved ) {
				$out[ $i ] = $moved;
				continue;
			}
			$this->moved[] = array( 'dir' => $dir, 'slug' => $slug, 'root' => $root );
			if ( $GLOBALS['ab_copy_fail'] ) {
				// install_package() failed after the move: core restores and
				// deletes at shutdown (class-wp-upgrader.php, run()).
				mkdir( "$root/$slug", 0777, true );
				file_put_contents( "$root/$slug/half.txt", 'partial' );
				add_action( 'shutdown', array( $this, 'restore_temp_backup' ), 10, 0 );
				add_action( 'shutdown', array( $this, 'delete_temp_backup' ), 100, 0 );
				$out[ $i ] = new WP_Error( 'files_not_writable', 'The update cannot be installed because some files could not be copied.' );
				continue;
			}
			ab_copy_tree( $o->package, "$root/$slug" );
			add_action( 'shutdown', array( $this, 'delete_temp_backup' ), 100, 0 );
			$out[ $i ] = array( 'destination_name' => $slug );
		}
		// Core's order (class-plugin-upgrader.php, bulk_upgrade()): the
		// maintenance mode goes off first, then the cache, then the hook.
		$this->maintenance_mode( false );
		$clear = ! isset( $args['clear_update_cache'] ) || $args['clear_update_cache'];
		( 'theme' === $type ) ? wp_clean_themes_cache( $clear ) : wp_clean_plugins_cache( $clear );
		do_action( 'upgrader_process_complete', $this, array( 'action' => 'update', 'type' => $type ) );
		return $out;
	}
}
class Plugin_Upgrader extends WP_Upgrader {
	public $install_result = null;
	public function bulk_upgrade( $plugins, $args = array() ) { return $this->upgrade_items( 'plugin', $plugins, $args ); }
	public function install( $package, $args = array() ) { return $GLOBALS['ab_install']( $this, $package ); }
	public function plugin_info() { return isset( $GLOBALS['ab_plugin_info'] ) ? $GLOBALS['ab_plugin_info'] : false; }
}
class Theme_Upgrader extends WP_Upgrader {
	public function bulk_upgrade( $themes, $args = array() ) { return $this->upgrade_items( 'theme', $themes, $args ); }
	public function install( $package, $args = array() ) { return $GLOBALS['ab_install']( $this, $package ); }
	public function theme_info() { return isset( $GLOBALS['ab_theme_info'] ) ? $GLOBALS['ab_theme_info'] : false; }
}
class WP_Upgrader_Skin {
	public $result   = false;
	public $messages = array();
	public function set_result( $r ) { $this->result = $r; }
	public function feedback( $f, ...$a ) { $this->messages[] = is_wp_error( $f ) ? $f->get_error_message() : (string) $f; }
}
class Automatic_Upgrader_Skin extends WP_Upgrader_Skin {
	public function get_upgrade_messages() { return $this->messages; }
}
$GLOBALS['wp_filesystem'] = null;
/* Core from 6.6 on lets test requests through its maintenance mode, and has
 * has_fatal_error() since the same release; $GLOBALS['ab_old_core'] stands
 * for 6.5. */
if ( empty( $GLOBALS['ab_old_core'] ) ) {
	class WP_Automatic_Updater { protected function has_fatal_error() { return false; } }
} else {
	class WP_Automatic_Updater {}
}

/* ------------------------------------------------- the site being requested */

function home_url( $p = '' ) { return 'http://site.test' . ( '' === $p ? '' : '/' . ltrim( $p, '/' ) ); }
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
class WP_Http {
	public static function make_absolute_url( $rel, $url ) { return preg_match( '#^https?://#', $rel ) ? $rel : 'http://site.test/' . ltrim( $rel, '/' ); }
}
function wp_remote_retrieve_body( $r ) { return is_array( $r ) && isset( $r['body'] ) ? (string) $r['body'] : ''; }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) && isset( $r['response']['code'] ) ? (int) $r['response']['code'] : ''; }
function wp_remote_retrieve_header( $r, $h ) { return is_array( $r ) && isset( $r['headers'][ strtolower( $h ) ] ) ? $r['headers'][ strtolower( $h ) ] : ''; }

/*
 * Markers in the code the site loads (an active plugin in a folder, the
 * active theme and its parent) and what they do:
 *   AB_FATAL_LOAD   fatal error at every target, before init (as in functions.php)
 *   AB_FATAL_FRONT  fatal error at the home page only
 *   AB_FATAL_REST   fatal error at this MCP endpoint only
 *   AB_CLOSE_REST   this MCP endpoint answers 401 rest_not_logged_in (ends early)
 *   AB_SLOW_ONCE    the first request to the home page after the code appeared times out
 *   AB_HANG         every request times out
 *   AB_FRONT_HANGS  every request to the home page times out; the others answer
 *   AB_SELF_OFF     an active plugin takes itself out of the stored list when a request loads it
 *   AB_SWITCH_TO_x  the active theme switches the site to theme x when a request loads it
 *   AB_FATAL_IF_NETWORK  fatal error at every target while the plugin is active network-wide
 * $GLOBALS['ab_net'] = 'down' fails every connection (a site that cannot reach itself),
 * 'hang' lets every request time out, whatever code runs (a host that hangs),
 * 'slow:N' makes every request take N seconds (and time out where it may take less).
 * $GLOBALS['ab_on_init'] = callable( $target ): what WordPress runs at init in a
 * request that gets there (one that WordPress answers and whose code has no
 * AB_FATAL_LOAD); the other markers end requests after init.
 * A request that times out moves the test guard's clock on by its timeout.
 */
$GLOBALS['ab_net']      = 'up';
$GLOBALS['ab_requests'] = array();
/* Seconds the test guard's clock is ahead of the real one: its waits and the
 * test requests that time out add what they would take on a site. */
$GLOBALS['ab_clock']    = 0;
$GLOBALS['ab_slow_seen'] = array();
function ab_target_of( $url ) {
	$path = (string) parse_url( $url, PHP_URL_PATH );
	if ( '/wp-json/alphabridge/v1/mcp' === $path ) { return 'mcp'; }
	if ( '/wp-admin/admin-post.php' === $path ) { return 'admin_post'; }
	return 'home';
}
function ab_loaded_files() {
	// What a test request loads, another request: from the table, without
	// the filters code loaded in this request added.
	$was                      = ! empty( $GLOBALS['ab_other'] );
	$GLOBALS['ab_other']      = true;
	$GLOBALS['ab_target_now'] = isset( $GLOBALS['ab_target_req'] ) ? $GLOBALS['ab_target_req'] : '';
	try {
		$dirs = array();
		foreach ( array_merge( (array) get_option( 'active_plugins', array() ), array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) as $p ) {
			if ( false !== strpos( $p, '/' ) ) { $dirs[] = WP_PLUGIN_DIR . '/' . dirname( $p ); }
		}
		$dirs[] = get_stylesheet_directory();
		$dirs[] = get_template_directory();
	} finally {
		$GLOBALS['ab_other']      = $was;
		$GLOBALS['ab_target_now'] = '';
	}
	$files  = array();
	foreach ( array_unique( $dirs ) as $d ) {
		foreach ( (array) glob( $d . '/*.php' ) as $f ) { $files[] = $f; }
	}
	return $files;
}
function ab_site_marked( $marker ) {
	foreach ( ab_loaded_files() as $f ) {
		$src = (string) file_get_contents( $f );
		if ( false !== strpos( $src, $marker ) ) { return $f; }
	}
	return '';
}
function wp_remote_get( $url, $args = array() ) {
	$target = ab_target_of( $url );
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
	$key   = isset( $q['wp_scrape_key'] ) ? (string) $q['wp_scrape_key'] : '';
	$nonce = isset( $q['wp_scrape_nonce'] ) ? (string) $q['wp_scrape_nonce'] : '';
	$GLOBALS['ab_requests'][] = array(
		'target'    => $target,
		'key'       => $key,
		'nonce'     => $nonce,
		'timeout'   => isset( $args['timeout'] ) ? $args['timeout'] : null,
		'transient' => '' !== $key ? get_transient( 'scrape_key_' . $key ) : false,
	);
	ab_event( 'probe:' . $target );
	$GLOBALS['ab_target_req'] = $target;
	if ( 'down' === $GLOBALS['ab_net'] ) {
		return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to site.test port 80' );
	}
	// wp_is_maintenance_mode(), wp-includes/load.php.
	$mfile = ABSPATH . '.maintenance';
	if ( file_exists( $mfile ) && preg_match( '/\$upgrading = (\d+);/', (string) file_get_contents( $mfile ), $mm ) ) {
		$upgrading = (int) $mm[1];
		if ( ( time() - $upgrading ) < 600 && ( ! empty( $GLOBALS['ab_old_core'] ) || ! ( md5( $upgrading ) === $key && (int) $nonce === $upgrading ) ) ) {
			return array( 'response' => array( 'code' => 503 ), 'headers' => array( 'retry-after' => '600' ), 'body' => '<html>Briefly unavailable for scheduled maintenance. Check back in a minute.</html>' );
		}
	}
	$secs    = isset( $args['timeout'] ) ? (float) $args['timeout'] : 10.0;
	$timeout = new WP_Error( 'http_request_failed', sprintf( 'cURL error 28: Operation timed out after %d milliseconds with 0 bytes received', (int) round( $secs * 1000 ) ) );
	if ( 'hang' === $GLOBALS['ab_net'] || '' !== ab_site_marked( 'AB_HANG' ) || ( 'home' === $target && '' !== ab_site_marked( 'AB_FRONT_HANGS' ) ) ) {
		$GLOBALS['ab_clock'] += $secs;
		return $timeout;
	}
	if ( preg_match( '/^slow:(\d+)\z/', (string) $GLOBALS['ab_net'], $sm ) ) {
		if ( $secs < (float) $sm[1] ) {
			$GLOBALS['ab_clock'] += $secs;
			return $timeout;
		}
		$GLOBALS['ab_clock'] += (float) $sm[1];
	}
	$slow = ab_site_marked( 'AB_SLOW_ONCE' );
	if ( '' !== $slow && 'home' === $target && empty( $GLOBALS['ab_slow_seen'][ $slow ] ) ) {
		$GLOBALS['ab_slow_seen'][ $slow ] = true;
		$GLOBALS['ab_clock'] += $secs;
		return $timeout;
	}
	// $GLOBALS['ab_home_redirects'] = N: the home page sends "/" on through N redirects on this host.
	if ( 'home' === $target && ! empty( $GLOBALS['ab_home_redirects'] ) ) {
		$path  = (string) parse_url( $url, PHP_URL_PATH );
		$chain = array( '' );
		for ( $n = 1; $n <= (int) $GLOBALS['ab_home_redirects']; $n++ ) { $chain[] = '/hop' . $n . '/'; }
		$at = array_search( '/' === $path ? '' : $path, $chain, true );
		if ( false !== $at && $at < count( $chain ) - 1 ) {
			return array( 'response' => array( 'code' => 301 ), 'headers' => array( 'location' => 'http://site.test' . $chain[ $at + 1 ] ), 'body' => '' );
		}
	}
	// AB_SELF_OFF: an active plugin that switches itself off when a request
	// loads it (a requirement it misses), in the table, as that request writes it.
	$self_off = ab_site_marked( 'AB_SELF_OFF' );
	if ( '' !== $self_off ) {
		$self_file                            = basename( dirname( $self_off ) ) . '/' . basename( $self_off );
		$GLOBALS['ab_opts']['active_plugins'] = array_values( array_diff( (array) ( $GLOBALS['ab_opts']['active_plugins'] ?? array() ), array( $self_file ) ) );
	}
	// AB_SWITCH_TO_<name>: theme code that switches the site to theme <name>
	// when a request loads it (a fallback in after_switch_theme), in the table.
	foreach ( ab_loaded_files() as $loaded ) {
		if ( preg_match( '/AB_SWITCH_TO_([a-z]+)/', (string) file_get_contents( $loaded ), $to ) ) {
			$GLOBALS['ab_opts']['stylesheet'] = $to[1];
			$GLOBALS['ab_opts']['template']   = $to[1];
			break;
		}
	}
	if ( isset( $GLOBALS['ab_on_init'] ) && '' === ab_site_marked( 'AB_FATAL_LOAD' ) ) {
		// Another request: it reads and writes the table, not this request's copy.
		$GLOBALS['ab_other'] = true;
		try {
			call_user_func( $GLOBALS['ab_on_init'], $target );
		} finally {
			$GLOBALS['ab_other'] = false;
		}
	}
	$code = ( 'mcp' === $target ) ? 401 : 200;
	$page = ( 'mcp' === $target ) ? '{"code":"ab_mcp_unauthorized","message":"Invalid or missing bearer token.","data":{"status":401}}' : '<html>page</html>';
	if ( 'mcp' === $target && '' !== ab_site_marked( 'AB_CLOSE_REST' ) ) {
		$page = '{"code":"rest_not_logged_in","message":"You are not currently logged in.","data":{"status":401}}';
	}
	if ( '' === $key ) {
		return array( 'response' => array( 'code' => $code ), 'headers' => array(), 'body' => $page );
	}
	// wp_start_scraping_edited_file_errors(): sanitize_key, 32 characters.
	$k     = substr( sanitize_key( $key ), 0, 32 );
	$saved = get_transient( 'scrape_key_' . $k );
	if ( false === $saved ) {
		return array( 'response' => array( 'code' => $code ), 'headers' => array(), 'body' => $page );
	}
	if ( $saved !== $nonce ) {
		return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => "###### wp_scraping_result_start:$k ######" . json_encode( array( 'code' => 'scrape_nonce_failure', 'message' => 'Scrape key check failed. Please try again.' ) ) . "###### wp_scraping_result_end:$k ######" );
	}
	$error = null;
	$which = array(
		'AB_FATAL_LOAD'  => array( 'home', 'mcp', 'admin_post' ),
		'AB_FATAL_FRONT' => array( 'home' ),
		'AB_FATAL_REST'  => array( 'mcp' ),
	);
	// AB_FATAL_IF_NETWORK: a fatal error at every target while the plugin is
	// active network-wide (its network code), none on this site's list alone.
	$net_bug = ab_site_marked( 'AB_FATAL_IF_NETWORK' );
	if ( '' !== $net_bug && array_key_exists( basename( dirname( $net_bug ) ) . '/' . basename( $net_bug ), (array) ( $GLOBALS['ab_opts']['active_sitewide_plugins'] ?? array() ) ) ) {
		$which = array( 'AB_FATAL_IF_NETWORK' => array( 'home', 'mcp', 'admin_post' ) ) + $which;
	}
	foreach ( $which as $marker => $targets ) {
		$f = ab_site_marked( $marker );
		if ( '' !== $f && in_array( $target, $targets, true ) ) {
			$rel   = str_replace( ABSPATH, '', $f );
			$src   = (string) file_get_contents( $f );
			$line  = substr_count( substr( $src, 0, (int) strpos( $src, $marker ) ), "\n" ) + 1;
			$error = array( 'type' => 1, 'message' => "Uncaught Error: Call to undefined function ab_missing() in $rel:$line", 'file' => $rel, 'line' => $line );
			break;
		}
	}
	if ( null !== $error ) {
		$code = 500;
		$page = '<html>There has been a critical error on this website.</html>';
	}
	// Code that ends the answer itself before WordPress has finished
	// (fastcgi_finish_request(), exit in the shutdown action): it answers,
	// without core's report.
	if ( null === $error && '' !== ab_site_marked( 'AB_ENDS_ALL' ) ) {
		return array( 'response' => array( 'code' => $code ), 'headers' => array(), 'body' => $page );
	}
	// ... only at the home page, before the page, which stays blank.
	if ( null === $error && 'home' === $target && '' !== ab_site_marked( 'AB_ENDS_FRONT' ) ) {
		return array( 'response' => array( 'code' => $code ), 'headers' => array(), 'body' => '' );
	}
	$body = $page . "\n###### wp_scraping_result_start:$k ######\n" . json_encode( null === $error ? true : $error ) . "\n###### wp_scraping_result_end:$k ######\n";
	// ab-mcp-state=1: what this request loaded, printed at shutdown
	// (AB_MCP_Probe_State), through the code that runs everywhere.
	// $GLOBALS['ab_no_state']: the report does not come (a cached page).
	if ( ! empty( $q[ AB_MCP_Probe_State::PARAM ] ) && empty( $GLOBALS['ab_no_state'] ) ) {
		$GLOBALS['ab_other']      = true;
		$GLOBALS['ab_target_now'] = $target;
		try {
			$network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
			$plugins = array();
			foreach ( (array) get_option( 'active_plugins', array() ) as $p ) {
				if ( is_file( WP_PLUGIN_DIR . '/' . $p ) && ! in_array( $p, $network, true ) ) { $plugins[] = $p; }
			}
			$state = array( 'plugins' => $plugins, 'network' => $network, 'stylesheet' => (string) get_stylesheet(), 'template' => (string) get_template() );
		} finally {
			$GLOBALS['ab_other']      = false;
			$GLOBALS['ab_target_now'] = '';
		}
		$body .= "###### ab_mcp_state_start:$k ######" . json_encode( $state ) . "###### ab_mcp_state_end:$k ######";
	}
	return array(
		'response' => array( 'code' => $code ),
		'headers'  => array(),
		'body'     => $body,
	);
}

/* the registry */
class AB_MCP_Tool_Registry {
	public $defs = array();
	public function register( $name, $def ) { $this->defs[ $name ] = $def; }
}

/* undo: the guard drops a folder's file undo points after a kept update, and
 * a kept theme switch records one; the real ones are in undo-test.php. */
class AB_MCP_Undo {
	public static $dropped  = array();
	public static $recorded = array();
	public static function discard_files_of( $jail, $slug ) { self::$dropped[] = array( $jail, $slug ); return array( $slug . '/old.php' ); }
	public static function record( array $rec ) { self::$recorded[] = $rec; ab_event( 'undo:record:' . $rec['tool'] ); return 'undo_' . count( self::$recorded ); }
}

/* --------------------------------------------------------- the real classes */

$GLOBALS['ab_free'] = dirname( __DIR__, 3 ) . '/includes/';
require_once $GLOBALS['ab_free'] . 'tools/class-tools-base.php';
require_once $GLOBALS['ab_free'] . 'lifecycle/class-update-log.php';
require_once $GLOBALS['ab_free'] . 'class-lifecycle-confirm.php';
require_once $GLOBALS['ab_free'] . 'tools/class-tools-install-update.php';

/** The guard without the waits, and with a clock the tests set and the waits and timeouts move on. */
class AB_Test_Update_Guard extends AB_MCP_Update_Guard {
	public static $start = null;
	protected static function pause( $seconds ) { ab_event( 'pause:' . $seconds ); $GLOBALS['ab_clock'] += $seconds; }
	protected static function now() { return microtime( true ) + $GLOBALS['ab_clock']; }
	protected static function request_start() { return null !== self::$start ? self::$start : static::now(); }
	/** Seconds since the call began, by the guard's clock. */
	public static function elapsed() { return static::now() - self::$began; }
	public static $began = 0.0;
	public static function update( array $job ) { self::$began = static::request_start(); return parent::update( $job ); }
	/**
	 * The state of a request that died (a fatal error of PHP) right after
	 * bulk_upgrade() replaced the files: lock and note taken, maintenance
	 * mode on, core's delete of the copy hooked at shutdown, the guard's
	 * rescue too. Nothing of the rest of update() ran.
	 */
	public static function pretend_died( array $job ) {
		WP_Upgrader::create_lock( 'auto_updater' );
		AB_MCP_Update_Log::set_running( array( 'type' => $job['type'], 'item' => $job['item'], 'started' => time(), 'nonce' => 'died' ) );
		require_once $GLOBALS['ab_free'] . 'lifecycle/class-update-upgraders.php';
		$up         = new AB_MCP_Held_Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		self::$live = array( 'nonce' => 'died', 'since' => time(), 'upgrading' => null, 'upgrader' => $up, 'job' => $job, 'version_before' => self::version_of( $job ), 'fp_before' => self::fingerprint( $job, self::folder( $job ) ) );
		static::hook_shutdown();
		$up->maintenance_mode( true );
		$up->bulk_upgrade( array( $job['item'] ), array( 'clear_update_cache' => false ) );
		$up->maintenance_mode( true );
		return 'died';
	}
}

/* ---------------------------------------------------------------- builders */

/** A plugin in its folder: main file with a version and optional marker. */
function ab_plugin_src( $slug, $version, $marker = '' ) {
	return "<?php\n/**\n * Plugin Name: Test $slug\n * Version: $version\n */\n" . ( '' !== $marker ? "// $marker\n" : '' );
}
function ab_install_plugin( $slug, $version, $marker = '', $active = true ) {
	ab_rm_any( WP_PLUGIN_DIR . "/$slug" );
	mkdir( WP_PLUGIN_DIR . "/$slug", 0777, true );
	file_put_contents( WP_PLUGIN_DIR . "/$slug/$slug.php", ab_plugin_src( $slug, $version, $marker ) );
	$act = array_values( array_diff( (array) get_option( 'active_plugins', array() ), array( "$slug/$slug.php" ) ) );
	if ( $active ) { $act[] = "$slug/$slug.php"; }
	update_option( 'active_plugins', $act );
	return "$slug/$slug.php";
}
function ab_package_plugin( $slug, $version, $marker = '' ) {
	$dir = $GLOBALS['ab_root'] . "/packages/$slug-$version-" . bin2hex( random_bytes( 3 ) );
	mkdir( $dir, 0777, true );
	file_put_contents( "$dir/$slug.php", ab_plugin_src( $slug, $version, $marker ) );
	return $dir;
}
function ab_theme_src( $slug, $version ) { return "/*\nTheme Name: Test $slug\nVersion: $version\n*/\n"; }
function ab_install_theme( $slug, $version, $marker = '' ) {
	$d = get_theme_root() . "/$slug";
	ab_rm_any( $d );
	mkdir( $d, 0777, true );
	file_put_contents( "$d/style.css", ab_theme_src( $slug, $version ) );
	file_put_contents( "$d/functions.php", "<?php\n" . ( '' !== $marker ? "// $marker\n" : '' ) );
}
function ab_package_theme( $slug, $version, $marker = '' ) {
	$dir = $GLOBALS['ab_root'] . "/packages/$slug-$version-" . bin2hex( random_bytes( 3 ) );
	mkdir( $dir, 0777, true );
	file_put_contents( "$dir/style.css", ab_theme_src( $slug, $version ) );
	file_put_contents( "$dir/functions.php", "<?php\n" . ( '' !== $marker ? "// $marker\n" : '' ) );
	return $dir;
}
/** A clean state between cases. */
function ab_reset() {
	if ( getenv( 'AB_TIMES' ) ) {
		static $t = null;
		$now = microtime( true );
		if ( null !== $t ) { fwrite( STDERR, sprintf( "    %.2f s\n", $now - $t ) ); }
		$t = $now;
	}
	foreach ( (array) glob( WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR ) as $d ) {
		if ( 'alphabridge-mcp' !== basename( $d ) ) {
			ab_rm_any( $d );
		}
	}
	update_option( 'active_plugins', array() );
	$GLOBALS['ab_offers']      = array( 'plugin' => array(), 'theme' => array() );
	delete_site_transient( 'update_plugins' );
	delete_site_transient( 'update_themes' );
	$GLOBALS['ab_events']      = array();
	$GLOBALS['ab_requests']    = array();
	$GLOBALS['ab_cron']        = array();
	$GLOBALS['ab_bulk_args']   = array();
	$GLOBALS['ab_bulk_throw']  = false;
	$GLOBALS['ab_bulk_result'] = null;
	$GLOBALS['ab_copy_fail']   = false;
	$GLOBALS['ab_in_upgrade']  = null;
	$GLOBALS['ab_hooks']['upgrader_process_complete'] = array();
	$GLOBALS['ab_lock_blog']   = array();
	$GLOBALS['ab_move_fail']   = '';
	$GLOBALS['ab_move_partial'] = array();
	$GLOBALS['ab_maint_fail']  = false;
	$GLOBALS['ab_offers_fail'] = false;
	$GLOBALS['ab_net']         = 'up';
	$GLOBALS['ab_home_redirects'] = 0;
	$GLOBALS['ab_fs_method']   = 'direct';
	$GLOBALS['ab_fs_move_fail'] = false;
	$GLOBALS['ab_slow_seen']   = array();
	$GLOBALS['ab_clock']       = 0;
	$GLOBALS['ab_hooks']['shutdown'] = array();
	unset( $GLOBALS['ab_filters']['ab_mcp_php_probe_enabled'] );
	$GLOBALS['ab_everywhere'] = array();
	$GLOBALS['ab_no_state']   = false;
	AB_MCP_Undo::$dropped  = array();
	AB_MCP_Undo::$recorded = array();
	foreach ( array( 'stylesheet', 'template', 'template_root', 'stylesheet_root', 'current_theme', 'theme_switched', 'active_sitewide_plugins' ) as $o ) { delete_option( $o ); }
	unset( $GLOBALS['ab_plugin_info'], $GLOBALS['ab_theme_info'] );
	AB_Test_Update_Guard::$start = null;
	foreach ( array( 'auto_updater.lock', AB_MCP_Change_Lock::OPTION, AB_MCP_Update_Log::RUNNING, AB_MCP_Update_Log::REPLACED ) as $o ) { delete_option( $o ); }
	// A case that ended inside a holder leaves this request's count behind.
	$depth = new ReflectionProperty( 'AB_MCP_Change_Lock', 'depth' );
	if ( PHP_VERSION_ID < 80100 ) { $depth->setAccessible( true ); }
	$depth->setValue( null, 0 );
	@unlink( ABSPATH . '.maintenance' );
	ab_rm_any( WP_CONTENT_DIR . '/upgrade-temp-backup' );
}
/** The job the tool builds for a plugin, for calling the guard directly. */
function ab_plugin_job( $file ) {
	wp_update_plugins();
	$t = get_site_transient( 'update_plugins' );
	$o = isset( $t->response[ $file ] ) ? $t->response[ $file ] : null;
	return array(
		'type'   => 'plugin',
		'item'   => $file,
		'slug'   => dirname( $file ),
		'root'   => WP_PLUGIN_DIR,
		'dir'    => 'plugins',
		'tool'   => 'wp_update_plugin',
		'active' => is_plugin_active( $file ),
		'offer'  => array(
			'version' => $o ? $o->new_version : '',
			'package' => $o ? $o->package : '',
			'wporg'   => $o && isset( $o->id ) && 0 === strpos( $o->id, 'w.org/' ),
			'slug'    => dirname( $file ),
		),
	);
}

$GLOBALS['ab_fails'] = 0;
$GLOBALS['ab_count'] = 0;
function ok( $c, $label ) {
	$GLOBALS['ab_count']++;
	if ( ! $c ) { $GLOBALS['ab_fails']++; echo "  FAIL: $label\n"; }
}
function ab_code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : '(no error)'; }
function ab_msg( $r ) { return is_wp_error( $r ) ? $r->get_error_message() : ''; }
function ab_data( $r ) { return is_wp_error( $r ) ? (array) $r->get_error_data() : array(); }
function ab_events_like( $prefix ) { return array_values( array_filter( $GLOBALS['ab_events'], static function ( $e ) use ( $prefix ) { return 0 === strpos( $e, $prefix ); } ) ); }
function ab_finish( $name ) {
	if ( $GLOBALS['ab_fails'] ) {
		printf( "%s: FAILED (%d von %d)\n", $name, $GLOBALS['ab_fails'], $GLOBALS['ab_count'] );
		exit( 1 );
	}
	printf( "ALLE %d %s-CHECKS BESTANDEN (0 Fehler)\n", $GLOBALS['ab_count'], $name );
	exit( 0 );
}
