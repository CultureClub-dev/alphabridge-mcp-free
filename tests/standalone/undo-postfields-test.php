<?php
/**
 * The undo of wp_update_post (kind post_fields) and the status write it shares
 * with the undo of wp_bulk_update_status, without WordPress. Posts and options
 * are in-memory stubs, wp_update_post, wp_trash_post and wp_untrash_post are
 * recorders. Ported from the post-fields part of AlphaBridge MCP Pro's
 * test/undo-theme-postfields-test.php to AB_MCP_Undo_Restore and the real
 * AB_MCP_Post_Status of this plugin (since 4.9.0).
 *
 * Proves: a restore re-checks the capability of the ORIGINAL tool and each
 * post as it is NOW (a status the account may not set, an author it may not
 * reassign, a file left to its parent without the right to publish); a
 * hand-edited snapshot with an unknown column or a value in the wrong form is
 * refused outright; status and dates are judged as the pair WordPress will
 * store; the write is judged again on the row it starts from; the trash is a
 * workflow, not a column; and what the site stored is read back.
 *
 * Left out against Pro's script: the theme kind, the theme switch and the
 * deploy flag (wp_activate_theme is a tool of Pro), and the recorder, which is
 * Pro's AB_MCP_Tools_Content_Pro::update_post() there: here the recorder is
 * AB_MCP_Tools_Content::update_post() itself, tested with the real write path
 * in tests/FreeSinceFourNineUndoTest.php.
 *
 * Run:  php tests/standalone/undo-postfields-test.php
 */

error_reporting( E_ALL & ~E_DEPRECATED );

define( 'ABSPATH', __DIR__ . '/' );
define( 'KB_IN_BYTES', 1024 );
define( 'MB_IN_BYTES', 1048576 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'EMPTY_TRASH_DAYS', false !== getenv( 'AB_TEST_NO_TRASH' ) ? 0 : 30 );   // 0: a site without a trash, run in a process of its own (see below)

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
	public function get_error_data( $code = '' ) { return $this->data; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function admin_url( $p = '' ) { return 'http://x/wp-admin/' . $p; }

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
function wp_cache_delete( $k, $group = '' ) { if ( 'posts' === $group ) { unset( $GLOBALS['ab_post_cache'][ (int) $k ] ); } return true; }
function apply_filters( $tag, $value ) { return $value; }
function wp_json_encode( $v, $options = 0, $depth = 512 ) { return json_encode( $v, $options, $depth ); }
function wp_salt( $s = 'auth' ) { return 'test-salt-value'; }
function size_format( $bytes, $decimals = 0 ) { return $bytes . ' B'; }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
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
$GLOBALS['ab_tmp'] = sys_get_temp_dir() . '/ab-free-undo-pf-' . getmypid() . '-' . bin2hex( random_bytes( 6 ) );
@mkdir( $GLOBALS['ab_tmp'], 0755, true );
register_shutdown_function( static function () { ab_test_rm_tree( $GLOBALS['ab_tmp'] ); } );
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['ab_tmp'], 'baseurl' => 'http://x', 'error' => false ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0755, true ); }
function wp_slash( $v ) { return $v; }
function is_multisite() { return false; }
/* registered statuses, as WordPress registers them (a plugin may add a public one) */
$GLOBALS['ab_stati'] = array();
function get_post_stati( $args = array(), $output = 'names' ) { $k = array(); foreach ( $GLOBALS['ab_stati'] as $name => $props ) { if ( isset( $args['date_floating'] ) && (bool) ( $props['date_floating'] ?? false ) !== (bool) $args['date_floating'] ) { continue; } $k[] = $name; } return $k ? array_combine( $k, $k ) : array(); }
function get_post_status_object( $s ) { return isset( $GLOBALS['ab_stati'][ $s ] ) ? (object) array_merge( array( 'name' => $s, 'public' => false, 'private' => false, 'publicly_queryable' => false ), $GLOBALS['ab_stati'][ $s ] ) : null; }
function get_gmt_from_date( $d ) { return $d; }
/* filters with priorities: what wp_untrash_post_status runs through */
$GLOBALS['ab_hooked'] = array();
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['ab_hooked'][ $tag ][ $prio ][] = $cb; return true; }
function has_filter( $tag, $cb = false ) { foreach ( $GLOBALS['ab_hooked'][ $tag ] ?? array() as $cbs ) { if ( ! empty( $cbs ) ) { return true; } } return false; }
function remove_filter( $tag, $cb, $prio = 10 ) { foreach ( $GLOBALS['ab_hooked'][ $tag ][ $prio ] ?? array() as $i => $c ) { if ( $c === $cb ) { unset( $GLOBALS['ab_hooked'][ $tag ][ $prio ][ $i ] ); return true; } } return false; }
function ab_run_hooked( $tag, $value, ...$args ) { $all = $GLOBALS['ab_hooked'][ $tag ] ?? array(); ksort( $all ); foreach ( $all as $cbs ) { foreach ( $cbs as $cb ) { $value = $cb( $value, ...$args ); } } return $value; }
function wp_timezone() { return new DateTimeZone( $GLOBALS['ab_tz'] ); }

/* ---- posts ---- */
$GLOBALS['ab_posts'] = array();
function ab_post( $id, $over = array() ) {
	$GLOBALS['ab_posts'][ $id ] = (object) array_merge(
		array( 'ID' => $id, 'post_title' => 'Old title', 'post_content' => 'Old body', 'post_excerpt' => '', 'post_status' => 'publish', 'post_name' => 'old', 'post_parent' => 0, 'post_author' => '1', 'post_type' => 'post', 'post_date' => '2026-09-01 10:00:00', 'post_date_gmt' => '2026-09-01 08:00:00', 'post_password' => '' ),
		$over
	);
}
/* get_post() answers from the object cache when there is one; the table is $GLOBALS['ab_posts'] */
$GLOBALS['ab_post_cache']     = array();
$GLOBALS['ab_get_post_calls'] = 0;
function get_post( $id ) { if ( ++$GLOBALS['ab_get_post_calls'] > 20000 ) { fwrite( STDERR, "get_post() called without end — a walk up the parents that does not stop\n" ); exit( 1 ); } if ( isset( $GLOBALS['ab_post_cache'][ (int) $id ] ) ) { return $GLOBALS['ab_post_cache'][ (int) $id ]; } return isset( $GLOBALS['ab_posts'][ (int) $id ] ) ? $GLOBALS['ab_posts'][ (int) $id ] : null; }
function ab_table_post( $id ) { return isset( $GLOBALS['ab_posts'][ (int) $id ] ) ? $GLOBALS['ab_posts'][ (int) $id ] : null; }
/* the trash notes a move to the trash clears first — noted with how many writes came before */
$GLOBALS['ab_meta_deleted'] = array();
function delete_post_meta( $id, $key ) { $GLOBALS['ab_meta_deleted'][] = array( (int) $id, $key, count( $GLOBALS['ab_seq'] ) ); unset( $GLOBALS['ab_meta_store'][ (int) $id ][ $key ] ); return true; }
$GLOBALS['ab_meta_store'] = array();
function get_post_meta( $id, $key = '', $single = false ) { $v = $GLOBALS['ab_meta_store'][ (int) $id ][ $key ] ?? array(); return $single ? ( $v ? $v[0] : '' ) : $v; }
function add_post_meta( $id, $key, $value, $unique = false ) { $GLOBALS['ab_meta_store'][ (int) $id ][ $key ][] = $value; return true; }
function get_post_status( $id ) { if ( isset( $GLOBALS['ab_status_lie'] ) ) { return $GLOBALS['ab_status_lie']; } $p = get_post( $id ); return $p ? $p->post_status : false; } // a plugin's get_post_status filter can say anything
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );
/* the posts table, read raw: what the restore's checks look at */
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
		if ( ! preg_match( '/WHERE ID = (\d+)/', $sql, $m ) ) { return null; }
		$p = isset( $GLOBALS['ab_posts'][ (int) $m[1] ] ) ? $GLOBALS['ab_posts'][ (int) $m[1] ] : null;
		if ( ! $p ) { return null; }
		$row = array();
		// Exactly the columns the SELECT names, as MySQL answers: a column left out of the query is missing from the row.
		if ( ! preg_match( '/^SELECT (.+?) FROM /', $sql, $sel ) ) { return null; }
		foreach ( array_map( 'trim', explode( ',', $sel[1] ) ) as $c ) { $row[ $c ] = (string) $p->$c; } // MySQL hands back strings
		return $row;
	}
}
$GLOBALS['wpdb']          = new AB_Test_WPDB();
$GLOBALS['ab_wpdb_reads'] = array();
function get_post_type( $id ) { $p = get_post( $id ); return $p ? $p->post_type : false; }
function get_post_type_object( $t ) { return (object) array( 'cap' => (object) array( 'edit_others_posts' => 'edit_others_posts', 'publish_posts' => 'publish_posts' ) ); }
$GLOBALS['ab_updates']   = array();
$GLOBALS['ab_untrashed'] = array();
$GLOBALS['ab_seq']       = array();
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
		$new = ab_run_hooked( 'wp_untrash_post_status', null !== $GLOBALS['ab_untrash_previous'] ? $GLOBALS['ab_untrash_previous'] : ( 'attachment' === $p->post_type ? 'inherit' : 'draft' ), (int) $id, (string) $GLOBALS['ab_untrash_previous'] );
		if ( 'future' === $new && strtotime( $p->post_date_gmt . ' UTC' ) - time() < MINUTE_IN_SECONDS ) { $new = 'publish'; }
		// WordPress deletes the trash notes before it saves; a save that fails leaves the post in the trash without them.
		delete_post_meta( $id, '_wp_trash_meta_status' );
		delete_post_meta( $id, '_wp_trash_meta_time' );
		if ( ! empty( $GLOBALS['ab_untrash_save_fails'] ) ) { return false; }
		$GLOBALS['ab_untrash_stored'][] = $new;
		$p->post_status = $new;
		if ( 'private' === $new ) { $p->post_password = ''; }
		if ( null !== $GLOBALS['ab_untrash_then'] ) { ( $GLOBALS['ab_untrash_then'] )( $p ); }
	}
	return $p;
}
function wp_trash_post( $id ) {
	$GLOBALS['ab_seq'][] = 'trash';
	if ( ! empty( $GLOBALS['ab_trash_veto'] ) ) { return false; }
	$GLOBALS['ab_trashed'][] = (int) $id; $p = ab_table_post( $id ); if ( $p ) { $p->post_status = 'trash'; } return $p;
}

