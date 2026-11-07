<?php
/**
 * The takeover lock of a write boundary (Z8, decision 19 of the plan of
 * 01.11.2026; concept «Echtes Staging», section 5; branch 8): while a
 * step of a run writes an object, the live site refuses the writes of
 * other users and processes to that object through the WordPress API,
 * with a message of its own; the step itself passes (it owns the
 * boundary, $owner).
 *
 * THE RECORD: an option of this site (OPTION): the run and step, the
 * posts (ids and their types), the taxonomies and the options the boundary
 * covers, and until when (TTL, the TIMEOUT of AB_MCP_Change_Lock: the lock
 * of the boundary, decision 18). A boundary begins with begin() and ends
 * with end(); a record older than TTL is none (the request that wrote it
 * ended; the run is interrupted, AB_MCP_Sync_Run::settle()).
 *
 * THE FILTERS are hooked on every request (arm(), on plugins_loaded),
 * whether a boundary holds at that moment or not, and each of them reads
 * the record afresh from its row when it is asked (holding(): the row of
 * the option, past the caches of this request). A request that passed
 * plugins_loaded before the boundary began (its alloptions and notoptions
 * caches do not hold the record) meets the record all the same when it
 * writes later (Codex round 1 of branch 8, A1). The price is one read of
 * the row per write through a hooked way; the ways of group B that never
 * carry a covered object (the transients and the other excluded options)
 * are answered without it.
 *
 * WHAT IS REFUSED for the objects of the record, as the actual writers go
 * (decision 19):
 *  - the REST API: rest_pre_insert_{$post_type} with the message, for
 *    every post type registered (hooked on rest_api_init);
 *  - the trash and deletions: pre_trash_post, pre_delete_post,
 *    pre_delete_attachment;
 *  - new terms of a covered taxonomy: pre_insert_term;
 *  - post meta: the short-circuit filters of add, update and delete, by
 *    the object and by the meta id (update_post_metadata_by_mid,
 *    delete_post_metadata_by_mid: the id names its post), and a delete of
 *    a key across every post (delete_post_meta_by_key(): the core hands
 *    over no object and delete_all) while a boundary covers any post;
 *  - the classic way: wp_insert_post_empty_content (the core's own text
 *    then), and the edit lock _edit_lock set on the posts, so an editor
 *    sees them taken;
 *  - a covered option: pre_update_option answers the old value (still).
 * NOT refused, named in the texts (decision 19): wp_publish_post() (the
 * cron publishing a scheduled post writes the status by SQL), the classic
 * way of terms, wp_set_object_terms(), delete_option(), any writer past
 * the WordPress API. For them a change between the check of the target and
 * the write can be lost without the read back seeing it; the step's own
 * check of the target hash right before the write narrows that window.
 *
 * Messages are English without gettext, as the other classes of the
 * takeover.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lifecycle/class-change-lock.php';
require_once __DIR__ . '/class-sync-exclusions.php';
require_once __DIR__ . '/class-sync-rows.php';

/**
 * Class AB_MCP_Sync_Guard
 */
final class AB_MCP_Sync_Guard {

	/** The option that holds the boundary. */
	const OPTION = 'ab_mcp_sync_boundary';

	/** Seconds a boundary holds at most: the lock of AB_MCP_Change_Lock. */
	const TTL = AB_MCP_Change_Lock::TIMEOUT;

	/** The code of the refusal. */
	const CODE = 'ab_mcp_sync_boundary';

	/** @var bool Whether this request owns the boundary (the step that writes). */
	public static $owner = false;

	/** @var int|null The clock for tests; time() when null. */
	public static $now = null;

	/* ---------------------------------------------------------- boundary */

	/**
	 * Begin a boundary: the record written, this request its owner, the
	 * edit lock set on the covered posts.
	 *
	 * @param int   $run_id     Run.
	 * @param int   $seq        Step.
	 * @param array $posts      id => post type of the posts the step writes.
	 * @param array $taxonomies Taxonomies new terms are refused in.
	 * @param array $options    Option names the step writes.
	 * @return true|WP_Error
	 */
	public static function begin( $run_id, $seq, array $posts, array $taxonomies = array(), array $options = array() ) {
		$record = array(
			'run_id'     => (int) $run_id,
			'seq'        => (int) $seq,
			'posts'      => array(),
			'taxonomies' => array_values( array_map( 'strval', $taxonomies ) ),
			'options'    => array_values( array_map( 'strval', $options ) ),
			'until'      => self::now() + self::TTL,
			'user_id'    => (int) get_current_user_id(),
		);
		foreach ( $posts as $id => $type ) {
			$record['posts'][ (string) (int) $id ] = (string) $type;
		}
		self::$owner = true;
		update_option( self::OPTION, $record, true );
		$read = get_option( self::OPTION );
		if ( ! is_array( $read ) || (int) $read['run_id'] !== (int) $run_id || (int) $read['seq'] !== (int) $seq ) {
			self::$owner = false;
			return new WP_Error( self::CODE . '_write', 'The lock of the write boundary could not be written to the options of this site, so the step writes nothing. Ask the host to check that the database accepts writes to the options, then call again.' );
		}
		foreach ( array_keys( $record['posts'] ) as $id ) {
			// The edit lock of wp-admin: an editor sees the post taken.
			update_post_meta( (int) $id, '_edit_lock', self::now() . ':' . (int) get_current_user_id() );
		}
		return true;
	}

