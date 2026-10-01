<?php
/**
 * Test bootstrap.
 *
 * The plugin ships without Composer and is loaded by WordPress, so the tests
 * stand up just enough of WordPress to load the classes under test: an
 * in-memory option store, the filter API, and the handful of helpers the
 * classes touch. That keeps the suite fast and dependency-free, and it means a
 * test failure points at plugin code rather than at a WordPress fixture.
 *
 * What is deliberately NOT emulated: capabilities and the database. Anything
 * that needs those belongs in the end-to-end run against a real site, not
 * here. Of the REST dispatcher only the part that decides status and headers
 * is copied (ab_test_rest_dispatch(), see there); register_rest_route() records
 * what is registered for it. There is no network either: wp_safe_remote_get()
 * is answered by a callback the test sets (ab_test_http_answer), and every call
 * is recorded, so a test can prove that a request was made, or was not.
 *
 * Not emulated but copied: WordPress' block parser and serializer and its
 * shortcode pattern, unchanged from WordPress 7.0.2 (tests/support/, each file
 * says where from), because the page-builder readers must read exactly what
 * WordPress reads.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );

/* ----------------------------------------------------------- option store */

/** @var array<string,mixed> $ab_test_options */
$GLOBALS['ab_test_options']    = array();
/** @var array<string,int> $ab_test_writes */
$GLOBALS['ab_test_writes']     = array();
/** @var array<string,mixed> $ab_test_transients */
$GLOBALS['ab_test_transients'] = array();
/** @var array<string,int> $ab_test_transient_ttl */
$GLOBALS['ab_test_transient_ttl'] = array();
/** @var array<string,array<int,array{fn:callable,prio:int}>> $ab_test_filters */
$GLOBALS['ab_test_filters']    = array();
/** @var array<int,object> $ab_test_users */
$GLOBALS['ab_test_users']      = array();
/** @var int|null $ab_test_now A fixed Unix time for current_time(), or null for the real clock. */
$GLOBALS['ab_test_now']        = null;
/** @var int $ab_test_core_delay Seconds by which wp_insert_post() reads the clock after the tool did. */
$GLOBALS['ab_test_core_delay'] = 0;

/**
 * Reset every emulated store. Called from the test base class.
 */
function ab_test_reset(): void {
	$GLOBALS['ab_test_current_user'] = 0;
	$GLOBALS['ab_test_can']          = null;
	$GLOBALS['ab_test_multisite']    = false;
	$GLOBALS['ab_test_super_admins'] = array();
	$GLOBALS['ab_test_posts']        = array();
	$GLOBALS['ab_test_query']        = null;
	$GLOBALS['ab_test_json_fail']    = false;
	$GLOBALS['ab_test_options']    = array();
	$GLOBALS['ab_test_writes']     = array();
	$GLOBALS['ab_test_transients'] = array();
	$GLOBALS['ab_test_transient_ttl'] = array();
	$GLOBALS['ab_test_filters']    = array();
	$GLOBALS['ab_test_users']      = array();
	$GLOBALS['ab_test_comments']   = array();
	$GLOBALS['ab_test_revisions']  = array();
	$GLOBALS['ab_test_inserted']   = array();
	$GLOBALS['ab_test_updated']    = array();
	$GLOBALS['ab_test_insert_wp_error'] = array();
	$GLOBALS['ab_test_insert_fails']    = false;
	$GLOBALS['ab_test_update_fails']    = false;
	$GLOBALS['ab_test_update_errors_after_write'] = false;
	$GLOBALS['ab_test_update_wp_error']  = array();
	$GLOBALS['ab_test_template_reset']   = array();
	$GLOBALS['ab_test_meta']         = array();
	$GLOBALS['ab_test_meta_deleted'] = array();
	$GLOBALS['ab_test_meta_added']   = array();
	$GLOBALS['ab_test_rand']         = array();
	$GLOBALS['ab_test_actions']      = array();
	$GLOBALS['ab_test_comments_untrashed'] = array();
	$GLOBALS['ab_test_trashed']       = array();
	$GLOBALS['ab_test_trash_refused'] = false;
	$GLOBALS['ab_test_deleted']       = array();
	$GLOBALS['ab_test_now']        = null;
	$GLOBALS['ab_test_core_delay'] = 0;
	$GLOBALS['ab_test_sites']      = array( 1 );
	$GLOBALS['ab_test_blog']       = 1;
	$GLOBALS['ab_test_blog_stack'] = array();
	$GLOBALS['ab_test_blog_store'] = array();
	$GLOBALS['ab_test_styles']     = array();
	$GLOBALS['ab_test_scripts']    = array();
	$GLOBALS['ab_test_locale']     = 'en_US';
	$GLOBALS['ab_test_template']   = 'twentytwentyfive';
	$GLOBALS['ab_test_stylesheet'] = 'twentytwentyfive';
	$GLOBALS['ab_test_site_options'] = array();
	// The builder registry caches signatures, adapters and block profiles
	// for a request; every test starts without them.
	if ( class_exists( 'AB_MCP_Builders', false ) ) {
		AB_MCP_Builders::reset();
	}
	$GLOBALS['ab_test_http']        = array();
	$GLOBALS['ab_test_http_answer'] = null;
	$GLOBALS['ab_test_http_curl']   = false;
	$GLOBALS['ab_test_logged_in']   = false;
	$GLOBALS['ab_test_routes']      = array();
}

function get_option( $name, $default = false ) {
	// WordPress answers gmt_offset from a named timezone when one is set
	// (pre_option_gmt_offset -> wp_timezone_override_offset): the offset of
	// that zone NOW, whatever is stored. Without this a test that names only a
	// zone would see an offset of 0 and could hide a timezone mistake again.
	if ( 'gmt_offset' === $name && ! empty( $GLOBALS['ab_test_options']['timezone_string'] ) ) {
		$zone = timezone_open( (string) $GLOBALS['ab_test_options']['timezone_string'] );
		if ( false !== $zone ) {
			return round( timezone_offset_get( $zone, date_create() ) / HOUR_IN_SECONDS, 2 );
		}
	}
	return array_key_exists( $name, $GLOBALS['ab_test_options'] ) ? $GLOBALS['ab_test_options'][ $name ] : $default;
}

function update_option( $name, $value ) {
	$GLOBALS['ab_test_options'][ $name ] = $value;
	// Counted so a test can prove that a request which changes nothing also
	// writes nothing. On a real site every write is a database round trip.
	$GLOBALS['ab_test_writes'][ $name ] = ( $GLOBALS['ab_test_writes'][ $name ] ?? 0 ) + 1;
	return true;
}

/**
 * How often update_option() was called for one option since the last reset.
 */
function ab_test_writes( string $name ): int {
	return $GLOBALS['ab_test_writes'][ $name ] ?? 0;
}

