<?php
/**
 * A hard abort at a named point, in a process of its own (Z8b branch 1,
 * point 4; plan E6, E21, F25–F33). Test code only: the shipped code knows
 * nothing of it (ShippedCodeKnowsNoTestFetcherTest scans for AB_MCP_Test_).
 *
 * Three modes, each with its own proof in the marker file:
 * - shutdown:    exit as a request that ends there; the shutdown functions
 *                run (the rescues of the plugin take hold). Proof: the
 *                marker says fired, and a shutdown function registered at
 *                the abort (so after every earlier one) adds shutdown_ran.
 * - no_shutdown: the process ends at once by SIGKILL to its own PID: no
 *                shutdown function, no destructor, no flush, so the states
 *                of a swap stay as they are (E6, «harter Abbruch ohne
 *                Shutdown»). Proof: fired, no shutdown_ran, and the parent
 *                sees the signal 9 (AB_MCP_IT_Process).
 * - timeout:     the time limit of the request is set to a few seconds and
 *                spent in a loop, so PHP stops with its fatal error
 *                «Maximum execution time … exceeded» (E_ERROR, a real fatal)
 *                and runs the shutdown functions (F32). Proof: fired,
 *                shutdown_ran, and the last error E_ERROR with that text.
 *                The limit counts CPU time on Linux and macOS (PHP builds
 *                without ZTS), so the loop works, sleeping would not.
 *
 * The marker file is written before the abort, so that a parent can tell
 * the abort at this point from any other end of the process (a wp_die(), an
 * exit elsewhere, a fatal error): without the marker the run is an error
 * (V2 of the pre-check; exit_at of bin/sync-site.php has no such proof,
 * GRENZEN-PRUEFUMGEBUNG.md). The marker also names the phase the point was
 * reached in (Codex round 1, A3): «request» while the plan runs, «shutdown»
 * inside a shutdown function (read from the call stack: the outermost frame
 * of a shutdown function is called by PHP itself and has no file). A point
 * meant inside a shutdown rescue is declared so ('phase' => 'shutdown') and
 * then takes the mode no_shutdown only: an exit there would end the other
 * shutdown functions, the time limit would come after them.
 *
 * fire() works only after arm(), which only the child runners call
 * (AB_MCP_IT_Child): in the PHPUnit process an abort would end the whole
 * suite with status 0.
 *
 * @package AlphaBridge_MCP
 */

/**
 * Class AB_MCP_Test_Abort
 */
final class AB_MCP_Test_Abort {

	/** The modes. */
	const MODES = array( 'shutdown', 'no_shutdown', 'timeout' );

	/** Default seconds of the time limit in the mode timeout. */
	const SECONDS = 1;

	/** The phases a point can be reached in. */
	const PHASES = array( 'request', 'shutdown' );

	/** @var string|null Path of the marker file; null until arm(). */
	private static $marker = null;

	/**
	 * Allow aborts in this process and name the marker file. Called by the
	 * child runners only.
	 *
	 * @param string $marker Path of the marker file (must not exist yet).
	 */
	public static function arm( $marker ) {
		$marker = (string) $marker;
		if ( '' === $marker || file_exists( $marker ) ) {
			throw new LogicException( "The marker file of an abort must be a new path: $marker." );
		}
		self::$marker = $marker;
	}

	/**
	 * Whether this process may abort.
	 *
	 * @return bool
	 */
	public static function armed() {
		return null !== self::$marker;
	}

	/**
	 * Check a mode, and that this process can carry it out.
	 *
	 * @param string $mode Mode.
	 */
	public static function check_mode( $mode ) {
		if ( ! in_array( $mode, self::MODES, true ) ) {
			throw new InvalidArgumentException( "No abort mode '$mode': the modes are " . implode( ', ', self::MODES ) . '.' );
		}
		if ( 'no_shutdown' === $mode && ! function_exists( 'posix_kill' ) ) {
			throw new RuntimeException( 'The abort without shutdown needs the posix extension (posix_kill).' );
		}
	}

	/**
	 * Check the phase declared for a point, with its mode.
	 *
	 * @param string $mode  Mode.
	 * @param string $phase Phase.
	 */
	public static function check_phase( $mode, $phase ) {
		if ( ! in_array( $phase, self::PHASES, true ) ) {
			throw new InvalidArgumentException( "No phase '$phase': a point is reached in the request or in the shutdown." );
		}
		if ( 'shutdown' === $phase && 'no_shutdown' !== $mode ) {
			throw new InvalidArgumentException( "An abort in the shutdown phase takes the mode no_shutdown only, not $mode." );
		}
	}

