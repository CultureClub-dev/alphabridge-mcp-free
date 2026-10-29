<?php
/**
 * The undo store and the restore of post statuses and site settings, without
 * WordPress. Pure PHP with an in-memory option store and a temp uploads dir;
 * ported from AlphaBridge MCP Pro's test/undo-test.php to the classes of this
 * plugin (AB_MCP_Undo, AB_MCP_Undo_Restore, since 4.9.0).
 *
 * Proves the promises the tools make: a token is owner-bound, snapshots
 * round-trip with integrity checking, expiry and the ring buffer drop old
 * points (and their files), the window is at most 7 days here (a longer one
 * Pro stored reads as 7), and a restore re-checks the original capability per
 * object. The undo points of Pro's tools share the store: this plugin reads,
 * lists and counts them, and refuses to reverse or discard them
 * (ab_mcp_undo_needs_pro).
 *
 * Left out against Pro's script, because this plugin has none of it: the
 * restore of options and files (sections I, J, P, Q, QB, QD, U1, U2, AC, AD),
 * capture_file()/record_file() (N, AA8) and the readme count (AB1).
 *
 * Run:  php tests/standalone/undo-test.php
 */

error_reporting( E_ALL & ~E_DEPRECATED );

define( 'ABSPATH', __DIR__ . '/' );
define( 'KB_IN_BYTES', 1024 );
define( 'MB_IN_BYTES', 1048576 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['ab_free'] = dirname( __DIR__, 2 ) . '/includes/';

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
	// Mirrors core: data for the (first) code, null when none was given.
	public function get_error_data( $code = '' ) { return $this->data; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function admin_url( $p = '' ) { return 'http://x/wp-admin/' . $p; }
function is_email( $e ) { return false !== filter_var( (string) $e, FILTER_VALIDATE_EMAIL ) ? (string) $e : false; }

/* ---- switchable identity and capabilities ---- */
$GLOBALS['ab_uid']  = 7;
$GLOBALS['ab_caps'] = array(); // 'cap' => true, or 'cap:objid' => true
function get_current_user_id() { return (int) $GLOBALS['ab_uid']; }
function current_user_can( $cap, $obj = null ) {
	if ( null !== $obj && isset( $GLOBALS['ab_caps'][ $cap . ':' . $obj ] ) ) {
		return (bool) $GLOBALS['ab_caps'][ $cap . ':' . $obj ];
	}
	return ! empty( $GLOBALS['ab_caps'][ $cap ] );
}

/* ---- in-memory options ---- */
$GLOBALS['ab_opts']       = array();
$GLOBALS['ab_autoload']   = array();
$GLOBALS['ab_optdefault'] = array();
// The row this name really addresses, or null when there is none (the SQL
// comparison of option_name; no collation folding here).
function ab_row_name( $k ) {
	$k = trim( (string) $k );
	if ( array_key_exists( $k, $GLOBALS['ab_opts'] ) || array_key_exists( $k, $GLOBALS['ab_autoload'] ) ) { return $k; }
	return null;
}
function get_option( $k, $d = false ) {
	$k = trim( (string) $k );
	if ( array_key_exists( $k, $GLOBALS['ab_opts'] ) ) { return $GLOBALS['ab_opts'][ $k ]; }
	// Stands in for default_option_{$option}: a filter may answer for an option
	// that is not there at all, and its answer replaces the default passed in.
	if ( array_key_exists( $k, $GLOBALS['ab_optdefault'] ) ) { return $GLOBALS['ab_optdefault'][ $k ]; }
	return $d;
}
$GLOBALS['ab_optaddfail']  = array();   // per option: every add fails
$GLOBALS['ab_optaddafter'] = array();   // added_option-like hook
function add_option( $k, $v, $depr = '', $autoload = 'yes' ) {
	$k = trim( (string) $k );
	if ( isset( $GLOBALS['ab_optaddfail'][ $k ] ) ) { return false; }
	if ( array_key_exists( $k, $GLOBALS['ab_opts'] ) ) { return false; }
	// Stands in for sanitize_option_{$option}, which core runs on an add too.
	if ( isset( $GLOBALS['ab_optfilter'][ $k ] ) ) { $v = call_user_func( $GLOBALS['ab_optfilter'][ $k ], $v, null ); }
	$GLOBALS['ab_opts'][ $k ]     = $v;
	$GLOBALS['ab_autoload'][ $k ] = is_bool( $autoload ) ? ( $autoload ? 'yes' : 'no' ) : ( in_array( $autoload, array( 'no', 'off', 'auto-off' ), true ) ? 'no' : 'yes' );
	if ( isset( $GLOBALS['ab_optaddafter'][ $k ] ) ) { call_user_func( $GLOBALS['ab_optaddafter'][ $k ], $k ); }
	return true;
}
// Stands in for pre_update_option_{$option}: option name => callable( $new, $old )
// returning the value that really gets stored. Handing back the old value is how
// a site rejects the write outright.
$GLOBALS['ab_optfilter'] = array();
$GLOBALS['ab_optafter']  = array();
function update_option( $k, $v, $autoload = null ) {
	$k   = trim( (string) $k );
	$old = array_key_exists( $k, $GLOBALS['ab_opts'] ) ? $GLOBALS['ab_opts'][ $k ] : null;
	if ( isset( $GLOBALS['ab_optfilter'][ $k ] ) ) {
		$v = call_user_func( $GLOBALS['ab_optfilter'][ $k ], $v, $old );
		if ( array_key_exists( $k, $GLOBALS['ab_opts'] ) && $v === $old ) {
			return false;   // as WordPress: the row stays as it was
		}
	}
	if ( ! array_key_exists( $k, $GLOBALS['ab_opts'] ) ) { $GLOBALS['ab_autoload'][ $k ] = 'yes'; }
	$GLOBALS['ab_opts'][ $k ] = $v;
	// Stands in for the updated_option action: it runs after the row is
	// written and can still delete it again.
	if ( isset( $GLOBALS['ab_optafter'][ $k ] ) ) { call_user_func( $GLOBALS['ab_optafter'][ $k ], $k ); }
	return true;
}
function delete_option( $k ) {
	$k   = trim( (string) $k );
	$had = array_key_exists( $k, $GLOBALS['ab_opts'] );
	unset( $GLOBALS['ab_opts'][ $k ], $GLOBALS['ab_autoload'][ $k ] );
	return $had;
}
function wp_cache_delete( $k, $group = '' ) { return true; }

/* ---- filters: overridable so limits can be shrunk in a test ---- */
$GLOBALS['ab_filters'] = array();
function apply_filters( $tag, $value ) {
	return array_key_exists( $tag, $GLOBALS['ab_filters'] ) ? $GLOBALS['ab_filters'][ $tag ] : $value;
}
// Mirrors core: is anything hooked on the tag? Here: is a value set for it.
function has_filter( $tag, $cb = false ) {
	return array_key_exists( $tag, $GLOBALS['ab_filters'] );
}
// No caching plugin on this site: the page caches have nothing to purge.
function has_action( $tag, $cb = false ) { return false; }
$GLOBALS['ab_actions_fired'] = array();
function do_action( $tag, ...$args ) { $GLOBALS['ab_actions_fired'][] = $tag; }
function clean_post_cache( $id ) {}
function is_multisite() { return false; }

/* ---- serialisation + fs + misc WP shims ---- */
function wp_salt( $s = 'auth' ) { return 'test-salt-value'; }
// Mirrors core: the largest unit the number reaches, "0 B" for nothing.
function size_format( $bytes, $decimals = 0 ) {
	if ( 0 === $bytes ) { return number_format( 0, $decimals ) . ' B'; }
	foreach ( array( 'GB' => 1073741824, 'MB' => MB_IN_BYTES, 'KB' => KB_IN_BYTES, 'B' => 1 ) as $unit => $mag ) {
		if ( (float) $bytes >= $mag ) { return number_format( $bytes / $mag, $decimals ) . ' ' . $unit; }
	}
	return false;
}
function wp_rand( $a = 0, $b = 0 ) { return random_int( $a, $b ); }
function trailingslashit( $p ) { return rtrim( $p, '/' ) . '/'; }
function wp_mkdir_p( $p ) { return is_dir( $p ) || mkdir( $p, 0777, true ); }
// Mirrors WordPress: flags and depth are passed through to json_encode.
function wp_json_encode( $v, $options = 0, $depth = 512 ) { return json_encode( $v, $options, $depth ); }
// One folder per run, unique, and gone when the run ends.
function ab_test_rm_tree( $dir ) {
	if ( 0 !== strpos( (string) $dir, sys_get_temp_dir() . '/ab-' ) || ! is_dir( $dir ) || is_link( $dir ) ) {
		return;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		( $f->isDir() && ! $f->isLink() ) ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
	}
	@rmdir( $dir );
}
$GLOBALS['ab_upload'] = sys_get_temp_dir() . '/ab-free-undo-test-' . getmypid() . '-' . bin2hex( random_bytes( 6 ) );
$GLOBALS['ab_upload_root'] = $GLOBALS['ab_upload'];
register_shutdown_function(
	static function () {
		ab_test_rm_tree( $GLOBALS['ab_upload_root'] );
		@unlink( $GLOBALS['ab_upload_root'] . '-datei' );
	}
);
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['ab_upload'], 'baseurl' => 'http://x/u', 'error' => false ); }

/* ---- wpdb for option_row() and the posts table ---- */
$GLOBALS['ab_dberror']  = array();   // option name => the query runs and errors
$GLOBALS['ab_dbdown']   = array();   // option name => the query never runs at all
$GLOBALS['ab_dbsloppy'] = array();   // option name => a drop-in that reports an error but not false
$GLOBALS['wpdb'] = new class {
	public $options     = 'wp_options';
	public $posts       = 'wp_posts';
	public $last_error  = '';
	public $last_result = array();
	public $last        = array();
	public function prepare( $q, ...$args ) { $this->last = $args; return $q; }
	public function query( $q ) {
		$name              = isset( $this->last[0] ) ? $this->last[0] : '';
		$this->last_result = array();
		// Mirrors WordPress: a connection that went away and cannot be
		// re-established returns false from query() with last_error STILL EMPTY.
		if ( isset( $GLOBALS['ab_dbdown'][ $name ] ) ) { $this->last_error = ''; return false; }
		if ( isset( $GLOBALS['ab_dberror'][ $name ] ) ) { $this->last_error = 'MySQL server has gone away'; return false; }
		$this->last_error = isset( $GLOBALS['ab_dbsloppy'][ $name ] ) ? 'Lost connection during query' : '';
		$stored = ab_row_name( $name );
		if ( null !== $stored && array_key_exists( $stored, $GLOBALS['ab_autoload'] ) ) {
			$this->last_result = array( (object) array( 'option_name' => $stored, 'autoload' => $GLOBALS['ab_autoload'][ $stored ] ) );
		}
		return count( $this->last_result );
	}
	public function get_row( $q, $output = null, $y = 0 ) {
		// The posts table, for the restore's read-back: the row as get_post() has it.
		if ( false !== strpos( (string) $q, $this->posts ) ) {
			$p = get_post( isset( $this->last[0] ) ? (int) $this->last[0] : 0 );
			return $p ? get_object_vars( $p ) : null;
		}
		if ( false === $this->query( $q ) ) { return null; }
		return isset( $this->last_result[ $y ] ) ? $this->last_result[ $y ] : null;
	}
	public function update( $table, $data, $where ) { return 1; }
};

/* ---- the rest of the site the restore path asks ---- */
$GLOBALS['ab_scope']     = 'full';
$GLOBALS['ab_toolstate'] = array();
class AB_MCP_Settings {
	// As the core reads the switches: on until switched off.
	public static function is_tool_enabled( $name, $def ) {
		if ( array_key_exists( $name, $GLOBALS['ab_toolstate'] ) ) { return (bool) $GLOBALS['ab_toolstate'][ $name ]; }
		return true;
	}
}
class AB_MCP_Auth {
	public static function current_scope() { return $GLOBALS['ab_scope']; }
}

$GLOBALS['ab_posts']      = array();  // id => status
$GLOBALS['ab_post_types'] = array();  // id => post type, default "post"
/* The post as a row: its status from ab_posts, dated long ago. */
function get_post( $id ) {
	$id = (int) $id;
	if ( ! isset( $GLOBALS['ab_posts'][ $id ] ) ) { return null; }
	return (object) array( 'ID' => $id, 'post_status' => $GLOBALS['ab_posts'][ $id ], 'post_type' => $GLOBALS['ab_post_types'][ $id ] ?? 'post', 'post_date' => '2001-01-01 10:00:00', 'post_date_gmt' => '2001-01-01 09:00:00', 'post_title' => '', 'post_content' => '', 'post_excerpt' => '', 'post_name' => '', 'post_parent' => 0, 'post_author' => 1, 'post_password' => '' );
}
function get_permalink( $id ) { return 'http://x/?p=' . (int) $id; }
function wp_slash( $v ) { return $v; }
function get_post_type( $id ) { return $GLOBALS['ab_post_types'][ (int) $id ] ?? 'post'; }
function get_post_type_object( $t ) { return (object) array( 'cap' => (object) array( 'publish_posts' => 'publish_posts', 'edit_others_posts' => 'edit_others_posts' ) ); }
function wp_update_post( $a, $err = false ) { if ( isset( $a['post_status'] ) ) { $GLOBALS['ab_posts'][ (int) $a['ID'] ] = $a['post_status']; } return (int) $a['ID']; }
function wp_trash_post( $id ) { $GLOBALS['ab_posts'][ (int) $id ] = 'trash'; return true; }
/* The statuses this site knows: the restore planner refuses a status WordPress has not registered. */
function get_post_stati( $args = array(), $output = 'names' ) { $k = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'auto-draft', 'inherit' ); return array_combine( $k, $k ); }
/* filters with priorities: what wp_untrash_post_status runs through */
$GLOBALS['ab_hooked'] = array();
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['ab_hooked'][ $tag ][ $prio ][] = $cb; return true; }
function remove_filter( $tag, $cb, $prio = 10 ) { foreach ( $GLOBALS['ab_hooked'][ $tag ][ $prio ] ?? array() as $i => $c ) { if ( $c === $cb ) { unset( $GLOBALS['ab_hooked'][ $tag ][ $prio ][ $i ] ); return true; } } return false; }
function ab_run_hooked( $tag, $value, ...$args ) { $all = $GLOBALS['ab_hooked'][ $tag ] ?? array(); ksort( $all ); foreach ( $all as $cbs ) { foreach ( $cbs as $cb ) { $value = $cb( $value, ...$args ); } } return $value; }
/* "draft", unless a filter gives another: wp_untrash_post_set_previous_status() gives back the status from before the trash */
$GLOBALS['ab_untrash_previous'] = null;
$GLOBALS['ab_untrash_stored']   = array();
function wp_untrash_post( $id ) {
	$new = ab_run_hooked( 'wp_untrash_post_status', null !== $GLOBALS['ab_untrash_previous'] ? $GLOBALS['ab_untrash_previous'] : 'draft', (int) $id, (string) $GLOBALS['ab_untrash_previous'] );
	$GLOBALS['ab_untrash_stored'][]   = $new;
	$GLOBALS['ab_posts'][ (int) $id ] = $new;
	return true;
}
$GLOBALS['ab_meta_store'] = array();
function delete_post_meta( $id, $key ) { unset( $GLOBALS['ab_meta_store'][ (int) $id ][ $key ] ); return true; }
function get_post_meta( $id, $key = '', $single = false ) { $v = $GLOBALS['ab_meta_store'][ (int) $id ][ $key ] ?? array(); return $single ? ( $v ? $v[0] : '' ) : $v; }
function add_post_meta( $id, $key, $value, $unique = false ) { $GLOBALS['ab_meta_store'][ (int) $id ][ $key ][] = $value; return true; }

