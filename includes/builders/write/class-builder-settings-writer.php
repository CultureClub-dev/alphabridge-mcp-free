<?php
/**
 * Shared part of the writers for builders that keep a page as a tree of
 * settings, read by the free core through an element profile
 * (AB_MCP_Builder_Element_Profile): Beaver Builder nodes, SiteOrigin widgets,
 * SeedProd blocks. A field of the outline is a dotted path into the settings
 * of its element ("headline.text", "items.0.link"), so finding the stored
 * value of a field and setting a new one is the same for all three; how the
 * settings are loaded and saved is each writer's own.
 *
 * What this class adds to AB_MCP_Builder_Writer:
 * - set_fields(): the element and its checked changes, with 'before' replaced
 *   by the value as stored (the outline shows text without markup, and the
 *   placeholder check needs the stored value in full);
 * - replace(): every text, heading, html and url field of the unlocked
 *   elements that holds the search text, each change checked like one asked
 *   for; fields a change would make invalid are left, named (left, notes)
 *   and counted with the matches outside these fields (locked_matches);
 * - prepare(): the hook where a writer looks at the planned changes before the
 *   seven steps run (a draft to keep in step, a second copy to match).
 *
 * Loaded by the writer files that extend it (require_once), not by the
 * bootstrap: it is a part of those writers, not a writer of its own.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Builder_Settings_Writer
 */
abstract class AB_MCP_Builder_Settings_Writer extends AB_MCP_Builder_Writer {

	/** Kinds replace() changes; an image is an attachment, not a text. */
	const REPLACE_KINDS = array( 'text', 'heading', 'html', 'url' );

	/**
	 * The settings of one element as stored (arrays and objects as the
	 * builder unserialized or decoded them), or a WP_Error.
	 *
	 * @param WP_Post $post    Post.
	 * @param array   $element Element of the outline.
	 * @return array|object|WP_Error
	 */
	abstract protected function settings_of( $post, array $element );

	/**
	 * How often a text occurs in the page's stored layout as a whole, field
	 * texts and everything else; replace() reports what lies outside the
	 * fields it changes as locked_matches. count_in() counts the way
	 * replace() matches.
	 *
	 * @param WP_Post $post   Post.
	 * @param string  $search Text.
	 * @param bool    $ci     Case-insensitive (wp_search_replace's case_insensitive).
	 * @return int
	 */
	abstract protected function count_stored( $post, $search, $ci = false );

	/**
	 * A last look at the planned change before the seven steps run: add what
	 * the writer needs to write it ('data' of a change, keys of its own on
	 * $change), warnings and notes — or refuse with the reason and the way.
	 * $change['mode'] is 'element' (set_fields) or 'text' (replace); in
	 * replace() a writer may also drop changes it cannot make, naming each
	 * with its reason in $change['left'].
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $change Change.
	 * @param array   $opts   Options as given.
	 * @return array|WP_Error
	 */
	protected function prepare( $post, array $change, array $opts ) {
		return $change;
	}

	/**
	 * Change fields of one element; see AB_MCP_Builder_Writer::set_fields().
	 *
	 * @param WP_Post $post       Post.
	 * @param string  $element_id Element id.
	 * @param array   $fields     Field name (or kind alias) => new value.
	 * @param array   $opts       See execute().
	 * @return array|WP_Error
	 */
	public function set_fields( $post, $element_id, array $fields, array $opts ) {
		$element = $this->element( $post, $element_id );
		if ( is_wp_error( $element ) ) {
			return $element;
		}
		$changes = $this->fields( $element, $fields );
		if ( is_wp_error( $changes ) ) {
			return $changes;
		}
		$settings = $this->settings_of( $post, $element );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		foreach ( $changes as $i => $c ) {
			$stored = self::value_at( $settings, $c['field'] );
			if ( null === $stored || ! is_scalar( $stored ) || is_bool( $stored ) ) {
				return self::gone( $c['element'], $c['field'] );
			}
			$changes[ $i ]['before'] = $stored;
			$changes[ $i ]['data']   = array( 'path' => $c['field'] );
			unset( $changes[ $i ]['truncated'] );
		}
		$change = $this->prepare(
			$post,
			array(
				'mode'    => 'element',
				'element' => $element,
				'changes' => $changes,
			),
			$opts
		);
		if ( is_wp_error( $change ) ) {
			return $change;
		}
		// A field that already holds its new value (in the copy the writer
		// writes) is left out: a write would change nothing, and some save
		// paths report an unchanged value as a failure.
		$change['changes'] = self::changed_only( $change['changes'] );
		if ( array() === $change['changes'] ) {
			return self::nothing_changed();
		}
		return $this->execute( $post, $change, $opts );
	}

