<?php
/**
 * The undo of a theme switch (kind theme) and the switch that records it,
 * without WordPress: themes and options are in-memory stubs, switch_theme is
 * a recorder. Ported from the theme part of AlphaBridge MCP Pro's
 * test/undo-theme-postfields-test.php to AB_MCP_Undo_Restore and
 * AB_MCP_Tools_Plugins_Themes_Manage of this plugin (free since 4.11.0); the
 * post-fields part is tests/standalone/undo-postfields-test.php.
 *
 * Proves: the snapshot holds the theme active before; a restore re-checks the
 * capability of the ORIGINAL tool (switch_themes) and the theme as it is NOW
 * (gone, broken, withheld by the network, its requirements unmet); a
 * hand-edited snapshot with an invalid theme name is refused outright; the
 * switch back goes through the checked switch of wp_activate_theme and the
 * undo answer carries its report.
 *
 * Left out against Pro's script: the deploy flag of wp_deploy_push_zip
 * (Site Deploy stays in Pro) and the post fields (see above).
 *
 * Run:  php tests/standalone/undo-theme-test.php
 */

error_reporting( E_ALL & ~E_DEPRECATED );

define( 'ABSPATH', __DIR__ . '/' );
define( 'KB_IN_BYTES', 1024 );
define( 'MB_IN_BYTES', 1048576 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

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
	public function get_error_messages() { return array( $this->message ); }
	public function get_error_data( $code = '' ) { return $this->data; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }

/* ---- identity and capabilities ---- */
$GLOBALS['ab_uid']  = 7;
$GLOBALS['ab_caps'] = array();
function get_current_user_id() { return (int) $GLOBALS['ab_uid']; }
function current_user_can( $cap, $obj = null ) {
	if ( null !== $obj && isset( $GLOBALS['ab_caps'][ $cap . ':' . $obj ] ) ) {
		return (bool) $GLOBALS['ab_caps'][ $cap . ':' . $obj ];
	}
	return ! empty( $GLOBALS['ab_caps'][ $cap ] );
}

/* ---- options, plain ---- */
$GLOBALS['ab_opts'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['ab_opts'] ) ? $GLOBALS['ab_opts'][ $k ] : $d; }
function add_option( $k, $v, $depr = '', $autoload = 'yes' ) {
	if ( array_key_exists( $k, $GLOBALS['ab_opts'] ) ) { return false; }
	$GLOBALS['ab_opts'][ $k ] = $v;
	return true;
}
function update_option( $k, $v, $autoload = null ) { $GLOBALS['ab_opts'][ $k ] = $v; return true; }
function delete_option( $k ) { $had = array_key_exists( $k, $GLOBALS['ab_opts'] ); unset( $GLOBALS['ab_opts'][ $k ] ); return $had; }
// The network's options: on a single site WordPress keeps them with the site's.
function get_site_option( $k, $d = false ) { return get_option( $k, $d ); }
function wp_cache_delete( $k, $group = '' ) { if ( 'posts' === $group ) { unset( $GLOBALS['ab_post_cache'][ (int) $k ] ); } return true; }
function apply_filters( $tag, $value ) { return $value; }
function maybe_serialize( $v ) { return is_array( $v ) || is_object( $v ) ? serialize( $v ) : $v; }
function maybe_unserialize( $v ) { return is_string( $v ) && preg_match( '/^[aOs]:/', $v ) ? unserialize( $v, array( 'allowed_classes' => false ) ) : $v; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
// As core: lower-case, then only a-z, 0-9, "_" and "-" (the meta keys of wp_update_post).
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
// One folder per run, not per process id, and gone when the run ends — the
// child run for the site without a trash (see the end) left its folder behind
// every time, and a later run with the same process id found its files.
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
$GLOBALS['ab_tmp'] = sys_get_temp_dir() . '/ab-undo-tp-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
@mkdir( $GLOBALS['ab_tmp'], 0755, true );
register_shutdown_function( static function () { ab_test_rm_tree( $GLOBALS['ab_tmp'] ); } );
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['ab_tmp'], 'baseurl' => 'http://x' ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0755, true ); }
function wp_slash( $v ) { return $v; }
function is_multisite() { return ! empty( $GLOBALS['ab_multisite'] ); }
/* registered statuses, as WordPress registers them (a plugin may add a public one) */
$GLOBALS['ab_stati'] = array( 'publish' => array( 'public' => true ), 'draft' => array(), 'pending' => array(), 'private' => array( 'private' => true ), 'future' => array( 'protected' => true ), 'trash' => array( 'internal' => true ), 'auto-draft' => array( 'internal' => true ), 'inherit' => array( 'internal' => true ) );
function get_post_stati( $args = array(), $output = 'names' ) { $k = array(); foreach ( $GLOBALS['ab_stati'] as $name => $props ) { if ( isset( $args['date_floating'] ) && (bool) ( $props['date_floating'] ?? false ) !== (bool) $args['date_floating'] ) { continue; } $k[] = $name; } return $k ? array_combine( $k, $k ) : array(); }
function get_post_status_object( $s ) { return isset( $GLOBALS['ab_stati'][ $s ] ) ? (object) array_merge( array( 'name' => $s, 'public' => false, 'private' => false, 'publicly_queryable' => false ), $GLOBALS['ab_stati'][ $s ] ) : null; }
$GLOBALS['ab_cache_cleaned'] = array();
function clean_post_cache( $id ) { $GLOBALS['ab_cache_cleaned'][] = (int) $id; }
$GLOBALS['ab_actions'] = array();
function add_action( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['ab_actions'][ $tag ][] = $cb; return true; }
function has_action( $tag, $cb = false ) {
	foreach ( $GLOBALS['ab_actions'][ $tag ] ?? array() as $c ) {
		if ( false === $cb || $c === $cb ) { return 10; }
	}
	return false;
}
function remove_action( $tag, $cb, $prio = 10 ) { if ( empty( $GLOBALS['ab_actions'][ $tag ] ) ) { return false; } foreach ( $GLOBALS['ab_actions'][ $tag ] as $i => $c ) { if ( $c === $cb ) { unset( $GLOBALS['ab_actions'][ $tag ][ $i ] ); return true; } } return false; }
function do_action( $tag, ...$args ) { foreach ( (array) ( $GLOBALS['ab_actions'][ $tag ] ?? array() ) as $cb ) { $cb( ...$args ); } }
function get_gmt_from_date( $d ) { return $d; }
/* filters with priorities: what wp_untrash_post_status runs through */
$GLOBALS['ab_hooked'] = array();
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['ab_hooked'][ $tag ][ $prio ][] = $cb; return true; }
// Mirrors core: whether anything is still hooked on the tag at any priority.
function has_filter( $tag, $cb = false ) { foreach ( $GLOBALS['ab_hooked'][ $tag ] ?? array() as $cbs ) { if ( ! empty( $cbs ) ) { return true; } } return false; }
function remove_filter( $tag, $cb, $prio = 10 ) { foreach ( $GLOBALS['ab_hooked'][ $tag ][ $prio ] ?? array() as $i => $c ) { if ( $c === $cb ) { unset( $GLOBALS['ab_hooked'][ $tag ][ $prio ][ $i ] ); return true; } } return false; }
function ab_run_hooked( $tag, $value, ...$args ) { $all = $GLOBALS['ab_hooked'][ $tag ] ?? array(); ksort( $all ); foreach ( $all as $cbs ) { foreach ( $cbs as $cb ) { $value = $cb( $value, ...$args ); } } return $value; }
function wp_timezone() { return new DateTimeZone( $GLOBALS['ab_tz'] ); }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }

