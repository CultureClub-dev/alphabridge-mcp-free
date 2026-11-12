<?php
/**
 * How long a relayed well-known document, or the failure to get one, is kept.
 *
 * When a site relays the well-known document of another site on its host
 * (AB_MCP_OAuth::well_known_answer(), see WellKnownHttpTest), it fetches it
 * from that site. Each answer, and each failure, is kept five minutes per
 * site path and per own address, so a client trying again, or random paths
 * asked again, do not make the site fetch from itself each time; a change of
 * the site's own address starts afresh.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_OAuth;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/wpdb-lock-fake.php';
require_once __DIR__ . '/support/well-known-docroot.php';

final class WellKnownRelayCacheTest extends TestCase {

	/** @var int */
	private int $fetches = 0;
	/** @var mixed The $wpdb another test may have left. */
	private $saved_wpdb = null;
	/**
	 * The other sites live on disk under the document root of this process
	 * (ABSPATH, the tests folder; site_url without a path): in a folder of
	 * their own, $p, with a WordPress under $p/staging/123 and $p/staging/456.
	 */
	private string $dir = '';
	private string $p   = '';
	/** /.well-known/oauth-authorization-server<$p>/staging/123 */
	private string $as_path = '';

	protected function setUp(): void {
		ab_test_reset();
		$this->fetches    = 0;
		$this->saved_wpdb = $GLOBALS['wpdb'] ?? null;
		// The lock is a row the database inserts atomically (support/wpdb-lock-fake.php).
		$GLOBALS['wpdb'] = new \wpdb();
		$this->dir       = ab_test_wk_dir( array( '/staging/123', '/staging/456' ), rtrim( ABSPATH, '/' ) );
		$this->p         = '/' . basename( $this->dir );
		$this->as_path   = '/.well-known/oauth-authorization-server' . $this->p . '/staging/123';
		$this->answerAsASite();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->saved_wpdb;
		ab_test_wk_remove( $this->dir );
	}

	public function testAFetchedDocumentIsKeptFiveMinutes(): void {
		$first  = AB_MCP_OAuth::well_known_answer( $this->as_path );
		$second = AB_MCP_OAuth::well_known_answer( $this->as_path );

		self::assertSame( 'document', $first['kind'] );
		self::assertSame( 'https://example.test' . $this->p . '/staging/123', $first['document']['issuer'] );
		self::assertSame( $first, $second, 'The same document the second time.' );
		self::assertSame( 1, $this->fetches, 'Fetched once.' );
		self::assertSame( array( 300 ), array_values( $this->relayTtls() ) );
	}

	public function testAFailureIsKeptFiveMinutesToo(): void {
		$GLOBALS['ab_test_http_answer'] = function () {
			++$this->fetches;
			return new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		};

		self::assertSame( array( 'kind' => 'refused' ), AB_MCP_OAuth::well_known_answer( $this->as_path ) );
		self::assertSame( array( 'kind' => 'refused' ), AB_MCP_OAuth::well_known_answer( $this->as_path ) );
		self::assertSame( 1, $this->fetches, 'A slow or blocked site costs one fetch per five minutes, not one per request.' );
		self::assertSame( array( 300 ), array_values( $this->relayTtls() ) );
	}

	public function testEachSitePathIsKeptOnItsOwn(): void {
		AB_MCP_OAuth::well_known_answer( $this->as_path );
		AB_MCP_OAuth::well_known_answer( '/.well-known/oauth-authorization-server' . $this->p . '/staging/456' );
		AB_MCP_OAuth::well_known_answer( '/.well-known/oauth-protected-resource' . $this->p . '/staging/123/wp-json/alphabridge/v1/mcp' );

		self::assertSame( 3, $this->fetches );
		self::assertCount( 3, $this->relayTtls() );
	}

	public function testANewOwnAddressStartsAfresh(): void {
		AB_MCP_OAuth::well_known_answer( $this->as_path );
		$GLOBALS['ab_test_home']      = 'https://tunnel.example';
		$GLOBALS['ab_test_rest_base'] = 'https://tunnel.example/wp-json/';

		$answer = AB_MCP_OAuth::well_known_answer( $this->as_path );

		self::assertSame( 2, $this->fetches, 'Not the document kept for the old address.' );
		self::assertSame( 'https://tunnel.example' . $this->p . '/staging/123', $answer['document']['issuer'] );
	}

	public function testAfterFiveMinutesItIsFetchedAgain(): void {
		AB_MCP_OAuth::well_known_answer( $this->as_path );
		foreach ( array_keys( $this->relayTtls() ) as $key ) {
			delete_transient( $key );
		}

		AB_MCP_OAuth::well_known_answer( $this->as_path );

		self::assertSame( 2, $this->fetches );
	}

	public function testOwnPathsNeitherFetchNorKeepAnything(): void {
		AB_MCP_OAuth::well_known_answer( '/.well-known/oauth-authorization-server' );
		AB_MCP_OAuth::well_known_answer( '/.well-known/oauth-protected-resource/wp-json/alphabridge/v1/mcp' );

		self::assertSame( 0, $this->fetches );
		self::assertSame( array(), $this->relayTtls() );
	}

	public function testUncleanPathsNeitherFetchNorKeepAnything(): void {
		AB_MCP_OAuth::well_known_answer( '/.well-known/oauth-authorization-server/.well-known/oauth-authorization-server/x' );
		AB_MCP_OAuth::well_known_answer( '/.well-known/oauth-authorization-server/a/b/c/d/e' );

		self::assertSame( 0, $this->fetches );
		self::assertSame( array(), $this->relayTtls() );
	}

	/* --------------------------------------------- identity in the cache key */

	public function testAChangedRestPrefixIsFetchedAnewNotTakenFromTheCache(): void {
		$first = AB_MCP_OAuth::well_known_answer( '/.well-known/oauth-protected-resource' . $this->p . '/staging/123/wp-json/alphabridge/v1/mcp' );
		add_filter( 'rest_url_prefix', static fn(): string => 'api' );
		$this->answerAsASite( 'api' );

		$second = AB_MCP_OAuth::well_known_answer( '/.well-known/oauth-protected-resource' . $this->p . '/staging/123/api/alphabridge/v1/mcp' );

		self::assertSame( 'https://example.test' . $this->p . '/staging/123/wp-json/alphabridge/v1/mcp', $first['document']['resource'] );
		self::assertSame( 'document', $second['kind'] );
		self::assertSame( 'https://example.test' . $this->p . '/staging/123/api/alphabridge/v1/mcp', $second['document']['resource'], 'Not the document kept for the old resource.' );
		self::assertSame( 2, $this->fetches );
	}

	public function testAKeptDocumentIsCheckedAgainWhenRead(): void {
		AB_MCP_OAuth::well_known_answer( $this->as_path );
		foreach ( array_keys( $this->relayTtls() ) as $key ) {
			$GLOBALS['ab_test_transients'][ $key ]['document']['issuer'] = 'https://example.test/staging/999';
		}

		self::assertSame( array( 'kind' => 'refused' ), AB_MCP_OAuth::well_known_answer( $this->as_path ) );
		self::assertSame( 1, $this->fetches );
	}

	/* --------------------------------------------------------------- lock */

	public function testWhileOneRequestFetchesAPathAnotherGetsA404WithoutFetching(): void {
		$inner  = null;
		$answer = $GLOBALS['ab_test_http_answer'];
		$GLOBALS['ab_test_http_answer'] = function ( string $url, array $args ) use ( &$inner, $answer ) {
			// A second request for the same path arrives while this fetch runs
			// (once; without a lock it would fetch, and arrive again).
			if ( null === $inner ) {
				$inner = array();
				$inner = AB_MCP_OAuth::well_known_answer( $this->as_path );
			}
			return $answer( $url, $args );
		};

		$outer = AB_MCP_OAuth::well_known_answer( $this->as_path );

		self::assertSame( array( 'kind' => 'refused' ), $inner, 'The second request does not fetch the same path again.' );
		self::assertSame( 'document', $outer['kind'] );
		self::assertSame( 1, $this->fetches );
		self::assertSame( array(), $this->locks(), 'The lock is gone after the fetch.' );
	}

	public function testALockIsReleasedAfterAFailedFetchToo(): void {
		$GLOBALS['ab_test_http_answer'] = function () {
			++$this->fetches;
			return new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
		};

		AB_MCP_OAuth::well_known_answer( $this->as_path );

		self::assertSame( array(), $this->locks() );
	}

	public function testAHeldLockMeansNoFetch(): void {
		AB_MCP_OAuth::well_known_answer( $this->as_path );
		$lock = 'ab_mcp_wk_lock_' . substr( (string) array_key_first( $this->relayTtls() ), strlen( 'ab_mcp_wk_' ) );
		foreach ( array_keys( $this->relayTtls() ) as $key ) {
			delete_transient( $key );
		}
		$GLOBALS['wpdb']->rows[ $lock ] = (string) ( time() + 5 );

		self::assertSame( array( 'kind' => 'refused' ), AB_MCP_OAuth::well_known_answer( $this->as_path ) );
		self::assertSame( 1, $this->fetches, 'Another request holds the lock: no fetch.' );
	}

	public function testAnExpiredLockIsTakenOver(): void {
		AB_MCP_OAuth::well_known_answer( $this->as_path );
		$lock = 'ab_mcp_wk_lock_' . substr( (string) array_key_first( $this->relayTtls() ), strlen( 'ab_mcp_wk_' ) );
		foreach ( array_keys( $this->relayTtls() ) as $key ) {
			delete_transient( $key );
		}
		$GLOBALS['wpdb']->rows[ $lock ] = (string) ( time() - 1 );

		self::assertSame( 'document', AB_MCP_OAuth::well_known_answer( $this->as_path )['kind'], 'A request that died while fetching does not block the path.' );
		self::assertSame( 2, $this->fetches );
	}

	public function testTheLockIsOneAtomicInsertAndOneDelete(): void {
		AB_MCP_OAuth::well_known_answer( $this->as_path );

		self::assertCount( 1, $GLOBALS['wpdb']->queries, 'INSERT IGNORE, nothing that looks first and inserts after.' );
		self::assertStringStartsWith( 'INSERT IGNORE INTO wp_options', $GLOBALS['wpdb']->queries[0] );
		self::assertSame( array( true ), $GLOBALS['wpdb']->suppressed_at_query, 'No database message inside the JSON answer.' );
		self::assertSame( array(), $this->locks() );
		self::assertSame( array(), array_values( array_filter( array_keys( $GLOBALS['ab_test_options'] ), static fn( $key ): bool => 0 === strpos( (string) $key, 'ab_mcp_wk_lock_' ) ) ), 'Not through the options API.' );
	}

	public function testWithoutADatabaseAddOptionHoldsTheLock(): void {
		$GLOBALS['wpdb'] = null;
		$inner           = null;
		$answer          = $GLOBALS['ab_test_http_answer'];
		$GLOBALS['ab_test_http_answer'] = function ( string $url, array $args ) use ( &$inner, $answer ) {
			if ( null === $inner ) {
				$inner = array();
				$inner = AB_MCP_OAuth::well_known_answer( $this->as_path );
			}
			return $answer( $url, $args );
		};

		self::assertSame( 'document', AB_MCP_OAuth::well_known_answer( $this->as_path )['kind'] );
		self::assertSame( array( 'kind' => 'refused' ), $inner );
		self::assertSame( 1, $this->fetches );
		self::assertArrayNotHasKey( 'ab_mcp_wk_lock_' . substr( (string) array_key_first( $this->relayTtls() ), strlen( 'ab_mcp_wk_' ) ), $GLOBALS['ab_test_options'] );
	}

	/* ------------------------------------------------------ WordPress on disk */

	public function testAThousandPathsWithoutAWordPressOnDiskFetchNothing(): void {
		for ( $i = 0; $i < 1000; $i++ ) {
			self::assertSame( array( 'kind' => 'refused' ), AB_MCP_OAuth::well_known_answer( '/.well-known/oauth-authorization-server/p' . $i ) );
		}

		self::assertSame( 0, $this->fetches, 'Random paths make the site fetch nothing.' );
		self::assertSame( array(), $this->relayTtls(), 'Nor keep anything: not counted as a failed fetch.' );
		self::assertSame( array(), $GLOBALS['wpdb']->queries, 'Nor touch the lock.' );
	}

	/* ------------------------------------------------------- database errors */

	public function testWhereTheDatabaseRefusesTheLockTheDocumentIsFetchedWithoutIt(): void {
		$GLOBALS['wpdb']->fail = true;

		$answer = AB_MCP_OAuth::well_known_answer( $this->as_path );

		self::assertSame( 'document', $answer['kind'], 'Not a 404 because the lock could not be written.' );
		self::assertSame( 1, $this->fetches );
		self::assertCount( 1, $GLOBALS['wpdb']->queries, 'One try, no takeover of a lock that is not there.' );
		self::assertFalse( $GLOBALS['wpdb']->suppressed, 'The error display is back as it was.' );
	}

	public function testAFailedRemovalOfAnExpiredLockMeansFetchingWithoutIt(): void {
		AB_MCP_OAuth::well_known_answer( $this->as_path );
		$lock = 'ab_mcp_wk_lock_' . substr( (string) array_key_first( $this->relayTtls() ), strlen( 'ab_mcp_wk_' ) );
		foreach ( array_keys( $this->relayTtls() ) as $key ) {
			delete_transient( $key );
		}
		$GLOBALS['wpdb']->rows[ $lock ] = (string) ( time() - 1 );
		$GLOBALS['wpdb']->fail_delete   = true;

		self::assertSame( 'document', AB_MCP_OAuth::well_known_answer( $this->as_path )['kind'], 'A database error is not «held».' );
		self::assertSame( 2, $this->fetches );
		self::assertFalse( $GLOBALS['wpdb']->suppressed );
	}

	public function testDatabaseErrorsAreSuppressedWhileTheLockIsGivenBack(): void {
		AB_MCP_OAuth::well_known_answer( $this->as_path );

		self::assertSame( array( true ), $GLOBALS['wpdb']->suppressed_at_delete, 'No database message inside the JSON answer.' );
		self::assertFalse( $GLOBALS['wpdb']->suppressed, 'And the display as it was afterwards.' );
	}

	/**
	 * Lock rows left behind.
	 *
	 * @return string[]
	 */
	private function locks(): array {
		$rows = $GLOBALS['wpdb'] instanceof \wpdb ? array_keys( $GLOBALS['wpdb']->rows ) : array();
		return array_values( array_filter( $rows, static fn( $key ): bool => 0 === strpos( (string) $key, 'ab_mcp_wk_lock_' ) ) );
	}

	/** The other site answers as an AlphaBridge site whose home is the path before /.well-known/. */
	private function answerAsASite( string $rest_prefix = 'wp-json' ): void {
		$GLOBALS['ab_test_http_answer'] = function ( string $url ) use ( $rest_prefix ) {
			++$this->fetches;
			preg_match( '#\A(https?://[^/]+)(.*)/\.well-known/(oauth-authorization-server|oauth-protected-resource)#', $url, $m );
			$saved                        = array( $GLOBALS['ab_test_home'], $GLOBALS['ab_test_rest_base'] ?? null );
			$GLOBALS['ab_test_home']      = $m[1] . $m[2];
			$GLOBALS['ab_test_rest_base'] = $m[1] . $m[2] . '/' . $rest_prefix . '/';
			$data                         = 'oauth-authorization-server' === $m[3] ? AB_MCP_OAuth::metadata() : AB_MCP_OAuth::resource_metadata();
			$GLOBALS['ab_test_home']      = $saved[0];
			if ( null === $saved[1] ) {
				unset( $GLOBALS['ab_test_rest_base'] );
			} else {
				$GLOBALS['ab_test_rest_base'] = $saved[1];
			}
			return ab_test_http_response( 200, (string) wp_json_encode( $data ), array( 'Content-Type' => 'application/json; charset=utf-8' ) );
		};
	}

	/**
	 * The relay's transients and their lifetimes.
	 *
	 * @return array<string,int>
	 */
	private function relayTtls(): array {
		return array_filter(
			$GLOBALS['ab_test_transient_ttl'],
			static fn( $key ): bool => 0 === strpos( (string) $key, 'ab_mcp_wk_' ),
			ARRAY_FILTER_USE_KEY
		);
	}
}
