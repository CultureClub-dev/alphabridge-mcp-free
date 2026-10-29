<?php
/**
 * Page caches after wp_undo and in wp_clear_cache, without WordPress. The
 * caching plugins are stand-ins with the signatures of the real entry points;
 * each records its call. Ported from AlphaBridge MCP Pro's
 * test/restore-cache-test.php to this plugin's AB_MCP_Page_Cache,
 * AB_MCP_Undo_Restore::after_restore(), AB_MCP_Tools_Undo_Core and
 * AB_MCP_Tools_System (since 4.9.0).
 *
 * Proves: a plugin is called only where its entry exists — three sets, none,
 * every second one and all of them, each in a process of its own, since a
 * function once defined cannot be taken away again; the per-post purge gets
 * exactly the posts the restore wrote, after clean_post_cache(); an undo of
 * post fields or post statuses purges those posts, an undo of site settings
 * (and any kind the table does not know, such as Pro's) the whole site;
 * ab_mcp_after_restore fires with kind, post ids and tool; wp_undo answers
 * with page_cache_purged, also after a partial undo, and purges only after
 * the undo point is settled; a cache, filter or listener that throws costs
 * only its own entry; wp_clear_cache purges the page caches as well; on a
 * network no purge reaches the other sites unless the plugin itself lets
 * this user do that, and what is left out is named with the reason.
 *
 * Left out against Pro's script: the theme and file undos (Pro's kinds).
 *
 * Run:  php tests/standalone/restore-cache-test.php   (the "none" and "half" sets run as child processes)
 */

error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['ab_free'] = dirname( __DIR__, 2 ) . '/includes/';

$set = getenv( 'AB_TEST_CACHE_SET' );
$set = in_array( $set, array( 'none', 'half', 'all' ), true ) ? $set : 'all';

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $c = '', $m = '', $d = null ) {
		$this->code    = $c;
		$this->message = $m;
		$this->data    = $d;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data( $code = '' ) { return $this->data; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
// null: every right (as a super admin); else the rights listed.
$GLOBALS['ab_caps'] = null;
function current_user_can( $cap, $obj = null ) { return null === $GLOBALS['ab_caps'] || in_array( $cap, $GLOBALS['ab_caps'], true ); }
function get_current_user_id() { return 7; }

/* ---- hooks, as WordPress runs them: do_action() without arguments passes '' ---- */
$GLOBALS['ab_hooks']   = array();
$GLOBALS['ab_filters'] = array();
function add_action( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['ab_hooks'][ $tag ][] = $cb; return true; }
function remove_all_actions( $tag ) { unset( $GLOBALS['ab_hooks'][ $tag ] ); return true; }
function has_action( $tag, $cb = false ) { return ! empty( $GLOBALS['ab_hooks'][ $tag ] ); }
function do_action( $tag, ...$args ) {
	if ( empty( $args ) ) {
		$args = array( '' );
	}
	foreach ( $GLOBALS['ab_hooks'][ $tag ] ?? array() as $cb ) {
		call_user_func_array( $cb, $args );
	}
}
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['ab_filters'][ $tag ][] = $cb; return true; }
function remove_all_filters( $tag ) { unset( $GLOBALS['ab_filters'][ $tag ] ); return true; }
function apply_filters( $tag, $value, ...$args ) {
	foreach ( $GLOBALS['ab_filters'][ $tag ] ?? array() as $cb ) {
		$value = call_user_func( $cb, $value, ...$args );
	}
	return $value;
}

/* ---- a call log: every purge, every clean_post_cache, the undo store's steps ---- */
$GLOBALS['ab_log']   = array();
$GLOBALS['ab_throw'] = array();   // slug => true: that stand-in throws
function ab_log( ...$entry ) { $GLOBALS['ab_log'][] = $entry; }
function ab_hit( $slug, $scope, ...$args ) {
	if ( ! empty( $GLOBALS['ab_throw'][ $slug ] ) ) {
		throw new RuntimeException( "$slug broke" );
	}
	ab_log( 'purge', $slug, $scope, $args );
}
function ab_purges() {
	$out = array();
	foreach ( $GLOBALS['ab_log'] as $e ) {
		if ( 'purge' === $e[0] ) {
			$out[ $e[1] ][ $e[2] ][] = $e[3];
		}
	}
	return $out;
}
function ab_first( $what ) {
	foreach ( $GLOBALS['ab_log'] as $i => $e ) {
		if ( $what === $e[0] ) {
			return $i;
		}
	}
	return -1;
}
function ab_last( $what ) {
	$last = -1;
	foreach ( $GLOBALS['ab_log'] as $i => $e ) {
		if ( $what === $e[0] ) {
			$last = $i;
		}
	}
	return $last;
}

/* ---- posts, options, site ---- */
$GLOBALS['ab_posts']     = array( 5 => 'publish', 6 => 'publish', 7 => 'publish', 8 => 'publish' );
$GLOBALS['ab_titles']    = array( 5 => 'New title' );
$GLOBALS['ab_multisite'] = false;
function get_post( $id ) {
	$id = (int) $id;
	if ( ! isset( $GLOBALS['ab_posts'][ $id ] ) ) { return null; }
	return (object) array( 'ID' => $id, 'post_status' => $GLOBALS['ab_posts'][ $id ], 'post_type' => 'post', 'post_title' => $GLOBALS['ab_titles'][ $id ] ?? '', 'post_content' => '', 'post_excerpt' => '', 'post_name' => '', 'post_parent' => 0, 'post_author' => 1, 'post_password' => '', 'post_date' => '2001-01-01 10:00:00', 'post_date_gmt' => '2001-01-01 09:00:00' );
}
function get_post_type( $id ) { return 'post'; }
function get_post_type_object( $t ) { return (object) array( 'cap' => (object) array( 'publish_posts' => 'publish_posts', 'edit_others_posts' => 'edit_others_posts' ) ); }
function get_post_stati( $args = array(), $output = 'names' ) { $k = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'inherit' ); return array_combine( $k, $k ); }
function wp_cache_delete( $k, $group = '' ) { return true; }
function wp_slash( $v ) { return $v; }
function wp_update_post( $a, $err = false ) {
	ab_log( 'wp_update_post', (int) $a['ID'] );
	if ( isset( $a['post_title'] ) ) { $GLOBALS['ab_titles'][ (int) $a['ID'] ] = $a['post_title']; }
	return (int) $a['ID'];
}
$GLOBALS['wpdb'] = new class {
	public $posts = 'wp_posts';
	public $options = 'wp_options';
	private $last = array();
	public function prepare( $q, ...$args ) { $this->last = $args; return $q; }
	public function get_row( $q, $output = null ) {
		$p = get_post( isset( $this->last[0] ) ? (int) $this->last[0] : 0 );
		return $p ? get_object_vars( $p ) : null;
	}
};
$GLOBALS['ab_opts']       = array( 'blogname' => 'New' );
$GLOBALS['ab_opt_refuse'] = false;   // true: the site keeps the value it has (a pre_update_option filter)
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['ab_opts'] ) ? $GLOBALS['ab_opts'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) {
	if ( ! empty( $GLOBALS['ab_opt_refuse'] ) ) { return false; }
	$GLOBALS['ab_opts'][ $k ] = $v;
	return true;
}
function add_option( $k, $v, $depr = '', $autoload = 'yes' ) {
	if ( array_key_exists( $k, $GLOBALS['ab_opts'] ) || ! empty( $GLOBALS['ab_opt_refuse'] ) ) { return false; }
	$GLOBALS['ab_opts'][ $k ] = $v;
	return true;
}
$GLOBALS['ab_no_link'] = array();   // posts get_permalink() answers false for
function get_permalink( $id ) { return isset( $GLOBALS['ab_posts'][ (int) $id ] ) && ! in_array( (int) $id, $GLOBALS['ab_no_link'], true ) ? 'https://example.test/?p=' . (int) $id : false; }
function clean_post_cache( $id ) {
	if ( ! empty( $GLOBALS['ab_throw']['clean_post_cache'] ) ) {
		throw new RuntimeException( 'clean_post_cache broke' );
	}
	ab_log( 'clean_post_cache', (int) $id );
}
function is_multisite() { return ! empty( $GLOBALS['ab_multisite'] ); }
function get_current_blog_id() { return 3; }
$GLOBALS['ab_main_site'] = false;
$GLOBALS['ab_subdomain'] = false;
function is_main_site() { return ! empty( $GLOBALS['ab_main_site'] ); }
function is_subdomain_install() { return ! empty( $GLOBALS['ab_subdomain'] ); }
function home_url( $path = '' ) { return 'https://example.test/sub' . $path; }
function network_admin_url( $path = '' ) { return 'https://example.test/wp-admin/network/' . $path; }
function wp_cache_flush() { ab_log( 'wp_cache_flush' ); return true; }