/* ---- posts ---- */
$GLOBALS['ab_posts'] = array();
function ab_post( $id, $over = array() ) {
	$GLOBALS['ab_posts'][ $id ] = (object) array_merge(
		array( 'ID' => $id, 'post_title' => 'Old title', 'post_content' => 'Old body', 'post_excerpt' => '', 'post_status' => 'publish', 'post_name' => 'old', 'post_parent' => 0, 'post_author' => '1', 'post_type' => 'post', 'post_date' => '2026-09-01 10:00:00', 'post_date_gmt' => '2026-09-01 08:00:00', 'post_password' => '' ),
		$over
	);
}
/* get_post() answers from the object cache when there is one; the table is $GLOBALS['ab_posts'] */
$GLOBALS['ab_post_cache'] = array();
$GLOBALS['ab_get_post_calls'] = 0;
function get_post( $id ) { if ( ++$GLOBALS['ab_get_post_calls'] > 20000 ) { fwrite( STDERR, "get_post() called without end — a walk up the parents that does not stop\n" ); exit( 1 ); } if ( isset( $GLOBALS['ab_post_cache'][ (int) $id ] ) ) { return $GLOBALS['ab_post_cache'][ (int) $id ]; } return isset( $GLOBALS['ab_posts'][ (int) $id ] ) ? $GLOBALS['ab_posts'][ (int) $id ] : null; }
function ab_table_post( $id ) { return isset( $GLOBALS['ab_posts'][ (int) $id ] ) ? $GLOBALS['ab_posts'][ (int) $id ] : null; }
/* the trash notes a move to the trash clears first — noted with how many writes came before */
$GLOBALS['ab_meta_deleted'] = array();
function delete_post_meta( $id, $key ) { $GLOBALS['ab_meta_deleted'][] = array( (int) $id, $key, count( $GLOBALS['ab_seq'] ) ); unset( $GLOBALS['ab_meta_store'][ (int) $id ][ $key ] ); return true; }
/* the post meta the trash notes live in: every value of a key, in the order it was added — WordPress reads the first */
$GLOBALS['ab_meta_store'] = array();
function get_post_meta( $id, $key = '', $single = false ) { $v = $GLOBALS['ab_meta_store'][ (int) $id ][ $key ] ?? array(); return $single ? ( $v ? $v[0] : '' ) : $v; }
function add_post_meta( $id, $key, $value, $unique = false ) { $GLOBALS['ab_meta_store'][ (int) $id ][ $key ][] = $value; return true; }
function get_post_status( $id ) { if ( isset( $GLOBALS['ab_status_lie'] ) ) { return $GLOBALS['ab_status_lie']; } $p = get_post( $id ); return $p ? $p->post_status : false; } // a plugin's get_post_status filter can say anything
/* the posts table, read raw: what the restore's checks and the recorder's snapshot look at */
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );
class AB_Test_WPDB {
	public $posts   = 'wp_posts';
	public $options = 'wp_options';
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $a ) {
			$sql = preg_replace_callback( '/%[ds]/', static function ( $m ) use ( $a ) { return '%d' === $m[0] ? (string) (int) $a : "'" . addslashes( (string) $a ) . "'"; }, $sql, 1 );
		}
		return $sql;
	}
	public function get_row( $sql, $output = OBJECT ) {
		$GLOBALS['ab_wpdb_reads'][] = $sql;
		// A query hook that writes the post again lands back in pre_post_update while the snapshot is being read.
		if ( ! empty( $GLOBALS['ab_wpdb_reentry'] ) && $GLOBALS['ab_wpdb_reentry'] < 3 ) { ++$GLOBALS['ab_wpdb_reentry']; do_action( 'pre_post_update', 5, array() ); }
		if ( ! preg_match( '/WHERE ID = (\d+)/', $sql, $m ) ) { return null; }
		$p = isset( $GLOBALS['ab_posts'][ (int) $m[1] ] ) ? $GLOBALS['ab_posts'][ (int) $m[1] ] : null;
		if ( ! $p ) { return null; }
		$row = array();
		// Exactly the columns the SELECT names, as MySQL answers: a column left out of the query is missing from the row.
		if ( ! preg_match( '/^SELECT (.+?) FROM /', $sql, $sel ) ) { return null; }
		foreach ( array_map( 'trim', explode( ',', $sel[1] ) ) as $c ) { $row[ $c ] = (string) $p->$c; } // MySQL hands back strings
		return $row;
	}
	/* One column of one row, as wpdb::get_var() answers: a string — or null, for no such row AND for an empty column. */
	public function get_var( $sql ) {
		// An option as the table holds it (the theme switch reads them past get_option()): $GLOBALS['ab_opts'] here.
		if ( preg_match( "/^SELECT option_value FROM wp_options WHERE option_name = '(.*)'$/", $sql, $o ) ) {
			$k = stripslashes( $o[1] );
			if ( ! array_key_exists( $k, $GLOBALS['ab_opts'] ) ) { return null; }
			$v = $GLOBALS['ab_opts'][ $k ];
			return ( is_array( $v ) || is_object( $v ) ) ? serialize( $v ) : (string) $v;
		}
		if ( ! preg_match( '/^SELECT (\w+) FROM .* WHERE ID = (\d+)/', $sql, $m ) ) { return null; }
		$p = isset( $GLOBALS['ab_posts'][ (int) $m[2] ] ) ? $GLOBALS['ab_posts'][ (int) $m[2] ] : null;
		return ( $p && '' !== (string) $p->{$m[1]} ) ? (string) $p->{$m[1]} : null;
	}
}
$GLOBALS['wpdb']          = new AB_Test_WPDB();
$GLOBALS['ab_wpdb_reads'] = array();
function get_post_type( $id ) { $p = get_post( $id ); return $p ? $p->post_type : false; }
function get_post_type_object( $t ) { return (object) array( 'cap' => (object) array( 'edit_others_posts' => 'edit_others_posts', 'publish_posts' => 'publish_posts' ) ); }
$GLOBALS['ab_updates']   = array();
$GLOBALS['ab_untrashed'] = array();
$GLOBALS['ab_seq'] = array();
function wp_update_post( $arr, $wp_error = false ) {
	$GLOBALS['ab_updates_wp_error'][] = $wp_error;
	// A save that fails before it writes anything: 0, or with $wp_error an error.
	if ( ! empty( $GLOBALS['ab_update_fails'] ) ) { return $wp_error ? new WP_Error( 'db_update_error', 'Could not update post in the database.' ) : 0; }
	$GLOBALS['ab_updates'][] = $arr;
	$GLOBALS['ab_seq'][]     = 'update';
	// wp_update_post() starts from get_post() — the cache when there is one — merges the given fields in and writes every column to the table.
	$src = get_post( $arr['ID'] );
	$p   = ab_table_post( $arr['ID'] );
	if ( ! $src || ! $p ) { return new WP_Error( 'invalid_post', 'Invalid post ID.' ); }
	if ( $src !== $p ) { foreach ( get_object_vars( $src ) as $k => $v ) { $p->$k = $v; } }
	foreach ( $arr as $k => $v ) { if ( 'ID' !== $k ) { $p->$k = $v; } }
	// The site may store something else than asked (a unique slug, a filtered body).
	foreach ( (array) $GLOBALS['ab_store_other'] as $k => $v ) { if ( isset( $arr[ $k ] ) ) { $p->$k = $v; } }
	// ... or a status of its own, on every save (a workflow plugin; WordPress itself between "publish" and "future").
	if ( null !== $GLOBALS['ab_store_status'] ) { $p->post_status = $GLOBALS['ab_store_status']; }
	if ( 'private' === $p->post_status ) { $p->post_password = ''; } // wp_insert_post() drops the password of a post it saves as private
	// As core where the post's page template is gone: with $wp_error the row is written, then the error comes back before any
	// hook; without it the template falls back to the default and the save goes on.
	if ( ! empty( $GLOBALS['ab_template_gone'] ) && $wp_error ) { return new WP_Error( 'invalid_page_template', 'Invalid page template.' ); }
	return (int) $arr['ID'];
}
function wp_untrash_post( $id ) {
	$GLOBALS['ab_seq'][] = 'untrash';
	if ( ! empty( $GLOBALS['ab_untrash_veto'] ) ) { return false; }
	// A pre_untrash_post filter can end the core routine early and hand back its own value — the post stays in the trash.
	if ( ! empty( $GLOBALS['ab_untrash_noop'] ) ) { return true; }
	$GLOBALS['ab_untrashed'][] = (int) $id; $p = ab_table_post( $id );
	// A plugin on untrash_post that takes another post out of the trash along with this one.
	if ( null !== $GLOBALS['ab_untrash_along'] && (int) $id !== $GLOBALS['ab_untrash_along'] ) { wp_untrash_post( $GLOBALS['ab_untrash_along'] ); }
	if ( $p ) {
		// "draft" (a file "inherit"), unless a filter gives another: wp_untrash_post_set_previous_status() gives back the one from before the trash ...
		$new = ab_run_hooked( 'wp_untrash_post_status', null !== $GLOBALS['ab_untrash_previous'] ? $GLOBALS['ab_untrash_previous'] : ( 'attachment' === $p->post_type ? 'inherit' : 'draft' ), (int) $id, (string) $GLOBALS['ab_untrash_previous'] );
		// ... and WordPress saves it with the post's date as it is: a "future" whose date has passed is published.
		if ( 'future' === $new && strtotime( $p->post_date_gmt . ' UTC' ) - time() < MINUTE_IN_SECONDS ) { $new = 'publish'; }
		// WordPress deletes the trash notes before it saves; a save that fails leaves the post in the trash without them.
		delete_post_meta( $id, '_wp_trash_meta_status' );
		delete_post_meta( $id, '_wp_trash_meta_time' );
		if ( ! empty( $GLOBALS['ab_untrash_save_fails'] ) ) { return false; }
		$GLOBALS['ab_untrash_stored'][] = $new;
		$p->post_status = $new;
		if ( 'private' === $new ) { $p->post_password = ''; } // wp_insert_post() drops the password of a post it saves as private
		// untrashed_post: a plugin that changes the post on the way.
		if ( null !== $GLOBALS['ab_untrash_then'] ) { ( $GLOBALS['ab_untrash_then'] )( $p ); }
	}
	return $p; // the table changes; a suspended cache does not
}
function wp_trash_post( $id ) {
	$GLOBALS['ab_seq'][] = 'trash';
	if ( ! empty( $GLOBALS['ab_trash_veto'] ) ) { return false; }
	$GLOBALS['ab_trashed'][] = (int) $id; $p = ab_table_post( $id ); if ( $p ) { $p->post_status = 'trash'; } return $p; // the table changes; a suspended cache does not
}

