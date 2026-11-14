<?php
/**
 * The operations of the code of a run (Z8b branch 5; plan E5, E8, 3.1 Z24):
 * the steps a run of the code holds for a unit, a plugin or a theme of the
 * staging site, each from the plan of its run and its own journal.
 *
 * THE ONE ENTRANCE (run()): AB_MCP_Sync_Engine::run_write() hands every
 * write step of a run of the code here, and nothing else runs one. The
 * operations of this version:
 * - stage («bereitstellen», E5): the unit built and put into the area stage
 *   of the protected store (AB_MCP_Sync_Code_Stage::stage(), once, with the
 *   run), then checked over as many calls as it takes
 *   (AB_MCP_Sync_Code_Stage::complete(), one call of the run each) until it
 *   is ready or refused. Nothing of the site changes. Between two calls the
 *   step stays planned; its journal names the stage (group, tree, hash), and
 *   how far the check is stands in the state of the stage alone
 *   (AB_MCP_Store_Inputs: stage.state), in no second copy;
 * - swap («tauschen», E5): the staged unit put in the place of the one this
 *   site has (AB_MCP_Sync_Code_Swap).
 *
 * WHAT A STEP READS (the pre-check of branch 5, V18 and V19): the unit of a
 * step comes from the plan of its run in the form of its row plan.code, by
 * the number of the step; the stage of a unit from the journal of its step
 * in the form of its row step.stage. What is not of its form is not known:
 * the step does not run, and no kind or step is ever taken for another one
 * by a default.
 *
 * Messages are in gettext, in the language of the site; their translations
 * come with text/z8b. Codes stay as the tools name them.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/class-shape.php';
require_once dirname( __DIR__ ) . '/class-store-inputs.php';
require_once __DIR__ . '/class-sync-run.php';
require_once __DIR__ . '/class-sync-code-stage.php';
require_once __DIR__ . '/class-sync-code-swap.php';

/**
 * Class AB_MCP_Sync_Code_Ops
 */
final class AB_MCP_Sync_Code_Ops {

	/** The operations of a step of the code in this version. */
	const OPS = array( 'stage', 'swap' );

	/* ------------------------------------------------------ the plan */

