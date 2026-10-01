<?php
/**
 * Small, dependency-free HTML helpers for the builder readers.
 *
 * Why not WP_HTML_Tag_Processor: the readers must behave the same in the unit
 * tests (which have no WordPress) and on a site, and they only ever READ a few
 * values out of markup a builder saved: the visible text of an element, an
 * inner fragment, one attribute. Writing (Pro) goes through WordPress' HTML API.
 *
 * Everything here works on byte offsets of the original string. Comments and
 * the content of script-like elements are masked with spaces of the same
 * length before tags are searched, so a "<p>" inside a comment or a script is
 * never mistaken for an element and offsets still point into the original.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Builder_Html
 */
final class AB_MCP_Builder_Html {

	/**
	 * Elements whose content is never visible text: code, styling and vector
	 * markup. Their content is dropped from every text and html value, so a
	 * reader can never hand out a script or a stylesheet as "text".
	 */
	const HIDDEN = array( 'script', 'style', 'svg', 'template', 'noscript', 'iframe', 'object', 'math', 'textarea', 'title' );

	/**
	 * Inline tags kept in an "html" field. Everything else is reduced to its
	 * text; no attribute survives except a safe href on a link.
	 */
	const INLINE = array( 'a', 'abbr', 'b', 'br', 'cite', 'code', 'del', 'em', 'i', 'ins', 'kbd', 'mark', 'q', 's', 'small', 'strong', 'sub', 'sup', 'u' );

	/**
	 * Tags that separate words when the markup is read as text.
	 */
	const BLOCK = array( 'address', 'article', 'aside', 'blockquote', 'br', 'dd', 'details', 'div', 'dl', 'dt', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'summary', 'table', 'td', 'th', 'tr', 'ul' );

	/**
	 * Marks a rebuilt tag in simple() while the rest is stripped: a control
	 * character no visible text needs, which strip_tags() leaves alone.
	 */
	const MARK = "\x1A";

