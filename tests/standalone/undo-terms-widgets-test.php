<?php
/**
 * The undo of terms, menus, menu items and widgets (kinds term, menu_item and
 * widget, since 4.13.0) and the read-back of these tools, without WordPress:
 * terms, term taxonomies, relationships, term meta, posts, post meta and
 * options are in-memory rows, read and written through a $wpdb that answers
 * the very queries the undo classes make (the option table matches names
 * without regard to case, as MySQL's usual collation does), and the term,
 * menu and option functions of WordPress work on the same rows. The undo
 * store, the restore, wp_undo and the term, menu and widget tools are the
 * real ones.
 *
 * Proves, per kind: a write records one point; wp_undo takes the change back
 * (a new term or menu or item deleted again, the old fields of a term, the
 * option rows of a widget byte for byte); a change since is refused unless
 * force, and an object gone since is nothing to do; the rights and rules of
 * the tool are asked again on the row the database holds, and a hand-edited
 * point gains no right (another taxonomy, another spelling, a post that is no
 * menu item, settings the widget would not store, a form the tool does not
 * write); the answer says why there is no point; saved_fields shows what the
 * reader shows and compares the rest; a deletion says it is not undoable; no
 * answer about a widget point shows a credential; a widget value sent back as
 * [REDACTED] keeps the stored one, and its point is the row before.
 *
 * Run:  php tests/standalone/undo-terms-widgets-test.php
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
function wp_get_current_user() { return (object) array( 'user_login' => 'editor' ); }

/* ---- filters ---- */
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
function add_action( $tag, $cb, $prio = 10, $n = 1 ) { $GLOBALS['ab_actions'][ $tag ][] = $cb; return true; }
function remove_action( $tag, $cb, $prio = 10 ) {
	foreach ( $GLOBALS['ab_actions'][ $tag ] ?? array() as $i => $f ) {
		if ( $f === $cb ) { unset( $GLOBALS['ab_actions'][ $tag ][ $i ] ); return true; }
	}
	return false;
}
function has_action( $tag, $cb = false ) { return ! empty( $GLOBALS['ab_actions'][ $tag ] ); }
function do_action( $tag, ...$args ) { foreach ( $GLOBALS['ab_actions'][ $tag ] ?? array() as $f ) { $f( ...$args ); } }
function is_multisite() { return false; }
$GLOBALS['ab_cache_deleted'] = array();
function wp_cache_delete( $k, $group = '' ) { $GLOBALS['ab_cache_deleted'][] = $group . ':' . $k; return true; }
function clean_term_cache( $ids, $tax = '' ) {}
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
$GLOBALS['ab_upload'] = sys_get_temp_dir() . '/ab-free-undo-terms-' . getmypid() . '-' . bin2hex( random_bytes( 6 ) );
register_shutdown_function( static function () { ab_test_rm_tree( $GLOBALS['ab_upload'] ); } );
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['ab_upload'], 'baseurl' => 'http://x/u', 'error' => false ); }
function wp_slash( $v ) { return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_title( $t ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $t ) ), '-' ); }
function esc_url_raw( $u ) { return preg_match( '#^(https?:|mailto:|tel:|/)#', (string) $u ) ? (string) $u : ''; }
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

/* ---- the tables ---- */
function ab_db_reset() {
	$GLOBALS['T'] = array(
		'terms'    => array(), // id => [ name, slug ]
		'tt'       => array(), // tt_id => [ term_id, taxonomy, description, parent ]
		'rel'      => array(), // [ object_id, tt_id ]
		'termmeta' => array(), // meta_id => [ term_id, key, value ]
		'posts'    => array(), // id => columns
		'postmeta' => array(), // meta_id => [ post_id, key, value ]
		'options'  => array(), // name => [ value (raw), autoload ]
	);
	$GLOBALS['ab_next']    = 100;
	$GLOBALS['ab_db_down']    = false; // the tables cannot be read
	$GLOBALS['ab_db_nowrite'] = false; // the options table takes no write
	$GLOBALS['ab_db_lies']    = false; // an update of an option that reports a write and writes nothing
	$GLOBALS['ab_db_noflag']  = false; // an update of an option that writes the value and drops the flag
	$GLOBALS['ab_db_error']   = false; // a SELECT that ran and failed: no rows, and last_error says why
	$GLOBALS['ab_delete_lies'] = false; // a deletion of a term or a post that reports success and deletes nothing
	$GLOBALS['ab_keep_posts']  = array(); // posts a plugin keeps from deletion (pre_delete_post)
}
ab_db_reset();
function ab_next() { return $GLOBALS['ab_next']++; }
/* The stored name of an option, matched as MySQL's case-folding collation matches it. */
function ab_opt_name( $name ) {
	foreach ( array_keys( $GLOBALS['T']['options'] ) as $n ) {
		if ( strtolower( (string) $n ) === strtolower( (string) $name ) ) { return (string) $n; }
	}
	return null;
}
function ab_tt_of( $term_id, $tax = null ) {
	foreach ( $GLOBALS['T']['tt'] as $tt => $r ) {
		if ( $r[0] === (int) $term_id && ( null === $tax || $r[1] === $tax ) ) { return $tt; }
	}
	return null;
}

