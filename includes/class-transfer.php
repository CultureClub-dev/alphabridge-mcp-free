<?php
/**
 * The takeover as a person sees it and acts on it (Z8, decision 35 of the
 * plan of 01.11.2026, branch 12), and what wp_staging_status reports of it
 * (transfer).
 *
 * WHAT IS READ, for the section «Takeover» of the page «Staging» (view())
 * and for wp_staging_status (status()), from the classes the tools use and
 * nothing counted again: the anchor of the key of the staging site, active
 * or prepared, with its fingerprint (AB_MCP_Sync_Anchor); the proof of the
 * protected store with the rule for the host (AB_MCP_Protected_Store::
 * proof(); the page only, as the proof may renew its record); the last
 * report of differences (AB_MCP_Sync_Report::latest()); the runs with their
 * state, their steps and how long their backups are kept
 * (AB_MCP_Sync_Run, AB_MCP_Sync_Schema::backup_rows()); an interrupted run
 * with its journal, its parts with their states and the rest (decision 18);
 * the way back of a closed run pair by pair (AB_MCP_Sync_Revert::
 * plan_for_person()); the pairs of the source of the confirmed key
 * (AB_MCP_Sync_Pairs::page_source()); the moments M1 to M3
 * (AB_MCP_Transfer_Moments).
 *
 * WHAT A PERSON DOES here, each behind its own nonce and manage_options in
 * AB_MCP_Admin, each through the class the tool goes through (no second way
 * of writing), and each that changes a run under the lock of the write
 * boundary (AB_MCP_Sync_Run::under_lock(), inside those classes): abort an
 * open run (AB_MCP_Sync_Engine::abort(); decision 15: a person with
 * manage_options may abort in wp-admin); close an interrupted run as
 * checked (AB_MCP_Sync_Run::close_checked(), way (a) of decision 18); take
 * one pair of a closed run back (AB_MCP_Sync_Revert::begin_for_person(),
 * then action=next as the tool goes on, until the run of the way back is
 * closed); go on with a way back this person began (AB_MCP_Sync_Revert::
 * call(), action=next); discard a pair (AB_MCP_Sync_Pairs_Tool::call(),
 * action=discard). A form carries the state the page showed (the state a
 * token binds for the tool), and nothing is done where it changed since.
 *
 * wp_staging_status reads only, as before: status() changes nothing, not
 * even the state of a run, which a reading call of a run settles
 * (AB_MCP_Sync_Run::settle()); a step begun whose request ended is named as
 * such, and the next call that changes the run takes it as interrupted.
 *
 * What a person reads on the page, and the way status() gives, is in
 * gettext calls with the text domain alphabridge-mcp and translated in the
 * nine bundled languages (branch 13, text/z8), as are the outcomes of the
 * forms and the refusals of the page's own checks. The refusals of the
 * tools a form goes through (AB_MCP_Sync_Run, AB_MCP_Sync_Engine,
 * AB_MCP_Sync_Revert, AB_MCP_Sync_Pairs_Tool) are translated where they
 * answer a person on this page; the deeper ones stay English for now.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-staging.php';
require_once __DIR__ . '/class-transfer-moments.php';

/**
 * Class AB_MCP_Transfer
 */
final class AB_MCP_Transfer {

	/** The section «Takeover» on the page «Staging». */
	const ANCHOR = 'ab-staging-transfer';

	/** An interrupted run, at the top of the section. */
	const ANCHOR_INTERRUPTED = 'ab-staging-interrupted';

	/** The steps of one run. */
	const ANCHOR_RUN = 'ab-staging-run';

	/** The pairs of the source. */
	const ANCHOR_PAIRS = 'ab-staging-pairs';

	/** Runs the list shows, the newest first. */
	const RUNS = 10;

	/**
	 * Runs to act on the list shows above its page at most (pinned());
	 * further ones are counted and named with the way to them (Codex round
	 * 2 of branch 12, B).
	 */
	const PINNED = 20;

	/** Pairs one page of the list shows. */
	const PAIRS = 50;

	/** Write steps of a run with a form of their own to take them back at most. */
	const TAKE_BACK = 50;

	/** Calls of action=next one form of the way back makes at most. */
	const NEXT_CALLS = 20;

	/** The actions of a report that a run writes. */
	const TAKEABLE = array( 'take_over', 'create' );

	/**
	 * The prefix of the transient that holds the outcome of a form of the
	 * section for its user, for OUTCOME_TTL seconds, until the page shows it.
	 */
	const OUTCOME = 'ab_mcp_transfer_outcome_';

	/** Seconds the outcome of a form is kept for the page. */
	const OUTCOME_TTL = 300;

	/**
	 * The classes of the takeover, loaded where they are needed (as the
	 * tools load them).
	 */
	public static function load() {
		require_once __DIR__ . '/sync/class-sync-run.php';
		require_once __DIR__ . '/sync/class-sync-engine.php';
		require_once __DIR__ . '/sync/class-sync-push.php';
		require_once __DIR__ . '/sync/class-sync-revert.php';
		require_once __DIR__ . '/sync/class-sync-report.php';
		require_once __DIR__ . '/sync/class-sync-pairs-tool.php';
		require_once __DIR__ . '/sync/class-sync-shown.php';
	}

	/* ------------------------------------------------- wp_staging_status */

