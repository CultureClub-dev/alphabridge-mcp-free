<?php
/**
 * The preview protection of a staging site (free since 4.15.0).
 *
 * A staging site is shown to a client before it goes live, and nobody else
 * should read it: not a visitor who guesses the address, not a search
 * engine. With the protection on, a visitor without a WordPress login sees a
 * password page instead of the site, for every page, post, archive, feed,
 * search and the sitemaps of SEO plugins, and the public REST API answers
 * such a visitor 401 as well, for every namespace (wp/v2 gives the content
 * away, oembed/1.0 titles and excerpts, the Store API of a shop its
 * products). A visitor who enters the shared password is remembered in a
 * browser cookie for 14 days.
 *
 * Where the front-end gate hangs, and why there:
 *
 * - First line: parse_request at priority 1 (gate_parse_request()), the
 *   action WP::parse_request() fires once it has turned the request into
 *   $wp->query_vars, before WP::main() goes on to the headers, the query and
 *   the template. SEO plugins answer their sitemaps on the way between: Yoast
 *   SEO on pre_get_posts at priority 1 (WPSEO_Sitemaps::redirect() prints
 *   the sitemap and ends the request), others on parse_request at the
 *   default priority 10; a gate on template_redirect alone never saw them.
 *   Measured on 31.10.2026 on a live site (WordPress 7.1.4, Yoast SEO 28.8):
 *   every page, post, archive, feed, search and REST route answered 401,
 *   /sitemap_index.xml, /post-sitemap.xml, /page-sitemap.xml,
 *   /category-sitemap.xml and /?sitemap=1 answered 200 with every address.
 *   Priority 1, not 0: a handler at 0 (WooCommerce's wc-api on
 *   parse_request) answers before the gate today and keeps doing so.
 * - Not covered, by the same token: what answers before WordPress has parsed
 *   the request, on parse_request at 0, on init or on wp_loaded (a plugin's
 *   own download or form handler). Their behaviour is unchanged, and the
 *   tool's description says so.
 * - Second line: template_redirect at priority 1 (gate_frontend()) and the
 *   action wp for the entry points without themes (gate_without_themes(),
 *   below), as before. A plugin can filter do_parse_request to false; then
 *   parse_request never fires, template_redirect does. The decision is one
 *   function (frontend_decision()), so a request the first line passed is
 *   decided the same way again; DONOTCACHEPAGE is defined once (guarded)
 *   and nocache_headers() replaces headers of the same name, so the second
 *   run changes nothing.
 * - wp_loaded for wp-links-opml.php, which runs no wp() (gate_script()).
 * - The REST API decides on rest_authentication_errors (gate_rest()).
 *
 * What the protection must never lock, because AlphaBridge itself and the
 * hub come in through it (the list of the survey of 31.10.2026, each with a
 * test of its own), in the order of the clauses of frontend_decision():
 *
 * - The MCP endpoint and every other route under this plugin's REST
 *   namespace: the token is checked in the route's own permission callback,
 *   so on rest_authentication_errors nobody is signed in yet; the OAuth
 *   routes (register, token, revoke) are public by their RFCs.
 * - Every REST request, on the first line: rest_api_loaded() (the core,
 *   wp-includes/rest-api.php) serves on parse_request at priority 10 every
 *   request whose $wp->query_vars['rest_route'] is not empty, set by the
 *   rewrite rule of pretty permalinks or by ?rest_route=; REST_REQUEST is
 *   not defined at priority 1. The clause rest_route is exactly that
 *   condition, read from the variable WordPress parsed and never from the
 *   path (a prefix like /wp-json/ decides nothing), and gate_rest() then
 *   decides on rest_authentication_errors as for every REST request.
 * - The two OAuth metadata documents below /.well-known/ and the consent
 *   page /?ab_mcp_oauth=authorize, which AB_MCP_OAuth serves on
 *   template_redirect at priority 0 (serve_well_known() while the sign-in
 *   is on, handle_authorize()). The gate does not pass them and wait for
 *   that: between parse_request and template_redirect the plugins above
 *   answer, and /.well-known/oauth-authorization-server?sitemap=1 or
 *   /?ab_mcp_oauth=authorize&sitemap=1 would be their sitemap, not the
 *   document. It calls the handler itself (decision serve, serve()), and the
 *   handler ends every request it serves: send_json() exits;
 *   handle_authorize() ends with the consent screen, the redirect to the
 *   login, the redirect back to the client or an error page (each branch
 *   exits or wp_die()s). Should a handler return, the password page follows.
 *   Each clause asks the handler's own routing decision, so what the gate
 *   hands over is exactly what the handler serves:
 *   AB_MCP_OAuth::well_known_document() (a double slash is neither
 *   document) and AB_MCP_OAuth::is_authorize_request() (?ab_mcp_oauth=0 on
 *   any page is nothing the handler serves, and is the password page; Codex,
 *   31.10.2026). The consent clause holds with themes only, where the
 *   handler would run on template_redirect; on wp-signup.php the password
 *   page as before. On template_redirect the handlers at 0 have answered
 *   already, so the clauses there are for the case that order changes.
 *   Not the rest of /.well-known/: WordPress would answer those with the
 *   theme's 404 page, menus and sidebars included. The hub asks for
 *   /.well-known/alphabridge.json first when it looks for the endpoint of
 *   a site, and reads any status but 200 as «not there» (alphabridge-connect
 *   src/detect.ts, readWellKnown()), so the password page there sends it on
 *   to the probe of the MCP endpoint as a 404 would.
 * - The REST root /wp-json/ (the namespace index): the hub reads it to find
 *   the endpoint; a locked root makes a protected staging site impossible
 *   to link. For a visitor without login and cookie the index is reduced to
 *   the namespaces, which is all the hub reads (alphabridge-connect
 *   src/detect.ts, hasWordPressRest(): ?_fields=namespaces, wp/v2 among
 *   them): the full index names the site, its description, address and
 *   timezone, every route, and links the site logo and icon, which ?_embed
 *   turns into the two media records through an internal dispatch that no
 *   rest_authentication_errors filter sees (index()).
 * - While on, every REST answer carries WordPress's no-cache headers
 *   (rest_send_nocache_headers), not only those of signed-in users: an
 *   answer to a visitor with the cookie must not be stored for the next
 *   visitor without one, and the 401 of everyone else is nothing to keep.
 * - REST requests of signed-in users (Gutenberg and the site editor with
 *   the cookie and the nonce, application passwords): this filter runs
 *   after WordPress's own checks (priority 100), where is_user_logged_in()
 *   is settled, and passes an error of theirs through.
 * - wp-admin, wp-login.php, admin-ajax.php, admin-post.php, wp-cron.php and
 *   xmlrpc.php run no wp(), so neither parse_request nor template_redirect
 *   fires there; the checks on the request constants are kept all the
 *   same, each with its test, so a later change of the hooks cannot lock
 *   them by accident — all but DOING_AJAX, which a plugin may define on a
 *   front-end request (see below). admin-ajax.php and admin-post.php have
 *   a gate of their own on admin_init (below).
 *
 * Where WordPress gives content out without template_redirect (measured on
 * the core, 31.10.2026), the gate hangs a second time:
 *
 * - wp-signup.php, wp-activate.php and wp-trackback.php run wp() without
 *   WP_USE_THEMES; the template loader then skips template_redirect but
 *   still answers is_feed() with do_feed(), so /wp-signup.php?feed=rss2 is
 *   the whole feed. The first line decides there as everywhere, with
 *   $with_themes = wp_using_themes(); the gate also hangs on the action wp,
 *   where it runs only when wp_using_themes() is false
 *   (gate_without_themes()); with themes template_redirect follows and runs
 *   it once. Signed-in users and cookie holders pass there as everywhere, so
 *   the sign-up of a network keeps working for them; a single site's
 *   wp-signup.php redirects to the registration anyway.
 * - wp-links-opml.php loads WordPress without wp() and prints the bookmarks;
 *   the gate runs for it on wp_loaded (gate_script()).
 * - wp-activate.php defines WP_INSTALLING before it loads WordPress, and
 *   wp_get_active_and_valid_plugins() returns nothing while wp_installing()
 *   (wp-includes/load.php): no plugin loads, this one included, and
 *   wp-blog-header.php then serves ?rest_route= and ?feed= before the
 *   script's own redirect. Must-use plugins load all the same
 *   (wp-settings.php loads wp_get_mu_plugins() without that check), so
 *   while the protection is on the plugin keeps a small loader at
 *   WPMU_PLUGIN_DIR/alphabridge-preview-protection.php (loader_source()),
 *   which does something only under wp_installing(), with this plugin
 *   active, its files in place and the protection on: it loads this class
 *   and hooks the gates. It does so on plugins_loaded (priority 0), which
 *   fires under WP_INSTALLING as well, after the must-use and the network
 *   plugins (wp-settings.php): a network-activated plugin that bundles this
 *   core (the Pro add-on) has loaded the classes by then and hooks the
 *   gates itself on init, so the loader only calls hook() and requires
 *   nothing whose class exists (a second copy of the core from another
 *   folder would be a fatal error). Written on switching on and on activation, kept
 *   current on every load (hook()), removed on switching off, on
 *   deactivation and by uninstall.php; on a network it stays while another
 *   site's protection is on, since the folder is shared. Where the folder
 *   cannot be written, the protection goes on all the same and the tool
 *   and the settings page say so, with the file to create by hand.
 * - robots.txt passes only on the way with themes: without themes the
 *   template loader prints robots.txt and returns to the script, and
 *   wp-signup.php then renders its page with the theme's header and footer;
 *   so gate_without_themes() and the loader's gates do not exempt it. On
 *   template_redirect is_robots() decides; on parse_request WP_Query has
 *   not run yet (it sets is_robots when '' !== $qv['robots']), so the gate
 *   reads the query var robots WordPress parsed, and passes only when it is
 *   the only one: /?robots=1&sitemap=1 is the sitemap of an SEO plugin on
 *   pre_get_posts, not robots.txt, and on template_redirect, where the
 *   template loader answers is_robots() first, it does not matter any more.
 * - Embeds (/embed/, ?embed=true), previews (?p=…&preview=true) and the
 *   feeds of index.php load the template loader with themes and so pass
 *   template_redirect. wp-comments-post.php prints no content of the site.
 *   xmlrpc.php stays open: its reads need a login, and pingback.ping, which
 *   needs none, answers no visitor's browser; a signed-in XML-RPC client
 *   carries no cookie, so a lock there would hit the clients and spare
 *   nobody. Files under /wp-content/ never reach PHP.
 *
 * admin-ajax.php and admin-post.php run no wp() either, and their nopriv
 * actions (wp_ajax_nopriv_*, admin_post_nopriv_*) are where plugins and
 * themes print content for visitors: «load more» and endless scrolling, an
 * AJAX search with titles and excerpts, the cart fragments of a shop. The
 * core's own nopriv actions print none (measured on the core, 31.10.2026),
 * and an earlier version of this header said so of all of them, measured
 * on the core alone. So a gate of its own hangs on admin_init at priority 1
 * (gate_admin(), ADMIN_PRIORITY), the action both scripts fire before they
 * dispatch the action: after a handler a plugin put at 0 on purpose, before
 * the plugins at the default priority 10, where handlers on admin_init print
 * downloads and exports. It decides only while AJAX runs (wp_doing_ajax())
 * or the script is admin-post.php ($pagenow): the pages of wp-admin send a
 * visitor to the login themselves (admin_decision()). A signed-in user and
 * a cookie holder pass, so the AJAX a page makes from the browser after the
 * password was entered works; so do the two nopriv actions of the core by
 * name, heartbeat and generate-password (the password suggestion of the
 * reset form on wp-login.php), which print no content of the site
 * (CORE_NOPRIV). Everyone else: for AJAX «0» with 401, as admin-ajax.php
 * answers an action nobody handles; for admin-post.php the password page,
 * whose form posts to the home page, since this gate takes no password.
 * AlphaBridge has no nopriv action of its own (tests/StagingCardTest.php
 * holds that) and the connection check asks neither script. The front-end
 * gates have no clause for DOING_AJAX: admin-ajax.php never reaches them,
 * and a plugin may define the constant on a front-end request, as
 * WooCommerce does on init for its ?wc-ajax= (served on template_redirect
 * at 0), where it would have opened /?sitemap=1&wc-ajax=… to the sitemap
 * (Codex, 31.10.2026); so ?wc-ajax= goes through wp(), meets the first
 * line on parse_request and is locked for a visitor without login and
 * cookie like every front-end request, which is what the lock of the
 * nopriv actions means for a shop's cart fragments as well. The cookie is
 * set for COOKIEPATH and, where it differs, SITECOOKIEPATH (cookie_paths()),
 * so a visitor who entered the password reaches the two admin scripts under
 * the WordPress folder too.
 * - robots.txt: with blog_public 0 WordPress answers Disallow: /, which a
 *   crawler reads only if the file is not behind the password.
 * - A visitor with a valid cookie, also for the REST calls a page makes
 *   from the browser (a shop's Store API, forms).
 *
 * The password page answers 401, not 200, and every front-end answer while
 * the protection is on carries DONOTCACHEPAGE and nocache_headers(): a page
 * cache stores 200 answers and would hand the page a visitor with the cookie
 * saw to everyone after. The page cache of the supported caching plugins is
 * emptied when the protection goes on and when it goes off
 * (AB_MCP_Page_Cache::purge_site()). Caches outside PHP (a host cache,
 * Cloudflare APO, a CDN) are not reached, and files under
 * /wp-content/uploads/ are delivered by the web server without PHP: the
 * tool and the settings page say both (limits()).
 *
 * Switching on remembers the site's blog_public and sets it to 0, so
 * WordPress itself adds noindex, disables its sitemaps and answers
 * robots.txt with Disallow: /; switching off writes the remembered value
 * back, never a plain 1. X-Robots-Tag: noindex, nofollow goes on every
 * front-end answer while on: through wp_headers where WP::send_headers()
 * runs, and from the gates themselves where an answer ends before it or
 * without it (parse_request, wp-links-opml.php, the two admin scripts;
 * send_robots_header()). Deactivating the plugin takes the
 * gates with it, so it writes the remembered value back as well, notes that
 * it did (restored) and keeps the state; activating it again sets 0 once
 * more, taking the value found as the new remembered one where the note is
 * there (a 0 set by hand while deactivated is a choice), and keeping the
 * old one where it is not (the 0 is this plugin's own, left by a
 * deactivation whose hook never ran); on a network both run on every site,
 * leaving out a site where the plugin stays active the other way (a local
 * activation under a network-wide deactivation, a network activation under
 * a local one: WordPress keeps the other activation, and the protection
 * runs on; the hooks fire before WordPress writes the activation options,
 * so both still show) (on_deactivate(), on_activate()). uninstall.php writes the value back
 * before it removes the option. A deactivated plugin that left «discouraged»
 * behind would be a change nobody can see the reason for.
 *
 * The password is generated here and shown once, by the tool in its answer
 * and on the settings page; only its hash is stored. The cookie is an HMAC
 * over the hash with the site's salt, so a new password makes every cookie
 * invalid at once. A shared password identifies nobody.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Preview_Protection
 */