$GLOBALS['wpdb'] = new class {
	public $posts              = 'wp_posts';
	public $postmeta           = 'wp_postmeta';
	public $terms              = 'wp_terms';
	public $term_taxonomy      = 'wp_term_taxonomy';
	public $term_relationships = 'wp_term_relationships';
	public $termmeta           = 'wp_termmeta';
	public $options            = 'wp_options';
	public $last_error         = '';
	public $last_result        = array();
	public $writes             = array();
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
		if ( preg_match( '/^SELECT t\.name, t\.slug, tt\.term_taxonomy_id, tt\.taxonomy, tt\.description, tt\.parent FROM wp_terms AS t INNER JOIN wp_term_taxonomy AS tt ON tt\.term_id = t\.term_id WHERE t\.term_id = (\d+)$/', $sql, $m ) ) {
			$out = array();
			foreach ( $T['tt'] as $tt => $r ) {
				if ( $r[0] === (int) $m[1] && isset( $T['terms'][ $r[0] ] ) ) {
					$out[] = array( 'name' => $T['terms'][ $r[0] ][0], 'slug' => $T['terms'][ $r[0] ][1], 'term_taxonomy_id' => (string) $tt, 'taxonomy' => $r[1], 'description' => $r[2], 'parent' => (string) $r[3] );
				}
			}
		} elseif ( preg_match( '/^SELECT object_id FROM wp_term_relationships WHERE term_taxonomy_id = (\d+) ORDER BY object_id ASC$/', $sql, $m ) ) {
			$ids = array();
			foreach ( $T['rel'] as $r ) {
				if ( $r[1] === (int) $m[1] ) { $ids[] = $r[0]; }
			}
			sort( $ids );
			$out = array_map( static fn( $i ) => array( 'object_id' => (string) $i ), $ids );
		} elseif ( preg_match( "/^SELECT term_id FROM wp_term_taxonomy WHERE parent = (\\d+) AND taxonomy = '(.*)' ORDER BY term_id ASC$/", $sql, $m ) ) {
			$ids = array();
			foreach ( $T['tt'] as $r ) {
				if ( $r[3] === (int) $m[1] && $r[1] === stripslashes( $m[2] ) ) { $ids[] = $r[0]; }
			}
			sort( $ids );
			$out = array_map( static fn( $i ) => array( 'term_id' => (string) $i ), $ids );
		} elseif ( preg_match( '/^SELECT meta_id, meta_key, meta_value FROM wp_termmeta WHERE term_id = (\d+) ORDER BY meta_id ASC$/', $sql, $m ) ) {
			$out = array();
			foreach ( $T['termmeta'] as $mid => $r ) {
				if ( $r[0] === (int) $m[1] ) { $out[] = array( 'meta_id' => (string) $mid, 'meta_key' => $r[1], 'meta_value' => $r[2] ); }
			}
		} elseif ( preg_match( '/^SELECT ([a-z_, ]+) FROM wp_posts WHERE ID = (\d+)$/', $sql, $m ) ) {
			$out = array();
			if ( isset( $T['posts'][ (int) $m[2] ] ) ) {
				$row = array();
				foreach ( array_map( 'trim', explode( ',', $m[1] ) ) as $c ) { $row[ $c ] = (string) ( $T['posts'][ (int) $m[2] ][ $c ] ?? '' ); }
				$out[] = $row;
			}
		} elseif ( preg_match( '/^SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = (\d+) ORDER BY meta_id ASC$/', $sql, $m ) ) {
			$out = array();
			foreach ( $T['postmeta'] as $r ) {
				if ( $r[0] === (int) $m[1] ) { $out[] = array( 'meta_key' => $r[1], 'meta_value' => $r[2] ); }
			}
		} elseif ( preg_match( '/^SELECT term_taxonomy_id FROM wp_term_relationships WHERE object_id = (\d+) ORDER BY term_taxonomy_id ASC$/', $sql, $m ) ) {
			$out = array();
			foreach ( $T['rel'] as $r ) {
				if ( $r[0] === (int) $m[1] ) { $out[] = array( 'term_taxonomy_id' => (string) $r[1] ); }
			}
		} elseif ( preg_match( "/^SELECT COUNT\\(\\*\\) AS n FROM wp_postmeta WHERE meta_key = '_menu_item_menu_item_parent' AND meta_value = '(\\d+)'$/", $sql, $m ) ) {
			$out = array( array( 'n' => (string) count( array_filter( $T['postmeta'], static fn( $r ) => '_menu_item_menu_item_parent' === $r[1] && $m[1] === $r[2] ) ) ) );
		} elseif ( preg_match( "/^SELECT option_name, (option_value, )?autoload FROM wp_options WHERE option_name = '(.*)' LIMIT 1$/s", $sql, $m ) ) {
			$out  = array();
			$name = ab_opt_name( stripslashes( $m[2] ) );
			if ( null !== $name ) {
				$out[] = array( 'option_name' => $name, 'option_value' => $T['options'][ $name ][0], 'autoload' => $T['options'][ $name ][1] );
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
	public function insert( $table, $data ) {
		if ( 'wp_options' !== $table || $GLOBALS['ab_db_nowrite'] || null !== ab_opt_name( $data['option_name'] ) ) { return false; }
		$this->writes[] = array( 'insert', $data['option_name'] );
		$GLOBALS['T']['options'][ (string) $data['option_name'] ] = array( (string) $data['option_value'], (string) ( $data['autoload'] ?? 'yes' ) );
		return 1;
	}
	public function update( $table, $data, $where ) {
		if ( 'wp_options' !== $table || $GLOBALS['ab_db_nowrite'] ) { return false; }
		$name = ab_opt_name( $where['option_name'] );
		if ( null === $name || ( isset( $where['option_value'] ) && $GLOBALS['T']['options'][ $name ][0] !== (string) $where['option_value'] ) ) { return 0; }
		$this->writes[] = array( 'update', $name );
		if ( $GLOBALS['ab_db_lies'] ) { return 1; }
		$GLOBALS['T']['options'][ $name ][0] = (string) $data['option_value'];
		if ( isset( $data['autoload'] ) && ! $GLOBALS['ab_db_noflag'] ) {
			$GLOBALS['T']['options'][ $name ][1] = (string) $data['autoload'];
		}
		return 1;
	}
	public function delete( $table, $where ) {
		if ( 'wp_options' !== $table || $GLOBALS['ab_db_nowrite'] ) { return false; }
		$name = ab_opt_name( $where['option_name'] );
		if ( null === $name ) { return 0; }
		$this->writes[] = array( 'delete', $name );
		unset( $GLOBALS['T']['options'][ $name ] );
		return 1;
	}
};

/* ---- options, on the options table (values serialized as WordPress stores them) ---- */
function get_option( $k, $d = false ) {
	$n = ab_opt_name( $k );
	return null === $n ? $d : maybe_unserialize( $GLOBALS['T']['options'][ $n ][0] );
}
function add_option( $k, $v, $depr = '', $autoload = 'yes' ) {
	if ( null !== ab_opt_name( $k ) ) { return false; }
	$GLOBALS['T']['options'][ (string) $k ] = array( (string) maybe_serialize( $v ), false === $autoload ? 'no' : 'yes' );
	return true;
}
function update_option( $k, $v, $autoload = null ) {
	$v = apply_filters( 'pre_update_option_' . $k, $v, get_option( $k ), $k );
	$n = ab_opt_name( $k );
	if ( null === $n ) { return add_option( $k, $v ); }
	$raw = (string) maybe_serialize( $v );
	if ( $raw === $GLOBALS['T']['options'][ $n ][0] ) { return false; }
	$GLOBALS['T']['options'][ $n ][0] = $raw;
	return true;
}
function delete_option( $k ) { $n = ab_opt_name( $k ); if ( null === $n ) { return false; } unset( $GLOBALS['T']['options'][ $n ] ); return true; }
function ab_raw( $name ) { return $GLOBALS['T']['options'][ $name ][0] ?? null; }

/* ---- taxonomies and terms ---- */
function taxonomy_exists( $t ) { return in_array( $t, array( 'category', 'post_tag', 'nav_menu' ), true ); }
function get_taxonomy( $t ) {
	if ( ! taxonomy_exists( $t ) ) { return false; }
	$c = 'nav_menu' === $t ? 'edit_theme_options' : 'manage_categories';
	return (object) array( 'name' => $t, 'cap' => (object) array( 'manage_terms' => $c, 'edit_terms' => $c, 'delete_terms' => $c, 'assign_terms' => $c ) );
}
function get_term( $id, $tax = '' ) {
	$T = $GLOBALS['T'];
	foreach ( $T['tt'] as $tt => $r ) {
		if ( $r[0] === (int) $id && ( '' === $tax || $r[1] === $tax ) && isset( $T['terms'][ $r[0] ] ) ) {
			return (object) array( 'term_id' => $r[0], 'name' => $T['terms'][ $r[0] ][0], 'slug' => $T['terms'][ $r[0] ][1], 'taxonomy' => $r[1], 'description' => $r[2], 'parent' => $r[3], 'term_taxonomy_id' => $tt, 'count' => 0 );
		}
	}
	return null;
}
/* The name as WordPress stores it: the filters of pre_term_name, of which _wp_specialchars turns & into &amp;. */
function ab_term_name( $name ) { return str_replace( '&', '&amp;', str_replace( '&amp;', '&', (string) $name ) ); }
function sanitize_term_field( $field, $value, $id, $tax, $ctx ) { return 'name' === $field ? wp_slash( ab_term_name( wp_unslash( $value ) ) ) : $value; }
function ab_slug_taken( $slug, $tax, $except = 0 ) {
	foreach ( $GLOBALS['T']['tt'] as $r ) {
		if ( $r[1] === $tax && $r[0] !== $except && isset( $GLOBALS['T']['terms'][ $r[0] ] ) && $GLOBALS['T']['terms'][ $r[0] ][1] === $slug ) { return true; }
	}
	return false;
}
$GLOBALS['ab_term_veto'] = false; // a filter keeps every change of a term from being stored
function wp_insert_term( $name, $tax, $args = array() ) {
	$name = ab_term_name( wp_unslash( $name ) );
	$slug = '' !== (string) ( $args['slug'] ?? '' ) ? sanitize_title( $args['slug'] ) : sanitize_title( $name );
	$base = $slug;
	for ( $i = 2; ab_slug_taken( $slug, $tax ); $i++ ) { $slug = $base . '-' . $i; }
	$parent = (int) ( $args['parent'] ?? 0 );
	if ( $parent && null === ab_tt_of( $parent, $tax ) ) { return new WP_Error( 'missing_parent', 'Parent term does not exist.' ); }
	$id = ab_next();
	$tt = ab_next();
	$GLOBALS['T']['terms'][ $id ] = array( $name, $slug );
	$GLOBALS['T']['tt'][ $tt ]    = array( $id, $tax, wp_unslash( (string) ( $args['description'] ?? '' ) ), $parent );
	// As WordPress: a duplicate another request made at the same moment is
	// removed again, and the older term is answered, without the create hooks.
	if ( isset( $GLOBALS['ab_insert_dupe'] ) ) {
		unset( $GLOBALS['T']['terms'][ $id ], $GLOBALS['T']['tt'][ $tt ] );
		return array( 'term_id' => $GLOBALS['ab_insert_dupe'], 'term_taxonomy_id' => ab_tt_of( $GLOBALS['ab_insert_dupe'] ) );
	}
	if ( isset( $GLOBALS['ab_on_create_term'] ) ) { ( $GLOBALS['ab_on_create_term'] )( $id ); }
	do_action( 'created_term', $id, $tt, $tax );
	return array( 'term_id' => $id, 'term_taxonomy_id' => $tt );
}
function wp_update_term( $id, $tax, $args = array() ) {
	$tt = ab_tt_of( $id, $tax );
	if ( null === $tt ) { return new WP_Error( 'invalid_term', 'Empty Term.' ); }
	if ( $GLOBALS['ab_term_veto'] ) { return array( 'term_id' => (int) $id, 'term_taxonomy_id' => $tt ); }
	$t = $GLOBALS['T']['terms'][ (int) $id ];
	$r = $GLOBALS['T']['tt'][ $tt ];
	if ( array_key_exists( 'name', $args ) ) { $t[0] = ab_term_name( wp_unslash( $args['name'] ) ); }
	if ( array_key_exists( 'slug', $args ) ) {
		$slug = '' === (string) $args['slug'] ? sanitize_title( $t[0] ) : sanitize_title( $args['slug'] );
		if ( ab_slug_taken( $slug, $tax, (int) $id ) ) { return new WP_Error( 'duplicate_term_slug', 'The slug is already in use by another term.' ); }
		$t[1] = $slug;
	}
	if ( array_key_exists( 'description', $args ) ) { $r[2] = wp_unslash( (string) $args['description'] ); }
	if ( array_key_exists( 'parent', $args ) ) {
		if ( (int) $args['parent'] && null === ab_tt_of( (int) $args['parent'], $tax ) ) { return new WP_Error( 'missing_parent', 'Parent term does not exist.' ); }
		$r[3] = (int) $args['parent'];
	}
	$GLOBALS['T']['terms'][ (int) $id ] = $t;
	$GLOBALS['T']['tt'][ $tt ]          = $r;
	return array( 'term_id' => (int) $id, 'term_taxonomy_id' => $tt );
}
function wp_delete_term( $id, $tax ) {
	$tt = ab_tt_of( $id, $tax );
	if ( null === $tt ) { return 0; }
	if ( (int) get_option( 'default_' . $tax ) === (int) $id ) { return 0; }
	if ( $GLOBALS['ab_delete_lies'] ) { return true; }
	unset( $GLOBALS['T']['tt'][ $tt ], $GLOBALS['T']['terms'][ (int) $id ] );
	$GLOBALS['T']['rel']      = array_values( array_filter( $GLOBALS['T']['rel'], static fn( $r ) => $r[1] !== $tt ) );
	$GLOBALS['T']['termmeta'] = array_filter( $GLOBALS['T']['termmeta'], static fn( $r ) => $r[0] !== (int) $id );
	return true;
}
function ab_add_term( $tax, $name, $slug = null, $parent = 0 ) {
	$id = ab_next();
	$tt = ab_next();
	$GLOBALS['T']['terms'][ $id ] = array( $name, $slug ?? sanitize_title( $name ) );
	$GLOBALS['T']['tt'][ $tt ]    = array( $id, $tax, '', $parent );
	return $id;
}

/* ---- menus and their items ---- */
$GLOBALS['ab_locations'] = array();
function get_nav_menu_locations() { return $GLOBALS['ab_locations']; }
function wp_create_nav_menu( $name ) {
	foreach ( $GLOBALS['T']['tt'] as $r ) {
		if ( 'nav_menu' === $r[1] && $GLOBALS['T']['terms'][ $r[0] ][0] === wp_unslash( $name ) ) { return new WP_Error( 'menu_exists', 'The menu name is already in use.' ); }
	}
	$res = wp_insert_term( $name, 'nav_menu' );
	return is_wp_error( $res ) ? $res : $res['term_id'];
}
function wp_get_nav_menu_object( $menu ) {
	foreach ( $GLOBALS['T']['tt'] as $r ) {
		if ( 'nav_menu' === $r[1] ) {
			$t = $GLOBALS['T']['terms'][ $r[0] ];
			if ( (string) $r[0] === (string) $menu || $t[1] === (string) $menu || $t[0] === (string) $menu ) { return get_term( $r[0], 'nav_menu' ); }
		}
	}
	return false;
}
function wp_delete_nav_menu( $id ) {
	$tt = ab_tt_of( $id, 'nav_menu' );
	if ( null === $tt ) { return false; }
	foreach ( $GLOBALS['T']['rel'] as $r ) {
		if ( $r[1] === $tt ) { wp_delete_post( $r[0], true ); }
	}
	$GLOBALS['ab_locations'] = array_filter( $GLOBALS['ab_locations'], static fn( $m ) => (int) $m !== (int) $id );
	return wp_delete_term( $id, 'nav_menu' );
}
function ab_add_post( $id, $cols = array() ) {
	$GLOBALS['T']['posts'][ $id ] = array_merge( array( 'ID' => $id, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Page ' . $id, 'post_name' => 'p' . $id, 'post_content' => '', 'post_excerpt' => '', 'post_parent' => 0, 'menu_order' => 0 ), $cols );
}
function ab_add_postmeta( $post, $key, $value ) { $GLOBALS['T']['postmeta'][ ab_next() ] = array( (int) $post, (string) $key, (string) $value ); }
function wp_update_nav_menu_item( $menu_id, $item_id, $data ) {
	$data = wp_unslash( $data );
	$id   = ab_next();
	$obj  = (int) ( $data['menu-item-object-id'] ?? 0 );
	$title = (string) $data['menu-item-title'];
	// As WordPress: a title the same as the linked post's is not stored.
	if ( $obj && isset( $GLOBALS['T']['posts'][ $obj ] ) && $GLOBALS['T']['posts'][ $obj ]['post_title'] === $title ) { $title = ''; }
	ab_add_post( $id, array( 'post_type' => 'nav_menu_item', 'post_status' => (string) $data['menu-item-status'], 'post_title' => $title, 'post_name' => (string) $id, 'menu_order' => 1 ) );
	ab_add_postmeta( $id, '_menu_item_type', (string) $data['menu-item-type'] );
	ab_add_postmeta( $id, '_menu_item_menu_item_parent', (string) (int) ( $data['menu-item-parent-id'] ?? 0 ) );
	ab_add_postmeta( $id, '_menu_item_object_id', (string) ( $obj ?: $id ) );
	ab_add_postmeta( $id, '_menu_item_object', (string) ( $data['menu-item-object'] ?? 'custom' ) );
	ab_add_postmeta( $id, '_menu_item_url', (string) ( $data['menu-item-url'] ?? '' ) );
	$GLOBALS['T']['rel'][] = array( $id, ab_tt_of( $menu_id, 'nav_menu' ) );
	return $id;
}
function get_post( $id ) { return isset( $GLOBALS['T']['posts'][ (int) $id ] ) ? (object) $GLOBALS['T']['posts'][ (int) $id ] : null; }
function get_post_meta( $id, $key = '', $single = false ) {
	$vals = array();
	foreach ( $GLOBALS['T']['postmeta'] as $r ) {
		if ( $r[0] === (int) $id && $r[1] === $key ) { $vals[] = $r[2]; }
	}
	return $single ? ( $vals[0] ?? '' ) : $vals;
}
function wp_setup_nav_menu_item( $post ) {
	$obj  = (int) get_post_meta( $post->ID, '_menu_item_object_id', true );
	$type = get_post_meta( $post->ID, '_menu_item_type', true );
	return (object) array(
		'title'            => '' !== $post->post_title ? $post->post_title : ( 'post_type' === $type && isset( $GLOBALS['T']['posts'][ $obj ] ) ? $GLOBALS['T']['posts'][ $obj ]['post_title'] : '' ),
		'url'              => 'custom' === $type ? get_post_meta( $post->ID, '_menu_item_url', true ) : 'http://x/?p=' . $obj,
		'menu_item_parent' => get_post_meta( $post->ID, '_menu_item_menu_item_parent', true ),
		'object_id'        => (string) $obj,
		'object'           => get_post_meta( $post->ID, '_menu_item_object', true ),
	);
}
function is_nav_menu_item( $id ) { return isset( $GLOBALS['T']['posts'][ (int) $id ] ) && 'nav_menu_item' === $GLOBALS['T']['posts'][ (int) $id ]['post_type']; }
function wp_delete_post( $id, $force = false ) {
	$p = get_post( $id );
	if ( ! $p ) { return false; }
	if ( in_array( (int) $id, $GLOBALS['ab_keep_posts'], true ) ) { return false; } // pre_delete_post said no
	if ( $GLOBALS['ab_delete_lies'] ) { return $p; }
	unset( $GLOBALS['T']['posts'][ (int) $id ] );
	$GLOBALS['T']['postmeta'] = array_filter( $GLOBALS['T']['postmeta'], static fn( $r ) => $r[0] !== (int) $id );
	$GLOBALS['T']['rel']      = array_values( array_filter( $GLOBALS['T']['rel'], static fn( $r ) => $r[0] !== (int) $id ) );
	return $p;
}

/* ---- widgets: a type whose update() strips tags for an account without unfiltered_html ---- */
class WP_Widget {
	public $id_base;
	public $number = 0;
	public function __construct( $id_base ) { $this->id_base = $id_base; }
	public function _set( $n ) { $this->number = (int) $n; }
	public function update( $new, $old ) {
		$clean = array();
		foreach ( (array) $new as $k => $v ) {
			$clean[ $k ] = is_string( $v ) && ! current_user_can( 'unfiltered_html' ) ? strip_tags( $v ) : $v;
			if ( 'count' === $k ) { $clean[ $k ] = (int) $v; } // a number field, kept as a number
			if ( 'shape' === $k ) { $clean[ $k ] = (object) array( 'kept' => $v ); } // a widget that keeps an object
		}
		return $clean;
	}
}
// As WP_Widget_Text::update(): starts from the stored instance, keeps
// "visual" from it, and visual mode turns "filter" on.
class AB_Text_Like_Widget extends WP_Widget {
	public function update( $new, $old ) {
		$new                = array_merge( array( 'title' => '', 'text' => '', 'filter' => false, 'visual' => null ), (array) $new );
		$instance           = (array) $old;
		$instance['title']  = strip_tags( (string) $new['title'] );
		$instance['text']   = current_user_can( 'unfiltered_html' ) ? (string) $new['text'] : strip_tags( (string) $new['text'] );
		$instance['filter'] = ! empty( $new['filter'] );
		if ( isset( $new['visual'] ) ) { $instance['visual'] = ! empty( $new['visual'] ); }
		if ( ! empty( $instance['visual'] ) ) { $instance['filter'] = true; }
		return $instance;
	}
}
// As WP_Widget_RSS::update() with wp_widget_rss_process(): the feed's
// link is worked out again only when the address changed, else empty.
class AB_Rss_Like_Widget extends WP_Widget {
	public function update( $new, $old ) {
		$new = (array) $new;
		$url = (string) ( $new['url'] ?? '' );
		return array(
			'title' => strip_tags( (string) ( $new['title'] ?? '' ) ),
			'url'   => $url,
			'link'  => $url !== (string) ( ( (array) $old )['url'] ?? '' ) ? 'https://example.test/' : '',
		);
	}
}
// wp_kses_post() as far as these checks need it: a script goes, a link stays,
// a lone "&" becomes "&amp;" as wp_kses_normalize_entities() writes it.
function wp_kses_post( $s ) { return preg_replace( '/&(?![a-z]+;|#[0-9]+;)/i', '&amp;', strip_tags( (string) $s, '<a><b><strong><em><p>' ) ); }
$GLOBALS['wp_widget_factory']      = (object) array( 'widgets' => array( new WP_Widget( 'text' ), new WP_Widget( 'feed' ), new AB_Text_Like_Widget( 'textcore' ), new AB_Rss_Like_Widget( 'rss' ) ) );
$GLOBALS['wp_registered_sidebars'] = array( 'sidebar-1' => array( 'name' => 'Side' ), 'footer' => array( 'name' => 'Foot' ) );

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
require $GLOBALS['ab_free'] . 'tools/class-tools-menus-users.php';
require $GLOBALS['ab_free'] . 'tools/class-tools-widgets.php';
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
function ab_forge( $tool, $kind, $data ) {
	return AB_MCP_Undo::record( array( 'tool' => $tool, 'cap' => 'x', 'kind' => $kind, 'summary' => 'forged', 'items' => 1, 'data' => $data ) );
}
function ab_token( $res ) { return is_array( $res ) && is_string( $res['undo'] ?? null ) && AB_MCP_Undo::valid_token( $res['undo'] ); }
function reset_all() {
	ab_db_reset();
	$GLOBALS['ab_filters']       = array();
	$GLOBALS['ab_deny']          = array();
	$GLOBALS['ab_toolstate']     = array();
	$GLOBALS['ab_term_veto']     = false;
	$GLOBALS['ab_locations']     = array();
	$GLOBALS['ab_cache_deleted'] = array();
	unset( $GLOBALS['ab_on_create_term'], $GLOBALS['ab_insert_dupe'] );
	$GLOBALS['wpdb']->writes = array();
}

/* ============================== A) a new term: one point, deleted again */

reset_all();
$res = AB_MCP_Tools_Taxonomy_Comments::create_term( array( 'taxonomy' => 'category', 'name' => 'News', 'description' => 'D' ) );
ok( true === $res['created'] && ab_token( $res ) && ! isset( $res['undo_note'] ), 'A1 wp_create_term answers with a token' );
$id = $res['term_id'];
ok( array( 'name' => 'News', 'description' => 'D' ) === (array) $res['saved_fields'] && ! isset( $res['saved_differs'] ), 'A2 saved_fields: the fields sent, read again' );
$c = AB_MCP_Undo::claim( $res['undo'] );
ok( 'wp_create_term' === $c['entry']['tool'] && 'term' === $c['entry']['kind'] && 'manage_categories' === $c['entry']['cap'] && 1 === (int) $c['entry']['items'], 'A3 kind term, with the capability of the tool' );
ok( array( 'v', 'id', 'taxonomy', 'after' ) === array_keys( $c['data'] ) && $id === $c['data']['id'] && 'category' === $c['data']['taxonomy'] && array( 'term', 'use' ) === array_keys( $c['data']['after'] ), 'A4 the snapshot names the term and keeps the state after' );
ok( sprintf( 'new term #%d (category)', $id ) === $c['entry']['summary'], 'A5 the summary names the term by id' );
$dry = ab_undo( $res['undo'], array( 'dry_run' => true ) );
ok( 1 === $dry['would_undo'] && array() === $dry['blocked'], 'A6 the dry run would restore it' );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && true === $out['consumed'] && array( 'term:' . $id ) === $out['restored'] && null === get_term( $id, 'category' ), 'A7 wp_undo deletes the term again and uses the point up' );
ok( isset( $out['page_cache_purged'] ), 'A8 the page caches are asked' );

reset_all();
ab_add_term( 'category', 'Taken', 'news' );
$res = AB_MCP_Tools_Taxonomy_Comments::create_term( array( 'taxonomy' => 'category', 'name' => 'Tom & Jerry', 'slug' => 'news' ) );
ok( array( 'name' => 'Tom &amp; Jerry', 'slug' => 'news-2' ) === (array) $res['saved_fields'] && array( 'name', 'slug' ) === $res['saved_differs'], 'A9 what WordPress stored otherwise (a name with &, a slug made unique) is shown and named' );
ok( false !== strpos( $res['warning'], 'wp_get_term' ) && ab_token( $res ), 'A10 ... with a warning naming the reader, and still a token' );

/* ============================== B) the new term put to use since */

foreach ( array(
	'assigned to a post' => static function ( $id ) { $GLOBALS['T']['rel'][] = array( 55, ab_tt_of( $id ) ); },
	'given a child'      => static function ( $id ) { ab_add_term( 'category', 'Child', null, $id ); },
	'given meta'         => static function ( $id ) { $GLOBALS['T']['termmeta'][ ab_next() ] = array( $id, 'color', 'red' ); },
) as $what => $use ) {
	reset_all();
	$res = AB_MCP_Tools_Taxonomy_Comments::create_term( array( 'taxonomy' => 'category', 'name' => 'Fresh' ) );
	$use( $res['term_id'] );
	$out = ab_undo( $res['undo'] );
	ok( 0 === $out['undone'] && 'changed_since' === $out['blocked'][0]['reason'] && array( 'use' ) === $out['blocked'][0]['locs'] && null !== get_term( $res['term_id'], 'category' ) && null !== ab_entry( $res['undo'] ), "B1 a new term $what since is not deleted, the point stays" );
}
$out = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 1 === $out['undone'] && null === get_term( $res['term_id'], 'category' ), 'B2 with force it is' );

reset_all();
$res = AB_MCP_Tools_Taxonomy_Comments::create_term( array( 'taxonomy' => 'category', 'name' => 'Fresh' ) );
wp_update_term( $res['term_id'], 'category', array( 'name' => 'Renamed' ) );
$dry = ab_undo( $res['undo'], array( 'dry_run' => true ) );
ok( 0 === $dry['would_undo'] && array( 'term' ) === $dry['blocked'][0]['locs'], 'B3 a new term renamed since: blocked, the location named' );
ok( false === strpos( (string) wp_json_encode( $dry ), 'Renamed' ), 'B4 ... and the dry run shows no value' );

reset_all();
$GLOBALS['ab_on_create_term'] = static function ( $id ) { $GLOBALS['T']['termmeta'][ ab_next() ] = array( $id, 'order', '0' ); };
$res = AB_MCP_Tools_Taxonomy_Comments::create_term( array( 'taxonomy' => 'category', 'name' => 'Shop' ) );
ok( 1 === ab_undo( $res['undo'] )['undone'], 'B5 meta a plugin adds while the term is created belongs to the state after: no conflict' );

reset_all();
$res = AB_MCP_Tools_Taxonomy_Comments::create_term( array( 'taxonomy' => 'category', 'name' => 'Short' ) );
wp_delete_term( $res['term_id'], 'category' );
$GLOBALS['ab_deny']['delete_term'] = true;
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && true === $out['consumed'], 'B6 a new term deleted since: nothing to do, restored without a right to delete' );

/* ============================== C) wp_update_term: the old fields back */

reset_all();
$p  = ab_add_term( 'category', 'Parent' );
$p0 = ab_add_term( 'category', 'First parent' );
$id = ab_add_term( 'category', 'Old\\ name', 'old-slug', $p0 );
$GLOBALS['T']['tt'][ ab_tt_of( $id ) ][2] = "Old\\ description";
$res = AB_MCP_Tools_Taxonomy_Comments::update_term( array( 'taxonomy' => 'category', 'term_id' => $id, 'name' => 'New name', 'slug' => 'new-slug', 'description' => 'New', 'parent' => $p ) );
ok( true === $res['updated'] && ab_token( $res ), 'C1 wp_update_term answers with a token' );
ok( array( 'name' => 'New name', 'slug' => 'new-slug', 'description' => 'New', 'parent' => $p ) === (array) $res['saved_fields'] && ! isset( $res['saved_differs'] ), 'C2 saved_fields: the fields sent, read again' );
$c = AB_MCP_Undo::claim( $res['undo'] );
ok( array( 'name' => 'Old\\ name', 'slug' => 'old-slug', 'description' => "Old\\ description", 'parent' => (string) $p0 ) === $c['data']['fields'] && array( 'term' ) === array_keys( $c['data']['after'] ), 'C3 the snapshot keeps the four fields as the tables held them' );
ok( sprintf( 'term #%d (category): name, slug, description, parent', $id ) === $c['entry']['summary'], 'C4 the summary names the fields, no values' );
$out = ab_undo( $res['undo'] );
$t   = get_term( $id, 'category' );
ok( 1 === $out['undone'] && 'Old\\ name' === $t->name && 'old-slug' === $t->slug && "Old\\ description" === $t->description && $p0 === $t->parent, 'C5 wp_undo puts the four back, a backslash included' );

reset_all();
$id  = ab_add_term( 'category', 'A' );
$res = AB_MCP_Tools_Taxonomy_Comments::update_term( array( 'taxonomy' => 'category', 'term_id' => $id, 'name' => 'B' ) );
wp_update_term( $id, 'category', array( 'name' => 'C' ) );
$out = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && 'changed_since' === $out['blocked'][0]['reason'] && 'C' === get_term( $id, 'category' )->name, 'C6 a term changed since is not thrown back' );
$out = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 1 === $out['undone'] && 'A' === get_term( $id, 'category' )->name, 'C7 with force it is' );

