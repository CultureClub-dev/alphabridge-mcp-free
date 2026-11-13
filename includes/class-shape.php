<?php
/**
 * The one reading of a value in an explained form (Z8b branch 3, Behebung 4,
 * Option C1; conventions.md «Entscheidstellen: prüfen statt umwandeln»).
 *
 * read( $value, $form ) gives the value in the form, or null where it is not
 * of it ("unknown"). No cast, no default value, no value made up: the one
 * change allowed is a lossless one after the check (the decimal digits of a
 * whole number a database gives as text, where the form takes them). A form
 * names every property it checks, none is implied:
 *
 * - int:    digits (bool: a text of decimal digits is the number, without
 *           sign, blank, leading zero, fraction or overflow), min, max
 *           (int, or null for none);
 * - number: min, max (an int or a finite float);
 * - string: empty (bool), max_bytes (int|null); optionally in (the values
 *           it may be) or pattern (a regular expression it matches);
 * - bool:   nothing;
 * - list:   empty (bool), item (form of each element), unique (bool: no
 *           element twice), max_items (int|null). A list is an array whose
 *           keys are exactly 0 to n-1 in this order (written out: PHP 8.0
 *           has no array_is_list());
 * - map:    empty (bool), key (form of each key), value (form of each value);
 * - record: fields (name => form; every field is there, its form may add
 *           nullable => true where null is one of its values), extra (bool:
 *           other keys may be there and are given back as they are). A
 *           non-empty list is no record;
 * - json:   codec, depth, value. The text is decoded and written again by
 *           its writer, and is only what that writer wrote byte for byte
 *           (the round trip): an object read as a list, a key twice, a
 *           number or an escape spelt otherwise, a value nested deeper than
 *           depth are no text of it. codec is 'json' (json_decode() and
 *           json_encode(): then flags and object, true where the writer
 *           writes the root as an object, are named too) or the name of a
 *           class with
 *           decode( $text, $depth ) and encode( $value )
 *           (AB_MCP_Sync_Canon). value is the form of what it decodes to.
 *
 * A form that lacks one of its properties reads every value as unknown
 * (fail closed); valid_form() says whether a form is whole, for the tests of
 * the tables that hold forms (AB_MCP_Store_Inputs::TABLE).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Shape
 */
final class AB_MCP_Shape {

	/** The properties each type of form names, every one of them. */
	const PROPERTIES = array(
		'int'    => array( 'digits', 'min', 'max' ),
		'number' => array( 'min', 'max' ),
		'string' => array( 'empty', 'max_bytes' ),
		'bool'   => array(),
		'list'   => array( 'empty', 'item', 'unique', 'max_items' ),
		'map'    => array( 'empty', 'key', 'value' ),
		'record' => array( 'fields', 'extra' ),
		'json'   => array( 'codec', 'depth', 'value' ),
	);

	/** Properties a type may name besides its own. */
	const OPTIONAL = array(
		'string' => array( 'in', 'pattern' ),
		'json'   => array( 'flags', 'object' ),
	);

