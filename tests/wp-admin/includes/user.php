<?php
/**
 * wp-admin/includes/user.php for the tests: wp_delete_user loads it with
 * require_once from ABSPATH, which is the tests folder, and wp_create_user
 * and wp_update_user where get_editable_roles() is not there yet. The
 * accounts are those of tests/users-stubs.php.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

if ( ! function_exists( 'get_editable_roles' ) ) {
	/** What the editable_roles filter leaves: $GLOBALS['ab_test_editable'], or every role of $GLOBALS['ab_test_roles']. */
	function get_editable_roles() {
		if ( is_array( $GLOBALS['ab_test_editable'] ?? null ) ) {
			return $GLOBALS['ab_test_editable'];
		}
		$out = array();
		foreach ( array_keys( $GLOBALS['ab_test_roles'] ?? array() ) as $slug ) {
			$out[ $slug ] = array( 'name' => ucfirst( (string) $slug ) );
		}
		return $out;
	}
}

if ( ! function_exists( 'wp_delete_user' ) ) {
	/**
	 * As WordPress: the content and the hooks first (the long part, where the
	 * connection can be lost and another request run whole,
	 * $GLOBALS['ab_test_during_cleanup']), then the account goes; on
	 * multisite it only leaves this site and loses its roles here. Then the
	 * cache, then the deleted_user hooks ($GLOBALS['ab_test_deleted_hook_throws']).
	 * $GLOBALS['ab_test_delete_ok'] false: WordPress answers false.
	 */
	function wp_delete_user( $id, $reassign = null ) {
		$id                            = (int) $id;
		$GLOBALS['ab_test_reassigned'] = $reassign;
		if ( is_callable( $GLOBALS['ab_test_during_cleanup'] ?? null ) ) {
			$call                              = $GLOBALS['ab_test_during_cleanup'];
			$GLOBALS['ab_test_during_cleanup'] = null;
			ab_test_renew_connection();
			ab_test_other_request( $call );
		}
		if ( empty( $GLOBALS['ab_test_delete_ok'] ) ) {
			return false;
		}
		$GLOBALS['ab_test_deleted_users'][] = $id;
		if ( is_multisite() ) {
			$GLOBALS['ab_test_gone'][ $id ]    = true;
			$GLOBALS['ab_test_user_db'][ $id ] = array();
		} else {
			unset( $GLOBALS['ab_test_user_db'][ $id ] );
		}
		clean_user_cache( $id );
		if ( ! empty( $GLOBALS['ab_test_deleted_hook_throws'] ) ) {
			throw new RuntimeException( 'deleted_user hook failed' );
		}
		return true;
	}
}
