<?php
/**
 * What an object is on its own site, apart from its id (Z8, decisions 10 and
 * 11 of the plan of 01.11.2026; branch 6): the identity a reference points
 * to, and the mapped form of an object, in which every reference by id names
 * its target by that identity instead (mapped_hash()). Two sites with other
 * ids for the same objects give the same mapped form: «beidseitig gleich»
 * compares the mapped forms (decision 10), while the bases of a pair stay
 * the hashes of each side in its own ID space.
 *
 * THE IDENTITY of a target, by its kind:
 *  - a post: its type, its theme (templates, template parts and global
 *    styles only, from the taxonomy wp_theme), the identity of its parent,
 *    its slug, its language; none for a post without a slug;
 *  - an attachment: its file under the uploads (_wp_attached_file) and its
 *    language, as the media kind will pair it (branch 10; read here, not
 *    paired);
 *  - a menu item: the slugs of its menus, its type and object, its target
 *    (the identity of the post or term it shows; for a custom link the
 *    neutral form of its address and its label; else nothing) and the
 *    identity of its parent item;
 *  - a term: its taxonomy, slug and language.
 * The language (Codex round 3, B2) where the rows of the site say it: the
 * taxonomies language and term_language of Polylang, else the table
 * icl_translations of WPML; '' for none.
 *
 * ONE PURE FUNCTION OF THE WHOLE SITE (Codex round 3, 1): index() reads, in
 * a first pass, what names each post, attachment, menu item and term (kind,
 * slug, file, target, the reference to its parent), in pages over the whole
 * site; in a second pass it works the identities out level by level of the
 * parents, each level from the finished identities of the one above, and
 * then asks over these finished identities whether another object of the
 * site has the same one. An identity another object has as well names none
 * of them (Codex round 2, A-1): two custom links «#» with one label under one
 * parent, two posts of one type, slug and language under one parent, two
 * attachments of one file and language, two terms of one taxonomy, slug and
 * language. Nothing is kept before it is final: the order of reading cannot
 * change an identity. A part of the site that cannot be read leaves every
 * identity none.
 *
 * A target that is not there, has no slug, is not read or has the identity
 * of another has none: the mapped form is then none (null), and the object
 * counts as not equal (a reference without a resolvable target is a
 * difference).
 *
 * POSSIBLE REFERENCES (decision 12; Codex round 2, B-1; round 3, A1):
 * possible_hash() gives a second mapped form, in which a whole number the
 * canonical form noted as a possible reference in a stored value (meta)
 * names the object it hits on this site by its identity, marked as possible,
 * keeping the value as it is written: only a run of digits in its plain
 * spelling (no leading zero, not 0) is named so, every other part of the
 * value (a separator, 0, a number that hits nothing) stays, and the marker
 * says whether the value was a number or a text. A number that hits a post
 * and a term, or an object without an identity, stays as well. Two objects
 * are the same where either form is the same (AB_MCP_Sync_Proposal::
 * mapped_same()): a number that is no reference (a price of 10 on both
 * sites, hitting other posts 10) keeps the first, an image id 7 against 107
 * of the same file the second.
 *
 * Read once per inventory (reset() with each source).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-rows.php';
require_once __DIR__ . '/class-sync-canon.php';
require_once __DIR__ . '/class-sync-refs.php';

/**
 * Class AB_MCP_Sync_Identity
 */
final class AB_MCP_Sync_Identity {

	/** Levels of parents (of posts, of menu items) worked out at most. */
	const MAX_DEPTH = 32;

	/** The post types whose identity holds their theme. */
	const THEMED = array( 'wp_template', 'wp_template_part', 'wp_global_styles' );

	/** The meta a menu item is named by. */
	const ITEM_META = array( '_menu_item_type', '_menu_item_object', '_menu_item_object_id', '_menu_item_url', '_menu_item_menu_item_parent' );

	/** @var array|null The identities of the site (index()), null until read. */
	private static $index = null;

	/** @var array<int,array|null> What a whole number hits, by number. */
	private static $hits = array();

	/**
	 * Forget what was read (a new inventory).
	 */
	public static function reset() {
		self::$index = null;
		self::$hits  = array();
	}

