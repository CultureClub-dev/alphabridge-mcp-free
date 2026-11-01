<?php
/**
 * References with a known schema, and the whole numbers without one (Z8,
 * decision 12 of the plan of 01.11.2026).
 *
 * A REFERENCE is rewritten only where its schema is known from its name,
 * its path and the type it points to:
 * - attributes of core blocks (BLOCKS), the class wp-image-<id> and the
 *   data-id the save of a core block writes from such an id (HTML), and
 *   the same class in classic content (get_image_tag());
 * - the attributes of core shortcodes (SHORTCODES): gallery and playlist
 *   ids, caption id="attachment_<id>";
 * - the fields of the kinds' own rows (menu item meta by _menu_item_type,
 *   core options, menu positions, the core widgets with ids: the kinds and
 *   WIDGETS here);
 * - the addresses of the sites, in every form of the replace engine.
 * Blocks are read with the core's WP_Block_Parser (what parse_blocks()
 * runs, without the filter block_parser_class that would let a plugin
 * replace it) and written back with serialize_blocks().
 *
 * A POSSIBLE REFERENCE is a whole number (an int, or a text of digits or of
 * digits and commas) where no schema says what it is: an attribute of a
 * block that is not of the core, an attribute of the core that no schema
 * names, an attribute of another shortcode, a field of a value that no
 * schema covers. Numbers the core's schema declares as plain numbers
 * (PLAIN_NUMBERS, the block supports style and layout, the paging of a
 * query) are none. possible_hits() locks the object when such a number
 * equals the staging id of an object in the scope whose live id differs, in
 * both ID spaces (posts and terms, survey 4): *:possible_reference.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-canon.php';

/**
 * Class AB_MCP_Sync_Refs
 */
final class AB_MCP_Sync_Refs {

	/**
	 * Core blocks with references in their attributes: path => what it is.
	 * post / term / user: one id; post_list: a list of post ids; by_kind:
	 * the id of a navigation link, a post with kind post-type, a term with
	 * kind taxonomy; user_any: an author list as core/query reads it (list,
	 * text of ids or one id, wp-includes/blocks.php:3033-3040); tax_query:
	 * the terms of a query in both of its formats (:2902-2940); term_objects:
	 * a list of objects with an id (core/latest-posts categories).
	 */
	const BLOCKS = array(
		'core/image'              => array( 'id' => 'post' ),
		'core/gallery'            => array( 'ids' => 'post_list' ),
		'core/cover'              => array( 'id' => 'post' ),
		'core/media-text'         => array( 'mediaId' => 'post' ),
		'core/file'               => array( 'id' => 'post' ),
		'core/video'              => array( 'id' => 'post' ),
		'core/audio'              => array( 'id' => 'post' ),
		'core/block'              => array( 'ref' => 'post' ),
		'core/navigation'         => array( 'ref' => 'post' ),
		'core/navigation-link'    => array( 'id' => 'by_kind' ),
		'core/navigation-submenu' => array( 'id' => 'by_kind' ),
		'core/page-list'          => array( 'parentPageID' => 'post' ),
		'core/query'              => array(
			'query.author'   => 'user_any',
			'query.taxQuery' => 'tax_query',
			'query.exclude'  => 'post_list',
			'query.parents'  => 'post_list',
		),
		'core/latest-posts'       => array(
			'selectedAuthor' => 'user',
			'categories'     => 'term_objects',
		),
	);

	/**
	 * The HTML the save of a block writes from its id: the class
	 * wp-image-<id> (image, cover and media-text save, wp-includes/js/dist/
	 * block-library.js:38075, :23940, :43448; the deprecated gallery, :28659,
	 * with data-id, :28656), and in classic content (no block name) the class
	 * of get_image_tag() (wp-includes/media.php:408).
	 */
	const HTML = array(
		''                => array( 'class' ),
		'core/image'      => array( 'class' ),
		'core/cover'      => array( 'class' ),
		'core/media-text' => array( 'class' ),
		'core/gallery'    => array( 'class', 'data_id' ),
	);

	/**
	 * Core shortcodes with references: attribute => what it is.
	 * gallery and playlist ids (wp-includes/media.php:2834, :3201),
	 * caption id="attachment_<id>" (wp-admin/includes/media.php:220).
	 */
	const SHORTCODES = array(
		'gallery'  => array( 'ids' => 'post_list' ),
		'playlist' => array( 'ids' => 'post_list' ),
		'caption'  => array( 'id' => 'attachment_id' ),
	);

	/**
	 * Numbers of core shortcodes that are plain by their attributes in the
	 * core (img_caption_shortcode() width, gallery_shortcode() columns,
	 * wp-includes/media.php:2628, :2828; wp_video_shortcode() width and
	 * height, :3797-3798; the embed shortcode's width and height,
	 * wp-includes/class-wp-embed.php:211).
	 */
	const SHORTCODE_PLAIN = array(
		'caption' => array( 'width' ),
		'gallery' => array( 'columns' ),
		'video'   => array( 'width', 'height' ),
		'embed'   => array( 'width', 'height' ),
	);

	/**
	 * Core widgets with references in their settings: field => what it is
	 * (wp-includes/widgets/: nav_menu of WP_Nav_Menu_Widget, attachment_id
	 * of the media widgets, ids of the gallery widget, exclude of the pages
	 * widget as a text of ids, the content of the block widget as blocks and
	 * the text of the text and HTML widgets as content).
	 */
	const WIDGETS = array(
		'nav_menu'      => array( 'nav_menu' => 'term' ),
		'media_image'   => array( 'attachment_id' => 'post' ),
		'media_audio'   => array( 'attachment_id' => 'post' ),
		'media_video'   => array( 'attachment_id' => 'post' ),
		'media_gallery' => array( 'ids' => 'post_list' ),
		'pages'         => array( 'exclude' => 'post_text' ),
		'block'         => array( 'content' => 'content' ),
		'text'          => array( 'text' => 'content' ),
		'custom_html'   => array( 'content' => 'content' ),
	);

	/**
	 * The core widgets whose other numbers are plain (counts, sizes, the
	 * number of items): their settings are the schema of WordPress.
	 */
	const CORE_WIDGETS = array( 'archives', 'block', 'calendar', 'categories', 'custom_html', 'media_audio', 'media_gallery', 'media_image', 'media_video', 'meta', 'nav_menu', 'pages', 'recent-comments', 'recent-posts', 'rss', 'search', 'tag_cloud', 'text' );

