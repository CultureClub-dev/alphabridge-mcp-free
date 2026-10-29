<?php
/**
 * Writer for the shortcode builders: WPBakery, Divi 4, Avada and Flatsome
 * keep a page as shortcodes in post_content (storage A), Enfold in the meta
 * field _aviaLayoutBuilderCleanData with a copy in post_content (storage B).
 * One class, one writer per reading adapter the free core registers for a
 * shortcode profile (AB_MCP_Builder_Adapter_Shortcodes), third-party profiles
 * included.
 *
 * How it writes — the free reader decides, this class follows it:
 * - The page is the reader's parse with byte offsets (adapter tree()). A
 *   change replaces exactly the bytes of one attribute value or one content
 *   range there, or adds an attribute the element does not have yet; every
 *   other byte of the page stays as it was.
 * - Values are written in the builder's encoding (AB_MCP_Pro_Shortcode_
 *   Encoder) and only when the free reader reads them back as the value
 *   meant; before anything is stored, the new text is parsed again and must
 *   have the same shortcodes in the same places.
 * - Only fields of the profile, on elements the profile describes: locked
 *   elements, elements the profile does not describe, other plugins'
 *   shortcodes, code attributes, encoded and dynamic values are never
 *   written. The shortcodes of plan §8 stay locked here even where a profile
 *   filter would describe them (LOCKED_TAGS).
 * - Storage A: the new post_content through wp_update_post() with
 *   wp_slash(), as an edit in the classic editor saves it. Enfold: the
 *   layout meta, then the same text into post_content — what Enfold's own
 *   builder does on every change (enfold.md a) [4] l. 1700–1701) — both in
 *   one undo point.
 * - For an account without unfiltered_html WordPress filters post_content
 *   when it is saved; where that would change more of the page than entities,
 *   nothing is written (the change must be the one asked for).
 *
 * Every profile is read from vendor documentation, not measured, so preview
 * and answer carry the warning «profile not yet measured»; writing is allowed
 * because the undo point and the read-back protect the page.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-shortcode-encoder.php';

/**
 * Class AB_MCP_Builder_Writer_Shortcodes
 */
class AB_MCP_Builder_Writer_Shortcodes extends AB_MCP_Builder_Writer {

	/**
	 * Shortcodes that are never written, whatever a profile says (plan §8):
	 * raw code, embeds and forms. A profile locks them already; this list
	 * keeps them locked if a profile filter describes them. Sites can add to
	 * it (filter ab_mcp_pro_shortcode_locked_tags), not take from it.
	 */
	const LOCKED_TAGS = array(
		// WPBakery (wpbakery.md g)): raw HTML and JavaScript, map embeds.
		'vc_raw_html',
		'vc_raw_js',
		'vc_gmaps',
		'vc_goo_maps',
		// Divi 4 (divi-4.md g)): code modules and forms.
		'et_pb_code',
		'et_pb_fullwidth_code',
		'et_pb_contact_form',
		'et_pb_contact_field',
		'et_pb_signup',
		'et_pb_login',
		// Avada (avada.md g)): the Code Block and forms.
		'fusion_code',
		'fusion_syntax_highlighter',
		'fusion_form',
		// Enfold (enfold.md g)): the code block and forms.
		'av_codeblock',
		'av_contact',
		'av_contact_field',
		'av_mailchimp',
		// Flatsome (flatsome.md g)): the HTML element.
		'ux_html',
		// The7 on WPBakery (salient-the7.md g)).
		'dt_code',
	);

	/**
	 * Image fields whose attachment id has an address copy in the same
	 * element, kept in step: Enfold stores src, attachment and
	 * attachment_size and shows the attachment (enfold.md c) [23]
	 * l. 911–948; for av_image derived there). Builder id => tag => id field => address field.
	 */
	const IMAGE_COPIES = array(
		'enfold' => array(
			'av_image' => array( 'image' => 'src' ),
		),
	);

	/** Elements whose content holds these are not rewritten whole: the outline does not show them. */
	const HIDDEN_IN_CONTENT = '~<!--|<(?:script|style|svg|template|noscript|iframe|object|embed|math|textarea|title|form|input|select|button)\b~i';

	/**
	 * Adapter id this writer writes.
	 *
	 * @var string
	 */
	private $adapter_id;

	/**
	 * One writer for one shortcode adapter.
	 *
	 * @param string $adapter_id Adapter id (wpbakery, divi4, avada, flatsome, enfold, or a third party's).
	 */
	public function __construct( $adapter_id ) {
		$this->adapter_id = (string) $adapter_id;
	}

	/**
	 * One writer per shortcode adapter the free core has registered.
	 *
	 * @return AB_MCP_Builder_Writer_Shortcodes[]
	 */
	public static function instances() {
		$out = array();
		if ( ! class_exists( 'AB_MCP_Builder_Adapter_Shortcodes' ) ) {
			return $out;
		}
		foreach ( AB_MCP_Builders::adapters() as $id => $adapter ) {
			if ( $adapter instanceof AB_MCP_Builder_Adapter_Shortcodes && method_exists( $adapter, 'tree' ) && method_exists( $adapter, 'profile' ) ) {
				$out[] = new self( (string) $id );
			}
		}
		return $out;
	}

	/**
	 * Adapter id.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->adapter_id;
	}

	/**
	 * Whether this page can be written: always where the free core reads it
	 * with a shortcode profile.
	 *
	 * @param WP_Post $post Post.
	 * @return true|WP_Error
	 */
	public function can_write( $post ) {
		if ( ! ( $this->adapter() instanceof AB_MCP_Builder_Adapter_Shortcodes ) ) {
			return new WP_Error( 'ab_mcp_builder_no_reader', __( 'The reader of this page builder is not loaded on this site. Change the page in its editor in wp-admin.', 'alphabridge-mcp' ) );
		}
		return true;
	}

	/**
	 * Enfold's layout meta goes into the undo point even where it does not
	 * exist yet: the write creates it (see write()).
	 *
	 * @param WP_Post $post Post.
	 * @return string[]
	 */
	public function touched_locs( $post ): array {
		$meta = $this->layout_meta();
		return '' === $meta ? array() : array( 'meta:' . $meta );
	}

	/* ------------------------------------------------------- set_fields */

