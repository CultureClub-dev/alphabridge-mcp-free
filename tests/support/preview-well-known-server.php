<?php
/**
 * The well-known documents of a site with the preview protection on, over
 * real HTTP, for PreviewWellKnownHttpTest (and for record-well-known-main.php,
 * which records what main answered before the hotfix).
 *
 * Served by PHP's built-in server (php -S, this file as the router). The
 * test bootstrap stands in for WordPress as in the unit tests; this script
 * runs the request the way WP::main() and the template loader run it:
 * parse_request (the protection's first line at priority 1), the query with
 * pre_get_posts, then template_redirect (the plugin's handler at 0, the
 * protection's second line at 1), then the theme. Two late handlers print
 * content a visitor must never get: a Yoast-like sitemap on pre_get_posts
 * at 1 (for ?sitemap=1) and a WooCommerce-like dispatcher on
 * template_redirect at 0 (for ?wc-ajax=), registered after the plugin as a
 * plugin later in the alphabet would be.
 *
 * Headers of the request:
 *
 * - X-AB-Test-Home, X-AB-Test-Abspath, X-AB-Test-OAuth, X-AB-Test-Fetch: as
 *   in well-known-server.php;
 * - X-AB-Test-Protection: "off" leaves the protection off (default on);
 * - X-AB-Test-Visitor: "logged-in" or "cookie" (a valid cookie of the
 *   protection); default a visitor without either;
 * - X-AB-Test-No-Parse: present: no parse_request (a plugin filtered
 *   do_parse_request to false).
 *
 * nocache_headers() and status_header() send what WordPress sends (the
 * bootstrap counts and records them, this script sends them): nocache with
 * the headers of wp_get_nocache_headers() in 7.1.3, the status when the
 * headers go out (header_register_callback), unless this script set one for
 * an end of the stubs (wp_die(), a redirect). Reported besides:
 * X-AB-Test-Fetches (fetches made), X-AB-Test-Status (status_header() of
 * the code under test), X-AB-Test-Canonical and X-AB-Test-Guess (whether a
 * refusal switched them off). The body is what was printed: the JSON of the
 * handler, the late handlers' content, THEME (the theme rendered the
 * request), or the password page.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

if ( isset( $_SERVER['HTTP_X_AB_TEST_ABSPATH'] ) ) {
	define( 'ABSPATH', rtrim( (string) $_SERVER['HTTP_X_AB_TEST_ABSPATH'], '/' ) . '/' );
}
require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/class-page-cache.php';
require_once __DIR__ . '/../../includes/class-preview-protection.php';
require_once __DIR__ . '/well-known-answers.php';
ab_test_reset();

$ab_home = isset( $_SERVER['HTTP_X_AB_TEST_HOME'] ) ? (string) $_SERVER['HTTP_X_AB_TEST_HOME'] : 'https://example.test';
$GLOBALS['ab_test_home']      = $ab_home;
$GLOBALS['ab_test_rest_base'] = $ab_home . '/wp-json/';
if ( isset( $_SERVER['HTTP_X_AB_TEST_OAUTH'] ) && 'off' === $_SERVER['HTTP_X_AB_TEST_OAUTH'] ) {
	AB_MCP_Settings::set( 'oauth_enabled', false );
}
$ab_mode    = isset( $_SERVER['HTTP_X_AB_TEST_FETCH'] ) ? (string) $_SERVER['HTTP_X_AB_TEST_FETCH'] : 'site';
$ab_visitor = isset( $_SERVER['HTTP_X_AB_TEST_VISITOR'] ) ? (string) $_SERVER['HTTP_X_AB_TEST_VISITOR'] : 'visitor';

// The protection on, with a hash of its own: the gates read on and the hash
// (state()), and the cookie is signed over that hash.
$ab_hash = '$2y$04$abcdefghijklmnopqrstuuabcdefghijklmnopqrstuvwxyz01234';
if ( ! isset( $_SERVER['HTTP_X_AB_TEST_PROTECTION'] ) || 'off' !== $_SERVER['HTTP_X_AB_TEST_PROTECTION'] ) {
	AB_MCP_Settings::set(
		AB_MCP_Preview_Protection::KEY,
		array(
			'on'          => true,
			'hash'        => $ab_hash,
			'blog_public' => '1',
		)
	);
}
if ( 'logged-in' === $ab_visitor ) {
	$GLOBALS['ab_test_logged_in'] = true;
}
if ( 'cookie' === $ab_visitor ) {
	$_COOKIE[ AB_MCP_Preview_Protection::cookie_name() ] = AB_MCP_Preview_Protection::cookie_value( $ab_hash, time() + 600 );
}

// What WordPress sends in nocache_headers() (7.1.3, wp_get_nocache_headers()).
$GLOBALS['ab_test_nocache_send'] = static function (): void {
	if ( headers_sent() ) {
		return;
	}
	header_remove( 'Last-Modified' );
	header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );
	header( 'Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private' );
};
// The status the code under test set, when the headers go out; and the
// report of what it did.
$GLOBALS['ab_router_code'] = null;
header_register_callback(
	static function (): void {
		if ( null !== $GLOBALS['ab_router_code'] ) {
			http_response_code( (int) $GLOBALS['ab_router_code'] );
		} elseif ( isset( $GLOBALS['ab_test_status'] ) ) {
			http_response_code( (int) $GLOBALS['ab_test_status'] );
		}
		header( 'X-AB-Test-Fetches: ' . count( $GLOBALS['ab_test_http'] ) );
		header( 'X-AB-Test-Status: ' . ( isset( $GLOBALS['ab_test_status'] ) ? (string) $GLOBALS['ab_test_status'] : 'unset' ) );
		header( 'X-AB-Test-Canonical: ' . ( false === has_filter( 'redirect_canonical', '__return_false' ) ? 'on' : 'off' ) );
		header( 'X-AB-Test-Guess: ' . ( false === has_filter( 'do_redirect_guess_404_permalink', '__return_false' ) ? 'on' : 'off' ) );
	}
);

$GLOBALS['ab_test_http_answer'] = static function ( string $url, array $args ) use ( $ab_mode ) {
	return ab_well_known_test_answer( $ab_mode, $url );
};

// The plugin's handlers on template_redirect at 0, then the late handlers.
AB_MCP_OAuth::boot();
add_action(
	'pre_get_posts',
	static function (): void {
		if ( isset( $_GET['sitemap'] ) ) {
			echo 'LATE-SITEMAP <urlset>https://example.test/secret-page/</urlset>';
		}
	},
	1
);
add_action(
	'template_redirect',
	static function (): void {
		if ( isset( $_GET['wc-ajax'] ) ) {
			echo 'LATE-WC-AJAX {"fragments":{"div.widget_shopping_cart_content":"secret cart"}}';
			exit;
		}
	},
	0
);
// The protection's two front-end lines, as hook() adds them (hook() itself
// would also write the must-use loader).
add_action( 'parse_request', array( AB_MCP_Preview_Protection::class, 'gate_parse_request' ), AB_MCP_Preview_Protection::PARSE_PRIORITY );
add_action( 'template_redirect', array( AB_MCP_Preview_Protection::class, 'gate_frontend' ), AB_MCP_Preview_Protection::TEMPLATE_PRIORITY );

// The query variables WordPress would parse: the path as pagename, and the
// sitemap variable of the SEO plugin.
$ab_path = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
$ab_vars = array( 'pagename' => trim( $ab_path, '/' ) );
if ( isset( $_GET['sitemap'] ) ) {
	$ab_vars['sitemap'] = (string) $_GET['sitemap'];
}
$GLOBALS['wp'] = (object) array( 'query_vars' => $ab_vars );

try {
	// X-AB-Test-No-Parse: a plugin filtered do_parse_request to false, so
	// parse_request never fires (the protection's second line decides).
	if ( ! isset( $_SERVER['HTTP_X_AB_TEST_NO_PARSE'] ) ) {
		do_action_ref_array( 'parse_request', array( $GLOBALS['wp'] ) );
	}
	do_action( 'pre_get_posts' );
	do_action( 'template_redirect' );
	// The template loader: the theme renders the request (its 404 page,
	// where the status says so).
	echo 'THEME';
} catch ( AbTestExit $exit ) {
	if ( 'die' === $exit->kind ) {
		$GLOBALS['ab_router_code'] = (int) ( $GLOBALS['ab_test_die_args']['response'] ?? 500 );
		echo $exit->detail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the page the gate built, escaped there.
	} elseif ( 'redirect' === $exit->kind ) {
		$GLOBALS['ab_router_code'] = $exit->status > 0 ? $exit->status : 302;
		header( 'Location: ' . $exit->detail );
	} else {
		$GLOBALS['ab_router_code'] = 500;
		echo 'unexpected end: ' . $exit->kind; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a test server.
	}
}