/* ---- the caching plugins, by the entry each publishes (see class-page-cache.php) ---- */
$all_slugs = array( 'litespeed-cache', 'wp-super-cache', 'w3-total-cache', 'wp-fastest-cache', 'sg-cachepress', 'breeze', 'nginx-helper', 'wp-rocket', 'cache-enabler', 'wp-optimize', 'hummingbird-performance' );
if ( 'none' === $set ) {
	$present = array();
} elseif ( 'half' === $set ) {
	// Every second one: actions, functions, a global object and a static class all appear on both sides.
	$present = array();
	foreach ( $all_slugs as $i => $slug ) {
		if ( 0 === $i % 2 ) {
			$present[] = $slug;
		}
	}
} else {
	$present = $all_slugs;
}
$has = function ( $slug ) use ( $present ) { return in_array( $slug, $present, true ); };

if ( $has( 'litespeed-cache' ) ) {
	add_action( 'litespeed_purge_post', function ( $pid ) { ab_hit( 'litespeed-cache', 'post', $pid ); } );
	add_action( 'litespeed_purge_all', function ( $reason = false ) { ab_hit( 'litespeed-cache', 'site' ); } );
}
if ( $has( 'wp-super-cache' ) ) {
	function wp_cache_post_change( $post_id ) { ab_hit( 'wp-super-cache', 'post', $post_id ); return $post_id; }
	function wp_cache_clear_cache( $blog_id = 0 ) { ab_hit( 'wp-super-cache', 'site', $blog_id ); }
}
if ( $has( 'w3-total-cache' ) ) {
	function w3tc_flush_post( $post_id, $force = false, $extras = null ) { ab_hit( 'w3-total-cache', 'post', $post_id ); }
	function w3tc_flush_posts( $extras = null ) { ab_hit( 'w3-total-cache', 'site' ); }
}
if ( $has( 'wp-fastest-cache' ) ) {
	function wpfc_clear_post_cache_by_id( $post_id = false ) { ab_hit( 'wp-fastest-cache', 'post', $post_id ); }
	function wpfc_clear_all_cache( $minified = false ) { ab_hit( 'wp-fastest-cache', 'site' ); }
}
if ( $has( 'sg-cachepress' ) ) {
	function sg_cachepress_purge_cache( $url = false ) { ab_hit( 'sg-cachepress', 'post', $url ); }
	function sg_cachepress_purge_everything() { ab_hit( 'sg-cachepress', 'site' ); }
}
if ( $has( 'breeze' ) ) {
	class Breeze_PurgeCache {}
	add_action( 'purge_post_cache', function ( $post_id ) { ab_hit( 'breeze', 'post', $post_id ); } );
	add_action( 'breeze_clear_all_cache', function () { ab_hit( 'breeze', 'site' ); } );
}
if ( $has( 'nginx-helper' ) ) {
	class AB_Test_Nginx_Purger {
		public function purge_post( $post_id ) { ab_hit( 'nginx-helper', 'post', $post_id ); }
		public function purge_all() { ab_hit( 'nginx-helper', 'site' ); }
	}
	$GLOBALS['nginx_purger'] = new AB_Test_Nginx_Purger();
	add_action( 'rt_nginx_helper_purge_all', array( $GLOBALS['nginx_purger'], 'purge_all' ) );
}
if ( $has( 'wp-rocket' ) ) {
	function rocket_clean_post( $post_id, $post = null ) { ab_hit( 'wp-rocket', 'post', $post_id ); }
	function rocket_clean_domain( $lang = '', $filesystem = null ) { ab_hit( 'wp-rocket', 'site' ); }
}
if ( $has( 'cache-enabler' ) ) {
	add_action( 'cache_enabler_clear_page_cache_by_post', function ( $post ) { ab_hit( 'cache-enabler', 'post', $post ); } );
	add_action( 'cache_enabler_clear_site_cache', function ( $site = null ) { ab_hit( 'cache-enabler', 'site' ); } );
}
if ( $has( 'wp-optimize' ) ) {
	class WPO_Page_Cache {
		public static function delete_single_post_cache( $post_id ) { ab_hit( 'wp-optimize', 'post', $post_id ); return true; }
		// The folder of one URL, with all below it when $recursive.
		public static function delete_cache_by_url( $url, $recursive = false ) { ab_hit( 'wp-optimize', 'own-site', $url, $recursive ); return true; }
	}
	function wpo_cache_flush() { ab_hit( 'wp-optimize', 'site' ); }
}
if ( $has( 'hummingbird-performance' ) ) {
	// As Hummingbird's clear_cache_action(): an id purges that post, none this
	// site's cache — on a network every site's when the referer is the network admin.
	add_action(
		'wphb_clear_page_cache',
		function ( $post_id = false ) {
			if ( $post_id ) {
				ab_hit( 'hummingbird-performance', 'post', $post_id );
				return;
			}
			$network = is_multisite() && isset( $_SERVER['HTTP_REFERER'] ) && 0 === strpos( $_SERVER['HTTP_REFERER'], network_admin_url() );
			ab_hit( 'hummingbird-performance', $network ? 'network' : 'site' );
		}
	);
}

