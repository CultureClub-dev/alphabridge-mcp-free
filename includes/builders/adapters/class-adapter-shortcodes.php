<?php
/**
 * Adapter for page builders that keep a page as shortcodes: WPBakery, Divi 4,
 * Avada and Flatsome in post_content (storage A), Enfold in a meta field
 * (storage B, post_content only a copy). One class, one instance per builder:
 * each instance reads with its builder's profile, so Flatsome's plain
 * [button] means something on a Flatsome page and nothing on a WPBakery page.
 *
 * PROFILES ARE DATA. A profile is a PHP file in ../profiles/ named
 * shortcodes-*.php that returns an array; adding a shortcode builder means
 * adding such a file (or filtering ab_mcp_builder_shortcode_profiles) plus its
 * signature in signatures.php — never a class. Format:
 *
 *   return array(
 *     'builder'      => 'wpbakery',        // builder id in signatures.php; one adapter per builder
 *     'id'           => 'wpbakery',        // profile id, default: the builder id
 *     'verified'     => false,             // measured at a real installation
 *     'source'       => 'quellen/wpbakery.md …',
 *     'layout_meta'  => '',                // meta key that holds the shortcodes; '' = post_content
 *     'codec'        => 'wpbakery',        // attribute encoding: 'wpbakery', 'divi4' or ''
 *                                          // (AB_MCP_Shortcode_Codecs)
 *     'id_attr'      => '',                // attribute with the builder's element id, or ''
 *     'family'       => array( 'vc_*' ),   // the builder's own tags ("*" = prefix) not listed below
 *     'global_attrs' => array( 'global_module' => 'note with %s' ), // attribute that makes an
 *                                          // element global (its text lives elsewhere)
 *     'placeholder'  => '~…~',             // pattern of placeholders filled in on page load
 *     'tags'         => array(
 *       'vc_row'         => array( 'container' => true ),           // structure only
 *       'vc_column_text' => array( 'fields' => array(
 *           'text' => array( 'kind' => 'html', 'from' => 'content' ),
 *       ) ),
 *       'vc_btn'         => array(
 *           'code_attrs' => array( 'custom_onclick_code' ),          // never read; noted when set
 *           'fields'     => array(
 *             'text' => array( 'kind' => 'text', 'from' => 'attr', 'attr' => 'title' ),
 *             'url'  => array( 'kind' => 'url', 'from' => 'attr', 'attr' => 'link', 'format' => 'vc_link' ),
 *           ),
 *       ),
 *       'vc_raw_html'    => array( 'locked' => 'code element' ),    // listed without content, children skipped
 *       'block'          => array( 'locked' => 'global element', 'global' => true,
 *                                  'ref_attr' => 'id', 'ref_note' => 'its content is UX Block %s' ),
 *     ),
 *   );
 *
 * A tag entry may also carry 'type' (default: the tag), 'note' (said on every
 * such element) and 'verified' (overrides the profile's; a field may carry
 * its own). Field sources ("from"): 'content' — what stands between the
 * opening and the closing tag; 'attr' — attribute "attr", decoded with the
 * profile's codec, or by "format" ('vc_link': the address of a WPBakery link;
 * 'enfold_link': an Enfold link). A field may name 'unless' => [ attr,
 * equals, note ]: when that attribute has that value, the field is left out
 * with the note. Attribute names that hold CSS, styles, scripts, classes,
 * code, HTML or event handlers can never be read (the same rule as block
 * paths, AB_MCP_Block_Reader::denied_segment()); such a field is dropped.
 *
 * How a page is read (the element format: see AB_MCP_Builders):
 * - The tags that count: the profile's, the family's, and every shortcode
 *   the site has registered — as WordPress, which leaves an unknown "[1]" or
 *   "[sic]" in a text alone. On a test without WordPress only the first two.
 * - Ids are the reader's paths ("s0.1.2", text runs counted), or the
 *   builder's own id (id_attr) where it is set and unique on the page. A
 *   path id is valid only with the layout_hash it was read with; it also
 *   assumes the same shortcodes are registered, so a writer compares the
 *   element's type before it changes anything.
 * - A listed element: its fields; children are listed in turn. Text that a
 *   container holds directly is listed as an element "#text".
 * - A content field whose content holds shortcodes reads the text around
 *   them and says so; the shortcodes are listed as its children.
 * - Elements of the builder that no entry describes, and enclosing
 *   shortcodes of other plugins: type and the visible text between their
 *   tags, never an attribute, noted "profile not verified, read only". A
 *   shortcode of another plugin standing alone is locked ("shortcode").
 * - Locked: no fields, children not listed.
 *
 * Pro: tree() gives the parse with byte offsets this outline was read from
 * (and where the text lives, loc); profile() the profile, codec and formats.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Builder_Adapter_Shortcodes
 */
