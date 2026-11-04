<?php
/**
 * The check for credentials before anything is compared or packed (Z8,
 * decision 9 of the plan of 01.11.2026): over the unpacked value of the
 * smallest unit of its own (an option, a meta row, a widget instance, a
 * column of a row), recursively — serialized values, nested arrays, JSON in
 * a text (a JSON string literal too), lists of name and value, and in a
 * text that is none of these the attributes of its blocks and shortcodes
 * (AB_MCP_Sync_Refs::embedded(): WP_Block_Parser, shortcode_parse_atts(),
 * named and by position) — by the names AB_MCP_Redactor::redacts_key()
 * redacts (normalised: accessToken, client-secret and API_KEY alike).
 *
 * Besides the structure, flat() reads every text of a unit as it is
 * stored and as the decodings the core knows give it (stripcslashes() of
 * shortcode_parse_atts(), HTML character references as WP_HTML_Decoder
 * decodes them, percent escapes, \uXXXX of JSON): every key in the
 * spelling of an attribute or a field (name=, "name":, 'name':) that
 * redacts_key() names makes the unit mixed, in a shortcode no registry
 * runs, an escaped [[…]], a tag, a comment alike. The tags are read by the
 * HTML API of the core (WP_HTML_Tag_Processor), nothing rebuilt.
 * JavaScript locks the unit (*:script: no parser of the core reads it, it
 * is taken over by hand): every script but one of JSON on a short list, an
 * event attribute, a javascript: address. Every other tag and attribute
 * must be on the list of the core ($allowedposttags of kses.php, and iframe
 * for embeds), else the unit locks (*:html_outside_kses: srcdoc, SVG,
 * embed, base …). A script of JSON is read as JSON, its fields as any
 * structure. In style, a style attribute and in a value of an
 * attribute of a block or a shortcode with "{" or "=>" name: counts as
 * well; the name or id of a field of a form (input, textarea, select,
 * option, button, meta), with a value or without. Where
 * a text holds a tag the HTML API cannot read (the unit suite, an
 * unfinished tag) the unit is unreadable. More locks than needed are
 * allowed; a credential overlooked because the structure was read
 * otherwise than the core reads it is not.
 *
 * The answer per unit:
 * - b9: the name of the unit is one; the unit is group B9, not compared,
 *   not packed, counted;
 * - mixed: a field inside it is one; the unit locks its object
 *   (*:credential_field), with the paths of those fields (names, never a
 *   value);
 * - unreadable: the value does not unpack (an object, a reference, broken,
 *   nested deeper than MAX_DEPTH or MAX_LAYERS), so nothing says what it
 *   holds; it locks its object (*:php_object, *:unreadable_value): what is
 *   not read to its end is never clean; or it holds a script of JavaScript
 *   (*:script);
 * - clean.
 * Values are never looked at, only names: nothing here reads or returns
 * what a credential holds.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-redactor.php';
require_once __DIR__ . '/class-sync-canon.php';
require_once __DIR__ . '/class-sync-refs.php';

/**
 * Class AB_MCP_Sync_Credentials
 */
final class AB_MCP_Sync_Credentials {

	/** At most this many field paths per unit. */
	const MAX_PATHS = 50;

	/** The keys of an item of a list of name and value that hold the name. */
	const NAME_KEYS = array( 'name', 'key' );

	/**
	 * The spellings of a key in a text, independent of its structure: the
	 * name of an attribute, a shortcode attribute, a query (name=), of a
	 * field of JSON or of a script ("name":, 'name':).
	 */
	const FLAT_KEYS = array(
		'/([A-Za-z0-9_\x80-\xFF][A-Za-z0-9_\-.:\x80-\xFF]*)\s*=/',
		'/"([^"\\\\]{1,256})"\s*:/',
		"/'([^'\\\\]{1,256})'\s*:/",
	);

	/**
	 * A name without quotes before ":" (a property of CSS, an object in the
	 * value of an attribute of a block or a shortcode that looks like code):
	 * in prose "Passwort: …" is none.
	 */
	const CODE_KEY = '/([A-Za-z_$][A-Za-z0-9_$\-]*)\s*:/';