/* ---- the undo store, reduced to what wp_undo asks ---- */
class AB_MCP_Undo {
	public static $entry = array();
	public static $data  = array();
	public static function enabled() { return true; }
	public static function suspend() {}
	public static function resume() {}
	public static function claim( $token ) { return array( 'entry' => self::$entry, 'data' => self::$data ); }
	public static function begin_apply( $token ) { ab_log( 'begin_apply' ); return 'nonce'; }
	public static function end_apply( $token, $nonce ) { ab_log( 'end_apply' ); }
	public static $finish_fails = false;   // true: the bookkeeping fails, as a lost write would
	public static function finish_apply( $token, $nonce, $consume ) {
		ab_log( 'finish_apply', $consume );
		return self::$finish_fails ? new WP_Error( 'ab_test_store', 'store broke' ) : true;
	}
	public static function extend_apply( $token, $nonce ) {}
	public static function hold_apply( $token, $nonce ) { return true; }
	// The row of a WordPress option is there (the settings of the tool always are).
	public static function option_autoload( $name ) { return array_key_exists( $name, $GLOBALS['ab_opts'] ) ? 'yes' : null; }
}
class AB_MCP_Tool_Registry {
	public $defs = array();
	public function register( $name, $def ) { $this->defs[ $name ] = $def; }
}

require $GLOBALS['ab_free'] . 'class-guidance.php';
require $GLOBALS['ab_free'] . 'class-post-status.php';
require $GLOBALS['ab_free'] . 'class-page-cache.php';
require $GLOBALS['ab_free'] . 'tools/class-tools-base.php';
require $GLOBALS['ab_free'] . 'tools/class-tools-system.php';
require $GLOBALS['ab_free'] . 'tools/class-tools-options-settings.php';
require $GLOBALS['ab_free'] . 'tools/class-tools-undo-core.php';
require $GLOBALS['ab_free'] . 'undo/class-undo-restore.php';

$fails = 0;
$count = 0;
function ok( $c, $label ) {
	global $fails, $count, $set;
	$count++;
	if ( ! $c ) {
		$fails++;
		echo "  FAIL [$set]: $label\n";
	}
}
function reset_all() {
	$GLOBALS['ab_log']            = array();
	$GLOBALS['ab_throw']          = array();
	$GLOBALS['ab_multisite']      = false;
	$GLOBALS['ab_caps']           = null;
	$GLOBALS['ab_main_site']      = false;
	$GLOBALS['ab_subdomain']      = false;
	$GLOBALS['ab_opts']           = array( 'blogname' => 'New' );
	$GLOBALS['ab_opt_refuse']     = false;
	$GLOBALS['ab_titles']         = array( 5 => 'New title' );
	AB_MCP_Undo::$finish_fails    = false;
	unset( $_SERVER['HTTP_REFERER'] );
	remove_all_actions( 'ab_mcp_after_restore' );
	remove_all_filters( 'ab_mcp_pro_page_cache_purgers' );
	add_action( 'ab_mcp_after_restore', function ( ...$args ) { ab_log( 'after_restore', $args ); } );
}
function ab_after_restore_calls() {
	$out = array();
	foreach ( $GLOBALS['ab_log'] as $e ) {
		if ( 'after_restore' === $e[0] ) {
			$out[] = $e[1];
		}
	}
	return $out;
}
function ab_cleaned() {
	$out = array();
	foreach ( $GLOBALS['ab_log'] as $e ) {
		if ( 'clean_post_cache' === $e[0] ) {
			$out[] = $e[1];
		}
	}
	return $out;
}
// Every present plugin purged these posts, one call each, and nothing else.
function posts_purged_by_all( array $ids, array $present ) {
	$p = ab_purges();
	if ( array_keys( $p ) !== array_values( array_intersect( array_keys( $p ), $present ) ) || count( $p ) !== count( $present ) ) {
		return false;
	}
	foreach ( $present as $slug ) {
		$want = array();
		foreach ( $ids as $id ) {
			$want[] = array( 'sg-cachepress' === $slug ? 'https://example.test/?p=' . $id : $id );
		}
		if ( ! isset( $p[ $slug ]['post'] ) || $p[ $slug ]['post'] !== $want || isset( $p[ $slug ]['site'] ) ) {
			return false;
		}
	}
	return true;
}
// Every present plugin purged the whole site once, and nothing else.
function site_purged_by_all( array $present, $wpsc_blog = 0 ) {
	$p = ab_purges();
	if ( count( $p ) !== count( $present ) ) {
		return false;
	}
	foreach ( $present as $slug ) {
		$want = array( 'wp-super-cache' === $slug ? array( $wpsc_blog ) : array() );
		if ( ! isset( $p[ $slug ]['site'] ) || $p[ $slug ]['site'] !== $want || isset( $p[ $slug ]['post'] ) ) {
			return false;
		}
	}
	return true;
}