/* ---- themes ---- */
$GLOBALS['ab_themes']     = array( 'old' => array(), 'new' => array(), 'broken' => array( 'broken' => true ), 'collection/theme' => array(), '_under' => array(), 'nope-net' => array( 'disallowed' => true ), 'needs-php9' => array( 'requires' => true ) );
$GLOBALS['ab_stylesheet'] = 'old';
$GLOBALS['ab_template']   = 'old';
$GLOBALS['ab_switches']   = array();
class AB_Test_Theme {
	public $slug;
	public function __construct( $s ) { $this->slug = (string) $s; }
	public function exists() { return isset( $GLOBALS['ab_themes'][ $this->slug ] ); }
	public function errors() { return ! empty( $GLOBALS['ab_themes'][ $this->slug ]['broken'] ) ? new WP_Error( 'theme_no_parent', 'The parent theme is missing.' ) : false; }
	public function get( $k ) { return 'Name' === $k ? ucfirst( $this->slug ) : '1.0'; }
	public function is_allowed() { return empty( $GLOBALS['ab_themes'][ $this->slug ]['disallowed'] ); }
	public function get_stylesheet() { return $this->slug; }
	public function get_template() { return $this->slug; } // as switch_theme() here sets it: every theme its own template
	public function get_stylesheet_directory() { return $GLOBALS['ab_tmp'] . '/themes/' . $this->slug; }
}
function wp_get_theme( $s = null ) { return new AB_Test_Theme( null === $s ? $GLOBALS['ab_stylesheet'] : $s ); }
function wp_get_themes( $args = array() ) { $out = array(); foreach ( array_keys( $GLOBALS['ab_themes'] ) as $k ) { $out[ $k ] = new AB_Test_Theme( $k ); } return $out; }
function validate_theme_requirements( $s ) { return ! empty( $GLOBALS['ab_themes'][ $s ]['requires'] ) ? new WP_Error( 'theme_php_version', 'Current PHP version does not meet minimum requirements for this theme.' ) : true; }
function get_stylesheet() { return $GLOBALS['ab_stylesheet']; }
function get_template() { return $GLOBALS['ab_template']; }
function switch_theme( $s ) { $GLOBALS['ab_switches'][] = $s; $GLOBALS['ab_stylesheet'] = $s; $GLOBALS['ab_template'] = $s; }

