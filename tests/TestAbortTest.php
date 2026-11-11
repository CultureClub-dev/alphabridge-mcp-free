<?php
/**
 * The hard abort in a process of its own and the verdict on a child (Z8b
 * branch 1, point 4; V2 and V3 of the pre-check): each mode at a named point
 * of the example seam (between the two renames of a swap) with its own
 * proof, and every other end of a child (exit, wp_die() as the CLI shows it,
 * fatal error, a shutdown that ends early, the point never reached, the time
 * limit) as an error, never as a pass. In plain PHP (bin/plain-call.php);
 * AbortHelperTest does the same on a site.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_IT_Process;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/integration/support/class-it-process.php';

final class TestAbortTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = (string) realpath( sys_get_temp_dir() ) . '/ab-abort-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir . '/target', 0700, true );
		mkdir( $this->dir . '/staged', 0700 );
		file_put_contents( $this->dir . '/target/plugin.php', 'alt' );
		file_put_contents( $this->dir . '/staged/plugin.php', 'neu' );
	}

	protected function tearDown(): void {
		self::remove( $this->dir );
	}

	private static function remove( string $path ): void {
		if ( is_file( $path ) || is_link( $path ) ) {
			unlink( $path );
			return;
		}
		if ( is_dir( $path ) ) {
			foreach ( (array) scandir( $path ) as $name ) {
				if ( '.' !== $name && '..' !== $name ) {
					self::remove( $path . '/' . $name );
				}
			}
			rmdir( $path );
		}
	}

	/**
	 * A plan: a rescue registered first (as the plugin would), the seam of
	 * the example with its faults, then the swap and more calls.
	 *
	 * @param array[] $faults Faults of the seam.
	 * @param array[] $before Calls before the swap.
	 * @return array
	 */
	private function plan( array $faults, array $before = array() ): array {
		return array(
			'require' => array( __DIR__ . '/fixtures/seams/class-test-seam-example.php', __DIR__ . '/fixtures/seams/probe-functions.php' ),
			'seams'   => array(
				array(
					'owner'    => 'AB_MCP_Test_Example_Swap',
					'property' => 'calls',
					'real'     => 'AB_MCP_Test_Example_Calls',
					'faults'   => $faults,
				),
			),
			'calls'   => array_merge(
				array( array( 'call' => 'ab_it_probe_on_shutdown', 'args' => array( $this->dir . '/rescue' ) ) ),
				$before,
				array( array( 'call' => 'AB_MCP_Test_Example_Swap::swap', 'args' => array( $this->dir . '/target', $this->dir . '/staged', $this->dir . '/backup', $this->dir . '/journal' ), 'as' => 'swap' ) )
			),
		);
	}

	private static function between( string $mode ): array {
		return array(
			array(
				'method' => 'rename',
				'nth'    => 1,
				'when'   => 'after',
				'kind'   => 'abort',
				'point'  => 'zwischen-den-umbenennungen',
				'mode'   => $mode,
			),
		);
	}

	/** The state a swap leaves between its two renames. */
	private function assertBetweenTheRenames(): void {
		self::assertDirectoryDoesNotExist( $this->dir . '/target', 'The target is gone…' );
		self::assertSame( 'alt', file_get_contents( $this->dir . '/backup/plugin.php' ), '…the backup holds the old version…' );
		self::assertSame( 'neu', file_get_contents( $this->dir . '/staged/plugin.php' ), '…the new one is still staged.' );
		self::assertSame( 'begun', file_get_contents( $this->dir . '/journal' ) );
	}

	/* ------------------------------------------------- the three modes */

	public function testAbortWithShutdownRunsTheShutdownFunctions(): void {
		$abort = AB_MCP_IT_Process::abort( null, $this->plan( self::between( 'shutdown' ) ) );
		self::assertSame( 'zwischen-den-umbenennungen', $abort['marker']['point'] );
		self::assertSame( 'shutdown', $abort['marker']['mode'] );
		self::assertTrue( $abort['marker']['shutdown_ran'] );
		self::assertSame( 0, $abort['run']['exit'], 'A real exit: status 0, and still no pass.' );
		self::assertNull( $abort['run']['signal'] );
		self::assertSame( 'rescue ran', file_get_contents( $this->dir . '/rescue' ), 'The rescue took hold.' );
		$this->assertBetweenTheRenames();
	}

	public function testAbortWithoutShutdownLeavesEverythingAsItIs(): void {
		$abort = AB_MCP_IT_Process::abort( null, $this->plan( self::between( 'no_shutdown' ) ) );
		self::assertSame( 'no_shutdown', $abort['marker']['mode'] );
		self::assertArrayNotHasKey( 'shutdown_ran', $abort['marker'] );
		self::assertSame( 9, $abort['run']['signal'] );
		self::assertNull( $abort['run']['exit'] );
		self::assertFileDoesNotExist( $this->dir . '/rescue', 'No shutdown function ran.' );
		$this->assertBetweenTheRenames();
	}

	public function testAbortByTheTimeLimitIsPhpsFatalErrorWithShutdown(): void {
		$abort = AB_MCP_IT_Process::abort( null, $this->plan( self::between( 'timeout' ) ) );
		self::assertSame( 'timeout', $abort['marker']['mode'] );
		self::assertTrue( $abort['marker']['shutdown_ran'] );
		self::assertSame( E_ERROR, $abort['marker']['error']['type'] );
		self::assertStringContainsString( 'Maximum execution time', $abort['marker']['error']['message'] );
		self::assertSame( 255, $abort['run']['exit'] );
		self::assertSame( 'rescue ran', file_get_contents( $this->dir . '/rescue' ) );
		$this->assertBetweenTheRenames();
	}

	public function testAnAbortBeforeTheCallLeavesTheCallUndone(): void {
		$faults            = self::between( 'no_shutdown' );
		$faults[0]['when'] = 'before';
		AB_MCP_IT_Process::abort( null, $this->plan( $faults ) );
		self::assertSame( 'alt', file_get_contents( $this->dir . '/target/plugin.php' ), 'The first rename never ran.' );
		self::assertDirectoryDoesNotExist( $this->dir . '/backup' );
	}

	/* --------------------------- no other end of a child counts as a pass */

	public function testARunWithoutAbortCountsWhenItRanToItsEnd(): void {
		$done = AB_MCP_IT_Process::calls( null, $this->plan( array() ) );
		self::assertSame( 'swapped', $done['results']['swap'] );
		self::assertSame( 0, $done['run']['exit'] );
		self::assertCount( 4, $done['seams']['AB_MCP_Test_Example_Swap::$calls'] );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function ends(): array {
		return array(
			'exit'                       => array( 'ab_it_probe_exit' ),
			'wp_die() as in the CLI'     => array( 'ab_it_probe_die_html' ),
			'fatal error'                => array( 'ab_it_probe_fatal' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'ends' )]
	public function testAnotherEndIsNeverAPass( string $end ): void {
		try {
			AB_MCP_IT_Process::calls( null, $this->plan( array(), array( array( 'call' => $end ) ) ) );
			self::fail( "calls() took $end for a pass." );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( 'did not run its plan to the end', $e->getMessage() );
		}
		try {
			AB_MCP_IT_Process::abort( null, $this->plan( self::between( 'shutdown' ), array( array( 'call' => $end ) ) ) );
			self::fail( "abort() took $end for the abort at the point." );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( 'without the marker', $e->getMessage() );
		}
		self::assertSame( 'alt', file_get_contents( $this->dir . '/target/plugin.php' ), 'The swap never began.' );
	}

	public function testAPointThatNeverComesIsAnError(): void {
		$faults           = self::between( 'shutdown' );
		$faults[0]['nth'] = 5;
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'never came' );
		AB_MCP_IT_Process::abort( null, $this->plan( $faults ) );
	}

	public function testAShutdownThatEndsBeforeOursIsNoAbortWithShutdown(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'did not run the shutdown functions to their end' );
		AB_MCP_IT_Process::abort( null, $this->plan( self::between( 'shutdown' ), array( array( 'call' => 'ab_it_probe_exit_in_shutdown' ) ) ) );
	}

	public function testATimeLimitWhoseShutdownEndsEarlyIsNoAbortByTimeLimit(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( "fatal error of the time limit and its shutdown" );
		AB_MCP_IT_Process::abort( null, $this->plan( self::between( 'timeout' ), array( array( 'call' => 'ab_it_probe_exit_in_shutdown' ) ) ) );
	}

	public function testAPlanNeedsExactlyOneAbort(): void {
		foreach ( array( array(), array_merge( self::between( 'shutdown' ), self::between( 'timeout' ) ) ) as $faults ) {
			try {
				AB_MCP_IT_Process::abort( null, $this->plan( $faults ) );
				self::fail( 'abort() ran a plan with ' . count( $faults ) . ' aborts.' );
			} catch ( InvalidArgumentException $e ) {
				self::assertStringContainsString( 'exactly one abort', $e->getMessage() );
			}
		}
		$this->expectException( InvalidArgumentException::class );
		AB_MCP_IT_Process::calls( null, array( 'marker' => '/x' ) );
	}

	public function testTheMarkerIsRemovedAfterwards(): void {
		$before = (array) glob( sys_get_temp_dir() . '/ab-it-abort-' . getmypid() . '-*' );
		AB_MCP_IT_Process::abort( null, $this->plan( self::between( 'no_shutdown' ) ) );
		self::assertSame( $before, (array) glob( sys_get_temp_dir() . '/ab-it-abort-' . getmypid() . '-*' ) );
	}

	/* ------------------------------------------------ the process itself */

	public function testAChildPastItsTimeLimitIsEndedByItsOwnPid(): void {
		$pid_file = $this->dir . '/pid';
		try {
			AB_MCP_IT_Process::calls( null, $this->plan( array(), array( array( 'call' => 'ab_it_probe_pid_then_sleep', 'args' => array( $pid_file, 30 ) ) ) ), 2 );
			self::fail( 'A child that sleeps 30 s finished within 2 s.' );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( 'longer than 2 s and was ended', $e->getMessage() );
		}
		$pid = (int) file_get_contents( $pid_file );
		self::assertGreaterThan( 0, $pid );
		self::assertFalse( posix_kill( $pid, 0 ), 'The child is gone.' );
	}

	public function testMuchOutputOnBothPipesDoesNotStallTheChild(): void {
		$done = AB_MCP_IT_Process::calls( null, $this->plan( array(), array( array( 'call' => 'ab_it_probe_noise', 'args' => array( 300000 ), 'as' => 'noise' ) ) ), 30 );
		self::assertSame( 300000, $done['results']['noise'] );
		self::assertSame( 300000, strlen( $done['run']['stderr'] ) );
		self::assertSame( 'swapped', $done['results']['swap'] );
	}

	public function testRunGivesStatusSignalAndBothOutputs(): void {
		$run = AB_MCP_IT_Process::run( array( PHP_BINARY, '-r', 'fwrite( STDOUT, "aus" ); fwrite( STDERR, "fehler" ); exit( 3 );' ) );
		self::assertSame( 3, $run['exit'] );
		self::assertNull( $run['signal'] );
		self::assertFalse( $run['timed_out'] );
		self::assertSame( 'aus', $run['stdout'] );
		self::assertSame( 'fehler', $run['stderr'] );
		$fed = AB_MCP_IT_Process::run( array( PHP_BINARY, '-r', 'echo strlen( stream_get_contents( STDIN ) );' ), str_repeat( 'x', 500000 ) );
		self::assertSame( '500000', $fed['stdout'], 'The whole stdin reached the child.' );
	}
}
