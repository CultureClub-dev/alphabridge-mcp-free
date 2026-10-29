<?php
/**
 * The guards of wp_search_replace and its engine (Pro 4.5.5). No WordPress:
 * a $wpdb stub hands out canned rows regardless of the WHERE clause (so the
 * per-row policy in PHP is exercised too), records writes and every prepared
 * statement; the term filters are a stub that records its calls, strips tags
 * and keeps WordPress' slashing convention.
 *
 * Run:  php test/search-replace-guards-test.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
define( 'AB_MCP_PRO_DIR', dirname( __DIR__, 2 ) . '/' );
// A site without a trash: this file runs again with AB_TEST_NO_TRASH set (see the end), for that one case.
if ( false !== getenv( 'AB_TEST_NO_TRASH' ) ) { define( 'EMPTY_TRASH_DAYS', 0 ); }
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { public $code; public $message; public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; } public function get_error_message() { return $this->message; } public function get_error_code() { return $this->code; } }
}
function __( $s, $d = null ) { return $s; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function apply_filters( $h, $v, ...$a ) { if ( isset( $GLOBALS['t_filters'][ $h ] ) ) { return $GLOBALS['t_filters'][ $h ]( $v, ...$a ); } return $v; }
// Filterable in WordPress; here a "filter" says no for _hidden and _vault,
// so only the literal underscore rule can keep them out.
function is_protected_meta( $key, $type = null ) { return '_' === substr( $key, 0, 1 ) && ! in_array( $key, array( '_hidden', '_vault' ), true ); }
// Taxonomies: category and post_tag public; product_cat non-public; brand public
// but its assign_terms refused (termmeta needs it, names do not).
function get_taxonomy( $name ) { $mk = static function ( $public, $assign ) { return (object) array( 'public' => $public, 'cap' => (object) array( 'assign_terms' => $assign, 'edit_terms' => 'manage_categories' ) ); }; $t = array( 'category' => $mk( true, 'edit_posts' ), 'post_tag' => $mk( true, 'edit_posts' ), 'product_cat' => $mk( false, 'assign_product_cat' ), 'brand' => $mk( true, 'assign_brand' ), 'wp_theme' => $mk( false, 'edit_theme_options' ), 'wp_template_part_area' => $mk( false, 'edit_theme_options' ) ); return isset( $t[ $name ] ) ? $t[ $name ] : false; }
// The caller: may edit others' posts, attachments and notes minus private notes,
// not pages. Post 12 cannot be read (a filter), 9, 10 and 14 cannot be edited
// (14 is the parent of revision 13), the meta key sealed_data and the termmeta
// key sealed have their own refusal, term 6 cannot be edited.
function current_user_can( $cap, ...$args ) {
	if ( 'manage_options' === $cap && ! empty( $GLOBALS['t_no_manage'] ) ) { return false; }
	if ( 'publish_posts' === $cap && ! empty( $GLOBALS['t_no_publish'] ) ) { return false; }
	if ( 'unfiltered_html' === $cap ) { return empty( $GLOBALS['t_no_unfiltered'] ); }
	if ( 'edit_others_pages' === $cap || 'edit_private_notes' === $cap || 'edit_products' === $cap || 'edit_ticket' === $cap ) { return false; }
	if ( 'read_post' === $cap && 12 === (int) $args[0] ) { return false; }
	if ( 'edit_post' === $cap && in_array( (int) $args[0], array( 9, 10, 14 ), true ) ) { return false; }
	if ( 'edit_post_meta' === $cap && 'sealed_data' === $args[1] ) { return false; }
	if ( 'edit_term' === $cap && 6 === (int) $args[0] ) { return false; }
	if ( 'assign_product_cat' === $cap || 'assign_brand' === $cap ) { return false; }
	if ( 'edit_term_meta' === $cap && 'sealed' === $args[1] ) { return false; }
	return true;
}
function get_the_title( $p ) { return 'Post ' . $p; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function get_post_type( $p ) { return isset( $GLOBALS['t_bulk_posts'][ (int) $p ]['post_type'] ) ? $GLOBALS['t_bulk_posts'][ (int) $p ]['post_type'] : 'post'; }
/* The posts the bulk status tool is called on, as rows. */
function get_post( $id ) { return isset( $GLOBALS['t_bulk_posts'][ (int) $id ] ) ? (object) ( array( 'ID' => (int) $id ) + $GLOBALS['t_bulk_posts'][ (int) $id ] ) : null; }
// As WordPress resolves it: a file stored as "inherit" without a parent reads as "publish".
function get_post_status( $id ) { $p = get_post( $id ); if ( ! $p ) { return false; } return ( 'attachment' === $p->post_type && 'inherit' === $p->post_status && 0 === (int) $p->post_parent ) ? 'publish' : $p->post_status; }
class AB_MCP_Undo {
	public static function record( array $e ) { $GLOBALS['t_undo'][] = $e; return 'undo-token'; }
	public static function option_snapshot( $n ) { return array( 'name' => $n, 'value' => 'foo', 'autoload' => 'yes', 'existed' => true ); }
	public static function record_result( array $r ) { $r['writes_before'] = count( $GLOBALS['t_opt'] ); $GLOBALS['t_undo_rows'][] = $r; return isset( $GLOBALS['t_undo_fail'] ) ? new WP_Error( $GLOBALS['t_undo_fail'], 'no room' ) : 'undo_opt'; }
}
function get_current_user_id() { return 7; }
function registered_meta_key_exists( $type, $key, $subtype = '' ) { return false; }
// Like core: a registered sanitize_callback shapes the value before the filter sees it.
function sanitize_meta( $key, $value, $type, $subtype = '' ) {
	if ( ! empty( $GLOBALS['t_meta_sanitize_back'] ) && 'color' === $key ) { return 'foo'; }
	// The taxonomy decides: only the category sanitiser upper-cases.
	return ( 'color' === $key && 'category' === $subtype && is_string( $value ) ) ? strtoupper( $value ) : $value;
}
function delete_post_meta( $id, $key ) { $GLOBALS['t_del_meta'][] = array( $id, $key ); unset( $GLOBALS['t_meta_store'][ (int) $id ][ $key ] ); return true; }
// The post meta the trash notes live in: every value of a key, in the order it was added — WordPress reads the first.
$GLOBALS['t_meta_store'] = array();
function get_post_meta( $id, $key = '', $single = false ) { $v = $GLOBALS['t_meta_store'][ (int) $id ][ $key ] ?? array(); return $single ? ( $v ? $v[0] : '' ) : $v; }
function add_post_meta( $id, $key, $value, $unique = false ) { $GLOBALS['t_meta_store'][ (int) $id ][ $key ][] = wp_unslash( $value ); $GLOBALS['t_add_meta'][] = array( (int) $id, $key ); return true; }
function delete_option( $name ) { $GLOBALS['t_del_opt'][] = $name; return true; }
// Every action fired is recorded; nothing listens, as on a site without Elementor.
function do_action( $hook, ...$args ) { $GLOBALS['t_actions'][] = array( $hook, $args ); }
function get_post_types( $args = array(), $output = 'names' ) {
	if ( isset( $GLOBALS['t_types'] ) ) { return $GLOBALS['t_types']; }
	// Core types: map_meta_cap, with the four capabilities edit_post maps to.
	$mk = static function ( $name, $plural ) { return (object) array( 'name' => $name, 'map_meta_cap' => true, 'cap' => (object) array( 'edit_post' => "edit_{$name}", 'edit_posts' => "edit_{$plural}", 'edit_others_posts' => "edit_others_{$plural}", 'edit_published_posts' => "edit_published_{$plural}", 'edit_private_posts' => "edit_private_{$plural}" ) ); };
	return array( 'post' => $mk( 'post', 'posts' ), 'page' => $mk( 'page', 'pages' ), 'attachment' => $mk( 'attachment', 'posts' ), 'note' => $mk( 'note', 'notes' ), 'custom_css' => $mk( 'custom_css', 'posts' ) );
}
// Like core: both walk into arrays.
function wp_slash( $v ) { if ( is_array( $v ) ) { foreach ( $v as $k => $x ) { $v[ $k ] = wp_slash( $x ); } return $v; } return is_string( $v ) ? addslashes( $v ) : $v; }
function wp_unslash( $v ) { if ( is_array( $v ) ) { foreach ( $v as $k => $x ) { $v[ $k ] = wp_unslash( $x ); } return $v; } return is_string( $v ) ? stripslashes( $v ) : $v; }
function wp_json_encode( $v, $f = 0, $d = 512 ) { return json_encode( $v, $f, $d ); }
// Like core: tags out, percent-encoded octets removed, whitespace collapsed —
// that last part is what sets it apart from wp_strip_all_tags().
function sanitize_text_field( $s ) { $s = strip_tags( (string) $s ); $s = preg_replace( '/%[a-f0-9]{2}/i', '', $s ); return trim( preg_replace( '/\s+/', ' ', $s ) ); }
// Like core: tags out and, with the second argument, line breaks too — but
// percent-encoded octets stay, unlike sanitize_text_field().
function wp_strip_all_tags( $s, $remove_breaks = false ) { $s = strip_tags( (string) $s ); if ( $remove_breaks ) { $s = preg_replace( '/[\r\n\t ]+/', ' ', $s ); } return trim( $s ); }
function clean_term_cache( $ids, $taxonomy = '' ) { $GLOBALS['t_cache'][] = array( $taxonomy, array_values( $ids ) ); }
// The terms as get_term() returns them. Term 10 has no slug — WordPress would
// give it one made from its new name — and term 11's parent no longer exists.
#[\AllowDynamicProperties]
class WP_Term { public function __construct( $fields ) { foreach ( $fields as $k => $v ) { $this->$k = $v; } } }
function reset_terms() {
	$GLOBALS['t_terms'] = array(
		1  => array( 'slug' => 'foo-bar', 'name' => 'foo bar', 'description' => 'a foo' ),
		3  => array( 'slug' => 'foo-3', 'name' => '<b>foo</b>' ),
		4  => array( 'slug' => 'oreilly-foo', 'name' => "O'Reilly foo" ),
		6  => array( 'slug' => 'foo-product', 'name' => 'foo product' ),
		7  => array( 'slug' => 'foo-brand', 'name' => 'foo brand' ),
		8  => array( 'slug' => 'foo-shared', 'name' => 'foo shared' ),
		9  => array( 'slug' => 'foo', 'name' => 'foo' ),
		10 => array( 'slug' => '0', 'name' => 'foo zero' ),
		11 => array( 'slug' => 'foo-child', 'name' => 'foo child', 'parent' => 55 ),
	);
	$GLOBALS['t_slug_filter'] = array();
}
reset_terms();
function get_term( $id, $taxonomy = '' ) { $id = (int) $id; if ( ! isset( $GLOBALS['t_terms'][ $id ] ) ) { return null; } $term = new WP_Term( array_merge( array( 'term_id' => $id, 'taxonomy' => (string) $taxonomy, 'name' => '', 'description' => '', 'slug' => '', 'parent' => 0, 'term_group' => 0, 'term_taxonomy_id' => 0, 'count' => 0 ), $GLOBALS['t_terms'][ $id ] ) ); return isset( $GLOBALS['t_filters']['get_term'] ) ? $GLOBALS['t_filters']['get_term']( $term, (string) $taxonomy ) : $term; }
// wp_update_term() as core answers: it records what it was handed, refuses an
// empty name (empty_term_name) and whatever a test declares refused.
function wp_update_term( $term_id, $taxonomy, $args = array() ) {
	$id = (int) $term_id;
	$GLOBALS['t_term_writes'][] = array( $id, (string) $taxonomy, $args );
	if ( isset( $args['name'] ) && '' === trim( (string) $args['name'] ) ) { return new WP_Error( 'empty_term_name', 'A name is required for this term.' ); }
	if ( isset( $GLOBALS['t_term_errors'][ $id ] ) ) { return new WP_Error( $GLOBALS['t_term_errors'][ $id ], 'core refused this update' ); }
	// Like _split_shared_term(): a term still shared between taxonomies is split
	// here. This taxonomy moves to a new id, the others keep the old one with
	// the old value, and the term is not shared any more.
	// Like core: it unslashes, writes and never looks at what the database did
	// with the write — the name column takes 200 characters, the rest is cut off.
	foreach ( $args as $field => $value ) {
		$value = wp_unslash( (string) $value );
		// Like the pre_term_name filters, then the column: trim first, cut after.
		// The column counts characters, not bytes.
		if ( 'name' === $field ) { $value = mb_substr( trim( $value ), 0, 200, 'UTF-8' ); }
		$GLOBALS['t_terms'][ $id ][ $field ] = $value;
	}
	// A pre_term_slug or wp_update_term_data filter that changes the slug along the way.
	if ( isset( $GLOBALS['t_slug_filter'][ $id ] ) ) { $GLOBALS['t_terms'][ $id ]['slug'] = $GLOBALS['t_slug_filter'][ $id ]; }
	return array( 'term_id' => $id, 'term_taxonomy_id' => 0 );
}
$GLOBALS['t_term_errors'] = array( 11 => 'missing_parent' );
function update_metadata_by_mid( $type, $mid, $value ) { $GLOBALS['t_meta'][] = array( $type, $mid, $value ); return true; }
// Like core: it unslashes what it is given, sanitises once, and picks the row
// by the previous value.
function update_term_meta( $term_id, $key, $value, $prev = '' ) { $GLOBALS['t_term_meta'][] = array( (int) $term_id, (string) $key, wp_unslash( $value ), wp_unslash( $prev ) ); return true; }
function update_option( $name, $value ) { $GLOBALS['t_opt'][] = array( $name, $value ); return true; }
function wp_update_post( $data, $wp_error = false ) {
	$GLOBALS['t_post'][]          = $data;
	$GLOBALS['t_post_wp_error'][] = $wp_error;
	// A post of the bulk status tool, as WordPress writes it: the status in WordPress's own form — a file is stored as
	// "inherit" for a status it cannot have, "publish" with a date at least a minute ahead becomes "future" — and
	// the password of a post saved as private dropped. A test can make the write fail (WP_Error with $wp_error,
	// or 0), let a plugin store another status, or remove the post during the write.
	$id = (int) $data['ID'];
	if ( isset( $GLOBALS['t_bulk_posts'][ $id ], $data['post_status'] ) ) {
		$knob = $GLOBALS['t_bulk_knobs'][ $id ] ?? array();
		if ( ! empty( $knob['error'] ) ) { return $wp_error ? new WP_Error( 'db_update_error', 'Could not update post in the database.' ) : 0; }
		if ( ! empty( $knob['zero'] ) ) { return 0; }
		if ( ! empty( $knob['deletes'] ) ) { unset( $GLOBALS['t_bulk_posts'][ $id ] ); return $id; }
		// With $wp_error, wp_insert_post() returns an error AFTER the row is written when the page template is gone.
		if ( ! empty( $knob['writes_then_fails'] ) ) { $GLOBALS['t_bulk_posts'][ $id ]['post_status'] = $data['post_status']; return $wp_error ? new WP_Error( 'invalid_page_template', 'Invalid page template.' ) : 0; }
		$p      = &$GLOBALS['t_bulk_posts'][ $id ];
		$status = $data['post_status'];
		if ( 'attachment' === $p['post_type'] ) {
			if ( ! in_array( $status, array( 'inherit', 'private', 'trash', 'auto-draft' ), true ) ) { $status = 'inherit'; }
		} elseif ( 'publish' === $status && strtotime( ( $p['post_date_gmt'] ?? '2001-01-01 09:00:00' ) . ' UTC' ) - time() >= 60 ) {
			$status = 'future';
		}
		if ( isset( $knob['store'] ) ) { $status = $knob['store']; }
		// A plugin that stores another status on the first save only.
		if ( isset( $knob['store_once'] ) && empty( $GLOBALS['t_bulk_knobs'][ $id ]['store_once_used'] ) ) { $status = $knob['store_once']; $GLOBALS['t_bulk_knobs'][ $id ]['store_once_used'] = true; }
		$p['post_status'] = $status;
		if ( 'private' === $status ) { $p['post_password'] = ''; }
		unset( $p );
	}
	return $id;
}
// As WordPress: false for a post already in the trash; a pre_trash_post filter can refuse (false) or claim
// success without trashing (true) — null from the filter lets WordPress go on.
function wp_trash_post( $id ) {
	$id = (int) $id;
	$GLOBALS['t_trash_calls'][] = $id;
	$GLOBALS['t_trash_notes_cleared'][] = count( $GLOBALS['t_del_meta'] ?? array() );
	if ( ! isset( $GLOBALS['t_bulk_posts'][ $id ] ) || 'trash' === $GLOBALS['t_bulk_posts'][ $id ]['post_status'] ) { return false; }
	$knob = $GLOBALS['t_bulk_knobs'][ $id ] ?? array();
	if ( ! empty( $knob['refuse'] ) ) { return false; }
	if ( ! empty( $knob['pretend'] ) ) { return true; }
	$GLOBALS['t_bulk_posts'][ $id ]['post_status'] = 'trash';
	return (object) array( 'ID' => $id );
}
// Hooks with priorities, for the status wp_untrash_post() gives: the tool pins its own on the way.
$GLOBALS['t_hooked'] = array();
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['t_hooked'][ $tag ][ $prio ][] = $cb; return true; }
function remove_filter( $tag, $cb, $prio = 10 ) { foreach ( $GLOBALS['t_hooked'][ $tag ][ $prio ] ?? array() as $i => $c ) { if ( $c === $cb ) { unset( $GLOBALS['t_hooked'][ $tag ][ $prio ][ $i ] ); return true; } } return false; }
// As WordPress: false for a post not in the trash, and where a pre_untrash_post filter refuses. "draft", a file "inherit",
// unless a wp_untrash_post_status filter gives another; the trash notes deleted before the save, which is wp_update_post()
// with the status alone; the comments' notes deleted after it, when they get their states back.
function wp_untrash_post( $id ) {
	$id = (int) $id;
	$GLOBALS['t_untrash_calls'][] = $id;
	if ( ! isset( $GLOBALS['t_bulk_posts'][ $id ] ) || 'trash' !== $GLOBALS['t_bulk_posts'][ $id ]['post_status'] ) { return false; }
	if ( ! empty( $GLOBALS['t_bulk_knobs'][ $id ]['untrash_refuse'] ) ) { return false; }
	$new = 'attachment' === $GLOBALS['t_bulk_posts'][ $id ]['post_type'] ? 'inherit' : 'draft';
	$all = $GLOBALS['t_hooked']['wp_untrash_post_status'] ?? array();
	ksort( $all );
	foreach ( $all as $cbs ) { foreach ( $cbs as $cb ) { $new = $cb( $new, $id, 'publish' ); } }
	delete_post_meta( $id, '_wp_trash_meta_status' );
	delete_post_meta( $id, '_wp_trash_meta_time' );
	if ( ! wp_update_post( array( 'ID' => $id, 'post_status' => $new ) ) ) { return false; }
	delete_post_meta( $id, '_wp_trash_meta_comments_status' );
	return (object) array( 'ID' => $id );
}
function get_post_type_object( $t ) { return (object) array( 'cap' => (object) array( 'publish_posts' => 'publish_posts' ) ); }
// Like wp_filter_kses: slashed in, slashed out; an unslashed apostrophe is noted.
// wp_update_term()'s slug helpers: a plain slugify, a lookup that knows the slugs a test declares taken, and a suffix.
function sanitize_title( $title, $fallback_title = '', $context = 'save' ) { return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $title ) ), '-' ); }
function get_term_by( $field, $value, $taxonomy = '' ) { return isset( $GLOBALS['t_slug_taken'][ $value ] ) ? (object) array( 'term_id' => $GLOBALS['t_slug_taken'][ $value ] ) : false; }
function wp_unique_term_slug( $slug, $term ) { return $slug . '-2'; }
// wp_update_term()'s other gates: a parent that must exist (55 does not), and the shared-term question (term 8 sits in two taxonomies).
function term_exists( $term, $taxonomy = '', $parent = null ) { return 55 === (int) $term ? null : $term; }
$GLOBALS['t_shared'] = array( 8 => true );
function wp_term_is_shared( $term_id ) { return ! empty( $GLOBALS['t_shared'][ (int) $term_id ] ); }
// Like core: every known field through sanitize_term_field(), then filter = context.
function sanitize_term( $term, $taxonomy, $context = 'display' ) { foreach ( array( 'term_id', 'name', 'description', 'slug', 'count', 'parent', 'term_group', 'term_taxonomy_id', 'object_id' ) as $field ) { if ( isset( $term[ $field ] ) ) { $term[ $field ] = sanitize_term_field( $field, $term[ $field ], $term['term_id'] ?? 0, $taxonomy, $context ); } } $term['filter'] = $context; return $term; }
// Like core: the integer fields are cast, the text fields go through the filters; logged for the two text fields.
function sanitize_term_field( $field, $value, $term_id, $taxonomy, $context ) { if ( in_array( $field, array( 'parent', 'term_id', 'count', 'term_group', 'term_taxonomy_id', 'object_id' ), true ) ) { return (int) $value; } if ( 'name' === $field || 'description' === $field ) { $GLOBALS['t_san'][] = array( $field, $term_id, $taxonomy, $context ); } if ( preg_match( "/(?<!\\\\)'/", (string) $value ) ) { $GLOBALS['t_bad_slash'] = true; } return addslashes( strip_tags( stripslashes( (string) $value ) ) ); }
class WP_Query { public $posts = array(); public $found_posts = 0; public $max_num_pages = 0; public function __construct( $a = array() ) {} }
class AB_MCP_Tool_Registry {}
// Hooks WordPress builds from option names, plus one registered setting: every
// such name is canonical — a row may carry it only byte for byte.
$GLOBALS['wp_filter'] = array( 'init' => 1, 'sanitize_option_my_setting' => 1, 'pre_update_option_other_setting' => 1, 'option_page_capability_reading' => 1, 'update_option' => 1, 'option_' => 1, 'option_BlogName' => 1 );
if ( ! function_exists( 'get_registered_settings' ) ) { function get_registered_settings() { return array( 'reg_setting' => array( 'type' => 'string' ) ); } }
// As WP_Widget sets them up: option_name is widget_<id_base> unless the widget
// says otherwise, alt_option_name the legacy option get_settings() reads.
$GLOBALS['wp_widget_factory'] = (object) array( 'widgets' => array(
	(object) array( 'id_base' => 'text', 'option_name' => 'widget_text' ),
	(object) array( 'id_base' => 'block', 'option_name' => 'widget_block' ),
	(object) array( 'id_base' => 'custom', 'option_name' => 'my_widget_store', 'alt_option_name' => 'widget_recent_entries' ),
) );
// The option guard, reduced to what the SQL builder consumes; actiue_plugins
// stands for a collation look-alike the guard folds onto active_plugins.
class AB_MCP_Option_Guard { // Free: the guard of the option tools, as a stand-in
	public static function write_guard_lists() { return array( 'needles' => array( 'secret', 'token' ), 'exact' => array( 'siteurl', 'mailserver_pass' ) ); }
	public static function option_write_refused( $name ) { $n = strtolower( trim( $name ) ); foreach ( array( 'secret', 'token' ) as $x ) { if ( false !== strpos( $n, $x ) ) { return new WP_Error( 'ab_mcp_blocked', 'refused' ); } } return in_array( $n, array( 'siteurl', 'mailserver_pass', 'actiue_plugins' ), true ) ? new WP_Error( 'ab_mcp_blocked', 'refused' ) : false; }
}
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
// WordPress core 7.0.2, wp-includes/functions.php — verbatim, so the engine
// meets the same notion of "looks serialized" as in production.
function is_serialized( $data, $strict = true ) {
	// If it isn't a string, it isn't serialized.
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
		// Either ; or } must exist.
		if ( false === $semicolon && false === $brace ) {
			return false;
		}
		// But neither must be in the first X characters.
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

// Never instantiated by the test itself: the serialized strings below are
// written by hand, so a __destruct at script end cannot fake a hit.
class Evil { public $a = 'foo'; public function __wakeup() { $GLOBALS['t_woke'] = true; } }
$GLOBALS['t_woke']     = false;
$GLOBALS['t_autoload'] = array();
spl_autoload_register( static function ( $class ) { $GLOBALS['t_autoload'][] = $class; } );

class AB_Test_WPDB {
	public $terms = 'wp_terms';
	public $term_taxonomy = 'wp_term_taxonomy';
	public $termmeta = 'wp_termmeta';
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $options = 'wp_options';
	public $updates = array();
	public $queries = array();
	public $prepares = array();
	public $served = array();
	public $posts_only_8 = false;
	// Page-builder rows only (the postmeta scan), for the unfiltered_html rule.
	public $builder_rows = false;
	public $huge = false;
	public $batches = false;
	public $batch_calls = 0;
	public $dupes = false;
	public $dupe_calls = 0;
	// The end of the table: small, unless the huge-table mode asks for more ids
	// than the walk will ever cover.
	// Which term a term-taxonomy row belongs to, for the description read-back.
	public $tt_terms = array( 11 => 1, 13 => 1, 12 => 2, 14 => 3, 15 => 4, 16 => 6, 17 => 7, 18 => 8, 19 => 8, 20 => 9, 21 => 10, 22 => 11, 23 => 12 );
	// A post of the bulk status tool as the table has it — exactly the columns the SELECT names, as strings. A read
	// can fail (null), and a post that is gone has no row.
	public function get_row( $q, $output = null, $y = 0 ) {
		$this->queries[] = $q;
		if ( preg_match( '/^SELECT (.+?) FROM wp_posts WHERE ID = %d$/', $q, $m ) ) {
			$last = end( $this->prepares ); $id = is_array( $last ) ? (int) $last[2][0] : 0;
			if ( ! isset( $GLOBALS['t_bulk_posts'][ $id ] ) || ! empty( $GLOBALS['t_bulk_knobs'][ $id ]['unreadable'] ) ) { return null; }
			// A read after the write that fails (the connection went away): null, like a row that is gone.
			if ( ! empty( $GLOBALS['t_bulk_knobs'][ $id ]['unreadable_after'] ) && false !== strpos( $m[1], 'post_type' ) ) { return null; }
			$p   = $GLOBALS['t_bulk_posts'][ $id ] + array( 'post_password' => '', 'post_type' => 'post', 'post_date_gmt' => '2001-01-01 09:00:00', 'post_parent' => 0 );
			$row = array();
			foreach ( array_map( 'trim', explode( ',', $m[1] ) ) as $c ) { $row[ $c ] = (string) $p[ $c ]; }
			return $row;
		}
		return null;
	}
	public function get_var( $q ) {
		$this->queries[] = $q;
		// Free 4.10: the value of an option row read again before it is written.
		if ( false !== strpos( $q, 'SELECT option_value FROM wp_options WHERE option_id = %d' ) ) { $last = end( $this->prepares ); $oid = is_array( $last ) ? (int) $last[2][0] : 0; if ( isset( $GLOBALS['t_opt_now'] ) ) { return is_array( $GLOBALS['t_opt_now'] ) ? ( $GLOBALS['t_opt_now'][ $oid ] ?? 'foo' ) : $GLOBALS['t_opt_now']; } return 'foo'; }
		// The read-back of a column just written: raw, as the scan read it.
		foreach ( array( 'SELECT name FROM wp_terms WHERE term_id = %d' => 'name', 'SELECT slug FROM wp_terms WHERE term_id = %d' => 'slug' ) as $needle => $field ) {
			if ( false !== strpos( $q, $needle ) ) {
				$last = end( $this->prepares ); $id = is_array( $last ) ? (int) $last[2][0] : 0;
				return isset( $GLOBALS['t_terms'][ $id ][ $field ] ) ? $GLOBALS['t_terms'][ $id ][ $field ] : null;
			}
		}
		// How many taxonomies a term sits in — the count _split_shared_term() makes.
		if ( false !== strpos( $q, 'SELECT COUNT(*) FROM wp_term_taxonomy WHERE term_id = %d' ) ) {
			$last = end( $this->prepares ); $id = is_array( $last ) ? (int) $last[2][0] : 0;
			return empty( $GLOBALS['t_shared'][ $id ] ) ? 1 : 2;
		}
		if ( false !== strpos( $q, 'SELECT description FROM wp_term_taxonomy WHERE term_taxonomy_id = %d' ) ) {
			$last = end( $this->prepares ); $tt = is_array( $last ) ? (int) $last[2][0] : 0;
			$id  = isset( $this->tt_terms[ $tt ] ) ? $this->tt_terms[ $tt ] : 0;
			return isset( $GLOBALS['t_terms'][ $id ]['description'] ) ? $GLOBALS['t_terms'][ $id ]['description'] : null;
		}
		if ( false !== strpos( $q, 'SELECT COUNT(*) FROM wp_options WHERE option_id = %d AND (option_name IN (' ) ) {
			// The database's answer, emulated: IN compares under the collation
			// (case folded, combining accent dropped), CAST AS BINARY compares bytes.
			$last    = end( $this->prepares ); $args = is_array( $last ) ? $last[2] : array();
			$n_never = substr_count( substr( $q, 0, (int) strpos( $q, ' OR (' ) ), '%s' );
			$never   = array_slice( $args, 1, $n_never );
			// The canonical names come as pairs (name, name): one per OR term.
			$rest    = array_slice( $args, 1 + $n_never ); $canon = array();
			for ( $i = 0; $i < count( $rest ); $i += 2 ) { if ( ! isset( $rest[ $i + 1 ] ) || $rest[ $i ] !== $rest[ $i + 1 ] ) { return -1; } $canon[] = $rest[ $i ]; }
			$this->guard_lists[] = $never; $this->canon_lists[] = $canon;
			$stored  = array( 3 => 'blogdescription', 9 => "wi\xCC\x81dget_text", 12 => 'BlogName', 13 => 'WPLANG' );
			$name    = isset( $stored[ (int) $args[0] ] ) ? $stored[ (int) $args[0] ] : '';
			$fold    = static function ( $s ) { return strtolower( str_replace( "\xCC\x81", '', $s ) ); };
			$equal   = static function ( $list ) use ( $name, $fold ) { foreach ( $list as $n ) { if ( $fold( $n ) === $fold( $name ) ) { return true; } } return false; };
			// Pair by pair: equal under the collation to a canonical name that is not the stored bytes.
			$twin    = static function ( $list ) use ( $name, $fold ) { foreach ( $list as $n ) { if ( $fold( $n ) === $fold( $name ) && $n !== $name ) { return true; } } return false; };
			return ( $equal( $never ) || $twin( $canon ) ) ? 1 : 0;
		}
		return $this->huge ? 10000000 : 100;
	}
	public $guard_lists = array();
	public $canon_lists = array();
	public $huge_windows = array();
	// The database's own equality for option names: a phantom row with a
	// combining accent resolves to the real widget row AND itself.
	public function get_col( $q ) { $this->queries[] = $q; $last = end( $this->prepares ); $name = is_array( $last ) ? (string) $last[2][0] : ''; $map = array( 'blogdescription' => array( 3 ), "wi\xCC\x81dget_text" => array( 9 ), 'BlogName' => array( 12 ), 'WPLANG' => array( 13 ) ); return isset( $map[ $name ] ) ? $map[ $name ] : array(); }
	public $last_error = '';
	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	// Records every prepared statement with its argument count and arguments.
	public function prepare( $q, ...$a ) { $args = ( 1 === count( $a ) && is_array( $a[0] ) ) ? $a[0] : $a; $this->prepares[] = array( $q, count( $args ), $args ); return $q; }
	// Canned rows, once per kind of query and run (the scan walks by cursor
	// and would otherwise see the same rows again).
	public function get_results( $q, $mode = null ) {
		$this->queries[] = $q;
		foreach ( array( 'posts' => 'post_excerpt FROM wp_posts', 'postmeta' => 'FROM wp_postmeta pm', 'options' => 'option_value FROM wp_options', 'names' => 'FROM wp_terms t ', 'descriptions' => 'FROM wp_term_taxonomy tt', 'termmeta' => 'FROM wp_termmeta tm' ) as $kind => $needle ) {
			if ( false === strpos( $q, $needle ) ) { continue; }
			if ( 'names' === $kind && $this->dupes ) {
				// 200 rows of term 8 in a refused taxonomy fill batch 1; the allowed
				// row of the same term comes with batch 2. A cursor by id alone
				// would never reach it.
				$n = ++$this->dupe_calls; $rows = array();
				if ( 1 === $n ) { for ( $i = 1; $i <= 200; $i++ ) { $rows[] = array( 'term_id' => 8, 'name' => 'foo shared', 'taxonomy' => 'product_cat', 'term_taxonomy_id' => $i ); } }
				if ( 2 === $n ) { $rows[] = array( 'term_id' => 8, 'name' => 'foo shared', 'taxonomy' => 'category', 'term_taxonomy_id' => 201 ); }
				return $rows;
			}
			if ( 'posts' === $kind && $this->batches ) {
				// A window with more rows than one batch: 200, then 5, then nothing.
				$n = ++$this->batch_calls; $rows = array();
				// Batch 1: ids 1..200; batch 2: 201..205; then nothing.
				if ( 1 === $n ) { for ( $i = 1; $i <= 200; $i++ ) { $rows[] = array( 'ID' => $i, 'post_type' => 'post', 'post_parent' => 0, 'post_content' => 'foo', 'post_title' => '', 'post_excerpt' => '' ); } }
				if ( 2 === $n ) { for ( $i = 201; $i <= 205; $i++ ) { $rows[] = array( 'ID' => $i, 'post_type' => 'post', 'post_parent' => 0, 'post_content' => 'foo', 'post_title' => '', 'post_excerpt' => '' ); } }
				return $rows;
			}
			if ( 'posts' === $kind && $this->huge ) {
				// One matching row near the end of a table of ten million ids,
				// served only by the query whose window holds its id.
				$last  = end( $this->prepares ); $a = is_array( $last ) ? $last[2] : array();
				// Bounds follow the seven internal types kept out of the scan.
				$lower = isset( $a[7] ) ? (int) $a[7] : 0; $upper = isset( $a[8] ) ? (int) $a[8] : 0;
				$this->huge_windows[] = array( $lower, $upper );
				return ( $lower < 9999999 && 9999999 <= $upper ) ? array( array( 'ID' => 9999999, 'post_type' => 'post', 'post_parent' => 0, 'post_content' => 'foo', 'post_title' => '', 'post_excerpt' => '' ) ) : array();
			}
			if ( ! empty( $this->served[ $kind ] ) && empty( $GLOBALS['t_serve_again'] ) ) { return array(); } // Free 4.10: t_serve_again for the dry run of expected_count
			$this->served[ $kind ] = true;
			return $this->rows( $kind );
		}
		return array();
	}
	public function rows( $kind ) {
		switch ( $kind ) {
			case 'posts':
				if ( $this->posts_only_8 ) { return array( array( 'ID' => 8, 'post_type' => 'post', 'post_parent' => 0, 'post_content' => 'foo', 'post_title' => '', 'post_excerpt' => '' ) ); }
				// Refused rows before and after the one allowed row.
				return array(
					array( 'ID' => 9, 'post_type' => 'page', 'post_parent' => 0, 'post_content' => 'foo', 'post_title' => '', 'post_excerpt' => '' ),
					array( 'ID' => 10, 'post_type' => 'post', 'post_parent' => 0, 'post_content' => 'foo', 'post_title' => '', 'post_excerpt' => '' ),
					array( 'ID' => 8, 'post_type' => 'post', 'post_parent' => 0, 'post_content' => 'foo', 'post_title' => '', 'post_excerpt' => '' ),
					array( 'ID' => 12, 'post_type' => 'post', 'post_parent' => 0, 'post_content' => 'foo', 'post_title' => '', 'post_excerpt' => '' ),
					array( 'ID' => 13, 'post_type' => 'revision', 'post_parent' => 14, 'post_content' => 'foo', 'post_title' => '', 'post_excerpt' => '' ),
					// An internal type, as if the SQL exclusion had not held: refused per row.
					array( 'ID' => 15, 'post_type' => 'custom_css', 'post_parent' => 0, 'post_content' => 'foo', 'post_title' => '', 'post_excerpt' => '' ),
				);
			case 'postmeta':
				if ( $this->builder_rows ) {
					// Builder keys without "_", which the reader's rules let through,
					// one in another letter case, one a prefix covers — and a plain
					// key next to them.
					$rows = array();
					foreach ( array( 'panels_data', 'dslc_code', 'pagelayer-data', 'pagelayer_header_code', 'brizy-compiled-sections', 'vcv-pageContent', 'vcv-settingsLocalJsFooter', 'tbp_custom_js', 'mfn-page-items', 'tve_updated_post', 'ct_builder_json', 'Panels_Data', 'color' ) as $i => $key ) {
						$rows[] = array( 'meta_id' => 60 + $i, 'post_id' => 8, 'meta_key' => $key, 'meta_value' => 'foo', 'post_type' => 'post', 'post_parent' => 0 );
					}
					return $rows;
				}
				return array(
					array( 'meta_id' => 31, 'post_id' => 8, 'meta_key' => 'color', 'meta_value' => 'foo', 'post_type' => 'post', 'post_parent' => 0 ),
					array( 'meta_id' => 32, 'post_id' => 8, 'meta_key' => 'stripe_secret_key', 'meta_value' => 'foo', 'post_type' => 'post', 'post_parent' => 0 ),
					array( 'meta_id' => 33, 'post_id' => 9, 'meta_key' => 'color', 'meta_value' => 'foo', 'post_type' => 'page', 'post_parent' => 0 ),
					array( 'meta_id' => 34, 'post_id' => 8, 'meta_key' => 'thing', 'meta_value' => 'a:2:{i:0;s:3:"foo";i:1;O:4:"Evil":1:{s:1:"a";s:1:"x";}}', 'post_type' => 'post', 'post_parent' => 0 ),
					array( 'meta_id' => 35, 'post_id' => 8, 'meta_key' => '_vault', 'meta_value' => 'foo', 'post_type' => 'post', 'post_parent' => 0 ),
					array( 'meta_id' => 36, 'post_id' => 8, 'meta_key' => 'sealed_data', 'meta_value' => 'foo', 'post_type' => 'post', 'post_parent' => 0 ),
					array( 'meta_id' => 37, 'post_id' => 13, 'meta_key' => 'color', 'meta_value' => 'foo', 'post_type' => 'revision', 'post_parent' => 14 ),
					// Allow-listed builder key: protected, unregistered, readable with edit_post.
					array( 'meta_id' => 38, 'post_id' => 8, 'meta_key' => '_elementor_data', 'meta_value' => '[{"t":"foo"}]', 'post_type' => 'post', 'post_parent' => 0 ),
					// Allow-listed for reading, but WordPress writes it through update_attached_file(): never rewritten.
					array( 'meta_id' => 39, 'post_id' => 8, 'meta_key' => '_wp_attached_file', 'meta_value' => '2026/09/foo.jpg', 'post_type' => 'post', 'post_parent' => 0 ),
					// Too deep to walk: left alone, but not because an object was found.
					array( 'meta_id' => 40, 'post_id' => 8, 'meta_key' => 'deep', 'meta_value' => $GLOBALS['t_deep'], 'post_type' => 'post', 'post_parent' => 0 ),
					// Protected, but plain text: the media screen lets any administrator edit it.
					array( 'meta_id' => 41, 'post_id' => 8, 'meta_key' => '_wp_attachment_image_alt', 'meta_value' => 'foo <b>Datei%20Name</b>', 'post_type' => 'post', 'post_parent' => 0 ),
				);
			case 'options':
				return array(
					array( 'option_id' => 1, 'option_name' => 'widget_text', 'option_value' => 'foo' ),
					array( 'option_id' => 2, 'option_name' => 'sidebars_widgets', 'option_value' => 'foo' ),
					array( 'option_id' => 3, 'option_name' => 'blogdescription', 'option_value' => 'foo' ),
					array( 'option_id' => 4, 'option_name' => 'stripe_secret', 'option_value' => 'foo' ),
					array( 'option_id' => 5, 'option_name' => 'siteurl', 'option_value' => 'foo' ),
					array( 'option_id' => 6, 'option_name' => 'actiue_plugins', 'option_value' => 'foo' ),
					// A stored row whose name update_option() would trim onto the real widget row.
					array( 'option_id' => 7, 'option_name' => ' widget_text', 'option_value' => 'foo' ),
					array( 'option_id' => 8, 'option_name' => 'Widget_block', 'option_value' => 'foo' ),
					// A combining acute accent on the i: equal to widget_text for the database, not
	// for LIKE — and the only such row, so it resolves to itself.
					array( 'option_id' => 9, 'option_name' => "wi\xCC\x81dget_text", 'option_value' => 'foo' ),
					// A name update_option() redirects to disallowed_keys.
					array( 'option_id' => 10, 'option_name' => 'blacklist_keys', 'option_value' => 'foo' ),
					// The option a registered widget stores under, without the widget_ prefix and in another case.
					array( 'option_id' => 11, 'option_name' => 'My_Widget_Store', 'option_value' => 'foo' ),
					// A look-alike of a name sanitize_option() treats by name: get_option('blogname') reads it,
					// update_option('BlogName') would skip the sanitiser.
					array( 'option_id' => 12, 'option_name' => 'BlogName', 'option_value' => 'foo' ),
					// A canonical name with upper-case letters, stored byte for byte: written through its sanitiser.
					array( 'option_id' => 13, 'option_name' => 'WPLANG', 'option_value' => 'foo' ),
				);
			case 'names':
				return array(
					array( 'term_id' => 1, 'name' => 'foo bar', 'taxonomy' => 'category', 'term_taxonomy_id' => 11 ),
					array( 'term_id' => 1, 'name' => 'foo bar', 'taxonomy' => 'post_tag', 'term_taxonomy_id' => 13 ),
					array( 'term_id' => 2, 'name' => 'foo-theme', 'taxonomy' => 'wp_theme', 'term_taxonomy_id' => 12 ),
					array( 'term_id' => 3, 'name' => '<b>foo</b>', 'taxonomy' => 'category', 'term_taxonomy_id' => 14 ),
					array( 'term_id' => 4, 'name' => "O'Reilly foo", 'taxonomy' => 'category', 'term_taxonomy_id' => 15 ),
					array( 'term_id' => 6, 'name' => 'foo product', 'taxonomy' => 'product_cat', 'term_taxonomy_id' => 16 ),
					array( 'term_id' => 7, 'name' => 'foo brand', 'taxonomy' => 'brand', 'term_taxonomy_id' => 17 ),
					// Shared id: the refused (non-public) taxonomy comes first, the allowed one second.
					array( 'term_id' => 8, 'name' => 'foo shared', 'taxonomy' => 'product_cat', 'term_taxonomy_id' => 18 ),
					array( 'term_id' => 8, 'name' => 'foo shared', 'taxonomy' => 'category', 'term_taxonomy_id' => 19 ),
					// A name that is nothing but the needle: replaced by nothing, it must not be written.
					array( 'term_id' => 9, 'name' => 'foo', 'taxonomy' => 'category', 'term_taxonomy_id' => 20 ),
					// A stored slug of "0": empty to wp_update_term(), which makes one from the new name.
					array( 'term_id' => 10, 'name' => 'foo zero', 'taxonomy' => 'category', 'term_taxonomy_id' => 21 ),
					// A parent that no longer exists: wp_update_term() refuses the update (missing_parent).
					array( 'term_id' => 11, 'name' => 'foo child', 'taxonomy' => 'category', 'term_taxonomy_id' => 22 ),
					// A row whose term get_term() cannot read any more.
					array( 'term_id' => 12, 'name' => 'foo gone', 'taxonomy' => 'category', 'term_taxonomy_id' => 23 ),
				);
			case 'descriptions':
				return array(
					array( 'term_taxonomy_id' => 11, 'term_id' => 1, 'taxonomy' => 'category', 'description' => 'a foo' ),
					array( 'term_taxonomy_id' => 12, 'term_id' => 2, 'taxonomy' => 'wp_template_part_area', 'description' => 'foo area' ),
					array( 'term_taxonomy_id' => 16, 'term_id' => 6, 'taxonomy' => 'product_cat', 'description' => 'foo desc' ),
				);
			case 'termmeta':
				return array(
					array( 'meta_id' => 21, 'term_id' => 1, 'meta_key' => 'color', 'meta_value' => 'foo', 'taxonomy' => 'category', 'term_taxonomy_id' => 11 ),
					array( 'meta_id' => 22, 'term_id' => 1, 'meta_key' => 'api_key', 'meta_value' => 'foo', 'taxonomy' => 'category', 'term_taxonomy_id' => 11 ),
					array( 'meta_id' => 23, 'term_id' => 2, 'meta_key' => 'color', 'meta_value' => 'foo', 'taxonomy' => 'wp_theme', 'term_taxonomy_id' => 12 ),
					array( 'meta_id' => 24, 'term_id' => 6, 'meta_key' => 'color', 'meta_value' => 'foo', 'taxonomy' => 'product_cat', 'term_taxonomy_id' => 16 ),
					array( 'meta_id' => 25, 'term_id' => 1, 'meta_key' => '_hidden', 'meta_value' => 'foo', 'taxonomy' => 'category', 'term_taxonomy_id' => 11 ),
					array( 'meta_id' => 26, 'term_id' => 1, 'meta_key' => 'sealed', 'meta_value' => 'foo', 'taxonomy' => 'category', 'term_taxonomy_id' => 11 ),
					array( 'meta_id' => 27, 'term_id' => 7, 'meta_key' => 'color', 'meta_value' => 'foo', 'taxonomy' => 'brand', 'term_taxonomy_id' => 17 ),
					// Meta of the shared term 8, once per taxonomy as the JOIN hands it out: counted under category, never written.
					array( 'meta_id' => 28, 'term_id' => 8, 'meta_key' => 'color', 'meta_value' => 'foo', 'taxonomy' => 'product_cat', 'term_taxonomy_id' => 18 ),
					array( 'meta_id' => 28, 'term_id' => 8, 'meta_key' => 'color', 'meta_value' => 'foo', 'taxonomy' => 'category', 'term_taxonomy_id' => 19 ),
				);
		}
		return array();
	}
	public function update( $table, $data, $where ) { $this->updates[] = array( $table, $data, $where ); return 1; }
}
$GLOBALS['wpdb'] = new AB_Test_WPDB();
if ( ! class_exists( 'AB_MCP_Settings', false ) ) {
	// Free: the option guard (AB_MCP_Option_Guard) names the plugin's own options by these constants.
	class AB_MCP_Settings {
		const OPT_OPTIONS   = 'ab_mcp_options';
		const OPT_TOKENS    = 'ab_mcp_tokens';
		const OPT_TOOLSTATE = 'ab_mcp_tool_state';
	}
}
// Free: this file checks wp_search_replace without the page-builder parts, as Pro did
// (search-replace-builder-test.php checks it with them).
function ab_mcp_load_builder_writers() { return false; }
$core = dirname( __DIR__, 2 ) . '/includes/tools/';
require_once $core . 'class-tools-base.php';
require_once $core . 'class-tools-content.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-post-status.php';
require_once $core . 'class-tools-search-bulk.php';
require_once AB_MCP_PRO_DIR . 'includes/tools/class-tools-search-replace.php';
// Deeper than the walker goes: nobody can say whether an object hides in there.
// Built against the engine's own limit, so that raising it keeps the case.
$deep = array( 'foo' ); for ( $i = 0; $i < AB_MCP_Replace_Engine::MAX_DEPTH; $i++ ) { $deep = array( $deep ); } $GLOBALS['t_deep'] = serialize( $deep );

$fail = 0;
function ok( $cond, $msg ) { global $fail; if ( $cond ) { echo "  ok   $msg\n"; } else { $fail++; echo "  FAIL $msg\n"; } }
function reset_log() { $GLOBALS['t_cache'] = array(); $GLOBALS['t_meta'] = array(); $GLOBALS['t_opt'] = array(); $GLOBALS['t_post'] = array(); $GLOBALS['t_san'] = array(); $GLOBALS['t_bad_slash'] = false; $w = $GLOBALS['wpdb']; $w->updates = array(); $w->queries = array(); $w->prepares = array(); $w->served = array(); $w->posts_only_8 = false; $w->builder_rows = false; $w->huge = false; $w->batches = false; $w->batch_calls = 0; $w->dupes = false; $w->dupe_calls = 0; $w->guard_lists = array(); $w->canon_lists = array(); $GLOBALS['t_term_writes'] = array(); $GLOBALS['t_term_meta'] = array(); $GLOBALS['t_no_unfiltered'] = false; $GLOBALS['t_shared'] = array( 8 => true ); $GLOBALS['t_filters'] = array(); reset_terms(); $w->huge_windows = array(); $GLOBALS['t_del_meta'] = array(); $GLOBALS['t_del_opt'] = array(); $GLOBALS['t_actions'] = array(); }
// The normalised expression for one column, exactly as sql_name_normalized()
// builds it: whitespace and NUL out, then every letter folded. A guard built
// on the wrong column would pass every "how many REPLACE" count.
function norm_of( $col ) {
	$expr = $col;
	foreach ( array( 32, 9, 10, 13, 0, 11 ) as $code ) { $expr = "REPLACE({$expr}, CHAR({$code}), '')"; }
	foreach ( range( 'A', 'Z' ) as $u ) { $expr = "REPLACE({$expr}, '{$u}', '" . strtolower( $u ) . "')"; }
	return $expr;
}
function query_with( $needle ) { foreach ( $GLOBALS['wpdb']->queries as $q ) { if ( false !== strpos( $q, $needle ) ) { return $q; } } return ''; }
function args_of( $needle ) { foreach ( $GLOBALS['wpdb']->prepares as $pr ) { if ( false !== strpos( $pr[0], $needle ) ) { return $pr[2]; } } return null; }
// Open at both ends: without the trailing %, a hit in the middle of a value
// would never be found.
function all_like_foo( $list ) { return count( $list ) >= 1 && count( $list ) === count( array_filter( $list, static function ( $a ) { return is_string( $a ) && '%foo%' === $a; } ) ); }
function prepares_ok( $label ) {
	$bad = array();
	foreach ( $GLOBALS['wpdb']->prepares as $pr ) {
		list( $q, $n, $args ) = $pr;
		$holes = substr_count( $q, '%s' ) + substr_count( $q, '%d' );
		if ( $holes !== $n ) { $bad[] = "$holes Platzhalter, $n Argumente: " . substr( $q, 0, 90 ); }
		foreach ( $args as $arg ) { if ( ! is_scalar( $arg ) ) { $bad[] = 'non-scalar argument in ' . substr( $q, 0, 60 ); } }
	}
	ok( count( $GLOBALS['wpdb']->prepares ) > 0 && array() === $bad, "$label: placeholders and arguments line up in " . count( $GLOBALS['wpdb']->prepares ) . ' prepared statements' . ( $bad ? ' -- ' . implode( ' | ', $bad ) : '' ) );
}
function reflect( $class, $method ) { $m = new ReflectionMethod( $class, $method ); if ( PHP_VERSION_ID < 80100 ) { $m->setAccessible( true ); } return $m; }
$SR = array( 'AB_MCP_Tools_Search_Replace', 'search_replace' );

echo "engine: no class is restored, no object is rewritten\n";
$engine  = new AB_MCP_Replace_Engine( 'foo', 'bar', false, false );
$count   = 0;
$payload = 'O:4:"Evil":1:{s:1:"a";s:3:"foo";}';
$out     = $engine->apply_value( $payload, $count );
ok( false === $GLOBALS['t_woke'], 'an O: payload inside a string value does not wake its class' );
ok( $payload === $out && 0 === $count && 1 === $engine->skipped_object_values, 'the string is left untouched and counted as an object value' );
$count = 0;
ok( 'a:1:{i:0;s:3:"bar";}' === $engine->apply_value( 'a:1:{i:0;s:3:"foo";}', $count ) && 1 === $count, 'a serialized array inside a string is still replaced' );
$count = 0;
$c_payload = 'a:2:{i:0;s:3:"foo";i:1;C:4:"Demo":3:{xyz}}';
ok( $c_payload === $engine->apply_value( $c_payload, $count ) && 0 === $count && 2 === $engine->skipped_object_values, 'a Serializable (C:) object next to a hit keeps its payload: the whole string is left alone' );
$db_value = reflect( 'AB_MCP_Tools_Search_Replace', 'db_value' );
ok( null === $db_value->invoke( null, 'O:4:"Evil":0:{}', $engine ) && 3 === $engine->skipped_object_values && false === $GLOBALS['t_woke'], 'a stored O: row is skipped as an object value, its class never woken' );
$count = 0;
$bare  = 'C:4:"Demo":3:{foo}';
ok( $bare === $engine->apply_value( $bare, $count ) && 0 === $count && 4 === $engine->skipped_object_values, 'a bare Serializable record, which is_serialized() does not know, is left alone rather than corrupted' );
$count = 0;
$cycle = 'a:2:{i:0;s:3:"foo";i:1;R:1;}';
ok( $cycle === $engine->apply_value( $cycle, $count ) && 0 === $count && 5 === $engine->skipped_object_values, 'a cyclic structure (R: back-reference) is left alone instead of recursing forever' );
ok( null === $db_value->invoke( null, $cycle, $engine ) && 6 === $engine->skipped_object_values, 'the same for a stored row' );
$count = 0;
$enum  = 'a:1:{i:0;E:15:"AuditGhost:Case";}';
ok( $enum === $engine->apply_value( $enum, $count ) && 0 === $count && 7 === $engine->skipped_object_values && array() === $GLOBALS['t_autoload'], 'an enum record inside a nested string never reaches the autoloader' );
ok( null === $db_value->invoke( null, 'E:15:"AuditGhost:Case";', $engine ) && 8 === $engine->skipped_object_values && array() === $GLOBALS['t_autoload'], 'nor does a stored enum row' );
$count  = 0;
$hidden = 'a:2:{i:0;O:4:"Evil":0:{}i:0;s:3:"foo";}';
ok( $hidden === $engine->apply_value( $hidden, $count ) && 0 === $count && 9 === $engine->skipped_object_values, 'an object hidden behind a duplicate key is found in the text and the string left alone' );
ok( null === $db_value->invoke( null, $hidden, $engine ) && 10 === $engine->skipped_object_values && false === $GLOBALS['t_woke'], 'the same for a stored row' );
ok( is_array( $db_value->invoke( null, 'a:1:{s:1:"k";s:1:"v";}', $engine ) ), 'a plain serialized array row is still decoded' );
$harmless = serialize( array( 'example' => 'Text;O:4:"Demo":0:{}', 'url' => 'https://foo.example' ) );
$count    = 0;
ok( ! $engine->carries_record( $harmless ) && serialize( array( 'example' => 'Text;O:4:"Demo":0:{}', 'url' => 'https://bar.example' ) ) === $engine->apply_value( $harmless, $count ) && 1 === $count, 'a record-looking token inside a string payload does not count: the row is still replaced' );
ok( $engine->carries_record( 'a:1:{i:0;C:4:"Demo":0:{}}' ) && $engine->carries_record( 'O:4:"Evil":0:{}' ) && $engine->carries_record( 'a:1:{s:1:"k";E:8:"Suit:Hearts";}' ) && $engine->carries_record( 'a:2:{i:0;O:4:"Evil":0:{}i:0;s:3:"foo";}' ), 'records at structural positions count, a hidden duplicate-key object included' );
ok( $engine->carries_record( 'S:3:"a\\62c";' ) && $engine->carries_record( 'a:1:{i:0;s:5:"abc";}' ) && $engine->carries_record( 'x:1;' ), 'an escaped string, a wrong length and an unknown token count as records: refusing is the safe answer' );
ok( ! $engine->carries_record( 'a:3:{i:0;N;i:1;d:1.5;s:2:"ok";b:1;}' ) && ! $engine->carries_record( 'a:1:{i:0;a:1:{i:0;i:7;}}' ), 'plain scalars, null and nesting pass' );
ok( $engine->carries_record( 'a:1:{i:0;a:1:{i:0;R:2;}}' ) && $engine->carries_record( 'a:2:{i:0;a:0:{}i:1;r:2;}' ), 'references are refused: a small text with many of them is an exponential walk' );
ok( $engine->carries_record( 's:9223372036854775807:"foo";' ) && $engine->carries_record( 's:99999999999999999999:"foo";' ) && $engine->carries_record( 'a:1:{i:0;s:4:"foo";}' ), 'a length beyond the text — up to and past the integer range — is refused without an error' );
ok( ! $engine->carries_record( 'a:2:{s:1:"k";s:0:"";i:-1;i:-5;}' ) && ! $engine->carries_record( 'a:1:{i:0;d:-1.5E-3;}' ) && ! $engine->carries_record( 'b:0;' ), 'empty strings, negative numbers and exponent floats pass' );
$count = 0;
ok( 'S:3:"foo";' === $engine->apply_value( 'S:3:"foo";', $count ) && 0 === $count && null === $db_value->invoke( null, 'S:3:"foo";', $engine ), 'an escaped string record on its own is left alone, in a nested string and as a stored row' );

echo "engine: Elementor JSON is walked as deep as real pages nest, and what lies deeper is counted\n";
// _elementor_data as Elementor 4.3.3 saved it in the measurement of 30.09.2026
// (docs/recherche/2026-09-30-page-builder-messung, ergebnis-1 and ergebnis-3),
// verbatim: one container, three widgets; the deepest string sits at depth 7.
$el_classic = '[{"id":"c0ffee1","elType":"container","settings":[],"elements":[{"id":"a1b2c3d","elType":"widget","settings":{"title":"Willkommen bei Alpine Bikes","header_size":"h1"},"elements":[],"widgetType":"heading"},{"id":"b2c3d4e","elType":"widget","settings":{"editor":"<p>\\u00d6ffnungszeiten: Mo\\u2013Fr 9\\u201318 Uhr<\\/p>"},"elements":[],"widgetType":"text-editor"},{"id":"c3d4e5f","elType":"widget","settings":{"text":"Jetzt reservieren","link":{"url":"https:\\/\\/example.com\\/reservieren","is_external":"","nofollow":""}},"elements":[],"widgetType":"button"}],"isInner":false}]';
$el_atomic  = '[{"id":"f00d001","elType":"e-flexbox","elements":[{"id":"f00d002","elType":"widget","settings":{"tag":{"$$type":"string","value":"h1"},"title":{"$$type":"escaped-html","value":"Willkommen bei Alpine Bikes"}},"elements":[],"widgetType":"e-heading","version":"0.0"},{"id":"f00d003","elType":"widget","settings":{"paragraph":{"$$type":"escaped-html","value":"\\u00d6ffnungszeiten: Mo\\u2013Fr 9\\u201318 Uhr"}},"elements":[],"widgetType":"e-paragraph","version":"0.0"},{"id":"f00d004","elType":"widget","settings":{"text":{"$$type":"escaped-html","value":"Jetzt reservieren"}},"elements":[],"widgetType":"e-button","version":"0.0"}],"isInner":false,"version":"0.0"}]';
$el = new AB_MCP_Replace_Engine( 'Alpine Bikes', 'Alpen Velo', false, false );
$count = 0;
ok( str_replace( 'Alpine Bikes', 'Alpen Velo', $el_classic ) === $el->apply_value( $el_classic, $count ) && 1 === $count && 0 === $el->skipped_unchecked_values, 'the measured classic page is replaced, byte for byte as Elementor encodes it' );
$count = 0;
ok( str_replace( 'Alpine Bikes', 'Alpen Velo', $el_atomic ) === $el->apply_value( $el_atomic, $count ) && 1 === $count && 0 === $el->skipped_unchecked_values, 'and so is the measured atomic (Elementor 4) page' );
// A real page nests: Elementor adds two levels per layout level (an element
// and its elements array). The measured page wrapped in three more containers,
// with an icon list whose item carries a link (a repeater, as Elementor stores
// it), puts that link at depth 7 + 2 * 4 = 15 — beyond the old limit of 12.
function el_wrap( array $elements, $levels ) {
	for ( $i = 0; $i < $levels; $i++ ) {
		$elements = array( (object) array( 'id' => sprintf( 'a0000%02d', $i ), 'elType' => 'container', 'settings' => array(), 'elements' => $elements, 'isInner' => true ) );
	}
	return $elements;
}
$page = json_decode( $el_classic );
$page[0]->elements[] = (object) array( 'id' => 'd4e5f6a', 'elType' => 'widget', 'settings' => (object) array( 'icon_list' => array( (object) array( 'text' => 'Velos bei Alpine Bikes', 'link' => (object) array( 'url' => 'https://example.com/velos', 'is_external' => '', 'nofollow' => '' ), '_id' => 'e5f6a7b' ) ) ), 'elements' => array(), 'widgetType' => 'icon-list' );
$nested = json_encode( el_wrap( $page, 3 ) );
$el     = new AB_MCP_Replace_Engine( 'example.com', 'example.ch', false, false );
$count  = 0;
ok( str_replace( 'example.com', 'example.ch', $nested ) === $el->apply_value( $nested, $count ) && 2 === $count && 0 === $el->skipped_unchecked_values, 'a page nested four layout levels deep: the button link and the link in the repeater item are both replaced, nothing is left unchecked' );
// Elementor 4 keeps an element's class styles in the element itself; an image
// URL there wraps every value as {$$type, value}. Built after the prop types
// of Elementor 4.3.3 (background, background-overlay, background-image-overlay,
// image, image-src, url), not measured: the URL sits at depth 19 + 2 * 1 = 21.
$page = json_decode( $el_atomic );
$url  = (object) array( '$$type' => 'url', 'value' => 'https://example.com/berg.jpg' );
$img  = (object) array( '$$type' => 'image', 'value' => (object) array( 'src' => (object) array( '$$type' => 'image-src', 'value' => (object) array( 'id' => null, 'url' => $url ) ) ) );
$bg   = (object) array( '$$type' => 'background', 'value' => (object) array( 'background-overlay' => (object) array( '$$type' => 'background-overlay', 'value' => array( (object) array( '$$type' => 'background-image-overlay', 'value' => (object) array( 'image' => $img ) ) ) ) ) );
$page[0]->elements[0]->styles = (object) array( 'e-f00d002-a1' => (object) array( 'id' => 'e-f00d002-a1', 'label' => 'local', 'type' => 'class', 'variants' => array( (object) array( 'meta' => (object) array( 'breakpoint' => 'desktop', 'state' => null ), 'props' => (object) array( 'background' => $bg ) ) ) ) );
$styled = json_encode( $page );
$count  = 0;
ok( str_replace( 'example.com', 'example.ch', $styled ) === $el->apply_value( $styled, $count ) && 1 === $count && 0 === $el->skipped_unchecked_values, 'an image URL in the class styles of an atomic widget is replaced' );
// Beyond the limit: left as it is, but counted — in the JSON path too, where it
// used to be missed in silence.
$max  = AB_MCP_Replace_Engine::MAX_DEPTH;
$wrap = static function ( $v, $n ) { for ( $i = 0; $i < $n; $i++ ) { $v = array( $v ); } return $v; };
$el   = new AB_MCP_Replace_Engine( 'foo', 'bar', false, false );
$count = 0;
// The stored string is depth 0, the decoded document 1: a string inside n wrappers of the document's second entry sits at n + 2.
ok( json_encode( array( 'bar', $wrap( 'bar', $max - 2 ) ) ) === $el->apply_value( json_encode( array( 'foo', $wrap( 'foo', $max - 2 ) ) ), $count ) && 2 === $count && 0 === $el->skipped_unchecked_values, 'a JSON string exactly at the limit is still replaced' );
$count = 0;
ok( json_encode( array( 'bar', $wrap( 'foo', $max - 1 ) ) ) === $el->apply_value( json_encode( array( 'foo', $wrap( 'foo', $max - 1 ) ) ), $count ) && 1 === $count && 1 === $el->skipped_unchecked_values, 'one level deeper it is left as it is and counted as unchecked, while the rest of the document is still replaced' );
$count = 0;
$inert = json_encode( $wrap( array( 5, true, null, '', array(), new stdClass(), 'foo' ), $max - 1 ) );
ok( $inert === $el->apply_value( $inert, $count ) && 0 === $count && 2 === $el->skipped_unchecked_values, 'beyond the limit only what could hold text is counted: a number, a boolean, null and an empty string or container cannot' );
$count = 0;
$inner = json_encode( $wrap( serialize( $wrap( 'foo', 8 ) ), $max - 4 ) );
ok( $inner === $el->apply_value( $inner, $count ) && 0 === $count && 3 === $el->skipped_unchecked_values, 'a serialized value inside the JSON that reaches past the limit is counted too, not missed in silence' );
// json_decode() hands Elementor's elements and settings back as objects: an
// object that could hold text and lies beyond the limit counts like an array.
$count = 0;
$obj   = json_encode( $wrap( (object) array( 'title' => 'foo' ), $max ) );
ok( $obj === $el->apply_value( $obj, $count ) && 0 === $count && 4 === $el->skipped_unchecked_values, 'a non-empty object beyond the limit is counted as unchecked, as decoded JSON hands it over' );

echo "engine: strings inside strings are unpacked at most MAX_LAYERS deep\n";
// Every unpacked layer holds a copy of nearly the whole row; the depth limit
// alone let 64 of them pile up. The layer limit is the reach the engine had.
$layers = AB_MCP_Replace_Engine::MAX_LAYERS;
$chain  = static function ( $v, $n, $enc ) { for ( $i = 0; $i < $n; $i++ ) { $v = $enc( $v ); } return $v; };
$ser    = static function ( $v ) { return serialize( $v ); };
$js     = static function ( $v ) { return json_encode( array( $v ) ); };
ok( 12 === $layers, 'the layer limit stays at the 12 the engine reached before the depth limit was raised' );
$el    = new AB_MCP_Replace_Engine( 'foo', 'bar', false, false );
$count = 0;
ok( $chain( 'x bar', $layers, $ser ) === $el->apply_value( $chain( 'x foo', $layers, $ser ), $count ) && 1 === $count && 0 === $el->skipped_unchecked_values, 'text inside exactly MAX_LAYERS serialized strings is still replaced' );
$count = 0;
$deep_chain = $chain( 'x foo', $layers + 1, $ser );
ok( $deep_chain === $el->apply_value( $deep_chain, $count ) && 0 === $count && 1 === $el->skipped_unchecked_values, 'one serialized string more is not unpacked: left as it is and counted, although the depth limit would still allow it' );
$count = 0;
ok( $chain( 'x bar', $layers, $js ) === $el->apply_value( $chain( 'x foo', $layers, $js ), $count ) && 1 === $count && 1 === $el->skipped_unchecked_values, 'text inside exactly MAX_LAYERS JSON documents is still replaced' );
$count = 0;
$deep_chain = $chain( 'x foo', $layers + 1, $js );
ok( $deep_chain === $el->apply_value( $deep_chain, $count ) && 0 === $count && 2 === $el->skipped_unchecked_values, 'one JSON document more is not walked: left as it is and counted' );
// At the limit, text that only starts like JSON is not JSON and is replaced as
// text, as it was before the layer limit existed.
$count = 0;
ok( $chain( '[gallery] bar', $layers, $ser ) === $el->apply_value( $chain( '[gallery] foo', $layers, $ser ), $count ) && 1 === $count && 2 === $el->skipped_unchecked_values, 'at the limit, text that merely starts like JSON is still replaced as text' );
// Layers are counted per chain, not per row: siblings each get the full reach.
$count = 0;
$pair  = serialize( array( $chain( 'x foo', $layers - 1, $ser ), $chain( 'y foo', $layers - 1, $js ) ) );
ok( serialize( array( $chain( 'x bar', $layers - 1, $ser ), $chain( 'y bar', $layers - 1, $js ) ) ) === $el->apply_value( $pair, $count ) && 2 === $count && 2 === $el->skipped_unchecked_values, 'two chains side by side each get the full reach' );

echo "the SQL mirror of is_sensitive_meta_key\n";
$base   = reflect( 'AB_MCP_Tools_Base', 'is_sensitive_meta_key' );
$corpus = array_merge(
	AB_MCP_Tools_Search_Replace::SENSITIVE_META_EXACT,
	AB_MCP_Tools_Search_Replace::SENSITIVE_META_NEEDLES,
	array( 'token_count', 'password_hint', 'credential_type', 'my_token', 'tokens2', 'Token', ' secret ', "secret\t", 'apikeys', 'xoauthx', 'smtp_password', 'color', 'price', 'pwd2', 'passwd_old', 'otpx', '_elementor_data', 'stripe_secret_key', 'SECRET_KEY', '', ' ' )
);
$agree = true;
foreach ( $corpus as $key ) {
	$m      = strtolower( trim( (string) $key ) );
	$mirror = in_array( $m, AB_MCP_Tools_Search_Replace::SENSITIVE_META_EXACT, true );
	foreach ( AB_MCP_Tools_Search_Replace::SENSITIVE_META_NEEDLES as $n ) { if ( '' !== $m && false !== strpos( $m, $n ) ) { $mirror = true; } }
	if ( $mirror !== (bool) $base->invoke( null, $key ) ) { $agree = false; echo "  disagree on '" . addcslashes( $key, "\t" ) . "'\n"; }
}
ok( $agree, 'the Pro lists reproduce the bundled core rule on ' . count( $corpus ) . ' keys' );
$args = array();
$sql  = reflect( 'AB_MCP_Tools_Search_Replace', 'sensitive_key_sql' )->invokeArgs( null, array( 'pm.meta_key', &$args ) );
$norm = reflect( 'AB_MCP_Tools_Search_Replace', 'sql_name_normalized' )->invoke( null, 'pm.meta_key' );
ok( false === strpos( $norm, 'LOWER(' ) && false !== strpos( $norm, "CHAR(0), '')" ) && false !== strpos( $norm, "'A', 'a')" ) && false !== strpos( $norm, "'Z', 'z')" ) && 32 === substr_count( $norm, 'REPLACE(' ), 'the name is folded letter by letter with REPLACE(), never with the collation-dependent LOWER(), and stripped of every character PHP trims' );
ok( 1 === substr_count( $sql, "{$norm} NOT IN (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)" ) && 23 === substr_count( $sql, "{$norm} NOT LIKE %s" ) && 39 === count( $args ), 'one NOT IN for the exact names, one LIKE per needle' );
ok( array_slice( $args, 0, 16 ) === AB_MCP_Tools_Search_Replace::SENSITIVE_META_EXACT, 'the exact names come first, in list order' );
ok( '%api\_key%' === $args[16] && '%smtp\_pass%' === $args[38], 'then the needles, underscores escaped so LIKE reads them literally' );

echo "prepared statements\n";
foreach ( array( false, true ) as $regex ) {
	reset_log();
	$SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'posts', 'postmeta', 'options', 'terms' ), 'regex' => $regex ) );
	prepares_ok( $regex ? 'regex mode' : 'plain mode' );
}
reset_log();
$SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'posts' ), 'type' => 'post' ) );
prepares_ok( 'posts with an explicit type' );