	/**
	 * Replace a text in every writable field; see
	 * AB_MCP_Builder_Writer::replace().
	 *
	 * $opts beyond execute()'s: case_insensitive (bool), as wp_search_replace
	 * passes it. regex is refused with the way: the html fields hold markup
	 * a pattern could match beyond the text.
	 *
	 * @param WP_Post $post    Post.
	 * @param string  $search  Text to find.
	 * @param string  $replace Replacement.
	 * @param array   $opts    See execute().
	 * @return array|WP_Error
	 */
	public function replace( $post, $search, $replace, array $opts ) {
		$search  = (string) $search;
		$replace = (string) $replace;
		if ( '' === $search ) {
			return new WP_Error( 'ab_mcp_builder_no_search', __( 'Name the text to replace.', 'alphabridge-mcp' ) );
		}
		$adapter = $this->adapter();
		if ( null === $adapter ) {
			return new WP_Error( 'ab_mcp_builder_no_reader', __( 'The reader of this page builder is not loaded on this site. Change the page in its editor in wp-admin.', 'alphabridge-mcp' ) );
		}
		if ( ! empty( $opts['regex'] ) ) {
			return new WP_Error(
				'ab_mcp_builder_regex',
				/* translators: %s: page builder name */
				sprintf( __( 'A regular expression is not applied to the fields of a %s page: in a field that holds markup it could match more than the text. Search for the text itself, or run wp_search_replace with scope: raw.', 'alphabridge-mcp' ), $adapter->name() )
			);
		}
		$ci   = ! empty( $opts['case_insensitive'] );
		$post = self::fresh_post( (int) $post->ID );
		if ( null === $post ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Post not found. wp_list_posts or wp_search finds its id.', 'alphabridge-mcp' ) );
		}
		// The changes are worked out from the page as read now; the hash of
		// that page guards the write (element ids that are positions hold
		// only for it), unless the caller brings the hash it read with.
		if ( ! isset( $opts['layout_hash'] ) || '' === (string) $opts['layout_hash'] ) {
			$opts['layout_hash'] = AB_MCP_Builders::layout_hash( $post );
		}
		$changes = array();
		$left    = array();
		$handled = 0;
		foreach ( $adapter->outline( $post, $this->outline_opts() ) as $element ) {
			if ( ! empty( $element['locked'] ) || empty( $element['fields'] ) || ! isset( $element['id'] ) ) {
				continue;
			}
			$settings = null;
			foreach ( $element['fields'] as $name => $field ) {
				if ( ! isset( $field['kind'] ) || ! in_array( $field['kind'], self::REPLACE_KINDS, true ) ) {
					continue;
				}
				if ( null === $settings ) {
					$settings = $this->settings_of( $post, $element );
				}
				if ( is_wp_error( $settings ) ) {
					break;
				}
				$stored = self::value_at( $settings, (string) $name );
				if ( ! is_string( $stored ) ) {
					continue;
				}
				$count = 0;
				$after = self::replace_in( $field['kind'], $stored, $search, $replace, $count, $ci );
				if ( 0 === $count || $after === $stored ) {
					continue;
				}
				$c = array(
					'element' => (string) $element['id'],
					'type'    => isset( $element['type'] ) ? (string) $element['type'] : '',
					'field'   => (string) $name,
					'kind'    => (string) $field['kind'],
					'before'  => $stored,
					'after'   => $after,
					'data'    => array( 'path' => (string) $name ),
				);
				$ok = AB_MCP_Builder_Fields::validate_change( $c );
				if ( is_wp_error( $ok ) ) {
					// Not handled: these matches stay where they are, so they
					// count as locked (the way to them is scope raw), and the
					// reason goes with them.
					/* translators: 1: element:field, 2: reason */
					$left[] = sprintf( __( 'Left %1$s as it is: %2$s', 'alphabridge-mcp' ), $c['element'] . ':' . $c['field'], $ok->get_error_message() );
					continue;
				}
				$handled  += $count;
				$changes[] = $c;
			}
		}
		$change = $this->prepare(
			$post,
			array(
				'mode'           => 'text',
				'changes'        => $changes,
				'locked_matches' => max( 0, (int) $this->count_stored( $post, $search, $ci ) - $handled ),
				'left'           => $left,
			),
			$opts
		);
		if ( is_wp_error( $change ) ) {
			return $change;
		}
		$left = self::left_reasons( $change );
		if ( array() === $change['changes'] && array() !== $left ) {
			// Every match was left, each for a reason: say which, not «nothing
			// to change» — and hand the reasons and the locked count on, so
			// wp_search_replace names them too.
			return new WP_Error(
				'ab_mcp_builder_nothing',
				implode( ' ', $left ),
				array(
					'locked_matches' => isset( $change['locked_matches'] ) ? max( 0, (int) $change['locked_matches'] ) : 0,
					'left'           => $left,
				)
			);
		}
		$change['left']  = $left;
		$change['notes'] = array_merge( isset( $change['notes'] ) ? (array) $change['notes'] : array(), $left );
		return $this->execute( $post, $change, $opts );
	}