	/**
	 * Change fields of one element.
	 *
	 * @param WP_Post $post       Post.
	 * @param string  $element_id Element id from the outline.
	 * @param array   $fields     Field name (or kind alias) => new value.
	 * @param array   $opts       See execute().
	 * @return array|WP_Error
	 */
	public function set_fields( $post, $element_id, array $fields, array $opts ) {
		$el = $this->element( $post, $element_id );
		if ( is_wp_error( $el ) ) {
			return $el;
		}
		$page = $this->page( $post );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$entry = isset( $page['ids'][ (string) $el['id'] ] ) ? $page['ids'][ (string) $el['id'] ] : null;
		if ( null === $entry || $entry['type'] !== (string) $el['type'] ) {
			return new WP_Error( 'ab_mcp_builder_no_element', __( 'The element could not be found again in the stored page. Read it again with wp_get_builder_layout.', 'alphabridge-mcp' ) );
		}
		$locked = $this->writer_lock( $entry );
		if ( '' !== $locked ) {
			return new WP_Error(
				'ab_mcp_builder_locked',
				/* translators: 1: element id, 2: shortcode tag, 3: builder name */
				sprintf( __( 'Element %1$s is or sits inside [%2$s], which holds code, an embed or a form: AlphaBridge does not change it. Change it in the %3$s editor in wp-admin.', 'alphabridge-mcp' ), (string) $el['id'], $locked, $this->adapter()->name() ),
				array( 'reason' => 'code element' )
			);
		}
		if ( null === $entry['spec'] && '#text' !== $entry['node']['tag'] ) {
			return new WP_Error(
				'ab_mcp_builder_unprofiled',
				sprintf(
					/* translators: 1: element id, 2: shortcode tag, 3: builder name */
					__( 'Element %1$s ([%2$s]) is not described by the %3$s profile, so AlphaBridge only reads its visible text and does not change it. Change it in the builder\'s editor in wp-admin; an administrator can also replace words in it with wp_search_replace and scope raw.', 'alphabridge-mcp' ),
					(string) $el['id'],
					(string) $entry['node']['tag'],
					$this->adapter()->name()
				)
			);
		}

		$slots   = $this->slots( $entry, $page );
		$blocked = $this->blocked_request( $slots, $fields );
		if ( is_wp_error( $blocked ) ) {
			return $blocked;
		}
		$target = array(
			'id'     => (string) $el['id'],
			'type'   => (string) $el['type'],
			'fields' => array(),
		);
		if ( ! empty( $el['global'] ) ) {
			$target['global'] = true;
		}
		if ( isset( $el['note'] ) ) {
			$target['note'] = $el['note'];
		}
		foreach ( $slots as $name => $slot ) {
			if ( '' === $slot['blocked'] ) {
				// The full stored value: what the change replaces and what its
				// placeholders are checked against.
				$target['fields'][ $name ] = array(
					'kind'  => $slot['kind'],
					'value' => $slot['value'],
				);
			}
		}
		$changes = $this->fields( $target, $fields );
		if ( is_wp_error( $changes ) ) {
			return $changes;
		}
		// A field that already holds its value is no change: saving the page
		// anyway would run wp_update_post() and leave an undo point for nothing.
		$changes = self::changed_only( $changes );
		if ( array() === $changes ) {
			return self::nothing_changed();
		}
		$changes = $this->with_image_copies( $entry, $slots, $changes );

		$edits = array();
		$notes = array();
		foreach ( $changes as $i => $c ) {
			$edit = $this->edit( $entry, $slots[ $c['field'] ], $c, $page );
			if ( is_wp_error( $edit ) ) {
				return $edit;
			}
			$changes[ $i ]['expect'] = $edit['expect'];
			$edits                   = array_merge( $edits, $edit['edits'] );
			if ( is_string( $edit['expect'] ) && html_entity_decode( $edit['expect'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) !== html_entity_decode( (string) $c['after'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) && 'image' !== $c['kind'] ) {
				/* translators: 1: field name, 2: value as stored */
				$notes[] = sprintf( __( '%1$s is stored as «%2$s»: the page builder writes these characters that way in its attributes.', 'alphabridge-mcp' ), $c['field'], $edit['expect'] );
			}
		}

		$change = array(
			'element'  => $target,
			'changes'  => $changes,
			'warnings' => array(),
			'notes'    => $notes,
		);
		if ( ! empty( $el['global'] ) && isset( $el['note'] ) ) {
			// Divi's global modules and Enfold's element templates show values
			// kept elsewhere: the page's copy may not be what is shown.
			$change['warnings'][] = (string) $el['note'];
		}
		$built = $this->build( $page, $edits, $change );
		if ( is_wp_error( $built ) ) {
			return $built;
		}
		if ( $built['write']['text'] === $page['text'] ) {
			// The new values, in the builder's encoding, are the bytes stored.
			return self::nothing_changed();
		}
		return $this->execute( $post, $built, $opts );
	}

	/* ---------------------------------------------------------- replace */

	/**
	 * Replace a text in every field this writer may write on the page.
	 * Matches in locked elements, in elements the profile does not describe,
	 * in attributes that are no field and in placeholders are left as they
	 * are and counted as locked_matches.
	 *
	 * $opts beyond execute()'s: case_insensitive (bool), as wp_search_replace
	 * passes it. regex is refused with the way: a pattern cannot be told
	 * apart from the shortcode syntax around a value.
	 *
	 * @param WP_Post $post    Post.
	 * @param string  $search  Text to find.
	 * @param string  $replace Replacement.
	 * @param array   $opts    See execute().
	 * @return array|WP_Error execute()'s answer plus matches and locked_matches;
	 *         without a writable match a WP_Error ab_mcp_builder_nothing whose
	 *         data carries locked_matches.
	 */
	public function replace( $post, $search, $replace, array $opts ) {
		$search  = (string) $search;
		$replace = (string) $replace;
		if ( '' === $search || $search === $replace ) {
			return new WP_Error( 'ab_mcp_builder_nothing', __( 'There is nothing to replace: the search text is empty or the same as the replacement. Pass a search text that differs from replace.', 'alphabridge-mcp' ), array( 'locked_matches' => 0 ) );
		}
		if ( ! empty( $opts['regex'] ) ) {
			return new WP_Error(
				'ab_mcp_builder_regex',
				/* translators: %s: page builder name */
				sprintf( __( 'A regular expression is not applied to the fields of a %s page: it could match the shortcode syntax around them. Search for the text itself, or run wp_search_replace with scope: raw.', 'alphabridge-mcp' ), null !== $this->adapter() ? $this->adapter()->name() : __( 'shortcode builder', 'alphabridge-mcp' ) )
			);
		}
		$ci   = ! empty( $opts['case_insensitive'] );
		$page = $this->page( $post );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$changes = array();
		$edits   = array();
		$notes   = array();
		$left    = array();
		$done    = 0;
		foreach ( $page['ids'] as $id => $entry ) {
			if ( ! $entry['listed'] || '' !== $this->writer_lock( $entry ) || ( null === $entry['spec'] && '#text' !== $entry['node']['tag'] ) ) {
				continue;
			}
			foreach ( $this->slots( $entry, $page ) as $slot ) {
				$found = $this->replace_in_slot( $entry, $slot, $search, $replace, $page, $ci );
				if ( null === $found ) {
					continue;
				}
				if ( is_wp_error( $found ) ) {
					$left[] = sprintf( '%s.%s: %s', $id, $slot['name'], $found->get_error_message() );
					continue;
				}
				$changes[] = $found['change'];
				$edits     = array_merge( $edits, $found['edits'] );
				$done     += $found['count'];
			}
		}
		$locked = max( 0, $this->count_all( $page['text'], $search, $ci ) - $done );
		$notes  = array_merge( $notes, $left );
		if ( array() === $changes ) {
			// Counts of what a page holds are for those who may edit it.
			if ( ! current_user_can( 'edit_post', (int) $post->ID ) ) {
				return AB_MCP_Guidance::role_refusal( __( 'Your account cannot edit this post, so it cannot change its elements either. Ask someone who may edit it, or an administrator.', 'alphabridge-mcp' ) );
			}
			$msg = __( 'No field AlphaBridge writes on this page holds the text.', 'alphabridge-mcp' );
			if ( $locked > 0 ) {
				/* translators: %d: number of matches */
				$msg .= ' ' . sprintf( _n( '%d match is in a locked element, a setting or a code field; run again with scope: raw to replace it there too, or change it in the page builder\'s editor.', '%d matches are in locked elements, settings or code fields; run again with scope: raw to replace them there too, or change them in the page builder\'s editor.', $locked, 'alphabridge-mcp' ), $locked );
			}
			return new WP_Error( 'ab_mcp_builder_nothing', $msg, array( 'locked_matches' => $locked, 'notes' => $notes, 'left' => $left ) );
		}
		$change = array(
			'changes'        => $changes,
			'locked_matches' => $locked,
			'left'           => $left,
			'warnings'       => array(),
			'notes'          => $notes,
		);
		if ( $locked > 0 ) {
			/* translators: %d: number of matches */
			$change['notes'][] = sprintf( _n( '%d further match is in a locked element, a setting or a code field and was left as it is (scope: raw replaces it there too).', '%d further matches are in locked elements, settings or code fields and were left as they are (scope: raw replaces them there too).', $locked, 'alphabridge-mcp' ), $locked );
		}
		$built = $this->build( $page, $edits, $change );
		if ( is_wp_error( $built ) ) {
			return $built;
		}
		$res = $this->execute( $post, $built, $opts );
		if ( is_array( $res ) ) {
			$res['matches'] = $done; // execute() hands locked_matches on itself.
		}
		return $res;
	}

	/* ------------------------------------------------------------ write */

	/**
	 * Step 5: store the new text — Enfold's layout meta and its copy, or
	 * post_content — and check that it is stored as meant.
	 *
	 * @param WP_Post $post   Post as read right before.
	 * @param array   $change Change with 'write' { loc, sha, text }.
	 * @param array   $opts   Options.
	 * @return true|WP_Error
	 */
	protected function write( $post, array $change, array $opts ) {
		$w       = isset( $change['write'] ) && is_array( $change['write'] ) ? $change['write'] : null;
		$adapter = $this->adapter();
		if ( null === $w || ! ( $adapter instanceof AB_MCP_Builder_Adapter_Shortcodes ) ) {
			return new WP_Error( 'ab_mcp_builder_write_failed', __( 'The change carries no text to store.', 'alphabridge-mcp' ) );
		}
		// The page as stored now, not as handed in: somebody may have saved
		// it while the change was prepared and the undo point taken.
		$id = (int) $post->ID;
		wp_cache_delete( $id, 'post_meta' );
		$now    = self::fresh_post( $id );
		$source = null === $now ? null : $adapter->layout_source( $now );
		if ( null === $source || $source['loc'] !== $w['loc'] || ! hash_equals( (string) $w['sha'], hash( 'sha256', (string) $source['text'] ) ) ) {
			return new WP_Error( 'ab_mcp_builder_layout_changed', __( 'The page changed while the change was prepared. Nothing was written. Read it again with wp_get_builder_layout.', 'alphabridge-mcp' ) );
		}
		$text = (string) $w['text'];
		$meta = $this->layout_meta();
		if ( '' !== $meta ) {
			update_post_meta( $id, $meta, wp_slash( $text ) );
			if ( $text !== $this->stored_meta( $id, $meta ) ) {
				return new WP_Error( 'ab_mcp_builder_write_failed', __( 'The page builder\'s layout field was not stored as written.', 'alphabridge-mcp' ) );
			}
			// The copy: what the builder writes alongside; not what the site
			// shows, so a copy WordPress filters differently is no failure.
			$copy = $this->save_content( $id, $text );
			if ( is_wp_error( $copy ) ) {
				$this->warnings[] = __( 'The copy of the page in post_content could not be updated; the page itself (the builder\'s layout field) was. The builder writes the copy again the next time the page is saved in its editor.', 'alphabridge-mcp' );
			}
			return true;
		}
		$stored = $this->as_stored( $id, $text );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		$saved = $this->save_content( $id, $text );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$fresh = self::fresh_post( $id );
		if ( null === $fresh || (string) $fresh->post_content !== $stored ) {
			return new WP_Error( 'ab_mcp_builder_write_failed', __( 'WordPress stored the page differently from what was written.', 'alphabridge-mcp' ) );
		}
		if ( $stored !== $text ) {
			$this->notes[] = __( 'WordPress wrote some characters of this page as HTML entities for your account (for example & as &amp;), as it does whenever this account saves post content. What the page shows is the same.', 'alphabridge-mcp' );
		}
		return true;
	}

	/* ------------------------------------------------------------ pages */

	/**
	 * The page as the reader parses it, with every element by its outline id.
	 *
	 * @param WP_Post $post Post.
	 * @return array|WP_Error { post_id, loc, text, profile, ids: id => entry }
	 */
	private function page( $post ) {
		$adapter = $this->adapter();
		if ( ! ( $adapter instanceof AB_MCP_Builder_Adapter_Shortcodes ) ) {
			return new WP_Error( 'ab_mcp_builder_no_reader', __( 'The reader of this page builder is not loaded on this site. Change the page in its editor in wp-admin.', 'alphabridge-mcp' ) );
		}
		$tree    = $adapter->tree( $post );
		$profile = $adapter->profile();
		$counts  = array();
		if ( '' !== $profile['id_attr'] ) {
			$this->count_ids( $tree['nodes'], $profile['id_attr'], $counts );
		}
		$page = array(
			'post_id' => (int) $post->ID,
			'loc'     => (string) $tree['loc'],
			'text'    => (string) $tree['text'],
			'profile' => $profile,
			'ids'     => array(),
		);
		$this->index( $tree['nodes'], array(), true, $counts, $page );
		return $page;
	}

	/**
	 * Walk the parse as the reader's outline does and keep each element under
	 * its outline id: node, profile entry, ancestors, whether it is listed.
	 *
	 * @param array $nodes      Nodes.
	 * @param array $ancestors  Tags of the enclosing shortcodes.
	 * @param bool  $text_shown Whether text runs at this level are elements of their own.
	 * @param array $counts     Occurrences of each builder id.
	 * @param array $page       Page (by reference).
	 */
	private function index( array $nodes, array $ancestors, $text_shown, array $counts, array &$page ) {
		$profile = $page['profile'];
		foreach ( $nodes as $node ) {
			$tag = (string) $node['tag'];
			if ( '#text' === $tag ) {
				if ( $text_shown ) {
					$page['ids'][ (string) $node['id'] ] = array(
						'id'        => (string) $node['id'],
						'node'      => $node,
						'spec'      => null,
						'type'      => '#text',
						'ancestors' => $ancestors,
						'listed'    => '' !== AB_MCP_Builders::field_text( AB_MCP_Shortcode_Reader::slice( $page['text'], $node['start'], $node['end'] ) ),
					);
				}
				continue;
			}
			$spec = isset( $profile['tags'][ $tag ] ) ? $profile['tags'][ $tag ] : null;
			$id   = $this->element_id( $node, $profile['id_attr'], $counts );
			if ( ! isset( $page['ids'][ $id ] ) ) {
				$page['ids'][ $id ] = array(
					'id'        => $id,
					'node'      => $node,
					'spec'      => $spec,
					'type'      => null !== $spec && '' !== $spec['type'] ? $spec['type'] : $tag,
					'ancestors' => $ancestors,
					'listed'    => null === $spec || '' === $spec['locked'],
				);
			}
			if ( null !== $spec && '' !== $spec['locked'] ) {
				continue; // The reader lists nothing inside a locked element.
			}
			$this->index( $node['children'], array_merge( $ancestors, array( $tag ) ), null !== $spec && $spec['container'], $counts, $page );
		}
	}

	/**
	 * The element id the reader gives a node: the builder's own id where it
	 * is set, usable and unique, else the reader's path.
	 *
	 * @param array  $node    Node.
	 * @param string $id_attr Profile's id attribute.
	 * @param array  $counts  Occurrences of each builder id.
	 * @return string
	 */
	private function element_id( array $node, $id_attr, array $counts ) {
		if ( '' !== $id_attr && isset( $node['attrs'][ $id_attr ] ) ) {
			$candidate = (string) $node['attrs'][ $id_attr ];
			if ( AB_MCP_Block_Reader::usable_id( $candidate ) && 1 === ( isset( $counts[ $candidate ] ) ? $counts[ $candidate ] : 0 ) ) {
				return $candidate;
			}
		}
		return (string) $node['id'];
	}

	/**
	 * Count the builder ids on the page, as the reader does.
	 *
	 * @param array  $nodes   Nodes.
	 * @param string $id_attr Id attribute.
	 * @param array  $counts  Counts (by reference).
	 */
	private function count_ids( array $nodes, $id_attr, array &$counts ) {
		foreach ( $nodes as $node ) {
			if ( '#text' === $node['tag'] ) {
				continue;
			}
			if ( isset( $node['attrs'][ $id_attr ] ) ) {
				$key            = (string) $node['attrs'][ $id_attr ];
				$counts[ $key ] = ( isset( $counts[ $key ] ) ? $counts[ $key ] : 0 ) + 1;
			}
			$this->count_ids( $node['children'], $id_attr, $counts );
		}
	}

	/**
	 * The tag of LOCKED_TAGS the element is or sits in; '' when none.
	 *
	 * @param array $entry Element entry.
	 * @return string
	 */
	private function writer_lock( array $entry ) {
		/**
		 * Shortcodes the shortcode writer never changes, nor anything inside
		 * them, in addition to its own list (plan §8). Entries can be added,
		 * not removed.
		 *
		 * @param string[] $tags Tags.
		 */
		$extra  = (array) apply_filters( 'ab_mcp_pro_shortcode_locked_tags', array() );
		$locked = array_flip( array_merge( self::LOCKED_TAGS, array_map( 'strval', $extra ) ) );
		foreach ( array_merge( $entry['ancestors'], array( (string) $entry['node']['tag'] ) ) as $tag ) {
			if ( isset( $locked[ $tag ] ) ) {
				return (string) $tag;
			}
		}
		return '';
	}

	/* ------------------------------------------------------------ slots */

	/**
	 * The writable places of one element: every field of its profile entry
	 * (a #text run: its text), with the stored value, where it stands in the
	 * text, and why it cannot be written, if it cannot.
	 *
	 * @param array $entry Element entry.
	 * @param array $page  Page.
	 * @return array<string,array> Field name => slot { name, kind, from, attr,
	 *   format, value, ranges, quote, missing, mixed, blocked }.
	 */
	private function slots( array $entry, array $page ) {
		$node = $entry['node'];
		$out  = array();
		if ( '#text' === $node['tag'] ) {
			$raw          = AB_MCP_Shortcode_Reader::slice( $page['text'], $node['start'], $node['end'] );
			$out['text']  = self::slot( 'text', 'html', 'text', '', '', $raw, array( array( $node['start'], $node['end'] ) ) );
			$out['text']['blocked'] = 1 === preg_match( self::HIDDEN_IN_CONTENT, $raw ) ? $this->hidden_message( 'text' ) : '';
			return $out;
		}
		$spec = $entry['spec'];
		foreach ( null === $spec ? array() : $spec['fields'] as $name => $field ) {
			$name = (string) $name;
			if ( 'content' === $field['from'] ) {
				$out[ $name ] = $this->content_slot( $name, $field, $node, $page );
				continue;
			}
			$out[ $name ] = $this->attr_slot( $name, $field, $node, $spec, $page );
		}
		return $out;
	}

	/**
	 * A slot with every key.
	 *
	 * @param string     $name   Field name.
	 * @param string     $kind   Kind.
	 * @param string     $from   content, attr or text.
	 * @param string     $attr   Attribute.
	 * @param string     $format Format.
	 * @param string|int $value  Stored value.
	 * @param array      $ranges Byte ranges.
	 * @return array
	 */
	private static function slot( $name, $kind, $from, $attr, $format, $value, array $ranges ) {
		return array(
			'name'    => $name,
			'kind'    => $kind,
			'from'    => $from,
			'attr'    => $attr,
			'format'  => $format,
			'value'   => $value,
			'ranges'  => $ranges,
			'quote'   => '',
			'missing' => false,
			'mixed'   => false,
			'blocked' => '',
		);
	}

	/**
	 * The content between an element's tags as a field: the whole content,
	 * or — where it holds shortcodes too — its text runs, which only a
	 * replacement can change.
	 *
	 * @param string $name  Field name.
	 * @param array  $field Profile field.
	 * @param array  $node  Node.
	 * @param array  $page  Page.
	 * @return array
	 */
	private function content_slot( $name, array $field, array $node, array $page ) {
		if ( null === $node['content'] ) {
			$slot            = self::slot( $name, $field['kind'], 'content', '', '', '', array() );
			$slot['blocked'] = sprintf(
				/* translators: %s: field name */
				__( 'The field %s is the text between the element\'s opening and closing tag, and this element has no closing tag. Change it in the page builder\'s editor.', 'alphabridge-mcp' ),
				$name
			);
			return $slot;
		}
		$runs  = array();
		$mixed = false;
		foreach ( $node['children'] as $child ) {
			if ( '#text' === $child['tag'] ) {
				$runs[] = array( $child['start'], $child['end'] );
			} else {
				$mixed = true;
			}
		}
		if ( ! $mixed ) {
			$raw             = AB_MCP_Shortcode_Reader::slice( $page['text'], $node['content'][0], $node['content'][1] );
			$slot            = self::slot( $name, $field['kind'], 'content', '', '', $raw, array( $node['content'] ) );
			$slot['blocked'] = 1 === preg_match( self::HIDDEN_IN_CONTENT, $raw ) ? $this->hidden_message( $name ) : '';
			return $slot;
		}
		$parts = array();
		foreach ( $runs as $r ) {
			$parts[] = AB_MCP_Shortcode_Reader::slice( $page['text'], $r[0], $r[1] );
		}
		$slot            = self::slot( $name, $field['kind'], 'content', '', '', implode( ' ', $parts ), $runs );
		$slot['mixed']   = true;
		$slot['blocked'] = sprintf(
			/* translators: %s: field name */
			__( 'The field %s holds shortcodes as well as text (they are listed as its children), so there is no single place for a new text. Change the words with wp_search_replace, which changes only the matching text, or change the field in the page builder\'s editor.', 'alphabridge-mcp' ),
			$name
		);
		return $slot;
	}

	/**
	 * An attribute as a field, decoded as the reader decodes it.
	 *
	 * @param string $name  Field name.
	 * @param array  $field Profile field.
	 * @param array  $node  Node.
	 * @param array  $spec  Profile entry.
	 * @param array  $page  Page.
	 * @return array
	 */
	private function attr_slot( $name, array $field, array $node, array $spec, array $page ) {
		$attr  = (string) $field['attr'];
		$codec = $this->profile()['codec'];
		$slot  = self::slot( $name, $field['kind'], 'attr', $attr, (string) $field['format'], '', array() );
		if ( in_array( $attr, $spec['code_attrs'], true ) || AB_MCP_Block_Reader::denied_segment( $attr ) ) {
			// The reader never reads such an attribute; neither is it written.
			$slot['blocked'] = __( 'This attribute holds code or styling.', 'alphabridge-mcp' );
			return $slot;
		}
		if ( null !== $field['unless'] && isset( $node['attrs'][ $field['unless']['attr'] ] ) && (string) $node['attrs'][ $field['unless']['attr'] ] === $field['unless']['equals'] ) {
			/* translators: 1: field name, 2: reason */
			$slot['blocked'] = sprintf( __( 'The field %1$s is not written: %2$s. Change that setting in the page builder\'s editor first.', 'alphabridge-mcp' ), $name, $field['unless']['note'] );
			return $slot;
		}
		if ( ! isset( $node['attrs'][ $attr ] ) ) {
			// Not there yet (a builder may leave out a default): it can be added.
			$slot['missing'] = true;
			$slot['quote']   = $this->new_quote();
			if ( ! empty( $node['attrs_truncated'] ) ) {
				$slot['blocked'] = __( 'This element carries more attributes than AlphaBridge reads, so none is added to it. Change it in the page builder\'s editor.', 'alphabridge-mcp' );
			}
			return $slot;
		}
		$raw             = (string) $node['attrs'][ $attr ];
		$slot['ranges']  = array( $node['attr_spans'][ $attr ] );
		$slot['quote']   = isset( $node['attr_quotes'][ $attr ] ) ? (string) $node['attr_quotes'][ $attr ] : '"';
		// The bytes as stored, for a value whose other parts are kept (vc_link).
		$slot['stored']  = AB_MCP_Shortcode_Reader::slice( $page['text'], $slot['ranges'][0][0], $slot['ranges'][0][1] );
		if ( 'wpbakery' === $codec && AB_MCP_Shortcode_Codecs::wpbakery_is_safe_encoded( $raw ) ) {
			/* translators: %s: field name */
			$slot['blocked'] = sprintf( __( 'The field %s holds an encoded value that AlphaBridge does not write. Change it in the page builder\'s editor.', 'alphabridge-mcp' ), $name );
			return $slot;
		}
		if ( 'divi4' === $codec && AB_MCP_Shortcode_Codecs::divi4_is_dynamic( $raw ) ) {
			/* translators: %s: field name */
			$slot['blocked'] = sprintf( __( 'The field %s shows dynamic content that is filled in when the page is shown. Change the source of that content, or switch the field to a fixed text in the page builder\'s editor.', 'alphabridge-mcp' ), $name );
			return $slot;
		}
		if ( 'vc_link' === $slot['format'] ) {
			$slot['value'] = AB_MCP_Shortcode_Codecs::wpbakery_link( $raw )['url'];
		} elseif ( 'enfold_link' === $slot['format'] ) {
			$link          = AB_MCP_Shortcode_Codecs::enfold_link( $raw );
			$slot['value'] = 'internal' === $link['kind'] ? $link['type'] . ',' . $link['id'] : $link['url'];
		} elseif ( 'wpbakery' === $codec ) {
			$slot['value'] = AB_MCP_Shortcode_Codecs::wpbakery_attr( $raw );
		} elseif ( 'divi4' === $codec ) {
			$slot['value'] = AB_MCP_Shortcode_Codecs::divi4_attr( $raw );
		} else {
			$slot['value'] = $raw;
		}
		if ( 'image' === $slot['kind'] && 1 === preg_match( '~^\s*[0-9]+\s*\z~', (string) $slot['value'] ) ) {
			$slot['value'] = (int) $slot['value'];
		}
		return $slot;
	}

	/**
	 * Refuse a request for a field that exists but cannot be written, with
	 * its reason, before the generic «no such field».
	 *
	 * @param array $slots  Slots.
	 * @param array $fields Request.
	 * @return true|WP_Error
	 */
	private function blocked_request( array $slots, array $fields ) {
		foreach ( array_keys( $fields ) as $key ) {
			$key = (string) $key;
			if ( isset( $slots[ $key ] ) && '' !== $slots[ $key ]['blocked'] ) {
				return new WP_Error( 'ab_mcp_builder_field_locked', $slots[ $key ]['blocked'] );
			}
			if ( isset( $slots[ $key ] ) || ! isset( AB_MCP_Builder_Fields::KIND_ALIASES[ $key ] ) ) {
				continue;
			}
			$kind  = AB_MCP_Builder_Fields::KIND_ALIASES[ $key ];
			$open  = array();
			$shut  = array();
			foreach ( $slots as $slot ) {
				if ( $kind === $slot['kind'] ) {
					if ( '' === $slot['blocked'] ) {
						$open[] = $slot;
					} else {
						$shut[] = $slot;
					}
				}
			}
			if ( array() === $open && 1 === count( $shut ) ) {
				return new WP_Error( 'ab_mcp_builder_field_locked', $shut[0]['blocked'] );
			}
		}
		return true;
	}

	/**
	 * The refusal for content the outline does not show in full.
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	private function hidden_message( $name ) {
		return sprintf(
			/* translators: %s: field name */
			__( 'The field %s holds embedded code, media, a form or comments that the outline does not show, and a new value would replace them. Change the field in the page builder\'s editor; an administrator can also replace only the words with wp_search_replace and scope raw.', 'alphabridge-mcp' ),
			$name
		);
	}