	/**
	 * What makes the value of an attribute of a block or a shortcode code: a
	 * brace or "=>". Parentheses and colons alone are prose.
	 */
	const LOOKS_LIKE_CODE = '/[{]|=>/';

	/**
	 * The attributes of iframe beside the list of kses (which has no iframe):
	 * the ones of an ordinary embed (a video of a platform), without srcdoc.
	 */
	const IFRAME = array( 'src', 'width', 'height', 'title', 'allow', 'allowfullscreen', 'frameborder', 'loading', 'referrerpolicy', 'name', 'class', 'id', 'style' );

	/**
	 * The tags read here beside the list of kses, with their attributes: a
	 * script of JSON (AB_MCP_Sync_Html::is_data_script(), read as JSON) and
	 * style (its CSS read as code, its addresses as prose).
	 */
	const READ_TAGS = array(
		'script' => array( 'type', 'id', 'class' ),
		'style'  => array( 'media', 'id', 'class' ),
	);

	/** The tags of a form whose name or id names its field. */
	const FORM_TAGS = array( 'INPUT', 'TEXTAREA', 'SELECT', 'OPTION', 'BUTTON', 'META' );

	/**
	 * Check one unit.
	 *
	 * @param string        $name  Name of the unit (option name, meta key,
	 *                             widget base).
	 * @param mixed         $value Its value as stored (a text, maybe
	 *                             serialized) or already unpacked.
	 * @param string[]|null $extra The names a site redacts on top
	 *                             (AB_MCP_Redactor::extra_keys() when null).
	 * @param bool          $css   Whether the value is CSS (custom_css).
	 * @return array{state:string,fields:string[],why?:string} why, for an
	 *               unreadable unit: php_object or unreadable_value.
	 */
	public static function unit( $name, $value, $extra = null, $css = false ) {
		$extra = is_array( $extra ) ? $extra : AB_MCP_Redactor::extra_keys();
		if ( self::names_credential( (string) $name, $extra ) ) {
			return array(
				'state'  => 'b9',
				'fields' => array(),
			);
		}
		$fields   = array();
		$why      = 'unreadable_value';
		$readable = self::walk( $value, '', $extra, $fields, 0, 0, $why, false, $css );
		if ( ! $readable ) {
			return array(
				'state'  => 'unreadable',
				'fields' => array(),
				'why'    => $why,
			);
		}
		$fields = self::merged( $fields );
		return array(
			'state'  => array() === $fields ? 'clean' : 'mixed',
			'fields' => array_slice( array_values( array_unique( $fields ) ), 0, self::MAX_PATHS ),
		);
	}