class AB_MCP_Builder_Adapter_Shortcodes extends AB_MCP_Builder_Adapter {

	/**
	 * Note on elements read with an unmeasured profile entry. It begins with
	 * the block reader's note, so the outline tool counts these elements the
	 * same way.
	 */
	const NOTE_VENDOR = 'profile not measured, read only: profile from vendor documentation, not yet measured on a live install';

	/** Attribute codecs a profile may name. */
	const CODECS = array( '', 'wpbakery', 'divi4' );

	/** Value formats a field may name. */
	const FORMATS = array( '', 'vc_link', 'enfold_link' );

	/** Field sources a profile may name. */
	const SOURCES = array( 'content', 'attr' );

	/**
	 * Builder id this instance reads.
	 *
	 * @var string
	 */
	private $builder;

	/**
	 * Normalised profile.
	 *
	 * @var array
	 */
	private $profile;

	/**
	 * One adapter for one builder.
	 *
	 * @param string $builder Builder id (signatures.php).
	 * @param array  $profile Normalised profile, see profiles().
	 */
	public function __construct( $builder, array $profile ) {
		$this->builder = (string) $builder;
		$this->profile = $profile;
	}

	/**
	 * One adapter per loaded profile — what the free plugin registers. The
	 * first profile of a builder wins.
	 *
	 * @return AB_MCP_Builder_Adapter_Shortcodes[]
	 */
	public static function defaults(): array {
		$out = array();
		foreach ( self::profiles() as $profile ) {
			if ( ! isset( $out[ $profile['builder'] ] ) ) {
				$out[ $profile['builder'] ] = new self( $profile['builder'], $profile );
			}
		}
		return array_values( $out );
	}

	/**
	 * The shortcode profiles (normalised), in file name order.
	 *
	 * @return array<int,array>
	 */
	public static function profiles(): array {
		$files = glob( dirname( __DIR__ ) . '/profiles/shortcodes-*.php' );
		$files = is_array( $files ) ? $files : array();
		sort( $files, SORT_STRING );
		$raw = array();
		foreach ( $files as $file ) {
			$profile = include $file;
			if ( is_array( $profile ) ) {
				$raw[] = $profile;
			}
		}
		/**
		 * Shortcode profiles, one per builder. The format is documented in
		 * includes/builders/adapters/class-adapter-shortcodes.php; the builder
		 * also needs a signature (filter ab_mcp_builder_signatures).
		 *
		 * @param array[] $profiles Profiles.
		 */
		$raw = apply_filters( 'ab_mcp_builder_shortcode_profiles', $raw );
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
	 * Adapter id: the builder id.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->builder;
	}

	/**
	 * The profile this adapter reads with.
	 *
	 * @return array
	 */
	public function profile(): array {
		return $this->profile;
	}

	/**
	 * Whether the profile was measured at a real installation.
	 *
	 * @return bool
	 */
	public function verified(): bool {
		return (bool) $this->profile['verified'];
	}

	/**
	 * For a builder that keeps its shortcodes in a meta field (Enfold):
	 * true while that field holds them. When it holds none, the page is read
	 * from post_content (layout_source()), and what the site then shows is
	 * not documented (quellen/enfold.md b), the display template is not
	 * public), so the answer is null: not known. Builders that keep the page
	 * in post_content: null, they are not storage B.
	 *
	 * @param WP_Post $post Post.
	 * @return bool|null
	 */
	public function own_data_shown( $post ): ?bool {
		if ( '' === $this->profile['layout_meta'] ) {
			return null;
		}
		return $this->layout_source( $post )['fallback'] ? null : true;
	}

