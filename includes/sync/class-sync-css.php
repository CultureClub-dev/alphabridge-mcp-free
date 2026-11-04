<?php
/**
 * The one reading of CSS of the takeover (Codex, round 17). No parser of
 * the core reads CSS, so the rules are closed ones, no tokenizer: every
 * text of CSS (style, a style attribute, custom_css, the value of a key
 * css of global styles or of JSON like theme.json, blocks.*.css as well)
 * is read here, for the credentials (AB_MCP_Sync_Credentials::flat()) and
 * for the addresses (AB_MCP_Sync_Urls) alike:
 * - the comments outside of strings removed for the addresses and the
 *   properties (a string between " or ' with its escapes, ended by a line
 *   break as in CSS; an escaped character starts neither); the credentials
 *   read the text with them as well (it is taken over as it is);
 * - the addresses of the text: the argument of every url(…), the string of
 *   every @import "…" and the strings of image-set(…), where they stand
 *   in the stored text (rewritten there in their canonical form); every
 *   other string (a custom property: --bild:"/?attachment_id=12" reaches
 *   image-set(var(--bild)) as an address), read only;
 * - what locks in any context: a backslash outside of strings and
 *   comments in the name of a function (an identifier right before "("),
 *   of an at-rule, or in the value of a declaration (u\72l(, image-s\65t(,
 *   @imp\6Frt, color:re\64); in a selector (the prelude of a style rule,
 *   before "{": .md\:flex, .w-1\/2) and the name of a property it is free;
 *   a comment inside an address, a backslash in it, a url( or an @import
 *   not read so;
 * - data: as such an address: data() decodes an image (DATA_IMAGES), the
 *   callers check what it holds; any other is not read;
 * - the text with the escapes of CSS decoded (unescaped()): the callers
 *   lock where it holds more than the text without them (escapes in
 *   strings, content:"\201C").
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Sync_Css
 */
final class AB_MCP_Sync_Css {

	/** url(…): its argument, in quotes or without. */
	const URL = '#url\(\s*(?|"([^"]*)"|\'([^\']*)\'|([^)\s"\']*))\s*\)#i';

	/** @import "…" or '…': the string is the address. */
	const IMPORT = '#@import\s*(?|"([^"]*)"|\'([^\']*)\')#i';

	/** image-set(…) to the end of its declaration (its strings whole): each string an address. */
	const IMAGE_SET = '#image-set\(((?:"[^"]*"|\'[^\']*\'|[^;{}"\'])*)#i';

	/** A string of CSS. */
	const STRING = '#(?|"([^"]*)"|\'([^\']*)\')#';

