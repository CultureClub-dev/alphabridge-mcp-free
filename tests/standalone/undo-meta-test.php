<?php
/**
 * The undo of the meta tools (kind meta, since 4.13.0) and the read-back of
 * their answers, without WordPress: the meta tables of posts, terms and users
 * and the posts table are in-memory rows with ids, read and written through a
 * $wpdb that answers the very queries AB_MCP_Undo_Meta makes, and
 * update_*_meta() work as update_metadata() does (unslash, the sanitize
 * filter, serialize; every row of the key gets the value, a missing key gets
 * a row). The undo store, the restore, wp_undo, the term, user and media
 * tools are the real ones.
 *
 * Proves: a write records one point with every row of the key and its id; a
 * restore puts the rows back byte for byte (several rows, a serialized
 * string, bytes that are no UTF-8), removes a key the change created and rows
 * it added, and refuses a key changed since unless force; it asks the rights
 * and the key rules of the tool again, and a hand-edited point in another
 * form is refused whole; the answer says why there is no point (undo off, too
 * large, Pro's own wp_undo, a database that could not be read); saved_fields
 * is what is stored, a write the site did not store is an error, a value the
 * site rewrote is reported; no answer about a point shows a value.
 *
 * Run:  php tests/standalone/undo-meta-test.php
 */

error_reporting( E_ALL & ~E_DEPRECATED );

define( 'ABSPATH', __DIR__ . '/' );
define( 'KB_IN_BYTES', 1024 );
define( 'MB_IN_BYTES', 1048576 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );
define( 'EMPTY_TRASH_DAYS', 30 );

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
$GLOBALS['ab_deny'] = array(); // 'cap', 'cap:obj' or 'cap:obj:key' => true
function get_current_user_id() { return (int) $GLOBALS['ab_uid']; }
function current_user_can( $cap, ...$args ) {
	$full = $cap . ( $args ? ':' . implode( ':', array_map( 'strval', $args ) ) : '' );
	$one  = $cap . ( $args ? ':' . (string) $args[0] : '' );
	return ! isset( $GLOBALS['ab_deny'][ $full ] ) && ! isset( $GLOBALS['ab_deny'][ $one ] ) && ! isset( $GLOBALS['ab_deny'][ $cap ] );
}
function wp_get_current_user() { return (object) array( 'user_login' => 'editor' ); }

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
$GLOBALS['ab_cache_deleted'] = array();
function wp_cache_delete( $k, $group = '' ) { $GLOBALS['ab_cache_deleted'][] = $group . ':' . $k; return true; }

/* ---- filters: a value per tag, so limits and switches can be set ---- */
$GLOBALS['ab_filters'] = array();
function apply_filters( $tag, $value, ...$args ) {
	if ( array_key_exists( $tag, $GLOBALS['ab_filters'] ) ) {
		$f = $GLOBALS['ab_filters'][ $tag ];
		return is_callable( $f ) && ! is_string( $f ) ? $f( $value, ...$args ) : $f;
	}
	return $value;
}
function has_filter( $tag, $cb = false ) { return array_key_exists( $tag, $GLOBALS['ab_filters'] ); }
function has_action( $tag, $cb = false ) { return false; }
function do_action( $tag, ...$args ) {}
function is_multisite() { return false; }
$GLOBALS['ab_cleaned'] = array();
function clean_post_cache( $id ) { $GLOBALS['ab_cleaned'][] = (int) $id; }
$GLOBALS['ab_last_changed'] = array();
function wp_cache_set_posts_last_changed() { $GLOBALS['ab_last_changed'][] = 'posts'; }
function wp_cache_set_terms_last_changed() { $GLOBALS['ab_last_changed'][] = 'terms'; }
function wp_cache_set_users_last_changed() { $GLOBALS['ab_last_changed'][] = 'users'; }

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
$GLOBALS['ab_upload'] = sys_get_temp_dir() . '/ab-free-undo-meta-' . getmypid() . '-' . bin2hex( random_bytes( 6 ) );
register_shutdown_function( static function () { ab_test_rm_tree( $GLOBALS['ab_upload'] ); } );
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['ab_upload'], 'baseurl' => 'http://x/u', 'error' => false ); }
function wp_slash( $v ) { return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function is_serialized( $data ) {
	if ( ! is_string( $data ) ) { return false; }
	$data = trim( $data );
	if ( 'N;' === $data ) { return true; }
	if ( ! preg_match( '/^[aOsibd]:/', $data ) ) { return false; }
	return 'b:0;' === $data || false !== @unserialize( $data ); // phpcs:ignore
}
function maybe_serialize( $v ) {
	if ( is_array( $v ) || is_object( $v ) ) { return serialize( $v ); } // phpcs:ignore
	if ( is_serialized( $v ) ) { return serialize( $v ); } // phpcs:ignore -- as WordPress: serialized again.
	return $v;
}
function maybe_unserialize( $v ) { return is_serialized( $v ) ? @unserialize( trim( $v ) ) : $v; } // phpcs:ignore

/* ---- the tables: meta rows with ids, posts, terms, users ---- */
$GLOBALS['ab_meta']    = array( 'post' => array(), 'term' => array(), 'user' => array() ); // id => [ obj, key, value ]
$GLOBALS['ab_next_id'] = 100;
$GLOBALS['ab_posts']   = array();
$GLOBALS['ab_terms']   = array();
$GLOBALS['ab_users']   = array();
$GLOBALS['ab_db_down'] = false; // the meta tables cannot be read
$GLOBALS['ab_db_nowrite'] = false; // the meta tables take no write
$GLOBALS['ab_db_lies']    = false; // an update that reports a row written and writes nothing
const AB_TABLES = array(
	'wp_postmeta' => array( 'post', 'post_id', 'meta_id' ),
	'wp_termmeta' => array( 'term', 'term_id', 'meta_id' ),
	'wp_usermeta' => array( 'user', 'user_id', 'umeta_id' ),
);
function ab_add_row( $type, $obj, $key, $value, $id = null ) {
	$id = null === $id ? $GLOBALS['ab_next_id']++ : (int) $id;
	$GLOBALS['ab_meta'][ $type ][ $id ] = array( (int) $obj, (string) $key, (string) $value );
	ksort( $GLOBALS['ab_meta'][ $type ] );
	return $id;
}
/* Rows of one key: id => value, oldest first. */
function ab_rows( $type, $obj, $key ) {
	$out = array();
	foreach ( $GLOBALS['ab_meta'][ $type ] as $id => $r ) {
		if ( $r[0] === (int) $obj && $r[1] === (string) $key ) { $out[ $id ] = $r[2]; }
	}
	return $out;
}
/* As the table compares meta_key: under a case-insensitive collation, as MySQL's default ones do. */
function ab_collates( $a, $b ) { return strtolower( (string) $a ) === strtolower( (string) $b ); }
/* is_protected_meta(): "_" keys, and those a plugin's filter marks as protected. */
$GLOBALS['ab_protected'] = array();
function is_protected_meta( $key, $type = '' ) { return '_' === substr( (string) $key, 0, 1 ) || in_array( (string) $key, $GLOBALS['ab_protected'], true ); }
function ab_post( $id, $over = array() ) {
	$GLOBALS['ab_posts'][ $id ] = (object) array_merge(
		array( 'ID' => $id, 'post_title' => 'Old title', 'post_content' => '', 'post_excerpt' => 'Old caption', 'post_status' => 'publish', 'post_name' => 'p' . $id, 'post_parent' => 0, 'post_author' => '1', 'post_type' => 'post', 'post_date' => '2001-01-01 10:00:00', 'post_date_gmt' => '2001-01-01 09:00:00', 'post_password' => '' ),
		$over
	);
}
$GLOBALS['wpdb'] = new class {
	public $posts       = 'wp_posts';
	public $options     = 'wp_options';
	public $postmeta    = 'wp_postmeta';
	public $termmeta    = 'wp_termmeta';
	public $usermeta    = 'wp_usermeta';
	public $last_error  = '';
	public $last_result = array();
	public $writes      = array();
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $a ) {
			$sql = preg_replace_callback( '/%[ds]/', static function ( $m ) use ( $a ) { return '%d' === $m[0] ? (string) (int) $a : "'" . addslashes( (string) $a ) . "'"; }, $sql, 1 );
		}
		return $sql;
	}
	public function query( $sql ) {
		$this->last_result = array();
		$this->last_error  = '';
		if ( preg_match( "/^SELECT (\\w+) AS meta_id, meta_key, meta_value FROM (\\w+) WHERE (\\w+) = (\\d+) AND meta_key = '(.*)' ORDER BY (\\w+) ASC$/s", $sql, $m ) ) {
			if ( $GLOBALS['ab_db_down'] ) { return false; } // a connection that went away: false, last_error empty
			$t = AB_TABLES[ $m[2] ];
			if ( $t[1] !== $m[3] || $t[2] !== $m[1] || $t[2] !== $m[6] ) { $this->last_error = 'Unknown column'; return false; }
			foreach ( $GLOBALS['ab_meta'][ $t[0] ] as $id => $r ) {
				if ( $r[0] === (int) $m[4] && ab_collates( $r[1], stripslashes( $m[5] ) ) ) {
					$this->last_result[] = (object) array( 'meta_id' => (string) $id, 'meta_key' => $r[1], 'meta_value' => $r[2] ); // MySQL hands back strings
				}
			}
			return count( $this->last_result );
		}
		if ( preg_match( '/^SELECT ([a-z_, ]+) FROM wp_posts WHERE ID = (\d+)$/', $sql, $m ) ) {
			$row = $this->get_row( $sql, ARRAY_A );
			if ( $row ) { $this->last_result = array( (object) $row ); }
			return count( $this->last_result );
		}
		return false;
	}
	public function get_row( $sql, $output = OBJECT ) {
		if ( ! preg_match( '/^SELECT ([a-z_, ]+) FROM wp_posts WHERE ID = (\d+)$/', $sql, $m ) ) { return null; }
		$p = $GLOBALS['ab_posts'][ (int) $m[2] ] ?? null;
		if ( ! $p ) { return null; }
		$row = array();
		foreach ( array_map( 'trim', explode( ',', $m[1] ) ) as $c ) { $row[ $c ] = (string) $p->$c; }
		return $row;
	}
	public function get_var( $sql ) {
		if ( preg_match( '/^SELECT (\w+) FROM (\w+) WHERE \1 = (\d+)$/', $sql, $m ) ) {
			return isset( $GLOBALS['ab_meta'][ AB_TABLES[ $m[2] ][0] ][ (int) $m[3] ] ) ? $m[3] : null;
		}
		return null;
	}
	public function insert( $table, $data ) {
		if ( $GLOBALS['ab_db_nowrite'] ) { return false; }
		$t = AB_TABLES[ $table ];
		$this->writes[] = array( 'insert', $table, $data );
		ab_add_row( $t[0], $data[ $t[1] ], $data['meta_key'], $data['meta_value'], $data[ $t[2] ] ?? null );
		return 1;
	}
	public function update( $table, $data, $where ) {
		if ( $GLOBALS['ab_db_nowrite'] ) { return false; }
		$t = AB_TABLES[ $table ];
		$this->writes[] = array( 'update', $table, $data, $where );
		$id = (int) $where[ $t[2] ];
		if ( ! isset( $GLOBALS['ab_meta'][ $t[0] ][ $id ] ) ) { return 0; }
		if ( $GLOBALS['ab_db_lies'] ) { return 1; }
		$GLOBALS['ab_meta'][ $t[0] ][ $id ][2] = (string) $data['meta_value'];
		if ( isset( $data['meta_key'] ) ) { $GLOBALS['ab_meta'][ $t[0] ][ $id ][1] = (string) $data['meta_key']; }
		return 1;
	}
	public function delete( $table, $where ) {
		if ( $GLOBALS['ab_db_nowrite'] ) { return false; }
		$t = AB_TABLES[ $table ];
		$this->writes[] = array( 'delete', $table, $where );
		$n = 0;
		foreach ( $GLOBALS['ab_meta'][ $t[0] ] as $id => $r ) {
			if ( ( isset( $where[ $t[2] ] ) && (int) $where[ $t[2] ] === $id ) || ( isset( $where[ $t[1] ] ) && (int) $where[ $t[1] ] === $r[0] && ab_collates( $where['meta_key'], $r[1] ) ) ) {
				unset( $GLOBALS['ab_meta'][ $t[0] ][ $id ] );
				++$n;
			}
		}
		return $n;
	}
};

