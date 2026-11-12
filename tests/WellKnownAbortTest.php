<?php
/**
 * The well-known relay ended in the middle: an abort of the process at each
 * of its effects (no shutdown, SIGKILL), and the time limit of PHP running
 * out while it fetches (a real fatal error, «Maximum execution time …
 * exceeded»). Each request runs in a process of its own
 * (support/well-known-fault-child.php) with its state on disk, as a database
 * and a cache outlive a request; the abort comes from the test seam
 * (AB_MCP_Test_Abort, with its marker as proof that it fired at that call).
 *
 * What must hold: the request that died answered nothing (no document, never
 * the own metadata); what it left is at most the lock row, which holds the
 * path until it expires (WELL_KNOWN_TIMEOUT + 2 seconds) and is then taken
 * over; a document kept before the abort is answered from the cache.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/well-known-docroot.php';

final class WellKnownAbortTest extends TestCase {

	private const PATH = '/.well-known/oauth-authorization-server/staging/123';

	private string $dir   = '';
	private string $work  = '';
	private string $state = '';

	protected function setUp(): void {
		if ( ! function_exists( 'posix_kill' ) || ! function_exists( 'proc_open' ) ) {
			self::markTestSkipped( 'The abort without shutdown needs posix_kill and proc_open.' );
		}
		$this->dir   = ab_test_wk_dir( array( '/staging/123' ) );
		$this->work  = ab_test_wk_dir();
		$this->state = $this->work . '/state.json';
	}

	protected function tearDown(): void {
		ab_test_wk_remove( $this->dir );
		ab_test_wk_remove( $this->work );
	}

	/**
	 * Where the process ends, and what it must leave.
	 *
	 * @return array<string,array{0:array,1:string,2:int,3:bool,4:bool}>
	 *         fault, mode, fetches made, lock row left, document kept
	 */
	public static function aborts(): array {
		$at = static fn( string $method, string $mode, string $when = 'before', int $nth = 1 ): array => array(
			'method' => $method,
			'nth'    => $nth,
			'kind'   => 'abort',
			'point'  => 'well-known/' . $method . '/' . $when,
			'mode'   => $mode,
			'when'   => $when,
		);
		return array(
			'right after the lock row was written'      => array( $at( 'query', 'no_shutdown', 'after' ), 'no_shutdown', 0, true, false ),
			'while it fetches'                          => array( $at( 'fetch', 'no_shutdown' ), 'no_shutdown', 0, true, false ),
			'the time limit of PHP while it fetches'    => array( $at( 'fetch', 'timeout' ), 'timeout', 0, true, false ),
			'after the fetch, before the cache'         => array( $at( 'set_transient', 'no_shutdown' ), 'no_shutdown', 1, true, false ),
			'after the cache, before the release'       => array( $at( 'delete', 'no_shutdown' ), 'no_shutdown', 1, true, true ),
			'a plain exit while it fetches'             => array( $at( 'fetch', 'shutdown' ), 'shutdown', 0, true, false ),
		);
	}

	#[DataProvider( 'aborts' )]
	public function testARequestThatDiesLeavesNothingButALockThatExpires( array $fault, string $mode, int $fetches, bool $lock, bool $kept ): void {
		$marker = $this->work . '/marker.json';
		$run    = $this->child( array( $fault ), $marker );

		// It ended where the plan said, and answered nothing.
		$fired = json_decode( (string) @file_get_contents( $marker ), true );
		self::assertIsArray( $fired, 'The abort fired: ' . $run['out'] . $run['err'] );
		self::assertSame( $fault['point'], $fired['point'] );
		self::assertSame( '', $run['out'], 'No answer at all from the request that died.' );
		if ( 'no_shutdown' === $mode ) {
			self::assertTrue( $run['signaled'], 'Killed by SIGKILL.' );
			self::assertSame( 9, $run['termsig'] );
		}
		if ( 'timeout' === $mode ) {
			self::assertTrue( $fired['shutdown_ran'] ?? false );
			self::assertSame( E_ERROR, $fired['error']['type'] ?? null );
			self::assertStringContainsString( 'Maximum execution time', (string) ( $fired['error']['message'] ?? '' ) );
		}

		$state = $this->readState();
		self::assertSame( $fetches, $state['fetches'], 'Fetches that reached the other site.' );
		self::assertSame( $lock, 1 === count( $state['rows'] ), 'The lock row: ' . json_encode( $state['rows'] ) );
		self::assertSame( $kept, 1 === count( $state['transients'] ), 'The cache: ' . json_encode( $state['transients'] ) );

		// The next request: from the cache where the document was kept;
		// else the path is held until the lock expires.
		$next = $this->child( array() );
		self::assertSame( $kept ? 'document' : 'refused', json_decode( $next['out'], true )['kind'] ?? null, $next['out'] . $next['err'] );
		self::assertSame( $fetches, $this->readState()['fetches'], 'No fetch while the lock holds.' );

		if ( ! $kept ) {
			// The lock expired: the next request takes it over and fetches.
			$state = $this->readState();
			foreach ( array_keys( $state['rows'] ) as $name ) {
				$state['rows'][ $name ] = (string) ( time() - 1 );
			}
			file_put_contents( $this->state, (string) json_encode( $state ) );
			$after = $this->child( array() );
			self::assertSame( 'document', json_decode( $after['out'], true )['kind'] ?? null, $after['out'] . $after['err'] );
			$state = $this->readState();
			self::assertSame( $fetches + 1, $state['fetches'] );
			self::assertSame( array(), $state['rows'], 'Given back after the fetch.' );
			self::assertCount( 1, $state['transients'] );
		}
	}

	/* ------------------------------------------------------------- helpers */

	/** @return array{rows:array,transients:array,fetches:int} */
	private function readState(): array {
		$state = json_decode( (string) @file_get_contents( $this->state ), true );
		self::assertIsArray( $state, 'The state was written.' );
		return $state + array(
			'rows'       => array(),
			'transients' => array(),
			'fetches'    => 0,
		);
	}

	/**
	 * One request in a process of its own.
	 *
	 * @return array{out:string,err:string,code:int,signaled:bool,termsig:int}
	 */
	private function child( array $faults, string $marker = '' ): array {
		$case  = array(
			'state'   => $this->state,
			'marker'  => $marker,
			'docroot' => $this->dir,
			'path'    => self::PATH,
			'faults'  => $faults,
		);
		$pipes = array();
		$proc  = proc_open(
			array( PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'max_execution_time=0', __DIR__ . '/support/well-known-fault-child.php', (string) json_encode( $case ) ),
			array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes
		);
		self::assertIsResource( $proc );
		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$status = proc_get_status( $proc );
		while ( $status['running'] ) {
			usleep( 10000 );
			$status = proc_get_status( $proc );
		}
		proc_close( $proc );
		return array(
			'out'      => $out,
			'err'      => $err,
			'code'     => (int) $status['exitcode'],
			'signaled' => (bool) $status['signaled'],
			'termsig'  => (int) $status['termsig'],
		);
	}
}