	/**
	 * The keys flat() finds in a text: the names redacts_key() names, in the
	 * text as stored and as do_blocks() joins it (without its block
	 * delimiters), each with the decodings of decodings(): in the spellings
	 * of FLAT_KEYS; in code (the text itself where it is the value of an
	 * attribute of a block or a shortcode and looks like code,
	 * LOOKS_LIKE_CODE; else what html() reads as code) a name without
	 * quotes before ":" as well (CODE_KEY); the name or id of a field of a
	 * form (html()), with a value or without.
	 *
	 * @param string   $text  Text as stored.
	 * @param string[] $extra Extra names.
	 * @param bool     $code  Whether the text is code (an attribute value).
	 * @param string   $why   Set to script where the text holds a script of
	 *                        JavaScript, else to unreadable_value or
	 *                        php_object where it returns null.
	 * @param bool     $css   Whether the text is CSS (read as style).
	 * @return string[]|null Null where the text could not be joined, its
	 *                       tags could not be read (html()), or it holds a
	 *                       script of JavaScript.
	 */
	public static function flat( $text, array $extra, $code = false, &$why = null, $css = false ) {
		$why  = 'unreadable_value';
		$text = (string) $text;
		// Without these no decoding gives a key its = or :, and no tag (a
		// script) stands in it.
		if ( ! $css && false === strpbrk( $text, '=:%&\\<' ) ) {
			return array();
		}
		$texts = array( $text );
		if ( false !== strpos( $text, '<!--' ) ) {
			$joined = AB_MCP_Sync_Refs::joined( $text );
			if ( null === $joined ) {
				return null;
			}
			$texts[] = $joined['text'];
		}
		$found = array();
		$code  = $code && preg_match( self::LOOKS_LIKE_CODE, $text );
		foreach ( $texts as $one ) {
			$variants = self::decodings( $one );
			if ( null === $variants ) {
				return null;
			}
			foreach ( $variants as $variant ) {
				foreach ( self::FLAT_KEYS as $pattern ) {
					if ( preg_match_all( $pattern, $variant, $m ) ) {
						$found = array_merge( $found, $m[1] );
					}
				}
			}
			// The tags as the browser parses the stored text, once.
			$html = self::html( $one );
			if ( null === $html ) {
				return null;
			}
			// JavaScript no parser reads: a script of it locks the unit.
			if ( $html['script'] ) {
				$why = 'script';
				return null;
			}
			// A tag or an attribute outside the list of the core locks it.
			if ( $html['outside'] ) {
				$why = 'html_outside_kses';
				return null;
			}
			// A script of JSON is read as JSON, as any structure (walk(): keys,
			// lists of name and value, nested, within MAX_DEPTH); so is the
			// value of an attribute that is JSON of an array or an object.
			foreach ( array_merge( $html['json'], $html['values'] ) as $i => $json ) {
				$value = json_decode( $json, true );
				if ( $i >= count( $html['json'] ) && ! is_array( $value ) ) {
					continue;
				}
				if ( JSON_ERROR_NONE !== json_last_error() ) {
					return null;
				}
				$fields = array();
				if ( ! self::walk( $value, '', $extra, $fields, 0, 1, $why ) ) {
					return null;
				}
				foreach ( $fields as $field ) {
					$found[] = ltrim( (string) $field, '~' );
				}
			}
			foreach ( array_merge( $code || $css ? array( $one ) : array(), $html['code'] ) as $segment ) {
				$variants = self::decodings( $segment );
				if ( null === $variants ) {
					return null;
				}
				// CSS reads its escapes (\2d, \-): the name as CSS reads it.
				$variants[] = AB_MCP_Sync_Urls::css_unescaped( $segment );
				foreach ( $variants as $variant ) {
					if ( preg_match_all( self::CODE_KEY, $variant, $m ) ) {
						$found = array_merge( $found, $m[1] );
					}
				}
			}
			$found = array_merge( $found, $html['fields'] );
		}
		$out = array();
		foreach ( $found as $key ) {
			if ( self::names_credential( (string) $key, $extra ) ) {
				$out[ strtolower( (string) $key ) ] = (string) $key;
			}
		}
		return array_values( $out );
	}

