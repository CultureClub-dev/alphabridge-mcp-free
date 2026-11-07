<?php
/**
 * The runs of the takeover and their steps (Z8, decisions 3, 15, 16, 18
 * and 20 of the plan of 01.11.2026; branch 8): the rows of the tables runs
 * and steps of AB_MCP_Sync_Schema, read and written here and nowhere else.
 *
 * A RUN (runs): type push (wp_site_push) or revert (wp_site_revert for the
 * pairs of a closed run); the source, the key and the print of the pair it
 * was made under; the report_hash of the report and the plan_hash of the
 * confirmed plan (the replay of decision 15: a confirmed call that meets an
 * open run of the same plan, account and connection gets that run); who
 * owns it (user_id and connection: next, step, status and abort are for
 * the same account over the same connection only); its state; created,
 * updated, closed, heartbeat (written with each step), expires; budget
 * (what it booked); open_one, 1 while the run is not closed and NULL once
 * it is, under the unique key of the table, so that at most one run per
 * site is not closed (decision 15; create() refuses with the open run);
 * forward_closed_at, set only by a forward takeover that wrote every
 * planned write step (decision 20: a qualified close); keep_until, the
 * time the backups of a closed run are kept until by the retention
 * contract as it held when the run closed (decision 20, rules 3, 5 and 6;
 * AB_MCP_Sync_Schema::keep_until()); calls, the calls
 * since the run was made (status asked, steps, repeats; the record of M2 and
 * M8); plan, what the run writes (AB_MCP_Sync_Push::plan()).
 *
 * STATES: open; interrupted (a step begun whose lock is no longer held,
 * settle(); decision 18: no next, no step, no new run on the site; only a
 * person closing it as checked, close_checked(), or wp_site_revert, which
 * leads the same run to reverting); reverting (the way back of this run
 * runs, decision 15); closed_done, closed_findings, closed_aborted,
 * closed_checked, closed_reverted. Every change of state is a conditional
 * UPDATE on the state the caller read (set_state()), so two requests cannot
 * both move it.
 *
 * A STEP (steps): seq from 1; pair_id (null for a step that makes a new
 * object until it did); op (check, write, post, verify, revert); phase
 * (1, 5, 7, 8 of decision 16; 0 for a step of the way back); depends (the
 * seqs it needs done first); state planned, begun, done, skipped, failed,
 * restored, unclear; parts, the journal of the write boundary (decision 17:
 * what the step writes, each partial mutation with its hash before, its
 * target hash, the reference of its backup, its state and its hash read
 * back; written ahead of each mutation); pair_before, the snapshot of the
 * pair before the step (AB_MCP_Sync_Pairs::snapshot(); decision 10); result;
 * time.
 *
 * Messages are English without gettext, as the other classes of the
 * takeover; each names the way.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-schema.php';
require_once __DIR__ . '/class-sync-pairs.php';
require_once __DIR__ . '/class-sync-canon.php';
require_once dirname( __DIR__ ) . '/class-staging-gate.php';
require_once dirname( __DIR__ ) . '/class-protected-store.php';
require_once dirname( __DIR__ ) . '/lifecycle/class-change-lock.php';
require_once __DIR__ . '/class-sync-guard.php';

/**
 * Class AB_MCP_Sync_Run
 */
final class AB_MCP_Sync_Run {

	/** The types of a run. */
	const TYPES = array( 'push', 'revert' );

	/** The states of a run that is not closed. */
	const OPEN_STATES = array( 'open', 'interrupted', 'reverting' );

	/** The states of a step. */
	const STEP_STATES = array( 'planned', 'begun', 'done', 'skipped', 'failed', 'restored', 'unclear' );

	/** The operations of a step. */
	const OPS = array( 'check', 'write', 'post', 'verify', 'revert' );

	/** Bytes of the plan of a run at most, as stored. */
	const MAX_PLAN = 8388608;

	/** Bytes of the journal of a step at most, as stored. */
	const MAX_PARTS = 8388608;

	/**
	 * Steps a plan has at most, forward or a way back of its own (the
	 * entries a tool call takes, AB_MCP_Security): what create() takes.
	 */
	const MAX_STEPS = 2000;

	/**
	 * The frame of a forward run besides its objects: the check before the
	 * first write, the caches, the check of the run, and one spare
	 * (AB_MCP_Sync_Push::MAX_OBJECTS = MAX_STEPS - FRAME objects).
	 */
	const FRAME = 4;

	/**
	 * Steps a run holds at most once the way back of the same run is added
	 * (decision 15: one step per written object): the forward plan and as
	 * many steps again as it has objects, FRAME + 2 × (MAX_STEPS - FRAME),
	 * so that the way back of a full run always has room (Codex round 1 of
	 * branch 8, B5; round 2, N4: the forward plan keeps its MAX_STEPS). The
	 * steps of the way back are small (the step they take back, the outcome
	 * per part); the journals of the written steps weigh as before.
	 */
	const MAX_RUN_STEPS = 2 * self::MAX_STEPS - self::FRAME;