	/**
	 * The identity of a post of this site (an attachment, a menu item too);
	 * null where it has none or another object of the site has the same.
	 *
	 * @param int   $id  Post id.
	 * @param array $ctx The context of the canonical form (the neutral addresses).
	 * @return array|null
	 */
	public static function post( $id, array $ctx ) {
		$index = self::index( $ctx );
		return $index['posts'][ (int) $id ] ?? null;
	}

	/**
	 * The identity of a term of this site; null where it has none or another
	 * term of its taxonomy has the same slug and language.
	 *
	 * @param int         $id       Term id.
	 * @param array       $ctx      Context.
	 * @param string|null $taxonomy Its taxonomy; null where the reference
	 *                              does not say (then the one taxonomy the
	 *                              term id has rows in).
	 * @return array|null
	 */
	public static function term( $id, array $ctx, $taxonomy = null ) {
		$index = self::index( $ctx );
		$id    = (int) $id;
		if ( null === $taxonomy ) {
			$taxonomies = $index['taxonomies'][ $id ] ?? array();
			if ( 1 !== count( $taxonomies ) ) {
				return null;
			}
			$taxonomy = $taxonomies[0];
		}
		return $index['terms'][ $id . "\0" . (string) $taxonomy ] ?? null;
	}

	/**
	 * The identity of a menu item as it is, before the question whether
	 * another item has the same (the identity of its line: what it shows and
	 * where it hangs); null where its target or parent has none.
	 *
	 * @param int   $id  Post id.
	 * @param array $ctx Context.
	 * @return array|null
	 */
	public static function menu_item( $id, array $ctx ) {
		$index = self::index( $ctx );
		return $index['items'][ (int) $id ] ?? null;
	}

