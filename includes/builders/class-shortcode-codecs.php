<?php
/**
 * How shortcode builders encode values inside their shortcodes, decoded for
 * display: pure functions, no WordPress needed, so the readers behave the
 * same in the unit tests and on a site, and Pro can use the same functions
 * when it finds a value it is about to change.
 *
 * Every rule names its source in the measurement folder of the page-builder
 * plan (alphabridge-docs: docs/recherche/2026-09-30-page-builder-messung/
 * quellen/<builder>.md, [n] = source n of that file). None of them was
 * measured at an installation yet; where a source says a form is only
 * derived or open, the comment says so.
 *
 * Deliberately NOT decoded, with the reason:
 * - Enfold turns a straight apostrophe in an attribute into "’" (U+2019),
 *   a pair into "‘…’" (quellen/enfold.md d) [4] l. 2195–2215). The page shows
 *   the stored "’" (WordPress texturizes the rest anyway, [18] l. 871), and
 *   turning it back would also change apostrophes the author typed as "’".
 *   Display keeps the stored character; a search must treat ' and ’ alike.
 * - Avada's "{ }" for "[ ]" is documented only for the button's
 *   "Additional Attributes" field (quellen/avada.md d) [19]), a developer
 *   field the profiles never read; decoding braces elsewhere would break
 *   Avada's Inline Dynamic Data placeholders such as {post_title} ([17]).
 * - WPBakery's "#E-8_" values (base64 of a URL-encoded value, used by the
 *   "safe" parameter types and the map embed, quellen/wpbakery.md d) [42][47])
 *   belong to fields the profile locks; a field that holds one is left out.
 * - Divi 4's "%5c" (quellen/divi-4.md d) [15]: what it stands for is open
 *   in the source, so it stays as stored.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Shortcode_Codecs
 */
final class AB_MCP_Shortcode_Codecs {

	/** Prefix of a WPBakery "safe" value (quellen/wpbakery.md d) [42][47]). */
	const WPBAKERY_SAFE_PREFIX = '#E-8_';

	/**
	 * Start of a Divi 4 Dynamic Content value. The form is prior knowledge in
	 * the source, not documented by the vendor (quellen/divi-4.md d),
	 * "Vorwissen: @ET-DC@<Base64-JSON>@", existence of the format [19]). Used
	 * only to keep such a value from being shown as text: if the form is
	 * different, nothing matches and the value is shown as stored.
	 */
	const DIVI4_DYNAMIC_PREFIX = '@ET-DC@';

	/**
	 * A WPBakery attribute value as the page shows it. WPBakery stores a
	 * double quote as two backticks, "[" as "`{`" and "]" as "`}`", because a
	 * shortcode attribute cannot hold them (quellen/wpbakery.md d), table row
	 * "normale Attribute" and the example below it: `Er sagt ``Hallo`` `{`x`}``
	 * is "Er sagt "Hallo" [x]"). The bracket forms are replaced first: a quote
	 * next to a bracket ("```{`") would otherwise pair the wrong backticks.
	 *
	 * @param string $value Attribute value, as WordPress hands it over.
	 * @return string
	 */
	public static function wpbakery_attr( $value ): string {
		return str_replace( array( '`{`', '`}`', '``' ), array( '[', ']', '"' ), (string) $value );
	}

	/**
	 * Whether a WPBakery value is a "safe" encoded value ("#E-8_" + base64).
	 *
	 * @param string $value Attribute value.
	 * @return bool
	 */
	public static function wpbakery_is_safe_encoded( $value ): bool {
		return 0 === strpos( (string) $value, self::WPBAKERY_SAFE_PREFIX );
	}

	/**
	 * A WPBakery link ("vc_link") split into its parts: "url:…|title:…|
	 * target:…|rel:…", each value rawurlencode()d, empty segments possible
	 * (quellen/wpbakery.md c) "Link-Format" [42][47][49][54], example
	 * `url:https%3A%2F%2Fbeispiel.ch%2Fpreise%2F|title:Preise|target:_blank|rel:nofollow`).
	 * The four keys are always present ('' when missing); unknown keys are
	 * dropped.
	 *
	 * @param string $value Attribute value.
	 * @return array{url:string,title:string,target:string,rel:string}
	 */
	public static function wpbakery_link( $value ): array {
		$out = array(
			'url'    => '',
			'title'  => '',
			'target' => '',
			'rel'    => '',
		);
		foreach ( explode( '|', (string) $value ) as $pair ) {
			$colon = strpos( $pair, ':' );
			if ( false === $colon ) {
				continue;
			}
			$key = substr( $pair, 0, $colon );
			if ( array_key_exists( $key, $out ) ) {
				$out[ $key ] = trim( rawurldecode( (string) substr( $pair, $colon + 1 ) ) );
			}
		}
		return $out;
	}

	/**
	 * A Divi 4 attribute value as the page shows it: "%22" is a double quote,
	 * "%91" "[", "%93" "]" and "%92" a backslash — the sequences Divi's own
	 * conversion of Divi 4 content restores (quellen/divi-4.md d) [15],
	 * restoreSpecialChars(); that Divi 4 stores attributes this way is derived
	 * there, not measured).
	 *
	 * @param string $value Attribute value.
	 * @return string
	 */
	public static function divi4_attr( $value ): string {
		return str_replace( array( '%22', '%91', '%93', '%92' ), array( '"', '[', ']', '\\' ), (string) $value );
	}

	/**
	 * Whether a Divi 4 value is a Dynamic Content reference (see
	 * DIVI4_DYNAMIC_PREFIX).
	 *
	 * @param string $value Attribute value.
	 * @return bool
	 */
	public static function divi4_is_dynamic( $value ): bool {
		return 0 === strpos( trim( (string) $value ), self::DIVI4_DYNAMIC_PREFIX );
	}

	/**
	 * An Enfold link value (quellen/enfold.md c) "Link-Formate" [17][20]):
	 * "manually,<address>" for an own address (mailto: and tel: included),
	 * "lightbox", or "<type>,<id>" for a page, post or category of the site —
	 * which type names Enfold uses is open in the source, so the type is
	 * handed on as stored. "manually,http://" means "no link" ([18] l. 842),
	 * as does an empty value.
	 *
	 * @param string $value Attribute value.
	 * @return array{kind:string,url:string,type:string,id:int}
	 *         kind: 'none', 'url', 'lightbox', 'internal' or 'unknown'.
	 */
	public static function enfold_link( $value ): array {
		$value = trim( (string) $value );
		$out   = array(
			'kind' => 'none',
			'url'  => '',
			'type' => '',
			'id'   => 0,
		);
		if ( '' === $value ) {
			return $out;
		}
		if ( 'lightbox' === $value ) {
			$out['kind'] = 'lightbox';
			return $out;
		}
		if ( 0 === strpos( $value, 'manually,' ) ) {
			$url = trim( substr( $value, strlen( 'manually,' ) ) );
			if ( '' !== $url && 'http://' !== $url ) {
				$out['kind'] = 'url';
				$out['url']  = $url;
			}
			return $out;
		}
		if ( 1 === preg_match( '~^([A-Za-z0-9_-]+),([0-9]+)$~', $value, $m ) ) {
			$out['kind'] = 'internal';
			$out['type'] = $m[1];
			$out['id']   = (int) $m[2];
			return $out;
		}
		$out['kind'] = 'unknown';
		$out['url']  = $value;
		return $out;
	}
}