	/* ---------------------------------------------------------- helpers */

	/**
	 * The value at a dotted path through arrays and objects, or null.
	 *
	 * @param mixed  $data Settings.
	 * @param string $path Path.
	 * @return mixed
	 */
	public static function value_at( $data, $path ) {
		return AB_MCP_Builder_Element_Profile::value_at( $data, (string) $path );
	}

	/**
	 * Set the value at a dotted path through arrays and objects. Only a path
	 * that exists is set: a writer changes the fields the outline showed and
	 * never adds a setting.
	 *
	 * @param mixed  $data  Settings (by reference; objects are changed in place).
	 * @param string $path  Path.
	 * @param mixed  $value New value.
	 * @return bool Whether the path existed and was set.
	 */
	public static function set_at( &$data, $path, $value ) {
		$segments = explode( '.', (string) $path );
		$last     = (string) array_pop( $segments );
		$ref      = &$data;
		foreach ( $segments as $segment ) {
			if ( is_array( $ref ) && array_key_exists( $segment, $ref ) ) {
				$ref = &$ref[ $segment ];
			} elseif ( is_object( $ref ) && property_exists( $ref, $segment ) ) {
				$ref = &$ref->$segment;
			} else {
				return false;
			}
		}
		if ( is_array( $ref ) && array_key_exists( $last, $ref ) ) {
			$ref[ $last ] = $value;
			return true;
		}
		if ( is_object( $ref ) && property_exists( $ref, $last ) ) {
			$ref->$last = $value;
			return true;
		}
		return false;
	}

	/**
	 * Replace a text in a stored value: as it is, or — in a field that holds
	 * markup or a link, where the search text may stand HTML-escaped ("A &amp;
	 * B") — in that form, the replacement escaped the same way.
	 *
	 * @param string $kind    Kind of the field.
	 * @param string $stored  Stored value.
	 * @param string $search  Text.
	 * @param string $replace Replacement.
	 * @param int    $count   Matches (out).
	 * @param bool   $ci      Case-insensitive.
	 * @return string
	 */
	public static function replace_in( $kind, $stored, $search, $replace, &$count, $ci = false ) {
		$after = self::swap_in( $stored, $search, $replace, $ci, $count );
		if ( $count > 0 ) {
			return $after;
		}
		if ( in_array( $kind, array( 'html', 'url' ), true ) ) {
			$esc = self::esc_text( $search );
			if ( $esc !== $search ) {
				$after = self::swap_in( $stored, $esc, self::esc_text( $replace ), $ci, $count );
				if ( $count > 0 ) {
					return $after;
				}
			}
		}
		$count = 0;
		return $stored;
	}

	/**
	 * How often a text stands in a string, as replace_in() matches it.
	 *
	 * @param string $haystack String.
	 * @param string $needle   Text.
	 * @param bool   $ci       Case-insensitive.
	 * @return int
	 */
	public static function count_in( $haystack, $needle, $ci = false ) {
		$haystack = (string) $haystack;
		$needle   = (string) $needle;
		if ( '' === $needle ) {
			return 0;
		}
		if ( ! $ci ) {
			return substr_count( $haystack, $needle );
		}
		$n = preg_match_all( self::ci_pattern( $needle, $haystack ), $haystack );
		return false === $n ? 0 : (int) $n;
	}