/* The REAL files of this plugin: no lifted copies, so the test cannot drift from them. */
require $GLOBALS['ab_free'] . 'class-tool-registry.php';
require $GLOBALS['ab_free'] . 'class-guidance.php';
require $GLOBALS['ab_free'] . 'class-post-status.php';
require $GLOBALS['ab_free'] . 'class-page-cache.php';
require $GLOBALS['ab_free'] . 'tools/class-tools-base.php';
require $GLOBALS['ab_free'] . 'tools/class-tools-options-settings.php';
require $GLOBALS['ab_free'] . 'tools/class-tools-undo-core.php';
require $GLOBALS['ab_free'] . 'undo/class-undo.php';
require $GLOBALS['ab_free'] . 'undo/class-undo-restore.php';

/* A door to the error code of each restored item: apply() reports a failed
 * item by its message only, and the messages name a way since 4.9.0. */
class AB_Test_Restore extends AB_MCP_Undo_Restore {
	public static $codes = array();
	protected static function restore_post_fields( array $item ) {
		$r = parent::restore_post_fields( $item );
		self::$codes[] = is_wp_error( $r ) ? $r->get_error_code() : true;
		return $r;
	}
	protected static function restore_setting( array $item ) {
		$r = parent::restore_setting( $item );
		self::$codes[] = is_wp_error( $r ) ? $r->get_error_code() : true;
		return $r;
	}
}

$pass = 0;
$fail = 0;
function ok( $c, $label ) {
	global $pass, $fail;
	if ( $c ) { $pass++; } else { $fail++; echo "  FAIL: $label\n"; }
}
function ab_code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ''; }
// The message, or '' for anything that is no error: a check must fail, not stop the run.
function ab_msg( $r ) { return is_wp_error( $r ) ? (string) $r->get_error_message() : ''; }

// A point of this plugin's own: wp_update_site_settings, inline when small.
function ab_set_rec( $name, $value = 'v' ) {
	return array( 'tool' => 'wp_update_site_settings', 'cap' => 'manage_options', 'kind' => 'site_settings', 'summary' => $name, 'items' => 1, 'data' => array( $name => $value ) );
}
// A point of Pro's in the same store: its file tool, always a payload file.
function ab_pro_file_rec( $rel, $bytes ) {
	return array( 'tool' => 'wp_write_plugin_file', 'cap' => 'edit_plugins', 'kind' => 'file', 'summary' => $rel, 'items' => 1, 'data' => array( 'jail' => 'plugin', 'slug' => 'foo', 'rel' => $rel, 'existed' => true, 'bytes' => $bytes ) );
}
// One of Pro's option points, inline.
function ab_pro_opt_rec( $name, $value = 1 ) {
	return array( 'tool' => 'wp_update_option', 'cap' => 'manage_options', 'kind' => 'option', 'summary' => $name, 'items' => 1, 'data' => array( 'name' => $name, 'value' => $value, 'autoload' => 'yes', 'existed' => true ) );
}
function ab_entry_of( $token ) {
	foreach ( get_option( AB_MCP_Undo::OPTION, array() ) as $e ) {
		if ( $e['token'] === $token ) { return $e; }
	}
	return null;
}
function ab_patch_entry( $token, callable $fn ) {
	$idx = get_option( AB_MCP_Undo::OPTION, array() );
	foreach ( $idx as $i => $e ) {
		if ( $e['token'] === $token ) { $idx[ $i ] = $fn( $e ); }
	}
	update_option( AB_MCP_Undo::OPTION, $idx );
}
function ab_bin_files() {
	$d = AB_MCP_Undo::dir();
	return is_wp_error( $d ) ? array() : glob( rtrim( $d, '/' ) . '/*.bin' );
}
// The files the index points at; an orphan from a section before does not count.
function ab_indexed_files() {
	$out = array();
	foreach ( get_option( AB_MCP_Undo::OPTION, array() ) as $e ) {
		if ( ! empty( $e['payload'] ) ) { $out[] = rtrim( AB_MCP_Undo::dir(), '/' ) . '/' . $e['payload']; }
		foreach ( $e['parts'] ?? array() as $part ) { $out[] = rtrim( AB_MCP_Undo::dir(), '/' ) . '/' . $part['payload']; }
	}
	return $out;
}
function ab_tokens( $list ) { return is_wp_error( $list ) ? array( 'error' ) : array_map( function ( $e ) { return $e['token']; }, $list ); }

/* ================================================= A) tokens + round-trip */

ok( AB_MCP_Undo::valid_token( 'undo_' . str_repeat( 'a', 32 ) ), 'A1 accepts a well-formed token' );
ok( ! AB_MCP_Undo::valid_token( 'undo_short' ), 'A1 rejects a malformed token' );
ok( ! AB_MCP_Undo::valid_token( 'undo_' . str_repeat( 'Z', 32 ) ), 'A1 rejects non-hex' );

$t1 = AB_MCP_Undo::record( array(
	'tool'    => 'wp_bulk_update_status',
	'cap'     => 'edit_others_posts',
	'kind'    => 'post_status',
	'summary' => '3 posts',
	'items'   => 3,
	'data'    => array( array( 10, 'publish' ), array( 11, 'draft' ), array( 12, 'pending' ) ),
) );
ok( AB_MCP_Undo::valid_token( $t1 ), 'A2 record returns a token' );
$c = AB_MCP_Undo::claim( $t1 );
ok( ! is_wp_error( $c ), 'A3 owner can claim' );
ok( is_array( $c['data'] ) && 3 === count( $c['data'] ) && 'draft' === $c['data'][1][1], 'A3 snapshot round-trips exactly' );
$dir = AB_MCP_Undo::dir();
ok( is_string( $dir ) && empty( glob( rtrim( $dir, '/' ) . '/*.bin' ) ), 'A4 a small db snapshot is stored inline, no payload file' );
ok( is_file( rtrim( $dir, '/' ) . '/index.php' ) && "Require all denied\n" === file_get_contents( rtrim( $dir, '/' ) . '/.htaccess' ), 'A5 the snapshot folder is protected (index.php and a deny .htaccess), the folder Pro uses: ' . AB_MCP_Undo::SUBDIR );
ok( 'ab_mcp_pro_undo_index' === AB_MCP_Undo::OPTION && 'ab-mcp-undo' === AB_MCP_Undo::SUBDIR && 'ab_mcp_pro_undo_ttl_days' === AB_MCP_Undo::TTL_OPTION, 'A6 option, folder and window keep the names Pro gave them: one store for both' );

/* ================================================= B) ownership */

$GLOBALS['ab_uid'] = 99;
$c2 = AB_MCP_Undo::claim( $t1 );
ok( 'ab_mcp_undo_not_found' === ab_code( $c2 ), 'B1 another user cannot claim (looks like not found)' );
ok( 'ab_mcp_undo_not_found' === ab_code( AB_MCP_Undo::discard( $t1 ) ), 'B2 another user cannot discard' );
ok( 'ab_mcp_undo_not_found' === ab_code( AB_MCP_Undo::entry_for( $t1 ) ), 'B2 nor read its metadata' );
ok( 'ab_mcp_undo_not_found' === ab_code( AB_MCP_Undo::begin_apply( $t1 ) ), 'B2 nor reserve it' );
ok( ! in_array( $t1, ab_tokens( AB_MCP_Undo::entries_for( 50 ) ), true ), 'B2 nor see it in the list' );
$GLOBALS['ab_uid'] = 7;
ok( ! is_wp_error( AB_MCP_Undo::claim( $t1 ) ), 'B3 owner can still claim' );

/* ================================================= C) file payloads + integrity (a Pro point) */

$GLOBALS['ab_filters']['ab_mcp_undo_inline_max'] = 8; // force a file
$t2 = AB_MCP_Undo::record( ab_pro_file_rec( 'x.php', str_repeat( 'CONTENT', 50 ) ) );
ok( AB_MCP_Undo::valid_token( $t2 ), 'C1 a point of Pro\'s file tool records in the shared store' );
$files = glob( rtrim( $dir, '/' ) . '/*.bin' );
ok( 1 === count( $files ), 'C1 and a payload file is written' );
$c3 = AB_MCP_Undo::claim( $t2 );
ok( ! is_wp_error( $c3 ) && str_repeat( 'CONTENT', 50 ) === $c3['data']['bytes'], 'C2 the file snapshot round-trips' );
file_put_contents( $files[0], 'tampered' );
ok( 'ab_mcp_undo_corrupt' === ab_code( AB_MCP_Undo::claim( $t2 ) ), 'C3 a tampered snapshot fails its integrity check' );
unset( $GLOBALS['ab_filters']['ab_mcp_undo_inline_max'] );

$GLOBALS['ab_filters']['ab_mcp_undo_snapshot_max'] = 10;
ok( null === AB_MCP_Undo::record( ab_pro_file_rec( 'big', str_repeat( 'z', 100 ) ) ), 'C4 a snapshot over the cap yields no token' );
unset( $GLOBALS['ab_filters']['ab_mcp_undo_snapshot_max'] );
ok( null === AB_MCP_Undo::record( array( 'kind' => 'site_settings', 'data' => array( 'x' => new stdClass() ) ) ), 'C5 a non-scalar snapshot yields no token' );

/* ================================================= D) expiry + ring buffer */

$t_exp = AB_MCP_Undo::record( ab_set_rec( 'blogname', 'foo' ) );
ab_patch_entry( $t_exp, function ( $e ) { $e['expires_at'] = time() - 5; return $e; } );
ok( 'ab_mcp_undo_expired' === ab_code( AB_MCP_Undo::claim( $t_exp ) ), 'D1 an expired point cannot be claimed' );
ok( AB_MCP_Undo::gc() >= 1, 'D1 gc removes expired points' );
ok( 'ab_mcp_undo_not_found' === ab_code( AB_MCP_Undo::claim( $t_exp ) ), 'D1 and it is gone afterwards' );

$GLOBALS['ab_filters']['ab_mcp_undo_max_entries'] = 3;
$tokens = array();
for ( $i = 0; $i < 6; $i++ ) {
	$tokens[] = AB_MCP_Undo::record( ab_set_rec( 'blogname', "v$i" ) );
}
ok( 3 === count( AB_MCP_Undo::entries_for( 50 ) ), 'D2 ring buffer keeps only the newest N' );
ok( is_wp_error( AB_MCP_Undo::claim( $tokens[0] ) ), 'D2 the oldest points were dropped' );
ok( ! is_wp_error( AB_MCP_Undo::claim( $tokens[5] ) ), 'D2 the newest point survives' );
unset( $GLOBALS['ab_filters']['ab_mcp_undo_max_entries'] );

$leaks = false;
foreach ( AB_MCP_Undo::entries_for( 50 ) as $row ) {
	if ( isset( $row['inline'] ) || isset( $row['data'] ) || isset( $row['payload'] ) || isset( $row['sha256'] ) || isset( $row['user'] ) ) { $leaks = true; }
}
ok( ! $leaks, 'D3 the list exposes only metadata, never the snapshot' );
ok( 1 === count( AB_MCP_Undo::entries_for( 1 ) ) && 1 === count( AB_MCP_Undo::entries_for( 0 ) ) && 3 === count( AB_MCP_Undo::entries_for( 999 ) ), 'D3 the list limit is clamped to 1..50' );
ok( 3 === AB_MCP_Tools_Undo_Core::undo_list( array( 'limit' => 0 ) )['count'], 'D3 the tool reads a limit of 0 as its default' );
ok( array( $tokens[5], $tokens[4], $tokens[3] ) === ab_tokens( AB_MCP_Undo::entries_for( 50 ) ), 'D3 newest first' );

$GLOBALS['ab_filters']['ab_mcp_undo_inline_max'] = 4;
$t_d     = AB_MCP_Undo::record( ab_pro_file_rec( 'y', str_repeat( 'Q', 40 ) ) );
$fbefore = count( glob( rtrim( $dir, '/' ) . '/*.bin' ) );
ok( true === AB_MCP_Undo::discard( $t_d ), 'D4 owner discards a point' );
ok( count( glob( rtrim( $dir, '/' ) . '/*.bin' ) ) === $fbefore - 1, 'D4 and its payload file is deleted' );
ok( is_wp_error( AB_MCP_Undo::claim( $t_d ) ), 'D4 and it can no longer be claimed' );
unset( $GLOBALS['ab_filters']['ab_mcp_undo_inline_max'] );

AB_MCP_Undo::clear_all();
ok( empty( AB_MCP_Undo::entries_for( 50 ) ), 'D5 clear_all empties the index' );
ok( empty( glob( rtrim( $dir, '/' ) . '/*.bin' ) ), 'D5 and removes all payload files' );

/* ============================== E) Envelope: JSON statt serialize */

ok( null === AB_MCP_Undo::decode( 'O:8:"stdClass":1:{s:1:"a";s:3:"pwn";}' ), 'E1 a serialized PHP object is not decodable at all' );
ok( null === AB_MCP_Undo::decode( '{"v":2,"data":[]}' ), 'E2 an unknown envelope version is refused' );
ok( null === AB_MCP_Undo::decode( 'not json' ), 'E2 garbage is refused' );
$binary = "\x00\x01\x02PK\x03\x04\xff\xfe";
$enc    = AB_MCP_Undo::encode( array( 'rel' => 'x.bin', 'bytes' => $binary ) );
ok( is_string( $enc ), 'E3 binary bytes can be encoded' );
$dec = AB_MCP_Undo::decode( $enc );
ok( is_array( $dec ) && $binary === $dec['bytes'], 'E3 and round-trip byte for byte' );
$f_dec = AB_MCP_Undo::decode( AB_MCP_Undo::encode( array( 'name' => 'x', 'value' => array( 5.0 ), 'existed' => true ) ) );
ok( is_array( $f_dec ) && array( 5.0 ) === $f_dec['value'], 'E4 a float inside an array keeps its type through the snapshot' );
$i_dec = AB_MCP_Undo::decode( AB_MCP_Undo::encode( array( 'value' => array( 5, 'a' => '5' ) ) ) );
ok( is_array( $i_dec ) && array( 5, 'a' => '5' ) === $i_dec['value'], 'E4 while integers and strings are untouched' );

// The whole way for a kind of this plugin: record, claim, write back.
$GLOBALS['ab_opts']['blogname']     = 'Neu';
$GLOBALS['ab_autoload']['blogname'] = 'yes';
$GLOBALS['ab_caps']                 = array( 'manage_options' => true );
$tok_f = AB_MCP_Undo::record( ab_set_rec( 'blogname', 'Alt' ) );
$cf    = AB_MCP_Undo::claim( $tok_f );
ok( ! is_wp_error( $cf ) && array( 'blogname' => 'Alt' ) === $cf['data'], 'E5 a site_settings snapshot round-trips' );
$rf = AB_MCP_Undo_Restore::apply( $cf['entry'], $cf['data'] );
ok( is_array( $rf ) && 1 === $rf['undone'] && 'Alt' === get_option( 'blogname' ), 'E5 and the option really holds the old value again' );
AB_MCP_Undo::discard( $tok_f );

/* ============================== F) strikte Entry-Validierung */