	/**
	 * What wp_staging_status reports of the takeover (transfer): the side
	 * of this site; on the staging site its key; on the production site of
	 * a pair the anchor, whether the tables are there, the run that is not
	 * closed (with its journal, its parts with their states and the rest
	 * where it is interrupted, decision 18) and the way. Reads only.
	 *
	 * @return array
	 */
	public static function status() {
		self::load();
		$role = AB_MCP_Staging::role();
		if ( AB_MCP_Staging::ROLE_STAGING === $role ) {
			$key = AB_MCP_Sync_Key::answer();
			return array(
				'side' => 'staging',
				'key'  => null === $key ? null : array( 'fingerprint' => (string) $key['fingerprint'] ),
				'next' => null === $key
					? __( 'This staging site has no key yet: a person makes it on the page AlphaBridge → Staging of this site and confirms it on the production site; only then does the production site take anything over from here.', 'alphabridge-mcp' )
					: __( 'The production site takes changes over from this site: wp_site_export here makes its packages (purpose=inventory for the report, purpose=objects for a run), and the page AlphaBridge → Staging of the production site shows the report, the runs and the way back.', 'alphabridge-mcp' ),
			);
		}
		$partner = AB_MCP_Staging::ROLE_PRODUCTION === $role ? AB_MCP_Sync_Anchor::partner() : null;
		if ( ! is_string( $partner ) ) {
			$out = array(
				'side' => 'none',
				'run'  => null,
				'next' => __( 'The takeover runs on the confirmed production site paired with a staging site: a person confirms the role and the pair on the page AlphaBridge → Staging of both sites.', 'alphabridge-mcp' ),
			);
			// A run left from before the pair changed stays named, with its
			// way (Codex round 1 of branch 12, B).
			$open = self::open_or_null();
			if ( null !== $open ) {
				$out['run']  = self::run_status( $open );
				$out['next'] = $out['run']['next'] . ' ' . $out['next'];
			}
			return $out;
		}
		$out = array(
			'side'   => 'production',
			'anchor' => self::anchor(),
			'tables' => AB_MCP_Sync_Schema::available(),
			'run'    => null,
			'next'   => '',
		);
		if ( null === $out['anchor']['active'] ) {
			$out['next'] = null === $out['anchor']['prepared']
				? __( 'No key of the staging site is confirmed here: fetch it or enter its pairing code on the page AlphaBridge → Staging, compare the fingerprint with the one the staging site shows, and confirm it there. Until then nothing is taken over.', 'alphabridge-mcp' )
				: __( 'A key of the staging site is prepared but not confirmed: a person compares its fingerprint with the one the staging site shows on its page AlphaBridge → Staging, and confirms it on this site\'s page. Until then nothing is taken over.', 'alphabridge-mcp' );
			// A run needs no key to be read, aborted or closed as checked.
			$open = $out['tables'] ? self::open_or_null() : null;
			if ( null !== $open ) {
				$out['run']  = self::run_status( $open );
				$out['next'] = $out['run']['next'] . ' ' . $out['next'];
			}
			return $out;
		}
		if ( ! $out['tables'] ) {
			$out['next'] = __( 'The tables of the takeover are not there: deactivate and activate AlphaBridge in wp-admin so they are made, or ask the host to let the database user create tables.', 'alphabridge-mcp' );
			return $out;
		}
		$open = AB_MCP_Sync_Run::open_run();
		if ( is_wp_error( $open ) ) {
			$out['next'] = $open->get_error_message();
			return $out;
		}
		if ( null === $open ) {
			$out['next'] = __( 'No run is open. The way: the report (wp_site_diff from the packages of the staging site), the approval of the person, the takeover (wp_site_push), its check, and the way back pair by pair (wp_site_revert); the page AlphaBridge → Staging shows the last report and the runs.', 'alphabridge-mcp' );
			return $out;
		}
		$out['run']  = self::run_status( $open );
		$out['next'] = $out['run']['next'];
		return $out;
	}

	/**
	 * The run of this site that is not closed, or null (also where the
	 * tables cannot be read). Reads only.
	 *
	 * @return array|null
	 */
	private static function open_or_null() {
		if ( ! AB_MCP_Sync_Schema::available() ) {
			return null;
		}
		$open = AB_MCP_Sync_Run::open_run();
		return is_array( $open ) ? $open : null;
	}

	/**
	 * The run that is not closed, as wp_staging_status reports it, without
	 * settling it (status() reads only): where it is interrupted, or a step
	 * of it is begun while the lock of the write boundary is not held (its
	 * request ended; the next call that changes the run takes it as
	 * interrupted), its journal (the steps, each with its parts and their
	 * states), the rest (the steps not run, each with its object) and the
	 * objects left as a rest so far.
	 *
	 * @param array $run The run (AB_MCP_Sync_Run::get()).
	 * @return array
	 */
	public static function run_status( array $run ) {
		$steps = AB_MCP_Sync_Run::steps( (int) $run['run_id'] );
		$steps = is_wp_error( $steps ) ? array() : $steps;
		$begun = null;
		foreach ( $steps as $step ) {
			if ( 'begun' === $step['state'] ) {
				$begun = (int) $step['seq'];
			}
		}
		$out = array(
			'run_id'  => (int) $run['run_id'],
			'type'    => (string) $run['type'],
			'state'   => (string) $run['state'],
			'created' => self::iso( (int) $run['created'] ),
			'calls'   => (int) $run['calls'],
			'counts'  => self::counts( $steps ),
			'next'    => AB_MCP_Sync_Run::way( $run ),
		);
		$interrupted = 'interrupted' === $run['state'];
		if ( ! $interrupted && null !== $begun ) {
			if ( AB_MCP_Sync_Run::lock_fresh() ) {
				$out['running'] = $begun;
			} else {
				// Not settled here (status() writes nothing): named as what it is.
				$out['begun']   = $begun;
				/* translators: 1: step, 2: run number. */
				$out['next']    = sprintf( __( 'Step %1$d of run %2$d was begun by a request that ended before it finished (the lock is no longer held): the next call that reads the run with the lock (wp_site_push action=status) marks it interrupted. Then a person closes it as checked on the page AlphaBridge → Staging, or wp_site_revert action=plan with its run_id leads its way back.', 'alphabridge-mcp' ), $begun, (int) $run['run_id'] );
				$interrupted    = true;
			}
		}
		if ( $interrupted ) {
			$shown          = AB_MCP_Sync_Run::shown( $run, $steps );
			$out['journal'] = is_wp_error( $shown ) ? array() : $shown['steps'];
			$out['rest']    = self::rest( $run, $steps );
			$out['rests']   = is_wp_error( $shown ) ? array() : $shown['rests'];
			$out['unclear'] = is_wp_error( $shown ) ? array() : $shown['unclear'];
		}
		if ( ! self::keyed() ) {
			// The ways of the tools need the key (Codex round 2 of branch 12,
			// B): only what goes without it.
			$out['next'] = self::way_without_key( $run, $interrupted );
		}
		return $out;
	}

