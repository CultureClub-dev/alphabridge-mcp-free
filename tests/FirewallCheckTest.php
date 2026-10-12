<?php
/**
 * The firewall self-test and the settings card.
 *
 * Some hosts run a firewall that answers 406 Not Acceptable in the site's
 * place as soon as a web address stands in client_id (Bluehost with
 * Mod_Security, measured on 12.10.2026). An app with a metadata document
 * sends exactly that and never reaches the consent screen; an app that
 * registers gets through. The site asks itself once: one request to its
 * own authorization endpoint with an address as client id, the way a
 * browser would send it.
 *
 * What must hold:
 *
 * - The verdict is a pure function of the answer: the endpoint's own mark
 *   means open whatever the status; 403 or 406 without the mark means
 *   blocked; everything else (no answer, a redirect elsewhere, a 5xx, a
 *   page without the mark) means unknown, and unknown never acts like
 *   blocked.
 * - The probe goes to the site's own authorization endpoint with an
 *   address as client id and the site as redirect address, like a browser,
 *   without following redirects, without cookies, within five seconds.
 * - The finding is kept with its time; the settings page checks again only
 *   when there is none or it is older than six hours; every save of the
 *   settings and the button «Check again» check now, the button behind
 *   its nonce and the capability.
 * - Blocked, the discovery metadata stops offering the metadata document
 *   way while the setting stays on; dynamic registration stays; an app
 *   that still names a document is resolved as before (the firewall
 *   decides, not the site). Open, unknown and no finding change nothing.
 * - Blocked, the ChatGPT panel and the metadata document switch carry the
 *   note that there is nothing to do; unknown and open carry no note, only
 *   the last check and what it found.
 * - The three settings are visible, not folded away, under their fixed
 *   names, in one form with one Save, each saying when it is on and when
 *   to change it.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Admin;
use AB_MCP_OAuth;
use AB_MCP_Settings;
use AB_MCP_Tool_Registry;
use AbTestExit;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_Error;

final class FirewallCheckTest extends TestCase {

	private const DOC_URL  = 'https://app.example/client.json';
	private const REDIRECT = 'https://app.example/callback';

	protected function setUp(): void {
		ab_test_reset();
		$GLOBALS['ab_test_can'] = static fn( string $cap ): bool => 'manage_options' === $cap;
	}

	protected function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
	}

	/** The site answers its own probe like this. */
	private static function answer( int $status, array $headers = array() ): void {
		$GLOBALS['ab_test_http_answer'] = static fn(): array => ab_test_http_response( $status, '', $headers );
	}

	private static function answerWithMark( int $status ): void {
		self::answer( $status, array( AB_MCP_OAuth::MARK_HEADER => AB_MCP_OAuth::MARK_AUTHORIZE ) );
	}

	private static function noAnswer(): void {
		$GLOBALS['ab_test_http_answer'] = static fn(): WP_Error => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
	}

	private static function probes(): int {
		return count( $GLOBALS['ab_test_http'] );
	}

	/** @param mixed ...$args */
	private function call( string $method, ...$args ): string {
		$m = new ReflectionMethod( AB_MCP_Admin::class, $method );
		ob_start();
		$out    = $m->invoke( new AB_MCP_Admin( new AB_MCP_Tool_Registry() ), ...$args );
		$echoed = (string) ob_get_clean();
		return $echoed . ( is_string( $out ) ? $out : '' );
	}

	private function xpath( string $html ): DOMXPath {
		$doc = new DOMDocument();
		$doc->loadHTML( '<!doctype html><meta charset="utf-8"><body>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		return new DOMXPath( $doc );
	}

	private function chatgptPanel(): string {
		$x    = $this->xpath( $this->call( 'connect_card_html' ) );
		$node = $x->query( '//div[@id="ab-panel-chatgpt"]' )->item( 0 );
		self::assertNotNull( $node );
		return (string) $node->ownerDocument->saveHTML( $node );
	}

	/** Post to an admin-post handler; returns how the request ended. */
	private function post( string $handler, array $post ): AbTestExit {
		$_POST    = $post;
		$_REQUEST = $post;
		try {
			( new AB_MCP_Admin( new AB_MCP_Tool_Registry() ) )->$handler();
		} catch ( AbTestExit $exit ) {
			return $exit;
		}
		self::fail( $handler . ' ended without a redirect or a refusal.' );
	}

	/* ----------------------------------------------------------- verdict */

	/**
	 * @return array<string,array{0:int|null,1:string|array,2:string}>
	 */
	public static function answers(): array {
		$mark = AB_MCP_OAuth::MARK_AUTHORIZE;
		return array(
			'302 to the login, with the mark'     => array( 302, $mark, 'open' ),
			'400 error page, with the mark'       => array( 400, $mark, 'open' ),
			'200 consent screen, with the mark'   => array( 200, $mark, 'open' ),
			'403 with the mark'                   => array( 403, $mark, 'open' ),
			'406 with the mark'                   => array( 406, $mark, 'open' ),
			'the mark among several values'       => array( 302, array( 'x', $mark ), 'open' ),
			'the mark with blanks around it'      => array( 302, ' ' . $mark . ' ', 'open' ),
			'406 without the mark (Mod_Security)' => array( 406, '', 'blocked' ),
			'403 without the mark'                => array( 403, '', 'blocked' ),
			'406 with another value in the header' => array( 406, 'something', 'blocked' ),
			'no answer at all'                    => array( null, '', 'unknown' ),
			'302 elsewhere, without the mark'     => array( 302, '', 'unknown' ),
			'500 without the mark'                => array( 500, '', 'unknown' ),
			'503 without the mark'                => array( 503, '', 'unknown' ),
			'200 page without the mark'           => array( 200, '', 'unknown' ),
			'404 without the mark'                => array( 404, '', 'unknown' ),
			'429 without the mark'                => array( 429, '', 'unknown' ),
			'401 without the mark'                => array( 401, '', 'unknown' ),
		);
	}

	/**
	 * @param int|null     $status
	 * @param string|array $mark
	 */
	#[DataProvider( 'answers' )]
	public function testTheVerdictFollowsTheTable( $status, $mark, string $verdict ): void {
		self::assertSame( $verdict, AB_MCP_OAuth::firewall_verdict( $status, $mark ) );
	}

	public function testTheVerdictKnowsThreeWordsOnly(): void {
		foreach ( self::answers() as $case ) {
			self::assertContains( AB_MCP_OAuth::firewall_verdict( $case[0], $case[1] ), array( 'open', 'blocked', 'unknown' ) );
		}
	}

	/* -------------------------------------------------------------- mark */

	/**
	 * Run the authorization endpoint with these query parameters, as the
	 * probe or a visitor would; returns how it ended.
	 *
	 * @param array<string,string> $params
	 */
	private function authorize( array $params ): AbTestExit {
		$_GET                      = array_merge( array( 'ab_mcp_oauth' => 'authorize' ), $params );
		$_REQUEST                  = $_GET;
		$_SERVER['REQUEST_METHOD'] = 'GET';
		try {
			AB_MCP_OAuth::handle_authorize();
		} catch ( AbTestExit $exit ) {
			return $exit;
		} finally {
			$_GET     = array();
			$_REQUEST = array();
			unset( $_SERVER['REQUEST_METHOD'] );
		}
		self::fail( 'handle_authorize() ended without redirect, login or error.' );
	}

	/**
	 * @return array<string,array{0:array<string,string>,1:string}>
	 */
	public static function answersOfTheEndpoint(): array {
		return array(
			'the probe: an address as client id, nobody logged in' => array( array( 'client_id' => AB_MCP_OAuth::FIREWALL_PROBE_ID, 'redirect_uri' => 'https://example.test/', 'response_type' => 'code' ), 'login' ),
			'an error: no client id at all'                        => array( array(), 'die' ),
			'an error: an unknown registered client'               => array( array( 'client_id' => 'abc_unknown', 'redirect_uri' => 'https://example.test/' ), 'die' ),
		);
	}

	/** @param array<string,string> $params */
	#[DataProvider( 'answersOfTheEndpoint' )]
	public function testTheEndpointMarksItsAnswerBeforeTheAnswerBegins( array $params, string $ending ): void {
		$marks = &$this->marksRef();

		$exit = $this->authorize( $params );

		self::assertSame( $ending, $exit->kind );
		self::assertSame( array( array( 'line' => 'X-AB-MCP: authorize', 'body' => 0 ) ), $marks, 'One mark, the header line, before any body.' );
	}

	public function testTheMarkIsSentWithSignInForAppsOffToo(): void {
		AB_MCP_Settings::set( 'oauth_enabled', false );
		$marks = &$this->marksRef();

		$exit = $this->authorize( array( 'client_id' => AB_MCP_OAuth::FIREWALL_PROBE_ID ) );

		self::assertSame( 'die', $exit->kind, 'The error page: sign-in for apps is off.' );
		self::assertCount( 1, $marks, 'The firewall test is about the firewall, not about the setting.' );
	}

	public function testOtherRequestsCarryNoMark(): void {
		$marks = &$this->marksRef();

		$_GET = array( 'p' => '1' );
		AB_MCP_OAuth::handle_authorize();
		$_GET = array();

		self::assertSame( array(), $marks );
	}

	/**
	 * Every mark the endpoint sends from now on, with how much of the
	 * answer's body had gone out by then (the test's output buffer).
	 *
	 * @return array<int,array{line:string,body:int}>
	 */
	private function &marksRef(): array {
		$marks = array();
		add_action(
			'ab_mcp_oauth_mark_sent',
			static function ( string $line ) use ( &$marks ): void {
				$marks[] = array(
					'line' => $line,
					'body' => (int) ob_get_length(),
				);
			}
		);
		return $marks;
	}

	/* ------------------------------------------------------------- probe */

	public function testTheProbeIsTheSitesOwnAuthorizeEndpointWithAnAddressAsClientId(): void {
		self::answerWithMark( 302 );
		// The admin's own login cookie must not travel with the probe.
		$_COOKIE['wordpress_logged_in_abc'] = 'admin|...';

		try {
			AB_MCP_OAuth::run_firewall_check();
		} finally {
			$_COOKIE = array();
		}

		self::assertSame( 1, self::probes() );
		$call = $GLOBALS['ab_test_http'][0];
		self::assertSame( home_url( '/?ab_mcp_oauth=authorize&client_id=https%3A%2F%2Fexample.com%2Fclient.json&redirect_uri=' . rawurlencode( home_url( '/' ) ) . '&response_type=code' ), $call['url'] );
		self::assertSame( AB_MCP_OAuth::FIREWALL_PROBE_ID, 'https://example.com/client.json' );
		self::assertSame( 5, $call['args']['timeout'] );
		self::assertSame( 0, $call['args']['redirection'], 'The answer with the mark is the redirect itself.' );
		self::assertSame( array(), $call['args']['cookies'], 'Without a login the endpoint never loads the document.' );
		self::assertStringStartsWith( 'Mozilla/5.0 ', $call['args']['user-agent'], 'Like a browser: a firewall may let the site’s own user agent pass.' );
		self::assertFalse( $call['args']['sslverify'], 'As WordPress checks its own site (https_local_ssl_verify).' );
		self::assertArrayNotHasKey( 'Authorization', $call['args']['headers'] );
	}

	public function testTheProbeCarriesTheBasicLoginOfAStagingSite(): void {
		self::answerWithMark( 302 );
		$_SERVER['PHP_AUTH_USER'] = 'staging';
		$_SERVER['PHP_AUTH_PW']   = 'secret';

		AB_MCP_OAuth::run_firewall_check();

		self::assertSame( 'Basic ' . base64_encode( 'staging:secret' ), $GLOBALS['ab_test_http'][0]['args']['headers']['Authorization'] );
	}

	public function testTheFilterForLocalCertificatesHasTheLastWordOnSslverify(): void {
		self::answerWithMark( 302 );
		add_filter( 'https_local_ssl_verify', '__return_true' );

		AB_MCP_OAuth::run_firewall_check();

		self::assertTrue( $GLOBALS['ab_test_http'][0]['args']['sslverify'] );
	}

	/* ----------------------------------------------------------- finding */

	public function testTheFindingIsKeptWithItsStatusAndTime(): void {
		self::answer( 406 );
		$before = time();

		$finding = AB_MCP_OAuth::run_firewall_check();

		self::assertSame( 'blocked', $finding['verdict'] );
		self::assertSame( 406, $finding['status'] );
		self::assertGreaterThanOrEqual( $before, $finding['checked'] );
		self::assertSame( $finding, AB_MCP_OAuth::firewall_check(), 'Read back from the option.' );
		self::assertSame( $finding, get_option( 'ab_mcp_firewall' ) );
		self::assertTrue( AB_MCP_OAuth::firewall_blocks_url_client_ids() );
	}

	public function testNoAnswerIsUnknownWithoutAStatusAndNeverBlocked(): void {
		self::noAnswer();

		$finding = AB_MCP_OAuth::run_firewall_check();

		self::assertSame( 'unknown', $finding['verdict'] );
		self::assertNull( $finding['status'] );
		self::assertFalse( AB_MCP_OAuth::firewall_blocks_url_client_ids() );
	}

	public function testBeforeTheFirstCheckThereIsNoFindingAndNothingIsBlocked(): void {
		self::assertNull( AB_MCP_OAuth::firewall_check() );
		self::assertFalse( AB_MCP_OAuth::firewall_blocks_url_client_ids() );
	}

	public function testADamagedFindingIsReadAsUnknown(): void {
		update_option( 'ab_mcp_firewall', array( 'verdict' => 'blocked!', 'checked' => time() ) );
		self::assertSame( 'unknown', AB_MCP_OAuth::firewall_check()['verdict'] );
		self::assertFalse( AB_MCP_OAuth::firewall_blocks_url_client_ids() );

		update_option( 'ab_mcp_firewall', 'blocked' );
		self::assertNull( AB_MCP_OAuth::firewall_check() );
		self::assertFalse( AB_MCP_OAuth::firewall_blocks_url_client_ids() );
	}

	public function testTheLatestCheckReplacesTheFinding(): void {
		self::answer( 406 );
		AB_MCP_OAuth::run_firewall_check();
		self::assertTrue( AB_MCP_OAuth::firewall_blocks_url_client_ids() );

		self::answerWithMark( 302 );
		AB_MCP_OAuth::run_firewall_check();
		self::assertFalse( AB_MCP_OAuth::firewall_blocks_url_client_ids() );
		self::assertSame( 'open', AB_MCP_OAuth::firewall_check()['verdict'] );
	}

	/* --------------------------------------------------------- when due */

	public function testWithoutAFindingTheSettingsPageChecks(): void {
		self::answerWithMark( 302 );

		$finding = AB_MCP_OAuth::firewall_check_if_due();

		self::assertSame( 1, self::probes() );
		self::assertSame( 'open', $finding['verdict'] );
	}

	public function testAFreshFindingIsNotCheckedAgain(): void {
		self::answer( 406 );
		$kept = AB_MCP_OAuth::run_firewall_check();

		self::assertSame( $kept, AB_MCP_OAuth::firewall_check_if_due() );
		self::assertSame( 1, self::probes(), 'The probe of run_firewall_check() only.' );
	}

	public function testAFindingOlderThanSixHoursIsCheckedAgain(): void {
		self::answer( 406 );
		update_option(
			'ab_mcp_firewall',
			array(
				'verdict' => 'open',
				'status'  => 302,
				'checked' => time() - 6 * HOUR_IN_SECONDS - 1,
			)
		);

		$finding = AB_MCP_OAuth::firewall_check_if_due();

		self::assertSame( 1, self::probes() );
		self::assertSame( 'blocked', $finding['verdict'] );
		self::assertSame( 6 * HOUR_IN_SECONDS, AB_MCP_OAuth::FIREWALL_TTL );
	}

	public function testAFindingJustUnderSixHoursOldStillHolds(): void {
		update_option(
			'ab_mcp_firewall',
			array(
				'verdict' => 'blocked',
				'status'  => 406,
				'checked' => time() - 6 * HOUR_IN_SECONDS + 60,
			)
		);

		self::assertSame( 'blocked', AB_MCP_OAuth::firewall_check_if_due()['verdict'] );
		self::assertSame( 0, self::probes() );
	}

	public function testTheSettingsPageChecksWhenItLoads(): void {
		self::answer( 406 );
		$r = new AB_MCP_Tool_Registry();
		$r->set_current_group( 'content', 'Posts & Pages' );
		$r->register( 'wp_list_posts', array( 'description' => 'List posts.' ) );

		ob_start();
		( new AB_MCP_Admin( $r ) )->render();
		$page = (string) ob_get_clean();

		self::assertSame( 1, self::probes(), 'No finding yet: the page checks.' );
		self::assertStringContainsString( 'Your host’s firewall blocks app IDs in address form.', $page, 'And shows what it found.' );

		ob_start();
		( new AB_MCP_Admin( $r ) )->render();
		ob_end_clean();
		self::assertSame( 1, self::probes(), 'A fresh finding: the page does not check again.' );
	}

	/* --------------------------------------------------------- discovery */

	/**
	 * @return array<string,array{0:string|null,1:bool}>
	 */
	public static function findings(): array {
		return array(
			'no finding' => array( null, true ),
			'open'       => array( 'open', true ),
			'unknown'    => array( 'unknown', true ),
			'blocked'    => array( 'blocked', false ),
		);
	}

	private static function keep( ?string $verdict ): void {
		if ( null === $verdict ) {
			return;
		}
		update_option(
			'ab_mcp_firewall',
			array(
				'verdict' => $verdict,
				'status'  => 'blocked' === $verdict ? 406 : ( 'open' === $verdict ? 302 : null ),
				'checked' => time(),
			)
		);
	}

	#[DataProvider( 'findings' )]
	public function testOnlyBlockedTakesTheMetadataDocumentWayOutOfTheDiscovery( ?string $verdict, bool $offered ): void {
		self::keep( $verdict );
		self::assertTrue( AB_MCP_Settings::get( 'oauth_cimd', true ), 'The setting is on.' );

		$meta = AB_MCP_OAuth::metadata();

		self::assertSame( $offered, isset( $meta['client_id_metadata_document_supported'] ), (string) $verdict );
		self::assertArrayHasKey( 'registration_endpoint', $meta, 'Dynamic registration always.' );
		self::assertSame( 0, self::probes(), 'The discovery only reads the finding; a public request never makes the site send requests.' );
	}

	public function testBlockedLeavesTheSettingOnAndTheCodeWayUntouched(): void {
		self::keep( 'blocked' );
		add_filter( 'ab_mcp_oauth_cimd_addresses', static fn(): array => array( '93.184.215.14' ) );
		$GLOBALS['ab_test_http_answer'] = static fn(): array => ab_test_http_response(
			200,
			(string) wp_json_encode(
				array(
					'client_id'                  => self::DOC_URL,
					'client_name'                => 'Example App',
					'redirect_uris'              => array( self::REDIRECT ),
					'grant_types'                => array( 'authorization_code' ),
					'response_types'             => array( 'code' ),
					'token_endpoint_auth_method' => 'none',
				)
			)
		);

		self::assertTrue( AB_MCP_OAuth::cimd_enabled(), 'The setting is untouched: the firewall decides, not the site.' );
		self::assertArrayNotHasKey( 'client_id_metadata_document_supported', AB_MCP_OAuth::metadata() );
		$client = AB_MCP_OAuth::resolve_client( self::DOC_URL );
		self::assertIsArray( $client, 'An app that still names a document is resolved as before.' );
		self::assertSame( 'Example App', $client['name'] );
	}

	public function testTheSettingOffStillWinsWhateverTheFinding(): void {
		self::keep( 'open' );
		AB_MCP_Settings::set( 'oauth_cimd', false );

		self::assertArrayNotHasKey( 'client_id_metadata_document_supported', AB_MCP_OAuth::metadata() );
	}

	/* ------------------------------------------------------------ notes */

	public function testBlockedPutsTheNoteOnTheChatGPTPanelAndNextToTheSwitch(): void {
		self::keep( 'blocked' );
		$note = 'Your host’s firewall blocks app IDs in address form. Apps register with the site instead. Nothing to do.';

		self::assertStringContainsString( $note, $this->chatgptPanel() );
		$x = $this->xpath( $this->call( 'render_settings_card' ) );
		self::assertSame( 1, $x->query( '//section[@id="ab-oauth"]//p[contains(@class,"ab-firewall__blocked")]' )->length );
		self::assertStringContainsString( $note, $x->query( '//section[@id="ab-oauth"]' )->item( 0 )->textContent );
		self::assertStringContainsString( 'the firewall blocks them, see above.', $x->query( '//section[@id="ab-oauth"]' )->item( 0 )->textContent );
	}

	public function testOpenUnknownAndNoFindingCarryNoNote(): void {
		foreach ( array( null, 'open', 'unknown' ) as $verdict ) {
			ab_test_reset();
			self::keep( $verdict );
			self::assertStringNotContainsString( 'firewall', $this->chatgptPanel(), (string) $verdict );
			$x = $this->xpath( $this->call( 'render_settings_card' ) );
			self::assertSame( 0, $x->query( '//p[contains(@class,"ab-firewall__blocked")]' )->length, (string) $verdict );
			self::assertStringNotContainsString( 'Nothing to do', $x->query( '//section[@id="ab-oauth"]' )->item( 0 )->textContent, (string) $verdict );
		}
	}

	public function testTheLastCheckIsShownWithWhatItFound(): void {
		$line = fn(): string => trim( $this->xpath( $this->call( 'render_settings_card' ) )->query( '//p[contains(@class,"ab-firewall__check")]' )->item( 0 )->textContent );

		self::assertStringStartsWith( 'Not checked yet.', $line() );

		self::keep( 'unknown' );
		self::assertMatchesRegularExpression( '/^Last check \d{4}-\d\d-\d\d \d\d:\d\d: no clear result; app IDs in address form are offered as before\./', $line() );

		self::keep( 'open' );
		self::assertMatchesRegularExpression( '/^Last check \d{4}-\d\d-\d\d \d\d:\d\d: app IDs in address form reach the site\./', $line() );

		self::keep( 'blocked' );
		self::assertMatchesRegularExpression( '/^Last check \d{4}-\d\d-\d\d \d\d:\d\d: the firewall blocks them, see above\./', $line() );
	}

	public function testTheNoteNeverShowsWhileSignInForAppsIsOff(): void {
		self::keep( 'blocked' );
		AB_MCP_Settings::set( 'oauth_enabled', false );

		self::assertStringNotContainsString( 'firewall', $this->chatgptPanel(), 'ChatGPT is told to switch sign-in on first.' );
		self::assertStringContainsString( 'Sign-in for apps', $this->chatgptPanel() );
	}

	/* ----------------------------------------------------------- button */

	public function testCheckAgainChecksNowAndLandsOnTheFinding(): void {
		self::keep( 'open' );
		self::answer( 406 );

		$exit = $this->post( 'handle_firewall_check', array( '_wpnonce' => 'nonce-ab_mcp_firewall_check' ) );

		self::assertSame( 'redirect', $exit->kind );
		self::assertStringContainsString( 'ab_notice=checked', $exit->detail );
		self::assertStringEndsWith( '#ab-oauth', $exit->detail );
		self::assertSame( 1, self::probes() );
		self::assertSame( 'blocked', AB_MCP_OAuth::firewall_check()['verdict'] );
	}

	/**
	 * @return array<string,array{0:array<string,string>}>
	 */
	public static function foreignNonces(): array {
		return array(
			'no nonce'                  => array( array() ),
			'an empty nonce'            => array( array( '_wpnonce' => '' ) ),
			'the nonce of the settings' => array( array( '_wpnonce' => 'nonce-ab_mcp_connection_settings' ) ),
		);
	}

	/** @param array<string,string> $post */
	#[DataProvider( 'foreignNonces' )]
	public function testCheckAgainNeedsItsOwnNonce( array $post ): void {
		self::keep( 'open' );
		self::answer( 406 );

		$exit = $this->post( 'handle_firewall_check', $post );

		self::assertSame( 'die', $exit->kind );
		self::assertSame( 0, self::probes() );
		self::assertSame( 'open', AB_MCP_OAuth::firewall_check()['verdict'], 'Unchanged.' );
	}

	public function testOnlyAnAdministratorCanCheckAgain(): void {
		$GLOBALS['ab_test_can'] = static fn(): bool => false;
		self::answer( 406 );

		$exit = $this->post( 'handle_firewall_check', array( '_wpnonce' => 'nonce-ab_mcp_firewall_check' ) );

		self::assertSame( 'die', $exit->kind );
		self::assertSame( 0, self::probes() );
	}

	public function testTheCheckedNoticeIsShown(): void {
		$_GET['ab_notice'] = 'checked';
		try {
			$html = $this->call( 'notice' );
		} finally {
			$_GET = array();
		}
		self::assertStringContainsString( 'Checked. The finding is shown under “Sign-in for apps”.', $html );
	}

	/* ---------------------------------------------------- settings card */

	public function testTheThreeSettingsAreVisibleUnderTheirNamesInOneForm(): void {
		$html = $this->call( 'render_settings_card' );
		$x    = $this->xpath( $html );

		self::assertStringNotContainsString( '<details', $html, 'Nothing folded away.' );
		self::assertStringNotContainsString( 'advanced', $html );
		self::assertSame( 1, $x->query( '//form[@id="ab-settings-form"]' )->length );
		foreach ( array(
			'ab-oauth'    => 'Sign-in for apps',
			'ab-url-auth' => 'Connector URL with token',
			'ab-protocol' => 'MCP protocol 2026-07-28',
		) as $id => $title ) {
			$section = $x->query( '//form[@id="ab-settings-form"]//section[@id="' . $id . '"]' )->item( 0 );
			self::assertNotNull( $section, $id );
			self::assertSame( $title, trim( $x->query( './/h3', $section )->item( 0 )->textContent ) );
		}
		self::assertSame( 1, $x->query( '//form[@id="ab-settings-form"]//button[not(@form)]' )->length, 'One Save.' );
		self::assertSame( 'Save', trim( $x->query( '//form[@id="ab-settings-form"]//button[not(@form)]' )->item( 0 )->textContent ) );
		self::assertSame( 'ab-firewall-form', $x->query( '//button[contains(.,"Check again")]' )->item( 0 )->getAttribute( 'form' ) );
	}

	public function testEverySettingSaysWhatItIsForAndWhenToChangeIt(): void {
		$x = $this->xpath( $this->call( 'render_settings_card' ) );
		$text = static fn( string $name ): string => $x->query( '//input[@name="' . $name . '"]/following-sibling::span' )->item( 0 )->textContent;

		self::assertStringContainsString( 'Let apps sign in with their Connect button. On by default. Claude, ChatGPT and other apps connect', $text( 'oauth_enabled' ) );
		self::assertStringContainsString( 'Switch it off only if no app should sign in at all.', $text( 'oauth_enabled' ) );
		self::assertStringContainsString( 'Accept apps with a metadata document. On by default. Some apps (ChatGPT, Codex)', $text( 'oauth_cimd' ) );
		self::assertStringContainsString( 'Switch it off if the sign-in ends with “Not Acceptable”', $text( 'oauth_cimd' ) );
		self::assertStringContainsString( 'Also offer a connector URL that carries the token. Off by default. For apps that have no password field', $text( 'connector_url_auth_enabled' ) );
		self::assertStringContainsString( 'treated like a password', $text( 'connector_url_auth_enabled' ) );
		self::assertStringContainsString( 'Answer apps in the MCP revision 2026-07-28. On by default.', $text( 'modern_protocol' ) );
		self::assertStringContainsString( 'Switch it off only if a proxy', $text( 'modern_protocol' ) );
		// No protocol detail that helps nobody on this page.
		foreach ( array( 'referrer', 'proxy logs', 'X-Api-Key', 'RFC', 'discovers' ) as $jargon ) {
			self::assertStringNotContainsString( $jargon, $this->call( 'render_settings_card' ), $jargon );
		}
	}

	public function testTheConnectionsCardHoldsTheTableOnly(): void {
		$html = $this->call( 'render_connections_card', array() );

		self::assertStringNotContainsString( '<details', $html );
		self::assertStringNotContainsString( '<form', $html );
		self::assertStringContainsString( 'Your connections', $html );
	}

	public function testTheWayToTheSettingIsNamedAsThePageShowsIt(): void {
		AB_MCP_Settings::set( 'oauth_enabled', false );
		$panel = $this->chatgptPanel();

		self::assertStringContainsString( 'Switch it on under “Sign-in for apps”, further down this page.', $panel );
		self::assertStringContainsString( 'href="#ab-oauth"', $panel );
	}

	public function testTheReadmeAnswersNotAcceptable(): void {
		$readme = (string) file_get_contents( AB_MCP_PATH . 'readme.txt' );
		$faq    = substr( $readme, (int) strpos( $readme, '== Frequently Asked Questions ==' ), (int) strpos( $readme, '== Screenshots ==' ) - (int) strpos( $readme, '== Frequently Asked Questions ==' ) );

		self::assertStringContainsString( '= Sign-in ends with “Not Acceptable” or mentions Mod_Security =', $faq );
		self::assertStringContainsString( 'lets apps register with the site instead', $faq );
		self::assertStringContainsString( '"Sign-in for apps" → untick "Accept apps with a metadata document", save, and create the connector in the app again', $faq );
		self::assertDoesNotMatchRegularExpression( '/= Sign-in ends[^=]*\d\.\d\.\d/s', $faq, 'No version number in the answer.' );
	}

	public function testTheFindingIsRemovedOnUninstall(): void {
		self::assertStringContainsString( "'ab_mcp_firewall'", (string) file_get_contents( AB_MCP_PATH . 'uninstall.php' ) );
	}
}