	/**
	 * Where the shortcodes of this page are read from: the profile's meta
	 * field, or post_content. When the meta field holds no shortcode, the
	 * page is read from post_content — what Enfold's editor loads in that
	 * case (quellen/enfold.md b) 3., avia-builder.js l. 1786–1803).
	 *
	 * @param WP_Post $post Post.
	 * @return array{loc:string,text:string,fallback:bool}
	 */
	public function layout_source( $post ): array {
		$meta = $this->profile['layout_meta'];
		if ( '' !== $meta ) {
			$value = get_post_meta( (int) $post->ID, $meta, true );
			if ( is_string( $value ) && false !== strpos( $value, '[' ) ) {
				return array(
					'loc'      => 'meta:' . $meta,
					'text'     => $value,
					'fallback' => false,
				);
			}
		}
		return array(
			'loc'      => 'post_content',
			'text'     => (string) $post->post_content,
			'fallback' => '' !== $meta,
		);
	}

	/**
	 * The parse the outline is read from: layout_source() plus the reader's
	 * nodes (byte offsets into "text") and whether it stopped early.
	 *
	 * @param WP_Post $post Post.
	 * @return array{loc:string,text:string,fallback:bool,nodes:array,truncated:bool}
	 */
	public function tree( $post ): array {
		$source = $this->layout_source( $post );
		$parsed = AB_MCP_Shortcode_Reader::parse( $source['text'], array( 'tags' => $this->tag_names( $source['text'] ) ) );
		return array_merge( $source, $parsed );
	}

	/**
	 * The tag names in a text that count as shortcodes: the profile's, the
	 * family's and the registered ones.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public function tag_names( $text ): array {
		if ( ! preg_match_all( '~\[/?(' . AB_MCP_Shortcode_Reader::NAME . ')(?![\w-])~', (string) $text, $m ) ) {
			return array();
		}
		$registered = isset( $GLOBALS['shortcode_tags'] ) && is_array( $GLOBALS['shortcode_tags'] ) ? $GLOBALS['shortcode_tags'] : array();
		$out        = array();
		foreach ( array_unique( $m[1] ) as $name ) {
			if ( isset( $this->profile['tags'][ $name ] ) || $this->in_family( $name ) || isset( $registered[ $name ] ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * The page as an outline.
	 *
	 * @param WP_Post $post Post.
	 * @param array   $o    include_locked (true), max_elements (500), max_field_chars.
	 * @return array<int,array>
	 */
	public function outline( $post, array $o ): array {
		$o    = array(
			'include_locked'  => array_key_exists( 'include_locked', $o ) ? (bool) $o['include_locked'] : true,
			'max_elements'    => isset( $o['max_elements'] ) ? max( 1, (int) $o['max_elements'] ) : 500,
			'max_field_chars' => AB_MCP_Builders::field_max_chars( isset( $o['max_field_chars'] ) ? (int) $o['max_field_chars'] : null ),
		);
		$tree = $this->tree( $post );
		$ctx  = array(
			'text'   => $tree['text'],
			'o'      => $o,
			'counts' => array(),
			'note'   => '',
		);
		if ( '' !== $this->profile['id_attr'] ) {
			$this->count_ids( $tree['nodes'], $ctx['counts'] );
		}
		if ( $tree['fallback'] ) {
			$ctx['note'] = sprintf( 'read from post_content: %s holds no shortcodes, and the builder\'s editor then opens post_content', $this->profile['layout_meta'] );
		}
		$out = array();
		$this->walk( $tree['nodes'], null, 0, false, $ctx, $out );
		return $out;
	}

	/* ------------------------------------------------------------ private */