function delete_option( $name ) {
	unset( $GLOBALS['ab_test_options'][ $name ] );
	return true;
}

function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['ab_test_transients'] ) ? $GLOBALS['ab_test_transients'][ $key ] : false;
}

function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['ab_test_transients'][ $key ] = $value;
	// Die Laufzeit wird mitgeschrieben, weil genau sie zu prüfen ist: ein
	// Zähler, der bei jedem zugelassenen Schreiben eine volle Stunde bekommt,
	// beginnt erst nach einer Stunde ohne zugelassene Anfrage von vorn.
	$GLOBALS['ab_test_transient_ttl'][ $key ] = $ttl;
	return true;
}

function delete_transient( $key ) {
	unset( $GLOBALS['ab_test_transients'][ $key ], $GLOBALS['ab_test_transient_ttl'][ $key ] );
	return true;
}

/* -------------------------------------------------------------- filter API */

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['ab_test_filters'][ $hook ][] = array(
		'fn'   => $callback,
		'prio' => $priority,
	);
	usort(
		$GLOBALS['ab_test_filters'][ $hook ],
		static fn( array $a, array $b ): int => $a['prio'] <=> $b['prio']
	);
	return true;
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['ab_test_filters'][ $hook ] ?? array() as $entry ) {
		$value = ( $entry['fn'] )( $value, ...$args );
	}
	return $value;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $hook, $callback, $priority, $accepted_args );
}

function remove_filter( $hook, $callback, $priority = 10 ) {
	foreach ( $GLOBALS['ab_test_filters'][ $hook ] ?? array() as $i => $entry ) {
		if ( $entry['fn'] === $callback && $entry['prio'] === $priority ) {
			unset( $GLOBALS['ab_test_filters'][ $hook ][ $i ] );
			return true;
		}
	}
	return false;
}

function remove_action( $hook, $callback, $priority = 10 ) {
	return remove_filter( $hook, $callback, $priority );
}

/** Runs what add_action() hooked, with the elements of $args as arguments. */
function do_action_ref_array( $hook, $args ) {
	foreach ( $GLOBALS['ab_test_filters'][ $hook ] ?? array() as $entry ) {
		( $entry['fn'] )( ...$args );
	}
}

/**
 * As in WordPress: without a callback, whether the hook has any; with one, its
 * priority when it is registered, else false.
 */
function has_filter( $hook, $callback = false ) {
	$entries = $GLOBALS['ab_test_filters'][ $hook ] ?? array();
	if ( false === $callback ) {
		return array() !== $entries;
	}
	foreach ( $entries as $entry ) {
		if ( $entry['fn'] === $callback ) {
			return $entry['prio'];
		}
	}
	return false;
}

function has_action( $hook, $callback = false ) {
	return has_filter( $hook, $callback );
}

/** As in WordPress: removes the callback registered at that priority. */
function remove_filter( $hook, $callback, $priority = 10 ) {
	foreach ( $GLOBALS['ab_test_filters'][ $hook ] ?? array() as $i => $entry ) {
		if ( $entry['fn'] === $callback && $entry['prio'] === $priority ) {
			unset( $GLOBALS['ab_test_filters'][ $hook ][ $i ] );
			$GLOBALS['ab_test_filters'][ $hook ] = array_values( $GLOBALS['ab_test_filters'][ $hook ] );
			return true;
		}
	}
	return false;
}

function remove_action( $hook, $callback, $priority = 10 ) {
	return remove_filter( $hook, $callback, $priority );
}

function __return_true() {
	return true;
}

function __return_false() {
	return false;
}


/* ------------------------------------------------------------- WP helpers */

function wp_parse_args( $args, $defaults = array() ) {
	if ( is_object( $args ) ) {
		$args = get_object_vars( $args );
	}
	if ( ! is_array( $args ) ) {
		parse_str( (string) $args, $args );
	}
	return array_merge( (array) $defaults, $args );
}

function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function __( $text, $domain = '' ) {
	return $text;
}

function esc_html__( $text, $domain = '' ) {
	return $text;
}

function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

function site_url( $path = '' ) {
	return 'https://example.test' . $path;
}

function esc_url_raw( $url ) {
	return (string) $url;
}

function wp_set_current_user( $id ) {
	$GLOBALS['ab_test_current_user'] = (int) $id;
}

function rest_url( $path = '' ) {
	return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}

function get_bloginfo( $what = '' ) {
	return 'version' === $what ? '6.9.1' : 'Example Site';
}

/**
 * Minimal stand-in for WP_User: the plugin only reads ->ID.
 */
class WP_User {
	public $ID;

	public function __construct( int $id ) {
		$this->ID = $id;
	}
}

function ab_test_add_user( int $id ): void {
	$GLOBALS['ab_test_users'][ $id ] = new WP_User( $id );
}

function get_user_by( $field, $value ) {
	if ( 'id' !== $field ) {
		return false;
	}
	return $GLOBALS['ab_test_users'][ (int) $value ] ?? false;
}

/* ------------------------------------------- users, capabilities, posts */

/** @var int $ab_test_current_user */
$GLOBALS['ab_test_current_user'] = 0;
/** @var callable|null $ab_test_can Decides current_user_can(): fn( string $cap, array $args ): bool */
$GLOBALS['ab_test_can'] = null;
/** @var bool $ab_test_multisite */
$GLOBALS['ab_test_multisite'] = false;
/** @var int[] $ab_test_super_admins */
$GLOBALS['ab_test_super_admins'] = array();
/** @var array<int,object> $ab_test_posts */
$GLOBALS['ab_test_posts'] = array();

function get_current_user_id() {
	return (int) $GLOBALS['ab_test_current_user'];
}

/**
 * Capabilities are not emulated as a role model: each test says, through a
 * closure, exactly which checks pass. A test that forgets to say so gets
 * "no" for everything — the safe direction.
 */
function current_user_can( $cap, ...$args ) {
	$decide = $GLOBALS['ab_test_can'];
	return is_callable( $decide ) ? (bool) $decide( (string) $cap, $args ) : false;
}

function is_multisite() {
	return (bool) $GLOBALS['ab_test_multisite'];
}

/**
 * get_sites() as WP_Site_Query answers it for the arguments uninstall uses:
 * 'fields' => 'ids', and 'number' — 100 unless given, 0 for no limit. The
 * default matters: a loop over get_sites() without 'number' stops at the
 * hundredth site of a network.
 */
function get_sites( $args = array() ) {
	$args   = (array) $args;
	$number = array_key_exists( 'number', $args ) ? absint( $args['number'] ) : 100;
	$ids    = 0 === $number ? $GLOBALS['ab_test_sites'] : array_slice( $GLOBALS['ab_test_sites'], 0, $number );
	if ( 'ids' === ( $args['fields'] ?? '' ) ) {
		return $ids;
	}
	// Without 'fields' => 'ids' WordPress answers site objects, not numbers.
	return array_map(
		static function ( $id ) {
			return (object) array( 'blog_id' => (string) $id );
		},
		$ids
	);
}

