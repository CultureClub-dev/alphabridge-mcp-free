<?php
/**
 * A PHPUnit run in CI counts only when it reached its end, accounted for
 * every listed test and none failed (Z8b branch 1, V1; Codex round 1, A1
 * and A4).
 *
 * The proofs, each on a suite of tests/fixtures/ci-lauf/:
 * - abbruch.xml, a real exit in the second test: the step of 9be07f7
 *   (PHPUnit, its status only) passes it with status 0, the new step
 *   (tests/integration/bin/run-complete.php after a run with --log-junit and
 *   --log-events-text) is red;
 * - rot.xml, a red test whose status a shutdown function turns into 0: old
 *   green, new red (A1);
 * - klassenskip.xml, a class skipped in setUpBeforeClass() (no testcase in
 *   the JUnit log): the count of 0f3be69 was red, the new step green (A4);
 * - ganz.xml, data sets, skipped, incomplete, a skip by a requirement:
 *   green.
 * Then each clause of AB_CI_Run_Complete, and that every PHPUnit run in
 * .github/workflows/ci.yml has that step with the same command.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_CI_Run_Complete;
use PHPUnit\Framework\Attributes\DataProvider;
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
		$file           = (string) tempnam( sys_get_temp_dir(), 'ab-ci-log-' );
		$this->remove[] = $file;
		if ( null === $content ) {
			unlink( $file );
		} else {
			file_put_contents( $file, $content );
		}
		return $file;
	}

	/**
	 * A suite run as the job runs it, then the new step.
	 *
	 * @return array{phpunit:int,check:int,said:string}
	 */
	private function both_steps( string $config ): array {
		$junit                 = $this->temp( '' );
		$events                = $this->temp( '' );
		list( $code, $output ) = self::command( array_merge( self::phpunit( $config ), array( '--testdox', '--log-junit', $junit, '--log-events-text', $events ) ) );
		list( $check, $said )  = self::command( array_merge( array( PHP_BINARY, self::root() . '/tests/integration/bin/run-complete.php', $junit, $events ), self::phpunit( $config ) ) );
		return array(
			'phpunit' => $code,
			'check'   => $check,
			'said'    => $said . "\nPHPUnit said: " . $output,
		);
	}

	private static function junit( int $cases, string $root = 'testsuites', string $inner = '' ): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?><' . $root . '><testsuite name="s" tests="' . $cases . '">';
		for ( $i = 0; $i < $cases; $i++ ) {
			$xml .= '<testcase name="t' . $i . '">' . ( 0 === $i ? $inner : '' ) . '</testcase>';
		}
		return $xml . '</testsuite></' . $root . '>';
	}

	/* ------------------------------------------------ the proofs, old and new */

	public function testTheStepOfTheOldStandPassesASuiteThatExitsInTheMiddle(): void {
		list( $code, $output ) = self::command( self::phpunit( 'abbruch.xml' ) );
		self::assertSame( 0, $code, 'The run of 9be07f7 reads only this status: a pass. ' . $output );
		self::assertStringNotContainsString( 'Tests:', $output, 'No summary: the run ended in test B.' );
		self::assertStringNotContainsString( 'OK (', $output );
		self::assertStringNotContainsString( 'C runs only', $output, 'Test C never ran.' );
	}

	public function testTheNewStepIsRedForASuiteThatExitsInTheMiddle(): void {
		$run = $this->both_steps( 'abbruch.xml' );
		self::assertSame( 0, $run['phpunit'], 'PHPUnit still ends with status 0.' );
		self::assertSame( 1, $run['check'], $run['said'] );
		self::assertStringContainsString( 'FAIL', $run['said'] );
		self::assertStringContainsString( 'is empty', $run['said'] );
	}

	public function testARedTestWhoseStatusAShutdownFunctionTurnsIntoZeroIsRed(): void {
		list( $code ) = self::command( self::phpunit( 'rot.xml' ) );
		self::assertSame( 0, $code, 'The status the old step reads: a pass.' );
		$run = $this->both_steps( 'rot.xml' );
		self::assertSame( 0, $run['phpunit'] );
		self::assertSame( 1, $run['check'], $run['said'] );
		self::assertStringContainsString( 'records 1 failed test(s)', $run['said'] );
	}

	public function testAClassSkippedInSetUpBeforeClassCountsAsSkipped(): void {
		$run = $this->both_steps( 'klassenskip.xml' );
		self::assertSame( 0, $run['phpunit'], $run['said'] );
		self::assertSame( 0, $run['check'], $run['said'] );
		self::assertStringContainsString( '9 of 9 listed tests ran or were skipped', $run['said'], 'Two of the skipped class, seven of GanzProbe.' );
	}

	public function testDataSetsSkippedIncompleteAndARequirementAreAccountedFor(): void {
		$run = $this->both_steps( 'ganz.xml' );
		self::assertSame( 0, $run['phpunit'], $run['said'] );
		self::assertSame( 0, $run['check'], $run['said'] );
		self::assertStringContainsString( '7 of 7 listed tests ran or were skipped', $run['said'] );
	}

	public function testTheScriptIsRedWhenOnlyTheEventLogFails(): void {
		$junit                = $this->temp( self::junit( 7 ) );
		$events               = $this->temp( "Test Finished (GanzProbe::testPlain)\n" );
		list( $check, $said ) = self::command( array_merge( array( PHP_BINARY, self::root() . '/tests/integration/bin/run-complete.php', $junit, $events ), self::phpunit( 'ganz.xml' ) ) );
		self::assertSame( 1, $check, $said );
		self::assertStringContainsString( 'does not end with', $said );
	}

	public function testTheListGivesTheIdsTheEventLogUses(): void {
		self::assertSame(
			array( 'GanzProbe::testSet#eins', 'GanzProbe::testSet#zwei', 'GanzProbe::testSet#0', 'GanzProbe::testSkipped', 'GanzProbe::testIncomplete', 'GanzProbe::testPlain', 'GanzProbe::testRequirement' ),
			AB_CI_Run_Complete::listed( self::phpunit( 'ganz.xml' ), self::root() )
		);
		self::assertCount( 3, AB_CI_Run_Complete::listed( self::phpunit( 'abbruch.xml' ), self::root() ), 'Listed without running: test B does not end the listing.' );
	}

	public function testAListThatFailsIsNoList(): void {
		$said = AB_CI_Run_Complete::listed( array( PHP_BINARY, '-r', 'exit( 3 );', '--' ), self::root() );
		self::assertIsString( $said );
		self::assertStringContainsString( 'exit 3', $said );
		$said = AB_CI_Run_Complete::listed( array( PHP_BINARY, '-r', 'exit( 0 );', '--' ), self::root() );
		self::assertIsString( $said, 'A command that writes no list gives none.' );
		self::assertStringContainsString( 'no list', $said );
	}

	public function testTheScriptWithoutBothLogsAndACommandSaysHowToCallIt(): void {
		list( $code, $said ) = self::command( array( PHP_BINARY, self::root() . '/tests/integration/bin/run-complete.php', 'x.xml', 'vendor/bin/phpunit' ) );
		self::assertSame( 2, $code );
		self::assertStringContainsString( 'usage', $said );
	}

	/* ------------------------------------------- the JUnit log, by clause */

	/**
	 * @return array<string,array{0:string|null,1:string}>
	 */
	public static function junit_cases(): array {
		return array(
			'missing'      => array( null, 'missing' ),
			'empty'        => array( "\n", 'is empty' ),
			'no XML'       => array( '<testsuites><testsuite', 'no XML' ),
			'another root' => array( self::junit( 2, 'testsuite' ), 'not testsuites' ),
			'a failure'    => array( self::junit( 2, 'testsuites', '<failure>x</failure>' ), 'records 1 failed test(s)' ),
			'an error'     => array( self::junit( 2, 'testsuites', '<error>x</error>' ), 'records 1 failed test(s)' ),
		);
	}

	#[DataProvider( 'junit_cases' )]
	public function testEachClauseOfTheJunitLog( ?string $content, string $says ): void {
		$said = AB_CI_Run_Complete::junit( $this->temp( $content ) );
		self::assertIsString( $said );
		self::assertStringContainsString( $says, $said );
	}

	public function testAJunitLogWithoutFailureOrErrorPasses(): void {
		self::assertNull( AB_CI_Run_Complete::junit( $this->temp( self::junit( 3, 'testsuites', '<skipped/>' ) ) ) );
	}

	/* ------------------------------------------- the event log, by clause */

	private const DONE = "Test Finished (K::a)\nTest Skipped (K::b)\nTest Suite Started (S, 2 tests)\nTest Suite Skipped (S, why, with a comma)\nTest Finished (D::m#eins)\n";

	/**
	 * @return array<string,array{0:string|null,1:string[],2:string}>
	 */
	public static function event_cases(): array {
		$end = AB_CI_Run_Complete::FINISHED . "\n";
		$ids = array( 'K::a', 'K::b', 'S::x', 'S::y', 'D::m#eins' );
		return array(
			'missing'                  => array( null, $ids, 'is missing' ),
			'no end'                   => array( self::DONE, $ids, 'does not end with' ),
			'end with another code'    => array( self::DONE . "PHPUnit Finished (Shell Exit Code: 1)\n", $ids, 'does not end with' ),
			'a failed test'            => array( "Test Failed (K::a)\n" . self::DONE . $end, $ids, 'records a failed test: K::a' ),
			'an errored test'          => array( "Test Errored (K::a)\n" . self::DONE . $end, $ids, 'records a failed test: K::a' ),
			'a test that never ran'    => array( self::DONE . $end, array_merge( $ids, array( 'K::c' ) ), 'never ran: K::c' ),
			'a data set that never ran' => array( self::DONE . $end, array_merge( $ids, array( 'D::m#zwei' ) ), 'never ran: D::m#zwei' ),
			'a skip of another class'  => array( self::DONE . $end, array_merge( $ids, array( 'SS::x' ) ), 'never ran: SS::x' ),
			'no listed test'           => array( self::DONE . $end, array(), 'no test' ),
		);
	}

	/**
	 * @param string[] $listed Ids.
	 */
	#[DataProvider( 'event_cases' )]
	public function testEachClauseOfTheEventLog( ?string $content, array $listed, string $says ): void {
		$verdict = AB_CI_Run_Complete::events( $this->temp( $content ), $listed );
		self::assertFalse( $verdict['ok'] );
		self::assertStringContainsString( $says, $verdict['message'] );
	}

	public function testFinishedSkippedAndASkippedSuiteAccountForEveryListedTest(): void {
		$verdict = AB_CI_Run_Complete::events( $this->temp( self::DONE . AB_CI_Run_Complete::FINISHED . "\n" ), array( 'K::a', 'K::b', 'S::x', 'S::y', 'S#0', 'D::m#eins' ) );
		self::assertTrue( $verdict['ok'], $verdict['message'] );
		self::assertSame( 6, $verdict['accounted'] );
	}

	/* ------------------------------------------------------ the workflow */

	public function testEveryPhpunitRunInTheWorkflowHasTheCheckWithTheSameCommand(): void {
		$yml   = (string) file_get_contents( self::root() . '/.github/workflows/ci.yml' );
		$runs  = array();
		$check = array();
		foreach ( explode( "\n", $yml ) as $line ) {
			if ( preg_match( '#php tests/integration/bin/run-complete\.php "([^"]+)" "([^"]+)" (vendor/bin/phpunit.*?)\s*$#', $line, $m ) ) {
				$check[ $m[1] . ' ' . $m[2] ] = $m[3];
			} elseif ( preg_match( '#^\s*(?:run:\s*)?(vendor/bin/phpunit.*?)\s*$#', $line, $m ) ) {
				$runs[] = $m[1];
			}
		}
		self::assertCount( 2, $runs, 'The jobs unit and integration each run PHPUnit once: ' . implode( ' | ', $runs ) );
		foreach ( $runs as $run ) {
			self::assertMatchesRegularExpression( '#--log-junit "([^"]+)"#', $run, "$run writes no JUnit log." );
			self::assertMatchesRegularExpression( '#--log-events-text "([^"]+)"#', $run, "$run writes no event log." );
			preg_match( '#--log-junit "([^"]+)"#', $run, $j );
			preg_match( '#--log-events-text "([^"]+)"#', $run, $e );
			$key = $j[1] . ' ' . $e[1];
			self::assertArrayHasKey( $key, $check, "No check reads the logs of: $run" );
			$base = trim( (string) preg_replace( '#\s+#', ' ', str_replace( array( '--log-junit "' . $j[1] . '"', '--log-events-text "' . $e[1] . '"', '--testdox' ), '', $run ) ) );
			self::assertSame( $base, $check[ $key ], 'The check lists the tests of the same command as the run.' );
		}
		self::assertCount( count( $runs ), $check );
	}
}
