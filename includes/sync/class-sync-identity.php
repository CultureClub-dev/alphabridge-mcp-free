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
 * THE IDENTITY of a target, by its kind, as the SHA-256 of what names it:
 *  - a post: its type, its theme (templates, template parts and global
 *    styles only, from the taxonomy wp_theme), its slug and language, and
 *    the identity of its parent; none for a post without a slug;
 *  - an attachment: its file under the uploads (_wp_attached_file) and its
 *    language, as the media kind will pair it (branch 10; read here, not
 *    paired);
 *  - a menu item: the slugs of its menus, its type and object, its target
 *    (the identity of the post or term it shows; for a custom link the
 *    neutral form of its address and its label; else its type and object)
 *    and the identity of its parent item;
 *  - a term: its taxonomy, slug and language.
 * The language (Codex round 3, B2) where the rows of the site say it: the
 * taxonomies language and term_language of Polylang, else the table
 * icl_translations of WPML; '' for none, none where they say more than one.
 *
 * ONE PURE FUNCTION OF THE WHOLE SITE (Codex round 3, 1): the build reads,
 * in pages over the whole site, what names each post, attachment, menu item
 * and term (kind, slug, file, target, the id of its parent), then works the
 * identities out level by level of the parents, each level from the
 * finished identities of the one above, and then asks over these finished
 * identities whether another object of the site has the same one. An
 * identity another object has as well names none of them (Codex round 2,
 * A-1), and each of them is a twin (twin(): two custom links «#» with one
 * label under one parent, two posts of one type, slug and language under one
 * parent, two attachments of one file and language, two terms of one
 * taxonomy, slug and language). Nothing is kept before it is final: the order
 * of reading cannot change an identity.
 *
 * COMPACT (Codex round 4, B): the index holds, for each post, its id, its
 * language and the SHA-256 of its identity in one record of POST bytes, for
 * each term one of TERM bytes, sorted by id and found by halving; while it is
 * built, each post read is one record of READ bytes with the id of its
 * parent and the SHA-256 of what names it but its parent, and each count is
 * one per SHA-256. For 20 000 posts and 50 000 attachments that is some
 * 3 MiB (SyncIdentityTest).
 *
 * IN PARTS (Codex round 4, B): build() goes as far as the time of the
 * call reaches, a page of rows after the other; stored() gives what was built
 * so far, or the finished index, as text, and reset() takes it up in the next
 * call, the way the inventory goes on from its cursor. An index begun in one
 * call of a run is the index of the whole run (AB_MCP_Sync_Inventory):
 * the site as it was when the run read it (GRENZEN-BERICHT.md).
 *
 * A table that cannot be read leaves every identity none (build() says
 * which); a target that is not there, has no slug, is not read or has the
 * identity of another has none: the mapped form is then none (null), and the
 * object counts as not equal (a reference without a resolvable target is a
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

	/** The format of an index as stored() gives it. */
	const FORMAT = 'ab-sync-identity/1';

	/** Bytes of a slice of what stored() gives, as a file of the store holds it (8 MiB). */
	const SLICE = 8388608;

	/** Bytes of a slice; SLICE when null. Set by tests only. */
	public static $slice = null;

	/** The steps of the build, in order; a step reads a page at a time. */
	const STEPS = array( 'pll_post', 'pll_term', 'wpml', 'themes', 'menus', 'files', 'posts', 'terms', 'items', 'compute' );

	/** The taxonomies the steps of assignments read. */
	const RELATIONS = array(
		'pll_post' => 'language',
		'pll_term' => 'term_language',
		'themes'   => 'wp_theme',
		'menus'    => 'nav_menu',
	);

	/** Bytes of a post of the index: id (8), flags (1), language (2), identity (32). */
	const POST = 43;

	/** Bytes of a term: term id (8), term_taxonomy_id (8), taxonomy (2), language (2), flags (1), identity (32). */
	const TERM = 53;

	/** Bytes of a post as the build reads it: id (8), kind (1), parent (8), language (2), what names it but its parent (32). */
	const READ = 51;

	/** A flag of a record: it has an identity. */
	const HAS = 1;

	/** A flag of a record: another object of the site has the same identity. */
	const TWIN = 2;

	/** The language of a record that names more than one. */
	const MANY = 65535;

	/** A post read: named by type, theme, slug and language; without; an attachment with a file; without; a menu item. */
	const NAMED = 0;
	const UNNAMED = 1;
	const FILE = 2;
	const NO_FILE = 3;
	const ITEM = 4;

	/** @var array|null The context of the index (the neutral addresses of a custom link). */
	private static $ctx = null;

	/** @var string|null The print of that context, which a stored index carries. */
	private static $print = null;

	/** @var int The step of the build (STEPS), count( STEPS ) once done. */
	private static $at = 0;

	/** @var int Where the step goes on: the id, meta_id, row it read last. */
	private static $after = 0;

	/** @var int The term_taxonomy_id of the assignment it read last. */
	private static $after_ttid = 0;

	/** @var bool|null Whether the site has the table of WPML; null until asked. */
	private static $wpml = null;

	/** @var string[] The language codes; a record names one by its place + 1, '' by 0. */
	private static $codes = array();

	/** @var array<string,int> Code => its number in records. */
	private static $code_of = array();

	/** @var string[] The taxonomies; a term record names one by its place. */
	private static $taxes = array();

	/** @var array<string,int> Taxonomy => its place. */
	private static $tax_of = array();

	/** @var array<string,array<int,int>> The languages read: pll_post, pll_term (by term id), wpml_post, wpml_tax (by term_taxonomy_id). */
	private static $langs = array();

	/** @var array<int,string|null> Post id => the slug of its theme, null for more than one. */
	private static $themes = array();

	/** @var array<int,string[]> Menu item id => the slugs of its menus. */
	private static $menus = array();

	/** @var array<int,string|false> Post id => what names its file (binary SHA-256), false for none. */
	private static $files = array();

	/** @var string The posts read, READ records by id. */
	private static $read = '';

	/** @var string The terms read, TERM records by term_taxonomy_id (the identity what names them). */
	private static $read_terms = '';

	/** @var array<int,array> Menu item id => p (parent item), o (object id), t (type), b (object), c (a custom target, hex). */
	private static $items = array();

	/** @var string The index of the posts: POST records by id. */
	private static $posts = '';

	/** @var string The index of the terms: TERM records by term id, then term_taxonomy_id. */
	private static $terms = '';

	/** @var array<int,array{0:string,1:string}> Menu item id => the identities of its target and parent (hex). */
	private static $lines = array();

	/** @var bool Whether the build went on since reset(). */
	private static $changed = false;

	/** @var string|null The table that could not be read: every identity is none. */
	private static $failed = null;

	/** @var array<int,string|null> What a whole number hits, by number. */
	private static $hits = array();

	/**
	 * Forget what was read: a new run, or the run of a stored index.
	 *
	 * @param array|null  $ctx    The context of the canonical form of the
	 *                            run; null until the first question.
	 * @param string|null $stored What stored() gave in a call before of the
	 *                            same run; one of another format or context
	 *                            is none (the build begins anew).
	 */
	public static function reset( $ctx = null, $stored = null ) {
		self::$ctx        = is_array( $ctx ) ? $ctx : null;
		self::$print      = is_array( $ctx ) ? self::print_of_context( $ctx ) : null;
		self::$at         = 0;
		self::$after      = 0;
		self::$after_ttid = 0;
		self::$wpml       = null;
		self::$codes      = array();
		self::$code_of    = array();
		self::$taxes      = array();
		self::$tax_of     = array();
		self::$langs      = array(
			'pll_post'  => array(),
			'pll_term'  => array(),
			'wpml_post' => array(),
			'wpml_tax'  => array(),
		);
		self::$themes     = array();
		self::$menus      = array();
		self::$files      = array();
		self::$read       = '';
		self::$read_terms = '';
		self::$items      = array();
		self::$posts      = '';
		self::$terms      = '';
		self::$lines      = array();
		self::$changed    = false;
		self::$failed     = null;
		self::$hits       = array();
		if ( is_string( $stored ) && null !== self::$print && ! self::load( $stored ) ) {
			self::reset( $ctx );
		}
	}

	/**
	 * Build the index as far as the time reaches: a page of rows after the
	 * other, at least one a call, until it is done.
	 *
	 * @param callable|null $out_of_time function(): bool, asked before each
	 *                                   page but the first; null for no end.
	 * @return bool|string true once done, false where the time ran out first,
	 *                     the table that could not be read (every identity
	 *                     is then none).
	 */
	public static function build( $out_of_time = null ) {
		if ( null !== self::$failed ) {
			return self::$failed;
		}
		if ( null === self::$ctx ) {
			self::$ctx   = AB_MCP_Sync_Canon::context( array() );
			self::$print = self::print_of_context( self::$ctx );
		}
		$worked = false;
		while ( self::$at < count( self::STEPS ) ) {
			if ( $worked && null !== $out_of_time && call_user_func( $out_of_time ) ) {
				return false;
			}
			$worked        = true;
			self::$changed = true;
			$step          = self::work();
			if ( true !== $step ) {
				self::$failed = (string) $step;
				self::$at     = count( self::STEPS );
				self::$read   = '';
				self::$files  = array();
				self::$items  = array();
				self::$posts  = '';
				self::$terms  = '';
				self::$lines  = array();
				return self::$failed;
			}
		}
		return true;
	}

	/**
	 * What to keep for the next call of the run: the index as far as it is
	 * built, or done, as text (FORMAT); null where nothing was built since
	 * reset() (a stored index taken up as it was) or a table could not be
	 * read.
	 *
	 * @return string|null
	 */
	public static function stored() {
		if ( ! self::$changed || null !== self::$failed || null === self::$print ) {
			return null;
		}
		$head = array(
			'format'     => self::FORMAT,
			'print'      => self::$print,
			'at'         => self::$at,
			'after'      => self::$after,
			'after_ttid' => self::$after_ttid,
			'wpml'       => self::$wpml,
			'codes'      => array_map( 'bin2hex', self::$codes ),
			'taxes'      => array_map( 'bin2hex', self::$taxes ),
		);
		if ( self::$at >= count( self::STEPS ) ) {
			$head['lines'] = self::$lines;
			$sections      = array(
				'posts' => self::$posts,
				'terms' => self::$terms,
			);
		} else {
			$head['themes'] = array();
			foreach ( self::$themes as $id => $slug ) {
				$head['themes'][ $id ] = null === $slug ? null : bin2hex( $slug );
			}
			$head['menus'] = array();
			foreach ( self::$menus as $id => $slugs ) {
				$head['menus'][ $id ] = array_map( 'bin2hex', $slugs );
			}
			$head['items'] = self::$items;
			$sections      = array();
			foreach ( self::$langs as $name => $map ) {
				$packed = array();
				foreach ( $map as $id => $code ) {
					$packed[] = pack( 'Jn', $id, $code );
				}
				$sections[ $name ] = implode( '', $packed );
			}
			$packed = array();
			foreach ( self::$files as $id => $part ) {
				$packed[] = pack( 'J', $id ) . ( false === $part ? "\0" . self::zero() : "\1" . $part );
			}
			$sections['files']      = implode( '', $packed );
			unset( $packed );
			$sections['read']       = self::$read;
			$sections['read_terms'] = self::$read_terms;
		}
		$head['sections'] = array_map( 'strlen', $sections );
		$json             = json_encode( $head, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the head of a file of this class, every text in hex.
		return is_string( $json ) ? $json . "\n" . implode( '', $sections ) : null;
	}

	/**
	 * Bytes of a slice of what stored() gives: the callers keep it in files
	 * of at most this size.
	 *
	 * @return int
	 */
	public static function slice() {
		return null === self::$slice ? self::SLICE : max( 1, (int) self::$slice );
	}

	/**
	 * The identity of a post of this site (an attachment, a menu item too),
	 * as a SHA-256 in hex; null where it has none or another object of the
	 * site has the same.
	 *
	 * @param int   $id  Post id.
	 * @param array $ctx The context of the canonical form (the neutral addresses).
	 * @return string|null
	 */
	public static function post( $id, array $ctx ) {
		$record = self::post_record( $id, $ctx );
		return null === $record || 0 === ( $record['flags'] & self::HAS ) ? null : bin2hex( $record['identity'] );
	}

	/**
	 * The identity of a term of this site, as a SHA-256 in hex; null where
	 * it has none or another term of its taxonomy has the same slug and
	 * language.
	 *
	 * @param int         $id       Term id.
	 * @param array       $ctx      Context.
	 * @param string|null $taxonomy Its taxonomy; null where the reference
	 *                              does not say (then the one taxonomy the
	 *                              term id has rows in).
	 * @return string|null
	 */
	public static function term( $id, array $ctx, $taxonomy = null ) {
		$record = self::term_record( $id, $ctx, $taxonomy, 0 );
		return null === $record || 0 === ( $record['flags'] & self::HAS ) ? null : bin2hex( $record['identity'] );
	}

	/**
	 * The identity of the line of a menu item: of what it shows and of the
	 * item it hangs under (SHA-256 in hex; at the top that of «none»), as it
	 * is before the question whether another item has the same; null where
	 * its target or parent has none.
	 *
	 * @param int   $id  Post id.
	 * @param array $ctx Context.
	 * @return array{0:string,1:string}|null target, parent.
	 */
	public static function menu_item( $id, array $ctx ) {
		self::ready( $ctx );
		return self::$lines[ (int) $id ] ?? null;
	}

	/**
	 * Whether another object of this site has the identity of an object of
	 * the inventory (Codex round 4: each line of such an identity is
	 * uncertain, also in a package that holds only one of them): a post or
	 * menu item by its id, a term or menu by its id and taxonomy.
	 *
	 * @param array $result AB_MCP_Sync_Canon::finish(): kind, ref, identity.
	 * @param array $ctx    Context.
	 * @return bool
	 */
	public static function twin( array $result, array $ctx ) {
		$kind = isset( $result['kind'] ) ? (string) $result['kind'] : '';
		$ref  = isset( $result['ref'] ) ? (int) $result['ref'] : 0;
		if ( 'post' === $kind || 'menu_item' === $kind ) {
			$record = self::post_record( $ref, $ctx );
		} elseif ( 'term' === $kind || 'menu' === $kind ) {
			$taxonomy = isset( $result['identity']['taxonomy'] ) ? (string) $result['identity']['taxonomy'] : ( 'menu' === $kind ? 'nav_menu' : null );
			$record   = null === $taxonomy ? null : self::term_record( $ref, $ctx, $taxonomy, 0 );
		} else {
			return false;
		}
		return null !== $record && 0 !== ( $record['flags'] & self::TWIN );
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
		$record = 'term' === $space ? self::term_record( $id, $ctx, null, (int) $ttid ) : self::post_record( $id, $ctx );
		return null === $record ? '' : self::language_of( $record['language'] );
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
				$form['meta'][ $i ][1] = self::stored_value( $entry[1], 'meta:' . $entry[0], $set, $ctx, $hit, 0 );
			}
		}
		return $hit ? self::mapped_hash( $form, $ctx ) : null;
	}

	/**
	 * The SHA-256 of what names an object (AB_MCP_Sync_Canon::encode() of
	 * its parts), binary: the identity of an attachment, term or menu item,
	 * and what names a post but its parent.
	 *
	 * @param array $parts Parts.
	 * @return string 32 bytes.
	 */
	public static function part( array $parts ) {
		return hash( 'sha256', AB_MCP_Sync_Canon::encode( $parts ), true );
	}

	/**
	 * The identity of a post from what names it but its parent (part()) and
	 * the identity of its parent ('' at the top), binary.
	 *
	 * @param string $part   What names it (32 bytes).
	 * @param string $parent The identity of its parent (32 bytes), '' for none.
	 * @return string 32 bytes.
	 */
	public static function under( $part, $parent ) {
		return self::part( array( 'post', bin2hex( $part ), '' === $parent ? '' : bin2hex( $parent ) ) );
	}

	/**
	 * The identity of the parent of a menu item at the top, in hex.
	 *
	 * @return string
	 */
	public static function top() {
		return hash( 'sha256', AB_MCP_Sync_Canon::encode( 'none' ) );
	}

	/* ------------------------------------------------------------ build */

	/**
	 * One page of the step the build is at; the next step once it is read
	 * to its end.
	 *
	 * @return true|string The table that could not be read.
	 */
	private static function work() {
		$step = self::STEPS[ self::$at ];
		switch ( $step ) {
			case 'pll_post':
			case 'pll_term':
			case 'themes':
			case 'menus':
				$rows = AB_MCP_Sync_Rows::relations( self::RELATIONS[ $step ], self::$after, self::$after_ttid, AB_MCP_Sync_Rows::INDEX_PAGE );
				if ( null === $rows ) {
					return 'term_relationships';
				}
				foreach ( $rows as $row ) {
					$id = $row['object_id'];
					if ( 'themes' === $step ) {
						self::$themes[ $id ] = array_key_exists( $id, self::$themes ) ? null : $row['slug'];
					} elseif ( 'menus' === $step ) {
						self::$menus[ $id ][] = $row['slug'];
					} else {
						// Polylang gives an object one language: a second names none.
						self::$langs[ $step ][ $id ] = isset( self::$langs[ $step ][ $id ] ) ? self::MANY : self::code( $row['slug'] );
					}
					self::$after      = $id;
					self::$after_ttid = $row['ttid'];
				}
				return self::paged( count( $rows ), AB_MCP_Sync_Rows::INDEX_PAGE );
			case 'wpml':
				if ( null === self::$wpml ) {
					$there = AB_MCP_Sync_Rows::wpml_table();
					if ( null === $there ) {
						return 'icl_translations';
					}
					self::$wpml = $there;
					return $there ? true : self::paged( 0, 1 );
				}
				$rows = AB_MCP_Sync_Rows::wpml_languages( self::$after, AB_MCP_Sync_Rows::INDEX_PAGE );
				if ( null === $rows ) {
					return 'icl_translations';
				}
				foreach ( $rows as $row ) {
					$space = 0 === strpos( $row['type'], 'post_' ) ? 'wpml_post' : ( 0 === strpos( $row['type'], 'tax_' ) ? 'wpml_tax' : null );
					if ( null !== $space ) {
						// Rows of one language name it; of two, none.
						$code                          = self::code( $row['language'] );
						self::$langs[ $space ][ $row['id'] ] = ! isset( self::$langs[ $space ][ $row['id'] ] ) || $code === self::$langs[ $space ][ $row['id'] ] ? $code : self::MANY;
					}
					self::$after = $row['row'];
				}
				return self::paged( count( $rows ), AB_MCP_Sync_Rows::INDEX_PAGE );
			case 'files':
				$rows = AB_MCP_Sync_Rows::meta_by_key( '_wp_attached_file', self::$after, AB_MCP_Sync_Rows::INDEX_PAGE );
				if ( null === $rows ) {
					return 'postmeta';
				}
				foreach ( $rows as $row ) {
					$id = $row['post_id'];
					if ( array_key_exists( $id, self::$files ) ) {
						// Two files of one attachment: none.
						self::$files[ $id ] = false;
					} else {
						$lang               = self::language_of( self::post_language( $id ) );
						self::$files[ $id ] = '' === $row['value'] || null === $lang ? false : self::part( array( 'attachment', $row['value'], $lang ) );
					}
					self::$after = $row['meta_id'];
				}
				return self::paged( count( $rows ), AB_MCP_Sync_Rows::INDEX_PAGE );
			case 'posts':
				$rows = AB_MCP_Sync_Rows::post_index( self::$after, AB_MCP_Sync_Rows::INDEX_PAGE );
				if ( null === $rows ) {
					return 'posts';
				}
				$page = array();
				foreach ( $rows as $row ) {
					$page[]      = self::read_post( $row );
					self::$after = $row['ID'];
				}
				self::$read .= implode( '', $page );
				if ( count( $rows ) < AB_MCP_Sync_Rows::INDEX_PAGE ) {
					// What is left names no attachment.
					self::$files = array();
				}
				return self::paged( count( $rows ), AB_MCP_Sync_Rows::INDEX_PAGE );
			case 'terms':
				$rows = AB_MCP_Sync_Rows::terms( null, self::$after, AB_MCP_Sync_Rows::MAX_PAGE );
				if ( null === $rows ) {
					return 'terms';
				}
				$page = array();
				foreach ( $rows as $row ) {
					$id     = (int) $row['term_id'];
					$ttid   = (int) $row['term_taxonomy_id'];
					$code   = self::term_language( $id, $ttid );
					$lang   = self::language_of( $code );
					$none   = '' === (string) $row['slug'] || null === $lang;
					$page[] = pack( 'JJnn', $id, $ttid, self::tax( (string) $row['taxonomy'] ), $code ) . chr( $none ? 0 : self::HAS ) . ( $none ? self::zero() : self::part( array( 'term', (string) $row['taxonomy'], (string) $row['slug'], $lang ) ) );
					self::$after = $ttid;
				}
				self::$read_terms .= implode( '', $page );
				return self::paged( count( $rows ), AB_MCP_Sync_Rows::MAX_PAGE );
			case 'items':
				$rows = AB_MCP_Sync_Rows::menu_items( self::ITEM_META );
				if ( null === $rows ) {
					return 'postmeta';
				}
				self::read_items( $rows );
				return self::paged( 0, 1 );
			default:
				self::compute();
				return self::paged( 0, 1 );
		}
	}

	/**
	 * After a page of a step: the next step where it was the last.
	 *
	 * @param int $rows Rows of the page.
	 * @param int $size Rows of a full page.
	 * @return true
	 */
	private static function paged( $rows, $size ) {
		if ( $rows < $size ) {
			++self::$at;
			self::$after      = 0;
			self::$after_ttid = 0;
		}
		return true;
	}

	/**
	 * The READ record of a post: an attachment by its file and language
	 * (files), a menu item for the step items, any other by its type, theme,
	 * slug and language; none without a slug, with more than one theme or
	 * language.
	 *
	 * @param array $row ID, post_type, post_name, post_parent.
	 * @return string READ bytes.
	 */
	private static function read_post( array $row ) {
		$id   = (int) $row['ID'];
		$code = self::post_language( $id );
		$part = self::zero();
		if ( 'attachment' === $row['post_type'] ) {
			$file = self::$files[ $id ] ?? false;
			unset( self::$files[ $id ] );
			$kind = false === $file ? self::NO_FILE : self::FILE;
			$part = false === $file ? $part : $file;
		} elseif ( 'nav_menu_item' === $row['post_type'] ) {
			$kind = self::ITEM;
		} else {
			$theme = '';
			if ( in_array( $row['post_type'], self::THEMED, true ) ) {
				$theme = array_key_exists( $id, self::$themes ) ? self::$themes[ $id ] : '';
			}
			$lang = self::language_of( $code );
			$kind = '' === $row['post_name'] || null === $theme || null === $lang ? self::UNNAMED : self::NAMED;
			if ( self::NAMED === $kind ) {
				$part = self::part( array( 'post', $row['post_type'], $theme, $row['post_name'], $lang ) );
			}
		}
		return pack( 'JCJn', $id, $kind, (int) $row['post_parent'], $code ) . $part;
	}

	/**
	 * What names the menu items: for each the first value of each key, its
	 * label, the slugs of its menus; a custom link's target at once (its
	 * address needs the context).
	 *
	 * @param array $rows AB_MCP_Sync_Rows::menu_items().
	 */
	private static function read_items( array $rows ) {
		$meta = array();
		foreach ( $rows['meta'] as $row ) {
			if ( ! isset( $meta[ $row['post_id'] ][ $row['key'] ] ) ) {
				$meta[ $row['post_id'] ][ $row['key'] ] = $row['value'];
			}
		}
		foreach ( $rows['titles'] as $id => $title ) {
			$m      = $meta[ $id ] ?? array();
			$type   = (string) ( $m['_menu_item_type'] ?? '' );
			$custom = null;
			if ( 'custom' === $type ) {
				$acc    = AB_MCP_Sync_Canon::acc();
				$custom = bin2hex( self::part( array( 'custom', AB_MCP_Sync_Canon::text( (string) ( $m['_menu_item_url'] ?? '' ), self::$ctx, array(), $acc, 'meta:_menu_item_url', true ), (string) $title ) ) );
			}
			self::$items[ (int) $id ] = array(
				'p' => isset( $m['_menu_item_menu_item_parent'] ) ? (int) $m['_menu_item_menu_item_parent'] : 0,
				'o' => (int) ( $m['_menu_item_object_id'] ?? 0 ),
				't' => bin2hex( $type ),
				'b' => bin2hex( (string) ( $m['_menu_item_object'] ?? '' ) ),
				'c' => $custom,
			);
		}
	}

	/**
	 * The second pass: the identities level by level, each unique over its
	 * level, into the records of the index; what the build read is let go.
	 */
	private static function compute() {
		// Attachments: the files and languages more than one has; posts:
		// where each is read, and its parent.
		$files  = self::twice( self::$read, self::READ, 19, self::FILE );
		$parent = array();
		$at     = array();
		$offset = 0;
		foreach ( self::records( self::$read, self::READ ) as $rec ) {
			$kind = ord( $rec[8] );
			if ( self::NAMED === $kind || self::UNNAMED === $kind ) {
				$head                  = unpack( 'Jid/x/Jparent', $rec );
				$parent[ $head['id'] ] = $head['parent'];
				$at[ $head['id'] ]     = $offset;
			}
			$offset += self::READ;
		}
		$twins = array();
		// Posts, level by level of their parents: a parent is final once it
		// is of a level above, or an attachment.
		$final = self::levels(
			$parent,
			static function ( $id, $up, $above ) use ( $at ) {
				$offset = $at[ $id ];
				if ( self::NAMED !== ord( self::$read[ $offset + 8 ] ) || false === $above ) {
					return false;
				}
				return self::under( substr( self::$read, $offset + 19, 32 ), 0 === $up ? '' : $above );
			},
			static function ( $up, array $final ) use ( $files ) {
				return array_key_exists( $up, $final ) ? $final[ $up ] : self::attachment( $up, $files );
			},
			$twins
		);
		unset( $parent, $at );

		// Terms: taxonomy, slug and language, unique.
		$doubled = self::twice( self::$read_terms, self::TERM, 21, null );
		$sorted  = array();
		$tfinal  = array();
		foreach ( self::records( self::$read_terms, self::TERM ) as $rec ) {
			$head  = unpack( 'Jid/Jttid/ntax', $rec );
			$named = 0 !== ( ord( $rec[20] ) & self::HAS );
			$twin  = $named && isset( $doubled[ substr( $rec, 21, 32 ) ] );
			$has   = $named && ! $twin;
			$sorted[ substr( $rec, 0, 16 ) ] = substr( $rec, 0, 20 ) . chr( ( $has ? self::HAS : 0 ) | ( $twin ? self::TWIN : 0 ) ) . ( $has ? substr( $rec, 21, 32 ) : self::zero() );
			$tfinal[ $head['id'] . "\0" . self::$taxes[ $head['tax'] ] ] = $has ? substr( $rec, 21, 32 ) : false;
		}
		ksort( $sorted, SORT_STRING );
		self::$terms      = implode( '', $sorted );
		self::$read_terms = '';
		unset( $sorted, $doubled );

		// Menu items, level by level of their parent items.
		$up = array();
		foreach ( self::$items as $id => $item ) {
			$up[ $id ] = $item['p'];
		}
		$items  = self::$items;
		$lines  = array();
		$itwins = array();
		$ifinal = self::levels(
			$up,
			static function ( $id, $parent, $above ) use ( $items, $final, $files, $tfinal, &$lines ) {
				if ( false === $above ) {
					return false;
				}
				$item = $items[ $id ];
				$type = (string) hex2bin( $item['t'] );
				$of   = (string) hex2bin( $item['b'] );
				switch ( $type ) {
					case 'post_type':
						$target = array_key_exists( $item['o'], $final ) ? $final[ $item['o'] ] : self::attachment( $item['o'], $files );
						$target = is_string( $target ) ? bin2hex( $target ) : null;
						break;
					case 'taxonomy':
						$target = $tfinal[ $item['o'] . "\0" . $of ] ?? false;
						$target = is_string( $target ) ? bin2hex( $target ) : null;
						break;
					case 'custom':
						$target = $item['c'];
						break;
					default:
						$target = bin2hex( self::part( array( $type, $of ) ) );
				}
				if ( null === $target ) {
					return false;
				}
				$slugs = self::$menus[ $id ] ?? array();
				sort( $slugs, SORT_STRING );
				$top          = 0 === $parent ? self::top() : bin2hex( $above );
				$lines[ $id ] = array( $target, $top );
				return self::part( array( 'nav_menu_item', $slugs, $type, $of, $target, $top ) );
			},
			static function ( $parent, array $final ) {
				return array_key_exists( $parent, $final ) ? $final[ $parent ] : null;
			},
			$itwins
		);
		self::$lines = $lines;
		self::$items = array();
		self::$menus = array();

		// The records, by id, a few thousand at a time.
		self::$posts = '';
		$out         = array();
		foreach ( self::records( self::$read, self::READ ) as $rec ) {
			$head = unpack( 'Jid/Ckind/Jparent/nlang', $rec );
			$id   = $head['id'];
			$twin = false;
			switch ( $head['kind'] ) {
				case self::FILE:
					$file     = substr( $rec, 19, 32 );
					$twin     = isset( $files[ $file ] );
					$identity = $twin ? false : $file;
					break;
				case self::ITEM:
					$identity = $ifinal[ $id ] ?? false;
					$twin     = isset( $itwins[ $id ] );
					break;
				case self::NAMED:
				case self::UNNAMED:
					$identity = $final[ $id ] ?? false;
					$twin     = isset( $twins[ $id ] );
					break;
				default:
					$identity = false;
			}
			$out[] = pack( 'JCn', $id, ( false === $identity ? 0 : self::HAS ) | ( $twin ? self::TWIN : 0 ), $head['lang'] ) . ( false === $identity ? self::zero() : $identity );
			if ( count( $out ) >= 4096 ) {
				self::$posts .= implode( '', $out );
				$out          = array();
			}
		}
		self::$posts .= implode( '', $out );
		unset( $out, $final, $files );
		self::$read   = '';
		self::$files  = array();
		self::$themes = array();
		self::$langs  = array(
			'pll_post'  => array(),
			'pll_term'  => array(),
			'wpml_post' => array(),
			'wpml_tax'  => array(),
		);
	}

	/**
	 * The parts of records (32 bytes at an offset) that more than one record
	 * has: counted by their first 8 bytes, then by the whole part only where
	 * those are there more than once, so a count holds an integer per record.
	 *
	 * @param string   $records Records.
	 * @param int      $width   Bytes of one.
	 * @param int      $offset  Where its part begins.
	 * @param int|null $kind    Only records of this kind (the byte at 8), null for those with HAS (the byte at 20).
	 * @return array<string,true> Part => true.
	 */
	private static function twice( $records, $width, $offset, $kind ) {
		$which = static function ( $rec ) use ( $kind ) {
			return null === $kind ? 0 !== ( ord( $rec[20] ) & self::HAS ) : $kind === ord( $rec[8] );
		};
		$first = array();
		foreach ( self::records( $records, $width ) as $rec ) {
			if ( $which( $rec ) ) {
				$key           = unpack( 'J', $rec, $offset )[1];
				$first[ $key ] = isset( $first[ $key ] );
			}
		}
		$whole = array();
		foreach ( self::records( $records, $width ) as $rec ) {
			if ( $which( $rec ) && $first[ unpack( 'J', $rec, $offset )[1] ] ) {
				$part           = substr( $rec, $offset, 32 );
				$whole[ $part ] = isset( $whole[ $part ] );
			}
		}
		return array_filter( $whole );
	}

	/**
	 * The identities of objects whose identity holds that of their parent,
	 * level by level: the objects under no parent first, then those whose
	 * parent is final; at each level the identities made from the finished
	 * ones above, then each that another of the level has as well made none
	 * and a twin. An object whose parent is not there, has none, or leads
	 * back to it has none.
	 *
	 * @param array<int,int> $open   id => its parent (0 for none), not worked out yet.
	 * @param callable       $make   function( $id, $parent, $above ): string|false,
	 *                               $above the identity of the parent ('' at the top).
	 * @param callable       $above  function( $parent, $final ): string|false|null,
	 *                               the identity of a parent final in $final (a
	 *                               level above) or of another kind, null where
	 *                               it is not final.
	 * @param array          $twins  Set: id => true for each twin.
	 * @return array<int,string|false> id => identity, false for none; an object
	 *                                 left open is not in it (none).
	 */
	private static function levels( array $open, $make, $above, array &$twins ) {
		$final = array();
		for ( $level = 0; $level <= self::MAX_DEPTH && array() !== $open; ++$level ) {
			$made = array();
			foreach ( $open as $id => $up ) {
				if ( 0 === $up ) {
					$made[ $id ] = call_user_func( $make, $id, 0, '' );
					continue;
				}
				// Its parent is final: of a level above, or of a kind worked out before.
				$of = call_user_func( $above, $up, $final );
				if ( null !== $of ) {
					$made[ $id ] = call_user_func( $make, $id, $up, $of );
				}
			}
			if ( array() === $made ) {
				break;
			}
			$count = array_count_values( array_filter( $made, 'is_string' ) );
			foreach ( $made as $id => $identity ) {
				if ( false !== $identity && $count[ $identity ] > 1 ) {
					$identity      = false;
					$twins[ $id ] = true;
				}
				$final[ $id ] = $identity;
				unset( $open[ $id ] );
			}
		}
		// What is left (under a parent that is not there, too deep, or leading
		// back) has none: it is not in the result.
		return $final;
	}

	/**
	 * The identity of an attachment the build read, by its id: its file and
	 * language where no other attachment has them; false where another has
	 * them; null for no attachment with a file.
	 *
	 * @param int               $id    Post id.
	 * @param array<string,true> $twice The files and languages more than one attachment has.
	 * @return string|false|null
	 */
	private static function attachment( $id, array $twice ) {
		$at = self::find( self::$read, self::READ, $id );
		if ( null === $at ) {
			return null;
		}
		if ( self::FILE !== ord( self::$read[ $at + 8 ] ) ) {
			return null;
		}
		$file = substr( self::$read, $at + 19, 32 );
		return isset( $twice[ $file ] ) ? false : $file;
	}

	/* ----------------------------------------------------------- lookup */

	/**
	 * Build the index where it is not done, without an end (a single
	 * object read outside a run).
	 *
	 * @param array $ctx Context.
	 */
	private static function ready( array $ctx ) {
		if ( self::$at < count( self::STEPS ) ) {
			if ( null === self::$ctx ) {
				self::$ctx   = $ctx;
				self::$print = self::print_of_context( $ctx );
			}
			self::build();
		}
	}

	/**
	 * The record of a post: flags, language, identity (binary); null where
	 * the index has none (not there, a revision).
	 *
	 * @param int   $id  Post id.
	 * @param array $ctx Context.
	 * @return array{flags:int,language:int,identity:string}|null
	 */
	private static function post_record( $id, array $ctx ) {
		self::ready( $ctx );
		$at = self::find( self::$posts, self::POST, (int) $id );
		if ( null === $at ) {
			return null;
		}
		$head = unpack( 'Cflags/nlanguage', self::$posts, $at + 8 );
		return array(
			'flags'    => $head['flags'],
			'language' => $head['language'],
			'identity' => substr( self::$posts, $at + 11, 32 ),
		);
	}

	/**
	 * The record of a term: of its taxonomy, or of its term_taxonomy_id, or
	 * the only one of its id; null for none.
	 *
	 * @param int         $id       Term id.
	 * @param array       $ctx      Context.
	 * @param string|null $taxonomy Taxonomy.
	 * @param int         $ttid     term_taxonomy_id; 0 for any.
	 * @return array{flags:int,language:int,identity:string,taxonomy:string}|null
	 */
	private static function term_record( $id, array $ctx, $taxonomy, $ttid ) {
		self::ready( $ctx );
		$found = array();
		$at    = self::find( self::$terms, self::TERM, (int) $id, true );
		$key   = null === $at ? '' : substr( self::$terms, $at, 8 );
		for ( ; null !== $at && $at < strlen( self::$terms ) && substr( self::$terms, $at, 8 ) === $key; $at += self::TERM ) {
			$head = unpack( 'Jttid/ntax/nlanguage/Cflags', self::$terms, $at + 8 );
			$tax  = self::$taxes[ $head['tax'] ] ?? '';
			if ( ( null === $taxonomy || $tax === (string) $taxonomy ) && ( 0 === $ttid || $head['ttid'] === $ttid ) ) {
				$found[] = array(
					'flags'    => $head['flags'],
					'language' => $head['language'],
					'identity' => substr( self::$terms, $at + 21, 32 ),
					'taxonomy' => $tax,
				);
			}
		}
		return 1 === count( $found ) ? $found[0] : null;
	}

	/**
	 * The place of the record of an id in records sorted by it, by halving;
	 * with $first, the first record of that id.
	 *
	 * @param string $records Records.
	 * @param int    $width   Bytes of a record.
	 * @param int    $id      Id.
	 * @param bool   $first   The first of several records of one id.
	 * @return int|null Byte offset.
	 */
	private static function find( $records, $width, $id, $first = false ) {
		$key = pack( 'J', $id );
		$lo  = 0;
		$hi  = intdiv( strlen( $records ), $width ) - 1;
		$hit = null;
		while ( $lo <= $hi ) {
			$mid = ( $lo + $hi ) >> 1;
			$cmp = strcmp( substr( $records, $mid * $width, 8 ), $key );
			if ( 0 === $cmp ) {
				$hit = $mid * $width;
				if ( ! $first ) {
					return $hit;
				}
				$hi = $mid - 1;
			} elseif ( $cmp < 0 ) {
				$lo = $mid + 1;
			} else {
				$hi = $mid - 1;
			}
		}
		return $hit;
	}

	/**
	 * Records of a width, one after another.
	 *
	 * @param string $records Records.
	 * @param int    $width   Bytes of one.
	 * @return Generator<string>
	 */
	private static function records( $records, $width ) {
		$n = intdiv( strlen( $records ), $width );
		for ( $i = 0; $i < $n; $i++ ) {
			yield substr( $records, $i * $width, $width );
		}
	}

	/* -------------------------------------------------------- languages */

	/**
	 * The number of a language code in records ('' is 0).
	 *
	 * @param string $code Code.
	 * @return int
	 */
	private static function code( $code ) {
		$code = (string) $code;
		if ( '' === $code ) {
			return 0;
		}
		if ( ! isset( self::$code_of[ $code ] ) ) {
			self::$codes[]          = $code;
			self::$code_of[ $code ] = count( self::$codes );
		}
		return self::$code_of[ $code ];
	}

	/**
	 * The place of a taxonomy in records.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return int
	 */
	private static function tax( $taxonomy ) {
		if ( ! isset( self::$tax_of[ $taxonomy ] ) ) {
			self::$taxes[]             = $taxonomy;
			self::$tax_of[ $taxonomy ] = count( self::$taxes ) - 1;
		}
		return self::$tax_of[ $taxonomy ];
	}

	/**
	 * The language of a number of records: '' for 0, null for MANY.
	 *
	 * @param int $code Number.
	 * @return string|null
	 */
	private static function language_of( $code ) {
		if ( self::MANY === $code ) {
			return null;
		}
		return 0 === $code ? '' : (string) ( self::$codes[ $code - 1 ] ?? '' );
	}

	/**
	 * The language of a post as read: of Polylang where it names one, else
	 * of WPML, else none.
	 *
	 * @param int $id Post id.
	 * @return int A number of records.
	 */
	private static function post_language( $id ) {
		return self::$langs['pll_post'][ $id ] ?? ( self::$langs['wpml_post'][ $id ] ?? 0 );
	}

	/**
	 * The language of a term as read: of Polylang (by its id), else of WPML
	 * (by its term_taxonomy_id), else none.
	 *
	 * @param int $id   Term id.
	 * @param int $ttid term_taxonomy_id.
	 * @return int
	 */
	private static function term_language( $id, $ttid ) {
		return self::$langs['pll_term'][ $id ] ?? ( self::$langs['wpml_tax'][ $ttid ] ?? 0 );
	}

	/* ---------------------------------------------------------- storage */

	/**
	 * Take up what stored() gave: the format, the context and the sizes
	 * must be its own.
	 *
	 * @param string $text Text.
	 * @return bool Whether it was taken up.
	 */
	private static function load( $text ) {
		$nl   = strpos( $text, "\n" );
		$head = false === $nl ? null : json_decode( substr( $text, 0, $nl ), true, 16 );
		if ( ! is_array( $head ) || self::FORMAT !== ( $head['format'] ?? null ) || self::$print !== ( $head['print'] ?? null ) || ! is_int( $head['at'] ?? null ) || $head['at'] < 0 || $head['at'] > count( self::STEPS ) || ! is_array( $head['sections'] ?? null ) ) {
			return false;
		}
		$done  = count( self::STEPS ) === $head['at'];
		$names = $done ? array( 'posts', 'terms' ) : array( 'pll_post', 'pll_term', 'wpml_post', 'wpml_tax', 'files', 'read', 'read_terms' );
		if ( array_keys( $head['sections'] ) !== $names || strlen( $text ) - $nl - 1 !== array_sum( array_map( 'intval', $head['sections'] ) ) ) {
			return false;
		}
		$sections = array();
		$at       = $nl + 1;
		foreach ( $head['sections'] as $name => $bytes ) {
			$sections[ $name ] = (string) substr( $text, $at, (int) $bytes );
			$at               += (int) $bytes;
		}
		self::$at         = $head['at'];
		self::$after      = (int) ( $head['after'] ?? 0 );
		self::$after_ttid = (int) ( $head['after_ttid'] ?? 0 );
		self::$wpml       = isset( $head['wpml'] ) ? (bool) $head['wpml'] : null;
		self::$codes      = array_map( 'hex2bin', (array) ( $head['codes'] ?? array() ) );
		self::$code_of    = array();
		foreach ( self::$codes as $i => $code ) {
			self::$code_of[ $code ] = $i + 1;
		}
		self::$taxes  = array_map( 'hex2bin', (array) ( $head['taxes'] ?? array() ) );
		self::$tax_of = array_flip( self::$taxes );
		if ( $done ) {
			self::$posts = $sections['posts'];
			self::$terms = $sections['terms'];
			self::$lines = array();
			foreach ( (array) ( $head['lines'] ?? array() ) as $id => $line ) {
				self::$lines[ (int) $id ] = array( (string) $line[0], (string) $line[1] );
			}
			return 0 === strlen( self::$posts ) % self::POST && 0 === strlen( self::$terms ) % self::TERM;
		}
		foreach ( array( 'pll_post', 'pll_term', 'wpml_post', 'wpml_tax' ) as $name ) {
			foreach ( self::records( $sections[ $name ], 10 ) as $rec ) {
				$pair                              = unpack( 'Jid/ncode', $rec );
				self::$langs[ $name ][ $pair['id'] ] = $pair['code'];
			}
		}
		foreach ( self::records( $sections['files'], 41 ) as $rec ) {
			self::$files[ unpack( 'J', $rec )[1] ] = "\1" === $rec[8] ? substr( $rec, 9, 32 ) : false;
		}
		foreach ( (array) ( $head['themes'] ?? array() ) as $id => $slug ) {
			self::$themes[ (int) $id ] = null === $slug ? null : (string) hex2bin( $slug );
		}
		foreach ( (array) ( $head['menus'] ?? array() ) as $id => $slugs ) {
			self::$menus[ (int) $id ] = array_map( 'hex2bin', (array) $slugs );
		}
		foreach ( (array) ( $head['items'] ?? array() ) as $id => $item ) {
			self::$items[ (int) $id ] = $item;
		}
		self::$read       = $sections['read'];
		self::$read_terms = $sections['read_terms'];
		return 0 === strlen( self::$read ) % self::READ && 0 === strlen( self::$read_terms ) % self::TERM;
	}

	/**
	 * The print of a context: an index built with other addresses or routes
	 * is another index.
	 *
	 * @param array $ctx Context.
	 * @return string
	 */
	private static function print_of_context( array $ctx ) {
		unset( $ctx['users'] );
		return hash( 'sha256', AB_MCP_Sync_Canon::encode( $ctx ) );
	}

	/**
	 * @return string 32 bytes of zero.
	 */
	private static function zero() {
		return str_repeat( "\0", 32 );
	}

	/* ------------------------------------------------------ the forms */

	/**
	 * What a whole number hits on this site, by identity: the post or the
	 * term of that id, where only one of the two ID spaces has it and it has
	 * an identity; null else.
	 *
	 * @param int   $n   Number.
	 * @param array $ctx Context.
	 * @return string|null
	 */
	private static function hit( $n, array $ctx ) {
		$n = (int) $n;
		if ( array_key_exists( $n, self::$hits ) ) {
			return self::$hits[ $n ];
		}
		// A revision is not in the index; it is a post all the same.
		$there = null !== self::post_record( $n, $ctx ) ? true : AB_MCP_Sync_Rows::post_type_of( $n );
		$terms = null !== self::find( self::$terms, self::TERM, $n, true );
		$out   = null;
		if ( null !== $there && ( false !== $there ) !== $terms ) {
			$out = false !== $there ? self::post( $n, $ctx ) : self::term( $n, $ctx );
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
	private static function stored_value( $value, $at, array $set, array $ctx, &$hit, $layers ) {
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
			return self::stored_value( $value, $at, $set, $ctx, $hit, $layers );
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
