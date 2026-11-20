<?php
/**
 * The time limit of the command line for each test of the integration suite
 * (Z8b branch 5, 1a-4b and 1a-4d). PHPUnit runs as the PHP command line, so
 * the process starts without a limit (max_execution_time 0). A test that
 * installs a plugin through WordPress (the install tools in CodeStageTest)
 * reaches WP_Upgrader::install_package(), and WordPress calls
 * set_time_limit( 300 ) there (wp-admin/includes/class-wp-upgrader.php of
 * 7.1.3). Without this, that limit held for the rest of the process and
 * ended a long run of the suite with «Maximum execution time of 300 seconds
 * exceeded»: on a Mac with Apple silicon PHP counts the time of the clock,
 * under Linux without thread safety (CI) the time the process computes.
 *
 * set_time_limit( 0 ) restores the limit of the command line, as
 * tests/SyncRunTest.php does after its own limits, at three events of
 * PHPUnit 11.5 (TestCase::runBare(), TestRunner::run()):
 * - Test\PreparationStarted, the first thing runBare() does for every test:
 *   each test begins without a limit, whatever the one before it did (a
 *   setUp() that set one and skipped or threw, a tearDown() after it);
 * - Test\AfterTestMethodFinished, after the tearDown() of every test that
 *   ran one, prepared or not;
 * - Test\Finished, after a test that was prepared, its tearDown() included
 *   (PHPUnit sends it only for those).
 * A test that sets a limit of its own keeps it while it runs. Between two
 * classes (tearDownAfterClass(), setUpBeforeClass()) a limit set by the
 * last test's setUp() that skipped or threw before preparation completed
 * (anything but a failed assertion, after which PHPUnit counts the test as
 * prepared and sends Finished), with no after-hook of its own (tearDown()
 * or an #[After] method), stays until the next test begins. Child
 * processes (AB_MCP_IT_Process, AB_MCP_Test_Abort) are not touched: each
 * starts with its own limit. Registered in phpunit.xml.dist of this suite;
 * bootstrap.php requires this file. Test code only.
 *
 * @package AlphaBridge_MCP
 */

use PHPUnit\Event\Test\AfterTestMethodFinished;
use PHPUnit\Event\Test\AfterTestMethodFinishedSubscriber;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Class AB_MCP_IT_Time_Limit: the extension that subscribes the resets.
 */
final class AB_MCP_IT_Time_Limit implements Extension {

	/**
	 * Subscribe the reset to the beginning of each test, to the end of its
	 * tearDown() and to its end.
	 *
	 * @param Configuration       $configuration The configuration of the run.
	 * @param Facade              $facade        Where an extension subscribes.
	 * @param ParameterCollection $parameters    None are read.
	 */
	public function bootstrap( Configuration $configuration, Facade $facade, ParameterCollection $parameters ): void {
		$facade->registerSubscribers(
			new AB_MCP_IT_Time_Limit_Begin(),
			new AB_MCP_IT_Time_Limit_After_Tear_Down(),
			new AB_MCP_IT_Time_Limit_Reset()
		);
	}
}

/**
 * Class AB_MCP_IT_Time_Limit_Begin: no limit when the preparation of a test starts.
 */
final class AB_MCP_IT_Time_Limit_Begin implements PreparationStartedSubscriber {

	/**
	 * A test begins (before its setUp()): no limit, as the command line starts.
	 *
	 * @param PreparationStarted $event The test that begins.
	 */
	public function notify( PreparationStarted $event ): void {
		set_time_limit( 0 );
	}
}

/**
 * Class AB_MCP_IT_Time_Limit_After_Tear_Down: no limit after a tearDown().
 */
final class AB_MCP_IT_Time_Limit_After_Tear_Down implements AfterTestMethodFinishedSubscriber {

	/**
	 * The tearDown() of a test has run, whether the test was prepared or not.
	 *
	 * @param AfterTestMethodFinished $event The after-hooks that ran.
	 */
	public function notify( AfterTestMethodFinished $event ): void {
		set_time_limit( 0 );
	}
}

/**
 * Class AB_MCP_IT_Time_Limit_Reset: the limit of the command line, after each prepared test.
 */
final class AB_MCP_IT_Time_Limit_Reset implements FinishedSubscriber {

	/**
	 * A prepared test has finished (its tearDown() included): no limit, as the command line starts.
	 *
	 * @param Finished $event The test that finished.
	 */
	public function notify( Finished $event ): void {
		set_time_limit( 0 );
	}
}
