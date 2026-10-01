<?php
/**
 * Adapter for SeedProd (storage A2): the site shows the HTML in
 * post_content, which only SeedProd's editor (in the browser) writes; the
 * editor itself loads the JSON in post_content_filtered and writes both on
 * every save (measured). A change made only to post_content shows at once
 * and is gone after the next save in SeedProd (measured).
 *
 * The outline reads the JSON — the page as SeedProd keeps it:
 * document.sections[].rows[].cols[].blocks[], each node with id, type and
 * settings. Which block settings are visible content is data:
 * profiles/elements-seedprod.php. Where the HTML in post_content shows a
 * field differently, the element's note says so; where post_content is
 * empty (a page saved only through SeedProd's ability, never in its editor),
 * the site does not show the blocks yet (measured), and the sections say so.
 *
 * Element ids are SeedProd's ids. A missing, odd or repeated id falls back
 * to the path "p" + indices of section, row, column and block joined by "."
 * ("p0.0.0.2"), valid together with layout_hash.
 *
 * Page scripts (header_scripts, body_scripts, footer_scripts …) and CSS
 * (document.settings.headCss, customCss …) live outside the blocks and are
 * never read.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Builder_Adapter_SeedProd
 */
class AB_MCP_Builder_Adapter_SeedProd extends AB_MCP_Builder_Adapter {

	/** Note on a section of a page whose post_content is empty. */
	const NOTE_NOT_SHOWN = 'post_content is empty: the site does not show these blocks until the page is saved in the SeedProd editor';

	/** Levels below a section, with the type each node of a level has. */
	const LEVELS = array(
		'rows'   => 'row',
		'cols'   => 'col',
		'blocks' => '',
	);

	/**
	 * Adapter id, the builder id of signatures.php.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'seedprod';
	}

	/**
	 * Measured at SeedProd Lite 6.20.10 (see the profile).
	 *
	 * @return bool
	 */
	public function verified(): bool {
		return true;
	}

	/**
	 * Sections, rows, columns and blocks in page order.
	 *
	 * @param WP_Post $post Post.
	 * @param array   $o    Options.
	 * @return array<int,array>
	 */
	public function outline( $post, array $o ): array {
		$o    = AB_MCP_Builder_Element_Profile::options( $o );
		$raw  = isset( $post->post_content_filtered ) ? (string) $post->post_content_filtered : '';
		$json = json_decode( $raw, true );
		if ( '' !== trim( $raw ) && ! is_array( $json ) ) {
			return self::unreadable_outline( 'post_content_filtered', 'post_content_filtered', 'post_content_filtered is not readable SeedProd data, so its blocks cannot be listed; the site shows post_content', $o );
		}
		$secs = is_array( $json ) ? AB_MCP_Builder_Element_Profile::value_at( $json, 'document.sections' ) : null;
		if ( ! is_array( $secs ) ) {
			return array();
		}
		$counts = array();
		$this->count_ids( $secs, 'sections', $counts, 0 );

		$shown = trim( (string) $post->post_content );
		$ctx   = array(
			'profile' => AB_MCP_Builder_Element_Profile::get( 'seedprod' ),
			'counts'  => $counts,
			'shown'   => $shown,
			'visible' => '' === $shown ? '' : AB_MCP_Builders::field_text( $shown ),
			'o'       => $o,
		);
		$out = array();
		$this->walk( $secs, 'sections', array(), null, 0, $ctx, $out );
		return $out;
	}

