<?php
/**
 * Shared stand-ins for the page-builder tests: an in-memory posts, postmeta
 * and options table behind a $wpdb stub, the WordPress functions the code
 * under test calls, the free core's builder classes (from the bundled core,
 * run ./sync-free-core.sh first), the Pro classes, and a test adapter
 * ("fake") whose page is a JSON list of elements in the meta key
 * _fake_layout with a text copy in post_content.
 *
 * Every stand-in mirrors the part of WordPress it replaces and says where it
 * is narrower. Included by builder-undo-test.php and builder-writer-test.php.
 */

error_reporting( E_ALL );

// A test that needs files under ABSPATH (stand-ins of wp-admin/includes/)
// defines it first, to a folder of its own.
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
define( 'KB_IN_BYTES', 1024 );
define( 'MB_IN_BYTES', 1048576 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['ab_fail']  = 0;
$GLOBALS['ab_count'] = 0;
function ok( $cond, $label ) {
	$GLOBALS['ab_count']++;
	if ( ! $cond ) {
		$GLOBALS['ab_fail']++;
		echo "  FAIL: $label\n";
	}
}
// A PHP warning or notice is a failure here, not a line in a log.
set_error_handler(
	static function ( $no, $str, $file, $line ) {
		// A silenced call (@) is the caller's decision, as in PHP itself.
		if ( ! ( error_reporting() & $no ) ) {
			return true;
		}
		$GLOBALS['ab_fail']++;
		$GLOBALS['ab_count']++;
		echo '  FAIL: PHP: ' . $str . ' in ' . basename( (string) $file ) . ':' . $line . "\n";
		return true;
	}
);

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
	public function has_errors() { return '' !== (string) $this->code; }
}
class WP_Post {
	public $ID;
	public $post_type;
	public $post_status;
	public $post_title;
	public $post_parent;
	public $post_content;
	public $post_content_filtered;
	public $post_name;
	public $post_modified_gmt;
	public function __construct( array $row ) {
		foreach ( $row as $k => $v ) {
			$this->$k = $v;
		}
	}
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; }
function wp_salt( $s = 'auth' ) { return 'test-salt'; }
function wp_rand( $a = 0, $b = 0 ) { return random_int( $a, $b ); }
function trailingslashit( $p ) { return rtrim( $p, '/' ) . '/'; }
function wp_mkdir_p( $p ) { return is_dir( $p ) || mkdir( $p, 0777, true ); }
function wp_json_encode( $v, $o = 0, $d = 512 ) { return json_encode( $v, $o, $d ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function is_multisite() { return false; }
function get_site_option( $k, $d = false ) { return $d; }
function get_template() { return 'twentytwentysix'; }
function get_stylesheet() { return 'twentytwentysix'; }
function size_format( $bytes, $decimals = 0 ) { return number_format( (float) $bytes / 1024, $decimals ) . ' KB'; }
function wp_convert_hr_to_bytes( $v ) { return -1; }
// WordPress unserializes any class; stdClass is the only one a page builder
// stores in its layout (Beaver Builder's nodes), so only that one is let in.
function maybe_unserialize( $v ) {
	if ( ! is_string( $v ) ) { return $v; }
	$d = @unserialize( $v, array( 'allowed_classes' => array( 'stdClass' ) ) );
	return ( false === $d && 'b:0;' !== $v ) ? $v : $d;
}
function maybe_serialize( $v ) { return is_array( $v ) || is_object( $v ) ? serialize( $v ) : $v; }

/* ---- hooks: real enough to register callbacks and run them in order ---- */
$GLOBALS['ab_hooks']   = array();
$GLOBALS['ab_actions'] = array();
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['ab_hooks'][ $tag ][ $prio ][] = array( $cb, $args );
	return true;
}
function add_action( $tag, $cb, $prio = 10, $args = 1 ) { return add_filter( $tag, $cb, $prio, $args ); }
function remove_all_filters( $tag ) { unset( $GLOBALS['ab_hooks'][ $tag ] ); }
function remove_filter( $tag, $cb, $prio = 10 ) {
	if ( empty( $GLOBALS['ab_hooks'][ $tag ][ $prio ] ) ) { return false; }
	foreach ( $GLOBALS['ab_hooks'][ $tag ][ $prio ] as $i => $entry ) {
		if ( $entry[0] === $cb ) { unset( $GLOBALS['ab_hooks'][ $tag ][ $prio ][ $i ] ); return true; }
	}
	return false;
}
function remove_action( $tag, $cb, $prio = 10 ) { return remove_filter( $tag, $cb, $prio ); }
function has_filter( $tag, $cb = false ) {
	if ( empty( $GLOBALS['ab_hooks'][ $tag ] ) ) { return false; }
	if ( false === $cb ) { return true; }
	foreach ( $GLOBALS['ab_hooks'][ $tag ] as $prio => $list ) {
		foreach ( $list as $entry ) {
			if ( $entry[0] === $cb ) { return $prio; }
		}
	}
	return false;
}
function has_action( $tag, $cb = false ) { return has_filter( $tag, $cb ); }
function apply_filters( $tag, $value, ...$rest ) {
	if ( empty( $GLOBALS['ab_hooks'][ $tag ] ) ) { return $value; }
	ksort( $GLOBALS['ab_hooks'][ $tag ] );
	foreach ( $GLOBALS['ab_hooks'][ $tag ] as $list ) {
		foreach ( $list as $entry ) {
			$value = call_user_func_array( $entry[0], array_slice( array_merge( array( $value ), $rest ), 0, max( 1, (int) $entry[1] ) ) );
		}
	}
	return $value;
}
function do_action( $tag, ...$args ) {
	$GLOBALS['ab_actions'][] = array( $tag, $args );
	if ( empty( $GLOBALS['ab_hooks'][ $tag ] ) ) { return; }
	ksort( $GLOBALS['ab_hooks'][ $tag ] );
	foreach ( $GLOBALS['ab_hooks'][ $tag ] as $list ) {
		foreach ( $list as $entry ) {
			call_user_func_array( $entry[0], array_slice( $args, 0, max( 0, (int) $entry[1] ) ) );
		}
	}
}

/* ---- identity and capabilities ---- */
$GLOBALS['ab_uid']  = 7;
$GLOBALS['ab_caps'] = array();
function get_current_user_id() { return (int) $GLOBALS['ab_uid']; }
function current_user_can( $cap, $obj = null ) {
	if ( null !== $obj && array_key_exists( $cap . ':' . $obj, $GLOBALS['ab_caps'] ) ) {
		return (bool) $GLOBALS['ab_caps'][ $cap . ':' . $obj ];
	}
	return ! empty( $GLOBALS['ab_caps'][ $cap ] );
}

/* ---- options (the undo index and its lock live here) ---- */
$GLOBALS['ab_opts'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['ab_opts'] ) ? $GLOBALS['ab_opts'][ $k ] : $d; }
// As WordPress 7.1.2 (wp-includes/option.php): add_option() runs
// sanitize_option() and writes only where nothing is stored; update_option()
// runs it too, then the pre_update filters decide what is written, a value
// that is what is there already is not written, and where nothing is stored
// it hands over to add_option().
function add_option( $k, $v, $x = '', $a = 'yes' ) {
	$v = apply_filters( 'sanitize_option_' . $k, $v, $k, $v );
	if ( false !== get_option( $k ) ) { return false; }
	$GLOBALS['ab_opts'][ $k ] = $v;
	return true;
}
function update_option( $k, $v, $a = null ) {
	$v   = apply_filters( 'sanitize_option_' . $k, $v, $k, $v );
	$old = get_option( $k );
	$v   = apply_filters( 'pre_update_option_' . $k, $v, $old, $k );
	$v   = apply_filters( 'pre_update_option', $v, $k, $old );
	if ( $v === $old || maybe_serialize( $v ) === maybe_serialize( $old ) ) {
		return false;
	}
	if ( false === $old ) {
		return add_option( $k, $v, '', $a );
	}
	$GLOBALS['ab_opts'][ $k ] = $v;
	return true;
}
// The autoload column, for the options that have one set here; others read as 'auto'.
$GLOBALS['ab_autoload'] = array();
function delete_option( $k ) { $had = array_key_exists( $k, $GLOBALS['ab_opts'] ); unset( $GLOBALS['ab_opts'][ $k ] ); return $had; }
$GLOBALS['ab_cache_deletes'] = array();
function wp_cache_delete( $k, $g = '' ) { $GLOBALS['ab_cache_deletes'][] = $g . ':' . $k; return true; }
$GLOBALS['ab_cleaned'] = array();
function clean_post_cache( $id ) { $GLOBALS['ab_cleaned'][] = (int) $id; }

