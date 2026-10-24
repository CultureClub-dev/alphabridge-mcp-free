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
				'description' => 'Get an overview of the technical environment: WordPress, PHP and MySQL versions, site and home URL, HTTPS, multisite, locale, the active theme\'s name and version, the number of installed plugins and of plugins active on this site (network-activated ones are not counted), WP_MEMORY_LIMIT, the maximum upload size, this plugin\'s MCP endpoint and version, and the number of connections in use. Needs manage_options. Read-only; takes no arguments. wp_site_health adds debug mode, object cache and pending updates; wp_get_site_settings returns title, email, timezone and other settings.',
				'capability'  => 'manage_options',
				'callback'    => array( __CLASS__, 'site_info' ),
			)
		);

		$r->register(
			'wp_get_post_types',
			array(
				'description' => 'List every post type registered on the site, built-in and custom, internal ones such as revision and nav_menu_item included. Returns post_types: [{name, label, public}]; name is the value the post tools take as type, public is WordPress\' public flag of the type. Needs edit_posts. Read-only; takes no arguments. Taxonomies are listed by wp_get_taxonomies.',
				'capability'  => 'edit_posts',
				'callback'    => array( __CLASS__, 'post_types' ),
			)
		);

		$r->register(
			'wp_get_taxonomies',
			array(
				'description' => 'List the taxonomies registered on the site, the built-in category and post_tag included. Returns taxonomies: [{name, label, objects}]; name is the value the term tools take as taxonomy (their terms are listed by wp_list_terms), objects names the post types the taxonomy applies to. A non-public taxonomy appears only for an account that may assign its terms. Needs edit_posts. Read-only; takes no arguments. Post types are listed by wp_get_post_types.',
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
