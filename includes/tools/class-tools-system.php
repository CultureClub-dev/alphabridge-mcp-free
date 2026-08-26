<?php
/**
 * System / diagnostics tools.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tools-base.php';

/**
 * Class AB_MCP_Tools_System
 */
class AB_MCP_Tools_System extends AB_MCP_Tools_Base {

	/**
	 * Register tools.
	 *
	 * @param AB_MCP_Tool_Registry $r Registry.
	 */
	public static function register( AB_MCP_Tool_Registry $r ) {

		$r->register(
			'wp_site_info',
			array(
				'description' => 'Environment overview: WordPress/PHP/MySQL versions, active theme, plugin counts, URLs, multisite, memory, HTTPS.',
				'capability'  => 'manage_options',
				'callback'    => array( __CLASS__, 'site_info' ),
			)
		);

		$r->register(
			'wp_get_post_types',
			array(
				'description' => 'List registered post types.',
				'capability'  => 'edit_posts',
				'callback'    => array( __CLASS__, 'post_types' ),
			)
		);

		$r->register(
			'wp_get_taxonomies',
			array(
				'description' => 'List registered taxonomies.',
				'capability'  => 'edit_posts',
				'callback'    => array( __CLASS__, 'taxonomies' ),
			)
		);

	}

	/**
	 * Site info.
	 *
	 * @return array
	 */
	public static function site_info() {
		global $wp_version, $wpdb;
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$active = (array) get_option( 'active_plugins', array() );

		return array(
			'wordpress_version' => $wp_version,
			'php_version'       => PHP_VERSION,
			'mysql_version'     => $wpdb->db_version(),
			'site_url'          => site_url(),
			'home_url'          => home_url(),
			'is_ssl'            => is_ssl(),
			'is_multisite'      => is_multisite(),
			'language'          => get_locale(),
			'active_theme'      => array(
				'name'    => wp_get_theme()->get( 'Name' ),
				'version' => wp_get_theme()->get( 'Version' ),
			),
			'plugins'           => array(
				'total'  => count( get_plugins() ),
				'active' => count( $active ),
			),
			'memory_limit'      => WP_MEMORY_LIMIT,
			'max_upload_size'   => size_format( wp_max_upload_size() ),
			'mcp_endpoint'      => rest_url( AB_MCP_REST_NAMESPACE . AB_MCP_REST_ROUTE ),
			'alphabridge'       => array(
				'version'          => AB_MCP_VERSION,
				'connections_used' => count( AB_MCP_Settings::get_tokens() ),
			),
		);
	}

	/**
	 * Post types.
	 *
	 * @return array
	 */
	public static function post_types() {
		$out = array();
		foreach ( get_post_types( array(), 'objects' ) as $pt ) {
			$out[] = array(
				'name'   => $pt->name,
				'label'  => $pt->label,
				'public' => (bool) $pt->public,
			);
		}
		return array( 'post_types' => $out );
	}

	/**
	 * Taxonomies.
	 *
	 * @return array
	 */
	public static function taxonomies() {
		$out = array();
		foreach ( get_taxonomies( array(), 'objects' ) as $tax ) {
			// Hide non-public taxonomies from users who lack their capability.
			if ( ! self::can_read_taxonomy( $tax ) ) {
				continue;
			}
			$out[] = array(
				'name'    => $tax->name,
				'label'   => $tax->label,
				'objects' => $tax->object_type,
			);
		}
		return array( 'taxonomies' => $out );
	}

}
