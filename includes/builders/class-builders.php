<?php
/**
 * Page builders: registry, recognition and the helpers both editions use.
 *
 * The free plugin recognises every builder listed in signatures.php and reads
 * the ones an adapter is registered for, as a compact outline. It writes only
 * through the tools it already has (wp_update_post for pages whose source is
 * post_content) and refuses a content change the page would not show. Pro
 * writes through its own adapters; it checks the integer
 * AB_MCP_BUILDER_API (never the plugin version) before it loads them.
 *
 * Element format (one row of an outline):
 *   {
 *     "id": string,          the builder's own id where it has one (Elementor id,
 *                            GenerateBlocks uniqueId, Kadence uniqueID, Spectra
 *                            block_id, Pagelayer pagelayer-id, Beaver node,
 *                            SeedProd id, Enfold av_uid), else a path:
 *                            "b" + indices joined by "." for blocks ("b0.2.1"),
 *                            "s" + indices for shortcodes, "e" + indices for
 *                            Elementor elements, "p" + indices for SeedProd
 *                            blocks, "w" + index for SiteOrigin widgets and
 *                            "r<n>" / "r<n>.c<n>" for its rows and cells. A
 *                            path id is valid only together with the
 *                            layout_hash it was read with.
 *     "type": string,        block name, widget type, shortcode tag …
 *     "parent": string|null, id of the enclosing element.
 *     "depth": int,          0 for top-level elements.
 *     "fields": { name: { "kind": "text"|"heading"|"html"|"url"|"image",
 *                         "value": string|int, "truncated": true? } },
 *     "locked": true?, "reason": string?, "global": true?, "note": string?
 *   }
 * Fields carry visible content only — never CSS, scripts, attributes, classes
 * or styles. A locked element has no fields, and its children are not listed.
 *
 * Block paths: the indices are positions in the arrays parse_blocks()
 * returns — the top-level array including its freeform entries (the white
 * space between blocks), then innerBlocks. Pro walks the same arrays.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/*
 * Version of the builder contract between the free core and Pro. Pro loads its
 * builder parts only when this is defined and at least the version it was
 * built for: the free plugin can be older than the core Pro bundles, and the
 * older copy wins when both are active.
 */
if ( ! defined( 'AB_MCP_BUILDER_API' ) ) {
	define( 'AB_MCP_BUILDER_API', 1 );
}

require_once __DIR__ . '/class-builder-html.php';
require_once __DIR__ . '/class-builder-adapter.php';
require_once __DIR__ . '/class-block-reader.php';
require_once __DIR__ . '/class-shortcode-reader.php';
require_once __DIR__ . '/class-shortcode-codecs.php';
require_once __DIR__ . '/adapters/class-adapter-blocks.php';
require_once __DIR__ . '/class-element-profile.php';
require_once __DIR__ . '/adapters/class-adapter-elementor.php';
require_once __DIR__ . '/adapters/class-adapter-beaver.php';
require_once __DIR__ . '/adapters/class-adapter-siteorigin.php';
require_once __DIR__ . '/adapters/class-adapter-seedprod.php';
require_once __DIR__ . '/adapters/class-adapter-shortcodes.php';

/**
 * Class AB_MCP_Builders
 */
final class AB_MCP_Builders {

	/** Kinds a field may have. */
	const FIELD_KINDS = array( 'text', 'heading', 'html', 'url', 'image' );

	/** Storage kinds, plan §2. A2 is the plan's A′. */
	const STORAGE = array( 'A', 'A2', 'B', 'C' );

	/** Default cap of one field value, in characters (filter ab_mcp_builder_field_max_chars). */
	const FIELD_MAX_CHARS = 2000;

	/** Longest element type or field name an answer carries, in characters; no builder's is longer. */
	const NAME_MAX_CHARS = 100;

	/**
	 * Normalised signatures, keyed by builder id.
	 *
	 * @var array<string,array>|null
	 */
	private static $signatures = null;

	/**
	 * Registered adapters, keyed by adapter id.
	 *
	 * @var array<string,AB_MCP_Builder_Adapter>|null
	 */
	private static $adapters = null;

	/**
	 * Builder id => adapter that reads it.
	 *
	 * @var array<string,AB_MCP_Builder_Adapter>|null
	 */
	private static $readers = null;

	/**
	 * Forget the cached signatures, adapters and block profiles. The caches
	 * live for one request; tests and code that registers adapters late call
	 * this.
	 */
	public static function reset() {
		self::$signatures = null;
		self::$adapters   = null;
		self::$readers    = null;
		AB_MCP_Block_Reader::reset();
	}

	/* ------------------------------------------------------- signatures */

	/**
	 * Every known builder, from signatures.php plus the filter
	 * ab_mcp_builder_signatures. Malformed entries and markers are dropped.
	 *
	 * @return array<string,array>
	 */
	public static function signatures(): array {
		if ( null !== self::$signatures ) {
			return self::$signatures;
		}
		$raw = include __DIR__ . '/signatures.php';
		/**
		 * Add or correct builder signatures. Each entry follows the format at
		 * the top of includes/builders/signatures.php.
		 *
		 * @param array $signatures Builder id => signature.
		 */
		$raw = apply_filters( 'ab_mcp_builder_signatures', is_array( $raw ) ? $raw : array() );
		$out = array();
		foreach ( (array) $raw as $id => $sig ) {
			$norm = self::normalize_signature( $id, $sig );
			if ( null !== $norm ) {
				$out[ (string) $id ] = $norm;
			}
		}
		self::$signatures = $out;
		return $out;
	}

	/**
	 * One signature, or null.
	 *
	 * @param string $id Builder id.
	 * @return array|null
	 */
	public static function signature( $id ): ?array {
		$all = self::signatures();
		return isset( $all[ (string) $id ] ) ? $all[ (string) $id ] : null;
	}

	/**
	 * All known builder ids.
	 *
	 * @return string[]
	 */
	public static function builder_ids(): array {
		return array_keys( self::signatures() );
	}

	/**
	 * The markers of one builder found on a post, as short evidence strings
	 * ("meta:_elementor_edit_mode", "content:<!-- wp:kadence/"); empty when
	 * the post is not built with it.
	 *
	 * @param string  $id   Builder id.
	 * @param WP_Post $post Post.
	 * @return string[]
	 */
	public static function markers_found( $id, $post ): array {
		$sig = self::signature( $id );
		if ( null === $sig || ! is_object( $post ) ) {
			return array();
		}
		$found = self::match_markers( $sig['markers'], $post );
		if ( array() !== $found && array() !== $sig['unless'] && array() !== self::match_markers( $sig['unless'], $post ) ) {
			return array();
		}
		return $found;
	}