reset_all();
$id  = ab_add_term( 'category', 'A' );
$res = AB_MCP_Tools_Taxonomy_Comments::update_term( array( 'taxonomy' => 'category', 'term_id' => $id, 'name' => 'B' ) );
wp_update_term( $id, 'category', array( 'name' => 'A' ) );
ok( 1 === ab_undo( $res['undo'] )['undone'], 'C8 a term that holds its old fields again: restored, nothing to do' );

reset_all();
$id   = ab_add_term( 'category', 'A' );
$res  = AB_MCP_Tools_Taxonomy_Comments::update_term( array( 'taxonomy' => 'category', 'term_id' => $id, 'name' => 'B' ) );
$plan = AB_MCP_Undo_Terms::plan( 'wp_update_term', AB_MCP_Undo::claim( $res['undo'] )['data'], false );
wp_update_term( $id, 'category', array( 'name' => 'Between' ) ); // between the plan and the write
$again = AB_MCP_Undo_Terms::restore( $plan['items'][0] );
ok( 'ab_mcp_undo_term_changed' === ab_code( $again ) && 'Between' === get_term( $id, 'category' )->name && false !== strpos( ab_msg( $again ), 'force=true' ), 'C8a the write compares once more right before it writes, and names force=true' );

reset_all();
$id  = ab_add_term( 'category', 'A', 'a' );
$res = AB_MCP_Tools_Taxonomy_Comments::update_term( array( 'taxonomy' => 'category', 'term_id' => $id, 'slug' => 'b' ) );
ab_add_term( 'category', 'Other', 'a' );
$out = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && 1 === count( $out['failed'] ) && false !== strpos( $out['failed'][0]['reason'], 'slug' ) && false !== strpos( $out['failed'][0]['reason'], 'wp_get_term' ) && null !== ab_entry( $res['undo'] ), 'C9 a slug taken since: failed with WordPress\' reason and the way, the point stays' );

reset_all();
$id  = ab_add_term( 'category', 'Same' );
$res = AB_MCP_Tools_Taxonomy_Comments::update_term( array( 'taxonomy' => 'category', 'term_id' => $id, 'name' => 'Same' ) );
ok( null === $res['undo'] && false !== strpos( $res['undo_note'], 'nothing changed' ) && 0 === ab_points(), 'C10 the fields it held already: no point, the note says why' );

reset_all();
$id  = ab_add_term( 'category', 'Tom &amp; Jerry' );
$res = AB_MCP_Tools_Taxonomy_Comments::update_term( array( 'taxonomy' => 'category', 'term_id' => $id, 'name' => 'Tom & Jerry' ) );
ok( is_array( $res ) && array( 'name' ) === $res['saved_differs'] && null === $res['undo'], 'C11 the same name in the form WordPress stores it: no error, the difference named' );

reset_all();
$id                      = ab_add_term( 'category', 'Kept' );
$GLOBALS['ab_term_veto'] = true;
$res                     = AB_MCP_Tools_Taxonomy_Comments::update_term( array( 'taxonomy' => 'category', 'term_id' => $id, 'name' => 'Refused' ) );
ok( 'ab_mcp_not_saved' === ab_code( $res ) && false !== strpos( ab_msg( $res ), 'name' ) && false !== strpos( ab_msg( $res ), 'wp_get_term' ) && 0 === ab_points(), 'C12 a change WordPress did not store: an error naming the field and the reader, no point' );

reset_all();
$res = AB_MCP_Tools_Taxonomy_Comments::update_term( array( 'taxonomy' => 'category', 'term_id' => 999, 'name' => 'X' ) );
ok( 'invalid_term' === ab_code( $res ) && 0 === ab_points(), 'C13 an id of no term: WordPress\' refusal, no point' );

reset_all();
$id  = ab_add_term( 'category', 'A' );
$res = AB_MCP_Tools_Taxonomy_Comments::update_term( array( 'taxonomy' => 'category', 'term_id' => $id, 'name' => 'B' ) );
$GLOBALS['ab_term_veto'] = true;
$out = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && 1 === count( $out['failed'] ) && false !== strpos( $out['failed'][0]['reason'], 'holds something else for: name' ) && null !== ab_entry( $res['undo'] ), 'C14 a write back WordPress reports and does not store is read again: failed, the point stays' );

reset_all();
$id  = ab_add_term( 'category', 'Named', 'other' );
$res = AB_MCP_Tools_Taxonomy_Comments::update_term( array( 'taxonomy' => 'category', 'term_id' => $id, 'slug' => '' ) );
ok( 'named' === get_term( $id, 'category' )->slug && array() === (array) $res['saved_fields'] && ! isset( $res['saved_differs'] ) && ab_token( $res ), 'C15 an empty slug asks WordPress to derive one: no difference' );

reset_all();
$res = AB_MCP_Tools_Taxonomy_Comments::create_term( array( 'taxonomy' => 'category', 'name' => 'Stays' ) );
$GLOBALS['ab_delete_lies'] = true;
$out = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && false !== strpos( $out['failed'][0]['reason'], 'wp_get_term' ) && null !== ab_entry( $res['undo'] ), 'C16 a deletion WordPress reports and does not make is read again: failed, the point stays' );

/* ============================== D) rights and the form, asked again */

reset_all();
$res = AB_MCP_Tools_Taxonomy_Comments::create_term( array( 'taxonomy' => 'category', 'name' => 'R' ) );
$GLOBALS['ab_deny']['manage_categories'] = true;
ok( 'ab_mcp_undo_forbidden' === ab_code( ab_undo( $res['undo'] ) ), 'D1 the capability of the tool (manage_categories) is asked again' );
$GLOBALS['ab_deny'] = array( 'delete_term:' . $res['term_id'] => true );
$out = ab_undo( $res['undo'] );
ok( 'forbidden' === $out['blocked'][0]['reason'] && null !== get_term( $res['term_id'], 'category' ), 'D2 the right to delete this one term (delete_term)' );
$GLOBALS['ab_deny'] = array();
update_option( 'default_category', $res['term_id'] );
ok( 'protected_now' === ab_undo( $res['undo'] )['blocked'][0]['reason'], 'D3 a term that became the default term is kept' );