ok( ! AB_MCP_Undo::valid_entry( array( 'token' => 'nope' ) ), 'F1 an incomplete entry is invalid' );
$good = array(
	'token' => 'undo_' . str_repeat( 'a', 32 ), 'tool' => 't', 'cap' => 'c', 'kind' => 'option',
	'summary' => 's', 'items' => 1, 'bytes' => 2, 'user' => 3, 'created_at' => 4, 'expires_at' => 5,
	'inline' => '{"v":1,"data":[]}', 'payload' => null,
);
ok( AB_MCP_Undo::valid_entry( $good ), 'F2 a well-formed inline entry is valid' );
$both = $good; $both['payload'] = str_repeat( 'a', 40 ) . '.bin';
ok( ! AB_MCP_Undo::valid_entry( $both ), 'F3 an entry with BOTH inline and payload is refused' );
$neither = $good; $neither['inline'] = null;
ok( ! AB_MCP_Undo::valid_entry( $neither ), 'F3 an entry with NEITHER is refused' );
$badfile = $good; $badfile['inline'] = null; $badfile['payload'] = '../../evil';
ok( ! AB_MCP_Undo::valid_entry( $badfile ), 'F4 a payload name that is not a hash is refused' );
$nohash = $good; $nohash['inline'] = null; $nohash['payload'] = str_repeat( 'a', 40 ) . '.bin';
ok( ! AB_MCP_Undo::valid_entry( $nohash ), 'F4 a payload without its sha256 is refused' );
update_option( AB_MCP_Undo::OPTION, array( array( 'token' => 'undo_' . str_repeat( 'b', 32 ), 'payload' => '../../../wp-config.php' ) ) );
ok( is_wp_error( AB_MCP_Undo::claim( 'undo_' . str_repeat( 'b', 32 ) ) ), 'F5 a malformed index entry is dropped, not followed' );
// Parts, as Pro writes them for its page-builder undo: only on builder_data, each with its hash.
$pe            = $good;
$pe['inline']  = null;
$pe['payload'] = str_repeat( 'a', 40 ) . '.bin';
$pe['sha256']  = str_repeat( '0', 64 );
$pe['kind']    = 'builder_data';
$pe['parts']   = array( array( 'payload' => str_repeat( 'b', 40 ) . '.bin', 'sha256' => str_repeat( '1', 64 ), 'bytes' => 10, 'stored' => 9 ) );
ok( AB_MCP_Undo::valid_entry( $pe ), 'F6 a builder_data entry with parts, as Pro writes it, is valid here' );
$pe_kind = $pe; $pe_kind['kind'] = 'site_settings';
ok( ! AB_MCP_Undo::valid_entry( $pe_kind ), 'F6 parts on another kind are refused' );
$pe_sha = $pe; unset( $pe_sha['parts'][0]['sha256'] );
ok( ! AB_MCP_Undo::valid_entry( $pe_sha ), 'F6 a part without its hash is refused' );
$pe_many = $pe; $pe_many['parts'] = array_fill( 0, AB_MCP_Undo::MAX_PARTS + 1, $pe['parts'][0] );
ok( ! AB_MCP_Undo::valid_entry( $pe_many ), 'F6 more parts than MAX_PARTS are refused' );
$pe_after = $pe; $pe_after['after'] = array( 5 => array( 'meta:_elementor_data' => str_repeat( 'c', 64 ) ) );
ok( AB_MCP_Undo::valid_entry( $pe_after ), 'F7 the state after the change Pro keeps per post is accepted' );
$pe_after['after'] = array( 5 => array( 'meta:x' => 'kurz' ) );
ok( ! AB_MCP_Undo::valid_entry( $pe_after ), 'F7 but only with a sha256 per copy' );
$GLOBALS['ab_opts'][ AB_MCP_Undo::OPTION ] = array();

/* ============================== G) Restore: Policy und Rechte */

$GLOBALS['ab_caps'] = array();
$entry_bulk = array( 'tool' => 'wp_bulk_update_status', 'kind' => 'post_status', 'cap' => 'edit_others_posts' );
$entry_set  = array( 'tool' => 'wp_update_site_settings', 'kind' => 'site_settings', 'cap' => 'manage_options' );
ok( 'ab_mcp_undo_forbidden' === ab_code( AB_MCP_Undo_Restore::plan( $entry_bulk, array( array( 1, 'draft' ) ) ) ), 'G1 restore needs the original tool capability' );
ok( 'ab_mcp_undo_forbidden' === ab_code( AB_MCP_Undo_Restore::plan( $entry_set, array( 'blogname' => 'x' ) ) ), 'G1 also for the site settings (manage_options)' );
$g1m = AB_MCP_Undo_Restore::plan( $entry_set, array( 'blogname' => 'x' ) );
ok( false !== strpos( ab_msg( $g1m ), 'manage_options' ) && false !== strpos( ab_msg( $g1m ), 'users.php' ), 'G1 the refusal names the permission and the way (Users)' );
ok( 'ab_mcp_undo_unknown_tool' === ab_code( AB_MCP_Undo_Restore::plan( array( 'tool' => 'wp_made_up', 'kind' => 'sql' ), array() ) ), 'G2 a tool nobody has is refused as unknown' );
ok( 'ab_mcp_undo_unknown_tool' === ab_code( AB_MCP_Undo_Restore::plan( array( 'tool' => '', 'kind' => 'post_fields' ), array() ) ), 'G2 an empty tool name too' );
$GLOBALS['ab_caps']['manage_options'] = true;
ok( 'ab_mcp_undo_kind_mismatch' === ab_code( AB_MCP_Undo_Restore::plan( array( 'tool' => 'wp_update_site_settings', 'kind' => 'option' ), array( 'blogname' => 'x' ) ) ), 'G3 a kind that does not match its tool is refused' );
ok( 'ab_mcp_undo_kind_mismatch' === ab_code( AB_MCP_Undo_Restore::plan( array( 'tool' => 'wp_update_post', 'kind' => 'post_status' ), array( array( 1, 'draft' ) ) ) ), 'G3 also between this plugin\'s own kinds' );
ok( array( 'wp_bulk_update_status', 'wp_update_site_settings', 'wp_update_post' ) === array_keys( AB_MCP_Undo_Restore::policy() ), 'G4 the policy names exactly the three tools of this plugin' );

/* ============================== H) post_status: Kaskade pro Objekt (the real AB_MCP_Post_Status) */

$GLOBALS['ab_caps']  = array( 'edit_others_posts' => true, 'edit_post:10' => true, 'edit_post:11' => false, 'delete_post:12' => true );
$GLOBALS['ab_posts'] = array( 10 => 'draft', 11 => 'draft', 12 => 'draft' );
$plan = AB_MCP_Undo_Restore::plan( $entry_bulk, array( array( 10, 'pending' ), array( 11, 'pending' ), array( 12, 'trash' ), array( 99, 'draft' ) ) );
ok( ! is_wp_error( $plan ), 'H1 plan builds' );
ok( 2 === $plan['restorable'], 'H1 only the permitted posts are restorable' );
$refs = array_map( function ( $i ) { return $i['ref']; }, $plan['items'] );
ok( in_array( 10, $refs, true ) && in_array( 12, $refs, true ), 'H1 posts 10 and 12 are in' );
$blocked = array_column( $plan['blocked'], 'reason', 'ref' );
ok( 'forbidden' === ( $blocked[11] ?? '' ), 'H2 post 11 is blocked (no edit_post)' );
ok( 'gone' === ( $blocked[99] ?? '' ), 'H2 a post that is gone is blocked, not silently skipped' );
$GLOBALS['ab_caps']['publish_posts'] = false;
$plan_pub = AB_MCP_Undo_Restore::plan( $entry_bulk, array( array( 10, 'publish' ) ) );
ok( 0 === $plan_pub['restorable'] && 'forbidden' === $plan_pub['blocked'][0]['reason'], 'H3 publishing also needs publish_posts' );
$plan_priv = AB_MCP_Undo_Restore::plan( $entry_bulk, array( array( 10, 'private' ) ) );
ok( 0 === $plan_priv['restorable'], 'H3 and so does private' );
$GLOBALS['ab_caps']['publish_posts'] = true;
$res = AB_MCP_Undo_Restore::apply( $entry_bulk, array( array( 10, 'publish' ), array( 12, 'trash' ) ) );
ok( 2 === $res['undone'] && array( 10, 12 ) === $res['restored'], 'H4 apply restores both' );
ok( 'publish' === $GLOBALS['ab_posts'][10] && 'trash' === $GLOBALS['ab_posts'][12], 'H4 statuses are actually back' );
ok( 'post_status' === $res['kind'] && 'wp_bulk_update_status' === $res['tool'], 'H4 the answer names kind and tool' );
$GLOBALS['ab_posts']            = array( 10 => 'trash' );
$GLOBALS['ab_untrash_previous'] = 'publish';
$GLOBALS['ab_untrash_stored']   = array();
$res = AB_MCP_Undo_Restore::apply( $entry_bulk, array( array( 10, 'pending' ) ) );
ok( 1 === $res['undone'] && array( 'draft' ) === $GLOBALS['ab_untrash_stored'] && 'pending' === $GLOBALS['ab_posts'][10], 'H5 a post taken out of the trash passes through "draft", not through the status the site would give back' );
ok( empty( $GLOBALS['ab_hooked']['wp_untrash_post_status'][ PHP_INT_MAX ] ), 'H5 and the filter that sees to it is gone again' );
$GLOBALS['ab_untrash_previous'] = null;
$GLOBALS['ab_posts']            = array( 10 => 'draft' );
$GLOBALS['ab_meta_store'][10]   = array( '_wp_trash_meta_status' => array( 'publish' ), '_wp_trash_meta_time' => array( '1700000000' ) );
$GLOBALS['ab_caps']['delete_post:10'] = true;
$res = AB_MCP_Undo_Restore::apply( $entry_bulk, array( array( 10, 'trash' ) ) );
ok( 1 === $res['undone'] && 'trash' === $GLOBALS['ab_posts'][10] && ! isset( $GLOBALS['ab_meta_store'][10]['_wp_trash_meta_status'] ), 'H6 back into the trash through AB_MCP_Post_Status::trash_post(), old trash notes cleared first' );
// A pair in a shape the tool does not write: skipped, or the whole point refused.
$GLOBALS['ab_posts'] = array( 10 => 'draft' );
$h7 = AB_MCP_Undo_Restore::plan( $entry_bulk, array( array( 10 ), array( 10, 'pending', 'password_protected', 'x' ), 'kaputt' ) );
ok( ! is_wp_error( $h7 ) && 0 === $h7['restorable'] && array() === $h7['blocked'], 'H7 pairs of another length are not restored' );
ok( 'ab_mcp_undo_corrupt' === ab_code( AB_MCP_Undo_Restore::plan( $entry_bulk, array( array( 10, 'pending', 'dropped' ) ) ) ), 'H7 a third entry other than the password note refuses the point' );
ok( 'ab_mcp_undo_corrupt' === ab_code( AB_MCP_Undo_Restore::plan( $entry_bulk, array( array( 10, 'PENDING' ) ) ) ), 'H7 a status WordPress would rewrite refuses the point' );
ok( 'ab_mcp_undo_corrupt' === ab_code( AB_MCP_Undo_Restore::plan( $entry_bulk, 'kaputt' ) ), 'H7 a snapshot that is no list is refused' );
$h8 = AB_MCP_Undo_Restore::plan( $entry_bulk, array( array( 10, 'unregistered' ) ) );
ok( ! is_wp_error( $h8 ) && 'unknown_status' === ( $h8['blocked'][0]['reason'] ?? '' ), 'H8 a status this site does not know is not restored on a guess' );

/* ============================== I) site_settings: nur die fuenf, jeder Wert gelesen */

$GLOBALS['ab_caps'] = array( 'manage_options' => true );
ok( array( 'blogname', 'blogdescription', 'admin_email', 'timezone_string', 'posts_per_page' ) === AB_MCP_Undo_Restore::SITE_SETTINGS, 'I1 the five options wp_update_site_settings writes' );
$pi = AB_MCP_Undo_Restore::plan( $entry_set, array( 'blogname' => 'Alt', 'siteurl' => 'http://evil.test', 'ab_mcp_tokens' => array(), 'BLOGNAME' => 'x', ' blogdescription' => 'y', 'active_plugins' => array( 'evil/evil.php' ) ) );
$pib = is_wp_error( $pi ) ? array() : array_column( $pi['blocked'], 'reason', 'ref' );
ok( ! is_wp_error( $pi ) && 1 === $pi['restorable'] && 'blogname' === $pi['items'][0]['ref'], 'I1 of a hand-edited point only the option of the tool is planned' );
ok( array( 'siteurl' => 'protected_now', 'ab_mcp_tokens' => 'protected_now', 'BLOGNAME' => 'protected_now', ' blogdescription' => 'protected_now', 'active_plugins' => 'protected_now' ) === $pib, 'I1 every other row is blocked as protected_now — another spelling of a name included' );
$GLOBALS['ab_opts']['siteurl']     = 'http://site.test';
$GLOBALS['ab_autoload']['siteurl'] = 'yes';
$GLOBALS['ab_opts']['blogname']    = 'Neu';
$ri = AB_MCP_Undo_Restore::apply( $entry_set, array( 'blogname' => 'Alt', 'siteurl' => 'http://evil.test' ) );
ok( 1 === $ri['undone'] && 'Alt' === get_option( 'blogname' ) && 'http://site.test' === get_option( 'siteurl' ), 'I2 apply writes the setting back and leaves the protected row alone' );
ok( 'site_settings' === $ri['kind'] && 1 === count( $ri['blocked'] ), 'I2 and reports what it left out' );
foreach ( array( 'not an array' => 'kaputt', 'empty' => array(), 'nameless' => array( '' => 'x' ) ) as $why => $bad ) {
	ok( 'ab_mcp_undo_corrupt' === ab_code( AB_MCP_Undo_Restore::plan( $entry_set, $bad ) ), "I3 a snapshot that is $why is refused" );
}
// The setting is gone: added again.
unset( $GLOBALS['ab_opts']['blogdescription'], $GLOBALS['ab_autoload']['blogdescription'] );
AB_Test_Restore::$codes = array();
$ri4 = AB_Test_Restore::apply( $entry_set, array( 'blogdescription' => 'Tag alt' ) );
ok( 1 === $ri4['undone'] && 'Tag alt' === get_option( 'blogdescription' ) && array( true ) === AB_Test_Restore::$codes, 'I4 a setting that is missing is added again' );
// A filter that rewrites the value: failed, not restored.
$GLOBALS['ab_opts']['blogname']     = 'Neu';
$GLOBALS['ab_optfilter']['blogname'] = function ( $v, $old ) { return 'etwas-anderes'; };
AB_Test_Restore::$codes = array();
$ri5 = AB_Test_Restore::apply( $entry_set, array( 'blogname' => 'Alt' ) );
ok( 0 === $ri5['undone'] && 1 === count( $ri5['failed'] ) && array( 'ab_mcp_undo_option_failed' ) === AB_Test_Restore::$codes, 'I5 a filter that rewrites the value makes the restore fail, not succeed' );
ok( 'blogname' === $ri5['failed'][0]['ref'] && false !== strpos( $ri5['failed'][0]['reason'], 'wp_get_site_settings' ), 'I5 the failure names the setting and the way to look' );
$GLOBALS['ab_optfilter']['blogname'] = function ( $v, $old ) { return $old; };
AB_Test_Restore::$codes = array();
$ri6 = AB_Test_Restore::apply( $entry_set, array( 'blogname' => 'Alt' ) );
ok( 0 === $ri6['undone'] && array( 'ab_mcp_undo_option_failed' ) === AB_Test_Restore::$codes && 'etwas-anderes' === get_option( 'blogname' ), 'I6 a filter that rejects the write is failed too, and the option keeps what the site holds' );
unset( $GLOBALS['ab_optfilter']['blogname'] );
// A hook that deletes the row right after the write: gone is failed.
$GLOBALS['ab_optafter']['blogname'] = function ( $k ) { delete_option( $k ); };
AB_Test_Restore::$codes = array();
$ri7 = AB_Test_Restore::apply( $entry_set, array( 'blogname' => 'Alt' ) );
ok( 0 === $ri7['undone'] && array( 'ab_mcp_undo_option_failed' ) === AB_Test_Restore::$codes, 'I7 a row a hook deletes again is failed, not restored' );
// ... also when a default filter answers for it with the very value asked for.
$GLOBALS['ab_opts']['blogname']     = 'Neu';
$GLOBALS['ab_autoload']['blogname'] = 'yes';
$GLOBALS['ab_optdefault']['blogname'] = 'Alt';
AB_Test_Restore::$codes = array();
$ri8 = AB_Test_Restore::apply( $entry_set, array( 'blogname' => 'Alt' ) );
ok( 0 === $ri8['undone'] && array( 'ab_mcp_undo_option_failed' ) === AB_Test_Restore::$codes, 'I8 a deleted row a default filter answers for is not passed off as restored' );
unset( $GLOBALS['ab_optafter']['blogname'], $GLOBALS['ab_optdefault']['blogname'] );
// A missing setting whose add lands another value: failed.
unset( $GLOBALS['ab_opts']['blogname'], $GLOBALS['ab_autoload']['blogname'] );
$GLOBALS['ab_optfilter']['blogname'] = function ( $v, $old ) { return 'etwas-anderes'; };
AB_Test_Restore::$codes = array();
$ri9 = AB_Test_Restore::apply( $entry_set, array( 'blogname' => 'Alt' ) );
ok( 0 === $ri9['undone'] && array( 'ab_mcp_undo_option_failed' ) === AB_Test_Restore::$codes, 'I9 an add that lands a different value is failed' );
unset( $GLOBALS['ab_optfilter']['blogname'] );
// WordPress stores scalars as strings: 5 back as '5' is restored.
$GLOBALS['ab_opts']['posts_per_page']     = '10';
$GLOBALS['ab_autoload']['posts_per_page'] = 'yes';
$GLOBALS['ab_optfilter']['posts_per_page'] = function ( $v, $old ) { return (string) $v; };
$ri10 = AB_MCP_Undo_Restore::apply( $entry_set, array( 'posts_per_page' => 5 ) );
ok( 1 === $ri10['undone'] && '5' === get_option( 'posts_per_page' ), 'I10 a number stored back as text still counts as restored' );
unset( $GLOBALS['ab_optfilter']['posts_per_page'] );
$GLOBALS['ab_opts']['blogname']     = 'Alt';
$GLOBALS['ab_autoload']['blogname'] = 'yes';