	/**
	 * The numeric attributes of core blocks that are plain numbers, from
	 * the block.json of each block in WordPress 7.1.3 (type number or
	 * integer), without the references of BLOCKS and without UNMAPPED.
	 * The integration suite holds this against the core.
	 */
	const PLAIN_NUMBERS = array(
		'core/accordion-heading'        => array( 'level' ),
		'core/accordion'                => array( 'headingLevel' ),
		'core/avatar'                   => array( 'size' ),
		'core/calendar'                 => array( 'month', 'year' ),
		'core/comments-title'           => array( 'level' ),
		'core/cover'                    => array( 'dimRatio', 'minHeight' ),
		'core/file'                     => array( 'previewHeight' ),
		'core/gallery'                  => array( 'columns' ),
		'core/heading'                  => array( 'level' ),
		'core/icon'                     => array( 'rotation' ),
		'core/latest-comments'          => array( 'commentsToShow' ),
		'core/latest-posts'             => array( 'postsToShow', 'excerptLength', 'featuredImageSizeWidth', 'featuredImageSizeHeight' ),
		'core/list'                     => array( 'start' ),
		'core/media-text'               => array( 'mediaWidth' ),
		'core/navigation'               => array( 'maxNestingLevel' ),
		'core/post-author'              => array( 'avatarSize' ),
		'core/post-excerpt'             => array( 'excerptLength' ),
		'core/post-featured-image'      => array( 'dimRatio' ),
		'core/post-time-to-read'        => array( 'averageReadingSpeed' ),
		'core/post-title'               => array( 'level' ),
		'core/query-pagination-numbers' => array( 'midSize' ),
		'core/query-title'              => array( 'level' ),
		'core/query'                    => array( 'queryId' ),
		'core/rss'                      => array( 'columns', 'itemsToShow', 'excerptLength' ),
		'core/search'                   => array( 'width' ),
		'core/site-logo'                => array( 'width' ),
		'core/site-tagline'             => array( 'level' ),
		'core/site-title'               => array( 'level' ),
		'core/tabs'                     => array( 'activeTabIndex', 'editorActiveTabIndex' ),
		'core/tag-cloud'                => array( 'numberOfTags' ),
		'core/term-name'                => array( 'level' ),
		'core/text-columns'             => array( 'columns' ),
		'core/video'                    => array( 'width', 'height' ),
	);

	/**
	 * Numeric attributes of core blocks that hold an id this version does
	 * not rewrite: a whole number there is a possible reference, as for any
	 * attribute PLAIN_NUMBERS does not name. The list says so for the
	 * integration suite, which holds that every numeric attribute of the
	 * core is in exactly one of PLAIN_NUMBERS, BLOCKS and UNMAPPED.
	 */
	const UNMAPPED = array(
		'core/avatar'         => array( 'userId' ),
		'core/page-list-item' => array( 'id' ),
		'core/playlist-track' => array( 'id' ),
	);

	/** Attributes of every block that the block supports of the core fill with plain values. */
	const SUPPORTS = array( 'style', 'layout' );

	/** The paging of core/query (block.json default of query), plain numbers. */
	const QUERY_PLAIN = array( 'perPage', 'pages', 'offset' );

	/** How deep blocks may nest. */
	const MAX_NESTING = 64;

	/* ============================================================ canon */

	/**
	 * The canonical form of post content: the block tree as the core's parser
	 * reads it ({"b":name,"a":attrs,"c":[text|block, …]}), references as
	 * markers, addresses neutral.
	 *
	 * @param string $raw  post_content as stored.
	 * @param array  $ctx  Context (AB_MCP_Sync_Canon::context()).
	 * @param array  $acc  Accumulator.
	 * @param string $kind Kind, for the locks.
	 * @param string $at   Where (such as post_content).
	 * @return array
	 */
	public static function content( $raw, array $ctx, array &$acc, $kind, $at ) {
		$parser = new WP_Block_Parser();
		return array( '~blocks' => self::canon_blocks( $parser->parse( (string) $raw ), $ctx, $acc, $kind, $at, 0 ) );
	}

	/**
	 * Note a whole number without a schema (decision 12).
	 *
	 * @param array  $acc    Accumulator.
	 * @param mixed  $value  Value.
	 * @param string $at     Where.
	 * @param int    $layers Texts unpacked above.
	 */
	public static function note_possible( array &$acc, $value, $at, $layers = 0 ) {
		foreach ( self::numbers( $value ) as $n ) {
			$acc['possible'][] = array(
				'value' => $n,
				'at'    => (string) $at,
			);
		}
		// A text that holds a value of its own (serialized, JSON, a JSON
		// string literal): its numbers as well, layer by layer.
		if ( ! is_string( $value ) || $layers >= AB_MCP_Sync_Canon::MAX_LAYERS ) {
			return;
		}
		if ( is_serialized( $value ) ) {
			$read  = AB_MCP_Sync_Canon::unserialized( $value );
			$inner = $read['ok'] ? $read['value'] : null;
		} else {
			$inner = AB_MCP_Sync_Canon::json_text( $value );
		}
		if ( is_array( $inner ) || is_string( $inner ) ) {
			self::note_tree( $acc, $inner, (string) $at, $layers + 1, 0 );
		}
	}

	/**
	 * The numbers of a value unpacked from a text, as possible references.
	 *
	 * @param array  $acc    Accumulator.
	 * @param mixed  $value  Value.
	 * @param string $at     Where.
	 * @param int    $layers Texts unpacked above.
	 * @param int    $depth  Depth.
	 */
	private static function note_tree( array &$acc, $value, $at, $layers, $depth ) {
		if ( $depth > AB_MCP_Sync_Canon::MAX_DEPTH ) {
			return;
		}
		if ( ! is_array( $value ) ) {
			self::note_possible( $acc, $value, $at, $layers );
			return;
		}
		foreach ( $value as $key => $item ) {
			self::note_tree( $acc, $item, $at . '.' . $key, $layers, $depth + 1 );
		}
	}

	/**
	 * The whole numbers a value could be ids of: an int above 0, a text of
	 * digits, a text of digits and commas.
	 *
	 * @param mixed $value Value.
	 * @return int[]
	 */
	public static function numbers( $value ) {
		if ( is_int( $value ) ) {
			return $value > 0 ? array( $value ) : array();
		}
		if ( ! is_string( $value ) || ! preg_match( '/^\s*\d+(?:\s*,\s*\d+)*\s*\z/', $value ) ) {
			return array();
		}
		$out = array();
		foreach ( preg_split( '/\s*,\s*/', trim( $value ) ) as $part ) {
			if ( (int) $part > 0 && strlen( ltrim( $part, '0' ) ) <= 18 ) {
				$out[] = (int) $part;
			}
		}
		return $out;
	}

	/**
	 * A leaf schema for AB_MCP_Sync_Canon::value() that notes every whole
	 * number as a possible reference and leaves the value as it is.
	 *
	 * @param array  $acc Accumulator.
	 * @param string $at  Where the value stands.
	 * @return callable
	 */
	public static function possible_leaf( array &$acc, $at ) {
		return static function ( $value, $path ) use ( &$acc, $at ) {
			self::note_possible( $acc, $value, '' === $path ? $at : $at . '.' . $path );
			return null;
		};
	}