reset_all();
$id  = ab_add_term( 'category', 'A' );
$res = AB_MCP_Tools_Taxonomy_Comments::update_term( array( 'taxonomy' => 'category', 'term_id' => $id, 'name' => 'B' ) );
$GLOBALS['ab_deny'] = array( 'edit_term:' . $id => true );
ok( 'forbidden' === ab_undo( $res['undo'] )['blocked'][0]['reason'] && 'B' === get_term( $id, 'category' )->name, 'D4 the right to edit this one term (edit_term)' );
$GLOBALS['ab_deny'] = array();
wp_delete_term( $id, 'category' );
ok( 'gone' === ab_undo( $res['undo'] )['blocked'][0]['reason'], 'D5 a term deleted since' );

reset_all();
$menu = ab_add_term( 'nav_menu', 'Main' );
$cat  = ab_add_term( 'category', 'Cat' );
$hash = str_repeat( 'a', 64 );
$t    = ab_forge( 'wp_create_term', 'term', array( 'v' => 1, 'id' => $menu, 'taxonomy' => 'nav_menu', 'after' => array( 'term' => $hash, 'use' => $hash ) ) );
$GLOBALS['ab_deny'] = array( 'edit_theme_options' => true );
$out = ab_undo( $t, array( 'force' => true ) );
ok( 0 === $out['undone'] && 'forbidden' === $out['blocked'][0]['reason'] && null !== get_term( $menu, 'nav_menu' ), 'D6 a hand-edited point of wp_create_term naming a menu: the menu\'s right (edit_theme_options) is asked, the menu stays' );
$GLOBALS['ab_deny'] = array();
$t   = ab_forge( 'wp_create_term', 'term', array( 'v' => 1, 'id' => $cat, 'taxonomy' => 'Category', 'after' => array( 'term' => $hash, 'use' => $hash ) ) );
$out = ab_undo( $t, array( 'force' => true ) );
ok( 1 === $out['undone'] && null !== get_term( $cat, 'category' ), 'D7 another spelling of the taxonomy reaches no row: nothing is deleted' );
$t = ab_forge( 'wp_create_menu', 'term', array( 'v' => 1, 'id' => $cat, 'taxonomy' => 'category', 'after' => array( 'term' => $hash, 'use' => $hash ) ) );
ok( 'ab_mcp_undo_corrupt' === ab_code( ab_undo( $t, array( 'force' => true ) ) ) && null !== get_term( $cat, 'category' ), 'D8 a point of wp_create_menu naming a category is refused whole' );

reset_all();
$id   = ab_add_term( 'category', 'A' );
$res  = AB_MCP_Tools_Taxonomy_Comments::update_term( array( 'taxonomy' => 'category', 'term_id' => $id, 'name' => 'B' ) );
$good = AB_MCP_Undo::claim( $res['undo'] )['data'];
$cres = AB_MCP_Tools_Taxonomy_Comments::create_term( array( 'taxonomy' => 'category', 'name' => 'N' ) );
$cgood = AB_MCP_Undo::claim( $cres['undo'] )['data'];
ok( AB_MCP_Undo_Terms::valid( 'wp_update_term', $good ) && AB_MCP_Undo_Terms::valid( 'wp_create_term', $cgood ), 'D9 the snapshots the tools write have the form' );
$bad = array(
	'update without fields'   => array( 'wp_update_term', array_diff_key( $good, array( 'fields' => 1 ) ) ),
	'update with use'         => array( 'wp_update_term', array_merge( $good, array( 'after' => $good['after'] + array( 'use' => $hash ) ) ) ),
	'update with a key more'  => array( 'wp_update_term', $good + array( 'menu' => 1 ) ),
	'a field more'            => array( 'wp_update_term', array_merge( $good, array( 'fields' => $good['fields'] + array( 'term_group' => '0' ) ) ) ),
	'a parent not a number'   => array( 'wp_update_term', array_merge( $good, array( 'fields' => array_merge( $good['fields'], array( 'parent' => '1x' ) ) ) ) ),
	'a name not text'         => array( 'wp_update_term', array_merge( $good, array( 'fields' => array_merge( $good['fields'], array( 'name' => 5 ) ) ) ) ),
	'create with fields'      => array( 'wp_create_term', $cgood + array( 'fields' => $good['fields'] ) ),
	'create without use'      => array( 'wp_create_term', array_merge( $cgood, array( 'after' => array( 'term' => $hash ) ) ) ),
	'after not a hash'        => array( 'wp_create_term', array_merge( $cgood, array( 'after' => array( 'term' => 'absent', 'use' => $hash ) ) ) ),
	'id as text'              => array( 'wp_create_term', array_merge( $cgood, array( 'id' => (string) $cgood['id'] ) ) ),
	'id 0'                    => array( 'wp_create_term', array_merge( $cgood, array( 'id' => 0 ) ) ),
	'version 2'               => array( 'wp_create_term', array_merge( $cgood, array( 'v' => 2 ) ) ),
	'no taxonomy'             => array( 'wp_create_term', array_merge( $cgood, array( 'taxonomy' => '' ) ) ),
	'a tool of another kind'  => array( 'wp_update_post_meta', $cgood ),
);
foreach ( $bad as $why => $case ) {
	ok( false === AB_MCP_Undo_Terms::valid( $case[0], $case[1] ) && 'ab_mcp_undo_corrupt' === ab_code( AB_MCP_Undo_Terms::plan( $case[0], $case[1], true ) ), "D10 refused whole: $why" );
}
$menu = ab_add_term( 'nav_menu', 'Main' );
$res  = AB_MCP_Tools_Taxonomy_Comments::update_term( array( 'taxonomy' => 'nav_menu', 'term_id' => $menu, 'name' => 'Renamed' ) );
$GLOBALS['ab_deny'] = array( 'edit_theme_options' => true );
ok( 'forbidden' === ab_undo( $res['undo'] )['blocked'][0]['reason'] && 'Renamed' === get_term( $menu, 'nav_menu' )->name, 'D12 the taxonomy\'s right to edit its terms is asked: a menu renamed with wp_update_term needs edit_theme_options back' );
$GLOBALS['ab_deny'] = array();
ok( 'ab_mcp_undo_kind_mismatch' === ab_code( AB_MCP_Undo_Restore::plan( array_merge( ab_entry( $res['undo'] ), array( 'kind' => 'widget' ) ), $good ) ), 'D11 a term tool with another kind is refused' );

/* ============================== E) menus and their items */

reset_all();
$res = AB_MCP_Tools_Menus_Users::create_menu( array( 'name' => 'Main' ) );
ok( true === $res['created'] && ab_token( $res ) && array( 'name' => 'Main' ) === (array) $res['saved_fields'], 'E1 wp_create_menu answers with the name read again and a token' );
$c = AB_MCP_Undo::claim( $res['undo'] );
ok( 'wp_create_menu' === $c['entry']['tool'] && 'term' === $c['entry']['kind'] && 'edit_theme_options' === $c['entry']['cap'] && 'nav_menu' === $c['data']['taxonomy'], 'E2 its point: a term of nav_menu, edit_theme_options' );
ok( 1 === ab_undo( $res['undo'] )['undone'] && false === wp_get_nav_menu_object( 'Main' ), 'E3 wp_undo deletes the menu again' );

reset_all();
$res  = AB_MCP_Tools_Menus_Users::create_menu( array( 'name' => 'Main' ) );
$item = AB_MCP_Tools_Menus_Users::add_menu_item( array( 'menu' => 'Main', 'title' => 'Home', 'url' => 'https://example.test/' ) );
$out  = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && 'changed_since' === $out['blocked'][0]['reason'] && array( 'use' ) === $out['blocked'][0]['locs'] && false !== wp_get_nav_menu_object( 'Main' ), 'E4 a menu with an item added since is not deleted' );
$out = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 1 === $out['undone'] && false === wp_get_nav_menu_object( 'Main' ) && null === get_post( $item['item_id'] ), 'E5 with force the menu goes, with its items, as wp-admin deletes it' );

// Codex round 4: an item a plugin keeps from deletion.
reset_all();
$res                      = AB_MCP_Tools_Menus_Users::create_menu( array( 'name' => 'Main' ) );
$item                     = AB_MCP_Tools_Menus_Users::add_menu_item( array( 'menu' => 'Main', 'title' => 'Home', 'url' => 'https://example.test/' ) );
$keep                     = AB_MCP_Tools_Menus_Users::add_menu_item( array( 'menu' => 'Main', 'title' => 'Kept', 'url' => 'https://example.test/k' ) );
$GLOBALS['ab_keep_posts'] = array( (int) $keep['item_id'] );
$out                      = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 0 === $out['undone'] && 1 === count( $out['failed'] ) && false !== strpos( $out['failed'][0]['reason'], (string) $keep['item_id'] ) && false !== wp_get_nav_menu_object( 'Main' ) && null !== get_post( $keep['item_id'] ) && null !== ab_entry( $res['undo'] ), 'E5a an item that stays: the menu is kept, the item named, the point stays' );
$GLOBALS['ab_keep_posts'] = array();
$out                      = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 1 === $out['undone'] && false === wp_get_nav_menu_object( 'Main' ) && null === get_post( $keep['item_id'] ) && null === get_post( $item['item_id'] ), 'E5b once it may go, the same token deletes it and the menu' );

reset_all();
$res                     = AB_MCP_Tools_Menus_Users::create_menu( array( 'name' => 'Main' ) );
$GLOBALS['ab_locations'] = array( 'primary' => $res['menu_id'] );
ok( 'changed_since' === ab_undo( $res['undo'] )['blocked'][0]['reason'], 'E6 a menu put into a theme location since is not deleted' );
$GLOBALS['ab_locations'] = array();
wp_update_term( $res['menu_id'], 'nav_menu', array( 'name' => 'Renamed' ) );
ok( array( 'term' ) === ab_undo( $res['undo'] )['blocked'][0]['locs'], 'E7 nor a menu renamed since' );

reset_all();
ab_add_post( 12, array( 'post_title' => 'About' ) );
$menu = AB_MCP_Tools_Menus_Users::create_menu( array( 'name' => 'Main' ) );
$res  = AB_MCP_Tools_Menus_Users::add_menu_item( array( 'menu' => 'Main', 'title' => 'C:\\Docs', 'url' => 'https://example.test/docs' ) );
ok( true === $res['added'] && ab_token( $res ), 'E8 wp_add_menu_item answers with a token' );
ok( array( 'title' => 'C:\\Docs', 'parent' => 0, 'url' => 'https://example.test/docs' ) === (array) $res['saved_fields'] && ! isset( $res['saved_differs'] ), 'E9 saved_fields: title, parent and address as wp_get_menu shows them' );
$c = AB_MCP_Undo::claim( $res['undo'] );
ok( 'wp_add_menu_item' === $c['entry']['tool'] && 'menu_item' === $c['entry']['kind'] && 'edit_theme_options' === $c['entry']['cap'] && sprintf( 'menu item #%d in menu #%d', $res['item_id'], $menu['menu_id'] ) === $c['entry']['summary'], 'E10 its point: kind menu_item, edit_theme_options, ids in the summary' );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && null === get_post( $res['item_id'] ) && array() === array_filter( $GLOBALS['T']['postmeta'], static fn( $r ) => $r[0] === $res['item_id'] ), 'E11 wp_undo deletes the item with its meta' );
ok( 1 === ab_undo( $menu['undo'] )['undone'], 'E12 and then the menu, now empty again' );

reset_all();
ab_add_post( 12, array( 'post_title' => 'About' ) );
AB_MCP_Tools_Menus_Users::create_menu( array( 'name' => 'Main' ) );
$res = AB_MCP_Tools_Menus_Users::add_menu_item( array( 'menu' => 'Main', 'title' => 'About', 'object_id' => 12 ) );
ok( array( 'title' => 'About', 'parent' => 0 ) === (array) $res['saved_fields'] && array( 'object_id' => 'not_shown', 'object' => 'not_shown' ) === $res['saved_fields_omitted'] && ! isset( $res['saved_differs'] ), 'E13 a link to a page: the title from the page, the target compared and not shown' );

reset_all();
AB_MCP_Tools_Menus_Users::create_menu( array( 'name' => 'Main' ) );
$res = AB_MCP_Tools_Menus_Users::add_menu_item( array( 'menu' => 'Main', 'title' => 'X', 'url' => 'https://example.test/' ) );
$GLOBALS['T']['posts'][ $res['item_id'] ]['post_title'] = 'Changed';
$out = ab_undo( $res['undo'] );
ok( 'changed_since' === $out['blocked'][0]['reason'] && array( 'item' ) === $out['blocked'][0]['locs'] && null !== get_post( $res['item_id'] ), 'E14 an item changed since is not deleted' );
$GLOBALS['T']['posts'][ $res['item_id'] ]['post_title'] = 'X';
foreach ( $GLOBALS['T']['postmeta'] as $mid => $r ) {
	if ( $r[0] === $res['item_id'] && '_menu_item_url' === $r[1] ) { $url_row = $mid; }
}
$GLOBALS['T']['postmeta'][ $url_row ][2] = 'https://example.test/changed';
ok( array( 'item' ) === ab_undo( $res['undo'] )['blocked'][0]['locs'], 'E14a nor one whose address changed since' );
$GLOBALS['T']['postmeta'][ $url_row ][2] = 'https://example.test/';
$other = AB_MCP_Tools_Menus_Users::create_menu( array( 'name' => 'Other' ) );
foreach ( $GLOBALS['T']['rel'] as $i => $r ) {
	if ( $r[0] === $res['item_id'] ) { $GLOBALS['T']['rel'][ $i ][1] = ab_tt_of( $other['menu_id'] ); }
}
ok( array( 'item' ) === ab_undo( $res['undo'] )['blocked'][0]['locs'], 'E14b nor one moved to another menu since' );
foreach ( $GLOBALS['T']['rel'] as $i => $r ) {
	if ( $r[0] === $res['item_id'] ) { $GLOBALS['T']['rel'][ $i ][1] = ab_tt_of( wp_get_nav_menu_object( 'Main' )->term_id ); }
}
$sub = AB_MCP_Tools_Menus_Users::add_menu_item( array( 'menu' => 'Main', 'title' => 'Sub', 'url' => '/sub', 'parent' => $res['item_id'] ) );
$out = ab_undo( $res['undo'] );
ok( array( 'use' ) === $out['blocked'][0]['locs'], 'E15 nor one given a sub-item since' );
ok( 1 === ab_undo( $res['undo'], array( 'force' => true ) )['undone'] && null === get_post( $res['item_id'] ) && null !== get_post( $sub['item_id'] ), 'E16 with force it goes; the sub-item stays, as wp_delete_menu_item leaves it' );

