<?php
/**
 * wp_site_revert on the production site (Z8, decisions 15, 18, 20 and 23 of
 * the plan of 01.11.2026; branch 8): the way back for what a run wrote,
 * pair by pair, byte for byte conditional.
 *
 * ACTIONS. plan (the confirmation in the same call, decision 15): the run
 * (run_id; without it, the run of this site that is not closed) and the
 * steps to take back (steps: the seqs of its write steps; without them,
 * every step that wrote). Without confirm_token the call answers with the
 * preview and the token; with it, the way back begins: for a run that is
 * open or interrupted, the same run moves to reverting (one atomic change
 * of state on the state read, decision 15) and its steps of the way back
 * are added, both in one transaction of the database, so the run is
 * reverting with all of them or stays as it was (GitHub Codex round 2 of
 * branch 8, P1); for a closed run, a run of the type revert is made, with
 * all its steps or not at all (one run per site, decision 15: another run
 * that is not closed refuses it). The
 * state the token is bound to is the SHA-256 over the run, the steps, the
 * print of the pair of sites and the hashes of the objects as they are
 * now. next runs one step of the way back (one pair, under the lock);
 * status shows the run.
 *
 * AN INTERRUPTED RUN (decision 18, way (b)), the same for both types of a
 * run (interrupted_plan()): where its way back had begun (a run of the type
 * revert, or the way back of the same run, of the type push), exactly that
 * way back is led on: the step the interruption left begun becomes unclear
 * and stays (nothing of it is put back again, its pair unclear), the steps
 * of the way back still planned go back one by one with next, each on a
 * fresh read (decision A), no step is added; where it had not (a forward
 * run), its way back begins as for an open run.
 *
 * ONE LOCK PER CALL: plan with the token (begin(): the way back begun, led
 * on, or a run of the way back made) and next run under the lock of the
 * write boundary from the first read of the run to the answer
 * (AB_MCP_Sync_Run::under_lock(); another request holding it refuses them as
 * busy, nothing read or changed); the plan is computed again under it and
 * compared with the plan confirmed. status and the preview read without it.
 *
 * WHAT GOES BACK (decision 23): a part only where its hash now is the hash
 * the step read back after its own call changed it (the step's own state);
 * nothing where it is the hash before; otherwise it is left and named with
 * the value it held before (value_before), the pair unclear. An object that
 * deviated within its step (a hook changed a part, a call was refused, a
 * part read back other than written; the decision of 07.11.2026) is left
 * whole, and so is a part the step never read back (an interrupted step).
 * A new object is never removed: it stays as a rest, its pair residual
 * (rule 0), named in the answer. The row goes back through wp_update_post() with the
 * backed-up dates and edit_date after the check of the status WordPress
 * would store now; meta raw and conditional; terms through
 * wp_set_object_terms(). The three limits of the way back (concept section
 * 5) are in the preview.
 *
 * Messages are English without gettext, as the other classes of the
 * takeover; each names the way.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-run.php';
require_once __DIR__ . '/class-sync-engine.php';
require_once __DIR__ . '/class-sync-push.php';
require_once dirname( __DIR__ ) . '/class-protected-store.php';

/**
 * Class AB_MCP_Sync_Revert
 */
final class AB_MCP_Sync_Revert {

	/** The actions. */
	const ACTIONS = array( 'plan', 'next', 'status' );

	/** The limits of the way back, named with every preview (concept section 5). */
	const LIMITS = array(
		'A new object the run made is not removed: it stays as a rest, its pair residual; remove it by hand in wp-admin where it should go.',
		'A part changed on this site since the run wrote it is left as it is and named with its value before; its pair is unclear until a person confirms a new alignment (wp_site_pairs).',
		'An object that deviated within its step (a hook of this site changed a part, a call was refused, a part read back other than written) is never put back by itself: every part of it is left as it is and named with its value before; check it by hand in wp-admin. The same for a part the step never read back (an interrupted step).',
		'A backed-up scheduled post whose date has passed is not put back (WordPress would publish it); it is named as «check needed»: set its date anew in wp-admin.',
	);

	/* ------------------------------------------------- the confirmation */

	/**
	 * Whether a call needs the token: a strict false for next and status.
	 *
	 * @param array $a Arguments.
	 * @return bool
	 */
	public static function when( array $a ) {
		return ! ( isset( $a['action'] ) && in_array( $a['action'], array( 'next', 'status' ), true ) );
	}

	/**
	 * @param array $a Arguments.
	 * @return string|WP_Error
	 */
	public static function state( array $a ) {
		$plan = self::plan( $a );
		return is_wp_error( $plan ) ? $plan : $plan['state'];
	}