$GLOBALS['ab_free'] = dirname( __DIR__, 2 ) . '/includes/';
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $p = '' ) { return 'http://x/wp-admin/' . $p; }
}
if ( ! function_exists( 'network_admin_url' ) ) {
	function network_admin_url( $p = '' ) { return 'http://x/wp-admin/network/' . $p; }
}

/* The REAL files of this plugin. */
require $GLOBALS['ab_free'] . 'class-tool-registry.php';
require $GLOBALS['ab_free'] . 'class-guidance.php';
require $GLOBALS['ab_free'] . 'class-post-status.php';
require $GLOBALS['ab_free'] . 'tools/class-tools-base.php';
require $GLOBALS['ab_free'] . 'tools/class-tools-plugins-themes-manage.php';
require $GLOBALS['ab_free'] . 'undo/class-undo.php';
require $GLOBALS['ab_free'] . 'undo/class-undo-restore.php';

$fails = 0;
$count = 0;
function ok( $c, $label ) {
	global $fails, $count;
	$count++;
	if ( ! $c ) { $fails++; echo "  FAIL: $label\n"; }
}
function reset_all() {
	$GLOBALS['ab_opts'] = array();
	$GLOBALS['ab_caps'] = array( 'switch_themes' => true, 'edit_posts' => true, 'edit_post:5' => true, 'publish_posts' => true, 'edit_others_posts' => true );
	$GLOBALS['ab_stylesheet'] = 'old';
	$GLOBALS['ab_template']   = 'old';
	$GLOBALS['ab_switches']   = array();
	$GLOBALS['ab_updates']    = array();
	$GLOBALS['ab_untrashed']  = array();
	$GLOBALS['ab_parent_calls'] = array();
	$GLOBALS['ab_parent_fail']  = false;
	$GLOBALS['ab_multisite']    = false;
	$GLOBALS['ab_untrash_veto'] = false;
	$GLOBALS['ab_untrash_noop'] = false;
	$GLOBALS['ab_trash_veto']   = false;
	$GLOBALS['ab_trashed']      = array();
	$GLOBALS['ab_store_other']  = null;
	$GLOBALS['ab_store_status'] = null;
	$GLOBALS['ab_hooked']       = array();
	$GLOBALS['ab_untrash_previous'] = null;
	$GLOBALS['ab_untrash_stored']   = array();
	$GLOBALS['ab_untrash_along']    = null;
	$GLOBALS['ab_untrash_then']     = null;
	$GLOBALS['ab_meta_deleted']     = array();
	$GLOBALS['ab_meta_store']       = array();
	$GLOBALS['ab_updates_wp_error']  = array();
	$GLOBALS['ab_update_fails']     = false;
	$GLOBALS['ab_template_gone']    = false;
	$GLOBALS['ab_untrash_save_fails'] = false;
	$GLOBALS['ab_get_post_calls']   = 0;
	$GLOBALS['ab_tz']           = 'UTC';
	$GLOBALS['ab_concurrent']   = null;
	$GLOBALS['ab_nested_update'] = false;
	$GLOBALS['ab_wpdb_reentry'] = 0;
	$GLOBALS['ab_post_cache']   = array();
	$GLOBALS['ab_wpdb_reads']   = array();
	unset( $GLOBALS['ab_status_lie'] );
	$GLOBALS['ab_seq']          = array();
	$GLOBALS['ab_actions']      = array();
	$GLOBALS['ab_stati']        = array( 'publish' => array( 'public' => true ), 'draft' => array( 'date_floating' => true ), 'pending' => array( 'date_floating' => true ), 'private' => array( 'private' => true ), 'future' => array( 'protected' => true ), 'trash' => array( 'internal' => true ), 'auto-draft' => array( 'internal' => true, 'date_floating' => true ), 'inherit' => array( 'internal' => true ), 'in-review' => array( 'date_floating' => true ) );
	ab_post( 5 );
}
function claim( $token ) { return AB_MCP_Undo::claim( $token ); }

