<?php
/**
 * The kses guard of wp_search_replace: would WordPress store a post field
 * differently from the replacement, for an account without unfiltered_html?
 *
 * For such an account wp_update_post() runs the whole field through kses
 * (kses_init_filters(): content_save_pre and excerpt_save_pre with
 * wp_filter_post_kses(), title_save_pre with wp_filter_kses()), and kses
 * changes more than the replaced text. Measured with WordPress 7.0.2's own
 * kses on the block pages of the page-builder measurement (test fixtures
 * test/fixtures/kses/): the hover style of GenerateBlocks ("&:is(" becomes
 * "&amp;:is(" and moves), the SVG icons of Spectra, the <style> of
 * Stackable, the <script> of a Pagelayer embed, a data: image and every "&"
 * or "<x>" inside a block attribute are lost or changed. Elsewhere kses only
 * writes the same page in other bytes: "/>" as " />", block attributes
 * serialised anew ("--" as \u002d\u002d), "&" in text as "&amp;".
 *
 * So the guard compares meaning, not bytes: the value and what kses makes
 * of it are both read as a list of tokens — block comments with their
 * attributes decoded, tags with their attributes decoded, text decoded,
 * other comments — and only a difference in that list counts. A post whose
 * replacement would be stored with such a difference is skipped, with the
 * reason. Measuring against the stored value of the replacement (not against
 * the old value) is deliberate: what counts is what the page will hold.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Kses_Guard
 */
final class AB_MCP_Kses_Guard {

	/** A block comment, as WordPress' block parser reads one (without the attribute pattern, which is decoded below). */
	const BLOCK = '~^<!--\s+(/)?wp:([a-z][a-z0-9_-]*/)?([a-z][a-z0-9_-]*)\s+(\{.*\}\s+)?(/)?-->$~s';

	/**
	 * Whether the guard applies to the current account: kses runs on post
	 * fields only for an account without unfiltered_html.
	 *
	 * @return bool
	 */
	public static function applies() {
		return ! current_user_can( 'unfiltered_html' ) && function_exists( 'wp_kses' );
	}

	/**
	 * What kses makes of a post field for this account, as wp_update_post()
	 * would store it (unslashed).
	 *
	 * @param string $value  Value as it would be written.
	 * @param string $column post_content, post_excerpt or post_title.
	 * @return string
	 */
	public static function filtered( $value, $column ) {
		// wp_filter_kses() and wp_filter_post_kses() call wp_kses() with these
		// contexts (wp-includes/kses.php): the title with the filter's name,
		// content and excerpt with 'post'.
		return 'post_title' === $column ? wp_kses( (string) $value, 'title_save_pre' ) : wp_kses( (string) $value, 'post' );
	}

	/**
	 * Why kses would store this field differently from the value, or null
	 * when it keeps its meaning.
	 *
	 * @param string $value  Value as it would be written.
	 * @param string $column post_content, post_excerpt or post_title.
	 * @return string|null Plain text, e.g. "a tag svg"; null when unchanged.
	 */
	public static function difference( $value, $column ) {
		$value = (string) $value;
		$kept  = self::filtered( $value, $column );
		if ( $kept === $value ) {
			return null;
		}
		$a = self::tokens( $value );
		$b = self::tokens( $kept );
		if ( $a === $b ) {
			return null;
		}
		$n = max( count( $a ), count( $b ) );
		for ( $i = 0; $i < $n; $i++ ) {
			$was = isset( $a[ $i ] ) ? $a[ $i ] : null;
			if ( ! isset( $b[ $i ] ) || $was !== $b[ $i ] ) {
				return self::describe( null !== $was ? $was : (string) $b[ $i ] );
			}
		}
		return 'the content';
	}

	/**
	 * The value read for its meaning: one string per token. Pure.
	 *
	 * @param string $html Value.
	 * @return string
	 */
	public static function canonical( $html ) {
		return implode( "\n", self::tokens( $html ) );
	}

