<?php
/**
 * The standalone scripts under tests/standalone-lifecycle/: installing,
 * updating, deleting and activating plugins and themes, with the update
 * guard, the activation guard, the test requests and the update log.
 *
 * They come from AlphaBridge MCP Pro, where these tools were before 4.11.0,
 * each its own PHP script with its own stand-ins for WordPress (the update
 * harness in tests/standalone-lib/support/). So each
 * runs in a process of its own, with the PHP that runs PHPUnit, under a time
 * limit, once per scenario, and passes only when it exits with 0, prints no
 * FAIL line, error or warning, ends with its line of success and made at
 * least as many checks as it is known to make.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LifecycleStandaloneTest extends TestCase {

	/** Seconds one run may take. */
	private const TIMEOUT = 180;

	/**
	 * Script => [ scenario => checks it makes at least ]. Somewhat below what
	 * each makes, so a new check needs no change here, while a run that stops
	 * halfway does not pass. 0: the script prints no count (it ends with «all
	 * passed»).
	 */
	private const RUNS = array(
		'activation-guard-test.php'  => array( '' => 190 ),
		'update-flow-test.php'       => array(
			''          => 240,
			'filemods'  => 3,
			'oldcore'   => 5,
			'multisite' => 38,
		),
		'php-probe-test.php'         => array( '' => 70 ),
		'update-log-test.php'        => array( '' => 60 ),
		'update-verdict-test.php'    => array( '' => 180 ),
		'lifecycle-delete-test.php'  => array( '' => 0 ),
		'lifecycle-errors-test.php'  => array( '' => 12 ),
		'probe-key-test.php'         => array( '' => 12 ),
	);

	/** @return array<string,array{string,string,int}> */
	public static function runs(): array {
		$out = array();
		foreach ( self::RUNS as $script => $scenarios ) {
			foreach ( $scenarios as $scenario => $minimum ) {
				$out[ $script . ( '' !== $scenario ? ' ' . $scenario : '' ) ] = array( $script, $scenario, $minimum );
			}
		}
		return $out;
	}

	public function testEveryScriptIsRun(): void {
		$files = array_map( 'basename', (array) glob( __DIR__ . '/standalone-lifecycle/*.php' ) );
		sort( $files );
		$listed = array_keys( self::RUNS );
		sort( $listed );
		self::assertSame( $listed, $files, 'A script in tests/standalone-lifecycle/ that is not listed here would never run.' );
	}

	#[DataProvider( 'runs' )]
	public function testTheScriptPasses( string $script, string $scenario, int $minimum ): void {
		[ $code, $output, $timed_out ] = self::run_script( __DIR__ . '/standalone-lifecycle/' . $script, $scenario );
		self::assertFalse( $timed_out, $script . ' ran longer than ' . self::TIMEOUT . " seconds:\n" . $output );
		$why = self::verdict( $code, $output, $minimum );
		self::assertNull( $why, $script . ' ' . $scenario . ': ' . (string) $why . "\n" . $output );
	}

	/**
	 * Why a run does not pass, or null when it does.
	 *
	 * @param int    $code    Exit code.
	 * @param string $output  What the script printed (stdout and stderr).
	 * @param int    $minimum Checks it must at least have made (0: no count printed).
	 * @return string|null
	 */
	public static function verdict( int $code, string $output, int $minimum ): ?string {
		if ( 0 !== $code ) {
			return 'exit code ' . $code;
		}
		if ( 1 === preg_match( '/^\s*FAIL\b|Fatal error|Warning:|Notice:|Deprecated:/m', $output ) ) {
			return 'a failure, an error or a warning in the output';
		}
		$lines = preg_split( '/\R/', rtrim( $output ) );
		$last  = (string) end( $lines );
		if ( 1 === preg_match( '/^ALLE (\d+) .*BESTANDEN \(0 Fehler\)$/', $last, $m ) ) {
			return (int) $m[1] < $minimum ? 'only ' . $m[1] . ' checks, at least ' . $minimum . ' expected' : null;
		}
		if ( 0 === $minimum && 1 === preg_match( '/: all passed$/', $last ) ) {
			return null;
		}
		return 'no line of success at the end (a script that stopped early?)';
	}

	public function testAScriptThatStopsEarlyOrFailsIsNotGreen(): void {
		self::assertNull( self::verdict( 0, "ALLE 12 X-CHECKS BESTANDEN (0 Fehler)\n", 10 ) );
		self::assertNotNull( self::verdict( 0, "ALLE 9 X-CHECKS BESTANDEN (0 Fehler)\n", 10 ), 'Fewer than the minimum.' );
		self::assertNotNull( self::verdict( 1, "  FAIL: a\nX: FAILED (1 von 12)\n", 10 ), 'A failure.' );
		self::assertNotNull( self::verdict( 0, "  FAIL: a\nALLE 12 X-CHECKS BESTANDEN (0 Fehler)\n", 10 ), 'A FAIL line the count missed.' );
		self::assertNotNull( self::verdict( 0, '', 10 ), 'No output.' );
		self::assertNull( self::verdict( 0, "x: all passed\n", 0 ) );
		self::assertNotNull( self::verdict( 0, "x: all passed\n", 10 ), 'No count where one is expected.' );
		self::assertNotNull( self::verdict( 0, "PHP Warning: x\nx: all passed\n", 0 ), 'A warning on the way.' );
	}

	/**
	 * Run one script with PHP_BINARY in its own process, stderr into stdout.
	 *
	 * @param string $script   Path.
	 * @param string $scenario Argument, '' for none.
	 * @return array{0:int,1:string,2:bool} Exit code, output, whether it was stopped.
	 */
	private static function run_script( string $script, string $scenario ): array {
		$cmd   = '' !== $scenario ? array( PHP_BINARY, $script, $scenario ) : array( PHP_BINARY, $script );
		$pipes = array();
		$proc  = proc_open(
			$cmd,
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
			if ( microtime( true ) - $start > self::TIMEOUT ) {
				$timed_out = true;
				proc_terminate( $proc, 9 );
				break;
			}
		}
		fclose( $pipes[1] );
		proc_close( $proc );
		return array( $code, $output, $timed_out );
	}
}
