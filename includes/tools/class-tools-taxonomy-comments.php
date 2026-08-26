<?php
/**
 * Taxonomy and comment tools.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tools-base.php';

/**
 * Class AB_MCP_Tools_Taxonomy_Comments
 */
class AB_MCP_Tools_Taxonomy_Comments extends AB_MCP_Tools_Base {

	/**
	 * Register tools.
	 *
	 * @param AB_MCP_Tool_Registry $r Registry.
	 */
	public static function register( AB_MCP_Tool_Registry $r ) {

		/* ---------------- Taxonomies / terms ---------------- */

		$r->register(
			'wp_list_terms',
			array(
				'description' => 'List terms of a taxonomy (categories, tags, custom).',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'taxonomy' ),
					'properties' => array(
						'taxonomy' => array( 'type' => 'string', 'description' => 'e.g. category, post_tag.' ),
						'search'   => array( 'type' => 'string' ),
						'hide_empty' => array( 'type' => 'boolean' ),
						'per_page' => array( 'type' => 'integer' ),
					),
				),
				'callback'    => array( __CLASS__, 'list_terms' ),
			)
		);

		$r->register(
			'wp_create_term',
			array(
				'description' => 'Create a term in a taxonomy.',
				'capability'  => 'manage_categories',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'taxonomy', 'name' ),
					'properties' => array(
						'taxonomy'    => array( 'type' => 'string' ),
						'name'        => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'parent'      => array( 'type' => 'integer' ),
					),
				),
				'callback'    => array( __CLASS__, 'create_term' ),
			)
		);

		$r->register(
			'wp_update_term',
			array(
				'description' => 'Update a term.',
				'capability'  => 'manage_categories',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'term_id', 'taxonomy' ),
					'properties' => array(
						'term_id'     => array( 'type' => 'integer' ),
						'taxonomy'    => array( 'type' => 'string' ),
						'name'        => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'parent'      => array( 'type' => 'integer' ),
					),
				),
				'callback'    => array( __CLASS__, 'update_term' ),
			)
		);

		$r->register(
			'wp_delete_term',
			array(
				'description' => 'Delete a term from a taxonomy.',
				'capability'  => 'manage_categories',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'term_id', 'taxonomy' ),
					'properties' => array(
						'term_id'  => array( 'type' => 'integer' ),
						'taxonomy' => array( 'type' => 'string' ),
					),
				),
				'callback'    => array( __CLASS__, 'delete_term' ),
			)
		);

		/* ---------------- Comments ---------------- */

		$r->register(
			'wp_list_comments',
			array(
				'description' => 'List comments with status and post filters.',
				'capability'  => 'moderate_comments',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'status'   => array( 'type' => 'string', 'description' => 'approve, hold, spam, trash, all.' ),
						'post_id'  => array( 'type' => 'integer' ),
						'search'   => array( 'type' => 'string' ),
						'per_page' => array( 'type' => 'integer' ),
						'page'     => array( 'type' => 'integer' ),
					),
				),
				'callback'    => array( __CLASS__, 'list_comments' ),
			)
		);

		$r->register(
			'wp_moderate_comment',
			array(
				'description' => 'Set a comment status: approve, hold, spam or trash.',
				'capability'  => 'moderate_comments',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'comment_id', 'status' ),
					'properties' => array(
						'comment_id' => array( 'type' => 'integer' ),
						'status'     => array( 'type' => 'string', 'description' => 'approve, hold, spam, trash.' ),
					),
				),
				'callback'    => array( __CLASS__, 'moderate_comment' ),
			)
		);

		$r->register(
			'wp_reply_comment',
			array(
				'description' => 'Reply to a comment (or comment on a post) as the current user.',
				'capability'  => 'moderate_comments',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'post_id', 'content' ),
					'properties' => array(
						'post_id'   => array( 'type' => 'integer' ),
						'content'   => array( 'type' => 'string' ),
						'parent'    => array( 'type' => 'integer', 'description' => 'Parent comment id.' ),
					),
				),
				'callback'    => array( __CLASS__, 'reply_comment' ),
			)
		);

		$r->register(
			'wp_delete_comment',
			array(
				'description' => 'Delete a comment (trash by default, force to remove permanently).',
				'capability'  => 'moderate_comments',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'comment_id' ),
					'properties' => array(
						'comment_id' => array( 'type' => 'integer' ),
						'force'      => array( 'type' => 'boolean' ),
					),
				),
				'callback'    => array( __CLASS__, 'delete_comment' ),
			)
		);

		$r->register(
			'wp_get_term',
			array(
				'description' => 'Get a single term by id and taxonomy.',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'term_id', 'taxonomy' ),
					'properties' => array(
						'term_id'  => array( 'type' => 'integer' ),
						'taxonomy' => array( 'type' => 'string' ),
					),
				),
				'callback'    => array( __CLASS__, 'get_term_tool' ),
			)
		);

		$r->register(
			'wp_get_comment',
			array(
				'description' => 'Get a single comment by id.',
				'capability'  => 'moderate_comments',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'comment_id' ),
					'properties' => array( 'comment_id' => array( 'type' => 'integer' ) ),
				),
				'callback'    => array( __CLASS__, 'get_comment_tool' ),
			)
		);
	}

	/**
	 * Get a term.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function get_term_tool( $a ) {
		$term = get_term( self::i( $a, 'term_id' ), self::s( $a, 'taxonomy' ) );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Term not found.', 'alphabridge-mcp' ) );
		}
		if ( ! self::can_read_taxonomy( get_taxonomy( $term->taxonomy ) ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot read terms of this taxonomy.', 'alphabridge-mcp' ) );
		}
		return array(
			'term_id'     => (int) $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'taxonomy'    => $term->taxonomy,
			'description' => $term->description,
			'count'       => (int) $term->count,
			'parent'      => (int) $term->parent,
		);
	}

	/**
	 * Get a comment.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function get_comment_tool( $a ) {
		$c = get_comment( self::i( $a, 'comment_id' ) );
		if ( ! $c ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Comment not found.', 'alphabridge-mcp' ) );
		}
		return array(
			'comment_id' => (int) $c->comment_ID,
			'post_id'    => (int) $c->comment_post_ID,
			'author'     => $c->comment_author,
			'content'    => $c->comment_content,
			'status'     => wp_get_comment_status( $c->comment_ID ),
			'date'       => $c->comment_date_gmt,
			'parent'     => (int) $c->comment_parent,
		);
	}

	/* --------------------------------------------------------------- terms */

	/**
	 * List terms.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function list_terms( $a ) {
		$tax = self::s( $a, 'taxonomy' );
		if ( ! taxonomy_exists( $tax ) ) {
			return new WP_Error( 'ab_mcp_bad_tax', __( 'Unknown taxonomy.', 'alphabridge-mcp' ) );
		}
		if ( ! self::can_read_taxonomy( get_taxonomy( $tax ) ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot read terms of this taxonomy.', 'alphabridge-mcp' ) );
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $tax,
				'search'     => self::s( $a, 'search', '' ),
				'hide_empty' => self::b( $a, 'hide_empty', false ),
				'number'     => self::clamp( self::i( $a, 'per_page', 100 ), 1, 500 ),
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		$out = array();
		foreach ( $terms as $t ) {
			$out[] = array(
				'term_id' => (int) $t->term_id,
				'name'    => $t->name,
				'slug'    => $t->slug,
				'count'   => (int) $t->count,
				'parent'  => (int) $t->parent,
			);
		}
		return array( 'terms' => $out );
	}

	/**
	 * Create term.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function create_term( $a ) {
		$tax = self::s( $a, 'taxonomy' );
		if ( ! taxonomy_exists( $tax ) ) {
			return new WP_Error( 'ab_mcp_bad_tax', __( 'Unknown taxonomy.', 'alphabridge-mcp' ) );
		}
		$tax_obj = get_taxonomy( $tax );
		if ( ! $tax_obj ) {
			return new WP_Error( 'ab_mcp_invalid_tax', __( 'Unknown taxonomy.', 'alphabridge-mcp' ) );
		}
		if ( ! current_user_can( $tax_obj->cap->manage_terms ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot manage terms of this taxonomy.', 'alphabridge-mcp' ) );
		}
		$res = wp_insert_term(
			self::s( $a, 'name' ),
			$tax,
			array(
				'slug'        => self::s( $a, 'slug', '' ),
				'description' => self::s( $a, 'description', '' ),
				'parent'      => self::i( $a, 'parent', 0 ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'created' => true,
			'term_id' => (int) $res['term_id'],
		);
	}

	/**
	 * Update term.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function update_term( $a ) {
		$tax = self::s( $a, 'taxonomy' );
		$id  = self::i( $a, 'term_id' );
		if ( ! taxonomy_exists( $tax ) ) {
			return new WP_Error( 'ab_mcp_bad_tax', __( 'Unknown taxonomy.', 'alphabridge-mcp' ) );
		}
		$tax_obj = get_taxonomy( $tax );
		if ( ! $tax_obj ) {
			return new WP_Error( 'ab_mcp_invalid_tax', __( 'Unknown taxonomy.', 'alphabridge-mcp' ) );
		}
		if ( ! current_user_can( $tax_obj->cap->edit_terms ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot manage terms of this taxonomy.', 'alphabridge-mcp' ) );
		}
		$fields = array();
		foreach ( array( 'name', 'slug', 'description' ) as $k ) {
			if ( array_key_exists( $k, $a ) ) {
				$fields[ $k ] = self::s( $a, $k );
			}
		}
		if ( array_key_exists( 'parent', $a ) ) {
			$fields['parent'] = self::i( $a, 'parent' );
		}
		$res = wp_update_term( $id, $tax, $fields );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'updated' => true,
			'term_id' => $id,
		);
	}

	/**
	 * Delete term.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function delete_term( $a ) {
		$tax = self::s( $a, 'taxonomy' );
		$tax_obj = get_taxonomy( $tax );
		if ( ! $tax_obj ) {
			return new WP_Error( 'ab_mcp_invalid_tax', __( 'Unknown taxonomy.', 'alphabridge-mcp' ) );
		}
		if ( ! current_user_can( $tax_obj->cap->delete_terms ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot manage terms of this taxonomy.', 'alphabridge-mcp' ) );
		}
		$res = wp_delete_term( self::i( $a, 'term_id' ), $tax );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! $res ) {
			return new WP_Error( 'ab_mcp_delete_failed', __( 'Delete failed.', 'alphabridge-mcp' ) );
		}
		return array( 'deleted' => true );
	}

	/* --------------------------------------------------------------- comments */

	/**
	 * List comments.
	 *
	 * @param array $a Args.
	 * @return array
	 */
	public static function list_comments( $a ) {
		$status_map = array(
			'approve' => 'approve',
			'hold'    => 'hold',
			'spam'    => 'spam',
			'trash'   => 'trash',
			'all'     => 'all',
		);
		$status   = self::s( $a, 'status', 'all' );
		$per_page = self::clamp( self::i( $a, 'per_page', 20 ), 1, 100 );

		$comments = get_comments(
			array(
				'status'  => isset( $status_map[ $status ] ) ? $status_map[ $status ] : 'all',
				'post_id' => self::i( $a, 'post_id', 0 ),
				'search'  => self::s( $a, 'search', '' ),
				'number'  => $per_page,
				'offset'  => ( max( 1, self::i( $a, 'page', 1 ) ) - 1 ) * $per_page,
			)
		);

		$out = array();
		foreach ( $comments as $c ) {
			$out[] = array(
				'comment_id' => (int) $c->comment_ID,
				'post_id'    => (int) $c->comment_post_ID,
				'author'     => $c->comment_author,
				'content'    => $c->comment_content,
				'status'     => wp_get_comment_status( $c->comment_ID ),
				'date'       => $c->comment_date_gmt,
			);
		}
		return array( 'comments' => $out );
	}

	/**
	 * Moderate comment.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function moderate_comment( $a ) {
		$id     = self::i( $a, 'comment_id' );
		$status = self::s( $a, 'status' );
		if ( ! get_comment( $id ) ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Comment not found.', 'alphabridge-mcp' ) );
		}

		if ( 'spam' === $status ) {
			wp_spam_comment( $id );
		} elseif ( 'trash' === $status ) {
			wp_trash_comment( $id );
		} elseif ( in_array( $status, array( 'approve', 'hold' ), true ) ) {
			wp_set_comment_status( $id, $status );
		} else {
			return new WP_Error( 'ab_mcp_bad_status', __( 'Status must be approve, hold, spam or trash.', 'alphabridge-mcp' ) );
		}

		return array(
			'moderated' => true,
			'comment_id'=> $id,
			'status'    => $status,
		);
	}

	/**
	 * Reply / add comment.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function reply_comment( $a ) {
		$post_id = self::i( $a, 'post_id' );
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Post not found.', 'alphabridge-mcp' ) );
		}
		$user = wp_get_current_user();
		$id   = wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_parent'       => self::i( $a, 'parent', 0 ),
				'comment_content'      => wp_kses_post( self::s( $a, 'content' ) ),
				'user_id'              => $user->ID,
				'comment_author'       => $user->display_name,
				'comment_author_email' => $user->user_email,
				'comment_approved'     => 1,
			)
		);
		if ( ! $id ) {
			return new WP_Error( 'ab_mcp_comment_failed', __( 'Could not create comment.', 'alphabridge-mcp' ) );
		}
		return array(
			'created'    => true,
			'comment_id' => (int) $id,
		);
	}

	/**
	 * Delete comment.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function delete_comment( $a ) {
		$id = self::i( $a, 'comment_id' );
		if ( ! get_comment( $id ) ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Comment not found.', 'alphabridge-mcp' ) );
		}
		$res = wp_delete_comment( $id, self::b( $a, 'force', false ) );
		if ( ! $res ) {
			return new WP_Error( 'ab_mcp_delete_failed', __( 'Delete failed.', 'alphabridge-mcp' ) );
		}
		return array(
			'deleted'    => true,
			'comment_id' => $id,
		);
	}
}