echo "guards as case tables\n";
foreach ( array( 'custom_css', 'wp_global_styles', ' WP_Font_Face ', 'oembed_cache', 'user_request', 'customize_changeset' ) as $t ) { ok( AB_MCP_Tools_Search_Replace::internal_post_type( $t ), "internal post type '$t'" ); }
foreach ( array( 'post', 'page', 'product', 'wp_template', 'wp_navigation', 'revision', '' ) as $t ) { ok( ! AB_MCP_Tools_Search_Replace::internal_post_type( $t ), "ordinary post type '$t'" ); }
foreach ( array( 'wp_theme', 'wp_template_part_area', 'post_format' ) as $t ) { ok( AB_MCP_Tools_Search_Replace::internal_taxonomy( $t ), "internal taxonomy '$t'" ); }
ok( ! AB_MCP_Tools_Search_Replace::internal_taxonomy( 'wp_pattern_category' ), 'wp_pattern_category is not internal: its terms are ordinary categories a user creates and edits in the pattern screen' );
foreach ( array( 'category', 'post_tag', 'nav_menu', 'product_cat', '' ) as $t ) { ok( ! AB_MCP_Tools_Search_Replace::internal_taxonomy( $t ), "ordinary taxonomy '$t'" ); }
$r = $SR( array( 'search' => 'foo', 'type' => 'custom_css' ) );
ok( is_wp_error( $r ) && 'ab_mcp_internal_type' === $r->code, 'asking for an internal type by name is refused' );
reset_log();
$r = $SR( array( 'search' => 'foo', 'type' => 'page' ) );
ok( ! is_wp_error( $r ) && 'page' === $r['flags']['type'] && false !== strpos( query_with( 'post_excerpt FROM wp_posts' ), 'AND post_type = %s AND ID > %d' ) && 'page' === args_of( 'post_excerpt FROM wp_posts' )[7], 'asking for a type by name narrows the SQL to it; whether its posts may be rewritten is decided per row by what WordPress answers, never per type (a meta capability asked without a post id, a revision judged by its own type, a map_meta_cap filter that grants by context would each mislead a type-level verdict)' );
// type is checked and applied for the posts scan only.
reset_log();
$r = $SR( array( 'search' => 'foo', 'tables' => array( 'options' ), 'type' => 'page' ) );
ok( ! is_wp_error( $r ) && 'any' === $r['flags']['type'] && true === $r['flags']['type_ignored'] && isset( $r['tables']['options'] ), 'a call about options is not stopped by a type the caller may not edit site-wide: type is checked and applied for the posts scan only, and the flags say it was ignored' );
$r = $SR( array( 'search' => 'foo', 'tables' => array( 'terms' ), 'type' => 'custom_css' ) );
ok( ! is_wp_error( $r ) && true === $r['flags']['type_ignored'], 'nor by an internal type name' );
$r = $SR( array( 'search' => 'foo', 'tables' => array( 'options', 'posts' ), 'type' => 'page' ) );
ok( ! is_wp_error( $r ) && 'page' === $r['flags']['type'] && false === $r['flags']['type_ignored'], 'with the posts scan among the tables the type applies' );
$r = $SR( array( 'search' => 'foo', 'tables' => array( 'posts' ), 'type' => 'attachment' ) );
ok( 'attachment' === $r['flags']['type'] && false === $r['flags']['type_ignored'], 'and echoed as applied' );
$r = AB_MCP_Tools_Search_Bulk::bulk_update_status( array( 'ids' => array( 1 ), 'status' => 'bogus' ) );
ok( is_wp_error( $r ) && 'ab_mcp_bad_status' === $r->code, 'bulk status: unknown status is a WP_Error, not an array' );
$GLOBALS['t_bulk_knobs']  = array();
$GLOBALS['t_trash_calls'] = array();
// A site without a trash (the run with AB_TEST_NO_TRASH): "trash" would delete for good, so nothing is written.
if ( defined( 'EMPTY_TRASH_DAYS' ) && ! EMPTY_TRASH_DAYS ) {
	$GLOBALS['t_bulk_posts'] = array( 63 => array( 'post_type' => 'post', 'post_status' => 'publish', 'post_parent' => 0 ) );
	$r = AB_MCP_Tools_Search_Bulk::bulk_update_status( array( 'ids' => array( 63 ), 'status' => 'trash' ) );
	ok( is_wp_error( $r ) && 'ab_mcp_no_trash' === $r->code && array() === $GLOBALS['t_trash_calls'] && 'publish' === $GLOBALS['t_bulk_posts'][63]['post_status'], 'no trash: "trash" is refused before anything is written — WordPress would delete the posts for good' );
	echo $fail ? "FAILED: $fail\n" : "no-trash: all passed\n";
	exit( $fail ? 1 : 0 );
}
// The undo point holds the status as the table has it. get_post_status() gives a file stored as "inherit" its
// parent's status, or "publish" when it has none, and the undo would put back a status the file never had.
$GLOBALS['t_bulk_posts'] = array( 40 => array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 0 ) );
$GLOBALS['t_undo']       = array();
$resolved                = get_post_status( 40 );
$r = AB_MCP_Tools_Search_Bulk::bulk_update_status( array( 'ids' => array( 40 ), 'status' => 'private' ) );
ok( ! is_wp_error( $r ) && 1 === $r['updated'] && isset( $GLOBALS['t_undo'][0]['data'] ) && array( array( 40, 'inherit' ) ) === $GLOBALS['t_undo'][0]['data'] && 'publish' === $resolved, 'bulk status: the undo point holds the status the table has ("inherit"), not the one get_post_status() resolves for a file ("publish")' );
// The undo point notes that a post had a password — WordPress drops it when it saves a post as private — without keeping it.
$GLOBALS['t_bulk_posts'] = array(
	41 => array( 'post_type' => 'post', 'post_status' => 'publish', 'post_parent' => 0, 'post_password' => 'geheim' ),
	42 => array( 'post_type' => 'post', 'post_status' => 'publish', 'post_parent' => 0, 'post_password' => '' ),
	43 => array( 'post_type' => 'post', 'post_status' => 'publish', 'post_parent' => 0, 'post_password' => 'geheim' ),
);
$GLOBALS['t_undo'] = array();
$r = AB_MCP_Tools_Search_Bulk::bulk_update_status( array( 'ids' => array( 41, 42 ), 'status' => 'private' ) );
ok( ! is_wp_error( $r ) && 2 === $r['updated'] && isset( $GLOBALS['t_undo'][0]['data'] ) && array( array( 41, 'publish', 'password_protected' ), array( 42, 'publish' ) ) === $GLOBALS['t_undo'][0]['data'] && '' === $GLOBALS['t_bulk_posts'][41]['post_password'] && false === strpos( json_encode( $GLOBALS['t_undo'][0] ), 'geheim' ), 'bulk status: the undo point notes that a post had a password — without keeping the password; a post without one gets no note' );
$GLOBALS['t_undo'] = array();
$r = AB_MCP_Tools_Search_Bulk::bulk_update_status( array( 'ids' => array( 43 ), 'status' => 'draft' ) );
ok( ! is_wp_error( $r ) && isset( $GLOBALS['t_undo'][0]['data'] ) && array( array( 43, 'publish', 'password_protected' ) ) === $GLOBALS['t_undo'][0]['data'] && 'geheim' === $GLOBALS['t_bulk_posts'][43]['post_password'], 'bulk status: a status that keeps the password notes it too — a later call may still drop it' );