	/**
	 * @param array $a Arguments.
	 * @return array|WP_Error
	 */
	public static function preview( array $a ) {
		$plan = self::plan( $a );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		return array(
			'run_id'    => (int) $plan['run']['run_id'],
			'run_state' => $plan['run']['state'],
			'same_run'  => $plan['same_run'],
			'steps'     => $plan['shown'],
			'limits'    => self::LIMITS,
			'effect'    => $plan['same_run']
				? 'Leads this run to reverting and takes its own parts back, one pair per call (action=next), under the lock; then it is closed as reverted, or with findings where rests or unclear parts remain.'
				: 'Makes a run of the way back for these pairs of the closed run and takes their parts back, one pair per call (action=next), under the lock; then it is closed as reverted, or with findings where rests or unclear parts remain.',
		);
	}

	/* ---------------------------------------------------------- the tool */

	/**
	 * wp_site_revert.
	 *
	 * @param array $a Arguments (without the token).
	 * @return array|WP_Error
	 */
	public static function call( array $a ) {
		$action = isset( $a['action'] ) ? $a['action'] : null;
		if ( 'plan' === $action ) {
			$plan = self::plan( $a );
			return is_wp_error( $plan ) ? $plan : self::begin( $plan, $a );
		}
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new WP_Error( 'ab_mcp_sync_revert_action', 'wp_site_revert takes action=plan (with run_id and steps; the token in the same call), next or status (with run_id). Pass one of them.' );
		}
		$source = AB_MCP_Sync_Proposal::source_id();
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( 'status' === $action ) {
			$run = AB_MCP_Sync_Push::own_run( $a );
			if ( is_wp_error( $run ) ) {
				return $run;
			}
			AB_MCP_Sync_Run::touch( (int) $run['run_id'], true );
			$run = AB_MCP_Sync_Run::settle( $run );
			if ( is_wp_error( $run ) ) {
				return $run;
			}
			$shown = AB_MCP_Sync_Run::shown( $run );
			if ( is_wp_error( $shown ) ) {
				return $shown;
			}
			$shown['next'] = AB_MCP_Sync_Run::way( $run );
			return $shown;
		}
		// next changes the run: under the lock of the write boundary for the
		// whole call, the run read and settled under it (the decision of
		// 07.11.2026 evening, Variante A).
		return AB_MCP_Sync_Run::under_lock(
			static function () use ( $a ) {
				$run = AB_MCP_Sync_Push::own_run( $a );
				if ( is_wp_error( $run ) ) {
					return $run;
				}
				AB_MCP_Sync_Run::touch( (int) $run['run_id'], true );
				$run = AB_MCP_Sync_Run::settle( $run );
				if ( is_wp_error( $run ) ) {
					return $run;
				}
				if ( 'interrupted' === $run['state'] ) {
					// Both ways of decision 18, for a run of the way back as well.
					return AB_MCP_Sync_Run::interrupted_refusal( $run );
				}
				if ( ! ( 'reverting' === $run['state'] || ( 'revert' === $run['type'] && 'open' === $run['state'] ) ) ) {
					return new WP_Error( 'ab_mcp_sync_run_state', sprintf( 'The run %1$d is %2$s: no way back is running for it. Ask for its status (wp_site_push action=status); %3$s', (int) $run['run_id'], $run['state'], 'revert' === $run['type'] ? 'a closed run of the way back is not taken back.' : 'action=plan with this run_id plans one.' ) );
				}
				return AB_MCP_Sync_Engine::next( $run, null );
			},
			sprintf( 'Run %d takes no step of its way back', isset( $a['run_id'] ) && is_numeric( $a['run_id'] ) ? (int) $a['run_id'] : 0 )
		);
	}

	/* ------------------------------------------------------------ plan */

	/**
	 * The plan of the way back, read fresh.
	 *
	 * @param array      $a   Arguments.
	 * @param array|null $run The run as read and settled under the lock of
	 *                        the call (begin(): the plan computed again for
	 *                        it); null to read, settle and check the run here.
	 * @return array|WP_Error
	 */
	public static function plan( array $a, $run = null ) {
		if ( ! isset( $a['action'] ) || 'plan' !== $a['action'] ) {
			return new WP_Error( 'ab_mcp_sync_revert_action', 'wp_site_revert takes action=plan (with run_id and steps; the token in the same call), next or status (with run_id). Pass one of them.' );
		}
		$source = AB_MCP_Sync_Proposal::source_id();
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$print = AB_MCP_Sync_Anchor::pair_print();
		if ( null === $print ) {
			return new WP_Error( 'ab_mcp_sync_pairs_no_anchor', 'This site has no confirmed key of a staging site, so no way back is planned. Ask the person to confirm it on the page AlphaBridge → Staging of this site; then call again.' );
		}
		if ( ! is_array( $run ) ) {
			if ( isset( $a['run_id'] ) ) {
				$run = AB_MCP_Sync_Push::own_run( $a );
				if ( is_wp_error( $run ) ) {
					return $run;
				}
			} else {
				$run = AB_MCP_Sync_Run::open_run();
				if ( is_wp_error( $run ) ) {
					return $run;
				}
				if ( null === $run ) {
					return new WP_Error( 'ab_mcp_sync_revert_run', 'No run is open on this site: pass run_id, the run whose pairs go back (wp_site_push action=status lists the runs).' );
				}
				$foreign = AB_MCP_Sync_Run::owner_refusal( $run );
				if ( null !== $foreign ) {
					return $foreign;
				}
			}
			$run = AB_MCP_Sync_Run::settle( $run );
			if ( is_wp_error( $run ) ) {
				return $run;
			}
			if ( isset( $run['running'] ) ) {
				return new WP_Error( 'ab_mcp_sync_run_busy', sprintf( 'Step %d of the run is being written right now (its lock is held); wait for its answer, then plan the way back.', (int) $run['running'] ) );
			}
		}
		if ( 'interrupted' === $run['state'] ) {
			// Way (b) of decision 18: one function for every interrupted run.
			return self::interrupted_plan( $run, $a, $print, $source );
		}
		if ( 'revert' === $run['type'] ) {
			return new WP_Error( 'ab_mcp_sync_revert_type', sprintf( 'Run %1$d is a run of the way back itself and is %2$s (wp_site_push action=status lists the runs): %3$s', (int) $run['run_id'], $run['state'], in_array( $run['state'], array( 'open', 'reverting' ), true ) ? 'go on with action=next.' : 'a run of the way back is not taken back; the way back is planned for a run that wrote.' ) );
		}
		if ( 'reverting' === $run['state'] ) {
			return new WP_Error( 'ab_mcp_sync_revert_running', sprintf( 'The way back of run %d is running already: use action=next to go on.', (int) $run['run_id'] ) );
		}
		return self::way_back( $run, $a, $print, $source );
	}

	/**
	 * Way (b) of decision 18 for an interrupted run, one function for both
	 * types of a run (the decision of 07.11.2026 evening, Variante A; GitHub
	 * Codex round 6 on free#137, P1: the continuation took runs of the type
	 * revert only; the way back of the same run, of the type push,
	 * interrupted, was planned as a new way back, which found every write
	 * step taken back already by its steps of the way back and refused, and
	 * the run held the site): where its way back had begun
	 * (AB_MCP_Sync_Run::on_way_back()), exactly that way back is led on
	 * (continuation(); steps is not taken, no step is added); where it had
	 * not (an interrupted forward run), its way back begins as for an open
	 * run (way_back()).
	 *
	 * @param array  $run    The interrupted run.
	 * @param array  $a      Arguments.
	 * @param string $print  The print of the pair of sites.
	 * @param string $source Source id.
	 * @return array|WP_Error As plan() returns it.
	 */
	private static function interrupted_plan( array $run, array $a, $print, $source ) {
		$steps = AB_MCP_Sync_Run::steps( (int) $run['run_id'] );
		if ( is_wp_error( $steps ) ) {
			return $steps;
		}
		if ( ! AB_MCP_Sync_Run::on_way_back( $run, $steps ) ) {
			return self::way_back( $run, $a, $print, $source );
		}
		if ( isset( $a['steps'] ) ) {
			return new WP_Error( 'ab_mcp_sync_revert_steps', sprintf( 'Run %d is interrupted on its way back: it goes on with the steps of the way back it has, so steps is not taken. Call again without steps.', (int) $run['run_id'] ) );
		}
		return self::continuation( $run, $steps, $print, $source );
	}

	/**
	 * The plan of the way back of a run that wrote and whose way back has
	 * not begun: an open or interrupted run (the same run, decision 15), or
	 * a closed one (a run of the type revert is made).
	 *
	 * @param array  $run    The run.
	 * @param array  $a      Arguments.
	 * @param string $print  The print of the pair of sites.
	 * @param string $source Source id.
	 * @return array|WP_Error As plan() returns it.
	 */
	private static function way_back( array $run, array $a, $print, $source ) {
		$same = in_array( $run['state'], array( 'open', 'interrupted' ), true );
		if ( ! $same ) {
			$open = AB_MCP_Sync_Run::open_run();
			if ( is_array( $open ) ) {
				// Settled, so the refusal names the ways that go for it.
				$open = AB_MCP_Sync_Run::settle( $open );
			}
			if ( is_wp_error( $open ) ) {
				return $open;
			}
			if ( null !== $open ) {
				return new WP_Error( 'ab_mcp_sync_run_open', sprintf( 'This site has a run that is not closed (run %1$d, %2$s, state %3$s), and at most one run per site is open at a time, so no way back for the closed run %4$d begins. Ask for its status (wp_site_push action=status). %5$s', (int) $open['run_id'], $open['type'], $open['state'], (int) $run['run_id'], AB_MCP_Sync_Run::way( $open ) ) );
			}
		}
		$steps = AB_MCP_Sync_Run::steps( (int) $run['run_id'] );
		if ( is_wp_error( $steps ) ) {
			return $steps;
		}
		$wanted = isset( $a['steps'] ) ? $a['steps'] : null;
		if ( null !== $wanted && ( ! is_array( $wanted ) || ! array_is_list( $wanted ) || array() !== array_filter( $wanted, static fn( $s ) => ! is_int( $s ) && ! ( is_string( $s ) && 1 === preg_match( '/^[1-9][0-9]*\z/', $s ) ) ) ) ) {
			return new WP_Error( 'ab_mcp_sync_revert_steps', 'steps is a list of the seqs of the write steps of the run to take back; without it, every step that wrote. Use action=status to list them.' );
		}
		$wanted = null === $wanted ? null : array_map( 'intval', $wanted );
		// A step goes back at most once: every step of the way back that
		// names it counts, whatever its state, in this run (the way back of a
		// run that was not closed, decision 15) and in every run of the type
		// revert made for this run once it was closed (reverts in their plan).
		// A second way back of the same step would undo a later takeover of
		// the same object (GitHub Codex round 1 of branch 8).
		$reverted = array();
		$others   = AB_MCP_Sync_Run::reverts_of( (int) $run['run_id'] );
		if ( is_wp_error( $others ) ) {
			return $others;
		}
		$ways = array( $run['run_id'] => $steps );
		foreach ( $others as $other ) {
			$ways[ (int) $other['run_id'] ] = AB_MCP_Sync_Run::steps( (int) $other['run_id'] );
			if ( is_wp_error( $ways[ (int) $other['run_id'] ] ) ) {
				return $ways[ (int) $other['run_id'] ];
			}
		}
		foreach ( $ways as $way_id => $way_steps ) {
			foreach ( $way_steps as $step ) {
				if ( 'revert' !== $step['op'] || ! is_array( $step['parts'] ) || ! isset( $step['parts']['forward_seq'] ) ) {
					continue;
				}
				if ( isset( $step['parts']['forward_run'] ) && (int) $step['parts']['forward_run'] !== (int) $run['run_id'] ) {
					continue;
				}
				$reverted[ (int) $step['parts']['forward_seq'] ] = (int) $way_id === (int) $run['run_id'] ? sprintf( 'step %d of this run', (int) $step['seq'] ) : sprintf( 'step %1$d of run %2$d', (int) $step['seq'], (int) $way_id );
			}
		}
		$items   = array();
		$objects = array();
		$shown   = array();
		$live    = array();
		foreach ( $steps as $step ) {
			if ( 'write' !== $step['op'] ) {
				continue;
			}
			if ( null !== $wanted && ! in_array( (int) $step['seq'], $wanted, true ) ) {
				continue;
			}
			// A journal that does not read back as it was written
			// (AB_MCP_Sync_Run::records()): no way back is planned on it, fail
			// closed and with the way, rather than leaving the step out unsaid.
			if ( isset( $step['unread'] ) && in_array( 'parts', $step['unread'], true ) ) {
				return new WP_Error( 'ab_mcp_sync_revert_unread', sprintf( 'The journal of step %1$d of run %2$d does not read back as it was written (a marker of its bytes that does not hold), so no way back is planned on it and nothing is taken back. Pass steps without %1$d to plan the way back of the other steps that wrote, and check its object by hand in wp-admin; its backup in the protected store holds the object as it was before the step.', (int) $step['seq'], (int) $run['run_id'] ) );
			}
			$journal = is_array( $step['parts'] ) && isset( $step['parts']['object'] ) ? $step['parts'] : null;
			// Only a step with a journal and a backup wrote anything (decision
			// 17: no mutation without a backup); a step that failed before its
			// backup, or made no object, has nothing to take back (B9).
			$wrote = null !== $journal && ! in_array( $step['state'], array( 'planned', 'skipped' ), true ) && is_array( $journal['backup'] ) && ( 'create' !== $journal['object']['op'] || null !== $journal['object']['live_ref'] );
			if ( ! $wrote ) {
				if ( null !== $wanted ) {
					return new WP_Error( 'ab_mcp_sync_revert_steps', sprintf( 'Step %1$d is %2$s and wrote nothing: there is nothing to take back for it. Choose a step that wrote (action=status lists them).', (int) $step['seq'], $step['state'] ) );
				}
				continue;
			}
			if ( isset( $reverted[ (int) $step['seq'] ] ) ) {
				if ( null !== $wanted ) {
					return new WP_Error( 'ab_mcp_sync_revert_steps', sprintf( 'Step %1$d was taken back already (%2$s), and a step goes back once. Choose another step, or leave steps out.', (int) $step['seq'], $reverted[ (int) $step['seq'] ] ) );
				}
				continue;
			}
			$object    = $journal['object'];
			$live_id   = self::live_ref_of( $object );
			$objects[] = $object;
			$items[]   = array(
				'forward_run' => (int) $run['run_id'],
				'forward_seq' => (int) $step['seq'],
			);
			$parts   = array();
			foreach ( isset( $journal['parts'] ) && is_array( $journal['parts'] ) ? $journal['parts'] : array() as $part ) {
				$parts[] = array_intersect_key( $part, array_flip( array( 'part', 'state', 'way' ) ) );
			}
			$foreign = false;
			foreach ( isset( $journal['parts'] ) && is_array( $journal['parts'] ) ? $journal['parts'] : array() as $part ) {
				$foreign = $foreign || ( is_array( $part ) && ! empty( $part['foreign'] ) );
			}
			// An object that deviated within its step: nothing of it goes back
			// by itself (the decision of 07.11.2026); the preview says so and
			// claims nothing of what it holds now: the way back names each part
			// unchanged (as the backup) or left (Codex rounds 8, 10, 11).
			$will = 'create' === $object['op'] ? 'rest' : ( ! empty( $object['deviated'] ) || ! array_key_exists( 'deviated', $object ) || $foreign ? 'stays' : ( 'done' === $step['state'] ? 'back' : 'back where own' ) );
			$shown[] = array(
				'seq'      => (int) $step['seq'],
				'state'    => $step['state'],
				'kind'     => $object['kind'],
				'op'       => $object['op'],
				'live_ref' => AB_MCP_Sync_Engine::is_ref( $live_id ) ? (string) $live_id : null,
				'pair_id'  => isset( $object['pair_id'] ) ? $object['pair_id'] : $step['pair_id'],
				'parts'    => $parts,
				'will'     => $will,
			);
			if ( AB_MCP_Sync_Engine::is_ref( $live_id ) ) {
				$live[ self::live_key( $object, $live_id ) ] = self::row_now( $object, $live_id );
			}
		}
		// The way back goes from the last step to the first (the steps of a
		// run in the order of the plan, a parent first): a term the run moved
		// under another term it moved goes back before it, so no state in
		// between closes a loop of parents (two categories that swapped their
		// places; branch 9, Codex round 1, B4).
		$items = array_reverse( $items );
		$shown = array_reverse( $shown );
		if ( array() === $items ) {
			return new WP_Error( 'ab_mcp_sync_revert_nothing', sprintf( 'Run %d has no written step to take back (none wrote, or every one was taken back already). Use wp_site_push action=status to see its steps.', (int) $run['run_id'] ) );
		}
		// The room for the steps of the way back in the same run, asked here,
		// before the run changes its state (B5): a run of wp_site_push writes
		// at most AB_MCP_Sync_Push::MAX_OBJECTS objects and a run holds
		// AB_MCP_Sync_Run::MAX_RUN_STEPS, so it always has it (N4); this holds
		// the rule where a run has more steps than that.
		if ( $same && ! AB_MCP_Sync_Run::has_room( count( $steps ), count( $items ) ) ) {
			return new WP_Error( 'ab_mcp_sync_revert_steps', sprintf( 'The way back of %1$d step(s) would take run %2$d past its %3$d steps, so it does not begin. Pass fewer steps (steps) for this way back and plan the rest once it is closed.', count( $items ), (int) $run['run_id'], AB_MCP_Sync_Run::MAX_RUN_STEPS ) );
		}
		ksort( $live );
		$state = hash(
			'sha256',
			AB_MCP_Sync_Canon::encode(
				array(
					'items'      => $items,
					'live'       => $live,
					// The order in which the parts of each kind go back (C2), by
					// the kinds of the journals (Codex round 2, C4).
					'part_order' => self::part_orders_of( $objects ),
					'print'      => $print,
					'run_id'     => (int) $run['run_id'],
					'state'      => $run['state'],
				)
			)
		);
		return array(
			'run'      => $run,
			'same_run' => $same,
			'items'    => $items,
			'shown'    => $shown,
			'print'    => $print,
			'source'   => $source,
			'state'    => $state,
		);
	}

	/**
	 * The key of an object of a step among the objects the token binds: a
	 * post by its id, as before branch 9; a term by term:<id> (two id spaces);
	 * an object a kind names by a name (branch 11) by kind:<name>.
	 *
	 * @param array      $object  The object of the journal.
	 * @param int|string $live_id Its ref here (live_ref_of()).
	 * @return string|int
	 */
	private static function live_key( array $object, $live_id ) {
		$kind = isset( $object['kind'] ) ? (string) $object['kind'] : 'post';
		return 'post' === $kind ? (int) $live_id : $kind . ':' . $live_id;
	}

	/**
	 * The object of this site a journal names, in the form the write side of
	 * its kind takes it (AB_MCP_Sync_Engine::live_of(): an id, or a name for
	 * the kinds of branch 11); 0 where it names none.
	 *
	 * @param array $object The object of the journal.
	 * @return int|string
	 */
	private static function live_ref_of( array $object ) {
		if ( ! isset( $object['live_ref'] ) ) {
			return 0;
		}
		$ref = AB_MCP_Sync_Engine::live_of( AB_MCP_Sync_Engine::writer( isset( $object['kind'] ) ? (string) $object['kind'] : 'post' ), $object['live_ref'] );
		return null === $ref ? 0 : $ref;
	}

	/**
	 * The hash of the row of an object as it is now, by the write side of its
	 * kind: gone, unread, or the hash.
	 *
	 * @param array $object  The object of the journal.
	 * @param int   $live_id Its id here.
	 * @return string
	 */
	private static function row_now( array $object, $live_id ) {
		$w = AB_MCP_Sync_Engine::writer( isset( $object['kind'] ) ? (string) $object['kind'] : 'post' );
		if ( null === $w ) {
			return 'unread';
		}
		$current = $w::current( $live_id );
		return is_array( $current ) ? $w::row_hash( $current['row'] ) : ( null === $current ? 'gone' : 'unread' );
	}

	/**
	 * The orders of the parts of the kinds of these objects of journals, by
	 * kind (AB_MCP_Sync_Engine::part_orders()), for the token of a way back
	 * and of its continuation (Codex round 2 of branch 11, C4): a journal
	 * of branch 8 without a kind is of a post (as writer_of() reads it).
	 *
	 * @param array[] $objects The objects of the journals of the steps.
	 * @return array<string,string[]>
	 */
	public static function part_orders_of( array $objects ) {
		$kinds = array();
		foreach ( $objects as $object ) {
			$kinds[] = is_array( $object ) && isset( $object['kind'] ) && is_string( $object['kind'] ) && '' !== $object['kind'] ? $object['kind'] : 'post';
		}
		return AB_MCP_Sync_Engine::part_orders( $kinds );
	}

	/**
	 * The plan that leads the interrupted way back of a run on (way (b) of
	 * decision 18, interrupted_plan()), a run of the type revert or the way
	 * back of the same run alike: no step is added; the step the
	 * interruption left begun stays as it is (unclear, its parts as they
	 * are: no put back is repeated, nothing of it goes back by itself), the
	 * steps still planned go back one by one afterwards, each deciding on a
	 * fresh read (decision A). The state the token binds: the run, its
	 * state, the steps by their state, and the objects of the steps still to
	 * go as they are.
	 *
	 * @param array   $run    The interrupted run on its way back.
	 * @param array[] $steps  Its steps.
	 * @param string  $print  The print of the pair of sites.
	 * @param string  $source Source id.
	 * @return array As plan() returns it, with same_run true and no items.
	 */
	private static function continuation( array $run, array $steps, $print, $source ) {
		$shown   = array();
		$live    = array();
		$state   = array();
		$objects = array();
		foreach ( $steps as $step ) {
			if ( 'revert' !== $step['op'] || ! in_array( $step['state'], array( 'planned', 'begun' ), true ) ) {
				continue;
			}
			$what    = is_array( $step['parts'] ) ? $step['parts'] : array();
			$forward = AB_MCP_Sync_Run::step( isset( $what['forward_run'] ) ? (int) $what['forward_run'] : (int) $run['run_id'], isset( $what['forward_seq'] ) ? (int) $what['forward_seq'] : 0 );
			$object  = is_array( $forward ) && is_array( $forward['parts'] ) && isset( $forward['parts']['object'] ) ? $forward['parts']['object'] : array();
			$live_id   = self::live_ref_of( $object );
			$objects[] = $object;
			$shown[]   = array(
				'seq'         => (int) $step['seq'],
				'state'       => $step['state'],
				'forward_seq' => isset( $what['forward_seq'] ) ? (int) $what['forward_seq'] : null,
				'kind'        => isset( $object['kind'] ) ? $object['kind'] : null,
				'live_ref'    => AB_MCP_Sync_Engine::is_ref( $live_id ) ? (string) $live_id : null,
				'will'        => 'begun' === $step['state'] ? 'stays' : 'back where own',
			);
			$state[] = array( (int) $step['seq'], (string) $step['state'] );
			if ( 'planned' === $step['state'] && AB_MCP_Sync_Engine::is_ref( $live_id ) ) {
				$live[ self::live_key( $object, $live_id ) ] = self::row_now( $object, $live_id );
			}
		}
		ksort( $live );
		return array(
			'run'      => $run,
			'same_run' => true,
			'items'    => array(),
			'shown'    => $shown,
			'print'    => $print,
			'source'   => $source,
			'state'    => hash(
				'sha256',
				AB_MCP_Sync_Canon::encode(
					array(
						'continue'   => $state,
						'live'       => $live,
						// The order of the parts of each kind still to go (C4).
						'part_order' => self::part_orders_of( $objects ),
						'print'      => $print,
						'run_id'   => (int) $run['run_id'],
						'state'    => $run['state'],
					)
				)
			),
		);
	}

	/**
	 * Begin the way back of a confirmed plan, under the lock of the write
	 * boundary for the whole call (AB_MCP_Sync_Run::under_lock(), the same
	 * every step takes; GitHub Codex round 3 on free#137, P1: between the
	 * plan and the transaction a concurrent next could begin a write step):
	 * another request holding it refuses as busy, nothing changed.
	 *
	 * @param array $plan plan(), as confirmed.
	 * @param array $a    The arguments the plan was made from (without the token).
	 * @return array|WP_Error
	 */
	private static function begin( array $plan, array $a ) {
		return AB_MCP_Sync_Run::under_lock(
			static function () use ( $plan, $a ) {
				return self::begin_locked( $plan, $a );
			},
			sprintf( 'The way back of run %d does not begin', (int) $plan['run']['run_id'] )
		);
	}

	/**
	 * begin() under the lock: the run read and settled under it (a step a
	 * request that ended left begun makes it interrupted here, and the plan
	 * confirmed for the open run is no longer the run's), the plan computed
	 * again for it and checked against the plan confirmed (a step written,
	 * an object changed, the state moved in between: refused, plan again;
	 * GitHub Codex round 3 on free#137, P1), then the way back of the same
	 * run begun or led on (begin_same_run()), or a run of the way back made
	 * for a closed run.
	 *
	 * @param array $plan plan(), as confirmed.
	 * @param array $a    Its arguments.
	 * @return array|WP_Error
	 */
	private static function begin_locked( array $plan, array $a ) {
		$run    = $plan['run'];
		$run_id = (int) $run['run_id'];
		$fresh  = AB_MCP_Sync_Run::get( $run_id );
		if ( ! is_array( $fresh ) ) {
			return is_wp_error( $fresh ) ? $fresh : AB_MCP_Sync_Run::unknown( $run_id );
		}
		$fresh = AB_MCP_Sync_Run::settle( $fresh );
		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}
		if ( (string) $fresh['state'] !== (string) $run['state'] ) {
			return new WP_Error( 'ab_mcp_sync_revert_changed', sprintf( 'The way back of run %1$d does not begin: the run was %2$s when the way back was planned and is %3$s now. Plan the way back again (wp_site_revert action=plan).', $run_id, (string) $run['state'], (string) $fresh['state'] ) );
		}
		$again = self::plan( $a, $fresh );
		if ( is_wp_error( $again ) ) {
			return $again;
		}
		if ( ! hash_equals( (string) $plan['state'], (string) $again['state'] ) ) {
			return new WP_Error( 'ab_mcp_sync_revert_changed', sprintf( 'The way back of run %d does not begin: the run changed while it was planned (a step was written, an object of this site changed, or the run changed its state), so the plan confirmed is not the run as it is. Plan the way back again (wp_site_revert action=plan); the new plan takes every step that wrote back.', $run_id ) );
		}
		$plan  = $again;
		$steps = array();
		foreach ( $plan['items'] as $item ) {
			$steps[] = array(
				'op'      => 'revert',
				'phase'   => 0,
				'depends' => array(),
				'parts'   => $item,
			);
		}
		if ( $plan['same_run'] ) {
			$began = self::begin_same_run( $plan, $steps );
			if ( is_wp_error( $began ) ) {
				return $began;
			}
			$now = AB_MCP_Sync_Run::get( $run_id );
		} else {
			// Made under the lock of the protected store (A6): a clean-up
			// that reads the runs whose backups it keeps and removes groups
			// does so under the same lock, so it either sees this run (which
			// names the run it takes back in reverts) or ran to its end
			// before; the backups a way back needs are never removed beside
			// its making (decision 20, rule 4). All its steps, or none
			// (AB_MCP_Sync_Run::create(), GitHub Codex round 2, P1).
			$anchor = AB_MCP_Sync_Anchor::active();
			$now    = AB_MCP_Protected_Store::under_lock(
				static function () use ( $run, $plan, $anchor, $steps ) {
					return AB_MCP_Sync_Run::create(
						array(
							'type'        => 'revert',
							'source_id'   => (string) $run['source_id'],
							'key_id'      => null === $anchor ? (string) $run['key_id'] : (string) $anchor['key_id'],
							'pair_print'  => (string) $plan['print'],
							'report_hash' => (string) $run['report_hash'],
							'plan_hash'   => $plan['state'],
							'user_id'     => get_current_user_id(),
							'connection'  => AB_MCP_Sync_Run::connection(),
							'expires'     => AB_MCP_Sync_Run::now() + AB_MCP_Sync_Push::EXPIRES,
							'budget'      => array( 'bytes' => 0 ),
							'plan'        => array(
								'reverts' => array( (int) $run['run_id'] ),
								'items'   => $plan['items'],
							),
						),
						$steps
					);
				}
			);
			if ( is_wp_error( $now ) ) {
				return $now;
			}
		}
		if ( ! is_array( $now ) ) {
			return AB_MCP_Sync_Run::unknown( $run_id );
		}
		$shown = AB_MCP_Sync_Run::shown( $now );
		if ( is_wp_error( $shown ) ) {
			return $shown;
		}
		$shown['same_run'] = $plan['same_run'];
		$shown['limits']   = self::LIMITS;
		$shown['next']     = array() === $steps && $plan['same_run']
			? sprintf( 'The way back of run %1$d goes on: the step the interruption left begun stays as it is (unclear, its pair unclear; nothing of it is put back again), and %2$d step(s) of the way back are still to go, none added. Call wp_site_revert action=next with run_id %1$d: each call takes one pair back under the lock, byte for byte where the part is still what the run wrote; the call that finds none left closes the run with the findings.', (int) $now['run_id'], (int) $shown['counts']['planned'] )
			: sprintf( 'The way back has %1$d step(s) and nothing is written yet. Call wp_site_revert action=next with run_id %2$d: each call takes one pair back under the lock, byte for byte where the part is still what the run wrote; the last one closes the run.', count( $steps ), (int) $now['run_id'] );
		return $shown;
	}

	/**
	 * The way back of the same run, under the lock of the call, on the plan
	 * computed again under it (begin_locked()): the run from open or
	 * interrupted to reverting, its forward steps settled and every step of
	 * the way back stored, in one transaction (AB_MCP_Sync_Run::
	 * transaction()): the run is reverting with the whole plan of its way
	 * back, or stays as it was (GitHub Codex round 2 of branch 8, P1:
	 * before, the state changed first and the steps were inserted one by
	 * one). An interrupted way back led on adds no step.
	 *
	 * @param array $plan  plan(), as computed again under the lock.
	 * @param array $steps The steps of the way back, as create() takes them.
	 * @return int|true|WP_Error The seq of the first step of the way back; true where none is added.
	 */
	private static function begin_same_run( array $plan, array $steps ) {
		$run    = $plan['run'];
		$run_id = (int) $run['run_id'];
		$began = AB_MCP_Sync_Run::transaction(
			static function () use ( $run_id, $steps ) {
				$moved = AB_MCP_Sync_Run::set_state( $run_id, array( 'open', 'interrupted' ), 'reverting' );
				if ( is_wp_error( $moved ) ) {
					return $moved;
				}
				// The step the interruption left begun is unclear from now
				// on: its journal says which parts were reached; the way back
				// reads it. The forward steps still planned are skipped: the
				// run goes back.
				$steps_now = AB_MCP_Sync_Run::steps( $run_id );
				if ( is_wp_error( $steps_now ) ) {
					return $steps_now;
				}
				foreach ( $steps_now as $step ) {
					$settled = true;
					if ( 'begun' === $step['state'] ) {
						$settled = AB_MCP_Sync_Run::update_step(
							$run_id,
							(int) $step['seq'],
							array(
								'state'  => 'unclear',
								'result' => array(
									'message' => 'revert' === $step['op']
										? 'Interrupted: the request that put this step back ended before it finished; whatever it reached stays as it is, nothing of it is put back again (no put back is repeated). Check the object in wp-admin; the backup of the step that wrote holds its state before the run.'
										: 'Interrupted: the request that wrote this step ended before it finished; the way back reads its journal.',
								),
							),
							'begun'
						);
						// The pair of a step of the way back left begun: unclear, as
						// a close as checked makes it (its object may be back in part).
						$pair_id = 'revert' === $step['op'] && ! is_wp_error( $settled ) ? AB_MCP_Sync_Run::pair_of_revert_step( $run_id, $step ) : null;
						if ( null !== $pair_id ) {
							$pair = AB_MCP_Sync_Pairs::get( $pair_id );
							if ( is_array( $pair ) && 'active' === $pair['state'] ) {
								AB_MCP_Sync_Pairs::set_state( $pair_id, 'unclear', AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( $pair_id ) ) );
							}
						}
					} elseif ( 'planned' === $step['state'] && 'revert' !== $step['op'] ) {
						// A forward step still planned is skipped: the run goes
						// back. A step of the way back still planned stays: it goes
						// back in its turn (an interrupted run of the way back led on).
						$settled = AB_MCP_Sync_Run::update_step( $run_id, (int) $step['seq'], array( 'state' => 'skipped', 'result' => array( 'message' => 'Skipped: the run went to its way back before this step ran.' ) ), 'planned' );
					}
					if ( is_wp_error( $settled ) ) {
						return $settled;
					}
				}
				return array() === $steps ? true : AB_MCP_Sync_Run::append_steps( $run_id, $steps );
			}
		);
		if ( is_wp_error( $began ) ) {
			if ( 'ab_mcp_sync_run_changed' === $began->get_error_code() ) {
				return $began;
			}
			return new WP_Error(
				$began->get_error_code(),
				sprintf( '%1$s The run %2$d stays %3$s and nothing of its way back began: its steps are stored all at once or not at all. Plan the way back again (wp_site_revert action=plan with run_id %2$d); if it keeps failing, the database error log names the cause.', $began->get_error_message(), $run_id, $run['state'] ),
				$began->get_error_data()
			);
		}
		return $began;
	}
}
