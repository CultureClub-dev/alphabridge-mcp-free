<?php
/**
 * The gate on parse_request answers a visitor's exemption itself and ends
 * the request (the decision serve): the documents below /.well-known/
 * (AB_MCP_OAuth::serve_well_known() prints the JSON and exits), robots.txt
 * (do_robots, then exit, as the template loader) and a REST request
 * (rest_api_loaded(), which dies). Ending with exit, this runs in a process
 * of its own, started by PreviewProtectionTest against the stubs of
 * tests/bootstrap.php.
 *
 * Every case carries a second parameter a later handler would answer: a
 * Yoast-like sitemap on pre_get_posts at 1 and a WooCommerce-like
 * dispatcher on template_redirect at 0 (reading $_GET['wc-ajax']), both
 * printing content. A gate that passed the exemption and waited for its
 * handler would let one of them print first.
 *
 * Argument: the case.
 * - well-known-sitemap: /.well-known/oauth-authorization-server?sitemap=1,
 *   the sign-in on: the document must come out, nothing else.
 * - well-known-disabled: the same with the sign-in off: nothing serves the
 *   document, so the password page.
 * - robots-wc-ajax: /?robots=1&wc-ajax=get_refreshed_fragments: robots.txt
 *   (the do_robots stub below) must come out, nothing else, and the process
 *   ends in the gate's exit.
 * - rest-wc-ajax: /wp-json/wp/v2/posts?wc-ajax=…: the REST API answers
 *   (the stub of the bootstrap ends with its route).
 *
 * Prints what the gate did: the answer alone (the process ends in an exit;
 * nothing may be printed before it, the handlers send headers),
 * CHILD-RETURNED followed by what the late handlers printed (the gate
 * passed), or CHILD-EXIT with the kind, the detail and the status.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

error_reporting( E_ALL );

$ab_case = (string) ( $argv[1] ?? 'well-known-sitemap' );

require dirname( __DIR__ ) . '/bootstrap.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-page-cache.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-preview-protection.php';

ab_test_reset();
ab_test_add_user( 7 );
$GLOBALS['ab_test_options']['blog_public'] = '1';
AB_MCP_Preview_Protection::enable( 7, AB_MCP_Preview_Protection::SOURCE_TOOL );
$_SERVER['REQUEST_METHOD'] = 'GET';

switch ( $ab_case ) {
	case 'well-known-disabled':
		AB_MCP_Settings::set( 'oauth_enabled', false );
		// Fall through: the same request.
	case 'well-known-sitemap':
		$_SERVER['REQUEST_URI'] = '/.well-known/oauth-authorization-server?sitemap=1';
		$_GET                   = array( 'sitemap' => '1' );
		$ab_vars                = array( 'pagename' => '.well-known/oauth-authorization-server', 'sitemap' => '1' );
		break;
	case 'robots-wc-ajax':
		$_SERVER['REQUEST_URI'] = '/?robots=1&wc-ajax=get_refreshed_fragments';
		$_GET                   = array( 'robots' => '1', 'wc-ajax' => 'get_refreshed_fragments' );
		$ab_vars                = array( 'robots' => '1' );
		break;
	case 'rest-wc-ajax':
		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts?wc-ajax=get_refreshed_fragments';
		$_GET                   = array( 'wc-ajax' => 'get_refreshed_fragments' );
		$ab_vars                = array( 'rest_route' => '/wp/v2/posts' );
		break;
	default:
		echo 'CHILD-UNKNOWN-CASE';
		exit( 2 );
}

// The core's do_robots, as the template loader fires it: prints robots.txt.
add_action(
	'do_robots',
	static function (): void {
		echo "User-agent: *\nDisallow: /\n";
	}
);
// The late handlers.
add_action(
	'pre_get_posts',
	static function (): void {
		echo 'LATE-SITEMAP <urlset>https://example.test/secret-page/</urlset>';
	},
	1
);
add_action(
	'template_redirect',
	static function (): void {
		if ( isset( $_GET['wc-ajax'] ) ) {
			echo 'LATE-WC-AJAX {"fragments":{"div.widget_shopping_cart_content":"secret cart"}}';
		}
	},
	0
);
AB_MCP_Preview_Protection::hook();

// The WP object the action passes is $GLOBALS['wp'], which rest_api_loaded() reads.
$GLOBALS['wp'] = (object) array( 'query_vars' => $ab_vars );
try {
	// WP::main(): parse_request, then the query with pre_get_posts, then the template.
	do_action_ref_array( 'parse_request', array( $GLOBALS['wp'] ) );
	echo 'CHILD-RETURNED ';
	do_action( 'pre_get_posts' );
	do_action( 'template_redirect' );
} catch ( AbTestExit $exit ) {
	echo 'CHILD-EXIT ' . $exit->kind . ' ' . $exit->detail . ' ' . (int) ( $GLOBALS['ab_test_die_args']['response'] ?? 0 );
}