$expected = array_values( array_intersect( $all_slugs, $present ) );

/* ============================== A) one post at a time */

reset_all();
$r = AB_MCP_Page_Cache::purge_posts( array( 5, '6', 5, 0, -3, 999 ) );
ok( $expected === $r['purged'], 'A1 the purged list names exactly the caches present, in a fixed order' );
ok( array() === $r['failed'], 'A2 nothing failed' );
ok( array( 5, 6, 999 ) === ab_cleaned(), 'A3 clean_post_cache() for every post id, each once, none for 0 or below' );
ok( posts_purged_by_all( array( 5, 6 ), $present ), 'A4 each cache present purged posts 5 and 6, once each (SiteGround by URL), and no post that is gone' );
ok( empty( $present ) || ab_last( 'clean_post_cache' ) < ab_first( 'purge' ), 'A5 the object cache is dropped before any page is purged' );
reset_all();
$r = AB_MCP_Page_Cache::purge_posts( array() );
ok( array() === $r['purged'] && array() === $GLOBALS['ab_log'], 'A6 no posts, no calls' );
reset_all();
$r = AB_MCP_Page_Cache::purge_posts( array( 999 ) );
ok( array() === $r['purged'] && array( 999 ) === ab_cleaned() && array() === ab_purges(), 'A7 a post that is gone: its cached copy is dropped, no page purge' );
reset_all();
$GLOBALS['ab_no_link'] = array( 8 );
$r = AB_MCP_Page_Cache::purge_posts( array( 8 ) );
ok( ! isset( ab_purges()['sg-cachepress'] ) && array() === $r['failed'], 'A8 SiteGround is not called without a URL: given none, it would purge the home page' );
$GLOBALS['ab_no_link'] = array();

/* ============================== B) the whole site */

reset_all();
$r = AB_MCP_Page_Cache::purge_site();
ok( $expected === $r['purged'] && array() === $r['failed'], 'B1 the whole-site purge names exactly the caches present' );
ok( site_purged_by_all( $present, 0 ), 'B2 each cache present purged once; WP Super Cache got 0, every site of a single install' );
reset_all();
$GLOBALS['ab_multisite'] = true;
$GLOBALS['ab_main_site'] = true;   // a network admin on the main site: every plugin purges whole (see J for the rest)
AB_MCP_Page_Cache::purge_site();
ok( site_purged_by_all( $present, 3 ), 'B3 on a network WP Super Cache gets this site\'s id, not 0 (which is every site)' );

/* ============================== C) a cache that throws */

reset_all();
if ( $present ) {
	$GLOBALS['ab_throw'][ $present[0] ] = true;
	$r = AB_MCP_Page_Cache::purge_site();
	ok( array( $present[0] ) === $r['failed'] && array_values( array_diff( $expected, array( $present[0] ) ) ) === $r['purged'], 'C1 a cache that throws is reported as failed, the others still purge' );
}
reset_all();
$GLOBALS['ab_throw']['clean_post_cache'] = true;
$r = AB_MCP_Page_Cache::purge_posts( array( 5 ) );
ok( array( 'clean_post_cache' ) === $r['failed'] && $expected === $r['purged'] && posts_purged_by_all( array( 5 ), $present ), 'C2 a clean_post_cache() hook that throws is reported, the page caches still purge' );

/* ============================== D) the filter (it keeps the name Pro gave it) */

reset_all();
add_filter(
	'ab_mcp_pro_page_cache_purgers',
	function ( $purgers, $scope ) {
		$purgers['my-host']  = function ( ...$a ) use ( $scope ) { ab_hit( 'my-host', $scope, ...$a ); };
		$purgers['']         = 'strlen';
		$purgers[7]          = 'strlen';
		$purgers['not-real'] = 'ab_no_such_function';
		return $purgers;
	},
	10,
	2
);
$r = AB_MCP_Page_Cache::purge_site();
ok( array_merge( $expected, array( 'my-host' ) ) === $r['purged'] && array() === $r['failed'], 'D1 a purger added through the filter runs and is listed; an empty or numeric slug or a non-callable is ignored' );
$r = AB_MCP_Page_Cache::purge_posts( array( 6 ) );
ok( in_array( 'my-host', $r['purged'], true ) && array( array( 6 ) ) === ab_purges()['my-host']['post'], 'D2 ... and gets the post id in the post scope' );
reset_all();
if ( $present ) {
	add_filter( 'ab_mcp_pro_page_cache_purgers', function ( $purgers ) use ( $present ) { unset( $purgers[ $present[0] ] ); return $purgers; } );
	$r = AB_MCP_Page_Cache::purge_site();
	ok( ! in_array( $present[0], $r['purged'], true ) && ! isset( ab_purges()[ $present[0] ] ), 'D3 a purger the filter takes out is not called' );
}
reset_all();
add_filter( 'ab_mcp_pro_page_cache_purgers', function () { throw new RuntimeException( 'filter broke' ); } );
try {
	$r     = AB_MCP_Page_Cache::purge_site();
	$threw = '';
} catch ( \Throwable $e ) {
	$threw = $e->getMessage();
}
ok( '' === $threw && $expected === $r['purged'] && array( 'ab_mcp_pro_page_cache_purgers' ) === $r['failed'] && site_purged_by_all( $present, 0 ), 'D4 a filter that throws is reported under failed; the caches present still purge' . ( $threw ? " — threw: $threw" : '' ) );
reset_all();
add_filter( 'ab_mcp_pro_page_cache_purgers', function () { throw new RuntimeException( 'filter broke' ); } );
try {
	$out   = AB_MCP_Undo_Restore::after_restore( array( 'kind' => 'post_fields', 'tool' => 'wp_update_post', 'restored' => array( 5 ), 'failed' => array(), 'blocked' => array() ) );
	$threw = '';
} catch ( \Throwable $e ) {
	$threw = $e->getMessage();
}
ok( '' === $threw && array( 'ab_mcp_pro_page_cache_purgers' ) === ( $out['page_cache_failed'] ?? null ) && $expected === $out['page_cache_purged'] && 1 === count( ab_after_restore_calls() ), 'D5 ... after a restore the answer comes back and ab_mcp_after_restore still fires' );
reset_all();
add_filter( 'ab_mcp_pro_page_cache_purgers', function () { throw new RuntimeException( 'filter broke' ); } );
try {
	$res   = AB_MCP_Tools_System::clear_cache();
	$threw = '';
} catch ( \Throwable $e ) {
	$threw = $e->getMessage();
}
ok( '' === $threw && true === $res['object_cache_flushed'] && array( 'ab_mcp_pro_page_cache_purgers' ) === ( $res['page_cache_failed'] ?? null ) && $expected === $res['page_cache_purged'], 'D6 ... and wp_clear_cache answers' );

