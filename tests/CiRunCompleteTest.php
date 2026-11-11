<?php
/**
 * A PHPUnit run in CI counts only when it reached its end (Z8b branch 1, V1).
 *
 * The proof of the gap and of its close, on a suite whose second test ends
 * the process with a real exit (tests/fixtures/ci-lauf/abbruch.xml): the
 * step of 9be07f7 (PHPUnit, its status only) passes it with status 0; the
 * new step (tests/integration/bin/run-complete.php after a run with --log-junit) is red.
 * Then each clause of AB_CI_Run_Complete, and that every PHPUnit run in
 * .github/workflows/ci.yml has that step with the same command.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_CI_Run_Complete;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/integration/support/class-ci-run-complete.php';

final class CiRunCompleteTest extends TestCase {

	/** @var string[] Files to remove after the test. */
	private array $remove = array();

	protected function tearDown(): void {
		foreach ( $this->remove as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
	}

	private static function root(): string {
		return dirname( __DIR__ );
	}

	/**
	 * The PHPUnit command of a fixture suite.
	 *
	 * @return string[]
	 */
	private static function phpunit( string $config ): array {
		return array( PHP_BINARY, self::root() . '/vendor/bin/phpunit', '-c', self::root() . '/tests/fixtures/ci-lauf/' . $config );
	}

	/**
	 * Run a command from the repo root.
	 *
	 * @param string[] $command Command.
	 * @return array{0:int,1:string} Exit code, output.
	 */
	private static function command( array $command ): array {
		$out     = (string) tempnam( sys_get_temp_dir(), 'ab-ci-test-' );
		$process = proc_open( $command, array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', $out, 'w' ), 2 => array( 'redirect', 1 ) ), $pipes, self::root() );
		self::assertIsResource( $process );
		$code   = proc_close( $process );
		$output = (string) file_get_contents( $out );
		unlink( $out );
		return array( $code, $output );
	}

	private function temp( ?string $content = null ): string {
		$file           = (string) tempnam( sys_get_temp_dir(), 'ab-ci-junit-' );
		$this->remove[] = $file;
		if ( null === $content ) {
			unlink( $file );
		} else {
			file_put_contents( $file, $content );
		}
		return $file;
	}

	private static function junit( int $cases, string $root = 'testsuites' ): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?><' . $root . '><testsuite name="s" tests="' . $cases . '">';
		for ( $i = 0; $i < $cases; $i++ ) {
			$xml .= '<testcase name="t' . $i . '"/>';
		}
		return $xml . '</testsuite></' . $root . '>';
	}

	/* --------------------------------------------- the proof: old and new */

	public function testTheStepOfTheOldStandPassesASuiteThatExitsInTheMiddle(): void {
		list( $code, $output ) = self::command( self::phpunit( 'abbruch.xml' ) );
		self::assertSame( 0, $code, 'The run of 9be07f7 reads only this status: a pass. ' . $output );
		self::assertStringNotContainsString( 'Tests:', $output, 'No summary: the run ended in test B.' );
		self::assertStringNotContainsString( 'OK (', $output );
		self::assertStringNotContainsString( 'C runs only', $output, 'Test C never ran.' );
	}

	public function testTheNewStepIsRedForASuiteThatExitsInTheMiddle(): void {
		$junit                 = $this->temp( '' );
		list( $code, $output ) = self::command( array_merge( self::phpunit( 'abbruch.xml' ), array( '--testdox', '--log-junit', $junit ) ) );
		self::assertSame( 0, $code, 'PHPUnit still ends with status 0. ' . $output );
		list( $check, $said ) = self::command( array_merge( array( PHP_BINARY, self::root() . '/tests/integration/bin/run-complete.php', $junit ), self::phpunit( 'abbruch.xml' ) ) );
		self::assertSame( 1, $check, $said );
		self::assertStringContainsString( 'FAIL', $said );
		self::assertStringContainsString( 'is empty', $said );
	}

	public function testTheNewStepPassesASuiteThatRunsToItsEndWithDataSetsSkippedAndIncompleteTests(): void {
		$junit                 = $this->temp( '' );
		list( $code, $output ) = self::command( array_merge( self::phpunit( 'ganz.xml' ), array( '--testdox', '--log-junit', $junit ) ) );
		self::assertSame( 0, $code, $output );
		list( $check, $said ) = self::command( array_merge( array( PHP_BINARY, self::root() . '/tests/integration/bin/run-complete.php', $junit ), self::phpunit( 'ganz.xml' ) ) );
		self::assertSame( 0, $check, $said );
		self::assertStringContainsString( '6 of 6 tests ran', $said, 'Three data sets, a skipped, an incomplete and a plain test, counted alike in the log and in the list.' );
	}

	public function testTheListCountsEachDataSetAsTheLogDoes(): void {
		self::assertSame( 6, AB_CI_Run_Complete::expected( self::phpunit( 'ganz.xml' ), self::root() ) );
		self::assertSame( 3, AB_CI_Run_Complete::expected( self::phpunit( 'abbruch.xml' ), self::root() ), 'Listed without running: test B does not end the listing.' );
	}

	public function testAListThatFailsIsNoNumber(): void {
		$said = AB_CI_Run_Complete::expected( array( PHP_BINARY, '-r', 'exit( 3 );', '--' ), self::root() );
		self::assertIsString( $said );
		self::assertStringContainsString( 'exit 3', $said );
		$said = AB_CI_Run_Complete::expected( array( PHP_BINARY, '-r', 'exit( 0 );', '--' ), self::root() );
		self::assertIsString( $said, 'A command that writes no list gives no number.' );
		self::assertStringContainsString( 'no list', $said );
	}

	public function testTheScriptWithoutACommandSaysHowToCallIt(): void {
		list( $code, $said ) = self::command( array( PHP_BINARY, self::root() . '/tests/integration/bin/run-complete.php', 'x.xml' ) );
		self::assertSame( 2, $code );
		self::assertStringContainsString( 'usage', $said );
	}

	/* --------------------------------------------------- clause by clause */

	public function testAMissingLogIsRed(): void {
		$verdict = AB_CI_Run_Complete::verdict( $this->temp(), 3 );
		self::assertFalse( $verdict['ok'] );
		self::assertStringContainsString( 'missing', $verdict['message'] );
	}

	public function testAnEmptyLogIsRed(): void {
		$verdict = AB_CI_Run_Complete::verdict( $this->temp( "\n" ), 3 );
		self::assertFalse( $verdict['ok'] );
		self::assertStringContainsString( 'empty', $verdict['message'] );
	}

	public function testALogThatIsNoXmlIsRed(): void {
		$verdict = AB_CI_Run_Complete::verdict( $this->temp( '<testsuites><testsuite' ), 3 );
		self::assertFalse( $verdict['ok'] );
		self::assertStringContainsString( 'no XML', $verdict['message'] );
	}

	public function testALogWithAnotherRootIsRed(): void {
		$verdict = AB_CI_Run_Complete::verdict( $this->temp( self::junit( 3, 'testsuite' ) ), 3 );
		self::assertFalse( $verdict['ok'] );
		self::assertStringContainsString( 'not testsuites', $verdict['message'] );
	}

	public function testFewerTestsThanListedIsRed(): void {
		$verdict = AB_CI_Run_Complete::verdict( $this->temp( self::junit( 2 ) ), 3 );
		self::assertFalse( $verdict['ok'] );
		self::assertSame( 2, $verdict['ran'] );
		self::assertStringContainsString( 'Only 2 of 3', $verdict['message'] );
	}

	public function testNoListedTestIsRed(): void {
		$verdict = AB_CI_Run_Complete::verdict( $this->temp( self::junit( 0 ) ), 0 );
		self::assertFalse( $verdict['ok'] );
		self::assertStringContainsString( 'no test', $verdict['message'] );
	}

	public function testAsManyOrMoreTestsThanListedIsComplete(): void {
		self::assertTrue( AB_CI_Run_Complete::verdict( $this->temp( self::junit( 3 ) ), 3 )['ok'] );
		self::assertTrue( AB_CI_Run_Complete::verdict( $this->temp( self::junit( 4 ) ), 3 )['ok'], 'More is fine: a test added while running does not make a run incomplete.' );
	}

	/* ------------------------------------------------------ the workflow */

	public function testEveryPhpunitRunInTheWorkflowHasTheCheckWithTheSameCommand(): void {
		$yml   = (string) file_get_contents( self::root() . '/.github/workflows/ci.yml' );
		$runs  = array();
		$check = array();
		foreach ( explode( "\n", $yml ) as $line ) {
			if ( preg_match( '#php tests/integration/bin/run-complete\.php "([^"]+)" (vendor/bin/phpunit.*?)\s*$#', $line, $m ) ) {
				$check[ $m[1] ] = $m[2];
			} elseif ( preg_match( '#^\s*(?:run:\s*)?(vendor/bin/phpunit.*?)\s*$#', $line, $m ) ) {
				$runs[] = $m[1];
			}
		}
		self::assertCount( 2, $runs, 'The jobs unit and integration each run PHPUnit once: ' . implode( ' | ', $runs ) );
		foreach ( $runs as $run ) {
			self::assertMatchesRegularExpression( '#--log-junit "([^"]+)"#', $run, "$run writes no JUnit log." );
			preg_match( '#--log-junit "([^"]+)"#', $run, $m );
			self::assertArrayHasKey( $m[1], $check, "No check reads the log of: $run" );
			$base = trim( (string) preg_replace( '#\s+#', ' ', str_replace( array( '--log-junit "' . $m[1] . '"', '--testdox' ), '', $run ) ) );
			self::assertSame( $base, $check[ $m[1] ], 'The check lists the tests of the same command as the run.' );
		}
		self::assertCount( count( $runs ), $check );
	}
}
