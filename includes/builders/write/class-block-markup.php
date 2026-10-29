<?php
/**
 * Changes to the saved markup of one block, made through WordPress' HTML API.
 *
 * The block writer finds the element a profile field names with the very
 * code the free core reads it with (AB_MCP_Builder_Html::find(): byte offsets
 * into the block's own markup), so reading and writing can never disagree
 * about which element is meant. This class then makes the change at those
 * offsets with WordPress' tag processor, which parses the markup the way a
 * browser does and escapes attribute values. What the tag processor lacks,
 * this subclass adds:
 * - landing on the start tag at a known offset, and on the end tag that
 *   closes it — two bookmarks — and replacing what lies between them: the
 *   HTML API has no setter for inner HTML (T4 of the plan review);
 * - writing a text node in the form the block editor writes text: & < > as
 *   character references, quotes as they are. set_modifiable_text() writes
 *   " and ' as &quot; and &apos; (since WordPress 6.9); a later search for a
 *   quoted text in the form the editor stores it would no longer find what
 *   this writer wrote;
 * - the pending changes as byte ranges of the markup it was given, so the
 *   writer can put them into the pieces a block's markup is split into
 *   around its inner blocks.
 *
 * Every member it adds carries the prefix ab_ (AB_ for constants): a
 * method WordPress adds to its tag processor later cannot collide with one
 * of these.
 *
 * Loaded only by the block writer, once WP_HTML_Tag_Processor exists.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Block_Markup
 */
final class AB_MCP_Block_Markup extends WP_HTML_Tag_Processor {

	/** Bookmark on the start tag of the element being changed. */
	const AB_OPEN = 'ab_open';

	/** Bookmark on the end tag that closes it. */
	const AB_CLOSE = 'ab_close';

	/** Bookmark used to learn where the current token starts. */
	const AB_PROBE = 'ab_probe';

	/**
	 * Elements whose content is code, styling or vector markup rather than
	 * text: replacing a span that holds one would remove it (an icon, a style
	 * sheet, a script), and their inside is never searched.
	 */
	const AB_HIDDEN = array( 'SCRIPT', 'STYLE', 'SVG', 'MATH', 'TEMPLATE', 'NOSCRIPT', 'IFRAME', 'OBJECT', 'EMBED', 'TEXTAREA', 'TITLE' );

	/**
	 * The markup as given, to tell whether the processor has applied pending
	 * changes to itself (then the byte ranges no longer fit it).
	 *
	 * @var string
	 */
	private $ab_given;

	/**
	 * Constructor.
	 *
	 * @param string $html Markup.
	 */
	public function __construct( $html ) {
		parent::__construct( $html );
		$this->ab_given = (string) $html;
	}

	/**
	 * Whether this WordPress has what the writer needs: text nodes that can
	 * be set (set_modifiable_text(), WordPress 6.7).
	 *
	 * @return bool
	 */
	public static function ab_supported(): bool {
		return method_exists( 'WP_HTML_Tag_Processor', 'set_modifiable_text' ) && method_exists( 'WP_HTML_Tag_Processor', 'get_modifiable_text' );
	}

	/**
	 * Plain text as HTML text, the way the block editor writes it.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function ab_text_html( $text ): string {
		return htmlspecialchars( (string) $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8', true );
	}

	/**
	 * Byte offset where the current token starts, or null.
	 *
	 * @return int|null
	 */
	public function ab_token_start() {
		if ( ! $this->set_bookmark( self::AB_PROBE ) ) {
			return null;
		}
		return (int) $this->bookmarks[ self::AB_PROBE ]->start;
	}

	/**
	 * Move to the start tag that begins at $offset and bookmark it.
	 *
	 * @param int $offset Byte offset of the start tag.
	 * @return bool False when no start tag begins there.
	 */
	public function ab_seek_start_tag( $offset ): bool {
		$offset = (int) $offset;
		while ( $this->next_tag() ) {
			$at = $this->ab_token_start();
			if ( null === $at || $at > $offset ) {
				return false;
			}
			if ( $at === $offset ) {
				return $this->set_bookmark( self::AB_OPEN );
			}
		}
		return false;
	}

	/**
	 * From the start tag (ab_seek_start_tag()), read on to the end tag that
	 * begins at $offset and bookmark it, noting what lies between.
	 *
	 * @param int $offset Byte offset of the end tag.
	 * @return array|null { text_nodes: int, markup: bool, hidden: string[] },
	 *                    null when no end tag of the element begins there.
	 */
	public function ab_read_to_end_tag( $offset ) {
		$offset = (int) $offset;
		$tag    = $this->get_tag();
		$info   = array(
			'text_nodes' => 0,
			'markup'     => false,
			'hidden'     => array(),
		);
		if ( null === $tag ) {
			return null;
		}
		while ( $this->next_token() ) {
			$at = $this->ab_token_start();
			if ( null === $at || $at > $offset ) {
				return null;
			}
			$type = $this->get_token_type();
			if ( $at === $offset ) {
				if ( '#tag' === $type && $this->is_tag_closer() && $tag === $this->get_tag() && $this->set_bookmark( self::AB_CLOSE ) ) {
					return $info;
				}
				return null;
			}
			if ( '#text' === $type ) {
				++$info['text_nodes'];
				continue;
			}
			$info['markup'] = true;
			if ( '#tag' === $type && ! $this->is_tag_closer() && in_array( $this->get_tag(), self::AB_HIDDEN, true ) ) {
				$info['hidden'][] = strtolower( (string) $this->get_tag() );
			}
		}
		return null;
	}

	/**
	 * Set the current text node to plain text, written as the block editor
	 * writes text (see the class comment).
	 *
	 * @param string $text Plain text.
	 * @return bool
	 */
	public function ab_set_text_node( $text ): bool {
		if ( '#text' !== $this->get_token_type() || ! $this->set_modifiable_text( (string) $text ) || ! isset( $this->lexical_updates['modifiable text'] ) ) {
			return false;
		}
		$this->lexical_updates['modifiable text']->text = self::ab_text_html( (string) $text );
		return true;
	}

	/**
	 * Replace everything between the bookmarked start and end tag.
	 *
	 * @param string $html New inner HTML.
	 * @return bool
	 */
	public function ab_replace_inner( $html ): bool {
		if ( ! isset( $this->bookmarks[ self::AB_OPEN ], $this->bookmarks[ self::AB_CLOSE ] ) ) {
			return false;
		}
		$open = $this->bookmarks[ self::AB_OPEN ];
		$from = $open->start + $open->length;
		$to   = $this->bookmarks[ self::AB_CLOSE ]->start;
		if ( $to < $from ) {
			return false;
		}
		$this->lexical_updates[] = new WP_HTML_Text_Replacement( $from, $to - $from, (string) $html );
		return true;
	}

	/**
	 * The pending changes as byte ranges of the markup as given, in order:
	 * list of [ start, length, text ]. Null when the processor has already
	 * applied changes to itself, or two of them overlap.
	 *
	 * @return array|null
	 */
	public function ab_updates() {
		if ( $this->html !== $this->ab_given ) {
			return null;
		}
		$out = array();
		foreach ( $this->lexical_updates as $update ) {
			$out[] = array( (int) $update->start, (int) $update->length, (string) $update->text );
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
			}
		);
		$end = -1;
		foreach ( $out as $u ) {
			if ( $u[0] < $end ) {
				return null;
			}
			$end = $u[0] + $u[1];
		}
		return $out;
	}
}