	/**
	 * The possible references of an object that may point to an object in
	 * the scope whose live id differs (decision 12): each number is asked in
	 * both ID spaces.
	 *
	 * @param array $possible Possible references (value, at).
	 * @param array $map      space (post, term) => array( staging id =>
	 *                        live id, or null where the object has none yet ):
	 *                        the objects in the scope.
	 * @return array<int,array{value:int,at:string,space:string,live:int|null}>
	 */
	public static function possible_hits( array $possible, array $map ) {
		$hits = array();
		foreach ( $possible as $p ) {
			$n = (int) $p['value'];
			foreach ( array( 'post', 'term' ) as $space ) {
				if ( ! isset( $map[ $space ] ) || ! is_array( $map[ $space ] ) || ! array_key_exists( $n, $map[ $space ] ) ) {
					continue;
				}
				$live = null === $map[ $space ][ $n ] ? null : (int) $map[ $space ][ $n ];
				if ( $live !== $n ) {
					$hits[] = array(
						'value' => $n,
						'at'    => (string) $p['at'],
						'space' => $space,
						'live'  => $live,
					);
				}
			}
		}
		return $hits;
	}

	/**
	 * The result of AB_MCP_Sync_Canon::finish() with the lock of its possible
	 * references, where possible_hits() finds any.
	 *
	 * @param array $result Result of one object (on the staging side).
	 * @param array $map    The map of possible_hits().
	 * @return array The result; 'possible_hits' added.
	 */
	public static function lock_possible( array $result, array $map ) {
		$hits                    = self::possible_hits( $result['possible'], $map );
		$result['possible_hits'] = $hits;
		if ( array() !== $hits ) {
			$acc = array( 'locks' => $result['locks'] );
			AB_MCP_Sync_Canon::lock( $acc, $result['kind'], 'possible_reference', $hits[0]['at'] );
			$result['locks'] = $acc['locks'];
		}
		return $result;
	}

	/* ========================================================== rewrite */

	/**
	 * What a rewrite puts in: the ids of the other side and its addresses.
	 *
	 * @param array $map       space (post, term) => array( id here => id
	 *                         there ); user => array( user id here => user
	 *                         id there ).
	 * @param array $addresses address here => address there (home => home,
	 *                         siteurl => siteurl).
	 * @return array
	 */
	public static function plan( array $map, array $addresses ) {
		$subs = array();
		foreach ( $addresses as $from => $to ) {
			$from = rtrim( trim( (string) $from ), '/' );
			$to   = rtrim( trim( (string) $to ), '/' );
			if ( '' === $from || '' === $to ) {
				continue;
			}
			foreach ( AB_MCP_Replace_Engine::FORMS as $form ) {
				$needle = AB_MCP_Replace_Engine::encode( $form, $from );
				if ( '' !== $needle && ! isset( $subs[ $needle ] ) ) {
					$subs[ $needle ] = AB_MCP_Replace_Engine::encode( $form, $to );
				}
			}
		}
		$ctx            = AB_MCP_Sync_Canon::context( array_keys( $addresses ), static function () {
			return false;
		} );
		$ctx['subs']    = $subs;
		$ctx['map']     = $map;
		$ctx['missing'] = array();
		return $ctx;
	}

	/**
	 * Post content with its references put into the ID space of the other
	 * side and its addresses replaced. Content without anything to change
	 * comes back byte for byte; otherwise as serialize_blocks() writes the
	 * tree.
	 *
	 * @param string $raw  Content.
	 * @param array  $plan plan().
	 * @return array{content:string,changed:bool,missing:array} missing: the
	 *               references without a counterpart; a caller writes nothing
	 *               while there are any.
	 */
	public static function rewrite_content( $raw, array $plan ) {
		$raw             = (string) $raw;
		$plan['missing'] = array();
		$parser          = new WP_Block_Parser();
		$blocks          = $parser->parse( $raw );
		$changed         = false;
		$blocks          = self::rewrite_blocks( $blocks, $plan, $changed, 0 );
		return array(
			'content' => $changed ? serialize_blocks( $blocks ) : $raw,
			'changed' => $changed,
			'missing' => $plan['missing'],
		);
	}

	/**
	 * A text with its addresses replaced and the given cuts rewritten.
	 *
	 * @param string $text    Text.
	 * @param array  $plan    plan(), with 'missing' collected.
	 * @param array  $cuts    Ref cuts: at, len, space, id.
	 * @param bool   $changed Set when anything changed.
	 * @return string
	 */
	public static function rewrite_text( $text, array &$plan, array $cuts, &$changed ) {
		$text = (string) $text;
		$all  = array();
		foreach ( AB_MCP_Sync_Canon::address_cuts( $text, $plan ) as $cut ) {
			$hit = substr( $text, $cut['at'], $cut['len'] );
			if ( isset( $plan['subs'][ $hit ] ) ) {
				$all[] = array( 'at' => $cut['at'], 'len' => $cut['len'], 'with' => $plan['subs'][ $hit ] );
			}
		}
		foreach ( $cuts as $cut ) {
			$id = self::mapped_id( $plan, $cut['space'], $cut['id'] );
			if ( null !== $id ) {
				$all[] = array( 'at' => $cut['at'], 'len' => $cut['len'], 'with' => (string) $id );
			}
		}
		if ( array() === $all ) {
			return $text;
		}
		usort(
			$all,
			static function ( $a, $b ) {
				return $b['at'] <=> $a['at'];
			}
		);
		$pos = PHP_INT_MAX;
		foreach ( $all as $cut ) {
			if ( $cut['at'] + $cut['len'] > $pos ) {
				continue;
			}
			$old = substr( $text, $cut['at'], $cut['len'] );
			if ( $old !== $cut['with'] ) {
				$text    = substr_replace( $text, $cut['with'], $cut['at'], $cut['len'] );
				$changed = true;
			}
			$pos = $cut['at'];
		}
		return $text;
	}

	/**
	 * The id of the other side, or null (noted in $plan['missing']).
	 *
	 * @param array  $plan  plan().
	 * @param string $space post, term or user.
	 * @param int    $id    Id here.
	 * @return int|null
	 */
	public static function mapped_id( array &$plan, $space, $id ) {
		$id = (int) $id;
		if ( $id <= 0 ) {
			return $id;
		}
		if ( isset( $plan['map'][ $space ][ $id ] ) && (int) $plan['map'][ $space ][ $id ] > 0 ) {
			return (int) $plan['map'][ $space ][ $id ];
		}
		$plan['missing'][] = array(
			'space' => (string) $space,
			'id'    => $id,
		);
		return null;
	}

