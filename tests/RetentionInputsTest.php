<?php
/**
 * The inputs of the retention decision of the protected store as the
 * database gives them (Z8b branch 3, Behebung 3): AB_MCP_Sync_Schema::
 * open_runs() and backup_rows() read every field they decide by whole, by
 * the table of the run rows (type, state and field), or give null
 * ("unknown"); keep_until() stores no period made of days or a time that
 * are not known. A stand-in of $wpdb gives the rows as the database does,
 * as strings, also those a real column of a bigint never holds.
 *
 * The table of the run rows (from the writers, AB_MCP_Sync_Run::create()
 * and set_state(), AB_MCP_Sync_Push, AB_MCP_Sync_Revert):
 * - every row: run_id a whole number of 1 or more; state one of
 *   AB_MCP_Sync_Run::OPEN_STATES and AB_MCP_Sync_Schema::CLOSED;
 * - a row not closed: type push or revert (AB_MCP_Sync_Run::TYPES); the
 *   plan of a revert run holds reverts, a list of whole numbers of 1 or
 *   more (AB_MCP_Sync_Revert); a push run names no runs, its plan is not
 *   needed (and may be NULL, a run made without a plan);
 * - a closed row: keep_until and forward_closed_at whole numbers of 0 or
 *   more (0: no period stored, kept; not a qualified close).
 * Anything else is unknown: the whole answer is null, and the clean-up
 * keeps the backups of every run (AB_MCP_Protected_Store::kept()).
 *
 * @package AlphaBridge_MCP
 */

declare( strict_types = 1 );

namespace AlphaBridge\Tests;

use AB_MCP_Shape;
use AB_MCP_Store_Inputs;
use AB_MCP_Sync_Schema;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

require_once __DIR__ . '/sync-stubs.php';
require_once __DIR__ . '/../includes/class-protected-store.php';

/**
 * A stand-in of $wpdb for the table of the runs: the rows of the query of
 * backup_rows(), the plan of one run, the ids of open_runs(). A query that
 * fails answers as wpdb does: get_results() and get_col() with an empty
 * array (wpdb::flush() empties last_result), get_var() with null, and only
 * last_error says it failed ("an empty list is no error").
 */
final class AbTestRunsWpdb {
	public $prefix     = 'wp_';
	public $last_error = '';
	/** @var array<int,array> The rows, as the database gives them. */
	public $rows = array();
	/** @var array<string,mixed> Plan by run_id as written in the query. */
	public $plans = array();
	/** @var string|null An error the next query of this kind sets. */
	public $fail = null;
	/** @var string[] The queries. */
	public $queries = array();

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$query = (string) preg_replace( '/%[sd]/', '%' === $arg ? '' : "'" . addslashes( (string) $arg ) . "'", $query, 1 );
		}
		return $query;
	}

	private function failing( $kind ) {
		$this->last_error = $this->fail === $kind ? 'gone away' : '';
		return '' !== $this->last_error;
	}

	public function get_results( $query, $output = null ) {
		$this->queries[] = (string) $query;
		return $this->failing( 'rows' ) ? array() : $this->rows;
	}

	public function get_var( $query ) {
		$this->queries[] = (string) $query;
		if ( $this->failing( 'plan' ) ) {
			return null;
		}
		return preg_match( "/WHERE run_id = '([^']*)'/", (string) $query, $m ) && array_key_exists( $m[1], $this->plans ) ? $this->plans[ $m[1] ] : null;
	}

	public function get_col( $query, $x = 0 ) {
		$this->queries[] = (string) $query;
		if ( $this->failing( 'ids' ) ) {
			return array();
		}
		$out = array();
		foreach ( $this->rows as $row ) {
			if ( ! in_array( $row['state'] ?? null, AB_MCP_Sync_Schema::CLOSED, true ) ) {
				$out[] = $row['run_id'] ?? null;
			}
		}
		return $out;
	}
}

final class RetentionInputsTest extends TestCase {

	/** @var mixed The $wpdb before. */
	private $wpdb_before;

	private AbTestRunsWpdb $db;

