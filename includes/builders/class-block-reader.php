<?php
/**
 * Reads pages made of blocks (storage A) as an outline: WordPress' own
 * parse_blocks() tree, one profile per block library, and a general reader
 * for every block no profile knows.
 *
 * PROFILES ARE DATA. A profile is a PHP file in profiles/ named blocks-*.php
 * that returns an array; adding a library means adding such a file (or
 * filtering ab_mcp_builder_block_profiles), never a class. Format:
 *
 *   return array(
 *     'id'         => 'kadence',              // profile id, unique
 *     'builder'    => 'kadence',              // builder id in signatures.php ('blocks' for core)
 *     'verified'   => true,                   // measured at a real installation
 *     'source'     => 'bloecke/README.md …',  // where the entries come from
 *     'namespaces' => array( 'kadence/' ),    // block name prefixes the profile speaks for
 *     'id_attr'    => 'uniqueID',             // attribute with the library's element id, or ''
 *     'blocks'     => array(
 *       'kadence/advancedheading' => array(
 *         'type'     => 'heading',            // optional; default: the block name
 *         'verified' => false,                // optional; overrides the profile's flag
 *                                             // (a field may carry its own 'verified' too)
 *         'id_attr'  => 'uniqueID',           // optional; overrides the profile's
 *         'fields'   => array(
 *           'text' => array( 'kind' => 'heading', 'from' => 'html_text', 'selector' => 'h1,h2,h3,h4,h5,h6,p' ),
 *         ),
 *       ),
 *       'kadence/singlebtn' => array( 'fields' => array(
 *           'text' => array( 'kind' => 'text', 'from' => 'attr', 'path' => 'text' ),
 *           'url'  => array( 'kind' => 'url',  'from' => 'attr', 'path' => 'link' ),
 *       ) ),
 *       'generateblocks/text' => array( 'fields' => array(
 *           'url' => array( 'kind' => 'url', 'from' => 'html_attr', 'selector' => 'a', 'attr' => 'href',
 *                           'also' => array( array( 'from' => 'attr', 'path' => 'htmlAttributes.href' ) ) ),
 *       ) ),
 *       'kadence/rowlayout'   => array( 'container' => true ),       // structure only, children are read
 *       'generateblocks/shape' => array( 'locked' => 'svg markup' ),  // listed without content, children skipped
 *       'core/block'          => array( 'locked' => 'global element', 'global' => true, 'ref_attr' => 'ref' ),
 *       'generateblocks/button' => array(
 *           'locked_when' => array( 'useDynamicData' => 'dynamic value' ), // locked when the block sets it
 *           'fields'      => array( … ),
 *       ),
 *     ),
 *   );
 *
 * "locked_when" maps attribute paths to a reason: the element is locked like
 * one with "locked" when the block sets any of them (present and not false,
 * null, '', 0, '0' or an empty array). It is for blocks whose saved markup is
 * only a placeholder once an option is on — the page then shows something
 * else, so the stored text is neither what visitors see nor worth changing.
 *
 * Field sources ("from"), all read from the block's OWN markup (its
 * innerContent strings; inner blocks are separate elements):
 * - html_text   visible text of the first element matching "selector" (the
 *               whole own markup without a selector);
 * - html_inner  inner HTML of that element, reduced to simple inline HTML;
 * - html_attr   attribute "attr" of that element; only href, src, alt and
 *               title can be read;
 * - attr        block comment attribute at "path" (dots for nesting); paths
 *               that touch CSS, styles, scripts, classes, code, HTML or
 *               attribute maps cannot be read.
 * "also" lists further copies of the same value (same keys as a field
 * without kind). The reader compares them and notes a stale copy; Pro's
 * writer changes them together with the field. Selector grammar: see
 * AB_MCP_Builder_Html::find().
 *
 * Kinds: text and heading become plain text, html simple inline HTML, url a
 * link (javascript:, vbscript: and data: addresses are left out), image an
 * attachment id (int) or an address.
 *
 * Blocks without a profile entry are read by the general reader: type = block
 * name, one field "text" with the visible text of the block's own markup, and
 * the note "profile not verified, read only".
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Block_Reader
 */