echo "bulk status: what the site stored decides (4.5.10)\n";
$bulk_run = static function ( array $posts, array $knobs, $status, ?array $ids = null, ?array $meta = null ) {
	$GLOBALS['t_meta_store'] = $meta ?? array();
	$GLOBALS['t_add_meta']   = array();
	$GLOBALS['t_bulk_posts']  = $posts;
	$GLOBALS['t_bulk_knobs']  = $knobs;
	$GLOBALS['t_trash_calls'] = array();
	$GLOBALS['t_trash_notes_cleared'] = array();
	$GLOBALS['t_untrash_calls'] = array();
	$GLOBALS['t_del_meta']    = array();
	$GLOBALS['t_undo']        = array();
	$GLOBALS['t_post']        = array();
	$GLOBALS['t_post_wp_error'] = array();
	return AB_MCP_Tools_Search_Bulk::bulk_update_status( array( 'ids' => $ids ?? array_keys( $posts ), 'status' => $status ) );
};
$undo_data = static function () { return isset( $GLOBALS['t_undo'][0]['data'] ) ? $GLOBALS['t_undo'][0]['data'] : array(); };
$pub       = array( 'post_type' => 'post', 'post_status' => 'publish', 'post_parent' => 0 );
$r = $bulk_run( array( 62 => $pub ), array(), 'draft' );
ok( ! is_wp_error( $r ) && 1 === $r['updated'] && array( 62 ) === $r['ids'] && array() === $r['failed'] && 0 === $r['failed_omitted'] && array() === $r['stored_as'] && array( array( 62, 'publish' ) ) === $undo_data(), 'bulk status: a status stored as asked is counted and goes into the undo point' );
ok( array( false ) === $GLOBALS['t_post_wp_error'], 'bulk status: wp_update_post() is called without $wp_error — with it, WordPress gives up after the row is written when the page template is gone, before the status hooks' );
$r = $bulk_run( array( 50 => $pub, 51 => $pub ), array( 50 => array( 'error' => true ), 51 => array( 'zero' => true ) ), 'draft' );
ok( ! is_wp_error( $r ) && 0 === $r['updated'] && array() === $r['ids'] && array( array( 'id' => 50, 'status' => 'publish', 'error' => 'update_failed' ), array( 'id' => 51, 'status' => 'publish', 'error' => 'update_failed' ) ) === $r['failed'] && array() === $GLOBALS['t_undo'], 'bulk status: a write WordPress refused is reported in failed with the status the post kept — not as updated, not in the undo point' );
$r = $bulk_run( array( 68 => $pub ), array( 68 => array( 'writes_then_fails' => true ) ), 'draft' );
ok( ! is_wp_error( $r ) && 1 === $r['updated'] && array( 68 ) === $r['ids'] && array() === $r['failed'] && array( array( 68, 'publish' ) ) === $undo_data(), 'bulk status: a write that answered failure but stored the status counts — the table decides, and the change goes into the undo point' );
$r = $bulk_run( array( 69 => $pub ), array( 69 => array( 'writes_then_fails' => true, 'unreadable_after' => true ) ), 'draft' );
ok( ! is_wp_error( $r ) && 0 === $r['updated'] && array( array( 'id' => 69, 'status' => null, 'error' => 'update_failed' ) ) === $r['failed'] && array( array( 69, 'publish' ) ) === $undo_data(), 'bulk status: ... and where it cannot be read back, a failure answer does not rule out a change: the undo pair is kept' );
$r = $bulk_run( array( 52 => $pub, 53 => $pub ), array( 52 => array( 'refuse' => true ), 53 => array( 'pretend' => true ) ), 'trash' );
ok( ! is_wp_error( $r ) && 0 === $r['updated'] && array( array( 'id' => 52, 'status' => 'publish', 'error' => 'trash_refused' ), array( 'id' => 53, 'status' => 'publish' ) ) === $r['failed'] && array() === $GLOBALS['t_undo'], 'bulk status: a move to the trash a pre_trash_post filter refused — or claimed without doing it — is reported in failed; the table decides, not the answer' );
$r = $bulk_run( array( 54 => array( 'post_type' => 'post', 'post_status' => 'trash', 'post_parent' => 0 ) ), array(), 'trash' );
ok( ! is_wp_error( $r ) && 1 === $r['updated'] && array( 54 ) === $r['ids'] && array() === $r['failed'] && array() === $GLOBALS['t_trash_calls'] && array() === $GLOBALS['t_undo'], 'bulk status: a post that already has the status is left as it is — counted, not written, nothing to undo' );
$r = $bulk_run( array( 55 => $pub ), array( 55 => array( 'store' => 'pending' ) ), 'draft' );
ok( ! is_wp_error( $r ) && 0 === $r['updated'] && array( array( 'id' => 55, 'status' => 'pending' ) ) === $r['failed'] && array( array( 55, 'publish' ) ) === $undo_data(), 'bulk status: a status a plugin stored in place of the one asked for is reported in failed — and goes into the undo point, since it changed' );
$r = $bulk_run( array( 56 => array( 'post_type' => 'post', 'post_status' => 'draft', 'post_parent' => 0, 'post_date_gmt' => '2099-01-01 09:00:00' ) ), array(), 'publish' );
ok( ! is_wp_error( $r ) && 1 === $r['updated'] && array( 56 ) === $r['ids'] && array( array( 'id' => 56, 'status' => 'future' ) ) === $r['stored_as'] && array() === $r['failed'] && array( array( 56, 'draft' ) ) === $undo_data(), 'bulk status: a post dated ahead is scheduled — counted, with the form WordPress stored in stored_as' );
$r = $bulk_run( array( 57 => array( 'post_type' => 'attachment', 'post_status' => 'private', 'post_parent' => 0 ) ), array(), 'draft' );
ok( ! is_wp_error( $r ) && 1 === $r['updated'] && array( array( 'id' => 57, 'status' => 'inherit' ) ) === $r['stored_as'] && array( array( 57, 'private' ) ) === $undo_data(), 'bulk status: a file is stored as "inherit" for "draft" — counted, in stored_as' );
$r = $bulk_run( array( 58 => array( 'post_type' => 'post', 'post_status' => 'draft', 'post_parent' => 0 ) ), array( 58 => array( 'store' => 'future' ) ), 'publish' );
ok( ! is_wp_error( $r ) && 0 === $r['updated'] && array( array( 'id' => 58, 'status' => 'future' ) ) === $r['failed'], 'bulk status: "future" counts for "publish" only with a date still ahead' );
$r = $bulk_run( array( 59 => array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 0 ) ), array( 59 => array( 'store' => 'inherit' ) ), 'private' );
ok( ! is_wp_error( $r ) && 0 === $r['updated'] && array( array( 'id' => 59, 'status' => 'inherit' ) ) === $r['failed'] && array() === $GLOBALS['t_undo'], 'bulk status: "inherit" counts for a file only where it cannot have the status asked — not for "private"' );
$r = $bulk_run( array( 60 => $pub ), array( 60 => array( 'deletes' => true ) ), 'draft' );
ok( ! is_wp_error( $r ) && 0 === $r['updated'] && array( array( 'id' => 60, 'status' => null, 'error' => 'unverified' ) ) === $r['failed'] && array( array( 60, 'publish' ) ) === $undo_data(), 'bulk status: a post a plugin removed during the write is reported in failed, unverified — and stays in the undo point, which finds it gone' );
$r = $bulk_run( array( 64 => $pub ), array( 64 => array( 'unreadable_after' => true ) ), 'draft' );
ok( ! is_wp_error( $r ) && 0 === $r['updated'] && array( array( 'id' => 64, 'status' => null, 'error' => 'unverified' ) ) === $r['failed'] && array( array( 64, 'publish' ) ) === $undo_data() && 'draft' === $GLOBALS['t_bulk_posts'][64]['post_status'], 'bulk status: a write that reported success but cannot be read back is reported as unverified — its undo pair is kept, the change is real' );
$r = $bulk_run( array( 65 => $pub ), array( 65 => array( 'error' => true, 'unreadable_after' => true ) ), 'draft' );
ok( ! is_wp_error( $r ) && 0 === $r['updated'] && array( array( 'id' => 65, 'status' => null, 'error' => 'update_failed' ) ) === $r['failed'] && array( array( 65, 'publish' ) ) === $undo_data(), 'bulk status: ... a write that answered failure and cannot be read back keeps its undo pair too — nothing rules out a change' );
$r = $bulk_run( array( 70 => $pub ), array( 70 => array( 'store_once' => 'pending' ) ), 'draft', array( 70, 70 ) );
ok( ! is_wp_error( $r ) && 0 === $r['updated'] && array( array( 'id' => 70, 'status' => 'pending' ) ) === $r['failed'] && array( array( 70, 'publish' ) ) === $undo_data() && 1 === count( $GLOBALS['t_post'] ), 'bulk status: an id given twice is handled once — a second round would start from the first one\'s result, and the undo would end there' );
$r = $bulk_run( array( 71 => $pub ), array(), 'draft', array( 71, 71 ) );
ok( ! is_wp_error( $r ) && 1 === $r['updated'] && array( 71 ) === $r['ids'], 'bulk status: ... and counted once' );
$r = $bulk_run( array( 66 => $pub, 67 => $pub ), array( 67 => array( 'store' => 'pending' ) ), 'draft' );
ok( ! is_wp_error( $r ) && isset( $GLOBALS['t_undo'][0]['summary'] ) && '2 post(s): bulk status change (asked for "draft")' === $GLOBALS['t_undo'][0]['summary'], 'bulk status: the undo point says what was asked for, not that every post got it — nor that each one changed' );
$r = $bulk_run( array( 61 => $pub ), array( 61 => array( 'unreadable' => true ) ), 'draft' );
ok( ! is_wp_error( $r ) && 0 === $r['updated'] && array( array( 'id' => 61, 'status' => null, 'error' => 'unreadable' ) ) === $r['failed'] && array() === $GLOBALS['t_post'], 'bulk status: a post whose status cannot be read first is not written — its change could not go into the undo point' );
$many = array();
$knob = array();
for ( $i = 1000; $i < 1101; $i++ ) { $many[ $i ] = $pub; $knob[ $i ] = array( 'error' => true ); }
$r = $bulk_run( $many, $knob, 'draft' );
ok( ! is_wp_error( $r ) && 0 === $r['updated'] && 100 === count( $r['failed'] ) && 1 === $r['failed_omitted'], 'bulk status: failed lists the first 100, like forbidden, and failed_omitted counts the rest' );
$many = array();
for ( $i = 2000; $i < 2101; $i++ ) { $many[ $i ] = array( 'post_type' => 'attachment', 'post_status' => 'private', 'post_parent' => 0 ); }
$r = $bulk_run( $many, array(), 'draft' );
ok( ! is_wp_error( $r ) && 101 === $r['updated'] && 101 === count( $r['stored_as'] ), 'bulk status: stored_as names every post stored in another form — one per id, and the ids are capped' );