	/**
	 * A stored value (meta, option, a widget's settings) with its addresses
	 * replaced and, through $leaf, its references of a schema: unpacked where
	 * it is serialized and serialized again only where something changed. A
	 * value that does not unpack is not rewritten (noted as missing space
	 * value): its bytes cannot be changed without breaking it.
	 *
	 * @param string        $raw     As stored.
	 * @param array         $plan    plan(), collecting missing.
	 * @param bool          $changed Set when anything changed.
	 * @param callable|null $leaf    function( $value, string $path, array &$plan, bool &$changed ): mixed|null,
	 *                               the new leaf for a reference; null for
	 *                               the generic rule (addresses only).
	 * @return string
	 */
	public static function rewrite_value( $raw, array &$plan, &$changed, $leaf = null ) {
		$raw = (string) $raw;
		if ( ! is_serialized( $raw ) ) {
			if ( null !== $leaf ) {
				$hit = call_user_func_array( $leaf, array( $raw, '', &$plan, &$changed ) );
				if ( null !== $hit ) {
					return (string) $hit;
				}
			}
			return self::rewrite_text( $raw, $plan, array(), $changed );
		}
		$read = AB_MCP_Sync_Canon::unserialized( $raw );
		if ( ! $read['ok'] ) {
			$plan['missing'][] = array(
				'space' => 'value',
				'id'    => 0,
			);
			return $raw;
		}
		$mine = false;
		$new  = self::rewrite_structure( $read['value'], '', $plan, $mine, $leaf, 0 );
		if ( ! $mine ) {
			return $raw;
		}
		$changed = true;
		return serialize( $new ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the form maybe_serialize() stores.
	}

	/**
	 * A value already unpacked (the settings of a widget instance), rewritten
	 * as rewrite_value() does inside a serialized one.
	 *
	 * @param mixed         $value   Value.
	 * @param string        $path    Path of $value ('' at the top).
	 * @param array         $plan    plan().
	 * @param bool          $changed Set when anything changed.
	 * @param callable|null $leaf    See rewrite_value().
	 * @param int           $depth   Depth.
	 * @return mixed
	 */
	public static function rewrite_structure( $value, $path, array &$plan, &$changed, $leaf = null, $depth = 0 ) {
		if ( $depth > AB_MCP_Sync_Canon::MAX_DEPTH ) {
			$plan['missing'][] = array(
				'space' => 'value',
				'id'    => 0,
			);
			return $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::rewrite_structure( $item, '' === $path ? (string) $key : $path . '.' . $key, $plan, $changed, $leaf, $depth + 1 );
			}
			return $value;
		}
		if ( null !== $leaf ) {
			$hit = call_user_func_array( $leaf, array( $value, $path, &$plan, &$changed ) );
			if ( null !== $hit ) {
				return $hit;
			}
		}
		if ( is_string( $value ) ) {
			return is_serialized( $value ) ? self::rewrite_value( $value, $plan, $changed, $leaf ) : self::rewrite_text( $value, $plan, array(), $changed );
		}
		return $value;
	}

	/**
	 * A core option with its schema (AB_MCP_Sync_Option_Schema::CORE): a
	 * reference or a list of them in the ID space of the other side, the
	 * addresses of a text replaced.
	 *
	 * @param array  $schema  Entry of CORE.
	 * @param string $raw     As stored.
	 * @param array  $plan    plan().
	 * @param bool   $changed Set when anything changed.
	 * @return string
	 */
	public static function rewrite_option( array $schema, $raw, array &$plan, &$changed ) {
		$raw = (string) $raw;
		if ( 'ref' === $schema['type'] ) {
			return (string) self::rewrite_id( $schema['space'], $raw, $plan, $changed );
		}
		if ( 'refs' === $schema['type'] ) {
			$space = $schema['space'];
			return self::rewrite_value(
				$raw,
				$plan,
				$changed,
				static function ( $value, $path, &$plan, &$changed ) use ( $space ) {
					return '' === $path ? null : self::rewrite_id( $space, $value, $plan, $changed );
				}
			);
		}
		if ( in_array( $schema['type'], array( 'text', 'rewrite' ), true ) ) {
			return self::rewrite_value( $raw, $plan, $changed );
		}
		return $raw;
	}

	/**
	 * A meta row of a menu item by _menu_item_type (decision 12): the parent
	 * item, the object (post for post_type, term for taxonomy, the item
	 * itself for custom: its live id, or missing while it has none), the
	 * address of a custom link, the rest as a stored value.
	 *
	 * @param string   $key     Meta key.
	 * @param string   $raw     As stored.
	 * @param string   $type    _menu_item_type of the item.
	 * @param int|null $self    The live id of the item, null when it is new.
	 * @param array    $plan    plan().
	 * @param bool     $changed Set when anything changed.
	 * @return string
	 */
	public static function rewrite_menu_item_meta( $key, $raw, $type, $self, array &$plan, &$changed ) {
		$raw = (string) $raw;
		if ( '_menu_item_menu_item_parent' === $key ) {
			return (string) self::rewrite_id( 'post', $raw, $plan, $changed );
		}
		if ( '_menu_item_object_id' === $key ) {
			if ( 'post_type' === $type || 'taxonomy' === $type ) {
				return (string) self::rewrite_id( 'post_type' === $type ? 'post' : 'term', $raw, $plan, $changed );
			}
			if ( 'custom' === $type ) {
				if ( null === $self ) {
					$plan['missing'][] = array(
						'space' => 'self',
						'id'    => (int) $raw,
					);
					return $raw;
				}
				if ( (string) (int) $self !== $raw ) {
					$changed = true;
				}
				return (string) (int) $self;
			}
			return $raw;
		}
		return self::rewrite_value( $raw, $plan, $changed );
	}

	/**
	 * Menu positions (nav_menu_locations): each menu in the ID space of the
	 * other side.
	 *
	 * @param array $locations Location => menu term id.
	 * @param array $plan      plan().
	 * @param bool  $changed   Set when anything changed.
	 * @return array
	 */
	public static function rewrite_locations( array $locations, array &$plan, &$changed ) {
		foreach ( $locations as $location => $menu ) {
			$locations[ $location ] = self::rewrite_id( 'term', $menu, $plan, $changed );
		}
		return $locations;
	}

	/**
	 * The settings of a widget instance by WIDGETS: its references, its
	 * content as post content, the addresses of every text.
	 *
	 * @param string $base     Widget base.
	 * @param array  $settings Settings.
	 * @param array  $plan     plan().
	 * @param bool   $changed  Set when anything changed.
	 * @return array
	 */
	public static function rewrite_widget( $base, array $settings, array &$plan, &$changed ) {
		$schema = isset( self::WIDGETS[ $base ] ) ? self::WIDGETS[ $base ] : array();
		return self::rewrite_structure(
			$settings,
			'',
			$plan,
			$changed,
			static function ( $value, $path, &$plan, &$changed ) use ( $schema ) {
				$type = isset( $schema[ explode( '.', (string) $path )[0] ] ) ? $schema[ explode( '.', (string) $path )[0] ] : '';
				if ( 'post' === $type || 'term' === $type || 'post_list' === $type ) {
					return self::rewrite_id( 'term' === $type ? 'term' : 'post', $value, $plan, $changed );
				}
				if ( 'post_text' === $type && is_string( $value ) ) {
					$cuts = array();
					if ( preg_match_all( '/\d+/', $value, $m, PREG_OFFSET_CAPTURE ) ) {
						foreach ( $m[0] as $hit ) {
							$cuts[] = array(
								'at'    => (int) $hit[1],
								'len'   => strlen( $hit[0] ),
								'space' => 'post',
								'id'    => (int) $hit[0],
							);
						}
					}
					return self::rewrite_text( $value, $plan, $cuts, $changed );
				}
				if ( 'content' === $type && is_string( $value ) ) {
					$out             = self::rewrite_content( $value, $plan );
					$plan['missing'] = array_merge( $plan['missing'], $out['missing'] );
					$changed         = $changed || $out['changed'];
					return $out['content'];
				}
				return null;
			}
		);
	}

