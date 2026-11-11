<?php
/**
 * A child process for a test, and its verdict (Z8b branch 1, point 4;
 * Codex round 1, A2, A3, A6, B7).
 *
 * run() starts a command in a process group of its own, with a time limit,
 * and reads stdout and stderr side by side without blocking (V3 of the
 * pre-check: AB_MCP_IT_Sync_Steps waits for stdout before it reads stderr
 * and has no limit; it is left as it is, GRENZEN-PRUEFUMGEBUNG.md). The
 * group is made by a small launcher (PHP with posix and pcntl: setpgid(0, 0),
 * then exec of the command), so the child is the leader and its PID the
 * group's id. However the child ends, at its own end, at the time limit or
 * by the SIGKILL it sends itself, the whole group is ended by that id
 * (posix_kill( -pgid, SIGKILL )): whatever the child started and left
 * behind (a shell's `sleep 20 &`) goes with it. No pkill, no pattern; a
 * group id is only ever one this helper made. A process that leaves the
 * group (a new session or group of its own) is out of reach
 * (GRENZEN-PRUEFUMGEBUNG.md, G6).
 *
 * Each run has a folder of its own under the temporary directory, named to
 * the child in AB_IT_TMP; the child puts what it builds there (the ZIPs of
 * AB_MCP_IT_Code) and the parent removes the folder after the child, also
 * after an abort without shutdown (B7).
 *
 * calls() runs a plan of AB_MCP_IT_Child and counts it only when the child
 * printed its result line, which it writes only after every shutdown
 * function (A2), and ended with status 0: an exit anywhere, also in a
 * shutdown function, a wp_die(), a fatal error or a signal is never a pass.
 *
 * abort() runs a plan with an abort and counts it only with the marker of
 * that point in that mode and phase and the proof of the mode
 * (AB_MCP_Test_Abort): a run that ends anywhere else, runs to its end, or
 * reaches the point in another phase (in a shutdown rescue where the plan
 * did not declare it, A3) is an error.
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

	/** The launcher: a process group of its own, then the command. */
	const LAUNCHER = <<<'PHP'
