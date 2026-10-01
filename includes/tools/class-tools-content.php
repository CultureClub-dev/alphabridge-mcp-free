<?php
/**
 * Content tools: posts, pages, custom post types and revisions.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tools-base.php';
require_once dirname( __DIR__ ) . '/builders/class-builders.php';
require_once __DIR__ . '/class-duplicate-meta.php';

/**
 * Class AB_MCP_Tools_Content
 */
class AB_MCP_Tools_Content extends AB_MCP_Tools_Base {

	/**
	 * The least lead a post is scheduled with, in seconds. WordPress publishes
	 * a "future" post at once whenever it is saved less than a minute before
	 * its date. A schedule closer than a few minutes could be lost that way to
	 * a save still running a minute later, or to a second save another plugin
	 * makes of the same post while this one is written. Five minutes leave room
	 * for either.
	 */
	const SCHEDULE_LEAD = 300;

	/**
	 * The date field of wp_update_post, shared with an edition that registers
	 * the tool again, so both describe the same rules.
	 */
	const UPDATE_DATE_DESCRIPTION = 'New publish date. Site time as "Y-m-d H:i:s" (or "Y-m-d H:i", "Y-m-d"), or RFC 3339 with an offset, the form of the dates this plugin returns, e.g. "2026-10-02T09:00:00+02:00". Times that do not exist in the site\'s timezone (clocks skip them) are refused, as is a time where the clocks go back that WordPress would read as the other of the two. A date alone never changes the status: a save that would publish the post or take it off the site is refused unless that status is passed as well, and a post is scheduled only for a date at least five minutes ahead.';

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
						'built_with' => array( 'type' => 'string', 'description' => 'Only posts built with this page builder: a builder id as wp_get_post names it in built_with (e.g. "elementor", "kadence", "wpbakery"), "any" for every known builder, or "none" for posts without one. Matched by the builder\'s markers (meta keys, content patterns); each listed item then names the builder found on it.' ),
					),
				),
				'callback'    => array( __CLASS__, 'list_posts' ),
			)
		);

		$r->register(
			'wp_get_post',
			array(
				'description' => 'Get a single post/page including raw content, meta and terms. For a post built with a page builder or block library, built_with names the builder, how the page is stored and the tools that change it (write_via); where post_content is not what the site shows, its note says so.',
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
						'status'  => array( 'type' => 'string', 'description' => 'draft, publish, pending, private, future. Default "draft". "future" needs a date at least five minutes ahead; "publish" with such a date schedules the post, as in WordPress.' ),
						'excerpt' => array( 'type' => 'string' ),
						'slug'    => array( 'type' => 'string' ),
						'author'  => array( 'type' => 'integer' ),
						'parent'  => array( 'type' => 'integer' ),
						'date'    => array( 'type' => 'string', 'description' => 'Publish date. Site time as "Y-m-d H:i:s" (or "Y-m-d H:i", "Y-m-d"), or RFC 3339 with an offset, the form of the dates this plugin returns, e.g. "2026-10-02T09:00:00+02:00". Times that do not exist in the site\'s timezone (clocks skip them) are refused, as is a time where the clocks go back that WordPress would read as the other of the two.' ),
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
				'description' => 'Update an existing post/page. Only provided fields change. On a page whose page builder shows its own data, post_content is only a copy: a change to content is refused there, with the way to make it, while title, status, excerpt and the other fields still change. Read such pages with wp_get_builder_layout.',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id'      => array( 'type' => 'integer', 'description' => 'Post id.' ),
						'title'   => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
						'status'  => array( 'type' => 'string', 'description' => 'draft, publish, pending, private, future. "future" needs a date at least five minutes ahead.' ),
						'excerpt' => array( 'type' => 'string' ),
						'slug'    => array( 'type' => 'string' ),
						'author'  => array( 'type' => 'integer' ),
						'parent'  => array( 'type' => 'integer' ),
						'date'    => array( 'type' => 'string', 'description' => self::UPDATE_DATE_DESCRIPTION ),
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
				'description' => 'Delete a post: into the trash by default, for good with force=true. Files are deleted with wp_delete_media (or force=true): the media library has no trash unless a site turns it on.',
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
				'description' => 'Duplicate a post or page as a new draft: title, content, excerpt, parent, menu order and terms, and, for an account that may edit the original, its custom fields, featured image, page template and page builder layout, so a page built with Elementor, Beaver Builder, SiteOrigin or another builder that stores its layout in post meta stays a builder page. Elementor element ids are renewed, so copy and original do not share them. Page builder data holds markup and is copied only for accounts with the unfiltered_html capability. A builder\'s keys, its page template included, are copied together or not at all. Never copied: credential-like keys, the original\'s editing state (edit lock, last editor, former slugs, trash data), caches the builder rebuilds, protected keys of other plugins, and the meta of a revision, which WordPress would write to the post the revision belongs to. The response lists the copied keys and the skipped ones with the reason, and names the builder the original is built with.',
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
		// A copy of a file would have no file behind it, and "draft" makes it
		// "inherit": its title, caption and description would be as visible as
		// its parent — a private file's included.
		if ( 'attachment' === $src->post_type ) {
			return new WP_Error( 'ab_mcp_invalid_type', __( 'Files cannot be duplicated: the copy would have no file, and its title, caption and description would follow the parent\'s visibility. Upload the file again with wp_upload_media instead.', 'alphabridge-mcp' ) );
		}
		// The copy would carry the text into a draft that anyone with the
		// draft's rights can read — the password would be gone.
		if ( ! self::raw_content_allowed( $src ) ) {
			return self::raw_content_error( $src );
		}
		$pto = get_post_type_object( $src->post_type );
		if ( ! $pto || ! current_user_can( $pto->cap->create_posts ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot create entries of this post type.', 'alphabridge-mcp' ) );
		}
		// post_content_filtered holds what SeedProd's editor opens, as JSON
		// (measured 30.09.2026). WordPress runs it through kses for an account
		// without unfiltered_html, which breaks JSON, so it goes with the page
		// builder data, under the same rights.
		$filtered      = (string) ( $src->post_content_filtered ?? '' );
		$copy_filtered = '' !== $filtered && current_user_can( 'edit_post', $src->ID ) && current_user_can( 'unfiltered_html' );
		// The source values come out of the database unslashed, and
		// wp_insert_post() expects them slashed — without this a copy of a post
		// whose title holds a backslash loses it. With $wp_error, so a failed
		// insert is reported: the flag used to sit inside wp_slash(), and a
		// failure came back as a copy with the id 0.
		$id = wp_insert_post(
			wp_slash( array(
				'post_type'             => $src->post_type,
				'post_title'            => $src->post_title . ' (Copy)',
				'post_content'          => $src->post_content,
				'post_content_filtered' => $copy_filtered ? $filtered : '',
				'post_excerpt'          => $src->post_excerpt,
				'post_status'           => 'draft',
				'post_parent'           => $src->post_parent,
				'menu_order'            => $src->menu_order,
			) ),
			true
		);
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
		// Custom fields, builder layout, page template and featured image. The
		// read right above is enough for the copy of the post itself, as
		// before; the meta needs the right to edit the original
		// (AB_MCP_Duplicate_Meta::copy()).
		$out = array_merge(
			array(
				'duplicated' => true,
				'new_id'     => (int) $id,
			),
			AB_MCP_Duplicate_Meta::copy( $src, (int) $id )
		);
		if ( '' !== $filtered && ! $copy_filtered ) {
			$out['notes'][] = AB_MCP_Duplicate_Meta::unfiltered_html_disallowed()
				? __( 'The original\'s post_content_filtered (where SeedProd, for one, keeps its layout) was not copied: like page builder data, it needs the unfiltered_html capability, which this site gives to no account, administrators included (DISALLOW_UNFILTERED_HTML in wp-config.php). Where the page builder offers its own copy or template function in wp-admin, use that.', 'alphabridge-mcp' )
				: __( 'The original\'s post_content_filtered (where SeedProd, for one, keeps its layout) was not copied: like page builder data, it needs the right to edit the original and the unfiltered_html capability. Run wp_duplicate_post with such an account to copy it.', 'alphabridge-mcp' );
		}
		return $out;
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
		// Revisions can be listed for one post at a time, by someone who may
		// edit it: a wider query would still count them in `total` for
		// everyone else, and a count of matching revisions is a leak of the
		// text. The type is normalised the way WP_Query normalises it.
		if ( 'revision' === sanitize_key( self::s( $a, 'type', 'post' ) ) ) {
			$parent = isset( $a['parent'] ) ? self::i( $a, 'parent' ) : 0;
			if ( $parent <= 0 || ! current_user_can( 'edit_post', $parent ) ) {
				return new WP_Error( 'ab_mcp_forbidden', __( 'Revisions are listed per post, and only for an account that may edit that post: pass its id as parent.', 'alphabridge-mcp' ) );
			}
		}
		$per_page = self::clamp( self::i( $a, 'per_page', 20 ), 1, 100 );
		$order    = strtoupper( self::s( $a, 'order', 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC';
		$args     = array(
			'post_type'      => self::s( $a, 'type', 'post' ) ?: 'post',
			'post_status'    => self::s( $a, 'status', 'any' ) ?: 'any',
			'perm'           => 'readable',
			's'              => self::s( $a, 'search', '' ),
			'author'         => self::i( $a, 'author', 0 ) ?: '',
			'post_parent'    => isset( $a['parent'] ) ? self::i( $a, 'parent' ) : null,
			'posts_per_page' => $per_page,
			'paged'          => max( 1, self::i( $a, 'page', 1 ) ),
			'orderby'        => self::paged_orderby( self::s( $a, 'orderby', 'date' ) ?: 'date', $order ),
			'order'          => $order,
		);

		// built_with narrows the query in SQL, so total and pages count only
		// matching posts. The condition is added to this one query alone: the
		// WHERE filter looks for its own query var and is removed right after.
		$built  = self::s( $a, 'built_with', '' );
		$filter = null;
		if ( '' !== $built ) {
			$known = array_merge( array( 'any', 'none' ), AB_MCP_Builders::builder_ids() );
			if ( ! in_array( $built, $known, true ) ) {
				return new WP_Error(
					'ab_mcp_invalid_builder',
					/* translators: %s: comma-separated list of accepted values */
					sprintf( __( 'Unknown built_with value. Use one of: %s.', 'alphabridge-mcp' ), implode( ', ', $known ) )
				);
			}
			global $wpdb;
			$where = AB_MCP_Builders::list_where( $built, $wpdb );
			if ( null === $where ) {
				return new WP_Error( 'ab_mcp_db_unavailable', __( 'The built_with filter needs the database, which is not available here. List without built_with and read each post\'s built_with with wp_get_post.', 'alphabridge-mcp' ) );
			}
			$args['ab_mcp_built_with'] = $built;
			$filter = static function ( $sql, $query = null ) use ( $built, $where ) {
				if ( is_object( $query ) && method_exists( $query, 'get' ) && $built === $query->get( 'ab_mcp_built_with' ) ) {
					$sql .= ' AND ' . $where;
				}
				return $sql;
			};
			add_filter( 'posts_where', $filter, 10, 2 );
		}
		try {
			$query = new WP_Query( $args );
		} finally {
			if ( null !== $filter ) {
				remove_filter( 'posts_where', $filter, 10 );
			}
		}

		$items = array();
		foreach ( $query->posts as $post ) {
			// With the gate above, a revision the caller may not edit can only
			// get here through a third-party query filter; the entry is withheld
			// regardless. The counts are WordPress' own and are not corrected —
			// a filter that injects other people's revisions into queries has
			// already decided to reveal them.
			if ( 'revision' === $post->post_type && ! self::raw_content_allowed( $post ) ) {
				continue;
			}
			// Objektbezogener Filter: fremde Drafts/private Posts nicht ausliefern.
			if ( ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}
			$item = self::post_summary( $post );
			if ( '' !== $built ) {
				$primary            = AB_MCP_Builders::primary( $post );
				$item['built_with'] = null !== $primary ? $primary['id'] : null;
			}
			$items[] = $item;
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

		if ( ! self::raw_content_allowed( $post ) ) {
			return self::raw_content_error( $post );
		}

		$data            = self::post_summary( $post );
		$data['content'] = $post->post_content;
		// Which page builder the post is built with, and — where post_content
		// is not what the site shows — a note pointing to the outline.
		$built = AB_MCP_Builders::built_with( $post );
		if ( null !== $built ) {
			$data['built_with'] = $built;
		}

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
		// A file created here would have no file behind it: an attachment page
		// and a REST entry with whatever text was given — and "draft" stored as
		// "inherit", public without a parent. Files come with the upload tools.
		if ( 'attachment' === $type ) {
			return new WP_Error( 'ab_mcp_invalid_type', __( 'Files are added with wp_upload_media or wp_upload_media_from_url; wp_create_post would make an attachment without a file.', 'alphabridge-mcp' ) );
		}
		// A parent given as a list would reach WordPress as 1, an unrelated post.
		if ( array_key_exists( 'parent', $a ) && null !== $a['parent'] && ! is_scalar( $a['parent'] ) ) {
			return new WP_Error( 'ab_mcp_invalid_parent', __( 'The parent must be a post id.', 'alphabridge-mcp' ) );
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
		// One reading of the clock for the whole save. Without a date, a post
		// saved as "publish" or "future" is dated here, both columns from that
		// reading, rather than by WordPress, which would read the site's clock
		// again and derive the UTC date from it — an hour off where the clocks
		// go back. What is checked below is then what is stored. A draft stays
		// undated, as in WordPress.
		$now       = (int) current_time( 'timestamp', true );
		$dated     = '' !== self::s( $a, 'date', '' );
		$at        = self::dates_at( $now );
		$saved_gmt = $at['post_date_gmt'];
		if ( ! $dated && in_array( $status, array( 'publish', 'future' ), true ) ) {
			$postarr['post_date']     = $at['post_date'];
			$postarr['post_date_gmt'] = $at['post_date_gmt'];
		}
		if ( $dated ) {
			$when = self::parse_post_date( self::s( $a, 'date' ) );
			if ( is_wp_error( $when ) ) {
				return $when;
			}
			$postarr['post_date'] = $when['post_date'];
			// Only a date that named its offset fixes the UTC column; a site-time
			// date leaves it to WordPress, as the admin screen does.
			if ( '' !== $when['post_date_gmt'] ) {
				$postarr['post_date_gmt'] = $when['post_date_gmt'];
			}
			$saved_gmt = '' !== $when['post_date_gmt'] ? $when['post_date_gmt'] : self::utc_of_site_time( $when['post_date'] );
		}
		$refusal = self::status_date_refusal( $type, null, $status, $saved_gmt, $dated, $now );
		if ( '' !== $refusal ) {
			return self::status_date_error( $refusal, $saved_gmt );
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

		// On a builder page post_content may be only a copy the site does not
		// show (AB_MCP_Builders::content_update_guard()). A change to content
		// there is refused before anything is written, with the way to make
		// it; other fields stay writable. Where it would show but not last,
		// the save goes ahead and the answer says why.
		$builder_note = '';
		if ( array_key_exists( 'content', $a ) ) {
			$guard = AB_MCP_Builders::content_update_guard( $post );
			if ( null !== $guard ) {
				if ( $guard['block'] ) {
					return new WP_Error( 'ab_mcp_builder_content_copy', $guard['message'], array( 'builder' => $guard['builder'] ) );
				}
				$builder_note = $guard['message'];
			}
		}

		$pto  = get_post_type_object( $post->post_type );
		// For a file, "draft" and "pending" are no way around the right to
		// publish: WordPress stores them as "inherit" (see below).
		$cannot_publish = 'attachment' === $post->post_type
			? __( 'You cannot publish: this status needs the right to publish. For a file, "draft" and "pending" do not help: WordPress stores them as "inherit", so the file follows its parent. Ask someone who can publish.', 'alphabridge-mcp' )
			: __( 'You cannot publish; use status "draft" or "pending".', 'alphabridge-mcp' );

		// A status given as null or a list is refused like an unknown one: it
		// would reach WordPress as "", which it stores as "draft" — a published
		// post taken off the site by a call that named no status.
		$asked = null;
		if ( array_key_exists( 'status', $a ) ) {
			$new_status = is_scalar( $a['status'] ) ? (string) $a['status'] : '';
			$asked      = $new_status;
			if ( ! self::is_allowed_status( $new_status ) ) {
				return new WP_Error( 'ab_mcp_invalid_status', __( 'Unsupported post status.', 'alphabridge-mcp' ) );
			}
			// Moving a post to publish, private or future needs the publish capability.
			if ( in_array( $new_status, array( 'publish', 'private', 'future' ), true )
				&& $new_status !== $post->post_status
				&& ( ! $pto || ! current_user_can( $pto->cap->publish_posts ) ) ) {
				return new WP_Error( 'ab_mcp_forbidden', $cannot_publish );
			}
		}
		// Nor is a parent given as null or a list: it would reach WordPress as
		// 0 — a file taken off its post, a page moved to the top level.
		if ( array_key_exists( 'parent', $a ) && ! is_scalar( $a['parent'] ) ) {
			return new WP_Error( 'ab_mcp_invalid_parent', __( 'The parent must be a post id.', 'alphabridge-mcp' ) );
		}
		// A file shows in its parent's gallery and attached media: another post
		// as its parent needs the right to edit that post, as the upload tools
		// ask — and, as in the REST API, is neither a revision nor a file.
		if ( 'attachment' === $post->post_type && array_key_exists( 'parent', $a ) ) {
			$new_parent = (int) $a['parent'];
			if ( $new_parent > 0 && (int) $post->post_parent !== $new_parent ) {
				$target = get_post( $new_parent );
				if ( ! $target ) {
					return new WP_Error( 'ab_mcp_not_found', __( 'The parent post does not exist.', 'alphabridge-mcp' ) );
				}
				if ( in_array( $target->post_type, array( 'revision', 'attachment' ), true ) ) {
					return new WP_Error( 'ab_mcp_invalid_parent', __( 'A file cannot be attached to a revision or to another file.', 'alphabridge-mcp' ) );
				}
				if ( ! current_user_can( 'edit_post', $new_parent ) ) {
					return new WP_Error( 'ab_mcp_forbidden', __( 'You cannot attach media to that post.', 'alphabridge-mcp' ) );
				}
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

		$now    = (int) current_time( 'timestamp', true );
		$dated  = '' !== self::s( $a, 'date', '' );
		$handed = null !== $asked ? $asked : (string) $post->post_status;
		if ( $dated ) {
			$when = self::parse_post_date( self::s( $a, 'date' ) );
			if ( is_wp_error( $when ) ) {
				return $when;
			}
			// Both columns, and edit_date. Without the UTC column
			// wp_update_post() keeps the stored one beside the new local date;
			// without edit_date it gives a draft the time of the save instead.
			$postarr['post_date']     = $when['post_date'];
			$postarr['post_date_gmt'] = '' !== $when['post_date_gmt'] ? $when['post_date_gmt'] : self::utc_of_site_time( $when['post_date'] );
			$postarr['edit_date']     = true;
		} elseif ( self::undated_draft( $post ) && in_array( $handed, array( 'publish', 'future' ), true ) ) {
			// wp_update_post() would date this draft at the save by the site's
			// clock and derive the UTC date from that — an hour off where the
			// clocks go back. It is dated here instead, both columns from the
			// one reading of the clock, so the check below sees what is stored.
			$at                       = self::dates_at( $now );
			$postarr['post_date']     = $at['post_date'];
			$postarr['post_date_gmt'] = $at['post_date_gmt'];
			$postarr['edit_date']     = true;
		}
		$saved_gmt = isset( $postarr['post_date_gmt'] ) ? $postarr['post_date_gmt'] : self::stored_gmt( $post );
		$refusal   = self::status_date_refusal( $post->post_type, (string) $post->post_status, $asked, $saved_gmt, $dated, $now );
		if ( '' !== $refusal ) {
			return self::status_date_error( $refusal, $saved_gmt );
		}
		// The same capability again, for the status WordPress will store: the
		// date can turn "publish" into "future", and so take a published post
		// off the site without "future" ever being asked for.
		$saved = self::saved_status( $post->post_type, $handed, $saved_gmt, $now );
		if ( in_array( $saved, array( 'publish', 'private', 'future' ), true )
			&& $saved !== $post->post_status
			&& ( ! $pto || ! current_user_can( $pto->cap->publish_posts ) ) ) {
			return new WP_Error( 'ab_mcp_forbidden', $cannot_publish );
		}
		// A file with "inherit" is as visible as its parent: public without
		// one, and public the moment the parent is published — whoever
		// publishes a post looks at the post, not at the files under it. And
		// WordPress stores a file with any status but private, trash or
		// auto-draft as "inherit". So a save that leaves a file to a parent it
		// did not follow before needs the right to publish too, whatever the
		// parent's status is now (file_newly_inherits()).
		if ( 'attachment' === $post->post_type ) {
			$refused = self::file_publish_refusal( $id, (string) $post->post_status, (int) $post->post_parent, $handed, array_key_exists( 'post_parent', $postarr ) ? (int) $postarr['post_parent'] : (int) $post->post_parent );
			if ( null !== $refused ) {
				return $refused;
			}
		}

		// A post in the trash that is given a status comes out the way
		// wp_untrash_post() takes one out: a pre_untrash_post filter may keep it
		// in, the hooks other plugins listen to run, the trash notes go and the
		// comments get their states back. But in the one save that writes the
		// status, date and fields asked for: wp_untrash_post() saves a status
		// alone, with the post's old date — a second save would have to follow,
		// and a status such as "future" given to it would be judged by that old
		// date, publishing a post whose date has passed. The notes go after the save,
		// not before it as in wp_untrash_post(): a save that fails leaves the
		// post in the trash with them, and a file under it keeps the visibility
		// that note gives it.
		$untrash  = 'trash' === (string) $post->post_status && null !== $asked;
		$previous = '';
		if ( $untrash ) {
			$previous = (string) get_post_meta( $id, '_wp_trash_meta_status', true );
			if ( null !== apply_filters( 'pre_untrash_post', null, $post, $previous ) ) {
				return new WP_Error( 'ab_mcp_untrash_refused', __( 'A plugin keeps this post in the trash; nothing was written.', 'alphabridge-mcp' ) );
			}
			do_action( 'untrash_post', $id, $previous );
		}

		// Same rule as create: wp_update_post() hands this straight to
		// wp_insert_post(), which unslashes it. And without $wp_error:
		// WordPress saves a post's page template along on every save, and
		// where that template is gone — a theme switched since, say — it gives
		// up with $wp_error after the row is written, before the hooks that
		// schedule a post and clear caches. Without it, it falls back to the
		// default template and finishes the save, as the classic editor does;
		// 0 then means nothing was written.
		$res = wp_update_post( wp_slash( $postarr ) );
		// The row decides whether the post left the trash, not the answer.
		$saved_post = $untrash ? get_post( $id ) : null;
		if ( $saved_post && 'trash' !== (string) $saved_post->post_status ) {
			delete_post_meta( $id, '_wp_trash_meta_status' );
			delete_post_meta( $id, '_wp_trash_meta_time' );
			wp_untrash_post_comments( $id );
			do_action( 'untrashed_post', $id, $previous );
		}
		if ( ! $res || is_wp_error( $res ) ) {
			return new WP_Error( 'ab_mcp_update_failed', __( 'WordPress did not save the post.', 'alphabridge-mcp' ) );
		}

		self::apply_meta_and_terms( $id, $a );

		$out = array(
			'updated' => true,
			'post'    => self::post_summary( get_post( $id ) ),
		);
		if ( '' !== $builder_note ) {
			$out['builder_note'] = $builder_note;
		}
		return $out;
	}

	/**
	 * Why a save must not go ahead as asked, or '' when it may.
	 *
	 * WordPress settles "publish" and "future" by the date: wp_insert_post()
	 * stores "future" for a date at least a minute ahead and "publish" for any
	 * other, whichever of the two it was handed. So a save could publish a
	 * post, or take one off the site, without the call asking for it — the
	 * date would decide, not the call. Refused:
	 *
	 * - would_publish: "future" asked for, the date not a minute ahead;
	 * - would_publish_scheduled: no status asked for, a scheduled post whose
	 *   date — a new one, or its own that has passed — is not a minute ahead;
	 * - would_unpublish: no status asked for, a published post dated ahead;
	 * - stays_scheduled: "publish" asked for without a date, the post dated
	 *   ahead, so WordPress would keep it scheduled;
	 * - too_close: a post that would be saved as scheduled, less than
	 *   SCHEDULE_LEAD ahead — see there.
	 *
	 * "publish" with a date ahead schedules the post, as it does in WordPress.
	 * Attachments are exempt, as they are in wp_insert_post().
	 *
	 * @param string      $type  Post type.
	 * @param string|null $old   Status the post has, or null for a new post.
	 * @param string|null $asked Status the call asks for, or null.
	 * @param string      $gmt   UTC date the post will be saved with, "Y-m-d H:i:s".
	 * @param bool        $dated Whether the call sets that date.
	 * @param int         $now   Unix time of the save.
	 * @return string Reason, or ''.
	 */
	public static function status_date_refusal( $type, $old, $asked, $gmt, $dated, $now ) {
		$handed = null !== $asked ? $asked : (string) $old;
		$saved  = self::saved_status( $type, $handed, $gmt, $now );
		if ( $saved !== $handed ) {
			if ( 'future' === $handed ) {
				return null === $asked ? 'would_publish_scheduled' : 'would_publish';
			}
			// Handed "publish", saved "future".
			if ( null === $asked ) {
				return 'would_unpublish';
			}
			if ( ! $dated ) {
				return 'stays_scheduled';
			}
		}
		if ( 'future' === $saved && 'attachment' !== $type && self::lead( $gmt, $now ) < self::SCHEDULE_LEAD ) {
			return 'too_close';
		}
		return '';
	}

	/**
	 * The status wp_insert_post() stores for a post handed to it with this
	 * status and UTC date: "future" for a date at least a minute ahead and
	 * "publish" for any other, when handed either of the two; any other status,
	 * and any attachment, as handed. A date that cannot be read counts as not
	 * ahead, as it does in WordPress' strtotime() comparison.
	 *
	 * @param string $type   Post type.
	 * @param string $status Status handed over.
	 * @param string $gmt    UTC date, "Y-m-d H:i:s".
	 * @param int    $now    Unix time of the save.
	 * @return string
	 */
	public static function saved_status( $type, $status, $gmt, $now ) {
		if ( 'attachment' === $type || ( 'publish' !== $status && 'future' !== $status ) ) {
			return (string) $status;
		}
		return self::lead( $gmt, $now ) >= MINUTE_IN_SECONDS ? 'future' : 'publish';
	}

	/**
	 * Seconds from $now to a UTC date, or PHP_INT_MIN when it cannot be read.
	 *
	 * @param string $gmt UTC date, "Y-m-d H:i:s".
	 * @param int    $now Unix time.
	 * @return int
	 */
	private static function lead( $gmt, $now ) {
		$when = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $gmt, new DateTimeZone( 'UTC' ) );
		return false === $when ? PHP_INT_MIN : $when->getTimestamp() - (int) $now;
	}

	/**
	 * The refusal for status_date_refusal(), with the date in site time.
	 *
	 * @param string $reason Reason from status_date_refusal().
	 * @param string $gmt    UTC date the post would have been saved with.
	 * @return WP_Error
	 */
	private static function status_date_error( $reason, $gmt ) {
		$date = (string) self::site_time( $gmt );
		switch ( $reason ) {
			case 'would_publish':
				/* translators: %s: the date the post would have, in site time. */
				$message = sprintf( __( 'WordPress schedules a post only for a date ahead of the save. Dated %s, this post would be published now. Pass a "date" at least five minutes ahead to schedule it.', 'alphabridge-mcp' ), $date );
				break;
			case 'would_publish_scheduled':
				/* translators: %s: the date the post would have, in site time. */
				$message = sprintf( __( 'This post is scheduled, but its date %s is not a minute ahead: saving would publish it now. Pass status "publish" to publish it, or a "date" at least five minutes ahead to keep it scheduled.', 'alphabridge-mcp' ), $date );
				break;
			case 'would_unpublish':
				/* translators: %s: the date the post would have, in site time. */
				$message = sprintf( __( 'The date %s is ahead: saving would take this published post off the site until then. To schedule it, pass status "future" with a "date" at least five minutes ahead; to keep it published, pass a "date" that is not ahead.', 'alphabridge-mcp' ), $date );
				break;
			case 'too_close':
				/* translators: %s: the date the post would have, in site time. */
				$message = sprintf( __( 'The post would be scheduled for %s, less than five minutes ahead. So close to its date, WordPress would publish it early if it were saved again within the last minute — by a save still running, or by another plugin saving it too. To schedule it, pass a "date" at least five minutes ahead; to publish it now, pass status "publish" with a "date" that is not ahead, such as the current time.', 'alphabridge-mcp' ), $date );
				break;
			default:
				/* translators: %s: the date the post would have, in site time. */
				$message = sprintf( __( 'This post is dated %s, which is ahead, so WordPress would keep it scheduled instead of publishing it. To publish it now, pass a "date" that is not ahead as well, such as the current time; to schedule it, pass status "future" with a "date" at least five minutes ahead.', 'alphabridge-mcp' ), $date );
		}
		return new WP_Error( 'ab_mcp_status_by_date', $message, array( 'reason' => $reason ) );
	}

	/**
	 * Both date columns for a moment, from one reading of the clock: the site
	 * time and the UTC time of that same instant.
	 *
	 * @param int $now Unix time.
	 * @return array{post_date:string,post_date_gmt:string}
	 */
	private static function dates_at( $now ) {
		$utc = new DateTimeImmutable( '@' . (int) $now );
		return array(
			'post_date'     => $utc->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' ),
			'post_date_gmt' => $utc->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Whether a save leaves a file to a parent it did not follow before: it
	 * gets "inherit" and did not have it, or it is left "inherit" or in the
	 * trash (from where it comes back as "inherit") under another parent. The
	 * parent that counts is the one WordPress stores (stored_parent()). The
	 * parent's status is left out on purpose: a file hidden under a draft goes
	 * public when the draft is published. A file left private follows no
	 * parent until a later save makes it "inherit" — which then asks the same.
	 *
	 * Public, because every tool that saves a file asks it: wp_update_media
	 * saves one too, and any save can take a file off its parent.
	 *
	 * @param int    $id         File id.
	 * @param string $status_now Status it has.
	 * @param int    $parent_now Parent it has.
	 * @param string $status     Status it is saved with.
	 * @param int    $parent     Parent it is saved with.
	 * @return bool
	 */
	public static function file_newly_inherits( $id, $status_now, $parent_now, $status, $parent ) {
		$after = in_array( $status, array( 'private', 'trash', 'auto-draft' ), true ) ? $status : 'inherit';
		if ( 'inherit' === $after && 'inherit' !== $status_now ) {
			return true;
		}
		return in_array( $after, array( 'inherit', 'trash' ), true )
			&& self::stored_parent( (int) $id, (int) $parent ) !== (int) $parent_now;
	}

	/**
	 * The refusal for a save of a file that file_newly_inherits() leaves to a
	 * parent it did not follow before, for an account without the right to
	 * publish files; null where the save may go ahead.
	 *
	 * @param int    $id         File id.
	 * @param string $status_now Status it has.
	 * @param int    $parent_now Parent it has.
	 * @param string $status     Status it is saved with.
	 * @param int    $parent     Parent it is saved with.
	 * @return WP_Error|null
	 */
	public static function file_publish_refusal( $id, $status_now, $parent_now, $status, $parent ) {
		if ( ! self::file_newly_inherits( $id, $status_now, $parent_now, $status, $parent ) ) {
			return null;
		}
		$pto = get_post_type_object( 'attachment' );
		if ( $pto && current_user_can( $pto->cap->publish_posts ) ) {
			return null;
		}
		return new WP_Error( 'ab_mcp_forbidden', __( 'You cannot publish: after this save the file would take its visibility from a parent it did not follow before (WordPress stores it as "inherit", or gives it "inherit" on the way out of the trash; where a loop runs through the file, any save leaves it without a parent) — public wherever that parent is published, and everywhere without one. Ask someone who can publish.', 'alphabridge-mcp' ) );
	}

	/**
	 * The parent WordPress stores for a post given $parent:
	 * wp_check_post_hierarchy_for_loops() stores none where the post would
	 * become its own ancestor — the post itself included, and also when the
	 * save keeps the parent the post has and a loop through the post runs
	 * over it.
	 *
	 * @param int $id     Post id.
	 * @param int $parent Parent it is given.
	 * @return int
	 */
	private static function stored_parent( $id, $parent ) {
		if ( $parent <= 0 ) {
			return 0;
		}
		$seen = array();
		for ( $cur = $parent; $cur > 0 && ! isset( $seen[ $cur ] ); ) {
			if ( $cur === $id ) {
				return 0;
			}
			$seen[ $cur ] = true;
			$p            = get_post( $cur );
			$cur          = $p ? (int) $p->post_parent : 0;
		}
		return $parent;
	}

	/**
	 * Whether wp_update_post() would date this post at the save: a draft,
	 * pending or auto-draft post without a UTC date, saved without edit_date.
	 *
	 * @param object $post The post as it is.
	 * @return bool
	 */
	private static function undated_draft( $post ) {
		return '0000-00-00 00:00:00' === (string) $post->post_date_gmt
			&& in_array( (string) $post->post_status, array( 'draft', 'pending', 'auto-draft' ), true );
	}

	/**
	 * The UTC date a post keeps when a save does not date it: its own, or for
	 * one without, the one WordPress derives from its local date. (An undated
	 * draft is dated by update_post() before it could be saved as "publish" or
	 * "future"; handed on as anything else, its date decides nothing.)
	 *
	 * @param object $post The post as it is.
	 * @return string "Y-m-d H:i:s" in UTC.
	 */
	private static function stored_gmt( $post ) {
		$gmt = (string) $post->post_date_gmt;
		if ( '' !== $gmt && '0000-00-00 00:00:00' !== $gmt ) {
			return $gmt;
		}
		return self::utc_of_site_time( (string) $post->post_date );
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
		$id   = self::i( $a, 'id' );
		$post = get_post( $id );
		if ( ! $post ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Post not found.', 'alphabridge-mcp' ) );
		}
		if ( ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot delete this specific post.', 'alphabridge-mcp' ) );
		}
		$force = self::b( $a, 'force', false );
		if ( ! $force ) {
			// Without force, into the trash, as the tool promises.
			// wp_delete_post() moves only posts and pages there: any other type
			// — a product, an event — it deletes for good, and a file together
			// with the file on the server, as the media trash is off unless a
			// site turns it on.
			if ( 'attachment' === $post->post_type ) {
				return new WP_Error( 'ab_mcp_file_not_trashed', __( 'Files have no trash here: WordPress deletes a file for good, together with the file on the server. Delete it with wp_delete_media, or here with force=true.', 'alphabridge-mcp' ) );
			}
			if ( 'trash' === $post->post_status ) {
				return new WP_Error( 'ab_mcp_already_trashed', __( 'The post is already in the trash. Pass force=true to delete it for good.', 'alphabridge-mcp' ) );
			}
			if ( defined( 'EMPTY_TRASH_DAYS' ) && ! EMPTY_TRASH_DAYS ) {
				return new WP_Error( 'ab_mcp_no_trash', __( 'This site has no trash (EMPTY_TRASH_DAYS is 0): WordPress would delete the post for good. Pass force=true to delete it.', 'alphabridge-mcp' ) );
			}
			// A post taken out of the trash by a plain status write (the REST
			// API does that) keeps the status and time noted then. WordPress
			// adds its own next to them and reads the first: the old status on
			// the way out, and the old time for its daily clean-up, which would
			// delete the post for good at once. The note on the comments stays:
			// comments left hidden then have their states only there.
			delete_post_meta( $id, '_wp_trash_meta_status' );
			delete_post_meta( $id, '_wp_trash_meta_time' );
			wp_trash_post( $id );
			$after = get_post( $id );
			if ( ! $after || 'trash' !== (string) $after->post_status ) {
				return new WP_Error( 'ab_mcp_trash_failed', __( 'The post could not be moved to the trash.', 'alphabridge-mcp' ) );
			}
			return array(
				'deleted'   => true,
				'permanent' => false,
				'id'        => $id,
			);
		}
		$res = wp_delete_post( $id, true );
		if ( ! $res ) {
			return new WP_Error( 'ab_mcp_delete_failed', __( 'Delete failed.', 'alphabridge-mcp' ) );
		}
		return array(
			'deleted'   => true,
			'permanent' => true,
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
		// Revisions belong to whoever may edit the post (the REST API's rule);
		// reading the post is not enough to see its history.
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'Your account cannot edit this post, so its revisions are not available.', 'alphabridge-mcp' ) );
		}
		$revs = wp_get_post_revisions( $post->ID );
		$out  = array();
		foreach ( $revs as $rev ) {
			$out[] = array(
				'revision_id' => (int) $rev->ID,
				'date'        => self::site_time( $rev->post_modified_gmt, $rev->post_modified ),
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