/* ============================== E) an action registered late, or taken away */

if ( 'all' === $set ) {
	reset_all();
	$saved = $GLOBALS['ab_hooks']['litespeed_purge_post'];
	remove_all_actions( 'litespeed_purge_post' );
	$r = AB_MCP_Page_Cache::purge_posts( array( 5 ) );
	ok( ! in_array( 'litespeed-cache', $r['purged'], true ) && ! isset( ab_purges()['litespeed-cache'] ), 'E1 the presence is asked at the moment of the purge: no hook, no call' );
	$GLOBALS['ab_hooks']['litespeed_purge_post'] = $saved;
	reset_all();
	$r = AB_MCP_Page_Cache::purge_posts( array( 5 ) );
	ok( in_array( 'litespeed-cache', $r['purged'], true ), 'E2 ... and a hook registered later counts' );
	reset_all();
	$saved = $GLOBALS['nginx_purger'];
	$GLOBALS['nginx_purger'] = new stdClass();
	$r = AB_MCP_Page_Cache::purge_posts( array( 5 ) );
	ok( ! in_array( 'nginx-helper', $r['purged'], true ), 'E3 a $nginx_purger without purge_post() is not called' );
	$GLOBALS['nginx_purger'] = $saved;
	reset_all();
	$saved = $GLOBALS['ab_hooks']['purge_post_cache'];
	remove_all_actions( 'purge_post_cache' );
	add_action( 'purge_post_cache', function ( $id ) { ab_hit( 'breeze', 'post', $id ); } );
	$r = AB_MCP_Page_Cache::purge_posts( array( 5 ) );
	ok( in_array( 'breeze', $r['purged'], true ), 'E4 Breeze: its class and the purge_post_cache hook' );
	$GLOBALS['ab_hooks']['purge_post_cache'] = $saved;
}
if ( 'none' === $set ) {
	reset_all();
	add_action( 'purge_post_cache', function ( $id ) { ab_hit( 'breeze', 'post', $id ); } );
	$r = AB_MCP_Page_Cache::purge_posts( array( 5 ) );
	ok( array() === $r['purged'] && array() === ab_purges(), 'E5 a purge_post_cache hook without Breeze\'s class is not taken for Breeze' );
	remove_all_actions( 'purge_post_cache' );
}

/* ============================== F) after a restore: the three kinds of this plugin */

$scopes  = AB_MCP_Undo_Restore::page_cache_scope();
$missing = array();
foreach ( AB_MCP_Undo_Restore::policy() as $tool => $row ) {
	if ( ! isset( $scopes[ $row['kind'] ] ) || ! in_array( $scopes[ $row['kind'] ], array( 'post', 'site' ), true ) ) {
		$missing[] = $row['kind'];
	}
}
ok( array() === $missing, 'F1 every undo kind says which pages it leaves stale' . ( $missing ? ' — missing: ' . implode( ', ', array_unique( $missing ) ) : '' ) );
ok( array( 'post_status' => 'post', 'post_fields' => 'post', 'site_settings' => 'site' ) === $scopes, 'F1 posts for the post kinds, the whole site for the settings' );

reset_all();
$out = AB_MCP_Undo_Restore::after_restore( array( 'kind' => 'post_fields', 'tool' => 'wp_update_post', 'restored' => array( 5 ), 'failed' => array(), 'blocked' => array() ) );
ok( array( 'page_cache_purged' => $expected ) === $out, 'F2 post_fields: the answer lists the caches asked, and carries no failure list when nothing failed' );
ok( array( 5 ) === ab_cleaned() && posts_purged_by_all( array( 5 ), $present ), 'F3 ... clean_post_cache() and the page purge for exactly the restored post' );
ok( array( array( 'post_fields', array( 5 ), 'wp_update_post' ) ) === ab_after_restore_calls(), 'F4 ... and ab_mcp_after_restore fires once, with kind, post ids and tool' );
ok( ab_last( 'purge' ) < ab_first( 'after_restore' ), 'F5 ... after the caches were asked' );

reset_all();
$out = AB_MCP_Undo_Restore::after_restore(
	array(
		'kind'     => 'post_status',
		'tool'     => 'wp_bulk_update_status',
		'restored' => array( 5, 6 ),
		'failed'   => array( array( 'ref' => 7, 'reason' => 'The post was written, but the site stored something else for: post_status.' ) ),
		'blocked'  => array( array( 'ref' => 8, 'reason' => 'forbidden' ) ),
	)
);
ok( $expected === $out['page_cache_purged'] && posts_purged_by_all( array( 5, 6, 7 ), $present ) && array( 5, 6, 7 ) === ab_cleaned(), 'F6 post_status: a failed item is purged too (it may have been written), a blocked one is not' );
ok( array( array( 'post_status', array( 5, 6, 7 ), 'wp_bulk_update_status' ) ) === ab_after_restore_calls(), 'F7 ... and the action names the same posts' );
reset_all();
$out = AB_MCP_Undo_Restore::after_restore( array( 'kind' => 'post_fields', 'tool' => 'wp_update_post', 'restored' => array( '5', '5:x', 'abc', 6 ), 'failed' => array(), 'blocked' => array() ) );
ok( array( 5, 6 ) === ab_cleaned() && array( array( 'post_fields', array( 5, 6 ), 'wp_update_post' ) ) === ab_after_restore_calls(), 'F7 only refs that are post ids count as posts, each once' );

