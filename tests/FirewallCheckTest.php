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
 * - Blocked, the ChatGPT panel and the status line say so and how to sign
 *   in all the same (add the connector again, or through the hub); unknown
 *   and open carry no note, only the last check and what it found.
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
	/** What the page says behind a firewall that blocks the direct sign-in, and the two steps. */
	private const BLOCKED = 'Your host’s firewall blocks the direct sign-in. ChatGPT and Codex register with the site instead.';
	private const STEP_1  = 'In the app, remove the connector for this site and add it again. The sign-in now goes through.';
	private const STEP_2  = 'If it still fails, add the connector once more, this time with this address instead of your site’s: <code>https://connect.alphabridge-mcp.com/mcp</code>. When you click Authenticate, the free AlphaBridge hub asks for your site’s address. Enter it and approve on your site as usual.';
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
		return $this->panelHtml( 'chatgpt' );
	}

	/** What a panel says, as text: the button «Check again» names its form
	 * (ab-firewall-form), which is no word about the firewall. */
	private function panelText( string $client ): string {
		$x    = $this->xpath( $this->call( 'connect_card_html' ) );
		$node = $x->query( '//div[@id="ab-panel-' . $client . '"]' )->item( 0 );
		self::assertNotNull( $node, $client );
		return trim( preg_replace( '/\s+/', ' ', $node->textContent ) );
	}

	/** One panel of the connect card, as HTML. */
	private function panelHtml( string $client ): string {
		$x    = $this->xpath( $this->call( 'connect_card_html' ) );
		$node = $x->query( '//div[@id="ab-panel-' . $client . '"]' )->item( 0 );
		self::assertNotNull( $node, $client );
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
		self::assertStringContainsString( self::BLOCKED, $page, 'And shows what it found.' );

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

	public function testBlockedPutsTheSentenceAndTheStepsInTheStatusLineOfTheChatGPTPanel(): void {
		self::keep( 'blocked' );
		$steps = '<ol class="ab-direct__steps"><li>' . self::STEP_1 . '</li><li>' . self::STEP_2 . '</li></ol>';
		$panel = $this->chatgptPanel();
		$x     = $this->xpath( $panel );

		self::assertSame( 1, substr_count( $panel, '<b>' . self::BLOCKED . '</b></p>' . $steps ), 'The case in bold, then the two steps: once, in the status line.' );
		self::assertSame( 1, $x->query( '//section[@id="ab-oauth-chatgpt"]//p[contains(@class,"ab-direct__status--blocked")]' )->length );
		self::assertSame( self::BLOCKED, $this->statusLine() );
		self::assertSame( 2, $x->query( '//section[@id="ab-oauth-chatgpt"]//ol[@class="ab-direct__steps"]/li' )->length, 'Two steps.' );
		self::assertSame( 1, $x->query( '//button[@form="ab-firewall-form"]' )->length, '«Check again» stays.' );
		self::assertSame( 0, $x->query( '//button[@form="ab-direct-signin-form"]' )->length, 'No link to switch the direct sign-in off: it is already out of the way.' );
		self::assertStringNotContainsString( 'Nothing to do', $panel );
		self::assertStringNotContainsString( 'firewall', $this->panelText( 'claude' ), 'Claude is not concerned.' );
	}

	public function testOpenUnknownAndNoFindingCarryNoNote(): void {
		foreach ( array( null, 'open', 'unknown' ) as $verdict ) {
			ab_test_reset();
			self::keep( $verdict );
			self::assertStringNotContainsString( 'firewall', $this->panelText( 'chatgpt' ), (string) $verdict );
			$x = $this->xpath( $this->call( 'connect_card_html' ) );
			self::assertSame( 0, $x->query( '//p[contains(@class,"ab-direct__status--blocked")]' )->length, (string) $verdict );
			self::assertStringNotContainsString( 'firewall', $this->statusLine(), (string) $verdict );
		}
	}

	public function testWithTheDirectSignInSwitchedOffNothingIsSaidAboutTheFirewall(): void {
		self::keep( 'blocked' );
		AB_MCP_Settings::set( 'oauth_cimd', false );

		self::assertStringNotContainsString( 'firewall', $this->panelText( 'chatgpt' ) );
		self::assertSame( 'You switched the direct sign-in off: ChatGPT and Codex register with the site.', $this->statusLine() );
	}

	/** The status line under the switch for the sign-in, as text. */
	private function statusLine(): string {
		$status = $this->xpath( $this->call( 'connect_card_html' ) )->query( '//section[@id="ab-oauth-chatgpt"]//p[contains(@class,"ab-direct__status")]' )->item( 0 );
		self::assertNotNull( $status );
		return trim( preg_replace( '/\s+/', ' ', $status->textContent ) );
	}

	public function testTheLinkToSwitchOffComesWithItsReasonUnlessTheFirewallAlreadyBlocks(): void {
		foreach ( array( null, 'open', 'unknown' ) as $verdict ) {
			ab_test_reset();
			self::keep( $verdict );
			$x    = $this->xpath( $this->call( 'connect_card_html' ) );
			$hint = $x->query( '//section[@id="ab-oauth-chatgpt"]//p[@class="ab-direct__switch"]' )->item( 0 );
			self::assertNotNull( $hint, (string) $verdict );
			self::assertSame( 'Switch the direct sign-in off if a sign-in ends with “Not Acceptable”, or if this site must not contact other servers.', trim( preg_replace( '/\s+/', ' ', $hint->textContent ) ), (string) $verdict );
			self::assertSame( 'off', $x->query( './/button[@form="ab-direct-signin-form"]', $hint )->item( 0 )->getAttribute( 'value' ), (string) $verdict );
		}
		self::keep( 'blocked' );
		$x = $this->xpath( $this->call( 'connect_card_html' ) );
		self::assertSame( 0, $x->query( '//section[@id="ab-oauth-chatgpt"]//p[@class="ab-direct__switch"]' )->length, 'Blocked: no link.' );
	}

	public function testTheStatusLineSaysWhatTheLastCheckFound(): void {
		self::assertSame( 'ChatGPT and Codex sign in without registering first. Not checked yet. Check again', $this->statusLine() );

		self::keep( 'unknown' );
		self::assertMatchesRegularExpression( '/^ChatGPT and Codex sign in without registering first\. Checked \d{1,2} [A-Z][a-z]{2} \d{4}, \d\d:\d\d: no clear result; this stays on\. Check again$/', $this->statusLine() );

		self::keep( 'open' );
		self::assertMatchesRegularExpression( '/^ChatGPT and Codex sign in without registering first\. Checked \d{1,2} [A-Z][a-z]{2} \d{4}, \d\d:\d\d: works\. Check again$/', $this->statusLine() );

		self::keep( 'blocked' );
		self::assertSame( self::BLOCKED, $this->statusLine(), 'The steps and «Check again» follow in lines of their own.' );
	}

	public function testTheNoteNeverShowsWhileSignInForAppsIsOff(): void {
		self::keep( 'blocked' );
		AB_MCP_Settings::set( 'oauth_enabled', false );

		self::assertStringNotContainsString( 'firewall', $this->panelText( 'chatgpt' ), 'ChatGPT is told to switch sign-in on first.' );
		self::assertStringContainsString( 'How ChatGPT signs in', $this->chatgptPanel(), 'The switch is right there.' );
	}

	/* ----------------------------------------------------------- button */

	public function testCheckAgainChecksNowAndLandsOnTheFinding(): void {
		self::keep( 'open' );
		self::answer( 406 );

		$exit = $this->post( 'handle_firewall_check', array( '_wpnonce' => 'nonce-ab_mcp_firewall_check' ) );

		self::assertSame( 'redirect', $exit->kind );
		self::assertStringContainsString( 'ab_notice=checked', $exit->detail );
		self::assertStringEndsWith( '#ab-oauth-chatgpt', $exit->detail );
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
		self::assertStringContainsString( 'Checked. The finding is shown under “How ChatGPT signs in”.', $html );
	}

	/* ---------------------------------------------------- settings card */

	public function testEachSettingSitsInThePanelOfItsAppInAFormOfItsOwn(): void {
		$html = $this->call( 'connect_card_html' );
		$x    = $this->xpath( $html );

		self::assertStringNotContainsString( 'ab-alt', $html, 'Nothing folded away: the direct way is in the open.' );
		self::assertSame( 0, $x->query( '//*[@id="ab-settings"]' )->length, 'No settings card of its own.' );
		foreach ( array(
			'ab-oauth-claude'  => array( 'How Claude signs in', 'oauth_enabled', '//div[@id="ab-panel-claude"]' ),
			'ab-oauth-chatgpt' => array( 'How ChatGPT signs in', 'oauth_enabled', '//div[@id="ab-panel-chatgpt"]' ),
			'ab-url-auth'      => array( 'Connector address with token', 'connector_url_auth_enabled', '' ),
		) as $id => list( $title, $setting, $where ) ) {
			$section = $x->query( $where . '//section[@id="' . $id . '"]' )->item( 0 );
			self::assertNotNull( $section, $id );
			self::assertSame( $title, trim( $x->query( './/h3', $section )->item( 0 )->textContent ) );
			self::assertSame( 1, $x->query( './/form', $section )->length, 'A form of its own.' );
			self::assertSame( $setting, $x->query( './/form//input[@name="setting"]', $section )->item( 0 )->getAttribute( 'value' ), 'The form names its setting.' );
			self::assertSame( 1, $x->query( './/form//input[@type="checkbox"][@name="' . $setting . '"]', $section )->length );
			self::assertSame( 'Save', trim( $x->query( './/form//button[not(@form)]', $section )->item( 0 )->textContent ) );
		}
		self::assertSame( 'cursor other', $x->query( '//section[@id="ab-url-auth"]' )->item( 0 )->getAttribute( 'data-for' ), 'With the token form: for the clients that connect with a token.' );
		self::assertSame( 0, $x->query( '//div[contains(@class,"ab-panel")]//section[@id="ab-url-auth"]' )->length, 'Not inside a panel.' );
		self::assertSame( 3, $x->query( '//section[contains(@class,"ab-setting")]' )->length, 'No fourth: the MCP revision left the page.' );
		self::assertSame( 'ab-firewall-form', $x->query( '//button[contains(.,"Check again")]' )->item( 0 )->getAttribute( 'form' ) );
		self::assertSame( 'ab-direct-signin-form', $x->query( '//button[contains(.,"Switch the direct sign-in off")]' )->item( 0 )->getAttribute( 'form' ) );
		// The status line belongs to ChatGPT alone.
		self::assertSame( 0, $x->query( '//div[@id="ab-panel-claude"]//*[contains(@class,"ab-direct")]' )->length );
		self::assertSame( 1, $x->query( '//div[@id="ab-panel-chatgpt"]//div[@class="ab-direct"]' )->length );
	}

	public function testEverySettingSaysWhatItIsFor(): void {
		$html = $this->call( 'connect_card_html' );
		$x    = $this->xpath( $html );
		$text = static function ( string $id ) use ( $x ): string {
			return trim( preg_replace( '/\s+/', ' ', $x->query( '//section[@id="' . $id . '"]//input[@type="checkbox"]/following-sibling::span' )->item( 0 )->textContent ) );
		};

		self::assertSame( 'Claude and other apps can sign in with their Connect button. You approve every connection on this site. Switch off only if no app should sign in.', $text( 'ab-oauth-claude' ) );
		self::assertSame( 'ChatGPT and other apps can sign in with their Connect button. You approve every connection on this site. Switch off only if no app should sign in.', $text( 'ab-oauth-chatgpt' ) );
		self::assertSame( 'Also offer an address that contains the token. For apps without a password field. Off by default: anyone who sees this address can use the connection, so treat it like a password.', $text( 'ab-url-auth' ) );
		// No protocol detail that helps nobody in the settings, and «On by
		// default» nowhere: only the address with the token says it is off.
		$settings = '';
		foreach ( $x->query( '//section[contains(@class,"ab-setting")]' ) as $section ) {
			$settings .= (string) $section->ownerDocument->saveHTML( $section );
		}
		foreach ( array( 'referrer', 'proxy logs', 'X-Api-Key', 'RFC', 'discovers', '2026-07-28', 'metadata document', 'On by default' ) as $jargon ) {
			self::assertStringNotContainsString( $jargon, $settings, $jargon );
		}
		self::assertSame( 1, substr_count( $settings, 'Off by default' ) );
	}

	public function testTheConnectionsCardHoldsTheTableOnly(): void {
		$html = $this->call( 'render_connections_card', array() );

		self::assertStringNotContainsString( '<details', $html );
		self::assertStringNotContainsString( '<form', $html );
		self::assertStringContainsString( 'Your connections', $html );
	}

	public function testTheWayToTheSettingIsNamedAsThePageShowsIt(): void {
		AB_MCP_Settings::set( 'oauth_enabled', false );
		foreach ( array( 'claude' => 'Claude', 'chatgpt' => 'ChatGPT' ) as $client => $name ) {
			$panel = $this->panelHtml( $client );
			self::assertStringContainsString( $name . ' connects with this site’s login and your approval (OAuth), and that is switched off here. Switch it on below.', $panel, $client );
			self::assertStringNotContainsString( 'href="#ab-oauth', $panel, 'No link: the switch is right below.' );
			self::assertLessThan( strpos( $panel, 'id="ab-oauth-' . $client . '"' ), strpos( $panel, 'Switch it on below.' ), 'Below indeed.' );
		}
	}

	public function testTheReadmeAnswersNotAcceptable(): void {
		$readme = (string) file_get_contents( AB_MCP_PATH . 'readme.txt' );
		$faq    = substr( $readme, (int) strpos( $readme, '== Frequently Asked Questions ==' ), (int) strpos( $readme, '== Screenshots ==' ) - (int) strpos( $readme, '== Frequently Asked Questions ==' ) );

		self::assertStringContainsString( '= Sign-in ends with “Not Acceptable” or mentions Mod_Security =', $faq );
		self::assertStringContainsString( "lets ChatGPT and Codex register with the site instead. Then:\n\n1. In the app, remove the connector for this site and add it again. The sign-in now goes through.\n2. If it still fails, add the connector once more, this time with this address instead of your site's: https://connect.alphabridge-mcp.com/mcp. When you click Authenticate, the free AlphaBridge hub asks for your site's address. Enter it and approve on your site as usual.\n", $faq, 'The two steps of the status line.' );
		self::assertStringContainsString( 'ChatGPT → "Switch the direct sign-in off", then add the connector again', $faq, 'The way by hand, for a site whose check does not notice the firewall.' );
		self::assertStringNotContainsString( 'othing to do', $readme, 'Nothing works by itself here.' );
		self::assertDoesNotMatchRegularExpression( '/= Sign-in ends[^=]*\d\.\d\.\d/s', $faq, 'No version number in the answer.' );
	}

	public function testTheFindingIsRemovedOnUninstall(): void {
		self::assertStringContainsString( "'ab_mcp_firewall'", (string) file_get_contents( AB_MCP_PATH . 'uninstall.php' ) );
	}
}