	/**
	 * End the boundary of a step (the record removed where it is this one).
	 *
	 * @param int $run_id Run.
	 * @param int $seq    Step.
	 */
	public static function end( $run_id, $seq ) {
		$read = get_option( self::OPTION );
		if ( is_array( $read ) && (int) $read['run_id'] === (int) $run_id && (int) $read['seq'] === (int) $seq ) {
			delete_option( self::OPTION );
		}
		self::$owner = false;
	}

	/**
	 * The record of a boundary that holds now, or null (a record past its
	 * time is removed: its request ended). Read through the option API (the
	 * request's own caches): for the owner and the tests.
	 *
	 * @return array|null
	 */
	public static function record() {
		$read = get_option( self::OPTION );
		if ( ! self::shaped( $read ) ) {
			return null;
		}
		if ( (int) $read['until'] <= self::now() ) {
			delete_option( self::OPTION );
			return null;
		}
		return $read;
	}

	/**
	 * The record of a boundary another request holds right now, read afresh
	 * from the row of the option (past the alloptions and notoptions caches
	 * of this request); null where none holds, or this request owns it.
	 *
	 * @return array|null
	 */
	public static function holding() {
		if ( self::$owner ) {
			return null;
		}
		$row = AB_MCP_Sync_Rows::option( self::OPTION );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$read = maybe_unserialize( (string) $row['value'] );
		if ( ! self::shaped( $read ) || (int) $read['until'] <= self::now() ) {
			return null;
		}
		return $read;
	}

	/**
	 * On plugins_loaded of every request: the filters that refuse the writes
	 * of a covered object, each asking holding() when it runs.
	 */
	public static function arm() {
		if ( self::$owner ) {
			return;
		}
		add_action( 'rest_api_init', array( __CLASS__, 'arm_rest' ), 1, 0 );
		add_filter( 'pre_trash_post', array( __CLASS__, 'pre_trash' ), 1, 2 );
		add_filter( 'pre_delete_post', array( __CLASS__, 'pre_delete' ), 1, 2 );
		add_filter( 'pre_delete_attachment', array( __CLASS__, 'pre_delete' ), 1, 2 );
		add_filter( 'pre_insert_term', array( __CLASS__, 'pre_insert_term' ), 1, 2 );
		add_filter( 'add_post_metadata', array( __CLASS__, 'meta_by_object' ), 1, 2 );
		add_filter( 'update_post_metadata', array( __CLASS__, 'meta_by_object' ), 1, 2 );
		add_filter( 'delete_post_metadata', array( __CLASS__, 'meta_delete' ), 1, 5 );
		add_filter( 'update_post_metadata_by_mid', array( __CLASS__, 'meta_by_mid' ), 1, 2 );
		add_filter( 'delete_post_metadata_by_mid', array( __CLASS__, 'meta_by_mid' ), 1, 2 );
		add_filter( 'wp_insert_post_empty_content', array( __CLASS__, 'empty_content' ), 1, 2 );
		add_filter( 'pre_update_option', array( __CLASS__, 'pre_update_option' ), 1, 3 );
	}

	/**
	 * On rest_api_init: rest_pre_insert_{$post_type} for every post type
	 * registered (the block editor saves through it).
	 */
	public static function arm_rest() {
		foreach ( (array) get_post_types( array(), 'names' ) as $type ) {
			add_filter( 'rest_pre_insert_' . $type, array( __CLASS__, 'rest_pre_insert' ), 1, 2 );
		}
	}

	/**
	 * Forget the ownership of this request (tests).
	 */
	public static function disarm() {
		self::$owner = false;
	}

	/* ------------------------------------------------------------ filters */

	/**
	 * Whether a post is covered by a boundary another request holds.
	 *
	 * @param mixed $id Post id.
	 * @return bool
	 */
	public static function covers( $id ) {
		$record = self::holding();
		return null !== $record && isset( $record['posts'][ (string) (int) $id ] );
	}

	/**
	 * The message of a refusal: what runs, until when.
	 *
	 * @param array|null $record The record that holds (holding()).
	 * @return string
	 */
	public static function message( $record = null ) {
		$left = is_array( $record ) ? max( 0, (int) $record['until'] - self::now() ) : 0;
		return sprintf( 'A takeover from the staging site is writing this object right now (AlphaBridge run %1$d, step %2$d); it is free again in at most %3$d s. Save again then. Nothing of yours was written.', is_array( $record ) ? (int) $record['run_id'] : 0, is_array( $record ) ? (int) $record['seq'] : 0, $left );
	}