/* ============================== I-T) wp_update_site_settings: the recorder */

AB_MCP_Undo::clear_all();
foreach ( array( 'blogname' => 'Titel alt', 'blogdescription' => 'Tag alt', 'admin_email' => 'alt@example.test', 'timezone_string' => 'Europe/Zurich', 'posts_per_page' => 10 ) as $k => $v ) {
	$GLOBALS['ab_opts'][ $k ]     = $v;
	$GLOBALS['ab_autoload'][ $k ] = 'yes';
}
$it = AB_MCP_Tools_Options_Settings::update_site_settings( array( 'title' => 'Titel neu', 'tagline' => '', 'admin_email' => 'kein-mail', 'posts_per_page' => '7' ) );
ok( ! is_wp_error( $it ) && true === $it['updated'] && array( 'title', 'tagline', 'posts_per_page' ) === $it['changed'], 'IT1 the fields written are listed as changed; an invalid admin_email is ignored' );
ok( ! isset( $it['adjusted'] ) && ! isset( $it['failed'] ), 'IT1 nothing adjusted, nothing failed' );
ok( 'Titel neu' === get_option( 'blogname' ) && '' === get_option( 'blogdescription' ) && 7 === get_option( 'posts_per_page' ) && 'alt@example.test' === get_option( 'admin_email' ), 'IT1 the site holds the new values; admin_email is untouched' );
$ct = AB_MCP_Undo::claim( (string) $it['undo'] );
ok( ! is_wp_error( $ct ) && array( 'blogname' => 'Titel alt', 'blogdescription' => 'Tag alt', 'posts_per_page' => 10 ) === $ct['data'], 'IT2 the undo point keeps the old values of exactly the fields written' );
ok( ! is_wp_error( $ct ) && 'wp_update_site_settings' === $ct['entry']['tool'] && 'site_settings' === $ct['entry']['kind'] && 'manage_options' === $ct['entry']['cap'] && 3 === (int) $ct['entry']['items'], 'IT2 recorded for the tool, its kind and capability' );
$ut = AB_MCP_Tools_Undo_Core::undo( array( 'token' => (string) $it['undo'] ) );
ok( ! is_wp_error( $ut ) && 3 === $ut['undone'] && true === $ut['consumed'] && 'Titel alt' === get_option( 'blogname' ) && 'Tag alt' === get_option( 'blogdescription' ) && 10 === get_option( 'posts_per_page' ), 'IT3 wp_undo puts the three back and consumes the token' );
ok( ! is_wp_error( $ut ) && array() === $ut['page_cache_purged'] && in_array( 'ab_mcp_after_restore', $GLOBALS['ab_actions_fired'], true ), 'IT3 and asks the page caches (none here) and announces the restore' );
ok( 'ab_mcp_undo_not_found' === ab_code( AB_MCP_Undo::claim( (string) $it['undo'] ) ), 'IT3 the token is used up' );
// adjusted: a filter stores another value; failed: one rejects the write.
$GLOBALS['ab_optfilter']['blogname']        = function ( $v, $old ) { return 'Gefiltert'; };
$GLOBALS['ab_optfilter']['timezone_string'] = function ( $v, $old ) { return $old; };
$it4 = AB_MCP_Tools_Options_Settings::update_site_settings( array( 'title' => 'Titel neu', 'timezone' => 'Mars/Olympus', 'tagline' => 'Tag neu' ) );
ok( ! is_wp_error( $it4 ) && array( 'title', 'tagline' ) === $it4['changed'] && array( 'title' => 'Gefiltert' ) === ( $it4['adjusted'] ?? null ) && array( 'timezone' ) === ( $it4['failed'] ?? null ), 'IT4 changed, adjusted and failed are told apart by reading back' );
ok( is_string( $it4['undo'] ), 'IT4 the undo point is there all the same' );
$it5 = AB_MCP_Tools_Options_Settings::update_site_settings( array( 'timezone' => 'Mars/Olympus' ) );
ok( 'ab_mcp_write_failed' === ab_code( $it5 ) && array( 'timezone' ) === ( $it5->get_error_data()['failed'] ?? null ), 'IT5 when every field failed the call is an error naming that only' );
ok( is_wp_error( $it5 ) && false !== strpos( $it5->get_error_message(), 'Settings › General' ), 'IT5 and the answer names the way (wp-admin)' );
unset( $GLOBALS['ab_optfilter']['blogname'], $GLOBALS['ab_optfilter']['timezone_string'] );
ok( null === AB_MCP_Tools_Options_Settings::update_site_settings( array() )['undo'], 'IT6 a call that writes nothing records nothing' );
AB_MCP_Undo::clear_all();

/* ============================== K) Restore erzeugt keinen neuen Undo-Punkt */

$GLOBALS['ab_caps'] = array( 'manage_options' => true );
$before = count( AB_MCP_Undo::entries_for( 50 ) );
AB_MCP_Undo_Restore::apply( $entry_set, array( 'blogname' => 'K' ) );
ok( ! AB_MCP_Undo::is_suspended(), 'K1 recording is resumed after a restore' );
ok( count( AB_MCP_Undo::entries_for( 50 ) ) === $before, 'K1 the restore itself recorded nothing' );
ok( null !== AB_MCP_Undo::record( ab_set_rec( 'blogname', 'z' ) ), 'K1 and recording works again afterwards' );

/* ============================== L) Token-Scope zaehlt fuer das ORIGINALTOOL */

$GLOBALS['ab_caps']  = array( 'manage_options' => true, 'edit_others_posts' => true, 'edit_post:10' => true );
$GLOBALS['ab_posts'] = array( 10 => 'draft' );
$GLOBALS['ab_scope'] = 'content';
$l1 = AB_MCP_Undo_Restore::plan( $entry_set, array( 'blogname' => 'v' ) );
ok( 'ab_mcp_undo_scope' === ab_code( $l1 ), 'L1 a content token cannot undo a site settings change' );
ok( is_wp_error( $l1 ) && false !== strpos( $l1->get_error_message(), 'wp_update_site_settings' ) && false !== strpos( $l1->get_error_message(), 'ab-connections' ), 'L1 the refusal names the tool and leads to the connections' );
ok( ! is_wp_error( AB_MCP_Undo_Restore::plan( $entry_bulk, array( array( 10, 'draft' ) ) ) ), 'L2 but it can undo a post-status change' );
$GLOBALS['ab_caps']['edit_posts'] = true;
ok( ! is_wp_error( AB_MCP_Undo_Restore::plan( array( 'tool' => 'wp_update_post', 'kind' => 'post_fields' ), array( 'id' => 10, 'fields' => array( 'post_title' => 'x' ) ) ) ), 'L2 and a post change' );
$GLOBALS['ab_scope'] = 'read';
ok( 'ab_mcp_undo_scope' === ab_code( AB_MCP_Undo_Restore::plan( $entry_bulk, array( array( 10, 'draft' ) ) ) ), 'L3 a read-only token cannot undo anything' );
$GLOBALS['ab_scope'] = 'full';

/* ============================== M) Aufzeichnung folgt dem Tool-Schalter */

$GLOBALS['ab_toolstate'] = array( 'wp_undo' => false );
ok( ! AB_MCP_Undo::enabled(), 'M1 switching wp_undo off also stops the recording' );
ok( null === AB_MCP_Undo::record( ab_set_rec( 'blogname', 'm' ) ), 'M1 no snapshot is written while it is off' );
ok( 'ab_mcp_undo_disabled' === ab_code( AB_MCP_Tools_Undo_Core::undo_list( array() ) ) && 'ab_mcp_undo_disabled' === ab_code( AB_MCP_Tools_Undo_Core::undo( array( 'token' => 'undo_' . str_repeat( 'a', 32 ) ) ) ) && 'ab_mcp_undo_disabled' === ab_code( AB_MCP_Tools_Undo_Core::undo_discard( array( 'all' => true ) ) ), 'M1 and the three tools say so' );
$GLOBALS['ab_filters']['ab_mcp_undo_enabled'] = false;
$GLOBALS['ab_toolstate'] = array();
ok( ! AB_MCP_Undo::enabled(), 'M2 the ab_mcp_undo_enabled filter switches it off in code' );
unset( $GLOBALS['ab_filters']['ab_mcp_undo_enabled'] );
ok( AB_MCP_Undo::enabled(), 'M2 and it comes back on' );

/* ============================== N) what this plugin's store does not do */

$missing = array();
foreach ( array( 'capture_file', 'capture_file_result', 'record_file', 'record_file_result', 'discard_files_of', 'append_part', 'set_after', 'check_size', 'file_max' ) as $fn ) {
	if ( method_exists( 'AB_MCP_Undo', $fn ) ) { $missing[] = $fn; }
}
ok( array() === $missing, 'N1 no file snapshots and no parts are written here (Pro does that)' . ( $missing ? ' -- found: ' . implode( ', ', $missing ) : '' ) );
ok( 7 === AB_MCP_Undo::TTL_DAYS_MAX && 7 === AB_MCP_Undo::TTL_DAYS_DEFAULT && 1 === AB_MCP_Undo::TTL_DAYS_MIN, 'N2 the window is 1 to 7 days here, 7 by default' );

/* ============================== O) Jeder Klasse::methode()-Verweis muss existieren */

$declared = array();
$parents  = array();
$sources  = array();
foreach ( array( '', 'tools/', 'undo/', 'builders/' ) as $sub ) {
	foreach ( (array) glob( $GLOBALS['ab_free'] . $sub . '*.php' ) as $f ) {
		$sources[ $f ] = file_get_contents( $f );
	}
}
foreach ( $sources as $f => $code ) {
	if ( preg_match( '/^\s*(?:final\s+|abstract\s+)*class\s+([A-Za-z0-9_]+)(?:\s+extends\s+([A-Za-z0-9_]+))?/m', $code, $m ) ) {
		$cls = $m[1];
		$declared[ $cls ] = isset( $declared[ $cls ] ) ? $declared[ $cls ] : array();
		if ( ! empty( $m[2] ) ) { $parents[ $cls ] = $m[2]; }
		if ( preg_match_all( '/function\s+([A-Za-z0-9_]+)\s*\(/', $code, $mm ) ) {
			foreach ( $mm[1] as $fn ) { $declared[ $cls ][ $fn ] = true; }
		}
	}
}
$has_method = function ( $cls, $fn ) use ( &$declared, &$parents ) {
	$seen = array();
	while ( isset( $declared[ $cls ] ) && ! isset( $seen[ $cls ] ) ) {
		if ( isset( $declared[ $cls ][ $fn ] ) ) { return true; }
		$seen[ $cls ] = true;
		$cls = isset( $parents[ $cls ] ) ? $parents[ $cls ] : null;
		if ( null === $cls ) { break; }
	}
	return false;
};
$check_files = array_merge(
	(array) glob( $GLOBALS['ab_free'] . 'undo/*.php' ),
	array(
		$GLOBALS['ab_free'] . 'class-post-status.php',
		$GLOBALS['ab_free'] . 'class-page-cache.php',
		$GLOBALS['ab_free'] . 'tools/class-tools-undo-core.php',
		$GLOBALS['ab_free'] . 'tools/class-tools-content.php',
		$GLOBALS['ab_free'] . 'tools/class-tools-options-settings.php',
		$GLOBALS['ab_free'] . 'tools/class-tools-search-bulk.php',
		$GLOBALS['ab_free'] . 'tools/class-tools-system.php',
	)
);
$bad     = array();
$checked = 0;
foreach ( $check_files as $f ) {
	$code = preg_replace( '#^\s*(//|\*|/\*).*$#m', '', (string) file_get_contents( $f ) );
	if ( ! preg_match_all( '/\b(AB_MCP_[A-Za-z0-9_]+)::([A-Za-z0-9_]+)\s*\(/', $code, $m, PREG_SET_ORDER ) ) {
		continue;
	}
	foreach ( $m as $hit ) {
		list( , $cls, $fn ) = $hit;
		if ( ! isset( $declared[ $cls ] ) ) {
			// A class of Pro, asked only where it is loaded: the free code
			// checks class_exists() and method_exists() first (wp_update_post
			// asks Pro's restore whether it knows the tool).
			if ( 0 === strpos( $cls, 'AB_MCP_Pro_' ) && false !== strpos( $code, "class_exists( '$cls' )" ) && false !== strpos( $code, "method_exists( '$cls', '$fn' )" ) ) {
				continue;
			}
			$bad[] = basename( $f ) . ": unbekannte Klasse $cls";
			continue;
		}
		$checked++;
		if ( ! $has_method( $cls, $fn ) ) {
			$bad[] = basename( $f ) . ": $cls::$fn() existiert nicht";
		}
	}
}
ok( $checked > 40, sprintf( 'O1 %d cross-class static calls were checked', $checked ) );
ok( empty( $bad ), 'O2 every AB_MCP_*::method() reference in the undo code resolves' . ( $bad ? ' -- ' . implode( '; ', array_unique( $bad ) ) : '' ) );
$pol   = AB_MCP_Undo_Restore::policy();
$kinds = array();
foreach ( $pol as $tool => $row ) { $kinds[ $row['kind'] ] = true; }
ok( 3 === count( $pol ), 'O3 the policy table covers exactly the three tools of this plugin' );
$restore_src = (string) file_get_contents( $GLOBALS['ab_free'] . 'undo/class-undo-restore.php' );
$missing     = array();
foreach ( array_keys( $kinds ) as $k ) {
	if ( 2 > substr_count( $restore_src, "case '$k':" ) ) { $missing[] = $k; }
}
ok( empty( $missing ), 'O3 and every kind it names has a plan branch and a restore branch' . ( $missing ? ' -- fehlt: ' . implode( ', ', $missing ) : '' ) );
$guidance_pro = AB_MCP_Guidance::PRO_TOOLS;
ok( array() === array_intersect( array_keys( $pol ), $guidance_pro ), 'O4 none of the three is listed as a Pro tool' );

