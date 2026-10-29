<?php
/**
 * Base class of a page-builder writer: the one way Pro changes a page that
 * a page builder or block library keeps, whichever builder it is.
 *
 * A writer belongs to one reading adapter of the free core (same id, see
 * AB_MCP_Builders::adapters()). It knows how its builder stores and saves a
 * page; everything else is here, the same for every builder, in the order of
 * the contract (BUILDER-ARCHITEKTUR.md, «Gemeinsame Pflichten»):
 *   1. permission: edit_post on the post, the writer's own can_write(), and
 *      field-type security on every change (AB_MCP_Builder_Fields);
 *   2. layout_hash: refused when the page changed since it was read;
 *   3. locks: refused while another user has the page open; a warning for
 *      the account's own open editor (under 150 s) and for a recent lock,
 *      since an editor that is still open overwrites the change on its next
 *      save (T6 of the plan review);
 *   4. undo point builder_data: nothing is written without one while undo is
 *      on — except a point over the size limit with allow_without_undo; with
 *      undo off the write goes ahead and says it has no way back;
 *   5. write: the writer's write(), through the builder's own save path;
 *   6. read back: every changed field carries its new value and no element
 *      is lost; otherwise the page is put back — only where it is still in
 *      the state this write left it (B3), never over somebody else's change;
 *   7. caches: the builder's, the page caches, Elementor's sync marker.
 * Preview (apply false) runs 1–3, measures the undo point, and writes nothing.
 *
 * A concrete writer implements id(), set_fields(), replace() and write():
 * set_fields() and replace() work out the changes — element(), fields() and
 * the builder's own data help — and hand them to execute(), which runs the
 * seven steps. The steps are protected methods, so a writer can add to one
 * (call the parent) but cannot leave one out or change their order.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Builder_Writer
 */
abstract class AB_MCP_Builder_Writer {

	/** Seconds within which WordPress counts an edit lock as an open editor (wp_check_post_lock(), wp-admin/includes/post.php). */
	const LOCK_WINDOW = 150;

	/**
	 * Seconds within which an older lock still earns a warning: an editor
	 * left open in a sleeping browser stops refreshing its lock and wakes up
	 * holding the old page. A design choice, not a measured value; filter
	 * ab_mcp_pro_builder_recent_lock.
	 */
	const RECENT_LOCK = 43200;

	/** Most elements read for one change; far above what a page has. */
	const OUTLINE_MAX = 100000;

	/** Longest field value the outline gives a writer (AB_MCP_Builders::field_max_chars() upper bound). */
	const FIELD_CHARS = 10000;

	/** Tool an undo point is recorded for, unless execute() is told otherwise. */
	const TOOL = 'wp_update_builder_element';

	/** Element ids that are positions in the page, valid only with the layout_hash they were read with. */
	const PATH_ID = '~^(?:[bspwe]\d+(?:\.\d+)*|r\d+(?:\.c\d+)?)$~';

	/**
	 * The steps run by the last execute(), in order (for tests and for the
	 * answer's diagnostics).
	 *
	 * @var string[]
	 */
	protected $trail = array();

	/**
	 * Warnings of the running execute().
	 *
	 * @var string[]
	 */
	protected $warnings = array();

	/**
	 * Notes of the running execute().
	 *
	 * @var string[]
	 */
	protected $notes = array();

	/**
	 * Matchers built by text_engine(), by search, replacement and mode.
	 *
	 * @var array<string,AB_MCP_Replace_Engine>
	 */
	private $engines = array();

	/* ------------------------------------------- what a writer implements */

	/**
	 * The adapter id this writer writes (a key of AB_MCP_Builders::adapters()).
	 *
	 * @return string
	 */
	abstract public function id(): string;

	/**
	 * Change fields of one element: preview by default, written with
	 * $opts['apply']. Work out the changes and return execute()'s answer.
	 *
	 * @param WP_Post $post       Post.
	 * @param string  $element_id Element id from the outline.
	 * @param array   $fields     Field name (or kind alias) => new value.
	 * @param array   $opts       See execute().
	 * @return array|WP_Error execute()'s answer.
	 */
	abstract public function set_fields( $post, $element_id, array $fields, array $opts );

	/**
	 * Replace a text in every field this writer may write on the page:
	 * preview by default, written with $opts['apply']. Matches in locked
	 * elements and placeholders are left and counted
	 * ($change['locked_matches']; execute() hands the number on in its
	 * answer, and in the data of the error when nothing else matched).
	 * wp_search_replace calls this for the pages of this writer, with
	 * case_insensitive and regex in $opts; text_engine() matches the way the
	 * rest of that call does.
	 *
	 * @param WP_Post $post    Post.
	 * @param string  $search  Text to find.
	 * @param string  $replace Replacement.
	 * @param array   $opts    See execute(); plus case_insensitive, regex.
	 * @return array|WP_Error execute()'s answer.
	 */
	abstract public function replace( $post, $search, $replace, array $opts );

	/**
	 * Step 5: write the changes through the builder's own save path, every
	 * copy of the page in step. Called only after steps 1–4 passed, and only
	 * while every copy is still as the undo point took it. Must not record
	 * undo, empty caches or read back: execute() does.
	 *
	 * A write() that refuses before it has touched the page (it finds the
	 * page saved by somebody else in the meantime, say) returns a WP_Error
	 * whose data has 'written' => false: whatever the page holds then is not
	 * this change's, so execute() neither puts it back nor keeps the undo
	 * point. Any other WP_Error counts as a write that may have stopped half
	 * way.
	 *
	 * @param WP_Post $post   Post as read right before.
	 * @param array   $change { changes, element?, … } as handed to execute().
	 * @param array   $opts   Normalised options.
	 * @return true|WP_Error
	 */
	abstract protected function write( $post, array $change, array $opts );

	/* --------------------------------------- what a writer may override */

	/**
	 * Whether this writer can write this page now: true, or a WP_Error that
	 * says why only reading works and what to do instead (never a bare «not
	 * supported»). Asked for write_via too, so it must be cheap.
	 *
	 * @param WP_Post $post Post.
	 * @return true|WP_Error
	 */
	public function can_write( $post ) {
		return true;
	}

