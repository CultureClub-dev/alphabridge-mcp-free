<?php
/**
 * Undo kind comment (since 4.14.0): the status wp_moderate_comment set and
 * the trash wp_delete_comment moved a comment into, put back, and a comment
 * wp_reply_comment added, deleted again.
 *
 * Snapshot (one point, one item):
 *   status:  { "v": 1, "id": int,
 *              "before": { "comment:<id>": { "status": string, "trash": [[key, value], ...] }, ... },
 *              "after":  { "comment:<id>": sha256, ... } }
 *   created: { "v": 1, "id": int, "after": { "comment": sha256, "use": sha256 } }
 *
 * A location of a status point is one comment: its comment_approved and its
 * rows of _wp_trash_meta_status and _wp_trash_meta_time, in which WordPress
 * keeps the status a comment had before the trash or spam. The comment the
 * tool was called for comes first; where trashing a top-level note
 * (comment_type note) took its reply notes into the trash, each of them
 * follows as a location of its own, since wp_untrash_comment() knows no
 * children. The undo puts each location back through WordPress: out of the
 * trash with wp_untrash_comment(), out of spam with wp_unspam_comment(), to
 * any other status with wp_set_comment_status() and the old one; the trash
 * rows of before are written again afterwards where those calls left others
 * (the trash and the spam of WordPress replace them). A comment or a reply
 * note moderated since is refused unless force is set; a comment its post
 * took into the trash (post-trashed) has no call that sets that status
 * again and is blocked (post_trashed).
 *
 * "comment" of a new comment is its row; "use" its replies and its meta rows
 * (id, key, sha256 of the value). The undo deletes it for good, as
 * wp_delete_comment with force does, only while both are as the change left
 * them, and refuses a comment edited, moderated or answered since unless
 * force is set; a comment deleted since is nothing to do.
 *
 * The account must have the right to edit each comment of the point
 * (edit_comment), as the tools ask it; force passes over later changes,
 * never over a right.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-undo-objects.php';

/**
 * Class AB_MCP_Undo_Comments
 */
class AB_MCP_Undo_Comments extends AB_MCP_Undo_Objects {

	/** The undo kind. */
	const KIND = 'comment';

	/** The tools that record this kind, and what they did to the comment. */
	const TOOLS = array(
		'wp_moderate_comment' => 'status',
		'wp_delete_comment'   => 'status',
		'wp_reply_comment'    => 'created',
	);

	/** The statuses wp_set_comment_status() writes, as comment_approved holds them. */
	const SETTABLE = array( '0', '1', 'spam', 'trash' );

	/** The meta keys in which WordPress keeps the status of before the trash or spam. */
	const TRASH_KEYS = array( '_wp_trash_meta_status', '_wp_trash_meta_time' );

	/** The columns of the row of a new comment that count. */
	const COLUMNS = 'comment_post_ID, comment_author, comment_author_email, comment_author_url, comment_author_IP, comment_date, comment_date_gmt, comment_content, comment_karma, comment_approved, comment_agent, comment_type, comment_parent, user_id';

	/** The reader of a comment, named in the answers. */
	const READER = 'wp_get_comment';

	/* ------------------------------------------------------------- the tables */