	/**
	 * What the HTML API of the core reads in a text: whether it holds
	 * JavaScript, which no parser of the core reads (a script not on the
	 * list of AB_MCP_Sync_Html::is_data_script(), an event attribute on…, a
	 * javascript: address): the unit locks, *:script; the content of a
	 * script of JSON (read as JSON); the content of style and the value of
	 * every style attribute (code); whether a tag or an attribute stands
	 * outside the list of the core (the global $allowedposttags of kses.php,
	 * iframe with IFRAME): the unit locks, *:html_outside_kses; the names of
	 * its fields of a form (name and id of FORM_TAGS, with a value or
	 * without). Null where the
	 * text holds a tag and the HTML API is not there or cannot read it to
	 * its end: what is not read is never clean.
	 *
	 * @param string $text Text.
	 * @return array{code:string[],json:string[],values:string[],fields:string[],script:bool,outside:bool}|null
	 */
	private static function html( $text ) {
		global $allowedposttags;
		$out = array(
			'code'    => array(),
			'json'    => array(),
			'values'  => array(),
			'fields'  => array(),
			'script'  => false,
			'outside' => false,
		);
		if ( ! preg_match( '/<[A-Za-z]/', $text ) ) {
			return $out;
		}
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return null;
		}
		require_once __DIR__ . '/class-sync-html.php';
		$tags = AB_MCP_Sync_Html::tag_list( $text );
		if ( null === $tags ) {
			return null;
		}
		foreach ( $tags as $tag ) {
			// Data in JSON on the list, every other script runs.
			if ( 'SCRIPT' === $tag['tag'] ) {
				if ( AB_MCP_Sync_Html::is_data_script( $tag['attrs'] ) ) {
					$out['json'][] = (string) $tag['text'];
				} else {
					$out['script'] = true;
				}
			}
			if ( 'STYLE' === $tag['tag'] ) {
				$out['code'][] = (string) $tag['text'];
			}
			// The tags and attributes of the list of the core, the global
			// $allowedposttags of kses.php as it stands (no filter is asked:
			// a filter may read options), iframe with IFRAME, READ_TAGS.
			$tag_name = strtolower( $tag['tag'] );
			$allowed  = is_array( $allowedposttags ) && isset( $allowedposttags[ $tag_name ] ) && is_array( $allowedposttags[ $tag_name ] ) ? $allowedposttags[ $tag_name ] : null;
			if ( 'iframe' === $tag_name ) {
				$allowed = array_fill_keys( self::IFRAME, true );
			} elseif ( isset( self::READ_TAGS[ $tag_name ] ) ) {
				$allowed = array_fill_keys( self::READ_TAGS[ $tag_name ], true );
			}
			if ( null === $allowed ) {
				$out['outside'] = true;
			}
			foreach ( $tag['attrs'] as $name => $value ) {
				if ( null !== $allowed && ! self::kses_attribute( $allowed, (string) $name ) ) {
					$out['outside'] = true;
				}
				if ( ! is_string( $value ) ) {
					continue;
				}
				// The CSS of a style attribute as that of style.
				if ( 'style' === $name ) {
					$out['code'][] = $value;
				}
				// A value that may be JSON (data-config='[…]'), read as JSON.
				if ( preg_match( '/^\s*[\[{]/', $value ) ) {
					$out['values'][] = $value;
				}
				// An embedded document (data:), and object with data, whose
				// rule of the core (_wp_kses_allow_pdf_objects()) reads the
				// uploads through options: outside.
				$url = trim( str_replace( array( "\t", "\n", "\r" ), '', $value ), "\x00..\x20" );
				if ( ( in_array( $name, AB_MCP_Sync_Urls::URL_ATTRIBUTES, true ) && 0 === stripos( $url, 'data:' ) ) || ( 'object' === $tag_name && 'data' === $name ) ) {
					$out['outside'] = true;
				}
				// An event attribute and a javascript: address (as the URL
				// parser reads it: without tabs and line breaks, C0 and spaces
				// trimmed) are JavaScript as well.
				if ( 0 === strpos( $name, 'on' ) || 0 === stripos( $url, 'javascript:' ) ) {
					$out['script'] = true;
				}
				if ( ( 'name' === $name || 'id' === $name ) && in_array( $tag['tag'], self::FORM_TAGS, true ) ) {
					$out['fields'][] = $value;
				}
			}
		}
		return $out;
	}

	/**
	 * Whether a name names a credential (redacts_key()): the whole name or
	 * any of its parts at "." (api_key.v1, v1.token; a path of fields too).
	 *
	 * @param string   $name  Name.
	 * @param string[] $extra Extra names.
	 * @return bool
	 */
	public static function names_credential( $name, array $extra ) {
		foreach ( array_merge( array( (string) $name ), explode( '.', (string) $name ) ) as $part ) {
			if ( AB_MCP_Redactor::redacts_key( ltrim( $part, '~' ), $extra ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether kses lets an attribute of a tag through, by its name as
	 * wp_kses_attr_check() reads it: listed (not ''), or data-… where the tag
	 * takes data-* (wp-includes/kses.php:1628-1629, its pattern
	 * /^data-[a-z0-9_-]+$/ on the name in lower case).
	 *
	 * @param array  $allowed The attributes of the tag.
	 * @param string $name    The attribute, in lower case.
	 * @return bool
	 */
	private static function kses_attribute( array $allowed, $name ) {
		if ( isset( $allowed[ $name ] ) && '' !== $allowed[ $name ] && false !== $allowed[ $name ] ) {
			return true;
		}
		return 0 === strpos( $name, 'data-' ) && ! empty( $allowed['data-*'] ) && 1 === preg_match( '/^data-[a-z0-9_-]+$/', $name );
	}

	/**
	 * A text as stored and as each decoding the core knows gives it; null
	 * where it holds a character reference and WP_HTML_Decoder of the core
	 * is not there to decode it.
	 *
	 * @param string $text Text.
	 * @return string[]|null
	 */
	private static function decodings( $text ) {
		if ( false !== strpos( $text, '&' ) && ! class_exists( 'WP_HTML_Decoder' ) ) {
			return null;
		}
		$out = array(
			$text,
			stripcslashes( $text ),
			// As the HTML parser of the core decodes (references without ";" too).
			false === strpos( $text, '&' ) ? $text : WP_HTML_Decoder::decode_attribute( $text ),
			rawurldecode( $text ),
			(string) preg_replace_callback(
				'/\\\\u([0-9a-fA-F]{4})/',
				static function ( $m ) {
					$code = hexdec( $m[1] );
					return $code < 0x80 ? chr( $code ) : $m[0];
				},
				$text
			),
		);
		return array_values( array_unique( $out ) );
	}

	/**
	 * The fields of the structure and the keys flat() found beside them:
	 * a flat key the structure named already is not named twice.
	 *
	 * @param string[] $fields Paths; flat keys as …~key.
	 * @return string[]
	 */
	private static function merged( array $fields ) {
		$named = array();
		foreach ( $fields as $field ) {
			if ( false === strpos( $field, '~' ) ) {
				$parts                               = preg_split( '/[.\]]/', $field );
				$named[ strtolower( (string) end( $parts ) ) ] = true;
			}
		}
		$out = array();
		foreach ( $fields as $field ) {
			$at = strrpos( $field, '~' );
			if ( false !== $at && isset( $named[ strtolower( substr( $field, $at + 1 ) ) ] ) ) {
				continue;
			}
			$out[] = $field;
		}
		return $out;
	}

	/**
	 * The check of a column of a row: a column named like a credential
	 * (names_credential(), as a field) that holds a value locks its object
	 * (post_password: a post protected by a password, *:password_protected;
	 * else *:credential_field), its value not read further; every other
	 * column is a unit (unit(), its CSS as CSS where $css).
	 *
	 * @param array  $acc    Accumulator.
	 * @param string $kind   Kind.
	 * @param string $column Column.
	 * @param mixed  $value  As stored.
	 * @param bool   $css    Whether the column holds CSS.
	 * @return void
	 */
	public static function column( array &$acc, $kind, $column, $value, $css = false ) {
		if ( '' !== (string) $value && self::names_credential( (string) $column, AB_MCP_Redactor::extra_keys() ) ) {
			$acc['credentials']['mixed'][] = array(
				'unit'   => (string) $column,
				'fields' => array( (string) $column ),
			);
			AB_MCP_Sync_Canon::lock( $acc, $kind, 'post_password' === $column ? 'password_protected' : 'credential_field', $column );
			return;
		}
		self::note( $acc, $kind, $column, self::unit( '', (string) $value, null, $css ) );
	}

	/**
	 * Note the answer of unit() in an accumulator of AB_MCP_Sync_Canon, and
	 * lock the object for a mixed unit and for one that could not be read to
	 * its end (fail closed: what is not read is never clean).
	 *
	 * @param array  $acc   Accumulator.
	 * @param string $kind  Kind.
	 * @param string $at    The unit (such as meta:_my_key).
	 * @param array  $check Answer of unit().
	 * @return bool Whether the unit stays in the canonical form (not b9).
	 */
	public static function note( array &$acc, $kind, $at, array $check ) {
		if ( 'b9' === $check['state'] ) {
			$acc['credentials']['b9'][] = (string) $at;
			return false;
		}
		if ( 'mixed' === $check['state'] ) {
			$acc['credentials']['mixed'][] = array(
				'unit'   => (string) $at,
				'fields' => $check['fields'],
			);
			AB_MCP_Sync_Canon::lock( $acc, $kind, 'credential_field', $at );
		}
		if ( 'unreadable' === $check['state'] ) {
			AB_MCP_Sync_Canon::lock( $acc, $kind, isset( $check['why'] ) ? $check['why'] : 'unreadable_value', $at );
		}
		return true;
	}

	/**
	 * @param mixed    $value  Value.
	 * @param string   $path   Path.
	 * @param string[] $extra  Extra names.
	 * @param string[] $fields Paths of credential fields found.
	 * @param int      $depth  Depth.
	 * @param int      $layers Texts unpacked above.
	 * @param string   $why    Set to php_object or unreadable_value when
	 *                         it could not be read.
	 * @param bool     $code   Inside the attributes of a block or a
	 *                         shortcode: its texts are code (flat()).
	 * @param bool     $css    The text is CSS (custom_css, the value of a
	 *                         key css, as global styles store it).
	 * @return bool Whether all of it could be read.
	 */
	private static function walk( $value, $path, array $extra, array &$fields, $depth, $layers, &$why, $code = false, $css = false ) {
		if ( $depth > AB_MCP_Sync_Canon::MAX_DEPTH || $layers > AB_MCP_Sync_Canon::MAX_LAYERS ) {
			$why = 'unreadable_value';
			return false;
		}
		if ( is_string( $value ) ) {
			$flat = self::flat( $value, $extra, $code, $why, $css );
			if ( null === $flat ) {
				return false;
			}
			foreach ( $flat as $key ) {
				$fields[] = ( '' === $path ? '' : $path . '.' ) . '~' . $key;
			}
			if ( is_serialized( $value ) ) {
				$read = AB_MCP_Sync_Canon::unserialized( $value );
				if ( ! $read['ok'] ) {
					$why = $read['why'];
					return false;
				}
				return self::walk( $read['value'], $path, $extra, $fields, $depth, $layers + 1, $why, $code );
			}
			$json = self::json( $value );
			if ( false === $json ) {
				$why = 'unreadable_value';
				return false;
			}
			if ( null !== $json ) {
				return self::walk( $json, $path, $extra, $fields, $depth, $layers + 1, $why, $code );
			}
			// The fields code reads from the text: blocks and shortcodes.
			$inner = AB_MCP_Sync_Refs::embedded( $value );
			if ( $inner['deep'] ) {
				$why = 'unreadable_value';
				return false;
			}
			foreach ( $inner['items'] as $item ) {
				if ( ! self::walk( $item['value'], ( '' === $path ? '' : $path . '.' ) . '[' . $item['at'] . ']', $extra, $fields, $depth + 1, $layers + 1, $why, true ) ) {
					return false;
				}
			}
			return true;
		}
		if ( is_object( $value ) ) {
			$why = 'php_object';
			return false;
		}
		if ( ! is_array( $value ) ) {
			return true;
		}
		// A list of name and value: the name is the field.
		foreach ( self::NAME_KEYS as $key ) {
			if ( isset( $value[ $key ] ) && is_string( $value[ $key ] ) && array_key_exists( 'value', $value ) && self::names_credential( $value[ $key ], $extra ) ) {
				$fields[] = ( '' === $path ? '' : $path . '.' ) . $value[ $key ];
			}
		}
		foreach ( $value as $key => $item ) {
			$sub = '' === $path ? (string) $key : $path . '.' . $key;
			if ( is_string( $key ) && self::names_credential( $key, $extra ) ) {
				$fields[] = $sub;
				continue;
			}
			if ( ! self::walk( $item, $sub, $extra, $fields, $depth + 1, $layers, $why, $code, 'css' === $key ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A text that is JSON, decoded (AB_MCP_Sync_Canon::json_text()); the walk
	 * goes on in what it returns, within MAX_DEPTH and MAX_LAYERS.
	 *
	 * @param string $text Text.
	 * @return array|string|null|false
	 */
	public static function json( $text ) {
		return AB_MCP_Sync_Canon::json_text( $text );
	}
}
