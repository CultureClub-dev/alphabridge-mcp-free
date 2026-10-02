<?php
/**
 * Short names of the tools, for the fine-tuning on the settings page.
 *
 * A tool's description is written for the assistant, in English, and its
 * first sentence is often long. The fine-tuning shows a short name in the
 * admin's language next to the tool's name in code instead («List snippets»,
 * German «Snippets auflisten»), and the description behind its «i». The core
 * names the tools of the Pro add-on as well, as it does with their groups:
 * Pro ships no translations, and the names show on the core's page. A tool
 * this list does not know (an add-on of someone else) is named by the filter
 * ab_mcp_tool_label, or else by the first sentence of its description.
 *
 * Only the display: the name in code, the description and everything a client
 * sees stay as they are.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Tool_Labels
 */
class AB_MCP_Tool_Labels {

	/**
	 * The names, per locale, once they were asked for.
	 *
	 * @var array<string,array<string,string>>
	 */
	private static $cache = array();

	/**
	 * Every short name this list knows, tool => name, in the admin's language.
	 *
	 * @return array<string,string>
	 */
	public static function all() {
		$locale = function_exists( 'determine_locale' ) ? (string) determine_locale() : '';
		if ( ! isset( self::$cache[ $locale ] ) ) {
			self::$cache[ $locale ] = self::build();
		}
		return self::$cache[ $locale ];
	}

	/**
	 * The short name of a tool, or '' where neither this list nor a filter
	 * names it.
	 *
	 * @param string $name Tool name.
	 * @return string Plain text.
	 */
	public static function get( $name ) {
		$name  = (string) $name;
		$all   = self::all();
		$label = isset( $all[ $name ] ) ? $all[ $name ] : '';
		/**
		 * The short name of a tool on the fine-tuning. An add-on names its
		 * own tools here; '' leaves the first sentence of the description.
		 *
		 * @since 4.5.0
		 *
		 * @param string $label The core's name, '' for a tool it does not know.
		 * @param string $name  Tool name.
		 */
		$label = apply_filters( 'ab_mcp_tool_label', $label, $name );
		return is_scalar( $label ) ? trim( (string) $label ) : '';
	}