final class AB_MCP_Block_Reader {

	/** Note on elements read without a profile. */
	const NOTE_UNVERIFIED = 'profile not verified, read only';

	/** Note on elements read with a profile entry that was not measured. */
	const NOTE_UNMEASURED = 'profile not measured, read only';

	/** Field sources a profile may name. */
	const SOURCES = array( 'html_text', 'html_inner', 'html_attr', 'attr' );

	/** HTML attributes a field may read: link and image addresses, alt and title texts. */
	const HTML_ATTRS = array( 'href', 'src', 'alt', 'title' );

	/**
	 * Words that make an attribute path segment unreadable: the segment holds
	 * CSS, styles, scripts, classes, code, raw markup or a whole map of HTML
	 * attributes. Segments are split into words at camelCase, "_" and "-", so
	 * "kadenceBlockCSS", "customCSS", "className" and "htmlAttributes" are
	 * caught and "description" is not.
	 */
	const DENIED_WORDS = array( 'css', 'style', 'styles', 'script', 'scripts', 'js', 'javascript', 'class', 'classes', 'classname', 'code', 'html', 'attribute', 'attributes', 'attrs', 'svg', 'iframe', 'embed' );

	/**
	 * Merged profiles: block name => [ spec, profile ], plus namespaces.
	 *
	 * @var array|null
	 */
	private static $index = null;

	/**
	 * Forget the loaded profiles.
	 */
	public static function reset() {
		self::$index = null;
	}

	/**
	 * The loaded block profiles (normalised), core first.
	 *
	 * @return array<int,array>
	 */
	public static function profiles(): array {
		$files = glob( __DIR__ . '/profiles/blocks-*.php' );
		$files = is_array( $files ) ? $files : array();
		usort(
			$files,
			static function ( $a, $b ) {
				$ca = 'blocks-core.php' === basename( $a ) ? 0 : 1;
				$cb = 'blocks-core.php' === basename( $b ) ? 0 : 1;
				return $ca <=> $cb ?: strcmp( basename( $a ), basename( $b ) );
			}
		);
		$raw = array();
		foreach ( $files as $file ) {
			$profile = include $file;
			if ( is_array( $profile ) ) {
				$raw[] = $profile;
			}
		}
		/**
		 * Block profiles, core first. Add one per block library; the format is
		 * documented in includes/builders/class-block-reader.php.
		 *
		 * @param array[] $profiles Profiles.
		 */
		$raw = apply_filters( 'ab_mcp_builder_block_profiles', $raw );
		$out = array();
		foreach ( (array) $raw as $profile ) {
			$norm = self::normalize_profile( $profile );
			if ( null !== $norm ) {
				$out[] = $norm;
			}
		}
		return $out;
	}

	/**
	 * The page as an outline (element format: see AB_MCP_Builders).
	 *
	 * @param string $content post_content.
	 * @param array  $o       include_locked (true), max_elements (500), max_field_chars.
	 * @return array<int,array>
	 */
	public static function outline( $content, array $o ): array {
		$o = array(
			'include_locked'  => array_key_exists( 'include_locked', $o ) ? (bool) $o['include_locked'] : true,
			'max_elements'    => isset( $o['max_elements'] ) ? max( 1, (int) $o['max_elements'] ) : 500,
			'max_field_chars' => AB_MCP_Builders::field_max_chars( isset( $o['max_field_chars'] ) ? (int) $o['max_field_chars'] : null ),
		);
		$blocks = parse_blocks( (string) $content );
		$counts = array();
		self::count_ids( $blocks, $counts );
		$out = array();
		self::walk( $blocks, array(), null, 0, $o, $counts, $out );
		return $out;
	}