reset_all();
AB_MCP_Tools_Menus_Users::create_menu( array( 'name' => 'Main' ) );
$res = AB_MCP_Tools_Menus_Users::add_menu_item( array( 'menu' => 'Main', 'title' => 'X', 'url' => '/x' ) );
$GLOBALS['ab_deny'] = array( 'delete_post:' . $res['item_id'] => true );
ok( 'forbidden' === ab_undo( $res['undo'] )['blocked'][0]['reason'] && null !== get_post( $res['item_id'] ), 'E17 the right to delete this one item (delete_post) is asked again' );
$GLOBALS['ab_deny'] = array( 'edit_theme_options' => true );
ok( 'ab_mcp_undo_forbidden' === ab_code( ab_undo( $res['undo'] ) ), 'E18 and edit_theme_options, the capability of the tool' );
$GLOBALS['ab_deny'] = array();
wp_delete_post( $res['item_id'], true );
ok( 1 === ab_undo( $res['undo'] )['undone'], 'E19 an item deleted since: nothing to do' );

reset_all();
$menu = AB_MCP_Tools_Menus_Users::create_menu( array( 'name' => 'Main' ) );
$res  = AB_MCP_Tools_Menus_Users::add_menu_item( array( 'menu' => 'Main', 'title' => 'X', 'url' => '/x' ) );
$GLOBALS['ab_delete_lies'] = true;
$out = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && false !== strpos( $out['failed'][0]['reason'], 'wp_get_menu' ) && null !== ab_entry( $res['undo'] ), 'E23 a deletion WordPress reports and does not make is read again: failed, the point stays' );
$GLOBALS['ab_delete_lies'] = false;
$GLOBALS['ab_deny'] = array( 'edit_theme_options' => true );
ok( 'ab_mcp_undo_forbidden' === ab_code( ab_undo( $menu['undo'] ) ), 'E24 a new menu: edit_theme_options, the capability of wp_create_menu, is asked again' );
$GLOBALS['ab_deny'] = array();

reset_all();
ab_add_post( 40, array( 'post_type' => 'page' ) );
$t   = ab_forge( 'wp_add_menu_item', 'menu_item', array( 'v' => 1, 'id' => 40, 'after' => array( 'item' => $hash, 'use' => $hash ) ) );
$out = ab_undo( $t, array( 'force' => true ) );
ok( 0 === $out['undone'] && 'gone' === $out['blocked'][0]['reason'] && null !== get_post( 40 ), 'E20 a hand-edited point naming a page, not a menu item: nothing is deleted, even with force' );
foreach ( array(
	'id as text' => array( 'v' => 1, 'id' => '40', 'after' => array( 'item' => $hash, 'use' => $hash ) ),
	'no use'     => array( 'v' => 1, 'id' => 40, 'after' => array( 'item' => $hash ) ),
	'a key more' => array( 'v' => 1, 'id' => 40, 'menu' => 3, 'after' => array( 'item' => $hash, 'use' => $hash ) ),
	'version 0'  => array( 'v' => 0, 'id' => 40, 'after' => array( 'item' => $hash, 'use' => $hash ) ),
) as $why => $data ) {
	ok( false === AB_MCP_Undo_Menu_Items::valid( 'wp_add_menu_item', $data ) && 'ab_mcp_undo_corrupt' === ab_code( AB_MCP_Undo_Menu_Items::plan( 'wp_add_menu_item', $data, true ) ), "E21 refused whole: $why" );
}
ok( false === AB_MCP_Undo_Menu_Items::valid( 'wp_create_menu', array( 'v' => 1, 'id' => 40, 'after' => array( 'item' => $hash, 'use' => $hash ) ) ), 'E22 only wp_add_menu_item records this kind' );

/* ============================== F) widgets: the option rows byte for byte */

function ab_widgets_typed() {
	update_option( 'widget_text', array( 2 => array( 'title' => 'Hello', 'count' => 5 ), '_multiwidget' => 1 ) );
	update_option( 'sidebars_widgets', array( 'sidebar-1' => array( 'text-2' ), 'array_version' => 3 ) );
}

function ab_widgets() {
	update_option( 'widget_text', array( 2 => array( 'title' => 'Hello', 'text' => 'Body' ), 3 => array( 'title' => 'Other' ), '_multiwidget' => 1 ) );
	update_option( 'widget_feed', array( 2 => array( 'title' => 'Feed', 'api_key' => 'GEHEIM-KEY', 'auth' => array( 'client_secret' => 'GEHEIM-SECRET', 'user' => 'u' ) ), '_multiwidget' => 1 ) );
	update_option( 'sidebars_widgets', array( 'sidebar-1' => array( 'text-2', 'feed-2' ), 'footer' => array( 'text-3' ), 'wp_inactive_widgets' => array(), 'array_version' => 3 ) );
}

reset_all();
ab_widgets();
$before = ab_raw( 'widget_text' );
$res    = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'title' => 'New', 'count' => 5 ) ) );
ok( true === $res['updated'] && ab_token( $res ), 'F1 wp_update_widget answers with a token' );
ok( array( 'title' => 'New', 'count' => 5 ) === (array) $res['saved_fields'] && ! isset( $res['saved_differs'] ), 'F2 saved_fields: the fields sent, read again' );
$e = ab_entry( $res['undo'] );
ok( null !== $e['inline'] && null === $e['payload'], 'F2a a small snapshot of the widgets stays inline in the index (a database kind)' );
$c = AB_MCP_Undo::claim( $res['undo'] );
ok( 'wp_update_widget' === $c['entry']['tool'] && 'widget' === $c['entry']['kind'] && 'edit_theme_options' === $c['entry']['cap'] && 'widget text-2' === $c['entry']['summary'], 'F3 kind widget, edit_theme_options, the widget id in the summary' );
ok( 'text' === $c['data']['base'] && array( 'widget_text' ) === array_keys( $c['data']['rows'] ) && $before === $c['data']['rows']['widget_text']['value'] && array( 'option:widget_text' ) === array_keys( $c['data']['after'] ), 'F4 an update keeps the row of its type, as stored' );
ab_undo( $res['undo'] );
ok( $before === ab_raw( 'widget_text' ), 'F5 wp_undo puts the row back byte for byte' );
ok( in_array( 'options:widget_text', $GLOBALS['ab_cache_deleted'], true ) && in_array( 'options:alloptions', $GLOBALS['ab_cache_deleted'], true ), 'F6 ... and drops the cached copies of the options' );

reset_all();
ab_widgets();
$text = ab_raw( 'widget_text' );
$map  = ab_raw( 'sidebars_widgets' );
$res  = AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'text', 'sidebar' => 'footer', 'settings' => array( 'title' => '<b>Bold</b>' ) ) );
ok( 'text-4' === $res['id'] && ab_token( $res ) && array( 'title' => '<b>Bold</b>' ) === (array) $res['saved_fields'], 'F7 wp_add_widget answers with the settings read again and a token' );
$c = AB_MCP_Undo::claim( $res['undo'] );
ok( array( 'widget_text', 'sidebars_widgets' ) === array_keys( $c['data']['rows'] ) && 'new widget text-4 in footer' === $c['entry']['summary'], 'F8 adding keeps both rows' );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && $text === ab_raw( 'widget_text' ) && $map === ab_raw( 'sidebars_widgets' ), 'F10 wp_undo takes the widget out of both rows, byte for byte' );
$order = array_values( array_filter( $GLOBALS['wpdb']->writes, static fn( $w ) => in_array( $w[1], array( 'widget_text', 'sidebars_widgets' ), true ) ) );
ok( array( array( 'update', 'sidebars_widgets' ), array( 'update', 'widget_text' ) ) === $order, 'F10a the map lets go of the widget before its settings go' );

reset_all();
ab_widgets();
$GLOBALS['ab_deny']['unfiltered_html'] = true;
$res = AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'text', 'sidebar' => 'footer', 'settings' => array( 'title' => '<b>Bold</b>' ) ) );
ok( array( 'title' => 'Bold' ) === (array) $res['saved_fields'] && array( 'title' ) === $res['saved_differs'] && false !== strpos( $res['warning'], 'wp_get_widgets' ) && ab_token( $res ), 'F11 settings the widget stored otherwise: shown and named, with a token' );
$GLOBALS['ab_deny'] = array();

reset_all();
$map = ab_raw( 'sidebars_widgets' );
update_option( 'sidebars_widgets', array( 'sidebar-1' => array(), 'array_version' => 3 ) );
$map = ab_raw( 'sidebars_widgets' );
$res = AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'text', 'sidebar' => 'sidebar-1', 'settings' => array( 'title' => 'First' ) ) );
ok( array( 'existed' => false ) === AB_MCP_Undo::claim( $res['undo'] )['data']['rows']['widget_text'], 'F12 the first widget of a type: its row was not there' );
ok( 1 === ab_undo( $res['undo'] )['undone'] && null === ab_raw( 'widget_text' ) && $map === ab_raw( 'sidebars_widgets' ), 'F13 and the undo deletes the row again, and the map entry' );

reset_all();
ab_widgets();
$text = ab_raw( 'widget_text' );
$map  = ab_raw( 'sidebars_widgets' );
$res  = AB_MCP_Tools_Widgets::delete_widget( array( 'id' => 'text-2' ) );
ok( true === $res['deleted'] && ab_token( $res ) && ! isset( $res['saved_fields'] ), 'F14 wp_delete_widget answers with a token' );
ok( 'deleted widget text-2' === AB_MCP_Undo::claim( $res['undo'] )['entry']['summary'], 'F15 its summary' );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && $text === ab_raw( 'widget_text' ) && $map === ab_raw( 'sidebars_widgets' ), 'F16 wp_undo brings the widget back, its settings and its place' );
$order = array_values( array_filter( $GLOBALS['wpdb']->writes, static fn( $w ) => in_array( $w[1], array( 'widget_text', 'sidebars_widgets' ), true ) ) );
ok( array( array( 'update', 'widget_text' ), array( 'update', 'sidebars_widgets' ) ) === $order, 'F17 the settings go back before the map that names the widget' );

reset_all();
ab_widgets();
$res = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'title' => 'New' ) ) );
update_option( 'widget_text', array( 2 => array( 'title' => 'New' ), 3 => array( 'title' => 'Edited in wp-admin' ), '_multiwidget' => 1 ) );
$out = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && 'changed_since' === $out['blocked'][0]['reason'] && array( 'option:widget_text' ) === $out['blocked'][0]['locs'] && null !== ab_entry( $res['undo'] ), 'F18 a row changed since (another widget of the type) is not thrown back' );
ok( 1 === ab_undo( $res['undo'], array( 'force' => true ) )['undone'] && 'Hello' === get_option( 'widget_text' )[2]['title'], 'F19 with force it is' );

reset_all();
ab_widgets();
$res = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'title' => 'Hello' ) ) );
ok( null === $res['undo'] && false !== strpos( $res['undo_note'], 'nothing changed' ) && 0 === ab_points(), 'F20 settings it held already: no point, the note says why' );

reset_all();
ab_widgets_typed();
$res = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'count' => '7' ) ) );
ok( array( 'count' => 7 ) === (array) $res['saved_fields'] && ! isset( $res['saved_differs'] ), 'F20a a number sent as text and kept as a number is no difference' );

reset_all();
ab_widgets();
$res = AB_MCP_Tools_Widgets::delete_widget( array( 'id' => 'text-2' ) );
$GLOBALS['_wp_sidebars_widgets'] = array( 'stale' => true );
ab_undo( $res['undo'] );
ok( null === $GLOBALS['_wp_sidebars_widgets'], 'F20b the request\'s copy of the map is dropped after the map is written back' );

reset_all();
ab_widgets();
$GLOBALS['T']['options']['widget_text'][1] = 'no';
$before = ab_raw( 'widget_text' );
$res    = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'title' => 'New' ) ) );
delete_option( 'widget_text' );
$out = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 1 === $out['undone'] && $before === ab_raw( 'widget_text' ) && 'no' === $GLOBALS['T']['options']['widget_text'][1], 'F20c a row deleted since comes back with its bytes and its autoload flag' );

reset_all();
ab_widgets();
$res  = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'shape' => 'round' ) ) );
ok( array( 'shape' => 'not_shown' ) === $res['saved_fields_omitted'] && array( 'shape' ) === $res['saved_differs'] && ab_token( $res ), 'F20d a value the widget keeps as an object: compared, named as different, not shown, and the answer comes' );

// Codex round 2: a sidebar with an older widget, registered without a number.
reset_all();
update_option( 'widget_text', array( 2 => array( 'title' => 'Hello' ), '_multiwidget' => 1 ) );
update_option( 'sidebars_widgets', array( 'sidebar-1' => array( 'legacy_weather', 'text-2' ), 'wp_inactive_widgets' => array(), 'array_version' => 3 ) );
$map = ab_raw( 'sidebars_widgets' );
$res = AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'text', 'sidebar' => 'sidebar-1', 'settings' => array( 'title' => 'New' ) ) );
$out = ab_undo( $res['undo'] );
ok( is_array( $out ) && 1 === $out['undone'] && $map === ab_raw( 'sidebars_widgets' ) && in_array( 'legacy_weather', get_option( 'sidebars_widgets' )['sidebar-1'], true ), 'F20e a sidebar with an older widget without a number: the undo goes through, the older widget stays' );
$res = AB_MCP_Tools_Widgets::delete_widget( array( 'id' => 'text-2' ) );
ok( 1 === ab_undo( $res['undo'] )['undone'] && $map === ab_raw( 'sidebars_widgets' ), 'F20f ... and a deletion comes back there too' );

