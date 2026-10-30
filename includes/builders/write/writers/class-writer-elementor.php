<?php
/**
 * Writer for pages built with Elementor: the page is the JSON tree in
 * _elementor_data (read by the free core's AB_MCP_Builder_Adapter_Elementor),
 * written back through Elementor itself, never as raw bytes.
 *
 * Two element formats share one tree, and each is written the way Elementor
 * writes it:
 * - V3 (section, container, widgets with plain settings): the tree is
 *   changed in memory and saved with the page's Elementor document
 *   (Document::save(), which re-sanitises, writes the text copy in
 *   post_content and empties Elementor's own caches). publish=false saves
 *   into the account's autosave revision the way Elementor's own MCP does
 *   (Document_Mutator::save_as_draft( …, true ), core/utils/document/
 *   document-mutator.php:188-279 in Elementor 4.3.3).
 * - V4 (atomic elements, settings wrapped as {"$$type", "value"}): handed to
 *   Elementor's own ability elementor/manage-elements, which knows the
 *   schema of every atomic prop (modules/mcp/abilities/manage-elements-
 *   ability.php). That ability always stages a change to a live page in the
 *   autosave; publish=true then promotes it with elementor/publish-document
 *   (publish-document-ability.php:61-141), as Elementor's MCP does, and the
 *   text copy in post_content, which promoting leaves alone, is written
 *   with Elementor's own save_plain_text(). Both abilities are
 *   gated on the Atomic Editor (modules/mcp/utils/mcp-v4-gate.php:28-59);
 *   while it is off, atomic elements are read only and the answer says how
 *   to switch it on. Where an administrator switched one of the two off
 *   under Fine-tuning (group «Abilities of other plugins»), that switch
 *   holds for AlphaBridge's own use of it too, as for SiteOrigin's
 *   layout-update: manage-elements off, atomic elements are read only;
 *   publish-document off, publish=false (the draft) is the way.
 *
 * Guards before anything is saved (T2 and §5.2 of the plan review):
 * - every element of the page is registered: Document::save() drops an
 *   element whose type Elementor does not know, without a word
 *   (core/base/document.php:1074-1114), so a missing Elementor Pro or a
 *   deactivated add-on would lose its widgets on any save;
 * - the element count of the tree handed to save() equals the stored one;
 * - for an account without unfiltered_html, Document::save() runs the whole
 *   page through kses (document.php:847-849, includes/utils.php:926-930):
 *   a page whose other content kses would change in substance (a script, an
 *   iframe, an event attribute someone with the capability stored) is
 *   refused rather than silently stripped;
 * - save() === false is a failure (document.php:838: the account may not
 *   edit the page with Elementor).
 * Undo, read-back, rollback and caches (with Editor_Sync_State) are the
 * base class's seven steps.
 *
 * Which fields: the free core's outline (Elementor's registered controls,
 * its own table, the B1 name deny list, dynamic values left out). Widgets of
 * third-party add-ons are read only until measured (B1 of the plan review):
 * a widget class outside Elementor and Elementor Pro is refused, unless the
 * filter ab_mcp_pro_elementor_writable_widget opens it.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Builder_Writer_Elementor
 */
class AB_MCP_Builder_Writer_Elementor extends AB_MCP_Builder_Writer {

	/** Meta key of the page tree (Elementor 4.3.3 core/base/document.php:44). */
	const DATA_KEY = '_elementor_data';

	/** Elementor's ability that changes atomic elements. */
	const ABILITY_ELEMENTS = 'elementor/manage-elements';

	/** Elementor's ability that promotes a staged autosave to the live page. */
	const ABILITY_PUBLISH = 'elementor/publish-document';

	/** Post statuses Elementor stages changes for in an autosave (document-mutator.php:264). */
	const LIVE_STATUSES = array( 'publish', 'private' );

	/** Element ids the free reader makes from positions ("e0.1.2"). */
	const PATH = '~^e(\d+(?:\.\d+)*)$~';

	/** Repeater field names of the outline: control.index.field. */
	const REPEATER_FIELD = '~^([^.]+)\.(\d+)\.([^.]+)$~';

	/** Most nesting levels walked; the free reader stops at the same depth. */
	const MAX_DEPTH = 512;

	/**
	 * The post the last write saved into: the page itself, or the autosave
	 * revision publish=false wrote (read back there).
	 *
	 * @var int
	 */
	private $target_id = 0;

	/**
	 * The autosave revision the last write created, 0 when it used one that
	 * existed or none (deleted again if the read-back fails).
	 *
	 * @var int
	 */
	private $created_autosave = 0;

	/**
	 * _elementor_data of the account's autosave before the last write
	 * wrote into it (null: there was none), put back if the read-back fails.
	 *
	 * @var string|null
	 */
	private $draft_prior = null;

	/**
	 * Adapter id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'elementor';
	}

	/**
	 * Elementor writes only where it is loaded: its documents and its
	 * elements manager. Cheap — asked for write_via too.
	 *
	 * @param WP_Post $post Post.
	 * @return true|WP_Error
	 */
	public function can_write( $post ) {
		if ( null === self::documents() || null === self::manager() ) {
			return new WP_Error(
				'ab_mcp_builder_elementor_not_loaded',
				__( 'Elementor is active but has not loaded its documents and elements here, so AlphaBridge only reads this page (wp_get_builder_layout). Change it in the Elementor editor, or try again once Elementor has finished loading.', 'alphabridge-mcp' )
			);
		}
		return true;
	}

	/**
	 * What a save writes besides the tree and its text copy: Elementor stamps
	 * its version on every save (document.php:1443-1460), and promoting an
	 * autosave copies the page settings too (includes/db.php copy_elementor_meta()).
	 *
	 * @param WP_Post $post Post.
	 * @return string[]
	 */
	public function touched_locs( $post ): array {
		return array( 'meta:_elementor_version', 'meta:_elementor_page_settings' );
	}

	/**
	 * publish=false needs Elementor's own draft path (Elementor 4.3 and newer).
	 *
	 * @return bool
	 */
	public function supports_draft(): bool {
		return class_exists( 'Elementor\\Core\\Utils\\Document\\Document_Mutator' ) && method_exists( 'Elementor\\Core\\Utils\\Document\\Document_Mutator', 'save_as_draft' );
	}

	/* --------------------------------------------------------- set_fields */

	/**
	 * Change fields of one element.
	 *
	 * @param WP_Post $post       Post.
	 * @param string  $element_id Element id from the outline.
	 * @param array   $fields     Field name (or kind alias) => new value.
	 * @param array   $opts       See AB_MCP_Builder_Writer::execute().
	 * @return array|WP_Error
	 */
	public function set_fields( $post, $element_id, array $fields, array $opts ) {
		$can = $this->can_write( $post );
		if ( is_wp_error( $can ) ) {
			return $can;
		}
		$el = $this->element( $post, $element_id );
		if ( is_wp_error( $el ) ) {
			return $el;
		}
		$tree = self::tree( $post );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}
		$path = self::locate( $tree, (string) $el['id'] );
		$node = null === $path ? null : self::node_at( $tree, $path );
		if ( null === $node ) {
			/* translators: %s: element id */
			return new WP_Error( 'ab_mcp_builder_no_element', sprintf( __( 'Element %s was not found in the stored page. Read it again with wp_get_builder_layout.', 'alphabridge-mcp' ), (string) $el['id'] ) );
		}
		$publish  = ! array_key_exists( 'publish', $opts ) || (bool) $opts['publish'];
		$atomic   = self::is_atomic( $node );
		$warnings = array();
		$notes    = array();