/* The REAL files of this plugin. */
require $GLOBALS['ab_free'] . 'class-tool-registry.php';
require $GLOBALS['ab_free'] . 'class-guidance.php';
require $GLOBALS['ab_free'] . 'class-post-status.php';
require $GLOBALS['ab_free'] . 'undo/class-undo.php';
require $GLOBALS['ab_free'] . 'undo/class-undo-restore.php';

/* A test door to the write alone, so the table can change between plan and
 * write; and to the error code of each item apply() writes. */
class AB_Test_Restore_Write extends AB_MCP_Undo_Restore {
	public static $codes = array();
	public static function write_item( array $item ) { return static::restore_post_fields( $item ); }
	public static function write_status_item( array $item ) { return static::restore_post_status( $item ); }
	protected static function restore_post_fields( array $item ) {
		$r = parent::restore_post_fields( $item );
		self::$codes[] = is_wp_error( $r ) ? $r->get_error_code() : true;
		return $r;
	}
}

$fails = 0;
$count = 0;
function ok( $c, $label ) {
	global $fails, $count;
	$count++;
	if ( ! $c ) { $fails++; echo "  FAIL: $label\n"; }
}
function ab_code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ''; }
function reset_all() {
	$GLOBALS['ab_opts'] = array();
	$GLOBALS['ab_caps'] = array( 'edit_posts' => true, 'edit_post:5' => true, 'publish_posts' => true, 'edit_others_posts' => true );
	$GLOBALS['ab_updates']    = array();
	$GLOBALS['ab_untrashed']  = array();
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
	$GLOBALS['ab_updates_wp_error'] = array();
	$GLOBALS['ab_update_fails']     = false;
	$GLOBALS['ab_template_gone']    = false;
	$GLOBALS['ab_untrash_save_fails'] = false;
	$GLOBALS['ab_get_post_calls']   = 0;
	$GLOBALS['ab_tz']           = 'UTC';
	$GLOBALS['ab_post_cache']   = array();
	$GLOBALS['ab_wpdb_reads']   = array();
	unset( $GLOBALS['ab_status_lie'] );
	$GLOBALS['ab_seq']          = array();
	$GLOBALS['ab_stati']        = array( 'publish' => array( 'public' => true ), 'draft' => array( 'date_floating' => true ), 'pending' => array( 'date_floating' => true ), 'private' => array( 'private' => true ), 'future' => array( 'protected' => true ), 'trash' => array( 'internal' => true ), 'auto-draft' => array( 'internal' => true, 'date_floating' => true ), 'inherit' => array( 'internal' => true ), 'in-review' => array( 'date_floating' => true ) );
	AB_Test_Restore_Write::$codes = array();
	ab_post( 5 );
}
function claim( $token ) { return AB_MCP_Undo::claim( $token ); }

/* A site without a trash (EMPTY_TRASH_DAYS 0), where wp_trash_post() deletes for good. The constant cannot change
 * within a run, so this file runs again with AB_TEST_NO_TRASH set (last section), and then only these cases run. */
if ( 0 === EMPTY_TRASH_DAYS ) {
	reset_all();
	$GLOBALS['ab_caps']['delete_post:5'] = true;
	$plan = AB_MCP_Undo_Restore::plan( array( 'tool' => 'wp_update_post', 'kind' => 'post_fields' ), array( 'id' => 5, 'fields' => array( 'post_status' => 'trash', 'post_title' => 'Old title' ) ) );
	ok( ! is_wp_error( $plan ) && 'no_trash' === ( $plan['blocked'][0]['reason'] ?? '' ) && '5:post_status' === $plan['blocked'][0]['ref'] && array( 'post_title' => 'Old title' ) === $plan['items'][0]['fields'], 'no trash: "trash" is not put back — it would delete the post for good; the title still is' );
	$res = AB_MCP_Undo_Restore::apply( array( 'tool' => 'wp_bulk_update_status', 'kind' => 'post_status' ), array( array( 5, 'trash' ) ) );
	ok( ! is_wp_error( $res ) && 0 === $res['undone'] && 'no_trash' === ( $res['blocked'][0]['reason'] ?? '' ) && array() === $GLOBALS['ab_trashed'], 'no trash: ... nor by the undo of the bulk tool' );
	printf( "UNDO-POSTFIELDS (no trash): %d passed, %d failed\n", $count - $fails, $fails );
	exit( $fails ? 1 : 0 );
}

/* ================================================== post fields: record → plan → apply */
reset_all();
$t = AB_MCP_Undo::record( array( 'tool' => 'wp_update_post', 'cap' => 'edit_posts', 'kind' => 'post_fields', 'summary' => '#5', 'items' => 1, 'data' => array( 'id' => 5, 'fields' => array( 'post_title' => 'Old title', 'post_content' => 'Old body' ) ) ) );
ok( is_string( $t ), 'post_fields: record returns a token' );
$c    = claim( $t );
$plan = AB_MCP_Undo_Restore::plan( $c['entry'], $c['data'] );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'] && array( 'post_title' => 'Old title', 'post_content' => 'Old body' ) === $plan['items'][0]['fields'], 'post_fields: plan restores exactly the stored fields' );
ab_post( 5, array( 'post_title' => 'New title', 'post_content' => 'New body' ) );
$res = AB_MCP_Undo_Restore::apply( $c['entry'], $c['data'] );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && 1 === count( $GLOBALS['ab_updates'] ) && array( 'ID' => 5, 'post_title' => 'Old title', 'post_content' => 'Old body' ) === $GLOBALS['ab_updates'][0], 'post_fields: apply writes ID plus the stored fields, nothing else' );
ok( 'Old title' === ab_table_post( 5 )->post_title && 'Old body' === ab_table_post( 5 )->post_content, 'post_fields: and the table has them again' );
ok( array( false ) === $GLOBALS['ab_updates_wp_error'], 'post_fields: written without $wp_error, as the tool writes' );

/* status the account may not set: left out, the rest restored */
reset_all();
$GLOBALS['ab_caps']['publish_posts'] = false;
ab_post( 5, array( 'post_status' => 'draft' ) );
$c    = claim( AB_MCP_Undo::record( array( 'tool' => 'wp_update_post', 'cap' => 'edit_posts', 'kind' => 'post_fields', 'summary' => '', 'items' => 1, 'data' => array( 'id' => 5, 'fields' => array( 'post_status' => 'publish', 'post_title' => 'Old title' ) ) ) ) );
$plan = AB_MCP_Undo_Restore::plan( $c['entry'], $c['data'] );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'] && array( 'post_title' => 'Old title' ) === $plan['items'][0]['fields'] && '5:post_status' === $plan['blocked'][0]['ref'] && 'forbidden' === $plan['blocked'][0]['reason'], 'post_fields: a status the account may not set is left out and reported' );

/* author without edit_others_posts: left out */
reset_all();
$GLOBALS['ab_caps']['edit_others_posts'] = false;
$entry = array( 'tool' => 'wp_update_post', 'kind' => 'post_fields' );
$plan  = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_author' => 3, 'post_excerpt' => 'x' ) ) );
ok( ! is_wp_error( $plan ) && array( 'post_excerpt' => 'x' ) === $plan['items'][0]['fields'] && '5:post_author' === $plan['blocked'][0]['ref'], 'post_fields: authorship is restored only with edit_others_posts' );

/* post gone, edit denied */
reset_all();
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 99, 'fields' => array( 'post_title' => 'x' ) ) );
ok( ! is_wp_error( $plan ) && 0 === $plan['restorable'] && 'gone' === $plan['blocked'][0]['reason'], 'post_fields: a deleted post is blocked' );
$GLOBALS['ab_caps']['edit_post:5'] = false;
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_title' => 'x' ) ) );
ok( ! is_wp_error( $plan ) && 0 === $plan['restorable'] && 'forbidden' === $plan['blocked'][0]['reason'], 'post_fields: a post the account may no longer edit is blocked' );
$GLOBALS['ab_caps']['edit_posts'] = false;
ok( 'ab_mcp_undo_forbidden' === ab_code( AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_title' => 'x' ) ) ) ), 'post_fields: without edit_posts the gate of the original tool refuses' );

/* hand-edited snapshots */
reset_all();
foreach ( array(
	array( 'id' => 5, 'fields' => array( 'post_password' => 'x' ) ),
	array( 'id' => 5, 'fields' => array( 'post_type' => 'page' ) ),
	array( 'id' => 5, 'fields' => array( 'post_title' => array( 'x' ) ) ),
	array( 'id' => 5, 'fields' => array() ),
	array( 'id' => 5 ),
	array( 'fields' => array( 'post_title' => 'x' ) ),
	array( 'id' => 5, 'fields' => array( 0 => 'x' ) ),
	'x',
) as $bad ) {
	ok( 'ab_mcp_undo_corrupt' === ab_code( AB_MCP_Undo_Restore::plan( $entry, $bad ) ), 'post_fields: refused: ' . json_encode( $bad ) );
}
ok( array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'post_author', 'post_date', 'post_date_gmt' ) === AB_MCP_Undo_Restore::post_fields_allowed(), 'post_fields: exactly the columns wp_update_post writes may come back — no password, no type' );

/* trash: untrash first */
reset_all();
ab_post( 5, array( 'post_status' => 'trash' ) );
$GLOBALS['ab_caps']['delete_post:5'] = true;
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'draft' ) ) );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && array( 5 ) === $GLOBALS['ab_untrashed'], 'post_fields: a trashed post is untrashed before its status is restored' );