	/**
	 * Replace every occurrence of a text, the replacement taken literally.
	 *
	 * @param string $stored      String.
	 * @param string $needle      Text.
	 * @param string $replacement Replacement.
	 * @param bool   $ci          Case-insensitive.
	 * @param int    $count       Replacements made (out).
	 * @return string
	 */
	private static function swap_in( $stored, $needle, $replacement, $ci, &$count ) {
		$stored = (string) $stored;
		$needle = (string) $needle;
		$count  = 0;
		if ( '' === $needle ) {
			return $stored;
		}
		if ( ! $ci ) {
			$count = substr_count( $stored, $needle );
			return $count > 0 ? str_replace( $needle, (string) $replacement, $stored ) : $stored;
		}
		$out = preg_replace_callback(
			self::ci_pattern( $needle, $stored ),
			static function () use ( $replacement ) {
				return (string) $replacement;
			},
			$stored,
			-1,
			$count
		);
		if ( null === $out ) {
			$count = 0;
			return $stored;
		}
		return $out;
	}

	/**
	 * The case-insensitive pattern for a text: Unicode case folding where
	 * text and subject are valid UTF-8, byte by byte (ASCII letters) where
	 * not, so an odd byte never turns the match off.
	 *
	 * @param string $needle  Text.
	 * @param string $subject Where it is looked for.
	 * @return string
	 */
	private static function ci_pattern( $needle, $subject ) {
		$utf8 = 1 === preg_match( '//u', (string) $needle ) && 1 === preg_match( '//u', (string) $subject );
		return '~' . preg_quote( (string) $needle, '~' ) . '~i' . ( $utf8 ? 'u' : '' );
	}

	/**
	 * Text as a browser writes it into a text node (the HTML serialisation
	 * algorithm): &, <, > and the no-break space escaped.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function esc_text( $text ) {
		return str_replace( array( '&', '<', '>', "\xC2\xA0" ), array( '&amp;', '&lt;', '&gt;', '&nbsp;' ), (string) $text );
	}

	/**
	 * Text as a browser writes it into a double-quoted attribute value: &, "
	 * and the no-break space escaped.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function esc_attr_value( $text ) {
		return str_replace( array( '&', '"', "\xC2\xA0" ), array( '&amp;', '&quot;', '&nbsp;' ), (string) $text );
	}

	/**
	 * A deep copy of settings, so a change never reaches an object a builder
	 * still holds in its own cache (unserialized objects are handles). Only
	 * stdClass comes back as an object, as the builders store it; settings
	 * that hold an object of another class are not copied (null) — the writer
	 * then refuses rather than hand the builder an incomplete object.
	 *
	 * @param mixed $settings Settings.
	 * @return mixed|null
	 */
	public static function deep_copy( $settings ) {
		$copy = unserialize( serialize( $settings ), array( 'allowed_classes' => array( 'stdClass' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- a copy of a value in memory, the classes limited.
		return self::only_plain( $copy ) ? $copy : null;
	}

	/**
	 * Whether a value holds nothing but scalars, arrays and stdClass objects.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Depth.
	 * @return bool
	 */
	private static function only_plain( $value, $depth = 0 ) {
		if ( $depth > 64 ) {
			return false;
		}
		if ( is_object( $value ) ) {
			if ( ! $value instanceof stdClass ) {
				return false;
			}
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( ! self::only_plain( $item, $depth + 1 ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * The answer when a field the outline showed is not in the stored
	 * settings any more.
	 *
	 * @param string $element Element id.
	 * @param string $field   Field name.
	 * @return WP_Error
	 */
	protected static function gone( $element, $field ) {
		return new WP_Error(
			'ab_mcp_builder_field_gone',
			sprintf(
				/* translators: 1: element id, 2: field name */
				__( 'The stored settings of element %1$s have no field %2$s (any more). Nothing was changed. Read the page again with wp_get_builder_layout.', 'alphabridge-mcp' ),
				(string) $element,
				(string) $field
			)
		);
	}

	/**
	 * The changes grouped by element, in order.
	 *
	 * @param array $changes Changes.
	 * @return array<string,array>
	 */
	protected static function by_element( array $changes ) {
		$out = array();
		foreach ( $changes as $c ) {
			$out[ (string) $c['element'] ][] = $c;
		}
		return $out;
	}
}
