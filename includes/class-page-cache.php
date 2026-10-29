<?php
/**
 * Page caches of caching plugins: tell them which pages are stale.
 *
 * WordPress keeps no page cache of its own and has no shared way to empty one.
 * The caching plugins keep finished HTML on disk, in the web server or at the
 * host, and empty it from their own hooks on the WordPress events they watch.
 * A restore that writes around those events, or a flush that reaches only the
 * object cache, leaves the old HTML in place: visitors see it until it
 * expires, and to the person who just ran an undo it looks as if the undo did
 * nothing. So each plugin is called through the purge entry it publishes
 * itself, and only where that entry exists on this site.
 *
 * The entries were read in each plugin's own source, not guessed: trunk on
 * plugins.svn.wordpress.org, WP Rocket on GitHub (wp-media/wp-rocket,
 * develop), on 1 October 2026; the version noted per entry is the stable
 * tag at that time, for WP Rocket the develop version. A plugin that is not
 * listed is not reached; a site can add its own (a host cache, a CDN) through
 * the filter below.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Page_Cache
 */
class AB_MCP_Page_Cache {

	/**
	 * Drop WordPress's cached copies of these posts and ask every caching
	 * plugin present to purge their pages.
	 *
	 * @param int[] $post_ids Post ids.
	 * @return array{purged: string[], failed: string[]} The caches asked to purge, and those whose purge threw.
	 */
	public static function purge_posts( array $post_ids ) {
		$ids = array();
		foreach ( $post_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		$out = array(
			'purged' => array(),
			'failed' => array(),
		);
		if ( empty( $ids ) ) {
			return $out;
		}
		$existing = array();
		foreach ( $ids as $id ) {
			// WP Super Cache and WP Rocket purge from their own clean_post_cache
			// hooks; the calls below then find the post done and return.
			try {
				clean_post_cache( $id );
			} catch ( \Throwable $e ) {
				$out['failed'][] = 'clean_post_cache';
			}
			// A post that is gone has no page left to purge, and some plugins
			// read the post without checking that there is one.
			if ( get_post( $id ) ) {
				$existing[] = $id;
			}
		}
		if ( empty( $existing ) ) {
			$out['failed'] = array_values( array_unique( $out['failed'] ) );
			return $out;
		}
		$calls = array();
		foreach ( $existing as $id ) {
			$calls[] = array( $id );
		}
		$res = self::run( 'post', $calls );
		return array(
			'purged' => $res['purged'],
			'failed' => array_values( array_unique( array_merge( $out['failed'], $res['failed'] ) ) ),
		);
	}

	/**
	 * Ask every caching plugin present to purge all pages of this site. On a
	 * network a plugin whose whole purge empties every site at once purges
	 * this site's folder alone where it keeps one; otherwise it runs only for
	 * a user that plugin itself lets do so, and else is left out and named
	 * under 'skipped' with the reason.
	 *
	 * @return array{purged: string[], failed: string[], skipped?: array<string,string>}
	 */
	public static function purge_site() {
		return self::run( 'site', array( array() ) );
	}

	/**
	 * Call each purger once per argument list. A purger that throws is
	 * reported and the others still run: a stale page is no reason to lose
	 * the answer of the call that changed it.
	 *
	 * @param string $scope 'post' or 'site'.
	 * @param array  $calls Argument lists.
	 * @return array{purged: string[], failed: string[], skipped?: array<string,string>}
	 */
	private static function run( $scope, array $calls ) {
		$found = self::purgers( $scope );
		$out   = array(
			'purged' => array(),
			'failed' => $found['failed'],
		);
		foreach ( $found['purgers'] as $slug => $purge ) {
			try {
				foreach ( $calls as $args ) {
					call_user_func_array( $purge, $args );
				}
				$out['purged'][] = $slug;
			} catch ( \Throwable $e ) {
				$out['failed'][] = $slug;
			}
		}
		if ( ! empty( $found['skipped'] ) ) {
			$out['skipped'] = $found['skipped'];
		}
		return $out;
	}

	/**
	 * The purgers present on this site, by plugin slug: for 'post' each takes
	 * a post id, for 'site' nothing. Asked at the moment of the purge, so a
	 * plugin that registers its hooks late still counts.
	 *
	 * @param string $scope 'post' or 'site'.
	 * @return array{purgers: array<string,callable>, failed: string[], skipped: array<string,string>}
	 */
	private static function purgers( $scope ) {
		$skipped = array();
		$failed  = array();
		$all     = 'post' === $scope ? self::post_purgers() : self::site_purgers( $skipped );
		try {
			/**
			 * Filters the page-cache purgers, by slug. For 'post' a purger takes
			 * a post id, for 'site' no argument. Add one for a cache this list
			 * does not know (a host cache, a CDN), or unset one to leave it out.
			 * A purger left out on a network (null) can be put back here.
			 * The name is the one it had in Pro, where this came from until
			 * 4.9.0, so a callback written for Pro keeps working.
			 *
			 * @param array<string,callable|null> $all   Purgers; null where the plugin is not present or left out.
			 * @param string                      $scope 'post' or 'site'.
			 */
			$filtered = apply_filters( 'ab_mcp_pro_page_cache_purgers', $all, $scope );
		} catch ( \Throwable $e ) {
			// The filter runs after the change it purges for: a broken one must
			// not cost that answer. The known caches still purge.
			$filtered = $all;
			$failed[] = 'ab_mcp_pro_page_cache_purgers';
		}
		$out = array();
		foreach ( (array) $filtered as $slug => $purge ) {
			if ( is_string( $slug ) && '' !== $slug && is_callable( $purge ) ) {
				$out[ $slug ] = $purge;
			}
		}
		return array(
			'purgers' => $out,
			'failed'  => $failed,
			'skipped' => array_diff_key( $skipped, $out ),
		);
	}

	/**
	 * Per caching plugin, what purges the pages of one post — null where the
	 * plugin's entry is not there.
	 *
	 * @return array<string,callable|null>
	 */
	private static function post_purgers() {
		$nginx_purger = isset( $GLOBALS['nginx_purger'] ) ? $GLOBALS['nginx_purger'] : null;
		return array(
			// LiteSpeed Cache 7.9.1, src/api.cls.php: public purge actions.
			'litespeed-cache'         => has_action( 'litespeed_purge_post' ) ? static function ( $id ) {
				do_action( 'litespeed_purge_post', $id );
			} : null,
			// WP Super Cache 3.1.4, wp-cache-phase2.php.
			'wp-super-cache'          => function_exists( 'wp_cache_post_change' ) ? 'wp_cache_post_change' : null,
			// W3 Total Cache 2.10.7, w3-total-cache-api.php.
			'w3-total-cache'          => function_exists( 'w3tc_flush_post' ) ? 'w3tc_flush_post' : null,
			// WP Fastest Cache 1.5.2, wpFastestCache.php.
			'wp-fastest-cache'        => function_exists( 'wpfc_clear_post_cache_by_id' ) ? 'wpfc_clear_post_cache_by_id' : null,
			// SiteGround Speed Optimizer 7.8.3, helpers/helpers.php. It takes a
			// URL; without one it purges the home page (with file caching on,
			// everything), so where a post has no URL it is not called.
			'sg-cachepress'           => function_exists( 'sg_cachepress_purge_cache' ) ? static function ( $id ) {
				$url = get_permalink( $id );
				if ( is_string( $url ) && '' !== $url ) {
					sg_cachepress_purge_cache( $url );
				}
			} : null,
			// Breeze 2.6.0, inc/cache/purge-cache.php. The hook name is generic,
			// so Breeze's class must be there too.
			'breeze'                  => ( class_exists( 'Breeze_PurgeCache' ) && has_action( 'purge_post_cache' ) ) ? static function ( $id ) {
				do_action( 'purge_post_cache', $id );
			} : null,
			// Nginx Helper 2.4.1 publishes no per-post action; this is the
			// object and method its own status-change hook calls
			// (admin/class-nginx-helper-admin.php). It honours the plugin's
			// "enable purge" setting itself.
			'nginx-helper'            => ( is_object( $nginx_purger ) && method_exists( $nginx_purger, 'purge_post' ) ) ? array( $nginx_purger, 'purge_post' ) : null,
			// WP Rocket 3.23.5-beta1 (develop), inc/common/purge.php.
			'wp-rocket'               => function_exists( 'rocket_clean_post' ) ? 'rocket_clean_post' : null,
			// Cache Enabler 1.8.17, inc/cache_enabler.class.php.
			'cache-enabler'           => has_action( 'cache_enabler_clear_page_cache_by_post' ) ? static function ( $id ) {
				do_action( 'cache_enabler_clear_page_cache_by_post', $id );
			} : null,
			// WP-Optimize 4.7.0, cache/class-wpo-page-cache.php (named in its readme).
			'wp-optimize'             => ( class_exists( 'WPO_Page_Cache' ) && method_exists( 'WPO_Page_Cache', 'delete_single_post_cache' ) ) ? array( 'WPO_Page_Cache', 'delete_single_post_cache' ) : null,
			// Hummingbird 3.21.2, core/modules/class-page-cache.php.
			'hummingbird-performance' => has_action( 'wphb_clear_page_cache' ) ? static function ( $id ) {
				do_action( 'wphb_clear_page_cache', $id );
			} : null,
		);
	}

	/**
	 * Per caching plugin, what purges every page of this site — null where
	 * the plugin's entry is not there, or where on a network it would empty
	 * every site and the user may not (then named in $skipped).
	 *
	 * @param array<string,string> $skipped Filled with slug => reason.
	 * @return array<string,callable|null>
	 */
	private static function site_purgers( array &$skipped ) {
		// WP-Optimize and Hummingbird keep pages in folders by host and path,
		// so the pages of one site of a network lie under the folder of its
		// home URL. On a sub-directory network the main site's folder holds
		// every other site's as well, so there it is not one site alone.
		$own_folder = is_multisite() && ( is_subdomain_install() || ! is_main_site() );
		$wpo_own    = ( $own_folder && class_exists( 'WPO_Page_Cache' ) && method_exists( 'WPO_Page_Cache', 'delete_cache_by_url' ) )
			? static function () {
				WPO_Page_Cache::delete_cache_by_url( home_url( '/' ), true );
			}
			: null;
		$hb         = static function () {
			do_action( 'wphb_clear_page_cache' );
		};
		$hb_own     = $own_folder
			? static function () use ( $hb ) {
				self::without_referer( $hb );
			}
			: null;
		return array(
			// Also empties LiteSpeed's CSS/JS, object and opcode caches: the
			// only whole-site purge the plugin publishes.
			'litespeed-cache'         => has_action( 'litespeed_purge_all' ) ? static function () {
				do_action( 'litespeed_purge_all' );
			} : null,
			// 0 is every site of the install, so a network passes its own.
			'wp-super-cache'          => function_exists( 'wp_cache_clear_cache' ) ? static function () {
				wp_cache_clear_cache( is_multisite() ? get_current_blog_id() : 0 );
			} : null,
			// The page cache; w3tc_flush_all() would also empty W3TC's database, object, minify and opcode caches.
			'w3-total-cache'          => function_exists( 'w3tc_flush_posts' ) ? 'w3tc_flush_posts' : null,
			'wp-fastest-cache'        => function_exists( 'wpfc_clear_all_cache' ) ? 'wpfc_clear_all_cache' : null,
			'sg-cachepress'           => function_exists( 'sg_cachepress_purge_everything' ) ? 'sg_cachepress_purge_everything' : null,
			'breeze'                  => has_action( 'breeze_clear_all_cache' ) ? static function () {
				do_action( 'breeze_clear_all_cache' );
			} : null,
			// Nginx Helper 2.4.1 empties its whole cache (by default the cache
			// folder, class-fastcgi-purger.php purge_all()), on a network every site's;
			// its own purge asks for the right "Nginx Helper | Purge cache"
			// (class-nginx-helper-admin.php purge_all()).
			'nginx-helper'            => has_action( 'rt_nginx_helper_purge_all' ) ? self::network_gate(
				'nginx-helper',
				'Nginx Helper | Purge cache',
				static function () {
					do_action( 'rt_nginx_helper_purge_all' );
				},
				null,
				$skipped
			) : null,
			'wp-rocket'               => function_exists( 'rocket_clean_domain' ) ? 'rocket_clean_domain' : null,
			'cache-enabler'           => has_action( 'cache_enabler_clear_site_cache' ) ? static function () {
				do_action( 'cache_enabler_clear_site_cache' );
			} : null,
			// WP-Optimize 4.7.0: wpo_cache_flush() deletes wp-content/cache/wpo-cache,
			// one folder for every site of a network; WP-Optimize itself lets
			// only manage_network_options purge there (capability_required()).
			'wp-optimize'             => function_exists( 'wpo_cache_flush' ) ? self::network_gate( 'wp-optimize', 'manage_network_options', 'wpo_cache_flush', $wpo_own, $skipped ) : null,
			// Without a post id Hummingbird 3.21.2 clears its whole cache; on a
			// network the folder of this site, but every site's when the
			// request's referer is the network admin (Page_Cache::clear_cache())
			// — a header the client sets as it likes. For the network
			// Hummingbird asks for manage_network.
			'hummingbird-performance' => has_action( 'wphb_clear_page_cache' ) ? self::network_gate( 'hummingbird-performance', 'manage_network', $hb, $hb_own, $skipped ) : null,
		);
	}

	/**
	 * The purge for a plugin whose whole purge on a network empties every
	 * site at once: off a network that whole purge; on a network the purge
	 * of this site alone where the plugin has one, else the whole purge for a
	 * user with the plugin's own right for it, else nothing, with the reason
	 * in $skipped.
	 *
	 * @param string               $slug     Plugin slug.
	 * @param string               $cap      The right the plugin itself asks for its network-wide purge.
	 * @param callable             $whole    The whole purge.
	 * @param callable|null        $own_site The purge of this site alone, if the plugin has one.
	 * @param array<string,string> $skipped  Filled with slug => reason.
	 * @return callable|null
	 */
	private static function network_gate( $slug, $cap, $whole, $own_site, array &$skipped ) {
		if ( ! is_multisite() ) {
			return $whole;
		}
		// Even a network admin who runs wp_undo on one site wants that site
		// purged, not every site's cache rebuilt.
		if ( is_callable( $own_site ) ) {
			return $own_site;
		}
		if ( current_user_can( $cap ) ) {
			return $whole;
		}
		$skipped[ $slug ] = sprintf(
			/* translators: 1: plugin slug, 2: capability */
			__( 'On this network %1$s can only empty the page cache of every site at once, and allows that only with the right "%2$s", which this account lacks. A network admin can do it with wp_clear_cache.', 'alphabridge-mcp' ),
			$slug,
			$cap
		);
		return null;
	}

	/**
	 * Run a purge with no HTTP referer in sight, then put it back.
	 *
	 * @param callable $purge Purge.
	 */
	private static function without_referer( $purge ) {
		$had = isset( $_SERVER['HTTP_REFERER'] );
		$ref = $had ? $_SERVER['HTTP_REFERER'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only put back as it was.
		unset( $_SERVER['HTTP_REFERER'] );
		try {
			call_user_func( $purge );
		} finally {
			if ( $had ) {
				$_SERVER['HTTP_REFERER'] = $ref;
			}
		}
	}
}