if ( ! function_exists( 'posix_setpgid' ) || ! function_exists( 'pcntl_exec' ) ) {
	fwrite( STDERR, "launch: the helper needs the extensions posix and pcntl.\n" );
	exit( 126 );
}
if ( ! posix_setpgid( 0, 0 ) ) {
	fwrite( STDERR, "launch: no process group of its own.\n" );
	exit( 126 );
}
$ab_it_cmd = (string) $argv[1];
if ( false === strpos( $ab_it_cmd, '/' ) ) {
	foreach ( explode( ':', (string) getenv( 'PATH' ) ) as $ab_it_dir ) {
		if ( '' !== $ab_it_dir && is_file( $ab_it_dir . '/' . $ab_it_cmd ) && is_executable( $ab_it_dir . '/' . $ab_it_cmd ) ) {
			$ab_it_cmd = $ab_it_dir . '/' . $ab_it_cmd;
			break;
		}
	}
}
pcntl_exec( $ab_it_cmd, array_slice( $argv, 2 ) );
fwrite( STDERR, "launch: cannot run {$argv[1]}.\n" );
exit( 127 );
PHP;

	/**
	 * Run a command in a process group of its own.
	 *
	 * @param string[]      $command Command (no shell).
	 * @param string        $stdin   Bytes for its stdin.
	 * @param int|float     $timeout Seconds; then the group is killed.
	 * @param string|null   $cwd     Directory.
	 * @param string[]|null $env     Environment (null: this one).
	 * @param string|null   $tmp     Folder for AB_IT_TMP that the caller
	 *                               made and removes; null: one of this run,
	 *                               removed at its end.
	 * @return array{exit:int|null,signal:int|null,timed_out:bool,stdout:string,stderr:string,seconds:float,pid:int,tmp:string}
	 */
	public static function run( array $command, $stdin = '', $timeout = self::TIMEOUT, $cwd = null, $env = null, $tmp = null ) {
		if ( ! function_exists( 'posix_kill' ) ) {
			throw new RuntimeException( 'The helper of child processes needs the posix extension.' );
		}
		$own = null === $tmp;
		$tmp = $own ? self::temp_dir() : (string) $tmp;
		$env = array_merge( null === $env ? getenv() : $env, array( 'AB_IT_TMP' => $tmp ) );
		try {
			return self::run_in_group( $command, (string) $stdin, $timeout, $cwd, $env ) + array( 'tmp' => $tmp );
		} finally {
			if ( $own ) {
				self::remove_tree( $tmp );
			}
		}
	}

	/**
	 * run() without the folder.
	 *
	 * @param string[]  $command Command.
	 * @param string    $stdin   Bytes.
	 * @param int|float $timeout Seconds.
	 * @param string    $cwd     Directory or null.
	 * @param array     $env     Environment.
	 * @return array
	 */
	private static function run_in_group( array $command, $stdin, $timeout, $cwd, array $env ) {
		$started = microtime( true );
		$process = proc_open( array_merge( array( PHP_BINARY, '-r', self::LAUNCHER, '--' ), $command ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $cwd, $env );
		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( 'Could not start ' . implode( ' ', $command ) . '.' );
		}
		// The launcher becomes the leader of its group and then the
		// command: its PID is the id of the group.
		$pid = (int) proc_get_status( $process )['pid'];
		foreach ( $pipes as $pipe ) {
			stream_set_blocking( $pipe, false );
		}
		$written    = 0;
		$open       = array(
			1 => true,
			2 => true,
		);
		$buffers    = array(
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
				self::kill_group( $pid );
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
		// The child has ended (by itself, by its own signal or at the
		// limit): what it left in its group goes now, before the pipes are
		// read to their end, so nothing it started keeps them open.
		self::kill_group( $pid );
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
	 * End every process of the group this helper made, by its id. A group
	 * that no longer exists is left alone (posix_kill( -id, 0 ) fails).
	 *
	 * @param int $pgid Id of the group (the PID of its leader, the child).
	 */
	private static function kill_group( $pgid ) {
		if ( $pgid > 1 && @posix_kill( -$pgid, 0 ) ) {
			@posix_kill( -$pgid, 9 );
		}
	}

	/**
	 * A new folder of this process under the temporary directory.
	 *
	 * @return string
	 */
	public static function temp_dir() {
		$dir = sys_get_temp_dir() . '/ab-it-run-' . getmypid() . '-' . bin2hex( random_bytes( 6 ) );
		if ( ! mkdir( $dir, 0700 ) ) {
			throw new RuntimeException( "Cannot create $dir." );
		}
		return $dir;
	}

	/**
	 * Remove a folder with what is in it; a link is removed, never followed.
	 *
	 * @param string $path Path.
	 */
	public static function remove_tree( $path ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( (array) scandir( $path ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				self::remove_tree( $path . '/' . $name );
			}
		}
		rmdir( $path );
	}

	/**
	 * Read what the pipes hold now.
	 *
	 * @param resource[]        $pipes   Pipes.
	 * @param array<int,bool>   $open    Open stdout/stderr.
	 * @param array<int,string> $buffers What was read.
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
	 * Run a plan that must run to its end, its shutdown included.
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
			throw new RuntimeException( self::describe( 'The child did not run its plan to the end of its shutdown (no result line, or not status 0): an exit, also in a shutdown function, a wp_die(), a fatal error or a signal is no pass', $run ) );
		}
		return array(
			'results' => $result['results'],
			'seams'   => $result['seams'],
			'run'     => $run,
		);
	}

	/**
	 * Run a plan with one abort, and require it at its point in its mode
	 * and phase.
	 *
	 * @param string|null $site    Site, or null for plain PHP.
	 * @param array       $plan    Plan with exactly one abort: a fault of
	 *                             kind abort in a seam, or one hook; its
	 *                             'phase' (request, the default, or
	 *                             shutdown) is where the point must come.
	 * @param int         $timeout Seconds.
	 * @return array{point:string,mode:string,phase:string,marker:array,run:array}
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
		$phase = isset( $aborts[0]['phase'] ) ? (string) $aborts[0]['phase'] : 'request';
		AB_MCP_Test_Abort::check_mode( $mode );
		AB_MCP_Test_Abort::check_phase( $mode, $phase );
		$tmp            = self::temp_dir();
		$marker         = $tmp . '/abort-marker.json';
		$plan['marker'] = $marker;
		try {
			$run    = self::run( self::child_command( $site ), (string) json_encode( $plan ), $timeout, null, null, $tmp );
			$record = AB_MCP_Test_Abort::read( $marker );
		} finally {
			self::remove_tree( $tmp );
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
		if ( ! isset( $record['phase'] ) || $phase !== $record['phase'] ) {
			throw new RuntimeException( self::describe( "The point '$point' came in the phase " . var_export( isset( $record['phase'] ) ? $record['phase'] : null, true ) . ", not in the phase $phase the plan named (a point reached in a shutdown rescue is not the abort of the request)", $run ) );
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
			'phase'  => $phase,
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