	/* ======================================================== the text */

	/**
	 * The references with a schema in a text of HTML: the tags with the class
	 * token or data-id of HTML for the block (AB_MCP_Sync_Html, the HTML API
	 * of the core), and the core shortcodes. Shortcodes of other tags, and
	 * attributes of core ones without a schema, give possible references.
	 *
	 * @param string $text  Text.
	 * @param string $block Block name; '' for classic content.
	 * @return array{cuts:array,tags:array,possible:array} cuts: the ids of
	 *               shortcodes; tags: AB_MCP_Sync_Html::tags().
	 */
	public static function text_refs( $text, $block ) {
		$text = (string) $text;
		$cuts = array();
		$tags = array();
		$html = self::html_rules( $block );
		if ( array() !== $html && self::html_api( $text, $html ) ) {
			$tags = AB_MCP_Sync_Html::tags( $text, $html );
		}
		$possible = array();
		self::shortcodes( $text, 0, $cuts, $possible, 0 );
		return array(
			'cuts'     => $cuts,
			'tags'     => $tags,
			'possible' => $possible,
		);
	}

	/**
	 * @param string $block Block name; '' for classic content.
	 * @return string[] The rules of HTML for the block.
	 */
	private static function html_rules( $block ) {
		return isset( self::HTML[ $block ] ) ? self::HTML[ $block ] : array();
	}

	/**
	 * Whether the HTML API is to read a text: only where it can find a
	 * reference, and loaded only then.
	 *
	 * @param string   $text  Text.
	 * @param string[] $rules Rules.
	 * @return bool
	 */
	private static function html_api( $text, array $rules ) {
		$text = (string) $text;
		if ( false === strpos( $text, '<' ) ) {
			return false;
		}
		// The class token or the attribute name as letters, or a character
		// reference that could spell one: without any of them the HTML API
		// finds no reference, and is not loaded.
		$may = false !== strpos( $text, '&' )
			|| ( in_array( 'class', $rules, true ) && false !== stripos( $text, 'wp-image-' ) )
			|| ( in_array( 'data_id', $rules, true ) && false !== stripos( $text, 'data-id' ) );
		if ( $may ) {
			require_once __DIR__ . '/class-sync-html.php';
		}
		return $may;
	}

	/* ========================================================== private */

