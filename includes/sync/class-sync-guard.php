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
 * THE FILTERS are hooked on every request of a site that has had a run
 * (arm(), on plugins_loaded), whether a boundary holds at that moment or
 * not, and each of them reads the record afresh from its row when it is
 * asked (holding(): the row of the option, past the caches of this
 * request). A request that passed plugins_loaded before the boundary began
 * (its alloptions and notoptions caches do not hold the record) meets the
 * record all the same when it writes later (Codex round 1 of branch 8, A1).
 * The price is one read of the row per write through a hooked way; the
 * ways of group B that never carry a covered object (the transients and
 * the other excluded options) are answered without it.
 *
 * THE MARK (MARK, an option autoloaded with the others of the request, so
 * it costs no query of its own): '1' on a site that has had a run, set
 * when a run is made (AB_MCP_Sync_Run::create(), before its first step)
 * and when a boundary begins; '0' on a site that never had one, which
 * then hooks nothing and pays nothing for the lock (Codex round 2 of
 * branch 8, N3). Where no mark is stored (a site from before this version,
 * or the option removed), guarded() derives it once from the table of the
 * runs and stores it. Boundaries come from runs only, so the mark is set
 * before the first boundary of a site can begin; a request that passed
 * plugins_loaded before the first run of its site was made and writes
 * after a boundary of that run began is not refused (GRENZEN-LAUF.md).
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
 *  - a covered term (branch 9): a save through the REST API with the
 *    message (rest_request_before_callbacks for a write to the term by its
 *    id through a controller of terms: rest_pre_insert_{$taxonomy} cannot
 *    refuse, WP_REST_Terms_Controller::update_item() casts what it answers
 *    to the arguments of wp_update_term()); its meta, by the object and by
 *    the meta id, as for posts;
 *  - a covered option: pre_update_option answers the old value (still).
 * NOT refused, named in the texts (decision 19): wp_publish_post() (the
 * cron publishing a scheduled post writes the status by SQL), the classic
 * way of terms (wp_update_term() and wp_delete_term() of another plugin or
 * of wp-admin's term screens, which have no filter to refuse with),
 * wp_set_object_terms(), delete_option(), any writer past
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
require_once __DIR__ . '/class-sync-schema.php';

/**
 * Class AB_MCP_Sync_Guard
 */
final class AB_MCP_Sync_Guard {

	/** The option that holds the boundary. */
	const OPTION = 'ab_mcp_sync_boundary';

	/** The mark of a site that has had a run ('1') or none ('0'); autoloaded. */
	const MARK = 'ab_mcp_sync_guard';

	/** Seconds a boundary holds at most: the lock of AB_MCP_Change_Lock. */
	const TTL = AB_MCP_Change_Lock::TIMEOUT;

	/** The code of the refusal. */
	const CODE = 'ab_mcp_sync_boundary';

	/** @var bool Whether this request owns the boundary (the step that writes). */
	public static $owner = false;

	/** @var int|null The clock for tests; time() when null. */
	public static $now = null;

	/**
	 * The rows of options a call of the step's own writes right now, each
	 * with the bytes it is to store (only()): while it runs, every other
	 * write to such a row is refused, also one of the step's own request
	 * (a hook in the call that writes the row nested; Codex round 5 of
	 * branch 11, C1, as decision 24 has it: a shared row is written by its
	 * owner of the moment only).
	 *
	 * @var array<string,string>
	 */
	private static $only = array();

	/**
	 * The writes only() refused since the engine last took them
	 * (refusals()): the row and the fields the write would have changed. A
	 * refusal is never silent: the engine puts it in the journal and the
	 * object deviates (decision A; Codex round 5 of branch 11, C1).
	 *
	 * @var array<int,array{option:string,fields:string[]}>
	 */
	private static $refusals = array();

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
	 * @param array $terms      id => taxonomy of the terms the step writes (branch 9).
	 * @return true|WP_Error
	 */
	public static function begin( $run_id, $seq, array $posts, array $taxonomies = array(), array $options = array(), array $terms = array() ) {
		$record = array(
			'run_id'     => (int) $run_id,
			'seq'        => (int) $seq,
			'posts'      => array(),
			'taxonomies' => array_values( array_map( 'strval', $taxonomies ) ),
			'options'    => array_values( array_map( 'strval', $options ) ),
			'terms'      => array(),
			'until'      => self::now() + self::TTL,
			'user_id'    => (int) get_current_user_id(),
		);
		foreach ( $posts as $id => $type ) {
			$record['posts'][ (string) (int) $id ] = (string) $type;
		}
		foreach ( $terms as $id => $taxonomy ) {
			$record['terms'][ (string) (int) $id ] = (string) $taxonomy;
		}
		self::$owner = true;
		self::mark();
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
	 * of a covered object, each asking holding() when it runs; on a site
	 * that has had a run only (guarded(): the mark), so that a site without
	 * one pays nothing for the lock (N3).
	 */
	public static function arm() {
		if ( self::$owner || ! self::guarded() ) {
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
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'rest_term' ), 1, 3 );
		add_filter( 'add_term_metadata', array( __CLASS__, 'term_meta_by_object' ), 1, 2 );
		add_filter( 'update_term_metadata', array( __CLASS__, 'term_meta_by_object' ), 1, 2 );
		add_filter( 'delete_term_metadata', array( __CLASS__, 'term_meta_delete' ), 1, 5 );
		add_filter( 'update_term_metadata_by_mid', array( __CLASS__, 'term_meta_by_mid' ), 1, 2 );
		add_filter( 'delete_term_metadata_by_mid', array( __CLASS__, 'term_meta_by_mid' ), 1, 2 );
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
	 * Whether this site has had a run, so that its boundaries are to be
	 * refused: the mark (MARK), read with the autoloaded options of the
	 * request. Where none is stored, derived once from the table of the
	 * runs and stored: a site without the tables (a network, a failed
	 * install) can have no run; a table that cannot be read answers true
	 * for this request and stores nothing (fail closed, asked again).
	 *
	 * @return bool
	 */
	public static function guarded() {
		$mark = get_option( self::MARK, null );
		if ( '1' === $mark || '0' === $mark ) {
			return '1' === $mark;
		}
		$on = false;
		if ( AB_MCP_Sync_Schema::available() ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is ours.
			$row = $wpdb->get_var( 'SELECT run_id FROM `' . AB_MCP_Sync_Schema::table( 'runs' ) . '` LIMIT 1' );
			if ( '' !== (string) $wpdb->last_error ) {
				return true;
			}
			$on = null !== $row;
		}
		self::mark( $on );
		return $on;
	}

	/**
	 * Mark this site as one that has had a run (its boundaries are refused
	 * from the next request on), or as one that had none; written only
	 * where the mark changes, autoloaded.
	 *
	 * @param bool $on Whether the site has had a run.
	 */
	public static function mark( $on = true ) {
		$value = $on ? '1' : '0';
		if ( $value !== (string) get_option( self::MARK, '' ) ) {
			update_option( self::MARK, $value, true );
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
	 * rest_request_before_callbacks: a write to a covered term through the
	 * REST API (a controller of terms, the term by its id, any method that
	 * writes) is refused with the message (branch 9). The record is read only
	 * for such a request.
	 *
	 * @param mixed           $response Null, or what an earlier filter answered.
	 * @param array           $handler  The route handler (its callback).
	 * @param WP_REST_Request $request  Request.
	 * @return mixed|WP_Error
	 */
	public static function rest_term( $response, $handler, $request ) {
		if ( null !== $response || ! is_object( $request ) || ! method_exists( $request, 'get_method' ) || in_array( strtoupper( (string) $request->get_method() ), array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return $response;
		}
		$controller = isset( $handler['callback'] ) && is_array( $handler['callback'] ) && isset( $handler['callback'][0] ) ? $handler['callback'][0] : null;
		$id         = (int) $request->get_param( 'id' );
		if ( $id < 1 || ! $controller instanceof WP_REST_Terms_Controller ) {
			return $response;
		}
		$record = self::holding();
		if ( null !== $record && isset( $record['terms'][ (string) $id ] ) ) {
			return new WP_Error( self::CODE, self::message( $record ), array( 'status' => 423 ) );
		}
		return $response;
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
	 * Whether a term is covered by a boundary another request holds.
	 *
	 * @param mixed $id Term id.
	 * @return bool
	 */
	public static function covers_term( $id ) {
		$record = self::holding();
		return null !== $record && isset( $record['terms'][ (string) (int) $id ] );
	}

	/**
	 * add_term_metadata, update_term_metadata: the meta of a covered term is
	 * not written (false short-circuits).
	 *
	 * @param mixed $check     Null to go on.
	 * @param int   $object_id Term.
	 * @return mixed
	 */
	public static function term_meta_by_object( $check, $object_id ) {
		return self::covers_term( $object_id ) ? false : $check;
	}

	/**
	 * delete_term_metadata: the meta of a covered term is not removed; a
	 * delete across every term (delete_metadata() with delete_all) is
	 * refused while a boundary covers any term.
	 *
	 * @param mixed  $check      Null to go on.
	 * @param mixed  $object_id  Term.
	 * @param string $meta_key   Key.
	 * @param mixed  $meta_value Value.
	 * @param bool   $delete_all Whether the key goes from every term.
	 * @return mixed
	 */
	public static function term_meta_delete( $check, $object_id, $meta_key = '', $meta_value = '', $delete_all = false ) {
		if ( $delete_all ) {
			$record = self::holding();
			return null !== $record && ! empty( $record['terms'] ) ? false : $check;
		}
		return self::covers_term( $object_id ) ? false : $check;
	}

	/**
	 * update_term_metadata_by_mid, delete_term_metadata_by_mid: by the term
	 * the meta id belongs to.
	 *
	 * @param mixed $check   Null to go on.
	 * @param int   $meta_id Meta id.
	 * @return mixed
	 */
	public static function term_meta_by_mid( $check, $meta_id ) {
		$record = self::holding();
		if ( null === $record || empty( $record['terms'] ) ) {
			return $check;
		}
		$meta = get_metadata_by_mid( 'term', (int) $meta_id );
		return is_object( $meta ) && isset( $meta->term_id ) && isset( $record['terms'][ (string) (int) $meta->term_id ] ) ? false : $check;
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
		if ( isset( self::$only[ (string) $option ] ) ) {
			// A row the step's own call writes right now: its own planned
			// value, byte for byte as it is stored, and no other. A filter
			// on pre_update_option_{$option} runs before this one
			// (wp-includes/option.php:901, this filter :912): where it changed
			// the value, the write is refused, and the read back sees the row
			// other than the call was to write.
			if ( hash_equals( self::$only[ (string) $option ], self::stored( $value ) ) ) {
				return $value;
			}
			self::$refusals[] = array(
				'option' => (string) $option,
				'fields' => self::fields( $old_value, $value ),
			);
			return $old_value;
		}
		if ( self::$owner || AB_MCP_Sync_Exclusions::option( (string) $option ) ) {
			return $value;
		}
		$record = self::holding();
		return null !== $record && in_array( (string) $option, (array) $record['options'], true ) ? $old_value : $value;
	}

	/**
	 * A call of the step's own that writes one row of options (a core
	 * option, the row of a widget, sidebars_widgets, the theme mods): while
	 * it runs, the row takes the planned value only, as its bytes are
	 * stored, and refuses every other write (pre_update_option()), the
	 * nested one of a hook in the call as well (Codex round 5 of branch 11,
	 * C1: before, the request owned the boundary whole, a hook in
	 * pre_update_option_{$option} or pre_set_theme_mod_{$name} wrote a
	 * neighbouring field of the row, and the call then stored the row it had
	 * read before over it, unseen). The filter is hooked for the call where
	 * the lock was not armed in this request.
	 *
	 * @param string   $option The option.
	 * @param mixed    $value  The value the call is to store.
	 * @param callable $call   The call of the core.
	 * @return mixed What the call answers.
	 */
	public static function only( $option, $value, callable $call ) {
		$option                 = (string) $option;
		$hooked                 = false === has_filter( 'pre_update_option', array( __CLASS__, 'pre_update_option' ) );
		self::$only[ $option ] = self::stored( $value );
		if ( $hooked ) {
			add_filter( 'pre_update_option', array( __CLASS__, 'pre_update_option' ), 1, 3 );
		}
		try {
			return $call();
		} finally {
			unset( self::$only[ $option ] );
			if ( $hooked ) {
				remove_filter( 'pre_update_option', array( __CLASS__, 'pre_update_option' ), 1 );
			}
		}
	}

	/**
	 * The writes only() refused since the last call, taken (the engine,
	 * after each call of the step's own).
	 *
	 * @return array<int,array{option:string,fields:string[]}>
	 */
	public static function refusals() {
		$out            = self::$refusals;
		self::$refusals = array();
		return $out;
	}

	/**
	 * The fields of a row a refused write would have changed: its keys
	 * whose stored bytes differ, the positions within nav_menu_locations by
	 * their name; at most ten.
	 *
	 * @param mixed $old The row as it is.
	 * @param mixed $new What the write would have stored.
	 * @return string[]
	 */
	private static function fields( $old, $new ) {
		if ( ! is_array( $old ) || ! is_array( $new ) ) {
			return array();
		}
		$out = array();
		foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $key ) {
			$a = array_key_exists( $key, $old ) ? self::stored( $old[ $key ] ) : null;
			$b = array_key_exists( $key, $new ) ? self::stored( $new[ $key ] ) : null;
			if ( $a === $b ) {
				continue;
			}
			if ( 'nav_menu_locations' === (string) $key && isset( $old[ $key ], $new[ $key ] ) && is_array( $old[ $key ] ) && is_array( $new[ $key ] ) ) {
				foreach ( self::fields( $old[ $key ], $new[ $key ] ) as $location ) {
					$out[] = 'nav_menu_locations.' . $location;
				}
				continue;
			}
			$out[] = (string) $key;
		}
		return array_slice( $out, 0, 10 );
	}

	/**
	 * A value as update_option() stores it (maybe_serialize(), a scalar as
	 * its text), to compare bytes.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function stored( $value ) {
		return (string) maybe_serialize( $value );
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
