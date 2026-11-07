<?php
/**
 * wp_site_push on the production site (Z8, decisions 15 to 20, 32 and 33 of
 * the plan of 01.11.2026; branch 8): a run that writes objects of a report
 * (wp_site_diff) onto this site, one step per call, with a backup and a
 * journal for each, and the way back (wp_site_revert).
 *
 * ACTIONS. plan (the confirmation in the same call, decision 15): the
 * report (report_id), the entries to write (select, or all for every entry
 * a takeover writes), the choice for each conflict (choices: staging_wins
 * writes the staging version; keep_live and leave_open write nothing), the
 * entries taken over as draft (as_draft, the one confirmed choice of
 * decision 21) and the packages of objects of the staging site for this
 * report (packages, wp_site_export with purpose=objects). Without
 * confirm_token the call answers with the preview and the token; with it,
 * it makes the run and answers with its id. The state the token is bound
 * to is the SHA-256 over the report_hash, the selection, the choices, the
 * print of the pair of sites and the hashes of the objects of this site as
 * they are now: a change of any refuses the token. A confirmed call that
 * meets an open run of the same plan, account and connection gets that
 * run (the replay); any other run that is not closed refuses it with the
 * way. next runs the next step whose dependencies are done; step a named
 * one (refused while a dependency is open); status shows a run with its
 * steps, journal and rests; abort closes an open run (what it wrote stays;
 * the way back is wp_site_revert).
 *
 * WHAT A PLAN HOLDS (this version): entries of the kind post that are posts
 * or pages (AB_MCP_Sync_Post_Writer); every other entry chosen is named as
 * locked with its reason and stage and is no step. The steps: the check
 * before the first write (decision 16; the means of 15.11), one write step
 * per object in the order of the graph of their references (an object
 * created in the same run before the one that refers to it), the caches,
 * the check of the run (decision 33). A report without the environment of
 * the two sites is run all the same: the plan says so («environment not
 * compared»), since no object of the environment is written.
 *
 * OWNERSHIP: next, step, status and abort for the account and connection
 * that made the run only (AB_MCP_Sync_Run::owner_refusal()). An interrupted
 * run (decision 18) takes no next and no step: a person closes it as
 * checked on the page «Staging», or wp_site_revert takes its parts back.
 *
 * Messages are English without gettext, as the other classes of the
 * takeover; each names the way.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-run.php';
require_once __DIR__ . '/class-sync-engine.php';
require_once __DIR__ . '/class-sync-report.php';
require_once __DIR__ . '/class-sync-resources.php';

/**
 * Class AB_MCP_Sync_Push
 */
final class AB_MCP_Sync_Push {

	/** The actions. */
	const ACTIONS = array( 'plan', 'next', 'step', 'status', 'abort' );

	/** The choices for a conflict. */
	const CHOICES = array( 'staging_wins', 'keep_live', 'leave_open' );

	/** Objects one run writes at most (the steps of a run less the four of the frame). */
	const MAX_OBJECTS = 1996;

	/** Steps a preview names at most (all are counted). */
	const NAMED = 200;

	/** Bytes a step books for its journal besides the object. */
	const JOURNAL_BYTES = 65536;

	/** Seconds a run stays open at most without a step (its expires). */
	const EXPIRES = 86400;

	/* ------------------------------------------------- the confirmation */

	/**
	 * Whether a call needs the token: a strict false for next, step, status
	 * and abort; true for plan and anything else (an unknown action is
	 * refused by state()).
	 *
	 * @param array $a Arguments.
	 * @return bool
	 */
	public static function when( array $a ) {
		return ! ( isset( $a['action'] ) && in_array( $a['action'], array( 'next', 'step', 'status', 'abort' ), true ) );
	}

	/**
	 * The state a token of plan is bound to.
	 *
	 * @param array $a Arguments.
	 * @return string|WP_Error
	 */
	public static function state( array $a ) {
		$plan = self::plan( $a );
		return is_wp_error( $plan ) ? $plan : $plan['state'];
	}

	/**
	 * The preview of plan: the steps, what is locked, the means.
	 *
	 * @param array $a Arguments.
	 * @return array|WP_Error
	 */
	public static function preview( array $a ) {
		$plan = self::plan( $a );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		return self::shown( $plan );
	}

	/* ---------------------------------------------------------- the tool */

