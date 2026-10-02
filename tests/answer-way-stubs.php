<?php
/**
 * The few WordPress functions the refusals in AnswerWayTest reach and the
 * bootstrap does not emulate: taxonomies and terms, and the deletions of a
 * term, a file and a comment. Each answers what the test put in
 * $GLOBALS['ab_test_way'], so a test decides exactly which branch runs.
 * Loaded only by AnswerWayTest; nothing else in the suite calls these.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

/** @var array{taxonomies?:array<string,object>,terms?:array<int,object>,term_deleted?:mixed,attachment_deleted?:mixed,comment_deleted?:mixed} $ab_test_way */
$GLOBALS['ab_test_way'] = array();

if ( ! function_exists( 'get_taxonomy' ) ) {
	/** A taxonomy the test registered, or false as WordPress answers for an unknown one. */
	function get_taxonomy( $taxonomy ) {
		return $GLOBALS['ab_test_way']['taxonomies'][ (string) $taxonomy ] ?? false;
	}
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( $taxonomy ) {
		return false !== get_taxonomy( $taxonomy );
	}
}

if ( ! function_exists( 'get_term' ) ) {
	/** A term the test added, or null as WordPress answers for an unknown id. */
	function get_term( $term, $taxonomy = '' ) {
		return $GLOBALS['ab_test_way']['terms'][ (int) $term ] ?? null;
	}
}

if ( ! function_exists( 'wp_delete_term' ) ) {
	/** What the test says WordPress answered: true, false (no such term, or the default term) or a WP_Error. */
	function wp_delete_term( $term, $taxonomy, $args = array() ) {
		return $GLOBALS['ab_test_way']['term_deleted'] ?? false;
	}
}

if ( ! function_exists( 'wp_delete_attachment' ) ) {
	/** What the test says WordPress answered: the post, or false (not an attachment, or a plugin said no). */
	function wp_delete_attachment( $post_id, $force_delete = false ) {
		return $GLOBALS['ab_test_way']['attachment_deleted'] ?? false;
	}
}

if ( ! function_exists( 'wp_delete_comment' ) ) {
	/** What the test says WordPress answered: true, or false (a plugin said no). */
	function wp_delete_comment( $comment_id, $force_delete = false ) {
		return $GLOBALS['ab_test_way']['comment_deleted'] ?? false;
	}
}