/* ============================== PRO) Pro-Punkte im gemeinsamen Speicher */

AB_MCP_Undo::clear_all();
$GLOBALS['ab_caps']  = array( 'manage_options' => true, 'edit_plugins' => true, 'edit_others_posts' => true, 'edit_posts' => true );
$GLOBALS['ab_scope'] = 'full';
$GLOBALS['ab_filters']['ab_mcp_undo_inline_max'] = 8;
$tp_file = AB_MCP_Undo::record( ab_pro_file_rec( 'inc.php', '<?php // 1.0.0' ) );
unset( $GLOBALS['ab_filters']['ab_mcp_undo_inline_max'] );
$tp_opt = AB_MCP_Undo::record( ab_pro_opt_rec( 'my_plugin_setting', 'alt' ) );
// A page-builder point with two parts, in the shape Pro's append_part() leaves.
$tp_bld = AB_MCP_Undo::record( array( 'tool' => 'wp_search_replace', 'cap' => 'manage_options', 'kind' => 'builder_data', 'summary' => '3 pages', 'items' => 1, 'data' => array( 'posts' => array( 5 => array( 'meta:_elementor_data' => '[1]' ) ) ) ) );
$tp_p1  = AB_MCP_Undo::record( array( 'tool' => 'wp_search_replace', 'cap' => 'manage_options', 'kind' => 'builder_data', 'items' => 1, 'data' => array( 'posts' => array( 6 => array( 'meta:_elementor_data' => '[2]' ) ) ) ) );
$tp_p2  = AB_MCP_Undo::record( array( 'tool' => 'wp_search_replace', 'cap' => 'manage_options', 'kind' => 'builder_data', 'items' => 1, 'data' => array( 'posts' => array( 7 => array( 'meta:_elementor_data' => '[3]' ) ) ) ) );
$parts  = array();
foreach ( array( $tp_p1, $tp_p2 ) as $pt ) {
	$pe2     = ab_entry_of( $pt );
	$parts[] = array( 'payload' => $pe2['payload'], 'sha256' => $pe2['sha256'], 'bytes' => (int) $pe2['bytes'], 'stored' => (int) $pe2['stored'] );
}
$idx = array();
foreach ( get_option( AB_MCP_Undo::OPTION, array() ) as $e ) {
	if ( in_array( $e['token'], array( $tp_p1, $tp_p2 ), true ) ) { continue; }
	if ( $e['token'] === $tp_bld ) {
		$e['parts']  = $parts;
		$e['stored'] = (int) $e['stored'] + $parts[0]['stored'] + $parts[1]['stored'];
		$e['after']  = array( 5 => array( 'meta:_elementor_data' => hash( 'sha256', '[1b]' ) ) );
	}
	$idx[] = $e;
}
update_option( AB_MCP_Undo::OPTION, $idx );
ok( AB_MCP_Undo::valid_token( $tp_file ) && AB_MCP_Undo::valid_token( $tp_opt ) && AB_MCP_Undo::valid_token( $tp_bld ), 'PRO0 points of three Pro tools are in the store (file, option, page builder with parts)' );
ok( 4 === count( ab_indexed_files() ) && 4 === count( array_filter( ab_indexed_files(), 'is_file' ) ), 'PRO0 (their files: one for the file point, three for the page-builder point and its parts; the option point is inline)' );
$lp = AB_MCP_Undo::entries_for( 50 );
ok( array( $tp_bld, $tp_opt, $tp_file ) === ab_tokens( $lp ), 'PRO1 this plugin lists Pro\'s points like its own, newest first' );
$cp = AB_MCP_Undo::claim( $tp_bld );
ok( ! is_wp_error( $cp ) && 2 === count( $cp['parts'] ) && '[2]' === $cp['parts'][0]['posts'][6]['meta:_elementor_data'] && '[3]' === $cp['parts'][1]['posts'][7]['meta:_elementor_data'], 'PRO2 it reads a point with parts, every part checked' );
ok( ! is_wp_error( AB_MCP_Undo::claim( $tp_file ) ) && ! is_wp_error( AB_MCP_Undo::claim( $tp_opt ) ), 'PRO2 and the file and option points' );
foreach ( array( $tp_file => 'wp_write_plugin_file', $tp_opt => 'wp_update_option', $tp_bld => 'wp_search_replace' ) as $tok => $tool ) {
	$cx   = AB_MCP_Undo::claim( $tok );
	$px   = AB_MCP_Undo_Restore::plan( $cx['entry'], $cx['data'] );
	ok( 'ab_mcp_undo_needs_pro' === ab_code( $px ), "PRO3 plan refuses the point of $tool with ab_mcp_undo_needs_pro" );
	ok( is_wp_error( $px ) && false !== strpos( $px->get_error_message(), $tool ) && false !== strpos( $px->get_error_message(), 'wp_undo_list' ), "PRO3 naming the tool and the way ($tool)" );
	ok( 'ab_mcp_undo_needs_pro' === ab_code( AB_MCP_Undo_Restore::apply( $cx['entry'], $cx['data'] ) ), "PRO3 apply refuses it too ($tool)" );
	ok( 'ab_mcp_undo_needs_pro' === ab_code( AB_MCP_Undo_Restore::may_touch( $tool ) ), "PRO3 and may_touch ($tool)" );
}
$GLOBALS['ab_caps'] = array( 'manage_options' => true, 'edit_plugins' => true, 'edit_others_posts' => true, 'edit_posts' => true, 'read' => true );
$wu = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $tp_file ) );
ok( 'ab_mcp_undo_needs_pro' === ab_code( $wu ), 'PRO4 wp_undo refuses Pro\'s file point' );
ok( null !== ab_entry_of( $tp_file ) && ! isset( ab_entry_of( $tp_file )['applying'] ) && 1 === count( array_filter( array( ab_entry_of( $tp_file )['payload'] ), function ( $p ) { return is_file( rtrim( AB_MCP_Undo::dir(), '/' ) . '/' . $p ); } ) ), 'PRO4 and the point stays, with its file, its reservation released' );
ok( 'ab_mcp_undo_needs_pro' === ab_code( AB_MCP_Tools_Undo_Core::undo( array( 'token' => $tp_bld, 'dry_run' => true ) ) ), 'PRO4 a dry run says the same' );
ok( 'ab_mcp_undo_needs_pro' === ab_code( AB_MCP_Tools_Undo_Core::undo_discard( array( 'token' => $tp_bld ) ) ) && null !== ab_entry_of( $tp_bld ), 'PRO5 wp_undo_discard does not throw a Pro point away' );
$da = AB_MCP_Tools_Undo_Core::undo_discard( array( 'all' => true ) );
ok( ! is_wp_error( $da ) && 0 === $da['discarded'] && 3 === ( $da['kept'] ?? null ) && 3 === count( AB_MCP_Undo::entries_for( 50 ) ), 'PRO5 all=true keeps them and counts them under kept' );
$ul = AB_MCP_Tools_Undo_Core::undo_list( array() );
$rev = array();
foreach ( $ul['undo_points'] as $row ) { $rev[ $row['tool'] ] = array( $row['reversible'], isset( $row['reason'] ) && false !== strpos( $row['reason'], 'AlphaBridge MCP Pro' ) ); }
ok( array( 'wp_search_replace' => array( false, true ), 'wp_update_option' => array( false, true ), 'wp_write_plugin_file' => array( false, true ) ) === $rev, 'PRO6 wp_undo_list shows them as not reversible here, with the reason' );
ok( 7.0 === (float) $ul['ttl_days'] && 3 === $ul['count'], 'PRO6 and the window of 7 days' );
// One of this plugin's own beside them is reversed as usual.
$GLOBALS['ab_opts']['blogname'] = 'Neu';
$tp_own = AB_MCP_Undo::record( ab_set_rec( 'blogname', 'Alt' ) );
$uo     = AB_MCP_Tools_Undo_Core::undo( array( 'token' => $tp_own ) );
ok( ! is_wp_error( $uo ) && true === $uo['consumed'] && 'Alt' === get_option( 'blogname' ) && 3 === count( AB_MCP_Undo::entries_for( 50 ) ), 'PRO7 a point of this plugin beside them is reversed and consumed; Pro\'s stay' );
// A tampered part refuses the whole point before anything is planned.
$part_file = rtrim( AB_MCP_Undo::dir(), '/' ) . '/' . $parts[1]['payload'];
$part_orig = file_get_contents( $part_file );
file_put_contents( $part_file, 'kaputt' );
ok( 'ab_mcp_undo_corrupt' === ab_code( AB_MCP_Undo::claim( $tp_bld ) ), 'PRO8 a damaged part makes the point unreadable' );
file_put_contents( $part_file, $part_orig );
// Expired Pro points go with all their files.
$files_bld = array();
foreach ( array_merge( array( ab_entry_of( $tp_bld )['payload'] ), array_column( $parts, 'payload' ) ) as $p ) { $files_bld[] = rtrim( AB_MCP_Undo::dir(), '/' ) . '/' . $p; }
ab_patch_entry( $tp_bld, function ( $e ) { $e['expires_at'] = time() - 5; return $e; } );
ok( ! in_array( $tp_bld, ab_tokens( AB_MCP_Undo::entries_for( 50 ) ), true ), 'PRO9 an expired Pro point is left out of the list' );
ok( AB_MCP_Undo::gc() >= 1 && null === ab_entry_of( $tp_bld ) && 0 === count( array_filter( $files_bld, 'is_file' ) ), 'PRO9 gc() removes it with its file and the files of its parts' );
$usage = AB_MCP_Undo::usage();
ok( 2 === $usage['points'] && $usage['bytes'] > 0, 'PRO10 usage() counts Pro\'s points too' );
// A point Pro kept for 30 days is past the window here after 7.
$GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] = 30;
$tp_old = AB_MCP_Undo::record( ab_pro_opt_rec( 'old_setting' ) );
ab_patch_entry( $tp_old, function ( $e ) { $e['created_at'] -= 8 * DAY_IN_SECONDS; $e['expires_at'] = $e['created_at'] + 30 * DAY_IN_SECONDS; return $e; } );
ok( 'ab_mcp_undo_expired' === ab_code( AB_MCP_Undo::claim( $tp_old ) ), 'PRO11 a point Pro keeps for 30 days is past the 7-day window here after 8 days' );
$tp_young = AB_MCP_Undo::record( ab_pro_opt_rec( 'young_setting' ) );
ab_patch_entry( $tp_young, function ( $e ) { $e['created_at'] -= 6 * DAY_IN_SECONDS; $e['expires_at'] = $e['created_at'] + 30 * DAY_IN_SECONDS; return $e; } );
$row_y = null;
foreach ( AB_MCP_Undo::entries_for( 50 ) as $row ) { if ( $row['token'] === $tp_young ) { $row_y = $row; } }
ok( is_array( $row_y ) && gmdate( 'c', (int) ab_entry_of( $tp_young )['created_at'] + 7 * DAY_IN_SECONDS ) === $row_y['expires_at'], 'PRO11 one of 6 days is listed, expiring 7 days after it was taken' );
unset( $GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] );
AB_MCP_Undo::clear_all();
ok( array() === get_option( AB_MCP_Undo::OPTION, array() ) && array() === ab_bin_files(), 'PRO12 clear_all() empties the store of Pro\'s points as well, files included' );

/* ============================== QA/QC) die Options-Helfer des Speichers */

$GLOBALS['ab_opts']['krude']     = 'da';
$GLOBALS['ab_autoload']['krude'] = 'was-auch-immer';
ok( 'no' === AB_MCP_Undo::option_autoload( 'krude' ), 'QA1 a row with an unknown autoload value is still a row, read as not autoloaded' );
$map    = array( 'yes' => 'yes', 'on' => 'yes', 'auto' => 'yes', 'auto-on' => 'yes', 'no' => 'no', 'off' => 'no', 'auto-off' => 'no' );
$map_ok = true;
foreach ( $map as $raw => $want ) {
	if ( $want !== AB_MCP_Undo::normalize_autoload( $raw ) ) { $map_ok = false; }
}
ok( $map_ok, 'QA2 every WordPress 6.6 autoload value maps to the right side' );
ok( null === AB_MCP_Undo::normalize_autoload( 'vielleicht' ) && null === AB_MCP_Undo::normalize_autoload( '' ) && null === AB_MCP_Undo::normalize_autoload( 1 ), 'QA2 an unrecognised flag maps to nothing' );
$GLOBALS['ab_opts']['normal']     = 'wert';
$GLOBALS['ab_autoload']['normal'] = 'no';
$sc1 = AB_MCP_Undo::option_snapshot( 'normal' );
ok( is_array( $sc1 ) && true === $sc1['existed'] && 'wert' === $sc1['value'] && 'no' === $sc1['autoload'], 'QC1 an option that is there is snapshotted with its value and flag' );
$sc2 = AB_MCP_Undo::option_snapshot( 'gibt_es_nicht' );
ok( is_array( $sc2 ) && false === $sc2['existed'] && null === $sc2['value'] && null === $sc2['autoload'], 'QC2 an option that is not there is snapshotted as "did not exist"' );
$GLOBALS['ab_opts']['db_kaputt'] = 'wert';
$GLOBALS['ab_autoload']['db_kaputt'] = 'yes';
$GLOBALS['ab_dberror']['db_kaputt']  = true;
ok( null === AB_MCP_Undo::option_snapshot( 'db_kaputt' ) && null === AB_MCP_Undo::option_row( 'db_kaputt' ), 'QC3 a lookup that could not run yields no snapshot at all' );
$GLOBALS['ab_dbdown']['db_kaputt'] = true;
unset( $GLOBALS['ab_dberror']['db_kaputt'] );
ok( null === AB_MCP_Undo::option_snapshot( 'db_kaputt' ), 'QC4 a connection that went away also yields none, though last_error stays empty' );
unset( $GLOBALS['ab_dbdown']['db_kaputt'] );
$GLOBALS['ab_dbsloppy']['db_kaputt'] = true;
ok( null === AB_MCP_Undo::option_snapshot( 'db_kaputt' ), 'QC5 a reported error is believed even when the query did not answer false' );
unset( $GLOBALS['ab_dbsloppy']['db_kaputt'] );
$GLOBALS['ab_opts']['leerflag']     = 'wert';
$GLOBALS['ab_autoload']['leerflag'] = '';
$sc6 = AB_MCP_Undo::option_snapshot( 'leerflag' );
ok( is_array( $sc6 ) && true === $sc6['existed'] && 'no' === $sc6['autoload'], 'QC6 a row whose autoload column is empty is still a row' );
if ( ! function_exists( 'wp_autoload_values_to_autoload' ) ) {
	// Deliberately WITHOUT 'auto': the filter may take values out of the list.
	function wp_autoload_values_to_autoload() { return array( 'yes', 'on', 'auto-on' ); }
}
ok( 'no' === AB_MCP_Undo::normalize_autoload( 'auto' ), 'QA3 core is asked what counts as autoload where it can say' );

