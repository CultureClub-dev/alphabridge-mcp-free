<?php
/**
 * Functions a plan of AB_MCP_IT_Child can call, for the tests of the abort
 * and process helpers (TestAbortTest, AbortHelperTest): each ends or loads a
 * process in one of the ways a run can end. Test code only.
 *
 * @package AlphaBridge_MCP
 */

/**
 * A shutdown function as a rescue of the plugin would register one: it
 * writes $file when it runs.
 *
 * @param string $file File.
 * @return bool
 */
function ab_it_probe_on_shutdown( $file ) {
	register_shutdown_function(
		static function () use ( $file ) {
			file_put_contents( $file, 'rescue ran' );
		}
	);
	return true;
}

/** A real exit with status 0. */
function ab_it_probe_exit() {
	exit( 0 );
}

/** What wp_die() does in the CLI: HTML, then die(), status 0. */
function ab_it_probe_die_html() {
	echo '<html><body><p>Stopped.</p></body></html>';
	die();
}

/** A fatal error no try/catch holds: memory exhausted. */
function ab_it_probe_fatal() {
	ini_set( 'memory_limit', '16M' );
	$keep = array();
	while ( true ) {
		$keep[] = str_repeat( 'x', 1048576 );
	}
}

/** A shutdown function that ends the process: the ones after it never run. */
function ab_it_probe_exit_in_shutdown() {
	register_shutdown_function(
		static function () {
			exit( 0 );
		}
	);
	return true;
}

/**
 * Much output on both pipes.
 *
 * @param int $bytes Bytes on each.
 * @return int
 */
function ab_it_probe_noise( $bytes ) {
	echo str_repeat( 'o', (int) $bytes );
	fwrite( STDERR, str_repeat( 'e', (int) $bytes ) );
	return (int) $bytes;
}

/**
 * Sleep.
 *
 * @param int $seconds Seconds.
 * @return bool
 */
function ab_it_probe_sleep( $seconds ) {
	sleep( (int) $seconds );
	return true;
}

/**
 * The PID of this process.
 *
 * @return int
 */
function ab_it_probe_pid() {
	return getmypid();
}

/**
 * The PID into a file, then sleep (for a test that must find the child by
 * its PID after the time limit).
 *
 * @param string $file    File.
 * @param int    $seconds Seconds.
 * @return bool
 */
function ab_it_probe_pid_then_sleep( $file, $seconds ) {
	file_put_contents( $file, (string) getmypid() );
	sleep( (int) $seconds );
	return true;
}
