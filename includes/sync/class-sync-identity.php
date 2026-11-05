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
 *    (the identity of the post or term it shows; for a custom link the
 *    neutral form of its address and its label, Codex round 2, A-1; else
 *    nothing) and its parent item;
 *  - a term: its taxonomy and slug.
 * A target that is not there, has no slug, or is not read to its end has no
 * identity: the mapped form is then none (null), and the object counts as
 * not equal (a reference without a resolvable target is a difference).
 *
 * ONE TARGET PER IDENTITY (Codex round 2, A-1): an identity that another
 * object of the same site has as well (two custom links «#» with the same
 * label under one parent, two posts of one type with one slug under one
 * parent, two attachments of one file, two terms of one taxonomy with one
 * slug) names none of them: it maps nothing, and an object whose own
 * identity holds it (a menu item under such a parent) has none either.
 *
 * POSSIBLE REFERENCES (decision 12; Codex round 2, B-1): possible_hash()
 * gives a second mapped form, in which each whole number the canonical form
 * noted as a possible reference in a stored value (meta) names the object it
 * hits on this site by its identity, marked as possible; a number that hits
 * none, hits a post and a term, or hits one without an identity stays as it
 * is. Two objects are the same where either form is the same
 * (AB_MCP_Sync_Proposal::mapped_same()): a number that is no reference
 * (a price of 10 on both sites, hitting other posts 10) keeps the first,
 * an image id 7 against 107 of the same file the second.
 *
 * Read once per object and inventory (reset() with each source).
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

	/** Parents (of posts, of menu items) followed at most. */
	const MAX_DEPTH = 32;

	/** The post types whose identity holds their theme. */
	const THEMED = array( 'wp_template', 'wp_template_part', 'wp_global_styles' );

	/** @var array<int,array|null> Identities of posts read, by id. */
	private static $posts = array();

	/** @var array<int,bool|null> Whether a post row was there (null: not read), by id. */
	private static $there = array();

	/** @var array<int,array|null> Identities of menu items as they are, before the check that they are unique, by id. */
	private static $items = array();

	/** @var array<string,array|null> Identities of terms read, by id and taxonomy. */
	private static $terms = array();

	/** @var array<int,array|null> What a whole number hits, by number. */
	private static $hits = array();

	/**
	 * Forget what was read (a new inventory).
	 */
	public static function reset() {
		self::$posts = array();
		self::$there = array();
		self::$items = array();
		self::$terms = array();
		self::$hits  = array();
	}

	/**
	 * The identity of a post of this site; null where it has none or another
	 * post of the site has the same.
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
		self::$there[ $id ] = null === $row ? null : is_array( $row );
		$out                = null;
		if ( is_array( $row ) ) {
			if ( 'attachment' === $row['post_type'] ) {
				$files = AB_MCP_Sync_Rows::meta_values( 'post', $id, '_wp_attached_file' );
				$file  = is_array( $files ) && 1 === count( $files ) && '' !== $files[0] ? $files[0] : null;
				$out   = null !== $file && array( $id ) === AB_MCP_Sync_Rows::attachments_of( $file ) ? array( 'attachment', $file ) : null;
			} elseif ( 'nav_menu_item' === $row['post_type'] ) {
				$out = self::unique_item( $id, self::menu_item( $id, $ctx, $depth, $row ), $ctx, $depth );
			} elseif ( '' !== (string) $row['post_name'] ) {
				$parent = (int) $row['post_parent'];
				$up     = 0 === $parent ? array() : self::post( $parent, $ctx, $depth + 1 );
				$theme  = in_array( $row['post_type'], self::THEMED, true ) ? self::theme( $id ) : '';
				$out    = null === $up || null === $theme ? null : array( 'post', (string) $row['post_type'], $theme, $up, (string) $row['post_name'] );
				if ( null !== $out && ! self::alone( $id, (string) $row['post_type'], (string) $row['post_name'], $parent, $theme ) ) {
					$out = null;
				}
			}
		}
		self::$posts[ $id ] = $out;
		return $out;
	}

	/**
	 * The identity of a term of this site; null where it has none or another
	 * term of its taxonomy has the same slug.
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
		if ( is_array( $row ) && '' !== (string) $row['slug'] && array( $id ) === AB_MCP_Sync_Rows::terms_by_slug( (string) $row['taxonomy'], (string) $row['slug'] ) ) {
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

	/**
	 * The identity of a menu item as it is: its menus, type, object, target
	 * and parent ('none' at the top). Whether another item of its menu has
	 * the same is asked where it names a target (post()).
	 *
	 * @param int        $id    Post id.
	 * @param array      $ctx   Context.
	 * @param int        $depth Parents followed so far.
	 * @param array|null $row   Its row, where read already.
	 * @return array|null
	 */
	public static function menu_item( $id, array $ctx, $depth = 0, $row = null ) {
		$id = (int) $id;
		if ( array_key_exists( $id, self::$items ) ) {
			return self::$items[ $id ];
		}
		self::$items[ $id ] = null;
		$row                = is_array( $row ) ? $row : AB_MCP_Sync_Rows::post( $id );
		$meta               = AB_MCP_Sync_Rows::meta( 'post', $id );
		$tax                = AB_MCP_Sync_Rows::object_terms( $id );
		if ( ! is_array( $row ) || null === $meta || null === $tax ) {
			return null;
		}
		$m = array();
		foreach ( $meta as $one ) {
			if ( ! array_key_exists( $one['key'], $m ) ) {
				$m[ $one['key'] ] = $one['value'];
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
		$target = self::target( $type, $object, isset( $m['_menu_item_object_id'] ) ? $m['_menu_item_object_id'] : '', isset( $m['_menu_item_url'] ) ? $m['_menu_item_url'] : '', $ctx, $depth, (string) $row['post_title'] );
		$parent = isset( $m['_menu_item_menu_item_parent'] ) ? (int) $m['_menu_item_menu_item_parent'] : 0;
		$up     = 0 === $parent ? 'none' : self::post( $parent, $ctx, $depth + 1 );
		$out    = null === $target || null === $up ? null : array( 'nav_menu_item', $menus, $type, $object, $target, $up );
		self::$items[ $id ] = $out;
		return $out;
	}

	/**
	 * What a menu item shows: the identity of its post or term; for a custom
	 * link the neutral form of its address and its label (two «#» headings
	 * are two items, Codex round 2, A-1); else its type and object.
	 *
	 * @param string $type   _menu_item_type.
	 * @param string $object _menu_item_object.
	 * @param string $id     _menu_item_object_id.
	 * @param string $url    _menu_item_url.
	 * @param array  $ctx    Context.
	 * @param int    $depth  Depth.
	 * @param string $label  The label of the item (post_title).
	 * @return array|string|null
	 */
	public static function target( $type, $object, $id, $url, array $ctx, $depth = 0, $label = '' ) {
		switch ( $type ) {
			case 'post_type':
				return self::post( (int) $id, $ctx, $depth + 1 );
			case 'taxonomy':
				return self::term( (int) $id, $object );
			case 'custom':
				$acc = AB_MCP_Sync_Canon::acc();
				return array( 'custom', AB_MCP_Sync_Canon::text( (string) $url, $ctx, array(), $acc, 'meta:_menu_item_url', true ), (string) $label );
		}
		return array( (string) $type, (string) $object );
	}

	/* ----------------------------------------------------------- private */

	/**
	 * Whether a post is the only one of its type, slug, parent (and theme)
	 * on this site; false where that is not read.
	 *
	 * @param int    $id     Post id.
	 * @param string $type   Type.
	 * @param string $name   Slug.
	 * @param int    $parent Parent.
	 * @param string $theme  Theme ('' for a type without one).
	 * @return bool
	 */
	private static function alone( $id, $type, $name, $parent, $theme ) {
		$same = AB_MCP_Sync_Rows::posts_named( $type, $name, $parent );
		if ( ! is_array( $same ) || ! in_array( $id, $same, true ) ) {
			return false;
		}
		foreach ( $same as $other ) {
			// A template of another theme is another identity.
			if ( $other !== $id && ( '' === $theme || self::theme( $other ) === $theme ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The identity of a menu item where no other item of its menus has the
	 * same; null else.
	 *
	 * @param int        $id       Post id.
	 * @param array|null $identity Its identity as it is (menu_item()).
	 * @param array      $ctx      Context.
	 * @param int        $depth    Depth.
	 * @return array|null
	 */
	private static function unique_item( $id, $identity, array $ctx, $depth ) {
		if ( null === $identity ) {
			return null;
		}
		$tax = AB_MCP_Sync_Rows::object_terms( (int) $id );
		if ( null === $tax ) {
			return null;
		}
		$mine = AB_MCP_Sync_Canon::encode( $identity );
		foreach ( $tax as $term ) {
			if ( 'nav_menu' !== $term['taxonomy'] ) {
				continue;
			}
			$items = AB_MCP_Sync_Rows::term_objects( (int) $term['term_id'], 'nav_menu' );
			if ( null === $items ) {
				return null;
			}
			foreach ( $items as $other ) {
				if ( (int) $other === (int) $id ) {
					continue;
				}
				$theirs = self::menu_item( (int) $other, $ctx, $depth );
				if ( null !== $theirs && AB_MCP_Sync_Canon::encode( $theirs ) === $mine ) {
					return null;
				}
			}
		}
		return $identity;
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
		$post  = self::post( $n, $ctx );
		$there = self::$there[ $n ] ?? null;
		$terms = AB_MCP_Sync_Rows::term_taxonomies( $n );
		$out   = null;
		if ( null !== $there && is_array( $terms ) && $there !== ( array() !== $terms ) ) {
			$out = $there ? $post : self::term( $n );
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
	 * The numbers of a leaf the canonical form noted as possible references,
	 * each by the identity of what it hits (marked as possible), or as it is;
	 * null where the place was not noted or no number hits an object.
	 *
	 * @param mixed  $value Leaf.
	 * @param string $at    Where it stands.
	 * @param array  $set   The places noted.
	 * @param array  $ctx   Context.
	 * @param bool   $hit   Set where a number names an object.
	 * @return array|null
	 */
	private static function numbers_of( $value, $at, array $set, array $ctx, &$hit ) {
		if ( ! isset( $set[ $at ] ) || ( ! is_int( $value ) && ! is_string( $value ) ) ) {
			return null;
		}
		$out = array();
		$any = false;
		foreach ( AB_MCP_Sync_Refs::numbers( $value ) as $n ) {
			$as    = self::hit( $n, $ctx );
			$any   = $any || null !== $as;
			$out[] = null === $as ? $n : array( 'as' => $as );
		}
		if ( ! $any ) {
			return null;
		}
		$hit = true;
		return array( '~possible' => $out );
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
