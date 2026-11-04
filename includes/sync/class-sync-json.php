<?php
/**
 * Stored JSON by its round trip (Codex, round 19): the ids and the
 * addresses in JSON are read only in what json_decode() gives, and
 * rewritten only where the stored text is byte for byte what a producer
 * of the core writes for it; then the data is changed and written again
 * with the same flags. No position in the stored text is mapped to the
 * data: a key twice, an escaped key, any other spelling is no round trip,
 * and every id or address in it locks (AB_MCP_Sync_Refs::json_canon()).
 *
 * The producers (wp-includes 7.1.3):
 * - global_styles: WP_REST_Global_Styles_Controller and
 *   wp_filter_global_styles_post() (kses.php), wp_json_encode() with
 *   JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
 * - wp_json_encode: its default flags;
 * - interactivity: wp_interactivity_data_wp_context() (interactivity-api.php
 *   :104), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
 * - block_attributes: serialize_block_attributes() (blocks.php:1705),
 *   JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE with its replacements.
 * wp_json_encode() is json_encode() where that encodes (functions.php
 * :4443): decoded JSON always does.
 *
 * Decoded as objects, so {} and [] come back as they were.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Sync_Json
 */
final class AB_MCP_Sync_Json {

	/** The producers: the flags of json_encode() each writes with. */
	const PRODUCERS = array(
		'global_styles'    => JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP,
		'wp_json_encode'   => 0,
		'interactivity'    => JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP,
		'block_attributes' => JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
	);

	/** The replacements of serialize_block_attributes() after encoding. */
	const BLOCK_ATTRIBUTES = array(
		'\\\\' => '\\u005c',
		'--'   => '\\u002d\\u002d',
		'<'    => '\\u003c',
		'>'    => '\\u003e',
		'&'    => '\\u0026',
		'\\"'  => '\\u0022',
	);

	/**
	 * A stored text of JSON (an object or a list) and the producer whose
	 * spelling it is byte for byte.
	 *
	 * @param string $raw As stored.
	 * @return array{raw:string,value:object|array,producer:string|null}|false|null
	 *               Null where it is no JSON of an object or a list; false
	 *               where it nests deeper than json_decode() reads.
	 */
	public static function read( $raw ) {
		$raw   = (string) $raw;
		$value = json_decode( $raw, false );
		if ( JSON_ERROR_DEPTH === json_last_error() ) {
			return false;
		}
		if ( ! is_object( $value ) && ! is_array( $value ) ) {
			return null;
		}
		$producer = null;
		foreach ( array_keys( self::PRODUCERS ) as $name ) {
			if ( self::encode( $value, $name ) === $raw ) {
				$producer = $name;
				break;
			}
		}
		return array(
			'raw'      => $raw,
			'value'    => $value,
			'producer' => $producer,
		);
	}

	/**
	 * The spans of the strings of a text of JSON json_decode() reads (each
	 * with its quotes), as a tokenizer of JSON bounds them: from " to the
	 * next " no backslash escapes.
	 *
	 * @param string $raw JSON as stored.
	 * @return array<int,array{0:int,1:int}> Offset and length.
	 */
	public static function string_spans( $raw ) {
		$raw = (string) $raw;
		$out = array();
		$n   = strlen( $raw );
		for ( $i = 0; $i < $n; $i++ ) {
			if ( '"' !== $raw[ $i ] ) {
				continue;
			}
			$start = $i;
			for ( ++$i; $i < $n && '"' !== $raw[ $i ]; $i++ ) {
				$i += '\\' === $raw[ $i ] ? 1 : 0;
			}
			$out[] = array( $start, $i + 1 - $start );
		}
		return $out;
	}

	/**
	 * Data written as a producer writes it.
	 *
	 * @param mixed  $value    Decoded JSON.
	 * @param string $producer Of PRODUCERS.
	 * @return string
	 */
	public static function encode( $value, $producer ) {
		$json = (string) json_encode( $value, self::PRODUCERS[ $producer ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() is json_encode() for decoded JSON; no stored value is read.
		return 'block_attributes' === $producer ? strtr( $json, self::BLOCK_ATTRIBUTES ) : $json;
	}
}
