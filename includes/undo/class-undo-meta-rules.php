<?php
/**
 * The key rules of the meta tools, for the undo of their changes.
 *
 * A restore must never write a key the tool itself would refuse (see
 * AB_MCP_Undo_Restore): a key that is protected since, or a hand-edited
 * undo point naming wp_capabilities, is blocked. The rules live in the tool
 * classes as protected methods — AlphaBridge MCP Pro declares the same ones
 * in its subclasses — so this class inherits them instead of keeping a copy
 * that could drift apart. Abstract: it is never registered and never runs a
 * tool.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/tools/class-tools-meta-auth.php';

/**
 * Class AB_MCP_Undo_Meta_Rules
 */
abstract class AB_MCP_Undo_Meta_Rules extends AB_MCP_Tools_Meta_Auth {

	/**
	 * Would wp_update_user_meta refuse this key outright (roles, sign-in,
	 * credentials)?
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function user_key_refused( $key ) {
		return self::protected_user_meta( (string) $key );
	}

	/**
	 * Would wp_update_post_meta or wp_update_term_meta refuse this key
	 * outright ("_" keys, credential-like keys)?
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function key_refused( $key ) {
		$key = (string) $key;
		return '' === $key || '_' === substr( $key, 0, 1 ) || self::is_sensitive_meta_key( $key );
	}
}