	protected function setUp(): void {
		ab_test_reset();
		$this->wpdb_before = $GLOBALS['wpdb'] ?? null;
		$this->db          = new AbTestRunsWpdb();
		$GLOBALS['wpdb']   = $this->db;
		self::schema_status( array( 'available' => true, 'reason' => null ) );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_before;
		self::schema_status( null );
	}

	private static function schema_status( $value ): void {
		( new ReflectionProperty( AB_MCP_Sync_Schema::class, 'status' ) )->setValue( null, $value );
	}

	/** A row as the database gives it: every field a string. */
	private static function row( string $id, string $type, string $state, string $keep_until = '0', string $forward = '0' ): array {
		return array(
			'run_id'            => $id,
			'type'              => $type,
			'state'             => $state,
			'forward_closed_at' => $forward,
			'keep_until'        => $keep_until,
		);
	}

	/** The rows of a normal history: closed runs and a way back that is open. */
	private function history( int $now ): void {
		$this->db->rows  = array(
			self::row( '1', 'push', 'closed_done', (string) ( $now - 10 ), (string) ( $now - 40 * 86400 ) ),
			self::row( '2', 'push', 'closed_done', (string) ( $now - 10 ), (string) ( $now - 30 * 86400 ) ),
			self::row( '3', 'push', 'closed_done', (string) ( $now - 10 ), (string) ( $now - 20 * 86400 ) ),
			self::row( '4', 'push', 'closed_done', (string) ( $now - 10 ), (string) ( $now - 10 * 86400 ) ),
			self::row( '5', 'push', 'closed_aborted', (string) ( $now + 86400 ) ),
			self::row( '6', 'push', 'closed_aborted', '0' ),
			self::row( '7', 'push', 'closed_aborted', (string) ( $now - 1 ) ),
			self::row( '8', 'push', 'open' ),
			self::row( '9', 'revert', 'reverting' ),
		);
		$this->db->plans = array( '9' => '{"items":[],"reverts":[7]}' );
	}

	public function testTheRunsOfANormalHistoryKeepWhatTheContractKeeps(): void {
		$now = 1800000000;
		$this->history( $now );
		self::assertSame(
			array(
				1 => false,
				2 => true,
				3 => true,
				4 => true,
				5 => true,
				6 => true,
				7 => true,
				8 => true,
				9 => true,
			),
			AB_MCP_Sync_Schema::backup_rows( $now ),
			'The last three qualified closes, a period not reached, no period stored (N1), the runs not closed, and the run the open way back names.'
		);
		self::assertSame( array( 8, 9 ), AB_MCP_Sync_Schema::open_runs() );
		self::assertNotContains( "SELECT plan FROM `wp_ab_mcp_staging_runs` WHERE run_id = '8'", $this->db->queries, 'The plan of a push run is not read: it names no runs.' );
		// A push run with a plan that is no JSON: not needed, still valid.
		$this->db->plans['8'] = '{"objects":';
		self::assertIsArray( AB_MCP_Sync_Schema::backup_rows( $now ) );
		// A way back without runs to name is no row the writers make.
		unset( $this->db->rows[6] );
		$this->db->plans['9'] = '{"items":[],"reverts":[10]}';
		self::assertTrue( AB_MCP_Sync_Schema::backup_rows( $now )[10], 'A run the way back needs is kept also without a row.' );
	}

