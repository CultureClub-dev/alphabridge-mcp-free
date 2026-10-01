<?php
/**
 * Reads shortcodes in a text as a tree with byte offsets, for any tag —
 * registered or not — so a shortcode builder's page (WPBakery, Divi 4, Avada,
 * Flatsome, Enfold's meta) can be outlined and Pro can later change exactly one
 * attribute value or one content range.
 *
 * It reads them the way WordPress does (get_shortcode_regex(),
 * shortcode_parse_atts() in wp-includes/shortcodes.php), so the tree matches
 * what the site renders:
 * - "[tag …]" opens; its content runs to the FIRST "[/tag]" after it, and the
 *   content is read again for nested shortcodes. Two open tags of the same
 *   name therefore do not nest — the reason builders have "vc_row_inner".
 * - "[tag … /]" is self-closing; "[tag]" without any "[/tag]" stands alone.
 * - "[[tag]]" (with its content, if any) is an escaped shortcode: text.
 * - The attribute text runs to the first "]"; attributes come in all of
 *   WordPress' forms: name="v", name='v', name=v, "v", 'v' and bare words.
 *   Values are decoded as WordPress decodes them (stripcslashes(), a value
 *   with an unclosed HTML tag becomes ''); attr_spans give the byte range
 *   of each raw value in the text.
 *
 * Broken text never stops it: a "[" that opens nothing, an unterminated tag
 * or a stray "[/tag]" is text, and reading goes on after it.
 *
 * Node format:
 *   tag          tag name, or "#text" for a text run between shortcodes
 *   id           "s" + indices among siblings, joined by "." ("s0.2.1")
 *   start, end   byte offsets of the whole node (end exclusive)
 *   open_end     offset after the opening tag's "]"                 (shortcodes)
 *   content      [ start, end ] of the content, or null               (shortcodes)
 *   self_closing written as "[tag /]"                                (shortcodes)
 *   closed       has its "[/tag]"                                     (shortcodes)
 *   attrs        name => value; positional values under 0, 1, …    (shortcodes)
 *   attr_spans   same keys => [ start, end ] of the raw value         (shortcodes)
 *   attr_quotes  same keys => '"', "'" or '' (unquoted)               (shortcodes)
 *   attrs_truncated  true when more than MAX_ATTS attributes were left unread
 *   children     nodes inside the content (white-space text left out)
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Shortcode_Reader
 */
final class AB_MCP_Shortcode_Reader {

	/** Nesting deeper than this is not read into children (the content range stays). */
	const MAX_DEPTH = 64;

	/** Shortcodes read at most; the rest of the text stays text and "truncated" is set. */
	const MAX_NODES = 20000;

	/** Attributes read per tag at most: a page can carry a broken tag whose
	 * attribute text runs over the whole rest of the page. */
	const MAX_ATTS = 500;

	/** A tag name: what builders use, letters, digits, "_" and "-". */
	const NAME = '[A-Za-z0-9_][A-Za-z0-9_-]*';

	/** WordPress' attribute pattern (get_shortcode_atts_regex(), WordPress 7.0.2). */
	const ATTS = '/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|\'([^\']*)\'(?:\s|$)|(\S+)(?:\s|$)/';

	/**
	 * Parse a text.
	 *
	 * Without a tag list every "[name …]" counts — the reader cannot know
	 * which shortcodes a site registers — except one whose attribute text
	 * holds a "[": that is far more likely a bracket in prose in front of a
	 * real tag ("[note: see [vc_row]…") than an attribute, and builders encode
	 * brackets in attributes anyway. With $o['tags'] (a builder profile's
	 * tag names) only those count, exactly as WordPress reads registered
	 * shortcodes, "[" in attributes included.
	 *
	 * @param string $text Text, e.g. post_content.
	 * @param array  $o    tags: string[] of tag names, optional.
	 * @return array{nodes:array,truncated:bool}
	 */
	public static function parse( $text, array $o = array() ): array {
		$text = (string) $text;
		$tags = null;
		if ( isset( $o['tags'] ) && is_array( $o['tags'] ) ) {
			$tags = array_fill_keys( array_map( 'strval', $o['tags'] ), true );
		}
		$ctx = array(
			'text'      => $text,
			'tags'      => $tags,
			'closes'    => array(),
			'bracket'   => array( -1, false ),
			'count'     => 0,
			'truncated' => false,
		);
		$nodes = self::parse_range( $ctx, 0, strlen( $text ), '', 0 );
		return array(
			'nodes'     => $nodes,
			'truncated' => $ctx['truncated'],
		);
	}

