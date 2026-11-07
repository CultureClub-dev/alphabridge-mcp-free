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
 * STATES: open; interrupted (a step begun by a request that ended before
 * it ended the step, settle(); decision 18: no next, no step, no new run on
 * the site; only a person closing it as checked, close_checked(), or
 * wp_site_revert, which leads the same run to reverting: its way back
 * begins, or goes on where it had begun); reverting (the way back of this
 * run runs, decision 15); closed_done, closed_findings, closed_aborted,
 * closed_checked, closed_reverted. Every change of state is a conditional
 * UPDATE on the state the caller read (set_state()), so two requests cannot
 * both move it.
 *
 * ONE LOCK PER CALL (decision 18; the decision of 07.11.2026 evening,
 * Variante A): every call that can change a run (next and step, abort, the
 * begin and the continuation of a way back, closing as checked, and what
 * closes a run on the way) runs under the lock of the write boundary
 * (AB_MCP_Change_Lock) from its first read of the run to its answer
 * (under_lock()), reads the run and its steps under it and decides only
 * then. No two such calls run at the same time, and no step is begun or
 * ended outside one. Another request holding the lock refuses the call as
 * busy before it reads or writes anything of the run. Before, each change
 * took the lock for itself (a write step, a step of the way back, the
 * abort, the begin of the way back), and GitHub Codex found a change of a
 * run outside it in three rounds (3, 4 and 6 on free#137).
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
	const STEP_STATES = array( 'planned', 'begun', 'done', 'skipped', 'failed', 'unclear' );

	/**
	 * Bytes of values before (value_before of the parts left; the decision of
	 * 07.11.2026) one answer carries at most, the newest steps first; past
	 * it, a value is replaced by VALUE_CUT (cap_values()).
	 */
	const ANSWER_VALUE_BYTES = 16384;

	/** What stands for a value before the answer has no room for. */
	const VALUE_CUT = '(cut: an answer carries at most 16384 bytes of values before; the backup of this step in the protected store holds the object as it was before the step)';

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

	/**
	 * The steps this request began under the lock of its call (update_step()
	 * to begun while AB_MCP_Change_Lock is held): run id => seq => true. Its
	 * own call, which settle() never takes as interrupted; forgotten when the
	 * call gives the lock back (under_lock()).
	 *
	 * @var array<int,array<int,true>>
	 */
	private static $own = array();

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
		// The run and every one of its steps in one transaction: a run is
		// there with all its steps or not at all (a step that cannot be
		// stored, a request that ends between two inserts; GitHub Codex round
		// 2 of branch 8, P1: a run of the way back with a part of its steps
		// would close as reverted).
		$run_id = self::transaction(
			static function () use ( $row, $formats, $steps ) {
				global $wpdb;
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
						return $made;
					}
				}
				return $run_id;
			}
		);
		if ( is_wp_error( $run_id ) ) {
			return $run_id;
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
	 * Writes of a run and its steps in one transaction of the database:
	 * committed when $fn returns anything but a WP_Error, rolled back
	 * otherwise, on an exception and, by the database itself, when the
	 * request ends before the commit. A run is made with all its steps or
	 * not at all (create()), and the way back of a run that is not closed
	 * moves the run to reverting, settles its forward steps and stores every
	 * step of the way back, or changes nothing (AB_MCP_Sync_Revert::begin();
	 * GitHub Codex round 2 of branch 8, P1: before, an insert that failed or
	 * a request that ended between the inserts left the run reverting with a
	 * part of its plan of the way back, and close_revert() reported it
	 * reverted). Only on tables whose engine keeps transactions (InnoDB);
	 * another one refuses with the way before anything is written, as
	 * AB_MCP_Sync_Pairs::transaction() does for the pairs.
	 *
	 * @param callable $fn function(): mixed|WP_Error.
	 * @return mixed|WP_Error What $fn returned.
	 */
	public static function transaction( callable $fn ) {
		$ready = self::ready();
		if ( true !== $ready ) {
			return $ready;
		}
		global $wpdb;
		$tables  = array( AB_MCP_Sync_Schema::table( 'runs' ), AB_MCP_Sync_Schema::table( 'steps' ) );
		$engines = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ( %s, %s )', $tables[0], $tables[1] ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}
		$by = array();
		foreach ( is_array( $engines ) ? $engines : array() as $one ) {
			$by[ (string) $one['name'] ] = (string) $one['engine'];
		}
		foreach ( $tables as $table ) {
			if ( ! isset( $by[ $table ] ) || 0 !== strcasecmp( $by[ $table ], 'InnoDB' ) ) {
				return new WP_Error(
					'ab_mcp_sync_run_engine',
					sprintf( 'The table %1$s of the runs of the takeover is stored with the engine %2$s, which keeps no transactions, so nothing of the run is changed: a run is made with all its steps or not at all, and a way back begins with all of its steps or not at all. Ask the host to convert the table to InnoDB (ALTER TABLE %1$s ENGINE=InnoDB), then call again.', $table, isset( $by[ $table ] ) ? $by[ $table ] : 'unknown' ),
					array( 'engine' => isset( $by[ $table ] ) ? $by[ $table ] : null )
				);
			}
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return self::db_error();
		}
		try {
			$result = call_user_func( $fn );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
		if ( is_wp_error( $result ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $result;
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::db_error();
		}
		return $result;
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
		// A step begun under the lock of this request's call is its own
		// (settle()), until the call gives the lock back (under_lock()).
		if ( isset( $set['state'] ) && 'begun' === $set['state'] && AB_MCP_Change_Lock::held() ) {
			self::$own[ (int) $run_id ][ (int) $seq ] = true;
		}
		return true;
	}

	/* ------------------------------------------------- the lock of a call */

	/**
	 * Run a call that can change a run under the lock of the write boundary
	 * (AB_MCP_Change_Lock, decision 18), held from its first read of the run
	 * to its answer and given back in every way (finally): next and step,
	 * abort, the begin and the continuation of a way back, closing as checked
	 * (ONE LOCK PER CALL above). Another request holding the lock (a step of
	 * a run being written, or an activation, a deploy, an update of the code
	 * of this site through AlphaBridge): refused as busy, before anything of
	 * the run is read or written. Taken again within the same request, it
	 * nests (AB_MCP_Change_Lock::take()); the outermost call that gives it
	 * back forgets the steps this request began (own).
	 *
	 * @param callable $fn   function(): mixed, run under the lock.
	 * @param string   $what What does not happen while another holds the lock («Run 7 takes no step»).
	 * @return mixed|WP_Error What $fn returned, or the refusal as busy.
	 */
	public static function under_lock( callable $fn, $what ) {
		$outer = ! AB_MCP_Change_Lock::held();
		$lock  = AB_MCP_Change_Lock::take();
		if ( is_wp_error( $lock ) ) {
			$since = self::lock_since();
			return new WP_Error(
				'ab_mcp_sync_run_busy',
				sprintf(
					'%1$s right now: the lock of the write boundary is held by another request (a step of a run being written, or an activation, a deploy or an update of the code of this site through AlphaBridge), and a call that changes a run runs only while no other one does. Nothing was read or changed. Call again when that request has answered; a lock left behind by a request that ended early is taken over from %2$s UTC.',
					(string) $what,
					gmdate( 'H:i', ( $since > 0 ? $since : self::now() ) + AB_MCP_Change_Lock::TIMEOUT )
				)
			);
		}
		try {
			return call_user_func( $fn );
		} finally {
			AB_MCP_Change_Lock::release();
			if ( $outer ) {
				self::$own = array();
			}
		}
	}

	/* -------------------------------------------------- the interruption */

	/**
	 * Whether a run with a step begun is interrupted (decision 18), and the
	 * run moved to interrupted where it is, conditionally on its state.
	 * Decided under the lock of the write boundary only: every step is begun
	 * and ended within one call under it (under_lock()), so there a step
	 * begun that this request did not begin belongs to a request that ended
	 * before it ended the step (its lock given back at its shutdown, or left
	 * behind and taken over after TIMEOUT; GRENZEN-LAUF.md), and a step this
	 * request began (own) is its own call, running, never interrupted.
	 * Called under the lock (a call that changes a run), it decides there.
	 * Called without (status, a preview), it changes nothing where no step
	 * is begun; where one is, a fresh row of the lock means another request
	 * holds it (the step may be running: the run stays as it is, with
	 * 'running'), else it takes the lock for the decision only and gives it
	 * back at once, and another request taking it first means the same.
	 * Before, settle() decided by the age of the row beside the lock, and
	 * under its own lock it took every begun step as running, also one a
	 * request that ended had left (the decision of 07.11.2026 evening,
	 * Variante A).
	 *
	 * @param array $run A run (get()).
	 * @return array|WP_Error The run as it is now, with 'running' where a step is begun and may be running.
	 */
	public static function settle( array $run ) {
		if ( AB_MCP_Change_Lock::held() ) {
			return self::interrupt( $run );
		}
		if ( ! in_array( $run['state'], array( 'open', 'reverting' ), true ) ) {
			return $run;
		}
		$begun = self::begun( (int) $run['run_id'] );
		if ( is_wp_error( $begun ) || null === $begun ) {
			return is_wp_error( $begun ) ? $begun : $run;
		}
		// A reading call: a fresh row of the lock, or another request taking
		// it first, means the step may be running; nothing changes.
		if ( self::lock_fresh() || is_wp_error( AB_MCP_Change_Lock::take() ) ) {
			$run['running'] = (int) $begun['seq'];
			return $run;
		}
		try {
			$now = self::get( (int) $run['run_id'] );
			return is_array( $now ) ? self::interrupt( $now ) : ( is_wp_error( $now ) ? $now : $run );
		} finally {
			AB_MCP_Change_Lock::release();
		}
	}

	/**
	 * settle() under the lock: the run interrupted where a step of it is
	 * begun that this request did not begin.
	 *
	 * @param array $run A run, read under the lock.
	 * @return array|WP_Error The run as it is now.
	 */
	private static function interrupt( array $run ) {
		if ( ! in_array( $run['state'], array( 'open', 'reverting' ), true ) ) {
			return $run;
		}
		$begun = self::begun( (int) $run['run_id'] );
		if ( is_wp_error( $begun ) || null === $begun ) {
			return is_wp_error( $begun ) ? $begun : $run;
		}
		$moved = self::set_state( (int) $run['run_id'], $run['state'], 'interrupted' );
		if ( is_wp_error( $moved ) && 'ab_mcp_sync_run_changed' !== $moved->get_error_code() ) {
			return $moved;
		}
		$now = self::get( (int) $run['run_id'] );
		return is_array( $now ) ? $now : $run;
	}

	/**
	 * A step of a run begun by another request than this one, or null: a
	 * step this request began under the lock of its call (own) is its own.
	 *
	 * @param int $run_id Run.
	 * @return array|null|WP_Error
	 */
	private static function begun( $run_id ) {
		$steps = self::steps( (int) $run_id );
		if ( is_wp_error( $steps ) ) {
			return $steps;
		}
		foreach ( $steps as $step ) {
			if ( 'begun' === $step['state'] && ! isset( self::$own[ (int) $run_id ][ (int) $step['seq'] ] ) ) {
				return $step;
			}
		}
		return null;
	}

	/**
	 * Whether the lock of AB_MCP_Change_Lock is held fresh right now: its row
	 * is there and younger than TIMEOUT (decision 18: a lock left behind
	 * proves no end of the request, so a fresh one counts as held). The look
	 * of a reading call before it takes the lock for settle(); the decision
	 * is take() itself.
	 *
	 * @return bool
	 */
	public static function lock_fresh() {
		if ( AB_MCP_Change_Lock::held() ) {
			return true;
		}
		$since = self::lock_since();
		return $since > 0 && ( self::now() - $since ) < AB_MCP_Change_Lock::TIMEOUT;
	}

	/**
	 * The time the lock of AB_MCP_Change_Lock was taken or last touched, 0
	 * without a row.
	 *
	 * @return int
	 */
	private static function lock_since() {
		return (int) get_option( AB_MCP_Change_Lock::OPTION, 0 );
	}

	/**
	 * What to do with a run in its state, with the ways that go there.
	 *
	 * @param array $run Run.
	 * @return string
	 */
	public static function way( array $run ) {
		switch ( $run['state'] ) {
			case 'open':
				return 'push' === $run['type'] ? 'Go on with action=next (one step per call), or action=abort.' : 'Go on with wp_site_revert action=next.';
			case 'interrupted':
				return self::interrupted_ways( $run, true );
			case 'reverting':
				return 'The way back of this run is running: go on with wp_site_revert action=next.';
			default:
				return 'revert' === $run['type']
					? 'The run of the way back is closed; it is not taken back itself. wp_site_push action=status lists the runs.'
					: 'The run is closed. wp_site_revert action=plan with this run_id plans the way back for its pairs.';
		}
	}

	/**
	 * The refusal of a step or an abort of an interrupted run (decision 18),
	 * with its two ways as they go for this run.
	 *
	 * @param array $run Run.
	 * @return WP_Error
	 */
	public static function interrupted_refusal( array $run ) {
		return new WP_Error( 'ab_mcp_sync_run_interrupted', self::interrupted_ways( $run, false ), array( 'run_id' => (int) $run['run_id'] ) );
	}

	/**
	 * The two ways of an interrupted run (decision 18), as they go for it:
	 * (a) a person closes it as checked; (b) wp_site_revert action=plan with
	 * the token, which leads a run of the way back on (the interrupted step
	 * stays, no step is added), and begins the way back of a forward run
	 * (GitHub Codex round 4 on free#137, P1: the hint named a way that
	 * refused).
	 *
	 * @param array $run    Run.
	 * @param bool  $status For status (the run described) or for a refusal.
	 * @return string
	 */
	private static function interrupted_ways( array $run, $status ) {
		$back  = 'revert' === $run['type'];
		$what  = $back
			? sprintf( 'Run %1$d%2$s is interrupted: a step of its way back began and the request that put it back ended before it finished (its lock is no longer held)', (int) $run['run_id'], 'revert' === $run['type'] ? ' (a run of the way back)' : ' (on its way back)' )
			: sprintf( 'Run %d is interrupted: a step began and the request that wrote it ended before it finished (its lock is no longer held)', (int) $run['run_id'] );
		$way_b = $back
			? 'wp_site_revert action=plan with this run_id leads it on (confirmed with its token): the interrupted step stays as it is (unclear, nothing of it is put back again), the steps of the way back still planned go back one by one (action=next), and no step is added'
			: 'wp_site_revert action=plan with this run_id begins its way back (confirmed with its token), which takes its own parts back (a part the interrupted step never read back stays as it is)';
		return sprintf(
			'%1$s. %2$s Two ways: a person closes it as checked on the page AlphaBridge → Staging after reading its journal (nothing is written; %3$s), or %4$s. %5$s',
			$what,
			$status ? 'No step runs and no new run begins until one of them is taken.' : 'It takes no step and is not aborted.',
			$back ? 'the pairs of the steps left begun become unclear' : 'pairs with unclear parts become unclear',
			$way_b,
			'action=status shows the journal, each part with its state.'
		);
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
		// Under the lock of the call, as every change of a run (ONE LOCK PER
		// CALL): the run read and settled under it, so a step a request that
		// ended left begun makes it interrupted here, and no way back begins
		// or goes on beside the close.
		return self::under_lock(
			static function () use ( $run_id, $user_id ) {
				return self::close_checked_locked( (int) $run_id, (int) $user_id );
			},
			sprintf( 'Run %d is not closed as checked', (int) $run_id )
		);
	}

	/**
	 * close_checked() under the lock.
	 *
	 * @param int $run_id  Run.
	 * @param int $user_id The person.
	 * @return array|WP_Error The run as stored.
	 */
	private static function close_checked_locked( $run_id, $user_id ) {
		$run = self::get( $run_id );
		if ( is_wp_error( $run ) || null === $run ) {
			return is_wp_error( $run ) ? $run : self::unknown( $run_id );
		}
		$run = self::settle( $run );
		if ( is_wp_error( $run ) ) {
			return $run;
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
			// The pair: the step's own, or, for a step of the way back (which
			// gets its pair_id only as it ends), the pair of the step it was
			// taking back (a run of the way back closed as checked, GitHub Codex
			// round 4 on free#137).
			$pair_id = null !== $step['pair_id'] ? (int) $step['pair_id'] : self::pair_of_revert_step( $run_id, $step );
			if ( null !== $pair_id ) {
				$pair = AB_MCP_Sync_Pairs::get( $pair_id );
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

	/**
	 * The pair of a step of the way back: the pair of the step it takes back
	 * (its own pair_id is set only as it ends). Null for another step, or
	 * where the step taken back names none.
	 *
	 * @param int   $run_id Run.
	 * @param array $step   Step (op revert; parts: forward_run, forward_seq).
	 * @return int|null
	 */
	public static function pair_of_revert_step( $run_id, array $step ) {
		if ( 'revert' !== $step['op'] || ! is_array( $step['parts'] ) || ! isset( $step['parts']['forward_seq'] ) ) {
			return null;
		}
		$forward = self::step( isset( $step['parts']['forward_run'] ) ? (int) $step['parts']['forward_run'] : (int) $run_id, (int) $step['parts']['forward_seq'] );
		if ( ! is_array( $forward ) ) {
			return null;
		}
		if ( null !== $forward['pair_id'] ) {
			return (int) $forward['pair_id'];
		}
		return is_array( $forward['parts'] ) && isset( $forward['parts']['object']['pair_id'] ) && null !== $forward['parts']['object']['pair_id'] ? (int) $forward['parts']['object']['pair_id'] : null;
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
			// A state of an earlier build (restored) counts under its own name.
			if ( ! isset( $out['counts'][ $step['state'] ] ) ) {
				$out['counts'][ $step['state'] ] = 0;
			}
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
		$out['steps'] = self::cap_values( $out['steps'] );
		return $out;
	}

	/**
	 * The journal of a step as shown: each part with its name and states,
	 * hashes and the name of its backup; of values only value_before, the
	 * value a part left held before the step (from the backup, short, never
	 * a credential: AB_MCP_Sync_Post_Writer::before_value()).
	 *
	 * @param array $parts Journal.
	 * @return array
	 */
	private static function parts_shown( array $parts ) {
		$out = array();
		foreach ( isset( $parts['parts'] ) && is_array( $parts['parts'] ) ? $parts['parts'] : array() as $part ) {
			$out[] = array_intersect_key( (array) $part, array_flip( array( 'part', 'state', 'before', 'target', 'after', 'own', 'own_after', 'deviated', 'value_before', 'value_rules', 'backup', 'way', 'note' ) ) );
		}
		return array(
			'object' => isset( $parts['object'] ) ? $parts['object'] : null,
			'backup' => isset( $parts['backup'] ) && is_array( $parts['backup'] ) ? array_intersect_key( $parts['backup'], array_flip( array( 'name', 'sha256', 'bytes', 'absent' ) ) ) : null,
			'parts'  => $out,
		);
	}

	/**
	 * The values before one answer carries, capped at ANSWER_VALUE_BYTES in
	 * all: the newest steps keep theirs first (the step that just ran is
	 * the last one), the older ones say so (VALUE_CUT) once the budget is
	 * spent. Read in the parts of the journal (parts.parts) and in the
	 * outcome of a step of the way back (result.parts).
	 *
	 * @param array $steps The steps as shown().
	 * @return array
	 */
	public static function cap_values( array $steps ) {
		$budget = self::ANSWER_VALUE_BYTES;
		for ( $s = count( $steps ) - 1; $s >= 0; $s-- ) {
			foreach ( array( 'parts', 'result' ) as $where ) {
				if ( ! isset( $steps[ $s ][ $where ]['parts'] ) || ! is_array( $steps[ $s ][ $where ]['parts'] ) ) {
					continue;
				}
				foreach ( $steps[ $s ][ $where ]['parts'] as $p => $part ) {
					if ( ! is_array( $part ) || ! isset( $part['value_before'] ) || ! is_string( $part['value_before'] ) ) {
						continue;
					}
					// Checked once more as it leaves, in the journal and in the
					// outcome of a step of the way back (Codex round 9).
					$part['value_before']                                 = AB_MCP_Sync_Post_Writer::checked_before( $part['value_before'], isset( $part['value_rules'] ) ? (int) $part['value_rules'] : null );
					$steps[ $s ][ $where ]['parts'][ $p ]['value_before'] = $part['value_before'];
					$bytes = strlen( $part['value_before'] );
					if ( $bytes <= $budget ) {
						$budget -= $bytes;
						continue;
					}
					// The mark counts as well; past the budget even for it, the
					// value is left out of the answer (Codex round 8).
					if ( strlen( self::VALUE_CUT ) <= $budget ) {
						$steps[ $s ][ $where ]['parts'][ $p ]['value_before'] = self::VALUE_CUT;
						$budget -= strlen( self::VALUE_CUT );
					} else {
						unset( $steps[ $s ][ $where ]['parts'][ $p ]['value_before'] );
					}
				}
			}
		}
		return $steps;
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