	/**
	 * The status of a comment as the tables hold it: comment_approved, its
	 * trash rows ([key, value] in the order of meta_id), and whether it is a
	 * top-level note; false when there is no such comment, null when a table
	 * could not be read.
	 *
	 * @param int $id Comment id.
	 * @return array|false|null { status, trash, note }
	 */
	public static function status_of( $id ) {
		global $wpdb;
		if ( ! self::has_tables( array( 'comments', 'commentmeta' ) ) ) {
			return null;
		}
		$row = self::select( $wpdb->prepare( "SELECT comment_approved, comment_type, comment_parent FROM {$wpdb->comments} WHERE comment_ID = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb.
		if ( null === $row ) {
			return null;
		}
		if ( ! isset( $row[0] ) ) {
			return false;
		}
		$meta = self::meta_rows( $id );
		if ( null === $meta ) {
			return null;
		}
		$trash = array();
		foreach ( $meta as $m ) {
			// The key byte for byte, not by the collation of the database.
			if ( in_array( $m[1], self::TRASH_KEYS, true ) ) {
				$trash[] = array( $m[1], $m[2] );
			}
		}
		return array(
			'status' => (string) ( $row[0]['comment_approved'] ?? '' ),
			'trash'  => $trash,
			'note'   => 'note' === (string) ( $row[0]['comment_type'] ?? '' ) && 0 === (int) ( $row[0]['comment_parent'] ?? 0 ),
		);
	}

	/**
	 * The meta rows of a comment, [meta_id, key, value] in the order of
	 * meta_id; null when the table could not be read.
	 *
	 * @param int $id Comment id.
	 * @return array[]|null
	 */
	private static function meta_rows( $id ) {
		global $wpdb;
		$rows = self::select( $wpdb->prepare( "SELECT meta_id, meta_key, meta_value FROM {$wpdb->commentmeta} WHERE comment_id = %d ORDER BY meta_id ASC", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb.
		if ( null === $rows ) {
			return null;
		}
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array( (int) ( $r['meta_id'] ?? 0 ), (string) ( $r['meta_key'] ?? '' ), (string) ( $r['meta_value'] ?? '' ) );
		}
		return $out;
	}

	/**
	 * The ids of the comments that name this one as their parent, of one
	 * comment type or of all; null when the table could not be read.
	 *
	 * @param int         $id   Comment id.
	 * @param string|null $type Only this comment_type (byte for byte), or null for all.
	 * @return int[]|null
	 */
	private static function replies( $id, $type = null ) {
		global $wpdb;
		$rows = self::select( $wpdb->prepare( "SELECT comment_ID, comment_type FROM {$wpdb->comments} WHERE comment_parent = %d ORDER BY comment_ID ASC", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb.
		if ( null === $rows ) {
			return null;
		}
		$out = array();
		foreach ( $rows as $r ) {
			if ( null === $type || (string) ( $r['comment_type'] ?? '' ) === $type ) {
				$out[] = (int) ( $r['comment_ID'] ?? 0 );
			}
		}
		return $out;
	}

	/**
	 * The row of a comment, column by column as text; false when there is no
	 * such comment, null when the table could not be read.
	 *
	 * @param int $id Comment id.
	 * @return array|false|null
	 */
	public static function row( $id ) {
		global $wpdb;
		if ( ! self::has_tables( array( 'comments', 'commentmeta' ) ) ) {
			return null;
		}
		$rows = self::select( $wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM {$wpdb->comments} WHERE comment_ID = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- columns from COLUMNS, table from $wpdb.
		if ( null === $rows ) {
			return null;
		}
		if ( ! isset( $rows[0] ) ) {
			return false;
		}
		$out = array();
		foreach ( array_map( 'trim', explode( ',', self::COLUMNS ) ) as $col ) {
			$out[ $col ] = isset( $rows[0][ $col ] ) ? (string) $rows[0][ $col ] : '';
		}
		return $out;
	}

	/**
	 * What hangs on a new comment, by identity and content: its replies and
	 * its meta rows (id, key, sha256 of the value); null when the tables
	 * could not be read.
	 *
	 * @param int $id Comment id.
	 * @return array|null
	 */
	private static function usage( $id ) {
		$replies = self::replies( $id );
		$meta    = self::meta_rows( $id );
		if ( null === $replies || null === $meta ) {
			return null;
		}
		$rows = array();
		foreach ( $meta as $m ) {
			$rows[] = array( $m[0], $m[1], hash( 'sha256', $m[2] ) );
		}
		return array(
			'replies' => $replies,
			'meta'    => $rows,
		);
	}

	/**
	 * The part of a status that a location holds: comment_approved and the
	 * trash rows, as text.
	 *
	 * @param array $state status_of() or a location of a snapshot.
	 * @return array { status, trash }
	 */
	private static function loc_state( array $state ) {
		$trash = array();
		foreach ( isset( $state['trash'] ) && is_array( $state['trash'] ) ? $state['trash'] : array() as $pair ) {
			$pair    = array_values( (array) $pair );
			$trash[] = array( (string) ( $pair[0] ?? '' ), (string) ( $pair[1] ?? '' ) );
		}
		return array(
			'status' => (string) ( $state['status'] ?? '' ),
			'trash'  => $trash,
		);
	}

	/**
	 * The comment id of a location ("comment:12").
	 *
	 * @param string $loc Location.
	 * @return int
	 */
	private static function loc_id( $loc ) {
		return (int) substr( (string) $loc, strlen( 'comment:' ) );
	}

	/**
	 * Is the site's trash on? WordPress deletes a comment for good instead
	 * where EMPTY_TRASH_DAYS is 0 (wp_trash_comment()).
	 *
	 * @return bool
	 */
	public static function trash_on() {
		return ! defined( 'EMPTY_TRASH_DAYS' ) || (bool) EMPTY_TRASH_DAYS;
	}

	/**
	 * The status of comment_approved as wp_get_comment shows it: approved,
	 * unapproved, spam, trash, or false for any other (post-trashed).
	 *
	 * @param mixed $approved comment_approved.
	 * @return string|false
	 */
	public static function label( $approved ) {
		$map = array(
			'1'     => 'approved',
			'0'     => 'unapproved',
			'spam'  => 'spam',
			'trash' => 'trash',
		);
		$approved = is_scalar( $approved ) ? (string) $approved : '';
		return $map[ $approved ] ?? false;
	}

	/* --------------------------------------------------------------- hashes */

	/**
	 * @param array $snap Snapshot.
	 * @return array<string,string>|null
	 */
	public static function now_hashes( array $snap ) {
		if ( ! isset( $snap['before'] ) ) {
			$row = self::row( (int) $snap['id'] );
			if ( null === $row ) {
				return null;
			}
			if ( false === $row ) {
				return array(
					'comment' => self::ABSENT,
					'use'     => self::ABSENT,
				);
			}
			$use = self::usage( (int) $snap['id'] );
			if ( null === $use ) {
				return null;
			}
			return array(
				'comment' => self::hash( $row ),
				'use'     => self::hash( $use ),
			);
		}
		$out = array();
		foreach ( array_keys( (array) $snap['before'] ) as $loc ) {
			$state = self::status_of( self::loc_id( $loc ) );
			if ( null === $state ) {
				return null;
			}
			$out[ (string) $loc ] = false === $state ? self::ABSENT : self::hash( self::loc_state( $state ) );
		}
		return $out;
	}

	/**
	 * @param array $snap Snapshot.
	 * @return array<string,string>
	 */
	public static function before_hashes( array $snap ) {
		if ( ! isset( $snap['before'] ) ) {
			return array(
				'comment' => self::ABSENT,
				'use'     => self::ABSENT,
			);
		}
		$out = array();
		foreach ( (array) $snap['before'] as $loc => $state ) {
			$out[ (string) $loc ] = self::hash( self::loc_state( (array) $state ) );
		}
		return $out;
	}

	/**
	 * @param array $snap Snapshot.
	 * @return string
	 */
	public static function ref( array $snap ) {
		return 'comment:' . (int) $snap['id'];
	}

	/* ------------------------------------------------------------ recording */

	/**
	 * Run a call that sets the status of a comment — wp_spam_comment(),
	 * wp_trash_comment(), wp_set_comment_status(), wp_delete_comment() into
	 * the trash — and answer for it: the state before, the call, the
	 * read-back, the undo point. As AB_MCP_Undo_Terms::update(): a call that
	 * changed nothing while the comment does not hold the status asked for
	 * is an error ($spec['refused']); one that changed nothing because the
	 * comment had it already is no error, and gets no point.
	 *
	 * @param array    $spec  { tool, id, want: the status as wp_get_comment shows it, summary, refused: WP_Error }.
	 * @param callable $write The call.
	 * @return array|WP_Error { saved_fields, saved_differs?, warning?, undo, undo_note? }
	 */
	public static function set_status( array $spec, callable $write ) {
		$tool = (string) $spec['tool'];
		$id   = (int) $spec['id'];
		$want = (string) $spec['want'];
		$main = 'comment:' . $id;

		// The state before: the comment, and where the call trashes a
		// top-level note, its reply notes (wp_trash_comment() trashes them
		// too). null where a table could not be read: no point on a guess.
		$before = null;
		if ( self::on() ) {
			$state = self::status_of( $id );
			if ( is_array( $state ) ) {
				$before = array( $main => self::loc_state( $state ) );
				if ( 'trash' === $want && $state['note'] ) {
					$before = self::with_reply_notes( $before, $id );
				}
			}
		}

		$was = self::read_back( $id );
		call_user_func( $write );
		$now = self::read_back( $id );

		$snap    = null;
		$gone    = false;
		$changed = $now !== $was;
		if ( null !== $before ) {
			$hashes = self::now_hashes( array( 'before' => $before ) );
			if ( null !== $hashes ) {
				$old = self::before_hashes( array( 'before' => $before ) );
				// The comment of the call stays in the point; a reply note
				// only where the call changed it and left it there.
				$keep = array( $main => true );
				foreach ( $hashes as $loc => $sha ) {
					if ( $sha !== $old[ $loc ] && self::ABSENT !== $sha ) {
						$keep[ $loc ] = true;
					}
				}
				$changed = $hashes[ $main ] !== $old[ $main ] || count( $keep ) > 1;
				// Deleted on the way (a plugin on the hooks of the call):
				// nothing is left to put back.
				$gone = self::ABSENT === $hashes[ $main ];
				$snap = $gone ? null : array(
					'v'      => self::VERSION,
					'id'     => $id,
					'before' => array_intersect_key( $before, $keep ),
					'after'  => array_intersect_key( $hashes, $keep ),
				);
			}
		}

		$have = $now['status'] ?? null;
		if ( ! $changed && $have !== $want ) {
			return $spec['refused'];
		}
		$summary = (string) $spec['summary'];
		$notes   = null !== $snap ? count( $snap['before'] ) - 1 : 0;
		if ( $notes > 0 ) {
			$summary .= sprintf( ', with %d reply notes', $notes );
		}
		$outcome = $gone ? AB_MCP_Undo_Answer::note( null ) : self::outcome( $tool, self::READER, $changed, $snap, $summary );
		$res     = AB_MCP_Undo_Answer::saved( array(), array( 'status' => $want ), $now, self::READER );
		return AB_MCP_Undo_Answer::with( $res, $outcome );
	}

	/**
	 * The state before of the reply notes of a top-level note, after the
	 * note's own: wp_trash_comment() takes those still approved or pending
	 * into the trash with it. Which of them it took, set_status() sees after
	 * the call. null when a table could not be read.
	 *
	 * @param array $before The note's own location.
	 * @param int   $id     The note.
	 * @return array|null
	 */
	private static function with_reply_notes( array $before, $id ) {
		$kids = self::replies( $id, 'note' );
		if ( null === $kids ) {
			return null;
		}
		foreach ( $kids as $kid ) {
			$state = self::status_of( $kid );
			if ( null === $state ) {
				return null;
			}
			if ( is_array( $state ) ) {
				$before[ 'comment:' . $kid ] = self::loc_state( $state );
			}
		}
		return $before;
	}

	/**
	 * The undo and read-back part of the answer of wp_reply_comment: the
	 * point deletes the new comment again.
	 *
	 * @param int    $id      The new comment.
	 * @param array  $asked   field => value as WordPress was asked to store it: post_id, parent, content, status.
	 * @param string $summary Summary for wp_undo_list.
	 * @return array
	 */
	public static function created( $id, array $asked, $summary ) {
		$snap = null;
		if ( self::on() ) {
			$data  = array(
				'v'  => self::VERSION,
				'id' => (int) $id,
			);
			$after = self::now_hashes( $data );
			if ( null !== $after && self::ABSENT !== $after['comment'] ) {
				$data['after'] = $after;
				$snap          = $data;
			}
		}
		$outcome = self::outcome( 'wp_reply_comment', self::READER, true, $snap, $summary );
		$res     = AB_MCP_Undo_Answer::saved( array(), $asked, self::read_back( $id ), self::READER );
		return AB_MCP_Undo_Answer::with( $res, $outcome );
	}

	/**
	 * The comment as wp_get_comment shows it, read again past the comment
	 * cache: post_id, parent, content and status; empty when it is not there.
	 *
	 * @param int $id Comment id.
	 * @return array
	 */
	public static function read_back( $id ) {
		if ( function_exists( 'clean_comment_cache' ) ) {
			clean_comment_cache( (int) $id );
		}
		$c = get_comment( (int) $id );
		if ( ! is_object( $c ) ) {
			return array();
		}
		return array(
			'post_id' => (int) ( $c->comment_post_ID ?? 0 ),
			'parent'  => (int) ( $c->comment_parent ?? 0 ),
			'content' => isset( $c->comment_content ) && is_scalar( $c->comment_content ) ? (string) $c->comment_content : '',
			'status'  => self::label( $c->comment_approved ?? null ),
		);
	}

	/* ------------------------------------------------------------- restore */

	/**
	 * @param string $tool Tool of the point.
	 * @param array  $snap Snapshot (valid()).
	 * @return string
	 */
	public static function refusal( $tool, array $snap ) {
		$id = (int) $snap['id'];
		if ( ! isset( $snap['before'] ) ) {
			if ( ! is_array( self::row( $id ) ) ) {
				return 'gone';
			}
			return current_user_can( 'edit_comment', $id ) ? '' : 'forbidden';
		}
		if ( ! is_array( self::status_of( $id ) ) ) {
			return 'gone';
		}
		foreach ( array_keys( (array) $snap['before'] ) as $loc ) {
			$cid = self::loc_id( $loc );
			// A reply note deleted since has nothing to put back (put_back()
			// passes over it); every other one needs the right to it.
			if ( false !== self::status_of( $cid ) && ! current_user_can( 'edit_comment', $cid ) ) {
				return 'forbidden';
			}
		}
		foreach ( (array) $snap['before'] as $state ) {
			$status = (string) ( $state['status'] ?? '' );
			if ( ! in_array( $status, self::SETTABLE, true ) ) {
				// WordPress sets post-trashed only when the post goes into
				// the trash, and no call sets it again.
				return 'post-trashed' === $status ? 'post_trashed' : 'status_not_settable';
			}
		}
		return '';
	}

	/**
	 * @param array    $snap Snapshot.
	 * @param string[] $locs Locations that differ.
	 * @return true|WP_Error
	 */
	protected static function put_back( array $snap, array $locs ) {
		$id = (int) $snap['id'];
		if ( ! isset( $snap['before'] ) ) {
			$done = wp_delete_comment( $id, true );
			if ( ! $done || false !== self::row( $id ) ) {
				return new WP_Error(
					'ab_mcp_undo_write_failed',
					sprintf(
						/* translators: %d: comment id */
						__( 'WordPress did not delete comment %d again; the undo point stays. Check the comment with wp_get_comment, and try again.', 'alphabridge-mcp' ),
						$id
					)
				);
			}
			return true;
		}
		// The comment of the call first, then its reply notes, in the order
		// WordPress trashed them.
		foreach ( (array) $snap['before'] as $loc => $state ) {
			if ( ! in_array( (string) $loc, $locs, true ) ) {
				continue;
			}
			$done = self::status_back( self::loc_id( $loc ), self::loc_state( (array) $state ) );
			if ( true !== $done ) {
				return $done;
			}
		}
		return true;
	}

	/**
	 * Put the status of one comment back through WordPress, and its trash
	 * rows as they were, and read both again.
	 *
	 * @param int   $id   Comment id.
	 * @param array $want loc_state() of before.
	 * @return true|WP_Error
	 */
	private static function status_back( $id, array $want ) {
		$now = self::status_of( $id );
		if ( false === $now ) {
			// A reply note deleted for good since (force): nothing of it is left to put back.
			return true;
		}
		if ( null === $now ) {
			return new WP_Error( 'ab_mcp_undo_write_failed', __( 'The state could not be read before the restore; nothing was restored. Try again in a moment; the undo point stays.', 'alphabridge-mcp' ) );
		}
		// Out of the trash or spam the way WordPress takes a comment out:
		// its hooks run, and it sets the status it noted.
		if ( 'trash' === $now['status'] && 'trash' !== $want['status'] ) {
			wp_untrash_comment( $id );
		} elseif ( 'spam' === $now['status'] && 'spam' !== $want['status'] ) {
			wp_unspam_comment( $id );
		}
		$now = self::status_of( $id );
		if ( is_array( $now ) && $now['status'] !== $want['status'] ) {
			wp_set_comment_status( $id, $want['status'] );
			$now = self::status_of( $id );
		}
		// The rows WordPress noted before the change; the trash and the spam
		// replace them, untrash and unspam remove them.
		if ( is_array( $now ) && self::loc_state( $now )['trash'] !== $want['trash'] ) {
			foreach ( self::TRASH_KEYS as $key ) {
				delete_comment_meta( $id, $key );
			}
			foreach ( $want['trash'] as $pair ) {
				add_comment_meta( $id, $pair[0], wp_slash( $pair[1] ) );
			}
			$now = self::status_of( $id );
		}
		if ( ! is_array( $now ) || self::loc_state( $now ) !== $want ) {
			return new WP_Error(
				'ab_mcp_undo_write_failed',
				sprintf(
					/* translators: %d: comment id */
					__( 'WordPress did not take the status of comment %d back; the undo point stays. A plugin may hold the status of comments. Check the comment with wp_get_comment, and try again.', 'alphabridge-mcp' ),
					$id
				)
			);
		}
		return true;
	}

	/**
	 * @return WP_Error
	 */
	protected static function changed_error() {
		return new WP_Error( 'ab_mcp_undo_comment_changed', __( 'The comment was edited, moderated or answered after the undo was planned; nothing was restored. Pass force=true to restore it over the newer change.', 'alphabridge-mcp' ) );
	}

	/**
	 * @return WP_Error
	 */
	protected static function corrupt() {
		return new WP_Error( 'ab_mcp_undo_corrupt', __( 'The snapshot of the comment is not in a form this tool writes; nothing was restored. This undo point cannot be used; wp_undo_discard removes it.', 'alphabridge-mcp' ) );
	}

	/* ------------------------------------------------------------- the form */

	/**
	 * @param string $tool Tool of the point.
	 * @param mixed  $data Snapshot.
	 * @return bool
	 */
	public static function valid( $tool, $data ) {
		if ( ! isset( self::TOOLS[ $tool ] ) || ! is_array( $data ) || ! isset( $data['v'], $data['id'], $data['after'] ) ) {
			return false;
		}
		if ( self::VERSION !== $data['v'] || ! is_int( $data['id'] ) || $data['id'] <= 0 || ! is_array( $data['after'] ) ) {
			return false;
		}
		$keys = array_keys( $data );
		sort( $keys );
		if ( 'created' === self::TOOLS[ $tool ] ) {
			if ( array( 'after', 'id', 'v' ) !== $keys ) {
				return false;
			}
			$locs = array( 'comment', 'use' );
		} else {
			if ( array( 'after', 'before', 'id', 'v' ) !== $keys || ! is_array( $data['before'] ) || array() === $data['before'] ) {
				return false;
			}
			$locs = array_map( 'strval', array_keys( $data['before'] ) );
			// The comment of the call comes first; its reply notes follow.
			if ( 'comment:' . $data['id'] !== $locs[0] ) {
				return false;
			}
			foreach ( $data['before'] as $loc => $state ) {
				if ( 1 !== preg_match( '/\Acomment:[1-9][0-9]*\z/', (string) $loc ) || ! self::state_form( $state ) ) {
					return false;
				}
			}
			sort( $locs );
		}
		$after = array_map( 'strval', array_keys( $data['after'] ) );
		sort( $after );
		if ( $locs !== $after ) {
			return false;
		}
		foreach ( $data['after'] as $sha ) {
			if ( ! is_string( $sha ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $sha ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Is a location in the form set_status() writes: a status as
	 * comment_approved holds it, and trash rows that only WordPress' own
	 * keys and values could have written?
	 *
	 * @param mixed $state Location of a snapshot.
	 * @return bool
	 */
	private static function state_form( $state ) {
		if ( ! is_array( $state ) ) {
			return false;
		}
		$keys = array_keys( $state );
		sort( $keys );
		if ( array( 'status', 'trash' ) !== $keys || ! is_string( $state['status'] ) || 1 !== preg_match( '/\A[a-z0-9-]{1,20}\z/', $state['status'] ) || ! is_array( $state['trash'] ) || ! array_is_list( $state['trash'] ) ) {
			return false;
		}
		foreach ( $state['trash'] as $pair ) {
			if ( ! is_array( $pair ) || array( 0, 1 ) !== array_keys( $pair ) || ! is_string( $pair[0] ) || ! is_string( $pair[1] ) ) {
				return false;
			}
			$form = '_wp_trash_meta_status' === $pair[0] ? '/\A[a-z0-9-]{1,20}\z/' : ( '_wp_trash_meta_time' === $pair[0] ? '/\A[0-9]{1,20}\z/' : '' );
			if ( '' === $form || 1 !== preg_match( $form, $pair[1] ) ) {
				return false;
			}
		}
		return true;
	}
}
