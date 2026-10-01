<?php
/**
 * Element profiles: for builders that keep a page as a tree of settings —
 * Beaver Builder nodes, SiteOrigin widgets, SeedProd blocks — which settings
 * of which element type are visible content, as data.
 *
 * PROFILES ARE DATA. A profile is a PHP file profiles/elements-<builder>.php
 * that returns an array; supporting another element type means adding an
 * entry, never code. Third parties add or correct entries with the filter
 * ab_mcp_builder_element_profiles. Format:
 *
 *   return array(
 *     'builder'  => 'beaver',               // builder id in signatures.php
 *     'verified' => true,                   // entries measured at a real installation
 *     'source'   => '…',                    // where the entries come from
 *     'elements' => array(
 *       'button' => array(                  // element type as the builder stores it
 *         'verified' => false,              // optional; overrides the profile's flag
 *                                           // (a field may carry its own 'verified' too)
 *         'fields'   => array(
 *           'text' => array( 'kind' => 'text' ),
 *           'link' => array( 'kind' => 'url', 'when' => array( 'click_action' => array( '', 'link' ) ) ),
 *         ),
 *       ),
 *       'items-example' => array( 'fields' => array( 'items.*.text' => array( 'kind' => 'text' ) ) ),
 *       'box'   => array( 'container' => true ),                 // structure only, children are read
 *       'html'  => array( 'locked' => 'code element' ),          // listed without content, children skipped
 *       'video' => array( 'locked' => 'code element', 'locked_when' => array( 'video_type' => array( 'embed' ) ) ),
 *       'reusable-block' => array( 'locked' => 'global element', 'global' => true, 'ref' => 'block_id' ),
 *     ),
 *   );
 *
 * Paths are setting names joined by "." ("headline.text"), through arrays and
 * objects alike; "*" stands for every item of a list, and the field is then
 * named with the item's index ("items.0.text"). A path segment that names
 * CSS, styles, scripts, classes, code, HTML, attribute maps or an event
 * handler is refused (AB_MCP_Block_Reader::denied_segment()), so a profile
 * can never hand such a value out — whatever a filter adds.
 *
 * "when" and "locked_when" name settings and the values they must have; a
 * setting that is missing counts as "". A "*" in their paths is the index of
 * the item the field belongs to. Their paths are only compared, never handed
 * out, so any path may be named.
 *
 * "ref" (with "global"): the setting that names the post whose content the
 * element shows; its trailing number goes into the note.
 *
 * Every field value that holds a shortcode locks its element ("shortcode"):
 * the three builders run shortcodes over what they render (Beaver
 * classes/class-fl-builder.php:2130-2139, SiteOrigin measured, SeedProd
 * resources/views/seedprod-preview.php per the measurement), so the stored
 * text is not what the visitor sees.
 *
 * Kinds are those of AB_MCP_Builders::FIELD_KINDS: text and heading become
 * plain text, html simple inline HTML, url a link (javascript:, vbscript: and
 * data: addresses are left out), image an attachment id (int) or an address.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Builder_Element_Profile
 */
final class AB_MCP_Builder_Element_Profile {

	/** Elements whose type no profile entry knows. */
	const UNKNOWN = 'unknown element type';

	/** Deeper than this no reader follows a tree: a crafted page cannot exhaust the stack. */
	const MAX_DEPTH = 64;

	/**
	 * A builder's profile, normalised: profiles/elements-<builder>.php plus
	 * the filter ab_mcp_builder_element_profiles. Not cached: it is read once
	 * per outline, and a filter added later (tests, late plugins) counts.
	 *
	 * @param string $builder Builder id.
	 * @return array{builder:string,verified:bool,source:string,elements:array}
	 */
	public static function get( $builder ): array {
		$builder = (string) $builder;
		$raw     = array();
		$file    = __DIR__ . '/profiles/elements-' . sanitize_key( $builder ) . '.php';
		if ( is_readable( $file ) ) {
			$loaded = include $file;
			$raw    = is_array( $loaded ) ? $loaded : array();
		}
		/**
		 * The element profile of a page builder that stores its page as a tree
		 * of settings (Beaver Builder, SiteOrigin, SeedProd). The format is
		 * documented in includes/builders/class-element-profile.php.
		 *
		 * @param array  $profile Profile.
		 * @param string $builder Builder id.
		 */
		$raw = apply_filters( 'ab_mcp_builder_element_profiles', $raw, $builder );
		return self::normalize( is_array( $raw ) ? $raw : array(), $builder );
	}