/* ---- uploads (undo payload files) ---- */
$GLOBALS['ab_upload'] = sys_get_temp_dir() . '/ab-builder-test-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
function ab_rm_tree( $dir ) {
	if ( 0 !== strpos( (string) $dir, sys_get_temp_dir() . '/ab-builder-test-' ) || ! is_dir( $dir ) ) { return; }
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) { $f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); }
	@rmdir( $dir );
}
register_shutdown_function( static function () { ab_rm_tree( $GLOBALS['ab_upload'] ); } );
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['ab_upload'], 'baseurl' => 'http://x/u', 'error' => false ); }
function ab_bin_files() { $g = glob( $GLOBALS['ab_upload'] . '/ab-mcp-undo/*.bin' ); return is_array( $g ) ? $g : array(); }

/* ---- the in-memory tables ---- */
$GLOBALS['db_posts'] = array();   // id => row
$GLOBALS['db_meta']  = array();   // meta_id => [ post_id, meta_key, meta_value ]
$GLOBALS['db_next']  = 100;
function ab_post( $id, array $row ) {
	$GLOBALS['db_posts'][ $id ] = array_merge(
		array(
			'ID'                    => $id,
			'post_type'             => 'page',
			'post_status'           => 'publish',
			'post_title'            => 'Page ' . $id,
			'post_parent'           => 0,
			'post_content'          => '',
			'post_content_filtered' => '',
		),
		$row
	);
}
function ab_meta_add( $post_id, $key, $value ) {
	$id                         = ++$GLOBALS['db_next'];
	$GLOBALS['db_meta'][ $id ] = array( (int) $post_id, (string) $key, (string) $value );
	return $id;
}
function ab_meta_rows( $post_id, $key ) {
	$out = array();
	foreach ( $GLOBALS['db_meta'] as $mid => $r ) {
		if ( $r[0] === (int) $post_id && $r[1] === (string) $key ) { $out[ $mid ] = $r[2]; }
	}
	ksort( $out );
	return $out;
}
function get_post( $id = null ) {
	if ( is_object( $id ) ) { $id = $id->ID; }
	return isset( $GLOBALS['db_posts'][ (int) $id ] ) ? new WP_Post( $GLOBALS['db_posts'][ (int) $id ] ) : null;
}
function get_post_meta( $id, $key = '', $single = false ) {
	$rows = array_values( array_map( 'maybe_unserialize', ab_meta_rows( $id, $key ) ) );
	if ( $single ) { return isset( $rows[0] ) ? $rows[0] : ''; }
	return $rows;
}
function update_post_meta( $id, $key, $value ) {
	// update_metadata() unslashes what it is given (wp-includes/meta.php); a
	// test that defines wp_unslash() gets the same, the others store as given.
	if ( function_exists( 'wp_unslash' ) ) { $value = wp_unslash( $value ); }
	$raw  = maybe_serialize( $value );
	$rows = ab_meta_rows( $id, $key );
	if ( array() === $rows ) { ab_meta_add( $id, $key, $raw ); return true; }
	foreach ( array_keys( $rows ) as $mid ) { $GLOBALS['db_meta'][ $mid ][2] = (string) $raw; }
	return true;
}
$GLOBALS['ab_meta_deleted'] = array();
function delete_post_meta( $id, $key ) {
	$GLOBALS['ab_meta_deleted'][] = (int) $id . ':' . $key;
	foreach ( array_keys( ab_meta_rows( $id, $key ) ) as $mid ) { unset( $GLOBALS['db_meta'][ $mid ] ); }
	return true;
}
$GLOBALS['wpdb'] = new class() {
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $options  = 'wp_options';
	public $fail_update = false;
	public function prepare( $q, ...$args ) { return array( 'q' => $q, 'a' => $args ); }
	public function esc_like( $s ) { return addcslashes( (string) $s, '_%\\' ); }
	public function get_var( $p ) {
		$q = $p['q'];
		$a = $p['a'];
		// As wpdb::get_var(): null for a missing row AND for an empty value
		// (wp-includes/class-wpdb.php:3044).
		if ( false !== strpos( $q, 'FROM wp_posts' ) && preg_match( '~SELECT (post_content(?:_filtered)?) FROM~', $q, $m ) ) {
			$v = isset( $GLOBALS['db_posts'][ (int) $a[0] ] ) ? (string) $GLOBALS['db_posts'][ (int) $a[0] ][ $m[1] ] : null;
			return '' === $v ? null : $v;
		}
		if ( false !== strpos( $q, 'SELECT ID FROM wp_posts WHERE ID' ) ) {
			return isset( $GLOBALS['db_posts'][ (int) $a[0] ] ) ? (string) (int) $a[0] : null;
		}
		if ( false !== strpos( $q, 'SELECT meta_id FROM wp_postmeta WHERE meta_id' ) ) {
			return isset( $GLOBALS['db_meta'][ (int) $a[0] ] ) ? (string) $a[0] : null;
		}
		if ( false !== strpos( $q, 'SELECT option_value FROM wp_options WHERE option_name' ) ) {
			// As the table holds it: serialized, null where it is not there.
			return array_key_exists( $a[0], $GLOBALS['ab_opts'] ) ? (string) maybe_serialize( $GLOBALS['ab_opts'][ $a[0] ] ) : null;
		}
		if ( false !== strpos( $q, 'SELECT autoload FROM wp_options WHERE option_name' ) ) {
			if ( ! array_key_exists( $a[0], $GLOBALS['ab_opts'] ) ) {
				return null;
			}
			return isset( $GLOBALS['ab_autoload'][ $a[0] ] ) ? $GLOBALS['ab_autoload'][ $a[0] ] : 'auto';
		}
		if ( false !== strpos( $q, 'SELECT meta_value FROM wp_postmeta' ) ) {
			$rows = ab_meta_rows( $a[0], $a[1] );
			return array() === $rows ? null : reset( $rows );
		}
		throw new RuntimeException( 'unexpected get_var: ' . $q );
	}
	public function get_results( $p, $out = null ) {
		$q = $p['q'];
		if ( false !== strpos( $q, 'SELECT meta_id, meta_value FROM wp_postmeta' ) ) {
			$res = array();
			foreach ( ab_meta_rows( $p['a'][0], $p['a'][1] ) as $mid => $v ) {
				$res[] = array( 'meta_id' => (string) $mid, 'meta_value' => $v );
			}
			return $res;
		}
		throw new RuntimeException( 'unexpected get_results: ' . $q );
	}
	public function get_col( $p ) {
		$q = $p['q'];
		if ( false !== strpos( $q, 'SELECT DISTINCT meta_key FROM wp_postmeta WHERE post_id' ) ) {
			$keys = array();
			foreach ( $GLOBALS['db_meta'] as $r ) {
				if ( $r[0] === (int) $p['a'][0] ) { $keys[ $r[1] ] = true; }
			}
			return array_keys( $keys );
		}
		throw new RuntimeException( 'unexpected get_col: ' . $q );
	}
	public function update( $table, $data, $where ) {
		if ( $this->fail_update ) { return false; }
		if ( 'wp_posts' === $table ) {
			foreach ( $data as $k => $v ) { $GLOBALS['db_posts'][ (int) $where['ID'] ][ $k ] = $v; }
			return 1;
		}
		if ( 'wp_postmeta' === $table ) {
			$GLOBALS['db_meta'][ (int) $where['meta_id'] ][2] = (string) $data['meta_value'];
			return 1;
		}
		if ( 'wp_options' === $table && array( 'autoload' ) === array_keys( $data ) ) {
			if ( ! array_key_exists( $where['option_name'], $GLOBALS['ab_opts'] ) ) {
				return 0;
			}
			$GLOBALS['ab_autoload'][ $where['option_name'] ] = (string) $data['autoload'];
			return 1;
		}
		return 0; // options: the stale-lock steal, never needed here.
	}
	public function insert( $table, $data ) {
		if ( isset( $data['meta_id'] ) ) {
			// An explicit id, as MySQL takes it for an AUTO_INCREMENT column: refused when taken.
			if ( isset( $GLOBALS['db_meta'][ (int) $data['meta_id'] ] ) ) { return false; }
			$GLOBALS['db_meta'][ (int) $data['meta_id'] ] = array( (int) $data['post_id'], (string) $data['meta_key'], (string) $data['meta_value'] );
			return 1;
		}
		ab_meta_add( $data['post_id'], $data['meta_key'], $data['meta_value'] );
		return 1;
	}
	public function delete( $table, $where ) {
		$n = 0;
		foreach ( $GLOBALS['db_meta'] as $mid => $r ) {
			if ( ( isset( $where['meta_id'] ) && (int) $where['meta_id'] === $mid )
				|| ( isset( $where['post_id'], $where['meta_key'] ) && (int) $where['post_id'] === $r[0] && $where['meta_key'] === $r[1] ) ) {
				unset( $GLOBALS['db_meta'][ $mid ] );
				$n++;
			}
		}
		return $n;
	}
};

