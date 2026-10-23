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
				'description' => 'List the terms of one taxonomy (categories, tags or a custom taxonomy), ordered by name. Returns terms: [{term_id, name, slug, count, parent}]; count is the number of objects WordPress counts for the term, parent is 0 at the top level. Only the first per_page matches are returned; there are no further pages. Terms of a non-public taxonomy need the right to assign them. Read-only; wp_get_term returns one term with its description.',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'taxonomy' ),
					'properties' => array(
						'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy name, e.g. "category", "post_tag" or a custom one, as wp_get_taxonomies lists it. An unknown taxonomy is refused.' ),
						'search'   => array( 'type' => 'string', 'description' => 'Only terms whose name or slug contains this text.' ),
						'hide_empty' => array( 'type' => 'boolean', 'description' => 'true leaves out terms whose count is 0; in a hierarchical taxonomy a parent stays when a descendant is not empty. Default false.', 'default' => false ),
						'per_page' => array( 'type' => 'integer', 'description' => 'Maximum number of terms, 1–500; other values are clamped. Default 100.', 'default' => 100 ),
					),
				),
				'callback'    => array( __CLASS__, 'list_terms' ),
			)
		);

		$r->register(
			'wp_create_term',
			array(
				'description' => 'Create a new term (category, tag or custom taxonomy term) in a taxonomy. Returns {created: true, term_id}. Needs the taxonomy\'s right to manage terms (for categories and tags: manage_categories). WordPress refuses a parent that does not exist and, without a distinct slug, a name the taxonomy already has (in a hierarchical taxonomy: under the same parent). Existing terms are changed with wp_update_term.',
				'capability'  => 'manage_categories',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'taxonomy', 'name' ),
					'properties' => array(
						'taxonomy'    => array( 'type' => 'string', 'description' => 'Taxonomy name, e.g. "category" or "post_tag", as wp_get_taxonomies lists it. An unknown taxonomy is refused.' ),
						'name'        => array( 'type' => 'string', 'description' => 'Display name of the new term.' ),
						'slug'        => array( 'type' => 'string', 'description' => 'URL slug. Default: derived from the name. WordPress adds a suffix when the slug is taken.' ),
						'description' => array( 'type' => 'string', 'description' => 'Term description. Default empty.' ),
						'parent'      => array( 'type' => 'integer', 'description' => 'Id of the parent term in the same taxonomy, for hierarchical taxonomies such as categories. Default 0: top level.', 'default' => 0 ),
					),
				),
				'callback'    => array( __CLASS__, 'create_term' ),
			)
		);

		$r->register(
			'wp_update_term',
			array(
				'description' => 'Change the name, slug, description or parent of an existing term; term_id and taxonomy identify it. Only the fields passed change, the others keep their values. Returns {updated: true, term_id}; an unknown term returns an error. Needs the taxonomy\'s right to edit terms (for categories and tags: manage_categories). Where the permalink structure uses slugs, a new slug changes the URL of the term\'s archive, and WordPress does not redirect the old one. The term\'s custom meta is not changed here.',
				'capability'  => 'manage_categories',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'term_id', 'taxonomy' ),
					'properties' => array(
						'term_id'     => array( 'type' => 'integer', 'description' => 'Id of the term to change, as wp_list_terms or wp_get_term return it.' ),
						'taxonomy'    => array( 'type' => 'string', 'description' => 'Taxonomy the term belongs to, e.g. "category" or "post_tag".' ),
						'name'        => array( 'type' => 'string', 'description' => 'New display name. When omitted, it stays as it is; an empty name is refused.' ),
						'slug'        => array( 'type' => 'string', 'description' => 'New URL slug. When omitted, it stays as it is; an empty string derives it from the name, made unique if taken. A slug another term of the taxonomy uses is refused, unless parent changes in the same call (WordPress then makes it unique).' ),
						'description' => array( 'type' => 'string', 'description' => 'New description; an empty string clears it. When omitted, it stays as it is.' ),
						'parent'      => array( 'type' => 'integer', 'description' => 'Id of the new parent term; 0 moves the term to the top level. A parent that does not exist is refused. When omitted, it stays as it is.' ),
					),
				),
				'callback'    => array( __CLASS__, 'update_term' ),
			)
		);

		$r->register(
			'wp_delete_term',
			array(
				'description' => 'Delete a term from a taxonomy for good; terms have no trash. Posts lose the term (a post left without a category gets the default category), child terms move up to the deleted term\'s parent, and the term\'s meta is removed. The taxonomy\'s default term, such as the default category, is not deleted; it and an unknown term_id return an error. Needs the taxonomy\'s right to delete terms. Returns {deleted: true}.',
				'capability'  => 'manage_categories',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'term_id', 'taxonomy' ),
					'properties' => array(
						'term_id'  => array( 'type' => 'integer', 'description' => 'Id of the term to delete.' ),
						'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy the term belongs to, e.g. "category" or "post_tag".' ),
					),
				),
				'callback'    => array( __CLASS__, 'delete_term' ),
			)
		);

		/* ---------------- Comments ---------------- */

		$r->register(
			'wp_list_comments',
			array(
				'description' => 'List comments, newest first, optionally filtered by status, post and search text. Returns comments: [{comment_id, post_id, author, content, status, date}]; status is "approved", "unapproved", "spam" or "trash", date is RFC 3339 in site time. Paged with per_page and page; no total count is returned, and a page past the end is empty. Needs moderate_comments. Read-only; wp_get_comment returns one comment with its parent.',
				'capability'  => 'moderate_comments',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'status'   => array( 'type' => 'string', 'description' => 'approve (approved), hold (pending), spam, trash or all. Default "all", which covers approved and pending comments, not spam or trash; any other value counts as all.', 'default' => 'all' ),
						'post_id'  => array( 'type' => 'integer', 'description' => 'Only comments on this post id. Default 0: comments on all posts.', 'default' => 0 ),
						'search'   => array( 'type' => 'string', 'description' => 'Text matched against the comment content and the author\'s name, email, URL and IP.' ),
						'per_page' => array( 'type' => 'integer', 'description' => 'Comments per page, 1–100; other values are clamped. Default 20.', 'default' => 20 ),
						'page'     => array( 'type' => 'integer', 'description' => 'Page number, starting at 1. Default 1.', 'default' => 1 ),
					),
				),
				'callback'    => array( __CLASS__, 'list_comments' ),
			)
		);

		$r->register(
			'wp_moderate_comment',
			array(
				'description' => 'Set the moderation status of one comment: approve marks it approved, hold sets it back to pending, spam marks it as spam, trash moves it to the trash. Returns {moderated: true, comment_id, status}, status being the one asked for: the answer does not confirm that WordPress stored it, and wp_get_comment shows the stored status. Needs moderate_comments. Approving shows the comment wherever the post\'s comments are public, and WordPress may notify the post\'s author. Where the site\'s trash is off (EMPTY_TRASH_DAYS 0), trash deletes the comment for good, as in WordPress; every other change can be reversed by a later call. Trashing a top-level note (comment_type note) also trashes its reply notes, and setting the note to approve or hold later does not bring those back. An unknown comment_id returns a not-found error. Deleting outright is done by wp_delete_comment.',
				'capability'  => 'moderate_comments',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'comment_id', 'status' ),
					'properties' => array(
						'comment_id' => array( 'type' => 'integer', 'description' => 'Id of the comment, as wp_list_comments returns it.' ),
						'status'     => array(
							'type'        => 'string',
							'enum'        => array( 'approve', 'hold', 'spam', 'trash' ),
							'description' => 'New status: approve, hold (pending), spam or trash. Other values are refused.',
						),
					),
				),
				'callback'    => array( __CLASS__, 'moderate_comment' ),
			)
		);

		$r->register(
			'wp_reply_comment',
			array(
				'description' => 'Add a comment to a post as the connected user: a reply to an existing comment (parent) or a new top-level comment; each call adds a new comment, so a repeated call posts it twice. It is saved as approved and shows at once wherever the post\'s comments are public, under the user\'s display name, with the user\'s email address stored. It is saved directly, so WordPress sends none of its new-comment emails. HTML is filtered to what post content allows. Whether comments are open on the post, and whether parent exists and belongs to that post, is not checked. Returns {created: true, comment_id}. Needs moderate_comments. Existing comments are approved, held or deleted with wp_moderate_comment and wp_delete_comment.',
				'capability'  => 'moderate_comments',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'post_id', 'content' ),
					'properties' => array(
						'post_id'   => array( 'type' => 'integer', 'description' => 'Id of the post the comment belongs to; for a reply, the post of the parent comment. A post that does not exist is refused.' ),
						'content'   => array( 'type' => 'string', 'description' => 'Comment text; HTML is filtered to what post content allows.' ),
						'parent'    => array( 'type' => 'integer', 'description' => 'Id of the comment this replies to; not checked to exist or to belong to post_id. Default 0: a top-level comment.', 'default' => 0 ),
					),
				),
				'callback'    => array( __CLASS__, 'reply_comment' ),
			)
		);

		$r->register(
			'wp_delete_comment',
			array(
				'description' => 'Delete one comment: into the trash by default, for good with force=true, which also removes its meta and moves its replies up a level. A comment already in the trash or marked as spam, and any comment on a site with the trash turned off, is deleted for good even without force, as in WordPress, so a second call on the same comment deletes it for good. Trashing a top-level note (comment_type note) also trashes its reply notes. A trashed comment can be set to approve or hold again with wp_moderate_comment. Returns {deleted: true, comment_id}; an unknown comment_id returns a not-found error. Needs moderate_comments.',
				'capability'  => 'moderate_comments',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'comment_id' ),
					'properties' => array(
						'comment_id' => array( 'type' => 'integer', 'description' => 'Id of the comment to delete.' ),
						'force'      => array( 'type' => 'boolean', 'description' => 'true deletes the comment for good instead of moving it to the trash. Default false.', 'default' => false ),
					),
				),
				'callback'    => array( __CLASS__, 'delete_comment' ),
			)
		);

		$r->register(
			'wp_get_term',
			array(
				'description' => 'Get one term by id and taxonomy. Returns {term_id, name, slug, taxonomy, description, count, parent}; count is the number of objects WordPress counts for the term, parent is 0 at the top level. Terms of a non-public taxonomy need the right to assign them. A term that does not exist in that taxonomy returns a not-found error. Read-only; the term\'s custom meta comes from wp_get_term_meta.',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'term_id', 'taxonomy' ),
					'properties' => array(
						'term_id'  => array( 'type' => 'integer', 'description' => 'Term id, as wp_list_terms returns it.' ),
						'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy the term belongs to, e.g. "category" or "post_tag".' ),
					),
				),
				'callback'    => array( __CLASS__, 'get_term_tool' ),
			)
		);

		$r->register(
			'wp_get_comment',
			array(
				'description' => 'Get one comment by id. Returns {comment_id, post_id, author, content, status, date, parent}: author is the commenter\'s name as stored (no email), status is "approved", "unapproved", "spam", "trash", or false for a comment hidden because its post is in the trash (stored as post-trashed), date is RFC 3339 in site time, parent is 0 for a top-level comment. An unknown comment_id returns a not-found error. Needs moderate_comments. Read-only; wp_list_comments lists several comments with filters.',
				'capability'  => 'moderate_comments',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'comment_id' ),
					'properties' => array( 'comment_id' => array( 'type' => 'integer', 'description' => 'Comment id, as wp_list_comments returns it.' ) ),
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
			return new WP_Error( 'ab_mcp_not_found', __( 'Term not found: no term of this taxonomy has this id. Look it up with wp_list_terms.', 'alphabridge-mcp' ) );
		}
		if ( ! self::can_read_taxonomy( get_taxonomy( $term->taxonomy ) ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot read terms of this taxonomy: it is not public, and reading it takes the right to assign its terms. Connect with an account that has it, such as an administrator.', 'alphabridge-mcp' ) );
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
			return new WP_Error( 'ab_mcp_not_found', __( 'Comment not found: no comment has this id. Look it up with wp_list_comments.', 'alphabridge-mcp' ) );
		}
		return array(
			'comment_id' => (int) $c->comment_ID,
			'post_id'    => (int) $c->comment_post_ID,
			'author'     => $c->comment_author,
			'content'    => $c->comment_content,
			'status'     => wp_get_comment_status( $c->comment_ID ),
			'date'       => self::site_time( $c->comment_date_gmt, $c->comment_date ),
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
			return new WP_Error( 'ab_mcp_bad_tax', __( 'Unknown taxonomy. wp_get_taxonomies lists the taxonomies of this site; pass one of their names as taxonomy.', 'alphabridge-mcp' ) );
		}
		if ( ! self::can_read_taxonomy( get_taxonomy( $tax ) ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot read terms of this taxonomy: it is not public, and reading it takes the right to assign its terms. Connect with an account that has it, such as an administrator.', 'alphabridge-mcp' ) );
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
			return new WP_Error( 'ab_mcp_bad_tax', __( 'Unknown taxonomy. wp_get_taxonomies lists the taxonomies of this site; pass one of their names as taxonomy.', 'alphabridge-mcp' ) );
		}
		$tax_obj = get_taxonomy( $tax );
		if ( ! $tax_obj ) {
			return new WP_Error( 'ab_mcp_invalid_tax', __( 'Unknown taxonomy. wp_get_taxonomies lists the taxonomies of this site; pass one of their names as taxonomy.', 'alphabridge-mcp' ) );
		}
		if ( ! current_user_can( $tax_obj->cap->manage_terms ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot manage terms of this taxonomy. Connect with an account that may, such as an editor or an administrator.', 'alphabridge-mcp' ) );
		}
		// wp_insert_term() unslashes exactly two of these — name and
		// description — so only those are slashed. Slashing the slug would add
		// a backslash that sanitize_title() then turns into a dash.
		$res = wp_insert_term(
			wp_slash( self::s( $a, 'name' ) ),
			$tax,
			array(
				'slug'        => self::s( $a, 'slug', '' ),
				'description' => wp_slash( self::s( $a, 'description', '' ) ),
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
			return new WP_Error( 'ab_mcp_bad_tax', __( 'Unknown taxonomy. wp_get_taxonomies lists the taxonomies of this site; pass one of their names as taxonomy.', 'alphabridge-mcp' ) );
		}
		$tax_obj = get_taxonomy( $tax );
		if ( ! $tax_obj ) {
			return new WP_Error( 'ab_mcp_invalid_tax', __( 'Unknown taxonomy. wp_get_taxonomies lists the taxonomies of this site; pass one of their names as taxonomy.', 'alphabridge-mcp' ) );
		}
		if ( ! current_user_can( $tax_obj->cap->edit_terms ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot manage terms of this taxonomy. Connect with an account that may, such as an editor or an administrator.', 'alphabridge-mcp' ) );
		}
		$fields = array();
		foreach ( array( 'name', 'slug', 'description' ) as $k ) {
			if ( array_key_exists( $k, $a ) ) {
				// wp_update_term() unslashes name and description, not slug.
				$fields[ $k ] = in_array( $k, array( 'name', 'description' ), true )
					? wp_slash( self::s( $a, $k ) )
					: self::s( $a, $k );
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
			return new WP_Error( 'ab_mcp_invalid_tax', __( 'Unknown taxonomy. wp_get_taxonomies lists the taxonomies of this site; pass one of their names as taxonomy.', 'alphabridge-mcp' ) );
		}
		if ( ! current_user_can( $tax_obj->cap->delete_terms ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot manage terms of this taxonomy. Connect with an account that may, such as an editor or an administrator.', 'alphabridge-mcp' ) );
		}
		$res = wp_delete_term( self::i( $a, 'term_id' ), $tax );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! $res ) {
			return new WP_Error( 'ab_mcp_delete_failed', __( 'WordPress did not delete the term: no term of this taxonomy has this id, or it is the taxonomy\'s default term (such as the default category), which WordPress keeps. Check the id with wp_list_terms; a default category can be deleted once another one is the default (Settings → Writing in wp-admin).', 'alphabridge-mcp' ) );
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
				'date'       => self::site_time( $c->comment_date_gmt, $c->comment_date ),
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
			return new WP_Error( 'ab_mcp_not_found', __( 'Comment not found: no comment has this id. Look it up with wp_list_comments.', 'alphabridge-mcp' ) );
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
			return new WP_Error( 'ab_mcp_not_found', __( 'Post not found: no post has this id. Look it up with wp_list_posts or wp_search.', 'alphabridge-mcp' ) );
		}
		$user = wp_get_current_user();
		// wp_insert_comment() unslashes the whole array it is given.
		$id   = wp_insert_comment(
			wp_slash( array(
				'comment_post_ID'      => $post_id,
				'comment_parent'       => self::i( $a, 'parent', 0 ),
				'comment_content'      => wp_kses_post( self::s( $a, 'content' ) ),
				'user_id'              => $user->ID,
				'comment_author'       => $user->display_name,
				'comment_author_email' => $user->user_email,
				'comment_approved'     => 1,
			) )
		);
		if ( ! $id ) {
			return new WP_Error( 'ab_mcp_comment_failed', __( 'WordPress did not save the comment, so nothing was written. Try again; if it keeps failing, ask the site administrator to check the database.', 'alphabridge-mcp' ) );
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
			return new WP_Error( 'ab_mcp_not_found', __( 'Comment not found: no comment has this id. Look it up with wp_list_comments.', 'alphabridge-mcp' ) );
		}
		// Nothing promised about what is left: deleting for good, WordPress
		// moves replies up and removes the comment's meta before its row;
		// trashing a note, it says false when a reply stays out of the trash.
		$res = wp_delete_comment( $id, self::b( $a, 'force', false ) );
		if ( ! $res ) {
			return new WP_Error( 'ab_mcp_delete_failed', __( 'WordPress did not delete the comment; a plugin may have stopped it. Check it with wp_get_comment and try again.', 'alphabridge-mcp' ) );
		}
		return array(
			'deleted'    => true,
			'comment_id' => $id,
		);
	}
}
