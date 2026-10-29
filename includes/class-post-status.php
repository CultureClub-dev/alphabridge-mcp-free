<?php
/**
 * What may happen to the status of one post: the checks wp_bulk_update_status
 * makes on every post, and the undo of it and of wp_update_post asks again
 * (AB_MCP_Undo_Restore), so whatever a tool refuses now, a restore refuses
 * too. Plus the two ways in and out of the trash both of them write through.
 *
 * Free since 4.9.0, before part of Pro's AB_MCP_Tools_Search_Bulk_Pro; the
 * methods are unchanged and Pro keeps its own.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Post_Status
 */
class AB_MCP_Post_Status {

	/**
	 * Whether setting this status makes content visible: the three core
	 * statuses that publish, and any registered status a plugin declared
	 * public, private or publicly queryable. WordPress expressly allows such
	 * custom statuses, and an undo snapshot can carry one — so "not one of the
	 * three" must not read as "harmless".
	 *
	 * @param string $status Status name.
	 * @return bool
	 */
	public static function status_goes_live( $status ) {
		if ( in_array( $status, array( 'publish', 'private', 'future' ), true ) ) {
			return true;
		}
		$obj = function_exists( 'get_post_status_object' ) ? get_post_status_object( $status ) : null;
		return is_object( $obj ) && ( ! empty( $obj->public ) || ! empty( $obj->private ) || ! empty( $obj->publicly_queryable ) );
	}