/* ================================================== theme: record → plan → apply */
reset_all();
$GLOBALS['ab_stylesheet'] = 'new'; // the switch already happened
$t = AB_MCP_Undo::record( array( 'tool' => 'wp_activate_theme', 'cap' => 'switch_themes', 'kind' => 'theme', 'summary' => 'old -> new', 'items' => 1, 'data' => array( 'stylesheet' => 'old', 'template' => 'old' ) ) );
ok( is_string( $t ) && 0 === strpos( $t, 'undo_' ), 'theme: record returns a token' );
$c = claim( $t );
ok( ! is_wp_error( $c ) && 'theme' === $c['entry']['kind'], 'theme: claim reads the inline snapshot back' );
$plan = AB_MCP_Undo_Restore::plan( $c['entry'], $c['data'] );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'] && 'old' === $plan['items'][0]['to'] && 'new' === $plan['items'][0]['from'], 'theme: plan restores the previous theme' );
$res = AB_MCP_Undo_Restore::apply( $c['entry'], $c['data'] );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && array( 'old' ) === $GLOBALS['ab_switches'] && 'old' === get_stylesheet(), 'theme: apply switches back exactly once' );
ok( isset( $res['probe'][0]['probe']['not_checked'] ) && 'old' === $res['probe'][0]['ref'], 'theme: the switch back goes through the checked switch of wp_activate_theme, and the undo answer carries its report (before 4.7.3 switch_theme() alone)' );
$res2 = AB_MCP_Undo_Restore::apply( $c['entry'], $c['data'] );
ok( ! is_wp_error( $res2 ) && 1 === $res2['undone'] && 1 === count( $GLOBALS['ab_switches'] ), 'theme: an already active theme is not switched again' );