class AB_MCP_Preview_Protection {

	/**
	 * Key of the state in AB_MCP_Settings::OPT_OPTIONS (the option
	 * ab_mcp_options, which uninstall.php removes).
	 */
	const KEY = 'preview_protection';

	/**
	 * Name of the cookie a visitor gets after entering the password.
	 */
	const COOKIE = 'ab_mcp_preview';

	/**
	 * How long the cookie lasts.
	 */
	const COOKIE_DAYS = 14;

	/**
	 * Name of the password field of the password page.
	 */
	const FIELD = 'ab_mcp_preview_password';

	/**
	 * Length of a generated password, and the characters it is made of:
	 * letters and digits without the ones people confuse when they read a
	 * password aloud (0 and O, 1, l and I).
	 */
	const PASSWORD_LENGTH   = 16;
	const PASSWORD_ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';

	/**
	 * Priority on parse_request: after handlers at 0 (WooCommerce's wc-api),
	 * before rest_api_loaded() and the plugins at the default priority 10,
	 * and before everything WP::main() does after parse_request (the query
	 * with pre_get_posts, where Yoast SEO answers its sitemaps).
	 */
	const PARSE_PRIORITY = 1;

	/**
	 * Priority on template_redirect: after AB_MCP_OAuth's handlers at 0
	 * (the well-known documents and the consent page), before
	 * redirect_canonical at 10.
	 */
	const TEMPLATE_PRIORITY = 1;

	/**
	 * Priority on admin_init, which admin-ajax.php and admin-post.php fire
	 * before they dispatch the action: after a handler at 0, before the
	 * plugins at the default priority 10, where handlers on admin_init print
	 * downloads and exports.
	 */
	const ADMIN_PRIORITY = 1;

	/**
	 * The nopriv actions of the core (admin-ajax.php) a visitor keeps:
	 * heartbeat and generate-password (the password suggestion of the reset
	 * form on wp-login.php); neither prints content of the site.
	 */
	const CORE_NOPRIV = array( 'heartbeat', 'generate-password' );

	/**
	 * Priority on rest_authentication_errors: after WordPress's
	 * rest_application_password_check_errors (90) and
	 * rest_cookie_check_errors (100), so is_user_logged_in() is settled.
	 */
	const REST_PRIORITY = 110;

