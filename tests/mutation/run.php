<?php
/**
 * Mutation probes, clause by clause (knowledge/conventions.md, «Gegenprobe je
 * Klausel»): each entry takes ONE clause out of the shipped code and expects
 * the tests that hold it to fail.
 *
 *   php tests/mutation/run.php [<list.php> ...] [--only=<id>] [--show]
 *
 * --show prints the end of every red run as well, to see that a mutation
 * was killed for the reason the list names.
 *
 * Without a list, every list in tests/mutation/lists/ runs. A list is a PHP
 * file that returns entries:
 *
 *   array(
 *       'id'      => 'option-guard/refusal-length',     // unique, for --only and the report
 *       'clause'  => 'refusal(): a name longer than the column is refused',
 *       'file'    => 'includes/class-option-guard.php',  // shipped file, from the repo root
 *       'search'  => "if ( self::too_long_for_column( \$name ) ) {",  // must occur exactly once
 *       'replace' => "if ( false && self::too_long_for_column( \$name ) ) {",
 *       'run'     => array( 'vendor/bin/phpunit', '--no-progress', '--filter', 'OptionGuardStageTwoTest' ),
 *       'expect'  => '/testTheSecondQuestionAsksTheLength/', // optional: must appear in the
 *                    // output of the red run, so that the failure is the test
 *                    // meant and not any failure
 *       'timeout' => 600,                                 // optional, seconds
 *   )
 *
 * 'run' is any command, started from the repo root with this environment:
 * the unit suite, or the integration suite (vendor/bin/phpunit -c
 * tests/integration/phpunit.xml.dist, the sites running and AB_IT_ROOT set;
 * their servers read the changed file at once, OPcache is off there).
 *
 * Per entry: the text must occur exactly once (else the list is out of
 * date); the command must pass unmutated, once per distinct command (a red
 * baseline proves nothing); the file is kept byte for byte beside itself as
 * <file>.rein, opened exclusively (an existing one means an earlier run did
 * not finish: nothing runs until a person has looked); the mutation is
 * written and must still parse (php -l: a syntax error would fail every
 * test and prove nothing); the command must now FAIL (and print 'expect');
 * then the kept bytes are written back and compared with what was read
 * first — equal, or the run stops at once and the .rein copy stays. Also on
 * Ctrl-C, SIGTERM or a fatal error of this runner the file is written back.
 *
 * Exit: 0 every mutation killed and every file back; 1 a mutation survived
 * (a clause no test holds); 2 the list or the baseline is wrong (no verdict);
 * 3 a file did not come back byte for byte. Never commit while it runs.
 *
 * @package AlphaBridge_MCP
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 2 );
}

final class AB_Mutation_Run {

	/** @var string Repo root. */
	private $root;

	/** @var bool Print the end of every red run (--show). */
	public $show = false;

	/** @var array<string,array{file:string,original:string}> Files changed right now, by path. */
	private static $pending = array();

	/** @var resource|null The command running right now. */
	private static $child = null;

	/**
	 * @param string $root Repo root.
	 */
	public function __construct( $root ) {
		$this->root = $root;
	}

	/**
	 * Write every pending file back (shutdown, signal). A file that does not
	 * come back keeps its .rein copy.
	 *
	 * @return bool All back.
	 */
	public static function restore_pending() {
		// The tests stop first: they must not read the file while it is
		// written back, nor go on after the runner.
		if ( is_resource( self::$child ) ) {
			proc_terminate( self::$child );
			self::$child = null;
		}
		$ok = true;
		foreach ( self::$pending as $path => $keep ) {
			$ok = self::restore( $path, $keep['original'] ) && $ok;
		}
		return $ok;
	}

	/**
	 * Write the kept bytes back and compare.
	 *
	 * @param string $path     File.
	 * @param string $original Bytes read before the mutation.
	 * @return bool Byte for byte back (the .rein copy is then removed).
	 */
	private static function restore( $path, $original ) {
		$rein = $path . '.rein';
		$kept = @file_get_contents( $rein );
		if ( false === $kept || $kept !== $original ) {
			fwrite( STDERR, "mutation: the kept copy $rein is missing or differs; $path left as it is.\n" );
			return false;
		}
		if ( false === file_put_contents( $path, $kept ) ) {
			fwrite( STDERR, "mutation: could not write $path back from $rein.\n" );
			return false;
		}
		clearstatcache( true, $path );
		$back = file_get_contents( $path );
		if ( $back !== $original || hash( 'sha256', (string) $back ) !== hash( 'sha256', $original ) ) {
			fwrite( STDERR, "mutation: $path is not byte for byte what it was; the copy stays at $rein.\n" );
			return false;
		}
		unset( self::$pending[ $path ] );
		unlink( $rein );
		return true;
	}

	/**
	 * Run a command from the repo root.
	 *
	 * @param string[] $command Command.
	 * @param int      $timeout Seconds.
	 * @return array{0:int|null,1:string,2:float} Exit code (null on timeout), output, seconds.
	 */
	private function exec( array $command, $timeout ) {
		$started = microtime( true );
		$log     = tempnam( sys_get_temp_dir(), 'ab-mutation-' );
		$process = proc_open( $command, array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', $log, 'w' ), 2 => array( 'redirect', 1 ) ), $pipes, $this->root );
		if ( ! is_resource( $process ) ) {
			return array( null, 'could not start: ' . implode( ' ', $command ), 0.0 );
		}
		self::$child = $process;
		$code        = null;
		while ( true ) {
			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				$code = (int) $status['exitcode'];
				break;
			}
			if ( microtime( true ) - $started > $timeout ) {
				proc_terminate( $process );
				break;
			}
			usleep( 50000 );
		}
		self::$child = null;
		proc_close( $process );
		$output = (string) file_get_contents( $log );
		unlink( $log );
		return array( $code, $output, microtime( true ) - $started );
	}

	/**
	 * The entries of the lists, checked.
	 *
	 * @param string[] $lists Files.
	 * @return array<int,array>
	 */
	public function entries( array $lists ) {
		$entries = array();
		$ids     = array();
		foreach ( $lists as $list ) {
			$found = require $list;
			if ( ! is_array( $found ) ) {
				throw new RuntimeException( "$list returns no list." );
			}
			foreach ( $found as $entry ) {
				foreach ( array( 'id', 'clause', 'file', 'search', 'replace' ) as $field ) {
					if ( ! isset( $entry[ $field ] ) || ! is_string( $entry[ $field ] ) || '' === $entry[ $field ] ) {
						throw new RuntimeException( "$list: an entry has no '$field'." );
					}
				}
				if ( empty( $entry['run'] ) || ! is_array( $entry['run'] ) ) {
					throw new RuntimeException( "$list: {$entry['id']} has no 'run' command." );
				}
				if ( isset( $ids[ $entry['id'] ] ) ) {
					throw new RuntimeException( "$list: the id {$entry['id']} is taken." );
				}
				if ( $entry['search'] === $entry['replace'] ) {
					throw new RuntimeException( "$list: {$entry['id']} changes nothing." );
				}
				$path = realpath( $this->root . '/' . $entry['file'] );
				if ( false === $path || 0 !== strpos( $path, $this->root . '/' ) || preg_match( '#^(tests|vendor)/#', substr( $path, strlen( $this->root ) + 1 ) ) ) {
					throw new RuntimeException( "$list: {$entry['id']} names no shipped file of this tree ({$entry['file']})." );
				}
				$entry['path']    = $path;
				$entry['timeout'] = isset( $entry['timeout'] ) ? (int) $entry['timeout'] : 600;
				$ids[ $entry['id'] ] = true;
				$entries[]           = $entry;
			}
		}
		return $entries;
	}

	/**
	 * Run the entries.
	 *
	 * @param array[] $entries Entries.
	 * @return int Exit code.
	 */
	public function run( array $entries ) {
		foreach ( $entries as $entry ) {
			if ( file_exists( $entry['path'] . '.rein' ) ) {
				fwrite( STDERR, "mutation: {$entry['path']}.rein exists — an earlier run did not finish. Compare it with the file, put the right bytes back, remove it; then run again.\n" );
				return 2;
			}
		}

		$baseline = array();
		foreach ( $entries as $entry ) {
			$key = json_encode( $entry['run'] );
			if ( isset( $baseline[ $key ] ) ) {
				continue;
			}
			list( $code, $output, $took ) = $this->exec( $entry['run'], $entry['timeout'] );
			if ( 0 !== $code ) {
				fwrite( STDERR, "mutation: the baseline of {$entry['id']} is not green (exit " . var_export( $code, true ) . "); a red run before the mutation proves nothing.\n" . self::tail( $output ) );
				return 2;
			}
			printf( "baseline  green  %5.1f s  %s\n", $took, implode( ' ', $entry['run'] ) );
			$baseline[ $key ] = true;
		}

		$survived = array();
		$broken   = false;
		foreach ( $entries as $entry ) {
			$path     = $entry['path'];
			$original = file_get_contents( $path );
			if ( false === $original ) {
				fwrite( STDERR, "mutation: cannot read $path.\n" );
				return 2;
			}
			$count = substr_count( $original, $entry['search'] );
			if ( 1 !== $count ) {
				fwrite( STDERR, "mutation: {$entry['id']}: the text occurs $count times in {$entry['file']} (once expected); the list is out of date.\n" );
				$broken = true;
				continue;
			}
			$keep = @fopen( $path . '.rein', 'xb' );
			if ( false === $keep || strlen( $original ) !== fwrite( $keep, $original ) || ! fclose( $keep ) || file_get_contents( $path . '.rein' ) !== $original ) {
				fwrite( STDERR, "mutation: could not keep $path as $path.rein.\n" );
				return 3;
			}
			self::$pending[ $path ] = array(
				'file'     => $entry['file'],
				'original' => $original,
			);
			$at      = strpos( $original, $entry['search'] );
			$mutated = substr_replace( $original, $entry['replace'], $at, strlen( $entry['search'] ) );
			file_put_contents( $path, $mutated );
			clearstatcache( true, $path );

			list( $lint_code, $lint ) = $this->exec( array( PHP_BINARY, '-l', $path ), 60 );
			if ( 0 !== $lint_code ) {
				$verdict = 'INVALID';
				$detail  = 'the mutation does not parse: ' . trim( $lint );
				$took    = 0.0;
			} else {
				list( $code, $output, $took ) = $this->exec( $entry['run'], $entry['timeout'] );
				if ( null === $code ) {
					$verdict = 'TIMEOUT';
					$detail  = 'no verdict within ' . $entry['timeout'] . ' s';
				} elseif ( 0 === $code ) {
					$verdict = 'SURVIVED';
					$detail  = 'the tests pass without the clause';
				} elseif ( isset( $entry['expect'] ) && ! preg_match( $entry['expect'], $output ) ) {
					$verdict = 'OTHER';
					$detail  = 'red, but not with ' . $entry['expect'];
				} else {
					$verdict = 'killed';
					$detail  = 'exit ' . $code;
				}
			}

			if ( ! self::restore( $path, $original ) ) {
				fwrite( STDERR, "mutation: stopped — $path did not come back.\n" );
				return 3;
			}
			printf( "%-8s  %5.1f s  %s — %s (%s)\n", $verdict, $took, $entry['id'], $entry['clause'], $detail );
			if ( 'SURVIVED' === $verdict ) {
				$survived[] = $entry['id'];
			} elseif ( 'killed' !== $verdict ) {
				$broken = true;
			}
			if ( isset( $output ) && ( 'OTHER' === $verdict || 'TIMEOUT' === $verdict || ( 'killed' === $verdict && $this->show ) ) ) {
				echo self::tail( $output );
			}
			unset( $output );
		}

		$total = count( $entries );
		if ( array() !== $survived ) {
			printf( "%d of %d mutations survived: %s\n", count( $survived ), $total, implode( ', ', $survived ) );
			return 1;
		}
		if ( $broken ) {
			echo "Some entries gave no verdict; the list needs a look.\n";
			return 2;
		}
		printf( "all %d mutations killed; every file is back byte for byte\n", $total );
		return 0;
	}

	/**
	 * The last lines of an output.
	 *
	 * @param string $output Output.
	 * @return string
	 */
	private static function tail( $output ) {
		$lines = explode( "\n", rtrim( $output ) );
		return '    ' . implode( "\n    ", array_slice( $lines, -25 ) ) . "\n";
	}
}

