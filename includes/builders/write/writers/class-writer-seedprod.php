<?php
/**
 * Writer for SeedProd (storage A2): the site shows the HTML in post_content,
 * which only SeedProd's editor builds (in the browser); the editor loads the
 * JSON in post_content_filtered and writes both on every save (measured,
 * seedprod-pagelayer/README.md d, e). A change to post_content alone shows at
 * once and is gone after the next save in SeedProd (measured: «Mo–Sa» became
 * «Mo–Fr» again).
 *
 * Narrow on purpose (plan review T7): texts and links only, and only where
 * the old value stands in both copies equally often, so each occurrence in
 * the JSON has its counterpart in the HTML. Counted per block, not per page:
 * the HTML of a block runs from the first mention of its id (SeedProd writes
 * it into the block's element: id="sp-<id>", class "…-<id>") to the first
 * mention of the next one, and the old value has to stand there once for
 * each field of that block that changes — a paragraph elsewhere that happens
 * to hold the same words is never taken for it. Both copies change together,
 * in one undo point. Everything else stays readable only, with the reason and
 * the way (SeedProd's editor):
 * - images: the HTML holds the address with sizes and attributes SeedProd's
 *   editor writes, more than one value to keep in step;
 * - a value the HTML shows in another version (the editor has not saved the
 *   page since), or not at all (a page saved only through SeedProd's ability,
 *   whose post_content is empty);
 * - a value that stands more or less often in one copy than in the other;
 * - a block without an id of its own (the free reader then addresses it by
 *   its position, "p…"): its HTML cannot be found.
 * SeedProd's own ability seedprod/save-page writes only the JSON (measured),
 * so it is no way to keep both copies in step.
 *
 * How the copies are changed: in the JSON, the string tokens whose decoded
 * value is the old one, each re-encoded the way it was written (SeedProd's
 * editor writes "https://" and «Ö» as they are, its ability escapes them —
 * both measured); in the HTML, the old value as a whole text node (>…<) or a
 * whole attribute value (="…"), the new one escaped the way a browser
 * serialises it there. Nothing else of either copy changes, byte for byte.
 * Stored straight into the two columns, as SeedProd's own ability stores its
 * JSON [SVN coming-soon@6.20.10 includes/class-seedprod-abilities.php:938-951]:
 * wp_update_post() would run both columns through kses for an account without
 * unfiltered_html — the JSON would break —, and SeedProd's editor itself saves
 * only for accounts that have it [app/lpage.php:619].
 *
 * Element ids are SeedProd's block ids, or "p" + the path where an id is
 * missing or repeated — the same rule as the free reader
 * (AB_MCP_Builder_Adapter_SeedProd::element_id()).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-builder-settings-writer.php';

/**
 * Class AB_MCP_Builder_Writer_SeedProd
 */
class AB_MCP_Builder_Writer_SeedProd extends AB_MCP_Builder_Settings_Writer {

	/**
	 * Whether this way of writing was checked on a real installation: it was.
	 * Built on the plan's measurement of SeedProd Lite 6.20.10 (both copies and
	 * their encodings) and tested against the measured data
	 * (test/builder-writers-meta-test.php), then run against SeedProd Lite
	 * 6.20.10 on WordPress Playground (WordPress 7.1.2, PHP 8.3.33; Pro 4.7.0,
	 * bundled core 4.5.0). On 02.10.2026 a measurement script, in two runs, on
	 * the page of recipe seedprod of test/e2e/builders/, saved first in
	 * SeedProd's editor in a headless browser: heading, HTML text, button with
	 * link, alt text and special characters previewed and applied, the visitor
	 * sees the change at once, wp_search_replace goes through this writer,
	 * every undo leaves both copies byte for byte as before — and after the
	 * next save in SeedProd's editor the visitor still sees the change:
	 * post_content is then byte for byte as written here, and the JSON holds
	 * the new value. On 03.10.2026 roundtrip.mjs --recipe seedprod (it saves
	 * the page in SeedProd's editor before the first visit): 40 of 40. The
	 * warning about an open SeedProd editor stays (open_editor_warning()): it
	 * holds, measured. Not measured: SeedProd Pro, ordinary pages edited with
	 * SeedProd, coming-soon and maintenance pages, templates from SeedProd's
	 * library, an account without unfiltered_html.
	 */
	const MEASURED = true;

