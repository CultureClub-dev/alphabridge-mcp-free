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
 *   in the stored text;
 * - what locks in any context: a backslash outside of strings and
 *   comments (an escape in a name, a function or an at-rule: u\72l(,
 *   image-s\65t(, @imp\6Frt), a comment inside such an address, a
 *   backslash in it, a url( or an @import not read so;
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
	 * @return array{plain:string,decoded:string,comments:int[],urls:array<int,array{url:string,at:int}>,lock:bool,data:string[]}
	 *               plain: without its comments; decoded: plain with its
	 *               escapes decoded; comments: where each stood in plain;
	 *               urls: the addresses and where they stand in the
	 *               stored text; lock: a backslash outside of strings, an
	 *               address not read to its end (a comment or a backslash
	 *               in it, a url( or an @import not read); data: the
	 *               addresses that are data: (for data()).
	 */
	public static function read( $css ) {
		$css      = (string) $css;
		$plain    = '';
		$comments = array();
		$quote    = '';
		$lock     = false;
		$n        = strlen( $css );
		for ( $i = 0; $i < $n; ) {
			$c = $css[ $i ];
			// An escape: the character after it (a line break, CR LF as one)
			// closes no string and starts no comment.
			if ( '\\' === $c ) {
				// Outside of a string: an escape in a name, a function, an
				// at-rule (u\72l(, @imp\6Frt) locks.
				$lock   = $lock || '' === $quote;
				$len    = "\r\n" === substr( $css, $i + 1, 2 ) ? 3 : 2;
				$plain .= substr( $css, $i, $len );
				$i     += $len;
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
			$plain .= $c;
			++$i;
		}
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
		return array(
			'plain'    => $plain,
			'decoded'  => self::unescaped( $plain ),
			'comments' => array_column( $comments, 0 ),
			'urls'     => array_values( $urls ),
			'lock'     => $lock,
			'data'     => $data,
		);
	}

	/**
	 * What an address data: of CSS holds, as the browser decodes it (the
	 * fetch standard: its type before the first ",", base64 as its last
	 * parameter, the body percent-decoded, then base64, white space
	 * skipped as base64_decode() skips it): only an image of DATA_IMAGES. Null for any other type and
	 * where it does not decode.
	 *
	 * @param string $url The address.
	 * @return string|null
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
		if ( 'base64' !== end( $params ) ) {
			return $body;
		}
		$bytes = base64_decode( $body, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- what the browser decodes, read.
		return false === $bytes ? null : $bytes;
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