	/**
	 * Whether a builder is loaded: true, false, or null when no check is
	 * documented for it.
	 *
	 * @param string $id Builder id.
	 * @return bool|null
	 */
	public static function signature_active( $id ): ?bool {
		$sig = self::signature( $id );
		if ( null === $sig || array() === $sig['active'] ) {
			return null;
		}
		foreach ( $sig['active'] as $check ) {
			if ( self::check_active( $check ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Plugin version from the builder's version constant, when it is defined.
	 *
	 * @param string $id Builder id.
	 * @return string|null
	 */
	public static function signature_version( $id ): ?string {
		$sig = self::signature( $id );
		if ( null === $sig || '' === $sig['version'] || ! defined( $sig['version'] ) ) {
			return null;
		}
		$v = constant( $sig['version'] );
		return is_scalar( $v ) ? (string) $v : null;
	}

	/**
	 * Data format version stored with the post, where the signature names it.
	 *
	 * @param string  $id   Builder id.
	 * @param WP_Post $post Post.
	 * @return string|null
	 */
	public static function signature_data_version( $id, $post ): ?string {
		$sig = self::signature( $id );
		if ( null === $sig || empty( $sig['data_version']['meta'] ) || ! is_object( $post ) ) {
			return null;
		}
		$v = get_post_meta( (int) $post->ID, $sig['data_version']['meta'], true );
		return is_scalar( $v ) && '' !== (string) $v ? (string) $v : null;
	}

	/* --------------------------------------------------------- adapters */

	/**
	 * Registered adapters, keyed by id. The free plugin registers its own
	 * readers (the list below, plus one shortcode reader per profile);
	 * others come through the filter ab_mcp_builder_adapters. An adapter
	 * registered later replaces an earlier one with the same id.
	 *
	 * @return array<string,AB_MCP_Builder_Adapter>
	 */
	public static function adapters(): array {
		if ( null !== self::$adapters ) {
			return self::$adapters;
		}
		$defaults = array(
			new AB_MCP_Builder_Adapter_Blocks(),
			new AB_MCP_Builder_Adapter_Elementor(),
			new AB_MCP_Builder_Adapter_Beaver(),
			new AB_MCP_Builder_Adapter_SiteOrigin(),
			new AB_MCP_Builder_Adapter_SeedProd(),
		);
		// Shortcode builders (WPBakery, Divi 4, Avada, Flatsome, Enfold): one
		// adapter per profile in profiles/shortcodes-*.php.
		$defaults = array_merge( $defaults, AB_MCP_Builder_Adapter_Shortcodes::defaults() );
		/**
		 * Register page-builder adapters.
		 *
		 * @param AB_MCP_Builder_Adapter[] $adapters Adapters, in order.
		 */
		$list = apply_filters( 'ab_mcp_builder_adapters', $defaults );
		$out  = array();
		foreach ( (array) $list as $adapter ) {
			if ( $adapter instanceof AB_MCP_Builder_Adapter && '' !== $adapter->id() ) {
				$out[ $adapter->id() ] = $adapter;
			}
		}
		self::$adapters = $out;
		$readers        = array();
		foreach ( $out as $adapter ) {
			foreach ( $adapter->builders() as $builder ) {
				$readers[ (string) $builder ] = $adapter;
			}
		}
		self::$readers = $readers;
		return $out;
	}

	/**
	 * The adapter that reads one builder's pages, or null.
	 *
	 * @param string $builder Builder id.
	 * @return AB_MCP_Builder_Adapter|null
	 */
	public static function adapter_for_builder( $builder ): ?AB_MCP_Builder_Adapter {
		self::adapters();
		return isset( self::$readers[ (string) $builder ] ) ? self::$readers[ (string) $builder ] : null;
	}

	/* -------------------------------------------------------- recognition */

	/**
	 * Every builder whose markers the post carries, supported or not, in the
	 * order of signatures.php:
	 * [ id, name, active (bool|null), support ('read'|'detected_only'), storage, detected_by ].
	 * storage is this post's, as the reading adapter says (a storage-B
	 * builder that shows post_content for this post makes it 'A'), else the
	 * signature's; it does not depend on whether the builder is active (see
	 * effective_storage()).
	 *
	 * @param WP_Post $post Post.
	 * @return array<int,array>
	 */
	public static function detect_all( $post ): array {
		$out = array();
		if ( ! is_object( $post ) ) {
			return $out;
		}
		foreach ( self::signatures() as $id => $sig ) {
			$found = self::markers_found( $id, $post );
			if ( array() === $found ) {
				continue;
			}
			$reader  = self::adapter_for_builder( $id );
			$storage = null !== $reader ? $reader->storage( $post ) : $sig['storage'];
			$out[]   = array(
				'id'          => $id,
				'name'        => $sig['name'],
				'active'      => self::signature_active( $id ),
				'support'     => null !== $reader ? 'read' : 'detected_only',
				'storage'     => in_array( $storage, self::STORAGE, true ) ? $storage : $sig['storage'],
				'detected_by' => $found,
			);
		}
		// A third party's adapter for a builder that has no signature here
		// recognises its pages itself.
		foreach ( self::adapters() as $adapter ) {
			$id = $adapter->id();
			if ( 'blocks' === $id || null !== self::signature( $id ) || ! $adapter->detect( $post ) ) {
				continue;
			}
			$out[] = array(
				'id'          => $id,
				'name'        => $adapter->name(),
				'active'      => $adapter->is_active(),
				'support'     => 'read',
				'storage'     => in_array( $adapter->storage( $post ), self::STORAGE, true ) ? $adapter->storage( $post ) : 'A',
				'detected_by' => array( 'adapter:' . $id ),
			);
		}
		return $out;
	}

	/**
	 * The builder that answers for the page: the first detected one that is
	 * active, else the first whose state is unknown, else the first detected.
	 *
	 * @param WP_Post $post Post.
	 * @return array|null Entry of detect_all().
	 */
	public static function primary( $post ): ?array {
		$all = self::detect_all( $post );
		foreach ( array( true, null, false ) as $state ) {
			foreach ( $all as $entry ) {
				if ( $state === $entry['active'] ) {
					return $entry;
				}
			}
		}
		return null;
	}

	/**
	 * The adapter that reads what the site shows for this post: the primary
	 * builder's adapter when that builder is (or may be) active — null when
	 * no adapter reads it — and otherwise the block reader, because without
	 * an active builder WordPress shows post_content. The same holds where an
	 * active builder shows post_content for this post itself
	 * (own_data_shown() false, e.g. Elementor without a layout).
	 *
	 * @param WP_Post $post Post.
	 * @return AB_MCP_Builder_Adapter|null
	 */
	public static function for_post( $post ): ?AB_MCP_Builder_Adapter {
		$primary = self::primary( $post );
		if ( null !== $primary && false !== $primary['active'] ) {
			$adapter = self::adapter_for_builder( $primary['id'] );
			if ( null === $adapter || false !== $adapter->own_data_shown( $post ) ) {
				return $adapter;
			}
		}
		$adapters = self::adapters();
		return isset( $adapters['blocks'] ) ? $adapters['blocks'] : null;
	}

	/**
	 * Storage kind of what the site shows: the primary builder's for this
	 * post, unless that builder is inactive — then WordPress shows
	 * post_content (A). This is the storage the tools report; the builder's
	 * own, where it differs, is builder_storage.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function effective_storage( $post ): string {
		$primary = self::primary( $post );
		return null !== $primary && false !== $primary['active'] ? $primary['storage'] : 'A';
	}

	/**
	 * Builder information for wp_get_post; null when no builder is detected.
	 * builder, name, active, version, storage (what the site shows, see
	 * effective_storage()), builder_storage (only where the builder's own
	 * storage differs, e.g. while it is inactive), support, write_via, note
	 * (only where post_content is not simply the page), also (other builders
	 * detected on the same post).
	 *
	 * Pro's AB_MCP_Tools_Content_Pro::get_post() gets this through
	 * parent::get_post(); an override that does not call the parent adds it
	 * with this method.
	 *
	 * @param WP_Post $post Post.
	 * @return array|null
	 */
	public static function built_with( $post ): ?array {
		$all = self::detect_all( $post );
		if ( array() === $all ) {
			return null;
		}
		$primary = self::primary( $post );
		$storage = self::effective_storage( $post );
		$out     = array(
			'builder' => $primary['id'],
			'name'    => $primary['name'],
			'active'  => $primary['active'],
			'version' => true === $primary['active'] ? self::builder_version( $primary['id'] ) : null,
			'storage' => $storage,
		);
		if ( $primary['storage'] !== $storage ) {
			$out['builder_storage'] = $primary['storage'];
		}
		$out['support']   = $primary['support'];
		$out['write_via'] = self::write_via( $post );
		$note             = self::note_for( $primary );
		if ( '' !== $note ) {
			$out['note'] = $note;
		}
		$also = array();
		foreach ( $all as $entry ) {
			if ( $entry['id'] !== $primary['id'] ) {
				$also[] = $entry['id'];
			}
		}
		if ( array() !== $also ) {
			$out['also'] = $also;
		}
		return $out;
	}

	/**
	 * Tools that change this page with a visible effect. The free plugin
	 * names wp_update_post only where post_content is what the site shows
	 * (storage A); Pro adds its writers through ab_mcp_builder_write_via.
	 *
	 * @param WP_Post $post Post.
	 * @return string[]
	 */
	public static function write_via( $post ): array {
		$tools = 'A' === self::effective_storage( $post ) ? array( 'wp_update_post' ) : array();
		/**
		 * Tools that write this page with a visible effect.
		 *
		 * @param string[]   $tools   Tool names.
		 * @param WP_Post    $post    Post.
		 * @param array|null $primary The builder answering for the page (see primary()).
		 */
		$tools = apply_filters( 'ab_mcp_builder_write_via', $tools, $post, self::primary( $post ) );
		return array_values( array_unique( array_map( 'strval', (array) $tools ) ) );
	}

	/**
	 * What wp_update_post must know before it writes the field content:
	 * null — nothing to say; [ 'block' => true, 'message' ] — refuse, the
	 * page would not show the change; [ 'block' => false, 'message' ] —
	 * write, and pass the message on.
	 *
	 * - Storage B, builder active, an adapter reads it and says the builder
	 *   shows its own data for this post (own_data_shown() true): block.
	 *   post_content is only a copy the builder does not show (measured for
	 *   Elementor, Beaver, SiteOrigin, Themify, Zion, Live Composer and Brizy;
	 *   for Enfold from the vendor's documentation and code, quellen/enfold.md
	 *   b), not measured).
	 * - Storage B, builder active or of unknown state, no adapter or the
	 *   adapter cannot tell: write, with a warning — nothing gets worse than
	 *   it was (plan, principle 9).
	 * - A storage-B builder that shows post_content for this post (e.g.
	 *   Elementor without a layout): the post is storage A, see below.
	 * - Storage A2: write, with a warning that the builder restores its own
	 *   copy on the next save in the builder.
	 * - Builder inactive: write, with a note — WordPress shows post_content.
	 * - Storage A or no builder: null.
	 *
	 * The filter ab_mcp_builder_content_update_guard can loosen or tighten
	 * the answer (null switches the guard off for a post).
	 *
	 * Pro: AB_MCP_Tools_Content_Pro::update_post() reaches this through
	 * parent::update_post(), which asks before anything is written. An override
	 * that writes content without the parent calls this first and refuses
	 * when 'block' is true.
	 *
	 * @param WP_Post $post Post.
	 * @return array{block:bool,message:string,builder:string}|null
	 */
	public static function content_update_guard( $post ): ?array {
		$primary = self::primary( $post );
		$guard   = null;
		if ( null !== $primary ) {
			$name    = $primary['name'];
			$storage = $primary['storage'];
			if ( false === $primary['active'] ) {
				if ( in_array( $storage, array( 'B', 'A2' ), true ) ) {
					$guard = array(
						'block'   => false,
						/* translators: %s: page builder name */
						'message' => sprintf( __( '%s is not active, so the site shows post_content and this change is visible. If it is activated again, it shows its own copy of the page, without this change.', 'alphabridge-mcp' ), $name ),
					);
				}
			} elseif ( 'B' === $storage ) {
				$reader = self::adapter_for_builder( $primary['id'] );
				if ( true === $primary['active'] && 'read' === $primary['support'] && null !== $reader && true === $reader->own_data_shown( $post ) ) {
					$guard = array(
						'block'   => true,
						'message' => self::copy_only_message( $post, $name ),
					);
				} else {
					$guard = array(
						'block'   => false,
						/* translators: %s: page builder name */
						'message' => sprintf( __( 'This page looks built with %s, which may show its own data rather than post_content, so this change may not be visible. Check the page; to change what it shows, edit it in the builder.', 'alphabridge-mcp' ), $name ),
					);
				}
			} elseif ( 'A2' === $storage ) {
				$guard = array(
					'block'   => false,
					/* translators: %1$s, %2$s, %3$s: page builder name */
					'message' => sprintf( __( 'The change is visible now, but %1$s keeps its own copy of this page and restores it the next time someone saves the page in %2$s, so this change will be lost then. To keep it, make the same change in the %3$s editor.', 'alphabridge-mcp' ), $name, $name, $name ),
				);
			}
			if ( null !== $guard ) {
				$guard['builder'] = $primary['id'];
			}
		}
		/**
		 * The answer to "may wp_update_post write content on this post?".
		 * Return null to let every content change through on this post.
		 *
		 * @param array|null $guard   [ 'block' => bool, 'message' => string, 'builder' => string ] or null.
		 * @param WP_Post    $post    Post.
		 * @param array|null $primary The builder answering for the page.
		 */
		$guard = apply_filters( 'ab_mcp_builder_content_update_guard', $guard, $post, $primary );
		if ( ! is_array( $guard ) || ! isset( $guard['message'] ) ) {
			return null;
		}
		return array(
			'block'   => ! empty( $guard['block'] ),
			'message' => (string) $guard['message'],
			'builder' => isset( $guard['builder'] ) ? (string) $guard['builder'] : '',
		);
	}

	/* ----------------------------------------------- copies, raw, hash */

	/**
	 * Stored copies of the page that exist on this post.
	 *
	 * @param WP_Post $post Post.
	 * @return array<int,array{loc:string,shown:bool,role:string}>
	 */
	public static function copies( $post ): array {
		$adapter = self::for_post( $post );
		if ( null !== $adapter ) {
			return $adapter->copies( $post );
		}
		$primary = self::primary( $post );
		$sig     = null !== $primary ? self::signature( $primary['id'] ) : null;
		if ( null === $sig ) {
			return self::existing_copies( $post, array( array( 'loc' => 'post_content', 'shown' => true, 'role' => 'source' ) ), array() );
		}
		return self::existing_copies( $post, $sig['copies'], $sig['locked_meta'] );
	}

	/**
	 * Raw values of every copy, keyed by loc (see AB_MCP_Builder_Adapter::raw()).
	 *
	 * @param WP_Post $post Post.
	 * @return array<string,string|null>
	 */
	public static function raw( $post ): array {
		$adapter = self::for_post( $post );
		if ( null !== $adapter ) {
			return $adapter->raw( $post );
		}
		$primary = self::primary( $post );
		$sig     = null !== $primary ? self::signature( $primary['id'] ) : null;
		return self::raw_values( $post, self::copies( $post ), null !== $sig ? $sig['locked_meta'] : array() );
	}

	/**
	 * sha256 over the raw values of every copy, keys sorted. Changes whenever
	 * any stored copy of the page changes; Free and Pro compute it here, the
	 * same way. A value that is not valid UTF-8 goes in as {"base64": …}: plain
	 * json_encode() would fail on it, and every broken page would share one hash.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function layout_hash( $post ): string {
		$raw = self::raw( $post );
		ksort( $raw, SORT_STRING );
		foreach ( $raw as $loc => $value ) {
			if ( is_string( $value ) && 1 !== preg_match( '//u', $value ) ) {
				$raw[ $loc ] = array( 'base64' => base64_encode( $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a stable form of bytes, not obfuscation.
			}
		}
		return hash( 'sha256', (string) json_encode( $raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the same bytes in Free and Pro, without WordPress' sanity pass.
	}

	/**
	 * The copies of a list that exist on the post, never a locked key.
	 * post_content is always there; a meta copy only when the key exists.
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $copies Declared copies.
	 * @param array   $locked Locked meta keys.
	 * @return array<int,array{loc:string,shown:bool,role:string}>
	 */
	public static function existing_copies( $post, array $copies, array $locked ): array {
		$out  = array();
		$seen = array();
		foreach ( $copies as $copy ) {
			$loc = (string) $copy['loc'];
			if ( isset( $seen[ $loc ] ) ) {
				continue;
			}
			if ( 0 === strpos( $loc, 'meta:' ) ) {
				$key = substr( $loc, 5 );
				if ( self::is_locked_key( $key, $locked ) || array() === (array) get_post_meta( (int) $post->ID, $key, false ) ) {
					continue;
				}
			} elseif ( 'post_content_filtered' === $loc && '' === (string) ( isset( $post->post_content_filtered ) ? $post->post_content_filtered : '' ) ) {
				continue;
			}
			$seen[ $loc ] = true;
			$out[]        = array(
				'loc'   => $loc,
				'shown' => (bool) $copy['shown'],
				'role'  => (string) $copy['role'],
			);
		}
		return $out;
	}

	/**
	 * Raw values of copies, keys sorted. Meta comes straight from the table,
	 * as stored (the first row of a key, as get_post_meta( …, true ) reads it).
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $copies Copies.
	 * @param array   $locked Locked meta keys.
	 * @return array<string,string|null>
	 */
	public static function raw_values( $post, array $copies, array $locked ): array {
		$out = array();
		foreach ( $copies as $copy ) {
			$loc = (string) $copy['loc'];
			if ( 'post_content' === $loc ) {
				$out[ $loc ] = (string) $post->post_content;
			} elseif ( 'post_content_filtered' === $loc ) {
				$out[ $loc ] = isset( $post->post_content_filtered ) ? (string) $post->post_content_filtered : '';
			} elseif ( 0 === strpos( $loc, 'meta:' ) ) {
				$key = substr( $loc, 5 );
				if ( ! self::is_locked_key( $key, $locked ) ) {
					$out[ $loc ] = self::raw_meta( (int) $post->ID, $key );
				}
			}
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * Whether a meta key is locked (holds page code). "*" at the end of a
	 * locked entry matches a prefix.
	 *
	 * @param string $key    Meta key.
	 * @param array  $locked Locked entries.
	 * @return bool
	 */
	public static function is_locked_key( $key, array $locked ): bool {
		foreach ( $locked as $entry ) {
			$entry = (string) $entry;
			if ( '*' === substr( $entry, -1 ) ? 0 === strpos( (string) $key, substr( $entry, 0, -1 ) ) : (string) $key === $entry ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------- page-builder data in meta */

	/**
	 * Post meta keys in which a page builder keeps markup or code that ends
	 * up in the page, or that decide how it renders: over every signature,
	 * those added through ab_mcp_builder_signatures included, the meta copies
	 * of the page (copies), the keys with page code (locked_meta) and the
	 * further keys a signature names (markup_meta). The "meta" argument of
	 * wp_create_post and wp_update_post stores a value as given, so every key
	 * here takes the unfiltered_html capability. That holds for keys without
	 * "_" too (panels_data, dslc_code, pagelayer-data, brizy, mfn-page-items,
	 * tve_updated_post …), which the post writers would otherwise store for
	 * anyone who may edit the post. An entry ending in "*" matches every key
	 * that starts with what comes before it.
	 *
	 * Meant for both editions: on 02.10.2026 it holds the same keys as Pro's
	 * AB_MCP_Tools_Content_Pro::markup_meta_keys().
	 *
	 * @return string[]
	 */
	public static function markup_meta_keys(): array {
		$keys = array();
		foreach ( self::signatures() as $sig ) {
			foreach ( $sig['copies'] as $copy ) {
				if ( 0 === strpos( $copy['loc'], 'meta:' ) ) {
					$keys[] = substr( $copy['loc'], 5 );
				}
			}
			$keys = array_merge( $keys, $sig['locked_meta'], $sig['markup_meta'] );
		}
		/**
		 * Meta keys only an account with unfiltered_html may write through the
		 * "meta" argument of wp_create_post and wp_update_post. Extend the
		 * list, or narrow it: a key taken off goes back to every account that
		 * may edit the post.
		 *
		 * @param string[] $keys Meta keys; an entry ending in "*" is a prefix.
		 */
		$keys = apply_filters( 'ab_mcp_builder_markup_meta_keys', array_values( array_unique( $keys ) ) );
		return array_values( array_filter( (array) $keys, 'is_string' ) );
	}

	/**
	 * Is this key page-builder data (markup_meta_keys())? Letter case does
	 * not count: MySQL compares meta_key under a case-insensitive collation
	 * (utf8mb4_unicode_520_ci, wp-includes/class-wpdb.php:901), and
	 * update_metadata() picks the row it overwrites by that comparison
	 * (wp-includes/meta.php:265). The lower-cased key the post writers store
	 * after sanitize_key(), "vcv-pagecontent", therefore lands on the row
	 * "vcv-pageContent".
	 *
	 * @param string        $key  Meta key.
	 * @param string[]|null $keys The list, when the caller already has it.
	 * @return bool
	 */
	public static function is_markup_meta_key( $key, $keys = null ): bool {
		$key = strtolower( trim( (string) $key ) );
		if ( '' === $key ) {
			return false;
		}
		foreach ( null === $keys ? self::markup_meta_keys() : (array) $keys as $entry ) {
			if ( ! is_scalar( $entry ) ) {
				continue;
			}
			$entry = strtolower( trim( (string) $entry ) );
			if ( '*' === substr( $entry, -1 ) ) {
				// A bare "*" is no prefix: it would put every meta key of the
				// site behind unfiltered_html by accident.
				$prefix = substr( $entry, 0, -1 );
				if ( '' !== $prefix && 0 === strpos( $key, $prefix ) ) {
					return true;
				}
			} elseif ( '' !== $entry && $entry === $key ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The refusal for writing a page-builder key, or null when the account may
	 * write it. It says that nothing was written and names the ways: which
	 * accounts have the capability, the builder's own editor, the tools on
	 * this site that change the page's texts (for an existing post), the call
	 * without the key for the other fields, and the filter for site owners.
	 *
	 * @param string       $key  Meta key as it would be stored.
	 * @param WP_Post|null $post The post written to, when it exists.
	 * @return WP_Error|null
	 */
	public static function markup_meta_refusal( $key, $post = null ): ?WP_Error {
		if ( current_user_can( 'unfiltered_html' ) || ! self::is_markup_meta_key( $key ) ) {
			return null;
		}
		$message = sprintf(
			/* translators: %s: meta key */
			__( 'Nothing was written: the meta key "%s" holds page-builder data that ends up in the page as markup or code. Writing it takes an account that may store unfiltered HTML (the unfiltered_html capability): on a single site administrators and editors have it, on a multisite network only super admins, and nobody where DISALLOW_UNFILTERED_HTML is set. Connect with such an account, or change the page in the builder\'s own editor.', 'alphabridge-mcp' ),
			(string) $key
		);
		$tools = null !== $post ? array_values( array_diff( self::write_via( $post ), array( 'wp_update_post' ) ) ) : array();
		if ( array() !== $tools ) {
			/* translators: %s: comma-separated tool names */
			$message .= ' ' . sprintf( __( 'Change its texts with %s.', 'alphabridge-mcp' ), implode( ', ', $tools ) );
		}
		$message .= ' ' . __( 'The other fields still change: send the call again without this key. A site owner can take a key off this list with the ab_mcp_builder_markup_meta_keys filter.', 'alphabridge-mcp' );
		return new WP_Error( 'ab_mcp_needs_unfiltered_html', $message, array( 'meta_key' => (string) $key ) );
	}

	/* ------------------------------------------------------------ fields */

	/**
	 * Visible text of HTML: tags removed, hidden elements (script, style,
	 * svg …) dropped with their content, entities decoded, white space
	 * collapsed.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function field_text( $html ): string {
		return AB_MCP_Builder_Html::text( (string) $html );
	}

	/**
	 * The cap of one field value in characters: the requested value clamped
	 * to 100–10000, or the default (filter ab_mcp_builder_field_max_chars).
	 *
	 * @param int|null $requested Requested cap.
	 * @return int
	 */
	public static function field_max_chars( $requested = null ): int {
		if ( null === $requested ) {
			/**
			 * Default cap of one field value in outlines, in characters.
			 *
			 * @param int $chars Characters.
			 */
			$requested = (int) apply_filters( 'ab_mcp_builder_field_max_chars', self::FIELD_MAX_CHARS );
		}
		return max( 100, min( 10000, (int) $requested ) );
	}

	/**
	 * A field with its value cut to $max characters, marked "truncated".
	 * Long texts are cut so a page cannot flood the client's context — and so
	 * a planted instruction in a text field cannot run to any length.
	 *
	 * @param array $field Field [ kind, value ].
	 * @param int   $max   Characters.
	 * @return array
	 */
	public static function cap_field( array $field, $max ): array {
		if ( is_string( $field['value'] ) && self::length( $field['value'] ) > $max ) {
			$field['value']     = self::cut( $field['value'], (int) $max );
			$field['truncated'] = true;
		}
		return $field;
	}

	/**
	 * An element with every text that comes from the page bounded, not only
	 * its field values: a type, a field name or a note is written by whoever
	 * wrote the page as much as a text is (a widget type, a settings key, a
	 * dynamic field named in a note), and cap_field() alone would leave
	 * those to run to any length. The type is cut to NAME_MAX_CHARS; a field
	 * whose name is longer is left out — a cut name would address nothing —
	 * and the note counts it; field values, note and reason are cut to $max
	 * characters. A cut type, note or reason ends in "…".
	 *
	 * @param array $element Element (see the format at the top).
	 * @param int   $max     Characters, as for field values.
	 * @return array
	 */
	public static function bound_element( array $element, $max ): array {
		$max = max( 1, (int) $max );
		if ( isset( $element['type'] ) && is_string( $element['type'] ) && self::length( $element['type'] ) > self::NAME_MAX_CHARS ) {
			$element['type'] = self::cut( $element['type'], self::NAME_MAX_CHARS ) . '…';
		}
		$dropped = 0;
		if ( isset( $element['fields'] ) && is_array( $element['fields'] ) ) {
			foreach ( $element['fields'] as $name => $field ) {
				if ( self::length( (string) $name ) > self::NAME_MAX_CHARS ) {
					unset( $element['fields'][ $name ] );
					++$dropped;
				} elseif ( is_array( $field ) && array_key_exists( 'value', $field ) ) {
					$element['fields'][ $name ] = self::cap_field( $field, $max );
				}
			}
			if ( array() === $element['fields'] ) {
				unset( $element['fields'] );
			}
		}
		foreach ( array( 'note', 'reason' ) as $key ) {
			if ( isset( $element[ $key ] ) && is_string( $element[ $key ] ) && self::length( $element[ $key ] ) > $max ) {
				$element[ $key ] = self::cut( $element[ $key ], $max ) . '…';
			}
		}
		if ( $dropped > 0 ) {
			$note            = sprintf( '%d field(s) with a name longer than %d characters left out', $dropped, self::NAME_MAX_CHARS );
			$element['note'] = isset( $element['note'] ) && is_string( $element['note'] ) && '' !== $element['note'] ? $element['note'] . '; ' . $note : $note;
		}
		return $element;
	}

	/**
	 * Whether post_content holds blocks (WordPress' own test, has_blocks()).
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function has_blocks( $post ): bool {
		return is_object( $post ) && false !== strpos( (string) $post->post_content, '<!-- wp:' );
	}

	/* --------------------------------------------------- wp_list_posts */

	/**
	 * SQL condition for wp_list_posts' built_with filter: a builder id, 'any'
	 * (any known builder) or 'none' (none). Built from the same markers as
	 * the recognition, through $wpdb->prepare(): meta markers as a sub-select
	 * on postmeta, content markers as LIKE. A regex marker contributes its
	 * literal ("like"), so the list can include a page the exact recognition
	 * then reads differently — each listed item says which builder it found.
	 * Null for an unknown value.
	 *
	 * @param string $value Filter value.
	 * @param object $db    wpdb.
	 * @return string|null
	 */
	public static function list_where( $value, $db ): ?string {
		if ( ! is_object( $db ) || ! method_exists( $db, 'prepare' ) || ! method_exists( $db, 'esc_like' ) ) {
			return null;
		}
		$value = (string) $value;
		if ( 'any' === $value || 'none' === $value ) {
			$parts = array();
			foreach ( self::signatures() as $sig ) {
				foreach ( $sig['markers'] as $marker ) {
					$sql = self::marker_sql( $marker, $db );
					if ( '' !== $sql ) {
						$parts[] = $sql;
					}
				}
			}
			$any = array() === $parts ? '1 = 0' : '( ' . implode( ' OR ', $parts ) . ' )';
			return 'any' === $value ? $any : 'NOT ' . $any;
		}
		$sig = self::signature( $value );
		if ( null === $sig ) {
			return null;
		}
		$parts = array();
		foreach ( $sig['markers'] as $marker ) {
			$sql = self::marker_sql( $marker, $db );
			if ( '' !== $sql ) {
				$parts[] = $sql;
			}
		}
		if ( array() === $parts ) {
			return '1 = 0';
		}
		$where = '( ' . implode( ' OR ', $parts ) . ' )';
		$not   = array();
		foreach ( $sig['unless'] as $marker ) {
			$sql = self::marker_sql( $marker, $db );
			if ( '' !== $sql ) {
				$not[] = $sql;
			}
		}
		if ( array() !== $not ) {
			$where .= ' AND NOT ( ' . implode( ' OR ', $not ) . ' )';
		}
		return $where;
	}

	/* ------------------------------------------------------------ private */

	/**
	 * A signature with every key present and every marker usable, or null.
	 *
	 * @param mixed $id  Builder id.
	 * @param mixed $sig Raw signature.
	 * @return array|null
	 */
	private static function normalize_signature( $id, $sig ): ?array {
		if ( ! is_string( $id ) || '' === $id || ! is_array( $sig ) ) {
			return null;
		}
		$storage = isset( $sig['storage'] ) && in_array( $sig['storage'], self::STORAGE, true ) ? $sig['storage'] : 'A';
		$copies  = array();
		foreach ( isset( $sig['copies'] ) && is_array( $sig['copies'] ) ? $sig['copies'] : array() as $copy ) {
			if ( is_array( $copy ) && isset( $copy['loc'] ) && is_string( $copy['loc'] )
				&& ( in_array( $copy['loc'], array( 'post_content', 'post_content_filtered' ), true ) || ( 0 === strpos( $copy['loc'], 'meta:' ) && strlen( $copy['loc'] ) > 5 ) ) ) {
				$copies[] = array(
					'loc'   => $copy['loc'],
					'shown' => ! empty( $copy['shown'] ),
					'role'  => isset( $copy['role'] ) && in_array( $copy['role'], array( 'source', 'copy', 'draft' ), true ) ? $copy['role'] : 'copy',
				);
			}
		}
		$active = array();
		foreach ( isset( $sig['active'] ) && is_array( $sig['active'] ) ? $sig['active'] : array() as $check ) {
			if ( is_array( $check ) && 1 === count( $check ) && in_array( key( $check ), array( 'constant', 'class', 'function', 'plugin', 'theme' ), true ) && is_string( current( $check ) ) && '' !== current( $check ) ) {
				$active[] = $check;
			}
		}
		return array(
			'name'         => isset( $sig['name'] ) && is_string( $sig['name'] ) && '' !== $sig['name'] ? $sig['name'] : $id,
			'family'       => isset( $sig['family'] ) && is_string( $sig['family'] ) ? $sig['family'] : 'meta',
			'storage'      => $storage,
			'markers'      => self::normalize_markers( isset( $sig['markers'] ) ? $sig['markers'] : array() ),
			'unless'       => self::normalize_markers( isset( $sig['unless'] ) ? $sig['unless'] : array() ),
			'active'       => $active,
			'version'      => isset( $sig['version'] ) && is_string( $sig['version'] ) ? $sig['version'] : '',
			'data_version' => isset( $sig['data_version']['meta'] ) && is_string( $sig['data_version']['meta'] ) ? array( 'meta' => $sig['data_version']['meta'] ) : array(),
			'copies'       => $copies,
			'locked_meta'  => isset( $sig['locked_meta'] ) && is_array( $sig['locked_meta'] ) ? array_values( array_filter( $sig['locked_meta'], 'is_string' ) ) : array(),
			'markup_meta'  => isset( $sig['markup_meta'] ) && is_array( $sig['markup_meta'] ) ? array_values( array_filter( $sig['markup_meta'], 'is_string' ) ) : array(),
		);
	}

	/**
	 * Markers that can be evaluated; a malformed one or a broken pattern is
	 * dropped rather than answering wrongly.
	 *
	 * @param mixed $markers Raw markers.
	 * @return array
	 */
	private static function normalize_markers( $markers ): array {
		$out = array();
		foreach ( is_array( $markers ) ? $markers : array() as $m ) {
			if ( ! is_array( $m ) ) {
				continue;
			}
			if ( isset( $m['meta'] ) && is_string( $m['meta'] ) && '' !== $m['meta'] ) {
				$keep = array( 'meta' => $m['meta'] );
				if ( isset( $m['equals'] ) && is_scalar( $m['equals'] ) ) {
					$keep['equals'] = (string) $m['equals'];
				} elseif ( isset( $m['not_in'] ) && is_array( $m['not_in'] ) ) {
					$keep['not_in'] = array_values( array_map( 'strval', array_filter( $m['not_in'], 'is_scalar' ) ) );
				}
				$out[] = $keep;
			} elseif ( isset( $m['content'] ) && is_string( $m['content'] ) && '' !== $m['content'] ) {
				$out[] = array( 'content' => $m['content'] );
			} elseif ( isset( $m['content_prefix'] ) && is_string( $m['content_prefix'] ) && '' !== $m['content_prefix'] ) {
				$out[] = array( 'content_prefix' => $m['content_prefix'] );
			} elseif ( isset( $m['content_regex'] ) && is_string( $m['content_regex'] ) && false !== @preg_match( $m['content_regex'], '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- testing a third party's pattern once.
				$out[] = array(
					'content_regex' => $m['content_regex'],
					'like'          => isset( $m['like'] ) && is_string( $m['like'] ) ? $m['like'] : '',
				);
			}
		}
		return $out;
	}

	/**
	 * Evidence of the markers a post carries.
	 *
	 * @param array   $markers Normalised markers.
	 * @param WP_Post $post    Post.
	 * @return string[]
	 */
	private static function match_markers( array $markers, $post ): array {
		$found   = array();
		$content = (string) $post->post_content;
		foreach ( $markers as $m ) {
			if ( isset( $m['meta'] ) ) {
				$value = get_post_meta( (int) $post->ID, $m['meta'], true );
				if ( isset( $m['equals'] ) ) {
					$hit = is_scalar( $value ) && (string) $value === $m['equals'];
				} else {
					$hit = ! self::is_empty_value( $value )
						&& ! ( isset( $m['not_in'] ) && is_scalar( $value ) && in_array( (string) $value, $m['not_in'], true ) );
				}
				if ( $hit ) {
					$found[] = 'meta:' . $m['meta'];
				}
			} elseif ( isset( $m['content'] ) ) {
				if ( false !== strpos( $content, $m['content'] ) ) {
					$found[] = 'content:' . $m['content'];
				}
			} elseif ( isset( $m['content_prefix'] ) ) {
				if ( 0 === strpos( $content, $m['content_prefix'] ) ) {
					$found[] = 'content:' . $m['content_prefix'];
				}
			} elseif ( isset( $m['content_regex'] ) ) {
				if ( 1 === preg_match( $m['content_regex'], $content ) ) {
					$found[] = 'content:' . ( '' !== $m['like'] ? $m['like'] : $m['content_regex'] );
				}
			}
		}
		return $found;
	}

	/**
	 * A meta value that does not count as set: missing, '', '0', false or an
	 * empty array — what PHP and the builders' own checks read as off.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_empty_value( $value ): bool {
		return null === $value || false === $value || '' === $value || '0' === $value || 0 === $value || array() === $value;
	}

	/**
	 * One active check.
	 *
	 * @param array $check [ type => value ].
	 * @return bool
	 */
	private static function check_active( array $check ): bool {
		$type  = key( $check );
		$value = ltrim( (string) current( $check ), '\\' );
		try {
			switch ( $type ) {
				case 'constant':
					return defined( $value );
				case 'class':
					return class_exists( $value );
				case 'function':
					return function_exists( $value );
				case 'plugin':
					// What is_plugin_active() reads, without loading wp-admin code.
					if ( in_array( $value, (array) get_option( 'active_plugins', array() ), true ) ) {
						return true;
					}
					if ( is_multisite() ) {
						$network = (array) get_site_option( 'active_sitewide_plugins', array() );
						return isset( $network[ $value ] );
					}
					return false;
				case 'theme':
					return ( function_exists( 'get_template' ) && get_template() === $value )
						|| ( function_exists( 'get_stylesheet' ) && get_stylesheet() === $value );
			}
		} catch ( Throwable $e ) {
			// A third party's autoloader that throws means "not loaded" here.
			return false;
		}
		return false;
	}

	/**
	 * The version to report for a builder: its adapter's, where the adapter
	 * is that builder's own, else the signature's version constant.
	 *
	 * @param string $id Builder id.
	 * @return string|null
	 */
	private static function builder_version( $id ): ?string {
		$adapter = self::adapter_for_builder( $id );
		if ( null !== $adapter && $adapter->id() === $id ) {
			return $adapter->version();
		}
		return self::signature_version( $id );
	}

	/**
	 * The short note built_with carries where post_content is not simply the
	 * page.
	 *
	 * @param array $primary Entry of detect_all().
	 * @return string
	 */
	private static function note_for( array $primary ): string {
		$name = $primary['name'];
		$sig  = self::signature( $primary['id'] );
		if ( false !== $primary['active'] && 'A' === $primary['storage'] && null !== $sig && 'B' === $sig['storage'] ) {
			/* translators: %s: page builder name */
			return sprintf( __( '%s is set for this page but shows no layout of its own for it, so the site shows post_content.', 'alphabridge-mcp' ), $name );
		}
		if ( false === $primary['active'] ) {
			if ( in_array( $primary['storage'], array( 'B', 'A2' ), true ) ) {
				/* translators: %s: page builder name */
				return sprintf( __( '%s is not active: the site shows post_content. Its own copy of the page stays stored and comes back when it is activated.', 'alphabridge-mcp' ), $name );
			}
			return '';
		}
		if ( 'B' === $primary['storage'] ) {
			/* translators: %s: page builder name */
			return sprintf( __( 'post_content is only a copy: %s shows its own data, so a change to content does not appear on the page. Read the page with wp_get_builder_layout.', 'alphabridge-mcp' ), $name );
		}
		if ( 'A2' === $primary['storage'] ) {
			/* translators: %1$s, %2$s: page builder name */
			return sprintf( __( 'post_content is what the site shows, but %1$s keeps its own copy of the page and restores it the next time the page is saved in %2$s. Read the page with wp_get_builder_layout.', 'alphabridge-mcp' ), $name, $name );
		}
		if ( 'C' === $primary['storage'] ) {
			/* translators: %1$s, %2$s: page builder name */
			return sprintf( __( '%1$s keeps this page in its own database tables, which no tool here reads; read and change it in the %2$s editor in wp-admin.', 'alphabridge-mcp' ), $name, $name );
		}
		return '';
	}

	/**
	 * The refusal for a content change on a storage-B page, with the way.
	 *
	 * @param WP_Post $post Post.
	 * @param string  $name Builder name.
	 * @return string
	 */
	private static function copy_only_message( $post, $name ) {
		/* translators: %s: page builder name */
		$msg   = sprintf( __( 'Nothing was written: this page is built with %s, which shows its own data. post_content is only a copy the page does not show, so this change would not appear on the page. Read the page with wp_get_builder_layout.', 'alphabridge-mcp' ), $name );
		$tools = array_values( array_diff( self::write_via( $post ), array( 'wp_update_post' ) ) );
		if ( array() !== $tools ) {
			/* translators: %s: comma-separated tool names */
			$msg .= ' ' . sprintf( __( 'Change its texts with %s.', 'alphabridge-mcp' ), implode( ', ', $tools ) );
		} else {
			/* translators: %1$s, %2$s: page builder name */
			$msg .= ' ' . sprintf( __( 'Change its texts in the %1$s editor in wp-admin: no tool on this site writes the data of %2$s.', 'alphabridge-mcp' ), $name, $name );
		}
		return $msg . ' ' . __( 'Title, status, excerpt and the other fields still change: call wp_update_post again without content.', 'alphabridge-mcp' );
	}

	/**
	 * SQL for one marker, or '' when it has no SQL form.
	 *
	 * @param array  $m  Normalised marker.
	 * @param object $db wpdb.
	 * @return string
	 */
	private static function marker_sql( array $m, $db ): string {
		if ( isset( $m['meta'] ) ) {
			if ( isset( $m['equals'] ) ) {
				return $db->prepare( "{$db->posts}.ID IN ( SELECT post_id FROM {$db->postmeta} WHERE meta_key = %s AND meta_value = %s )", $m['meta'], $m['equals'] );
			}
			$skip = array_merge( array( '', '0' ), isset( $m['not_in'] ) ? $m['not_in'] : array() );
			$list = implode( ', ', array_fill( 0, count( $skip ), '%s' ) );
			return $db->prepare( "{$db->posts}.ID IN ( SELECT post_id FROM {$db->postmeta} WHERE meta_key = %s AND meta_value NOT IN ( {$list} ) )", array_merge( array( $m['meta'] ), $skip ) );
		}
		if ( isset( $m['content'] ) ) {
			return $db->prepare( "{$db->posts}.post_content LIKE %s", '%' . $db->esc_like( $m['content'] ) . '%' );
		}
		if ( isset( $m['content_prefix'] ) ) {
			return $db->prepare( "{$db->posts}.post_content LIKE %s", $db->esc_like( $m['content_prefix'] ) . '%' );
		}
		if ( isset( $m['content_regex'] ) && '' !== $m['like'] ) {
			return $db->prepare( "{$db->posts}.post_content LIKE %s", '%' . $db->esc_like( $m['like'] ) . '%' );
		}
		return '';
	}

	/**
	 * A meta value exactly as stored. Through $wpdb on a site — the raw row,
	 * not get_post_meta(), which unserializes; without a database (the unit
	 * tests) the unserialized value serialized again.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @return string|null Null when the key does not exist.
	 */
	private static function raw_meta( $post_id, $key ) {
		global $wpdb;
		if ( is_object( $wpdb ) && isset( $wpdb->postmeta ) && method_exists( $wpdb, 'get_var' ) ) {
			$value = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1", $post_id, $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the stored bytes are the point; the cache holds unserialized values.
			return null === $value ? null : (string) $value;
		}
		$values = (array) get_post_meta( $post_id, $key, false );
		if ( array() === $values ) {
			return null;
		}
		$first = reset( $values );
		return is_string( $first ) ? $first : serialize( $first ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- mirrors maybe_serialize() for the test double.
	}

	/**
	 * Length in characters (UTF-8), bytes where mbstring is missing.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * The first $max characters.
	 *
	 * @param string $text Text.
	 * @param int    $max  Characters.
	 * @return string
	 */
	private static function cut( $text, $max ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max, 'UTF-8' ) : substr( $text, 0, $max );
	}
}