	/**
	 * rest_pre_insert_{$post_type}: a REST save of a covered post is refused
	 * with the message (the block editor shows it).
	 *
	 * @param mixed           $prepared The post as prepared.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed|WP_Error
	 */
	public static function rest_pre_insert( $prepared, $request ) {
		$id = is_object( $prepared ) && isset( $prepared->ID ) ? (int) $prepared->ID : 0;
		if ( $id < 1 ) {
			return $prepared;
		}
		$record = self::holding();
		if ( null !== $record && isset( $record['posts'][ (string) $id ] ) ) {
			return new WP_Error( self::CODE, self::message( $record ), array( 'status' => 423 ) );
		}
		return $prepared;
	}

	/**
	 * pre_trash_post, pre_delete_post, pre_delete_attachment: refused for a
	 * covered post (false stops the core).
	 *
	 * @param mixed $check Null to go on.
	 * @param mixed $post  Post.
	 * @return mixed
	 */
	public static function pre_trash( $check, $post ) {
		return self::pre_delete( $check, $post );
	}

	/**
	 * @param mixed $check Null to go on.
	 * @param mixed $post  Post.
	 * @return mixed
	 */
	public static function pre_delete( $check, $post ) {
		$id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : (int) $post;
		return $id > 0 && self::covers( $id ) ? false : $check;
	}

	/**
	 * pre_insert_term: a new term of a covered taxonomy is refused.
	 *
	 * @param mixed  $term     Term.
	 * @param string $taxonomy Taxonomy.
	 * @return mixed|WP_Error
	 */
	public static function pre_insert_term( $term, $taxonomy ) {
		$record = self::holding();
		if ( null !== $record && in_array( (string) $taxonomy, (array) $record['taxonomies'], true ) ) {
			return new WP_Error( self::CODE, self::message( $record ) );
		}
		return $term;
	}

	/**
	 * add_post_metadata, update_post_metadata: the meta of a covered post is
	 * not written (false short-circuits).
	 *
	 * @param mixed $check     Null to go on.
	 * @param int   $object_id Post.
	 * @return mixed
	 */
	public static function meta_by_object( $check, $object_id ) {
		return self::covers( $object_id ) ? false : $check;
	}

	/**
	 * delete_post_metadata: the meta of a covered post is not removed; a
	 * delete across every post (delete_post_meta_by_key(): no object,
	 * delete_all) is refused while a boundary covers any post, since it
	 * would reach the covered ones.
	 *
	 * @param mixed  $check      Null to go on.
	 * @param mixed  $object_id  Post (null for a delete across every post).
	 * @param string $meta_key   Key.
	 * @param mixed  $meta_value Value.
	 * @param bool   $delete_all Whether the key goes from every post.
	 * @return mixed
	 */
	public static function meta_delete( $check, $object_id, $meta_key = '', $meta_value = '', $delete_all = false ) {
		if ( $delete_all ) {
			$record = self::holding();
			return null !== $record && array() !== (array) $record['posts'] ? false : $check;
		}
		return self::covers( $object_id ) ? false : $check;
	}

	/**
	 * update_post_metadata_by_mid, delete_post_metadata_by_mid: by the post
	 * the meta id belongs to.
	 *
	 * @param mixed $check   Null to go on.
	 * @param int   $meta_id Meta id.
	 * @return mixed
	 */
	public static function meta_by_mid( $check, $meta_id ) {
		$record = self::holding();
		if ( null === $record ) {
			return $check;
		}
		$meta = get_metadata_by_mid( 'post', (int) $meta_id );
		return is_object( $meta ) && isset( $meta->post_id ) && isset( $record['posts'][ (string) (int) $meta->post_id ] ) ? false : $check;
	}

	/**
	 * wp_insert_post_empty_content: the classic way (wp-admin's post.php,
	 * wp_update_post() of another plugin) is refused with the core's own
	 * text for a covered post.
	 *
	 * @param bool  $maybe_empty Whether the core found the post empty.
	 * @param array $postarr     The post as handed over.
	 * @return bool
	 */
	public static function empty_content( $maybe_empty, $postarr ) {
		$id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		return $id > 0 && self::covers( $id ) ? true : $maybe_empty;
	}

	/**
	 * pre_update_option: a covered option keeps its old value (still:
	 * update_option() then writes nothing). An option of group B (a
	 * transient, a cache row) is never covered and is answered without a
	 * read.
	 *
	 * @param mixed  $value     New value.
	 * @param string $option    Option name.
	 * @param mixed  $old_value Old value.
	 * @return mixed
	 */
	public static function pre_update_option( $value, $option, $old_value = null ) {
		if ( self::$owner || AB_MCP_Sync_Exclusions::option( (string) $option ) ) {
			return $value;
		}
		$record = self::holding();
		return null !== $record && in_array( (string) $option, (array) $record['options'], true ) ? $old_value : $value;
	}

	/* ------------------------------------------------------------ helpers */

	/**
	 * @param mixed $read A stored value.
	 * @return bool Whether it is a record of a boundary.
	 */
	private static function shaped( $read ) {
		return is_array( $read ) && isset( $read['until'], $read['run_id'], $read['seq'], $read['posts'] ) && is_array( $read['posts'] ) && isset( $read['taxonomies'], $read['options'] );
	}

	/**
	 * @return int Seconds.
	 */
	private static function now() {
		return null !== self::$now ? (int) self::$now : time();
	}
}