	/**
	 * Outline options with defaults and limits, as every reader takes them.
	 *
	 * @param array $o include_locked, max_elements, max_field_chars.
	 * @return array{include_locked:bool,max_elements:int,max_field_chars:int}
	 */
	public static function options( array $o ): array {
		return array(
			'include_locked'  => array_key_exists( 'include_locked', $o ) ? (bool) $o['include_locked'] : true,
			'max_elements'    => isset( $o['max_elements'] ) ? max( 1, (int) $o['max_elements'] ) : 500,
			'max_field_chars' => AB_MCP_Builders::field_max_chars( isset( $o['max_field_chars'] ) ? (int) $o['max_field_chars'] : null ),
		);
	}

	/**
	 * One element from its settings and its profile entry.
	 *
	 * Returns the element (null when it is locked and locked elements are
	 * left out), whether its children are to be read, and the uncapped value
	 * of every field read (for comparing copies).
	 *
	 * @param array        $base     id, type, parent, depth.
	 * @param array|object $settings The element's settings.
	 * @param array|null   $spec     Profile entry; null for a type no entry knows.
	 * @param array        $profile  The profile (for its verified flag).
	 * @param array        $o        Options (see options()).
	 * @return array{element:array|null,children:bool,values:array}
	 */
	public static function element( array $base, $settings, $spec, array $profile, array $o ): array {
		if ( null === $spec ) {
			return self::locked( $base, self::UNKNOWN, $o );
		}
		if ( '' !== $spec['locked'] && ( array() === $spec['locked_when'] || self::matches( $settings, $spec['locked_when'], null ) ) ) {
			$extra = array();
			if ( $spec['global'] ) {
				$extra['global'] = true;
			}
			if ( '' !== $spec['ref'] ) {
				$ref = self::value_at( $settings, $spec['ref'] );
				if ( is_scalar( $ref ) && 1 === preg_match( '~(\d+)\z~', (string) $ref, $m ) && (int) $m[1] > 0 ) {
					$extra['note'] = sprintf( 'its content is post #%d', (int) $m[1] );
				}
			}
			return self::locked( $base, $spec['locked'], $o, $extra );
		}

		$read = self::read( $settings, $spec, $profile, $o['max_field_chars'] );
		if ( $read['shortcode'] ) {
			return self::locked( $base, 'shortcode', $o );
		}
		$element = $base;
		if ( array() !== $read['fields'] ) {
			$element['fields'] = $read['fields'];
		}
		if ( array() !== $read['notes'] ) {
			$element['note'] = implode( '; ', array_values( array_unique( $read['notes'] ) ) );
		}
		return array(
			'element'  => $element,
			'children' => true,
			'values'   => $read['values'],
		);
	}

	/**
	 * A locked element: no fields, the reason, children not read.
	 *
	 * @param array  $base   id, type, parent, depth.
	 * @param string $reason Reason.
	 * @param array  $o      Options.
	 * @param array  $extra  global, note.
	 * @return array{element:array|null,children:bool,values:array}
	 */
	public static function locked( array $base, $reason, array $o, array $extra = array() ): array {
		$element = null;
		if ( $o['include_locked'] ) {
			$element = array_merge(
				$base,
				array(
					'locked' => true,
					'reason' => (string) $reason,
				),
				$extra
			);
		}
		return array(
			'element'  => $element,
			'children' => false,
			'values'   => array(),
		);
	}

	/**
	 * The value at a path through arrays and objects, or null when a step is
	 * missing. No "*" here.
	 *
	 * @param mixed  $data Settings.
	 * @param string $path Dotted path.
	 * @return mixed
	 */
	public static function value_at( $data, $path ) {
		foreach ( explode( '.', (string) $path ) as $segment ) {
			if ( is_array( $data ) && array_key_exists( $segment, $data ) ) {
				$data = $data[ $segment ];
			} elseif ( is_object( $data ) && isset( $data->$segment ) ) {
				$data = $data->$segment;
			} else {
				return null;
			}
		}
		return $data;
	}

	/**
	 * Whether a text holds a shortcode. On a site only registered shortcodes
	 * count — only they run; without WordPress' registry every "[tag]" counts,
	 * which errs on the side of locking.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function has_shortcode( $text ): bool {
		$text = (string) $text;
		if ( false === strpos( $text, '[' ) ) {
			return false;
		}
		$o = array();
		if ( isset( $GLOBALS['shortcode_tags'] ) && is_array( $GLOBALS['shortcode_tags'] ) ) {
			$o['tags'] = array_keys( $GLOBALS['shortcode_tags'] );
		}
		foreach ( AB_MCP_Shortcode_Reader::parse( $text, $o )['nodes'] as $node ) {
			if ( '#text' !== $node['tag'] ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------ private */