/**
 * Every site of a network keeps its own options. Switching parks the current
 * site's options and brings the other site's in.
 */
function ab_test_enter_blog( int $id ): void {
	$GLOBALS['ab_test_blog_store'][ $GLOBALS['ab_test_blog'] ] = $GLOBALS['ab_test_options'];
	$GLOBALS['ab_test_options']                                = $GLOBALS['ab_test_blog_store'][ $id ] ?? array();
	$GLOBALS['ab_test_blog']                                   = $id;
}

function switch_to_blog( $id ) {
	// A site object where an id belongs is a bug in the caller; WordPress would
	// not say so, the emulation does.
	if ( ! is_numeric( $id ) ) {
		throw new TypeError( 'switch_to_blog() expects a site id, got ' . get_debug_type( $id ) );
	}
	$GLOBALS['ab_test_blog_stack'][] = $GLOBALS['ab_test_blog'];
	// WordPress stays on the current site for an empty id.
	ab_test_enter_blog( 0 === (int) $id ? $GLOBALS['ab_test_blog'] : (int) $id );
	return true;
}

function restore_current_blog() {
	// WordPress answers false and stays where it is when nothing was switched.
	if ( empty( $GLOBALS['ab_test_blog_stack'] ) ) {
		return false;
	}
	ab_test_enter_blog( (int) array_pop( $GLOBALS['ab_test_blog_stack'] ) );
	return true;
}

function is_super_admin( $user_id = false ) {
	$id = ( false === $user_id ) ? get_current_user_id() : (int) $user_id;
	return in_array( $id, $GLOBALS['ab_test_super_admins'], true );
}

function wp_salt( $scheme = 'auth' ) {
	return 'test-salt-' . $scheme;
}

function ab_test_add_post( int $id, array $fields = array() ): object {
	$post = (object) array_merge(
		array(
			'ID'            => $id,
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_password' => '',
			'post_title'    => 'Post ' . $id,
			'post_content'  => 'Text of post ' . $id,
			'post_excerpt'  => '',
			'post_parent'   => 0,
			'post_author'   => 1,
			'menu_order'    => 0,
			'post_name'     => 'post-' . $id,
			'post_date_gmt' => '2026-09-21 10:00:00',
			'post_date'     => '2026-09-21 10:00:00',
			'post_modified_gmt' => '2026-09-21 10:00:00',
			'post_modified' => '2026-09-21 10:00:00',
			'post_mime_type' => '',
		),
		$fields
	);
	$GLOBALS['ab_test_posts'][ $id ] = $post;
	return $post;
}

function get_post( $post = null ) {
	return $GLOBALS['ab_test_posts'][ (int) $post ] ?? null;
}

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/** @var bool $ab_test_json_fail When true, wp_json_encode() fails like it does on unencodable input. */
$GLOBALS['ab_test_json_fail'] = false;

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	if ( ! empty( $GLOBALS['ab_test_json_fail'] ) ) {
		return false;
	}
	return json_encode( $data, $options, $depth );
}

/* -------------------------------------------------------- queries, summaries */

/** @var callable|null $ab_test_query Answers WP_Query: fn( array $args ): object[] */
$GLOBALS['ab_test_query'] = null;

class WP_Query {
	public $posts         = array();
	public $found_posts   = 0;
	public $max_num_pages = 0;
	public $args;
	/** @var string The WHERE clause posts_where made of '' for this query. */
	public $where         = '';

	public function __construct( $args = array() ) {
		$this->args = $args;
		// Like WP_Query::get_posts(): the WHERE filter runs with the query
		// object, so a filter can tell its own query from any other.
		$this->where         = (string) apply_filters( 'posts_where', '', $this );
		$answer              = $GLOBALS['ab_test_query'];
		$this->posts         = is_callable( $answer ) ? (array) $answer( $args, $this->where ) : array();
		$this->found_posts   = count( $this->posts );
		$this->max_num_pages = $this->found_posts > 0 ? 1 : 0;
	}

	/** A query var, as WP_Query::get(). */
	public function get( $key, $default = '' ) {
		return $this->args[ $key ] ?? $default;
	}
}

function get_the_title( $post ) {
	return (string) $post->post_title;
}

function get_permalink( $post ) {
	return 'https://example.test/?p=' . (int) $post->ID;
}

// Like core: a protected post yields a placeholder, otherwise the stored
// excerpt or the start of the text.
function get_the_excerpt( $post ) {
	if ( '' !== (string) $post->post_password ) {
		return 'There is no excerpt because this is a protected post.';
	}
	return '' !== (string) $post->post_excerpt ? (string) $post->post_excerpt : substr( (string) $post->post_content, 0, 55 );
}

function wp_strip_all_tags( $text ) {
	return strip_tags( (string) $text );
}

/* ---------------------------------------------------- themes, network */

/** @var string $ab_test_template Active (parent) theme. */
$GLOBALS['ab_test_template']   = 'twentytwentyfive';
/** @var string $ab_test_stylesheet Active (child) theme. */
$GLOBALS['ab_test_stylesheet'] = 'twentytwentyfive';
/** @var array<string,mixed> $ab_test_site_options Network options. */
$GLOBALS['ab_test_site_options'] = array();

function get_template() {
	return (string) $GLOBALS['ab_test_template'];
}

function get_stylesheet() {
	return (string) $GLOBALS['ab_test_stylesheet'];
}

function get_site_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['ab_test_site_options'] ) ? $GLOBALS['ab_test_site_options'][ $name ] : $default;
}

/**
 * Post meta from $ab_test_meta, which holds values as the database does:
 * serialized where WordPress serialized them. As in WordPress, a key asked
 * for comes out unserialized — every value in the order it was added, or the
 * first — and without a key every key comes out with its values raw.
 */
function get_post_meta( $post_id, $key = '', $single = false ) {
	if ( '' === $key ) {
		return $GLOBALS['ab_test_meta'][ (int) $post_id ] ?? array();
	}
	$values = array_map( 'maybe_unserialize', $GLOBALS['ab_test_meta'][ (int) $post_id ][ $key ] ?? array() );
	return $single ? ( $values ? $values[0] : '' ) : $values;
}

/**
 * add_post_meta() as add_metadata() works: key and value unslashed all the
 * way down, objects included (stripslashes_deep() is map_deep()), the
 * sanitize filter, then maybe_serialize() — which serializes a string that
 * already looks serialized a second time. The stored form is appended. As in
 * core, meta for a revision goes to the post the revision belongs to.
 */