	/**
	 * The language of a post or term, as the rows of the site say it; '' for
	 * none (also where they cannot be read), null where they say more than
	 * one.
	 *
	 * @param string $space post or term.
	 * @param int    $id    Post or term id.
	 * @param array  $ctx   Context.
	 * @param int    $ttid  The term_taxonomy_id of a term (WPML names it by that).
	 * @return string|null
	 */
	public static function language( $space, $id, array $ctx, $ttid = 0 ) {
		$index = self::index( $ctx );
		return 'term' === $space ? self::lang( $index['languages'], 'term', (int) $id, (int) $ttid ) : self::lang( $index['languages'], 'post', (int) $id, 0 );
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
	 * The SHA-256 of the mapped form with the possible references of its
	 * stored values (meta) by the identity of what they hit on this site;
	 * null where none hits an object with an identity (the mapped form then
	 * says all), or a typed reference has no target.
	 *
	 * @param array $form     Canonical form.
	 * @param array $possible Its possible references (value, at), as the
	 *                        canonical form noted them.
	 * @param array $ctx      Context.
	 * @return string|null
	 */
	public static function possible_hash( array $form, array $possible, array $ctx ) {
		$set = array();
		foreach ( $possible as $p ) {
			if ( is_array( $p ) && isset( $p['at'] ) && is_string( $p['at'] ) ) {
				$set[ $p['at'] ] = true;
			}
		}
		if ( array() === $set || ! isset( $form['meta'] ) || ! is_array( $form['meta'] ) ) {
			return null;
		}
		$hit = false;
		foreach ( $form['meta'] as $i => $entry ) {
			if ( is_array( $entry ) && isset( $entry[0] ) && is_string( $entry[0] ) && array_key_exists( 1, $entry ) ) {
				$form['meta'][ $i ][1] = self::stored( $entry[1], 'meta:' . $entry[0], $set, $ctx, $hit, 0 );
			}
		}
		return $hit ? self::mapped_hash( $form, $ctx ) : null;
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

	/* ------------------------------------------------------------ index */

	/**
	 * The identities of the site, read once per inventory.
	 *
	 * @param array $ctx Context.
	 * @return array posts (id => identity|null), there (id => true, every
	 *               post but revisions), items (id => identity as it is),
	 *               terms (term id NUL taxonomy => identity|null),
	 *               taxonomies (term id => taxonomies), languages.
	 */
	private static function index( array $ctx ) {
		if ( null === self::$index ) {
			self::$index = self::build( $ctx );
		}
		return self::$index;
	}

	/**
	 * The first pass (what names each object) and the second (the
	 * identities, level by level, then the question whether one is unique).
	 *
	 * @param array $ctx Context.
	 * @return array
	 */
	private static function build( array $ctx ) {
		$none = array(
			'posts'      => array(),
			'there'      => array(),
			'items'      => array(),
			'terms'      => array(),
			'taxonomies' => array(),
			'languages'  => array(
				'post'    => array(),
				'term'    => array(),
				'wp_post' => array(),
				'wp_tax'  => array(),
			),
		);
		// First pass: what names each object.
		$rows = self::all_pages(
			static function ( $after ) {
				return AB_MCP_Sync_Rows::post_index( $after, AB_MCP_Sync_Rows::INDEX_PAGE );
			},
			'ID'
		);
		$files = self::all_pages(
			static function ( $after ) {
				return AB_MCP_Sync_Rows::meta_by_key( '_wp_attached_file', $after, AB_MCP_Sync_Rows::INDEX_PAGE );
			},
			'meta_id'
		);
		$terms = self::all_pages(
			static function ( $after ) {
				return AB_MCP_Sync_Rows::terms( null, $after, AB_MCP_Sync_Rows::MAX_PAGE );
			},
			'term_taxonomy_id',
			AB_MCP_Sync_Rows::MAX_PAGE
		);
		$themes    = AB_MCP_Sync_Rows::relations( 'wp_theme' );
		$menus     = AB_MCP_Sync_Rows::relations( 'nav_menu' );
		$pll_post  = AB_MCP_Sync_Rows::relations( 'language' );
		$pll_term  = AB_MCP_Sync_Rows::relations( 'term_language' );
		$wpml      = AB_MCP_Sync_Rows::wpml_languages();
		$item_rows = AB_MCP_Sync_Rows::menu_items( self::ITEM_META );
		if ( null === $rows || null === $files || null === $terms || null === $themes || null === $menus || null === $pll_post || null === $pll_term || null === $wpml || null === $item_rows ) {
			return $none;
		}
		$index              = $none;
		$index['languages'] = array(
			'post'    => self::by_object( $pll_post ),
			'term'    => self::by_object( $pll_term ),
			'wp_post' => array(),
			'wp_tax'  => array(),
		);
		foreach ( $wpml as $row ) {
			$space = 0 === strpos( $row['type'], 'post_' ) ? 'wp_post' : ( 0 === strpos( $row['type'], 'tax_' ) ? 'wp_tax' : null );
			if ( null !== $space ) {
				$index['languages'][ $space ][ $row['id'] ][] = $row['language'];
			}
		}
		$langs = $index['languages'];

		// Second pass, terms: taxonomy, slug, language.
		$candidates = array();
		foreach ( $terms as $row ) {
			$id                           = (int) $row['term_id'];
			$index['taxonomies'][ $id ][] = (string) $row['taxonomy'];
			$lang                         = self::lang( $langs, 'term', $id, (int) $row['term_taxonomy_id'] );
			$candidates[ $id . "\0" . $row['taxonomy'] ] = '' === (string) $row['slug'] || null === $lang ? null : array( 'term', (string) $row['taxonomy'], (string) $row['slug'], $lang );
		}
		$index['terms'] = self::unique( $candidates );

		$posts = array();
		foreach ( $rows as $row ) {
			$posts[ $row['ID'] ]          = $row;
			$index['there'][ $row['ID'] ] = true;
		}
		$by_file = self::by_object( $files, 'post_id', 'value' );
		$themed  = self::by_object( $themes );

		// Attachments: file and language.
		$candidates = array();
		foreach ( $posts as $id => $row ) {
			if ( 'attachment' === $row['post_type'] ) {
				$list              = $by_file[ $id ] ?? array();
				$lang              = self::lang( $langs, 'post', $id, 0 );
				$candidates[ $id ] = 1 === count( $list ) && '' !== $list[0] && null !== $lang ? array( 'attachment', $list[0], $lang ) : null;
			}
		}
		$index['posts'] = self::unique( $candidates );

		// Posts, level by level of their parents.
		$open = array();
		foreach ( $posts as $id => $row ) {
			if ( 'attachment' !== $row['post_type'] && 'nav_menu_item' !== $row['post_type'] ) {
				$open[ $id ] = $row;
			}
		}
		$index['posts'] = self::levels(
			$open,
			$index['posts'],
			static function ( $row ) {
				return (int) $row['post_parent'];
			},
			static function ( $id, $row, $up ) use ( $themed, $langs ) {
				$theme = '';
				if ( in_array( $row['post_type'], self::THEMED, true ) ) {
					$list  = $themed[ $id ] ?? array();
					$theme = array() === $list ? '' : ( 1 === count( $list ) ? $list[0] : null );
				}
				$lang = self::lang( $langs, 'post', $id, 0 );
				return '' === $row['post_name'] || null === $theme || null === $lang ? null : array( 'post', $row['post_type'], $theme, $up, $row['post_name'], $lang );
			}
		);

		// Menu items, level by level of their parent items.
		$meta = array();
		foreach ( $item_rows['meta'] as $row ) {
			if ( ! isset( $meta[ $row['post_id'] ][ $row['key'] ] ) ) {
				$meta[ $row['post_id'] ][ $row['key'] ] = $row['value'];
			}
		}
		$in_menus = self::by_object( $menus );
		$open     = array();
		foreach ( $posts as $id => $row ) {
			if ( 'nav_menu_item' === $row['post_type'] ) {
				$open[ $id ] = ( $meta[ $id ] ?? array() ) + array( '~title' => $item_rows['titles'][ $id ] ?? '' );
			}
		}
		$items = array();
		$found = self::levels(
			$open,
			array(),
			static function ( $m ) {
				return isset( $m['_menu_item_menu_item_parent'] ) ? (int) $m['_menu_item_menu_item_parent'] : 0;
			},
			static function ( $id, $m, $up ) use ( $in_menus, $index, $ctx, &$items ) {
				$slugs = $in_menus[ $id ] ?? array();
				sort( $slugs, SORT_STRING );
				$type   = (string) ( $m['_menu_item_type'] ?? '' );
				$object = (string) ( $m['_menu_item_object'] ?? '' );
				$of     = (int) ( $m['_menu_item_object_id'] ?? 0 );
				switch ( $type ) {
					case 'post_type':
						$target = $index['posts'][ $of ] ?? null;
						break;
					case 'taxonomy':
						$target = $index['terms'][ $of . "\0" . $object ] ?? null;
						break;
					case 'custom':
						$acc    = AB_MCP_Sync_Canon::acc();
						$target = array( 'custom', AB_MCP_Sync_Canon::text( (string) ( $m['_menu_item_url'] ?? '' ), $ctx, array(), $acc, 'meta:_menu_item_url', true ), (string) $m['~title'] );
						break;
					default:
						$target = array( $type, $object );
				}
				$items[ $id ] = null === $target ? null : array( 'nav_menu_item', $slugs, $type, $object, $target, null === $up ? 'none' : $up );
				return $items[ $id ];
			},
			true
		);
		$index['items'] = $items;
		$index['posts'] = $index['posts'] + $found;
		return $index;
	}

	/**
	 * The identities of objects whose identity holds that of their parent,
	 * level by level: the objects under no parent first, then those whose
	 * parent is final; at each level the identities made from the finished
	 * ones above, then each that another of the level has as well made none.
	 * An object whose parent is not there, has none, or leads back to it has
	 * none.
	 *
	 * @param array    $open   id => what names it, not worked out yet.
	 * @param array    $final  id => identity|null already final (the parents
	 *                         they may hang under besides their own kind).
	 * @param callable $parent function( $row ): int, its parent (0 for none).
	 * @param callable $make   function( $id, $row, $up ): array|null, $up the
	 *                         identity of the parent (array() at the top for
	 *                         posts, null for items).
	 * @param bool     $items  Whether these are menu items (their parent is an
	 *                         item; at the top their up is null, not array()).
	 * @return array id => identity|null, the final ones of $open (and $final).
	 */
	private static function levels( array $open, array $final, $parent, $make, $items = false ) {
		$out = $final;
		for ( $level = 0; $level <= self::MAX_DEPTH && array() !== $open; ++$level ) {
			$made = array();
			foreach ( $open as $id => $row ) {
				$up = (int) call_user_func( $parent, $row );
				if ( 0 === $up ) {
					$made[ $id ] = call_user_func( $make, $id, $row, $items ? null : array() );
				} elseif ( ! isset( $open[ $up ] ) && array_key_exists( $up, $out ) ) {
					// Its parent is final: of a level above, or of a kind worked out before.
					$made[ $id ] = null === $out[ $up ] ? null : call_user_func( $make, $id, $row, $out[ $up ] );
				}
			}
			if ( array() === $made ) {
				break;
			}
			foreach ( self::unique( $made ) as $id => $identity ) {
				$out[ $id ] = $identity;
				unset( $open[ $id ] );
			}
		}
		// What is left hangs under a parent that is not there, too deep, or leads back.
		foreach ( array_keys( $open ) as $id ) {
			$out[ $id ] = null;
		}
		return $out;
	}

	/**
	 * Identities with each that another one has as well made none.
	 *
	 * @param array $candidates key => identity|null.
	 * @return array
	 */
	private static function unique( array $candidates ) {
		$count = array();
		foreach ( $candidates as $identity ) {
			if ( null !== $identity ) {
				$key           = AB_MCP_Sync_Canon::encode( $identity );
				$count[ $key ] = ( $count[ $key ] ?? 0 ) + 1;
			}
		}
		foreach ( $candidates as $key => $identity ) {
			if ( null !== $identity && 1 !== $count[ AB_MCP_Sync_Canon::encode( $identity ) ] ) {
				$candidates[ $key ] = null;
			}
		}
		return $candidates;
	}

	/**
	 * Every page of a reader of the index, one after another.
	 *
	 * @param callable $page   function( int $after ): array[]|null.
	 * @param string   $cursor The field of a row the next page starts after.
	 * @param int      $size   Rows of a full page.
	 * @return array[]|null
	 */
	private static function all_pages( $page, $cursor, $size = AB_MCP_Sync_Rows::INDEX_PAGE ) {
		$all   = array();
		$after = 0;
		do {
			$rows = call_user_func( $page, $after );
			if ( null === $rows ) {
				return null;
			}
			foreach ( $rows as $row ) {
				$all[] = $row;
				$after = (int) $row[ $cursor ];
			}
		} while ( count( $rows ) >= $size );
		return $all;
	}

	/**
	 * Rows by their object: object id => the values of the field.
	 *
	 * @param array[] $rows  Rows.
	 * @param string  $by    Field of the object.
	 * @param string  $field Field of the value.
	 * @return array<int,array>
	 */
	private static function by_object( array $rows, $by = 'object_id', $field = 'slug' ) {
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row[ $by ] ][] = $row[ $field ];
		}
		return $out;
	}

