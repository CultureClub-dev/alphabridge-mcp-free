<?php
/**
 * The undo of the comment tools (kind comment, since 4.14.0) and their
 * read-back, without WordPress: comments and comment meta are in-memory rows,
 * read through a $wpdb that answers the very queries AB_MCP_Undo_Comments
 * makes, and the comment functions of WordPress work on the same rows as
 * those of WordPress 7.1 do: the trash and spam note the status before in
 * _wp_trash_meta_status and _wp_trash_meta_time, untrash and unspam set it
 * again and remove the notes, trashing a top-level note trashes its approved
 * and pending reply notes, a status already held is no update, deleting for
 * good moves the replies up a level, and every status change counts the
 * approved comments of the post again. The undo store, the restore, wp_undo
 * and the comment tools are the real ones.
 *
 * Proves: each tool records one point; wp_undo takes the change back the way
 * WordPress takes a comment out of the trash or spam, with the trash notes of
 * before, and deletes a new reply again; a change since is refused unless
 * force; the rights of the tool are asked again on each comment; the reply
 * notes of a note go and come back with it, each with its own state; a status
 * already held, a status not stored, a deletion for good and the trash
 * turned off (in a process of its own: EMPTY_TRASH_DAYS is a constant) each
 * answer as they should; a hand-edited point gains no right; no answer about
 * a point shows a comment's text.
 *
 * Run:  php tests/standalone/undo-comments-test.php
 */

error_reporting( E_ALL & ~E_DEPRECATED );

$GLOBALS['ab_trash_off'] = '1' === getenv( 'AB_TRASH_OFF' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'KB_IN_BYTES', 1024 );
define( 'MB_IN_BYTES', 1048576 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );
define( 'EMPTY_TRASH_DAYS', $GLOBALS['ab_trash_off'] ? 0 : 30 );

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

/* ---- identity and capabilities: everything, unless a test takes it away ---- */
$GLOBALS['ab_uid']  = 7;
$GLOBALS['ab_deny'] = array(); // 'cap' or 'cap:obj' => true
function get_current_user_id() { return (int) $GLOBALS['ab_uid']; }
function current_user_can( $cap, ...$args ) {
	$one = $cap . ( $args ? ':' . (string) $args[0] : '' );
	return ! isset( $GLOBALS['ab_deny'][ $one ] ) && ! isset( $GLOBALS['ab_deny'][ $cap ] );
}
function wp_get_current_user() { return (object) array( 'ID' => 7, 'user_login' => 'editor', 'display_name' => 'Eva Editor', 'user_email' => 'eva@example.test' ); }

/* ---- options: the undo index ---- */
$GLOBALS['ab_opts'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['ab_opts'] ) ? $GLOBALS['ab_opts'][ $k ] : $d; }
function add_option( $k, $v, $depr = '', $autoload = 'yes' ) {
	if ( array_key_exists( $k, $GLOBALS['ab_opts'] ) ) { return false; }
	$GLOBALS['ab_opts'][ $k ] = $v;
	return true;
}
function update_option( $k, $v, $autoload = null ) { $GLOBALS['ab_opts'][ $k ] = $v; return true; }
function delete_option( $k ) { $had = array_key_exists( $k, $GLOBALS['ab_opts'] ); unset( $GLOBALS['ab_opts'][ $k ] ); return $had; }
function wp_cache_delete( $k, $group = '' ) { return true; }

/* ---- filters and actions ---- */
$GLOBALS['ab_filters'] = array();
function apply_filters( $tag, $value, ...$args ) {
	if ( array_key_exists( $tag, $GLOBALS['ab_filters'] ) ) {
		$f = $GLOBALS['ab_filters'][ $tag ];
		return is_callable( $f ) && ! is_string( $f ) ? $f( $value, ...$args ) : $f;
	}
	return $value;
}
function has_filter( $tag, $cb = false ) { return array_key_exists( $tag, $GLOBALS['ab_filters'] ); }
$GLOBALS['ab_actions'] = array();
$GLOBALS['ab_did']     = array(); // [tag, first argument] per action fired
function add_action( $tag, $cb, $prio = 10, $n = 1 ) { $GLOBALS['ab_actions'][ $tag ][] = $cb; return true; }
function remove_action( $tag, $cb, $prio = 10 ) {
	foreach ( $GLOBALS['ab_actions'][ $tag ] ?? array() as $i => $f ) {
		if ( $f === $cb ) { unset( $GLOBALS['ab_actions'][ $tag ][ $i ] ); return true; }
	}
	return false;
}
function has_action( $tag, $cb = false ) { return ! empty( $GLOBALS['ab_actions'][ $tag ] ); }
function do_action( $tag, ...$args ) {
	$GLOBALS['ab_did'][] = array( $tag, (string) ( $args[0] ?? '' ) );
	foreach ( $GLOBALS['ab_actions'][ $tag ] ?? array() as $f ) { $f( ...$args ); }
}
function ab_did( $tag, $id ) { return in_array( array( $tag, (string) $id ), $GLOBALS['ab_did'], true ); }
function is_multisite() { return false; }
function clean_post_cache( $id ) {}