	/** Kinds this writer changes. */
	const KINDS = array( 'text', 'heading', 'html', 'url' );

	/** A JSON string token; possessive, so a long string cannot exhaust the backtracking. */
	const JSON_STRING = '~"(?:[^"\\\\]++|\\\\.)*+"~s';

	/**
	 * The free reader's id for a block without a usable id of its own: "p" and
	 * the indices. A SeedProd id never looks like it (no ".", never "p<digits>").
	 */
	const SEEDPROD_PATH = '~^p\d+(?:\.\d+)*$~';

	/** The levels of a SeedProd page, as the free reader walks them. */
	const LEVELS = array( 'sections', 'rows', 'cols', 'blocks' );

	/**
	 * sha256 of each column as write() left it, for the read-back.
	 *
	 * @var array<string,string>|null
	 */
	private $written = null;

	/**
	 * Adapter id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'seedprod';
	}

	/**
	 * See MEASURED.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function verified( $post ): bool {
		return self::MEASURED && parent::verified( $post );
	}

	/**
	 * The JSON copy goes into the undo point even where it is empty.
	 *
	 * @param WP_Post $post Post.
	 * @return string[]
	 */
	public function touched_locs( $post ): array {
		return array( 'post_content_filtered' );
	}

	/**
	 * The warning every SeedProd write carries (see prepare()).
	 *
	 * @return string
	 */
	public static function open_editor_warning() {
		return __( 'SeedProd does not lock a page while its editor is open: a SeedProd editor that is open now will put the old text back the next time it saves. Close or reload the SeedProd editor first.', 'alphabridge-mcp' );
	}

	/**
	 * Both copies have to be there: the HTML the site shows and the JSON the
	 * editor loads.
	 *
	 * @param WP_Post $post Post.
	 * @return true|WP_Error
	 */
	public function can_write( $post ) {
		if ( '' === trim( (string) $post->post_content ) ) {
			return new WP_Error( 'ab_mcp_builder_cannot_write', __( 'This SeedProd page has not been saved in SeedProd\'s editor yet: post_content, which the site shows, is empty, and only that editor builds it. Open the page in SeedProd and save it once; then its texts and links can be changed here.', 'alphabridge-mcp' ) );
		}
		if ( '' === trim( isset( $post->post_content_filtered ) ? (string) $post->post_content_filtered : '' ) ) {
			return new WP_Error( 'ab_mcp_builder_cannot_write', __( 'SeedProd\'s own data of this page (post_content_filtered) is empty, so AlphaBridge cannot keep it in step with what the site shows. Change the page in SeedProd\'s editor.', 'alphabridge-mcp' ) );
		}
		return true;
	}

	/**
	 * The settings of a block, from the JSON.
	 *
	 * @param WP_Post $post    Post.
	 * @param array   $element Element.
	 * @return array|WP_Error
	 */
	protected function settings_of( $post, array $element ) {
		$block = self::block( json_decode( (string) $post->post_content_filtered, true ), (string) $element['id'] );
		if ( null === $block || ! isset( $block['settings'] ) || ! is_array( $block['settings'] ) ) {
			return self::gone( (string) $element['id'], 'settings' );
		}
		return $block['settings'];
	}

	/**
	 * Matches in the strings of the JSON, decoded.
	 *
	 * @param WP_Post $post   Post.
	 * @param string  $search Text.
	 * @param bool    $ci     Case-insensitive.
	 * @return int
	 */
	protected function count_stored( $post, $search, $ci = false ) {
		$n = 0;
		foreach ( (array) self::json_strings( (string) $post->post_content_filtered ) as $token ) {
			$n += self::count_in( $token['value'], (string) $search, $ci );
		}
		return $n;
	}

