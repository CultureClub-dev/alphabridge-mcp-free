<?php
/**
 * Content tools: posts, pages, custom post types and revisions.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tools-base.php';

/**
 * Class AB_MCP_Tools_Content
 */
class AB_MCP_Tools_Content extends AB_MCP_Tools_Base {

	/**
	 * Register tools.
	 *
	 * @param AB_MCP_Tool_Registry $r Registry.
	 */
	public static function register( AB_MCP_Tool_Registry $r ) {

		$r->register(
			'wp_list_posts',
			array(
				'description' => 'List or search posts/pages/any post type with filters and pagination.',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'type'     => array(
							'type'        => 'string',
							'description' => 'Post type (post, page, any, or a custom type). Default "post".',
						),
						'status'   => array(
							'type'        => 'string',
							'description' => 'Post status (publish, draft, pending, private, any). Default "any".',
						),
						'search'   => array( 'type' => 'string', 'description' => 'Keyword search.' ),
						'author'   => array( 'type' => 'integer', 'description' => 'Author user id.' ),
						'parent'   => array( 'type' => 'integer', 'description' => 'Parent post id.' ),
						'per_page' => array( 'type' => 'integer', 'description' => 'Items per page (max 100). Default 20.' ),
						'page'     => array( 'type' => 'integer', 'description' => 'Page number. Default 1.' ),
						'orderby'  => array( 'type' => 'string', 'description' => 'date, title, modified, menu_order, ID.' ),
						'order'    => array( 'type' => 'string', 'description' => 'ASC or DESC. Default DESC.' ),
					),
				),
				'callback'    => array( __CLASS__, 'list_posts' ),
			)
		);

		$r->register(
			'wp_get_post',
			array(
				'description' => 'Get a single post/page including raw content, meta and terms.',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id'           => array( 'type' => 'integer', 'description' => 'Post id.' ),
						'include_meta' => array( 'type' => 'boolean', 'description' => 'Include post meta. Default true.' ),
					),
				),
				'callback'    => array( __CLASS__, 'get_post' ),
			)
		);

		$r->register(
			'wp_create_post',
			array(
				'description' => 'Create a post, page or custom post type entry (title, content, status, terms, meta).',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'type'    => array( 'type' => 'string', 'description' => 'Post type. Default "post".' ),
						'title'   => array( 'type' => 'string', 'description' => 'Title.' ),
						'content' => array( 'type' => 'string', 'description' => 'Content (HTML or block markup).' ),
						'status'  => array( 'type' => 'string', 'description' => 'draft, publish, pending, private. Default "draft".' ),
						'excerpt' => array( 'type' => 'string' ),
						'slug'    => array( 'type' => 'string' ),
						'author'  => array( 'type' => 'integer' ),
						'parent'  => array( 'type' => 'integer' ),
						'date'    => array( 'type' => 'string', 'description' => 'Publish date (Y-m-d H:i:s).' ),
						'meta'    => array( 'type' => 'object', 'description' => 'Key/value meta to set.' ),
						'terms'   => array( 'type' => 'object', 'description' => 'Taxonomy => array of term ids or names, e.g. {"category":["News"]}.' ),
					),
				),
				'callback'    => array( __CLASS__, 'create_post' ),
			)
		);

		$r->register(
			'wp_update_post',
			array(
				'description' => 'Update an existing post/page. Only provided fields change.',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id'      => array( 'type' => 'integer', 'description' => 'Post id.' ),
						'title'   => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
						'status'  => array( 'type' => 'string' ),
						'excerpt' => array( 'type' => 'string' ),
						'slug'    => array( 'type' => 'string' ),
						'author'  => array( 'type' => 'integer' ),
						'parent'  => array( 'type' => 'integer' ),
						'meta'    => array( 'type' => 'object' ),
						'terms'   => array( 'type' => 'object' ),
					),
				),
				'callback'    => array( __CLASS__, 'update_post' ),
			)
		);

		$r->register(
			'wp_delete_post',
			array(
				'description' => 'Delete a post (trash by default, or permanently with force=true).',
				'capability'  => 'delete_posts',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id'    => array( 'type' => 'integer' ),
						'force' => array( 'type' => 'boolean', 'description' => 'Permanently delete instead of trashing.' ),
					),
				),
				'callback'    => array( __CLASS__, 'delete_post' ),
			)
		);

		$r->register(
			'wp_list_revisions',
			array(
				'description' => 'List revisions of a post.',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array( 'id' => array( 'type' => 'integer' ) ),
				),
				'callback'    => array( __CLASS__, 'list_revisions' ),
			)
		);

		$r->register(
			'wp_duplicate_post',
			array(
				'description' => 'Duplicate a post/page (as a new draft, including its terms).',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array( 'id' => array( 'type' => 'integer' ) ),
				),
				'callback'    => array( __CLASS__, 'duplicate_post' ),
			)
		);

		$r->register(
			'wp_get_post_meta',
			array(
				'description' => 'Get post meta: one key, or all public meta for a post.',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id'  => array( 'type' => 'integer' ),
						'key' => array( 'type' => 'string', 'description' => 'Optional single meta key.' ),
					),
				),
				'callback'    => array( __CLASS__, 'get_post_meta_tool' ),
			)
		);

	}

	/* --------------------------------------------------------------- handlers */

	/**
	 * Duplicate a post as a draft.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function duplicate_post( $a ) {
		$need = self::need( $a, array( 'id' ) );
		if ( is_wp_error( $need ) ) {
			return $need;
		}
		$src = get_post( self::i( $a, 'id' ) );
		if ( ! $src ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Post not found.', 'alphabridge-mcp' ) );
		}
		if ( ! current_user_can( 'read_post', $src->ID ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot read this specific post.', 'alphabridge-mcp' ) );
		}
		$pto = get_post_type_object( $src->post_type );
		if ( ! $pto || ! current_user_can( $pto->cap->create_posts ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot create entries of this post type.', 'alphabridge-mcp' ) );
		}
		// The source values come out of the database unslashed, and
		// wp_insert_post() expects them slashed — without this a copy of a post
		// whose title holds a backslash loses it.
		$id = wp_insert_post(
			wp_slash( array(
				'post_type'    => $src->post_type,
				'post_title'   => $src->post_title . ' (Copy)',
				'post_content' => $src->post_content,
				'post_excerpt' => $src->post_excerpt,
				'post_status'  => 'draft',
				'post_parent'  => $src->post_parent,
				'menu_order'   => $src->menu_order,
			),
			true
		) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		foreach ( get_object_taxonomies( $src->post_type ) as $tax ) {
			$tax_obj = get_taxonomy( $tax );
			if ( ! $tax_obj || ! current_user_can( $tax_obj->cap->assign_terms ) ) {
				continue; // Skip taxonomies this user may not assign.
			}
			$terms = wp_get_object_terms( $src->ID, $tax, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) ) {
				wp_set_object_terms( $id, $terms, $tax, false );
			}
		}
		return array(
			'duplicated' => true,
			'new_id'     => (int) $id,
		);
	}

	/**
	 * Get post meta.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function get_post_meta_tool( $a ) {
		$need = self::need( $a, array( 'id' ) );
		if ( is_wp_error( $need ) ) {
			return $need;
		}
		$id = self::i( $a, 'id' );
		if ( ! get_post( $id ) ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Post not found.', 'alphabridge-mcp' ) );
		}
		if ( ! current_user_can( 'read_post', $id ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot read this specific post.', 'alphabridge-mcp' ) );
		}
		$key = self::s( $a, 'key', '' );
		if ( '' !== $key ) {
			if ( '_' === substr( $key, 0, 1 ) || is_protected_meta( $key, 'post' ) || self::is_sensitive_meta_key( $key ) ) {
				return new WP_Error( 'ab_mcp_protected_meta', __( 'This meta key is protected.', 'alphabridge-mcp' ) );
			}
			// Deliberately strict read rule: exposing a key over MCP requires the
			// key's edit capability, which honours auth_callback rules that other
			// plugins registered via register_meta().
			if ( ! current_user_can( 'edit_post_meta', $id, $key ) ) {
				return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot access this meta key.', 'alphabridge-mcp' ) );
			}
			return array(
				'id'    => $id,
				'key'   => $key,
				'value' => get_post_meta( $id, $key, true ),
			);
		}
		$all  = get_post_meta( $id );
		$flat = array();
		foreach ( $all as $k => $v ) {
			if ( '_' === substr( $k, 0, 1 ) || is_protected_meta( $k, 'post' ) || self::is_sensitive_meta_key( $k )
				|| ! current_user_can( 'edit_post_meta', $id, $k ) ) {
				continue;
			}
			$flat[ $k ] = count( $v ) === 1 ? maybe_unserialize( $v[0] ) : array_map( 'maybe_unserialize', $v );
		}
		return array(
			'id'   => $id,
			'meta' => $flat,
		);
	}

	/**
	 * List posts.
	 *
	 * @param array $a Args.
	 * @return array
	 */
	public static function list_posts( $a ) {
		$per_page = self::clamp( self::i( $a, 'per_page', 20 ), 1, 100 );
		$query    = new WP_Query(
			array(
				'post_type'      => self::s( $a, 'type', 'post' ) ?: 'post',
				'post_status'    => self::s( $a, 'status', 'any' ) ?: 'any',
				'perm'           => 'readable',
				's'              => self::s( $a, 'search', '' ),
				'author'         => self::i( $a, 'author', 0 ) ?: '',
				'post_parent'    => isset( $a['parent'] ) ? self::i( $a, 'parent' ) : null,
				'posts_per_page' => $per_page,
				'paged'          => max( 1, self::i( $a, 'page', 1 ) ),
				'orderby'        => self::s( $a, 'orderby', 'date' ) ?: 'date',
				'order'          => strtoupper( self::s( $a, 'order', 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC',
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			// Objektbezogener Filter: fremde Drafts/private Posts nicht ausliefern.
			if ( ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}
			$items[] = self::post_summary( $post );
		}

		return array(
			'items'      => $items,
			'pagination' => array(
				'page'        => max( 1, self::i( $a, 'page', 1 ) ),
				'per_page'    => $per_page,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
			),
		);
	}

	/**
	 * Get one post.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function get_post( $a ) {
		$need = self::need( $a, array( 'id' ) );
		if ( is_wp_error( $need ) ) {
			return $need;
		}
		$post = get_post( self::i( $a, 'id' ) );
		if ( ! $post ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Post not found.', 'alphabridge-mcp' ) );
		}
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot read this specific post.', 'alphabridge-mcp' ) );
		}

		$data            = self::post_summary( $post );
		$data['content'] = $post->post_content;

		if ( self::b( $a, 'include_meta', true ) ) {
			$meta = get_post_meta( $post->ID );
			$flat = array();
			foreach ( $meta as $key => $vals ) {
				if ( '_' === substr( $key, 0, 1 ) || is_protected_meta( $key, 'post' ) || self::is_sensitive_meta_key( $key )
					|| ! current_user_can( 'edit_post_meta', $post->ID, $key ) ) {
					continue; // Skip protected meta and keys the user may not access.
				}
				$flat[ $key ] = count( $vals ) === 1 ? maybe_unserialize( $vals[0] ) : array_map( 'maybe_unserialize', $vals );
			}
			$data['meta'] = $flat;
		}

		$taxes = get_object_taxonomies( $post->post_type );
		$terms = array();
		foreach ( $taxes as $tax ) {
			// Skip non-public taxonomies the user may not read.
			if ( ! self::can_read_taxonomy( get_taxonomy( $tax ) ) ) {
				continue;
			}
			$t = wp_get_object_terms( $post->ID, $tax, array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $t ) && $t ) {
				$terms[ $tax ] = $t;
			}
		}
		$data['terms'] = $terms;

		return $data;
	}

	/**
	 * Create post.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function create_post( $a ) {
		$type = self::s( $a, 'type', 'post' ) ?: 'post';
		$pto  = get_post_type_object( $type );
		if ( ! $pto ) {
			return new WP_Error( 'ab_mcp_invalid_type', __( 'Unknown post type.', 'alphabridge-mcp' ) );
		}
		if ( ! current_user_can( $pto->cap->create_posts ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot create entries of this post type.', 'alphabridge-mcp' ) );
		}

		$status = self::s( $a, 'status', 'draft' ) ?: 'draft';
		if ( ! self::is_allowed_status( $status ) ) {
			return new WP_Error( 'ab_mcp_invalid_status', __( 'Unsupported post status.', 'alphabridge-mcp' ) );
		}
		// publish, private and future all require the post type's publish capability.
		if ( in_array( $status, array( 'publish', 'private', 'future' ), true ) && ! current_user_can( $pto->cap->publish_posts ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'You cannot publish; use status "draft" or "pending".', 'alphabridge-mcp' ) );
		}

		$postarr = array(
			'post_type'    => $type,
			'post_title'   => self::s( $a, 'title', '' ),
			'post_content' => self::s( $a, 'content', '' ),
			'post_status'  => $status,
			'post_excerpt' => self::s( $a, 'excerpt', '' ),
			'post_name'    => self::s( $a, 'slug', '' ),
			'post_parent'  => self::i( $a, 'parent', 0 ),
		);
		if ( self::i( $a, 'author', 0 ) && current_user_can( $pto->cap->edit_others_posts ) ) {
			$postarr['post_author'] = self::i( $a, 'author' );
		}
		if ( self::s( $a, 'date', '' ) ) {
			$postarr['post_date'] = self::s( $a, 'date' );
		}

		// Slashed on the way in. WordPress unslashes what it is given here
		// ("Expected_slashed (everything!)" says wp_insert_post itself), so
		// handing it raw data stores a backslash-bearing value mangled:
		// "C:\Docs" becomes "C:Docs".
		$id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		self::apply_meta_and_terms( $id, $a );

		return array(
			'created' => true,
			'post'    => self::post_summary( get_post( $id ) ),
		);
	}

	/**
	 * Update post.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function update_post( $a ) {
		$need = self::need( $a, array( 'id' ) );
		if ( is_wp_error( $need ) ) {
			return $need;
		}
		$id = self::i( $a, 'id' );
		if ( ! get_post( $id ) ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Post not found.', 'alphabridge-mcp' ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot edit this specific post.', 'alphabridge-mcp' ) );
		}

		$post = get_post( $id );
		$pto  = get_post_type_object( $post->post_type );

		if ( isset( $a['status'] ) ) {
			$new_status = (string) $a['status'];
			if ( ! self::is_allowed_status( $new_status ) ) {
				return new WP_Error( 'ab_mcp_invalid_status', __( 'Unsupported post status.', 'alphabridge-mcp' ) );
			}
			// Moving a post to publish, private or future needs the publish capability.
			if ( in_array( $new_status, array( 'publish', 'private', 'future' ), true )
				&& $new_status !== $post->post_status
				&& ( ! $pto || ! current_user_can( $pto->cap->publish_posts ) ) ) {
				return new WP_Error( 'ab_mcp_forbidden', __( 'You cannot publish; use status "draft" or "pending".', 'alphabridge-mcp' ) );
			}
		}

		$postarr = array( 'ID' => $id );
		$map     = array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'status'  => 'post_status',
			'excerpt' => 'post_excerpt',
			'slug'    => 'post_name',
			'parent'  => 'post_parent',
			'author'  => 'post_author',
		);
		foreach ( $map as $in => $out ) {
			if ( array_key_exists( $in, $a ) ) {
				$postarr[ $out ] = is_scalar( $a[ $in ] ) ? $a[ $in ] : '';
			}
		}
		// Only users who can edit others' posts of this type may reassign authorship.
		if ( isset( $postarr['post_author'] ) && ( ! $pto || ! current_user_can( $pto->cap->edit_others_posts ) ) ) {
			unset( $postarr['post_author'] );
		}

		// Same rule as create: wp_update_post() hands this straight to
		// wp_insert_post(), which unslashes it.
		$res = wp_update_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		self::apply_meta_and_terms( $id, $a );

		return array(
			'updated' => true,
			'post'    => self::post_summary( get_post( $id ) ),
		);
	}

	/**
	 * Delete post.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function delete_post( $a ) {
		$need = self::need( $a, array( 'id' ) );
		if ( is_wp_error( $need ) ) {
			return $need;
		}
		$id = self::i( $a, 'id' );
		if ( ! get_post( $id ) ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Post not found.', 'alphabridge-mcp' ) );
		}
		if ( ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot delete this specific post.', 'alphabridge-mcp' ) );
		}
		$force = self::b( $a, 'force', false );
		$res   = wp_delete_post( $id, $force );
		if ( ! $res ) {
			return new WP_Error( 'ab_mcp_delete_failed', __( 'Delete failed.', 'alphabridge-mcp' ) );
		}
		return array(
			'deleted'   => true,
			'permanent' => $force,
			'id'        => $id,
		);
	}

	/**
	 * List revisions.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function list_revisions( $a ) {
		$need = self::need( $a, array( 'id' ) );
		if ( is_wp_error( $need ) ) {
			return $need;
		}
		$post = get_post( self::i( $a, 'id' ) );
		if ( ! $post ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Post not found.', 'alphabridge-mcp' ) );
		}
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot read this specific post.', 'alphabridge-mcp' ) );
		}
		$revs = wp_get_post_revisions( $post->ID );
		$out  = array();
		foreach ( $revs as $rev ) {
			$out[] = array(
				'revision_id' => (int) $rev->ID,
				'date'        => $rev->post_modified_gmt,
				'author'      => (int) $rev->post_author,
			);
		}
		return array( 'revisions' => $out );
	}

	/* --------------------------------------------------------------- internal */

	/**
	 * Apply meta and terms from args to a post. Protected meta keys are
	 * skipped; term assignment/creation respects the taxonomy's own
	 * capabilities (assign_terms / manage_terms).
	 *
	 * @param int   $id Post id.
	 * @param array $a  Args.
	 */
	private static function apply_meta_and_terms( $id, $a ) {
		$meta = self::arr( $a, 'meta', array() );
		foreach ( $meta as $key => $value ) {
			// Sanitize first, then screen the key that will actually be stored,
			// so key normalisation cannot smuggle a protected/credential name past.
			$clean = sanitize_key( (string) $key );
			if ( '' === $clean || '_' === substr( $clean, 0, 1 ) || is_protected_meta( $clean, 'post' )
				|| self::is_sensitive_meta_key( $clean ) || ! self::safe_value( $value ) ) {
				continue; // Skip empty, protected, credential-like and unsafe (object) values.
			}
			// WordPress meta capability: routes through map_meta_cap and thereby
			// honours any auth_callback another plugin registered for this key.
			if ( ! current_user_can( 'edit_post_meta', $id, $clean ) ) {
				continue;
			}
			// update_metadata() unslashes key and value; the key is already
			// sanitize_key()'d above, the value is not.
			update_post_meta( $id, $clean, wp_slash( $value ) );
		}

		$terms = self::arr( $a, 'terms', array() );
		foreach ( $terms as $tax => $list ) {
			if ( ! taxonomy_exists( $tax ) || ! is_array( $list ) ) {
				continue;
			}
			$tax_obj = get_taxonomy( $tax );
			if ( ! $tax_obj || ! current_user_can( $tax_obj->cap->assign_terms ) ) {
				continue; // User may not assign terms of this taxonomy.
			}
			$ids = array();
			foreach ( $list as $t ) {
				if ( is_numeric( $t ) ) {
					$ids[] = (int) $t;
				} else {
					$term = term_exists( $t, $tax );
					if ( ! $term ) {
						// Creating a NEW term needs manage_terms on top of assign_terms.
						if ( ! current_user_can( $tax_obj->cap->manage_terms ) ) {
							continue;
						}
						// wp_insert_term() unslashes the name.
						$term = wp_insert_term( wp_slash( $t ), $tax );
					}
					if ( ! is_wp_error( $term ) && isset( $term['term_id'] ) ) {
						$ids[] = (int) $term['term_id'];
					}
				}
			}
			wp_set_object_terms( $id, $ids, $tax, false );
		}
	}

	/**
	 * Allow only scalars or (nested) arrays of scalars; reject objects/resources.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	protected static function safe_value( $value ) {
		if ( is_scalar( $value ) || null === $value ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $v ) {
				if ( ! self::safe_value( $v ) ) {
					return false;
				}
			}
			return true;
		}
		return false;
	}

	/**
	 * Post statuses a client may set via create/update. Registered custom
	 * statuses are honoured; anything else (including internal statuses like
	 * "trash", "auto-draft" or "inherit") is rejected.
	 *
	 * @param string $status Status slug.
	 * @return bool
	 */
	protected static function is_allowed_status( $status ) {
		/**
		 * Post statuses settable through the MCP tools. Deliberately ONLY the
		 * built-in editorial statuses: custom statuses registered by other
		 * plugins often carry their own workflow/permission semantics that
		 * WordPress exposes no universal capability for, so they are rejected
		 * by default. Site owners who understand a custom status's semantics
		 * can add it here (additively) at their own responsibility.
		 *
		 * @param string[] $allowed Allowed status slugs.
		 */
		$allowed = (array) apply_filters(
			'ab_mcp_allowed_post_statuses',
			array( 'draft', 'pending', 'publish', 'private', 'future' )
		);
		return in_array( (string) $status, $allowed, true );
	}
}