echo "bulk status: a time budget, so the answer arrives (27.10.2026)\n";
// The clock runs only where the test says: a list of readings, the last one repeated.
$clock_at = static function ( array $readings ) {
	return static function () use ( &$readings ) { return count( $readings ) > 1 ? array_shift( $readings ) : $readings[0]; };
};
$saved_start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? $_SERVER['REQUEST_TIME_FLOAT'] : null;
$_SERVER['REQUEST_TIME_FLOAT'] = 1000.0;
$five = array( 200 => $pub, 201 => $pub, 202 => $pub, 203 => $pub, 204 => $pub );
AB_MCP_Tools_Search_Bulk::$clock = $clock_at( array( 1010.0, 1060.0 ) );
$r = $bulk_run( $five, array(), 'draft' );
ok( ! is_wp_error( $r ) && 2 === $r['updated'] && array( 200, 201 ) === $r['ids'] && array( 202, 203, 204 ) === $r['remaining'] && 2 === count( $GLOBALS['t_post'] ), 'bulk status: once the request has run 60 seconds, no further post is begun; remaining lists the rest in the order given' );
ok( array( array( 200, 'publish' ), array( 201, 'publish' ) ) === $undo_data() && 'publish' === $GLOBALS['t_bulk_posts'][202]['post_status'] && 'publish' === $GLOBALS['t_bulk_posts'][204]['post_status'], 'bulk status: ... the undo point holds what was done, the rest is untouched' );
AB_MCP_Tools_Search_Bulk::$clock = $clock_at( array( 1059.9 ) );
$r = $bulk_run( $five, array(), 'draft' );
ok( ! is_wp_error( $r ) && 5 === $r['updated'] && array() === $r['remaining'], 'bulk status: just under the budget every post is handled, and remaining is empty' );
// Counted from the start of the request: loading WordPress took its time too.
AB_MCP_Tools_Search_Bulk::$clock = $clock_at( array( 1075.0 ) );
$r = $bulk_run( $five, array(), 'draft' );
ok( ! is_wp_error( $r ) && 1 === $r['updated'] && array( 200 ) === $r['ids'] && array( 201, 202, 203, 204 ) === $r['remaining'], 'bulk status: a request already past the budget still handles its first post, so every call gets on' );
ok( 'undo-token' === $r['undo'] && 1 === count( $GLOBALS['t_undo'] ), 'bulk status: ... and a call stopped by the budget still answers with the undo token of what it did' );
$stopped = $r;
// The ids it did not reach, sent again, continue where it stopped — with an undo point of their own.
AB_MCP_Tools_Search_Bulk::$clock = $clock_at( array( 1010.0 ) );
$first_undo = $GLOBALS['t_undo'];
$GLOBALS['t_undo'] = array();
$GLOBALS['t_post'] = array();
$r = AB_MCP_Tools_Search_Bulk::bulk_update_status( array( 'ids' => $stopped['remaining'], 'status' => 'draft' ) );
ok( ! is_wp_error( $r ) && 4 === $r['updated'] && array() === $r['remaining'] && 'draft' === $GLOBALS['t_bulk_posts'][204]['post_status'] && array( array( 201, 'publish' ), array( 202, 'publish' ), array( 203, 'publish' ), array( 204, 'publish' ) ) === $undo_data() && array( array( 200, 'publish' ) ) === $first_undo[0]['data'], 'bulk status: remaining sent as ids goes on from there, and each call keeps its own undo point' );
ok( 'undo-token' === $stopped['undo'] && 'undo-token' === $r['undo'] && 1 === count( $first_undo ) && 1 === count( $GLOBALS['t_undo'] ), 'bulk status: ... both answers carry an undo token, one point each' );
// Beyond 500 ids: over_limit counts them as before, remaining names only what this call did not reach of its 500.
$many = array();
for ( $i = 4000; $i < 4503; $i++ ) { $many[ $i ] = $pub; }
AB_MCP_Tools_Search_Bulk::$clock = $clock_at( array_merge( array_fill( 0, 299, 1010.0 ), array( 1060.0 ) ) );
$r = $bulk_run( $many, array(), 'draft' );
ok( ! is_wp_error( $r ) && 300 === $r['updated'] && 200 === count( $r['remaining'] ) && 4300 === $r['remaining'][0] && 4499 === $r['remaining'][199] && 3 === $r['over_limit'], 'bulk status: remaining holds the untouched part of the first 500, over_limit still counts the ids beyond them' );
AB_MCP_Tools_Search_Bulk::$clock = null;
if ( null === $saved_start ) { unset( $_SERVER['REQUEST_TIME_FLOAT'] ); } else { $_SERVER['REQUEST_TIME_FLOAT'] = $saved_start; }