/* update_metadata() as WordPress runs it without a previous value: unslash, the
 * sanitize filter, serialize; every row of the key takes the value, a key
 * without a row gets one. A key in ab_meta_veto is kept from being stored, as
 * an update_{type}_metadata filter can. */
$GLOBALS['ab_meta_veto']     = array();
$GLOBALS['ab_meta_sanitize'] = array();
function ab_update_meta( $type, $obj, $key, $value ) {
	$key   = wp_unslash( $key );
	$value = wp_unslash( $value );
	if ( isset( $GLOBALS['ab_meta_veto'][ $key ] ) ) { return false; }
	if ( isset( $GLOBALS['ab_meta_sanitize'][ $key ] ) ) { $value = $GLOBALS['ab_meta_sanitize'][ $key ]( $value ); }
	$stored = maybe_serialize( $value );
	$stored = is_bool( $stored ) ? ( $stored ? '1' : '' ) : ( null === $stored ? '' : (string) $stored );
	// WHERE meta_key = …, under the collation: "Mixed" is updated with "mixed".
	$rows = array();
	foreach ( $GLOBALS['ab_meta'][ $type ] as $id => $r ) {
		if ( $r[0] === (int) $obj && ab_collates( $r[1], $key ) ) { $rows[] = $id; }
	}
	if ( array() === $rows ) {
		ab_add_row( $type, $obj, $key, $stored );
		return true;
	}
	foreach ( $rows as $id ) { $GLOBALS['ab_meta'][ $type ][ $id ][2] = $stored; }
	return true;
}
function ab_get_meta( $type, $obj, $key, $single ) {
	$values = array_values( array_map( 'maybe_unserialize', ab_rows( $type, $obj, $key ) ) );
	return $single ? ( $values ? $values[0] : '' ) : $values;
}
function update_post_meta( $id, $key, $value, $prev = '' ) { return ab_update_meta( 'post', $id, $key, $value ); }
function update_term_meta( $id, $key, $value, $prev = '' ) { return ab_update_meta( 'term', $id, $key, $value ); }
function update_user_meta( $id, $key, $value, $prev = '' ) { return ab_update_meta( 'user', $id, $key, $value ); }
function get_post_meta( $id, $key = '', $single = false ) { return ab_get_meta( 'post', $id, $key, $single ); }
function get_term_meta( $id, $key = '', $single = false ) { return ab_get_meta( 'term', $id, $key, $single ); }
function get_user_meta( $id, $key = '', $single = false ) { return ab_get_meta( 'user', $id, $key, $single ); }
function delete_post_meta( $id, $key, $value = '' ) { foreach ( array_keys( ab_rows( 'post', $id, $key ) ) as $mid ) { unset( $GLOBALS['ab_meta']['post'][ $mid ] ); } return true; }
function add_post_meta( $id, $key, $value, $unique = false ) { ab_add_row( 'post', $id, $key, maybe_serialize( $value ) ); return true; }