function add_post_meta( $post_id, $meta_key, $meta_value, $unique = false ) {
	$post_id    = wp_is_post_revision( $post_id ) ?: $post_id;
	$meta_key   = stripslashes( (string) $meta_key );
	$meta_value = stripslashes_deep( $meta_value );
	$meta_value = apply_filters( "sanitize_post_meta_{$meta_key}", $meta_value, $meta_key, 'post' );
	if ( $unique && ! empty( $GLOBALS['ab_test_meta'][ (int) $post_id ][ $meta_key ] ) ) {
		return false;
	}
	$GLOBALS['ab_test_meta'][ (int) $post_id ][ $meta_key ][] = maybe_serialize( $meta_value );
	$GLOBALS['ab_test_meta_added'][] = array( (int) $post_id, $meta_key );
	return count( $GLOBALS['ab_test_meta_added'] );
}

// The four helpers below are WordPress' own (6.9 / 7.0), so a test sees
// exactly what core does with a value on its way into the meta table.

function is_protected_meta( $meta_key, $meta_type = '' ) {
	$sanitized_key = preg_replace( "/[^\x20-\x7E\p{L}]/", '', (string) $meta_key );
	$protected     = strlen( $sanitized_key ) > 0 && ( '_' === $sanitized_key[0] );
	return apply_filters( 'is_protected_meta', $protected, $meta_key, $meta_type );
}

function map_deep( $value, $callback ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $index => $item ) {
			$value[ $index ] = map_deep( $item, $callback );
		}
	} elseif ( is_object( $value ) ) {
		$object_vars = get_object_vars( $value );
		foreach ( $object_vars as $property_name => $property_value ) {
			$value->$property_name = map_deep( $property_value, $callback );
		}
	} else {
		$value = call_user_func( $callback, $value );
	}
	return $value;
}

function stripslashes_from_strings_only( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function stripslashes_deep( $value ) {
	return map_deep( $value, 'stripslashes_from_strings_only' );
}

function is_serialized( $data, $strict = true ) {
	if ( ! is_string( $data ) ) {
		return false;
	}
	$data = trim( $data );
	if ( 'N;' === $data ) {
		return true;
	}
	if ( strlen( $data ) < 4 ) {
		return false;
	}
	if ( ':' !== $data[1] ) {
		return false;
	}
	if ( $strict ) {
		$lastc = substr( $data, -1 );
		if ( ';' !== $lastc && '}' !== $lastc ) {
			return false;
		}
	} else {
		$semicolon = strpos( $data, ';' );
		$brace     = strpos( $data, '}' );
		if ( false === $semicolon && false === $brace ) {
			return false;
		}
		if ( false !== $semicolon && $semicolon < 3 ) {
			return false;
		}
		if ( false !== $brace && $brace < 4 ) {
			return false;
		}
	}
	$token = $data[0];
	switch ( $token ) {
		case 's':
			if ( $strict ) {
				if ( '"' !== substr( $data, -2, 1 ) ) {
					return false;
				}
			} elseif ( ! str_contains( $data, '"' ) ) {
				return false;
			}
			// Or else fall through.
		case 'a':
		case 'O':
		case 'E':
			return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
		case 'b':
		case 'i':
		case 'd':
			$end = $strict ? '$' : '';
			return (bool) preg_match( "/^{$token}:[0-9.E+-]+;$end/", $data );
	}
	return false;
}

function maybe_serialize( $data ) {
	if ( is_array( $data ) || is_object( $data ) ) {
		return serialize( $data );
	}
	if ( is_serialized( $data, false ) ) {
		return serialize( $data );
	}
	return $data;
}

function maybe_unserialize( $data ) {
	if ( is_serialized( $data ) ) {
		return @unserialize( trim( $data ) );
	}
	return $data;
}

/** @var int[] $ab_test_rand Numbers wp_rand() hands out first, in order; random ones after. */
$GLOBALS['ab_test_rand'] = array();

function wp_rand( $min = null, $max = null ) {
	if ( ! empty( $GLOBALS['ab_test_rand'] ) ) {
		return (int) array_shift( $GLOBALS['ab_test_rand'] );
	}
	return random_int( (int) $min, (int) $max );
}

/** As core: the id of the post a revision belongs to, or false. */
function wp_is_post_revision( $post ) {
	$post = get_post( is_object( $post ) ? $post->ID : $post );
	return ( $post && 'revision' === $post->post_type ) ? (int) $post->post_parent : false;
}

/** As core: the key unslashed (delete_metadata()), a revision's meta deleted from its post. */
function delete_post_meta( $post_id, $key, $value = '' ) {
	$post_id = wp_is_post_revision( $post_id ) ?: $post_id;
	$key     = stripslashes( (string) $key );
	$GLOBALS['ab_test_meta_deleted'][] = array( (int) $post_id, $key );
	unset( $GLOBALS['ab_test_meta'][ (int) $post_id ][ $key ] );
	return true;
}

/** Runs what add_action() hooked, and notes the call. */
function do_action( $hook, ...$args ) {
	$GLOBALS['ab_test_actions'][] = array( $hook, $args );
	foreach ( $GLOBALS['ab_test_filters'][ $hook ] ?? array() as $entry ) {
		( $entry['fn'] )( ...$args );
	}
}

/** No taxonomies: the copy made by wp_duplicate_post carries no terms here. */
function get_object_taxonomies( $object ) {
	return array();
}

/** As WordPress: into the trash, or false — already there, or a pre_trash_post filter said no. */
function wp_trash_post( $post_id = 0 ) {
	$GLOBALS['ab_test_trashed'][] = (int) $post_id;
	$post = get_post( (int) $post_id );
	if ( ! $post || 'trash' === $post->post_status || ! empty( $GLOBALS['ab_test_trash_refused'] ) ) {
		return false;
	}
	$post->post_status = 'trash';
	return $post;
}

/** Deletes for good (only called with force here), and notes the call. */
function wp_delete_post( $post_id = 0, $force_delete = false ) {
	$GLOBALS['ab_test_deleted'][] = array( (int) $post_id, (bool) $force_delete );
	$post = get_post( (int) $post_id );
	if ( ! $post ) {
		return false;
	}
	unset( $GLOBALS['ab_test_posts'][ (int) $post_id ] );
	return $post;
}

/** Notes that the comments of a post were given their states back. */
function wp_untrash_post_comments( $post = null ) {
	$GLOBALS['ab_test_comments_untrashed'][] = is_object( $post ) ? (int) $post->ID : (int) $post;
	return true;
}

/* ------------------------------------------------ comments, revisions, insert */

/** @var array<int,object> $ab_test_comments */
$GLOBALS['ab_test_comments'] = array();
/** @var array<int,object[]> $ab_test_revisions Post id => its revisions. */
$GLOBALS['ab_test_revisions'] = array();
/** @var array[] $ab_test_inserted What wp_insert_post() was handed, unslashed. */
$GLOBALS['ab_test_inserted'] = array();

function get_comment( $id ) {
	return $GLOBALS['ab_test_comments'][ (int) $id ] ?? null;
}

function get_comments( $args = array() ) {
	return array_values( $GLOBALS['ab_test_comments'] );
}

function wp_get_comment_status( $id ) {
	return 'approved';
}

function wp_get_post_revisions( $post_id ) {
	return $GLOBALS['ab_test_revisions'][ (int) $post_id ] ?? array();
}

function get_post_type_object( $type ) {
	if ( ! in_array( $type, array( 'post', 'page', 'attachment', 'revision' ), true ) ) {
		return null;
	}
	return (object) array(
		'name' => $type,
		'cap'  => (object) array(
			// As WordPress: a file is created with the right to upload.
			'create_posts'      => 'attachment' === $type ? 'upload_files' : 'edit_posts',
			'publish_posts'     => 'publish_posts',
			'edit_others_posts' => 'edit_others_posts',
		),
	);
}

/** wp_slash() and wp_insert_post() as a pair: core slashes on the way in and unslashes inside. */
function wp_slash( $value ) {
	return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value );
}