echo "bulk status: a file left to its parent needs the right to publish (4.5.10)\n";
$priv_file = static function ( $parent ) { return array( 'post_type' => 'attachment', 'post_status' => 'private', 'post_parent' => $parent ); };
$draft_post = array( 'post_type' => 'post', 'post_status' => 'draft', 'post_parent' => 0 );
$GLOBALS['t_no_publish'] = true;
$r = $bulk_run( array( 80 => $priv_file( 0 ) ), array(), 'draft' );
ok( ! is_wp_error( $r ) && 0 === $r['updated'] && array( 80 ) === $r['forbidden'] && 'private' === $GLOBALS['t_bulk_posts'][80]['post_status'] && array() === $GLOBALS['t_post'], 'bulk status: without the right to publish, a private file without a parent is not made a draft — WordPress would store "inherit", which is public' );
$r = $bulk_run( array( 81 => $pub, 82 => $priv_file( 81 ) ), array(), 'pending', array( 82 ) );
ok( ! is_wp_error( $r ) && 0 === $r['updated'] && array( 82 ) === $r['forbidden'], 'bulk status: ... nor one under a published parent' );
$r = $bulk_run( array( 83 => $draft_post, 84 => $priv_file( 83 ) ), array(), 'draft', array( 84 ) );
ok( ! is_wp_error( $r ) && 0 === $r['updated'] && array( 84 ) === $r['forbidden'] && 'private' === $GLOBALS['t_bulk_posts'][84]['post_status'] && array() === $GLOBALS['t_post'], 'bulk status: ... nor one under a draft — whoever publishes the draft later shows the file' );
$r = $bulk_run( array( 86 => $draft_post, 87 => $priv_file( 86 ) ), array(), 'publish', array( 87 ) );
ok( ! is_wp_error( $r ) && array( 87 ) === $r['forbidden'], 'bulk status: asking for "publish" still needs the right' );
$r = $bulk_run( array( 83 => $draft_post, 85 => array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 83 ) ), array(), 'draft', array( 85 ) );
ok( ! is_wp_error( $r ) && 1 === $r['updated'] && array( array( 'id' => 85, 'status' => 'inherit' ) ) === $r['stored_as'] && array() === $r['forbidden'] && array() === $GLOBALS['t_undo'], 'bulk status: a file that has "inherit" keeps its parent — "draft" leaves it to no other post, no right needed' );
$r = $bulk_run( array( 90 => array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 90 ) ), array(), 'draft' );
ok( ! is_wp_error( $r ) && array( 90 ) === $r['forbidden'], 'bulk status: a file named as its own parent gets none from WordPress — and without one it is public' );
$r = $bulk_run( array( 91 => array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 92 ), 92 => array( 'post_type' => 'post', 'post_status' => 'draft', 'post_parent' => 91 ) ), array(), 'draft', array( 91 ) );
ok( ! is_wp_error( $r ) && array( 91 ) === $r['forbidden'] && array() === $GLOBALS['t_post'], 'bulk status: ... nor does a file a loop runs through' );
$r = $bulk_run( array( 83 => $draft_post, 93 => array( 'post_type' => 'attachment', 'post_status' => 'trash', 'post_parent' => 83 ) ), array(), 'draft', array( 93 ) );
ok( ! is_wp_error( $r ) && array( 93 ) === $r['forbidden'] && array() === $GLOBALS['t_untrash_calls'] && 'trash' === $GLOBALS['t_bulk_posts'][93]['post_status'], 'bulk status: a file in the trash does not come out as "inherit" without the right' );
$r = $bulk_run( array( 89 => $priv_file( 0 ) ), array(), 'trash' );
ok( ! is_wp_error( $r ) && 1 === $r['updated'] && array() === $r['forbidden'] && 'trash' === $GLOBALS['t_bulk_posts'][89]['post_status'], 'bulk status: moving a file to the trash shows it to nobody — no right to publish needed' );
$many = array();
for ( $i = 3000; $i < 3101; $i++ ) { $many[ $i ] = $priv_file( 0 ); }
$r = $bulk_run( $many, array(), 'draft' );
ok( ! is_wp_error( $r ) && 0 === $r['updated'] && 100 === count( $r['forbidden'] ) && 1 === $r['forbidden_omitted'], 'bulk status: forbidden lists the first 100, and forbidden_omitted counts the rest' );
$GLOBALS['t_no_publish'] = false;
$r = $bulk_run( array( 88 => $priv_file( 0 ) ), array(), 'draft' );
ok( ! is_wp_error( $r ) && 1 === $r['updated'] && array( array( 'id' => 88, 'status' => 'inherit' ) ) === $r['stored_as'] && 0 === $r['forbidden_omitted'], 'bulk status: with the right to publish, the file goes to "inherit"' );