	/* ------------------------------------------------------------ edits */

	/**
	 * The byte edit for one change of set_fields(), and what the reader will
	 * show for the field afterwards.
	 *
	 * @param array $entry Element entry.
	 * @param array $slot  Slot.
	 * @param array $c     Change.
	 * @param array $page  Page.
	 * @return array|WP_Error { edits: [ [ start, end, bytes ] ], expect }
	 */
	private function edit( array $entry, array $slot, array $c, array $page ) {
		$ok = $this->keeps_placeholders( $slot['name'], (string) $slot['value'], 'image' === $c['kind'] ? '' : (string) $c['after'] );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( 'attr' !== $slot['from'] ) {
			$range = $slot['ranges'][0];
			$raw   = AB_MCP_Shortcode_Reader::slice( $page['text'], $range[0], $range[1] );
			$value = AB_MCP_Shortcode_Encoder::content( (string) $c['after'], $c['kind'] );
			if ( 'text' === $slot['from'] && '' === AB_MCP_Builders::field_text( $value ) ) {
				return new WP_Error( 'ab_mcp_builder_field_value', __( 'A text that stands between other elements cannot be emptied here: the page would lose the element. Change it in the page builder\'s editor.', 'alphabridge-mcp' ) );
			}
			$new = self::space_before( $raw ) . trim( $value ) . self::space_after( $raw );
			if ( array() !== $this->adapter()->tag_names( $value ) ) {
				return $this->shortcode_in_value( $slot['name'] );
			}
			return array(
				'edits'  => array( array( $range[0], $range[1], $new ) ),
				'expect' => $c['after'],
			);
		}
		return $this->attr_edit( $entry, $slot, $c );
	}