/* ---- serialisation + fs + misc ---- */
function wp_salt( $s = 'auth' ) { return 'test-salt-value'; }
function size_format( $bytes, $decimals = 0 ) { return $bytes . ' B'; }
function trailingslashit( $p ) { return rtrim( $p, '/' ) . '/'; }
function wp_mkdir_p( $p ) { return is_dir( $p ) || mkdir( $p, 0777, true ); }
function wp_json_encode( $v, $options = 0, $depth = 512 ) { return json_encode( $v, $options, $depth ); }
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
$GLOBALS['ab_upload'] = sys_get_temp_dir() . '/ab-free-undo-comments-' . getmypid() . '-' . bin2hex( random_bytes( 6 ) );
register_shutdown_function( static function () { ab_test_rm_tree( $GLOBALS['ab_upload'] ); } );
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['ab_upload'], 'baseurl' => 'http://x/u', 'error' => false ); }
function wp_slash( $v ) { return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function is_serialized( $data ) {
	if ( ! is_string( $data ) ) { return false; }
	$data = trim( $data );
	if ( 'N;' === $data ) { return true; }
	if ( ! preg_match( '/^[aOsibd]:/', $data ) ) { return false; }
	return 'b:0;' === $data || false !== @unserialize( $data ); // phpcs:ignore
}
function maybe_serialize( $v ) {
	if ( is_array( $v ) || is_object( $v ) ) { return serialize( $v ); } // phpcs:ignore
	if ( is_serialized( $v ) ) { return serialize( $v ); } // phpcs:ignore
	return $v;
}
function maybe_unserialize( $v ) { return is_serialized( $v ) ? @unserialize( trim( $v ) ) : $v; } // phpcs:ignore
// wp_kses_post() as far as these checks need it: a script goes, a link stays.
function wp_kses_post( $s ) { return strip_tags( (string) $s, '<a><b><strong><em><p>' ); }

/* ---- the tables ---- */
const AB_COLS = array( 'comment_post_ID', 'comment_author', 'comment_author_email', 'comment_author_url', 'comment_author_IP', 'comment_date', 'comment_date_gmt', 'comment_content', 'comment_karma', 'comment_approved', 'comment_agent', 'comment_type', 'comment_parent', 'user_id' );
function ab_db_reset() {
	$GLOBALS['T'] = array(
		'comments'    => array(), // id => columns (as MySQL hands them back: text)
		'commentmeta' => array(), // meta_id => [ comment_id, key, value ]
	);
	$GLOBALS['ab_next']        = 100;
	$GLOBALS['ab_db_down']     = false; // the tables cannot be read
	$GLOBALS['ab_db_error']    = false; // a SELECT that ran and failed: last_error says why
	$GLOBALS['ab_status_veto'] = array(); // comment ids whose status update the database refuses
	$GLOBALS['ab_cache']       = array(); // the object cache of get_comment()
	$GLOBALS['ab_counts']      = array(); // post id => approved comments, as wp_update_comment_count() stores them
	$GLOBALS['ab_on_insert']   = null; // a plugin on wp_insert_comment
	$GLOBALS['ab_delete_lies'] = false; // a deletion that reports success and deletes nothing
	$GLOBALS['ab_delete_refused'] = false; // a deletion for good whose DELETE fails: false
}
ab_db_reset();
function ab_next() { return $GLOBALS['ab_next']++; }
function ab_comment( $id, $over = array() ) {
	$GLOBALS['T']['comments'][ $id ] = array_merge(
		array(
			'comment_post_ID'      => '5',
			'comment_author'       => 'Anna',
			'comment_author_email' => 'anna@example.test',
			'comment_author_url'   => '',
			'comment_author_IP'    => '127.0.0.1',
			'comment_date'         => '2026-10-30 10:00:00',
			'comment_date_gmt'     => '2026-10-30 09:00:00',
			'comment_content'      => 'Geheimer Text ' . $id,
			'comment_karma'        => '0',
			'comment_approved'     => '1',
			'comment_agent'        => '',
			'comment_type'         => 'comment',
			'comment_parent'       => '0',
			'user_id'              => '0',
		),
		array_map( 'strval', $over )
	);
	wp_update_comment_count( (int) $GLOBALS['T']['comments'][ $id ]['comment_post_ID'] );
	return $id;
}
function ab_status( $id ) { return $GLOBALS['T']['comments'][ $id ]['comment_approved'] ?? null; }
function ab_trash_rows( $id ) {
	$out = array();
	foreach ( $GLOBALS['T']['commentmeta'] as $r ) {
		if ( $r[0] === (int) $id && in_array( $r[1], array( '_wp_trash_meta_status', '_wp_trash_meta_time' ), true ) ) { $out[] = array( $r[1], $r[2] ); }
	}
	return $out;
}
function ab_meta_add( $id, $key, $value ) { $mid = ab_next(); $GLOBALS['T']['commentmeta'][ $mid ] = array( (int) $id, (string) $key, (string) $value ); return $mid; }

$GLOBALS['wpdb'] = new class {
	public $comments    = 'wp_comments';
	public $commentmeta = 'wp_commentmeta';
	public $options     = 'wp_options';
	public $last_error  = '';
	public $last_result = array();
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $a ) {
			$sql = preg_replace_callback( '/%[ds]/', static function ( $m ) use ( $a ) { return '%d' === $m[0] ? (string) (int) $a : "'" . addslashes( (string) $a ) . "'"; }, $sql, 1 );
		}
		return $sql;
	}
	public function query( $sql ) {
		$this->last_result = array();
		$this->last_error  = '';
		$T                 = $GLOBALS['T'];
		$out               = null;
		if ( preg_match( '/^SELECT ([A-Za-z_, ]+) FROM wp_comments WHERE comment_ID = (\d+)$/', $sql, $m ) ) {
			$out = array();
			if ( isset( $T['comments'][ (int) $m[2] ] ) ) {
				$row = array();
				foreach ( array_map( 'trim', explode( ',', $m[1] ) ) as $c ) { $row[ $c ] = $T['comments'][ (int) $m[2] ][ $c ]; }
				$out[] = $row;
			}
		} elseif ( preg_match( '/^SELECT comment_ID, comment_type FROM wp_comments WHERE comment_parent = (\d+) ORDER BY comment_ID ASC$/', $sql, $m ) ) {
			$out = array();
			$ids = array_keys( $T['comments'] );
			sort( $ids );
			foreach ( $ids as $id ) {
				if ( $T['comments'][ $id ]['comment_parent'] === $m[1] ) { $out[] = array( 'comment_ID' => (string) $id, 'comment_type' => $T['comments'][ $id ]['comment_type'] ); }
			}
		} elseif ( preg_match( '/^SELECT meta_id, meta_key, meta_value FROM wp_commentmeta WHERE comment_id = (\d+) ORDER BY meta_id ASC$/', $sql, $m ) ) {
			$out = array();
			foreach ( $T['commentmeta'] as $mid => $r ) {
				if ( $r[0] === (int) $m[1] ) { $out[] = array( 'meta_id' => (string) $mid, 'meta_key' => $r[1], 'meta_value' => $r[2] ); }
			}
		}
		if ( null === $out ) {
			return false;
		}
		if ( $GLOBALS['ab_db_down'] ) {
			return false; // a connection that went away: false, last_error empty
		}
		if ( $GLOBALS['ab_db_error'] ) {
			$this->last_error = 'Table is marked as crashed';
			return 0;
		}
		foreach ( $out as $row ) { $this->last_result[] = (object) $row; }
		return count( $out );
	}
	public function get_var( $sql ) { return null; }
};