/* ---- what the writers ask of WordPress ---- */
function wp_check_post_lock( $id ) {
	$lock = explode( ':', (string) get_post_meta( $id, '_edit_lock', true ) );
	$time = isset( $lock[0] ) ? (int) $lock[0] : 0;
	$user = isset( $lock[1] ) ? (int) $lock[1] : 0;
	// Mirrors wp-admin/includes/post.php:1715-1745: within 150 s, and never the current user.
	if ( $time && $time > time() - 150 && $user !== get_current_user_id() ) { return $user; }
	return false;
}
/*
 * Stand-in for wp_kses_post(), narrower than core: drops script, style and
 * iframe elements with their content, event attributes and javascript:
 * links, and writes a bare "&" as "&amp;" — the cases the field checks are
 * tested with. Not a sanitiser.
 */
function wp_kses_post( $html ) {
	$html = preg_replace( '~<(script|style|iframe)\b[^>]*>.*?</\1>~is', '', (string) $html );
	$html = preg_replace( '~\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $html );
	$html = preg_replace( '~javascript:~i', '', $html );
	return preg_replace( '~&(?![a-zA-Z0-9#]+;)~', '&amp;', $html );
}
$GLOBALS['ab_images'] = array( 55 => 'http://x/u/bild.jpg' );   // ab_post() 55 and 56 as attachments, see the tests
function wp_attachment_is_image( $id ) { return isset( $GLOBALS['ab_images'][ (int) $id ] ); }
function wp_get_attachment_url( $id ) { return isset( $GLOBALS['ab_images'][ (int) $id ] ) ? $GLOBALS['ab_images'][ (int) $id ] : false; }

/* ---- the core's classes the undo path asks ---- */
// The tool switches as the core 4.4.0 reads them: every tool is on until an
// administrator switches it off under Fine-tuning, the Mighty ones included.
$GLOBALS['ab_toolstate'] = array();
class AB_MCP_Settings {
	public static function is_tool_enabled( $name, $def ) {
		if ( array_key_exists( $name, $GLOBALS['ab_toolstate'] ) ) { return (bool) $GLOBALS['ab_toolstate'][ $name ]; }
		return true;
	}
}
// The token scope of the request; a test sets $GLOBALS['ab_scope'] to try a narrower one.
class AB_MCP_Auth { public static function current_scope() { return isset( $GLOBALS['ab_scope'] ) ? (string) $GLOBALS['ab_scope'] : 'full'; } }
class AB_MCP_Tool_Registry { public static function scope_allows( $s, $n, array $d ) { return true; } }

/* ---- load: the free core's builder classes, then Pro ---- */
$pro = dirname( __DIR__, 2 ) . '/';
define( 'AB_MCP_PRO_DIR', $pro );
if ( ! is_readable( $pro . 'includes/builders/class-builders.php' ) ) {
	fwrite( STDERR, "Der gebuendelte Kern fehlt: zuerst ./sync-free-core.sh <free-pfad>\n" );
	exit( 2 );
}
require_once $pro . 'includes/builders/class-builders.php';
require_once $pro . 'includes/undo/class-undo.php';
require_once $pro . 'includes/undo/class-undo-restore.php';
require_once $pro . 'includes/undo/class-undo-builder.php';
require_once $pro . 'includes/class-page-cache.php';

// A refusal for the account's role goes through Pro's guidance and the
// core's AB_MCP_Guidance, which the plugin loads on every request.
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $p = '' ) { return 'http://x/wp-admin/' . $p; }
}
if ( ! function_exists( 'network_admin_url' ) ) {
	function network_admin_url( $p = '' ) { return 'http://x/wp-admin/network/' . $p; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return 0; }
}
require_once $pro . 'includes/class-guidance.php';
require_once $pro . 'includes/builders/write/load.php';
ok( true === ab_mcp_load_builder_writers(), 'H0 the builder parts load on a core with AB_MCP_BUILDER_API ' . AB_MCP_BUILDER_API );