	/**
	 * Walk one level of nodes, adding elements in page order.
	 *
	 * @param array       $nodes         Nodes of the reader.
	 * @param string|null $parent        Parent element id.
	 * @param int         $depth         Depth.
	 * @param bool        $text_is_read  Whether the text runs at this level belong to the
	 *                                   parent (its content field), not to the page.
	 * @param array       $ctx           text, o, counts, note.
	 * @param array       $out           Elements (by reference).
	 * @return bool False once max_elements is reached.
	 */
	private function walk( array $nodes, $parent, $depth, $text_is_read, array $ctx, array &$out ) {
		foreach ( $nodes as $node ) {
			if ( count( $out ) >= $ctx['o']['max_elements'] ) {
				return false;
			}
			if ( '#text' === $node['tag'] ) {
				if ( $text_is_read ) {
					continue;
				}
				$raw = AB_MCP_Shortcode_Reader::slice( $ctx['text'], $node['start'], $node['end'] );
				if ( '' === AB_MCP_Builders::field_text( $raw ) ) {
					continue;
				}
				$out[] = $this->element(
					array(
						'id'     => $node['id'],
						'type'   => '#text',
						'parent' => $parent,
						'depth'  => $depth,
						'fields' => array(
							'text' => AB_MCP_Builders::cap_field(
								array(
									'kind'  => 'html',
									'value' => AB_MCP_Builder_Html::simple( $raw ),
								),
								$ctx['o']['max_field_chars']
							),
						),
					),
					array( AB_MCP_Block_Reader::NOTE_UNVERIFIED ),
					$ctx
				);
				continue;
			}

			$tag     = (string) $node['tag'];
			$spec    = isset( $this->profile['tags'][ $tag ] ) ? $this->profile['tags'][ $tag ] : null;
			$element = array(
				'id'     => $this->element_id( $node, $ctx['counts'] ),
				'type'   => null !== $spec && '' !== $spec['type'] ? $spec['type'] : $tag,
				'parent' => $parent,
				'depth'  => $depth,
			);

			if ( null !== $spec && '' !== $spec['locked'] ) {
				if ( $ctx['o']['include_locked'] ) {
					$element['locked'] = true;
					$element['reason'] = $spec['locked'];
					$notes             = array();
					if ( $spec['global'] ) {
						$element['global'] = true;
					}
					if ( '' !== $spec['ref_attr'] && '' !== $spec['ref_note'] && isset( $node['attrs'][ $spec['ref_attr'] ] ) ) {
						$notes[] = sprintf( $spec['ref_note'], self::ref( $node['attrs'][ $spec['ref_attr'] ] ) );
					}
					$out[] = $this->element( $element, $notes, $ctx );
				}
				continue; // Children of a locked element are not listed.
			}

			if ( null === $spec && null === $node['content'] && ! $this->in_family( $tag ) ) {
				// Another plugin's shortcode standing alone: what it shows is
				// whatever that plugin makes of its attributes.
				if ( $ctx['o']['include_locked'] ) {
					$element['locked'] = true;
					$element['reason'] = 'shortcode';
					$out[]             = $this->element( $element, array(), $ctx );
				}
				continue;
			}

			$notes = array();
			foreach ( $this->profile['global_attrs'] as $attr => $format ) {
				if ( isset( $node['attrs'][ $attr ] ) && '' !== trim( (string) $node['attrs'][ $attr ] ) ) {
					$element['global'] = true;
					$notes[]           = sprintf( $format, self::ref( $node['attrs'][ $attr ] ) );
				}
			}

			$fields = array();
			if ( null === $spec ) {
				// Not described by the profile: the visible text between its
				// tags, never an attribute.
				$text = AB_MCP_Builders::field_text( $this->own_text( $node, $ctx['text'] ) );
				if ( '' !== $text ) {
					$fields['text'] = AB_MCP_Builders::cap_field(
						array(
							'kind'  => 'text',
							'value' => $text,
						),
						$ctx['o']['max_field_chars']
					);
				}
				$notes[]      = AB_MCP_Block_Reader::NOTE_UNVERIFIED;
				$text_is_mine = true;
			} else {
				if ( $spec['global'] ) {
					$element['global'] = true;
				}
				$unmeasured = false;
				foreach ( $spec['fields'] as $name => $field ) {
					$value = $this->read_field( $node, $name, $field, $ctx['text'], $notes );
					if ( null === $value ) {
						continue;
					}
					if ( ! ( null === $field['verified'] ? $spec['verified'] : $field['verified'] ) ) {
						$unmeasured = true;
					}
					$fields[ $name ] = AB_MCP_Builders::cap_field(
						array(
							'kind'  => $field['kind'],
							'value' => $value,
						),
						$ctx['o']['max_field_chars']
					);
				}
				if ( $unmeasured ) {
					$notes[] = self::NOTE_VENDOR;
				}
				if ( '' !== $spec['note'] ) {
					$notes[] = $spec['note'];
				}
				foreach ( $spec['code_attrs'] as $attr ) {
					if ( isset( $node['attrs'][ $attr ] ) && '' !== trim( (string) $node['attrs'][ $attr ] ) ) {
						$notes[] = 'holds code in ' . $attr . ', not shown';
					}
				}
				// A container's own text runs are page text, listed as "#text";
				// any other element's are its content field, or not page text.
				$text_is_mine = ! $spec['container'];
			}
			if ( array() !== $fields ) {
				$element['fields'] = $fields;
			}
			$out[] = $this->element( $element, $notes, $ctx );

			if ( ! $this->walk( $node['children'], $element['id'], $depth + 1, $text_is_mine, $ctx, $out ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A field's value, shaped by its kind, or null when it is missing or
	 * must not be shown; the reason, if any, goes to $notes.
	 *
	 * @param array  $node  Node.
	 * @param string $name  Field name.
	 * @param array  $field Field spec.
	 * @param string $text  Whole text.
	 * @param array  $notes Notes (by reference).
	 * @return string|int|null
	 */
	private function read_field( array $node, $name, array $field, $text, array &$notes ) {
		if ( 'content' === $field['from'] ) {
			if ( null === $node['content'] ) {
				return null;
			}
			$raw = $this->own_text( $node, $text );
			foreach ( $node['children'] as $child ) {
				if ( '#text' !== $child['tag'] ) {
					// Only elements in it: no text of its own, and no single
					// place a text could go.
					if ( '' === AB_MCP_Builders::field_text( $raw ) ) {
						return null;
					}
					$notes[] = $name . ': holds shortcodes, listed as its children';
					break;
				}
			}
		} else {
			if ( ! isset( $node['attrs'][ $field['attr'] ] ) ) {
				return null;
			}
			$raw = (string) $node['attrs'][ $field['attr'] ];
			if ( null !== $field['unless'] && isset( $node['attrs'][ $field['unless']['attr'] ] ) && (string) $node['attrs'][ $field['unless']['attr'] ] === $field['unless']['equals'] ) {
				$notes[] = $name . ': ' . $field['unless']['note'];
				return null;
			}
			$raw = $this->decode( $name, $raw, $field['format'], $notes );
			if ( null === $raw ) {
				return null;
			}
		}
		if ( '' !== $this->profile['placeholder'] && 1 === preg_match( $this->profile['placeholder'], $raw ) ) {
			$notes[] = $name . ': holds placeholders that are filled in when the page is shown';
		}
		switch ( $field['kind'] ) {
			case 'text':
			case 'heading':
				return AB_MCP_Builders::field_text( $raw );
			case 'html':
				return AB_MCP_Builder_Html::simple( $raw );
			case 'image':
				$raw = trim( $raw );
				if ( 1 === preg_match( '~^[0-9]+\z~', $raw ) ) {
					return (int) $raw;
				}
				$safe = AB_MCP_Builder_Html::safe_url( $raw, true );
				break;
			default: // url.
				$safe = AB_MCP_Builder_Html::safe_url( trim( $raw ) );
		}
		if ( null === $safe ) {
			$notes[] = $name . ': an address that would run code, left out';
		}
		return $safe;
	}

	/**
	 * An attribute value as the page shows it: the field's format, or the
	 * profile's codec. Null (with a note) where the value must not be shown
	 * or there is nothing to show.
	 *
	 * @param string $name   Field name.
	 * @param string $raw    Attribute value as WordPress hands it over.
	 * @param string $format Field format.
	 * @param array  $notes  Notes (by reference).
	 * @return string|null
	 */
	private function decode( $name, $raw, $format, array &$notes ) {
		$codec = $this->profile['codec'];
		if ( 'wpbakery' === $codec && AB_MCP_Shortcode_Codecs::wpbakery_is_safe_encoded( $raw ) ) {
			$notes[] = $name . ': an encoded value, not shown';
			return null;
		}
		if ( 'divi4' === $codec && AB_MCP_Shortcode_Codecs::divi4_is_dynamic( $raw ) ) {
			$notes[] = $name . ': dynamic content, filled in when the page is shown';
			return null;
		}
		if ( 'vc_link' === $format ) {
			return AB_MCP_Shortcode_Codecs::wpbakery_link( $raw )['url'];
		}
		if ( 'enfold_link' === $format ) {
			$link = AB_MCP_Shortcode_Codecs::enfold_link( $raw );
			switch ( $link['kind'] ) {
				case 'none':
					return null;
				case 'lightbox':
					$notes[] = $name . ': opens the image in a lightbox';
					return null;
				case 'internal':
					$notes[] = sprintf( '%s: a link to %s #%d of this site, stored as type,id', $name, $link['type'], $link['id'] );
					return $link['type'] . ',' . $link['id'];
			}
			return $link['url'];
		}
		if ( 'wpbakery' === $codec ) {
			return AB_MCP_Shortcode_Codecs::wpbakery_attr( $raw );
		}
		if ( 'divi4' === $codec ) {
			return AB_MCP_Shortcode_Codecs::divi4_attr( $raw );
		}
		return $raw;
	}

	/**
	 * What stands between a node's tags without the shortcodes in it: the
	 * whole content where it holds none, else its text runs.
	 *
	 * @param array  $node Node.
	 * @param string $text Whole text.
	 * @return string
	 */
	private function own_text( array $node, $text ) {
		if ( null === $node['content'] ) {
			return '';
		}
		$runs = array();
		foreach ( $node['children'] as $child ) {
			if ( '#text' !== $child['tag'] ) {
				$runs = null;
				break;
			}
		}
		if ( null !== $runs ) {
			return AB_MCP_Shortcode_Reader::slice( $text, $node['content'][0], $node['content'][1] );
		}
		$runs = array();
		foreach ( $node['children'] as $child ) {
			if ( '#text' === $child['tag'] ) {
				$runs[] = AB_MCP_Shortcode_Reader::slice( $text, $child['start'], $child['end'] );
			}
		}
		return implode( ' ', $runs );
	}

	/**
	 * An element with its notes, the page-wide note included.
	 *
	 * @param array $element Element.
	 * @param array $notes   Notes.
	 * @param array $ctx     Context.
	 * @return array
	 */
	private function element( array $element, array $notes, array $ctx ) {
		if ( '' !== $ctx['note'] ) {
			$notes[] = $ctx['note'];
		}
		$notes = array_values( array_unique( array_filter( $notes, 'strlen' ) ) );
		if ( array() !== $notes ) {
			$element['note'] = implode( '; ', $notes );
		}
		return $element;
	}

	/**
	 * The element id: the builder's own id where it is set, usable and
	 * unique on the page, else the reader's path.
	 *
	 * @param array $node   Node.
	 * @param array $counts Occurrences of each builder id.
	 * @return string
	 */
	private function element_id( array $node, array $counts ) {
		$attr = $this->profile['id_attr'];
		if ( '' !== $attr && isset( $node['attrs'][ $attr ] ) ) {
			$candidate = (string) $node['attrs'][ $attr ];
			if ( AB_MCP_Block_Reader::usable_id( $candidate ) && 1 === ( isset( $counts[ $candidate ] ) ? $counts[ $candidate ] : 0 ) ) {
				return $candidate;
			}
		}
		return (string) $node['id'];
	}

	/**
	 * Count every builder id on the page, so a duplicated one (a copied
	 * element can keep it until the page is saved again) falls back to the
	 * path for all its copies.
	 *
	 * @param array $nodes  Nodes.
	 * @param array $counts Counts (by reference).
	 */
	private function count_ids( array $nodes, array &$counts ) {
		$attr = $this->profile['id_attr'];
		foreach ( $nodes as $node ) {
			if ( '#text' === $node['tag'] ) {
				continue;
			}
			if ( isset( $node['attrs'][ $attr ] ) ) {
				$id            = (string) $node['attrs'][ $attr ];
				$counts[ $id ] = ( isset( $counts[ $id ] ) ? $counts[ $id ] : 0 ) + 1;
			}
			$this->count_ids( $node['children'], $counts );
		}
	}

	/**
	 * Whether a tag belongs to the builder's family (profile 'family').
	 *
	 * @param string $tag Tag name.
	 * @return bool
	 */
	private function in_family( $tag ) {
		foreach ( $this->profile['family'] as $entry ) {
			if ( '*' === substr( $entry, -1 ) ? 0 === strpos( $tag, substr( $entry, 0, -1 ) ) : $tag === $entry ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A reference (an id or slug from an attribute) fit for a note.
	 *
	 * @param mixed $value Attribute value.
	 * @return string
	 */
	private static function ref( $value ) {
		$ref = substr( (string) preg_replace( '~[^A-Za-z0-9_.:-]~', '', (string) $value ), 0, 64 );
		return '' !== $ref ? $ref : '(unreadable)';
	}

	/**
	 * A profile with every key present, or null. Tags, fields and keys that
	 * do not follow the format are dropped — a field that cannot be read
	 * safely is not read at all.
	 *
	 * @param mixed $p Raw profile.
	 * @return array|null
	 */
	private static function normalize_profile( $p ) {
		if ( ! is_array( $p ) || ! isset( $p['builder'] ) || ! is_string( $p['builder'] ) || '' === $p['builder'] || ! isset( $p['tags'] ) || ! is_array( $p['tags'] ) ) {
			return null;
		}
		$verified = ! empty( $p['verified'] );
		$out      = array(
			'id'           => isset( $p['id'] ) && is_string( $p['id'] ) && '' !== $p['id'] ? $p['id'] : $p['builder'],
			'builder'      => $p['builder'],
			'verified'     => $verified,
			'source'       => isset( $p['source'] ) && is_string( $p['source'] ) ? $p['source'] : '',
			'layout_meta'  => isset( $p['layout_meta'] ) && is_string( $p['layout_meta'] ) ? $p['layout_meta'] : '',
			'codec'        => isset( $p['codec'] ) && in_array( $p['codec'], self::CODECS, true ) ? $p['codec'] : '',
			'id_attr'      => isset( $p['id_attr'] ) && is_string( $p['id_attr'] ) ? strtolower( $p['id_attr'] ) : '',
			'family'       => array(),
			'global_attrs' => array(),
			'placeholder'  => '',
			'tags'         => array(),
		);
		foreach ( isset( $p['family'] ) && is_array( $p['family'] ) ? $p['family'] : array() as $entry ) {
			if ( is_string( $entry ) && '' !== trim( $entry, '*' ) ) {
				$out['family'][] = $entry;
			}
		}
		foreach ( isset( $p['global_attrs'] ) && is_array( $p['global_attrs'] ) ? $p['global_attrs'] : array() as $attr => $format ) {
			if ( is_string( $attr ) && '' !== $attr && is_string( $format ) && 1 === substr_count( $format, '%s' ) && false === strpos( str_replace( '%s', '', $format ), '%' ) ) {
				$out['global_attrs'][ strtolower( $attr ) ] = $format;
			}
		}
		if ( isset( $p['placeholder'] ) && is_string( $p['placeholder'] ) && '' !== $p['placeholder'] && false !== @preg_match( $p['placeholder'], '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- testing a third party's pattern once.
			$out['placeholder'] = $p['placeholder'];
		}
		foreach ( $p['tags'] as $tag => $spec ) {
			if ( ! is_string( $tag ) || 1 !== preg_match( '~^' . AB_MCP_Shortcode_Reader::NAME . '$~', $tag ) || ! is_array( $spec ) ) {
				continue;
			}
			$code_attrs = array();
			foreach ( isset( $spec['code_attrs'] ) && is_array( $spec['code_attrs'] ) ? $spec['code_attrs'] : array() as $attr ) {
				if ( is_string( $attr ) && '' !== $attr ) {
					$code_attrs[] = strtolower( $attr );
				}
			}
			$fields = array();
			foreach ( isset( $spec['fields'] ) && is_array( $spec['fields'] ) ? $spec['fields'] : array() as $name => $field ) {
				$norm = self::normalize_field( $field, $code_attrs );
				if ( is_string( $name ) && '' !== $name && null !== $norm ) {
					$fields[ $name ] = $norm;
				}
			}
			$ref_note = isset( $spec['ref_note'] ) && is_string( $spec['ref_note'] ) && 1 === substr_count( $spec['ref_note'], '%s' ) && false === strpos( str_replace( '%s', '', $spec['ref_note'] ), '%' ) ? $spec['ref_note'] : '';
			$out['tags'][ $tag ] = array(
				'type'       => isset( $spec['type'] ) && is_string( $spec['type'] ) ? $spec['type'] : '',
				'container'  => ! empty( $spec['container'] ),
				'locked'     => isset( $spec['locked'] ) && is_string( $spec['locked'] ) ? $spec['locked'] : '',
				'global'     => ! empty( $spec['global'] ),
				'ref_attr'   => isset( $spec['ref_attr'] ) && is_string( $spec['ref_attr'] ) ? strtolower( $spec['ref_attr'] ) : '',
				'ref_note'   => $ref_note,
				'note'       => isset( $spec['note'] ) && is_string( $spec['note'] ) ? $spec['note'] : '',
				'code_attrs' => $code_attrs,
				'verified'   => array_key_exists( 'verified', $spec ) ? (bool) $spec['verified'] : $verified,
				'fields'     => $fields,
			);
		}
		return $out;
	}

	/**
	 * A field that can be read safely, or null.
	 *
	 * @param mixed    $f          Raw field.
	 * @param string[] $code_attrs Attributes of the tag that hold code.
	 * @return array|null
	 */
	private static function normalize_field( $f, array $code_attrs ) {
		if ( ! is_array( $f ) || ! isset( $f['from'], $f['kind'] ) || ! in_array( $f['from'], self::SOURCES, true ) || ! in_array( $f['kind'], AB_MCP_Builders::FIELD_KINDS, true ) ) {
			return null;
		}
		$out = array(
			'kind'     => $f['kind'],
			'from'     => $f['from'],
			'attr'     => '',
			'format'   => isset( $f['format'] ) && in_array( $f['format'], self::FORMATS, true ) ? $f['format'] : '',
			'verified' => array_key_exists( 'verified', $f ) ? (bool) $f['verified'] : null,
			'unless'   => null,
		);
		if ( 'attr' === $out['from'] ) {
			$attr = isset( $f['attr'] ) && is_string( $f['attr'] ) ? strtolower( $f['attr'] ) : '';
			// WordPress lower-cases attribute names; one that holds code,
			// styling or markup is never read, whatever a profile says.
			if ( '' === $attr || in_array( $attr, $code_attrs, true ) || AB_MCP_Block_Reader::denied_segment( $attr ) ) {
				return null;
			}
			$out['attr'] = $attr;
		}
		if ( isset( $f['unless']['attr'], $f['unless']['equals'], $f['unless']['note'] ) && is_string( $f['unless']['attr'] ) && is_scalar( $f['unless']['equals'] ) && is_string( $f['unless']['note'] ) ) {
			$out['unless'] = array(
				'attr'   => strtolower( $f['unless']['attr'] ),
				'equals' => (string) $f['unless']['equals'],
				'note'   => $f['unless']['note'],
			);
		}
		return $out;
	}
}