	/**
	 * Texts and links only, each occurring equally often in both copies.
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $change Change.
	 * @param array   $opts   Options.
	 * @return array|WP_Error
	 */
	protected function prepare( $post, array $change, array $opts ) {
		foreach ( $change['changes'] as $c ) {
			if ( 1 === preg_match( self::SEEDPROD_PATH, (string) $c['element'] ) ) {
				return new WP_Error(
					'ab_mcp_builder_not_writable',
					/* translators: %s: element id */
					sprintf( __( 'Block %s has no id of its own on this page (or shares it with another block), so AlphaBridge cannot find its place in the HTML the site shows. Change it in SeedProd\'s editor.', 'alphabridge-mcp' ), (string) $c['element'] )
				);
			}
			if ( ! in_array( $c['kind'], self::KINDS, true ) ) {
				return new WP_Error(
					'ab_mcp_builder_not_writable',
					/* translators: 1: element id, 2: field name */
					sprintf( __( 'AlphaBridge changes texts and links on SeedProd pages, not images (%1$s:%2$s): SeedProd keeps an image twice, as an address in its data and inside the page\'s HTML with the sizes and attributes its editor writes. Change the image in SeedProd\'s editor.', 'alphabridge-mcp' ), $c['element'], $c['field'] )
				);
			}
		}
		$notes = isset( $change['notes'] ) ? (array) $change['notes'] : array();
		$left  = isset( $change['left'] ) ? (array) $change['left'] : array();
		$keep  = array();
		foreach ( self::groups( $change['changes'] ) as $group ) {
			$hits = self::hits( $post, $group );
			if ( is_wp_error( $hits ) ) {
				if ( 'element' === $change['mode'] ) {
					return $hits;
				}
				$left[] = $hits->get_error_message();
				continue;
			}
			foreach ( $group['changes'] as $c ) {
				$keep[] = $c;
			}
		}
		$change['changes'] = $keep;
		if ( array() !== $keep ) {
			$notes[] = __( 'SeedProd keeps this page twice — the HTML the site shows (post_content) and the data its editor loads (post_content_filtered) —; both are changed together, with one undo point.', 'alphabridge-mcp' );
			// SeedProd's editor sets no _edit_lock while it is open (measured
			// with SeedProd Lite 6.20.10: the heartbeat runs, no post lock), so
			// the lock step cannot see it, and its next save writes both copies
			// as it holds them — the change is gone without a word (measured).
			// Every answer that writes, or would write, says so.
			$change['warnings']   = isset( $change['warnings'] ) ? (array) $change['warnings'] : array();
			$change['warnings'][] = self::open_editor_warning();
		}
		$change['notes'] = $notes;
		$change['left']  = $left;
		return $change;
	}