/* ---- the test adapter ---- */
class AB_Test_Fake_Adapter extends AB_MCP_Builder_Adapter {
	public static $mutate_on_outline = null; // callable, run once inside the next outline()
	public function id(): string { return 'fake'; }
	public function name(): string { return 'Fake Builder'; }
	public function detect( $post ): bool { return '' !== (string) get_post_meta( (int) $post->ID, '_fake_layout', true ); }
	public function is_active(): bool { return true; }
	public function verified(): bool { return ! empty( $GLOBALS['ab_fake_verified'] ); }
	public function storage( $post ): string { return 'B'; }
	public function own_data_shown( $post ): ?bool { return true; }
	public function copies( $post ): array {
		return AB_MCP_Builders::existing_copies(
			$post,
			array(
				array( 'loc' => 'post_content', 'shown' => false, 'role' => 'copy' ),
				array( 'loc' => 'meta:_fake_layout', 'shown' => true, 'role' => 'source' ),
			),
			$this->locked_meta_keys()
		);
	}
	public function locked_meta_keys(): array { return array( '_fake_code' ); }
	public function outline( $post, array $o ): array {
		if ( is_callable( self::$mutate_on_outline ) ) {
			$cb                      = self::$mutate_on_outline;
			self::$mutate_on_outline = null;
			$cb( $post );
		}
		$tree = json_decode( (string) ab_first_meta( (int) $post->ID, '_fake_layout' ), true );
		$out  = array();
		foreach ( is_array( $tree ) ? $tree : array() as $el ) {
			$row = array( 'id' => $el['id'], 'type' => $el['type'], 'parent' => null, 'depth' => 0 );
			if ( ! empty( $el['locked'] ) ) {
				$row['locked'] = true;
				$row['reason'] = $el['locked'];
				if ( ! empty( $el['global'] ) ) { $row['global'] = true; $row['note'] = 'its content is post #9'; }
			} elseif ( ! empty( $el['fields'] ) ) {
				foreach ( $el['fields'] as $name => $f ) {
					$row['fields'][ $name ] = AB_MCP_Builders::cap_field( $f, isset( $o['max_field_chars'] ) ? (int) $o['max_field_chars'] : 2000 );
				}
			}
			$out[] = $row;
		}
		return $out;
	}
}
function ab_first_meta( $post_id, $key ) {
	$rows = ab_meta_rows( $post_id, $key );
	return array() === $rows ? '' : reset( $rows );
}
// Only the test adapter: the free core's own readers stay out of the way.
add_filter( 'ab_mcp_builder_adapters', static function ( $list ) { return array( new AB_Test_Fake_Adapter() ); } );
AB_MCP_Builders::reset();