	/**
	 * The byte edit for an attribute: the value in the builder's encoding, in
	 * place of the stored one, or added to the opening tag.
	 *
	 * @param array $entry Element entry.
	 * @param array $slot  Slot.
	 * @param array $c     Change.
	 * @return array|WP_Error
	 */
	private function attr_edit( array $entry, array $slot, array $c ) {
		$codec  = $this->profile()['codec'];
		$quote  = '' === $slot['quote'] ? $this->new_quote() : $slot['quote'];
		$expect = $c['after'];
		if ( 'image' === $c['kind'] ) {
			// Mirror the form the field has: an attachment id, or an address
			// (Divi 4 keeps images by address, divi-4.md c) [14]).
			if ( is_int( $slot['value'] ) ) {
				$by_address = false;
			} elseif ( '' !== trim( (string) $slot['value'] ) ) {
				$by_address = true;
			} else {
				// Empty or not there yet: the attribute's name says which.
				$by_address = 1 === preg_match( '~(?:src|url)~i', $slot['attr'] );
			}
			if ( $by_address ) {
				$url = isset( $c['url'] ) ? (string) $c['url'] : '';
				if ( '' === $url || 1 === preg_match( '~[\s"\'<>`\[\]\\\\]~', $url ) ) {
					return new WP_Error( 'ab_mcp_builder_image', __( 'This field keeps its image by address, and the image has no address that can be written here. Pick another image from the media library (wp_list_media), or change it in the page builder\'s editor.', 'alphabridge-mcp' ) );
				}
				$value  = $url;
				$expect = $url;
			} else {
				$value = (string) (int) $c['after'];
			}
			$bytes = AB_MCP_Shortcode_Encoder::attr( $value, $codec, $quote );
		} elseif ( 'vc_link' === $slot['format'] ) {
			$bytes = AB_MCP_Shortcode_Encoder::vc_link( isset( $slot['stored'] ) ? $slot['stored'] : '', (string) $c['after'] );
		} elseif ( 'enfold_link' === $slot['format'] ) {
			$bytes = AB_MCP_Shortcode_Encoder::enfold_link( (string) $c['after'] );
		} else {
			// Every codec writes "[" and "]" in a form of its own, so no
			// shortcode can stand in an attribute value written here.
			$bytes = AB_MCP_Shortcode_Encoder::attr( (string) $c['after'], $codec, $quote );
		}
		$decoded = AB_MCP_Shortcode_Encoder::decode( $bytes, $codec, $quote );
		if ( 'vc_link' === $slot['format'] ) {
			$shown = null === $decoded ? null : AB_MCP_Shortcode_Codecs::wpbakery_link( $bytes )['url'];
			$right = null !== $shown && $shown === (string) $c['after'];
		} elseif ( 'enfold_link' === $slot['format'] ) {
			$link  = AB_MCP_Shortcode_Codecs::enfold_link( $bytes );
			$right = null !== $decoded && ( '' === (string) $c['after'] ? 'none' === $link['kind'] : ( 'url' === $link['kind'] && $link['url'] === (string) $c['after'] ) );
		} else {
			$right = AB_MCP_Shortcode_Encoder::shows( $decoded, 'image' === $c['kind'] ? $value : (string) $c['after'], $quote );
			if ( $right && in_array( $c['kind'], array( 'text', 'heading' ), true ) ) {
				// What the reader will show: the stored form (Enfold's ’).
				$expect = (string) $decoded;
			}
		}
		if ( ! $right ) {
			return new WP_Error(
				'ab_mcp_builder_encoding',
				sprintf(
					/* translators: 1: field name, 2: page builder name */
					__( 'The new value for %1$s cannot be stored in this %2$s attribute so that the page shows it as sent (it holds characters the builder uses for its own encoding, such as back-ticks or %%22). Send it without them, or change the field in the page builder\'s editor.', 'alphabridge-mcp' ),
					$slot['name'],
					$this->adapter()->name()
				)
			);
		}
		if ( $slot['missing'] ) {
			$at = $this->insert_at( $entry['node'] );
			return array(
				'edits'  => array( array( $at, $at, ' ' . $slot['attr'] . '=' . $quote . $bytes . $quote ) ),
				'expect' => $expect,
			);
		}
		$range = $slot['ranges'][0];
		if ( '' === $slot['quote'] ) {
			// An unquoted value: written in quotes, which every form allows.
			$bytes = $quote . $bytes . $quote;
		}
		return array(
			'edits'  => array( array( $range[0], $range[1], $bytes ) ),
			'expect' => $expect,
		);
	}