	/**
	 * Tokens of a value: B block comment (name and attributes, decoded and
	 * encoded again), C other comment, E tag (name, attributes decoded, no
	 * self-closing slash), T text (decoded). Pure.
	 *
	 * @param string $html Value.
	 * @return string[]
	 */
	public static function tokens( $html ) {
		$html = (string) $html;
		$out  = array();
		$pos  = 0;
		// Comments first, then anything that starts like a tag — the split
		// kses itself makes (wp_kses_split()).
		if ( preg_match_all( '~<!--.*?(?:-->|$)|<(?=/?[a-zA-Z])[^>]*(?:>|$)~s', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $hit ) {
				$tok   = $hit[0];
				$start = (int) $hit[1];
				if ( $start > $pos ) {
					$out[] = 'T' . self::decode( substr( $html, $pos, $start - $pos ) );
				}
				$out[] = 0 === strpos( $tok, '<!--' ) ? self::comment( $tok ) : self::tag( $tok );
				$pos   = $start + strlen( $tok );
			}
		}
		if ( $pos < strlen( $html ) ) {
			$out[] = 'T' . self::decode( substr( $html, $pos ) );
		}
		return $out;
	}

	/**
	 * Text as the browser reads it.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function decode( $text ) {
		return html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * A comment token: a block comment by name and decoded attributes (kses
	 * serialises them anew, "core/" is left out as serialize_block() does);
	 * another comment by its text, runs of dashes as one (kses does so).
	 *
	 * @param string $tok Comment.
	 * @return string
	 */
	private static function comment( $tok ) {
		if ( preg_match( self::BLOCK, $tok, $b ) ) {
			$ns    = isset( $b[2] ) && 'core/' !== $b[2] ? $b[2] : '';
			$attrs = '';
			if ( isset( $b[4] ) && '' !== trim( $b[4] ) ) {
				$decoded = json_decode( trim( $b[4] ), true );
				$attrs   = null === $decoded ? 'raw:' . trim( $b[4] ) : (string) json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- a comparison form, not output.
			}
			return 'B' . $b[1] . $ns . $b[3] . ' ' . $attrs . ( isset( $b[5] ) ? $b[5] : '' );
		}
		return 'C' . preg_replace( '/-{2,}/', '-', substr( $tok, 4, '-->' === substr( $tok, -3 ) ? -3 : strlen( $tok ) ) );
	}

	/**
	 * A tag token: closer, name in lower case, attributes in their order with
	 * decoded values, without the self-closing slash and the spacing.
	 *
	 * @param string $tok Tag.
	 * @return string
	 */
	private static function tag( $tok ) {
		if ( ! preg_match( '~^<(/?)([a-zA-Z][^\s/>]*)(.*?)/?\s*>?$~s', $tok, $t ) ) {
			return 'X' . $tok;
		}
		$attrs = array();
		preg_match_all( '~([^\s=/>"\']+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?~', $t[3], $am, PREG_SET_ORDER );
		foreach ( $am as $a ) {
			$value = '';
			foreach ( array( 2, 3, 4 ) as $i ) {
				if ( isset( $a[ $i ] ) && '' !== $a[ $i ] ) {
					$value = $a[ $i ];
				}
			}
			$attrs[] = strtolower( $a[1] ) . '=' . json_encode( self::decode( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- a comparison form, not output.
		}
		return 'E' . $t[1] . strtolower( $t[2] ) . ( array() !== $attrs ? ' ' . implode( ' ', $attrs ) : '' );
	}

	/**
	 * A token in words, for the reason in the answer (no content).
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function describe( $token ) {
		$kind = substr( (string) $token, 0, 1 );
		$rest = (string) substr( (string) $token, 1 );
		if ( 'B' === $kind ) {
			$name = (string) strtok( ltrim( $rest, '/' ), ' ' );
			/* translators: %s: block name */
			return sprintf( __( 'the attributes of the block %s', 'alphabridge-mcp' ), false === strpos( $name, '/' ) ? 'core/' . $name : $name );
		}
		if ( 'E' === $kind ) {
			$name = (string) strtok( ltrim( $rest, '/' ), ' ' );
			/* translators: %s: HTML tag name */
			return sprintf( __( 'a %s tag', 'alphabridge-mcp' ), $name );
		}
		if ( 'C' === $kind ) {
			return __( 'an HTML comment', 'alphabridge-mcp' );
		}
		return __( 'text', 'alphabridge-mcp' );
	}
}
