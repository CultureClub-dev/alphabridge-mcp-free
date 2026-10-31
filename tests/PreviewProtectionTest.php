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
 *   case: wp-admin, admin-ajax, cron, XML-RPC, REST and WP-CLI requests,
 *   the documents below /.well-known/, the consent page, robots.txt,
 *   signed-in users, and a visitor with a valid cookie. Feeds, search,
 *   sitemaps and uploads paths that reach WordPress are not exempt.
 * - While on, every front-end answer is marked DONOTCACHEPAGE and carries
 *   X-Robots-Tag: noindex, nofollow.
 * - The REST gate passes an error of WordPress's own checks through, passes
 *   signed-in users, the root /wp-json/ (the namespace index the hub
 *   reads), every route of this plugin's namespace (the MCP endpoint with
 *   and without the token in the path, the three OAuth routes), and a
 *   visitor with a valid cookie; wp/v2, oembed/1.0, the Store API and every
 *   other namespace answer 401 to anyone else, whether the route comes as
 *   a pretty permalink or as ?rest_route=.
 * - The gates hang after the OAuth handlers (template_redirect 0) and after
 *   WordPress's REST checks (rest_authentication_errors 100).
 * - The connection check asks only addresses the protection leaves open.
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
	}

	protected function tearDown(): void {
		$_GET    = array();
		$_POST   = array();
		$_COOKIE = array();
		unset( $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], $GLOBALS['wp'] );
		self::scope( 'full' );
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
				'path'        => '/',
				'request_uri' => '/',
				'method'      => 'GET',
				'oauth'       => false,
				'oauth_on'    => false,
				'password'    => null,
				'cookie'      => '',
				'logged_in'   => false,
				'admin'       => false,
				'ajax'        => false,
				'cron'        => false,
				'xmlrpc'      => false,
				'rest'        => false,
				'cli'         => false,
				'robots'      => false,
			),
			$over
		);
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
			'admin-ajax'               => array( array( 'ajax' => true ), 'ajax' ),
			'wp-cron'                  => array( array( 'cron' => true ), 'cron' ),
			'xmlrpc'                   => array( array( 'xmlrpc' => true ), 'xmlrpc' ),
			'REST'                     => array( array( 'rest' => true ), 'rest' ),
			'WP-CLI'                   => array( array( 'cli' => true ), 'cli' ),
			'OAuth server metadata'    => array( array( 'path' => '/.well-known/oauth-authorization-server', 'oauth_on' => true ), 'well_known' ),
			'OAuth resource metadata'  => array( array( 'path' => '/.well-known/oauth-protected-resource/wp-json/alphabridge/v1/mcp', 'oauth_on' => true ), 'well_known' ),
			'consent page'             => array( array( 'oauth' => true, 'request_uri' => '/?ab_mcp_oauth=authorize&client_id=x' ), 'oauth' ),
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

	public function testTheRestOfWellKnownIsNotOpenAndTheHubReadsThe401AsNotThere(): void {
		$this->on();
		$state = AB_MCP_Preview_Protection::state();
		// The hub asks for this document first and reads any status but 200
		// as «not there» (alphabridge-connect src/detect.ts, readWellKnown()),
		// so the password page sends it on to the probe as a 404 would; left
		// open, WordPress would answer it with the theme's 404 page.
		self::assertSame( 'challenge', AB_MCP_Preview_Protection::frontend_decision( $this->visitor( array( 'path' => '/.well-known/alphabridge.json', 'oauth_on' => true ) ), $state, time() )['action'] );
		self::assertSame( 'challenge', AB_MCP_Preview_Protection::frontend_decision( $this->visitor( array( 'path' => '/.well-known/security.txt', 'oauth_on' => true ) ), $state, time() )['action'] );
		self::assertSame( 'challenge', AB_MCP_Preview_Protection::frontend_decision( $this->visitor( array( 'path' => '/.well-known/oauth-authorization-server', 'oauth_on' => false ) ), $state, time() )['action'], 'With the sign-in off nothing serves the document; WordPress would show its 404 page.' );
		self::assertSame( 'challenge', AB_MCP_Preview_Protection::frontend_decision( $this->visitor( array( 'path' => '/.well-known/oauth-authorization-server-x', 'oauth_on' => true ) ), $state, time() )['action'], 'Matched as AB_MCP_OAuth matches it: the document, or a path below it.' );
		self::assertSame( 'pass', AB_MCP_Preview_Protection::frontend_decision( $this->visitor( array( 'path' => '/.well-known/oauth-protected-resource', 'oauth_on' => true ) ), $state, time() )['action'] );
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
		self::assertTrue( $ctx['oauth'] );
		self::assertTrue( $ctx['oauth_on'], 'The sign-in is on by default.' );
		self::assertSame( 'secret', $ctx['password'] );
		self::assertSame( '1|abc', $ctx['cookie'] );
		self::assertTrue( $ctx['logged_in'] );
		self::assertTrue( $ctx['admin'] );
		self::assertFalse( $ctx['ajax'] );
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
		self::assertFalse( $ctx['oauth'] );
		AB_MCP_Settings::set( 'oauth_enabled', false );
		self::assertFalse( AB_MCP_Preview_Protection::frontend_context()['oauth_on'] );
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
		self::assertTrue( $ctx['ajax'] );
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
		self::assertSame( 1, has_action( 'template_redirect', array( AB_MCP_Preview_Protection::class, 'gate_frontend' ) ) );
		self::assertGreaterThan( 0, AB_MCP_Preview_Protection::TEMPLATE_PRIORITY, 'After the well-known documents and the consent page (priority 0).' );
		self::assertLessThan( 10, AB_MCP_Preview_Protection::TEMPLATE_PRIORITY, 'Before redirect_canonical.' );
		self::assertSame( 110, has_filter( 'rest_authentication_errors', array( AB_MCP_Preview_Protection::class, 'gate_rest' ) ) );
		self::assertGreaterThan( 100, AB_MCP_Preview_Protection::REST_PRIORITY, 'After rest_cookie_check_errors (100), where is_user_logged_in() is settled.' );
		self::assertSame( 10, has_filter( 'wp_headers', array( AB_MCP_Preview_Protection::class, 'headers' ) ) );

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
		foreach ( array( '/wp/v2', '/wp/v2/posts', '/oembed/1.0/embed', '/alphabridge/v10', '/alphabridge/v1x/mcp', '/alphabridge', '/x/alphabridge/v1/mcp' ) as $route ) {
			self::assertFalse( AB_MCP_Preview_Protection::rest_exempt( $route ), $route );
		}
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
			$ctx = $this->visitor( array( 'path' => $path, 'request_uri' => $url, 'oauth' => isset( $query['ab_mcp_oauth'] ), 'oauth_on' => AB_MCP_OAuth::enabled() ) );
			self::assertSame( 'pass', AB_MCP_Preview_Protection::frontend_decision( $ctx, $state, time() )['action'], $url );
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
		$html                   = $this->admin( 'preview_card_html' );
		self::assertStringContainsString( 'id="ab-preview"', $html );
		self::assertStringContainsString( '<p class="ab-eyebrow">Staging</p>', $html );
		self::assertStringContainsString( 'Preview protection</h2>', $html );
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
		$html = $this->admin( 'preview_card_html' );
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
