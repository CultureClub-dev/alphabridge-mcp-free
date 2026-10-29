<?php
/**
 * How a value is written back into a shortcode builder's page: the inverse of
 * the free core's AB_MCP_Shortcode_Codecs (which decodes for display), as pure
 * functions, so the shortcode writer and its tests use the same rules.
 *
 * The free core decides how a page reads; a value written here is only right
 * when the free reader reads it back as the value that was meant. decode()
 * therefore reads with the free reader's own functions, and the writer
 * refuses any value whose encoding does not survive that round trip.
 *
 * Rules and their sources (alphabridge-docs: docs/recherche/2026-09-30-page-
 * builder-messung/quellen/<builder>.md; none measured at an installation):
 * - WordPress (every builder): an attribute value cannot hold its own quote
 *   character or "]" (it ends the tag), backslashes are read through
 *   stripcslashes(), and a value with a "<" that opens no complete tag is
 *   dropped whole (shortcode_parse_atts(), wp-includes/shortcodes.php).
 * - WPBakery: '"' as two back-ticks, "[" as "`{`", "]" as "`}`"
 *   (wpbakery.md d)); a link ("vc_link") as "url:…|title:…|…" with every
 *   value rawurlencode()d (wpbakery.md c)).
 * - Divi 4: '"' %22, "[" %91, "]" %93, "\" %92 (divi-4.md d), derived there).
 * - Enfold: attributes in single quotes; the builder turns a pair '…' into
 *   ‘…’ and any other ' into ’ (enfold.md d) [4] l. 2195–2215); a link of its
 *   own as "manually,<address>" (enfold.md c)).
 * - Builders without a documented encoding (Avada, Flatsome, third-party
 *   profiles): what WordPress cannot carry is written as an HTML character
 *   reference (&quot; &#91; &#93; &#92; &lt; &gt;). Builders print these
 *   values into HTML, where a reference shows as its character, and
 *   esc_html()/esc_attr() do not encode a reference twice. Derived, not
 *   measured. Avada's "{ }" for "[ ]" is documented only for the button's
 *   "Additional Attributes" field, which no profile reads or writes; the free
 *   reader does not decode braces (they are Avada's placeholders), so they
 *   are not written either.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Shortcode_Encoder
 */
final class AB_MCP_Shortcode_Encoder {

	/** WordPress' test for an attribute value without unclosed HTML (shortcode_parse_atts(), WordPress 7.0.2). */
	const BALANCED = '/^[^<]*+(?:<[^>]*+>[^<]*+)*+$/';

	/** A character reference or entity, which a bare "&" in HTML text must not be taken for. */
	const ENTITY = '/&(?!(?:[A-Za-z][A-Za-z0-9]*|#[0-9]+|#[xX][0-9A-Fa-f]+);)/';

	/** The start of a tag in text: what a plain-text field must not carry as markup. */
	const TAG_START = '~<[A-Za-z/!?]~';

