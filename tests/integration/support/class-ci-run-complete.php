<?php
/**
 * Did a PHPUnit run reach its end? (Z8b branch 1, V1 of the pre-check.)
 *
 * A real exit or die in the test process ends PHPUnit 11 at once, with status
 * 0 and no summary: the tests after it never run, and a step that reads only
 * the status counts that as a pass (measured 11.11.2026 with PHPUnit 11.5.56:
 * test A green, test B exit, test C red gives status 0). PHPUnit writes its
 * JUnit log only when the run ends, so a run cut short leaves it empty.
 *
 * A run counts as complete when its JUnit log
 * - exists and is not empty,
 * - has the root element testsuites, and
 * - holds at least as many testcase elements as PHPUnit lists for the same
 *   command (--list-tests-xml: collecting without running, data sets of a
 *   data provider one each, as in the log; skipped and incomplete tests are
 *   in the log as testcase as well).
 *
 * Used by tests/integration/bin/run-complete.php in the jobs unit and integration of
 * .github/workflows/ci.yml; CiRunCompleteTest holds each clause.
 *
 * @package AlphaBridge_MCP
 */

/**
 * Class AB_CI_Run_Complete
 */
final class AB_CI_Run_Complete {

	/**
	 * The verdict on a JUnit log against the number of tests expected.
	 *
	 * @param string $junit    Path of the JUnit log.
	 * @param int    $expected Tests PHPUnit lists for the same command.
	 * @return array{ok:bool,ran:int,message:string}
	 */
	public static function verdict( $junit, $expected ) {
		clearstatcache( true, $junit );
		if ( ! is_file( $junit ) ) {
			return self::no( 0, "The JUnit log $junit is missing: PHPUnit did not reach the end of its run." );
		}
		$xml = (string) file_get_contents( $junit );
		if ( '' === trim( $xml ) ) {
			return self::no( 0, "The JUnit log $junit is empty: PHPUnit ended before the end of its run (a real exit or die in a test?)." );
		}
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $doc->loadXML( $xml );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded || null === $doc->documentElement ) {
			return self::no( 0, "The JUnit log $junit is no XML: the run was cut off while it was written." );
		}
		if ( 'testsuites' !== $doc->documentElement->nodeName ) {
			return self::no( 0, "The JUnit log $junit has the root " . $doc->documentElement->nodeName . ', not testsuites.' );
		}
		$ran = $doc->getElementsByTagName( 'testcase' )->length;
		if ( (int) $expected < 1 ) {
			return self::no( $ran, 'PHPUnit lists no test for this command; a run without tests proves nothing.' );
		}
		if ( $ran < (int) $expected ) {
			return self::no( $ran, "Only $ran of $expected tests ran: PHPUnit ended before the end of its run (a real exit or die in a test?)." );
		}
		return array(
			'ok'      => true,
			'ran'     => $ran,
			'message' => "$ran of $expected tests ran; the JUnit log is complete.",
		);
	}

	/**
	 * How many tests PHPUnit lists for a command (its --list-tests-xml).
	 *
	 * @param string[] $command The PHPUnit command of the run, without
	 *                          --log-junit or other output options.
	 * @param string   $cwd     Directory to run it in.
	 * @return int|string The number, or why it could not be had.
	 */
	public static function expected( array $command, $cwd ) {
		$list = tempnam( sys_get_temp_dir(), 'ab-ci-list-' );
		$out  = tempnam( sys_get_temp_dir(), 'ab-ci-out-' );
		if ( false === $list || false === $out ) {
			return 'No temporary file for the list of tests.';
		}
		$process = proc_open( array_merge( $command, array( '--list-tests-xml', $list ) ), array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', $out, 'w' ), 2 => array( 'redirect', 1 ) ), $pipes, $cwd );
		if ( ! is_resource( $process ) ) {
			unlink( $list );
			unlink( $out );
			return 'Could not start ' . implode( ' ', $command ) . '.';
		}
		$code   = proc_close( $process );
		$output = (string) file_get_contents( $out );
		$xml    = (string) file_get_contents( $list );
		unlink( $list );
		unlink( $out );
		if ( 0 !== $code ) {
			return 'Listing the tests failed (exit ' . $code . '): ' . trim( substr( $output, -2000 ) );
		}
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = '' !== trim( $xml ) && $doc->loadXML( $xml );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return 'PHPUnit wrote no list of tests: ' . trim( substr( $output, -2000 ) );
		}
		return $doc->getElementsByTagNameNS( '*', 'testMethod' )->length + $doc->getElementsByTagNameNS( '*', 'phptFile' )->length;
	}

	/**
	 * A verdict that fails.
	 *
	 * @param int    $ran     Tests in the log.
	 * @param string $message Why.
	 * @return array{ok:bool,ran:int,message:string}
	 */
	private static function no( $ran, $message ) {
		return array(
			'ok'      => false,
			'ran'     => $ran,
			'message' => $message,
		);
	}
}
