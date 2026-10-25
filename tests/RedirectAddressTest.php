<?php
/**
 * The redirect addresses a client may register, and the host the consent
 * screen names for them.
 *
 * wp_parse_url() and a browser read some strings differently: of
 * https://claude.ai%2Eattacker.example/cb PHP reads the host
 * claude.ai%2Eattacker.example, a browser goes to claude.ai.attacker.example,
 * and a Cyrillic letter in place of a Latin one looks the same on the
 * consent screen and leads elsewhere. The consent screen named the host PHP
 * read. Only one strict form counts now, in which both read the same; the
 * screen reads the host through the same check, and an address stored
 * before that check is refused when it is used. A backslash is refused too,
 * although wp_redirect() happens to strip it, and so is a user name: no app
 * needs one, and claude.ai@attacker.example reads to a person like
 * claude.ai. The addresses the real apps register stay accepted.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use AbTestExit;
use AB_MCP_OAuth;
use AB_MCP_Tool_Registry;
use DOMDocument;
use DOMXPath;
use ReflectionMethod;

final class RedirectAddressTest extends TestCase {

	private const VERIFIER = 'verifier-verifier-verifier-verifier-verifier-1234';

	protected function setUp(): void {
		ab_test_reset();
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
	}

	/** @return array<string,array{0:string}> */
	public static function realAddresses(): array {
		return array(
			'Claude'                   => array( 'https://claude.ai/api/mcp/auth_callback' ),
			'ChatGPT'                  => array( 'https://chatgpt.com/connector_platform_oauth_redirect' ),
			// The callback id is ChatGPT's own per connector; any id of this form.
			'ChatGPT, per connector'   => array( 'https://chatgpt.com/connector/oauth/0123abcdEF_-xyz' ),
			'AlphaBridge Connect'      => array( 'https://connect.alphabridge-mcp.com/link/callback' ),
			'MCP Inspector'            => array( 'http://localhost:6274/oauth/callback' ),
			'MCP Inspector, debug'     => array( 'http://localhost:6274/oauth/callback/debug' ),
			'loopback, any port'       => array( 'http://127.0.0.1:33418/' ),
			'loopback, highest port'   => array( 'http://127.0.0.1:65535/cb' ),
			'loopback IPv6'            => array( 'http://[::1]:8080/cb' ),
			'loopback in capitals'     => array( 'http://LOCALHOST:6274/oauth/callback' ),
			'https in capitals'        => array( 'HTTPS://app.example/cb' ),
			'with a query'             => array( 'https://app.example/cb?x=1&y=2' ),
			'host only'                => array( 'https://app.example' ),
			'usual IPv4'               => array( 'https://10.0.0.1/cb' ),
			'IPv6 as a browser writes' => array( 'https://[2001:db8::1]/cb' ),
			// Not IPv4: what decides is the last label (URL standard, "ends in a number").
			'domain starting 0x'       => array( 'https://0x123.example/cb' ),
			'domain starting 0x7f'     => array( 'https://0x7f.example.com/cb' ),
			'domain starting a digit'  => array( 'https://1password.com/cb' ),
			'domain 0x.org'            => array( 'https://0x.org/cb' ),
			'domain 123.example'       => array( 'https://123.example/cb' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'realAddresses' )]
	public function testTheAddressesOfRealAppsAreAccepted( string $uri ): void {
		$client = AB_MCP_OAuth::register_client(
			array(
				'redirect_uris' => array( $uri ),
				'client_name'   => 'App',
			)
		);

		self::assertArrayNotHasKey( 'error', $client );
		self::assertSame( array( $uri ), $client['redirect_uris'] );
		self::assertSame( array( $uri ), AB_MCP_OAuth::get_client( $client['client_id'] )['redirect_uris'] );
	}

	/** @return array<string,array{0:mixed}> */
	public static function refusedAddresses(): array {
		return array(
			'http on another host'          => array( 'http://example.com/cb' ),
			'fragment'                      => array( 'https://example.com/cb#x' ),
			'own scheme'                    => array( 'cursor://anysphere.cursor-retrieval/oauth/callback' ),
			'javascript'                    => array( 'javascript:alert(1)' ),
			'no host'                       => array( 'https:///cb' ),
			'not a string'                  => array( array( 'https://example.com' ) ),
			'too long'                      => array( 'https://example.com/' . str_repeat( 'a', 2000 ) ),
			// Forms wp_parse_url() reads differently from a browser.
			'backslash before @'            => array( 'https://attacker.example\\@claude.ai/cb' ),
			'backslash before @, loopback'  => array( 'http://attacker.example\\@localhost/cb' ),
			'backslash in the host'         => array( 'https://claude.ai\\.attacker.example/cb' ),
			'backslash in the path'         => array( 'https://claude.ai/cb\\x' ),
			'user and password'             => array( 'https://user:pw@claude.ai/cb' ),
			'user name'                     => array( 'https://user@claude.ai/cb' ),
			'space in the host'             => array( 'https://claude.ai /cb' ),
			'space in the path'             => array( 'https://claude.ai/c b' ),
			'tab in the host'               => array( "https://claude.ai\t.attacker.example/cb" ),
			'line break at the end'         => array( "https://claude.ai/cb\n" ),
			'NUL'                           => array( "https://claude.ai\0.attacker.example/cb" ),
			'DEL'                           => array( "https://claude.ai\x7F.attacker.example/cb" ),
			'percent in the host'           => array( 'https://claude.ai%2Eattacker.example/cb' ),
			'percent backslash in the host' => array( 'https://claude.ai%5C@x/' ),
			// Cyrillic es (D1 81) for the c: the screen reads claude.ai, the browser xn--laude-0ye.ai.
			'Cyrillic look-alike'           => array( "https://\xD1\x81laude.ai/cb" ),
			// Ideographic full stop (E3 80 82): a browser reads claude.ai.attacker.example.
			'ideographic full stop'         => array( "https://claude.ai\xE3\x80\x82attacker.example/cb" ),
			'port above 65535'              => array( 'https://claude.ai:65536/cb' ),
			'port above 65535, loopback'    => array( 'http://127.0.0.1:99999/cb' ),
			'IPv4 octal'                    => array( 'https://0177.0.0.1/cb' ),
			'IPv4 short form'               => array( 'https://127.1/cb' ),
			'IPv4 as one number'            => array( 'https://2130706433/cb' ),
			'IPv4 hex'                      => array( 'https://0x7f.0.0.1/cb' ),
			'IPv4 part above 255'           => array( 'https://256.1.1.1/cb' ),
			'IPv4 mixed hex'                => array( 'https://127.0x1/cb' ),
			// A browser writes [0:0:0:0:0:0:0:1] as [::1] and opens no [abcd].
			'IPv6 written out'              => array( 'https://[0:0:0:0:0:0:0:1]:8443/cb' ),
			'IPv6 loopback written out'     => array( 'http://[0:0:0:0:0:0:0:1]/cb' ),
			'IPv6 with leading zeros'       => array( 'https://[2001:0db8::1]/cb' ),
			'IPv6 with an IPv4 part'        => array( 'https://[::ffff:127.0.0.1]/cb' ),
			'not an IPv6 address'           => array( 'https://[abcd]/cb' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'refusedAddresses' )]
	public function testAnAddressABrowserCouldReadDifferentlyIsRefused( $uri ): void {
		self::assertSame(
			array(
				'error'             => 'invalid_redirect_uri',
				'error_description' => 'redirect_uris must be https (or http on localhost) without fragments.',
			),
			AB_MCP_OAuth::register_client( array( 'redirect_uris' => array( 'https://claude.ai/api/mcp/auth_callback', $uri ) ) ),
			'One bad address refuses the whole registration.'
		);
		self::assertSame( array(), get_option( AB_MCP_OAuth::OPT_CLIENTS, array() ), 'Nothing is stored.' );
	}

	public function testTheHostIsTheOneTheBrowserUses(): void {
		self::assertSame( 'claude.ai', AB_MCP_OAuth::redirect_host( 'https://claude.ai/api/mcp/auth_callback' ) );
		self::assertSame( 'chatgpt.com', AB_MCP_OAuth::redirect_host( 'https://ChatGPT.com/connector_platform_oauth_redirect' ) );
		self::assertSame( 'localhost', AB_MCP_OAuth::redirect_host( 'http://LOCALHOST:6274/cb?x=1' ) );
		self::assertSame( '[::1]', AB_MCP_OAuth::redirect_host( 'http://[::1]:8080/cb' ) );
		self::assertSame( '[2001:db8::1]', AB_MCP_OAuth::redirect_host( 'https://[2001:DB8::1]/cb' ), 'In lower case, as the browser writes it.' );
		self::assertNull( AB_MCP_OAuth::redirect_host( 'https://attacker.example\\@claude.ai/cb' ), 'wp_parse_url() reads claude.ai, a browser attacker.example.' );
		self::assertNull( AB_MCP_OAuth::redirect_host( 'https://user@claude.ai/cb' ) );
	}

	/** The consent screen for user 7 and this redirect address, as echoed. */
	private function consent_page( string $redirect ): DOMXPath {
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		$m                               = new ReflectionMethod( AB_MCP_OAuth::class, 'render_consent' );
		ob_start();
		$m->invoke(
			null,
			array( 'name' => 'Claude' ),
			array(
				'client_id'      => 'abc',
				'redirect_uri'   => $redirect,
				'code_challenge' => str_repeat( 'a', 43 ),
			),
			'st4te',
			AB_MCP_Tool_Registry::scopes()
		);
		$doc = new DOMDocument();
		$doc->loadHTML( (string) ob_get_clean(), LIBXML_NOERROR | LIBXML_NOWARNING );
		return new DOMXPath( $doc );
	}

	private static function destination( DOMXPath $x ): string {
		$b = $x->query( '//div[@class="dest"]/b' );
		self::assertSame( 1, $b->length );
		return (string) $b->item( 0 )->textContent;
	}

	private static function loopback_warnings( DOMXPath $x ): int {
		return $x->query( '//div[@class="dest"][contains(., "on your own computer")]' )->length;
	}

	public function testTheConsentScreenNamesTheHostTheBrowserGoesTo(): void {
		$claude = $this->consent_page( 'https://claude.ai/api/mcp/auth_callback' );
		self::assertSame( 'claude.ai', self::destination( $claude ) );
		self::assertSame( 0, self::loopback_warnings( $claude ) );

		$local = $this->consent_page( 'http://LOCALHOST:6274/oauth/callback' );
		self::assertSame( 'localhost', self::destination( $local ) );
		self::assertSame( 1, self::loopback_warnings( $local ), 'An app on this computer is said to be one.' );
		self::assertSame( 1, self::loopback_warnings( $this->consent_page( 'http://[::1]:8080/cb' ) ) );

		// Never the host only wp_parse_url() reads.
		self::assertSame( '(unknown)', self::destination( $this->consent_page( 'https://attacker.example\\@claude.ai/cb' ) ) );
	}

	/** A client registered with 4.6.8 or earlier, with an address of the old, loose form. */
	private function stored_client( string $redirect ): void {
		update_option(
			AB_MCP_OAuth::OPT_CLIENTS,
			array(
				'abc_registered' => array(
					'name'          => 'Claude',
					'redirect_uris' => array( $redirect ),
				),
			)
		);
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		$GLOBALS['ab_test_logged_in']    = true;
		$GLOBALS['ab_test_can']          = static fn(): bool => true;
	}

	private function authorize( string $redirect, string $method ): AbTestExit {
		$_GET                      = array(
			'ab_mcp_oauth'          => 'authorize',
			'client_id'             => 'abc_registered',
			'redirect_uri'          => $redirect,
			'response_type'         => 'code',
			'code_challenge'        => rtrim( strtr( base64_encode( hash( 'sha256', self::VERIFIER, true ) ), '+/', '-_' ), '=' ),
			'code_challenge_method' => 'S256',
			'state'                 => 'st4te',
		);
		$_REQUEST                  = $_GET;
		$_POST                     = 'POST' === $method ? array( '_wpnonce' => 'nonce-ab_mcp_oauth_approve' ) : array();
		$_SERVER['REQUEST_METHOD'] = $method;
		try {
			ob_start();
			AB_MCP_OAuth::handle_authorize();
		} catch ( AbTestExit $exit ) {
			return $exit;
		} finally {
			ob_end_clean();
		}
		self::fail( 'handle_authorize() ended without redirect, login or error.' );
	}

	/** @return array<string,array{0:string}> */
	public static function methods(): array {
		return array(
			'consent screen' => array( 'GET' ),
			'approval'       => array( 'POST' ),
		);
	}

	/**
	 * In a process of its own: without the check the request reaches the
	 * consent screen, which ends with a real exit; here that fails the test
	 * instead of ending the whole run with status 0.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'methods' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testAnAddressStoredBeforeTheStrictFormIsRefusedWhenUsed( string $method ): void {
		$redirect = 'https://attacker.example\\@claude.ai/cb';
		$this->stored_client( $redirect );

		$exit = $this->authorize( $redirect, $method );

		self::assertSame( 'die', $exit->kind, 'An error page, never a redirect to the address.' );
		self::assertSame( 'The redirect address is not registered for this client.', $exit->detail );
		self::assertSame( array(), $GLOBALS['ab_test_transients'], 'No code was issued.' );
	}

	/** The approval, since the consent screen itself ends the request with exit. */
	public function testAStoredAddressOfTheStrictFormStillGetsItsCode(): void {
		$redirect = 'https://claude.ai/api/mcp/auth_callback';
		$this->stored_client( $redirect );

		$exit = $this->authorize( $redirect, 'POST' );

		self::assertSame( 'redirect', $exit->kind );
		self::assertStringStartsWith( $redirect . '?code=', $exit->detail );
	}
}