	/**
	 * Where a new attribute goes: after the last one, before "]" or "/]".
	 *
	 * @param array $node Node.
	 * @return int
	 */
	private function insert_at( array $node ) {
		$end = (int) $node['open_end'] - 1; // The "]".
		if ( ! empty( $node['self_closing'] ) ) {
			--$end; // The "/".
		}
		return $end;
	}

	/**
	 * Image fields with an address copy: when the attachment changes, the
	 * address follows (IMAGE_COPIES), unless the request sets it itself.
	 *
	 * @param array $entry   Element entry.
	 * @param array $slots   Slots.
	 * @param array $changes Changes.
	 * @return array
	 */
	private function with_image_copies( array $entry, array $slots, array $changes ) {
		$map = isset( self::IMAGE_COPIES[ $this->id() ][ (string) $entry['node']['tag'] ] ) ? self::IMAGE_COPIES[ $this->id() ][ (string) $entry['node']['tag'] ] : array();
		$set = array_column( $changes, 'field' );
		foreach ( $changes as $c ) {
			if ( 'image' !== $c['kind'] || ! isset( $map[ $c['field'] ] ) || empty( $c['url'] ) ) {
				continue;
			}
			$copy = $map[ $c['field'] ];
			if ( in_array( $copy, $set, true ) || ! isset( $slots[ $copy ] ) || '' !== $slots[ $copy ]['blocked'] || $slots[ $copy ]['missing'] ) {
				continue;
			}
			$checked = AB_MCP_Builder_Fields::check( 'url', (string) $c['url'], (string) $c['type'], $copy );
			if ( is_wp_error( $checked ) ) {
				continue;
			}
			$changes[] = array(
				'element' => $c['element'],
				'type'    => $c['type'],
				'field'   => $copy,
				'kind'    => 'url',
				'before'  => (string) $slots[ $copy ]['value'],
				'after'   => $checked['value'],
			);
		}
		return $changes;
	}