	/**
	 * The phase this process is in: «shutdown» when the outermost frame of
	 * the call stack has no file (PHP itself called it, as it calls a
	 * shutdown function), else «request».
	 *
	 * @return string
	 */
	public static function phase() {
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
		$outer  = end( $frames );
		return is_array( $outer ) && isset( $outer['file'] ) ? 'request' : 'shutdown';
	}

	/**
	 * Abort here. Never returns.
	 *
	 * @param string $point   Name of the point (as the test named it).
	 * @param string $mode    One of MODES.
	 * @param int    $seconds Time limit of the mode timeout.
	 */
	public static function fire( $point, $mode, $seconds = self::SECONDS ) {
		if ( null === self::$marker ) {
			throw new LogicException( "Abort at '$point' refused: a hard abort runs only in a process of its own (AB_MCP_IT_Child), never in the test process." );
		}
		self::check_mode( $mode );
		$record = array(
			'fired'    => true,
			'point'    => (string) $point,
			'mode'     => (string) $mode,
			'phase'    => self::phase(),
			'pid'      => getmypid(),
			'fired_at' => microtime( true ),
		);
		self::write( $record );
		if ( 'no_shutdown' === $mode ) {
			// 9 is SIGKILL (the constant comes with pcntl, which a site need
			// not have). A signal a process sends itself is delivered before
			// posix_kill() returns.
			posix_kill( getmypid(), 9 );
			exit( 95 ); // Not reached; were it, the parent would see no signal.
		}
		register_shutdown_function(
			static function () use ( $record ) {
				$record['shutdown_ran'] = true;
				$record['error']        = error_get_last();
				// PHP 8.5 gives a fatal error its backtrace with the arguments
				// of each frame (fatal_error_backtraces); one that is no UTF-8
				// (the gzip bytes of a payload, Z8b branch 3) or a resource
				// left json_encode() without a result and the marker empty.
				// The parent reads type and message only.
				if ( is_array( $record['error'] ) ) {
					unset( $record['error']['trace'] );
				}
				self::write( $record );
			}
		);
		if ( 'shutdown' === $mode ) {
			exit( 0 );
		}
		set_time_limit( max( 1, (int) $seconds ) );
		$spin = (string) $point;
		while ( true ) {
			$spin = hash( 'sha256', $spin );
		}
	}

	/**
	 * Write the marker, whole (a reader never sees half of it).
	 *
	 * @param array $record Record.
	 */
	private static function write( array $record ) {
		$tmp = self::$marker . '.' . getmypid() . '.tmp';
		if ( false === file_put_contents( $tmp, (string) json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ) ) || ! rename( $tmp, self::$marker ) ) {
			fwrite( STDERR, 'abort: the marker ' . self::$marker . " could not be written.\n" );
		}
	}

	/**
	 * Abort at the nth run of a hook (WordPress: action or filter), before
	 * every other callback of it unless a priority is given. The callback
	 * hands a filter's value through until then.
	 *
	 * @param string $hook     Hook.
	 * @param string $point    Name of the point.
	 * @param string $mode     Mode.
	 * @param int    $nth      Which run (1 = the first).
	 * @param int    $priority Priority.
	 * @param int    $seconds  Time limit of the mode timeout.
	 */
	public static function at_hook( $hook, $point, $mode, $nth = 1, $priority = PHP_INT_MIN, $seconds = self::SECONDS ) {
		self::check_mode( $mode );
		$count = 0;
		add_filter(
			(string) $hook,
			static function ( $value = null ) use ( &$count, $nth, $point, $mode, $seconds ) {
				if ( ++$count === (int) $nth ) {
					self::fire( $point, $mode, $seconds );
				}
				return $value;
			},
			(int) $priority,
			1
		);
	}

	/**
	 * Read a marker file.
	 *
	 * @param string $marker Path.
	 * @return array|null The record, or null when there is none.
	 */
	public static function read( $marker ) {
		clearstatcache( true, $marker );
		if ( ! is_file( $marker ) ) {
			return null;
		}
		$record = json_decode( (string) file_get_contents( $marker ), true );
		return is_array( $record ) ? $record : null;
	}
}
