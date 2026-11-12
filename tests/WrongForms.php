<?php
/**
 * The wrong forms of an explained form (Z8b branch 3, Behebung 4, Option
 * C1): for a form of AB_MCP_Shape and a value of it, every value that is not
 * of the form, made from the form's own properties (not by asking
 * AB_MCP_Shape::read(), which they test): an object where a list is, a list
 * with a gap or its keys in another order, an empty list where none may be,
 * a nested list, an element twice, the digits of a number where no text is
 * taken and digits with something around them, a fraction, a number out of
 * range, PHP_INT_MAX + 1 from JSON and as text, null, true, false, a missing
 * key; and for a JSON text: broken JSON, a list at the root, a text outside
 * the spelling of its writer, a key twice, nesting past the limit, and each
 * wrong form of what it decodes to as its writer would write it.
 *
 * Used by the tests that go through AB_MCP_Store_Inputs::TABLE row by row
 * (StoreInputsTest, unit and integration). INTEGRATION names the rows the
 * integration suite drives (their consumers need the folder of the store).
 *
 * @package AlphaBridge_MCP
 */

namespace AlphaBridge\Tests;

final class WrongForms {

	/** The rows of AB_MCP_Store_Inputs::TABLE the integration suite drives; the unit suite drives the others. */
	const INTEGRATION = array(
		'sidecar.json',
		'sidecar.format',
		'sidecar.gid',
		'sidecar.time',
		'sidecar.count',
		'sidecar.run',
		'sidecar.label',
		'sidecar.text',
		'sidecar.bool',
		'mark.json',
		'file.mtime',
		'budget.book_run',
		'budget.book_bytes',
		'budget.free',
		'budget.bookings',
		'backup.run',
		'budget.payload',
		'budget.listing',
		'budget.held_names',
		'budget.file_size',
	);

	/**
	 * Every wrong form of a form, by what it is.
	 *
	 * @param array $form  Form.
	 * @param mixed $valid A value of the form (the base the wrong forms are made of).
	 * @return array<string,mixed>
	 */
	public static function of( array $form, $valid ): array {
		switch ( $form['type'] ) {
			case 'int':
				return self::of_int( $form, $valid );
			case 'number':
				return array(
					'a text'       => (string) $valid,
					'null'         => null,
					'true'         => true,
					'a list'       => array( $valid ),
					'infinite'     => INF,
					'not a number' => NAN,
					'negative'     => -1,
				);
			case 'string':
				return self::of_string( $form, $valid );
			case 'bool':
				return array(
					'0'       => 0,
					'1'       => 1,
					'"true"'  => 'true',
					'"false"' => 'false',
					'"1"'     => '1',
					'null'    => null,
					'a list'  => array(),
				);
			case 'list':
				return self::of_list( $form, $valid );
			case 'map':
				return self::of_map( $form, $valid );
			case 'record':
				return self::of_record( $form, $valid );
		}
		return self::of_json( $form, $valid );
	}

	/**
	 * A valid value of a form other than null, made from the form alone, or
	 * null where none is found (a pattern none of the candidates matches):
	 * the base of the wrong forms of a field that is null in the value a
	 * test gives. A list and a map hold one element, so each has a value to
	 * make wrong forms of.
	 *
	 * @param array $form Form.
	 * @return mixed|null
	 */
	public static function example( array $form ) {
		switch ( $form['type'] ) {
			case 'int':
				return null !== $form['min'] ? $form['min'] : ( null !== $form['max'] && $form['max'] < 0 ? $form['max'] : 0 );
			case 'number':
				return null !== $form['min'] ? $form['min'] : 0;
			case 'bool':
				return true;
			case 'string':
				if ( array_key_exists( 'in', $form ) ) {
					return $form['in'][0];
				}
				$candidates = array( 'a', 'A', '0', 'ab', 'a.b' );
				for ( $n = 1; $n <= 256; $n++ ) {
					$candidates[] = str_repeat( 'a', $n );
					$candidates[] = str_repeat( '0', $n );
				}
				foreach ( $candidates as $text ) {
					if ( ( null === $form['max_bytes'] || strlen( $text ) <= $form['max_bytes'] ) && ( ! array_key_exists( 'pattern', $form ) || 1 === preg_match( $form['pattern'], $text ) ) ) {
						return $text;
					}
				}
				return null;
			case 'list':
				$item = self::example( $form['item'] );
				return null === $item ? null : array( $item );
			case 'map':
				$key   = self::example( $form['key'] );
				$value = self::example( $form['value'] );
				return null === $key || null === $value ? null : array( $key => $value );
			case 'record':
				$out = array();
				foreach ( $form['fields'] as $name => $field ) {
					$out[ $name ] = self::example( $field );
					if ( null === $out[ $name ] ) {
						return null;
					}
				}
				return $out;
		}
		$value = self::example( $form['value'] );
		return null === $value ? null : self::encode( $form, $value );
	}