/* ---- the comment functions of WordPress 7.1, on the same rows ---- */
function clean_comment_cache( $ids ) { foreach ( (array) $ids as $id ) { unset( $GLOBALS['ab_cache'][ (int) $id ] ); } }
function get_comment( $id ) {
	$id = (int) ( is_object( $id ) ? $id->comment_ID : $id );
	if ( ! isset( $GLOBALS['T']['comments'][ $id ] ) ) { return null; }
	if ( ! isset( $GLOBALS['ab_cache'][ $id ] ) ) {
		$GLOBALS['ab_cache'][ $id ] = (object) array_merge( array( 'comment_ID' => (string) $id ), $GLOBALS['T']['comments'][ $id ] );
	}
	return clone $GLOBALS['ab_cache'][ $id ];
}
function wp_get_comment_status( $id ) {
	$c = get_comment( $id );
	return $c ? AB_MCP_Undo_Comments::label( $c->comment_approved ) : false;
}
function wp_update_comment_count( $post ) {
	$n = 0;
	foreach ( $GLOBALS['T']['comments'] as $r ) {
		if ( (int) $r['comment_post_ID'] === (int) $post && '1' === $r['comment_approved'] && 'note' !== $r['comment_type'] ) { ++$n; }
	}
	$GLOBALS['ab_counts'][ (int) $post ] = $n;
}
function ab_count( $post = 5 ) { return $GLOBALS['ab_counts'][ $post ] ?? 0; }
$GLOBALS['ab_notified'] = array();
function wp_set_comment_status( $id, $status, $wp_error = false ) {
	$map = array( 'hold' => '0', '0' => '0', 'approve' => '1', '1' => '1', 'spam' => 'spam', 'trash' => 'trash' );
	if ( ! isset( $map[ (string) $status ] ) ) { return false; }
	$old = get_comment( $id );
	$id  = (int) $old->comment_ID;
	// $wpdb->update(): 0 rows where the value is the same, or the database refuses.
	if ( $map[ (string) $status ] === $old->comment_approved || in_array( $id, $GLOBALS['ab_status_veto'], true ) ) { return false; }
	$GLOBALS['T']['comments'][ $id ]['comment_approved'] = $map[ (string) $status ];
	if ( '1' === $map[ (string) $status ] ) { $GLOBALS['ab_notified'][] = $id; } // wp_new_comment_notify_postauthor
	clean_comment_cache( $id );
	do_action( 'wp_set_comment_status', (string) $id, $status );
	wp_update_comment_count( (int) $old->comment_post_ID );
	return true;
}
function get_comment_meta( $id, $key = '', $single = false ) {
	$vals = array();
	foreach ( $GLOBALS['T']['commentmeta'] as $r ) {
		if ( $r[0] === (int) $id && $r[1] === $key ) { $vals[] = maybe_unserialize( $r[2] ); }
	}
	return $single ? ( $vals[0] ?? '' ) : $vals;
}
function add_comment_meta( $id, $key, $value, $unique = false ) { return ab_meta_add( $id, wp_unslash( $key ), maybe_serialize( wp_unslash( $value ) ) ); }
function delete_comment_meta( $id, $key, $value = '' ) {
	$n = 0;
	foreach ( $GLOBALS['T']['commentmeta'] as $mid => $r ) {
		if ( $r[0] === (int) $id && $r[1] === $key ) { unset( $GLOBALS['T']['commentmeta'][ $mid ] ); ++$n; }
	}
	return $n > 0;
}
/* The direct reply notes of a note that are approved or pending ($comment->get_children() with status all, type note). */
function ab_note_children( $id ) {
	$out = array();
	foreach ( $GLOBALS['T']['comments'] as $cid => $r ) {
		if ( (int) $r['comment_parent'] === (int) $id && 'note' === $r['comment_type'] && in_array( $r['comment_approved'], array( '0', '1' ), true ) ) { $out[] = $cid; }
	}
	sort( $out );
	return $out;
}
function wp_trash_comment( $id ) {
	if ( ! EMPTY_TRASH_DAYS ) {
		$c  = get_comment( $id );
		$ok = wp_delete_comment( $id, true );
		if ( ! $ok ) { return false; }
		if ( $c && 'note' === $c->comment_type && 0 === (int) $c->comment_parent ) {
			foreach ( ab_note_children( $id ) as $kid ) {
				if ( ! wp_delete_comment( $kid, true ) ) { $ok = false; }
			}
		}
		return $ok;
	}
	$c = get_comment( $id );
	if ( ! $c ) { return false; }
	do_action( 'trash_comment', $c->comment_ID );
	if ( wp_set_comment_status( $c, 'trash' ) ) {
		delete_comment_meta( $c->comment_ID, '_wp_trash_meta_status' );
		delete_comment_meta( $c->comment_ID, '_wp_trash_meta_time' );
		add_comment_meta( $c->comment_ID, '_wp_trash_meta_status', $c->comment_approved );
		add_comment_meta( $c->comment_ID, '_wp_trash_meta_time', time() );
		do_action( 'trashed_comment', $c->comment_ID );
		if ( 'note' === $c->comment_type && 0 === (int) $c->comment_parent ) {
			$ok = true;
			foreach ( ab_note_children( $c->comment_ID ) as $kid ) {
				if ( ! wp_trash_comment( $kid ) ) { $ok = false; }
			}
			return $ok;
		}
		return true;
	}
	return false;
}
function wp_untrash_comment( $id ) {
	$c = get_comment( $id );
	if ( ! $c ) { return false; }
	do_action( 'untrash_comment', $c->comment_ID );
	$status = (string) get_comment_meta( $c->comment_ID, '_wp_trash_meta_status', true );
	if ( empty( $status ) ) { $status = '0'; }
	if ( wp_set_comment_status( $c, $status ) ) {
		delete_comment_meta( $c->comment_ID, '_wp_trash_meta_time' );
		delete_comment_meta( $c->comment_ID, '_wp_trash_meta_status' );
		do_action( 'untrashed_comment', $c->comment_ID );
		return true;
	}
	return false;
}
function wp_spam_comment( $id ) {
	$c = get_comment( $id );
	if ( ! $c ) { return false; }
	do_action( 'spam_comment', $c->comment_ID );
	if ( wp_set_comment_status( $c, 'spam' ) ) {
		delete_comment_meta( $c->comment_ID, '_wp_trash_meta_status' );
		delete_comment_meta( $c->comment_ID, '_wp_trash_meta_time' );
		add_comment_meta( $c->comment_ID, '_wp_trash_meta_status', $c->comment_approved );
		add_comment_meta( $c->comment_ID, '_wp_trash_meta_time', time() );
		do_action( 'spammed_comment', $c->comment_ID );
		return true;
	}
	return false;
}
function wp_unspam_comment( $id ) {
	$c = get_comment( $id );
	if ( ! $c ) { return false; }
	do_action( 'unspam_comment', $c->comment_ID );
	$status = (string) get_comment_meta( $c->comment_ID, '_wp_trash_meta_status', true );
	if ( empty( $status ) ) { $status = '0'; }
	if ( wp_set_comment_status( $c, $status ) ) {
		delete_comment_meta( $c->comment_ID, '_wp_trash_meta_status' );
		delete_comment_meta( $c->comment_ID, '_wp_trash_meta_time' );
		do_action( 'unspammed_comment', $c->comment_ID );
		return true;
	}
	return false;
}
function wp_delete_comment( $id, $force = false ) {
	$c = get_comment( $id );
	if ( ! $c ) { return false; }
	if ( ! $force && EMPTY_TRASH_DAYS && ! in_array( wp_get_comment_status( $c ), array( 'trash', 'spam' ), true ) ) {
		return wp_trash_comment( $id );
	}
	if ( $GLOBALS['ab_delete_lies'] ) { return true; }
	if ( $GLOBALS['ab_delete_refused'] ) { return false; }
	do_action( 'delete_comment', $c->comment_ID );
	foreach ( $GLOBALS['T']['comments'] as $cid => $r ) {
		if ( $r['comment_parent'] === (string) $c->comment_ID ) { $GLOBALS['T']['comments'][ $cid ]['comment_parent'] = (string) $c->comment_parent; clean_comment_cache( $cid ); }
	}
	foreach ( $GLOBALS['T']['commentmeta'] as $mid => $r ) {
		if ( $r[0] === (int) $c->comment_ID ) { unset( $GLOBALS['T']['commentmeta'][ $mid ] ); }
	}
	unset( $GLOBALS['T']['comments'][ (int) $c->comment_ID ] );
	do_action( 'deleted_comment', $c->comment_ID );
	wp_update_comment_count( (int) $c->comment_post_ID );
	clean_comment_cache( $c->comment_ID );
	return true;
}
function wp_insert_comment( $data ) {
	$data = wp_unslash( $data );
	$id   = ab_next();
	ab_comment(
		$id,
		array(
			'comment_post_ID'      => (int) $data['comment_post_ID'],
			'comment_parent'       => (int) ( $data['comment_parent'] ?? 0 ),
			'comment_content'      => (string) $data['comment_content'],
			'comment_author'       => (string) $data['comment_author'],
			'comment_author_email' => (string) $data['comment_author_email'],
			'comment_approved'     => (string) $data['comment_approved'],
			'user_id'              => (int) $data['user_id'],
		)
	);
	if ( is_callable( $GLOBALS['ab_on_insert'] ) ) { ( $GLOBALS['ab_on_insert'] )( $id ); }
	do_action( 'wp_insert_comment', $id );
	return $id;
}
$GLOBALS['ab_posts'] = array( 5 => true, 6 => true );
function get_post( $id ) { return isset( $GLOBALS['ab_posts'][ (int) $id ] ) ? (object) array( 'ID' => (int) $id, 'post_type' => 'post' ) : null; }

/* ---- the rest of the site the tools and the restore ask ---- */
$GLOBALS['ab_scope']     = 'full';
$GLOBALS['ab_toolstate'] = array();
class AB_MCP_Settings {
	public static function is_tool_enabled( $name, $def ) {
		return array_key_exists( $name, $GLOBALS['ab_toolstate'] ) ? (bool) $GLOBALS['ab_toolstate'][ $name ] : true;
	}
}
class AB_MCP_Auth {
	public static function current_scope() { return $GLOBALS['ab_scope']; }
}

require $GLOBALS['ab_free'] . 'class-tool-registry.php';
require $GLOBALS['ab_free'] . 'class-guidance.php';
require $GLOBALS['ab_free'] . 'class-page-cache.php';
require $GLOBALS['ab_free'] . 'tools/class-tools-base.php';
require $GLOBALS['ab_free'] . 'tools/class-tools-taxonomy-comments.php';
require $GLOBALS['ab_free'] . 'tools/class-tools-undo-core.php';
require $GLOBALS['ab_free'] . 'undo/class-undo.php';
require $GLOBALS['ab_free'] . 'undo/class-undo-restore.php';

$pass = 0;
$fail = 0;
function ok( $c, $label ) {
	global $pass, $fail;
	if ( $c ) { $pass++; } else { $fail++; echo "  FAIL: $label\n"; }
}
function ab_code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ''; }
function ab_msg( $r ) { return is_wp_error( $r ) ? (string) $r->get_error_message() : ''; }
function ab_undo( $token, $extra = array() ) { return AB_MCP_Tools_Undo_Core::undo( array_merge( array( 'token' => (string) $token ), $extra ) ); }
function ab_entry( $token ) {
	foreach ( (array) get_option( AB_MCP_Undo::OPTION, array() ) as $e ) {
		if ( $e['token'] === $token ) { return $e; }
	}
	return null;
}
function ab_points() { return count( (array) get_option( AB_MCP_Undo::OPTION, array() ) ); }
function ab_token( $res ) { return is_array( $res ) && is_string( $res['undo'] ?? null ) && AB_MCP_Undo::valid_token( $res['undo'] ); }
function ab_forge( $tool, $data ) {
	return AB_MCP_Undo::record( array( 'tool' => $tool, 'cap' => 'moderate_comments', 'kind' => 'comment', 'summary' => 'forged', 'items' => 1, 'data' => $data ) );
}
function ab_moderate( $id, $status ) { return AB_MCP_Tools_Taxonomy_Comments::moderate_comment( array( 'comment_id' => $id, 'status' => $status ) ); }
function ab_delete( $id, $force = false ) { return AB_MCP_Tools_Taxonomy_Comments::delete_comment( array( 'comment_id' => $id, 'force' => $force ) ); }
function ab_reply( $content, $parent = 0, $post = 5 ) { return AB_MCP_Tools_Taxonomy_Comments::reply_comment( array( 'post_id' => $post, 'content' => $content, 'parent' => $parent ) ); }
function reset_all() {
	ab_db_reset();
	$GLOBALS['ab_opts']      = array();
	$GLOBALS['ab_filters']   = array();
	$GLOBALS['ab_deny']      = array();
	$GLOBALS['ab_toolstate'] = array();
	$GLOBALS['ab_did']       = array();
	$GLOBALS['ab_notified']  = array();
}
/* A top-level note with reply notes: 10 (note), 11 approved, 12 pending, 13 in the trash already, 14 a reply that is no note. */
function ab_note_thread() {
	ab_comment( 10, array( 'comment_type' => 'note' ) );
	ab_comment( 11, array( 'comment_type' => 'note', 'comment_parent' => 10 ) );
	ab_comment( 12, array( 'comment_type' => 'note', 'comment_parent' => 10, 'comment_approved' => '0' ) );
	ab_comment( 13, array( 'comment_type' => 'note', 'comment_parent' => 10, 'comment_approved' => 'trash' ) );
	ab_meta_add( 13, '_wp_trash_meta_status', '1' );
	ab_meta_add( 13, '_wp_trash_meta_time', '1700000000' );
	ab_comment( 14, array( 'comment_parent' => 10 ) );
}

