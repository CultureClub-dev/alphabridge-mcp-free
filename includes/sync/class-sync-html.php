<?php
/**
 * The references the save of a core block writes into its HTML (Z8,
 * decision 12; AB_MCP_Sync_Refs::HTML), read with the HTML API of the core:
 * the class token wp-image-<id> exactly as a token of the class attribute,
 * and the attribute data-id exactly by its name — not data-class, not a
 * file name that holds the same letters, with character references
 * decoded as a browser decodes them.
 *
 * WP_HTML_Tag_Processor finds the tags and their attributes; its bookmark
 * gives the span of a tag in the text, so the canonical form can cut it out.
 * A tag with references is written in the canonical form as the core writes
 * it once its attributes are set again with PLACEHOLDER for each id; the
 * rewriter sets the same attributes to the ids of the other side. Both write
 * through set_attribute(), so a rewritten tag reads alike again.
 *
 * A class id counts as rewritable only in the spelling of canonical():
 * otherwise AB_MCP_Sync_Refs locks the object (*:reference_spelling).
 *
 * Loaded by AB_MCP_Sync_Refs only where a text can hold such a reference.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Sync_Html
 */
final class AB_MCP_Sync_Html extends WP_HTML_Tag_Processor {

	/** A class token that holds an id; the core reads it so (wp-includes/media.php:2009). */
	const CLASS_TOKEN = '/^wp-image-(\d+)\z/i';

	/** The attribute that holds an id. */
	const DATA_ID = 'data-id';

	/** What stands for an id in the canonical form of a tag. */
	const PLACEHOLDER = '~';

	/** The name of the bookmark that gives a tag's span. */
	const MARK = 'ab_mcp_sync_tag';