	/**
	 * The names, translated.
	 *
	 * @return array<string,string>
	 */
	private static function build() {
		return array(
			'wp_list_posts'                  => _x( 'List posts and pages', 'tool label', 'alphabridge-mcp' ),
			'wp_get_post'                    => _x( 'Read a post or page', 'tool label', 'alphabridge-mcp' ),
			'wp_create_post'                 => _x( 'Create a post or page', 'tool label', 'alphabridge-mcp' ),
			'wp_update_post'                 => _x( 'Change a post or page', 'tool label', 'alphabridge-mcp' ),
			'wp_delete_post'                 => _x( 'Delete a post or page', 'tool label', 'alphabridge-mcp' ),
			'wp_duplicate_post'              => _x( 'Duplicate a post or page', 'tool label', 'alphabridge-mcp' ),
			'wp_list_revisions'              => _x( 'List revisions', 'tool label', 'alphabridge-mcp' ),
			'wp_restore_revision'            => _x( 'Restore a revision', 'tool label', 'alphabridge-mcp' ),
			'wp_get_post_meta'               => _x( 'Read post meta', 'tool label', 'alphabridge-mcp' ),
			'wp_update_post_meta'            => _x( 'Set post meta', 'tool label', 'alphabridge-mcp' ),
			'wp_get_builder_layout'          => _x( 'Read a page-builder layout', 'tool label', 'alphabridge-mcp' ),
			'wp_update_builder_element'      => _x( 'Change a page-builder element', 'tool label', 'alphabridge-mcp' ),
			'wp_list_media'                  => _x( 'List media', 'tool label', 'alphabridge-mcp' ),
			'wp_get_media'                   => _x( 'Read a media file', 'tool label', 'alphabridge-mcp' ),
			'wp_upload_media'                => _x( 'Upload media', 'tool label', 'alphabridge-mcp' ),
			'wp_upload_media_from_url'       => _x( 'Add media from a URL', 'tool label', 'alphabridge-mcp' ),
			'wp_update_media'                => _x( 'Change media details', 'tool label', 'alphabridge-mcp' ),
			'wp_delete_media'                => _x( 'Delete media', 'tool label', 'alphabridge-mcp' ),
			'wp_list_terms'                  => _x( 'List terms', 'tool label', 'alphabridge-mcp' ),
			'wp_get_term'                    => _x( 'Read a term', 'tool label', 'alphabridge-mcp' ),
			'wp_create_term'                 => _x( 'Create a term', 'tool label', 'alphabridge-mcp' ),
			'wp_update_term'                 => _x( 'Change a term', 'tool label', 'alphabridge-mcp' ),
			'wp_delete_term'                 => _x( 'Delete a term', 'tool label', 'alphabridge-mcp' ),
			'wp_list_comments'               => _x( 'List comments', 'tool label', 'alphabridge-mcp' ),
			'wp_get_comment'                 => _x( 'Read a comment', 'tool label', 'alphabridge-mcp' ),
			'wp_moderate_comment'            => _x( 'Moderate a comment', 'tool label', 'alphabridge-mcp' ),
			'wp_reply_comment'               => _x( 'Reply to a comment', 'tool label', 'alphabridge-mcp' ),
			'wp_delete_comment'              => _x( 'Delete a comment', 'tool label', 'alphabridge-mcp' ),
			'wp_list_sidebars'               => _x( 'List widget areas', 'tool label', 'alphabridge-mcp' ),
			'wp_get_widgets'                 => _x( 'List widgets', 'tool label', 'alphabridge-mcp' ),
			'wp_add_widget'                  => _x( 'Add a widget', 'tool label', 'alphabridge-mcp' ),
			'wp_update_widget'               => _x( 'Change a widget', 'tool label', 'alphabridge-mcp' ),
			'wp_delete_widget'               => _x( 'Remove a widget', 'tool label', 'alphabridge-mcp' ),
			'wp_seo_detect'                  => _x( 'Detect the SEO plugin', 'tool label', 'alphabridge-mcp' ),
			'wp_seo_get'                     => _x( 'Read SEO title and description', 'tool label', 'alphabridge-mcp' ),
			'wp_seo_set'                     => _x( 'Set SEO title and description', 'tool label', 'alphabridge-mcp' ),
			'wp_search'                      => _x( 'Search content', 'tool label', 'alphabridge-mcp' ),
			'wp_search_replace'              => _x( 'Search and replace across the site', 'tool label', 'alphabridge-mcp' ),
			'wp_bulk_update_status'          => _x( 'Change the status of many posts', 'tool label', 'alphabridge-mcp' ),
			'wp_get_site_settings'           => _x( 'Read site settings', 'tool label', 'alphabridge-mcp' ),
			'wp_update_site_settings'        => _x( 'Change site settings', 'tool label', 'alphabridge-mcp' ),
			'wp_update_option'               => _x( 'Set an option', 'tool label', 'alphabridge-mcp' ),
			'wp_delete_option'               => _x( 'Delete an option', 'tool label', 'alphabridge-mcp' ),
			'wp_list_users'                  => _x( 'List users', 'tool label', 'alphabridge-mcp' ),
			'wp_get_user'                    => _x( 'Read a user', 'tool label', 'alphabridge-mcp' ),
			'wp_create_user'                 => _x( 'Create a user', 'tool label', 'alphabridge-mcp' ),
			'wp_update_user'                 => _x( 'Change a user', 'tool label', 'alphabridge-mcp' ),
			'wp_delete_user'                 => _x( 'Delete a user', 'tool label', 'alphabridge-mcp' ),
			'wp_list_menus'                  => _x( 'List menus', 'tool label', 'alphabridge-mcp' ),
			'wp_get_menu'                    => _x( 'Read a menu', 'tool label', 'alphabridge-mcp' ),
			'wp_create_menu'                 => _x( 'Create a menu', 'tool label', 'alphabridge-mcp' ),
			'wp_add_menu_item'               => _x( 'Add a menu item', 'tool label', 'alphabridge-mcp' ),
			'wp_delete_menu_item'            => _x( 'Remove a menu item', 'tool label', 'alphabridge-mcp' ),
			'wp_get_user_meta'               => _x( 'Read profile fields', 'tool label', 'alphabridge-mcp' ),
			'wp_update_user_meta'            => _x( 'Set user meta', 'tool label', 'alphabridge-mcp' ),
			'wp_get_term_meta'               => _x( 'Read term meta', 'tool label', 'alphabridge-mcp' ),
			'wp_update_term_meta'            => _x( 'Set term meta', 'tool label', 'alphabridge-mcp' ),
			'wp_list_application_passwords'  => _x( 'List application passwords', 'tool label', 'alphabridge-mcp' ),
			'wp_create_application_password' => _x( 'Create an application password', 'tool label', 'alphabridge-mcp' ),
			'wp_revoke_application_password' => _x( 'Revoke an application password', 'tool label', 'alphabridge-mcp' ),
			'wp_network_list_sites'          => _x( 'List the sites of the network', 'tool label', 'alphabridge-mcp' ),
			'wp_network_create_site'         => _x( 'Create a site in the network', 'tool label', 'alphabridge-mcp' ),
			'wp_network_activate_plugin'     => _x( 'Activate a plugin network-wide', 'tool label', 'alphabridge-mcp' ),
			'wp_get_update_status'           => _x( 'Show available updates', 'tool label', 'alphabridge-mcp' ),
			'wp_install_plugin'              => _x( 'Install a plugin', 'tool label', 'alphabridge-mcp' ),
			'wp_install_plugin_zip'          => _x( 'Install a plugin from a ZIP', 'tool label', 'alphabridge-mcp' ),
			'wp_update_plugin'               => _x( 'Update a plugin', 'tool label', 'alphabridge-mcp' ),
			'wp_delete_plugin'               => _x( 'Delete a plugin', 'tool label', 'alphabridge-mcp' ),
			'wp_install_theme'               => _x( 'Install a theme', 'tool label', 'alphabridge-mcp' ),
			'wp_install_theme_zip'           => _x( 'Install a theme from a ZIP', 'tool label', 'alphabridge-mcp' ),
			'wp_update_theme'                => _x( 'Update a theme', 'tool label', 'alphabridge-mcp' ),
			'wp_delete_theme'                => _x( 'Delete a theme', 'tool label', 'alphabridge-mcp' ),
			'wp_update_core'                 => _x( 'Update WordPress', 'tool label', 'alphabridge-mcp' ),
			'wp_list_plugins'                => _x( 'List plugins', 'tool label', 'alphabridge-mcp' ),
			'wp_activate_plugin'             => _x( 'Activate a plugin', 'tool label', 'alphabridge-mcp' ),
			'wp_deactivate_plugin'           => _x( 'Deactivate a plugin', 'tool label', 'alphabridge-mcp' ),
			'wp_list_themes'                 => _x( 'List themes', 'tool label', 'alphabridge-mcp' ),
			'wp_activate_theme'              => _x( 'Switch the theme', 'tool label', 'alphabridge-mcp' ),
			'wp_list_theme_files'            => _x( 'List theme files', 'tool label', 'alphabridge-mcp' ),
			'wp_read_theme_file'             => _x( 'Read a theme file', 'tool label', 'alphabridge-mcp' ),
			'wp_write_theme_file'            => _x( 'Write a theme file', 'tool label', 'alphabridge-mcp' ),
			'wp_delete_theme_file'           => _x( 'Delete a theme file', 'tool label', 'alphabridge-mcp' ),
			'wp_list_plugin_files'           => _x( 'List plugin files', 'tool label', 'alphabridge-mcp' ),
			'wp_read_plugin_file'            => _x( 'Read a plugin file', 'tool label', 'alphabridge-mcp' ),
			'wp_write_plugin_file'           => _x( 'Write a plugin file', 'tool label', 'alphabridge-mcp' ),
			'wp_delete_plugin_file'          => _x( 'Delete a plugin file', 'tool label', 'alphabridge-mcp' ),
			'wp_list_uploads'                => _x( 'List uploaded files', 'tool label', 'alphabridge-mcp' ),
			'wp_read_upload_file'            => _x( 'Read an uploaded file', 'tool label', 'alphabridge-mcp' ),
			'wp_write_upload_file'           => _x( 'Write a file to the uploads', 'tool label', 'alphabridge-mcp' ),
			'wp_delete_upload_file'          => _x( 'Delete an uploaded file', 'tool label', 'alphabridge-mcp' ),
			'wp_list_php_snippets'           => _x( 'List snippets', 'tool label', 'alphabridge-mcp' ),
			'wp_get_php_snippet'             => _x( 'Read a snippet', 'tool label', 'alphabridge-mcp' ),
			'wp_save_php_snippet'            => _x( 'Create or change a snippet', 'tool label', 'alphabridge-mcp' ),
			'wp_toggle_php_snippet'          => _x( 'Switch a snippet on or off', 'tool label', 'alphabridge-mcp' ),
			'wp_delete_php_snippet'          => _x( 'Delete a snippet', 'tool label', 'alphabridge-mcp' ),
			'wp_deploy_test'                 => _x( 'Test the deploy connection', 'tool label', 'alphabridge-mcp' ),
			'wp_deploy_list'                 => _x( 'List the deploy area', 'tool label', 'alphabridge-mcp' ),
			'wp_deploy_read'                 => _x( 'Read a file in the deploy area', 'tool label', 'alphabridge-mcp' ),
			'wp_deploy_diff'                 => _x( 'Preview a deploy', 'tool label', 'alphabridge-mcp' ),
			'wp_deploy_push_file'            => _x( 'Write a file to the deploy area', 'tool label', 'alphabridge-mcp' ),
			'wp_deploy_push_zip'             => _x( 'Unpack a ZIP into the deploy area', 'tool label', 'alphabridge-mcp' ),
			'wp_deploy_delete'               => _x( 'Delete in the deploy area', 'tool label', 'alphabridge-mcp' ),
			'wp_upload_begin'                => _x( 'Start a large upload', 'tool label', 'alphabridge-mcp' ),
			'wp_upload_append'               => _x( 'Send a part of the upload', 'tool label', 'alphabridge-mcp' ),
			'wp_upload_commit'               => _x( 'Finish the upload', 'tool label', 'alphabridge-mcp' ),
			'wp_upload_abort'                => _x( 'Cancel the upload', 'tool label', 'alphabridge-mcp' ),
			'wp_blueprint_export'            => _x( 'Export the site structure', 'tool label', 'alphabridge-mcp' ),
			'wp_blueprint_diff'              => _x( 'Compare a blueprint with this site', 'tool label', 'alphabridge-mcp' ),
			'wp_blueprint_apply'             => _x( 'Apply a blueprint', 'tool label', 'alphabridge-mcp' ),
			'wp_export_content'              => _x( 'Export content (WXR)', 'tool label', 'alphabridge-mcp' ),
			'wp_db_tables'                   => _x( 'List database tables', 'tool label', 'alphabridge-mcp' ),
			'wp_db_schema'                   => _x( 'Describe a table', 'tool label', 'alphabridge-mcp' ),
			'wp_db_query'                    => _x( 'Run a read-only query', 'tool label', 'alphabridge-mcp' ),
			'wp_db_execute'                  => _x( 'Write database rows', 'tool label', 'alphabridge-mcp' ),
			'wp_db_optimize'                 => _x( 'Optimize database tables', 'tool label', 'alphabridge-mcp' ),
			'wp_site_info'                   => _x( 'Show the environment', 'tool label', 'alphabridge-mcp' ),
			'wp_site_health'                 => _x( 'Check site health', 'tool label', 'alphabridge-mcp' ),
			'wp_dashboard_counts'            => _x( 'Count content', 'tool label', 'alphabridge-mcp' ),
			'wp_get_post_types'              => _x( 'List post types', 'tool label', 'alphabridge-mcp' ),
			'wp_get_taxonomies'              => _x( 'List taxonomies', 'tool label', 'alphabridge-mcp' ),
			'wp_get_error_log'               => _x( 'Read the error log', 'tool label', 'alphabridge-mcp' ),
			'wp_clear_cache'                 => _x( 'Clear caches', 'tool label', 'alphabridge-mcp' ),
			'wp_flush_rewrite_rules'         => _x( 'Refresh permalinks', 'tool label', 'alphabridge-mcp' ),
			'wp_get_cron'                    => _x( 'List cron events', 'tool label', 'alphabridge-mcp' ),
			'wp_schedule_cron'               => _x( 'Schedule a cron event', 'tool label', 'alphabridge-mcp' ),
			'wp_unschedule_cron'             => _x( 'Unschedule cron events', 'tool label', 'alphabridge-mcp' ),
			'wp_set_transient'               => _x( 'Set a transient', 'tool label', 'alphabridge-mcp' ),
			'wp_delete_transient'            => _x( 'Delete a transient', 'tool label', 'alphabridge-mcp' ),
			'wp_cleanup_transients'          => _x( 'Delete expired transients', 'tool label', 'alphabridge-mcp' ),
			'wp_wc_list_orders'              => _x( 'List orders', 'tool label', 'alphabridge-mcp' ),
			'wp_wc_get_order'                => _x( 'Read an order', 'tool label', 'alphabridge-mcp' ),
			'wp_wc_update_order_status'      => _x( 'Change an order\'s status', 'tool label', 'alphabridge-mcp' ),
			'wp_wc_list_products'            => _x( 'List products', 'tool label', 'alphabridge-mcp' ),
			'wp_wc_get_product'              => _x( 'Read a product', 'tool label', 'alphabridge-mcp' ),
			'wp_wc_update_product'           => _x( 'Change a product', 'tool label', 'alphabridge-mcp' ),
			'wp_wc_list_coupons'             => _x( 'List coupons', 'tool label', 'alphabridge-mcp' ),
			'wp_wc_create_coupon'            => _x( 'Create a coupon', 'tool label', 'alphabridge-mcp' ),
			'wp_wc_list_customers'           => _x( 'List customers', 'tool label', 'alphabridge-mcp' ),
			'wp_undo_list'                   => _x( 'List undo points', 'tool label', 'alphabridge-mcp' ),
			'wp_undo'                        => _x( 'Undo a change', 'tool label', 'alphabridge-mcp' ),
			'wp_undo_discard'                => _x( 'Discard an undo point', 'tool label', 'alphabridge-mcp' ),
			'wp_list_abilities'              => _x( 'List abilities', 'tool label', 'alphabridge-mcp' ),
			'wp_run_ability'                 => _x( 'Run an ability', 'tool label', 'alphabridge-mcp' ),
		);
	}
}