	/**
	 * Step 5: both columns, in one statement.
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $change Change.
	 * @param array   $opts   Options.
	 * @return true|WP_Error
	 */
	protected function write( $post, array $change, array $opts ) {
		global $wpdb;
		$this->written = null;
		$json          = (string) $post->post_content_filtered;
		$html          = (string) $post->post_content;
		$edits         = array(
			'json' => array(),
			'html' => array(),
		);
		foreach ( self::groups( $change['changes'] ) as $group ) {
			$hits = self::hits( $post, $group );
			if ( is_wp_error( $hits ) ) {
				return $hits;
			}
			foreach ( array( 'json', 'html' ) as $copy ) {
				foreach ( $hits[ $copy ] as $hit ) {
					$edits[ $copy ][] = $hit;
				}
			}
		}
		$new_json = self::apply( $json, $edits['json'] );
		$new_html = self::apply( $html, $edits['html'] );
		if ( null === $new_json || null === $new_html ) {
			return new WP_Error( 'ab_mcp_builder_write_failed', __( 'Two changes of this call touch the same place of the page; nothing was changed. Change the fields one at a time.', 'alphabridge-mcp' ) );
		}
		if ( ! is_array( json_decode( $new_json, true ) ) ) {
			return new WP_Error( 'ab_mcp_builder_write_failed', __( 'The changed SeedProd data would not be valid JSON; nothing was changed. Change it in SeedProd\'s editor.', 'alphabridge-mcp' ) );
		}
		$done = $wpdb->update(
			$wpdb->posts,
			array(
				'post_content'          => $new_html,
				'post_content_filtered' => $new_json,
			),
			array( 'ID' => (int) $post->ID )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- see the file comment: kses would break the JSON; SeedProd's own ability writes it the same way.
		if ( false === $done ) {
			return new WP_Error( 'ab_mcp_builder_write_failed', __( 'The database did not take the changed SeedProd page. Try again; if it keeps failing, change it in SeedProd\'s editor.', 'alphabridge-mcp' ) );
		}
		clean_post_cache( (int) $post->ID );
		$this->written = array(
			'post_content'          => hash( 'sha256', $new_html ),
			'post_content_filtered' => hash( 'sha256', $new_json ),
		);
		return true;
	}

	/**
	 * Step 6: the JSON through the outline (as the base does), and both
	 * columns byte for byte as write() left them.
	 *
	 * @param WP_Post $post       Post.
	 * @param array   $change     Change.
	 * @param array   $ids_before Element ids before.
	 * @return true|WP_Error
	 */
	protected function readback( $post, array $change, array $ids_before ) {
		$ok = parent::readback( $post, $change, $ids_before );
		if ( true !== $ok ) {
			return $ok;
		}
		foreach ( (array) $this->written as $loc => $sha ) {
			$state = AB_MCP_Undo_Builder::read_loc( (int) $post->ID, $loc );
			if ( ! hash_equals( $sha, hash( 'sha256', isset( $state['value'] ) ? (string) $state['value'] : '' ) ) ) {
				/* translators: %s: column */
				return new WP_Error( 'ab_mcp_builder_readback_fields', sprintf( __( 'After the save %s did not hold what was written.', 'alphabridge-mcp' ), $loc ) );
			}
		}
		return null === $this->written ? new WP_Error( 'ab_mcp_builder_readback', __( 'Nothing was written to read back.', 'alphabridge-mcp' ) ) : true;
	}

	/* ------------------------------------------------------------ private */

	/**
	 * Changes grouped by their old and new value: fields that hold the same
	 * text and get the same new one change together (replace() over several
	 * buttons with the same label).
	 *
	 * @param array $changes Changes.
	 * @return array<int,array{before:string,after:string,kinds:string[],changes:array}>
	 */
	private static function groups( array $changes ) {
		$out = array();
		foreach ( $changes as $c ) {
			$key = (string) $c['before'] . "\0" . (string) $c['after'];
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array(
					'before'  => (string) $c['before'],
					'after'   => (string) $c['after'],
					'kinds'   => array(),
					'changes' => array(),
				);
			}
			$out[ $key ]['kinds'][]   = (string) $c['kind'];
			$out[ $key ]['changes'][] = $c;
		}
		return array_values( $out );
	}

	/**
	 * Where a group's old value stands in each copy, with what replaces it
	 * there; a WP_Error when the copies do not hold it once per field.
	 *
	 * @param WP_Post $post  Post.
	 * @param array   $group Group of groups().
	 * @return array|WP_Error { json: edits, html: edits }
	 */
	private static function hits( $post, array $group ) {
		$fields = array();
		foreach ( $group['changes'] as $c ) {
			$fields[] = $c['element'] . ':' . $c['field'];
		}
		$fields = implode( ', ', $fields );
		$want   = count( $group['changes'] );
		if ( '' === $group['before'] ) {
			return new WP_Error(
				'ab_mcp_builder_not_writable',
				/* translators: %s: element:field list */
				sprintf( __( 'An empty field of a SeedProd page (%s) cannot be filled here: its HTML has no place for it until SeedProd\'s editor builds one. Fill it in SeedProd\'s editor.', 'alphabridge-mcp' ), $fields )
			);
		}
		$json = self::json_hits( (string) $post->post_content_filtered, $group['before'], $group['after'] );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		// Each field's own place in the HTML: the hits inside the block's part.
		$per = array();
		foreach ( $group['changes'] as $c ) {
			$per[ (string) $c['element'] ] = ( isset( $per[ (string) $c['element'] ] ) ? $per[ (string) $c['element'] ] : 0 ) + 1;
		}
		$content = (string) $post->post_content;
		$parts   = self::parts( $content, self::node_ids( json_decode( (string) $post->post_content_filtered, true ) ) );
		$html    = array();
		$found   = array_fill_keys( array_keys( $per ), 0 );
		foreach ( self::html_hits( $content, $group['before'], $group['after'], $group['kinds'] ) as $hit ) {
			foreach ( array_keys( $per ) as $block ) {
				if ( isset( $parts[ $block ] ) && $hit[0] >= $parts[ $block ][0] && $hit[0] < $parts[ $block ][1] ) {
					++$found[ $block ];
					$html[] = $hit;
				}
			}
		}
		if ( count( $json ) === $want && $found === $per ) {
			return array(
				'json' => $json,
				'html' => $html,
			);
		}
		if ( 0 === count( $html ) ) {
			return new WP_Error(
				'ab_mcp_builder_copies_differ',
				sprintf(
					/* translators: %s: element:field list */
					__( 'The HTML the site shows does not hold %s as SeedProd\'s data has it (SeedProd\'s editor has not saved the page since this field last changed, or shows it in another form). Changing only one copy would be undone by the next save in SeedProd, so nothing was changed. Open and save the page in SeedProd\'s editor, then try again — or change it there.', 'alphabridge-mcp' ),
					$fields
				)
			);
		}
		return new WP_Error(
			'ab_mcp_builder_copies_differ',
			sprintf(
				/* translators: 1: element:field list, 2: count in the data, 3: count in the HTML of these blocks, 4: fields asked */
				__( 'The text of %1$s stands %2$d time(s) in SeedProd\'s data and %3$d time(s) in the HTML of these blocks, for %4$d field(s) to change; AlphaBridge cannot tell which place belongs to which, so nothing was changed. Change it in SeedProd\'s editor.', 'alphabridge-mcp' ),
				$fields,
				count( $json ),
				count( $html ),
				$want
			)
		);
	}