	/**
	 * A value as it is stored inside an attribute in $quote quotes.
	 *
	 * @param string $value Value as the page should show it (decoded).
	 * @param string $codec Profile codec: 'wpbakery', 'divi4' or ''.
	 * @param string $quote '"' or "'".
	 * @return string
	 */
	public static function attr( $value, $codec, $quote ) {
		$out = (string) $value;
		if ( 'divi4' === $codec ) {
			$out = str_replace( array( '\\', '"', '[', ']' ), array( '%92', '%22', '%91', '%93' ), $out );
		} else {
			// A backslash would be read through stripcslashes(); a reference
			// shows the character itself.
			$out = str_replace( '\\', '&#92;', $out );
			if ( 'wpbakery' === $codec ) {
				$out = str_replace( array( '"', '[', ']' ), array( '``', '`{`', '`}`' ), $out );
			} else {
				$out = str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), $out );
			}
		}
		if ( "'" === $quote ) {
			$out = self::apostrophes( $out );
		} elseif ( '' === $codec ) {
			$out = str_replace( '"', '&quot;', $out );
		}
		// WordPress turns these into plain spaces when it reads an attribute.
		$out = str_replace( array( "\u{00A0}", "\u{200B}" ), array( '&nbsp;', '&#8203;' ), $out );
		if ( false !== strpos( $out, '<' ) && 1 !== preg_match( self::BALANCED, $out ) ) {
			$out = str_replace( array( '<', '>' ), array( '&lt;', '&gt;' ), $out );
		}
		return $out;
	}

	/**
	 * What the free reader makes of a raw attribute value: WordPress' parse,
	 * then the profile's codec. Null when WordPress would not read it as one
	 * value in these quotes.
	 *
	 * @param string $raw   Raw value as stored between the quotes.
	 * @param string $codec Profile codec.
	 * @param string $quote '"' or "'".
	 * @return string|null
	 */
	public static function decode( $raw, $codec, $quote ) {
		$raw = (string) $raw;
		if ( false !== strpos( $raw, ']' ) || false !== strpos( $raw, $quote ) ) {
			return null;
		}
		$parsed = AB_MCP_Shortcode_Reader::parse_atts( ' x=' . $quote . $raw . $quote );
		if ( ! isset( $parsed['attrs']['x'], $parsed['spans']['x'] ) || 1 !== count( $parsed['attrs'] ) || array( 3 + 1, 3 + 1 + strlen( $raw ) ) !== $parsed['spans']['x'] ) {
			return null;
		}
		$value = (string) $parsed['attrs']['x'];
		if ( 'wpbakery' === $codec ) {
			return AB_MCP_Shortcode_Codecs::wpbakery_attr( $value );
		}
		if ( 'divi4' === $codec ) {
			return AB_MCP_Shortcode_Codecs::divi4_attr( $value );
		}
		return $value;
	}

	/**
	 * Whether a stored value shows the text that was meant: the same after
	 * character references are resolved, with Enfold's apostrophes counted as
	 * what the builder itself would have stored.
	 *
	 * @param string|null $decoded decode() of the stored value.
	 * @param string      $value   Value meant.
	 * @param string      $quote   Quote of the attribute.
	 * @return bool
	 */
	public static function shows( $decoded, $value, $quote ) {
		if ( null === $decoded ) {
			return false;
		}
		$meant = "'" === $quote ? self::apostrophes( (string) $value ) : (string) $value;
		return self::plain( $decoded ) === self::plain( $meant );
	}

	/**
	 * Enfold's apostrophes: a pair '…' becomes ‘…’, every other ' a ’ — what
	 * its builder does to every attribute so a value cannot end the
	 * single-quoted attribute (enfold.md d) [4] l. 2195–2215). A pair is read
	 * as quotation marks only where it stands like them (after a space or an
	 * opening bracket, before a space or punctuation), so "Peter's" keeps its
	 * apostrophe.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function apostrophes( $text ) {
		$paired = preg_replace( "/(^|[\\s(\\[{\"“„«])'([^'\\s][^']*?)'(?=$|[\\s.,;:!?)\\]}\"”»])/u", "\$1\u{2018}\$2\u{2019}", (string) $text );
		// Not valid UTF-8: no pairs, every apostrophe as ’.
		return str_replace( "'", "\u{2019}", null === $paired ? (string) $text : $paired );
	}

	/**
	 * A value as it is stored between a shortcode's tags, where the builder
	 * keeps HTML: an html field as given (a bare "&" written as "&amp;", as
	 * the editor stores it and as kses would), a plain-text field with its
	 * "&", "<" and ">" as references — unless it carries markup it already had
	 * (AB_MCP_Builder_Fields lets a heading keep its own tags).
	 *
	 * @param string $value Value.
	 * @param string $kind  Field kind.
	 * @return string
	 */
	public static function content( $value, $kind ) {
		$value = (string) $value;
		if ( 'html' === $kind || 1 === preg_match( self::TAG_START, $value ) ) {
			return (string) preg_replace( self::ENTITY, '&amp;', $value );
		}
		return htmlspecialchars( $value, ENT_NOQUOTES, 'UTF-8', false );
	}

	/**
	 * A WPBakery link with a new address: the url part replaced (or put in
	 * front), every other part kept byte for byte.
	 *
	 * @param string $raw Stored link value ('' when there is none).
	 * @param string $url New address ('' for none).
	 * @return string
	 */
	public static function vc_link( $raw, $url ) {
		$parts = '' === (string) $raw ? array() : explode( '|', (string) $raw );
		$new   = 'url:' . rawurlencode( (string) $url );
		foreach ( $parts as $i => $part ) {
			if ( 0 === strpos( $part, 'url:' ) ) {
				$parts[ $i ] = $new;
				return implode( '|', $parts );
			}
		}
		array_unshift( $parts, $new );
		return implode( '|', $parts );
	}

	/**
	 * An Enfold link to an address of its own ('' for no link).
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function enfold_link( $url ) {
		return '' === (string) $url ? '' : 'manually,' . $url;
	}

	/**
	 * Every place a text appears in stored HTML: as written, and with "&",
	 * "<" and ">" as the editor stores them (the search for «A & B» has to
	 * find «A &amp; B»). Longest first, so one pass takes each place once.
	 *
	 * @param string $text Text searched for.
	 * @return string[] Forms, keyed by the same form of the replacement's key: 'plain' or 'html'.
	 */
	public static function html_forms( $text ) {
		$forms = array( 'plain' => (string) $text );
		$html  = htmlspecialchars( (string) $text, ENT_NOQUOTES, 'UTF-8', false );
		if ( $html !== $forms['plain'] ) {
			$forms['html'] = $html;
		}
		uasort(
			$forms,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		return $forms;
	}

	/**
	 * Text with character references resolved, for comparing what two
	 * stored forms show.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function plain( $text ) {
		return html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
