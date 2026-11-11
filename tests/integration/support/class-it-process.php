<?php
/**
 * A child process for a test, and its verdict (Z8b branch 1, point 4).
 *
 * run() starts a command with a time limit and reads stdout and stderr side
 * by side without blocking (V3 of the pre-check: AB_MCP_IT_Sync_Steps waits
 * for stdout before it reads stderr and has no limit; it is left as it is,
 * GRENZEN-PRUEFUMGEBUNG.md). At the limit it ends the child by its own PID
 * (proc_terminate with SIGKILL), and first what the child started (its
 * descendants, read with pgrep -P at that moment): no process of the suite
 * outlives its limit, and no other process is touched (V4 of the
 * pre-check concerns the mutation runner, which is left as it is).
 *
 * calls() runs a plan of AB_MCP_IT_Child and counts it only when the child
 * printed its result line and ended with status 0: an exit anywhere, a
 * wp_die(), a fatal error or a signal is never a pass.
 *
 * abort() runs a plan with an abort and counts it only with the marker of
 * that point in that mode and the proof of the mode (AB_MCP_Test_Abort): a
 * run that ends anywhere else, or runs to its end, is an error.
 *
 * Both work for a site (bin/site-call.php, its WordPress) or in plain PHP
 * (bin/plain-call.php).
 *
 * @package AlphaBridge_MCP
 */

require_once __DIR__ . '/class-test-abort.php';
require_once __DIR__ . '/class-it-child.php';

/**
 * Class AB_MCP_IT_Process
 */
final class AB_MCP_IT_Process {

	/** Default seconds a child may take. */
	const TIMEOUT = 120;

