<?php
/**
 * The check for credentials before anything is compared or packed (Z8,
 * decision 9 of the plan of 01.11.2026): over the unpacked value of the
 * smallest unit of its own (an option, a meta row, a widget instance),
 * recursively — serialized values, nested arrays, JSON in a text, lists of
 * name and value — by the names AB_MCP_Redactor::redacts_key() redacts
 * (normalised: accessToken, client-secret and API_KEY alike).
 *
 * The answer per unit:
 * - b9: the name of the unit is one; the unit is group B9, not compared,
 *   not packed, counted;
 * - mixed: a field inside it is one; the unit locks its object
 *   (*:credential_field), with the paths of those fields (names, never a
 *   value);
 * - unreadable: the value does not unpack (an object, a reference, broken),
 *   so nothing says what it holds; the canonical form locks it already
 *   (*:php_object, *:unreadable_value);
 * - clean.
 * Values are never looked at, only names: nothing here reads or returns
 * what a credential holds.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-redactor.php';
require_once __DIR__ . '/class-sync-canon.php';

/**
 * Class AB_MCP_Sync_Credentials
 */
final class AB_MCP_Sync_Credentials {

	/** At most this many field paths per unit. */
	const MAX_PATHS = 50;

	/** The keys of an item of a list of name and value that hold the name. */
	const NAME_KEYS = array( 'name', 'key' );

	/**
	 * Check one unit.
	 *
	 * @param string        $name  Name of the unit (option name, meta key,
	 *                             widget base).
	 * @param mixed         $value Its value as stored (a text, maybe
	 *                             serialized) or already unpacked.
	 * @param string[]|null $extra The names a site redacts on top
	 *                             (AB_MCP_Redactor::extra_keys() when null).
	 * @return array{state:string,fields:string[]}
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
		$readable = self::walk( $value, '', $extra, $fields, 0, 0 );
		if ( ! $readable ) {
			return array(
				'state'  => 'unreadable',
				'fields' => array(),
			);
		}
		return array(
			'state'  => array() === $fields ? 'clean' : 'mixed',
			'fields' => array_slice( array_values( array_unique( $fields ) ), 0, self::MAX_PATHS ),
		);
	}

	/**
	 * Note the answer of unit() in an accumulator of AB_MCP_Sync_Canon, and
	 * lock the object for a mixed unit.
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
		return true;
	}

	/**
	 * @param mixed    $value  Value.
	 * @param string   $path   Path.
	 * @param string[] $extra  Extra names.
	 * @param string[] $fields Paths of credential fields found.
	 * @param int      $depth  Depth.
	 * @param int      $layers Texts unpacked above.
	 * @return bool Whether all of it could be read.
	 */
	private static function walk( $value, $path, array $extra, array &$fields, $depth, $layers ) {
		if ( $depth > AB_MCP_Sync_Canon::MAX_DEPTH || $layers > AB_MCP_Sync_Canon::MAX_LAYERS ) {
			return false;
		}
		if ( is_string( $value ) ) {
			if ( is_serialized( $value ) ) {
				$read = AB_MCP_Sync_Canon::unserialized( $value );
				return $read['ok'] && self::walk( $read['value'], $path, $extra, $fields, $depth, $layers + 1 );
			}
			$json = self::json( $value );
			if ( false === $json ) {
				return false;
			}
			return null === $json || self::walk( $json, $path, $extra, $fields, $depth, $layers + 1 );
		}
		if ( is_object( $value ) ) {
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
			if ( ! self::walk( $item, $sub, $extra, $fields, $depth + 1, $layers ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A text that is a JSON object or list, decoded; null for any other text;
	 * false for JSON nested deeper than json_decode() reads (it could hide a
	 * name).
	 *
	 * @param string $text Text.
	 * @return array|null|false
	 */
	private static function json( $text ) {
		$trim = ltrim( $text );
		if ( '' === $trim || ( '{' !== $trim[0] && '[' !== $trim[0] ) ) {
			return null;
		}
		$value = json_decode( $text, true, 512 );
		if ( JSON_ERROR_DEPTH === json_last_error() ) {
			return false;
		}
		return is_array( $value ) ? $value : null;
	}
}