reset_all();
$out = AB_MCP_Undo_Restore::after_restore( array( 'kind' => 'site_settings', 'tool' => 'wp_update_site_settings', 'restored' => array( 'blogname' ), 'failed' => array(), 'blocked' => array() ) );
ok( $expected === $out['page_cache_purged'] && site_purged_by_all( $present, 0 ) && array() === ab_cleaned(), 'F8 site_settings: the whole site is purged, no single post' );
ok( array( array( 'site_settings', array(), 'wp_update_site_settings' ) ) === ab_after_restore_calls(), 'F9 site_settings: the action fires with no post ids' );
reset_all();
$out = AB_MCP_Undo_Restore::after_restore( array( 'kind' => 'site_settings', 'tool' => 'wp_update_site_settings', 'restored' => array(), 'failed' => array( array( 'ref' => 'blogname', 'reason' => 'x' ) ), 'blocked' => array() ) );
ok( $expected === $out['page_cache_purged'] && site_purged_by_all( $present, 0 ), 'F10 a settings restore that failed still purges: the write may have run' );
foreach ( array( 'option' => 'wp_update_option', 'theme' => 'wp_activate_theme', 'file' => 'wp_write_theme_file', 'builder_data' => 'wp_search_replace', 'something_new' => 'wp_x' ) as $kind => $tool ) {
	reset_all();
	$out = AB_MCP_Undo_Restore::after_restore( array( 'kind' => $kind, 'tool' => $tool, 'restored' => array( '5' ), 'failed' => array(), 'blocked' => array() ) );
	ok( $expected === $out['page_cache_purged'] && site_purged_by_all( $present, 0 ) && array() === ab_cleaned(), "F11 $kind (not a kind of this plugin): the whole site is purged rather than nothing" );
	ok( array( array( $kind, array(), $tool ) ) === ab_after_restore_calls(), "F12 $kind: the action fires with no post ids" );
}
reset_all();
$out = AB_MCP_Undo_Restore::after_restore( array( 'kind' => 'post_fields', 'tool' => 'wp_update_post', 'restored' => array(), 'failed' => array(), 'blocked' => array( array( 'ref' => 5, 'reason' => 'forbidden' ) ) ) );
ok( array( 'page_cache_purged' => array() ) === $out && array() === $GLOBALS['ab_log'], 'F13 a restore that wrote nothing purges nothing and announces nothing' );
reset_all();
add_action( 'ab_mcp_after_restore', function () { throw new RuntimeException( 'listener broke' ); } );
$out = AB_MCP_Undo_Restore::after_restore( array( 'kind' => 'site_settings', 'tool' => 'wp_update_site_settings', 'restored' => array( 'blogname' ), 'failed' => array(), 'blocked' => array() ) );
ok( $expected === $out['page_cache_purged'] && array( 'ab_mcp_after_restore' ) === $out['page_cache_failed'], 'F15 a listener that throws is reported and does not cost the answer' );
reset_all();
if ( $present ) {
	$GLOBALS['ab_throw'][ $present[0] ] = true;
	$out = AB_MCP_Undo_Restore::after_restore( array( 'kind' => 'post_fields', 'tool' => 'wp_update_post', 'restored' => array( 5 ), 'failed' => array(), 'blocked' => array() ) );
	ok( array( $present[0] ) === $out['page_cache_failed'] && ! in_array( $present[0], $out['page_cache_purged'], true ) && 1 === count( ab_after_restore_calls() ), 'F16 a cache that throws is listed under page_cache_failed; the action still fires' );
}

/* ============================== G) wp_undo */

AB_MCP_Undo::$entry = array( 'tool' => 'wp_update_site_settings', 'kind' => 'site_settings', 'summary' => 'Site settings: title' );
AB_MCP_Undo::$data  = array( 'blogname' => 'Old' );

reset_all();
$res = AB_MCP_Tools_Undo_Core::undo( array( 'token' => 'undo_abc', 'dry_run' => true ) );
ok( ! is_wp_error( $res ) && true === $res['dry_run'] && 1 === $res['would_undo'] && ! isset( $res['page_cache_purged'] ) && array() === ab_purges() && array() === ab_after_restore_calls() && 'New' === $GLOBALS['ab_opts']['blogname'], 'G1 a dry run purges nothing and announces nothing' );
reset_all();
$res = AB_MCP_Tools_Undo_Core::undo( array( 'token' => 'undo_abc' ) );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && 'Old' === $GLOBALS['ab_opts']['blogname'] && true === $res['consumed'], 'G2 the settings undo itself runs' );
ok( isset( $res['page_cache_purged'] ) && $expected === $res['page_cache_purged'] && ! isset( $res['page_cache_failed'] ), 'G3 wp_undo answers with page_cache_purged' );
ok( site_purged_by_all( $present, 0 ) && array( array( 'site_settings', array(), 'wp_update_site_settings' ) ) === ab_after_restore_calls(), 'G4 ... after purging the whole site and firing the action' );
$fin = ab_first( 'finish_apply' );
ok( $fin >= 0 && true === $GLOBALS['ab_log'][ $fin ][1] && $fin < ab_first( 'after_restore' ) && ( empty( $present ) || $fin < ab_first( 'purge' ) ), 'G5 the caches are asked only after the undo point is settled (consumed)' );

// A partial undo keeps the token, and what it wrote still counts.
reset_all();
$GLOBALS['ab_opt_refuse'] = true;
$res = AB_MCP_Tools_Undo_Core::undo( array( 'token' => 'undo_abc' ) );
ok( ! is_wp_error( $res ) && 0 === $res['undone'] && 1 === count( $res['failed'] ) && false === $res['consumed'], 'G6 a setting the site refuses: the undo is partial and keeps its token' );
ok( isset( $res['page_cache_purged'] ) && $expected === $res['page_cache_purged'] && site_purged_by_all( $present, 0 ) && array( array( 'site_settings', array(), 'wp_update_site_settings' ) ) === ab_after_restore_calls(), 'G7 ... and still purges the whole site, answers with page_cache_purged and fires the action' );
$fin = ab_first( 'finish_apply' );
ok( $fin >= 0 && false === $GLOBALS['ab_log'][ $fin ][1] && $fin < ab_first( 'after_restore' ), 'G8 ... after the undo point is settled as kept' );

