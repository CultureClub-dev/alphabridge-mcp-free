<?php
/**
 * A plan of AB_MCP_IT_Child in plain PHP, without WordPress: for the tests
 * of the helpers themselves (TestAbortTest, TestSeamTest) and for code that
 * needs no site.
 *
 *   php plain-call.php          plan as JSON on stdin
 *
 * @package AlphaBridge_MCP
 */

if ( 'cli' !== PHP_SAPI || 1 !== count( $argv ) ) {
	exit( 2 );
}

require dirname( __DIR__ ) . '/support/class-it-child.php';

$ab_it_plan = AB_MCP_IT_Child::plan();
AB_MCP_IT_Child::prepare( $ab_it_plan );
AB_MCP_IT_Child::run( $ab_it_plan );
