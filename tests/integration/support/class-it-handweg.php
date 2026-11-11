<?php
/**
 * The shell run of a manual way back (Handweg; Z8b branch 1, point 4; plan
 * E6, F25–F33): the commands an instruction file names for one abort point
 * are run in a shell, and the test checks what they left.
 *
 * The rule for finding the commands is in HANDWEG.md beside this file (F-B
 * of the pre-check; branch 3 builds WIEDERHERSTELLEN.txt and takes the rule
 * or replaces it). In short: a section per abort point, opened by a line
 * [<point>]; its command lines begin with "$ "; run with bash -euo pipefail
 * in a given folder within a time limit; the answer is status and output.
 *
 * @package AlphaBridge_MCP
 */

require_once __DIR__ . '/class-it-process.php';

/**
 * Class AB_MCP_IT_Handweg
 */
final class AB_MCP_IT_Handweg {

	/** Default seconds for the commands of one point. */
	const TIMEOUT = 60;

	/** A line that opens the section of a point. */
	const SECTION = '/^\[([a-z0-9][a-z0-9-]*)\]\s*$/';

	/** A command line of a section. */
	const COMMAND = '/^\$ (.+)$/';

	/**
	 * The commands of one abort point.
	 *
	 * @param string $text  The instruction (the whole file).
	 * @param string $point Name of the point.
	 * @return string[]
	 */
	public static function commands( $text, $point ) {
		$sections = array();
		$current  = null;
		foreach ( preg_split( '/\r\n|\n|\r/', (string) $text ) as $number => $line ) {
			if ( preg_match( self::SECTION, $line, $m ) ) {
				if ( isset( $sections[ $m[1] ] ) ) {
					throw new RuntimeException( "The instruction has the section [{$m[1]}] twice (line " . ( $number + 1 ) . ').' );
				}
				$current              = $m[1];
				$sections[ $current ] = array();
				continue;
			}
			if ( null !== $current && preg_match( self::COMMAND, $line, $m ) ) {
				$sections[ $current ][] = $m[1];
			}
		}
		if ( ! isset( $sections[ $point ] ) ) {
			throw new RuntimeException( "The instruction has no section [$point]; it has: " . implode( ', ', array_keys( $sections ) ) . '.' );
		}
		if ( array() === $sections[ $point ] ) {
			throw new RuntimeException( "The section [$point] of the instruction has no command line (\"\$ …\")." );
		}
		return $sections[ $point ];
	}

	/**
	 * Run commands with bash -euo pipefail.
	 *
	 * @param string[]      $commands Commands, one per line.
	 * @param string        $dir      Folder they run in.
	 * @param int           $timeout  Seconds.
	 * @param string[]|null $env      Environment (null: this one).
	 * @return array{status:int|null,signal:int|null,timed_out:bool,output:string,stderr:string,commands:string[]}
	 */
	public static function run( array $commands, $dir, $timeout = self::TIMEOUT, $env = null ) {
		if ( ! is_dir( $dir ) ) {
			throw new RuntimeException( "No folder $dir to run the way back in." );
		}
		$run = AB_MCP_IT_Process::run( array( 'bash', '-euo', 'pipefail', '-c', implode( "\n", $commands ) ), '', $timeout, $dir, $env );
		return array(
			'status'    => $run['exit'],
			'signal'    => $run['signal'],
			'timed_out' => $run['timed_out'],
			'output'    => $run['stdout'],
			'stderr'    => $run['stderr'],
			'commands'  => $commands,
		);
	}

	/**
	 * The commands of a point from an instruction file, run.
	 *
	 * @param string $file    Instruction file.
	 * @param string $point   Point.
	 * @param string $dir     Folder.
	 * @param int    $timeout Seconds.
	 * @return array See run().
	 */
	public static function run_point( $file, $point, $dir, $timeout = self::TIMEOUT ) {
		if ( ! is_file( $file ) ) {
			throw new RuntimeException( "No instruction at $file." );
		}
		return self::run( self::commands( (string) file_get_contents( $file ), $point ), $dir, $timeout );
	}
}
