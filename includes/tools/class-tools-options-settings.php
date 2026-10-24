<?php
/**
 * Site settings tool (fixed, safe field list).
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tools-base.php';

/**
 * Class AB_MCP_Tools_Options_Settings
 */
class AB_MCP_Tools_Options_Settings extends AB_MCP_Tools_Base {

	/**
	 * Register tools.
	 *
	 * @param AB_MCP_Tool_Registry $r Registry.
	 */
	public static function register( AB_MCP_Tool_Registry $r ) {

		$r->register(
			'wp_get_site_settings',
			array(
				'description' => 'Get common site settings: title, tagline, home URL (url), WordPress URL (wp_url), admin email, timezone, language, posts per page, date format and permalink structure. timezone is the named timezone, empty when the site uses a fixed UTC offset; permalink is empty for plain permalinks. Needs manage_options. Read-only; takes no arguments. Versions, theme and plugins are reported by wp_site_info.',
				'capability'  => 'manage_options',
				'callback'    => array( __CLASS__, 'get_site_settings' ),
			)
		);

	}

	/**
	 * Get site settings.
	 *
	 * @return array
	 */
	public static function get_site_settings() {
		return array(
			'title'          => get_option( 'blogname' ),
			'tagline'        => get_option( 'blogdescription' ),
			'url'            => home_url(),
			'wp_url'         => site_url(),
			'admin_email'    => get_option( 'admin_email' ),
			'timezone'       => get_option( 'timezone_string' ),
			'language'       => get_option( 'WPLANG' ) ? get_option( 'WPLANG' ) : get_locale(),
			'posts_per_page' => (int) get_option( 'posts_per_page' ),
			'date_format'    => get_option( 'date_format' ),
			'permalink'      => get_option( 'permalink_structure' ),
		);
	}

}
