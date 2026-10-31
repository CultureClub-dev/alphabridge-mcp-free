<?php
/**
 * Taxonomy and comment tools.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tools-base.php';
require_once dirname( __DIR__ ) . '/undo/class-undo-terms.php';
require_once dirname( __DIR__ ) . '/undo/class-undo-comments.php';

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
				'description' => 'Create a new term (category, tag or custom taxonomy term) in a taxonomy. Returns {created: true, term_id}, saved_fields (name, slug, description and parent as stored, read again; saved_differs and warning where WordPress stored something else than sent, e.g. a slug made unique) and undo: the token for wp_undo, which deletes the term again while it is unchanged and unused, or null with undo_note saying why. Needs the taxonomy\'s right to manage terms (for categories and tags: manage_categories). WordPress refuses a parent that does not exist and, without a distinct slug, a name the taxonomy already has (in a hierarchical taxonomy: under the same parent). Existing terms are changed with wp_update_term.',
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
				'description' => 'Change the name, slug, description or parent of an existing term; term_id and taxonomy identify it. Only the fields passed change, the others keep their values. Returns {updated: true, term_id}, saved_fields (the fields passed as stored afterwards, read again; saved_differs and warning where WordPress stored something else than sent) and undo: the token for wp_undo, which puts name, slug, description and parent of before back, or null with undo_note saying why. A change WordPress did not store at all is an error (ab_mcp_not_saved); an unknown term returns an error. Needs the taxonomy\'s right to edit terms (for categories and tags: manage_categories) and the right to edit this term (edit_term), which a site can withhold for single terms. Where the permalink structure uses slugs, a new slug changes the URL of the term\'s archive, and WordPress does not redirect the old one. The term\'s custom meta is not changed here.',
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
				'description' => 'Delete a term from a taxonomy for good; terms have no trash. Posts lose the term (a post left without a category gets the default category), child terms move up to the deleted term\'s parent, and the term\'s meta is removed. The taxonomy\'s default term, such as the default category, is not deleted; it and an unknown term_id return an error. Needs the taxonomy\'s right to delete terms and the right to delete this term (delete_term), which a site can withhold for single terms. Returns {deleted: true}, and undo: null with undo_note: the deletion cannot be undone, so note a term that may be needed again with wp_get_term and wp_get_term_meta first.',
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
				'description' => 'Set the moderation status of one comment: approve marks it approved, hold sets it back to pending, spam marks it as spam, trash moves it to the trash. Returns {moderated: true, comment_id, status} with the status asked for, saved_fields (the status as stored afterwards, read again, in the form wp_get_comment shows it; saved_differs and warning where WordPress stored another one) and undo: the token for wp_undo, which puts the status of before back (out of the trash and out of spam the way WordPress takes a comment out), or null with undo_note saying why, also where the comment had the status already. A status WordPress did not store at all is an error (ab_mcp_not_saved). Needs moderate_comments and the right to edit this comment (edit_comment), which follows the right to edit its post. Approving shows the comment wherever the post\'s comments are public, and WordPress may notify the post\'s author, also when an undo approves it again. Trashing a top-level note (comment_type note) also trashes its reply notes, and its undo takes them out of the trash too. Where the site\'s trash is off (EMPTY_TRASH_DAYS 0), trash deletes the comment for good, as in WordPress: the answer then carries deleted: true, undo: null and undo_note. A comment hidden because its post is in the trash (post-trashed) gets no undo point (undo: null, undo_note): no call sets that status again, and WordPress gives the comment its earlier status back when the post comes out of the trash. An unknown comment_id returns a not-found error. Deleting outright is done by wp_delete_comment.',
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
				'description' => 'Add a comment to a post as the connected user: a reply to a comment (parent) or a new top-level comment. Each call adds one, so a repeated call posts it twice. It is saved approved and shows at once wherever the post\'s comments are public, under the user\'s display name, with the user\'s email address stored; WordPress sends none of its new-comment emails. HTML is filtered as in post content. Not checked: whether comments are open, and whether parent exists on that post. Returns {created: true, comment_id}, saved_fields (post_id, parent, content and status as stored, read again; saved_differs and warning where WordPress stored something else than sent, e.g. HTML filtered out) and undo: the token for wp_undo, which deletes the comment again for good while it is unchanged, without replies and without new meta, or null with undo_note saying why. Needs moderate_comments and the right to edit the post (edit_post), as a reply from the comments screen in wp-admin. Existing comments are approved, held or deleted with wp_moderate_comment and wp_delete_comment.',
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
				'description' => 'Delete one comment: into the trash by default, for good with force=true, which also removes its meta and moves its replies up a level. A comment already in the trash or marked as spam, and any comment on a site with the trash turned off, is deleted for good even without force, as in WordPress, so a second call on the same comment deletes it for good. Trashing a top-level note (comment_type note) also trashes its reply notes. Returns {deleted: true, comment_id}; into the trash also saved_fields (the status as stored afterwards, read again) and undo: the token for wp_undo, which takes the comment out of the trash again the way WordPress does, with the reply notes that went with it, or null with undo_note saying why (also for a comment whose post is in the trash, post-trashed). Deleted for good, undo is null and undo_note says it is not undoable, so note a comment that may be needed again with wp_get_comment first. An unknown comment_id returns a not-found error. Needs moderate_comments and the right to edit this comment (edit_comment).',
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
				'description' => 'Get one term by id and taxonomy. Returns {term_id, name, slug, taxonomy, description, count, parent}; count is the number of objects WordPress counts for the term, parent is 0 at the top level. Terms of a non-public taxonomy need the right to assign them. A term that does not exist in that taxonomy returns a not-found error. A term known only by name or slug is found with wp_list_terms (search); the term\'s custom meta comes from wp_get_term_meta.',
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
		// a backslash that sanitize_title() then turns into a dash. Observed
		// (since 4.13.0): only a term this call created gets an undo point.
		list( $res, $made ) = AB_MCP_Undo_Terms::observe(
			static function () use ( $a, $tax ) {
				return wp_insert_term(
					wp_slash( self::s( $a, 'name' ) ),
					$tax,
					array(
						'slug'        => self::s( $a, 'slug', '' ),
						'description' => wp_slash( self::s( $a, 'description', '' ) ),
						'parent'      => self::i( $a, 'parent', 0 ),
					)
				);
			}
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$id = (int) $res['term_id'];
		// What was sent, as WordPress was asked to store it; the read-back
		// and an undo point that deletes the term again (since 4.13.0).
		$asked = array( 'name' => self::s( $a, 'name' ) );
		if ( '' !== self::s( $a, 'slug', '' ) ) {
			$asked['slug'] = self::s( $a, 'slug' );
		}
		if ( array_key_exists( 'description', $a ) ) {
			$asked['description'] = self::s( $a, 'description' );
		}
		if ( array_key_exists( 'parent', $a ) ) {
			$asked['parent'] = self::i( $a, 'parent' );
		}
		return array_merge(
			array(
				'created' => true,
				'term_id' => $id,
			),
			AB_MCP_Undo_Terms::created( 'wp_create_term', $id, $tax, $asked, 'wp_get_term', sprintf( 'new term #%d (%s)', $id, $tax ), $made )
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
		if ( $id <= 0 ) {
			return self::no_such_term();
		}
		// And the right to edit this one term, as wp-admin asks it (term.php,
		// edit-tags.php): edit_term goes through map_meta_cap, where a site can
		// withhold single terms from an account that may edit the others. An
		// id of no term of this taxonomy is left to wp_update_term(), which
		// refuses it and writes nothing.
		$term = self::term_of( $id, $tax );
		if ( $term && ! current_user_can( 'edit_term', $id ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot edit this specific term. Connect with an account that may edit it, such as an editor or an administrator.', 'alphabridge-mcp' ) );
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
		// The fields as sent; an empty slug asks WordPress to derive one, so
		// what it stores is no difference.
		$asked = array();
		foreach ( $fields as $k => $v ) {
			if ( 'slug' === $k && '' === $v ) {
				continue;
			}
			$asked[ $k ] = 'parent' === $k ? (int) $v : self::s( $a, $k );
		}
		// The fields before, the write, the read-back and the undo point
		// (since 4.13.0). An id of no term of this taxonomy is refused by
		// wp_update_term() before anything is kept.
		$res = AB_MCP_Undo_Terms::update(
			array(
				'tool'     => 'wp_update_term',
				'id'       => $id,
				'taxonomy' => $tax,
				'asked'    => $asked,
				'reader'   => 'wp_get_term',
				'summary'  => sprintf( 'term #%d (%s): %s', $id, $tax, implode( ', ', array_keys( $fields ) ) ),
			),
			static function () use ( $id, $tax, $fields ) {
				$done = wp_update_term( $id, $tax, $fields );
				return is_wp_error( $done ) ? $done : true;
			}
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array_merge(
			array(
				'updated' => true,
				'term_id' => $id,
			),
			$res
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
		// A number below 1 names no term, but WordPress would look it up as
		// its absolute value (term_exists()), past the check below.
		$id = self::i( $a, 'term_id' );
		if ( $id <= 0 ) {
			return self::no_such_term();
		}
		// The taxonomy's default term is kept, as wp-admin keeps it: there
		// map_meta_cap answers do_not_allow for delete_term, while
		// wp_delete_term() itself spares only some defaults (the default
		// category, a taxonomy's registered default term).
		$term = self::term_of( $id, $tax );
		if ( $term && self::is_default_term( $term ) ) {
			return self::not_deleted();
		}
		// And the right to delete this one term, as wp-admin asks it:
		// delete_term goes through map_meta_cap like edit_term above.
		if ( $term && ! current_user_can( 'delete_term', $id ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot delete this specific term. Connect with an account that may delete it, such as an editor or an administrator.', 'alphabridge-mcp' ) );
		}
		$res = wp_delete_term( $id, $tax );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! $res ) {
			return self::not_deleted();
		}
		return array(
			'deleted'   => true,
			'undo'      => null,
			'undo_note' => AB_MCP_Undo_Objects::deleted_for_good( 'wp_delete_term' ),
		);
	}

	/**
	 * The answer for a term id that names no term.
	 *
	 * @return WP_Error
	 */
	private static function no_such_term() {
		return new WP_Error( 'ab_mcp_not_found', __( 'Term not found: no term has this id. Look it up with wp_list_terms.', 'alphabridge-mcp' ) );
	}

	/**
	 * The answer when a term stays: no term of this taxonomy has the id, or
	 * it is the taxonomy's default term.
	 *
	 * @return WP_Error
	 */
	private static function not_deleted() {
		return new WP_Error( 'ab_mcp_delete_failed', __( 'WordPress did not delete the term: no term of this taxonomy has this id, or it is the taxonomy\'s default term (such as the default category), which WordPress keeps. Check the id with wp_list_terms; a default category can be deleted once another one is the default (Settings → Writing in wp-admin).', 'alphabridge-mcp' ) );
	}

	/**
	 * The term with this id, if it belongs to this taxonomy.
	 *
	 * @param int    $id  Term id.
	 * @param string $tax Taxonomy name.
	 * @return object|null
	 */
	private static function term_of( $id, $tax ) {
		$term = $id > 0 ? get_term( $id, $tax ) : null;
		return ( $term && ! is_wp_error( $term ) && $tax === $term->taxonomy ) ? $term : null;
	}

	/**
	 * Whether wp-admin treats the term as its taxonomy's default, the one no
	 * account may delete: the same two options map_meta_cap() reads for
	 * delete_term (default_{taxonomy}, such as default_category, and
	 * default_term_{taxonomy}).
	 *
	 * @param object $term Term.
	 * @return bool
	 */
	private static function is_default_term( $term ) {
		$id = (int) $term->term_id;
		return (int) get_option( 'default_' . $term->taxonomy ) === $id
			|| (int) get_option( 'default_term_' . $term->taxonomy ) === $id;
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
		// moderate_comments, which the tool is registered with, is the right
		// for comments as a whole; wp-admin approves, holds, marks spam and
		// trashes one only with edit_comment for it, which map_meta_cap ties
		// to the right to edit its post.
		if ( ! current_user_can( 'edit_comment', $id ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot moderate this specific comment. Connect with an account that may edit it, such as an editor or an administrator.', 'alphabridge-mcp' ) );
		}
		// The status asked for, as wp_get_comment shows it once stored.
		$labels = array(
			'approve' => 'approved',
			'hold'    => 'unapproved',
			'spam'    => 'spam',
			'trash'   => 'trash',
		);
		if ( ! isset( $labels[ $status ] ) ) {
			return new WP_Error( 'ab_mcp_bad_status', __( 'Status must be approve, hold, spam or trash.', 'alphabridge-mcp' ) );
		}
		$out = array(
			'moderated'  => true,
			'comment_id' => $id,
			'status'     => $status,
		);

		// With the trash turned off WordPress deletes the comment for good:
		// no way back (since 4.14.0 the answer says so). Its replies move up
		// a level, a top-level note's reply notes too: wp_trash_comment()
		// looks for them only after the note is gone.
		if ( 'trash' === $status && ! AB_MCP_Undo_Comments::trash_on() ) {
			wp_trash_comment( $id );
			if ( array() !== AB_MCP_Undo_Comments::read_back( $id ) ) {
				return self::comment_not_deleted();
			}
			return array_merge(
				$out,
				array(
					'deleted'   => true,
					'undo'      => null,
					'undo_note' => AB_MCP_Undo_Objects::deleted_for_good( 'wp_moderate_comment' ),
				)
			);
		}

		// The state before, the call, the read-back and the undo point
		// (since 4.14.0): a status WordPress did not store is an error.
		$res = AB_MCP_Undo_Comments::set_status(
			array(
				'tool'    => 'wp_moderate_comment',
				'id'      => $id,
				'want'    => $labels[ $status ],
				'summary' => sprintf( 'comment #%d: %s', $id, $status ),
				'refused' => new WP_Error(
					'ab_mcp_not_saved',
					sprintf(
						/* translators: %d: comment id */
						__( 'WordPress did not store the new status of comment %d: it still has the status it had before. A plugin may keep it from changing. Check the comment with wp_get_comment, and ask the site administrator which plugin guards it.', 'alphabridge-mcp' ),
						$id
					)
				),
			),
			static function () use ( $id, $status ) {
				if ( 'spam' === $status ) {
					return wp_spam_comment( $id );
				}
				if ( 'trash' === $status ) {
					return wp_trash_comment( $id );
				}
				return wp_set_comment_status( $id, $status );
			}
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array_merge( $out, $res );
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
		// The reply is saved approved, as a reply from the comments screen in
		// wp-admin, which takes the right to edit the post it goes under.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot reply under this specific post: a reply from here takes the right to edit the post, as in wp-admin. Connect with an account that may edit it, such as an editor or an administrator.', 'alphabridge-mcp' ) );
		}
		$user    = wp_get_current_user();
		$parent  = self::i( $a, 'parent', 0 );
		$content = wp_kses_post( self::s( $a, 'content' ) );
		// wp_insert_comment() unslashes the whole array it is given.
		$id = wp_insert_comment(
			wp_slash( array(
				'comment_post_ID'      => $post_id,
				'comment_parent'       => $parent,
				'comment_content'      => $content,
				'user_id'              => $user->ID,
				'comment_author'       => $user->display_name,
				'comment_author_email' => $user->user_email,
				'comment_approved'     => 1,
			) )
		);
		if ( ! $id ) {
			return new WP_Error( 'ab_mcp_comment_failed', __( 'WordPress did not save the comment, so nothing was written. Try again; if it keeps failing, ask the site administrator to check the database.', 'alphabridge-mcp' ) );
		}
		// What WordPress was asked to store, the read-back and an undo point
		// that deletes the comment again (since 4.14.0).
		$asked = array(
			'post_id' => $post_id,
			'parent'  => $parent,
			'content' => $content,
			'status'  => 'approved',
		);
		return array_merge(
			array(
				'created'    => true,
				'comment_id' => (int) $id,
			),
			AB_MCP_Undo_Comments::created( (int) $id, $asked, sprintf( 'new comment #%d on post #%d', (int) $id, $post_id ) )
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
		// As moderate_comment() above: wp-admin deletes one comment only with
		// edit_comment for it.
		if ( ! current_user_can( 'edit_comment', $id ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot delete this specific comment. Connect with an account that may edit it, such as an editor or an administrator.', 'alphabridge-mcp' ) );
		}
		// For good, as wp_delete_comment() decides it: with force, with the
		// trash turned off, and for a comment in the trash or in spam. No way
		// back, and the answer says so (since 4.14.0). Nothing promised about
		// what is left: WordPress moves replies up and removes the comment's
		// meta before its row.
		$force = self::b( $a, 'force', false );
		$was   = (string) ( get_comment( $id )->comment_approved ?? '' );
		if ( $force || ! AB_MCP_Undo_Comments::trash_on() || in_array( $was, array( 'trash', 'spam' ), true ) ) {
			if ( ! wp_delete_comment( $id, $force ) ) {
				return self::comment_not_deleted();
			}
			return array(
				'deleted'    => true,
				'comment_id' => $id,
				'undo'       => null,
				'undo_note'  => AB_MCP_Undo_Objects::deleted_for_good( 'wp_delete_comment' ),
			);
		}
		// Into the trash, with an undo point that takes it out again, and
		// the reply notes of a top-level note with it (since 4.14.0).
		// Trashing a note, WordPress says false when a reply note stays out
		// of the trash; what did go is in the point all the same.
		$res = AB_MCP_Undo_Comments::set_status(
			array(
				'tool'    => 'wp_delete_comment',
				'id'      => $id,
				'want'    => 'trash',
				'summary' => sprintf( 'comment #%d: into the trash', $id ),
				'refused' => self::comment_not_deleted(),
			),
			static function () use ( $id ) {
				return wp_delete_comment( $id, false );
			}
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array_merge(
			array(
				'deleted'    => true,
				'comment_id' => $id,
			),
			$res
		);
	}

	/**
	 * The answer when WordPress kept a comment it was asked to delete.
	 *
	 * @return WP_Error
	 */
	private static function comment_not_deleted() {
		return new WP_Error( 'ab_mcp_delete_failed', __( 'WordPress did not delete the comment; a plugin may have stopped it. Check it with wp_get_comment and try again.', 'alphabridge-mcp' ) );
	}
}
