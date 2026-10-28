<?php
/**
 * The lists of installed plugins and themes.
 *
 * Free since 4.7.0. Until then these two tools came with AlphaBridge MCP Pro
 * only; the code is Pro's, unchanged in what it does. Pro registers its own
 * copy of the same names over these. Activating, installing, updating and
 * deleting, and the files inside a plugin or theme, stay in Pro.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tools-base.php';

/**
 * Class AB_MCP_Tools_Plugins_Themes_List
 */
class AB_MCP_Tools_Plugins_Themes_List extends AB_MCP_Tools_Base {

	/**
	 * Register tools.
	 *
	 * @param AB_MCP_Tool_Registry $r Registry.
	 */
	public static function register( AB_MCP_Tool_Registry $r ) {

		$r->register(
			'wp_list_plugins',
			array(
				'description' => 'List the installed plugins in wp-content/plugins, all in one answer (no paging). Returns plugins: one entry per plugin with file (the identifier WordPress uses, e.g. "akismet/akismet.php"), name, version and active. active reflects this site\'s own active plugins; on multisite a network-activated plugin shows false here. Must-use plugins and drop-ins are not listed. Pending updates are reported by wp_get_update_status. Requires activate_plugins.',
				'capability'  => 'activate_plugins',
				'callback'    => array( __CLASS__, 'list_plugins' ),
			)
		);

		$r->register(
			'wp_list_themes',
			array(
				'description' => 'List the installed themes, all in one answer (no paging). Returns themes: one entry per theme with slug (the folder name, "collection/theme" for a theme inside a collection), name, version and active (true only for the theme in use; the parent of an active child theme shows false). Themes WordPress counts as broken, such as a folder without style.css, are left out. Pending updates are reported by wp_get_update_status. Requires edit_theme_options.',
				'capability'  => 'edit_theme_options',
				'callback'    => array( __CLASS__, 'list_themes' ),
			)
		);
	}

	/**
	 * List plugins.
	 *
	 * @return array
	 */
	public static function list_plugins() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$all    = get_plugins();
		$active = (array) get_option( 'active_plugins', array() );
		$out    = array();
		foreach ( $all as $file => $data ) {
			$out[] = array(
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => in_array( $file, $active, true ),
			);
		}
		return array( 'plugins' => $out );
	}

	/**
	 * List themes.
	 *
	 * @return array
	 */
	public static function list_themes() {
		$themes  = wp_get_themes();
		$current = get_stylesheet();
		$out     = array();
		foreach ( $themes as $slug => $theme ) {
			$out[] = array(
				'slug'    => $slug,
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'active'  => ( $slug === $current ),
			);
		}
		return array( 'themes' => $out );
	}
}
