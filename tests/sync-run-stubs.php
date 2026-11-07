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
			'meta_id'                     => (int) $meta_id,
			'post_id'                     => (int) $row[0],
			sanitize_key( $meta_type . '_id' ) => (int) $row[0],
			'meta_key'                    => (string) $row[1],
			'meta_value'                  => $row[2],
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

if ( ! function_exists( 'get_taxonomy' ) ) {
	/** A taxonomy the test registered in $ab_test_way['taxonomies'] (the same record tests/answer-way-stubs.php reads, so both definitions answer alike), or false. */
	function get_taxonomy( $taxonomy ) {
		return $GLOBALS['ab_test_way']['taxonomies'][ (string) $taxonomy ] ?? false;
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

if ( ! function_exists( 'get_post_types' ) ) {
	/** The post types registered: $ab_test_post_types, or the two core types. */
	function get_post_types( $args = array(), $output = 'names', $operator = 'and' ) {
		$types = $GLOBALS['ab_test_post_types'] ?? array( 'post', 'page' );
		return 'names' === $output ? array_combine( $types, $types ) : $types;
	}
}

if ( ! function_exists( 'sanitize_meta' ) ) {
	/** As the core: the filter sanitize_{$type}_meta_{$key}, a pass-through without one (branch 9: the sanitizers ahead). */
	function sanitize_meta( $meta_key, $meta_value, $object_type, $object_subtype = '' ) {
		return apply_filters( "sanitize_{$object_type}_meta_{$meta_key}", $meta_value, $meta_key, $object_type );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	/** Lower case, runs of other characters as one hyphen (enough for the slugs of these tests). */
	function sanitize_title( $title, $fallback_title = '', $context = 'save' ) {
		$slug = trim( (string) preg_replace( '/[^a-z0-9_%-]+/', '-', strtolower( (string) $title ) ), '-' );
		return '' === $slug ? (string) $fallback_title : $slug;
	}
}

if ( ! function_exists( 'wp_unique_post_slug' ) ) {
	/** The slug as given, unless a test names another in $ab_test_unique_slugs (slug => slug WordPress would store). */
	function wp_unique_post_slug( $slug, $post_id, $post_status, $post_type, $post_parent ) {
		return $GLOBALS['ab_test_unique_slugs'][ (string) $slug ] ?? (string) $slug;
	}
}

if ( ! function_exists( 'wp_get_attachment_image' ) ) {
	/** An image for the attachments a test lists in $ab_test_images. */
	function wp_get_attachment_image( $attachment_id, $size = 'thumbnail', $icon = false, $attr = '' ) {
		return in_array( (int) $attachment_id, $GLOBALS['ab_test_images'] ?? array(), true ) ? '<img src="x.jpg">' : '';
	}
}

if ( ! function_exists( 'set_post_thumbnail' ) ) {
	function set_post_thumbnail( $post, $thumbnail_id ) {
		$GLOBALS['ab_test_thumbnails'][] = array( 'set', (int) $post, (int) $thumbnail_id );
		return update_post_meta( (int) $post, '_thumbnail_id', (int) $thumbnail_id );
	}
}

if ( ! function_exists( 'delete_post_thumbnail' ) ) {
	function delete_post_thumbnail( $post ) {
		$GLOBALS['ab_test_thumbnails'][] = array( 'delete', (int) $post );
		return delete_post_meta( (int) $post, '_thumbnail_id' );
	}
}

if ( ! function_exists( 'sanitize_term' ) ) {
	/** The fields as given, unless a test's filter pre_term_<field> changes one (kses would). */
	function sanitize_term( $term, $taxonomy, $context = 'display' ) {
		$fields = (array) $term;
		foreach ( array( 'name', 'slug', 'description' ) as $field ) {
			if ( isset( $fields[ $field ] ) ) {
				$fields[ $field ] = apply_filters( 'pre_term_' . $field, $fields[ $field ], $taxonomy );
			}
		}
		return is_object( $term ) ? (object) $fields : $fields;
	}
}

if ( ! function_exists( 'wp_unique_term_slug' ) ) {
	/** The slug as given, unless a test names another in $ab_test_unique_slugs. */
	function wp_unique_term_slug( $slug, $term ) {
		return $GLOBALS['ab_test_unique_slugs'][ (string) $slug ] ?? (string) $slug;
	}
}

if ( ! function_exists( 'is_taxonomy_hierarchical' ) ) {
	function is_taxonomy_hierarchical( $taxonomy ) {
		return in_array( (string) $taxonomy, $GLOBALS['ab_test_hierarchical'] ?? array( 'category' ), true );
	}
}

if ( ! function_exists( 'wp_find_hierarchy_loop' ) ) {
	/** The loop a test names in $ab_test_loop (id => true), none otherwise. */
	function wp_find_hierarchy_loop( $callback, $start, $start_parent, $callback_args = array() ) {
		return $GLOBALS['ab_test_loop'] ?? array();
	}
}

if ( ! function_exists( 'wp_insert_term' ) ) {
	/** Records the call; answers $ab_test_term_answer (array( 'term_id' => …, 'term_taxonomy_id' => … ) or a WP_Error). */
	function wp_insert_term( $term, $taxonomy, $args = array() ) {
		$GLOBALS['ab_test_term_calls'][] = array( 'insert', (string) $term, (string) $taxonomy, (array) $args );
		return $GLOBALS['ab_test_term_answer'] ?? new WP_Error( 'db_insert_error', 'No answer set.' );
	}
}

if ( ! function_exists( 'wp_update_term' ) ) {
	/**
	 * Records the call; answers $ab_test_term_answer, or the term given back.
	 * Where a test keeps its terms in $ab_test_way['terms'] (the stubs of
	 * TermAndCommentRightsTest, tests/term-rights-stubs.php, whose definition
	 * stands back where this one loaded first), as those: an id of no term of
	 * the taxonomy refused with invalid_term, the change noted and kept.
	 */
	function wp_update_term( $term_id, $taxonomy, $args = array() ) {
		$GLOBALS['ab_test_term_calls'][] = array( 'update', (int) $term_id, (string) $taxonomy, (array) $args );
		if ( isset( $GLOBALS['ab_test_term_answer'] ) ) {
			return $GLOBALS['ab_test_term_answer'];
		}
		if ( isset( $GLOBALS['ab_test_way']['terms'] ) ) {
			$term = $GLOBALS['ab_test_way']['terms'][ (int) $term_id ] ?? null;
			if ( ! $term || (string) $taxonomy !== $term->taxonomy ) {
				return new WP_Error( 'invalid_term', 'Empty Term.' );
			}
			$GLOBALS['ab_test_term_writes'][] = array( 'update', (int) $term_id, (string) $taxonomy );
			foreach ( array( 'name', 'slug', 'description', 'parent' ) as $field ) {
				if ( array_key_exists( $field, $args ) ) {
					$term->$field = 'parent' === $field ? (int) $args[ $field ] : wp_unslash( (string) $args[ $field ] );
				}
			}
		}
		return array( 'term_id' => (int) $term_id, 'term_taxonomy_id' => (int) $term_id );
	}
}