/* ============================== R) Zwei parallele wp_undo auf denselben Token */

$GLOBALS['ab_caps'] = array( 'manage_options' => true );
$tok_r = AB_MCP_Undo::record( ab_set_rec( 'blogname', 'r' ) );
$n1 = AB_MCP_Undo::begin_apply( $tok_r );
ok( is_string( $n1 ) && '' !== $n1, 'R1 the first restore reserves the undo point and gets a nonce' );
ok( 'ab_mcp_undo_in_progress' === ab_code( AB_MCP_Undo::begin_apply( $tok_r ) ), 'R1 a second, parallel restore is refused' );
ok( 'ab_mcp_undo_in_progress' === ab_code( AB_MCP_Tools_Undo_Core::undo( array( 'token' => $tok_r ) ) ), 'R1 wp_undo says so too' );
AB_MCP_Undo::end_apply( $tok_r, $n1 );
$n2 = AB_MCP_Undo::begin_apply( $tok_r );
ok( is_string( $n2 ), 'R2 and it is free again once the first one releases it' );
AB_MCP_Undo::end_apply( $tok_r, $n2 );
$n3 = AB_MCP_Undo::begin_apply( $tok_r );
ab_patch_entry( $tok_r, function ( $e ) { $e['applying'] = time() - 999; return $e; } );
$n4 = AB_MCP_Undo::begin_apply( $tok_r );
ok( is_string( $n4 ), 'R3 a stale reservation from a crashed request expires' );
AB_MCP_Undo::end_apply( $tok_r, $n3 );
ok( 'ab_mcp_undo_in_progress' === ab_code( AB_MCP_Undo::begin_apply( $tok_r ) ), 'R4 a late release from an overrun holder does not free the new holder' );
AB_MCP_Undo::end_apply( $tok_r, $n4 );
AB_MCP_Undo::discard( $tok_r );

/* ============================== S) Discard achtet auf den Scope */

$GLOBALS['ab_caps'] = array( 'manage_options' => true, 'edit_others_posts' => true );
AB_MCP_Undo::clear_all();
$t_set  = AB_MCP_Undo::record( ab_set_rec( 'blogname', 's1' ) );
$t_post = AB_MCP_Undo::record( array( 'tool' => 'wp_bulk_update_status', 'cap' => 'edit_others_posts', 'kind' => 'post_status', 'data' => array( array( 7, 'draft' ) ) ) );
$GLOBALS['ab_scope'] = 'content';
ok( 'ab_mcp_undo_scope' === ab_code( AB_MCP_Undo_Restore::may_touch( 'wp_update_site_settings' ) ), 'S1 a content token may not touch a site settings undo point' );
ok( true === AB_MCP_Undo_Restore::may_touch( 'wp_bulk_update_status' ), 'S1 but it may touch a post one' );
ok( 'ab_mcp_undo_scope' === ab_code( AB_MCP_Tools_Undo_Core::undo_discard( array( 'token' => $t_set ) ) ) && null !== ab_entry_of( $t_set ), 'S1 wp_undo_discard refuses it and it stays' );
$sd = AB_MCP_Tools_Undo_Core::undo_discard( array( 'all' => true ) );
ok( ! is_wp_error( $sd ) && 1 === $sd['discarded'] && 1 === ( $sd['kept'] ?? null ), 'S2 discard all removes only the points this token may act on' );
ok( in_array( $t_set, ab_tokens( AB_MCP_Undo::entries_for( 50 ) ), true ), 'S2 and the out-of-scope point survives' );
$GLOBALS['ab_scope'] = 'full';
$GLOBALS['ab_uid']   = 8;
$t_other = AB_MCP_Undo::record( ab_set_rec( 'blogname', 'other' ) );
$GLOBALS['ab_uid']   = 7;
AB_MCP_Undo::clear_for_user();
ok( null === ab_entry_of( $t_set ) && null !== ab_entry_of( $t_other ), 'S3 clear_for_user() removes the own points only' );
AB_MCP_Undo::clear_all();

/* ============================== T) Speicher-Integritaet */

$t_ok = AB_MCP_Undo::record( ab_pro_file_rec( 'a.php', str_repeat( 'A', 200 ) ) );
ok( null !== $t_ok && ! empty( ab_entry_of( $t_ok )['payload'] ), 'T1 a file snapshot is stored as a file' );
ab_patch_entry( $t_ok, function ( $e ) { $e['sha256'] = ''; return $e; } );
ok( is_wp_error( AB_MCP_Undo::claim( $t_ok ) ), 'T1 an entry whose hash was stripped is refused' );
AB_MCP_Undo::clear_all();
$waise = trailingslashit( AB_MCP_Undo::dir() ) . str_repeat( 'a', 40 ) . '.bin';
file_put_contents( $waise, 'muell' );
touch( $waise, time() - ( 2 * DAY_IN_SECONDS ) );
AB_MCP_Undo::gc();
ok( ! file_exists( $waise ), 'T2 gc removes an orphaned snapshot file nothing points at' );
$frisch = trailingslashit( AB_MCP_Undo::dir() ) . str_repeat( 'b', 40 ) . '.bin';
file_put_contents( $frisch, 'muell' );
AB_MCP_Undo::gc();
ok( file_exists( $frisch ), 'T2 but a fresh one is left alone, it may be mid-write' );
@unlink( $frisch );
$fremd = trailingslashit( AB_MCP_Undo::dir() ) . 'fremd.bin';
file_put_contents( $fremd, 'nicht von uns' );
touch( $fremd, time() - ( 2 * DAY_IN_SECONDS ) );
AB_MCP_Undo::gc();
ok( file_exists( $fremd ), 'T2 and a file whose name is not a snapshot name is never touched' );
@unlink( $fremd );

/* ============================== U) Nachzuegler */

update_option( AB_MCP_Undo::LOCK, (string) time() );   // lock held by another hand
ok( 'ab_mcp_undo_busy' === ab_code( AB_MCP_Undo::entries_for( 10 ) ), 'U3 a busy index is an error, not "no undo points"' );
ok( 'ab_mcp_undo_busy' === ab_code( AB_MCP_Undo::clear_for_user() ), 'U3 clear_for_user says busy instead of "0 discarded"' );
ok( 'ab_mcp_undo_busy' === ab_code( AB_MCP_Tools_Undo_Core::undo_list( array() ) ), 'U3 and wp_undo_list passes it on' );
delete_option( AB_MCP_Undo::LOCK );
if ( function_exists( 'gzencode' ) ) {
	$tokq = AB_MCP_Undo::record( ab_pro_file_rec( 'b.php', str_repeat( 'B', 300 ) ) );
	$fq   = ab_entry_of( $tokq )['payload'] ?? null;
	$payload = str_repeat( "\0", 2 * MB_IN_BYTES );
	file_put_contents( trailingslashit( AB_MCP_Undo::dir() ) . $fq, gzencode( $payload, 9 ) );
	ab_patch_entry( $tokq, function ( $e ) use ( $payload ) { $e['sha256'] = hash( 'sha256', $payload ); return $e; } );
	unset( $payload );
	$GLOBALS['ab_filters']['ab_mcp_undo_snapshot_max'] = 64 * KB_IN_BYTES;
	ok( is_wp_error( AB_MCP_Undo::claim( $tokq ) ), 'U4 a decompression bomb is refused' );
	unset( $GLOBALS['ab_filters']['ab_mcp_undo_snapshot_max'] );
	AB_MCP_Undo::discard( $tokq );
} else {
	ok( true, 'U4 skipped (no gz here)' );
}

/* ============================== V) finish_apply: Verbrauch und Freigabe in einem */

$tok_v = AB_MCP_Undo::record( ab_set_rec( 'blogname', 'v' ) );
$nv    = AB_MCP_Undo::begin_apply( $tok_v );
ok( is_string( $nv ), 'V1 reservation with nonce' );
ok( 'ab_mcp_undo_taken_over' === ab_code( AB_MCP_Undo::finish_apply( $tok_v, 'ffffffffffffffff', true ) ), 'V1 a foreign nonce cannot consume the point' );
ok( 'ab_mcp_undo_in_progress' === ab_code( AB_MCP_Undo::discard( $tok_v ) ), 'V2 discard respects an active reservation' );
ok( true === AB_MCP_Undo::finish_apply( $tok_v, $nv, true ), 'V3 the holder consumes the point' );
ok( ! in_array( $tok_v, ab_tokens( AB_MCP_Undo::entries_for( 50 ) ), true ), 'V3 and it is gone from the index' );
ok( true === AB_MCP_Undo::finish_apply( $tok_v, $nv, true ), 'V4 consuming an already-gone point is not an error' );
$tok_w = AB_MCP_Undo::record( ab_set_rec( 'blogname', 'w' ) );
$nw    = AB_MCP_Undo::begin_apply( $tok_w );
ok( true === AB_MCP_Undo::finish_apply( $tok_w, $nw, false ), 'V5 release keeps the point' );
$nw2 = AB_MCP_Undo::begin_apply( $tok_w );
ok( is_string( $nw2 ), 'V5 and it can be reserved again' );
AB_MCP_Undo::finish_apply( $tok_w, $nw2, false );
AB_MCP_Undo::discard( $tok_w );

/* ============================== W) discard-Wettlauf + extend */

$tok_x = AB_MCP_Undo::record( ab_set_rec( 'blogname', 'x1' ) );
$nx    = AB_MCP_Undo::begin_apply( $tok_x );
ok( 'ab_mcp_undo_in_progress' === ab_code( AB_MCP_Undo::discard( $tok_x ) ), 'W1 discard yields to the stored reservation' );
ok( in_array( $tok_x, ab_tokens( AB_MCP_Undo::entries_for( 50 ) ), true ), 'W1 and the point is still there' );
ab_patch_entry( $tok_x, function ( $e ) { $e['applying'] = time() - 100; return $e; } );
AB_MCP_Undo::extend_apply( $tok_x, 'ffffffffffffffff' );
ok( (int) ab_entry_of( $tok_x )['applying'] <= time() - 100, 'W2 a foreign nonce cannot renew the window' );
AB_MCP_Undo::extend_apply( $tok_x, $nx );
ok( (int) ab_entry_of( $tok_x )['applying'] > time() - 5, 'W2 the holder renews it' );
AB_MCP_Undo::finish_apply( $tok_x, $nx, true );

/* ============================== X) Undo-Fenster: 7 Tage, hoechstens 7, Filter gewinnt */