/* ================================================== review round: shape, status form, dates, trash, read-back */
reset_all();
foreach ( array(
	array( 'id' => 5, 'fields' => array( 'post_status' => 'PUBLISH' ) ),
	array( 'id' => 5, 'fields' => array( 'post_status' => 'pub!lish' ) ),
	array( 'id' => 5, 'fields' => array( 'post_status' => '' ) ),
	array( 'id' => 5, 'fields' => array( 'post_status' => 7 ) ),
	array( 'id' => array( 999 ), 'fields' => array( 'post_title' => 'x' ) ),
	array( 'id' => '05', 'fields' => array( 'post_title' => 'x' ) ),
	array( 'id' => 0, 'fields' => array( 'post_title' => 'x' ) ),
	array( 'id' => 5.0, 'fields' => array( 'post_title' => 'x' ) ),
	array( 'id' => 5, 'fields' => array( 'post_title' => false ) ),
	array( 'id' => 5, 'fields' => array( 'post_title' => 7 ) ),
	array( 'id' => 5, 'fields' => array( 'post_author' => -1 ) ),
	array( 'id' => 5, 'fields' => array( 'post_author' => 1.5 ) ),
	array( 'id' => 5, 'fields' => array( 'post_parent' => '9a' ) ),
	array( 'id' => 5, 'fields' => array( 'post_parent' => true ) ),
	array( 'id' => 5, 'fields' => array( 'post_parent' => '' ) ),
) as $bad ) {
	ok( 'ab_mcp_undo_corrupt' === ab_code( AB_MCP_Undo_Restore::plan( $entry, $bad ) ), 'review: refused as a whole: ' . json_encode( $bad ) );
}
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => '5', 'fields' => array( 'post_author' => '3', 'post_parent' => 0 ) ) );
ok( ! is_wp_error( $plan ) && array( 'post_author' => 3, 'post_parent' => 0 ) === $plan['items'][0]['fields'] && 5 === $plan['items'][0]['ref'], 'review: canonical digit strings are accepted and become integers' );
ok( 'ab_mcp_undo_corrupt' === ab_code( AB_MCP_Undo_Restore::plan( array( 'tool' => 'wp_bulk_update_status', 'kind' => 'post_status' ), array( array( 5, 'PUBLISH' ) ) ) ), 'review: the bulk-status snapshot refuses a non-canonical status as well' );
$plan = AB_MCP_Undo_Restore::plan( array( 'tool' => 'wp_bulk_update_status', 'kind' => 'post_status' ), array( array( 5, 'draft' ) ) );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'], 'review: a canonical status still plans' );

/* the post's date decides whether a status would stick */
reset_all();
ab_post( 5, array( 'post_status' => 'draft', 'post_date' => '2020-01-01 10:00:00', 'post_date_gmt' => '2020-01-01 09:00:00' ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'future', 'post_title' => 'x' ) ) );
ok( ! is_wp_error( $plan ) && array( 'post_title' => 'x' ) === $plan['items'][0]['fields'] && 'date_passed' === $plan['blocked'][0]['reason'], 'review: "future" with a date that has passed is left out (WordPress would publish it)' );
ab_post( 5, array( 'post_status' => 'draft', 'post_date' => '2099-01-01 10:00:00', 'post_date_gmt' => '2099-01-01 09:00:00' ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'publish' ) ) );
ok( ! is_wp_error( $plan ) && 0 === $plan['restorable'] && 'date_ahead' === $plan['blocked'][0]['reason'], 'review: "publish" with a date still ahead is left out (WordPress would schedule it)' );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'future' ) ) );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'], 'review: "future" with a date ahead is restorable' );
ab_post( 5, array( 'post_status' => 'draft', 'post_date' => '2099-01-01 10:00:00', 'post_date_gmt' => '0000-00-00 00:00:00' ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'future' ) ) );
ok( ! is_wp_error( $plan ) && 0 === $plan['restorable'] && 'date_passed' === $plan['blocked'][0]['reason'], 'review 2: a draft without a GMT date is dated NOW by wp_update_post(), so "future" would publish it — blocked' );
foreach ( array( 'pending', 'auto-draft' ) as $st ) {
	ab_post( 5, array( 'post_status' => $st, 'post_date' => '2099-01-01 10:00:00', 'post_date_gmt' => '0000-00-00 00:00:00' ) );
	$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'future' ) ) );
	ok( ! is_wp_error( $plan ) && 'date_passed' === $plan['blocked'][0]['reason'], 'review 2: the same for ' . $st );
}
ab_post( 5, array( 'post_status' => 'private', 'post_date' => '2099-01-01 10:00:00', 'post_date_gmt' => '0000-00-00 00:00:00' ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'future' ) ) );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'], 'review 2: any other status without a GMT date keeps its local date' );
ab_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_date' => '2020-01-01 10:00:00', 'post_date_gmt' => '2020-01-01 09:00:00' ) );
$GLOBALS['ab_caps']['delete_post:5'] = true;
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'future', 'post_title' => 'x' ) ) );
ok( ! is_wp_error( $plan ) && array( 'post_title' => 'x' ) === $plan['items'][0]['fields'] && 'attachment_status' === $plan['blocked'][0]['reason'], 'review 3: an attachment cannot be "future" — so the status is left out' );
foreach ( array( 'inherit', 'private', 'trash' ) as $st ) {
	$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => $st ) ) );
	ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'], 'review 3: an attachment may be restored to ' . $st );
}
ab_post( 5 );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'draft' ) ) );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'], 'review: a status without a date rule is not touched by it' );

/* trash goes through the trash functions; a veto stops the restore before any write */
reset_all();
$GLOBALS['ab_caps']['delete_post:5'] = true;
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'trash', 'post_title' => 'Old title' ) ) );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && array( 5 ) === $GLOBALS['ab_trashed'] && 1 === count( $GLOBALS['ab_updates'] ) && ! isset( $GLOBALS['ab_updates'][0]['post_status'] ) && 'trash' === get_post_status( 5 ), 'review: back into the trash via wp_trash_post(); the other fields via wp_update_post() without a status' );
ok( array( 'trash', 'update' ) === $GLOBALS['ab_seq'], 'review 2: the move to the trash comes FIRST, the other fields after it' );
ok( array( array( 5, '_wp_trash_meta_status', 0 ), array( 5, '_wp_trash_meta_time', 0 ) ) === $GLOBALS['ab_meta_deleted'], 'trash notes: the status and the time an earlier stay left are cleared before the move' );
reset_all();
ab_post( 5, array( 'post_status' => 'trash' ) );
$GLOBALS['ab_meta_store'][5]      = array( '_wp_trash_meta_status' => array( 'publish', 'draft' ), '_wp_trash_meta_time' => array( '1700000000' ) );
$GLOBALS['ab_untrash_save_fails'] = true;
$res = AB_Test_Restore_Write::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'draft' ) ) );
ok( ! is_wp_error( $res ) && 0 === $res['undone'] && array( 'ab_mcp_undo_untrash_failed' ) === AB_Test_Restore_Write::$codes && 'trash' === ab_table_post( 5 )->post_status && array( '_wp_trash_meta_status' => array( 'publish', 'draft' ), '_wp_trash_meta_time' => array( '1700000000' ) ) === $GLOBALS['ab_meta_store'][5] && array() === $GLOBALS['ab_updates'], 'trash notes: where the save fails on the way out of the trash, the notes WordPress deleted before it are put back, in their order' );
$GLOBALS['ab_untrash_save_fails'] = false;
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'draft' ) ) );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && 'draft' === ab_table_post( 5 )->post_status && array() === ( $GLOBALS['ab_meta_store'][5] ?? array() ), 'trash notes: where the post came out, the notes WordPress deleted stay deleted' );
reset_all();
$GLOBALS['ab_caps']['delete_post:5'] = true;
$GLOBALS['ab_trash_veto']            = true;
$res = AB_Test_Restore_Write::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'trash', 'post_title' => 'New' ) ) );
ok( ! is_wp_error( $res ) && 0 === $res['undone'] && array( 'ab_mcp_undo_trash_failed' ) === AB_Test_Restore_Write::$codes && array() === $GLOBALS['ab_updates'] && 'publish' === get_post_status( 5 ) && 'New' !== get_post( 5 )->post_title, 'review 2: a vetoed trash writes nothing else — the live post keeps its current title' );
reset_all();
ab_post( 5, array( 'post_status' => 'trash' ) );
$GLOBALS['ab_untrash_noop'] = true;
$res = AB_Test_Restore_Write::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'draft', 'post_title' => 'x' ) ) );
ok( ! is_wp_error( $res ) && 0 === $res['undone'] && array( 'ab_mcp_undo_untrash_failed' ) === AB_Test_Restore_Write::$codes && array() === $GLOBALS['ab_updates'] && 'trash' === get_post_status( 5 ), 'review 2: an untrash that returns true without moving the post is not believed — the post itself is read' );
reset_all();
ab_post( 5, array( 'post_status' => 'trash' ) );
$GLOBALS['ab_untrash_veto'] = true;
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'draft', 'post_title' => 'x' ) ) );
ok( ! is_wp_error( $res ) && 0 === $res['undone'] && 1 === count( $res['failed'] ) && array() === $GLOBALS['ab_updates'] && 'trash' === get_post_status( 5 ), 'review: a vetoed untrash ends the restore before anything is written' );
ok( false !== strpos( $res['failed'][0]['reason'], 'wp-admin' ), 'review: and the failure names a way (wp-admin)' );
reset_all();
ab_post( 5, array( 'post_status' => 'trash' ) );
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_title' => 'x' ) ) );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && array() === $GLOBALS['ab_untrashed'] && 'trash' === get_post_status( 5 ), 'review: without a status in the snapshot a trashed post stays in the trash' );