	/** The elements whose content the HTML API reads as text (raw text and RCDATA). */
	const TEXT_ELEMENTS = array( 'IFRAME', 'NOEMBED', 'NOFRAMES', 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE', 'XMP' );

	/**
	 * The JavaScript MIME type essences of the HTML standard, and module: a
	 * script of one of these types runs.
	 */
	const JS_TYPES = array( 'application/ecmascript', 'application/javascript', 'application/x-ecmascript', 'application/x-javascript', 'text/ecmascript', 'text/javascript', 'text/javascript1.0', 'text/javascript1.1', 'text/javascript1.2', 'text/javascript1.3', 'text/javascript1.4', 'text/javascript1.5', 'text/jscript', 'text/livescript', 'text/x-ecmascript', 'text/x-javascript', 'module' );

	/** What value_span() puts in place of a value to see the HTML API read it there. */
	const PROBE = 'ab-mcp-sync-probe';

	/**
	 * The tags of a text that hold references: each with its span, its ids in
	 * the order of the tag (class tokens, then data-id) and the tag as the
	 * canonical form has it.
	 *
	 * @param string   $text  Text.
	 * @param string[] $rules class, data_id.
	 * @return array<int,array{at:int,len:int,ids:array,canonical:bool|null,tag:string}>
	 *               canonical: canonical().
	 */
	public static function tags( $text, array $rules ) {
		$text = (string) $text;
		$out  = array();
		$p    = new self( $text );
		while ( $p->next_tag() ) {
			$ids = $p->ids( $rules );
			if ( array() === $ids || ! $p->set_bookmark( self::MARK ) ) {
				continue;
			}
			$span = $p->bookmarks[ self::MARK ];
			$p->release_bookmark( self::MARK );
			$raw   = substr( $text, $span->start, $span->length );
			$out[] = array(
				'at'        => (int) $span->start,
				'len'       => (int) $span->length,
				'ids'       => $ids,
				'canonical' => $p->canonical( $raw, $ids ),
				'tag'       => self::set_ids(
					$raw,
					$rules,
					static function () {
						return self::PLACEHOLDER;
					}
				),
			);
		}
		return $out;
	}

	/**
	 * The tags of a text as the HTML API reads them: each opener with its
	 * span, its name, its attributes (decoded as the browser decodes them:
	 * get_attribute()) and, for TEXT_ELEMENTS, its text. Null
	 * where the text ends in a token the HTML API cannot finish: what follows
	 * is not read.
	 *
	 * @param string $text Text.
	 * @return array<int,array{at:int,len:int,tag:string,attrs:array<string,string|true>,text:string|null}>|null
	 */
	public static function tag_list( $text ) {
		$text = (string) $text;
		$out  = array();
		$p    = new self( $text );
		while ( $p->next_token() ) {
			if ( '#tag' !== $p->get_token_type() || $p->is_tag_closer() || ! $p->set_bookmark( self::MARK ) ) {
				continue;
			}
			$span = $p->bookmarks[ self::MARK ];
			$p->release_bookmark( self::MARK );
			$attrs = array();
			foreach ( $p->get_attribute_names_with_prefix( '' ) ?? array() as $name ) {
				$value = $p->get_attribute( $name );
				if ( is_string( $value ) || true === $value ) {
					$attrs[ $name ] = $value;
				}
			}
			$tag   = (string) $p->get_tag();
			$out[] = array(
				'at'    => (int) $span->start,
				'len'   => (int) $span->length,
				'tag'   => $tag,
				'attrs' => $attrs,
				'text'  => in_array( $tag, self::TEXT_ELEMENTS, true ) ? (string) $p->get_modifiable_text() : null,
			);
		}
		return $p->paused_at_incomplete_token() ? null : $out;
	}

	/**
	 * What a script is by its type, as the HTML standard reads it: js (no
	 * type, an empty one, or the language of an old script, a JavaScript
	 * MIME type or module, its parameters aside: it runs), json (a JSON type,
	 * importmap, speculationrules: data in JSON), data (any other: not run).
	 *
	 * @param array $attrs Of tag_list().
	 * @return string js, json or data.
	 */
	public static function script_kind( array $attrs ) {
		$type = isset( $attrs['type'] ) && is_string( $attrs['type'] ) ? $attrs['type'] : '';
		if ( '' === trim( $type ) && isset( $attrs['language'] ) && is_string( $attrs['language'] ) && '' !== trim( $attrs['language'] ) ) {
			$type = 'text/' . $attrs['language'];
		}
		$type = strtolower( trim( $type, " \t\n\f\r" ) );
		$base = trim( (string) strtok( $type . ';', ';' ) );
		if ( '' === $type || in_array( $base, self::JS_TYPES, true ) ) {
			return 'js';
		}
		return 'importmap' === $base || 'speculationrules' === $base || preg_match( '#[/+]json\z#', $base ) ? 'json' : 'data';
	}

	/**
	 * Where the value of an attribute stands in the stored text of its tag,
	 * only where it is stored in double or single quotes in a spelling the
	 * core writes (the value itself where nothing needs escaping, as
	 * esc_attr() and as esc_url() escape it, in UTF-8), and only where the
	 * HTML API reads that span as the value:
	 * with the span replaced, get_attribute() gives the replacement (not a
	 * spelling inside another attribute). Null for every other spelling.
	 *
	 * @param string $raw   The stored text of the tag.
	 * @param string $name  Attribute (lower case).
	 * @param string $value Its value, decoded.
	 * @return array{0:int,1:int}|null Offset and length of the value in $raw.
	 */
	public static function value_span( $raw, $name, $value ) {
		$raw     = (string) $raw;
		$escaped = htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8', false );
		// As stored, as esc_attr() writes it (&amp;), as esc_url() writes it
		// (&#038;); made without a function that reads an option.
		foreach ( array_unique( array( (string) $value, $escaped, str_replace( '&amp;', '&#038;', $escaped ) ) ) as $spelling ) {
			foreach ( array( '"', "'" ) as $quote ) {
				$at = strpos( $raw, ' ' . $name . '=' . $quote . $spelling . $quote );
				if ( false === $at ) {
					continue;
				}
				$at += strlen( $name ) + 3;
				$p   = new self( substr_replace( $raw, self::PROBE, $at, strlen( $spelling ) ) );
				if ( $p->next_tag() && self::PROBE === $p->get_attribute( $name ) ) {
					return array( $at, strlen( $spelling ) );
				}
			}
		}
		return null;
	}

	/**
	 * The spans of the tags the HTML API reads in a text.
	 *
	 * @param string $text Text.
	 * @return array<int,array{0:int,1:int}> Start and end of each.
	 */
	public static function tag_spans( $text ) {
		$out = array();
		$p   = new self( (string) $text );
		while ( $p->next_tag() ) {
			if ( ! $p->set_bookmark( self::MARK ) ) {
				continue;
			}
			$span = $p->bookmarks[ self::MARK ];
			$p->release_bookmark( self::MARK );
			$out[] = array( (int) $span->start, (int) $span->start + (int) $span->length );
		}
		return $out;
	}

	/**
	 * A text with the ids of its tags replaced.
	 *
	 * @param string   $text    Text.
	 * @param string[] $rules   class, data_id.
	 * @param callable $map     function( int $id ): int|null, the id of the
	 *                          other side; null leaves it.
	 * @param bool     $changed Set when anything changed.
	 * @return string
	 */
	public static function rewrite( $text, array $rules, callable $map, &$changed ) {
		$text = (string) $text;
		$p    = new self( $text );
		$mine = false;
		while ( $p->next_tag() ) {
			if ( array() !== $p->ids( $rules ) && $p->put_ids( $rules, $map ) ) {
				$mine = true;
			}
		}
		if ( ! $mine ) {
			return $text;
		}
		$changed = true;
		return $p->get_updated_html();
	}

	/**
	 * One tag with its ids set to what $id_for gives.
	 *
	 * @param string   $tag    The text of one tag.
	 * @param string[] $rules  class, data_id.
	 * @param callable $id_for function( int $id ): string|int|null.
	 * @return string
	 */
	private static function set_ids( $tag, array $rules, callable $id_for ) {
		$p = new self( $tag );
		if ( ! $p->next_tag() ) {
			return $tag;
		}
		$p->put_ids( $rules, $id_for, true );
		return $p->get_updated_html();
	}

	/**
	 * Whether the ids of the tag the processor stands on are in the spelling
	 * that is rewritten; null where they are and the tag has no class id
	 * (there is nothing to rewrite in its class). Each id in the spelling of
	 * AB_MCP_Sync_Canon::id_spelling(), a data-id without a character
	 * reference as stored. The class id: an img whose stored text holds
	 * wp-image- only in the class attribute the HTML API reads (the first
	 * of that name: a later one is a duplicate it passes over, the pattern
	 * of wp_filter_content_tags() does not), followed by the digits of the
	 * id as stored, and whose attribute values decoded hold it once. Then
	 * the stored text holds it exactly once. The core reads the first
	 * wp-image-<digits> of the stored tag: in this spelling the id of the
	 * class, or none.
	 *
	 * @param string $raw The stored text of the tag.
	 * @param array  $ids ids().
	 * @return bool|null
	 */
	private function canonical( $raw, array $ids ) {
		$raw = (string) $raw;
		foreach ( $ids as $id ) {
			if ( null === $id['id'] || ( 'data_id' === $id['rule'] && self::stored_reference( $raw, self::DATA_ID ) ) ) {
				return false;
			}
		}
		$class = array_values( array_filter( $ids, static fn( $id ) => 'class' === $id['rule'] ) );
		if ( array() === $class ) {
			return null;
		}
		// The core reads the digits after the first wp-image- as stored: the
		// class id, digit for digit (no character reference among them).
		if ( 'IMG' !== $this->get_tag() || 1 !== preg_match( '/wp-image-([0-9]*)/i', $raw, $core ) || (string) $class[0]['id'] !== $core[1] ) {
			return false;
		}
		// Without the class the HTML API reads, no wp-image- is left: not in
		// a name, not in a duplicate attribute (were the class not set, the
		// text would keep its wp-image-). So the stored tag holds wp-image-
		// in that class only, and there once: twice would be counted below.
		$p = new self( $raw );
		$p->next_tag();
		$p->set_attribute( 'class', '' );
		if ( false !== stripos( $p->get_updated_html(), 'wp-image-' ) ) {
			return false;
		}
		$seen = 0;
		foreach ( $this->get_attribute_names_with_prefix( '' ) ?? array() as $name ) {
			$seen += preg_match_all( '/wp-image-/i', (string) $this->get_attribute( $name ) );
		}
		return 1 === $seen;
	}

	/**
	 * Whether an attribute of a tag holds a character reference as stored:
	 * set_attribute() replaces it (and only it) with a text without one.
	 *
	 * @param string $raw  The stored text of the tag.
	 * @param string $name Attribute.
	 * @return bool
	 */
	private static function stored_reference( $raw, $name ) {
		$p = new self( $raw );
		$p->next_tag();
		$p->set_attribute( $name, '' );
		return substr_count( $raw, '&' ) !== substr_count( $p->get_updated_html(), '&' );
	}

	/**
	 * The ids of the tag the processor stands on.
	 *
	 * @param string[] $rules class, data_id.
	 * @return array<int,array{rule:string,id:int|null}> id null: in another
	 *               spelling (AB_MCP_Sync_Canon::id_spelling()); a token
	 *               that holds no id is none.
	 */
	private function ids( array $rules ) {
		$out = array();
		if ( in_array( 'class', $rules, true ) ) {
			foreach ( $this->class_list() ?? array() as $token ) {
				if ( preg_match( self::CLASS_TOKEN, (string) $token, $m ) && self::holds_id( $m[1] ) ) {
					$out[] = array(
						'rule' => 'class',
						'id'   => AB_MCP_Sync_Canon::id_spelling( $m[1] ),
					);
				}
			}
		}
		if ( in_array( 'data_id', $rules, true ) ) {
			$value = $this->get_attribute( self::DATA_ID );
			if ( self::holds_id( $value ) ) {
				$out[] = array(
					'rule' => 'data_id',
					'id'   => AB_MCP_Sync_Canon::id_spelling( $value ),
				);
			}
		}
		return $out;
	}

	/**
	 * Whether the digits of a class token or the text of a data-id hold an
	 * id, in its spelling or in another (AB_MCP_Sync_Canon::id_spelling()
	 * is not 0): ids() names them, put_ids() sets them.
	 *
	 * @param mixed $value Digits or the value of data-id.
	 * @return bool
	 */
	private static function holds_id( $value ) {
		return is_string( $value ) && 0 !== AB_MCP_Sync_Canon::id_spelling( $value );
	}

	/**
	 * Set the ids of the tag the processor stands on.
	 *
	 * @param string[] $rules  class, data_id.
	 * @param callable $id_for The new id; null keeps it.
	 * @param bool     $always Write the attributes even where nothing changes.
	 * @return bool Whether an attribute was set.
	 */
	private function put_ids( array $rules, callable $id_for, $always = false ) {
		$set = false;
		if ( in_array( 'class', $rules, true ) ) {
			$class = $this->get_attribute( 'class' );
			if ( is_string( $class ) ) {
				$tokens = preg_split( '/[ \t\n\f\r]+/', $class, -1, PREG_SPLIT_NO_EMPTY );
				$new    = false;
				foreach ( $tokens as $i => $token ) {
					if ( preg_match( self::CLASS_TOKEN, $token, $m ) && self::holds_id( $m[1] ) ) {
						$id = call_user_func( $id_for, (int) $m[1] );
						if ( null !== $id && 'wp-image-' . $id !== $token ) {
							$tokens[ $i ] = 'wp-image-' . $id;
							$new          = true;
						}
					}
				}
				if ( $new || $always ) {
					$this->set_attribute( 'class', implode( ' ', $tokens ) );
					$set = true;
				}
			}
		}
		if ( in_array( 'data_id', $rules, true ) ) {
			$value = $this->get_attribute( self::DATA_ID );
			if ( self::holds_id( $value ) ) {
				$id = call_user_func( $id_for, (int) $value );
				if ( null !== $id && ( (string) $id !== $value || $always ) ) {
					$this->set_attribute( self::DATA_ID, (string) $id );
					$set = true;
				}
			}
		}
		return $set;
	}
}
