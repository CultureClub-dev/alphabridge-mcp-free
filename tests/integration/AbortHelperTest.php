<?php
/**
 * The hard abort on a site (Z8b branch 1, point 4): a plan of
 * AB_MCP_IT_Child in a process of its own with the site's WordPress
 * (bin/site-call.php), aborted at a hook or at a call of a seam, in each
 * mode. The lock of code changes (AB_MCP_Change_Lock, freed by a shutdown
 * function of the plugin) shows what each mode leaves: with shutdown and by
 * the time limit the rescue frees it, without shutdown it stays. Every other
 * end of the process (wp_die(), an exit) is no pass and no abort. The
 * mechanics in plain PHP are TestAbortTest (unit suite).
 *
 * A later branch names its points the same way: a fault of kind abort on a
 * call of its seam (the swap: between the two renames), or a hook.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests\Integration;

use AB_MCP_Change_Lock;
use AB_MCP_IT_Process;
use AB_MCP_IT_Sites;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AbortHelperTest extends TestCase {

	protected function setUp(): void {
		AB_MCP_IT_Sites::reset( 'a' );
	}

	protected function tearDown(): void {
		AB_MCP_IT_Sites::reset( 'a' );
	}

	/**
	 * The lock taken, then the point: a hook the plan fires.
	 *
	 * @param string $mode Mode.
	 * @return array
	 */
	private static function locked_then( string $mode ): array {
		return array(
			'require' => array( AB_MCP_DIR . 'includes/lifecycle/class-change-lock.php' ),
			'hooks'   => array(
				array(
					'hook'  => 'ab_it_punkt',
					'point' => 'nach-der-sperre',
					'mode'  => $mode,
				),
			),
			'calls'   => array(
				array( 'call' => 'AB_MCP_Change_Lock::take', 'as' => 'take', 'expect' => 'result' ),
				array( 'call' => 'do_action', 'args' => array( 'ab_it_punkt' ) ),
			),
		);
	}

	private static function lock(): ?array {
		return AB_MCP_IT_Sites::option_row( 'a', AB_MCP_Change_Lock::OPTION );
	}

	public function testWithoutAbortTheRunCountsAndTheShutdownFreesTheLock(): void {
		$done = AB_MCP_IT_Process::calls(
			'a',
			array(
				'require' => array( AB_MCP_DIR . 'includes/lifecycle/class-change-lock.php' ),
				'calls'   => array(
					array( 'call' => 'AB_MCP_Change_Lock::take', 'as' => 'take', 'expect' => 'result' ),
					array( 'call' => 'get_option', 'args' => array( 'siteurl' ), 'as' => 'url' ),
				),
			)
		);
		self::assertTrue( $done['results']['take'] );
		self::assertSame( AB_MCP_IT_Sites::url( 'a' ), $done['results']['url'] );
		self::assertNull( self::lock(), 'Freed at the end of the request.' );
	}

	public function testAbortWithShutdownAtAHook(): void {
		$abort = AB_MCP_IT_Process::abort( 'a', self::locked_then( 'shutdown' ) );
		self::assertSame( 'nach-der-sperre', $abort['marker']['point'] );
		self::assertTrue( $abort['marker']['shutdown_ran'] );
		self::assertNull( self::lock(), 'The rescue of the plugin freed the lock.' );
	}

	public function testAbortWithoutShutdownAtAHookLeavesTheLock(): void {
		$abort = AB_MCP_IT_Process::abort( 'a', self::locked_then( 'no_shutdown' ) );
		self::assertSame( 9, $abort['run']['signal'] );
		self::assertNotNull( self::lock(), 'No shutdown function ran: the lock stays as a hard abort leaves it.' );
	}

	public function testAbortByTheTimeLimitAtAHookRunsTheRescue(): void {
		$abort = AB_MCP_IT_Process::abort( 'a', self::locked_then( 'timeout' ) );
		self::assertStringContainsString( 'Maximum execution time', $abort['marker']['error']['message'] );
		self::assertNull( self::lock() );
	}

	public function testWpDieOnTheSiteIsNoPassAndNoAbort(): void {
		$plan                 = self::locked_then( 'shutdown' );
		$plan['calls'][1]     = array( 'call' => 'wp_die', 'args' => array( 'Stop.' ) );
		try {
			AB_MCP_IT_Process::calls( 'a', array( 'calls' => array( array( 'call' => 'wp_die', 'args' => array( 'Stop.' ) ) ) ) );
			self::fail( 'wp_die() counted as a pass.' );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( 'did not run its plan to the end', $e->getMessage() );
		}
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'without the marker' );
		AB_MCP_IT_Process::abort( 'a', $plan );
	}

	public function testAbortAtACallOfASeamOnTheSite(): void {
		$dir = (string) realpath( sys_get_temp_dir() ) . '/ab-it-seam-' . bin2hex( random_bytes( 6 ) );
		mkdir( $dir . '/target', 0700, true );
		mkdir( $dir . '/staged', 0700 );
		file_put_contents( $dir . '/target/plugin.php', 'alt' );
		file_put_contents( $dir . '/staged/plugin.php', 'neu' );
		try {
			$abort = AB_MCP_IT_Process::abort(
				'a',
				array(
					'require' => array( dirname( __DIR__ ) . '/fixtures/seams/class-test-seam-example.php' ),
					'seams'   => array(
						array(
							'owner'    => 'AB_MCP_Test_Example_Swap',
							'property' => 'calls',
							'real'     => 'AB_MCP_Test_Example_Calls',
							'faults'   => array(
								array(
									'method' => 'rename',
									'nth'    => 1,
									'when'   => 'after',
									'kind'   => 'abort',
									'point'  => 'zwischen-den-umbenennungen',
									'mode'   => 'no_shutdown',
								),
							),
						),
					),
					'calls'   => array( array( 'call' => 'AB_MCP_Test_Example_Swap::swap', 'args' => array( $dir . '/target', $dir . '/staged', $dir . '/backup', $dir . '/journal' ) ) ),
				)
			);
			self::assertSame( 'zwischen-den-umbenennungen', $abort['marker']['point'] );
			self::assertDirectoryDoesNotExist( $dir . '/target' );
			self::assertSame( 'alt', file_get_contents( $dir . '/backup/plugin.php' ) );
		} finally {
			foreach ( array( 'backup/plugin.php', 'staged/plugin.php', 'target/plugin.php', 'journal' ) as $file ) {
				if ( is_file( $dir . '/' . $file ) ) {
					unlink( $dir . '/' . $file );
				}
			}
			foreach ( array( 'backup', 'staged', 'target', '' ) as $sub ) {
				if ( is_dir( $dir . '/' . $sub ) ) {
					rmdir( $dir . '/' . $sub );
				}
			}
		}
	}
}