	/**
	 * The JSON string tokens whose value is $before, each with its
	 * replacement encoded the way the token was.
	 *
	 * @param string $json   JSON.
	 * @param string $before Old value.
	 * @param string $after  New value.
	 * @return array|WP_Error Edits [ offset, length, text ].
	 */
	private static function json_hits( $json, $before, $after ) {
		$tokens = self::json_strings( $json );
		if ( null === $tokens ) {
			return new WP_Error( 'ab_mcp_builder_not_writable', __( 'SeedProd\'s data of this page could not be read token by token; nothing was changed. Change it in SeedProd\'s editor.', 'alphabridge-mcp' ) );
		}
		$out = array();
		foreach ( $tokens as $token ) {
			if ( $token['value'] !== $before ) {
				continue;
			}
			$flags = self::json_flags( $before, $token['raw'] );
			if ( null === $flags ) {
				return new WP_Error( 'ab_mcp_builder_not_writable', __( 'SeedProd\'s data writes this text in a form AlphaBridge does not reproduce byte for byte; nothing was changed. Change it in SeedProd\'s editor.', 'alphabridge-mcp' ) );
			}
			$out[] = array( $token['offset'], strlen( $token['raw'] ), (string) wp_json_encode( $after, $flags ) );
		}
		return $out;
	}

	/**
	 * Every string token of a JSON text with its offset and decoded value;
	 * null when the text is no JSON this can walk.
	 *
	 * @param string $json JSON.
	 * @return array|null
	 */
	private static function json_strings( $json ) {
		if ( '' === trim( $json ) || null === json_decode( $json ) ) {
			return null;
		}
		$found = preg_match_all( self::JSON_STRING, $json, $m, PREG_OFFSET_CAPTURE );
		if ( false === $found ) {
			return null;
		}
		$out = array();
		foreach ( $m[0] as $hit ) {
			$value = json_decode( $hit[0] );
			if ( is_string( $value ) ) {
				$out[] = array(
					'offset' => (int) $hit[1],
					'raw'    => (string) $hit[0],
					'value'  => $value,
				);
			}
		}
		return $out;
	}

	/**
	 * The json_encode() flags that write $value exactly as $token: SeedProd's
	 * editor (JSON.stringify) leaves slashes and non-ASCII letters as they are,
	 * wp_json_encode() escapes both; null for any other form.
	 *
	 * @param string $value Value.
	 * @param string $token Token as stored, with quotes.
	 * @return int|null
	 */
	private static function json_flags( $value, $token ) {
		foreach ( array( JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES, 0 ) as $flags ) {
			if ( wp_json_encode( $value, $flags ) === $token ) {
				return $flags;
			}
		}
		return null;
	}

