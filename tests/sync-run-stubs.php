<?php
/**
 * What the unit tests of the run of the takeover (Z8 branch 8:
 * AB_MCP_Sync_Guard, AB_MCP_Sync_Post_Writer, AB_MCP_Sync_Push) need beyond
 * the bootstrap and the stubs of the takeover: the metadata API by object
 * and by meta id over $ab_test_meta, sanitize_post() as a pass-through that
 * a test may override through a filter, taxonomy_exists(), get_term_by(),
 * clean_term_cache() and get_gmt_from_date(). Each function only where
 * nothing defined it before, as the other stub files.
 *
 * @package AlphaBridge_MCP
 */

require_once __DIR__ . '/sync-stubs.php';

if ( ! function_exists( 'get_metadata_by_mid' ) ) {
	/** A meta row by its id: $ab_test_meta_ids holds mid => array( post_id, key, value ). */
	function get_metadata_by_mid( $meta_type, $meta_id ) {
		$row = $GLOBALS['ab_test_meta_ids'][ (int) $meta_id ] ?? null;
		if ( ! is_array( $row ) ) {
			return false;
		}
		return (object) array(
			'meta_id'    => (int) $meta_id,
			'post_id'    => (int) $row[0],
			'meta_key'   => (string) $row[1],
			'meta_value' => $row[2],
		);
	}
}

if ( ! function_exists( 'update_metadata' ) ) {
	function update_metadata( $meta_type, $object_id, $meta_key, $meta_value, $prev_value = '' ) {
		$check = apply_filters( "update_{$meta_type}_metadata", null, $object_id, $meta_key, $meta_value, $prev_value );
		if ( null !== $check ) {
			return (bool) $check;
		}
		return update_post_meta( $object_id, $meta_key, $meta_value, $prev_value );
	}
}

if ( ! function_exists( 'add_metadata' ) ) {
	function add_metadata( $meta_type, $object_id, $meta_key, $meta_value, $unique = false ) {
		$check = apply_filters( "add_{$meta_type}_metadata", null, $object_id, $meta_key, $meta_value, $unique );
		if ( null !== $check ) {
			return $check;
		}
		return add_post_meta( $object_id, $meta_key, $meta_value, $unique );
	}
}

if ( ! function_exists( 'update_metadata_by_mid' ) ) {
	function update_metadata_by_mid( $meta_type, $meta_id, $meta_value, $meta_key = false ) {
		$check = apply_filters( "update_{$meta_type}_metadata_by_mid", null, $meta_id, $meta_value, $meta_key );
		if ( null !== $check ) {
			return (bool) $check;
		}
		$GLOBALS['ab_test_meta_ids'][ (int) $meta_id ][2] = $meta_value;
		return true;
	}
}

if ( ! function_exists( 'delete_metadata_by_mid' ) ) {
	function delete_metadata_by_mid( $meta_type, $meta_id ) {
		$check = apply_filters( "delete_{$meta_type}_metadata_by_mid", null, $meta_id );
		if ( null !== $check ) {
			return (bool) $check;
		}
		unset( $GLOBALS['ab_test_meta_ids'][ (int) $meta_id ] );
		return true;
	}
}

if ( ! function_exists( 'sanitize_post' ) ) {
	/** The fields as given, unless a test's filter pre_<field> changes one (kses would). */
	function sanitize_post( $post, $context = 'display' ) {
		$fields = (array) $post;
		foreach ( $fields as $field => $value ) {
			$fields[ $field ] = apply_filters( 'pre_' . $field, $value );
		}
		return is_object( $post ) ? (object) $fields : $fields;
	}
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( $taxonomy ) {
		return in_array( (string) $taxonomy, $GLOBALS['ab_test_taxonomies'] ?? array( 'category', 'post_tag', 'post_format' ), true );
	}
}

if ( ! function_exists( 'get_term_by' ) ) {
	/** $ab_test_terms_by_slug holds taxonomy => slug => term_id. */
	function get_term_by( $field, $value, $taxonomy = '' ) {
		$id = $GLOBALS['ab_test_terms_by_slug'][ (string) $taxonomy ][ (string) $value ] ?? null;
		return null === $id ? false : (object) array(
			'term_id'  => (int) $id,
			'slug'     => (string) $value,
			'taxonomy' => (string) $taxonomy,
		);
	}
}

if ( ! function_exists( 'clean_term_cache' ) ) {
	function clean_term_cache( $ids, $taxonomy = '', $clean_taxonomy = true ) {
		$GLOBALS['ab_test_term_cache_cleaned'][] = (string) $taxonomy;
	}
}

if ( ! function_exists( 'clean_post_cache' ) ) {
	function clean_post_cache( $post ) {
		$GLOBALS['ab_test_post_cache_cleaned'][] = (int) ( is_object( $post ) ? $post->ID : $post );
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		return true;
	}
}

if ( ! function_exists( 'get_gmt_from_date' ) ) {
	/** The site in UTC: the same date. */
	function get_gmt_from_date( $date_string, $format = 'Y-m-d H:i:s' ) {
		return (string) $date_string;
	}
}

if ( ! function_exists( 'wp_set_object_terms' ) ) {
	function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
		$GLOBALS['ab_test_term_writes'][] = array( 'set', (int) $object_id, (string) $taxonomy, (array) $terms );
		return array();
	}
}

if ( ! function_exists( 'wp_trash_post' ) ) {
	function wp_trash_post( $post_id = 0 ) {
		$check = apply_filters( 'pre_trash_post', null, get_post( $post_id ), 'publish' );
		return null !== $check ? $check : get_post( $post_id );
	}
}
