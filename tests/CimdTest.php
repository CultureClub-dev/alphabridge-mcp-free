<?php
/**
 * Client ID Metadata Documents (CIMD) next to dynamic client registration.
 *
 * A client id that is an HTTPS URL names a JSON document describing the
 * client. Fetching it is the one outbound request the OAuth flow makes, so
 * every limit on that request has a test here: only https, only public
 * addresses and a connection to exactly those, no redirects, at most 5 KB, a
 * few seconds, only once a logged-in approver is on the consent page, and at
 * the token endpoint only for the client id an approver consented to. The fetched document must match
 * its own URL exactly and carry valid redirect addresses; the cached copy is
 * signed like the consent records.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\TestCase;
use AbTestExit;
use AB_MCP_OAuth;
use AB_MCP_Settings;
use WP_Error;

final class CimdTest extends TestCase {

	private const URL      = 'https://app.example/oauth/client.json';
	private const REDIRECT = 'https://app.example/callback';
	private const PUBLIC   = '93.184.215.14';
	private const VERIFIER = 'verifier-verifier-verifier-verifier-verifier-1234';

	/** @var array<string,mixed> */
	private $doc;
	/** @var array<string,string> */
	private $headers = array();
	/** @var int */
	private $status = 200;

	protected function setUp(): void {
		ab_test_reset();
		$this->doc = array(
			'client_id'                  => self::URL,
			'client_name'                => 'Example App',
			'redirect_uris'              => array( self::REDIRECT ),
			'grant_types'                => array( 'authorization_code' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'none',
		);
		// Every host resolves to a public address unless a test says otherwise.
		add_filter( 'ab_mcp_oauth_cimd_addresses', static fn(): array => array( self::PUBLIC ) );
		$GLOBALS['ab_test_http_answer'] = function (): array {
			return ab_test_http_response( $this->status, (string) wp_json_encode( $this->doc ), $this->headers );
		};
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
	}

	/** @return array|WP_Error */
	private function resolve( string $url = self::URL ) {
		return AB_MCP_OAuth::resolve_client( $url );
	}

	private function fetches(): int {
		return count( $GLOBALS['ab_test_http'] );
	}

	private function refusal( string $url = self::URL ): string {
		$client = $this->resolve( $url );
		self::assertInstanceOf( WP_Error::class, $client );
		return $client->get_error_message();
	}

	private static function key( string $url = self::URL ): string {
		return 'ab_mcp_cimd_' . substr( hash( 'sha256', $url ), 0, 40 );
	}

	/* --------------------------------------------------------- discovery */

	public function testTheServerMetadataAdvertisesBothWaysToRegister(): void {
		$meta = AB_MCP_OAuth::metadata();

		self::assertTrue( $meta['client_id_metadata_document_supported'] ?? null );
		self::assertArrayHasKey( 'registration_endpoint', $meta, 'Dynamic registration stays for clients that only know it.' );
		self::assertTrue( $meta['authorization_response_iss_parameter_supported'] );
	}

	/**
	 * @return array<string,array{0:callable}>
	 */
	public static function switchedOff(): array {
		return array(
			'setting' => array( static function (): void {
				AB_MCP_Settings::set( 'oauth_cimd', false );
			} ),
			'filter'  => array( static function (): void {
				add_filter( 'ab_mcp_oauth_cimd', '__return_false' );
			} ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'switchedOff' )]
	public function testSwitchedOffNothingIsAdvertisedOrFetched( callable $off ): void {
		$off();

		self::assertArrayNotHasKey( 'client_id_metadata_document_supported', AB_MCP_OAuth::metadata() );
		self::assertArrayHasKey( 'registration_endpoint', AB_MCP_OAuth::metadata() );
		self::assertStringContainsString( 'register', $this->refusal(), 'The refusal names the way that still works.' );
		self::assertFalse( AB_MCP_OAuth::get_client( self::URL ) );
		self::assertSame( 0, $this->fetches() );
	}

	public function testTheFilterHasTheLastWordOverTheSetting(): void {
		AB_MCP_Settings::set( 'oauth_cimd', false );
		add_filter( 'ab_mcp_oauth_cimd', '__return_true' );

		self::assertTrue( AB_MCP_OAuth::metadata()['client_id_metadata_document_supported'] ?? null );
	}

	/**
	 * A site that cannot reach other servers must not send clients down a
	 * way that fails every time: they prefer CIMD as soon as it is offered.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function testASiteThatBlocksOutgoingRequestsDoesNotOfferDocuments(): void {
		define( 'WP_HTTP_BLOCK_EXTERNAL', true );

		self::assertArrayNotHasKey( 'client_id_metadata_document_supported', AB_MCP_OAuth::metadata(), 'Registration only, as before CIMD.' );
		self::assertStringContainsString( 'WP_HTTP_BLOCK_EXTERNAL', $this->refusal(), 'The refusal names why.' );
		self::assertSame( 0, $this->fetches() );

		add_filter( 'ab_mcp_oauth_cimd', '__return_true' );
		self::assertTrue( AB_MCP_OAuth::metadata()['client_id_metadata_document_supported'] ?? null, 'A site that lists the app hosts in WP_ACCESSIBLE_HOSTS can switch it on.' );
	}

	/* ------------------------------------------------------- a good fetch */

	public function testAValidDocumentDescribesTheClient(): void {
		$client = $this->resolve();

		self::assertSame(
			array(
				'name'          => 'Example App',
				'redirect_uris' => array( self::REDIRECT ),
				'cimd'          => true,
			),
			$client
		);
		self::assertSame( $client, AB_MCP_OAuth::get_client( self::URL ) );
	}

	public function testTheFetchIsBounded(): void {
		$this->resolve();

		self::assertSame( 1, $this->fetches() );
		$call = $GLOBALS['ab_test_http'][0];
		self::assertSame( self::URL, $call['url'] );
		self::assertSame( 5, $call['args']['timeout'] );
		self::assertSame( 0, $call['args']['redirection'], 'A redirect could lead where the address check did not look.' );
		self::assertSame( 5121, $call['args']['limit_response_size'], 'One byte over 5 KB, so an oversized document shows as such.' );
		self::assertSame( 'AlphaBridge-MCP/' . AB_MCP_VERSION, $call['args']['user-agent'], 'Not WordPress\'s default, which names the site.' );
	}

	/**
	 * @return array<string,array{0:array<int,string>,1:string}>
	 */
	public static function pins(): array {
		return array(
			'one address'    => array( array( self::PUBLIC ), 'app.example:443:' . self::PUBLIC ),
			'IPv4 and IPv6'  => array( array( self::PUBLIC, '2606:2800:21f:cb07:6820:80da:af6b:8b2c' ), 'app.example:443:' . self::PUBLIC . ',2606:2800:21f:cb07:6820:80da:af6b:8b2c' ),
		);
	}

	/**
	 * cURL connects to the addresses that were checked and does not look the
	 * name up again (DNS rebinding). Observed in cURL's own log of a real
	 * handle, see wp_safe_remote_get() in the bootstrap.
	 *
	 * @param array<int,string> $addresses What the host resolves to.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'pins' )]
	#[\PHPUnit\Framework\Attributes\RequiresPhpExtension( 'curl' )]
	public function testTheConnectionGoesToTheCheckedAddresses( array $addresses, string $entry ): void {
		$GLOBALS['ab_test_filters']['ab_mcp_oauth_cimd_addresses'] = array();
		add_filter( 'ab_mcp_oauth_cimd_addresses', static fn(): array => $addresses );
		$GLOBALS['ab_test_http_curl'] = true;

		$this->resolve();

		self::assertStringContainsString( ' ' . $entry . ' ', $GLOBALS['ab_test_http'][0]['curl'] );
		self::assertFalse( has_action( 'http_api_curl' ), 'Gone after the request: no other request of the site is pinned.' );
	}

	public function testTheHookIsGoneAfterAFailedRequestToo(): void {
		$GLOBALS['ab_test_http_answer'] = static fn(): WP_Error => new WP_Error( 'http_request_failed', 'timed out' );

		$this->refusal();

		self::assertSame( 1, $this->fetches() );
		self::assertFalse( has_action( 'http_api_curl' ) );
	}

	/**
	 * @return array<string,array{0:string,1:array<int,string>,2:string|null}>
	 */
	public static function resolveEntries(): array {
		return array(
			'default port'      => array( 'https://app.example/c.json', array( '93.184.215.14' ), 'app.example:443:93.184.215.14' ),
			'own port'          => array( 'https://app.example:8080/c.json', array( '93.184.215.14' ), 'app.example:8080:93.184.215.14' ),
			'spelled as in URL' => array( 'https://App.Example/c.json', array( '93.184.215.14' ), 'App.Example:443:93.184.215.14' ),
			'IPv4 as host'      => array( 'https://93.184.215.14/c.json', array( '93.184.215.14' ), null ),
			'IPv6 as host'      => array( 'https://[2606:2800:21f:cb07:6820:80da:af6b:8b2c]/c.json', array( '2606:2800:21f:cb07:6820:80da:af6b:8b2c' ), null ),
		);
	}

	/**
	 * @param array<int,string> $addresses
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'resolveEntries' )]
	public function testTheResolveEntry( string $url, array $addresses, ?string $entry ): void {
		self::assertSame( $entry, ( new \ReflectionMethod( AB_MCP_OAuth::class, 'curl_resolve_entry' ) )->invoke( null, $url, $addresses ) );
	}

	public function testTheDocumentIsCachedAndReused(): void {
		$this->resolve();
		$this->resolve();

		self::assertSame( 1, $this->fetches(), 'Consent page, approval and token exchange need one fetch, not three.' );
		self::assertSame( 300, $GLOBALS['ab_test_transient_ttl'][ self::key() ], 'Five minutes when the response names no lifetime.' );
	}

	/**
	 * @return array<string,array{0:string,1:int}>
	 */
	public static function cacheHeaders(): array {
		return array(
			'max-age'          => array( 'public, max-age=60', 60 ),
			'max-age too long' => array( 'max-age=31536000', 86400 ),
			'no-store'         => array( 'no-store', 0 ),
			'no-cache'         => array( 'no-cache', 0 ),
			'max-age zero'     => array( 'max-age=0', 0 ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'cacheHeaders' )]
	public function testTheCacheFollowsTheResponseHeaders( string $cache_control, int $ttl ): void {
		$this->headers = array( 'Cache-Control' => $cache_control );
		$this->resolve();

		if ( 0 === $ttl ) {
			self::assertArrayNotHasKey( self::key(), $GLOBALS['ab_test_transients'] );
			$this->resolve();
			self::assertSame( 2, $this->fetches() );
			return;
		}
		self::assertSame( $ttl, $GLOBALS['ab_test_transient_ttl'][ self::key() ] );
	}

	public function testAForgedCacheEntryIsNotTrusted(): void {
		$this->resolve();
		// Someone with access to the transient store adds their own address.
		$GLOBALS['ab_test_transients'][ self::key() ]['client']['redirect_uris'][] = 'https://evil.example/steal';

		$client = $this->resolve();

		self::assertSame( array( self::REDIRECT ), $client['redirect_uris'] );
		self::assertSame( 2, $this->fetches(), 'The forged entry was discarded and the document fetched again.' );
	}

	public function testACacheEntryWithAFieldTheSignatureDoesNotCoverIsNotTrusted(): void {
		$this->resolve();
		// Correctly signed, since the signature covers name, redirect_uris
		// and expiry only; the extra field rides along unsigned.
		$GLOBALS['ab_test_transients'][ self::key() ]['client']['scope'] = 'full';

		$client = $this->resolve();

		self::assertArrayNotHasKey( 'scope', $client );
		self::assertSame( 2, $this->fetches(), 'Discarded and fetched again.' );
	}

	public function testAnExpiredCacheEntryIsNotUsedEvenIfTheStoreKeepsIt(): void {
		$this->resolve();
		// A genuine, correctly signed entry whose lifetime has run out, still
		// in a store that does not expire entries on time (some object caches).
		$entry            = $GLOBALS['ab_test_transients'][ self::key() ];
		$entry['expires'] = time() - 1;
		$entry['sig']     = ( new \ReflectionMethod( AB_MCP_OAuth::class, 'cimd_sig' ) )->invoke( null, self::key(), self::URL, $entry['client'], $entry['expires'] );
		$GLOBALS['ab_test_transients'][ self::key() ] = $entry;

		$this->resolve();

		self::assertSame( 2, $this->fetches() );
	}

	public function testAnEntryForAnotherAddressIsNotUsed(): void {
		$this->resolve();
		// Moved under the key of another client id: the signature names the key and the URL.
		$other = 'https://app.example/other.json';
		$GLOBALS['ab_test_transients'][ self::key( $other ) ] = $GLOBALS['ab_test_transients'][ self::key() ];
		$this->doc['client_id'] = $other;

		$this->resolve( $other );

		self::assertSame( 2, $this->fetches() );
	}

	/* ------------------------------------------------------- the address */

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function badUrls(): array {
		return array(
			'no path'         => array( 'https://app.example' ),
			'root path'       => array( 'https://app.example/' ),
			'fragment'        => array( 'https://app.example/client.json#x' ),
			'credentials'     => array( 'https://user:pw@app.example/client.json' ),
			'dot segment'     => array( 'https://app.example/a/../client.json' ),
			'single dot'      => array( 'https://app.example/./client.json' ),
			'space'           => array( 'https://app.example/my client.json' ),
			'too long'        => array( 'https://app.example/' . str_repeat( 'a', 2000 ) ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'badUrls' )]
	public function testAMalformedClientIdIsRefusedWithoutAFetch( string $url ): void {
		$this->doc['client_id'] = $url;

		$this->refusal( $url );
		self::assertSame( 0, $this->fetches() );
	}

	public function testOnlyHttpsUrlsCountAsMetadataDocuments(): void {
		self::assertFalse( AB_MCP_OAuth::is_cimd_client_id( 'http://app.example/client.json' ) );
		self::assertFalse( AB_MCP_OAuth::is_cimd_client_id( 'abc_0123' ) );
		self::assertTrue( AB_MCP_OAuth::is_cimd_client_id( self::URL ) );

		self::assertFalse( AB_MCP_OAuth::get_client( 'http://app.example/client.json' ), 'Unknown, as before: no registration has that id.' );
		self::assertSame( 0, $this->fetches() );
	}

	/**
	 * @return array<string,array{0:array<int,string>}>
	 */
	public static function privateAddresses(): array {
		return array(
			'loopback'               => array( array( '127.0.0.1' ) ),
			'private 10'             => array( array( '10.1.2.3' ) ),
			'private 172'            => array( array( '172.20.0.5' ) ),
			'private 192'            => array( array( '192.168.1.1' ) ),
			'cloud metadata service' => array( array( '169.254.169.254' ) ),
			'shared address space'   => array( array( '100.64.1.1' ) ),
			'this network'           => array( array( '0.0.0.0' ) ),
			'multicast'              => array( array( '224.0.0.1' ) ),
			'broadcast'              => array( array( '255.255.255.255' ) ),
			'IPv6 loopback'          => array( array( '::1' ) ),
			'IPv6 link-local'        => array( array( 'fe80::1' ) ),
			'IPv6 unique local'      => array( array( 'fd00::1' ) ),
			'IPv4 mapped loopback'   => array( array( '::ffff:127.0.0.1' ) ),
			'NAT64 of private'       => array( array( '64:ff9b::a00:1' ) ),
			'one public, one not'    => array( array( self::PUBLIC, '10.0.0.1' ) ),
			'not an address'         => array( array( 'app.example' ) ),
			'nothing resolved'       => array( array() ),
		);
	}

	/**
	 * @param array<int,string> $addresses What the host resolves to.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'privateAddresses' )]
	public function testAHostThatIsNotPublicIsNeverFetched( array $addresses ): void {
		$GLOBALS['ab_test_filters']['ab_mcp_oauth_cimd_addresses'] = array();
		add_filter( 'ab_mcp_oauth_cimd_addresses', static fn(): array => $addresses );

		$this->refusal();
		self::assertSame( 0, $this->fetches() );
	}

	public function testLocalhostIsRefusedByNameBeforeAnyLookup(): void {
		$url                    = 'https://localhost/client.json';
		$this->doc['client_id'] = $url;

		self::assertStringContainsString( 'this machine', $this->refusal( $url ) );
		self::assertSame( 0, $this->fetches() );
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function addresses(): array {
		return array(
			'public IPv4'      => array( '93.184.215.14', true ),
			'public IPv6'      => array( '2606:2800:21f:cb07:6820:80da:af6b:8b2c', true ),
			'edge of 172.16/12' => array( '172.32.0.1', true ),
			'edge of 100.64/10' => array( '100.128.0.1', true ),
			'inside 172.16/12' => array( '172.31.255.255', false ),
			'inside 100.64/10' => array( '100.127.255.255', false ),
			'documentation'    => array( '2001:db8::1', false ),
			'documentation, new' => array( '3fff::1', false ),
			'benchmarking IPv6'  => array( '2001:2::1', false ),
			'6to4'             => array( '2002:a00:1::1', false ),
			'NAT64 well-known, metadata service' => array( '64:ff9b::a9fe:a9fe', false ),
			'NAT64 local, private'               => array( '64:ff9b:1::a00:1', false ),
			'NAT64 local, metadata service'      => array( '64:ff9b:1::a9fe:a9fe', false ),
			'IPv4-compatible, private'           => array( '::a00:1', false ),
			'IPv4-compatible, loopback'          => array( '::7f00:1', false ),
			'IPv4-translated, loopback'          => array( '::ffff:0:7f00:1', false ),
			'IPv4-mapped, loopback'              => array( '::ffff:127.0.0.1', false ),
			'unspecified IPv6'                   => array( '::', false ),
			'garbage'          => array( 'not-an-ip', false ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'addresses' )]
	public function testThePublicAddressCheck( string $ip, bool $public ): void {
		self::assertSame( $public, AB_MCP_OAuth::is_public_ip( $ip ) );
	}

	/* ------------------------------------------------------- the response */

	public function testAFailedRequestIsReported(): void {
		$GLOBALS['ab_test_http_answer'] = static fn(): WP_Error => new WP_Error( 'http_request_failed', 'timed out' );

		$message = $this->refusal();
		self::assertStringContainsString( 'timed out', $message );
		self::assertStringContainsString( '"Accept apps with a metadata document"', $message, 'The refusal names the switch that lets such an app connect by registering.' );
	}

	/**
	 * @return array<string,array{0:int}>
	 */
	public static function statuses(): array {
		return array(
			'redirect'  => array( 302 ),
			'not found' => array( 404 ),
			'error'     => array( 500 ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'statuses' )]
	public function testAnythingButStatus200IsRefused( int $status ): void {
		$this->status = $status;

		self::assertStringContainsString( 'HTTP ' . $status, $this->refusal() );
	}

	public function testAnOversizedDocumentIsRefused(): void {
		$this->doc['padding'] = str_repeat( 'x', 6000 );

		self::assertStringContainsString( 'larger than 5120 bytes', $this->refusal() );
	}

	public function testADocumentJustBelowTheLimitIsAccepted(): void {
		$this->doc['padding'] = '';
		$room                 = 5120 - strlen( (string) wp_json_encode( $this->doc ) );
		$this->doc['padding'] = str_repeat( 'x', $room );

		self::assertSame( 5120, strlen( (string) wp_json_encode( $this->doc ) ) );
		self::assertIsArray( $this->resolve() );
	}

	public function testABodyThatIsNotJsonIsRefused(): void {
		$GLOBALS['ab_test_http_answer'] = static fn(): array => ab_test_http_response( 200, '<html>hello</html>' );

		self::assertStringContainsString( 'not a JSON object', $this->refusal() );
	}

	/**
	 * @return array<string,array{0:callable,1:string}>
	 */
	public static function badDocuments(): array {
		return array(
			'client_id differs'          => array( static function ( array &$d ): void { $d['client_id'] = self::URL . '?v=2'; }, 'does not match' ),
			'client_id trailing slash'   => array( static function ( array &$d ): void { $d['client_id'] = self::URL . '/'; }, 'does not match' ),
			'client_id missing'          => array( static function ( array &$d ): void { unset( $d['client_id'] ); }, 'does not match' ),
			'client secret'              => array( static function ( array &$d ): void { $d['client_secret'] = 's3cret'; }, 'client secret' ),
			'secret expiry'              => array( static function ( array &$d ): void { $d['client_secret_expires_at'] = 0; }, 'client secret' ),
			'shared secret auth'         => array( static function ( array &$d ): void { $d['token_endpoint_auth_method'] = 'client_secret_basic'; }, 'public clients' ),
			'private key auth'           => array( static function ( array &$d ): void { $d['token_endpoint_auth_method'] = 'private_key_jwt'; }, 'public clients' ),
			'no authorization_code'      => array( static function ( array &$d ): void { $d['grant_types'] = array( 'client_credentials' ); }, 'grant_types' ),
			'no code response'           => array( static function ( array &$d ): void { $d['response_types'] = array( 'token' ); }, 'response_types' ),
			'no redirect_uris'           => array( static function ( array &$d ): void { unset( $d['redirect_uris'] ); }, 'redirect_uris' ),
			'plain http redirect'        => array( static function ( array &$d ): void { $d['redirect_uris'] = array( 'http://app.example/cb' ); }, 'redirect_uris' ),
			'redirect with fragment'     => array( static function ( array &$d ): void { $d['redirect_uris'] = array( 'https://app.example/cb#x' ); }, 'redirect_uris' ),
			'no client_name'             => array( static function ( array &$d ): void { unset( $d['client_name'] ); }, 'client_name' ),
			'empty client_name'          => array( static function ( array &$d ): void { $d['client_name'] = '<b></b>'; }, 'client_name' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'badDocuments' )]
	public function testADocumentThatBreaksTheRulesIsRefused( callable $change, string $reason ): void {
		$change( $this->doc );

		$message = $this->refusal();
		self::assertStringContainsString( $reason, $message );
		self::assertStringContainsString( 'app.example', $message, 'The refusal names where the document lives.' );
		self::assertArrayNotHasKey( self::key(), $GLOBALS['ab_test_transients'], 'A refused document is not cached.' );
	}

	public function testALoopbackRedirectIsAllowedAsForRegistration(): void {
		$this->doc['redirect_uris'] = array( 'http://127.0.0.1:3000/callback', 'http://localhost:3000/callback' );

		self::assertSame( $this->doc['redirect_uris'], $this->resolve()['redirect_uris'] );
	}

	public function testTheNameIsCleanedAndCut(): void {
		$this->doc['client_name'] = '<script>x</script>' . str_repeat( 'N', 100 );

		self::assertSame( 80, strlen( $this->resolve()['name'] ) );
		self::assertStringNotContainsString( '<', $this->resolve()['name'] );
	}

	/* ------------------------------------------------------ the whole flow */

	public function testATokenIsIssuedToADocumentClient(): void {
		ab_test_add_user( 7 );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', self::VERIFIER, true ) ), '+/', '-_' ), '=' );
		$code      = AB_MCP_OAuth::create_auth_code( self::URL, self::REDIRECT, 7, 'content', $challenge );

		$out = AB_MCP_OAuth::redeem_code(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => self::URL,
				'redirect_uri'  => self::REDIRECT,
				'code_verifier' => self::VERIFIER,
			)
		);

		self::assertArrayHasKey( 'access_token', $out, print_r( $out, true ) );
		$token = AB_MCP_Settings::get_tokens()[0];
		self::assertSame( 'Example App · OAuth', $token['label'] );
		self::assertSame( self::URL, $token['client_id'], 'Reconnecting the same app is recognised by its stable id.' );
		self::assertSame( array(), get_option( AB_MCP_OAuth::OPT_CLIENTS, array() ), 'Nothing is registered: the document is the registration.' );
	}

	public function testATokenRequestWithoutAValidCodeFetchesNothing(): void {
		$out = AB_MCP_OAuth::redeem_code(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => 'abac_forged',
				'client_id'     => self::URL,
				'redirect_uri'  => self::REDIRECT,
				'code_verifier' => self::VERIFIER,
			)
		);

		self::assertSame( array( 'error' => 'invalid_grant' ), $out );
		self::assertSame( 0, $this->fetches(), 'The public token endpoint cannot be used to make the site fetch addresses.' );
	}

	public function testAValidCodeCannotMakeTheSiteFetchAnotherAddress(): void {
		ab_test_add_user( 7 );
		update_option(
			AB_MCP_OAuth::OPT_CLIENTS,
			array(
				'abc_registered' => array(
					'name'          => 'Registered',
					'redirect_uris' => array( self::REDIRECT ),
				),
			)
		);
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', self::VERIFIER, true ) ), '+/', '-_' ), '=' );
		$code      = AB_MCP_OAuth::create_auth_code( 'abc_registered', self::REDIRECT, 7, 'content', $challenge );

		$out = AB_MCP_OAuth::redeem_code(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => 'https://attacker-chosen.example/any/path',
				'redirect_uri'  => self::REDIRECT,
				'code_verifier' => self::VERIFIER,
			)
		);

		self::assertSame( array( 'error' => 'invalid_client' ), $out );
		self::assertSame( 0, $this->fetches(), 'Only the client id an approver consented to is ever looked up.' );
	}

	/**
	 * @param array<string,string> $params Query of the authorization request.
	 */
	private function authorize( array $params, string $method = 'GET' ): AbTestExit {
		$_GET                      = array_merge( array( 'ab_mcp_oauth' => 'authorize' ), $params );
		$_REQUEST                  = $_GET;
		$_SERVER['REQUEST_METHOD'] = $method;
		try {
			AB_MCP_OAuth::handle_authorize();
		} catch ( AbTestExit $exit ) {
			return $exit;
		}
		self::fail( 'handle_authorize() ended without redirect, login or error.' );
	}

	/** @return array<string,string> */
	private function request(): array {
		return array(
			'client_id'             => self::URL,
			'redirect_uri'          => self::REDIRECT,
			'response_type'         => 'code',
			'code_challenge'        => rtrim( strtr( base64_encode( hash( 'sha256', self::VERIFIER, true ) ), '+/', '-_' ), '=' ),
			'code_challenge_method' => 'S256',
			'state'                 => 'st4te',
		);
	}

	public function testAVisitorIsSentToTheLoginBeforeAnythingIsFetched(): void {
		$exit = $this->authorize( $this->request() );

		self::assertSame( 'login', $exit->kind );
		self::assertSame( 0, $this->fetches(), 'Otherwise any visitor could make the site request addresses of their choosing.' );
	}

	public function testAnApproverWithoutTheCapabilityTriggersNoFetch(): void {
		$GLOBALS['ab_test_logged_in'] = true;
		$GLOBALS['ab_test_can']       = static fn(): bool => false;

		try {
			// error_page() sends headers before wp_die(); PHP warns about them
			// in a test that has printed output. Only the outcome matters here.
			$exit = @$this->authorize( $this->request() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} finally {
			$GLOBALS['ab_test_can'] = null;
		}

		self::assertSame( 'die', $exit->kind );
		self::assertStringContainsString( 'not allowed to approve', $exit->detail );
		self::assertSame( 0, $this->fetches() );
	}

	public function testTheApprovalRedirectNamesTheIssuer(): void {
		ab_test_add_user( 7 );
		$GLOBALS['ab_test_current_user'] = 7;
		$GLOBALS['ab_test_logged_in']    = true;
		$GLOBALS['ab_test_can']          = static fn(): bool => true;
		$_POST                           = array(
			'_wpnonce' => 'nonce-ab_mcp_oauth_approve',
			'ab_scope' => 'read',
		);

		$exit = $this->authorize( $this->request(), 'POST' );

		self::assertSame( 'redirect', $exit->kind );
		self::assertStringStartsWith( self::REDIRECT . '?code=abac_', $exit->detail );
		self::assertStringContainsString( '&iss=' . rawurlencode( 'https://example.test' ) . '&', $exit->detail, 'RFC 9207: the client can tell which server answered.' );
		self::assertStringEndsWith( '&state=st4te', $exit->detail );
		self::assertSame( 1, $this->fetches() );
	}

	public function testAnErrorRedirectNamesTheIssuerToo(): void {
		$GLOBALS['ab_test_logged_in'] = true;
		$GLOBALS['ab_test_can']       = static fn(): bool => true;

		$exit = $this->authorize( array( 'response_type' => 'token' ) + $this->request() );

		self::assertSame( 'redirect', $exit->kind );
		self::assertSame( self::REDIRECT . '?error=unsupported_response_type&iss=' . rawurlencode( 'https://example.test' ) . '&state=st4te', $exit->detail );
	}

	public function testARedirectNotInTheDocumentIsNeverFollowed(): void {
		$GLOBALS['ab_test_logged_in'] = true;
		$GLOBALS['ab_test_can']       = static fn(): bool => true;

		$exit = @$this->authorize( array( 'redirect_uri' => 'https://evil.example/cb' ) + $this->request() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- error_page() sends headers, see above.

		self::assertSame( 'die', $exit->kind, 'An error page, never a redirect to an address the document does not list.' );
	}

	public function testARegisteredClientStillGoesThroughTheOldOrder(): void {
		update_option(
			AB_MCP_OAuth::OPT_CLIENTS,
			array(
				'abc_registered' => array(
					'name'          => 'Registered',
					'redirect_uris' => array( self::REDIRECT ),
				),
			)
		);

		$exit = $this->authorize( array( 'client_id' => 'abc_registered' ) + $this->request() );

		self::assertSame( 'login', $exit->kind );
		self::assertSame( 0, $this->fetches() );
	}

	public function testRegistrationKeepsItsAnswers(): void {
		$bad = AB_MCP_OAuth::register_client( array( 'redirect_uris' => array( 'http://evil.example/cb' ) ) );
		self::assertSame(
			array(
				'error'             => 'invalid_redirect_uri',
				'error_description' => 'redirect_uris must be https (or http on localhost) without fragments.',
			),
			$bad
		);
		self::assertSame( 'redirect_uris is required.', AB_MCP_OAuth::register_client( array() )['error_description'] );
		self::assertSame( 'Too many redirect_uris.', AB_MCP_OAuth::register_client( array( 'redirect_uris' => array_fill( 0, 21, self::REDIRECT ) ) )['error_description'] );

		$ok = AB_MCP_OAuth::register_client(
			array(
				'redirect_uris' => array( self::REDIRECT ),
				'client_name'   => 'Old Style',
			)
		);
		self::assertStringStartsWith( 'abc_', $ok['client_id'] );
		self::assertSame( 'Old Style', AB_MCP_OAuth::get_client( $ok['client_id'] )['name'] );
		self::assertSame( 0, $this->fetches() );
	}
}