/* what the site stored is read back and compared */
reset_all();
$GLOBALS['ab_store_other'] = array( 'post_name' => 'old-2' );
$res = AB_Test_Restore_Write::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_name' => 'old', 'post_title' => 'Old title' ) ) );
ok( ! is_wp_error( $res ) && 0 === $res['undone'] && array( 'ab_mcp_undo_post_mismatch' ) === AB_Test_Restore_Write::$codes && false !== strpos( $res['failed'][0]['reason'], 'post_name' ) && false === strpos( $res['failed'][0]['reason'], 'post_title' ), 'review: a value the site changed on the way in is reported by column, not as success' );
reset_all();
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_author' => '1', 'post_parent' => 0 ) ) );
ok( ! is_wp_error( $res ) && 1 === $res['undone'], 'review: ids compare as numbers, so the string WordPress stores matches the int restored' );

/* ================================================== review round 2: statuses a plugin registered, number range */
reset_all();
$GLOBALS['ab_stati']['approved'] = array( 'public' => true );
$GLOBALS['ab_caps']['publish_posts'] = false;
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'approved', 'post_title' => 'x' ) ) );
ok( ! is_wp_error( $plan ) && array( 'post_title' => 'x' ) === $plan['items'][0]['fields'] && 'forbidden' === $plan['blocked'][0]['reason'], 'review 2: a custom status registered as public is publishing — refused without publish_posts' );
$GLOBALS['ab_caps']['publish_posts'] = true;
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'approved' ) ) );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'], 'review 2: ... and restorable with it' );
$GLOBALS['ab_stati']['archived'] = array();
$GLOBALS['ab_caps']['publish_posts'] = false;
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'archived' ) ) );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'], 'review 2: a custom status that is not public needs no publishing right' );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'unregistered', 'post_title' => 'x' ) ) );
ok( ! is_wp_error( $plan ) && array( 'post_title' => 'x' ) === $plan['items'][0]['fields'] && 'unknown_status' === $plan['blocked'][0]['reason'], 'review 2: a status this site does not know is left out and reported' );
$plan = AB_MCP_Undo_Restore::plan( array( 'tool' => 'wp_bulk_update_status', 'kind' => 'post_status' ), array( array( 5, 'unregistered' ) ) );
ok( ! is_wp_error( $plan ) && 0 === $plan['restorable'] && 'unknown_status' === $plan['blocked'][0]['reason'], 'review 2: the bulk-status planner applies the same rule' );
$plan = AB_MCP_Undo_Restore::plan( array( 'tool' => 'wp_bulk_update_status', 'kind' => 'post_status' ), array( array( 5, 'approved' ) ) );
ok( ! is_wp_error( $plan ) && 'forbidden' === $plan['blocked'][0]['reason'], 'review 2: ... publishing rule included' );
ok( true === AB_MCP_Post_Status::status_goes_live( 'approved' ) && false === AB_MCP_Post_Status::status_goes_live( 'archived' ) && true === AB_MCP_Post_Status::status_goes_live( 'future' ) && false === AB_MCP_Post_Status::status_goes_live( 'draft' ), 'review 2: AB_MCP_Post_Status decides "goes live" by the registered flags' );
reset_all();
foreach ( array( '999999999999999999999999999999', '9223372036854775808', '-0' ) as $bad ) {
	ok( 'ab_mcp_undo_corrupt' === ab_code( AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_parent' => $bad ) ) ) ), 'review 2: a number PHP could not hold as itself is refused: ' . $bad );
}
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_parent' => (string) PHP_INT_MAX ) ) );
ok( ! is_wp_error( $plan ) && PHP_INT_MAX === $plan['items'][0]['fields']['post_parent'], 'review 2: the largest representable number still passes' );

/* ================================================== review round 3/4: raw table reads, stale object cache */
reset_all();
$GLOBALS['ab_status_lie'] = 'trash';                // a filter claims the published post is in the trash
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'draft', 'post_title' => 'Old title' ) ) );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && array() === $GLOBALS['ab_untrashed'] && 'draft' === get_post( 5 )->post_status, 'review 3: the transition check reads the table, not get_post_status() — no untrash on a post that is not in the trash' );
reset_all();
ab_post( 5, array( 'post_status' => 'trash' ) );
$GLOBALS['ab_status_lie'] = 'draft';                // a filter hides that the post IS in the trash
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'draft' ) ) );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && array( 5 ) === $GLOBALS['ab_untrashed'], 'review 3: ... and a post the table shows in the trash is untrashed whatever the filter says' );
reset_all();
$GLOBALS['ab_store_other'] = array( 'post_title' => 'filtered' );
$GLOBALS['ab_status_lie']  = 'publish';
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_title' => 'Old title' ) ) );
ok( ! is_wp_error( $res ) && 0 === $res['undone'] && count( $GLOBALS['ab_wpdb_reads'] ) >= 1, 'review 3: the read-back compares against the table row' );
reset_all();
$GLOBALS['ab_caps']['delete_post:5'] = true;
$GLOBALS['ab_post_cache'][5] = clone $GLOBALS['ab_posts'][5];   // cache invalidation suspended: the cache keeps "publish" after the trash
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'trash', 'post_title' => 'Old title' ) ) );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && 'trash' === ab_table_post( 5 )->post_status && 'Old title' === ab_table_post( 5 )->post_title, 'review 4: the cached post is dropped before the field write, so a stale "publish" in it cannot come back through wp_update_post()' );
reset_all();
$GLOBALS['ab_post_cache'][5]             = clone $GLOBALS['ab_posts'][5];
$GLOBALS['ab_post_cache'][5]->post_title = 'stale';
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_excerpt' => 'e' ) ) );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && 'Old title' === ab_table_post( 5 )->post_title && 'e' === ab_table_post( 5 )->post_excerpt, 'review 4: ... and other stale columns are not written back either' );

/* ================================================== dates: plan and restore */
reset_all();
$res  = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_date' => '2026-08-01 10:00:00', 'post_date_gmt' => '2026-08-01 08:00:00' ) ) );
$last = end( $GLOBALS['ab_updates'] );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && ! empty( $last['edit_date'] ) && '2026-08-01 10:00:00' === ab_table_post( 5 )->post_date && '2026-08-01 08:00:00' === ab_table_post( 5 )->post_date_gmt, 'dates: the undo writes both columns back, with edit_date, and the table has them again' );
reset_all();
ab_post( 5, array( 'post_status' => 'publish', 'post_date' => '2026-10-01 10:00:00', 'post_date_gmt' => '2026-10-01 08:00:00' ) );
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'draft', 'post_date' => '2026-09-01 10:00:00', 'post_date_gmt' => '0000-00-00 00:00:00' ) ) );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && 'draft' === ab_table_post( 5 )->post_status && '0000-00-00 00:00:00' === ab_table_post( 5 )->post_date_gmt, 'dates: the undo of a published draft makes it an undated draft again' );

/* "future" is judged by the date the undo puts back */
reset_all();
ab_post( 5, array( 'post_status' => 'publish', 'post_date' => '2001-01-01 10:00:00', 'post_date_gmt' => '2001-01-01 09:00:00' ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'future', 'post_date' => '2099-01-01 10:00:00', 'post_date_gmt' => '2099-01-01 09:00:00' ) ) );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'] && array() === $plan['blocked'] && 'future' === $plan['items'][0]['fields']['post_status'], 'dates: "future" is judged by the date the undo puts back, not by the passed one the post has now' );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'future' ) ) );
ok( ! is_wp_error( $plan ) && isset( $plan['blocked'][0] ) && '5:post_status' === $plan['blocked'][0]['ref'] && 'date_passed' === $plan['blocked'][0]['reason'], 'dates: without a date in the snapshot the post\'s own passed date still blocks "future"' );
ab_post( 5, array( 'post_status' => 'draft', 'post_date' => '2001-01-01 10:00:00', 'post_date_gmt' => '0000-00-00 00:00:00' ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'future', 'post_date' => '2099-01-01 10:00:00', 'post_date_gmt' => '0000-00-00 00:00:00' ) ) );
ok( ! is_wp_error( $plan ) && array( 'post_date' => '2099-01-01 10:00:00', 'post_date_gmt' => '0000-00-00 00:00:00' ) === $plan['items'][0]['fields'] && 1 === count( $plan['blocked'] ) && '5:post_status' === $plan['blocked'][0]['ref'] && 'date_passed' === $plan['blocked'][0]['reason'], 'dates: "future" with a missing UTC date is not put back: the date goes back onto the draft, the status is reported' );