reset_all();
update_option( 'widget_text', array( 2 => array( 'title' => 'Hello' ), '_multiwidget' => 1 ) );
update_option( 'sidebars_widgets', array( 'sidebar-1' => array( 'counter-007', 'text-2' ), 'array_version' => 3 ) );
$res = AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'text', 'sidebar' => 'sidebar-1' ) );
ok( 1 === ab_undo( $res['undo'] )['undone'], 'F20i an older widget whose id ends in digits with a leading zero is another type\'s: no obstacle' );

// Codex round 4: a sidebar registered with a numeric id is a whole-number key.
reset_all();
update_option( 'widget_text', array( 2 => array( 'title' => 'Hello' ), '_multiwidget' => 1 ) );
update_option( 'sidebars_widgets', array( '123' => array( 'text-2' ), 'empty-one' => array(), 'array_version' => 3 ) );
$map = ab_raw( 'sidebars_widgets' );
$res = AB_MCP_Tools_Widgets::delete_widget( array( 'id' => 'text-2' ) );
ok( 1 === ab_undo( $res['undo'] )['undone'] && $map === ab_raw( 'sidebars_widgets' ) && array( 'text-2' ) === get_option( 'sidebars_widgets' )[123], 'F20n a sidebar with a numeric id: the deletion comes back' );

// Codex round 3: any id wp_register_sidebar_widget() takes, a dot or a space in it.
foreach ( array( 'legacy.weather', 'legacy weather', 'Wetter_ä' ) as $legacy ) {
	reset_all();
	update_option( 'widget_text', array( 2 => array( 'title' => 'Hello' ), '_multiwidget' => 1 ) );
	update_option( 'sidebars_widgets', array( 'sidebar-1' => array( $legacy, 'text-2' ), 'wp_inactive_widgets' => array(), 'array_version' => 3 ) );
	$map = ab_raw( 'sidebars_widgets' );
	$res = AB_MCP_Tools_Widgets::delete_widget( array( 'id' => 'text-2' ) );
	$out = ab_undo( $res['undo'] );
	ok( is_array( $out ) && 1 === $out['undone'] && $map === ab_raw( 'sidebars_widgets' ), "F20j an older widget \"$legacy\" in the sidebar: the deletion comes back" );
	$res = AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'text', 'sidebar' => 'sidebar-1' ) );
	ok( 1 === ab_undo( $res['undo'] )['undone'] && $map === ab_raw( 'sidebars_widgets' ), "F20k ... and so does an addition" );
}

// Codex round 3: the flag comes back with the bytes.
reset_all();
ab_widgets();
$GLOBALS['T']['options']['widget_text'][1] = 'auto-off';
$before = ab_raw( 'widget_text' );
$res    = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'title' => 'New' ) ) );
$GLOBALS['T']['options']['widget_text'][1] = 'auto'; // core rates the flag of the smaller value again
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && $before === ab_raw( 'widget_text' ) && 'auto-off' === $GLOBALS['T']['options']['widget_text'][1], 'F20l a row whose flag core changed with the value comes back with its bytes and its flag as stored' );
reset_all();
ab_widgets();
$GLOBALS['T']['options']['sidebars_widgets'][1] = 'off';
$res = AB_MCP_Tools_Widgets::delete_widget( array( 'id' => 'text-2' ) );
$GLOBALS['T']['options']['sidebars_widgets'][1] = 'yes';
ok( 1 === ab_undo( $res['undo'] )['undone'] && 'off' === $GLOBALS['T']['options']['sidebars_widgets'][1], 'F20m ... and the map with its flag' );

// Codex round 2: no PHP object comes back.
reset_all();
ab_widgets();
$res  = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'title' => 'New' ) ) );
$data = AB_MCP_Undo::claim( $res['undo'] )['data'];
foreach ( array(
	'_multiwidget an object' => array( 2 => array( 'title' => 'Hello' ), '_multiwidget' => new ArrayObject() ),
	'_multiwidget "1"'       => array( 2 => array( 'title' => 'Hello' ), '_multiwidget' => '1' ),
	'_multiwidget 2'         => array( 2 => array( 'title' => 'Hello' ), '_multiwidget' => 2 ),
	'an object in settings'  => array( 2 => array( 'title' => 'Hello', 'x' => array( new ArrayObject() ) ), '_multiwidget' => 1 ),
) as $why => $row ) {
	$odd = $data;
	$odd['rows']['widget_text']['value'] = serialize( $row ); // phpcs:ignore
	$out = ab_undo( ab_forge( 'wp_update_widget', 'widget', $odd ), array( 'force' => true ) );
	ok( 'ab_mcp_undo_corrupt' === ab_code( $out ) && false === strpos( ab_raw( 'widget_text' ), 'ArrayObject' ), "F20g a row with $why: refused whole, nothing written" );
}
reset_all();
ab_widgets();
AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'shape' => 'round' ) ) ); // the widget keeps an object now
$res = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'title' => 'Next' ) ) );
ok( true === $res['updated'] && null === $res['undo'] && false !== strpos( $res['undo_note'], 'PHP object' ) && false !== strpos( $res['undo_note'], 'wp_get_widgets' ), 'F20h a row that holds an object before the write: no point, the note says why and where' );

/* credentials: compared, never shown */
reset_all();
ab_widgets();
$res  = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'feed-2', 'settings' => array( 'title' => 'T', 'api_key' => 'GEHEIM-NEW', 'auth' => array( 'user' => 'v' ) ) ) );
$json = (string) wp_json_encode( $res );
ok( array( 'title' => 'T' ) === (array) $res['saved_fields'] && array( 'api_key' => 'not_shown', 'auth' => 'not_shown' ) === $res['saved_fields_omitted'], 'F21 a credential and a field holding one are compared, not shown' );
ok( false === strpos( $json, 'GEHEIM' ), 'F22 no credential in the answer' );
$list = AB_MCP_Tools_Undo_Core::undo_list( array() );
$dry  = ab_undo( $res['undo'], array( 'dry_run' => true ) );
ok( 1 === $list['count'] && false === strpos( (string) wp_json_encode( $list ), 'GEHEIM' ) && false === strpos( (string) wp_json_encode( $dry ), 'GEHEIM' ), 'F23 nor in wp_undo_list or a dry run' );
update_option( 'widget_feed', array( 2 => array( 'api_key' => 'GEHEIM-LATER' ), '_multiwidget' => 1 ) );
$out = ab_undo( $res['undo'] );
ok( 'changed_since' === $out['blocked'][0]['reason'] && false === strpos( (string) wp_json_encode( $out ), 'GEHEIM' ), 'F24 nor in a refusal' );
$out = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 'GEHEIM-KEY' === get_option( 'widget_feed' )[2]['api_key'] && 'GEHEIM-SECRET' === get_option( 'widget_feed' )[2]['auth']['client_secret'] && false === strpos( (string) wp_json_encode( $out ), 'GEHEIM' ), 'F25 the restore puts the credentials back, and its answer shows none' );

/* a value wp_get_widgets shows as [REDACTED] (AB_MCP_Redactor), sent back or left out */
reset_all();
update_option( 'widget_text', array( 2 => array( 'title' => 'Feed', 'feed' => array( 'accessToken' => 'GEHEIM-AT', 'count' => 5 ) ), '_multiwidget' => 1 ) );
update_option( 'sidebars_widgets', array( 'sidebar-1' => array( 'text-2' ), 'array_version' => 3 ) );
$before = ab_raw( 'widget_text' );
$res    = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'feed' => array( 'accessToken' => '[REDACTED]', 'count' => 10 ) ) ) );
ok( ab_token( $res ) && array( 'accessToken' => 'GEHEIM-AT', 'count' => 10 ) === get_option( 'widget_text' )[2]['feed'] && ! isset( $res['saved_differs'] ), 'F25a [REDACTED] sent back: the stored token stays, the write is as asked, with a token' );
ok( $before === AB_MCP_Undo::claim( $res['undo'] )['data']['rows']['widget_text']['value'], 'F25b the point holds the row before the write' );
ok( 1 === ab_undo( $res['undo'] )['undone'] && $before === ab_raw( 'widget_text' ), 'F25c wp_undo puts it back byte for byte' );
$res = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'feed' => array( 'count' => 11 ) ) ) );
ok( ab_token( $res ) && array( 'count' => 11, 'accessToken' => 'GEHEIM-AT' ) === get_option( 'widget_text' )[2]['feed'], 'F25d left out of a nested field: kept, as a key wp_get_widgets leaves out' );

/* the rule of the tools, asked again */
reset_all();
ab_widgets();
$res = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'title' => 'New' ) ) );
$GLOBALS['ab_deny'] = array( 'edit_theme_options' => true );
ok( 'ab_mcp_undo_forbidden' === ab_code( ab_undo( $res['undo'] ) ), 'F26 edit_theme_options, the capability of the tool, is asked again' );
$GLOBALS['ab_deny'] = array();
$GLOBALS['T']['options']['widget_text'][0] = serialize( array( 2 => array( 'title' => 'New' ) ) ); // phpcs:ignore -- a row no WP_Widget wrote (no _multiwidget)
$out = ab_undo( $res['undo'], array( 'force' => true ) );
ok( 'protected_now' === $out['blocked'][0]['reason'] && false === strpos( ab_raw( 'widget_text' ), 'Hello' ), 'F27 a row the widgets of WordPress no longer own is not written, even with force' );

// Codex round 5: a key the widget gained since does not steer the check.
reset_all();
update_option( 'widget_textcore', array( '_multiwidget' => 1 ) );
update_option( 'sidebars_widgets', array( 'sidebar-1' => array(), 'array_version' => 3 ) );
$GLOBALS['ab_deny']['unfiltered_html'] = true;
$add    = AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'textcore', 'sidebar' => 'sidebar-1', 'settings' => array( 'title' => 'T', 'text' => 'Body', 'filter' => false ) ) );
$before = ab_raw( 'widget_textcore' );
$upd    = AB_MCP_Tools_Widgets::update_widget( array( 'id' => $add['id'], 'settings' => array( 'title' => 'T', 'text' => 'Body', 'visual' => true ) ) );
$out    = ab_undo( $upd['undo'] );
ok( is_array( $out ) && 1 === $out['undone'] && $before === ab_raw( 'widget_textcore' ), 'F30a a text widget switched to visual mode since comes back without it, for an account without unfiltered_html' );
$GLOBALS['ab_deny'] = array();

// Codex round 6: a field the widget works out again does not make it forbidden.
reset_all();
update_option( 'widget_rss', array( 2 => array( 'title' => 'News', 'url' => 'https://example.test/feed/?lang=de&cat=2', 'link' => 'https://example.test/' ), '_multiwidget' => 1 ) );
update_option( 'sidebars_widgets', array( 'sidebar-1' => array( 'rss-2' ), 'array_version' => 3 ) );
$GLOBALS['ab_deny']['unfiltered_html'] = true;
$before = ab_raw( 'widget_rss' );
$upd    = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'rss-2', 'settings' => array( 'title' => 'Latest' ) ) );
$out    = ab_undo( $upd['undo'] );
ok( is_array( $out ) && 1 === $out['undone'] && $before === ab_raw( 'widget_rss' ), 'F30b an RSS widget whose link its update() works out anew comes back, for an account without unfiltered_html' );
$GLOBALS['ab_deny'] = array();

/* a hand-edited point gains no right */
reset_all();
ab_widgets();
$GLOBALS['ab_deny']['unfiltered_html'] = true;
$res  = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'title' => 'New' ) ) );
$data = AB_MCP_Undo::claim( $res['undo'] )['data'];
$evil = $data;
$evil['rows']['widget_text']['value'] = serialize( array( 2 => array( 'title' => '<script>x</script>' ), 3 => array( 'title' => 'Other' ), '_multiwidget' => 1 ) ); // phpcs:ignore
$t   = ab_forge( 'wp_update_widget', 'widget', $evil );
$out = ab_undo( $t, array( 'force' => true ) );
ok( 0 === $out['undone'] && 'forbidden' === $out['blocked'][0]['reason'] && false === strpos( ab_raw( 'widget_text' ), 'script' ), 'F28 settings the widget would not store for an account without unfiltered_html are not written back' );
$keyed = $data;
$keyed['rows']['widget_text']['value'] = serialize( array( 2 => array( 'title' => '<b>Hello</b>', '<script>x</script>' => 'y' ), 3 => array( 'title' => 'Other' ), '_multiwidget' => 1 ) ); // phpcs:ignore
$out = ab_undo( ab_forge( 'wp_update_widget', 'widget', $keyed ), array( 'force' => true ) );
ok( 0 === $out['undone'] && 'forbidden' === $out['blocked'][0]['reason'] && false === strpos( ab_raw( 'widget_text' ), 'script' ), 'F28c nor markup in a key, where the texts alone would pass' );
$odd = $data;
$odd['rows']['widget_text']['value'] = serialize( array( 2 => 'not settings', 3 => array( 'title' => 'Other' ), '_multiwidget' => 1 ) ); // phpcs:ignore
$out = ab_undo( ab_forge( 'wp_update_widget', 'widget', $odd ), array( 'force' => true ) );
ok( 'ab_mcp_undo_corrupt' === ab_code( $out ) && false === strpos( ab_raw( 'widget_text' ), 'not settings' ), 'F28a settings that are no list: the point is refused whole' );
// Codex 31.10.: an instance number WordPress would never write ("02")
// must not skip the widget's check.
foreach ( array( '"02"' => array( '02' => array( 'title' => '<script>x</script>' ) ), '"2a"' => array( '2a' => array( 'title' => '<script>x</script>' ) ), '0' => array( 0 => array( 'title' => 'x' ) ), '-1' => array( -1 => array( 'title' => 'x' ) ) ) as $why => $inst ) {
	$odd = $data;
	$odd['rows']['widget_text']['value'] = serialize( $inst + array( 2 => array( 'title' => 'Hello' ), '_multiwidget' => 1 ) ); // phpcs:ignore
	$out = ab_undo( ab_forge( 'wp_update_widget', 'widget', $odd ), array( 'force' => true ) );
	ok( 'ab_mcp_undo_corrupt' === ab_code( $out ) && false === strpos( ab_raw( 'widget_text' ), 'script' ), "F28b an instance number $why WordPress never writes: the point is refused whole, nothing written" );
}
$odd = $data;
$odd['rows']['widget_text']['value'] = serialize( array( 2 => array( 'title' => 'Hello' ) ) ); // phpcs:ignore
ok( 'ab_mcp_undo_corrupt' === ab_code( ab_undo( ab_forge( 'wp_update_widget', 'widget', $odd ), array( 'force' => true ) ) ), 'F28c settings without the _multiwidget mark: refused whole' );
$odd = $data;
$odd['rows']['widget_text'] = array( 'existed' => false );
ok( 'ab_mcp_undo_corrupt' === ab_code( ab_undo( ab_forge( 'wp_update_widget', 'widget', $odd ), array( 'force' => true ) ) ) && null !== ab_raw( 'widget_text' ), 'F28d an update of a row that was not there: refused whole, the row is not deleted' );
ok( 1 === ab_undo( $res['undo'] )['undone'] && 'Hello' === get_option( 'widget_text' )[2]['title'], 'F29 settings it would store come back for that account' );
$GLOBALS['ab_deny'] = array();
$out = ab_undo( $t, array( 'force' => true ) );
ok( 1 === $out['undone'] && false !== strpos( ab_raw( 'widget_text' ), 'script' ), 'F30 an account with unfiltered_html may store them, so they come back' );

