<?php
/**
 * Faults in every effect of the well-known relay, injected through its test
 * seam (conventions.md «Heikle Zweige …», point 2; hotfix 4.10.1 in main,
 * point 5): the calls object AB_MCP_OAuth_Well_Known_Calls (fetch, cache,
 * disk, the statements of the lock) in AB_MCP_OAuth::$naht_oauth_well_known,
 * replaced by the proxy of tests/integration/support/class-test-seam.php.
 *
 * Effects to the outside: the lock row (INSERT IGNORE, the DELETE of an
 * expired lock, its release), the cache (transient read and write), the
 * disk (realpath() of the document root and of the other site's
 * wp-load.php), the fetch, foreign hooks (the REST prefix, the cURL hook of
 * the HTTP API, the home address). Kinds of fault: false, a Throwable,
 * a concurrent writer, half written, a read error. An abort and the time
 * limit of PHP need a process of their own: WellKnownAbortTest.
 *
 * What must hold in every case: the own metadata never stand in for another
 * site's; whatever the fault, the answer is the other site's document
 * checked as always, or a refusal; a fault that ends the request (a
 * Throwable) leaves at most a lock row, which expires on its own
 * (WELL_KNOWN_TIMEOUT + 2 seconds) and is then taken over.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_OAuth;
use AB_MCP_Test_Seam;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/support/wpdb-lock-fake.php';
require_once __DIR__ . '/support/well-known-docroot.php';
require_once __DIR__ . '/integration/support/class-test-abort.php';
require_once __DIR__ . '/integration/support/class-test-seam.php';

final class WellKnownFaultTest extends TestCase {

	private int $fetches = 0;
	/** @var mixed */
	private $saved_wpdb = null;
	private string $dir = '';
	private string $p   = '';
	private string $path = '';
	private string $issuer = '';

	protected function setUp(): void {
		ab_test_reset();
		$this->fetches    = 0;
		$this->saved_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']  = new \wpdb();
		$this->dir        = ab_test_wk_dir( array( '/staging/123' ), rtrim( ABSPATH, '/' ) );
		$this->p          = '/' . basename( $this->dir );
		$this->path       = '/.well-known/oauth-authorization-server' . $this->p . '/staging/123';
		$this->issuer     = 'https://example.test' . $this->p . '/staging/123';
		$GLOBALS['ab_test_http_answer'] = function ( string $url ) {
			++$this->fetches;
			return $this->siteAnswer( $url );
		};
	}

	protected function tearDown(): void {
		AB_MCP_Test_Seam::restore_all();
		$GLOBALS['wpdb'] = $this->saved_wpdb;
		ab_test_wk_remove( $this->dir );
	}

	/* ------------------------------------------------------------ lock row */

	public function testTheLockCannotBeWrittenSoTheDocumentIsFetchedWithoutIt(): void {
		$this->seam( array( array( 'method' => 'query', 'nth' => 1, 'kind' => 'return', 'value' => false ) ) );

		self::assertSame( 'document', $this->answer()['kind'], 'A database error is not «held».' );
		self::assertSame( 1, $this->fetches );
		self::assertSame( array(), $this->lockRows() );
		self::assertFalse( $GLOBALS['wpdb']->suppressed, 'The error display as it was.' );
	}

	public function testAnExpiredLockCannotBeRemovedSoTheDocumentIsFetchedWithoutIt(): void {
		$GLOBALS['wpdb']->rows[ $this->lock() ] = (string) ( time() - 1 );
		$this->seam( array( array( 'method' => 'query', 'nth' => 2, 'kind' => 'return', 'value' => false ) ) );

		self::assertSame( 'document', $this->answer()['kind'] );
		self::assertSame( 1, $this->fetches );
		self::assertSame( array( 'query', 'query', 'fetch' ), $this->methods( array( 'query', 'fetch' ) ), 'No second INSERT after the failed DELETE.' );
	}

	public function testTheLockCannotBeGivenBackSoItExpiresOnItsOwn(): void {
		$this->seam( array( array( 'method' => 'delete', 'kind' => 'return', 'value' => false ) ) );

		self::assertSame( 'document', $this->answer()['kind'], 'The answer does not depend on the release.' );
		self::assertSame( array( $this->lock() ), $this->lockRows(), 'The row stays.' );
		$this->forgetCache();
		self::assertSame( array( 'kind' => 'refused' ), $this->answer(), 'Held until it expires: no second fetch.' );
		self::assertSame( 1, $this->fetches );
		$GLOBALS['wpdb']->rows[ $this->lock() ] = (string) ( time() - 1 );
		self::assertSame( 'document', $this->answer()['kind'], 'Expired: taken over.' );
		self::assertSame( 2, $this->fetches );
	}

	public function testAThrowableFromTheLockEndsTheRequestWithNothingWritten(): void {
		$this->seam( array( array( 'method' => 'query', 'nth' => 1, 'kind' => 'throw' ) ) );

		$this->assertThrows();
		self::assertSame( 0, $this->fetches );
		self::assertSame( array(), $this->relayKeys(), 'Nothing kept.' );
		self::assertSame( array(), $this->lockRows() );
	}

	public function testAThrowableWhileTheLockIsGivenBackComesAfterTheDocumentWasKept(): void {
		$this->seam( array( array( 'method' => 'delete', 'kind' => 'throw' ) ) );

		$this->assertThrows();
		self::assertCount( 1, $this->relayKeys(), 'The document was kept before the release.' );
		AB_MCP_Test_Seam::restore_all();
		self::assertSame( 'document', $this->answer()['kind'], 'The next request reads it from the cache.' );
		self::assertSame( 1, $this->fetches );
	}

	public function testAConcurrentWriterTakesTheLockFirst(): void {
		$lock = $this->lock();
		$this->seam(
			array(
				array(
					'method' => 'query',
					'nth'    => 1,
					'kind'   => 'before',
					'before' => static function () use ( $lock ): void {
						$GLOBALS['wpdb']->rows[ $lock ] = (string) ( time() + 5 );
					},
				),
			)
		);

		self::assertSame( array( 'kind' => 'refused' ), $this->answer() );
		self::assertSame( 0, $this->fetches, 'The other request fetches.' );
		self::assertSame( array( $lock ), $this->lockRows(), 'Its lock stays.' );
	}

	public function testAConcurrentWriterRemovesTheLockWhileThisRequestFetches(): void {
		$lock = $this->lock();
		$this->seam(
			array(
				array(
					'method' => 'fetch',
					'kind'   => 'before',
					'before' => static function () use ( $lock ): void {
						unset( $GLOBALS['wpdb']->rows[ $lock ] );
					},
				),
			)
		);

		self::assertSame( 'document', $this->answer()['kind'] );
		self::assertSame( array(), $this->lockRows() );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function halfWrittenLocks(): array {
		return array(
			'empty value'     => array( '' ),
			'cut-off number'  => array( '17' ),
			'not a number'    => array( 'abc' ),
		);
	}

	#[DataProvider( 'halfWrittenLocks' )]
	public function testAHalfWrittenLockRowCountsAsExpired( string $value ): void {
		$GLOBALS['wpdb']->rows[ $this->lock() ] = $value;
		$this->seam( array() );

		self::assertSame( 'document', $this->answer()['kind'] );
		self::assertSame( 1, $this->fetches );
		self::assertSame( array(), $this->lockRows() );
	}

	/* --------------------------------------------------------------- cache */

	public function testAFailedReadOfTheCacheMeansAnotherFetch(): void {
		$this->seam( array( array( 'method' => 'get_transient', 'nth' => 2, 'kind' => 'return', 'value' => false ) ) );

		self::assertSame( 'document', $this->answer()['kind'] );
		self::assertSame( 'document', $this->answer()['kind'] );
		self::assertSame( 2, $this->fetches );
		self::assertSame( array(), $this->lockRows() );
	}

	public function testAFailedWriteOfTheCacheStillAnswers(): void {
		$this->seam( array( array( 'method' => 'set_transient', 'kind' => 'return', 'value' => false ) ) );

		self::assertSame( 'document', $this->answer()['kind'] );
		self::assertSame( 'document', $this->answer()['kind'] );
		self::assertSame( 2, $this->fetches, 'Nothing kept: fetched again.' );
		self::assertSame( array(), $this->lockRows() );
	}

	public function testAThrowableWhileTheCacheIsReadWritesNothing(): void {
		$this->seam( array( array( 'method' => 'get_transient', 'kind' => 'throw' ) ) );

		$this->assertThrows();
		self::assertSame( 0, $this->fetches );
		self::assertSame( array(), $GLOBALS['wpdb']->queries, 'Not even the lock.' );
	}

	public function testAThrowableWhileTheCacheIsWrittenLeavesTheLockToExpire(): void {
		$this->seam( array( array( 'method' => 'set_transient', 'kind' => 'throw' ) ) );

		$this->assertThrows();
		self::assertSame( 1, $this->fetches );
		self::assertSame( array(), $this->relayKeys() );
		self::assertSame( array( $this->lock() ), $this->lockRows(), 'The release did not run.' );
		AB_MCP_Test_Seam::restore_all();
		self::assertSame( array( 'kind' => 'refused' ), $this->answer(), 'Held until it expires.' );
		$GLOBALS['wpdb']->rows[ $this->lock() ] = (string) ( time() - 1 );
		self::assertSame( 'document', $this->answer()['kind'] );
		self::assertSame( 2, $this->fetches );
	}

	public function testAConcurrentWriterOfTheCacheIsOverwrittenAndReadIsCheckedAgain(): void {
		$key = $this->key();
		$this->seam(
			array(
				array(
					'method' => 'set_transient',
					'kind'   => 'before',
					'before' => static function () use ( $key ): void {
						set_transient( $key, array( 'document' => array( 'issuer' => 'https://example.test/other' ) ), 300 );
					},
				),
			)
		);

		self::assertSame( 'document', $this->answer()['kind'] );
		self::assertSame( $this->issuer, $GLOBALS['ab_test_transients'][ $key ]['document']['issuer'], 'The last writer, this request.' );
		$GLOBALS['ab_test_transients'][ $key ]['document']['issuer'] = 'https://example.test/other';
		self::assertSame( array( 'kind' => 'refused' ), $this->answer(), 'What another wrote is checked when read.' );
	}

	/**
	 * A cache entry as it may be found: cut off, of another form, or read
	 * wrong. Either it counts as missing (a fetch) or as a kept failure (a
	 * refusal without a fetch); never as a document unchecked.
	 *
	 * @return array<string,array{0:mixed,1:string,2:int}> value, kind, fetches
	 */
	public static function halfWrittenCache(): array {
		return array(
			'a string, not the entry'          => array( 'x', 'document', 1 ),
			'an empty array'                   => array( array(), 'document', 1 ),
			'the bytes of a serialized entry'  => array( 'a:1:{s:8:"document";', 'document', 1 ),
			'document not an array'            => array( array( 'document' => 'x' ), 'refused', 0 ),
			'a kept failure'                   => array( array( 'document' => null ), 'refused', 0 ),
			'a document of another issuer'     => array( array( 'document' => array( 'issuer' => 'https://example.test/other' ) ), 'refused', 0 ),
		);
	}

	#[DataProvider( 'halfWrittenCache' )]
	public function testAHalfWrittenOrMisreadCacheEntry( $value, string $kind, int $fetches ): void {
		$this->seam( array( array( 'method' => 'get_transient', 'nth' => 1, 'kind' => 'return', 'value' => $value ) ) );

		self::assertSame( $kind, $this->answer()['kind'] );
		self::assertSame( $fetches, $this->fetches );
	}

	/* ---------------------------------------------------------------- disk */

	/**
	 * @return array<string,array{0:int,1:mixed}> which realpath(), what it gives
	 */
	public static function diskFaults(): array {
		return array(
			'the document root cannot be read'       => array( 1, false ),
			'wp-load.php cannot be read'             => array( 2, false ),
			'wp-load.php resolves outside the root'  => array( 2, '/elsewhere/staging/123/wp-load.php' ),
		);
	}

	#[DataProvider( 'diskFaults' )]
	public function testADiskThatCannotBeReadMeansARefusalWithNothingWritten( int $nth, $value ): void {
		$this->seam( array( array( 'method' => 'realpath', 'nth' => $nth, 'kind' => 'return', 'value' => $value ) ) );

		self::assertSame( array( 'kind' => 'refused' ), $this->answer() );
		self::assertSame( 0, $this->fetches );
		self::assertSame( array(), $GLOBALS['wpdb']->queries );
		self::assertSame( array(), $this->relayKeys() );
	}

	public function testAThrowableFromTheDiskWritesNothing(): void {
		$this->seam( array( array( 'method' => 'realpath', 'kind' => 'throw' ) ) );

		$this->assertThrows();
		self::assertSame( 0, $this->fetches );
		self::assertSame( array(), $GLOBALS['wpdb']->queries );
		self::assertSame( array(), $this->relayKeys() );
	}

	public function testTheOtherSiteGoesAwayBetweenTheLookAndTheFetch(): void {
		$load = $this->dir . '/staging/123/wp-load.php';
		$this->seam(
			array(
				array(
					'method' => 'fetch',
					'kind'   => 'before',
					'before' => static function () use ( $load ): void {
						unlink( $load );
					},
				),
			)
		);
		$GLOBALS['ab_test_http_answer'] = function () {
			++$this->fetches;
			return ab_test_http_response( 404, '<html>not found</html>', array( 'Content-Type' => 'text/html' ) );
		};

		self::assertSame( array( 'kind' => 'refused' ), $this->answer(), 'The answer of the fetch decides.' );
		self::assertSame( 1, $this->fetches );
		self::assertSame( array(), $this->lockRows() );
	}

	/* --------------------------------------------------------------- fetch */

	public function testAFetchThatGivesFalseIsAFailure(): void {
		$this->seam( array( array( 'method' => 'fetch', 'kind' => 'return', 'value' => false ) ) );

		self::assertSame( array( 'kind' => 'refused' ), $this->answer() );
		self::assertSame( array( 'kind' => 'refused' ), $this->answer(), 'Kept as a failure.' );
		self::assertSame( 1, $this->seamCalls( 'fetch' ) );
		self::assertSame( array(), $this->lockRows() );
	}

	public function testAThrowableFromTheFetchLeavesTheLockToExpire(): void {
		$this->seam( array( array( 'method' => 'fetch', 'kind' => 'throw' ) ) );

		$this->assertThrows();
		self::assertSame( array(), $this->relayKeys() );
		self::assertSame( array( $this->lock() ), $this->lockRows() );
		AB_MCP_Test_Seam::restore_all();
		self::assertSame( array( 'kind' => 'refused' ), $this->answer() );
		self::assertSame( 0, $this->fetches );
		$GLOBALS['wpdb']->rows[ $this->lock() ] = (string) ( time() - 1 );
		self::assertSame( 'document', $this->answer()['kind'] );
	}

	/**
	 * An answer cut off or read wrong.
	 *
	 * @return array<string,array{0:string,1:array<string,string>}> body, headers
	 */
	public static function halfAnswers(): array {
		$json = array( 'Content-Type' => 'application/json' );
		return array(
			'cut off after 40 bytes'  => array( '40', $json ),
			'empty'                   => array( '0', $json ),
			'bytes before the JSON'   => array( "\xff\xfe", $json ),
		);
	}

	#[DataProvider( 'halfAnswers' )]
	public function testAHalfReadAnswerIsAFailure( string $how, array $headers ): void {
		$doc  = $this->siteAnswer( $this->issuer . '/.well-known/oauth-authorization-server' )['body'];
		$body = ctype_digit( $how ) ? substr( $doc, 0, (int) $how ) : $how . $doc;
		$this->seam( array( array( 'method' => 'fetch', 'kind' => 'return', 'value' => ab_test_http_response( 200, $body, $headers ) ) ) );

		self::assertSame( array( 'kind' => 'refused' ), $this->answer() );
		self::assertSame( array(), $this->lockRows() );
	}

	public function testASecondRequestWhileTheFetchRunsGetsARefusal(): void {
		$inner = null;
		$this->seam(
			array(
				array(
					'method' => 'fetch',
					'nth'    => 1,
					'kind'   => 'before',
					'before' => function () use ( &$inner ): void {
						$inner = AB_MCP_OAuth::well_known_answer( $this->path );
					},
				),
			)
		);

		self::assertSame( 'document', $this->answer()['kind'] );
		self::assertSame( array( 'kind' => 'refused' ), $inner );
		self::assertSame( 1, $this->fetches );
	}

	/* --------------------------------------------------------- foreign hooks */

	public function testAForeignHookOnTheRestPrefixThatThrowsWritesNothing(): void {
		$this->seam( array() );
		add_filter(
			'rest_url_prefix',
			static function (): string {
				throw new RuntimeException( 'a foreign hook' );
			}
		);

		$this->assertThrows( '/.well-known/oauth-protected-resource' . $this->p . '/staging/123/wp-json/alphabridge/v1/mcp' );
		self::assertSame( 0, $this->fetches );
		self::assertSame( array(), $this->seamMethods() );
	}

	public function testAForeignHookOfTheHttpApiThatThrowsLeavesTheLockToExpire(): void {
		$this->seam( array() );
		add_action(
			'http_api_curl',
			static function (): void {
				throw new RuntimeException( 'a foreign hook' );
			}
		);

		$this->assertThrows();
		self::assertSame( array(), $this->relayKeys() );
		self::assertSame( array( $this->lock() ), $this->lockRows() );
	}

	public function testAHomeAddressAFilterBrokeMeansNoFetch(): void {
		$this->seam( array() );
		$GLOBALS['ab_test_home'] = 'example.test';

		self::assertSame( array( 'kind' => 'refused' ), AB_MCP_OAuth::well_known_answer( '/.well-known/oauth-authorization-server' . $this->p . '/staging/123' ) );
		self::assertSame( 0, $this->fetches );
		self::assertSame( array(), $GLOBALS['wpdb']->queries );
	}

	/* ------------------------------------------------------------- helpers */

	/** Install the seam with this fault plan over the real calls. */
	private function seam( array $faults ): void {
		AB_MCP_Test_Seam::restore_all();
		AB_MCP_Test_Seam::install( AB_MCP_OAuth::class, 'naht_oauth_well_known', 'AB_MCP_OAuth_Well_Known_Calls', $faults );
	}

	private function answer(): array {
		return AB_MCP_OAuth::well_known_answer( $this->path );
	}

	private function assertThrows( string $path = '' ): void {
		try {
			AB_MCP_OAuth::well_known_answer( '' !== $path ? $path : $this->path );
			self::fail( 'The Throwable did not reach the caller.' );
		} catch ( RuntimeException $e ) {
			self::assertNotSame( '', $e->getMessage() );
		}
	}

	/** The methods of the calls object called so far, in order (only these, if given). */
	private function seamMethods( array $only = array() ): array {
		$out = array();
		foreach ( AB_MCP_Test_Seam::recorded() as $calls ) {
			foreach ( $calls as $call ) {
				if ( array() === $only || in_array( $call['method'], $only, true ) ) {
					$out[] = $call['method'];
				}
			}
		}
		return $out;
	}

	private function methods( array $only ): array {
		return $this->seamMethods( $only );
	}

	private function seamCalls( string $method ): int {
		return count( $this->seamMethods( array( $method ) ) );
	}

	private function hash(): string {
		return md5( 'oauth-authorization-server|' . $this->issuer . '|' );
	}

	private function lock(): string {
		return 'ab_mcp_wk_lock_' . $this->hash();
	}

	private function key(): string {
		return 'ab_mcp_wk_' . $this->hash();
	}

	private function forgetCache(): void {
		foreach ( $this->relayKeys() as $key ) {
			delete_transient( $key );
		}
	}

	/** @return string[] */
	private function lockRows(): array {
		return array_values( array_filter( array_keys( $GLOBALS['wpdb']->rows ), static fn( $key ): bool => 0 === strpos( (string) $key, 'ab_mcp_wk_lock_' ) ) );
	}

	/** @return string[] */
	private function relayKeys(): array {
		return array_values( array_filter( array_keys( $GLOBALS['ab_test_transients'] ), static fn( $key ): bool => 0 === strpos( (string) $key, 'ab_mcp_wk_' ) ) );
	}

	/** The other site answers as an AlphaBridge site whose home is the path before /.well-known/. */
	private function siteAnswer( string $url ): array {
		preg_match( '#\A(https?://[^/]+)(.*)/\.well-known/(oauth-authorization-server|oauth-protected-resource)#', $url, $m );
		$saved                        = array( $GLOBALS['ab_test_home'], $GLOBALS['ab_test_rest_base'] ?? null );
		$GLOBALS['ab_test_home']      = $m[1] . $m[2];
		$GLOBALS['ab_test_rest_base'] = $m[1] . $m[2] . '/wp-json/';
		$data                         = 'oauth-authorization-server' === $m[3] ? AB_MCP_OAuth::metadata() : AB_MCP_OAuth::resource_metadata();
		$GLOBALS['ab_test_home']      = $saved[0];
		if ( null === $saved[1] ) {
			unset( $GLOBALS['ab_test_rest_base'] );
		} else {
			$GLOBALS['ab_test_rest_base'] = $saved[1];
		}
		return ab_test_http_response( 200, (string) wp_json_encode( $data ), array( 'Content-Type' => 'application/json' ) );
	}
}
