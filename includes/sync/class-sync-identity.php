<?php
/**
 * What an object is on its own site, apart from its id (Z8, decisions 10 and
 * 11 of the plan of 01.11.2026; branch 6): the identity a reference points
 * to, read from the rows (AB_MCP_Sync_Rows), and the mapped form of an
 * object, in which every reference by id names its target by that identity
 * instead (mapped_hash()). Two sites with other ids for the same objects
 * give the same mapped form: «beidseitig gleich» compares the mapped forms
 * (decision 10), while the bases of a pair stay the hashes of each side in
 * its own ID space.
 *
 * THE IDENTITY of a target, by its kind:
 *  - a post: its type, its theme (templates, template parts and global
 *    styles only, from the taxonomy wp_theme), the identities of its parents
 *    from the top, its slug; none for a post without a slug;
 *  - an attachment: its file under the uploads (_wp_attached_file), as the
 *    media kind will pair it (branch 10; read here, not paired);
 *  - a menu item: the slugs of its menus, its type and object, its target
 *    (the identity of the post or term it shows, the neutral form of the
 *    address of a custom link, else nothing) and its parent item;
 *  - a term: its taxonomy and slug.
 * A target that is not there, has no slug, or is not read to its end has no
 * identity: the mapped form is then none (null), and the object counts as
 * not equal (a reference without a resolvable target is a difference).
 *
 * Read once per object and inventory (reset() with each source).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-rows.php';
require_once __DIR__ . '/class-sync-canon.php';

/**
 * Class AB_MCP_Sync_Identity
 */
final class AB_MCP_Sync_Identity {

	/** Parents (of posts, of menu items) followed at most. */
	const MAX_DEPTH = 32;

	/** The post types whose identity holds their theme. */
	const THEMED = array( 'wp_template', 'wp_template_part', 'wp_global_styles' );

	/** @var array<int,array|null> Identities of posts read, by id. */
	private static $posts = array();

	/** @var array<string,array|null> Identities of terms read, by id and taxonomy. */
	private static $terms = array();

	/**
	 * Forget what was read (a new inventory).
	 */
	public static function reset() {
		self::$posts = array();
		self::$terms = array();
	}

	/**
	 * The identity of a post of this site.
	 *
	 * @param int   $id    Post id.
	 * @param array $ctx   The context of the canonical form (the neutral addresses).
	 * @param int   $depth Parents followed so far.
	 * @return array|null
	 */
	public static function post( $id, array $ctx, $depth = 0 ) {
		$id = (int) $id;
		if ( $id <= 0 || $depth > self::MAX_DEPTH ) {
			return null;
		}
		if ( array_key_exists( $id, self::$posts ) ) {
			return self::$posts[ $id ];
		}
		// A parent that leads back here has none.
		self::$posts[ $id ] = null;
		$row                = AB_MCP_Sync_Rows::post( $id );
		$out                = null;
		if ( is_array( $row ) ) {
			if ( 'attachment' === $row['post_type'] ) {
				$files = AB_MCP_Sync_Rows::meta_values( 'post', $id, '_wp_attached_file' );
				$out   = is_array( $files ) && 1 === count( $files ) && '' !== $files[0] ? array( 'attachment', $files[0] ) : null;
			} elseif ( 'nav_menu_item' === $row['post_type'] ) {
				$out = self::menu_item( $id, $ctx, $depth );
			} elseif ( '' !== (string) $row['post_name'] ) {
				$parent = (int) $row['post_parent'];
				$up     = 0 === $parent ? array() : self::post( $parent, $ctx, $depth + 1 );
				$theme  = in_array( $row['post_type'], self::THEMED, true ) ? self::theme( $id ) : '';
				$out    = null === $up || null === $theme ? null : array( 'post', (string) $row['post_type'], $theme, $up, (string) $row['post_name'] );
			}
		}
		self::$posts[ $id ] = $out;
		return $out;
	}

	/**
	 * The identity of a term of this site.
	 *
	 * @param int         $id       Term id.
	 * @param string|null $taxonomy Its taxonomy; null where the reference
	 *                              does not say (then the one taxonomy the
	 *                              term id has rows in).
	 * @return array|null
	 */
	public static function term( $id, $taxonomy = null ) {
		$id = (int) $id;
		if ( $id <= 0 ) {
			return null;
		}
		$key = $id . "\0" . (string) $taxonomy;
		if ( array_key_exists( $key, self::$terms ) ) {
			return self::$terms[ $key ];
		}
		$out = null;
		if ( null === $taxonomy ) {
			$taxonomies = AB_MCP_Sync_Rows::term_taxonomies( $id );
			$taxonomy   = is_array( $taxonomies ) && 1 === count( $taxonomies ) ? $taxonomies[0] : null;
		}
		$row = null === $taxonomy ? null : AB_MCP_Sync_Rows::term( $id, (string) $taxonomy );
		if ( is_array( $row ) && '' !== (string) $row['slug'] ) {
			$out = array( 'term', (string) $row['taxonomy'], (string) $row['slug'] );
		}
		self::$terms[ $key ] = $out;
		return $out;
	}

