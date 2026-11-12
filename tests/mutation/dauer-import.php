<?php
/**
 * Writes tests/mutation/dauer.php, the measured durations for
 * tests/mutation/run.php --shard, from the log of a green CI run:
 *
 *   php tests/mutation/dauer-import.php --run=<run id> [--attempt=<n>] [--out=<file>]
 *
 * Reads the run with gh (status, head commit, attempt; then its log), the
 * lists of that commit with git (git show), and checks the log against
 * them (AB_Mutation_Plan::import(): every shard there and ended, every entry
 * measured once, nothing unknown). A pull request runs the merge of its head
 * into the base; that counts only where the base is an ancestor of the head
 * (then the merge is the head's tree). On any doubt nothing is written and
 * the status is 1.
 *
 * Then it prints, for the number of shards of the run, the longest and the
 * shortest shard and the whole, the old way (every n-th entry, in the order
 * loaded) and the new.
 *
 * Development only: /tests ships with no release.
 *
 * @package AlphaBridge_MCP
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 2 );
}

require_once __DIR__ . '/verteilung.php';

/**
 * Run a command from the repo root; its output into a file.
 *
 * @param string[] $command Command.
 * @param string   $root    Repo root.
 * @return array{0:int,1:string} Status, output.
 */
function ab_dauer_exec( array $command, $root ) {
	$log     = tempnam( sys_get_temp_dir(), 'ab-dauer-' );
	$process = proc_open( $command, array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', $log, 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $root );
	if ( ! is_resource( $process ) ) {
		unlink( $log );
		return array( 127, '' );
	}
	$error = stream_get_contents( $pipes[2] );
	fclose( $pipes[2] );
	$code   = proc_close( $process );
	$output = (string) file_get_contents( $log );
	unlink( $log );
	if ( 0 !== $code ) {
		fwrite( STDERR, implode( ' ', $command ) . ": exit $code\n" . $error );
	}
	return array( $code, $output );
}

/**
 * Remove a folder this tool made.
 *
 * @param string $path Path.
 */
function ab_dauer_remove( $path ) {
	if ( is_link( $path ) || is_file( $path ) ) {
		unlink( $path );
		return;
	}
	if ( ! is_dir( $path ) ) {
		return;
	}
	foreach ( (array) scandir( $path ) as $name ) {
		if ( '.' !== $name && '..' !== $name ) {
			ab_dauer_remove( $path . '/' . $name );
		}
	}
	rmdir( $path );
}

/**
 * Minutes of tenths, for the report.
 *
 * @param int $tenths Tenths of a second.
 * @return string
 */
function ab_dauer_min( $tenths ) {
	return sprintf( '%.1f min', $tenths / 600 );
}

$ab_root    = (string) realpath( dirname( __DIR__, 2 ) );
$ab_run_id  = null;
$ab_attempt = null;
$ab_out     = $ab_root . '/tests/mutation/dauer.php';
foreach ( array_slice( $argv, 1 ) as $ab_arg ) {
	if ( preg_match( '/^--run=([1-9][0-9]*)$/D', $ab_arg, $ab_m ) ) {
		$ab_run_id = $ab_m[1];
	} elseif ( preg_match( '/^--attempt=([1-9][0-9]*)$/D', $ab_arg, $ab_m ) ) {
		$ab_attempt = (int) $ab_m[1];
	} elseif ( 0 === strpos( $ab_arg, '--out=' ) && '' !== substr( $ab_arg, 6 ) ) {
		$ab_out = substr( $ab_arg, 6 );
	} else {
		fwrite( STDERR, "usage: php tests/mutation/dauer-import.php --run=<run id> [--attempt=<n>] [--out=<file>]\n" );
		exit( 2 );
	}
}
if ( null === $ab_run_id ) {
	fwrite( STDERR, "dauer-import: --run=<run id> is wanted.\n" );
	exit( 2 );
}

$ab_view = array( 'gh', 'run', 'view', $ab_run_id );
if ( null !== $ab_attempt ) {
	$ab_view[] = '--attempt';
	$ab_view[] = (string) $ab_attempt;
}
list( $ab_code, $ab_json ) = ab_dauer_exec( array_merge( $ab_view, array( '--json', 'status,conclusion,headSha,attempt,event' ) ), $ab_root );
$ab_info                   = json_decode( $ab_json, true );
if ( 0 !== $ab_code || ! is_array( $ab_info ) ) {
	fwrite( STDERR, "dauer-import: cannot read run $ab_run_id with gh.\n" );
	exit( 1 );
}
if ( 'completed' !== ( $ab_info['status'] ?? '' ) || 'success' !== ( $ab_info['conclusion'] ?? '' ) ) {
	fwrite( STDERR, "dauer-import: run $ab_run_id is not green ({$ab_info['status']}, {$ab_info['conclusion']}); only a green run is measured.\n" );
	exit( 1 );
}
$ab_source  = (string) ( $ab_info['headSha'] ?? '' );
$ab_attempt = (int) ( $ab_info['attempt'] ?? 0 );
if ( ! preg_match( '/^[0-9a-f]{40}$/D', $ab_source ) || $ab_attempt < 1 ) {
	fwrite( STDERR, "dauer-import: run $ab_run_id names no head commit or attempt.\n" );
	exit( 1 );
}

list( $ab_code, $ab_log ) = ab_dauer_exec( array_merge( $ab_view, array( '--log' ) ), $ab_root );
if ( 0 !== $ab_code || '' === $ab_log ) {
	fwrite( STDERR, "dauer-import: cannot read the log of run $ab_run_id with gh.\n" );
	exit( 1 );
}

// The lists of the source commit, out of git into a folder of its own
// (file by file: git archive leaves tests/ out, export-ignore).
$ab_tmp = sys_get_temp_dir() . '/ab-dauer-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $ab_tmp, 0700 );
list( $ab_code, $ab_names ) = ab_dauer_exec( array( 'git', 'ls-tree', '--name-only', $ab_source, 'tests/mutation/lists/' ), $ab_root );
$ab_lists                   = array();
foreach ( array_filter( explode( "\n", $ab_names ) ) as $ab_name ) {
	if ( 0 !== $ab_code || ! preg_match( '#^tests/mutation/lists/([^/]+\.php)$#D', $ab_name, $ab_m ) ) {
		continue;
	}
	list( $ab_code, $ab_bytes ) = ab_dauer_exec( array( 'git', 'show', $ab_source . ':' . $ab_name ), $ab_root );
	$ab_lists[]                 = $ab_tmp . '/' . $ab_m[1];
	file_put_contents( $ab_tmp . '/' . $ab_m[1], $ab_bytes );
}
sort( $ab_lists );

try {
	if ( 0 !== $ab_code || array() === $ab_lists ) {
		throw new RuntimeException( "the lists of $ab_source could not be read from this clone (git fetch?)" );
	}
	$ab_entries = AB_Mutation_Plan::list_entries( $ab_lists );
	$ab_found   = AB_Mutation_Plan::import( $ab_log, $ab_entries, $ab_source );
	foreach ( $ab_found['merge_bases'] as $ab_base ) {
		list( $ab_code ) = ab_dauer_exec( array( 'git', 'merge-base', '--is-ancestor', $ab_base, $ab_source ), $ab_root );
		if ( 0 !== $ab_code ) {
			throw new RuntimeException( "the run checked out a merge of $ab_source into $ab_base, which is not an ancestor of it: the lists of the run are not those of the source" );
		}
	}
} catch ( RuntimeException $e ) {
	ab_dauer_remove( $ab_tmp );
	fwrite( STDERR, 'dauer-import: ' . $e->getMessage() . "; nothing written.\n" );
	exit( 1 );
}
ab_dauer_remove( $ab_tmp );

$ab_measures = array(
	'herkunft'   => array(
		'quelle'      => $ab_source,
		'lauf'        => (int) $ab_run_id,
		'versuch'     => $ab_attempt,
		'ausgecheckt' => array() === $ab_found['merge_bases'] ? 'the source itself' : 'merge of the source into ' . implode( ', ', $ab_found['merge_bases'] ) . ' (an ancestor of it: the same tree)',
		'scheiben'    => $ab_found['shards'],
		'regel'       => AB_Mutation_Plan::RULE,
	) + AB_Mutation_Plan::inventory( $ab_found ),
	'mutation'   => $ab_found['mutation'],
	'grundlinie' => $ab_found['grundlinie'],
);
if ( false === file_put_contents( $ab_out, AB_Mutation_Plan::export( $ab_measures ) ) ) {
	fwrite( STDERR, "dauer-import: cannot write $ab_out.\n" );
	exit( 1 );
}
printf( "%s: %d entries, %d commands, from run %s attempt %d (%s)\n", $ab_out, count( $ab_found['mutation'] ), count( $ab_found['grundlinie'] ), $ab_run_id, $ab_attempt, $ab_source );

$ab_n = $ab_found['shards'];
foreach ( array(
	'every n-th' => AB_Mutation_Plan::round_robin( $ab_entries, $ab_n ),
	'measured'   => AB_Mutation_Plan::assign( $ab_entries, $ab_measures, $ab_n ),
) as $ab_way => $ab_shards ) {
	$ab_costs = AB_Mutation_Plan::costs( $ab_entries, $ab_shards, $ab_measures, $ab_n );
	$ab_each  = array_column( $ab_costs, 'cost' );
	printf(
		"%-10s  n=%d  longest %s  shortest %s  whole %s  baselines run %d\n",
		$ab_way,
		$ab_n,
		ab_dauer_min( max( $ab_each ) ),
		ab_dauer_min( min( $ab_each ) ),
		ab_dauer_min( array_sum( $ab_each ) ),
		array_sum( array_column( $ab_costs, 'baselines' ) )
	);
}
