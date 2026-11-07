<?php
/**
 * The engine of a run (Z8, decisions 15 to 20, 23, 32 and 33 of the plan of
 * 01.11.2026; branch 8): one step per call, in the order of the plan, each
 * step a write boundary of its own.
 *
 * THE STEPS of a run of wp_site_push (decision 16): phase 1, the check of
 * everything before the first write (run_check(): the means, the booking
 * of the store, the lock available, the packages of objects against the
 * report, every target as it is now, the rights, the status WordPress would
 * store, the rule of this version for each object; one deviation closes
 * the run as aborted before anything is written); phase 5, one write step
 * per object in the order of the graph (a step whose dependency is not
 * done is skipped, «waits for»); phase 7, the caches (run_post()); phase 8,
 * the check of the run (run_verify(), over calls as the check: the hash of
 * each written pair against its target, a sample of a published object
 * answering 200 with the title of the object, the PHP probe passing whole,
 * the error log without new lines), which closes the run as done or with
 * findings; a qualified close marks forward_closed_at where every write
 * step was written (decision 20).
 *
 * THE WRITE BOUNDARY (decision 17, run_write()): the lock
 * (AB_MCP_Change_Lock::take(); busy: the step stays planned and the answer
 * says so); the step begun with the snapshot of its pair; the takeover lock
 * of the objects (AB_MCP_Sync_Guard); the target and the rights checked
 * again; the backup kept and read back (AB_MCP_Sync_Backup); the journal
 * written ahead of every part (the row, each meta key, each taxonomy: its
 * hash before, its target, its backup, its state), then each part written
 * through the kind (AB_MCP_Sync_Post_Writer) and read back from the rows
 * under the same lock, its hash in the journal; a part that reads back
 * other than its target is «written, deviating»: put back at once where
 * its way is refusable (the row, meta) and the bytes are still what the
 * step wrote, left unclear otherwise (terms); the object as a whole read
 * back against the target (the mapped staging form with the confirmed
 * choice); then the pair takes its new bases (or is made, for a new
 * object), the step is done, the guard and the lock go. A request that
 * ends in a boundary leaves the step begun: AB_MCP_Sync_Run::settle()
 * makes the run interrupted once the lock is no longer held (decision 18).
 *
 * THE WAY BACK (decision 23, run_revert()): for one write step of the run
 * (or of a closed run, a run of the type revert), each part in reverse
 * order from its journal: put back where its hash now is the hash the step
 * read back, nothing where it is the hash before, left and named otherwise;
 * a new object is never removed and stays as a rest, its pair residual
 * (rule 0); the pair of a restored object goes back to its snapshot. A run
 * of the way back closes as reverted, or with findings where rests or
 * unclear parts remain.
 *
 * ANSWERS (decision 32): the step with its result and journal as fields; a
 * WP_Error only for the refusal of the whole call.
 *
 * Messages are English without gettext, as the other classes of the
 * takeover; each names the way.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-run.php';
require_once __DIR__ . '/class-sync-backup.php';
require_once __DIR__ . '/class-sync-guard.php';
require_once __DIR__ . '/class-sync-resources.php';
require_once __DIR__ . '/class-sync-post-writer.php';
require_once __DIR__ . '/class-sync-import.php';
require_once __DIR__ . '/class-sync-anchor.php';
require_once __DIR__ . '/class-sync-urls.php';
require_once __DIR__ . '/class-sync-inventory.php';
require_once dirname( __DIR__ ) . '/class-store-budget.php';
require_once dirname( __DIR__ ) . '/lifecycle/class-change-lock.php';

/**
 * Class AB_MCP_Sync_Engine
 */
final class AB_MCP_Sync_Engine {

	/** Seconds one call may work (the budget of the other long tools). */
	const BUDGET = 60;

	/** Seconds the check and the verification keep for one more object; with less left, the next call goes on. */
	const RESERVE = 10;

	/** The clock: a callable returning seconds (float), null for microtime(). Set by tests only. */
	public static $clock = null;

	/** A probe of a published object: callable( string $url ): array{status:int|null,body:string}|int|null, null for wp_remote_get(). Set by tests only. */
	public static $probe = null;

	/* --------------------------------------------------------------- next */

	/**
	 * Run one step of a run: the next one whose dependencies are done, or a
	 * named one (refused while a dependency is open). The run has to be
	 * open (or reverting for a step of the way back), settled and the
	 * caller's own.
	 *
	 * @param array    $run   The run (AB_MCP_Sync_Run::get()).
	 * @param int|null $named The seq of the step to run; null for the next.
	 * @return array|WP_Error The answer (answer()).
	 */
	public static function next( array $run, $named = null ) {
		$started = self::now();
		$steps   = AB_MCP_Sync_Run::steps( (int) $run['run_id'] );
		if ( is_wp_error( $steps ) ) {
			return $steps;
		}
		$ctx = self::context( $run );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		$picked = self::pick( $run, $steps, $named );
		if ( is_wp_error( $picked ) ) {
			return $picked;
		}
		if ( null === $picked ) {
			// Nothing left to run: a run of the way back closes here.
			if ( 'reverting' === $run['state'] || 'revert' === $run['type'] ) {
				$closed = self::close_revert( $run );
				if ( is_wp_error( $closed ) ) {
					return $closed;
				}
				return self::answer( $closed, null, $started, 'Every step of the way back ran; the run is closed. The rests it left are named in rests.' );
			}
			return self::answer( $run, null, $started, 'No step is left to run: the run is closed, or every remaining step waits on one that was skipped or failed (see the steps in status).' );
		}
		switch ( $picked['op'] ) {
			case 'check':
				$done = self::run_check( $run, $picked, $ctx, $started );
				break;
			case 'write':
				$done = self::run_write( $run, $picked, $ctx );
				break;
			case 'post':
				$done = self::run_post( $run, $picked, $ctx );
				break;
			case 'verify':
				$done = self::run_verify( $run, $picked, $ctx, $started );
				break;
			case 'revert':
				$done = self::run_revert( $run, $picked, $ctx );
				break;
			default:
				return new WP_Error( 'ab_mcp_sync_run_invalid', sprintf( 'Step %d has an operation this version does not run. Ask for the status of the run (wp_site_push action=status).', (int) $picked['seq'] ) );
		}
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		$now  = AB_MCP_Sync_Run::get( (int) $run['run_id'] );
		$step = AB_MCP_Sync_Run::step( (int) $run['run_id'], (int) $picked['seq'] );
		// A run of the way back closes once no step is left.
		if ( is_array( $now ) && ( 'reverting' === $now['state'] || ( 'revert' === $now['type'] && 'open' === $now['state'] ) ) && null === self::pick( $now, AB_MCP_Sync_Run::steps( (int) $run['run_id'] ), null, true ) ) {
			$closed = self::close_revert( $now );
			if ( ! is_wp_error( $closed ) ) {
				$now = $closed;
			}
		}
		return self::answer( is_array( $now ) ? $now : $run, is_array( $step ) ? $step : null, $started, $done['next'], isset( $done['busy'] ) ? $done['busy'] : false );
	}