/* ============================== T) the trash turned off (in a process of its own) */

if ( $GLOBALS['ab_trash_off'] ) {
	reset_all();
	ab_comment( 20 );
	ab_meta_add( 20, 'rating', '5' );
	ab_comment( 21, array( 'comment_parent' => 20 ) );
	$res = ab_moderate( 20, 'trash' );
	ok( true === $res['moderated'] && true === $res['deleted'] && null === $res['undo'] && 0 === ab_points(), 'T1 trash with the trash off: deleted, no undo point' );
	ok( 0 === strpos( (string) $res['undo_note'], 'not undoable: The trash is turned off on this site (EMPTY_TRASH_DAYS is 0)' ) && false !== strpos( $res['undo_note'], 'wp_get_comment' ), 'T2 ... the note says why, and how to note a comment first' );
	ok( null === ab_status( 20 ) && array() === ab_trash_rows( 20 ) && '0' === $GLOBALS['T']['comments'][21]['comment_parent'], 'T3 ... as WordPress deletes for good: the row and its meta gone, the reply moved up' );

	reset_all();
	ab_note_thread();
	$res = ab_moderate( 10, 'trash' );
	// As WordPress 7.1 runs it: the note is deleted first, which moves its
	// replies up a level, so the reply notes it looks for afterwards are no
	// longer its children and stay.
	ok( true === $res['deleted'] && null === $res['undo'] && null === ab_status( 10 ) && '1' === ab_status( 11 ) && '0' === $GLOBALS['T']['comments'][11]['comment_parent'] && '0' === ab_status( 12 ), 'T4 a top-level note goes for good, no point; its reply notes move up a level, as in WordPress' );

	reset_all();
	ab_comment( 22 );
	$res = ab_delete( 22 );
	ok( true === $res['deleted'] && null === $res['undo'] && 0 === strpos( (string) $res['undo_note'], 'not undoable: WordPress deletes a comment for good' ) && null === ab_status( 22 ) && 0 === ab_points(), 'T5 wp_delete_comment without force deletes for good with the trash off, and says it is not undoable' );

	reset_all();
	ab_comment( 23 );
	$GLOBALS['ab_delete_lies'] = true;
	$GLOBALS['T']['comments'][23]['comment_approved'] = '1';
	$res = ab_moderate( 23, 'trash' );
	ok( 'ab_mcp_delete_failed' === ab_code( $res ) && false !== strpos( ab_msg( $res ), 'wp_get_comment' ), 'T6 a comment WordPress kept: an error naming the way, not deleted: true' );

	echo "UNDO-COMMENTS-TRASH-OFF: $pass passed, $fail failed\n";
	exit( $fail ? 1 : 0 );
}

/* ============================== A) the status of a comment: one point, put back */

reset_all();
ab_comment( 20 );
ok( 1 === ab_count(), 'A0 the post counts one approved comment' );
$res = ab_moderate( 20, 'hold' );
ok( true === $res['moderated'] && 'hold' === $res['status'] && ab_token( $res ) && ! isset( $res['undo_note'] ), 'A1 wp_moderate_comment answers with a token' );
ok( array( 'status' => 'unapproved' ) === (array) $res['saved_fields'] && ! isset( $res['saved_differs'] ), 'A2 saved_fields: the status as stored, as wp_get_comment shows it' );
$c = AB_MCP_Undo::claim( $res['undo'] );
ok( 'wp_moderate_comment' === $c['entry']['tool'] && 'comment' === $c['entry']['kind'] && 'moderate_comments' === $c['entry']['cap'] && 1 === (int) $c['entry']['items'], 'A3 kind comment, with the capability of the tool' );
ok( array( 'v', 'id', 'before', 'after' ) === array_keys( $c['data'] ) && 20 === $c['data']['id'] && array( 'comment:20' ) === array_keys( $c['data']['before'] ) && array( 'status' => '1', 'trash' => array() ) === $c['data']['before']['comment:20'], 'A4 the snapshot keeps the status before and the trash rows' );
ok( 'comment #20: hold' === $c['entry']['summary'] && null !== $c['entry']['inline'], 'A5 the summary names the comment by id; a small point lies inline' );
ok( 0 === ab_count(), 'A6 WordPress counted the comments again' );
$dry = ab_undo( $res['undo'], array( 'dry_run' => true ) );
ok( 1 === $dry['would_undo'] && array() === $dry['blocked'] && '0' === ab_status( 20 ), 'A7 the dry run would restore it and changes nothing' );
$GLOBALS['ab_filters']['ab_mcp_pro_page_cache_purgers'] = static fn( $all, $scope ) => array( 'host-' . $scope => static function () {} );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && true === $out['consumed'] && array( 'comment:20' ) === $out['restored'] && '1' === ab_status( 20 ), 'A8 wp_undo approves it again and uses the point up' );
ok( 1 === ab_count() && in_array( 20, $GLOBALS['ab_notified'], true ), 'A9 ... through wp_set_comment_status: counted again, and WordPress may notify the author' );
ok( array( 'host-site' ) === $out['page_cache_purged'] && 'site' === AB_MCP_Undo_Restore::page_cache_scope()['comment'], 'A10 every page of the site is purged: a comment shows in counts, widgets and feeds' );
unset( $GLOBALS['ab_filters']['ab_mcp_pro_page_cache_purgers'] );

// Each direction, the way WordPress takes a comment back.
reset_all();
ab_comment( 21 );
$res = ab_moderate( 21, 'spam' );
ok( 'spam' === ab_status( 21 ) && array( array( '_wp_trash_meta_status', '1' ), array( '_wp_trash_meta_time', ab_trash_rows( 21 )[1][1] ) ) === ab_trash_rows( 21 ) && ab_token( $res ), 'A11 spam: WordPress notes the status before' );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && '1' === ab_status( 21 ) && array() === ab_trash_rows( 21 ) && ab_did( 'unspammed_comment', 21 ), 'A12 the undo of spam takes it out with wp_unspam_comment: status back, the notes removed' );

reset_all();
ab_comment( 22, array( 'comment_approved' => '0' ) );
$res = ab_moderate( 22, 'trash' );
ok( 'trash' === ab_status( 22 ) && '0' === ab_trash_rows( 22 )[0][1] && ab_token( $res ) && array( 'status' => 'trash' ) === (array) $res['saved_fields'], 'A13 trash: into the trash, the status before noted' );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && '0' === ab_status( 22 ) && array() === ab_trash_rows( 22 ) && ab_did( 'untrashed_comment', 22 ), 'A14 the undo of trash takes it out with wp_untrash_comment: pending again, the notes removed' );

reset_all();
ab_comment( 23, array( 'comment_approved' => '0' ) );
$res = ab_moderate( 23, 'approve' );
ok( '1' === ab_status( 23 ) && array( 'status' => 'approved' ) === (array) $res['saved_fields'], 'A15 approve' );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && '0' === ab_status( 23 ) && ! ab_did( 'untrashed_comment', 23 ) && ! ab_did( 'unspammed_comment', 23 ), 'A16 the undo of approve sets the old status with wp_set_comment_status' );