/* a date pair that would publish or unpublish, or that WordPress would not store as given, is left out */
reset_all();
ab_post( 5, array( 'post_status' => 'future', 'post_date' => '2099-01-01 10:00:00', 'post_date_gmt' => '2099-01-01 09:00:00' ) );
$GLOBALS['ab_caps']['publish_posts'] = false;
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'publish', 'post_title' => 'Old title', 'post_date' => '2001-01-01 10:00:00', 'post_date_gmt' => '2001-01-01 09:00:00' ) ) );
$refs = is_wp_error( $plan ) ? array() : array_column( $plan['blocked'], 'reason', 'ref' );
ok( ! is_wp_error( $plan ) && array( 'post_title' => 'Old title' ) === $plan['items'][0]['fields'] && 'forbidden' === ( $refs['5:post_status'] ?? '' ) && 'date_passed' === ( $refs['5:post_date'] ?? '' ), 'dates: with the status blocked, a passed date is left out too — WordPress would publish the scheduled post it stays' );
$GLOBALS['ab_caps']['publish_posts'] = true;
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_date' => '2001-01-01 10:00:00', 'post_date_gmt' => '2001-01-01 09:00:00' ) ) );
ok( ! is_wp_error( $plan ) && 0 === $plan['restorable'] && '5:post_date' === $plan['blocked'][0]['ref'] && 'date_passed' === $plan['blocked'][0]['reason'], 'dates: a snapshot with only a passed date is not put back on a post scheduled since' );
reset_all();
ab_post( 5, array( 'post_status' => 'publish', 'post_date' => '2026-09-20 10:00:00', 'post_date_gmt' => '2026-09-20 08:00:00' ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_date' => '2026-09-01 10:00:00', 'post_date_gmt' => '0000-00-00 00:00:00' ) ) );
ok( ! is_wp_error( $plan ) && 0 === $plan['restorable'] && 'date_unstorable' === $plan['blocked'][0]['reason'], 'dates: a draft\'s missing UTC date is not written onto a post that is no longer a draft' );
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_date' => '2026-09-01 10:00:00', 'post_date_gmt' => '0000-00-00 00:00:00' ) ) );
ok( ! is_wp_error( $res ) && 0 === $res['undone'] && array() === $GLOBALS['ab_updates'], 'dates: ... and nothing is written, rather than a partial change reported afterwards' );
ab_post( 5, array( 'post_status' => 'draft', 'post_date' => '2026-09-20 10:00:00', 'post_date_gmt' => '0000-00-00 00:00:00' ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_date' => '0000-00-00 00:00:00', 'post_date_gmt' => '0000-00-00 00:00:00' ) ) );
ok( ! is_wp_error( $plan ) && 'date_unstorable' === $plan['blocked'][0]['reason'], 'dates: a local zero date is never put back — WordPress dates it at the save' );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_date' => '2026-09-01 10:00:00', 'post_date_gmt' => '0000-00-00 00:00:00' ) ) );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'] && array() === $plan['blocked'], 'dates: on a draft the missing UTC date is put back' );

/* the write that is left, judged as a whole */
reset_all();
ab_post( 5, array( 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + 30 ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + 30 ) ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_title' => 'Old title', 'post_date' => '2001-01-01 10:00:00', 'post_date_gmt' => '2001-01-01 09:00:00' ) ) );
$refs = is_wp_error( $plan ) ? array() : array_column( $plan['blocked'], 'reason', 'ref' );
ok( ! is_wp_error( $plan ) && 0 === $plan['restorable'] && 'date_passed' === ( $refs['5:post_date'] ?? '' ) && 'date_passed' === ( $refs['5'] ?? '' ), 'rest: a title restored onto a post going live in 30 seconds is not written — WordPress would publish it at once' );
ab_post( 5, array( 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + 180 ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + 180 ) ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_title' => 'Old title' ) ) );
ok( ! is_wp_error( $plan ) && 0 === $plan['restorable'] && 'too_close' === $plan['blocked'][0]['reason'], 'rest: ... nor onto one going live in three minutes: too close, as in the free core' );
ab_post( 5, array( 'post_status' => 'future', 'post_date' => '2099-01-01 10:00:00', 'post_date_gmt' => '2099-01-01 09:00:00' ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_title' => 'Old title' ) ) );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'] && array() === $plan['blocked'], 'rest: onto a post scheduled far ahead the title goes back' );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + 120 ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + 120 ) ) ) );
$refs = is_wp_error( $plan ) ? array() : array_column( $plan['blocked'], 'reason', 'ref' );
ok( ! is_wp_error( $plan ) && 'too_close' === ( $refs['5:post_date'] ?? '' ), 'rest: a schedule put back less than five minutes ahead is not restored' );
reset_all();
ab_post( 5, array( 'post_status' => 'in-review', 'post_date' => '2026-09-20 10:00:00', 'post_date_gmt' => '0000-00-00 00:00:00' ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_date' => '2026-09-01 10:00:00', 'post_date_gmt' => '0000-00-00 00:00:00' ) ) );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'] && array() === $plan['blocked'], 'dates: a status registered as date_floating by a plugin keeps a missing UTC date, as WordPress does' );

/* ================================================== the plan and the write judge the row the write starts from */
reset_all();
$soon = gmdate( 'Y-m-d H:i:s', time() + 30 );
ab_post( 5, array( 'post_status' => 'future', 'post_date' => $soon, 'post_date_gmt' => $soon ) );
$GLOBALS['ab_post_cache'][5]                = clone $GLOBALS['ab_posts'][5];
$GLOBALS['ab_post_cache'][5]->post_date     = '2099-01-01 10:00:00';   // a stale cached copy: still scheduled far ahead
$GLOBALS['ab_post_cache'][5]->post_date_gmt = '2099-01-01 09:00:00';
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_title' => 'Old title' ) ) );
ok( ! is_wp_error( $res ) && 0 === $res['undone'] && array() === $GLOBALS['ab_updates'] && 'date_passed' === ( $res['blocked'][0]['reason'] ?? '' ), 'basis: the plan judges the table row, not a stale cached copy' );
reset_all();
ab_post( 5, array( 'post_status' => 'future', 'post_date' => '2099-01-01 10:00:00', 'post_date_gmt' => '2099-01-01 09:00:00' ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_title' => 'Old title' ) ) );
ab_table_post( 5 )->post_date     = $soon;   // rescheduled after the plan was made
ab_table_post( 5 )->post_date_gmt = $soon;
$res = AB_Test_Restore_Write::write_item( $plan['items'][0] );
ok( 1 === $plan['restorable'] && 'ab_mcp_undo_post_changed' === ab_code( $res ) && array() === $GLOBALS['ab_updates'], 'basis: the write is judged again on the row it starts from — a post rescheduled after the plan gets nothing written' );
ok( is_wp_error( $res ) && false !== strpos( $res->get_error_message(), 'dry_run=true' ), 'basis: and the answer names the way (a dry run, then again)' );
reset_all();
ab_post( 5, array( 'post_status' => 'trash', 'post_date' => '2001-01-01 10:00:00', 'post_date_gmt' => '2001-01-01 09:00:00' ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'publish' ) ) );
ab_table_post( 5 )->post_date     = '2099-01-01 10:00:00';
ab_table_post( 5 )->post_date_gmt = '2099-01-01 09:00:00';
$res = AB_Test_Restore_Write::write_item( $plan['items'][0] );
ok( 1 === $plan['restorable'] && 'ab_mcp_undo_post_changed' === ab_code( $res ) && array() === $GLOBALS['ab_seq'] && 'trash' === ab_table_post( 5 )->post_status, 'basis: ... before anything changes — the post is not taken out of the trash first' );
reset_all();
ab_post( 5, array( 'post_status' => 'publish', 'post_date' => '2099-01-01 10:00:00', 'post_date_gmt' => '2099-01-01 09:00:00' ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_date' => '2001-01-01 10:00:00', 'post_date_gmt' => '2001-01-01 09:00:00' ) ) );
$res  = AB_Test_Restore_Write::write_item( $plan['items'][0] );
ok( 1 === $plan['restorable'] && true === $res && '2001-01-01 09:00:00' === ab_table_post( 5 )->post_date_gmt, 'basis: the check before the write judges the post with the date it puts back' );
reset_all();
ab_post( 5, array( 'post_status' => 'trash', 'post_date' => '2099-01-01 10:00:00', 'post_date_gmt' => '0000-00-00 00:00:00' ) );
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'future' ) ) );
ok( ! is_wp_error( $res ) && 0 === $res['undone'] && 'date_passed' === ( $res['blocked'][0]['reason'] ?? '' ) && array() === $GLOBALS['ab_seq'] && 'trash' === ab_table_post( 5 )->post_status, 'basis: a post in the trash is judged as it will be when the status is written, out of the trash' );
reset_all();
ab_post( 5, array( 'post_status' => 'trash', 'post_date' => '2099-01-01 10:00:00', 'post_date_gmt' => '2099-01-01 09:00:00' ) );
$GLOBALS['ab_untrash_then'] = static function ( $p ) { $p->post_date_gmt = '0000-00-00 00:00:00'; };
$res = AB_Test_Restore_Write::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'future' ) ) );
ok( ! is_wp_error( $res ) && 0 === $res['undone'] && array( 'ab_mcp_undo_post_changed' ) === AB_Test_Restore_Write::$codes && false !== strpos( $res['failed'][0]['reason'], '"draft"' ) && array( 'untrash' ) === $GLOBALS['ab_seq'] && array() === $GLOBALS['ab_updates'], 'basis: ... and what a plugin changes on the way is judged once more, on the row the write starts from' );
reset_all();
ab_post( 5, array( 'post_status' => 'draft' ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'pending' ) ) );
$GLOBALS['ab_caps']['edit_post:5'] = false;   // the right to edit it was taken away after the plan was made
$res = AB_Test_Restore_Write::write_item( $plan['items'][0] );
ok( 1 === $plan['restorable'] && 'ab_mcp_undo_post_changed' === ab_code( $res ) && false !== strpos( $res->get_error_message(), '(forbidden)' ) && array() === $GLOBALS['ab_updates'], 'basis: the check before the write asks the same questions as the plan — a right taken away since is not written past' );
reset_all();
ab_post( 5, array( 'post_status' => 'trash' ) );
$GLOBALS['ab_untrash_then'] = static function ( $p ) { $p->post_type = 'attachment'; };
$res = AB_Test_Restore_Write::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'pending' ) ) );
ok( ! is_wp_error( $res ) && 0 === $res['undone'] && array( 'ab_mcp_undo_post_changed' ) === AB_Test_Restore_Write::$codes && false !== strpos( $res['failed'][0]['reason'], '(attachment_status)' ) && array() === $GLOBALS['ab_updates'], 'basis: ... and so does the check after the untrash — "pending" is not written onto what is a file by now' );
reset_all();
$GLOBALS['ab_posts'] = array();
$res = AB_Test_Restore_Write::write_item( array( 'ref' => 5, 'fields' => array( 'post_title' => 'Old title' ) ) );
ok( 'ab_mcp_undo_post_gone' === ab_code( $res ) && array() === $GLOBALS['ab_updates'], 'basis: a post deleted after the plan gets nothing written' );
reset_all();
ab_post( 5, array( 'post_status' => 'future', 'post_date' => '2099-01-01 10:00:00', 'post_date_gmt' => '2099-01-01 09:00:00' ) );
$GLOBALS['ab_store_status'] = 'pending';
$res = AB_Test_Restore_Write::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_title' => 'Old title' ) ) );
ok( ! is_wp_error( $res ) && 0 === $res['undone'] && array( 'ab_mcp_undo_post_mismatch' ) === AB_Test_Restore_Write::$codes && false !== strpos( $res['failed'][0]['reason'], 'post_status' ), 'basis: a status the save changed although the undo wrote none is reported, not counted as restored' );
$GLOBALS['ab_store_status'] = null;
ab_post( 5, array( 'post_status' => 'future', 'post_date' => '2099-01-01 10:00:00', 'post_date_gmt' => '2099-01-01 09:00:00' ) );
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_title' => 'Old title' ) ) );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && array() === $res['failed'], 'basis: ... and a status that stays as it was is no difference' );

