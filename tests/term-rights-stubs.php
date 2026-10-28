<?php
/**
 * What TermAndCommentRightsTest reaches beyond the bootstrap and the stubs
 * of AnswerWayTest (taxonomies, terms, the deletion of a term and of a
 * comment): changing a term, setting the terms of a post and saving a
 * comment. Each notes its call in $GLOBALS['ab_test_term_writes'], so a test
 * sees whether anything was written. Loaded only by that test.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

require_once __DIR__ . '/answer-way-stubs.php';

/** @var array[] $ab_test_term_writes One entry per write: its kind and what it was handed. */
$GLOBALS['ab_test_term_writes'] = array();

if ( ! function_exists( 'wp_update_term' ) ) {
	/**
	 * As WordPress: an id of no term of this taxonomy is refused with
	 * invalid_term and nothing is written; otherwise notes the change.
	 */
	function wp_update_term( $term_id, $taxonomy, $args = array() ) {
		$term = $GLOBALS['ab_test_way']['terms'][ (int) $term_id ] ?? null;
		if ( ! $term || (string) $taxonomy !== $term->taxonomy ) {
			return new WP_Error( 'invalid_term', 'Empty Term.' );
		}
		$GLOBALS['ab_test_term_writes'][] = array( 'update', (int) $term_id, (string) $taxonomy );
		return array(
			'term_id'          => (int) $term_id,
			'term_taxonomy_id' => (int) $term_id,
		);
	}
}

if ( ! function_exists( 'wp_get_object_terms' ) ) {
	/** The term ids the test gave a post in a taxonomy ($GLOBALS['ab_test_way']['object_terms']). */
	function wp_get_object_terms( $object_ids, $taxonomies, $args = array() ) {
		return $GLOBALS['ab_test_way']['object_terms'][ (int) $object_ids ][ (string) $taxonomies ] ?? array();
	}
}

if ( ! function_exists( 'wp_set_object_terms' ) ) {
	/** Notes which terms a post was given in which taxonomy. */
	function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
		$GLOBALS['ab_test_term_writes'][] = array( 'set', (int) $object_id, (string) $taxonomy, array_map( 'intval', (array) $terms ) );
		return array();
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $data ) {
		return (string) $data;
	}
}

if ( ! function_exists( 'wp_insert_comment' ) ) {
	/** Notes the comment and answers the id of a new one. */
	function wp_insert_comment( $commentdata ) {
		$GLOBALS['ab_test_term_writes'][] = array( 'comment', (int) $commentdata['comment_post_ID'] );
		return 77;
	}
}