	/**
	 * Every field of an entry that is present and wanted, shaped by its kind
	 * and capped.
	 *
	 * @param array|object $settings Settings.
	 * @param array        $spec     Entry.
	 * @param array        $profile  Profile.
	 * @param int          $max      Field cap in characters.
	 * @return array{fields:array,notes:string[],values:array,shortcode:bool}
	 */
	private static function read( $settings, array $spec, array $profile, $max ) {
		$fields     = array();
		$values     = array();
		$notes      = array();
		$unmeasured = false;
		foreach ( $spec['fields'] as $path => $field ) {
			foreach ( self::expand( $settings, $path ) as $name => $hit ) {
				if ( array() !== $field['when'] && ! self::matches( $settings, $field['when'], $hit['index'] ) ) {
					continue;
				}
				$raw = $hit['value'];
				if ( null === $raw || is_bool( $raw ) || ! is_scalar( $raw ) ) {
					continue;
				}
				if ( is_string( $raw ) && self::has_shortcode( $raw ) ) {
					return array(
						'fields'    => array(),
						'notes'     => array(),
						'values'    => array(),
						'shortcode' => true,
					);
				}
				$value = self::shape( $raw, $field['kind'] );
				if ( null === $value ) {
					$notes[] = $name . ': an address that would run code, left out';
					continue;
				}
				$verified = null !== $field['verified'] ? $field['verified'] : ( null !== $spec['verified'] ? $spec['verified'] : $profile['verified'] );
				if ( ! $verified ) {
					$unmeasured = true;
				}
				$values[ $name ] = array(
					'kind'  => $field['kind'],
					'value' => $value,
					'raw'   => $raw,
				);
				$fields[ $name ] = AB_MCP_Builders::cap_field(
					array(
						'kind'  => $field['kind'],
						'value' => $value,
					),
					$max
				);
			}
		}
		if ( $unmeasured ) {
			$notes[] = AB_MCP_Block_Reader::NOTE_UNMEASURED;
		}
		return array(
			'fields'    => $fields,
			'notes'     => $notes,
			'values'    => $values,
			'shortcode' => false,
		);
	}

	/**
	 * A raw value in the form of its kind; null for an address that could
	 * run code.
	 *
	 * @param string|int|float $raw  Raw value.
	 * @param string           $kind Kind.
	 * @return string|int|null
	 */
	private static function shape( $raw, $kind ) {
		switch ( $kind ) {
			case 'text':
			case 'heading':
				return AB_MCP_Builders::field_text( (string) $raw );
			case 'html':
				return AB_MCP_Builder_Html::simple( (string) $raw );
			case 'image':
				if ( is_int( $raw ) || ( is_string( $raw ) && 1 === preg_match( '~^[0-9]+\z~', $raw ) ) || ( is_float( $raw ) && floor( $raw ) === $raw ) ) {
					return (int) $raw;
				}
				// An image source may be an inline raster image; a link may not.
				return AB_MCP_Builder_Html::safe_url( trim( (string) $raw ), true );
			default: // url.
				return AB_MCP_Builder_Html::safe_url( trim( (string) $raw ) );
		}
	}

	/**
	 * The values a path with "*" reaches: field name => [ value, index ].
	 *
	 * @param mixed  $settings Settings.
	 * @param string $path     Path.
	 * @return array<string,array{value:mixed,index:string|null}>
	 */
	private static function expand( $settings, $path ) {
		$star = strpos( $path, '*' );
		if ( false === $star ) {
			$value = self::value_at( $settings, $path );
			return null === $value ? array() : array(
				$path => array(
					'value' => $value,
					'index' => null,
				),
			);
		}
		$head  = rtrim( substr( $path, 0, $star ), '.' );
		$tail  = ltrim( substr( $path, $star + 1 ), '.' );
		$items = '' === $head ? $settings : self::value_at( $settings, $head );
		$out   = array();
		if ( ! is_array( $items ) && ! is_object( $items ) ) {
			return $out;
		}
		foreach ( (array) $items as $index => $item ) {
			$value = '' === $tail ? $item : self::value_at( $item, $tail );
			if ( null !== $value ) {
				$name         = ( '' === $head ? '' : $head . '.' ) . $index . ( '' === $tail ? '' : '.' . $tail );
				$out[ $name ] = array(
					'value' => $value,
					'index' => (string) $index,
				);
			}
		}
		return $out;
	}

