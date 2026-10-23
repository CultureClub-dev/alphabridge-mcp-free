<?php
/**
 * Global search, search-and-replace and bulk actions.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tools-base.php';

/**
 * Class AB_MCP_Tools_Search_Bulk
 */
class AB_MCP_Tools_Search_Bulk extends AB_MCP_Tools_Base {

	/**
	 * Register tools.
	 *
	 * @param AB_MCP_Tool_Registry $r Registry.
	 */
	public static function register( AB_MCP_Tool_Registry $r ) {

		$r->register(
			'wp_search',
			array(
				'description' => 'Search posts, pages and other entries for words in title, excerpt and content, across all searchable post types or one, ordered by relevance. Every status but trash, auto-draft and others registered as excluded from search is searched (drafts and private entries included); only entries the account may read are returned. Returns {query, total, results}; results are summaries as in wp_list_posts (no content), up to per_page, without further pages. Needs edit_posts. Read-only. wp_list_posts also takes search, with filters and paging.',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'query' ),
					'properties' => array(
						'query'    => array( 'type' => 'string', 'description' => 'Words to search for.' ),
						'type'     => array( 'type' => 'string', 'description' => 'Post type, or "any" for every post type not excluded from search. Default any. Revisions are not searched.', 'default' => 'any' ),
						'per_page' => array( 'type' => 'integer', 'description' => 'Maximum number of results, 1–100; other values are clamped. Default 20.', 'default' => 20 ),
					),
				),
				'callback'    => array( __CLASS__, 'search' ),
			)
		);

	}

	/**
	 * Search.
	 *
	 * @param array $a Args.
	 * @return array
	 */
	public static function search( $a ) {
		$per_page = self::clamp( self::i( $a, 'per_page', 20 ), 1, 100 );
		// Revisions are not searched: a hit count over other people's revisions
		// would tell of their text. "any" leaves them out by itself; an explicit
		// type is normalised the way WP_Query normalises it.
		if ( 'revision' === sanitize_key( self::s( $a, 'type', 'any' ) ) ) {
			return new WP_Error( 'ab_mcp_forbidden', __( 'Revisions cannot be searched; use wp_list_revisions on a post you may edit.', 'alphabridge-mcp' ) );
		}
		$query    = new WP_Query(
			array(
				'post_type'      => self::s( $a, 'type', 'any' ) ?: 'any',
				'post_status'    => 'any',
				'perm'           => 'readable',
				's'              => self::s( $a, 'query' ),
				'posts_per_page' => $per_page,
			)
		);
		$items = array();
		foreach ( $query->posts as $post ) {
			// Objektbezogener Filter: fremde Drafts/private Posts nicht ausliefern.
			if ( ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}
			// With the gate above, a revision can only get here through a
			// third-party query filter; the entry is withheld regardless (the
			// count is WordPress' own, see list_posts).
			if ( 'revision' === $post->post_type && ! self::raw_content_allowed( $post ) ) {
				continue;
			}
			$items[] = self::post_summary( $post );
		}
		return array(
			'query'   => self::s( $a, 'query' ),
			'total'   => (int) $query->found_posts,
			'results' => $items,
		);
	}

}
