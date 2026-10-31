<?php
/**
 * The files of wp-admin that the routines of other plugins and themes expect,
 * loaded right before AlphaBridge runs one: an activation, of a plugin or a
 * theme (AB_MCP_Activation_Guard: wp_activate_plugin, wp_activate_theme, the
 * installs with activate, wp_undo of a theme switch), a deactivation
 * (wp_deactivate_plugin), and the runs of the upgrader and the deletes
 * (AB_MCP_Tools_Install_Update::boot_upgrader(): the installs, the updates,
 * the core update, wp_delete_plugin with the plugin's deactivation and
 * uninstall routine, wp_delete_theme), with the hooks other plugins hang on
 * them; and an ability of another plugin (wp_run_ability,
 * AB_MCP_Ability_Broker::call()), which may do any of these.
 *
 * wp-admin loads wp-admin/includes/admin.php on every screen before any of
 * these runs; a REST request loads none of it, and WordPress's own REST
 * endpoint for plugins loads only plugin.php to switch one on or off.
 * Routines rely on it without asking: measured 31.10.2026 on
 * video.alphabridge-mcp.com (WordPress 7.1.4), wp_deactivate_plugin on WP
 * Super Cache ended with "The tool failed with an internal error" and left
 * the plugin active, as its deactivation (wpsupercache_deactivate() →
 * wp_cache_disable_plugin() → wpsc_get_htaccess_info()) calls get_home_path()
 * (file.php) and insert_with_markers() (misc.php); in wp-admin the same
 * deactivation went through. Before 4.16.0 a plugin's activation and the
 * deactivation loaded plugin.php only, a theme switch nothing, the
 * upgrader's runs and the deletes file.php, misc.php, plugin.php, update.php
 * and theme.php.
 *
 * A list of files rather than admin.php itself. The list is admin.php's
 * (WordPress 7.1.3, the same as in 7.0.2), in its order: the functions and
 * classes of wp-admin, all of them, so no routine has to be guessed at. Left
 * out is what admin.php does besides, as none of it is what a routine calls,
 * and all of it would stay for the rest of the request, the further messages
 * of a batch included:
 *  - admin-filters.php (and ms-admin-filters.php on a network) add about a
 *    hundred hooks. Most hang on wp-admin's own actions, which a REST request
 *    does not run; the others change what follows: four on
 *    upgrader_process_complete ask wordpress.org three times
 *    (wp_version_check(), wp_update_plugins(), wp_update_themes()) and fetch
 *    language packs after every install (AB_MCP_Update_Guard takes them off
 *    for its updates, quiet_upgrader_hooks()), update_option_admin_email and
 *    the hooks on new_admin_email send e-mails, those on home, siteurl and
 *    page_on_front flush the rewrite rules, and on a network in folders
 *    wp_insert_post_data changes the slugs of pages on the main site
 *    (avoid_blog_page_permalink_collision()) and wp_handle_upload_prefilter
 *    checks the upload quota;
 *  - outside wp-admin, admin.php loads the admin translations
 *    (admin-{locale}.mo) into the default text domain.
 * Either way is_admin() stays false (it asks the current screen and WP_ADMIN,
 * and neither is set), no constant is defined (the define()s of file.php,
 * plugin.php, theme.php, post.php and media.php sit inside functions), and
 * nothing is printed: the files define functions and classes; template.php
 * loads two files of classes, and file.php fills $wp_file_descriptions,
 * which stays in the scope of load() here, as where WordPress's REST
 * endpoints load file.php (only the theme file editor reads it). New for the
 * rest of the request is only that these functions and classes exist, as in
 * wp-admin. Loading them all takes about 3 MB and 13 ms without OPcache
 * (PHP 8.5, measured 31.10.2026), once per request.
 *
 * A file this WordPress does not have is skipped: an older or a newer core,
 * and the stand-ins of the tests, which have only some of them.
 *
 * Not reached from here: after_switch_theme runs in the next request, at
 * init (check_theme_switched()), where wp-admin has not loaded these files
 * yet either; the test requests of the guards are requests of their own.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Admin_Files
 */
class AB_MCP_Admin_Files {

	/**
	 * What wp-admin/includes/admin.php loads, in its order, without
	 * admin-filters.php.
	 */
	const FILES = array(
		'bookmark.php',
		'comment.php',
		'file.php',
		'image.php',
		'media.php',
		'import.php',
		'misc.php',
		'class-wp-privacy-policy-content.php',
		'options.php',
		'plugin.php',
		'post.php',
		'class-wp-screen.php',
		'screen.php',
		'taxonomy.php',
		'template.php',
		'class-wp-list-table.php',
		'class-wp-list-table-compat.php',
		'list-table.php',
		'theme.php',
		'privacy-tools.php',
		'class-wp-privacy-requests-table.php',
		'class-wp-privacy-data-export-requests-list-table.php',
		'class-wp-privacy-data-removal-requests-list-table.php',
		'user.php',
		'class-wp-site-icon.php',
		'update.php',
		'deprecated.php',
	);

	/** What admin.php loads on a network as well, without ms-admin-filters.php. */
	const NETWORK_FILES = array(
		'ms.php',
		'ms-deprecated.php',
	);

	/**
	 * Load them. Called right before a routine of another plugin or theme
	 * may run, never when this plugin loads.
	 */
	public static function load() {
		$files = self::FILES;
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$files = array_merge( $files, self::NETWORK_FILES );
		}
		foreach ( $files as $file ) {
			$path = ABSPATH . 'wp-admin/includes/' . $file;
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	}
}