AB_MCP_Undo::clear_all();
$GLOBALS['ab_filters']   = array();
$GLOBALS['ab_toolstate'] = array();
$GLOBALS['ab_caps']      = array( 'manage_options' => true );
unset( $GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] );
ok( 7 === AB_MCP_Undo::ttl_days(), 'X1 the window is 7 days when nothing is set' );
ok( 7 * DAY_IN_SECONDS === AB_MCP_Undo::ttl(), 'X1 and ttl() says so in seconds' );
$GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] = 3;
ok( 3 * DAY_IN_SECONDS === AB_MCP_Undo::ttl() && 3 === AB_MCP_Undo::ttl_days(), 'X2 a shorter window Pro stored is kept (3 days)' );
$GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] = 30;
ok( 7 === AB_MCP_Undo::ttl_days() && 7 * DAY_IN_SECONDS === AB_MCP_Undo::ttl(), 'X2 the 30 days Pro stored read as 7 here' );
$GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] = '14';
ok( 7 === AB_MCP_Undo::ttl_days(), 'X2 a number stored as text over 7 is read as 7 too (14)' );
$GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] = '5';
ok( 5 === AB_MCP_Undo::ttl_days(), 'X2 one within the range is kept as text as well (5)' );
$x3 = array(
	array( 0, 1 ), array( -4, 1 ), array( 1, 1 ), array( 3, 3 ), array( 7, 7 ), array( 8, 7 ), array( 30, 7 ), array( 31, 7 ), array( 1000, 7 ),
	array( '45', 7 ), array( ' 6 ', 6 ), array( ' 12 ', 7 ), array( '2.9', 2 ), array( 0.5, 1 ), array( 6.99, 6 ), array( 1e300, 7 ), array( -1e300, 1 ),
	array( 'abc', 7 ), array( '', 7 ), array( null, 7 ), array( true, 7 ), array( array( 5 ), 7 ), array( INF, 7 ), array( NAN, 7 ), array( '1e400', 7 ),
);
foreach ( $x3 as $pair ) {
	ok( $pair[1] === AB_MCP_Undo::clamp_days( $pair[0] ), 'X3 clamp_days(' . var_export( $pair[0], true ) . ') is ' . $pair[1] );
}
$GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] = 99;
ok( 7 * DAY_IN_SECONDS === AB_MCP_Undo::ttl(), 'X3 a stored value over 7 days is read as 7' );
$GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] = 'kaputt';
ok( 7 * DAY_IN_SECONDS === AB_MCP_Undo::ttl(), 'X3 a stored value that is no number is read as the default, not as 1 day' );
$GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] = 3;
$GLOBALS['ab_filters']['ab_mcp_undo_ttl'] = 2 * HOUR_IN_SECONDS;
ok( 2 * HOUR_IN_SECONDS === AB_MCP_Undo::ttl(), 'X4 the ab_mcp_undo_ttl filter wins over the option' );
ok( 3 === AB_MCP_Undo::ttl_days(), 'X4 while the screen value stays what was saved' );
$GLOBALS['ab_filters']['ab_mcp_undo_ttl'] = 90 * DAY_IN_SECONDS;
ok( 90 * DAY_IN_SECONDS === AB_MCP_Undo::ttl(), 'X4 the filter is not held to the 7 days of this store' );
$GLOBALS['ab_filters']['ab_mcp_undo_ttl'] = 10;
ok( 60 === AB_MCP_Undo::ttl(), 'X4 but to the 60-second floor' );
unset( $GLOBALS['ab_filters']['ab_mcp_undo_ttl'], $GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] );
$tx5 = AB_MCP_Undo::record( ab_set_rec( 'blogname', 'x5' ) );
$ex5 = ab_entry_of( $tx5 );
ok( is_array( $ex5 ) && 7 * DAY_IN_SECONDS === (int) $ex5['expires_at'] - (int) $ex5['created_at'], 'X5 a new point expires 7 days after it was taken' );
$GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] = 30;
$tx5c = AB_MCP_Undo::record( ab_set_rec( 'blogname', 'x5c' ) );
$ex5c = ab_entry_of( $tx5c );
ok( is_array( $ex5c ) && 7 * DAY_IN_SECONDS === (int) $ex5c['expires_at'] - (int) $ex5c['created_at'], 'X5 also with the 30 days Pro stored' );
$GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] = 2;
$tx5b = AB_MCP_Undo::record( ab_set_rec( 'blogname', 'x5b' ) );
$ex5b = ab_entry_of( $tx5b );
ok( is_array( $ex5b ) && 2 * DAY_IN_SECONDS === (int) $ex5b['expires_at'] - (int) $ex5b['created_at'], 'X5 and with the window at 2 days, 2 days after' );
unset( $GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] );
$tx6 = AB_MCP_Undo::record( ab_set_rec( 'blogname', 'x6' ) );
ab_patch_entry( $tx6, function ( $e ) { $e['created_at'] -= 2 * DAY_IN_SECONDS; $e['expires_at'] -= 2 * DAY_IN_SECONDS; return $e; } );
ok( ! is_wp_error( AB_MCP_Undo::claim( $tx6 ) ), 'X6 a point taken 2 days ago lives within a 7-day window' );
$GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] = 1;
ok( 'ab_mcp_undo_expired' === ab_code( AB_MCP_Undo::claim( $tx6 ) ), 'X6 cutting the window to 1 day expires it at once' );
ok( false !== strpos( ab_msg( AB_MCP_Undo::claim( $tx6 ) ), 'wp_restore_revision' ), 'X6 and the answer names another way back' );
$GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] = 7;
ok( ! is_wp_error( AB_MCP_Undo::claim( $tx6 ) ), 'X6 (before any clean-up ran, putting the window back brings it back)' );
$GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] = 1;
$tx6b = AB_MCP_Undo::record( ab_set_rec( 'blogname', 'x6b' ) );
$GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] = 30;
$ex6b = ab_entry_of( $tx6b );
$lx6b = null;
foreach ( AB_MCP_Undo::entries_for( 50 ) as $row ) {
	if ( $row['token'] === $tx6b ) { $lx6b = $row; }
}
ok( is_array( $lx6b ) && gmdate( 'c', (int) $ex6b['created_at'] + DAY_IN_SECONDS ) === $lx6b['expires_at'], 'X6 lengthening the window does not stretch a point already taken' );
$GLOBALS['ab_filters']['ab_mcp_undo_inline_max'] = 8;
$GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ]  = 7;
$tx7 = AB_MCP_Undo::record( ab_set_rec( 'blogname', str_repeat( 'v', 50 ) ) );
unset( $GLOBALS['ab_filters']['ab_mcp_undo_inline_max'] );
$fx7 = ab_entry_of( $tx7 )['payload'] ?? null;
ab_patch_entry( $tx7, function ( $e ) { $e['created_at'] -= 3 * DAY_IN_SECONDS; return $e; } );
$GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] = 2;
AB_MCP_Undo::gc();
ok( null === ab_entry_of( $tx7 ), 'X7 gc removes a point the shortened window has expired' );
ok( is_string( $fx7 ) && ! is_file( rtrim( AB_MCP_Undo::dir(), '/' ) . '/' . $fx7 ), 'X7 together with its snapshot file' );
$GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] = 30;
$orph = rtrim( AB_MCP_Undo::dir(), '/' ) . '/' . str_repeat( 'c', 40 ) . '.bin';
file_put_contents( $orph, 'orphan' );
touch( $orph, time() - 2 * DAY_IN_SECONDS );
$young = rtrim( AB_MCP_Undo::dir(), '/' ) . '/' . str_repeat( 'd', 40 ) . '.bin';
file_put_contents( $young, 'being written' );
touch( $young, time() - 600 );
AB_MCP_Undo::gc();
ok( ! is_file( $orph ), 'X8 an orphan file older than a day is swept, whatever window Pro stored' );
ok( is_file( $young ), 'X8 a file younger than that is left alone (it may be a write in progress)' );
@unlink( $young );
unset( $GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] );
AB_MCP_Undo::clear_all();
$GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] = 7;
$GLOBALS['ab_filters']['ab_mcp_undo_inline_max'] = 8;
$tx10 = AB_MCP_Undo::record( ab_set_rec( 'blogname', str_repeat( 'v', 50 ) ) );
unset( $GLOBALS['ab_filters']['ab_mcp_undo_inline_max'] );
$fx10 = rtrim( AB_MCP_Undo::dir(), '/' ) . '/' . ( ab_entry_of( $tx10 )['payload'] ?? 'x' );
ab_patch_entry( $tx10, function ( $e ) { $e['created_at'] -= 3 * DAY_IN_SECONDS; return $e; } );
$GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] = 1;
$ux10 = AB_MCP_Undo::usage();
ok( 1 === $ux10['points'] && $ux10['bytes'] > 0 && is_file( $fx10 ), 'X10 a point the shortened window has expired is counted while it is still stored' );
$tx10b = AB_MCP_Undo::record( ab_set_rec( 'blogname', 'x10b' ) );
ok( null === ab_entry_of( $tx10 ) && null !== ab_entry_of( $tx10b ), 'X10 recording the next point drops it from the index' );
ok( ! is_file( $fx10 ), 'X10 together with its snapshot file' );
ok( 1 === AB_MCP_Undo::usage()['points'], 'X10 and usage() no longer counts it' );
unset( $GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] );
AB_MCP_Undo::clear_all();
$GLOBALS['ab_uid']                        = 7;
$GLOBALS['ab_filters']['ab_mcp_undo_ttl'] = 7 * DAY_IN_SECONDS;
$tx11 = AB_MCP_Undo::record( ab_set_rec( 'blogname', 'x11' ) );
ab_patch_entry( $tx11, function ( $e ) { $e['created_at'] -= 2 * DAY_IN_SECONDS; $e['expires_at'] -= 2 * DAY_IN_SECONDS; return $e; } );
$GLOBALS['ab_uid']                        = 8;
$GLOBALS['ab_filters']['ab_mcp_undo_ttl'] = DAY_IN_SECONDS;
$tx11b = AB_MCP_Undo::record( ab_set_rec( 'blogname', 'x11b' ) );
ok( AB_MCP_Undo::valid_token( $tx11b ) && null !== ab_entry_of( $tx11 ), 'X11 a request with a shorter filtered window leaves another user\'s point in place when it records' );
AB_MCP_Undo::gc();
ok( null !== ab_entry_of( $tx11 ), 'X11 and when it cleans up' );
$GLOBALS['ab_uid']                        = 7;
$GLOBALS['ab_filters']['ab_mcp_undo_ttl'] = 7 * DAY_IN_SECONDS;
ok( ! is_wp_error( AB_MCP_Undo::claim( $tx11 ) ), 'X11 so its owner can still use it' );
$GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] = 1;
ok( ! is_wp_error( AB_MCP_Undo::claim( $tx11 ) ) && AB_MCP_Undo::ttl_filtered(), 'X11 with a filter on, the setting on the screen does not reach back either' );
unset( $GLOBALS['ab_filters']['ab_mcp_undo_ttl'] );
ok( ! AB_MCP_Undo::ttl_filtered() && 'ab_mcp_undo_expired' === ab_code( AB_MCP_Undo::claim( $tx11 ) ), 'X11 without the filter, the shortened setting reaches back again' );
unset( $GLOBALS['ab_opts'][ AB_MCP_Undo::TTL_OPTION ] );
AB_MCP_Undo::clear_all();
ok( 0 === strpos( AB_MCP_Undo::TTL_OPTION, 'ab_mcp' ), 'X9 the window option carries the ab_mcp prefix the option write guard refuses' );

/* ============================== Y) Gesamtgrenze: Aelteste zuerst */

AB_MCP_Undo::clear_all();
$GLOBALS['ab_filters'] = array();
ok( 100 * MB_IN_BYTES === AB_MCP_Undo::max_total_bytes(), 'Y0 the store is capped at 100 MB by default' );
$GLOBALS['ab_filters']['ab_mcp_undo_max_total_bytes'] = 0;
ok( 1 === AB_MCP_Undo::max_total_bytes(), 'Y0 a filter cannot set it below 1 byte' );
unset( $GLOBALS['ab_filters']['ab_mcp_undo_max_total_bytes'] );
$yrec  = function () { return ab_pro_file_rec( 'y.bin', random_bytes( 3000 ) ); };
$ty    = array( AB_MCP_Undo::record( $yrec() ) );
$ysize = (int) ab_entry_of( $ty[0] )['stored'];
ok( $ysize > 3000, 'Y1 (incompressible: the stored size is the snapshot size plus overhead, ' . $ysize . ' bytes)' );
$GLOBALS['ab_filters']['ab_mcp_undo_max_total_bytes'] = (int) ( 3.5 * $ysize );
$yfile = array( $ty[0] => rtrim( AB_MCP_Undo::dir(), '/' ) . '/' . ab_entry_of( $ty[0] )['payload'] );
for ( $i = 1; $i < 5; $i++ ) {
	$t           = AB_MCP_Undo::record( $yrec() );
	$ty[]        = $t;
	$e           = ab_entry_of( $t );
	$yfile[ $t ] = rtrim( AB_MCP_Undo::dir(), '/' ) . '/' . ( $e ? $e['payload'] : 'x' );
}
ok( 5 === count( array_filter( $ty, array( 'AB_MCP_Undo', 'valid_token' ) ) ), 'Y1 every one of the five gets its token' );
ok( array( $ty[2], $ty[3], $ty[4] ) === array_column( get_option( AB_MCP_Undo::OPTION, array() ), 'token' ), 'Y1 the store keeps the newest three, in order' );
ok( is_wp_error( AB_MCP_Undo::claim( $ty[0] ) ) && is_wp_error( AB_MCP_Undo::claim( $ty[1] ) ), 'Y1 the two oldest are gone' );
ok( ! is_file( $yfile[ $ty[0] ] ) && ! is_file( $yfile[ $ty[1] ] ), 'Y2 the dropped points took their files with them' );
ok( 3 === count( array_filter( ab_indexed_files(), 'is_file' ) ), 'Y2 the three kept points still have theirs' );
$yu = AB_MCP_Undo::usage();
ok( 3 === $yu['points'] && $yu['bytes'] <= AB_MCP_Undo::max_total_bytes(), 'Y3 usage() reports 3 points within the cap' );
$ysum = 0;
foreach ( ab_indexed_files() as $f ) { $ysum += filesize( $f ); }
ok( $ysum === $yu['bytes'], 'Y3 and the bytes it reports are the bytes on disk' );
$GLOBALS['ab_filters']['ab_mcp_undo_max_total_bytes'] = 1000;
$ny4 = count( ab_bin_files() );
$y4  = AB_MCP_Undo::record_result( $yrec() );
ok( 'ab_mcp_undo_too_large' === ab_code( $y4 ) && 'total' === $y4->get_error_data()['measured'] && 1000 === $y4->get_error_data()['limit'], 'Y4 a point larger than the whole store is refused as too large (total)' );
ok( is_wp_error( $y4 ) && false !== strpos( $y4->get_error_message(), 'ab_mcp_undo_max_total_bytes' ), 'Y4 and names the filter that raises it' );
ok( $ny4 === count( ab_bin_files() ) && 3 === count( get_option( AB_MCP_Undo::OPTION, array() ) ), 'Y4 nothing was written and nothing pushed out' );
unset( $GLOBALS['ab_filters']['ab_mcp_undo_max_total_bytes'] );
AB_MCP_Undo::clear_all();
$ti = array( AB_MCP_Undo::record( ab_set_rec( 'blogname', str_repeat( 'i', 400 ) ) ) );
$ei = ab_entry_of( $ti[0] );
ok( is_string( $ei['inline'] ) && strlen( $ei['inline'] ) === (int) $ei['stored'], 'Y5 an inline point counts with the length of its row' );
$GLOBALS['ab_filters']['ab_mcp_undo_max_total_bytes'] = 2 * (int) $ei['stored'] + 10;
$ti[] = AB_MCP_Undo::record( ab_set_rec( 'blogname', str_repeat( 'j', 400 ) ) );
$ti[] = AB_MCP_Undo::record( ab_set_rec( 'blogname', str_repeat( 'k', 400 ) ) );
ok( array( $ti[1], $ti[2] ) === array_column( get_option( AB_MCP_Undo::OPTION, array() ), 'token' ), 'Y5 and inline points are dropped oldest first as well' );
ab_patch_entry( $ti[1], function ( $e ) { unset( $e['stored'] ); $e['inline'] = null; $e['payload'] = str_repeat( 'e', 40 ) . '.bin'; $e['sha256'] = str_repeat( '0', 64 ); $e['bytes'] = 5000; return $e; } );
ok( null !== ab_entry_of( $ti[1] ) && ! array_key_exists( 'stored', ab_entry_of( $ti[1] ) ), 'Y6 (an old-style file entry without "stored")' );
ok( 5000 + (int) $ei['stored'] === AB_MCP_Undo::usage()['bytes'], 'Y6 counts with its size before compression' );
$ti[] = AB_MCP_Undo::record( ab_set_rec( 'blogname', str_repeat( 'l', 400 ) ) );
ok( array( $ti[2], $ti[3] ) === array_column( get_option( AB_MCP_Undo::OPTION, array() ), 'token' ), 'Y6 and is pushed out by it' );
unset( $GLOBALS['ab_filters']['ab_mcp_undo_max_total_bytes'] );
$ystr = $good; $ystr['stored'] = 'viel';
ok( ! AB_MCP_Undo::valid_entry( $ystr ), 'Y7 an entry whose "stored" is not a number is refused' );
$ystr['stored'] = 17;
ok( AB_MCP_Undo::valid_entry( $ystr ) && AB_MCP_Undo::valid_entry( $good ), 'Y7 one with a number is fine, and one without it (recorded before) still is' );

/* ============================== Z) Grenze je Punkt nach gzip */