	/**
	 * Where the HTML shows $before as a whole text node or a whole attribute
	 * value, with the new value written the way a browser writes it there.
	 *
	 * @param string   $html   HTML.
	 * @param string   $before Old value.
	 * @param string   $after  New value.
	 * @param string[] $kinds  Kinds of the fields.
	 * @return array Edits [ offset, length, text ].
	 */
	private static function html_hits( $html, $before, $after, array $kinds ) {
		$plain  = (bool) array_intersect( $kinds, array( 'text', 'heading' ) );
		$markup = in_array( 'html', $kinds, true );
		$link   = in_array( 'url', $kinds, true );
		$places = array();
		if ( $plain || $markup ) {
			// Between two tags. A field that holds markup stands there as it is.
			$places[] = array( '>', '<', 'text', $markup ? array( $before ) : array( $before, self::esc_text( $before ) ) );
		}
		if ( $plain || $link ) {
			// A whole attribute value: alt text, href.
			$places[] = array( '="', '"', 'attr', array( $before, self::esc_attr_value( $before ) ) );
		}
		$out  = array();
		$seen = array();
		foreach ( $places as $place ) {
			list( $open, $close, $where, $forms ) = $place;
			foreach ( array_unique( $forms ) as $form ) {
				$needle = $open . $form . $close;
				$at     = 0;
				while ( false !== ( $pos = strpos( $html, $needle, $at ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
					$start = $pos + strlen( $open );
					if ( ! isset( $seen[ $start ] ) ) {
						$seen[ $start ] = true;
						$out[]          = array( $start, strlen( $form ), self::html_value( $after, $before, $form, $where, $markup ) );
					}
					$at = $pos + strlen( $needle );
				}
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $a[0] <=> $b[0];
			}
		);
		return $out;
	}

	/**
	 * The new value as it goes into the HTML. In an attribute always escaped
	 * the way a browser serialises it: a quote in the new value would end the
	 * attribute and open new ones (an event handler), and this write passes
	 * kses by — so how the old value happened to stand there never decides.
	 * Between tags: markup as it is (it passed the field rules); text escaped
	 * — unless the old value stood there unescaped although it has characters
	 * to escape (the field is shown as HTML), then as it is too.
	 *
	 * @param string $after  New value.
	 * @param string $before Old value.
	 * @param string $form   The form the old value was found in.
	 * @param string $where  'text' or 'attr'.
	 * @param bool   $markup Whether the field holds markup.
	 * @return string
	 */
	private static function html_value( $after, $before, $form, $where, $markup ) {
		if ( 'attr' === $where ) {
			return self::esc_attr_value( $after );
		}
		if ( $markup ) {
			return $after;
		}
		if ( $form === $before && self::esc_text( $before ) !== $before ) {
			return $after;
		}
		return self::esc_text( $after );
	}

	/**
	 * A text with edits applied; null when two edits overlap.
	 *
	 * @param string $text  Text.
	 * @param array  $edits [ offset, length, replacement ].
	 * @return string|null
	 */
	private static function apply( $text, array $edits ) {
		usort(
			$edits,
			static function ( $a, $b ) {
				return $a[0] <=> $b[0];
			}
		);
		$end = -1;
		foreach ( $edits as $edit ) {
			if ( $edit[0] < $end ) {
				return null;
			}
			$end = $edit[0] + $edit[1];
		}
		foreach ( array_reverse( $edits ) as $edit ) {
			$text = substr_replace( $text, $edit[2], $edit[0], $edit[1] );
		}
		return $text;
	}

	/**
	 * The part of the HTML each node id stands for: from the first mention of
	 * the id (a whole word: "sp-blk001", ".sp-text-wrapper-blk002" count,
	 * "blk0012" does not) to the first mention of the next one. An id the HTML
	 * does not mention has no part.
	 *
	 * @param string   $html HTML.
	 * @param string[] $ids  Node ids of the page.
	 * @return array<string,array{0:int,1:int}>
	 */
	private static function parts( $html, array $ids ) {
		$at = array();
		foreach ( array_unique( $ids ) as $id ) {
			if ( '' !== $id && 1 === preg_match( '~(?<![A-Za-z0-9_])' . preg_quote( $id, '~' ) . '(?![A-Za-z0-9_-])~', $html, $m, PREG_OFFSET_CAPTURE ) ) {
				$at[ $id ] = (int) $m[0][1];
			}
		}
		asort( $at );
		$order = array_keys( $at );
		$out   = array();
		foreach ( $order as $i => $id ) {
			$out[ (string) $id ] = array( $at[ $id ], isset( $order[ $i + 1 ] ) ? $at[ $order[ $i + 1 ] ] : strlen( $html ) );
		}
		return $out;
	}

	/**
	 * Every usable, unique node id of the page, at every level.
	 *
	 * @param mixed $json Decoded JSON.
	 * @return string[]
	 */
	private static function node_ids( $json ) {
		$sections = is_array( $json ) ? self::value_at( $json, 'document.sections' ) : null;
		if ( ! is_array( $sections ) ) {
			return array();
		}
		$counts = array();
		self::count_ids( $sections, 0, $counts, 0 );
		$out = array();
		foreach ( $counts as $id => $n ) {
			if ( 1 === $n && self::usable( (string) $id ) ) {
				$out[] = (string) $id;
			}
		}
		return $out;
	}

	/**
	 * Whether a SeedProd id can stand for its node (the free reader's rule).
	 *
	 * @param string $id Id.
	 * @return bool
	 */
	private static function usable( $id ) {
		return 1 === preg_match( '~^[A-Za-z0-9_:-]{1,64}$~', $id ) && 1 !== preg_match( '~^p\d+$~', $id );
	}

	/**
	 * The block with an element id, by the free reader's rule: SeedProd's id
	 * where it is usable and unique on the page, else "p" + the indices.
	 *
	 * @param mixed  $json Decoded JSON.
	 * @param string $id   Element id.
	 * @return array|null
	 */
	private static function block( $json, $id ) {
		$sections = is_array( $json ) ? self::value_at( $json, 'document.sections' ) : null;
		if ( ! is_array( $sections ) ) {
			return null;
		}
		$counts = array();
		self::count_ids( $sections, 0, $counts, 0 );
		return self::find( $sections, 0, array(), (string) $id, $counts );
	}

	/**
	 * Find a block below one level.
	 *
	 * @param array  $nodes  Nodes of the level.
	 * @param int    $level  Index in LEVELS.
	 * @param int[]  $path   Indices of the parent.
	 * @param string $id     Element id.
	 * @param array  $counts Occurrences per id.
	 * @return array|null
	 */
	private static function find( array $nodes, $level, array $path, $id, array $counts ) {
		foreach ( array_values( $nodes ) as $i => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$p   = array_merge( $path, array( $i ) );
			$own = isset( $node['id'] ) && is_scalar( $node['id'] ) ? (string) $node['id'] : '';
			$eid = ( self::usable( $own ) && 1 === ( isset( $counts[ $own ] ) ? $counts[ $own ] : 0 ) ) ? $own : 'p' . implode( '.', $p );
			if ( 3 === $level ) {
				if ( $eid === $id ) {
					return $node;
				}
				continue;
			}
			$next = self::LEVELS[ $level + 1 ];
			if ( isset( $node[ $next ] ) && is_array( $node[ $next ] ) ) {
				$hit = self::find( $node[ $next ], $level + 1, $p, $id, $counts );
				if ( null !== $hit ) {
					return $hit;
				}
			}
		}
		return null;
	}

	/**
	 * Count every id on the page (a copied block may keep its id).
	 *
	 * @param array $nodes  Nodes of a level.
	 * @param int   $level  Index in LEVELS.
	 * @param array $counts Counts (by reference).
	 * @param int   $depth  Depth.
	 */
	private static function count_ids( array $nodes, $level, array &$counts, $depth ) {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( isset( $node['id'] ) && is_scalar( $node['id'] ) ) {
				$counts[ (string) $node['id'] ] = ( isset( $counts[ (string) $node['id'] ] ) ? $counts[ (string) $node['id'] ] : 0 ) + 1;
			}
			$next = isset( self::LEVELS[ $level + 1 ] ) ? self::LEVELS[ $level + 1 ] : null;
			if ( null !== $next && $depth < AB_MCP_Builder_Element_Profile::MAX_DEPTH && isset( $node[ $next ] ) && is_array( $node[ $next ] ) ) {
				self::count_ids( $node[ $next ], $level + 1, $counts, $depth + 1 );
			}
		}
	}
}

AB_MCP_Builder_Writers::register_class( 'AB_MCP_Builder_Writer_SeedProd' );
