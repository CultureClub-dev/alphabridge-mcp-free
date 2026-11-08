<?php
/**
 * The option rows the kinds of the structure of a site write (Z8 branch 11,
 * decisions 23 and 24 of the plan of 01.11.2026): a core option as one
 * object, and the rows several objects share, each object a field of the
 * row (widget_<base> and sidebars_widgets for the widget areas,
 * nav_menu_locations in theme_mods_<stylesheet> for the menus).
 *
 * READ from the row (AB_MCP_Sync_Rows::option(), past the caches of the
 * request), unpacked without objects (AB_MCP_Sync_Canon::unserialized()). A
 * field is compared by the bytes PHP stores it with (serialize() of its
 * value, field()): the bytes of a row that update_option() writes again
 * after a field of it changed are serialize() of the whole, so every other
 * field keeps its bytes where its value did not change.
 *
 * WRITTEN BACK on the way back raw, byte for byte conditional (decision 23,
 * put_back()): the row only where it holds right now the very bytes read
 * before (CAST AS BINARY and OCTET_LENGTH in the WHERE), then the caches of
 * the options (alloptions, notoptions, the row) emptied.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-rows.php';
require_once __DIR__ . '/class-sync-canon.php';

/**
 * Class AB_MCP_Sync_Shared_Row
 */
final class AB_MCP_Sync_Shared_Row {

	/**
	 * A row as stored: false where there is none, null where it could not be
	 * read; else raw (the bytes), ok (whether it unpacks without an object)
	 * and value (what it unpacks to; null where it does not).
	 *
	 * @param string $option Option name.
	 * @return array{raw:string,ok:bool,value:mixed}|false|null
	 */
	public static function read( $option ) {
		$row = AB_MCP_Sync_Rows::option( (string) $option );
		if ( ! is_array( $row ) ) {
			return $row;
		}
		$read = AB_MCP_Sync_Canon::unserialized( (string) $row['value'] );
		return array(
			'raw'   => (string) $row['value'],
			'ok'    => (bool) $read['ok'],
			'value' => $read['ok'] ? $read['value'] : null,
		);
	}

	/**
	 * The bytes of one field as PHP stores it in a row.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function field( $value ) {
		return serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the form maybe_serialize() stores an array with.
	}

	/**
	 * The hash of the bytes of a part; of none (a field not there) its own.
	 *
	 * @param string|null $bytes The bytes, null for none.
	 * @return string
	 */
	public static function hash( $bytes ) {
		return null === $bytes ? hash( 'sha256', 'absent' ) : hash( 'sha256', 'field:' . (string) $bytes );
	}

	/**
	 * Write a row back raw, byte for byte conditional (decision 23): only
	 * where it holds right now the bytes $now; the caches of the options
	 * emptied either way.
	 *
	 * @param string $option Option name.
	 * @param string $now    The bytes it has to hold now.
	 * @param string $bytes  The bytes to write.
	 * @return bool Whether exactly that row was written.
	 */
	public static function put_back( $option, $now, $bytes ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- byte for byte conditional, the point of the way back.
		$done = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST( option_value AS BINARY ) = CAST( %s AS BINARY ) AND OCTET_LENGTH( option_value ) = %d", (string) $bytes, (string) $option, (string) $now, strlen( (string) $now ) ) );
		self::forget( $option );
		return 1 === (int) $done;
	}

	/**
	 * The caches of an option emptied (decision 33: alloptions, notoptions
	 * and the row of an option not loaded with the others).
	 *
	 * @param string $option Option name.
	 */
	public static function forget( $option ) {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( (string) $option, 'options' );
	}
}
