<?php
/**
 * The deadline of a reading within one call (Z8b branch 5, R12: the budget
 * of the readings of the swap, the part of A1 of the Codex round on
 * 4881c0d..947fa6d that passes until − keep down the readings of the stage
 * and of the signed manifest). A reader that takes a deadline checks it
 * before each step of its reading (a file read, a folder listed, a chunk
 * inflated, an element of a list read or worked on) and before it gives
 * its answer; once it is over, no further step of the reading begins, and
 * the reading answers with the stop, never as a package, file or group
 * that is missing, incomplete or damaged.
 *
 * - The deadline is optional for every reader: none (null) is the reading
 *   as it was before; a deadline is made by at() from a time of the clock
 *   of the budget, in the form of its row read.until of
 *   AB_MCP_Store_Inputs::TABLE; a value that is neither is an error
 *   (ab_mcp_read_deadline), never «no deadline».
 * - One clock: the clock of the budget of AB_MCP_Sync_Env ($clock, the
 *   tests set it; microtime() else), read strictly (no cast: a value that is
 *   no finite number is unknown, ab_mcp_read_clock). The clocks of another
 *   meaning stay what they are: the validity of a package
 *   (AB_MCP_Sync_Manifest::verify() by AB_MCP_Sync_Run::now()) and the
 *   proof of the protected store (time(), microtime()).
 * - The first stop is kept (the reading under a deadline that stopped stays
 *   stopped, whatever the clock says later), so every caller in between can
 *   pass it on (stop()) where a helper answers only «not read» (null, false,
 *   an array marked unknown).
 * - A single call that cannot be interrupted (one file read, one gzip
 *   member inflated at once, one decoding of JSON, one sort, a signature
 *   checked) is a named limit: a call begun before the end of the deadline
 *   may return after it, and the reading stops right then.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-store-inputs.php';

/**
 * Class AB_MCP_Sync_Deadline
 */
final class AB_MCP_Sync_Deadline {

	/** The code of a reading whose deadline is over. */
	const LATE = 'ab_mcp_read_late';

	/** The code of a reading whose clock gave no time. */
	const CLOCK = 'ab_mcp_read_clock';

	/** The code of a deadline that is not one. */
	const INVALID = 'ab_mcp_read_deadline';

	/** @var int|float Until when (the clock of the budget). */
	private $until;

	/** @var WP_Error|null The first stop of the reading under this deadline. */
	private $stop = null;

	/**
	 * @param int|float $until Until when, read in the form of read.until.
	 */
	private function __construct( $until ) {
		$this->until = $until;
	}

	/**
	 * A deadline at a time of the clock of the budget, or the refusal where
	 * the time is not of the form of its row read.until (a finite number):
	 * never «no deadline».
	 *
	 * @param mixed $until Until when.
	 * @return AB_MCP_Sync_Deadline|WP_Error
	 */
	public static function at( $until ) {
		$read = AB_MCP_Store_Inputs::read( 'read.until', $until );
		if ( null === $read ) {
			return self::invalid();
		}
		return new self( $read );
	}

	/**
	 * Whether a reading may take its next step: true without a deadline
	 * (null) and while the deadline is not over; else the stop (the first
	 * one stays), and the refusal for a value that is no deadline. The clock
	 * is read at every check.
	 *
	 * @param mixed $deadline Null, or a deadline of at().
	 * @return true|WP_Error
	 */
	public static function left( $deadline ) {
		if ( null === $deadline ) {
			return true;
		}
		if ( ! $deadline instanceof self ) {
			return self::invalid();
		}
		$now = self::now();
		if ( null === $deadline->stop && null === $now ) {
			$deadline->stop = self::clock_unknown();
		} elseif ( null === $deadline->stop && $now >= $deadline->until ) {
			$deadline->stop = self::late();
		}
		return null === $deadline->stop ? true : $deadline->stop;
	}

	/**
	 * Stop a reading under a deadline for a reason of its reader (the
	 * protected store not decided in this request): kept as its first stop.
	 *
	 * @param mixed    $deadline A deadline of at().
	 * @param WP_Error $why      The reason.
	 * @return WP_Error The stop of the deadline (the first one).
	 */
	public static function halt( $deadline, WP_Error $why ) {
		if ( ! $deadline instanceof self ) {
			return $why;
		}
		if ( null === $deadline->stop ) {
			$deadline->stop = $why;
		}
		return $deadline->stop;
	}

	/**
	 * The stop of a reading, for a caller whose helper answered «not read»:
	 * null without a deadline and while none happened; the refusal for a
	 * value that is no deadline.
	 *
	 * @param mixed $deadline Null, or a deadline of at().
	 * @return WP_Error|null
	 */
	public static function stop( $deadline ) {
		if ( null === $deadline ) {
			return null;
		}
		if ( ! $deadline instanceof self ) {
			return self::invalid();
		}
		return $deadline->stop;
	}

	/**
	 * The time within one call, for the budget of a reading only: the clock
	 * of AB_MCP_Sync_Env, which the tests set (the same clock as the swap's
	 * in them), else microtime( true ); a value that is no finite number is
	 * unknown (null), never a time.
	 *
	 * @return int|float|null Seconds.
	 */
	private static function now() {
		$now = class_exists( 'AB_MCP_Sync_Env', false ) && null !== AB_MCP_Sync_Env::$clock ? call_user_func( AB_MCP_Sync_Env::$clock ) : microtime( true );
		return is_int( $now ) || ( is_float( $now ) && is_finite( $now ) ) ? $now : null;
	}

	/**
	 * @return WP_Error The deadline is over.
	 */
	private static function late() {
		return new WP_Error( self::LATE, __( 'The time this call had for its reading ran out before the reading was done, so the reading stopped before its next step and what it read is not used. Call again; where it keeps failing, ask the host for a longer time limit of PHP (max_execution_time).', 'alphabridge-mcp' ) );
	}

	/**
	 * @return WP_Error The clock gave no time.
	 */
	private static function clock_unknown() {
		return new WP_Error( self::CLOCK, __( 'The time of this call could not be measured during its reading, so the reading stopped before its next step and what it read is not used. Call again.', 'alphabridge-mcp' ) );
	}

	/**
	 * @return WP_Error A deadline that is not one.
	 */
	private static function invalid() {
		return new WP_Error( self::INVALID, __( 'The time limit this reading was given is not a time (a finite number of seconds), so nothing was read. This is a fault of the code that asked for the reading, not of the site: call again, and where it keeps failing, update AlphaBridge MCP or report it to its makers.', 'alphabridge-mcp' ) );
	}
}