	/**
	 * @param array  $blocks Parsed blocks.
	 * @param array  $ctx    Context.
	 * @param array  $acc    Accumulator.
	 * @param string $kind   Kind.
	 * @param string $at     Where.
	 * @param int    $depth  Nesting.
	 * @return array
	 */
	private static function canon_blocks( array $blocks, array $ctx, array &$acc, $kind, $at, $depth ) {
		if ( $depth > self::MAX_NESTING ) {
			AB_MCP_Sync_Canon::lock( $acc, $kind, 'unreadable_value', $at );
			return array();
		}
		$out = array();
		foreach ( $blocks as $block ) {
			$name  = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : null;
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$where = $at . ':' . ( null === $name ? 'html' : $name );
			$parts = array();
			$i     = 0;
			foreach ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : array() as $chunk ) {
				if ( is_string( $chunk ) ) {
					$parts[] = self::canon_html( $chunk, (string) $name, $ctx, $acc, $kind, $where );
				} elseif ( isset( $block['innerBlocks'][ $i ] ) ) {
					$inner   = self::canon_blocks( array( $block['innerBlocks'][ $i ] ), $ctx, $acc, $kind, $at, $depth + 1 );
					$parts[] = isset( $inner[0] ) ? $inner[0] : null;
					++$i;
				}
			}
			$out[] = array(
				'b' => $name,
				'a' => self::canon_attrs( (string) $name, $attrs, $ctx, $acc, $kind, $where ),
				'c' => $parts,
			);
		}
		return $out;
	}

	/**
	 * @param string $text  HTML of a block or classic content.
	 * @param string $block Block name, '' for classic content.
	 * @param array  $ctx   Context.
	 * @param array  $acc   Accumulator.
	 * @param string $kind  Kind.
	 * @param string $at    Where.
	 * @return string|array
	 */
	private static function canon_html( $text, $block, array $ctx, array &$acc, $kind, $at ) {
		$found = self::text_refs( $text, $block );
		$cuts  = array();
		// A tag with references as the core writes it with each id set to
		// a placeholder, its addresses neutral, and the ids as markers.
		foreach ( $found['tags'] as $tag ) {
			$refs = array();
			foreach ( $tag['ids'] as $id ) {
				$refs[]        = AB_MCP_Sync_Canon::ref( 'post', $id['id'] );
				$acc['refs'][] = array(
					'space'    => 'post',
					'id'       => $id['id'],
					'at'       => $at . ':html:' . $id['rule'],
					'strength' => 'strong',
				);
			}
			$cuts[] = array(
				'at'     => $tag['at'],
				'len'    => $tag['len'],
				'marker' => array(
					'~tag' => AB_MCP_Sync_Canon::text( $tag['tag'], $ctx ),
					'refs' => $refs,
				),
			);
		}
		foreach ( $found['cuts'] as $cut ) {
			$cuts[]        = array(
				'at'     => $cut['at'],
				'len'    => $cut['len'],
				'marker' => AB_MCP_Sync_Canon::ref( $cut['space'], $cut['id'] ),
			);
			$acc['refs'][] = array(
				'space'    => $cut['space'],
				'id'       => $cut['id'],
				'at'       => $at . ':' . $cut['role'],
				'strength' => 'strong',
			);
		}
		foreach ( $found['possible'] as $p ) {
			$acc['possible'][] = array(
				'value' => $p['value'],
				'at'    => $at . ':' . $p['at'],
			);
		}
		return AB_MCP_Sync_Canon::text( $text, $ctx, $cuts );
	}

	/**
	 * The attributes of a block: references of BLOCKS as markers, texts with
	 * the addresses neutral, whole numbers without a schema noted.
	 *
	 * @param string $block Block name.
	 * @param array  $attrs Attributes.
	 * @param array  $ctx   Context.
	 * @param array  $acc   Accumulator.
	 * @param string $kind  Kind.
	 * @param string $at    Where.
	 * @return array
	 */
	private static function canon_attrs( $block, array $attrs, array $ctx, array &$acc, $kind, $at ) {
		$schema = isset( self::BLOCKS[ $block ] ) ? self::BLOCKS[ $block ] : array();
		$out    = self::canon_leaves( $block, $attrs, '', $schema, $ctx, $acc, $kind, $at, 0 );
		foreach ( $schema as $path => $type ) {
			$keys = explode( '.', $path );
			if ( ! self::has_path( $attrs, $keys ) ) {
				continue;
			}
			$value = self::get_path( $attrs, $keys );
			$where = $at . '.' . $path;
			$out   = self::set_path( $out, $keys, self::canon_ref( $type, $value, $attrs, $ctx, $acc, $where ) );
		}
		return $out;
	}

	/**
	 * @param string $block  Block name.
	 * @param mixed  $value  Attributes or a part of them.
	 * @param string $path   Path of $value.
	 * @param array  $schema BLOCKS of the block.
	 * @param array  $ctx    Context.
	 * @param array  $acc    Accumulator.
	 * @param string $kind   Kind, for the locks.
	 * @param string $at     Where.
	 * @param int    $depth  Depth.
	 * @return mixed
	 */
	private static function canon_leaves( $block, $value, $path, array $schema, array $ctx, array &$acc, $kind, $at, $depth ) {
		if ( $depth > AB_MCP_Sync_Canon::MAX_DEPTH ) {
			AB_MCP_Sync_Canon::lock( $acc, $kind, 'unreadable_value', $at );
			return null;
		}
		if ( '' !== $path && isset( $schema[ $path ] ) ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$name         = is_string( $key ) && '' !== $key && '~' === $key[0] ? '~' . $key : $key;
				$out[ $name ] = self::canon_leaves( $block, $item, '' === $path ? (string) $key : $path . '.' . $key, $schema, $ctx, $acc, $kind, $at, $depth + 1 );
			}
			return $out;
		}
		if ( ! self::is_plain( $block, $path ) ) {
			self::note_possible( $acc, $value, $at . '.' . $path );
		}
		return is_string( $value ) ? AB_MCP_Sync_Canon::text( $value, $ctx ) : $value;
	}

	/**
	 * The marker form of a reference attribute.
	 *
	 * @param string $type  Type of BLOCKS.
	 * @param mixed  $value Value.
	 * @param array  $attrs All attributes of the block (for by_kind).
	 * @param array  $ctx   Context.
	 * @param array  $acc   Accumulator.
	 * @param string $at    Where.
	 * @return mixed
	 */
	private static function canon_ref( $type, $value, array $attrs, array $ctx, array &$acc, $at ) {
		switch ( $type ) {
			case 'post':
			case 'term':
				return self::canon_id( $type, $value, $acc, $at );
			case 'by_kind':
				$kind = isset( $attrs['kind'] ) ? (string) $attrs['kind'] : '';
				if ( 'post-type' === $kind ) {
					return self::canon_id( 'post', $value, $acc, $at );
				}
				if ( 'taxonomy' === $kind ) {
					return self::canon_id( 'term', $value, $acc, $at );
				}
				self::note_possible( $acc, $value, $at );
				return $value;
			case 'post_list':
				if ( ! is_array( $value ) ) {
					return self::canon_id( 'post', $value, $acc, $at );
				}
				$out = array();
				foreach ( $value as $i => $item ) {
					$out[ $i ] = self::canon_id( 'post', $item, $acc, $at . '.' . $i );
				}
				return $out;
			case 'user':
				return self::canon_user( $value, $ctx, $acc, $at );
			case 'user_any':
				if ( is_array( $value ) ) {
					$out = array();
					foreach ( $value as $i => $item ) {
						$out[ $i ] = self::canon_user( $item, $ctx, $acc, $at . '.' . $i );
					}
					return $out;
				}
				if ( is_string( $value ) ) {
					$cuts = array();
					if ( preg_match_all( '/\d+/', $value, $m, PREG_OFFSET_CAPTURE ) ) {
						foreach ( $m[0] as $hit ) {
							$cuts[] = array(
								'at'     => (int) $hit[1],
								'len'    => strlen( $hit[0] ),
								'marker' => AB_MCP_Sync_Canon::user( (int) $hit[0], $ctx, $acc, $at ),
							);
						}
					}
					return AB_MCP_Sync_Canon::text( $value, $ctx, $cuts );
				}
				return self::canon_user( $value, $ctx, $acc, $at );
			case 'tax_query':
				if ( ! is_array( $value ) ) {
					return $value;
				}
				$out = array();
				foreach ( $value as $key => $item ) {
					if ( ( 'include' === $key || 'exclude' === $key ) && is_array( $item ) ) {
						$sub = array();
						foreach ( $item as $tax => $ids ) {
							$sub[ $tax ] = self::canon_terms( $ids, $acc, $at . '.' . $key . '.' . $tax );
						}
						$out[ $key ] = $sub;
					} else {
						$out[ $key ] = self::canon_terms( $item, $acc, $at . '.' . $key );
					}
				}
				return $out;
			case 'term_objects':
				if ( ! is_array( $value ) ) {
					return $value;
				}
				$out = array();
				foreach ( $value as $i => $item ) {
					if ( is_array( $item ) && isset( $item['id'] ) ) {
						$item['id'] = self::canon_id( 'term', $item['id'], $acc, $at . '.' . $i . '.id' );
						foreach ( $item as $field => $v ) {
							if ( 'id' !== $field && is_string( $v ) ) {
								$item[ $field ] = AB_MCP_Sync_Canon::text( $v, $ctx );
							}
						}
					}
					$out[ $i ] = $item;
				}
				return $out;
		}
		return $value;
	}

	/**
	 * @param mixed  $ids Term ids (list or one).
	 * @param array  $acc Accumulator.
	 * @param string $at  Where.
	 * @return mixed
	 */
	private static function canon_terms( $ids, array &$acc, $at ) {
		if ( ! is_array( $ids ) ) {
			return self::canon_id( 'term', $ids, $acc, $at );
		}
		$out = array();
		foreach ( $ids as $i => $id ) {
			$out[ $i ] = self::canon_id( 'term', $id, $acc, $at . '.' . $i );
		}
		return $out;
	}

	/**
	 * An id as a marker; 0, '' and what is no whole number stay as they are
	 * (a text of no digits is noted nowhere: it is no id).
	 *
	 * @param string $space post or term.
	 * @param mixed  $value Value.
	 * @param array  $acc   Accumulator.
	 * @param string $at    Where.
	 * @return mixed
	 */
	private static function canon_id( $space, $value, array &$acc, $at ) {
		if ( ! ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^\d+\z/', $value ) ) ) || (int) $value <= 0 ) {
			return $value;
		}
		$acc['refs'][] = array(
			'space'    => $space,
			'id'       => (int) $value,
			'at'       => (string) $at,
			'strength' => 'strong',
		);
		return AB_MCP_Sync_Canon::ref( $space, (int) $value ) + ( is_string( $value ) ? array( 'text' => true ) : array() );
	}

	/**
	 * @param mixed  $value User id.
	 * @param array  $ctx   Context.
	 * @param array  $acc   Accumulator.
	 * @param string $at    Where.
	 * @return mixed
	 */
	private static function canon_user( $value, array $ctx, array &$acc, $at ) {
		if ( ! ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^\d+\z/', $value ) ) ) || (int) $value <= 0 ) {
			return $value;
		}
		return AB_MCP_Sync_Canon::user( (int) $value, $ctx, $acc, $at );
	}

	/**
	 * Whether a number at this path of a block's attributes is plain by the
	 * schema of the core.
	 *
	 * @param string $block Block name.
	 * @param string $path  Path in the attributes.
	 * @return bool
	 */
	private static function is_plain( $block, $path ) {
		if ( 0 !== strpos( $block, 'core/' ) ) {
			return false;
		}
		$top = explode( '.', $path )[0];
		if ( in_array( $top, self::SUPPORTS, true ) ) {
			return true;
		}
		if ( 'core/query' === $block && preg_match( '/^query\.([A-Za-z]+)\z/', $path, $m ) && in_array( $m[1], self::QUERY_PLAIN, true ) ) {
			return true;
		}
		return isset( self::PLAIN_NUMBERS[ $block ] ) && $path === $top && in_array( $top, self::PLAIN_NUMBERS[ $block ], true );
	}

	/**
	 * The shortcodes of a text, with their nested ones: references of
	 * SHORTCODES as cuts, every other whole number of an attribute possible.
	 *
	 * @param string $text     Text.
	 * @param int    $base     Offset of $text in the whole.
	 * @param array  $cuts     Cuts found.
	 * @param array  $possible Possible references found.
	 * @param int    $depth    Nesting.
	 */
	private static function shortcodes( $text, $base, array &$cuts, array &$possible, $depth ) {
		if ( $depth > 8 || false === strpos( $text, '[' ) || ! preg_match_all( '/\[\/?([A-Za-z0-9_-]+)/', $text, $tags ) ) {
			return;
		}
		$names = array_values( array_unique( $tags[1] ) );
		if ( ! preg_match_all( '/' . get_shortcode_regex( $names ) . '/', $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return;
		}
		foreach ( $all as $m ) {
			// [[tag]] is written out as text, not run.
			if ( '[' === $m[1][0] && ']' === $m[6][0] ) {
				continue;
			}
			$tag   = strtolower( (string) $m[2][0] );
			$atts  = (string) $m[3][0];
			$start = $base + (int) $m[3][1];
			// shortcode_parse_atts() reads U+00A0 and U+200B as spaces; the
			// same bytes as spaces keep the offsets.
			$atts = (string) preg_replace_callback(
				"/\xC2\xA0|\xE2\x80\x8B/",
				static function ( $c ) {
					return str_repeat( ' ', strlen( $c[0] ) );
				},
				$atts
			);
			if ( preg_match_all( get_shortcode_atts_regex(), $atts, $pairs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				foreach ( $pairs as $pair ) {
					foreach ( array( 1, 3, 5 ) as $g ) {
						if ( isset( $pair[ $g ] ) && '' !== $pair[ $g ][0] && -1 !== $pair[ $g ][1] ) {
							self::shortcode_attr( $tag, strtolower( $pair[ $g ][0] ), $pair[ $g + 1 ][0], $start + (int) $pair[ $g + 1 ][1], $cuts, $possible );
						}
					}
				}
			}
			if ( isset( $m[5] ) && '' !== $m[5][0] && -1 !== $m[5][1] ) {
				self::shortcodes( $m[5][0], $base + (int) $m[5][1], $cuts, $possible, $depth + 1 );
			}
		}
	}

	/**
	 * One attribute of a shortcode.
	 *
	 * @param string $tag      Tag.
	 * @param string $name     Attribute.
	 * @param string $value    Value.
	 * @param int    $at       Offset of the value.
	 * @param array  $cuts     Cuts.
	 * @param array  $possible Possible references.
	 */
	private static function shortcode_attr( $tag, $name, $value, $at, array &$cuts, array &$possible ) {
		$type = isset( self::SHORTCODES[ $tag ][ $name ] ) ? self::SHORTCODES[ $tag ][ $name ] : '';
		if ( 'post_list' === $type && preg_match_all( '/\d+/', $value, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $hit ) {
				$cuts[] = array(
					'at'    => $at + (int) $hit[1],
					'len'   => strlen( $hit[0] ),
					'space' => 'post',
					'id'    => (int) $hit[0],
					'role'  => 'shortcode:' . $tag . '.' . $name,
				);
			}
			return;
		}
		if ( 'attachment_id' === $type && preg_match( '/^attachment_(\d+)\z/', $value, $m, PREG_OFFSET_CAPTURE ) ) {
			$cuts[] = array(
				'at'    => $at + (int) $m[1][1],
				'len'   => strlen( $m[1][0] ),
				'space' => 'post',
				'id'    => (int) $m[1][0],
				'role'  => 'shortcode:' . $tag . '.' . $name,
			);
			return;
		}
		if ( isset( self::SHORTCODE_PLAIN[ $tag ] ) && in_array( $name, self::SHORTCODE_PLAIN[ $tag ], true ) ) {
			return;
		}
		foreach ( self::numbers( $value ) as $n ) {
			$possible[] = array(
				'value' => $n,
				'at'    => 'shortcode:' . $tag . '.' . $name,
			);
		}
	}

	/**
	 * @param array $blocks  Parsed blocks.
	 * @param array $plan    plan(), collecting missing.
	 * @param bool  $changed Set when anything changed.
	 * @param int   $depth   Nesting.
	 * @return array
	 */
	private static function rewrite_blocks( array $blocks, array &$plan, &$changed, $depth ) {
		if ( $depth > self::MAX_NESTING ) {
			$plan['missing'][] = array(
				'space' => 'depth',
				'id'    => 0,
			);
			return $blocks;
		}
		foreach ( $blocks as $b => $block ) {
			$name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
			if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				$block['attrs'] = self::rewrite_attrs( $name, $block['attrs'], $plan, $changed );
			}
			if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
				foreach ( $block['innerContent'] as $i => $chunk ) {
					if ( is_string( $chunk ) ) {
						$html = self::html_rules( $name );
						if ( array() !== $html && self::html_api( $chunk, $html ) ) {
							$chunk = AB_MCP_Sync_Html::rewrite(
								$chunk,
								$html,
								static function ( $id ) use ( &$plan ) {
									return self::mapped_id( $plan, 'post', $id );
								},
								$changed
							);
						}
						// The shortcodes of the text, its tags done above.
						$found                       = self::text_refs( $chunk, '~shortcodes' );
						$block['innerContent'][ $i ] = self::rewrite_text( $chunk, $plan, $found['cuts'], $changed );
					}
				}
			}
			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && array() !== $block['innerBlocks'] ) {
				$block['innerBlocks'] = self::rewrite_blocks( $block['innerBlocks'], $plan, $changed, $depth + 1 );
			}
			$blocks[ $b ] = $block;
		}
		return $blocks;
	}

	/**
	 * @param string $block   Block name.
	 * @param array  $attrs   Attributes.
	 * @param array  $plan    plan().
	 * @param bool   $changed Set when anything changed.
	 * @return array
	 */
	private static function rewrite_attrs( $block, array $attrs, array &$plan, &$changed ) {
		$schema = isset( self::BLOCKS[ $block ] ) ? self::BLOCKS[ $block ] : array();
		$out    = self::rewrite_leaves( $attrs, '', $schema, $plan, $changed );
		foreach ( $schema as $path => $type ) {
			$keys = explode( '.', $path );
			if ( self::has_path( $attrs, $keys ) ) {
				$out = self::set_path( $out, $keys, self::rewrite_ref( $type, self::get_path( $attrs, $keys ), $attrs, $plan, $changed ) );
			}
		}
		return $out;
	}

	/**
	 * Addresses in every text of the attributes but the references.
	 *
	 * @param mixed  $value   Value.
	 * @param string $path    Path.
	 * @param array  $schema  BLOCKS of the block.
	 * @param array  $plan    plan().
	 * @param bool   $changed Set when anything changed.
	 * @return mixed
	 */
	private static function rewrite_leaves( $value, $path, array $schema, array &$plan, &$changed ) {
		if ( '' !== $path && isset( $schema[ $path ] ) ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::rewrite_leaves( $item, '' === $path ? (string) $key : $path . '.' . $key, $schema, $plan, $changed );
			}
			return $value;
		}
		return is_string( $value ) ? self::rewrite_text( $value, $plan, array(), $changed ) : $value;
	}

	/**
	 * @param string $type    Type of BLOCKS.
	 * @param mixed  $value   Value.
	 * @param array  $attrs   All attributes.
	 * @param array  $plan    plan().
	 * @param bool   $changed Set when anything changed.
	 * @return mixed
	 */
	private static function rewrite_ref( $type, $value, array $attrs, array &$plan, &$changed ) {
		switch ( $type ) {
			case 'post':
			case 'term':
			case 'user':
				return self::rewrite_id( $type, $value, $plan, $changed );
			case 'by_kind':
				$kind = isset( $attrs['kind'] ) ? (string) $attrs['kind'] : '';
				if ( 'post-type' === $kind ) {
					return self::rewrite_id( 'post', $value, $plan, $changed );
				}
				if ( 'taxonomy' === $kind ) {
					return self::rewrite_id( 'term', $value, $plan, $changed );
				}
				return $value;
			case 'post_list':
				if ( ! is_array( $value ) ) {
					return self::rewrite_id( 'post', $value, $plan, $changed );
				}
				foreach ( $value as $i => $item ) {
					$value[ $i ] = self::rewrite_id( 'post', $item, $plan, $changed );
				}
				return $value;
			case 'user_any':
				if ( is_array( $value ) ) {
					foreach ( $value as $i => $item ) {
						$value[ $i ] = self::rewrite_id( 'user', $item, $plan, $changed );
					}
					return $value;
				}
				if ( is_string( $value ) ) {
					$cuts = array();
					if ( preg_match_all( '/\d+/', $value, $m, PREG_OFFSET_CAPTURE ) ) {
						foreach ( $m[0] as $hit ) {
							$cuts[] = array(
								'at'    => (int) $hit[1],
								'len'   => strlen( $hit[0] ),
								'space' => 'user',
								'id'    => (int) $hit[0],
							);
						}
					}
					// The ids only: an author list holds no address.
					$sub             = array(
						'subs'    => array(),
						'pattern' => '',
					) + $plan;
					$out             = self::rewrite_text( $value, $sub, $cuts, $changed );
					$plan['missing'] = $sub['missing'];
					return $out;
				}
				return self::rewrite_id( 'user', $value, $plan, $changed );
			case 'tax_query':
				if ( ! is_array( $value ) ) {
					return $value;
				}
				foreach ( $value as $key => $item ) {
					if ( ( 'include' === $key || 'exclude' === $key ) && is_array( $item ) ) {
						foreach ( $item as $tax => $ids ) {
							$value[ $key ][ $tax ] = self::rewrite_terms( $ids, $plan, $changed );
						}
					} else {
						$value[ $key ] = self::rewrite_terms( $item, $plan, $changed );
					}
				}
				return $value;
			case 'term_objects':
				if ( ! is_array( $value ) ) {
					return $value;
				}
				foreach ( $value as $i => $item ) {
					if ( is_array( $item ) && isset( $item['id'] ) ) {
						$value[ $i ]['id'] = self::rewrite_id( 'term', $item['id'], $plan, $changed );
					}
				}
				return $value;
		}
		return $value;
	}

	/**
	 * @param mixed $ids     Term ids.
	 * @param array $plan    plan().
	 * @param bool  $changed Set when anything changed.
	 * @return mixed
	 */
	private static function rewrite_terms( $ids, array &$plan, &$changed ) {
		if ( ! is_array( $ids ) ) {
			return self::rewrite_id( 'term', $ids, $plan, $changed );
		}
		foreach ( $ids as $i => $id ) {
			$ids[ $i ] = self::rewrite_id( 'term', $id, $plan, $changed );
		}
		return $ids;
	}

	/**
	 * An id of the other side in the type it had (an int stays an int, a
	 * text of digits a text).
	 *
	 * @param string $space   post, term or user.
	 * @param mixed  $value   Value.
	 * @param array  $plan    plan().
	 * @param bool   $changed Set when anything changed.
	 * @return mixed
	 */
	private static function rewrite_id( $space, $value, array &$plan, &$changed ) {
		if ( ! ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^\d+\z/', $value ) ) ) || (int) $value <= 0 ) {
			return $value;
		}
		$id = self::mapped_id( $plan, $space, (int) $value );
		if ( null === $id ) {
			return $value;
		}
		$new = is_string( $value ) ? (string) $id : $id;
		if ( $new !== $value ) {
			$changed = true;
		}
		return $new;
	}

	/**
	 * @param array    $value Array.
	 * @param string[] $keys  Path.
	 * @return bool
	 */
	private static function has_path( array $value, array $keys ) {
		foreach ( $keys as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return false;
			}
			$value = $value[ $key ];
		}
		return true;
	}

	/**
	 * @param array    $value Array.
	 * @param string[] $keys  Path (has_path()).
	 * @return mixed
	 */
	private static function get_path( array $value, array $keys ) {
		foreach ( $keys as $key ) {
			$value = $value[ $key ];
		}
		return $value;
	}

	/**
	 * @param array    $value Array.
	 * @param string[] $keys  Path (of BLOCKS: none begins with "~", so the
	 *                        escaped keys of canon_leaves() are never meant).
	 * @param mixed    $item  New value.
	 * @return array
	 */
	private static function set_path( array $value, array $keys, $item ) {
		$key = array_shift( $keys );
		if ( array() === $keys ) {
			$value[ $key ] = $item;
			return $value;
		}
		$value[ $key ] = self::set_path( isset( $value[ $key ] ) && is_array( $value[ $key ] ) ? $value[ $key ] : array(), $keys, $item );
		return $value;
	}
}