$ab_root  = realpath( dirname( __DIR__, 2 ) );
$ab_lists = array();
$ab_only  = null;
$ab_show  = false;
foreach ( array_slice( $argv, 1 ) as $ab_arg ) {
	if ( 0 === strpos( $ab_arg, '--only=' ) ) {
		$ab_only = substr( $ab_arg, 7 );
	} elseif ( '--show' === $ab_arg ) {
		$ab_show = true;
	} else {
		$ab_lists[] = $ab_arg;
	}
}
if ( array() === $ab_lists ) {
	$ab_lists = glob( __DIR__ . '/lists/*.php' );
	sort( $ab_lists );
}

// Without signal handling a SIGTERM would leave a mutated file behind (the
// shutdown function does not run then), so no mutation starts without it.
if ( ! function_exists( 'pcntl_async_signals' ) || ! function_exists( 'pcntl_signal' ) ) {
	fwrite( STDERR, "mutation: needs the pcntl extension to write files back on SIGINT/SIGTERM; nothing changed.\n" );
	exit( 2 );
}
register_shutdown_function( array( 'AB_Mutation_Run', 'restore_pending' ) );
pcntl_async_signals( true );
foreach ( array( SIGINT, SIGTERM, SIGHUP ) as $ab_signal ) {
	pcntl_signal(
		$ab_signal,
		static function () {
			fwrite( STDERR, "mutation: interrupted; writing the files back.\n" );
			exit( AB_Mutation_Run::restore_pending() ? 130 : 3 );
		}
	);
}

$ab_run       = new AB_Mutation_Run( $ab_root );
$ab_run->show = $ab_show;
try {
	$ab_entries = $ab_run->entries( $ab_lists );
} catch ( RuntimeException $e ) {
	fwrite( STDERR, 'mutation: ' . $e->getMessage() . "\n" );
	exit( 2 );
}
if ( null !== $ab_only ) {
	$ab_entries = array_values( array_filter( $ab_entries, static fn( $e ) => $e['id'] === $ab_only ) );
}
if ( array() === $ab_entries ) {
	fwrite( STDERR, "mutation: no entry to run.\n" );
	exit( 2 );
}
exit( $ab_run->run( $ab_entries ) );