	/**
	 * Stored copies a write can change besides those the adapter lists
	 * (copies()), e.g. a draft the builder keeps in step or a copy the write
	 * creates: 'meta:<key>', 'post_content_filtered'. They go into the undo
	 * point, as missing where they do not exist yet.
	 *
	 * @param WP_Post $post Post.
	 * @return string[]
	 */
	public function touched_locs( $post ): array {
		return array();
	}

	/**
	 * Whether the builder has a draft of its own that publish=false writes
	 * into (Elementor: the autosave revision); false refuses publish=false.
	 *
	 * @return bool
	 */
	public function supports_draft(): bool {
		return false;
	}

	/**
	 * Whether this writer's way of writing was checked on a real
	 * installation; false makes preview and answer say «profile not yet
	 * measured». Default: what the reading adapter says of itself.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function verified( $post ): bool {
		$adapter = $this->adapter();
		return null !== $adapter && $adapter->verified();
	}

	/**
	 * Step 6, the comparison: true when the page as stored now carries every
	 * change and has lost no element, else a WP_Error saying what is off.
	 * The default reads the adapter's outline again; a writer whose outline
	 * shows a field in another form than it was written in gives 'expect'
	 * per change or overrides this.
	 *
	 * @param WP_Post $post       Post as stored now.
	 * @param array   $change     Change.
	 * @param array   $ids_before Element ids before the write, in order.
	 * @return true|WP_Error
	 */
	protected function readback( $post, array $change, array $ids_before ) {
		$adapter = $this->adapter();
		$outline = $adapter->outline( $post, $this->outline_opts() );
		$ids     = array();
		$by_id   = array();
		foreach ( $outline as $el ) {
			if ( isset( $el['id'] ) ) {
				$ids[] = (string) $el['id'];
				if ( ! isset( $by_id[ (string) $el['id'] ] ) ) {
					$by_id[ (string) $el['id'] ] = $el;
				}
			}
		}
		$lost = array_values( array_diff( $ids_before, $ids ) );
		if ( array() !== $lost || count( $ids ) !== count( $ids_before ) ) {
			return new WP_Error(
				'ab_mcp_builder_readback_elements',
				sprintf(
					/* translators: 1: number of elements before, 2: number after, 3: ids missing */
					__( 'After the save the page had %2$d elements instead of %1$d (missing: %3$s).', 'alphabridge-mcp' ),
					count( $ids_before ),
					count( $ids ),
					array() === $lost ? '-' : implode( ', ', array_slice( $lost, 0, 20 ) )
				)
			);
		}
		$off = array();
		foreach ( $change['changes'] as $c ) {
			$expect = array_key_exists( 'expect', $c ) ? $c['expect'] : $c['after'];
			$el     = isset( $by_id[ (string) $c['element'] ] ) ? $by_id[ (string) $c['element'] ] : null;
			$field  = ( null !== $el && isset( $el['fields'][ $c['field'] ] ) ) ? $el['fields'][ $c['field'] ] : null;
			if ( null === $field ) {
				// An emptied field drops out of the outline: that is its new value.
				if ( '' !== self::norm( $c['kind'], $expect ) ) {
					$off[] = $c['element'] . ':' . $c['field'];
				}
				continue;
			}
			if ( ! empty( $field['truncated'] ) ) {
				// The outline cut the field, possibly inside a tag; its start
				// is still the stored value's start, so that is compared.
				$have = self::norm( $c['kind'], $field['value'] );
				$want = self::norm( $c['kind'], $expect );
				if ( self::head( $have ) !== self::head( $want ) ) {
					$off[] = $c['element'] . ':' . $c['field'];
				}
				continue;
			}
			if ( self::norm( $c['kind'], $field['value'] ) !== self::norm( $c['kind'], $expect ) ) {
				$off[] = $c['element'] . ':' . $c['field'];
			}
		}
		if ( array() !== $off ) {
			return new WP_Error(
				'ab_mcp_builder_readback_fields',
				sprintf(
					/* translators: %s: element:field list */
					__( 'After the save these fields do not show their new value: %s.', 'alphabridge-mcp' ),
					implode( ', ', array_slice( $off, 0, 20 ) )
				)
			);
		}
		return true;
	}