	/** The columns of runs that hold numbers. */
	const INT_COLUMNS = array( 'run_id', 'user_id', 'created', 'updated', 'closed', 'heartbeat', 'expires', 'open_one', 'forward_closed_at', 'keep_until', 'calls' );

	/** The clock: a callable returning seconds, null for time(). Set by tests only. */
	public static $clock = null;

	/* -------------------------------------------------------------- read */

	/**
	 * One run by its id.
	 *
	 * @param int $run_id Run.
	 * @return array|null|WP_Error
	 */
	public static function get( $run_id ) {
		$ready = self::ready();
		if ( true !== $ready ) {
			return $ready;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is ours.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . AB_MCP_Sync_Schema::table( 'runs' ) . '` WHERE run_id = %d', (int) $run_id ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}
		return is_array( $row ) ? self::decode( $row ) : null;
	}

	/**
	 * The run of this site that is not closed, or null.
	 *
	 * @return array|null|WP_Error
	 */
	public static function open_run() {
		$ready = self::ready();
		if ( true !== $ready ) {
			return $ready;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is ours.
		$row = $wpdb->get_row( 'SELECT * FROM `' . AB_MCP_Sync_Schema::table( 'runs' ) . '` WHERE open_one = 1 LIMIT 1', ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}
		return is_array( $row ) ? self::decode( $row ) : null;
	}

	/**
	 * The runs of this site, newest first, a page at a time.
	 *
	 * @param int $after The run_id the page before ended with (0: from the newest).
	 * @param int $limit At most.
	 * @return array{runs:array[],next:int|null}|WP_Error
	 */
	public static function page( $after = 0, $limit = 50 ) {
		$ready = self::ready();
		if ( true !== $ready ) {
			return $ready;
		}
		global $wpdb;
		$limit = max( 1, min( 200, (int) $limit ) );
		$where = $after > 0 ? $wpdb->prepare( ' WHERE run_id < %d', (int) $after ) : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is ours; the values are prepared.
		$rows = $wpdb->get_results( 'SELECT * FROM `' . AB_MCP_Sync_Schema::table( 'runs' ) . '`' . $where . $wpdb->prepare( ' ORDER BY run_id DESC LIMIT %d', $limit + 1 ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return self::db_error();
		}
		$next = null;
		if ( count( $rows ) > $limit ) {
			$rows = array_slice( $rows, 0, $limit );
			$next = (int) $rows[ $limit - 1 ]['run_id'];
		}
		return array(
			'runs' => array_map( array( __CLASS__, 'decode' ), $rows ),
			'next' => $next,
		);
	}

	/**
	 * The runs of the way back that name a run (reverts in their plan): every
	 * run of the type revert made for the steps of that run once it was
	 * closed (decision 15), whatever its state. The plan of a further way
	 * back asks them, so that a step of the run goes back at most once
	 * (GitHub Codex round 1 of branch 8).
	 *
	 * @param int $run_id The run that wrote.
	 * @return array[]|WP_Error
	 */
	public static function reverts_of( $run_id ) {
		$ready = self::ready();
		if ( true !== $ready ) {
			return $ready;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is ours.
		$rows = $wpdb->get_results( 'SELECT * FROM `' . AB_MCP_Sync_Schema::table( 'runs' ) . "` WHERE type = 'revert' ORDER BY run_id ASC", ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return self::db_error();
		}
		$out = array();
		foreach ( $rows as $row ) {
			$run  = self::decode( $row );
			$plan = is_array( $run['plan'] ) ? $run['plan'] : array();
			if ( isset( $plan['reverts'] ) && is_array( $plan['reverts'] ) && in_array( (int) $run_id, array_map( 'intval', $plan['reverts'] ), true ) ) {
				$out[] = $run;
			}
		}
		return $out;
	}

	/**
	 * The steps of a run, by seq.
	 *
	 * @param int $run_id Run.
	 * @return array[]|WP_Error
	 */
	public static function steps( $run_id ) {
		$ready = self::ready();
		if ( true !== $ready ) {
			return $ready;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is ours.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . AB_MCP_Sync_Schema::table( 'steps' ) . '` WHERE run_id = %d ORDER BY seq ASC', (int) $run_id ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return self::db_error();
		}
		return array_map( array( __CLASS__, 'decode_step' ), $rows );
	}

	/**
	 * One step of a run.
	 *
	 * @param int $run_id Run.
	 * @param int $seq    Step.
	 * @return array|null|WP_Error
	 */
	public static function step( $run_id, $seq ) {
		$ready = self::ready();
		if ( true !== $ready ) {
			return $ready;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is ours.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . AB_MCP_Sync_Schema::table( 'steps' ) . '` WHERE run_id = %d AND seq = %d', (int) $run_id, (int) $seq ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}
		return is_array( $row ) ? self::decode_step( $row ) : null;
	}

	/* ------------------------------------------------------------- write */

	/**
	 * Make a run with its steps, open. Refused while another run of this
	 * site is not closed (the unique key of open_one holds it also against
	 * a request in between): the answer names that run and the way.
	 *
	 * @param array   $run   type, source_id, key_id, pair_print, report_hash,
	 *                       plan_hash, user_id, connection, expires, budget
	 *                       (array), plan (array).
	 * @param array[] $steps Each: op, phase, depends (int[]), pair_id
	 *                       (int|null), parts (array|null), result (array|null).
	 * @return array|WP_Error The run as stored, with its steps.
	 */
	public static function create( array $run, array $steps ) {
		$ready = self::ready();
		if ( true !== $ready ) {
			return $ready;
		}
		foreach ( array( 'type', 'source_id', 'key_id', 'pair_print', 'report_hash', 'plan_hash', 'user_id', 'connection' ) as $field ) {
			if ( ! array_key_exists( $field, $run ) ) {
				return new WP_Error( 'ab_mcp_sync_run_invalid', sprintf( 'A run needs %s. Pass it.', $field ) );
			}
		}
		if ( ! in_array( $run['type'], self::TYPES, true ) ) {
			return new WP_Error( 'ab_mcp_sync_run_invalid', 'A run is of the type push or revert. Pass one of them.' );
		}
		if ( array() === $steps || count( $steps ) > self::MAX_STEPS ) {
			return new WP_Error( 'ab_mcp_sync_run_invalid', sprintf( 'A run has 1 to %d steps. Choose fewer objects for one run.', self::MAX_STEPS ) );
		}
		$plan = self::encode_json( isset( $run['plan'] ) ? $run['plan'] : null, self::MAX_PLAN );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$budget = self::encode_json( isset( $run['budget'] ) ? $run['budget'] : null, self::MAX_PLAN );
		if ( is_wp_error( $budget ) ) {
			return $budget;
		}
		$open = self::open_run();
		if ( is_wp_error( $open ) ) {
			return $open;
		}
		if ( null !== $open ) {
			return self::another_open( $open );
		}
		// This site has a run from here on: the filters of the takeover lock
		// are hooked on its requests, before the first boundary can begin
		// (AB_MCP_Sync_Guard::arm(); N3).
		AB_MCP_Sync_Guard::mark();
		global $wpdb;
		$now = self::now();
		$row = array(
			'type'              => (string) $run['type'],
			'source_id'         => (string) $run['source_id'],
			'key_id'            => (string) $run['key_id'],
			'pair_print'        => (string) $run['pair_print'],
			'report_hash'       => (string) $run['report_hash'],
			'plan_hash'         => (string) $run['plan_hash'],
			'user_id'           => (int) $run['user_id'],
			'connection'        => (string) $run['connection'],
			'state'             => 'open',
			'created'           => $now,
			'updated'           => $now,
			'closed'            => 0,
			'heartbeat'         => $now,
			'expires'           => isset( $run['expires'] ) ? (int) $run['expires'] : 0,
			'budget'            => $budget,
			'open_one'          => 1,
			'forward_closed_at' => 0,
			'keep_until'        => 0,
			'calls'             => 1,
			'plan'              => $plan,
		);
		$formats = array();
		foreach ( array_keys( $row ) as $column ) {
			$formats[] = in_array( $column, self::INT_COLUMNS, true ) ? '%d' : '%s';
		}
		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert( AB_MCP_Sync_Schema::table( 'runs' ), $row, $formats );
		$wpdb->suppress_errors( $suppress );
		if ( false === $inserted ) {
			// A run another request made in between: the unique key refused it.
			$open = self::open_run();
			if ( is_array( $open ) ) {
				return self::another_open( $open );
			}
			return self::db_error();
		}
		$run_id = (int) $wpdb->insert_id;
		$seq    = 0;
		foreach ( $steps as $step ) {
			++$seq;
			$made = self::insert_step( $run_id, $seq, $step );
			if ( is_wp_error( $made ) ) {
				// The run cannot be used without its steps: closed as aborted,
				// with what went wrong, so the site is not held by it.
				self::set_state( $run_id, 'open', 'closed_aborted' );
				return $made;
			}
		}
		$made = self::get( $run_id );
		if ( is_array( $made ) ) {
			$made['steps'] = self::steps( $run_id );
		}
		return $made;
	}

	/**
	 * Add steps to a run that is not closed (the way back of the same run,
	 * decision 15), after its last step.
	 *
	 * @param int     $run_id Run.
	 * @param array[] $steps  As create() takes them.
	 * @return int|WP_Error The seq of the first step added.
	 */
	public static function append_steps( $run_id, array $steps ) {
		$have = self::steps( $run_id );
		if ( is_wp_error( $have ) ) {
			return $have;
		}
		if ( ! self::has_room( count( $have ), count( $steps ) ) ) {
			return new WP_Error( 'ab_mcp_sync_run_invalid', sprintf( 'A run has at most %d steps with its way back. Choose fewer pairs for one way back.', self::MAX_RUN_STEPS ) );
		}
		$seq   = array() === $have ? 0 : (int) end( $have )['seq'];
		$first = $seq + 1;
		foreach ( $steps as $step ) {
			++$seq;
			$made = self::insert_step( (int) $run_id, $seq, $step );
			if ( is_wp_error( $made ) ) {
				return $made;
			}
		}
		return $first;
	}

	/**
	 * Whether a run with $have steps takes $add more within MAX_RUN_STEPS
	 * (the way back of the same run). Asked before the way back changes the
	 * state of the run (AB_MCP_Sync_Revert::plan()), so that it never fails
	 * at this limit once the run is reverting (Codex round 1 of branch 8,
	 * B5); a run of wp_site_push always has the room (N4).
	 *
	 * @param int $have Steps the run has.
	 * @param int $add  Steps to add.
	 * @return bool
	 */
	public static function has_room( $have, $add ) {
		return (int) $have + (int) $add <= self::MAX_RUN_STEPS;
	}

	/**
	 * Move a run from one state to another, only while it is in the state
	 * the caller read (or one of them). A closed state frees open_one and
	 * sets closed; a qualified close sets forward_closed_at (decision 20)
	 * and every close stores keep_until, the time its backups are kept
	 * until by the retention contract as it holds now (rules 3, 5 and 6:
	 * the period a close gets stays with it when the edition changes).
	 *
	 * @param int             $run_id    Run.
	 * @param string|string[] $from      The state read.
	 * @param string          $to        The new state.
	 * @param bool            $qualified Whether the close is a qualified one (forward, every write step written).
	 * @return true|WP_Error ab_mcp_sync_run_changed where another request moved it first.
	 */
	public static function set_state( $run_id, $from, $to, $qualified = false ) {
		$ready = self::ready();
		if ( true !== $ready ) {
			return $ready;
		}
		if ( ! in_array( $to, array_merge( self::OPEN_STATES, AB_MCP_Sync_Schema::CLOSED ), true ) ) {
			return new WP_Error( 'ab_mcp_sync_run_invalid', sprintf( 'A run has no state %s. Use one of the states of AB_MCP_Sync_Run.', (string) $to ) );
		}
		global $wpdb;
		$now = self::now();
		$set = array(
			'state'   => (string) $to,
			'updated' => $now,
		);
		if ( in_array( $to, AB_MCP_Sync_Schema::CLOSED, true ) ) {
			$set['closed']     = $now;
			$set['open_one']   = null;
			$set['keep_until'] = AB_MCP_Sync_Schema::keep_until( $now, (bool) $qualified, AB_MCP_Protected_Store::backup_days() );
			if ( $qualified ) {
				$set['forward_closed_at'] = $now;
			}
		}
		$states = (array) $from;
		$where  = implode( ', ', array_fill( 0, count( $states ), '%s' ) );
		$sets   = array();
		foreach ( $set as $column => $value ) {
			$sets[] = null === $value ? "`$column` = NULL" : ( is_int( $value ) ? $wpdb->prepare( "`$column` = %d", $value ) : $wpdb->prepare( "`$column` = %s", $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the column names are ours.
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table and columns are ours; the values are prepared.
		$done = $wpdb->query( $wpdb->prepare( 'UPDATE `' . AB_MCP_Sync_Schema::table( 'runs' ) . '` SET ' . implode( ', ', $sets ) . " WHERE run_id = %d AND state IN ( $where )", array_merge( array( (int) $run_id ), $states ) ) );
		if ( false === $done || '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}
		if ( 1 !== (int) $done ) {
			$now_run = self::get( (int) $run_id );
			return new WP_Error( 'ab_mcp_sync_run_changed', sprintf( 'The run %1$d is no longer in the state this call read it in (%2$s; it is %3$s now): another call moved it in between. Ask for its status (wp_site_push action=status) and go on from there.', (int) $run_id, implode( ' or ', $states ), is_array( $now_run ) ? $now_run['state'] : 'gone' ) );
		}
		return true;
	}

	/**
	 * Note a sign of life of the run (its heartbeat), and count a call where
	 * asked (the record of M2 and M8: calls since the run was made).
	 *
	 * @param int  $run_id Run.
	 * @param bool $call   Whether to count a call.
	 * @return void
	 */
	public static function touch( $run_id, $call = false ) {
		if ( true !== self::ready() ) {
			return;
		}
		global $wpdb;
		$now = self::now();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is ours.
		$wpdb->query( $wpdb->prepare( 'UPDATE `' . AB_MCP_Sync_Schema::table( 'runs' ) . '` SET heartbeat = %d, updated = %d' . ( $call ? ', calls = calls + 1' : '' ) . ' WHERE run_id = %d', $now, $now, (int) $run_id ) );
	}

	/**
	 * Write the budget of a run (what it booked).
	 *
	 * @param int   $run_id Run.
	 * @param array $budget Budget.
	 * @return true|WP_Error
	 */
	public static function set_budget( $run_id, array $budget ) {
		$ready = self::ready();
		if ( true !== $ready ) {
			return $ready;
		}
		$json = self::encode_json( $budget, self::MAX_PLAN );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is ours.
		$done = $wpdb->query( $wpdb->prepare( 'UPDATE `' . AB_MCP_Sync_Schema::table( 'runs' ) . '` SET budget = %s, updated = %d WHERE run_id = %d', $json, self::now(), (int) $run_id ) );
		return false === $done || '' !== (string) $wpdb->last_error ? self::db_error() : true;
	}

	/**
	 * Write a step: its state (only from the state the caller read, where
	 * one is given), its journal, its pair, its result, its time.
	 *
	 * @param int         $run_id Run.
	 * @param int         $seq    Step.
	 * @param array       $set    state, parts (array), pair_id, pair_before (string), result (array).
	 * @param string|null $from   The state read; null for any.
	 * @return true|WP_Error ab_mcp_sync_run_changed where the step is no longer in $from.
	 */
	public static function update_step( $run_id, $seq, array $set, $from = null ) {
		$ready = self::ready();
		if ( true !== $ready ) {
			return $ready;
		}
		global $wpdb;
		$sets = array( $wpdb->prepare( 'time = %d', self::now() ) );
		foreach ( $set as $column => $value ) {
			switch ( $column ) {
				case 'state':
					if ( ! in_array( $value, self::STEP_STATES, true ) ) {
						return new WP_Error( 'ab_mcp_sync_run_invalid', sprintf( 'A step has no state %s. Use one of the states of AB_MCP_Sync_Run.', (string) $value ) );
					}
					$sets[] = $wpdb->prepare( 'state = %s', (string) $value );
					break;
				case 'pair_id':
					$sets[] = null === $value ? 'pair_id = NULL' : $wpdb->prepare( 'pair_id = %d', (int) $value );
					break;
				case 'pair_before':
					$sets[] = null === $value ? 'pair_before = NULL' : $wpdb->prepare( 'pair_before = %s', (string) $value );
					break;
				case 'parts':
				case 'result':
					$json = self::encode_json( $value, self::MAX_PARTS );
					if ( is_wp_error( $json ) ) {
						return $json;
					}
					$sets[] = null === $json ? "$column = NULL" : $wpdb->prepare( "$column = %s", $json ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the column name is ours.
					break;
				default:
					return new WP_Error( 'ab_mcp_sync_run_invalid', sprintf( '%s is not a field a step takes here. Pass state, parts, pair_id, pair_before or result.', (string) $column ) );
			}
		}
		$where = $wpdb->prepare( ' WHERE run_id = %d AND seq = %d', (int) $run_id, (int) $seq );
		if ( null !== $from ) {
			$where .= $wpdb->prepare( ' AND state = %s', (string) $from );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table and columns are ours; the values are prepared.
		$done = $wpdb->query( 'UPDATE `' . AB_MCP_Sync_Schema::table( 'steps' ) . '` SET ' . implode( ', ', $sets ) . $where );
		if ( false === $done || '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}
		if ( null !== $from && 1 !== (int) $done ) {
			$now_step = self::step( (int) $run_id, (int) $seq );
			return new WP_Error( 'ab_mcp_sync_run_changed', sprintf( 'Step %1$d of run %2$d is no longer in the state this call read it in (%3$s; it is %4$s now): another call moved it in between. Ask for the status of the run (wp_site_push action=status) and go on from there.', (int) $seq, (int) $run_id, (string) $from, is_array( $now_step ) ? $now_step['state'] : 'gone' ) );
		}
		return true;
	}

	/* -------------------------------------------------- the interruption */

	/**
	 * Whether a run with a step begun is interrupted (decision 18): the lock
	 * of the write boundary is no longer held (the row of
	 * AB_MCP_Change_Lock is missing or older than its TIMEOUT), so the
	 * request that began the step ended without finishing it. Such a run
	 * is moved to interrupted here, conditionally on its state; a step whose
	 * lock is still fresh is running (or another change of the code holds
	 * the lock), and the run stays as it is: the caller answers busy.
	 *
	 * @param array $run A run (get()).
	 * @return array|WP_Error The run as it is now, with 'running' where a step is begun under a fresh lock.
	 */
	public static function settle( array $run ) {
		if ( ! in_array( $run['state'], array( 'open', 'reverting' ), true ) ) {
			return $run;
		}
		$steps = self::steps( (int) $run['run_id'] );
		if ( is_wp_error( $steps ) ) {
			return $steps;
		}
		$begun = null;
		foreach ( $steps as $step ) {
			if ( 'begun' === $step['state'] ) {
				$begun = $step;
			}
		}
		if ( null === $begun ) {
			return $run;
		}
		if ( self::lock_fresh() ) {
			$run['running'] = (int) $begun['seq'];
			return $run;
		}
		$moved = self::set_state( (int) $run['run_id'], $run['state'], 'interrupted' );
		if ( is_wp_error( $moved ) && 'ab_mcp_sync_run_changed' !== $moved->get_error_code() ) {
			return $moved;
		}
		$now = self::get( (int) $run['run_id'] );
		return is_array( $now ) ? $now : $run;
	}

	/**
	 * Whether the lock of AB_MCP_Change_Lock is held fresh right now: its row
	 * is there and younger than TIMEOUT (decision 18: a lock left behind
	 * proves no end of the request, so a fresh one counts as held).
	 *
	 * @return bool
	 */
	public static function lock_fresh() {
		if ( AB_MCP_Change_Lock::held() ) {
			return true;
		}
		$since = (int) get_option( AB_MCP_Change_Lock::OPTION, 0 );
		return $since > 0 && ( self::now() - $since ) < AB_MCP_Change_Lock::TIMEOUT;
	}

	/**
	 * Close an interrupted run as checked (decision 18, way a): a person
	 * looked at the journal and closes it; nothing is written to the site,
	 * and every pair with a part left unclear becomes unclear. For the
	 * page «Staging» of branch 12 (the nonce is checked there; this method
	 * takes the result of that check); no tool calls it.
	 *
	 * @param int  $run_id    Run.
	 * @param int  $user_id   The person.
	 * @param bool $confirmed True only after a nonce of a person in wp-admin was checked.
	 * @return array|WP_Error The run as stored.
	 */
	public static function close_checked( $run_id, $user_id, $confirmed ) {
		if ( true !== $confirmed ) {
			return new WP_Error( 'ab_mcp_sync_run_unconfirmed', 'Closing an interrupted run as checked takes a person in wp-admin (page AlphaBridge → Staging): pass true only for a request whose nonce was checked there.' );
		}
		$run = self::get( $run_id );
		if ( is_wp_error( $run ) || null === $run ) {
			return is_wp_error( $run ) ? $run : self::unknown( $run_id );
		}
		if ( 'interrupted' !== $run['state'] ) {
			return new WP_Error( 'ab_mcp_sync_run_not_interrupted', sprintf( 'Only an interrupted run is closed as checked; this run is %s. Use wp_site_revert for the way back of a run that is open or closed.', $run['state'] ) );
		}
		$steps = self::steps( $run_id );
		if ( is_wp_error( $steps ) ) {
			return $steps;
		}
		foreach ( $steps as $step ) {
			if ( 'begun' !== $step['state'] ) {
				continue;
			}
			// The step stays what it was: unclear, its parts as the journal
			// has them (a part written but not read back is unclear).
			self::update_step( $run_id, (int) $step['seq'], array( 'state' => 'unclear', 'result' => array( 'closed_checked_by' => (int) $user_id ) ), 'begun' );
			if ( null !== $step['pair_id'] ) {
				$pair = AB_MCP_Sync_Pairs::get( (int) $step['pair_id'] );
				if ( is_array( $pair ) && 'active' === $pair['state'] ) {
					AB_MCP_Sync_Pairs::set_state( (int) $pair['id'], 'unclear', AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( (int) $pair['id'] ) ) );
				}
			}
		}
		$moved = self::set_state( $run_id, 'interrupted', 'closed_checked' );
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}
		$closed = self::get( $run_id );
		return is_array( $closed ) ? $closed : self::unknown( $run_id );
	}

	/* --------------------------------------------------------- ownership */

	/**
	 * Why the current account over this connection does not own a run, or
	 * null where it does (decision 15: next, step, status and abort are for
	 * the same account over the same connection).
	 *
	 * @param array $run Run.
	 * @return WP_Error|null
	 */
	public static function owner_refusal( array $run ) {
		if ( (int) $run['user_id'] !== (int) get_current_user_id() || ! hash_equals( (string) $run['connection'], self::connection() ) ) {
			return new WP_Error( 'ab_mcp_sync_run_foreign', sprintf( 'The run %d belongs to another account or connection, and only the one that made it goes on with it, asks its status or aborts it. Use that connection, or wait until the run is closed; a person with manage_options sees it on the page AlphaBridge → Staging.', (int) $run['run_id'] ), array( 'run_id' => (int) $run['run_id'] ) );
		}
		return null;
	}

	/**
	 * The connection of the call, as a run is bound to it (decision 15): a
	 * hash of AB_MCP_Staging_Gate::connection().
	 *
	 * @return string
	 */
	public static function connection() {
		$conn = AB_MCP_Staging_Gate::connection();
		return hash( 'sha256', 'ab-sync-run|' . $conn['kind'] . ':' . $conn['id'] );
	}

	/* ----------------------------------------------------------- shapes */

	/**
	 * A row of runs as the callers read it: numbers as ints, budget and
	 * plan decoded.
	 *
	 * @param array $raw Row.
	 * @return array
	 */
	public static function decode( array $raw ) {
		foreach ( self::INT_COLUMNS as $column ) {
			if ( array_key_exists( $column, $raw ) ) {
				$raw[ $column ] = null === $raw[ $column ] ? null : (int) $raw[ $column ];
			}
		}
		foreach ( array( 'budget', 'plan' ) as $column ) {
			$raw[ $column ] = isset( $raw[ $column ] ) && is_string( $raw[ $column ] ) ? json_decode( $raw[ $column ], true, 64 ) : null;
		}
		return $raw;
	}

	/**
	 * A row of steps as the callers read it.
	 *
	 * @param array $raw Row.
	 * @return array
	 */
	public static function decode_step( array $raw ) {
		foreach ( array( 'run_id', 'seq', 'phase', 'time' ) as $column ) {
			$raw[ $column ] = (int) $raw[ $column ];
		}
		$raw['pair_id'] = null === $raw['pair_id'] ? null : (int) $raw['pair_id'];
		foreach ( array( 'depends', 'parts', 'result' ) as $column ) {
			$raw[ $column ] = isset( $raw[ $column ] ) && is_string( $raw[ $column ] ) ? json_decode( $raw[ $column ], true, 64 ) : null;
		}
		$raw['depends'] = is_array( $raw['depends'] ) ? array_map( 'intval', $raw['depends'] ) : array();
		return $raw;
	}

	/**
	 * What a run shows of itself (status): the run without its plan, with
	 * its steps, each with its journal, and the rests it left.
	 *
	 * @param array $run Run.
	 * @return array|WP_Error
	 */
	public static function shown( array $run ) {
		$steps = self::steps( (int) $run['run_id'] );
		if ( is_wp_error( $steps ) ) {
			return $steps;
		}
		$out   = array(
			'run_id'            => (int) $run['run_id'],
			'type'              => $run['type'],
			'state'             => $run['state'],
			'report_hash'       => $run['report_hash'],
			'created'           => (int) $run['created'],
			'updated'           => (int) $run['updated'],
			'closed'            => (int) $run['closed'],
			'heartbeat'         => (int) $run['heartbeat'],
			'calls'             => (int) $run['calls'],
			'duration'          => ( (int) $run['closed'] > 0 ? (int) $run['closed'] : self::now() ) - (int) $run['created'],
			'forward_closed_at' => (int) $run['forward_closed_at'],
			'keep_until'        => (int) $run['keep_until'],
			'steps'             => array(),
			'counts'            => array_fill_keys( self::STEP_STATES, 0 ),
			'rests'             => array(),
			'unclear'           => array(),
		);
		foreach ( $steps as $step ) {
			++$out['counts'][ $step['state'] ];
			$shown = array(
				'seq'     => (int) $step['seq'],
				'op'      => $step['op'],
				'phase'   => (int) $step['phase'],
				'state'   => $step['state'],
				'depends' => $step['depends'],
				'pair_id' => $step['pair_id'],
				'parts'   => is_array( $step['parts'] ) ? self::parts_shown( $step['parts'] ) : null,
				'result'  => $step['result'],
				'time'    => (int) $step['time'],
			);
			$out['steps'][] = $shown;
			if ( is_array( $step['result'] ) && isset( $step['result']['rest'] ) ) {
				$out['rests'][] = $step['result']['rest'];
			}
			if ( 'unclear' === $step['state'] ) {
				$out['unclear'][] = (int) $step['seq'];
			}
		}
		return $out;
	}

	/**
	 * The journal of a step as shown: each part with its name and states,
	 * hashes and the name of its backup; never a value.
	 *
	 * @param array $parts Journal.
	 * @return array
	 */
	private static function parts_shown( array $parts ) {
		$out = array();
		foreach ( isset( $parts['parts'] ) && is_array( $parts['parts'] ) ? $parts['parts'] : array() as $part ) {
			$out[] = array_intersect_key( (array) $part, array_flip( array( 'part', 'state', 'before', 'target', 'after', 'backup', 'way', 'note' ) ) );
		}
		return array(
			'object' => isset( $parts['object'] ) ? $parts['object'] : null,
			'backup' => isset( $parts['backup'] ) && is_array( $parts['backup'] ) ? array_intersect_key( $parts['backup'], array_flip( array( 'name', 'sha256', 'bytes', 'absent' ) ) ) : null,
			'parts'  => $out,
		);
	}

	/* ------------------------------------------------------------ private */

	/**
	 * @param int   $run_id Run.
	 * @param int   $seq    Step.
	 * @param array $step   Step as create() takes it.
	 * @return true|WP_Error
	 */
	private static function insert_step( $run_id, $seq, array $step ) {
		if ( ! isset( $step['op'] ) || ! in_array( $step['op'], self::OPS, true ) ) {
			return new WP_Error( 'ab_mcp_sync_run_invalid', sprintf( 'Step %d has no operation of check, write, post, verify or revert. Pass one of them.', (int) $seq ) );
		}
		$depends = isset( $step['depends'] ) && is_array( $step['depends'] ) ? array_values( array_map( 'intval', $step['depends'] ) ) : array();
		$parts   = self::encode_json( isset( $step['parts'] ) ? $step['parts'] : null, self::MAX_PARTS );
		if ( is_wp_error( $parts ) ) {
			return $parts;
		}
		$result = self::encode_json( isset( $step['result'] ) ? $step['result'] : null, self::MAX_PARTS );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		global $wpdb;
		$row     = array(
			'run_id'      => (int) $run_id,
			'seq'         => (int) $seq,
			'pair_id'     => isset( $step['pair_id'] ) && null !== $step['pair_id'] ? (int) $step['pair_id'] : null,
			'op'          => (string) $step['op'],
			'phase'       => isset( $step['phase'] ) ? (int) $step['phase'] : 0,
			'depends'     => (string) wp_json_encode( $depends ),
			'state'       => 'planned',
			'parts'       => $parts,
			'pair_before' => null,
			'result'      => $result,
			'time'        => self::now(),
		);
		$formats = array( '%d', '%d', null === $row['pair_id'] ? null : '%d', '%s', '%d', '%s', '%s', null === $parts ? null : '%s', null, null === $result ? null : '%s', '%d' );
		$done    = $wpdb->insert( AB_MCP_Sync_Schema::table( 'steps' ), $row, $formats );
		return false === $done ? self::db_error() : true;
	}

	/**
	 * @param mixed $value Value.
	 * @param int   $max   Bytes at most.
	 * @return string|null|WP_Error JSON in the one spelling of the canon; null for null.
	 */
	private static function encode_json( $value, $max ) {
		if ( null === $value ) {
			return null;
		}
		$json = AB_MCP_Sync_Canon::encode( $value );
		if ( '' === $json ) {
			return new WP_Error( 'ab_mcp_sync_run_invalid', 'A value of the run cannot be written as JSON. Pass values JSON can hold.' );
		}
		if ( strlen( $json ) > $max ) {
			return new WP_Error( 'ab_mcp_sync_run_too_large', sprintf( 'A record of the run holds at most %1$d bytes as stored, and this one would hold %2$d. Choose fewer objects for one run.', $max, strlen( $json ) ) );
		}
		return $json;
	}

	/**
	 * @param array $open The run that is not closed.
	 * @return WP_Error
	 */
	private static function another_open( array $open ) {
		$way = 'interrupted' === $open['state']
			? 'It was interrupted (a step began and its request ended): a person closes it as checked on the page AlphaBridge → Staging after reading its journal, or wp_site_revert with its run_id takes its own parts back; then a new run can begin.'
			: 'Go on with it (wp_site_push action=next or action=status with its run_id), abort it (action=abort), or wait until it is closed; then a new run can begin.';
		return new WP_Error(
			'ab_mcp_sync_run_open',
			sprintf( 'This site has a run that is not closed (run %1$d, %2$s, state %3$s), and at most one run per site is open at a time. Ask for its status (wp_site_push action=status). %4$s', (int) $open['run_id'], $open['type'], $open['state'], $way ),
			array(
				'run_id' => (int) $open['run_id'],
				'state'  => $open['state'],
				'type'   => $open['type'],
			)
		);
	}

	/**
	 * @param int $run_id Run.
	 * @return WP_Error
	 */
	public static function unknown( $run_id ) {
		return new WP_Error( 'ab_mcp_sync_run_unknown', sprintf( 'This site has no run %d. Ask for the runs with wp_site_push action=status without a run_id (the open run), or plan one (action=plan).', (int) $run_id ) );
	}

	/**
	 * @return true|WP_Error Whether the tables can be used.
	 */
	private static function ready() {
		$status = AB_MCP_Sync_Schema::status();
		if ( $status['available'] ) {
			return true;
		}
		return new WP_Error( 'ab_mcp_staging_unavailable', sprintf( 'The tables of the takeover are not available on this site (%s): no run begins. Deactivate and activate AlphaBridge in wp-admin so the tables are made, or ask the host to let the database user create tables.', (string) $status['reason'] ), array( 'reason' => $status['reason'] ) );
	}

	/**
	 * @return WP_Error The database refused a read or write.
	 */
	private static function db_error() {
		global $wpdb;
		return new WP_Error( 'ab_mcp_sync_run_db', 'The database refused a read or write of the runs of the takeover; nothing more was changed. Try again; if it keeps failing, the database error log names the cause.', array( 'db_error' => is_object( $wpdb ) && isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '' ) );
	}

	/**
	 * @return int Seconds.
	 */
	public static function now() {
		return null !== self::$clock ? (int) call_user_func( self::$clock ) : time();
	}
}
