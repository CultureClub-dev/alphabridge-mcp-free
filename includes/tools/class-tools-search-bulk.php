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

		$r->register(
			'wp_bulk_update_status',
			array(
				'description' => 'Change the status of many posts at once by id list (posts, pages, other post types and media files alike) to publish, draft, pending, private or trash; at most 500 ids per call (over_limit counts the rest, which are not touched). Suited to batches; one post\'s status and other fields change with wp_update_post. Unknown ids are skipped, posts already in the status are left as they are and an id given twice counts once, so a repeated call changes nothing more, except where WordPress stores another form than asked (stored_as below): those posts are written again. Publishing makes posts public at once. On a site without a trash, "trash" is refused, since WordPress would delete for good. A media file set to draft, pending or publish becomes "inherit" (shown wherever its parent is published, everywhere without one), which needs the right to publish. Returns updated (the count); ids, the posts that have the status now, with stored_as where WordPress stores a form of its own ("future" for a post dated ahead, "inherit" for a file); failed, each with the status it has (null if unreadable) and, where telling, an error: update_failed, trash_refused or untrash_refused (refused by WordPress or a plugin), unreadable (nothing written) or unverified (written, not readable back); forbidden, the posts the account may not change (edit right per post, delete right for trash). failed and forbidden list 100 each; failed_omitted and forbidden_omitted count the rest. undo is the token of an undo point with the previous statuses, for wp_undo (null when none was recorded). A call begins no further post once the request has run 60 seconds (the first post is always handled, and one already begun is finished), so a long list is answered in parts; remaining lists the ids it did not reach, in their order (empty when it reached all), and those ids, sent in a further call, continue where it stopped, with an undo point of their own. Needs edit_others_posts.',
				'capability'  => 'edit_others_posts',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'ids', 'status' ),
					'properties' => array(
						'ids'    => array( 'type' => 'array', 'description' => 'Post ids (integers) of any post type. An id given twice counts once; ids beyond the first 500 are not touched, nor those a call does not reach in its time (remaining).' ),
						'status' => array( 'type' => 'string', 'description' => 'Target status: publish, draft, pending, private or trash; any other value is refused and nothing changes.' ),
					),
				),
				'callback'    => array( __CLASS__, 'bulk_update_status' ),
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


	/* ---------------------------------------------------- wp_bulk_update_status
	 * Free since 4.9.0, before in Pro only. Pro's AB_MCP_Tools_Search_Bulk_Pro
	 * extends this class and registers its own wp_bulk_update_status over this
	 * one. The checks on a single post live in AB_MCP_Post_Status, not here:
	 * Pro 4.5.3 to 4.5.9 declare can_set_status() with two parameters, and a
	 * method of this class with three would stop such a Pro from loading.
	 */

	/**
	 * Set the status of many posts at once (wp_bulk_update_status).
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function bulk_update_status( $a ) {
		global $wpdb;
		// Each post once: named twice, its second round would start from the
		// first one's result, and the undo point would end at that instead of
		// at where the post started.
		$all    = array_values( array_unique( array_map( 'intval', self::arr( $a, 'ids', array() ) ) ) );
		$ids    = array_slice( $all, 0, 500 );
		$status = self::s( $a, 'status' );
		$valid  = array( 'publish', 'draft', 'pending', 'private', 'trash' );
		if ( ! in_array( $status, $valid, true ) ) {
			// A WP_Error, not an array with an error key: the dispatcher logs the
			// latter as a success.
			return new WP_Error( 'ab_mcp_bad_status', __( 'Invalid status: publish, draft, pending, private or trash.', 'alphabridge-mcp' ) );
		}
		// On a site without a trash, wp_trash_post() deletes for good — the
		// trash cannot give the posts back, and neither can the undo.
		if ( 'trash' === $status && defined( 'EMPTY_TRASH_DAYS' ) && ! EMPTY_TRASH_DAYS ) {
			return new WP_Error( 'ab_mcp_no_trash', __( 'This site has no trash (EMPTY_TRASH_DAYS is 0): WordPress would delete the posts for good. Nothing was changed. Use the status draft or private instead, or ask an administrator to set EMPTY_TRASH_DAYS above 0 in wp-config.php.', 'alphabridge-mcp' ) );
		}

		$done              = array();
		$forbidden         = array();
		$forbidden_omitted = 0;
		$failed            = array();
		$failed_omitted    = 0;
		$stored_as         = array();
		$before            = array();
		$remaining         = array();
		// From the start of the request, not of the loop: WordPress has spent
		// its own seconds on loading by then, and they count for the client too.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a number.
		$started = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : self::budget_clock();
		foreach ( $ids as $n => $id ) {
			// The first post is always handled, so every call gets on.
			if ( $n > 0 && self::budget_clock() - $started >= self::STATUS_BUDGET ) {
				$remaining = array_slice( $ids, $n );
				break;
			}
			if ( ! get_post( $id ) ) {
				continue;
			}
			if ( ! AB_MCP_Post_Status::can_set_status( $id, $status ) ) {
				if ( count( $forbidden ) < 100 ) {
					$forbidden[] = $id;
				} else {
					++$forbidden_omitted;
				}
				continue;
			}
			// The previous status is read right before the change, so the undo
			// snapshot describes what actually happened rather than what was
			// requested — posts that were skipped never enter it. It is read as
			// the table has it: get_post_status() gives a file stored as
			// "inherit" its parent's status, or "publish" when it has none, and
			// runs a filter — the undo would put back a status the post never had.
			// The password is read along, never kept: only whether the post
			// has one counts (below).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The stored status, right before it is changed.
			$row  = $wpdb->get_row( $wpdb->prepare( "SELECT post_status, post_password FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );
			$prev = is_array( $row ) && isset( $row['post_status'] ) ? (string) $row['post_status'] : '';
			if ( '' === $prev ) {
				// Unread, a change could not go into the undo point: not written.
				self::add_failure( $failed, $failed_omitted, $id, null, 'unreadable' );
				continue;
			}
			if ( $prev === $status ) {
				// Already so: nothing to write and nothing to undo.
				// wp_trash_post() would even answer false for a post that is
				// already in the trash.
				$done[] = $id;
				continue;
			}
			if ( 'trash' === $status ) {
				// A WP_Post when it worked; false, null or whatever a
				// pre_trash_post filter hands back when not.
				$res = AB_MCP_Post_Status::trash_post( $id );
				$err = empty( $res ) ? 'trash_refused' : '';
			} elseif ( 'trash' === $prev ) {
				// Out of the trash the way WordPress takes a post out, not by a
				// plain status write: the trash notes go, the comments get their
				// states back. Straight to the status asked for, with the same
				// save a plain write makes; a pre_untrash_post filter can refuse.
				$res = AB_MCP_Post_Status::untrash_as( $id, $status );
				$err = empty( $res ) ? 'untrash_refused' : '';
			} else {
				// Without $wp_error: with it, wp_insert_post() gives up after the
				// row is written when the page template the post names is gone —
				// before the status hooks run, so a post it schedules gets no
				// event. Without it, WordPress falls back to the default template
				// and goes on, as it did before this tool read anything back.
				$res = wp_update_post(
					array(
						'ID'          => $id,
						'post_status' => $status,
					)
				);
				$err = ( is_wp_error( $res ) || (int) $res <= 0 ) ? 'update_failed' : '';
			}
			// What the table has now decides what is reported — not the answer
			// of the call, which a filter can make say anything, and not the
			// status asked for, which WordPress stores in its own form where it
			// has one.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The stored status, right after the write.
			$after  = $wpdb->get_row( $wpdb->prepare( "SELECT post_status, post_type, post_date_gmt FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );
			$stored = is_array( $after ) && isset( $after['post_status'] ) ? (string) $after['post_status'] : null;
			// Every status this call changed goes into the undo point, also one
			// a plugin stored in place of the one asked for: the undo is for
			// what happened. Where the status after the write cannot be read
			// back — get_row() answers null for a row that is gone and for a
			// query that failed alike — a change cannot be ruled out, whatever
			// the call answered: the pair is kept, and the undo judges the post
			// again as it finds it.
			if ( null === $stored || $stored !== $prev ) {
				$entry = array( $id, $prev );
				// The undo point keeps no password, but notes that the post had
				// one: WordPress drops the password of a post it saves as
				// private, in this call or a later one, and the undo then puts
				// back no status that could show the post without it.
				if ( isset( $row['post_password'] ) && '' !== (string) $row['post_password'] ) {
					$entry[] = 'password_protected';
				}
				$before[] = $entry;
			}
			if ( null !== $stored && self::stored_as_asked( $status, $stored, $after ) ) {
				$done[] = $id;
				// Not capped: at most one per id, and the ids are capped.
				if ( $stored !== $status ) {
					$stored_as[] = array(
						'id'     => $id,
						'status' => $stored,
					);
				}
				continue;
			}
			self::add_failure( $failed, $failed_omitted, $id, $stored, '' !== $err ? $err : ( null === $stored ? 'unverified' : '' ) );
		}

		$undo = null;
		if ( ! empty( $before ) ) {
			$undo = AB_MCP_Undo::record(
				array(
					'tool'    => 'wp_bulk_update_status',
					'cap'     => 'edit_others_posts',
					'kind'    => 'post_status',
					/* translators: 1: number of posts, 2: status asked for */
					// Neutral: the point also holds posts whose status after the
					// write could not be read back, so a change is not claimed.
					'summary' => sprintf( '%1$d post(s): bulk status change (asked for "%2$s")', count( $before ), $status ),
					'items'   => count( $before ),
					'data'    => $before,
				)
			);
		}

		return array(
			'updated'           => count( $done ),
			'ids'               => $done,
			'status'            => $status,
			'stored_as'         => $stored_as,
			'failed'            => $failed,
			'failed_omitted'    => $failed_omitted,
			'forbidden'         => $forbidden,
			'forbidden_omitted' => $forbidden_omitted,
			// Beyond the first 500 ids nothing is touched; said, not dropped.
			'over_limit'        => count( $all ) - count( $ids ),
			// Not reached within STATUS_BUDGET, untouched, in the order given.
			'remaining'         => $remaining,
			'undo'              => $undo,
		);
	}

	/**
	 * Now, in seconds, for STATUS_BUDGET.
	 *
	 * @return float
	 */
	private static function budget_clock() {
		return is_callable( self::$clock ) ? (float) call_user_func( self::$clock ) : microtime( true );
	}

	/**
	 * A post the bulk status change left otherwise: listed up to 100 times,
	 * counted beyond that, so no failure goes unmentioned.
	 *
	 * @param array       $failed  The list (by reference).
	 * @param int         $omitted The count beyond it (by reference).
	 * @param int         $id      Post id.
	 * @param string|null $status  Status the table has, null where it could not be read.
	 * @param string      $error   Why, '' when the table alone tells.
	 */
	private static function add_failure( array &$failed, &$omitted, $id, $status, $error ) {
		if ( count( $failed ) >= 100 ) {
			++$omitted;
			return;
		}
		$item = array(
			'id'     => (int) $id,
			'status' => $status,
		);
		if ( '' !== $error ) {
			$item['error'] = $error;
		}
		$failed[] = $item;
	}

	/**
	 * Whether the status the table has is what WordPress stores for the one
	 * asked: the same, or WordPress's own form of it — a file is stored as
	 * "inherit" for a status it cannot have, and "publish" with a date still
	 * ahead as "future".
	 *
	 * @param string $asked  Status asked for.
	 * @param string $stored Status the table has.
	 * @param array  $row    The row read back: post_type, post_date_gmt.
	 * @return bool
	 */
	private static function stored_as_asked( $asked, $stored, array $row ) {
		if ( $stored === $asked ) {
			return true;
		}
		$type = isset( $row['post_type'] ) ? (string) $row['post_type'] : '';
		if ( 'attachment' === $type ) {
			return 'inherit' === $stored && in_array( $asked, array( 'publish', 'draft', 'pending' ), true );
		}
		if ( 'publish' === $asked && 'future' === $stored ) {
			$gmt = isset( $row['post_date_gmt'] ) ? (string) $row['post_date_gmt'] : '';
			return '' !== $gmt && '0000-00-00 00:00:00' !== $gmt && strtotime( $gmt . ' UTC' ) > time();
		}
		return false;
	}
}
