<?php
/**
 * Adapter for Beaver Builder (storage B): the page is the layout in the
 * post meta _fl_builder_data — an array of node objects keyed by node id,
 * each with node, type ('row', 'column-group', 'column' or 'module'),
 * parent, position and settings (a module's own type in settings->type).
 * post_content is a text copy the site does not show (measured), and the
 * editor keeps an unpublished draft in _fl_builder_draft.
 *
 * Read through get_post_meta(), as Beaver itself loads the layout
 * (FLBuilderModel::get_layout_data(), class-fl-builder-model.php:5890-5903):
 * WordPress unserializes the stored objects; this adapter never does.
 * Which module settings are visible content is data:
 * profiles/elements-beaver.php.
 *
 * Element ids are Beaver's node ids. Page CSS and JavaScript sit in
 * _fl_builder_data_settings and _fl_builder_draft_settings (locked, never
 * read); per-node code (bb_css_code, bb_js_code) and every other setting not
 * in the profile is never a field.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Builder_Adapter_Beaver
 */
class AB_MCP_Builder_Adapter_Beaver extends AB_MCP_Builder_Adapter {

	/** The published layout. */
	const DATA = '_fl_builder_data';

	/** The editor's unpublished draft. */
	const DRAFT = '_fl_builder_draft';

	/** Node types that only give the layout its structure. */
	const STRUCTURE = array( 'row', 'column-group', 'column' );

	/** A node id as Beaver makes them (FLBuilderModel::generate_node_id(), 12 characters). */
	const NODE_ID = '~^[A-Za-z0-9_-]{1,64}$~';

	/**
	 * Adapter id, the builder id of signatures.php.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'beaver';
	}

	/**
	 * Measured at Beaver Builder Lite 2.11.0.6 (see the profile).
	 *
	 * @return bool
	 */
	public function verified(): bool {
		return true;
	}