// The bookkeeping fails after a full restore: the warning path.
reset_all();
AB_MCP_Undo::$finish_fails = true;
$res = AB_MCP_Tools_Undo_Core::undo( array( 'token' => 'undo_abc' ) );
ok( ! is_wp_error( $res ) && 'Old' === $GLOBALS['ab_opts']['blogname'] && ! empty( $res['warning'] ) && false === $res['consumed'], 'G9 a restore whose undo point cannot be removed answers with the warning' );
ok( is_string( $res['warning'] ?? null ) && false !== strpos( $res['warning'], 'wp_undo_discard' ), 'G9 and the warning names the way' );
ok( $expected === $res['page_cache_purged'] && site_purged_by_all( $present, 0 ) && 1 === count( ab_after_restore_calls() ), 'G10 ... and the restored settings are still purged and announced' );

// The fields of a post, end to end.
AB_MCP_Undo::$entry = array( 'tool' => 'wp_update_post', 'kind' => 'post_fields', 'summary' => '#5: post_title' );
AB_MCP_Undo::$data  = array( 'id' => 5, 'fields' => array( 'post_title' => 'Old title' ) );
reset_all();
$res = AB_MCP_Tools_Undo_Core::undo( array( 'token' => 'undo_abc' ) );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && true === $res['consumed'] && 'Old title' === $GLOBALS['ab_titles'][5], 'G11 a post fields undo writes the title back' );
ok( $expected === $res['page_cache_purged'] && posts_purged_by_all( array( 5 ), $present ) && array( 5 ) === ab_cleaned() && array( array( 'post_fields', array( 5 ), 'wp_update_post' ) ) === ab_after_restore_calls(), 'G12 ... and purges that post alone' );
ok( empty( $present ) || ab_first( 'wp_update_post' ) < ab_first( 'purge' ), 'G12 ... after it was written' );

// A filter that throws does not cost wp_undo its answer.
reset_all();
add_filter( 'ab_mcp_pro_page_cache_purgers', function () { throw new RuntimeException( 'filter broke' ); } );
try {
	$res   = AB_MCP_Tools_Undo_Core::undo( array( 'token' => 'undo_abc' ) );
	$threw = '';
} catch ( \Throwable $e ) {
	$threw = $e->getMessage();
}
ok( '' === $threw && ! is_wp_error( $res ) && true === $res['consumed'] && array( 'ab_mcp_pro_page_cache_purgers' ) === ( $res['page_cache_failed'] ?? null ), 'G13 a filter that throws: wp_undo still answers, with the filter under page_cache_failed' );

/* ============================== H) wp_clear_cache */

reset_all();
$res = AB_MCP_Tools_System::clear_cache();
ok( true === $res['object_cache_flushed'] && ab_first( 'wp_cache_flush' ) >= 0, 'H1 the object cache is flushed' );
ok( $expected === $res['page_cache_purged'] && ! isset( $res['page_cache_failed'] ) && site_purged_by_all( $present, 0 ), 'H2 wp_clear_cache purges the whole page cache of every plugin present and lists them' );
reset_all();
if ( $present ) {
	$GLOBALS['ab_throw'][ $present[0] ] = true;
	$res = AB_MCP_Tools_System::clear_cache();
	ok( array( $present[0] ) === $res['page_cache_failed'] && true === $res['object_cache_flushed'], 'H3 a cache that throws is listed under page_cache_failed' );
}
$reg = new AB_MCP_Tool_Registry();
AB_MCP_Tools_System::register( $reg );
AB_MCP_Tools_Undo_Core::register( $reg );
$desc = (string) $reg->defs['wp_clear_cache']['description'];
ok( false !== strpos( $desc, 'page cache' ) && false !== strpos( $desc, 'page_cache_purged' ), 'H4 the description of wp_clear_cache names the page cache and the answer field' );
$udesc = (string) $reg->defs['wp_undo']['description'];
ok( false !== strpos( $udesc, 'page_cache_purged' ) && false !== strpos( $udesc, 'page_cache_skipped' ) && false !== strpos( $udesc, 'site settings' ), 'H5 the description of wp_undo names page_cache_purged, page_cache_skipped and the purge after a settings undo' );
ok( false !== strpos( $desc, 'page_cache_skipped' ), 'H6 the description of wp_clear_cache names page_cache_skipped' );

/* ============================== J) a network: no purge reaches the other sites unless the plugin lets this user */

