<?php
/**
 * The key rules of the meta tools: which key a writer refuses, and which
 * one its reader shows — asked by the readers themselves, by the writers'
 * answers (saved_fields) and by the undo of their changes.
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

	/**
	 * Would the reader of this type refuse to show this key, whoever asks?
	 * wp_get_post_meta and wp_get_term_meta leave out "_" keys, keys
	 * WordPress marks as protected (is_protected_meta(), which a plugin can
	 * filter) and credential-like keys; wp_get_user_meta shows only the
	 * standard profile fields. The right to edit the key comes on top, in
	 * the reader as in the writer. A writer's answer does not show the
	 * stored value of a key its reader would not show.
	 *
	 * @param string $type post, term or user.
	 * @param string $key  Meta key.
	 * @return bool
	 */
	public static function read_refused( $type, $key ) {
		$key = (string) $key;
		if ( 'user' === $type ) {
			return ! in_array( $key, self::allowed_user_meta_keys(), true );
		}
		return '_' === substr( $key, 0, 1 ) || is_protected_meta( $key, (string) $type ) || self::is_sensitive_meta_key( $key );
	}

	/**
	 * May the reader of this type read this object for the current account?
	 * wp_get_post_meta asks read_post, wp_get_term_meta the right to assign
	 * terms of the term's taxonomy, wp_get_user_meta the right to edit the
	 * user. The readers ask it here, so a writer's answer that shows what is
	 * stored asks exactly the same.
	 *
	 * @param string $type post, term or user.
	 * @param int    $id   Object id.
	 * @return bool
	 */
	public static function object_readable( $type, $id ) {
		$id = (int) $id;
		if ( 'post' === $type ) {
			return current_user_can( 'read_post', $id );
		}
		if ( 'term' === $type ) {
			$term    = get_term( $id );
			$tax_obj = ( $term && ! is_wp_error( $term ) ) ? get_taxonomy( $term->taxonomy ) : null;
			return (bool) $tax_obj && current_user_can( $tax_obj->cap->assign_terms );
		}
		if ( 'user' === $type ) {
			return current_user_can( 'edit_user', $id );
		}
		return false;
	}

	/**
	 * Would the reader of this type answer the current account for this
	 * object at all: the capability the reader tool takes (edit_posts for
	 * post and term meta, list_users for user meta) and object_readable()?
	 * Where it would not, a writer's answer shows none of the stored values.
	 *
	 * @param string $type post, term or user.
	 * @param int    $id   Object id.
	 * @return bool
	 */
	public static function reader_answers( $type, $id ) {
		$caps = array(
			'post' => 'edit_posts',
			'term' => 'edit_posts',
			'user' => 'list_users',
		);
		return isset( $caps[ $type ] ) && current_user_can( $caps[ $type ] ) && self::object_readable( $type, $id );
	}
}