echo "bulk status: out of the trash and into it the way WordPress does (4.5.10)\n";
$trashed = array( 'post_type' => 'post', 'post_status' => 'trash', 'post_parent' => 0 );
$r = $bulk_run( array( 100 => $trashed ), array(), 'publish' );
ok( ! is_wp_error( $r ) && 1 === $r['updated'] && array( 100 ) === $r['ids'] && 'publish' === $GLOBALS['t_bulk_posts'][100]['post_status'] && array( 100 ) === $GLOBALS['t_untrash_calls'] && array( array( 100, 'trash' ) ) === $undo_data() && array() === $GLOBALS['t_add_meta'], 'bulk status: a post in the trash comes out through wp_untrash_post(), straight to the status asked for' );
ok( array( array( 100, '_wp_trash_meta_status' ), array( 100, '_wp_trash_meta_time' ), array( 100, '_wp_trash_meta_comments_status' ) ) === $GLOBALS['t_del_meta'], 'bulk status: ... so the trash notes go and the comments get their states back — a plain status write left both' );
$GLOBALS['t_hooked']['wp_untrash_post_status'][10][] = static function () { return 'pending'; };   // the site's own filter
$r = $bulk_run( array( 101 => $trashed ), array(), 'draft' );
ok( ! is_wp_error( $r ) && 1 === $r['updated'] && 'draft' === $GLOBALS['t_bulk_posts'][101]['post_status'] && array( 10 ) === array_keys( array_filter( $GLOBALS['t_hooked']['wp_untrash_post_status'] ) ), 'bulk status: ... whatever status a filter of the site would have the trash give back — and the tool\'s own pin is gone afterwards' );
$GLOBALS['t_hooked'] = array();
$r = $bulk_run( array( 102 => $trashed ), array( 102 => array( 'untrash_refuse' => true ) ), 'draft' );
ok( ! is_wp_error( $r ) && 0 === $r['updated'] && array( array( 'id' => 102, 'status' => 'trash', 'error' => 'untrash_refused' ) ) === $r['failed'] && array() === $GLOBALS['t_undo'], 'bulk status: a post a pre_untrash_post filter keeps in the trash is reported with untrash_refused' );
$r = $bulk_run( array( 103 => $pub ), array(), 'trash', null, array( 103 => array( '_wp_trash_meta_status' => array( 'publish' ), '_wp_trash_meta_time' => array( '1600000000' ), '_wp_trash_meta_comments_status' => array( array( 7 => '1', 8 => 'spam' ) ) ) ) );
ok( ! is_wp_error( $r ) && 1 === $r['updated'] && array( array( 103, '_wp_trash_meta_status' ), array( 103, '_wp_trash_meta_time' ) ) === $GLOBALS['t_del_meta'] && array( 2 ) === $GLOBALS['t_trash_notes_cleared'], 'bulk status: before a move to the trash, the status and the time an earlier stay left are cleared — WordPress adds its own next to them and reads the first; by the old time its daily clean-up would delete the post for good at once' );
ok( array( array( 7 => '1', 8 => 'spam' ) ) === ( $GLOBALS['t_meta_store'][103]['_wp_trash_meta_comments_status'] ?? null ), 'bulk status: ... the note on the comments stays — comments a plain status write left hidden have their earlier states only there' );
$notes = array( '_wp_trash_meta_status' => array( 'publish', 'draft' ), '_wp_trash_meta_time' => array( '1700000000' ) );
$r = $bulk_run( array( 105 => $trashed ), array( 105 => array( 'error' => true ) ), 'draft', null, array( 105 => $notes ) );
ok( ! is_wp_error( $r ) && 0 === $r['updated'] && array( array( 'id' => 105, 'status' => 'trash', 'error' => 'untrash_refused' ) ) === $r['failed'] && array() === $GLOBALS['t_undo'] && $notes === $GLOBALS['t_meta_store'][105], 'bulk status: where the save fails on the way out of the trash, the notes WordPress deleted before it are put back, in their order — a file under the post would otherwise count as published' );
$r = $bulk_run( array( 107 => $trashed ), array(), 'draft', null, array( 107 => $notes ) );
ok( ! is_wp_error( $r ) && 1 === $r['updated'] && array() === ( $GLOBALS['t_meta_store'][107] ?? array() ) && array() === $GLOBALS['t_add_meta'], 'bulk status: ... but where the post came out, WordPress has deleted them for good — none is put back on a post outside the trash' );
$r = $bulk_run( array( 106 => $trashed ), array( 106 => array( 'untrash_refuse' => true ) ), 'draft', null, array( 106 => $notes ) );
ok( ! is_wp_error( $r ) && $notes === $GLOBALS['t_meta_store'][106] && array() === $GLOBALS['t_add_meta'], 'bulk status: ... and where WordPress was refused before it deleted anything, nothing is added twice' );
$many = array();
for ( $i = 4000; $i < 4502; $i++ ) { $many[ $i ] = $pub; }
$r = $bulk_run( $many, array(), 'draft' );
ok( ! is_wp_error( $r ) && 500 === $r['updated'] && 2 === $r['over_limit'] && 'draft' === $GLOBALS['t_bulk_posts'][4499]['post_status'] && 'publish' === $GLOBALS['t_bulk_posts'][4500]['post_status'] && 'publish' === $GLOBALS['t_bulk_posts'][4501]['post_status'], 'bulk status: at most 500 ids a call — over_limit counts the ones beyond, which are not touched' );
$r = $bulk_run( array( 104 => $pub ), array(), 'draft' );
ok( ! is_wp_error( $r ) && 0 === $r['over_limit'] && 0 === $r['forbidden_omitted'], 'bulk status: ... and says 0 where there are none' );

echo "the scan: by id, refused rows count for nothing\n";
reset_log();
$GLOBALS['t_post_wp_error'] = array();
$r  = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'posts' ), 'apply' => true ) );
$pr = $r['tables']['posts'];
$pq = query_with( 'post_excerpt FROM wp_posts' );
ok( false !== strpos( $pq, "WHERE post_status NOT IN ('auto-draft','trash') AND post_type <> 'revision' AND post_type NOT IN (%s,%s,%s,%s,%s,%s,%s) AND ID > %d AND ID <= %d AND (post_content LIKE %s OR post_title LIKE %s OR post_excerpt LIKE %s) ORDER BY ID ASC LIMIT 200" ), 'the posts query keeps the internal types out and walks an id window with the LIKE inside, in batches of 200 rows by id — no limit by match count, no type-level rights shortcut' );
ok( '' !== query_with( 'SELECT MAX(ID) FROM wp_posts' ), 'the end of the table is read once, independently of any value' );
$pa = args_of( 'post_excerpt FROM wp_posts' );
ok( is_array( $pa ) && AB_MCP_Tools_Search_Replace::INTERNAL_POST_TYPES === array_slice( $pa, 0, 7 ) && array( 0, 100 ) === array_slice( $pa, 7, 2 ) && all_like_foo( array_slice( $pa, 9 ) ), 'arguments: the internal types kept out, the window bounds (the table ends at id 100, so does the window), then the LIKE patterns' );
ok( 1 === $pr['matched'] && 1 === $pr['changed'] && 8 === $pr['samples'][0]['id'] && 'post' === $pr['samples'][0]['type'], 'of six matching rows only post 8 counts: the page, the uneditable post, the unreadable post, the revision with a refused parent and the internal custom_css row (kept out in SQL, refused again per row) are passed over' );
ok( 1 === count( $GLOBALS['t_post'] ) && 8 === (int) $GLOBALS['t_post'][0]['ID'] && 'bar' === $GLOBALS['t_post'][0]['post_content'], 'only post 8 is written' );
ok( array( false ) === $GLOBALS['t_post_wp_error'], 'written without $wp_error — with it, a post whose page template is gone would be written and still counted as failed (4.5.11)' );
// Without the page-builder parts — a core without AB_MCP_BUILDER_API, as
// this test runs — scope text replaces every post as before (principle 9).
ok( 'text' === $r['scope'] && 'text' === $r['flags']['scope'] && ! isset( $r['tables']['builders'] ) && 0 === $r['locked_matches'] && null === $r['undo_token'] && ! isset( $r['builder_pages_without_writer'] ) && array() === $r['warnings'], 'scope text is the default and, without the page-builder parts, has no builders scan, no locked matches, no undo point and nothing to warn about' );
reset_log();
$rr = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'posts' ), 'apply' => true, 'scope' => 'raw' ) );
ok( 'raw' === $rr['scope'] && 1 === $rr['tables']['posts']['changed'] && null === $rr['undo_token'] && false !== strpos( $rr['warnings'][0], 'scope raw' ), 'scope raw writes the same way, with its warning; without page-builder parts there is no page-builder page to keep an undo point for' );
$bad = $SR( array( 'search' => 'foo', 'scope' => 'everything' ) );
ok( is_wp_error( $bad ) && 'ab_mcp_bad_scope' === $bad->get_error_code(), 'an unknown scope is refused before anything is read' );
reset_log();
$GLOBALS['wpdb']->posts_only_8 = true;
$bs  = chr( 92 );
$rsl = $SR( array( 'search' => 'foo', 'replace' => 'a' . $bs . 'b', 'tables' => array( 'posts' ), 'apply' => true ) );
ok( 1 === count( $GLOBALS['t_post'] ) && 'a' . $bs . $bs . 'b' === $GLOBALS['t_post'][0]['post_content'], 'the post goes to wp_update_post() slashed, as that function expects: it unslashes what it gets, so without this every backslash of a Gutenberg block attribute would be gone after the write' );
reset_log();
$GLOBALS['wpdb']->batches = true;
$rb = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'posts' ), 'limit' => 1000 ) );
$second = null;
foreach ( $GLOBALS['wpdb']->prepares as $pr ) { if ( false !== strpos( $pr[0], 'post_excerpt FROM wp_posts' ) ) { $second = $pr[2]; } }
// 205 ids, of which the stub refuses 9, 10, 12 and 14.
ok( 201 === $rb['tables']['posts']['matched'] && 2 === $GLOBALS['wpdb']->batch_calls && is_array( $second ) && array( 200, 100 ) === array_slice( $second, 7, 2 ), 'a window with more rows than one batch is read to the end: 200 rows, then 5 more after id 200; a short batch ends the window' );
reset_log();
$GLOBALS['wpdb']->dupes = true;
$GLOBALS['t_shared'] = array(); // hier geht es um den Cursor, nicht um die Sperre
$rd = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'terms' ), 'limit' => 1000 ) );
$nq = null;
foreach ( $GLOBALS['wpdb']->prepares as $pr ) { if ( false !== strpos( $pr[0], 'tt.taxonomy, tt.term_taxonomy_id FROM wp_terms t ' ) ) { $nq = $pr; } }
ok( is_array( $nq ) && false !== strpos( $nq[0], '(t.term_id > %d OR (t.term_id = %d AND tt.term_taxonomy_id > %d)) AND t.term_id <= %d' ) && false !== strpos( $nq[0], 'ORDER BY t.term_id ASC, tt.term_taxonomy_id ASC LIMIT 200' ) && array( 8, 8, 200, 100 ) === array_slice( $nq[2], 3, 4 ), 'JOIN scans walk by (id, term_taxonomy_id): the second names batch starts after row (8, 200)' );
ok( 2 === $GLOBALS['wpdb']->dupe_calls && 1 === count( array_filter( $rd['tables']['terms']['samples'], static function ( $s ) { return 8 === $s['term_id'] && 'name' === $s['field']; } ) ), 'the 201st row of the same term — the first one allowed — is reached and counts' );
ok( ! array_key_exists( 'excluded_post_types', $r ) && ! array_key_exists( 'skipped_forbidden', $pr ) && ! array_key_exists( 'skipped_forbidden_count', $pr ), 'no type-level verdict is reported — the decision is per row — and refused rows leave no trace' );
reset_log();
$r1 = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'posts' ), 'limit' => 1 ) );
reset_log();
$GLOBALS['wpdb']->posts_only_8 = true;
$r2 = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'posts' ), 'limit' => 1 ) );
ok( 1 === $r1['tables']['posts']['matched'] && 1 === $r2['tables']['posts']['matched'] && true === $r1['tables']['posts']['truncated'] && true === $r2['tables']['posts']['truncated'] && array( 'posts' => 8 ) === $r1['next'] && $r1['next'] === $r2['next'], 'with limit=1 the result is the same whether or not refused rows match: truncated means "stopped at the limit", not "more rows matched", and next names the row handled last — the same either way' );
reset_log();
$r3 = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'posts' ), 'limit' => 1, 'after' => array( 'posts' => 8 ) ) );
$pa = args_of( 'post_excerpt FROM wp_posts' );
ok( is_array( $pa ) && array( 8, 100 ) === array_slice( $pa, 7, 2 ), 'continued with that next, the first window starts behind row 8' );
reset_log();
$r  = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'posts' ), 'regex' => true ) );
$pq = query_with( 'post_excerpt FROM wp_posts' );
ok( false === strpos( $pq, 'LIKE' ) && false !== strpos( $pq, 'AND ID > %d AND ID <= %d ORDER BY ID ASC' ) && 1 === $r['tables']['posts']['matched'], 'regex mode: the same walk without the LIKE, the same policy' );
reset_log();
$GLOBALS['wpdb']->huge = true;
$r = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'posts' ) ) );
$hw = $GLOBALS['wpdb']->huge_windows;
ok( 500 === count( $hw ) && array( 0, 10000 ) === $hw[0] && array( 4990000, 5000000 ) === $hw[499] && 0 === $r['tables']['posts']['matched'] && true === $r['tables']['posts']['truncated'] && array( 'posts' => 5000000 ) === $r['next'] && array( 'posts' => 5000000 ) === $r['tables']['posts']['next'] && 1 === count( array_filter( $r['tables']['posts']['notes'], static function ( $n ) { return false !== strpos( $n, 'budget of 5000000 ids per scan is used up' ); } ) ), 'a table of ten million ids: 500 windows of 10 000 — the span never grows — end at id 5 000 000, the call is truncated by its budget and next names that id' );
reset_log();
$GLOBALS['wpdb']->huge = true;
$r = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'posts' ), 'after' => array( 'posts' => 5000000 ) ) );
$hw = $GLOBALS['wpdb']->huge_windows;
ok( 500 === count( $hw ) && array( 5000000, 5010000 ) === $hw[0] && array( 9990000, 10000000 ) === $hw[499] && 1 === $r['tables']['posts']['matched'] && 9999999 === $r['tables']['posts']['samples'][0]['id'] && false === $r['tables']['posts']['truncated'] && array( 'posts' => 10000000 ) === $r['next'] && array( 'posts' => 5000000 ) === $r['flags']['after'], 'handed back as after, the next call continues at id 5 000 000, reaches the highest id, finds the row near the end, and next now stands at the end of the table' );
reset_log();
$GLOBALS['wpdb']->huge = true;
$r = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'posts' ), 'regex' => true, 'after' => array( 'posts' => 5000000 ) ) );
$hw = $GLOBALS['wpdb']->huge_windows;
ok( 50 === count( $hw ) && array( 5000000, 5010000 ) === $hw[0] && array( 5490000, 5500000 ) === $hw[49] && 0 === $r['tables']['posts']['matched'] && true === $r['tables']['posts']['truncated'] && array( 'posts' => 5500000 ) === $r['next'] && 1 === count( array_filter( $r['tables']['posts']['notes'], static function ( $n ) { return false !== strpos( $n, 'budget of 500000 ids per scan is used up' ); } ) ), 'regex mode walks a tenth of the ids per call — every row of the span is read and tested in PHP, without a LIKE to thin it out — and next says where to carry on' );
reset_log();
$GLOBALS['wpdb']->huge = true;
$r = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'posts' ), 'after' => array( 'posts' => 10000000 ) ) );
ok( array() === $GLOBALS['wpdb']->huge_windows && 0 === $r['tables']['posts']['matched'] && false === $r['tables']['posts']['truncated'] && array( 'posts' => 10000000 ) === $r['next'], 'an after at the end of the table reads nothing and is not truncated' );
foreach ( array( array( 'bogus' => 1 ), array( 'posts' => -1 ), array( 'posts' => 'x' ), array( 'posts' => true ), array( 'posts' => array( 1 ) ), 'x', array( 5 ) ) as $bad ) {
	$r = $SR( array( 'search' => 'foo', 'after' => $bad ) );
	ok( is_wp_error( $r ) && 'ab_mcp_bad_after' === $r->code && false !== strpos( $r->message, 'term_descriptions' ), 'after that is not a next object is refused: ' . json_encode( $bad ) );
}

echo "next records every scan; a continued call reads nothing twice\n";
reset_log();
$r = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'posts', 'postmeta', 'options', 'terms' ), 'limit' => 1000, 'apply' => true ) );
ok( array( 'posts' => 100, 'postmeta' => 100, 'options' => 100, 'term_names' => 100, 'term_descriptions' => 100, 'termmeta' => 100 ) === $r['next'] && false === $r['tables']['posts']['truncated'] && false === $r['tables']['terms']['truncated'], 'a call that finishes every scan records each of the six at the end of its table (MAX id 100), not only the ones that stopped early ' );
$next = $r['next'];
reset_log();
$r = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'posts', 'postmeta', 'options', 'terms' ), 'limit' => 1000, 'apply' => true, 'after' => $next ) );
$batch_queries = count( array_filter( $GLOBALS['wpdb']->queries, static function ( $q ) { return false !== strpos( $q, 'LIMIT 200' ); } ) );
ok( 0 === $batch_queries && 0 === $r['totals']['matched'] && $next === $r['next'] && array() === $GLOBALS['t_post'] && array() === $GLOBALS['t_opt'] && array() === $GLOBALS['wpdb']->updates, 'handed back as after, the next call reads no row and writes nothing — a replacement that contains its own search text would otherwise be applied again — and next stands where it stood' );
reset_log();
$r = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'posts', 'postmeta' ), 'limit' => 1 ) );
ok( array( 'posts' => 8, 'postmeta' => 31 ) === $r['next'] && true === $r['tables']['posts']['truncated'] && true === $r['tables']['postmeta']['truncated'], 'two scans stopped at their limit: next names the row each handled last' );
reset_log();
$r = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'posts', 'postmeta' ), 'type' => 'attachment' ) );
ok( array( 'attachment', 0, 100 ) === array_slice( args_of( 'post_excerpt FROM wp_posts' ), 7, 3 ) && AB_MCP_Tools_Search_Replace::INTERNAL_POST_TYPES === array_slice( args_of( 'p.post_parent FROM wp_postmeta pm' ), 0, 7 ) && false === strpos( query_with( 'p.post_parent FROM wp_postmeta pm' ), 'p.post_type = %s' ), 'type narrows the posts scan only — postmeta keeps just the internal types out, as the schema says and as before 4.5.5' );

