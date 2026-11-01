<?php
/**
 * The gates of the preview protection over real HTTP, for the test of the
 * mark on its password page (PreviewMarkHttpTest).
 *
 * Served by PHP's built-in server (php -S, this file as the router) or run
 * under php-cgi. The test bootstrap stands in for WordPress exactly as in
 * the unit tests; its stubs end a request by throwing AbTestExit where
 * WordPress would redirect, die or serve, so this script turns that into the
 * status WordPress would send. What matters is what is delivered BEFORE
 * that: a header set by header() in the gate (send_mark(), X-Robots-Tag, the
 * cookie) reaches the client here, or it does not.
 *
 * The protection is switched on with the hash the test made of a password
 * it knows (environment variable AB_TEST_PREVIEW_HASH): every request runs
 * this script afresh, and the cookie a right password sets is signed for
 * that hash, so the same hash on every request lets the cookie of one answer
 * pass the next.
 *
 * Routing, as WordPress reaches the gates:
 *
 * - /wp-admin/admin-ajax.php, /wp-admin/admin-post.php: gate_admin() on
 *   admin_init (is_admin(), wp_doing_ajax() for admin-ajax.php).
 * - /wp-links-opml.php: gate_script() on wp_loaded.
 * - anything else: index.php with themes, gate_parse_request() with the
 *   query variables WordPress would parse: robots for /robots.txt, else the
 *   path as pagename.
 *
 * A gate that returns passed the request: 200 with a stand-in for the page.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../includes/class-page-cache.php';
require_once __DIR__ . '/../../includes/class-preview-protection.php';
ab_test_reset();

$ab_hash = (string) getenv( 'AB_TEST_PREVIEW_HASH' );
if ( '' === $ab_hash ) {
	http_response_code( 500 );
	echo 'AB_TEST_PREVIEW_HASH is not set';
	return;
}

// Switched on: the record enable() stores, as far as the gates read it
// (state(): on and the hash), with the hash the test made. enable() itself
// would hash a password of its own first, a fifth of a second at PHP's
// default bcrypt cost on every request, only to have it replaced.
AB_MCP_Settings::set(
	AB_MCP_Preview_Protection::KEY,
	array(
		'on'          => true,
		'hash'        => $ab_hash,
		'blog_public' => '1',
	)
);
if ( ! AB_MCP_Preview_Protection::is_on() ) {
	http_response_code( 500 );
	echo 'the protection did not switch on';
	return;
}

// The core's do_robots, as the template loader fires it; the stub ends the
// request as WordPress does after printing robots.txt.
add_action(
	'do_robots',
	static function (): void {
		throw new AbTestExit( 'robots', "User-agent: *\nDisallow: /\n" );
	}
);

// admin-ajax.php and admin-post.php read the action from $_REQUEST.
$_REQUEST = array_merge( $_GET, $_POST );
$ab_path  = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );

try {
	if ( '/wp-admin/admin-ajax.php' === $ab_path || '/wp-admin/admin-post.php' === $ab_path ) {
		$GLOBALS['pagenow']            = basename( $ab_path );
		$GLOBALS['ab_test_is_admin']   = true;
		$GLOBALS['ab_test_doing_ajax'] = 'admin-ajax.php' === $GLOBALS['pagenow'];
		AB_MCP_Preview_Protection::gate_admin();
	} elseif ( '/wp-links-opml.php' === $ab_path ) {
		$GLOBALS['pagenow'] = 'wp-links-opml.php';
		AB_MCP_Preview_Protection::gate_script();
	} else {
		$ab_vars = '/robots.txt' === $ab_path ? array( 'robots' => '1' ) : array( 'pagename' => trim( $ab_path, '/' ) );
		// The WP object parse_request passes, as $GLOBALS['wp'] in the core.
		$GLOBALS['wp'] = (object) array( 'query_vars' => $ab_vars );
		AB_MCP_Preview_Protection::gate_parse_request( $GLOBALS['wp'] );
	}
	// The gate passed: what WordPress would render.
	http_response_code( 200 );
	echo 'page';
} catch ( AbTestExit $exit ) {
	if ( 'die' === $exit->kind ) {
		$ab_args = $GLOBALS['ab_test_die_args'];
		http_response_code( isset( $ab_args['response'] ) ? (int) $ab_args['response'] : 500 );
		echo $exit->detail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the page the gate built, escaped there.
	} elseif ( 'redirect' === $exit->kind ) {
		http_response_code( $exit->status > 0 ? $exit->status : 302 );
		header( 'Location: ' . $exit->detail );
	} elseif ( 'robots' === $exit->kind ) {
		http_response_code( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo $exit->detail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text of the stub.
	} else {
		http_response_code( 500 );
		echo 'unexpected end: ' . $exit->kind; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a test server.
	}
}