	/**
	 * The language of an object: of Polylang where it names one, else of
	 * WPML, else ''; null where one of them names more than one.
	 *
	 * @param array  $langs post, term (Polylang), wp_post, wp_tax (WPML).
	 * @param string $space post or term.
	 * @param int    $id    Post or term id.
	 * @param int    $ttid  term_taxonomy_id of a term.
	 * @return string|null
	 */
	private static function lang( array $langs, $space, $id, $ttid ) {
		$polylang = $langs[ $space ][ $id ] ?? array();
		if ( array() !== $polylang ) {
			return 1 === count( $polylang ) ? (string) $polylang[0] : null;
		}
		$wpml = 'term' === $space ? ( $langs['wp_tax'][ $ttid ] ?? array() ) : ( $langs['wp_post'][ $id ] ?? array() );
		if ( array() !== $wpml ) {
			return 1 === count( array_unique( $wpml ) ) ? (string) $wpml[0] : null;
		}
		return '';
	}

	/**
	 * What a whole number hits on this site, by identity: the post or the
	 * term of that id, where only one of the two ID spaces has it and it has
	 * an identity; null else.
	 *
	 * @param int   $n   Number.
	 * @param array $ctx Context.
	 * @return array|null
	 */
	private static function hit( $n, array $ctx ) {
		$n = (int) $n;
		if ( array_key_exists( $n, self::$hits ) ) {
			return self::$hits[ $n ];
		}
		$index = self::index( $ctx );
		// A revision is not in the index; it is a post all the same.
		$there = isset( $index['there'][ $n ] ) ? true : AB_MCP_Sync_Rows::post_type_of( $n );
		$terms = isset( $index['taxonomies'][ $n ] );
		$out   = null;
		if ( null !== $there && ( false !== $there ) !== $terms ) {
			$out = false !== $there ? ( $index['posts'][ $n ] ?? null ) : self::term( $n, $ctx );
		}
		self::$hits[ $n ] = $out;
		return $out;
	}