// The trash rows of before come back too: the trash and the spam replace them.
reset_all();
ab_comment( 24, array( 'comment_approved' => 'spam' ) );
ab_meta_add( 24, '_wp_trash_meta_status', '0' );
ab_meta_add( 24, '_wp_trash_meta_time', '1700000000' );
$res = ab_moderate( 24, 'trash' );
ok( 'trash' === ab_status( 24 ) && 'spam' === ab_trash_rows( 24 )[0][1], 'A17 a spam comment trashed: WordPress replaced its notes' );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && 'spam' === ab_status( 24 ) && array( array( '_wp_trash_meta_status', '0' ), array( '_wp_trash_meta_time', '1700000000' ) ) === ab_trash_rows( 24 ), 'A18 the undo: spam again, with the notes it had, byte for byte' );
ok( ab_did( 'untrashed_comment', 24 ), 'A19 ... out of the trash with wp_untrash_comment first' );

reset_all();
ab_comment( 25, array( 'comment_approved' => 'trash' ) );
ab_meta_add( 25, '_wp_trash_meta_status', '1' );
ab_meta_add( 25, '_wp_trash_meta_time', '1700000001' );
$res = ab_moderate( 25, 'spam' );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && 'trash' === ab_status( 25 ) && array( array( '_wp_trash_meta_status', '1' ), array( '_wp_trash_meta_time', '1700000001' ) ) === ab_trash_rows( 25 ) && ab_did( 'unspammed_comment', 25 ), 'A20 a trashed comment marked spam: the undo puts it in the trash again with its old notes' );

reset_all();
ab_comment( 26, array( 'comment_approved' => 'trash' ) );
ab_meta_add( 26, '_wp_trash_meta_status', '0' );
ab_meta_add( 26, '_wp_trash_meta_time', '1700000002' );
$res = ab_moderate( 26, 'approve' );
ok( '1' === ab_status( 26 ) && 2 === count( ab_trash_rows( 26 ) ), 'A21 approve on a trashed comment leaves the notes, as WordPress does' );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && 'trash' === ab_status( 26 ) && array( array( '_wp_trash_meta_status', '0' ), array( '_wp_trash_meta_time', '1700000002' ) ) === ab_trash_rows( 26 ), 'A22 ... and its undo puts it back into the trash as it was' );

/* ============================== B) nothing changed, and nothing stored */

reset_all();
ab_comment( 30 );
$res = ab_moderate( 30, 'approve' );
ok( true === $res['moderated'] && null === $res['undo'] && false !== strpos( $res['undo_note'], 'nothing changed' ) && false !== strpos( $res['undo_note'], 'wp_get_comment' ) && 0 === ab_points(), 'B1 the status the comment had already: no point, the note says why' );
ok( array( 'status' => 'approved' ) === (array) $res['saved_fields'] && ! isset( $res['saved_differs'] ), 'B2 ... and saved_fields shows it' );
$res = ab_moderate( 30, 'trash' );
$res = ab_moderate( 30, 'trash' );
ok( null === $res['undo'] && false !== strpos( $res['undo_note'], 'nothing changed' ) && 1 === ab_points(), 'B3 trash on a trashed comment: nothing changed, no second point' );

reset_all();
ab_comment( 31 );
$GLOBALS['ab_status_veto'] = array( 31 );
foreach ( array( 'hold', 'spam', 'trash' ) as $status ) {
	$res = ab_moderate( 31, $status );
	ok( 'ab_mcp_not_saved' === ab_code( $res ) && false !== strpos( ab_msg( $res ), 'wp_get_comment' ) && '1' === ab_status( 31 ) && 0 === ab_points(), "B4 a status WordPress did not store ($status): an error naming the way, not moderated: true" );
}
$res = ab_delete( 31 );
ok( 'ab_mcp_delete_failed' === ab_code( $res ) && 0 === ab_points(), 'B5 a comment WordPress did not move to the trash: the error of before' );
$GLOBALS['ab_status_veto'] = array();
ok( 'ab_mcp_bad_status' === ab_code( ab_moderate( 31, 'publish' ) ) && 0 === ab_points(), 'B6 an unknown status is refused before anything is written' );
ok( 'ab_mcp_not_found' === ab_code( ab_moderate( 999, 'hold' ) ), 'B7 an unknown comment' );

/* ============================== C) changed since: refused unless force */

reset_all();
ab_comment( 40 );
$res = ab_moderate( 40, 'spam' );
wp_set_comment_status( 40, 'approve' ); // a moderator approves it in wp-admin
$out = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && 'changed_since' === $out['blocked'][0]['reason'] && array( 'comment:40' ) === $out['blocked'][0]['locs'] && null !== ab_entry( $res['undo'] ), 'C1 a comment moderated since is not put back, the point stays' );
ok( '1' === ab_status( 40 ) && 2 === count( ab_trash_rows( 40 ) ), 'C2 ... and nothing was written' );
$out = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 1 === $out['undone'] && '1' === ab_status( 40 ) && array() === ab_trash_rows( 40 ), 'C3 with force it is, as before the change (the stale notes go)' );

reset_all();
ab_comment( 41 );
$res = ab_moderate( 41, 'trash' );
foreach ( $GLOBALS['T']['commentmeta'] as $mid => $r ) {
	if ( '_wp_trash_meta_time' === $r[1] ) { $GLOBALS['T']['commentmeta'][ $mid ][2] = '1'; }
}
$dry = ab_undo( $res['undo'], array( 'dry_run' => true ) );
ok( 0 === $dry['would_undo'] && 'changed_since' === $dry['blocked'][0]['reason'], 'C4 only the trash notes changed since: changed' );

reset_all();
ab_comment( 42 );
$res = ab_moderate( 42, 'hold' );
wp_set_comment_status( 42, 'approve' );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && array() === $out['blocked'] && true === $out['consumed'] && '1' === ab_status( 42 ) && ! in_array( 42, array_slice( $GLOBALS['ab_notified'], 1 ), true ), 'C5 a comment back at its status of before since: nothing to write, the point is used up' );

reset_all();
ab_comment( 43 );
$res  = ab_moderate( 43, 'hold' );
$plan = AB_MCP_Undo_Comments::plan( 'wp_moderate_comment', AB_MCP_Undo::claim( $res['undo'] )['data'], false );
wp_set_comment_status( 43, 'spam' ); // between the plan and the write
$again = AB_MCP_Undo_Comments::restore( $plan['items'][0] );
ok( 'ab_mcp_undo_comment_changed' === ab_code( $again ) && false !== strpos( ab_msg( $again ), 'force=true' ) && 'spam' === ab_status( 43 ), 'C6 changed between the plan and the write: refused, with the way' );

reset_all();
ab_comment( 44 );
$res = ab_moderate( 44, 'trash' );
wp_delete_comment( 44, true );
$out = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 0 === $out['undone'] && 'gone' === $out['blocked'][0]['reason'], 'C7 a comment deleted for good since: gone, also with force' );

/* ============================== D) the rights of the tool, asked again */

reset_all();
ab_comment( 50 );
$res                = ab_moderate( 50, 'spam' );
$GLOBALS['ab_deny'] = array( 'edit_comment:50' => true );
$out                = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 0 === $out['undone'] && 'forbidden' === $out['blocked'][0]['reason'] && 'spam' === ab_status( 50 ), 'D1 a comment the account may no longer edit: forbidden, even with force' );
$GLOBALS['ab_deny'] = array( 'moderate_comments' => true );
ok( 'ab_mcp_undo_forbidden' === ab_code( ab_undo( $res['undo'] ) ) && 'spam' === ab_status( 50 ), 'D2 without moderate_comments, the capability of the tool, wp_undo refuses' );
$GLOBALS['ab_deny'] = array();
ok( 1 === ab_undo( $res['undo'] )['undone'], 'D3 with both, it goes back' );

reset_all();
ab_comment( 51 );
$res                = ab_reply( 'Danke', 51 );
$GLOBALS['ab_deny'] = array( 'edit_comment:' . $res['comment_id'] => true );
$out                = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 0 === $out['undone'] && 'forbidden' === $out['blocked'][0]['reason'] && null !== ab_status( $res['comment_id'] ), 'D4 a reply the account may no longer edit is not deleted, even with force' );