	public function testAWayBackWhosePlanIsNotWholeMakesTheAnswerUnknown(): void {
		// The case of the confirmation of the list (Codex, 18:15): a plan
		// that is no JSON was read as no runs, so the run it names went.
		$now = 1800000000;
		foreach ( array(
			'no JSON'               => '{"reverts":',
			'NULL'                  => null,
			'no object'             => '"10"',
			'reverts no list'       => '{"reverts":"10"}',
			'reverts empty'         => '{"reverts":[]}',
			'no reverts'            => '{"items":[]}',
			'a run as a string'     => '{"reverts":["10"]}',
			'a run of 0'            => '{"reverts":[0]}',
			'a run below 0'         => '{"reverts":[-10]}',
			'a run of no whole'     => '{"reverts":[10.5]}',
			'a run of no number'    => '{"reverts":[null]}',
			'too deep for the canon' => str_repeat( '[', 70 ) . str_repeat( ']', 70 ),
		) as $why => $plan ) {
			$this->db->rows  = array( self::row( '10', 'push', 'closed_aborted', (string) ( $now - 1 ) ), self::row( '11', 'revert', 'open' ) );
			$this->db->plans = array( '11' => $plan );
			self::assertNull( AB_MCP_Sync_Schema::backup_rows( $now ), "$why: unknown." );
			self::assertNull( AB_MCP_Sync_Schema::backup_runs( $now ), "$why: unknown." );
		}
		$this->db->plans = array( '11' => '{"reverts":[10]}' );
		self::assertSame( array( 10 => true, 11 => true ), AB_MCP_Sync_Schema::backup_rows( $now ), 'The plan whole: run 10 kept.' );
	}

	public function testARowWhoseFieldsAreNotWholeMakesTheAnswerUnknown(): void {
		$now  = 1800000000;
		$good = self::row( '7', 'push', 'closed_aborted', (string) ( $now - 1 ), '0' );
		foreach ( array(
			'run_id'            => array( 'abc', '05', '-1', '0', '99999999999999999999', '', ' 7', '7.0', null, 7.0 ),
			'state'             => array( 'weird', '', 'CLOSED_DONE', null, 1 ),
			'keep_until'        => array( '1.5', 'x', '-1', '', '01', null, 1.5 ),
			'forward_closed_at' => array( '1.5', 'x', '-1', '', '01', null ),
		) as $field => $values ) {
			foreach ( $values as $value ) {
				$this->db->rows = array( array( $field => $value ) + $good );
				self::assertNull( AB_MCP_Sync_Schema::backup_rows( $now ), "$field " . var_export( $value, true ) . ': unknown.' );
			}
			$this->db->rows = array( array_diff_key( $good, array( $field => 0 ) ) );
			self::assertNull( AB_MCP_Sync_Schema::backup_rows( $now ), "$field missing: unknown." );
		}
		// The type decides for a row that is not closed (whether a plan names
		// runs); for a closed row it is not needed.
		foreach ( array( 'pull', '', null ) as $type ) {
			$this->db->rows = array( self::row( '8', 'push', 'open' ) );
			$this->db->rows[0]['type'] = $type;
			self::assertNull( AB_MCP_Sync_Schema::backup_rows( $now ), 'type ' . var_export( $type, true ) . ' of an open row: unknown.' );
			$this->db->rows = array( array( 'type' => $type ) + $good );
			self::assertSame( array( 7 => false ), AB_MCP_Sync_Schema::backup_rows( $now ), 'Of a closed row: not needed.' );
		}
		// keep_until and forward_closed_at decide for a closed row only.
		$this->db->rows = array( array( 'keep_until' => 'x', 'forward_closed_at' => 'y' ) + self::row( '8', 'push', 'open' ) );
		self::assertSame( array( 8 => true ), AB_MCP_Sync_Schema::backup_rows( $now ), 'Of an open row: not needed.' );
		// Integers, as a driver may give them, are the same numbers.
		$this->db->rows = array( array( 'run_id' => 7, 'keep_until' => $now - 1, 'forward_closed_at' => 0 ) + $good );
		self::assertSame( array( 7 => false ), AB_MCP_Sync_Schema::backup_rows( $now ) );
	}