	/**
	 * A stored value of the form (AB_MCP_Sync_Canon::value()) with its
	 * possible references by identity, at the places the canonical form
	 * noted them (AB_MCP_Sync_Refs::possible_leaf()).
	 *
	 * @param mixed  $value  Form of the value.
	 * @param string $at     Where it stands.
	 * @param array  $set    The places noted.
	 * @param array  $ctx    Context.
	 * @param bool   $hit    Set to true where a number names an object.
	 * @param int    $layers Texts unpacked above.
	 * @return mixed
	 */
	private static function stored( $value, $at, array $set, array $ctx, &$hit, $layers ) {
		if ( is_array( $value ) ) {
			if ( array( '~php' ) === array_keys( $value ) ) {
				return array( '~php' => self::tree( $value['~php'], $at, $set, $ctx, $hit, $layers ) );
			}
			if ( array( '~s' ) !== array_keys( $value ) || ! is_array( $value['~s'] ) ) {
				return $value;
			}
			$parts = $value['~s'];
			// A text that is stored JSON as a whole (AB_MCP_Sync_Refs::json_canon()):
			// its tree, by the paths of the JSON.
			if ( 1 === count( $parts ) && is_array( $parts[0] ) && isset( $parts[0]['~json'] ) && array_key_exists( 'v', $parts[0] ) ) {
				$parts[0]['v'] = self::tree( $parts[0]['v'], $at, $set, $ctx, $hit, $layers );
				return array( '~s' => $parts );
			}
			return self::inside( $value, $at, $set, $ctx, $hit, $layers );
		}
		$leaf = self::numbers_of( $value, $at, $set, $ctx, $hit );
		if ( null !== $leaf ) {
			return $leaf;
		}
		return is_string( $value ) ? self::inside( $value, $at, $set, $ctx, $hit, $layers ) : $value;
	}

