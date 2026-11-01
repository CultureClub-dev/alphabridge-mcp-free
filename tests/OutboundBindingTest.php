<?php
/**
 * Every address the site fetches on request goes through AB_MCP_Outbound
 * (4.15.0): a file for the media library from an address or from the chat,
 * a ZIP to install, and the metadata document of an app (CimdTest). Here:
 * the redirects get() follows itself, each target made absolute, checked
 * and tied like the first address; and for each of the three download paths
 * that the connection is tied to the checked addresses (cURL's own log of a
 * real handle, see wp_safe_remote_get() in the bootstrap), that a host with
 * any address that is not public is not asked, that nothing goes through a
 * proxy of WordPress or of the environment, that nothing is sent without
 * cURL, and what each refusal tells the person.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Outbound;
use AB_MCP_Tools_Install_Update;
use AB_MCP_Tools_Media;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_Error;

require_once __DIR__ . '/media-stubs.php';
require_once __DIR__ . '/../includes/tools/class-tools-install-update.php';

final class OutboundBindingTest extends TestCase {

	const V4 = '93.184.216.34';
	const V6 = '2606:2800:220:1:248:1893:25c8:1946';

	/** What each test host resolves to (ab_mcp_outbound_addresses); any other name has no address. */
	const DNS = array(
		'files.example'     => array( self::V4, self::V6 ),
		'www.files.example' => array( self::V4 ),
		'cdn.example'       => array( self::V4 ),
		'mixed.example'     => array( self::V4, 'fd00::1' ),
		'mapped.example'    => array( '::ffff:127.0.0.1' ),
		'inside.example'    => array( '10.0.0.5' ),
	);

	const PNG = "\x89PNG\r\n\x1a\n-test-image-bytes";
	const ZIP = "PK\x03\x04-test-zip-bytes";

	/** The settings page, where AB_MCP_Guidance::compose() leads. */
	const SETTINGS = 'https://example.test/wp-admin/admin.php?page=alphabridge-connection';

	/** @var array<string,array|WP_Error|callable> The answer for each address, or a callable that answers from the request arguments. */
	private array $answers = array();

	protected function setUp(): void {
		ab_test_reset();
		$GLOBALS['ab_test_can']            = static fn(): bool => true;
		$GLOBALS['ab_test_sideloads']      = array();
		$GLOBALS['ab_test_sideload_error'] = false;
		$GLOBALS['ab_test_editor']         = null;
		$GLOBALS['ab_test_attached_files'] = array();
		$GLOBALS['ab_test_tempfiles']      = array();
		$GLOBALS['ab_test_query']          = static fn(): array => array();
		add_filter( 'ab_mcp_outbound_addresses', static fn( $found, string $host ) => self::DNS[ $host ] ?? array(), 10, 2 );
		$this->answers                  = array();
		$GLOBALS['ab_test_http_answer'] = function ( string $url, array $args ) {
			$answer = $this->answers[ $url ] ?? ab_test_http_response( 404, 'no such address' );
			return is_callable( $answer ) ? $answer( $args ) : $answer;
		};
	}

	protected function tearDown(): void {
		foreach ( $GLOBALS['ab_test_tempfiles'] ?? array() as $file ) {
			@unlink( $file );
		}
		$GLOBALS['ab_test_tempfiles'] = array();
		$GLOBALS['ab_test_query']     = null;
		$GLOBALS['ab_test_editor']    = null;
	}

	/* ------------------------------------------------------------- helpers */

	private function serve( string $url, string $body, string $type ): void {
		$this->answers[ $url ] = ab_test_http_response( 200, $body, array( 'Content-Type' => $type ) );
	}

	private function redirect( string $url, string $to, int $status = 302 ): void {
		$this->answers[ $url ] = ab_test_http_response( $status, '', array( 'Location' => $to ) );
	}

	/** The addresses asked, in order. */
	private static function urls(): array {
		return array_column( $GLOBALS['ab_test_http'], 'url' );
	}

	private static function code( $result ): string {
		return is_wp_error( $result ) ? (string) $result->get_error_code() : '';
	}

	private static function message( $result ): string {
		self::assertInstanceOf( WP_Error::class, $result );
		return (string) $result->get_error_message();
	}

	/** Proxy settings as in wp-config.php; constants, so each test in a process of its own. */
	private static function proxy( string $bypass = '' ): void {
		\define( 'WP_PROXY_HOST', 'proxy.example' );
		\define( 'WP_PROXY_PORT', '3128' );
		if ( '' !== $bypass ) {
			\define( 'WP_PROXY_BYPASS_HOSTS', $bypass );
		}
	}

	/**
	 * A hook after the one of AB_MCP_Outbound that starts the request's own
	 * handle towards this machine, with the environment's proxy set to a
	 * port that refuses: the verbose log says whether cURL tried the proxy.
	 *
	 * @param string[] $logs Where each handle's log goes.
	 */
	private static function probe_environment_proxy( array &$logs ): void {
		add_action(
			'http_api_curl',
			static function ( $handle ) use ( &$logs ): void {
				$log = fopen( 'php://temp', 'w+' );
				putenv( 'http_proxy=http://127.0.0.1:9' );
				try {
					curl_setopt_array(
						$handle,
						array(
							CURLOPT_URL               => 'http://pin.example:80/',
							CURLOPT_RESOLVE           => array( 'pin.example:80:127.0.0.1' ),
							CURLOPT_VERBOSE           => true,
							CURLOPT_STDERR            => $log,
							CURLOPT_RETURNTRANSFER    => true,
							CURLOPT_CONNECTTIMEOUT_MS => 500,
							CURLOPT_TIMEOUT_MS        => 1000,
						)
					);
					curl_exec( $handle );
					rewind( $log );
					$logs[] = (string) stream_get_contents( $log );
				} finally {
					putenv( 'http_proxy' );
					fclose( $log );
				}
			},
			20
		);
	}

	/** @param string[] $logs */
	private static function assertNoEnvironmentProxy( array $logs, int $count ): void {
		self::assertCount( $count, $logs, 'Every request ran through cURL with the hook.' );
		foreach ( $logs as $verbose ) {
			self::assertStringNotContainsString( 'http_proxy', $verbose, 'cURL names an environment proxy it uses; it uses none.' );
			self::assertStringNotContainsString( '127.0.0.1:9', $verbose );
		}
	}

	/* ------------------------------------------------- the three paths */

	/** @return array<string,array{0:string}> */
	public static function paths(): array {
		return array(
			'a file from an address' => array( 'media' ),
			'a file from the chat'   => array( 'chat' ),
			'a ZIP to install'       => array( 'zip' ),
		);
	}

	/** The address a path is asked to fetch. */
	private static function address( string $path, string $host = 'files.example', string $dir = '' ): string {
		return 'https://' . $host . $dir . ( 'zip' === $path ? '/f.zip' : '/f.png' );
	}

	/** Runs the path for an address and returns what the tool answers. */
	private function fetch( string $path, string $url ) {
		switch ( $path ) {
			case 'media':
				return AB_MCP_Tools_Media::upload_from_url( array( 'url' => $url ) );
			case 'chat':
				return AB_MCP_Tools_Media::upload_base64(
					array(
						'file' => array(
							'download_url' => $url,
							'file_id'      => 'file_abc',
							'file_name'    => 'photo.png',
							'mime_type'    => 'image/png',
						),
					)
				);
			default:
				return ( new ReflectionMethod( AB_MCP_Tools_Install_Update::class, 'package_from_args' ) )->invoke( null, array( 'url' => $url ) );
		}
	}

	/** Serves at an address what the path stores. */
	private function serve_file( string $path, string $url ): void {
		if ( 'zip' === $path ) {
			$this->serve( $url, self::ZIP, 'application/zip' );
		} else {
			$this->serve( $url, self::PNG, 'image/png' );
		}
	}

	/** The way each path's refusal names besides the address itself. */
	private static function alternative( string $path ): string {
		return 'zip' === $path ? 'base64-encoded as "content"' : 'wp_upload_media';
	}

	private static function assertStored( string $path, $result ): void {
		if ( 'zip' === $path ) {
			self::assertIsString( $result, self::code( $result ) );
			self::assertSame( self::ZIP, file_get_contents( $result ), 'The package as served.' );
			return;
		}
		self::assertTrue( $result['uploaded'] ?? null, self::code( $result ) );
		self::assertSame( self::PNG, $GLOBALS['ab_test_sideloads'][0]['bytes'], 'The file as served.' );
	}

	private static function assertNothingStored(): void {
		self::assertSame( array(), $GLOBALS['ab_test_sideloads'] );
		self::assertSame( array(), $GLOBALS['ab_test_tempfiles'] );
	}

	#[DataProvider( 'paths' )]
	public function testAHostWithAnyAddressThatIsNotPublicIsNotAsked( string $path ): void {
		foreach ( array( 'mixed.example', 'mapped.example', 'inside.example' ) as $host ) {
			$url = self::address( $path, $host );
			$this->serve_file( $path, $url );
			$result = $this->fetch( $path, $url );
			self::assertSame( 'ab_mcp_blocked_url', self::code( $result ), $host );
			self::assertStringContainsString( 'not public', self::message( $result ) );
			self::assertStringContainsString( self::alternative( $path ), self::message( $result ) );
		}
		self::assertSame( array(), $GLOBALS['ab_test_http'], 'Nothing was requested: one internal address is enough, the AAAA record included.' );
		self::assertNothingStored();
	}

	#[DataProvider( 'paths' )]
	public function testANameWithoutAnAddressIsNotAskedAndSaysSo( string $path ): void {
		$url    = self::address( $path, 'nowhere.example' );
		$result = $this->fetch( $path, $url );
		self::assertSame( 'ab_mcp_fetch_failed', self::code( $result ) );
		self::assertStringContainsString( 'has no address', self::message( $result ) );
		self::assertStringContainsString( 'Check the spelling', self::message( $result ) );
		self::assertSame( array(), $GLOBALS['ab_test_http'] );
	}

	#[DataProvider( 'paths' )]
	#[RequiresPhpExtension( 'curl' )]
	public function testTheConnectionIsTiedToTheCheckedAddresses( string $path ): void {
		$url = self::address( $path );
		$this->serve_file( $path, $url );
		$GLOBALS['ab_test_http_curl'] = true;
		$result                       = $this->fetch( $path, $url );
		self::assertStored( $path, $result );
		self::assertCount( 1, $GLOBALS['ab_test_http'] );
		self::assertStringContainsString( ' files.example:443:' . self::V4 . ',' . self::V6 . ' ', $GLOBALS['ab_test_http'][0]['curl'], 'cURL took the checked addresses, IPv4 and IPv6, and looked nothing up.' );
		self::assertFalse( has_action( 'http_api_curl' ), 'Taken off after the request: no other request of the site is tied.' );
		self::assertFalse( has_action( 'requests-fsockopen.before_request' ) );
	}

	#[DataProvider( 'paths' )]
	public function testTheLimitsOfThePathGoWithTheRequestAndAnErrorOfTheTransportComesBackAsItIs( string $path ): void {
		$url = self::address( $path );
		$this->serve_file( $path, $url );
		$this->fetch( $path, $url );
		$args = $GLOBALS['ab_test_http'][0]['args'];
		self::assertSame( 0, $args['redirection'], 'WordPress follows nothing; the plugin does, each target checked.' );
		if ( 'zip' === $path ) {
			self::assertSame( 30, $args['timeout'] );
			self::assertSame( 30 * MB_IN_BYTES, $args['limit_response_size'] );
		} else {
			self::assertSame( 20, $args['timeout'] );
			self::assertSame( 20 * MB_IN_BYTES + 1, $args['limit_response_size'] );
		}

		$this->answers[ $url ] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$result                = $this->fetch( $path, $url );
		self::assertSame( 'http_request_failed', self::code( $result ), 'As before: WordPress\'s own error is passed on.' );
		self::assertSame( 'cURL error 28: Operation timed out', self::message( $result ) );
	}

	#[DataProvider( 'paths' )]
	#[RequiresPhpExtension( 'curl' )]
	public function testTheRequestIgnoresTheProxyOfTheEnvironment( string $path ): void {
		$url = self::address( $path );
		$this->serve_file( $path, $url );
		$logs = array();
		self::probe_environment_proxy( $logs );
		self::assertStored( $path, $this->fetch( $path, $url ) );
		self::assertNoEnvironmentProxy( $logs, 1 );
	}

	#[DataProvider( 'paths' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testThroughTheProxyOfWordPressNothingIsDownloaded( string $path ): void {
		self::proxy();
		$url = self::address( $path );
		$this->serve_file( $path, $url );
		$result = $this->fetch( $path, $url );
		self::assertSame( 'ab_mcp_proxy', self::code( $result ) );
		$message = self::message( $result );
		self::assertStringStartsWith( 'This site sends its requests through a proxy (WP_PROXY_HOST), which looks the name of files.example up itself', $message );
		self::assertStringContainsString( '1. To let the site download from that host directly, an administrator adds it to WP_PROXY_BYPASS_HOSTS in wp-config.php; then try again. 2. Or ', $message );
		self::assertStringContainsString( self::alternative( $path ), $message );
		self::assertStringContainsString( ' Direct link: ' . self::SETTINGS . ' ', $message );
		self::assertStringEndsWith( \AB_MCP_Guidance::handover(), $message );
		self::assertSame( array(), $GLOBALS['ab_test_http'], 'The proxy would look the name up itself.' );
		self::assertNothingStored();
	}

	#[DataProvider( 'paths' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testAHostInTheBypassListIsDownloadedDirectly( string $path ): void {
		self::proxy( 'localhost, *.example' );
		$url = self::address( $path );
		$this->serve_file( $path, $url );
		self::assertStored( $path, $this->fetch( $path, $url ) );
		self::assertSame( array( $url ), self::urls() );
	}

	#[DataProvider( 'paths' )]
	public function testWithoutCurlNothingIsDownloaded( string $path ): void {
		$GLOBALS['ab_test_http_transport'] = 'fsockopen';
		$url                               = self::address( $path );
		$this->serve_file( $path, $url );
		$result = $this->fetch( $path, $url );
		self::assertSame( 'ab_mcp_unbound', self::code( $result ) );
		self::assertStringContainsString( 'without cURL', self::message( $result ) );
		self::assertStringContainsString( '1. Ask the host to enable the curl extension of PHP. 2. Until then, ', self::message( $result ) );
		self::assertStringContainsString( self::alternative( $path ), self::message( $result ) );
		self::assertSame( array(), $GLOBALS['ab_test_http'], 'Refused before the transport connects.' );
		self::assertNothingStored();
		self::assertFalse( has_action( 'requests-fsockopen.before_request' ) );
	}

	#[DataProvider( 'paths' )]
	#[RequiresPhpExtension( 'curl' )]
	public function testARedirectToAPublicHostIsFollowedAndTiedAgain( string $path ): void {
		$url = self::address( $path );
		$to  = 'https://cdn.example/store/4711';
		$this->redirect( $url, $to, 302 );
		$this->serve_file( $path, $to );
		$GLOBALS['ab_test_http_curl'] = true;
		$result                       = $this->fetch( $path, $url );
		self::assertStored( $path, $result );
		self::assertSame( array( $url, $to ), self::urls() );
		self::assertStringContainsString( ' files.example:443:' . self::V4 . ',' . self::V6 . ' ', $GLOBALS['ab_test_http'][0]['curl'] );
		self::assertStringContainsString( ' cdn.example:443:' . self::V4 . ' ', $GLOBALS['ab_test_http'][1]['curl'], 'The target is tied to its own checked addresses.' );
		if ( 'zip' !== $path ) {
			self::assertSame( 'media' === $path ? 'f.png' : 'photo.png', $GLOBALS['ab_test_sideloads'][0]['name'], 'The name comes from the address given or the chat, as before, not from the target.' );
		}
	}

	#[DataProvider( 'paths' )]
	public function testARedirectToAnInternalHostIsRefused( string $path ): void {
		$url = self::address( $path );
		foreach ( array( 'https://inside.example/x', 'https://mixed.example/x', 'http://169.254.169.254/latest/meta-data', 'http://localhost/x' ) as $to ) {
			$GLOBALS['ab_test_http'] = array();
			$this->redirect( $url, $to );
			$this->serve_file( $path, $to );
			$result = $this->fetch( $path, $url );
			self::assertSame( 'ab_mcp_blocked_url', self::code( $result ), $to );
			self::assertStringContainsString( 'The address redirects to ' . (string) wp_parse_url( $to, PHP_URL_HOST ) . ', a private or internal host', self::message( $result ) );
			self::assertStringContainsString( '1. Open the address in a browser', self::message( $result ) );
			self::assertStringContainsString( self::alternative( $path ), self::message( $result ) );
			self::assertSame( array( $url ), self::urls(), 'The target was not asked: ' . $to );
		}
		self::assertNothingStored();
	}

	#[DataProvider( 'paths' )]
	public function testARedirectToANameWithoutAnAddressIsRefused( string $path ): void {
		$url = self::address( $path );
		$this->redirect( $url, 'https://nowhere.example/x' );
		$result = $this->fetch( $path, $url );
		self::assertSame( 'ab_mcp_fetch_failed', self::code( $result ) );
		self::assertStringContainsString( 'The address redirects to nowhere.example, whose name has no address', self::message( $result ) );
		self::assertStringContainsString( self::alternative( $path ), self::message( $result ) );
		self::assertSame( array( $url ), self::urls() );
	}

	#[DataProvider( 'paths' )]
	public function testMoreRedirectsThanAllowedAreRefused( string $path ): void {
		$step = static fn( int $n ): string => 'https://files.example/step/' . $n;
		for ( $n = 1; $n <= 4; $n++ ) {
			$this->redirect( $step( $n ), $step( $n + 1 ), 301 );
		}
		$this->serve_file( $path, $step( 5 ) );

		$result = $this->fetch( $path, $step( 1 ) );
		self::assertSame( 'ab_mcp_fetch_failed', self::code( $result ) );
		self::assertStringContainsString( 'redirected more than 3 times', self::message( $result ) );
		self::assertStringContainsString( self::alternative( $path ), self::message( $result ) );
		self::assertSame( array( $step( 1 ), $step( 2 ), $step( 3 ), $step( 4 ) ), self::urls(), 'The address and three redirects; the fourth target is not asked.' );
		self::assertNothingStored();

		$GLOBALS['ab_test_http'] = array();
		$result                  = $this->fetch( $path, $step( 2 ) );
		self::assertStored( $path, $result );
		self::assertCount( 4, $GLOBALS['ab_test_http'], 'Counter-check: exactly three redirects are followed, as before.' );
	}

	#[DataProvider( 'paths' )]
	public function testARelativeLocationIsFollowedFromTheAddressThatRedirected( string $path ): void {
		$url = self::address( $path, 'files.example', '/dir' );
		$this->redirect( $url, 'v2/' . basename( $url ) );
		$this->redirect( 'https://files.example/dir/v2/' . basename( $url ), '/final?x=1' );
		$this->serve_file( $path, 'https://files.example/final?x=1' );
		self::assertStored( $path, $this->fetch( $path, $url ) );
		self::assertSame( array( $url, 'https://files.example/dir/v2/' . basename( $url ), 'https://files.example/final?x=1' ), self::urls() );
	}

	#[DataProvider( 'paths' )]
	public function testARedirectToAnotherSchemeIsRefused( string $path ): void {
		$url = self::address( $path );
		foreach ( array( 'ftp://cdn.example/f', 'file:///etc/passwd', 'gopher://cdn.example/', 'javascript:alert(1)' ) as $to ) {
			$GLOBALS['ab_test_http'] = array();
			$this->redirect( $url, $to );
			$this->serve_file( $path, $to );
			$result = $this->fetch( $path, $url );
			self::assertSame( 'ab_mcp_fetch_failed', self::code( $result ), $to );
			self::assertStringContainsString( 'not an http(s) address', self::message( $result ) );
			self::assertStringContainsString( self::alternative( $path ), self::message( $result ) );
			self::assertSame( array( $url ), self::urls(), $to );
		}
		self::assertNothingStored();
	}

	/* ------------------------------------------------- AB_MCP_Outbound::get() */

	public function testGetFollowsTheRedirectsItIsAllowedEachHopWithTheSameArgumentsAndNoneOfItsOwn(): void {
		$this->redirect( 'https://files.example/a', 'https://cdn.example/b', 301 );
		$this->redirect( 'https://cdn.example/b', '/c', 307 );
		$this->serve( 'https://cdn.example/c', 'body', 'text/plain' );
		$result = AB_MCP_Outbound::get( 'https://files.example/a', array( 'redirection' => 3, 'timeout' => 7 ) );
		self::assertSame( 'body', wp_remote_retrieve_body( $result ) );
		self::assertSame( array( 'https://files.example/a', 'https://cdn.example/b', 'https://cdn.example/c' ), self::urls() );
		foreach ( $GLOBALS['ab_test_http'] as $call ) {
			self::assertSame( 0, $call['args']['redirection'], 'WordPress follows nothing: its transport would look the target up unbound.' );
			self::assertSame( 7, $call['args']['timeout'], 'The other arguments go with every hop.' );
		}
	}

	public function testOneRedirectMoreThanAllowedEndsTheRequest(): void {
		for ( $n = 1; $n <= 4; $n++ ) {
			$this->redirect( 'https://files.example/' . $n, 'https://files.example/' . ( $n + 1 ) );
		}
		$this->serve( 'https://files.example/5', 'body', 'text/plain' );
		$result = AB_MCP_Outbound::get( 'https://files.example/1', array( 'redirection' => 3 ) );
		self::assertSame( 'ab_mcp_outbound_redirects', self::code( $result ) );
		self::assertSame( array( 'url' => 'https://files.example/5', 'hop' => 4 ), $result->get_error_data() );
		self::assertCount( 4, $GLOBALS['ab_test_http'], 'The address and three redirects; the fourth target is not asked.' );

		$GLOBALS['ab_test_http'] = array();
		self::assertSame( 'body', wp_remote_retrieve_body( AB_MCP_Outbound::get( 'https://files.example/2', array( 'redirection' => 3 ) ) ), 'Counter-check: exactly as many as allowed are followed.' );
		self::assertCount( 4, $GLOBALS['ab_test_http'] );
	}

	public function testWithoutRedirectsAllowedA3xxIsTheAnswer(): void {
		$this->redirect( 'https://files.example/a', 'https://cdn.example/b' );
		$result = AB_MCP_Outbound::get( 'https://files.example/a', array() );
		self::assertSame( 302, wp_remote_retrieve_response_code( $result ), 'As WordPress with redirection 0: the caller reads the 3xx (the staging check reports it, the document fetch refuses it).' );
		self::assertSame( array( 'https://files.example/a' ), self::urls() );
	}

	public function testA3xxWithoutALocationIsTheAnswerToo(): void {
		$this->answers['https://files.example/a'] = ab_test_http_response( 304, '', array() );
		$result                                   = AB_MCP_Outbound::get( 'https://files.example/a', array( 'redirection' => 3 ) );
		self::assertSame( 304, wp_remote_retrieve_response_code( $result ) );
		self::assertCount( 1, $GLOBALS['ab_test_http'] );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function refusedTargets(): array {
		return array(
			'public and private mixed, AAAA' => array( 'https://mixed.example/b', 'ab_mcp_outbound_local' ),
			'IPv4 mapped into IPv6'          => array( 'https://mapped.example/b', 'ab_mcp_outbound_local' ),
			'a private address'              => array( 'https://inside.example/b', 'ab_mcp_outbound_local' ),
			'an address as host, link-local' => array( 'http://169.254.169.254/latest', 'ab_mcp_outbound_local' ),
			'this machine'                   => array( 'http://localhost/b', 'ab_mcp_outbound_local' ),
			'a name without an address'      => array( 'https://nowhere.example/b', 'ab_mcp_outbound_unresolved' ),
			'not http(s)'                    => array( 'ftp://cdn.example/b', 'ab_mcp_outbound_redirect' ),
			'a file'                         => array( 'file:///etc/passwd', 'ab_mcp_outbound_redirect' ),
		);
	}

	#[DataProvider( 'refusedTargets' )]
	public function testARedirectTargetThatFailsTheCheckIsNotAsked( string $target, string $code ): void {
		$this->redirect( 'https://files.example/a', $target );
		$this->serve( $target, 'secret', 'text/plain' );
		$result = AB_MCP_Outbound::get( 'https://files.example/a', array( 'redirection' => 3 ) );
		self::assertSame( $code, self::code( $result ) );
		self::assertSame( array( 'url' => $target, 'hop' => 1 ), $result->get_error_data() );
		self::assertSame( array( 'https://files.example/a' ), self::urls(), 'The target was not asked.' );
	}

	public function testARelativeLocationIsResolvedAgainstTheAddressThatRedirected(): void {
		$this->redirect( 'https://files.example/dir/a.png', 'sub/b.png' );
		$this->redirect( 'https://files.example/dir/sub/b.png', '//cdn.example/c.png' );
		$this->redirect( 'https://cdn.example/c.png', '?v=2' );
		$this->serve( 'https://cdn.example/c.png?v=2', 'body', 'text/plain' );
		$result = AB_MCP_Outbound::get( 'https://files.example/dir/a.png', array( 'redirection' => 3 ) );
		self::assertSame( 'body', wp_remote_retrieve_body( $result ) );
		self::assertSame( array( 'https://files.example/dir/a.png', 'https://files.example/dir/sub/b.png', 'https://cdn.example/c.png', 'https://cdn.example/c.png?v=2' ), self::urls() );
	}

	#[RequiresPhpExtension( 'curl' )]
	public function testEveryHopIsTiedToTheCheckedAddressesOfItsOwnHost(): void {
		$this->redirect( 'https://files.example/a', 'http://cdn.example:8080/b' );
		$this->serve( 'http://cdn.example:8080/b', 'body', 'text/plain' );
		$GLOBALS['ab_test_http_curl'] = true;
		$result                       = AB_MCP_Outbound::get( 'https://files.example/a', array( 'redirection' => 3 ) );
		self::assertSame( 'body', wp_remote_retrieve_body( $result ) );
		self::assertStringContainsString( ' files.example:443:' . self::V4 . ',' . self::V6 . ' ', $GLOBALS['ab_test_http'][0]['curl'] );
		self::assertStringContainsString( ' cdn.example:8080:' . self::V4 . ' ', $GLOBALS['ab_test_http'][1]['curl'], 'Its own host and port.' );
		self::assertStringNotContainsString( 'files.example', $GLOBALS['ab_test_http'][1]['curl'], 'Not the entry of the hop before.' );
		self::assertFalse( has_action( 'http_api_curl' ) );
		self::assertFalse( has_action( 'requests-fsockopen.before_request' ) );
	}

	#[RequiresPhpExtension( 'curl' )]
	public function testEveryHopIgnoresTheProxyOfTheEnvironment(): void {
		$this->redirect( 'https://files.example/a', 'https://cdn.example/b' );
		$this->serve( 'https://cdn.example/b', 'body', 'text/plain' );
		$logs = array();
		self::probe_environment_proxy( $logs );
		self::assertSame( 'body', wp_remote_retrieve_body( AB_MCP_Outbound::get( 'https://files.example/a', array( 'redirection' => 3 ) ) ) );
		self::assertNoEnvironmentProxy( $logs, 2 );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testARedirectTargetBehindTheProxyOfWordPressIsNotAsked(): void {
		self::proxy( 'files.example' );
		$this->redirect( 'https://files.example/a', 'https://cdn.example/b' );
		$this->serve( 'https://cdn.example/b', 'body', 'text/plain' );
		$result = AB_MCP_Outbound::get( 'https://files.example/a', array( 'redirection' => 3 ) );
		self::assertSame( 'ab_mcp_outbound_proxy', self::code( $result ) );
		self::assertSame( array( 'url' => 'https://cdn.example/b', 'hop' => 1 ), $result->get_error_data() );
		self::assertSame( array( 'https://files.example/a' ), self::urls(), 'The first host is in WP_PROXY_BYPASS_HOSTS and was asked directly; the target is not.' );
	}

	public function testACallersAddressesAreCheckedAgain(): void {
		$this->serve( 'https://files.example/a', 'body', 'text/plain' );
		$result = AB_MCP_Outbound::get( 'https://files.example/a', array(), array( self::V4, '10.0.0.1' ) );
		self::assertSame( 'ab_mcp_outbound_local', self::code( $result ) );
		self::assertSame( array( 'url' => 'https://files.example/a', 'hop' => 0 ), $result->get_error_data() );
		self::assertSame( 'ab_mcp_outbound_unresolved', self::code( AB_MCP_Outbound::get( 'https://files.example/a', array(), array() ) ) );
		self::assertSame( array(), $GLOBALS['ab_test_http'] );
	}

	#[RequiresPhpExtension( 'curl' )]
	public function testACallersAddressesAreTheOnesTheConnectionIsTiedTo(): void {
		$this->serve( 'https://files.example/a', 'body', 'text/plain' );
		$GLOBALS['ab_test_http_curl'] = true;
		$result                       = AB_MCP_Outbound::get( 'https://files.example/a', array(), array( self::V4 ) );
		self::assertSame( 'body', wp_remote_retrieve_body( $result ) );
		self::assertStringContainsString( ' files.example:443:' . self::V4 . ' ', $GLOBALS['ab_test_http'][0]['curl'], 'The caller\'s list, not the AAAA record DNS would add.' );
	}

	/* ------------------------------------------------------------- cookies */

	/**
	 * The cookies a recorded request carried in its Cookie header, name =>
	 * value as sent (Requests: "name=value", joined with "; ").
	 */
	private static function carried( int $call ): array {
		$header = $GLOBALS['ab_test_http'][ $call ]['args']['headers']['Cookie'] ?? '';
		$out    = array();
		foreach ( '' === $header ? array() : explode( '; ', (string) $header ) as $pair ) {
			list( $name, $value ) = explode( '=', $pair, 2 ) + array( '', '' );
			$out[ $name ]         = $value;
		}
		return $out;
	}

	/** Whether a recorded request carried a Cookie header at all. */
	private static function carriedHeader( int $call ): bool {
		return isset( $GLOBALS['ab_test_http'][ $call ]['args']['headers']['Cookie'] );
	}

	/**
	 * An answer that sets cookies: its Set-Cookie headers as a server sends
	 * them. Nothing else of an answer is read for cookies.
	 *
	 * @param string[] $set_cookie
	 */
	private static function setting( int $status, array $headers, array $set_cookie, string $body = '' ): array {
		return ab_test_http_response( $status, $body, $headers + array( 'Set-Cookie' => $set_cookie ) );
	}

	/** An answer that serves the file only to a request whose Cookie header carries name=value, 403 otherwise. */
	private static function gated( string $name, string $value, string $body, string $type ): callable {
		return static function ( array $args ) use ( $name, $value, $body, $type ): array {
			$pairs = explode( '; ', (string) ( $args['headers']['Cookie'] ?? '' ) );
			if ( in_array( $name . '=' . $value, $pairs, true ) ) {
				return ab_test_http_response( 200, $body, array( 'Content-Type' => $type ) );
			}
			return ab_test_http_response( 403, 'no cookie', array( 'Content-Type' => 'text/plain' ) );
		};
	}

	/**
	 * Codex on ccf642a: a download that sets a cookie and redirects to the
	 * file, which answers 403 without it, worked with WordPress following
	 * (its transport carries the cookie) and failed with get() following.
	 * The jar is the transport's own since the fourth round (Codex on
	 * fcc033e): WpOrg\Requests\Cookie\Jar, so what get() carries is what
	 * WordPress carries, in the Cookie header.
	 */
	public function testTheCookiesOfAnAnswerGoWithTheNextHopAsWordPressSendsThem(): void {
		$this->answers['https://files.example/dl']       = self::setting( 302, array( 'Location' => '/file.png' ), array( 'download=ok; Path=/' ) );
		$this->answers['https://files.example/file.png'] = self::gated( 'download', 'ok', 'body', 'text/plain' );
		$result = AB_MCP_Outbound::get( 'https://files.example/dl', array( 'redirection' => 3 ) );
		self::assertSame( 'body', wp_remote_retrieve_body( $result ) );
		self::assertFalse( self::carriedHeader( 0 ), 'Nothing to carry on the first hop.' );
		self::assertArrayNotHasKey( 'cookies', $GLOBALS['ab_test_http'][1]['args'], 'Carried as the transport carries it: in the Cookie header.' );
		self::assertSame( array( 'download' => 'ok' ), self::carried( 1 ) );
	}

	#[DataProvider( 'paths' )]
	public function testADownloadBehindACookieWorksAsBefore( string $path ): void {
		$url = self::address( $path, 'files.example', '/dl' );
		$this->answers[ $url ] = self::setting( 302, array( 'Location' => '/store/' . basename( $url ) ), array( 'download=ok; Path=/' ) );
		$this->answers[ 'https://files.example/store/' . basename( $url ) ] = self::gated( 'download', 'ok', 'zip' === $path ? self::ZIP : self::PNG, 'zip' === $path ? 'application/zip' : 'image/png' );
		self::assertStored( $path, $this->fetch( $path, $url ) );
		self::assertSame( array( 'download' => 'ok' ), self::carried( 1 ) );
	}

	/** @return array<string,array{0:string,1:string}> A Set-Cookie header of files.example, and the address of the next hop. */
	public static function cookiesThatStayBehind(): array {
		return array(
			'set by another host (host-only)'              => array( 'c=v', 'https://cdn.example/x' ),
			'host-only, a sub host'                        => array( 'c=v', 'https://www.files.example/x' ),
			'a Domain the host is not in (Codex, fcc033e)' => array( 'c=v; Domain=cdn.example', 'https://cdn.example/x' ),
			'expired'                                      => array( 'c=v; Expires=' . gmdate( 'D, d M Y H:i:s', time() - 60 ) . ' GMT', 'https://files.example/x' ),
			'Max-Age=0, a deletion (Codex, fcc033e)'       => array( 'c=v; Max-Age=0', 'https://files.example/x' ),
			'Max-Age in the past'                          => array( 'c=v; Max-Age=-5', 'https://files.example/x' ),
		);
	}

	/**
	 * Requests, following for WordPress, parses a cookie for the address
	 * that set it (Cookie::parse_from_headers(): a Domain the address is
	 * not in is refused) and sends it only where its domain matches and it
	 * is not expired (Jar::before_request()).
	 */
	#[DataProvider( 'cookiesThatStayBehind' )]
	public function testACookieTheTransportWouldNotSendStaysBehind( string $set_cookie, string $target ): void {
		$this->answers['https://files.example/dl'] = self::setting( 302, array( 'Location' => $target ), array( $set_cookie ) );
		$this->serve( $target, 'body', 'text/plain' );
		self::assertSame( 'body', wp_remote_retrieve_body( AB_MCP_Outbound::get( 'https://files.example/dl', array( 'redirection' => 3 ) ) ) );
		self::assertSame( array( 'https://files.example/dl', $target ), self::urls() );
		self::assertFalse( self::carriedHeader( 1 ) );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function cookiesThatGoAlong(): array {
		return array(
			'the same host'                             => array( 'c=v', 'https://files.example/other/x' ),
			'the host in capitals (Codex, fcc033e)'     => array( 'c=v', 'https://FILES.EXAMPLE/x' ),
			'a sub host, Domain set'                    => array( 'c=v; Domain=files.example', 'https://www.files.example/x' ),
			'a sub host, Domain with a leading dot'     => array( 'c=v; Domain=.files.example', 'https://www.files.example/x' ),
			'not yet expired'                           => array( 'c=v; Expires=' . gmdate( 'D, d M Y H:i:s', time() + 3600 ) . ' GMT', 'https://files.example/x' ),
			'Max-Age ahead'                             => array( 'c=v; Max-Age=3600', 'https://files.example/x' ),
			// Jar::before_request() asks the domain and the expiry only, not
			// the path: a cookie goes to every path of its host, as with
			// WordPress following.
			'another path, as the transport sends it'   => array( 'c=v; Path=/dl/', 'https://files.example/other/x' ),
		);
	}

	#[DataProvider( 'cookiesThatGoAlong' )]
	public function testACookieTheTransportWouldSendGoesAlong( string $set_cookie, string $target ): void {
		$this->answers['https://files.example/dl'] = self::setting( 302, array( 'Location' => $target ), array( $set_cookie ) );
		$this->serve( $target, 'body', 'text/plain' );
		self::assertSame( 'body', wp_remote_retrieve_body( AB_MCP_Outbound::get( 'https://files.example/dl', array( 'redirection' => 3 ) ) ) );
		self::assertSame( array( 'https://files.example/dl', $target ), self::urls() );
		self::assertSame( array( 'c' => 'v' ), self::carried( 1 ) );
	}

	/**
	 * WP_Http::request() fills the jar from 'cookies' through
	 * WP_Http::normalize_cookies(): a scalar as (string) $value, for any
	 * host; a WP_Http_Cookie with its attributes; neither encoded nor
	 * decoded (Cookie::format_for_header()). The headers of the call stay.
	 */
	public function testTheCallersCookiesGoAsWordPressSendsThemWithTheHeadersOfTheCall(): void {
		$this->answers['https://files.example/1'] = self::setting( 302, array( 'Location' => '/2' ), array( 'token=first; Path=/' ) );
		$this->answers['https://files.example/2'] = self::setting( 302, array( 'Location' => 'https://cdn.example/3' ), array( 'token=second; Path=/' ) );
		$this->serve( 'https://cdn.example/3', 'body', 'text/plain' );
		$result = AB_MCP_Outbound::get(
			'https://files.example/1',
			array(
				'redirection' => 3,
				'headers'     => array( 'Accept' => 'image/png' ),
				'cookies'     => array(
					'session' => 'x%2Fy+z=1',
					new \WP_Http_Cookie(
						array(
							'name'   => 'cdn',
							'value'  => 'only',
							'domain' => 'cdn.example',
						)
					),
					new \WP_Http_Cookie(
						array(
							'name'  => 'raw',
							'value' => 'p%20q=r',
						)
					),
				),
			)
		);
		self::assertSame( 'body', wp_remote_retrieve_body( $result ) );
		self::assertSame( array( 'session' => 'x%2Fy+z=1', 'raw' => 'p%20q=r' ), self::carried( 0 ), 'The caller\'s cookie for another domain stays behind; the values as given.' );
		self::assertSame( array( 'session' => 'x%2Fy+z=1', 'raw' => 'p%20q=r', 'token' => 'first' ), self::carried( 1 ) );
		self::assertSame( array( 'session' => 'x%2Fy+z=1', 'cdn' => 'only', 'raw' => 'p%20q=r' ), self::carried( 2 ), 'The host-only token of files.example does not go to cdn.example; the caller\'s cookie for it does.' );
		foreach ( array( 0, 1, 2 ) as $hop ) {
			self::assertSame( 'image/png', $GLOBALS['ab_test_http'][ $hop ]['args']['headers']['Accept'], 'Hop ' . $hop . ': the headers of the call stay.' );
			self::assertArrayNotHasKey( 'cookies', $GLOBALS['ab_test_http'][ $hop ]['args'] );
		}
	}

	public function testALaterCookieOfTheSameNameReplacesTheEarlier(): void {
		$this->answers['https://files.example/1'] = self::setting( 302, array( 'Location' => '/2' ), array( 'token=first; Path=/' ) );
		$this->answers['https://files.example/2'] = self::setting( 302, array( 'Location' => '/3' ), array( 'token=second; Path=/' ) );
		$this->serve( 'https://files.example/3', 'body', 'text/plain' );
		AB_MCP_Outbound::get( 'https://files.example/1', array( 'redirection' => 3 ) );
		self::assertSame( array( 'token' => 'first' ), self::carried( 1 ) );
		self::assertSame( 'token=second', $GLOBALS['ab_test_http'][2]['args']['headers']['Cookie'], 'Replaced, not carried twice (Jar::before_redirect_check() merges by name).' );
	}

	/**
	 * Codex on 92cbcd5 and 3a27944: the value as the Set-Cookie header
	 * carried it, with "%2F", "+" and "=", through a hop that sets nothing
	 * and one that sets something else; what WordPress lists as the cookies
	 * of those answers (the whole jar, decoded) is never read.
	 */
	public function testTheValueOfACookieGoesOnAsTheHeaderCarriedItThroughTheWholeChain(): void {
		$this->answers['https://files.example/start']  = self::setting( 302, array( 'Location' => '/middle' ), array( 'token=a%2Fb+c=d; Path=/' ) );
		$this->answers['https://files.example/middle'] = self::setting( 302, array( 'Location' => '/other' ), array() );
		$this->answers['https://files.example/other']  = self::setting( 302, array( 'Location' => '/file' ), array( 'other=1; Path=/' ) );
		$this->answers['https://files.example/file']   = self::gated( 'token', 'a%2Fb+c=d', 'body', 'text/plain' );
		$result = AB_MCP_Outbound::get( 'https://files.example/start', array( 'redirection' => 3 ) );
		self::assertSame( 'body', wp_remote_retrieve_body( $result ), 'Raw, as the server sent it; not "a/b c=d".' );
		self::assertSame( array( 'token' => 'a%2Fb+c=d' ), self::carried( 1 ) );
		self::assertSame( array( 'token' => 'a%2Fb+c=d' ), self::carried( 2 ) );
		self::assertSame( array( 'token' => 'a%2Fb+c=d', 'other' => '1' ), self::carried( 3 ) );
	}

	/** Codex on fcc033e: a deletion (Max-Age=0) in the middle takes the cookie out of the jar, as the transport's jar does. */
	public function testADeletionInTheMiddleTakesTheCookieOut(): void {
		$this->answers['https://files.example/start']  = self::setting( 302, array( 'Location' => '/middle' ), array( 'token=abc; Path=/' ) );
		$this->answers['https://files.example/middle'] = self::setting( 302, array( 'Location' => '/file' ), array( 'token=deleted; Max-Age=0; Path=/' ) );
		$this->serve( 'https://files.example/file', 'body', 'text/plain' );
		self::assertSame( 'body', wp_remote_retrieve_body( AB_MCP_Outbound::get( 'https://files.example/start', array( 'redirection' => 3 ) ) ) );
		self::assertSame( array( 'token' => 'abc' ), self::carried( 1 ) );
		self::assertFalse( self::carriedHeader( 2 ), 'Neither «abc» nor «deleted».' );
	}

	public function testSeveralSetCookieHeadersEachKeepTheirValueTrimmedAsTheTransportReadsIt(): void {
		$this->answers['https://files.example/dl'] = self::setting( 302, array( 'Location' => '/file.png' ), array( 'a=1%3D1', 'b=x+y; Path=/', ' d = v%20w ; Path=/; Secure' ) );
		$this->serve( 'https://files.example/file.png', 'body', 'text/plain' );
		AB_MCP_Outbound::get( 'https://files.example/dl', array( 'redirection' => 3 ) );
		self::assertSame( array( 'a' => '1%3D1', 'b' => 'x+y', 'd' => 'v%20w' ), self::carried( 1 ) );
	}

	/**
	 * Codex on 3a27944 and fcc033e: wp_remote_retrieve_cookies() lists the
	 * whole jar of the request, decoded, the carried cookies included. Only
	 * the Set-Cookie headers are read.
	 */
	public function testWhatWordPressListsAsTheCookiesOfAnAnswerIsNotRead(): void {
		$this->answers['https://files.example/dl'] = ab_test_http_response(
			302,
			'',
			array( 'Location' => '/file.png' ),
			array(
				new \WP_Http_Cookie(
					array(
						'name'   => 'download',
						'value'  => 'a/b c',
						'domain' => 'files.example',
						'path'   => '/',
					)
				),
			)
		);
		$this->serve( 'https://files.example/file.png', 'body', 'text/plain' );
		AB_MCP_Outbound::get( 'https://files.example/dl', array( 'redirection' => 3 ) );
		self::assertFalse( self::carriedHeader( 1 ) );
	}

	/* ---------------------------------------------- the address of a redirect */

	/** @return array<string,array{0:string,1:string,2:string|null}> */
	public static function locations(): array {
		$base = 'https://files.example/dir/sub/file.png?v=1';
		return array(
			'absolute'                        => array( 'https://cdn.example/x.png', $base, 'https://cdn.example/x.png' ),
			'absolute, dots kept as sent'     => array( 'https://cdn.example/a/../x.png', $base, 'https://cdn.example/a/../x.png' ),
			'another scheme'                  => array( 'ftp://cdn.example/x.png', $base, 'ftp://cdn.example/x.png' ),
			'scheme-relative'                 => array( '//cdn.example/x.png', $base, 'https://cdn.example/x.png' ),
			'scheme-relative with dots'       => array( '//cdn.example:8080/a/../x.png?q#f', $base, 'https://cdn.example:8080/x.png?q' ),
			'scheme-relative, host alone'     => array( '//cdn.example', $base, 'https://cdn.example' ),
			'absolute path'                   => array( '/other/x.png?a=b', $base, 'https://files.example/other/x.png?a=b' ),
			'relative path'                   => array( 'x.png', $base, 'https://files.example/dir/sub/x.png' ),
			'dot segments'                    => array( '../up/./x.png', $base, 'https://files.example/dir/up/x.png' ),
			'two levels up'                   => array( '../../x.png', $base, 'https://files.example/x.png' ),
			'further up than the root'        => array( '../../../x.png', $base, 'https://files.example/x.png' ),
			'above the root, absolute'        => array( '/../../x.png', $base, 'https://files.example/x.png' ),
			'./ in front'                     => array( './x.png', $base, 'https://files.example/dir/sub/x.png' ),
			'dots in the middle'              => array( 'a/./b/../c.png', $base, 'https://files.example/dir/sub/a/c.png' ),
			'dots at the end'                 => array( '..', $base, 'https://files.example/dir/' ),
			'dot at the end'                  => array( '.', $base, 'https://files.example/dir/sub/' ),
			'./ alone'                        => array( './', $base, 'https://files.example/dir/sub/' ),
			'/.. alone'                       => array( '/..', $base, 'https://files.example/' ),
			'/. alone'                        => array( '/.', $base, 'https://files.example/' ),
			'/./x'                            => array( '/./x.png', $base, 'https://files.example/x.png' ),
			'dots with a query'               => array( '../x.png?v=2', $base, 'https://files.example/dir/x.png?v=2' ),
			'doubled slash in the base path'  => array( 'x.png', 'https://files.example//dir/file.png', 'https://files.example//dir/x.png' ),
			'doubled slash in the reference'  => array( '/a//b/x.png', $base, 'https://files.example/a//b/x.png' ),
			'doubled slash and dots'          => array( '//dir/../x.png', 'https://files.example/a', 'https://dir/x.png' ),
			'empty segment kept'              => array( 'a//../x.png', $base, 'https://files.example/dir/sub/a/x.png' ),
			'query only'                      => array( '?b=2', $base, 'https://files.example/dir/sub/file.png?b=2' ),
			'query only, base without a path' => array( '?b=2', 'https://files.example', 'https://files.example?b=2' ),
			'fragment dropped'                => array( 'x.png#top', $base, 'https://files.example/dir/sub/x.png' ),
			'fragment after a query'          => array( '../x.png?v=2#top', $base, 'https://files.example/dir/x.png?v=2' ),
			'with the port'                   => array( '/x.png', 'http://files.example:8080/a', 'http://files.example:8080/x.png' ),
			'base without a path'             => array( 'x.png', 'https://files.example', 'https://files.example/x.png' ),
			'base without a path, dots'       => array( '../x.png', 'https://files.example', 'https://files.example/x.png' ),
			'base path is the root'           => array( 'x.png', 'https://files.example/', 'https://files.example/x.png' ),
			'spaces around'                   => array( '  /x.png  ', $base, 'https://files.example/x.png' ),
			'empty'                           => array( '', $base, null ),
			'fragment alone'                  => array( '#top', $base, null ),
			'base no address'                 => array( 'x.png', 'not an address', null ),
		);
	}

	#[DataProvider( 'locations' )]
	public function testTheAddressOfARedirect( string $location, string $base, ?string $expected ): void {
		self::assertSame( $expected, AB_MCP_Outbound::absolute_url( $location, $base ) );
	}

	/* ---------------------------------------- AB_MCP_Outbound::get_bound() */

	/** A new site address whose name points nowhere yet: no test answers it. */
	const NEW_HOST = 'video2.alphabridge-mcp.com';

	/** @var string[] The hosts looked up (ab_mcp_outbound_addresses), in order. */
	private array $looked_up = array();

	/**
	 * WordPress's transport as far as the bound mode reaches it. The bootstrap
	 * answers wp_remote_get() without the transport's hooks (it is the
	 * loopback of the firewall check, where nothing is tied), so here the
	 * answer runs what WP_Http::request() runs before it connects, as the
	 * bootstrap's wp_safe_remote_get() does: the first hook of the fsockopen
	 * transport, where an exception of Requests ends the request with
	 * nothing sent (the call is taken off the record), or http_api_curl on a
	 * real cURL handle, which with ab_test_http_curl set is then started
	 * against a refusing proxy on this machine and leaves its verbose log
	 * ('curl'). Each call also records which function of WordPress sent it
	 * ('via', from the stack). The hosts looked up are recorded too.
	 */
	private function boundTransport(): void {
		$this->looked_up = array();
		add_filter(
			'ab_mcp_outbound_addresses',
			function ( $found, string $host ) {
				$this->looked_up[] = $host;
				return $found;
			},
			5,
			2
		);
		$GLOBALS['ab_test_http_answer'] = function ( string $url, array $args ) {
			$call   = count( $GLOBALS['ab_test_http'] ) - 1;
			$frames = array_column( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ), 'function' );
			$GLOBALS['ab_test_http'][ $call ]['via'] = in_array( 'wp_safe_remote_get', $frames, true ) ? 'wp_safe_remote_get' : ( in_array( 'wp_remote_get', $frames, true ) ? 'wp_remote_get' : 'other' );
			$transport                               = $GLOBALS['ab_test_http_transport'] ?? 'curl';
			if ( 'fsockopen' === $transport ) {
				try {
					do_action_ref_array( 'requests-fsockopen.before_request', array() );
				} catch ( \WpOrg\Requests\Exception $e ) {
					array_pop( $GLOBALS['ab_test_http'] );
					return new WP_Error( 'http_request_failed', $e->getMessage() );
				}
			}
			if ( 'curl' === $transport && function_exists( 'curl_init' ) ) {
				$handle = curl_init();
				do_action_ref_array( 'http_api_curl', array( &$handle, $args, $url ) );
				if ( ! empty( $GLOBALS['ab_test_http_curl'] ) ) {
					$log = fopen( 'php://temp', 'w+' );
					curl_setopt_array(
						$handle,
						array(
							CURLOPT_URL               => $url,
							CURLOPT_PROXY             => 'http://127.0.0.1:9',
							CURLOPT_VERBOSE           => true,
							CURLOPT_STDERR            => $log,
							CURLOPT_RETURNTRANSFER    => true,
							CURLOPT_CONNECTTIMEOUT_MS => 500,
							CURLOPT_TIMEOUT_MS        => 1000,
						)
					);
					curl_exec( $handle );
					rewind( $log );
					$GLOBALS['ab_test_http'][ $call ]['curl'] = (string) stream_get_contents( $log );
					fclose( $log );
				}
			}
			$answer = $this->answers[ $url ] ?? ab_test_http_response( 404, 'no such address' );
			return is_callable( $answer ) ? $answer( $args ) : $answer;
		};
	}

	/** get_bound() over the transport above. */
	private function bound( string $url, array $args = array(), $pin_to = null ) {
		$this->boundTransport();
		return AB_MCP_Outbound::get_bound( $url, $args, $pin_to );
	}

	/** Nothing was sent and no name looked up. */
	private function assertNotAsked(): void {
		self::assertSame( array(), $GLOBALS['ab_test_http'], 'Nothing was sent.' );
		self::assertSame( array(), $this->looked_up, 'No name was looked up.' );
	}

	public function testTheBoundModeSendsThroughWpRemoteGetAndNeverThroughWpSafeRemoteGet(): void {
		// wp_safe_remote_get() would hand the address to
		// wp_http_validate_url(), which looks the host up with
		// gethostbyname() and refuses a name without an A record before the
		// binding could tie it.
		$url = 'https://' . self::NEW_HOST . '/';
		$this->serve( $url, 'body', 'text/plain' );
		$result = $this->bound( $url, array(), array( self::V4 ) );
		self::assertSame( 'body', wp_remote_retrieve_body( $result ) );
		self::assertCount( 1, $GLOBALS['ab_test_http'] );
		self::assertSame( 'wp_remote_get', $GLOBALS['ab_test_http'][0]['via'] );
	}

	public function testRejectUnsafeUrlsIsFalseWhateverTheCallerSays(): void {
		// With true, WordPress would look the name up after all
		// (WP_Http::request(): wp_http_validate_url()).
		$url = 'https://' . self::NEW_HOST . '/';
		$this->serve( $url, 'body', 'text/plain' );
		$this->bound( $url, array( 'reject_unsafe_urls' => true, 'timeout' => 7 ), array( self::V4 ) );
		self::assertFalse( $GLOBALS['ab_test_http'][0]['args']['reject_unsafe_urls'] );
		self::assertSame( 7, $GLOBALS['ab_test_http'][0]['args']['timeout'], 'The other arguments go with the request.' );
	}

	public function testA3xxIsTheAnswerAndRedirectionIsZeroWhateverTheCallerSays(): void {
		$url = 'https://' . self::NEW_HOST . '/';
		$this->redirect( $url, 'https://files.example/elsewhere', 301 );
		$this->serve( 'https://files.example/elsewhere', 'body', 'text/plain' );
		$result = $this->bound( $url, array( 'redirection' => 5 ), array( self::V4 ) );
		self::assertSame( 301, wp_remote_retrieve_response_code( $result ), 'The caller reads the 3xx: a target would be looked up unbound.' );
		self::assertSame( 'https://files.example/elsewhere', wp_remote_retrieve_header( $result, 'location' ) );
		self::assertSame( array( $url ), self::urls(), 'Nothing followed.' );
		self::assertSame( 0, $GLOBALS['ab_test_http'][0]['args']['redirection'] );
	}

	public function testTheAddressGoesOutExactlyAsGiven(): void {
		foreach ( array(
			'https://' . self::NEW_HOST . '/wp/?rest_route=/alphabridge/v1/address-probe&key=a%2Fb&nonce=N+1',
			'https://' . self::NEW_HOST . '?rest_route=/alphabridge/v1/address-probe',
			'http://' . self::NEW_HOST . ':8080/a/../b//c',
			'HTTPS://' . self::NEW_HOST . '/',
		) as $url ) {
			$GLOBALS['ab_test_http'] = array();
			$this->serve( $url, 'body', 'text/plain' );
			self::assertSame( 'body', wp_remote_retrieve_body( $this->bound( $url, array(), array( self::V4 ) ) ), $url );
			self::assertSame( array( $url ), self::urls() );
		}
	}

	#[RequiresPhpExtension( 'curl' )]
	public function testWithoutPinToTheRequestIsTiedToEveryAddressOfTheName(): void {
		$url = 'https://files.example/probe';
		$this->serve( $url, 'body', 'text/plain' );
		$GLOBALS['ab_test_http_curl'] = true;
		self::assertSame( 'body', wp_remote_retrieve_body( $this->bound( $url ) ) );
		self::assertStringContainsString( ' files.example:443:' . self::V4 . ',' . self::V6 . ' ', $GLOBALS['ab_test_http'][0]['curl'], 'cURL took the checked addresses, IPv4 and IPv6, and looked nothing up.' );
		self::assertSame( array( 'files.example' ), $this->looked_up );
		self::assertFalse( has_action( 'http_api_curl' ), 'Taken off after the request: no other request of the site is tied.' );
		self::assertFalse( has_action( 'requests-fsockopen.before_request' ) );
	}

	#[RequiresPhpExtension( 'curl' )]
	public function testWithPinToTheRequestIsTiedToThoseAddressesAndTheNameIsNotLookedUp(): void {
		$GLOBALS['ab_test_http_curl'] = true;
		$url                          = 'https://' . self::NEW_HOST . '/';
		$this->serve( $url, 'body', 'text/plain' );
		self::assertSame( 'body', wp_remote_retrieve_body( $this->bound( $url, array(), array( self::V4 ) ) ), 'A name without an A record is asked: the binding is pin_to.' );
		self::assertStringContainsString( ' ' . self::NEW_HOST . ':443:' . self::V4 . ' ', $GLOBALS['ab_test_http'][0]['curl'] );
		self::assertSame( array(), $this->looked_up );

		$GLOBALS['ab_test_http'] = array();
		$this->bound( $url, array(), array( self::V4, self::V6 ) );
		self::assertStringContainsString( ' ' . self::NEW_HOST . ':443:' . self::V4 . ',' . self::V6 . ' ', $GLOBALS['ab_test_http'][0]['curl'] );

		// A name that does resolve: still the caller's list, not the AAAA
		// record DNS would add.
		$GLOBALS['ab_test_http'] = array();
		$this->serve( 'https://files.example/', 'body', 'text/plain' );
		$this->bound( 'https://files.example/', array(), array( self::V4 ) );
		self::assertStringContainsString( ' files.example:443:' . self::V4 . ' ', $GLOBALS['ab_test_http'][0]['curl'] );
		self::assertStringNotContainsString( self::V6, $GLOBALS['ab_test_http'][0]['curl'] );
		self::assertSame( array(), $this->looked_up );
	}

	/** @return array<string,array{0:string,1:int}> An address, and the port its binding carries. */
	public static function boundPorts(): array {
		return array(
			'http, no port'        => array( 'http://' . self::NEW_HOST . '/', 80 ),
			'https, no port'       => array( 'https://' . self::NEW_HOST . '/', 443 ),
			'https in capitals'    => array( 'HTTPS://' . self::NEW_HOST . '/', 443 ),
			'http on 8080'         => array( 'http://' . self::NEW_HOST . ':8080/x', 8080 ),
			'https on 8443, query' => array( 'https://' . self::NEW_HOST . ':8443?a=1', 8443 ),
			'http on 443'          => array( 'http://' . self::NEW_HOST . ':443/', 443 ),
			'https on 80'          => array( 'https://' . self::NEW_HOST . ':80/', 80 ),
			'the highest port'     => array( 'https://' . self::NEW_HOST . ':65535/', 65535 ),
			'the lowest port'      => array( 'https://' . self::NEW_HOST . ':1/', 1 ),
		);
	}

	#[DataProvider( 'boundPorts' )]
	#[RequiresPhpExtension( 'curl' )]
	public function testTheBindingCarriesThePortOfTheAddressOrOfItsScheme( string $url, int $port ): void {
		$GLOBALS['ab_test_http_curl'] = true;
		$this->serve( $url, 'body', 'text/plain' );
		self::assertSame( 'body', wp_remote_retrieve_body( $this->bound( $url, array(), array( self::V4 ) ) ) );
		self::assertStringContainsString( ' ' . self::NEW_HOST . ':' . $port . ':' . self::V4 . ' ', $GLOBALS['ab_test_http'][0]['curl'] );
	}

	/** @return array<string,array{0:string}> */
	public static function dnsNames(): array {
		return array(
			'the new name of the plan' => array( self::NEW_HOST ),
			'punycode'                 => array( 'xn--bcher-kva.example' ),
			'hyphens'                  => array( 'a-b.c-d.example' ),
			'a label of digits first'  => array( '1.example' ),
			'many labels'              => array( 'a.b.c.d.example' ),
			'a long top label'         => array( 'shop.xn--p1ai' ),
		);
	}

	#[DataProvider( 'dnsNames' )]
	public function testADnsNameIsTaken( string $host ): void {
		$url = 'https://' . $host . '/';
		$this->serve( $url, 'body', 'text/plain' );
		self::assertSame( 'body', wp_remote_retrieve_body( $this->bound( $url, array(), array( self::V4 ) ) ) );
		self::assertSame( array( $url ), self::urls() );
	}

	/** @return array<string,array{0:string,1:string}> An address the bound mode refuses before anything, and the code. */
	public static function boundRefusals(): array {
		$n = self::NEW_HOST;
		return array(
			// 1. The scheme.
			'ftp'                                   => array( 'ftp://' . $n . '/x', 'ab_mcp_outbound_scheme' ),
			'a file'                                => array( 'file:///etc/passwd', 'ab_mcp_outbound_scheme' ),
			'javascript'                            => array( 'javascript:alert(1)', 'ab_mcp_outbound_scheme' ),
			'no scheme'                             => array( $n . '/x', 'ab_mcp_outbound_scheme' ),
			'scheme-relative'                       => array( '//' . $n . '/x', 'ab_mcp_outbound_scheme' ),
			'one slash'                             => array( 'https:/' . $n . '/', 'ab_mcp_outbound_scheme' ),
			'a space in front'                      => array( ' https://' . $n . '/', 'ab_mcp_outbound_scheme' ),
			// 2. A user part.
			'a user'                                => array( 'https://user@' . $n . '/', 'ab_mcp_outbound_userinfo' ),
			'a user and a password'                 => array( 'https://user:pw@' . $n . '/', 'ab_mcp_outbound_userinfo' ),
			'an empty user'                         => array( 'https://@' . $n . '/', 'ab_mcp_outbound_userinfo' ),
			'a backslash: parse_url reads the host behind it' => array( 'https://' . $n . '\\@inside.example/', 'ab_mcp_outbound_userinfo' ),
			// 2. The host is a DNS name.
			'IPv4'                                  => array( 'http://127.0.0.1/', 'ab_mcp_outbound_not_dns_name' ),
			'IPv4, octal (libcurl: 127.0.0.1)'      => array( 'http://0177.0.0.1/', 'ab_mcp_outbound_not_dns_name' ),
			'IPv4, one number (libcurl: 127.0.0.1)' => array( 'http://2130706433/', 'ab_mcp_outbound_not_dns_name' ),
			'IPv4, hex (libcurl: 127.0.0.1)'        => array( 'http://0x7f.0.0.1/', 'ab_mcp_outbound_not_dns_name' ),
			'a public IPv4'                         => array( 'http://' . self::V4 . '/', 'ab_mcp_outbound_not_dns_name' ),
			'IPv6 in brackets'                      => array( 'http://[::1]/', 'ab_mcp_outbound_not_dns_name' ),
			'IPv6 bare'                             => array( 'http://::1/', 'ab_mcp_outbound_not_dns_name' ),
			'IPv4 inside IPv6'                      => array( 'http://[::ffff:127.0.0.1]/', 'ab_mcp_outbound_not_dns_name' ),
			'a public IPv6 bare'                    => array( 'http://2606:2800:220:1:248:1893:25c8:1946/', 'ab_mcp_outbound_not_dns_name' ),
			'localhost, no dot'                     => array( 'http://localhost/', 'ab_mcp_outbound_not_dns_name' ),
			'a name without a dot'                  => array( 'http://example/', 'ab_mcp_outbound_not_dns_name' ),
			'the last label a number'               => array( 'http://files.9example/', 'ab_mcp_outbound_not_dns_name' ),
			'the last label starts with a hyphen'   => array( 'http://files.-example/', 'ab_mcp_outbound_not_dns_name' ),
			'an underscore'                         => array( 'http://files_x.example/', 'ab_mcp_outbound_not_dns_name' ),
			'an empty label'                        => array( 'http://files..example/', 'ab_mcp_outbound_not_dns_name' ),
			'a dot in front'                        => array( 'http://.files.example/', 'ab_mcp_outbound_not_dns_name' ),
			'a dot at the end'                      => array( 'http://files.example./', 'ab_mcp_outbound_not_dns_name' ),
			'another script'                        => array( 'http://bücher.example/', 'ab_mcp_outbound_not_dns_name' ),
			'percent-encoded'                       => array( 'http://%66iles.example/', 'ab_mcp_outbound_not_dns_name' ),
			'a backslash'                           => array( 'http://files.example\\x/', 'ab_mcp_outbound_not_dns_name' ),
			'a line break at the end of the host'   => array( "http://files.example\n/x", 'ab_mcp_outbound_not_dns_name' ),
			'no host'                               => array( 'https:///x', 'ab_mcp_outbound_not_dns_name' ),
			// 2. Capitals.
			'a capital'                             => array( 'https://Video2.alphabridge-mcp.com/', 'ab_mcp_outbound_host_case' ),
			'all capitals'                          => array( 'https://VIDEO2.ALPHABRIDGE-MCP.COM/', 'ab_mcp_outbound_host_case' ),
			// 2. The port.
			'port 0'                                => array( 'https://' . $n . ':0/', 'ab_mcp_outbound_port' ),
			'an empty port'                         => array( 'https://' . $n . ':/', 'ab_mcp_outbound_port' ),
			'port 65536'                            => array( 'https://' . $n . ':65536/', 'ab_mcp_outbound_port' ),
			'a port of six digits'                  => array( 'https://' . $n . ':100000/', 'ab_mcp_outbound_port' ),
			'a leading zero'                        => array( 'https://' . $n . ':08080/', 'ab_mcp_outbound_port' ),
			'not a number'                          => array( 'https://' . $n . ':80a/', 'ab_mcp_outbound_port' ),
			'two colons'                            => array( 'https://' . $n . '::80/', 'ab_mcp_outbound_port' ),
			'a line break after the port'           => array( 'https://' . $n . ":80\n/", 'ab_mcp_outbound_port' ),
			// 3. A fragment.
			'a fragment'                            => array( 'https://' . $n . '/#top', 'ab_mcp_outbound_fragment' ),
			'an empty fragment'                     => array( 'https://' . $n . '#', 'ab_mcp_outbound_fragment' ),
			'a fragment after a query'              => array( 'https://' . $n . '/?a=1#b', 'ab_mcp_outbound_fragment' ),
			'a host in the fragment'                => array( 'https://' . $n . '#@inside.example/', 'ab_mcp_outbound_fragment' ),
		);
	}

	/** What each refusal tells the caller to do. */
	const BOUND_STEPS = array(
		'ab_mcp_outbound_scheme'       => 'Give the address with its scheme, for example https://www.example.com/.',
		'ab_mcp_outbound_userinfo'     => 'carries a user part (a name before "@" in front of the host). Give the address without it',
		'ab_mcp_outbound_not_dns_name' => 'its host must be a DNS name such as www.example.com, not an IP address, a number or a name without a dot. Give the address with the domain name of the site',
		'ab_mcp_outbound_host_case'    => 'Give the host name in lower case, for example https://www.example.com/ (a DNS name does not depend on case).',
		'ab_mcp_outbound_port'         => 'Give the port as digits after the host',
		'ab_mcp_outbound_fragment'     => 'carries a fragment (a part after "#"), which a request never sends. Give the address without it.',
		'ab_mcp_outbound_pin_empty'    => 'Give the public IP addresses of the server, or leave pin_to out',
		'ab_mcp_outbound_pin_local'    => 'Give only public IPv4 or IPv6 addresses of the server.',
		'ab_mcp_outbound_unresolved'   => 'its host name has no address. Check the spelling of the host, or give the IP addresses of the server to tie the request to (pin_to).',
		'ab_mcp_outbound_local'        => 'points to an address that is local or not public. Give a host name whose every address is public',
	);

	#[DataProvider( 'boundRefusals' )]
	public function testAnAddressOutsideTheContractIsNotAskedAndSaysWhy( string $url, string $code ): void {
		$this->serve( $url, 'secret', 'text/plain' );
		// With pin_to, so nothing but the form decides; without it the
		// refusal comes before any lookup all the same.
		foreach ( array( array( self::V4 ), null ) as $pin_to ) {
			$result = $this->bound( $url, array(), $pin_to );
			self::assertSame( $code, self::code( $result ) );
			self::assertStringStartsWith( 'The address was not asked: ', self::message( $result ) );
			self::assertStringContainsString( self::BOUND_STEPS[ $code ], self::message( $result ) );
			self::assertSame( array( 'url' => $url ), $result->get_error_data() );
			$this->assertNotAsked();
		}
	}

	/** @return array<string,array{0:mixed,1:string}> */
	public static function pinRefusals(): array {
		return array(
			'an empty list'                  => array( array(), 'ab_mcp_outbound_pin_empty' ),
			'not a list'                     => array( self::V4, 'ab_mcp_outbound_pin_empty' ),
			'a private address among them'   => array( array( self::V4, '10.0.0.1' ), 'ab_mcp_outbound_pin_local' ),
			'this machine'                   => array( array( '127.0.0.1' ), 'ab_mcp_outbound_pin_local' ),
			'IPv4 mapped into IPv6'          => array( array( '::ffff:127.0.0.1' ), 'ab_mcp_outbound_pin_local' ),
			'IPv6 loopback'                  => array( array( '::1' ), 'ab_mcp_outbound_pin_local' ),
			'IPv6 unique local, among'       => array( array( self::V6, 'fd00::1' ), 'ab_mcp_outbound_pin_local' ),
			'link-local (cloud metadata)'    => array( array( '169.254.169.254' ), 'ab_mcp_outbound_pin_local' ),
			'a name, not an address'         => array( array( 'files.example' ), 'ab_mcp_outbound_pin_local' ),
			'IPv6 in brackets'               => array( array( '[' . self::V6 . ']' ), 'ab_mcp_outbound_pin_local' ),
			'an entry that is not a string'  => array( array( self::V4, array( self::V4 ) ), 'ab_mcp_outbound_pin_local' ),
			'a number'                       => array( array( 1572395042 ), 'ab_mcp_outbound_pin_local' ),
		);
	}

	#[DataProvider( 'pinRefusals' )]
	public function testPinToHoldsOnlyPublicAddresses( $pin_to, string $code ): void {
		$url = 'https://' . self::NEW_HOST . '/';
		$this->serve( $url, 'secret', 'text/plain' );
		$result = $this->bound( $url, array(), $pin_to );
		self::assertSame( $code, self::code( $result ) );
		self::assertStringContainsString( self::BOUND_STEPS[ $code ], self::message( $result ) );
		self::assertSame( array( 'url' => $url ), $result->get_error_data() );
		$this->assertNotAsked();
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function boundNamesRefused(): array {
		return array(
			'a name without an address'      => array( 'nowhere.example', 'ab_mcp_outbound_unresolved' ),
			'public and private mixed, AAAA' => array( 'mixed.example', 'ab_mcp_outbound_local' ),
			'IPv4 mapped into IPv6'          => array( 'mapped.example', 'ab_mcp_outbound_local' ),
			'a private address'              => array( 'inside.example', 'ab_mcp_outbound_local' ),
			'below localhost'                => array( 'app.localhost', 'ab_mcp_outbound_local' ),
		);
	}

	#[DataProvider( 'boundNamesRefused' )]
	public function testWithoutPinToEveryAddressOfTheNameIsPublic( string $host, string $code ): void {
		$url = 'https://' . $host . '/';
		$this->serve( $url, 'secret', 'text/plain' );
		$result = $this->bound( $url );
		self::assertSame( $code, self::code( $result ) );
		self::assertStringContainsString( self::BOUND_STEPS[ $code ], self::message( $result ) );
		self::assertSame( array( 'url' => $url ), $result->get_error_data() );
		self::assertSame( array(), $GLOBALS['ab_test_http'], 'Nothing was sent.' );
		self::assertSame( 'app.localhost' === $host ? array() : array( $host ), $this->looked_up, 'A name below localhost is local before any lookup.' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testThroughTheProxyOfWordPressTheBoundModeSendsNothing(): void {
		self::proxy();
		$url = 'https://' . self::NEW_HOST . '/';
		$this->serve( $url, 'secret', 'text/plain' );
		$result = $this->bound( $url, array(), array( self::V4 ) );
		self::assertSame( 'ab_mcp_outbound_proxy', self::code( $result ) );
		self::assertStringContainsString( 'which looks the name up itself', self::message( $result ) );
		self::assertStringEndsWith( 'Add the host to WP_PROXY_BYPASS_HOSTS in wp-config.php, then try again.', self::message( $result ) );
		self::assertSame( array( 'url' => $url ), $result->get_error_data() );
		self::assertSame( array(), $GLOBALS['ab_test_http'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	#[RequiresPhpExtension( 'curl' )]
	public function testAHostInTheBypassListGoesDirectAndTied(): void {
		self::proxy( 'localhost, *.alphabridge-mcp.com' );
		$GLOBALS['ab_test_http_curl'] = true;
		$url                          = 'https://' . self::NEW_HOST . '/';
		$this->serve( $url, 'body', 'text/plain' );
		self::assertSame( 'body', wp_remote_retrieve_body( $this->bound( $url, array(), array( self::V4 ) ) ) );
		self::assertStringContainsString( ' ' . self::NEW_HOST . ':443:' . self::V4 . ' ', $GLOBALS['ab_test_http'][0]['curl'] );
	}

	#[RequiresPhpExtension( 'curl' )]
	public function testTheBoundModeIgnoresTheProxyOfTheEnvironment(): void {
		$url = 'https://' . self::NEW_HOST . '/';
		$this->serve( $url, 'body', 'text/plain' );
		$logs = array();
		self::probe_environment_proxy( $logs );
		self::assertSame( 'body', wp_remote_retrieve_body( $this->bound( $url, array(), array( self::V4 ) ) ) );
		self::assertNoEnvironmentProxy( $logs, 1 );
	}

	public function testWithoutCurlTheBoundModeSendsNothing(): void {
		$GLOBALS['ab_test_http_transport'] = 'fsockopen';
		$url                               = 'https://' . self::NEW_HOST . '/';
		$this->serve( $url, 'secret', 'text/plain' );
		$result = $this->bound( $url, array(), array( self::V4 ) );
		self::assertSame( 'ab_mcp_outbound_unbound', self::code( $result ) );
		self::assertSame( 'Not sent: without cURL the request cannot be tied to the checked addresses. Ask the host to enable the curl extension of PHP, then try again.', self::message( $result ) );
		self::assertSame( array( 'url' => $url ), $result->get_error_data() );
		self::assertSame( array(), $GLOBALS['ab_test_http'], 'Refused before the transport connects.' );
		self::assertFalse( has_action( 'requests-fsockopen.before_request' ) );
		self::assertFalse( has_action( 'http_api_curl' ) );
	}

	public function testAnAnswerThatCameWithoutTheBindingIsDiscarded(): void {
		// Another transport, or a plugin that answers on pre_http_request:
		// the hook of cURL never ran.
		$GLOBALS['ab_test_http_transport'] = 'other';
		$url                               = 'https://' . self::NEW_HOST . '/';
		$this->serve( $url, 'secret', 'text/plain' );
		$result = $this->bound( $url, array(), array( self::V4 ) );
		self::assertSame( 'ab_mcp_outbound_unbound', self::code( $result ) );
		self::assertStringStartsWith( 'The answer was discarded: the request went out without cURL', self::message( $result ) );
		self::assertStringEndsWith( '(the filter pre_http_request); then try again.', self::message( $result ) );
		self::assertSame( array( 'url' => $url ), $result->get_error_data() );
	}

	public function testAnErrorOfTheTransportComesBackAsItIs(): void {
		$url                   = 'https://' . self::NEW_HOST . '/';
		$this->answers[ $url ] = new WP_Error( 'http_request_failed', 'cURL error 60: SSL certificate problem: unable to get local issuer certificate' );
		$result                = $this->bound( $url, array(), array( self::V4 ) );
		self::assertSame( 'http_request_failed', self::code( $result ), 'A TLS error is the caller\'s to read.' );
		self::assertSame( 'cURL error 60: SSL certificate problem: unable to get local issuer certificate', self::message( $result ) );
	}

	public function testGetSaysWhatItSaidBeforeWithoutCurl(): void {
		// The messages of get() moved into SAY_GET when the transport became
		// one for both modes; what its callers read stays word for word.
		$this->serve( 'https://files.example/a', 'body', 'text/plain' );
		$GLOBALS['ab_test_http_transport'] = 'fsockopen';
		$result                            = AB_MCP_Outbound::get( 'https://files.example/a', array() );
		self::assertSame( 'ab_mcp_outbound_unbound', self::code( $result ) );
		self::assertSame( 'Not sent: without cURL the request cannot be tied to the checked addresses.', self::message( $result ) );
		self::assertSame( array( 'url' => 'https://files.example/a', 'hop' => 0 ), $result->get_error_data() );
		$GLOBALS['ab_test_http_transport'] = 'other';
		$result                            = AB_MCP_Outbound::get( 'https://files.example/a', array() );
		self::assertSame( 'ab_mcp_outbound_unbound', self::code( $result ) );
		self::assertSame( 'The answer was discarded: the request went out without cURL, so it was not tied to the checked addresses.', self::message( $result ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testGetSaysWhatItSaidBeforeBehindTheProxy(): void {
		self::proxy();
		$result = AB_MCP_Outbound::get( 'https://files.example/a', array() );
		self::assertSame( 'ab_mcp_outbound_proxy', self::code( $result ) );
		self::assertSame( 'The address was not asked: the request would go through the proxy, which looks the name up itself.', self::message( $result ) );
		self::assertSame( array( 'url' => 'https://files.example/a', 'hop' => 0 ), $result->get_error_data() );
	}

	public function testGetStillSendsThroughWpSafeRemoteGet(): void {
		// The other mode is unchanged: every address it fetches goes through
		// wp_safe_remote_get() as before.
		$this->boundTransport();
		$this->serve( 'https://files.example/a', 'body', 'text/plain' );
		self::assertSame( 'body', wp_remote_retrieve_body( AB_MCP_Outbound::get( 'https://files.example/a', array() ) ) );
		self::assertSame( 'wp_safe_remote_get', $GLOBALS['ab_test_http'][0]['via'] );
		self::assertArrayNotHasKey( 'reject_unsafe_urls', $GLOBALS['ab_test_http'][0]['args'], 'wp_safe_remote_get() sets it itself.' );
	}
}
