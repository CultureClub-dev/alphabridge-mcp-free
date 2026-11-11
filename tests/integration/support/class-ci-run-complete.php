<?php
/**
 * Did a PHPUnit run reach its end, every test accounted for, none failed?
 * (Z8b branch 1, V1 of the pre-check; Codex round 1, A1 and A4.)
 *
 * A real exit or die in the test process ends PHPUnit 11 at once, with
 * status 0 and no summary: the tests after it never run, and a step that
 * reads only the status counts that as a pass (measured 11.11.2026 with
 * PHPUnit 11.5.56: test A green, test B exit, test C red gives status 0).
 * A shutdown function with exit( 0 ) after a red test turns PHPUnit's status
 * 1 into 0 as well (A1).
 *
 * Two logs PHPUnit writes only as its run ends decide instead:
 * - the JUnit log (--log-junit) exists, is not empty, is XML with the root
 *   testsuites and holds no <failure> and no <error>;
 * - the event log (--log-events-text) ends with «PHPUnit Finished (Shell
 *   Exit Code: 0)», PHPUnit's own verdict, whatever status a shutdown
 *   function leaves; it has no «Test Failed» or «Test Errored»; and every
 *   test PHPUnit lists for the same command (--list-tests-xml, collected
 *   without running) is accounted for by its id: «Test Finished (id)» (ran,
 *   also incomplete or risky), «Test Skipped (id)» (skipped, also by a
 *   requirement, without Finished) or a «Test Suite Skipped (name, …)» of
 *   its class or its data provider (a skip in setUpBeforeClass(), which
 *   writes no testcase to the JUnit log: A4). A listed test with none of
 *   these never ran.
 * The ids of the list and of the events are the same strings (data sets as
 * Class::method#name), measured with PHPUnit 11.5.56.
 *
 * Used by tests/integration/bin/run-complete.php in the jobs unit and
 * integration of .github/workflows/ci.yml; CiRunCompleteTest holds each
 * clause.
 *
 * @package AlphaBridge_MCP
 */

/**
 * Class AB_CI_Run_Complete
 */
final class AB_CI_Run_Complete {

	/** The last line of a complete event log. */
	const FINISHED = 'PHPUnit Finished (Shell Exit Code: 0)';

	/**
	 * The verdict on the JUnit log.
	 *
	 * @param string $junit Path of the JUnit log.
	 * @return string|null Why it fails, or null.
	 */
	public static function junit( $junit ) {
		clearstatcache( true, $junit );
		if ( ! is_file( $junit ) ) {
			return "The JUnit log $junit is missing: PHPUnit did not reach the end of its run.";
		}
		$xml = (string) file_get_contents( $junit );
		if ( '' === trim( $xml ) ) {
			return "The JUnit log $junit is empty: PHPUnit ended before the end of its run (a real exit or die in a test?).";
		}
		$doc = self::xml( $xml );
		if ( null === $doc ) {
			return "The JUnit log $junit is no XML: the run was cut off while it was written.";
		}
		if ( 'testsuites' !== $doc->documentElement->nodeName ) {
			return "The JUnit log $junit has the root " . $doc->documentElement->nodeName . ', not testsuites.';
		}
		$failed = $doc->getElementsByTagName( 'failure' )->length + $doc->getElementsByTagName( 'error' )->length;
		if ( $failed > 0 ) {
			return "The JUnit log $junit records $failed failed test(s) (failure or error), whatever status the process ended with.";
		}
		return null;
	}