/** A page of the test builder: elements as the tree, their texts as the copy. */
function ab_fake_page( $id, array $elements, array $extra = array() ) {
	$copy = array();
	foreach ( $elements as $el ) {
		foreach ( isset( $el['fields'] ) ? $el['fields'] : array() as $f ) {
			$copy[] = (string) $f['value'];
		}
	}
	ab_post( $id, array_merge( array( 'post_content' => implode( "\n", $copy ) ), $extra ) );
	foreach ( array_keys( ab_meta_rows( $id, '_fake_layout' ) ) as $mid ) { unset( $GLOBALS['db_meta'][ $mid ] ); }
	return ab_meta_add( $id, '_fake_layout', json_encode( $elements, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
}
function ab_fake_elements() {
	return array(
		array( 'id' => 'h1', 'type' => 'heading', 'fields' => array( 'title' => array( 'kind' => 'heading', 'value' => 'Willkommen' ), 'link' => array( 'kind' => 'url', 'value' => 'https://example.org/' ) ) ),
		array( 'id' => 't1', 'type' => 'text', 'fields' => array( 'editor' => array( 'kind' => 'html', 'value' => '<p>Hallo {post_title} Welt</p>' ) ) ),
		array( 'id' => 'btn1', 'type' => 'button', 'fields' => array( 'text' => array( 'kind' => 'text', 'value' => 'Los' ), 'link' => array( 'kind' => 'url', 'value' => '/kontakt' ) ) ),
		array( 'id' => 'i1', 'type' => 'image', 'fields' => array( 'image' => array( 'kind' => 'image', 'value' => 54 ) ) ),
		array( 'id' => 'x1', 'type' => 'html', 'locked' => 'html widget' ),
		array( 'id' => 'g1', 'type' => 'global', 'locked' => 'global element', 'global' => true ),
	);
}
function ab_result( $name ) {
	if ( $GLOBALS['ab_fail'] ) {
		printf( "%s: FAILED (%d von %d)\n", $name, $GLOBALS['ab_fail'], $GLOBALS['ab_count'] );
		exit( 1 );
	}
	printf( "ALLE %d %s BESTANDEN (0 Fehler)\n", $GLOBALS['ab_count'], $name );
	exit( 0 );
}