reset_all();
ab_widgets();
$GLOBALS['T']['options']['widget_text'][0] = serialize( array( 2 => array( 'title' => 'Hello' ), 3 => array( 'title' => '<b>Set in wp-admin</b>' ), '_multiwidget' => 1 ) ); // phpcs:ignore
$GLOBALS['ab_deny']['unfiltered_html'] = true;
$res = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'title' => 'New' ) ) );
ok( 1 === ab_undo( $res['undo'] )['undone'] && 'Hello' === get_option( 'widget_text' )[2]['title'], 'F30a only the settings that change are run through the widget: another widget\'s markup stays no obstacle' );
$GLOBALS['ab_deny'] = array();

reset_all();
ab_widgets();
$res     = AB_MCP_Tools_Widgets::delete_widget( array( 'id' => 'feed-2' ) );
$factory = $GLOBALS['wp_widget_factory']->widgets;
$GLOBALS['wp_widget_factory']->widgets = array( $factory[0] ); // the feed widget's plugin is off
$GLOBALS['ab_deny']['unfiltered_html'] = true;
ok( 'forbidden' === ab_undo( $res['undo'] )['blocked'][0]['reason'], 'F30b a widget whose type is not registered cannot be checked: not put back for an account without unfiltered_html' );
$GLOBALS['ab_deny'] = array();
ok( 1 === ab_undo( $res['undo'] )['undone'] && 'GEHEIM-KEY' === get_option( 'widget_feed' )[2]['api_key'], 'F30c an account with unfiltered_html gets it back' );
$GLOBALS['wp_widget_factory']->widgets = $factory;

reset_all();
ab_widgets();
$hash = str_repeat( 'a', 64 );
$t    = ab_forge( 'wp_add_widget', 'widget', array( 'v' => 1, 'base' => 'TEXT', 'rows' => array( 'widget_TEXT' => array( 'existed' => false ), 'sidebars_widgets' => array( 'existed' => true, 'value' => ab_raw( 'sidebars_widgets' ), 'autoload' => 'yes' ) ), 'after' => array( 'option:widget_TEXT' => $hash, 'option:sidebars_widgets' => $hash ) ) );
$out  = ab_undo( $t, array( 'force' => true ) );
ok( 0 === $out['undone'] && 'unreadable' === $out['blocked'][0]['reason'] && null !== ab_raw( 'widget_text' ), 'F31 another spelling of the row reaches the row the database matches: nothing is deleted' );
$t   = ab_forge( 'wp_update_widget', 'widget', array( 'v' => 1, 'base' => 'x', 'rows' => array( 'sidebars_widgets' => array( 'existed' => false ) ), 'after' => array( 'option:sidebars_widgets' => $hash ) ) );
ok( 'ab_mcp_undo_corrupt' === ab_code( ab_undo( $t, array( 'force' => true ) ) ) && null !== ab_raw( 'sidebars_widgets' ), 'F32 a point naming a row the tool does not write is refused whole' );
$t   = ab_forge( 'wp_update_widget', 'widget', array( 'v' => 1, 'base' => 'feed', 'rows' => array( 'widget_feed' => array( 'existed' => true, 'value' => 'not a list', 'autoload' => 'yes' ) ), 'after' => array( 'option:widget_feed' => $hash ) ) );
ok( 'ab_mcp_undo_corrupt' === ab_code( ab_undo( $t, array( 'force' => true ) ) ), 'F33 nor are settings put back that are no list of the widgets of WordPress' );
$t   = ab_forge( 'wp_add_widget', 'widget', array( 'v' => 1, 'base' => 'feed', 'rows' => array( 'widget_feed' => array( 'existed' => false ), 'sidebars_widgets' => array( 'existed' => true, 'value' => 'not a list', 'autoload' => 'yes' ) ), 'after' => array( 'option:widget_feed' => $hash, 'option:sidebars_widgets' => $hash ) ) );
ok( 'ab_mcp_undo_corrupt' === ab_code( ab_undo( $t, array( 'force' => true ) ) ) && is_array( get_option( 'sidebars_widgets' ) ), 'F33a nor a map of the widgets per sidebar that is no list' );
foreach ( array(
	'an id of its type with "02"' => array( 'sidebar-1' => array( 'feed-02' ), 'array_version' => 3 ),
	'an id of its type with 0'    => array( 'sidebar-1' => array( 'feed-0' ), 'array_version' => 3 ),
	'an id with a control character' => array( 'sidebar-1' => array( "legacy\tweather" ), 'array_version' => 3 ),
	'an id with a line break'     => array( 'sidebar-1' => array( "legacy\nweather" ), 'array_version' => 3 ),
	'an empty id'                 => array( 'sidebar-1' => array( '' ), 'array_version' => 3 ),
	'an object in it'             => array( 'sidebar-1' => array( 'text-2' ), 'x' => array( new ArrayObject() ), 'array_version' => 3 ),
	'an id that is no text'  => array( 'sidebar-1' => array( 5 ), 'array_version' => 3 ),
	'a sidebar no list'      => array( 'sidebar-1' => 'text-2', 'array_version' => 3 ),
	'a version no number'    => array( 'sidebar-1' => array( 'text-2' ), 'array_version' => '3' ),
) as $why => $map ) {
	$t = ab_forge( 'wp_add_widget', 'widget', array( 'v' => 1, 'base' => 'feed', 'rows' => array( 'widget_feed' => array( 'existed' => false ), 'sidebars_widgets' => array( 'existed' => true, 'value' => serialize( $map ), 'autoload' => 'yes' ) ), 'after' => array( 'option:widget_feed' => $hash, 'option:sidebars_widgets' => $hash ) ) ); // phpcs:ignore
	ok( 'ab_mcp_undo_corrupt' === ab_code( ab_undo( $t, array( 'force' => true ) ) ), "F33b a map with $why: refused whole" );
}

reset_all();
ab_widgets();
$res  = AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'text', 'sidebar' => 'footer' ) );
$good = AB_MCP_Undo::claim( $res['undo'] )['data'];
ok( AB_MCP_Undo_Widgets::valid( 'wp_add_widget', $good ) && ! AB_MCP_Undo_Widgets::valid( 'wp_update_widget', $good ), 'F34 the snapshot of an add has the form of an add, not of an update' );
$bad = array(
	'no map'          => array_merge( $good, array( 'rows' => array( 'widget_text' => $good['rows']['widget_text'] ), 'after' => array( 'option:widget_text' => $hash ) ) ),
	'another row'     => array_merge( $good, array( 'rows' => array( 'widget_text' => $good['rows']['widget_text'], 'active_plugins' => $good['rows']['sidebars_widgets'] ), 'after' => array( 'option:widget_text' => $hash, 'option:active_plugins' => $hash ) ) ),
	'a flag unknown'  => array_merge( $good, array( 'rows' => array( 'widget_text' => array_merge( $good['rows']['widget_text'], array( 'autoload' => 'maybe' ) ) ) + $good['rows'] ) ),
	'value and b64'   => array_merge( $good, array( 'rows' => array( 'widget_text' => $good['rows']['widget_text'] + array( 'b64' => 'eA==' ) ) + $good['rows'] ) ),
	'missing w/ value' => array_merge( $good, array( 'rows' => array( 'widget_text' => array( 'existed' => false, 'value' => 'x' ) ) + $good['rows'] ) ),
	'a control char'  => array( 'v' => 1, 'base' => "te\nxt", 'rows' => array( "widget_te\nxt" => $good['rows']['widget_text'], 'sidebars_widgets' => $good['rows']['sidebars_widgets'] ), 'after' => array( "option:widget_te\nxt" => $hash, 'option:sidebars_widgets' => $hash ) ),
	'a row more'      => array_merge( $good, array( 'rows' => $good['rows'] + array( 'active_plugins' => array( 'existed' => false ) ) ) ),
	'neither value nor b64' => array_merge( $good, array( 'rows' => array( 'widget_text' => array( 'existed' => true, 'raw' => 'x', 'autoload' => 'yes' ) ) + $good['rows'] ) ),
	'after of another row'  => array_merge( $good, array( 'after' => array( 'option:widget_text' => $hash, 'option:active_plugins' => $hash ) ) ),
	'version 2'       => array_merge( $good, array( 'v' => 2 ) ),
	'after not hash'  => array_merge( $good, array( 'after' => array( 'option:widget_text' => 'x', 'option:sidebars_widgets' => $hash ) ) ),
);
foreach ( $bad as $why => $data ) {
	ok( false === AB_MCP_Undo_Widgets::valid( 'wp_add_widget', $data ) && 'ab_mcp_undo_corrupt' === ab_code( AB_MCP_Undo_Widgets::plan( 'wp_add_widget', $data, true ) ), "F35 refused whole: $why" );
}

/* a restore that fails keeps the point */
reset_all();
ab_widgets();
$res                      = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'title' => 'New' ) ) );
$GLOBALS['ab_db_nowrite'] = true;
$out                      = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && 1 === count( $out['failed'] ) && false !== strpos( $out['failed'][0]['reason'], 'widget_text' ) && false !== strpos( $out['failed'][0]['reason'], 'Try again' ) && null !== ab_entry( $res['undo'] ), 'F36 the database took no write: failed, the point stays' );
$GLOBALS['ab_db_nowrite'] = false;
ok( 1 === ab_undo( $res['undo'] )['undone'], 'F37 once it takes it, the same token restores' );
$res                   = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'title' => 'New' ) ) );
$GLOBALS['ab_db_lies'] = true;
$out                   = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && 1 === count( $out['failed'] ) && null !== ab_entry( $res['undo'] ), 'F37a a write the database reports and does not hold is read again: failed, the point stays' );
$GLOBALS['ab_db_lies'] = false;
ab_undo( $res['undo'] );
$GLOBALS['T']['options']['widget_text'][1] = 'auto-off';
$res                     = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'title' => 'Newer' ) ) );
$GLOBALS['T']['options']['widget_text'][1] = 'auto';
$GLOBALS['ab_db_noflag'] = true;
$out                     = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && 1 === count( $out['failed'] ) && null !== ab_entry( $res['undo'] ), 'F37b the bytes back but the flag not: read again, failed, the point stays' );
$GLOBALS['ab_db_noflag'] = false;
ok( 1 === ab_undo( $res['undo'] )['undone'] && 'auto-off' === $GLOBALS['T']['options']['widget_text'][1], 'F37c once the flag is taken too, the same token restores' );
$res                   = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'title' => 'New' ) ) );
$GLOBALS['ab_db_down'] = true;
ok( 'unreadable' === ab_undo( $res['undo'] )['blocked'][0]['reason'], 'F38 a table that cannot be read: blocked, nothing written on a guess' );
$GLOBALS['ab_db_down'] = false;

reset_all();
ab_widgets();
$GLOBALS['ab_db_down'] = true;
$res = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'title' => 'New' ) ) );
ok( true === $res['updated'] && null === $res['undo'] && false !== strpos( $res['undo_note'], 'could not be read from the database' ) && 'New' === get_option( 'widget_text' )[2]['title'], 'F39 a row that could not be read before: the write goes on, no point on a guess' );
$GLOBALS['ab_db_down'] = false;

reset_all();
ab_widgets();
$GLOBALS['ab_db_error'] = true;
$res = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'title' => 'New' ) ) );
ok( null === $res['undo'] && false !== strpos( $res['undo_note'], 'could not be read from the database' ), 'F40 a query that failed with an error is no "row not there": no point that would delete the row' );
$GLOBALS['ab_db_error'] = false;

reset_all();
update_option( 'widget_text', array( 2 => array( 'title' => 'Not by WP_Widget' ) ) );
ok( 'ab_mcp_blocked' === ab_code( AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'title' => 'X' ) ) ) ), 'F41 the tools ask the same rule (a row without _multiwidget is not theirs)' );

/* ============================== G) deleting says it is not undoable */

reset_all();
$id  = ab_add_term( 'category', 'Gone' );
$res = AB_MCP_Tools_Taxonomy_Comments::delete_term( array( 'taxonomy' => 'category', 'term_id' => $id ) );
ok( true === $res['deleted'] && array_key_exists( 'undo', $res ) && null === $res['undo'] && 0 === strpos( $res['undo_note'], 'not undoable: ' ) && false !== strpos( $res['undo_note'], 'wp_get_term' ) && 0 === ab_points(), 'G1 wp_delete_term: undo null, not undoable, with the way' );
ab_add_post( 30, array( 'post_type' => 'nav_menu_item' ) );
$res = AB_MCP_Tools_Menus_Users::delete_menu_item( array( 'item_id' => 30 ) );
ok( true === $res['deleted'] && null === $res['undo'] && 0 === strpos( $res['undo_note'], 'not undoable: ' ) && false !== strpos( $res['undo_note'], 'wp_get_menu' ), 'G2 wp_delete_menu_item: the same' );
// The shop's notes (4.14.0) name its readers, wp_wc_get_order and wp_wc_list_coupons.
foreach ( AB_MCP_Undo_Objects::own_notes() as $tool => $note ) {
	ok( 0 === strpos( $note, 'not undoable: ' ) && 1 === preg_match( '/\bwp_(wc_)?(get|list|create|add|upload)_[a-z_]+/', $note ), "G3 the note of $tool names a tool to note it with first" );
}

