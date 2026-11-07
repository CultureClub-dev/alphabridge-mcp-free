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
 * are added; for a closed run, a run of the type revert is made (one run
 * per site, decision 15: another run that is not closed refuses it). The
 * state the token is bound to is the SHA-256 over the run, the steps, the
 * print of the pair of sites and the hashes of the objects as they are
 * now. next runs one step of the way back (one pair, under the lock);
 * status shows the run.
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
			return is_wp_error( $plan ) ? $plan : self::begin( $plan );
		}
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new WP_Error( 'ab_mcp_sync_revert_action', 'wp_site_revert takes action=plan (with run_id and steps; the token in the same call), next or status (with run_id). Pass one of them.' );
		}
		$source = AB_MCP_Sync_Proposal::source_id();
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$run = AB_MCP_Sync_Push::own_run( $a );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		AB_MCP_Sync_Run::touch( (int) $run['run_id'], true );
		$run = AB_MCP_Sync_Run::settle( $run );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		if ( 'status' === $action ) {
			$shown = AB_MCP_Sync_Run::shown( $run );
			if ( is_wp_error( $shown ) ) {
				return $shown;
			}
			$shown['next'] = AB_MCP_Sync_Push::status_way( $run );
			return $shown;
		}
		if ( isset( $run['running'] ) ) {
			return new WP_Error( 'ab_mcp_sync_run_busy', sprintf( 'Step %d of the run is being written right now (its lock is held); call again when it has answered.', (int) $run['running'] ) );
		}
		if ( ! ( 'reverting' === $run['state'] || ( 'revert' === $run['type'] && 'open' === $run['state'] ) ) ) {
			return new WP_Error( 'ab_mcp_sync_run_state', sprintf( 'The run %1$d is %2$s: no way back is running for it. Use action=plan with this run_id to plan one.', (int) $run['run_id'], $run['state'] ) );
		}
		return AB_MCP_Sync_Engine::next( $run, null );
	}

	/* ------------------------------------------------------------ plan */

	/**
	 * The plan of the way back, read fresh.
	 *
	 * @param array $a Arguments.
	 * @return array|WP_Error
	 */
	public static function plan( array $a ) {
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
		if ( 'revert' === $run['type'] ) {
			return new WP_Error( 'ab_mcp_sync_revert_type', sprintf( 'Run %d is a run of the way back itself; the way back is planned for the run that wrote (its plan names it in reverts). Use wp_site_push action=status to list the runs.', (int) $run['run_id'] ) );
		}
		if ( 'reverting' === $run['state'] ) {
			return new WP_Error( 'ab_mcp_sync_revert_running', sprintf( 'The way back of run %d is running already: use action=next to go on.', (int) $run['run_id'] ) );
		}
		$same = in_array( $run['state'], array( 'open', 'interrupted' ), true );
		if ( ! $same ) {
			$open = AB_MCP_Sync_Run::open_run();
			if ( is_wp_error( $open ) ) {
				return $open;
			}
			if ( null !== $open ) {
				return new WP_Error( 'ab_mcp_sync_run_open', sprintf( 'This site has a run that is not closed (run %1$d, %2$s, state %3$s), and at most one run per site is open at a time, so no way back for the closed run %4$d begins. Ask for its status (wp_site_push action=status). %5$s', (int) $open['run_id'], $open['type'], $open['state'], (int) $run['run_id'], AB_MCP_Sync_Push::status_way( $open ) ) );
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
		$items = array();
		$shown = array();
		$live  = array();
		foreach ( $steps as $step ) {
			if ( 'write' !== $step['op'] ) {
				continue;
			}
			if ( null !== $wanted && ! in_array( (int) $step['seq'], $wanted, true ) ) {
				continue;
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
			$object  = $journal['object'];
			$live_id = isset( $object['live_ref'] ) ? (int) $object['live_ref'] : 0;
			$items[] = array(
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
			$will = 'create' === $object['op'] ? 'rest' : ( ! empty( $object['deviated'] ) || ! array_key_exists( 'deviated', $object ) || $foreign ? 'left' : ( 'done' === $step['state'] ? 'back' : 'back where own' ) );
			$shown[] = array(
				'seq'      => (int) $step['seq'],
				'state'    => $step['state'],
				'kind'     => $object['kind'],
				'op'       => $object['op'],
				'live_ref' => $live_id > 0 ? (string) $live_id : null,
				'pair_id'  => isset( $object['pair_id'] ) ? $object['pair_id'] : $step['pair_id'],
				'parts'    => $parts,
				'will'     => $will,
			);
			if ( $live_id > 0 ) {
				$current             = AB_MCP_Sync_Post_Writer::current( $live_id );
				$live[ $live_id ]    = is_array( $current ) ? AB_MCP_Sync_Post_Writer::row_hash( $current['row'] ) : ( null === $current ? 'gone' : 'unread' );
			}
		}
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
					'items'  => $items,
					'live'   => $live,
					'print'  => $print,
					'run_id' => (int) $run['run_id'],
					'state'  => $run['state'],
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
	 * Begin the way back of a confirmed plan.
	 *
	 * @param array $plan plan().
	 * @return array|WP_Error
	 */
	private static function begin( array $plan ) {
		$run   = $plan['run'];
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
			// The same run, atomically from open or interrupted to reverting.
			$moved = AB_MCP_Sync_Run::set_state( (int) $run['run_id'], array( 'open', 'interrupted' ), 'reverting' );
			if ( is_wp_error( $moved ) ) {
				return $moved;
			}
			// The step the interruption left begun is unclear from now on: its
			// journal says which parts were reached; the way back reads it.
			// The forward steps still planned are skipped: the run goes back.
			$steps_now = AB_MCP_Sync_Run::steps( (int) $run['run_id'] );
			foreach ( is_array( $steps_now ) ? $steps_now : array() as $step ) {
				if ( 'begun' === $step['state'] ) {
					AB_MCP_Sync_Run::update_step( (int) $run['run_id'], (int) $step['seq'], array( 'state' => 'unclear', 'result' => array( 'message' => 'Interrupted: the request that wrote this step ended before it finished; the way back reads its journal.' ) ), 'begun' );
				} elseif ( 'planned' === $step['state'] ) {
					AB_MCP_Sync_Run::update_step( (int) $run['run_id'], (int) $step['seq'], array( 'state' => 'skipped', 'result' => array( 'message' => 'Skipped: the run went to its way back before this step ran.' ) ), 'planned' );
				}
			}
			$first = AB_MCP_Sync_Run::append_steps( (int) $run['run_id'], $steps );
			if ( is_wp_error( $first ) ) {
				return $first;
			}
			$now = AB_MCP_Sync_Run::get( (int) $run['run_id'] );
		} else {
			// Made under the lock of the protected store (A6): a clean-up
			// that reads the runs whose backups it keeps and removes groups
			// does so under the same lock, so it either sees this run (which
			// names the run it takes back in reverts) or ran to its end
			// before; the backups a way back needs are never removed beside
			// its making (decision 20, rule 4).
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
			return AB_MCP_Sync_Run::unknown( (int) $run['run_id'] );
		}
		$shown = AB_MCP_Sync_Run::shown( $now );
		if ( is_wp_error( $shown ) ) {
			return $shown;
		}
		$shown['same_run'] = $plan['same_run'];
		$shown['limits']   = self::LIMITS;
		$shown['next']     = sprintf( 'The way back has %1$d step(s) and nothing is written yet. Call wp_site_revert action=next with run_id %2$d: each call takes one pair back under the lock, byte for byte where the part is still what the run wrote; the last one closes the run.', count( $steps ), (int) $now['run_id'] );
		return $shown;
	}
}
