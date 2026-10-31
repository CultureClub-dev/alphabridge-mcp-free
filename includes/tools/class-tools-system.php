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

		// The connection check of the settings page (AB_MCP_Connection_Doctor),
		// for an assistant that is connected and helps with a second app.
		// Read-only by name (AB_MCP_Tool_Registry::is_read_only()).
		$r->register(
			'wp_connection_doctor',
			array(
				'description' => 'Check what stops an app from connecting to this site and how to fix it: HTTPS, the permalinks, whether the MCP endpoint answers a request without a token with its own 401 or something in front of the site answers instead (a ModSecurity rule, Cloudflare, LiteSpeed, Wordfence, a plugin that restricts the REST API, an HTTP login), whether the server passes the Authorization header on to WordPress, the two sign-in descriptions below /.well-known/ (resource and authorization server, each checked to be this plugin\'s), the firewall test of the direct sign-in, whether the site is behind Cloudflare, and active plugins known to restrict the REST API. Every request goes from the site to itself, the endpoint asked without the HTTP login of the current request; so a block that applies only to requests from outside, such as Cloudflare\'s bot rules for AI crawlers, is not seen; the result says so in limits. Returns checks: [{id, status (ok, warn, fail, info or skip), title, finding, steps}], summary (the count per status and one line), checked_at (UTC, ISO 8601) and limits. Changes nothing on the site and keeps nothing, the finding of the firewall test included. Needs manage_options; takes no arguments.',
				'capability'  => 'manage_options',
				'callback'    => array( 'AB_MCP_Connection_Doctor', 'tool' ),
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

		// Free since 4.7.0, before in AlphaBridge MCP Pro only; Pro's copy of
		// the two names (AB_MCP_Tools_System_Pro) registers over these.
		$r->register(
			'wp_get_cron',
			array(
				'description' => 'List the WP-Cron events scheduled on this site, soonest first: per event hook, next_run (UTC, ISO 8601) and schedule (the recurrence name, or false for a single event). Read-only; hook arguments are not included. count is the total number of events, events holds at most the first 200. Useful to check whether a job runs and when. Requires manage_options.',
				'capability'  => 'manage_options',
				'callback'    => array( __CLASS__, 'cron' ),
			)
		);

		$r->register(
			'wp_get_error_log',
			array(
				'description' => 'Return the last lines of wp-content/debug.log, where WordPress writes PHP errors, warnings and notices when WP_DEBUG_LOG is on, for diagnosing failures. Read-only; returns available, lines (the count returned) and log (the lines as one text). Without a readable file there it answers available=false with a message, not an error; a log written to another path is not read. The log can hold file paths, queries and data plugins wrote into it; credentials in it read as [REDACTED]: the values of pairs such as password=…, token=… or api_key=… and of Authorization headers, such pairs in JSON at any level of escaping, entries under such names in PHP dumps (print_r, var_dump, var_export), private keys in PEM form and Bearer tokens. Other free text stays as it is; redacted then names log. Requires manage_options and runs only with write access on.',
				'capability'  => 'manage_options',
				// Mighty: a log holds paths, queries and whatever plugins wrote
				// into it, which the site mode Read keeps closed until Full.
				'dangerous'   => true,
				// Mighty since 4.6.0 in Pro, an ordinary tool before: a switch saved
				// before the site mode reads as for one (AB_MCP_Settings::is_tool_enabled()).
				'mighty_since' => '4.6.0',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'lines' => array( 'type' => 'integer', 'description' => 'How many trailing lines to return, 1 to 500 (values outside are clamped). Default 100.' ) ),
				),
				'callback'    => array( __CLASS__, 'error_log' ),
			)
		);

		// Free since 4.9.0, before in Pro only; Pro registers its own over them.
		$r->register(
			'wp_clear_cache',
			array(
				'description' => 'Flush the WordPress object cache, OPcache if available, and the whole page cache of the supported caching plugins present (LiteSpeed Cache, WP Super Cache, W3 Total Cache, WP Fastest Cache, SiteGround Speed Optimizer, Breeze, Nginx Helper, WP Rocket, Cache Enabler, WP-Optimize, Hummingbird). Suited to making a change visible that a cache still serves in its old form; expired transients are removed by wp_cleanup_transients. page_cache_purged lists the ones asked to purge; a cache none of them drives (host, CDN) is not reached. On a multisite network a plugin that can only empty every site at once runs only for a user who holds the network right that plugin asks for; page_cache_skipped names it and why. Returns object_cache_flushed, opcache_reset and page_cache_purged, plus page_cache_failed and page_cache_skipped where a purge failed or was left out. Only cached copies go: pages and queries are built afresh on their next request, so the site can answer slower for a short while. Requires manage_options; no undo point is recorded.',
				'capability'  => 'manage_options',
				'dangerous'   => true,
				'callback'    => array( __CLASS__, 'clear_cache' ),
			)
		);

		$r->register(
			'wp_flush_rewrite_rules',
			array(
				'description' => 'Regenerate the permalink (rewrite) rules stored in the database; it helps when posts, custom post types or taxonomies answer 404 after a structural change. A soft flush: the .htaccess file is not rewritten, and the permalink structure itself stays as set (Settings › Permalinks). Returns flushed=true. Requires manage_options.',
				'capability'  => 'manage_options',
				'dangerous'   => true,
				'callback'    => array( __CLASS__, 'flush_rewrite' ),
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

	/**
	 * Cron events.
	 *
	 * @return array
	 */
	public static function cron() {
		$crons = _get_cron_array();
		$out   = array();
		if ( is_array( $crons ) ) {
			foreach ( $crons as $timestamp => $hooks ) {
				foreach ( $hooks as $hook => $events ) {
					foreach ( $events as $event ) {
						$out[] = array(
							'hook'     => $hook,
							'next_run' => gmdate( 'c', $timestamp ),
							'schedule' => isset( $event['schedule'] ) ? $event['schedule'] : 'once',
						);
					}
				}
			}
		}
		return array(
			'count'  => count( $out ),
			'events' => array_slice( $out, 0, 200 ),
		);
	}

	/**
	 * Debug log tail.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function error_log( $a ) {
		$log = WP_CONTENT_DIR . '/debug.log';
		if ( ! is_readable( $log ) ) {
			return array(
				'available' => false,
				'message'   => __( 'No readable debug.log. Enable WP_DEBUG_LOG to capture errors.', 'alphabridge-mcp' ),
			);
		}
		$lines = self::clamp( self::i( $a, 'lines', 100 ), 1, 500 );
		$data  = file( $log, FILE_IGNORE_NEW_LINES ); // phpcs:ignore
		$tail  = is_array( $data ) ? array_slice( $data, -$lines ) : array();
		return array(
			'available' => true,
			'lines'     => count( $tail ),
			'log'       => implode( "\n", $tail ),
		);
	}

	/**
	 * Clear caches.
	 *
	 * @return array
	 */
	public static function clear_cache() {
		$object  = wp_cache_flush();
		$opcache = false;
		if ( function_exists( 'opcache_reset' ) ) {
			$opcache = @opcache_reset();
		}
		// The object cache alone leaves every cached page as it was, and a
		// page is what a visitor sees.
		$pages = AB_MCP_Page_Cache::purge_site();
		$out   = array(
			// What the cache backend answered: a flush it refused is no success.
			'object_cache_flushed' => (bool) $object,
			'opcache_reset'        => (bool) $opcache,
			'page_cache_purged'    => $pages['purged'],
		);
		if ( ! empty( $pages['failed'] ) ) {
			$out['page_cache_failed'] = $pages['failed'];
		}
		if ( ! empty( $pages['skipped'] ) ) {
			$out['page_cache_skipped'] = $pages['skipped'];
		}
		return $out;
	}

	/**
	 * Flush rewrite rules.
	 *
	 * @return array
	 */
	public static function flush_rewrite() {
		flush_rewrite_rules( false );
		return array( 'flushed' => true );
	}
}
