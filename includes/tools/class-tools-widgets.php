<?php
/**
 * Widget and sidebar tools (classic-widget API). Sidebars come from
 * $wp_registered_sidebars; widget instances live in the widget_{id_base}
 * options and their placement in wp_get_sidebars_widgets().
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tools-base.php';

/**
 * Class AB_MCP_Tools_Widgets
 */
class AB_MCP_Tools_Widgets extends AB_MCP_Tools_Base {

	/**
	 * Register tools.
	 *
	 * @param AB_MCP_Tool_Registry $r Registry.
	 */
	public static function register( AB_MCP_Tool_Registry $r ) {

		$r->register(
			'wp_list_sidebars',
			array(
				'description' => "List the theme's registered sidebars (widget areas) with their widget counts.",
				'capability'  => 'edit_theme_options',
				'callback'    => array( __CLASS__, 'list_sidebars' ),
			)
		);

		$r->register(
			'wp_get_widgets',
			array(
				'description' => 'List widgets and their settings, optionally filtered to one sidebar.',
				'capability'  => 'edit_theme_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'sidebar' => array( 'type' => 'string', 'description' => 'Optional sidebar id, e.g. "sidebar-1" or "wp_inactive_widgets".' ),
					),
				),
				'callback'    => array( __CLASS__, 'get_widgets' ),
			)
		);

	}

	/* --------------------------------------------------------------- helpers */

	/**
	 * Sidebar => widget-ids map. Reads the raw option instead of the private
	 * core function wp_get_sidebars_widgets() (flagged by Plugin Check).
	 *
	 * @return array<string,mixed>
	 */
	protected static function sidebars_map() {
		$map = get_option( 'sidebars_widgets', array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Split a widget id "base-number" into its parts.
	 *
	 * @param string $id Widget id.
	 * @return array{0:string,1:int} [ id_base, number ]
	 */
	protected static function split_id( $id ) {
		if ( preg_match( '/^(.+)-(\d+)$/', (string) $id, $m ) ) {
			return array( $m[1], (int) $m[2] );
		}
		return array( '', 0 );
	}

	/* --------------------------------------------------------------- reads */

	/**
	 * List registered sidebars.
	 *
	 * @return array
	 */
	public static function list_sidebars() {
		global $wp_registered_sidebars;
		$map = self::sidebars_map();
		$out = array();
		if ( is_array( $wp_registered_sidebars ) ) {
			foreach ( $wp_registered_sidebars as $id => $sb ) {
				$out[] = array(
					'id'           => $id,
					'name'         => isset( $sb['name'] ) ? $sb['name'] : $id,
					'description'  => isset( $sb['description'] ) ? $sb['description'] : '',
					'widget_count' => isset( $map[ $id ] ) && is_array( $map[ $id ] ) ? count( $map[ $id ] ) : 0,
				);
			}
		}
		$inactive = isset( $map['wp_inactive_widgets'] ) && is_array( $map['wp_inactive_widgets'] ) ? count( $map['wp_inactive_widgets'] ) : 0;
		$out[]    = array(
			'id'           => 'wp_inactive_widgets',
			'name'         => __( 'Inactive widgets', 'alphabridge-mcp' ),
			'description'  => __( 'Widgets not placed in any active sidebar.', 'alphabridge-mcp' ),
			'widget_count' => $inactive,
		);
		return array( 'sidebars' => $out );
	}

	/**
	 * List widgets, optionally within one sidebar.
	 *
	 * @param array $a Args.
	 * @return array
	 */
	public static function get_widgets( $a ) {
		global $wp_registered_widgets;
		$want = self::s( $a, 'sidebar', '' );
		$map  = self::sidebars_map();
		$loc  = array();
		foreach ( $map as $sb => $ids ) {
			if ( 'array_version' === $sb || ! is_array( $ids ) ) {
				continue;
			}
			foreach ( $ids as $wid ) {
				$loc[ $wid ] = $sb;
			}
		}
		$out = array();
		$ids = is_array( $wp_registered_widgets ) ? array_keys( $wp_registered_widgets ) : array();
		foreach ( $ids as $wid ) {
			$sidebar = isset( $loc[ $wid ] ) ? $loc[ $wid ] : '';
			if ( '' !== $want && $sidebar !== $want ) {
				continue;
			}
			list( $base, $num ) = self::split_id( $wid );
			$settings = null;
			if ( '' !== $base ) {
				$opt = get_option( 'widget_' . $base );
				if ( is_array( $opt ) && isset( $opt[ $num ] ) ) {
					$settings = $opt[ $num ];
				}
			}
			$info  = $wp_registered_widgets[ $wid ];
			$out[] = array(
				'id'       => $wid,
				'id_base'  => $base,
				'number'   => $num,
				'name'     => isset( $info['name'] ) ? $info['name'] : $wid,
				'sidebar'  => $sidebar,
				'settings' => $settings,
			);
		}
		return array( 'widgets' => $out );
	}

	/* --------------------------------------------------------------- writes */

}