	/**
	 * @param array $form  Form.
	 * @param int   $valid Value.
	 * @return array<string,mixed>
	 */
	private static function of_int( array $form, $valid ): array {
		$out = array(
			'digits with a suffix'   => $valid . 'bad',
			'a blank before'         => ' ' . $valid,
			'a leading zero'         => '0' . $valid,
			'digits as a fraction'   => $valid . '.0',
			'an exponent'            => '1e6',
			'a sign'                 => '+' . $valid,
			'a float'                => (float) $valid,
			'a fraction'             => $valid + 0.5,
			'PHP_INT_MAX+1 (JSON)'   => json_decode( '9223372036854775808' ),
			'PHP_INT_MAX+1 as text'  => '9223372036854775808',
			'null'                   => null,
			'true'                   => true,
			'false'                  => false,
			'an empty text'          => '',
			'a list'                 => array( $valid ),
			'an object'              => array( 'k' => $valid ),
		);
		if ( false === $form['digits'] ) {
			$out['its digits as text'] = (string) $valid;
		}
		if ( null !== $form['min'] ) {
			$out['below its least'] = $form['min'] - 1;
			$out['negative']        = -1 < $form['min'] ? -1 : $form['min'] - 1;
			if ( true === $form['digits'] && $form['min'] > 0 ) {
				$out['digits below its least'] = (string) ( $form['min'] - 1 );
			}
		}
		if ( null !== $form['max'] ) {
			$out['above its most'] = $form['max'] + 1;
			if ( true === $form['digits'] ) {
				$out['digits above its most'] = (string) ( $form['max'] + 1 );
			}
		}
		return $out;
	}

	/**
	 * @param array  $form  Form.
	 * @param string $valid Value.
	 * @return array<string,mixed>
	 */
	private static function of_string( array $form, $valid ): array {
		$out = array(
			'a number'  => 5,
			'a float'   => 1.5,
			'null'      => null,
			'true'      => true,
			'false'     => false,
			'a list'    => array( $valid ),
		);
		if ( false === $form['empty'] ) {
			$out['empty'] = '';
		}
		// Texts the values or the pattern of the form do not hold: of its
		// declared values none, of its pattern those it does not match.
		$texts = array(
			'another text'   => $valid . 'x',
			'a line break'   => $valid . "\n",
			'in other case'  => strtoupper( $valid ) === $valid ? strtolower( $valid ) . 'X' : strtoupper( $valid ),
			'a blank before' => ' ' . $valid,
			'a slash'        => $valid . '/',
			'a hash'         => $valid . '#',
			'too long'       => str_repeat( 'a', 300 ),
		);
		foreach ( $texts as $what => $text ) {
			if ( ( array_key_exists( 'in', $form ) && ! in_array( $text, $form['in'], true ) ) || ( array_key_exists( 'pattern', $form ) && 1 !== preg_match( $form['pattern'], $text ) ) ) {
				$out[ $what ] = $text;
			}
		}
		if ( null !== $form['max_bytes'] ) {
			$out['too long'] = str_repeat( 'a', $form['max_bytes'] + 1 );
		}
		return $out;
	}

