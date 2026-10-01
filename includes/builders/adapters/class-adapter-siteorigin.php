<?php
/**
 * Adapter for SiteOrigin Page Builder (storage B): the page is the layout
 * in the post meta panels_data — an array with "grids" (rows), "grid_cells"
 * (cells, each naming its row) and "widgets" (each with its settings and
 * panels_info: class, grid, cell). post_content is a copy the site does not
 * show (measured).
 *
 * Read through get_post_meta(), as SiteOrigin loads it (renderer.php:186,
 * :684); the layout holds no objects (measured), WordPress unserializes it.
 * Page order is the renderer's (SiteOrigin_Panels_Renderer::
 * get_panels_layout_data(), renderer.php:1180-1210 of 2.36.1): rows in
 * order, each row's cells in the order grid_cells lists them, each cell's
 * widgets in the order of widgets[].
 *
 * Element ids (path ids, valid together with layout_hash):
 * - "w" + the widget's index in widgets[] — the index the renderer gives
 *   each widget (widget_index) and Pro addresses; panels_info.id holds the
 *   same number in every layout measured;
 * - "r" + row index for a row and "r<row>.c<cell>" for a cell, structure
 *   only.
 * Which widget settings are visible content is data:
 * profiles/elements-siteorigin.php. Styles (panels_info.style, row and cell
 * styles) are never read.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Builder_Adapter_SiteOrigin
 */
class AB_MCP_Builder_Adapter_SiteOrigin extends AB_MCP_Builder_Adapter {

	/** The layout. */
	const DATA = 'panels_data';

	/**
	 * Adapter id, the builder id of signatures.php.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'siteorigin';
	}

	/**
	 * Measured at Page Builder 2.36.1 with Widgets Bundle 1.74.3 (see the
	 * profile).
	 *
	 * @return bool
	 */
	public function verified(): bool {
		return true;
	}

	/**
	 * Rows, cells and widgets in page order.
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

		// The renderer's model: rows as listed, cells appended to their row.
		$rows = array();
		foreach ( isset( $data['grids'] ) && is_array( $data['grids'] ) ? $data['grids'] : array() as $grid ) {
			$rows[] = array();
		}
		foreach ( isset( $data['grid_cells'] ) && is_array( $data['grid_cells'] ) ? $data['grid_cells'] : array() as $cell ) {
			$row = is_array( $cell ) && isset( $cell['grid'] ) && is_numeric( $cell['grid'] ) ? (int) $cell['grid'] : -1;
			if ( isset( $rows[ $row ] ) ) {
				$rows[ $row ][] = array();
			}
		}
		$loose = array();
		foreach ( isset( $data['widgets'] ) && is_array( $data['widgets'] ) ? $data['widgets'] : array() as $index => $widget ) {
			if ( ! is_int( $index ) || ! is_array( $widget ) ) {
				continue;
			}
			$info = $this->info( $widget );
			$row  = isset( $info['grid'] ) && is_numeric( $info['grid'] ) ? (int) $info['grid'] : -1;
			$cell = isset( $info['cell'] ) && is_numeric( $info['cell'] ) ? (int) $info['cell'] : -1;
			if ( isset( $rows[ $row ][ $cell ] ) ) {
				$rows[ $row ][ $cell ][] = $index;
			} else {
				// The renderer would start a row of its own for it, after the others.
				$loose[] = $index;
			}
		}

		$profile = AB_MCP_Builder_Element_Profile::get( 'siteorigin' );
		$widgets = $data['widgets'];
		$out     = array();
		foreach ( $rows as $r => $cells ) {
			$row_id = 'r' . $r;
			if ( ! $this->add( array( 'element' => array( 'id' => $row_id, 'type' => 'row', 'parent' => null, 'depth' => 0 ) ), $o, $out ) ) {
				return $out;
			}
			foreach ( $cells as $c => $list ) {
				$cell_id = $row_id . '.c' . $c;
				if ( ! $this->add( array( 'element' => array( 'id' => $cell_id, 'type' => 'cell', 'parent' => $row_id, 'depth' => 1 ) ), $o, $out ) ) {
					return $out;
				}
				foreach ( $list as $index ) {
					if ( ! $this->add( $this->widget( $widgets[ $index ], $index, $cell_id, 2, $profile, $o ), $o, $out ) ) {
						return $out;
					}
				}
			}
		}
		foreach ( $loose as $index ) {
			if ( ! $this->add( $this->widget( $widgets[ $index ], $index, null, 0, $profile, $o ), $o, $out ) ) {
				return $out;
			}
		}
		return $out;
	}

	/**
	 * One widget as an element.
	 *
	 * @param array       $widget  Widget settings with panels_info.
	 * @param int         $index   Index in widgets[].
	 * @param string|null $parent  Cell id.
	 * @param int         $depth   Depth.
	 * @param array       $profile Element profile.
	 * @param array       $o       Options.
	 * @return array{element:array|null}
	 */
	private function widget( array $widget, $index, $parent, $depth, array $profile, array $o ) {
		$info  = $this->info( $widget );
		$class = isset( $info['class'] ) && is_string( $info['class'] ) ? $info['class'] : '';
		// Namespaced class names can arrive with doubled backslashes; SiteOrigin
		// reads them with one (SiteOrigin_Panels::fix_namespace_escaping()).
		$class = (string) preg_replace( '~\\\\+~', '\\', $class );
		$base  = array(
			'id'     => 'w' . $index,
			'type'   => '' !== $class ? $class : 'unknown',
			'parent' => $parent,
			'depth'  => $depth,
		);
		unset( $widget['panels_info'], $widget['info'] );
		$spec = '' !== $class && isset( $profile['elements'][ $class ] ) ? $profile['elements'][ $class ] : null;
		return AB_MCP_Builder_Element_Profile::element( $base, $widget, $spec, $profile, $o );
	}

	/**
	 * panels_info of a widget; layouts from before panels_info kept it in
	 * "info" (SiteOrigin_Panels::process_panels_data(), siteorigin-panels.php:634-637).
	 *
	 * @param array $widget Widget.
	 * @return array
	 */
	private function info( array $widget ) {
		if ( isset( $widget['panels_info'] ) && is_array( $widget['panels_info'] ) ) {
			return $widget['panels_info'];
		}
		return isset( $widget['info'] ) && is_array( $widget['info'] ) ? $widget['info'] : array();
	}

	/**
	 * Add an element unless the limit is reached.
	 *
	 * @param array $read Result with "element" (null: left out).
	 * @param array $o    Options.
	 * @param array $out  Elements (by reference).
	 * @return bool False once max_elements is reached.
	 */
	private function add( array $read, array $o, array &$out ) {
		if ( count( $out ) >= $o['max_elements'] ) {
			return false;
		}
		if ( null !== $read['element'] ) {
			$out[] = $read['element'];
		}
		return true;
	}
}
