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
	const SETTINGS = 'https://example.test/wp-admin/options-general.php?page=alphabridge-mcp';

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

	/** A cookie of an answer as WordPress hands it over (WP_HTTP_Requests_Response::get_cookies()): set without a Domain, so host-only. */
	private static function cookie( string $name, string $value, string $domain, array $more = array() ): \WP_Http_Cookie {
		return new \WP_Http_Cookie(
			$more + array(
				'name'      => $name,
				'value'     => $value,
				'domain'    => $domain,
				'path'      => '/',
				'host_only' => true,
			)
		);
	}

	/**
	 * An answer that sets cookies as WordPress returns it: the Set-Cookie
	 * headers as the server sent them (raw values), and the cookie objects
	 * with the value URL-decoded (WP_HTTP_Requests_Response::get_cookies()).
	 *
	 * @param array<string,string> $set name => raw value, each set without a Domain by the host.
	 */
	private static function answer_setting( int $status, array $headers, array $set, string $host, string $body = '' ): array {
		$set_cookie = array();
		$cookies    = array();
		foreach ( $set as $name => $raw ) {
			$set_cookie[] = $name . '=' . $raw . '; Path=/';
			$cookies[]    = self::cookie( $name, urldecode( $raw ), $host );
		}
		return ab_test_http_response( $status, $body, $headers + array( 'Set-Cookie' => $set_cookie ), $cookies );
	}

	/**
	 * An answer that sets the given cookies (plain values): a Set-Cookie
	 * header for each, as the server sends it, and the objects as WordPress
	 * hands them over, with their attributes.
	 *
	 * @param \WP_Http_Cookie[] $cookies
	 */
	private static function answer_with( int $status, array $headers, array $cookies ): array {
		$set_cookie = array();
		foreach ( $cookies as $cookie ) {
			$set_cookie[] = $cookie->name . '=' . $cookie->value . '; Path=' . $cookie->path;
		}
		return ab_test_http_response( $status, '', $headers + array( 'Set-Cookie' => $set_cookie ), $cookies );
	}

	/** The cookies a recorded request carried, name => value. */
	private static function carried( int $call ): array {
		$out = array();
		foreach ( $GLOBALS['ab_test_http'][ $call ]['args']['cookies'] ?? array() as $cookie ) {
			$out[ $cookie->name ] = $cookie->value;
		}
		return $out;
	}

	/** An answer that serves the file only to a request with the cookie, 403 otherwise. */
	private static function gated( string $name, string $value, string $body, string $type ): callable {
		return static function ( array $args ) use ( $name, $value, $body, $type ): array {
			foreach ( $args['cookies'] ?? array() as $cookie ) {
				if ( $cookie instanceof \WP_Http_Cookie && $cookie->name === $name && $cookie->value === $value ) {
					return ab_test_http_response( 200, $body, array( 'Content-Type' => $type ) );
				}
			}
			return ab_test_http_response( 403, 'no cookie', array( 'Content-Type' => 'text/plain' ) );
		};
	}

	/**
	 * Codex on ccf642a: a download that sets a cookie and redirects to the
	 * file, which answers 403 without it, worked with WordPress following
	 * (its transport carries the cookie) and failed with get() following.
	 */
	public function testTheCookiesOfAnAnswerGoWithTheNextHopAsWordPressSendsThem(): void {
		$this->answers['https://files.example/dl']       = self::answer_with( 302, array( 'Location' => '/file.png' ), array( self::cookie( 'download', 'ok', 'files.example' ) ) );
		$this->answers['https://files.example/file.png'] = self::gated( 'download', 'ok', 'body', 'text/plain' );
		$result = AB_MCP_Outbound::get( 'https://files.example/dl', array( 'redirection' => 3 ) );
		self::assertSame( 'body', wp_remote_retrieve_body( $result ) );
		self::assertArrayNotHasKey( 'cookies', $GLOBALS['ab_test_http'][0]['args'], 'Nothing to carry on the first hop.' );
		self::assertSame( array( 'download' => 'ok' ), self::carried( 1 ) );
	}

	#[DataProvider( 'paths' )]
	public function testADownloadBehindACookieWorksAsBefore( string $path ): void {
		$url = self::address( $path, 'files.example', '/dl' );
		$this->answers[ $url ] = self::answer_with( 302, array( 'Location' => '/store/' . basename( $url ) ), array( self::cookie( 'download', 'ok', 'files.example' ) ) );
		$this->answers[ 'https://files.example/store/' . basename( $url ) ] = self::gated( 'download', 'ok', 'zip' === $path ? self::ZIP : self::PNG, 'zip' === $path ? 'application/zip' : 'image/png' );
		self::assertStored( $path, $this->fetch( $path, $url ) );
		self::assertSame( array( 'download' => 'ok' ), self::carried( 1 ) );
	}

	/** @return array<string,array{0:\WP_Http_Cookie,1:string}> */
	public static function cookiesThatDoNotHold(): array {
		return array(
			'set by another host (host-only)' => array( self::cookie( 'c', 'v', 'files.example' ), 'https://cdn.example/x' ),
			'host-only, a sub host'           => array( self::cookie( 'c', 'v', 'files.example' ), 'https://www.files.example/x' ),
			'expired'                         => array( self::cookie( 'c', 'v', 'files.example', array( 'expires' => time() - 60 ) ), 'https://files.example/x' ),
			'another path'                    => array( self::cookie( 'c', 'v', 'files.example', array( 'path' => '/dl/' ) ), 'https://files.example/other/x' ),
			'another port'                    => array( self::cookie( 'c', 'v', 'files.example', array( 'port' => '8443' ) ), 'https://files.example/x' ),
		);
	}

	#[DataProvider( 'cookiesThatDoNotHold' )]
	public function testACookieThatDoesNotHoldForTheNextHopStaysBehind( \WP_Http_Cookie $cookie, string $target ): void {
		$this->answers['https://files.example/dl'] = self::answer_with( 302, array( 'Location' => $target ), array( $cookie ) );
		$this->serve( $target, 'body', 'text/plain' );
		self::assertSame( 'body', wp_remote_retrieve_body( AB_MCP_Outbound::get( 'https://files.example/dl', array( 'redirection' => 3 ) ) ) );
		self::assertSame( array( 'https://files.example/dl', $target ), self::urls() );
		self::assertArrayNotHasKey( 'cookies', $GLOBALS['ab_test_http'][1]['args'] );
	}

	/** @return array<string,array{0:\WP_Http_Cookie,1:string}> */
	public static function cookiesThatHold(): array {
		return array(
			'the same host'                    => array( self::cookie( 'c', 'v', 'files.example' ), 'https://files.example/other/x' ),
			'a sub host, Domain set'           => array( self::cookie( 'c', 'v', 'files.example', array( 'host_only' => false ) ), 'https://www.files.example/x' ),
			'the path it is set for'           => array( self::cookie( 'c', 'v', 'files.example', array( 'path' => '/dl/' ) ), 'https://files.example/dl/x' ),
			'not yet expired'                  => array( self::cookie( 'c', 'v', 'files.example', array( 'expires' => time() + 3600 ) ), 'https://files.example/x' ),
		);
	}

	#[DataProvider( 'cookiesThatHold' )]
	public function testACookieThatHoldsForTheNextHopGoesWithIt( \WP_Http_Cookie $cookie, string $target ): void {
		$this->answers['https://files.example/dl'] = self::answer_with( 302, array( 'Location' => $target ), array( $cookie ) );
		$this->serve( $target, 'body', 'text/plain' );
		self::assertSame( 'body', wp_remote_retrieve_body( AB_MCP_Outbound::get( 'https://files.example/dl', array( 'redirection' => 3 ) ) ) );
		self::assertSame( array( 'c' => 'v' ), self::carried( 1 ) );
	}

	public function testTheCallersCookiesGoWithEveryHopTheyHoldForAndALaterCookieOfTheSameNameReplacesAnEarlier(): void {
		$this->answers['https://files.example/1'] = self::answer_with( 302, array( 'Location' => '/2' ), array( self::cookie( 'token', 'first', 'files.example' ) ) );
		$this->answers['https://files.example/2'] = self::answer_with( 302, array( 'Location' => 'https://cdn.example/3' ), array( self::cookie( 'token', 'second', 'files.example' ) ) );
		$this->serve( 'https://cdn.example/3', 'body', 'text/plain' );
		$result = AB_MCP_Outbound::get(
			'https://files.example/1',
			array(
				'redirection' => 3,
				// As WordPress takes them: name => value for any host, an object with its own domain.
				'cookies'     => array(
					'session' => 'abc',
					new \WP_Http_Cookie(
						array(
							'name'   => 'cdn',
							'value'  => 'only',
							'domain' => 'cdn.example',
						)
					),
				),
			)
		);
		self::assertSame( 'body', wp_remote_retrieve_body( $result ) );
		self::assertSame( array( 'session' => 'abc' ), self::carried( 0 ), 'The caller\'s cookie for another domain stays behind.' );
		self::assertSame( array( 'session' => 'abc', 'token' => 'first' ), self::carried( 1 ) );
		self::assertSame( array( 'session' => 'abc', 'cdn' => 'only' ), self::carried( 2 ), 'The host-only token of files.example does not go to cdn.example; the caller\'s cookie for it does.' );

		$GLOBALS['ab_test_http'] = array();
		$this->answers['https://files.example/2'] = self::answer_with( 302, array( 'Location' => '/3' ), array( self::cookie( 'token', 'second', 'files.example' ) ) );
		$this->serve( 'https://files.example/3', 'body', 'text/plain' );
		AB_MCP_Outbound::get( 'https://files.example/1', array( 'redirection' => 3 ) );
		self::assertSame( array( 'token' => 'second' ), self::carried( 2 ), 'The later cookie of the same name replaces the earlier, as the transport\'s jar does.' );
		self::assertCount( 1, $GLOBALS['ab_test_http'][2]['args']['cookies'], 'Replaced, not carried twice.' );
	}

	/**
	 * Codex on 92cbcd5: WordPress hands the cookies of an answer over
	 * URL-decoded, its own transport sends the raw header value on when it
	 * follows; get() must do the same, or "a%2Fb+c=d" reaches the next hop
	 * as "a/b c=d".
	 */
	public function testTheValueOfACookieGoesOnAsTheHeaderCarriedIt(): void {
		$this->answers['https://files.example/dl']       = self::answer_setting( 302, array( 'Location' => '/file.png' ), array( 'download' => 'a%2Fb+c=d' ), 'files.example' );
		$this->answers['https://files.example/file.png'] = self::gated( 'download', 'a%2Fb+c=d', 'body', 'text/plain' );
		$result = AB_MCP_Outbound::get( 'https://files.example/dl', array( 'redirection' => 3 ) );
		self::assertSame( 'body', wp_remote_retrieve_body( $result ) );
		self::assertSame( array( 'download' => 'a%2Fb+c=d' ), self::carried( 1 ), 'Raw, with %2F, + and = as the server sent them; not "a/b c=d".' );
		$sent = $GLOBALS['ab_test_http'][1]['args']['cookies'][0];
		self::assertSame( 'files.example', $sent->domain, 'The attributes stay those WordPress read.' );
		self::assertTrue( $sent->host_only );
	}

	public function testSeveralSetCookieHeadersEachKeepTheirValue(): void {
		$this->answers['https://files.example/dl'] = self::answer_setting( 302, array( 'Location' => '/file.png' ), array( 'a' => '1%3D1', 'b' => 'x+y', 'c' => 'plain' ), 'files.example' );
		$this->serve( 'https://files.example/file.png', 'body', 'text/plain' );
		AB_MCP_Outbound::get( 'https://files.example/dl', array( 'redirection' => 3 ) );
		self::assertSame( array( 'a' => '1%3D1', 'b' => 'x+y', 'c' => 'plain' ), self::carried( 1 ) );
	}

	public function testTheValueIsTrimmedAsTheTransportTrimsItAndTheAttributesAreNotPartOfIt(): void {
		// Requests: name and value of the first pair trimmed, the attributes after ";" are not the value.
		$this->answers['https://files.example/dl'] = ab_test_http_response(
			302,
			'',
			array(
				'Location'   => '/file.png',
				'Set-Cookie' => ' d = v%20w ; Path=/; Secure',
			),
			array( self::cookie( 'd', 'v w', 'files.example' ) )
		);
		$this->serve( 'https://files.example/file.png', 'body', 'text/plain' );
		AB_MCP_Outbound::get( 'https://files.example/dl', array( 'redirection' => 3 ) );
		self::assertSame( array( 'd' => 'v%20w' ), self::carried( 1 ) );
	}

	/**
	 * Codex on 3a27944: WordPress hands the cookies of an answer over with
	 * every cookie the request carried (the transport's jar), decoded. An
	 * answer in the middle of a chain that sets nothing itself must not
	 * write the carried value back decoded: the jar learns from an answer
	 * only what its own Set-Cookie headers set.
	 */
	public function testAnAnswerThatSetsNothingDoesNotWriteTheCarriedCookiesBackDecoded(): void {
		$this->answers['https://files.example/start'] = self::answer_setting( 302, array( 'Location' => '/middle' ), array( 'token' => 'a%2Fb+c' ), 'files.example' );
		// No Set-Cookie; WordPress lists the carried token all the same, URL-decoded.
		$this->answers['https://files.example/middle'] = ab_test_http_response( 302, '', array( 'Location' => '/file' ), array( self::cookie( 'token', 'a/b c', 'files.example' ) ) );
		$this->answers['https://files.example/file']   = self::gated( 'token', 'a%2Fb+c', 'body', 'text/plain' );
		$result = AB_MCP_Outbound::get( 'https://files.example/start', array( 'redirection' => 3 ) );
		self::assertSame( 'body', wp_remote_retrieve_body( $result ), 'The file got the raw token, as with WordPress following.' );
		self::assertSame( array( 'token' => 'a%2Fb+c' ), self::carried( 1 ) );
		self::assertSame( array( 'token' => 'a%2Fb+c' ), self::carried( 2 ) );
	}

	public function testAnAnswerThatSetsAnotherCookieDoesNotWriteTheCarriedOneBackDecodedEither(): void {
		$this->answers['https://files.example/start'] = self::answer_setting( 302, array( 'Location' => '/middle' ), array( 'token' => 'a%2Fb+c' ), 'files.example' );
		// Sets «other»; WordPress's list holds the carried token as well, URL-decoded.
		$this->answers['https://files.example/middle'] = ab_test_http_response(
			302,
			'',
			array(
				'Location'   => '/file',
				'Set-Cookie' => 'other=1; Path=/',
			),
			array( self::cookie( 'token', 'a/b c', 'files.example' ), self::cookie( 'other', '1', 'files.example' ) )
		);
		$this->answers['https://files.example/file'] = self::gated( 'token', 'a%2Fb+c', 'body', 'text/plain' );
		$result = AB_MCP_Outbound::get( 'https://files.example/start', array( 'redirection' => 3 ) );
		self::assertSame( 'body', wp_remote_retrieve_body( $result ) );
		self::assertSame( array( 'token' => 'a%2Fb+c', 'other' => '1' ), self::carried( 2 ), 'The token as set at the start, «other» as set in the middle.' );
	}

	public function testACookieWordPressListsWithoutASetCookieHeaderIsNotLearnt(): void {
		// Only the request's own cookies in WordPress's list, nothing set: nothing to carry on.
		$this->answers['https://files.example/dl'] = ab_test_http_response( 302, '', array( 'Location' => '/file.png' ), array( self::cookie( 'download', 'a/b c', 'files.example' ) ) );
		$this->serve( 'https://files.example/file.png', 'body', 'text/plain' );
		AB_MCP_Outbound::get( 'https://files.example/dl', array( 'redirection' => 3 ) );
		self::assertArrayNotHasKey( 'cookies', $GLOBALS['ab_test_http'][1]['args'] );
	}

	public function testAHeaderWordPressHandedNoCookieForIsReadFromTheHeader(): void {
		// An answer a filter made (pre_http_request), with the headers but without the objects.
		$this->answers['https://files.example/dl/x'] = ab_test_http_response(
			302,
			'',
			array(
				'Location'   => '/dl/file.png',
				'Set-Cookie' => array( 'a=1%2B1', 'b=2; Domain=files.example; Path=/' ),
			)
		);
		$this->serve( 'https://files.example/dl/file.png', 'body', 'text/plain' );
		AB_MCP_Outbound::get( 'https://files.example/dl/x', array( 'redirection' => 3 ) );
		self::assertSame( array( 'a' => '1%2B1', 'b' => '2' ), self::carried( 1 ) );
		$cookies = $GLOBALS['ab_test_http'][1]['args']['cookies'];
		self::assertSame( 'files.example', $cookies[0]->domain, 'Domain from the address.' );
		self::assertSame( '/dl/', $cookies[0]->path, 'Path from the address, as WordPress reads a header.' );
		self::assertTrue( $cookies[0]->host_only, 'Without a Domain attribute: host-only.' );
		self::assertFalse( $cookies[1]->host_only );

		// Host-only, so not to a sub host; with the Domain attribute, yes.
		$GLOBALS['ab_test_http'] = array();
		$this->answers['https://files.example/dl/x'] = ab_test_http_response( 302, '', array( 'Location' => 'https://www.files.example/dl/file.png', 'Set-Cookie' => array( 'a=1', 'b=2; Domain=files.example' ) ) );
		$this->serve( 'https://www.files.example/dl/file.png', 'body', 'text/plain' );
		AB_MCP_Outbound::get( 'https://files.example/dl/x', array( 'redirection' => 3 ) );
		self::assertSame( array( 'b' => '2' ), self::carried( 1 ) );
	}

	public function testTheCallersCookiesGoAsGivenAsWordPressSendsThem(): void {
		// WP_Http::normalize_cookies(): a scalar as (string) $value, an object as its value, neither encoded nor decoded.
		$this->redirect( 'https://files.example/1', '/2' );
		$this->serve( 'https://files.example/2', 'body', 'text/plain' );
		AB_MCP_Outbound::get(
			'https://files.example/1',
			array(
				'redirection' => 3,
				'cookies'     => array(
					'session' => 'x%2Fy+z=1',
					new \WP_Http_Cookie(
						array(
							'name'  => 'raw',
							'value' => 'p%20q=r',
						)
					),
				),
			)
		);
		foreach ( array( 0, 1 ) as $hop ) {
			self::assertSame( array( 'session' => 'x%2Fy+z=1', 'raw' => 'p%20q=r' ), self::carried( $hop ), 'Hop ' . $hop );
		}
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
}
