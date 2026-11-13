<?php
/**
 * The time limit of the command line back after each test of the
 * integration suite (Z8b branch 5, 1a-4b). PHPUnit runs as the PHP command
 * line, so the process starts without a limit (max_execution_time 0). A test
 * that installs a plugin through WordPress (the install tools in
 * CodeStageTest) reaches WP_Upgrader::install_package(), and WordPress
 * calls set_time_limit( 300 ) there (wp-admin/includes/class-wp-upgrader.php
 * of 7.1.3). Without this, that limit held for the rest of the process and
 * ended a long run of the suite with «Maximum execution time of 300 seconds
 * exceeded»: on a Mac with Apple silicon PHP counts the time of the clock,
 * under Linux without thread safety (CI) the time the process computes.
 *
 * After each test, its tearDown() included, set_time_limit( 0 ) restores
 * the limit of the command line, as tests/SyncRunTest.php does after its
 * own limits. A test that sets a limit of its own keeps it while it runs.
 * Child processes (AB_MCP_IT_Process, AB_MCP_Test_Abort) are not touched:
 * each starts with its own limit. Registered in phpunit.xml.dist of this
 * suite; bootstrap.php requires this file. Test code only.
 *
 * @package AlphaBridge_MCP
 */

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Class AB_MCP_IT_Time_Limit: the extension that subscribes the reset.
 */
final class AB_MCP_IT_Time_Limit implements Extension {

	/**
	 * Subscribe the reset to the end of each test.
	 *
	 * @param Configuration       $configuration The configuration of the run.
	 * @param Facade              $facade        Where an extension subscribes.
	 * @param ParameterCollection $parameters    None are read.
	 */
	public function bootstrap( Configuration $configuration, Facade $facade, ParameterCollection $parameters ): void {
		$facade->registerSubscriber( new AB_MCP_IT_Time_Limit_Reset() );
	}
}

/**
 * Class AB_MCP_IT_Time_Limit_Reset: the limit of the command line, after each test.
 */
final class AB_MCP_IT_Time_Limit_Reset implements FinishedSubscriber {

	/**
	 * A test has finished (its tearDown() included): no limit, as the command line starts.
	 *
	 * @param Finished $event The test that finished.
	 */
	public function notify( Finished $event ): void {
		set_time_limit( 0 );
	}
}