reset_all();
ab_comment( 52 );
$GLOBALS['ab_deny'] = array( 'edit_comment:52' => true );
ok( 'ab_mcp_forbidden' === ab_code( ab_moderate( 52, 'spam' ) ) && 'ab_mcp_forbidden' === ab_code( ab_delete( 52 ) ) && '1' === ab_status( 52 ) && 0 === ab_points(), 'D5 the tools themselves refuse a comment the account may not edit' );

/* ============================== E) a note and its reply notes */

reset_all();
ab_note_thread();
$res = ab_moderate( 10, 'trash' );
ok( 'trash' === ab_status( 10 ) && 'trash' === ab_status( 11 ) && 'trash' === ab_status( 12 ) && '1' === ab_status( 14 ), 'E1 trashing a top-level note trashes its approved and pending reply notes, as WordPress does' );
$c = AB_MCP_Undo::claim( $res['undo'] );
ok( array( 'comment:10', 'comment:11', 'comment:12' ) === array_keys( $c['data']['before'] ) && array( 'comment:10', 'comment:11', 'comment:12' ) === array_keys( $c['data']['after'] ), 'E2 the point holds the note and each reply note it took, the note first' );
ok( array( 'status' => '0', 'trash' => array() ) === $c['data']['before']['comment:12'] && 'comment #10: trash, with 2 reply notes' === $c['entry']['summary'], 'E3 each reply note with its own state; the summary counts them' );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && '1' === ab_status( 10 ) && '1' === ab_status( 11 ) && '0' === ab_status( 12 ), 'E4 the undo takes the note and its reply notes out of the trash, each to its own status' );
ok( array() === ab_trash_rows( 10 ) && array() === ab_trash_rows( 11 ) && array() === ab_trash_rows( 12 ) && ab_did( 'untrashed_comment', 11 ) && ab_did( 'untrashed_comment', 12 ), 'E5 ... each with wp_untrash_comment' );
ok( 'trash' === ab_status( 13 ) && array( array( '_wp_trash_meta_status', '1' ), array( '_wp_trash_meta_time', '1700000000' ) ) === ab_trash_rows( 13 ) && '1' === ab_status( 14 ), 'E6 a reply note in the trash before stays there untouched, a reply that is no note too' );

reset_all();
ab_note_thread();
$res = ab_moderate( 10, 'trash' );
wp_spam_comment( 11 ); // a reply note moderated since
$out = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && 'changed_since' === $out['blocked'][0]['reason'] && array( 'comment:11' ) === $out['blocked'][0]['locs'] && 'trash' === ab_status( 10 ), 'E7 a reply note moderated since: changed_since names it, and nothing comes back without force' );
$out = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 1 === $out['undone'] && '1' === ab_status( 10 ) && '1' === ab_status( 11 ) && '0' === ab_status( 12 ) && array() === ab_trash_rows( 11 ), 'E8 with force it comes back with the others' );

reset_all();
ab_note_thread();
$res = ab_moderate( 10, 'trash' );
wp_delete_comment( 11, true ); // emptied from the trash since
$out = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && array( 'comment:11' ) === $out['blocked'][0]['locs'], 'E9 a reply note deleted for good since: changed' );
$out = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 1 === $out['undone'] && '1' === ab_status( 10 ) && '0' === ab_status( 12 ) && null === ab_status( 11 ), 'E10 with force the rest comes back; the deleted one stays deleted' );

reset_all();
ab_note_thread();
$res                = ab_moderate( 10, 'trash' );
$GLOBALS['ab_deny'] = array( 'edit_comment:12' => true );
$out                = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 0 === $out['undone'] && 'forbidden' === $out['blocked'][0]['reason'] && 'trash' === ab_status( 10 ) && 'trash' === ab_status( 12 ), 'E11 a reply note the account may not edit: the point is refused, even with force' );

reset_all();
ab_note_thread();
$res = ab_moderate( 10, 'spam' );
ok( array( 'comment:10' ) === array_keys( AB_MCP_Undo::claim( $res['undo'] )['data']['before'] ) && '1' === ab_status( 11 ), 'E12 spam takes no reply notes, and the point holds none' );

reset_all();
ab_comment( 15, array( 'comment_type' => 'note', 'comment_parent' => 9 ) );
ab_comment( 16, array( 'comment_type' => 'note', 'comment_parent' => 15 ) );
$res = ab_moderate( 15, 'trash' );
ok( '1' === ab_status( 16 ) && array( 'comment:15' ) === array_keys( AB_MCP_Undo::claim( $res['undo'] )['data']['before'] ), 'E13 a note that is itself a reply takes none' );

reset_all();
ab_note_thread();
$res = ab_delete( 10 );
ok( true === $res['deleted'] && ab_token( $res ) && 'trash' === ab_status( 11 ) && 3 === count( AB_MCP_Undo::claim( $res['undo'] )['data']['before'] ), 'E14 wp_delete_comment into the trash takes the reply notes too, and its point holds them' );
ok( 1 === ab_undo( $res['undo'] )['undone'] && '1' === ab_status( 10 ) && '1' === ab_status( 11 ) && '0' === ab_status( 12 ), 'E15 ... and its undo brings them all back' );

/* ============================== F) wp_delete_comment */

reset_all();
ab_comment( 60, array( 'comment_approved' => '0' ) );
$res = ab_delete( 60 );
ok( true === $res['deleted'] && 60 === $res['comment_id'] && ab_token( $res ) && array( 'status' => 'trash' ) === (array) $res['saved_fields'] && 'trash' === ab_status( 60 ), 'F1 into the trash: a token, and the status read back' );
$c = AB_MCP_Undo::claim( $res['undo'] );
ok( 'wp_delete_comment' === $c['entry']['tool'] && 'comment' === $c['entry']['kind'] && 'comment #60: into the trash' === $c['entry']['summary'], 'F2 kind comment, the summary names the comment' );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && '0' === ab_status( 60 ) && array() === ab_trash_rows( 60 ) && ab_did( 'untrashed_comment', 60 ), 'F3 the undo takes it out with wp_untrash_comment: pending again, the notes removed' );

foreach ( array(
	'with force'          => array( array(), true ),
	'already in the trash' => array( array( 'comment_approved' => 'trash' ), false ),
	'marked as spam'      => array( array( 'comment_approved' => 'spam' ), false ),
) as $what => $case ) {
	reset_all();
	ab_comment( 61, $case[0] );
	ab_meta_add( 61, 'rating', '4' );
	ab_comment( 62, array( 'comment_parent' => 61 ) );
	$res = ab_delete( 61, $case[1] );
	ok( true === $res['deleted'] && null === $res['undo'] && 0 === strpos( (string) $res['undo_note'], 'not undoable: WordPress deletes a comment for good' ) && 0 === ab_points(), "F4 deleted for good $what: undo null, not undoable" );
	ok( false !== strpos( $res['undo_note'], 'wp_get_comment' ) && false !== strpos( $res['undo_note'], 'wp_reply_comment' ), "F5 ... the note names how to keep it first and how to add it anew ($what)" );
	ok( null === ab_status( 61 ) && '0' === $GLOBALS['T']['comments'][62]['comment_parent'] && array() === array_filter( $GLOBALS['T']['commentmeta'], static fn( $r ) => 61 === $r[0] ), "F6 ... gone with its meta, the reply moved up ($what)" );
}
reset_all();
ab_comment( 63, array( 'comment_approved' => 'spam' ) );
$GLOBALS['ab_delete_refused'] = true;
$res = ab_delete( 63 );
ok( 'ab_mcp_delete_failed' === ab_code( $res ) && false !== strpos( ab_msg( $res ), 'wp_get_comment' ) && 'spam' === ab_status( 63 ), 'F7 a deletion for good that failed: the error of before, with the way' );
$GLOBALS['ab_delete_refused'] = false;
ok( 'ab_mcp_not_found' === ab_code( ab_delete( 998 ) ), 'F8 an unknown comment' );

/* ============================== R) wp_reply_comment: a new comment, deleted again */