	/**
	 * The SHA-256 of the mapped form of an object: its canonical form with
	 * every reference by id naming the identity of its target; null where a
	 * target has none.
	 *
	 * @param array $form Canonical form (AB_MCP_Sync_Canon::finish()).
	 * @param array $ctx  Context.
	 * @return string|null
	 */
	public static function mapped_hash( array $form, array $ctx ) {
		$ok     = true;
		$mapped = self::map( $form, $ctx, $ok );
		return $ok ? AB_MCP_Sync_Canon::hash( $mapped ) : null;
	}

	/**
	 * The SHA-256 of an identity, for the identity of a line (a menu item's
	 * target and parent); null for none.
	 *
	 * @param array|string|null $identity Identity.
	 * @return string|null
	 */
	public static function print_of( $identity ) {
		return null === $identity ? null : hash( 'sha256', AB_MCP_Sync_Canon::encode( $identity ) );
	}

	/**
	 * The identity of a menu item: its menus, type, object, target and
	 * parent ('none' at the top).
	 *
	 * @param int   $id    Post id.
	 * @param array $ctx   Context.
	 * @param int   $depth Parents followed so far.
	 * @return array|null
	 */
	public static function menu_item( $id, array $ctx, $depth = 0 ) {
		$meta = AB_MCP_Sync_Rows::meta( 'post', (int) $id );
		$tax  = AB_MCP_Sync_Rows::object_terms( (int) $id );
		if ( null === $meta || null === $tax ) {
			return null;
		}
		$m = array();
		foreach ( $meta as $row ) {
			if ( ! array_key_exists( $row['key'], $m ) ) {
				$m[ $row['key'] ] = $row['value'];
			}
		}
		$menus = array();
		foreach ( $tax as $term ) {
			if ( 'nav_menu' === $term['taxonomy'] ) {
				$menus[] = $term['slug'];
			}
		}
		sort( $menus, SORT_STRING );
		$type   = isset( $m['_menu_item_type'] ) ? (string) $m['_menu_item_type'] : '';
		$object = isset( $m['_menu_item_object'] ) ? (string) $m['_menu_item_object'] : '';
		$target = self::target( $type, $object, isset( $m['_menu_item_object_id'] ) ? $m['_menu_item_object_id'] : '', isset( $m['_menu_item_url'] ) ? $m['_menu_item_url'] : '', $ctx, $depth );
		$parent = isset( $m['_menu_item_menu_item_parent'] ) ? (int) $m['_menu_item_menu_item_parent'] : 0;
		$up     = 0 === $parent ? 'none' : self::post( $parent, $ctx, $depth + 1 );
		if ( null === $target || null === $up ) {
			return null;
		}
		return array( 'nav_menu_item', $menus, $type, $object, $target, $up );
	}

	/**
	 * What a menu item shows: the identity of its post or term, the neutral
	 * form of the address of a custom link, else its type and object.
	 *
	 * @param string $type   _menu_item_type.
	 * @param string $object _menu_item_object.
	 * @param string $id     _menu_item_object_id.
	 * @param string $url    _menu_item_url.
	 * @param array  $ctx    Context.
	 * @param int    $depth  Depth.
	 * @return array|string|null
	 */
	public static function target( $type, $object, $id, $url, array $ctx, $depth = 0 ) {
		switch ( $type ) {
			case 'post_type':
				return self::post( (int) $id, $ctx, $depth + 1 );
			case 'taxonomy':
				return self::term( (int) $id, $object );
			case 'custom':
				$acc = AB_MCP_Sync_Canon::acc();
				return array( 'custom', AB_MCP_Sync_Canon::text( (string) $url, $ctx, array(), $acc, 'meta:_menu_item_url', true ) );
		}
		return array( (string) $type, (string) $object );
	}

	/**
	 * The theme of a template: the slug of its one term of wp_theme; '' for
	 * none, null where there are more.
	 *
	 * @param int $id Post id.
	 * @return string|null
	 */
	private static function theme( $id ) {
		$tax = AB_MCP_Sync_Rows::object_terms( (int) $id );
		if ( null === $tax ) {
			return null;
		}
		$themes = array();
		foreach ( $tax as $term ) {
			if ( 'wp_theme' === $term['taxonomy'] ) {
				$themes[] = $term['slug'];
			}
		}
		return array() === $themes ? '' : ( 1 === count( $themes ) ? $themes[0] : null );
	}

	/**
	 * A form with each reference by id naming its target by identity.
	 *
	 * @param mixed $value Form.
	 * @param array $ctx   Context.
	 * @param bool  $ok    Set to false where a target has no identity.
	 * @return mixed
	 */
	private static function map( $value, array $ctx, &$ok ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( isset( $value['~ref'], $value['id'] ) && is_string( $value['~ref'] ) ) {
			$as = 'post' === $value['~ref'] ? self::post( (int) $value['id'], $ctx ) : ( 'term' === $value['~ref'] ? self::term( (int) $value['id'] ) : null );
			if ( null === $as ) {
				$ok = false;
				return $value;
			}
			unset( $value['id'] );
			return array( 'as' => $as ) + $value;
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::map( $item, $ctx, $ok );
		}
		return $value;
	}
}
