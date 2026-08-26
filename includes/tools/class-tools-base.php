<?php
/**
 * Shared helpers for tool groups.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Tools_Base
 */
abstract class AB_MCP_Tools_Base {

	/**
	 * String argument.
	 *
	 * @param array  $a   Args.
	 * @param string $k   Key.
	 * @param string $d   Default.
	 * @return string
	 */
	protected static function s( $a, $k, $d = '' ) {
		return isset( $a[ $k ] ) && is_scalar( $a[ $k ] ) ? (string) $a[ $k ] : $d;
	}

	/**
	 * Integer argument.
	 *
	 * @param array  $a Args.
	 * @param string $k Key.
	 * @param int    $d Default.
	 * @return int
	 */
	protected static function i( $a, $k, $d = 0 ) {
		return isset( $a[ $k ] ) ? (int) $a[ $k ] : $d;
	}

	/**
	 * Boolean argument.
	 *
	 * @param array  $a Args.
	 * @param string $k Key.
	 * @param bool   $d Default.
	 * @return bool
	 */
	protected static function b( $a, $k, $d = false ) {
		if ( ! isset( $a[ $k ] ) ) {
			return $d;
		}
		$v = $a[ $k ];
		if ( is_bool( $v ) ) {
			return $v;
		}
		if ( is_string( $v ) ) {
			return in_array( strtolower( $v ), array( '1', 'true', 'yes', 'on' ), true );
		}
		return (bool) $v;
	}

	/**
	 * Array argument.
	 *
	 * @param array  $a Args.
	 * @param string $k Key.
	 * @param array  $d Default.
	 * @return array
	 */
	protected static function arr( $a, $k, $d = array() ) {
		return isset( $a[ $k ] ) && is_array( $a[ $k ] ) ? $a[ $k ] : $d;
	}

	/**
	 * Require keys to be present.
	 *
	 * @param array $a    Args.
	 * @param array $keys Required keys.
	 * @return true|WP_Error
	 */
	protected static function need( $a, array $keys ) {
		$missing = array();
		foreach ( $keys as $k ) {
			if ( ! isset( $a[ $k ] ) || '' === $a[ $k ] ) {
				$missing[] = $k;
			}
		}
		if ( empty( $missing ) ) {
			return true;
		}
		return new WP_Error(
			'ab_mcp_missing_arg',
			sprintf(
				/* translators: %s: comma-separated argument names */
				__( 'Missing required argument(s): %s', 'alphabridge-mcp' ),
				implode( ', ', $missing )
			)
		);
	}

	/**
	 * Clamp an int.
	 *
	 * @param mixed $n   Value.
	 * @param int   $min Min.
	 * @param int   $max Max.
	 * @return int
	 */
	protected static function clamp( $n, $min, $max ) {
		return max( $min, min( $max, (int) $n ) );
	}

	/**
	 * May the current user read terms of this taxonomy? Public taxonomies are
	 * readable (their terms appear on the public site anyway); non-public
	 * taxonomies (internal workflows, shop/membership systems, …) require the
	 * taxonomy's own assign_terms capability.
	 *
	 * @param WP_Taxonomy|false $tax_obj Taxonomy object from get_taxonomy().
	 * @return bool
	 */
	protected static function can_read_taxonomy( $tax_obj ) {
		if ( ! $tax_obj ) {
			return false;
		}
		return ! empty( $tax_obj->public ) || current_user_can( $tax_obj->cap->assign_terms );
	}

	/**
	 * Does a meta key look like a stored credential? Used to keep the meta
	 * tools from reading or writing another plugin's API keys, tokens or
	 * secrets even when such keys are stored under a public (non "_") name.
	 * This is defence-in-depth on top of the "_" prefix and is_protected_meta()
	 * checks, not a replacement for them.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	protected static function is_sensitive_meta_key( $key ) {
		$key = strtolower( (string) $key );
		// High-precision compound credential patterns: these virtually never
		// occur in legitimate content meta, so they avoid false positives on
		// keys like token_count, email_signature, password_hint or
		// credential_type while still catching real API keys and tokens.
		$needles = array(
			'api_key',
			'apikey',
			'api-key',
			'api_token',
			'api_secret',
			'access_key',
			'access_token',
			'secret_key',
			'secret_token',
			'private_key',
			'client_secret',
			'consumer_key',
			'consumer_secret',
			'refresh_token',
			'auth_token',
			'bearer_token',
			'oauth',
			'_token',
			'_secret',
			'_password',
			'passwd',
			'smtp_pass',
		);
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $key, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Compact summary of a post object.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	protected static function post_summary( $post ) {
		return array(
			'id'        => (int) $post->ID,
			'type'      => $post->post_type,
			'status'    => $post->post_status,
			'title'     => get_the_title( $post ),
			'slug'      => $post->post_name,
			'link'      => get_permalink( $post ),
			'date'      => $post->post_date_gmt,
			'modified'  => $post->post_modified_gmt,
			'author'    => (int) $post->post_author,
			'parent'    => (int) $post->post_parent,
			'excerpt'   => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'menu_order'=> (int) $post->menu_order,
		);
	}
}