function ab_test_unslash_deep( $value ) {
	return is_array( $value ) ? array_map( 'ab_test_unslash_deep', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value );
}

/**
 * The one decision of wp_insert_post() the tools depend on: "publish" and
 * "future" settled by the UTC date against the clock, a lead of a minute
 * making a post "future" — then wp_insert_post_data, which core runs after
 * that decision and before it writes the row. WordPress reads its clock a
 * moment after the tool read it; ab_test_core_delay is that moment. Without
 * a UTC date in hand the status stays as handed: the undated cases (a draft
 * dated at the save) are not modelled here.
 */
function ab_test_core_save( array $data, array $postarr ): array {
	$gmt = (string) ( $data['post_date_gmt'] ?? '' );
	if ( 'attachment' !== $data['post_type'] && '' !== $gmt && '0000-00-00 00:00:00' !== $gmt ) {
		$lead = strtotime( $gmt . ' UTC' ) - ( (int) current_time( 'timestamp', true ) + (int) $GLOBALS['ab_test_core_delay'] );
		if ( 'publish' === $data['post_status'] && $lead >= MINUTE_IN_SECONDS ) {
			$data['post_status'] = 'future';
		} elseif ( 'future' === $data['post_status'] && $lead < MINUTE_IN_SECONDS ) {
			$data['post_status'] = 'publish';
		}
	}
	return apply_filters( 'wp_insert_post_data', $data, $postarr );
}

/**
 * Records what it is handed and stores a post from it, with the status
 * ab_test_core_save() settles. The dates are stored as given; how WordPress
 * derives a missing post_date_gmt is not modelled.
 */
function wp_insert_post( $postarr, $wp_error = false ) {
	$postarr                        = ab_test_unslash_deep( $postarr );
	$GLOBALS['ab_test_inserted'][] = $postarr;
	$GLOBALS['ab_test_insert_wp_error'][] = $wp_error;
	if ( ! empty( $GLOBALS['ab_test_insert_fails'] ) ) {
		return $wp_error ? new WP_Error( 'db_insert_error', 'Could not insert post into the database.' ) : 0;
	}
	$id                             = 1000 + count( $GLOBALS['ab_test_inserted'] );
	ab_test_add_post(
		$id,
		ab_test_core_save(
			array(
				'post_type'     => $postarr['post_type'] ?? 'post',
				'post_status'   => $postarr['post_status'] ?? 'draft',
				'post_title'    => $postarr['post_title'] ?? '',
				'post_date'     => $postarr['post_date'] ?? '2026-09-21 10:00:00',
				'post_date_gmt' => $postarr['post_date_gmt'] ?? '0000-00-00 00:00:00',
			),
			$postarr
		)
	);
	// Kept apart from the data above, which wp_insert_post_data filters in tests see.
	$GLOBALS['ab_test_posts'][ $id ]->post_parent = (int) ( $postarr['post_parent'] ?? 0 );
	return $id;
}

/** @var array[] $ab_test_updated What wp_update_post() was handed, unslashed. */
$GLOBALS['ab_test_updated'] = array();

/**
 * Records what it is handed and writes those columns onto the stored post,
 * with the status ab_test_core_save() settles from the handed status and
 * date, or the stored ones. How WordPress gives a draft without a UTC date the
 * time of the save is not modelled.
 */
function wp_update_post( $postarr, $wp_error = false ) {
	$postarr                       = ab_test_unslash_deep( $postarr );
	$GLOBALS['ab_test_updated'][] = $postarr;
	$GLOBALS['ab_test_update_wp_error'][] = $wp_error;
	$post                          = get_post( (int) ( $postarr['ID'] ?? 0 ) );
	if ( ! $post ) {
		return $wp_error ? new WP_Error( 'invalid_post', 'Invalid post ID.' ) : 0;
	}
	// A save that fails before it writes anything (an empty post, the database).
	if ( ! empty( $GLOBALS['ab_test_update_fails'] ) ) {
		return $wp_error ? new WP_Error( 'db_update_error', 'Could not update post in the database.' ) : 0;
	}
	$data = ab_test_core_save(
		array(
			'post_type'     => $post->post_type,
			'post_status'   => $postarr['post_status'] ?? $post->post_status,
			'post_date_gmt' => $postarr['post_date_gmt'] ?? $post->post_date_gmt,
		),
		$postarr
	);
	// Like core, only post columns are written; anything else handed over is not.
	foreach ( $postarr as $key => $value ) {
		if ( 0 === strpos( (string) $key, 'post_' ) ) {
			$post->$key = $value;
		}
	}
	$post->post_status = $data['post_status'];
	// As core where the post's page template is gone: with $wp_error the row
	// is written, then the error comes back, before any hook; without it the
	// template falls back to the default and the save goes on.
	if ( ! empty( $GLOBALS['ab_test_update_errors_after_write'] ) ) {
		if ( $wp_error ) {
			return new WP_Error( 'invalid_page_template', 'Invalid page template.' );
		}
		$GLOBALS['ab_test_template_reset'][] = (int) $post->ID;
	}
	return (int) $post->ID;
}

function wp_get_attachment_url( $id ) {
	return 'https://example.test/wp-content/uploads/' . (int) $id . '.jpg';
}

function wp_get_attachment_metadata( $id ) {
	return array();
}

/* ---------------------------------------------------------- admin ajax */

/**
 * wp_send_json_*() end the request. Here they end the handler by throwing,
 * so a test can read status and payload and assert on the state left behind.
 */
class AbTestJsonExit extends Exception {
	public $status;
	public $payload;

	public function __construct( int $status, $payload ) {
		parent::__construct( 'json exit ' . $status );
		$this->status  = $status;
		$this->payload = $payload;
	}
}

function wp_send_json_error( $data = null, $status_code = null ) {
	throw new AbTestJsonExit( (int) ( $status_code ?? 500 ), $data );
}