/* theme gone / broken / forbidden / corrupt */
reset_all();
$t = AB_MCP_Undo::record( array( 'tool' => 'wp_activate_theme', 'cap' => 'switch_themes', 'kind' => 'theme', 'summary' => '', 'items' => 1, 'data' => array( 'stylesheet' => 'vanished', 'template' => 'vanished' ) ) );
$c = claim( $t );
$plan = AB_MCP_Undo_Restore::plan( $c['entry'], $c['data'] );
ok( ! is_wp_error( $plan ) && 0 === $plan['restorable'] && 'gone' === $plan['blocked'][0]['reason'], 'theme: a theme that is no longer installed is blocked, not an error' );
$t = AB_MCP_Undo::record( array( 'tool' => 'wp_activate_theme', 'cap' => 'switch_themes', 'kind' => 'theme', 'summary' => '', 'items' => 1, 'data' => array( 'stylesheet' => 'broken', 'template' => 'x' ) ) );
$c = claim( $t );
$plan = AB_MCP_Undo_Restore::plan( $c['entry'], $c['data'] );
ok( ! is_wp_error( $plan ) && 0 === $plan['restorable'] && 'broken' === $plan['blocked'][0]['reason'], 'theme: a broken theme is blocked' );
$t = AB_MCP_Undo::record( array( 'tool' => 'wp_activate_theme', 'cap' => 'switch_themes', 'kind' => 'theme', 'summary' => '', 'items' => 1, 'data' => array( 'stylesheet' => 'old', 'template' => 'old' ) ) );
$c = claim( $t );
$GLOBALS['ab_caps']['switch_themes'] = false;
$plan = AB_MCP_Undo_Restore::plan( $c['entry'], $c['data'] );
ok( is_wp_error( $plan ) && 'ab_mcp_undo_forbidden' === $plan->get_error_code(), 'theme: without switch_themes NOW the restore is refused' );
$GLOBALS['ab_caps']['switch_themes'] = true;
foreach ( array( array( 'template' => 'old' ), array( 'stylesheet' => '../x', 'template' => 'x' ), array( 'stylesheet' => '.hidden', 'template' => 'x' ), 'old' ) as $bad ) {
	$plan = AB_MCP_Undo_Restore::plan( $c['entry'], $bad );
	ok( is_wp_error( $plan ) && 'ab_mcp_undo_corrupt' === $plan->get_error_code(), 'theme: hand-edited snapshot refused: ' . json_encode( $bad ) );
}