function get_post( $id ) { return $GLOBALS['ab_posts'][ (int) $id ] ?? null; }
function get_post_type( $id ) { $p = get_post( $id ); return $p ? $p->post_type : false; }
function get_post_type_object( $t ) { return (object) array( 'cap' => (object) array( 'publish_posts' => 'publish_posts', 'edit_others_posts' => 'edit_others_posts' ) ); }
function get_post_status_object( $s ) { return (object) array( 'name' => $s, 'public' => 'publish' === $s, 'private' => 'private' === $s, 'publicly_queryable' => false ); }
function get_post_stati( $args = array(), $output = 'names' ) { $k = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'auto-draft', 'inherit' ); if ( ! empty( $args['date_floating'] ) ) { $k = array( 'draft', 'pending', 'auto-draft' ); } return array_combine( $k, $k ); }
function get_gmt_from_date( $d ) { return $d; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
$GLOBALS['ab_revisions'] = array(); // revision id => its post
function wp_is_post_revision( $p ) { return $GLOBALS['ab_revisions'][ (int) $p ] ?? false; }
/* wp_update_post: the fields as given (unslashed), unless a filter rewrites the title (kses for an account without unfiltered_html) or the save is vetoed. */
$GLOBALS['ab_title_filter'] = null;
$GLOBALS['ab_save_veto']    = false;
function wp_update_post( $arr, $wp_error = false ) {
	if ( $GLOBALS['ab_save_veto'] ) { return 0; }
	$arr = wp_unslash( $arr );
	$p   = $GLOBALS['ab_posts'][ (int) $arr['ID'] ] ?? null;
	if ( ! $p ) { return 0; }
	foreach ( $arr as $k => $v ) {
		if ( 'ID' === $k || 'edit_date' === $k ) { continue; }
		$p->$k = ( 'post_title' === $k && null !== $GLOBALS['ab_title_filter'] ) ? $GLOBALS['ab_title_filter']( $v ) : $v;
	}
	return (int) $arr['ID'];
}
function get_term( $id, $tax = '' ) { return $GLOBALS['ab_terms'][ (int) $id ] ?? null; }
function get_taxonomy( $tax ) { return (object) array( 'cap' => (object) array( 'assign_terms' => 'assign_categories', 'manage_terms' => 'manage_categories' ) ); }
function get_user_by( $field, $id ) { return isset( $GLOBALS['ab_users'][ (int) $id ] ) ? (object) array( 'ID' => (int) $id ) : false; }

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
/* Page-builder data takes unfiltered_html (the real rule is AB_MCP_Builders::markup_meta_refusal()). */
class AB_MCP_Builders {
	public static function markup_meta_refusal( $key, $post = null ) {
		return ( 'panels_data' === $key && ! current_user_can( 'unfiltered_html' ) ) ? new WP_Error( 'ab_mcp_needs_unfiltered_html', 'Connect with such an account.' ) : null;
	}
}
/* wp_update_media asks the parent rule of wp_update_post; files here keep their parent. */
class AB_MCP_Tools_Content {
	public static function file_publish_refusal( $id, $status_now, $parent_now, $status, $parent ) { return null; }
}

require $GLOBALS['ab_free'] . 'class-tool-registry.php';
require $GLOBALS['ab_free'] . 'class-guidance.php';
require $GLOBALS['ab_free'] . 'class-post-status.php';
require $GLOBALS['ab_free'] . 'class-page-cache.php';
require $GLOBALS['ab_free'] . 'tools/class-tools-base.php';
require $GLOBALS['ab_free'] . 'tools/class-tools-meta-auth.php';
require $GLOBALS['ab_free'] . 'tools/class-tools-media.php';
require $GLOBALS['ab_free'] . 'tools/class-tools-undo-core.php';
require $GLOBALS['ab_free'] . 'undo/class-undo.php';
require $GLOBALS['ab_free'] . 'undo/class-undo-restore.php';
require $GLOBALS['ab_free'] . 'undo/class-undo-meta.php';

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
/* The meta tool wp_update_post_meta, as its handler calls the shared write (the handler itself is tested in tests/FreeSinceFourNineUndoTest.php). */
function ab_post_meta( $id, $key, $value ) {
	return AB_MCP_Undo_Meta::write(
		array(
			'tool'    => 'wp_update_post_meta',
			'type'    => 'post',
			'id'      => $id,
			'keys'    => array( $key => AB_MCP_Undo_Answer::stored_form( $value ) ),
			'reader'  => 'wp_get_post_meta',
			'summary' => sprintf( '#%d: %s', $id, $key ),
		),
		static function () use ( $id, $key, $value ) {
			update_post_meta( $id, $key, wp_slash( $value ) );
		}
	);
}
function reset_all() {
	$GLOBALS['ab_meta']          = array( 'post' => array(), 'term' => array(), 'user' => array() );
	$GLOBALS['ab_next_id']       = 100;
	$GLOBALS['ab_opts']          = array();
	$GLOBALS['ab_filters']       = array();
	$GLOBALS['ab_deny']          = array();
	$GLOBALS['ab_db_down']       = false;
	$GLOBALS['ab_db_nowrite']    = false;
	$GLOBALS['ab_db_lies']       = false;
	$GLOBALS['ab_protected']     = array();
	$GLOBALS['ab_revisions']     = array();
	$GLOBALS['ab_meta_veto']     = array();
	$GLOBALS['ab_meta_sanitize'] = array();
	$GLOBALS['ab_title_filter']  = null;
	$GLOBALS['ab_save_veto']     = false;
	$GLOBALS['ab_cleaned']       = array();
	$GLOBALS['ab_last_changed']  = array();
	$GLOBALS['ab_posts']         = array();
	$GLOBALS['ab_terms']         = array( 8 => (object) array( 'term_id' => 8, 'taxonomy' => 'category' ) );
	$GLOBALS['ab_users']         = array( 5 => true );
	ab_post( 5 );
	ab_post( 9, array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );
}

/* ============================== A) a write records one point with every row */

reset_all();
$r1 = ab_add_row( 'post', 5, 'farbe', 'rot' );
$r2 = ab_add_row( 'post', 5, 'farbe', 'GEHEIM-rot-2' );
ab_add_row( 'post', 6, 'farbe', 'other post' );
$res = ab_post_meta( 5, 'farbe', 'blau' );
ok( is_array( $res ) && AB_MCP_Undo::valid_token( (string) $res['undo'] ) && ! isset( $res['undo_note'] ), 'A1 the write answers with a token and no note' );
ok( array( 'farbe' => 'blau' ) === (array) $res['saved_fields'] && ! isset( $res['saved_differs'] ) && ! isset( $res['warning'] ), 'A2 saved_fields: what is stored, read again, as asked' );
ok( array( $r1 => 'blau', $r2 => 'blau' ) === ab_rows( 'post', 5, 'farbe' ), 'A3 the write itself (every row of the key takes the value)' );
$claim = AB_MCP_Undo::claim( (string) $res['undo'] );
$e     = $claim['entry'];
ok( 'wp_update_post_meta' === $e['tool'] && 'meta' === $e['kind'] && 'edit_posts' === $e['cap'] && 1 === (int) $e['items'], 'A4 one item, kind meta, with the capability of the tool' );
ok( '#5: farbe' === $e['summary'], 'A5 the summary names the post and the key' );
ok( null !== $e['inline'] && null === $e['payload'], 'A6 a small snapshot stays inline in the index (meta is a database kind)' );
$d = $claim['data'];
ok( 1 === $d['v'] && 'post' === $d['type'] && 5 === $d['id'], 'A7 the snapshot names the object' );
ok( array( 'existed' => true, 'rows' => array( array( 'meta_id' => $r1, 'value' => 'rot' ), array( 'meta_id' => $r2, 'value' => 'GEHEIM-rot-2' ) ) ) === $d['keys']['farbe'], 'A8 both rows of the key, oldest first, with their ids' );
ok( array( 'meta:farbe' ) === array_keys( $d['after'] ) && 1 === preg_match( '/\A[0-9a-f]{64}\z/', $d['after']['meta:farbe'] ), 'A9 the state after the change, as a hash' );
ok( ! isset( $d['keys']['farbe']['rows'][2] ) && array( 'other post' ) === array_values( ab_rows( 'post', 6, 'farbe' ) ), 'A10 the rows of another post are neither kept nor touched' );

/* ============================== B) the way back, byte for byte */

$out = ab_undo( $res['undo'] );
ok( ! is_wp_error( $out ) && 1 === $out['undone'] && true === $out['consumed'] && 'meta' === $out['kind'], 'B1 wp_undo restores the point and uses it up' );
ok( array( $r1 => 'rot', $r2 => 'GEHEIM-rot-2' ) === ab_rows( 'post', 5, 'farbe' ), 'B2 both rows back, under their ids, in their order' );
ok( in_array( 'post_meta:5', $GLOBALS['ab_cache_deleted'], true ) && in_array( 5, $GLOBALS['ab_cleaned'], true ) && in_array( 'posts', $GLOBALS['ab_last_changed'], true ), 'B3 the meta cache of the post and the last-changed of posts are reset' );
ok( isset( $out['page_cache_purged'] ), 'B4 the page caches are asked' );

reset_all();
$res = ab_post_meta( 5, 'neu', array( 'a' => 1, 'b' => array( true, null, 1.5 ) ) );
ok( array( 'neu' => array( 'a' => 1, 'b' => array( true, null, 1.5 ) ) ) === (array) $res['saved_fields'], 'B5 a list comes back with its types' );
ok( array( 'existed' => false ) === AB_MCP_Undo::claim( (string) $res['undo'] )['data']['keys']['neu'], 'B6 a key the change creates is kept as missing' );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && array() === ab_rows( 'post', 5, 'neu' ), 'B7 and the undo removes it again' );

reset_all();
$ser = serialize( array( 'x' => 'y' ) ); // phpcs:ignore
$bin = "\xff\xfe raw";
$m1  = ab_add_row( 'post', 5, 'mix', $ser );
$m2  = ab_add_row( 'post', 5, 'mix', $bin );
$res = ab_post_meta( 5, 'mix', 'plain' );
$st  = AB_MCP_Undo::claim( (string) $res['undo'] )['data']['keys']['mix'];
ok( isset( $st['rows'][1]['b64'] ) && ! isset( $st['rows'][1]['value'] ) && $ser === $st['rows'][0]['value'], 'B8 bytes that are no UTF-8 are kept as base64, a serialized value as it is' );
ab_undo( $res['undo'] );
ok( array( $m1 => $ser, $m2 => $bin ) === ab_rows( 'post', 5, 'mix' ), 'B9 both come back byte for byte, not serialized again' );

reset_all();
$k1  = ab_add_row( 'post', 5, 'liste', 'a' );
$res = ab_post_meta( 5, 'liste', 'b' );
$k2  = ab_add_row( 'post', 5, 'liste', 'b' ); // a second row as the change left it would read as changed; force covers it below
unset( $GLOBALS['ab_meta']['post'][ $k2 ] );
ab_add_row( 'post', 5, 'liste', 'c', 50 ); // the id of the snapshot is free, so the restore keeps it; an added row goes
$GLOBALS['ab_meta']['post'][ $k1 ][2] = 'b';
$out = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 1 === $out['undone'] && array( $k1 => 'a' ) === ab_rows( 'post', 5, 'liste' ), 'B10 with force, the rows a later change added go and the old row is back under its id' );

reset_all();
$o1  = ab_add_row( 'post', 5, 'ord', 'one' );
$o2  = ab_add_row( 'post', 5, 'ord', 'two' );
$res = ab_post_meta( 5, 'ord', 'x' );
// Both rows deleted and the key written anew, and the old id handed out to another key since.
foreach ( array( $o1, $o2 ) as $gone ) { unset( $GLOBALS['ab_meta']['post'][ $gone ] ); }
ab_add_row( 'post', 5, 'other', 'keeps its row', $o1 );
ab_add_row( 'post', 5, 'ord', 'x' );
$out = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 1 === $out['undone'] && array( 'one', 'two' ) === array_values( ab_rows( 'post', 5, 'ord' ) ), 'B11 an id taken by another row: the values come back in their order under other ids (force: a key written anew counts as changed)' );
ok( array( $o1 => 'keeps its row' ) === ab_rows( 'post', 5, 'other' ), 'B12 and the row that holds the id now is left alone' );