	/**
	 * What the plan of a run holds for a step of the code: its unit, the
	 * packages of the environment of the report and the packages of code,
	 * each in the form of the row plan.code. Null where the plan is no plan
	 * of a run of the code (it holds no key code: a run of the contents);
	 * the refusal where it is one and does not read in its form, or names no
	 * unit for the step.
	 *
	 * @param array $plan The plan of the run (AB_MCP_Sync_Run::get()).
	 * @param int   $seq  The step.
	 * @return array|null|WP_Error unit, env, packages.
	 */
	public static function item( array $plan, $seq ) {
		if ( ! array_key_exists( 'code', $plan ) ) {
			return null;
		}
		$code = AB_MCP_Store_Inputs::read( 'plan.code', $plan['code'] );
		if ( null !== $code && is_int( $seq ) ) {
			foreach ( $code['units'] as $unit ) {
				if ( $seq === $unit['seq'] ) {
					return array(
						'unit'     => $unit,
						'env'      => $code['env'],
						'packages' => $code['packages'],
					);
				}
			}
		}
		/* translators: %d: step number. */
		return new WP_Error( 'ab_mcp_sync_code_plan', sprintf( __( 'The plan of this run names no unit of code for step %d in the form a run of the code holds it, so the step does not run and nothing of this site changed. Abort the run (wp_site_push action=abort), make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), is_int( $seq ) ? $seq : 0 ) );
	}

	/**
	 * The stage the journal of a step names, in the form of its row
	 * step.stage; null where the journal is not of that form (unknown).
	 *
	 * @param mixed $journal The journal of the step (its parts).
	 * @return array|null unit (kind, ref, op), stage (gid, name, hash, source, files, bytes).
	 */
	public static function staged( $journal ) {
		return AB_MCP_Store_Inputs::read( 'step.stage', $journal );
	}

	/* ----------------------------------------------------- the entrance */

	/**
	 * Run a step of the code of a run (the one entrance, Z24), under the
	 * lock of the call (AB_MCP_Sync_Engine::next()).
	 *
	 * @param array $run  The run.
	 * @param array $step The step.
	 * @param array $item item(): unit, env, packages.
	 * @return array|WP_Error next.
	 */
	public static function run( array $run, array $step, array $item ) {
		if ( 'stage' === $item['unit']['op'] ) {
			return self::stage( $run, $step, $item );
		}
		// Part 2 of branch 5 brings the swap (AB_MCP_Sync_Code_Swap): until
		// then the step does not run and stays planned, nothing is changed.
		return new WP_Error( 'ab_mcp_sync_code_swap_not_built', 'The swap of a unit of code is not built at this commit of Z8b branch 5 (it follows with the swap mechanics): the step stays planned and nothing of this site changed. Abort the run (wp_site_push action=abort).' );
	}

	/**
	 * What the check of a run finds of a step of the code that is done
	 * (AB_MCP_Sync_Engine::run_verify()): nothing of a step that staged a
	 * unit, which changed nothing of the site (its stage is checked by its
	 * swap, AB_MCP_Sync_Code_Swap). Null for no finding, else a whole
	 * sentence.
	 *
	 * @param array $step The step.
	 * @param array $item item() of it.
	 * @return string|null
	 */
	public static function finding( array $step, array $item ) {
		unset( $step, $item );
		return null;
	}

	/* ------------------------------------------------------------ stage */

	/**
	 * The step «bereitstellen» of a unit, one call of it: without a journal
	 * yet, the unit is built and put into the area stage (once); with one,
	 * its check goes on for this call. The step stays planned until the stage
	 * is ready (done), refused (failed), or the unit is here already as the
	 * staging site has it (done, nothing to swap).
	 *
	 * @param array $run  The run.
	 * @param array $step The step.
	 * @param array $item item().
	 * @return array|WP_Error next.
	 */
	private static function stage( array $run, array $step, array $item ) {
		$unit   = $item['unit'];
		$run_id = AB_MCP_Store_Inputs::read( 'runs.run_id', $run['run_id'] );
		$seq    = $unit['seq'];
		if ( null === $run_id || ( isset( $step['unread'] ) && array() !== $step['unread'] ) ) {
			return self::journal_unknown( $seq );
		}
		if ( null === $step['parts'] ) {
			$staged = AB_MCP_Sync_Code_Stage::stage( $run_id, $item['env'], $unit['kind'], $unit['ref'], $item['packages'] );
			if ( is_wp_error( $staged ) ) {
				return self::not_staged( $run_id, $seq, $unit, $staged->get_error_code(), $staged->get_error_message() );
			}
			if ( array_key_exists( 'same', $staged ) ) {
				/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name. */
				$message = sprintf( __( 'This site already has the %1$s %2$s as the staging site has it (the same files), so nothing is staged and nothing of it is swapped. Make a new report (wp_site_diff): where it still shows the unit as changed, confirm its pair anew (wp_site_pairs).', 'alphabridge-mcp' ), $unit['kind'], $unit['ref'] );
				return self::ended( $run_id, $seq, 'done', array( 'message' => $message, 'staged' => array( 'kind' => $unit['kind'], 'ref' => $unit['ref'] ), 'same' => true ), $message );
			}
			$journal = array(
				'unit'  => array(
					'kind' => $unit['kind'],
					'ref'  => $unit['ref'],
					'op'   => 'stage',
				),
				'stage' => array(
					'gid'    => $staged['gid'],
					'name'   => $staged['name'],
					'hash'   => $staged['hash'],
					'source' => $staged['source'],
					'files'  => $staged['files'],
					'bytes'  => $staged['bytes'],
				),
			);
			// Only a journal that reads back in its form is one a later call
			// goes on with: checked before it is saved.
			if ( null === self::staged( $journal ) ) {
				return self::not_staged( $run_id, $seq, $unit, 'code:stage_unknown', __( 'The stage did not answer in the form a step of the code takes (its group, tree and hash).', 'alphabridge-mcp' ) );
			}
			$saved = AB_MCP_Sync_Run::update_step( $run_id, $seq, array( 'parts' => $journal ), 'planned' );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			AB_MCP_Sync_Run::touch( $run_id );
			/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name, 3: number of files, 4: where it came from, as the tools name it (wordpress.org, packages). */
			return array( 'next' => sprintf( __( 'The %1$s %2$s of the staging site is staged in the protected store (%3$d files, from %4$s); nothing of this site changed. Its PHP files are checked next: call action=next again.', 'alphabridge-mcp' ), $unit['kind'], $unit['ref'], $staged['files'], $staged['source'] ) );
		}
		$journal = self::staged( $step['parts'] );
		if ( null === $journal || $journal['unit']['kind'] !== $unit['kind'] || $journal['unit']['ref'] !== $unit['ref'] ) {
			return self::journal_unknown( $seq );
		}
		// The stage is checked and answered only where it is bound to this
		// step: its group to this run, its unit and the staging_hash of the
		// plan (A2 of the Codex round 1 on the code, option A: the stage holds
		// the binding before it writes anything, K2).
		$bound = array(
			'run_id' => $run_id,
			'kind'   => $unit['kind'],
			'ref'    => $unit['ref'],
			'signed' => $unit['staging_hash'],
		);
		$state = AB_MCP_Sync_Code_Stage::complete( $journal['stage']['gid'], $journal['stage']['name'], $journal['stage']['hash'], $item['env'], $bound );
		if ( is_wp_error( $state ) ) {
			// A refusal no later call changes (the stage is not the one its
			// group holds, the unit not signed, the stage not bound to this
			// step; A3 and A2 of the Codex round 1 on the code) ends the step
			// failed, its swap is skipped. Not known (a package, the tree, the
			// state or the entry of the group of the stage did not read): the
			// step stays planned, the answer names the error and the way
			// (reading 5).
			if ( in_array( $state->get_error_code(), AB_MCP_Sync_Code_Stage::PERMANENT, true ) ) {
				return self::not_staged( $run_id, $seq, $unit, $state->get_error_code(), $state->get_error_message() );
			}
			return self::not_checked( $seq, $state );
		}
		$checked = self::checked( $state );
		if ( null === $checked ) {
			return self::journal_unknown( $seq );
		}
		if ( AB_MCP_Sync_Code_Stage::CHECKING === $checked['state'] ) {
			AB_MCP_Sync_Run::touch( $run_id );
			/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name, 3: PHP files checked so far, 4: PHP files to check. */
			return array( 'next' => sprintf( __( 'The stage of the %1$s %2$s is being checked: %3$d of %4$d PHP files so far; nothing of this site changed. Call action=next again.', 'alphabridge-mcp' ), $unit['kind'], $unit['ref'], $checked['checked'], $checked['files'] ) );
		}
		if ( AB_MCP_Sync_Code_Stage::REFUSED === $checked['state'] ) {
			$refusal = $checked['refusal'];
			return self::not_staged( $run_id, $seq, $unit, $refusal['code'], $refusal['message'] . ( null === $refusal['path'] ? '' : ' (' . $refusal['path'] . ( null === $refusal['line'] ? '' : ':' . $refusal['line'] ) . ')' ) );
		}
		/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name, 3: number of PHP files checked. */
		$message = sprintf( __( 'The %1$s %2$s of the staging site is staged and checked (%3$d PHP files): it is ready for its swap, the next step. Nothing of this site changed.', 'alphabridge-mcp' ), $unit['kind'], $unit['ref'], $checked['checked'] );
		return self::ended(
			$run_id,
			$seq,
			'done',
			array(
				'message' => $message,
				'staged'  => array(
					'kind' => $unit['kind'],
					'ref'  => $unit['ref'],
				),
				'same'    => false,
				'checked' => $checked['checked'],
				'notes'   => $checked['notes'],
			),
			$message
		);
	}

	/**
	 * The answer of AB_MCP_Sync_Code_Stage::complete() as this step takes it:
	 * its state one of the three, how many PHP files are checked of how
	 * many, its notes, and its refusal (a code and a message, a path and a
	 * line where it names them) or none. Null where it is anything else: the
	 * step decides nothing on it.
	 *
	 * @param mixed $state The answer.
	 * @return array|null
	 */
	private static function checked( $state ) {
		if ( ! is_array( $state ) || ! isset( $state['state'], $state['checked'], $state['files'], $state['notes'] ) || ! array_key_exists( 'refusal', $state ) ) {
			return null;
		}
		if ( ! in_array( $state['state'], array( AB_MCP_Sync_Code_Stage::CHECKING, AB_MCP_Sync_Code_Stage::READY, AB_MCP_Sync_Code_Stage::REFUSED ), true ) || ! is_int( $state['checked'] ) || ! is_int( $state['files'] ) || ! is_array( $state['notes'] ) ) {
			return null;
		}
		$refusal = $state['refusal'];
		if ( null !== $refusal && ( ! is_array( $refusal ) || ! isset( $refusal['code'], $refusal['message'] ) || ! is_string( $refusal['code'] ) || ! is_string( $refusal['message'] ) || ! array_key_exists( 'path', $refusal ) || ! array_key_exists( 'line', $refusal ) || ( null !== $refusal['path'] && ! is_string( $refusal['path'] ) ) || ( null !== $refusal['line'] && ! is_int( $refusal['line'] ) ) ) ) {
			return null;
		}
		// Ready without a refusal, refused with one: nothing in between.
		if ( ( AB_MCP_Sync_Code_Stage::REFUSED === $state['state'] ) !== ( null !== $refusal ) ) {
			return null;
		}
		return $state;
	}

	/**
	 * A step of the code ended: its state and result stored, from planned.
	 *
	 * @param int    $run_id  Run.
	 * @param int    $seq     Step.
	 * @param string $state   done or failed.
	 * @param array  $result  Its result.
	 * @param string $message What the answer says.
	 * @return array|WP_Error next.
	 */
	private static function ended( $run_id, $seq, $state, array $result, $message ) {
		$done = AB_MCP_Sync_Run::update_step( $run_id, $seq, array( 'state' => $state, 'result' => $result ), 'planned' );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		AB_MCP_Sync_Run::touch( $run_id );
		return array( 'next' => $message );
	}

	/**
	 * A unit that is not staged: the step failed with the refusal, so the
	 * swap that depends on it is skipped.
	 *
	 * @param int    $run_id  Run.
	 * @param int    $seq     Step.
	 * @param array  $unit    The unit of the plan.
	 * @param string $code    The code of the refusal.
	 * @param string $why     Its message (one or more whole sentences).
	 * @return array|WP_Error next.
	 */
	private static function not_staged( $run_id, $seq, array $unit, $code, $why ) {
		/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name, 3: the code of the refusal, as the tools name it (code:syntax, …), 4: the refusal (one or more whole sentences). */
		$message = sprintf( __( 'The %1$s %2$s is not staged (%3$s), so it is not swapped; nothing of this site changed. %4$s', 'alphabridge-mcp' ), $unit['kind'], $unit['ref'], $code, $why );
		return self::ended( $run_id, $seq, 'failed', array( 'message' => $message, 'code' => $code ), $message . ' ' . __( 'The run goes on with the next step (action=next).', 'alphabridge-mcp' ) );
	}

	/**
	 * A check of a stage that gave no state this call (complete() refused
	 * with an error): the step stays planned and its swap does not run, for
	 * no swap goes on without a stage read as ready; the error with the way.
	 *
	 * @param int      $seq   Step.
	 * @param WP_Error $error The error of complete().
	 * @return WP_Error
	 */
	private static function not_checked( $seq, WP_Error $error ) {
		/* translators: 1: the error (one or more whole sentences), 2: step number. */
		return new WP_Error( $error->get_error_code(), sprintf( __( '%1$s Step %2$d stays planned, and no unit is swapped without a stage read as ready; nothing of this site changed. Call action=next again; where it keeps failing, abort the run (wp_site_push action=abort).', 'alphabridge-mcp' ), $error->get_error_message(), $seq ) );
	}

	/**
	 * The refusal of a step whose journal, run or stage does not read in its
	 * form: nothing is decided on it, the step stays as it is.
	 *
	 * @param int $seq Step.
	 * @return WP_Error
	 */
	private static function journal_unknown( $seq ) {
		/* translators: %d: step number. */
		return new WP_Error( 'ab_mcp_sync_code_journal', sprintf( __( 'The journal of step %d does not read as a step of the code wrote it (its unit, its stage, or the answer of its check), so nothing is staged or swapped on it and nothing of this site changed. Abort the run (wp_site_push action=abort), make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), $seq ) );
	}

	/* --------------------------------------------------------- versions */

	/**
	 * How the version of the staging side stands to the one of this site, for
	 * the lock against going down (E9, Z12): newer, same or older, each only
	 * from two versions in the form of their row unit.version; null where
	 * one of them is not of that form (unknown: version_compare() answers on
	 * anything, so it is asked only on checked values).
	 *
	 * @param mixed $staging The version of the staging side.
	 * @param mixed $live    The version of this site.
	 * @return string|null
	 */
	public static function order( $staging, $live ) {
		$s = AB_MCP_Store_Inputs::read( 'unit.version', $staging );
		$l = AB_MCP_Store_Inputs::read( 'unit.version', $live );
		if ( null === $s || null === $l ) {
			return null;
		}
		$way = version_compare( $s, $l );
		return 0 === $way ? 'same' : ( $way > 0 ? 'newer' : 'older' );
	}
}
