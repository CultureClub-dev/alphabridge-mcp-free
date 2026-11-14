<?php
/**
 * The clock of the budget of a reading in a test (R12 of Z8b branch 5):
 * set as AB_MCP_Sync_Env::$clock (install(); for a test of the swap also
 * as the clocks of the swap and of the import, one clock for all), it
 * tells each reading of a deadline (AB_MCP_Sync_Deadline::left(), found by
 * its frame) from the other readings of the same clock
 * (AB_MCP_Sync_File_Manifest::now(), the swap's own time), which it
 * answers in time and only counts.
 *
 * - Each reading of a deadline is kept by its gate, the place of the
 *   reader that asked: Class::function#n, n the place of that ask among
 *   the asks of the function in its source, counted from 1 (a call of
 *   AB_MCP_Sync_Deadline::left() or of one of WRAPPERS, ASKS); a helper of
 *   WRAPPERS is no gate of its own, its caller is. A gate that is no such
 *   ask (a closure, a test) is kept as Class::function:line.
 * - The time is NOW until the k-th reading of a deadline (late_from(),
 *   unknown_from()) or until jump() asks for it: from then on LATE (the
 *   deadline over) or NAN (the clock unknown). The readings that are no
 *   deadline's stay in time.
 * - Once a reading stopped, its readers pass the stop on without asking
 *   the clock again (AB_MCP_Sync_Deadline::stop()): the gates of a run
 *   halted at the k-th reading are the first k gates of the same run in
 *   time, and any reading more is a step taken after the stop.
 * - A step the test can see (a codec of its own, a seam) is kept with the
 *   gates in one order (step(), events): after the k-th gate of a halted
 *   run neither a step nor a gate follows.
 *
 * Used by the unit tests of the deadline (SyncDeadlineTest) and meant for
 * the tests of the swap over two sites (tests/integration).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use ReflectionMethod;

final class DeadlineClock {

	/** The time while the deadline is not over (and of every reading that is no deadline's). */
	const NOW = 1000.0;

	/** The deadline of the tests (AB_MCP_Sync_Deadline::at( UNTIL ), deadline()). */
	const UNTIL = 2000.0;

	/**
	 * The time once it is over: far after any deadline a test makes, the
	 * one the swap reckons from its own clock too. Where exactly the
	 * deadline ends (its time is late) is a test of its own.
	 */
	const LATE = 1.0e12;

	/**
	 * The helpers between a reader and AB_MCP_Sync_Deadline::left(): no gate
	 * of their own, their caller is. AB_MCP_Sync_File_Manifest::due() asks
	 * the deadline of the caller first (R12 part 2: the tree), through its
	 * left(): a gate of the walk is the line that calls left() or due().
	 */
	const WRAPPERS = array(
		'AB_MCP_Shape::stopped',
		'AB_MCP_Sync_Canon::stopped',
		'AB_MCP_Payload_File::left',
		'AB_MCP_Protected_Store::read_gate',
		'AB_MCP_Sync_File_Manifest::left',
		'AB_MCP_Sync_File_Manifest::due',
	);

	/** An ask of the deadline in the source of a reader: AB_MCP_Sync_Deadline::left() or one of WRAPPERS. */
	const ASKS = '/AB_MCP_Sync_Deadline::left\(|self::(?:stopped|left|read_gate|due)\(/';

	/** @var string[] Each reading of a deadline by its gate, in order. */
	public $gates = array();

	/** @var string[] Each reading of a deadline by Class::function:line of its gate, in order. */
	public $lines = array();

	/** @var string[] Each gate (as in gates) and each step of the test ('step: ' and what), in order. */
	public $events = array();

	/** @var int The readings of this clock that are no deadline's. */
	public $others = 0;

	/** @var int|null From which reading of a deadline on (1: the first) the time is over; null: never. */
	private $from = null;

	/** @var bool Whether from then on the time is unknown (NAN) rather than late. */
	private $unknown = false;

	/** @var array<string,int[]> The lines of the asks of a method, by Class::function. */
	private static $asks = array();

	/**
	 * A clock in time at every reading.
	 *
	 * @return self
	 */
	public static function in_time(): self {
		return new self();
	}

	/**
	 * A clock late from the k-th reading of a deadline on.
	 *
	 * @param int $k 1: the first reading.
	 * @return self
	 */
	public static function late_from( int $k ): self {
		$clock       = new self();
		$clock->from = $k;
		return $clock;
	}

	/**
	 * A clock that gives no time (NAN) from the k-th reading of a deadline on.
	 *
	 * @param int $k 1: the first reading.
	 * @return self
	 */
	public static function unknown_from( int $k ): self {
		$clock          = new self();
		$clock->from    = $k;
		$clock->unknown = true;
		return $clock;
	}

	/**
	 * The deadline of the tests, made by the code under test.
	 *
	 * @return \AB_MCP_Sync_Deadline|\WP_Error
	 */
	public static function deadline() {
		return \AB_MCP_Sync_Deadline::at( self::UNTIL );
	}

	/**
	 * The time is over from the next reading of a deadline on (a step that
	 * takes the time: called from within it, a callback of the test).
	 */
	public function jump(): void {
		$this->from = count( $this->gates ) + 1;
	}

	/**
	 * A step of the reading the test sees, kept in order with the gates.
	 *
	 * @param string $what The step.
	 */
	public function step( string $what ): void {
		$this->events[] = 'step: ' . $what;
	}

	/**
	 * The events up to and with the k-th gate.
	 *
	 * @param int $k 1: the first gate.
	 * @return string[]
	 */
	public function until_gate( int $k ): array {
		$seen = 0;
		foreach ( $this->events as $i => $event ) {
			if ( 0 !== strpos( $event, 'step: ' ) && ++$seen === $k ) {
				return array_slice( $this->events, 0, $i + 1 );
			}
		}
		return $this->events;
	}

	/**
	 * This clock as the clock of the budget (AB_MCP_Sync_Env::$clock) and,
	 * where they are loaded, as the clocks of the swap and of the import:
	 * one clock for all (R12). restore() takes them back.
	 *
	 * @return self
	 */
	public function install(): self {
		foreach ( self::holders() as $class ) {
			$class::$clock = $this;
		}
		return $this;
	}

	/**
	 * No clock of a test for the budget, the swap and the import.
	 */
	public static function restore(): void {
		foreach ( self::holders() as $class ) {
			$class::$clock = null;
		}
	}

	/**
	 * @return string[] The classes whose clock install() sets.
	 */
	private static function holders(): array {
		$out = array();
		foreach ( array( 'AB_MCP_Sync_Env', 'AB_MCP_Sync_Code_Swap', 'AB_MCP_Sync_Import' ) as $class ) {
			if ( class_exists( $class, false ) ) {
				$out[] = $class;
			}
		}
		return $out;
	}

	/**
	 * A reading of the clock.
	 *
	 * @return float
	 */
	public function __invoke() {
		$gate = self::gate( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) );
		if ( null === $gate ) {
			++$this->others;
			return self::NOW;
		}
		$this->gates[]  = $gate[0];
		$this->lines[]  = $gate[1];
		$this->events[] = $gate[0];
		if ( null === $this->from || count( $this->gates ) < $this->from ) {
			return self::NOW;
		}
		return $this->unknown ? NAN : self::LATE;
	}

	/**
	 * The gate of a reading of a deadline, or null for a reading that is no
	 * deadline's.
	 *
	 * @param array[] $frames debug_backtrace() from within __invoke().
	 * @return string[]|null array( Class::function#n, Class::function:line ).
	 */
	private static function gate( array $frames ): ?array {
		$now = null;
		foreach ( $frames as $i => $frame ) {
			if ( 'AB_MCP_Sync_Deadline::now' === self::name( $frame ) ) {
				$now = $i;
				break;
			}
		}
		if ( null === $now || ! isset( $frames[ $now + 1 ] ) || 'AB_MCP_Sync_Deadline::left' !== self::name( $frames[ $now + 1 ] ) ) {
			return null;
		}
		// The frame of a call holds the line it was called from, in the
		// function of the frame above it.
		$at = $now + 1;
		while ( isset( $frames[ $at + 1 ] ) && in_array( self::name( $frames[ $at + 1 ] ), self::WRAPPERS, true ) ) {
			++$at;
		}
		$line   = isset( $frames[ $at ]['line'] ) ? (int) $frames[ $at ]['line'] : 0;
		$caller = isset( $frames[ $at + 1 ] ) ? self::name( $frames[ $at + 1 ] ) : 'main';
		$place  = $caller . ':' . $line;
		$asks   = self::asks( $caller );
		$n      = array_search( $line, $asks, true );
		return array( false === $n ? $place : $caller . '#' . ( $n + 1 ), $place );
	}

	/**
	 * @param array $frame A frame of debug_backtrace().
	 * @return string Class::function, or the function.
	 */
	private static function name( array $frame ): string {
		return ( isset( $frame['class'] ) ? $frame['class'] . '::' : '' ) . ( isset( $frame['function'] ) ? $frame['function'] : '' );
	}

	/**
	 * The lines of the asks of the deadline in a method, in the order of its
	 * source; none for what is no method (a closure, a function).
	 *
	 * @param string $name Class::function.
	 * @return int[]
	 */
	private static function asks( string $name ): array {
		if ( isset( self::$asks[ $name ] ) ) {
			return self::$asks[ $name ];
		}
		$out   = array();
		$parts = explode( '::', $name, 2 );
		if ( 2 === count( $parts ) && method_exists( $parts[0], $parts[1] ) ) {
			$method = new ReflectionMethod( $parts[0], $parts[1] );
			$file   = $method->getFileName();
			$source = is_string( $file ) ? file( $file ) : false;
			if ( is_array( $source ) ) {
				for ( $line = (int) $method->getStartLine(); $line <= (int) $method->getEndLine(); $line++ ) {
					if ( isset( $source[ $line - 1 ] ) && 1 === preg_match( self::ASKS, $source[ $line - 1 ] ) ) {
						$out[] = $line;
					}
				}
			}
		}
		self::$asks[ $name ] = $out;
		return $out;
	}
}