function wp_send_json_success( $data = null, $status_code = null ) {
	throw new AbTestJsonExit( (int) ( $status_code ?? 200 ), $data );
}

function check_ajax_referer( $action = -1, $query_arg = false, $die = true ) {
	return 1;
}

function wp_unslash( $value ) {
	return $value;
}

function absint( $value ) {
	return abs( (int) $value );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

// Rendering helpers the connections row uses; the row's HTML is not under test.
function esc_attr( $text ) {
	return (string) $text;
}

function esc_attr__( $text, $domain = '' ) {
	return (string) $text;
}

function esc_url( $url ) {
	return (string) $url;
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

// Like WordPress: the hidden field names its action, so a test can see which
// action a form is signed for.
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
	$field = '<input type="hidden" name="' . $name . '" value="nonce-' . $action . '">';
	if ( $display ) {
		echo $field; // phpcs:ignore
	}
	return $field;
}

function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) {
	return (string) $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . $name . '=testnonce';
}

// The settings screen's assets, recorded so a test can see what loads where.
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
	$GLOBALS['ab_test_styles'][ $handle ] = (string) $src;
}

function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) {
	$GLOBALS['ab_test_scripts'][ $handle ] = (string) $src;
}

function wp_localize_script( $handle, $object_name, $l10n ) {
	return true;
}

function wp_create_nonce( $action = -1 ) {
	return 'testnonce';
}

// The settings screen's builders: plural forms, the admin's language, the
// user dropdown and checked(), as WordPress answers them.
function _n( $single, $plural, $number, $domain = '' ) {
	return 1 === (int) $number ? (string) $single : (string) $plural;
}

function get_locale() {
	return (string) ( $GLOBALS['ab_test_locale'] ?? 'en_US' );
}

function checked( $checked, $current = true, $display = true ) {
	$out = ( (string) $checked === (string) $current ) ? " checked='checked'" : '';
	if ( $display ) {
		echo $out; // phpcs:ignore
	}
	return $out;
}

function wp_dropdown_users( $args = array() ) {
	$html = '<select name="' . ( $args['name'] ?? 'user' ) . '"></select>';
	if ( ! isset( $args['echo'] ) || $args['echo'] ) {
		echo $html; // phpcs:ignore
	}
	return $html;
}

/**
 * The site's timezone as WordPress derives it: the named zone if one is set,
 * otherwise a fixed offset built from gmt_offset (hours, may be fractional).
 */
function wp_timezone_string() {
	$timezone_string = get_option( 'timezone_string' );
	if ( $timezone_string ) {
		return (string) $timezone_string;
	}
	$offset  = (float) get_option( 'gmt_offset' );
	$hours   = (int) $offset;
	$minutes = abs( ( $offset - $hours ) * 60 );
	return sprintf( '%s%02d:%02d', $offset < 0 ? '-' : '+', abs( $hours ), $minutes );
}

function wp_timezone() {
	return new DateTimeZone( wp_timezone_string() );
}

/**
 * current_time() as WordPress defines it. 'timestamp' and 'U' are time() PLUS
 * the site's UTC offset unless $gmt is set — not a real Unix timestamp. The
 * former stand-in returned time() and so hid exactly the mistake of comparing
 * one kind of timestamp with the other («Last used», 26.09.2026).
 */