	/**
	 * Elements that never have an end tag.
	 */
	const VOID = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );

	/**
	 * Start or end tag with quoted or unquoted attributes. Quotes are honoured,
	 * so a ">" inside an attribute value does not end the tag.
	 */
	const TAG = '~<(/?)([a-zA-Z][a-zA-Z0-9:-]*)((?:\s+[^\s"\'>/=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?)*)\s*(/?)>~';

	/**
	 * Visible text of an HTML fragment: hidden elements and comments dropped,
	 * tags removed (block-level ones leave a space), entities decoded and
	 * white space collapsed, as a browser would show it.
	 *
	 * @param string $html Fragment.
	 * @return string
	 */
	public static function text( $html ) {
		$html = self::drop_hidden( (string) $html );
		$html = preg_replace_callback(
			self::TAG,
			static function ( $m ) {
				return in_array( strtolower( $m[2] ), self::BLOCK, true ) ? ' ' : '';
			},
			$html
		);
		// What the pattern did not take for a tag (broken markup) goes too.
		$html = strip_tags( (string) $html );
		return self::squash( html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * A fragment reduced to simple HTML: hidden elements and comments dropped,
	 * only the inline tags of INLINE kept, without attributes except a safe
	 * href on links. Entities stay as they are.
	 *
	 * Only tags this method writes itself survive. A tag the pattern does
	 * not take — malformed attributes such as a quoted name or a bare "=x" —
	 * would otherwise reach strip_tags() with its attributes, and a list of
	 * allowed tags there keeps every attribute of an allowed tag. So each
	 * rebuilt tag stands in as a placeholder while strip_tags() removes
	 * everything else that looks like a tag, and only then comes back. A
	 * closing tag without an opening one is dropped.
	 *
	 * @param string $html Fragment.
	 * @return string
	 */
	public static function simple( $html ) {
		// The placeholder mark cannot come from the page: it is removed first.
		$html  = str_replace( self::MARK, '', self::drop_hidden( (string) $html ) );
		$built = array();
		$out   = preg_replace_callback(
			self::TAG,
			static function ( $m ) use ( &$built ) {
				$name = strtolower( $m[2] );
				if ( ! in_array( $name, self::INLINE, true ) ) {
					return in_array( $name, self::BLOCK, true ) ? ' ' : '';
				}
				if ( '/' === $m[1] ) {
					if ( 'br' === $name ) {
						return '';
					}
					$tag = '</' . $name . '>';
				} elseif ( 'a' === $name ) {
					$href = self::attr( $m[3], 'href' );
					$href = null === $href ? null : self::safe_url( $href );
					$tag  = null === $href ? '<a>' : '<a href="' . htmlspecialchars( $href, ENT_QUOTES, 'UTF-8' ) . '">';
				} else {
					$tag = '<' . $name . '>';
				}
				$built[] = array( $name, '/' === $m[1], $tag );
				return self::MARK . ( count( $built ) - 1 ) . self::MARK;
			},
			$html
		);
		// Everything still shaped like a tag is the page's, not ours.
		$out  = strip_tags( (string) $out );
		$open = array();
		$out  = preg_replace_callback(
			'~' . self::MARK . '(\d+)' . self::MARK . '~',
			static function ( $m ) use ( $built, &$open ) {
				list( $name, $closing, $tag ) = $built[ (int) $m[1] ];
				if ( 'br' === $name ) {
					return $tag;
				}
				if ( $closing ) {
					if ( empty( $open[ $name ] ) ) {
						return '';
					}
					--$open[ $name ];
					return $tag;
				}
				$open[ $name ] = ( isset( $open[ $name ] ) ? $open[ $name ] : 0 ) + 1;
				return $tag;
			},
			(string) $out
		);
		return self::squash( (string) $out );
	}

	/**
	 * The first element matching a selector, with byte offsets into $html.
	 *
	 * Selector grammar (deliberately small): "" for the first element; "tag",
	 * ".class", "tag.class"; several alternatives separated by commas, the
	 * first match in document order wins; a leading "> " restricts the match to
	 * direct children of the fragment's first element.
	 *
	 * @param string $html     Fragment.
	 * @param string $selector Selector.
	 * @return array{tag:string,attrs:string,start:int,open_end:int,inner_end:int,end:int}|null
	 */
	public static function find( $html, $selector = '' ) {
		$html   = (string) $html;
		$masked = self::mask( $html );
		if ( false === preg_match_all( self::TAG, $masked, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$selector = trim( (string) $selector );
		$child    = false;
		if ( 0 === strpos( $selector, '>' ) ) {
			$child    = true;
			$selector = trim( substr( $selector, 1 ) );
		}
		$alts = array();
		foreach ( '' === $selector ? array( '' ) : explode( ',', $selector ) as $alt ) {
			$alt    = trim( $alt );
			$dot    = strpos( $alt, '.' );
			$alts[] = array(
				'tag'   => strtolower( false === $dot ? $alt : substr( $alt, 0, $dot ) ),
				'class' => false === $dot ? '' : substr( $alt, $dot + 1 ),
			);
		}

		$depth = 0;
		$count = count( $tags );
		for ( $i = 0; $i < $count; $i++ ) {
			$t     = $tags[ $i ];
			$name  = strtolower( $t[2][0] );
			$close = '/' === $t[1][0];
			$void  = in_array( $name, self::VOID, true ) || '/' === $t[4][0];
			if ( $close ) {
				$depth = max( 0, $depth - 1 );
				continue;
			}
			$wanted_depth = $child ? 1 : null;
			if ( null === $wanted_depth || $depth === $wanted_depth ) {
				foreach ( $alts as $alt ) {
					if ( '' !== $alt['tag'] && $alt['tag'] !== $name ) {
						continue;
					}
					if ( '' !== $alt['class'] && ! self::has_class( $t[3][0], $alt['class'] ) ) {
						continue;
					}
					$start    = (int) $t[0][1];
					$open_end = $start + strlen( $t[0][0] );
					if ( $void ) {
						return array(
							'tag'       => $name,
							'attrs'     => substr( $html, (int) $t[3][1], strlen( $t[3][0] ) ),
							'start'     => $start,
							'open_end'  => $open_end,
							'inner_end' => $open_end,
							'end'       => $open_end,
						);
					}
					$end = self::matching_end( $tags, $i, $name, strlen( $html ) );
					return array(
						'tag'       => $name,
						'attrs'     => substr( $html, (int) $t[3][1], strlen( $t[3][0] ) ),
						'start'     => $start,
						'open_end'  => $open_end,
						'inner_end' => $end[0],
						'end'       => $end[1],
					);
				}
			}
			if ( ! $void ) {
				++$depth;
			}
		}
		return null;
	}

	/**
	 * Inner HTML of the first element matching a selector, or null.
	 *
	 * @param string $html     Fragment.
	 * @param string $selector Selector, see find().
	 * @return string|null
	 */
	public static function inner( $html, $selector = '' ) {
		$found = self::find( $html, $selector );
		if ( null === $found ) {
			return null;
		}
		return substr( (string) $html, $found['open_end'], $found['inner_end'] - $found['open_end'] );
	}

	/**
	 * One attribute of the first element matching a selector, entities
	 * decoded, or null when the element or the attribute is missing.
	 *
	 * @param string $html     Fragment.
	 * @param string $selector Selector, see find().
	 * @param string $name     Attribute name.
	 * @return string|null
	 */
	public static function element_attr( $html, $selector, $name ) {
		$found = self::find( $html, $selector );
		return null === $found ? null : self::attr( $found['attrs'], $name );
	}

	/**
	 * One attribute out of a raw attribute string, entities decoded; '' for an
	 * attribute without value, null when it is missing.
	 *
	 * @param string $attrs Raw attribute text of a start tag.
	 * @param string $name  Attribute name, case-insensitive.
	 * @return string|null
	 */
	public static function attr( $attrs, $name ) {
		if ( ! preg_match_all( '~([^\s"\'>/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~', (string) $attrs, $m, PREG_SET_ORDER ) ) {
			return null;
		}
		$name = strtolower( (string) $name );
		foreach ( $m as $one ) {
			if ( strtolower( $one[1] ) !== $name ) {
				continue;
			}
			$value = '';
			foreach ( array( 2, 3, 4 ) as $k ) {
				if ( isset( $one[ $k ] ) && '' !== $one[ $k ] ) {
					$value = $one[ $k ];
					break;
				}
			}
			return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return null;
	}

	/**
	 * The address unless it could run code: javascript:, vbscript: and data:
	 * addresses are refused (null), whatever case or control characters hide
	 * the scheme. With $image_data, an inline raster image (data:image/png,
	 * jpeg, gif, webp, avif) passes: an image source written that way is the
	 * picture itself, and none of those types runs script (SVG can, so it
	 * stays refused). Everything else — relative links, anchors, mailto, tel
	 * and any ordinary web address — is returned as it was.
	 *
	 * @param string $url        Address.
	 * @param bool   $image_data Whether an inline raster image is acceptable.
	 * @return string|null
	 */
	public static function safe_url( $url, $image_data = false ) {
		$url   = (string) $url;
		$plain = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$plain = strtolower( (string) preg_replace( '~[\x00-\x20\x7f]+~', '', $plain ) );
		if ( $image_data && 1 === preg_match( '~^data:image/(?:png|jpeg|jpg|gif|webp|avif)[;,]~', $plain ) ) {
			return trim( $url );
		}
		foreach ( array( 'javascript:', 'vbscript:', 'data:' ) as $scheme ) {
			if ( 0 === strpos( $plain, $scheme ) ) {
				return null;
			}
		}
		return trim( $url );
	}

	/**
	 * Whether a raw attribute string carries a class.
	 *
	 * @param string $attrs Raw attribute text.
	 * @param string $class Class name.
	 * @return bool
	 */
	private static function has_class( $attrs, $class ) {
		$value = self::attr( $attrs, 'class' );
		if ( null === $value ) {
			return false;
		}
		return in_array( $class, preg_split( '~\s+~', trim( $value ) ), true );
	}

	/**
	 * Offsets of the end tag that closes the element opened at $i: the start
	 * of the end tag and the offset after it. An element that is never closed
	 * runs to the end of the fragment, as a browser would read it.
	 *
	 * @param array  $tags  Matches from preg_match_all( TAG ).
	 * @param int    $i     Index of the start tag.
	 * @param string $name  Lower-case tag name.
	 * @param int    $limit Length of the fragment.
	 * @return int[]
	 */
	private static function matching_end( array $tags, $i, $name, $limit ) {
		$depth = 0;
		$count = count( $tags );
		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( strtolower( $tags[ $j ][2][0] ) !== $name ) {
				continue;
			}
			if ( '/' === $tags[ $j ][1][0] ) {
				if ( 0 === $depth ) {
					return array( (int) $tags[ $j ][0][1], (int) $tags[ $j ][0][1] + strlen( $tags[ $j ][0][0] ) );
				}
				--$depth;
			} elseif ( '/' !== $tags[ $j ][4][0] ) {
				++$depth;
			}
		}
		return array( $limit, $limit );
	}

	/**
	 * Comments and the content of hidden elements replaced by spaces of the
	 * same byte length, so tag search never looks inside them and offsets
	 * stay valid for the original string.
	 *
	 * @param string $html Fragment.
	 * @return string
	 */
	private static function mask( $html ) {
		$blank = static function ( $m ) {
			return str_repeat( ' ', strlen( $m[0] ) );
		};
		$html = (string) preg_replace_callback( '~<!--.*?(?:-->|$)~s', $blank, $html );
		$html = (string) preg_replace_callback(
			'~(<(' . implode( '|', self::HIDDEN ) . ')\b[^>]*>)(.*?)(?=</\2\s*>|$)~is',
			static function ( $m ) {
				return $m[1] . str_repeat( ' ', strlen( $m[3] ) );
			},
			$html
		);
		return $html;
	}

	/**
	 * Comments and hidden elements (with their content) removed. An element
	 * that is opened and never closed takes the rest of the fragment with it,
	 * as a browser treats an unclosed script.
	 *
	 * @param string $html Fragment.
	 * @return string
	 */
	private static function drop_hidden( $html ) {
		$html = (string) preg_replace( '~<!--.*?(?:-->|$)~s', '', (string) $html );
		return (string) preg_replace( '~<(' . implode( '|', self::HIDDEN ) . ')\b[^>]*>.*?(?:</\1\s*>|$)~is', '', $html );
	}

	/**
	 * White space collapsed to single spaces and trimmed. A no-break space
	 * counts as white space, as it looks like one.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function squash( $text ) {
		$out = preg_replace( '~[\s\x{00A0}]+~u', ' ', (string) $text );
		if ( null === $out ) {
			// Invalid UTF-8: the Unicode-aware pattern refuses the string; the
			// ASCII one still collapses ordinary white space.
			$out = preg_replace( '~\s+~', ' ', (string) $text );
		}
		return trim( (string) $out );
	}
}