	/**
	 * A byte range of the text.
	 *
	 * @param string $text  Text.
	 * @param int    $start Start offset.
	 * @param int    $end   End offset (exclusive).
	 * @return string
	 */
	public static function slice( $text, $start, $end ): string {
		return (string) substr( (string) $text, (int) $start, max( 0, (int) $end - (int) $start ) );
	}

	/**
	 * Attribute text parsed the way shortcode_parse_atts() does, with the byte
	 * range of every raw value. Offsets are relative to $base.
	 *
	 * @param string $atts Attribute text (between the tag name and "]").
	 * @param int    $base Offset of $atts in the whole text.
	 * @return array{attrs:array,spans:array,quotes:array,truncated:bool}
	 */
	public static function parse_atts( $atts, $base = 0 ): array {
		$out  = array(
			'attrs'     => array(),
			'spans'     => array(),
			'quotes'    => array(),
			'truncated' => false,
		);
		$orig = (string) $atts;
		// WordPress turns no-break and zero-width spaces into spaces first.
		// The pattern runs on a copy where they are spaces of the same byte
		// length, so its offsets still point into the original text.
		$same = preg_replace_callback(
			'/[\x{00a0}\x{200b}]+/u',
			static function ( $m ) {
				return str_repeat( ' ', strlen( $m[0] ) );
			},
			$orig
		);
		$atts = null !== $same ? $same : $orig; // Invalid UTF-8: as it is.
		// One match at a time, as preg_match_all() would find them, so a
		// broken tag cannot fill the memory with a match list.
		$position = 0;
		$offset   = 0;
		$length   = strlen( $atts );
		$read     = 0;
		while ( $offset < $length ) {
			if ( 1 !== preg_match( self::ATTS, $atts, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
				break;
			}
			if ( $read >= self::MAX_ATTS ) {
				$out['truncated'] = true;
				break;
			}
			++$read;
			$offset = max( $offset + 1, (int) $m[0][1] + strlen( $m[0][0] ) );
			$named = null;
			$group = 0;
			$quote = '';
			foreach ( array( array( 1, 2, '"' ), array( 3, 4, "'" ), array( 5, 6, '' ) ) as $g ) {
				// As in WordPress (! empty()): a name "0" names nothing.
				if ( isset( $m[ $g[0] ] ) && '' !== $m[ $g[0] ][0] && '0' !== $m[ $g[0] ][0] && -1 !== $m[ $g[0] ][1] ) {
					$named = strtolower( $m[ $g[0] ][0] );
					$group = $g[1];
					$quote = $g[2];
					break;
				}
			}
			if ( null === $named ) {
				// As in WordPress: an empty quoted value without a name is skipped.
				foreach ( array( array( 7, '"' ), array( 8, "'" ), array( 9, '' ) ) as $g ) {
					if ( isset( $m[ $g[0] ] ) && -1 !== $m[ $g[0] ][1] && ( '' !== $m[ $g[0] ][0] || 9 === $g[0] ) ) {
						$group = $g[0];
						$quote = $g[1];
						break;
					}
				}
				if ( 0 === $group ) {
					continue;
				}
				$key = $position++;
			} else {
				$key = $named;
			}
			$raw                   = (string) substr( $orig, (int) $m[ $group ][1], strlen( $m[ $group ][0] ) );
			$out['attrs'][ $key ]  = self::att_value( $raw );
			$out['spans'][ $key ]  = array( $base + (int) $m[ $group ][1], $base + (int) $m[ $group ][1] + strlen( $raw ) );
			$out['quotes'][ $key ] = $quote;
		}
		return $out;
	}

	/* ------------------------------------------------------------ private */

	/**
	 * Nodes in [ $from, $to ).
	 *
	 * @param array  $ctx    Parse state (by reference).
	 * @param int    $from   Start offset.
	 * @param int    $to     End offset.
	 * @param string $prefix Id prefix ("" at the top).
	 * @param int    $depth  Depth.
	 * @return array
	 */
	private static function parse_range( array &$ctx, $from, $to, $prefix, $depth ) {
		$nodes      = array();
		$text       = $ctx['text'];
		$pos        = $from;
		$text_start = $from;
		while ( $pos < $to ) {
			$lb = strpos( $text, '[', $pos );
			if ( false === $lb || $lb >= $to ) {
				break;
			}
			if ( $ctx['count'] >= self::MAX_NODES ) {
				$ctx['truncated'] = true;
				break;
			}
			// "[[tag]]": escaped, text — WordPress strips one pair of brackets.
			if ( $lb + 1 < $to && '[' === $text[ $lb + 1 ] ) {
				$inner = self::read_tag( $ctx, $lb + 1, $to );
				if ( null !== $inner && ! $inner['closing'] ) {
					$end = self::full_end( $ctx, $inner, $to );
					if ( $end < $to && ']' === $text[ $end ] ) {
						$pos = $end + 1;
						continue;
					}
				}
				$pos = $lb + 1;
				continue;
			}
			$tag = self::read_tag( $ctx, $lb, $to );
			if ( null === $tag || $tag['closing'] ) {
				// Not a tag, or a "[/tag]" nothing opened: text.
				$pos = null === $tag ? $lb + 1 : $tag['end'];
				continue;
			}
			self::add_text( $nodes, $text, $text_start, $lb, $prefix );
			++$ctx['count'];
			$id    = $prefix . ( '' === $prefix ? 's' : '.' ) . count( $nodes );
			$close = $tag['self_closing'] ? false : self::find_close( $ctx, $tag['name'], $tag['open_end'], $to );
			$parsed = self::parse_atts( $tag['atts'], $tag['atts_start'] );
			$node  = array(
				'tag'          => $tag['name'],
				'id'           => $id,
				'start'        => $lb,
				'end'          => false === $close ? $tag['open_end'] : $close + strlen( $tag['name'] ) + 3,
				'open_end'     => $tag['open_end'],
				'content'      => false === $close ? null : array( $tag['open_end'], $close ),
				'self_closing' => $tag['self_closing'],
				'closed'       => false !== $close,
				'attrs'        => $parsed['attrs'],
				'attr_spans'   => $parsed['spans'],
				'attr_quotes'  => $parsed['quotes'],
				'children'     => array(),
			);
			if ( $parsed['truncated'] ) {
				$node['attrs_truncated'] = true;
				$ctx['truncated']        = true;
			}
			if ( false !== $close ) {
				if ( $depth + 1 < self::MAX_DEPTH ) {
					$node['children'] = self::parse_range( $ctx, $tag['open_end'], $close, $id, $depth + 1 );
				} else {
					$ctx['truncated'] = true;
				}
			}
			$nodes[]    = $node;
			$pos        = $node['end'];
			$text_start = $pos;
		}
		self::add_text( $nodes, $text, $text_start, $to, $prefix );
		return $nodes;
	}

	/**
	 * The tag at $lb: [ closing, name, atts, atts_start, self_closing,
	 * open_end, end ], or null when "[" opens no tag here.
	 *
	 * @param array $ctx Parse state.
	 * @param int   $lb  Offset of "[".
	 * @param int   $to  End of the range.
	 * @return array|null
	 */
	private static function read_tag( array &$ctx, $lb, $to ) {
		$text = $ctx['text'];
		if ( $lb + 1 < $to && '/' === $text[ $lb + 1 ] ) {
			if ( 1 !== preg_match( '~\G(' . self::NAME . ')\]~', $text, $m, 0, $lb + 2 ) || $lb + 2 + strlen( $m[0] ) > $to
				|| ( null !== $ctx['tags'] && ! isset( $ctx['tags'][ $m[1] ] ) ) ) {
				return null;
			}
			return array(
				'closing' => true,
				'name'    => $m[1],
				'end'     => $lb + 2 + strlen( $m[0] ),
			);
		}
		// As get_shortcode_regex(): the name ends where no word character or
		// "-" follows — "[b[a]" opens "b", with "[a" as its attribute text.
		if ( 1 !== preg_match( '~\G(' . self::NAME . ')(?![\w-])~', $text, $m, 0, $lb + 1 )
			|| ( null !== $ctx['tags'] && ! isset( $ctx['tags'][ $m[1] ] ) ) ) {
			return null;
		}
		$name_end = $lb + 1 + strlen( $m[1] );
		$rb       = self::next_bracket( $ctx, $name_end );
		if ( false === $rb || $rb >= $to ) {
			return null; // Never closed: text.
		}
		$atts = (string) substr( $text, $name_end, $rb - $name_end );
		if ( null === $ctx['tags'] && false !== strpos( $atts, '[' ) ) {
			return null; // See parse(): prose in brackets, not a tag.
		}
		$self_closing = '' !== $atts && '/' === substr( $atts, -1 );
		if ( $self_closing ) {
			$atts = substr( $atts, 0, -1 );
		}
		return array(
			'closing'      => false,
			'name'         => $m[1],
			'atts'         => $atts,
			'atts_start'   => $name_end,
			'self_closing' => $self_closing,
			'open_end'     => $rb + 1,
			'end'          => $rb + 1,
		);
	}

	/**
	 * End offset of a tag read at some position, including its content and
	 * "[/tag]" when it has them.
	 *
	 * @param array $ctx Parse state.
	 * @param array $tag Tag from read_tag().
	 * @param int   $to  End of the range.
	 * @return int
	 */
	private static function full_end( array &$ctx, array $tag, $to ) {
		if ( $tag['self_closing'] ) {
			return $tag['open_end'];
		}
		$close = self::find_close( $ctx, $tag['name'], $tag['open_end'], $to );
		return false === $close ? $tag['open_end'] : $close + strlen( $tag['name'] ) + 3;
	}

	/**
	 * Offset of the first "]" at or after $from. Remembered, so a text full
	 * of "[" is still read in linear time.
	 *
	 * @param array $ctx  Parse state.
	 * @param int   $from Offset.
	 * @return int|false
	 */
	private static function next_bracket( array &$ctx, $from ) {
		list( $searched, $found ) = $ctx['bracket'];
		if ( $searched >= 0 && $searched <= $from && ( false === $found || $found >= $from ) ) {
			return $found;
		}
		$found          = strpos( $ctx['text'], ']', $from );
		$ctx['bracket'] = array( $from, $found );
		return $found;
	}

	/**
	 * Offset of the first "[/name]" in [ $from, $to ), or false. Remembered
	 * per name, for the same reason.
	 *
	 * @param array  $ctx  Parse state.
	 * @param string $name Tag name.
	 * @param int    $from Offset.
	 * @param int    $to   End of the range.
	 * @return int|false
	 */
	private static function find_close( array &$ctx, $name, $from, $to ) {
		$cached = isset( $ctx['closes'][ $name ] ) ? $ctx['closes'][ $name ] : null;
		if ( null !== $cached && $cached[0] <= $from && ( false === $cached[1] || $cached[1] >= $from ) ) {
			$found = $cached[1];
		} else {
			$found                   = strpos( $ctx['text'], '[/' . $name . ']', $from );
			$ctx['closes'][ $name ] = array( $from, $found );
		}
		return false !== $found && $found + strlen( $name ) + 3 <= $to ? $found : false;
	}

	/**
	 * Add a text node for [ $start, $end ) unless it is only white space.
	 *
	 * @param array  $nodes  Siblings (by reference).
	 * @param string $text   Whole text.
	 * @param int    $start  Start offset.
	 * @param int    $end    End offset.
	 * @param string $prefix Id prefix.
	 */
	private static function add_text( array &$nodes, $text, $start, $end, $prefix ) {
		if ( $end <= $start || '' === trim( (string) substr( $text, $start, $end - $start ) ) ) {
			return;
		}
		$nodes[] = array(
			'tag'      => '#text',
			'id'       => $prefix . ( '' === $prefix ? 's' : '.' ) . count( $nodes ),
			'start'    => $start,
			'end'      => $end,
			'children' => array(),
		);
	}

	/**
	 * One attribute value as WordPress hands it to a shortcode.
	 *
	 * @param string $raw Raw value.
	 * @return string
	 */
	private static function att_value( $raw ) {
		$plain = preg_replace( '/[\x{00a0}\x{200b}]+/u', ' ', (string) $raw );
		$value = stripcslashes( null !== $plain ? $plain : (string) $raw );
		// shortcode_parse_atts(): "Reject any unclosed HTML elements."
		if ( false !== strpos( $value, '<' ) && 1 !== preg_match( '/^[^<]*+(?:<[^>]*+>[^<]*+)*+$/', $value ) ) {
			return '';
		}
		return $value;
	}
}
