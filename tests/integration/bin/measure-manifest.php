<?php
/**
 * The measurement of the manifests (Z8b branch 2, plan section 13): how long
 * the full hash of trees of plugins, themes and uploads takes, with the
 * number and size of their files, and how large the series of the
 * environment grows (lines, bytes, parts at the lines of a package), read
 * through AB_MCP_Sync_Env::read() as wp_site_export reads it (60 s a part,
 * AB_MCP_Sync_Export::OBJECTS lines a part).
 *
 *   php tests/integration/bin/measure-manifest.php <state.json> <site>
 *
 * It puts generated trees into wp-content of the site (names beginning with
 * ab-measure-, files of the sizes of SHAPES, from a fixed seed), reads the
 * environment of the site in parts, prints one table to the log, and removes
 * the trees again (also where a step fails). Each generated unit must come
 * back read whole, with as many files and bytes as were written: a unit
 * not read whole (too large, too many lines, not read) makes the
 * measurement incomplete, which says so, names the units and their causes,
 * gives no rate and ends with status 1 (Codex round 1, C2). The rate is the
 * bytes of the units read whole over the time of the series.
 * AB_MEASURE_SCALE (a number, default 1) scales the number of files, for a
 * short try of the script; AB_MEASURE_LINES (default and at most
 * AB_MCP_Sync_Export::OBJECTS) lowers the lines a part, and
 * AB_MEASURE_MAX_PARTS (default and at most
 * AB_MCP_Sync_Report::MAX_ENVIRONMENT) the parts a series may take, for a
 * test of its end. A series that does not end within them, or
 * takes more parts than wp_site_diff takes, is no measurement of a report
 * and ends with status 1. The CI runs it in the job
 * integration (step «Measure the manifests»); its numbers are read from the
 * log of that step. Development only: /tests ships with no release.
 *
 * @package AlphaBridge_MCP
 */

if ( 'cli' !== PHP_SAPI || 3 !== count( $argv ) ) {
	fwrite( STDERR, "usage: php measure-manifest.php <state.json> <site>\n" );
	exit( 2 );
}

require dirname( __DIR__ ) . '/support/class-it-sites.php';

putenv( 'AB_IT_STATE=' . $argv[1] );
$ab_it_key = $argv[2];
$_SERVER   = array_merge( $_SERVER, AB_MCP_IT_Sites::server_vars( $ab_it_key ), array( 'DOCUMENT_ROOT' => AB_MCP_IT_Sites::docroot( $ab_it_key ) ) );
define( 'WP_USE_THEMES', false );
require AB_MCP_IT_Sites::docroot( $ab_it_key ) . '/wp-load.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-export.php';
require_once AB_MCP_DIR . 'includes/sync/class-sync-report.php';

/**
 * The trees: where, how many units, files a unit, and the sizes of the files
 * (bytes, chosen in turn).
 */
const AB_MEASURE_SHAPES = array(
	'plugins' => array(
		'units' => 30,
		'files' => 600,
		'sizes' => array( 512, 2048, 6144, 16384, 40960 ),
	),
	'themes'  => array(
		'units' => 3,
		'files' => 900,
		'sizes' => array( 1024, 4096, 12288, 65536, 262144 ),
	),
	'uploads' => array(
		'units' => 1,
		'files' => 1500,
		'sizes' => array( 20480, 81920, 307200, 1048576 ),
	),
);