	/**
	 * @param array $form  Form.
	 * @param array $valid A list of at least one element.
	 * @return array<string,mixed>
	 */
	private static function of_list( array $form, $valid ): array {
		$item = $valid[0];
		// Two elements where the value has them, so a list of its form that
		// takes no element twice does not refuse the gap for the twice.
		$next = count( $valid ) > 1 ? $valid[1] : $item;
		$out  = array(
			'an object'         => array( 'k' => $item ),
			'a list with a gap' => array(
				0 => $item,
				2 => $next,
			),
			'keys in another order' => array(
				1 => $item,
				0 => $next,
			),
			'a list from key 1' => array( 1 => $item ),
			'a nested list'     => array( array( $item ) ),
			'an element alone'  => $item,
			'null'              => null,
			'a text'            => 'x',
			'true'              => true,
		);
		if ( false === $form['empty'] ) {
			$out['empty'] = array();
		}
		if ( true === $form['unique'] ) {
			$out['an element twice'] = array( $item, $item );
		}
		if ( null !== $form['max_items'] ) {
			$out['one element too many'] = array_fill( 0, $form['max_items'] + 1, $item );
		}
		foreach ( self::of( $form['item'], $item ) as $what => $wrong ) {
			$out[ 'an element: ' . $what ] = array( $wrong );
		}
		return $out;
	}

	/**
	 * @param array $form  Form.
	 * @param array $valid A map of at least one pair.
	 * @return array<string,mixed>
	 */
	private static function of_map( array $form, $valid ): array {
		$key   = array_key_first( $valid );
		$value = $valid[ $key ];
		$out   = array(
			'null'   => null,
			'a text' => 'x',
			'true'   => true,
		);
		if ( false === $form['empty'] ) {
			$out['empty'] = array();
		}
		// Keys PHP keeps as they are (a text of digits would become an int).
		$keys = array( 'k', 'k5', ' 5' );
		if ( 'int' === $form['key']['type'] && null !== $form['key']['min'] ) {
			$keys[] = $form['key']['min'] - 1;
		}
		foreach ( $keys as $wrong ) {
			$out[ 'a key: ' . var_export( $wrong, true ) ] = array( $wrong => $value );
		}
		foreach ( self::of( $form['value'], $value ) as $what => $wrong ) {
			$out[ 'a value: ' . $what ] = array( $key => $wrong );
		}
		return $out;
	}

	/**
	 * @param array $form  Form.
	 * @param array $valid A record of the form.
	 * @return array<string,mixed>
	 */
	private static function of_record( array $form, $valid ): array {
		$out = array(
			'null'   => null,
			'a text' => 'x',
		);
		if ( array() !== $valid ) {
			$out['a list of its values'] = array_values( $valid );
		}
		foreach ( $form['fields'] as $name => $field ) {
			$without = $valid;
			unset( $without[ $name ] );
			$out[ "without $name" ] = $without;
			// A field that may be null and is null in the value: its wrong
			// forms from a valid value besides null (example()), so no field
			// goes without its wrong values (Behebung 5, B2: they were all
			// left out, the hash of a tree of a mark among them).
			$base = array_key_exists( $name, $valid ) ? $valid[ $name ] : null;
			if ( null === $base && array_key_exists( 'nullable', $field ) ) {
				$base = self::example( $field );
			}
			if ( null === $base ) {
				continue;
			}
			foreach ( self::of( $field, $base ) as $what => $wrong ) {
				if ( null === $wrong && array_key_exists( 'nullable', $field ) ) {
					continue;
				}
				$out[ "$name: $what" ] = array_replace( $valid, array( $name => $wrong ) );
			}
		}
		if ( false === $form['extra'] ) {
			$out['a key more'] = $valid + array( 'more' => 1 );
		}
		return $out;
	}