/* ================================================== the switch itself (the tool, and the install with activate) */
reset_all();
$r = AB_MCP_Tools_Plugins_Themes_Manage::switch_to( 'new', 'wp_install_theme_zip' );
ok( ! is_wp_error( $r ) && true === $r['activated'] && 'old' === $r['previous']['slug'] && 'new' === get_stylesheet(), 'switch_to: switches and names the previous theme' );
ok( is_string( $r['undo'] ), 'switch_to: records an undo point' );
$c = claim( $r['undo'] );
ok( ! is_wp_error( $c ) && 'wp_activate_theme' === $c['entry']['tool'] && 'old' === $c['data']['stylesheet'] && false !== strpos( $c['entry']['summary'], 'wp_install_theme_zip' ), 'switch_to: the undo point belongs to wp_activate_theme and names the caller' );
$res = AB_MCP_Undo_Restore::apply( $c['entry'], $c['data'] );
ok( ! is_wp_error( $res ) && 'old' === get_stylesheet(), 'switch_to: its undo point restores the previous theme' );
$r = AB_MCP_Tools_Plugins_Themes_Manage::switch_to( 'old' );
ok( ! is_wp_error( $r ) && false === $r['activated'] && ! empty( $r['already_active'] ), 'switch_to: the active theme is reported, not switched' );
$r = AB_MCP_Tools_Plugins_Themes_Manage::switch_to( 'nope' );
ok( is_wp_error( $r ) && 'ab_mcp_no_theme' === $r->get_error_code(), 'switch_to: unknown theme refused' );
$r = AB_MCP_Tools_Plugins_Themes_Manage::switch_to( 'broken' );
ok( is_wp_error( $r ) && 'ab_mcp_theme_broken' === $r->get_error_code(), 'switch_to: broken theme refused' );
foreach ( array( '', '../x', '.git', 'a/.b', "a\\b", "a\0b", 'a/b/c', 'a//b', '/a' ) as $bad ) {
	$r = AB_MCP_Tools_Plugins_Themes_Manage::switch_to( $bad );
	ok( is_wp_error( $r ) && 'ab_mcp_bad_theme' === $r->get_error_code(), 'switch_to: bad slug refused: ' . json_encode( $bad ) );
}
$r = AB_MCP_Tools_Plugins_Themes_Manage::switch_to( 'sl ug' );
ok( is_wp_error( $r ) && 'ab_mcp_no_theme' === $r->get_error_code(), 'switch_to: a name of directory shape that is not installed is "not found", not "invalid"' );
$GLOBALS['ab_caps']['switch_themes'] = false;
$r = AB_MCP_Tools_Plugins_Themes_Manage::switch_to( 'new' );
ok( is_wp_error( $r ) && 'ab_mcp_forbidden' === $r->get_error_code() && 'old' === get_stylesheet(), 'switch_to: without switch_themes nothing is switched' );
reset_all();
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array( 'theme' => 'new' ) );
ok( ! is_wp_error( $r ) && true === $r['activated'], 'wp_activate_theme: the tool goes through the same switch' );
$r = AB_MCP_Tools_Plugins_Themes_Manage::activate_theme( array() );
ok( is_wp_error( $r ) && 'ab_mcp_missing_arg' === $r->get_error_code(), 'wp_activate_theme: theme is required' );


