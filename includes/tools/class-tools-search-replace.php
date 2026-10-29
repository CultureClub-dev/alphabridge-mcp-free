<?php
/**
 * wp_search_replace: search and replace across the site's stored data, safe
 * for serialized and JSON values, over posts, postmeta, options and terms,
 * with a per-table dry-run preview; page-builder pages through their writer
 * or with an undo point. Free since 4.10.0, before in Pro only (Pro keeps its
 * own copy, AB_MCP_Tools_Search_Bulk_Pro, for older cores). See
 * class-replace-engine.php for the value-level replacement rules.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'AB_MCP_Tools_Base', false ) ) {
	require_once __DIR__ . '/class-tools-base.php';
}
require_once dirname( __DIR__ ) . '/class-replace-engine.php';
require_once dirname( __DIR__ ) . '/class-kses-guard.php';
if ( ! class_exists( 'AB_MCP_Option_Guard', false ) ) {
	require_once dirname( __DIR__ ) . '/class-option-guard.php';
}
require_once dirname( __DIR__ ) . '/builders/write/load.php';

/**
 * Class AB_MCP_Tools_Search_Replace
 */
class AB_MCP_Tools_Search_Replace extends AB_MCP_Tools_Base {

	const SAMPLE_CAP = 50;    // Max preview entries returned per table.
	const WINDOW      = 10000; // Ids per query: a fixed span, never a count of matching rows — the work of one query is bounded.
	const MAX_WINDOWS = 500;   // Windows per scan and call: 5 000 000 ids — the work of one call is bounded; `next`/`after` continue beyond.
	const SCAN_KEYS   = array( 'posts', 'postmeta', 'options', 'term_names', 'term_descriptions', 'termmeta', 'builders' );
	const SCOPES      = array( 'text', 'raw' );
	/**
	 * Page-builder pages one call changes through their writer (scope text)
	 * or with an undo point (scope raw); next/after carry on behind them.
	 * Each such page is a part of the call's undo point, and a page builder's
	 * data runs to 100 KB a page (plan review S4: 25 pages, with a cursor).
	 */
	const BUILDER_POSTS = 25;
	/** Posts one call may name in post_ids. */
	const POST_IDS_MAX = 500;
	const SAMPLE_CHARS  = 300; // Characters of a field value a builder sample shows before and after.
	const NOTE_CAP    = 10;
	const SLUG_CAP    = 200;  // Slug changes listed per table; the rest counted in slug_changes_omitted.
	const TERM_NAME_CHARS = 200; // What wp_terms.name holds (characters, the column is utf8mb4); MySQL cuts a longer value without a word.   // Notes per table through add_note(); what does not fit is counted in notes_omitted. A scan's own note about where it stopped is added directly and is never dropped.
	const BATCH       = 200;   // Rows per query inside a window: every row of the window is read, in pieces.
	/** Post fields WordPress runs through kses when it saves a post for an account without unfiltered_html (kses_init_filters()). */
	const KSES_COLUMNS = array( 'post_content', 'post_title', 'post_excerpt', 'post_content_filtered' );

	/**
	 * State of the running wp_search_replace call: flags, how each post is
	 * treated, the undo points, what the answer reports beyond the tables.
	 * Reset at the start of every call.
	 *
	 * @var array
	 */
	private static $run = array();

	/**
	 * Post types a text replacement must not rewrite: global styles and custom
	 * CSS need edit_css (unfiltered_html), font files and oEmbed caches are
	 * machine data, user requests are legal records. Revisions have their own
	 * flag.
	 */
	const INTERNAL_POST_TYPES = array( 'oembed_cache', 'user_request', 'custom_css', 'customize_changeset', 'wp_global_styles', 'wp_font_family', 'wp_font_face' );

	/**
	 * Taxonomies whose term NAMES bind things to code: the wp_theme term is the
	 * theme slug that ties block templates to the theme — a replacement that
	 * happens to hit it detaches every template — and wp_template_part_area is
	 * the fixed set of areas a template part can sit in. post_format is
	 * WordPress' own list ("Aside", "Gallery", …), registered with show_ui
	 * false, so not even an administrator renames it from the term screen.
	 * wp_pattern_category is NOT among them: its terms are ordinary categories
	 * a user creates and edits in the pattern screen.
	 */
	const INTERNAL_TAXONOMIES = array( 'wp_theme', 'wp_template_part_area', 'post_format' );

	/**
	 * Post meta WordPress writes through a function or a path of its own, each
	 * with checks this tool has no business skipping: update_attached_file()
	 * and wp_update_attachment_metadata() with their filters and path
	 * handling, set_post_thumbnail(), which takes an attachment that exists,
	 * and the post writer, which takes a template the theme has. Never
	 * rewritten here, although the direct reader may show them: in an id or a
	 * file name a replacement means another object, not another text.
	 */
	const META_KEYS_WITH_OWN_WRITER = array( '_wp_attached_file', '_wp_attachment_metadata', '_thumbnail_id', '_wp_page_template' );

	/**
	 * The credential-like meta keys the free core refuses, as data — the
	 * same two lists is_sensitive_meta_key() carries in code, so they can be
	 * turned into SQL. A key that is only skipped in PHP, after the prefilter
	 * compared the value, has already answered whether the value contains the
	 * needle: through the row count, the limit, the truncated flag. The
	 * exclusion therefore has to happen before any LIKE, against the name as
	 * PHP normalises it. The test proves the lists and the core function agree
	 * on a corpus of keys.
	 */
	const SENSITIVE_META_EXACT   = array( 'token', 'tokens', 'secret', 'secrets', 'password', 'passwords', 'passphrase', 'passphrases', 'passcode', 'passcodes', 'pwd', 'pwds', 'otp', 'otps', 'credential', 'credentials' );
	const SENSITIVE_META_NEEDLES = array( 'api_key', 'apikey', 'api-key', 'api_token', 'api_secret', 'access_key', 'access_token', 'secret_key', 'secret_token', 'private_key', 'client_secret', 'consumer_key', 'consumer_secret', 'refresh_token', 'auth_token', 'bearer_token', 'license_key', 'oauth', '_token', '_secret', '_password', 'passwd', 'smtp_pass' );

	/**
	 * Option names update_option() writes elsewhere (wp-includes/option.php,
	 * $deprecated_keys, WordPress 7.0.2). A stored row under the old name is
	 * read, but a write lands on the successor — over a current setting.
	 */
	const DEPRECATED_OPTION_NAMES = array(
		'blacklist_keys'    => 'disallowed_keys',
		'comment_whitelist' => 'comment_previously_approved',
	);

	/**
	 * Option names sanitize_option() treats by name (its switch cases,
	 * wp-includes/formatting.php, WordPress 7.0.2). update_option() runs that
	 * sanitiser for the exact name only: a stored look-alike such as
	 * "BlogName" is read by get_option('blogname') under the collation, but
	 * update_option('BlogName') skips the sanitiser blogname gets.
	 */
	const SANITIZED_OPTION_NAMES = array(
		'admin_email', 'banned_email_domains', 'blog_charset', 'blog_public', 'blogdescription', 'blogname', 'category_base',
		'close_comments_days_old', 'comment_max_links', 'comments_per_page', 'date_format', 'default_category',
		'default_comment_status', 'default_email_category', 'default_link_category', 'default_ping_status', 'default_role',
		'disallowed_keys', 'fileupload_maxk', 'gmt_offset', 'home', 'illegal_names', 'large_size_h', 'large_size_w',
		'limited_email_domains', 'mailserver_login', 'mailserver_pass', 'mailserver_port', 'mailserver_url',
		'medium_large_size_h', 'medium_large_size_w', 'medium_size_h', 'medium_size_w', 'moderation_keys', 'new_admin_email',
		'page_for_posts', 'page_on_front', 'permalink_structure', 'ping_sites', 'posts_per_page', 'posts_per_rss',
		'rss_excerpt_length', 'site_icon', 'siteurl', 'start_of_week', 'tag_base', 'thread_comments_depth', 'thumbnail_size_h',
		'thumbnail_size_w', 'time_format', 'timezone_string', 'upload_path', 'users_can_register', 'WPLANG',
	);

	/**
	 * Hook prefixes WordPress builds from an option's name (wp-includes/option.php):
	 * every hook under one of them names an option that is treated by name on
	 * the write or the read path.
	 */
	const OPTION_HOOK_PREFIXES = array(
		'sanitize_option_', 'pre_update_option_', 'update_option_', 'add_option_', 'pre_delete_option_', 'delete_option_',
		'pre_option_', 'default_option_', 'option_',
	);