/* out of the trash as a draft, whatever status the site would give back */
reset_all();
ab_post( 5, array( 'post_status' => 'trash', 'post_date' => '2001-01-01 10:00:00', 'post_date_gmt' => '2001-01-01 09:00:00' ) );
$GLOBALS['ab_untrash_previous'] = 'future';
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'future', 'post_date' => '2099-01-01 10:00:00', 'post_date_gmt' => '2099-01-01 09:00:00' ) ) );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && array( 'draft' ) === $GLOBALS['ab_untrash_stored'] && 'future' === ab_table_post( 5 )->post_status && '2099-01-01 09:00:00' === ab_table_post( 5 )->post_date_gmt, 'trash: the post comes out of the trash as a draft, not as the "future" the site gives back' );
ok( empty( $GLOBALS['ab_hooked']['wp_untrash_post_status'][ PHP_INT_MAX ] ), 'trash: ... and the filter that sees to it is gone again' );
reset_all();
ab_post( 5, array( 'post_status' => 'trash' ) );
ab_post( 6, array( 'post_status' => 'trash' ) );
$GLOBALS['ab_untrash_previous'] = 'publish';
$GLOBALS['ab_untrash_along']    = 6;
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'pending' ) ) );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && 'pending' === ab_table_post( 5 )->post_status && 'publish' === ab_table_post( 6 )->post_status, 'trash: only the post the undo restores passes through "draft"' );
reset_all();
ab_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'trash' ) );
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'private' ) ) );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && array( 'private' ) === $GLOBALS['ab_untrash_stored'] && 'private' === ab_table_post( 5 )->post_status, 'trash: a file comes out with the status the undo puts back — "private" here, never through "inherit"' );
reset_all();
ab_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'trash', 'post_password' => 'geheim' ) );
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'inherit' ) ) );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && array( 'inherit' ) === $GLOBALS['ab_untrash_stored'] && 'inherit' === ab_table_post( 5 )->post_status && 'geheim' === ab_table_post( 5 )->post_password, 'trash: ... "inherit" when the undo puts that back — not through "private", which would drop the file\'s password' );

/* the undo of wp_bulk_update_status: the same questions, the same write */
reset_all();
$bulk = array( 'tool' => 'wp_bulk_update_status', 'kind' => 'post_status' );
ab_post( 5, array( 'post_status' => 'trash', 'post_date' => '2001-01-01 10:00:00', 'post_date_gmt' => '2001-01-01 09:00:00' ) );
$res = AB_MCP_Undo_Restore::apply( $bulk, array( array( 5, 'future' ) ) );
ok( ! is_wp_error( $res ) && 0 === $res['undone'] && 'date_passed' === ( $res['blocked'][0]['reason'] ?? '' ) && 'trash' === ab_table_post( 5 )->post_status && array() === $GLOBALS['ab_seq'], 'bulk: "future" is not put back onto a post whose date has passed' );
ab_post( 5, array( 'post_status' => 'draft', 'post_date' => '2099-01-01 10:00:00', 'post_date_gmt' => '2099-01-01 09:00:00' ) );
$res = AB_MCP_Undo_Restore::apply( $bulk, array( array( 5, 'publish' ) ) );
ok( ! is_wp_error( $res ) && 0 === $res['undone'] && 'date_ahead' === ( $res['blocked'][0]['reason'] ?? '' ) && 'draft' === ab_table_post( 5 )->post_status, 'bulk: ... nor "publish" onto a post dated ahead' );
$res = AB_MCP_Undo_Restore::apply( $bulk, array( array( 5, 'future' ) ) );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && 'future' === ab_table_post( 5 )->post_status, 'bulk: a schedule still ahead goes back' );
ab_post( 5, array( 'post_status' => 'draft', 'post_date' => '2001-01-01 10:00:00', 'post_date_gmt' => '2001-01-01 09:00:00' ) );
$GLOBALS['ab_post_cache'][5]                = clone $GLOBALS['ab_posts'][5];
$GLOBALS['ab_post_cache'][5]->post_date     = '2099-01-01 10:00:00';
$GLOBALS['ab_post_cache'][5]->post_date_gmt = '2099-01-01 09:00:00';
$plan = AB_MCP_Undo_Restore::plan( $bulk, array( array( 5, 'future' ) ) );
ok( ! is_wp_error( $plan ) && 0 === $plan['restorable'] && 'date_passed' === ( $plan['blocked'][0]['reason'] ?? '' ), 'bulk: the plan judges the table row, not a stale cached copy' );
ab_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );
$res = AB_MCP_Undo_Restore::apply( $bulk, array( array( 5, 'publish' ) ) );
ok( ! is_wp_error( $res ) && 0 === $res['undone'] && 'attachment_status' === ( $res['blocked'][0]['reason'] ?? '' ), 'bulk: a status a file cannot have is not put back' );
reset_all();
ab_post( 5, array( 'post_status' => 'future', 'post_date' => '2099-01-01 10:00:00', 'post_date_gmt' => '2099-01-01 09:00:00' ) );
$GLOBALS['ab_store_status'] = 'pending';
$res = AB_Test_Restore_Write::apply( $bulk, array( array( 5, 'draft' ) ) );
ok( ! is_wp_error( $res ) && 0 === $res['undone'] && array( 'ab_mcp_undo_post_mismatch' ) === AB_Test_Restore_Write::$codes && false !== strpos( $res['failed'][0]['reason'], 'post_status' ), 'bulk: a status the site stores in place of the one put back is reported, not counted as restored' );
reset_all();
$GLOBALS['ab_caps']['delete_post:5'] = true;
$GLOBALS['ab_trash_veto']            = true;
$res = AB_Test_Restore_Write::apply( $bulk, array( array( 5, 'trash' ) ) );
ok( ! is_wp_error( $res ) && 0 === $res['undone'] && array( 'ab_mcp_undo_trash_failed' ) === AB_Test_Restore_Write::$codes && 'publish' === ab_table_post( 5 )->post_status, 'bulk: a move to the trash a plugin refuses is reported, not counted as restored' );

/* an undated draft the undo publishes gets now in both columns */
reset_all();
$GLOBALS['ab_tz'] = 'Europe/Zurich';
ab_post( 5, array( 'post_status' => 'draft', 'post_date' => '2026-09-01 10:00:00', 'post_date_gmt' => '0000-00-00 00:00:00' ) );
$res  = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'publish' ) ) );
$last = end( $GLOBALS['ab_updates'] );
$gmt  = isset( $last['post_date_gmt'] ) ? strtotime( $last['post_date_gmt'] . ' UTC' ) : 0;
$loc  = $gmt ? ( new DateTimeImmutable( '@' . $gmt ) )->setTimezone( new DateTimeZone( 'Europe/Zurich' ) )->format( 'Y-m-d H:i:s' ) : 'none';
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && ! empty( $last['edit_date'] ) && abs( $gmt - time() ) <= 2 && $loc === ( $last['post_date'] ?? null ), 'dates: an undated draft the undo publishes gets now in both columns, the local one from the same instant' );
reset_all();
ab_post( 5, array( 'post_status' => 'draft', 'post_date' => '2026-09-01 10:00:00', 'post_date_gmt' => '0000-00-00 00:00:00' ) );
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'publish', 'post_date' => '2001-01-01 10:00:00', 'post_date_gmt' => '2001-01-01 09:00:00' ) ) );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && '2001-01-01 09:00:00' === ab_table_post( 5 )->post_date_gmt, 'dates: ... a date the undo puts back is written as it is, not replaced by now' );
reset_all();
ab_post( 5, array( 'post_status' => 'draft', 'post_date' => '2026-09-01 10:00:00', 'post_date_gmt' => '2026-09-01 08:00:00' ) );
AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'publish' ) ) );
$last = end( $GLOBALS['ab_updates'] );
ok( 'publish' === ( $last['post_status'] ?? '' ) && ! isset( $last['post_date'] ) && ! isset( $last['post_date_gmt'] ), 'dates: ... a dated draft keeps its date' );
reset_all();
ab_post( 5, array( 'post_status' => 'draft', 'post_date' => '2026-09-01 10:00:00', 'post_date_gmt' => '0000-00-00 00:00:00' ) );
AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'private' ) ) );
$last = end( $GLOBALS['ab_updates'] );
ok( 'private' === ( $last['post_status'] ?? '' ) && ! isset( $last['post_date'] ), 'dates: ... and a status that is not settled by the date gets none passed along' );

/* ================================================== a password the post had */
/* The recorder notes it (tests/FreeSinceFourNineUndoTest.php); here the point
 * it leaves after a call that made a protected post private: WordPress has
 * dropped the password by then. */