AB_MCP_Undo::clear_all();
$GLOBALS['ab_filters'] = array();
ok( MB_IN_BYTES === AB_MCP_Undo::snapshot_max() && 8 * MB_IN_BYTES === AB_MCP_Undo::raw_max(), 'Z0 1 MB per point as stored, 8 MB before compression' );
$GLOBALS['ab_filters']['ab_mcp_undo_raw_max'] = 10;
ok( MB_IN_BYTES === AB_MCP_Undo::raw_max(), 'Z0 the raw limit never goes below the stored one' );
$GLOBALS['ab_filters']['ab_mcp_undo_snapshot_max'] = PHP_INT_MAX;
unset( $GLOBALS['ab_filters']['ab_mcp_undo_raw_max'] );
ok( PHP_INT_MAX === AB_MCP_Undo::raw_max(), 'Z0 eight times a huge limit does not overflow' );
$GLOBALS['ab_filters'] = array();
if ( function_exists( 'gzencode' ) ) {
	$GLOBALS['ab_filters']['ab_mcp_undo_snapshot_max'] = 64 * KB_IN_BYTES;   // raw up to 512 KB
	$zbytes = str_repeat( "<div class=\"elementor-widget\">Hallo Welt</div>\n", 4000 );   // ~196 KB
	$zrec   = ab_pro_file_rec( 'z.html', $zbytes );
	$tz     = AB_MCP_Undo::record_result( $zrec );
	ok( AB_MCP_Undo::valid_token( $tz ), 'Z1 a snapshot far over the limit raw, but under it compressed, gets an undo point' );
	$ez = ab_entry_of( $tz );
	ok( is_array( $ez ) && (int) $ez['bytes'] > 64 * KB_IN_BYTES && (int) $ez['stored'] < 64 * KB_IN_BYTES, 'Z1 raw ' . ( $ez['bytes'] ?? '?' ) . ' bytes, stored ' . ( $ez['stored'] ?? '?' ) . ' bytes' );
	$zpath = rtrim( AB_MCP_Undo::dir(), '/' ) . '/' . ( $ez['payload'] ?? 'x' );
	ok( is_file( $zpath ) && filesize( $zpath ) === (int) $ez['stored'] && "\x1f\x8b" === substr( file_get_contents( $zpath ), 0, 2 ), 'Z1 the measured size is the size on disk, and the file is gzip' );
	ok( hash( 'sha256', gzdecode( file_get_contents( $zpath ) ) ) === $ez['sha256'], 'Z2 the sha256 is over the uncompressed snapshot' );
	$cz = AB_MCP_Undo::claim( $tz );
	ok( ! is_wp_error( $cz ) && $zbytes === $cz['data']['bytes'], 'Z2 reading it back gives the exact bytes' );
	$zorig = file_get_contents( $zpath );
	file_put_contents( $zpath, gzencode( str_replace( '"z.html"', '"y.html"', gzdecode( $zorig ) ), 6 ) );
	ok( 'ab_mcp_undo_corrupt' === ab_code( AB_MCP_Undo::claim( $tz ) ), 'Z2 a changed snapshot, validly recompressed, fails its integrity check' );
	file_put_contents( $zpath, substr( $zorig, 0, (int) ( strlen( $zorig ) / 2 ) ) );
	ok( 'ab_mcp_undo_corrupt' === ab_code( AB_MCP_Undo::claim( $tz ) ), 'Z2 a truncated file is refused too' );
	file_put_contents( $zpath, $zorig );
	ok( ! is_wp_error( AB_MCP_Undo::claim( $tz ) ), 'Z2 and the intact file reads again' );
	$zbig = $zrec; $zbig['data']['bytes'] = str_repeat( 'A', 600 * KB_IN_BYTES );
	$nz   = count( ab_bin_files() );
	$z4   = AB_MCP_Undo::record_result( $zbig );
	ok( 'ab_mcp_undo_too_large' === ab_code( $z4 ) && 'raw' === $z4->get_error_data()['measured'] && 512 * KB_IN_BYTES === $z4->get_error_data()['limit'], 'Z3 over the uncompressed limit: too large, measured raw' );
	ok( is_wp_error( $z4 ) && false !== strpos( $z4->get_error_message(), 'ab_mcp_undo_raw_max' ) && $nz === count( ab_bin_files() ), 'Z3 with the filter in the answer, and no file written' );
	$zrnd = $zrec; $zrnd['data']['bytes'] = random_bytes( 100 * KB_IN_BYTES );
	$z5   = AB_MCP_Undo::record_result( $zrnd );
	ok( 'ab_mcp_undo_too_large' === ab_code( $z5 ) && 'compressed' === $z5->get_error_data()['measured'] && false !== strpos( $z5->get_error_message(), 'ab_mcp_undo_snapshot_max' ), 'Z4 incompressible and over the limit: too large, measured compressed' );
	ok( $nz === count( ab_bin_files() ), 'Z4 and no file written' );
	$tz6 = AB_MCP_Undo::record( ab_set_rec( 'blogdescription', str_repeat( 'Lorem ipsum dolor sit amet. ', 4000 ) ) );
	$ez6 = ab_entry_of( $tz6 );
	ok( is_array( $ez6 ) && null === $ez6['inline'] && (int) $ez6['bytes'] > 64 * KB_IN_BYTES && (int) $ez6['stored'] < (int) $ez6['bytes'], 'Z5 a site_settings snapshot over the inline size goes to a compressed file' );
	$cz6 = AB_MCP_Undo::claim( $tz6 );
	ok( ! is_wp_error( $cz6 ) && str_repeat( 'Lorem ipsum dolor sit amet. ', 4000 ) === $cz6['data']['blogdescription'], 'Z5 and reads back exactly' );
	$tz7  = AB_MCP_Undo::record( $zrec );
	$ez7  = ab_entry_of( $tz7 );
	$bomb = str_repeat( "\0", 600 * KB_IN_BYTES );
	file_put_contents( rtrim( AB_MCP_Undo::dir(), '/' ) . '/' . $ez7['payload'], gzencode( $bomb, 9 ) );
	ab_patch_entry( $tz7, function ( $e ) use ( $bomb ) { $e['sha256'] = hash( 'sha256', $bomb ); return $e; } );
	ok( 'ab_mcp_undo_corrupt' === ab_code( AB_MCP_Undo::claim( $tz7 ) ), 'Z6 a file that inflates past the uncompressed limit is refused, matching hash or not' );
	unset( $bomb );
	$GLOBALS['ab_filters'] = array();
	$GLOBALS['ab_filters']['ab_mcp_undo_inline_max']   = MB_IN_BYTES;
	$GLOBALS['ab_filters']['ab_mcp_undo_snapshot_max'] = 200;
	$tz8 = AB_MCP_Undo::record( ab_set_rec( 'blogname', str_repeat( 'x', 500 ) ) );
	$ez8 = ab_entry_of( $tz8 );
	ok( is_array( $ez8 ) && null === $ez8['inline'] && (int) $ez8['bytes'] > 200 && (int) $ez8['stored'] <= 200, 'Z7 a row over the per-point limit is not kept inline, even under a raised inline limit' );
	$GLOBALS['ab_filters'] = array();
} else {
	ok( true, 'Z1-Z7 skipped (no zlib here)' );
}

/* ============================== AA) record_result: jeder Fehler mit eigenem Code */

AB_MCP_Undo::clear_all();
$GLOBALS['ab_filters'] = array();
ok( AB_MCP_Undo::valid_token( AB_MCP_Undo::record_result( ab_set_rec( 'blogname', 'aa0' ) ) ), 'AA0 success is a token' );
function ab_aa( $rec, $code, $label, $reason_key = null, $reason = null ) {
	$r = AB_MCP_Undo::record_result( $rec );
	ok( $code === ab_code( $r ), "$label: $code" . ( is_wp_error( $r ) ? '' : ' (got ' . var_export( $r, true ) . ')' ) );
	if ( null !== $reason_key ) {
		$d = is_wp_error( $r ) ? $r->get_error_data() : null;
		ok( is_array( $d ) && ( $d[ $reason_key ] ?? null ) === $reason, "$label: $reason_key = $reason" );
	}
	ok( is_wp_error( $r ) && '' !== trim( (string) $r->get_error_message() ), "$label: with a message" );
	return $r;
}
$GLOBALS['ab_toolstate'] = array( 'wp_undo' => false );
$aa1 = ab_aa( ab_set_rec( 'blogname', 'aa1' ), 'ab_mcp_undo_disabled', 'AA1 switched off', 'reason', 'switched_off' );
ok( false !== strpos( ab_msg( $aa1 ), 'wp_undo' ), 'AA1 the answer names the switch' );
ok( null === AB_MCP_Undo::record( ab_set_rec( 'blogname', 'aa1' ) ), 'AA1 record() still answers null' );
$GLOBALS['ab_toolstate'] = array();
AB_MCP_Undo::suspend();
ab_aa( ab_set_rec( 'blogname', 'aa2' ), 'ab_mcp_undo_disabled', 'AA2 paused during a restore', 'reason', 'suspended' );
AB_MCP_Undo::resume();
ab_aa( array( 'kind' => '', 'data' => array( 1 ) ), 'ab_mcp_undo_no_data', 'AA3 no kind' );
ab_aa( array( 'kind' => 'site_settings' ), 'ab_mcp_undo_no_data', 'AA3 no data' );
ab_aa( array( 'kind' => 'site_settings', 'data' => array( 'x' => new stdClass() ) ), 'ab_mcp_undo_encode_failed', 'AA4 an object', 'reason', 'unsafe_value' );
ab_aa( ab_set_rec( 'blogname', "\xff\xfe kein UTF-8" ), 'ab_mcp_undo_encode_failed', 'AA4 invalid UTF-8 in a setting', 'reason', 'not_encodable' );
$GLOBALS['ab_filters']['ab_mcp_undo_snapshot_max'] = 10;
ab_aa( ab_set_rec( 'blogname', str_repeat( 'q', 200 ) ), 'ab_mcp_undo_too_large', 'AA5 over the limit', 'measured', 'raw' );
unset( $GLOBALS['ab_filters']['ab_mcp_undo_snapshot_max'] );
$aa_up = $GLOBALS['ab_upload'];
$GLOBALS['ab_upload'] = $aa_up . '-datei';
file_put_contents( $GLOBALS['ab_upload'], 'kein Ordner' );
$GLOBALS['ab_filters']['ab_mcp_undo_inline_max'] = 8;
$r6 = @AB_MCP_Undo::record_result( ab_set_rec( 'blogname', str_repeat( 'w', 100 ) ) );
ok( 'ab_mcp_undo_write_failed' === ab_code( $r6 ) && 'ab_mcp_undo_dir' === ( $r6->get_error_data()['cause'] ?? '' ), 'AA6 the snapshot file cannot be written: write_failed, with the cause underneath' );
unset( $GLOBALS['ab_filters']['ab_mcp_undo_inline_max'] );
@unlink( $GLOBALS['ab_upload'] );
$GLOBALS['ab_upload'] = $aa_up;
$GLOBALS['ab_filters']['ab_mcp_undo_inline_max'] = 8;
$GLOBALS['ab_optafter'][ AB_MCP_Undo::OPTION ] = function () { foreach ( ab_bin_files() as $f ) { @unlink( $f ); } };
$r6b = AB_MCP_Undo::record_result( ab_set_rec( 'blogname', str_repeat( 'w', 100 ) ) );
unset( $GLOBALS['ab_optafter'][ AB_MCP_Undo::OPTION ] );
ok( 'ab_mcp_undo_write_failed' === ab_code( $r6b ) && 'vanished' === ( $r6b->get_error_data()['cause'] ?? '' ), 'AA6 a snapshot file gone before the commit: write_failed, vanished' );
$n7 = count( ab_bin_files() );
update_option( AB_MCP_Undo::LOCK, (string) time() );
ab_aa( ab_set_rec( 'blogname', str_repeat( 'b', 100 ) ), 'ab_mcp_undo_index_failed', 'AA7 index busy', 'reason', 'busy' );
delete_option( AB_MCP_Undo::LOCK );
ok( $n7 === count( ab_bin_files() ), 'AA7 and the snapshot file written before is removed again' );
$GLOBALS['ab_optfilter'][ AB_MCP_Undo::OPTION ] = function ( $new, $old ) { return $old; };
ab_aa( ab_set_rec( 'blogname', str_repeat( 'b', 100 ) ), 'ab_mcp_undo_index_failed', 'AA7 index write not kept', 'reason', 'not_stored' );
unset( $GLOBALS['ab_optfilter'][ AB_MCP_Undo::OPTION ] );
ok( $n7 === count( ab_bin_files() ), 'AA7 and no file is left behind' );
unset( $GLOBALS['ab_filters']['ab_mcp_undo_inline_max'] );
AB_MCP_Undo::clear_all();

/* ============================== AB) Texte: Fenster und Werkzeuge */

$undo_tools_src = (string) file_get_contents( $GLOBALS['ab_free'] . 'tools/class-tools-undo-core.php' );
ok( false === stripos( $undo_tools_src, '24 hours' ) && false !== strpos( $undo_tools_src, '7 days' ), 'AB2 the undo tools name the 7 days, not 24 hours' );
ok( false === strpos( $undo_tools_src, '30 days' ) && false === strpos( $undo_tools_src, '1 to 30' ), 'AB2 and no window this plugin does not keep' );
ok( false !== strpos( $undo_tools_src, "'ttl_days'" ), 'AB2 wp_undo_list reports the window in days' );

/* ======================= AC) Auflisten aendert nichts (readOnlyHint) */

AB_MCP_Undo::clear_for_user();
$t_ac = AB_MCP_Undo::record( ab_set_rec( 'blogname', 'ac' ) );
ab_patch_entry( $t_ac, function ( $e ) { $e['expires_at'] = time() - 5; return $e; } );
$ac_before = get_option( AB_MCP_Undo::OPTION, array() );
ok( ! in_array( $t_ac, ab_tokens( AB_MCP_Undo::entries_for( 50 ) ), true ), 'AC1 an expired point is left out of the list' );
$ac_list = AB_MCP_Tools_Undo_Core::undo_list( array() );
ok( get_option( AB_MCP_Undo::OPTION, array() ) === $ac_before && 0 === $ac_list['count'], 'AC2 listing (store and tool) leaves the index untouched' );
ok( false === strpos( $undo_tools_src, 'AB_MCP_Undo::gc()' ), 'AC3 wp_undo_list does not clean up' );
ok( AB_MCP_Undo::gc() >= 1, 'AC5 gc() still removes the expired point' );

/* ===== AD) Codex 29.10.2026: a failed index write costs no listed point its snapshot */

AB_MCP_Undo::clear_all();
$GLOBALS['ab_filters']['ab_mcp_undo_inline_max']  = 8;
$GLOBALS['ab_filters']['ab_mcp_undo_max_entries'] = 1;
$ad_big  = array( 'blogname' => str_repeat( 'x', 400 ) );
$ad_keep = AB_MCP_Undo::record( array( 'tool' => 'wp_update_site_settings', 'cap' => 'manage_options', 'kind' => 'site_settings', 'summary' => 'ad1', 'items' => 1, 'data' => $ad_big ) );
ok( AB_MCP_Undo::valid_token( (string) $ad_keep ) && '' !== (string) ab_entry_of( $ad_keep )['payload'], 'AD1 a point with a snapshot file is recorded' );
$GLOBALS['ab_optfilter'][ AB_MCP_Undo::OPTION ] = function ( $v, $old ) { return $old; };
$ad_r = AB_MCP_Undo::record_result( array( 'tool' => 'wp_update_site_settings', 'cap' => 'manage_options', 'kind' => 'site_settings', 'summary' => 'ad2', 'items' => 1, 'data' => $ad_big ) );
unset( $GLOBALS['ab_optfilter'][ AB_MCP_Undo::OPTION ] );
ok( is_wp_error( $ad_r ) && 'ab_mcp_undo_index_failed' === $ad_r->get_error_code(), 'AD2 the next point, which would push it out, is refused when the index write fails' );
$ad_c = AB_MCP_Undo::claim( (string) $ad_keep );
ok( ! is_wp_error( $ad_c ) && $ad_big === $ad_c['data'], 'AD3 the point the stored index still lists keeps its snapshot and restores' );
$ad_ok = AB_MCP_Undo::record( array( 'tool' => 'wp_update_site_settings', 'cap' => 'manage_options', 'kind' => 'site_settings', 'summary' => 'ad3', 'items' => 1, 'data' => $ad_big ) );
ok( AB_MCP_Undo::valid_token( (string) $ad_ok ) && is_wp_error( AB_MCP_Undo::claim( (string) $ad_keep ) ), 'AD4 with the write stored the old point goes, its file with it' );
unset( $GLOBALS['ab_filters']['ab_mcp_undo_inline_max'], $GLOBALS['ab_filters']['ab_mcp_undo_max_entries'] );
AB_MCP_Undo::clear_all();

/* ================================================= Result */

printf( "UNDO-TEST: %d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
