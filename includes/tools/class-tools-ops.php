<?php
/**
 * Operations tools: cron scheduling, transients, a site-health summary, dashboard
 * counts, and database maintenance.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-tools-base.php';

/**
 * Class AB_MCP_Tools_Ops
 */
class AB_MCP_Tools_Ops extends AB_MCP_Tools_Base {

	/**
	 * Register tools.
	 *
	 * @param AB_MCP_Tool_Registry $r Registry.
	 */
	public static function register( AB_MCP_Tool_Registry $r ) {

		$r->register(
			'wp_site_health',
			array(
				'description' => 'Get a short health summary: PHP version and whether it is 7.4 or newer, WordPress version, HTTPS, WP_DEBUG, WP_MEMORY_LIMIT, persistent object cache, multisite, whether file changes are disabled (DISALLOW_FILE_MODS), and the counts of pending updates from WordPress\' last update check. Needs manage_options. Read-only; takes no arguments, runs none of the Site Health tests of wp-admin and makes no remote request. wp_get_update_status names the pending updates; wp_site_info lists versions, theme and plugins.',
				'capability'  => 'manage_options',
				'callback'    => array( __CLASS__, 'site_health' ),
			)
		);

		$r->register(
			'wp_dashboard_counts',
			array(
				'description' => 'Get content counts of this site (on a multisite network: the current site): for each post type shown in wp-admin, its published, draft, pending, private and trashed entries (scheduled ones are not counted); users in total and per role; comments approved, moderated (pending), spam and trash; and the number of media files with the usual status inherit (private files are not counted). Needs manage_options. Read-only; takes no arguments. The entries themselves are listed by wp_list_posts, wp_list_comments and wp_list_media.',
				'capability'  => 'manage_options',
				'callback'    => array( __CLASS__, 'dashboard_counts' ),
			)
		);

	}

	/* --------------------------------------------------------------- cron */

	/* --------------------------------------------------------------- health */

	/**
	 * Site health summary.
	 *
	 * @return array
	 */
	public static function site_health() {
		global $wp_version;
		$updates = array();
		$upd_file = ABSPATH . 'wp-admin/includes/update.php';
		if ( is_readable( $upd_file ) ) {
			require_once $upd_file;
			if ( function_exists( 'wp_get_update_data' ) ) {
				$data    = wp_get_update_data();
				$updates = isset( $data['counts'] ) ? $data['counts'] : array();
			}
		}
		return array(
			'php_version'         => PHP_VERSION,
			'php_supported'       => version_compare( PHP_VERSION, '7.4', '>=' ),
			'wordpress_version'   => $wp_version,
			'https'               => is_ssl(),
			'debug_mode'          => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'memory_limit'        => WP_MEMORY_LIMIT,
			'persistent_cache'    => wp_using_ext_object_cache(),
			'multisite'           => is_multisite(),
			'file_mods_disabled'  => defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS,
			'pending_updates'     => $updates,
		);
	}

	/**
	 * Dashboard counts.
	 *
	 * @return array
	 */
	public static function dashboard_counts() {
		$posts = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'names' ) as $type ) {
			$c = wp_count_posts( $type );
			$posts[ $type ] = array(
				'publish' => isset( $c->publish ) ? (int) $c->publish : 0,
				'draft'   => isset( $c->draft ) ? (int) $c->draft : 0,
				'pending' => isset( $c->pending ) ? (int) $c->pending : 0,
				'private' => isset( $c->private ) ? (int) $c->private : 0,
				'trash'   => isset( $c->trash ) ? (int) $c->trash : 0,
			);
		}
		$users    = count_users();
		$comments = wp_count_comments();
		$media    = wp_count_posts( 'attachment' );

		return array(
			'posts'    => $posts,
			'users'    => array(
				'total' => isset( $users['total_users'] ) ? (int) $users['total_users'] : 0,
				'roles' => isset( $users['avail_roles'] ) ? $users['avail_roles'] : array(),
			),
			'comments' => array(
				'approved'  => (int) $comments->approved,
				'moderated' => (int) $comments->moderated,
				'spam'      => (int) $comments->spam,
				'trash'     => (int) $comments->trash,
			),
			'media'    => isset( $media->inherit ) ? (int) $media->inherit : 0,
		);
	}

	/* --------------------------------------------------------------- database */

}
