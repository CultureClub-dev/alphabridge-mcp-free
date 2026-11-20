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
 *   site has, in one call (AB_MCP_Sync_Code_Swap::run()), after the entrance
 *   asked the release of its code (released(), Z24): with the stage the
 *   journal of its stage step names and the time of the call
 *   (AB_MCP_Sync_Code_Swap::until()). The swap writes its own journal and
 *   end; a refusal leaves the step planned.
 *
 * THE ONE ENTRANCE OF THE WAY BACK (back(); plan Z22, N12): the core asks
 * it through its place (AB_MCP_Sync_Revert::code()), which the last line of
 * this file fills, never by this name: the preview of the way back of a
 * swap step (plan), of its continuation (continue), the step of the way
 * back itself (revert, AB_MCP_Sync_Code_Swap::back()), and a step left
 * begun of a run a person closes as checked (checked, way (a);
 * AB_MCP_Sync_Run::close_checked()). accept of the way back is refused
 * here for a step that is no swap of a plugin that ran when it began
 * (Z25, Z43). Where this file is not loaded, a run of the code is neither
 * planned back, run back nor closed as checked.
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
		// The run of a step in the form of runs.run_id, and a step with
		// fields not read (unread) is not known: neither staged nor swapped,
		// the step stays as it is (V19).
		$run_id = AB_MCP_Store_Inputs::read( 'runs.run_id', $run['run_id'] );
		if ( null === $run_id || ( isset( $step['unread'] ) && array() !== $step['unread'] ) ) {
			return self::journal_unknown( $item['unit']['seq'] );
		}
		if ( 'stage' === $item['unit']['op'] ) {
			return self::stage( $run_id, $step, $item );
		}
		// A unit its stage step found as this site has it has nothing to swap
		// (G1 of GitHub Codex on #155): skipped, before the release, which is
		// asked only for code that is swapped.
		if ( self::same( $run_id, $step, $item['unit'] ) ) {
			/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name, 3: number of the step that was to stage it. */
			$message = sprintf( __( 'Skipped: when step %3$d was to stage it, this site already had the %1$s %2$s as the staging site has it (the same files), so there is nothing to swap and nothing of it changed. Make a new report (wp_site_diff): where it still shows the unit as changed, confirm its pair anew (wp_site_pairs).', 'alphabridge-mcp' ), $item['unit']['kind'], $item['unit']['ref'], $item['unit']['stage_seq'] );
			return self::ended( $run_id, $item['unit']['seq'], 'skipped', array( 'message' => $message, 'same' => true ), $message );
		}
		// The swap runs code of the unit (Z24): released or not, read now.
		$released = self::released( $item['unit'] );
		if ( is_wp_error( $released ) ) {
			return $released;
		}
		return self::swap( $run_id, $step, $item );
	}

	/**
	 * The one entrance of the way back of the code (plan Z22, N12), the
	 * place AB_MCP_Sync_Revert::code() asks; the last line of this file puts
	 * it there, beside run(), the entrance of the swap. plan: a write step
	 * of the run that wrote, null where it wrote nothing (a step that
	 * staged, or a swap planned, skipped or failed: the old version at its
	 * place), else what the preview shows and the token binds; continue: a
	 * step of the way back still open, for the preview of its continuation;
	 * revert: the step of the way back runs; checked: a step left begun of
	 * a run a person closes as checked (way (a): its own step, or one of its
	 * way back). The unit from the plan of the run that wrote (item()), the
	 * swap from its step; the rest is the swap's
	 * (AB_MCP_Sync_Code_Swap::back()). accept (plan) is refused where the
	 * swap does not take it into the entry of the way back: no swap of a
	 * plugin that ran when it began (Z25, Z43).
	 *
	 * @param string $at      plan, continue, revert or checked.
	 * @param array  $run     The run (for continue and revert: of the way back).
	 * @param array  $step    A write step of the run (plan), else a step of the way back.
	 * @param array  $forward The run that wrote.
	 * @param bool   $accept  plan: the step is in accept of wp_site_revert.
	 * @return array|null|WP_Error
	 */
	public static function back( $at, array $run, array $step, array $forward, $accept = false ) {
		$own  = 'plan' === $at || 'revert' !== $step['op'];
		$seq  = (int) ( $own ? $step['seq'] : ( is_array( $step['parts'] ) && isset( $step['parts']['forward_seq'] ) ? $step['parts']['forward_seq'] : 0 ) );
		$item = self::item( $forward['plan'], $seq );
		$swap = $own ? $step : AB_MCP_Sync_Run::step( (int) $forward['run_id'], $seq );
		if ( is_wp_error( $swap ) ) {
			return $swap;
		}
		$back = $own && ! is_wp_error( $item ) && ( 'swap' !== $item['unit']['op'] || ! in_array( $step['state'], array( 'begun', 'unclear', 'done' ), true ) ) ? null : AB_MCP_Sync_Code_Swap::back( $at, $run, $step, is_wp_error( $item ) ? null : $item['unit'], $swap, $accept );
		if ( $accept && ! is_wp_error( $back ) && empty( $back['item']['accept'] ) ) {
			/* translators: 1: step number, 2: run number. */
			return new WP_Error( 'ab_mcp_sync_code_accept', sprintf( __( 'Step %1$d of run %2$d is in accept, but it is no swap of a plugin that ran when the swap began, so its way back activates nothing: accept takes the steps whose plugin the way back activates again, with its activation routine, where it is inactive after it. Pass accept without %1$d.', 'alphabridge-mcp' ), $seq, (int) $run['run_id'] ) );
		}
		return $back;
	}

	/**
	 * Z24, the one rule of the release (E8): an operation that runs code of
	 * a unit runs only where that code does not run on this site now, or
	 * where the plan holds the release of the person (accept). Whether it
	 * runs is read now (AB_MCP_Sync_Code_Swap::runs()); not known is no
	 * state the release covers. The entrance (run()) asks it, and the swap
	 * asks it again under its locks (AB_MCP_Sync_Code_Swap::prepare()), each
	 * by this function alone.
	 *
	 * @param array $unit The unit of the step, in the form of its row plan.code (kind, ref, unit_op, accept).
	 * @return bool|WP_Error Whether code of the unit runs now; the refusal where that is not known, or it runs without its release.
	 */
	public static function released( array $unit ) {
		$runs = AB_MCP_Sync_Code_Swap::runs( $unit['kind'], $unit['ref'] );
		if ( null === $runs ) {
			/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name. */
			return new WP_Error( 'ab_mcp_sync_code_runs_unknown', sprintf( __( 'Whether code of the %1$s %2$s runs on this site could not be read now (the options active_plugins, stylesheet and template do not read in the form WordPress writes them, or the theme was switched while they were read), so it is not swapped. Check the plugins and the theme of this site in wp-admin (deactivating and activating a plugin makes WordPress write the option anew), then call action=next again; or abort the run (wp_site_push action=abort).', 'alphabridge-mcp' ), $unit['kind'], $unit['ref'] ) );
		}
		// A unit the plan makes new that WordPress names as running: its
		// folder was missing at the report, so what of it runs here is not
		// known, with accept or without (the decision of the lead on finding 2
		// of part 2a; no claim that its code runs).
		if ( $runs && 'create' === $unit['unit_op'] ) {
			/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name. */
			return new WP_Error( 'ab_mcp_sync_code_create_runs', sprintf( __( 'The plan makes the %1$s %2$s new on this site, and WordPress names it as running here now (a main file of it in the option active_plugins, or the theme that runs or its parent), so what of it runs here is not known and it is not swapped. Check the plugins and the theme of this site in wp-admin (the page Plugins deactivates a plugin whose files are missing), then call action=next again; or abort the run (wp_site_push action=abort), make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), $unit['kind'], $unit['ref'] ) );
		}
		if ( $runs && true !== $unit['accept'] ) {
			/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name. */
			return new WP_Error( 'ab_mcp_sync_code_not_released', sprintf( __( 'Code of the %1$s %2$s runs on this site now, and the plan of this run does not release it (its number was not in accept), so it is not swapped. Deactivate it in wp-admin (or switch to another theme) and call action=next again; or abort the run (wp_site_push action=abort), make a new report (wp_site_diff) and plan again with its number in accept.', 'alphabridge-mcp' ), $unit['kind'], $unit['ref'] ) );
		}
		return $runs;
	}

	/**
	 * What the check of a run finds of a step of the code that is done
	 * (AB_MCP_Sync_Engine::run_verify()): nothing of a step that staged a
	 * unit, which counts() leaves out of the check (its stage is checked by its
	 * swap, AB_MCP_Sync_Code_Swap). Of a swap, whether its unit is at its
	 * target as the swap wrote it (G3 of GitHub Codex on #155): its journal
	 * (AB_MCP_Sync_Code_Swap::journal_of()), the unit read whole now as the
	 * swap read it at its end (AB_MCP_Sync_File_Manifest::unit()) and its
	 * hash held against the new version of the journal; the same bytes at a
	 * new inode are that version. A reading that is not the first work of
	 * its call has the rest of the call ($until) and stops where it ends:
	 * false, no finding, and the next call reads it as its first work, with
	 * the time of a whole call (AB_MCP_Sync_Code_Swap::until()); a unit not
	 * read whole then is a finding (GRENZEN-CODE.md, G-5-25). Null for no
	 * finding, else a whole sentence.
	 *
	 * @param array      $step  The step.
	 * @param array      $item  item() of it.
	 * @param float|null $until The end of the rest of the call; null where this is the first work of the call.
	 * @return string|null|false
	 */
	public static function finding( array $step, array $item, $until ) {
		$unit = $item['unit'];
		$journal = AB_MCP_Sync_Code_Swap::journal_of( $unit, $step );
		if ( null === $journal ) {
			/* translators: 1: step number, 2: kind of the unit, as the tools name it (plugin, theme), 3: its name. */
			return sprintf( __( 'Step %1$d swapped the %2$s %3$s, but its journal does not read back as the swap wrote it (its form, its unit or its target), so whether the unit is at its target as the swap wrote it is not checked. Check the unit by hand in wp-admin, and make a new report (wp_site_diff) before the next takeover.', 'alphabridge-mcp' ), (int) $step['seq'], $unit['kind'], $unit['ref'] );
		}
		$read = AB_MCP_Sync_File_Manifest::unit( dirname( $journal['target'] ), $unit['ref'], 'live', null === $until ? AB_MCP_Sync_Code_Swap::until() : $until, null === $until, $unit['kind'] );
		if ( true === $read['complete'] && is_string( $read['hash'] ) && hash_equals( $journal['new']['hash'], $read['hash'] ) ) {
			return null;
		}
		if ( true === $read['stopped'] ) {
			return false;
		}
		/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name, 3: the folder or file of the unit (absolute path), 4: step number. */
		return sprintf( __( 'The %1$s %2$s at %3$s could not be confirmed as the version step %4$d swapped in: it is missing, changed since the swap, or not readable whole within the time of one call. Check it in wp-admin, and make a new report (wp_site_diff) before the next takeover.', 'alphabridge-mcp' ), $unit['kind'], $unit['ref'], $journal['target'], (int) $step['seq'] );
	}

	/* ------------------------------------------------------------ stage */

	/**
	 * The step «bereitstellen» of a unit, one call of it: without a journal
	 * yet, the unit is built and put into the area stage (once); with one,
	 * its check goes on for this call. The step stays planned until the stage
	 * is ready (done), refused (failed), or the unit is here already as the
	 * staging site has it (done, nothing to swap).
	 *
	 * @param int   $run_id The run (runs.run_id).
	 * @param array $step   The step.
	 * @param array $item   item().
	 * @return array|WP_Error next.
	 */
	private static function stage( $run_id, array $step, array $item ) {
		$unit = $item['unit'];
		$seq  = $unit['seq'];
		if ( null === $step['parts'] ) {
			$staged = AB_MCP_Sync_Code_Stage::stage( $run_id, $item['env'], $unit['kind'], $unit['ref'], $item['packages'] );
			if ( is_wp_error( $staged ) ) {
				return self::not_staged( $run_id, $seq, $unit, $staged->get_error_code(), $staged->get_error_message(), $staged->get_error_data() );
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

	/* ------------------------------------------------------------- swap */

	/**
	 * The step «tauschen» of a unit, one call (AB_MCP_Sync_Code_Swap::run()):
	 * the stage the journal of its stage step names (done, in the form of
	 * its row step.stage, of this unit), the unit and the packages of the
	 * environment of the plan, the run, and when the swap has to be over
	 * (AB_MCP_Sync_Code_Swap::until()). A swap step that holds a journal
	 * already, or whose stage step does not read so, is not known: nothing
	 * is swapped on it.
	 *
	 * @param int   $run_id The run (runs.run_id).
	 * @param array $step   The step.
	 * @param array $item   item().
	 * @return array|WP_Error next.
	 */
	private static function swap( $run_id, array $step, array $item ) {
		$unit  = $item['unit'];
		$stage = null === $unit['stage_seq'] ? null : AB_MCP_Sync_Run::step( $run_id, $unit['stage_seq'] );
		$read  = is_array( $stage ) && 'done' === $stage['state'] && ( ! isset( $stage['unread'] ) || array() === $stage['unread'] ) ? self::staged( $stage['parts'] ) : null;
		if ( null !== ( $step['parts'] ?? null ) || null === $read || $read['unit']['kind'] !== $unit['kind'] || $read['unit']['ref'] !== $unit['ref'] ) {
			return self::journal_unknown( $unit['seq'] );
		}
		return AB_MCP_Sync_Code_Swap::run(
			array(
				'run_id' => $run_id,
				'unit'   => $unit,
				'env'    => $item['env'],
				'stage'  => $read['stage'],
				'until'  => AB_MCP_Sync_Code_Swap::until(),
			)
		);
	}

	/**
	 * Whether a swap has nothing to swap (G1 of GitHub Codex on #155): it
	 * holds no journal, and its stage step is done as stage() ends it where
	 * this site has the unit as the staging site has it: no journal, every
	 * field read, its result same, of this very unit. Anything else is not
	 * that: the swap goes its way, which refuses a stage step that does not
	 * read (journal_unknown()).
	 *
	 * @param int   $run_id The run (runs.run_id).
	 * @param array $step   The swap step.
	 * @param array $unit   Its unit (item()).
	 * @return bool
	 */
	private static function same( $run_id, array $step, array $unit ) {
		$stage = null === $unit['stage_seq'] || null !== ( $step['parts'] ?? null ) ? null : AB_MCP_Sync_Run::step( $run_id, $unit['stage_seq'] );
		return is_array( $stage ) && 'done' === $stage['state'] && ( ! isset( $stage['unread'] ) || array() === $stage['unread'] ) && null === $stage['parts'] && is_array( $stage['result'] )
			&& true === ( $stage['result']['same'] ?? null ) && array( 'kind' => $unit['kind'], 'ref' => $unit['ref'] ) === ( $stage['result']['staged'] ?? null );
	}

	/**
	 * Whether the check of a run (AB_MCP_Sync_Engine::run_verify()) takes a
	 * step for an object written: every write step of a run of the contents,
	 * and one whose unit does not read (written() names that); of the code
	 * neither a stage done, which changed nothing of the site, nor a swap
	 * skipped as the same (same(); G1 of GitHub Codex on #155, Fable check
	 * 2, C4).
	 *
	 * @param array $run  The run.
	 * @param array $step A step of it.
	 * @return bool
	 */
	public static function counts( array $run, array $step ) {
		$item = 'write' === $step['op'] && is_array( $run['plan'] ) ? self::item( $run['plan'], $step['seq'] ) : null;
		if ( ! is_array( $item ) ) {
			return 'write' === $step['op'];
		}
		if ( 'stage' === $item['unit']['op'] ) {
			return 'done' !== $step['state'];
		}
		return ! ( 'skipped' === $step['state'] && is_array( $step['result'] ) && true === ( $step['result']['same'] ?? null ) );
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
	 * @param string $state   done, failed or skipped.
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
	 * swap that depends on it is skipped. A unit whose packages do not hold
	 * every file (code:stage_incomplete, F43) has the paths of the files
	 * missing in its answer and its result, and the way: a new run with
	 * every package, for action=next does not stage it again (Codex D4,
	 * finding 5); as many of them as the result of a step holds (missing()).
	 *
	 * @param int    $run_id  Run.
	 * @param int    $seq     Step.
	 * @param array  $unit    The unit of the plan.
	 * @param string $code    The code of the refusal.
	 * @param string $why     Its message (one or more whole sentences).
	 * @param mixed  $data    The data of the refusal (code:stage_incomplete: paths).
	 * @return array|WP_Error next.
	 */
	private static function not_staged( $run_id, $seq, array $unit, $code, $why, $data = null ) {
		/* translators: 1: kind of the unit, as the tools name it (plugin, theme), 2: its name, 3: the code of the refusal, as the tools name it (code:syntax, …), 4: the refusal (one or more whole sentences). */
		$message = sprintf( __( 'The %1$s %2$s is not staged (%3$s), so it is not swapped; nothing of this site changed. %4$s', 'alphabridge-mcp' ), $unit['kind'], $unit['ref'], $code, $why );
		$result  = array(
			'message' => $message,
			'code'    => $code,
		);
		$paths   = 'code:stage_incomplete' === $code && is_array( $data ) && isset( $data['paths'] ) && is_array( $data['paths'] ) ? array_values( array_filter( $data['paths'], 'is_string' ) ) : array();
		if ( array() !== $paths ) {
			$result = self::missing( $result, $paths, $unit );
		}
		return self::ended( $run_id, $seq, 'failed', $result, $result['message'] . ' ' . __( 'The run goes on with the next step (action=next).', 'alphabridge-mcp' ) );
	}

	/**
	 * The result of a unit not staged with the paths of the files missing
	 * (F43): all of them where the result is then one a step holds
	 * (holds()), as an answer of F43 read before. Where it is not (Codex D5:
	 * a path stands in the message and in paths, and a quotation mark of it
	 * takes six bytes as stored, so 2000 long paths are more than a result
	 * holds; the step then stayed planned with ab_mcp_sync_run_too_large
	 * instead of this answer), the most of the first ones that fit, found
	 * by halving between a number that fits and one that does not: one path
	 * beside the message fits (the path of a file is a few thousand bytes at
	 * most), all of them do not, and each path more makes the result larger.
	 * Were one path too much already, the run refuses the result as before
	 * and the step stays planned.
	 *
	 * @param array    $result The result so far: message, code.
	 * @param string[] $paths  The paths of the files missing, at least one.
	 * @param array    $unit   The unit of the plan.
	 * @return array The result: message, code, paths.
	 */
	private static function missing( array $result, array $paths, array $unit ) {
		$whole = self::named( $result, $paths, count( $paths ), $unit );
		if ( self::holds( $whole ) ) {
			return $whole;
		}
		unset( $whole );
		$fits = 1;
		$over = count( $paths );
		while ( $over - $fits > 1 ) {
			$half = intdiv( $fits + $over, 2 );
			if ( self::holds( self::named( $result, $paths, $half, $unit ) ) ) {
				$fits = $half;
			} else {
				$over = $half;
			}
		}
		return self::named( $result, $paths, $fits, $unit );
	}

	/**
	 * Whether a result is one a step holds as stored: in the JSON the run
	 * stores it in (AB_MCP_Sync_Canon::encode(), as update_step() writes
	 * it) at most AB_MCP_Sync_Run::MAX_PARTS bytes.
	 *
	 * @param array $result A result of a step.
	 * @return bool
	 */
	private static function holds( array $result ) {
		return strlen( AB_MCP_Sync_Canon::encode( $result ) ) <= AB_MCP_Sync_Run::MAX_PARTS;
	}

	/**
	 * The result of a unit not staged with the first $n of the paths of the
	 * files missing, in its message and in paths, and the way: a new run
	 * with every package. Where paths are left out, the message says how
	 * many it names and how many not, and that the new run names the next
	 * ones: its stage misses only what neither a package of it nor the live
	 * unit holds, so each run of this way names files no run before it
	 * named, until none is missing (AB_MCP_Sync_Code_Stage::
	 * from_packages()).
	 *
	 * @param array    $result The result so far: message, code.
	 * @param string[] $paths  The paths of the files missing.
	 * @param int      $n      How many of them are named, from the first.
	 * @param array    $unit   The unit of the plan.
	 * @return array The result: message, code, paths.
	 */
	private static function named( array $result, array $paths, $n, array $unit ) {
		$first = array_slice( $paths, 0, $n );
		$list  = (string) wp_json_encode( $first, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$left  = count( $paths ) - $n;
		if ( 0 === $left ) {
			/* translators: 1: the paths of the files missing, as a JSON list, 2: kind of the unit, as the tools name it (plugin, theme), 3: its name. */
			$way = sprintf( __( 'The paths of the files missing here (the first 2000 where more are missing), for paths of wp_site_export: %1$s. This run does not stage the %2$s %3$s again: plan a new run (wp_site_push action=plan) with every package of the unit, the one with these files as well; where this run is still open, abort it first (wp_site_push action=abort).', 'alphabridge-mcp' ), $list, $unit['kind'], $unit['ref'] );
		} else {
			/* translators: 1: how many paths the list names, 2: the paths of the first files missing, as a JSON list, 3: the most bytes the result of a step holds, 4: how many paths the list leaves out, 5: kind of the unit, as the tools name it (plugin, theme), 6: its name. */
			$way = sprintf( __( 'The paths of the files missing here, the first %1$d of them, for paths of wp_site_export: %2$s. The result of a step holds at most %3$d bytes as stored, so this list leaves out %4$d of the paths it takes (the first 2000 where more are missing). This run does not stage the %5$s %6$s again: plan a new run (wp_site_push action=plan) with every package of the unit, the one with the files named here as well; where this run is still open, abort it first (wp_site_push action=abort). Where files are still missing then, the new run names the next ones in the same way: export those as well and plan again, until the unit is staged.', 'alphabridge-mcp' ), $n, $list, AB_MCP_Sync_Run::MAX_PARTS, $left, $unit['kind'], $unit['ref'] );
		}
		// One text made of the three (megabytes where the paths are long).
		$result['message'] = $result['message'] . ' ' . $way;
		$result['paths']   = $first;
		return $result;
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

// The one entrance of the way back of the code and the one of its forward
// way, in the file of the entrance of the swap (N12; D5 of the seam): where
// this file is not loaded, the core plans and runs no way back of the code
// (AB_MCP_Sync_Revert::code()) and runs no step of it
// (AB_MCP_Sync_Engine::code_item()).
require_once __DIR__ . '/class-sync-revert.php';
AB_MCP_Sync_Revert::admit( array( 'AB_MCP_Sync_Code_Ops', 'back' ) );
AB_MCP_Sync_Engine::admit_code( 'AB_MCP_Sync_Code_Ops' );