echo "terms: dry run\n";
reset_log();
$r  = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'terms' ) ) );
$tr = $r['tables']['terms'];
ok( 8 === $tr['matched'] && 0 === $tr['changed'], "matches six names, one description, one meta = 8 (got {$tr['matched']}), changes nothing — the term in two taxonomies, the one without a slug and the one WordPress can no longer read are passed over before their value is read, so they count for nothing" );
ok( false !== strpos( query_with( 'tt.term_taxonomy_id FROM wp_terms t ' ), 'SELECT t.term_id, t.name, tt.taxonomy, tt.term_taxonomy_id FROM wp_terms t INNER JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id WHERE 1=1 AND tt.taxonomy NOT IN (%s,%s,%s) AND (t.term_id > %d OR (t.term_id = %d AND tt.term_taxonomy_id > %d)) AND t.term_id <= %d AND (t.name LIKE %s) ORDER BY t.term_id ASC, tt.term_taxonomy_id ASC' ), 'the names query reads the four columns the scan needs, excludes the internal taxonomies and walks a term-id window with a unique (id, term_taxonomy_id) cursor' );
ok( false !== strpos( query_with( 'tt.description FROM wp_term_taxonomy tt' ), 'tt.taxonomy NOT IN (%s,%s,%s) AND tt.term_taxonomy_id > %d AND tt.term_taxonomy_id <= %d' ), 'so does the descriptions query' );
$mq = query_with( 'tt.term_taxonomy_id FROM wp_termmeta tm' );
ok( false !== strpos( $mq, 'tt.taxonomy NOT IN (%s,%s,%s)' ) && 24 === substr_count( $mq, norm_of( 'tm.meta_key' ) ) && false === strpos( $mq, norm_of( 'tm.meta_value' ) ) && 23 === substr_count( $mq, 'NOT LIKE %s' ) && false !== strpos( $mq, 'NOT IN (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)' ) && false !== strpos( $mq, '(tm.meta_id > %d OR (tm.meta_id = %d AND tt.term_taxonomy_id > %d)) AND tm.meta_id <= %d' ), 'the termmeta query excludes internal taxonomies and credential-like keys and walks a meta-id window with a unique cursor' );
$ta = args_of( 'tt.term_taxonomy_id FROM wp_termmeta tm' );
ok( is_array( $ta ) && array_slice( $ta, 0, 3 ) === AB_MCP_Tools_Search_Replace::INTERNAL_TAXONOMIES && array_slice( $ta, 3, 16 ) === AB_MCP_Tools_Search_Replace::SENSITIVE_META_EXACT && '%api\_key%' === $ta[19] && array( 0, 0, PHP_INT_MAX, 100 ) === array_slice( $ta, 42, 4 ) && all_like_foo( array_slice( $ta, 46 ) ), 'termmeta arguments: taxonomies, exact names, needles, the composite window bounds, then the LIKE patterns' );
ok( array() === $GLOBALS['t_term_writes'] && array() === $GLOBALS['t_meta'] && array() === $GLOBALS['wpdb']->updates && 0 === $tr['notes_omitted'], 'dry run: wp_update_term() is not called and nothing is written, and no note was left out' );
ok( array( 'category', 'category', 'category', 'brand', 'category', 'category', 'category', 'category' ) === array_column( $tr['samples'], 'taxonomy' ), 'every sample names its taxonomy; the wp_theme rows and the non-public product_cat term never show, the public brand term does, and the shared term counts under its allowed taxonomy although the refused one came first' );

echo "terms: apply\n";
reset_log();
$r  = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'terms' ), 'apply' => true ) );
$tr = $r['tables']['terms'];
$w  = $GLOBALS['t_term_writes'];
ok( 7 === $tr['changed'], "seven rows written (got {$tr['changed']}): five names, one description, one meta — term 8 sits in two taxonomies and is left to the term screen" );
ok( array() === $GLOBALS['wpdb']->updates, 'no column is written behind WordPress: names and descriptions go through wp_update_term(), term meta through update_metadata_by_mid()' );
ok( 7 === count( $w ) && array( 1, 'category', array( 'name' => 'bar bar' ) ) === $w[0] && array( 3, 'category', array( 'name' => '<b>bar</b>' ) ) === $w[1] && array( 7, 'brand', array( 'name' => 'bar brand' ) ) === $w[3] && array( 9, 'category', array( 'name' => 'bar' ) ) === $w[4] && ! in_array( 8, array_column( $w, 0 ), true ), 'each allowed term is handed to wp_update_term() once with its taxonomy and the replaced name — raw, KSES and the other pre_term_ filters are that function\'s job, not this tool\'s; term 1 goes once although it sits in two taxonomies of its own, and term 8, which sits in two, is not handed over at all' );
ok( array( 4, 'category', array( 'name' => "O\\'Reilly bar" ) ) === $w[2], 'the value is handed over slashed, as wp_update_term() expects it (expected_slashed) and unslashes again for the write — a lost backslash would otherwise eat the apostrophe' );
ok( array( 1, 'category', array( 'description' => 'a bar' ) ) === $w[6], 'a description goes the same way, under the taxonomy of the row that matched; the product_cat description is not written' );
ok( array( 11, 'category', array( 'name' => 'bar child' ) ) === $w[5] && 1 === count( array_filter( $tr['notes'], static function ( $n ) { return false !== strpos( $n, 'Term 11: WordPress refused this update (missing_parent' ); } ) ), 'what WordPress refuses is reported as its own refusal, with its error code, and nothing else is tried' );
ok( ! in_array( 10, array_column( $w, 0 ), true ) && array() === array_filter( $tr['notes'], static function ( $n ) { return false !== strpos( $n, 'Term 10' ); } ) && ! in_array( 10, array_column( $tr['samples'], 'term_id' ), true ), 'a term without a slug is passed over before its value is read: WordPress would give it a slug made from the new name, and a row refused after the value had been read would have counted as a hit and used up a slot of the limit' );
ok( ! in_array( 12, array_column( $w, 0 ), true ) && array() === array_filter( $tr['notes'], static function ( $n ) { return false !== strpos( $n, 'Term 12' ); } ), 'a row whose term WordPress can no longer read is passed over the same way, without a trace' );
ok( array( array( 'term', 21, 'bar' ) ) === $GLOBALS['t_meta'] && array() === $GLOBALS['t_term_meta'] && array() === array_filter( $tr['notes'], static function ( $n ) { return false !== strpos( $n, 'Term 8' ); } ), 'only the plain termmeta row of an editable term is written, by meta id — the one row the scan read, not by term and key, which WordPress would widen to every row of that key when the previous value is empty api_key, the wp_theme row, the uneditable term, the _hidden key the filter unprotected, the key edit_term_meta refuses, the brand term without assign_terms and the term in two taxonomies (which WordPress refuses meta for) are not, the last of them before its value is read' );
ok( array( array( 'category', array( 1 ) ) ) === $GLOBALS['t_cache'], 'the term cache is cleaned per taxonomy for the meta writes only — wp_update_term() cleans up after the name and description writes itself' );
reset_log();
$re = $SR( array( 'search' => 'foo', 'replace' => '', 'tables' => array( 'terms' ), 'apply' => true ) );
$empty = array_values( array_filter( $GLOBALS['t_term_writes'], static function ( $c ) { return 9 === $c[0]; } ) );
ok( 1 === count( $empty ) && array( 'name' => '' ) === $empty[0][2] && 1 === count( array_filter( $re['tables']['terms']['notes'], static function ( $n ) { return false !== strpos( $n, 'Term 9: WordPress refused this update (empty_term_name' ); } ) ) && $re['tables']['terms']['matched'] === $tr['matched'], 'a replacement that leaves a name empty is handed over all the same and refused by wp_update_term(), as it refuses the term screen; the note says so and the row still counts' );

// A replacement that changes nothing: the column already reads that way.
reset_log();
$rn = $SR( array( 'search' => 'foo', 'replace' => 'foo', 'tables' => array( 'terms' ), 'apply' => true ) );
ok( 8 === $rn['tables']['terms']['matched'] && 0 === $rn['tables']['terms']['changed'] && array() === $GLOBALS['t_term_writes'] && array() === $GLOBALS['t_meta'] && 1 === count( $rn['tables']['terms']['notes'] ), 'a row whose value already reads as the replacement counts as a hit and is not written at all — no call, no refusal to explain, and no note beyond the standing one' );
// A term that still sits in more than one taxonomy is left alone: writing it
// would make WordPress split it first.
reset_log();
$GLOBALS['t_shared'] = array( 1 => true, 8 => true );
$rsp = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'terms' ), 'apply' => true ) );
ok( ! in_array( 1, array_column( $GLOBALS['t_term_writes'], 0 ), true ) && ! in_array( 8, array_column( $GLOBALS['t_term_writes'], 0 ), true ) && array() === array_filter( $rsp['tables']['terms']['notes'], static function ( $n ) { return false !== strpos( $n, 'Term 1:' ) || false !== strpos( $n, 'Term 8:' ); } ) && ! in_array( 1, array_column( $rsp['tables']['terms']['samples'], 'term_id' ), true ), 'a term in more than one taxonomy is never handed to wp_update_term(), for no field: that function would split it first, give one taxonomy an id beyond the end of this run and leave the others with the old value. It is passed over before its value is read, so it counts for nothing and leaves no note' );
$GLOBALS['t_shared'] = array( 8 => true );
// A value with a backslash: WordPress unslashes what it is given, so it has to
// be handed over slashed or the backslash is gone.
reset_log();
$bs2 = chr( 92 );
$rbs = $SR( array( 'search' => 'foo', 'replace' => 'a' . $bs2 . 'b', 'tables' => array( 'terms' ), 'apply' => true ) );
$meta_bs = array_values( array_filter( $GLOBALS['t_meta'], static function ( $c ) { return 'term' === $c[0]; } ) );
ok( 1 === count( $meta_bs ) && 'a' . $bs2 . 'b' === $meta_bs[0][2], 'a backslash survives the write: update_metadata_by_mid() does not unslash, so the value goes in raw' );

// A plugin that takes over or refuses the write through the filter
// update_term_meta() would have asked.
reset_log();
$GLOBALS['t_filters']['update_term_metadata'] = static function ( $check, $tid, $key, $value, $prev ) { return 1 === (int) $tid ? true : $check; };
$rtf = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'terms' ), 'apply' => true ) );
ok( array() === $GLOBALS['t_meta'] && 1 === count( array_filter( $rtf['tables']['terms']['notes'], static function ( $n ) { return false !== strpos( $n, 'a plugin took over or refused this meta write' ); } ) ), 'the short-circuit filter update_term_meta() would have asked is asked here, because writing by meta id skips it: a plugin that answers it stops the write, with a note' );
$GLOBALS['t_filters'] = array();

// The write goes through WordPress' own function, which is what makes the
// update_term_metadata filter, the single sanitising pass and the refusal for a
// term in several taxonomies happen at all.
reset_log();
$rtm = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'terms' ), 'apply' => true ) );
ok( array( array( 'term', 21, 'bar' ) ) === $GLOBALS['t_meta'] && array() === $GLOBALS['t_term_meta'], 'term meta is written by meta id: update_term_meta() picks its row by the previous value, and WordPress drops that condition whenever the value is empty — a stored "0" would take every row of that term and key with it' );

// A plugin that serves its own name through get_term, as a translation plugin
// does: the read-back must not judge the write by that view.
reset_log();
// It serves the name the row had before the write, whatever is stored now —
// that is what makes a raw read-back necessary.
$GLOBALS['t_filters']['get_term'] = static function ( $term, $tax ) { $was = array( 1 => 'foo bar', 3 => '<b>foo</b>', 4 => "O'Reilly foo", 7 => 'foo brand', 8 => 'foo shared', 9 => 'foo' ); if ( isset( $was[ (int) $term->term_id ] ) ) { $term->name = $was[ (int) $term->term_id ]; } return $term; };
$rgt = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'terms' ), 'apply' => true ) );
ok( 7 === $rgt['tables']['terms']['changed'] && 'bar bar' === $GLOBALS['t_terms'][1]['name'] && 0 === count( array_filter( $rgt['tables']['terms']['notes'], static function ( $n ) { return false !== strpos( $n, 'did not change' ); } ) ), 'a filter that serves a different name through get_term does not make a write that worked look like one that did nothing: the read-back reads the column, raw, the way the scan read it' );
$GLOBALS['t_filters'] = array();

// A get_term filter that serves its own slug must not invent or hide a change.
reset_log();
$GLOBALS['t_filters']['get_term'] = static function ( $term, $tax ) { $term->slug = 'served-by-a-filter'; return $term; };
$rgs = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'terms' ), 'apply' => true ) );
ok( array() === $rgs['tables']['terms']['slug_changes'], 'a slug served by a get_term filter is not mistaken for a change: both sides of the comparison are read raw from the column' );
$GLOBALS['t_filters'] = array();
// A value the filters shorten is their ordinary work, not a cut column.
reset_log();
$rtr = $SR( array( 'search' => 'foo', 'replace' => 'bar ', 'tables' => array( 'terms' ), 'apply' => true ) );
ok( 'bar' === $GLOBALS['t_terms'][9]['name'] && 0 === count( array_filter( $rtr['tables']['terms']['notes'], static function ( $n ) { return false !== strpos( $n, 'cut this name' ); } ) ), 'a name the term filters shortened is not reported as a cut column: only a value that fills the column exactly while more was asked for was cut there' );

// A sanitiser that turns the replacement back into what is stored: nothing to
// write, and the update actions must not fire for it.
reset_log();
$GLOBALS['t_meta_sanitize_back'] = true;
$rsb = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'terms' ), 'apply' => true ) );
ok( array() === $GLOBALS['t_meta'], 'a value the sanitiser turns back into the stored one is not written: update_metadata() compares the sanitised value, and so does this' );
$GLOBALS['t_meta_sanitize_back'] = false;
// A name of 210 umlauts: 420 bytes, 210 characters. The column holds 200
// characters, so it is cut — and the cut is measured in characters.
reset_log();
$ru = $SR( array( 'search' => 'foo', 'replace' => str_repeat( 'ä', 210 ), 'tables' => array( 'terms' ), 'apply' => true ) );
ok( 200 === mb_strlen( $GLOBALS['t_terms'][9]['name'], 'UTF-8' ) && count( array_filter( $ru['tables']['terms']['notes'], static function ( $n ) { return false !== strpos( $n, 'cut this name to the 200 characters' ); } ) ) >= 1, 'the cut is counted in characters, not bytes: 210 umlauts are 420 bytes, and a byte count would not recognise the full column' );

// A replacement too long for the name column: core writes, the database drops
// it, core reports success all the same.
reset_log();
$rl = $SR( array( 'search' => 'foo', 'replace' => str_repeat( 'x', 210 ), 'tables' => array( 'terms' ), 'apply' => true ) );
$tl = $rl['tables']['terms'];
ok( 'a ' . str_repeat( 'x', 210 ) === $GLOBALS['t_terms'][1]['description'] && 200 === strlen( $GLOBALS['t_terms'][1]['name'] ) && count( array_filter( $tl['notes'], static function ( $n ) { return false !== strpos( $n, 'the database cut this name to the 200 characters the column holds' ); } ) ) >= 1 && 7 === count( $tl['notes'] ) && 0 === $tl['notes_omitted'], 'a name longer than the column takes is cut by the database without a word — WordPress runs without the strict modes — so the cut is reported against the term; the description (a long-text column) takes the whole value, and the notes still fit, so none is counted away' );
// A filter that changes the slug along with the name.
reset_log();
$GLOBALS['t_slug_filter'] = array( 3 => 'changed-by-a-filter' );
$rs = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'terms' ), 'apply' => true ) );
ok( 7 === $rs['tables']['terms']['changed'] && 'changed-by-a-filter' === $GLOBALS['t_terms'][3]['slug'] && array( array( 'term_id' => 3, 'from' => 'foo-3', 'to' => 'changed-by-a-filter', 'field' => 'name' ) ) === $rs['tables']['terms']['slug_changes'] && array() === $r['tables']['terms']['slug_changes'] && 0 === $rs['tables']['terms']['slug_changes_omitted'], 'a slug a WordPress filter changes along the way stands — this tool never asks for one — and is listed in slug_changes with the term and both slugs, not in the capped notes; the write itself still counts, and a run where no filter touches a slug lists none' );
$GLOBALS['t_slug_filter'] = array();