		$ok = $atomic ? $this->v4_gate( $post, $tree, $node, $publish ) : $this->v3_writable( $node );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		// The fields with their stored values, so the checks judge what is
		// stored (markup a heading already has may stay, placeholders are
		// counted in full) and the preview shows it.
		$refs = array();
		$full = $el;
		foreach ( isset( $el['fields'] ) && is_array( $el['fields'] ) ? $el['fields'] : array() as $name => $field ) {
			$ref = $atomic ? self::v4_ref( $node, (string) $name ) : self::v3_ref( $node, (string) $name );
			if ( null === $ref ) {
				continue;
			}
			$refs[ $name ] = $ref;
			if ( $ref['stored'] && 'image' !== $field['kind'] ) {
				$full['fields'][ $name ]['value'] = self::raw_text( $field['kind'], $ref['raw'] );
				unset( $full['fields'][ $name ]['truncated'] );
			}
		}
		$changes = $this->fields( $full, $fields );
		if ( is_wp_error( $changes ) ) {
			return $changes;
		}
		foreach ( $changes as $i => $c ) {
			if ( ! isset( $refs[ $c['field'] ] ) ) {
				/* translators: %s: field name */
				return new WP_Error( 'ab_mcp_builder_no_field', sprintf( __( 'The field %s was not found in the stored element. Read the page again with wp_get_builder_layout.', 'alphabridge-mcp' ), $c['field'] ) );
			}
			$ref = $refs[ $c['field'] ];
			if ( $atomic ) {
				$plain = self::v4_plain( $c, $ref );
				if ( is_wp_error( $plain ) ) {
					return $plain;
				}
				$changes[ $i ]['data'] = array(
					'v4'    => true,
					'eid'   => (string) $node['id'],
					'key'   => $ref['key'],
					'plain' => $plain,
				);
				if ( in_array( $c['kind'], array( 'url', 'image' ), true ) ) {
					$warnings[] = __( 'Links and images of atomic (V4) elements are handed to Elementor in a form read from Elementor\'s code, not measured on a real installation yet. Check the element after the change.', 'alphabridge-mcp' );
				}
				continue;
			}
			if ( null !== $ref['item'] && ! $ref['stored_list'] ) {
				return new WP_Error(
					'ab_mcp_builder_elementor_default_list',
					sprintf(
						/* translators: %s: field name */
						__( 'The list that holds %s is still Elementor\'s default and not stored on the page, so AlphaBridge would have to write the whole list. Change one item of it once in the Elementor editor; after that its fields can be changed here.', 'alphabridge-mcp' ),
						$c['field']
					)
				);
			}
			$changes[ $i ]['data'] = array(
				'v4'   => false,
				'path' => $path,
				'key'  => $ref['key'],
				'item' => $ref['item'],
				'sub'  => $ref['sub'],
			);
		}
		// Document::save() of an unchanged tree still stamps the version,
		// rebuilds the text copy and empties the caches: a field that already
		// holds its value is no change.
		$changes = self::changed_only( $changes );
		if ( array() === $changes ) {
			return self::nothing_changed();
		}

		$checks = $this->page_checks( $post, $tree, $publish, $atomic );
		if ( is_wp_error( $checks ) ) {
			return $checks;
		}
		$warnings = array_merge( $warnings, $checks['warnings'] );
		if ( isset( $el['note'] ) && false !== strpos( (string) $el['note'], AB_MCP_Block_Reader::NOTE_UNMEASURED ) ) {
			$warnings[] = __( 'The fields of this element come from Elementor\'s registration of the widget, not from a widget AlphaBridge has measured. Check the element after the change.', 'alphabridge-mcp' );
		}
		$notes = array_merge( $notes, self::draft_notes( $post, $publish ) );