reset_all();
ab_post( 5, array( 'post_status' => 'private', 'post_password' => '' ) );
$c    = claim( AB_MCP_Undo::record( array( 'tool' => 'wp_update_post', 'cap' => 'edit_posts', 'kind' => 'post_fields', 'summary' => '#5', 'items' => 1, 'data' => array( 'id' => 5, 'fields' => array( 'post_status' => 'publish', 'post_date' => '2026-09-01 10:00:00', 'post_date_gmt' => '2026-09-01 08:00:00' ), 'password_protected' => true ) ) ) );
$res  = AB_MCP_Undo_Restore::apply( $c['entry'], $c['data'] );
$refs = is_wp_error( $res ) ? array() : array_column( $res['blocked'], 'reason', 'ref' );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && 'private' === ab_table_post( 5 )->post_status && 'password_lost' === ( $refs['5:post_status'] ?? '' ), 'password: the undo leaves the status as it is instead of showing the post without its protection (password_lost); the date still goes back' );
reset_all();
ab_post( 5, array( 'post_status' => 'draft', 'post_password' => 'geheim' ) );
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'publish' ), 'password_protected' => true ) );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && array() === $res['blocked'] && 'publish' === ab_table_post( 5 )->post_status && 'geheim' === ab_table_post( 5 )->post_password, 'password: a post that still has its password goes back to publish, protected as before' );
reset_all();
foreach ( array( 'yes', 1, false, null, array( true ) ) as $bad ) {
	ok( 'ab_mcp_undo_corrupt' === ab_code( AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_title' => 'x' ), 'password_protected' => $bad ) ) ), 'password: a note in another form than the recorder writes is refused: ' . json_encode( $bad ) );
}
ab_post( 5, array( 'post_status' => 'draft' ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'private' ), 'password_protected' => true ) );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'] && array() === $plan['blocked'], 'password: "private" still goes back — nobody sees the post that the password kept out' );
reset_all();
ab_post( 5, array( 'post_status' => 'draft', 'post_password' => 'geheim' ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'publish' ), 'password_protected' => true ) );
ab_table_post( 5 )->post_password = '';   // removed after the plan was made
$res = AB_Test_Restore_Write::write_item( $plan['items'][0] );
ok( 1 === $plan['restorable'] && 'ab_mcp_undo_post_changed' === ab_code( $res ) && false !== strpos( $res->get_error_message(), '(password_lost)' ) && array() === $GLOBALS['ab_updates'], 'password: the check before the write asks again — a password removed after the plan was made stops the write' );
reset_all();
ab_post( 5, array( 'post_status' => 'private' ) );
$plan = AB_MCP_Undo_Restore::plan( $bulk, array( array( 5, 'publish', 'password_protected' ) ) );
ok( ! is_wp_error( $plan ) && 0 === $plan['restorable'] && 'password_lost' === ( $plan['blocked'][0]['reason'] ?? '' ), 'password: the undo of the bulk tool asks the same' );
ab_post( 5, array( 'post_status' => 'draft', 'post_password' => 'geheim' ) );
$res = AB_MCP_Undo_Restore::apply( $bulk, array( array( 5, 'publish', 'password_protected' ) ) );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && 'publish' === ab_table_post( 5 )->post_status && 'geheim' === ab_table_post( 5 )->post_password, 'password: ... and puts "publish" back on a post that still has its password' );
ab_post( 5, array( 'post_status' => 'draft' ) );
$plan = AB_MCP_Undo_Restore::plan( $bulk, array( array( 5, 'private', 'password_protected' ) ) );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'], 'password: ... and "private" on one without' );
ok( 'ab_mcp_undo_corrupt' === ab_code( AB_MCP_Undo_Restore::plan( $bulk, array( array( 5, 'publish', 'dropped' ) ) ) ), 'password: a third entry other than the note is refused' );
$plan = AB_MCP_Undo_Restore::plan( $bulk, array( array( 5, 'publish' ) ) );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'], 'password: an entry without the note goes back as before' );
reset_all();
ab_post( 5, array( 'post_status' => 'draft', 'post_password' => 'geheim' ) );
$plan = AB_MCP_Undo_Restore::plan( $bulk, array( array( 5, 'publish', 'password_protected' ) ) );
ab_table_post( 5 )->post_password = '';
$res = AB_Test_Restore_Write::write_status_item( $plan['items'][0] );
ok( 1 === $plan['restorable'] && 'ab_mcp_undo_post_changed' === ab_code( $res ) && false !== strpos( $res->get_error_message(), '(password_lost)' ) && array() === $GLOBALS['ab_updates'], 'password: ... the bulk undo asks again before the write, too' );

/* ================================================== a file is not taken out of the trash together with a new parent */
reset_all();
$GLOBALS['ab_caps']['edit_post:8'] = true; // the parent put back: the account may edit it
ab_post( 7, array( 'post_status' => 'publish' ) );
ab_post( 8, array( 'post_status' => 'private' ) );
ab_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'trash', 'post_parent' => 7 ) );
$res  = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'inherit', 'post_parent' => 8 ) ) );
$refs = is_wp_error( $res ) ? array() : array_column( $res['blocked'], 'reason', 'ref' );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && 'attachment_parent' === ( $refs['5:post_status'] ?? '' ) && 'trash' === ab_table_post( 5 )->post_status && 8 === (int) ab_table_post( 5 )->post_parent && ! in_array( 'untrash', $GLOBALS['ab_seq'], true ), 'parent: a file is not taken out of the trash as "inherit" together with a new parent; the parent goes back, the file stays in the trash' );
reset_all();
$GLOBALS['ab_caps']['edit_post:8'] = true; // the parent put back: the account may edit it
ab_post( 8, array( 'post_status' => 'private' ) );
ab_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'trash', 'post_parent' => 8 ) );
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'inherit', 'post_parent' => 8 ) ) );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && array() === $res['blocked'] && 'inherit' === ab_table_post( 5 )->post_status, 'parent: ... with the parent it keeps, it comes out as before' );
reset_all();
$GLOBALS['ab_caps']['edit_post:8'] = true; // the parent put back: the account may edit it
ab_post( 8, array( 'post_status' => 'private' ) );
ab_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'trash', 'post_parent' => 7 ) );
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'private', 'post_parent' => 8 ) ) );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && array() === $res['blocked'] && 'private' === ab_table_post( 5 )->post_status && 8 === (int) ab_table_post( 5 )->post_parent, 'parent: ... and as "private", which nobody sees on the way, it comes out with the new parent' );
reset_all();
$GLOBALS['ab_caps']['edit_post:8'] = true; // the parent put back: the account may edit it
ab_post( 8, array( 'post_status' => 'private' ) );
ab_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'private', 'post_parent' => 7 ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'inherit', 'post_parent' => 8 ) ) );
ab_table_post( 5 )->post_status = 'trash';
$res = AB_Test_Restore_Write::write_item( $plan['items'][0] );
ok( 1 === $plan['restorable'] && 'ab_mcp_undo_post_changed' === ab_code( $res ) && false !== strpos( $res->get_error_message(), '(attachment_parent)' ) && array() === $GLOBALS['ab_seq'], 'parent: the check before the write asks it too — a file trashed after the plan is not taken out with a new parent' );

/* ================================================== a file goes back only to a post the account may edit now (Codex 29.10.2026) */
reset_all();
$GLOBALS['ab_caps']['edit_post:8'] = false;
ab_post( 8, array( 'post_status' => 'publish' ) );
ab_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 7 ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_parent' => 8 ) ) );
ok( ! is_wp_error( $plan ) && 0 === $plan['restorable'] && array( array( 'ref' => '5:post_parent', 'reason' => 'forbidden' ) ) === $plan['blocked'], 'parent: a file is not attached to a post the account may no longer edit' );
reset_all();
$GLOBALS['ab_caps']['edit_post:8'] = true;
ab_post( 8, array( 'post_status' => 'publish' ) );
ab_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'trash', 'post_parent' => 7 ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'private', 'post_parent' => 8 ) ) );
$GLOBALS['ab_untrash_then'] = function () { $GLOBALS['ab_caps']['edit_post:8'] = false; };
$res = AB_Test_Restore_Write::write_item( $plan['items'][0] );
$GLOBALS['ab_untrash_then'] = null;
ok( 1 === $plan['restorable'] && is_wp_error( $res ) && 7 === (int) ab_table_post( 5 )->post_parent, 'parent: ... and once more after the file came out of the trash' );

/* ================================================== a file left to its parent needs the right to publish */
reset_all();
$GLOBALS['ab_caps']['publish_posts'] = false;
ab_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'private', 'post_parent' => 0 ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'inherit' ) ) );
ok( ! is_wp_error( $plan ) && 0 === $plan['restorable'] && 'forbidden' === ( $plan['blocked'][0]['reason'] ?? '' ), 'publish right: without it, the undo puts no "inherit" back on a private file without a parent' );
ab_post( 7, array( 'post_status' => 'draft' ) );
ab_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'private', 'post_parent' => 7 ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'inherit' ) ) );
ok( ! is_wp_error( $plan ) && 0 === $plan['restorable'] && 'forbidden' === ( $plan['blocked'][0]['reason'] ?? '' ), 'publish right: ... nor under a parent that is a draft' );
ab_post( 7, array( 'post_status' => 'publish' ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'inherit' ) ) );
ok( ! is_wp_error( $plan ) && 'forbidden' === ( $plan['blocked'][0]['reason'] ?? '' ), 'publish right: ... nor under a published parent' );
$plan = AB_MCP_Undo_Restore::plan( $bulk, array( array( 5, 'inherit' ) ) );
ok( ! is_wp_error( $plan ) && 'forbidden' === ( $plan['blocked'][0]['reason'] ?? '' ), 'publish right: the undo of the bulk tool asks the same' );
ab_post( 7, array( 'post_status' => 'draft' ) );
ab_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'trash', 'post_parent' => 7 ) );
$plan = AB_MCP_Undo_Restore::plan( $bulk, array( array( 5, 'inherit' ) ) );
ok( ! is_wp_error( $plan ) && 'forbidden' === ( $plan['blocked'][0]['reason'] ?? '' ), 'publish right: a file in the trash does not come back as "inherit" without it' );
ab_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 7 ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'inherit', 'post_title' => 'Older title' ) ) );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'] && array() === $plan['blocked'], 'publish right: a file that has "inherit" and keeps its parent — no right needed' );
$GLOBALS['ab_caps']['publish_posts'] = true;
ab_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'private', 'post_parent' => 0 ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'inherit' ) ) );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'] && array() === $plan['blocked'], 'publish right: with it, "inherit" goes back' );

