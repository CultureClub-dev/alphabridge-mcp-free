<?php
/**
 * What the takeover never compares and never writes (Z8, decisions 11 and
 * 21 of the plan of 01.11.2026; concept «Echtes Staging», section 3): the
 * objects of group B, and the parts of an object that WordPress or this
 * plugin write on each site by themselves.
 *
 * Every entry stands here because WordPress (7.1.3) or this plugin writes
 * it on its own; the test of each entry names where
 * (tests/fixtures/sync-reasons.php, checked against the core in the
 * integration suite). An object type that is neither excluded here nor
 * given to a kind in AB_MCP_Sync_Kinds::OWNERSHIP is locked there.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Sync_Exclusions
 */
final class AB_MCP_Sync_Exclusions {

	/**
	 * Post types of group B: revisions (B7, made on every update), oEmbed
	 * caches (B5), the requests for personal data (legal records).
	 */
	const POST_TYPES = array( 'revision', 'oembed_cache', 'user_request' );

	/**
	 * Options of group B: the identity of the site (B6: its addresses, its
	 * visibility, its administrator's address, where it keeps its uploads),
	 * the instances of WP-Cron (B5), what WordPress works out or notes for
	 * itself (the rewrite rules, recently activated plugins and edited
	 * files, the uninstall hooks of plugins, the database version, whether
	 * the site is fresh, the end of the term splitting, the next check of
	 * the administrator's address, the mails about failed updates, the
	 * plugins the core deactivated).
	 */
	const OPTIONS = array( 'siteurl', 'home', 'blog_public', 'admin_email', 'cron', 'rewrite_rules', 'recently_activated', 'recently_edited', 'uninstall_plugins', 'db_version', 'initial_db_version', 'fresh_site', 'upload_path', 'upload_url_path', 'finished_splitting_shared_terms', 'admin_email_lifespan', 'auto_plugin_theme_update_emails', 'wp_force_deactivated_plugins' );

	/**
	 * Option names that begin so: transients (B5) and the options of this
	 * plugin (B6: connections, tokens, the undo index, the pair, these
	 * tables' version).
	 */
	const OPTION_PREFIXES = array( '_transient_', '_site_transient_', 'ab_mcp_' );

	/**
	 * Option names that end so: the locks of WP_Upgrader::create_lock() (B5).
	 */
	const OPTION_SUFFIXES = array( '.lock' );

	/**
	 * Post meta that records an object's life on one site rather than its
	 * content: who edits it now and who did last, its former slugs and
	 * dates, the slug it had before the trash, the enclosure, pingback and
	 * trackback jobs not yet run.
	 */
	const POST_META_KEYS = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_desired_post_slug', '_encloseme', '_pingme', '_trackbackme' );

	/**
	 * Post meta keys that begin so: the state the trash keeps, oEmbed caches
	 * kept on the post (B5), this plugin's own records (B6).
	 */
	const POST_META_PREFIXES = array( '_wp_trash_meta_', '_oembed_', '_ab_mcp_' );

	/**
	 * Columns WordPress writes by itself, never compared: the id (each side
	 * has its own ID space), the guid (the address at the time of the
	 * insert), the time of the last change, the counters of comments and
	 * terms, the pingback queue.
	 */
	const COLUMNS = array(
		'posts'         => array( 'ID', 'guid', 'post_modified', 'post_modified_gmt', 'comment_count', 'to_ping', 'pinged' ),
		'term_taxonomy' => array( 'count' ),
	);

	/**
	 * @param string $post_type Post type.
	 * @return bool Whether it is of group B.
	 */
	public static function post_type( $post_type ) {
		return in_array( (string) $post_type, self::POST_TYPES, true );
	}

	/**
	 * @param string $name Option name, exactly as stored.
	 * @return bool Whether it is of group B.
	 */
	public static function option( $name ) {
		$name = (string) $name;
		return in_array( $name, self::OPTIONS, true ) || self::starts( $name, self::OPTION_PREFIXES ) || self::ends( $name, self::OPTION_SUFFIXES );
	}

	/**
	 * @param string $key Meta key of a post, exactly as stored.
	 * @return bool Whether it is never compared or written.
	 */
	public static function post_meta( $key ) {
		$key = (string) $key;
		return in_array( $key, self::POST_META_KEYS, true ) || self::starts( $key, self::POST_META_PREFIXES );
	}

	/**
	 * @param string $table  Table without the prefix (posts, term_taxonomy).
	 * @param string $column Column.
	 * @return bool Whether WordPress writes it by itself.
	 */
	public static function column( $table, $column ) {
		return isset( self::COLUMNS[ $table ] ) && in_array( (string) $column, self::COLUMNS[ $table ], true );
	}

	/**
	 * @param string   $name     Name.
	 * @param string[] $prefixes Prefixes.
	 * @return bool
	 */
	private static function starts( $name, array $prefixes ) {
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string   $name     Name.
	 * @param string[] $suffixes Suffixes.
	 * @return bool
	 */
	private static function ends( $name, array $suffixes ) {
		foreach ( $suffixes as $suffix ) {
			if ( strlen( $name ) > strlen( $suffix ) && substr( $name, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		return false;
	}
}