	/** The types of data: whose content is read (images; any other locks). */
	const DATA_IMAGES = array( 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml' );

	/**
	 * Read a text of CSS.
	 *
	 * @param string $css As stored.
	 * @return array{plain:string,decoded:string,comments:int[],urls:array<int,array{url:string,at:int}>,strings:string[],lock:bool,data:string[]}
	 *               plain: without its comments; decoded: plain with its
	 *               escapes decoded; comments: where each stood in plain;
	 *               urls: the addresses and where they stand in the
	 *               stored text; strings: every other string; lock: a
	 *               backslash where it is no part of a selector or a
	 *               property name (escapes()), an address not read to its
	 *               end (a comment or a backslash in it, a url( or an
	 *               @import not read); data: the addresses that are data:
	 *               (for data()).
	 */
	public static function read( $css ) {
		$css      = (string) $css;
		$plain    = '';
		$comments = array();
		$quote    = '';
		$masked   = '';
		$escapes  = array();
		$n        = strlen( $css );
		for ( $i = 0; $i < $n; ) {
			$c = $css[ $i ];
			// An escape: the character after it (a line break, CR LF as one)
			// closes no string and starts no comment.
			if ( '\\' === $c ) {
				$len = "\r\n" === substr( $css, $i + 1, 2 ) ? 3 : 2;
				// Outside of a string: where it stands, escapes() decides.
				if ( '' === $quote ) {
					$escapes[] = strlen( $plain );
				}
				$plain  .= substr( $css, $i, $len );
				$masked .= '' === $quote ? substr( $css, $i, $len ) : str_repeat( '_', strlen( substr( $css, $i, $len ) ) );
				$i      += $len;
				continue;
			}
			if ( '' !== $quote ) {
				if ( $c === $quote || "\n" === $c || "\r" === $c || "\f" === $c ) {
					$quote = '';
				}
			} elseif ( '"' === $c || "'" === $c ) {
				$quote = $c;
			} elseif ( '/' === $c && '*' === substr( $css, $i + 1, 1 ) ) {
				// A comment to its end, or to the end of the text.
				$end        = strpos( $css, '*/', $i + 2 );
				$len        = false === $end ? $n - $i : $end + 2 - $i;
				$comments[] = array( strlen( $plain ), $len );
				$i         += $len;
				continue;
			}
			// The text outside of strings (each character of a string as _).
			$masked .= '' !== $quote && $c !== $quote ? '_' : $c;
			$plain  .= $c;
			++$i;
		}
		$lock = self::escapes( $masked, $escapes );
		$data  = array();
		$found = array();
		if ( preg_match_all( self::URL, $plain, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $all as $m ) {
				$found[] = array( (string) $m[1][0], (int) $m[1][1], (int) $m[0][1], (int) $m[0][1] + strlen( $m[0][0] ) );
			}
		}
		if ( preg_match_all( self::IMPORT, $plain, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $all as $m ) {
				$found[] = array( (string) $m[1][0], (int) $m[1][1], (int) $m[1][1] - 1, (int) $m[1][1] + strlen( $m[1][0] ) + 1 );
			}
		}
		if ( preg_match_all( self::IMAGE_SET, $plain, $sets, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $sets as $set ) {
				if ( preg_match_all( self::STRING, $set[1][0], $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
					foreach ( $all as $m ) {
						$at      = (int) $set[1][1] + (int) $m[0][1];
						$found[] = array( (string) $m[1][0], (int) $set[1][1] + (int) $m[1][1], $at, $at + strlen( $m[0][0] ) );
					}
				}
			}
		}
		// Every url( and every @import read so, else not to its end.
		if ( preg_match_all( '#url\(#i', $plain ) !== preg_match_all( self::URL, $plain ) || preg_match_all( '#@import#i', $plain ) !== preg_match_all( self::IMPORT, $plain ) + preg_match_all( '#@import\s*url\(#i', $plain ) ) {
			$lock = true;
		}
		$urls = array();
		foreach ( $found as $hit ) {
			list( $url, $at, $start, $end ) = $hit;
			foreach ( $comments as $comment ) {
				// A comment inside the address: read without it, never so.
				if ( $comment[0] > $start && $comment[0] < $end ) {
					$lock = true;
				}
				// Where it stands as stored: after the comments before it.
				$at += $comment[0] <= $hit[1] ? $comment[1] : 0;
			}
			if ( false !== strpos( $url, '\\' ) ) {
				$lock = true;
			}
			// As the URL parser reads it: without tabs and line breaks, trimmed.
			if ( 0 === stripos( trim( str_replace( array( "\t", "\n", "\r" ), '', $url ), "\x00..\x20" ), 'data:' ) ) {
				$data[] = $url;
			}
			$urls[ $at ] = array(
				'url' => $url,
				'at'  => $at,
			);
		}
		ksort( $urls );
		// Every other string: read only (a custom property reaches
		// image-set(var(…)) as an address).
		$strings = array();
		if ( preg_match_all( self::STRING, $plain, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			$targets = array_flip( array_column( $found, 1 ) );
			foreach ( $all as $m ) {
				if ( ! isset( $targets[ (int) $m[1][1] ] ) ) {
					$strings[] = (string) $m[1][0];
				}
			}
		}
		return array(
			'plain'    => $plain,
			'decoded'  => self::unescaped( $plain ),
			'comments' => array_column( $comments, 0 ),
			'urls'     => array_values( $urls ),
			'strings'  => $strings,
			'lock'     => $lock,
			'data'     => $data,
		);
	}

	/**
	 * Whether a backslash outside of strings and comments locks: in the name
	 * of a function (an identifier right before "("), of an at-rule, or in
	 * the value of a declaration (after its first ":", up to ";", "{" or
	 * "}"). In a selector (text before "{" that is no at-rule) and in the
	 * name of a property it is free.
	 *
	 * @param string $masked  The text without comments, its strings as _.
	 * @param int[]  $escapes Where a backslash stands outside of strings.
	 * @return bool
	 */
	private static function escapes( $masked, array $escapes ) {
		if ( array() === $escapes ) {
			return false;
		}
		// An escaped name of a function or of an at-rule.
		if ( preg_match_all( '/@?(?:[A-Za-z0-9_\-]|\\\\(?:[0-9a-fA-F]{1,6}\s?|.))+\(|@(?:[A-Za-z0-9_\-]|\\\\(?:[0-9a-fA-F]{1,6}\s?|.))+/s', $masked, $names ) ) {
			foreach ( $names[0] as $name ) {
				if ( false !== strpos( $name, '\\' ) && ( '(' === substr( $name, -1 ) || '@' === $name[0] ) ) {
					return true;
				}
			}
		}
		// In the value of a declaration: a part between {, }, ; that is no
		// prelude (not ended by "{"), after its first ":".
		foreach ( $escapes as $at ) {
			$start = max( strrpos( substr( $masked, 0, $at ), '{' ), strrpos( substr( $masked, 0, $at ), '}' ), strrpos( substr( $masked, 0, $at ), ';' ) );
			$start = false === $start ? 0 : $start + 1;
			$end   = strcspn( $masked, '{};', $at ) + $at;
			$part  = (string) substr( $masked, $start, $end - $start );
			if ( '{' !== substr( $masked, $end, 1 ) && '@' !== substr( ltrim( $part ), 0, 1 ) && $start + (int) strpos( $part, ':' ) < $at ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * What an address data: of CSS holds, as the browser decodes it (the
	 * fetch standard: its type before the first ",", base64 as its last
	 * parameter, the body percent-decoded, then base64, white space
	 * skipped as base64_decode() skips it): only an image of DATA_IMAGES. Null for any other type and
	 * where it does not decode.
	 *
	 * @param string $url The address.
	 * @return array{type:string,content:string}|null
	 */
	public static function data( $url ) {
		$url = trim( str_replace( array( "\t", "\n", "\r" ), '', (string) $url ), "\x00..\x20" );
		if ( 1 !== preg_match( '#^data:([^,]*),(.*)\z#is', $url, $m ) ) {
			return null;
		}
		$params = array_map( 'trim', explode( ';', strtolower( $m[1] ) ) );
		if ( ! in_array( $params[0], self::DATA_IMAGES, true ) ) {
			return null;
		}
		$body = rawurldecode( $m[2] );
		if ( 'base64' === end( $params ) ) {
			$body = base64_decode( $body, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- what the browser decodes, read.
		}
		return false === $body ? null : array(
			'type'    => $params[0],
			'content' => $body,
		);
	}

	/**
	 * A text of CSS with its escapes decoded as CSS decodes them: a
	 * backslash with one to six hex digits (and one white space after
	 * them) the code point (0, a surrogate and beyond U+10FFFF as U+FFFD),
	 * with a line break nothing, with any other character that character.
	 *
	 * @param string $css Text.
	 * @return string
	 */
	public static function unescaped( $css ) {
		if ( false === strpos( (string) $css, '\\' ) ) {
			return (string) $css;
		}
		return (string) preg_replace_callback(
			'/\\\\(?:([0-9a-fA-F]{1,6})(?:\r\n|[ \t\n\r\f])?|(\r\n|[\n\r\f])|(.))/su',
			static function ( $m ) {
				if ( isset( $m[3] ) && '' !== $m[3] ) {
					return $m[3];
				}
				if ( isset( $m[2] ) && '' !== $m[2] ) {
					return '';
				}
				$code = hexdec( $m[1] );
				if ( 0 === $code || ( $code >= 0xD800 && $code <= 0xDFFF ) || $code > 0x10FFFF ) {
					$code = 0xFFFD;
				}
				return mb_chr( (int) $code, 'UTF-8' );
			},
			(string) $css
		);
	}
}