	/**
	 * wp_site_push.
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
			return new WP_Error( 'ab_mcp_sync_push_action', 'wp_site_push takes action=plan (with report_id and the selection; the token in the same call), next, step (with run_id and seq), status or abort (with run_id). Pass one of them.' );
		}
		$source = AB_MCP_Sync_Proposal::source_id();
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( 'status' === $action && ! isset( $a['run_id'] ) ) {
			return self::overview();
		}
		$run = self::own_run( $a );
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
			$shown['next'] = self::status_way( $run );
			return $shown;
		}
		if ( 'abort' === $action ) {
			$closed = AB_MCP_Sync_Engine::abort( $run );
			if ( is_wp_error( $closed ) ) {
				return $closed;
			}
			$shown = AB_MCP_Sync_Run::shown( $closed );
			if ( is_wp_error( $shown ) ) {
				return $shown;
			}
			$shown['next'] = 'The run is closed as aborted. What its steps wrote stays as written (their pairs have the new bases); wp_site_revert with this run_id takes it back pair by pair.';
			return $shown;
		}
		// next, step.
		if ( 'interrupted' === $run['state'] ) {
			return self::interrupted( $run );
		}
		if ( isset( $run['running'] ) ) {
			return new WP_Error( 'ab_mcp_sync_run_busy', sprintf( 'Step %d of the run is being written right now (its lock is held); call again when it has answered.', (int) $run['running'] ) );
		}
		if ( 'open' !== $run['state'] || 'push' !== $run['type'] ) {
			return new WP_Error( 'ab_mcp_sync_run_state', sprintf( 'The run %1$d is %2$s (%3$s) and takes no step here: a run of the way back goes on with wp_site_revert, a closed run no more. Ask for its status.', (int) $run['run_id'], $run['state'], $run['type'] ) );
		}
		$seq = null;
		if ( 'step' === $action ) {
			if ( ! isset( $a['seq'] ) || ! is_numeric( $a['seq'] ) || (int) $a['seq'] < 1 ) {
				return new WP_Error( 'ab_mcp_sync_push_seq', 'action=step takes seq, the number of the step to run (status lists them). Pass it, or use action=next.' );
			}
			$seq = (int) $a['seq'];
		}
		return AB_MCP_Sync_Engine::next( $run, $seq );
	}

	/* ------------------------------------------------------------ plan */