	/**
	 * An unserialized value of the form, or the tree of stored JSON (an
	 * object as "~o"), key by key (AB_MCP_Sync_Canon::walk(),
	 * AB_MCP_Sync_Refs::json_tree(): a key beginning with "~" carries one
	 * more).
	 *
	 * @param mixed  $value  Value.
	 * @param string $at     Where it stands.
	 * @param array  $set    The places noted.
	 * @param array  $ctx    Context.
	 * @param bool   $hit    Set where a number names an object.
	 * @param int    $layers Layers.
	 * @return mixed
	 */
	private static function tree( $value, $at, array $set, array $ctx, &$hit, $layers ) {
		if ( is_array( $value ) && array( '~o' ) === array_keys( $value ) && is_array( $value['~o'] ) ) {
			return array( '~o' => self::tree( $value['~o'], $at, $set, $ctx, $hit, $layers ) );
		}
		if ( ! is_array( $value ) || self::marker( $value ) ) {
			return self::stored( $value, $at, $set, $ctx, $hit, $layers );
		}
		foreach ( $value as $name => $item ) {
			$key            = is_string( $name ) && '' !== $name && '~' === $name[0] ? substr( $name, 1 ) : $name;
			$value[ $name ] = self::tree( $item, $at . '.' . $key, $set, $ctx, $hit, $layers );
		}
		return $value;
	}

	/**
	 * A text that holds a value of its own (JSON, serialized, as
	 * AB_MCP_Sync_Refs::note_possible() reads it): that value with its
	 * possible references by identity, where any names an object; the text
	 * as it is else. The neutral addresses in it stand by their place.
	 *
	 * @param string|array $value  A text, or its form with addresses.
	 * @param string       $at     Where it stands.
	 * @param array        $set    The places noted.
	 * @param array        $ctx    Context.
	 * @param bool         $hit    Set where a number names an object.
	 * @param int          $layers Layers.
	 * @return mixed
	 */
	private static function inside( $value, $at, array $set, array $ctx, &$hit, $layers ) {
		if ( $layers >= AB_MCP_Sync_Canon::MAX_LAYERS ) {
			return $value;
		}
		$marks = array();
		$text  = is_string( $value ) ? $value : self::flat( $value['~s'], $marks );
		if ( is_serialized( $text ) ) {
			$read  = AB_MCP_Sync_Canon::unserialized( $text );
			$inner = $read['ok'] ? $read['value'] : null;
		} else {
			$inner = AB_MCP_Sync_Canon::json_text( $text );
		}
		if ( ! is_array( $inner ) && ! is_string( $inner ) ) {
			return $value;
		}
		$found = false;
		$out   = self::noted( $inner, $at, $set, $ctx, $found, $layers + 1, 0 );
		if ( ! $found ) {
			return $value;
		}
		$hit = true;
		return array(
			'~inside' => $out,
			'marks'   => $marks,
		);
	}