	/**
	 * Whether the tools of the takeover go on here: a confirmed key of the
	 * staging site (AB_MCP_Sync_Proposal::source_id(), which wp_site_push,
	 * wp_site_revert and wp_site_pairs ask first).
	 *
	 * @return bool
	 */
	public static function keyed() {
		return ! is_wp_error( AB_MCP_Sync_Proposal::source_id() );
	}

	/**
	 * The way of a run that is not closed while no key of the staging site
	 * is confirmed: only what needs no key (closing as checked, aborting on
	 * the page «Staging»), and that the rest waits for the key.
	 *
	 * @param array $run         The run.
	 * @param bool  $interrupted Whether it is, or will be taken as, interrupted.
	 * @return string
	 */
	public static function way_without_key( array $run, $interrupted ) {
		$id = (int) $run['run_id'];
		if ( $interrupted ) {
			/* translators: %d: run number. */
			return sprintf( __( 'Run %d is interrupted, and no key of the staging site is confirmed here: a person reads its journal and closes it as checked on the page AlphaBridge → Staging (nothing is written). Leading its way back waits until the key is confirmed again there.', 'alphabridge-mcp' ), $id );
		}
		if ( 'push' === $run['type'] && 'open' === $run['state'] ) {
			/* translators: %d: run number. */
			return sprintf( __( 'Run %d is open, and no key of the staging site is confirmed here, so it takes no step: a person aborts it on the page AlphaBridge → Staging (what its steps wrote stays), or confirms the key again there.', 'alphabridge-mcp' ), $id );
		}
		/* translators: %d: run number. */
		return sprintf( __( 'The way back of run %d waits: no key of the staging site is confirmed here. It goes on once a person confirms the key again on the page AlphaBridge → Staging.', 'alphabridge-mcp' ), $id );
	}