reset_all();
$i1  = ab_add_row( 'post', 5, 'paar', 'erste' );
$i2  = ab_add_row( 'post', 5, 'paar', 'zweite' );
$res = ab_post_meta( 5, 'paar', 'neu' );
unset( $GLOBALS['ab_meta']['post'][ $i2 ] ); // deleted since; its id stays free
$out = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 1 === $out['undone'] && array( $i1 => 'erste', $i2 => 'zweite' ) === ab_rows( 'post', 5, 'paar' ), 'B13 a row deleted since comes back under its own id, which is free' );

/* ============================== C) a change since is not thrown away */

reset_all();
ab_add_row( 'post', 5, 'farbe', 'rot' );
$res = ab_post_meta( 5, 'farbe', 'blau' );
ab_update_meta( 'post', 5, 'farbe', 'gruen' );
$dry = ab_undo( $res['undo'], array( 'dry_run' => true ) );
ok( 0 === $dry['would_undo'] && 'changed_since' === $dry['blocked'][0]['reason'] && array( 'meta:farbe' ) === $dry['blocked'][0]['locs'] && 5 === $dry['blocked'][0]['ref'], 'C1 the dry run names the key changed since, blocked' );
$out = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && false === $out['consumed'] && array( 'gruen' ) === array_values( ab_rows( 'post', 5, 'farbe' ) ), 'C2 without force nothing is written and the point stays' );
ok( null !== ab_entry( $res['undo'] ), 'C3 ... the point is still in the index' );
$out = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 1 === $out['undone'] && true === $out['consumed'] && array( 'rot' ) === array_values( ab_rows( 'post', 5, 'farbe' ) ), 'C4 with force the old value comes back over the newer one' );

reset_all();
ab_add_row( 'post', 5, 'farbe', 'rot' );
$res = ab_post_meta( 5, 'farbe', 'blau' );
ab_update_meta( 'post', 5, 'farbe', 'rot' );
$n   = count( $GLOBALS['wpdb']->writes );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && $n === count( $GLOBALS['wpdb']->writes ), 'C5 a key that holds its old state again: restored without a write' );

reset_all();
ab_add_row( 'post', 5, 'farbe', 'rot' );
$res  = ab_post_meta( 5, 'farbe', 'blau' );
$c    = AB_MCP_Undo::claim( (string) $res['undo'] );
$plan = AB_MCP_Undo_Restore::plan( $c['entry'], $c['data'] );
ab_update_meta( 'post', 5, 'farbe', 'gelb' ); // between the plan and the write
$item = AB_MCP_Undo_Restore::apply( $c['entry'], $c['data'] );
ok( 0 === $item['undone'] && 'changed_since' === $item['blocked'][0]['reason'], 'C6 apply plans again and sees the change' );
class AB_Test_Meta_Restore extends AB_MCP_Undo_Restore {
	public static function item( $item ) { return static::restore_meta( $item ); }
}
$again = AB_Test_Meta_Restore::item( $plan['items'][0] );
ok( 'ab_mcp_undo_meta_changed' === ab_code( $again ) && array( 'gelb' ) === array_values( ab_rows( 'post', 5, 'farbe' ) ), 'C7 the write compares once more right before it writes' );
ok( false !== strpos( ab_msg( $again ), 'force=true' ), 'C8 ... and names force=true as the way' );

/* ============================== D) rights and key rules, asked again */

reset_all();
ab_add_row( 'post', 5, 'farbe', 'rot' );
$res = ab_post_meta( 5, 'farbe', 'blau' );
$GLOBALS['ab_deny']['edit_posts'] = true;
ok( 'ab_mcp_undo_forbidden' === ab_code( ab_undo( $res['undo'] ) ), 'D1 the capability of the tool (edit_posts) is asked again' );
unset( $GLOBALS['ab_deny']['edit_posts'] );
$GLOBALS['ab_deny']['edit_post:5'] = true;
$out = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && 'forbidden' === $out['blocked'][0]['reason'] && array( 'blau' ) === array_values( ab_rows( 'post', 5, 'farbe' ) ), 'D2 the right to edit the post' );
$GLOBALS['ab_deny'] = array( 'edit_post_meta:5:farbe' => true );
ok( 'forbidden' === ab_undo( $res['undo'] )['blocked'][0]['reason'], 'D3 the right to edit the key (an auth callback of register_meta())' );
$GLOBALS['ab_deny'] = array();
unset( $GLOBALS['ab_posts'][5] );
ok( 'gone' === ab_undo( $res['undo'] )['blocked'][0]['reason'], 'D4 a post that is gone' );

reset_all();
ab_add_row( 'post', 5, 'panels_data', 'old layout' );
$res = ab_post_meta( 5, 'panels_data', 'new layout' );
$GLOBALS['ab_deny']['unfiltered_html'] = true;
ok( 'forbidden' === ab_undo( $res['undo'] )['blocked'][0]['reason'], 'D5 page-builder data takes unfiltered_html on the way back too' );

/* A hand-edited point that names a key the tool refuses: blocked, never written. */
function ab_forge( $tool, $type, $id, $key, $existed = true ) {
	$state = $existed ? array( 'existed' => true, 'rows' => array( array( 'meta_id' => 900, 'value' => 'a:1:{s:13:"administrator";b:1;}' ) ) ) : array( 'existed' => false );
	$data  = array( 'v' => 1, 'type' => $type, 'id' => $id, 'keys' => array( $key => $state ), 'after' => array( 'meta:' . $key => str_repeat( 'a', 64 ) ) );
	return AB_MCP_Undo::record( array( 'tool' => $tool, 'cap' => 'x', 'kind' => 'meta', 'summary' => 'forged', 'items' => 1, 'data' => $data ) );
}
reset_all();
foreach ( array( array( 'wp_update_user_meta', 'user', 5, 'wp_capabilities' ), array( 'wp_update_user_meta', 'user', 5, 'session_tokens' ), array( 'wp_update_post_meta', 'post', 5, '_edit_lock' ), array( 'wp_update_post_meta', 'post', 5, 'api_key' ), array( 'wp_update_term_meta', 'term', 8, '_secret' ), array( 'wp_update_term_meta', 'term', 8, 'client_secret' ) ) as $f ) {
	$t   = ab_forge( $f[0], $f[1], $f[2], $f[3] );
	$out = ab_undo( $t, array( 'force' => true ) );
	ok( 0 === $out['undone'] && 'protected_now' === $out['blocked'][0]['reason'] && array() === ab_rows( $f[1], $f[2], $f[3] ), "D6 a key the tool refuses is not written back, even with force ({$f[3]})" );
}
$GLOBALS['ab_deny'] = array( 'edit_user:5' => true );
$out = ab_undo( ab_forge( 'wp_update_user_meta', 'user', 5, 'nickname' ) );
ok( 'forbidden' === $out['blocked'][0]['reason'], 'D7 user meta: the right to edit the user' );
$GLOBALS['ab_deny'] = array( 'edit_term_meta:8:color' => true );
ok( 'forbidden' === ab_undo( ab_forge( 'wp_update_term_meta', 'term', 8, 'color' ) )['blocked'][0]['reason'], 'D8 term meta: the right to edit the key of the term' );
$GLOBALS['ab_deny'] = array( 'edit_users' => true );
ok( 'ab_mcp_undo_forbidden' === ab_code( ab_undo( ab_forge( 'wp_update_user_meta', 'user', 5, 'nickname' ) ) ), 'D9 user meta: edit_users, the capability of the tool' );
$GLOBALS['ab_deny'] = array( 'manage_categories' => true );
ok( 'ab_mcp_undo_forbidden' === ab_code( ab_undo( ab_forge( 'wp_update_term_meta', 'term', 8, 'color' ) ) ), 'D10 term meta: manage_categories, the capability of the tool' );
$GLOBALS['ab_deny'] = array();
unset( $GLOBALS['ab_terms'][8], $GLOBALS['ab_users'][5] );
ok( 'gone' === ab_undo( ab_forge( 'wp_update_term_meta', 'term', 8, 'color' ) )['blocked'][0]['reason'] && 'gone' === ab_undo( ab_forge( 'wp_update_user_meta', 'user', 5, 'nickname' ) )['blocked'][0]['reason'], 'D11 a term or a user that is gone' );

/* ============================== E) the form: a hand-edited point is refused whole */