function current_time( $type, $gmt = 0 ) {
	$now = null !== $GLOBALS['ab_test_now'] ? (int) $GLOBALS['ab_test_now'] : time();
	if ( 'timestamp' === $type || 'U' === $type ) {
		return $gmt ? $now : $now + (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
	}
	if ( 'mysql' === $type ) {
		$type = 'Y-m-d H:i:s';
	}
	$timezone = $gmt ? new DateTimeZone( 'UTC' ) : wp_timezone();
	return ( new DateTime( '@' . $now ) )->setTimezone( $timezone )->format( (string) $type );
}

/**
 * WordPress rounds to minutes, hours, days; this stand-in prints the exact
 * seconds so a test can see a shift of any size. Like WordPress it measures
 * against time() when no second argument is given.
 */
function human_time_diff( $from, $to = 0 ) {
	if ( empty( $to ) ) {
		$to = time();
	}
	return (int) abs( $to - $from ) . ' seconds';
}

/**
 * wp_date() as WordPress defines it: a real Unix timestamp shown in the
 * site's timezone (or the one given). Month and day names are not localised
 * here; numeric formats come out as in WordPress.
 */
function wp_date( $format, $timestamp = null, $timezone = null ) {
	if ( null === $timestamp ) {
		$timestamp = time();
	} elseif ( ! is_numeric( $timestamp ) ) {
		return false;
	}
	$datetime = date_create( '@' . (int) $timestamp );
	$datetime->setTimezone( $timezone ? $timezone : wp_timezone() );
	return $datetime->format( (string) $format );
}

/**
 * date_i18n() as WordPress (5.3 and later) defines it. The plugin no longer
 * calls it; the stand-in is faithful so that a return to it shows up as a
 * wrong time in the tests, not as a missing function. Given a timestamp, it
 * reads that number as ALREADY shifted to local time — its UTC clock reading
 * is taken as the local time. Handed a real Unix timestamp, it therefore
 * prints the UTC clock.
 */
function date_i18n( $format, $timestamp_with_offset = false, $gmt = false ) {
	$timestamp = $timestamp_with_offset;
	if ( ! is_numeric( $timestamp ) ) {
		$timestamp = current_time( 'timestamp', $gmt );
	}
	if ( 'U' === $format ) {
		return $timestamp;
	}
	if ( $gmt && false === $timestamp_with_offset ) {
		return wp_date( $format, null, new DateTimeZone( 'UTC' ) );
	}
	if ( false === $timestamp_with_offset ) {
		return wp_date( $format );
	}
	$timezone = wp_timezone();
	$datetime = date_create( gmdate( 'Y-m-d H:i:s', (int) $timestamp ), $timezone );
	return wp_date( $format, $datetime->getTimestamp(), $timezone );
}

/**
 * Minimal stand-in for WP_REST_Response.
 */
class WP_REST_Response {
	public $data;
	public $status;
	/** @var array<string,string> */
	public $headers = array();

	public function __construct( $data = null, int $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	public function header( string $key, string $value ): void {
		$this->headers[ $key ] = $value;
	}

	public function get_status(): int {
		return $this->status;
	}

	/** @return array<string,string> */
	public function get_headers(): array {
		return $this->headers;
	}

	public function get_data() {
		return $this->data;
	}
}

/**
 * Minimal stand-in for WP_REST_Request: body params, JSON params and headers.
 *
 * Header names are canonicalised like WP_REST_Request::canonicalize_header_name()
 * (lower case, dashes to underscores), so a test sends "MCP-Protocol-Version"
 * as a client spells it and the plugin reads "mcp_protocol_version" as WordPress
 * hands it over.
 */
class WP_REST_Request {
	/** @var array<string,mixed> */
	private $body = array();
	/** @var array<string,mixed>|null */
	private $json = null;
	/** @var array<string,string> */
	private $headers = array();
	/** @var string */
	private $route = '';
	/** @var string POST unless a test says otherwise: most tests call the POST handler directly. */
	private $method = 'POST';
	/** @var array<string,string> */
	private $url_params = array();

	/** Upper case, as WP_REST_Request::set_method() stores it. */
	public function set_method( string $method ): void {
		$this->method = strtoupper( $method );
	}

	public function get_method(): string {
		return $this->method;
	}

	/** @param array<string,string> $params */
	public function set_url_params( array $params ): void {
		$this->url_params = $params;
	}

	public function set_route( string $route ): void {
		$this->route = $route;
	}

	public function get_route(): string {
		return $this->route;
	}

	public function set_body_params( array $params ): void {
		$this->body = $params;
	}

	public function set_json_params( array $params ): void {
		$this->json = $params;
	}

	public function set_header( string $key, string $value ): void {
		$this->headers[ str_replace( '-', '_', strtolower( $key ) ) ] = $value;
	}

	public function get_body_params(): array {
		return $this->body;
	}

	public function get_json_params() {
		return $this->json;
	}

	public function get_header( $key ) {
		return $this->headers[ str_replace( '-', '_', strtolower( (string) $key ) ) ] ?? '';
	}

	public function get_param( $key ) {
		return $this->body[ $key ] ?? $this->url_params[ $key ] ?? null;
	}
}

/**
 * The part of the REST server that decides status and headers of an answer,
 * over the routes register_rest_route() recorded. Copied from WordPress 7.0.2:
 * WP_REST_Server::dispatch() (rest_pre_dispatch; a non-empty result is served
 * as it is and matches no route), match_request_to_handler() (the whole path,
 * case-insensitive; the first handler whose method fits; none: 404
 * rest_no_route), respond_to_request() (permission_callback, then callback;
 * WP_Error to response), then rest_post_dispatch with rest_send_allow_header()
 * first, as rest_api_default_filters() hooks it before any plugin: for a
 * matched route, Allow lists every method whose permission_callback returns
 * true for this request, and replaces whatever Allow the answer had.
 *
 * Left out: OPTIONS and HEAD, argument validation, embedding, batches.
 */
function ab_test_rest_dispatch( WP_REST_Request $request ): WP_REST_Response {
	$routes = array();
	foreach ( $GLOBALS['ab_test_routes'] as $entry ) {
		$handlers = isset( $entry['args']['methods'] ) ? array( $entry['args'] ) : $entry['args'];
		foreach ( $handlers as $handler ) {
			$methods = is_array( $handler['methods'] ) ? $handler['methods'] : preg_split( '/,\s*/', (string) $handler['methods'] );
			$handler['methods'] = array_fill_keys( array_map( 'strtoupper', $methods ), true );
			$routes[ '/' . trim( $entry['namespace'], '/' ) . $entry['route'] ][] = $handler;
		}
	}

	$pre      = apply_filters( 'rest_pre_dispatch', null, null, $request );
	$response = empty( $pre ) ? null : ab_test_rest_response( $pre );
	$matched  = null;
	if ( null === $response ) {
		foreach ( $routes as $route => $handlers ) {
			if ( 1 !== preg_match( '@^' . $route . '$@i', $request->get_route(), $m ) ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				if ( empty( $handler['methods'][ $request->get_method() ] ) ) {
					continue;
				}
				$request->set_url_params( array_filter( $m, 'is_string', ARRAY_FILTER_USE_KEY ) );
				$matched    = $route;
				$permission = call_user_func( $handler['permission_callback'], $request );
				$result     = true === $permission ? call_user_func( $handler['callback'], $request ) : $permission;
				$response   = ab_test_rest_response( $result );
				break 2;
			}
		}
		if ( null === $matched ) {
			$response = ab_test_rest_response( new WP_Error( 'rest_no_route', 'No route was found matching the URL and request method.', array( 'status' => 404 ) ) );
		}
	}

	// rest_send_allow_header().
	if ( null !== $matched ) {
		$allowed = array();
		foreach ( $routes[ $matched ] as $handler ) {
			foreach ( $handler['methods'] as $method => $on ) {
				$allowed[ $method ] = true === call_user_func( $handler['permission_callback'], $request );
			}
		}
		$allowed = array_filter( $allowed );
		if ( $allowed ) {
			$response->header( 'Allow', implode( ', ', array_keys( $allowed ) ) );
		}
	}

	return apply_filters( 'rest_post_dispatch', $response, null, $request );
}

/**
 * A callback's result as WordPress serves it: a WP_Error becomes its code,
 * message and data with the status from the data (500 without one).
 *
 * @param mixed $result
 */
function ab_test_rest_response( $result ): WP_REST_Response {
	if ( $result instanceof WP_Error ) {
		$data = $result->get_error_data();
		return new WP_REST_Response(
			array(
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
				'data'    => $data,
			),
			is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500
		);
	}
	return $result instanceof WP_REST_Response ? $result : new WP_REST_Response( $result );
}

/* ------------------------------------------------------------ URLs, HTTP */

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( (string) $url, $component );
}

function add_query_arg( $args, $url = '' ) {
	return (string) $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . http_build_query( (array) $args );
}

/** @var array<int,array{url:string,args:array,curl?:string}> $ab_test_http Every wp_safe_remote_get() call, in order. */
$GLOBALS['ab_test_http'] = array();
/** @var callable|null $ab_test_http_answer Answers wp_safe_remote_get(): fn( string $url, array $args ): array|WP_Error */
$GLOBALS['ab_test_http_answer'] = null;
/** @var bool $ab_test_http_curl When true, every call also records what cURL was told about host addresses ('curl'). */
$GLOBALS['ab_test_http_curl'] = false;

/**
 * Records the call and answers through ab_test_http_answer. Like WordPress,
 * limit_response_size cuts the body after that many bytes. Without an answer
 * configured, the request fails, as it would without network.
 *
 * As WordPress does with its cURL transport, the http_api_curl action runs
 * with a real cURL handle before the answer. With ab_test_http_curl set, that
 * handle is then started against a proxy on this machine that refuses the
 * connection: cURL loads CURLOPT_RESOLVE entries when a transfer starts and
 * says so in its verbose log ("added host:port:address"), and the proxy keeps
 * it from looking up or reaching the host itself. The log is recorded.
 */
function wp_safe_remote_get( $url, $args = array() ) {
	$call = array(
		'url'  => (string) $url,
		'args' => (array) $args,
	);
	if ( function_exists( 'curl_init' ) ) {
		$handle = curl_init();
		do_action_ref_array( 'http_api_curl', array( &$handle, (array) $args, (string) $url ) );
		if ( ! empty( $GLOBALS['ab_test_http_curl'] ) ) {
			$log = fopen( 'php://temp', 'w+' );
			curl_setopt_array(
				$handle,
				array(
					CURLOPT_URL               => (string) $url,
					CURLOPT_PROXY             => 'http://127.0.0.1:9',
					CURLOPT_VERBOSE           => true,
					CURLOPT_STDERR            => $log,
					CURLOPT_RETURNTRANSFER    => true,
					CURLOPT_CONNECTTIMEOUT_MS => 500,
					CURLOPT_TIMEOUT_MS        => 1000,
				)
			);
			curl_exec( $handle );
			rewind( $log );
			$call['curl'] = (string) stream_get_contents( $log );
			fclose( $log );
		}
	}
	$GLOBALS['ab_test_http'][] = $call;
	$answer = $GLOBALS['ab_test_http_answer'];
	if ( ! is_callable( $answer ) ) {
		return new WP_Error( 'http_request_failed', 'No network in the tests.' );
	}
	$response = $answer( (string) $url, (array) $args );
	if ( is_array( $response ) && isset( $args['limit_response_size'] ) ) {
		$response['body'] = substr( (string) $response['body'], 0, (int) $args['limit_response_size'] );
	}
	return $response;
}

/**
 * A response array as the WordPress HTTP API returns it. Header names are
 * looked up without regard to case, as WordPress does.
 *
 * @param array<string,string> $headers
 */
function ab_test_http_response( int $code, string $body, array $headers = array() ): array {
	return array(
		'response' => array(
			'code'    => $code,
			'message' => '',
		),
		'body'     => $body,
		'headers'  => array_change_key_case( $headers, CASE_LOWER ),
	);
}

function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) && isset( $response['response']['code'] ) ? $response['response']['code'] : '';
}