/* theme: the network's allow list, requirements, nested and underscored names, an unrecordable previous name */
reset_all();
$GLOBALS['ab_multisite'] = true;
$r = AB_MCP_Tools_Plugins_Themes_Manage::switch_to( 'nope-net' );
ok( is_wp_error( $r ) && 'ab_mcp_theme_not_allowed' === $r->get_error_code() && 'old' === get_stylesheet(), 'review: a theme the network withholds is refused by the tool' );
$tentry = array( 'tool' => 'wp_activate_theme', 'kind' => 'theme' );
$plan   = AB_MCP_Undo_Restore::plan( $tentry, array( 'stylesheet' => 'nope-net', 'template' => 'nope-net' ) );
ok( ! is_wp_error( $plan ) && 0 === $plan['restorable'] && 'forbidden' === $plan['blocked'][0]['reason'], 'review: ... and blocked by the undo planner' );
$GLOBALS['ab_multisite'] = false;
$r = AB_MCP_Tools_Plugins_Themes_Manage::switch_to( 'nope-net' );
ok( ! is_wp_error( $r ) && true === $r['activated'], 'review: on a single site the allow list does not apply' );
reset_all();
$r = AB_MCP_Tools_Plugins_Themes_Manage::switch_to( 'needs-php9' );
ok( is_wp_error( $r ) && 'ab_mcp_theme_requirements' === $r->get_error_code() && false !== strpos( $r->get_error_message(), 'PHP' ) && array() === $GLOBALS['ab_switches'], 'review: an unmet requirement comes back as an answer, not as wp_die()' );
$plan = AB_MCP_Undo_Restore::plan( $tentry, array( 'stylesheet' => 'needs-php9', 'template' => 'x' ) );
ok( ! is_wp_error( $plan ) && 'requirements' === $plan['blocked'][0]['reason'], 'review: ... and the planner blocks it' );
$res = AB_MCP_Undo_Restore::apply( $tentry, array( 'stylesheet' => 'needs-php9', 'template' => 'x' ) );
ok( ! is_wp_error( $res ) && 0 === $res['undone'] && array() === $GLOBALS['ab_switches'], 'review: ... and apply switches nothing' );
reset_all();
$r = AB_MCP_Tools_Plugins_Themes_Manage::switch_to( 'collection/theme' );
ok( ! is_wp_error( $r ) && true === $r['activated'] && 'collection/theme' === get_stylesheet() && is_string( $r['undo'] ), 'review: a theme inside a collection is switched to by its two-level name' );
$r = AB_MCP_Tools_Plugins_Themes_Manage::switch_to( '_under' );
ok( ! is_wp_error( $r ) && true === $r['activated'] && is_string( $r['undo'] ), 'review: an underscored directory is a theme too' );
$c   = claim( $r['undo'] );
$res = AB_MCP_Undo_Restore::apply( $c['entry'], $c['data'] );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && 'collection/theme' === get_stylesheet(), 'review: ... and its undo restores the nested theme' );
reset_all();
$GLOBALS['ab_stylesheet'] = 'strange\\name';
$r = AB_MCP_Tools_Plugins_Themes_Manage::switch_to( 'new' );
ok( ! is_wp_error( $r ) && true === $r['activated'] && null === $r['undo'] && ! empty( $r['undo_note'] ) && 'new' === get_stylesheet(), 'review: a previous theme that could never be restored yields no token, and says so' );
foreach ( array( 'broken', 'needs-php9', 'vanished' ) as $prev ) {
	reset_all();
	$GLOBALS['ab_stylesheet'] = $prev;
	$r = AB_MCP_Tools_Plugins_Themes_Manage::switch_to( 'new' );
	ok( ! is_wp_error( $r ) && true === $r['activated'] && null === $r['undo'] && false !== strpos( (string) $r['undo_note'], 'cannot be switched back' ), 'review 2: switching away from a theme that could not be switched back to (' . $prev . ') yields no token, with the reason' );
}


printf( "UNDO-THEME: %d passed, %d failed\n", $count - $fails, $fails );
exit( $fails ? 1 : 0 );
