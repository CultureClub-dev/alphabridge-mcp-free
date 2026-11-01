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
 * shortcode_parse_atts(), HTML character references, percent escapes,
 * \uXXXX of JSON): every key in the spelling of an attribute or a field
 * (name=, "name":, 'name':) that redacts_key() names makes the unit mixed,
 * in a shortcode no registry runs, an escaped [[…]], a tag, a comment
 * alike. More locks than needed are allowed; a credential overlooked
 * because the structure was read otherwise than the core reads it is not.
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
 *   not read to its end is never clean;
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
	 * Check one unit.
	 *
	 * @param string        $name  Name of the unit (option name, meta key,
	 *                             widget base).
	 * @param mixed         $value Its value as stored (a text, maybe
	 *                             serialized) or already unpacked.
	 * @param string[]|null $extra The names a site redacts on top
	 *                             (AB_MCP_Redactor::extra_keys() when null).
	 * @return array{state:string,fields:string[],why?:string} why, for an
	 *               unreadable unit: php_object or unreadable_value.
	 */
	public static function unit( $name, $value, $extra = null ) {
		$extra = is_array( $extra ) ? $extra : AB_MCP_Redactor::extra_keys();
		if ( AB_MCP_Redactor::redacts_key( (string) $name, $extra ) ) {
			return array(
				'state'  => 'b9',
				'fields' => array(),
			);
		}
		$fields   = array();
		$why      = 'unreadable_value';
		$readable = self::walk( $value, '', $extra, $fields, 0, 0, $why );
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
	 * The keys flat() finds in a text: the names redacts_key() names.
	 *
	 * @param string   $text  Text as stored.
	 * @param string[] $extra Extra names.
	 * @return string[]
	 */
	public static function flat( $text, array $extra ) {
		$text = (string) $text;
		// Without these no decoding gives a key its = or :.
		if ( false === strpbrk( $text, '=:%&\\' ) ) {
			return array();
		}
		$out = array();
		foreach ( self::decodings( $text ) as $variant ) {
			foreach ( self::FLAT_KEYS as $pattern ) {
				if ( preg_match_all( $pattern, $variant, $m ) ) {
					foreach ( $m[1] as $key ) {
						if ( AB_MCP_Redactor::redacts_key( (string) $key, $extra ) ) {
							$out[ strtolower( (string) $key ) ] = (string) $key;
						}
					}
				}
			}
		}
		return array_values( $out );
	}

	/**
	 * A text as stored and as each decoding the core knows gives it.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function decodings( $text ) {
		$out = array(
			$text,
			stripcslashes( $text ),
			html_entity_decode( $text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8' ),
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
	 * @return bool Whether all of it could be read.
	 */
	private static function walk( $value, $path, array $extra, array &$fields, $depth, $layers, &$why ) {
		if ( $depth > AB_MCP_Sync_Canon::MAX_DEPTH || $layers > AB_MCP_Sync_Canon::MAX_LAYERS ) {
			$why = 'unreadable_value';
			return false;
		}
		if ( is_string( $value ) ) {
			foreach ( self::flat( $value, $extra ) as $key ) {
				$fields[] = ( '' === $path ? '' : $path . '.' ) . '~' . $key;
			}
			if ( is_serialized( $value ) ) {
				$read = AB_MCP_Sync_Canon::unserialized( $value );
				if ( ! $read['ok'] ) {
					$why = $read['why'];
					return false;
				}
				return self::walk( $read['value'], $path, $extra, $fields, $depth, $layers + 1, $why );
			}
			$json = self::json( $value );
			if ( false === $json ) {
				$why = 'unreadable_value';
				return false;
			}
			if ( null !== $json ) {
				return self::walk( $json, $path, $extra, $fields, $depth, $layers + 1, $why );
			}
			// The fields code reads from the text: blocks and shortcodes.
			$inner = AB_MCP_Sync_Refs::embedded( $value );
			if ( $inner['deep'] ) {
				$why = 'unreadable_value';
				return false;
			}
			foreach ( $inner['items'] as $item ) {
				if ( ! self::walk( $item['value'], ( '' === $path ? '' : $path . '.' ) . '[' . $item['at'] . ']', $extra, $fields, $depth + 1, $layers + 1, $why ) ) {
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
			if ( isset( $value[ $key ] ) && is_string( $value[ $key ] ) && array_key_exists( 'value', $value ) && AB_MCP_Redactor::redacts_key( $value[ $key ], $extra ) ) {
				$fields[] = ( '' === $path ? '' : $path . '.' ) . $value[ $key ];
			}
		}
		foreach ( $value as $key => $item ) {
			$sub = '' === $path ? (string) $key : $path . '.' . $key;
			if ( is_string( $key ) && AB_MCP_Redactor::redacts_key( $key, $extra ) ) {
				$fields[] = $sub;
				continue;
			}
			if ( ! self::walk( $item, $sub, $extra, $fields, $depth + 1, $layers, $why ) ) {
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