	/**
	 * The value in the form, or null where it is not of it.
	 *
	 * @param mixed $value Value as read.
	 * @param array $form  Form.
	 * @return mixed|null
	 */
	public static function read( $value, array $form ) {
		$type = array_key_exists( 'type', $form ) ? $form['type'] : null;
		if ( ! is_string( $type ) || ! isset( self::PROPERTIES[ $type ] ) ) {
			return null;
		}
		foreach ( self::PROPERTIES[ $type ] as $property ) {
			if ( ! array_key_exists( $property, $form ) ) {
				return null;
			}
		}
		switch ( $type ) {
			case 'int':
				return self::whole( $value, $form );
			case 'number':
				return ( is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) ) && self::within( $value, $form ) ? $value : null;
			case 'string':
				return self::text( $value, $form );
			case 'bool':
				return is_bool( $value ) ? $value : null;
			case 'list':
				return self::items( $value, $form );
			case 'map':
				return self::pairs( $value, $form );
			case 'record':
				return self::fields( $value, $form );
		}
		return self::decoded( $value, $form );
	}

	/**
	 * Whether a value is a list: an array whose keys are exactly 0 to n-1 in
	 * this order (the empty array is one).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_list( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		$next = 0;
		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $next ) {
				return false;
			}
			++$next;
		}
		return true;
	}

	/**
	 * A field of an array, or null where the value is no array or has no
	 * such key: absent is unknown, never a value.
	 *
	 * @param mixed      $value Value.
	 * @param int|string $key   Key.
	 * @return mixed|null
	 */
	public static function field( $value, $key ) {
		return is_array( $value ) && array_key_exists( $key, $value ) ? $value[ $key ] : null;
	}

	/**
	 * Whether a form names its type, every property of it and nothing else,
	 * each of its kind, the forms within it alike.
	 *
	 * @param mixed $form Form.
	 * @return bool
	 */
	public static function valid_form( $form ) {
		if ( ! is_array( $form ) || ! isset( $form['type'] ) || ! is_string( $form['type'] ) || ! isset( self::PROPERTIES[ $form['type'] ] ) ) {
			return false;
		}
		$type    = $form['type'];
		$allowed = array_merge( array( 'type', 'nullable' ), self::PROPERTIES[ $type ], isset( self::OPTIONAL[ $type ] ) ? self::OPTIONAL[ $type ] : array() );
		foreach ( array_keys( $form ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				return false;
			}
		}
		foreach ( self::PROPERTIES[ $type ] as $property ) {
			if ( ! array_key_exists( $property, $form ) ) {
				return false;
			}
		}
		if ( array_key_exists( 'nullable', $form ) && true !== $form['nullable'] ) {
			return false;
		}
		return self::valid_properties( $form );
	}

	/* ------------------------------------------------------------ internals */

	/**
	 * Whether the properties of a whole form are each of their kind.
	 *
	 * @param array $form Form with every property of its type.
	 * @return bool
	 */
	private static function valid_properties( array $form ) {
		$bound = static function ( $value ) {
			return null === $value || is_int( $value );
		};
		switch ( $form['type'] ) {
			case 'int':
				return is_bool( $form['digits'] ) && $bound( $form['min'] ) && $bound( $form['max'] );
			case 'number':
				return $bound( $form['min'] ) && $bound( $form['max'] );
			case 'string':
				return is_bool( $form['empty'] ) && $bound( $form['max_bytes'] ) && ( ! array_key_exists( 'in', $form ) || ( self::is_list( $form['in'] ) && array() !== $form['in'] ) ) && ( ! array_key_exists( 'pattern', $form ) || ( is_string( $form['pattern'] ) && false !== @preg_match( $form['pattern'], '' ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a pattern that does not compile is no form.
			case 'bool':
				return true;
			case 'list':
				return is_bool( $form['empty'] ) && is_bool( $form['unique'] ) && $bound( $form['max_items'] ) && self::valid_form( $form['item'] ) && ! array_key_exists( 'nullable', $form['item'] );
			case 'map':
				return is_bool( $form['empty'] ) && self::valid_form( $form['key'] ) && in_array( $form['key']['type'], array( 'int', 'string' ), true ) && self::valid_form( $form['value'] ) && ! array_key_exists( 'nullable', $form['value'] );
			case 'record':
				if ( ! is_array( $form['fields'] ) || ! is_bool( $form['extra'] ) ) {
					return false;
				}
				foreach ( $form['fields'] as $name => $field ) {
					if ( ! is_string( $name ) || ! self::valid_form( $field ) ) {
						return false;
					}
				}
				return true;
		}
		// json.
		$plain = 'json' === $form['codec'];
		$codec = $plain || ( is_string( $form['codec'] ) && method_exists( $form['codec'], 'decode' ) && method_exists( $form['codec'], 'encode' ) );
		$own = $plain ? ( array_key_exists( 'flags', $form ) && is_int( $form['flags'] ) && array_key_exists( 'object', $form ) && is_bool( $form['object'] ) ) : ( ! array_key_exists( 'flags', $form ) && ! array_key_exists( 'object', $form ) );
		return $codec && $own && is_int( $form['depth'] ) && $form['depth'] > 0 && self::valid_form( $form['value'] ) && ! array_key_exists( 'nullable', $form['value'] );
	}

	/**
	 * Whether a number lies within min and max of its form.
	 *
	 * @param int|float $value Number.
	 * @param array     $form  Form.
	 * @return bool
	 */
	private static function within( $value, array $form ) {
		return ( null === $form['min'] || $value >= $form['min'] ) && ( null === $form['max'] || $value <= $form['max'] );
	}

	/**
	 * A whole number of its form: an int, or where digits is true the text of
	 * its decimal digits (no sign, blank, leading zero, fraction or overflow:
	 * the number written out again is the text).
	 *
	 * @param mixed $value Value.
	 * @param array $form  Form.
	 * @return int|null
	 */
	private static function whole( $value, array $form ) {
		if ( true === $form['digits'] && is_string( $value ) && 1 === preg_match( '/^(0|[1-9][0-9]*)\z/', $value ) && (string) (int) $value === $value ) {
			$value = (int) $value;
		}
		return is_int( $value ) && self::within( $value, $form ) ? $value : null;
	}

	/**
	 * A text of its form.
	 *
	 * @param mixed $value Value.
	 * @param array $form  Form.
	 * @return string|null
	 */
	private static function text( $value, array $form ) {
		if ( ! is_string( $value ) || ( '' === $value && true !== $form['empty'] ) || ( null !== $form['max_bytes'] && strlen( $value ) > $form['max_bytes'] ) ) {
			return null;
		}
		if ( array_key_exists( 'in', $form ) && ! ( is_array( $form['in'] ) && in_array( $value, $form['in'], true ) ) ) {
			return null;
		}
		if ( array_key_exists( 'pattern', $form ) && ! ( is_string( $form['pattern'] ) && 1 === @preg_match( $form['pattern'], $value ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a pattern that does not compile matches nothing.
			return null;
		}
		return $value;
	}

	/**
	 * A list of its form, each element read in the form of its items.
	 *
	 * @param mixed $value Value.
	 * @param array $form  Form.
	 * @return array|null
	 */
	private static function items( $value, array $form ) {
		if ( ! self::is_list( $value ) || ( array() === $value && true !== $form['empty'] ) || ( null !== $form['max_items'] && count( $value ) > $form['max_items'] ) || ! is_array( $form['item'] ) ) {
			return null;
		}
		$out = array();
		foreach ( $value as $item ) {
			$read = self::read( $item, $form['item'] );
			if ( null === $read || ( true === $form['unique'] && in_array( $read, $out, true ) ) ) {
				return null;
			}
			$out[] = $read;
		}
		return $out;
	}

	/**
	 * A map of its form, each key and value read in their forms; a key read
	 * otherwise than it is (a lossless change) is not of the form.
	 *
	 * @param mixed $value Value.
	 * @param array $form  Form.
	 * @return array|null
	 */
	private static function pairs( $value, array $form ) {
		if ( ! is_array( $value ) || ( array() === $value && true !== $form['empty'] ) || ! is_array( $form['key'] ) || ! is_array( $form['value'] ) ) {
			return null;
		}
		$out = array();
		foreach ( $value as $key => $item ) {
			$read = self::read( $item, $form['value'] );
			if ( self::read( $key, $form['key'] ) !== $key || null === $read ) {
				return null;
			}
			$out[ $key ] = $read;
		}
		return $out;
	}

	/**
	 * A record of its form: every field there and of its form (null where
	 * the field's form is nullable), other keys only where extra is true.
	 *
	 * @param mixed $value Value.
	 * @param array $form  Form.
	 * @return array|null
	 */
	private static function fields( $value, array $form ) {
		if ( ! is_array( $value ) || ( array() !== $value && self::is_list( $value ) ) || ! is_array( $form['fields'] ) ) {
			return null;
		}
		$out = array();
		foreach ( $form['fields'] as $name => $field ) {
			if ( ! array_key_exists( $name, $value ) || ! is_array( $field ) ) {
				return null;
			}
			if ( null === $value[ $name ] && array_key_exists( 'nullable', $field ) && true === $field['nullable'] ) {
				$out[ $name ] = null;
				continue;
			}
			$read = self::read( $value[ $name ], $field );
			if ( null === $read ) {
				return null;
			}
			$out[ $name ] = $read;
		}
		if ( true !== $form['extra'] && array() !== array_diff_key( $value, $form['fields'] ) ) {
			return null;
		}
		return true === $form['extra'] ? array_replace( $value, $out ) : $out;
	}

	/**
	 * What a JSON text decodes to, in the form of its value, where the text
	 * is the one its writer wrote of it (the round trip).
	 *
	 * @param mixed $value Text.
	 * @param array $form  Form.
	 * @return mixed|null
	 */
	private static function decoded( $value, array $form ) {
		if ( ! is_string( $value ) || ! is_int( $form['depth'] ) || $form['depth'] < 1 || ! is_array( $form['value'] ) ) {
			return null;
		}
		if ( 'json' === $form['codec'] ) {
			if ( ! array_key_exists( 'flags', $form ) || ! is_int( $form['flags'] ) || ! array_key_exists( 'object', $form ) || ! is_bool( $form['object'] ) ) {
				return null;
			}
			$decoded = json_decode( $value, true, $form['depth'] );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return null;
			}
			$again = json_encode( $form['object'] && is_array( $decoded ) ? (object) $decoded : $decoded, $form['flags'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the writer's own bytes, not WordPress' re-encoding.
		} else {
			$codec = $form['codec'];
			if ( ! is_string( $codec ) || ! method_exists( $codec, 'decode' ) || ! method_exists( $codec, 'encode' ) ) {
				return null;
			}
			$decoded = $codec::decode( $value, $form['depth'] );
			$again   = $codec::encode( $decoded );
		}
		if ( ! is_string( $again ) || $again !== $value ) {
			return null;
		}
		return self::read( $decoded, $form['value'] );
	}
}