	/**
	 * The rest of a run: the steps not run (planned), each with its object
	 * where the plan names one (decision 18: the rest named).
	 *
	 * @param array   $run   The run.
	 * @param array[] $steps Its steps.
	 * @return array[]
	 */
	public static function rest( array $run, array $steps ) {
		$objects = array();
		$plan    = is_array( $run['plan'] ?? null ) ? $run['plan'] : array();
		foreach ( isset( $plan['objects'] ) && is_array( $plan['objects'] ) ? $plan['objects'] : array() as $item ) {
			if ( is_array( $item ) && isset( $item['seq'] ) ) {
				$objects[ (int) $item['seq'] ] = $item;
			}
		}
		$out = array();
		foreach ( $steps as $step ) {
			if ( 'planned' !== $step['state'] ) {
				continue;
			}
			$entry = array(
				'seq' => (int) $step['seq'],
				'op'  => (string) $step['op'],
			);
			$item = $objects[ (int) $step['seq'] ] ?? null;
			if ( 'revert' === $step['op'] && is_array( $step['parts'] ) && isset( $step['parts']['forward_seq'] ) ) {
				$entry['takes_back'] = (int) $step['parts']['forward_seq'];
			} elseif ( is_array( $item ) ) {
				$entry['object'] = array(
					'kind'        => (string) ( $item['kind'] ?? '' ),
					'op'          => (string) ( $item['op'] ?? '' ),
					'staging_ref' => isset( $item['staging_ref'] ) ? (string) $item['staging_ref'] : null,
					'live_ref'    => isset( $item['live_ref'] ) ? (string) $item['live_ref'] : null,
				);
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * The anchor of the key of the staging site: active and prepared, each
	 * with its fingerprint and when.
	 *
	 * @return array{active:array|null,prepared:array|null}
	 */
	public static function anchor() {
		$active   = AB_MCP_Sync_Anchor::active();
		$prepared = AB_MCP_Sync_Anchor::prepared();
		return array(
			'active'   => null === $active ? null : array(
				'fingerprint'  => (string) $active['fingerprint'],
				'confirmed_at' => self::iso( (int) $active['at'] ),
			),
			'prepared' => null === $prepared ? null : array(
				'fingerprint' => (string) $prepared['fingerprint'],
				'via'         => 'route' === $prepared['via'] ? 'fetched' : 'pairing code',
				'prepared_at' => self::iso( (int) $prepared['at'] ),
			),
		);
	}

	/* ------------------------------------------------------- the page */

	/**
	 * What the section «Takeover» shows, read fresh for the page. The run
	 * that is not closed is settled here, as the status of the tool does
	 * (decision 18: the page shows an interrupted run with its journal).
	 *
	 * @param array $args run (the run whose steps to show), pairs_after (the page of the pairs).
	 * @return array
	 */
	public static function view( array $args = array() ) {
		self::load();
		$status = self::status();
		$view   = array(
			'side'        => $status['side'],
			'status'      => $status,
			'store'       => 'none' === $status['side'] ? null : self::store(),
			'report'      => null,
			'runs'        => array(),
			'runs_next'   => null,
			'runs_after'  => 0,
			'pinned'      => array(),
			'pinned_more' => 0,
			'anchored'    => false,
			'open'        => null,
			'interrupted' => null,
			'run'         => null,
			'pairs'       => null,
			'notice'      => null,
			'notes'       => array(
				'possible' => AB_MCP_Transfer_Moments::possible(),
				'hidden'   => AB_MCP_Transfer_Moments::hidden(),
			),
			'outcome'     => self::take_outcome(),
		);
		// The runs need the tables only, not the key of the staging site: a
		// run, its journal, «Close as checked» and «Abort» stay on the page
		// when the anchor is gone or the pair changed (Codex round 1 of
		// branch 12, B). The report, the pairs and the way back of a pair
		// need the confirmed key; without it the page says so.
		if ( ! AB_MCP_Sync_Schema::available() ) {
			return $view;
		}
		$anchored         = 'production' === $status['side'] && null !== $status['anchor']['active'];
		$view['anchored'] = $anchored;
		$due              = array();

		$report = $anchored ? AB_MCP_Sync_Report::latest( (string) AB_MCP_Sync_Anchor::active()['source_id'] ) : null;
		if ( is_array( $report ) ) {
			$view['report'] = self::report_view( $report );
			if ( $view['report']['done'] ) {
				AB_MCP_Transfer_Moments::report_seen( $report['created'] );
				if ( null !== $view['report']['m1'] ) {
					$due[] = 'm1';
				}
			}
		} elseif ( is_wp_error( $report ) ) {
			$view['report'] = array( 'error' => $report->get_error_message() );
		}

		$open = AB_MCP_Sync_Run::open_run();
		if ( is_array( $open ) ) {
			$settled      = AB_MCP_Sync_Run::settle( $open );
			$open         = is_wp_error( $settled ) ? $open : $settled;
			$view['open'] = $open;
			if ( 'interrupted' === $open['state'] ) {
				$view['interrupted'] = self::run_view( $open );
			}
		}
		$after = isset( $args['runs_after'] ) ? max( 0, (int) $args['runs_after'] ) : 0;
		$page  = AB_MCP_Sync_Run::page( $after, self::RUNS );
		if ( is_array( $page ) ) {
			if ( array() !== $page['runs'] ) {
				// A run needs a report: a site with a run has seen one.
				AB_MCP_Transfer_Moments::report_seen( (int) end( $page['runs'] )['created'] );
			}
			$keep = AB_MCP_Sync_Schema::backup_rows();
			$on   = array();
			foreach ( $page['runs'] as $run ) {
				$view['runs'][]               = self::run_row( $run, $keep );
				$on[ (int) $run['run_id'] ] = true;
			}
			$view['runs_next']  = $page['next'];
			$view['runs_after'] = $after;
			$pinned               = self::pinned( $keep, $on );
			$view['pinned']       = $pinned['runs'];
			$view['pinned_more']  = $pinned['more'];
		}

		$selected = isset( $args['run'] ) ? (int) $args['run'] : 0;
		if ( $selected > 0 ) {
			$run = AB_MCP_Sync_Run::get( $selected );
			if ( is_array( $run ) ) {
				$view['run'] = self::run_view( $run, true );
				if ( null !== $view['run']['m3'] ) {
					$due[] = 'm3';
				}
			}
		}

		$source = $anchored ? (string) AB_MCP_Sync_Anchor::active()['source_id'] : '';
		// One reading of the page of pairs, and from it the print of each
		// form (AB_MCP_Sync_Shown::shown(); the discard goes through the
		// other side of the helper).
		$after_p = isset( $args['pairs_after'] ) ? max( 0, (int) $args['pairs_after'] ) : 0;
		$shown_p = $anchored ? AB_MCP_Sync_Shown::shown(
			static function () use ( $source, $after_p ) {
				return AB_MCP_Sync_Pairs::page_source_shown( $source, $after_p, self::PAIRS );
			},
			static function ( array $page ) {
				$prints = array();
				foreach ( $page['pairs'] as $pair ) {
					$prints[ (int) $pair['id'] ] = AB_MCP_Sync_Pairs_Tool::state_shown( $pair );
				}
				return $prints;
			}
		) : null;
		$pairs   = is_array( $shown_p ) ? $shown_p['read'] : null;
		if ( is_array( $pairs ) ) {
			$view['pairs'] = array(
				'source' => $source,
				'pairs'  => array(),
				'next'   => $pairs['next'],
				'after'  => isset( $args['pairs_after'] ) ? max( 0, (int) $args['pairs_after'] ) : 0,
			);
			foreach ( $pairs['pairs'] as $pair ) {
				// The form binds the row shown, read once (Codex round 2, A).
				$state                    = $shown_p['print'][ (int) $pair['id'] ];
				$view['pairs']['pairs'][] = array(
					'id'          => (int) $pair['id'],
					'kind'        => (string) $pair['kind'],
					'live_ref'    => (string) $pair['live_ref'],
					'staging_ref' => (string) $pair['staging_ref'],
					'state'       => (string) $pair['state'],
					'last_run'    => null === $pair['last_run'] ? null : (int) $pair['last_run'],
					'discard'     => $state,
				);
			}
		}

		$notice = AB_MCP_Transfer_Moments::notice( $due );
		if ( null !== $notice ) {
			$view['notice'] = array(
				'moment'  => $notice,
				'text'    => 'm1' === $notice ? $view['report']['m1'] : $view['run']['m3'],
				'edition' => AB_MCP_Transfer_Moments::edition( $notice, 'm1' === $notice ? (int) $view['report']['takeable'] : 0 ),
			);
		}
		return $view;
	}

	/**
	 * The runs the page shows whatever page of the list it is on (Codex
	 * round 1 of branch 12, B: ten newer runs of the way back pushed the run
	 * that wrote off the list): every run that is not closed (open,
	 * interrupted, going back), and every closed run of the type push whose
	 * backups the retention contract keeps and which still has a write step
	 * no way back named. Not the ones on the page already ($on). The newest
	 * first; at most PINNED of them, and how many runs whose backups are
	 * kept were not read beyond (more), each once.
	 *
	 * @param array|null      $keep AB_MCP_Sync_Schema::backup_rows().
	 * @param array<int,true> $on   The runs on the page.
	 * @return array{runs:array[],more:int} runs as run_row() each.
	 */
	public static function pinned( $keep, array $on ) {
		$out = array(
			'runs' => array(),
			'more' => 0,
		);
		$ids = is_array( $keep ) ? array_keys( array_filter( $keep ) ) : array();
		rsort( $ids );
		$ids = array_values( array_filter( $ids, static fn( $id ) => ! isset( $on[ (int) $id ] ) ) );
		foreach ( $ids as $i => $id ) {
			if ( count( $out['runs'] ) >= self::PINNED ) {
				// Not read: kept runs not on the page that may need acting on.
				$out['more'] = count( $ids ) - $i;
				break;
			}
			$run = AB_MCP_Sync_Run::get( (int) $id );
			if ( ! is_array( $run ) ) {
				continue;
			}
			$closed = in_array( (string) $run['state'], AB_MCP_Sync_Schema::CLOSED, true );
			if ( ! $closed || ( 'push' === $run['type'] && self::way_back_open( $run ) ) ) {
				$out['runs'][] = self::run_row( $run, $keep );
			}
		}
		return $out;
	}

	/**
	 * Whether a run that wrote has a write step no way back named yet: one
	 * with a journal and a backup (it wrote), whose seq no step of the way
	 * back takes back, in the run itself or in a run of the type revert made
	 * for it, whatever its state (a step goes back once,
	 * AB_MCP_Sync_Revert).
	 *
	 * @param array $run The run.
	 * @return bool
	 */
	public static function way_back_open( array $run ) {
		$steps = AB_MCP_Sync_Run::steps( (int) $run['run_id'] );
		if ( is_wp_error( $steps ) ) {
			return false;
		}
		$ways   = array( $steps );
		$others = AB_MCP_Sync_Run::reverts_of( (int) $run['run_id'] );
		foreach ( is_wp_error( $others ) ? array() : $others as $other ) {
			$other_steps = AB_MCP_Sync_Run::steps( (int) $other['run_id'] );
			$ways[]      = is_wp_error( $other_steps ) ? array() : $other_steps;
		}
		$named = array();
		foreach ( $ways as $way ) {
			foreach ( $way as $step ) {
				if ( 'revert' === $step['op'] && is_array( $step['parts'] ) && isset( $step['parts']['forward_seq'] ) && ( ! isset( $step['parts']['forward_run'] ) || (int) $step['parts']['forward_run'] === (int) $run['run_id'] ) ) {
					$named[ (int) $step['parts']['forward_seq'] ] = true;
				}
			}
		}
		foreach ( $steps as $step ) {
			$journal = is_array( $step['parts'] ) && isset( $step['parts']['object'] ) ? $step['parts'] : null;
			if ( 'write' === $step['op'] && null !== $journal && ! in_array( $step['state'], array( 'planned', 'skipped' ), true ) && is_array( $journal['backup'] ?? null ) && ! isset( $named[ (int) $step['seq'] ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The last report as the page shows it: when, until when, its counts,
	 * how many object pairs a run can take over, and M1.
	 *
	 * @param array $report AB_MCP_Sync_Report::latest().
	 * @return array
	 */
	public static function report_view( array $report ) {
		$index   = is_array( $report['index'] ) ? $report['index'] : null;
		$actions = null !== $index && isset( $index['counts']['actions'] ) && is_array( $index['counts']['actions'] ) ? $index['counts']['actions'] : array();
		$take    = 0;
		foreach ( self::TAKEABLE as $action ) {
			$take += (int) ( $actions[ $action ] ?? 0 );
		}
		return array(
			'report_id' => (string) $report['report_id'],
			'created'   => (int) $report['created'],
			'expires'   => (int) $report['expires'],
			'done'      => null !== $index,
			'total'     => null !== $index ? (int) ( $index['total'] ?? 0 ) : 0,
			'actions'   => array_map( 'intval', $actions ),
			'takeable'  => $take,
			'blocked'   => null !== $index && isset( $index['blocked'] ) && is_array( $index['blocked'] ) ? count( $index['blocked'] ) : 0,
			'm1'        => null !== $index ? AB_MCP_Transfer_Moments::m1( $take ) : null,
		);
	}

	/**
	 * A run in the list: its state, its steps by state, how long its backups
	 * are kept, M2, and whether a person may abort it here.
	 *
	 * @param array           $run  The run.
	 * @param array|null      $keep AB_MCP_Sync_Schema::backup_rows().
	 * @param int|null        $now  Time; now when null.
	 * @return array
	 */
	public static function run_row( array $run, $keep, $now = null ) {
		$steps = AB_MCP_Sync_Run::steps( (int) $run['run_id'] );
		$steps = is_wp_error( $steps ) ? array() : $steps;
		return array(
			'run_id'  => (int) $run['run_id'],
			'type'    => (string) $run['type'],
			'state'   => (string) $run['state'],
			'created' => (int) $run['created'],
			'closed'  => (int) $run['closed'],
			'counts'  => self::counts( $steps ),
			'steps'   => count( $steps ),
			'backups' => self::backups( $run, $keep, $now ),
			'm2'      => AB_MCP_Transfer_Moments::m2( $run, count( $steps ) ),
			'abort'   => 'push' === $run['type'] && 'open' === $run['state'],
			// Going on calls wp_site_revert, which needs the key (Codex round
			// 2 of branch 12, B).
			'go_on'   => 'revert' === $run['type'] && 'open' === $run['state'] && null === AB_MCP_Sync_Run::owner_refusal( $run ) && self::keyed(),
		);
	}

	/**
	 * How long the backups of a run are kept, by the retention contract
	 * (decision 20; AB_MCP_Sync_Schema::backup_rows()): open (kept while the
	 * run is not closed), last_three (one of the last three qualified
	 * closes, without an age), until (the period its close got), kept (a
	 * close without a stored period, kept as long as it is there), way_back
	 * (a way back that is not closed needs them), gone (the clean-up removes
	 * them), unknown (the table could not be read: everything is kept).
	 *
	 * @param array      $run  The run.
	 * @param array|null $keep AB_MCP_Sync_Schema::backup_rows().
	 * @param int|null   $now  Time; now when null.
	 * @return array{state:string,until:int}
	 */
	public static function backups( array $run, $keep, $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$id  = (int) $run['run_id'];
		if ( ! in_array( (string) $run['state'], AB_MCP_Sync_Schema::CLOSED, true ) ) {
			return array( 'state' => 'open', 'until' => 0 );
		}
		if ( ! is_array( $keep ) ) {
			return array( 'state' => 'unknown', 'until' => 0 );
		}
		if ( empty( $keep[ $id ] ) ) {
			return array( 'state' => 'gone', 'until' => 0 );
		}
		$until = (int) $run['keep_until'];
		if ( 0 === $until ) {
			return array( 'state' => 'kept', 'until' => 0 );
		}
		if ( $until > $now ) {
			return array( 'state' => 'until', 'until' => $until );
		}
		return (int) $run['forward_closed_at'] > 0
			? array( 'state' => 'last_three', 'until' => 0 )
			: array( 'state' => 'way_back', 'until' => 0 );
	}

	/**
	 * One run with its steps (the journal, AB_MCP_Sync_Run::shown()), its
	 * rest, M2 and M3, and, with $forms, the way back of each pair a person
	 * can take here (a closed run of the type push), each with the state of
	 * its plan for the form.
	 *
	 * @param array $run   The run.
	 * @param bool  $forms Whether to plan the way back of its pairs.
	 * @return array
	 */
	public static function run_view( array $run, $forms = false ) {
		// One reading of the steps for the journal, the print of the form,
		// the rest and the pairs taken back (Codex round 2 of branch 12, A:
		// two readings let the form carry the print of a later interruption
		// beside the journal of an earlier one).
		// The print through AB_MCP_Sync_Shown::shown(), the side of the page
		// of the helper the close goes through as well.
		$read  = AB_MCP_Sync_Shown::shown(
			static function () use ( $run ) {
				return AB_MCP_Sync_Run::steps( (int) $run['run_id'] );
			},
			static function ( array $steps ) use ( $run ) {
				return 'interrupted' === $run['state'] ? AB_MCP_Sync_Run::interruption_print( $run, $steps ) : null;
			}
		);
		$shown = is_wp_error( $read ) ? $read : AB_MCP_Sync_Run::shown( $run, $read['read'] );
		$steps = is_wp_error( $read ) ? array() : $read['read'];
		$back  = self::pairs_back( $run, $steps );
		$out   = array(
			'run'     => $run,
			'shown'   => is_wp_error( $shown ) ? null : $shown,
			'error'   => is_wp_error( $shown ) ? $shown->get_error_message() : null,
			'rest'    => self::rest( $run, $steps ),
			'back'    => $back,
			'm2'      => AB_MCP_Transfer_Moments::m2( $run, count( $steps ) ),
			'm3'      => 'push' === $run['type'] ? AB_MCP_Transfer_Moments::m3( (int) $run['run_id'], $back ) : null,
			'pairs'   => array(),
			'no_back' => null,
			// The interruption as the page shows it, for the form «Close as
			// checked» (AB_MCP_Sync_Run::interruption_print()).
			'print'   => is_wp_error( $read ) ? null : $read['print'],
			// The object each step of the way back takes back, from the step
			// that wrote it (the journal of a step of the way back names only
			// that step; its outcome stands in its result).
			'back_of' => self::back_of( $run, $steps ),
		);
		if ( ! $forms || 'push' !== $run['type'] || ! in_array( (string) $run['state'], AB_MCP_Sync_Schema::CLOSED, true ) ) {
			return $out;
		}
		$plan = AB_MCP_Sync_Revert::plan_for_person( array( 'run_id' => (int) $run['run_id'] ) );
		if ( is_wp_error( $plan ) ) {
			$out['no_back'] = $plan->get_error_message();
			return $out;
		}
		foreach ( array_slice( $plan['shown'], 0, self::TAKE_BACK ) as $entry ) {
			$one             = AB_MCP_Sync_Revert::plan_for_person(
				array(
					'run_id' => (int) $run['run_id'],
					'steps'  => array( (int) $entry['seq'] ),
				)
			);
			$out['pairs'][] = $entry + array( 'plan_state' => is_wp_error( $one ) ? null : (string) $one['state'] );
		}
		return $out;
	}

	/**
	 * For each step of the way back of a run: the step it takes back and that
	 * step's object (kind, ref here), by seq.
	 *
	 * @param array   $run   The run.
	 * @param array[] $steps Its steps.
	 * @return array<int,array{run:int,seq:int,kind:string,live_ref:string}>
	 */
	public static function back_of( array $run, array $steps ) {
		$out = array();
		foreach ( $steps as $step ) {
			if ( 'revert' !== $step['op'] || ! is_array( $step['parts'] ) || ! isset( $step['parts']['forward_seq'] ) ) {
				continue;
			}
			$forward_run = isset( $step['parts']['forward_run'] ) ? (int) $step['parts']['forward_run'] : (int) $run['run_id'];
			$forward     = AB_MCP_Sync_Run::step( $forward_run, (int) $step['parts']['forward_seq'] );
			$object      = is_array( $forward ) && is_array( $forward['parts'] ) && is_array( $forward['parts']['object'] ?? null ) ? $forward['parts']['object'] : array();
			$out[ (int) $step['seq'] ] = array(
				'run'      => $forward_run,
				'seq'      => (int) $step['parts']['forward_seq'],
				'kind'     => (string) ( $object['kind'] ?? '' ),
				'live_ref' => isset( $object['live_ref'] ) && null !== $object['live_ref'] ? (string) $object['live_ref'] : '',
			);
		}
		return $out;
	}

	/**
	 * The pairs of a run taken back: the steps of the way back that took a
	 * step of it back and are done, in the run itself and in every run of
	 * the type revert made for it (M3).
	 *
	 * @param array   $run   The run.
	 * @param array[] $steps Its steps.
	 * @return int
	 */
	public static function pairs_back( array $run, array $steps ) {
		$back = 0;
		$ways = array( $steps );
		$runs = AB_MCP_Sync_Run::reverts_of( (int) $run['run_id'] );
		foreach ( is_wp_error( $runs ) ? array() : $runs as $other ) {
			$other_steps = AB_MCP_Sync_Run::steps( (int) $other['run_id'] );
			$ways[]      = is_wp_error( $other_steps ) ? array() : $other_steps;
		}
		foreach ( $ways as $way ) {
			foreach ( $way as $step ) {
				if ( 'revert' !== $step['op'] || 'done' !== $step['state'] || ! is_array( $step['parts'] ) ) {
					continue;
				}
				if ( isset( $step['parts']['forward_run'] ) && (int) $step['parts']['forward_run'] !== (int) $run['run_id'] ) {
					continue;
				}
				++$back;
			}
		}
		return $back;
	}

	/**
	 * The proof of the protected store, as the page shows it (decision 7):
	 * where it is and since when, or why there is none and the way; for the
	 * folder in the uploads the rule a host on nginx needs.
	 *
	 * @return array{available:bool,where:string|null,checked:int,rule:string|null,message:string|null}
	 */
	public static function store() {
		$proof = AB_MCP_Protected_Store::proof();
		$rule  = null;
		if ( 'uploads' === $proof['where'] ) {
			$rule = __( 'The store lies in the folder ab-mcp-store-… of the uploads folder, which its .htaccess (Apache, LiteSpeed) or web.config (IIS) closes to the web; the self-check found it closed. On nginx the web server reads neither: there the host adds a rule such as location ~ /ab-mcp-store- { deny all; }. The safe place is the folder ab-mcp-store beside the WordPress folder, outside the web root, where PHP may create it.', 'alphabridge-mcp' );
		}
		return array(
			'available' => (bool) $proof['available'],
			'where'     => $proof['where'],
			'checked'   => (int) $proof['checked'],
			'rule'      => $rule,
			'message'   => $proof['message'],
		);
	}

	/* ---------------------------------------------- what a person does */

	/**
	 * Abort an open run of the type push (decision 15: a person with
	 * manage_options in wp-admin), through the abort of the tool, under the
	 * lock of the write boundary (AB_MCP_Sync_Engine::abort()).
	 *
	 * @param int $run_id Run.
	 * @return string|WP_Error The outcome in words.
	 */
	public static function abort( $run_id ) {
		self::load();
		$run = AB_MCP_Sync_Run::get( (int) $run_id );
		if ( ! is_array( $run ) ) {
			return is_wp_error( $run ) ? $run : AB_MCP_Sync_Run::unknown( (int) $run_id );
		}
		if ( 'push' !== $run['type'] ) {
			/* translators: %d: run number. */
			return new WP_Error( 'ab_mcp_sync_run_state', sprintf( __( 'Run %d is a run of the way back: it is not aborted, it runs to its end. Go on with it with wp_site_revert action=next over the connection that began it, or with «Go on with the way back» on the page AlphaBridge → Staging where it was begun there.', 'alphabridge-mcp' ), (int) $run_id ) );
		}
		$closed = AB_MCP_Sync_Engine::abort( $run );
		if ( is_wp_error( $closed ) ) {
			return $closed;
		}
		/* translators: %d: run number. */
		return sprintf( __( 'Run %d is closed as aborted. What its steps wrote stays as written; its pairs can be taken back one by one below.', 'alphabridge-mcp' ), (int) $run_id );
	}

	/**
	 * Close an interrupted run as checked (decision 18, way a), through
	 * AB_MCP_Sync_Run::close_checked(), under the lock of the write boundary,
	 * only where it is still the interruption the page showed (its print,
	 * checked under the lock).
	 *
	 * @param int    $run_id    Run.
	 * @param string $print     The print of the interruption the page showed.
	 * @param bool   $confirmed True only after the nonce of the form was checked.
	 * @return string|WP_Error
	 */
	public static function close_checked( $run_id, $print, $confirmed ) {
		self::load();
		$closed = AB_MCP_Sync_Run::close_checked( (int) $run_id, get_current_user_id(), $confirmed, (string) $print );
		if ( is_wp_error( $closed ) ) {
			return $closed;
		}
		/* translators: %d: run number. */
		return sprintf( __( 'Run %d is closed as checked. Nothing was written; the pairs of its steps left begun are unclear until a person aligns them (wp_site_pairs), and the site takes a new run again.', 'alphabridge-mcp' ), (int) $run_id );
	}

	/**
	 * Take one pair of a closed run back (decision 35, «Rückweg je Paar mit
	 * Nonce»): the way back the person saw begun (AB_MCP_Sync_Revert::
	 * begin_for_person()), then gone on with as the tool goes on, one step
	 * per call of action=next, until the run of the way back is closed.
	 *
	 * @param int    $run_id    The run that wrote.
	 * @param int    $seq       Its write step.
	 * @param string $state     The state of the plan the page showed.
	 * @param bool   $confirmed True only after the nonce of the form was checked.
	 * @return string|WP_Error
	 */
	public static function take_back( $run_id, $seq, $state, $confirmed ) {
		self::load();
		$begun = AB_MCP_Sync_Revert::begin_for_person(
			array(
				'run_id' => (int) $run_id,
				'steps'  => array( (int) $seq ),
			),
			$state,
			$confirmed
		);
		if ( is_wp_error( $begun ) ) {
			return $begun;
		}
		return self::go_on( (int) $begun['run_id'] );
	}

	/**
	 * Go on with a way back this person began here (its run belongs to their
	 * account over wp-admin): action=next of the tool until it is closed, at
	 * most NEXT_CALLS calls.
	 *
	 * @param int $run_id The run of the way back.
	 * @return string|WP_Error
	 */
	public static function go_on( $run_id ) {
		self::load();
		$answer = null;
		for ( $i = 0; $i < self::NEXT_CALLS; $i++ ) {
			$answer = AB_MCP_Sync_Revert::call(
				array(
					'action' => 'next',
					'run_id' => (int) $run_id,
				)
			);
			if ( is_wp_error( $answer ) ) {
				return $answer;
			}
			if ( in_array( (string) $answer['state'], AB_MCP_Sync_Schema::CLOSED, true ) ) {
				break;
			}
		}
		if ( ! is_array( $answer ) || ! in_array( (string) $answer['state'], AB_MCP_Sync_Schema::CLOSED, true ) ) {
			/* translators: %d: run number. */
			return sprintf( __( 'The way back (run %d) goes on: it is not closed yet. Go on with it in the list of the runs.', 'alphabridge-mcp' ), (int) $run_id );
		}
		$rests = isset( $answer['rests'] ) && is_array( $answer['rests'] ) ? count( $answer['rests'] ) : 0;
		if ( 'closed_reverted' === $answer['state'] ) {
			/* translators: %d: run number of the way back. */
			return sprintf( __( 'Taken back (run %d of the way back): the pair is as it was before the run.', 'alphabridge-mcp' ), (int) $run_id );
		}
		if ( $rests > 0 ) {
			/* translators: 1: run number of the way back, 2: objects left as a rest. */
			return sprintf( _n( 'The way back (run %1$d) is closed with findings: %2$d object the run made stays as a rest; remove it by hand in wp-admin where it should go. Its steps below say what was left and why.', 'The way back (run %1$d) is closed with findings: %2$d objects the run made stay as a rest; remove them by hand in wp-admin where they should go. Its steps below say what was left and why.', $rests, 'alphabridge-mcp' ), (int) $run_id, $rests );
		}
		/* translators: %d: run number of the way back. */
		return sprintf( __( 'The way back (run %d) is closed with findings: a part changed here since the run wrote it, or did not read back, was left with its value before. Its steps below say what was left and why.', 'alphabridge-mcp' ), (int) $run_id );
	}

	/**
	 * Discard a pair of the source of the confirmed key, through the tool's
	 * discard (AB_MCP_Sync_Pairs_Tool::discard_as_shown()): one plan on the
	 * state the page showed (the snapshot of the pair, as a token binds it),
	 * deleted conditionally under the lock of the write boundary.
	 *
	 * @param int    $pair_id Pair.
	 * @param string $state   The state the page showed.
	 * @return string|WP_Error
	 */
	public static function discard( $pair_id, $state ) {
		self::load();
		$done = AB_MCP_Sync_Pairs_Tool::discard_as_shown(
			array(
				'action'   => 'discard',
				'pair_ids' => array( (int) $pair_id ),
			),
			$state
		);
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		/* translators: %d: pair. */
		return sprintf( __( 'Pair %d is discarded: its objects stay as they are on both sites, each without a pair from now on.', 'alphabridge-mcp' ), (int) $pair_id );
	}

	/* ---------------------------------------------------------- outcome */

	/**
	 * Keep the outcome of a form for the page of this user.
	 *
	 * @param string|WP_Error $outcome Outcome.
	 * @return void
	 */
	public static function keep_outcome( $outcome ) {
		set_transient(
			self::OUTCOME . (int) get_current_user_id(),
			array(
				'ok'   => ! is_wp_error( $outcome ),
				'text' => is_wp_error( $outcome ) ? $outcome->get_error_message() : (string) $outcome,
			),
			self::OUTCOME_TTL
		);
	}

	/**
	 * The outcome kept for this user, once.
	 *
	 * @return array{ok:bool,text:string}|null
	 */
	private static function take_outcome() {
		$key  = self::OUTCOME . (int) get_current_user_id();
		$kept = get_transient( $key );
		if ( ! is_array( $kept ) || ! isset( $kept['text'] ) ) {
			return null;
		}
		delete_transient( $key );
		return array(
			'ok'   => ! empty( $kept['ok'] ),
			'text' => (string) $kept['text'],
		);
	}

	/* ---------------------------------------------------------- helpers */

	/**
	 * Steps by state.
	 *
	 * @param array[] $steps Steps.
	 * @return array<string,int>
	 */
	private static function counts( array $steps ) {
		$counts = array_fill_keys( AB_MCP_Sync_Run::STEP_STATES, 0 );
		foreach ( $steps as $step ) {
			$counts[ $step['state'] ] = ( $counts[ $step['state'] ] ?? 0 ) + 1;
		}
		return $counts;
	}

	/**
	 * @param int $at Seconds.
	 * @return string UTC, or '' for none.
	 */
	private static function iso( $at ) {
		return $at > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $at ) : '';
	}
}