	/**
	 * Every change of a replacement in one slot, or null where the text is
	 * not in it; a WP_Error where it is but cannot be replaced there.
	 *
	 * @param array  $entry   Element entry.
	 * @param array  $slot    Slot.
	 * @param string $search  Text.
	 * @param string $replace Replacement.
	 * @param array  $page    Page.
	 * @param bool   $ci      Case-insensitive.
	 * @return array|WP_Error|null { change, edits, count }
	 */
	private function replace_in_slot( array $entry, array $slot, $search, $replace, array $page, $ci = false ) {
		if ( ( '' !== $slot['blocked'] && ! $slot['mixed'] && 1 !== preg_match( self::HIDDEN_IN_CONTENT, (string) $slot['value'] ) ) || $slot['missing'] ) {
			return null;
		}
		if ( 'image' === $slot['kind'] && ! is_string( $slot['value'] ) ) {
			return null; // An attachment id: no text to replace.
		}
		$kind = 'image' === $slot['kind'] ? 'url' : $slot['kind'];
		if ( 'attr' === $slot['from'] ) {
			$quote = '' === $slot['quote'] ? $this->new_quote() : $slot['quote'];
			list( $after, $count ) = self::swap( (string) $slot['value'], "'" === $quote && 'url' !== $kind ? array( 'plain' => $search, 'quoted' => AB_MCP_Shortcode_Encoder::apostrophes( $search ) ) : array( 'plain' => $search ), array( 'plain' => $replace, 'quoted' => AB_MCP_Shortcode_Encoder::apostrophes( $replace ) ), $ci );
			if ( 0 === $count ) {
				return null;
			}
			$c = array(
				'element' => $entry['id'],
				'type'    => (string) $entry['type'],
				'field'   => $slot['name'],
				'kind'    => $kind,
				'before'  => $slot['value'],
				'after'   => $after,
			);
			$ok = $this->check_replaced( $c );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$edit = $this->attr_edit( $entry, array_merge( $slot, array( 'kind' => $kind ) ), $c );
			if ( is_wp_error( $edit ) ) {
				return $edit;
			}
			$c['expect'] = $edit['expect'];
			return array(
				'change' => $c,
				'edits'  => $edit['edits'],
				'count'  => $count,
			);
		}
		// Content and text runs: each run on its own, in the forms HTML keeps
		// the text in, so the rest of the run stays byte for byte.
		$forms = AB_MCP_Shortcode_Encoder::html_forms( $search );
		$repls = array(
			'plain' => $replace,
			'html'  => htmlspecialchars( $replace, ENT_NOQUOTES, 'UTF-8', false ),
		);
		$edits = array();
		$parts = array();
		$total = 0;
		foreach ( $slot['ranges'] as $range ) {
			$raw                   = AB_MCP_Shortcode_Reader::slice( $page['text'], $range[0], $range[1] );
			list( $new, $count )   = self::swap( $raw, $forms, $repls, $ci );
			$parts[]               = $new;
			$total                += $count;
			if ( $count > 0 ) {
				if ( $this->adapter()->tag_names( $new ) !== $this->adapter()->tag_names( $raw ) ) {
					return $this->shortcode_in_value( $slot['name'] );
				}
				$edits[] = array( $range[0], $range[1], $new );
			}
		}
		if ( 0 === $total ) {
			return null;
		}
		$c  = array(
			'element' => $entry['id'],
			'type'    => (string) $entry['type'],
			'field'   => $slot['name'],
			'kind'    => $kind,
			'before'  => $slot['value'],
			'after'   => implode( ' ', $parts ),
		);
		$ok = $this->check_replaced( $c );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( '#text' === $entry['node']['tag'] && '' === AB_MCP_Builders::field_text( $c['after'] ) ) {
			return new WP_Error( 'ab_mcp_builder_field_value', __( 'the replacement would empty a text that stands between other elements', 'alphabridge-mcp' ) );
		}
		return array(
			'change' => $c,
			'edits'  => $edits,
			'count'  => $total,
		);
	}

