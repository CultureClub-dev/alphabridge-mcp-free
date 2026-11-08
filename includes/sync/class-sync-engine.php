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
 * ONE LOCK PER CALL (decision 18; the decision of 07.11.2026 evening,
 * Variante A): next() and abort() run under the lock of the write boundary
 * for the whole call (AB_MCP_Sync_Run::under_lock(); another request holding
 * it: refused as busy, nothing read or written), read the run under it once
 * (fresh(): in the state the caller read it in, settled) and decide only
 * then; whatever the call does to the run on the way (a step, the close of
 * the check, of the run, of the way back) is done under that lock.
 *
 * THE WRITE BOUNDARY (decision 17, run_write()): under the lock of the call,
 * the step begun with the snapshot of its pair; the takeover lock
 * of the objects (AB_MCP_Sync_Guard); the target and the rights checked
 * again; the backup kept and read back (AB_MCP_Sync_Backup); the journal
 * written ahead of every part (the row, each meta key, each taxonomy: its
 * hash before, its target, its backup, its state), then each part written
 * through the kind (AB_MCP_Sync_Post_Writer), read right before the call
 * and read back from the rows under the same lock after it (pre, post):
 * the step owns a part only where its own call changed it (own, own_after
 * the hash read back; write_parts()), never by the state of the part; the
 * object as a whole read back against the target (the mapped staging form
 * with the confirmed choice); then the pair takes its new bases (or is
 * made, for a new object), the step is done, the guard and the lock go.
 *
 * THE ONE BOUNDARY (the decision of 07.11.2026, «A mit altem Wert»;
 * boundary C of GRENZEN-LAUF.md; deviated()): where anything of the object
 * deviates within the step (another hand changed a part: a hook of this
 * site, a writer on a way the lock does not refuse; a call of the step's
 * own was refused or failed; a part reads back other than the call was to
 * write; the object as a whole does not hash to its target), nothing of
 * the object is put back by itself, neither within the boundary nor by the
 * way back: every part that is not as the backup is left as it is, with
 * the value it held before (value_before, from the backup, never a
 * credential), the object carries the mark (object.deviated, with the part
 * and why), the step is unclear and so is the pair; a person checks the
 * object in wp-admin and aligns the pair. The backup stays (decision 20).
 * Before, the step turned within the boundary and put its own parts back,
 * and every round of Codex since round 2 found a new way in which that turn
 * or the way back took a part back that was not provably the step's own.
 *
 * A request that ends in a boundary leaves the step begun:
 * AB_MCP_Sync_Run::settle() makes the run interrupted once the lock is no
 * longer held (decision 18); an interruption is no deviation of the object.
 *
 * THE WAY BACK (decision 23, run_revert()): for one write step of the run
 * (or of a closed run, a run of the type revert), each part of its journal
 * decided right before its turn on the state read then (put_back(), with
 * decide() the one place of the rule): put back where its hash now is
 * own_after, the hash the step read back after its own call changed the
 * part, nothing where it is the hash of the backup, left and named with its
 * value before otherwise (a part of an object that deviated always, a part
 * never read back always: a hash equal to the target proves no ownership);
 * the row first, then the meta, then the terms, and every part read again
 * against the backup after that; a new object is never removed and stays
 * as a rest, its pair residual (rule 0); the pair of a restored object
 * goes back to its snapshot. A run of the way back closes as reverted, or
 * with findings where rests or parts left remain.
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
require_once __DIR__ . '/class-sync-term-writer.php';
require_once __DIR__ . '/class-sync-attachment-writer.php';
require_once __DIR__ . '/class-sync-menu-writer.php';
require_once __DIR__ . '/class-sync-widget-area-writer.php';
require_once __DIR__ . '/class-sync-css-writer.php';
require_once __DIR__ . '/class-sync-write.php';
require_once __DIR__ . '/class-sync-option-writer.php';
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

	/** Seconds one call takes at most where the request has no time limit: the work (AB_MCP_Sync_Resources::WORK_MAX) and the reserve. */
	const BUDGET = AB_MCP_Sync_Resources::WORK_MAX + AB_MCP_Sync_Resources::WORK_RESERVE;

	/** Seconds the check and the verification keep for one more object; with less left, the next call goes on. */
	const RESERVE = AB_MCP_Sync_Resources::WORK_RESERVE;

	/**
	 * Seconds of work the call running now does at most: WORK_MAX with no
	 * time limit of the request, half the limit otherwise
	 * (AB_MCP_Sync_Resources::time_for_call(), set by next(); GitHub Codex
	 * round 3 on free#137). The check and the verification stop here and go
	 * on in the next call.
	 *
	 * @var int
	 */
	private static $work = AB_MCP_Sync_Resources::WORK_MAX;

	/** Bytes of the error log one verification reads at most, counting the lines it gained since the check (error_log_lines_since()). */
	const LOG_BYTES = 16777216;

	/** Bytes of one read of the error log. */
	const LOG_CHUNK = 65536;

	/** The clock: a callable returning seconds (float), null for microtime(). Set by tests only. */
	public static $clock = null;

	/** A probe of a published object: callable( string $url ): array{status:int|null,body:string}|int|null, null for wp_remote_get(). Set by tests only. */
	public static $probe = null;

	/**
	 * The write side of each kind a run writes (contract A; branch 9): the
	 * class whose static methods prepare, check, write, read back and put
	 * back one object of the kind, with the same names for every kind
	 * (target_of(), precheck(), current(), journal_parts(), write_row(),
	 * write_part(), restore_part(), …). A kind without one is written by no
	 * step (writer() null: the step fails before anything is written). Since
	 * branch 11 the kinds of the structure of a site: a menu (its term and its
	 * positions) and its items, a widget area with its instances, the
	 * additional CSS of a stylesheet and a core option; the objects of the
	 * last three are named by a name, not an id (ref_of(), live_of()).
	 */
	const WRITERS = array(
		'post'        => 'AB_MCP_Sync_Post_Writer',
		'term'        => 'AB_MCP_Sync_Term_Writer',
		'attachment'  => 'AB_MCP_Sync_Attachment_Writer',
		'menu'        => 'AB_MCP_Sync_Menu_Writer',
		'menu_item'   => 'AB_MCP_Sync_Menu_Item_Writer',
		'widget_area' => 'AB_MCP_Sync_Widget_Area_Writer',
		'custom_css'  => 'AB_MCP_Sync_Css_Writer',
		'option'      => 'AB_MCP_Sync_Option_Writer',
	);

	/**
	 * The write side of a kind, or null where no step writes the kind.
	 *
	 * @param string $kind Kind of the plan (post, term).
	 * @return string|null The class name.
	 */
	public static function writer( $kind ) {
		$kind = (string) $kind;
		return isset( self::WRITERS[ $kind ] ) ? self::WRITERS[ $kind ] : null;
	}

	/**
	 * The write side of the object of a journal (its kind), the post's for a
	 * journal of branch 8 that names no other.
	 *
	 * @param array $journal Journal.
	 * @return string|null The class name.
	 */
	private static function writer_of( array $journal ) {
		return self::writer( isset( $journal['object']['kind'] ) ? (string) $journal['object']['kind'] : 'post' );
	}

	/**
	 * The object of this site a ref of the plan, of a journal or of a result
	 * names, in the form the write side of its kind takes it (branch 11): an
	 * id as an integer (a post, a term, an attachment, a menu and its items),
	 * as before; a name as the writer reads it where the kind names its
	 * objects so (ref_of(): a widget area, the stylesheet of the additional
	 * CSS, an option), null where the ref names none.
	 *
	 * @param string|null $w   The write side of the kind (writer()).
	 * @param mixed       $raw The ref as stored.
	 * @return int|string|null
	 */
	public static function live_of( $w, $raw ) {
		if ( null !== $w && method_exists( $w, 'ref_of' ) ) {
			return $w::ref_of( $raw );
		}
		return (int) $raw;
	}

	/**
	 * Whether live_of() named an object: an id above 0, or a name.
	 *
	 * @param mixed $live_id live_of().
	 * @return bool
	 */
	public static function is_ref( $live_id ) {
		return is_int( $live_id ) ? $live_id > 0 : ( is_string( $live_id ) && '' !== $live_id );
	}

	/* --------------------------------------------------------------- next */

	/**
	 * Run one step of a run: the next one whose dependencies are done, or a
	 * named one (refused while a dependency is open). The run has to be
	 * open (or reverting for a step of the way back), settled and the
	 * caller's own. The whole call runs under the lock of the write boundary
	 * (AB_MCP_Sync_Run::under_lock(): another request holding it refuses the
	 * call as busy, nothing read or written), and the run is read under it
	 * (fresh()) before anything is decided.
	 *
	 * @param array    $run   The run as the caller read it (AB_MCP_Sync_Run::get()).
	 * @param int|null $named The seq of the step to run; null for the next.
	 * @return array|WP_Error The answer (answer()).
	 */
	public static function next( array $run, $named = null ) {
		return AB_MCP_Sync_Run::under_lock(
			static function () use ( $run, $named ) {
				$fresh = self::fresh( $run );
				return is_wp_error( $fresh ) ? $fresh : self::step_of_call( $fresh, $named );
			},
			sprintf( 'Run %d takes no step', (int) $run['run_id'] )
		);
	}

	/**
	 * The run as it is under the lock of the call, the one place that reads
	 * it for the decision (decision 18; the decision of 07.11.2026 evening,
	 * Variante A): in the state the caller read it in, or nothing is written
	 * (a request that read the run before another call closed, aborted,
	 * settled or led it on must not write into it; GitHub Codex rounds 4 and
	 * 6 on free#137), and settled under the lock: a step a request that
	 * ended left begun makes it interrupted here, refused with both ways.
	 * Before, each boundary read the run again (still()), and the check
	 * before the first write, the caches, the check of the run and the close
	 * of the way back did not.
	 *
	 * @param array $run The run as the caller read it.
	 * @return array|WP_Error The run as it is now.
	 */
	private static function fresh( array $run ) {
		$now = AB_MCP_Sync_Run::get( (int) $run['run_id'] );
		if ( ! is_array( $now ) ) {
			return is_wp_error( $now ) ? $now : AB_MCP_Sync_Run::unknown( (int) $run['run_id'] );
		}
		if ( isset( $now['unread'] ) ) {
			// A record of the run that does not read back as it was written
			// (AB_MCP_Sync_Run::records()): nothing is decided on it.
			return new WP_Error( 'ab_mcp_sync_run_unread', sprintf( 'Run %1$d is not changed: its %2$s does not read back as it was written (a marker of its bytes that does not hold), so nothing of the run is decided on it. Ask the host to check the table of the runs; the backups of the run in the protected store hold the objects as they were before its steps.', (int) $run['run_id'], implode( ' and ', $now['unread'] ) ) );
		}
		if ( (string) $now['state'] !== (string) $run['state'] ) {
			return new WP_Error( 'ab_mcp_sync_run_state', sprintf( 'Run %1$d was %2$s when this call read it and is %3$s now (another call moved it in between): nothing was written, its steps stay as they are. Ask for the status of the run (wp_site_push action=status) and go on from there.', (int) $run['run_id'], (string) $run['state'], (string) $now['state'] ) );
		}
		$now = AB_MCP_Sync_Run::settle( $now );
		if ( is_wp_error( $now ) ) {
			return $now;
		}
		return 'interrupted' === $now['state'] ? AB_MCP_Sync_Run::interrupted_refusal( $now ) : $now;
	}

	/**
	 * next() under the lock, on the run as fresh() read it.
	 *
	 * @param array    $run   The run, read under the lock.
	 * @param int|null $named The seq of the step to run; null for the next.
	 * @return array|WP_Error The answer (answer()).
	 */
	private static function step_of_call( array $run, $named ) {
		$started = self::now();
		// The time of this request: raised where set_time_limit() takes, the
		// work of the call derived from the limit it has (GitHub Codex round 3
		// on free#137, P2: before, the loops worked 50 s under a limit of 30).
		$time       = AB_MCP_Sync_Resources::time_for_call();
		self::$work = (int) $time['budget'];
		$steps      = AB_MCP_Sync_Run::steps( (int) $run['run_id'] );
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
				return self::answer( $closed, null, $started, self::closed_note( $closed ) );
			}
			return self::answer( $run, null, $started, 'No step is left to run: the run is closed, or every remaining step waits on one that was skipped or failed (see the steps in status).' );
		}
		// A write step and a step of the way back mutate under the lock, with
		// their backup and journal: a request whose time limit does not carry
		// one (under AB_MCP_Sync_Resources::MIN_TIME, not raised) does not run
		// it, with the way; the step stays planned, nothing is written. The
		// check and the verification read and go on with the smaller work.
		if ( in_array( $picked['op'], array( 'write', 'revert' ), true ) && ! $time['writes'] ) {
			return new WP_Error(
				'ab_mcp_sync_run_time',
				sprintf( 'Step %1$d is not run: the PHP time limit of this request is %2$d s (set_time_limit() did not raise it), and one step that writes needs up to %3$d s; a step ended by the limit halfway would leave the object in between. Nothing was written; the step stays planned. Raise max_execution_time to at least %3$d s at the host (at Infomaniak under Advanced → PHP | Apache), or let set_time_limit() take, then call again.', (int) $picked['seq'], (int) $time['limit'], AB_MCP_Sync_Resources::MIN_TIME ),
				array( 'time' => $time )
			);
		}
		switch ( $picked['op'] ) {
			case 'check':
				$done = self::run_check( $run, $picked, $ctx, $started );
				break;
			case 'write':
				$done = self::run_write( $run, $picked, $ctx );
				break;
			case 'post':
				$done = self::run_post( $run, $picked, $ctx, $started );
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
		$next = (string) $done['next'];
		// A run of the way back closes once no step is left.
		if ( is_array( $now ) && ( 'reverting' === $now['state'] || ( 'revert' === $now['type'] && 'open' === $now['state'] ) ) && null === self::pick( $now, AB_MCP_Sync_Run::steps( (int) $run['run_id'] ), null, true ) ) {
			$closed = self::close_revert( $now );
			if ( ! is_wp_error( $closed ) ) {
				$now   = $closed;
				$next .= ' ' . self::closed_note( $closed );
			}
		}
		return self::answer( is_array( $now ) ? $now : $run, is_array( $step ) ? $step : null, $started, $next );
	}

	/**
	 * Abort an open run: closed as aborted; what its steps wrote stays (the
	 * way back is wp_site_revert for the run), its booking is given back.
	 * Under the lock of the write boundary for the whole call (decision 18;
	 * GitHub Codex round 4 on free#137, P2: before, the abort took no lock,
	 * and a delayed request could move its planned step to begun and write
	 * into a closed run whose booking was given back): another request
	 * holding it refuses as busy, nothing changed; the run is read and
	 * settled under it, and the close, the booking given back and the
	 * clean-up of the store run under it as well.
	 *
	 * @param array $run Run.
	 * @return array|WP_Error The run as closed.
	 */
	public static function abort( array $run ) {
		if ( 'open' !== $run['state'] ) {
			return new WP_Error( 'ab_mcp_sync_run_state', sprintf( 'Only an open run is aborted; this run is %s. An interrupted run is closed as checked by a person on the page AlphaBridge → Staging, or wp_site_revert action=plan with its run_id leads its way back on or begins it; a run on its way back runs to its end with wp_site_revert action=next; a closed run is not aborted.', (string) $run['state'] ) );
		}
		return AB_MCP_Sync_Run::under_lock(
			static function () use ( $run ) {
				$fresh = AB_MCP_Sync_Run::get( (int) $run['run_id'] );
				if ( ! is_array( $fresh ) ) {
					return is_wp_error( $fresh ) ? $fresh : AB_MCP_Sync_Run::unknown( (int) $run['run_id'] );
				}
				if ( 'open' !== $fresh['state'] ) {
					return new WP_Error( 'ab_mcp_sync_run_changed', sprintf( 'The run %1$d is %2$s now (another call moved it since this one read it as open): nothing was changed. Ask for its status (wp_site_push action=status) and go on from there.', (int) $run['run_id'], (string) $fresh['state'] ) );
				}
				// A step begun under the lock of this call is one a request
				// that ended left: the run is interrupted, and is not aborted.
				$fresh = AB_MCP_Sync_Run::settle( $fresh );
				if ( is_wp_error( $fresh ) ) {
					return $fresh;
				}
				if ( 'interrupted' === $fresh['state'] ) {
					return AB_MCP_Sync_Run::interrupted_refusal( $fresh );
				}
				$moved = AB_MCP_Sync_Run::set_state( (int) $run['run_id'], 'open', 'closed_aborted' );
				if ( is_wp_error( $moved ) ) {
					return $moved;
				}
				AB_MCP_Store_Budget::release( (int) $run['run_id'] );
				AB_MCP_Protected_Store::cleanup();
				$closed = AB_MCP_Sync_Run::get( (int) $run['run_id'] );
				return is_array( $closed ) ? $closed : $run;
			},
			sprintf( 'Run %d is not aborted', (int) $run['run_id'] )
		);
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
		$media = array();
		foreach ( isset( $plan['packages'] ) && is_array( $plan['packages'] ) ? $plan['packages'] : array() as $package_id ) {
			// A package of media (branch 10): its index of files, by the
			// attachment of the staging site and the path; its bytes are read
			// a file at a time as a step lays it.
			if ( 'media' === AB_MCP_Sync_Import::purpose_of( $package_id ) ) {
				$taken = AB_MCP_Sync_Import::taken( $package_id, 'media' );
				if ( is_wp_error( $taken ) ) {
					return $taken;
				}
				if ( ! hash_equals( (string) $run['report_hash'], (string) $taken['manifest']['report_hash'] ) ) {
					return new WP_Error( 'ab_mcp_sync_objects_report', sprintf( 'The package of media %s was made for another report than the one of this run, and a package is used for its own report only. Export the media again on the staging site for this report (wp_site_export with purpose=media and its report_hash).', $package_id ) );
				}
				foreach ( $taken['files'] as $file ) {
					$media[ $file['ref'] . "\0" . $file['path'] ] = array(
						'package' => (string) $package_id,
						'sha256'  => (string) $file['sha256'],
						'size'    => (int) $file['size'],
					);
				}
				continue;
			}
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
			'media' => $media,
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
	 * @return array|WP_Error next.
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
			// The lock (decision 18) is held: this call runs under it (next()).
			$result['resources'] = $check['level'];
			$result['notes']     = array();
			if ( empty( $plan['environment'] ) ) {
				$result['notes'][] = 'The environment of the two sites was not compared in the report (wp_site_diff without environment): the run writes objects only, which this does not hold back.';
			}
			// The error log marked by its size: the verification counts the
			// lines it gained from there (error_log_lines_since()).
			$mark                      = self::error_log_mark();
			$result['error_log_bytes'] = null === $mark ? null : (int) $mark['bytes'];
			$result['checked']         = 0;
			$result['findings']        = array();
		}
		$lines = self::lines( $run );
		if ( is_wp_error( $lines ) ) {
			return self::abort_at( $run, $step, $lines->get_error_message(), $result );
		}
		// The files of the packages of media of the run (branch 10).
		$ctx['media'] = $lines['media'];
		// The objects the run creates: a reference to one of them is pending,
		// not missing (B1); the boundary maps it by the pair its step made.
		$ctx['pending'] = self::pending( $items );
		// The templates and template parts the run creates, by the names a
		// content or a page names them by (branch 9b): a template part by
		// theme and slug, a template by its slug (page_template).
		$ctx['pending_named'] = self::pending_named( $items, $lines );
		// The objects as the run writes them, for a navigation (where the run
		// leaves a navigation block without a menu of its own; branch 9b).
		$ctx['planned'] = self::planned( $items, $lines );
		// The parents the run gives the terms of this site it writes: the
		// check asks a loop of parents of the state the run leaves (branch 9,
		// Codex round 1, B4); the boundary asks the state as it is then.
		$ctx['parents'] = self::planned_parents( $items, $lines, $ctx );
		// The findings of the calls before, kept with the cursor (B3).
		$findings = isset( $result['findings'] ) && is_array( $result['findings'] ) ? $result['findings'] : array();
		$count    = count( $items );
		for ( $i = $at; $i < $count; $i++ ) {
			if ( $i > $at && self::now() - $started > self::$work ) {
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
		$w    = self::writer( $item['kind'] );
		if ( null === $w ) {
			return self::finding( $item, sprintf( 'No step of this version writes the kind %s.', $item['kind'] ) );
		}
		$line = self::line_of( $item, $lines );
		if ( is_wp_error( $line ) ) {
			return self::finding( $item, $line->get_error_message() );
		}
		// The parent step of an attachment the run creates (branch 10): the
		// step that creates it checks the attachment, the step of the parent
		// the parent; this one writes once both are done.
		if ( 'attach' === $item['op'] ) {
			return null;
		}
		$live_id = null;
		if ( 'update' === $item['op'] ) {
			$live_id = self::live_of( $w, $item['live_ref'] );
			$hash    = $w::live_hash( $live_id, $ctx['source'] );
			if ( null === $hash || ! hash_equals( (string) $item['live_hash'], $hash ) ) {
				return self::finding( $item, sprintf( 'The %1$s %2$s of this site changed since the plan (or cannot be read whole), so the %3$s is not written over it.', $item['kind'], $live_id, $name ) );
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
		$prepared = $w::target_of( $line, $item, $ctx );
		if ( is_wp_error( $prepared ) ) {
			return self::finding( $item, $prepared->get_error_message() );
		}
		$ok = $w::precheck( $prepared, $live_id, (int) $ctx['now'] );
		if ( is_wp_error( $ok ) ) {
			return self::finding( $item, $ok->get_error_message() );
		}
		if ( null !== $live_id ) {
			$ok = $w::live_rule( $live_id, $ctx['source'] );
			if ( is_wp_error( $ok ) ) {
				return self::finding( $item, $ok->get_error_message() );
			}
		}
		return null;
	}

	/**
	 * The parents a run gives the terms of this site it writes: term here =>
	 * the parent here its target names (AB_MCP_Sync_Term_Writer::target_of()),
	 * for the check before the first write, which asks a loop of parents of
	 * the state the run leaves (branch 9, Codex round 1, B4: two categories
	 * that swap their places were locked against the state here). A term
	 * whose parent the run makes first is left out: it counts as it is here.
	 *
	 * @param array[] $items The objects of the plan.
	 * @param array   $lines lines().
	 * @param array   $ctx   context(), with pending.
	 * @return array<int,int>
	 */
	private static function planned_parents( array $items, array $lines, array $ctx ) {
		$out = array();
		foreach ( $items as $one ) {
			if ( 'term' !== ( isset( $one['kind'] ) ? (string) $one['kind'] : '' ) || 'update' !== ( isset( $one['op'] ) ? (string) $one['op'] : '' ) ) {
				continue;
			}
			$line = self::line_of( $one, $lines );
			if ( is_wp_error( $line ) ) {
				continue;
			}
			$prepared = AB_MCP_Sync_Term_Writer::target_of( $line, $one, $ctx );
			// A parent the run makes first has no id here yet: the term counts
			// as it is here (fail closed; the boundary asks it again).
			if ( is_wp_error( $prepared ) || ! empty( $prepared['pending'] ) ) {
				continue;
			}
			$out[ (int) $one['live_ref'] ] = (int) $prepared['args']['parent'];
		}
		return $out;
	}

	/**
	 * The objects a run creates, by the space of their ids: a reference to
	 * one of them is pending in the check before the first write, not
	 * missing (B1); the boundary maps it by the pair its step made. Posts in
	 * the space of posts, terms in the space of terms (branch 9: a new post
	 * in a new category, a new term under a new parent).
	 *
	 * @param array[] $items The objects of the plan.
	 * @return array{post:array<int,true>,term:array<int,true>}
	 */
	private static function pending( array $items ) {
		$pending = array(
			'post' => array(),
			'term' => array(),
		);
		foreach ( $items as $one ) {
			if ( ! isset( $one['op'], $one['kind'], $one['staging_ref'] ) || 'create' !== $one['op'] ) {
				continue;
			}
			// The space of its ids, as the pairs have it (AB_MCP_Sync_Proposal::
			// SPACES): a post, an attachment (branch 10) and a menu item in that
			// of posts, a term and a menu in that of terms (branch 11).
			$space = null;
			foreach ( AB_MCP_Sync_Proposal::SPACES as $name => $kinds ) {
				if ( in_array( (string) $one['kind'], $kinds, true ) ) {
					$space = $name;
				}
			}
			if ( null !== $space ) {
				$pending[ $space ][ (int) $one['staging_ref'] ] = true;
			}
		}
		return $pending;
	}

	/**
	 * The templates and template parts a run creates, by their names (branch
	 * 9b): a template part by theme//slug (a content names it so,
	 * core/template-part), a template by its slug (a page names it so in
	 * _wp_page_template); from the identity of each line. Only for the check
	 * before the first write, as pending(): the boundary finds them made.
	 *
	 * @param array[] $items The objects of the plan.
	 * @param array   $lines lines().
	 * @return array{wp_template:array<string,true>,wp_template_part:array<string,true>}
	 */
	private static function pending_named( array $items, array $lines ) {
		$out = array(
			'wp_template'      => array(),
			'wp_template_part' => array(),
		);
		foreach ( $items as $one ) {
			if ( ! isset( $one['op'], $one['kind'], $one['subtype'] ) || 'create' !== $one['op'] || 'post' !== $one['kind'] || ! isset( $out[ (string) $one['subtype'] ] ) ) {
				continue;
			}
			$line = self::line_of( $one, $lines );
			if ( is_wp_error( $line ) || ! isset( $line['identity']['post_name'], $line['identity']['theme'] ) || ! is_string( $line['identity']['post_name'] ) || ! is_string( $line['identity']['theme'] ) ) {
				continue;
			}
			$name = 'wp_template' === $one['subtype'] ? $line['identity']['post_name'] : $line['identity']['theme'] . '//' . $line['identity']['post_name'];

			$out[ (string) $one['subtype'] ][ $name ] = true;
		}
		return $out;
	}

	/**
	 * The objects of the kind post whose planned version counts for the
	 * check of a navigation (AB_MCP_Sync_Post_Writer::fallback_shown(); branch
	 * 9b, Codex rounds 1 and 2): what is seen after the run is, for each
	 * object of the run, its planned version where its step is still to be
	 * written (planned), and the state of this site, read afresh, where its
	 * step began or ended (done, failed, skipped, unclear, …: whatever it
	 * wrote or did not write stands here now). The check before the first
	 * write passes no states: no step has ended, every planned version
	 * counts. Each with type, op, staging_ref, live_ref, theme and slug of
	 * the identity, the status the step writes (draft for the confirmed
	 * choice), and the content of the staging object where it can show a
	 * navigation block (it names a navigation, a pattern, a reusable block or
	 * a template part), else none: every object that replaces one of this
	 * site counts with it, also where its new version shows no navigation
	 * block any more (Codex round 2, 3), as do the templates, template parts
	 * and reusable blocks it creates. Only where the run writes a navigation.
	 *
	 * @param array[]    $items  The objects of the plan.
	 * @param array      $lines  lines().
	 * @param array|null $states seq => state of the steps of the run; null for the check before the first write.
	 * @return array[]
	 */
	private static function planned( array $items, array $lines, $states = null ) {
		$navigation = false;
		foreach ( $items as $one ) {
			if ( isset( $one['kind'], $one['subtype'] ) && 'post' === $one['kind'] && 'wp_navigation' === (string) $one['subtype'] ) {
				$navigation = true;
			}
		}
		if ( ! $navigation ) {
			return array();
		}
		$out = array();
		foreach ( $items as $one ) {
			if ( ! isset( $one['kind'], $one['op'], $one['subtype'], $one['staging_ref'] ) || 'post' !== $one['kind'] ) {
				continue;
			}
			// A step that began or ended: the state of this site counts.
			if ( is_array( $states ) && ( ! isset( $one['seq'] ) || 'planned' !== ( $states[ (int) $one['seq'] ] ?? '' ) ) ) {
				continue;
			}
			$line = self::line_of( $one, $lines );
			if ( is_wp_error( $line ) || ! isset( $line['content']['row'] ) || ! is_array( $line['content']['row'] ) ) {
				continue;
			}
			$type    = (string) $one['subtype'];
			$content = AB_MCP_Sync_Post_Writer::bytes( $line['content']['row']['post_content'] ?? '' );
			$marked  = false;
			foreach ( array( 'wp:navigation', 'wp:pattern', 'wp:block ', 'wp:template-part' ) as $mark ) {
				$marked = $marked || false !== strpos( $content, $mark );
			}
			$replaces = 'update' === $one['op'] && isset( $one['live_ref'] );
			if ( ! $marked && ! $replaces && ! in_array( $type, array( 'wp_template', 'wp_template_part', 'wp_block' ), true ) ) {
				continue;
			}
			$out[] = array(
				'type'        => $type,
				'op'          => (string) $one['op'],
				'staging_ref' => (string) $one['staging_ref'],
				'live_ref'    => 'update' === $one['op'] && isset( $one['live_ref'] ) ? (int) $one['live_ref'] : null,
				'theme'       => isset( $line['identity']['theme'] ) && is_string( $line['identity']['theme'] ) ? $line['identity']['theme'] : '',
				'slug'        => isset( $line['identity']['post_name'] ) && is_string( $line['identity']['post_name'] ) ? $line['identity']['post_name'] : '',
				'status'      => ! empty( $one['as_draft'] ) ? 'draft' : AB_MCP_Sync_Post_Writer::bytes( $line['content']['row']['post_status'] ?? '' ),
				'content'     => $marked ? $content : '',
			);
		}
		return $out;
	}

	/**
	 * Phase 5: one write boundary (decision 17), under the lock of the call
	 * (next()); the takeover lock of the object given back in every way.
	 *
	 * @param array $run  Run.
	 * @param array $step Step.
	 * @param array $ctx  context().
	 * @return array|WP_Error next.
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
		try {
			return self::boundary( $run, $step, $item, $ctx );
		} finally {
			AB_MCP_Sync_Guard::end( (int) $run['run_id'], (int) $step['seq'] );
		}
	}

	/**
	 * The boundary itself, under the lock of the call, on the run as the
	 * call read it under that lock (fresh()).
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
		// The parent step of an attachment the run created (branch 10): an
		// update of that attachment, the one its step made.
		if ( 'attach' === $item['op'] ) {
			$item = self::attach_item( $run, $item );
			if ( is_wp_error( $item ) ) {
				return self::fail_step( $run_id, $seq, 'planned', $item->get_error_message() );
			}
		}
		$w       = self::writer( $item['kind'] );
		$live_id = 'update' === $item['op'] ? self::live_of( $w, $item['live_ref'] ) : null;
		$before  = null;
		if ( null === $w ) {
			return self::fail_step( $run_id, $seq, 'planned', sprintf( 'no step of this version writes the kind %s', $item['kind'] ) );
		}
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
				// Whether the object deviated within the step (deviated(): the
				// part and why), null where it did not; a journal without the
				// key is of an earlier build (revert_boundary(): fail closed).
				'deviated'    => null,
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
		// (3) The takeover lock of the object (its kind names what it covers).
		$covers = $w::guard_of( $live_id, (string) $item['subtype'] );
		$guard  = AB_MCP_Sync_Guard::begin( $run_id, $seq, $covers['posts'], $covers['taxonomies'], isset( $covers['options'] ) ? $covers['options'] : array(), $covers['terms'] );
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
		// The files of the packages of media of the run (branch 10).
		$ctx['media'] = $lines['media'];
		// For a navigation, the objects of the run by the state of their
		// steps (planned(); Codex round 2 of branch 9b, 1): a step that ended
		// before this one (a part that failed) leaves this site as it is.
		$ctx['planned'] = array();
		if ( 'wp_navigation' === (string) ( $item['subtype'] ?? '' ) ) {
			$steps = AB_MCP_Sync_Run::steps( $run_id );
			if ( is_wp_error( $steps ) ) {
				return self::fail_step( $run_id, $seq, 'begun', $steps->get_error_message(), $journal );
			}
			$states = array();
			foreach ( $steps as $one ) {
				$states[ (int) $one['seq'] ] = (string) $one['state'];
			}
			$ctx['planned'] = self::planned( isset( $run['plan']['objects'] ) && is_array( $run['plan']['objects'] ) ? $run['plan']['objects'] : array(), $lines, $states );
		}
		$prepared = $w::target_of( $line, $item, $ctx );
		if ( is_wp_error( $prepared ) ) {
			return self::fail_step( $run_id, $seq, 'begun', $prepared->get_error_message(), $journal );
		}
		$ok = $w::precheck( $prepared, $live_id, (int) $ctx['now'] );
		if ( is_wp_error( $ok ) ) {
			return self::fail_step( $run_id, $seq, 'begun', $ok->get_error_message(), $journal );
		}
		$current = null === $live_id ? null : $w::current( $live_id );
		if ( is_wp_error( $current ) ) {
			return self::fail_step( $run_id, $seq, 'begun', $current->get_error_message(), $journal );
		}
		if ( null !== $live_id ) {
			if ( null === $current ) {
				return self::fail_step( $run_id, $seq, 'begun', sprintf( 'The %1$s %2$s of this site is no longer there; nothing was written.', $item['kind'], $live_id ), $journal );
			}
			$hash = $w::live_hash( $live_id, $ctx['source'] );
			if ( null === $hash || ! hash_equals( (string) $item['live_hash'], $hash ) ) {
				return self::fail_step( $run_id, $seq, 'begun', sprintf( 'The %1$s %2$s of this site changed since the plan (or cannot be read whole); nothing was written. Make a new report and plan again.', $item['kind'], $live_id ), $journal );
			}
			$ok = $w::live_rule( $live_id, $ctx['source'] );
			if ( is_wp_error( $ok ) ) {
				return self::fail_step( $run_id, $seq, 'begun', $ok->get_error_message(), $journal );
			}
		} elseif ( is_array( AB_MCP_Sync_Pairs::find_staging( (string) $item['source_id'], (string) $item['kind'], (string) $item['staging_ref'] ) ) ) {
			return self::fail_step( $run_id, $seq, 'begun', sprintf( 'The %1$s %2$s of the staging site has a pair here since the plan; it is not created anew.', $item['kind'], $item['staging_ref'] ), $journal );
		}
		$journal['object']['target']   = $prepared['target_hash'];
		$journal['object']['identity'] = $prepared['identity'];
		// (5) The backup, checked by its hash; without it no mutation. First
		// whether it fits what the run booked beside the backups it holds
		// (AB_MCP_Sync_Backup::fits(); GitHub Codex round 6 on free#137, P2:
		// the plan reserves from the rows of this site, and the writer holds
		// the run to its booking before each backup).
		// A new object leaves a mark; one whose step sets fields of a row other
		// objects share (the positions of a new menu, branch 11) keeps those
		// fields as they are now, for its way back (decision 23: the
		// positions go back, the new object stays).
		$kept = null === $current ? array(
			'absent'   => true,
			'identity' => $prepared['identity'],
		) + ( method_exists( $w, 'shared_state' ) ? array( 'parts' => $w::shared_state() ) : array() ) : $current;
		$fits = AB_MCP_Sync_Backup::fits( $run_id, $kept );
		if ( is_wp_error( $fits ) ) {
			return self::fail_step( $run_id, $seq, 'begun', $fits->get_error_message(), $journal );
		}
		$backup = AB_MCP_Sync_Backup::put( $run_id, sprintf( 'step-%04d', $seq ), $kept );
		if ( is_wp_error( $backup ) ) {
			return self::fail_step( $run_id, $seq, 'begun', $backup->get_error_message(), $journal );
		}
		$journal['backup'] = $backup;
		// (6) The parts, written ahead: the row, each meta key (of the target
		// and of this site), each taxonomy. Each with its hash before (the
		// backup), its target, its state (a description), and the marks of
		// ownership write_parts() sets: pre and post, the hashes read right
		// before and right after a call of the step's own; own, own_after and
		// own_target where that call changed the part (the ownership rule).
		$parts = array();
		foreach ( $w::journal_parts( $prepared, $current ) as $one ) {
			$parts[] = array(
				'part'       => (string) $one['part'],
				'way'        => (string) $one['way'],
				'before'     => $one['before'],
				'target'     => (string) $one['target'],
				'state'      => 'planned',
				'after'      => null,
				'pre'        => null,
				'post'       => null,
				'own'        => false,
				'own_after'  => null,
				'own_target' => null,
			);
		}
		$journal['parts'] = $parts;
		$ahead            = AB_MCP_Sync_Run::update_step( $run_id, $seq, array( 'parts' => $journal ), 'begun' );
		if ( is_wp_error( $ahead ) ) {
			// Nothing of the object is written without its journal ahead
			// (decision 17; GitHub Codex round 9 on free#137, P2).
			return self::fail_step( $run_id, $seq, 'begun', sprintf( 'the journal of the step could not be written ahead of its first part (%s), so nothing of the object was written', $ahead->get_error_message() ) );
		}
		// The files of an attachment, before its row (decision 26; branch
		// 10): each laid exclusively, the journal saved before each; where one
		// fails, the files laid stay as the run's own and no row is written.
		if ( method_exists( $w, 'lay_files' ) ) {
			$laid    = $w::lay_files( $run_id, $seq, $journal, $prepared, $lines, $live_id );
			$journal = $laid['journal'];
			if ( true !== $laid['ok'] ) {
				$result = array(
					'message'  => $laid['message'],
					'live_ref' => null === $live_id ? null : (string) $live_id,
				);
				$result = self::with_files( $result, $journal, $w );
				$ended  = self::end_step( $run_id, $seq, array( 'state' => isset( $laid['state'] ) && 'unclear' === $laid['state'] ? 'unclear' : 'failed', 'parts' => self::settled( $journal ), 'result' => $result ) );
				return is_wp_error( $ended ) ? $ended : array( 'next' => $laid['message'] . ' The run goes on with the next step (action=next); the steps that depend on this object are skipped.' );
			}
		}
		// The writes, part by part, each read back under the lock.
		$outcome = self::write_parts( $run_id, $seq, $journal, $prepared, $current, $live_id, $w );
		$journal = self::settled( $outcome['journal'] );
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
			} elseif ( null !== $pair_id && array() !== self::made_in( $w, $journal, $live_id ) ) {
				// A part of the unit the step made and that stays (a widget
				// instance, branch 11; decisions 23 and 28): the whole unit is
				// a rest, its bases of before kept, its mapping of the run in
				// base_extra, so no later run makes the part a second time.
				$kept = self::unit_rest( $pair_id, $prepared );
				$rest = self::rest( $item, $live_id ) + array( 'made' => self::made_in( $w, $journal, $live_id ) );
				if ( is_wp_error( $kept ) ) {
					$outcome['state']    = 'unclear';
					$outcome['message'] .= ' ' . self::unit_rest_failed( $kept );
				}
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
			$result = self::with_files( $result, $journal, $w );
			$ended  = self::end_step( $run_id, $seq, array( 'state' => $outcome['state'], 'parts' => $journal, 'result' => $result, 'pair_id' => $pair_id ) );
			if ( is_wp_error( $ended ) ) {
				return $ended;
			}
			return array( 'next' => $outcome['message'] . ' The run goes on with the next step (action=next); the steps that depend on this object are skipped.' );
		}
		// (7) The object as a whole against the target: a deviation like any
		// other (deviated()), the parts left with their values before.
		$hash = $w::live_hash( $live_id, $ctx['source'] );
		if ( null === $hash || ! hash_equals( $prepared['target_hash'], $hash ) ) {
			$outcome = self::deviated( $journal, 0, 'every part read back as written, but the object as a whole does not hash to its target (a filter of this site changed what the rows hold, or the object cannot be read whole)', $live_id, $current );
			$journal = $outcome['journal'];
			$pair_id = isset( $item['pair_id'] ) ? (int) $item['pair_id'] : null;
			$rest    = null;
			if ( 'create' === $item['op'] ) {
				$made = self::pair_for_new( $run, $item, $live_id, $prepared, 'residual' );
				if ( is_array( $made ) ) {
					$pair_id = (int) $made['id'];
				}
				$rest = self::rest( $item, $live_id );
			} elseif ( null !== $pair_id && array() !== self::made_in( $w, $journal, $live_id ) ) {
				// A widget instance the step made stays (branch 11): the unit a rest.
				$kept = self::unit_rest( $pair_id, $prepared );
				$rest = self::rest( $item, $live_id ) + array( 'made' => self::made_in( $w, $journal, $live_id ) );
				if ( is_wp_error( $kept ) ) {
					$outcome['message'] .= ' ' . self::unit_rest_failed( $kept );
				}
			} elseif ( null !== $pair_id ) {
				AB_MCP_Sync_Pairs::set_state( $pair_id, 'unclear', AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( $pair_id ) ) );
			}
			$message = $outcome['message'] . ( null === $rest ? '' : ' The object is new: it stays as a rest.' );
			$result  = array(
				'message'  => $message,
				'live_ref' => (string) $live_id,
			);
			if ( null !== $rest ) {
				$result['rest'] = $rest;
			}
			$result = self::with_files( $result, $journal, $w );
			$ended  = self::end_step( $run_id, $seq, array( 'state' => 'unclear', 'parts' => $journal, 'result' => $result, 'pair_id' => $pair_id ) );
			return is_wp_error( $ended ) ? $ended : array( 'next' => $message );
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
				$ended = self::end_step( $run_id, $seq, array( 'state' => 'unclear', 'parts' => $journal, 'result' => $result ) );
				return is_wp_error( $ended ) ? $ended : array( 'next' => $result['message'] );
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
				) + ( isset( $prepared['base_extra'] ) && is_array( $prepared['base_extra'] ) ? array( 'base_extra' => $prepared['base_extra'] ) : array() ),
				AB_MCP_Sync_Pairs::snapshot_hash( $before )
			);
			if ( is_wp_error( $rebased ) ) {
				$result = array(
					'message'  => 'The object was written as its target, but its pair did not take the new bases: ' . $rebased->get_error_message(),
					'live_ref' => (string) $live_id,
				);
				$ended = self::end_step( $run_id, $seq, array( 'state' => 'unclear', 'parts' => $journal, 'result' => $result ) );
				return is_wp_error( $ended ) ? $ended : array( 'next' => $result['message'] );
			}
		}
		$result = array(
			'message'  => sprintf( '%1$s %2$s of the staging site written as %3$s %4$s of this site%5$s; read back as its target.%6$s', ucfirst( $item['kind'] ), $item['staging_ref'], $item['kind'], $live_id, $prepared['as_draft'] ? ' with the status draft (the confirmed choice)' : '', ! empty( $prepared['parent_later'] ) ? ' It hangs under no post yet: a step of its own sets its parent once the run has written that post.' : ( ! empty( $item['attach'] ) ? ' Its parent is set.' : '' ) ),
			'live_ref' => (string) $live_id,
			'created'  => 'create' === $item['op'],
			'pair_id'  => $pair_id,
			'status'   => $prepared['status'],
			'title'    => $w::title_of( $prepared ),
		);
		$result = self::with_files( $result, $journal, $w );
		$ended = self::end_step( $run_id, $seq, array( 'state' => 'done', 'parts' => $journal, 'result' => $result, 'pair_id' => $pair_id ) );
		return is_wp_error( $ended ) ? $ended : array( 'next' => $result['message'] . ' Call action=next for the next step.' );
	}

	/**
	 * The end of a step recorded, from begun: its state, journal, result
	 * and pair in one update. Where it cannot be saved (a database error, a
	 * record past AB_MCP_Sync_Run::MAX_PARTS), nothing more is written by
	 * the step and the call says so instead of reporting an end it did not
	 * record: the step stays begun, and the next call finds the run
	 * interrupted, with its two ways (GitHub Codex round 9 on free#137, P2:
	 * the end of a step was saved without its result being checked).
	 *
	 * @param int   $run_id Run.
	 * @param int   $seq    Step.
	 * @param array $set    state, parts, result, pair_id.
	 * @return true|WP_Error
	 */
	private static function end_step( $run_id, $seq, array $set ) {
		$done = AB_MCP_Sync_Run::update_step( $run_id, $seq, $set, 'begun' );
		if ( true !== $done ) {
			return new WP_Error(
				'ab_mcp_sync_run_journal',
				sprintf( 'Step %1$d of run %2$d ran, but its end could not be recorded (%3$s): nothing more was written, and the step stays begun, so the next call finds the run interrupted. Ask for the status of the run (wp_site_push action=status) once the database takes writes again: a person closes it as checked on the page AlphaBridge → Staging, or wp_site_revert action=plan with this run_id goes on from there and leaves every part that is not provably the run\'s own as it is.', (int) $seq, (int) $run_id, is_wp_error( $done ) ? $done->get_error_message() : 'no answer' )
			);
		}
		AB_MCP_Sync_Run::touch( $run_id );
		return true;
	}

	/**
	 * The parts of a boundary written one by one, each read back: the row,
	 * the meta keys, the taxonomies. The first deviation ends the step
	 * (deviated(): the one boundary, the decision of 07.11.2026): a call
	 * refused or failed, a part read back other than the call was to write,
	 * a part another hand changed within the step (foreign). Nothing of the
	 * object is put back then; every part not as the backup is left with its
	 * value before, the object is unclear.
	 *
	 * OWNERSHIP is a mark of its own in the journal (own, own_after), never
	 * taken from the state of a part nor from what a call answered (Codex
	 * round 6 of branch 8, after six findings of one class: a state says
	 * what happened to a part, not whose hand wrote what it holds now).
	 * Right before every call of the step's own the part is read afresh
	 * under the lock (pre; for the parts the row call writes besides the
	 * row, with_row(), the state right before the row call), and read back
	 * after the call whatever it answered (post). The call changed the part
	 * where post differs from pre: then, and only then, the part is the
	 * step's own, own_after the hash read back, own_target what the call was
	 * to write. A part the row call changed is the step's own only where
	 * with_row() names that very hash; any other change with the row is
	 * another hand's (a hook of this site reacting to the row): foreign. A
	 * pre other than the backup is a change by another hand before the call:
	 * foreign as well (N1). The way back of a step that ended done puts a
	 * part back by own and own_after alone (decide()).
	 *
	 * The states describe: read (as its target), failed (the call answered
	 * with an error; the note says whether it changed the part), left (the
	 * object deviated and the part is not as the backup), not_reached
	 * (settled()). decide() reads none of them.
	 *
	 * @param int        $run_id   Run.
	 * @param int        $seq      Step.
	 * @param array      $journal  Journal (parts planned).
	 * @param array      $prepared target_of().
	 * @param array|null $current  current() before (the backup); null for a new object.
	 * @param int|null   $live_id  Post here, null for a new one.
	 * @param string     $w        The write side of the kind (writer()).
	 * @return array{state:string,message:string,journal:array,live_id:int|null}
	 */
	private static function write_parts( $run_id, $seq, array $journal, array $prepared, $current, $live_id, $w ) {
		// A checkpoint of the journal; its result is checked at every use:
		// where it cannot be saved, the boundary stops before any further
		// mutation (unsaved(); GitHub Codex round 9 on free#137, P2: before,
		// the result was dropped and the step wrote on with a journal behind
		// it, which a request ending in the next call left stale).
		$save = static function ( array $journal ) use ( $run_id, $seq ) {
			return AB_MCP_Sync_Run::update_step( $run_id, $seq, array( 'parts' => $journal ), 'begun' );
		};
		$backup = is_array( $current ) ? $current : null;
		// The files of the object as the last reading saw them (an attachment:
		// files_state(), saved with the journal since its files were laid;
		// branch 10, Codex round 1, A3): every call of the step's own is
		// compared against them, as the parts are.
		$files_seen = method_exists( $w, 'files_state' ) ? ( isset( $journal['files_seen'] ) && is_array( $journal['files_seen'] ) ? $journal['files_seen'] : $w::files_state( $journal ) ) : null;
		foreach ( $journal['parts'] as $i => $part ) {
			$name        = (string) $part['part'];
			$target      = (string) $part['target'];
			$through_row = false;
			$error       = null;
			$post_all    = null;
			$pre_call    = null;
			$refused     = false;
			if ( 'row' === $name ) {
				// Every part right before the row call: the row call writes
				// besides the row (with_row()), so every part is read before it
				// and read back after it.
				$pre_all = null === $live_id ? null : $w::current( $live_id );
				if ( is_wp_error( $pre_all ) ) {
					return self::unread( $journal, $i, $pre_all, $live_id, $backup );
				}
				$named = $w::with_row( $prepared, $live_id, $pre_all );
				foreach ( $journal['parts'] as $k => $other ) {
					$journal['parts'][ $k ] = self::seen_before( $other, $pre_all, $backup, $w );
				}
				// With the state read right before it, for the last read and the
				// comparison of the writer (AB_MCP_Sync_Write; Codex round 3 of
				// branch 11, A6).
				$id = $w::write_row( $prepared, $live_id, $pre_all );
				if ( is_wp_error( $id ) ) {
					$error   = $id;
					$refused = AB_MCP_Sync_Write::CHANGED === $id->get_error_code();
				} else {
					$live_id                       = self::live_of( $w, $id );
					$journal['object']['live_ref'] = (string) $live_id;
				}
				// Read back whatever the call answered: an error after the row
				// was written is a written row all the same. Every other part
				// with it: the step's own where the row call was to write it
				// (with_row()) and it reads so, another hand's otherwise.
				$post_all = null === $live_id ? null : $w::current( $live_id );
				$post_all = is_array( $post_all ) ? $post_all : null;
				// A call the writer refused because the object changed right
				// before it (AB_MCP_Sync_Write::CHANGED) wrote nothing: what
				// differs now is another hand's, never the step's own.
				foreach ( $journal['parts'] as $k => $other ) {
					$expected               = $refused ? null : ( $k === $i ? (string) $other['target'] : ( isset( $named[ $other['part'] ] ) ? (string) $named[ $other['part'] ] : null ) );
					$journal['parts'][ $k ] = self::seen_after( $other, $post_all, $expected, ! $refused && $k !== $i, $w );
				}
				// The whole state read before and after the row call, not only
				// the parts of the journal (branch 9, Codex round 1, A1: a key
				// equal on both sides is no part, decision 28, and a hook of this
				// site that changed it with the row went unnoticed up to the hash
				// of the whole object; a request ending in between left the row
				// owned for the way back). What changed besides the parts of the
				// journal, other than what the row call was to write
				// (with_row()), is another hand's: the object deviated, before
				// the journal is saved again.
				if ( is_array( $post_all ) && ! $refused ) {
					$changed_besides = self::changed_besides( $journal, is_array( $pre_all ) ? $pre_all : null, $post_all, $named, $w );
					if ( null !== $changed_besides ) {
						return self::deviated( $journal, $i, sprintf( 'the row call changed the part %s besides it, which the step does not write (a hook of this site reacting to the row)', $changed_besides ), $live_id, $backup );
					}
				}
			} elseif ( $w::through_row( $prepared, $name ) ) {
				// A core key with an API of its own (_wp_page_template) was
				// written with the row, through page_template (decision 17,
				// B7), and read back with it: no call of its own, so what the
				// row call made of it is judged here, never written over. Its
				// removal goes through the meta API.
				$through_row = true;
			} else {
				// A part with a call of its own: a meta key, the terms of a
				// taxonomy (the kind writes it, write_part(); the rights at the
				// object asked right before the call).
				$pre_all = $w::current( $live_id );
				if ( is_wp_error( $pre_all ) ) {
					return self::unread( $journal, $i, $pre_all, $live_id, $backup );
				}
				$journal['parts'][ $i ] = self::seen_before( $journal['parts'][ $i ], $pre_all, $backup, $w );
				$pre_call               = $pre_all;
				// Written ahead of the call (decision 18; Codex round 7, F1):
				// a request that ends inside it leaves the part without a read
				// back, never with the read back of the row before. For the
				// display of the state reached only: a part never read back
				// is never put back (decide()).
				$saved = $save( $journal );
				if ( true !== $saved ) {
					return self::unsaved( $journal, $i, $saved, 'before', $live_id, $backup );
				}
				$done                   = $w::write_part( $live_id, $name, $prepared, is_array( $pre_all ) ? $pre_all : null );
				$error                  = is_wp_error( $done ) ? $done : null;
				$refused                = null !== $error && AB_MCP_Sync_Write::CHANGED === $error->get_error_code();
				$post_all               = $w::current( $live_id );
				$journal['parts'][ $i ] = self::seen_after( $journal['parts'][ $i ], is_array( $post_all ) ? $post_all : null, $refused ? null : $target, false, $w );
			}
			if ( $refused ) {
				// The writer made no call: the object changed right before it,
				// after the last check of the rights (Codex round 3 of branch 11,
				// A6). Every part that differs from the read before is another
				// hand's; the step deviates, nothing of it goes back by itself
				// (decision A).
				return self::refused( $journal, $i, $error, $pre_all, $post_all, $live_id, $backup, $w );
			}
			// Whatever this call changed besides its own part (a hook of this
			// site reacting to the call): a part the step wrote before or has
			// still to write, or one the plan does not name at all (a key a hook
			// adds and takes away again), is another hand's: the object
			// deviated, even where it reads as before again later (Codex
			// rounds 13 to 15; the mirror of the way back, put_back()). The row
			// call reads every part with the row already (with_row()).
			if ( 'row' !== $name && is_array( $pre_call ) && is_array( $post_all ) ) {
				foreach ( self::part_names( $pre_call, $post_all ) as $other_name ) {
					if ( $other_name === $name || ! self::changed( $w::part_hash( $pre_call, $other_name ), $w::part_hash( $post_all, $other_name ) ) ) {
						continue;
					}
					foreach ( $journal['parts'] as $k => $other ) {
						if ( (string) $other['part'] === $other_name ) {
							$journal['parts'][ $k ]['foreign'] = true;
							$journal['parts'][ $k ]['note']    = self::noted( $other, sprintf( 'Changed as the step wrote the part %s (a hook of this site reacting to the call): not provably the step\'s own.', $name ) );
						}
					}
					return self::deviated( $journal, $i, sprintf( 'the call of the part %1$s changed the part %2$s besides it (a hook of this site reacting to the call)', $name, $other_name ), $live_id, $backup );
				}
			}
			// The files of the object (an attachment; Codex round 1 of branch
			// 10, A3): a call that changed one of them (a hook of this site,
			// edit_attachment, an optimizer) changed the object besides its
			// parts, as a part changed besides: another hand's, the object
			// deviated, nothing of it goes back by itself (decision A). The
			// reading is saved with the journal, so the way back compares
			// against it even after a request that ended here.
			if ( is_array( $files_seen ) && ! $through_row ) {
				$files_now = $w::files_state( $journal );
				$moved     = self::changed_file( $files_seen, is_array( $files_now ) ? $files_now : array() );
				if ( null !== $moved ) {
					return self::deviated( $journal, $i, sprintf( 'the call of the part %1$s changed the file %2$s of the attachment besides it (a hook of this site reacting to the call)', $name, $moved ), $live_id, $backup );
				}
				$files_seen            = $files_now;
				$journal['files_seen'] = $files_now;
			}
			$one     = $journal['parts'][ $i ];
			$post    = $one['post'];
			$changed = self::changed( $one['pre'], $post );
			if ( null !== $error ) {
				// The call answered with an error: a deviation, whether it
				// changed the part before (a key of several rows: removed, the
				// new ones refused) or not.
				$journal['parts'][ $i ]['state'] = 'failed';
				$journal['parts'][ $i ]['note']  = self::noted( $one, sprintf( 'The call failed (%1$s)%2$s.', $error->get_error_message(), $changed ? ' after it changed the part' : ' and changed nothing of the part' ) );
				return self::deviated( $journal, $i, sprintf( 'the call of the part %1$s failed (%2$s)%3$s', $name, $error->get_error_message(), $changed ? ' after it changed the part' : '' ), $live_id, $backup );
			}
			// A part not read back after the call: whether it holds what the
			// call wrote is unknown, a deviation (Codex round 11).
			if ( ! empty( $one['unread'] ) ) {
				$journal['parts'][ $i ]['note'] = self::noted( $one, 'Could not be read back after the call.' );
				return self::deviated( $journal, $i, sprintf( 'the part %s could not be read back after the call', $name ), $live_id, $backup );
			}
			// Another hand changed a part within the step (seen_before(),
			// seen_after(): with the row, or before the step's call): a
			// deviation, whichever part, even where the step then wrote it as
			// its target.
			foreach ( $journal['parts'] as $k => $other ) {
				if ( ! empty( $other['foreign'] ) ) {
					$journal['parts'][ $k ]['note'] = self::noted( $other, 'Not provably the step\'s own.' );
					$as_target = isset( $other['post'] ) && null !== $other['post'] && hash_equals( (string) $other['target'], (string) $other['post'] );
					return self::deviated( $journal, $k, sprintf( 'another hand changed the part %1$s within the step (a hook of this site%2$s)%3$s', (string) $other['part'], 'not_refusable' === $other['way'] ? ', or a writer on a way the lock does not refuse' : '', $as_target ? ', though the part reads as its target now' : '' ), $live_id, $backup );
				}
			}
			if ( null !== $post && hash_equals( $target, $post ) ) {
				$journal['parts'][ $i ]['state'] = 'read';
				if ( ! $through_row && isset( $one['note'] ) && '' !== (string) $one['note'] ) {
					// The row call wrote it (the default category) before the
					// step wrote it as its target: said in the journal.
					$journal['parts'][ $i ]['note'] = (string) $one['note'] . ' Written as its target by the step after that.';
				}
				$saved = $save( $journal );
				if ( true !== $saved ) {
					return self::unsaved( $journal, $i, $saved, 'after', $live_id, $backup );
				}
				AB_MCP_Change_Lock::touch();
				continue;
			}
			if ( empty( $one['own'] ) ) {
				// The call answered well, yet the part holds what it held before
				// the call, other than its target: nothing was written.
				$journal['parts'][ $i ]['state'] = 'failed';
				$journal['parts'][ $i ]['note']  = self::noted( $one, $through_row ? 'The core did not write it with the row: it reads as before the row call, other than its target.' : 'The call changed nothing of it: it reads as before the call, other than its target.' );
				return self::deviated( $journal, $i, sprintf( 'the part %s was not written: it reads as before the call, other than its target', $name ), $live_id, $backup );
			}
			// Written, deviating (decision 17): read back other than the call
			// was to write (a filter of this site changed it as it was written).
			$journal['parts'][ $i ]['note'] = self::noted( $one, 'Written, deviating: read back other than its target.' );
			return self::deviated( $journal, $i, sprintf( 'the part %s was written but reads back other than its target (a filter of this site changed it as it was written)', $name ), $live_id, $backup );
		}
		return self::outcome( 'done', '', $journal, $live_id );
	}

	/**
	 * A call the writer did not make (AB_MCP_Sync_Write::CHANGED; Codex
	 * round 3 of branch 11, A6): its last read, after the rights, differed
	 * from the state the engine read right before the call. Nothing was
	 * written; every part that differs between that state and the read back
	 * is another hand's (foreign, never the step's own), and the step
	 * deviates with the first of them named.
	 *
	 * @param array      $journal Journal.
	 * @param int        $i       The part of the call.
	 * @param WP_Error   $error   The refusal of the writer.
	 * @param array|null $pre     The state the engine read right before the call.
	 * @param array|null $post    The state read back.
	 * @param int|null   $live_id The object here.
	 * @param array|null $backup  current() of the backup.
	 * @param string     $w       The write side of the kind.
	 * @return array{state:string,message:string,journal:array,live_id:int|null}
	 */
	private static function refused( array $journal, $i, WP_Error $error, $pre, $post, $live_id, $backup, $w ) {
		$name  = (string) $journal['parts'][ $i ]['part'];
		$first = null;
		if ( is_array( $pre ) && is_array( $post ) ) {
			foreach ( self::part_names( $pre, $post ) as $other_name ) {
				if ( ! self::changed( $w::part_hash( $pre, $other_name ), $w::part_hash( $post, $other_name ) ) ) {
					continue;
				}
				$first = null === $first ? $other_name : $first;
				foreach ( $journal['parts'] as $k => $other ) {
					if ( (string) $other['part'] === $other_name ) {
						$journal['parts'][ $k ]['foreign'] = true;
						$journal['parts'][ $k ]['note']    = self::noted( $other, sprintf( 'Changed right before the step\'s call of the part %s, after the last check of the rights (a hook of this site): not the step\'s own.', $name ) );
					}
				}
			}
		}
		$journal['parts'][ $i ]['state'] = 'failed';
		$journal['parts'][ $i ]['note']  = self::noted( $journal['parts'][ $i ], sprintf( 'Not written (%s).', $error->get_error_message() ) );
		$changed = null === $first ? 'the object' : 'the part ' . $first;
		$out     = self::deviated( $journal, $i, sprintf( 'the part %1$s was not written: %2$s changed right before the call, after the last check of the rights (a hook of this site), not by the step', $name, $changed ), $live_id, $backup );
		if ( 'failed' === $out['state'] ) {
			// No part of the step differs from its backup: the step wrote
			// nothing, and what changed is another hand's and stays.
			$out['message'] = sprintf( 'Nothing was written: %1$s changed right before the call of the part %2$s, after the last check of the rights (a hook of this site), not by the step. That change stays as it is; every part of the step is as before it. Check the object in wp-admin, then make a new report and plan again.', $changed, $name );
		}
		return $out;
	}

	/**
	 * The one answer to a deviation within the boundary (the decision of
	 * 07.11.2026, «A mit altem Wert»; boundary C of GRENZEN-LAUF.md): nothing
	 * of the object is put back by itself, now or by the way back. The
	 * object carries the mark (object.deviated: the part and why), every
	 * part the mark (deviated, which decide() answers with left), and every
	 * part that is not as the backup is left as it is, with the value it
	 * held before (value_before, from the backup: AB_MCP_Sync_Post_Writer::
	 * before_value(), never a credential). A part as the backup keeps its
	 * state (planned, which settled() names not_reached; failed; read where
	 * the target is the backup). The step is unclear where a part is left,
	 * failed where nothing of the object differs from the backup (the row
	 * call refused before it wrote). The caller saves the journal.
	 *
	 * Why one boundary: every round of Codex since round 2 of this branch
	 * found a new way in which the turn within the boundary or the way back
	 * took a part back that was not provably the step's own (which parts a
	 * step wrote, and whose hand wrote what a part holds now, after a hook, a
	 * refusal or a deviating read back). A person with the value before in
	 * the answer decides it; the backup stays (decision 20).
	 *
	 * @param array      $journal Journal.
	 * @param int        $i       The part that deviated.
	 * @param string     $why     Why, as a clause (no capital, no full stop).
	 * @param int|null   $live_id Post, null where none was made.
	 * @param array|null $backup  current() of the backup; null for a new object.
	 * @return array outcome().
	 */
	private static function deviated( array $journal, $i, $why, $live_id, $backup ) {
		$name  = (string) $journal['parts'][ $i ]['part'];
		$w     = self::writer_of( $journal );
		$state = null === $live_id ? null : $w::current( $live_id );
		$state = is_array( $state ) ? $state : null;
		$journal['object']['deviated'] = array(
			'part' => $name,
			'why'  => (string) $why,
		);
		$left = 0;
		foreach ( $journal['parts'] as $k => $part ) {
			$journal['parts'][ $k ]['deviated'] = true;
			$now = null === $state ? null : $w::part_hash( $state, (string) $part['part'] );
			$was = $w::part_hash( is_array( $backup ) ? $backup : null, (string) $part['part'] );
			if ( ( null === $now && null === $was ) || ( null !== $now && null !== $was && hash_equals( $was, $now ) ) ) {
				continue;
			}
			$journal['parts'][ $k ]['state']        = 'left';
			$journal['parts'][ $k ]['value_before'] = $w::before_value( (string) $part['part'], $backup, $state, $live_id );
			$journal['parts'][ $k ]['value_rules']  = $w::VALUE_RULES;
			$journal['parts'][ $k ]['note']         = self::noted( $journal['parts'][ $k ], 'Left as it is: the object deviated within the step, so nothing of it is put back by itself; check it by hand, its value before stands in value_before.' );
			++$left;
		}
		if ( 0 === $left ) {
			// Nothing differs from the backup: the step is failed, the object
			// keeps its mark all the same (a part owned before stays never put
			// back, Codex round 9); the preview of a way back says nothing is
			// to do for it (AB_MCP_Sync_Revert::plan()).
			return self::outcome( 'failed', sprintf( 'Nothing was written: %s. The object is as before the step.', $why ), $journal, $live_id );
		}
		return self::outcome( 'unclear', sprintf( 'The object deviated within the step: %1$s. Nothing of it is put back by itself (the one boundary, GRENZEN-LAUF.md): %2$d part(s) stay as they are (left, each with its value before in the journal), and the object is unclear. Check it in wp-admin and align the pair (wp_site_pairs).', $why, $left ), $journal, $live_id );
	}

	/**
	 * A part read right before a call of the step's own: pre, the read back
	 * cleared until the call is read back (post null). A pre other than the
	 * backup, where the part does not hold what the step owns, is a change by
	 * another hand before the call: marked foreign with a note, never
	 * ownership (N1). For the parts the row call writes besides the row, pre
	 * is the state right before the row call. The journal is saved right
	 * before the call with post null (write_parts()): a request that ends
	 * inside a call leaves the part so, and the way back never puts a part
	 * back that was not read back (decide()).
	 *
	 * @param array      $part   Part of the journal.
	 * @param array|null $state  current() right before the call; null for no post.
	 * @param array|null $backup current() of the backup; null for a new object.
	 * @param string     $w      The write side of the kind (writer()).
	 * @return array The part.
	 */
	private static function seen_before( array $part, $state, $backup, $w ) {
		$name         = (string) $part['part'];
		$pre          = $w::part_hash( is_array( $state ) ? $state : null, $name );
		$was          = $w::part_hash( $backup, $name );
		$part['pre']  = $pre;
		$part['post'] = null;
		$holds_own    = ! empty( $part['own'] ) && isset( $part['own_after'] ) && null !== $part['own_after'] && null !== $pre && hash_equals( (string) $part['own_after'], $pre );
		if ( null !== $pre && null !== $was && ! hash_equals( $was, $pre ) && ! $holds_own && empty( $part['foreign'] ) ) {
			$part['foreign'] = true;
			$part['note']    = 'Changed since the backup of the step by another hand (a hook of this site, or a writer on a way the lock does not refuse) before the step\'s call: not the step\'s own, never put back.';
		}
		return $part;
	}

	/**
	 * A part read back after a call of the step's own: post, and after (the
	 * last hash read back, a description). The call changed the part where
	 * post differs from pre: then the part is the step's own (own, own_after
	 * the hash read back, own_target what the call was to write). For a part
	 * the row call writes besides the row ($by_row), only where it reads
	 * what with_row() names; a change with the row that reads otherwise is
	 * another hand's (a hook of this site), marked foreign, never ownership.
	 *
	 * @param array       $part     Part of the journal, after seen_before().
	 * @param array|null  $state    current() read back after the call; null where there is no post or it could not be read.
	 * @param string|null $expected What the call was to write for this part: the target for the part's own call, what with_row() names for the row call, null where the row call was not to write it.
	 * @param bool        $by_row   Whether the call was the row call and this part another one.
	 * @param string      $w        The write side of the kind (writer()).
	 * @return array The part.
	 */
	private static function seen_after( array $part, $state, $expected, $by_row, $w ) {
		$name = (string) $part['part'];
		if ( ! is_array( $state ) ) {
			// Not read back (the read failed, or the post is gone): never the
			// hash of an empty set, never ownership; the step deviates
			// (write_parts(); Codex round 11).
			$part['post']   = null;
			$part['after']  = null;
			$part['unread'] = true;
			return $part;
		}
		$post          = $w::part_hash( $state, $name );
		$part['post']  = $post;
		$part['after'] = $post;
		if ( ! self::changed( isset( $part['pre'] ) ? $part['pre'] : null, $post ) ) {
			return $part;
		}
		if ( null !== $expected && ( ! $by_row || hash_equals( (string) $expected, (string) $post ) ) ) {
			$part['own']        = true;
			$part['own_after']  = $post;
			$part['own_target'] = (string) $expected;
			if ( $by_row ) {
				$part['state'] = hash_equals( (string) $part['target'], (string) $post ) ? 'read' : 'written';
				$part['note']  = 'Written with the row by the core (wp_insert_post()).';
			}
			return $part;
		}
		if ( $by_row ) {
			$part['foreign'] = true;
			$part['note']    = null !== $expected
				? 'Written with the row by the core, but read back other than what the row was to write: not provably the step\'s own, not put back.'
				: 'Changed since the backup of the step by another hand (a hook of this site reacting to the row, or a writer on a way the lock does not refuse), not by the step: not the step\'s own, not put back.';
		}
		return $part;
	}

	/**
	 * Whether a call changed a part: it reads back (post), and other than
	 * before the call (pre; nothing there, for the row of a new post).
	 *
	 * @param string|null $pre  The hash before the call.
	 * @param string|null $post The hash read back.
	 * @return bool
	 */
	private static function changed( $pre, $post ) {
		return null !== $post && ( null === $pre || ! hash_equals( (string) $pre, (string) $post ) );
	}

	/**
	 * A note that keeps what the journal said of the part before (a change
	 * by another hand, what the row wrote).
	 *
	 * @param array  $part Part.
	 * @param string $text The note to add.
	 * @return string
	 */
	private static function noted( array $part, $text ) {
		$before = isset( $part['note'] ) ? trim( (string) $part['note'] ) : '';
		return '' === $before ? (string) $text : $before . ' ' . (string) $text;
	}

	/**
	 * A checkpoint of the journal that could not be saved (a database error,
	 * a journal past AB_MCP_Sync_Run::MAX_PARTS): the boundary stops before
	 * any further mutation (GitHub Codex round 9 on free#137, P2), and the
	 * step ends as deviated (deviated(), the decision of 07.11.2026): the row
	 * call is always made first, so what the step wrote before stays, left
	 * with its value before, the step unclear; failed where nothing of the
	 * object differs from the backup. The end is saved as any other
	 * (end_step()); where that fails as well, the step stays begun and the
	 * run is interrupted.
	 *
	 * @param array      $journal Journal.
	 * @param int        $i       Part whose call was next (before) or was made (after).
	 * @param mixed      $error   What update_step() answered.
	 * @param string     $when    before or after the call.
	 * @param int|null   $live_id Post.
	 * @param array|null $backup  current() of the backup; null for a new object.
	 * @return array outcome().
	 */
	private static function unsaved( array $journal, $i, $error, $when, $live_id, $backup ) {
		$name   = (string) $journal['parts'][ $i ]['part'];
		$reason = is_wp_error( $error ) ? $error->get_error_message() : 'no answer';
		$journal['parts'][ $i ]['note'] = self::noted( $journal['parts'][ $i ], 'before' === $when ? sprintf( 'Not written: the journal of the step could not be saved before its call (%s), so the call was not made.', $reason ) : sprintf( 'The journal of the step could not be saved after its call (%s); the step stopped there.', $reason ) );
		return self::deviated( $journal, $i, sprintf( 'the journal of the step could not be saved %1$s the call of the part %2$s (%3$s), so the step wrote nothing more: a request that ended in a later call could not be told from the journal', 'before' === $when ? 'before' : 'after', $name, $reason ), $live_id, $backup );
	}

	/**
	 * A part that could not be read right before the step's call: nothing is
	 * written for it (fail closed), the part is failed and the step ends as
	 * deviated (the parts written before it stay, left).
	 *
	 * @param array      $journal Journal.
	 * @param int        $i       Part.
	 * @param WP_Error   $error   The read that failed.
	 * @param int|null   $live_id Post.
	 * @param array|null $backup  current() of the backup; null for a new object.
	 * @return array outcome().
	 */
	private static function unread( array $journal, $i, WP_Error $error, $live_id, $backup ) {
		$name                            = (string) $journal['parts'][ $i ]['part'];
		$journal['parts'][ $i ]['state'] = 'failed';
		$journal['parts'][ $i ]['note']  = 'Not written: the part could not be read before the call (' . $error->get_error_message() . ').';
		return self::deviated( $journal, $i, sprintf( 'the part %1$s could not be read before its call (%2$s)', $name, $error->get_error_message() ), $live_id, $backup );
	}

	/**
	 * Whether a part goes back, from the journal and the state read now; the
	 * one place of the rule (decisions 17, 18 and 23; Codex round 6 of
	 * branch 8; the decision of 07.11.2026), pure: no call of WordPress,
	 * nothing of the state of the part. The way back of the run asks it, each
	 * part right before its turn, on a hash read then.
	 *
	 * 1. $now null (the part could not be read): left.
	 * 2. $now as the backup: unchanged, nothing to do.
	 * 3. The object deviated within its step (deviated, the mark deviated()
	 *    sets on every part; or the journal is of an earlier build that did
	 *    not record it): left, whatever the part holds. Nothing of such an
	 *    object goes back by itself (boundary C of GRENZEN-LAUF.md).
	 * 4. The step owns the part (own, own_after: its own call changed it and
	 *    read it back): $now as own_after, put back; otherwise the part was
	 *    changed since the step wrote it: left.
	 * 5. The step does not own it (no call of its own changed it; a part never
	 *    read back, which an interrupted step leaves: a hash equal to the
	 *    target proves no ownership; not_reached): left, with why.
	 *
	 * @param array       $part   Part of the journal (part, own, own_after, deviated, post, foreign, state, note).
	 * @param string|null $now    The hash of the part read now; null where it could not be read.
	 * @param string      $backup The hash of the part in the backup.
	 * @return array{state:string,why:string,hash?:string} state unchanged, put_back (hash: what the part has to hold as it is put back) or left (why).
	 */
	public static function decide( array $part, $now, $backup ) {
		$name = isset( $part['part'] ) ? (string) $part['part'] : '';
		$own  = ! empty( $part['own'] ) && isset( $part['own_after'] ) && null !== $part['own_after'];
		if ( null === $now ) {
			return array(
				'state' => 'left',
				'why'   => 'Could not be read, so whether it changed since the backup is unknown: left as it is.',
			);
		}
		if ( hash_equals( (string) $backup, (string) $now ) ) {
			return array(
				'state' => 'unchanged',
				'why'   => $own ? '' : 'As before the run.',
			);
		}
		if ( ! empty( $part['deviated'] ) ) {
			return array(
				'state' => 'left',
				'why'   => 'earlier_build' === $part['deviated']
					? sprintf( 'The journal of this step is of an earlier build and does not say whether the object deviated within the step, so nothing of it goes back by itself: the part %s is left as it is; check it by hand, its value before stands beside it (value_before).', $name )
					: ( 'foreign_mark' === $part['deviated']
						? sprintf( 'The journal marks a part of this object as changed by another hand within its step, so nothing of it goes back by itself: the part %s is left as it is; check it by hand, its value before stands beside it (value_before).', $name )
						: sprintf( 'The object deviated within its step, so nothing of it goes back by itself: the part %s is left as it is; check it by hand, its value before stands beside it (value_before).', $name ) ),
			);
		}
		if ( $own ) {
			if ( hash_equals( (string) $part['own_after'], (string) $now ) ) {
				return array(
					'state' => 'put_back',
					'why'   => '',
					'hash'  => (string) $part['own_after'],
				);
			}
			return array(
				'state' => 'left',
				'why'   => sprintf( 'The part %s was changed since the step wrote it, so it is left as it is.', $name ),
			);
		}
		return array(
			'state' => 'left',
			'why'   => 'Not the step\'s own (' . self::not_own_why( $part ) . '), so it is left as it is.',
		);
	}

	/**
	 * Why a part is not the step's own, for the journal and the answer: a
	 * wording from the marks of the part (its state only names the moment).
	 *
	 * @param array $part Part of the journal.
	 * @return string
	 */
	private static function not_own_why( array $part ) {
		$note  = isset( $part['note'] ) && '' !== (string) $part['note'] ? ': ' . (string) $part['note'] : '';
		$state = isset( $part['state'] ) ? (string) $part['state'] : '';
		if ( ! empty( $part['foreign'] ) ) {
			return 'changed by another hand, not by the step' . $note;
		}
		if ( 'not_reached' === $state ) {
			return 'the step ended before it reached this part, so it never wrote it';
		}
		if ( ! isset( $part['post'] ) || null === $part['post'] ) {
			return 'the step never read it back (its request ended in the call), so whether the step wrote what it holds is unknown';
		}
		return 'no call of the step changed it, so what it holds now is another hand\'s' . $note;
	}

	/**
	 * The parts of a step put back from its backup on the way back of the
	 * run: the one place of the way back (decisions 17 and 23; GitHub Codex
	 * round 1 of branch 8; Codex round 6 after N1 to N4).
	 *
	 * 1. The row goes back first, then the meta, then the terms:
	 *    wp_update_post() has side effects on the terms of a post (the
	 *    default category of a post without one), which the terms put back
	 *    after it undo where the terms are still the step's own.
	 * 2. Every part is decided right before its turn, on the hash read then
	 *    (decide(): as the backup, unchanged; as own_after, put back; else
	 *    left), never on a hash read before another part went back (N3).
	 * 3. What putting a part back changed besides it, whatever it is (a side
	 *    effect of the core such as the default category the row gives a post
	 *    without one, a hook of this site), stops the way back for the
	 *    object: no further part goes back by itself, each is left unless it
	 *    is as the backup (N3; the decision of 07.11.2026 after Codex round 7,
	 *    F2: before, the default category the row gave back became the step's
	 *    own and took terms back that another hand had changed; Codex rounds
	 *    12 and 16).
	 * 4. Then every part counted as back is read once more against the
	 *    backup: only where every one reads as the backup is the object back;
	 *    a part that does not is unclear, with the reason.
	 * 5. Right before a part goes back, the rights at the object as the
	 *    forward write asks them (AB_MCP_Sync_Post_Writer::restore_right();
	 *    decision 28): edit_post for the row, edit_, add_ or delete_post_meta
	 *    for a key, the rights of the taxonomy and assign_term for the terms.
	 *    A part the account may not write is left with its value before; the
	 *    run goes on (GitHub Codex round 2 of branch 8, P2: before, the way
	 *    back asked the owner of the run only).
	 *
	 * A part left carries the value it held before (value_before, from the
	 * backup; AB_MCP_Sync_Post_Writer::before_value()).
	 *
	 * @param array $journal Journal of the step that wrote.
	 * @param int   $live_id Post.
	 * @param array $backup  The backed-up state (current() before the step).
	 * @return array{all_back:bool,parts:array<int,array{state:string,note?:string,value_before?:string}>} parts: the outcome by the index of the part.
	 */
	/**
	 * The order in which the parts of a kind go back (put_back()): the row,
	 * the meta, the terms; a kind whose parts have other names says its own
	 * (PART_ORDER, branch 11).
	 *
	 * @param string|null $w Writer class.
	 * @return string[] Prefixes of the names of the parts.
	 */
	public static function part_order( $w ) {
		return null !== $w && defined( $w . '::PART_ORDER' ) ? $w::PART_ORDER : array( 'row', 'meta:', 'terms:' );
	}

	/**
	 * The orders of the parts of these kinds, by kind: what the tokens of a
	 * plan and of a way back cover with the rest of the order of a run
	 * (Codex round 1 of branch 11, C2).
	 *
	 * @param string[] $kinds Kinds.
	 * @return array<string,string[]>
	 */
	public static function part_orders( array $kinds ) {
		$out = array();
		foreach ( array_unique( array_map( 'strval', $kinds ) ) as $kind ) {
			$out[ $kind ] = self::part_order( self::writer( $kind ) );
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	private static function put_back( array $journal, $live_id, array $backup ) {
		$parts = $journal['parts'];
		$out   = array();
		$w     = self::writer_of( $journal );
		// The row first, then the meta, then the terms; a kind whose parts
		// have other names says their order (PART_ORDER; branch 11).
		$order = array();
		foreach ( self::part_order( $w ) as $prefix ) {
			foreach ( $parts as $i => $part ) {
				if ( is_string( $part['part'] ) && 0 === strpos( $part['part'], $prefix ) ) {
					$order[] = $i;
				}
			}
		}
		// A part of no known name gets its outcome as well: left, never put
		// back (every part of the journal has one; GitHub Codex round 8 on
		// free#137, P2: a name read as the marker of its bytes was left out,
		// and the way back of the step ended on a TypeError).
		foreach ( $parts as $i => $part ) {
			if ( ! in_array( $i, $order, true ) ) {
				$out[ $i ] = array(
					'state' => 'left',
					'note'  => 'A part of a name this version does not know: left as it is, check the object by hand.',
				);
			}
		}
		$w       = self::writer_of( $journal );
		$seen    = $w::current( $live_id );
		$seen    = is_array( $seen ) ? $seen : null;
		$stopped = null;
		// The files of the object (an attachment; Codex round 1 of branch 10,
		// A3): one changed since the last reading of the step (a hook as it
		// wrote, another hand since) stops the way back for the object before
		// any part goes back; one a put back changes stops it from there.
		$files_seen = null;
		if ( method_exists( $w, 'files_state' ) && isset( $journal['files_seen'] ) && is_array( $journal['files_seen'] ) ) {
			$files_seen = $w::files_state( $journal );
			$files_seen = is_array( $files_seen ) ? $files_seen : array();
			$moved      = self::changed_file( $journal['files_seen'], $files_seen );
			if ( null !== $moved ) {
				$stopped = sprintf( 'the file %s of the attachment changed since the step read it last (a hook of this site, or another hand since)', $moved );
			}
		}
		// The parts that go back only together, all or none (an attachment:
		// the three keys that name its files; Codex round 2 of branch 10, A4),
		// decided once, as the first of them comes up.
		$groups   = method_exists( $w, 'together' ) ? $w::together() : array();
		$group_of = array();
		foreach ( $groups as $g => $members ) {
			foreach ( $members as $member ) {
				$group_of[ (string) $member ] = $g;
			}
		}
		$group_why = array();
		// Every member of such a group as the step left it, also one the
		// journal does not hold (equal to its target, so no part: as the
		// backup), against the object now (Codex round 3 of branch 10, A4, at
		// the place of decision A): a member that changed since is another
		// hand's change of the object, so nothing of it goes back by itself.
		// Then restore_files() checks the very list the values after the way
		// back name.
		foreach ( $groups as $members ) {
			foreach ( $members as $member ) {
				if ( null !== $stopped ) {
					break 2;
				}
				$left_at = $w::part_hash( $backup, (string) $member );
				foreach ( $parts as $part ) {
					if ( is_string( $part['part'] ) && $part['part'] === (string) $member ) {
						$left_at = isset( $part['post'] ) && null !== $part['post'] ? (string) $part['post'] : $part['before'];
					}
				}
				$now = null === $seen ? null : $w::part_hash( $seen, (string) $member );
				// Both ways: a key that came, went or changed (changed() sees no
				// key that went).
				if ( ( null === $left_at ) !== ( null === $now ) || ( null !== $now && ! hash_equals( (string) $left_at, (string) $now ) ) ) {
					$stopped = sprintf( 'the part %s, which names files of the attachment, changed since the step left it (another hand changed the object)', (string) $member );
				}
			}
		}
		foreach ( $order as $i ) {
			$name    = (string) $parts[ $i ]['part'];
			$now     = null === $seen ? null : $w::part_hash( $seen, $name );
			$decided = self::decide( $parts[ $i ], $now, (string) $w::part_hash( $backup, $name ) );
			if ( null !== $stopped ) {
				// Putting a part back changed something besides it (a side
				// effect of the core, a hook of this site): the object deviated
				// on the way back, nothing more of it goes back by itself (Codex
				// round 16); a part decide() leaves on its own names the halt
				// besides its reason, so the answer says what changed the object.
				if ( 'put_back' === $decided['state'] ) {
					$decided = array(
						'state' => 'left',
						'why'   => sprintf( 'The way back stopped for this object: %s; the part %s is left as it is, check it by hand.', $stopped, $name ),
					);
				} elseif ( 'left' === $decided['state'] ) {
					$decided['why'] .= sprintf( ' The way back stopped for this object: %s.', $stopped );
				}
			}
			if ( 'put_back' === $decided['state'] && isset( $group_of[ $name ] ) ) {
				$g = $group_of[ $name ];
				if ( ! array_key_exists( $g, $group_why ) ) {
					$group_why[ $g ] = self::group_refusal( $parts, $groups[ $g ], $seen, $backup, $live_id, $w );
				}
				if ( null !== $group_why[ $g ] ) {
					$decided = array(
						'state' => 'left',
						'why'   => sprintf( 'Not put back: the parts %1$s go back only together, and %2$s; so the part %3$s is left as it is, its value before stands beside it (value_before). Set them by hand in wp-admin.', implode( ', ', $groups[ $g ] ), $group_why[ $g ], $name ),
					);
				}
			}
			if ( 'put_back' === $decided['state'] ) {
				// The rights at the object, as the forward write asks them
				// (decision 28; GitHub Codex round 2 of branch 8, P2): the
				// owner of the run may have lost the right to this post, key or
				// taxonomy since the run wrote. Without it the part is left with
				// its value before, fail closed; the run goes on.
				$may = $w::restore_right( $name, $live_id, $backup, is_array( $seen ) ? $seen : array() );
				if ( true !== $may ) {
					$decided = array(
						'state' => 'left',
						'why'   => sprintf( 'Not put back: this account may not %1$s on this site, so the part %2$s is left as it is; its value before stands beside it (value_before). Set it by hand in wp-admin with an account that may.', $may, $name ),
					);
				}
			}
			if ( 'put_back' === $decided['state'] && is_array( $files_seen ) ) {
				// The files right before the part goes back (Codex round 2 of
				// branch 10, A3): a file that changed since the last reading
				// (a filter that ran as the way back asked a right or a type, or
				// another hand) stops the way back for the object before any
				// further part goes back (decision A).
				$files_now = $w::files_state( $journal );
				$files_now = is_array( $files_now ) ? $files_now : array();
				$moved     = self::changed_file( $files_seen, $files_now );
				if ( null !== $moved ) {
					$stopped = sprintf( 'the file %1$s of the attachment changed before the part %2$s went back (a hook of this site as the way back asked a right or a type, or another hand)', $moved, $name );
					$decided = array(
						'state' => 'left',
						'why'   => sprintf( 'The way back stopped for this object: %s; the part %s is left as it is, check it by hand.', $stopped, $name ),
					);
				}
			}
			if ( 'put_back' !== $decided['state'] ) {
				$one = '' === $decided['why'] ? array( 'state' => $decided['state'] ) : array(
					'state' => $decided['state'],
					'note'  => $decided['why'],
				);
			} else {
				$one = self::restore_part( $parts[ $i ], $live_id, $backup, $decided['hash'], $w );
				AB_MCP_Change_Lock::touch();
				$again = $w::current( $live_id );
				$again = is_array( $again ) ? $again : null;
				// Whatever putting this part back changed besides it, a part of
				// the plan or not (a side effect of the core such as the default
				// category of a post without one, N3, F2; a hook that sets the
				// tags as the category goes back, Codex round 12; a key a hook
				// adds as the part goes back, Codex round 16), stops the way
				// back for the object: no further part goes back by itself, a
				// part not as the backup is left with its value before. (The
				// mark of another hand on the changed part, Codex round 12,
				// decided nothing the halt does not decide since Codex round 16
				// and is gone; GitHub Codex round 2 of branch 8, CI.)
				if ( null === $stopped && is_array( $seen ) && is_array( $again ) ) {
					foreach ( self::part_names( $seen, $again ) as $other_name ) {
						if ( $other_name !== $name && self::changed( $w::part_hash( $seen, $other_name ), $w::part_hash( $again, $other_name ) ) ) {
							$stopped = sprintf( 'putting the part %1$s back changed the part %2$s besides it (a side effect of the core or a hook of this site)', $name, $other_name );
							break;
						}
					}
				}
				if ( is_array( $files_seen ) ) {
					$files_again = $w::files_state( $journal );
					$files_again = is_array( $files_again ) ? $files_again : array();
					$moved       = self::changed_file( $files_seen, $files_again );
					if ( null === $stopped && null !== $moved ) {
						$stopped = sprintf( 'putting the part %1$s back changed the file %2$s of the attachment (a side effect of the core or a hook of this site)', $name, $moved );
					}
					$files_seen = $files_again;
				}
				$seen = $again;
			}
			if ( 'left' === $one['state'] ) {
				$one['value_before'] = $w::before_value( $name, $backup, $seen, $live_id );
				$one['value_rules']  = $w::VALUE_RULES;
			}
			$out[ $i ] = $one;
		}
		// The check after: every part counted as back reads as the backup now.
		$again    = $w::current( $live_id );
		$all_back = true;
		foreach ( $parts as $i => $part ) {
			$name = is_string( $part['part'] ) ? $part['part'] : '';
			if ( in_array( $out[ $i ]['state'], array( 'restored', 'unchanged' ), true ) ) {
				$now = is_array( $again ) ? $w::part_hash( $again, $name ) : null;
				if ( null === $now || ! hash_equals( (string) $w::part_hash( $backup, $name ), $now ) ) {
					$out[ $i ] = array(
						'state' => 'unclear',
						'note'  => sprintf( 'The part %1$s reads other than the backup after the parts went back%2$s: a side effect of the core or a hook of this site changed it after it was %3$s. Check the object in wp-admin.', $name, null === $now ? ' (it could not be read)' : '', 'restored' === $out[ $i ]['state'] ? 'put back' : 'read as the backup' ),
					);
				}
			}
			if ( ! in_array( $out[ $i ]['state'], array( 'restored', 'unchanged' ), true ) ) {
				$all_back = false;
			}
		}
		// A way back that stopped changed something besides the parts (Codex
		// round 16): the object is not back as a whole, whatever the parts say;
		// nor where a file of it changed after the last part went back.
		if ( null !== $stopped || ( is_array( $files_seen ) && null !== self::changed_file( $files_seen, (array) $w::files_state( $journal ) ) ) ) {
			$all_back = false;
		}
		return array(
			'all_back' => $all_back,
			'parts'    => $out,
		);
	}

	/**
	 * Put one part back from the backup of the step: the row through
	 * wp_update_post(), meta raw, the terms through wp_set_object_terms();
	 * each only where what it holds is the hash decide() named (the kind
	 * reads again as it writes).
	 *
	 * @param array  $part    Part of the journal.
	 * @param int    $live_id Post.
	 * @param array  $backup  The backed-up state (current() before the step).
	 * @param string $hash    The hash the part has to hold as it is put back (decide()).
	 * @param string $w       The write side of the kind (writer()).
	 * @return array{state:string,note?:string}
	 */
	private static function restore_part( array $part, $live_id, array $backup, $hash, $w ) {
		$put = $w::restore_part( (string) $part['part'], $live_id, $backup, (string) $hash, AB_MCP_Sync_Run::now() );
		if ( is_wp_error( $put ) ) {
			return array(
				'state' => 'unclear',
				'note'  => $put->get_error_message(),
			);
		}
		return $put;
	}

	/**
	 * Phase 7: the caches of what the run wrote (decision 33), in parts
	 * within the work of each call, the cursor kept in the result of the
	 * step (GitHub Codex round 5 on free#137, P2).
	 *
	 * @param array $run     Run.
	 * @param array $step    Step.
	 * @param array $ctx     context().
	 * @param float $started When the call began.
	 * @return array|WP_Error
	 */
	private static function run_post( array $run, array $step, array $ctx, $started ) {
		unset( $ctx );
		$steps = AB_MCP_Sync_Run::steps( (int) $run['run_id'] );
		if ( is_wp_error( $steps ) ) {
			return $steps;
		}
		// The written objects with their kind: a post by its id, a term by
		// term:<id> (two id spaces; branch 9).
		$ids = array();
		foreach ( $steps as $one ) {
			if ( 'write' === $one['op'] && 'done' === $one['state'] && is_array( $one['result'] ) && isset( $one['result']['live_ref'] ) ) {
				$kind  = is_array( $one['parts'] ) && isset( $one['parts']['object']['kind'] ) ? (string) $one['parts']['object']['kind'] : 'post';
				$ids[] = array( null === self::writer( $kind ) ? 'post' : $kind, self::live_of( self::writer( $kind ), $one['result']['live_ref'] ) );
			}
		}
		// In parts, within the work of the call, the cursor kept in the result
		// of the step (as the check and the verification; GitHub Codex round 5
		// on free#137, P2: before, every object in one call without a budget,
		// and a call that ran out of time left the step planned, the next one
		// beginning at the first object again). Each object is emptied once:
		// the cursor stands at the first object not emptied yet; a request
		// that ends between an emptying and its cursor empties those objects
		// again, which changes nothing (a cache emptied twice is empty).
		$result = is_array( $step['result'] ) && isset( $step['result']['cursor'] ) ? $step['result'] : array(
			'cursor'  => 0,
			'objects' => array(),
		);
		$count  = count( $ids );
		for ( $i = (int) $result['cursor']; $i < $count; $i++ ) {
			if ( $i > (int) $result['cursor'] && self::now() - $started > self::$work ) {
				$result['cursor'] = $i;
				AB_MCP_Sync_Run::update_step( (int) $run['run_id'], (int) $step['seq'], array( 'result' => $result ), 'planned' );
				AB_MCP_Sync_Run::touch( (int) $run['run_id'] );
				return array( 'next' => sprintf( 'The caches of %1$d of %2$d written object(s) were emptied within the work of this call; call action=next again to go on with them.', $i, $count ) );
			}
			list( $kind, $id ) = $ids[ $i ];
			$w                 = self::writer( $kind );
			$result['objects'][ 'post' === $kind ? (string) $id : $kind . ':' . $id ] = $w::post_write( $id );
		}
		foreach ( array_unique( array_merge( AB_MCP_Sync_Post_Writer::TAXONOMIES, AB_MCP_Sync_Term_Writer::TAXONOMIES ) ) as $taxonomy ) {
			clean_term_cache( array(), $taxonomy, true );
		}
		unset( $result['cursor'] );
		$result['message'] = sprintf( 'The caches of %d written object(s) were emptied (post, meta and term caches, the builder caches, the page cache where a caching plugin is known).', count( $result['objects'] ) );
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
		$last   = array();
		foreach ( $steps as $one ) {
			if ( 'write' === $one['op'] ) {
				$writes[] = $one;
				if ( 'done' === $one['state'] && is_array( $one['result'] ) && isset( $one['result']['live_ref'] ) ) {
					// The last step that wrote an object holds its target: the
					// parent step of an attachment writes after the step that
					// made it unattached (branch 10).
					$last[ ( $one['parts']['object']['kind'] ?? 'post' ) . ':' . (string) $one['result']['live_ref'] ] = (int) $one['seq'];
				}
			}
		}
		$worked = false;
		$left   = static function () use ( $started ) {
			return self::$work - ( self::now() - $started );
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
				$target  = isset( $one['parts']['object']['target'] ) ? (string) $one['parts']['object']['target'] : '';
				$w       = self::writer( isset( $one['parts']['object']['kind'] ) ? (string) $one['parts']['object']['kind'] : 'post' );
				$live_id = isset( $one['result']['live_ref'] ) ? self::live_of( $w, $one['result']['live_ref'] ) : 0;
				$later   = ( $last[ ( $one['parts']['object']['kind'] ?? 'post' ) . ':' . (string) $live_id ] ?? (int) $one['seq'] ) !== (int) $one['seq'];
				$hash    = self::is_ref( $live_id ) && null !== $w && ! $later ? $w::live_hash( $live_id, $ctx['source'] ) : null;
				if ( ! $later && ( null === $hash || ! hash_equals( $target, $hash ) ) ) {
					$result['findings'][] = array(
						'seq'     => (int) $one['seq'],
						'message' => sprintf( 'The %1$s %2$s no longer hashes to the target of step %3$d (changed since it was written, or not readable whole).', $one['parts']['object']['kind'] ?? 'post', $live_id, (int) $one['seq'] ),
					);
				}
				// The first published object with a page of its own is the
				// sample: a template, a template part, the global styles, a
				// navigation and a reusable block have none (their types are not
				// viewable, is_post_type_viewable(); branch 9b), a term neither.
				if ( null === $result['sample'] && isset( $one['result']['status'] ) && 'publish' === $one['result']['status'] && $live_id > 0 && self::viewable( $one, $live_id ) ) {
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
		// The error log without new lines since the mark of the check, where
		// readable; then the close. Read from the marked byte on, in chunks,
		// at most LOG_BYTES (GitHub Codex round 4 on free#137, P2: file()
		// loaded the whole log). A log that cannot be told (not marked, read
		// to its cap, rotated) is a finding, never «no new line».
		$mark   = null;
		$marked = false;
		foreach ( $steps as $one ) {
			if ( 'check' !== $one['op'] || ! is_array( $one['result'] ) ) {
				continue;
			}
			if ( array_key_exists( 'error_log_bytes', $one['result'] ) ) {
				$marked = true;
				$mark   = $one['result']['error_log_bytes'];
			} elseif ( array_key_exists( 'error_log_lines', $one['result'] ) ) {
				$marked = 'lines';
			}
		}
		$path = self::error_log_path();
		if ( null !== $path ) {
			if ( 'lines' === $marked || ! $marked || null === $mark ) {
				$result['findings'][] = array(
					'seq'     => 0,
					'message' => 'The error log of the site is readable now but was not marked at the check before the run (a run begun before this build, or the log made since), so new lines since the run began cannot be told: read it by hand (wp_get_error_log).',
				);
			} else {
				$since = self::error_log_lines_since( $path, (int) $mark, self::LOG_BYTES );
				if ( null === $since ) {
					$result['findings'][] = array(
						'seq'     => 0,
						'message' => 'The error log of the site could not be read after the run, so new lines since the run began cannot be told: read it by hand (wp_get_error_log).',
					);
				} elseif ( $since['rotated'] ) {
					$result['findings'][] = array(
						'seq'     => 0,
						'message' => sprintf( 'The error log of the site is smaller than at the check before the run (rotated or emptied in between); it holds %d line(s) now, which cannot be told from lines the run added: read it by hand (wp_get_error_log).', (int) $since['lines'] ),
					);
				} elseif ( $since['failed'] ) {
					$result['findings'][] = array(
						'seq'     => 0,
						'message' => sprintf( 'The error log of the site could not be read to its end after the run (a read failed; %d line(s) counted up to there): read it by hand (wp_get_error_log).', (int) $since['lines'] ),
					);
				} elseif ( $since['capped'] ) {
					$result['findings'][] = array(
						'seq'     => 0,
						'message' => sprintf( 'The error log of the site grew by more than %1$d MiB since the check before the run (%2$d line(s) counted up to there, not to its end): read it by hand (wp_get_error_log).', (int) ( self::LOG_BYTES / 1048576 ), (int) $since['lines'] ),
					);
				} elseif ( $since['lines'] > 0 ) {
					$result['findings'][] = array(
						'seq'     => 0,
						'message' => sprintf( 'The error log of the site has %d new line(s) since the check before the run (wp_get_error_log shows them).', (int) $since['lines'] ),
					);
				}
			}
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
	 * Whether the object a write step wrote has a page of its own for the
	 * sample (decision 33): a post of a type WordPress makes viewable
	 * (is_post_type_viewable()), read from its row.
	 *
	 * @param array $step    The write step.
	 * @param int   $live_id The object here.
	 * @return bool
	 */
	private static function viewable( array $step, $live_id ) {
		$kind = is_array( $step['parts'] ) && isset( $step['parts']['object']['kind'] ) ? (string) $step['parts']['object']['kind'] : 'post';
		if ( 'post' !== $kind ) {
			return false;
		}
		$type = AB_MCP_Sync_Rows::post_type_of( (int) $live_id );
		return is_string( $type ) && is_post_type_viewable( $type );
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
	 * the closed run a run of the type revert names) taken back part by part,
	 * under the lock of the call (next()), on the run as the call read it
	 * under that lock (fresh()).
	 *
	 * @param array $run  Run.
	 * @param array $step Step.
	 * @param array $ctx  context().
	 * @return array|WP_Error
	 */
	private static function run_revert( array $run, array $step, array $ctx ) {
		try {
			return self::revert_boundary( $run, $step, $ctx );
		} finally {
			AB_MCP_Sync_Guard::end( (int) $run['run_id'], (int) $step['seq'] );
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
		$live_id = isset( $object['live_ref'] ) ? self::live_of( self::writer_of( $journal ), $object['live_ref'] ) : 0;
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
			if ( ! self::is_ref( $live_id ) ) {
				return self::fail_step( $run_id, $seq, 'begun', sprintf( 'Step %d made no object (its row was not written); there is nothing to take back for it.', (int) $forward['seq'] ) );
			}
			$rest = self::rest( array( 'kind' => $object['kind'], 'staging_ref' => $object['staging_ref'] ), $live_id );
			// The pair residual, its save checked as every save (the lead after
			// Codex round 1 of branch 11, as C1): a pair that did not become a
			// rest stays active, and a later run could make the object again.
			$kept = true;
			if ( null !== $pair_id ) {
				$pair = AB_MCP_Sync_Pairs::get( $pair_id );
				$kept = is_array( $pair ) && 'residual' !== $pair['state'] ? AB_MCP_Sync_Pairs::set_state( $pair_id, 'residual', AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( $pair_id ) ) ) : $pair;
			}
			$result = array(
				'message'  => sprintf( 'The %1$s %2$s was made by the run and is not removed by the way back (nothing is deleted in this version); it stays as a rest, its pair residual. Remove it by hand in wp-admin where it should go.', $object['kind'], $live_id ),
				'live_ref' => (string) $live_id,
				'rest'     => $rest,
				'parts'    => array(),
			);
			$made_by = self::writer_of( $journal );
			if ( null !== $made_by ) {
				// The files the run laid stay as well (decision 26), named.
				$result = self::with_files( $result, $journal, $made_by );
				// The fields of a shared row the step set for its new object (a
				// position of a new menu; decision 23, «setzt … die Positionen
				// zurück»): put back where they are still the step's own.
				$shared = self::shared_back( $run_id, $seq, $journal, $live_id, $what );
				if ( null !== $shared ) {
					$result['parts'] = $shared['parts'];
					if ( ! $shared['all_back'] ) {
						$result['message'] .= ' A field of a row it shares with other objects (a position of a menu) did not go back whole: see parts.';
					}
				}
			}
			$state = 'done';
			if ( is_wp_error( $kept ) ) {
				$state              = 'unclear';
				$result['message'] .= ' ' . self::unit_rest_failed( $kept );
			}
			$ended = self::end_step( $run_id, $seq, array( 'state' => $state, 'result' => $result, 'pair_id' => $pair_id ) );
			return is_wp_error( $ended ) ? $ended : array( 'next' => $result['message'] );
		}
		if ( ! self::is_ref( $live_id ) ) {
			return self::fail_step( $run_id, $seq, 'begun', 'The journal names no object on this site; nothing is put back.' );
		}
		$w = self::writer_of( $journal );
		if ( null === $w ) {
			return self::fail_step( $run_id, $seq, 'begun', sprintf( 'No step of this version writes the kind %s; nothing is put back.', (string) $object['kind'] ) );
		}
		$covers = $w::guard_of( $live_id, $w::subtype_of( $object ) );
		$guard  = AB_MCP_Sync_Guard::begin( $run_id, $seq, $covers['posts'], $covers['taxonomies'], isset( $covers['options'] ) ? $covers['options'] : array(), $covers['terms'] );
		if ( is_wp_error( $guard ) ) {
			return self::fail_step( $run_id, $seq, 'begun', $guard->get_error_message() );
		}
		$backup = AB_MCP_Sync_Backup::get( isset( $what['forward_run'] ) ? (int) $what['forward_run'] : $run_id, $journal['backup'] );
		if ( is_wp_error( $backup ) ) {
			return self::fail_step( $run_id, $seq, 'begun', $backup->get_error_message() );
		}
		// A journal of an earlier build of this branch, without the record
		// whether the object deviated within its step: fail closed, every part
		// of it that is not as the backup is left (decide()).
		if ( ! array_key_exists( 'deviated', $object ) ) {
			foreach ( $journal['parts'] as $k => $part ) {
				$journal['parts'][ $k ]['deviated'] = 'earlier_build';
			}
		} elseif ( empty( $object['deviated'] ) && self::marked_foreign( $journal ) ) {
			// A part the journal marks as changed by another hand within the
			// step, where the step ended before it marked the object (its
			// request ended): the object deviated all the same (Codex round 8).
			foreach ( $journal['parts'] as $k => $part ) {
				$journal['parts'][ $k ]['deviated'] = 'foreign_mark';
			}
		}
		// The parts, each decided right before its turn on the state read then
		// against the backup and what the run owns (put_back(), decide(): the
		// one place of the rule; decision 23), the row first, then every part
		// read again. A part of an object that deviated, and a part never read
		// back (an interrupted step), is left with its value before.
		$put      = self::put_back( $journal, $live_id, $backup );
		$outcomes = array();
		foreach ( $journal['parts'] as $i => $part ) {
			$outcomes[] = array( 'part' => $part['part'] ) + $put['parts'][ $i ];
		}
		$all_back = $put['all_back'];
		$w::post_write( $live_id );
		// The pair: back to its snapshot where everything went back; unclear otherwise.
		$pair_note = '';
		$made      = self::made_in( $w, $journal, $live_id );
		if ( null !== $pair_id && array() !== $made ) {
			// A part of the unit the run made stays (a widget instance; decision
			// 23, finding R7-2): the way back does not put this pair back to its
			// snapshot; the whole unit is a rest, its bases of before and its
			// mapping of the run (base_extra) kept until a confirmed alignment
			// (wp_site_pairs) replaces them.
			$pair = AB_MCP_Sync_Pairs::get( $pair_id );
			$set  = is_array( $pair ) && 'residual' !== $pair['state'] ? AB_MCP_Sync_Pairs::set_state( $pair_id, 'residual', AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( $pair_id ) ) ) : $pair;
			$all_back  = false;
			$pair_note = sprintf( ' The run made %s in this unit, which the way back does not remove: the unit stays as a rest, its pair residual.', implode( ', ', $made ) );
			if ( is_wp_error( $set ) ) {
				// Checked as every save (C1).
				$pair_note .= ' ' . self::unit_rest_failed( $set );
			}
		} elseif ( null !== $pair_id ) {
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
				? sprintf( 'The %1$s %2$s went back to its state before the run: every part the run wrote was still its own and was put back, and every part reads as the backup after that; the pair has its bases of before.%3$s', $object['kind'], $live_id, $pair_note )
				: sprintf( 'The %1$s %2$s went back only in part: the object deviated within its step and is left whole, or a part was changed since the run wrote it, was never read back, or needs a check, and is left as it is, or reads other than the backup after the parts went back (see parts; a part left names its value before); the pair is unclear until a person confirms a new alignment (wp_site_pairs).%3$s', $object['kind'], $live_id, $pair_note ),
			'live_ref' => (string) $live_id,
			'parts'    => $outcomes,
		);
		if ( array() !== $made ) {
			$result['rest'] = self::rest( array( 'kind' => $object['kind'], 'staging_ref' => $object['staging_ref'] ), $live_id ) + array( 'made' => $made );
		}
		// The files the run laid stay (decision 26: nothing is deleted, not
		// even a file of its own), named with their paths.
		$result = self::with_files( $result, $journal, $w );
		$ended  = self::end_step( $run_id, $seq, array( 'state' => $all_back ? 'done' : 'unclear', 'result' => $result, 'pair_id' => $pair_id ) );
		return is_wp_error( $ended ) ? $ended : array( 'next' => $result['message'] );
	}

	/**
	 * Close a run of the way back: reverted where every step went back and
	 * nothing is left, else with findings (rests, unclear parts). Under the
	 * lock of the call (next()), and only once no step of the way back is
	 * planned or begun: the booking given back and the clean-up of the store
	 * never run beside a step that still reads its backup (GitHub Codex
	 * round 6 on free#137, P1: pick() found nothing planned while the last
	 * step was begun in another request, and the run closed beside it
	 * without the lock).
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
			if ( in_array( $step['state'], array( 'planned', 'begun' ), true ) ) {
				return new WP_Error( 'ab_mcp_sync_run_state', sprintf( 'The way back of run %1$d is not closed: its step %2$d is %3$s. Ask for the status of the run (wp_site_push action=status) and go on from there.', (int) $run['run_id'], (int) $step['seq'], (string) $step['state'] ) );
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

	/**
	 * What the close of a way back says: reverted, or with findings, naming
	 * the steps of the way back that stayed as they are (unclear: the
	 * interruption, a part changed since, an object that deviated; failed)
	 * and the rests.
	 *
	 * @param array $run The run as closed.
	 * @return string
	 */
	private static function closed_note( array $run ) {
		if ( 'closed_reverted' === $run['state'] ) {
			return 'Every step of the way back ran and went back whole; the run is closed as reverted.';
		}
		$stayed = array();
		$rests  = 0;
		$steps  = AB_MCP_Sync_Run::steps( (int) $run['run_id'] );
		foreach ( is_array( $steps ) ? $steps : array() as $step ) {
			if ( 'revert' !== $step['op'] ) {
				continue;
			}
			if ( in_array( $step['state'], array( 'unclear', 'failed' ), true ) ) {
				$stayed[] = sprintf( 'step %1$d of the way back stayed as it is (%2$s)', (int) $step['seq'], (string) $step['state'] );
			}
			if ( is_array( $step['result'] ) && isset( $step['result']['rest'] ) ) {
				++$rests;
			}
		}
		return sprintf(
			'Every step of the way back ran; the run is closed with findings: %1$s%2$s. Check those objects in wp-admin (each part left names its value before; their pairs are unclear until wp_site_pairs confirms a new alignment); remove a rest by hand where it should go.',
			array() === $stayed ? 'no step stayed' : implode( '; ', $stayed ),
			$rests > 0 ? sprintf( '; %d new object(s) stay as rests (named in rests)', $rests ) : ''
		);
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
		$w    = self::writer( $item['kind'] );
		$hash = null === $w ? null : $w::live_hash( $live_id, self::context( $run )['source'] );
		$made = AB_MCP_Sync_Pairs::create(
			array(
				'source_id'    => (string) $run['source_id'],
				'kind'         => (string) $item['kind'],
				'live_ref'     => (string) $live_id,
				'staging_ref'  => (string) $item['staging_ref'],
				'canon'        => AB_MCP_Sync_Canon::VERSION,
				'base_live'    => null === $hash ? str_repeat( '0', 64 ) : $hash,
				// An attachment made unattached until its parent step (branch 10):
				// the staging base of that state, so a report sees it changed on
				// staging until its parent is set.
				'base_staging' => isset( $prepared['base_staging'] ) && is_string( $prepared['base_staging'] ) ? $prepared['base_staging'] : (string) $item['staging_hash'],
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
	 * The parent step of an attachment the run created (branch 10; decision
	 * 16: the weak reference set by a step of its own after its target), as
	 * an update of that attachment: the step that created it is done and
	 * names it and its pair; the pair is active and of this run; the
	 * attachment is to hash to that step's target (the attachment
	 * unattached), as an update compares the target of the plan.
	 *
	 * @param array $run  Run.
	 * @param array $item The item of the plan (op attach, attach_of).
	 * @return array|WP_Error The item as an update.
	 */
	private static function attach_item( array $run, array $item ) {
		$made = AB_MCP_Sync_Run::step( (int) $run['run_id'], isset( $item['attach_of'] ) ? (int) $item['attach_of'] : 0 );
		if ( ! is_array( $made ) || 'done' !== $made['state'] || ! is_array( $made['result'] ) || empty( $made['result']['live_ref'] ) || empty( $made['result']['pair_id'] ) || ! is_array( $made['parts'] ) || ! isset( $made['parts']['object']['target'] ) ) {
			return new WP_Error( 'ab_mcp_sync_run_invalid', sprintf( 'The attachment %s of the staging site was not made by step %d of this run, so its parent is not set. It stays unattached: a new report (wp_site_diff) shows it changed on staging, and a run from it sets the parent.', (string) $item['staging_ref'], isset( $item['attach_of'] ) ? (int) $item['attach_of'] : 0 ) );
		}
		$pair = AB_MCP_Sync_Pairs::get( (int) $made['result']['pair_id'] );
		if ( ! is_array( $pair ) || 'active' !== $pair['state'] || (int) $pair['last_run'] !== (int) $run['run_id'] || (string) $pair['live_ref'] !== (string) $made['result']['live_ref'] ) {
			return new WP_Error( 'ab_mcp_sync_run_invalid', sprintf( 'The pair of the attachment %s changed since step %d of this run made it, so its parent is not set. Make a new report (wp_site_diff) and plan again.', (string) $item['staging_ref'], (int) $item['attach_of'] ) );
		}
		$item['op']        = 'update';
		$item['attach']    = true;
		$item['live_ref']  = (string) $made['result']['live_ref'];
		$item['pair_id']   = (int) $made['result']['pair_id'];
		$item['live_hash'] = (string) $made['parts']['object']['target'];
		$item['pair_hash'] = AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( (int) $made['result']['pair_id'] ) );
		return $item;
	}

	/**
	 * The files a step laid itself, in its result (decision 26: they stay,
	 * named with their paths), for a kind that lays files.
	 *
	 * @param array  $result  The result of the step.
	 * @param array  $journal Its journal.
	 * @param string $w       The write side of the kind.
	 * @return array
	 */
	private static function with_files( array $result, array $journal, $w ) {
		if ( method_exists( $w, 'own_files' ) ) {
			$files = $w::own_files( $journal );
			if ( array() !== $files ) {
				$result['files'] = $files;
			}
		}
		// The files whose state is unclear (a request that ended as one was
		// made, one not provably at its path): named, never own (Codex round
		// 1 of branch 10, A2).
		if ( method_exists( $w, 'unclear_files' ) ) {
			$unclear = $w::unclear_files( $journal );
			if ( array() !== $unclear ) {
				$result['files_unclear'] = $unclear;
			}
		}
		return $result;
	}

	/**
	 * The way back of the fields a step set in rows other objects share for a
	 * new object it made (a position of a new menu; branch 11, decision 23):
	 * the object stays as a rest, these fields go back as any part does
	 * (put_back(): where they are the step's own and read as it left them),
	 * from the fields the backup of the new object holds (shared_state()),
	 * under the takeover lock. Null where the kind has none, or the step set
	 * none.
	 *
	 * @param int        $run_id  Run.
	 * @param int        $seq     Step of the way back.
	 * @param array      $journal Journal of the step that made the object.
	 * @param int|string $live_id The object.
	 * @param array      $what    forward_run, forward_seq.
	 * @return array{all_back:bool,parts:array}|null
	 */
	private static function shared_back( $run_id, $seq, array $journal, $live_id, array $what ) {
		$w = self::writer_of( $journal );
		if ( null === $w || ! method_exists( $w, 'shared_part' ) ) {
			return null;
		}
		$parts = array();
		foreach ( $journal['parts'] as $part ) {
			if ( is_array( $part ) && isset( $part['part'] ) && $w::shared_part( (string) $part['part'] ) ) {
				$parts[] = $part;
			}
		}
		if ( array() === $parts ) {
			return null;
		}
		$left   = static function ( $note ) use ( $parts ) {
			$out = array();
			foreach ( $parts as $part ) {
				$out[] = array(
					'part'  => (string) $part['part'],
					'state' => 'left',
					'note'  => (string) $note,
				);
			}
			return array(
				'all_back' => false,
				'parts'    => $out,
			);
		};
		$covers = $w::guard_of( $live_id, $w::subtype_of( $journal['object'] ) );
		$guard  = AB_MCP_Sync_Guard::begin( $run_id, $seq, $covers['posts'], $covers['taxonomies'], isset( $covers['options'] ) ? $covers['options'] : array(), $covers['terms'] );
		if ( is_wp_error( $guard ) ) {
			return $left( $guard->get_error_message() );
		}
		$backup = AB_MCP_Sync_Backup::get( isset( $what['forward_run'] ) ? (int) $what['forward_run'] : $run_id, $journal['backup'] );
		if ( is_wp_error( $backup ) || ! is_array( $backup ) || ! isset( $backup['parts'] ) || ! is_array( $backup['parts'] ) ) {
			return $left( 'The backup of the step holds no fields of the shared row, so they are left as they are; set them by hand in wp-admin.' );
		}
		if ( ! array_key_exists( 'deviated', $journal['object'] ) ) {
			foreach ( $parts as $k => $part ) {
				$parts[ $k ]['deviated'] = 'earlier_build';
			}
		} elseif ( empty( $journal['object']['deviated'] ) && self::marked_foreign( $journal ) ) {
			foreach ( $parts as $k => $part ) {
				$parts[ $k ]['deviated'] = 'foreign_mark';
			}
		}
		$journal['parts'] = $parts;
		$put              = self::put_back( $journal, $live_id, $backup );
		$w::post_write( $live_id );
		$out = array();
		foreach ( $parts as $i => $part ) {
			$out[] = array( 'part' => $part['part'] ) + $put['parts'][ $i ];
		}
		return array(
			'all_back' => $put['all_back'],
			'parts'    => $out,
		);
	}

	/**
	 * The parts of a unit a step made and that are there now (a widget
	 * instance of a widget area; branch 11), by the write side of the kind
	 * (made()); none for a kind without such parts.
	 *
	 * @param string|null     $w       The write side of the kind (writer()).
	 * @param array           $journal The journal of the step.
	 * @param int|string|null $live_id The object here.
	 * @return string[]
	 */
	private static function made_in( $w, array $journal, $live_id ) {
		if ( null === $w || null === $live_id || ! method_exists( $w, 'made' ) ) {
			return array();
		}
		return $w::made( $journal, $live_id );
	}

	/**
	 * The pair of a unit a step made a part of and left (a widget area with a
	 * new instance whose step did not end done; decisions 23 and 28): its
	 * bases of before kept, the mapping of the run in base_extra (the kept
	 * form of the writer, base_extra_kept), then residual as a whole.
	 *
	 * Every save checked (Codex round 1 of branch 11, C1): a pair that did
	 * not become a rest would let a later run make the part a second time.
	 *
	 * @param int   $pair_id  Pair.
	 * @param array $prepared target_of() of the step.
	 * @return true|WP_Error
	 */
	private static function unit_rest( $pair_id, array $prepared ) {
		$pair = AB_MCP_Sync_Pairs::get( (int) $pair_id );
		if ( is_wp_error( $pair ) ) {
			return $pair;
		}
		if ( ! is_array( $pair ) || 'active' !== $pair['state'] ) {
			return true;
		}
		if ( isset( $prepared['base_extra_kept'] ) && is_array( $prepared['base_extra_kept'] ) ) {
			$rebased = AB_MCP_Sync_Pairs::rebase(
				(int) $pair_id,
				array(
					'canon'        => (string) $pair['canon'],
					'base_live'    => (string) $pair['base_live'],
					'base_staging' => (string) $pair['base_staging'],
					'base_origin'  => (string) $pair['base_origin'],
					'base_extra'   => $prepared['base_extra_kept'],
				),
				AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( (int) $pair_id ) )
			);
			if ( is_wp_error( $rebased ) ) {
				return $rebased;
			}
		}
		$set = AB_MCP_Sync_Pairs::set_state( (int) $pair_id, 'residual', AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( (int) $pair_id ) ) );
		return is_wp_error( $set ) ? $set : true;
	}

	/**
	 * What a step says where the pair of its unit did not become a rest
	 * (unit_rest(), C1).
	 *
	 * @param WP_Error $error The answer of the pairs.
	 * @return string
	 */
	private static function unit_rest_failed( WP_Error $error ) {
		return sprintf( 'Its pair could not be kept as a rest (%s): a later run could make it again, so align the pair by hand (wp_site_pairs) before another run.', $error->get_error_message() );
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
			'live_ref'    => (string) $live_id,
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
	 * The names of the parts of one or more states as current() reads them:
	 * the row, meta:<key> for every key, terms:<taxonomy> for every
	 * taxonomy, whether the plan names them or not (Codex round 15).
	 *
	 * @param array ...$states current() of the post.
	 * @return string[]
	 */
	private static function part_names( array ...$states ) {
		$names = array( 'row' => true );
		foreach ( $states as $state ) {
			foreach ( isset( $state['meta'] ) && is_array( $state['meta'] ) ? $state['meta'] : array() as $row ) {
				$names[ 'meta:' . (string) $row['key'] ] = true;
			}
			foreach ( isset( $state['terms'] ) && is_array( $state['terms'] ) ? $state['terms'] : array() as $term ) {
				$names[ 'terms:' . (string) $term['taxonomy'] ] = true;
			}
			// The fields of the rows a kind shares with other objects (branch
			// 11, decision 24: a position of a menu, an instance of a widget,
			// an area of sidebars_widgets, the rest of such a row), each a part
			// of the state by its name: what changed besides is another hand's.
			foreach ( isset( $state['parts'] ) && is_array( $state['parts'] ) ? $state['parts'] : array() as $name => $unused ) {
				$names[ (string) $name ] = true;
			}
		}
		return array_keys( $names );
	}

	/**
	 * The first part, outside the parts of the journal, that a call changed
	 * between two reads of the whole state (part_names(): every key and
	 * taxonomy, planned or not), other than what the row call was to write
	 * besides the row ($named, with_row()); null where none.
	 *
	 * @param array      $journal Journal.
	 * @param array|null $before  current() before the call; null for no object yet.
	 * @param array      $after   current() after the call.
	 * @param array      $named   part => hash the call was to write besides its own part.
	 * @param string     $w       The write side of the kind (writer()).
	 * @return string|null
	 */
	private static function changed_besides( array $journal, $before, array $after, array $named, $w ) {
		$journaled = array();
		foreach ( $journal['parts'] as $part ) {
			$journaled[ (string) $part['part'] ] = true;
		}
		foreach ( self::part_names( is_array( $before ) ? $before : array(), $after ) as $name ) {
			if ( isset( $journaled[ $name ] ) ) {
				continue;
			}
			$now = $w::part_hash( $after, $name );
			if ( ! self::changed( $w::part_hash( $before, $name ), $now ) ) {
				continue;
			}
			if ( isset( $named[ $name ] ) && null !== $now && hash_equals( (string) $named[ $name ], (string) $now ) ) {
				continue;
			}
			return $name;
		}
		return null;
	}

	/**
	 * Why a group of parts that go back only together may not (together()):
	 * a member of the journal that the way back leaves by its rule (decide():
	 * not the step's own, changed since, never read back) or that this
	 * account may not put back (restore_right()); null where every member
	 * goes back or is as the backup already.
	 *
	 * @param array      $parts   The parts of the journal.
	 * @param string[]   $members The group.
	 * @param array|null $seen    current() as read now.
	 * @param array      $backup  current() of the backup.
	 * @param int        $live_id The object.
	 * @param string     $w       The write side of the kind.
	 * @return string|null Why not, as a clause.
	 */
	private static function group_refusal( array $parts, array $members, $seen, array $backup, $live_id, $w ) {
		foreach ( $parts as $part ) {
			$member = is_string( $part['part'] ) ? $part['part'] : '';
			if ( ! in_array( $member, $members, true ) ) {
				continue;
			}
			$decided = self::decide( $part, null === $seen ? null : $w::part_hash( $seen, $member ), (string) $w::part_hash( $backup, $member ) );
			if ( 'left' === $decided['state'] ) {
				return sprintf( 'the part %1$s does not go back (%2$s)', $member, rtrim( (string) $decided['why'], '.' ) );
			}
			if ( 'put_back' === $decided['state'] ) {
				$may = $w::restore_right( $member, $live_id, $backup, is_array( $seen ) ? $seen : array() );
				if ( true !== $may ) {
					return sprintf( 'this account may not %1$s on this site', $may );
				}
			}
		}
		return null;
	}

	/**
	 * The first file whose state differs between two readings of the files
	 * of an object (AB_MCP_Sync_Attachment_Writer::files_state(): path =>
	 * absent, SHA-256 and size, or what else lies there); null where none.
	 *
	 * @param array $before A reading.
	 * @param array $after  A later one.
	 * @return string|null The path.
	 */
	private static function changed_file( array $before, array $after ) {
		foreach ( array_unique( array_merge( array_map( 'strval', array_keys( $before ) ), array_map( 'strval', array_keys( $after ) ) ) ) as $path ) {
			if ( ( $before[ $path ] ?? null ) !== ( $after[ $path ] ?? null ) ) {
				return (string) $path;
			}
		}
		return null;
	}

	/**
	 * Whether a part of the journal is marked as changed by another hand
	 * (foreign), the mark that deviates its object (Codex round 8).
	 *
	 * @param array $journal Journal.
	 * @return bool
	 */
	private static function marked_foreign( array $journal ) {
		foreach ( isset( $journal['parts'] ) && is_array( $journal['parts'] ) ? $journal['parts'] : array() as $part ) {
			if ( is_array( $part ) && ! empty( $part['foreign'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The journal of a step whose request ended the step itself (done,
	 * deviated, failed; not interrupted), marked so (settled): a part still
	 * planned was never reached, so the step did not write it; it stands as
	 * not_reached, a description (Codex round 4). Only the journal of a step
	 * whose request ended before (interrupted) lacks the mark and keeps
	 * parts planned: never read back, so never put back (decide()); the
	 * answer names their value before.
	 *
	 * @param array $journal Journal.
	 * @return array
	 */
	private static function settled( array $journal ) {
		$journal['settled'] = true;
		foreach ( $journal['parts'] as $k => $part ) {
			if ( 'planned' === $part['state'] ) {
				$journal['parts'][ $k ]['state'] = 'not_reached';
			}
		}
		return $journal;
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
	 * @return array|WP_Error
	 */
	private static function answer( array $run, $step, $started, $next ) {
		$shown = AB_MCP_Sync_Run::shown( $run );
		if ( is_wp_error( $shown ) ) {
			return $shown;
		}
		$out = array(
			'run_id'    => (int) $run['run_id'],
			'type'      => $run['type'],
			'state'     => $run['state'],
			'step'      => null,
			// Kept in the shape of an answer: a lock another request holds
			// refuses the whole call (ab_mcp_sync_run_busy), so an answer is
			// never busy (the decision of 07.11.2026 evening, Variante A).
			'busy'      => false,
			'remaining' => array(
				'planned' => $shown['counts']['planned'],
				'seconds' => max( 0, (int) ( self::$work + self::RESERVE - ( self::now() - $started ) ) ),
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
	 * The error log of the site, debug.log of wp-content (where WP_DEBUG_LOG
	 * writes it; a log WP_DEBUG_LOG names elsewhere is not read, GRENZEN-
	 * LAUF.md): its path where it is a readable file, null otherwise.
	 *
	 * @return string|null
	 */
	private static function error_log_path() {
		$log = WP_CONTENT_DIR . '/debug.log';
		return is_file( $log ) && is_readable( $log ) ? $log : null;
	}

	/**
	 * The mark of the error log at the check before the run: its size in
	 * bytes, from which the verification counts the lines it gained
	 * (error_log_lines_since()); 0 where there is no log yet (one made since
	 * is new whole); null where there is one that cannot be measured.
	 *
	 * @return array{bytes:int}|null
	 */
	private static function error_log_mark() {
		$path = self::error_log_path();
		if ( null === $path ) {
			return array( 'bytes' => 0 );
		}
		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a log that cannot be measured is none.
		return false === $size ? null : array( 'bytes' => (int) $size );
	}

	/**
	 * The lines a log gained since a byte mark: read from the mark on in
	 * chunks of LOG_CHUNK bytes, the line ends counted, at most $max bytes in
	 * one call (GitHub Codex round 4 on free#137, P2: file() loaded the whole
	 * log, and a log of 300 MiB ended the run on a fatal error). A log
	 * smaller than the mark was rotated or emptied in between: it is counted
	 * from its start, and said so (rotated), since its lines cannot be told
	 * from lines the run added. Where the span to read passes $max, the
	 * count stops there and says so (capped): never «no new line». A read
	 * that fails before the end (fread() false) is said so as well (failed:
	 * counted up to there, not to the end; Codex round 22 of branch 8:
	 * before, it passed as the end of the file, and «no new line» could
	 * follow a read that failed at once); a file that cannot be opened or
	 * sought gives null.
	 *
	 * @param string $path The log.
	 * @param int    $from The mark: bytes the log had at the check.
	 * @param int    $max  Bytes to read at most.
	 * @return array{lines:int,capped:bool,rotated:bool,failed:bool,bytes:int}|null Null where the log cannot be read.
	 */
	public static function error_log_lines_since( $path, $from, $max ) {
		$size = is_file( $path ) && is_readable( $path ) ? @filesize( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $size ) {
			return null;
		}
		$from    = max( 0, (int) $from );
		$rotated = false;
		if ( (int) $size < $from ) {
			$rotated = true;
			$from    = 0;
		}
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- read in chunks, bounded.
		if ( false === $handle ) {
			return null;
		}
		$lines  = 0;
		$read   = 0;
		$capped = false;
		$failed = false;
		try {
			if ( $from > 0 && 0 !== fseek( $handle, $from ) ) {
				return null;
			}
			while ( $read < (int) $max ) {
				$chunk = fread( $handle, (int) min( self::LOG_CHUNK, (int) $max - $read ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( false === $chunk ) {
					// A read that failed is no end of the file: counted up to
					// here only, said so (fail closed).
					$failed = true;
					break;
				}
				if ( '' === $chunk ) {
					break;
				}
				$read  += strlen( $chunk );
				$lines += substr_count( $chunk, "\n" );
			}
			// Read up to the cap with more of the log behind it: not counted to its end.
			$capped = $read >= (int) $max && $from + $read < (int) $size;
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return array(
			'lines'   => $lines,
			'capped'  => $capped,
			'rotated' => $rotated,
			'failed'  => $failed,
			'bytes'   => (int) $size,
		);
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