	/**
	 * Run a command.
	 *
	 * @param string[]      $command Command (no shell).
	 * @param string        $stdin   Bytes for its stdin.
	 * @param int|float     $timeout Seconds; then the child is killed.
	 * @param string|null   $cwd     Directory.
	 * @param string[]|null $env     Environment (null: this one).
	 * @return array{exit:int|null,signal:int|null,timed_out:bool,stdout:string,stderr:string,seconds:float,pid:int}
	 */
	public static function run( array $command, $stdin = '', $timeout = self::TIMEOUT, $cwd = null, $env = null ) {
		$started = microtime( true );
		$process = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $cwd, $env );
		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( 'Could not start ' . implode( ' ', $command ) . '.' );
		}
		$pid = (int) proc_get_status( $process )['pid'];
		foreach ( $pipes as $pipe ) {
			stream_set_blocking( $pipe, false );
		}
		$stdin     = (string) $stdin;
		$written   = 0;
		$open      = array(
			1 => true,
			2 => true,
		);
		$buffers   = array(
			1 => '',
			2 => '',
		);
		$stdin_open = true;
		if ( '' === $stdin ) {
			fclose( $pipes[0] );
			$stdin_open = false;
		}
		$status    = null;
		$timed_out = false;
		while ( true ) {
			$read  = array();
			$write = $stdin_open ? array( $pipes[0] ) : array();
			foreach ( $open as $i => $is_open ) {
				if ( $is_open ) {
					$read[] = $pipes[ $i ];
				}
			}
			$except = null;
			if ( array() !== $read || array() !== $write ) {
				@stream_select( $read, $write, $except, 0, 100000 );
			} else {
				usleep( 50000 );
			}
			if ( $stdin_open && array() !== $write ) {
				$chunk = @fwrite( $pipes[0], (string) substr( $stdin, $written, 65536 ) );
				if ( false === $chunk ) {
					$written = strlen( $stdin );
				} else {
					$written += $chunk;
				}
				if ( $written >= strlen( $stdin ) ) {
					fclose( $pipes[0] );
					$stdin_open = false;
				}
			}
			self::drain( $pipes, $open, $buffers );
			$state = proc_get_status( $process );
			if ( ! $state['running'] ) {
				$status = $state;
				break;
			}
			if ( microtime( true ) - $started > $timeout ) {
				$timed_out = true;
				// The processes the child started go first (a shell's
				// commands, say), found as its descendants at this moment;
				// then the child itself, by its own PID.
				foreach ( self::descendants( $pid ) as $descendant ) {
					posix_kill( $descendant, 9 );
				}
				proc_terminate( $process, 9 );
				for ( $i = 0; $i < 100; $i++ ) {
					$state = proc_get_status( $process );
					if ( ! $state['running'] ) {
						break;
					}
					usleep( 50000 );
				}
				$status = $state;
				break;
			}
		}
		// What the child wrote before it ended, without waiting on a pipe
		// that something else might hold open.
		$until = microtime( true ) + 2;
		while ( ( $open[1] || $open[2] ) && microtime( true ) < $until ) {
			$before = strlen( $buffers[1] ) + strlen( $buffers[2] );
			self::drain( $pipes, $open, $buffers );
			if ( strlen( $buffers[1] ) + strlen( $buffers[2] ) === $before ) {
				usleep( 20000 );
			}
		}
		foreach ( array( 1, 2 ) as $i ) {
			if ( is_resource( $pipes[ $i ] ) ) {
				fclose( $pipes[ $i ] );
			}
		}
		if ( $stdin_open ) {
			fclose( $pipes[0] );
		}
		proc_close( $process );
		$signaled = ! empty( $status['signaled'] );
		return array(
			'exit'      => $signaled || $status['running'] ? null : (int) $status['exitcode'],
			'signal'    => $signaled ? (int) $status['termsig'] : null,
			'timed_out' => $timed_out,
			'stdout'    => $buffers[1],
			'stderr'    => $buffers[2],
			'seconds'   => microtime( true ) - $started,
			'pid'       => $pid,
		);
	}

	/**
	 * The processes below a PID (children, their children …), deepest first,
	 * read with pgrep -P at this moment.
	 *
	 * @param int $pid PID.
	 * @return int[]
	 */
	public static function descendants( $pid ) {
		$out = array();
		$run = proc_open( array( 'pgrep', '-P', (string) (int) $pid ), array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ), $pipes );
		if ( ! is_resource( $run ) ) {
			return $out;
		}
		$list = (string) stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		proc_close( $run );
		foreach ( preg_split( '/\s+/', trim( $list ) ) as $child ) {
			if ( ctype_digit( (string) $child ) ) {
				$out = array_merge( $out, self::descendants( (int) $child ), array( (int) $child ) );
			}
		}
		return $out;
	}

	/**
	 * Read what the pipes hold now.
	 *
	 * @param resource[]         $pipes   Pipes.
	 * @param array<int,bool>    $open    Open stdout/stderr.
	 * @param array<int,string>  $buffers What was read.
	 */
	private static function drain( array $pipes, array &$open, array &$buffers ) {
		foreach ( array( 1, 2 ) as $i ) {
			while ( $open[ $i ] ) {
				$chunk = fread( $pipes[ $i ], 65536 );
				if ( false === $chunk || '' === $chunk ) {
					if ( feof( $pipes[ $i ] ) ) {
						$open[ $i ] = false;
					}
					break;
				}
				$buffers[ $i ] .= $chunk;
			}
		}
	}

	/**
	 * The command of a child runner.
	 *
	 * @param string|null $site 'a' or 'b' (bin/site-call.php), null for plain
	 *                          PHP (bin/plain-call.php).
	 * @return string[]
	 */
	public static function child_command( $site ) {
		if ( null === $site ) {
			return array( PHP_BINARY, dirname( __DIR__ ) . '/bin/plain-call.php' );
		}
		return array( PHP_BINARY, dirname( __DIR__ ) . '/bin/site-call.php', AB_MCP_IT_Sites::state_file(), (string) $site );
	}

	/**
	 * Run a plan that must run to its end.
	 *
	 * @param string|null $site    Site, or null for plain PHP.
	 * @param array       $plan    Plan of AB_MCP_IT_Child (without marker).
	 * @param int         $timeout Seconds.
	 * @return array{results:array,seams:array,run:array}
	 */
	public static function calls( $site, array $plan, $timeout = self::TIMEOUT ) {
		if ( isset( $plan['marker'] ) ) {
			throw new InvalidArgumentException( 'A plan run by calls() takes no marker: an abort goes through abort().' );
		}
		$run    = self::run( self::child_command( $site ), (string) json_encode( $plan ), $timeout );
		$result = self::result( $run );
		if ( $run['timed_out'] ) {
			throw new RuntimeException( self::describe( 'The child took longer than ' . $timeout . ' s and was ended', $run ) );
		}
		if ( null === $result || 0 !== $run['exit'] ) {
			throw new RuntimeException( self::describe( 'The child did not run its plan to the end (no result line, or not status 0): an exit, a wp_die(), a fatal error or a signal is no pass', $run ) );
		}
		return array(
			'results' => $result['results'],
			'seams'   => $result['seams'],
			'run'     => $run,
		);
	}

	/**
	 * Run a plan with one abort, and require it at its point in its mode.
	 *
	 * @param string|null $site    Site, or null for plain PHP.
	 * @param array       $plan    Plan with exactly one abort: a fault of
	 *                             kind abort in a seam, or one hook.
	 * @param int         $timeout Seconds.
	 * @return array{point:string,mode:string,marker:array,run:array}
	 */
	public static function abort( $site, array $plan, $timeout = self::TIMEOUT ) {
		$aborts = array();
		foreach ( isset( $plan['seams'] ) ? (array) $plan['seams'] : array() as $seam ) {
			foreach ( isset( $seam['faults'] ) ? (array) $seam['faults'] : array() as $fault ) {
				if ( isset( $fault['kind'] ) && 'abort' === $fault['kind'] ) {
					$aborts[] = $fault;
				}
			}
		}
		foreach ( isset( $plan['hooks'] ) ? (array) $plan['hooks'] : array() as $hook ) {
			$aborts[] = $hook;
		}
		if ( 1 !== count( $aborts ) ) {
			throw new InvalidArgumentException( 'abort() takes a plan with exactly one abort; it has ' . count( $aborts ) . '.' );
		}
		$point = (string) $aborts[0]['point'];
		$mode  = (string) $aborts[0]['mode'];
		AB_MCP_Test_Abort::check_mode( $mode );
		$marker         = sys_get_temp_dir() . '/ab-it-abort-' . getmypid() . '-' . bin2hex( random_bytes( 8 ) ) . '.json';
		$plan['marker'] = $marker;
		try {
			$run    = self::run( self::child_command( $site ), (string) json_encode( $plan ), $timeout );
			$record = AB_MCP_Test_Abort::read( $marker );
		} finally {
			if ( is_file( $marker ) ) {
				unlink( $marker );
			}
			foreach ( (array) glob( $marker . '.*.tmp' ) as $tmp ) {
				unlink( (string) $tmp );
			}
		}
		if ( $run['timed_out'] ) {
			throw new RuntimeException( self::describe( "The abort at '$point' did not end the child within $timeout s", $run ) );
		}
		if ( null !== self::result( $run ) ) {
			throw new RuntimeException( self::describe( "The child ran its plan to the end: the point '$point' never came", $run ) );
		}
		if ( null === $record || empty( $record['fired'] ) ) {
			throw new RuntimeException( self::describe( "The child ended without the marker of '$point': it stopped somewhere else (an exit, a wp_die(), a fatal error), not at the point", $run ) );
		}
		if ( $point !== $record['point'] || $mode !== $record['mode'] ) {
			throw new RuntimeException( self::describe( "The marker names '{$record['point']}' ({$record['mode']}), not '$point' ($mode)", $run ) );
		}
		$ran = ! empty( $record['shutdown_ran'] );
		if ( 'no_shutdown' === $mode && ( 9 !== $run['signal'] || $ran ) ) {
			throw new RuntimeException( self::describe( "The abort without shutdown at '$point' did not end by the signal 9 before any shutdown function", $run ) );
		}
		if ( 'shutdown' === $mode && ( null !== $run['signal'] || ! $ran ) ) {
			throw new RuntimeException( self::describe( "The abort with shutdown at '$point' did not run the shutdown functions to their end", $run ) );
		}
		if ( 'timeout' === $mode ) {
			$error = isset( $record['error'] ) && is_array( $record['error'] ) ? $record['error'] : array();
			if ( ! $ran || E_ERROR !== ( isset( $error['type'] ) ? (int) $error['type'] : 0 ) || false === strpos( (string) ( isset( $error['message'] ) ? $error['message'] : '' ), 'Maximum execution time' ) ) {
				throw new RuntimeException( self::describe( "The abort by time limit at '$point' did not end with PHP's fatal error of the time limit and its shutdown", $run ) );
			}
		}
		return array(
			'point'  => $point,
			'mode'   => $mode,
			'marker' => $record,
			'run'    => $run,
		);
	}

	/**
	 * The result of a child runner, or null when it printed none.
	 *
	 * @param array $run Run.
	 * @return array|null
	 */
	public static function result( array $run ) {
		$at = strrpos( $run['stdout'], AB_MCP_IT_Child::RESULT_LINE . "\n" );
		if ( false === $at ) {
			return null;
		}
		$result = json_decode( trim( substr( $run['stdout'], $at + strlen( AB_MCP_IT_Child::RESULT_LINE ) + 1 ) ), true );
		return is_array( $result ) && isset( $result['completed'] ) && true === $result['completed'] ? $result : null;
	}

	/**
	 * A message with what the child left.
	 *
	 * @param string $what What went wrong.
	 * @param array  $run  Run.
	 * @return string
	 */
	private static function describe( $what, array $run ) {
		return sprintf(
			"%s (exit %s, signal %s, %.1f s).\nstdout: %s\nstderr: %s",
			$what,
			var_export( $run['exit'], true ),
			var_export( $run['signal'], true ),
			$run['seconds'],
			substr( $run['stdout'], -3000 ),
			substr( $run['stderr'], -3000 )
		);
	}
}