function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) && isset( $response['body'] ) ? $response['body'] : '';
}

function wp_remote_retrieve_header( $response, $header ) {
	$header = strtolower( (string) $header );
	return is_array( $response ) && isset( $response['headers'][ $header ] ) ? $response['headers'][ $header ] : '';
}

/* ------------------------------------------------- login, redirects, exits */

/** @var bool $ab_test_logged_in */
$GLOBALS['ab_test_logged_in'] = false;

function is_user_logged_in() {
	return (bool) $GLOBALS['ab_test_logged_in'];
}

/**
 * auth_redirect(), wp_redirect(), wp_safe_redirect() and wp_die() end the
 * request in WordPress. Here they end the code under test by throwing, so a
 * test can see which one was reached and with what.
 */
class AbTestExit extends Exception {
	/** @var string 'login' | 'redirect' | 'die' */
	public $kind;
	/** @var string Location or message. */
	public $detail;

	public function __construct( string $kind, string $detail = '' ) {
		parent::__construct( $kind . ': ' . $detail );
		$this->kind   = $kind;
		$this->detail = $detail;
	}
}

function auth_redirect() {
	throw new AbTestExit( 'login' );
}

function wp_redirect( $location, $status = 302, $x_redirect_by = 'WordPress' ) {
	throw new AbTestExit( 'redirect', (string) $location );
}

function wp_safe_redirect( $location, $status = 302, $x_redirect_by = 'WordPress' ) {
	throw new AbTestExit( 'redirect', (string) $location );
}

function wp_die( $message = '', $title = '', $args = array() ) {
	throw new AbTestExit( 'die', (string) $message );
}

function status_header( $code, $description = '' ) {
}

function nocache_headers() {
}

/** Like WordPress: the nonce made for an action (see wp_nonce_field()) verifies for that action only. */
function wp_verify_nonce( $nonce, $action = -1 ) {
	return 'nonce-' . $action === (string) $nonce ? 1 : false;
}

/**
 * Like WordPress: the nonce in the request must be the one made for this
 * action; otherwise the request ends ("The link you followed has expired").
 */
function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
	$result = isset( $_REQUEST[ $query_arg ] ) ? wp_verify_nonce( $_REQUEST[ $query_arg ], $action ) : false;
	if ( ! $result ) {
		wp_die( 'The link you followed has expired.' );
	}
	return $result;
}

/** @var array<int,array{namespace:string,route:string,args:array}> $ab_test_routes Every register_rest_route() call. */
$GLOBALS['ab_test_routes'] = array();

function register_rest_route( $route_namespace, $route, $args = array(), $override = false ) {
	$GLOBALS['ab_test_routes'][] = array(
		'namespace' => (string) $route_namespace,
		'route'     => (string) $route,
		'args'      => (array) $args,
	);
	return true;
}

/** No persistent object cache: counters live in transients, as on most sites. */
function wp_using_ext_object_cache( $using = null ) {
	return false;
}

/* ------------------------------------------------------- plugin constants */

// WordPress time constants the plugin relies on.
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

define( 'AB_MCP_VERSION', '4.3.6' );
define( 'AB_MCP_REST_NAMESPACE', 'alphabridge/v1' );
define( 'AB_MCP_REST_ROUTE', '/mcp' );
define( 'AB_MCP_PROTOCOL_VERSION', '2025-06-18' );
define( 'AB_MCP_MAX_BATCH', 25 );
define( 'AB_MCP_PATH', dirname( __DIR__ ) . '/' );
define( 'AB_MCP_URL', 'https://example.test/wp-content/plugins/alphabridge-mcp/' );

/* --------------------------------------------------------- classes to test */

require_once __DIR__ . '/../includes/class-tool-registry.php';
require_once __DIR__ . '/../includes/class-settings.php';
require_once __DIR__ . '/../includes/class-security.php';
require_once __DIR__ . '/../includes/class-audit-log.php';
require_once __DIR__ . '/../includes/class-review-notice.php';
require_once __DIR__ . '/../includes/class-auth.php';
require_once __DIR__ . '/../includes/class-oauth.php';
require_once __DIR__ . '/../includes/class-rest-controller.php';
// WordPress' block parser and serializer, fixed copies from 7.0.2 (see the
// file headers): the builder readers call parse_blocks().
require_once __DIR__ . '/support/class-wp-block-parser.php';
require_once __DIR__ . '/support/blocks-functions.php';
// WordPress' shortcode pattern and attribute parser, the yardstick for the
// shortcode reader (same version, same kind of copy).
require_once __DIR__ . '/support/shortcodes-functions.php';
require_once __DIR__ . '/../includes/builders/class-builders.php';
require_once __DIR__ . '/../includes/tools/class-tools-content.php';
require_once __DIR__ . '/../includes/tools/class-tools-builders.php';
require_once __DIR__ . '/../includes/tools/class-tools-search-bulk.php';
require_once __DIR__ . '/../includes/tools/class-tools-media.php';
require_once __DIR__ . '/../includes/tools/class-tools-taxonomy-comments.php';
require_once __DIR__ . '/../includes/class-admin.php';
