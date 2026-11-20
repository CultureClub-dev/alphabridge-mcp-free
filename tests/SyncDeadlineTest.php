<?php
/**
 * The deadline of a reading (AB_MCP_Sync_Deadline, R12 of Z8b branch 5)
 * and the helpers that read under it, without WordPress:
 *
 * - at() makes a deadline of a finite number of seconds, 0 or more (row
 *   read.until), and refuses any other value (ab_mcp_read_deadline), never
 *   «no deadline»; a value that is no deadline is refused by every reader
 *   without a reading of the clock;
 * - left() reads the clock at every check: in time before the deadline,
 *   late from its own time on (ab_mcp_read_late); a clock without a finite
 *   number is unknown (ab_mcp_read_clock); the first stop stays, whatever
 *   the clock says later, and so does the stop of a reader (halt()); after
 *   a stop it answers it without a reading of the clock (R12b part 3.1);
 *   stop() gives it without asking the clock; without a deadline (null)
 *   nothing is asked or stopped;
 * - the helpers (AB_MCP_Payload_File::read(), AB_MCP_Sync_Import::inflate(),
 *   AB_MCP_Shape::read(), AB_MCP_Sync_Canon::encode()/decode(),
 *   AB_MCP_Sync_Env::held_here() with names_held()): in time each asks at
 *   exactly its gates (GATES, made from a run in time and written down) and
 *   answers as without a deadline; the deadline over at any one of its
 *   gates (each gate by itself: the first, a middle and the last time it
 *   is asked) answers with the stop in its full wording, by the same way
 *   up to that gate and without a reading of the clock or a step of its
 *   codec after it (DeadlineClock, SyncDeadlineCodec; a reading stopped
 *   within the decoding of its codec does not ask the codec again to encode,
 *   way()); the clock unknown alike; the refusals of the readers
 *   in time stay what they are (no gzip, a hash that does not match, a
 *   file that is not there);
 * - the check of one name by the rules is one step: the deadline stops
 *   the names between two names, before the next one, not within the check
 *   of a name (names_held(); the grain of the branch, for its Lead).
 *
 * The readings of the swap over two sites: tests/integration.
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Payload_File;
use AB_MCP_Shape;
use AB_MCP_Sync_Canon;
use AB_MCP_Sync_Deadline;
use AB_MCP_Sync_Env;
use AB_MCP_Sync_Import;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use stdClass;
use WP_Error;

require_once __DIR__ . '/site-info-stubs.php';
require_once __DIR__ . '/sync-stubs.php';
require_once __DIR__ . '/../includes/class-shape.php';
require_once __DIR__ . '/../includes/class-payload-file.php';
require_once __DIR__ . '/../includes/class-store-inputs.php';
require_once __DIR__ . '/../includes/sync/class-sync-deadline.php';
require_once __DIR__ . '/../includes/sync/class-sync-canon.php';
require_once __DIR__ . '/../includes/sync/class-sync-env.php';
require_once __DIR__ . '/../includes/class-staging-gate.php';
require_once __DIR__ . '/../includes/sync/class-sync-import.php';
require_once __DIR__ . '/DeadlineClock.php';

final class SyncDeadlineTest extends TestCase {

	/** The deadline is over (ab_mcp_read_late), in full. */
	const LATE_TEXT = 'The time this call had for its reading ran out before the reading was done, so the reading stopped before its next step and what it read is not used. Call again; where it keeps failing, ask the host for a longer time limit of PHP (max_execution_time).';

	/** The clock gave no time (ab_mcp_read_clock), in full. */
	const CLOCK_TEXT = 'The time of this call could not be measured during its reading, so the reading stopped before its next step and what it read is not used. Call again.';

	/** A deadline that is not one (ab_mcp_read_deadline), in full. */
	const DEADLINE_TEXT = 'The time limit this reading was given is not a time (a finite number of seconds), so nothing was read. This is a fault of the code that asked for the reading, not of the site: call again, and where it keeps failing, update AlphaBridge MCP or report it to its makers.';

	/**
	 * The gates of each reading in time, in order (DeadlineClock), from a run
	 * in time and written down: a gate more, less or elsewhere is a change of
	 * the reading this test sees.
	 */
	const GATES = array(
		// Before the file is asked, its size, its bytes, inflated, hashed, and before the answer.
		'payload'   => array(
			'AB_MCP_Payload_File::read#1',
			'AB_MCP_Payload_File::read#2',
			'AB_MCP_Payload_File::read#3',
			'AB_MCP_Payload_File::read#4',
			'AB_MCP_Payload_File::read#5',
			'AB_MCP_Payload_File::read#6',
		),
		// Before the first piece, before each of three pieces, before the end of the stream.
		'inflate'   => array(
			'AB_MCP_Sync_Import::inflate#1',
			'AB_MCP_Sync_Import::inflate#2',
			'AB_MCP_Sync_Import::inflate#2',
			'AB_MCP_Sync_Import::inflate#2',
			'AB_MCP_Sync_Import::inflate#3',
		),
		// A record: before each field; the list before each item, the map before
		// each pair; the text of the codec before it is decoded, each element
		// revived, each element prepared again and before its text, after the
		// round trip, and each item of what it decodes to.
		'shape'     => array(
			'AB_MCP_Shape::fields#1',
			'AB_MCP_Shape::items#1',
			'AB_MCP_Shape::items#1',
			'AB_MCP_Shape::items#1',
			'AB_MCP_Shape::fields#1',
			'AB_MCP_Shape::pairs#1',
			'AB_MCP_Shape::pairs#1',
			'AB_MCP_Shape::fields#1',
			'AB_MCP_Shape::decoded#1',
			'AB_MCP_Sync_Canon::revived#1',
			'AB_MCP_Sync_Canon::revived#1',
			'AB_MCP_Sync_Canon::prepared#2',
			'AB_MCP_Sync_Canon::prepared#2',
			'AB_MCP_Sync_Canon::encode#1',
			'AB_MCP_Shape::decoded#2',
			'AB_MCP_Shape::items#1',
			'AB_MCP_Shape::items#1',
		),
		// A map (each key), in it a map with a set (each element) and a list
		// (each element), then before the text.
		'encode'    => array(
			'AB_MCP_Sync_Canon::prepared#3',
			'AB_MCP_Sync_Canon::prepared#3',
			'AB_MCP_Sync_Canon::prepared#1',
			'AB_MCP_Sync_Canon::prepared#1',
			'AB_MCP_Sync_Canon::prepared#3',
			'AB_MCP_Sync_Canon::prepared#3',
			'AB_MCP_Sync_Canon::prepared#2',
			'AB_MCP_Sync_Canon::prepared#2',
			'AB_MCP_Sync_Canon::encode#1',
		),
		// Each element revived, depth first.
		'decode'    => array(
			'AB_MCP_Sync_Canon::revived#1',
			'AB_MCP_Sync_Canon::revived#1',
			'AB_MCP_Sync_Canon::revived#1',
			'AB_MCP_Sync_Canon::revived#1',
			'AB_MCP_Sync_Canon::revived#1',
			'AB_MCP_Sync_Canon::revived#1',
		),
		// held_here(): before its first step, each entry of the list, each line;
		// names_held(): each line (the units held), each line again, each main
		// file and each that runs, each entry, and before the answer.
		'held_here' => array(
			'AB_MCP_Sync_Env::held_here#1',
			'AB_MCP_Sync_Env::held_here#2',
			'AB_MCP_Sync_Env::held_here#2',
			'AB_MCP_Sync_Env::held_here#3',
			'AB_MCP_Sync_Env::held_here#3',
			'AB_MCP_Sync_Env::held_here#3',
			'AB_MCP_Sync_Env::names_held#1',
			'AB_MCP_Sync_Env::names_held#1',
			'AB_MCP_Sync_Env::names_held#1',
			'AB_MCP_Sync_Env::names_held#2',
			'AB_MCP_Sync_Env::names_held#4',
			'AB_MCP_Sync_Env::names_held#4',
			'AB_MCP_Sync_Env::names_held#4',
			'AB_MCP_Sync_Env::names_held#2',
			'AB_MCP_Sync_Env::names_held#3',
			'AB_MCP_Sync_Env::names_held#3',
			'AB_MCP_Sync_Env::names_held#2',
			'AB_MCP_Sync_Env::names_held#5',
		),
	);

	/**
	 * What a reading answers once it stopped: the stop itself ('stop'), or
	 * «not read» (null, '') while its caller passes the stop on
	 * (AB_MCP_Sync_Deadline::stop()).
	 */
	const STOPPED = array(
		'payload'   => 'stop',
		'inflate'   => 'stop',
		'shape'     => null,
		'encode'    => '',
		'decode'    => null,
		'held_here' => 'stop',
	);

	/** @var string A folder of this test. */
	private $dir = '';

	protected function setUp(): void {
		ab_test_reset();
		DeadlineClock::restore();
		$this->dir = sys_get_temp_dir() . '/ab-mcp-deadline-test-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir, 0700 );
		self::assertTrue( AB_MCP_Payload_File::write( $this->dir . '/p.bin', AB_MCP_Payload_File::compress( self::raw() ) ) );
	}

	protected function tearDown(): void {
		DeadlineClock::restore();
		foreach ( (array) scandir( $this->dir ) as $file ) {
			if ( '.' !== $file && '..' !== $file ) {
				unlink( $this->dir . '/' . $file );
			}
		}
		rmdir( $this->dir );
	}

	/* ------------------------------------------------------------ the deadline */

	public function testAtMakesADeadlineOfAFiniteNumberOfZeroOrMoreOnly(): void {
		$clock = DeadlineClock::in_time()->install();
		foreach ( array( 0, 0.0, 5, 2000.0, 1.0e12 ) as $until ) {
			self::assertInstanceOf( AB_MCP_Sync_Deadline::class, AB_MCP_Sync_Deadline::at( $until ), var_export( $until, true ) );
		}
		foreach ( array( -1, -0.5, NAN, INF, -INF, '2000', '', null, true, false, array(), array( 2000 ), new stdClass() ) as $until ) {
			self::assertRefused( AB_MCP_Sync_Deadline::at( $until ), 'ab_mcp_read_deadline', self::DEADLINE_TEXT, 'Never «no deadline»: ' . gettype( $until ) );
		}
		self::assertSame( array( array(), 0 ), array( $clock->gates, $clock->others ), 'at() reads no clock.' );
	}

	public function testWithoutADeadlineNothingIsAskedOrStopped(): void {
		$clock = DeadlineClock::in_time()->install();
		$why   = new WP_Error( 'ab_mcp_store_undecided', 'why' );
		self::assertTrue( AB_MCP_Sync_Deadline::left( null ) );
		self::assertNull( AB_MCP_Sync_Deadline::stop( null ) );
		self::assertSame( $why, AB_MCP_Sync_Deadline::halt( null, $why ), 'The reason of the reader, as it is.' );
		self::assertTrue( AB_MCP_Sync_Deadline::left( null ), 'Nothing kept.' );
		self::assertSame( array( array(), 0 ), array( $clock->gates, $clock->others ), 'No clock read.' );
	}

	public function testAValueThatIsNoDeadlineIsRefusedWithoutAReadingOfTheClock(): void {
		$clock = DeadlineClock::in_time()->install();
		$why   = new WP_Error( 'ab_mcp_store_undecided', 'why' );
		foreach ( array( DeadlineClock::UNTIL, 0, 'soon', '', false, true, array(), new stdClass(), new WP_Error( 'x' ) ) as $value ) {
			$name = gettype( $value );
			self::assertRefused( AB_MCP_Sync_Deadline::left( $value ), 'ab_mcp_read_deadline', self::DEADLINE_TEXT, 'left(): ' . $name );
			self::assertRefused( AB_MCP_Sync_Deadline::stop( $value ), 'ab_mcp_read_deadline', self::DEADLINE_TEXT, 'stop(): ' . $name );
			self::assertSame( $why, AB_MCP_Sync_Deadline::halt( $value, $why ), 'halt(): the reason of the reader, ' . $name );
		}
		self::assertSame( array( array(), 0 ), array( $clock->gates, $clock->others ), 'No clock read for a value that is no deadline.' );
	}

	public function testInTimeEachCheckReadsTheClockOnceAndStopReadsItNot(): void {
		$clock    = DeadlineClock::in_time()->install();
		$deadline = DeadlineClock::deadline();
		self::assertTrue( AB_MCP_Sync_Deadline::left( $deadline ) );
		self::assertTrue( AB_MCP_Sync_Deadline::left( $deadline ) );
		self::assertTrue( AB_MCP_Sync_Deadline::left( $deadline ) );
		self::assertCount( 3, $clock->gates, 'The clock at every check.' );
		self::assertNull( AB_MCP_Sync_Deadline::stop( $deadline ), 'No stop while in time.' );
		self::assertCount( 3, $clock->gates, 'stop() asks no clock.' );
		self::assertSame( 0, $clock->others );
	}

	public function testTheDeadlineIsOverFromItsOwnTimeOn(): void {
		$now                    = 0;
		AB_MCP_Sync_Env::$clock = static function () use ( &$now ) {
			return $now;
		};
		foreach ( array( array( 1999.999, true ), array( 2000.0, false ), array( 2000.001, false ), array( 1999, true ), array( 2000, false ) ) as $case ) {
			$now      = $case[0];
			$deadline = AB_MCP_Sync_Deadline::at( 2000 );
			$left     = AB_MCP_Sync_Deadline::left( $deadline );
			if ( $case[1] ) {
				self::assertTrue( $left, 'In time at ' . var_export( $now, true ) );
				continue;
			}
			self::assertRefused( $left, 'ab_mcp_read_late', self::LATE_TEXT, 'Late at ' . var_export( $now, true ) );
			self::assertSame( $left, AB_MCP_Sync_Deadline::stop( $deadline ), 'Kept as its stop.' );
		}
	}

	public function testAClockWithoutAFiniteNumberIsUnknownAndNeverATime(): void {
		$now                    = null;
		AB_MCP_Sync_Env::$clock = static function () use ( &$now ) {
			return $now;
		};
		foreach ( array( NAN, INF, -INF, '1500', '', null, true, array( 1500 ) ) as $value ) {
			$now      = $value;
			$deadline = DeadlineClock::deadline();
			self::assertRefused( AB_MCP_Sync_Deadline::left( $deadline ), 'ab_mcp_read_clock', self::CLOCK_TEXT, 'Clock: ' . gettype( $value ) );
		}
		$now = 1500;
		self::assertTrue( AB_MCP_Sync_Deadline::left( DeadlineClock::deadline() ), 'A whole number is a time.' );
		$clock = DeadlineClock::unknown_from( 1 )->install();
		self::assertRefused( AB_MCP_Sync_Deadline::left( DeadlineClock::deadline() ), 'ab_mcp_read_clock', self::CLOCK_TEXT, 'NAN of the clock of the tests.' );
		self::assertCount( 1, $clock->gates );
	}

	public function testWithoutAClockOfATestTheTimeIsTheTimeOfPhp(): void {
		AB_MCP_Sync_Env::$clock = null;
		self::assertRefused( AB_MCP_Sync_Deadline::left( AB_MCP_Sync_Deadline::at( microtime( true ) - 60 ) ), 'ab_mcp_read_late', self::LATE_TEXT, 'A minute ago.' );
		self::assertTrue( AB_MCP_Sync_Deadline::left( AB_MCP_Sync_Deadline::at( microtime( true ) + 3600 ) ), 'In an hour.' );
	}

	public function testTheFirstStopStaysWhateverTheClockSaysLater(): void {
		$now                    = DeadlineClock::LATE;
		AB_MCP_Sync_Env::$clock = static function () use ( &$now ) {
			return $now;
		};
		$deadline = DeadlineClock::deadline();
		$late     = AB_MCP_Sync_Deadline::left( $deadline );
		self::assertRefused( $late, 'ab_mcp_read_late', self::LATE_TEXT, 'Late.' );
		$now = DeadlineClock::NOW;
		self::assertSame( $late, AB_MCP_Sync_Deadline::left( $deadline ), 'In time again: still the first stop.' );
		$now = NAN;
		self::assertSame( $late, AB_MCP_Sync_Deadline::left( $deadline ), 'The clock unknown later: still late.' );
		self::assertSame( $late, AB_MCP_Sync_Deadline::halt( $deadline, new WP_Error( 'ab_mcp_store_undecided', 'why' ) ), 'A reason of a reader later: still late.' );
		self::assertSame( $late, AB_MCP_Sync_Deadline::stop( $deadline ) );

		$deadline = DeadlineClock::deadline();
		$unknown  = AB_MCP_Sync_Deadline::left( $deadline );
		self::assertRefused( $unknown, 'ab_mcp_read_clock', self::CLOCK_TEXT, 'Unknown.' );
		$now = DeadlineClock::LATE;
		self::assertSame( $unknown, AB_MCP_Sync_Deadline::left( $deadline ), 'Late later: still unknown.' );
		self::assertSame( $unknown, AB_MCP_Sync_Deadline::stop( $deadline ) );
	}

	public function testTheStopOfAReaderStaysAsTheFirstStop(): void {
		$now                    = DeadlineClock::NOW;
		AB_MCP_Sync_Env::$clock = static function () use ( &$now ) {
			return $now;
		};
		$deadline = DeadlineClock::deadline();
		$why      = new WP_Error( 'ab_mcp_store_undecided', 'why' );
		self::assertSame( $why, AB_MCP_Sync_Deadline::halt( $deadline, $why ) );
		self::assertSame( $why, AB_MCP_Sync_Deadline::halt( $deadline, new WP_Error( 'ab_mcp_store_undecided', 'another' ) ), 'The first reason stays.' );
		self::assertSame( $why, AB_MCP_Sync_Deadline::stop( $deadline ) );
		self::assertSame( $why, AB_MCP_Sync_Deadline::left( $deadline ), 'In time, still stopped.' );
		$now = DeadlineClock::LATE;
		self::assertSame( $why, AB_MCP_Sync_Deadline::left( $deadline ), 'Late later: still the reason of the reader.' );
		$now = NAN;
		self::assertSame( $why, AB_MCP_Sync_Deadline::left( $deadline ), 'The clock unknown later: still the reason of the reader.' );
	}

	public function testAfterAStopLeftAnswersItWithoutAReadingOfTheClock(): void {
		// R12b part 3.1 (section 3.1 of the order): after the halt no asking of
		// the clock, in left() itself.
		foreach ( array( 'late' => DeadlineClock::late_from( 1 ), 'unknown' => DeadlineClock::unknown_from( 1 ) ) as $case => $clock ) {
			$clock->install();
			$deadline = DeadlineClock::deadline();
			$stop     = AB_MCP_Sync_Deadline::left( $deadline );
			self::assertInstanceOf( WP_Error::class, $stop, $case );
			self::assertSame( $stop, AB_MCP_Sync_Deadline::left( $deadline ), $case . ': the first stop.' );
			self::assertSame( $stop, AB_MCP_Sync_Deadline::left( $deadline ), $case . ': still the first stop.' );
			self::assertCount( 1, $clock->gates, $case . ': the clock read once, by the check that stopped.' );
			self::assertSame( 0, $clock->others, $case );
		}
		$clock    = DeadlineClock::in_time()->install();
		$deadline = DeadlineClock::deadline();
		$why      = new WP_Error( 'ab_mcp_store_undecided', 'why' );
		AB_MCP_Sync_Deadline::halt( $deadline, $why );
		self::assertSame( $why, AB_MCP_Sync_Deadline::left( $deadline ), 'The reason of a reader.' );
		self::assertSame( array( array(), 0 ), array( $clock->gates, $clock->others ), 'No reading of the clock after the stop of a reader.' );
	}

	/* ------------------------------------------------------------- the readers */

	/** @return array<string,array{string}> */
	public static function scenarios(): array {
		$out = array();
		foreach ( array_keys( self::GATES ) as $scenario ) {
			$out[ $scenario ] = array( $scenario );
		}
		return $out;
	}

	/**
	 * Each gate of each reading by itself: the first, a middle and the last
	 * time the reading asks it.
	 *
	 * @return array<string,array{string,int}>
	 */
	public static function gates(): array {
		$out = array();
		foreach ( self::GATES as $scenario => $gates ) {
			$at = array();
			foreach ( $gates as $i => $gate ) {
				$at[ $gate ][] = $i;
			}
			foreach ( $at as $gate => $places ) {
				$m     = count( $places );
				$picks = array_unique( array( 0, intdiv( $m - 1, 2 ), $m - 1 ) );
				foreach ( $picks as $pick ) {
					$k = $places[ $pick ] + 1;
					$out[ sprintf( '%s: %s, %d of %d (reading %d)', $scenario, $gate, $pick + 1, $m, $k ) ] = array( $scenario, $k );
				}
			}
		}
		return $out;
	}

	/**
	 * A middle gate of each reading.
	 *
	 * @return array<string,array{string,int}>
	 */
	public static function middles(): array {
		$out = array();
		foreach ( self::GATES as $scenario => $gates ) {
			$k    = intdiv( count( $gates ) + 1, 2 );
			$name = sprintf( '%s: %s (reading %d)', $scenario, $gates[ $k - 1 ], $k );
			$out[ $name ] = array( $scenario, $k );
		}
		return $out;
	}

	#[DataProvider( 'scenarios' )]
	public function testInTimeAReadingAsksAtExactlyItsGatesAndAnswersAsWithoutADeadline( string $scenario ): void {
		$clock   = DeadlineClock::in_time()->install();
		$without = $this->reading( $scenario, null );
		self::assertSame( array( array(), 0 ), array( $clock->gates, $clock->others ), 'Without a deadline no clock read.' );
		self::assertSame( self::answer( $scenario ), $without, 'The answer of the reading.' );

		$clock = DeadlineClock::in_time()->install();
		self::assertSame( DeadlineClock::NOW, $clock(), 'A reading of the clock that is no deadline\'s: in time.' );
		self::assertSame( array( array(), 1 ), array( $clock->gates, $clock->others ), 'Counted apart, no gate.' );
		$deadline = DeadlineClock::deadline();
		self::assertSame( $without, $this->reading( $scenario, $deadline ), 'In time as without a deadline.' );
		self::assertSame( self::GATES[ $scenario ], $clock->gates, 'Each gate, in order.' );
		self::assertSame( 1, $clock->others, 'No other reading of the clock.' );
		self::assertNull( AB_MCP_Sync_Deadline::stop( $deadline ), 'No stop.' );
	}

	#[DataProvider( 'gates' )]
	public function testOverAtOneGateAReadingStopsThereWithoutAStepOrAReadingOfTheClockAfterIt( string $scenario, int $k ): void {
		$clock    = DeadlineClock::late_from( $k )->install();
		$deadline = DeadlineClock::deadline();
		$answer   = $this->reading( $scenario, $deadline );
		self::assertSame( array_slice( self::GATES[ $scenario ], 0, $k ), $clock->gates, 'The same way up to the gate (each gate before it in time), and no reading of the clock after it.' );
		self::assertSame( $this->way( $scenario, $k ), $clock->events, 'The same steps up to the gate, none after it.' );
		self::assertSame( 0, $clock->others );
		self::assertStopped( $scenario, $answer, $deadline, 'ab_mcp_read_late', self::LATE_TEXT );
	}

	#[DataProvider( 'middles' )]
	public function testTheClockUnknownAtAGateStopsAReadingAsItsOwnStop( string $scenario, int $k ): void {
		$clock    = DeadlineClock::unknown_from( $k )->install();
		$deadline = DeadlineClock::deadline();
		$answer   = $this->reading( $scenario, $deadline );
		self::assertSame( array_slice( self::GATES[ $scenario ], 0, $k ), $clock->gates );
		self::assertSame( $this->way( $scenario, $k ), $clock->events );
		self::assertStopped( $scenario, $answer, $deadline, 'ab_mcp_read_clock', self::CLOCK_TEXT );
	}

	/**
	 * The gates and steps of a reading in time up to and with its k-th
	 * gate: the way of a reading stopped there. Stopped within the decoding
	 * of its codec, the reading of a JSON text does not ask the codec again
	 * (AB_MCP_Shape::decoded(): no encoding after the stop, Lead of the
	 * branch on observation 1 of part 1b-1).
	 *
	 * @param string $scenario Key of GATES.
	 * @param int    $k        1: the first gate.
	 * @return string[]
	 */
	private function way( string $scenario, int $k ): array {
		$base = DeadlineClock::in_time()->install();
		$this->reading( $scenario, DeadlineClock::deadline() );
		self::assertSame( self::GATES[ $scenario ], $base->gates, 'The run in time.' );
		return $base->until_gate( $k );
	}

	#[DataProvider( 'scenarios' )]
	public function testAValueThatIsNoDeadlineIsRefusedByEachReaderWithoutAReadingOfTheClock( string $scenario ): void {
		foreach ( array( DeadlineClock::UNTIL, 'soon' ) as $value ) {
			$clock  = DeadlineClock::in_time()->install();
			$answer = $this->reading( $scenario, $value );
			self::assertSame( array( array(), 0 ), array( $clock->gates, $clock->others ), 'No clock read: ' . gettype( $value ) );
			if ( 'stop' === self::STOPPED[ $scenario ] ) {
				self::assertRefused( $answer, 'ab_mcp_read_deadline', self::DEADLINE_TEXT, 'The refusal: ' . gettype( $value ) );
				continue;
			}
			self::assertSame( self::STOPPED[ $scenario ], $answer, 'Not read: ' . gettype( $value ) );
			self::assertRefused( AB_MCP_Sync_Deadline::stop( $value ), 'ab_mcp_read_deadline', self::DEADLINE_TEXT, 'Its caller passes the refusal on.' );
		}
	}

	/* ------------------------------------------------ the refusals of a reader */

	public function testAFileThatIsNotThereOrWhoseHashDiffersIsRefusedAsSuchInTimeAndAsTheStopWhenLate(): void {
		$sha = hash( 'sha256', self::raw() );
		DeadlineClock::in_time()->install();
		$deadline = DeadlineClock::deadline();
		self::assertRefused( AB_MCP_Payload_File::read( $this->dir . '/p.bin', str_repeat( '0', 64 ), 1 << 20, 1 << 20, $deadline ), 'hash', '', 'A hash that differs, in time.' );
		self::assertRefused( AB_MCP_Payload_File::read( $this->dir . '/none.bin', $sha, 1 << 20, 1 << 20, $deadline ), 'missing', '', 'No file, in time.' );
		self::assertNull( AB_MCP_Sync_Deadline::stop( $deadline ), 'Refusals of the reader, no stop.' );

		// Over right before the hash: the stop, not a hash that differs.
		$clock    = DeadlineClock::late_from( 5 )->install();
		$deadline = DeadlineClock::deadline();
		$answer   = AB_MCP_Payload_File::read( $this->dir . '/p.bin', str_repeat( '0', 64 ), 1 << 20, 1 << 20, $deadline );
		self::assertRefused( $answer, 'ab_mcp_read_late', self::LATE_TEXT, 'Late, not hash.' );
		self::assertSame( array_slice( self::GATES['payload'], 0, 5 ), $clock->gates );

		// Over before the file is asked: the stop, not a file that is not there.
		$clock    = DeadlineClock::late_from( 1 )->install();
		$deadline = DeadlineClock::deadline();
		self::assertRefused( AB_MCP_Payload_File::read( $this->dir . '/none.bin', $sha, 1 << 20, 1 << 20, $deadline ), 'ab_mcp_read_late', self::LATE_TEXT, 'Late, not missing.' );
		self::assertSame( array( 'AB_MCP_Payload_File::read#1' ), $clock->gates );
	}

	public function testAChunkThatIsNoGzipIsFalseInTimeAndTheStopWhenLate(): void {
		$clock    = DeadlineClock::in_time()->install();
		$deadline = DeadlineClock::deadline();
		$cut      = substr( self::chunk(), 0, -9 );
		self::assertFalse( AB_MCP_Sync_Import::inflate( 'no gzip at all', 1 << 20, $deadline ), 'No gzip.' );
		self::assertFalse( AB_MCP_Sync_Import::inflate( $cut, 1 << 20, $deadline ), 'A gzip cut short.' );
		self::assertFalse( AB_MCP_Sync_Import::inflate( '', 1 << 20, $deadline ), 'Nothing.' );
		self::assertNull( AB_MCP_Sync_Import::inflate( self::chunk(), 100, $deadline ), 'Past the cap.' );
		self::assertNull( AB_MCP_Sync_Deadline::stop( $deadline ), 'Answers of the reader, no stop.' );

		// Over before the first piece: the stop, not «no gzip».
		$clock    = DeadlineClock::late_from( 2 )->install();
		$deadline = DeadlineClock::deadline();
		self::assertRefused( AB_MCP_Sync_Import::inflate( 'no gzip at all', 1 << 20, $deadline ), 'ab_mcp_read_late', self::LATE_TEXT, 'Late, not false.' );
		self::assertSame( array( 'AB_MCP_Sync_Import::inflate#1', 'AB_MCP_Sync_Import::inflate#2' ), $clock->gates );

		// Over before the end of a stream cut short: the stop, not false.
		$clock    = DeadlineClock::late_from( 5 )->install();
		$deadline = DeadlineClock::deadline();
		self::assertRefused( AB_MCP_Sync_Import::inflate( $cut, 1 << 20, $deadline ), 'ab_mcp_read_late', self::LATE_TEXT, 'Late, not a gzip cut short.' );
		self::assertSame( self::GATES['inflate'], $clock->gates );
	}

	public function testAValueNotOfItsFormIsNullInTimeWithoutAStop(): void {
		DeadlineClock::in_time()->install();
		$deadline = DeadlineClock::deadline();
		$value    = self::shaped();
		$value['list'][1] = -1;
		self::assertNull( AB_MCP_Shape::read( $value, self::form(), $deadline ), 'An item out of range.' );
		$value         = self::shaped();
		$value['text'] = '["p", "q"]';
		self::assertNull( AB_MCP_Shape::read( $value, self::form(), $deadline ), 'A text its writer did not write.' );
		self::assertNull( AB_MCP_Sync_Canon::decode( '{"~set":["x"]}', 8, $deadline ), 'A set is no text of decode().' );
		self::assertNull( AB_MCP_Sync_Deadline::stop( $deadline ), 'Not of the form: no stop.' );
	}

	/* ------------------------------------------------------- the grain of names */

	public function testTheDeadlineStopsTheNamesBetweenTwoNamesNotWithinTheCheckOfOneName(): void {
		$names = new ReflectionMethod( AB_MCP_Sync_Env::class, 'names_held' );
		if ( PHP_VERSION_ID < 80100 ) {
			$names->setAccessible( true );
		}
		$lines = array( array( 'env' => 'plugin', 'ref' => 'shop', 'mains' => array( 'vault=1.php', 'b.php' ) ) );

		// In time: each name checked by the rules, the held one by its SHA-256.
		$clock = DeadlineClock::in_time()->install();
		$asked = array();
		$held  = static function ( $name ) use ( &$asked ) {
			$asked[] = $name;
			return null !== AB_MCP_Sync_Env::held( $name, array( 'vault' ) );
		};
		$out   = $names->invoke( null, $lines, $held, DeadlineClock::deadline() );
		self::assertSame( array( AB_MCP_Sync_Env::hashed( 'vault=1.php' ), 'b.php' ), $out[0]['mains'] );
		self::assertSame( array( 'vault=1.php', 'b.php' ), $asked );
		self::assertSame( array( 'AB_MCP_Sync_Env::names_held#1', 'AB_MCP_Sync_Env::names_held#2', 'AB_MCP_Sync_Env::names_held#4', 'AB_MCP_Sync_Env::names_held#4', 'AB_MCP_Sync_Env::names_held#5' ), $clock->gates, 'One check before each name, none within it.' );

		// The time runs out while the first name is checked: its check ends,
		// the next name is not checked.
		$clock = DeadlineClock::in_time()->install();
		$asked = array();
		$held  = static function ( $name ) use ( &$asked, $clock ) {
			$asked[] = $name;
			$clock->jump();
			$held    = null !== AB_MCP_Sync_Env::held( $name, array( 'vault' ) );
			$asked[] = 'checked ' . $name . ': ' . ( $held ? 'held' : 'not held' );
			return $held;
		};
		$deadline = DeadlineClock::deadline();
		$out      = $names->invoke( null, $lines, $held, $deadline );
		self::assertRefused( $out, 'ab_mcp_read_late', self::LATE_TEXT, 'The stop.' );
		self::assertSame( $out, AB_MCP_Sync_Deadline::stop( $deadline ) );
		self::assertSame( array( 'vault=1.php', 'checked vault=1.php: held' ), $asked, 'The check of the first name whole, no second name.' );
		self::assertSame( array( 'AB_MCP_Sync_Env::names_held#1', 'AB_MCP_Sync_Env::names_held#2', 'AB_MCP_Sync_Env::names_held#4', 'AB_MCP_Sync_Env::names_held#4' ), $clock->gates, 'Stopped before the next name, no reading of the clock within the check of a name.' );
	}

	/* ----------------------------------------------------------------- helpers */

	/**
	 * A reading of a scenario under a deadline (or none, or a value that is
	 * no deadline).
	 *
	 * @param string $scenario Key of GATES.
	 * @param mixed  $deadline Deadline.
	 * @return mixed What the reader answers.
	 */
	private function reading( string $scenario, $deadline ) {
		switch ( $scenario ) {
			case 'payload':
				return AB_MCP_Payload_File::read( $this->dir . '/p.bin', hash( 'sha256', self::raw() ), 1 << 20, 1 << 20, $deadline );
			case 'inflate':
				return AB_MCP_Sync_Import::inflate( self::chunk(), 1 << 20, $deadline );
			case 'shape':
				return AB_MCP_Shape::read( self::shaped(), self::form(), $deadline );
			case 'encode':
				return AB_MCP_Sync_Canon::encode( self::canonical(), $deadline );
			case 'decode':
				return AB_MCP_Sync_Canon::decode( '{"l":[1,2],"m":{"a":1,"b":"z"}}', 8, $deadline );
			case 'held_here':
				return AB_MCP_Sync_Env::held_here( self::lines(), self::held(), array(), $deadline );
		}
		self::fail( 'No scenario ' . $scenario );
	}

	/**
	 * What a reading answers in time.
	 *
	 * @param string $scenario Key of GATES.
	 * @return mixed
	 */
	private static function answer( string $scenario ) {
		switch ( $scenario ) {
			case 'payload':
				return self::raw();
			case 'inflate':
				return self::bytes();
			case 'shape':
				return array_replace( self::shaped(), array( 'text' => array( 'p', 'q' ) ) );
			case 'encode':
				return '{"l":[1,2],"m":{"a":1,"b":{"~set":["x","y"]}}}';
			case 'decode':
				return array(
					'l' => array( 1, 2 ),
					'm' => array(
						'a' => 1,
						'b' => 'z',
					),
				);
			case 'held_here':
				return self::lines();
		}
		self::fail( 'No scenario ' . $scenario );
	}

	/** The payload of the file of the test. */
	private static function raw(): string {
		return str_repeat( 'a payload of a file of the store. ', 40 );
	}

	/** Bytes zlib cannot shrink: three pieces of AB_MCP_Sync_Import::INFLATE_PIECE gzipped. */
	private static function bytes(): string {
		$out = '';
		for ( $i = 0; $i < 45; $i++ ) {
			$out .= hash( 'sha256', 'piece ' . $i, true );
		}
		return $out;
	}

	/** The chunk of bytes(), gzipped. */
	private static function chunk(): string {
		return (string) gzencode( self::bytes() );
	}

	/** A record with a list, a map and a text of AB_MCP_Sync_Canon (by SyncDeadlineCodec). */
	private static function form(): array {
		$text = array(
			'type'      => 'string',
			'empty'     => false,
			'max_bytes' => null,
		);
		return array(
			'type'   => 'record',
			'extra'  => false,
			'fields' => array(
				'list' => array(
					'type'      => 'list',
					'empty'     => false,
					'unique'    => false,
					'max_items' => null,
					'item'      => array(
						'type'   => 'int',
						'digits' => false,
						'min'    => 0,
						'max'    => null,
					),
				),
				'map'  => array(
					'type'  => 'map',
					'empty' => false,
					'key'   => $text,
					'value' => $text,
				),
				'text' => array(
					'type'  => 'json',
					'codec' => SyncDeadlineCodec::class,
					'depth' => 8,
					'value' => array(
						'type'      => 'list',
						'empty'     => false,
						'unique'    => true,
						'max_items' => null,
						'item'      => $text,
					),
				),
			),
		);
	}

	/** A value of form(). */
	private static function shaped(): array {
		return array(
			'list' => array( 1, 2, 3 ),
			'map'  => array(
				'a' => 'x',
				'b' => 'y',
			),
			'text' => '["p","q"]',
		);
	}

	/** A form of AB_MCP_Sync_Canon with a map, a set and a list. */
	private static function canonical(): array {
		return array(
			'm' => array(
				'b' => array( '~set' => array( 'y', 'x' ) ),
				'a' => 1,
			),
			'l' => array( 1, 2 ),
		);
	}

	/** Lines of the environment: a plugin with its main files, its entries, a line of the core. */
	private static function lines(): array {
		return array(
			array(
				'env'    => 'plugin',
				'ref'    => 'shop',
				'mains'  => array( 'shop/a.php', 'shop/b.php' ),
				'active' => array( 'shop/a.php' ),
			),
			array(
				'env'     => 'entries',
				'unit'    => 'plugin:shop',
				'entries' => array( array( 'shop/a.php', 1 ), array( 'shop/b.php', 2 ) ),
			),
			array(
				'env'   => 'core',
				'ref'   => 'locale',
				'value' => 'de_CH',
			),
		);
	}

	/** The list of the held objects of the import: none of lines(). */
	private static function held(): array {
		return array(
			array( 'plugin', hash( 'sha256', AB_MCP_Sync_Canon::encode( 'other' ) ), 'credential_field' ),
			array( 'theme', hash( 'sha256', AB_MCP_Sync_Canon::encode( 'another' ) ), 'credential_field' ),
		);
	}

	/**
	 * A reading stopped: the stop as its answer, or «not read» and the stop
	 * kept for its caller.
	 */
	private static function assertStopped( string $scenario, $answer, $deadline, string $code, string $text ): void {
		$stop = AB_MCP_Sync_Deadline::stop( $deadline );
		self::assertRefused( $stop, $code, $text, 'The stop kept.' );
		if ( 'stop' === self::STOPPED[ $scenario ] ) {
			self::assertSame( $stop, $answer, 'The stop is the answer.' );
			return;
		}
		self::assertSame( self::STOPPED[ $scenario ], $answer, 'Not read; its caller passes the stop on.' );
	}

	/** A WP_Error of its code with its full message. */
	private static function assertRefused( $error, string $code, string $text, string $why ): void {
		self::assertInstanceOf( WP_Error::class, $error, $why );
		self::assertSame( array( $code, $text ), array( $error->get_error_code(), $error->get_error_message() ), $why );
	}
}

/**
 * AB_MCP_Sync_Canon as the codec of a JSON text of AB_MCP_Shape, each of
 * its decodings and encodings kept as a step of the reading
 * (DeadlineClock::step()): whether the codec is asked after a stop.
 */
final class SyncDeadlineCodec {

	/**
	 * @param string     $json     Text.
	 * @param int        $depth    Depth.
	 * @param mixed|null $deadline Deadline.
	 * @return mixed|null
	 */
	public static function decode( $json, $depth = 512, $deadline = null ) {
		self::step( 'decode' );
		return AB_MCP_Sync_Canon::decode( $json, $depth, $deadline );
	}

	/**
	 * @param mixed      $value    Form.
	 * @param mixed|null $deadline Deadline.
	 * @return string
	 */
	public static function encode( $value, $deadline = null ) {
		self::step( null === $value ? 'encode null' : 'encode' );
		return AB_MCP_Sync_Canon::encode( $value, $deadline );
	}

	private static function step( string $what ): void {
		if ( AB_MCP_Sync_Env::$clock instanceof DeadlineClock ) {
			AB_MCP_Sync_Env::$clock->step( $what );
		}
	}
}
