<?php
/**
 * The step after a PHPUnit run in CI: red unless the run reached its end.
 *
 *   php tests/integration/bin/run-complete.php <junit.xml> <phpunit command …>
 *
 * The command is the one of the run without its output options (--testdox,
 * --log-junit): the same configuration and the same filters, so that the
 * list of tests (--list-tests-xml, collected without running them) counts
 * what the run had to run. Rules in ../support/class-ci-run-complete.php.
 *
 * Exit: 0 complete; 1 the run ended early or the log is not a full one;
 * 2 no command, or the tests could not be listed.
 *
 * @package AlphaBridge_MCP
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 2 );
}

require dirname( __DIR__ ) . '/support/class-ci-run-complete.php';

if ( $argc < 3 ) {
	fwrite( STDERR, "usage: php tests/integration/bin/run-complete.php <junit.xml> <phpunit command …>\n" );
	exit( 2 );
}

$ab_ci_expected = AB_CI_Run_Complete::expected( array_slice( $argv, 2 ), (string) getcwd() );
if ( ! is_int( $ab_ci_expected ) ) {
	fwrite( STDERR, "run-complete: $ab_ci_expected\n" );
	exit( 2 );
}
$ab_ci_verdict = AB_CI_Run_Complete::verdict( $argv[1], $ab_ci_expected );
if ( ! $ab_ci_verdict['ok'] ) {
	fwrite( STDERR, 'run-complete: FAIL: ' . $ab_ci_verdict['message'] . "\n" );
	exit( 1 );
}
echo 'run-complete: ' . $ab_ci_verdict['message'] . "\n";
exit( 0 );