echo "options and postmeta\n";
reset_log();
$r  = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'options', 'postmeta' ), 'apply' => true ) );
$or = $r['tables']['options'];
$oq = query_with( 'option_value FROM wp_options' );
ok( 0 === substr_count( $oq, 'option_name NOT LIKE %s' ) && 6 === substr_count( $oq, 'NOT LIKE %s' ) && false !== strpos( $oq, "'Z', 'z') <> %s" ) && false !== strpos( $oq, 'NOT IN (%s,%s)' ) && 9 === substr_count( $oq, norm_of( 'option_name' ) ) && false === strpos( $oq, norm_of( 'option_value' ) ) && false !== strpos( $oq, 'NOT IN (%s,%s,%s,%s,%s,%s) AND option_id > %d AND option_id <= %d AND (option_value LIKE %s' ), 'transients, plugin rows, widget rows, the guard\'s names and the option of every registered widget are all excluded against the normalised name (a stored " widget_text" would otherwise be written as widget_text), and the scan walks an option-id window' );
$oa = args_of( 'option_value FROM wp_options' );
ok( is_array( $oa ) && array( 'siteurl', 'mailserver_pass', 'sidebars_widgets', 'widget_text', 'widget_block', 'widget_custom', 'my_widget_store', 'widget_recent_entries', 0, 100 ) === array_slice( $oa, 7, 10 ), 'options arguments: after the guard\'s exact names come the widget option names — widget_<id_base>, option_name and alt_option_name of every registered widget — then the window bounds' );
ok( ! array_key_exists( 'skipped_protected', $or ) && ! array_key_exists( 'skipped_widgets', $or ) && 2 === $or['matched'] && array( array( 'blogdescription', 'bar' ), array( 'WPLANG', 'bar' ) ) === $GLOBALS['t_opt'], 'of thirteen matching rows only blogdescription and WPLANG count and are written: the phantom " widget_text" and "Widget_block" rows, the combining-accent look-alike the database resolves to the real widget row, the deprecated blacklist_keys, the row under a registered widget\'s own option_name (My_Widget_Store) and the look-alike BlogName of a name sanitize_option() treats by name are all refused in PHP; WPLANG is such a name stored byte for byte and goes through' );
ok( 0 === count( array_filter( $GLOBALS['wpdb']->prepares, static function ( $pr ) { return false !== strpos( $pr[0], 'SELECT option_id FROM wp_options' ) && isset( $pr[2][0] ) && ' widget_text' === $pr[2][0]; } ) ) && 4 === count( array_filter( $GLOBALS['wpdb']->queries, static function ( $q ) { return false !== strpos( $q, 'SELECT option_id FROM wp_options WHERE option_name = %s' ); } ) ), 'the name of a row about to count is resolved by the database (blogdescription, the widget look-alike, BlogName and WPLANG; the deprecated name and the widget\'s option_name need no query — they are refused before)' );
ok( 4 === count( $GLOBALS['wpdb']->guard_lists ) && array( 'sidebars_widgets', 'widget_text', 'widget_block', 'widget_custom', 'my_widget_store', 'widget_recent_entries', 'blacklist_keys', 'comment_whitelist', 'siteurl', 'mailserver_pass' ) === $GLOBALS['wpdb']->guard_lists[0], 'and asked whether the database reads it as a registered widget option (option_name and alt_option_name included), the sidebar map, a name WordPress redirects away from (only the old one: the name it redirects to is written under its own spelling) or a guarded exact name — the look-alike is, so it is left alone although it resolves to itself' );
ok( 4 === count( $GLOBALS['wpdb']->canon_lists ) && array_merge( AB_MCP_Tools_Search_Replace::SANITIZED_OPTION_NAMES, array( 'comment_previously_approved', 'my_setting', 'other_setting', 'page_capability_reading', 'BlogName', 'reg_setting' ) ) === $GLOBALS['wpdb']->canon_lists[0] && 54 === count( AB_MCP_Tools_Search_Replace::SANITIZED_OPTION_NAMES ) && in_array( 'blogname', AB_MCP_Tools_Search_Replace::SANITIZED_OPTION_NAMES, true ), 'and, in the same query, whether it reads it as a name WordPress sanitises or filters by name — the 54 sanitize_option() cases, the names WordPress redirects deprecated ones to, the names behind the option hooks (a bare hook prefix and unrelated hooks yield nothing), the registered settings — while the bytes differ: BlogName is such a look-alike of blogname and stays refused although a hook registers the spelling option_BlogName too, WPLANG carries its name byte for byte and is not' );
$cq = query_with( 'SELECT COUNT(*) FROM wp_options WHERE option_id = %d AND (option_name IN (' );
ok( false !== strpos( $cq, ') OR (option_name = %s AND CAST(option_name AS BINARY) <> CAST(%s AS BINARY)) OR (option_name = %s AND' ) && 60 === substr_count( $cq, '(option_name = %s AND CAST(option_name AS BINARY) <> CAST(%s AS BINARY))' ) && false === strpos( $cq, 'NOT IN (' . str_repeat( '%s,', 58 ) ) && 1 + 10 + 2 * 60 === substr_count( $cq, '%' ), 'one prepared query asks both questions: the guarded names under the collation, and pair by pair whether the row equals a canonical name under the collation but not byte for byte — never as a set, where a second registered spelling could vouch for a look-alike' );
$mr = $r['tables']['postmeta'];
$pq = query_with( 'p.post_parent FROM wp_postmeta pm' );
ok( false !== strpos( $pq, 'p.post_type NOT IN (%s,%s,%s,%s,%s,%s,%s)' ) && 25 === substr_count( $pq, norm_of( 'pm.meta_key' ) ) && false === strpos( $pq, norm_of( 'pm.meta_value' ) ) && false !== strpos( $pq, norm_of( 'pm.meta_key' ) . ' NOT IN (%s,%s,%s,%s) AND pm.meta_id > %d' ) && 23 === substr_count( $pq, 'NOT LIKE %s' ) && false !== strpos( $pq, 'NOT IN (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)' ) && false !== strpos( $pq, 'AND pm.meta_id > %d AND pm.meta_id <= %d AND (pm.meta_value LIKE %s' ), 'postmeta: internal types and credential-like keys kept out in SQL — each exclusion wraps the KEY column, never the value column, which would put the oracle back — and walked in a meta-id window' );
$pm = args_of( 'p.post_parent FROM wp_postmeta pm' );
ok( is_array( $pm ) && AB_MCP_Tools_Search_Replace::INTERNAL_POST_TYPES === array_slice( $pm, 0, 7 ) && array_slice( $pm, 7, 16 ) === AB_MCP_Tools_Search_Replace::SENSITIVE_META_EXACT && '%api\_key%' === $pm[23] && AB_MCP_Tools_Search_Replace::META_KEYS_WITH_OWN_WRITER === array_slice( $pm, 46, 4 ) && array( 0, 100 ) === array_slice( $pm, 50, 2 ) && all_like_foo( array_slice( $pm, 52 ) ), 'postmeta arguments: internal types, exact names, needles, the four keys with a writer of their own, the window bounds, then the LIKE patterns' );
ok( ! array_key_exists( 'skipped_sensitive', $mr ) && ! array_key_exists( 'skipped_forbidden', $mr ) && 3 === $mr['matched'] && array( 'post', 'post', 'post' ) === array_column( $mr['samples'], 'type' ) && 1 === $r['skipped_object_values'] && 1 === $r['skipped_unchecked_values'] && array( array( 'post', 31, 'bar' ), array( 'post', 38, '[{"t":"bar"}]' ), array( 'post', 41, 'bar Datei%20Name' ) ) === $GLOBALS['t_meta'], 'of ten matching rows the plain key, the allow-listed builder key and the alt text of the editable post are written — raw, update_metadata_by_mid() does not unslash: the credential-like key, the page, the object row, the _vault key a filter unprotected, the key edit_post_meta refuses, the revision with a refused parent and _wp_attached_file (WordPress writes it through update_attached_file()) are passed over, unlisted; each sample names the post type, and the row nobody could walk to the end is counted apart from the row that really carries an object' );
ok( 1 === $r['elementor']['posts_touched'] && true === $r['elementor']['css_cache_cleared'] && true === $r['elementor']['element_cache_cleared'] && array( array( 8, '_elementor_css' ), array( 8, '_elementor_element_cache' ), array( 8, '_elementor_markdown_cache' ) ) === $GLOBALS['t_del_meta'] && array( '_elementor_global_css' ) === $GLOBALS['t_del_opt'], 'the Elementor payload counts and its CSS cache, element cache (the rendered HTML, kept for elementor_element_cache_ttl hours, 24 by default) and Markdown cache are reset — the allow-list rule is the direct reader\'s (edit_post for an unregistered protected key)' );
// The CSS files of atomic elements: Elementor keeps them valid until its own
// action says otherwise, for this post only, under the key of its local styles.
ok( array( array( 'elementor/atomic-widgets/styles/clear', array( array( 'local', 8 ) ) ) ) === $GLOBALS['t_actions'], 'the CSS files of Elementor 4 atomic elements are invalidated for the rewritten post, through the action Elementor\'s own save fires' );
ok( 1 === $r['skipped_object_values'], 'the row holding an object is counted, not rewritten' );
// Without unfiltered_html a builder payload is not written: Elementor's own
// save path would have run it through KSES.
reset_log();
$GLOBALS['t_no_unfiltered'] = true;
$ru = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'postmeta' ), 'apply' => true ) );
ok( 2 === $ru['tables']['postmeta']['matched'] && array( array( 'post', 31, 'bar' ), array( 'post', 41, 'bar Datei%20Name' ) ) === $GLOBALS['t_meta'] && array() === array_filter( $ru['tables']['postmeta']['notes'], static function ( $n ) { return false !== strpos( $n, '_elementor_data' ); } ), 'without unfiltered_html a builder payload is passed over before its value is looked at: it does not count as a hit, uses up no slot of the row limit and leaves no note — anything else would tell by the count whether the row matched. The alt text is protected but holds no markup, so the same account writes it, through wp_strip_all_tags() as the media screen does, percent-encoded octets and all' );
$GLOBALS['t_no_unfiltered'] = false;

// Every page-builder key, with or without "_", follows the same rule: the one
// list in AB_MCP_Tools_Search_Replace::markup_meta_keys(), whatever the letter case.
reset_log();
$GLOBALS['wpdb']->builder_rows = true;
$GLOBALS['t_no_unfiltered']    = true;
$rb = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'postmeta' ), 'apply' => true ) );
ok( 1 === $rb['tables']['postmeta']['matched'] && array( array( 'post', 72, 'bar' ) ) === $GLOBALS['t_meta'] && array( 'color' ) === array_column( $rb['tables']['postmeta']['samples'], 'meta_key' ), 'without unfiltered_html the builder keys without "_" — SiteOrigin, Live Composer, Pagelayer data and page code, Brizy\'s compiled HTML, Visual Composer (in any letter case, its page JS by prefix), Themify page JS, BeTheme, Thrive, Oxygen — are passed over like _elementor_data, before their value is looked at; the plain key next to them is written' );
reset_log();
$GLOBALS['wpdb']->builder_rows = true;
$rb = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'postmeta' ), 'apply' => true ) );
ok( 13 === $rb['tables']['postmeta']['matched'] && 13 === count( $GLOBALS['t_meta'] ), 'with unfiltered_html all of them are written, as before' );
reset_log();
$GLOBALS['wpdb']->builder_rows = true;
$GLOBALS['t_no_unfiltered']    = true;
$GLOBALS['t_filters']['ab_mcp_pro_markup_meta_keys'] = static function ( $keys ) { return array_values( array_diff( $keys, array( 'panels_data' ) ) ); };
$rb = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'postmeta' ), 'apply' => true ) );
ok( array( 60, 71, 72 ) === array_column( $GLOBALS['t_meta'], 1 ), 'a key the filter takes off the list is written without unfiltered_html again — in both letter cases' );
reset_log();
reset_log();
$rd = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'postmeta' ) ) );
ok( true === $rd['dry_run'] && 0 === $rd['elementor']['posts_touched'] && false === $rd['elementor']['css_cache_cleared'] && false === $rd['elementor']['element_cache_cleared'] && array() === $GLOBALS['t_del_meta'] && array() === $GLOBALS['t_del_opt'] && array() === $GLOBALS['t_actions'], 'a dry run clears no Elementor cache and fires no action' );
// With Elementor loaded, the keys come from its classes, so a renamed key is
// followed. Stand-in classes with stand-in values, defined last: they stay for
// the rest of this process.
eval( 'namespace Elementor\\Core\\Base; class Document { const CACHE_META_KEY = "_elementor_element_cache_stand_in"; }' );
eval( 'namespace Elementor\\Modules\\MarkdownRender; class Module { const CACHE_META_KEY = "_elementor_markdown_cache_stand_in"; }' );
eval( 'namespace Elementor\\Modules\\AtomicWidgets\\Styles; class Atomic_Widget_Styles { const STYLES_KEY = "local_stand_in"; }' );
reset_log();
$re = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'postmeta' ), 'apply' => true ) );
ok( true === $re['elementor']['element_cache_cleared'] && array( array( 8, '_elementor_css' ), array( 8, '_elementor_element_cache_stand_in' ), array( 8, '_elementor_markdown_cache_stand_in' ) ) === $GLOBALS['t_del_meta'] && array( array( 'elementor/atomic-widgets/styles/clear', array( array( 'local_stand_in', 8 ) ) ) ) === $GLOBALS['t_actions'], 'with Elementor loaded, the element and Markdown caches are cleared under the keys its classes name, and the atomic styles under its styles key' );

$GLOBALS['t_no_manage'] = true;
ok( ! AB_MCP_Tools_Search_Replace::meta_hit_allowed( 8, '_elementor_data' ) && AB_MCP_Tools_Search_Replace::meta_hit_allowed( 8, 'color' ), 'without manage_options an allow-listed protected key is not exposed, a plain key still is — as the direct reader has it' );
$GLOBALS['t_no_manage'] = false;

// The case for a site without a trash, in a process of its own (see the top).
exec( 'AB_TEST_NO_TRASH=1 ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' 2>&1', $nt_out, $nt_rc );
ok( 0 === $nt_rc && in_array( 'no-trash: all passed', $nt_out, true ), 'no trash: the case for a site without a trash passes in a process of its own' . ( $nt_rc ? ' — ' . implode( ' | ', array_slice( $nt_out, -4 ) ) : '' ) );

echo "options: an undo point before the first row is written (Free 4.10.0)\n";
reset_log();
$GLOBALS['t_undo_rows'] = array();
$r  = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'options' ), 'apply' => true ) );
$u  = $GLOBALS['t_undo_rows'];
ok( 1 === count( $u ) && 'option_rows' === $u[0]['kind'] && 'wp_search_replace' === $u[0]['tool'] && 'manage_options' === $u[0]['cap'] && array( 'blogdescription', 'WPLANG' ) === array_column( $u[0]['data'], 'name' ) && 0 === $u[0]['writes_before'], 'one undo point of kind option_rows holds the old value of both rows, and is recorded before the first of them is written' );
ok( 'undo_opt' === $r['options_undo_token'] && array( array( 'blogdescription', 'bar' ), array( 'WPLANG', 'bar' ) ) === $GLOBALS['t_opt'] && 2 === $r['tables']['options']['changed'], 'the answer names it as options_undo_token, and both rows are written' );
reset_log();
$GLOBALS['t_undo_rows'] = array();
$r = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'options' ) ) );
ok( array() === $GLOBALS['t_undo_rows'] && null === $r['options_undo_token'] && array() === $GLOBALS['t_opt'] && 2 === $r['tables']['options']['matched'], 'a dry run records nothing and writes nothing' );
reset_log();
$GLOBALS['t_undo_fail'] = 'ab_mcp_undo_write_failed';
$r = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'options' ), 'apply' => true ) );
ok( array() === $GLOBALS['t_opt'] && 0 === $r['tables']['options']['changed'] && null === $r['options_undo_token'] && false !== strpos( implode( ' ', $r['warnings'] ), 'The option rows were not written' ), 'without its undo point no row is written, and the answer says why and what to do' );
$GLOBALS['t_undo_fail'] = 'ab_mcp_undo_disabled';
reset_log();
$r = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'options' ), 'apply' => true ) );
ok( 2 === count( $GLOBALS['t_opt'] ) && null === $r['options_undo_token'] && false !== strpos( implode( ' ', $r['warnings'] ), 'Undo is switched off on this site' ), 'with undo switched off on the site the rows are written, and the answer says there is no undo point' );
$GLOBALS['t_undo_fail'] = 'ab_mcp_undo_too_large';
reset_log();
$r = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'options' ), 'apply' => true ) );
ok( array() === $GLOBALS['t_opt'], 'an undo point over the size limit stops the write' );
reset_log();
$r = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'options' ), 'apply' => true, 'allow_without_undo' => true ) );
ok( 2 === count( $GLOBALS['t_opt'] ) && false !== strpos( implode( ' ', $r['warnings'] ), 'as allow_without_undo asked' ), 'unless allow_without_undo lets it go, and then the answer says so' );
unset( $GLOBALS['t_undo_fail'] );
reset_log();
$GLOBALS['t_opt_now'] = 'changed meanwhile';
$r = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'options' ), 'apply' => true ) );
ok( array() === $GLOBALS['t_opt'] && 0 === $r['tables']['options']['changed'] && false !== strpos( implode( ' ', $r['tables']['options']['notes'] ), 'changed while this call ran' ), 'a row whose stored value changed after the scan read it is left as it is' );
reset_log();
$GLOBALS['t_undo_rows'] = array();
$GLOBALS['t_opt_now']   = array( 3 => 'changed meanwhile' ); // only blogdescription (option_id 3)
$r = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'options' ), 'apply' => true ) );
$u = $GLOBALS['t_undo_rows'];
ok( array( array( 'WPLANG', 'bar' ) ) === $GLOBALS['t_opt'] && 1 === count( $u ) && array( 'WPLANG' ) === array_column( $u[0]['data'], 'name' ), 'a row changed meanwhile is neither written nor in the undo point: an undo must not write back over a change this call did not make' );
unset( $GLOBALS['t_opt_now'] );

echo "post_ids and expected_count (Free 4.10.0)\n";
reset_log();
$r = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'posts', 'postmeta' ), 'post_ids' => array( 8, '8', 12 ) ) );
$pq = query_with( 'post_excerpt FROM wp_posts' );
$mq = query_with( 'p.post_parent FROM wp_postmeta pm' );
ok( is_array( $r ) && array( 8, 12 ) === $r['flags']['post_ids'] && false !== strpos( $pq, 'AND ID IN (%d,%d)' ) && false !== strpos( $mq, 'AND pm.post_id IN (%d,%d)' ), 'post_ids keeps the posts and postmeta scans to the posts it names, in SQL (an id named twice counts once)' );
ok( array() === array_diff( array_unique( array_merge( array_column( $r['tables']['posts']['samples'], 'id' ), array_column( $r['tables']['postmeta']['samples'], 'post_id' ) ) ), array( 8, 12 ) ), 'and no row of another post is in the preview' );
reset_log();
$all  = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'posts' ) ) );
reset_log();
$only = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'posts' ), 'post_ids' => array( 99 ) ) );
ok( in_array( 8, array_column( $all['tables']['posts']['samples'], 'id' ), true ) && 0 === $only['tables']['posts']['matched'] && array() === $only['tables']['posts']['samples'], 'post 8, which the scan otherwise changes, is left out when post_ids does not name it — also where the database would hand the row over anyway (the check per row, behind the SQL filter)' );
foreach ( array( array(), array( 0 ), array( -3 ), array( 'x' ), array( 1.5 ), 'not a list', array( array( 1 ) ), range( 1, 501 ) ) as $bad ) {
	$e = $SR( array( 'search' => 'foo', 'post_ids' => $bad ) );
	ok( is_wp_error( $e ) && 'ab_mcp_bad_post_ids' === $e->get_error_code(), 'post_ids refused: ' . json_encode( $bad ) );
}
$e = $SR( array( 'search' => 'foo', 'post_ids' => array( 8 ), 'tables' => array( 'posts', 'options' ) ) );
ok( is_wp_error( $e ) && 'ab_mcp_post_ids_tables' === $e->get_error_code() && false !== strpos( $e->get_error_message(), 'post_ids' ), 'post_ids with options or terms is refused, with the way' );
reset_log();
$dry = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'options' ) ) );
reset_log();
$e = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'options' ), 'apply' => true, 'expected_count' => $dry['totals']['matched'] + 1 ) );
ok( is_wp_error( $e ) && 'ab_mcp_expected_count' === $e->get_error_code() && array() === $GLOBALS['t_opt'] && false !== strpos( $e->get_error_message(), 'finds ' . $dry['totals']['matched'] . ' matching rows now, not the ' . ( $dry['totals']['matched'] + 1 ) ), 'expected_count that does not fit: nothing written, and the answer names what was found: ' . $e->get_error_message() );
reset_log();
$GLOBALS['t_serve_again'] = true; // the dry run inside reads the table once more
$r = $SR( array( 'search' => 'foo', 'replace' => 'bar', 'tables' => array( 'options' ), 'apply' => true, 'expected_count' => $dry['totals']['matched'] ) );
unset( $GLOBALS['t_serve_again'] );
ok( is_array( $r ) && 2 === count( $GLOBALS['t_opt'] ) && $dry['totals']['matched'] === $r['flags']['expected_count'], 'expected_count that fits: written, and echoed in flags' );
foreach ( array( -1, 'x', 1.5, true, array() ) as $bad ) {
	$e = $SR( array( 'search' => 'foo', 'expected_count' => $bad, 'apply' => true ) );
	ok( is_wp_error( $e ) && 'ab_mcp_bad_expected_count' === $e->get_error_code(), 'expected_count refused: ' . json_encode( $bad ) );
}
reset_log();

echo $fail ? "FAILED: $fail\n" : "search-replace-guards-test: all passed\n";
exit( $fail ? 1 : 0 );