	/**
	 * Whether a write makes a file take its visibility from a parent where it
	 * did not before. WordPress stores a file with any status but "private",
	 * "trash" or "auto-draft" as "inherit", and a file with "inherit" is as
	 * visible as its parent: public without one, and public the moment the
	 * parent is published — whoever publishes a post looks at the post, not at
	 * the files under it. So a write that makes a file "inherit", or leaves it
	 * "inherit" or in the trash (from where it comes back as "inherit") under
	 * another parent, is asking to publish, whatever the parent's status is
	 * now. A file left private, or as an auto-draft, follows no parent until
	 * a later write makes it "inherit" — which then asks the same.
	 *
	 * The parent's status is left out on purpose: judged by it, a file hidden
	 * under a draft went public when the draft was published, and the judging
	 * had to rebuild how WordPress resolves a parent (the trash, loops,
	 * filters) — and still differed from it.
	 *
	 * @param int      $id     Post id.
	 * @param string   $status Status the post has after the write.
	 * @param int|null $parent Parent the write gives the post, or null for the one it has.
	 * @return bool
	 */
	public static function newly_inherits( $id, $status, $parent = null ) {
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return false;
		}
		$now_parent = (int) $post->post_parent;
		$after      = in_array( $status, array( 'private', 'trash', 'auto-draft' ), true ) ? (string) $status : 'inherit';
		if ( 'inherit' === $after && 'inherit' !== (string) $post->post_status ) {
			return true;
		}
		// The parent WordPress stores: none where the one named, or the one the
		// file keeps, would make the file its own ancestor.
		return in_array( $after, array( 'inherit', 'trash' ), true )
			&& self::stored_parent( $id, null === $parent ? $now_parent : (int) $parent ) !== $now_parent;
	}

	/**
	 * The parent WordPress stores when a write gives a post $parent:
	 * wp_check_post_hierarchy_for_loops() stores none where the post would
	 * become its own ancestor — the post itself included, and also when the
	 * write keeps the parent the post has and a loop through the post runs
	 * over it.
	 *
	 * @param int $id     Post id.
	 * @param int $parent Parent the write gives it.
	 * @return int
	 */
	private static function stored_parent( $id, $parent ) {
		$id     = (int) $id;
		$parent = (int) $parent;
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
	 * Whether the current user holds the publish capability of this post's type.
	 *
	 * @param int $id Post id.
	 * @return bool
	 */
	public static function may_publish( $id ) {
		$pto         = get_post_type_object( get_post_type( $id ) );
		$publish_cap = ( $pto && isset( $pto->cap->publish_posts ) ) ? $pto->cap->publish_posts : 'publish_posts';
		return current_user_can( $publish_cap );
	}

	/**
	 * Whether a write that leaves this post with $status may give it $parent,
	 * or keep the one it has ($parent null): for a file, the right to publish
	 * where the write leaves it to another parent than before
	 * (newly_inherits()). For any other post the parent changes nothing
	 * anyone can see.
	 *
	 * @param int      $id     Post id.
	 * @param string   $status Status the post has after the write.
	 * @param int|null $parent Parent the write gives it, or null for the one it has.
	 * @return bool
	 */
	public static function may_change_parent( $id, $status, $parent ) {
		return self::may_publish( $id ) || ! self::newly_inherits( $id, $status, $parent );
	}

	/**
	 * May the current user put THIS post into THIS status?
	 *
	 * The tool-level 'edit_others_posts' capability is only a coarse gate, so
	 * the per-object capability is enforced too: trashing needs delete_post, any
	 * other status change needs edit_post for that specific post. And edit_post
	 * alone does not permit publishing — a contributor may edit their own draft
	 * but must not push it live — so every status that makes content go live
	 * additionally needs the post type's own publish capability, and so does a
	 * write that leaves a file to its parent (newly_inherits()): WordPress
	 * stores a file asked to be a draft as "inherit".
	 *
	 * Shared with the undo restore rather than copied, so the two paths cannot
	 * drift apart: whatever this refuses now, a restore refuses too.
	 *
	 * @param int      $id     Post id.
	 * @param string   $status Target status.
	 * @param int|null $parent Parent the same write gives the post, or null for the one it has.
	 * @return bool
	 */
	public static function can_set_status( $id, $status, $parent = null ) {
		$id  = (int) $id;
		$cap = ( 'trash' === $status ) ? 'delete_post' : 'edit_post';
		if ( ! current_user_can( $cap, $id ) ) {
			return false;
		}
		// The right to publish first: who has it needs nothing else looked up.
		return self::may_publish( $id ) || ( ! self::status_goes_live( $status ) && ! self::newly_inherits( $id, $status, $parent ) );
	}

	/**
	 * Move a post to the trash through wp_trash_post(), after clearing the
	 * status and the time an earlier stay there may have noted. WordPress
	 * deletes them only when wp_untrash_post() takes a post out; one taken out
	 * by a plain status write (the REST API does that, and
	 * wp_bulk_update_status did before 4.5.10) keeps them. wp_trash_post()
	 * then adds its notes next to the old ones, and WordPress reads the first:
	 * the status the trash would give back and how visible the files under the
	 * post are while it sits there would be the old ones, and the daily
	 * clean-up (wp_scheduled_delete()) would delete the post for good at once,
	 * by the old time. Nothing reads these two notes of a post outside the
	 * trash; WordPress's clean-up deletes them there itself.
	 *
	 * The note on the comments stays: after a plain status write the comments
	 * are still hidden, and their states from before are only in that note —
	 * as the first, it gives them back when the post comes out again.
	 *
	 * @param int $id Post id, not in the trash.
	 * @return mixed What wp_trash_post() answers.
	 */
	public static function trash_post( $id ) {
		$id = (int) $id;
		foreach ( array( '_wp_trash_meta_status', '_wp_trash_meta_time' ) as $key ) {
			delete_post_meta( $id, $key );
		}
		return wp_trash_post( $id );
	}

	/**
	 * Take a post out of the trash as $status through wp_untrash_post(),
	 * whatever status a plugin would have the trash give back: it clears the
	 * trash notes, gives the comments back their states and runs the hooks
	 * other plugins listen to — a plain status write does none of that.
	 *
	 * WordPress deletes the status and time notes BEFORE it saves the post. A
	 * save that fails — WordPress does not save a post without title, content
	 * and excerpt, a plugin can refuse, the database can fail — leaves the post
	 * in the trash without them, and a file under it then counts as published:
	 * get_post_status() reads a trashed parent's status from that note, and
	 * takes "publish" where there is none. So the notes are kept, and put back
	 * where the post is still in the trash and has none.
	 *
	 * @param int    $id     Post id, in the trash.
	 * @param string $status Status it comes out with.
	 * @return mixed What wp_untrash_post() answers.
	 */
	public static function untrash_as( $id, $status ) {
		global $wpdb;
		$id    = (int) $id;
		$to    = (string) $status;
		$notes = array();
		foreach ( array( '_wp_trash_meta_status', '_wp_trash_meta_time' ) as $key ) {
			$notes[ $key ] = (array) get_post_meta( $id, $key );
		}
		$pin = static function ( $new_status, $post_id ) use ( $id, $to ) {
			return (int) $post_id === $id ? $to : $new_status;
		};
		add_filter( 'wp_untrash_post_status', $pin, PHP_INT_MAX, 2 );
		try {
			$res = wp_untrash_post( $id );
		} finally {
			remove_filter( 'wp_untrash_post_status', $pin, PHP_INT_MAX );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Whether the post is still in the trash, as the table has it.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );
		if ( is_array( $row ) && isset( $row['post_status'] ) && 'trash' === (string) $row['post_status'] ) {
			foreach ( $notes as $key => $values ) {
				if ( ! empty( $values ) && empty( get_post_meta( $id, $key ) ) ) {
					// In the order they were added: WordPress reads the first.
					foreach ( $values as $value ) {
						add_post_meta( $id, $key, wp_slash( $value ) );
					}
				}
			}
		}
		return $res;
	}
}