reset_all();
ab_add_row( 'post', 5, 'farbe', 'rot' );
$res  = ab_post_meta( 5, 'farbe', 'blau' );
$good = AB_MCP_Undo::claim( (string) $res['undo'] )['data'];
ok( true === AB_MCP_Undo_Meta::valid( 'wp_update_post_meta', $good ), 'E0 the snapshot the tool wrote has the form' );
$bad = array(
	'no after'          => array_diff_key( $good, array( 'after' => 1 ) ),
	'after of another'  => array_merge( $good, array( 'after' => array( 'meta:x' => str_repeat( 'a', 64 ) ) ) ),
	'after of one more' => array_merge( $good, array( 'after' => $good['after'] + array( 'meta:x' => str_repeat( 'a', 64 ) ) ) ),
	'after not a hash'  => array_merge( $good, array( 'after' => array( 'meta:farbe' => 'abc' ) ) ),
	'two keys'          => array_merge( $good, array( 'keys' => $good['keys'] + array( 'b' => array( 'existed' => false ) ), 'after' => $good['after'] + array( 'meta:b' => str_repeat( 'a', 64 ) ) ) ),
	'no key'            => array_merge( $good, array( 'keys' => array(), 'after' => array() ) ),
	'another type'      => array_merge( $good, array( 'type' => 'user' ) ),
	'version 2'         => array_merge( $good, array( 'v' => 2 ) ),
	'id 0'              => array_merge( $good, array( 'id' => 0 ) ),
	'id as text'        => array_merge( $good, array( 'id' => '05' ) ),
	'fields'            => array_merge( $good, array( 'fields' => array( 'post_title' => 'x' ), 'after' => $good['after'] + array( 'field:post_title' => str_repeat( 'a', 64 ) ) ) ),
	'id not a number'   => array_merge( $good, array( 'keys' => array( 'farbe' => array( 'existed' => true, 'rows' => array( array( 'meta_id' => '100', 'value' => 'rot' ) ) ) ) ) ),
	'value and b64'     => array_merge( $good, array( 'keys' => array( 'farbe' => array( 'existed' => true, 'rows' => array( array( 'meta_id' => 100, 'value' => 'rot', 'b64' => 'cm90' ) ) ) ) ) ),
	'existed, no rows'  => array_merge( $good, array( 'keys' => array( 'farbe' => array( 'existed' => true, 'rows' => array() ) ) ) ),
	'missing with rows' => array_merge( $good, array( 'keys' => array( 'farbe' => array( 'existed' => false, 'rows' => array( array( 'meta_id' => 1, 'value' => 'x' ) ) ) ) ) ),
	'twice the id'      => array_merge( $good, array( 'keys' => array( 'farbe' => array( 'existed' => true, 'rows' => array( array( 'meta_id' => 1, 'value' => 'x' ), array( 'meta_id' => 1, 'value' => 'y' ) ) ) ) ) ),
	'existed as 1'      => array_merge( $good, array( 'keys' => array( 'farbe' => array( 'existed' => 1, 'rows' => array( array( 'meta_id' => 1, 'value' => 'x' ) ) ) ) ) ),
);
foreach ( $bad as $why => $data ) {
	ok( false === AB_MCP_Undo_Meta::valid( 'wp_update_post_meta', $data ) && 'ab_mcp_undo_corrupt' === ab_code( AB_MCP_Undo_Meta::plan( 'wp_update_post_meta', $data, true ) ), "E1 refused whole: $why" );
}
ok( false === AB_MCP_Undo_Meta::valid( 'wp_update_media', array_merge( $good, array( 'keys' => array( 'farbe' => $good['keys']['farbe'] ) ) ) ), 'E2 wp_update_media: no key but the alternative text' );
ok( false === AB_MCP_Undo_Meta::valid( 'wp_update_media', array_merge( $good, array( 'keys' => array(), 'after' => array( 'field:post_content' => str_repeat( 'a', 64 ) ), 'fields' => array( 'post_content' => 'x' ) ) ) ), 'E3 wp_update_media: no column but title and caption' );
ok( false === AB_MCP_Undo_Meta::valid( 'wp_made_up', $good ), 'E4 a tool that records no meta point' );
$num = array( 'v' => 1, 'type' => 'post', 'id' => 5, 'keys' => array( 2024 => array( 'existed' => false ) ), 'after' => array( 'meta:2024' => str_repeat( 'a', 64 ) ) );
ok( true === AB_MCP_Undo_Meta::valid( 'wp_update_post_meta', json_decode( wp_json_encode( $num ), true ) ), 'E5 a key of digits, as JSON hands it back, has the form' );
$entry = ab_entry( $res['undo'] );
ok( 'ab_mcp_undo_kind_mismatch' === ab_code( AB_MCP_Undo_Restore::plan( array_merge( $entry, array( 'kind' => 'post_fields' ) ), $good ) ), 'E6 a meta tool with another kind is refused' );

/* ============================== F) why there is no undo point */

reset_all();
$GLOBALS['ab_toolstate']['wp_undo'] = false;
ab_add_row( 'post', 5, 'farbe', 'rot' );
$res = ab_post_meta( 5, 'farbe', 'blau' );
ok( null === $res['undo'] && false !== strpos( $res['undo_note'], 'Undo is switched off on this site' ) && false !== strpos( $res['undo_note'], 'wp_undo' ), 'F1 undo switched off: the note says so and names the switch' );
ok( array( 'blau' ) === array_values( ab_rows( 'post', 5, 'farbe' ) ) && array() === (array) get_option( AB_MCP_Undo::OPTION, array() ), 'F2 the write happens, nothing is kept' );
$GLOBALS['ab_toolstate'] = array();

reset_all();
$GLOBALS['ab_filters']['ab_mcp_undo_snapshot_max'] = 200;
$GLOBALS['ab_filters']['ab_mcp_undo_inline_max']   = 100;
ab_add_row( 'post', 5, 'gross', bin2hex( random_bytes( 400 ) ) );
$res = ab_post_meta( 5, 'gross', 'klein' );
ok( null === $res['undo'] && false !== strpos( $res['undo_note'], 'ab_mcp_undo_snapshot_max' ) && false !== strpos( $res['undo_note'], 'no undo point was recorded' ), 'F3 a value too large: the note gives the limit and the filter' );
ok( 0 === strpos( $res['undo_note'], 'The snapshot takes ' ), 'F3 ... in the words of the undo store, not wrapped in another sentence' );
ok( array( 'klein' ) === array_values( ab_rows( 'post', 5, 'gross' ) ) && array( 'gross' => 'klein' ) === (array) $res['saved_fields'], 'F4 the write still happens and is read back' );
$GLOBALS['ab_filters'] = array();
$GLOBALS['ab_filters']['ab_mcp_undo_inline_max'] = 100;
ab_add_row( 'post', 5, 'mittel', str_repeat( 'x', 300 ) );
$res = ab_post_meta( 5, 'mittel', 'y' );
$e   = ab_entry( (string) $res['undo'] );
ok( null !== $e && null === $e['inline'] && null !== $e['payload'], 'F5 over the inline limit, the snapshot goes to a file' );
ok( 1 === ab_undo( $res['undo'] )['undone'] && array( str_repeat( 'x', 300 ) ) === array_values( ab_rows( 'post', 5, 'mittel' ) ), 'F6 ... and comes back from it' );

reset_all();
ab_add_row( 'post', 5, 'farbe', 'blau' );
$res = ab_post_meta( 5, 'farbe', 'blau' );
ok( null === $res['undo'] && false !== strpos( $res['undo_note'], 'nothing changed' ) && false !== strpos( $res['undo_note'], 'wp_get_post_meta' ) && array() === (array) get_option( AB_MCP_Undo::OPTION, array() ), 'F7 the value it held already: no point, and the note says why' );

reset_all();
$GLOBALS['ab_db_down'] = true;
$res = ab_post_meta( 5, 'farbe', 'blau' );
ok( true === is_array( $res ) && null === $res['undo'] && false !== strpos( $res['undo_note'], 'could not be read from the database' ), 'F8 a table that could not be read: no point on a guess, the note says why' );
ok( array( 'blau' ) === array_values( ab_rows( 'post', 5, 'farbe' ) ) && array( 'farbe' => 'blau' ) === (array) $res['saved_fields'], 'F9 the write and the read-back through WordPress still happen' );


/* ============================== G) saved_fields */

reset_all();
$GLOBALS['ab_meta_veto']['farbe'] = true;
ab_add_row( 'post', 5, 'farbe', 'rot' );
$res = ab_post_meta( 5, 'farbe', 'blau' );
ok( 'ab_mcp_not_saved' === ab_code( $res ) && false !== strpos( ab_msg( $res ), 'farbe' ) && false !== strpos( ab_msg( $res ), 'wp_get_post_meta' ), 'G1 a write the site did not store is an error, not updated: true' );
ok( array() === (array) get_option( AB_MCP_Undo::OPTION, array() ), 'G2 ... and leaves no point' );

reset_all();
$GLOBALS['ab_meta_sanitize']['farbe'] = static fn( $v ) => strtoupper( (string) $v );
$res = ab_post_meta( 5, 'farbe', 'blau' );
ok( array( 'farbe' => 'BLAU' ) === (array) $res['saved_fields'] && array( 'farbe' ) === $res['saved_differs'], 'G3 a value the site rewrote: saved_fields shows it, saved_differs names it' );
ok( false !== strpos( $res['warning'], 'farbe' ) && false !== strpos( $res['warning'], 'wp_get_post_meta' ), 'G4 ... with a warning that names the reader' );
ok( AB_MCP_Undo::valid_token( (string) $res['undo'] ) && 1 === ab_undo( $res['undo'] )['undone'] && array() === ab_rows( 'post', 5, 'farbe' ), 'G5 ... and since something changed, an undo point that takes it back' );