	/**
	 * Register wp_search_replace.
	 *
	 * @param AB_MCP_Tool_Registry $r Registry.
	 */
	public static function register( AB_MCP_Tool_Registry $r ) {
		// The name as a literal: ToolNamesTest reads names from the source.
		$r->register(
			'wp_search_replace',
			array(
				'description' => 'Search and replace across the site\'s stored data, safe for serialized and JSON values; finding text without changing it is wp_search, one post is changed with wp_update_post. post_ids limits the call to the posts it names (posts, postmeta and page-builder pages); expected_count makes apply=true write only when the call finds exactly that many matching rows (totals.matched of the dry run), so nothing is written where the site changed since the preview. tables: posts (title, content, excerpt), postmeta, options, terms (names, descriptions, termmeta); default posts and postmeta. apply=false (default) is a dry run that returns a per-table preview; apply=true writes. Matching: serialized and JSON values are rewritten losslessly, and the CSS and caches Elementor builds from _elementor_data (element cache with the rendered HTML, Markdown cache) are reset. Plain (non-regex) mode also finds the encoded forms WordPress and page builders store (JSON-escaped such as https:\\/\\/…, block attributes such as &, HTML entities such as &amp;, and HTML inside block attributes), so "A & B" is found where a page stores "A &amp; B", and writes the replacement in the form it found, as JSON inside block attributes. A serialized value holding a PHP object, a Serializable or enum record or a reference is left untouched (skipped_object_values), so no class is restored from stored data. Scope "text" (default): a page-builder page that a writer here writes (write_via in wp_get_builder_layout) changes only through that writer, in the texts, links and images it may change, under the field rules of wp_update_builder_element; its post_content copy is left out (copy_only). Matches in locked places (code, HTML, CSS, shortcodes, forms, placeholders, global parts, page code), and on pages the writer refuses as a whole, stay and are counted in locked_matches; scope "raw" reaches them. Fields a writer leaves are named in notes. A page whose page builder has no writer here, and a text a writer finds in none of its fields where the site shows post_content (SeedProd), are replaced as text (as_text, builder_pages_without_writer); a builder that keeps its own copy writes it over that at its next save. For an account without unfiltered_html, a post that WordPress\'s kses filter would store differently (an SVG, a style or a script dropped, a block attribute changed, in any field it filters) is not written and is named in notes. Scope "raw": the stored data as text everywhere, page-builder data and page code included (page-builder data needs unfiltered_html); a copy in post_content_filtered (SeedProd) is not read, so only post_content changes there until the page builder writes its copy back at its next save, and warnings names such pages. Undo: page-builder pages get undo points under one undo_token (undo_pages lists them). For pages a writer changes it holds their page-builder data and its copies; for pages changed outside a writer and in scope "raw" also post_content and the meta keys the call rewrites on them, except the code fields a page builder locks (custom CSS, for example): an undo leaves those as changed, and warnings names them. Option rows the call changes get an undo point of their own (options_undo_token), recorded before they are written; posts without a page builder, titles, excerpts and terms are not in any undo point, and the answer says so. Per call, at most 25 pages change through writers (next.builders continues) and at most 25 in scope "raw"; of the pages scope "text" changes outside a writer, up to 25 get an undo point, and the others, or any whose undo point cannot be recorded, are written without one and named in warnings (stop_at_undo_cap, allow_without_undo). Never touched: trash, auto-drafts, revisions (unless exclude_revisions=false), protected options and option names the option guard refuses, transients, the guid column, credential-like meta keys, widget rows, internal post types and taxonomies; rows the account may not read or edit are passed over silently. Protected "_" meta keys are rewritten only for an account with manage_options and only when allow-listed (filter ab_mcp_pro_protected_meta_allowlist), never _wp_attached_file, _wp_attachment_metadata, _thumbnail_id or _wp_page_template; page-builder data (_elementor_data, panels_data, dslc_code, pagelayer-data, brizy, vcv-pageContent, mfn-page-items, tve_updated_post and more; filter ab_mcp_pro_markup_meta_keys) only for an account with unfiltered_html, on multisite a super admin. Writes go through the WordPress APIs (revisions created, serialization regenerated, caches invalidated), term names and descriptions through wp_update_term(). No slug is asked to change: a term shared by several taxonomies or with an empty stored slug is passed over, and slug_changes lists any slug a filter changed along with a name (term, old and new slug; up to 200, the rest in slug_changes_omitted). Paging: at most 5 000 000 ids per scan (10 000 per query, a tenth in regex mode; the terms table has three scans, the page-builder pages one, builders); next records where each scan stopped, and sent back as after it lets the next call continue without reading anything twice. Answer: per-table counts and previews; notes (up to 10 per table, notes_omitted the rest); warnings; skipped_object_values and skipped_unchecked_values (too large or too deep to walk: more than ' . AB_MCP_Replace_Engine::MAX_DEPTH . ' levels or ' . AB_MCP_Replace_Engine::MAX_LAYERS . ' nested serialized or JSON strings; or JSON stored in a form the encoder does not write, spaced for example, when the replacement holds a quote, a backslash or a control character or the result would no longer be JSON) count values, not rows, and the rest of such a row is still rewritten.',
				'open_world'  => true, // rewrites published posts and their fields by default.
				'capability'  => 'manage_options',
				'dangerous'   => true,
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'search' ),
					'properties' => array(
						'search'            => array( 'type' => 'string', 'description' => 'Text to find (or PCRE pattern without delimiters when regex=true).' ),
						'replace'           => array( 'type' => 'string', 'description' => 'Replacement text. Default "" (removes the match).' ),
						'tables'            => array( 'type' => 'array', 'description' => 'Tables to process: posts, postmeta, options, terms. Default ["posts","postmeta"].' ),
						'type'              => array( 'type' => 'string', 'description' => 'Post type filter for the posts table and the page-builder pages (postmeta is scanned for every post whose meta the account may read and edit); a call without the posts table ignores it (flags.type_ignored). Default any.' ),
						'apply'             => array( 'type' => 'boolean', 'description' => 'true writes the changes; false (default) returns the dry-run preview only.' ),
						'limit'             => array( 'type' => 'integer', 'description' => 'Max matched rows processed per table (1–2000). Default 200. When truncated=true, next, sent back as after, continues there; notes say why a scan stopped, and only where the row limit stopped it does a higher limit take more rows (page-builder pages stay at 25 per call: table builders, scope raw, stop_at_undo_cap). Rows the policy passes over do not count.' ),
						'after'             => array( 'type' => 'object', 'description' => 'The next object of a previous call (keys posts, postmeta, options, term_names, term_descriptions, termmeta, builders; each the id to continue behind). Default: start at the beginning of every table.' ),
						'exclude_revisions' => array( 'type' => 'boolean', 'description' => 'true (default) skips post revisions; false includes them.' ),
						'case_insensitive'  => array( 'type' => 'boolean', 'description' => 'Case-insensitive matching. Default false.' ),
						'regex'             => array( 'type' => 'boolean', 'description' => 'true treats search as a PCRE regex (no delimiters; slower: every row of the id budget is read and tested in PHP, without a SQL prefilter). Matches the stored bytes only, without the encoded forms. In scope text, a page-builder page whose writer does not apply a pattern to its fields is left as it is and named in notes, with the way: scope "raw". Default false.' ),
						'scope'             => array(
							'type'        => 'string',
							'enum'        => array( 'text', 'raw' ),
							'description' => 'text (default): page-builder pages that a writer of this version writes are changed only in the texts, links and images their writer may change; matches in locked places are counted in locked_matches; the page-builder pages it changes get an undo point (those changed outside a writer up to 25 per call; warnings names the pages beyond). raw: the stored data as text everywhere, page code included (page-builder data needs unfiltered_html; a copy a page builder keeps in post_content_filtered, as SeedProd does, is not read), with an undo point for every page-builder page.',
						),
						'allow_without_undo' => array( 'type' => 'boolean', 'description' => 'true writes a page-builder page whose undo point would be over the size limit, without a way back. Default false: such a page is not written. Any other failure to record the undo point still refuses the page. Applies to scope raw and to the pages a writer changes; in scope text a page changed outside a writer is written as before when its undo point cannot be recorded, and warnings names it.' ),
						'post_ids'          => array( 'type' => 'array', 'description' => 'Post ids (integers, at most 500): only these posts, their postmeta and, among page-builder pages, only these are scanned and written; a revision counts by its own id. Works with the tables posts and postmeta only. Default: every post the account may edit.' ),
						'expected_count'    => array( 'type' => 'integer', 'description' => 'With apply=true: the number of matching rows the call must find (totals.matched of the dry run with the same arguments); with another number nothing is written and the answer says what it found. Default: no check.' ),
						'stop_at_undo_cap'  => array( 'type' => 'boolean', 'description' => 'scope text: one call keeps undo points for up to 25 page-builder pages it changes outside a writer. Default false: the pages beyond them are written without one, as before, and warnings names them. true: the posts and postmeta scans stop before the first such page instead (truncated, with a note); next, sent back as after, continues there, so every page gets an undo point.' ),
					),
				),
				'callback'    => array( __CLASS__, 'search_replace' ),
			)
		);
	}

	/**
	 * A name column as the PHP rules see it: ASCII letters lower-cased, and
	 * everything PHP's trim() strips — space, tab, newline, carriage return,
	 * NUL, vertical tab — removed, anywhere rather than only at the ends. A
	 * superset of the PHP normalisation (strtolower/strtr + trim), so nothing
	 * a PHP rule refuses can slip past the SQL on account of a stray character
	 * or a letter case (" mailserver_pass", "\0secret" and, under a
	 * Turkish collation, LOWER('APIKEY') = 'apıkey'). REPLACE() matches
	 * case-sensitively and byte for byte, independent of any collation; LOWER()
	 * does not, so the case folding is spelled out letter by letter.
	 *
	 * @param string $col Column, e.g. pm.meta_key.
	 * @return string SQL expression.
	 */
	protected static function sql_name_normalized( $col ) {
		$expr = $col;
		foreach ( array( 32, 9, 10, 13, 0, 11 ) as $code ) {
			$expr = "REPLACE({$expr}, CHAR({$code}), '')";
		}
		foreach ( range( 'A', 'Z' ) as $upper ) {
			$expr = "REPLACE({$expr}, '{$upper}', '" . strtolower( $upper ) . "')";
		}
		return $expr;
	}

	/**
	 * SQL that keeps credential-like keys out of a scan, mirroring
	 * is_sensitive_meta_key(): the exact names as a NOT IN, the needles as
	 * NOT LIKEs, both against the normalised name.
	 *
	 * @param string $col  Column, e.g. pm.meta_key.
	 * @param array  $args Prepared-statement arguments (appended, by reference).
	 * @return string Fragment starting with " AND ".
	 */
	protected static function sensitive_key_sql( $col, &$args ) {
		global $wpdb;
		$norm = self::sql_name_normalized( $col );
		$sql  = " AND {$norm} NOT IN (" . implode( ',', array_fill( 0, count( self::SENSITIVE_META_EXACT ), '%s' ) ) . ')';
		foreach ( self::SENSITIVE_META_EXACT as $name ) {
			$args[] = $name;
		}
		foreach ( self::SENSITIVE_META_NEEDLES as $needle ) {
			$sql   .= " AND {$norm} NOT LIKE %s";
			$args[] = '%' . $wpdb->esc_like( $needle ) . '%';
		}
		return $sql;
	}

	/**
	 * SQL that keeps the option names the write guard refuses out of the
	 * options scan. option_blocked() still answers for every row that comes
	 * back — collation look-alikes, over-long or invisible names have no SQL
	 * form — and since the scan walks by id, a row it refuses counts for
	 * nothing; this exclusion just keeps the ordinary refusals out of PHP.
	 *
	 * @param array $args Prepared-statement arguments (appended, by reference).
	 * @return string Fragment starting with " AND ".
	 */
	protected static function option_guard_sql( &$args ) {
		global $wpdb;
		$lists = AB_MCP_Option_Guard::write_guard_lists();
		$norm = self::sql_name_normalized( 'option_name' );
		$sql  = '';
		foreach ( $lists['needles'] as $needle ) {
			$sql   .= " AND {$norm} NOT LIKE %s";
			$args[] = '%' . $wpdb->esc_like( strtolower( (string) $needle ) ) . '%';
		}
		$exact = array_values( array_unique( array_map( 'strtolower', $lists['exact'] ) ) );
		if ( $exact ) {
			$sql .= " AND {$norm} NOT IN (" . implode( ',', array_fill( 0, count( $exact ), '%s' ) ) . ')';
			foreach ( $exact as $name ) {
				$args[] = $name;
			}
		}
		return $sql;
	}

	/**
	 * Names WordPress treats by name — sanitised, filtered or defaulted through
	 * a hook that carries the name — and that a row may therefore carry only
	 * byte for byte: the sanitize_option() cases, every option with a hook
	 * under OPTION_HOOK_PREFIXES, every registered setting. A row the database
	 * reads as one of them under another spelling is a look-alike that
	 * update_option() would write past the name's own sanitiser and filters.
	 *
	 * @return string[]
	 */
	protected static function canonical_option_names() {
		global $wp_filter;
		$names = self::SANITIZED_OPTION_NAMES;
		// The name a deprecated one is sent to is treated by name as well.
		foreach ( self::DEPRECATED_OPTION_NAMES as $new ) {
			$names[] = $new;
		}
		if ( is_array( $wp_filter ) ) {
			foreach ( array_keys( $wp_filter ) as $hook ) {
				$hook = (string) $hook;
				foreach ( self::OPTION_HOOK_PREFIXES as $prefix ) {
					if ( 0 === strpos( $hook, $prefix ) && strlen( $hook ) > strlen( $prefix ) ) {
						$names[] = substr( $hook, strlen( $prefix ) );
						break;
					}
				}
			}
		}
		if ( function_exists( 'get_registered_settings' ) ) {
			foreach ( array_keys( (array) get_registered_settings() ) as $setting ) {
				$names[] = (string) $setting;
			}
		}
		return array_values( array_unique( array_filter( $names, 'strlen' ) ) );
	}

	/**
	 * Is this row the one update_option( $name ) would write?
	 *
	 * The database compares names under its collation: a stored
	 * "wídget_text" with a combining accent equals widget_text under
	 * utf8mb4_unicode_ci, while LIKE, which weighs character by character,
	 * tells them apart — so the row slips past the widget exclusion and its
	 * value is then written over the real widget row, past the widget
	 * sanitiser. The one comparison that cannot be fooled is the
	 * database's own: the name must resolve to exactly this row. And a name
	 * WordPress redirects to a successor is not this row's name either.
	 *
	 * @param string $name      Option name as stored.
	 * @param int    $option_id This row's id.
	 * @return bool
	 */
	protected static function option_row_is_target( $name, $option_id ) {
		global $wpdb;
		if ( isset( self::DEPRECATED_OPTION_NAMES[ $name ] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The database's own equality is the point.
		$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( array( (string) (int) $option_id ) !== array_map( 'strval', $ids ) ) {
			return false;
		}
		// And the row must not BE, for the database, (1) one of the names
		// WordPress reads under protection — if the look-alike is the only row,
		// it resolves to itself, and get_option('widget_text') still reads it:
		// every registered widget's option, the sidebar map, the redirected
		// names and the option guard's exact names, never written here under
		// any spelling — nor (2) one of the names WordPress sanitises or
		// filters by name unless the row carries that very name byte for byte:
		// update_option('BlogName') skips the sanitiser blogname gets while
		// get_option('blogname') reads the row. IN compares under the
		// collation, as get_option() does; CAST AS BINARY compares the bytes.
		$never     = self::collation_guarded_option_names();
		$canonical = self::canonical_option_names();
		$in_never  = implode( ',', array_fill( 0, count( $never ), '%s' ) );
		// Pair by pair: the row equals THIS canonical name under the collation
		// but not byte for byte. A set-wide test (IN … AND BINARY NOT IN …)
		// would let a second spelling that a plugin's hook registers
		// (option_BlogName) vouch for the row against blogname's sanitiser.
		$pairs = implode( ' OR ', array_fill( 0, count( $canonical ), '(option_name = %s AND CAST(option_name AS BINARY) <> CAST(%s AS BINARY))' ) );
		$args  = array( (int) $option_id );
		foreach ( $never as $name ) {
			$args[] = $name;
		}
		foreach ( $canonical as $name ) {
			$args[] = $name;
			$args[] = $name;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The database's own equality is the point; every placeholder is prepared here.
		$hits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_id = %d AND (option_name IN ({$in_never}) OR {$pairs})", $args ) );
		return $hits < 1;
	}

	/**
	 * Option names that must not be reached through a collation look-alike:
	 * the option of every registered widget, the sidebar map, the names
	 * WordPress redirects, and the exact names the option guard protects.
	 *
	 * @return string[]
	 */
	protected static function collation_guarded_option_names() {
		$names = self::widget_option_names();
		// Only the old name: update_option() sends it to the new one, so a row
		// under it is never the row a write would reach. The new name is a name
		// of its own and is written under its own spelling; it is canonical, not
		// forbidden (canonical_option_names()).
		foreach ( array_keys( self::DEPRECATED_OPTION_NAMES ) as $old ) {
			$names[] = $old;
		}
		$lists = AB_MCP_Option_Guard::write_guard_lists();
		foreach ( (array) $lists['exact'] as $exact ) {
			$names[] = (string) $exact;
		}
		return array_values( array_unique( array_filter( $names, 'strlen' ) ) );
	}

	/**
	 * The option names widgets live under: the sidebar map, widget_<id_base>,
	 * and for every registered widget the option it stores its settings in
	 * (option_name, which a widget may set to anything) and the legacy one
	 * WP_Widget::get_settings() falls back to and reads (alt_option_name).
	 * A row under any of them is a widget row, whatever its name looks like.
	 *
	 * @param bool $normalized Fold the names the way sql_name_normalized() folds the column.
	 * @return string[]
	 */
	protected static function widget_option_names( $normalized = false ) {
		global $wp_widget_factory;
		$names = array( 'sidebars_widgets' );
		if ( isset( $wp_widget_factory->widgets ) && is_array( $wp_widget_factory->widgets ) ) {
			foreach ( $wp_widget_factory->widgets as $widget ) {
				if ( ! is_object( $widget ) ) {
					continue;
				}
				if ( isset( $widget->id_base ) && is_scalar( $widget->id_base ) && '' !== (string) $widget->id_base ) {
					$names[] = 'widget_' . $widget->id_base;
				}
				foreach ( array( 'option_name', 'alt_option_name' ) as $prop ) {
					if ( isset( $widget->$prop ) && is_string( $widget->$prop ) && '' !== $widget->$prop ) {
						$names[] = $widget->$prop;
					}
				}
			}
		}
		if ( $normalized ) {
			$names = array_map( array( __CLASS__, 'php_name_normalized' ), $names );
		}
		return array_values( array_unique( array_filter( $names, 'strlen' ) ) );
	}

	/**
	 * The PHP twin of sql_name_normalized(): whitespace and NUL removed,
	 * ASCII letters lower-cased by table (strtr, not strtolower: locale-free
	 * on every PHP version), nothing else touched.
	 *
	 * @param string $name Name as stored.
	 * @return string
	 */
	protected static function php_name_normalized( $name ) {
		$name = str_replace( array( ' ', "\t", "\n", "\r", "\0", "\x0B" ), '', (string) $name );
		return strtr( $name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
	}

	/**
	 * Is this, by normalised name, a widget row: the sidebar map, a widget_*
	 * name, or the option a registered widget stores under or reads back from?
	 *
	 * @param string $name Name as stored.
	 * @return bool
	 */
	protected static function widget_option_name( $name ) {
		$n = self::php_name_normalized( $name );
		return 'sidebars_widgets' === $n || 0 === strpos( $n, 'widget_' ) || in_array( $n, self::widget_option_names( true ), true );
	}

	/**
	 * SQL that keeps the internal post types out of a scan.
	 *
	 * @param string $col  Column, e.g. p.post_type.
	 * @param array  $args Prepared-statement arguments (appended, by reference).
	 * @return string Fragment starting with " AND ".
	 */
	protected static function internal_post_type_sql( $col, &$args ) {
		$sql = " AND {$col} NOT IN (" . implode( ',', array_fill( 0, count( self::INTERNAL_POST_TYPES ), '%s' ) ) . ')';
		foreach ( self::INTERNAL_POST_TYPES as $type ) {
			$args[] = $type;
		}
		return $sql;
	}

	/**
	 * SQL that keeps the internal taxonomies out of a scan.
	 *
	 * @param string $col  Column, e.g. tt.taxonomy.
	 * @param array  $args Prepared-statement arguments (appended, by reference).
	 * @return string Fragment starting with " AND ".
	 */
	protected static function internal_taxonomy_sql( $col, &$args ) {
		foreach ( self::INTERNAL_TAXONOMIES as $taxonomy ) {
			$args[] = $taxonomy;
		}
		return " AND {$col} NOT IN (" . implode( ',', array_fill( 0, count( self::INTERNAL_TAXONOMIES ), '%s' ) ) . ')';
	}

	/**
	 * @param string $type Post type as given.
	 * @return bool
	 */
	public static function internal_post_type( $type ) {
		return in_array( strtolower( trim( (string) $type ) ), self::INTERNAL_POST_TYPES, true );
	}

	/**
	 * @param string $taxonomy Taxonomy as given.
	 * @return bool
	 */
	public static function internal_taxonomy( $taxonomy ) {
		return in_array( strtolower( trim( (string) $taxonomy ) ), self::INTERNAL_TAXONOMIES, true );
	}

	/**
	 * Write one field of one term through wp_update_term().
	 *
	 * Not a copy of that function: the function itself. Everything it does
	 * belongs to this write — the pre_term_* filters through sanitize_term()
	 * with the slashing they expect, the wp_update_term_data filter, the
	 * refusals for an empty name, a parent that no longer exists and a slug
	 * another term holds,
	 * the edit_term/edited_term actions and the term caches. Earlier builds of
	 * this release rebuilt that path step by step and differed from it in a new
	 * detail every review round.
	 *
	 * The one reason it was ever rebuilt is the slug, and that reason holds for
	 * a single case: wp_update_term() gives a term whose stored slug is empty a
	 * slug made from its new name, which this tool promises not to do. Such a
	 * term is passed over instead.
	 *
	 * @param array  $row   Scan row: term_id, taxonomy, term_taxonomy_id and the field as stored.
	 * @param string $field 'name' or 'description'.
	 * @param string $value Replaced value, unslashed.
	 * @param array  $res   Table result, for the note (by reference).
	 * @return bool True when the field was written.
	 */
	protected static function write_term_field( $row, $field, $value, &$res ) {
		global $wpdb;
		$term_id  = (int) $row['term_id'];
		$taxonomy = (string) $row['taxonomy'];
		$tt_id    = (int) $row['term_taxonomy_id'];
		$field    = (string) $field;
		$value    = (string) $value;
		// The stored value, raw from the column the scan read — not what
		// get_term() returns, which a get_term or get_{$taxonomy} filter may
		// have reshaped (a translation plugin serves a different name there).
		// Comparing a filtered value with a raw one would call a write that
		// worked a write that did nothing.
		$before = isset( $row[ $field ] ) ? (string) $row[ $field ] : '';
		if ( $value === $before ) {
			// The column already reads as the replacement — a case-insensitive
			// search or a regex can match without changing anything. Nothing to
			// write, and no refusal to explain.
			return false;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof WP_Term ) {
			self::term_note( $res, $term_id, 'WordPress cannot read this term any more' );
			return false;
		}
		// Raw, like the read-back below: a get_term filter that serves its own
		// slug would otherwise hide a real change, or invent one.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The stored slug, to compare with the stored slug afterwards.
		$before_slug = (string) $wpdb->get_var( $wpdb->prepare( "SELECT slug FROM {$wpdb->terms} WHERE term_id = %d", $term_id ) );
		// Slashed, as wp_update_term() expects it (expected_slashed) and
		// unslashes again for the write.
		$updated = wp_update_term( $term_id, $taxonomy, array( $field => wp_slash( $value ) ) );
		if ( is_wp_error( $updated ) ) {
			self::term_note( $res, $term_id, 'WordPress refused this update (' . $updated->get_error_code() . ': ' . $updated->get_error_message() . ')' );
			return false;
		}
		// A term still shared between taxonomies is split here, and this
		// taxonomy's row moves to a new id; the other taxonomies keep the old
		// one and the old value, so they are rows of their own from now on.
		$new_id = isset( $updated['term_id'] ) ? (int) $updated['term_id'] : $term_id;
		// The id stays what it was: a term that would have been split never
		// got this far.
		// wp_update_term() does not look at what its own database write did:
		// a value the column will not hold — a name of more than 200
		// characters — is dropped by the database while the function still
		// reports success. What counts as written is what the column reads
		// back as, raw, the same way the scan read it.
		if ( 'description' === $field ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading back the column just written; the cache would answer from before it.
			$after = $wpdb->get_var( $wpdb->prepare( "SELECT description FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $tt_id ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading back the column just written; the cache would answer from before it.
			$after = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->terms} WHERE term_id = %d", $new_id ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$after_slug = (string) $wpdb->get_var( $wpdb->prepare( "SELECT slug FROM {$wpdb->terms} WHERE term_id = %d", $new_id ) );
		// This tool never asks for a slug, but pre_term_slug and
		// wp_update_term_data may change one along with the field that was
		// asked for. That is WordPress' own doing and stands; it is said out
		// loud rather than quietly undone — and said even when the field
		// itself did not change, because the permalink did.
		if ( $after_slug !== $before_slug ) {
			// Its own list, not a note: a changed permalink is named however
			// many there are, while notes are capped.
			if ( count( $res['slug_changes'] ) < self::SLUG_CAP ) {
				$res['slug_changes'][] = array(
					'term_id' => $new_id,
					'from'    => $before_slug,
					'to'      => $after_slug,
					'field'   => $field,
				);
			} else {
				$res['slug_changes_omitted']++;
			}
		}
		if ( null === $after || (string) $after === $before ) {
			self::term_note( $res, $term_id, 'WordPress reported success but the stored ' . $field . ' did not change (a filter may have undone the replacement, or the database may have refused the value)' );
			return false;
		}
		// WordPress runs without the strict SQL modes, so a name longer than
		// the column takes is cut instead of refused, and nobody says so. Only
		// a stored value that fills the column exactly, is the start of what
		// was asked for and is shorter was cut there; a shorter value that a
		// filter produced (trimming, KSES) is the filters' ordinary work and no
		// finding. Characters, not bytes: the column is utf8mb4 and counts
		// characters, so a hundred umlauts are a hundred, not two hundred.
		$after = (string) $after;
		$len   = static function ( $s ) {
			return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
		};
		if ( 'name' === $field && self::TERM_NAME_CHARS === $len( $after ) && $len( $value ) > self::TERM_NAME_CHARS && 0 === strpos( $value, $after ) ) {
			self::term_note( $res, $term_id, 'the database cut this name to the ' . self::TERM_NAME_CHARS . ' characters the column holds', '.' );
		}
		return true;
	}

	/**
	 * Can this term be written at all, whatever its value is?
	 *
	 * Two conditions that have nothing to do with the value and therefore
	 * belong before it is read: a term that still sits in more than one
	 * taxonomy would be split by wp_update_term() before it writes, and a term
	 * whose stored slug is empty would be given one made from its new name.
	 * Asked later — in the write, where both used to sit — a row refused here
	 * would already have counted as a hit and used up a slot of the row limit,
	 * which tells by the count whether its value matched.
	 *
	 * @param int  $term_id   Term id.
	 * @param bool $needs_slug Whether an empty slug matters (it does not for meta).
	 * @return bool
	 */
	protected static function term_row_writable( $term_id, $needs_slug = true ) {
		global $wpdb;
		$term_id = (int) $term_id;
		// The count _split_shared_term() makes; wp_term_is_shared() answers
		// false wherever finished_splitting_shared_terms is set.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A row rule, asked before any value is read.
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d", $term_id ) ) > 1 ) {
			return false;
		}
		if ( ! $needs_slug ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		return ! empty( $wpdb->get_var( $wpdb->prepare( "SELECT slug FROM {$wpdb->terms} WHERE term_id = %d", $term_id ) ) );
	}

	/**
	 * Why a term the caller may edit was passed over. Only such terms are ever
	 * named, so a note says nothing about a row the caller may not see.
	 *
	 * @param array  $res     Table result (by reference).
	 * @param int    $term_id Term id.
	 * @param string $why     Reason, without the trailing full stop.
	 * @param string $tail    What follows the reason.
	 */
	protected static function term_note( &$res, $term_id, $why, $tail = '; not written.' ) {
		self::add_note( $res, 'Term ' . (int) $term_id . ': ' . $why . $tail );
	}

	/**
	 * Add a note about a row the caller may edit, or — once the list is full —
	 * count it in notes_omitted, so nothing is dropped in silence. What must be
	 * named however often it happens does not go through here: a slug a filter
	 * changed goes into slug_changes, which is not capped.
	 *
	 * @param array  $res  Table result (by reference).
	 * @param string $text The note.
	 */
	protected static function add_note( &$res, $text ) {
		if ( count( $res['notes'] ) < self::NOTE_CAP ) {
			$res['notes'][] = (string) $text;
			return;
		}
		$res['notes_omitted']++;
	}

	/**
	 * May a postmeta hit be reported for this key? The same policy as the
	 * direct read in wp_get_post_meta — post right, protected-meta allow-list,
	 * the key's edit right AND the credential-like key names the direct read
	 * refuses. That last rule was missing here: a hit on "api_key" told the
	 * caller that the value contains the search text, and repeated searches
	 * would spell the secret out although reading it is refused.
	 *
	 * @param int    $pid      Post id.
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	public static function meta_hit_allowed( $pid, $meta_key ) {
		if ( ! current_user_can( 'read_post', $pid ) ) {
			return false;
		}
		if ( self::is_sensitive_meta_key( $meta_key ) ) {
			return false;
		}
		// The underscore is checked literally as well: is_protected_meta() is
		// filterable, and the direct readers refuse "_" whatever a filter says.
		// A protected key is the direct reader's decision (may_expose_protected:
		// allow-listed, then edit_post_meta if registered, edit_post if not —
		// WordPress default-denies edit_post_meta for every unregistered
		// protected key, which is why asking it here left _elementor_data
		// unreachable).
		if ( '_' === substr( $meta_key, 0, 1 ) || is_protected_meta( $meta_key, 'post' ) ) {
			// The direct reader hands protected keys out under manage_options
			// only (include_protected); wp_search runs under edit_posts.
			return current_user_can( 'manage_options' ) && (bool) self::may_expose_protected( $meta_key, $pid );
		}
		return (bool) current_user_can( 'edit_post_meta', $pid, $meta_key );
	}

	/**
	 * Serialisation-safe multi-table search & replace.
	 *
	 * @param array $a Args.
	 * @return array|WP_Error
	 */
	public static function search_replace( $a ) {
		$search = isset( $a['search'] ) && is_scalar( $a['search'] ) ? (string) $a['search'] : '';
		if ( '' === $search ) {
			return new WP_Error(
				'ab_mcp_missing_arg',
				__( 'Missing required argument: search (non-empty string). Schema: search* (string), replace (string, default ""), tables (array of posts|postmeta|options|terms), type (string), scope ("text" or "raw", default "text"), apply (boolean), limit (integer 1–2000), after (object: the next of a previous call), exclude_revisions (boolean), case_insensitive (boolean), regex (boolean), allow_without_undo (boolean), stop_at_undo_cap (boolean), post_ids (array of integers), expected_count (integer).', 'alphabridge-mcp' )
			);
		}
		$replace = isset( $a['replace'] ) && is_scalar( $a['replace'] ) ? (string) $a['replace'] : '';

		// after: the `next` of a previous call — per scan, the id to continue
		// behind. Anything else is refused rather than guessed at.
		$after = array();
		if ( isset( $a['after'] ) ) {
			$bad = ! is_array( $a['after'] );
			foreach ( $bad ? array() : $a['after'] as $key => $id ) {
				$id = is_bool( $id ) || is_array( $id ) ? false : filter_var( $id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
				if ( ! is_string( $key ) || ! in_array( $key, self::SCAN_KEYS, true ) || false === $id ) {
					$bad = true;
					break;
				}
				$after[ $key ] = (int) $id;
			}
			if ( $bad ) {
				return new WP_Error(
					'ab_mcp_bad_after',
					sprintf(
						/* translators: %s: list of keys */
						__( 'after must be the next object of a previous call: keys among %s, each a whole number of 0 or more. Pass next from the previous answer as it is, or leave after out to start over.', 'alphabridge-mcp' ),
						implode( ', ', self::SCAN_KEYS )
					)
				);
			}
		}

		$scope = isset( $a['scope'] ) && is_scalar( $a['scope'] ) && '' !== (string) $a['scope'] ? strtolower( trim( (string) $a['scope'] ) ) : 'text';
		if ( ! in_array( $scope, self::SCOPES, true ) ) {
			return new WP_Error(
				'ab_mcp_bad_scope',
				__( 'scope must be "text" (the default: on page-builder pages only the texts, links and images their writer may change) or "raw" (the stored data as text, page code included, with an undo point; page-builder data needs an account that may post unfiltered HTML). Pass one of the two, or leave scope out.', 'alphabridge-mcp' )
			);
		}

		// post_ids: the posts this call may touch, and no other.
		$post_ids = array();
		if ( isset( $a['post_ids'] ) ) {
			$list = is_array( $a['post_ids'] ) ? $a['post_ids'] : null;
			foreach ( null === $list ? array( false ) : $list as $id ) {
				$id = is_bool( $id ) || is_array( $id ) ? false : filter_var( $id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
				if ( false === $id ) {
					$list = null;
					break;
				}
				$post_ids[ (int) $id ] = (int) $id;
			}
			if ( null === $list || array() === $post_ids || count( $post_ids ) > self::POST_IDS_MAX ) {
				return new WP_Error(
					'ab_mcp_bad_post_ids',
					sprintf(
						/* translators: %d: most ids one call takes */
						__( 'post_ids must be a list of 1 to %d post ids (whole numbers of 1 or more). Pass the ids of the posts to change, or leave post_ids out to scan every post.', 'alphabridge-mcp' ),
						self::POST_IDS_MAX
					)
				);
			}
			sort( $post_ids );
		}
		$expected = null;
		if ( isset( $a['expected_count'] ) ) {
			$expected = is_bool( $a['expected_count'] ) || is_array( $a['expected_count'] ) ? false : filter_var( $a['expected_count'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
			if ( false === $expected ) {
				return new WP_Error( 'ab_mcp_bad_expected_count', __( 'expected_count must be a whole number of 0 or more: totals.matched of the dry run. Run the call without apply first and pass that number.', 'alphabridge-mcp' ) );
			}
		}

		$f = array(
			'search'            => $search,
			'replace'           => $replace,
			'scope'             => $scope,
			'allow_without_undo' => self::b( $a, 'allow_without_undo', false ),
			'stop_at_undo_cap'  => self::b( $a, 'stop_at_undo_cap', false ),
			'apply'             => self::b( $a, 'apply', false ),
			'limit'             => self::clamp( self::i( $a, 'limit', 200 ), 1, 2000 ),
			'after'             => $after,
			'type'              => self::s( $a, 'type', '' ),
			'exclude_revisions' => self::b( $a, 'exclude_revisions', true ),
			'case_insensitive'  => self::b( $a, 'case_insensitive', false ),
			'regex'             => self::b( $a, 'regex', false ),
			'post_ids'          => array_values( $post_ids ),
			'expected_count'    => $expected,
		);
		$valid  = array( 'posts', 'postmeta', 'options', 'terms' );
		$tables = array();
		foreach ( self::arr( $a, 'tables', array( 'posts', 'postmeta' ) ) as $t ) {
			$t = strtolower( trim( (string) $t ) );
			if ( in_array( $t, $valid, true ) && ! in_array( $t, $tables, true ) ) {
				$tables[] = $t;
			}
		}
		if ( empty( $tables ) ) {
			return new WP_Error( 'ab_mcp_bad_tables', __( 'tables must contain at least one of: posts, postmeta, options, terms. Pass at least one of them, or leave tables out for posts and postmeta.', 'alphabridge-mcp' ) );
		}
		if ( array() !== $f['post_ids'] && array() !== array_diff( $tables, array( 'posts', 'postmeta' ) ) ) {
			return new WP_Error( 'ab_mcp_post_ids_tables', __( 'post_ids limits posts and their postmeta, not options or terms. Pass tables ["posts","postmeta"] with post_ids, or leave post_ids out to replace in options and terms.', 'alphabridge-mcp' ) );
		}

		// type narrows the posts scan only, as the schema says: it is checked
		// and applied when that scan runs, and a call without it — about
		// options or terms — is not stopped by it; flags say when it was
		// ignored. Whether a post may be rewritten is decided per row by what
		// WordPress itself answers for that post (post_row_allowed()); no
		// type-level shortcut stands in for that answer — a meta capability
		// asked without a post id, a revision judged by its own type instead
		// of its parent's, or a map_meta_cap filter that grants a type by
		// context would each make such a shortcut answer differently.
		$type_wanted        = '' !== $f['type'] && 'any' !== $f['type'];
		$f['type_applies']  = $type_wanted && in_array( 'posts', $tables, true );
		$f['type_ignored']  = $type_wanted && ! $f['type_applies'];
		if ( $f['type_applies'] && self::internal_post_type( $f['type'] ) ) {
			return new WP_Error(
				'ab_mcp_internal_type',
				sprintf(
					/* translators: %s: post type */
					__( 'The post type "%s" is internal to WordPress and is not rewritten by this tool. Leave type out, or name a content type (wp_get_post_types lists them).', 'alphabridge-mcp' ),
					$f['type']
				)
			);
		}


		// scope text writes a replacement inside block attributes as JSON; raw
		// writes it as typed, as this tool always did.
		$engine = new AB_MCP_Replace_Engine( $search, $replace, $f['case_insensitive'], $f['regex'], array( 'attr_context' => 'text' === $scope ) );
		if ( is_wp_error( $engine->error ) ) {
			return $engine->error;
		}

		// expected_count: the same call as a dry run first; with another
		// number of matching rows nothing is written.
		if ( $f['apply'] && null !== $f['expected_count'] ) {
			$probe = $a;
			unset( $probe['expected_count'] );
			$probe['apply'] = false;
			$dry            = self::search_replace( $probe );
			if ( is_wp_error( $dry ) ) {
				return $dry;
			}
			$found = (int) $dry['totals']['matched'];
			if ( $found !== (int) $f['expected_count'] ) {
				$per_table = array();
				foreach ( (array) $dry['tables'] as $name => $tr ) {
					$per_table[ $name ] = (int) $tr['matched'];
				}
				return new WP_Error(
					'ab_mcp_expected_count',
					sprintf(
						/* translators: 1: rows found now, 2: rows expected */
						__( 'This call finds %1$d matching rows now, not the %2$d of expected_count, so nothing was changed. Run it without apply to see the current preview, check it, and pass its totals.matched as expected_count.', 'alphabridge-mcp' ),
						$found,
						(int) $f['expected_count']
					),
					array(
						'found'    => $found,
						'expected' => (int) $f['expected_count'],
						'tables'   => $per_table,
					)
				);
			}
		}

		// Page builders are known only to a core with the builder contract
		// (AB_MCP_BUILDER_API); without it every post is replaced as before.
		$f['builders'] = self::builders_on();
		self::$run     = array(
			'f'              => $f,
			'ways'           => array(),
			'token'          => null,
			'applied'        => array(),
			'failed_at'      => null,
			'locked'         => 0,
			'warnings'       => array(),
			'without_writer' => array(),
			'engine_written' => array(),
			'as_text'        => array(),
			'writer_marks'   => array(),
			// Pages a writer failed on after writing, whose undo part the
			// call's token keeps.
			'held'           => array(),
			// Scope text: whether this call wrote anything its undo token does
			// not hold (an ordinary post, a title, an option, a term, …).
			'uncovered'      => false,
			// The undo point of the option rows this call changed.
			'options_token'  => null,
			'raw'            => array(
				'token'   => null,
				'locs'    => array(),
				'refused' => array(),
				'unsaved' => array(),
				// Pages whose own copy in post_content_filtered no scan reads
				// (SeedProd), whose post_content this call replaced: id => name.
				'own_copy' => array(),
			),
			// Scope text, page-builder pages changed outside a writer
			// (text_undo()): pages counted against BUILDER_POSTS, the locs of
			// the undo part each got, the pages whose part was tried, and the
			// pages beyond the share (id => whether they were or would be
			// written without an undo part).
			'text'           => array(
				'pages'   => array(),
				'locs'    => array(),
				'tried'   => array(),
				'unsaved' => array(),
				'over'    => array(),
			),
		);

		$report          = array();
		$elementor_posts = array();
		// The pages a writer changes, before the tables: their post_content
		// copy and their builder data are left out of the posts and postmeta
		// scans, so nothing is replaced twice.
		if ( 'text' === $scope && $f['builders'] && ( in_array( 'posts', $tables, true ) || in_array( 'postmeta', $tables, true ) ) ) {
			$report['builders'] = self::phase_builders( $engine, $f );
		}
		foreach ( $tables as $t ) {
			switch ( $t ) {
				case 'posts':
					$report['posts'] = self::phase_posts( $engine, $f );
					break;
				case 'postmeta':
					$report['postmeta'] = self::phase_postmeta( $engine, $f, $elementor_posts );
					break;
				case 'options':
					$report['options'] = self::phase_options( $engine, $f );
					break;
				case 'terms':
					$report['terms'] = self::phase_terms( $engine, $f );
					break;
			}
		}

		// A page-builder page this call rewrote as stored text (no writer, or
		// scope raw): what its builder made from the old data is stale, and so
		// are the page caches that hold it — emptied as after a writer's save.
		$builder_caches = array();
		if ( $f['apply'] && class_exists( 'AB_MCP_Builder_Caches' ) ) {
			foreach ( self::$run['engine_written'] as $pid => $builder ) {
				AB_MCP_Builder_Caches::after_write( (int) $pid, (string) $builder, true );
				$builder_caches[] = (int) $pid;
			}
		}

		// Elementor keeps several things per post that are made from its JSON,
		// and after the JSON changed each of them is stale:
		// - the generated CSS of classic widgets (_elementor_css);
		// - the CSS files of Elementor 4 atomic elements, made from the class
		//   styles inside the JSON and served for as long as Elementor holds
		//   them valid, with no expiry. Elementor's own save invalidates them
		//   through the action fired here; without Elementor loaded nothing
		//   listens to it;
		// - the rendered HTML (the element cache), on unless the option
		//   elementor_element_cache_ttl is "disable", kept for that many hours
		//   (24 when unset);
		// - the Markdown served to ?format=markdown while that experiment is
		//   on, dropped by Elementor on save_post, which a meta write does not
		//   fire; kept for elementor_markdown_cache_ttl hours (24 when unset).
		// The meta keys are deleted by name, as Elementor's own clean-up does;
		// for the element cache 4.3.3 has no public method per document
		// (Document::delete_cache() is protected).
		$elementor = array(
			'posts_touched'         => count( $elementor_posts ),
			'css_cache_cleared'     => false,
			'element_cache_cleared' => false,
		);
		if ( $f['apply'] && ! empty( $elementor_posts ) ) {
			$element_cache_key  = self::elementor_constant( 'Elementor\\Core\\Base\\Document::CACHE_META_KEY', '_elementor_element_cache' );
			$markdown_cache_key = self::elementor_constant( 'Elementor\\Modules\\MarkdownRender\\Module::CACHE_META_KEY', '_elementor_markdown_cache' );
			$atomic_styles_key  = self::elementor_constant( 'Elementor\\Modules\\AtomicWidgets\\Styles\\Atomic_Widget_Styles::STYLES_KEY', 'local' );
			foreach ( $elementor_posts as $pid ) {
				delete_post_meta( $pid, '_elementor_css' );
				do_action( 'elementor/atomic-widgets/styles/clear', array( $atomic_styles_key, $pid ) );
				delete_post_meta( $pid, $element_cache_key );
				delete_post_meta( $pid, $markdown_cache_key );
			}
			delete_option( '_elementor_global_css' );
			$elementor['css_cache_cleared']     = true;
			$elementor['element_cache_cleared'] = true;
		}

		$totals = array(
			'matched'     => 0,
			'occurrences' => 0,
			'changed'     => 0,
		);
		// Where each scan stopped early, if any did: hand it back as `after`.
		$next = array();
		foreach ( $report as $tr ) {
			$totals['matched']     += $tr['matched'];
			$totals['occurrences'] += $tr['occurrences'];
			$totals['changed']     += $tr['changed'];
			$next                   = array_merge( $next, $tr['next'] );
		}

		$out = array(
			'dry_run'                    => ! $f['apply'],
			'search'                     => $search,
			'replace'                    => $replace,
			'scope'                      => $scope,
			'flags'                      => array(
				'scope'             => $scope,
				'tables'            => $tables,
				'limit'             => $f['limit'],
				'after'             => $f['after'],
				'type'              => $f['type_applies'] ? $f['type'] : 'any',
				'type_ignored'      => $f['type_ignored'],
				'exclude_revisions' => $f['exclude_revisions'],
				'case_insensitive'  => $f['case_insensitive'],
				'regex'             => $f['regex'],
				'post_ids'          => $f['post_ids'],
				'expected_count'    => $f['expected_count'],
			),
			'tables'                     => $report,
			'totals'                     => $totals,
			'next'                       => $next,
			'skipped_corrupt_serialized' => $engine->skipped_corrupt,
			'skipped_object_values'      => $engine->skipped_object_values,
			'skipped_unchecked_values'   => $engine->skipped_unchecked_values,
			'elementor'                  => $elementor,
			'builder_caches_cleared'     => array_slice( $builder_caches, 0, self::SAMPLE_CAP ),
			'note'                       => $f['apply']
				? __( 'Changes written via the WordPress APIs (serialization regenerated, caches invalidated, post revisions created).', 'alphabridge-mcp' )
				: __( 'Dry-run only. Review the per-table preview, then set apply=true.', 'alphabridge-mcp' ),
		);
		return self::builder_answer( $out, $f );
	}

	/**
	 * What the answer says beyond the tables: locked matches and the way to
	 * them, the undo point, the pages without a writer, warnings, and where a
	 * writer failed.
	 *
	 * @param array $out Answer so far.
	 * @param array $f   Flags.
	 * @return array
	 */
	private static function builder_answer( array $out, array $f ) {
		$run   = self::$run;
		$token = 'raw' === $f['scope'] ? $run['raw']['token'] : $run['token'];
		$out['locked_matches'] = (int) $run['locked'];
		$out['undo_token']     = $token;
		// The option rows have an undo point of their own (write_options()).
		$out['options_undo_token'] = $run['options_token'];
		if ( null !== $token ) {
			$out['undo_pages'] = self::undo_pages( $f );
		}
		$warnings              = array_values( array_unique( $run['warnings'] ) );
		if ( $run['locked'] > 0 ) {
			$out['locked_note'] = sprintf(
				/* translators: %d: number of matches */
				_n(
					'%d match is in a locked place of a page-builder page (code, HTML, CSS, a shortcode, a form, a placeholder, a global part or the page\'s own code) and was left as it is. To replace it as stored text too, run again with scope: raw (page-builder data then needs an account that may post unfiltered HTML).',
					'%d matches are in locked places of page-builder pages (code, HTML, CSS, shortcodes, forms, placeholders, global parts or the page\'s own code) and were left as they are. To replace them as stored text too, run again with scope: raw (page-builder data then needs an account that may post unfiltered HTML).',
					(int) $run['locked'],
					'alphabridge-mcp'
				),
				(int) $run['locked']
			);
		}
		if ( array() !== $run['without_writer'] ) {
			$list = array();
			foreach ( array_slice( $run['without_writer'], 0, self::SAMPLE_CAP, true ) as $id => $page ) {
				$list[] = array(
					'id'      => (int) $id,
					'builder' => (string) $page['builder'],
					'storage' => (string) $page['storage'],
				);
			}
			$out['builder_pages_without_writer'] = $list;
			$out['builder_pages_note']           = __( 'These pages are built with a page builder that no writer of this version writes (or not this page, for the reason given): their stored data is replaced as text, the way this tool always did, without the field rules of a writer. On a page of storage A2 the page builder restores its own copy the next time the page is saved in it. Check the pages after applying.', 'alphabridge-mcp' );
		}
		$uncovered = $run['uncovered'] || ! empty( $out['tables']['terms']['changed'] )
			|| ( ! empty( $out['tables']['options']['changed'] ) && null === $run['options_token'] );
		if ( 'text' === $f['scope'] && null !== $token && ! empty( $f['apply'] ) && $uncovered ) {
			// The token holds the page-builder pages; a reader of the answer
			// must not take it for one over the whole call.
			$warnings[] = __( 'undo_token takes back the page-builder pages in undo_pages: their page-builder data, its copies, post_content and what the page builder\'s save touches besides (Elementor\'s page settings). The titles, excerpts, other posts, other meta and terms this call changed are not in it (option rows have their own undo point, options_undo_token, where one was recorded); wp_undo leaves them as they are.', 'alphabridge-mcp' );
		}
		if ( 'raw' === $f['scope'] ) {
			array_unshift( $warnings, __( 'scope raw: stored data is replaced as text, page-builder data and page code included, past the field rules of the page builders — except a copy a page builder keeps in post_content_filtered (SeedProd), which no scan reads. For a page built with a page builder the undo point holds its builder data and post_content; titles, excerpts and terms are not in it, and option rows have their own undo point (options_undo_token).', 'alphabridge-mcp' ) );
		}
		if ( 'raw' === $f['scope'] && array() !== $run['raw']['own_copy'] ) {
			$ids = array_keys( $run['raw']['own_copy'] );
			sort( $ids );
			$warnings[] = sprintf(
				empty( $f['apply'] )
					/* translators: 1: page builder names, 2: post ids */
					? __( 'On these pages scope raw would replace post_content only: %1$s keeps its own copy of the page, which its editor loads and no scan reads, and writes it back over post_content the next time the page is saved in its editor (an editor open at that moment does so at its next save): posts %2$s. scope text changes both copies through the page builder\'s writer where the text stands in its fields.', 'alphabridge-mcp' )
					/* translators: 1: page builder names, 2: post ids */
					: __( 'On these pages scope raw replaced post_content only: %1$s keeps its own copy of the page, which its editor loads and no scan reads, and writes it back over post_content the next time the page is saved in its editor (an editor open now does so at its next save): posts %2$s. scope text changes both copies through the page builder\'s writer where the text stands in its fields.', 'alphabridge-mcp' ),
				implode( ', ', array_values( array_unique( $run['raw']['own_copy'] ) ) ),
				self::id_list( $ids )
			);
		}
		$over = array_keys( array_filter( $run['text']['over'] ) );
		if ( array() !== $over ) {
			sort( $over );
			$warnings[] = sprintf(
				empty( $f['apply'] )
					/* translators: 1: pages per call, 2: number of pages, 3: post ids */
					? __( 'One call keeps undo points for up to %1$d page-builder pages it changes outside a writer. %2$d more would be written without one, as scope text always wrote them: posts %3$s. To give each of them an undo point, run with stop_at_undo_cap=true: the scans then stop before the first such page, and next continues there.', 'alphabridge-mcp' )
					/* translators: 1: pages per call, 2: number of pages, 3: post ids */
					: __( 'One call keeps undo points for up to %1$d page-builder pages it changes outside a writer. %2$d more were written without one, as scope text always wrote them, and wp_undo does not take them back: posts %3$s. To keep an undo point for every such page, run with stop_at_undo_cap=true: the scans then stop before the first page beyond them, and next continues there.', 'alphabridge-mcp' ),
				self::BUILDER_POSTS,
				count( $over ),
				self::id_list( $over )
			);
		}
		foreach ( array_slice( array_merge( $run['raw']['unsaved'], $run['text']['unsaved'] ), 0, self::NOTE_CAP ) as $what ) {
			/* translators: %s: post id and meta key */
			$warnings[] = sprintf( __( 'Changed without an undo point (page code a page builder locks, or a key the undo point did not foresee): %s.', 'alphabridge-mcp' ), $what );
		}
		if ( null !== $run['failed_at'] ) {
			$out['failed_at']      = $run['failed_at'];
			$out['applied_so_far'] = $run['applied'];
			$warnings[]            = sprintf(
				/* translators: 1: post id, 2: error class, 3: undo token or a sentence */
				__( 'The page-builder writer failed on post %1$d (%2$s); the builders scan stopped there and continues behind it with next. The pages changed before it are in applied_so_far; %3$s', 'alphabridge-mcp' ),
				(int) $run['failed_at']['id'],
				(string) $run['failed_at']['error'],
				null !== $token ? sprintf( /* translators: %s: undo token */ __( 'wp_undo with undo token %s puts them back.', 'alphabridge-mcp' ), $token ) : __( 'this call has no undo token to name; if the writer recorded an undo point for the failed page before it stopped, wp_undo_list shows it. Check that page with wp_get_builder_layout.', 'alphabridge-mcp' )
			);
		}
		$out['warnings'] = array_values( array_unique( $warnings ) );
		return $out;
	}

	/**
	 * Post ids for a warning: the first SAMPLE_CAP of them, and "…" where
	 * there are more.
	 *
	 * @param int[] $ids Post ids, sorted.
	 * @return string
	 */
	private static function id_list( array $ids ) {
		$shown = array_slice( $ids, 0, self::SAMPLE_CAP );
		return implode( ', ', $shown ) . ( count( $ids ) > count( $shown ) ? ', …' : '' );
	}

	/**
	 * The pages whose state the call's undo token holds: the pages a writer
	 * applied a change to and the page-builder pages changed outside a writer
	 * that got their undo part (scope text), or the pages of the raw point.
	 *
	 * @param array $f Flags.
	 * @return int[]
	 */
	private static function undo_pages( array $f ) {
		$locs = 'raw' === $f['scope'] ? self::$run['raw']['locs'] : self::$run['text']['locs'];
		$ids  = 'raw' === $f['scope'] ? array() : array_map( 'intval', array_merge( array_keys( self::$run['writer_marks'] ), array_keys( self::$run['held'] ) ) );
		foreach ( $locs as $id => $held ) {
			if ( array() !== $held ) {
				$ids[] = (int) $id;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids );
		return array_slice( $ids, 0, self::SAMPLE_CAP );
	}

	/**
	 * Empty per-table result skeleton.
	 *
	 * @return array
	 */
	private static function table_result() {
		return array(
			'matched'     => 0,
			'occurrences' => 0,
			'changed'     => 0,
			'samples'     => array(),
			'truncated'   => false,
			'next'        => array(),
			'notes'       => array(),
			'notes_omitted' => 0,
			'slug_changes'  => array(),
			'slug_changes_omitted' => 0,
		);
	}

	/**
	 * Walk a table by id windows and hand every row to a handler.
	 *
	 * The one shape every scan has, in plain and regex mode alike, and what
	 * keeps a refused row silent. The old plain path let the database pick the
	 * rows by value (LIKE) and cap them (LIMIT limit+1) BEFORE the per-row
	 * policy ran, so a refused row whose value matched pushed an allowed one
	 * out of the window and showed in the count, the samples and the truncated
	 * flag — a yes/no oracle on a value the reader refuses (found over
	 * several review rounds; every new reader rule reopened it). Here the table is
	 * walked in windows of ids — a fixed span per query and a fixed number of
	 * windows per call, never a count of matching rows, so a refused row cannot
	 * use up anything, and `next`/`after` carry the walk on across calls, so
	 * the end of every table is reachable — a refused row is passed over
	 * and counts for nothing, and the row limit counts handled rows only. The
	 * name-based SQL exclusions still apply on top: a credential-like key
	 * never even reaches PHP. What a refused row still costs is time.
	 *
	 * truncated means "stopped at the limit" or "the walk ended before the
	 * highest id": whether more rows would have matched is not looked at, on
	 * purpose — that would depend on the rows after the last one, refused
	 * ones included.
	 *
	 * @param string   $select    "SELECT … FROM …" without WHERE.
	 * @param string   $id_col    Id column in SQL (ID, pm.meta_id, …).
	 * @param string   $id_key    Its key in the row array.
	 * @param string   $where     WHERE clause without the id window ('' for none).
	 * @param array    $args      Its prepared-statement arguments.
	 * @param string[] $like_cols Columns for the LIKE prefilter (unused in regex mode).
	 * @param callable $handler   Returns true when the row counted as a match.
	 * @param object   $engine    Replace engine (for the LIKE needles).
	 * @param array    $f         Flags.
	 * @param array    $res       Table result (by reference).
	 * @param string   $scan_key  This scan's key in next and after (one of SCAN_KEYS).
	 * @param string   $sub_col   Second cursor column for JOIN scans (tt.term_taxonomy_id), '' otherwise.
	 * @param string   $sub_key   Its key in the row array.
	 */
	private static function scan_by_id( $select, $id_col, $id_key, $where, $args, $like_cols, $handler, $engine, $f, &$res, $scan_key, $sub_col = '', $sub_key = '' ) {
		global $wpdb;
		$like      = '';
		$like_args = array();
		if ( ! $f['regex'] && $like_cols ) {
			$or = array();
			foreach ( $engine->like_needles() as $needle ) {
				foreach ( $like_cols as $col ) {
					$or[]        = "{$col} LIKE %s";
					$like_args[] = '%' . $wpdb->esc_like( $needle ) . '%';
				}
			}
			if ( $or ) {
				$like = ' AND (' . implode( ' OR ', $or ) . ')';
			}
		}
		// The end of the table, read once and independently of any value.
		$from = substr( $select, (int) strpos( $select, 'FROM ' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Table names from $wpdb, no user input.
		$max = (int) $wpdb->get_var( "SELECT MAX({$id_col}) {$from}" );
		// Where a previous call stopped (its `next`, handed back as `after`).
		$start = isset( $f['after'][ $scan_key ] ) ? (int) $f['after'][ $scan_key ] : 0;
		// next records where EVERY scan stands afterwards — the id it stopped
		// at, or the end of its table — so a call continued with next as
		// after reads nothing twice: a completed scan restarted at 0 would
		// apply a replacement that contains its own search text once more.
		$res['next'][ $scan_key ] = max( $start, $max );
		if ( $max < 1 || $start >= $max ) {
			return;
		}
		// A JOIN hands one id out as several rows (a term in two taxonomies),
		// so the batch cursor is the pair (id, sub id) there: unique, strictly
		// increasing, and no run of duplicates — however long — can be cut or
		// skipped. Without a sub column the id alone is unique.
		$composite = '' !== $sub_col;
		$cursor    = $composite
			? "({$id_col} > %d OR ({$id_col} = %d AND {$sub_col} > %d)) AND {$id_col} <= %d"
			: "{$id_col} > %d AND {$id_col} <= %d";
		$order     = $composite ? "{$id_col} ASC, {$sub_col} ASC" : "{$id_col} ASC";
		$base      = "{$select} WHERE " . ( '' !== $where ? "{$where} AND " : '' ) . "{$cursor}{$like} ORDER BY {$order} LIMIT " . (int) self::BATCH;
		// A window is WINDOW ids — a fixed span, so one query never examines
		// more than that many rows — and a call walks at most MAX_WINDOWS of
		// them per scan, so one call's work is bounded too. Beyond that budget
		// the scan stops and names the id to continue from (next); a table of
		// any size is walked to its end across calls, never in one unbounded
		// query.
		$lower   = $start;
		$matched = 0;
		// In regex mode every row of the span is read and tested in PHP — there
		// is no LIKE to thin it out first — so a call walks a tenth of the ids
		// it walks in plain mode. next/after carries it on either way.
		$windows = $f['regex'] ? (int) max( 1, self::MAX_WINDOWS / 10 ) : self::MAX_WINDOWS;
		for ( $window = 0; $window < $windows && $lower < $max; $window++ ) {
			$upper     = (int) min( $lower + self::WINDOW, $max );
			$after_id  = $lower;
			$after_sub = PHP_INT_MAX; // At a window start: strictly id > lower.
			// Every row of the window is read, in batches of BATCH rows: a
			// window of ten thousand large rows would otherwise sit in memory
			// at once. A refused row changes nothing but the number of queries.
			while ( true ) {
				$bounds = $composite ? array( $after_id, $after_id, $after_sub, $upper ) : array( $after_id, $upper );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Windowed scan; every placeholder is prepared here, writes go through the WordPress APIs in the handlers.
				$rows = (array) $wpdb->get_results( $wpdb->prepare( $base, array_merge( $args, $bounds, $like_args ) ), ARRAY_A );
				foreach ( $rows as $row ) {
					$before_id = $after_id;
					$after_id  = (int) $row[ $id_key ];
					$after_sub = $composite ? (int) $row[ $sub_key ] : 0;
					$handled   = $handler( $row );
					// A handler stops the scan: 'stop_before' leaves this row to the
					// next call (a cap was reached before it), 'stop_after' counts it
					// as done (it failed and must not be tried again blindly).
					if ( 'stop_before' === $handled || 'stop_after' === $handled ) {
						$res['truncated']         = true;
						$res['next'][ $scan_key ] = 'stop_before' === $handled ? $before_id : $after_id;
						return;
					}
					if ( $handled ) {
						$matched++;
						if ( $matched >= $f['limit'] ) {
							$res['truncated']         = true;
							$res['next'][ $scan_key ] = $after_id;
							$res['notes'][]           = isset( $f['limit_note'] ) ? (string) $f['limit_note'] : __( 'Row limit reached; pass next back as after to continue behind the last row handled, or raise limit.', 'alphabridge-mcp' );
							return;
						}
					}
				}
				if ( count( $rows ) < self::BATCH ) {
					break;
				}
			}
			$lower = $upper;
		}
		if ( $lower < $max ) {
			$res['truncated']         = true;
			$res['next'][ $scan_key ] = $lower;
			/* translators: %d: number of ids */
			$res['notes'][] = sprintf( __( 'This call\'s budget of %d ids per scan is used up; pass next back as after to continue where it stopped.', 'alphabridge-mcp' ), self::WINDOW * $windows );
		}
	}

	/**
	 * SQL that keeps a scan to the posts post_ids names; '' without post_ids.
	 *
	 * @param string $col  Column of the post id.
	 * @param array  $f    Flags.
	 * @param array  $args Prepared-statement arguments (appended, by reference).
	 * @return string Fragment starting with " AND ", or ''.
	 */
	protected static function post_ids_sql( $col, array $f, &$args ) {
		if ( empty( $f['post_ids'] ) ) {
			return '';
		}
		foreach ( $f['post_ids'] as $id ) {
			$args[] = (int) $id;
		}
		return " AND {$col} IN (" . implode( ',', array_fill( 0, count( $f['post_ids'] ), '%d' ) ) . ')';
	}

	/**
	 * The direct reader's rule for a post row, plus the writer's: read_post
	 * and edit_post, and for a revision edit_post on its parent as well —
	 * raw_content_allowed() hands a revision's text only to someone who may
	 * edit the post it belongs to.
	 *
	 * @param array $row Row with ID, post_type, post_parent.
	 * @return bool
	 */
	protected static function post_row_allowed( $row ) {
		$id   = (int) $row['ID'];
		$type = (string) $row['post_type'];
		// post_ids: a post the call does not name is not touched (behind the
		// SQL filter, for every scan that asks here).
		$only = isset( self::$run['f']['post_ids'] ) ? (array) self::$run['f']['post_ids'] : array();
		if ( array() !== $only && ! in_array( $id, $only, true ) ) {
			return false;
		}
		// Defence in depth behind the SQL exclusion: an internal type, or a
		// revision of one, is never rewritten.
		if ( self::internal_post_type( $type ) || ( 'revision' === $type && self::internal_post_type( (string) get_post_type( (int) $row['post_parent'] ) ) ) ) {
			return false;
		}
		if ( $id <= 0 || ! current_user_can( 'read_post', $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return false;
		}
		if ( 'revision' === (string) $row['post_type'] ) {
			$parent = (int) $row['post_parent'];
			if ( $parent <= 0 || ! current_user_can( 'edit_post', $parent ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The direct reader's rule for a term row, plus the writer's: the
	 * taxonomy must be registered and readable as wp_get_term reads it
	 * (public, or assign_terms), and the term editable (edit_term, which maps
	 * to the taxonomy's edit_terms).
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	protected static function term_row_allowed( $term_id, $taxonomy ) {
		$tax = get_taxonomy( (string) $taxonomy );
		return $term_id > 0 && $tax && self::can_read_taxonomy( $tax ) && current_user_can( 'edit_term', (int) $term_id );
	}

	/**
	 * The termmeta reader's rule (wp_get_term_meta): assign_terms on the
	 * taxonomy, no key that starts with "_" — literally, whatever a filter
	 * makes is_protected_meta() say —, none protected or credential-like,
	 * edit_term_meta for the key; plus the writer's edit_term.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $meta_key Meta key.
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	protected static function term_meta_allowed( $term_id, $meta_key, $taxonomy ) {
		$tax = get_taxonomy( (string) $taxonomy );
		if ( ! $tax || ! current_user_can( $tax->cap->assign_terms ) ) {
			return false;
		}
		if ( '_' === substr( $meta_key, 0, 1 ) || is_protected_meta( $meta_key, 'term' ) || self::is_sensitive_meta_key( $meta_key ) ) {
			return false;
		}
		return $term_id > 0 && current_user_can( 'edit_term', (int) $term_id ) && current_user_can( 'edit_term_meta', (int) $term_id, $meta_key );
	}

	/**
	 * Whether the page-builder parts are there: the readers and the writers
	 * loaded on them.
	 *
	 * @return bool
	 */
	protected static function builders_on() {
		return function_exists( 'ab_mcp_load_builder_writers' ) && ab_mcp_load_builder_writers()
			&& class_exists( 'AB_MCP_Builders', false ) && class_exists( 'AB_MCP_Builder_Writers', false );
	}

	/**
	 * How this call treats a post, decided once per post and call:
	 * - way 'writer': scope text, the page shows its page builder's own data
	 *   (storage B, or A2 where the builder keeps a second copy) and a Pro
	 *   writer can write it now — its texts are replaced through the writer
	 *   (phase_builders), its copies (locs: the ones the reading adapter
	 *   lists, which the writer searches and keeps in step) stay out of the
	 *   other scans, and matches in the page code its builder locks (locked)
	 *   are only counted. What a writer's save touches besides (touched_locs:
	 *   Elementor's page settings and version) is no copy of the page: the
	 *   writer does not search it, so the postmeta scan replaces it as before;
	 * - way 'engine': everything else — as this tool always replaced it
	 *   (principle 9 of the plan: without a writer nothing gets worse).
	 * builder is the page builder detected on the post (null for none), which
	 * scope raw needs for its undo point.
	 *
	 * @param int $post_id Post id.
	 * @return array { way, writer, builder, name, storage, locs, locked, note }
	 */
	protected static function way( $post_id ) {
		$id = (int) $post_id;
		if ( isset( self::$run['ways'][ $id ] ) ) {
			return self::$run['ways'][ $id ];
		}
		$way = array(
			'way'     => 'engine',
			'writer'  => null,
			'builder' => null,
			'name'    => '',
			'storage' => 'A',
			'locs'    => array(),
			'locked'  => array(),
			'note'    => '',
		);
		$post = ! empty( self::$run['f']['builders'] ) ? get_post( $id ) : null;
		// A revision is replaced as before (exclude_revisions=false): no writer
		// writes one, and an undo point could not put it back (edit_post is
		// never granted on a revision).
		if ( $post && 'revision' !== $post->post_type ) {
			try {
				$primary = AB_MCP_Builders::primary( $post );
				if ( null !== $primary ) {
					$way['builder'] = (string) $primary['id'];
					$way['name']    = (string) $primary['name'];
					$way['storage'] = AB_MCP_Builders::effective_storage( $post );
					$adapter        = AB_MCP_Builders::for_post( $post );
					$writer         = null === $adapter ? null : AB_MCP_Builder_Writers::for_adapter( $adapter->id() );
					if ( 'text' === self::$run['f']['scope'] && null !== $writer && in_array( $way['storage'], array( 'B', 'A2' ), true ) ) {
						$can = $writer->can_write( $post );
						if ( true === $can ) {
							$way['way']    = 'writer';
							$way['writer'] = $writer;
							$way['locked'] = (array) $adapter->locked_meta_keys();
							foreach ( (array) $adapter->copies( $post ) as $copy ) {
								if ( isset( $copy['loc'] ) ) {
									$way['locs'][] = (string) $copy['loc'];
								}
							}
						} elseif ( is_wp_error( $can ) ) {
							$way['note'] = $can->get_error_message();
						}
					}
				}
			} catch ( \Throwable $e ) {
				// A reader or writer that cannot answer for this post: it is
				// replaced as before.
				unset( $e );
			}
		}
		self::$run['ways'][ $id ] = $way;
		return $way;
	}

	/**
	 * Remember a page-builder page that scope text replaced the way this tool
	 * always did, because no writer writes it (the answer lists them).
	 *
	 * @param int   $post_id Post id.
	 * @param array $way     way().
	 */
	private static function note_without_writer( $post_id, array $way ) {
		if ( 'text' === self::$run['f']['scope'] && 'engine' === $way['way'] && null !== $way['builder'] && in_array( $way['storage'], array( 'B', 'A2' ), true ) ) {
			self::$run['without_writer'][ (int) $post_id ] = array(
				'builder' => '' !== $way['note'] ? $way['name'] . ': ' . $way['note'] : $way['name'],
				'storage' => $way['storage'],
			);
		}
	}

	/**
	 * The undo list's line for this call.
	 *
	 * @return string
	 */
	private static function undo_summary() {
		$cut = static function ( $text ) {
			$text = (string) $text;
			return function_exists( 'mb_substr' ) && mb_strlen( $text, 'UTF-8' ) > 40 ? mb_substr( $text, 0, 40, 'UTF-8' ) . '…' : $text;
		};
		return sprintf( '"%s" → "%s" (%s)', $cut( self::$run['f']['search'] ), $cut( self::$run['f']['replace'] ), self::$run['f']['scope'] );
	}

	/**
	 * Scope raw: the undo point of a page-builder page, recorded once per
	 * post and call before its first write — its builder's copies, post_content
	 * and every meta key this call may rewrite on it, as part of the call's
	 * point (builder_data, scope raw). At most BUILDER_POSTS pages per call;
	 * the preview counts them the same way and records nothing.
	 *
	 * @param int                       $post_id Post id.
	 * @param AB_MCP_Replace_Engine $engine  Engine.
	 * @return true|string|WP_Error true: write; 'cap': stop before this page; WP_Error: do not write it.
	 */
	private static function raw_undo( $post_id, $engine ) {
		$id  = (int) $post_id;
		$raw = self::$run['raw'];
		if ( isset( $raw['locs'][ $id ] ) ) {
			return true;
		}
		if ( isset( $raw['refused'][ $id ] ) ) {
			return $raw['refused'][ $id ];
		}
		if ( count( $raw['locs'] ) >= self::BUILDER_POSTS ) {
			return 'cap';
		}
		if ( empty( self::$run['f']['apply'] ) ) {
			self::$run['raw']['locs'][ $id ] = array();
			return true;
		}
		$post  = get_post( $id );
		$way   = self::way( $id );
		$item  = $post ? AB_MCP_Undo_Builder::snapshot( $post, AB_MCP_Builders::adapter_for_builder( (string) $way['builder'] ), self::raw_meta_locs( $id, $engine ) ) : null;
		$token = null === $item ? new WP_Error( 'ab_mcp_not_found', __( 'Post not found. wp_list_posts or wp_search finds its id.', 'alphabridge-mcp' ) ) : AB_MCP_Undo_Builder::record( 'wp_search_replace', 'raw', array( $item ), $raw['token'], self::undo_summary() );
		if ( ! is_wp_error( $token ) ) {
			self::$run['raw']['token']       = (string) $token;
			self::$run['raw']['locs'][ $id ] = array_keys( $item['locs'] );
			return true;
		}
		$data = $token->get_error_data();
		if ( 'ab_mcp_undo_disabled' === $token->get_error_code() && is_array( $data ) && isset( $data['reason'] ) && 'switched_off' === $data['reason'] ) {
			self::$run['warnings'][]         = __( 'Undo is switched off on this site, so these changes have no way back. An administrator switches it on with the wp_undo tool under Settings › AlphaBridge MCP › Fine-tuning.', 'alphabridge-mcp' );
			self::$run['raw']['locs'][ $id ] = array();
			return true;
		}
		if ( 'ab_mcp_undo_too_large' === $token->get_error_code() && ! empty( self::$run['f']['allow_without_undo'] ) ) {
			/* translators: 1: post id, 2: reason */
			self::$run['warnings'][]         = sprintf( __( 'Post %1$d was written without an undo point, as allow_without_undo asked: %2$s', 'alphabridge-mcp' ), $id, $token->get_error_message() );
			self::$run['raw']['locs'][ $id ] = array();
			return true;
		}
		$err = new WP_Error(
			'ab_mcp_undo_failed',
			sprintf(
				/* translators: 1: post id, 2: reason, 3: what to do */
				__( 'Post %1$d: not written, because its undo point could not be recorded: %2$s %3$s', 'alphabridge-mcp' ),
				$id,
				$token->get_error_message(),
				'ab_mcp_undo_too_large' === $token->get_error_code() ? __( 'To write it without a way back, call again with allow_without_undo=true.', 'alphabridge-mcp' ) : __( 'Nothing was written to this post.', 'alphabridge-mcp' )
			)
		);
		self::$run['raw']['refused'][ $id ] = $err;
		return $err;
	}

	/**
	 * Scope raw: keep the state a page was left in with its undo point, after
	 * every write to it (wp_undo restores only from that state, or with force).
	 *
	 * @param int $post_id Post id.
	 */
	private static function raw_mark( $post_id ) {
		$id = (int) $post_id;
		self::mark_part( $id, self::$run['raw']['token'], isset( self::$run['raw']['locs'][ $id ] ) ? self::$run['raw']['locs'][ $id ] : array() );
	}

	/**
	 * Scope text: the same for a page that got its undo part from text_undo().
	 *
	 * @param int $post_id Post id.
	 */
	private static function text_mark( $post_id ) {
		$id = (int) $post_id;
		self::mark_part( $id, self::$run['token'], isset( self::$run['text']['locs'][ $id ] ) ? self::$run['text']['locs'][ $id ] : array() );
	}

	/**
	 * Keep with the undo point the state its locs of one page are in now.
	 *
	 * @param int         $id    Post id.
	 * @param string|null $token Token of the point.
	 * @param string[]    $locs  Locs of the page's part.
	 */
	private static function mark_part( $id, $token, array $locs ) {
		if ( null === $token || array() === $locs ) {
			return;
		}
		$marked = AB_MCP_Undo_Builder::mark_written( $token, (int) $id, AB_MCP_Undo_Builder::state_hashes( (int) $id, $locs ) );
		if ( is_wp_error( $marked ) ) {
			/* translators: %d: post id */
			self::$run['warnings'][] = sprintf( __( 'The undo point does not know the state post %d was left in, so wp_undo will ask for force=true for it.', 'alphabridge-mcp' ), (int) $id );
		}
	}

	/**
	 * Scope text: whether a page gets an undo point before the posts or the
	 * postmeta scan writes it — a page built with a page builder that these
	 * scans replace the way this tool always did, outside a writer: a page
	 * that shows post_content (storage A: the blocks of GenerateBlocks,
	 * Kadence, Spectra, Stackable or Pagelayer, a builder page whose builder
	 * is inactive), or one whose page builder no writer of this version
	 * writes. Scope raw kept an undo point for exactly these pages and scope
	 * text kept none (measured on real installations, 2 October 2026). A post
	 * without a page builder gets none, as in scope raw. A2 pages replaced as
	 * text (a2_as_text()) take their part there.
	 *
	 * @param array $way way().
	 * @return bool
	 */
	private static function text_undo_due( array $way ) {
		return 'text' === self::$run['f']['scope'] && 'engine' === $way['way'] && null !== $way['builder'];
	}

	/**
	 * Scope text: count a page against the BUILDER_POSTS pages one call keeps
	 * undo parts for — in the preview as in the write, so both hand out the
	 * same parts.
	 *
	 * @param int $post_id Post id.
	 * @return bool False: the call has its share; text_cap_stops() decides.
	 */
	private static function text_undo_slot( $post_id ) {
		$id = (int) $post_id;
		if ( isset( self::$run['text']['pages'][ $id ] ) ) {
			return true;
		}
		if ( count( self::$run['text']['pages'] ) >= self::BUILDER_POSTS ) {
			return false;
		}
		self::$run['text']['pages'][ $id ] = true;
		return true;
	}

	/**
	 * Scope text: a page beyond the BUILDER_POSTS pages that get an undo part.
	 * By default it is written all the same, without one, because scope text
	 * always wrote such pages and its reach stays (principle 9 of the plan;
	 * the share only bounds the parts one undo point holds), and the answer
	 * names it; stop_at_undo_cap stops the scan before it instead, so every
	 * page keeps a way back and next continues there.
	 *
	 * @param int $post_id Post id.
	 * @return bool True: stop the scan before this page.
	 */
	private static function text_cap_stops( $post_id ) {
		if ( ! empty( self::$run['f']['stop_at_undo_cap'] ) ) {
			return true;
		}
		if ( ! isset( self::$run['text']['over'][ (int) $post_id ] ) ) {
			self::$run['text']['over'][ (int) $post_id ] = false;
		}
		return false;
	}

	/**
	 * Scope text: a page beyond the share was written without an undo part,
	 * or would be in the preview; only then does the answer name it (a page
	 * kses keeps back, or one WordPress does not save, is not written).
	 *
	 * @param int $post_id Post id.
	 */
	private static function text_over_written( $post_id ) {
		if ( isset( self::$run['text']['over'][ (int) $post_id ] ) ) {
			self::$run['text']['over'][ (int) $post_id ] = true;
		}
	}

	/**
	 * Scope raw: whether the page builder of a page keeps its own copy in
	 * post_content_filtered (SeedProd), which none of the scans reads — the
	 * posts scan takes content, title and excerpt — so that replacing
	 * post_content alone is undone by the builder's next save.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	private static function own_copy_unscanned( $post_id ) {
		$way = self::way( (int) $post_id );
		if ( null === $way['builder'] || 'A2' !== $way['storage'] ) {
			return false;
		}
		try {
			$post = get_post( (int) $post_id );
			foreach ( $post ? (array) AB_MCP_Builders::copies( $post ) : array() as $copy ) {
				if ( isset( $copy['loc'] ) && 'post_content_filtered' === $copy['loc'] ) {
					return true;
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return false;
	}

	/**
	 * Where a scan stops because the call has taken its share of page-builder
	 * pages with an undo point, the answer says so and names the way on.
	 *
	 * @return string
	 */
	private static function cap_note() {
		return sprintf(
			/* translators: %d: pages per call */
			__( 'Every page-builder page this call changes gets an undo point, and one call takes at most %d of them; this scan stopped before the next one. Pass next back as after to continue.', 'alphabridge-mcp' ),
			self::BUILDER_POSTS
		);
	}

	/**
	 * Scope text: the undo part of a page text_undo_slot() counted, taken once
	 * per post and call before its first write, as raw_undo() takes it for
	 * scope raw — its page builder's copies, post_content and the meta keys
	 * this call may rewrite on it — and added to the call's token, so one
	 * wp_undo takes the writers' pages and these back together. Recorded with
	 * scope text: putting back what the page held takes what writing it in
	 * scope text took. Unlike scope raw, a part that cannot be recorded does
	 * not hold the page back: scope text wrote these pages without an undo
	 * point before, and what it wrote then it still writes (principle 9 of
	 * the plan: the reach of scope text stays as it was); the answer names
	 * the page and why.
	 *
	 * @param int                       $post_id Post id.
	 * @param AB_MCP_Replace_Engine $engine  Engine.
	 */
	private static function text_undo( $post_id, $engine ) {
		$id = (int) $post_id;
		if ( empty( self::$run['f']['apply'] ) || ! isset( self::$run['text']['pages'][ $id ] ) || isset( self::$run['text']['tried'][ $id ] ) ) {
			return;
		}
		self::$run['text']['tried'][ $id ] = true;
		$post = get_post( $id );
		if ( ! $post ) {
			return;
		}
		$way   = self::way( $id );
		$item  = AB_MCP_Undo_Builder::snapshot( $post, AB_MCP_Builders::adapter_for_builder( (string) $way['builder'] ), self::raw_meta_locs( $id, $engine ) );
		$token = AB_MCP_Undo_Builder::record( 'wp_search_replace', 'text', array( $item ), self::$run['token'], self::undo_summary() );
		if ( ! is_wp_error( $token ) ) {
			self::$run['token']               = (string) $token;
			self::$run['text']['locs'][ $id ] = array_keys( $item['locs'] );
			return;
		}
		$data = $token->get_error_data();
		if ( 'ab_mcp_undo_disabled' === $token->get_error_code() && is_array( $data ) && isset( $data['reason'] ) && 'switched_off' === $data['reason'] ) {
			self::$run['warnings'][] = __( 'Undo is switched off on this site, so these changes have no way back. An administrator switches it on with the wp_undo tool under Settings › AlphaBridge MCP › Fine-tuning.', 'alphabridge-mcp' );
			return;
		}
		/* translators: 1: post id, 2: the reason, with its way */
		self::$run['warnings'][] = sprintf( __( 'Post %1$d was written without an undo point, because none could be recorded: %2$s', 'alphabridge-mcp' ), $id, $token->get_error_message() );
	}

	/**
	 * Scope text, after the postmeta scan wrote a key: keep the state with
	 * the page's undo part, or say that the key has no way back.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key written.
	 */
	private static function text_meta_written( $post_id, $key ) {
		$id = (int) $post_id;
		if ( self::writer_remark( $id, $key ) ) {
			return;
		}
		if ( ! isset( self::$run['text']['locs'][ $id ] ) ) {
			self::$run['uncovered'] = true;
			return;
		}
		if ( ! in_array( 'meta:' . $key, self::$run['text']['locs'][ $id ], true ) ) {
			self::$run['text']['unsaved'][] = 'post ' . $id . ', ' . $key;
			self::$run['uncovered']         = true;
		}
		self::text_mark( $id );
	}

	/**
	 * Scope text: a meta key the postmeta scan wrote on a page whose writer
	 * applied a change in this call, and which that writer's undo point holds
	 * (what its save touches besides the page: Elementor's page settings).
	 * The point gets the page's state as it is now, so wp_undo takes this
	 * change back with the writer's — without that, the state it knows would
	 * no longer match and it would ask for force=true.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key written.
	 * @return bool Whether the writer's point holds the key.
	 */
	private static function writer_remark( $post_id, $key ) {
		$id    = (int) $post_id;
		$token = isset( self::$run['writer_marks'][ $id ] ) ? (string) self::$run['writer_marks'][ $id ] : '';
		if ( '' === $token || ! class_exists( 'AB_MCP_Undo_Builder', false ) ) {
			return false;
		}
		$entry = AB_MCP_Undo::entry_for( $token );
		$after = is_array( $entry ) && isset( $entry['after'][ (string) $id ] ) && is_array( $entry['after'][ (string) $id ] ) ? $entry['after'][ (string) $id ] : array();
		if ( ! isset( $after[ 'meta:' . $key ] ) ) {
			return false;
		}
		self::mark_part( $id, $token, array_keys( $after ) );
		return true;
	}

	/**
	 * Scope raw: the meta keys of a post this call may rewrite — every key the
	 * postmeta scan would write (the same row rules) whose value holds a match
	 * — as undo locs. Asked with a copy of the engine, so its counters stay
	 * those of the scans.
	 *
	 * @param int                       $post_id Post id.
	 * @param AB_MCP_Replace_Engine $engine  Engine.
	 * @return string[] 'meta:<key>'
	 */
	private static function raw_meta_locs( $post_id, $engine ) {
		global $wpdb;
		$args  = array( (int) $post_id );
		$where = 'post_id = %d' . self::sensitive_key_sql( 'meta_key', $args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one post's meta, every value bound.
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE {$where}", $args ), ARRAY_A );
		$probe  = clone $engine;
		$markup = self::markup_meta_keys();
		$locs   = array();
		foreach ( $rows as $row ) {
			$key = (string) $row['meta_key'];
			if ( isset( $locs[ 'meta:' . $key ] ) || in_array( $key, self::META_KEYS_WITH_OWN_WRITER, true ) || self::is_sensitive_meta_key( $key ) || ! self::meta_hit_allowed( (int) $post_id, $key ) ) {
				continue;
			}
			if ( self::is_markup_meta_key( $key, $markup ) && ! current_user_can( 'unfiltered_html' ) ) {
				continue;
			}
			$value = self::db_value( (string) $row['meta_value'], $probe );
			if ( null === $value ) {
				continue;
			}
			$hits = 0;
			$probe->apply_value( $value, $hits );
			if ( $hits > 0 ) {
				$locs[ 'meta:' . $key ] = true;
			}
		}
		return array_keys( $locs );
	}

	/**
	 * A field value for a builder sample: at most SAMPLE_CHARS characters.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function sample_value( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $value, 'UTF-8' ) > self::SAMPLE_CHARS ) {
			return mb_substr( $value, 0, self::SAMPLE_CHARS, 'UTF-8' ) . '…';
		}
		return $value;
	}

	/**
	 * builders: the pages a page builder's writer changes (scope text). A
	 * page is a candidate when its post_content, its second copy or any of
	 * its meta values holds a form of the text; the writer decides what it
	 * changes — only the fields it may write, never page code — and runs the
	 * seven steps of every builder write (field rules, lock, undo part under
	 * the call's token, its own save path, read-back, caches). At most
	 * BUILDER_POSTS pages per call; next.builders carries on. A writer that
	 * throws stops the scan; the answer names the page (failed_at), the pages
	 * changed before it (applied_so_far) and the undo token.
	 *
	 * Every match on such a page leaves a trace: changed (changes), locked
	 * (locked_matches, the way is scope raw), left in a field for a reason
	 * the writer gives (notes, left), or — where the writer refuses the page
	 * as a whole before writing — counted from the page builder's own data as
	 * locked, with the writer's reason. On a page of storage A2, where the
	 * site shows post_content, a text the writer finds in none of its fields
	 * is replaced in post_content as this tool always did (as_text,
	 * builder_pages_without_writer).
	 *
	 * @param AB_MCP_Replace_Engine $engine Engine (the needles).
	 * @param array                     $f      Flags.
	 * @return array
	 */
	private static function phase_builders( $engine, $f ) {
		global $wpdb;
		$res                   = self::table_result();
		$res['locked_matches'] = 0;
		$res['applied']        = array();
		$res['as_text']        = array();
		$res['kses_skipped']   = 0;
		$args                  = array();
		// Revisions are never written by a writer; internal types never enter.
		$where = "p.post_status NOT IN ('auto-draft','trash') AND p.post_type <> 'revision'";
		$where .= self::internal_post_type_sql( 'p.post_type', $args );
		$where .= self::post_ids_sql( 'p.ID', $f, $args );
		if ( $f['type_applies'] ) {
			$where .= ' AND p.post_type = %s';
			$args[] = $f['type'];
		}
		if ( ! $f['regex'] ) {
			$or = array();
			foreach ( $engine->like_needles() as $needle ) {
				$like   = '%' . $wpdb->esc_like( $needle ) . '%';
				$or[]   = 'p.post_content LIKE %s';
				$or[]   = 'p.post_content_filtered LIKE %s';
				$or[]   = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} bm WHERE bm.post_id = p.ID AND bm.meta_value LIKE %s)";
				$args[] = $like;
				$args[] = $like;
				$args[] = $like;
			}
			$where .= ' AND (' . implode( ' OR ', $or ) . ')';
		}
		$fb          = $f;
		$fb['limit'] = min( (int) $f['limit'], self::BUILDER_POSTS );
		if ( self::BUILDER_POSTS === $fb['limit'] ) {
			$fb['limit_note'] = sprintf(
				/* translators: %d: pages per call */
				__( 'This call handled %d page-builder pages through their writer, the most one call does; pass next back as after to continue.', 'alphabridge-mcp' ),
				self::BUILDER_POSTS
			);
		}

		$handler = static function ( $row ) use ( $engine, $f, &$res ) {
			if ( ! self::post_row_allowed( $row ) ) {
				return false;
			}
			$id  = (int) $row['ID'];
			$way = self::way( $id );
			if ( 'writer' !== $way['way'] ) {
				return false;
			}
			$post = get_post( $id );
			if ( ! $post ) {
				return false;
			}
			try {
				// The page as read right now: the writer works out its changes
				// from it, and execute() refuses if it changed before the write —
				// which also makes element ids that are positions (b0.2, s1 …) valid.
				$opts = array(
					'apply'              => $f['apply'],
					'layout_hash'        => AB_MCP_Builders::layout_hash( $post ),
					'scope'              => 'text',
					'undo_tool'          => 'wp_search_replace',
					'undo_token'         => (string) self::$run['token'],
					'undo_summary'       => self::undo_summary(),
					'allow_without_undo' => $f['allow_without_undo'],
					'case_insensitive'   => $f['case_insensitive'],
					'regex'              => $f['regex'],
				);
				$r    = $way['writer']->replace( $post, $f['search'], $f['replace'], $opts );
			} catch ( \Throwable $e ) {
				// The class only: a message can carry what the page holds.
				self::$run['failed_at'] = array(
					'id'    => $id,
					'error' => get_class( $e ),
				);
				return 'stop_after';
			}
			$changes = array();
			$locked  = 0;
			$left    = array();
			$problem = null;
			$as_text = false;
			if ( is_wp_error( $r ) ) {
				$data   = $r->get_error_data();
				$locked = is_array( $data ) && isset( $data['locked_matches'] ) ? (int) $data['locked_matches'] : 0;
				$left   = is_array( $data ) && isset( $data['left'] ) ? array_values( array_map( 'strval', (array) $data['left'] ) ) : array();
				if ( 'ab_mcp_builder_nothing' === $r->get_error_code() ) {
					// Shown copy, no field of the builder's data holds the text:
					// replaced there as before, the page named.
					$as_text = 'A2' === $way['storage'] && self::a2_as_text( $id, $row, $way, $engine, $f, $res );
					if ( $locked < 1 && array() === $left ) {
						return $as_text;
					}
				} else {
					$problem = $r->get_error_message();
					// Refused as a whole before anything was written (a failure
					// after the write says rolled_back): the page's matches stay
					// where they are and are counted, so the answer names them
					// and the way to them — unless the refusal is one of the
					// moment (an open editor, a save in between, the undo
					// point), whose own message names the way: try again.
					if ( ! ( is_array( $data ) && array_key_exists( 'rolled_back', $data ) ) && ! isset( $data['locked_matches'] ) && ! self::refused_for_now( $r->get_error_code() ) ) {
						$locked = self::source_matches( $post, $engine );
					}
				}
				// A failure after the write keeps its undo point: the next pages
				// join it, so one token takes the whole call back.
				if ( is_array( $data ) && ! empty( $data['undo_token'] ) ) {
					self::$run['held'][ $id ] = true;
					if ( null === self::$run['token'] ) {
						self::$run['token'] = (string) $data['undo_token'];
					}
				}
			} elseif ( is_array( $r ) ) {
				$changes = isset( $r['changes'] ) && is_array( $r['changes'] ) ? $r['changes'] : array();
				$locked  = isset( $r['locked_matches'] ) ? (int) $r['locked_matches'] : 0;
				$left    = isset( $r['left'] ) ? array_values( array_map( 'strval', (array) $r['left'] ) ) : array();
				if ( ! empty( $r['undo_token'] ) && null === self::$run['token'] ) {
					self::$run['token'] = (string) $r['undo_token'];
				}
				if ( ! empty( $r['applied'] ) ) {
					$res['changed']++;
					$res['applied'][]        = $id;
					self::$run['applied'][] = $id;
					if ( ! empty( $r['undo_token'] ) ) {
						// The postmeta scan may still write a key this point
						// holds (Elementor's page settings): it marks the page
						// again then, so wp_undo is not blocked by it.
						self::$run['writer_marks'][ $id ] = (string) $r['undo_token'];
					}
				}
				foreach ( isset( $r['warnings'] ) ? (array) $r['warnings'] : array() as $w ) {
					self::$run['warnings'][] = (string) $w;
				}
			} else {
				return false;
			}
			$res['occurrences']    += count( $changes );
			$res['locked_matches'] += $locked;
			self::$run['locked']   += $locked;
			if ( ! $as_text ) {
				// A page replaced as text is counted and shown there already.
				$res['matched']++;
			}
			if ( ! $as_text && count( $res['samples'] ) < self::SAMPLE_CAP ) {
				$shown = array();
				foreach ( array_slice( $changes, 0, 10 ) as $c ) {
					$shown[] = array(
						'element' => isset( $c['element'] ) ? (string) $c['element'] : '',
						'field'   => isset( $c['field'] ) ? (string) $c['field'] : '',
						'before'  => self::sample_value( isset( $c['before'] ) ? $c['before'] : '' ),
						'after'   => self::sample_value( isset( $c['after'] ) ? $c['after'] : '' ),
					);
				}
				$sample = array(
					'id'             => $id,
					'type'           => (string) $row['post_type'],
					'title'          => get_the_title( $id ),
					'builder'        => $way['builder'],
					'fields'         => count( $changes ),
					'changes'        => $shown,
					'locked_matches' => $locked,
				);
				if ( array() !== $left ) {
					$sample['left'] = array_slice( $left, 0, 10 );
				}
				if ( null !== $problem ) {
					$sample['refused'] = $problem;
				}
				$res['samples'][] = $sample;
			}
			if ( null !== $problem ) {
				/* translators: 1: post id, 2: reason */
				self::add_note( $res, sprintf( __( 'Post %1$d: %2$s', 'alphabridge-mcp' ), $id, $problem ) );
			}
			foreach ( $left as $why ) {
				/* translators: 1: post id, 2: which field was left and why */
				self::add_note( $res, sprintf( __( 'Post %1$d: %2$s', 'alphabridge-mcp' ), $id, $why ) );
			}
			return true;
		};

		self::scan_by_id( "SELECT p.ID, p.post_type, p.post_parent FROM {$wpdb->posts} p", 'p.ID', 'ID', $where, $args, array(), $handler, $engine, $fb, $res, 'builders' );
		$res['notes'][] = __( 'builders: pages built with a page builder that a writer of this version writes. Only the texts, links and images the writer may change were replaced, with the field rules of wp_update_builder_element; matches in locked places, and on a page the writer refused as a whole, are counted in locked_matches. occurrences counts changed fields here.', 'alphabridge-mcp' );
		if ( array() !== $res['as_text'] ) {
			$res['notes'][] = __( 'as_text: on these pages the site shows post_content, and its page builder keeps a second copy; the writer found the text in none of the fields of that copy, so it was replaced in post_content as text, the way this tool always did (builder_pages_without_writer). The page builder writes its own copy over it the next time the page is saved in it.', 'alphabridge-mcp' );
		}
		return $res;
	}

	/**
	 * Scope text, storage A2: the writer found nothing to change, yet
	 * post_content — what the site shows — may hold the text, in a place no
	 * field of the builder's own copy has (the page builder's editor has not
	 * saved the page since post_content was changed, or the text is part of
	 * its HTML only). Replaced there as this tool always did (principle 9 of
	 * the plan: nothing gets worse than before), behind the kses guard, and
	 * the page is named with the reason.
	 *
	 * @param int                       $id     Post id.
	 * @param array                     $row    Scan row.
	 * @param array                     $way    way().
	 * @param AB_MCP_Replace_Engine $engine Engine.
	 * @param array                     $f      Flags.
	 * @param array                     $res    Table result (by reference).
	 * @return bool Whether post_content holds the text.
	 */
	private static function a2_as_text( $id, array $row, array $way, $engine, array $f, array &$res ) {
		wp_cache_delete( (int) $id, 'posts' );
		$post = get_post( (int) $id );
		if ( ! $post ) {
			return false;
		}
		$old  = (string) $post->post_content;
		$hits = 0;
		$new  = $engine->apply_value( $old, $hits );
		if ( $hits < 1 || $new === $old ) {
			return false;
		}
		self::$run['as_text'][ (int) $id ]        = true;
		self::$run['without_writer'][ (int) $id ] = array(
			/* translators: %s: page builder name */
			'builder' => sprintf( __( '%s: its writer found the text in none of the fields it changes; post_content, which the site shows, was replaced as text', 'alphabridge-mcp' ), $way['name'] ),
			'storage' => $way['storage'],
		);
		if ( count( $res['as_text'] ) < self::SAMPLE_CAP ) {
			$res['as_text'][] = (int) $id;
		}
		$res['matched']++;
		$res['occurrences'] += $hits;
		$sample = array(
			'id'                => (int) $id,
			'type'              => (string) $row['post_type'],
			'title'             => get_the_title( (int) $id ),
			'builder'           => $way['builder'],
			'post_content_hits' => $hits,
		);
		$cols   = array(
			'post_content' => $old,
			'post_title'   => (string) $post->post_title,
			'post_excerpt' => isset( $post->post_excerpt ) ? (string) $post->post_excerpt : '',
		);
		// Written outside the writer, so it gets an undo part of its own
		// (text_undo()). The builders scan handles at most BUILDER_POSTS
		// pages, so the share cannot be used up here.
		self::text_undo_slot( (int) $id );
		self::write_fields( (int) $id, $cols, array( 'post_content' => $new ), $sample, $f, $res, $way['builder'], $engine );
		return true;
	}

	/**
	 * Whether a writer's refusal holds for the moment only (an open editor, a
	 * save in between, the undo point): its message names the way — try
	 * again, allow_without_undo —, not scope raw. A list in a method, not a
	 * class constant: the uninstall test reads constants named ab_mcp_… as
	 * option names.
	 *
	 * @param string $code Error code.
	 * @return bool
	 */
	private static function refused_for_now( $code ) {
		return in_array( (string) $code, array( 'ab_mcp_builder_post_locked', 'ab_mcp_builder_layout_changed', 'ab_mcp_builder_changed_meanwhile', 'ab_mcp_builder_undo_too_large', 'ab_mcp_builder_undo_failed' ), true );
	}

	/**
	 * Matches in the page builder's own data of a page (the copies the reading
	 * adapter names as source): what a writer that refuses the page as a whole
	 * leaves. Counted with a copy of the engine, so the scans' counters stay
	 * theirs; a value the engine would not walk (an object, say) counts none,
	 * as scope raw would not replace it either.
	 *
	 * @param WP_Post                   $post   Post.
	 * @param AB_MCP_Replace_Engine $engine Engine.
	 * @return int
	 */
	private static function source_matches( $post, $engine ) {
		try {
			$adapter = AB_MCP_Builders::for_post( $post );
			if ( null === $adapter ) {
				return 0;
			}
			$probe = clone $engine;
			$n     = 0;
			foreach ( (array) $adapter->copies( $post ) as $copy ) {
				if ( ! is_array( $copy ) || ! isset( $copy['loc'], $copy['role'] ) || 'source' !== $copy['role'] ) {
					continue;
				}
				$state  = AB_MCP_Undo_Builder::read_loc( (int) $post->ID, (string) $copy['loc'] );
				$values = isset( $state['rows'] ) ? array_column( (array) $state['rows'], 'value' ) : array( isset( $state['value'] ) ? $state['value'] : '' );
				foreach ( $values as $raw ) {
					$value = self::db_value( (string) $raw, $probe );
					if ( null === $value ) {
						continue;
					}
					$hits = 0;
					$probe->apply_value( $value, $hits );
					$n += $hits;
				}
			}
			return $n;
		} catch ( \Throwable $e ) {
			unset( $e );
			return 0;
		}
	}

	/**
	 * posts: post_content, post_title, post_excerpt.
	 *
	 * @param AB_MCP_Replace_Engine $engine Engine.
	 * @param array                     $f      Flags.
	 * @return array
	 */
	private static function phase_posts( $engine, $f ) {
		global $wpdb;
		$res = self::table_result();
		// Internal types never enter the scan; every other post is judged per
		// row by post_row_allowed(). type, when it applies, narrows the scan.
		$where = "post_status NOT IN ('auto-draft','trash')";
		$args  = array();
		if ( $f['exclude_revisions'] ) {
			$where .= " AND post_type <> 'revision'";
		}
		$where .= self::internal_post_type_sql( 'post_type', $args );
		$where .= self::post_ids_sql( 'ID', $f, $args );
		if ( $f['type_applies'] ) {
			$where .= ' AND post_type = %s';
			$args[] = $f['type'];
		}

		$res['copy_only']      = array();
		$res['left_to_writer'] = array();
		$res['kses_skipped']   = 0;

		$handler = static function ( $row ) use ( $engine, $f, &$res ) {
			if ( ! self::post_row_allowed( $row ) ) {
				return false;
			}
			$id   = (int) $row['ID'];
			$hits = 0;
			$new  = array();
			$per  = array();
			foreach ( array( 'post_content', 'post_title', 'post_excerpt' ) as $col ) {
				$h           = 0;
				$new[ $col ] = $engine->apply_value( (string) $row[ $col ], $h );
				$per[ $col ] = $h;
				$hits       += $h;
			}
			if ( $hits < 1 ) {
				return false;
			}
			$way = self::way( $id );
			if ( 'writer' === $way['way'] && $per['post_content'] > 0 ) {
				// The page builder's writer changes this page (table builders)
				// and keeps post_content in step where the builder does. Here it
				// would change a copy the page does not show (storage B), or one
				// of two copies the builder keeps together (A2).
				// A page whose post_content table builders replaced as text
				// (as_text) is named there; it is not left to the writer.
				$list = 'B' === $way['storage'] ? 'copy_only' : 'left_to_writer';
				if ( count( $res[ $list ] ) < self::SAMPLE_CAP && ! isset( self::$run['as_text'][ $id ] ) ) {
					$res[ $list ][] = $id;
				}
				$hits               -= $per['post_content'];
				$new['post_content'] = (string) $row['post_content'];
				if ( $hits < 1 ) {
					return false;
				}
			}
			// Scope raw: a page-builder page is written only with an undo point,
			// and at most BUILDER_POSTS of them per call (also in the preview, so
			// that both stop at the same page). A page the replacement leaves
			// byte for byte is not written, so it needs none.
			if ( 'raw' === $f['scope'] && null !== $way['builder'] && self::differs( $row, $new ) ) {
				$undo = self::raw_undo( $id, $engine );
				if ( 'cap' === $undo ) {
					$res['notes'][] = self::cap_note();
					return 'stop_before';
				}
				if ( is_wp_error( $undo ) ) {
					$res['matched']++;
					$res['occurrences'] += $hits;
					self::add_note( $res, $undo->get_error_message() );
					return true;
				}
			}
			// Scope text: such a page gets its undo point too (text_undo()),
			// within the same share per call; beyond it, text_cap_stops().
			if ( self::text_undo_due( $way ) && self::differs( $row, $new ) && ! self::text_undo_slot( $id ) && self::text_cap_stops( $id ) ) {
				$res['notes'][] = self::cap_note();
				return 'stop_before';
			}
			self::note_without_writer( $id, $way );
			$res['matched']++;
			$res['occurrences'] += $hits;
			$sample = array(
				'id'    => $id,
				'type'  => (string) $row['post_type'],
				'title' => get_the_title( $id ),
				'hits'  => $hits,
			);
			self::write_fields( $id, $row, $new, $sample, $f, $res, $way['builder'], $engine );
			return true;
		};

		self::scan_by_id( "SELECT ID, post_type, post_parent, post_content, post_title, post_excerpt FROM {$wpdb->posts}", 'ID', 'ID', $where, $args, array( 'post_content', 'post_title', 'post_excerpt' ), $handler, $engine, $f, $res, 'posts' );
		if ( array() !== $res['copy_only'] ) {
			$res['notes'][] = __( 'copy_only: post_content of these page-builder pages is only a copy the page does not show, so it was left out here; the page builder\'s writer replaces their texts (table builders) and keeps the copy in step where the builder does.', 'alphabridge-mcp' );
		}
		if ( array() !== $res['left_to_writer'] ) {
			$res['notes'][] = __( 'left_to_writer: post_content of these pages is one of two copies their page builder keeps together; the page builder\'s writer replaces the text in both where it can, and table builders names what it left and why.', 'alphabridge-mcp' );
		}
		return $res;
	}

	/**
	 * Judge and write the replaced fields of one post (the posts scan, and an
	 * A2 page whose writer found nothing): the kses guard, the sample, the
	 * note, wp_update_post().
	 *
	 * @param int         $id      Post id.
	 * @param array       $row     Columns as read (post_content, post_title, post_excerpt).
	 * @param array       $new     The same columns after the replacement.
	 * @param array       $sample  Sample entry for the answer.
	 * @param array       $f       Flags.
	 * @param array       $res     Table result (by reference; needs kses_skipped).
	 * @param string|null $builder Page builder of the post, or null.
	 * @param AB_MCP_Replace_Engine|null $engine Engine (for the undo part of scope text).
	 */
	private static function write_fields( $id, array $row, array $new, array $sample, array $f, array &$res, $builder, $engine = null ) {
		// A match whose replacement gives the bytes already stored (search
		// equal to replace, a pattern that puts back what it found) is no
		// change: counted as a hit, not written and not counted as changed —
		// wp_update_post() would still stamp post_modified, keep a revision
		// and run the save hooks that empty page-builder caches. The terms
		// scan treats such a row the same way.
		if ( ! self::differs( $row, $new ) ) {
			if ( count( $res['samples'] ) < self::SAMPLE_CAP ) {
				$sample['skipped'] = 'unchanged';
				$res['samples'][]  = $sample;
			}
			return;
		}
		// An account kses filters: a post that WordPress would store
		// otherwise than asked is left as it is, in scope raw too — there
		// wp_update_post() stripped an iframe, a form or a script a super
		// admin had placed, and the post counted as changed (issue #14).
		$why = self::kses_refusal( $id, $row, $new );
		if ( count( $res['samples'] ) < self::SAMPLE_CAP ) {
			if ( null !== $why ) {
				$sample['skipped'] = 'kses';
			}
			$res['samples'][] = $sample;
		}
		if ( null !== $why ) {
			$res['kses_skipped']++;
			self::add_note(
				$res,
				sprintf(
					/* translators: 1: post id, 2: what kses would change, e.g. "a svg tag", 3: column */
					__( 'Post %1$d: not written. For your account WordPress filters every field of a post it saves (kses), also the ones this replacement leaves alone, and it would store %2$s in %3$s differently, beyond the replaced text. An account that may post unfiltered HTML (on a network: a super admin) can run this replacement, or change the post in its editor.', 'alphabridge-mcp' ),
					$id,
					$why[0],
					$why[1]
				)
			);
			return;
		}
		// Scope raw on a page whose page builder keeps its own copy where no
		// scan reads (SeedProd): only post_content changes, and the answer
		// says that the builder's next save brings the old text back.
		$own_copy = 'raw' === $f['scope'] && null !== $builder && isset( $new['post_content'], $row['post_content'] ) && (string) $new['post_content'] !== (string) $row['post_content'] && self::own_copy_unscanned( $id );
		if ( ! $f['apply'] ) {
			self::text_over_written( $id );
			if ( $own_copy ) {
				self::$run['raw']['own_copy'][ $id ] = self::way( $id )['name'];
			}
			return;
		}
		if ( 'text' === $f['scope'] && null !== $engine ) {
			self::text_undo( $id, $engine );
		}
		$upd = array( 'ID' => $id );
		foreach ( $new as $col => $val ) {
			if ( $val !== $row[ $col ] ) {
				$upd[ $col ] = $val;
			}
		}
		// wp_update_post() expects slashed data; without wp_slash() the
		// backslashes inside Gutenberg block attributes (https:\/\/…) would be
		// stripped on write. Without $wp_error: a post whose page template is
		// gone would be written and still counted as failed, its hooks
		// (caches, search index) never run.
		$r2 = wp_update_post( wp_slash( $upd ) );
		if ( $r2 && ! is_wp_error( $r2 ) ) {
			$res['changed']++;
			self::text_over_written( $id );
			if ( $own_copy ) {
				self::$run['raw']['own_copy'][ $id ] = self::way( $id )['name'];
			}
			if ( null !== $builder ) {
				self::$run['engine_written'][ $id ] = $builder;
			}
			// Held by the token only where the page has its undo part and
			// nothing but post_content changed: titles and excerpts are in no
			// undo point of this tool.
			if ( ! ( isset( self::$run['text']['locs'][ $id ] ) && array( 'ID', 'post_content' ) === array_keys( $upd ) ) ) {
				self::$run['uncovered'] = true;
			}
		} else {
			/* translators: %d: post id */
			self::add_note( $res, sprintf( __( 'Post %d: WordPress did not save it. WordPress refuses a post whose title, content and excerpt would all be empty, a plugin can refuse a save (filter wp_insert_post_empty_content), and the database can refuse the write; wp_get_post shows the post as it is.', 'alphabridge-mcp' ), $id ) );
		}
		if ( 'raw' === $f['scope'] && null !== $builder ) {
			self::raw_mark( $id );
		}
		if ( 'text' === $f['scope'] ) {
			self::text_mark( $id );
		}
	}

	/**
	 * Whether a replacement changes any byte of the post columns it covers.
	 *
	 * @param array $row Columns as read.
	 * @param array $new The same columns after the replacement.
	 * @return bool
	 */
	private static function differs( array $row, array $new ) {
		foreach ( $new as $col => $val ) {
			if ( ! array_key_exists( $col, $row ) || (string) $val !== (string) $row[ $col ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Why WordPress would store a post otherwise than asked, for an account
	 * kses filters — or null. wp_update_post() merges the stored post into
	 * what it is given and saves every field again (wp-includes/post.php,
	 * wp_update_post(): get_post(), array_merge(); wp_insert_post():
	 * sanitize_post( …, 'db' )), so kses runs over the fields a replacement
	 * leaves alone too: a title change would strip the SVG of an unchanged
	 * content, or the JSON a page builder keeps in post_content_filtered.
	 * Each field is judged in the form it would be written, the changed ones
	 * first (their reason is the likelier one).
	 *
	 * @param int   $id  Post id.
	 * @param array $row Columns as read.
	 * @param array $new Columns after the replacement.
	 * @return array|null [ what kses would change, column ]
	 */
	private static function kses_refusal( $id, array $row, array $new ) {
		if ( ! AB_MCP_Kses_Guard::applies() ) {
			return null;
		}
		$order = array();
		foreach ( $new as $col => $val ) {
			if ( ! array_key_exists( $col, $row ) || $val !== (string) $row[ $col ] ) {
				$order[] = $col;
			}
		}
		foreach ( array_unique( array_merge( $order, self::KSES_COLUMNS ) ) as $col ) {
			if ( array_key_exists( $col, $new ) ) {
				$value = (string) $new[ $col ];
			} elseif ( array_key_exists( $col, $row ) ) {
				$value = (string) $row[ $col ];
			} else {
				$value = self::stored_column( $id, $col );
			}
			$why = AB_MCP_Kses_Guard::difference( $value, $col );
			if ( null !== $why ) {
				return array( $why, $col );
			}
		}
		return null;
	}

	/**
	 * A post column the scan did not read, as stored (post_content_filtered).
	 *
	 * @param int    $id  Post id.
	 * @param string $col Column.
	 * @return string
	 */
	private static function stored_column( $id, $col ) {
		global $wpdb;
		if ( 'post_content_filtered' !== $col ) {
			return '';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the stored bytes kses would see.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT post_content_filtered FROM {$wpdb->posts} WHERE ID = %d", (int) $id ) );
		return null === $value ? '' : (string) $value;
	}

	/**
	 * postmeta: every key the reader would hand out (including allow-listed
	 * protected builder keys such as _elementor_data).
	 *
	 * @param AB_MCP_Replace_Engine $engine          Engine.
	 * @param array                     $f               Flags.
	 * @param array                     $elementor_posts Collector: post ids whose _elementor_data matched (by ref).
	 * @return array
	 */
	private static function phase_postmeta( $engine, $f, &$elementor_posts ) {
		global $wpdb;
		$res                   = self::table_result();
		$res['locked_matches'] = 0;
		$res['notes'][]        = __( 'Meta keys that look like credentials are never scanned.', 'alphabridge-mcp' );
		$args  = array();
		$where = "p.post_status NOT IN ('auto-draft','trash')";
		if ( $f['exclude_revisions'] ) {
			$where .= " AND p.post_type <> 'revision'";
		}
		// Internal types never enter the scan; every other row is judged per
		// row by the post's and the key's rules. `type` narrows the posts scan
		// only, as the schema says.
		$where .= self::internal_post_type_sql( 'p.post_type', $args );
		// Credential-like keys never enter the scan, nor do the attachment
		// keys WordPress writes through functions of its own.
		$where .= self::sensitive_key_sql( 'pm.meta_key', $args );
		$where .= ' AND ' . self::sql_name_normalized( 'pm.meta_key' ) . ' NOT IN (' . implode( ',', array_fill( 0, count( self::META_KEYS_WITH_OWN_WRITER ), '%s' ) ) . ')';
		foreach ( self::META_KEYS_WITH_OWN_WRITER as $key ) {
			$args[] = $key;
		}
		$where .= self::post_ids_sql( 'pm.post_id', $f, $args );

		// Page-builder keys (markup or code the builder prints) take the
		// capability the builder's own save path takes. A protected key that
		// holds plain text — an alt text — does not; it is written through the
		// filter the media screen uses instead. Read once per scan.
		$markup_keys = self::markup_meta_keys();

		$handler = static function ( $row ) use ( $engine, $f, &$res, &$elementor_posts, $markup_keys ) {
			$pid = (int) $row['post_id'];
			// Defence in depth behind the SQL exclusion.
			if ( in_array( (string) $row['meta_key'], self::META_KEYS_WITH_OWN_WRITER, true ) ) {
				return false;
			}
			// The reader's rules, all of them: the post (and a revision's
			// parent), then the key — protected outside the allow-list,
			// credential-like, edit_post_meta — as wp_search applies them.
			if ( ! self::post_row_allowed( array( 'ID' => $pid, 'post_type' => $row['post_type'], 'post_parent' => $row['post_parent'] ) ) ) {
				return false;
			}
			if ( ! self::meta_hit_allowed( $pid, (string) $row['meta_key'] ) ) {
				return false;
			}
			// A builder payload needs the capability the builder's own save path
			// needs. Asked here, before the value is looked at: asked later, a
			// refused row would still have counted as a hit and used up a slot
			// of the row limit, which is a yes/no oracle on its content.
			if ( self::is_markup_meta_key( (string) $row['meta_key'], $markup_keys ) && ! current_user_can( 'unfiltered_html' ) ) {
				return false;
			}
			$key = (string) $row['meta_key'];
			$way = self::way( $pid );
			// A copy of the page that the page builder's writer changes (table
			// builders): never through the engine as well. Decided by the key,
			// before the value is read.
			if ( 'writer' === $way['way'] && in_array( 'meta:' . $key, $way['locs'], true ) ) {
				return false;
			}
			$value = self::db_value( (string) $row['meta_value'], $engine );
			if ( null === $value ) {
				return false;
			}
			$hits = 0;
			$new  = $engine->apply_value( $value, $hits );
			if ( $hits < 1 ) {
				return false;
			}
			// The page's own code that its page builder locks (custom CSS and
			// JS, header code): scope text leaves it and counts the matches.
			if ( 'writer' === $way['way'] && AB_MCP_Builders::is_locked_key( $key, $way['locked'] ) ) {
				$res['locked_matches'] += $hits;
				self::$run['locked']   += $hits;
				return false;
			}
			if ( 'raw' === $f['scope'] && null !== $way['builder'] ) {
				$undo = self::raw_undo( $pid, $engine );
				if ( 'cap' === $undo ) {
					$res['notes'][] = self::cap_note();
					return 'stop_before';
				}
				if ( is_wp_error( $undo ) ) {
					$res['matched']++;
					$res['occurrences'] += $hits;
					self::add_note( $res, $undo->get_error_message() );
					return true;
				}
			}
			if ( self::text_undo_due( $way ) && ! self::text_undo_slot( $pid ) && self::text_cap_stops( $pid ) ) {
				$res['notes'][] = self::cap_note();
				return 'stop_before';
			}
			self::note_without_writer( $pid, $way );
			$res['matched']++;
			$res['occurrences'] += $hits;
			if ( count( $res['samples'] ) < self::SAMPLE_CAP ) {
				$res['samples'][] = array(
					'post_id'  => $pid,
					'type'     => (string) $row['post_type'],
					'meta_key' => (string) $row['meta_key'],
					'hits'     => $hits,
				);
			}
			if ( ! $f['apply'] ) {
				self::text_over_written( $pid );
			}
			if ( $f['apply'] ) {
				// update_metadata_by_mid() targets exactly this row, re-serializes
				// and clears the meta cache. It takes the value RAW: unlike
				// update_metadata() it does not unslash, so the wp_slash() that
				// used to sit here put a backslash before every quote of a JSON
				// payload such as Elementor's (a defect since the tool
				// first shipped).
				$key = (string) $row['meta_key'];
				// The alt text is plain text to WordPress: the media screen puts
				// it through wp_strip_all_tags( …, true ) — tags and line breaks
				// out, everything else left alone, percent-encoded octets
				// included — and so does this, rather than asking for a
				// capability that screen does not ask for.
				if ( '_wp_attachment_image_alt' === $key && is_string( $new ) && function_exists( 'wp_strip_all_tags' ) ) {
					$new = wp_strip_all_tags( $new, true );
				}
				if ( 'text' === $f['scope'] ) {
					self::text_undo( $pid, $engine );
				}
				$written = update_metadata_by_mid( 'post', (int) $row['meta_id'], $new );
				if ( $written && 'raw' === $f['scope'] && null !== $way['builder'] ) {
					if ( ! in_array( 'meta:' . $key, isset( self::$run['raw']['locs'][ $pid ] ) ? self::$run['raw']['locs'][ $pid ] : array(), true ) && null !== self::$run['raw']['token'] ) {
						self::$run['raw']['unsaved'][] = 'post ' . $pid . ', ' . $key;
					}
					self::raw_mark( $pid );
				}
				if ( $written && 'text' === $f['scope'] ) {
					self::text_meta_written( $pid, $key );
				}
				if ( $written ) {
					$res['changed']++;
					self::text_over_written( $pid );
					if ( null !== $way['builder'] ) {
						self::$run['engine_written'][ $pid ] = $way['builder'];
					}
					// Only now: the generated CSS is stale because this payload
					// changed. Counting a post whose payload was not written
					// would report a clean-up for a change that never happened.
					if ( '_elementor_data' === $row['meta_key'] ) {
						$elementor_posts[ $pid ] = $pid;
					}
				}
			}
			return true;
		};

		self::scan_by_id( "SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value, p.post_type, p.post_parent FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id", 'pm.meta_id', 'meta_id', $where, $args, array( 'pm.meta_value' ), $handler, $engine, $f, $res, 'postmeta' );
		return $res;
	}

	/**
	 * options: transients, the plugin's own rows, widget rows and the names
	 * the option guard refuses never enter the scan.
	 *
	 * @param AB_MCP_Replace_Engine $engine Engine.
	 * @param array                     $f      Flags.
	 * @return array
	 */
	private static function phase_options( $engine, $f ) {
		global $wpdb;
		$res            = self::table_result();
		$res['notes'][] = __( 'Widget options (widget_*, the option of every registered widget, sidebars_widgets) are never scanned; the widget tools are the way in. Protected options are never touched.', 'alphabridge-mcp' );

		// All on the normalised name: update_option() trims the name it is
		// given, so a stored row called " widget_text" would be written back
		// as widget_text — past the widget sanitiser. The handler
		// refuses such rows too.
		$norm  = self::sql_name_normalized( 'option_name' );
		$where = "{$norm} NOT LIKE %s AND {$norm} NOT LIKE %s AND {$norm} NOT LIKE %s AND {$norm} NOT LIKE %s AND {$norm} <> %s";
		$args  = array(
			$wpdb->esc_like( '_transient_' ) . '%',
			$wpdb->esc_like( '_site_transient_' ) . '%',
			$wpdb->esc_like( 'ab_mcp_' ) . '%',
			$wpdb->esc_like( 'widget_' ) . '%',
			'sidebars_widgets',
		);
		$where .= self::option_guard_sql( $args );
		// And the option every registered widget stores under or reads back
		// from — option_name need not start with widget_, and get_settings()
		// falls back to the legacy alt_option_name — by exact normalised name.
		$widget_names = self::widget_option_names( true );
		$where       .= " AND {$norm} NOT IN (" . implode( ',', array_fill( 0, count( $widget_names ), '%s' ) ) . ')';
		foreach ( $widget_names as $widget_name ) {
			$args[] = $widget_name;
		}

		// The rows to change, collected first: their undo point is recorded
		// before any of them is written (write_options()).
		$collect = array();
		$handler = static function ( $row ) use ( $engine, &$res, &$collect ) {
			$name = (string) $row['option_name'];
			// A name update_option() would trim is not this row's name: writing
			// it would land on another row. Such a row is left alone.
			if ( trim( $name ) !== $name ) {
				return false;
			}
			// Defence in depth behind the SQL exclusions: a widget's own update()
			// is what keeps script out of Text, Custom HTML and Block widgets for
			// users without unfiltered_html; update_option() would skip it. And
			// the guard's finer rules (collation look-alikes, over-long or
			// invisible names) have no SQL form.
			if ( self::widget_option_name( $name ) || self::option_blocked( $name ) ) {
				return false;
			}
			// Only a row that update_option( $name ) would write, and no other.
			if ( ! self::option_row_is_target( $name, (int) $row['option_id'] ) ) {
				return false;
			}
			$value = self::db_value( (string) $row['option_value'], $engine );
			if ( null === $value ) {
				return false;
			}
			$hits = 0;
			$new  = $engine->apply_value( $value, $hits );
			if ( $hits < 1 ) {
				return false;
			}
			$res['matched']++;
			$res['occurrences'] += $hits;
			if ( count( $res['samples'] ) < self::SAMPLE_CAP ) {
				$res['samples'][] = array(
					'option' => $name,
					'hits'   => $hits,
				);
			}
			$collect[] = array(
				'name' => $name,
				'id'   => (int) $row['option_id'],
				'raw'  => (string) $row['option_value'],
				'new'  => $new,
			);
			return true;
		};

		self::scan_by_id( "SELECT option_id, option_name, option_value FROM {$wpdb->options}", 'option_id', 'option_id', $where, $args, array( 'option_value' ), $handler, $engine, $f, $res, 'options' );
		if ( $f['apply'] && array() !== $collect ) {
			self::write_options( $collect, $f, $res );
		}
		return $res;
	}

	/**
	 * Write the option rows a replace found, after their undo point: one point
	 * (kind option_rows) with the old value of every row about to be written,
	 * recorded before the first of them is. Without it nothing is written,
	 * unless undo is switched off on the site or allow_without_undo lets a
	 * point over the size limit go. A row whose stored value changed since the
	 * scan read it is left as it is, and is not in the point either: an undo
	 * must not write back over a change this call did not make.
	 *
	 * @param array $collect Rows: name, id, raw (the stored value read), new.
	 * @param array $f       Flags.
	 * @param array $res     Table result (by reference).
	 */
	private static function write_options( array $collect, array $f, array &$res ) {
		global $wpdb;
		$snaps = array();
		foreach ( $collect as $c ) {
			// Still the value the scan read? Otherwise somebody changed it in
			// between, and the replacement was made on the old value.
			$now = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_id = %d", $c['id'] ) ); // phpcs:ignore WordPress.DB
			if ( null === $now || (string) $now !== $c['raw'] ) {
				self::add_note( $res, 'The option "' . $c['name'] . '" changed while this call ran; it was not written. Run the call again.' );
				continue;
			}
			$snap = AB_MCP_Undo::option_snapshot( $c['name'] );
			if ( ! is_array( $snap ) || empty( $snap['existed'] ) || $snap['name'] !== $c['name'] ) {
				self::add_note( $res, 'The option "' . $c['name'] . '" could not be read for its undo point; it was not written. Try again.' );
				continue;
			}
			$snaps[ $c['name'] ] = $snap;
		}
		if ( array() === $snaps ) {
			return;
		}
		$names = array_keys( $snaps );
		$token = AB_MCP_Undo::record_result(
			array(
				'tool'    => 'wp_search_replace',
				'cap'     => 'manage_options',
				'kind'    => 'option_rows',
				'summary' => sprintf( '%d options: %s', count( $names ), implode( ', ', array_slice( $names, 0, 5 ) ) . ( count( $names ) > 5 ? ', …' : '' ) ),
				'items'   => count( $names ),
				'data'    => array_values( $snaps ),
			)
		);
		if ( is_wp_error( $token ) ) {
			$code = $token->get_error_code();
			$off  = 'ab_mcp_undo_disabled' === $code;
			$size = 'ab_mcp_undo_too_large' === $code && $f['allow_without_undo'];
			if ( ! $off && ! $size ) {
				self::$run['warnings'][] = sprintf(
					/* translators: %s: why the undo point could not be recorded */
					__( 'The option rows were not written: their undo point could not be recorded (%s). Try again; allow_without_undo=true writes them without a way back where the undo point is only too large.', 'alphabridge-mcp' ),
					$token->get_error_message()
				);
				return;
			}
			self::$run['warnings'][] = $off
				? __( 'Undo is switched off on this site: the option rows were written without an undo point.', 'alphabridge-mcp' )
				: __( 'The option rows were written without an undo point, as allow_without_undo asked: it would have been over the size limit.', 'alphabridge-mcp' );
			$token = null;
		}
		self::$run['options_token'] = $token;
		$kept = array();
		foreach ( $collect as $c ) {
			if ( ! isset( $snaps[ $c['name'] ] ) ) {
				continue;
			}
			// Read once more right before the write: a write earlier in this
			// loop can change a later row (a hook on updated_option), and the
			// replacement was made on the value the scan read.
			$now = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_id = %d", $c['id'] ) ); // phpcs:ignore WordPress.DB
			if ( null === $now || (string) $now !== $c['raw'] ) {
				self::add_note( $res, 'The option "' . $c['name'] . '" changed while this call ran; it was not written. Run the call again.' );
				continue;
			}
			$kept[ $c['name'] ] = $snaps[ $c['name'] ];
			// update_option() re-serializes, keeps the autoload flag and
			// maintains the options cache. A row it refuses keeps its value,
			// so its old value in the undo point writes back what is there.
			if ( update_option( $c['name'], $c['new'] ) ) {
				$res['changed']++;
			} else {
				self::add_note( $res, 'update_option returned false for "' . $c['name'] . '" (a sanitize filter may have rejected the value).' );
			}
		}
		if ( null !== $token && count( $kept ) < count( $snaps ) ) {
			self::narrow_options_point( (string) $token, $kept, $res );
		}
	}

	/**
	 * Narrow the undo point of the option rows to the rows that were written,
	 * where one changed between the point and its write and was left alone:
	 * the undo must not write back over that change. The narrowed point takes
	 * the place of the first (replaces: it pushes out no other point), which
	 * is then removed. Where the narrowed point cannot be recorded, the first
	 * is removed all the same: no undo point is safer than one that would
	 * reset a row this call did not write.
	 *
	 * @param string $token Token of the point recorded before the writes.
	 * @param array  $kept  Snapshots of the rows written, by name.
	 * @param array  $res   Table result (by reference).
	 */
	private static function narrow_options_point( $token, array $kept, array &$res ) {
		$new = null;
		if ( array() !== $kept ) {
			$names = array_keys( $kept );
			$new   = AB_MCP_Undo::record_result(
				array(
					'tool'     => 'wp_search_replace',
					'cap'      => 'manage_options',
					'kind'     => 'option_rows',
					'summary'  => sprintf( '%d options: %s', count( $names ), implode( ', ', array_slice( $names, 0, 5 ) ) . ( count( $names ) > 5 ? ', …' : '' ) ),
					'items'    => count( $names ),
					'data'     => array_values( $kept ),
					'replaces' => $token,
				)
			);
			if ( is_wp_error( $new ) ) {
				self::add_note( $res, 'The option rows written have no undo point: one row changed during this call and was left alone, and the undo point could not be narrowed to the rows written (' . $new->get_error_message() . '). Check the rows written before you rely on them.' );
				$new = null;
			}
		}
		self::$run['options_token'] = $new;
		// Twice, as wp_undo does: a busy index is the one failure a moment
		// cures. A point already gone needs no removing.
		$gone = static function () use ( $token ) {
			$d = AB_MCP_Undo::discard( $token );
			return ! is_wp_error( $d ) || 'ab_mcp_undo_not_found' === $d->get_error_code();
		};
		if ( ! $gone() && ! $gone() ) {
			self::add_note( $res, 'The undo point ' . $token . ' also holds option rows that were not written, and could not be removed. Remove it with wp_undo_discard; do not use it with wp_undo.' );
		}
	}

	/**
	 * terms: names, descriptions, termmeta. Slugs are never touched.
	 *
	 * @param AB_MCP_Replace_Engine $engine Engine.
	 * @param array                     $f      Flags.
	 * @return array
	 */
	private static function phase_terms( $engine, $f ) {
		global $wpdb;
		$res            = self::table_result();
		$res['notes'][] = __( 'No slug is ever asked to change (permalinks), and a term that has no slug is passed over rather than given one; a WordPress filter that changes a slug along with a name is listed in slug_changes. Names and descriptions are written through wp_update_term(). limit applies to each of the three scans (names, descriptions, termmeta). Internal taxonomies (wp_theme, wp_template_part_area, post_format) and credential-like termmeta keys are never scanned.', 'alphabridge-mcp' );
		$touched        = array();

		// Every scan joins the taxonomy: the internal ones are excluded in SQL
		// (the term NAME of wp_theme is the theme slug that binds block templates
		// to the theme — a replacement that happens to hit it detaches every
		// template), and the filters WordPress runs before storing a name or
		// description need it. A term in more than one taxonomy appears once per
		// taxonomy and is written once. Names and descriptions go through
		// wp_update_term(); only the term meta is written by meta id, and $wpdb is
		// fetched inside that handler — a closure does not see the function's
		// global, and before 4.5.5 the writes failed on a null $wpdb, so terms
		// were never rewritten in apply mode.
		$tax_args  = array();
		$tax_where = '1=1' . self::internal_taxonomy_sql( 'tt.taxonomy', $tax_args );
		$skip      = static function ( $row ) {
			// Defence in depth behind the SQL exclusion; silent on purpose.
			return self::internal_taxonomy( (string) $row['taxonomy'] );
		};

		// 1) Term names (plain column, no serialization possible).
		$handled_tid  = 0;
		$handler_name = static function ( $row ) use ( $engine, $f, &$res, &$handled_tid, $skip ) {
			$tid = (int) $row['term_id'];
			// A term in more than one taxonomy comes once per taxonomy; the FIRST
			// row that passes the policy handles it, refused ones do not use up
			// the term (a refused taxonomy first would otherwise hide an allowed
			// one). The rows of one id are contiguous — the scan orders by (id,
			// term_taxonomy_id) across batches and windows — so the id handled
			// last is all that needs remembering, not every id seen.
			if ( $skip( $row ) || $tid === $handled_tid || ! self::term_row_allowed( $tid, (string) $row['taxonomy'] ) || ! self::term_row_writable( $tid ) ) {
				return false;
			}
			// One name row serves every taxonomy of a term; a term in more than
			// one is not written at all, so nothing can make that untrue.
			$handled_tid = $tid;
			$hits = 0;
			$new  = $engine->replace_string( (string) $row['name'], $hits );
			if ( $hits < 1 ) {
				return false;
			}
			$res['matched']++;
			$res['occurrences'] += $hits;
			if ( count( $res['samples'] ) < self::SAMPLE_CAP ) {
				$res['samples'][] = array(
					'term_id'  => $tid,
					'taxonomy' => (string) $row['taxonomy'],
					'field'    => 'name',
					'hits'     => $hits,
				);
			}
			if ( $f['apply'] && self::write_term_field( $row, 'name', $new, $res ) ) {
				$res['changed']++;
			}
			return true;
		};
		self::scan_by_id( "SELECT t.term_id, t.name, tt.taxonomy, tt.term_taxonomy_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id", 't.term_id', 'term_id', $tax_where, $tax_args, array( 't.name' ), $handler_name, $engine, $f, $res, 'term_names', 'tt.term_taxonomy_id', 'term_taxonomy_id' );

		// 2) Term descriptions.
		$handler_desc = static function ( $row ) use ( $engine, $f, &$res, $skip ) {
			$tid = (int) $row['term_id'];
			if ( $skip( $row ) || ! self::term_row_allowed( $tid, (string) $row['taxonomy'] ) || ! self::term_row_writable( $tid ) ) {
				return false;
			}
			$hits = 0;
			$new  = $engine->replace_string( (string) $row['description'], $hits );
			if ( $hits < 1 ) {
				return false;
			}
			$res['matched']++;
			$res['occurrences'] += $hits;
			if ( count( $res['samples'] ) < self::SAMPLE_CAP ) {
				$res['samples'][] = array(
					'term_id'  => $tid,
					'taxonomy' => (string) $row['taxonomy'],
					'field'    => 'description',
					'hits'     => $hits,
				);
			}
			if ( $f['apply'] && self::write_term_field( $row, 'description', $new, $res ) ) {
				$res['changed']++;
			}
			return true;
		};
		self::scan_by_id( "SELECT tt.term_taxonomy_id, tt.term_id, tt.taxonomy, tt.description FROM {$wpdb->term_taxonomy} tt", 'tt.term_taxonomy_id', 'term_taxonomy_id', $tax_where, $tax_args, array( 'tt.description' ), $handler_desc, $engine, $f, $res, 'term_descriptions' );

		// 3) Term meta (serialized values possible). Credential-like keys never
		// enter the scan.
		$meta_args    = $tax_args;
		$meta_where   = $tax_where . self::sensitive_key_sql( 'tm.meta_key', $meta_args );
		$handled_mid  = 0;
		$handler_meta = static function ( $row ) use ( $engine, $f, &$res, &$touched, &$handled_mid, $skip ) {
			$mid = (int) $row['meta_id'];
			$tid = (int) $row['term_id'];
			// As for names: the rows of one meta id are contiguous, the one
			// handled last is all that needs remembering.
			if ( $skip( $row ) || $mid === $handled_mid || ! self::term_meta_allowed( $tid, (string) $row['meta_key'], (string) $row['taxonomy'] ) || ! self::term_row_writable( $tid, false ) ) {
				return false;
			}
			$handled_mid = $mid;
			$value = self::db_value( (string) $row['meta_value'], $engine );
			if ( null === $value ) {
				return false;
			}
			$hits = 0;
			$new  = $engine->apply_value( $value, $hits );
			if ( $hits < 1 ) {
				return false;
			}
			$res['matched']++;
			$res['occurrences'] += $hits;
			if ( count( $res['samples'] ) < self::SAMPLE_CAP ) {
				$res['samples'][] = array(
					'term_id'  => $tid,
					'taxonomy' => (string) $row['taxonomy'],
					'field'    => 'meta:' . $row['meta_key'],
					'hits'     => $hits,
				);
			}
			if ( $f['apply'] ) {
				// Nothing to write: the value already reads that way. Asked
				// before any refusal, because a write that would not happen
				// needs no permission and no explanation.
				if ( $new === $value ) {
					return true;
				}
				// By meta id, which names the one row the scan read.
				// update_term_meta() would be the WordPress way and was tried
				// here, but it picks the row by its previous value, and
				// update_metadata() drops that condition whenever the previous
				// value is empty() — a stored "0" would have every row of that
				// term and key overwritten at once. Identity comes first.
				//
				// What that costs: the update_term_metadata filter is asked
				// here rather than by core, and the value is sanitised twice
				// (once for that filter, once inside update_metadata_by_mid),
				// which differs from core only for a sanitise_callback that
				// answers differently on a second call.
				$for_filter = function_exists( 'sanitize_meta' ) ? sanitize_meta( (string) $row['meta_key'], $new, 'term', (string) $row['taxonomy'] ) : $new;
				$taken      = apply_filters( 'update_term_metadata', null, $tid, (string) $row['meta_key'], $for_filter, '' );
				if ( null !== $taken ) {
					self::term_note( $res, $tid, 'a plugin took over or refused this meta write through the update_term_metadata filter' );
					return true;
				}
				// And against the sanitised value, as update_metadata() compares
				// it: a sanitiser that turns the replacement back into what is
				// stored leaves nothing to write, and the update actions should
				// not fire for it.
				if ( $for_filter === $value ) {
					return true;
				}
				// Raw: unlike update_metadata(), this one does not unslash.
				if ( update_metadata_by_mid( 'term', $mid, $new ) ) {
					$res['changed']++;
					$touched[ (string) $row['taxonomy'] ][] = $tid;
				}
			}
			return true;
		};
		self::scan_by_id( "SELECT tm.meta_id, tm.term_id, tm.meta_key, tm.meta_value, tt.taxonomy, tt.term_taxonomy_id FROM {$wpdb->termmeta} tm INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id", 'tm.meta_id', 'meta_id', $meta_where, $meta_args, array( 'tm.meta_value' ), $handler_meta, $engine, $f, $res, 'termmeta', 'tt.term_taxonomy_id', 'term_taxonomy_id' );

		// For the meta writes only — wp_update_term() cleans up after the name
		// and description writes itself, and update_metadata_by_mid() clears the
		// meta cache; this clears the term objects that carry it. Per taxonomy:
		// without one, clean_term_cache() takes the ids for term-taxonomy ids
		// and misses the terms where the two differ.
		if ( $f['apply'] ) {
			foreach ( $touched as $taxonomy => $ids ) {
				clean_term_cache( array_values( array_unique( $ids ) ), $taxonomy );
			}
		}
		return $res;
	}

	/**
	 * A key Elementor names in one of its class constants.
	 *
	 * Read from the constant while Elementor is loaded, so a renamed key is
	 * followed; the fallback is its value in Elementor 4.3.3, for a site where
	 * Elementor is off and the cache would be stale once it is back on.
	 *
	 * @param string $constant Fully qualified class constant.
	 * @param string $fallback Its value in Elementor 4.3.3.
	 * @return string
	 */
	private static function elementor_constant( $constant, $fallback ) {
		if ( defined( $constant ) ) {
			$key = constant( $constant );
			if ( is_string( $key ) && '' !== $key ) {
				return $key;
			}
		}
		return $fallback;
	}

	/**
	 * Unserialize a raw DB value; null when the serialized payload is corrupt.
	 *
	 * @param string                    $raw    Raw column value.
	 * @param AB_MCP_Replace_Engine $engine Engine (corruption counter).
	 * @return mixed|null
	 */
	private static function db_value( $raw, $engine ) {
		if ( preg_match( '/^\s*S:\d+:"/', $raw ) ) {
			// An escaped string record, which is_serialized() does not know; a
			// replacement inside it would corrupt its byte length.
			$engine->skipped_object_values++;
			return null;
		}
		if ( ! is_serialized( $raw ) ) {
			return $raw;
		}
		$trimmed = trim( $raw );
		// No class is ever restored from a stored value: an `O:` payload in a
		// meta or option row (any plugin form that writes what a visitor sent)
		// would otherwise run its __wakeup the moment an administrator searched.
		// Object, Serializable and enum records are found by walking the text,
		// not in the unserialized structure: an enum (`E:`) reaches the
		// autoloader even with classes disallowed, and a duplicate array key can
		// make an object vanish from the structure while it stays in the row —
		// which WordPress unserializes again, classes and all, on the next write.
		// A row that carries any such record is left alone.
		if ( $engine->carries_record( $trimmed ) ) {
			$engine->skipped_object_values++;
			return null;
		}
		$value = @unserialize( $trimmed, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Stored data; classes deliberately not restored.
		if ( false === $value && 'b:0;' !== $trimmed ) {
			$engine->skipped_corrupt++;
			return null;
		}
		if ( $engine->holds_object( $value ) ) {
			if ( $engine->gave_up ) {
				$engine->skipped_unchecked_values++;
				return null;
			}
			$engine->skipped_object_values++;
			return null;
		}
		return $value;
	}

	/**
	 * Is an option name protected? The guard of the option tools
	 * (AB_MCP_Option_Guard).
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	private static function option_blocked( $name ) {
		// option_write_refused(), not option_is_blocked(): this writes option
		// rows like the option tools do and has to refuse everything they
		// refuse. The narrow question only compares the name as written, so a
		// row the collation equates with a protected one — "actiue_plugins"
		// against active_plugins — was rewritten here while wp_update_option
		// refused it. It also carries the length and invisible-character
		// rules, for the same reason.
		return (bool) AB_MCP_Option_Guard::option_write_refused( $name );
	}

	/**
	 * Protected ("_") meta keys that include_protected may return. Deliberately
	 * an allow-list of known builder/layout fields: a block-list could never
	 * know every secret another plugin decides to store in post meta, and this
	 * data leaves the site through an external API.
	 *
	 * Sites that genuinely need another key can add it via the filter — that is
	 * an intentional extension point, and the per-key capability still applies.
	 *
	 * @return string[]
	 */
	public static function protected_meta_allowlist() {
		return (array) apply_filters(
			'ab_mcp_pro_protected_meta_allowlist',
			array(
				'_elementor_data',
				'_elementor_page_settings',
				'_elementor_edit_mode',
				'_elementor_template_type',
				'_elementor_controls_usage',
				'_wp_page_template',
				'_thumbnail_id',
				'_wp_attached_file',
				'_wp_attachment_image_alt',
				'_wp_attachment_metadata',
			)
		);
	}

	/**
	 * Post meta keys in which a page builder keeps markup or code that ends up
	 * in the page: layout trees with HTML fields, compiled HTML, page CSS and
	 * JavaScript. A builder's own save path filters such data for an account
	 * without unfiltered_html, or refuses it; a direct write through a meta
	 * tool would skip that, so every key here takes that capability. That
	 * holds for keys without "_" too: several builders keep their payload
	 * under a public name, which the meta tools would otherwise write for
	 * anyone who may edit the post.
	 *
	 * Four Pro writers ask this list: wp_update_post_meta, the "meta"
	 * argument of wp_create_post and wp_update_post, and wp_search_replace.
	 * wp_db_execute does not; it writes table rows, postmeta included,
	 * without asking for unfiltered_html. An entry ending in "*" matches
	 * every key that starts with what comes before it.
	 *
	 * Every key stands here with its source, from the page-builder survey of
	 * 30.09.2026 (alphabridge-docs, docs/recherche/2026-09-30-page-builder-messung/):
	 * "measured" = seen in a throwaway install, "code" = read in the builder's
	 * source, "vendor" = the builder's documentation or staff, "third party"
	 * = only other plugins name it. A key without a source stays out.
	 *
	 * The filter can extend the list or narrow it; narrowing hands that key
	 * back to accounts without unfiltered_html.
	 *
	 * @return string[]
	 */
	public static function markup_meta_keys() {
		return (array) apply_filters(
			'ab_mcp_pro_markup_meta_keys',
			array(
				// Elementor: layout JSON, page settings and the keys that decide
				// how it renders. Measured (README.md, ergebnis-1); the five keys
				// wp_search_replace guarded before this list existed.
				'_elementor_data',
				'_elementor_page_settings',
				'_elementor_edit_mode',
				'_elementor_template_type',
				'_elementor_controls_usage',
				// Beaver Builder: published and draft layout, and their settings,
				// which carry the page's own CSS and JS. Measured: the keys
				// (ergebnis-3), css/js in the settings (ergebnis-1); code:
				// update_layout_data() picks _fl_builder_data or _fl_builder_draft
				// (ergebnis-2). The draft settings key: vendor,
				// github.com/beaverbuilder/documentation beaver-builder/settings/tools.md.
				'_fl_builder_data',
				'_fl_builder_draft',
				'_fl_builder_data_settings',
				'_fl_builder_draft_settings',
				// SiteOrigin Page Builder: the layout, editor widget HTML and button
				// on_click included; the page renders from it. Measured: meta/README.md 1.
				'panels_data',
				// Live Composer: the module tree, HTML module and button onclick
				// included. Measured: meta/README.md 6.
				'dslc_code',
				// Pagelayer: the layout tree its abilities keep, and page code it
				// prints raw into head, after body and into the footer. Measured
				// and code: seedprod-pagelayer/README.md (d, g; init.php:1574, :1595, :1616).
				'pagelayer-data',
				'pagelayer_header_code',
				'pagelayer_body_open_code',
				'pagelayer_footer_code',
				// Brizy: the editor data and the compiled HTML the page is served
				// from. Measured: meta/README.md 2.
				'brizy',
				'brizy-compiled-sections',
				// Visual Composer Website Builder: the editor's source, the page JS
				// for head and footer (vcv-settingsLocalJsHead/-Footer) and the page
				// CSS. Measured and code: meta/README.md 4.
				'vcv-pageContent',
				'vcv-settingsLocalJs*',
				'vcvSettingsSourceCustomCss',
				// Themify Builder: the layout, the page JS it prints as <script>
				// and the page CSS. Measured and code: meta/README.md 3.
				'_themify_builder_settings_json',
				'tbp_custom_js',
				'tbp_custom_css',
				// Zion Builder: the element tree, custom HTML included. Measured:
				// meta/README.md 5.
				'_zionbuilder_page_elements',
				// BeTheme (BeBuilder): the serialized layout. CVE-2024-2694 names
				// the key (quellen/bricks-breakdance-betheme.md, verbreitung.md 6).
				'mfn-page-items',
				// Thrive Architect: the page's finished HTML, the generated CSS and
				// the user's CSS. Vendor: quellen/thrive-architect.md (a).
				'tve_updated_post',
				'tve_custom_css',
				'tve_user_custom_css',
				// Bricks: content, header, footer and page settings (page code).
				// Vendor: academy.bricksbuilder.io/developer/schema/
				// (quellen/bricks-breakdance-betheme.md, B1).
				'_bricks_page_content_2',
				'_bricks_page_header_2',
				'_bricks_page_footer_2',
				'_bricks_page_settings',
				// Breakdance: the tree. Vendor: github.com/soflyy/breakdance-playground
				// (quellen/bricks-breakdance-betheme.md, K1).
				'_breakdance_data',
				// Oxygen Classic: shortcodes up to 3.x, JSON tree from 4.0. Vendor
				// and third party: quellen/oxygen-klassisch.md (a). Oxygen 6: the
				// tree; third party and users only: quellen/oxygen-6.md (a).
				'ct_builder_shortcodes',
				'ct_builder_json',
				'_oxygen_data',
				// Cornerstone: the element tree. Vendor staff: quellen/cornerstone.md (a).
				'_cornerstone_data',
				// Enfold: the layout builder's shortcodes, which the page renders.
				// Vendor documentation and code: quellen/enfold.md (a).
				'_aviaLayoutBuilderCleanData',
				// WPBakery: the design CSS built on save (vendor code example) and
				// the page CSS (third party). quellen/wpbakery.md (a).
				'_wpb_shortcodes_custom_css',
				'_wpb_post_custom_css',
				// Avada: the page's builder CSS. Third party: quellen/avada.md (a).
				'_fusion_builder_custom_css',
			)
		);
	}

	/**
	 * Is this key page-builder data (markup_meta_keys())? Letter case does
	 * not count: MySQL compares meta_key under a case-insensitive collation
	 * (utf8mb4_unicode_520_ci, wp-includes/class-wpdb.php:901), and
	 * update_metadata() picks the row it overwrites by that comparison
	 * (meta.php:265). The lower-cased key a writer stores after sanitize_key()
	 * — "vcv-pagecontent" — therefore lands on the row "vcv-pageContent".
	 *
	 * @param string        $key  Meta key.
	 * @param string[]|null $keys The list, when the caller already has it.
	 * @return bool
	 */
	public static function is_markup_meta_key( $key, $keys = null ) {
		$key = strtolower( trim( (string) $key ) );
		if ( '' === $key ) {
			return false;
		}
		foreach ( null === $keys ? self::markup_meta_keys() : (array) $keys as $entry ) {
			if ( ! is_scalar( $entry ) ) {
				continue;
			}
			$entry = strtolower( trim( (string) $entry ) );
			if ( '*' === substr( $entry, -1 ) ) {
				// A bare "*" is no prefix: it would put every meta key of the
				// site behind unfiltered_html by accident.
				$prefix = substr( $entry, 0, -1 );
				if ( '' !== $prefix && 0 === strpos( $key, $prefix ) ) {
					return true;
				}
			} elseif ( '' !== $entry && $entry === $key ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * May this protected key be exposed? Allow-list membership is not enough on
	 * its own — the per-key meta capability must also pass, so any
	 * register_meta() auth_callback still has the final say.
	 *
	 * @param string $key Meta key.
	 * @param int    $id  Post id.
	 * @return bool
	 */
	public static function may_expose_protected( $key, $id ) {
		if ( ! in_array( $key, self::protected_meta_allowlist(), true ) ) {
			return false;
		}
		// A key registered through register_meta()/register_post_meta() carries an
		// auth_callback. That callback is the site's own explicit decision about
		// who may touch the key, so it wins over anything we think.
		if ( registered_meta_key_exists( 'post', $key, (string) get_post_type( $id ) )
			|| registered_meta_key_exists( 'post', $key ) ) {
			return current_user_can( 'edit_post_meta', $id, $key );
		}
		// Unregistered protected key — the normal case for builder payloads like
		// _elementor_data. WordPress default-DENIES edit_post_meta for every
		// protected key that has no auth_callback, so asking it here would deny
		// even an administrator and make the allow-list unreachable: the feature
		// would silently return nothing. For these keys the allow-list is itself
		// the authorisation decision (they are vetted layout data, not secrets);
		// editing rights on the post are still required.
		return current_user_can( 'edit_post', $id );
	}
}