	public function testADatabaseThatFailsOrATimeNotKnownMakesTheAnswerUnknown(): void {
		$now = 1800000000;
		$this->history( $now );
		foreach ( array( 'rows', 'plan' ) as $kind ) {
			$this->db->fail = $kind;
			self::assertNull( AB_MCP_Sync_Schema::backup_rows( $now ), "the read of the $kind fails: unknown." );
		}
		$this->db->fail = 'ids';
		self::assertNull( AB_MCP_Sync_Schema::open_runs(), 'the read of the open runs fails: unknown.' );
		$this->db->fail = null;
		foreach ( array( 0, -1, '1800000000', 1.8e9 ) as $at ) {
			self::assertNull( AB_MCP_Sync_Schema::backup_rows( $at ), 'now ' . var_export( $at, true ) . ': unknown.' );
		}
		self::schema_status( array( 'available' => false, 'reason' => 'missing' ) );
		self::assertNull( AB_MCP_Sync_Schema::backup_rows( $now ), 'no table: unknown.' );
		self::assertNull( AB_MCP_Sync_Schema::open_runs(), 'no table: unknown.' );
	}

	public function testTheOpenRunsAreTheirIdsWholeOrUnknown(): void {
		foreach ( array( 'abc', '05', '-1', '0', '99999999999999999999', '', null, 1.5 ) as $id ) {
			$this->db->rows = array( array( 'run_id' => $id ) + self::row( '1', 'push', 'open' ) );
			self::assertNull( AB_MCP_Sync_Schema::open_runs(), 'id ' . var_export( $id, true ) . ': unknown.' );
		}
		// A row of a state the store does not know counts as not closed: its
		// run's groups stay (open_runs() holds it), and backup_rows() is
		// unknown (testARowWhoseFieldsAreNotWholeMakesTheAnswerUnknown).
		$this->db->rows = array( self::row( '12', 'push', 'weird' ) );
		self::assertSame( array( 12 ), AB_MCP_Sync_Schema::open_runs() );
	}

	public function testAPeriodIsStoredOnlyOfDaysAndATimeThatAreKnown(): void {
		$now = 1800000000;
		self::assertSame( $now + 30 * 86400, AB_MCP_Sync_Schema::keep_until( $now, true, 30 ) );
		self::assertSame( $now, AB_MCP_Sync_Schema::keep_until( $now, true, 0 ), 'Free: a qualified close ends at the close (the last three keep it).' );
		self::assertSame( $now + 7 * 86400, AB_MCP_Sync_Schema::keep_until( $now, false, 0 ) );
		self::assertSame( $now + 30 * 86400, AB_MCP_Sync_Schema::keep_until( $now, false, 30 ) );
		foreach ( array( null, -1, 3651, '30 days', '030', 30.0, true ) as $days ) {
			self::assertSame( 0, AB_MCP_Sync_Schema::keep_until( $now, false, $days ), 'days ' . var_export( $days, true ) . ': no period, kept (N1).' );
		}
		// The days as their row of AB_MCP_Store_Inputs::TABLE takes them
		// (period.days, as the filter gives them): the digits of a whole
		// number are the number, without loss (Behebung 4).
		self::assertSame( $now + 30 * 86400, AB_MCP_Sync_Schema::keep_until( $now, false, '30' ) );
		foreach ( array( 0, -1, '1800000000', null ) as $at ) {
			self::assertSame( 0, AB_MCP_Sync_Schema::keep_until( $at, false, 30 ), 'time ' . var_export( $at, true ) . ': no period, kept (N1).' );
		}
	}

	public function testAWholeNumberIsReadWithoutLossOrNotAtAll(): void {
		// The form of a run of a column of the database (DB_RUN; Behebung 4:
		// AB_MCP_Shape::read() in place of whole_number()).
		$form = AB_MCP_Store_Inputs::DB_RUN;
		$min0 = array_replace( $form, array( 'min' => 0 ) );
		foreach ( array( array( '7', $form, 7 ), array( 7, $form, 7 ), array( '0', $min0, 0 ), array( (string) PHP_INT_MAX, $form, PHP_INT_MAX ) ) as $case ) {
			self::assertSame( $case[2], AB_MCP_Shape::read( $case[0], $case[1] ) );
		}
		foreach ( array( '0', '-1', '05', ' 5', '5 ', '+5', '5.0', '5abc', '', '99999999999999999999', 5.0, true, null, array( 5 ) ) as $value ) {
			self::assertNull( AB_MCP_Shape::read( $value, $form ), var_export( $value, true ) );
		}
	}
}