reset_all();
foreach ( array( array( 7, '7' ), array( true, '1' ), array( false, '' ), array( null, '' ), array( 1.5, '1.5' ), array( 'a:1:{i:0;i:1;}', 'a:1:{i:0;i:1;}' ) ) as $case ) {
	$res = ab_post_meta( 5, 'typ', $case[0] );
	ok( is_array( $res ) && array( 'typ' => $case[1] ) === (array) $res['saved_fields'] && ! isset( $res['saved_differs'] ), 'G6 stored as WordPress stores it, and that is no difference: ' . var_export( $case[0], true ) );
}
$long = str_repeat( 'L', AB_MCP_Undo_Answer::SHOW_MAX + 1 );
$res  = ab_post_meta( 5, 'lang', $long );
ok( array() === (array) $res['saved_fields'] && array( 'lang' => 'too_long' ) === $res['saved_fields_omitted'] && ! isset( $res['saved_differs'] ), 'G7 a long value is compared, not repeated' );
ab_add_row( 'post', 5, 'zwei', 'a' );
ab_add_row( 'post', 5, 'zwei', 'b' );
$GLOBALS['ab_meta_sanitize']['zwei'] = static function ( $v ) { return $v; };
$res = ab_post_meta( 5, 'zwei', 'c' );
ok( array( 'zwei' => 'c' ) === (array) $res['saved_fields'], 'G8 several rows that hold the same value read as that value' );

/* ============================== H) term and user meta, through their tools */

reset_all();
ab_add_row( 'term', 8, 'color', '#000' );
$res = AB_MCP_Tools_Meta_Auth::update_term_meta_tool( array( 'term_id' => 8, 'key' => 'Color', 'value' => '#c00' ) );
ok( true === $res['updated'] && 8 === $res['term_id'] && 'color' === $res['key'] && array( 'color' => '#c00' ) === (array) $res['saved_fields'], 'H1 wp_update_term_meta answers with the read-back' );
$c = AB_MCP_Undo::claim( (string) $res['undo'] );
ok( 'wp_update_term_meta' === $c['entry']['tool'] && 'manage_categories' === $c['entry']['cap'] && 'term' === $c['data']['type'] && 'term #8: color' === $c['entry']['summary'], 'H2 its point: term meta, manage_categories' );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && array( '#000' ) === array_values( ab_rows( 'term', 8, 'color' ) ) && array( 'term:8' ) === $out['restored'], 'H3 and wp_undo puts the term meta back' );
ok( in_array( 'term_meta:8', $GLOBALS['ab_cache_deleted'], true ) && in_array( 'terms', $GLOBALS['ab_last_changed'], true ), 'H4 the meta cache of the term is reset' );

reset_all();
$res = AB_MCP_Tools_Meta_Auth::update_user_meta_tool( array( 'user_id' => 5, 'key' => 'nickname', 'value' => 'Tom' ) );
ok( array( 'nickname' => 'Tom' ) === (array) $res['saved_fields'] && AB_MCP_Undo::valid_token( (string) $res['undo'] ), 'H5 wp_update_user_meta: a profile field shows its value' );
$c = AB_MCP_Undo::claim( (string) $res['undo'] );
ok( 'edit_users' === $c['entry']['cap'] && 'user' === $c['data']['type'], 'H6 its point: user meta, edit_users' );
ok( 1 === ab_undo( $res['undo'] )['undone'] && array() === ab_rows( 'user', 5, 'nickname' ), 'H7 and wp_undo removes the field it created' );
ab_add_row( 'user', 5, 'billing_phone', 'GEHEIM-0791234567' );
$GLOBALS['ab_meta_veto']['billing_phone'] = true;
$res = AB_MCP_Tools_Meta_Auth::update_user_meta_tool( array( 'user_id' => 5, 'key' => 'billing_phone', 'value' => 'x' ) );
ok( 'ab_mcp_not_saved' === ab_code( $res ) && false === strpos( ab_msg( $res ), 'GEHEIM' ), 'H8 a key outside the profile fields the site did not store: an error that does not show the value it kept' );
$GLOBALS['ab_meta_sanitize']['billing_phone'] = static fn( $v ) => $v . '-rewritten';
unset( $GLOBALS['ab_meta_veto']['billing_phone'] );
$res  = AB_MCP_Tools_Meta_Auth::update_user_meta_tool( array( 'user_id' => 5, 'key' => 'billing_phone', 'value' => 'x' ) );
$json = (string) wp_json_encode( $res );
ok( array() === (array) $res['saved_fields'] && array( 'billing_phone' => 'not_shown' ) === $res['saved_fields_omitted'] && array( 'billing_phone' ) === $res['saved_differs'] && false === strpos( $json, 'rewritten' ), 'H9 ... and a value the site rewrote is reported as different, not shown' );
$res = AB_MCP_Tools_Meta_Auth::update_user_meta_tool( array( 'user_id' => 5, 'key' => 'wp_capabilities', 'value' => array( 'administrator' => true ) ) );
ok( 'ab_mcp_protected_meta' === ab_code( $res ) && array() === ab_rows( 'user', 5, 'wp_capabilities' ), 'H10 the tool still refuses the role key before anything is read or written' );

/* ============================== I) wp_update_media: one point for the three */

reset_all();
ab_add_row( 'post', 9, '_wp_attachment_image_alt', 'Old alt' );
$res = AB_MCP_Tools_Media::update_media( array( 'id' => 9, 'title' => 'New title', 'caption' => 'New caption', 'alt' => '<b>New</b> alt' ) );
ok( true === $res['updated'] && 9 === $res['id'], 'I1 wp_update_media answers updated' );
ok( array( 'title' => 'New title', 'caption' => 'New caption', 'alt' => 'New alt' ) === (array) $res['saved_fields'] && ! isset( $res['saved_differs'] ), 'I2 saved_fields under the names of the tool, the alt text as plain text' );
$c = AB_MCP_Undo::claim( (string) $res['undo'] );
ok( 'wp_update_media' === $c['entry']['tool'] && 'upload_files' === $c['entry']['cap'] && array( 'post_title' => 'Old title', 'post_excerpt' => 'Old caption' ) === $c['data']['fields'] && isset( $c['data']['keys']['_wp_attachment_image_alt'] ), 'I3 one point holds title, caption and alt text' );
ok( 'media #9: title, caption, alt' === $c['entry']['summary'], 'I4 its summary names the fields' );
$out = ab_undo( $res['undo'] );
$p   = get_post( 9 );
ok( 1 === $out['undone'] && 'Old title' === $p->post_title && 'Old caption' === $p->post_excerpt && array( 'Old alt' ) === array_values( ab_rows( 'post', 9, '_wp_attachment_image_alt' ) ), 'I5 wp_undo puts all three back' );

reset_all();
$res = AB_MCP_Tools_Media::update_media( array( 'id' => 9, 'alt' => 'Only alt' ) );
$c   = AB_MCP_Undo::claim( (string) $res['undo'] );
ok( ! isset( $c['data']['fields'] ) && array( 'existed' => false ) === $c['data']['keys']['_wp_attachment_image_alt'], 'I6 the alt text alone: no columns in the point' );
ok( 1 === ab_undo( $res['undo'] )['undone'] && array() === ab_rows( 'post', 9, '_wp_attachment_image_alt' ), 'I7 and the alt text it created goes again' );

reset_all();
$res = AB_MCP_Tools_Media::update_media( array( 'id' => 9, 'title' => 'T2' ) );
get_post( 9 )->post_title = 'Somebody else';
$out = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && 'changed_since' === $out['blocked'][0]['reason'] && array( 'field:post_title' ) === $out['blocked'][0]['locs'] && 'Somebody else' === get_post( 9 )->post_title, 'I8 a title changed since is not thrown away' );
ok( 1 === ab_undo( $res['undo'], array( 'force' => true ) )['undone'] && 'Old title' === get_post( 9 )->post_title, 'I9 with force it is' );

reset_all();
$GLOBALS['ab_title_filter'] = static fn( $v ) => strip_tags( (string) $v );
$res = AB_MCP_Tools_Media::update_media( array( 'id' => 9, 'title' => '<i>Kursiv</i>' ) );
ok( array( 'title' => 'Kursiv' ) === (array) $res['saved_fields'] && array( 'title' ) === $res['saved_differs'] && AB_MCP_Undo::valid_token( (string) $res['undo'] ), 'I10 a title the site filtered: reported, and undoable' );
$GLOBALS['ab_save_veto'] = true;
$res = AB_MCP_Tools_Media::update_media( array( 'id' => 9, 'title' => 'X' ) );
ok( 'ab_mcp_update_failed' === ab_code( $res ), 'I11 a save WordPress refused is the error it was' );
$GLOBALS['ab_save_veto'] = false;