/* a file that has "inherit" or sits in the trash: another parent needs the right to publish */
reset_all();
$GLOBALS['ab_caps']['publish_posts'] = false;
ab_post( 7, array( 'post_status' => 'draft' ) );
ab_post( 9, array( 'post_status' => 'publish' ) );
ab_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 7 ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_parent' => 0 ) ) );
ok( ! is_wp_error( $plan ) && 0 === $plan['restorable'] && 'forbidden' === ( $plan['blocked'][0]['reason'] ?? '' ) && '5:post_parent' === $plan['blocked'][0]['ref'], 'parent: without the right to publish, the undo does not take a file with "inherit" away from a draft' );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_parent' => 9 ) ) );
ok( ! is_wp_error( $plan ) && 0 === $plan['restorable'] && 'forbidden' === ( $plan['blocked'][0]['reason'] ?? '' ), 'parent: ... nor moves it under a published post' );
ab_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 9 ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_parent' => 7 ) ) );
ok( ! is_wp_error( $plan ) && 0 === $plan['restorable'] && 'forbidden' === ( $plan['blocked'][0]['reason'] ?? '' ), 'parent: ... nor under a draft: the parent\'s status is not judged' );
ab_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 7 ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_parent' => 7 ) ) );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'] && array() === $plan['blocked'], 'parent: the parent the file has already is no other one — no right needed' );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'inherit', 'post_parent' => 0, 'post_title' => 'Older title' ) ) );
$refs = is_wp_error( $plan ) ? array() : array_column( $plan['blocked'], 'reason', 'ref' );
ok( ! is_wp_error( $plan ) && 'forbidden' === ( $refs['5:post_status'] ?? '' ) && 'forbidden' === ( $refs['5:post_parent'] ?? '' ) && array( 'post_title' => 'Older title' ) === $plan['items'][0]['fields'], 'parent: ... nor together with the status: both are left out, the title still goes back' );
$GLOBALS['ab_caps']['delete_post:5'] = true;
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'trash' ) ) );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'] && array() === $plan['blocked'], 'parent: into the trash with the parent the file has — no right needed' );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'trash', 'post_parent' => 0 ) ) );
$refs = is_wp_error( $plan ) ? array() : array_column( $plan['blocked'], 'reason', 'ref' );
ok( ! is_wp_error( $plan ) && 0 === $plan['restorable'] && 'forbidden' === ( $refs['5:post_status'] ?? '' ) && 'forbidden' === ( $refs['5:post_parent'] ?? '' ), 'parent: ... but not with another parent' );
ab_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'trash', 'post_parent' => 7 ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_parent' => 0 ) ) );
ok( ! is_wp_error( $plan ) && 0 === $plan['restorable'] && 'forbidden' === ( $plan['blocked'][0]['reason'] ?? '' ), 'parent: a file in the trash gets no other parent either' );
$GLOBALS['ab_caps']['publish_posts'] = true;
ab_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 7 ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_parent' => 0 ) ) );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'], 'parent: with the right to publish it goes back' );
$GLOBALS['ab_caps']['publish_posts'] = false;
ab_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'private', 'post_parent' => 7 ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_parent' => 0 ) ) );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'], 'parent: a private file shows nobody more under another parent — no right to publish needed' );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_status' => 'inherit', 'post_parent' => 0 ) ) );
$refs = is_wp_error( $plan ) ? array() : array_column( $plan['blocked'], 'reason', 'ref' );
ok( ! is_wp_error( $plan ) && 'forbidden' === ( $refs['5:post_status'] ?? '' ) && ! isset( $refs['5:post_parent'] ) && array( 'post_parent' => 0 ) === $plan['items'][0]['fields'], 'parent: judged with the status the file keeps where the one put back is refused' );
$GLOBALS['ab_caps']['edit_post:11'] = true;
ab_post( 11, array( 'post_status' => 'publish', 'post_parent' => 7 ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 11, 'fields' => array( 'post_parent' => 0 ) ) );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'] && array() === $plan['blocked'], 'parent: for any post but a file the parent shows nobody anything — no right to publish needed' );

/* a save that names no parent still gets the one WordPress stores */
reset_all();
$GLOBALS['ab_caps']['publish_posts'] = false;
ab_post( 7, array( 'post_status' => 'draft', 'post_parent' => 5 ) );   // the draft is a child of the file
ab_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 7 ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_title' => 'Older title' ) ) );
ok( ! is_wp_error( $plan ) && 0 === $plan['restorable'] && array( array( 'ref' => 5, 'reason' => 'forbidden' ) ) === $plan['blocked'], 'loop: a title alone is not written onto a file a loop runs through' );
$plan = AB_MCP_Undo_Restore::plan( $bulk, array( array( 5, 'inherit' ) ) );
ok( ! is_wp_error( $plan ) && 'forbidden' === ( $plan['blocked'][0]['reason'] ?? '' ), 'loop: ... nor the status it has already' );
$GLOBALS['ab_caps']['publish_posts'] = true;
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_title' => 'Older title' ) ) );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'], 'loop: ... with the right to publish it is' );
$GLOBALS['ab_caps']['publish_posts'] = false;
ab_post( 7, array( 'post_status' => 'draft', 'post_parent' => 8 ) );
ab_post( 8, array( 'post_status' => 'draft', 'post_parent' => 7 ) );   // a loop above the file, not through it
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_title' => 'Older title' ) ) );
ok( ! is_wp_error( $plan ) && 1 === $plan['restorable'] && array() === $plan['blocked'], 'loop: one above the file that does not run through it leaves the parent as it is — and the walk up ends' );
reset_all();
$GLOBALS['ab_caps']['publish_posts'] = false;
ab_post( 7, array( 'post_status' => 'draft' ) );
ab_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 7 ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_title' => 'Older title' ) ) );
ab_table_post( 7 )->post_parent = 5;   // the loop is made after the plan
$res = AB_Test_Restore_Write::write_item( $plan['items'][0] );
ok( 1 === $plan['restorable'] && 'ab_mcp_undo_post_changed' === ab_code( $res ) && false !== strpos( $res->get_error_message(), '(forbidden)' ) && array() === $GLOBALS['ab_updates'], 'loop: the check before the write asks it too — a loop made after the plan is not written past' );
reset_all();
ab_post( 7, array( 'post_status' => 'draft' ) );
ab_post( 5, array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 7 ) );
$plan = AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_parent' => 0 ) ) );
$GLOBALS['ab_caps']['publish_posts'] = false;   // taken away after the plan was made
$res = AB_Test_Restore_Write::write_item( $plan['items'][0] );
ok( 1 === $plan['restorable'] && 'ab_mcp_undo_post_changed' === ab_code( $res ) && false !== strpos( $res->get_error_message(), '(forbidden)' ) && array() === $GLOBALS['ab_updates'], 'parent: the check before the write asks it again' );

/* a page whose page template is gone */
reset_all();
ab_post( 5, array( 'post_type' => 'page', 'post_title' => 'New title' ) );
$GLOBALS['ab_template_gone'] = true;
$res = AB_MCP_Undo_Restore::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_title' => 'Old title' ) ) );
ok( ! is_wp_error( $res ) && 1 === $res['undone'] && 'Old title' === ab_table_post( 5 )->post_title && array( false ) === $GLOBALS['ab_updates_wp_error'], 'template: the undo writes without $wp_error — a page whose template is gone is restored, not reported as failed after the row was written' );
reset_all();
ab_post( 5, array( 'post_type' => 'page', 'post_title' => 'New title' ) );
$GLOBALS['ab_update_fails'] = true;
$res = AB_Test_Restore_Write::apply( $entry, array( 'id' => 5, 'fields' => array( 'post_title' => 'Old title' ) ) );
ok( ! is_wp_error( $res ) && 0 === $res['undone'] && array( 'ab_mcp_undo_write_failed' ) === AB_Test_Restore_Write::$codes && 'New title' === ab_table_post( 5 )->post_title, 'template: a save that fails is still reported' );

/* hand-edited date columns */
reset_all();
foreach ( array( 'soon', '2026-02-30 10:00:00', '2026-09-01T10:00:00', '2026-09-01 24:00:00', '2026-09-01 10:00', '0000-00-00 00:00:01', 7, array( 'x' ), null ) as $bad ) {
	ok( 'ab_mcp_undo_corrupt' === ab_code( AB_MCP_Undo_Restore::plan( $entry, array( 'id' => 5, 'fields' => array( 'post_date' => $bad ) ) ) ), 'dates: refused: ' . json_encode( $bad ) );
}

/* ================================================== the token, end to end */
reset_all();
$t   = AB_MCP_Undo::record( array( 'tool' => 'wp_update_post', 'cap' => 'edit_posts', 'kind' => 'post_fields', 'summary' => '#5', 'items' => 1, 'data' => array( 'id' => 5, 'fields' => array( 'post_title' => 'Old title' ) ) ) );
$GLOBALS['ab_uid'] = 8;
ok( 'ab_mcp_undo_not_found' === ab_code( claim( $t ) ), 'token: another account cannot use the undo point of wp_update_post' );
$GLOBALS['ab_uid'] = 7;
ok( ! is_wp_error( claim( $t ) ), 'token: its own account can' );
AB_MCP_Undo::clear_all();

/* the cases for a site without a trash, in a process of their own (see the top) */
exec( 'AB_TEST_NO_TRASH=1 ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' 2>&1', $no_trash_out, $no_trash_rc );
ok( 0 === $no_trash_rc && false !== strpos( (string) end( $no_trash_out ), '0 failed' ), 'no trash: the cases for a site without a trash pass in a process of their own' . ( $no_trash_rc ? ' — ' . implode( ' | ', $no_trash_out ) : '' ) );

printf( "UNDO-POSTFIELDS: %d passed, %d failed\n", $count - $fails, $fails );
exit( $fails ? 1 : 0 );