	/**
	 * Abort an open run: closed as aborted; what its steps wrote stays (the
	 * way back is wp_site_revert for the run), its booking is given back.
	 *
	 * @param array $run Run.
	 * @return array|WP_Error The run as closed.
	 */
	public static function abort( array $run ) {
		if ( 'open' !== $run['state'] ) {
			return new WP_Error( 'ab_mcp_sync_run_state', sprintf( 'Only an open run is aborted; this run is %s. An interrupted run is closed as checked by a person on the page AlphaBridge → Staging, or taken back with wp_site_revert; a run of the way back runs to its end.', $run['state'] ) );
		}
		$steps = AB_MCP_Sync_Run::steps( (int) $run['run_id'] );
		if ( is_wp_error( $steps ) ) {
			return $steps;
		}
		foreach ( $steps as $step ) {
			if ( 'begun' === $step['state'] ) {
				return new WP_Error( 'ab_mcp_sync_run_busy', sprintf( 'Step %d of the run is being written right now; call again when it has answered.', (int) $step['seq'] ) );
			}
		}
		$moved = AB_MCP_Sync_Run::set_state( (int) $run['run_id'], 'open', 'closed_aborted' );
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}
		AB_MCP_Store_Budget::release( (int) $run['run_id'] );
		AB_MCP_Protected_Store::cleanup();
		$closed = AB_MCP_Sync_Run::get( (int) $run['run_id'] );
		return is_array( $closed ) ? $closed : $run;
	}

	/* ----------------------------------------------------------- context */

	/**
	 * What every step of a run needs: the map of the pairs (staging id =>
	 * live id, by space), the addresses of both sites, the routes of this
	 * site, the source of the canonical form of this site.
	 *
	 * @param array $run Run.
	 * @return array|WP_Error
	 */
	public static function context( array $run ) {
		$partner = AB_MCP_Sync_Anchor::partner();
		if ( is_wp_error( $partner ) ) {
			return $partner;
		}
		$map   = array(
			'post' => array(),
			'term' => array(),
		);
		$after = 0;
		$pages = 0;
		do {
			$page = AB_MCP_Sync_Pairs::page_source( (string) $run['source_id'], $after );
			if ( is_wp_error( $page ) ) {
				return $page;
			}
			foreach ( $page['pairs'] as $pair ) {
				if ( 'active' !== $pair['state'] ) {
					continue;
				}
				foreach ( AB_MCP_Sync_Proposal::SPACES as $space => $kinds ) {
					if ( in_array( $pair['kind'], $kinds, true ) && 1 === preg_match( '/^[1-9][0-9]*\z/', (string) $pair['staging_ref'] ) && 1 === preg_match( '/^[1-9][0-9]*\z/', (string) $pair['live_ref'] ) ) {
						$map[ $space ][ (int) $pair['staging_ref'] ] = (int) $pair['live_ref'];
					}
				}
			}
			$after = $page['next'];
			++$pages;
		} while ( null !== $after && $pages < 1000 );
		$home = home_url();
		return array(
			'map'       => $map,
			'addresses' => array( (string) $partner => (string) $home ),
			'routes'    => AB_MCP_Sync_Urls::routes(),
			'source'    => AB_MCP_Sync_Inventory::source( array( $home, $partner ), AB_MCP_Sync_Inventory::BUDGET ),
			'now'       => AB_MCP_Sync_Run::now(),
			'users'     => array(),
		);
	}

	/**
	 * The lines of the packages of objects of a run, by kind NUL staging
	 * ref, each checked for its report; and the objects held here.
	 *
	 * @param array $run Run.
	 * @return array{lines:array,held:array<string,string>}|WP_Error
	 */
	public static function lines( array $run ) {
		$plan  = is_array( $run['plan'] ) ? $run['plan'] : array();
		$lines = array();
		$held  = array();
		foreach ( isset( $plan['packages'] ) && is_array( $plan['packages'] ) ? $plan['packages'] : array() as $package_id ) {
			$taken = AB_MCP_Sync_Import::taken( $package_id, 'objects' );
			if ( is_wp_error( $taken ) ) {
				return $taken;
			}
			if ( ! hash_equals( (string) $run['report_hash'], (string) $taken['manifest']['report_hash'] ) ) {
				return new WP_Error( 'ab_mcp_sync_objects_report', sprintf( 'The package of objects %s was made for another report than the one of this run, and a package is used for its own report only. Export the objects again on the staging site for this report (wp_site_export with purpose=objects and its report_hash).', $package_id ) );
			}
			foreach ( $taken['lines'] as $line ) {
				if ( is_array( $line ) && isset( $line['kind'], $line['ref'] ) ) {
					$lines[ (string) $line['kind'] . "\0" . AB_MCP_Sync_Post_Writer::bytes( $line['ref'] ) ] = $line;
				}
			}
			foreach ( $taken['held'] as $one ) {
				if ( is_array( $one ) && isset( $one[0], $one[1], $one[2] ) ) {
					$held[ (string) $one[0] . "\0" . (string) $one[1] ] = (string) $one[2];
				}
			}
		}
		return array(
			'lines' => $lines,
			'held'  => $held,
		);
	}

	/**
	 * The line of an item among the lines of the run, checked: there, not
	 * held, its hash the one the report compared.
	 *
	 * @param array $item  Item of the plan.
	 * @param array $lines lines().
	 * @return array|WP_Error
	 */
	public static function line_of( array $item, array $lines ) {
		$key = (string) $item['kind'] . "\0" . (string) $item['staging_ref'];
		if ( ! isset( $lines['lines'][ $key ] ) ) {
			return new WP_Error( 'ab_mcp_sync_objects_missing', sprintf( 'The packages of objects of this run hold no %1$s %2$s of the staging site. Export it there (wp_site_export with purpose=objects, the report_hash and this ref), take the package over here (wp_site_import_chunk) and plan again.', $item['kind'], $item['staging_ref'] ) );
		}
		$line = $lines['lines'][ $key ];
		$sha  = hash( 'sha256', AB_MCP_Sync_Canon::encode( (string) $item['staging_ref'] ) );
		if ( isset( $lines['held'][ (string) $item['kind'] . "\0" . $sha ] ) || ! isset( $line['hash'] ) ) {
			return new WP_Error( 'ab_mcp_sync_objects_held', sprintf( 'The %1$s %2$s of the staging site is held by the credential check of this site, so it is not written. Take it over by hand in wp-admin, or leave it out of the plan.', $item['kind'], $item['staging_ref'] ) );
		}
		if ( ! hash_equals( (string) $item['staging_hash'], (string) $line['hash'] ) ) {
			return new WP_Error( 'ab_mcp_sync_objects_changed', sprintf( 'The %1$s %2$s in the package of objects is not the one the report compared (its hash differs: it changed on the staging site since). Make a new report (wp_site_diff), export the objects again and plan again.', $item['kind'], $item['staging_ref'] ) );
		}
		return $line;
	}

	/* ------------------------------------------------------------- steps */

	/**
	 * The step to run: the named one, or the first planned one whose
	 * dependencies are done; a planned step whose dependency was skipped,
	 * failed, restored or left unclear is skipped («waits for …») on the
	 * way. Null where none is left.
	 *
	 * @param array    $run   Run.
	 * @param array[]  $steps Steps.
	 * @param int|null $named Seq.
	 * @param bool     $peek  Only look, change nothing.
	 * @return array|null|WP_Error
	 */
	private static function pick( array $run, array $steps, $named, $peek = false ) {
		$by = array();
		foreach ( $steps as $step ) {
			$by[ (int) $step['seq'] ] = $step;
		}
		if ( null !== $named ) {
			if ( ! isset( $by[ (int) $named ] ) ) {
				return new WP_Error( 'ab_mcp_sync_step_unknown', sprintf( 'The run has no step %d. Ask for its status to see its steps.', (int) $named ) );
			}
			$step = $by[ (int) $named ];
			if ( 'planned' !== $step['state'] ) {
				return new WP_Error( 'ab_mcp_sync_step_state', sprintf( 'Step %1$d is %2$s and runs no more; a step runs once. Use action=next for the next planned step.', (int) $named, $step['state'] ) );
			}
			foreach ( $step['depends'] as $dep ) {
				if ( ! isset( $by[ $dep ] ) || 'done' !== $by[ $dep ]['state'] ) {
					return new WP_Error( 'ab_mcp_sync_step_waits', sprintf( 'Step %1$d waits for step %2$d, which is %3$s: a step runs only once the objects it refers to are written. Use action=next to run the steps in order.', (int) $named, (int) $dep, isset( $by[ $dep ] ) ? $by[ $dep ]['state'] : 'unknown' ) );
				}
			}
			$earlier = self::earlier_phase_open( $by, $step );
			if ( null !== $earlier ) {
				return new WP_Error( 'ab_mcp_sync_step_waits', sprintf( 'Step %1$d runs in phase %2$d, after every step of the phases before it (decision 16); step %3$d is %4$s. Use action=next, which runs the steps in order.', (int) $named, (int) $step['phase'], (int) $earlier, $by[ $earlier ]['state'] ) );
			}
			return $step;
		}
		// A run on its way back runs its steps of the way back only.
		$reverting = 'reverting' === $run['state'] || 'revert' === $run['type'];
		foreach ( $steps as $step ) {
			if ( 'planned' !== $step['state'] || ( $reverting && 'revert' !== $step['op'] ) || ( ! $reverting && 'revert' === $step['op'] ) ) {
				continue;
			}
			$waits = null;
			$open  = false;
			foreach ( $step['depends'] as $dep ) {
				$state = isset( $by[ $dep ] ) ? $by[ $dep ]['state'] : 'unknown';
				if ( 'done' === $state ) {
					continue;
				}
				if ( in_array( $state, array( 'planned', 'begun' ), true ) ) {
					$open = true;
					continue;
				}
				$waits = $dep;
			}
			if ( null !== $waits ) {
				if ( ! $peek ) {
					AB_MCP_Sync_Run::update_step(
						(int) $run['run_id'],
						(int) $step['seq'],
						array(
							'state'  => 'skipped',
							'result' => array( 'message' => sprintf( 'Skipped: waits for step %1$d, which is %2$s.', (int) $waits, $by[ $waits ]['state'] ) ),
						),
						'planned'
					);
				}
				continue;
			}
			if ( $open || null !== self::earlier_phase_open( $by, $step ) ) {
				continue;
			}
			return $step;
		}
		return null;
	}

	/**
	 * The phases of a run go in order (decision 16): a step of phase 7
	 * (post-processing) or 8 (the check of the run) runs only once every
	 * step of a lower phase has run, whatever became of it (done, failed,
	 * skipped, unclear); a step of the way back (phase 0) is outside the
	 * order. The seq of a step of a lower phase that is still planned or
	 * begun, or null (B2: a named step could close the run before its
	 * write steps).
	 *
	 * @param array[] $by   Steps by seq.
	 * @param array   $step Step.
	 * @return int|null
	 */
	private static function earlier_phase_open( array $by, array $step ) {
		$phase = (int) $step['phase'];
		if ( $phase < 1 ) {
			return null;
		}
		foreach ( $by as $other ) {
			if ( (int) $other['phase'] > 0 && (int) $other['phase'] < $phase && in_array( $other['state'], array( 'planned', 'begun' ), true ) ) {
				return (int) $other['seq'];
			}
		}
		return null;
	}

	/**
	 * Phase 1: the check of everything before the first write (decision
	 * 16; the means of 15.11; decision 8). One deviation closes the run as
	 * aborted; nothing is written. Over several calls where the objects are
	 * many (a cursor in the result of the step).
	 *
	 * @param array $run     Run.
	 * @param array $step    Step.
	 * @param array $ctx     context().
	 * @param float $started When the call began.
	 * @return array|WP_Error next, busy.
	 */
	private static function run_check( array $run, array $step, array $ctx, $started ) {
		$plan   = is_array( $run['plan'] ) ? $run['plan'] : array();
		$result = is_array( $step['result'] ) ? $step['result'] : array();
		$items  = isset( $plan['objects'] ) && is_array( $plan['objects'] ) ? $plan['objects'] : array();
		$at     = isset( $result['cursor'] ) ? (int) $result['cursor'] : 0;
		if ( 0 === $at ) {
			// The means and the booking (decision 8, 15.11).
			$bytes = isset( $plan['bytes'] ) ? (int) $plan['bytes'] : 0;
			$check = AB_MCP_Sync_Resources::check( $bytes );
			if ( AB_MCP_Sync_Resources::INSUFFICIENT === $check['level'] ) {
				return self::abort_at( $run, $step, 'The means of this site do not carry the run to its end: ' . AB_MCP_Sync_Resources::refusal( $check )->get_error_message(), array( 'resources' => $check ) );
			}
			if ( ! AB_MCP_Protected_Store::available() ) {
				return self::abort_at( $run, $step, AB_MCP_Protected_Store::refusal()->get_error_message(), array() );
			}
			$booked = AB_MCP_Store_Budget::book( (int) $run['run_id'], $check['booked'] );
			if ( is_wp_error( $booked ) ) {
				return self::abort_at( $run, $step, $booked->get_error_message(), array() );
			}
			AB_MCP_Sync_Run::set_budget( (int) $run['run_id'], $booked + array( 'resources' => $check['level'] ) );
			// The lock available (decision 18).
			$lock = AB_MCP_Change_Lock::take();
			if ( is_wp_error( $lock ) ) {
				return array(
					'next' => $lock->get_error_message(),
					'busy' => true,
				);
			}
			AB_MCP_Change_Lock::release();
			$result['resources'] = $check['level'];
			$result['notes']     = array();
			if ( empty( $plan['environment'] ) ) {
				$result['notes'][] = 'The environment of the two sites was not compared in the report (wp_site_diff without environment): the run writes objects only, which this does not hold back.';
			}
			$result['error_log_lines'] = self::error_log_lines();
			$result['checked']         = 0;
			$result['findings']        = array();
		}
		$lines = self::lines( $run );
		if ( is_wp_error( $lines ) ) {
			return self::abort_at( $run, $step, $lines->get_error_message(), $result );
		}
		// The objects the run creates: a reference to one of them is pending,
		// not missing (B1); the boundary maps it by the pair its step made.
		$ctx['pending'] = array(
			'post' => array(),
			'term' => array(),
		);
		foreach ( $items as $one ) {
			if ( isset( $one['op'], $one['kind'], $one['staging_ref'] ) && 'create' === $one['op'] && 'post' === $one['kind'] ) {
				$ctx['pending']['post'][ (int) $one['staging_ref'] ] = true;
			}
		}
		// The findings of the calls before, kept with the cursor (B3).
		$findings = isset( $result['findings'] ) && is_array( $result['findings'] ) ? $result['findings'] : array();
		$count    = count( $items );
		for ( $i = $at; $i < $count; $i++ ) {
			if ( $i > $at && self::now() - $started > self::BUDGET - self::RESERVE ) {
				$result['cursor']   = $i;
				$result['findings'] = $findings;
				AB_MCP_Sync_Run::update_step( (int) $run['run_id'], (int) $step['seq'], array( 'result' => $result ), 'planned' );
				AB_MCP_Sync_Run::touch( (int) $run['run_id'] );
				return array( 'next' => sprintf( 'The check went over %1$d of %2$d objects within the budget of this call; call action=next again to go on with it.', $i, $count ) );
			}
			$item    = $items[ $i ];
			$finding = self::check_item( $item, $lines, $ctx );
			if ( null !== $finding ) {
				$findings[] = $finding;
			}
			++$result['checked'];
		}
		$result['findings'] = $findings;
		if ( array() !== $findings ) {
			return self::abort_at( $run, $step, sprintf( 'The check before the first write found %d deviation(s) from the plan; nothing was written and the run is closed. Make a new report (wp_site_diff) and plan again.', count( $findings ) ), $result );
		}
		unset( $result['cursor'] );
		$result['message'] = sprintf( 'Checked %d object(s): the targets are as planned, the rights and the means suffice, the packages hold what the report compared.', $count );
		AB_MCP_Sync_Run::update_step( (int) $run['run_id'], (int) $step['seq'], array( 'state' => 'done', 'result' => $result ), 'planned' );
		AB_MCP_Sync_Run::touch( (int) $run['run_id'] );
		return array( 'next' => 'The check passed. Call action=next for the first write step; each call writes one object, under the lock, with its backup and journal.' );
	}

	/**
	 * One item of the plan checked against the site as it is now.
	 *
	 * @param array $item  Item.
	 * @param array $lines lines().
	 * @param array $ctx   context().
	 * @return array|null A finding (seq, object, message), or null.
	 */
	private static function check_item( array $item, array $lines, array $ctx ) {
		$name = sprintf( '%1$s %2$s of the staging site', $item['kind'], $item['staging_ref'] );
		$line = self::line_of( $item, $lines );
		if ( is_wp_error( $line ) ) {
			return self::finding( $item, $line->get_error_message() );
		}
		$live_id = null;
		if ( 'update' === $item['op'] ) {
			$live_id = (int) $item['live_ref'];
			$hash    = AB_MCP_Sync_Post_Writer::live_hash( $live_id, $ctx['source'] );
			if ( null === $hash || ! hash_equals( (string) $item['live_hash'], $hash ) ) {
				return self::finding( $item, sprintf( 'The %1$s %2$d of this site changed since the plan (or cannot be read whole), so the %3$s is not written over it.', $item['kind'], $live_id, $name ) );
			}
			$pair = AB_MCP_Sync_Pairs::get( (int) $item['pair_id'] );
			if ( ! is_array( $pair ) || 'active' !== $pair['state'] || ! hash_equals( (string) $item['pair_hash'], AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( (int) $item['pair_id'] ) ) ) ) {
				return self::finding( $item, sprintf( 'The pair %d changed since the plan.', (int) $item['pair_id'] ) );
			}
		} else {
			$pair = AB_MCP_Sync_Pairs::find_staging( (string) $item['source_id'], (string) $item['kind'], (string) $item['staging_ref'] );
			if ( is_array( $pair ) ) {
				return self::finding( $item, sprintf( 'The %1$s has a pair here since the plan (pair %2$d), so it is not created anew.', $name, (int) $pair['id'] ) );
			}
		}
		$prepared = AB_MCP_Sync_Post_Writer::target_of( $line, $item, $ctx );
		if ( is_wp_error( $prepared ) ) {
			return self::finding( $item, $prepared->get_error_message() );
		}
		$ok = AB_MCP_Sync_Post_Writer::precheck( $prepared, $live_id, (int) $ctx['now'] );
		if ( is_wp_error( $ok ) ) {
			return self::finding( $item, $ok->get_error_message() );
		}
		if ( null !== $live_id ) {
			$ok = self::live_meta_rule( $live_id );
			if ( is_wp_error( $ok ) ) {
				return self::finding( $item, $ok->get_error_message() );
			}
		}
		return null;
	}

	/**
	 * Whether every meta key of the post here is one this version writes
	 * (a key outside the rule would stay and make the written object differ
	 * from the target: fail closed).
	 *
	 * @param int $live_id Post.
	 * @return true|WP_Error
	 */
	private static function live_meta_rule( $live_id ) {
		$current = AB_MCP_Sync_Post_Writer::current( $live_id );
		if ( is_wp_error( $current ) || null === $current ) {
			return is_wp_error( $current ) ? $current : true;
		}
		foreach ( $current['meta'] as $row ) {
			if ( ! AB_MCP_Sync_Post_Writer::meta_allowed( $row['key'] ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'post:meta_rule', sprintf( 'meta key %s of the post on this site', $row['key'] ) );
			}
		}
		foreach ( $current['terms'] as $term ) {
			if ( ! in_array( $term['taxonomy'], AB_MCP_Sync_Post_Writer::TAXONOMIES, true ) ) {
				return AB_MCP_Sync_Post_Writer::locked( 'post:taxonomy_rule', sprintf( 'taxonomy %s of the post on this site', $term['taxonomy'] ) );
			}
		}
		return true;
	}

	/**
	 * Phase 5: one write boundary (decision 17).
	 *
	 * @param array $run  Run.
	 * @param array $step Step.
	 * @param array $ctx  context().
	 * @return array|WP_Error next, busy.
	 */
	private static function run_write( array $run, array $step, array $ctx ) {
		$plan = is_array( $run['plan'] ) ? $run['plan'] : array();
		$item = null;
		foreach ( isset( $plan['objects'] ) && is_array( $plan['objects'] ) ? $plan['objects'] : array() as $one ) {
			if ( (int) $one['seq'] === (int) $step['seq'] ) {
				$item = $one;
			}
		}
		if ( null === $item ) {
			return new WP_Error( 'ab_mcp_sync_run_invalid', sprintf( 'The plan of the run names no object for step %d. Ask for the status of the run (wp_site_push action=status).', (int) $step['seq'] ) );
		}
		$lock = AB_MCP_Change_Lock::take();
		if ( is_wp_error( $lock ) ) {
			return array(
				'next' => $lock->get_error_message(),
				'busy' => true,
			);
		}
		try {
			return self::boundary( $run, $step, $item, $ctx );
		} finally {
			AB_MCP_Sync_Guard::end( (int) $run['run_id'], (int) $step['seq'] );
			AB_MCP_Change_Lock::release();
		}
	}

	/**
	 * The boundary itself, under the lock.
	 *
	 * @param array $run  Run.
	 * @param array $step Step.
	 * @param array $item Item of the plan.
	 * @param array $ctx  context().
	 * @return array|WP_Error
	 */
	private static function boundary( array $run, array $step, array $item, array $ctx ) {
		$run_id  = (int) $run['run_id'];
		$seq     = (int) $step['seq'];
		$live_id = 'update' === $item['op'] ? (int) $item['live_ref'] : null;
		$before  = null;
		if ( null !== $live_id ) {
			$before = AB_MCP_Sync_Pairs::snapshot( (int) $item['pair_id'] );
			if ( is_wp_error( $before ) ) {
				return self::fail_step( $run_id, $seq, 'planned', $before->get_error_message() );
			}
			if ( ! hash_equals( (string) $item['pair_hash'], AB_MCP_Sync_Pairs::snapshot_hash( $before ) ) ) {
				return self::fail_step( $run_id, $seq, 'planned', sprintf( 'The pair %d changed since the plan; nothing was written. Make a new report and plan again.', (int) $item['pair_id'] ) );
			}
		}
		$journal = array(
			'object' => array(
				'kind'        => $item['kind'],
				'op'          => $item['op'],
				'staging_ref' => (string) $item['staging_ref'],
				'live_ref'    => null === $live_id ? null : (string) $live_id,
				'pair_id'     => isset( $item['pair_id'] ) ? $item['pair_id'] : null,
				'as_draft'    => ! empty( $item['as_draft'] ),
				'target'      => null,
			),
			'backup' => null,
			'parts'  => array(),
		);
		// (2) The step begun, with the state of its pair before.
		$begun = AB_MCP_Sync_Run::update_step(
			$run_id,
			$seq,
			array(
				'state'       => 'begun',
				'parts'       => $journal,
				'pair_before' => $before,
			),
			'planned'
		);
		if ( is_wp_error( $begun ) ) {
			return $begun;
		}
		AB_MCP_Sync_Run::touch( $run_id );
		// (3) The takeover lock of the object.
		$guard = AB_MCP_Sync_Guard::begin( $run_id, $seq, null === $live_id ? array() : array( $live_id => $item['subtype'] ) );
		if ( is_wp_error( $guard ) ) {
			return self::fail_step( $run_id, $seq, 'begun', $guard->get_error_message(), $journal );
		}
		// (4) The target and the rights again, fresh.
		$lines = self::lines( $run );
		if ( is_wp_error( $lines ) ) {
			return self::fail_step( $run_id, $seq, 'begun', $lines->get_error_message(), $journal );
		}
		$line = self::line_of( $item, $lines );
		if ( is_wp_error( $line ) ) {
			return self::fail_step( $run_id, $seq, 'begun', $line->get_error_message(), $journal );
		}
		$prepared = AB_MCP_Sync_Post_Writer::target_of( $line, $item, $ctx );
		if ( is_wp_error( $prepared ) ) {
			return self::fail_step( $run_id, $seq, 'begun', $prepared->get_error_message(), $journal );
		}
		$ok = AB_MCP_Sync_Post_Writer::precheck( $prepared, $live_id, (int) $ctx['now'] );
		if ( is_wp_error( $ok ) ) {
			return self::fail_step( $run_id, $seq, 'begun', $ok->get_error_message(), $journal );
		}
		$current = null === $live_id ? null : AB_MCP_Sync_Post_Writer::current( $live_id );
		if ( is_wp_error( $current ) ) {
			return self::fail_step( $run_id, $seq, 'begun', $current->get_error_message(), $journal );
		}
		if ( null !== $live_id ) {
			if ( null === $current ) {
				return self::fail_step( $run_id, $seq, 'begun', sprintf( 'The post %d of this site is no longer there; nothing was written.', $live_id ), $journal );
			}
			$hash = AB_MCP_Sync_Post_Writer::live_hash( $live_id, $ctx['source'] );
			if ( null === $hash || ! hash_equals( (string) $item['live_hash'], $hash ) ) {
				return self::fail_step( $run_id, $seq, 'begun', sprintf( 'The post %d of this site changed since the plan (or cannot be read whole); nothing was written. Make a new report and plan again.', $live_id ), $journal );
			}
			$ok = self::live_meta_rule( $live_id );
			if ( is_wp_error( $ok ) ) {
				return self::fail_step( $run_id, $seq, 'begun', $ok->get_error_message(), $journal );
			}
		} elseif ( is_array( AB_MCP_Sync_Pairs::find_staging( (string) $item['source_id'], (string) $item['kind'], (string) $item['staging_ref'] ) ) ) {
			return self::fail_step( $run_id, $seq, 'begun', sprintf( 'The %1$s %2$s of the staging site has a pair here since the plan; it is not created anew.', $item['kind'], $item['staging_ref'] ), $journal );
		}
		$journal['object']['target']   = $prepared['target_hash'];
		$journal['object']['identity'] = $prepared['identity'];
		// (5) The backup, checked by its hash; without it no mutation.
		$backup = AB_MCP_Sync_Backup::put(
			$run_id,
			sprintf( 'step-%04d', $seq ),
			null === $current ? array(
				'absent'   => true,
				'identity' => $prepared['identity'],
			) : $current
		);
		if ( is_wp_error( $backup ) ) {
			return self::fail_step( $run_id, $seq, 'begun', $backup->get_error_message(), $journal );
		}
		$journal['backup'] = $backup;
		// (6) The parts, written ahead: the row, each meta key (of the target
		// and of this site), each taxonomy.
		$keys  = array_keys( $prepared['meta'] );
		$taxes = array_keys( $prepared['terms'] );
		if ( is_array( $current ) ) {
			foreach ( $current['meta'] as $row ) {
				$keys[] = $row['key'];
			}
			foreach ( $current['terms'] as $term ) {
				$taxes[] = $term['taxonomy'];
			}
		}
		$keys   = array_values( array_unique( $keys ) );
		$taxes  = array_values( array_unique( $taxes ) );
		$hashes = AB_MCP_Sync_Post_Writer::hashes( $current, $keys, $taxes );
		$parts  = array(
			array(
				'part'   => 'row',
				'way'    => AB_MCP_Sync_Post_Writer::WAY_ROW,
				'before' => $hashes['row'],
				'target' => AB_MCP_Sync_Post_Writer::row_hash( $prepared['columns'] ),
				'state'  => 'planned',
				'after'  => null,
			),
		);
		foreach ( $keys as $key ) {
			$values  = isset( $prepared['meta'][ $key ] ) ? $prepared['meta'][ $key ] : array();
			$stored  = array();
			foreach ( $values as $value ) {
				$stored[] = is_array( $value ) ? serialize( $value ) : (string) $value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- as maybe_serialize() stores an array.
			}
			$parts[] = array(
				'part'   => 'meta:' . $key,
				'way'    => AB_MCP_Sync_Post_Writer::WAY_META,
				'before' => $hashes[ 'meta:' . $key ],
				'target' => AB_MCP_Sync_Post_Writer::meta_hash( $stored ),
				'state'  => 'planned',
				'after'  => null,
			);
		}
		foreach ( $taxes as $taxonomy ) {
			$keys    = isset( $prepared['terms'][ $taxonomy ] ) ? AB_MCP_Sync_Post_Writer::target_term_keys( $taxonomy, $prepared['terms'][ $taxonomy ] ) : array();
			$parts[] = array(
				'part'   => 'terms:' . $taxonomy,
				'way'    => AB_MCP_Sync_Post_Writer::WAY_TERMS,
				'before' => $hashes[ 'terms:' . $taxonomy ],
				'target' => AB_MCP_Sync_Post_Writer::terms_hash( $keys ),
				'state'  => 'planned',
				'after'  => null,
			);
		}
		$journal['parts'] = $parts;
		$ahead            = AB_MCP_Sync_Run::update_step( $run_id, $seq, array( 'parts' => $journal ), 'begun' );
		if ( is_wp_error( $ahead ) ) {
			return $ahead;
		}
		// The writes, part by part, each read back under the lock.
		$outcome = self::write_parts( $run_id, $seq, $journal, $prepared, $current, $live_id );
		$journal = $outcome['journal'];
		$live_id = $outcome['live_id'];
		AB_MCP_Change_Lock::touch();
		if ( 'done' !== $outcome['state'] ) {
			// Deviating or failed: the pair of a new object made residual
			// (the object stays), the pair of one here left as it was or
			// made unclear.
			$pair_id = isset( $item['pair_id'] ) ? (int) $item['pair_id'] : null;
			$rest    = null;
			if ( 'create' === $item['op'] && null !== $live_id ) {
				$made = self::pair_for_new( $run, $item, $live_id, $prepared, 'residual' );
				if ( is_array( $made ) ) {
					$pair_id = (int) $made['id'];
				}
				$rest = self::rest( $item, $live_id );
			} elseif ( null !== $pair_id && 'unclear' === $outcome['state'] ) {
				AB_MCP_Sync_Pairs::set_state( $pair_id, 'unclear', AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( $pair_id ) ) );
			}
			$result = array(
				'message'  => $outcome['message'],
				'live_ref' => null === $live_id ? null : (string) $live_id,
			);
			if ( null !== $rest ) {
				$result['rest'] = $rest;
			}
			AB_MCP_Sync_Run::update_step( $run_id, $seq, array( 'state' => $outcome['state'], 'parts' => $journal, 'result' => $result, 'pair_id' => $pair_id ), 'begun' );
			AB_MCP_Sync_Run::touch( $run_id );
			return array( 'next' => $outcome['message'] . ' The run goes on with the next step (action=next); the steps that depend on this object are skipped.' );
		}
		// (7) The object as a whole against the target.
		$hash = AB_MCP_Sync_Post_Writer::live_hash( $live_id, $ctx['source'] );
		if ( null === $hash || ! hash_equals( $prepared['target_hash'], $hash ) ) {
			$pair_id = isset( $item['pair_id'] ) ? (int) $item['pair_id'] : null;
			$rest    = null;
			if ( 'create' === $item['op'] ) {
				$made = self::pair_for_new( $run, $item, $live_id, $prepared, 'residual' );
				if ( is_array( $made ) ) {
					$pair_id = (int) $made['id'];
				}
				$rest = self::rest( $item, $live_id );
			} elseif ( null !== $pair_id ) {
				AB_MCP_Sync_Pairs::set_state( $pair_id, 'unclear', AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( $pair_id ) ) );
			}
			$message = sprintf( 'Every part read back as written, but the object as a whole does not hash to its target (a filter of this site changed what the rows hold); the pair is %s. Check the object in wp-admin.', null === $rest ? 'unclear' : 'a rest' );
			$result  = array(
				'message'  => $message,
				'live_ref' => (string) $live_id,
			);
			if ( null !== $rest ) {
				$result['rest'] = $rest;
			}
			AB_MCP_Sync_Run::update_step( $run_id, $seq, array( 'state' => 'unclear', 'parts' => $journal, 'result' => $result, 'pair_id' => $pair_id ), 'begun' );
			return array( 'next' => $message );
		}
		// (8) Done: the pair with its new bases (or made), the step done.
		$pair_id = isset( $item['pair_id'] ) ? (int) $item['pair_id'] : null;
		if ( 'create' === $item['op'] ) {
			$made = self::pair_for_new( $run, $item, $live_id, $prepared, 'active' );
			if ( is_wp_error( $made ) ) {
				$result = array(
					'message'  => 'The object was written as its target, but its pair could not be made: ' . $made->get_error_message(),
					'live_ref' => (string) $live_id,
					'rest'     => self::rest( $item, $live_id ),
				);
				AB_MCP_Sync_Run::update_step( $run_id, $seq, array( 'state' => 'unclear', 'parts' => $journal, 'result' => $result ), 'begun' );
				return array( 'next' => $result['message'] );
			}
			$pair_id = (int) $made['id'];
		} else {
			$rebased = AB_MCP_Sync_Pairs::rebase(
				$pair_id,
				array(
					'canon'        => AB_MCP_Sync_Canon::VERSION,
					'base_live'    => $hash,
					'base_staging' => (string) $item['staging_hash'],
					'base_origin'  => 'push',
					'override'     => $prepared['as_draft'] ? array( 'post_status' => 'draft' ) : null,
					'last_run'     => $run_id,
				),
				AB_MCP_Sync_Pairs::snapshot_hash( $before )
			);
			if ( is_wp_error( $rebased ) ) {
				$result = array(
					'message'  => 'The object was written as its target, but its pair did not take the new bases: ' . $rebased->get_error_message(),
					'live_ref' => (string) $live_id,
				);
				AB_MCP_Sync_Run::update_step( $run_id, $seq, array( 'state' => 'unclear', 'parts' => $journal, 'result' => $result ), 'begun' );
				return array( 'next' => $result['message'] );
			}
		}
		$result = array(
			'message'  => sprintf( '%1$s %2$s of the staging site written as %3$s %4$d of this site%5$s; read back as its target.', ucfirst( $item['kind'] ), $item['staging_ref'], $item['kind'], $live_id, $prepared['as_draft'] ? ' with the status draft (the confirmed choice)' : '' ),
			'live_ref' => (string) $live_id,
			'created'  => 'create' === $item['op'],
			'pair_id'  => $pair_id,
			'status'   => $prepared['status'],
			'title'    => (string) $prepared['postarr']['post_title'],
		);
		AB_MCP_Sync_Run::update_step( $run_id, $seq, array( 'state' => 'done', 'parts' => $journal, 'result' => $result, 'pair_id' => $pair_id ), 'begun' );
		AB_MCP_Sync_Run::touch( $run_id );
		return array( 'next' => $result['message'] . ' Call action=next for the next step.' );
	}

	/**
	 * The parts of a boundary written one by one, each read back: the row,
	 * the meta keys, the taxonomies. A part that deviates is put back where
	 * its way is refusable and its bytes are still the written ones.
	 *
	 * The ownership rule (Codex round 3 of branch 8, after B8 and N2): a
	 * part is the step's own only where the step wrote it itself, through
	 * its own call here, or through a field of the row call
	 * (AB_MCP_Sync_Post_Writer::with_row(): the template through
	 * page_template, the default category of a new post), and reads back
	 * what that call was to write. Only the step's own parts stand in the
	 * journal as read or written and go back when the step turns. A part
	 * outside that set that changed since the backup was changed by another
	 * hand: it is never written down as written or read and never put back;
	 * the journal says so, and a turn leaves it and calls the object unclear.
	 *
	 * @param int        $run_id   Run.
	 * @param int        $seq      Step.
	 * @param array      $journal  Journal (parts planned).
	 * @param array      $prepared target_of().
	 * @param array|null $current  current() before.
	 * @param int|null   $live_id  Post here, null for a new one.
	 * @return array{state:string,message:string,journal:array,live_id:int|null}
	 */
	private static function write_parts( $run_id, $seq, array $journal, array $prepared, $current, $live_id ) {
		$save = static function ( array $journal ) use ( $run_id, $seq ) {
			AB_MCP_Sync_Run::update_step( $run_id, $seq, array( 'parts' => $journal ), 'begun' );
		};
		foreach ( $journal['parts'] as $i => $part ) {
			$name        = (string) $part['part'];
			$before_own  = '';
			$through_row = false;
			if ( 'row' === $name ) {
				// What the row call writes besides the row (the ownership rule).
				$own = AB_MCP_Sync_Post_Writer::with_row( $prepared, $live_id, $current );
				$id  = AB_MCP_Sync_Post_Writer::write_row( $prepared, $live_id );
				if ( is_wp_error( $id ) ) {
					$journal['parts'][ $i ]['state'] = 'failed';
					$journal['parts'][ $i ]['note']  = $id->get_error_message();
					$save( $journal );
					return self::outcome( 'failed', 'The row was not written: ' . $id->get_error_message(), $journal, $live_id );
				}
				$live_id                       = (int) $id;
				$journal['object']['live_ref'] = (string) $live_id;
				$now                           = AB_MCP_Sync_Post_Writer::current( $live_id );
				$after                         = is_array( $now ) ? AB_MCP_Sync_Post_Writer::row_hash( $now['row'] ) : null;
				// Every other part read back right after the row. One that
				// changed since the backup is the step's own only where the row
				// call was to write it (with_row()) and it reads back what that
				// call was to write: it stands in the journal as read (its
				// target) or written, so that a turn takes it back with the row
				// and an interruption shows it (N2). Any other change is another
				// hand's (a hook of this site reacting to the row, a writer on a
				// way the lock does not refuse): never written down as written
				// or read, never put back; the journal says so, and a turn of
				// the step leaves it and calls the object unclear (Codex round 3).
				if ( is_array( $now ) ) {
					foreach ( $journal['parts'] as $k => $other ) {
						if ( $k === $i || 'planned' !== $other['state'] ) {
							continue;
						}
						$hash = AB_MCP_Sync_Post_Writer::part_hash( $now, (string) $other['part'] );
						$was  = null !== $other['before'] ? (string) $other['before'] : AB_MCP_Sync_Post_Writer::part_hash( null, (string) $other['part'] );
						if ( null === $hash || hash_equals( (string) $was, $hash ) ) {
							continue;
						}
						if ( isset( $own[ $other['part'] ] ) && hash_equals( (string) $own[ $other['part'] ], $hash ) ) {
							$journal['parts'][ $k ]['state'] = hash_equals( (string) $other['target'], $hash ) ? 'read' : 'written';
							$journal['parts'][ $k ]['after'] = $hash;
							$journal['parts'][ $k ]['note']  = 'Written with the row by the core (wp_insert_post()).';
							continue;
						}
						$journal['parts'][ $k ]['note'] = isset( $own[ $other['part'] ] )
							? 'Written with the row by the core, but read back other than what the row was to write: not provably the step\'s own, not put back.'
							: 'Changed since the backup of the step by another hand (a hook of this site reacting to the row, or a writer on a way the lock does not refuse), not by the step: not the step\'s own, not put back.';
					}
				}
			} elseif ( 0 === strpos( $name, 'meta:' ) ) {
				$key    = substr( $name, 5 );
				$values = isset( $prepared['meta'][ $key ] ) ? $prepared['meta'][ $key ] : array();
				$have   = is_array( $current ) ? AB_MCP_Sync_Post_Writer::meta_values( $current, $key ) : array();
				// A core key with an API of its own (_wp_page_template) was
				// written with the row, through page_template (decision 17, B7),
				// and read back then (N2); here it is read back once more. Its
				// removal goes through the meta API.
				$core        = array() !== $values && in_array( $key, AB_MCP_Sync_Post_Writer::CORE_META, true );
				$through_row = $core;
				if ( ! $core ) {
					$before_own = isset( $journal['parts'][ $i ]['note'] ) ? (string) $journal['parts'][ $i ]['note'] : '';
					unset( $journal['parts'][ $i ]['note'] );
				}
				$done = $core ? true : AB_MCP_Sync_Post_Writer::write_meta( $live_id, $key, $values, $have );
				if ( is_wp_error( $done ) ) {
					$journal['parts'][ $i ]['state'] = 'failed';
					$journal['parts'][ $i ]['note']  = $done->get_error_message();
					$save( $journal );
					return self::outcome( 'unclear', 'A part was not written, the parts before stay: ' . $done->get_error_message(), $journal, $live_id );
				}
				$now   = AB_MCP_Sync_Post_Writer::current( $live_id );
				$after = is_array( $now ) ? AB_MCP_Sync_Post_Writer::meta_hash( AB_MCP_Sync_Post_Writer::meta_values( $now, $key ) ) : null;
			} else {
				$taxonomy   = substr( $name, 6 );
				$terms      = isset( $prepared['terms'][ $taxonomy ] ) ? $prepared['terms'][ $taxonomy ] : array();
				$before_own = isset( $journal['parts'][ $i ]['note'] ) ? (string) $journal['parts'][ $i ]['note'] : '';
				unset( $journal['parts'][ $i ]['note'] );
				$done = AB_MCP_Sync_Post_Writer::write_terms( $live_id, $taxonomy, $terms );
				if ( is_wp_error( $done ) ) {
					$journal['parts'][ $i ]['state'] = 'failed';
					$journal['parts'][ $i ]['note']  = $done->get_error_message();
					$save( $journal );
					return self::outcome( 'unclear', 'A part was not written, the parts before stay: ' . $done->get_error_message(), $journal, $live_id );
				}
				$now   = AB_MCP_Sync_Post_Writer::current( $live_id );
				$after = is_array( $now ) ? AB_MCP_Sync_Post_Writer::terms_hash( AB_MCP_Sync_Post_Writer::term_keys( $now, $taxonomy ) ) : null;
			}
			$journal['parts'][ $i ]['state'] = 'written';
			$journal['parts'][ $i ]['after'] = $after;
			if ( null !== $after && hash_equals( (string) $part['target'], $after ) ) {
				$journal['parts'][ $i ]['state'] = 'read';
				if ( '' !== $before_own ) {
					// Another hand changed it with the row, before the step wrote
					// it as its target (the window of a way the lock does not
					// refuse, GRENZEN-LAUF.md): said in the journal, not hidden.
					$journal['parts'][ $i ]['note'] = $before_own . ' Written as its target by the step after that.';
				}
				$save( $journal );
				AB_MCP_Change_Lock::touch();
				continue;
			}
			// Written, deviating (decision 17).
			$journal['parts'][ $i ]['note'] = 'Written, deviating: read back other than its target.';
			$save( $journal );
			if ( AB_MCP_Sync_Post_Writer::WAY_TERMS === $part['way'] || $through_row || null === $after || null === $current ) {
				// Not provably the step's own (a way the lock does not refuse; a
				// part the core wrote with the row that reads back other than
				// what the row was to write), or a new object: it stays, unclear.
				if ( $through_row ) {
					$journal['parts'][ $i ]['state'] = 'left';
					$journal['parts'][ $i ]['note']  = 'Written with the row by the core, but read back other than what the row was to write: not provably the step\'s own, left as it is.';
					$save( $journal );
					$why = 'the core wrote it with the row and it reads back other than what the row was to write, so it is not provably the step\'s own';
				} else {
					$why = AB_MCP_Sync_Post_Writer::WAY_TERMS === $part['way'] ? 'it is on a way the lock does not refuse' : 'the object is new, or the part could not be read back';
				}
				return self::outcome( 'unclear', sprintf( 'The part %1$s was written but reads back other than its target; %2$s, so it stays and the object is unclear.', $name, $why ), $journal, $live_id );
			}
			// The deviating part goes back, and with it every part the step
			// wrote (its own: read as its target or written, through its own
			// call or with the row; B8, N2), each read back under this lock,
			// the last written first. A part the step did not write (planned
			// still) that changed since the backup was changed by another
			// hand: it is left, the journal says so, and the object is unclear
			// (the ownership rule, Codex round 3). The step is restored only
			// where every part it wrote is back and nothing else changed.
			$seen     = AB_MCP_Sync_Post_Writer::current( $live_id );
			$all_back = true;
			for ( $j = count( $journal['parts'] ) - 1; $j >= 0; $j-- ) {
				if ( $j !== $i && ! in_array( $journal['parts'][ $j ]['state'], array( 'read', 'written' ), true ) ) {
					$other = $journal['parts'][ $j ];
					$hash  = is_array( $seen ) ? AB_MCP_Sync_Post_Writer::part_hash( $seen, (string) $other['part'] ) : null;
					$was   = null !== $other['before'] ? (string) $other['before'] : AB_MCP_Sync_Post_Writer::part_hash( null, (string) $other['part'] );
					if ( null === $hash || ! hash_equals( $was, $hash ) ) {
						$journal['parts'][ $j ]['state'] = 'left';
						$journal['parts'][ $j ]['note']  = null === $hash ? 'Could not be read back, so whether it changed since the backup is unknown: left as it is.' : 'Changed since the backup of the step by another hand, not by the step (it had not written this part): not the step\'s own, left as it is.';
						$all_back                        = false;
						$save( $journal );
					}
					continue;
				}
				$put                             = self::restore_part( $run_id, $journal, $j, $live_id, $current );
				$journal['parts'][ $j ]['state'] = $put['state'];
				if ( isset( $put['note'] ) ) {
					$journal['parts'][ $j ]['note'] = $put['note'];
				}
				if ( ! in_array( $put['state'], array( 'restored', 'unchanged' ), true ) ) {
					$all_back = false;
				}
				$save( $journal );
				AB_MCP_Change_Lock::touch();
			}
			return self::outcome( $all_back ? 'restored' : 'unclear', sprintf( 'The part %1$s was written but read back other than its target (a filter of this site changed it); %2$s', $name, $all_back ? 'it and every part the step wrote before it or with the row were put back byte for byte: nothing of the object changed.' : 'a part could not be put back, or a part changed since the backup by another hand and is left as it is (see the parts of the journal), so the object is unclear.' ), $journal, $live_id );
		}
		return self::outcome( 'done', '', $journal, $live_id );
	}

	/**
	 * Put one part back from the backup of the step (within the boundary,
	 * or on the way back): the row through wp_update_post(), meta raw, the
	 * terms through wp_set_object_terms().
	 *
	 * @param int   $run_id  Run.
	 * @param array $journal Journal.
	 * @param int   $i       Part.
	 * @param int   $live_id Post.
	 * @param array $backup  The backed-up state (current() before the step).
	 * @return array{state:string,note?:string}
	 */
	private static function restore_part( $run_id, array $journal, $i, $live_id, array $backup ) {
		$part    = $journal['parts'][ $i ];
		$name    = (string) $part['part'];
		// What the step wrote: the hash read back, or, for a part never read
		// back (the request ended), its target.
		$written = null !== $part['after'] ? (string) $part['after'] : (string) $part['target'];
		// A part written deviating (read back other than its target) goes
		// back only where its way is refusable (decisions 17 and 23): on a
		// way the lock does not refuse, what was read back is not provably
		// the step's own (A2).
		if ( null !== $part['after'] && ! hash_equals( (string) $part['target'], (string) $part['after'] ) && AB_MCP_Sync_Post_Writer::WAY_TERMS === $part['way'] ) {
			return array(
				'state' => 'left',
				'note'  => sprintf( 'The part %s was written deviating on a way the lock does not refuse, so what it holds is not provably the run\'s own: left as it is.', $name ),
			);
		}
		if ( 'row' === $name ) {
			$put = AB_MCP_Sync_Post_Writer::restore_row( $live_id, $backup['row'], (string) $part['before'], $written, AB_MCP_Sync_Run::now() );
		} elseif ( 0 === strpos( $name, 'meta:' ) ) {
			$key = substr( $name, 5 );
			$put = AB_MCP_Sync_Post_Writer::restore_meta( $live_id, $key, AB_MCP_Sync_Post_Writer::meta_values( $backup, $key ), $written );
		} else {
			$taxonomy = substr( $name, 6 );
			$put      = AB_MCP_Sync_Post_Writer::restore_terms( $live_id, $taxonomy, AB_MCP_Sync_Post_Writer::term_keys( $backup, $taxonomy ), $written );
		}
		if ( is_wp_error( $put ) ) {
			return array(
				'state' => 'unclear',
				'note'  => $put->get_error_message(),
			);
		}
		return $put;
	}

	/**
	 * Phase 7: the caches of what the run wrote (decision 33).
	 *
	 * @param array $run  Run.
	 * @param array $step Step.
	 * @param array $ctx  context().
	 * @return array|WP_Error
	 */
	private static function run_post( array $run, array $step, array $ctx ) {
		unset( $ctx );
		$steps  = AB_MCP_Sync_Run::steps( (int) $run['run_id'] );
		$done   = array();
		foreach ( is_array( $steps ) ? $steps : array() as $one ) {
			if ( 'write' === $one['op'] && 'done' === $one['state'] && is_array( $one['result'] ) && isset( $one['result']['live_ref'] ) ) {
				$done[ (string) $one['result']['live_ref'] ] = AB_MCP_Sync_Post_Writer::post_write( (int) $one['result']['live_ref'] );
			}
		}
		foreach ( AB_MCP_Sync_Post_Writer::TAXONOMIES as $taxonomy ) {
			clean_term_cache( array(), $taxonomy, true );
		}
		$result = array(
			'message' => sprintf( 'The caches of %d written object(s) were emptied (post, meta and term caches, the builder caches, the page cache where a caching plugin is known).', count( $done ) ),
			'objects' => $done,
		);
		AB_MCP_Sync_Run::update_step( (int) $run['run_id'], (int) $step['seq'], array( 'state' => 'done', 'result' => $result ), 'planned' );
		AB_MCP_Sync_Run::touch( (int) $run['run_id'] );
		return array( 'next' => $result['message'] . ' Call action=next for the check of the run, which closes it.' );
	}

	/**
	 * Phase 8: the check of the run (decision 33), which closes it, over as
	 * many calls as its budget takes (a cursor and a stage in the result of
	 * the step, as the check before the first write; B4): every written pair
	 * against its target, one object after the other; a sample of a
	 * published object answering 200 with the title of the written object
	 * in its body (an old page from a cache would not); the PHP probe, which
	 * has to pass whole (partial and unreachable are findings); the error
	 * log without new lines. Each call does at least one of these, so the
	 * check always goes on; closed_done only once every part ran and passed,
	 * else closed_findings.
	 *
	 * @param array $run     Run.
	 * @param array $step    Step.
	 * @param array $ctx     context().
	 * @param float $started When the call began.
	 * @return array|WP_Error
	 */
	private static function run_verify( array $run, array $step, array $ctx, $started ) {
		$steps = AB_MCP_Sync_Run::steps( (int) $run['run_id'] );
		if ( is_wp_error( $steps ) ) {
			return $steps;
		}
		$result = is_array( $step['result'] ) && isset( $step['result']['stage'] ) ? $step['result'] : array(
			'stage'    => 'hashes',
			'cursor'   => 0,
			'findings' => array(),
			'written'  => 0,
			'writes'   => 0,
			'sample'   => null,
			'probe'    => null,
		);
		$writes = array();
		foreach ( $steps as $one ) {
			if ( 'write' === $one['op'] ) {
				$writes[] = $one;
			}
		}
		$worked = false;
		$left   = static function () use ( $started ) {
			return self::BUDGET - self::RESERVE - ( self::now() - $started );
		};
		$save   = static function ( array $result, $message ) use ( $run, $step ) {
			AB_MCP_Sync_Run::update_step( (int) $run['run_id'], (int) $step['seq'], array( 'result' => $result ), 'planned' );
			AB_MCP_Sync_Run::touch( (int) $run['run_id'] );
			return array( 'next' => $message );
		};
		// The hashes of the written objects, one after the other.
		if ( 'hashes' === $result['stage'] ) {
			$count = count( $writes );
			for ( $i = (int) $result['cursor']; $i < $count; $i++ ) {
				if ( $worked && $left() <= 0 ) {
					$result['cursor'] = $i;
					return $save( $result, sprintf( 'The check of the run went over %1$d of %2$d written objects within the budget of this call; call action=next again to go on with it.', $i, $count ) );
				}
				$worked = true;
				$one    = $writes[ $i ];
				++$result['writes'];
				if ( 'done' !== $one['state'] ) {
					$result['findings'][] = array(
						'seq'     => (int) $one['seq'],
						'message' => sprintf( 'Step %1$d is %2$s: %3$s', (int) $one['seq'], $one['state'], is_array( $one['result'] ) && isset( $one['result']['message'] ) ? $one['result']['message'] : 'not written.' ),
					);
					continue;
				}
				++$result['written'];
				$live_id = isset( $one['result']['live_ref'] ) ? (int) $one['result']['live_ref'] : 0;
				$target  = isset( $one['parts']['object']['target'] ) ? (string) $one['parts']['object']['target'] : '';
				$hash    = $live_id > 0 ? AB_MCP_Sync_Post_Writer::live_hash( $live_id, $ctx['source'] ) : null;
				if ( null === $hash || ! hash_equals( $target, $hash ) ) {
					$result['findings'][] = array(
						'seq'     => (int) $one['seq'],
						'message' => sprintf( 'The %1$s %2$d no longer hashes to the target of step %3$d (changed since it was written, or not readable whole).', $one['parts']['object']['kind'] ?? 'post', $live_id, (int) $one['seq'] ),
					);
				}
				// The first published object is the sample.
				if ( null === $result['sample'] && isset( $one['result']['status'] ) && 'publish' === $one['result']['status'] && $live_id > 0 ) {
					$result['sample'] = array(
						'seq'      => (int) $one['seq'],
						'live_ref' => $live_id,
						'title'    => isset( $one['result']['title'] ) ? (string) $one['result']['title'] : '',
						'status'   => null,
						'asked'    => false,
					);
				}
			}
			$result['cursor'] = $count;
			$result['stage']  = 'sample';
		}
		// The sample: a published object answers 200 and shows its title.
		if ( 'sample' === $result['stage'] ) {
			if ( $worked && $left() <= 0 ) {
				return $save( $result, 'The hashes of the written objects were checked within the budget of this call; the sample, the PHP probe and the log follow: call action=next again.' );
			}
			$worked = true;
			if ( is_array( $result['sample'] ) ) {
				$live_id = (int) $result['sample']['live_ref'];
				$answer  = self::probe( get_permalink( $live_id ) );
				$title   = (string) $result['sample']['title'];
				if ( '' === $title ) {
					$row   = AB_MCP_Sync_Rows::post( $live_id );
					$title = is_array( $row ) ? (string) $row['post_title'] : '';
				}
				$finding                      = self::sample_finding( $answer['status'], $answer['body'], $title );
				$result['sample']['status']   = $answer['status'];
				$result['sample']['asked']    = true;
				$result['sample']['finding']  = $finding;
				if ( null !== $finding ) {
					$result['findings'][] = array(
						'seq'     => (int) $result['sample']['seq'],
						'message' => sprintf( 'The published %1$s %2$d %3$s', $result['sample']['kind'] ?? 'post', $live_id, $finding ),
					);
				}
			}
			$result['stage'] = 'probe';
		}
		// The PHP probe of the site: it has to pass whole.
		if ( 'probe' === $result['stage'] ) {
			if ( $worked && $left() <= self::RESERVE ) {
				return $save( $result, 'The hashes and the sample were checked within the budget of this call; the PHP probe and the log follow: call action=next again.' );
			}
			$worked = true;
			if ( class_exists( 'AB_MCP_Php_Probe' ) ) {
				$probe           = AB_MCP_Php_Probe::run( array(), true, array( 'until' => microtime( true ) + max( 1.0, (float) $left() ) ) );
				$result['probe'] = is_array( $probe ) && isset( $probe['result'] ) ? (string) $probe['result'] : 'unreachable';
			} else {
				$result['probe'] = 'unavailable';
			}
			if ( 'passed' !== $result['probe'] ) {
				$result['findings'][] = array(
					'seq'     => 0,
					'message' => 'fatal' === $result['probe'] ? 'The PHP probe of the site found a fatal error after the run.' : sprintf( 'The PHP probe of the site did not pass whole after the run (%s): open the site and wp-admin in the browser; wp_site_health shows the probe.', $result['probe'] ),
				);
			}
			$result['stage'] = 'log';
		}
		// The error log without new lines, where readable; then the close.
		$check = null;
		foreach ( $steps as $one ) {
			if ( 'check' === $one['op'] && is_array( $one['result'] ) && isset( $one['result']['error_log_lines'] ) ) {
				$check = $one['result']['error_log_lines'];
			}
		}
		$lines_now = self::error_log_lines();
		if ( null !== $check && null !== $lines_now && $lines_now > $check ) {
			$result['findings'][] = array(
				'seq'     => 0,
				'message' => sprintf( 'The error log of the site has %d new line(s) since the check before the run (wp_get_error_log shows them).', $lines_now - $check ),
			);
		}
		unset( $result['cursor'], $result['stage'] );
		$written           = (int) $result['written'];
		$writes_n          = (int) $result['writes'];
		$qualified         = $writes_n > 0 && $written === $writes_n;
		$state             = array() === $result['findings'] ? 'closed_done' : 'closed_findings';
		$result['message'] = array() === $result['findings'] ? sprintf( 'Checked: %d written object(s) hash to their targets; the sample shows its object, the probe passed and the log has no new line. The run is closed as done.', $written ) : sprintf( 'Checked with %d finding(s); the run is closed with findings.', count( $result['findings'] ) );
		AB_MCP_Sync_Run::update_step( (int) $run['run_id'], (int) $step['seq'], array( 'state' => 'done', 'result' => $result ), 'planned' );
		$moved = AB_MCP_Sync_Run::set_state( (int) $run['run_id'], 'open', $state, $qualified );
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}
		AB_MCP_Store_Budget::release( (int) $run['run_id'] );
		// The retention of the backups (decision 20): the fourth qualified
		// close pushes the first out.
		AB_MCP_Protected_Store::cleanup();
		return array( 'next' => $result['message'] . ' The way back for its pairs is wp_site_revert (action=plan with this run_id).' );
	}

	/**
	 * What the sample of a published object found, or null where it passed
	 * (decision 33: HTTP 200 and the expected string, the title of the
	 * written object as the page shows it; an old page a cache serves with
	 * another title does not pass).
	 *
	 * @param int|null $status The status answered; null for no answer.
	 * @param string   $body   The body answered.
	 * @param string   $title  The title of the written object.
	 * @return string|null
	 */
	public static function sample_finding( $status, $body, $title ) {
		if ( 200 !== (int) $status ) {
			return sprintf( 'did not answer 200 (%s).', null === $status ? 'no answer' : (string) (int) $status );
		}
		$title   = trim( (string) $title );
		$needles = array( $title, esc_html( $title ) );
		if ( function_exists( 'wptexturize' ) ) {
			$needles[] = wptexturize( esc_html( $title ) );
		}
		foreach ( array_unique( $needles ) as $needle ) {
			if ( '' !== $needle && false !== strpos( (string) $body, $needle ) ) {
				return null;
			}
		}
		return 'answered 200 but did not show the title of the written object: a page cache may serve an old version (empty it and open the page), or the theme does not show titles.';
	}

	/**
	 * A step of the way back (decision 23): one write step of the run (or of
	 * the closed run a run of the type revert names) taken back part by part.
	 *
	 * @param array $run  Run.
	 * @param array $step Step.
	 * @param array $ctx  context().
	 * @return array|WP_Error
	 */
	private static function run_revert( array $run, array $step, array $ctx ) {
		$lock = AB_MCP_Change_Lock::take();
		if ( is_wp_error( $lock ) ) {
			return array(
				'next' => $lock->get_error_message(),
				'busy' => true,
			);
		}
		try {
			return self::revert_boundary( $run, $step, $ctx );
		} finally {
			AB_MCP_Sync_Guard::end( (int) $run['run_id'], (int) $step['seq'] );
			AB_MCP_Change_Lock::release();
		}
	}

	/**
	 * @param array $run  Run.
	 * @param array $step Step.
	 * @param array $ctx  context().
	 * @return array|WP_Error
	 */
	private static function revert_boundary( array $run, array $step, array $ctx ) {
		unset( $ctx );
		$run_id  = (int) $run['run_id'];
		$seq     = (int) $step['seq'];
		$what    = is_array( $step['parts'] ) ? $step['parts'] : array();
		$forward = AB_MCP_Sync_Run::step( isset( $what['forward_run'] ) ? (int) $what['forward_run'] : $run_id, isset( $what['forward_seq'] ) ? (int) $what['forward_seq'] : 0 );
		if ( ! is_array( $forward ) || ! is_array( $forward['parts'] ) || ! isset( $forward['parts']['object'] ) ) {
			return self::fail_step( $run_id, $seq, 'planned', 'The step of the way back names no written step with a journal; nothing is put back.' );
		}
		$journal = $forward['parts'];
		$object  = $journal['object'];
		$live_id = isset( $object['live_ref'] ) ? (int) $object['live_ref'] : 0;
		$begun   = AB_MCP_Sync_Run::update_step( $run_id, $seq, array( 'state' => 'begun' ), 'planned' );
		if ( is_wp_error( $begun ) ) {
			return $begun;
		}
		AB_MCP_Sync_Run::touch( $run_id );
		$pair_id = isset( $object['pair_id'] ) && null !== $object['pair_id'] ? (int) $object['pair_id'] : ( null !== $forward['pair_id'] ? (int) $forward['pair_id'] : null );
		// A step without a backup wrote nothing (it failed before the backup):
		// there is nothing to take back; the plan leaves such a step out (B9).
		if ( ! is_array( $journal['backup'] ) ) {
			return self::fail_step( $run_id, $seq, 'begun', sprintf( 'Step %d wrote nothing (its journal holds no backup); there is nothing to take back for it.', (int) $forward['seq'] ) );
		}
		// A new object stays as a rest (decision 23, finding R5-4): by the
		// operation of the step, never by the shape of its backup.
		if ( 'create' === $object['op'] ) {
			if ( $live_id < 1 ) {
				return self::fail_step( $run_id, $seq, 'begun', sprintf( 'Step %d made no object (its row was not written); there is nothing to take back for it.', (int) $forward['seq'] ) );
			}
			$rest = self::rest( array( 'kind' => $object['kind'], 'staging_ref' => $object['staging_ref'] ), $live_id );
			if ( null !== $pair_id ) {
				$pair = AB_MCP_Sync_Pairs::get( $pair_id );
				if ( is_array( $pair ) && 'residual' !== $pair['state'] ) {
					AB_MCP_Sync_Pairs::set_state( $pair_id, 'residual', AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( $pair_id ) ) );
				}
			}
			$result = array(
				'message'  => sprintf( 'The %1$s %2$d was made by the run and is not removed by the way back (nothing is deleted in this version); it stays as a rest, its pair residual. Remove it by hand in wp-admin where it should go.', $object['kind'], $live_id ),
				'live_ref' => (string) $live_id,
				'rest'     => $rest,
				'parts'    => array(),
			);
			AB_MCP_Sync_Run::update_step( $run_id, $seq, array( 'state' => 'done', 'result' => $result, 'pair_id' => $pair_id ), 'begun' );
			return array( 'next' => $result['message'] );
		}
		if ( $live_id < 1 ) {
			return self::fail_step( $run_id, $seq, 'begun', 'The journal names no object on this site; nothing is put back.' );
		}
		$guard = AB_MCP_Sync_Guard::begin( $run_id, $seq, array( $live_id => isset( $object['identity']['post_type'] ) ? (string) $object['identity']['post_type'] : 'post' ) );
		if ( is_wp_error( $guard ) ) {
			return self::fail_step( $run_id, $seq, 'begun', $guard->get_error_message() );
		}
		$backup = AB_MCP_Sync_Backup::get( isset( $what['forward_run'] ) ? (int) $what['forward_run'] : $run_id, $journal['backup'] );
		if ( is_wp_error( $backup ) ) {
			return self::fail_step( $run_id, $seq, 'begun', $backup->get_error_message() );
		}
		$outcomes = array();
		$all_back = true;
		$parts    = array_reverse( $journal['parts'], true );
		foreach ( $parts as $i => $part ) {
			// A part the step never reached (its request ended before: the
			// state planned without a hash read back) is asked all the same:
			// where it hashes to the target now, the step wrote it before the
			// read back, and it goes back; where it is the hash before,
			// nothing happened (decision 18: the journal written ahead).
			if ( ! isset( $part['target'] ) || null === $part['target'] || 'failed' === $part['state'] ) {
				$outcomes[] = array(
					'part'  => $part['part'],
					'state' => 'unchanged',
				);
				continue;
			}
			if ( 'restored' === $part['state'] ) {
				$outcomes[] = array(
					'part'  => $part['part'],
					'state' => 'unchanged',
					'note'  => 'Put back within the boundary already.',
				);
				continue;
			}
			// A part the step left when it turned (changed by another hand, or
			// not provably the step's own; the ownership rule): never the run's
			// own, never put back; unchanged only where it is the backup now.
			if ( 'left' === $part['state'] ) {
				$seen       = AB_MCP_Sync_Post_Writer::current( $live_id );
				$same       = is_array( $seen ) && hash_equals( (string) AB_MCP_Sync_Post_Writer::part_hash( $backup, (string) $part['part'] ), (string) AB_MCP_Sync_Post_Writer::part_hash( $seen, (string) $part['part'] ) );
				$outcomes[] = array(
					'part'  => $part['part'],
					'state' => $same ? 'unchanged' : 'left',
					'note'  => $same ? 'As before the run.' : 'Not the run\'s own (left when the step turned: ' . ( isset( $part['note'] ) ? (string) $part['note'] : 'changed by another hand' ) . '), so it is left as it is.',
				);
				if ( ! $same ) {
					$all_back = false;
				}
				continue;
			}
			$put        = self::restore_part( $run_id, $journal, $i, $live_id, $backup );
			$outcomes[] = array( 'part' => $part['part'] ) + $put;
			if ( ! in_array( $put['state'], array( 'restored', 'unchanged' ), true ) ) {
				$all_back = false;
			}
			AB_MCP_Change_Lock::touch();
		}
		AB_MCP_Sync_Post_Writer::post_write( $live_id );
		// The pair: back to its snapshot where everything went back; unclear otherwise.
		$pair_note = '';
		if ( null !== $pair_id ) {
			$now_hash = AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( $pair_id ) );
			if ( $all_back && null !== $forward['pair_before'] ) {
				$restored = AB_MCP_Sync_Pairs::restore( $pair_id, (string) $forward['pair_before'], $now_hash );
				if ( is_wp_error( $restored ) ) {
					$pair_note = ' The pair did not go back to its state before: ' . $restored->get_error_message();
					$all_back  = false;
				}
			} else {
				AB_MCP_Sync_Pairs::set_state( $pair_id, 'unclear', $now_hash );
			}
		}
		$result = array(
			'message'  => $all_back
				? sprintf( 'The %1$s %2$d went back to its state before the run: every part the run wrote was still its own and was put back; the pair has its bases of before.%3$s', $object['kind'], $live_id, $pair_note )
				: sprintf( 'The %1$s %2$d went back only in part: a part was changed since the run wrote it, or needs a check, and is left as it is (see parts); the pair is unclear until a person confirms a new alignment (wp_site_pairs).%3$s', $object['kind'], $live_id, $pair_note ),
			'live_ref' => (string) $live_id,
			'parts'    => $outcomes,
		);
		AB_MCP_Sync_Run::update_step( $run_id, $seq, array( 'state' => $all_back ? 'done' : 'unclear', 'result' => $result, 'pair_id' => $pair_id ), 'begun' );
		AB_MCP_Sync_Run::touch( $run_id );
		return array( 'next' => $result['message'] );
	}

	/**
	 * Close a run of the way back: reverted where every step went back and
	 * nothing is left, else with findings (rests, unclear parts).
	 *
	 * @param array $run Run.
	 * @return array|WP_Error The run as closed.
	 */
	private static function close_revert( array $run ) {
		$steps = AB_MCP_Sync_Run::steps( (int) $run['run_id'] );
		if ( is_wp_error( $steps ) ) {
			return $steps;
		}
		$clean = true;
		foreach ( $steps as $step ) {
			if ( 'revert' !== $step['op'] ) {
				continue;
			}
			if ( 'done' !== $step['state'] || ( is_array( $step['result'] ) && isset( $step['result']['rest'] ) ) ) {
				$clean = false;
			}
		}
		$moved = AB_MCP_Sync_Run::set_state( (int) $run['run_id'], array( 'open', 'reverting' ), $clean ? 'closed_reverted' : 'closed_findings' );
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}
		AB_MCP_Store_Budget::release( (int) $run['run_id'] );
		AB_MCP_Protected_Store::cleanup();
		$closed = AB_MCP_Sync_Run::get( (int) $run['run_id'] );
		return is_array( $closed ) ? $closed : $run;
	}

	/* ------------------------------------------------------------ helpers */

	/**
	 * The pair of a new object the run made.
	 *
	 * @param array  $run      Run.
	 * @param array  $item     Item.
	 * @param int    $live_id  The post made.
	 * @param array  $prepared target_of().
	 * @param string $state    active or residual.
	 * @return array|WP_Error
	 */
	private static function pair_for_new( array $run, array $item, $live_id, array $prepared, $state ) {
		$hash = AB_MCP_Sync_Post_Writer::live_hash( $live_id, self::context( $run )['source'] );
		$made = AB_MCP_Sync_Pairs::create(
			array(
				'source_id'    => (string) $run['source_id'],
				'kind'         => (string) $item['kind'],
				'live_ref'     => (string) $live_id,
				'staging_ref'  => (string) $item['staging_ref'],
				'canon'        => AB_MCP_Sync_Canon::VERSION,
				'base_live'    => null === $hash ? str_repeat( '0', 64 ) : $hash,
				'base_staging' => (string) $item['staging_hash'],
				'base_origin'  => 'push',
				'override'     => ! empty( $prepared['as_draft'] ) ? array( 'post_status' => 'draft' ) : null,
				'last_run'     => (int) $run['run_id'],
			)
		);
		if ( is_array( $made ) && 'residual' === $state ) {
			AB_MCP_Sync_Pairs::set_state( (int) $made['id'], 'residual', AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( (int) $made['id'] ) ) );
		}
		return $made;
	}

	/**
	 * A rest the run left: a new object the way back does not remove.
	 *
	 * @param array $item    kind, staging_ref.
	 * @param int   $live_id The object here.
	 * @return array
	 */
	private static function rest( array $item, $live_id ) {
		return array(
			'kind'        => (string) $item['kind'],
			'live_ref'    => (string) (int) $live_id,
			'staging_ref' => (string) $item['staging_ref'],
			'way'         => 'Made by the run and not removed by it: remove it by hand in wp-admin where it should go; its pair is residual until wp_site_pairs confirms a new alignment or discards it.',
		);
	}

	/**
	 * @param array  $item    Item.
	 * @param string $message Message.
	 * @return array
	 */
	private static function finding( array $item, $message ) {
		return array(
			'seq'         => (int) $item['seq'],
			'kind'        => (string) $item['kind'],
			'staging_ref' => (string) $item['staging_ref'],
			'live_ref'    => isset( $item['live_ref'] ) ? $item['live_ref'] : null,
			'message'     => (string) $message,
		);
	}

	/**
	 * The check failed: the step failed, the run closed as aborted, the
	 * booking given back; nothing was written.
	 *
	 * @param array  $run     Run.
	 * @param array  $step    Step.
	 * @param string $message Why.
	 * @param array  $result  The result so far.
	 * @return array|WP_Error
	 */
	private static function abort_at( array $run, array $step, $message, array $result ) {
		$result['message'] = $message;
		unset( $result['cursor'] );
		AB_MCP_Sync_Run::update_step( (int) $run['run_id'], (int) $step['seq'], array( 'state' => 'failed', 'result' => $result ), 'planned' );
		$moved = AB_MCP_Sync_Run::set_state( (int) $run['run_id'], 'open', 'closed_aborted' );
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}
		AB_MCP_Store_Budget::release( (int) $run['run_id'] );
		return array( 'next' => $message );
	}

	/**
	 * A step that failed before it wrote anything.
	 *
	 * @param int        $run_id  Run.
	 * @param int        $seq     Step.
	 * @param string     $from    The state read.
	 * @param string     $message Why.
	 * @param array|null $journal Journal, where there is one.
	 * @return array|WP_Error
	 */
	private static function fail_step( $run_id, $seq, $from, $message, $journal = null ) {
		$set = array(
			'state'  => 'failed',
			'result' => array( 'message' => 'Not written: ' . $message ),
		);
		if ( null !== $journal ) {
			$set['parts'] = $journal;
		}
		$done = AB_MCP_Sync_Run::update_step( $run_id, $seq, $set, $from );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		AB_MCP_Sync_Run::touch( $run_id );
		return array( 'next' => 'Not written: ' . $message . ' The run goes on with the next step (action=next); the steps that depend on this object are skipped.' );
	}

	/**
	 * @param string   $state   done, failed, restored, unclear.
	 * @param string   $message Message.
	 * @param array    $journal Journal.
	 * @param int|null $live_id Post.
	 * @return array
	 */
	private static function outcome( $state, $message, array $journal, $live_id ) {
		return array(
			'state'   => $state,
			'message' => $message,
			'journal' => $journal,
			'live_id' => $live_id,
		);
	}

	/**
	 * The answer of a call (decision 32).
	 *
	 * @param array      $run     Run as it is now.
	 * @param array|null $step    The step that ran.
	 * @param float      $started When the call began.
	 * @param string     $next    What to do next.
	 * @param bool       $busy    Whether the lock was busy.
	 * @return array|WP_Error
	 */
	private static function answer( array $run, $step, $started, $next, $busy = false ) {
		$shown = AB_MCP_Sync_Run::shown( $run );
		if ( is_wp_error( $shown ) ) {
			return $shown;
		}
		$out = array(
			'run_id'    => (int) $run['run_id'],
			'type'      => $run['type'],
			'state'     => $run['state'],
			'step'      => null,
			'busy'      => (bool) $busy,
			'remaining' => array(
				'planned' => $shown['counts']['planned'],
				'seconds' => max( 0, (int) ( self::BUDGET - ( self::now() - $started ) ) ),
			),
			'counts'    => $shown['counts'],
			'rests'     => $shown['rests'],
			'calls'     => $shown['calls'],
			'duration'  => $shown['duration'],
			'next'      => (string) $next,
		);
		if ( is_array( $step ) ) {
			foreach ( $shown['steps'] as $one ) {
				if ( $one['seq'] === (int) $step['seq'] ) {
					$out['step'] = $one;
				}
			}
		}
		return $out;
	}

	/**
	 * The lines of the error log of the site, where readable; null otherwise.
	 *
	 * @return int|null
	 */
	private static function error_log_lines() {
		$log = WP_CONTENT_DIR . '/debug.log';
		if ( ! is_readable( $log ) ) {
			return null;
		}
		$data = file( $log, FILE_IGNORE_NEW_LINES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file
		return is_array( $data ) ? count( $data ) : null;
	}

	/**
	 * The answer of a published object's address: its status and body.
	 *
	 * @param string $url Address.
	 * @return array{status:int|null,body:string}
	 */
	private static function probe( $url ) {
		if ( null !== self::$probe ) {
			$answer = call_user_func( self::$probe, (string) $url );
			if ( is_array( $answer ) ) {
				return array(
					'status' => isset( $answer['status'] ) && null !== $answer['status'] ? (int) $answer['status'] : null,
					'body'   => isset( $answer['body'] ) ? (string) $answer['body'] : '',
				);
			}
			return array(
				'status' => null === $answer ? null : (int) $answer,
				'body'   => '',
			);
		}
		$answer = wp_remote_get(
			(string) $url,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'sslverify'   => false,
			)
		);
		if ( is_wp_error( $answer ) ) {
			return array(
				'status' => null,
				'body'   => '',
			);
		}
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $answer ),
			'body'   => (string) wp_remote_retrieve_body( $answer ),
		);
	}

	/**
	 * @return float Seconds.
	 */
	public static function now() {
		return null !== self::$clock ? (float) call_user_func( self::$clock ) : microtime( true );
	}
}