reset_all();
$res = AB_MCP_Tools_Media::update_media( array( 'id' => 9, 'title' => 'T3' ) );
$GLOBALS['ab_deny']['edit_post:9'] = true;
ok( 'forbidden' === ab_undo( $res['undo'] )['blocked'][0]['reason'] && 'T3' === get_post( 9 )->post_title, 'I12 the right to edit the file is asked again' );
$GLOBALS['ab_deny'] = array( 'upload_files' => true );
ok( 'ab_mcp_undo_forbidden' === ab_code( ab_undo( $res['undo'] ) ), 'I13 and upload_files, the capability of the tool' );
$GLOBALS['ab_deny'] = array();
$GLOBALS['ab_posts'][9]->post_type = 'post';
ok( 'gone' === ab_undo( $res['undo'] )['blocked'][0]['reason'], 'I14 an id that is no file any more' );

/* ============================== J) answers about a point show no value */

reset_all();
ab_add_row( 'post', 5, 'notiz', 'GEHEIM-alt' );
$res  = ab_post_meta( 5, 'notiz', 'neu' );
$list = AB_MCP_Tools_Undo_Core::undo_list( array() );
$dry  = ab_undo( $res['undo'], array( 'dry_run' => true ) );
ok( 1 === $list['count'] && false === strpos( (string) wp_json_encode( $list ), 'GEHEIM' ), 'J1 wp_undo_list shows the key, not the value' );
ok( 1 === $dry['would_undo'] && false === strpos( (string) wp_json_encode( $dry ), 'GEHEIM' ), 'J2 a dry run neither' );
ab_update_meta( 'post', 5, 'notiz', 'GEHEIM-neuer' );
$out = ab_undo( $res['undo'] );
ok( false === strpos( (string) wp_json_encode( $out ), 'GEHEIM' ), 'J3 nor a refusal' );

/* ============================== K) a restore that fails keeps the point */

reset_all();
ab_add_row( 'post', 5, 'farbe', 'rot' );
$res = ab_post_meta( 5, 'farbe', 'blau' );
$GLOBALS['ab_db_nowrite'] = true;
$out = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && 1 === count( $out['failed'] ) && false === $out['consumed'] && null !== ab_entry( $res['undo'] ), 'K1 the database took no write: failed, and the point stays' );
ok( false !== strpos( $out['failed'][0]['reason'], 'farbe' ) && false !== strpos( $out['failed'][0]['reason'], 'Try again' ), 'K2 the reason names the key and the way' );
$GLOBALS['ab_db_nowrite'] = false;
ok( 1 === ab_undo( $res['undo'] )['undone'] && array( 'rot' ) === array_values( ab_rows( 'post', 5, 'farbe' ) ), 'K3 once the database takes it, the same token restores' );
$res = ab_post_meta( 5, 'farbe', 'blau' );
$GLOBALS['ab_db_down'] = true;
$out = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && 'unreadable' === $out['blocked'][0]['reason'] && null !== ab_entry( $res['undo'] ), 'K4 a table that cannot be read: blocked, nothing written on a guess' );

reset_all();
ab_add_row( 'post', 5, 'farbe', 'rot' );
$res = ab_post_meta( 5, 'farbe', 'blau' );
$GLOBALS['ab_db_lies'] = true;
$out = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && 1 === count( $out['failed'] ) && false !== strpos( $out['failed'][0]['reason'], 'holds something else for: farbe' ) && null !== ab_entry( $res['undo'] ), 'K5 a write the database reports but does not hold is read back: failed, the point stays' );

/* ============================== M) Codex 31.10.2026: the key as stored, keys of digits, objects */

reset_all();
$lf  = ab_add_row( 'post', 5, 'locked_field', 'locked' );
$GLOBALS['ab_deny'] = array( 'edit_post_meta:5:locked_field' => true );
$forged = array( 'v' => 1, 'type' => 'post', 'id' => 5, 'keys' => array( 'LOCKED_FIELD' => array( 'existed' => true, 'rows' => array( array( 'meta_id' => $lf, 'value' => 'mine' ) ) ) ), 'after' => array( 'meta:LOCKED_FIELD' => str_repeat( 'a', 64 ) ) );
$t   = AB_MCP_Undo::record( array( 'tool' => 'wp_update_post_meta', 'cap' => 'edit_posts', 'kind' => 'meta', 'summary' => 'forged', 'items' => 1, 'data' => $forged ) );
$out = ab_undo( $t );
ok( 'ab_mcp_undo_corrupt' === ab_code( $out ) && array( $lf => 'locked' ) === ab_rows( 'post', 5, 'locked_field' ), 'M1 a key in another spelling than the tool writes (LOCKED_FIELD for locked_field) is refused whole; the row its rights guard stays' );
ok( false === AB_MCP_Undo_Meta::valid( 'wp_update_media', array( 'v' => 1, 'type' => 'post', 'id' => 9, 'keys' => array( '_WP_ATTACHMENT_IMAGE_ALT' => array( 'existed' => false ) ), 'after' => array( 'meta:_WP_ATTACHMENT_IMAGE_ALT' => str_repeat( 'a', 64 ) ) ) ), 'M2 wp_update_media: the alt key only as WordPress writes it' );
$GLOBALS['ab_deny'] = array();
$mx = ab_add_row( 'post', 5, 'Mixed', 'another spelling' );
$r3 = AB_MCP_Undo_Meta::rows( 'post', 5, 'mixed' );
ok( 1 === count( $r3 ) && $mx === $r3[0]['meta_id'] && 'Mixed' === $r3[0]['key'], 'M3 every row the database matches for the key, with the key it is stored under' );

/* Codex 31.10.2026, second round: update_metadata() writes "Mixed" with "mixed". */
reset_all();
$m1  = ab_add_row( 'term', 8, 'mixed', 'alt1' );
$m2  = ab_add_row( 'term', 8, 'Mixed', 'alt2' );
$res = AB_MCP_Tools_Meta_Auth::update_term_meta_tool( array( 'term_id' => 8, 'key' => 'mixed', 'value' => 'neu' ) );
ok( array( $m1 => 'neu' ) === ab_rows( 'term', 8, 'mixed' ) && array( $m2 => 'neu' ) === ab_rows( 'term', 8, 'Mixed' ), 'M4 (the write as WordPress does it: both rows)' );
$st = AB_MCP_Undo::claim( (string) $res['undo'] )['data']['keys']['mixed'];
ok( array( array( 'meta_id' => $m1, 'value' => 'alt1' ), array( 'meta_id' => $m2, 'value' => 'alt2', 'key' => 'Mixed' ) ) === $st['rows'], 'M4 the snapshot holds both rows, the other spelling with its key' );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && array( $m1 => 'alt1' ) === ab_rows( 'term', 8, 'mixed' ) && array( $m2 => 'alt2' ) === ab_rows( 'term', 8, 'Mixed' ), 'M5 and the undo puts both back, each under its own key' );

reset_all();
$m1  = ab_add_row( 'term', 8, 'mixed', 'alt1' );
$m2  = ab_add_row( 'term', 8, 'Mixed', 'alt2' );
$res = AB_MCP_Tools_Meta_Auth::update_term_meta_tool( array( 'term_id' => 8, 'key' => 'mixed', 'value' => 'neu' ) );
$GLOBALS['ab_meta']['term'][ $m2 ][1] = 'MIXED'; // renamed since, value as the change left it
$out = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && 'changed_since' === $out['blocked'][0]['reason'], 'M5i a row renamed since counts as changed: its key is part of its state' );
$out = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 1 === $out['undone'] && array( $m2 => 'alt2' ) === ab_rows( 'term', 8, 'Mixed' ) && array() === ab_rows( 'term', 8, 'MIXED' ), 'M5j with force it comes back under the key it had' );

reset_all();
$m2  = ab_add_row( 'term', 8, 'Mixed', 'alt2' );
$res = AB_MCP_Tools_Meta_Auth::update_term_meta_tool( array( 'term_id' => 8, 'key' => 'mixed', 'value' => 'neu' ) );
ok( is_array( $res ) && AB_MCP_Undo::valid_token( (string) $res['undo'] ) && array( 'mixed' ) === $res['saved_differs'], 'M5a only "Mixed" there: the write changed it, so it is no refusal, it is undoable, and the answer says the key reads otherwise' );
ok( 1 === ab_undo( $res['undo'] )['undone'] && array( $m2 => 'alt2' ) === ab_rows( 'term', 8, 'Mixed' ), 'M5b and the undo puts "Mixed" back' );