reset_all();
ab_comment( 70 );
$res = ab_reply( 'Danke für die <b>Frage</b><script>x</script>', 70 );
$id  = $res['comment_id'];
ok( true === $res['created'] && ab_token( $res ) && ! isset( $res['undo_note'] ), 'R1 wp_reply_comment answers with a token' );
ok( array( 'post_id' => 5, 'parent' => 70, 'content' => 'Danke für die <b>Frage</b>x', 'status' => 'approved' ) === (array) $res['saved_fields'] && ! isset( $res['saved_differs'] ), 'R2 saved_fields: post, parent, the content as filtered and stored, the status' );
$c = AB_MCP_Undo::claim( $res['undo'] );
ok( 'wp_reply_comment' === $c['entry']['tool'] && 'comment' === $c['entry']['kind'] && array( 'v', 'id', 'after' ) === array_keys( $c['data'] ) && array( 'comment', 'use' ) === array_keys( $c['data']['after'] ), 'R3 the snapshot names the comment and keeps the state after' );
ok( sprintf( 'new comment #%d on post #5', $id ) === $c['entry']['summary'] && 2 === ab_count(), 'R4 the summary names comment and post by id; the post counts it' );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && array( 'comment:' . $id ) === $out['restored'] && null === ab_status( $id ) && 1 === ab_count(), 'R5 wp_undo deletes it again, for good, and the post counts again' );
ok( ab_did( 'deleted_comment', $id ) && ! ab_did( 'trashed_comment', $id ), 'R6 ... through wp_delete_comment with force, not into the trash' );

foreach ( array(
	'answered'          => array( 'use', static function ( $id ) { ab_comment( 71, array( 'comment_parent' => $id ) ); } ),
	'given meta'        => array( 'use', static function ( $id ) { ab_meta_add( $id, 'akismet_result', 'false' ); } ),
	'edited'            => array( 'comment', static function ( $id ) { $GLOBALS['T']['comments'][ $id ]['comment_content'] = 'Anders'; } ),
	'moderated'         => array( 'comment', static function ( $id ) { wp_set_comment_status( $id, 'hold' ); } ),
	'moved to a post'   => array( 'comment', static function ( $id ) { $GLOBALS['T']['comments'][ $id ]['comment_post_ID'] = '6'; } ),
) as $what => $case ) {
	reset_all();
	$res = ab_reply( 'Hallo' );
	( $case[1] )( $res['comment_id'] );
	$out = ab_undo( $res['undo'] );
	ok( 0 === $out['undone'] && 'changed_since' === $out['blocked'][0]['reason'] && array( $case[0] ) === $out['blocked'][0]['locs'] && null !== ab_status( $res['comment_id'] ) && null !== ab_entry( $res['undo'] ), "R7 a reply $what since is not deleted, the location named, the point stays" );
}
$out = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 1 === $out['undone'] && null === ab_status( $res['comment_id'] ), 'R8 with force it is' );

reset_all();
$res = ab_reply( 'Hallo' );
ab_comment( 72, array( 'comment_parent' => $res['comment_id'] ) );
$out = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 1 === $out['undone'] && '0' === $GLOBALS['T']['comments'][72]['comment_parent'], 'R9 with force, its answer moves up a level, as WordPress deletes' );

reset_all();
$res = ab_reply( 'Hallo' );
wp_delete_comment( $res['comment_id'], true );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && array() === $out['blocked'] && true === $out['consumed'], 'R10 a reply deleted since: nothing to do, the point is used up' );

reset_all();
$res                       = ab_reply( 'Hallo' );
$GLOBALS['ab_delete_lies'] = true;
$out                       = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && false !== strpos( (string) $out['failed'][0]['reason'], 'wp_get_comment' ) && null !== ab_entry( $res['undo'] ), 'R11 a deletion WordPress reported and did not make: failed, the point stays' );
$GLOBALS['ab_delete_lies'] = false;

reset_all();
$GLOBALS['ab_on_insert'] = static function ( $id ) { $GLOBALS['T']['comments'][ $id ]['comment_content'] = 'Umgeschrieben'; };
$res = ab_reply( 'Hallo' );
ok( array( 'content' ) === $res['saved_differs'] && 'Umgeschrieben' === $res['saved_fields']->content && false !== strpos( $res['warning'], 'wp_get_comment' ) && ab_token( $res ), 'R12 a plugin that rewrites the content: saved_differs and a warning naming the reader, still a token' );

/* ============================== P) a comment whose post is in the trash: no point */

foreach ( array(
	'wp_moderate_comment approve' => array( static fn() => ab_moderate( 80, 'approve' ), '1', 'moderated' ),
	'wp_moderate_comment spam'    => array( static fn() => ab_moderate( 80, 'spam' ), 'spam', 'moderated' ),
	'wp_moderate_comment trash'   => array( static fn() => ab_moderate( 80, 'trash' ), 'trash', 'moderated' ),
	'wp_delete_comment'           => array( static fn() => ab_delete( 80 ), 'trash', 'deleted' ),
) as $what => $case ) {
	reset_all();
	ab_comment( 80, array( 'comment_approved' => 'post-trashed' ) );
	$res = ( $case[0] )();
	ok( true === $res[ $case[2] ] && $case[1] === ab_status( 80 ) && null === $res['undo'] && 0 === ab_points(), "P1 a post-trashed comment changed ($what): no undo point that would always be refused" );
	ok( AB_MCP_Undo_Comments::post_trashed_note() === $res['undo_note'] && 0 === strpos( $res['undo_note'], 'not undoable: The post of this comment is in the trash' ), "P2 ... the note says why ($what)" );
	ok( false !== strpos( $res['undo_note'], 'wp_bulk_update_status' ) && false !== strpos( $res['undo_note'], 'wp-admin' ) && false !== strpos( $res['undo_note'], 'wp_get_comment' ), "P3 ... and the way: the post out of the trash ($what)" );
}
reset_all();
ab_comment( 81, array( 'comment_approved' => 'post-trashed' ) );
$res = ab_moderate( 81, 'approve' );
$res = ab_moderate( 81, 'hold' );
ok( ab_token( $res ) && array( 'status' => '1', 'trash' => array() ) === AB_MCP_Undo::claim( $res['undo'] )['data']['before']['comment:81'], 'P4 moderated again from a status that can be set: a point as for any comment' );
reset_all();
ab_comment( 82, array( 'comment_approved' => 'held-by-plugin' ) );
$res = ab_moderate( 82, 'approve' );
ok( null === $res['undo'] && false !== strpos( $res['undo_note'], 'No undo point was recorded' ) && false === strpos( $res['undo_note'], 'trash' ) && 0 === ab_points(), 'P5 another status no call sets: no point, and no reason that is not true' );
reset_all();
ab_comment( 83, array( 'comment_approved' => 'post-trashed' ) );
$GLOBALS['ab_toolstate']['wp_undo'] = false;
$res = ab_moderate( 83, 'approve' );
ok( null === $res['undo'] && false !== strpos( $res['undo_note'], 'Undo is switched off' ), 'P6 with undo switched off, the note says that' );

/* ============================== H) why there is no undo point */

reset_all();
ab_comment( 90 );
ab_comment( 91 );
$GLOBALS['ab_toolstate']['wp_undo'] = false;
foreach ( array(
	'wp_moderate_comment' => static fn() => ab_moderate( 90, 'spam' ),
	'wp_delete_comment'   => static fn() => ab_delete( 91 ),
	'wp_reply_comment'    => static fn() => ab_reply( 'Aus' ),
) as $tool => $call ) {
	$res = $call();
	ok( is_array( $res ) && null === $res['undo'] && false !== strpos( $res['undo_note'], 'Undo is switched off on this site' ) && 0 === ab_points(), "H1 undo switched off: the note says so ($tool)" );
}
ok( 'spam' === ab_status( 90 ) && 'trash' === ab_status( 91 ), 'H2 the writes happen all the same' );

foreach ( array( 'ab_db_down', 'ab_db_error' ) as $broken ) {
	reset_all();
	ab_comment( 92 );
	ab_comment( 93 );
	$GLOBALS[ $broken ] = true;
	foreach ( array(
		'wp_moderate_comment' => static fn() => ab_moderate( 92, 'hold' ),
		'wp_delete_comment'   => static fn() => ab_delete( 93 ),
		'wp_reply_comment'    => static fn() => ab_reply( 'Blind' ),
	) as $tool => $call ) {
		$res = $call();
		ok( is_array( $res ) && null === $res['undo'] && false !== strpos( $res['undo_note'], 'could not be read from the database' ) && 0 === ab_points(), "H3 the tables could not be read ($broken): no point on a guess ($tool)" );
	}
	ok( '0' === ab_status( 92 ) && 'trash' === ab_status( 93 ), "H4 the writes stand, and the read-back still answers ($broken)" );
}

