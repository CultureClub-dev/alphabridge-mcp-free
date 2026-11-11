<?php
/**
 * The step after a PHPUnit run in CI: red unless the run reached its end
 * with every listed test accounted for and none failed.
 *
 *   php tests/integration/bin/run-complete.php <junit.xml> <events.txt> <phpunit command …>
 *
 * The command is the one of the run without its output options (--testdox,
 * --log-junit, --log-events-text): the same configuration and the same
 * filters, so that the list of tests (--list-tests-xml, collected without
 * running them) is what the run had to run. Rules in
 * ../support/class-ci-run-complete.php.
 *
 * Exit: 0 complete; 1 the run ended early, a test failed or never ran;
 * 2 no command, or the tests could not be listed.
 *
 * @package AlphaBridge_MCP
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 2 );
}

require dirname( __DIR__ ) . '/support/class-ci-run-complete.php';

if ( $argc < 4 ) {
	fwrite( STDERR, "usage: php tests/integration/bin/run-complete.php <junit.xml> <events.txt> <phpunit command …>\n" );
	exit( 2 );
}

$ab_ci_listed = AB_CI_Run_Complete::listed( array_slice( $argv, 3 ), (string) getcwd() );
if ( ! is_array( $ab_ci_listed ) ) {
	fwrite( STDERR, "run-complete: $ab_ci_listed\n" );
	exit( 2 );
}
$ab_ci_junit = AB_CI_Run_Complete::junit( $argv[1] );
if ( null !== $ab_ci_junit ) {
	fwrite( STDERR, "run-complete: FAIL: $ab_ci_junit\n" );
	exit( 1 );
}
$ab_ci_verdict = AB_CI_Run_Complete::events( $argv[2], $ab_ci_listed );
if ( ! $ab_ci_verdict['ok'] ) {
	fwrite( STDERR, 'run-complete: FAIL: ' . $ab_ci_verdict['message'] . "\n" );
	exit( 1 );
}
echo 'run-complete: ' . $ab_ci_verdict['message'] . "\n";
exit( 0 );