	/**
	 * Add the nodes of one level and what is below them.
	 *
	 * @param array       $nodes  Nodes of this level.
	 * @param string      $level  'sections', 'rows', 'cols' or 'blocks'.
	 * @param int[]       $path   Indices of the parent.
	 * @param string|null $parent Parent element id.
	 * @param int         $depth  Depth.
	 * @param array       $ctx    Profile, id counts, post_content, options.
	 * @param array       $out    Elements (by reference).
	 * @return bool False once max_elements is reached.
	 */
	private function walk( array $nodes, $level, array $path, $parent, $depth, array $ctx, array &$out ) {
		$o    = $ctx['o'];
		$next = $this->next_level( $level );
		foreach ( array_values( $nodes ) as $i => $node ) {
			if ( count( $out ) >= $o['max_elements'] ) {
				return false;
			}
			if ( ! is_array( $node ) ) {
				continue;
			}
			$p    = array_merge( $path, array( $i ) );
			$id   = $this->element_id( $node, $p, $ctx['counts'] );
			$type = 'blocks' === $level
				? ( isset( $node['type'] ) && is_string( $node['type'] ) && '' !== $node['type'] ? $node['type'] : 'unknown' )
				: ( 'sections' === $level ? 'section' : self::LEVELS[ $level ] );
			$base = array(
				'id'     => $id,
				'type'   => $type,
				'parent' => $parent,
				'depth'  => $depth,
			);

			if ( 'blocks' === $level ) {
				$spec     = isset( $ctx['profile']['elements'][ $type ] ) ? $ctx['profile']['elements'][ $type ] : null;
				$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
				$read     = AB_MCP_Builder_Element_Profile::element( $base, $settings, $spec, $ctx['profile'], $o );
				if ( null !== $read['element'] ) {
					$notes = $this->copy_notes( $read['values'], $ctx );
					if ( array() !== $notes ) {
						$read['element']['note'] = implode( '; ', array_merge( isset( $read['element']['note'] ) ? array( $read['element']['note'] ) : array(), $notes ) );
					}
					$out[] = $read['element'];
				}
				continue; // Blocks hold no further levels.
			}

			if ( 'sections' === $level && '' === $ctx['shown'] ) {
				$base['note'] = self::NOTE_NOT_SHOWN;
			}
			$out[] = $base;
			$below = null !== $next && isset( $node[ $next ] ) && is_array( $node[ $next ] ) ? $node[ $next ] : array();
			if ( ! $this->walk( $below, $next, $p, $id, $depth + 1, $ctx, $out ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Fields whose value the HTML in post_content does not show: the site
	 * shows another version than the one SeedProd's editor will save next.
	 *
	 * @param array $values Field name => kind, value, raw.
	 * @param array $ctx    Context.
	 * @return string[]
	 */
	private function copy_notes( array $values, array $ctx ) {
		if ( '' === $ctx['shown'] ) {
			return array();
		}
		$notes = array();
		foreach ( $values as $name => $field ) {
			if ( in_array( $field['kind'], array( 'text', 'heading', 'html' ), true ) ) {
				// Visible text, or an attribute value such as the image's alt text.
				$text = 'html' === $field['kind'] ? AB_MCP_Builders::field_text( (string) $field['value'] ) : (string) $field['value'];
				$seen = '' === $text || false !== strpos( $ctx['visible'], $text ) || $this->in_markup( $text, $ctx['shown'] );
			} else {
				$raw  = trim( (string) $field['raw'] );
				$seen = '' === $raw || $this->in_markup( $raw, $ctx['shown'] );
			}
			if ( ! $seen ) {
				$notes[] = $name . ': post_content shows another version';
			}
		}
		return $notes;
	}

	/**
	 * Whether a value stands in the markup as it is or HTML-escaped (an
	 * attribute value).
	 *
	 * @param string $value  Value.
	 * @param string $markup Markup.
	 * @return bool
	 */
	private function in_markup( $value, $markup ) {
		return false !== strpos( $markup, $value ) || false !== strpos( $markup, htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * The element id: SeedProd's where it is usable and unique on the page,
	 * else the path.
	 *
	 * @param array $node   Node.
	 * @param int[] $path   Indices.
	 * @param array $counts Occurrences per id.
	 * @return string
	 */
	private function element_id( array $node, array $path, array $counts ) {
		$id = isset( $node['id'] ) && is_scalar( $node['id'] ) ? (string) $node['id'] : '';
		if ( $this->usable( $id ) && 1 === ( isset( $counts[ $id ] ) ? $counts[ $id ] : 0 ) ) {
			return $id;
		}
		return 'p' . implode( '.', $path );
	}

	/**
	 * Whether an id can stand for its node: short, plain, and not shaped
	 * like a path id.
	 *
	 * @param string $id Candidate.
	 * @return bool
	 */
	private function usable( $id ) {
		return 1 === preg_match( '~^[A-Za-z0-9_:-]{1,64}$~', $id ) && 1 !== preg_match( '~^p\d+$~', $id );
	}

	/**
	 * Count every id on the page, so a repeated one (a copied block may keep
	 * its id) falls back to the path for all its copies.
	 *
	 * @param array  $nodes  Nodes of a level.
	 * @param string $level  Level.
	 * @param array  $counts Counts (by reference).
	 * @param int    $depth  Depth.
	 */
	private function count_ids( array $nodes, $level, array &$counts, $depth ) {
		$next = $this->next_level( $level );
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( isset( $node['id'] ) && is_scalar( $node['id'] ) ) {
				$id            = (string) $node['id'];
				$counts[ $id ] = ( isset( $counts[ $id ] ) ? $counts[ $id ] : 0 ) + 1;
			}
			if ( null !== $next && $depth < AB_MCP_Builder_Element_Profile::MAX_DEPTH && isset( $node[ $next ] ) && is_array( $node[ $next ] ) ) {
				$this->count_ids( $node[ $next ], $next, $counts, $depth + 1 );
			}
		}
	}

	/**
	 * The level below another, or null below blocks.
	 *
	 * @param string $level Level.
	 * @return string|null
	 */
	private function next_level( $level ) {
		$order = array( 'sections', 'rows', 'cols', 'blocks' );
		$at    = array_search( $level, $order, true );
		return false !== $at && isset( $order[ $at + 1 ] ) ? $order[ $at + 1 ] : null;
	}
}