reset_all();
ab_note_thread();
$GLOBALS['ab_db_error'] = false;
// The note readable, its replies not: no point that would leave them in the trash.
$wpdb_orig       = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new class( $wpdb_orig ) {
	public $comments    = 'wp_comments';
	public $commentmeta = 'wp_commentmeta';
	public $last_error  = '';
	public $last_result = array();
	private $inner;
	public function __construct( $inner ) { $this->inner = $inner; }
	public function prepare( $sql, ...$args ) { return $this->inner->prepare( $sql, ...$args ); }
	public function query( $sql ) {
		if ( 0 === strpos( $sql, 'SELECT comment_ID, comment_type FROM' ) ) { $this->last_result = array(); return false; }
		$r = $this->inner->query( $sql );
		$this->last_result = $this->inner->last_result;
		$this->last_error  = $this->inner->last_error;
		return $r;
	}
};
$res = ab_moderate( 10, 'trash' );
$GLOBALS['wpdb'] = $wpdb_orig;
ok( null === $res['undo'] && false !== strpos( $res['undo_note'], 'could not be read' ) && 'trash' === ab_status( 11 ), 'H5 a note whose reply notes could not be read: no point' );

reset_all();
ab_comment( 94 );
$GLOBALS['ab_actions']['trashed_comment'] = array( static function ( $id ) { wp_delete_comment( $id, true ); } ); // a plugin that empties the trash at once
$res = ab_moderate( 94, 'trash' );
unset( $GLOBALS['ab_actions']['trashed_comment'] );
ok( true === $res['moderated'] && null === $res['undo'] && false !== strpos( $res['undo_note'], 'No undo point was recorded' ) && 0 === ab_points(), 'H6 a comment a plugin deleted on the way: no point that could never be used' );
ok( array( 'status' ) === $res['saved_differs'] && false !== strpos( $res['warning'], 'wp_get_comment' ), 'H7 ... and the read-back says the comment does not hold what was asked' );

/* ============================== K) a hand-edited point gains nothing */

reset_all();
ab_comment( 100 );
ab_comment( 101 );
$res  = ab_moderate( 100, 'spam' );
$good = AB_MCP_Undo::claim( $res['undo'] )['data'];
$bad  = array(
	'a field more'                    => array_merge( $good, array( 'x' => 1 ) ),
	'another version'                 => array_merge( $good, array( 'v' => 2 ) ),
	'the id as text'                  => array_merge( $good, array( 'id' => '100' ) ),
	'the first location not the call' => array_merge( $good, array( 'before' => array( 'comment:101' => $good['before']['comment:100'], 'comment:100' => $good['before']['comment:100'] ) ) ),
	'after without the location'      => array_merge( $good, array( 'after' => array() ) ),
	'a status in another spelling'    => array_merge( $good, array( 'before' => array( 'comment:100' => array( 'status' => 'SPAM', 'trash' => array() ) ) ) ),
	'a status no call sets'           => array_merge( $good, array( 'before' => array( 'comment:100' => array( 'status' => 'post-trashed', 'trash' => array() ) ) ) ),
	'a foreign meta key'              => array_merge( $good, array( 'before' => array( 'comment:100' => array( 'status' => '1', 'trash' => array( array( '_edit_lock', '1' ) ) ) ) ) ),
	'a trash time that is no time'    => array_merge( $good, array( 'before' => array( 'comment:100' => array( 'status' => '1', 'trash' => array( array( '_wp_trash_meta_time', 'x' ) ) ) ) ) ),
	'a location of another form'      => array_merge( $good, array( 'before' => array( 'comment:100' => $good['before']['comment:100'], 'post:5' => $good['before']['comment:100'] ) ) ),
	'a hash that is none'             => array_merge( $good, array( 'after' => array( 'comment:100' => 'x' ) ) ),
);
foreach ( $bad as $what => $data ) {
	$t = ab_forge( 'wp_moderate_comment', $data );
	$p = AB_MCP_Undo_Restore::plan( ab_entry( $t ), AB_MCP_Undo::claim( $t )['data'] );
	ok( 'ab_mcp_undo_corrupt' === ab_code( $p ) && false !== strpos( ab_msg( $p ), 'wp_undo_discard' ), "K1 refused whole: $what" );
}
ok( 'spam' === ab_status( 100 ), 'K2 ... and nothing written' );
$t = ab_forge( 'wp_reply_comment', $good );
ok( 'ab_mcp_undo_corrupt' === ab_code( AB_MCP_Undo_Restore::plan( ab_entry( $t ), AB_MCP_Undo::claim( $t )['data'] ) ), 'K3 a status point under the tool that creates is refused' );
$t = ab_forge( 'wp_moderate_comment', array( 'v' => 1, 'id' => 100, 'after' => $good['after'] ) );
ok( 'ab_mcp_undo_corrupt' === ab_code( AB_MCP_Undo_Restore::plan( ab_entry( $t ), AB_MCP_Undo::claim( $t )['data'] ) ), 'K4 a point without the state before is refused' );
$t = AB_MCP_Undo::record( array( 'tool' => 'wp_moderate_comment', 'cap' => 'x', 'kind' => 'term', 'summary' => 'forged', 'items' => 1, 'data' => $good ) );
ok( 'ab_mcp_undo_kind_mismatch' === ab_code( AB_MCP_Undo_Restore::plan( ab_entry( $t ), AB_MCP_Undo::claim( $t )['data'] ) ), 'K5 a kind that does not match its tool' );
ok( 1 === ab_undo( $res['undo'] )['undone'] && '1' === ab_status( 100 ), 'K6 the real point still works' );

/* ============================== V) answers name ids, never a comment's text */

reset_all();
ab_comment( 110 );
$res = ab_reply( 'Vertraulicher Inhalt' );
$GLOBALS['T']['comments'][ $res['comment_id'] ]['comment_content'] = 'Noch vertraulicher';
$dry  = ab_undo( $res['undo'], array( 'dry_run' => true ) );
$list = AB_MCP_Tools_Undo_Core::undo_list( array() );
$json = (string) wp_json_encode( array( $dry, $list ) );
ok( false === strpos( $json, 'vertraulich' ) && false === strpos( $json, 'Geheimer' ) && 1 === $list['count'], 'V1 neither the dry run nor wp_undo_list shows the text of a comment' );

/* ============================== N) the notes of a deletion without undo */

$notes = AB_MCP_Undo_Objects::own_notes();
foreach ( array( 'wp_delete_comment', 'wp_moderate_comment', 'post-trashed' ) as $tool ) {
	ok( isset( $notes[ $tool ] ) && 0 === strpos( $notes[ $tool ], 'not undoable: ' ) && false !== strpos( $notes[ $tool ], 'wp_get_comment' ), "N1 the note of $tool says not undoable and names wp_get_comment" );
}
ok( $notes['wp_delete_comment'] !== $notes['wp_moderate_comment'], 'N2 the trash turned off is its own reason' );

/* ============================== T) the trash turned off, in a process of its own */

$pipes = array();
$proc  = proc_open(
	array( PHP_BINARY, __FILE__ ),
	array(
		0 => array( 'file', '/dev/null', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'redirect', 1 ),
	),
	$pipes,
	__DIR__,
	array_merge( getenv(), array( 'AB_TRASH_OFF' => '1' ) )
);
$child = is_resource( $proc ) ? (string) stream_get_contents( $pipes[1] ) : '';
if ( is_resource( $proc ) ) {
	fclose( $pipes[1] );
	$code = proc_close( $proc );
} else {
	$code = -1;
}
if ( 1 === preg_match( '/^UNDO-COMMENTS-TRASH-OFF: (\d+) passed, (\d+) failed$/m', $child, $m ) ) {
	$pass += (int) $m[1];
	$fail += (int) $m[2];
	ok( (int) $m[1] >= 6 && 0 === (int) $m[2] && 0 === $code, 'T0 the process with the trash off ran its checks' );
	$rest = trim( str_replace( $m[0], '', $child ) );
	if ( '' !== $rest ) {
		echo $rest, "\n";
	}
} else {
	ok( false, "T0 the process with the trash off did not finish:\n" . $child );
}

/* ============================== end */

echo "UNDO-COMMENTS: $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