/* ============================== H) why there is no undo point */

reset_all();
$GLOBALS['ab_toolstate']['wp_undo'] = false;
ab_widgets();
$id = ab_add_term( 'category', 'A' );
foreach ( array(
	'wp_create_term'   => static fn() => AB_MCP_Tools_Taxonomy_Comments::create_term( array( 'taxonomy' => 'category', 'name' => 'Off' ) ),
	'wp_update_term'   => static fn() => AB_MCP_Tools_Taxonomy_Comments::update_term( array( 'taxonomy' => 'category', 'term_id' => $id, 'name' => 'Off' ) ),
	'wp_create_menu'   => static fn() => AB_MCP_Tools_Menus_Users::create_menu( array( 'name' => 'Off' ) ),
	'wp_add_menu_item' => static fn() => AB_MCP_Tools_Menus_Users::add_menu_item( array( 'menu' => 'Off', 'title' => 'T', 'url' => '/t' ) ),
	'wp_update_widget' => static fn() => AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'title' => 'Off' ) ) ),
	'wp_add_widget'    => static fn() => AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'text', 'sidebar' => 'footer' ) ),
	'wp_delete_widget' => static fn() => AB_MCP_Tools_Widgets::delete_widget( array( 'id' => 'text-3' ) ),
) as $tool => $call ) {
	$res = $call();
	ok( is_array( $res ) && null === $res['undo'] && false !== strpos( $res['undo_note'], 'Undo is switched off on this site' ) && 0 === ab_points(), "H1 undo switched off: the note says so ($tool)" );
}
ok( 'Off' === get_term( $id, 'category' )->name, 'H2 the writes happen all the same' );

reset_all();
ab_widgets();
$GLOBALS['ab_db_down'] = true;
$res = AB_MCP_Tools_Taxonomy_Comments::create_term( array( 'taxonomy' => 'category', 'name' => 'Blind' ) );
ok( true === $res['created'] && null === $res['undo'] && false !== strpos( $res['undo_note'], 'could not be read from the database' ), 'H3 a new term whose rows could not be read: no point on a guess' );
$id  = ab_add_term( 'category', 'A' );
$res = AB_MCP_Tools_Taxonomy_Comments::update_term( array( 'taxonomy' => 'category', 'term_id' => $id, 'name' => 'B' ) );
ok( true === $res['updated'] && null === $res['undo'] && false !== strpos( $res['undo_note'], 'could not be read' ) && 'B' === get_term( $id, 'category' )->name, 'H4 nor for a changed term' );
$GLOBALS['ab_db_down'] = false;
AB_MCP_Tools_Menus_Users::create_menu( array( 'name' => 'M' ) );
$GLOBALS['ab_db_down'] = true;
$res = AB_MCP_Tools_Menus_Users::add_menu_item( array( 'menu' => 'M', 'title' => 'T', 'url' => '/t' ) );
ok( true === $res['added'] && null === $res['undo'] && false !== strpos( $res['undo_note'], 'could not be read' ), 'H5 nor for a new menu item' );
$GLOBALS['ab_db_down'] = false;

reset_all();
$GLOBALS['ab_filters']['ab_mcp_undo_snapshot_max'] = 10;
$GLOBALS['ab_filters']['ab_mcp_undo_inline_max']   = 5;
ab_widgets();
$res = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'title' => 'Big' ) ) );
ok( null === $res['undo'] && 1 === preg_match( '/ab_mcp_undo_(raw|snapshot)_max/', $res['undo_note'] ) && 'Big' === get_option( 'widget_text' )[2]['title'], 'H6 a row too large for a point: the note gives the limit and the filter, the write stands' );

reset_all();
ab_widgets();
$GLOBALS['ab_filters']['ab_mcp_undo_inline_max'] = 50;
$res = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'feed-2', 'settings' => array( 'title' => 'File' ) ) );
$e   = ab_entry( $res['undo'] );
ok( null !== $e && null === $e['inline'] && null !== $e['payload'], 'H7 over the inline limit, the snapshot goes to a file' );
$GLOBALS['ab_filters'] = array();
update_option( 'widget_feed', array( 2 => array( 'title' => 'File', 'api_key' => 'GEHEIM-KEY', 'auth' => array( 'client_secret' => 'GEHEIM-SECRET', 'user' => 'u' ) ), '_multiwidget' => 1 ) );
$out = ab_undo( $res['undo'] );
ok( 1 === $out['undone'] && 'Feed' === get_option( 'widget_feed' )[2]['title'], 'H8 ... and comes back from it' );

/* ============================== K) Codex 31.10.: identities, rights of the items, a term not created here */

// Not counts but identities and contents.
reset_all();
$GLOBALS['ab_on_create_term'] = static function ( $id ) { $GLOBALS['T']['termmeta'][ ab_next() ] = array( $id, 'order', '0' ); };
$res = AB_MCP_Tools_Taxonomy_Comments::create_term( array( 'taxonomy' => 'category', 'name' => 'Shop' ) );
foreach ( $GLOBALS['T']['termmeta'] as $mid => $r ) {
	if ( $r[0] === $res['term_id'] ) { $GLOBALS['T']['termmeta'][ $mid ][2] = '42'; }
}
$out = ab_undo( $res['undo'] );
ok( 0 === $out['undone'] && array( 'use' ) === $out['blocked'][0]['locs'] && null !== get_term( $res['term_id'], 'category' ), 'K1 a meta value changed since, the number of rows the same: blocked' );
foreach ( $GLOBALS['T']['termmeta'] as $mid => $r ) {
	if ( $r[0] === $res['term_id'] ) { unset( $GLOBALS['T']['termmeta'][ $mid ] ); $GLOBALS['T']['termmeta'][ ab_next() ] = array( $r[0], 'order', '0' ); }
}
ok( 'changed_since' === ab_undo( $res['undo'] )['blocked'][0]['reason'], 'K1a a meta row deleted and written anew with the old value: another row, changed' );

reset_all();
$res = AB_MCP_Tools_Taxonomy_Comments::create_term( array( 'taxonomy' => 'category', 'name' => 'Swap' ) );
$GLOBALS['T']['rel'][] = array( 55, ab_tt_of( $res['term_id'] ) );
$c = AB_MCP_Undo::claim( $res['undo'] )['data'];
// The state after the change had post 55 on it; now another post instead.
$c['after'] = AB_MCP_Undo_Terms::now_hashes( $c );
$GLOBALS['T']['rel'][ count( $GLOBALS['T']['rel'] ) - 1 ][0] = 56;
ok( 'changed' === AB_MCP_Undo_Terms::compare( $c )['use'], 'K2 another post in place of one, the count the same: changed' );
$kid = ab_add_term( 'category', 'Kid', null, $res['term_id'] );
$c['after'] = AB_MCP_Undo_Terms::now_hashes( $c );
$GLOBALS['T']['tt'][ ab_tt_of( $kid ) ][3] = 0;
ab_add_term( 'category', 'Other kid', null, $res['term_id'] );
ok( 'changed' === AB_MCP_Undo_Terms::compare( $c )['use'], 'K3 another child term in place of one: changed' );

reset_all();
AB_MCP_Tools_Menus_Users::create_menu( array( 'name' => 'Main' ) );
$item = AB_MCP_Tools_Menus_Users::add_menu_item( array( 'menu' => 'Main', 'title' => 'Home', 'url' => '/' ) );
$c    = array( 'v' => 1, 'id' => wp_get_nav_menu_object( 'Main' )->term_id, 'taxonomy' => 'nav_menu' );
$c['after'] = AB_MCP_Undo_Terms::now_hashes( $c );
$GLOBALS['T']['posts'][ $item['item_id'] ]['post_title'] = 'Changed';
ok( 'changed' === AB_MCP_Undo_Terms::compare( $c )['use'], 'K4 an item of a menu changed: the menu changed' );

// The rights of the items a menu takes with it, with force too.
reset_all();
$menu = AB_MCP_Tools_Menus_Users::create_menu( array( 'name' => 'Main' ) );
$item = AB_MCP_Tools_Menus_Users::add_menu_item( array( 'menu' => 'Main', 'title' => 'Home', 'url' => '/' ) );
$GLOBALS['ab_deny'] = array( 'delete_post:' . $item['item_id'] => true );
$out = ab_undo( $menu['undo'], array( 'force' => true ) );
ok( 0 === $out['undone'] && 'forbidden' === $out['blocked'][0]['reason'] && null !== get_post( $item['item_id'] ) && false !== wp_get_nav_menu_object( 'Main' ), 'K5 a menu with an item the account may not delete stays, even with force' );
$GLOBALS['ab_deny'] = array();
ab_add_post( 300, array( 'post_type' => 'page' ) );
$GLOBALS['T']['rel'][] = array( 300, ab_tt_of( $menu['menu_id'] ) );
$out = ab_undo( $menu['undo'], array( 'force' => true ) );
ok( 'forbidden' === $out['blocked'][0]['reason'] && null !== get_post( 300 ), 'K6 nor one holding a post that is no menu item' );
$GLOBALS['T']['rel'] = array_values( array_filter( $GLOBALS['T']['rel'], static fn( $r ) => 300 !== $r[0] ) );
$plan = AB_MCP_Undo_Terms::plan( 'wp_create_menu', AB_MCP_Undo::claim( $menu['undo'] )['data'], true );
$GLOBALS['ab_deny'] = array( 'delete_post:' . $item['item_id'] => true ); // between the plan and the write
$again = AB_MCP_Undo_Terms::restore( $plan['items'][0] );
ok( 'ab_mcp_undo_forbidden' === ab_code( $again ) && null !== get_post( $item['item_id'] ) && false !== strpos( ab_msg( $again ), 'wp_delete_menu_item' ), 'K7 the rights of the items are asked once more right before the write, with the way' );
$GLOBALS['ab_deny'] = array();
ok( 1 === ab_undo( $menu['undo'], array( 'force' => true ) )['undone'] && null === get_post( $item['item_id'] ), 'K8 with the right to each item, force deletes the menu with them' );

// A term WordPress answered with, though this call did not create it.
reset_all();
$old                       = ab_add_term( 'category', 'Twin' );
$GLOBALS['ab_insert_dupe'] = $old;
$res = AB_MCP_Tools_Taxonomy_Comments::create_term( array( 'taxonomy' => 'category', 'name' => 'Twin' ) );
ok( true === $res['created'] && $old === $res['term_id'] && null === $res['undo'] && false !== strpos( $res['undo_note'], 'did not create' ) && false !== strpos( $res['undo_note'], 'wp_get_term' ) && 0 === ab_points(), 'K9 a term of another request answered for a duplicate: no point that would delete it' );
$res = AB_MCP_Tools_Menus_Users::create_menu( array( 'name' => 'Menu twin' ) );
ok( null === $res['undo'] && false !== strpos( $res['undo_note'], 'did not create' ) && 0 === ab_points() && null !== get_term( $old, 'category' ), 'K10 the same for a menu' );
unset( $GLOBALS['ab_insert_dupe'] );
$res = AB_MCP_Tools_Taxonomy_Comments::create_term( array( 'taxonomy' => 'category', 'name' => 'Own' ) );
ok( ab_token( $res ) && empty( $GLOBALS['ab_actions']['created_term'] ), 'K11 a term the call created gets its point, and the watch is gone again' );

/* Pro's own wp_undo (last: the class stays) */
reset_all();
ab_widgets();
eval( 'class AB_MCP_Pro_Undo_Restore { public static function policy() { return array( "wp_update_post" => array() ); } }' ); // phpcs:ignore
$id = ab_add_term( 'category', 'A' );
foreach ( array(
	'wp_create_term'   => static fn() => AB_MCP_Tools_Taxonomy_Comments::create_term( array( 'taxonomy' => 'category', 'name' => 'Pro' ) ),
	'wp_update_term'   => static fn() => AB_MCP_Tools_Taxonomy_Comments::update_term( array( 'taxonomy' => 'category', 'term_id' => $id, 'name' => 'Pro' ) ),
	'wp_create_menu'   => static fn() => AB_MCP_Tools_Menus_Users::create_menu( array( 'name' => 'Pro' ) ),
	'wp_add_menu_item' => static fn() => AB_MCP_Tools_Menus_Users::add_menu_item( array( 'menu' => 'Pro', 'title' => 'T', 'url' => '/t' ) ),
	'wp_update_widget' => static fn() => AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'title' => 'Pro' ) ) ),
	'wp_add_widget'    => static fn() => AB_MCP_Tools_Widgets::add_widget( array( 'id_base' => 'text', 'sidebar' => 'footer' ) ),
	'wp_delete_widget' => static fn() => AB_MCP_Tools_Widgets::delete_widget( array( 'id' => 'text-3' ) ),
) as $tool => $call ) {
	$res = $call();
	ok( is_array( $res ) && null === $res['undo'] && false !== strpos( $res['undo_note'], 'AlphaBridge MCP Pro' ) && false !== strpos( $res['undo_note'], $tool ) && 0 === ab_points(), "H9 under a Pro whose wp_undo cannot reverse it: no token ($tool)" );
}
$GLOBALS['ab_filters']['ab_mcp_undo_core_restores'] = static fn( $handed, $tool ) => 'wp_update_widget' === $tool;
$res = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'text-2', 'settings' => array( 'title' => 'Handed' ) ) );
ok( ab_token( $res ), 'H10 a Pro that hands the points of the tool on gets the token' );
$res = AB_MCP_Tools_Widgets::update_widget( array( 'id' => 'feed-2', 'settings' => array( 'title' => 'Handed' ) ) );
$res2 = AB_MCP_Tools_Taxonomy_Comments::create_term( array( 'taxonomy' => 'category', 'name' => 'Not handed' ) );
ok( ab_token( $res ) && null === $res2['undo'], 'H11 ... per tool' );

/* ============================== end */

echo "UNDO-TERMS-WIDGETS: $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
