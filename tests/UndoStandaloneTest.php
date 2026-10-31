<?php
/**
 * The standalone scripts under tests/standalone/ (the undo store, the undo of
 * wp_update_post, the page caches after a restore, the undo of the meta
 * tools, of terms, menus and widgets, of the comment tools, and of the
 * writing shop tools), run by this suite.
 *
 * They come from AlphaBridge MCP Pro, where each is its own PHP script with
 * its own stand-ins for WordPress; those stand-ins cannot share a process with
 * the bootstrap of this suite. So each script runs in a process of its own,
 * with the PHP that runs PHPUnit, under a time limit, and passes only when it
 * exits with 0, its last line says how many checks passed and that none
 * failed, and it made at least as many checks as it is known to make. A
 * script that stops early, or checks nothing at all, is not green.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UndoStandaloneTest extends TestCase {

	/** Seconds one script may run. */
	private const TIMEOUT = 120;

	/**
	 * Checks each script makes at least. Somewhat below what they make, so a
	 * new check needs no change here, while a script that stops halfway
	 * through, or loses a section, does not pass.
	 */
	private const MINIMUM = array(
		'undo-test.php'            => 335,
		'undo-postfields-test.php' => 170,
		'restore-cache-test.php'   => 90,
		// Free since 4.11.0: the undo of a theme switch.
		'undo-theme-test.php'      => 40,
		// Since 4.13.0: the undo of the meta tools and their read-back.
		'undo-meta-test.php'       => 135,
		// Since 4.13.0: the undo of terms, menus, menu items and widgets.
		'undo-terms-widgets-test.php' => 140,
		// Since 4.14.0: the undo of the comment tools and their read-back.
		'undo-comments-test.php'      => 120,
		// Since 4.14.0: the undo of a product and of a new coupon, the order
		// status that says it is not undoable, and their read-back.
		'undo-shop-test.php'          => 140,
	);

	/** For a script added later, until it is listed above. */
	private const MINIMUM_UNLISTED = 10;

	/** @return array<string,array{string}> */
	public static function scripts(): array {
		$out = array();
		foreach ( (array) glob( __DIR__ . '/standalone/*.php' ) as $file ) {
			$out[ basename( $file ) ] = array( $file );
		}
		return $out;
	}

	public function testTheScriptsAreThere(): void {
		self::assertSame( array_keys( self::MINIMUM ), array_values( array_intersect( array_keys( self::MINIMUM ), array_keys( self::scripts() ) ) ) );
	}

	#[DataProvider( 'scripts' )]
	public function testTheScriptPassesInAProcessOfItsOwn( string $script ): void {
		[ $code, $output, $timed_out ] = self::run_script( $script );
		self::assertFalse( $timed_out, basename( $script ) . ' ran longer than ' . self::TIMEOUT . " seconds:\n" . $output );
		$why = self::verdict( $code, $output, self::MINIMUM[ basename( $script ) ] ?? self::MINIMUM_UNLISTED );
		self::assertNull( $why, basename( $script ) . ': ' . (string) $why . "\n" . $output );
	}

	/* ---------------------------------------- the judgment, and its own test */

	/**
	 * Why a run does not pass, or null when it does.
	 *
	 * @param int    $code    Exit code.
	 * @param string $output  What the script printed (stdout and stderr).
	 * @param int    $minimum Checks it must at least have made.
	 * @return string|null
	 */
	public static function verdict( int $code, string $output, int $minimum ): ?string {
		if ( 0 !== $code ) {
			return 'exit code ' . $code;
		}
		$lines = preg_split( '/\R/', rtrim( $output ) );
		$last  = (string) end( $lines );
		if ( 1 !== preg_match( '/: (\d+) passed, (\d+) failed$/', $last, $m ) ) {
			return 'no summary line at the end (a script that stopped early?)';
		}
		if ( '0' !== $m[2] ) {
			return $m[2] . ' checks failed';
		}
		if ( (int) $m[1] < $minimum ) {
			return 'only ' . $m[1] . ' checks, at least ' . $minimum . ' expected';
		}
		if ( 1 === preg_match( '/^\s*FAIL\b|Fatal error|Warning:|Notice:|Deprecated:/m', $output ) ) {
			return 'a failure, an error or a warning in the output';
		}
		return null;
	}

	public function testAScriptThatChecksNothingOrStopsEarlyIsNotGreen(): void {
		self::assertNull( self::verdict( 0, "X: 12 passed, 0 failed\n", 10 ) );
		self::assertNotNull( self::verdict( 0, "X: 0 passed, 0 failed\n", 10 ), 'No check at all.' );
		self::assertNotNull( self::verdict( 0, "X: 9 passed, 0 failed\n", 10 ), 'Fewer than the minimum.' );
		self::assertNotNull( self::verdict( 1, "  FAIL: a\nX: 11 passed, 1 failed\n", 10 ), 'A failure.' );
		self::assertNotNull( self::verdict( 0, "X: 11 passed, 1 failed\n", 10 ), 'A failure, even with exit code 0.' );
		self::assertNotNull( self::verdict( 0, "  FAIL: a\nX: 11 passed, 0 failed\n", 10 ), 'A FAIL line the count missed.' );
		self::assertNotNull( self::verdict( 0, '', 10 ), 'No output.' );
		self::assertNotNull( self::verdict( 0, "X: 12 passed, 0 failed\nPHP Fatal error: x\n", 10 ), 'Output after the summary.' );
		self::assertNotNull( self::verdict( 0, "PHP Warning: x\nX: 12 passed, 0 failed\n", 10 ), 'A warning on the way.' );
		self::assertNotNull( self::verdict( 255, "PHP Fatal error: x\n", 10 ), 'A crash.' );
	}

	public function testTheRunnerKillsAScriptThatHangs(): void {
		$dir  = sys_get_temp_dir() . '/ab-free-undo-bridge-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
		$file = $dir . '/hangs.php';
		mkdir( $dir, 0700 );
		file_put_contents( $file, "<?php\necho \"started\\n\";\nsleep( 30 );\necho \"X: 50 passed, 0 failed\\n\";\n" );
		try {
			$t0                             = microtime( true );
			[ $code, $output, $timed_out ] = self::run_script( $file, 1 );
			self::assertTrue( $timed_out );
			self::assertLessThan( 10, microtime( true ) - $t0, 'Stopped at the limit, not after the script.' );
			self::assertStringNotContainsString( 'passed', $output );
		} finally {
			@unlink( $file );
			@rmdir( $dir );
		}
	}

	/**
	 * Run one script with PHP_BINARY in its own process, stderr into stdout.
	 *
	 * @param string $script  Path.
	 * @param int    $timeout Seconds.
	 * @return array{0:int,1:string,2:bool} Exit code, output, whether it was stopped.
	 */
	private static function run_script( string $script, int $timeout = self::TIMEOUT ): array {
		$pipes = array();
		$proc  = proc_open(
			array( PHP_BINARY, $script ),
			array(
				0 => array( 'file', '/dev/null', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'redirect', 1 ),
			),
			$pipes,
			dirname( $script )
		);
		self::assertIsResource( $proc, 'The script could not be started.' );
		stream_set_blocking( $pipes[1], false );
		$output    = '';
		$code      = -1;
		$timed_out = false;
		$start     = microtime( true );
		while ( true ) {
			$read  = array( $pipes[1] );
			$write = null;
			$other = null;
			if ( false !== @stream_select( $read, $write, $other, 0, 200000 ) ) {
				$output .= (string) stream_get_contents( $pipes[1] );
			}
			$status = proc_get_status( $proc );
			if ( ! $status['running'] ) {
				$output .= (string) stream_get_contents( $pipes[1] );
				$code    = (int) $status['exitcode'];
				break;
			}
			if ( microtime( true ) - $start > $timeout ) {
				// Only the process this test started, by its own handle.
				proc_terminate( $proc, 9 );
				$timed_out = true;
				break;
			}
		}
		fclose( $pipes[1] );
		proc_close( $proc );
		return array( $code, $output, $timed_out );
	}
}