		$hash_before = AB_MCP_Builders::layout_hash( $post );
		$res         = $this->execute(
			$post,
			array(
				'element'  => $el,
				'changes'  => $changes,
				'warnings' => $warnings,
				'notes'    => $notes,
			),
			$opts
		);
		return $this->after_draft( $post, $res, $hash_before, $opts );
	}

	/* ------------------------------------------------- locked and hidden */

	/**
	 * An element the outline does not list because it sits inside a locked
	 * one (the free reader lists no children of a locked element, e.g. of an
	 * atomic container while the Atomic Editor is off): said with the
	 * locked ancestor's reason and way, not as "no such element".
	 *
	 * @param WP_Post $post       Post.
	 * @param string  $element_id Element id.
	 * @return array|WP_Error
	 */
	protected function element( $post, $element_id ) {
		$el = parent::element( $post, $element_id );
		if ( ! is_wp_error( $el ) || 'ab_mcp_builder_no_element' !== $el->get_error_code() ) {
			return $el;
		}
		$inside = $this->inside_locked( $post, (string) $element_id );
		return null === $inside ? $el : $inside;
	}

	/**
	 * The locked ancestor of an element the outline leaves out, as an error
	 * with its way; null when the element is not in the stored tree or no
	 * locked ancestor hides it.
	 *
	 * @param WP_Post $post       Post.
	 * @param string  $element_id Element id.
	 * @return WP_Error|null
	 */
	private function inside_locked( $post, $element_id ) {
		$adapter = $this->adapter();
		$tree    = self::tree( $post );
		if ( null === $adapter || is_wp_error( $tree ) ) {
			return null;
		}
		$path = self::locate( $tree, $element_id );
		if ( null === $path || count( $path ) < 2 ) {
			return null;
		}
		$locked = array();
		foreach ( $adapter->outline( $post, $this->outline_opts() ) as $o ) {
			if ( ! empty( $o['locked'] ) && isset( $o['id'] ) ) {
				$locked[ (string) $o['id'] ] = $o;
			}
		}
		for ( $n = count( $path ) - 1; $n >= 1; $n-- ) {
			$at   = array_slice( $path, 0, $n );
			$node = self::node_at( $tree, $at );
			$ids  = array( 'e' . implode( '.', $at ) );
			if ( is_array( $node ) && isset( $node['id'] ) && is_scalar( $node['id'] ) && ! is_bool( $node['id'] ) ) {
				array_unshift( $ids, (string) $node['id'] );
			}
			foreach ( $ids as $aid ) {
				if ( ! isset( $locked[ $aid ] ) ) {
					continue;
				}
				$msg = sprintf(
					/* translators: 1: element id, 2: id of the locked element around it */
					__( 'Element %1$s sits inside element %2$s, which is locked, so wp_get_builder_layout does not list it and AlphaBridge does not change it.', 'alphabridge-mcp' ),
					$element_id,
					$aid
				);
				return new WP_Error(
					'ab_mcp_builder_locked',
					$msg . ' ' . $this->locked_message( $post, $aid, $locked[ $aid ] ),
					array(
						'reason' => isset( $locked[ $aid ]['reason'] ) ? (string) $locked[ $aid ]['reason'] : 'locked',
						'inside' => $aid,
					)
				);
			}
		}
		return null;
	}

	/**
	 * Elementor's own words for an element it has not registered: it does
	 * not show it and drops it on its next save (Document::save(),
	 * core/base/document.php:1074-1114), so the way is the plugin or the
	 * Atomic Editor that registers it — never a save in the Elementor editor.
	 *
	 * @param WP_Post $post       Post.
	 * @param string  $element_id Element id as asked.
	 * @param array   $el         Outline element (locked).
	 * @return string
	 */
	protected function locked_message( $post, $element_id, array $el ) {
		$reason = isset( $el['reason'] ) ? (string) $el['reason'] : '';
		if ( ! in_array( $reason, array( 'unregistered widget', 'unknown element type' ), true ) ) {
			return parent::locked_message( $post, $element_id, $el );
		}
		$tree = self::tree( $post );
		$path = is_wp_error( $tree ) ? null : self::locate( $tree, isset( $el['id'] ) ? (string) $el['id'] : (string) $element_id );
		$node = null === $path ? null : self::node_at( $tree, $path );
		if ( ! is_array( $node ) || ! self::unregistered( $node ) ) {
			return parent::locked_message( $post, $element_id, $el );
		}
		$type = self::node_type( $node );
		if ( 0 === strpos( $type, 'e-' ) && true === self::atomic_editor_off() ) {
			return sprintf(
				/* translators: 1: element id, 2: element type */
				__( 'Element %1$s (%2$s) is an atomic (V4) Elementor element, and while Elementor\'s Atomic Editor is off Elementor has no such element: it does not show it, nor anything inside it, and drops it when the page is saved. An administrator turns the Atomic Editor on under Elementor › Settings (Atomic Editor); then read the page again with wp_get_builder_layout. Until then do not save this page in the Elementor editor.', 'alphabridge-mcp' ),
				$element_id,
				$type
			);
		}
		return sprintf(
			/* translators: 1: element id, 2: element type */
			__( 'Element %1$s (%2$s) is not registered with Elementor on this site, usually because the plugin that provides it (Elementor Pro or an add-on) is inactive. Elementor does not show it and drops it when the page is saved. Activate that plugin, then read the page again with wp_get_builder_layout; until then do not save this page in the Elementor editor.', 'alphabridge-mcp' ),
			$element_id,
			$type
		);
	}

	/**
	 * Whether Elementor answers that it has no element of this node's type.
	 * False when it cannot be asked (not loaded, or it throws).
	 *
	 * @param array $node Element.
	 * @return bool
	 */
	private static function unregistered( array $node ) {
		$manager = self::manager();
		$el_type = isset( $node['elType'] ) && is_string( $node['elType'] ) ? $node['elType'] : '';
		if ( null === $manager || '' === $el_type ) {
			return false;
		}
		try {
			$known = $manager->get_element( $el_type, 'widget' === $el_type ? self::node_type( $node ) : null );
		} catch ( \Throwable $e ) {
			return false;
		}
		return ! is_object( $known );
	}

	/**
	 * The widget type of a widget, else the element type.
	 *
	 * @param array $node Element.
	 * @return string
	 */
	private static function node_type( array $node ) {
		$el_type = isset( $node['elType'] ) && is_string( $node['elType'] ) ? $node['elType'] : '';
		if ( 'widget' === $el_type ) {
			return isset( $node['widgetType'] ) && is_string( $node['widgetType'] ) ? $node['widgetType'] : '';
		}
		return $el_type;
	}

	/**
	 * Whether Elementor says its Atomic Editor is off: true, false, or null
	 * when this Elementor cannot tell (before 4.3).
	 *
	 * @return bool|null
	 */
	private static function atomic_editor_off() {
		$gate = 'Elementor\\Modules\\Mcp\\Utils\\Mcp_V4_Gate';
		if ( ! class_exists( $gate ) ) {
			return null;
		}
		try {
			if ( method_exists( $gate, 'is_atomic_editor_active' ) ) {
				return ! call_user_func( array( $gate, 'is_atomic_editor_active' ) );
			}
			if ( method_exists( $gate, 'is_available' ) ) {
				return is_wp_error( call_user_func( array( $gate, 'is_available' ), self::ABILITY_ELEMENTS ) );
			}
		} catch ( \Throwable $e ) {
			return null;
		}
		return null;
	}

	/* ------------------------------------------------------------ replace */

	/**
	 * Replace a text in the stored text, heading, HTML and link fields of
	 * every V3 element this writer may write. Matches anywhere else on the
	 * page (locked widgets, code, image addresses, atomic elements, fields a
	 * replacement would break) are left and counted as locked_matches.
	 *
	 * $opts beyond execute()'s: case_insensitive (false), regex (false), as
	 * in wp_search_replace.
	 *
	 * @param WP_Post $post    Post.
	 * @param string  $search  Text to find.
	 * @param string  $replace Replacement.
	 * @param array   $opts    Options.
	 * @return array|WP_Error
	 */
	public function replace( $post, $search, $replace, array $opts ) {
		$can = $this->can_write( $post );
		if ( is_wp_error( $can ) ) {
			return $can;
		}
		if ( '' === (string) $search ) {
			return new WP_Error( 'ab_mcp_builder_no_search', __( 'The text to find is empty. Pass the text to find as search.', 'alphabridge-mcp' ) );
		}
		if ( ! class_exists( 'AB_MCP_Replace_Engine' ) ) {
			require_once dirname( __DIR__, 3 ) . '/class-replace-engine.php';
		}
		$engine = new AB_MCP_Replace_Engine( (string) $search, (string) $replace, ! empty( $opts['case_insensitive'] ), ! empty( $opts['regex'] ) );
		if ( isset( $engine->error ) && is_wp_error( $engine->error ) ) {
			return $engine->error;
		}
		$tree = self::tree( $post );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}
		$total = 0;
		$engine->apply_value( $tree, $total );

		$adapter = $this->adapter();
		$changes = array();
		$notes   = array();
		$left    = array();
		$counted = 0;
		$atomic  = 0;
		foreach ( $adapter->outline( $post, $this->outline_opts() ) as $el ) {
			if ( ! empty( $el['locked'] ) || empty( $el['fields'] ) ) {
				continue;
			}
			$path = self::locate( $tree, (string) $el['id'] );
			$node = null === $path ? null : self::node_at( $tree, $path );
			if ( null === $node ) {
				continue;
			}
			if ( self::is_atomic( $node ) ) {
				++$atomic;
				continue;
			}
			if ( is_wp_error( $this->v3_writable( $node ) ) ) {
				continue;
			}
			foreach ( $el['fields'] as $name => $field ) {
				if ( 'image' === $field['kind'] ) {
					continue;
				}
				$ref = self::v3_ref( $node, (string) $name );
				if ( null === $ref || ! $ref['stored'] ) {
					continue; // A default is not on the page; a change would store it.
				}
				$before = self::raw_text( $field['kind'], $ref['raw'] );
				$n      = 0;
				$after  = $engine->replace_string( $before, $n );
				if ( 0 === $n || $after === $before ) {
					continue;
				}
				$c = array(
					'element' => (string) $el['id'],
					'type'    => (string) $el['type'],
					'field'   => (string) $name,
					'kind'    => (string) $field['kind'],
					'before'  => $before,
					'after'   => 'url' === $field['kind'] ? trim( $after ) : $after,
					'data'    => array(
						'v4'   => false,
						'path' => $path,
						'key'  => $ref['key'],
						'item' => $ref['item'],
						'sub'  => $ref['sub'],
					),
				);
				$ok = AB_MCP_Builder_Fields::validate_change( $c );
				if ( is_wp_error( $ok ) ) {
					/* translators: 1: element id, 2: field name, 3: reason */
					$left[] = sprintf( __( '%1$s.%2$s left as it is: %3$s', 'alphabridge-mcp' ), $c['element'], $c['field'], $ok->get_error_message() );
					continue;
				}
				$counted  += $n;
				$changes[] = $c;
			}
		}
		$locked = max( 0, $total - $counted );
		$notes  = array_merge( $left, $notes );
		if ( $atomic > 0 && $locked > 0 ) {
			$notes[] = __( 'Atomic (V4) elements are changed one at a time with wp_update_builder_element, which hands the change to Elementor; their matches are counted under locked_matches.', 'alphabridge-mcp' );
		}
		if ( $locked > 0 ) {
			/* translators: %d: number of matches */
			$notes[] = sprintf( _n( '%d match is in a place this text replace does not write (a locked or atomic element, code, an image address or a setting): run again with scope: raw to replace it as stored.', '%d matches are in places this text replace does not write (locked or atomic elements, code, image addresses or settings): run again with scope: raw to replace them as stored.', $locked, 'alphabridge-mcp' ), $locked );
		}
		if ( array() === $changes ) {
			return new WP_Error(
				'ab_mcp_builder_nothing',
				__( 'No text, heading, HTML or link field of this page that AlphaBridge writes contains the text. Check its fields with wp_get_builder_layout.', 'alphabridge-mcp' ) . ( array() !== $notes ? ' ' . implode( ' ', $notes ) : '' ),
				array(
					'locked_matches' => $locked,
					'left'           => $left,
				)
			);
		}
		$publish = ! array_key_exists( 'publish', $opts ) || (bool) $opts['publish'];
		$checks  = $this->page_checks( $post, $tree, $publish, false );
		if ( is_wp_error( $checks ) ) {
			return $checks;
		}
		if ( ! isset( $opts['scope'] ) ) {
			$opts['scope'] = 'text';
		}
		$hash_before = AB_MCP_Builders::layout_hash( $post );
		$res         = $this->execute(
			$post,
			array(
				'changes'        => $changes,
				'locked_matches' => $locked,
				'left'           => $left,
				'warnings'       => $checks['warnings'],
				'notes'          => array_merge( $notes, self::draft_notes( $post, $publish ) ),
			),
			$opts
		);
		return $this->after_draft( $post, $res, $hash_before, $opts );
	}

	/* -------------------------------------------------------------- write */

	/**
	 * Step 5: save through Elementor.
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $change Change.
	 * @param array   $opts   Options.
	 * @return true|WP_Error
	 */
	protected function write( $post, array $change, array $opts ) {
		$this->target_id        = (int) $post->ID;
		$this->created_autosave = 0;
		$this->draft_prior      = null;
		$first                  = reset( $change['changes'] );
		if ( is_array( $first ) && ! empty( $first['data']['v4'] ) ) {
			return $this->write_v4( $post, $change, $opts );
		}
		return $this->write_v3( $post, $change, $opts );
	}

	/**
	 * Read back where the write went: the autosave revision for
	 * publish=false on a live page, else the page.
	 *
	 * @param WP_Post $post       Post as stored now.
	 * @param array   $change     Change.
	 * @param array   $ids_before Element ids before the write.
	 * @return true|WP_Error
	 */
	protected function readback( $post, array $change, array $ids_before ) {
		if ( $this->target_id <= 0 || (int) $post->ID === $this->target_id ) {
			return parent::readback( $post, $change, $ids_before );
		}
		wp_cache_delete( $this->target_id, 'posts' );
		wp_cache_delete( $this->target_id, 'post_meta' );
		$target = get_post( $this->target_id );
		$res    = null === $target ? new WP_Error( 'ab_mcp_builder_readback', __( 'Elementor\'s draft of this page was gone after the save.', 'alphabridge-mcp' ) ) : parent::readback( $target, $change, $ids_before );
		if ( is_wp_error( $res ) ) {
			// The live page is unchanged; the draft must not keep a change
			// that did not come out as asked.
			$msg = $res->get_error_message();
			if ( $this->created_autosave > 0 && function_exists( 'wp_delete_post_revision' ) ) {
				wp_delete_post_revision( $this->created_autosave );
				$msg .= ' ' . __( 'The draft this change created was deleted again.', 'alphabridge-mcp' );
			} elseif ( null !== $target && null !== $this->draft_prior && self::put_back_draft( $this->target_id, $this->draft_prior ) ) {
				$msg .= ' ' . __( 'Elementor\'s draft of this page was put back as it was.', 'alphabridge-mcp' );
			} elseif ( null !== $target ) {
				/* translators: %d: revision id */
				$msg .= ' ' . sprintf( __( 'The change is in Elementor\'s draft of your account (revision #%d); open the page in the Elementor editor to check or drop it.', 'alphabridge-mcp' ), $this->target_id );
			}
			return new WP_Error( $res->get_error_code(), $msg );
		}
		return $res;
	}

	/**
	 * V3: change the tree in memory, check it, save it with the document.
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $change Change.
	 * @param array   $opts   Options.
	 * @return true|WP_Error
	 */
	private function write_v3( $post, array $change, array $opts ) {
		$docs = self::documents();
		$doc  = null === $docs ? null : $docs->get( (int) $post->ID, false );
		if ( ! is_object( $doc ) || ! method_exists( $doc, 'save' ) ) {
			return new WP_Error( 'ab_mcp_builder_elementor_no_document', __( 'Elementor has no document for this page, so it cannot save it. Open the page in the Elementor editor once.', 'alphabridge-mcp' ) );
		}
		$tree = self::tree( $post );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}
		$new = self::apply_v3( $tree, $change['changes'] );
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		// T2: before save(), never after.
		$guard = self::guard( $tree, $new );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		if ( $opts['publish'] ) {
			$saved = $doc->save( array( 'elements' => $new ) );
			if ( false === $saved ) {
				return self::not_saved();
			}
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			return true;
		}
		$before            = self::own_autosave_id( (int) $post->ID );
		$this->draft_prior = $before > 0 ? self::raw_data( $before ) : null;
		$class             = 'Elementor\\Core\\Utils\\Document\\Document_Mutator';
		$target = call_user_func( array( $class, 'instance' ) )->save_as_draft( $doc, $new, true );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		if ( ! is_object( $target ) || ! method_exists( $target, 'get_post' ) ) {
			return self::not_saved();
		}
		$this->note_target( (int) $post->ID, $target->get_post(), $before );
		return true;
	}

	/**
	 * V4: hand the change to Elementor's manage-elements ability, then — for
	 * a live page with publish=true — promote the staged autosave with
	 * publish-document.
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $change Change.
	 * @param array   $opts   Options.
	 * @return true|WP_Error
	 */
	private function write_v4( $post, array $change, array $opts ) {
		$tree = self::tree( $post );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}
		$guard = self::guard( $tree, $tree );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$settings = array();
		$eid      = '';
		foreach ( $change['changes'] as $c ) {
			$eid = (string) $c['data']['eid'];
			$settings[ (string) $c['data']['key'] ] = $c['data']['plain'];
		}
		$id     = (int) $post->ID;
		$live   = in_array( (string) get_post_status( $id ), self::LIVE_STATUSES, true );
		$before = self::own_autosave_id( $id );
		if ( $live && ! $opts['publish'] && $before > 0 ) {
			$this->draft_prior = self::raw_data( $before );
		}
		$res = self::run_ability(
			self::ABILITY_ELEMENTS,
			array(
				'post_id'    => $id,
				'operations' => array(
					array(
						'action'     => 'update',
						'element_id' => $eid,
						'settings'   => $settings,
					),
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! $live ) {
			return true; // A page that is not live is written in place (document-mutator.php:264-266).
		}
		if ( ! $opts['publish'] ) {
			$autosave = function_exists( 'wp_get_post_autosave' ) ? wp_get_post_autosave( $id, get_current_user_id() ) : false;
			if ( ! is_object( $autosave ) ) {
				return new WP_Error( 'ab_mcp_builder_elementor_no_draft', __( 'Elementor reported the change as saved, but its draft of this page cannot be found.', 'alphabridge-mcp' ) );
			}
			$this->note_target( $id, $autosave, $before );
			return true;
		}
		$pub = self::run_ability( self::ABILITY_PUBLISH, array( 'post_id' => $id ) );
		if ( is_wp_error( $pub ) ) {
			return new WP_Error(
				'ab_mcp_builder_elementor_not_published',
				$pub->get_error_message() . ' ' . __( 'Elementor holds the change in its draft of this page (autosave); the live page is unchanged. Publish it in the Elementor editor, or drop it there.', 'alphabridge-mcp' )
			);
		}
		// Before returning, so that the undo point records the state with
		// the new copy (mark_written runs right after write()).
		$this->sync_plain_text( $id );
		return true;
	}

	/**
	 * Bring the text copy in post_content in step with the published tree.
	 * publish-document promotes the autosave with
	 * Revisions_Manager::restore_revision() (modules/history/revisions-
	 * manager.php:219-240), which copies the _elementor meta and rebuilds
	 * the CSS but leaves post_content as it was; only Document::save()
	 * writes the text copy (core/base/document.php:1401). Without this the
	 * site search, excerpts and feeds keep showing the old text. Called the
	 * way Document::save() calls it; a failure leaves the published change
	 * in place and says what is stale.
	 *
	 * @param int $post_id Page.
	 */
	private function sync_plain_text( $post_id ) {
		if ( ! self::save_plain_text( $post_id ) ) {
			$this->warnings[] = __( 'Elementor published the change, but the text copy of the page in post_content could not be brought in step: the site search, excerpts and feeds show the old text until the page is saved once in the Elementor editor.', 'alphabridge-mcp' );
		}
	}

	/**
	 * Write the text copy in post_content from the page's Elementor data
	 * with Elementor's own save_plain_text() (includes/db.php:225-242). Also
	 * called by wp_run_ability after it ran publish-document.
	 *
	 * @param int $post_id Page.
	 * @return bool Whether Elementor wrote it.
	 */
	public static function save_plain_text( $post_id ) {
		$plugin = self::plugin();
		$db     = null !== $plugin && isset( $plugin->db ) && is_object( $plugin->db ) ? $plugin->db : null;
		if ( null === $db || ! method_exists( $db, 'save_plain_text' ) ) {
			return false;
		}
		try {
			$db->save_plain_text( (int) $post_id );
			return true;
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}
	}

	/* ------------------------------------------------------- page checks */

	/**
	 * Checks of the whole page before a change is previewed or written: T2,
	 * kses, Elementor's own record of unsaved editor work, and a pending
	 * autosave where the change would mix with it.
	 *
	 * @param WP_Post $post    Post.
	 * @param array   $tree    Stored tree.
	 * @param bool    $publish Live write.
	 * @param bool    $atomic  The change goes through Elementor's ability.
	 * @return array|WP_Error { warnings }
	 */
	private function page_checks( $post, array $tree, $publish, $atomic ) {
		$guard = self::guard( $tree, $tree );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$warnings = array();
		$id       = (int) $post->ID;
		$class    = 'Elementor\\Modules\\Mcp\\Utils\\Editor_Sync_State';
		if ( class_exists( $class ) && method_exists( $class, 'has_editor_unsaved' ) ) {
			try {
				if ( call_user_func( array( $class, 'has_editor_unsaved' ), $id ) ) {
					$warnings[] = __( 'The Elementor editor reports unsaved changes on this page. Whoever has it open should reload it before saving, or their save overwrites this change.', 'alphabridge-mcp' );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		$auto = self::own_autosave_id( $id );
		if ( $auto > 0 && self::raw_data( $auto ) !== self::raw_data( $id ) ) {
			// Elementor's draft path and its ability both work on this autosave
			// (documents-manager.php:255-262); the ids were read from the page.
			if ( ! $publish || $atomic ) {
				return new WP_Error(
					'ab_mcp_builder_elementor_autosave',
					sprintf(
						/* translators: %d: revision id */
						__( 'Your account has unpublished changes of this page in Elementor (autosave revision #%d). This change would be mixed into them, or published together with them. Publish or discard them in the Elementor editor first.', 'alphabridge-mcp' ),
						$auto
					) . ( $atomic ? '' : ' ' . __( 'Leaving publish out changes the live page and leaves that draft as it is.', 'alphabridge-mcp' ) ),
					array( 'autosave' => $auto )
				);
			}
			/* translators: %d: revision id */
			$warnings[] = sprintf( __( 'Your account has unpublished changes of this page in Elementor (autosave revision #%d) that do not contain this change.', 'alphabridge-mcp' ), $auto );
		}
		return array( 'warnings' => $warnings );
	}

	/**
	 * T2 and kses on a tree about to be saved ($new) against the stored one.
	 * T2 is one comparison: the elements Elementor keeps when it saves $new
	 * (registered ones, and the children of registered ones) must be as many
	 * as the page stores. An unregistered element and anything it holds would
	 * be lost; the answer names the types.
	 *
	 * @param array $tree Stored tree.
	 * @param array $new  Tree for save().
	 * @return true|WP_Error
	 */
	private static function guard( array $tree, array $new ) {
		$missing = array();
		$kept    = self::kept( $new, $missing );
		$stored  = self::count_nodes( $tree );
		if ( $kept !== $stored ) {
			$types = array_values( array_unique( $missing ) );
			return new WP_Error(
				'ab_mcp_builder_elementor_unregistered',
				sprintf(
					/* translators: 1: elements stored, 2: elements Elementor would keep, 3: element types */
					__( 'Elementor would keep %2$d of the %1$d elements of this page when saving it: it does not have every element type registered on this site (%3$s), for example because Elementor Pro or an add-on is inactive, and its save deletes such elements. Nothing was written. Activate the plugin that provides them, or remove them in the Elementor editor.', 'alphabridge-mcp' ),
					$stored,
					$kept,
					array() !== $types ? implode( ', ', array_slice( $types, 0, 10 ) ) : '-'
				),
				array( 'types' => $types )
			);
		}
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$filtered = array();
			self::kses_changes( $new, $filtered );
			if ( array() !== $filtered ) {
				return new WP_Error(
					'ab_mcp_builder_elementor_kses',
					sprintf(
						/* translators: %s: element ids */
						__( 'Elementor filters the whole page for an account without the unfiltered_html capability, and on this page that would remove markup someone else stored (scripts, frames or similar in %s). Nothing was written. Ask someone with that capability (an administrator; on a multisite network a super admin) to make the change.', 'alphabridge-mcp' ),
						implode( ', ', array_slice( array_values( array_unique( $filtered ) ), 0, 10 ) )
					)
				);
			}
		}
		return true;
	}

	/**
	 * How many elements Elementor keeps when it saves this tree: those whose
	 * type it has registered, and their children by the same rule
	 * (core/base/document.php:1074-1114 get_elements_raw_data(), which skips
	 * an element create_element_instance() returns null for). The types it
	 * would drop go to $missing.
	 *
	 * @param array    $elements Elements.
	 * @param string[] $missing  Types (by reference).
	 * @param int      $depth    Depth.
	 * @return int
	 */
	private static function kept( array $elements, array &$missing, $depth = 0 ) {
		$manager = self::manager();
		$n       = 0;
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) || ! isset( $el['elType'] ) || ! is_string( $el['elType'] ) || '' === $el['elType'] ) {
				$missing[] = 'unknown';
				continue;
			}
			$widget = 'widget' === $el['elType'] ? ( isset( $el['widgetType'] ) && is_string( $el['widgetType'] ) ? $el['widgetType'] : '' ) : null;
			$known  = null;
			if ( null !== $manager && '' !== $widget ) {
				try {
					$known = $manager->get_element( $el['elType'], $widget );
				} catch ( \Throwable $e ) {
					$known = null;
				}
			}
			if ( ! is_object( $known ) ) {
				$missing[] = null !== $widget ? ( '' !== $widget ? $widget : 'widget' ) : $el['elType'];
				continue;
			}
			++$n;
			if ( $depth < self::MAX_DEPTH && ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$n += self::kept( $el['elements'], $missing, $depth + 1 );
			}
		}
		return $n;
	}

	/**
	 * Elements of a tree, at any depth.
	 *
	 * @param array $elements Elements.
	 * @param int   $depth    Depth.
	 * @return int
	 */
	private static function count_nodes( array $elements, $depth = 0 ) {
		$n = 0;
		foreach ( $elements as $el ) {
			++$n;
			if ( $depth < self::MAX_DEPTH && is_array( $el ) && ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$n += self::count_nodes( $el['elements'], $depth + 1 );
			}
		}
		return $n;
	}

	/**
	 * Elements with a string kses would change in substance (the filter
	 * Document::save() applies for an account without unfiltered_html).
	 * Only strings with markup can change in substance: kses rewrites a bare
	 * "&" as "&amp;", which shows the same.
	 *
	 * @param array    $elements Elements.
	 * @param string[] $out      Element ids (by reference).
	 * @param int      $depth    Depth.
	 */
	private static function kses_changes( array $elements, array &$out, $depth = 0 ) {
		foreach ( $elements as $i => $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$settings = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
			if ( self::filtered( $settings ) ) {
				$out[] = isset( $el['id'] ) && is_scalar( $el['id'] ) ? (string) $el['id'] : '#' . $i;
			}
			if ( $depth < self::MAX_DEPTH && ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::kses_changes( $el['elements'], $out, $depth + 1 );
			}
		}
	}

	/**
	 * Whether kses would change a value in substance.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Depth.
	 * @return bool
	 */
	private static function filtered( $value, $depth = 0 ) {
		if ( is_string( $value ) ) {
			if ( false === strpos( $value, '<' ) && false === strpos( $value, "\0" ) ) {
				return false;
			}
			$clean = wp_kses_post( $value );
			return $clean !== $value && self::substance( $clean ) !== self::substance( $value );
		}
		if ( is_array( $value ) && $depth < 64 ) {
			foreach ( $value as $v ) {
				if ( self::filtered( $v, $depth + 1 ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Markup without what kses normalises (entities, quotes, white space,
	 * slashes, case) — the comparison AB_MCP_Builder_Fields makes for a
	 * single field.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function substance( $html ) {
		return strtolower( (string) preg_replace( '~[\s"\'/]+~', '', html_entity_decode( (string) $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/* ------------------------------------------------------------------ V3 */

	/**
	 * Whether this V3 element may be written: Elementor's own widgets and
	 * Elementor Pro's; an add-on's only through the filter (B1).
	 *
	 * @param array $node Element.
	 * @return true|WP_Error
	 */
	private function v3_writable( array $node ) {
		if ( ! isset( $node['elType'] ) || 'widget' !== $node['elType'] ) {
			return true; // Sections, columns and containers are Elementor's own.
		}
		$type    = isset( $node['widgetType'] ) && is_string( $node['widgetType'] ) ? $node['widgetType'] : '';
		$manager = self::manager();
		$widget  = null;
		if ( null !== $manager && '' !== $type ) {
			try {
				$widget = $manager->get_element( 'widget', $type );
			} catch ( \Throwable $e ) {
				$widget = null;
			}
		}
		$own = is_object( $widget ) && self::from_elementor( $widget );
		/**
		 * Whether AlphaBridge may write the fields of an Elementor widget.
		 * Elementor's and Elementor Pro's widgets are open; a third-party
		 * add-on's are read only until measured, because an add-on may
		 * render a text control raw (an attribute, an embed). Open a widget
		 * type here once you have checked how it renders its text controls.
		 *
		 * @param bool        $writable Whether the widget may be written.
		 * @param string      $type     Widget type.
		 * @param object|null $widget   Elementor's widget instance.
		 */
		$own = (bool) apply_filters( 'ab_mcp_pro_elementor_writable_widget', $own, $type, $widget );
		if ( $own ) {
			return true;
		}
		return new WP_Error(
			'ab_mcp_builder_elementor_addon',
			sprintf(
				/* translators: %s: widget type */
				__( 'The widget %s comes from an add-on, not from Elementor or Elementor Pro. AlphaBridge reads its fields but does not change them until that add-on has been measured, because an add-on may print a text field unfiltered into the page. Change it in the Elementor editor; a developer can open the widget with the filter ab_mcp_pro_elementor_writable_widget.', 'alphabridge-mcp' ),
				$type
			)
		);
	}

	/**
	 * Whether an object's class is defined in Elementor's or Elementor Pro's
	 * plugin folder.
	 *
	 * @param object $obj Object.
	 * @return bool
	 */
	private static function from_elementor( $obj ) {
		try {
			$file = ( new ReflectionClass( $obj ) )->getFileName();
		} catch ( \Throwable $e ) {
			return false;
		}
		if ( ! is_string( $file ) || '' === $file ) {
			return false;
		}
		$file = wp_normalize_path( $file );
		foreach ( array( 'ELEMENTOR_PATH', 'ELEMENTOR_PRO_PATH' ) as $constant ) {
			if ( defined( $constant ) && is_string( constant( $constant ) ) && '' !== constant( $constant ) ) {
				$dir = trailingslashit( wp_normalize_path( constant( $constant ) ) );
				if ( 0 === strpos( $file, $dir ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Where an outline field of a V3 element is stored.
	 *
	 * @param array  $node Element.
	 * @param string $name Field name of the outline.
	 * @return array|null { key, item, sub, stored, stored_list, raw }
	 */
	private static function v3_ref( array $node, $name ) {
		$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
		if ( 1 === preg_match( self::REPEATER_FIELD, $name, $m ) ) {
			$list = isset( $settings[ $m[1] ] ) && is_array( $settings[ $m[1] ] ) ? $settings[ $m[1] ] : null;
			if ( null === $list ) {
				return array(
					'key'         => $m[1],
					'item'        => (int) $m[2],
					'sub'         => $m[3],
					'stored'      => false,
					'stored_list' => false,
					'raw'         => null,
				);
			}
			$keys = array_keys( $list );
			$k    = (int) $m[2];
			if ( ! isset( $keys[ $k ] ) || ! is_array( $list[ $keys[ $k ] ] ) ) {
				return null;
			}
			$item = $list[ $keys[ $k ] ];
			return array(
				'key'         => $m[1],
				'item'        => $keys[ $k ],
				'sub'         => $m[3],
				'stored'      => array_key_exists( $m[3], $item ),
				'stored_list' => true,
				'raw'         => array_key_exists( $m[3], $item ) ? $item[ $m[3] ] : null,
			);
		}
		return array(
			'key'         => $name,
			'item'        => null,
			'sub'         => null,
			'stored'      => array_key_exists( $name, $settings ),
			'stored_list' => true,
			'raw'         => array_key_exists( $name, $settings ) ? $settings[ $name ] : null,
		);
	}

	/**
	 * A stored value as the text a change compares and replaces in.
	 *
	 * @param string $kind Kind.
	 * @param mixed  $raw  Stored value (V3), or the inner value of a V4 prop.
	 * @return string|int
	 */
	private static function raw_text( $kind, $raw ) {
		if ( 'url' === $kind ) {
			return is_array( $raw ) && isset( $raw['url'] ) && is_scalar( $raw['url'] ) ? trim( (string) $raw['url'] ) : ( is_string( $raw ) ? trim( $raw ) : '' );
		}
		if ( 'image' === $kind ) {
			if ( is_array( $raw ) && isset( $raw['id'] ) && is_numeric( $raw['id'] ) && (int) $raw['id'] > 0 ) {
				return (int) $raw['id'];
			}
			return is_array( $raw ) && isset( $raw['url'] ) && is_string( $raw['url'] ) ? $raw['url'] : '';
		}
		return is_scalar( $raw ) && ! is_bool( $raw ) ? (string) $raw : '';
	}

	/**
	 * The tree with every V3 change in place.
	 *
	 * @param array $tree    Stored tree.
	 * @param array $changes Changes with data { path, key, item, sub }.
	 * @return array|WP_Error
	 */
	private static function apply_v3( array $tree, array $changes ) {
		foreach ( $changes as $c ) {
			$d    = $c['data'];
			$node = self::node_at( $tree, $d['path'] );
			if ( null === $node ) {
				return new WP_Error( 'ab_mcp_builder_no_element', __( 'The element moved while the change was being written; nothing was written. Read the page again with wp_get_builder_layout.', 'alphabridge-mcp' ) );
			}
			$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
			if ( null === $d['item'] ) {
				$settings[ $d['key'] ] = self::v3_store( $c, array_key_exists( $d['key'], $settings ) ? $settings[ $d['key'] ] : null );
			} else {
				if ( ! isset( $settings[ $d['key'] ][ $d['item'] ] ) || ! is_array( $settings[ $d['key'] ][ $d['item'] ] ) ) {
					return new WP_Error( 'ab_mcp_builder_no_field', __( 'The list item moved while the change was being written; nothing was written. Read the page again with wp_get_builder_layout.', 'alphabridge-mcp' ) );
				}
				$item                                 = $settings[ $d['key'] ][ $d['item'] ];
				$item[ $d['sub'] ]                    = self::v3_store( $c, array_key_exists( $d['sub'], $item ) ? $item[ $d['sub'] ] : null );
				$settings[ $d['key'] ][ $d['item'] ] = $item;
			}
			$node['settings'] = $settings;
			$tree             = self::with_node( $tree, $d['path'], $node );
		}
		return $tree;
	}

	/**
	 * A new value in the shape Elementor stores the control in: text as a
	 * string, a link as the URL control's array (url, is_external, nofollow,
	 * custom_attributes, includes/controls/url.php), an image as the media
	 * control's array (url, id; the alt Elementor shows comes from the
	 * attachment by id).
	 *
	 * @param array $c   Change.
	 * @param mixed $old Stored value.
	 * @return mixed
	 */
	private static function v3_store( array $c, $old ) {
		switch ( $c['kind'] ) {
			case 'url':
				$value        = is_array( $old ) ? $old : array(
					'url'               => '',
					'is_external'       => '',
					'nofollow'          => '',
					'custom_attributes' => '',
				);
				$value['url'] = (string) $c['after'];
				return $value;
			case 'image':
				$value       = is_array( $old ) ? $old : array( 'size' => '' );
				$value['id'] = (int) $c['after'];
				$value['url'] = isset( $c['url'] ) ? (string) $c['url'] : (string) wp_get_attachment_url( (int) $c['after'] );
				if ( array_key_exists( 'alt', $value ) ) {
					$value['alt'] = (string) get_post_meta( (int) $c['after'], '_wp_attachment_image_alt', true );
				}
				if ( array_key_exists( 'source', $value ) ) {
					$value['source'] = 'library';
				}
				return $value;
			default:
				return (string) $c['after'];
		}
	}

	/* ------------------------------------------------------------------ V4 */

	/**
	 * Whether atomic elements can be changed on this page now: Elementor's
	 * ability, the Atomic Editor, an id Elementor can address, and a way to
	 * the live page for publish=true.
	 *
	 * @param WP_Post $post    Post.
	 * @param array   $tree    Stored tree.
	 * @param array   $node    Element.
	 * @param bool    $publish Live write.
	 * @return true|WP_Error
	 */
	private function v4_gate( $post, array $tree, array $node, $publish ) {
		if ( ! function_exists( 'wp_get_ability' ) || null === wp_get_ability( self::ABILITY_ELEMENTS ) ) {
			return new WP_Error(
				'ab_mcp_builder_elementor_v4_unavailable',
				__( 'This is an atomic (V4) Elementor element. AlphaBridge changes those only through Elementor\'s own ability elementor/manage-elements, which this site does not offer (it needs Elementor 4.3 or newer on WordPress 6.9 or newer). The element is read only here; change it in the Elementor editor.', 'alphabridge-mcp' )
			);
		}
		$off = self::switched_off( self::ABILITY_ELEMENTS );
		if ( '' !== $off ) {
			return new WP_Error(
				'ab_mcp_ability_not_allowed',
				__( 'This is an atomic (V4) Elementor element. AlphaBridge changes those only through Elementor\'s own ability elementor/manage-elements; there is no other way for it, so the element is read only here.', 'alphabridge-mcp' ) . ' ' . $off
			);
		}
		$gate = 'Elementor\\Modules\\Mcp\\Utils\\Mcp_V4_Gate';
		if ( class_exists( $gate ) && method_exists( $gate, 'is_available' ) ) {
			$open = call_user_func( array( $gate, 'is_available' ), self::ABILITY_ELEMENTS );
			if ( is_wp_error( $open ) ) {
				return new WP_Error(
					'ab_mcp_builder_elementor_v4_unavailable',
					__( 'This is an atomic (V4) Elementor element, and Elementor changes those only while its Atomic Editor is on. It is off on this site, so the element is read only here. An administrator turns it on under Elementor › Settings (Atomic Editor); or change the element in the Elementor editor.', 'alphabridge-mcp' )
				);
			}
		}
		$eid = isset( $node['id'] ) && is_scalar( $node['id'] ) && ! is_bool( $node['id'] ) ? (string) $node['id'] : '';
		if ( '' === $eid || 1 !== self::count_id( $tree, $eid ) ) {
			return new WP_Error(
				'ab_mcp_builder_elementor_no_id',
				__( 'This atomic element has no id of its own that is unique on the page, so Elementor cannot be told which element to change. Change it in the Elementor editor (saving there gives every element its own id).', 'alphabridge-mcp' )
			);
		}
		$status = (string) get_post_status( (int) $post->ID );
		if ( $publish && 'private' === $status ) {
			return new WP_Error(
				'ab_mcp_builder_elementor_private',
				__( 'Elementor publishes a staged change of an atomic element by making the page public, and this page is private. Pass publish=false: the change goes into Elementor\'s draft of the page, and you publish it in the Elementor editor without changing who may see the page.', 'alphabridge-mcp' )
			);
		}
		if ( $publish && 'publish' === $status ) {
			if ( null === wp_get_ability( self::ABILITY_PUBLISH ) ) {
				return new WP_Error(
					'ab_mcp_builder_elementor_v4_unavailable',
					__( 'Elementor stages changes of atomic elements on a live page as a draft and publishes them with its ability elementor/publish-document, which this site does not offer. Pass publish=false to save the change as that draft, then publish it in the Elementor editor.', 'alphabridge-mcp' )
				);
			}
			$off = self::switched_off( self::ABILITY_PUBLISH );
			if ( '' !== $off ) {
				return new WP_Error(
					'ab_mcp_ability_not_allowed',
					__( 'Elementor stages changes of atomic elements on a live page as a draft and publishes them with its ability elementor/publish-document. Pass publish=false to save the change as that draft and publish it in the Elementor editor; publishing it from here needs that ability.', 'alphabridge-mcp' ) . ' ' . $off
				);
			}
			if ( ! current_user_can( 'publish_post', (int) $post->ID ) ) {
				return AB_MCP_Guidance::role_refusal( __( 'Elementor publishes a change of an atomic element on a live page only for an account that may publish this page, and yours may not. Pass publish=false to save it as a draft for someone who may publish it.', 'alphabridge-mcp' ) );
			}
		}
		return true;
	}

	/**
	 * Where an outline field of a V4 element is stored.
	 *
	 * @param array  $node Element.
	 * @param string $name Field name (a settings key).
	 * @return array|null { key, item, sub, stored, stored_list, raw, prop }
	 */
	private static function v4_ref( array $node, $name ) {
		$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
		if ( ! isset( $settings[ $name ] ) || ! is_array( $settings[ $name ] ) || ! isset( $settings[ $name ]['$$type'] ) ) {
			return null;
		}
		$prop  = $settings[ $name ];
		$inner = isset( $prop['value'] ) ? $prop['value'] : null;
		if ( 'overridable' === $prop['$$type'] ) {
			$inner = null;
		} elseif ( 'link' === $prop['$$type'] ) {
			// raw_text() reads a link from 'url', as for V3.
			$dest  = is_array( $inner ) && isset( $inner['destination'] ) ? self::unwrap( $inner['destination'] ) : '';
			$inner = array( 'url' => is_string( $dest ) ? $dest : '' );
		}
		return array(
			'key'         => $name,
			'item'        => null,
			'sub'         => null,
			'stored'      => true,
			'stored_list' => true,
			'raw'         => $inner,
			'prop'        => $prop,
		);
	}

	/**
	 * The value handed to manage-elements for one change: a plain value
	 * Elementor resolves against the prop's schema (Plain_Values_Resolver,
	 * modules/atomic-widgets/plain-resolvers/plain-values-resolver.php).
	 * Text is a string; a link is the link object with the new destination
	 * and what the element had besides (link-prop-type.php); an image is the
	 * attachment id with the size it had (image-prop-type.php,
	 * image-src-prop-type.php: an id or a URL, never both).
	 *
	 * @param array $c   Change.
	 * @param array $ref v4_ref().
	 * @return mixed|WP_Error
	 */
	private static function v4_plain( array $c, array $ref ) {
		$prop = $ref['prop'];
		if ( 'overridable' === $prop['$$type'] ) {
			return new WP_Error(
				'ab_mcp_builder_elementor_component',
				sprintf(
					/* translators: %s: field name */
					__( 'The field %s is an override of a component (a part shared by many pages). Change it in the Elementor editor.', 'alphabridge-mcp' ),
					$c['field']
				)
			);
		}
		$inner = isset( $prop['value'] ) && is_array( $prop['value'] ) ? $prop['value'] : array();
		switch ( $c['kind'] ) {
			case 'url':
				if ( '' === (string) $c['after'] ) {
					return null; // Elementor clears a prop set to null.
				}
				$out = array( 'destination' => (string) $c['after'] );
				foreach ( array( 'isTargetBlank', 'tag' ) as $key ) {
					if ( isset( $inner[ $key ] ) ) {
						$out[ $key ] = self::unwrap( $inner[ $key ] );
					}
				}
				return $out;
			case 'image':
				$size = isset( $inner['size'] ) ? self::unwrap( $inner['size'] ) : null;
				return array(
					'src'  => array( 'id' => (int) $c['after'] ),
					'size' => is_string( $size ) && '' !== $size ? $size : 'full',
				);
			default:
				return (string) $c['after'];
		}
	}

	/**
	 * The plain value of a {"$$type", "value"} prop, or the value itself.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function unwrap( $value ) {
		return is_array( $value ) && isset( $value['$$type'] ) && array_key_exists( 'value', $value ) ? $value['value'] : $value;
	}

	/**
	 * Whether an element is atomic (V4): its type starts with "e-", or its
	 * settings carry $$type (the free reader's test).
	 *
	 * @param array $node Element.
	 * @return bool
	 */
	private static function is_atomic( array $node ) {
		$type = isset( $node['elType'] ) && 'widget' === $node['elType'] ? ( isset( $node['widgetType'] ) ? (string) $node['widgetType'] : '' ) : ( isset( $node['elType'] ) ? (string) $node['elType'] : '' );
		if ( 0 === strpos( $type, 'e-' ) ) {
			return true;
		}
		foreach ( isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array() as $value ) {
			if ( is_array( $value ) && isset( $value['$$type'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Run one of Elementor's abilities and turn its answer into true or an
	 * error that carries Elementor's own message.
	 *
	 * @param string $name  Ability.
	 * @param array  $input Input.
	 * @return true|WP_Error
	 */
	private static function run_ability( $name, array $input ) {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
		if ( null === $ability ) {
			/* translators: %s: ability name */
			return new WP_Error( 'ab_mcp_builder_elementor_v4_unavailable', sprintf( __( 'Elementor\'s ability %s is not registered on this site; it comes with Elementor 4.3 or newer on WordPress 6.9 or newer. Update both, or change the element in the Elementor editor.', 'alphabridge-mcp' ), $name ) );
		}
		$res = $ability->execute( $input );
		if ( is_wp_error( $res ) ) {
			/* translators: 1: ability name, 2: Elementor's message */
			return new WP_Error( 'ab_mcp_builder_elementor_refused', sprintf( __( 'Elementor (%1$s) did not make the change: %2$s', 'alphabridge-mcp' ), $name, $res->get_error_message() ) );
		}
		if ( ! is_array( $res ) ) {
			/* translators: %s: ability name */
			return new WP_Error( 'ab_mcp_builder_elementor_refused', sprintf( __( 'Elementor (%s) gave no answer. Try again; if it keeps failing, change the element in the Elementor editor.', 'alphabridge-mcp' ), $name ) );
		}
		$errors = array();
		if ( isset( $res['save_error'] ) && is_string( $res['save_error'] ) && '' !== $res['save_error'] ) {
			$errors[] = $res['save_error'];
		}
		foreach ( isset( $res['results'] ) && is_array( $res['results'] ) ? $res['results'] : array() as $r ) {
			if ( is_array( $r ) && isset( $r['status'] ) && 'error' === $r['status'] ) {
				$errors[] = isset( $r['message'] ) && is_string( $r['message'] ) ? $r['message'] : ( isset( $r['code'] ) ? (string) $r['code'] : 'error' );
			}
		}
		if ( array() !== $errors || ( isset( $res['status'] ) && 'error' === $res['status'] ) ) {
			return new WP_Error(
				'ab_mcp_builder_elementor_refused',
				/* translators: 1: ability name, 2: Elementor's messages */
				sprintf( __( 'Elementor (%1$s) did not make the change: %2$s', 'alphabridge-mcp' ), $name, array() !== $errors ? implode( ' ', $errors ) : 'error' )
			);
		}
		return true;
	}

	/**
	 * Whether an administrator switched one of Elementor's abilities off for
	 * AlphaBridge (Fine-tuning, a namespace rule, or the filter
	 * ab_mcp_pro_ability_allowed): the way to switch it on, '' when it may
	 * run (found in the review of 03.10.2026: before, the writer ran them
	 * while they were switched off). Only Pro's switches count here: the
	 * approvals of this plugin (4.12.0) are for wp_run_ability, the general
	 * runner; this writer uses Elementor's abilities inside its own checked
	 * change with an undo point, as before.
	 *
	 * @param string $name Ability.
	 * @return string
	 */
	private static function switched_off( $name ) {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
		if ( null === $ability || ! class_exists( 'AB_MCP_Pro_Ability_Broker', false ) ) {
			return '';
		}
		$decision = AB_MCP_Pro_Ability_Broker::decision( $ability );
		return empty( $decision['allowed'] ) ? (string) $decision['how'] : '';
	}

	/* -------------------------------------------------------------- draft */

	/**
	 * Notes on where a change goes, for the preview and the answer.
	 *
	 * @param WP_Post $post    Post.
	 * @param bool    $publish Live write.
	 * @return string[]
	 */
	private static function draft_notes( $post, $publish ) {
		if ( $publish ) {
			return array();
		}
		if ( in_array( (string) get_post_status( (int) $post->ID ), self::LIVE_STATUSES, true ) ) {
			return array( __( 'publish=false: the change goes into Elementor\'s draft of this page for your account (an autosave revision). The live page keeps its version until the page is published in the Elementor editor; wp_get_builder_layout keeps showing the live version.', 'alphabridge-mcp' ) );
		}
		return array( __( 'publish=false: this page is not published, so Elementor saves the change into the page itself.', 'alphabridge-mcp' ) );
	}

	/**
	 * After a draft write that left the page itself as it was: its undo
	 * point has nothing to undo (wp_undo restores the page, not Elementor's
	 * draft), so it is dropped, and the answer says how the draft goes away.
	 *
	 * @param WP_Post        $post        Post.
	 * @param array|WP_Error $res         execute()'s answer.
	 * @param string         $hash_before layout_hash before.
	 * @param array          $opts        Options.
	 * @return array|WP_Error
	 */
	private function after_draft( $post, $res, $hash_before, array $opts ) {
		if ( ! is_array( $res ) || empty( $res['applied'] ) || ! empty( $res['published'] ) ) {
			return $res;
		}
		if ( $this->target_id <= 0 || $this->target_id === (int) $post->ID || ! isset( $res['layout_hash'] ) || $res['layout_hash'] !== $hash_before ) {
			return $res;
		}
		if ( ! empty( $res['undo_token'] ) && ( ! isset( $opts['undo_token'] ) || '' === (string) $opts['undo_token'] ) ) {
			AB_MCP_Undo::discard( (string) $res['undo_token'] );
			$res['undo_token'] = null;
		}
		/* translators: %d: revision id */
		$res['notes'][] = sprintf( __( 'The change is in Elementor\'s draft (revision #%d), not on the live page, so there is no undo point: wp_undo restores pages, not drafts. Publish the draft in the Elementor editor, or drop it there.', 'alphabridge-mcp' ), $this->target_id );
		$res['draft'] = $this->target_id;
		return $res;
	}

	/**
	 * Remember where a draft write went and whether it created the autosave.
	 *
	 * @param int          $post_id Page.
	 * @param WP_Post|null $target  Post written.
	 * @param int          $before  The account's autosave before the write (0: none).
	 */
	private function note_target( $post_id, $target, $before ) {
		$this->target_id = is_object( $target ) && isset( $target->ID ) ? (int) $target->ID : (int) $post_id;
		if ( $this->target_id !== (int) $post_id && $this->target_id !== (int) $before ) {
			$this->created_autosave = $this->target_id;
		}
	}

	/**
	 * The current account's autosave of a page (wp_get_post_autosave()), 0 when none.
	 *
	 * @param int $post_id Page.
	 * @return int
	 */
	private static function own_autosave_id( $post_id ) {
		if ( ! function_exists( 'wp_get_post_autosave' ) ) {
			return 0;
		}
		$auto = wp_get_post_autosave( (int) $post_id, get_current_user_id() );
		return is_object( $auto ) && isset( $auto->ID ) ? (int) $auto->ID : 0;
	}

	/**
	 * _elementor_data of a post as stored (first row).
	 *
	 * @param int $post_id Post.
	 * @return string|null
	 */
	private static function raw_data( $post_id ) {
		$rows = AB_MCP_Undo_Builder::meta_rows( (int) $post_id, self::DATA_KEY );
		return array() === $rows ? null : $rows[0]['value'];
	}

	/**
	 * Put the tree of an autosave revision back. update_metadata() and not
	 * update_post_meta(), which writes a revision's meta to its parent — the
	 * way Elementor writes revisions itself (document.php:1386-1387).
	 *
	 * @param int    $revision_id Autosave revision.
	 * @param string $raw         _elementor_data as it was.
	 * @return bool Whether it holds that value again.
	 */
	private static function put_back_draft( $revision_id, $raw ) {
		update_metadata( 'post', (int) $revision_id, self::DATA_KEY, wp_slash( (string) $raw ) );
		wp_cache_delete( (int) $revision_id, 'post_meta' );
		return self::raw_data( (int) $revision_id ) === (string) $raw;
	}

	/* --------------------------------------------------------------- tree */

	/**
	 * The stored tree, as Elementor reads it.
	 *
	 * @param WP_Post $post Post.
	 * @return array|WP_Error
	 */
	private static function tree( $post ) {
		$tree = AB_MCP_Builder_Adapter_Elementor::elements_data( $post );
		if ( null === $tree ) {
			return new WP_Error( 'ab_mcp_builder_elementor_unreadable', __( '_elementor_data of this page holds no list of elements, so AlphaBridge does not change it. Open and save the page in the Elementor editor.', 'alphabridge-mcp' ) );
		}
		return $tree;
	}

	/**
	 * The position of an outline element in the tree: a path id ("e0.1")
	 * as it is, an Elementor id where exactly one element has it (the free
	 * reader uses an Elementor id only then).
	 *
	 * @param array  $tree Tree.
	 * @param string $id   Element id of the outline.
	 * @return int[]|null
	 */
	private static function locate( array $tree, $id ) {
		if ( 1 === preg_match( self::PATH, (string) $id, $m ) ) {
			$path = array_map( 'intval', explode( '.', $m[1] ) );
			return null === self::node_at( $tree, $path ) ? null : $path;
		}
		$found = array();
		self::find( $tree, (string) $id, array(), $found );
		return 1 === count( $found ) ? $found[0] : null;
	}

	/**
	 * Paths of every element with this Elementor id.
	 *
	 * @param array  $elements Elements.
	 * @param string $id       Id.
	 * @param int[]  $path     Path of the parent.
	 * @param array  $found    Paths (by reference).
	 */
	private static function find( array $elements, $id, array $path, array &$found ) {
		if ( count( $path ) > self::MAX_DEPTH ) {
			return;
		}
		foreach ( $elements as $i => $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$p = array_merge( $path, array( (int) $i ) );
			if ( isset( $el['id'] ) && is_scalar( $el['id'] ) && ! is_bool( $el['id'] ) && (string) $el['id'] === $id ) {
				$found[] = $p;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::find( $el['elements'], $id, $p, $found );
			}
		}
	}

	/**
	 * How many elements carry an Elementor id.
	 *
	 * @param array  $tree Tree.
	 * @param string $id   Id.
	 * @return int
	 */
	private static function count_id( array $tree, $id ) {
		$found = array();
		self::find( $tree, (string) $id, array(), $found );
		return count( $found );
	}

	/**
	 * The element at a path, or null.
	 *
	 * @param array $tree Tree.
	 * @param int[] $path Path.
	 * @return array|null
	 */
	private static function node_at( array $tree, array $path ) {
		$level = $tree;
		$node  = null;
		foreach ( $path as $i ) {
			if ( ! isset( $level[ $i ] ) || ! is_array( $level[ $i ] ) ) {
				return null;
			}
			$node  = $level[ $i ];
			$level = isset( $node['elements'] ) && is_array( $node['elements'] ) ? $node['elements'] : array();
		}
		return $node;
	}

	/**
	 * The tree with the element at a path replaced.
	 *
	 * @param array $tree Tree.
	 * @param int[] $path Path (exists).
	 * @param array $node Element.
	 * @return array
	 */
	private static function with_node( array $tree, array $path, array $node ) {
		$i = array_shift( $path );
		if ( array() === $path ) {
			$tree[ $i ] = $node;
			return $tree;
		}
		$tree[ $i ]['elements'] = self::with_node( $tree[ $i ]['elements'], $path, $node );
		return $tree;
	}

	/* ------------------------------------------------------------ Elementor */

	/**
	 * Elementor's documents manager, or null.
	 *
	 * @return object|null
	 */
	private static function documents() {
		$plugin = self::plugin();
		return null !== $plugin && isset( $plugin->documents ) && is_object( $plugin->documents ) && method_exists( $plugin->documents, 'get' ) ? $plugin->documents : null;
	}

	/**
	 * Elementor's elements manager, or null.
	 *
	 * @return object|null
	 */
	private static function manager() {
		$plugin = self::plugin();
		return null !== $plugin && isset( $plugin->elements_manager ) && is_object( $plugin->elements_manager ) && method_exists( $plugin->elements_manager, 'get_element' ) ? $plugin->elements_manager : null;
	}

	/**
	 * Elementor's plugin instance, or null.
	 *
	 * @return object|null
	 */
	private static function plugin() {
		if ( ! class_exists( 'Elementor\\Plugin', false ) ) {
			return null;
		}
		$plugin = \Elementor\Plugin::$instance;
		return is_object( $plugin ) ? $plugin : null;
	}

	/**
	 * The answer when Document::save() returned false.
	 *
	 * @return WP_Error
	 */
	private static function not_saved() {
		return new WP_Error(
			'ab_mcp_builder_elementor_not_saved',
			__( 'Elementor did not save the page: its save reported failure. That happens when your account may not edit this page with Elementor (Elementor › Role Manager, or a post type Elementor is not enabled for). Ask someone who edits it in Elementor.', 'alphabridge-mcp' )
		);
	}
}

AB_MCP_Builder_Writers::register_class( 'AB_MCP_Builder_Writer_Elementor' );