	/**
	 * The verdict on the event log against the tests listed.
	 *
	 * @param string   $events Path of the event log (--log-events-text).
	 * @param string[] $listed Ids of the tests listed for the same command.
	 * @return array{ok:bool,accounted:int,message:string}
	 */
	public static function events( $events, array $listed ) {
		clearstatcache( true, $events );
		if ( array() === $listed ) {
			return self::no( 0, 'PHPUnit lists no test for this command; a run without tests proves nothing.' );
		}
		if ( ! is_file( $events ) ) {
			return self::no( 0, "The event log $events is missing: PHPUnit did not reach the end of its run." );
		}
		$lines = preg_split( '/\r\n|\n|\r/', rtrim( (string) file_get_contents( $events ) ) );
		if ( self::FINISHED !== end( $lines ) ) {
			return self::no( 0, "The event log $events does not end with «" . self::FINISHED . '» but with «' . end( $lines ) . '»: PHPUnit did not finish, or finished with failures (a status of 0 left by a shutdown function changes nothing).' );
		}
		$done    = array();
		$skipped = array();
		foreach ( $lines as $line ) {
			if ( preg_match( '/^Test (?:Failed|Errored) \((.*)\)$/', $line, $m ) ) {
				return self::no( 0, "The event log $events records a failed test: {$m[1]}." );
			}
			if ( preg_match( '/^Test (?:Finished|Skipped) \((.*)\)$/', $line, $m ) ) {
				$done[ $m[1] ] = true;
			} elseif ( preg_match( '/^Test Suite Skipped \(([^,]+), /', $line, $m ) ) {
				$skipped[] = $m[1];
			}
		}
		$missing = array();
		foreach ( $listed as $id ) {
			if ( isset( $done[ $id ] ) ) {
				continue;
			}
			$covered = false;
			foreach ( $skipped as $suite ) {
				if ( $id === $suite || 0 === strpos( $id, $suite . '::' ) || 0 === strpos( $id, $suite . '#' ) ) {
					$covered = true;
					break;
				}
			}
			if ( ! $covered ) {
				$missing[] = $id;
			}
		}
		$accounted = count( $listed ) - count( $missing );
		if ( array() !== $missing ) {
			return self::no( $accounted, 'Only ' . $accounted . ' of ' . count( $listed ) . ' listed tests ran or were skipped; never ran: ' . implode( ', ', array_slice( $missing, 0, 5 ) ) . ( count( $missing ) > 5 ? ', …' : '' ) . '.' );
		}
		return array(
			'ok'        => true,
			'accounted' => $accounted,
			'message'   => "$accounted of " . count( $listed ) . ' listed tests ran or were skipped; none failed; both logs are complete.',
		);
	}

	/**
	 * The ids of the tests PHPUnit lists for a command (--list-tests-xml).
	 *
	 * @param string[] $command The PHPUnit command of the run, without its
	 *                          output options.
	 * @param string   $cwd     Directory to run it in.
	 * @return string[]|string The ids, or why they could not be had.
	 */
	public static function listed( array $command, $cwd ) {
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
		$doc = '' === trim( $xml ) ? null : self::xml( $xml );
		if ( null === $doc ) {
			return 'PHPUnit wrote no list of tests: ' . trim( substr( $output, -2000 ) );
		}
		$ids = array();
		foreach ( $doc->getElementsByTagNameNS( '*', 'testMethod' ) as $method ) {
			$ids[] = $method->getAttribute( 'id' );
		}
		foreach ( $doc->getElementsByTagNameNS( '*', 'phptFile' ) as $phpt ) {
			$ids[] = $phpt->getAttribute( 'path' );
		}
		return $ids;
	}

	/**
	 * A document from XML, or null.
	 *
	 * @param string $xml XML.
	 * @return DOMDocument|null
	 */
	private static function xml( $xml ) {
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $doc->loadXML( $xml );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return $loaded && null !== $doc->documentElement ? $doc : null;
	}

	/**
	 * A verdict that fails.
	 *
	 * @param int    $accounted Tests accounted for.
	 * @param string $message   Why.
	 * @return array{ok:bool,accounted:int,message:string}
	 */
	private static function no( $accounted, $message ) {
		return array(
			'ok'        => false,
			'accounted' => $accounted,
			'message'   => $message,
		);
	}
}