	/**
	 * Builder ids that are usable: short, plain, and unable to be mistaken
	 * for a path id.
	 *
	 * @param string $id Candidate.
	 * @return bool
	 */
	public static function usable_id( $id ): bool {
		return 1 === preg_match( '~^[A-Za-z0-9_.:-]{1,64}$~', (string) $id )
			&& 1 !== preg_match( '~^[bsw]\d+(?:\.\d+)*$~', (string) $id );
	}

	/* ------------------------------------------------------------ private */

	/**
	 * Walk one level of blocks, adding elements in page order.
	 *
	 * @param array       $blocks Blocks.
	 * @param int[]       $path   Path of the parent.
	 * @param string|null $parent Parent element id.
	 * @param int         $depth  Depth.
	 * @param array       $o      Options.
	 * @param array       $counts Occurrences of each builder id on the page.
	 * @param array       $out    Elements (by reference).
	 * @return bool False once max_elements is reached.
	 */
	private static function walk( array $blocks, array $path, $parent, $depth, array $o, array $counts, array &$out ) {
		foreach ( $blocks as $i => $block ) {
			if ( count( $out ) >= $o['max_elements'] ) {
				return false;
			}
			$p    = array_merge( $path, array( (int) $i ) );
			$name = isset( $block['blockName'] ) ? $block['blockName'] : null;
			$own  = self::own_html( $block );

			if ( null === $name ) {
				// Text between blocks is white space; anything else is classic
				// content, which may hold any HTML.
				if ( '' === trim( $own ) || ! $o['include_locked'] ) {
					continue;
				}
				$out[] = array(
					'id'     => 'b' . implode( '.', $p ),
					'type'   => 'core/freeform',
					'parent' => $parent,
					'depth'  => $depth,
					'locked' => true,
					'reason' => 'classic content',
				);
				continue;
			}

			$found   = self::lookup( (string) $name );
			$spec    = $found['spec'];
			$id      = self::element_id( $block, $p, $found['id_attr'], $counts );
			$element = array(
				'id'     => $id,
				'type'   => null !== $spec && '' !== $spec['type'] ? $spec['type'] : (string) $name,
				'parent' => $parent,
				'depth'  => $depth,
			);

			$reason = null !== $spec ? self::locked_reason( $spec, $block ) : '';
			if ( '' !== $reason ) {
				if ( $o['include_locked'] ) {
					$element['locked'] = true;
					$element['reason'] = $reason;
					if ( $spec['global'] ) {
						$element['global'] = true;
					}
					if ( '' !== $spec['ref_attr'] && isset( $block['attrs'][ $spec['ref_attr'] ] ) && is_scalar( $block['attrs'][ $spec['ref_attr'] ] ) ) {
						$element['note'] = sprintf( 'its content is post #%d', (int) $block['attrs'][ $spec['ref_attr'] ] );
					}
					$out[] = $element;
				}
				continue; // Children of a locked element are not listed.
			}

			$notes = array();
			if ( null === $spec ) {
				$text = AB_MCP_Builders::field_text( $own );
				if ( '' !== $text ) {
					$element['fields'] = array(
						'text' => AB_MCP_Builders::cap_field(
							array(
								'kind'  => 'text',
								'value' => $text,
							),
							$o['max_field_chars']
						),
					);
				}
				$notes[] = self::NOTE_UNVERIFIED;
			} else {
				if ( $spec['global'] ) {
					$element['global'] = true;
				}
				$fields     = array();
				$unmeasured = false;
				foreach ( $spec['fields'] as $field_name => $field ) {
					$value = self::read_field( $block, $own, $field );
					if ( null === $value ) {
						continue;
					}
					if ( ! ( null === $field['verified'] ? $spec['verified'] : $field['verified'] ) ) {
						$unmeasured = true;
					}
					if ( 'url' === $field['kind'] || ( 'image' === $field['kind'] && is_string( $value ) ) ) {
						// An image source may be an inline raster image; a link may not.
						$is_src = 'image' === $field['kind'] || ( 'html_attr' === $field['from'] && 'src' === $field['attr'] );
						$safe   = AB_MCP_Builder_Html::safe_url( (string) $value, $is_src );
						if ( null === $safe ) {
							$notes[] = $field_name . ': an address that would run code, left out';
							continue;
						}
						$value = $safe;
					}
					$fields[ $field_name ] = AB_MCP_Builders::cap_field(
						array(
							'kind'  => $field['kind'],
							'value' => $value,
						),
						$o['max_field_chars']
					);
					foreach ( $field['also'] as $copy ) {
						$other = self::read_raw( $block, $own, $copy );
						$first = self::read_raw( $block, $own, $field );
						if ( null !== $other && null !== $first && trim( (string) $other ) !== trim( (string) $first ) ) {
							$notes[] = $field_name . ': the copy in ' . self::describe( $copy ) . ' differs';
						}
					}
				}
				if ( array() !== $fields ) {
					$element['fields'] = $fields;
				}
				if ( $unmeasured ) {
					$notes[] = self::NOTE_UNMEASURED;
				}
			}
			if ( array() !== $notes ) {
				$element['note'] = implode( '; ', array_values( array_unique( $notes ) ) );
			}
			$out[] = $element;

			$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
			if ( ! self::walk( $inner, $p, $id, $depth + 1, $o, $counts, $out ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A field's value, shaped by its kind, or null when its source is
	 * missing.
	 *
	 * @param array  $block Block.
	 * @param string $own   Own markup.
	 * @param array  $field Field spec.
	 * @return string|int|null
	 */
	private static function read_field( array $block, $own, array $field ) {
		$raw = self::read_raw( $block, $own, $field );
		if ( null === $raw ) {
			return null;
		}
		switch ( $field['kind'] ) {
			case 'text':
			case 'heading':
				// From markup: the element's visible text. From an attribute:
				// the attribute may itself hold rich text, so it is read the
				// same way — what a visitor would see of it.
				return AB_MCP_Builders::field_text( 'html_attr' === $field['from'] ? htmlspecialchars( (string) $raw, ENT_QUOTES, 'UTF-8' ) : (string) $raw );
			case 'html':
				return AB_MCP_Builder_Html::simple( (string) $raw );
			case 'image':
				return is_int( $raw ) || ( is_string( $raw ) && 1 === preg_match( '~^[0-9]+\z~', $raw ) ) || ( is_float( $raw ) && floor( $raw ) === $raw ) ? (int) $raw : trim( (string) $raw );
			default: // url.
				return trim( (string) $raw );
		}
	}

	/**
	 * The raw value a source points to: markup for html_text/html_inner, the
	 * decoded attribute for html_attr, a scalar for attr; null when missing.
	 *
	 * @param array  $block Block.
	 * @param string $own   Own markup.
	 * @param array  $src   from, selector, attr, path.
	 * @return string|int|float|null
	 */
	private static function read_raw( array $block, $own, array $src ) {
		switch ( $src['from'] ) {
			case 'html_text':
			case 'html_inner':
				return '' === $src['selector'] ? $own : AB_MCP_Builder_Html::inner( $own, $src['selector'] );
			case 'html_attr':
				return AB_MCP_Builder_Html::element_attr( $own, $src['selector'], $src['attr'] );
			case 'attr':
				$value = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
				foreach ( explode( '.', $src['path'] ) as $segment ) {
					if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
						return null;
					}
					$value = $value[ $segment ];
				}
				if ( is_bool( $value ) || ! is_scalar( $value ) ) {
					return null;
				}
				return $value;
		}
		return null;
	}

	/**
	 * Why an element is locked: the entry's fixed reason, else the reason of
	 * the first "locked_when" attribute the block sets, else ''.
	 *
	 * @param array $spec  Profile entry.
	 * @param array $block Block.
	 * @return string
	 */
	private static function locked_reason( array $spec, array $block ) {
		if ( '' !== $spec['locked'] ) {
			return $spec['locked'];
		}
		foreach ( $spec['locked_when'] as $path => $reason ) {
			$value = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			foreach ( explode( '.', $path ) as $segment ) {
				$value = is_array( $value ) && array_key_exists( $segment, $value ) ? $value[ $segment ] : null;
			}
			// empty() is the test on purpose: false, null, '', 0, '0' and an
			// empty array all mean "the option is off".
			if ( ! empty( $value ) ) {
				return $reason;
			}
		}
		return '';
	}

	/**
	 * Where a copy lives, for a note.
	 *
	 * @param array $src Source.
	 * @return string
	 */
	private static function describe( array $src ) {
		if ( 'attr' === $src['from'] ) {
			return 'attribute ' . $src['path'];
		}
		return 'markup' . ( '' !== $src['selector'] ? ' ' . $src['selector'] : '' ) . ( 'html_attr' === $src['from'] ? ' ' . $src['attr'] : '' );
	}

	/**
	 * The block's own markup: its innerContent strings, without inner blocks.
	 *
	 * @param array $block Block.
	 * @return string
	 */
	private static function own_html( array $block ) {
		$parts = isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : array();
		$own   = '';
		foreach ( $parts as $part ) {
			if ( is_string( $part ) ) {
				$own .= $part;
			}
		}
		return $own;
	}

	/**
	 * The element id: the library's own id where it is usable and unique on
	 * the page, else the path.
	 *
	 * @param array  $block   Block.
	 * @param int[]  $path    Path.
	 * @param string $id_attr Attribute holding the library id, or ''.
	 * @param array  $counts  Occurrences per id.
	 * @return string
	 */
	private static function element_id( array $block, array $path, $id_attr, array $counts ) {
		if ( '' !== $id_attr && isset( $block['attrs'][ $id_attr ] ) && is_scalar( $block['attrs'][ $id_attr ] ) ) {
			$candidate = (string) $block['attrs'][ $id_attr ];
			if ( self::usable_id( $candidate ) && 1 === ( isset( $counts[ $candidate ] ) ? $counts[ $candidate ] : 0 ) ) {
				return $candidate;
			}
		}
		return 'b' . implode( '.', $path );
	}

	/**
	 * Count every library id on the page, so a duplicated one (a copied
	 * block keeps its id) falls back to the path for all its copies.
	 *
	 * @param array $blocks Blocks.
	 * @param array $counts Counts (by reference).
	 * @param int   $depth  Nesting depth; far deeper than any page is not
	 *                      followed, so a crafted page cannot exhaust the stack.
	 */
	private static function count_ids( array $blocks, array &$counts, $depth = 0 ) {
		if ( $depth > 512 ) {
			return;
		}
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$found = self::lookup( (string) $block['blockName'] );
			if ( '' !== $found['id_attr'] && isset( $block['attrs'][ $found['id_attr'] ] ) && is_scalar( $block['attrs'][ $found['id_attr'] ] ) ) {
				$id            = (string) $block['attrs'][ $found['id_attr'] ];
				$counts[ $id ] = ( isset( $counts[ $id ] ) ? $counts[ $id ] : 0 ) + 1;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::count_ids( $block['innerBlocks'], $counts, $depth + 1 );
			}
		}
	}

	/**
	 * Profile entry, profile and id attribute for a block name.
	 *
	 * @param string $name Block name.
	 * @return array{spec:array|null,profile:array|null,id_attr:string}
	 */
	private static function lookup( $name ) {
		$index = self::index();
		if ( isset( $index['blocks'][ $name ] ) ) {
			$hit = $index['blocks'][ $name ];
			return array(
				'spec'    => $hit['spec'],
				'profile' => $hit['profile'],
				'id_attr' => '' !== $hit['spec']['id_attr'] ? $hit['spec']['id_attr'] : $hit['profile']['id_attr'],
			);
		}
		foreach ( $index['namespaces'] as $prefix => $profile ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				return array(
					'spec'    => null,
					'profile' => $profile,
					'id_attr' => $profile['id_attr'],
				);
			}
		}
		return array(
			'spec'    => null,
			'profile' => null,
			'id_attr' => '',
		);
	}

	/**
	 * Block name and namespace lookup over all profiles; the first profile
	 * that names a block keeps it.
	 *
	 * @return array{blocks:array,namespaces:array}
	 */
	private static function index() {
		if ( null !== self::$index ) {
			return self::$index;
		}
		$blocks     = array();
		$namespaces = array();
		foreach ( self::profiles() as $profile ) {
			foreach ( $profile['blocks'] as $name => $spec ) {
				if ( ! isset( $blocks[ $name ] ) ) {
					$blocks[ $name ] = array(
						'spec'    => $spec,
						'profile' => $profile,
					);
				}
			}
			foreach ( $profile['namespaces'] as $prefix ) {
				if ( ! isset( $namespaces[ $prefix ] ) ) {
					$namespaces[ $prefix ] = $profile;
				}
			}
		}
		self::$index = array(
			'blocks'     => $blocks,
			'namespaces' => $namespaces,
		);
		return self::$index;
	}

	/**
	 * Whether an attribute path segment may not be read (see DENIED_WORDS).
	 * An event handler ("onClick", "on_click", "onmouseover") is refused too.
	 *
	 * @param string $segment Path segment.
	 * @return bool
	 */
	public static function denied_segment( $segment ): bool {
		$segment = (string) $segment;
		if ( '' === $segment || 1 === preg_match( '~^on(?:click|dblclick|load|error|focus|blur|change|input|submit|key\w*|mouse\w*|pointer\w*|touch\w*)$~i', $segment ) ) {
			return true;
		}
		$words = preg_split( '~(?<=[a-z0-9])(?=[A-Z])|[_\-\s]+~', $segment );
		$words = array_values( array_filter( array_map( 'strtolower', is_array( $words ) ? $words : array() ), 'strlen' ) );
		if ( count( $words ) > 1 && 'on' === $words[0] ) {
			return true;
		}
		return array() !== array_intersect( $words, self::DENIED_WORDS );
	}

	/**
	 * A profile with every key present, or null. Entries and fields that do
	 * not follow the format are dropped — a field that cannot be read safely
	 * is not read at all.
	 *
	 * @param mixed $p Raw profile.
	 * @return array|null
	 */
	private static function normalize_profile( $p ) {
		if ( ! is_array( $p ) || empty( $p['id'] ) || ! is_string( $p['id'] ) || ! isset( $p['blocks'] ) || ! is_array( $p['blocks'] ) ) {
			return null;
		}
		$verified = ! empty( $p['verified'] );
		$out      = array(
			'id'         => $p['id'],
			'builder'    => isset( $p['builder'] ) && is_string( $p['builder'] ) ? $p['builder'] : $p['id'],
			'verified'   => $verified,
			'source'     => isset( $p['source'] ) && is_string( $p['source'] ) ? $p['source'] : '',
			'namespaces' => isset( $p['namespaces'] ) && is_array( $p['namespaces'] ) ? array_values( array_filter( $p['namespaces'], 'is_string' ) ) : array(),
			'id_attr'    => isset( $p['id_attr'] ) && is_string( $p['id_attr'] ) ? $p['id_attr'] : '',
			'blocks'     => array(),
		);
		foreach ( $p['blocks'] as $name => $spec ) {
			if ( ! is_string( $name ) || '' === $name || ! is_array( $spec ) ) {
				continue;
			}
			$fields = array();
			foreach ( isset( $spec['fields'] ) && is_array( $spec['fields'] ) ? $spec['fields'] : array() as $field_name => $field ) {
				$norm = self::normalize_field( $field, true );
				if ( is_string( $field_name ) && '' !== $field_name && null !== $norm ) {
					$fields[ $field_name ] = $norm;
				}
			}
			$locked_when = array();
			foreach ( isset( $spec['locked_when'] ) && is_array( $spec['locked_when'] ) ? $spec['locked_when'] : array() as $path => $reason ) {
				if ( is_string( $path ) && '' !== $path && is_string( $reason ) && '' !== $reason ) {
					$locked_when[ $path ] = $reason;
				}
			}
			$out['blocks'][ $name ] = array(
				'type'        => isset( $spec['type'] ) && is_string( $spec['type'] ) ? $spec['type'] : '',
				'container'   => ! empty( $spec['container'] ),
				'locked'      => isset( $spec['locked'] ) && is_string( $spec['locked'] ) ? $spec['locked'] : '',
				'locked_when' => $locked_when,
				'global'      => ! empty( $spec['global'] ),
				'ref_attr'    => isset( $spec['ref_attr'] ) && is_string( $spec['ref_attr'] ) ? $spec['ref_attr'] : '',
				'id_attr'     => isset( $spec['id_attr'] ) && is_string( $spec['id_attr'] ) ? $spec['id_attr'] : '',
				'verified'    => array_key_exists( 'verified', $spec ) ? (bool) $spec['verified'] : $verified,
				'fields'      => $fields,
			);
		}
		return $out;
	}

	/**
	 * A field (or, without $with_kind, a copy) that can be read safely, or
	 * null.
	 *
	 * @param mixed $f         Raw field.
	 * @param bool  $with_kind Whether a kind is required.
	 * @return array|null
	 */
	private static function normalize_field( $f, $with_kind ) {
		if ( ! is_array( $f ) || ! isset( $f['from'] ) || ! in_array( $f['from'], self::SOURCES, true ) ) {
			return null;
		}
		$kind = isset( $f['kind'] ) && is_string( $f['kind'] ) ? $f['kind'] : '';
		if ( $with_kind && ! in_array( $kind, AB_MCP_Builders::FIELD_KINDS, true ) ) {
			return null;
		}
		$out = array(
			'kind'     => $kind,
			'from'     => $f['from'],
			'selector' => isset( $f['selector'] ) && is_string( $f['selector'] ) ? $f['selector'] : '',
			'attr'     => isset( $f['attr'] ) && is_string( $f['attr'] ) ? strtolower( $f['attr'] ) : '',
			'path'     => isset( $f['path'] ) && is_string( $f['path'] ) ? $f['path'] : '',
			'verified' => array_key_exists( 'verified', $f ) ? (bool) $f['verified'] : null,
			'also'     => array(),
		);
		if ( $with_kind ) {
			if ( 'html_attr' === $out['from'] && ! in_array( $out['attr'], self::HTML_ATTRS, true ) ) {
				return null;
			}
			if ( 'attr' === $out['from'] ) {
				if ( '' === $out['path'] ) {
					return null;
				}
				foreach ( explode( '.', $out['path'] ) as $segment ) {
					if ( self::denied_segment( $segment ) ) {
						return null;
					}
				}
			}
			foreach ( isset( $f['also'] ) && is_array( $f['also'] ) ? $f['also'] : array() as $copy ) {
				// Copies are compared, never handed out, so any path may be named.
				$norm = self::normalize_field( $copy, false );
				if ( null !== $norm && ( 'attr' !== $norm['from'] || '' !== $norm['path'] ) ) {
					$out['also'][] = $norm;
				}
			}
		} elseif ( 'html_attr' === $out['from'] && '' === $out['attr'] ) {
			return null;
		}
		return $out;
	}
}