	/**
	 * Put the page back after a failed write or read-back, through this
	 * writer. The default writes the undo snapshot back byte for byte and
	 * empties the caches (the same as wp_undo); a writer whose builder keeps
	 * state that a raw restore leaves stale overrides this and writes back
	 * through the builder's save path (B3 of the plan review).
	 *
	 * @param WP_Post $post     Post.
	 * @param array   $snapshot Snapshot item (AB_MCP_Undo_Builder::snapshot()).
	 * @return true|WP_Error
	 */
	protected function rollback( $post, array $snapshot ) {
		$res = AB_MCP_Undo_Builder::write_back( $snapshot );
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * Step 7 for this builder, beyond what AB_MCP_Builder_Caches empties
	 * for every writer. Return the names of the caches emptied.
	 *
	 * @param WP_Post $post Post.
	 * @param array   $opts Options.
	 * @return string[]
	 */
	protected function clear_caches( $post, array $opts ) {
		return array();
	}

	/* ---------------------------------------- helpers for set_fields() */

	/**
	 * The reading adapter of this writer.
	 *
	 * @return AB_MCP_Builder_Adapter|null
	 */
	public function adapter() {
		$all = AB_MCP_Builders::adapters();
		return isset( $all[ $this->id() ] ) ? $all[ $this->id() ] : null;
	}

	/**
	 * One element of the page by its outline id: refused when it is not there,
	 * not unique, or locked — with the reason and the way.
	 *
	 * @param WP_Post $post       Post.
	 * @param string  $element_id Element id.
	 * @return array|WP_Error Element of the outline (fields up to FIELD_CHARS characters).
	 */
	protected function element( $post, $element_id ) {
		$adapter = $this->adapter();
		if ( null === $adapter ) {
			return new WP_Error( 'ab_mcp_builder_no_reader', __( 'The reader of this page builder is not loaded on this site. Change the page in its editor in wp-admin.', 'alphabridge-mcp' ) );
		}
		$element_id = (string) $element_id;
		$hits       = array();
		foreach ( $adapter->outline( $post, $this->outline_opts() ) as $el ) {
			if ( isset( $el['id'] ) && (string) $el['id'] === $element_id ) {
				$hits[] = $el;
			}
		}
		if ( array() === $hits ) {
			return new WP_Error(
				'ab_mcp_builder_no_element',
				sprintf(
					/* translators: %s: element id */
					__( 'This page has no element %s. Read it again with wp_get_builder_layout and take the id from there; ids that are positions in the page (b0.2, s1, e0.1 …) change whenever the page changes.', 'alphabridge-mcp' ),
					$element_id
				)
			);
		}
		if ( count( $hits ) > 1 ) {
			/* translators: %s: element id */
			return new WP_Error( 'ab_mcp_builder_element_ambiguous', sprintf( __( 'More than one element of this page has the id %s, so it does not say which one to change. Change it in the page builder\'s editor.', 'alphabridge-mcp' ), $element_id ) );
		}
		$el = $hits[0];
		if ( ! empty( $el['locked'] ) ) {
			$reason = isset( $el['reason'] ) ? (string) $el['reason'] : 'locked';
			$msg    = $this->locked_message( $post, $element_id, $el );
			if ( ! empty( $el['global'] ) && isset( $el['note'] ) ) {
				/* translators: %s: note, e.g. "its content is post #12" */
				$msg .= ' ' . sprintf( __( 'It is a global part (%s): changing that post changes every page that shows it.', 'alphabridge-mcp' ), $el['note'] );
			}
			return new WP_Error( 'ab_mcp_builder_locked', $msg, array( 'reason' => $reason ) );
		}
		return $el;
	}

	/**
	 * Why a locked element is not changed, and the way. An element whose type
	 * the site or AlphaBridge does not know is not code that stays locked for
	 * good: its plugin may only be inactive, and the builder's editor — the
	 * way for code and forms — is the wrong advice for it, since a page
	 * builder may drop such an element when it saves the page. A writer that
	 * can tell more (Elementor: inactive plugin or Atomic Editor) adds to it.
	 *
	 * @param WP_Post $post       Post.
	 * @param string  $element_id Element id as asked.
	 * @param array   $el         Outline element (locked).
	 * @return string
	 */
	protected function locked_message( $post, $element_id, array $el ) {
		$adapter = $this->adapter();
		$name    = null !== $adapter ? $adapter->name() : '';
		$reason  = isset( $el['reason'] ) ? (string) $el['reason'] : 'locked';
		$type    = isset( $el['type'] ) ? (string) $el['type'] : '';
		if ( 'unregistered widget' === $reason ) {
			return sprintf(
				/* translators: 1: element id, 2: element type, 3: page builder name */
				__( 'Element %1$s is locked (unregistered widget): its type %2$s is not registered on this site, usually because the plugin that provides it is inactive. Activate that plugin, then read the page again with wp_get_builder_layout. Until then leave the page unsaved in the %3$s editor: a page builder may drop an element whose type it does not know when it saves.', 'alphabridge-mcp' ),
				$element_id,
				$type,
				$name
			);
		}
		if ( 'unknown element type' === $reason ) {
			return sprintf(
				/* translators: 1: element id, 2: element type, 3: page builder name */
				__( 'Element %1$s is locked (unknown element type): AlphaBridge does not know the type %2$s, so it leaves the element as it is. If it comes from a plugin that is inactive on this site, activate that plugin and read the page again with wp_get_builder_layout; otherwise change the element in the %3$s editor in wp-admin.', 'alphabridge-mcp' ),
				$element_id,
				$type,
				$name
			);
		}
		return sprintf(
			/* translators: 1: element id, 2: reason, e.g. "html widget", 3: page builder name */
			__( 'Element %1$s is locked (%2$s): AlphaBridge does not change code, HTML, shortcodes, forms, dynamic values or global parts. Change it in the %3$s editor in wp-admin.', 'alphabridge-mcp' ),
			$element_id,
			$reason,
			$name
		);
	}

	/**
	 * The requested fields of an element as checked changes
	 * (AB_MCP_Builder_Fields::changes()).
	 *
	 * @param array $element Element.
	 * @param array $fields  Field name (or kind alias) => value.
	 * @return array|WP_Error
	 */
	protected function fields( array $element, array $fields ) {
		return AB_MCP_Builder_Fields::changes( $element, $fields );
	}

	/**
	 * The matcher of wp_search_replace for a writer's replace(): the same
	 * case rule, regex mode and encoded forms of the text (&amp;, \u0026 …)
	 * as the rest of the call. Use it on each field value a writer may
	 * change: $value = $this->text_engine( $search, $replace, $opts )
	 * ->replace_string( $value, $count ). $opts carries case_insensitive and
	 * regex as wp_search_replace passes them.
	 *
	 * @param string $search  Text to find.
	 * @param string $replace Replacement.
	 * @param array  $opts    Options of replace().
	 * @return AB_MCP_Replace_Engine
	 */
	protected function text_engine( $search, $replace, array $opts ) {
		if ( ! class_exists( 'AB_MCP_Replace_Engine', false ) ) {
			require_once dirname( __DIR__, 2 ) . '/class-replace-engine.php';
		}
		$ci    = ! empty( $opts['case_insensitive'] );
		$regex = ! empty( $opts['regex'] );
		$key   = md5( serialize( array( (string) $search, (string) $replace, $ci, $regex ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- a cache key of scalars.
		if ( ! isset( $this->engines[ $key ] ) ) {
			$this->engines[ $key ] = new AB_MCP_Replace_Engine( (string) $search, (string) $replace, $ci, $regex );
		}
		return $this->engines[ $key ];
	}

	/**
	 * Matches a replace() left in locked places, as it reported them.
	 *
	 * @param array $change Change.
	 * @return int
	 */
	private static function locked_count( array $change ) {
		return isset( $change['locked_matches'] ) && is_numeric( $change['locked_matches'] ) ? max( 0, (int) $change['locked_matches'] ) : 0;
	}

	/**
	 * The reasons a replace() gave for matches it left in fields it writes
	 * otherwise ($change['left']). wp_search_replace passes them on per post;
	 * without them a match that was neither changed nor locked would leave no
	 * trace in its answer.
	 *
	 * @param array $change Change.
	 * @return string[]
	 */
	protected static function left_reasons( array $change ) {
		return isset( $change['left'] ) ? array_values( array_map( 'strval', (array) $change['left'] ) ) : array();
	}

	/**
	 * The changes whose new value differs from the stored one. A field set to
	 * the value it holds would still be saved — and a save is not neutral: a
	 * builder stamps its version and rebuilds its copies and caches — and
	 * would leave an undo point for nothing. Links compare as the read-back
	 * compares them (entities decoded, trimmed), images by attachment id,
	 * texts byte for byte (markup is part of the value).
	 *
	 * @param array $changes Changes.
	 * @return array
	 */
	protected static function changed_only( array $changes ) {
		$out = array();
		foreach ( $changes as $c ) {
			$kind   = isset( $c['kind'] ) ? (string) $c['kind'] : '';
			$before = array_key_exists( 'before', $c ) ? $c['before'] : null;
			$after  = array_key_exists( 'after', $c ) ? $c['after'] : null;
			if ( 'image' === $kind ) {
				$same = is_numeric( $before ) && is_numeric( $after ) && (int) $before === (int) $after;
			} elseif ( 'url' === $kind ) {
				$same = is_scalar( $before ) && is_scalar( $after ) && self::norm( 'url', $before ) === self::norm( 'url', $after );
			} else {
				$same = is_scalar( $before ) && is_scalar( $after ) && (string) $before === (string) $after;
			}
			if ( ! $same ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	/**
	 * The answer when every requested field already holds its new value.
	 *
	 * @return WP_Error
	 */
	protected static function nothing_changed() {
		return new WP_Error( 'ab_mcp_builder_nothing', __( 'The fields already hold these values; nothing was changed.', 'alphabridge-mcp' ) );
	}

	/* ------------------------------------------------------- the seven */

	/**
	 * Run the seven steps for a set of changes on one post.
	 *
	 * $change: { changes: [ { element, type, field, kind, before, after,
	 *   expect?, data? } ], element?: outline element (set_fields),
	 *   locked_matches?: int (replace), left?: string[] (replace: why a match
	 *   in a writable field was left), notes?: string[], warnings?: string[] }
	 * 'before' must be the field's full stored value (placeholders are checked
	 * on it): a change still marked 'truncated' is refused. 'data' is the
	 * writer's own (a path, a settings key) and is not shown.
	 *
	 * $opts: apply (false), layout_hash (''), publish (true),
	 *   allow_without_undo (false), undo_tool (wp_update_builder_element),
	 *   undo_token ('' — a token to add this post to as a part),
	 *   undo_summary (''), scope ('element' or 'text').
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $change Changes.
	 * @param array   $opts   Options.
	 * @return array|WP_Error Answer: { id, builder, builder_name, applied,
	 *   changes, layout_hash, undo_token, published, warnings, notes,
	 *   readback, locked_matches, left, caches? }; a refusal or failure as
	 *   WP_Error whose data may carry layout_hash, undo_token, rolled_back and
	 *   (when nothing was left to change) locked_matches and left.
	 */
	final protected function execute( $post, array $change, array $opts ) {
		$this->trail    = array();
		$this->warnings = isset( $change['warnings'] ) ? array_values( array_map( 'strval', (array) $change['warnings'] ) ) : array();
		$this->notes    = isset( $change['notes'] ) ? array_values( array_map( 'strval', (array) $change['notes'] ) ) : array();
		$opts           = self::normalize_opts( $opts );
		$adapter        = $this->adapter();
		$post           = is_object( $post ) ? self::fresh_post( (int) $post->ID ) : null;
		if ( null === $post ) {
			return new WP_Error( 'ab_mcp_not_found', __( 'Post not found. wp_list_posts or wp_search finds its id.', 'alphabridge-mcp' ) );
		}
		if ( null === $adapter ) {
			return new WP_Error( 'ab_mcp_builder_no_reader', __( 'The reader of this page builder is not loaded on this site. Change the page in its editor in wp-admin.', 'alphabridge-mcp' ) );
		}
		$change['changes'] = isset( $change['changes'] ) && is_array( $change['changes'] ) ? array_values( $change['changes'] ) : array();

		// 1. Permission and field types.
		$this->trail[] = 'permission';
		$ok            = $this->step_permission( $post, $change, $opts );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		// 2. layout_hash.
		$this->trail[] = 'layout_hash';
		$hash          = $this->step_layout_hash( $post, $change, $opts );
		if ( is_wp_error( $hash ) ) {
			return $hash;
		}

		// 3. Locks.
		$this->trail[] = 'lock';
		$locked        = $this->step_lock( $post, $opts );
		if ( is_wp_error( $locked ) ) {
			if ( $opts['apply'] ) {
				return $locked;
			}
			$this->warnings[] = $locked->get_error_message();
		}

		if ( ! $this->verified( $post ) ) {
			$this->warnings[] = sprintf(
				/* translators: %s: page builder name */
				__( 'profile not yet measured: how AlphaBridge writes %s pages has not been checked on a real installation yet. Check the page after the change.', 'alphabridge-mcp' ),
				$adapter->name()
			);
		}

		if ( ! $opts['apply'] ) {
			return $this->preview( $post, $adapter, $change, $opts, $hash );
		}

		// 4. Undo point.
		$this->trail[] = 'undo';
		$undo          = $this->step_undo( $post, $adapter, $change, $opts );
		if ( is_wp_error( $undo ) ) {
			return $undo;
		}
		$token = $undo['token'];
		$locs  = array_keys( $undo['snapshot']['locs'] );
		$ids   = self::ids( $adapter->outline( $post, $this->outline_opts() ) );

		// 5. Write — onto the page the undo point holds, or not at all. A save
		// by somebody else after the snapshot would otherwise be overwritten
		// by a change worked out from the page before it, or put back by the
		// read-back and by wp_undo as if it were this change (B3). A writer
		// that compares the page itself (blocks, shortcodes) does so from the
		// moment it read it; this covers every writer from the snapshot on.
		if ( AB_MCP_Undo_Builder::state_hashes( (int) $post->ID, $locs ) !== AB_MCP_Undo_Builder::before_hashes( $undo['snapshot'] ) ) {
			return $this->unwritten(
				new WP_Error( 'ab_mcp_builder_changed_meanwhile', __( 'The page was saved by someone else while this change was being prepared, and their version is kept. Read it again with wp_get_builder_layout and repeat the change.', 'alphabridge-mcp' ) ),
				$undo
			);
		}
		$this->trail[] = 'write';
		$written       = $this->step_write( $post, $change, $opts );
		if ( is_wp_error( $written ) && self::untouched( $written ) ) {
			return $this->unwritten( $written, $undo );
		}
		// The state this write left: what wp_undo may restore without force,
		// and the only state the read-back may roll back from (B3).
		$own = AB_MCP_Undo_Builder::state_hashes( (int) $post->ID, $locs );
		if ( null !== $token ) {
			$marked = AB_MCP_Undo_Builder::mark_written( $token, (int) $post->ID, $own );
			if ( is_wp_error( $marked ) ) {
				$this->warnings[] = __( 'The undo point does not know the state this change left, so wp_undo will ask for force=true.', 'alphabridge-mcp' );
			}
		}
		if ( is_wp_error( $written ) ) {
			return $this->failed( $post, $written, $undo, $own, $opts );
		}

		// 6. Read back.
		$this->trail[] = 'readback';
		$read          = $this->step_readback( $post, $change, $ids, $undo, $own, $opts );
		if ( is_wp_error( $read ) ) {
			return $read;
		}

		// 7. Caches.
		$this->trail[] = 'caches';
		$caches        = $this->step_caches( $post, $opts );

		$after = self::fresh_post( (int) $post->ID );
		return array(
			'id'           => (int) $post->ID,
			'builder'      => $this->id(),
			'builder_name' => $adapter->name(),
			'applied'      => true,
			'published'    => (bool) $opts['publish'],
			'changes'      => self::public_changes( $change['changes'] ),
			'layout_hash'  => null !== $after ? AB_MCP_Builders::layout_hash( $after ) : '',
			'undo_token'   => $token,
			'readback'     => $read,
			'caches'       => $caches,
			'locked_matches' => self::locked_count( $change ),
			'left'         => self::left_reasons( $change ),
			'warnings'     => array_values( array_unique( $this->warnings ) ),
			'notes'        => array_values( array_unique( $this->notes ) ),
		);
	}

	/**
	 * Step 1: edit_post on the post itself, the writer's can_write(), the
	 * draft option, and every change against the rules of its kind.
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $change Change.
	 * @param array   $opts   Options.
	 * @return true|WP_Error
	 */
	protected function step_permission( $post, array $change, array $opts ) {
		if ( 'revision' === $post->post_type ) {
			/* translators: %d: parent post id */
			return new WP_Error( 'ab_mcp_builder_revision', sprintf( __( 'This is a revision; revisions are not changed. Change post %d itself, or restore the revision with wp_restore_revision first.', 'alphabridge-mcp' ), (int) $post->post_parent ) );
		}
		if ( ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			return AB_MCP_Guidance::role_refusal( __( 'Your account cannot edit this post, so it cannot change its elements either. Ask someone who may edit it, or an administrator.', 'alphabridge-mcp' ) );
		}
		$can = $this->can_write( $post );
		if ( is_wp_error( $can ) ) {
			return $can;
		}
		if ( ! $opts['publish'] && ! $this->supports_draft() ) {
			return new WP_Error(
				'ab_mcp_builder_no_draft',
				sprintf(
					/* translators: %s: page builder name */
					__( '%s keeps no draft of its own that publish=false could write into. Leave publish out to change the page as it is shown, or make the change as a draft in the page builder\'s editor.', 'alphabridge-mcp' ),
					$this->adapter()->name()
				)
			);
		}
		if ( array() === $change['changes'] ) {
			// A replace() that found its text only in locked places says how
			// often: wp_search_replace counts those matches (locked_matches).
			return new WP_Error(
				'ab_mcp_builder_nothing',
				__( 'There is nothing to change on this page.', 'alphabridge-mcp' ),
				array(
					'locked_matches' => self::locked_count( $change ),
					'left'           => self::left_reasons( $change ),
				)
			);
		}
		foreach ( $change['changes'] as $c ) {
			if ( ! is_array( $c ) ) {
				return new WP_Error( 'ab_mcp_builder_bad_change', __( 'A change is not in the form a writer hands over.', 'alphabridge-mcp' ) );
			}
			$ok = AB_MCP_Builder_Fields::validate_change( $c );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		if ( ( isset( $change['element']['global'] ) && $change['element']['global'] ) || AB_MCP_Builder_Fields::is_global_post( $post ) ) {
			$this->warnings[] = __( 'This is a global part (a template or a part shown on many pages): the change appears everywhere it is used.', 'alphabridge-mcp' );
		}
		return true;
	}

	/**
	 * Step 2: the page as read. Refused when a layout_hash is given and the
	 * page has changed since, and when an element is addressed by its
	 * position without one when writing.
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $change Change.
	 * @param array   $opts   Options.
	 * @return string|WP_Error The current layout_hash.
	 */
	protected function step_layout_hash( $post, array $change, array $opts ) {
		$current = AB_MCP_Builders::layout_hash( $post );
		if ( '' !== $opts['layout_hash'] && ! hash_equals( $current, $opts['layout_hash'] ) ) {
			return new WP_Error(
				'ab_mcp_builder_layout_changed',
				__( 'The page has changed since it was read: layout_hash does not match. Nothing was written. Read it again with wp_get_builder_layout and use the new element ids and layout_hash.', 'alphabridge-mcp' ),
				array( 'layout_hash' => $current )
			);
		}
		if ( $opts['apply'] && '' === $opts['layout_hash'] ) {
			foreach ( $change['changes'] as $c ) {
				if ( 1 === preg_match( self::PATH_ID, (string) $c['element'] ) ) {
					return new WP_Error(
						'ab_mcp_builder_path_needs_hash',
						sprintf(
							/* translators: %s: element id */
							__( 'Element %s is addressed by its position in the page, which holds only for the page as it was read. Pass the layout_hash from wp_get_builder_layout (or from this preview) with apply=true.', 'alphabridge-mcp' ),
							(string) $c['element']
						),
						array( 'layout_hash' => $current )
					);
				}
			}
		}
		return $current;
	}

	/**
	 * Step 3: edit locks. Another user's open editor refuses the write (a
	 * WP_Error; the preview passes it on as a warning); the account's own
	 * open editor, and any editor active recently, add a warning.
	 *
	 * @param WP_Post $post Post.
	 * @param array   $opts Options.
	 * @return true|WP_Error
	 */
	protected function step_lock( $post, array $opts ) {
		$id = (int) $post->ID;
		if ( ! function_exists( 'wp_check_post_lock' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/post.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$other = function_exists( 'wp_check_post_lock' ) ? wp_check_post_lock( $id ) : false;
		$raw   = (string) get_post_meta( $id, '_edit_lock', true );
		$parts = explode( ':', $raw );
		$time  = isset( $parts[0] ) ? (int) $parts[0] : 0;
		$user  = isset( $parts[1] ) ? (int) $parts[1] : 0;
		$age   = $time > 0 ? max( 0, time() - $time ) : null;
		if ( $other ) {
			return new WP_Error(
				'ab_mcp_builder_post_locked',
				sprintf(
					/* translators: %d: user id */
					__( 'User #%d has this page open in an editor right now. Nothing was written: their editor holds the page as it was and would overwrite this change when they save. Try again once they have closed it, or ask them to.', 'alphabridge-mcp' ),
					(int) $other
				),
				array( 'locked_by' => (int) $other )
			);
		}
		if ( null === $age ) {
			return true;
		}
		$window = (int) apply_filters( 'wp_check_post_lock_window', self::LOCK_WINDOW );
		if ( $user === (int) get_current_user_id() && $age < $window ) {
			/* translators: %d: seconds */
			$this->warnings[] = sprintf( __( 'Your own account has this page open in an editor (active %d seconds ago). Reload that editor before saving there, or it overwrites this change.', 'alphabridge-mcp' ), $age );
		} elseif ( $age < (int) apply_filters( 'ab_mcp_pro_builder_recent_lock', self::RECENT_LOCK ) ) {
			/* translators: %d: minutes */
			$this->warnings[] = sprintf( __( 'This page was open in an editor %d minutes ago. An editor that is still open holds the page as it was and overwrites this change when it saves; reload it first.', 'alphabridge-mcp' ), max( 1, (int) round( $age / 60 ) ) );
		}
		return true;
	}

	/**
	 * Step 4: the undo point. Recorded as a new point, or as a part of
	 * $opts['undo_token'].
	 *
	 * @param WP_Post                $post    Post.
	 * @param AB_MCP_Builder_Adapter $adapter Adapter.
	 * @param array                  $change  Change.
	 * @param array                  $opts    Options.
	 * @return array|WP_Error { token (string|null), snapshot, own_point (bool) }
	 */
	protected function step_undo( $post, $adapter, array $change, array $opts ) {
		$snapshot = AB_MCP_Undo_Builder::snapshot( $post, $adapter, $this->touched_locs( $post ) );
		$summary  = '' !== $opts['undo_summary'] ? $opts['undo_summary'] : self::summary( $post, $change );
		$token    = AB_MCP_Undo_Builder::record( $opts['undo_tool'], $opts['scope'], array( $snapshot ), '' !== $opts['undo_token'] ? $opts['undo_token'] : null, $summary );
		if ( ! is_wp_error( $token ) ) {
			return array(
				'token'     => $token,
				'snapshot'  => $snapshot,
				'own_point' => '' === $opts['undo_token'],
			);
		}
		$data = $token->get_error_data();
		if ( 'ab_mcp_undo_disabled' === $token->get_error_code() && is_array( $data ) && isset( $data['reason'] ) && 'switched_off' === $data['reason'] ) {
			$this->warnings[] = __( 'Undo is switched off on this site, so this change has no way back. An administrator switches it on with the wp_undo tool under Settings › AlphaBridge MCP › Fine-tuning.', 'alphabridge-mcp' );
			return array(
				'token'     => null,
				'snapshot'  => $snapshot,
				'own_point' => false,
			);
		}
		if ( 'ab_mcp_undo_too_large' === $token->get_error_code() ) {
			if ( $opts['allow_without_undo'] ) {
				$this->warnings[] = __( 'Written without an undo point, as allow_without_undo asked: the page\'s stored data is larger than an undo point may hold. This change has no way back through wp_undo.', 'alphabridge-mcp' ) . ' ' . $token->get_error_message();
				return array(
					'token'     => null,
					'snapshot'  => $snapshot,
					'own_point' => false,
				);
			}
			return new WP_Error(
				'ab_mcp_builder_undo_too_large',
				$token->get_error_message() . ' ' . __( 'Nothing was written. To write without a way back, call again with allow_without_undo=true.', 'alphabridge-mcp' ),
				$data
			);
		}
		return new WP_Error(
			'ab_mcp_builder_undo_failed',
			$token->get_error_message() . ' ' . __( 'Without an undo point nothing was written.', 'alphabridge-mcp' ),
			array( 'cause' => $token->get_error_code() )
		);
	}

	/**
	 * Step 5: the writer's write(), with any Throwable turned into an error.
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $change Change.
	 * @param array   $opts   Options.
	 * @return true|WP_Error
	 */
	protected function step_write( $post, array $change, array $opts ) {
		try {
			$res = $this->write( $post, $change, $opts );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'ab_mcp_builder_write_failed',
				/* translators: %s: error class */
				sprintf( __( 'The page builder failed while saving (%s).', 'alphabridge-mcp' ), get_class( $e ) )
			);
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( true !== $res ) {
			return new WP_Error( 'ab_mcp_builder_write_failed', __( 'The page builder did not confirm the save.', 'alphabridge-mcp' ) );
		}
		return true;
	}

	/**
	 * Step 6: read the page back; on a mismatch put it back, where it still
	 * is as this write left it, and fail with the undo token.
	 *
	 * @param WP_Post $post       Post.
	 * @param array   $change     Change.
	 * @param array   $ids_before Element ids before the write.
	 * @param array   $undo       step_undo()'s answer.
	 * @param array   $own        State hashes right after the write.
	 * @param array   $opts       Options.
	 * @return array|WP_Error { ok, checked, elements }
	 */
	protected function step_readback( $post, array $change, array $ids_before, array $undo, array $own, array $opts ) {
		$fresh = self::fresh_post( (int) $post->ID );
		$check = null === $fresh ? new WP_Error( 'ab_mcp_not_found', __( 'The post was gone after the save.', 'alphabridge-mcp' ) ) : $this->readback( $fresh, $change, $ids_before );
		if ( true === $check ) {
			return array(
				'ok'       => true,
				'checked'  => count( $change['changes'] ),
				'elements' => count( $ids_before ),
			);
		}
		if ( ! is_wp_error( $check ) ) {
			$check = new WP_Error( 'ab_mcp_builder_readback', __( 'The read-back gave no answer.', 'alphabridge-mcp' ) );
		}
		return $this->failed( null !== $fresh ? $fresh : $post, $check, $undo, $own, $opts, 'ab_mcp_builder_readback_failed' );
	}

	/**
	 * Step 7: the caches.
	 *
	 * @param WP_Post $post Post.
	 * @param array   $opts Options.
	 * @return array
	 */
	protected function step_caches( $post, array $opts ) {
		$out                   = AB_MCP_Builder_Caches::after_write( (int) $post->ID, $this->id(), (bool) $opts['publish'] );
		$own                   = $this->clear_caches( $post, $opts );
		$out['builder_caches'] = array_values( array_unique( array_merge( $out['builder_caches'], (array) $own ) ) );
		return $out;
	}

	/* ------------------------------------------------------------ private */

	/**
	 * The preview: what would change, and whether an undo point would fit.
	 *
	 * @param WP_Post                $post    Post.
	 * @param AB_MCP_Builder_Adapter $adapter Adapter.
	 * @param array                  $change  Change.
	 * @param array                  $opts    Options.
	 * @param string                 $hash    Current layout_hash.
	 * @return array
	 */
	private function preview( $post, $adapter, array $change, array $opts, $hash ) {
		$snapshot = AB_MCP_Undo_Builder::snapshot( $post, $adapter, $this->touched_locs( $post ) );
		$fits     = AB_MCP_Undo_Builder::check_size( $opts['scope'], array( $snapshot ) );
		if ( is_wp_error( $fits ) ) {
			if ( 'ab_mcp_undo_disabled' === $fits->get_error_code() ) {
				$this->warnings[] = __( 'Undo is switched off on this site: applying this change would leave no way back.', 'alphabridge-mcp' );
			} elseif ( 'ab_mcp_undo_too_large' === $fits->get_error_code() ) {
				$this->warnings[] = $fits->get_error_message() . ' ' . __( 'Applying will need allow_without_undo=true and will have no way back.', 'alphabridge-mcp' );
			} else {
				$this->warnings[] = $fits->get_error_message() . ' ' . __( 'Applying will be refused while no undo point can be recorded.', 'alphabridge-mcp' );
			}
		}
		$this->notes[] = __( 'Preview only, nothing was written. Call again with apply=true and this layout_hash to save.', 'alphabridge-mcp' );
		return array(
			'id'           => (int) $post->ID,
			'builder'      => $this->id(),
			'builder_name' => $adapter->name(),
			'applied'      => false,
			'published'    => false,
			'changes'      => self::public_changes( $change['changes'] ),
			'layout_hash'  => $hash,
			'undo_token'   => null,
			'readback'     => null,
			'locked_matches' => self::locked_count( $change ),
			'left'         => self::left_reasons( $change ),
			'warnings'     => array_values( array_unique( $this->warnings ) ),
			'notes'        => array_values( array_unique( $this->notes ) ),
		);
	}

	/**
	 * A write or read-back that failed: put the page back where it is still
	 * as this write left it (B3), drop an undo point that has nothing left to
	 * undo, and say what happened.
	 *
	 * @param WP_Post  $post  Post.
	 * @param WP_Error $error What failed.
	 * @param array    $undo  step_undo()'s answer.
	 * @param array    $own   State hashes right after the write.
	 * @param array    $opts  Options.
	 * @param string   $code  Error code of the answer.
	 * @return WP_Error
	 */
	private function failed( $post, WP_Error $error, array $undo, array $own, array $opts, $code = 'ab_mcp_builder_write_failed' ) {
		$snapshot = $undo['snapshot'];
		$locs     = array_keys( $snapshot['locs'] );
		$before   = AB_MCP_Undo_Builder::before_hashes( $snapshot );
		$now      = AB_MCP_Undo_Builder::state_hashes( (int) $post->ID, $locs );
		$token    = $undo['token'];
		$data     = array(
			'undo_token'  => $token,
			'rolled_back' => false,
			'cause'       => $error->get_error_code(),
		);
		if ( $now === $before ) {
			// Nothing stuck: no change to undo, no point to keep.
			if ( null !== $token && ! empty( $undo['own_point'] ) ) {
				AB_MCP_Undo::discard( $token );
				$data['undo_token'] = null;
			}
			return new WP_Error( $code, $error->get_error_message() . ' ' . __( 'The page is unchanged. Try again; if it fails the same way, change it in the page builder\'s editor.', 'alphabridge-mcp' ), $data );
		}
		if ( $now !== $own ) {
			$this->trail[] = 'rollback_skipped';
			return new WP_Error(
				$code,
				$error->get_error_message() . ' ' . __( 'The page was not put back: it changed again after this save, and putting it back would undo that change too. Check it with wp_get_builder_layout; wp_undo with the undo token can restore it (force=true over the newer change).', 'alphabridge-mcp' ) . self::token_note( $token ),
				$data
			);
		}
		$this->trail[] = 'rollback';
		$back          = $this->rollback( $post, $snapshot );
		AB_MCP_Builder_Caches::after_write( (int) $post->ID, $this->id(), (bool) $opts['publish'] );
		$restored = AB_MCP_Undo_Builder::state_hashes( (int) $post->ID, $locs ) === $before;
		if ( ! is_wp_error( $back ) && $restored ) {
			$data['rolled_back'] = true;
			if ( null !== $token && ! empty( $undo['own_point'] ) ) {
				AB_MCP_Undo::discard( $token );
				$data['undo_token'] = null;
			}
			return new WP_Error( $code, $error->get_error_message() . ' ' . __( 'The page was put back as it was before; nothing changed. Try again; if it fails the same way, change it in the page builder\'s editor.', 'alphabridge-mcp' ), $data );
		}
		return new WP_Error(
			$code,
			$error->get_error_message() . ' ' . __( 'Putting the page back failed as well. Restore it with wp_undo and the undo token, then check it with wp_get_builder_layout.', 'alphabridge-mcp' ) . self::token_note( $token ),
			$data
		);
	}

	/**
	 * Whether write() said it refused before touching the page.
	 *
	 * @param WP_Error $error write()'s error.
	 * @return bool
	 */
	private static function untouched( WP_Error $error ) {
		$data = $error->get_error_data();
		return is_array( $data ) && array_key_exists( 'written', $data ) && false === $data['written'];
	}

	/**
	 * A write() that refused before it touched the page: the page is as
	 * somebody left it, so nothing is put back over it (B3), and an undo
	 * point of this call alone has nothing to undo.
	 *
	 * @param WP_Error $error write()'s error.
	 * @param array    $undo  step_undo()'s answer.
	 * @return WP_Error
	 */
	private function unwritten( WP_Error $error, array $undo ) {
		$this->trail[] = 'unwritten';
		$token         = $undo['token'];
		if ( null !== $token && ! empty( $undo['own_point'] ) ) {
			AB_MCP_Undo::discard( $token );
			$token = null;
		}
		return new WP_Error(
			$error->get_error_code(),
			$error->get_error_message() . ' ' . __( 'This call wrote nothing.', 'alphabridge-mcp' ),
			array(
				'undo_token'  => $token,
				'rolled_back' => false,
				'cause'       => $error->get_error_code(),
			)
		);
	}

	/**
	 * The undo token in the words of an error: a client sees only the
	 * message of a failed tool call, not its data.
	 *
	 * @param string|null $token Token.
	 * @return string
	 */
	private static function token_note( $token ) {
		if ( null === $token || '' === (string) $token ) {
			return ' ' . __( 'This change has no undo point.', 'alphabridge-mcp' );
		}
		/* translators: %s: undo token */
		return ' ' . sprintf( __( 'Undo token: %s.', 'alphabridge-mcp' ), (string) $token );
	}

	/**
	 * Options with their defaults and types.
	 *
	 * @param array $opts Options.
	 * @return array
	 */
	private static function normalize_opts( array $opts ) {
		$bool = static function ( $key, $default ) use ( $opts ) {
			return array_key_exists( $key, $opts ) ? (bool) $opts[ $key ] : $default;
		};
		$str  = static function ( $key, $default ) use ( $opts ) {
			return isset( $opts[ $key ] ) && is_scalar( $opts[ $key ] ) ? (string) $opts[ $key ] : $default;
		};
		$out = array(
			'apply'              => $bool( 'apply', false ),
			'layout_hash'        => $str( 'layout_hash', '' ),
			'publish'            => $bool( 'publish', true ),
			'allow_without_undo' => $bool( 'allow_without_undo', false ),
			'undo_tool'          => $str( 'undo_tool', self::TOOL ),
			'undo_token'         => $str( 'undo_token', '' ),
			'undo_summary'       => $str( 'undo_summary', '' ),
			'scope'              => $str( 'scope', 'element' ),
		);
		if ( ! in_array( $out['scope'], AB_MCP_Undo_Builder::SCOPES, true ) ) {
			$out['scope'] = 'element';
		}
		return $out;
	}

	/**
	 * Options for reading the outline: everything, fields at full length.
	 *
	 * @return array
	 */
	protected function outline_opts() {
		return array(
			'include_locked'  => true,
			'max_elements'    => self::OUTLINE_MAX,
			'max_field_chars' => self::FIELD_CHARS,
		);
	}

	/**
	 * The post as the table has it now.
	 *
	 * @param int $id Post id.
	 * @return WP_Post|null
	 */
	protected static function fresh_post( $id ) {
		wp_cache_delete( (int) $id, 'posts' );
		$post = get_post( (int) $id );
		return $post ? $post : null;
	}

	/**
	 * Element ids of an outline, in order.
	 *
	 * @param array $outline Outline.
	 * @return string[]
	 */
	private static function ids( array $outline ) {
		$ids = array();
		foreach ( $outline as $el ) {
			if ( isset( $el['id'] ) ) {
				$ids[] = (string) $el['id'];
			}
		}
		return $ids;
	}

	/**
	 * A value in the form two readings of a field can be compared in.
	 *
	 * @param string $kind  Kind.
	 * @param mixed  $value Value.
	 * @return string
	 */
	protected static function norm( $kind, $value ) {
		if ( 'image' === $kind ) {
			return is_numeric( $value ) ? (string) (int) $value : trim( (string) $value );
		}
		if ( 'url' === $kind ) {
			return trim( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}
		return trim( (string) preg_replace( '~\s+~u', ' ', AB_MCP_Builders::field_text( (string) $value ) ) );
	}

	/**
	 * The first 200 characters of a normalised value.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function head( $text ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $text, 0, 200, 'UTF-8' ) : substr( (string) $text, 0, 200 );
	}

	/**
	 * The changes as the answer shows them, without the writer's own data.
	 *
	 * @param array $changes Changes.
	 * @return array
	 */
	private static function public_changes( array $changes ) {
		$out = array();
		foreach ( $changes as $c ) {
			$out[] = array(
				'element' => (string) $c['element'],
				'type'    => isset( $c['type'] ) ? (string) $c['type'] : '',
				'field'   => (string) $c['field'],
				'kind'    => (string) $c['kind'],
				'before'  => $c['before'],
				'after'   => $c['after'],
			);
		}
		return $out;
	}

	/**
	 * The undo list's line for this change.
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $change Change.
	 * @return string
	 */
	private static function summary( $post, array $change ) {
		$fields = array();
		foreach ( $change['changes'] as $c ) {
			$fields[] = $c['element'] . '.' . $c['field'];
		}
		return sprintf( '#%d: %s', (int) $post->ID, implode( ', ', array_slice( array_unique( $fields ), 0, 8 ) ) );
	}

	/**
	 * The steps the last execute() ran, in order.
	 *
	 * @return string[]
	 */
	public function trail() {
		return $this->trail;
	}
}
