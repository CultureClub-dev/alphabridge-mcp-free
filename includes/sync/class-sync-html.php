<?php
/**
 * The references the save of a core block writes into its HTML (Z8,
 * decision 12; AB_MCP_Sync_Refs::HTML), read with the HTML API of the core:
 * the class token wp-image-<id> exactly as a token of the class attribute,
 * and the attribute data-id exactly by its name — not data-class, not a
 * file name that holds the same letters, with character references
 * decoded as a browser decodes them.
 *
 * WP_HTML_Tag_Processor finds the tags and their attributes; its bookmark
 * gives the span of a tag in the text, so the canonical form can cut it out.
 * A tag with references is written in the canonical form as the core writes
 * it once its attributes are set again with PLACEHOLDER for each id; the
 * rewriter sets the same attributes to the ids of the other side. Both write
 * through set_attribute(), so a rewritten tag reads alike again.
 *
 * Loaded by AB_MCP_Sync_Refs only where a text can hold such a reference.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Sync_Html
 */
final class AB_MCP_Sync_Html extends WP_HTML_Tag_Processor {

	/** A class token that holds an id; the core reads it so (wp-includes/media.php:2009). */
	const CLASS_TOKEN = '/^wp-image-(\d+)\z/i';

	/** The attribute that holds an id. */
	const DATA_ID = 'data-id';

	/** What stands for an id in the canonical form of a tag. */
	const PLACEHOLDER = '~';

	/** The name of the bookmark that gives a tag's span. */
	const MARK = 'ab_mcp_sync_tag';

	/**
	 * The tags of a text that hold references: each with its span, its ids in
	 * the order of the tag (class tokens, then data-id) and the tag as the
	 * canonical form has it.
	 *
	 * @param string   $text  Text.
	 * @param string[] $rules class, data_id.
	 * @return array<int,array{at:int,len:int,ids:array,tag:string}>
	 */
	public static function tags( $text, array $rules ) {
		$text = (string) $text;
		$out  = array();
		$p    = new self( $text );
		while ( $p->next_tag() ) {
			$ids = $p->ids( $rules );
			if ( array() === $ids || ! $p->set_bookmark( self::MARK ) ) {
				continue;
			}
			$span = $p->bookmarks[ self::MARK ];
			$p->release_bookmark( self::MARK );
			$raw   = substr( $text, $span->start, $span->length );
			$out[] = array(
				'at'  => (int) $span->start,
				'len' => (int) $span->length,
				'ids' => $ids,
				'tag' => self::set_ids(
					$raw,
					$rules,
					static function () {
						return self::PLACEHOLDER;
					}
				),
			);
		}
		return $out;
	}

	/**
	 * A text with the ids of its tags replaced.
	 *
	 * @param string   $text    Text.
	 * @param string[] $rules   class, data_id.
	 * @param callable $map     function( int $id ): int|null, the id of the
	 *                          other side; null leaves it.
	 * @param bool     $changed Set when anything changed.
	 * @return string
	 */
	public static function rewrite( $text, array $rules, callable $map, &$changed ) {
		$text = (string) $text;
		$p    = new self( $text );
		$mine = false;
		while ( $p->next_tag() ) {
			if ( array() !== $p->ids( $rules ) && $p->put_ids( $rules, $map ) ) {
				$mine = true;
			}
		}
		if ( ! $mine ) {
			return $text;
		}
		$changed = true;
		return $p->get_updated_html();
	}

	/**
	 * One tag with its ids set to what $id_for gives.
	 *
	 * @param string   $tag    The text of one tag.
	 * @param string[] $rules  class, data_id.
	 * @param callable $id_for function( int $id ): string|int|null.
	 * @return string
	 */
	private static function set_ids( $tag, array $rules, callable $id_for ) {
		$p = new self( $tag );
		if ( ! $p->next_tag() ) {
			return $tag;
		}
		$p->put_ids( $rules, $id_for, true );
		return $p->get_updated_html();
	}

	/**
	 * The ids of the tag the processor stands on.
	 *
	 * @param string[] $rules class, data_id.
	 * @return array<int,array{rule:string,id:int}>
	 */
	private function ids( array $rules ) {
		$out = array();
		if ( in_array( 'class', $rules, true ) ) {
			foreach ( $this->class_list() ?? array() as $token ) {
				if ( preg_match( self::CLASS_TOKEN, (string) $token, $m ) && strlen( ltrim( $m[1], '0' ) ) <= 18 ) {
					$out[] = array(
						'rule' => 'class',
						'id'   => (int) $m[1],
					);
				}
			}
		}
		if ( in_array( 'data_id', $rules, true ) ) {
			$value = $this->get_attribute( self::DATA_ID );
			if ( is_string( $value ) && preg_match( '/^\d{1,18}\z/', $value ) ) {
				$out[] = array(
					'rule' => 'data_id',
					'id'   => (int) $value,
				);
			}
		}
		return $out;
	}

	/**
	 * Set the ids of the tag the processor stands on.
	 *
	 * @param string[] $rules  class, data_id.
	 * @param callable $id_for The new id; null keeps it.
	 * @param bool     $always Write the attributes even where nothing changes.
	 * @return bool Whether an attribute was set.
	 */
	private function put_ids( array $rules, callable $id_for, $always = false ) {
		$set = false;
		if ( in_array( 'class', $rules, true ) ) {
			$class = $this->get_attribute( 'class' );
			if ( is_string( $class ) ) {
				$tokens = preg_split( '/[ \t\n\f\r]+/', $class, -1, PREG_SPLIT_NO_EMPTY );
				$new    = false;
				foreach ( $tokens as $i => $token ) {
					if ( preg_match( self::CLASS_TOKEN, $token, $m ) ) {
						$id = call_user_func( $id_for, (int) $m[1] );
						if ( null !== $id && 'wp-image-' . $id !== $token ) {
							$tokens[ $i ] = 'wp-image-' . $id;
							$new          = true;
						}
					}
				}
				if ( $new || $always ) {
					$this->set_attribute( 'class', implode( ' ', $tokens ) );
					$set = true;
				}
			}
		}
		if ( in_array( 'data_id', $rules, true ) ) {
			$value = $this->get_attribute( self::DATA_ID );
			if ( is_string( $value ) && preg_match( '/^\d{1,18}\z/', $value ) ) {
				$id = call_user_func( $id_for, (int) $value );
				if ( null !== $id && ( (string) $id !== $value || $always ) ) {
					$this->set_attribute( self::DATA_ID, (string) $id );
					$set = true;
				}
			}
		}
		return $set;
	}
}