	/**
	 * The text of the parts of a text form: a part that is text again (the
	 * JSON of no producer, AB_MCP_Sync_Refs::json_canon()) by its parts,
	 * every other marker (a neutral address, a reference) by a sign of its
	 * place that no stored text holds, kept in $marks.
	 *
	 * @param array $parts Parts.
	 * @param array $marks Markers, by their place.
	 * @return string
	 */
	private static function flat( array $parts, array &$marks ) {
		$text = '';
		foreach ( $parts as $part ) {
			if ( is_string( $part ) ) {
				$text .= $part;
			} elseif ( is_array( $part ) && array( '~s' ) === array_keys( $part ) && is_array( $part['~s'] ) ) {
				$text .= self::flat( $part['~s'], $marks );
			} else {
				$text   .= "\u{E000}" . count( $marks ) . "\u{E001}";
				$marks[] = $part;
			}
		}
		return $text;
	}

	/**
	 * A value unpacked from a text (AB_MCP_Sync_Refs::note_tree()).
	 *
	 * @param mixed  $value  Value.
	 * @param string $at     Where it stands.
	 * @param array  $set    The places noted.
	 * @param array  $ctx    Context.
	 * @param bool   $hit    Set where a number names an object.
	 * @param int    $layers Layers.
	 * @param int    $depth  Depth.
	 * @return mixed
	 */
	private static function noted( $value, $at, array $set, array $ctx, &$hit, $layers, $depth ) {
		if ( $depth > AB_MCP_Sync_Canon::MAX_DEPTH ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::noted( $item, $at . '.' . $key, $set, $ctx, $hit, $layers, $depth + 1 );
			}
			return $value;
		}
		$leaf = self::numbers_of( $value, $at, $set, $ctx, $hit );
		if ( null !== $leaf ) {
			return $leaf;
		}
		return is_string( $value ) ? self::inside( $value, $at, $set, $ctx, $hit, $layers ) : $value;
	}

	/**
	 * A leaf the canonical form noted as a possible reference, as it is
	 * written (Codex round 3, A1): a number as a number, a text as its parts,
	 * each run of digits in its plain spelling (no leading zero, not 0) that
	 * hits an object by its identity, every other part (a separator, 0, a
	 * number with a leading zero, one that hits nothing) as it is; null where
	 * the place was not noted or no part hits an object.
	 *
	 * @param mixed  $value Leaf.
	 * @param string $at    Where it stands.
	 * @param array  $set   The places noted.
	 * @param array  $ctx   Context.
	 * @param bool   $hit   Set where a number names an object.
	 * @return array|null
	 */
	private static function numbers_of( $value, $at, array $set, array $ctx, &$hit ) {
		if ( ! isset( $set[ $at ] ) || ( ! is_int( $value ) && ! is_string( $value ) ) || array() === AB_MCP_Sync_Refs::numbers( $value ) ) {
			return null;
		}
		$parts = is_int( $value ) ? array( (string) $value ) : preg_split( '/([0-9]+)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$any   = false;
		foreach ( $parts as $i => $part ) {
			if ( 1 === preg_match( '/^[1-9][0-9]{0,17}\z/', $part ) ) {
				$as = self::hit( (int) $part, $ctx );
				if ( null !== $as ) {
					$parts[ $i ] = array( 'as' => $as );
					$any         = true;
				}
			}
		}
		if ( ! $any ) {
			return null;
		}
		$hit = true;
		return array(
			'~possible' => is_int( $value ) ? 'number' : 'text',
			'parts'     => $parts,
		);
	}

	/**
	 * Whether an array of the form is a marker of the canonical form (one
	 * key beginning with a single "~"), not a stored array.
	 *
	 * @param array $value Value.
	 * @return bool
	 */
	private static function marker( array $value ) {
		if ( 1 !== count( $value ) ) {
			return false;
		}
		$key = (string) array_key_first( $value );
		return strlen( $key ) > 1 && '~' === $key[0] && '~' !== $key[1];
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
			$as = 'post' === $value['~ref'] ? self::post( (int) $value['id'], $ctx ) : ( 'term' === $value['~ref'] ? self::term( (int) $value['id'], $ctx ) : null );
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
