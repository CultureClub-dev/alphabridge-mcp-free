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

use AB_MCP_IT_Child;
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
		// 30 s: the abort takes about one second of CPU; a time limit that
		// is not set leaves the loop running until the helper ends it.
		$abort = AB_MCP_IT_Process::abort( null, $this->plan( self::between( 'timeout' ) ), 30 );
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

	public function testAResultThatIsNoUtf8EndsTheRunAsAFailureNotAsNull(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'did not run its plan to the end' );
		AB_MCP_IT_Process::calls( null, $this->plan( array(), array( array( 'call' => 'hex2bin', 'args' => array( 'ff' ) ) ) ) );
	}

	public function testAResultLineFollowedByAnotherStatusIsNoPass(): void {
		try {
			AB_MCP_IT_Process::calls( null, $this->plan( array(), array( array( 'call' => 'ab_it_probe_exit_in_destructor', 'args' => array( 3 ) ) ) ) );
			self::fail( 'A run with status 3 counted as a pass.' );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( 'exit 3', $e->getMessage() );
			self::assertStringContainsString( AB_MCP_IT_Child::RESULT_LINE, $e->getMessage(), 'The result line was there: only the status makes it red.' );
		}
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'exit 3' );
		AB_MCP_IT_Process::calls( null, $this->plan( array(), array( array( 'call' => 'ab_it_probe_exit_code_in_shutdown', 'args' => array( 3 ) ) ) ) );
	}

	public function testAMarkerOfAnotherPointIsAnError(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( "The marker names 'anderer-punkt' (shutdown), not 'zwischen-den-umbenennungen' (shutdown)" );
		AB_MCP_IT_Process::abort( null, $this->plan( self::between( 'shutdown' ), array( array( 'call' => 'AB_MCP_Test_Abort::fire', 'args' => array( 'anderer-punkt', 'shutdown' ) ) ) ) );
	}

	/**
	 * A marker alone, written without the abort, for each mode.
	 *
	 * @return array<string,array>
	 */
	public static function markers_without_proof(): array {
		$base = array( 'fired' => true, 'point' => 'zwischen-den-umbenennungen', 'phase' => 'request' );
		return array(
			'no_shutdown without the signal' => array( 'no_shutdown', $base + array( 'mode' => 'no_shutdown' ), 'did not end by the signal 9' ),
			'no_shutdown with shutdown'      => array( 'no_shutdown', $base + array( 'mode' => 'no_shutdown', 'shutdown_ran' => true ), 'did not end by the signal 9 before any shutdown function', 'ab_it_probe_marker_then_kill' ),
			'shutdown without its run'       => array( 'shutdown', $base + array( 'mode' => 'shutdown' ), 'did not run the shutdown functions' ),
			'timeout without the fatal'      => array( 'timeout', $base + array( 'mode' => 'timeout', 'shutdown_ran' => true, 'error' => null ), 'fatal error of the time limit' ),
			'timeout with another error'     => array( 'timeout', $base + array( 'mode' => 'timeout', 'shutdown_ran' => true, 'error' => array( 'type' => E_WARNING, 'message' => 'Maximum execution time of 1 second exceeded' ) ), 'fatal error of the time limit' ),
			'timeout with another fatal'     => array( 'timeout', $base + array( 'mode' => 'timeout', 'shutdown_ran' => true, 'error' => array( 'type' => E_ERROR, 'message' => 'Allowed memory size exhausted' ) ), 'fatal error of the time limit' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'markers_without_proof' )]
	public function testAMarkerWithoutTheProofOfItsModeIsAnError( string $mode, array $record, string $says, string $probe = 'ab_it_probe_marker' ): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( $says );
		AB_MCP_IT_Process::abort( null, $this->plan( self::between( $mode ), array( array( 'call' => $probe, 'args' => array( $record ) ) ) ) );
	}

	public function testAnExitInAShutdownFunctionIsNoPass(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'did not run its plan to the end of its shutdown' );
		AB_MCP_IT_Process::calls( null, $this->plan( array(), array( array( 'call' => 'ab_it_probe_exit_in_shutdown' ) ) ) );
	}

	private function swap_in_shutdown(): array {
		return array( 'call' => 'ab_it_probe_swap_in_shutdown', 'args' => array( $this->dir . '/target', $this->dir . '/staged', $this->dir . '/backup', $this->dir . '/journal' ) );
	}

	/**
	 * A plan whose only call registers the swap as a shutdown function.
	 *
	 * @param array $faults Faults.
	 * @return array
	 */
	private function plan_in_shutdown( array $faults ): array {
		$plan          = $this->plan( $faults );
		$plan['calls'] = array( $this->swap_in_shutdown() );
		return $plan;
	}

	public function testAPointReachedInAShutdownRescueIsNotTheAbortOfTheRequest(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( "came in the phase 'shutdown', not in the phase request" );
		AB_MCP_IT_Process::abort( null, $this->plan_in_shutdown( self::between( 'no_shutdown' ) ) );
	}

	public function testAPointDeclaredInTheShutdownPhaseIsAbortedThere(): void {
		$faults               = self::between( 'no_shutdown' );
		$faults[0]['phase']   = 'shutdown';
		$abort                = AB_MCP_IT_Process::abort( null, $this->plan_in_shutdown( $faults ) );
		self::assertSame( 'shutdown', $abort['marker']['phase'] );
		self::assertSame( 9, $abort['run']['signal'] );
		$this->assertBetweenTheRenames();
	}

	public function testAPointDeclaredInTheShutdownPhaseButReachedInTheRequestIsAnError(): void {
		$faults             = self::between( 'no_shutdown' );
		$faults[0]['phase'] = 'shutdown';
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( "came in the phase 'request', not in the phase shutdown" );
		AB_MCP_IT_Process::abort( null, $this->plan( $faults ) );
	}

	public function testOnlyTheAbortWithoutShutdownTakesTheShutdownPhase(): void {
		foreach ( array( 'shutdown', 'timeout' ) as $mode ) {
			$faults             = self::between( $mode );
			$faults[0]['phase'] = 'shutdown';
			try {
				AB_MCP_IT_Process::abort( null, $this->plan( $faults ) );
				self::fail( "The phase shutdown took the mode $mode." );
			} catch ( InvalidArgumentException $e ) {
				self::assertStringContainsString( 'takes the mode no_shutdown only', $e->getMessage() );
			}
		}
		$faults             = self::between( 'no_shutdown' );
		$faults[0]['phase'] = 'nachher';
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( "No phase 'nachher'" );
		AB_MCP_IT_Process::abort( null, $this->plan( $faults ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function ends_of_a_child(): array {
		return array(
			'its own end'              => array( 'end' ),
			'its own SIGKILL'          => array( 'no_shutdown' ),
			'the end of its shutdown'  => array( 'shutdown' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'ends_of_a_child' )]
	public function testWhatTheChildStartedEndsWithIt( string $end ): void {
		$pid_file = $this->dir . '/sleep.pid';
		$before   = array( array( 'call' => 'ab_it_probe_background_sleep', 'args' => array( $pid_file, 20 ) ) );
		if ( 'end' === $end ) {
			AB_MCP_IT_Process::calls( null, $this->plan( array(), $before ) );
		} else {
			AB_MCP_IT_Process::abort( null, $this->plan( self::between( $end ), $before ) );
		}
		$sleep = (int) trim( (string) file_get_contents( $pid_file ) );
		self::assertGreaterThan( 1, $sleep );
		usleep( 100000 );
		self::assertFalse( posix_kill( $sleep, 0 ), "The sleep the child started outlived it ($end)." );
	}

	/**
	 * @return array<string,array{0:string|null}>
	 */
	public static function runs_with_a_package(): array {
		return array(
			'to its end'         => array( null ),
			'without shutdown'   => array( 'no_shutdown' ),
			'by the time limit'  => array( 'timeout' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'runs_with_a_package' )]
	public function testThePackagesOfAChildGoWithTheFolderOfItsRun( ?string $mode ): void {
		$before = (array) glob( sys_get_temp_dir() . '/ab-it-*' );
		$build  = array(
			array( 'call' => 'AB_MCP_IT_Code::zip', 'args' => array( 'plugin', 'ab-it-aktiv', '1.0.0' ), 'as' => 'zip' ),
			array( 'call' => 'file_put_contents', 'args' => array( $this->dir . '/zip-path', '$zip' ) ),
		);
		$plan              = $this->plan( null === $mode ? array() : self::between( $mode ), $build );
		$plan['require'][] = __DIR__ . '/integration/support/class-it-code.php';
		if ( null === $mode ) {
			$run = AB_MCP_IT_Process::calls( null, $plan )['run'];
		} else {
			$run = AB_MCP_IT_Process::abort( null, $plan, 30 )['run'];
		}
		$zip = (string) file_get_contents( $this->dir . '/zip-path' );
		self::assertStringStartsWith( $run['tmp'] . '/', $zip, 'The child built its package in the folder of its run.' );
		self::assertFileDoesNotExist( $zip );
		self::assertDirectoryDoesNotExist( $run['tmp'] );
		self::assertSame( $before, (array) glob( sys_get_temp_dir() . '/ab-it-*' ), 'Nothing of the run is left in the temporary directory.' );
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
		$before = (array) glob( sys_get_temp_dir() . '/ab-it-*' );
		$abort  = AB_MCP_IT_Process::abort( null, $this->plan( self::between( 'no_shutdown' ) ) );
		self::assertDirectoryDoesNotExist( $abort['run']['tmp'], 'The folder of the run with the marker in it.' );
		self::assertSame( $before, (array) glob( sys_get_temp_dir() . '/ab-it-*' ) );
	}

	/* ------------------------------------------------ the process itself */

	public function testAChildPastItsTimeLimitIsEndedByItsOwnPid(): void {
		$pid_file = $this->dir . '/pid';
		$started  = microtime( true );
		try {
			AB_MCP_IT_Process::calls( null, $this->plan( array(), array( array( 'call' => 'ab_it_probe_pid_then_sleep', 'args' => array( $pid_file, 10 ) ) ) ), 2 );
			self::fail( 'A child that sleeps 10 s finished within 2 s.' );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( 'longer than 2 s and was ended', $e->getMessage() );
			self::assertStringContainsString( 'signal 9', $e->getMessage(), 'Ended by SIGKILL, not by waiting for it.' );
		}
		self::assertLessThan( 8, microtime( true ) - $started, 'The helper did not wait for the child to finish its sleep.' );
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