	/**
	 * The value of X-Robots-Tag while the protection is on.
	 */
	const ROBOTS = 'noindex, nofollow';

	/**
	 * Id of the section on the settings page.
	 */
	const ANCHOR = 'ab-preview';

	/**
	 * File name of the must-use loader under WPMU_PLUGIN_DIR, and the
	 * version of its source: raised whenever loader_source() changes, so a
	 * loader an earlier version wrote is replaced (hook()).
	 */
	const LOADER_FILE    = 'alphabridge-preview-protection.php';
	const LOADER_VERSION = 2;

	/**
	 * Where a change came from.
	 */
	const SOURCE_TOOL = 'tool';
	const SOURCE_FORM = 'form';

	/**
	 * Wire the gates and the header. Called once from the plugin bootstrap,
	 * on init.
	 */
	public static function hook() {
		// The loader for the requests WordPress serves without plugins, kept
		// current: a loader of an earlier version is replaced, a missing one
		// written. Not under wp_installing(), where this very loader runs.
		if ( ! wp_installing() && self::is_on() ) {
			self::write_loader();
		}
		// The first line, once WordPress has parsed the request; the two
		// below are the second, for a request that arrives without
		// parse_request (do_parse_request filtered to false).
		add_action( 'parse_request', array( __CLASS__, 'gate_parse_request' ), self::PARSE_PRIORITY );
		add_action( 'template_redirect', array( __CLASS__, 'gate_frontend' ), self::TEMPLATE_PRIORITY );
		add_action( 'wp', array( __CLASS__, 'gate_without_themes' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'gate_script' ) );
		add_action( 'admin_init', array( __CLASS__, 'gate_admin' ), self::ADMIN_PRIORITY );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'gate_rest' ), self::REST_PRIORITY );
		add_filter( 'rest_index', array( __CLASS__, 'index' ), 10, 2 );
		add_filter( 'rest_send_nocache_headers', array( __CLASS__, 'nocache' ) );
		add_filter( 'wp_headers', array( __CLASS__, 'headers' ) );
	}

	/**
	 * Activation: a protection left on when the plugin was deactivated
	 * (on_deactivate() wrote the visibility back and kept the state) takes
	 * the visibility again, on every site of a network when activated for
	 * the network. The value found is remembered anew where the deactivation
	 * noted that it wrote the old one back (restored): whatever stands there
	 * now, a 0 included, is a choice made while the plugin was off. Without
	 * the note (a deactivation whose hook never ran) a 0 is this plugin's
	 * own and the remembered value stays; any other value is remembered.
	 *
	 * @param bool $network_wide Activated for the whole network.
	 */
	public static function on_activate( $network_wide = false ) {
		self::each_site(
			(bool) $network_wide,
			static function () use ( $network_wide ) {
				$state = self::state();
				if ( ! $state['on'] ) {
					return;
				}
				// Active here the other way already: the protection runs,
				// nothing to remember or set again.
				if ( $network_wide ? self::active_locally() : self::active_network() ) {
					return;
				}
				$before = get_option( 'blog_public', '1' );
				if ( is_scalar( $before ) && ( $state['restored'] || '0' !== (string) $before ) ) {
					self::write(
						array(
							'blog_public' => (string) $before,
							'restored'    => false,
						)
					);
				} else {
					self::write( array( 'restored' => false ) );
				}
				update_option( 'blog_public', 0 );
				self::write_loader();
				AB_MCP_Page_Cache::purge_site();
			}
		);
	}

	/**
	 * Deactivation: the gates are gone, so the remembered visibility goes
	 * back and the state notes it (restored); the state (hash and all) stays
	 * for the next activation, as the plugin keeps its other settings. On
	 * every site of a network when deactivated for the network. The page
	 * cache is emptied, so no password page is served from it after. The
	 * loader goes too, once no site of the network runs the protection any
	 * more (running_anywhere()).
	 *
	 * @param bool $network_wide Deactivated for the whole network.
	 */
	public static function on_deactivate( $network_wide = false ) {
		self::each_site(
			(bool) $network_wide,
			static function () use ( $network_wide ) {
				$state = self::state();
				if ( ! $state['on'] ) {
					return;
				}
				// Still active here the other way: the protection runs on,
				// the visibility stays.
				if ( $network_wide ? self::active_locally() : self::active_network() ) {
					return;
				}
				if ( null !== $state['blog_public'] ) {
					update_option( 'blog_public', $state['blog_public'] );
				}
				self::write( array( 'restored' => true ) );
				AB_MCP_Page_Cache::purge_site();
			}
		);
		// The loader stays while a site of the network runs the protection:
		// one left out above (active the other way), or one deactivated
		// another time and still running.
		if ( ! is_multisite() || ! self::running_anywhere() ) {
			self::remove_loader();
		}
	}

	/**
	 * Run a step on this site, or on every site of the network when asked
	 * for the network: the state and blog_public are per site. Every site,
	 * not the hundred get_sites() stops at by default (as uninstall.php).
	 *
	 * @param bool     $network_wide Every site of the network.
	 * @param callable $step         What to do on each site.
	 */
	private static function each_site( $network_wide, $step ) {
		if ( ! $network_wide || ! is_multisite() ) {
			$step();
			return;
		}
		$sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
		foreach ( (array) $sites as $site_id ) {
			switch_to_blog( (int) $site_id );
			$step();
			restore_current_blog();
		}
	}

	/* ------------------------------------------------------------ state */

	/**
	 * The stored state, in a known shape. Anything other than exactly true
	 * under 'on' with a hash reads as off: a broken record never locks.
	 *
	 * @return array{on:bool,hash:string,blog_public:string|null,since:int,user_id:int,user_login:string,source:string,restored:bool}
	 */
	public static function state() {
		$raw = AB_MCP_Settings::get( self::KEY, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$out = array(
			'on'          => false,
			'hash'        => isset( $raw['hash'] ) && is_string( $raw['hash'] ) ? $raw['hash'] : '',
			'blog_public' => array_key_exists( 'blog_public', $raw ) && is_scalar( $raw['blog_public'] ) ? (string) $raw['blog_public'] : null,
			'since'       => isset( $raw['since'] ) ? (int) $raw['since'] : 0,
			'user_id'     => isset( $raw['user_id'] ) ? (int) $raw['user_id'] : 0,
			'user_login'  => isset( $raw['user_login'] ) && is_string( $raw['user_login'] ) ? $raw['user_login'] : '',
			'source'      => isset( $raw['source'] ) && is_string( $raw['source'] ) ? $raw['source'] : '',
			'restored'    => ! empty( $raw['restored'] ),
		);
		$out['on'] = isset( $raw['on'] ) && true === $raw['on'] && '' !== $out['hash'];
		return $out;
	}

	/**
	 * Is the protection on?
	 *
	 * @return bool
	 */
	public static function is_on() {
		return self::state()['on'];
	}

	/**
	 * Switch the protection on with a new password. Already on: nothing
	 * changes and no password is returned (renew_password() sets a new one).
	 *
	 * @param int    $user_id Who switches.
	 * @param string $source  SOURCE_TOOL or SOURCE_FORM.
	 * @return array{changed:bool,password:string,loader?:array,page_cache:array}
	 */
	public static function enable( $user_id, $source ) {
		$state = self::state();
		if ( $state['on'] ) {
			return array(
				'changed'    => false,
				'password'   => '',
				'page_cache' => array(
					'purged' => array(),
					'failed' => array(),
				),
			);
		}
		$password = self::generate_password();
		// Remembered as stored, so that '1', 1 or '' go back as they were.
		$before = get_option( 'blog_public', '1' );
		self::store(
			array(
				'on'          => true,
				'hash'        => self::hash_password( $password ),
				'blog_public' => is_scalar( $before ) ? (string) $before : '1',
			),
			(int) $user_id,
			(string) $source
		);
		update_option( 'blog_public', 0 );
		return array(
			'changed'    => true,
			'password'   => $password,
			'loader'     => self::loader_status( self::write_loader() ),
			'page_cache' => AB_MCP_Page_Cache::purge_site(),
		);
	}

	/**
	 * Set a new password while the protection is on; every cookie issued
	 * for the old one stops working.
	 *
	 * @param int    $user_id Who sets it.
	 * @param string $source  SOURCE_TOOL or SOURCE_FORM.
	 * @return array{changed:bool,password:string}|null Null when off.
	 */
	public static function renew_password( $user_id, $source ) {
		$state = self::state();
		if ( ! $state['on'] ) {
			return null;
		}
		$password = self::generate_password();
		self::store(
			array(
				'on'          => true,
				'hash'        => self::hash_password( $password ),
				'blog_public' => $state['blog_public'],
			),
			(int) $user_id,
			(string) $source
		);
		return array(
			'changed'  => true,
			'password' => $password,
		);
	}

	/**
	 * Switch the protection off: the remembered blog_public goes back as it
	 * was, the hash goes (so every cookie dies), the page cache is emptied
	 * once more, since caches that do store a 401 would keep the password
	 * page.
	 *
	 * @param int    $user_id Who switches.
	 * @param string $source  SOURCE_TOOL or SOURCE_FORM.
	 * @return array{changed:bool,blog_public:string|null,page_cache:array}
	 */
	public static function disable( $user_id, $source ) {
		$state = self::state();
		if ( ! $state['on'] ) {
			return array(
				'changed'     => false,
				'blog_public' => null,
				'page_cache'  => array(
					'purged' => array(),
					'failed' => array(),
				),
			);
		}
		if ( null !== $state['blog_public'] ) {
			update_option( 'blog_public', $state['blog_public'] );
		}
		self::store( array( 'on' => false ), (int) $user_id, (string) $source );
		if ( ! is_multisite() || ! self::running_anywhere() ) {
			self::remove_loader();
		}
		return array(
			'changed'     => true,
			'blog_public' => $state['blog_public'],
			'page_cache'  => AB_MCP_Page_Cache::purge_site(),
		);
	}

	/* ----------------------------------------------------------- loader */

	/**
	 * Where the must-use loader lives: WPMU_PLUGIN_DIR as WordPress defines
	 * it; '' where nothing says where must-use plugins are.
	 *
	 * @return string
	 */
	public static function loader_path() {
		if ( defined( 'WPMU_PLUGIN_DIR' ) && '' !== (string) WPMU_PLUGIN_DIR ) {
			return rtrim( (string) WPMU_PLUGIN_DIR, '/\\' ) . '/' . self::LOADER_FILE;
		}
		if ( defined( 'WP_CONTENT_DIR' ) && '' !== (string) WP_CONTENT_DIR ) {
			return rtrim( (string) WP_CONTENT_DIR, '/\\' ) . '/mu-plugins/' . self::LOADER_FILE;
		}
		return '';
	}

	/**
	 * The source of the must-use loader. It names the folder of the plugin
	 * that carries this core (this plugin, or the Pro add-on that bundles
	 * it) and the folder of the core's files, as PHP: below WP_PLUGIN_DIR
	 * where the core lives there, else as an absolute path. Everything it
	 * does is guarded: nothing outside wp_installing(), nothing without the
	 * plugin active on this site or the network, nothing when the files are
	 * gone (a plugin deleted over FTP must not leave a fatal error behind),
	 * nothing while the protection is off. It waits for plugins_loaded, after
	 * the network plugins, and requires no file whose class is there already:
	 * a network-activated Pro add-on carries this core itself.
	 *
	 * @return string
	 */
	public static function loader_source() {
		$basename = defined( 'AB_MCP_BASENAME' ) ? (string) AB_MCP_BASENAME : 'alphabridge-mcp/alphabridge-mcp.php';
		$folder   = (string) strtok( $basename, '/' );
		$dir      = defined( 'AB_MCP_DIR' ) ? rtrim( (string) AB_MCP_DIR, '/\\' ) . '/' : '';
		$plugins  = defined( 'WP_PLUGIN_DIR' ) ? rtrim( (string) WP_PLUGIN_DIR, '/\\' ) : '';
		if ( '' !== $plugins && 0 === strpos( $dir, $plugins . '/' ) ) {
			$dir_code = 'WP_PLUGIN_DIR . ' . var_export( substr( $dir, strlen( $plugins ) ), true );
		} else {
			$dir_code = var_export( $dir, true );
		}
		$version = defined( 'AB_MCP_VERSION' ) ? (string) AB_MCP_VERSION : '';
		$lines   = array(
			'<?php',
			'/**',
			' * Plugin Name: AlphaBridge MCP – Preview Protection Loader',
			' * Description: Written by AlphaBridge MCP while its preview protection is on and removed when it goes off. It acts only on the requests WordPress serves without loading plugins (wp-activate.php sets WP_INSTALLING) and loads the protection from the plugin\'s folder for them; otherwise it does nothing. Do not edit: the plugin rewrites it.',
			' * Version: ' . $version,
			' */',
			'// ab-mcp-preview-loader ' . self::LOADER_VERSION . ' ' . $version,
			'if ( ! defined( \'ABSPATH\' ) || ! function_exists( \'wp_installing\' ) || ! wp_installing() ) {',
			'	return;',
			'}',
			'// After the must-use and the network plugins: a network-activated plugin',
			'// that carries this core has loaded its classes by then.',
			'add_action(',
			'	\'plugins_loaded\',',
			'	static function () {',
			'		$ab_mcp_loader_folder = ' . var_export( $folder, true ) . ';',
			'		$ab_mcp_loader_dir    = ' . $dir_code . ';',
			'		$ab_mcp_loader_active = false;',
			'		foreach ( (array) get_option( \'active_plugins\', array() ) as $ab_mcp_loader_plugin ) {',
			'			if ( is_string( $ab_mcp_loader_plugin ) && 0 === strpos( $ab_mcp_loader_plugin, $ab_mcp_loader_folder . \'/\' ) ) {',
			'				$ab_mcp_loader_active = true;',
			'			}',
			'		}',
			'		if ( ! $ab_mcp_loader_active && function_exists( \'is_multisite\' ) && is_multisite() ) {',
			'			foreach ( array_keys( (array) get_site_option( \'active_sitewide_plugins\', array() ) ) as $ab_mcp_loader_plugin ) {',
			'				if ( is_string( $ab_mcp_loader_plugin ) && 0 === strpos( $ab_mcp_loader_plugin, $ab_mcp_loader_folder . \'/\' ) ) {',
			'					$ab_mcp_loader_active = true;',
			'				}',
			'			}',
			'		}',
			'		if ( ! $ab_mcp_loader_active ) {',
			'			return;',
			'		}',
			'		$ab_mcp_loader_options = get_option( \'ab_mcp_options\' );',
			'		if ( ! is_array( $ab_mcp_loader_options ) || ! isset( $ab_mcp_loader_options[\'preview_protection\'] ) || ! is_array( $ab_mcp_loader_options[\'preview_protection\'] ) ) {',
			'			return;',
			'		}',
			'		$ab_mcp_loader_state = $ab_mcp_loader_options[\'preview_protection\'];',
			'		if ( ! isset( $ab_mcp_loader_state[\'on\'], $ab_mcp_loader_state[\'hash\'] ) || true !== $ab_mcp_loader_state[\'on\'] || \'\' === (string) $ab_mcp_loader_state[\'hash\'] ) {',
			'			return;',
			'		}',
			'		// Each file only where its class is not there yet: a network plugin',
			'		// with this core has loaded them, from its own folder.',
			'		$ab_mcp_loader_files = array(',
			'			\'AB_MCP_Settings\'           => \'includes/class-settings.php\',',
			'			\'AB_MCP_Page_Cache\'         => \'includes/class-page-cache.php\',',
			'			\'AB_MCP_Preview_Protection\' => \'includes/class-preview-protection.php\',',
			'		);',
			'		foreach ( $ab_mcp_loader_files as $ab_mcp_loader_class => $ab_mcp_loader_file ) {',
			'			if ( ! class_exists( $ab_mcp_loader_class, false ) && ! is_readable( $ab_mcp_loader_dir . $ab_mcp_loader_file ) ) {',
			'				return;',
			'			}',
			'		}',
			'		foreach ( $ab_mcp_loader_files as $ab_mcp_loader_class => $ab_mcp_loader_file ) {',
			'			if ( ! class_exists( $ab_mcp_loader_class, false ) ) {',
			'				require_once $ab_mcp_loader_dir . $ab_mcp_loader_file;',
			'			}',
			'		}',
			'		if ( class_exists( \'AB_MCP_Preview_Protection\', false ) ) {',
			'			// Idempotent: the core hooks the same callbacks on init where it loaded itself.',
			'			AB_MCP_Preview_Protection::hook();',
			'		}',
			'	},',
			'	0',
			');',
		);
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Is the loader in place, with the source of this version?
	 *
	 * @return bool
	 */
	public static function loader_current() {
		$path = self::loader_path();
		return '' !== $path && is_file( $path ) && (string) file_get_contents( $path ) === self::loader_source(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file this plugin wrote, read back.
	}

	/**
	 * Write the loader where it is missing or of another version: into a
	 * temporary file first, then renamed into place (atomic within the
	 * folder), and read back. As the undo store writes its snapshots.
	 *
	 * @return bool Whether the loader is in place with the current source now.
	 */
	public static function write_loader() {
		$path = self::loader_path();
		if ( '' === $path ) {
			return false;
		}
		if ( self::loader_current() ) {
			return true;
		}
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$tmp = $path . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		if ( false === @file_put_contents( $tmp, self::loader_source() ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a folder that cannot be written is reported, not fatal.
			return false;
		}
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return false;
		}
		return self::loader_current();
	}

	/**
	 * Remove the loader.
	 *
	 * @return bool Whether no loader is there now.
	 */
	public static function remove_loader() {
		$path = self::loader_path();
		if ( '' === $path || ! file_exists( $path ) ) {
			return true;
		}
		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- a file this plugin wrote.
		return ! file_exists( $path );
	}

	/**
	 * On a network: does any site run the protection? Running means: on,
	 * the plugin active there (locally or for the network), and no note of
	 * a deactivation (restored) — a site where the plugin was deactivated
	 * keeps its state for the next activation but protects nothing, and a
	 * site switched off is off. The loader is one file for the whole network
	 * and stays while a site runs the protection.
	 *
	 * @return bool
	 */
	private static function running_anywhere() {
		if ( ! is_multisite() ) {
			return false;
		}
		$sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
		$found = false;
		foreach ( (array) $sites as $site_id ) {
			switch_to_blog( (int) $site_id );
			$state   = self::state();
			$running = $state['on'] && ! $state['restored'] && ( self::active_locally() || self::active_network() );
			restore_current_blog();
			if ( $running ) {
				$found = true;
				break;
			}
		}
		return $found;
	}

	/**
	 * The folder of the plugin that carries this core: this plugin's, or
	 * the Pro add-on's where it bundles the core. What active_plugins and
	 * active_sitewide_plugins name begins with it.
	 *
	 * @return string
	 */
	private static function carrier_folder() {
		$basename = defined( 'AB_MCP_BASENAME' ) ? (string) AB_MCP_BASENAME : 'alphabridge-mcp/alphabridge-mcp.php';
		return (string) strtok( $basename, '/' );
	}

	/**
	 * Is the plugin that carries this core active on the current site by
	 * its own activation (active_plugins)?
	 *
	 * @return bool
	 */
	private static function active_locally() {
		return self::names_carrier( (array) get_option( 'active_plugins', array() ) );
	}

	/**
	 * Is the plugin that carries this core active for the whole network
	 * (active_sitewide_plugins)?
	 *
	 * @return bool
	 */
	private static function active_network() {
		return is_multisite() && self::names_carrier( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}

	/**
	 * Does a list of plugin files name the carrier of this core?
	 *
	 * @param array $plugins Plugin files, folder/file.php.
	 * @return bool
	 */
	private static function names_carrier( array $plugins ) {
		$folder = self::carrier_folder();
		foreach ( $plugins as $plugin ) {
			if ( is_string( $plugin ) && 0 === strpos( $plugin, $folder . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * What the tool and the settings page say about the loader.
	 *
	 * @param bool $written Whether it is in place.
	 * @return array{path:string,written:bool}
	 */
	public static function loader_status( $written ) {
		return array(
			'path'    => self::loader_path(),
			'written' => (bool) $written,
		);
	}

	/**
	 * The sentence for a loader that could not be written, for the tool
	 * (English; the settings page translates its own).
	 *
	 * @return string
	 */
	public static function loader_missing_note() {
		return 'WordPress serves a few requests without loading plugins (wp-activate.php: feeds and the REST API), and the loader that answers them for the protection could not be written to ' . self::loader_path() . '. Create that file with the content in loader_source, or give the folder write permission; until then those requests answer without the password.';
	}

	/**
	 * Change fields of the stored record, leaving the rest as it is.
	 *
	 * @param array $fields Fields to set.
	 */
	private static function write( array $fields ) {
		$raw = AB_MCP_Settings::get( self::KEY, array() );
		AB_MCP_Settings::set( self::KEY, array_merge( is_array( $raw ) ? $raw : array(), $fields ) );
	}

	/**
	 * Write the state with who and when.
	 *
	 * @param array  $fields  on, hash, blog_public.
	 * @param int    $user_id Who.
	 * @param string $source  Where from.
	 */
	private static function store( array $fields, $user_id, $source ) {
		$user  = get_user_by( 'id', $user_id );
		$login = ( is_object( $user ) && isset( $user->user_login ) ) ? (string) $user->user_login : '';
		AB_MCP_Settings::set(
			self::KEY,
			array_merge(
				$fields,
				array(
					'since'      => time(),
					'user_id'    => $user_id,
					'user_login' => $login,
					'source'     => in_array( $source, array( self::SOURCE_TOOL, self::SOURCE_FORM ), true ) ? $source : '',
				)
			)
		);
	}

	/**
	 * What the tool and the settings page report about the state.
	 *
	 * @return array{enabled:bool,since:string,set_by:array|null,blog_public:string,blog_public_before:string|null}
	 */
	public static function summary() {
		$state = self::state();
		$out   = array(
			'enabled'            => $state['on'],
			'loader'             => $state['on'] ? self::loader_status( self::loader_current() ) : null,
			'since'              => $state['on'] && $state['since'] > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $state['since'] ) : '',
			'set_by'             => null,
			'blog_public'        => (string) get_option( 'blog_public', '1' ),
			'blog_public_before' => $state['on'] ? $state['blog_public'] : null,
		);
		if ( $state['on'] ) {
			$out['set_by'] = array(
				'user_id'    => $state['user_id'],
				'user_login' => $state['user_login'],
				'source'     => $state['source'],
			);
		}
		return $out;
	}

	/* --------------------------------------------------------- password */

	/**
	 * A new shared password: PASSWORD_LENGTH characters of PASSWORD_ALPHABET.
	 *
	 * @return string
	 */
	public static function generate_password() {
		$max = strlen( self::PASSWORD_ALPHABET ) - 1;
		$out = '';
		for ( $i = 0; $i < self::PASSWORD_LENGTH; $i++ ) {
			$out .= self::PASSWORD_ALPHABET[ random_int( 0, $max ) ];
		}
		return $out;
	}

	/**
	 * The hash that is stored. PHP's own hashing, not wp_hash_password():
	 * that one is pluggable and meant for user accounts, and a plugin may
	 * replace it with something that reads user records.
	 *
	 * @param string $password Plain password.
	 * @return string
	 */
	public static function hash_password( $password ) {
		return (string) password_hash( (string) $password, PASSWORD_DEFAULT );
	}

	/**
	 * Does a password match the stored hash?
	 *
	 * @param string $password Plain password.
	 * @param string $hash     Stored hash.
	 * @return bool
	 */
	public static function verify_password( $password, $hash ) {
		return '' !== (string) $hash && '' !== (string) $password && password_verify( (string) $password, (string) $hash );
	}

	/* ----------------------------------------------------------- cookie */

	/**
	 * The cookie value for a hash: the expiry and an HMAC over the expiry and
	 * the hash with the site's salt. A new password has a new hash, so every
	 * earlier cookie fails the check.
	 *
	 * @param string $hash    Stored hash.
	 * @param int    $expires Unix time the cookie expires.
	 * @return string
	 */
	public static function cookie_value( $hash, $expires ) {
		$expires = (int) $expires;
		return $expires . '|' . hash_hmac( 'sha256', $expires . '|' . (string) $hash, wp_salt( 'auth' ) . '|ab_mcp_preview' );
	}

	/**
	 * Is a cookie value valid for the stored hash now?
	 *
	 * @param string $value Cookie value.
	 * @param string $hash  Stored hash.
	 * @param int    $now   Unix time.
	 * @return bool
	 */
	public static function cookie_valid( $value, $hash, $now ) {
		if ( '' === (string) $hash || ! is_string( $value ) || ! preg_match( '/\A(\d{1,12})\|[0-9a-f]{64}\z/', $value, $m ) ) {
			return false;
		}
		$expires = (int) $m[1];
		if ( $expires <= (int) $now ) {
			return false;
		}
		return hash_equals( self::cookie_value( $hash, $expires ), $value );
	}

	/* --------------------------------------------------------- frontend */

	/**
	 * What the front-end gate decides on, read from the request.
	 *
	 * @param bool        $with_themes Whether this is the way with themes
	 *                                 (WP_USE_THEMES), where robots.txt may
	 *                                 pass and the consent page is served.
	 * @param object|null $wp          On parse_request the WP object with the
	 *                                 parsed query_vars; null on the hooks
	 *                                 after the query, where is_robots() is
	 *                                 settled and REST has answered already.
	 * @return array
	 */
	public static function frontend_context( $with_themes = true, $wp = null ) {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- matched and parsed, never echoed raw.
		$path = self::strip_home( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
		$vars = ( is_object( $wp ) && isset( $wp->query_vars ) && is_array( $wp->query_vars ) ) ? $wp->query_vars : null;
		return array(
			'path'            => $path,
			'request_uri'     => $uri,
			'method'          => isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET',
			'well_known'      => class_exists( 'AB_MCP_OAuth' ) && AB_MCP_OAuth::enabled() && '' !== AB_MCP_OAuth::well_known_document( $uri ),
			// With themes only: the handler runs on template_redirect, which
			// the entry points without themes never reach.
			'oauth_authorize' => (bool) $with_themes && class_exists( 'AB_MCP_OAuth' ) && AB_MCP_OAuth::is_authorize_request(),
			'password'        => isset( $_POST[ self::FIELD ] ) && is_string( $_POST[ self::FIELD ] ) ? (string) wp_unslash( $_POST[ self::FIELD ] ) : null, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the password is the credential checked here.
			'cookie'          => isset( $_COOKIE[ self::COOKIE ] ) && is_string( $_COOKIE[ self::COOKIE ] ) ? (string) $_COOKIE[ self::COOKIE ] : '',
			'logged_in'       => is_user_logged_in(),
			'admin'           => is_admin(),
			// No clause for DOING_AJAX: admin-ajax.php runs no wp(), so the
			// front-end gates never see it (it has gate_admin()), and a plugin
			// may define the constant on a front-end request (WooCommerce on
			// init for ?wc-ajax=), which then is a front-end request like any
			// other, sitemap variables included (Codex, 31.10.2026).
			'cron'            => defined( 'DOING_CRON' ) && DOING_CRON,
			'xmlrpc'          => defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST,
			'rest'            => defined( 'REST_REQUEST' ) && REST_REQUEST,
			// The condition of rest_api_loaded() (wp-includes/rest-api.php),
			// which serves the request on parse_request at priority 10: the
			// variable WordPress parsed, never the path.
			'rest_route'      => null !== $vars && ! empty( $vars['rest_route'] ),
			'cli'             => defined( 'WP_CLI' ) && WP_CLI,
			// Only with themes: without them the template loader prints
			// robots.txt and returns to the script, which renders on. After
			// the query is_robots() decides; on parse_request the parsed
			// variable, and only when nothing else was asked for.
			'robots'          => (bool) $with_themes && ( null === $vars ? function_exists( 'is_robots' ) && is_robots() : self::only_robots( $vars ) ),
		);
	}

	/**
	 * Does a parsed query ask for robots.txt and nothing else? WP_Query sets
	 * is_robots when '' !== $qv['robots']; a second variable beside it is
	 * what a plugin on pre_get_posts answers (/?robots=1&sitemap=1), so it
	 * is not robots.txt here.
	 *
	 * @param array $vars $wp->query_vars.
	 * @return bool
	 */
	private static function only_robots( array $vars ) {
		return array( 'robots' ) === array_keys( $vars ) && is_scalar( $vars['robots'] ) && '' !== (string) $vars['robots'];
	}

	/**
	 * The decision for a front-end request: pass (serve the site), serve (a
	 * document of AB_MCP_OAuth: hand the request to its handler, which ends
	 * it), accept (a right password was posted: set the cookie and reload),
	 * reject (a wrong one: the password page again, 401) or challenge (the
	 * password page, 401). Each exemption is one clause, in the order of the
	 * header of this file; each has its test.
	 *
	 * @param array $ctx   frontend_context().
	 * @param array $state state().
	 * @param int   $now   Unix time.
	 * @return array{action:string,reason:string}
	 */
	public static function frontend_decision( array $ctx, array $state, $now ) {
		if ( empty( $state['on'] ) ) {
			return self::decision( 'pass', 'off' );
		}
		foreach ( array( 'admin', 'cron', 'xmlrpc', 'rest', 'cli' ) as $kind ) {
			if ( ! empty( $ctx[ $kind ] ) ) {
				return self::decision( 'pass', $kind );
			}
		}
		// A REST request on parse_request: rest_api_loaded() serves it next,
		// and gate_rest() decides on rest_authentication_errors.
		if ( ! empty( $ctx['rest_route'] ) ) {
			return self::decision( 'pass', 'rest_route' );
		}
		if ( ! empty( $ctx['well_known'] ) ) {
			return self::decision( 'serve', 'well_known' );
		}
		if ( ! empty( $ctx['oauth_authorize'] ) ) {
			return self::decision( 'serve', 'oauth_authorize' );
		}
		if ( ! empty( $ctx['robots'] ) ) {
			return self::decision( 'pass', 'robots' );
		}
		if ( ! empty( $ctx['logged_in'] ) ) {
			return self::decision( 'pass', 'logged_in' );
		}
		$hash = isset( $state['hash'] ) ? (string) $state['hash'] : '';
		if ( self::cookie_valid( isset( $ctx['cookie'] ) ? $ctx['cookie'] : '', $hash, $now ) ) {
			return self::decision( 'pass', 'cookie' );
		}
		if ( 'POST' === ( isset( $ctx['method'] ) ? $ctx['method'] : 'GET' ) && isset( $ctx['password'] ) && is_string( $ctx['password'] ) ) {
			return self::verify_password( $ctx['password'], $hash )
				? self::decision( 'accept', 'password' )
				: self::decision( 'reject', 'wrong_password' );
		}
		return self::decision( 'challenge', 'anonymous' );
	}

	/**
	 * One decision.
	 *
	 * @param string $action pass | serve | accept | reject | challenge.
	 * @param string $reason Which clause decided.
	 * @return array{action:string,reason:string}
	 */
	private static function decision( $action, $reason ) {
		return array(
			'action' => $action,
			'reason' => $reason,
		);
	}

	/**
	 * The front-end gate: on template_redirect, and from gate_parse_request()
	 * and the gates of the ways without themes. Does nothing while off. While
	 * on, every answer is marked as not to be cached, whoever gets it: a
	 * cache that stored the page a visitor with the cookie saw would hand it
	 * to everyone after.
	 *
	 * @param mixed       $with_themes true on template_redirect (the action
	 *                                 passes nothing, so the default); false
	 *                                 from the gates of the ways without
	 *                                 themes; wp_using_themes() from
	 *                                 gate_parse_request().
	 * @param object|null $wp          The WP object on parse_request.
	 */
	public static function gate_frontend( $with_themes = true, $wp = null ) {
		$state = self::state();
		if ( ! $state['on'] ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		self::send_robots_header();

		$ctx      = self::frontend_context( true === $with_themes, $wp );
		$decision = self::frontend_decision( $ctx, $state, time() );
		if ( 'pass' === $decision['action'] ) {
			return;
		}
		if ( 'serve' === $decision['action'] ) {
			// The handler ends every request it serves; one that returns
			// served nothing, and the password page follows.
			self::serve( $decision['reason'] );
		}
		if ( 'accept' === $decision['action'] ) {
			$expires = time() + self::COOKIE_DAYS * DAY_IN_SECONDS;
			self::send_cookie( self::cookie_value( $state['hash'], $expires ), $expires );
			// The same address again, as a GET: the password is not in the
			// address, and a reload does not post it a second time.
			wp_safe_redirect( home_url( self::relative( $ctx['request_uri'] ) ), 303 );
			exit;
		}
		wp_die(
			self::page_html( 'reject' === $decision['action'], self::relative( $ctx['request_uri'] ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside page_html().
			esc_html__( 'Preview', 'alphabridge-mcp' ),
			array(
				'response' => 401,
				'exit'     => true,
			)
		);
	}

	/**
	 * Hand a request to the handler of AB_MCP_OAuth that serves it, as
	 * template_redirect at priority 0 would later: the two documents below
	 * /.well-known/ (serve_well_known(), which exits after the JSON) and the
	 * consent page (handle_authorize(), whose every branch exits or
	 * wp_die()s). Nothing else is served here; a reason this does not know
	 * returns, and the gate shows the password page.
	 *
	 * @param string $reason The clause that decided: well_known or oauth_authorize.
	 */
	private static function serve( $reason ) {
		if ( ! class_exists( 'AB_MCP_OAuth' ) ) {
			return;
		}
		if ( 'well_known' === $reason ) {
			AB_MCP_OAuth::serve_well_known();
		} elseif ( 'oauth_authorize' === $reason ) {
			AB_MCP_OAuth::handle_authorize();
		}
	}

	/**
	 * The first line, on parse_request at PARSE_PRIORITY: WordPress has
	 * parsed the request into $wp->query_vars and nothing has answered yet,
	 * neither rest_api_loaded() at 10 nor the SEO plugins on pre_get_posts.
	 * Decides for every entry point that runs wp(), with or without themes.
	 *
	 * @param object $wp The WP object the action passes.
	 */
	public static function gate_parse_request( $wp = null ) {
		self::gate_frontend( wp_using_themes(), $wp );
	}

	/**
	 * The gate for an entry point that runs wp() without themes (the action
	 * wp, which WP::main() fires before the template loader): wp-signup.php,
	 * wp-activate.php and wp-trackback.php, where the template loader skips
	 * template_redirect but still answers a feed. With themes nothing happens
	 * here; template_redirect follows and runs the gate once.
	 */
	public static function gate_without_themes() {
		if ( wp_using_themes() ) {
			return;
		}
		self::gate_frontend( false );
	}

	/**
	 * The gate for a script that prints content without wp(): on wp_loaded,
	 * for wp-links-opml.php (the bookmarks as OPML).
	 */
	public static function gate_script() {
		if ( isset( $GLOBALS['pagenow'] ) && 'wp-links-opml.php' === $GLOBALS['pagenow'] ) {
			self::gate_frontend( false );
		}
	}

	/* ------------------------------------------- admin-ajax.php, admin-post.php */

	/**
	 * What the gate on admin_init decides on, read from the request.
	 *
	 * @return array{ajax:bool,post:bool,action:string,logged_in:bool,cookie:string}
	 */
	public static function admin_context() {
		return array(
			'ajax'      => function_exists( 'wp_doing_ajax' ) && wp_doing_ajax(),
			'post'      => isset( $GLOBALS['pagenow'] ) && 'admin-post.php' === $GLOBALS['pagenow'],
			'action'    => isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? (string) $_REQUEST['action'] : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only, as admin-ajax.php reads it.
			'logged_in' => is_user_logged_in(),
			'cookie'    => isset( $_COOKIE[ self::COOKIE ] ) && is_string( $_COOKIE[ self::COOKIE ] ) ? (string) $_COOKIE[ self::COOKIE ] : '',
		);
	}

	/**
	 * The decision for admin-ajax.php and admin-post.php: pass, or challenge
	 * (401). Only there: the pages of wp-admin send a visitor to the login
	 * themselves. A signed-in user, a cookie holder and the two nopriv
	 * actions of the core pass; everyone else is challenged. Each clause has
	 * its test.
	 *
	 * @param array $ctx   admin_context().
	 * @param array $state state().
	 * @param int   $now   Unix time.
	 * @return array{action:string,reason:string}
	 */
	public static function admin_decision( array $ctx, array $state, $now ) {
		if ( empty( $state['on'] ) ) {
			return self::decision( 'pass', 'off' );
		}
		if ( empty( $ctx['ajax'] ) && empty( $ctx['post'] ) ) {
			return self::decision( 'pass', 'admin' );
		}
		if ( ! empty( $ctx['logged_in'] ) ) {
			return self::decision( 'pass', 'logged_in' );
		}
		$hash = isset( $state['hash'] ) ? (string) $state['hash'] : '';
		if ( self::cookie_valid( isset( $ctx['cookie'] ) ? $ctx['cookie'] : '', $hash, $now ) ) {
			return self::decision( 'pass', 'cookie' );
		}
		if ( ! empty( $ctx['ajax'] ) && isset( $ctx['action'] ) && in_array( $ctx['action'], self::CORE_NOPRIV, true ) ) {
			return self::decision( 'pass', 'core' );
		}
		return self::decision( 'challenge', 'anonymous' );
	}

	/**
	 * The gate on admin_init for the nopriv actions of admin-ajax.php and
	 * admin-post.php, where plugins print content for visitors. For AJAX the
	 * answer is «0» with 401, as admin-ajax.php answers an action nobody
	 * handles; for admin-post.php the password page, with its form posting
	 * to the home page, where the front-end gate takes the password.
	 */
	public static function gate_admin() {
		$state = self::state();
		if ( ! $state['on'] ) {
			return;
		}
		self::send_robots_header();
		$ctx      = self::admin_context();
		$decision = self::admin_decision( $ctx, $state, time() );
		if ( 'pass' === $decision['action'] ) {
			return;
		}
		if ( $ctx['ajax'] ) {
			wp_die( '0', '', array( 'response' => 401 ) );
		}
		wp_die(
			self::page_html( false, '/' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside page_html().
			esc_html__( 'Preview', 'alphabridge-mcp' ),
			array(
				'response' => 401,
				'exit'     => true,
			)
		);
	}

	/**
	 * The paths the cookie is set for, as WordPress sets its login cookie
	 * (wp_set_auth_cookie()): COOKIEPATH, the path of the home address, and,
	 * where it differs, SITECOOKIEPATH, the path of the WordPress folder
	 * (home https://example.test/staging, WordPress in /wordpress): a cookie
	 * for /staging/ alone never reaches /wordpress/wp-admin/admin-ajax.php
	 * and admin-post.php, and the AJAX of a page a visitor unlocked would
	 * meet gate_admin() without it (Codex, 31.10.2026). Domain as WordPress
	 * sets its own, so on a sub-directory network the cookie of one site
	 * does not reach another.
	 *
	 * @return string[] One or two paths, COOKIEPATH first.
	 */
	public static function cookie_paths() {
		$paths = array( defined( 'COOKIEPATH' ) && '' !== (string) COOKIEPATH ? (string) COOKIEPATH : '/' );
		if ( defined( 'SITECOOKIEPATH' ) && '' !== (string) SITECOOKIEPATH && (string) SITECOOKIEPATH !== $paths[0] ) {
			$paths[] = (string) SITECOOKIEPATH;
		}
		return $paths;
	}

	/**
	 * Set the cookie, where headers can still be sent: once per path of
	 * cookie_paths(), with the same value, expiry and flags. Each setcookie()
	 * is followed by the action ab_mcp_preview_cookie_sent with its
	 * arguments, so that a test, or code that watches what the site answers,
	 * sees what was set and for which path (PHP's CLI keeps no list of the
	 * headers it sent; the mark of AB_MCP_OAuth is reported the same way).
	 *
	 * @param string $value   cookie_value().
	 * @param int    $expires Unix time.
	 */
	private static function send_cookie( $value, $expires ) {
		if ( headers_sent() ) {
			return;
		}
		foreach ( self::cookie_paths() as $path ) {
			$options = array(
				'expires'  => (int) $expires,
				'path'     => $path,
				'domain'   => defined( 'COOKIE_DOMAIN' ) && is_string( COOKIE_DOMAIN ) ? COOKIE_DOMAIN : '',
				'secure'   => function_exists( 'is_ssl' ) && is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			);
			setcookie( self::COOKIE, $value, $options );
			/**
			 * Fires after the preview cookie was set for one path.
			 *
			 * @param string $name    The cookie's name.
			 * @param string $value   Its value.
			 * @param array  $options The options setcookie() got (expires, path, domain, secure, httponly, samesite).
			 */
			do_action( 'ab_mcp_preview_cookie_sent', self::COOKIE, $value, $options );
		}
	}

	/**
	 * X-Robots-Tag on an answer a gate gives itself. The filter wp_headers
	 * (headers()) runs in WP::send_headers(), after parse_request; the
	 * password page, the reload after the password and the documents handed
	 * to AB_MCP_OAuth end on parse_request before that, wp-links-opml.php
	 * and the two admin scripts never run it. So every gate sends the header
	 * as soon as the protection is on, where headers can still be sent;
	 * header() replaces a header of the same name, so the second line and
	 * WP::send_headers() add no second one (Codex, 31.10.2026). Followed by
	 * the action ab_mcp_preview_header_sent with the line, as the cookie.
	 */
	private static function send_robots_header() {
		if ( headers_sent() ) {
			return;
		}
		$line = 'X-Robots-Tag: ' . self::ROBOTS;
		header( $line, true );
		/**
		 * Fires after a gate sent a header of its own.
		 *
		 * @param string $line The header line sent.
		 */
		do_action( 'ab_mcp_preview_header_sent', $line );
	}

	/**
	 * A request URI as a path below the home address with its query, for
	 * home_url() and the form's action: never a scheme or host a visitor
	 * sent, and without the folder of a WordPress in a sub-directory, which
	 * home_url() adds again.
	 *
	 * @param string $uri REQUEST_URI.
	 * @return string
	 */
	private static function relative( $uri ) {
		$path  = self::strip_home( (string) wp_parse_url( (string) $uri, PHP_URL_PATH ) );
		$query = (string) wp_parse_url( (string) $uri, PHP_URL_QUERY );
		return $path . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * A request path without the folder of the home address (WordPress in a
	 * sub-directory, home https://example.test/staging), with a leading
	 * slash; '/' for the home page. For the addresses this class builds
	 * (the form's action, the reload) and for the REST prefix; the documents
	 * below /.well-known/ are matched by AB_MCP_OAuth itself.
	 *
	 * @param string $path Path of the request.
	 * @return string
	 */
	public static function strip_home( $path ) {
		$path = (string) $path;
		$home = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( '' !== $home && ( $path === $home || 0 === strpos( $path, $home . '/' ) ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		return '/' . ltrim( $path, '/' );
	}

	/**
	 * The password page: what this is, the form, and the way in for members.
	 * wp_die() puts it into WordPress's own error page and sends the 401.
	 *
	 * @param string $wrong  Whether a wrong password was just entered.
	 * @param string $action Path and query of the current address, for the form.
	 * @return string HTML, escaped.
	 */
	public static function page_html( $wrong, $action ) {
		$html  = '<p>' . esc_html__( 'This site is a preview and not public yet. Enter the password you were given to see it; the browser then remembers you for 14 days.', 'alphabridge-mcp' ) . '</p>';
		if ( $wrong ) {
			$html .= '<p class="ab-preview__wrong"><strong>' . esc_html__( 'That password is not right. Check it with the person who gave it to you and try again.', 'alphabridge-mcp' ) . '</strong></p>';
		}
		$html .= '<form method="post" action="' . esc_url( home_url( $action ) ) . '" class="ab-preview__form">';
		$html .= '<p><label for="ab-preview-password">' . esc_html__( 'Password', 'alphabridge-mcp' ) . '</label><br>';
		$html .= '<input type="password" id="ab-preview-password" name="' . esc_attr( self::FIELD ) . '" autocomplete="current-password" required autofocus></p>';
		$html .= '<p><button type="submit" class="button button-primary">' . esc_html__( 'Show the preview', 'alphabridge-mcp' ) . '</button></p>';
		$html .= '</form>';
		$html .= '<p class="ab-preview__members">' . esc_html__( 'Members of this site log in as usual instead.', 'alphabridge-mcp' ) . ' <a href="' . esc_url( wp_login_url( home_url( $action ) ) ) . '">' . esc_html__( 'Log in', 'alphabridge-mcp' ) . '</a></p>';
		return $html;
	}

	/**
	 * X-Robots-Tag on every front-end answer while on (the wp_headers
	 * filter, sent by WP::send_headers() before the template loads). The
	 * answers that end before that get it from the gates themselves
	 * (send_robots_header()).
	 *
	 * @param array $headers Headers WordPress is about to send.
	 * @return array
	 */
	public static function headers( $headers ) {
		$headers = is_array( $headers ) ? $headers : array();
		if ( self::is_on() ) {
			$headers['X-Robots-Tag'] = self::ROBOTS;
		}
		return $headers;
	}

	/* ------------------------------------------------------------- REST */

	/**
	 * The REST route of the current request, as WordPress resolved it:
	 * the query var rest_route (set for pretty permalinks by the rewrite
	 * rule and for plain ones by ?rest_route=), else the path after the
	 * REST prefix. Always with a leading slash and no trailing one; the
	 * root is '/'.
	 *
	 * @param array $server     $_SERVER.
	 * @param array $query_vars $wp->query_vars, or $_GET.
	 * @return string
	 */
	public static function rest_route( array $server, array $query_vars ) {
		$route = '';
		if ( isset( $query_vars['rest_route'] ) && is_string( $query_vars['rest_route'] ) ) {
			$route = $query_vars['rest_route'];
		} elseif ( isset( $server['REQUEST_URI'] ) ) {
			$path   = self::strip_home( (string) wp_parse_url( (string) $server['REQUEST_URI'], PHP_URL_PATH ) );
			$prefix = '/' . trim( rest_get_url_prefix(), '/' );
			if ( $path === $prefix || 0 === strpos( $path, $prefix . '/' ) ) {
				$route = substr( $path, strlen( $prefix ) );
			}
		}
		$route = '/' . trim( (string) $route, '/' );
		return $route;
	}

	/**
	 * Is a route open to a visitor without login and cookie while the
	 * protection is on? The root (the namespace index the hub reads), and
	 * every route under this plugin's namespace: each of them checks its
	 * own credential (the MCP endpoint its token in the permission callback,
	 * the OAuth routes their code or token), and none serves content.
	 *
	 * Compared without regard to case, as WordPress's router matches a route
	 * (preg_match with the i flag in WP_REST_Server::match_request_to_handler()):
	 * /wp-json/AlphaBridge/v1/mcp reaches the MCP endpoint and so is open.
	 *
	 * @param string $route rest_route().
	 * @return bool
	 */
	public static function rest_exempt( $route ) {
		$route = '/' . trim( (string) $route, '/' );
		if ( '/' === $route ) {
			return true;
		}
		$ns = '/' . trim( defined( 'AB_MCP_REST_NAMESPACE' ) ? AB_MCP_REST_NAMESPACE : 'alphabridge/v1', '/' );
		return 1 === preg_match( '#^' . preg_quote( $ns, '#' ) . '(/|$)#i', $route );
	}

	/**
	 * The REST gate (rest_authentication_errors, after WordPress's own
	 * checks). An error of theirs passes through as it is; so does every
	 * request while off, of a signed-in user, to an exempt route, or with a
	 * valid cookie. Anything else is answered 401.
	 *
	 * @param WP_Error|true|null $result What the filters before decided.
	 * @return WP_Error|true|null
	 */
	public static function gate_rest( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$state = self::state();
		if ( ! $state['on'] ) {
			return $result;
		}
		if ( is_user_logged_in() ) {
			return $result;
		}
		$wp    = isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->query_vars ) && is_array( $GLOBALS['wp']->query_vars ) ? $GLOBALS['wp']->query_vars : array();
		$route = self::rest_route( $_SERVER, array_merge( $_GET, $wp ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
		if ( self::rest_exempt( $route ) ) {
			return $result;
		}
		if ( self::holds_cookie( $state['hash'] ) ) {
			return $result;
		}
		return new WP_Error(
			'ab_mcp_preview_protected',
			__( 'This site is a preview behind a password, so its REST API answers only signed-in users (a cookie with a nonce, or an application password) and visitors who entered the preview password in their browser. Enter it on the password page of the site, sign in, or ask the site administrator; the MCP endpoint and the sign-in of AlphaBridge MCP are open as always.', 'alphabridge-mcp' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Does the request carry a valid preview cookie for this hash?
	 *
	 * @param string $hash Stored hash.
	 * @return bool
	 */
	private static function holds_cookie( $hash ) {
		$cookie = isset( $_COOKIE[ self::COOKIE ] ) && is_string( $_COOKIE[ self::COOKIE ] ) ? (string) $_COOKIE[ self::COOKIE ] : '';
		return self::cookie_valid( $cookie, (string) $hash, time() );
	}

	/**
	 * The REST index (rest_index) for a visitor without login and cookie
	 * while on: the namespaces alone, in a fresh response without links, so
	 * neither _links nor ?_embed give the site logo or icon away. Everyone
	 * else gets the index as WordPress built it.
	 *
	 * @param WP_REST_Response $response The index.
	 * @param WP_REST_Request  $request  The request (unused; the filter passes it).
	 * @return WP_REST_Response
	 */
	public static function index( $response, $request = null ) {
		if ( ! ( $response instanceof WP_REST_Response ) ) {
			return $response;
		}
		$state = self::state();
		if ( ! $state['on'] ) {
			return $response;
		}
		if ( is_user_logged_in() ) {
			return $response;
		}
		if ( self::holds_cookie( $state['hash'] ) ) {
			return $response;
		}
		$data       = $response->get_data();
		$namespaces = ( is_array( $data ) && isset( $data['namespaces'] ) && is_array( $data['namespaces'] ) ) ? array_values( $data['namespaces'] ) : array();
		return new WP_REST_Response( array( 'namespaces' => $namespaces ), 200 );
	}

	/**
	 * No-cache headers on every REST answer while on
	 * (rest_send_nocache_headers; WordPress sends them for signed-in users
	 * only): the answer a visitor with the cookie gets is theirs alone.
	 *
	 * @param bool $send What WordPress decided.
	 * @return bool
	 */
	public static function nocache( $send ) {
		return self::is_on() ? true : (bool) $send;
	}

	/* ------------------------------------------------------------ texts */

	/**
	 * What the protection does not cover, for the tool's answer and the
	 * settings page. English for the tool (the assistant reads it); the
	 * settings page translates its own sentence.
	 *
	 * @return string[]
	 */
	public static function limits() {
		return array(
			'Files under /wp-content/uploads/ are delivered by the web server without PHP and stay reachable by their address unless the host adds a rule for that folder.',
			'Caches outside PHP (a host cache such as at Kinsta, WP Engine or SiteGround, Cloudflare APO, a CDN) are not emptied by this plugin; purge them at the host when a page still shows without the password.',
			'The shared password identifies nobody: it suits a staging site shown to a client, not confidential data.',
		);
	}
}