	/**
	 * The rules a change of a replacement has to meet before it joins the
	 * others: its kind's checks, the placeholders of the profile and of
	 * AB_MCP_Builder_Fields.
	 *
	 * @param array $c Change.
	 * @return true|WP_Error
	 */
	private function check_replaced( array $c ) {
		$ok = AB_MCP_Builder_Fields::validate_change( $c );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return $this->keeps_placeholders( $c['field'], (string) $c['before'], (string) $c['after'] );
	}

	/**
	 * Replace every form of the text in one pass, longest first.
	 *
	 * @param string $text  Text.
	 * @param array  $forms Key => form of the search text.
	 * @param array  $repls Key => form of the replacement.
	 * @param bool   $ci    Case-insensitive.
	 * @return array{0:string,1:int}
	 */
	private static function swap( $text, array $forms, array $repls, $ci = false ) {
		$forms = array_filter( array_unique( $forms ), 'strlen' );
		uasort(
			$forms,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		$by_form = array();
		foreach ( $forms as $key => $form ) {
			$by_form[ $form ] = isset( $repls[ $key ] ) ? $repls[ $key ] : $repls['plain'];
		}
		$pattern = '~' . implode( '|', array_map( static function ( $f ) { return preg_quote( $f, '~' ); }, array_keys( $by_form ) ) ) . '~';
		$flags   = $ci ? 'i' : '';
		$mod     = 'u';
		// Case-insensitive, a match may spell its form otherwise: the first
		// form (longest first, as the alternation tries them) that matches it.
		$pick = static function ( $m ) use ( $by_form, $flags, &$mod ) {
			if ( isset( $by_form[ $m[0] ] ) ) {
				return $by_form[ $m[0] ];
			}
			foreach ( $by_form as $form => $repl ) {
				if ( 1 === preg_match( '~^' . preg_quote( (string) $form, '~' ) . '\z~' . $mod . $flags, $m[0] ) ) {
					return $repl;
				}
			}
			return $m[0];
		};
		$count = 0;
		$out   = preg_replace_callback( $pattern . $mod . $flags, $pick, (string) $text, -1, $count );
		if ( null === $out ) {
			// Not valid UTF-8: the pattern without u, byte for byte.
			$mod = '';
			$out = preg_replace_callback( $pattern . $flags, $pick, (string) $text, -1, $count );
		}
		return array( (string) $out, (int) $count );
	}

	/**
	 * How often the text stands in the whole stored page, in any form a
	 * field could hold it: the base for locked_matches.
	 *
	 * @param string $text   Stored page.
	 * @param string $search Text.
	 * @param bool   $ci     Case-insensitive.
	 * @return int
	 */
	private function count_all( $text, $search, $ci = false ) {
		$forms = AB_MCP_Shortcode_Encoder::html_forms( $search );
		$codec = $this->profile()['codec'];
		foreach ( array( '"', "'" ) as $quote ) {
			$forms[ 'attr' . $quote ] = AB_MCP_Shortcode_Encoder::attr( $search, $codec, $quote );
		}
		if ( 'wpbakery' === $codec ) {
			$forms['link'] = rawurlencode( $search ); // A WPBakery link holds its address encoded.
		}
		list( , $count ) = self::swap( (string) $text, $forms, array( 'plain' => '' ), $ci );
		return $count;
	}

	/**
	 * Placeholders of the profile (Avada's {post_title}, Enfold's
	 * {wp_post_title} …) stay as they are, in number and order — besides the
	 * ones AB_MCP_Builder_Fields knows.
	 *
	 * @param string $name   Field name.
	 * @param string $before Stored value.
	 * @param string $after  New value.
	 * @return true|WP_Error
	 */
	private function keeps_placeholders( $name, $before, $after ) {
		$pattern = (string) $this->profile()['placeholder'];
		if ( '' === $pattern ) {
			return true;
		}
		preg_match_all( $pattern, $before, $b );
		preg_match_all( $pattern, $after, $a );
		if ( $b[0] === $a[0] ) {
			return true;
		}
		return new WP_Error(
			'ab_mcp_builder_placeholder',
			sprintf(
				/* translators: 1: field name, 2: placeholders */
				__( 'The field %1$s has placeholders the page builder fills in when the page is shown (%2$s); a change must keep them as they are and add none. Nothing was changed. Keep them in the new text, or change them in the page builder\'s editor.', 'alphabridge-mcp' ),
				$name,
				array() === $b[0] ? '-' : implode( ' ', $b[0] )
			)
		);
	}

	/**
	 * The refusal for a value that would add a shortcode.
	 *
	 * @param string $name Field name.
	 * @return WP_Error
	 */
	private function shortcode_in_value( $name ) {
		return new WP_Error(
			'ab_mcp_builder_shortcode_in_value',
			sprintf(
				/* translators: %s: field name */
				__( 'The new value for %s contains a shortcode in square brackets, which would add or end an element of the page. AlphaBridge changes text, links and images only; add elements in the page builder\'s editor.', 'alphabridge-mcp' ),
				$name
			)
		);
	}

	/* ------------------------------------------------------------ build */

	/**
	 * Apply the byte edits to the stored text, check the result parses to the
	 * same shortcodes, and hand the change on with the text to store.
	 *
	 * @param array $page   Page.
	 * @param array $edits  [ start, end, bytes ].
	 * @param array $change Change.
	 * @return array|WP_Error
	 */
	private function build( array $page, array $edits, array $change ) {
		usort(
			$edits,
			static function ( $a, $b ) {
				return $b[0] - $a[0];
			}
		);
		$text  = $page['text'];
		$floor = PHP_INT_MAX;
		foreach ( $edits as $e ) {
			if ( $e[1] > $floor ) {
				return new WP_Error( 'ab_mcp_builder_bad_change', __( 'Two changes overlap in the stored page. Change one field at a time.', 'alphabridge-mcp' ) );
			}
			$text  = substr_replace( $text, (string) $e[2], (int) $e[0], (int) $e[1] - (int) $e[0] );
			$floor = (int) $e[0];
		}
		if ( $this->shape( $page['text'] ) !== $this->shape( $text ) ) {
			return new WP_Error( 'ab_mcp_builder_structure', __( 'The change would alter the shortcodes of the page, not only their texts. Nothing was written. Change it in the page builder\'s editor.', 'alphabridge-mcp' ) );
		}
		$change['write'] = array(
			'loc'  => $page['loc'],
			'sha'  => hash( 'sha256', $page['text'] ),
			'text' => $text,
		);
		if ( '' === $this->layout_meta() ) {
			$stored = $this->as_stored( $page['post_id'], $text );
			if ( is_wp_error( $stored ) ) {
				$change['warnings'][] = __( 'Applying will be refused:', 'alphabridge-mcp' ) . ' ' . $stored->get_error_message();
			}
		}
		return $change;
	}

	/**
	 * The shortcodes of a text as the reader parses them: tag and position
	 * id of each, in order.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private function shape( $text ) {
		$parsed = AB_MCP_Shortcode_Reader::parse( $text, array( 'tags' => $this->adapter()->tag_names( $text ) ) );
		$out    = array();
		$walk   = static function ( array $nodes ) use ( &$walk, &$out ) {
			foreach ( $nodes as $n ) {
				if ( '#text' !== $n['tag'] ) {
					$out[] = $n['id'] . ':' . $n['tag'] . ( empty( $n['closed'] ) ? '' : '/' );
				}
				$walk( $n['children'] );
			}
		};
		$walk( $parsed['nodes'] );
		$out[] = $parsed['truncated'] ? 'truncated' : '';
		return $out;
	}

	/* ----------------------------------------------------------- saving */

	/**
	 * What WordPress will store as post_content for this account: the text
	 * itself, or — for an account without unfiltered_html — what its content
	 * filters (kses) make of it. Refused where they would change more than
	 * how characters are written (entities, quotes, white space).
	 *
	 * @param int    $id   Post id.
	 * @param string $text Text.
	 * @return string|WP_Error
	 */
	private function as_stored( $id, $text ) {
		if ( current_user_can( 'unfiltered_html' ) || ! function_exists( 'sanitize_post_field' ) ) {
			return $text;
		}
		$stored = wp_unslash( sanitize_post_field( 'post_content', wp_slash( $text ), (int) $id, 'db' ) );
		if ( ! is_string( $stored ) ) {
			return new WP_Error( 'ab_mcp_builder_kses', __( 'WordPress\' content filters gave no text back for this page. Nothing was written. An account with the unfiltered_html capability (an administrator on a single site) can make this change, or change it in the page builder\'s editor.', 'alphabridge-mcp' ) );
		}
		if ( $stored === $text || self::markup_key( $stored ) === self::markup_key( $text ) ) {
			return $stored;
		}
		return new WP_Error(
			'ab_mcp_builder_kses',
			__( 'Your account may not store unfiltered HTML, so WordPress filters the whole page when it is saved, and here that would change parts of the page you did not ask to change (markup elsewhere on the page that post content may not hold for your account). Nothing was written. An account with the unfiltered_html capability (an administrator on a single site) can make this change, or change it in the page builder\'s editor.', 'alphabridge-mcp' )
		);
	}

	/**
	 * Save post_content as the classic editor does: wp_update_post() with
	 * slashed data, so WordPress' own save path, hooks and revisions run.
	 *
	 * @param int    $id   Post id.
	 * @param string $text Text.
	 * @return true|WP_Error
	 */
	private function save_content( $id, $text ) {
		$res = wp_update_post(
			wp_slash(
				array(
					'ID'           => (int) $id,
					'post_content' => $text,
				)
			),
			true
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( (int) $res !== (int) $id ) {
			return new WP_Error( 'ab_mcp_builder_write_failed', __( 'WordPress did not save the page.', 'alphabridge-mcp' ) );
		}
		return true;
	}

	/**
	 * A meta value as stored, the first row of its key.
	 *
	 * @param int    $id  Post id.
	 * @param string $key Key.
	 * @return string|null
	 */
	private function stored_meta( $id, $key ) {
		global $wpdb;
		wp_cache_delete( (int) $id, 'post_meta' );
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1", (int) $id, (string) $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the stored bytes are what is checked.
		return null === $value ? null : (string) $value;
	}

	/* ---------------------------------------------------------- helpers */

	/**
	 * The reading adapter's profile.
	 *
	 * @return array
	 */
	private function profile() {
		$adapter = $this->adapter();
		return $adapter instanceof AB_MCP_Builder_Adapter_Shortcodes ? $adapter->profile() : array(
			'codec'       => '',
			'layout_meta' => '',
			'placeholder' => '',
			'id_attr'     => '',
			'tags'        => array(),
		);
	}

	/**
	 * The meta key that holds the page's shortcodes ('' = post_content).
	 *
	 * @return string
	 */
	private function layout_meta() {
		return (string) $this->profile()['layout_meta'];
	}

	/**
	 * Quotes for an attribute AlphaBridge writes anew: Enfold's single quotes
	 * where the page lives in a layout meta written by Enfold's builder
	 * (enfold.md c) [4] l. 2573), else double quotes.
	 *
	 * @return string
	 */
	private function new_quote() {
		return 'enfold' === $this->id() ? "'" : '"';
	}

	/**
	 * Leading white space of a text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function space_before( $text ) {
		return preg_match( '~^\s*~', (string) $text, $m ) ? $m[0] : '';
	}

	/**
	 * Trailing white space of a text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function space_after( $text ) {
		return preg_match( '~\s*\z~', (string) $text, $m ) ? $m[0] : '';
	}

	/**
	 * Markup with what kses only normalises taken out (entities, quotes, white
	 * space, slashes, case), as AB_MCP_Builder_Fields compares a field.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function markup_key( $html ) {
		return strtolower( (string) preg_replace( '~[\s"\'/]+~', '', html_entity_decode( (string) $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}
}

AB_MCP_Builder_Writers::register_class( 'AB_MCP_Builder_Writer_Shortcodes' );