$content = AB_MCP_IT_Sites::docroot( $ab_it_key ) . '/wp-content';
$made    = array();
$status  = 0;
try {
	mt_srand( 8200 );
	$scale = is_numeric( getenv( 'AB_MEASURE_SCALE' ) ) ? (float) getenv( 'AB_MEASURE_SCALE' ) : 1.0;
	$year  = $content . '/uploads/2099';
	if ( file_exists( $year ) ) {
		throw new RuntimeException( "$year is there already: not a folder of this measurement." );
	}
	$made[] = $year;
	$total = array(
		'files' => 0,
		'bytes' => 0,
	);
	// Each generated unit with the files and bytes it must come back with.
	$want = array();
	// Every file the measurement writes, of the shapes or the main file of a
	// plugin or style.css of a theme, counted once in the totals and in its
	// unit (Codex, whole round over 4c66939..f25da74, C).
	$put = static function ( $path, $bytes, $key ) use ( &$total, &$want ) {
		file_put_contents( $path, $bytes );
		$total['files'] += 1;
		$total['bytes'] += strlen( $bytes );
		$want[ $key ]    = array(
			'files' => ( $want[ $key ]['files'] ?? 0 ) + 1,
			'bytes' => ( $want[ $key ]['bytes'] ?? 0 ) + strlen( $bytes ),
		);
	};
	foreach ( AB_MEASURE_SHAPES as $root => $shape ) {
		for ( $u = 1; $u <= $shape['units']; $u++ ) {
			$dir = 'uploads' === $root ? $year . '/01' : sprintf( '%s/%s/ab-measure-%02d', $content, $root, $u );
			if ( 'uploads' !== $root ) {
				$made[] = $dir;
			}
			for ( $f = 0; $f < max( 1, (int) ( $shape['files'] * $scale ) ); $f++ ) {
				$sub  = 'uploads' === $root ? '' : sprintf( 'inc/%02d/', $f % 20 );
				$path = sprintf( '%s/%sab-measure-%04d.%s', $dir, $sub, $f, 'uploads' === $root ? 'bin' : 'php' );
				if ( ! is_dir( dirname( $path ) ) ) {
					mkdir( dirname( $path ), 0755, true );
				}
				$size = $shape['sizes'][ $f % count( $shape['sizes'] ) ];
				$put( $path, str_repeat( chr( mt_rand( 65, 90 ) ), $size ), 'uploads' === $root ? 'upload:2099/01/' . basename( $path ) : ( 'plugins' === $root ? 'plugin:' : 'theme:' ) . basename( $dir ) );
			}
			$head = null;
			if ( 'plugins' === $root ) {
				$head = array( $dir . '/ab-measure.php', "<?php\n/* Plugin Name: Measure $u\n * Version: 1.0.0 */\n" );
			} elseif ( 'themes' === $root ) {
				$head = array( $dir . '/style.css', "/*\nTheme Name: Measure $u\nVersion: 1.0.0\n*/\n" );
			}
			if ( null !== $head ) {
				$put( $head[0], $head[1], ( 'plugins' === $root ? 'plugin:' : 'theme:' ) . basename( $dir ) );
			}
		}
	}

	// The series as wp_site_export reads it: 60 s and OBJECTS lines a part,
	// one part more than a report takes, to see a series that takes more.
	$lower = static function ( $name, $most ) {
		$value = getenv( $name );
		return is_numeric( $value ) && (int) $value >= 1 ? min( $most, (int) $value ) : $most;
	};
	$max   = $lower( 'AB_MEASURE_MAX_PARTS', AB_MCP_Sync_Report::MAX_ENVIRONMENT );
	$room  = $lower( 'AB_MEASURE_LINES', AB_MCP_Sync_Export::OBJECTS );
	$parts    = 0;
	$lines    = 0;
	$bytes    = 0;
	$units    = array();
	$hashed   = 0;
	$whole    = 0;
	$got      = array();
	$largest  = 0;
	$state    = null;
	$started  = microtime( true );
	$slowest  = 0.0;
	do {
		$t    = microtime( true );
		$read = AB_MCP_Sync_Env::read( $state, AB_MCP_Sync_Inventory::BUDGET, $room, 'staging' );
		if ( is_wp_error( $read ) ) {
			throw new RuntimeException( $read->get_error_message() );
		}
		$slowest = max( $slowest, microtime( true ) - $t );
		++$parts;
		$part = 0;
		foreach ( $read['lines'] as $line ) {
			$json  = AB_MCP_Sync_Canon::encode( $line );
			$part += strlen( $json );
			++$lines;
			if ( in_array( $line['env'], AB_MCP_Sync_Env::UNITS, true ) ) {
				$units[ $line['env'] ] = ( $units[ $line['env'] ] ?? 0 ) + 1;
				if ( null !== $line['hash'] && true === $line['complete'] ) {
					$hashed += (int) $line['files'];
					$whole  += (int) $line['bytes'];
				}
				$got[ $line['env'] . ':' . $line['ref'] ] = $line;
			}
		}
		$bytes  += $part;
		$largest = max( $largest, $part );
		$state   = $read['state'];
	} while ( null !== $state && $parts <= $max );
	$seconds = microtime( true ) - $started;

	// Each generated unit read whole, with its files and bytes.
	$missed = array();
	foreach ( $want as $key => $count ) {
		$line = $got[ $key ] ?? null;
		if ( null === $line ) {
			$missed[ $key ] = 'no line';
		} elseif ( true !== $line['complete'] || null === $line['hash'] ) {
			$missed[ $key ] = (string) ( $line['cause'] ?? 'not whole' );
		} elseif ( (int) $line['files'] !== $count['files'] || (int) $line['bytes'] !== $count['bytes'] ) {
			$missed[ $key ] = sprintf( '%d files, %d bytes read of %d, %d', $line['files'], $line['bytes'], $count['files'], $count['bytes'] );
		}
	}

	$mib = static fn( $b ) => sprintf( '%.1f MiB', $b / 1048576 );
	$out = array(
		'generated files'                      => $total['files'] . ' (' . $total['bytes'] . ' bytes, ' . $mib( $total['bytes'] ) . ')',
		'generated units read whole'           => ( count( $want ) - count( $missed ) ) . ' of ' . count( $want ),
		'units read'                           => (string) json_encode( $units ),
		'files hashed (whole units)'           => (string) $hashed,
		'bytes hashed (whole units)'           => $mib( $whole ),
		'duration of the series (all parts)'   => sprintf( '%.2f s', $seconds ),
		'slowest part'                         => sprintf( '%.2f s', $slowest ),
		'hash rate (whole units / series)'     => array() === $missed ? sprintf( '%.1f MiB/s', $whole / 1048576 / max( 0.001, $seconds ) ) : 'none: the measurement is incomplete',
		'parts (packages of the series)'       => (string) $parts,
		'lines of the series'                  => (string) $lines,
		'bytes of the lines (canonical JSON)'  => $mib( $bytes ) . ', ' . sprintf( '%.0f', $bytes / max( 1, $lines ) ) . ' bytes a line',
		'largest part'                         => $mib( $largest ),
		'lines a part at most'                 => (string) $room,
		'files a line of entries'              => (string) AB_MCP_Sync_Env::PER_LINE,
		'parts a series at most'               => (string) $max,
		'php'                                  => PHP_VERSION,
	);
	echo "measure-manifest: Z8b branch 2, the manifests of code and files\n";
	foreach ( $out as $what => $value ) {
		printf( "measure-manifest: %-40s %s\n", $what, $value );
	}
	// A report takes at most $max parts: a series that did not end within
	// them, or ended only in the part after them, is not one a report reads
	// (Codex, whole round over 4c66939..f25da74, C).
	if ( null !== $state || $parts > $max ) {
		echo "measure-manifest: NOT REPORTABLE: the series did not end within $max parts (wp_site_diff takes at most so many)\n";
		$status = 1;
	}
	if ( array() !== $missed ) {
		echo 'measure-manifest: INCOMPLETE: ' . count( $missed ) . ' generated units not read whole: ' . json_encode( array_slice( $missed, 0, 20, true ) ) . ( count( $missed ) > 20 ? ' …' : '' ) . "\n";
		$status = 1;
	}
} catch ( Throwable $e ) {
	fwrite( STDERR, 'measure-manifest: ' . $e->getMessage() . "\n" );
	$status = 1;
} finally {
	foreach ( $made as $dir ) {
		ab_measure_remove( $dir, $content );
	}
}
exit( $status );

/**
 * Remove a tree the measurement made, only below wp-content of the site and
 * only one of its own names.
 *
 * @param string $path    Path.
 * @param string $content wp-content.
 */
function ab_measure_remove( $path, $content ) {
	if ( 0 !== strpos( $path, $content . '/' ) || ( false === strpos( $path, '/ab-measure' ) && $content . '/uploads/2099' !== $path && 0 !== strpos( $path, $content . '/uploads/2099/' ) ) ) {
		throw new RuntimeException( "Will not remove $path." );
	}
	if ( is_link( $path ) || is_file( $path ) ) {
		unlink( $path );
		return;
	}
	if ( ! is_dir( $path ) ) {
		return;
	}
	foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $entry ) {
		ab_measure_remove( $path . '/' . $entry, $content );
	}
	rmdir( $path );
}