	/**
	 * @param array  $form  Form.
	 * @param string $valid A text of the form.
	 * @return array<string,mixed>
	 */
	private static function of_json( array $form, $valid ): array {
		$decoded = self::decode( $form, $valid );
		$out     = array(
			'no text: null'          => null,
			'no text: a number'      => 5,
			'no text: an array'      => array(),
			'broken JSON'            => substr( $valid, 0, -1 ),
			'a list at the root'     => '[1]',
			'a blank after it'       => $valid . ' ',
			'a blank before it'      => ' ' . $valid,
			'nested past the limit'  => str_repeat( '[', $form['depth'] + 1 ) . str_repeat( ']', $form['depth'] + 1 ),
			'the JSON null'          => 'null',
		);
		if ( '{' === $valid[0] && preg_match( '/^\{\s*("(?:[^"\\\\]|\\\\.)*"\s*:\s*(?:"(?:[^"\\\\]|\\\\.)*"|[^,{}\[\]"]+|\[[^\[\]]*\]))/', $valid, $first ) ) {
			$out['its first key twice'] = '{' . $first[1] . ',' . substr( $valid, 1 );
		}
		if ( false !== strpos( $valid, '/' ) ) {
			$out['a slash escaped'] = str_replace( '/', '\\/', $valid );
		}
		if ( 'json' === $form['codec'] ) {
			$other = json_encode( $form['object'] && is_array( $decoded ) ? (object) $decoded : $decoded, $form['flags'] ^ JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			if ( is_string( $other ) && $other !== $valid ) {
				$out['spelt with other flags'] = $other;
			}
		} elseif ( is_array( $decoded ) && count( $decoded ) > 1 ) {
			$out['its keys in another order'] = json_encode( array_reverse( $decoded, true ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		}
		// A list written as an object: of its keys 0..n-1, of another key.
		if ( 'record' === $form['value']['type'] && is_array( $decoded ) ) {
			foreach ( $form['value']['fields'] as $name => $field ) {
				if ( 'list' === $field['type'] && is_array( $decoded[ $name ] ) && array() !== $decoded[ $name ] ) {
					$out[ "$name an object of its keys" ] = self::write_raw( $form, array_replace( $decoded, array( $name => (object) $decoded[ $name ] ) ) );
					$out[ "$name an object of a key" ]    = self::write_raw( $form, array_replace( $decoded, array( $name => (object) array( 'k' => $decoded[ $name ][0] ) ) ) );
				}
			}
		}
		foreach ( self::of( $form['value'], $decoded ) as $what => $wrong ) {
			$text = self::encode( $form, $wrong );
			if ( is_string( $text ) && $text !== $valid ) {
				$out[ 'as its writer writes it: ' . $what ] = $text;
			}
		}
		return $out;
	}

	/**
	 * What a valid text decodes to.
	 *
	 * @param array  $form Form.
	 * @param string $text Text.
	 * @return mixed
	 */
	private static function decode( array $form, $text ) {
		if ( 'json' === $form['codec'] ) {
			return json_decode( $text, true );
		}
		$codec = $form['codec'];
		return $codec::decode( $text, $form['depth'] );
	}

	/**
	 * A value as the writer of the form writes it, or null where it cannot.
	 *
	 * @param array $form  Form.
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private static function encode( array $form, $value ) {
		if ( 'json' === $form['codec'] ) {
			$text = json_encode( $form['object'] && is_array( $value ) ? (object) $value : $value, $form['flags'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			return is_string( $text ) ? $text : null;
		}
		$codec = $form['codec'];
		$text  = $codec::encode( $value );
		return '' === $text ? null : $text;
	}

	/**
	 * A value with objects in it, written as the writer of the form would
	 * write its keys (sorted for AB_MCP_Sync_Canon), so only the object
	 * differs.
	 *
	 * @param array $form  Form.
	 * @param array $value Value.
	 * @return string
	 */
	private static function write_raw( array $form, array $value ) {
		if ( 'json' === $form['codec'] ) {
			return (string) json_encode( $value, $form['flags'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		}
		ksort( $value, SORT_STRING );
		return (string) json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}