function ab_network( $caps, $main = false, $subdomain = false ) {
	reset_all();
	$GLOBALS['ab_multisite'] = true;
	$GLOBALS['ab_caps']      = $caps;
	$GLOBALS['ab_main_site'] = $main;
	$GLOBALS['ab_subdomain'] = $subdomain;
	$_SERVER['HTTP_REFERER'] = network_admin_url( 'admin.php?page=wphb-caching' );
}
function ab_purges_of( array $slugs ) {
	return array_intersect_key( ab_purges(), array_flip( $slugs ) );
}
$wide    = array( 'nginx-helper', 'wp-optimize', 'hummingbird-performance' );
$narrow  = array_values( array_diff( $present, $wide ) );
$site_ok = function () use ( $narrow ) {
	foreach ( $narrow as $slug ) {
		$want = array( 'wp-super-cache' === $slug ? array( 3 ) : array() );
		if ( ( ab_purges()[ $slug ]['site'] ?? null ) !== $want ) {
			return false;
		}
	}
	return true;
};
ab_network( array( 'manage_options' ) );
$r = AB_MCP_Page_Cache::purge_site();
$p = ab_purges();
ok( array_values( array_diff( $expected, array( 'nginx-helper' ) ) ) === $r['purged'] && array() === $r['failed'] && $site_ok(), 'J1 a site admin: every cache present but Nginx Helper is purged, the narrow ones as always' );
ok( ! isset( $p['nginx-helper'] ) && ( $has( 'nginx-helper' ) ? array( 'nginx-helper' ) === array_keys( $r['skipped'] ) && false !== strpos( $r['skipped']['nginx-helper'], '"Nginx Helper | Purge cache"' ) && false !== strpos( $r['skipped']['nginx-helper'], 'wp_clear_cache' ) : ! isset( $r['skipped'] ) ), 'J2 ... Nginx Helper, whose purge empties every site, is not called and is named with its right and the way' );
ok( ! $has( 'wp-optimize' ) || array( 'own-site' => array( array( 'https://example.test/sub/', true ) ) ) === $p['wp-optimize'], 'J3 ... WP-Optimize empties the folder of this site\'s home URL alone' );
ok( ! $has( 'hummingbird-performance' ) || array( 'site' => array( array() ) ) === $p['hummingbird-performance'], 'J4 ... Hummingbird clears this site even with a referer from the network admin' );
ok( network_admin_url( 'admin.php?page=wphb-caching' ) === ( $_SERVER['HTTP_REFERER'] ?? null ), 'J5 ... and the referer is put back afterwards' );
ab_network( array( 'manage_options' ), true );
$r = AB_MCP_Page_Cache::purge_site();
ok( array_values( array_diff( $expected, $wide ) ) === $r['purged'] && array() === ab_purges_of( $wide ) && $site_ok(), 'J6 the main site of a sub-directory network: Nginx Helper, WP-Optimize and Hummingbird are not called' );
ok( array_values( array_intersect( $wide, $present ) ) === array_keys( $r['skipped'] ?? array() ), 'J7 ... and each is named under skipped' );
ab_network( array( 'manage_options' ), true, true );
$r = AB_MCP_Page_Cache::purge_site();
$p = ab_purges();
ok( ( ! $has( 'wp-optimize' ) || isset( $p['wp-optimize']['own-site'] ) ) && ( ! $has( 'hummingbird-performance' ) || isset( $p['hummingbird-performance']['site'] ) ) && array_keys( $r['skipped'] ?? array() ) === array_values( array_intersect( array( 'nginx-helper' ), $present ) ), 'J8 the main site of a sub-domain network: WP-Optimize and Hummingbird clear this site, Nginx Helper is left out' );
ab_network( null );
$r = AB_MCP_Page_Cache::purge_site();
$p = ab_purges();
ok( $expected === $r['purged'] && ! isset( $r['skipped'] ), 'J9 a network admin: nothing is left out' );
ok( ( ! $has( 'nginx-helper' ) || array( 'site' => array( array() ) ) === $p['nginx-helper'] ) && ( ! $has( 'wp-optimize' ) || isset( $p['wp-optimize']['own-site'] ) && ! isset( $p['wp-optimize']['site'] ) ) && ( ! $has( 'hummingbird-performance' ) || array( 'site' => array( array() ) ) === $p['hummingbird-performance'] ), 'J10 ... WP-Optimize and Hummingbird still purge this site alone; Nginx Helper, which has no such purge, purges whole' );
ab_network( array( 'manage_options', 'Nginx Helper | Purge cache', 'manage_network_options', 'manage_network' ), true );
$r = AB_MCP_Page_Cache::purge_site();
$p = ab_purges();
ok( $expected === $r['purged'] && ! isset( $r['skipped'] ) && ( ! $has( 'wp-optimize' ) || array( 'site' => array( array() ) ) === $p['wp-optimize'] ) && ( ! $has( 'hummingbird-performance' ) || array( 'network' => array( array() ) ) === $p['hummingbird-performance'] ), 'J15 ... where no folder is one site alone, the plugins\' own network rights let their whole purge run' );
reset_all();
$GLOBALS['ab_caps'] = array();
$r = AB_MCP_Page_Cache::purge_site();
ok( $expected === $r['purged'] && ! isset( $r['skipped'] ) && site_purged_by_all( $present, 0 ), 'J11 a single site: every cache present purges whole, whatever the rights' );
ab_network( array( 'manage_options' ) );
$res = AB_MCP_Tools_System::clear_cache();
ok( $has( 'nginx-helper' ) ? isset( $res['page_cache_skipped']['nginx-helper'] ) : ! isset( $res['page_cache_skipped'] ), 'J12 wp_clear_cache names under page_cache_skipped what it left out' );
ab_network( array( 'manage_options' ) );
$out = AB_MCP_Undo_Restore::after_restore( array( 'kind' => 'site_settings', 'tool' => 'wp_update_site_settings', 'restored' => array( 'blogname' ), 'failed' => array(), 'blocked' => array() ) );
ok( $has( 'nginx-helper' ) ? isset( $out['page_cache_skipped']['nginx-helper'] ) : ! isset( $out['page_cache_skipped'] ), 'J13 ... and so does the purge after a restore' );
ab_network( array( 'manage_options' ) );
add_filter( 'ab_mcp_pro_page_cache_purgers', function ( $purgers ) { $purgers['nginx-helper'] = function () { ab_hit( 'nginx-helper', 'site' ); }; return $purgers; } );
$r = AB_MCP_Page_Cache::purge_site();
ok( in_array( 'nginx-helper', $r['purged'], true ) && ! isset( $r['skipped']['nginx-helper'] ), 'J14 a purger the filter puts back runs and is not named as left out' );

/* ============================== I) the other two sets, each in a process of its own */

if ( 'all' === $set ) {
	foreach ( array( 'none', 'half' ) as $child ) {
		$lines = array();
		exec( 'AB_TEST_CACHE_SET=' . $child . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' 2>&1', $lines, $rc );
		$last = (string) end( $lines );
		ok( 0 === $rc && false !== strpos( $last, ' 0 failed' ) && false !== strpos( $last, "($child)" ), "I1 the \"$child\" set passes" . ( $rc ? ' — ' . implode( ' | ', $lines ) : '' ) );
	}
}

printf( "RESTORE-CACHE (%s): %d passed, %d failed\n", $set, $count - $fails, $fails );
exit( $fails ? 1 : 0 );