	/**
	 * The plan, read fresh: the report, the entries chosen, the objects of
	 * the staging site, the targets as they are now, the graph, the means,
	 * the state the token is bound to.
	 *
	 * @param array $a Arguments.
	 * @return array|WP_Error
	 */
	public static function plan( array $a ) {
		$action = isset( $a['action'] ) ? $a['action'] : null;
		if ( 'plan' !== $action ) {
			return new WP_Error( 'ab_mcp_sync_push_action', 'wp_site_push takes action=plan (with report_id and the selection; the token in the same call), next, step (with run_id and seq), status or abort (with run_id). Pass one of them.' );
		}
		$source = AB_MCP_Sync_Proposal::source_id();
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$anchor = AB_MCP_Sync_Anchor::active();
		$print  = AB_MCP_Sync_Anchor::pair_print();
		if ( null === $anchor || null === $print ) {
			return new WP_Error( 'ab_mcp_sync_pairs_no_anchor', 'This site has no confirmed key of a staging site, so no run is planned. Ask the person to confirm it on the page AlphaBridge → Staging of this site; then call again.' );
		}
		$status = AB_MCP_Sync_Schema::status();
		if ( ! $status['available'] ) {
			return new WP_Error( 'ab_mcp_staging_unavailable', sprintf( 'The tables of the takeover are not available on this site (%s): no run begins. Deactivate and activate AlphaBridge in wp-admin so the tables are made, or ask the host to let the database user create tables.', (string) $status['reason'] ) );
		}
		$found = AB_MCP_Sync_Report::find( isset( $a['report_id'] ) ? $a['report_id'] : null, $source );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		if ( null === $found['index'] ) {
			return new WP_Error( 'ab_mcp_sync_report_not_done', 'This report is not worked out yet: the inventory of this site is still being read. Call wp_site_diff with this report_id until it answers done true, then plan.' );
		}
		$report_hash = (string) $found['index']['report_hash'];
		// The selection.
		$select = self::numbers( isset( $a['select'] ) ? $a['select'] : array() );
		$drafts = self::numbers( isset( $a['as_draft'] ) ? $a['as_draft'] : array() );
		$all    = isset( $a['all'] ) && ( true === $a['all'] || 'true' === $a['all'] || 1 === $a['all'] );
		if ( null === $select || null === $drafts ) {
			return self::selection( 'select and as_draft are lists of entry numbers of the report.' );
		}
		$choices = array();
		foreach ( isset( $a['choices'] ) && is_array( $a['choices'] ) ? $a['choices'] : array() as $choice ) {
			if ( ! is_array( $choice ) || ! isset( $choice['entry'], $choice['choice'] ) || null === self::numbers( array( $choice['entry'] ) ) || ! in_array( $choice['choice'], self::CHOICES, true ) ) {
				return self::selection( 'A choice is an object of entry (its number) and choice (staging_wins, keep_live or leave_open).' );
			}
			$choices[ (int) $choice['entry'] ] = (string) $choice['choice'];
		}
		if ( array() === $select && array() === $choices && ! $all ) {
			return self::selection( 'Nothing is chosen: pass select (entry numbers), choices (for conflicts) or all=true.' );
		}
		$packages = isset( $a['packages'] ) ? $a['packages'] : array();
		if ( ! is_array( $packages ) || ! array_is_list( $packages ) || array() === $packages || count( $packages ) > AB_MCP_Sync_Proposal::MAX_PACKAGES || array() !== array_filter( $packages, static fn( $id ) => ! is_string( $id ) || 1 !== preg_match( AB_MCP_Sync_Key::ID, $id ) ) ) {
			return new WP_Error( 'ab_mcp_sync_push_packages', 'A run writes from packages of objects of the staging site: pass packages, the package_ids (32 hex each) this site took over with wp_site_import_chunk (purpose=objects, with this report_hash), made on the staging site with wp_site_export (purpose=objects, report_hash and the refs of the entries).' );
		}
		// The entries.
		$numbers = array_values( array_unique( array_merge( $select, array_keys( $choices ), $drafts ) ) );
		if ( $all ) {
			$entries = self::all_entries( $found );
			if ( is_wp_error( $entries ) ) {
				return $entries;
			}
			foreach ( $numbers as $n ) {
				if ( ! isset( $entries[ $n ] ) ) {
					return self::selection( sprintf( 'The report has no entry %d (it has %d).', $n, (int) $found['index']['total'] ) );
				}
			}
		} else {
			$entries = AB_MCP_Sync_Proposal::entries( $found, $numbers );
			if ( is_wp_error( $entries ) ) {
				return $entries;
			}
			foreach ( $numbers as $n ) {
				if ( ! isset( $entries[ $n ] ) ) {
					return self::selection( sprintf( 'The report has no entry %d (it has %d).', $n, (int) $found['index']['total'] ) );
				}
			}
		}
		foreach ( $drafts as $n ) {
			if ( ! in_array( $n, $select, true ) && ! isset( $choices[ $n ] ) && ! $all ) {
				return self::selection( sprintf( 'Entry %d is in as_draft but not chosen: name it in select as well.', $n ) );
			}
		}
		// The staging lines of the report (their hashes bind the packages of
		// objects to what the report compared).
		$staging = AB_MCP_Sync_Report::packages( $found['head']['packages'] );
		if ( is_wp_error( $staging ) ) {
			return $staging;
		}
		$hashes = array();
		foreach ( $staging['lines'] as $line ) {
			if ( is_array( $line ) && isset( $line['kind'], $line['ref'], $line['hash'] ) ) {
				$hashes[ (string) $line['kind'] . "\0" . AB_MCP_Sync_Post_Writer::bytes( $line['ref'] ) ] = (string) $line['hash'];
			}
		}
		// The objects of the staging site.
		$lines = AB_MCP_Sync_Engine::lines(
			array(
				'plan'        => array( 'packages' => $packages ),
				'report_hash' => $report_hash,
			)
		);
		if ( is_wp_error( $lines ) ) {
			return $lines;
		}
		// Each entry: a step, or locked.
		$items  = array();
		$locked = array();
		$skip   = array();
		ksort( $entries );
		foreach ( $entries as $n => $entry ) {
			$n = (int) $n;
			if ( ! $all && ! in_array( $n, $select, true ) && ! isset( $choices[ $n ] ) ) {
				continue;
			}
			$verdict = self::verdict( $entry, isset( $choices[ $n ] ) ? $choices[ $n ] : null, in_array( $n, $drafts, true ), $all );
			if ( null === $verdict ) {
				continue;
			}
			if ( isset( $verdict['locked'] ) ) {
				if ( $all && ! in_array( $n, $select, true ) && ! isset( $choices[ $n ] ) ) {
					// all takes what a takeover writes; what it cannot is named, not refused.
					$skip[] = $verdict['locked'];
				} else {
					$locked[] = $verdict['locked'];
				}
				continue;
			}
			$item              = $verdict['item'];
			$item['source_id'] = $source;
			$key               = $item['kind'] . "\0" . $item['staging_ref'];
			if ( ! isset( $hashes[ $key ] ) ) {
				return new WP_Error( 'ab_mcp_sync_push_report', sprintf( 'The report holds no staging line for the %1$s %2$s of entry %3$d (its packages of the inventory are gone or changed). Make a new report (wp_site_diff) and plan again.', $item['kind'], $item['staging_ref'], $n ) );
			}
			$item['staging_hash'] = $hashes[ $key ];
			$line                 = AB_MCP_Sync_Engine::line_of( $item, $lines );
			if ( is_wp_error( $line ) ) {
				return $line;
			}
			$item['size'] = isset( $line['size'] ) ? (int) $line['size'] : 0;
			$item['refs'] = array();
			foreach ( isset( $line['refs'] ) && is_array( $line['refs'] ) ? $line['refs'] : array() as $ref ) {
				if ( is_array( $ref ) && isset( $ref['space'], $ref['id'] ) && in_array( $ref['space'], array( 'post', 'term' ), true ) && 'strong' === ( $ref['strength'] ?? null ) ) {
					$item['refs'][] = array( (string) $ref['space'], (string) (int) $ref['id'] );
				}
			}
			$items[ $n ] = $item;
		}
		if ( count( $items ) > self::MAX_OBJECTS ) {
			return self::selection( sprintf( 'One run writes at most %d objects. Plan in parts.', self::MAX_OBJECTS ) );
		}
		if ( array() === $items ) {
			return new WP_Error( 'ab_mcp_sync_push_nothing', sprintf( 'Nothing of the selection is written in this version: %s Choose entries the report marks take_over or create (posts and pages).', array() === $locked ? 'no entry a takeover writes was chosen.' : implode( ' ', array_map( static fn( $l ) => sprintf( 'Entry %1$d (%2$s %3$s): %4$s', $l['n'], $l['kind'], $l['subtype'], $l['reason'] ), array_slice( $locked, 0, 5 ) ) ) ), array( 'locked' => $locked ) );
		}
		// The objects of this site as they are now, and the pairs.
		$asked = array();
		foreach ( $items as $item ) {
			if ( 'update' === $item['op'] ) {
				$asked[] = array(
					'kind' => $item['kind'],
					'ref'  => $item['live_ref'],
				);
			}
		}
		$live = array() === $asked ? array() : AB_MCP_Sync_Proposal::live_now( $asked );
		if ( is_wp_error( $live ) ) {
			return $live;
		}
		$pairs = array();
		foreach ( $items as $n => $item ) {
			if ( 'update' !== $item['op'] ) {
				continue;
			}
			$hash = $live[ $item['kind'] . "\0" . $item['live_ref'] ];
			if ( 'gone' === $hash ) {
				return new WP_Error( 'ab_mcp_sync_push_gone', sprintf( 'The %1$s %2$s of this site (entry %3$d) is gone since the report. Make a new report (wp_site_diff) and plan again.', $item['kind'], $item['live_ref'], $n ) );
			}
			$items[ $n ]['live_hash'] = $hash;
			$pair                     = AB_MCP_Sync_Pairs::get( (int) $item['pair_id'] );
			if ( is_wp_error( $pair ) || null === $pair ) {
				return new WP_Error( 'ab_mcp_sync_push_pair', sprintf( 'The pair %1$d of entry %2$d is no longer there. Make a new report (wp_site_diff) and plan again.', (int) $item['pair_id'], $n ) );
			}
			if ( 'active' !== $pair['state'] ) {
				return new WP_Error( 'ab_mcp_sync_push_pair', sprintf( 'The pair %1$d of entry %2$d is %3$s since the report, and only an active pair is written over. Make a new report (wp_site_diff) and plan again.', (int) $item['pair_id'], $n, $pair['state'] ) );
			}
			$items[ $n ]['pair_hash']       = AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( (int) $item['pair_id'] ) );
			$pairs[ (int) $item['pair_id'] ] = $items[ $n ]['pair_hash'];
		}
		// The order: an object created in the run before the one that refers
		// to it (decision 16); a cycle is locked (mutual references).
		$ordered = self::order( $items );
		if ( is_wp_error( $ordered ) ) {
			return $ordered;
		}
		$items = $ordered['items'];
		foreach ( $ordered['locked'] as $l ) {
			$locked[] = $l;
		}
		if ( array() === $items ) {
			return new WP_Error( 'ab_mcp_sync_push_nothing', 'Nothing of the selection is written: the objects chosen need each other to be created (mutual references, locked until Z11c). Choose objects that do not refer to each other.', array( 'locked' => $locked ) );
		}
		// The steps.
		$bytes = 0;
		$steps = array( array( 'op' => 'check', 'phase' => 1, 'depends' => array() ) );
		$seq   = 1;
		$by_n  = array();
		foreach ( $items as $n => $item ) {
			++$seq;
			$items[ $n ]['seq'] = $seq;
			$by_n[ $n ]         = $seq;
			$bytes             += 2 * (int) $item['size'] + self::JOURNAL_BYTES;
		}
		foreach ( $items as $n => $item ) {
			$depends = array( 1 );
			foreach ( $item['depends_on'] as $m ) {
				$depends[] = $by_n[ $m ];
			}
			$steps[] = array(
				'op'      => 'write',
				'phase'   => 5,
				'depends' => array_values( array_unique( $depends ) ),
				'pair_id' => isset( $item['pair_id'] ) ? $item['pair_id'] : null,
			);
			unset( $items[ $n ]['depends_on'], $items[ $n ]['refs'] );
		}
		$writes  = range( 2, $seq );
		$steps[] = array( 'op' => 'post', 'phase' => 7, 'depends' => array( 1 ) );
		$steps[] = array( 'op' => 'verify', 'phase' => 8, 'depends' => array( 1, $seq + 1 ) );
		unset( $writes );
		// The means (15.11): insufficient refuses the plan with the two ways.
		$resources = AB_MCP_Sync_Resources::check( $bytes );
		if ( AB_MCP_Sync_Resources::INSUFFICIENT === $resources['level'] ) {
			return AB_MCP_Sync_Resources::refusal( $resources );
		}
		sort( $select );
		sort( $drafts );
		ksort( $choices );
		$selection = array(
			'all'      => $all,
			'as_draft' => $drafts,
			'choices'  => $choices,
			'packages' => array_values( array_unique( $packages ) ),
			'select'   => $select,
		);
		sort( $selection['packages'] );
		$plan_hash = hash( 'sha256', AB_MCP_Sync_Canon::encode( array( 'report_hash' => $report_hash ) + $selection ) );
		ksort( $live );
		ksort( $pairs );
		$state = hash(
			'sha256',
			AB_MCP_Sync_Canon::encode(
				array(
					'live'        => $live,
					'pairs'       => $pairs,
					'print'       => $print,
					'report_hash' => $report_hash,
					'selection'   => $selection,
				)
			)
		);
		$plan = array(
			'report_id'   => $found['gid'],
			'report_hash' => $report_hash,
			'source_id'   => $source,
			'key_id'      => (string) $anchor['key_id'],
			'pair_print'  => $print,
			'selection'   => $selection,
			'packages'    => $selection['packages'],
			'objects'     => array_values( $items ),
			'steps'       => $steps,
			'locked'      => $locked,
			'skipped'     => $skip,
			'resources'   => $resources,
			'environment' => isset( $found['index']['environment']['packages'] ) && (int) $found['index']['environment']['packages'] > 0,
			'bytes'       => $bytes,
			'plan_hash'   => $plan_hash,
			'state'       => $state,
		);
		// A run that is not closed: the same plan of this account and
		// connection is given back (the replay of decision 15); another refuses.
		$open = AB_MCP_Sync_Run::open_run();
		if ( is_wp_error( $open ) ) {
			return $open;
		}
		if ( null !== $open ) {
			if ( 'open' === $open['state'] && hash_equals( (string) $open['plan_hash'], $plan_hash ) && null === AB_MCP_Sync_Run::owner_refusal( $open ) ) {
				$plan['existing_run'] = (int) $open['run_id'];
			} else {
				return self::another_open( $open );
			}
		}
		return $plan;
	}

	/**
	 * What an entry of the report is for the plan: an item (create or
	 * update), locked (with reason and stage), or nothing (a choice that
	 * writes nothing).
	 *
	 * @param array       $entry    Entry.
	 * @param string|null $choice   The choice for a conflict.
	 * @param bool        $as_draft Whether it is taken over as draft.
	 * @param bool        $all      Whether the entry comes with all.
	 * @return array|null item, or locked.
	 */
	private static function verdict( array $entry, $choice, $as_draft, $all ) {
		$n      = (int) $entry['n'];
		$action = (string) $entry['action'];
		$lock   = static function ( $reason, $stage = null ) use ( $entry, $n ) {
			return array(
				'locked' => array(
					'n'       => $n,
					'kind'    => (string) $entry['kind'],
					'subtype' => (string) ( $entry['subtype'] ?? '' ),
					'action'  => (string) $entry['action'],
					'result'  => (string) $entry['result'],
					'reason'  => (string) $reason,
					'stage'   => $stage,
				),
			);
		};
		if ( 'choose' === $action ) {
			if ( null === $choice ) {
				if ( $all ) {
					return null;
				}
				return $lock( 'A conflict (or an unclear pair): name the choice in choices (staging_wins, keep_live or leave_open).' );
			}
			if ( 'staging_wins' !== $choice ) {
				return null;
			}
			if ( 'unclear' === $entry['result'] ) {
				return $lock( 'An unclear pair is written over only after a person confirms its bases (wp_site_pairs action=propose, then confirm).' );
			}
		} elseif ( null !== $choice ) {
			return $lock( sprintf( 'Entry %d is no conflict; a choice applies to conflicts only.', $n ) );
		}
		if ( in_array( $action, array( 'locked', 'waits' ), true ) ) {
			$locks = isset( $entry['locks'] ) && is_array( $entry['locks'] ) ? $entry['locks'] : array();
			$first = array() !== $locks ? $locks[0] : null;
			return $lock(
				'locked' === $action
					? ( null === $first ? 'Locked in the report.' : (string) $first['reason'] )
					: 'Waits in the report: ' . implode( ' ', array_map( static fn( $w ) => (string) ( $w['why'] ?? '' ), isset( $entry['waits_for'] ) && is_array( $entry['waits_for'] ) ? array_slice( $entry['waits_for'], 0, 3 ) : array() ) ),
				null === $first ? null : (string) $first['stage']
			);
		}
		if ( ! in_array( $action, array( 'take_over', 'create', 'choose' ), true ) ) {
			if ( $all ) {
				return null;
			}
			return $lock( sprintf( 'The report says %s for this entry: a takeover writes nothing for it.', $action ) );
		}
		if ( 'post' !== $entry['kind'] ) {
			return $lock( sprintf( 'The kind %s is written from a later branch of Z8 (terms: Z9; media: Z10; menus, widget areas, the additional CSS and options: Z11).', (string) $entry['kind'] ), 'term' === $entry['kind'] ? 'Z9' : ( 'attachment' === $entry['kind'] ? 'Z10' : 'Z11' ) );
		}
		if ( ! in_array( (string) ( $entry['subtype'] ?? '' ), AB_MCP_Sync_Post_Writer::TYPES, true ) ) {
			return $lock( sprintf( 'In this version the engine of the kind post writes posts and pages; the type %s follows with Z9.', (string) ( $entry['subtype'] ?? '' ) ), 'Z9' );
		}
		$staging = isset( $entry['staging']['ref'] ) ? (string) $entry['staging']['ref'] : '';
		if ( '' === $staging ) {
			return $lock( 'The staging side of this entry has no ref (held by a credential): nothing is written for it.' );
		}
		$op = 'create' === $action ? 'create' : 'update';
		if ( 'update' === $op && ( ! isset( $entry['pair']['id'] ) || ! isset( $entry['live']['ref'] ) ) ) {
			return $lock( 'The entry names no pair or no object of this site to write over.' );
		}
		return array(
			'item' => array(
				'n'           => $n,
				'kind'        => 'post',
				'subtype'     => (string) $entry['subtype'],
				'op'          => $op,
				'staging_ref' => $staging,
				'live_ref'    => 'update' === $op ? (string) $entry['live']['ref'] : null,
				'pair_id'     => 'update' === $op ? (int) $entry['pair']['id'] : null,
				'pair_hash'   => null,
				'live_hash'   => null,
				'as_draft'    => (bool) $as_draft,
				'choice'      => $choice,
				'action'      => $action,
			),
		);
	}

	/**
	 * The items in the order of their references: a target created in the
	 * same run comes first (depends_on); a cycle is locked.
	 *
	 * @param array $items Items by entry number.
	 * @return array{items:array,locked:array}|WP_Error
	 */
	private static function order( array $items ) {
		$at = array();
		foreach ( $items as $n => $item ) {
			if ( 'create' === $item['op'] ) {
				$at[ 'post' . "\0" . $item['staging_ref'] ] = $n;
			}
		}
		$edges = array();
		foreach ( $items as $n => $item ) {
			$items[ $n ]['depends_on'] = array();
			foreach ( $item['refs'] as list( $space, $id ) ) {
				$key = $space . "\0" . $id;
				if ( isset( $at[ $key ] ) && $at[ $key ] !== $n ) {
					$items[ $n ]['depends_on'][] = $at[ $key ];
					$edges[ $n ][ $at[ $key ] ] = true;
				} elseif ( isset( $at[ $key ] ) ) {
					$edges[ $n ][ $n ] = true;
				}
			}
		}
		$locked = array();
		foreach ( AB_MCP_Sync_Decision::cycles( $edges ) as $members ) {
			$lock = AB_MCP_Sync_Kinds::lock( 'post', 'mutual_references' );
			foreach ( $members as $n ) {
				if ( isset( $items[ $n ] ) ) {
					$locked[] = array(
						'n'       => (int) $n,
						'kind'    => $items[ $n ]['kind'],
						'subtype' => $items[ $n ]['subtype'],
						'action'  => $items[ $n ]['action'],
						'result'  => '',
						'reason'  => $lock['reason'],
						'stage'   => $lock['stage'],
					);
					unset( $items[ $n ] );
				}
			}
		}
		// Whatever depends on a locked member is locked as well.
		$changed = true;
		$rounds  = 0;
		while ( $changed && $rounds < 2000 ) {
			$changed = false;
			++$rounds;
			foreach ( $items as $n => $item ) {
				foreach ( $item['depends_on'] as $m ) {
					if ( ! isset( $items[ $m ] ) ) {
						$locked[] = array(
							'n'       => (int) $n,
							'kind'    => $item['kind'],
							'subtype' => $item['subtype'],
							'action'  => $item['action'],
							'result'  => '',
							'reason'  => sprintf( 'It refers to entry %d, which is locked.', (int) $m ),
							'stage'   => null,
						);
						unset( $items[ $n ] );
						$changed = true;
						break;
					}
				}
			}
		}
		// Topological order: each time the smallest entry whose targets are
		// placed (stable, whatever order the entries came in).
		$ordered = array();
		$placed  = array();
		$left    = $items;
		ksort( $left );
		$rounds = 0;
		while ( array() !== $left && $rounds < self::MAX_OBJECTS + 1 ) {
			++$rounds;
			$progress = false;
			foreach ( $left as $n => $item ) {
				$ready = true;
				foreach ( $item['depends_on'] as $m ) {
					if ( ! isset( $placed[ $m ] ) ) {
						$ready = false;
					}
				}
				if ( $ready ) {
					$ordered[ $n ] = $item;
					$placed[ $n ]  = true;
					unset( $left[ $n ] );
					$progress = true;
					break;
				}
			}
			if ( ! $progress ) {
				return new WP_Error( 'ab_mcp_sync_push_order', 'The objects chosen cannot be ordered by their references (a cycle the lock did not catch). Choose fewer objects.' );
			}
		}
		return array(
			'items'  => $ordered,
			'locked' => $locked,
		);
	}

	/**
	 * Every entry of a report that is done.
	 *
	 * @param array $found AB_MCP_Sync_Report::find().
	 * @return array<int,array>|WP_Error
	 */
	private static function all_entries( array $found ) {
		$out = array();
		foreach ( $found['index']['parts'] as $part ) {
			$list = AB_MCP_Sync_Proposal::read_part( $found['gid'], $part, AB_MCP_Sync_Report::concurrent() );
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			foreach ( $list as $entry ) {
				if ( is_array( $entry ) && isset( $entry['n'] ) ) {
					$out[ (int) $entry['n'] ] = $entry;
				}
			}
		}
		return $out;
	}

	/* ----------------------------------------------------------- begin */

	/**
	 * Make the run of a confirmed plan, or give the run of the same plan
	 * back (the replay).
	 *
	 * @param array $plan plan().
	 * @return array|WP_Error
	 */
	private static function begin( array $plan ) {
		if ( isset( $plan['existing_run'] ) ) {
			$run = AB_MCP_Sync_Run::get( (int) $plan['existing_run'] );
			if ( is_array( $run ) ) {
				AB_MCP_Sync_Run::touch( (int) $run['run_id'], true );
				$shown = AB_MCP_Sync_Run::shown( $run );
				if ( is_wp_error( $shown ) ) {
					return $shown;
				}
				$shown['created_now'] = false;
				$shown['next']        = 'This plan has its run already (the same report, selection, account and connection): go on with it, action=next with this run_id.';
				return $shown;
			}
		}
		$stored = $plan;
		unset( $stored['steps'], $stored['state'], $stored['existing_run'], $stored['resources'], $stored['locked'], $stored['skipped'] );
		$run = AB_MCP_Sync_Run::create(
			array(
				'type'        => 'push',
				'source_id'   => $plan['source_id'],
				'key_id'      => $plan['key_id'],
				'pair_print'  => $plan['pair_print'],
				'report_hash' => $plan['report_hash'],
				'plan_hash'   => $plan['plan_hash'],
				'user_id'     => get_current_user_id(),
				'connection'  => AB_MCP_Sync_Run::connection(),
				'expires'     => AB_MCP_Sync_Run::now() + self::EXPIRES,
				'budget'      => array( 'bytes' => (int) $plan['bytes'] ),
				'plan'        => $stored,
			),
			$plan['steps']
		);
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		// The packages of objects are bound to the run: kept while it is not closed.
		foreach ( $plan['packages'] as $package_id ) {
			self::bind_package( $package_id, (int) $run['run_id'] );
		}
		$shown = AB_MCP_Sync_Run::shown( $run );
		if ( is_wp_error( $shown ) ) {
			return $shown;
		}
		$shown['created_now'] = true;
		$shown['locked']      = $plan['locked'];
		$shown['resources']   = $plan['resources']['level'];
		$shown['next']        = sprintf( 'The run is made with %d step(s) and nothing is written yet. Call action=next with this run_id: the first step checks everything before the first write, each next one writes one object under the lock with its backup and journal, then the caches, then the check of the run closes it. Each step answers with its result; status shows the whole run.', count( $plan['steps'] ) );
		return $shown;
	}

	/**
	 * Bind the group of a package of objects to the run.
	 *
	 * @param string $package_id Package.
	 * @param int    $run_id     Run.
	 */
	private static function bind_package( $package_id, $run_id ) {
		$groups = AB_MCP_Protected_Store::groups( 'in' );
		if ( is_wp_error( $groups ) ) {
			return;
		}
		foreach ( $groups as $group ) {
			if ( AB_MCP_Sync_Import::LABEL . $package_id === (string) $group['label'] ) {
				AB_MCP_Protected_Store::bind( 'in', (string) $group['gid'], $run_id );
			}
		}
	}

	/* ------------------------------------------------------------ shown */

	/**
	 * The preview of a plan.
	 *
	 * @param array $plan plan().
	 * @return array
	 */
	public static function shown( array $plan ) {
		$steps = array();
		$seq   = 0;
		foreach ( $plan['steps'] as $step ) {
			++$seq;
			$shown = array(
				'seq'     => $seq,
				'op'      => $step['op'],
				'phase'   => $step['phase'],
				'depends' => $step['depends'],
			);
			foreach ( $plan['objects'] as $item ) {
				if ( (int) $item['seq'] === $seq ) {
					$shown['object'] = array(
						'entry'       => (int) $item['n'],
						'kind'        => $item['kind'],
						'subtype'     => $item['subtype'],
						'op'          => $item['op'],
						'staging_ref' => $item['staging_ref'],
						'live_ref'    => $item['live_ref'],
						'pair_id'     => $item['pair_id'],
						'as_draft'    => (bool) $item['as_draft'],
						'choice'      => $item['choice'],
					);
				}
			}
			if ( count( $steps ) < self::NAMED ) {
				$steps[] = $shown;
			}
		}
		$out = array(
			'report_id'   => $plan['report_id'],
			'report_hash' => $plan['report_hash'],
			'objects'     => count( $plan['objects'] ),
			'steps'       => $steps,
			'steps_total' => count( $plan['steps'] ),
			'locked'      => $plan['locked'],
			'not_written' => $plan['skipped'],
			'resources'   => $plan['resources'],
			'environment' => $plan['environment'] ? 'compared' : 'not compared: the report was made without the packages of the environment; the run writes objects only, which this does not hold back',
			'effect'      => 'Makes a run that writes these objects of the staging site onto this site, one per call (action=next), each under the lock of AlphaBridge with a backup and a journal, read back against its target; a new object stays on the way back (a rest); the pairs take new bases. Nothing is deleted. The way back is wp_site_revert, pair by pair, byte for byte conditional. A change of another user or process on a way the lock does not refuse (wp_publish_post() by the cron, terms on the classic way, SQL) between the check and the write can be lost without the read back seeing it.',
		);
		if ( isset( $plan['existing_run'] ) ) {
			$out['existing_run'] = (int) $plan['existing_run'];
		}
		return $out;
	}

	/**
	 * The overview without a run_id: the run that is not closed, and the
	 * last runs.
	 *
	 * @return array|WP_Error
	 */
	private static function overview() {
		$open = AB_MCP_Sync_Run::open_run();
		if ( is_wp_error( $open ) ) {
			return $open;
		}
		$page = AB_MCP_Sync_Run::page( 0, 10 );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$runs = array();
		foreach ( $page['runs'] as $run ) {
			$runs[] = array(
				'run_id'            => (int) $run['run_id'],
				'type'              => $run['type'],
				'state'             => $run['state'],
				'created'           => (int) $run['created'],
				'closed'            => (int) $run['closed'],
				'forward_closed_at' => (int) $run['forward_closed_at'],
				'own'               => null === AB_MCP_Sync_Run::owner_refusal( $run ),
			);
		}
		$out = array(
			'open' => null,
			'runs' => $runs,
			'next' => null === $open ? 'No run is open on this site. Plan one with action=plan (report_id, select or all, packages).' : sprintf( 'Run %1$d is %2$s. %3$s', (int) $open['run_id'], $open['state'], self::status_way( $open ) ),
		);
		if ( null !== $open ) {
			$open        = AB_MCP_Sync_Run::settle( $open );
			$out['open'] = is_wp_error( $open ) ? null : array(
				'run_id' => (int) $open['run_id'],
				'type'   => $open['type'],
				'state'  => $open['state'],
				'own'    => null === AB_MCP_Sync_Run::owner_refusal( $open ),
			);
		}
		return $out;
	}

	/**
	 * The run a call names, checked for its owner.
	 *
	 * @param array $a Arguments.
	 * @return array|WP_Error
	 */
	public static function own_run( array $a ) {
		if ( ! isset( $a['run_id'] ) || ! is_numeric( $a['run_id'] ) || (int) $a['run_id'] < 1 ) {
			return new WP_Error( 'ab_mcp_sync_push_run', 'Pass run_id, the number the answer of action=plan gave (action=status without run_id lists the runs).' );
		}
		$run = AB_MCP_Sync_Run::get( (int) $a['run_id'] );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		if ( null === $run ) {
			return AB_MCP_Sync_Run::unknown( (int) $a['run_id'] );
		}
		$foreign = AB_MCP_Sync_Run::owner_refusal( $run );
		return null === $foreign ? $run : $foreign;
	}

	/**
	 * What to do with a run in its state.
	 *
	 * @param array $run Run.
	 * @return string
	 */
	public static function status_way( array $run ) {
		switch ( $run['state'] ) {
			case 'open':
				return 'push' === $run['type'] ? 'Go on with action=next (one step per call), or action=abort.' : 'Go on with wp_site_revert action=next.';
			case 'interrupted':
				return 'The run was interrupted: a step began and the request that wrote it ended before it finished (its lock is no longer held). No step runs and no new run begins until a person closes it as checked on the page AlphaBridge → Staging (nothing is written then; pairs with unclear parts become unclear), or wp_site_revert with this run_id takes its own parts back. The journal names each part with its state.';
			case 'reverting':
				return 'The way back of this run is running: go on with wp_site_revert action=next.';
			default:
				return 'The run is closed. wp_site_revert action=plan with this run_id plans the way back for its pairs.';
		}
	}

	/**
	 * The refusal of a step of an interrupted run (decision 18).
	 *
	 * @param array $run Run.
	 * @return WP_Error
	 */
	private static function interrupted( array $run ) {
		return new WP_Error( 'ab_mcp_sync_run_interrupted', sprintf( 'Run %d is interrupted and takes no step: a step began and the request that wrote it ended before it finished. Two ways: a person closes it as checked on the page AlphaBridge → Staging after reading its journal (nothing is written), or wp_site_revert action=plan with this run_id takes its own parts back. action=status shows the journal.', (int) $run['run_id'] ), array( 'run_id' => (int) $run['run_id'] ) );
	}

	/**
	 * @param array $open The run that is not closed.
	 * @return WP_Error
	 */
	private static function another_open( array $open ) {
		return new WP_Error(
			'ab_mcp_sync_run_open',
			sprintf( 'This site has a run that is not closed (run %1$d, %2$s, state %3$s), and at most one run per site is open at a time, so no new plan begins. Ask for its status (wp_site_push action=status). %4$s', (int) $open['run_id'], $open['type'], $open['state'], self::status_way( $open ) ),
			array(
				'run_id' => (int) $open['run_id'],
				'state'  => $open['state'],
			)
		);
	}

	/**
	 * @param mixed $list A list of entry numbers.
	 * @return int[]|null Null where it is none.
	 */
	private static function numbers( $list ) {
		if ( ! is_array( $list ) || ! array_is_list( $list ) ) {
			return null;
		}
		$out = array();
		foreach ( $list as $n ) {
			if ( ! is_int( $n ) && ! ( is_string( $n ) && 1 === preg_match( '/^[1-9][0-9]*\z/', $n ) ) ) {
				return null;
			}
			if ( (int) $n < 1 ) {
				return null;
			}
			$out[] = (int) $n;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param string $what What is wrong with the selection.
	 * @return WP_Error
	 */
	private static function selection( $what ) {
		return new WP_Error( 'ab_mcp_sync_push_selection', sprintf( 'The selection is not one a run takes: %s Pass select (entry numbers of the report of kind post with action take_over or create), choices (entry and choice for a conflict), as_draft (entry numbers taken over with the status draft) or all=true, and packages (the package_ids of the objects).', $what ) );
	}
}
