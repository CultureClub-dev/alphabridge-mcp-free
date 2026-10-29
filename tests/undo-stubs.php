<?php
/**
 * What the undo store, its restore and the four tools that record undo points
 * need beyond the bootstrap (free since 4.9.0): an add_option() that refuses
 * an existing row (the store's lock is built on it), the uploads folder of the
 * snapshots, the registered post statuses, and a $wpdb that answers the two
 * raw reads they make — the posts table by id and an option row by name.
 *
 * Each function only where nothing defined it before, as the other stub files.
 * $wpdb is not set here: a test sets AbTestUndoWpdb in setUp() and takes it
 * away again in tearDown(), as BuiltWithToolsTest does with its own.
 *
 * @package AlphaBridge_MCP
 */

require_once __DIR__ . '/uninstall-stubs.php';

if ( ! defined( 'KB_IN_BYTES' ) ) {
	define( 'KB_IN_BYTES', 1024 );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! function_exists( 'add_option' ) ) {
	/** As WordPress: an INSERT, false where the row is there already. */
	function add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
		if ( array_key_exists( $name, $GLOBALS['ab_test_options'] ) ) {
			return false;
		}
		$GLOBALS['ab_test_options'][ $name ]  = $value;
		$GLOBALS['ab_test_autoload'][ $name ] = $autoload;
		return true;
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $target ) {
		return is_dir( $target ) || @mkdir( $target, 0777, true );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'size_format' ) ) {
	function size_format( $bytes, $decimals = 0 ) {
		return number_format( (float) $bytes, (int) $decimals ) . ' B';
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		return true;
	}
}

if ( ! function_exists( 'wp_cache_flush' ) ) {
	function wp_cache_flush() {
		$GLOBALS['ab_test_cache_flushed'] = ( $GLOBALS['ab_test_cache_flushed'] ?? 0 ) + 1;
		return $GLOBALS['ab_test_cache_flush_result'] ?? true;
	}
}

if ( ! function_exists( 'clean_post_cache' ) ) {
	function clean_post_cache( $post ) {
	}
}

if ( ! function_exists( 'flush_rewrite_rules' ) ) {
	function flush_rewrite_rules( $hard = true ) {
		$GLOBALS['ab_test_rewrite_flushed'][] = (bool) $hard;
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) ? (string) $email : false;
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post = null ) {
		$p = get_post( is_object( $post ) ? $post->ID : $post );
		return $p ? (string) $p->post_type : false;
	}
}

if ( ! function_exists( 'get_post_stati' ) ) {
	/** The statuses WordPress registers, with the date_floating ones as it marks them. */
	function get_post_stati( $args = array(), $output = 'names' ) {
		$all = array(
			'publish'    => false,
			'future'     => false,
			'draft'      => true,
			'pending'    => true,
			'private'    => false,
			'trash'      => false,
			'auto-draft' => true,
			'inherit'    => false,
		);
		$out = array();
		foreach ( $all as $name => $floating ) {
			if ( isset( $args['date_floating'] ) && (bool) $args['date_floating'] !== $floating ) {
				continue;
			}
			$out[ $name ] = $name;
		}
		return $out;
	}
}

if ( ! function_exists( 'get_post_status_object' ) ) {
	function get_post_status_object( $status ) {
		if ( ! in_array( $status, get_post_stati(), true ) ) {
			return null;
		}
		return (object) array(
			'name'               => $status,
			'public'             => 'publish' === $status,
			'private'            => 'private' === $status,
			'publicly_queryable' => false,
		);
	}
}

if ( ! function_exists( 'get_gmt_from_date' ) ) {
	function get_gmt_from_date( $date_string, $format = 'Y-m-d H:i:s' ) {
		return ( new DateTimeImmutable( (string) $date_string, wp_timezone() ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( $format );
	}
}

if ( ! function_exists( 'wp_untrash_post' ) ) {
	/** Out of the trash as "draft", or what a wp_untrash_post_status filter says. */
	function wp_untrash_post( $post_id = 0 ) {
		$post = get_post( (int) $post_id );
		if ( ! $post || 'trash' !== $post->post_status ) {
			return false;
		}
		$post->post_status = apply_filters( 'wp_untrash_post_status', 'draft', (int) $post_id, 'publish' );
		return $post;
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() {
		return (int) $GLOBALS['ab_test_blog'];
	}
}

if ( ! function_exists( 'is_main_site' ) ) {
	function is_main_site( $site_id = null ) {
		return true;
	}
}

if ( ! function_exists( 'is_subdomain_install' ) ) {
	function is_subdomain_install() {
		return false;
	}
}

if ( ! function_exists( 'wp_get_post_revision' ) ) {
	function wp_get_post_revision( $post ) {
		$p = get_post( $post );
		return ( $p && 'revision' === $p->post_type ) ? $p : null;
	}
}

if ( ! function_exists( 'wp_restore_post_revision' ) ) {
	/** Copies title, content and excerpt of the revision onto its post, as core does for these three. */
	function wp_restore_post_revision( $revision_id, $fields = null ) {
		$rev = wp_get_post_revision( $revision_id );
		if ( ! $rev ) {
			return false;
		}
		$post = get_post( (int) $rev->post_parent );
		if ( ! $post ) {
			return false;
		}
		foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $col ) {
			$post->$col = $rev->$col;
		}
		return (int) $post->ID;
	}
}

/**
 * The two raw reads of the undo code: a row of the posts table by id, with
 * exactly the columns the SELECT names and as strings, as MySQL answers; and
 * an option row by name, for AB_MCP_Undo::option_row(). Every query is noted.
 */
class AbTestUndoWpdb {
	public $posts       = 'wp_posts';
	public $options     = 'wp_options';
	public $last_error  = '';
	public $last_result = array();
	public $queries     = array();

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $a ) {
			$query = preg_replace_callback(
				'/%[ds]/',
				static function ( $m ) use ( $a ) {
					return '%d' === $m[0] ? (string) (int) $a : "'" . addslashes( (string) $a ) . "'";
				},
				$query,
				1
			);
		}
		return $query;
	}

	public function get_row( $query, $output = null, $y = 0 ) {
		$this->queries[] = $query;
		if ( ! preg_match( '/^SELECT (.+?) FROM wp_posts WHERE ID = (\d+)/', (string) $query, $m ) ) {
			return null;
		}
		$post = $GLOBALS['ab_test_posts'][ (int) $m[2] ] ?? null;
		if ( ! $post ) {
			return null;
		}
		$row = array();
		foreach ( array_map( 'trim', explode( ',', $m[1] ) ) as $col ) {
			$row[ $col ] = isset( $post->$col ) ? (string) $post->$col : '';
		}
		return $row;
	}

	public function query( $query ) {
		$this->queries[]   = $query;
		$this->last_result = array();
		$this->last_error  = '';
		if ( preg_match( "/FROM wp_options WHERE option_name = '(.*)' LIMIT 1$/", (string) $query, $m ) ) {
			$name = stripslashes( $m[1] );
			if ( array_key_exists( $name, $GLOBALS['ab_test_options'] ) ) {
				$flag              = $GLOBALS['ab_test_autoload'][ $name ] ?? 'yes';
				$this->last_result = array( (object) array( 'option_name' => $name, 'autoload' => is_string( $flag ) ? $flag : 'yes' ) );
			}
		}
		return count( $this->last_result );
	}

	public function update( $table, $data, $where ) {
		return 0;
	}
}
