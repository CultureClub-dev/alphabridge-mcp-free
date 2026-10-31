<?php
/**
 * The gate on parse_request hands a request for one of the two documents
 * below /.well-known/ to AB_MCP_OAuth::serve_well_known(), which prints the
 * JSON and exits; so this runs in a process of its own, started by
 * PreviewProtectionTest against the stubs of tests/bootstrap.php.
 *
 * The request is /.well-known/oauth-authorization-server?sitemap=1 with a
 * Yoast-like sitemap plugin on pre_get_posts: a gate that passed the
 * document and waited for template_redirect would let the plugin print the
 * sitemap first. Argument: the mode, «document» (the sign-in on: the
 * document must come out, and the process ends in the handler's exit) or
 * «disabled» (the sign-in off: nothing serves the document, so the password
 * page).
 *
 * Prints what the gate did: the document alone (the process ends in the
 * handler's exit; nothing may be printed before it, the handler sends
 * headers), CHILD-RETURNED followed by what the plugin printed (the gate
 * passed), or CHILD-EXIT with the kind and status.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

error_reporting( E_ALL );

$ab_mode = (string) ( $argv[1] ?? 'document' );

require dirname( __DIR__ ) . '/bootstrap.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-page-cache.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-preview-protection.php';

ab_test_reset();
ab_test_add_user( 7 );
$GLOBALS['ab_test_options']['blog_public'] = '1';
AB_MCP_Preview_Protection::enable( 7, AB_MCP_Preview_Protection::SOURCE_TOOL );
if ( 'disabled' === $ab_mode ) {
	AB_MCP_Settings::set( 'oauth_enabled', false );
}

$_SERVER['REQUEST_URI']    = '/.well-known/oauth-authorization-server?sitemap=1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET                      = array( 'sitemap' => '1' );

// The Yoast-like plugin, on pre_get_posts at 1.
add_action(
	'pre_get_posts',
	static function (): void {
		echo '<urlset>https://example.test/secret-page/</urlset>';
	},
	1
);
AB_MCP_Preview_Protection::hook();

try {
	// WP::main(): parse_request, then the query with pre_get_posts.
	do_action_ref_array( 'parse_request', array( (object) array( 'query_vars' => array( 'pagename' => '.well-known/oauth-authorization-server', 'sitemap' => '1' ) ) ) );
	echo 'CHILD-RETURNED ';
	do_action( 'pre_get_posts' );
} catch ( AbTestExit $exit ) {
	echo 'CHILD-EXIT ' . $exit->kind . ' ' . (int) ( $GLOBALS['ab_test_die_args']['response'] ?? 0 );
}