reset_all();
$x   = ab_add_row( 'post', 5, 'mixed', 'old' );
$fp  = array( 'v' => 1, 'type' => 'post', 'id' => 5, 'keys' => array( 'mixed' => array( 'existed' => true, 'rows' => array( array( 'meta_id' => $x, 'value' => 'mine', 'key' => 'MIXED' ) ) ) ), 'after' => array( 'meta:mixed' => str_repeat( 'a', 64 ) ) );
$GLOBALS['ab_deny'] = array( 'edit_post_meta:5:MIXED' => true );
$out = ab_undo( AB_MCP_Undo::record( array( 'tool' => 'wp_update_post_meta', 'cap' => 'edit_posts', 'kind' => 'meta', 'summary' => 'f', 'items' => 1, 'data' => $fp ) ), array( 'force' => true ) );
ok( 'forbidden' === $out['blocked'][0]['reason'] && array( $x => 'old' ) === ab_rows( 'post', 5, 'mixed' ), 'M5c every spelling a row is stored under is asked the rights of the tool' );
$GLOBALS['ab_deny'] = array();
$fp['keys']['mixed']['rows'][0]['key'] = 'locked_field';
$out = ab_undo( AB_MCP_Undo::record( array( 'tool' => 'wp_update_post_meta', 'cap' => 'edit_posts', 'kind' => 'meta', 'summary' => 'f', 'items' => 1, 'data' => $fp ) ), array( 'force' => true ) );
ok( 'key_spelling' === $out['blocked'][0]['reason'] && array( $x => 'old' ) === ab_rows( 'post', 5, 'mixed' ) && array() === ab_rows( 'post', 5, 'locked_field' ), 'M5d a row stored under a key that is no other letter case of it: not restored' );

reset_all();
ab_post( 6, array( 'post_type' => 'revision', 'post_parent' => 5 ) );
$GLOBALS['ab_revisions'][6] = 5;
$fr  = array( 'v' => 1, 'type' => 'post', 'id' => 6, 'keys' => array( 'note' => array( 'existed' => false ) ), 'after' => array( 'meta:note' => str_repeat( 'a', 64 ) ) );
$out = ab_undo( AB_MCP_Undo::record( array( 'tool' => 'wp_update_post_meta', 'cap' => 'edit_posts', 'kind' => 'meta', 'summary' => 'f', 'items' => 1, 'data' => $fr ) ), array( 'force' => true ) );
ok( 'revision' === $out['blocked'][0]['reason'], 'M5e a point that names a revision (the tool records its post) is not restored' );

reset_all();
$GLOBALS['ab_deny'] = array( 'assign_categories' => true );
$GLOBALS['ab_meta_sanitize']['note'] = static fn( $v ) => 'GEHEIM-server';
$res = AB_MCP_Tools_Meta_Auth::update_term_meta_tool( array( 'term_id' => 8, 'key' => 'note', 'value' => 'x' ) );
ok( array() === (array) $res['saved_fields'] && array( 'note' => 'not_shown' ) === $res['saved_fields_omitted'] && false === strpos( (string) wp_json_encode( $res ), 'GEHEIM' ), 'M5f an account wp_get_term_meta does not answer for this term (no right to assign its terms): nothing shown' );
ok( 'ab_mcp_forbidden' === ab_code( AB_MCP_Tools_Meta_Auth::get_term_meta_tool( array( 'term_id' => 8 ) ) ), 'M5g ... as the reader refuses it, by the same rule' );
$GLOBALS['ab_deny'] = array( 'list_users' => true );
$res = AB_MCP_Tools_Meta_Auth::update_user_meta_tool( array( 'user_id' => 5, 'key' => 'nickname', 'value' => 'Tom' ) );
ok( array() === (array) $res['saved_fields'] && array( 'nickname' => 'not_shown' ) === $res['saved_fields_omitted'], 'M5h without list_users, the capability of wp_get_user_meta, a profile field is not shown either' );
$GLOBALS['ab_deny'] = array();

reset_all();
$GLOBALS['ab_protected'] = array( 'internal_note' );
$GLOBALS['ab_meta_sanitize']['internal_note'] = static fn( $v ) => 'GEHEIM-server-value';
$res = AB_MCP_Tools_Meta_Auth::update_term_meta_tool( array( 'term_id' => 8, 'key' => 'internal_note', 'value' => 'x' ) );
ok( array() === (array) $res['saved_fields'] && array( 'internal_note' => 'not_shown' ) === $res['saved_fields_omitted'] && array( 'internal_note' ) === $res['saved_differs'] && false === strpos( (string) wp_json_encode( $res ), 'GEHEIM' ), 'M6 a key wp_get_term_meta does not show (protected by a filter): compared, never shown' );
ok( 'ab_mcp_protected_meta' === ab_code( AB_MCP_Tools_Meta_Auth::get_term_meta_tool( array( 'term_id' => 8, 'key' => 'internal_note' ) ) ), 'M7 ... the same rule as the reader, which refuses it' );
$res = AB_MCP_Tools_Meta_Auth::update_term_meta_tool( array( 'term_id' => 8, 'key' => 'api_hint', 'value' => 'shown' ) );
ok( array( 'api_hint' => 'shown' ) === (array) $res['saved_fields'], 'M8 a key the reader shows is shown' );

reset_all();
$res = AB_MCP_Tools_Meta_Auth::update_term_meta_tool( array( 'term_id' => 8, 'key' => '2024', 'value' => 'blue' ) );
ok( array( '2024' => 'blue' ) === (array) $res['saved_fields'] && ! isset( $res['saved_differs'] ) && '{"2024":"blue"}' === wp_json_encode( $res['saved_fields'] ), 'M9 a key of digits keeps its name in saved_fields' );
ok( AB_MCP_Undo::valid_token( (string) $res['undo'] ), 'M10 ... and gets its undo point' );
$again = AB_MCP_Tools_Meta_Auth::update_term_meta_tool( array( 'term_id' => 8, 'key' => '2024', 'value' => 'blue' ) );
ok( is_array( $again ) && null === $again['undo'] && false !== strpos( $again['undo_note'], 'nothing changed' ), 'M11 the same call again: nothing changed, no error' );
ok( 1 === ab_undo( $res['undo'], array( 'force' => true ) )['undone'] && array() === ab_rows( 'term', 8, '2024' ), 'M12 and the undo takes the key of digits back' );

reset_all();
$GLOBALS['ab_meta_sanitize']['obj'] = static fn( $v ) => (object) array( 'wrapped' => $v );
$res = ab_post_meta( 5, 'obj', 'x' );
ok( is_array( $res ) && AB_MCP_Undo::valid_token( (string) $res['undo'] ), 'M13 a value a sanitize callback turned into an object: the undo point is still recorded' );
ok( array( 'obj' ) === $res['saved_differs'] && isset( ( (array) $res['saved_fields'] )['obj'] ) && 'x' === ( (array) $res['saved_fields'] )['obj']->wrapped, 'M14 ... reported as different, shown as stored' );
ok( 1 === ab_undo( $res['undo'] )['undone'] && array() === ab_rows( 'post', 5, 'obj' ), 'M15 ... and undone' );
$res = ab_post_meta( 5, 'obj', 'x' );
$res = ab_post_meta( 5, 'obj', 'x' );
ok( 'ab_mcp_not_saved' === ab_code( $res ) && false !== strpos( ab_msg( $res ), 'obj' ), 'M16 the same call again does not break on the object: the site still holds the object of before, not what was sent, and nothing changed' );

reset_all();
$GLOBALS['ab_meta_sanitize']['obj2'] = static fn( $v ) => (object) array( 'wrapped' => $v );
ab_add_row( 'post', 5, 'obj2', 'a' );
ab_add_row( 'post', 5, 'obj2', 'b' );
$res = ab_post_meta( 5, 'obj2', 'x' );
ok( is_object( ( (array) $res['saved_fields'] )['obj2'] ), 'M17 two rows that hold the same object read as that object, not as a list' );
$GLOBALS['ab_db_down'] = true;
$res = ab_post_meta( 5, 'obj2', 'x' );
ok( 'ab_mcp_not_saved' === ab_code( $res ), 'M18 without the rows, the read-back still sees that the same object is nothing new' );

/* ============================== L) Pro's own wp_undo (last: the class stays) */

reset_all();
eval( 'class AB_MCP_Pro_Undo_Restore { public static function policy() { return array( "wp_update_post" => array() ); } }' ); // phpcs:ignore
$res = ab_post_meta( 5, 'farbe', 'blau' );
ok( null === $res['undo'] && false !== strpos( $res['undo_note'], 'AlphaBridge MCP Pro' ) && false !== strpos( $res['undo_note'], 'wp_update_post_meta' ) && array() === (array) get_option( AB_MCP_Undo::OPTION, array() ), 'L1 Pro\'s own wp_undo cannot reverse it: no token it could not use' );
ok( array( 'blau' ) === array_values( ab_rows( 'post', 5, 'farbe' ) ) && array( 'farbe' => 'blau' ) === (array) $res['saved_fields'], 'L2 the write and the read-back are the same' );
$GLOBALS['ab_filters']['ab_mcp_undo_core_restores'] = static fn( $handed, $tool ) => 'wp_update_post_meta' === $tool;
$res = ab_post_meta( 5, 'farbe', 'gruen' );
ok( AB_MCP_Undo::valid_token( (string) $res['undo'] ), 'L3 a Pro that hands the points of the tool on gets the token' );
$res = AB_MCP_Tools_Media::update_media( array( 'id' => 9, 'alt' => 'x' ) );
ok( null === $res['undo'] && false !== strpos( $res['undo_note'], 'wp_update_media' ), 'L4 ... per tool' );

echo "UNDO-META: $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
