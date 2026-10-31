<?php
/**
 * The preview protection of a staging site (AB_MCP_Preview_Protection, the
 * tool wp_set_preview_protection and the section on the settings page; free
 * since 4.15.0).
 *
 * What must hold:
 *
 * - Off by default, and while off nothing is locked: the front-end gate
 *   passes, the REST gate hands the result through, no header is added.
 * - Switching on stores the hash of the password and never the password,
 *   remembers blog_public and sets it to 0, and empties the page cache;
 *   switching off writes the remembered blog_public back (also a 0), drops
 *   the hash and empties the cache again. On while on changes nothing and
 *   shows no password; a new password makes every cookie invalid.
 * - The cookie is an HMAC over the hash and the expiry with the site's salt:
 *   valid until it expires, invalid when expired, altered, or for another
 *   hash.
 * - The front-end gate answers a visitor without login and cookie with the
 *   password page and 401, a right password with the cookie and a reload,
 *   a wrong one with the page again; and it passes, each clause with its own
 *   case: wp-admin, admin-ajax, cron, XML-RPC, REST and WP-CLI requests, a
 *   REST route WordPress parsed, robots.txt, signed-in users, and a visitor
 *   with a valid cookie; the documents below /.well-known/ and the consent
 *   page it hands to the handler of AB_MCP_OAuth, which ends the request.
 *   Feeds, search, sitemaps and uploads paths that reach WordPress are not
 *   exempt.
 * - The gate decides first on parse_request at priority 1, once WordPress
 *   has parsed the request: a Yoast-like sitemap on pre_get_posts (priority
 *   1) or on parse_request (priority 10) prints nothing for a visitor, since
 *   the 401 comes before; off, both run. A REST request (rest_route parsed)
 *   passes there to the REST gate; the consent page goes to its handler,
 *   ?ab_mcp_oauth=0, an array and the entry points without themes get the
 *   password page; robots passes with themes only and only when robots is
 *   the only parsed variable. The gates on template_redirect and wp stay.
 * - admin-ajax.php and admin-post.php have a gate of their own on admin_init
 *   (priority 1): a nopriv action of a plugin that prints content prints
 *   nothing for a visitor without login and cookie and answers 401 («0» for
 *   AJAX, the password page for admin-post.php, its form posting to the
 *   home page); off, signed in or with a valid cookie it runs; the two
 *   nopriv actions of the core (heartbeat, generate-password) pass, on
 *   admin-ajax.php only; the pages of wp-admin are not this gate's business.
 * - While on, every front-end answer is marked DONOTCACHEPAGE and carries
 *   X-Robots-Tag: noindex, nofollow.
 * - The REST gate passes an error of WordPress's own checks through, passes
 *   signed-in users, the root /wp-json/ (the namespace index the hub
 *   reads), every route of this plugin's namespace (the MCP endpoint with
 *   and without the token in the path, the three OAuth routes), and a
 *   visitor with a valid cookie; wp/v2, oembed/1.0, the Store API and every
 *   other namespace answer 401 to anyone else, whether the route comes as
 *   a pretty permalink or as ?rest_route=.
 * - The gates hang on parse_request before rest_api_loaded() and the plugins
 *   at the default priority (1), after the OAuth handlers (template_redirect
 *   0) and after WordPress's REST checks (rest_authentication_errors 100).
 * - The connection check asks only addresses the protection leaves open or
 *   hands to the OAuth handler; the handler ends every request it serves,
 *   the clause asks its own routing decision, and ?ab_mcp_oauth with any
 *   other value is the password page.
 * - The REST index for a visitor without login and cookie holds the
 *   namespaces alone (what the hub reads), without links to embed; every
 *   REST answer while on carries the no-cache headers.
 * - WordPress in a sub-directory: the request path loses the home folder
 *   once, so the form action and the reload land on the page asked for.
 * - Deactivating writes the search engine visibility back, notes it and
 *   keeps the state; activating takes it again, remembering a value set by
 *   hand in between (a 0 too) where the note is there, and keeping the old
 *   one where it is not; both on every site of a network when done for the
 *   network; uninstalling writes it back before the option goes.
 * - The entry points that run WordPress without themes (wp-signup.php,
 *   wp-activate.php, wp-trackback.php: the template loader skips
 *   template_redirect there but still answers a feed) and wp-links-opml.php
 *   (no wp() at all) meet the gate too; with themes it stays with
 *   template_redirect.
 * - The well-known clause asks the OAuth handler's own path decision, so a
 *   path the handler does not serve (a double slash) is the password page,
 *   not the theme's 404; the namespace of the REST exemption is compared
 *   without regard to case, as WordPress's router matches routes.
 * - wp-activate.php loads WordPress under WP_INSTALLING, where no plugin
 *   loads but must-use plugins do: while on, a loader stands in
 *   WPMU_PLUGIN_DIR (written on switching on, on activation and when
 *   outdated on load; removed on switching off, deactivation and uninstall;
 *   on a network kept while another site is on). It does nothing outside
 *   wp_installing(), without the plugin active, without its files or with
 *   the protection off; under wp_installing() it locks the REST API and the
 *   feed for visitors and passes cookie holders and signed-in users. A
 *   folder that cannot be written is reported by the tool and the card,
 *   with the file to create.
 *   It acts on plugins_loaded, after the network plugins, and requires no
 *   file whose class a network-activated plugin with this core (Pro) has
 *   loaded already: no second copy of the core, no fatal error. Switching
 *   on over ajax carries the note and the file in its answer.
 * - robots.txt passes only on the way with themes; without them the
 *   template loader returns to wp-signup.php, which renders on.
 * - Uninstall respects the note: a value chosen after a deactivation stays.
 * - Mixed activation on a network: a network-wide deactivation leaves out
 *   a site where the plugin is active by its own activation (the protection
 *   runs on there), a network-wide activation remembers nothing twice on
 *   such a site, and the loader counts only sites whose protection runs
 *   (on, plugin active there, no note of a deactivation).
 * - The tool needs manage_options and write access, is not destructive by
 *   its definition, returns the password exactly once, reports the state
 *   and what is not covered, and refuses to renew a password while off with
 *   the way to switch on.
 * - The settings page shows the card between the connection check and the
 *   fine-tuning, with the off form signed, the ajax buttons, and the
 *   password revealed once by the ajax answer, never persisted.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Admin;
use AB_MCP_Auth;
use AB_MCP_Connection_Doctor;
use AB_MCP_Guidance;
use AB_MCP_OAuth;
use AB_MCP_Preview_Protection;
use AB_MCP_Security;
use AB_MCP_Settings;
use AB_MCP_Site_Mode;
use AB_MCP_Tool_Labels;
use AB_MCP_Tool_Registry;
use AB_MCP_Tools_Staging;
use AbTestExit;
use AbTestJsonExit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use WP_Error;

require_once __DIR__ . '/../includes/class-page-cache.php';
require_once __DIR__ . '/../includes/class-preview-protection.php';
require_once __DIR__ . '/../includes/tools/class-tools-staging.php';

final class PreviewProtectionTest extends TestCase {

	protected function setUp(): void {
		ab_test_reset();
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		// Breeze's whole-site purge, one of the eleven AB_MCP_Page_Cache knows:
		// its action is there, so purge_site() fires it, and this counts.
		$GLOBALS['ab_test_purges'] = 0;
		add_action(
			'breeze_clear_all_cache',
			static function (): void {
				++$GLOBALS['ab_test_purges'];
			}
		);
		$GLOBALS['ab_test_options']['blog_public'] = '1';
		$_SERVER                                   = array_merge( $_SERVER, array( 'REQUEST_URI' => '/', 'REQUEST_METHOD' => 'GET' ) );
		$_GET                                      = array();
		$_POST                                     = array();
		$_COOKIE                                   = array();
		unset( $GLOBALS['wp'] );
		// The folder of must-use plugins, a temporary one of this process:
		// every switch-on writes the loader there (WPMU_PLUGIN_DIR as
		// WordPress defines it), so it exists for every test of this class.
		if ( ! \defined( 'WPMU_PLUGIN_DIR' ) ) {
			\define( 'WPMU_PLUGIN_DIR', sys_get_temp_dir() . '/ab-mcp-mu-plugins-' . getmypid() );
		}
		self::cleanMuDir();
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_COOKIE  = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], $GLOBALS['wp'], $GLOBALS['pagenow'] );
		$GLOBALS['ab_test_logged_in']    = false;
		$GLOBALS['ab_test_is_admin']     = false;
		$GLOBALS['ab_test_is_robots']    = false;
		$GLOBALS['ab_test_using_themes'] = true;
		self::scope( 'full' );
		self::cleanMuDir();
	}

	/** Remove the temporary folder of must-use plugins with whatever a test left in it. */
	private static function cleanMuDir(): void {
		$dir = WPMU_PLUGIN_DIR;
		if ( ! is_dir( $dir ) ) {
			return;
		}
		chmod( $dir, 0755 );
		foreach ( (array) glob( $dir . '/{,.}*', GLOB_BRACE ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		rmdir( $dir );
	}

	/** The constants uninstall.php runs under, as UninstallTest sets them. */
	private static function uninstallConstants(): void {
		require_once __DIR__ . '/uninstall-stubs.php';
		if ( ! \defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			\define( 'WP_UNINSTALL_PLUGIN', 'alphabridge-mcp/alphabridge-mcp.php' );
		}
		if ( ! \defined( 'WP_PLUGIN_DIR' ) ) {
			\define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/ab-mcp-uninstall-test-' . getmypid() );
		}
		self::assertFileDoesNotExist( WP_PLUGIN_DIR . '/alphabridge-mcp-pro/alphabridge-mcp-pro.php' );
	}

	/**
	 * Include the loader (its own file, or a copy with another source) with
	 * every hook of the process forgotten first, and let plugins_loaded fire,
	 * as WordPress does after the network plugins.
	 */
	private static function runLoader( string $file ): void {
		$GLOBALS['ab_test_filters'] = array();
		include $file;
		do_action( 'plugins_loaded' );
	}

	/** The access level of the current connection, as AB_MCP_Auth records it after the token check. */
	private static function scope( string $scope ): void {
		( new ReflectionProperty( AB_MCP_Auth::class, 'current_scope' ) )->setValue( null, $scope );
	}

	/** Switch on as the current user, as the tool does; returns the password. */
	private function on(): string {
		$result = AB_MCP_Preview_Protection::enable( 7, AB_MCP_Preview_Protection::SOURCE_TOOL );
		self::assertTrue( $result['changed'] );
		return $result['password'];
	}

	/** A request of a visitor: no login, no cookie, not wp-admin. */
	private function visitor( array $over = array() ): array {
		return array_merge(
			array(
				'path'            => '/',
				'request_uri'     => '/',
				'method'          => 'GET',
				'well_known'      => false,
				'oauth_authorize' => false,
				'password'        => null,
				'cookie'          => '',
				'logged_in'       => false,
				'admin'           => false,
				'cron'            => false,
				'xmlrpc'          => false,
				'rest'            => false,
				'rest_route'      => false,
				'cli'             => false,
				'robots'          => false,
			),
			$over
		);
	}

	/** The WP object as parse_request passes it: the parsed query vars. */
	private static function wp( array $query_vars ): object {
		return (object) array( 'query_vars' => $query_vars );
	}

	/**
	 * A Yoast-like plugin: a callback that prints its sitemap and ends the
	 * request where SEO plugins do, on pre_get_posts (Yoast SEO, priority 1)
	 * or on parse_request (priority 10). Returns a reader of what it printed.
	 */
	private static function sitemapPlugin( string $hook, int $priority ): \Closure {
		$printed = array();
		add_action(
			$hook,
			static function () use ( &$printed, $hook ): void {
				$printed[] = $hook . ': <urlset>https://example.test/secret-page/</urlset>';
				throw new AbTestExit( 'sitemap', $hook );
			},
			$priority
		);
		return static function () use ( &$printed ): array {
			return $printed;
		};
	}

	/** Every stored option and transient as one text, to look for a password in it. */
	private function stored(): string {
		return serialize( $GLOBALS['ab_test_options'] ) . serialize( $GLOBALS['ab_test_transients'] );
	}

	/* ------------------------------------------------------------- state */

	public function testOffByDefaultAndNothingIsLocked(): void {
		$state = AB_MCP_Preview_Protection::state();
		self::assertFalse( $state['on'] );
		self::assertFalse( AB_MCP_Preview_Protection::is_on() );

		self::assertSame( array( 'action' => 'pass', 'reason' => 'off' ), AB_MCP_Preview_Protection::frontend_decision( $this->visitor(), $state, time() ) );
		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
		self::assertNull( AB_MCP_Preview_Protection::gate_rest( null ), 'The REST gate hands the result through while off.' );
		self::assertTrue( AB_MCP_Preview_Protection::gate_rest( true ) );
		self::assertSame( array( 'X-Pingback' => 'x' ), AB_MCP_Preview_Protection::headers( array( 'X-Pingback' => 'x' ) ), 'No header is added while off.' );
		self::assertNull( AB_MCP_Preview_Protection::gate_frontend(), 'The front-end gate ends without a page while off.' );
		self::assertSame( '1', $GLOBALS['ab_test_options']['blog_public'] );
	}

	public function testABrokenRecordReadsAsOff(): void {
		AB_MCP_Settings::set( AB_MCP_Preview_Protection::KEY, array( 'on' => true ) );
		self::assertFalse( AB_MCP_Preview_Protection::is_on(), 'On without a hash is off.' );
		AB_MCP_Settings::set( AB_MCP_Preview_Protection::KEY, array( 'on' => 'yes', 'hash' => 'x' ) );
		self::assertFalse( AB_MCP_Preview_Protection::is_on(), 'Anything but exactly true is off.' );
		AB_MCP_Settings::set( AB_MCP_Preview_Protection::KEY, 'on' );
		self::assertFalse( AB_MCP_Preview_Protection::is_on() );
	}

	public function testSwitchingOnStoresOnlyTheHashRemembersBlogPublicAndPurges(): void {
		$password = $this->on();

		self::assertSame( AB_MCP_Preview_Protection::PASSWORD_LENGTH, strlen( $password ) );
		self::assertMatchesRegularExpression( '/\A[' . preg_quote( AB_MCP_Preview_Protection::PASSWORD_ALPHABET, '/' ) . ']+\z/', $password );
		$state = AB_MCP_Preview_Protection::state();
		self::assertTrue( $state['on'] );
		self::assertTrue( password_verify( $password, $state['hash'] ), 'The hash is of this password.' );
		self::assertStringNotContainsString( $password, $this->stored(), 'The password itself is nowhere in the options.' );
		self::assertSame( '1', $state['blog_public'], 'The earlier search engine visibility is remembered.' );
		self::assertSame( 0, $GLOBALS['ab_test_options']['blog_public'], 'blog_public is 0 while on.' );
		self::assertGreaterThan( 0, $state['since'] );
		self::assertSame( 7, $state['user_id'] );
		self::assertSame( 'user7', $state['user_login'] );
		self::assertSame( 'tool', $state['source'] );
		self::assertSame( 1, $GLOBALS['ab_test_purges'], 'The page cache is emptied once.' );

		$summary = AB_MCP_Preview_Protection::summary();
		self::assertTrue( $summary['enabled'] );
		self::assertSame( '0', $summary['blog_public'] );
		self::assertSame( '1', $summary['blog_public_before'] );
		self::assertSame( array( 'user_id' => 7, 'user_login' => 'user7', 'source' => 'tool' ), $summary['set_by'] );
		self::assertMatchesRegularExpression( '/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/', $summary['since'] );
	}

	public function testSwitchingOnWhileOnChangesNothingAndShowsNoPassword(): void {
		$password = $this->on();
		$hash     = AB_MCP_Preview_Protection::state()['hash'];
		$again    = AB_MCP_Preview_Protection::enable( 7, AB_MCP_Preview_Protection::SOURCE_FORM );
		self::assertFalse( $again['changed'] );
		self::assertSame( '', $again['password'] );
		self::assertSame( $hash, AB_MCP_Preview_Protection::state()['hash'], 'The hash stays: the first password still opens the preview.' );
		self::assertTrue( AB_MCP_Preview_Protection::verify_password( $password, $hash ) );
		self::assertSame( 1, $GLOBALS['ab_test_purges'], 'No second purge.' );
	}

	/** @return array<string,array{0:string}> */
	public static function blogPublicBefore(): array {
		return array(
			'visible before'    => array( '1' ),
			'discouraged before' => array( '0' ),
		);
	}

	#[DataProvider( 'blogPublicBefore' )]
	public function testSwitchingOffWritesBlogPublicBackAndPurges( string $before ): void {
		$GLOBALS['ab_test_options']['blog_public'] = $before;
		$this->on();
		self::assertSame( 0, $GLOBALS['ab_test_options']['blog_public'] );

		$off = AB_MCP_Preview_Protection::disable( 7, AB_MCP_Preview_Protection::SOURCE_FORM );
		self::assertTrue( $off['changed'] );
		self::assertSame( $before, $off['blog_public'] );
		self::assertSame( $before, $GLOBALS['ab_test_options']['blog_public'], 'The remembered value goes back, not a plain 1.' );
		$state = AB_MCP_Preview_Protection::state();
		self::assertFalse( $state['on'] );
		self::assertSame( '', $state['hash'], 'The hash goes with it.' );
		self::assertSame( 'form', $state['source'] );
		self::assertSame( 2, $GLOBALS['ab_test_purges'], 'The page cache is emptied on switching off as well.' );

		$twice = AB_MCP_Preview_Protection::disable( 7, AB_MCP_Preview_Protection::SOURCE_FORM );
		self::assertFalse( $twice['changed'] );
		self::assertSame( $before, $GLOBALS['ab_test_options']['blog_public'] );
		self::assertSame( 2, $GLOBALS['ab_test_purges'] );
	}

	public function testANewPasswordInvalidatesEveryCookie(): void {
		$first = $this->on();
		$hash  = AB_MCP_Preview_Protection::state()['hash'];
		$now   = time();
		$cookie = AB_MCP_Preview_Protection::cookie_value( $hash, $now + 3600 );
		self::assertTrue( AB_MCP_Preview_Protection::cookie_valid( $cookie, $hash, $now ) );

		$new = AB_MCP_Preview_Protection::renew_password( 7, AB_MCP_Preview_Protection::SOURCE_TOOL );
		self::assertTrue( $new['changed'] );
		self::assertNotSame( $first, $new['password'] );
		$state = AB_MCP_Preview_Protection::state();
		self::assertTrue( $state['on'] );
		self::assertSame( '1', $state['blog_public'], 'The remembered visibility stays.' );
		self::assertTrue( AB_MCP_Preview_Protection::verify_password( $new['password'], $state['hash'] ) );
		self::assertFalse( AB_MCP_Preview_Protection::verify_password( $first, $state['hash'] ), 'The first password is gone.' );
		self::assertFalse( AB_MCP_Preview_Protection::cookie_valid( $cookie, $state['hash'], $now ), 'The cookie of the first password is invalid.' );
		self::assertStringNotContainsString( $new['password'], $this->stored() );
	}

	public function testRenewingWhileOffIsRefused(): void {
		self::assertNull( AB_MCP_Preview_Protection::renew_password( 7, AB_MCP_Preview_Protection::SOURCE_TOOL ) );
		self::assertFalse( AB_MCP_Preview_Protection::is_on() );
	}

	public function testEveryPasswordIsNew(): void {
		$seen = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$seen[ AB_MCP_Preview_Protection::generate_password() ] = true;
		}
		self::assertCount( 20, $seen );
	}

	/* ------------------------------------------------------------ cookie */

	public function testTheCookieIsValidUntilItExpiresAndForItsHashOnly(): void {
		$hash  = AB_MCP_Preview_Protection::hash_password( 'x' );
		$other = AB_MCP_Preview_Protection::hash_password( 'y' );
		$now   = 1_800_000_000;
		$value = AB_MCP_Preview_Protection::cookie_value( $hash, $now + 60 );
		self::assertMatchesRegularExpression( '/\A\d+\|[0-9a-f]{64}\z/', $value );

		self::assertTrue( AB_MCP_Preview_Protection::cookie_valid( $value, $hash, $now ) );
		self::assertTrue( AB_MCP_Preview_Protection::cookie_valid( $value, $hash, $now + 59 ) );
		self::assertFalse( AB_MCP_Preview_Protection::cookie_valid( $value, $hash, $now + 60 ), 'Expired at the second it names.' );
		self::assertFalse( AB_MCP_Preview_Protection::cookie_valid( $value, $other, $now ), 'Another hash: another password was set.' );
		self::assertFalse( AB_MCP_Preview_Protection::cookie_valid( $value, '', $now ), 'No hash: off.' );
		self::assertFalse( AB_MCP_Preview_Protection::cookie_valid( AB_MCP_Preview_Protection::cookie_value( '', $now + 60 ), '', $now ), 'Without a hash no cookie is valid, not even one signed for the empty hash.' );
		$altered = ( $now + 3600 ) . substr( $value, strpos( $value, '|' ) );
		self::assertFalse( AB_MCP_Preview_Protection::cookie_valid( $altered, $hash, $now ), 'A later expiry with the old signature.' );
		$flipped = substr( $value, 0, -1 ) . ( '0' === substr( $value, -1 ) ? '1' : '0' );
		self::assertFalse( AB_MCP_Preview_Protection::cookie_valid( $flipped, $hash, $now ), 'A changed signature.' );
		self::assertFalse( AB_MCP_Preview_Protection::cookie_valid( '', $hash, $now ) );
		self::assertFalse( AB_MCP_Preview_Protection::cookie_valid( 'garbage', $hash, $now ) );
		self::assertFalse( AB_MCP_Preview_Protection::cookie_valid( null, $hash, $now ) );
	}

	/* ------------------------------------------------ front-end decision */

	public function testAVisitorWithoutLoginAndCookieIsChallenged(): void {
		$this->on();
		$state = AB_MCP_Preview_Protection::state();
		foreach ( array( '/', '/hello-world/', '/feed/', '/?s=secret', '/wp-sitemap.xml', '/category/news/', '/wp-content/uploads/2026/10/plan.pdf', '/.well-known-not/x' ) as $path ) {
			$d = AB_MCP_Preview_Protection::frontend_decision( $this->visitor( array( 'path' => (string) wp_parse_url( $path, PHP_URL_PATH ), 'request_uri' => $path ) ), $state, time() );
			self::assertSame( array( 'action' => 'challenge', 'reason' => 'anonymous' ), $d, $path );
		}
	}

	/** @return array<string,array{0:array,1:string}> */
	public static function passes(): array {
		return array(
			'wp-admin'                 => array( array( 'admin' => true ), 'admin' ),
			'wp-cron'                  => array( array( 'cron' => true ), 'cron' ),
			'xmlrpc'                   => array( array( 'xmlrpc' => true ), 'xmlrpc' ),
			'REST'                     => array( array( 'rest' => true ), 'rest' ),
			'WP-CLI'                   => array( array( 'cli' => true ), 'cli' ),
			'REST route parsed'        => array( array( 'path' => '/index.php', 'request_uri' => '/index.php?rest_route=/wp/v2/posts', 'rest_route' => true ), 'rest_route' ),
			'robots.txt'               => array( array( 'path' => '/robots.txt', 'robots' => true ), 'robots' ),
			'signed-in user'           => array( array( 'logged_in' => true ), 'logged_in' ),
		);
	}

	#[DataProvider( 'passes' )]
	public function testTheGateLeavesOpenWhatAlphaBridgeAndWordPressNeed( array $over, string $reason ): void {
		$this->on();
		$state = AB_MCP_Preview_Protection::state();
		$d     = AB_MCP_Preview_Protection::frontend_decision( $this->visitor( $over ), $state, time() );
		self::assertSame( array( 'action' => 'pass', 'reason' => $reason ), $d );
	}

	/** @return array<string,array{0:array,1:string}> */
	public static function served(): array {
		return array(
			'OAuth metadata document' => array( array( 'path' => '/.well-known/oauth-authorization-server', 'well_known' => true ), 'well_known' ),
			'consent page'            => array( array( 'request_uri' => '/?ab_mcp_oauth=authorize&client_id=x', 'oauth_authorize' => true ), 'oauth_authorize' ),
		);
	}

	#[DataProvider( 'served' )]
	public function testWhatAlphaBridgeServesItselfGoesToItsHandlerNotPastTheGate( array $over, string $reason ): void {
		// Not «pass»: between parse_request and template_redirect other
		// plugins answer, and a sitemap variable beside the document would be
		// their sitemap. The gate calls the handler, which ends the request.
		$this->on();
		$state = AB_MCP_Preview_Protection::state();
		$d     = AB_MCP_Preview_Protection::frontend_decision( $this->visitor( $over ), $state, time() );
		self::assertSame( array( 'action' => 'serve', 'reason' => $reason ), $d );
		// A clause stands before the others only when the request is one of
		// its kind: a signed-in user or a cookie changes nothing about it.
		self::assertSame( 'serve', AB_MCP_Preview_Protection::frontend_decision( $this->visitor( $over + array( 'logged_in' => true ) ), $state, time() )['action'] );
		// REST comes first: rest_api_loaded() serves such a request next.
		self::assertSame( 'rest_route', AB_MCP_Preview_Protection::frontend_decision( $this->visitor( array_merge( $over, array( 'rest_route' => true ) ) ), $state, time() )['reason'] );
	}

	public function testTheRestOfWellKnownIsNotOpenAndTheHubReadsThe401AsNotThere(): void {
		$this->on();
		$state = AB_MCP_Preview_Protection::state();
		// The hub asks for this document first and reads any status but 200
		// as «not there» (alphabridge-connect src/detect.ts, readWellKnown()),
		// so the password page sends it on to the probe as a 404 would; left
		// open, WordPress would answer it with the theme's 404 page.
		$decide = static function ( string $uri ) use ( $state ): string {
			$_SERVER['REQUEST_URI'] = $uri;
			return AB_MCP_Preview_Protection::frontend_decision( AB_MCP_Preview_Protection::frontend_context(), $state, time() )['action'];
		};
		self::assertSame( 'challenge', $decide( '/.well-known/alphabridge.json' ) );
		self::assertSame( 'challenge', $decide( '/.well-known/security.txt' ) );
		self::assertSame( 'challenge', $decide( '/.well-known/oauth-authorization-server-x' ), 'Matched as AB_MCP_OAuth matches it: the document, or a path below it.' );
		self::assertSame( 'serve', $decide( '/.well-known/oauth-protected-resource' ) );
		self::assertSame( 'serve', $decide( '/.well-known/oauth-protected-resource/wp-json/alphabridge/v1/mcp' ) );
		self::assertSame( 'serve', $decide( '/.well-known/oauth-authorization-server?x=1' ) );
		AB_MCP_Settings::set( 'oauth_enabled', false );
		self::assertSame( 'challenge', $decide( '/.well-known/oauth-authorization-server' ), 'With the sign-in off nothing serves the document; WordPress would show its 404 page.' );
	}

	public function testTheWellKnownClauseAsksTheHandlerSoADoubleSlashIsNotOpen(): void {
		// AB_MCP_OAuth::serve_well_known() takes the home path off the front
		// and matches the rest as sent; it normalises nothing, so with a
		// double slash it serves nothing, and WordPress would answer the
		// theme's 404 page. The gate asks the same function.
		self::assertSame( 'authorization-server', AB_MCP_OAuth::well_known_document( '/.well-known/oauth-authorization-server' ) );
		self::assertSame( 'protected-resource', AB_MCP_OAuth::well_known_document( '/.well-known/oauth-protected-resource/wp-json/alphabridge/v1/mcp?x=1' ) );
		self::assertSame( '', AB_MCP_OAuth::well_known_document( '//.well-known/oauth-authorization-server' ) );
		self::assertSame( '', AB_MCP_OAuth::well_known_document( '/.well-known//oauth-authorization-server' ) );
		self::assertSame( '', AB_MCP_OAuth::well_known_document( '/.well-known/oauth-authorization-server-x' ) );
		self::assertSame( '', AB_MCP_OAuth::well_known_document( '/.well-known/alphabridge.json' ) );

		$GLOBALS['ab_test_home'] = 'https://example.test/staging';
		self::assertSame( 'authorization-server', AB_MCP_OAuth::well_known_document( '/staging/.well-known/oauth-authorization-server' ) );
		self::assertSame( '', AB_MCP_OAuth::well_known_document( '/staging//.well-known/oauth-authorization-server' ), 'The handler does not serve this.' );
		$this->on();
		$state                  = AB_MCP_Preview_Protection::state();
		$_SERVER['REQUEST_URI'] = '/staging//.well-known/oauth-authorization-server';
		self::assertSame( 'challenge', AB_MCP_Preview_Protection::frontend_decision( AB_MCP_Preview_Protection::frontend_context(), $state, time() )['action'], 'So the gate does not open it either.' );
		$_SERVER['REQUEST_URI'] = '/staging/.well-known/oauth-authorization-server';
		self::assertSame( 'serve', AB_MCP_Preview_Protection::frontend_decision( AB_MCP_Preview_Protection::frontend_context(), $state, time() )['action'] );
	}

	public function testTheOAuthParameterOpensNothingButTheConsentPageWhichGoesToItsHandler(): void {
		$this->on();
		$state = AB_MCP_Preview_Protection::state();
		// The clause asks the handler's own routing decision: any other value
		// of the parameter, an array, a case or a space is nothing the
		// handler serves, and is the password page.
		foreach ( array( '0', 'x', '', 'authorize ', 'Authorize', array( 'authorize' ) ) as $value ) {
			$_GET                   = array( 'ab_mcp_oauth' => $value );
			$_SERVER['REQUEST_URI'] = '/beitrag/?ab_mcp_oauth=' . ( is_array( $value ) ? 'a' : rawurlencode( $value ) );
			self::assertFalse( AB_MCP_OAuth::is_authorize_request() );
			$d = AB_MCP_Preview_Protection::frontend_decision( AB_MCP_Preview_Protection::frontend_context(), $state, time() );
			self::assertSame( 'challenge', $d['action'], 'ab_mcp_oauth=' . ( is_array( $value ) ? 'array' : $value ) . ' is nothing the handler serves: the password page.' );
			try {
				AB_MCP_Preview_Protection::gate_parse_request( self::wp( array() ) );
				self::fail( 'No page.' );
			} catch ( AbTestExit $exit ) {
				self::assertSame( 'die', $exit->kind );
				self::assertSame( 401, $GLOBALS['ab_test_die_args']['response'] );
			}
		}
		// The consent page itself: the gate hands it to the handler, which
		// ends every request it serves; for a visitor with a client that
		// identifies itself with a metadata document (a URL) the redirect to
		// the login comes first, after the mark the firewall self-test looks
		// for (with any other client id the error page for an unknown
		// client, see the next test).
		$_GET                      = array( 'ab_mcp_oauth' => 'authorize', 'client_id' => 'https://client.test/client.json', 'redirect_uri' => 'https://client.test/cb', 'response_type' => 'code' );
		$_REQUEST                  = $_GET;
		$_SERVER['REQUEST_URI']    = '/?ab_mcp_oauth=authorize&client_id=https%3A%2F%2Fclient.test%2Fclient.json&redirect_uri=https%3A%2F%2Fclient.test%2Fcb&response_type=code';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		self::assertTrue( AB_MCP_OAuth::is_authorize_request() );
		self::assertSame( array( 'action' => 'serve', 'reason' => 'oauth_authorize' ), AB_MCP_Preview_Protection::frontend_decision( AB_MCP_Preview_Protection::frontend_context(), $state, time() ) );
		$GLOBALS['ab_test_actions'] = array();
		try {
			AB_MCP_Preview_Protection::gate_parse_request( self::wp( array() ) );
			self::fail( 'The gate returned: WordPress would go on to the plugins and the template.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'login', $exit->kind, 'The handler answered, not the password page.' );
		}
		self::assertContains( 'ab_mcp_oauth_mark_sent', array_column( $GLOBALS['ab_test_actions'], 0 ), 'The mark of the authorization endpoint went out first.' );
		// Without themes the handler would never run (no template_redirect),
		// so the password page as before: wp-signup.php?ab_mcp_oauth=authorize
		// is not the consent page.
		$GLOBALS['ab_test_using_themes'] = false;
		$_SERVER['REQUEST_URI']          = '/wp-signup.php?ab_mcp_oauth=authorize&client_id=https%3A%2F%2Fclient.test%2Fclient.json';
		self::assertSame( 'challenge', AB_MCP_Preview_Protection::frontend_decision( AB_MCP_Preview_Protection::frontend_context( false ), $state, time() )['action'] );
		try {
			AB_MCP_Preview_Protection::gate_parse_request( self::wp( array() ) );
			self::fail( 'No page.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'die', $exit->kind );
		}
		$GLOBALS['ab_test_using_themes'] = true;
		$_REQUEST                        = array();
		AB_MCP_OAuth::boot();
		AB_MCP_Preview_Protection::hook();
		self::assertLessThan( has_action( 'template_redirect', array( AB_MCP_Preview_Protection::class, 'gate_frontend' ) ), has_action( 'template_redirect', array( AB_MCP_OAuth::class, 'handle_authorize' ) ), 'On template_redirect the handler still runs first.' );
	}

	public function testTheConsentPageWithASitemapVariableIsTheConsentPageNotTheSitemap(): void {
		// A clause that passed the consent page on parse_request and waited
		// for the handler on template_redirect would be a hole: a Yoast-like
		// plugin on pre_get_posts answers the sitemap variable before. The
		// gate hands the request to the handler instead, which ends it.
		$this->on();
		$sitemap                   = self::sitemapPlugin( 'pre_get_posts', 1 );
		$_GET                      = array( 'ab_mcp_oauth' => 'authorize', 'client_id' => 'https://client.test/client.json', 'sitemap' => '1' );
		$_REQUEST                  = $_GET;
		$_SERVER['REQUEST_URI']    = '/?ab_mcp_oauth=authorize&client_id=https%3A%2F%2Fclient.test%2Fclient.json&sitemap=1';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		AB_MCP_Preview_Protection::hook();
		try {
			do_action_ref_array( 'parse_request', array( self::wp( array( 'sitemap' => '1' ) ) ) );
			do_action( 'pre_get_posts' );
			self::fail( 'Nothing answered.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'login', $exit->kind, 'The handler: the redirect to the login.' );
		}
		self::assertSame( array(), $sitemap(), 'The sitemap plugin never ran.' );
		$_REQUEST = array();
	}

	public function testEveryBranchOfTheHandlerEndsTheRequestWhereTheGateHandsItOver(): void {
		// The branches a test process can take without exit: a visitor with a
		// client that identifies itself with a metadata document (redirect to
		// the login, before the document is fetched), a visitor with an
		// unknown client and the sign-in switched off (error page, 400), a
		// signed-in user without the capability (error page). The consent
		// screen and the redirects back to the client end with exit in the
		// handler itself (consent_page(), client_error_redirect(), the code),
		// read in the source. None of them is the password page (401).
		$this->on();
		$_SERVER['REQUEST_URI']    = '/?ab_mcp_oauth=authorize';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$run                       = static function ( array $get ): AbTestExit {
			$_GET     = array( 'ab_mcp_oauth' => 'authorize' ) + $get;
			$_REQUEST = $_GET;
			try {
				AB_MCP_Preview_Protection::gate_parse_request( (object) array( 'query_vars' => array() ) );
			} catch ( AbTestExit $exit ) {
				return $exit;
			}
			self::fail( 'handle_authorize() returned for ' . http_build_query( $get ) . '; the password page would follow, never the site.' );
		};
		self::assertSame( 'login', $run( array( 'client_id' => 'https://client.test/client.json' ) )->kind, 'A visitor: to the login.' );
		$exit = $run( array( 'client_id' => 'unknown' ) );
		self::assertSame( 'die', $exit->kind, 'An unknown client: the error page.' );
		self::assertSame( 400, $GLOBALS['ab_test_die_args']['response'] );
		AB_MCP_Settings::set( 'oauth_enabled', false );
		$exit = $run( array( 'client_id' => 'https://client.test/client.json' ) );
		self::assertSame( 'die', $exit->kind, 'Sign-in off: the error page.' );
		self::assertSame( 400, $GLOBALS['ab_test_die_args']['response'] );
		AB_MCP_Settings::set( 'oauth_enabled', true );
		$GLOBALS['ab_test_logged_in'] = true;
		$GLOBALS['ab_test_can']       = static fn(): bool => false;
		$exit                         = $run( array( 'client_id' => 'https://client.test/client.json' ) );
		self::assertSame( 'die', $exit->kind, 'Signed in without the capability: the error page.' );
		self::assertSame( 400, $GLOBALS['ab_test_die_args']['response'] );
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		$GLOBALS['ab_test_logged_in'] = false;
		$_REQUEST                     = array();
	}

	public function testASubDirectoryInstallLosesItsFolderOnceOnly(): void {
		$GLOBALS['ab_test_home'] = 'https://example.test/staging';
		$password                = $this->on();
		$state                   = AB_MCP_Preview_Protection::state();

		self::assertSame( '/about/', AB_MCP_Preview_Protection::strip_home( '/staging/about/' ) );
		self::assertSame( '/', AB_MCP_Preview_Protection::strip_home( '/staging/' ) );
		self::assertSame( '/', AB_MCP_Preview_Protection::strip_home( '/staging' ) );
		self::assertSame( '/staging-old/x', AB_MCP_Preview_Protection::strip_home( '/staging-old/x' ), 'Only the folder itself, not a name that starts like it.' );
		self::assertSame( '/other/', AB_MCP_Preview_Protection::strip_home( '/other/' ), 'A path outside the folder stays as it is.' );

		$_SERVER['REQUEST_URI'] = '/staging/about/?draft=1';
		$ctx                    = AB_MCP_Preview_Protection::frontend_context();
		self::assertSame( '/about/', $ctx['path'] );
		try {
			AB_MCP_Preview_Protection::gate_frontend();
			self::fail( 'No page.' );
		} catch ( AbTestExit $exit ) {
			self::assertStringContainsString( 'action="https://example.test/staging/about/?draft=1"', $exit->detail, 'The folder once, not twice.' );
			self::assertStringContainsString( 'redirect_to=' . rawurlencode( 'https://example.test/staging/about/?draft=1' ), $exit->detail );
		}
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST[ AB_MCP_Preview_Protection::FIELD ] = $password;
		try {
			AB_MCP_Preview_Protection::gate_frontend();
			self::fail( 'No redirect.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'https://example.test/staging/about/?draft=1', $exit->detail );
		}
		$_POST = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';

		// The well-known documents and the REST prefix sit under the folder.
		$_SERVER['REQUEST_URI'] = '/staging/.well-known/oauth-authorization-server';
		self::assertSame( 'serve', AB_MCP_Preview_Protection::frontend_decision( AB_MCP_Preview_Protection::frontend_context(), $state, time() )['action'] );
		self::assertSame( '/wp/v2/posts', AB_MCP_Preview_Protection::rest_route( array( 'REQUEST_URI' => '/staging/wp-json/wp/v2/posts' ), array() ) );
		self::assertSame( '/', AB_MCP_Preview_Protection::rest_route( array( 'REQUEST_URI' => '/staging/wp-json/' ), array() ) );
		$_SERVER['REQUEST_URI'] = '/staging/wp-json/wp/v2/posts';
		self::assertInstanceOf( WP_Error::class, AB_MCP_Preview_Protection::gate_rest( true ) );
		$_SERVER['REQUEST_URI'] = '/staging/wp-json/alphabridge/v1/mcp';
		self::assertTrue( AB_MCP_Preview_Protection::gate_rest( true ) );
	}

	public function testAVisitorWithAValidCookiePassesAnExpiredOneDoesNot(): void {
		$this->on();
		$state = AB_MCP_Preview_Protection::state();
		$now   = time();
		$good  = AB_MCP_Preview_Protection::cookie_value( $state['hash'], $now + 100 );
		self::assertSame( array( 'action' => 'pass', 'reason' => 'cookie' ), AB_MCP_Preview_Protection::frontend_decision( $this->visitor( array( 'cookie' => $good ) ), $state, $now ) );
		self::assertSame( 'challenge', AB_MCP_Preview_Protection::frontend_decision( $this->visitor( array( 'cookie' => $good ) ), $state, $now + 100 )['action'], 'Expired: the password page again.' );
		$old = AB_MCP_Preview_Protection::cookie_value( AB_MCP_Preview_Protection::hash_password( 'earlier' ), $now + 100 );
		self::assertSame( 'challenge', AB_MCP_Preview_Protection::frontend_decision( $this->visitor( array( 'cookie' => $old ) ), $state, $now )['action'], 'A cookie of an earlier password.' );
	}

	public function testTheRightPasswordIsAcceptedTheWrongOneRejectedAndOnlyWhenPosted(): void {
		$password = $this->on();
		$state    = AB_MCP_Preview_Protection::state();
		self::assertSame( array( 'action' => 'accept', 'reason' => 'password' ), AB_MCP_Preview_Protection::frontend_decision( $this->visitor( array( 'method' => 'POST', 'password' => $password ) ), $state, time() ) );
		self::assertSame( array( 'action' => 'reject', 'reason' => 'wrong_password' ), AB_MCP_Preview_Protection::frontend_decision( $this->visitor( array( 'method' => 'POST', 'password' => strrev( $password ) . 'x' ) ), $state, time() ) );
		self::assertSame( 'reject', AB_MCP_Preview_Protection::frontend_decision( $this->visitor( array( 'method' => 'POST', 'password' => '' ) ), $state, time() )['action'], 'An empty password is wrong.' );
		self::assertSame( 'challenge', AB_MCP_Preview_Protection::frontend_decision( $this->visitor( array( 'method' => 'GET', 'password' => $password ) ), $state, time() )['action'], 'Only a POST carries the password.' );
	}

	public function testTheContextReadsTheRequest(): void {
		$_SERVER['REQUEST_URI']    = '/hello/?x=1';
		$_SERVER['REQUEST_METHOD'] = 'post';
		$_GET['ab_mcp_oauth']      = 'authorize';
		$_POST[ AB_MCP_Preview_Protection::FIELD ] = 'secret';
		$_COOKIE[ AB_MCP_Preview_Protection::COOKIE ] = '1|abc';
		$GLOBALS['ab_test_logged_in'] = true;
		$GLOBALS['ab_test_is_admin']  = true;

		$ctx = AB_MCP_Preview_Protection::frontend_context();
		self::assertSame( '/hello/', $ctx['path'] );
		self::assertSame( '/hello/?x=1', $ctx['request_uri'] );
		self::assertSame( 'POST', $ctx['method'] );
		self::assertTrue( $ctx['oauth_authorize'], 'The consent page, as the handler decides it.' );
		self::assertFalse( AB_MCP_Preview_Protection::frontend_context( false )['oauth_authorize'], 'Without themes the handler would not run.' );
		self::assertFalse( $ctx['rest_route'], 'No parsed query here.' );
		self::assertFalse( $ctx['well_known'], 'Not one of the two documents.' );
		self::assertSame( 'secret', $ctx['password'] );
		self::assertSame( '1|abc', $ctx['cookie'] );
		self::assertTrue( $ctx['logged_in'] );
		self::assertTrue( $ctx['admin'] );
		self::assertArrayNotHasKey( 'ajax', $ctx, 'DOING_AJAX proves nothing about the script on the front end.' );
		self::assertFalse( $ctx['cron'] );
		self::assertFalse( $ctx['xmlrpc'] );
		self::assertFalse( $ctx['rest'] );
		self::assertFalse( $ctx['cli'] );
		self::assertFalse( $ctx['robots'] );

		$_GET = array();
		$_POST = array();
		$_COOKIE = array();
		$GLOBALS['ab_test_logged_in'] = false;
		$GLOBALS['ab_test_is_admin']  = false;
		unset( $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'] );
		$ctx = AB_MCP_Preview_Protection::frontend_context();
		self::assertSame( '/', $ctx['path'] );
		self::assertSame( 'GET', $ctx['method'] );
		self::assertNull( $ctx['password'] );
		self::assertSame( '', $ctx['cookie'] );
		self::assertFalse( $ctx['oauth_authorize'] );
		// On parse_request the parsed query vars decide REST and robots.
		self::assertTrue( AB_MCP_Preview_Protection::frontend_context( true, self::wp( array( 'rest_route' => '/wp/v2/posts' ) ) )['rest_route'] );
		self::assertFalse( AB_MCP_Preview_Protection::frontend_context( true, self::wp( array( 'rest_route' => '' ) ) )['rest_route'], 'Empty: not a REST request, as rest_api_loaded() reads it.' );
		self::assertFalse( AB_MCP_Preview_Protection::frontend_context( true, self::wp( array( 'pagename' => 'wp-json/wp/v2/posts' ) ) )['rest_route'], 'A path that looks like REST decides nothing.' );
		self::assertFalse( AB_MCP_Preview_Protection::frontend_context( true, (object) array() )['rest_route'], 'No query vars at all.' );
		$_SERVER['REQUEST_URI'] = '/.well-known/oauth-authorization-server';
		self::assertTrue( AB_MCP_Preview_Protection::frontend_context()['well_known'], 'The sign-in is on by default, and the handler serves this.' );
		AB_MCP_Settings::set( 'oauth_enabled', false );
		self::assertFalse( AB_MCP_Preview_Protection::frontend_context()['well_known'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testTheContextReadsTheRequestConstants(): void {
		define( 'DOING_AJAX', true );
		define( 'DOING_CRON', true );
		define( 'XMLRPC_REQUEST', true );
		define( 'REST_REQUEST', true );
		define( 'WP_CLI', true );
		$ctx = AB_MCP_Preview_Protection::frontend_context();
		self::assertArrayNotHasKey( 'ajax', $ctx );
		self::assertTrue( $ctx['cron'] );
		self::assertTrue( $ctx['xmlrpc'] );
		self::assertTrue( $ctx['rest'] );
		self::assertTrue( $ctx['cli'] );
	}

	/* ------------------------------------------------- front-end gate */

	public function testTheGateAnswersAVisitorWithThePasswordPageAnd401(): void {
		$this->on();
		$_SERVER['REQUEST_URI'] = '/about/?draft=1';
		try {
			AB_MCP_Preview_Protection::gate_frontend();
			self::fail( 'The gate ended without a page.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'die', $exit->kind );
			self::assertSame( 401, $GLOBALS['ab_test_die_args']['response'] );
			self::assertStringContainsString( 'name="' . AB_MCP_Preview_Protection::FIELD . '"', $exit->detail );
			self::assertStringContainsString( 'action="https://example.test/about/?draft=1"', $exit->detail, 'The form posts to the address the visitor asked for.' );
			self::assertStringContainsString( 'type="password"', $exit->detail );
			self::assertStringContainsString( 'This site is a preview and not public yet.', $exit->detail );
			self::assertStringNotContainsString( 'not right', $exit->detail, 'No complaint before a password was tried.' );
			self::assertStringContainsString( 'https://example.test/wp-login.php?redirect_to=', $exit->detail, 'Members are led to the login.' );
		}
	}

	public function testTheGateAnswersAWrongPasswordWithThePageAgain(): void {
		$this->on();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST[ AB_MCP_Preview_Protection::FIELD ] = 'nope';
		try {
			AB_MCP_Preview_Protection::gate_frontend();
			self::fail( 'The gate ended without a page.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'die', $exit->kind );
			self::assertSame( 401, $GLOBALS['ab_test_die_args']['response'] );
			self::assertStringContainsString( 'That password is not right.', $exit->detail );
		}
	}

	public function testTheGateAcceptsTheRightPasswordAndReloadsTheSameAddress(): void {
		$password = $this->on();
		$_SERVER['REQUEST_URI']    = '/about/?draft=1';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST[ AB_MCP_Preview_Protection::FIELD ] = $password;
		try {
			AB_MCP_Preview_Protection::gate_frontend();
			self::fail( 'The gate ended without a redirect.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'redirect', $exit->kind );
			self::assertSame( 'https://example.test/about/?draft=1', $exit->detail );
		}
	}

	public function testTheGateNeverTrustsAHostTheVisitorSent(): void {
		$password = $this->on();
		$_SERVER['REQUEST_URI']    = 'https://evil.test/about/';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST[ AB_MCP_Preview_Protection::FIELD ] = $password;
		try {
			AB_MCP_Preview_Protection::gate_frontend();
			self::fail( 'The gate ended without a redirect.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'https://example.test/about/', $exit->detail, 'Path and query only, on this site.' );
		}
	}

	public function testTheGatePassesASignedInUserAndACookieHolder(): void {
		$this->on();
		$GLOBALS['ab_test_logged_in'] = true;
		self::assertNull( AB_MCP_Preview_Protection::gate_frontend() );
		$GLOBALS['ab_test_logged_in'] = false;
		$_COOKIE[ AB_MCP_Preview_Protection::COOKIE ] = AB_MCP_Preview_Protection::cookie_value( AB_MCP_Preview_Protection::state()['hash'], time() + 100 );
		self::assertNull( AB_MCP_Preview_Protection::gate_frontend() );
	}

	public function testAFeedThroughAnEntryPointWithoutThemesMeetsTheGateToo(): void {
		// wp-signup.php, wp-activate.php and wp-trackback.php run wp() without
		// WP_USE_THEMES; the template loader skips template_redirect there
		// but still answers is_feed() with do_feed() (WordPress core,
		// wp-includes/template-loader.php). So the gate also hangs on wp.
		$GLOBALS['ab_test_using_themes'] = false;
		$_SERVER['REQUEST_URI']          = '/wp-signup.php?feed=rss2';
		self::assertNull( AB_MCP_Preview_Protection::gate_without_themes(), 'Off: the feed.' );

		$this->on();
		try {
			AB_MCP_Preview_Protection::gate_without_themes();
			self::fail( 'The feed went out.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'die', $exit->kind );
			self::assertSame( 401, $GLOBALS['ab_test_die_args']['response'] );
			self::assertStringContainsString( 'name="' . AB_MCP_Preview_Protection::FIELD . '"', $exit->detail );
		}
		$GLOBALS['ab_test_logged_in'] = true;
		self::assertNull( AB_MCP_Preview_Protection::gate_without_themes(), 'A signed-in user signs up on the network as before.' );
		$GLOBALS['ab_test_logged_in'] = false;
		$_COOKIE[ AB_MCP_Preview_Protection::COOKIE ] = AB_MCP_Preview_Protection::cookie_value( AB_MCP_Preview_Protection::state()['hash'], time() + 100 );
		self::assertNull( AB_MCP_Preview_Protection::gate_without_themes(), 'A visitor with the cookie reads the feed.' );
		$_COOKIE = array();

		// With themes template_redirect follows and runs the gate once.
		$GLOBALS['ab_test_using_themes'] = true;
		$_SERVER['REQUEST_URI']          = '/feed/';
		self::assertNull( AB_MCP_Preview_Protection::gate_without_themes(), 'index.php: nothing here, the gate on template_redirect answers.' );
		try {
			AB_MCP_Preview_Protection::gate_frontend();
			self::fail( 'The feed went out.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'die', $exit->kind );
		}
	}

	/* ------------------------------------------- the first line: parse_request */

	/** @return array<string,array{0:string,1:int}> */
	public static function sitemapHooks(): array {
		return array(
			'Yoast SEO on pre_get_posts 1'        => array( 'pre_get_posts', 1 ),
			'another plugin on parse_request 10'  => array( 'parse_request', 10 ),
		);
	}

	#[DataProvider( 'sitemapHooks' )]
	public function testASitemapPluginPrintsNothingForAVisitorThe401ComesBefore( string $hook, int $priority ): void {
		// Measured 31.10.2026 (WordPress 7.1.4, Yoast SEO 28.8): with the
		// gate on template_redirect alone, /sitemap_index.xml, the type
		// sitemaps and /?sitemap=1 came with 200 and every address. Yoast
		// answers on pre_get_posts at 1 (WPSEO_Sitemaps::redirect()), others
		// on parse_request at 10; both before template_redirect.
		$printed = self::sitemapPlugin( $hook, $priority );
		AB_MCP_Preview_Protection::hook();
		$_SERVER['REQUEST_URI'] = '/sitemap_index.xml';
		$wp                     = self::wp( array( 'sitemap' => '1' ) );
		$sequence               = static function () use ( $wp ): void {
			// What WP::main() does: parse_request, then (headers,) the query
			// with pre_get_posts, then the template.
			do_action_ref_array( 'parse_request', array( $wp ) );
			do_action( 'pre_get_posts' );
		};

		// Off: the plugin answers as always.
		try {
			$sequence();
			self::fail( 'The sitemap plugin did not run.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'sitemap', $exit->kind );
		}
		self::assertCount( 1, $printed() );

		// On: the 401 comes first, the plugin never runs.
		$this->on();
		try {
			$sequence();
			self::fail( 'Nothing answered.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'die', $exit->kind, 'The password page, not the sitemap.' );
			self::assertSame( 401, $GLOBALS['ab_test_die_args']['response'] );
			self::assertStringContainsString( 'name="' . AB_MCP_Preview_Protection::FIELD . '"', $exit->detail );
		}
		self::assertCount( 1, $printed(), 'Nothing more was printed.' );

		// A signed-in user and a cookie holder read the sitemap as before.
		$GLOBALS['ab_test_logged_in'] = true;
		try {
			$sequence();
			self::fail( 'The sitemap plugin did not run.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'sitemap', $exit->kind );
		}
		$GLOBALS['ab_test_logged_in'] = false;
		$_COOKIE[ AB_MCP_Preview_Protection::COOKIE ] = AB_MCP_Preview_Protection::cookie_value( AB_MCP_Preview_Protection::state()['hash'], time() + 100 );
		try {
			$sequence();
			self::fail( 'The sitemap plugin did not run.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'sitemap', $exit->kind );
		}
		self::assertCount( 3, $printed() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testDoingAjaxOnTheFrontEndOpensNothing(): void {
		// WooCommerce defines DOING_AJAX on init for ?wc-ajax= and serves it
		// on template_redirect at 0; a clause on the constant let
		// /?sitemap=1&wc-ajax=get_refreshed_fragments pass the first line,
		// and Yoast printed the sitemap on pre_get_posts (Codex, 31.10.2026).
		// The constant proves nothing about the script: admin-ajax.php never
		// reaches the front-end gates.
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_options']['blog_public'] = '1';
		define( 'DOING_AJAX', true );
		$printed = self::sitemapPlugin( 'pre_get_posts', 1 );
		AB_MCP_Preview_Protection::hook();
		$_SERVER['REQUEST_URI'] = '/?sitemap=1&wc-ajax=get_refreshed_fragments';
		$_GET                   = array( 'sitemap' => '1', 'wc-ajax' => 'get_refreshed_fragments' );
		$wp                     = self::wp( array( 'sitemap' => '1' ) );
		$sequence               = static function () use ( $wp ): void {
			do_action_ref_array( 'parse_request', array( $wp ) );
			do_action( 'pre_get_posts' );
		};
		$this->on();
		$state = AB_MCP_Preview_Protection::state();
		self::assertSame( 'challenge', AB_MCP_Preview_Protection::frontend_decision( AB_MCP_Preview_Protection::frontend_context( true, $wp ), $state, time() )['action'] );
		try {
			$sequence();
			self::fail( 'Nothing answered.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'die', $exit->kind, 'The password page, not the sitemap.' );
			self::assertSame( 401, $GLOBALS['ab_test_die_args']['response'] );
		}
		self::assertSame( array(), $printed(), 'The sitemap plugin never ran.' );
		// Signed in, or with the cookie: the request runs as before, the cart
		// fragments of a shop included.
		$GLOBALS['ab_test_logged_in'] = true;
		try {
			$sequence();
			self::fail( 'The sitemap plugin did not run.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'sitemap', $exit->kind );
		}
		$GLOBALS['ab_test_logged_in'] = false;
		$_COOKIE[ AB_MCP_Preview_Protection::COOKIE ] = AB_MCP_Preview_Protection::cookie_value( $state['hash'], time() + 100 );
		try {
			$sequence();
			self::fail( 'The sitemap plugin did not run.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'sitemap', $exit->kind );
		}
		self::assertCount( 2, $printed() );
	}

	public function testARestRequestPassesTheFirstLineToTheRestGate(): void {
		// rest_api_loaded() (wp-includes/rest-api.php) serves on parse_request
		// at 10 every request whose parsed rest_route is not empty; at 1
		// REST_REQUEST is not defined yet. The gate passes exactly these and
		// gate_rest() decides on rest_authentication_errors.
		$this->on();
		$state = AB_MCP_Preview_Protection::state();
		foreach ( array( '/index.php?rest_route=%2Fwp%2Fv2%2Fposts', '/wp-json/wp/v2/posts', '/wp-json/alphabridge/v1/mcp' ) as $uri ) {
			$_SERVER['REQUEST_URI'] = $uri;
			$wp                     = self::wp( array( 'rest_route' => '/wp/v2/posts' ) );
			self::assertSame( array( 'action' => 'pass', 'reason' => 'rest_route' ), AB_MCP_Preview_Protection::frontend_decision( AB_MCP_Preview_Protection::frontend_context( true, $wp ), $state, time() ), $uri );
			self::assertNull( AB_MCP_Preview_Protection::gate_parse_request( $wp ), $uri );
			// And then the REST gate, as for every REST request.
			$GLOBALS['wp'] = $wp;
			self::assertInstanceOf( WP_Error::class, AB_MCP_Preview_Protection::gate_rest( true ), $uri );
			unset( $GLOBALS['wp'] );
		}
		// The path decides nothing: without the parsed variable a request to
		// /wp-json/... is no REST request (rest_api_loaded() serves nothing),
		// and the password page answers it.
		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
		foreach ( array( self::wp( array() ), self::wp( array( 'rest_route' => '' ) ), self::wp( array( 'pagename' => 'wp-json/wp/v2/posts' ) ) ) as $wp ) {
			self::assertSame( 'challenge', AB_MCP_Preview_Protection::frontend_decision( AB_MCP_Preview_Protection::frontend_context( true, $wp ), $state, time() )['action'] );
			try {
				AB_MCP_Preview_Protection::gate_parse_request( $wp );
				self::fail( 'No page.' );
			} catch ( AbTestExit $exit ) {
				self::assertSame( 'die', $exit->kind );
			}
		}
	}

	public function testRobotsOnTheFirstLineIsTheParsedVariableAloneAndWithThemesOnly(): void {
		// On parse_request WP_Query has not run, so is_robots() says nothing
		// yet; the gate reads the parsed variable robots (WP_Query sets
		// is_robots when '' !== $qv['robots']). Only when it is the only
		// variable: /?robots=1&sitemap=1 is a sitemap plugin's answer on
		// pre_get_posts, not robots.txt.
		$this->on();
		$state                  = AB_MCP_Preview_Protection::state();
		$_SERVER['REQUEST_URI'] = '/robots.txt';
		$decide                 = static fn( bool $themes, array $vars ): string => AB_MCP_Preview_Protection::frontend_decision( AB_MCP_Preview_Protection::frontend_context( $themes, self::wp( $vars ) ), $state, time() )['action'];

		self::assertSame( 'pass', $decide( true, array( 'robots' => '1' ) ) );
		self::assertNull( AB_MCP_Preview_Protection::gate_parse_request( self::wp( array( 'robots' => '1' ) ) ), 'robots.txt is served, with Disallow: / from blog_public 0.' );
		self::assertSame( 'challenge', $decide( false, array( 'robots' => '1' ) ), 'Without themes the template loader returns to wp-signup.php, which renders on.' );
		self::assertSame( 'challenge', $decide( true, array( 'robots' => '' ) ), 'Empty: WP_Query would not set is_robots.' );
		self::assertSame( 'challenge', $decide( true, array( 'robots' => array( '1' ) ) ), 'Not a value WordPress would print robots.txt for without a warning.' );
		self::assertSame( 'challenge', $decide( true, array( 'robots' => '1', 'sitemap' => '1' ) ), 'A second variable: whatever answers it, not robots.txt.' );
		self::assertSame( 'challenge', $decide( true, array( 'sitemap' => '1', 'robots' => '1' ) ) );
		self::assertSame( 'challenge', $decide( true, array( 'feed' => 'rss2' ) ) );
		// is_robots() is not asked on parse_request: the parsed variable is.
		$GLOBALS['ab_test_is_robots'] = true;
		self::assertSame( 'challenge', $decide( true, array( 'sitemap' => '1' ) ) );
		self::assertSame( 'pass', AB_MCP_Preview_Protection::frontend_decision( AB_MCP_Preview_Protection::frontend_context( true ), $state, time() )['action'], 'template_redirect: is_robots().' );
		$GLOBALS['ab_test_is_robots'] = false;

		$GLOBALS['ab_test_using_themes'] = false;
		$_SERVER['REQUEST_URI']          = '/wp-signup.php?robots=1';
		try {
			AB_MCP_Preview_Protection::gate_parse_request( self::wp( array( 'robots' => '1' ) ) );
			self::fail( 'wp-signup.php rendered with the theme.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'die', $exit->kind );
		}
	}

	public function testTheFirstLineDecidesForTheEntryPointsWithoutThemesToo(): void {
		// wp-signup.php, wp-activate.php and wp-trackback.php run wp(), so
		// parse_request fires there as well, with wp_using_themes() false.
		$GLOBALS['ab_test_using_themes'] = false;
		$_SERVER['REQUEST_URI']          = '/wp-signup.php?feed=rss2';
		$wp                              = self::wp( array( 'feed' => 'rss2' ) );
		self::assertNull( AB_MCP_Preview_Protection::gate_parse_request( $wp ), 'Off: the feed.' );
		$this->on();
		try {
			AB_MCP_Preview_Protection::gate_parse_request( $wp );
			self::fail( 'The feed went out.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'die', $exit->kind );
			self::assertSame( 401, $GLOBALS['ab_test_die_args']['response'] );
		}
		$GLOBALS['ab_test_logged_in'] = true;
		self::assertNull( AB_MCP_Preview_Protection::gate_parse_request( $wp ), 'A signed-in user signs up on the network as before.' );
		$GLOBALS['ab_test_logged_in'] = false;
		$_COOKIE[ AB_MCP_Preview_Protection::COOKIE ] = AB_MCP_Preview_Protection::cookie_value( AB_MCP_Preview_Protection::state()['hash'], time() + 100 );
		self::assertNull( AB_MCP_Preview_Protection::gate_parse_request( $wp ), 'A visitor with the cookie reads the feed.' );
	}

	public function testTheWellKnownDocumentGoesToItsHandlerOnTheFirstLine(): void {
		// serve_well_known() prints the JSON and exits, so the handover runs
		// in a process of its own (tests/standalone-preview/well-known-child.php):
		// the gate on parse_request, a sitemap plugin on pre_get_posts, and
		// the request /.well-known/oauth-authorization-server?sitemap=1. The
		// document must come out, never the sitemap or the password page.
		$this->on();
		$run = static function ( string $mode ): array {
			$out  = array();
			$code = 1;
			exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/standalone-preview/well-known-child.php' ) . ' ' . escapeshellarg( $mode ) . ' 2>&1', $out, $code );
			return array( $code, implode( "\n", $out ) );
		};
		list( $code, $out ) = $run( 'document' );
		self::assertSame( 0, $code, $out );
		$document = json_decode( $out, true );
		self::assertIsArray( $document, 'The handler answered with the metadata document, and nothing else was printed: ' . $out );
		self::assertSame( 'https://example.test', $document['issuer'] );
		self::assertSame( 'https://example.test/?ab_mcp_oauth=authorize', $document['authorization_endpoint'] );
		self::assertStringNotContainsString( 'urlset', $out, 'Not the sitemap.' );
		self::assertStringNotContainsString( 'CHILD-RETURNED', $out, 'The gate did not pass the request on.' );
		self::assertStringNotContainsString( 'CHILD-EXIT', $out, 'Not the password page.' );
		// The sign-in off: nothing serves the document, the password page.
		list( $code, $out ) = $run( 'disabled' );
		self::assertSame( 0, $code, $out );
		self::assertStringContainsString( 'CHILD-EXIT die 401', $out );
		self::assertStringNotContainsString( 'urlset', $out );
	}

	public function testTheSecondRunOfTheGateChangesNothing(): void {
		// With themes the first line passes a request and template_redirect
		// decides the same way again: the constant is defined once, the
		// headers are the same, the decision too.
		$this->on();
		$GLOBALS['ab_test_logged_in'] = true;
		$_SERVER['REQUEST_URI']       = '/about/';
		$wp                           = self::wp( array( 'pagename' => 'about' ) );
		self::assertNull( AB_MCP_Preview_Protection::gate_parse_request( $wp ) );
		self::assertNull( AB_MCP_Preview_Protection::gate_frontend(), 'template_redirect after parse_request: the same answer, no error.' );
		self::assertNull( AB_MCP_Preview_Protection::gate_frontend() );
		$state = AB_MCP_Preview_Protection::state();
		self::assertSame( AB_MCP_Preview_Protection::frontend_decision( AB_MCP_Preview_Protection::frontend_context( true, $wp ), $state, time() ), AB_MCP_Preview_Protection::frontend_decision( AB_MCP_Preview_Protection::frontend_context(), $state, time() ) );
	}

	/* ------------------------------------- admin-ajax.php and admin-post.php */

	/**
	 * A plugin that prints content for visitors over the nopriv ways: «load
	 * more» on admin-ajax.php (and the same action for signed-in users, as
	 * plugins register both), a form's thank-you page on admin-post.php.
	 * Each records what it printed and ends the request as such a handler
	 * does. Returns a reader of what was printed.
	 */
	private static function noprivPlugin(): \Closure {
		$printed = array();
		foreach ( array( 'wp_ajax_nopriv_load_more', 'wp_ajax_load_more' ) as $hook ) {
			add_action(
				$hook,
				static function () use ( &$printed ): void {
					$printed[] = '<article><h2>Secret post</h2><p>Not public yet.</p></article>';
					throw new AbTestExit( 'ajax', 'load_more' );
				}
			);
		}
		foreach ( array( 'admin_post_nopriv_contact_form', 'admin_post_contact_form' ) as $hook ) {
			add_action(
				$hook,
				static function () use ( &$printed ): void {
					$printed[] = 'thank-you page with the theme';
					throw new AbTestExit( 'admin_post', 'contact_form' );
				}
			);
		}
		return static function () use ( &$printed ): array {
			return $printed;
		};
	}

	/**
	 * admin-ajax.php as WordPress runs it: admin_init, then the action, the
	 * nopriv one for a visitor.
	 */
	private static function adminAjax( string $action ): void {
		$GLOBALS['ab_test_is_admin']   = true;
		$GLOBALS['ab_test_doing_ajax'] = true;
		$GLOBALS['pagenow']            = 'admin-ajax.php';
		$_REQUEST['action']            = $action;
		do_action( 'admin_init' );
		do_action( ( is_user_logged_in() ? 'wp_ajax_' : 'wp_ajax_nopriv_' ) . $action );
	}

	/** admin-post.php as WordPress runs it: admin_init, then the action. */
	private static function adminPost( string $action ): void {
		$GLOBALS['ab_test_is_admin']   = true;
		$GLOBALS['ab_test_doing_ajax'] = false;
		$GLOBALS['pagenow']            = 'admin-post.php';
		$_REQUEST['action']            = $action;
		do_action( 'admin_init' );
		do_action( ( is_user_logged_in() ? 'admin_post_' : 'admin_post_nopriv_' ) . $action );
	}

	public function testANoprivAjaxActionOfAPluginPrintsNothingForAVisitor(): void {
		// Plugins and themes print content over wp_ajax_nopriv_*: «load
		// more», endless scrolling, an AJAX search with titles and excerpts,
		// the cart fragments of a shop. The core's own nopriv actions print
		// none, which an earlier header took for all of them.
		$printed = self::noprivPlugin();
		AB_MCP_Preview_Protection::hook();

		// Off: the plugin answers as always.
		try {
			self::adminAjax( 'load_more' );
			self::fail( 'The plugin did not run.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'ajax', $exit->kind );
		}
		self::assertCount( 1, $printed() );

		// On, a visitor: «0» with 401, as admin-ajax.php answers an action
		// nobody handles; the plugin never runs.
		$this->on();
		try {
			self::adminAjax( 'load_more' );
			self::fail( 'Nothing answered.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'die', $exit->kind );
			self::assertSame( '0', $exit->detail, 'No content, not even the password page.' );
			self::assertSame( 401, $GLOBALS['ab_test_die_args']['response'] );
		}
		self::assertCount( 1, $printed(), 'Nothing more was printed.' );

		// Signed in: runs (the AJAX of wp-admin and of a page a member reads).
		$GLOBALS['ab_test_logged_in'] = true;
		try {
			self::adminAjax( 'load_more' );
			self::fail( 'The plugin did not run.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'ajax', $exit->kind );
		}
		$GLOBALS['ab_test_logged_in'] = false;
		// With the cookie: runs (the AJAX a page makes after the password).
		$hash                                         = AB_MCP_Preview_Protection::state()['hash'];
		$_COOKIE[ AB_MCP_Preview_Protection::COOKIE ] = AB_MCP_Preview_Protection::cookie_value( $hash, time() + 100 );
		try {
			self::adminAjax( 'load_more' );
			self::fail( 'The plugin did not run.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'ajax', $exit->kind );
		}
		self::assertCount( 3, $printed() );
		// An expired cookie does not.
		$_COOKIE[ AB_MCP_Preview_Protection::COOKIE ] = AB_MCP_Preview_Protection::cookie_value( $hash, time() - 1 );
		try {
			self::adminAjax( 'load_more' );
			self::fail( 'Nothing answered.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'die', $exit->kind );
			self::assertSame( 401, $GLOBALS['ab_test_die_args']['response'] );
		}
		$_COOKIE = array();

		// The two nopriv actions of the core keep answering a visitor: the
		// heartbeat and the password suggestion of the reset form.
		foreach ( AB_MCP_Preview_Protection::CORE_NOPRIV as $core ) {
			self::adminAjax( $core );
			self::assertSame( 'core', AB_MCP_Preview_Protection::admin_decision( AB_MCP_Preview_Protection::admin_context(), AB_MCP_Preview_Protection::state(), time() )['reason'], $core );
		}
		self::assertSame( array( 'heartbeat', 'generate-password' ), AB_MCP_Preview_Protection::CORE_NOPRIV, 'Exactly the two of admin-ajax.php (measured on the core, 31.10.2026).' );
		self::assertCount( 3, $printed() );
	}

	public function testAdminPostForAVisitorIsThePasswordPageWhoseFormPostsToTheHomePage(): void {
		$printed = self::noprivPlugin();
		AB_MCP_Preview_Protection::hook();
		try {
			self::adminPost( 'contact_form' );
			self::fail( 'The plugin did not run.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'admin_post', $exit->kind, 'Off: as always.' );
		}

		$this->on();
		try {
			self::adminPost( 'contact_form' );
			self::fail( 'Nothing answered.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'die', $exit->kind );
			self::assertSame( 401, $GLOBALS['ab_test_die_args']['response'] );
			self::assertStringContainsString( 'name="' . AB_MCP_Preview_Protection::FIELD . '"', $exit->detail, 'The password page.' );
			self::assertStringContainsString( 'action="https://example.test/"', $exit->detail, 'Its form posts to the home page, where the front-end gate takes the password; this gate takes none.' );
		}
		self::assertCount( 1, $printed() );

		// The names of the core's AJAX actions open nothing here: the clause
		// is for admin-ajax.php.
		try {
			self::adminPost( 'heartbeat' );
			self::fail( 'Nothing answered.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'die', $exit->kind );
		}

		$GLOBALS['ab_test_logged_in'] = true;
		try {
			self::adminPost( 'contact_form' );
			self::fail( 'The plugin did not run.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'admin_post', $exit->kind, 'Signed in: runs.' );
		}
		$GLOBALS['ab_test_logged_in'] = false;
		$_COOKIE[ AB_MCP_Preview_Protection::COOKIE ] = AB_MCP_Preview_Protection::cookie_value( AB_MCP_Preview_Protection::state()['hash'], time() + 100 );
		try {
			self::adminPost( 'contact_form' );
			self::fail( 'The plugin did not run.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'admin_post', $exit->kind, 'With the cookie: runs.' );
		}
		self::assertCount( 3, $printed() );
	}

	public function testThePagesOfWpAdminAreNotThisGatesBusiness(): void {
		// wp-admin sends a visitor to the login itself; the gate decides only
		// while AJAX runs or the script is admin-post.php.
		$this->on();
		$state                       = AB_MCP_Preview_Protection::state();
		$GLOBALS['ab_test_is_admin'] = true;
		foreach ( array( 'index.php', 'admin.php', 'edit.php', 'wp-login.php' ) as $pagenow ) {
			$GLOBALS['pagenow'] = $pagenow;
			$ctx                = AB_MCP_Preview_Protection::admin_context();
			self::assertFalse( $ctx['ajax'] );
			self::assertFalse( $ctx['post'] );
			self::assertSame( array( 'action' => 'pass', 'reason' => 'admin' ), AB_MCP_Preview_Protection::admin_decision( $ctx, $state, time() ), $pagenow );
			self::assertNull( AB_MCP_Preview_Protection::gate_admin(), $pagenow );
		}
		unset( $GLOBALS['pagenow'] );
		self::assertNull( AB_MCP_Preview_Protection::gate_admin(), 'No script name at all.' );
		// Off: nothing, wherever.
		AB_MCP_Preview_Protection::disable( 7, AB_MCP_Preview_Protection::SOURCE_TOOL );
		$GLOBALS['ab_test_doing_ajax'] = true;
		self::assertSame( array( 'action' => 'pass', 'reason' => 'off' ), AB_MCP_Preview_Protection::admin_decision( AB_MCP_Preview_Protection::admin_context(), AB_MCP_Preview_Protection::state(), time() ) );
		self::assertNull( AB_MCP_Preview_Protection::gate_admin() );
	}

	public function testTheAdminContextReadsTheRequest(): void {
		$ctx = AB_MCP_Preview_Protection::admin_context();
		self::assertSame( array( 'ajax' => false, 'post' => false, 'action' => '', 'logged_in' => false, 'cookie' => '' ), $ctx );
		$GLOBALS['ab_test_doing_ajax'] = true;
		$GLOBALS['pagenow']            = 'admin-post.php';
		$_REQUEST['action']            = array( 'heartbeat' );
		$_COOKIE[ AB_MCP_Preview_Protection::COOKIE ] = '1|abc';
		$GLOBALS['ab_test_logged_in']                 = true;
		$ctx                                          = AB_MCP_Preview_Protection::admin_context();
		self::assertTrue( $ctx['ajax'] );
		self::assertTrue( $ctx['post'] );
		self::assertSame( '', $ctx['action'], 'An array is no action name.' );
		self::assertSame( '1|abc', $ctx['cookie'] );
		self::assertTrue( $ctx['logged_in'] );
		$_REQUEST['action'] = 'heartbeat';
		self::assertSame( 'heartbeat', AB_MCP_Preview_Protection::admin_context()['action'] );
	}

	public function testTheBookmarksScriptMeetsTheGateOnWpLoaded(): void {
		// wp-links-opml.php loads WordPress without wp() and prints the
		// bookmarks as OPML; no action of the request but wp_loaded is left.
		$this->on();
		$GLOBALS['pagenow']     = 'wp-login.php';
		$_SERVER['REQUEST_URI'] = '/wp-login.php';
		self::assertNull( AB_MCP_Preview_Protection::gate_script(), 'The login, or any other script, is not this gate\'s business.' );
		$GLOBALS['pagenow']     = 'wp-links-opml.php';
		$_SERVER['REQUEST_URI'] = '/wp-links-opml.php';
		try {
			AB_MCP_Preview_Protection::gate_script();
			self::fail( 'The bookmarks went out.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'die', $exit->kind );
			self::assertSame( 401, $GLOBALS['ab_test_die_args']['response'] );
		}
		$GLOBALS['ab_test_logged_in'] = true;
		self::assertNull( AB_MCP_Preview_Protection::gate_script() );
		unset( $GLOBALS['pagenow'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testEveryAnswerWhileOnIsMarkedNotCacheable(): void {
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_options']['blog_public'] = '1';
		self::assertFalse( defined( 'DONOTCACHEPAGE' ) );
		AB_MCP_Preview_Protection::gate_frontend();
		self::assertFalse( defined( 'DONOTCACHEPAGE' ), 'Off: nothing is marked.' );
		AB_MCP_Preview_Protection::enable( 7, AB_MCP_Preview_Protection::SOURCE_TOOL );
		$GLOBALS['ab_test_logged_in'] = true;
		AB_MCP_Preview_Protection::gate_frontend();
		self::assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE, 'On: also the answer a signed-in user gets.' );
	}

	/**
	 * What the gates sent: the lines of ab_mcp_preview_header_sent and the
	 * arguments of ab_mcp_preview_cookie_sent, which follow the real header()
	 * and setcookie() (PHP's CLI keeps no list of sent headers). Only in a
	 * process of its own, where nothing was printed yet and headers_sent()
	 * is false, as on a web server.
	 *
	 * @return array{headers:string[],cookies:array}
	 */
	private static function sent(): array {
		$out = array( 'headers' => array(), 'cookies' => array() );
		foreach ( $GLOBALS['ab_test_actions'] as list( $hook, $args ) ) {
			if ( 'ab_mcp_preview_header_sent' === $hook ) {
				$out['headers'][] = $args[0];
			} elseif ( 'ab_mcp_preview_cookie_sent' === $hook ) {
				$out['cookies'][] = $args;
			}
		}
		return $out;
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testTheRightPasswordSetsTheCookieForTheHomePathAndTheWordPressFolder(): void {
		// home https://example.test/staging, WordPress in /wordpress: a cookie
		// for /staging/ alone never reaches /wordpress/wp-admin/admin-ajax.php,
		// and the AJAX of an unlocked page would meet gate_admin() without it
		// (Codex, 31.10.2026). As WordPress sets its login cookie: COOKIEPATH
		// and, where it differs, SITECOOKIEPATH.
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_options']['blog_public'] = '1';
		define( 'COOKIEPATH', '/staging/' );
		define( 'SITECOOKIEPATH', '/wordpress/' );
		define( 'COOKIE_DOMAIN', '' );
		self::assertFalse( headers_sent(), 'Nothing printed yet in this process: the gate really sends.' );
		self::assertSame( array( '/staging/', '/wordpress/' ), AB_MCP_Preview_Protection::cookie_paths() );

		$password                  = $this->on();
		$_SERVER['REQUEST_URI']    = '/staging/about/';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST[ AB_MCP_Preview_Protection::FIELD ] = $password;
		$GLOBALS['ab_test_actions']                = array();
		try {
			AB_MCP_Preview_Protection::gate_parse_request( self::wp( array( 'pagename' => 'about' ) ) );
			self::fail( 'No redirect.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'redirect', $exit->kind );
		}
		$cookies = self::sent()['cookies'];
		self::assertCount( 2, $cookies, 'One setcookie() per path.' );
		self::assertSame( '/staging/', $cookies[0][2]['path'] );
		self::assertSame( '/wordpress/', $cookies[1][2]['path'] );
		foreach ( $cookies as $cookie ) {
			self::assertSame( AB_MCP_Preview_Protection::COOKIE, $cookie[0] );
			self::assertSame( $cookies[0][1], $cookie[1], 'The same value.' );
			self::assertTrue( AB_MCP_Preview_Protection::cookie_valid( $cookie[1], AB_MCP_Preview_Protection::state()['hash'], time() ) );
			self::assertSame( $cookies[0][2]['expires'], $cookie[2]['expires'], 'The same expiry.' );
			self::assertGreaterThan( time() + 13 * DAY_IN_SECONDS, $cookie[2]['expires'] );
			self::assertSame( '', $cookie[2]['domain'] );
			self::assertFalse( $cookie[2]['secure'] );
			self::assertTrue( $cookie[2]['httponly'] );
			self::assertSame( 'Lax', $cookie[2]['samesite'] );
		}
		// The reload after the password carries the robots header as well.
		self::assertContains( 'X-Robots-Tag: noindex, nofollow', self::sent()['headers'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testTheCookieIsSetOnceWhenTheTwoPathsAreTheSame(): void {
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_options']['blog_public'] = '1';
		define( 'COOKIEPATH', '/' );
		define( 'SITECOOKIEPATH', '/' );
		self::assertSame( array( '/' ), AB_MCP_Preview_Protection::cookie_paths() );
		$password                  = $this->on();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST[ AB_MCP_Preview_Protection::FIELD ] = $password;
		$GLOBALS['ab_test_actions']                = array();
		try {
			AB_MCP_Preview_Protection::gate_frontend();
			self::fail( 'No redirect.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'redirect', $exit->kind );
		}
		$cookies = self::sent()['cookies'];
		self::assertCount( 1, $cookies, 'The same path twice would be the same cookie twice.' );
		self::assertSame( '/', $cookies[0][2]['path'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testWithoutTheConstantsTheCookiePathIsTheRoot(): void {
		self::assertSame( array( '/' ), AB_MCP_Preview_Protection::cookie_paths() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testEveryAnswerAGateGivesItselfCarriesXRobotsTag(): void {
		// The filter wp_headers runs in WP::send_headers(), after
		// parse_request: the password page, the documents handed to
		// AB_MCP_OAuth, wp-links-opml.php (live measured without the header)
		// and the two admin scripts never reach it, so the gates send the
		// header themselves (Codex, 31.10.2026).
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_options']['blog_public'] = '1';
		self::assertFalse( headers_sent() );
		$line = 'X-Robots-Tag: noindex, nofollow';
		$sent = static function ( callable $gate ): array {
			$GLOBALS['ab_test_actions'] = array();
			try {
				$gate();
			} catch ( AbTestExit $exit ) {
				return self::sent()['headers'];
			}
			return self::sent()['headers'];
		};

		// Off: nothing.
		$_SERVER['REQUEST_URI'] = '/about/';
		self::assertSame( array(), $sent( static fn() => AB_MCP_Preview_Protection::gate_parse_request( self::wp( array( 'pagename' => 'about' ) ) ) ), 'Off: no header of ours.' );

		$this->on();
		// The password page on parse_request.
		$GLOBALS['ab_test_die_args'] = array();
		self::assertSame( array( $line ), $sent( static fn() => AB_MCP_Preview_Protection::gate_parse_request( self::wp( array( 'pagename' => 'about' ) ) ) ) );
		self::assertSame( 401, $GLOBALS['ab_test_die_args']['response'], 'The password page followed the header.' );
		// A pass on parse_request carries it too (WordPress would send the
		// same again through wp_headers; header() replaces).
		$GLOBALS['ab_test_logged_in'] = true;
		self::assertSame( array( $line ), $sent( static fn() => AB_MCP_Preview_Protection::gate_parse_request( self::wp( array( 'pagename' => 'about' ) ) ) ) );
		$GLOBALS['ab_test_logged_in'] = false;
		// The consent page handed to its handler.
		$_GET                      = array( 'ab_mcp_oauth' => 'authorize', 'client_id' => 'https://client.test/client.json' );
		$_REQUEST                  = $_GET;
		$_SERVER['REQUEST_URI']    = '/?ab_mcp_oauth=authorize&client_id=https%3A%2F%2Fclient.test%2Fclient.json';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		self::assertSame( array( $line ), $sent( static fn() => AB_MCP_Preview_Protection::gate_parse_request( self::wp( array() ) ) ) );
		$_GET     = array();
		$_REQUEST = array();
		// wp-links-opml.php on wp_loaded.
		$GLOBALS['pagenow']     = 'wp-links-opml.php';
		$_SERVER['REQUEST_URI'] = '/wp-links-opml.php';
		self::assertSame( array( $line ), $sent( static fn() => AB_MCP_Preview_Protection::gate_script() ) );
		// admin-ajax.php and admin-post.php on admin_init.
		$GLOBALS['ab_test_is_admin']   = true;
		$GLOBALS['ab_test_doing_ajax'] = true;
		$GLOBALS['pagenow']            = 'admin-ajax.php';
		$_REQUEST['action']            = 'load_more';
		self::assertSame( array( $line ), $sent( static fn() => AB_MCP_Preview_Protection::gate_admin() ) );
		self::assertSame( 401, $GLOBALS['ab_test_die_args']['response'] );
		$GLOBALS['ab_test_doing_ajax'] = false;
		$GLOBALS['pagenow']            = 'admin-post.php';
		self::assertSame( array( $line ), $sent( static fn() => AB_MCP_Preview_Protection::gate_admin() ) );
		self::assertSame( 401, $GLOBALS['ab_test_die_args']['response'] );
	}

	public function testXRobotsTagGoesOnEveryAnswerWhileOn(): void {
		self::assertArrayNotHasKey( 'X-Robots-Tag', AB_MCP_Preview_Protection::headers( array() ) );
		$this->on();
		$headers = AB_MCP_Preview_Protection::headers( array( 'X-Pingback' => 'x' ) );
		self::assertSame( 'noindex, nofollow', $headers['X-Robots-Tag'] );
		self::assertSame( 'x', $headers['X-Pingback'], 'What WordPress sends stays.' );
		self::assertSame( array( 'X-Robots-Tag' => 'noindex, nofollow' ), AB_MCP_Preview_Protection::headers( 'not an array' ) );
	}

	public function testTheGatesHangAfterTheOAuthHandlersAndWordPressRestChecks(): void {
		AB_MCP_Preview_Protection::hook();
		// The first line: parse_request at 1, before rest_api_loaded() and
		// the plugins at the default priority 10, after handlers at 0
		// (WooCommerce's wc-api), which answer before the gate as before.
		self::assertSame( 1, has_action( 'parse_request', array( AB_MCP_Preview_Protection::class, 'gate_parse_request' ) ) );
		self::assertSame( 1, AB_MCP_Preview_Protection::PARSE_PRIORITY );
		self::assertLessThan( 10, AB_MCP_Preview_Protection::PARSE_PRIORITY, 'Before rest_api_loaded() and the SEO plugins on parse_request.' );
		self::assertGreaterThan( 0, AB_MCP_Preview_Protection::PARSE_PRIORITY, 'After handlers at 0.' );
		// The second line stays.
		self::assertSame( 1, has_action( 'template_redirect', array( AB_MCP_Preview_Protection::class, 'gate_frontend' ) ) );
		// admin-ajax.php and admin-post.php: admin_init at 1, after a handler
		// at 0, before the plugins at the default priority 10.
		self::assertSame( 1, has_action( 'admin_init', array( AB_MCP_Preview_Protection::class, 'gate_admin' ) ) );
		self::assertSame( 1, AB_MCP_Preview_Protection::ADMIN_PRIORITY );
		self::assertGreaterThan( 0, AB_MCP_Preview_Protection::TEMPLATE_PRIORITY, 'After the well-known documents and the consent page (priority 0).' );
		self::assertLessThan( 10, AB_MCP_Preview_Protection::TEMPLATE_PRIORITY, 'Before redirect_canonical.' );
		self::assertSame( 110, has_filter( 'rest_authentication_errors', array( AB_MCP_Preview_Protection::class, 'gate_rest' ) ) );
		self::assertGreaterThan( 100, AB_MCP_Preview_Protection::REST_PRIORITY, 'After rest_cookie_check_errors (100), where is_user_logged_in() is settled.' );
		self::assertSame( 10, has_filter( 'wp_headers', array( AB_MCP_Preview_Protection::class, 'headers' ) ) );
		self::assertSame( 10, has_action( 'wp', array( AB_MCP_Preview_Protection::class, 'gate_without_themes' ) ), 'The entry points without themes.' );
		self::assertSame( 10, has_action( 'wp_loaded', array( AB_MCP_Preview_Protection::class, 'gate_script' ) ), 'wp-links-opml.php.' );

		AB_MCP_OAuth::boot();
		self::assertSame( 0, has_action( 'template_redirect', array( AB_MCP_OAuth::class, 'serve_well_known' ) ) );
		self::assertSame( 0, has_action( 'template_redirect', array( AB_MCP_OAuth::class, 'handle_authorize' ) ) );
	}

	/* -------------------------------------------------------------- REST */

	public function testTheRestGatePassesAnErrorOfWordPressThrough(): void {
		$this->on();
		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
		$error = new WP_Error( 'rest_cookie_invalid_nonce', 'Cookie check failed', array( 'status' => 403 ) );
		self::assertSame( $error, AB_MCP_Preview_Protection::gate_rest( $error ) );
	}

	public function testTheRestGatePassesASignedInUser(): void {
		$this->on();
		$_SERVER['REQUEST_URI']       = '/wp-json/wp/v2/posts';
		$GLOBALS['ab_test_logged_in'] = true;
		self::assertTrue( AB_MCP_Preview_Protection::gate_rest( true ), 'Gutenberg with cookie and nonce, or an application password.' );
		self::assertNull( AB_MCP_Preview_Protection::gate_rest( null ) );
	}

	/** @return array<string,array{0:string}> */
	public static function openRoutes(): array {
		return array(
			'MCP endpoint'                 => array( '/wp-json/alphabridge/v1/mcp' ),
			'MCP endpoint, token in path'  => array( '/wp-json/alphabridge/v1/mcp/abmcp_0123456789abcdef' ),
			'OAuth register'               => array( '/wp-json/alphabridge/v1/oauth/register' ),
			'OAuth token'                  => array( '/wp-json/alphabridge/v1/oauth/token' ),
			'OAuth revoke'                 => array( '/wp-json/alphabridge/v1/oauth/revoke' ),
			'REST root'                    => array( '/wp-json/' ),
			'REST root without slash'      => array( '/wp-json' ),
			'REST root as the hub asks it' => array( '/wp-json/?_fields=namespaces' ),
		);
	}

	#[DataProvider( 'openRoutes' )]
	public function testTheRestGateLeavesOpenWhatTheHubAndTheClientsNeed( string $uri ): void {
		$this->on();
		$_SERVER['REQUEST_URI'] = $uri;
		self::assertNull( AB_MCP_Preview_Protection::gate_rest( null ), $uri );
		self::assertTrue( AB_MCP_Preview_Protection::gate_rest( true ), $uri );
	}

	/** @return array<string,array{0:string}> */
	public static function closedRoutes(): array {
		return array(
			'posts'              => array( '/wp-json/wp/v2/posts' ),
			'a namespace index'  => array( '/wp-json/wp/v2' ),
			'users'              => array( '/wp-json/wp/v2/users?per_page=100' ),
			'oembed'             => array( '/wp-json/oembed/1.0/embed?url=https%3A%2F%2Fexample.test%2Fhello%2F' ),
			'Store API'          => array( '/wp-json/wc/store/v1/products' ),
			'a look-alike'       => array( '/wp-json/alphabridge/v10/mcp' ),
			'another look-alike' => array( '/wp-json/alphabridge/v1x/mcp' ),
		);
	}

	#[DataProvider( 'closedRoutes' )]
	public function testTheRestGateAnswersEveryOtherNamespace401ToAVisitor( string $uri ): void {
		$this->on();
		$_SERVER['REQUEST_URI'] = $uri;
		$result = AB_MCP_Preview_Protection::gate_rest( true );
		self::assertInstanceOf( WP_Error::class, $result, $uri );
		self::assertSame( 'ab_mcp_preview_protected', $result->get_error_code() );
		self::assertSame( 401, $result->get_error_data()['status'] );
		self::assertStringContainsString( 'preview behind a password', $result->get_error_message() );
		self::assertInstanceOf( WP_Error::class, AB_MCP_Preview_Protection::gate_rest( null ), 'Also when no filter before decided.' );
	}

	public function testTheRestGatePassesAVisitorWithAValidCookie(): void {
		$this->on();
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/cart';
		$hash                   = AB_MCP_Preview_Protection::state()['hash'];
		$_COOKIE[ AB_MCP_Preview_Protection::COOKIE ] = AB_MCP_Preview_Protection::cookie_value( $hash, time() + 100 );
		self::assertTrue( AB_MCP_Preview_Protection::gate_rest( true ), 'The shop of a visitor who entered the password works.' );
		$_COOKIE[ AB_MCP_Preview_Protection::COOKIE ] = AB_MCP_Preview_Protection::cookie_value( $hash, time() - 1 );
		self::assertInstanceOf( WP_Error::class, AB_MCP_Preview_Protection::gate_rest( true ), 'An expired cookie does not.' );
	}

	public function testTheRouteIsReadFromTheQueryVarOrThePath(): void {
		self::assertSame( '/alphabridge/v1/mcp', AB_MCP_Preview_Protection::rest_route( array( 'REQUEST_URI' => '/index.php?rest_route=/alphabridge/v1/mcp' ), array( 'rest_route' => '/alphabridge/v1/mcp' ) ), 'Plain permalinks.' );
		self::assertSame( '/', AB_MCP_Preview_Protection::rest_route( array( 'REQUEST_URI' => '/?rest_route=/' ), array( 'rest_route' => '/' ) ) );
		self::assertSame( '/wp/v2/posts', AB_MCP_Preview_Protection::rest_route( array( 'REQUEST_URI' => '/wp-json/wp/v2/posts/?x=1' ), array() ), 'Pretty permalinks, from the path.' );
		self::assertSame( '/', AB_MCP_Preview_Protection::rest_route( array( 'REQUEST_URI' => '/wp-json' ), array() ) );
		self::assertSame( '/', AB_MCP_Preview_Protection::rest_route( array( 'REQUEST_URI' => '/wp-json/?_fields=namespaces' ), array() ) );
		self::assertSame( '/', AB_MCP_Preview_Protection::rest_route( array( 'REQUEST_URI' => '/not-rest/wp-json/wp/v2' ), array() ), 'Not the REST prefix: no route, which reads as the root and is open; the route only decides between open and closed, the request is no REST request anyway.' );
		self::assertSame( '/alphabridge/v1/mcp', AB_MCP_Preview_Protection::rest_route( array( 'REQUEST_URI' => '/wp-json/wp/v2/posts' ), array( 'rest_route' => '/alphabridge/v1/mcp' ) ), 'The query var WordPress resolved wins.' );
	}

	public function testTheRestGateReadsTheRouteWordPressResolved(): void {
		$this->on();
		$_SERVER['REQUEST_URI'] = '/index.php?rest_route=%2Fwp%2Fv2%2Fposts';
		$_GET['rest_route']     = '/wp/v2/posts';
		self::assertInstanceOf( WP_Error::class, AB_MCP_Preview_Protection::gate_rest( true ), 'Plain permalinks: the query string.' );
		$_GET['rest_route'] = '/alphabridge/v1/mcp';
		self::assertTrue( AB_MCP_Preview_Protection::gate_rest( true ) );
		$_GET = array();
		// Pretty permalinks: the rewrite rule sets the query var, and
		// WordPress serves the route it names (rest_api_loaded()), whatever
		// the path looks like; so the query var decides here too.
		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
		$GLOBALS['wp']          = (object) array( 'query_vars' => array( 'rest_route' => '/alphabridge/v1/oauth/token' ) );
		self::assertTrue( AB_MCP_Preview_Protection::gate_rest( true ), 'Pretty permalinks: the query var of the rewrite rule.' );
		$_SERVER['REQUEST_URI'] = '/wp-json/';
		$GLOBALS['wp']          = (object) array( 'query_vars' => array( 'rest_route' => '/wp/v2/posts' ) );
		self::assertInstanceOf( WP_Error::class, AB_MCP_Preview_Protection::gate_rest( true ), 'And a closed route named there is closed, whatever the path.' );
	}

	public function testWhichRoutesAreOpen(): void {
		foreach ( array( '/', '', '/alphabridge/v1', '/alphabridge/v1/', '/alphabridge/v1/mcp', '/alphabridge/v1/mcp/abc', '/alphabridge/v1/oauth/register', '/alphabridge/v1/staging-pair' ) as $route ) {
			self::assertTrue( AB_MCP_Preview_Protection::rest_exempt( $route ), $route );
		}
		// WordPress's router matches a route without regard to case
		// (WP_REST_Server::match_request_to_handler(), the i flag), so these
		// reach the MCP endpoint and its token check, and are open.
		foreach ( array( '/AlphaBridge/v1/mcp', '/ALPHABRIDGE/V1/MCP', '/alphabridge/V1/oauth/token' ) as $route ) {
			self::assertTrue( AB_MCP_Preview_Protection::rest_exempt( $route ), $route );
		}
		$this->on();
		$_SERVER['REQUEST_URI'] = '/wp-json/AlphaBridge/v1/mcp';
		self::assertTrue( AB_MCP_Preview_Protection::gate_rest( true ), 'A client that spells the namespace with capitals.' );
		foreach ( array( '/wp/v2', '/wp/v2/posts', '/oembed/1.0/embed', '/alphabridge/v10', '/alphabridge/v1x/mcp', '/alphabridge', '/x/alphabridge/v1/mcp' ) as $route ) {
			self::assertFalse( AB_MCP_Preview_Protection::rest_exempt( $route ), $route );
		}
	}

	/** The index as WordPress builds it, with what ?_embed would turn into media records. */
	private function fullIndex(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'name'           => 'Staging of a client',
				'description'    => 'Not public yet',
				'url'            => 'https://example.test',
				'home'           => 'https://example.test',
				'gmt_offset'     => 1,
				'timezone_string' => 'Europe/Zurich',
				'namespaces'     => array( 'oembed/1.0', 'wp/v2', 'wp-site-health/v1', 'alphabridge/v1' ),
				'authentication' => array( 'application-passwords' => array( 'endpoints' => array( 'authorization' => 'https://example.test/wp-admin/authorize-application.php' ) ) ),
				'routes'         => array( '/' => array(), '/wp/v2/posts' => array() ),
				'site_logo'      => 12,
				'site_icon'      => 13,
				'site_icon_url'  => 'https://example.test/wp-content/uploads/icon.png',
				'_links'         => array( 'https://api.w.org/featuredmedia' => array( array( 'href' => 'https://example.test/wp-json/wp/v2/media/12', 'embeddable' => true ) ) ),
			),
			200
		);
	}

	public function testTheRestIndexForAVisitorHoldsTheNamespacesAlone(): void {
		$full = $this->fullIndex();
		self::assertSame( $full, AB_MCP_Preview_Protection::index( $full ), 'Off: as WordPress built it.' );

		$this->on();
		$reduced = AB_MCP_Preview_Protection::index( $full, new \WP_REST_Request( 'GET', '/' ) );
		self::assertNotSame( $full, $reduced );
		self::assertSame( array( 'namespaces' => array( 'oembed/1.0', 'wp/v2', 'wp-site-health/v1', 'alphabridge/v1' ) ), $reduced->get_data(), 'What the hub reads (alphabridge-connect src/detect.ts, hasWordPressRest()), nothing else: no name, no routes, no site icon, no links to embed.' );
		self::assertSame( 200, $reduced->get_status() );
		self::assertContains( 'wp/v2', $reduced->get_data()['namespaces'], 'The hub looks for wp/v2 among them.' );

		$GLOBALS['ab_test_logged_in'] = true;
		self::assertSame( $full, AB_MCP_Preview_Protection::index( $full ), 'Signed in: the full index.' );
		$GLOBALS['ab_test_logged_in'] = false;
		$_COOKIE[ AB_MCP_Preview_Protection::COOKIE ] = AB_MCP_Preview_Protection::cookie_value( AB_MCP_Preview_Protection::state()['hash'], time() + 100 );
		self::assertSame( $full, AB_MCP_Preview_Protection::index( $full ), 'With the cookie: the full index.' );
		$_COOKIE[ AB_MCP_Preview_Protection::COOKIE ] = AB_MCP_Preview_Protection::cookie_value( AB_MCP_Preview_Protection::state()['hash'], time() - 1 );
		self::assertSame( array( 'namespaces' ), array_keys( AB_MCP_Preview_Protection::index( $full )->get_data() ), 'An expired cookie: the namespaces.' );

		$odd = new \WP_REST_Response( 'not an index', 200 );
		self::assertSame( array( 'namespaces' => array() ), AB_MCP_Preview_Protection::index( $odd )->get_data() );
		self::assertSame( 'no response', AB_MCP_Preview_Protection::index( 'no response' ) );
	}

	public function testEveryRestAnswerWhileOnCarriesTheNoCacheHeaders(): void {
		self::assertFalse( AB_MCP_Preview_Protection::nocache( false ), 'Off: as WordPress decided (signed-in users only).' );
		self::assertTrue( AB_MCP_Preview_Protection::nocache( true ) );
		$this->on();
		self::assertTrue( AB_MCP_Preview_Protection::nocache( false ), 'On: also the answer a visitor with the cookie gets.' );
		self::assertTrue( AB_MCP_Preview_Protection::nocache( true ) );
		AB_MCP_Preview_Protection::hook();
		self::assertSame( 10, has_filter( 'rest_send_nocache_headers', array( AB_MCP_Preview_Protection::class, 'nocache' ) ) );
		self::assertSame( 10, has_filter( 'rest_index', array( AB_MCP_Preview_Protection::class, 'index' ) ) );
	}

	/* ------------------------------------------------- the connection check */

	public function testTheConnectionCheckAsksOnlyAddressesTheProtectionLeavesOpen(): void {
		$this->on();
		$state = AB_MCP_Preview_Protection::state();
		$GLOBALS['ab_test_http_answer'] = static function ( string $url ): array {
			return ab_test_http_response( 401, '{"code":"ab_mcp_unauthorized"}', array( 'WWW-Authenticate' => 'Bearer' ) );
		};
		AB_MCP_Connection_Doctor::run();
		$urls = array_column( $GLOBALS['ab_test_http'], 'url' );
		self::assertGreaterThanOrEqual( 4, count( $urls ), 'The endpoint twice, the two well-known documents, the consent page.' );
		foreach ( $urls as $url ) {
			$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
			$query = array();
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
			if ( 0 === strpos( $path, '/wp-json' ) ) {
				$route = AB_MCP_Preview_Protection::rest_route( array( 'REQUEST_URI' => $path ), array() );
				self::assertTrue( AB_MCP_Preview_Protection::rest_exempt( $route ), $url );
				continue;
			}
			if ( isset( $query['ab_mcp_oauth'] ) ) {
				// The firewall probe calls the consent page: the OAuth handler
				// at priority 0 answers it and ends the request, before the gate.
				self::assertSame( 'authorize', $query['ab_mcp_oauth'] );
				$_GET                      = $query;
				$_REQUEST                  = $query;
				$_SERVER['REQUEST_METHOD'] = 'GET';
				try {
					AB_MCP_OAuth::handle_authorize();
					self::fail( 'handle_authorize() did not end the request for ' . $url );
				} catch ( AbTestExit $exit ) {
					self::assertContains( $exit->kind, array( 'login', 'redirect', 'die' ), $url );
				}
				$_GET     = array();
				$_REQUEST = array();
				continue;
			}
			// The two documents below /.well-known/: the gate hands them to
			// the handler that serves them.
			$ctx = $this->visitor( array( 'path' => $path, 'request_uri' => $url, 'well_known' => AB_MCP_OAuth::enabled() && '' !== AB_MCP_OAuth::well_known_document( $url ) ) );
			self::assertSame( array( 'action' => 'serve', 'reason' => 'well_known' ), AB_MCP_Preview_Protection::frontend_decision( $ctx, $state, time() ), $url );
		}
	}

	/* -------------------------------------------------------------- tool */

	private function registry(): AB_MCP_Tool_Registry {
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'staging', 'Staging' );
		AB_MCP_Tools_Staging::register( $r );
		return $r;
	}

	public function testTheToolNeedsManageOptionsAndIsNotDestructive(): void {
		$def = $this->registry()->get( 'wp_set_preview_protection' );
		self::assertIsArray( $def );
		self::assertSame( 'manage_options', $def['capability'] );
		self::assertSame( 'staging', $def['group'] );
		self::assertFalse( AB_MCP_Tool_Registry::is_read_only( 'wp_set_preview_protection', $def ), 'It writes, so write access is needed and a read-only connection cannot call it.' );
		self::assertFalse( AB_MCP_Tool_Registry::is_destructive( 'wp_set_preview_protection', $def ), 'The same call takes the switch back; nothing is overwritten.' );
		self::assertFalse( AB_MCP_Tool_Registry::is_mighty( $def ) );
		self::assertFalse( AB_MCP_Site_Mode::runs_in_read( 'wp_set_preview_protection', $def ) );
		self::assertSame( 'Switch the preview protection on or off', AB_MCP_Tool_Labels::english()['wp_set_preview_protection'] );
		self::assertSame( array( 'enabled', 'new_password' ), array_keys( $def['inputSchema']['properties'] ) );
		foreach ( array( '/wp-content/uploads/', 'Cloudflare APO', 'identifies nobody', 'only its hash is stored', '/wp-json/', '/.well-known/', 'application passwords', 'returned once' ) as $said ) {
			self::assertStringContainsString( $said, $def['description'], $said );
		}
		self::assertStringNotContainsString( 'Pro', $def['description'] );
	}

	public function testTheToolIsRefusedWithoutTheCapabilityAndWithWriteAccessOff(): void {
		$def = $this->registry()->get( 'wp_set_preview_protection' );
		self::scope( 'full' );
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' !== $cap;
		AB_MCP_Settings::set( AB_MCP_Site_Mode::KEY, AB_MCP_Site_Mode::FULL );
		$refused = AB_MCP_Security::authorize( 'wp_set_preview_protection', $def );
		self::assertInstanceOf( WP_Error::class, $refused );
		self::assertSame( 'ab_mcp_forbidden', $refused->get_error_code() );

		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		AB_MCP_Settings::set( AB_MCP_Site_Mode::KEY, AB_MCP_Site_Mode::READ );
		$refused = AB_MCP_Security::authorize( 'wp_set_preview_protection', $def );
		self::assertInstanceOf( WP_Error::class, $refused, 'With write access off the switch is not an assistant\'s to flip.' );

		AB_MCP_Settings::set( AB_MCP_Site_Mode::KEY, AB_MCP_Site_Mode::FULL );
		self::scope( 'content' );
		$refused = AB_MCP_Security::authorize( 'wp_set_preview_protection', $def );
		self::assertInstanceOf( WP_Error::class, $refused, 'A content connection cannot flip it either: the capability is not a content capability.' );
		self::assertSame( 'ab_mcp_scope', $refused->get_error_code() );

		self::scope( 'full' );
		self::assertTrue( AB_MCP_Security::authorize( 'wp_set_preview_protection', $def ) );
		self::assertFalse( AB_MCP_Preview_Protection::is_on(), 'Authorizing switches nothing.' );
	}

	public function testTheToolSwitchesOnAndReturnsThePasswordExactlyOnce(): void {
		$out = AB_MCP_Tools_Staging::set_preview_protection( array( 'enabled' => true ) );
		self::assertIsArray( $out );
		self::assertTrue( $out['enabled'] );
		self::assertTrue( $out['changed'] );
		self::assertSame( AB_MCP_Preview_Protection::PASSWORD_LENGTH, strlen( $out['password'] ) );
		self::assertSame( 1, substr_count( json_encode( $out ), $out['password'] ), 'The password stands once in the answer.' );
		self::assertStringContainsString( 'Shown this once', $out['password_note'] );
		self::assertSame( '0', $out['blog_public'] );
		self::assertSame( '1', $out['blog_public_before'] );
		self::assertSame( array( 'user_id' => 7, 'user_login' => 'user7', 'source' => 'tool' ), $out['set_by'] );
		self::assertSame( array( 'breeze' ), $out['page_cache']['purged'] );
		self::assertCount( 3, $out['not_covered'] );
		self::assertStringContainsString( '/wp-content/uploads/', $out['not_covered'][0] );
		self::assertStringContainsString( 'Caches outside PHP', $out['not_covered'][1] );
		self::assertStringContainsString( 'identifies nobody', $out['not_covered'][2] );
		self::assertTrue( AB_MCP_Preview_Protection::verify_password( $out['password'], AB_MCP_Preview_Protection::state()['hash'] ) );
		self::assertStringNotContainsString( $out['password'], $this->stored() );

		$again = AB_MCP_Tools_Staging::set_preview_protection( array( 'enabled' => true ) );
		self::assertFalse( $again['changed'] );
		self::assertArrayNotHasKey( 'password', $again, 'Not shown a second time.' );
		self::assertStringContainsString( 'not shown again', $again['password_note'] );
		self::assertArrayNotHasKey( 'page_cache', $again, 'Nothing was purged.' );

		$status = AB_MCP_Tools_Staging::set_preview_protection( array() );
		self::assertTrue( $status['enabled'] );
		self::assertFalse( $status['changed'] );
		self::assertArrayNotHasKey( 'password', $status );
	}

	public function testTheToolRenewsThePasswordAndSwitchesOff(): void {
		$first = AB_MCP_Tools_Staging::set_preview_protection( array( 'enabled' => true ) )['password'];
		$new   = AB_MCP_Tools_Staging::set_preview_protection( array( 'new_password' => true ) );
		self::assertTrue( $new['changed'] );
		self::assertNotSame( $first, $new['password'] );
		self::assertTrue( AB_MCP_Preview_Protection::verify_password( $new['password'], AB_MCP_Preview_Protection::state()['hash'] ) );
		$both = AB_MCP_Tools_Staging::set_preview_protection( array( 'enabled' => true, 'new_password' => true ) );
		self::assertTrue( $both['changed'], 'enabled=true while on with new_password renews.' );
		self::assertNotSame( $new['password'], $both['password'] );

		$off = AB_MCP_Tools_Staging::set_preview_protection( array( 'enabled' => false ) );
		self::assertFalse( $off['enabled'] );
		self::assertTrue( $off['changed'] );
		self::assertArrayNotHasKey( 'password', $off );
		self::assertSame( '1', $off['blog_public'], 'Written back.' );
		self::assertNull( $off['blog_public_before'] );
		self::assertNull( $off['set_by'] );
		self::assertSame( array( 'breeze' ), $off['page_cache']['purged'] );

		$still = AB_MCP_Tools_Staging::set_preview_protection( array( 'enabled' => false ) );
		self::assertFalse( $still['changed'] );
		self::assertArrayNotHasKey( 'page_cache', $still );
		self::assertSame( array( 'enabled' => false, 'changed' => false ), array_intersect_key( $still, array( 'enabled' => 1, 'changed' => 1 ) ) );
	}

	public function testTheToolRefusesToRenewWhileOffWithTheWayToSwitchOn(): void {
		$result = AB_MCP_Tools_Staging::set_preview_protection( array( 'new_password' => true ) );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'ab_mcp_preview_off', $result->get_error_code() );
		$message = $result->get_error_message();
		self::assertStringContainsString( 'The preview protection is off, so there is no password to renew.', $message );
		self::assertStringContainsString( '1. Switch it on with enabled=true', $message );
		self::assertStringContainsString( 'Direct link: ' . AB_MCP_Guidance::settings_url( 'ab-preview' ), $message );
		self::assertStringContainsString( AB_MCP_Guidance::HANDOVER, $message );
		self::assertFalse( AB_MCP_Preview_Protection::is_on() );
	}

	public function testTheToolTakesTheBooleansAsTheOtherToolsDo(): void {
		$out = AB_MCP_Tools_Staging::set_preview_protection( array( 'enabled' => 'true' ) );
		self::assertTrue( $out['enabled'] );
		$out = AB_MCP_Tools_Staging::set_preview_protection( array( 'enabled' => 'false' ) );
		self::assertFalse( $out['enabled'] );
		self::assertTrue( $out['changed'] );
	}

	public function testTheStateLivesInTheOptionUninstallRemoves(): void {
		$this->on();
		self::assertArrayHasKey( AB_MCP_Preview_Protection::KEY, $GLOBALS['ab_test_options'][ AB_MCP_Settings::OPT_OPTIONS ] );
		self::assertStringContainsString( "'" . AB_MCP_Settings::OPT_OPTIONS . "',", (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' ) );
	}

	/* ---------------------------------------- deactivation, activation, uninstall */

	public function testDeactivatingWritesTheVisibilityBackAndKeepsTheStateActivatingTakesItAgain(): void {
		AB_MCP_Preview_Protection::on_deactivate();
		AB_MCP_Preview_Protection::on_activate();
		self::assertSame( '1', $GLOBALS['ab_test_options']['blog_public'], 'Off: nothing happens.' );
		self::assertSame( 0, $GLOBALS['ab_test_purges'] );

		$password = $this->on();
		$hash     = AB_MCP_Preview_Protection::state()['hash'];
		AB_MCP_Preview_Protection::on_deactivate();
		self::assertSame( '1', $GLOBALS['ab_test_options']['blog_public'], 'The gates go with the plugin, so the visibility goes back.' );
		$state = AB_MCP_Preview_Protection::state();
		self::assertTrue( $state['on'], 'The state stays for the next activation.' );
		self::assertSame( $hash, $state['hash'], 'With the password.' );
		self::assertSame( 2, $GLOBALS['ab_test_purges'], 'No password page stays in a cache.' );

		AB_MCP_Preview_Protection::on_activate();
		self::assertSame( 0, $GLOBALS['ab_test_options']['blog_public'], 'Activated again: discouraged again.' );
		self::assertSame( '1', AB_MCP_Preview_Protection::state()['blog_public'] );
		self::assertTrue( AB_MCP_Preview_Protection::verify_password( $password, AB_MCP_Preview_Protection::state()['hash'] ), 'The same password.' );
		self::assertSame( 3, $GLOBALS['ab_test_purges'], 'Pages cached while off go.' );

		// Deactivation noted that it wrote the value back (restored), so
		// whatever stands there on activation was set while the plugin was
		// off: a 0 too is a choice, and switching off later leaves it.
		AB_MCP_Preview_Protection::on_deactivate();
		self::assertTrue( AB_MCP_Preview_Protection::state()['restored'] );
		$GLOBALS['ab_test_options']['blog_public'] = 0;
		AB_MCP_Preview_Protection::on_activate();
		self::assertSame( '0', AB_MCP_Preview_Protection::state()['blog_public'], 'Set by hand while deactivated: remembered.' );
		self::assertFalse( AB_MCP_Preview_Protection::state()['restored'], 'The note is used up.' );
		self::assertSame( 0, $GLOBALS['ab_test_options']['blog_public'] );
		AB_MCP_Preview_Protection::disable( 7, AB_MCP_Preview_Protection::SOURCE_TOOL );
		self::assertSame( '0', $GLOBALS['ab_test_options']['blog_public'], 'Switching off leaves the choice.' );
	}

	public function testWithoutTheNoteAZeroOnActivationIsThePluginsOwn(): void {
		// A deactivation whose hook never ran (the folder replaced, the
		// plugin switched off by hand): blog_public is still the 0 this
		// plugin set, no note was written, so the remembered value stays.
		$this->on();
		self::assertFalse( AB_MCP_Preview_Protection::state()['restored'] );
		self::assertSame( 0, $GLOBALS['ab_test_options']['blog_public'] );
		AB_MCP_Preview_Protection::on_activate();
		self::assertSame( '1', AB_MCP_Preview_Protection::state()['blog_public'], 'The 0 is not remembered.' );
		// And a value other than 0 found without the note is remembered: it
		// cannot be this plugin's.
		$GLOBALS['ab_test_options']['blog_public'] = '1';
		AB_MCP_Settings::set( AB_MCP_Preview_Protection::KEY, array_merge( AB_MCP_Settings::get( AB_MCP_Preview_Protection::KEY ), array( 'blog_public' => '0' ) ) );
		AB_MCP_Preview_Protection::on_activate();
		self::assertSame( '1', AB_MCP_Preview_Protection::state()['blog_public'] );
		self::assertSame( 0, $GLOBALS['ab_test_options']['blog_public'] );
	}

	public function testNetworkWideDeactivationAndActivationReachEverySite(): void {
		$GLOBALS['ab_test_multisite'] = true;
		// More sites than get_sites() lists by default (100): the loop must
		// ask for every site, as uninstall.php does.
		$GLOBALS['ab_test_sites'] = range( 1, 101 );
		foreach ( array( 1, 101 ) as $site ) {
			switch_to_blog( $site );
			ab_test_add_user( 7 );
			$GLOBALS['ab_test_options']['blog_public'] = '1';
			AB_MCP_Preview_Protection::enable( 7, AB_MCP_Preview_Protection::SOURCE_TOOL );
			self::assertSame( 0, $GLOBALS['ab_test_options']['blog_public'] );
			restore_current_blog();
		}
		switch_to_blog( 2 );
		$GLOBALS['ab_test_options']['blog_public'] = '1';
		restore_current_blog();

		AB_MCP_Preview_Protection::on_deactivate( true );
		foreach ( array( 1, 101 ) as $site ) {
			switch_to_blog( $site );
			self::assertSame( '1', $GLOBALS['ab_test_options']['blog_public'], 'Site ' . $site . ': written back.' );
			self::assertTrue( AB_MCP_Preview_Protection::state()['on'] );
			restore_current_blog();
		}
		switch_to_blog( 2 );
		self::assertSame( '1', $GLOBALS['ab_test_options']['blog_public'], 'A site without the protection is left alone.' );
		self::assertFalse( AB_MCP_Preview_Protection::is_on() );
		restore_current_blog();

		AB_MCP_Preview_Protection::on_activate( true );
		foreach ( array( 1, 101 ) as $site ) {
			switch_to_blog( $site );
			self::assertSame( 0, $GLOBALS['ab_test_options']['blog_public'], 'Site ' . $site . ': discouraged again.' );
			restore_current_blog();
		}
		self::assertSame( 1, $GLOBALS['ab_test_blog'], 'Back on the site it started from.' );

		// Not for the network: the current site only.
		switch_to_blog( 101 );
		AB_MCP_Preview_Protection::on_deactivate( false );
		self::assertSame( '1', $GLOBALS['ab_test_options']['blog_public'] );
		restore_current_blog();
		switch_to_blog( 1 );
		self::assertSame( 0, $GLOBALS['ab_test_options']['blog_public'], 'Site 1 untouched.' );
		restore_current_blog();
	}

	public function testUninstallingWritesTheVisibilityBackBeforeTheOptionGoes(): void {
		self::uninstallConstants();
		$this->on();
		self::assertSame( 0, $GLOBALS['ab_test_options']['blog_public'] );
		include dirname( __DIR__ ) . '/uninstall.php';
		self::assertSame( '1', $GLOBALS['ab_test_options']['blog_public'], 'Written back from the remembered value.' );
		self::assertArrayNotHasKey( AB_MCP_Settings::OPT_OPTIONS, $GLOBALS['ab_test_options'] );

		// Off: the visibility is the site's own and stays.
		$GLOBALS['ab_test_options']['blog_public'] = 0;
		AB_MCP_Settings::set( AB_MCP_Preview_Protection::KEY, array( 'on' => false, 'blog_public' => '1' ) );
		include dirname( __DIR__ ) . '/uninstall.php';
		self::assertSame( 0, $GLOBALS['ab_test_options']['blog_public'] );
	}

	/* ------------------------------------------------------------- loader */

	public function testTheLoaderIsWrittenWithTheProtectionAndRemovedWithIt(): void {
		$path = AB_MCP_Preview_Protection::loader_path();
		self::assertSame( WPMU_PLUGIN_DIR . '/alphabridge-preview-protection.php', $path );
		self::assertFileDoesNotExist( $path );

		$on = AB_MCP_Preview_Protection::enable( 7, AB_MCP_Preview_Protection::SOURCE_TOOL );
		self::assertSame( array( 'path' => $path, 'written' => true ), $on['loader'] );
		self::assertFileExists( $path );
		$source = (string) file_get_contents( $path );
		self::assertSame( AB_MCP_Preview_Protection::loader_source(), $source );
		self::assertStringStartsWith( "<?php\n/**\n * Plugin Name: AlphaBridge MCP – Preview Protection Loader", $source, 'Explained under Must-Use in wp-admin.' );
		self::assertStringContainsString( ' * Version: ' . AB_MCP_VERSION . "\n", $source );
		self::assertStringContainsString( '// ab-mcp-preview-loader ' . AB_MCP_Preview_Protection::LOADER_VERSION . ' ' . AB_MCP_VERSION . "\n", $source, 'The version mark.' );
		self::assertStringContainsString( "wp_installing()", $source );
		self::assertStringContainsString( "'alphabridge-mcp'", $source, 'The folder of the plugin that carries the core.' );
		self::assertStringContainsString( "'plugins_loaded'", $source, 'After the network plugins.' );
		self::assertSame( 3, substr_count( $source, 'class_exists(' ), 'Each file only where its class is not there, and hook() only with the class.' );
		self::assertTrue( AB_MCP_Preview_Protection::loader_current() );
		self::assertSame( array( 'path' => $path, 'written' => true ), AB_MCP_Preview_Protection::summary()['loader'] );

		AB_MCP_Preview_Protection::renew_password( 7, AB_MCP_Preview_Protection::SOURCE_TOOL );
		self::assertFileExists( $path, 'A new password changes nothing about the loader.' );

		AB_MCP_Preview_Protection::disable( 7, AB_MCP_Preview_Protection::SOURCE_TOOL );
		self::assertFileDoesNotExist( $path, 'Off: removed.' );
		self::assertNull( AB_MCP_Preview_Protection::summary()['loader'] );

		$this->on();
		self::assertFileExists( $path );
		AB_MCP_Preview_Protection::on_deactivate();
		self::assertFileDoesNotExist( $path, 'Deactivated: removed, the state kept.' );
		self::assertTrue( AB_MCP_Preview_Protection::is_on() );
		AB_MCP_Preview_Protection::on_activate();
		self::assertFileExists( $path, 'Activated again: written again.' );

		// A loader of an earlier version is replaced when the plugin loads.
		file_put_contents( $path, "<?php\n// ab-mcp-preview-loader 0 4.14.0\nreturn;\n" );
		self::assertFalse( AB_MCP_Preview_Protection::loader_current() );
		AB_MCP_Preview_Protection::hook();
		self::assertTrue( AB_MCP_Preview_Protection::loader_current(), 'Replaced on load.' );
		unlink( $path );
		AB_MCP_Preview_Protection::hook();
		self::assertFileExists( $path, 'A missing one is written on load.' );

		// Not while off, and not under wp_installing(), where the loader itself runs.
		AB_MCP_Preview_Protection::disable( 7, AB_MCP_Preview_Protection::SOURCE_TOOL );
		AB_MCP_Preview_Protection::hook();
		self::assertFileDoesNotExist( $path );
		$this->on();
		unlink( $path );
		$GLOBALS['ab_test_installing'] = true;
		AB_MCP_Preview_Protection::hook();
		self::assertFileDoesNotExist( $path, 'Under WP_INSTALLING nothing is written.' );
	}

	public function testTheLoaderDoesNothingOutsideInstallingWithoutThePluginWithoutItsFilesOrWithTheProtectionOff(): void {
		$this->on();
		$path = AB_MCP_Preview_Protection::loader_path();
		$hooked = static fn(): bool => false !== has_action( 'wp', array( AB_MCP_Preview_Protection::class, 'gate_without_themes' ) )
			|| false !== has_filter( 'rest_authentication_errors', array( AB_MCP_Preview_Protection::class, 'gate_rest' ) );
		$GLOBALS['ab_test_options']['active_plugins'] = array( 'akismet/akismet.php', 'alphabridge-mcp/alphabridge-mcp.php' );

		$GLOBALS['ab_test_installing'] = false;
		self::runLoader( $path );
		self::assertFalse( $hooked(), 'Not under WP_INSTALLING: the plugin itself hooks the gates.' );

		$GLOBALS['ab_test_installing'] = true;
		$GLOBALS['ab_test_options']['active_plugins'] = array( 'akismet/akismet.php' );
		self::runLoader( $path );
		self::assertFalse( $hooked(), 'The plugin is not active on this site.' );

		$GLOBALS['ab_test_options']['active_plugins'] = array( 'alphabridge-mcp/alphabridge-mcp.php' );
		$copy = $path . '.copy.php';
		file_put_contents( $copy, str_replace( var_export( rtrim( AB_MCP_DIR, '/' ) . '/', true ), var_export( sys_get_temp_dir() . '/ab-mcp-gone-' . getmypid() . '/', true ), AB_MCP_Preview_Protection::loader_source() ) );
		self::runLoader( $copy );
		self::assertTrue( $hooked(), 'The files are gone but the classes are there (a network plugin with the core, here the test process): the gates, from the classes loaded. The case with neither runs in a process of its own (the child below, mode gone).' );

		AB_MCP_Preview_Protection::disable( 7, AB_MCP_Preview_Protection::SOURCE_TOOL );
		file_put_contents( $copy, AB_MCP_Preview_Protection::loader_source() );
		self::runLoader( $copy );
		self::assertFalse( $hooked(), 'The protection is off.' );

		$this->on();
		self::runLoader( $path );
		self::assertTrue( $hooked(), 'Under WP_INSTALLING, with the plugin active, its files there and the protection on: the gates.' );

		// A network-activated plugin counts too.
		$GLOBALS['ab_test_options']['active_plugins'] = array();
		$GLOBALS['ab_test_multisite']                 = true;
		$GLOBALS['ab_test_site_options']['active_sitewide_plugins'] = array( 'alphabridge-mcp/alphabridge-mcp.php' => time() );
		self::runLoader( $path );
		self::assertTrue( $hooked() );
	}

	public function testUnderInstallingTheLoaderLocksRestAndFeedForVisitorsAndPassesCookieAndLogin(): void {
		$this->on();
		$GLOBALS['ab_test_installing']                = true;
		$GLOBALS['ab_test_using_themes']              = false;
		$GLOBALS['ab_test_options']['active_plugins'] = array( 'alphabridge-mcp/alphabridge-mcp.php' );
		self::runLoader( AB_MCP_Preview_Protection::loader_path() );

		// wp-activate.php?rest_route=/wp/v2/posts: the REST API under WP_INSTALLING.
		$_SERVER['REQUEST_URI'] = '/wp-activate.php?rest_route=%2Fwp%2Fv2%2Fposts';
		$_GET['rest_route']     = '/wp/v2/posts';
		$result                 = apply_filters( 'rest_authentication_errors', true );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 401, $result->get_error_data()['status'] );
		$_GET['rest_route'] = '/alphabridge/v1/mcp';
		self::assertTrue( apply_filters( 'rest_authentication_errors', true ), 'The MCP endpoint stays open there too.' );
		$_GET = array();

		// wp-activate.php?feed=rss2: the feed, served without themes.
		$_SERVER['REQUEST_URI'] = '/wp-activate.php?feed=rss2';
		try {
			do_action( 'wp' );
			self::fail( 'The feed went out.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'die', $exit->kind );
			self::assertSame( 401, $GLOBALS['ab_test_die_args']['response'] );
		}

		$_COOKIE[ AB_MCP_Preview_Protection::COOKIE ] = AB_MCP_Preview_Protection::cookie_value( AB_MCP_Preview_Protection::state()['hash'], time() + 100 );
		$_SERVER['REQUEST_URI']                       = '/wp-activate.php?rest_route=%2Fwp%2Fv2%2Fposts';
		$_GET['rest_route']                           = '/wp/v2/posts';
		self::assertTrue( apply_filters( 'rest_authentication_errors', true ), 'With the cookie.' );
		$_SERVER['REQUEST_URI'] = '/wp-activate.php?feed=rss2';
		do_action( 'wp' );
		$_COOKIE = array();

		$GLOBALS['ab_test_logged_in'] = true;
		self::assertTrue( apply_filters( 'rest_authentication_errors', true ), 'Signed in.' );
		do_action( 'wp' );
		$_GET = array();
	}

	public function testWithANetworkPluginThatCarriesTheCoreTheLoaderRequiresNoSecondCopy(): void {
		// A network-activated Pro add-on loads under WP_INSTALLING too
		// (wp_get_active_network_plugins() has no such check) and brings the
		// core's classes from its own folder. The loader, pointed at a copy
		// of the files in another folder, must not require them again: that
		// would be «Cannot redeclare class». The classes are loaded in this
		// process, so the copy stands for the network plugin's.
		$this->on();
		$copy_dir = sys_get_temp_dir() . '/ab-mcp-core-copy-' . getmypid() . '/';
		mkdir( $copy_dir . 'includes', 0755, true );
		foreach ( array( 'class-settings.php', 'class-page-cache.php', 'class-preview-protection.php' ) as $file ) {
			copy( AB_MCP_DIR . 'includes/' . $file, $copy_dir . 'includes/' . $file );
		}
		try {
			$loader = AB_MCP_Preview_Protection::loader_path() . '.copy.php';
			file_put_contents( $loader, str_replace( var_export( rtrim( AB_MCP_DIR, '/' ) . '/', true ), var_export( $copy_dir, true ), AB_MCP_Preview_Protection::loader_source() ) );
			$GLOBALS['ab_test_installing']                = true;
			$GLOBALS['ab_test_options']['active_plugins'] = array( 'alphabridge-mcp/alphabridge-mcp.php' );
			self::runLoader( $loader );
			self::assertSame( 10, has_action( 'wp', array( AB_MCP_Preview_Protection::class, 'gate_without_themes' ) ), 'The gates hang, with the classes already there.' );
			self::assertSame( 110, has_filter( 'rest_authentication_errors', array( AB_MCP_Preview_Protection::class, 'gate_rest' ) ) );
			self::assertNotContains( realpath( $copy_dir . 'includes/class-preview-protection.php' ), array_map( 'realpath', get_included_files() ), 'The copy was not required: no second copy of the core.' );
		} finally {
			foreach ( (array) glob( $copy_dir . 'includes/*' ) as $file ) {
				unlink( $file );
			}
			rmdir( $copy_dir . 'includes' );
			rmdir( $copy_dir );
		}
	}

	public function testInAProcessWithoutThePluginTheLoaderLoadsTheClassesAndHooksTheGates(): void {
		// As wp-activate.php: no plugin loaded. The classes are in this
		// process from the start, so a process of its own runs the loader
		// against stubs (tests/standalone-preview/loader-child.php).
		$this->on();
		$path = AB_MCP_Preview_Protection::loader_path();
		// A loader whose plugin folder is gone (the plugin deleted over FTP).
		$gone = $path . '.gone.php';
		file_put_contents( $gone, str_replace( var_export( rtrim( AB_MCP_DIR, '/' ) . '/', true ), var_export( sys_get_temp_dir() . '/ab-mcp-gone-' . getmypid() . '/', true ), AB_MCP_Preview_Protection::loader_source() ) );
		foreach ( array( 'fresh', 'off', 'gone' ) as $mode ) {
			$out  = array();
			$code = 1;
			exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/standalone-preview/loader-child.php' ) . ' ' . escapeshellarg( 'gone' === $mode ? $gone : $path ) . ' ' . $mode . ' 2>&1', $out, $code );
			self::assertSame( 0, $code, $mode . ': ' . implode( ' | ', $out ) );
			self::assertSame( 'LOADER-CHILD ok', end( $out ), $mode );
			self::assertStringNotContainsString( 'FAIL', implode( "\n", $out ), $mode );
		}
	}

	public function testAFolderThatCannotBeWrittenIsReportedNotHidden(): void {
		mkdir( WPMU_PLUGIN_DIR, 0755, true );
		chmod( WPMU_PLUGIN_DIR, 0555 );
		if ( is_writable( WPMU_PLUGIN_DIR ) && false !== @file_put_contents( WPMU_PLUGIN_DIR . '/probe', 'x' ) ) {
			unlink( WPMU_PLUGIN_DIR . '/probe' );
			self::markTestSkipped( 'This account writes into a read-only folder (root).' );
		}
		try {
			$out = AB_MCP_Tools_Staging::set_preview_protection( array( 'enabled' => true ) );
			self::assertTrue( $out['enabled'], 'The protection goes on all the same.' );
			self::assertSame( array( 'path' => AB_MCP_Preview_Protection::loader_path(), 'written' => false ), $out['loader'] );
			self::assertCount( 4, $out['not_covered'] );
			self::assertStringContainsString( 'could not be written to ' . AB_MCP_Preview_Protection::loader_path(), $out['not_covered'][3] );
			self::assertStringContainsString( 'Create that file with the content in loader_source', $out['not_covered'][3] );
			self::assertSame( AB_MCP_Preview_Protection::loader_source(), $out['loader_source'] );
			self::assertFalse( AB_MCP_Preview_Protection::loader_current() );

			$GLOBALS['ab_test_can'] = static fn(): bool => true;
			$html                   = $this->admin( 'staging_card_html' );
			self::assertStringContainsString( '<div class="notice notice-warning inline ab-preview__loader"><p class="ab-preview__loader-text">', $html, 'The block shows.' );
			self::assertStringContainsString( 'could not be written to ' . AB_MCP_Preview_Protection::loader_path(), $html );
			self::assertStringContainsString( '<textarea readonly', $html );
			self::assertStringContainsString( htmlspecialchars( '// ab-mcp-preview-loader ' . AB_MCP_Preview_Protection::LOADER_VERSION, ENT_QUOTES, 'UTF-8' ), $html, 'The file to create, in full.' );

			// Switching on over ajax says the same at once, without a reload.
			AB_MCP_Tools_Staging::set_preview_protection( array( 'enabled' => false ) );
			$exit = $this->ajax( 'on' );
			self::assertSame( 200, $exit->status );
			self::assertSame( array( 'path' => AB_MCP_Preview_Protection::loader_path(), 'written' => false ), $exit->payload['loader'] );
			self::assertStringContainsString( 'could not be written to ' . AB_MCP_Preview_Protection::loader_path(), $exit->payload['loader_note'] );
			self::assertSame( AB_MCP_Preview_Protection::loader_source(), $exit->payload['loader_source'] );
			self::assertSame( AB_MCP_Preview_Protection::PASSWORD_LENGTH, strlen( $exit->payload['password'] ), 'And the password all the same.' );

			chmod( WPMU_PLUGIN_DIR, 0755 );
			$ok = AB_MCP_Tools_Staging::set_preview_protection( array( 'enabled' => false ) );
			$ok = AB_MCP_Tools_Staging::set_preview_protection( array( 'enabled' => true ) );
			self::assertTrue( $ok['loader']['written'] );
			self::assertCount( 3, $ok['not_covered'] );
			self::assertArrayNotHasKey( 'loader_source', $ok );
			$html = $this->admin( 'staging_card_html' );
			self::assertStringNotContainsString( 'could not be written', $html );
			self::assertStringContainsString( '<div class="notice notice-warning inline ab-preview__loader" hidden><p class="ab-preview__loader-text"></p>', $html, 'The block is there for the ajax answer, hidden and empty.' );
			AB_MCP_Tools_Staging::set_preview_protection( array( 'enabled' => false ) );
			$exit = $this->ajax( 'on' );
			self::assertSame( '', $exit->payload['loader_note'], 'Written: no note.' );
			self::assertSame( '', $exit->payload['loader_source'] );
			self::assertTrue( $exit->payload['loader']['written'] );
		} finally {
			chmod( WPMU_PLUGIN_DIR, 0755 );
		}
	}

	public function testOnANetworkTheLoaderStaysWhileAnotherSiteIsOn(): void {
		$GLOBALS['ab_test_multisite'] = true;
		$GLOBALS['ab_test_sites']     = array( 1, 2, 3 );
		$path                         = AB_MCP_Preview_Protection::loader_path();
		foreach ( array( 1, 3 ) as $site ) {
			switch_to_blog( $site );
			ab_test_add_user( 7 );
			$GLOBALS['ab_test_options']['blog_public']    = '1';
			$GLOBALS['ab_test_options']['active_plugins'] = array( 'alphabridge-mcp/alphabridge-mcp.php' );
			AB_MCP_Preview_Protection::enable( 7, AB_MCP_Preview_Protection::SOURCE_TOOL );
			restore_current_blog();
		}
		self::assertFileExists( $path );

		switch_to_blog( 1 );
		AB_MCP_Preview_Protection::disable( 7, AB_MCP_Preview_Protection::SOURCE_TOOL );
		restore_current_blog();
		self::assertFileExists( $path, 'Site 3 is still on: the loader is the network\'s.' );

		switch_to_blog( 3 );
		AB_MCP_Preview_Protection::on_deactivate( false );
		restore_current_blog();
		self::assertFileDoesNotExist( $path, 'The last site with the protection deactivated the plugin: removed.' );

		switch_to_blog( 3 );
		AB_MCP_Preview_Protection::on_activate( false );
		restore_current_blog();
		self::assertFileExists( $path );
		switch_to_blog( 1 );
		AB_MCP_Preview_Protection::enable( 7, AB_MCP_Preview_Protection::SOURCE_TOOL );
		restore_current_blog();
		AB_MCP_Preview_Protection::on_deactivate( true );
		self::assertFileExists( $path, 'Deactivated for the network while sites 1 and 3 are active by their own activation: they run on, the loader stays.' );

		// Active for the network only: a network-wide deactivation ends every
		// protection, and the loader goes.
		foreach ( array( 1, 3 ) as $site ) {
			switch_to_blog( $site );
			$GLOBALS['ab_test_options']['active_plugins'] = array();
			restore_current_blog();
		}
		$GLOBALS['ab_test_site_options']['active_sitewide_plugins'] = array( 'alphabridge-mcp/alphabridge-mcp.php' => time() );
		AB_MCP_Preview_Protection::on_deactivate( true );
		self::assertFileDoesNotExist( $path, 'Nobody runs it any more: removed.' );
	}

	public function testANetworkWideDeactivationLeavesOutASiteWhereThePluginStaysActiveLocally(): void {
		// WordPress fires the hooks before it writes the activation options:
		// under a network-wide deactivation a site's own activation still
		// stands, and so the protection runs on there.
		$GLOBALS['ab_test_multisite'] = true;
		$GLOBALS['ab_test_sites']     = array( 1, 2 );
		$plugin                       = 'alphabridge-mcp/alphabridge-mcp.php';
		foreach ( array( 1, 2 ) as $site ) {
			switch_to_blog( $site );
			ab_test_add_user( 7 );
			$GLOBALS['ab_test_options']['blog_public']    = '1';
			$GLOBALS['ab_test_options']['active_plugins'] = 1 === $site ? array( $plugin ) : array();
			AB_MCP_Preview_Protection::enable( 7, AB_MCP_Preview_Protection::SOURCE_TOOL );
			restore_current_blog();
		}

		// Activated for the network on top: site 1 runs the protection by
		// its own activation already, nothing is remembered twice.
		switch_to_blog( 1 );
		$GLOBALS['ab_test_options']['blog_public'] = 0;
		restore_current_blog();
		$purges = $GLOBALS['ab_test_purges'];
		AB_MCP_Preview_Protection::on_activate( true );
		switch_to_blog( 1 );
		self::assertSame( '1', AB_MCP_Preview_Protection::state()['blog_public'], 'Site 1: the remembered value stays what the local activation remembered.' );
		self::assertSame( 0, $GLOBALS['ab_test_options']['blog_public'] );
		restore_current_blog();
		self::assertSame( $purges + 1, $GLOBALS['ab_test_purges'], 'Site 2 was taken up (its cache emptied once); site 1 was left alone.' );

		// Deactivated for the network: site 1 keeps running by its own
		// activation, site 2 (network only) goes back.
		$GLOBALS['ab_test_site_options']['active_sitewide_plugins'] = array( $plugin => time() );
		AB_MCP_Preview_Protection::on_deactivate( true );
		switch_to_blog( 1 );
		self::assertSame( 0, $GLOBALS['ab_test_options']['blog_public'], 'Site 1: still discouraged, the protection runs on.' );
		self::assertFalse( AB_MCP_Preview_Protection::state()['restored'], 'No note: nothing was written back.' );
		self::assertTrue( AB_MCP_Preview_Protection::is_on() );
		restore_current_blog();
		switch_to_blog( 2 );
		self::assertSame( '1', $GLOBALS['ab_test_options']['blog_public'], 'Site 2: written back.' );
		self::assertTrue( AB_MCP_Preview_Protection::state()['restored'] );
		restore_current_blog();
		self::assertFileExists( AB_MCP_Preview_Protection::loader_path(), 'Site 1 runs the protection: the loader stays for it.' );

		// WordPress then writes the option: the network activation is gone.
		$GLOBALS['ab_test_site_options']['active_sitewide_plugins'] = array();

		// The other way round: activated on site 2 by itself, then activated
		// for the network on top and deactivated locally: the network
		// activation keeps the protection running there, the visibility stays.
		switch_to_blog( 2 );
		$GLOBALS['ab_test_options']['active_plugins'] = array( $plugin );
		AB_MCP_Preview_Protection::on_activate( false );
		self::assertSame( 0, $GLOBALS['ab_test_options']['blog_public'], 'Activated by itself: discouraged again.' );
		self::assertFalse( AB_MCP_Preview_Protection::state()['restored'] );
		$GLOBALS['ab_test_site_options']['active_sitewide_plugins'] = array( $plugin => time() );
		$purges                                                     = $GLOBALS['ab_test_purges'];
		AB_MCP_Preview_Protection::on_activate( false );
		self::assertSame( '1', AB_MCP_Preview_Protection::state()['blog_public'], 'A local activation under the network one remembers nothing anew.' );
		self::assertSame( $purges, $GLOBALS['ab_test_purges'], 'And does nothing else either.' );
		AB_MCP_Preview_Protection::on_deactivate( false );
		self::assertSame( 0, $GLOBALS['ab_test_options']['blog_public'], 'Site 2 stays active for the network: the visibility stays.' );
		self::assertFalse( AB_MCP_Preview_Protection::state()['restored'] );
		self::assertFileExists( AB_MCP_Preview_Protection::loader_path() );
		restore_current_blog();
	}

	public function testOnASingleSiteAStaleNetworkOptionDoesNotKeepTheProtection(): void {
		// Not a network: active_sitewide_plugins means nothing here (a row a
		// former network left behind), so a deactivation writes back.
		$GLOBALS['ab_test_site_options']['active_sitewide_plugins'] = array( 'alphabridge-mcp/alphabridge-mcp.php' => time() );
		$this->on();
		AB_MCP_Preview_Protection::on_deactivate( false );
		self::assertSame( '1', $GLOBALS['ab_test_options']['blog_public'] );
		self::assertTrue( AB_MCP_Preview_Protection::state()['restored'] );
	}

	public function testTheLoaderCountsOnlySitesWhoseProtectionRuns(): void {
		$GLOBALS['ab_test_multisite'] = true;
		$GLOBALS['ab_test_sites']     = array( 1, 2, 3 );
		$plugin                       = 'alphabridge-mcp/alphabridge-mcp.php';
		$path                         = AB_MCP_Preview_Protection::loader_path();
		foreach ( array( 1, 2, 3 ) as $site ) {
			switch_to_blog( $site );
			ab_test_add_user( 7 );
			$GLOBALS['ab_test_options']['blog_public']    = '1';
			// Site 3: a plugin whose folder only begins like this one's.
			$GLOBALS['ab_test_options']['active_plugins'] = 3 === $site ? array( 'alphabridge-mcp-extra/alphabridge-mcp-extra.php' ) : array( $plugin );
			AB_MCP_Preview_Protection::enable( 7, AB_MCP_Preview_Protection::SOURCE_TOOL );
			restore_current_blog();
		}
		// Site 3: on, but the plugin is active neither there nor for the
		// network — a state left behind; it protects nothing.

		switch_to_blog( 1 );
		AB_MCP_Preview_Protection::on_deactivate( false );
		restore_current_blog();
		self::assertFileExists( $path, 'Site 2 runs the protection.' );

		switch_to_blog( 2 );
		AB_MCP_Preview_Protection::on_deactivate( false );
		restore_current_blog();
		self::assertFileDoesNotExist( $path, 'Site 1 was deactivated (restored), site 3 has no active plugin: nobody runs it, the loader goes.' );

		// Switching off on a site counts the same way.
		switch_to_blog( 2 );
		AB_MCP_Preview_Protection::on_activate( false );
		restore_current_blog();
		self::assertFileExists( $path );
		switch_to_blog( 2 );
		AB_MCP_Preview_Protection::disable( 7, AB_MCP_Preview_Protection::SOURCE_TOOL );
		restore_current_blog();
		self::assertFileDoesNotExist( $path );

		// A site the network activation covers counts, with no local activation.
		$GLOBALS['ab_test_site_options']['active_sitewide_plugins'] = array( $plugin => time() );
		switch_to_blog( 2 );
		AB_MCP_Preview_Protection::enable( 7, AB_MCP_Preview_Protection::SOURCE_TOOL );
		$GLOBALS['ab_test_options']['active_plugins'] = array();
		restore_current_blog();
		switch_to_blog( 3 );
		AB_MCP_Preview_Protection::disable( 7, AB_MCP_Preview_Protection::SOURCE_TOOL );
		restore_current_blog();
		self::assertFileExists( $path, 'Site 2 runs the protection by the network activation.' );
	}

	public function testRobotsPassesOnlyOnTheWayWithThemes(): void {
		$this->on();
		$state                        = AB_MCP_Preview_Protection::state();
		$GLOBALS['ab_test_is_robots'] = true;
		$_SERVER['REQUEST_URI']       = '/robots.txt';
		self::assertTrue( AB_MCP_Preview_Protection::frontend_context( true )['robots'] );
		self::assertFalse( AB_MCP_Preview_Protection::frontend_context( false )['robots'], 'Without themes the template loader returns to the script after robots.txt, and wp-signup.php renders on.' );
		self::assertSame( 'pass', AB_MCP_Preview_Protection::frontend_decision( AB_MCP_Preview_Protection::frontend_context( true ), $state, time() )['action'] );
		self::assertSame( 'challenge', AB_MCP_Preview_Protection::frontend_decision( AB_MCP_Preview_Protection::frontend_context( false ), $state, time() )['action'] );
		self::assertNull( AB_MCP_Preview_Protection::gate_frontend(), 'template_redirect: robots.txt is served, with Disallow: / from blog_public 0.' );

		$GLOBALS['ab_test_using_themes'] = false;
		$_SERVER['REQUEST_URI']          = '/wp-signup.php?robots=1';
		try {
			AB_MCP_Preview_Protection::gate_without_themes();
			self::fail( 'wp-signup.php rendered with the theme.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'die', $exit->kind );
		}
		$GLOBALS['pagenow'] = 'wp-links-opml.php';
		try {
			AB_MCP_Preview_Protection::gate_script();
			self::fail( 'The bookmarks went out.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'die', $exit->kind );
		}
		unset( $GLOBALS['pagenow'] );
	}

	public function testUninstallRespectsTheNoteAndRemovesTheLoader(): void {
		self::uninstallConstants();
		$this->on();
		AB_MCP_Preview_Protection::on_deactivate();
		self::assertTrue( AB_MCP_Preview_Protection::state()['restored'] );
		$GLOBALS['ab_test_options']['blog_public'] = 0;
		$path = AB_MCP_Preview_Protection::loader_path();
		file_put_contents( $path, AB_MCP_Preview_Protection::loader_source() );
		include dirname( __DIR__ ) . '/uninstall.php';
		self::assertSame( 0, $GLOBALS['ab_test_options']['blog_public'], 'Chosen after the deactivation: stays.' );
		self::assertArrayNotHasKey( AB_MCP_Settings::OPT_OPTIONS, $GLOBALS['ab_test_options'] );
		self::assertFileDoesNotExist( $path, 'The loader goes with the plugin.' );
	}

	/* ------------------------------------------------------- settings page */

	/** @param mixed ...$args */
	private function admin( string $method, ...$args ): string {
		$m = new ReflectionMethod( AB_MCP_Admin::class, $method );
		ob_start();
		$out    = $m->invoke( new AB_MCP_Admin(), ...$args );
		$echoed = (string) ob_get_clean();
		return $echoed . ( is_string( $out ) ? $out : '' );
	}

	public function testTheCardShowsTheStateAndTheWayToSwitch(): void {
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		$html                   = $this->admin( 'staging_card_html' );
		self::assertStringContainsString( 'id="ab-preview"', $html );
		self::assertStringContainsString( '<p class="ab-eyebrow">Staging</p>', $html );
		self::assertStringContainsString( '<div class="ab-staging ab-preview" id="ab-preview"><h3 class="ab-setting__title">Preview protection</h3>', $html, 'A part of the card «Staging».' );
		self::assertStringContainsString( 'Off: visitors see the site.', $html );
		self::assertStringContainsString( 'data-preview="on" data-when="off">Switch on', $html, 'On is offered while off.' );
		self::assertStringContainsString( 'data-preview="password" data-when="on" hidden>New password', $html );
		self::assertStringContainsString( 'class="ab-preview__off" data-when="on" hidden>', $html );
		self::assertStringContainsString( 'name="_wpnonce" value="nonce-ab_mcp_preview_protection"', $html, 'Signed for its handler.' );
		self::assertStringContainsString( '<input type="hidden" name="action" value="ab_mcp_preview_protection">', $html );
		self::assertStringContainsString( '<input type="hidden" name="preview" value="off">', $html );
		self::assertStringContainsString( 'class="ab-reveal ab-preview-reveal" hidden>', $html );
		self::assertStringContainsString( 'readonly value="" class="ab-select ab-preview-password"', $html, 'The field is empty until the ajax answer fills it.' );
		self::assertStringContainsString( '/wp-content/uploads/', $html );
		self::assertStringContainsString( 'identifies nobody', $html );

		$this->on();
		$html = $this->admin( 'staging_card_html' );
		self::assertStringContainsString( 'switched on by user7.', $html );
		self::assertStringContainsString( 'data-preview="on" data-when="off" hidden>Switch on', $html );
		self::assertStringContainsString( 'data-preview="password" data-when="on">New password', $html );
		self::assertStringContainsString( 'class="ab-preview__off" data-when="on">', $html );
		self::assertStringNotContainsString( AB_MCP_Preview_Protection::state()['hash'], $html );
	}

	public function testTheFormSwitchesOffBehindTheNonceAndTheCapability(): void {
		$this->on();
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		$_POST    = array( 'preview' => 'off', '_wpnonce' => 'nonce-ab_mcp_preview_protection' );
		$_REQUEST = $_POST;
		try {
			( new AB_MCP_Admin() )->handle_preview_protection();
			self::fail( 'No redirect.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'redirect', $exit->kind );
			self::assertStringContainsString( 'ab_notice=preview_off', $exit->detail );
			self::assertStringEndsWith( '#ab-preview', $exit->detail );
		}
		self::assertFalse( AB_MCP_Preview_Protection::is_on() );
		self::assertSame( '1', $GLOBALS['ab_test_options']['blog_public'] );
		self::assertSame( 'form', AB_MCP_Preview_Protection::state()['source'] );

		$this->on();
		$_POST    = array( 'preview' => 'off', '_wpnonce' => 'nonce-ab_mcp_save' );
		$_REQUEST = $_POST;
		try {
			( new AB_MCP_Admin() )->handle_preview_protection();
			self::fail( 'No refusal.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'die', $exit->kind );
		}
		self::assertTrue( AB_MCP_Preview_Protection::is_on(), 'A wrong nonce changes nothing.' );

		$GLOBALS['ab_test_can'] = static fn(): bool => false;
		$_POST    = array( 'preview' => 'off', '_wpnonce' => 'nonce-ab_mcp_preview_protection' );
		$_REQUEST = $_POST;
		try {
			( new AB_MCP_Admin() )->handle_preview_protection();
			self::fail( 'No refusal.' );
		} catch ( AbTestExit $exit ) {
			self::assertSame( 'die', $exit->kind );
			self::assertSame( 'Insufficient permissions.', $exit->detail );
		}
		self::assertTrue( AB_MCP_Preview_Protection::is_on() );
		$_REQUEST = array();
	}

	private function ajax( string $choice ): AbTestJsonExit {
		$_POST = array( 'preview' => $choice );
		try {
			( new AB_MCP_Admin() )->ajax_preview_protection();
		} catch ( AbTestJsonExit $exit ) {
			return $exit;
		}
		self::fail( 'The ajax handler ended without an answer.' );
	}

	public function testTheAjaxSwitchesOnAndRevealsThePasswordOnce(): void {
		$GLOBALS['ab_test_can'] = static fn(): bool => true;

		$exit = $this->ajax( 'on' );
		self::assertSame( 200, $exit->status );
		$password = $exit->payload['password'];
		self::assertSame( AB_MCP_Preview_Protection::PASSWORD_LENGTH, strlen( $password ) );
		self::assertStringContainsString( 'switched on by user7.', $exit->payload['status'] );
		self::assertSame( array( 'path' => AB_MCP_Preview_Protection::loader_path(), 'written' => true ), $exit->payload['loader'] );
		self::assertSame( '', $exit->payload['loader_note'] );
		self::assertTrue( AB_MCP_Preview_Protection::is_on() );
		self::assertSame( 'form', AB_MCP_Preview_Protection::state()['source'] );
		self::assertTrue( AB_MCP_Preview_Protection::verify_password( $password, AB_MCP_Preview_Protection::state()['hash'] ) );
		self::assertStringNotContainsString( $password, $this->stored(), 'Never persisted.' );

		$exit = $this->ajax( 'on' );
		self::assertSame( 409, $exit->status, 'On already: the password is not shown again.' );
		self::assertStringContainsString( 'not shown again', $exit->payload['message'] );

		$exit = $this->ajax( 'password' );
		self::assertSame( 200, $exit->status );
		self::assertNotSame( $password, $exit->payload['password'] );
		self::assertFalse( AB_MCP_Preview_Protection::verify_password( $password, AB_MCP_Preview_Protection::state()['hash'] ) );

		$exit = $this->ajax( 'maybe' );
		self::assertSame( 400, $exit->status );
	}

	public function testTheAjaxRefusesWhatCannotBeDone(): void {
		$GLOBALS['ab_test_can'] = static fn(): bool => true;
		$exit = $this->ajax( 'password' );
		self::assertSame( 409, $exit->status, 'Off: no password to renew.' );
		self::assertStringContainsString( 'Switch it on first', $exit->payload['message'] );
		self::assertFalse( AB_MCP_Preview_Protection::is_on() );

		$GLOBALS['ab_test_can'] = static fn(): bool => false;
		$exit = $this->ajax( 'on' );
		self::assertSame( 403, $exit->status );
		self::assertFalse( AB_MCP_Preview_Protection::is_on(), 'Without manage_options nothing is switched.' );
	}

	public function testTheNoticeAfterSwitchingOff(): void {
		$_GET['ab_notice'] = 'preview_off';
		$html              = $this->admin( 'notice' );
		self::assertStringContainsString( 'notice-success', $html );
		self::assertStringContainsString( 'The preview protection is off: visitors see the site again', $html );
	}
}