	/**
	 * Whether the editor's draft differs from the published layout: then
	 * the next "Publish" in the Beaver editor publishes the draft, not what
	 * the site shows now. Compared as Beaver loads them (unserialized), so
	 * two byte forms of the same layout count as equal. No draft: false —
	 * the editor then starts from the published layout.
	 *
	 * @param WP_Post $post Post.
	 * @return bool|null
	 */
	public function draft_differs( $post ): ?bool {
		$id = (int) $post->ID;
		if ( array() === (array) get_post_meta( $id, self::DRAFT, false ) ) {
			return false;
		}
		// Loose on purpose: arrays of objects compare by their properties.
		return get_post_meta( $id, self::DRAFT, true ) != get_post_meta( $id, self::DATA, true ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
	}

	/**
	 * Rows, column groups, columns and modules in the order Beaver renders
	 * them: children of a node by position.
	 *
	 * @param WP_Post $post Post.
	 * @param array   $o    Options.
	 * @return array<int,array>
	 */
	public function outline( $post, array $o ): array {
		$o    = AB_MCP_Builder_Element_Profile::options( $o );
		$data = get_post_meta( (int) $post->ID, self::DATA, true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$nodes = array();
		$order = 0;
		foreach ( $data as $node ) {
			if ( ! is_object( $node ) || ! isset( $node->node ) || ! is_scalar( $node->node ) || 1 !== preg_match( self::NODE_ID, (string) $node->node ) ) {
				continue;
			}
			$nodes[ (string) $node->node ] = array(
				'node'  => $node,
				'order' => $order++,
			);
		}

		$children = array();
		foreach ( $nodes as $id => $entry ) {
			$parent = isset( $entry['node']->parent ) && is_scalar( $entry['node']->parent ) ? (string) $entry['node']->parent : '';
			if ( '' !== $parent && ! isset( $nodes[ $parent ] ) ) {
				continue; // A node whose parent is gone is not rendered.
			}
			$children[ $parent ][] = $id;
		}
		foreach ( $children as $parent => $ids ) {
			usort(
				$ids,
				static function ( $a, $b ) use ( $nodes ) {
					$pa = isset( $nodes[ $a ]['node']->position ) && is_numeric( $nodes[ $a ]['node']->position ) ? (int) $nodes[ $a ]['node']->position : 0;
					$pb = isset( $nodes[ $b ]['node']->position ) && is_numeric( $nodes[ $b ]['node']->position ) ? (int) $nodes[ $b ]['node']->position : 0;
					return $pa <=> $pb ?: $nodes[ $a ]['order'] <=> $nodes[ $b ]['order'];
				}
			);
			$children[ $parent ] = $ids;
		}

		$profile = AB_MCP_Builder_Element_Profile::get( 'beaver' );
		$out     = array();
		$this->walk( '', 0, $nodes, $children, $profile, $o, $out );
		return $out;
	}

	/**
	 * Add the children of one node, depth first.
	 *
	 * @param string $parent   Parent node id ('' for the top level).
	 * @param int    $depth    Depth.
	 * @param array  $nodes    Node id => [ node, order ].
	 * @param array  $children Parent id => child ids in order.
	 * @param array  $profile  Element profile.
	 * @param array  $o        Options.
	 * @param array  $out      Elements (by reference).
	 * @return bool False once max_elements is reached.
	 */
	private function walk( $parent, $depth, array $nodes, array $children, array $profile, array $o, array &$out ) {
		if ( $depth > AB_MCP_Builder_Element_Profile::MAX_DEPTH || ! isset( $children[ $parent ] ) ) {
			return true;
		}
		foreach ( $children[ $parent ] as $id ) {
			if ( count( $out ) >= $o['max_elements'] ) {
				return false;
			}
			$node     = $nodes[ $id ]['node'];
			$settings = isset( $node->settings ) && ( is_object( $node->settings ) || is_array( $node->settings ) ) ? $node->settings : array();
			$kind     = isset( $node->type ) && is_string( $node->type ) ? $node->type : '';
			$type     = 'module' === $kind ? AB_MCP_Builder_Element_Profile::value_at( $settings, 'type' ) : $kind;
			$base     = array(
				'id'     => $id,
				'type'   => is_string( $type ) && '' !== $type ? $type : 'unknown',
				'parent' => '' === $parent ? null : $parent,
				'depth'  => $depth,
			);

			$global = $this->global_post( $node );
			if ( null !== $global ) {
				// A global node renders its saved template, not the copy kept here
				// (FLBuilderModel::is_node_global(), class-fl-builder-model.php:7110-7118).
				$read = AB_MCP_Builder_Element_Profile::locked( $base, 'global element', $o, array_merge( array( 'global' => true ), $global > 0 ? array( 'note' => sprintf( 'its content is post #%d', $global ) ) : array() ) );
			} elseif ( in_array( $kind, self::STRUCTURE, true ) ) {
				$read = array(
					'element'  => $base,
					'children' => true,
				);
			} elseif ( 'module' !== $kind ) {
				$read = AB_MCP_Builder_Element_Profile::locked( $base, AB_MCP_Builder_Element_Profile::UNKNOWN, $o );
			} else {
				$spec = isset( $profile['elements'][ $base['type'] ] ) ? $profile['elements'][ $base['type'] ] : null;
				$read = null !== $spec && $this->connected( $settings, $spec )
					? AB_MCP_Builder_Element_Profile::locked( $base, 'dynamic value', $o )
					: AB_MCP_Builder_Element_Profile::element( $base, $settings, $spec, $profile, $o );
			}

			if ( null !== $read['element'] ) {
				$out[] = $read['element'];
			}
			if ( $read['children'] && ! $this->walk( $id, $depth + 1, $nodes, $children, $profile, $o, $out ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The template post a global node shows: its id (0 when unknown), or
	 * null for a node that is not global. Beaver stores template_id on such
	 * a node and, after loading, the template's post id in "global".
	 *
	 * @param object $node Node.
	 * @return int|null
	 */
	private function global_post( $node ) {
		$template = isset( $node->template_id ) && is_scalar( $node->template_id ) ? (string) $node->template_id : '';
		$global   = isset( $node->global ) && is_scalar( $node->global ) ? $node->global : false;
		if ( '' === $template && ( false === $global || '' === $global || 0 === $global || '0' === $global ) ) {
			return null;
		}
		return is_numeric( $global ) && (int) $global > 0 ? (int) $global : 0;
	}

	/**
	 * Whether a field the profile reads is connected to dynamic data: Beaver
	 * keeps such links in settings->connections, keyed by field name
	 * (modules/rich-text/includes/frontend.php:17-19 of 2.11.0.6), and then
	 * shows the connected value instead of the stored text.
	 *
	 * @param array|object $settings Settings.
	 * @param array        $spec     Profile entry.
	 * @return bool
	 */
	private function connected( $settings, array $spec ) {
		$connections = AB_MCP_Builder_Element_Profile::value_at( $settings, 'connections' );
		if ( ! is_array( $connections ) && ! is_object( $connections ) ) {
			return false;
		}
		foreach ( array_keys( $spec['fields'] ) as $path ) {
			$name = strtok( $path, '.' );
			$link = AB_MCP_Builder_Element_Profile::value_at( $connections, (string) $name );
			if ( ! empty( $link ) && ! ( is_object( $link ) && array() === get_object_vars( $link ) ) ) {
				return true;
			}
		}
		return false;
	}
}