	/**
	 * Whether every named setting has one of its values; missing counts as ''.
	 *
	 * @param mixed       $settings   Settings.
	 * @param array       $conditions path => allowed values.
	 * @param string|null $index      Item index that replaces "*".
	 * @return bool
	 */
	private static function matches( $settings, array $conditions, $index ) {
		foreach ( $conditions as $path => $allowed ) {
			$star = null !== $index ? strpos( $path, '*' ) : false;
			if ( false !== $star ) {
				$path = substr_replace( $path, (string) $index, $star, 1 );
			}
			$value = self::value_at( $settings, (string) $path );
			$value = is_scalar( $value ) && ! is_bool( $value ) ? (string) $value : ( null === $value ? '' : null );
			if ( null === $value || ! in_array( $value, $allowed, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A profile with every key present; entries and fields that do not
	 * follow the format, or would hand out code, are dropped.
	 *
	 * @param array  $p       Raw profile.
	 * @param string $builder Builder id.
	 * @return array
	 */
	private static function normalize( array $p, $builder ) {
		$out = array(
			'builder'  => isset( $p['builder'] ) && is_string( $p['builder'] ) ? $p['builder'] : $builder,
			'verified' => ! empty( $p['verified'] ),
			'source'   => isset( $p['source'] ) && is_string( $p['source'] ) ? $p['source'] : '',
			'elements' => array(),
		);
		foreach ( isset( $p['elements'] ) && is_array( $p['elements'] ) ? $p['elements'] : array() as $type => $spec ) {
			if ( ! is_string( $type ) || '' === $type || ! is_array( $spec ) ) {
				continue;
			}
			$fields = array();
			foreach ( isset( $spec['fields'] ) && is_array( $spec['fields'] ) ? $spec['fields'] : array() as $path => $field ) {
				$norm = self::normalize_field( $path, $field );
				if ( null !== $norm ) {
					$fields[ $path ] = $norm;
				}
			}
			$out['elements'][ $type ] = array(
				'container'   => ! empty( $spec['container'] ),
				'locked'      => isset( $spec['locked'] ) && is_string( $spec['locked'] ) ? $spec['locked'] : '',
				'locked_when' => self::normalize_conditions( isset( $spec['locked_when'] ) ? $spec['locked_when'] : array() ),
				'global'      => ! empty( $spec['global'] ),
				'ref'         => isset( $spec['ref'] ) && is_string( $spec['ref'] ) ? $spec['ref'] : '',
				'verified'    => array_key_exists( 'verified', $spec ) ? (bool) $spec['verified'] : null,
				'fields'      => $fields,
			);
		}
		return $out;
	}

	/**
	 * A field that can be read safely, or null.
	 *
	 * @param mixed $path  Path.
	 * @param mixed $field Raw field.
	 * @return array|null
	 */
	private static function normalize_field( $path, $field ) {
		if ( ! is_string( $path ) || '' === $path || ! is_array( $field ) || ! isset( $field['kind'] ) || ! in_array( $field['kind'], AB_MCP_Builders::FIELD_KINDS, true ) ) {
			return null;
		}
		if ( substr_count( $path, '*' ) > 1 ) {
			return null;
		}
		foreach ( explode( '.', $path ) as $segment ) {
			if ( '*' !== $segment && AB_MCP_Block_Reader::denied_segment( $segment ) ) {
				return null;
			}
		}
		return array(
			'kind'     => $field['kind'],
			'when'     => self::normalize_conditions( isset( $field['when'] ) ? $field['when'] : array() ),
			'verified' => array_key_exists( 'verified', $field ) ? (bool) $field['verified'] : null,
		);
	}

	/**
	 * Conditions as path => list of string values.
	 *
	 * @param mixed $conditions Raw conditions.
	 * @return array<string,string[]>
	 */
	private static function normalize_conditions( $conditions ) {
		$out = array();
		foreach ( is_array( $conditions ) ? $conditions : array() as $path => $values ) {
			if ( is_string( $path ) && '' !== $path && is_array( $values ) ) {
				$out[ $path ] = array_values( array_map( 'strval', array_filter( $values, 'is_scalar' ) ) );
			}
		}
		return $out;
	}
}
