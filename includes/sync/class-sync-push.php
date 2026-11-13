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
 * WHAT A PLAN HOLDS (this version): entries of the kind post of the types
 * the post engine writes (AB_MCP_Sync_Post_Writer::TYPES: posts and pages,
 * since branch 9b the templates, template parts, global styles,
 * navigations and reusable blocks as well) and of the kind
 * term of its taxonomies (AB_MCP_Sync_Term_Writer::TAXONOMIES; branch 9);
 * every other entry chosen is named as locked with its reason and stage
 * and is no step. The steps: the check
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
 * checked on the page «Staging», or wp_site_revert leads its way back on or
 * begins it.
 *
 * ONE LOCK PER CALL: next, step and abort run under the lock of the write
 * boundary from the first read of the run to the answer
 * (AB_MCP_Sync_Run::under_lock(); another request holding it refuses them as
 * busy, nothing read or changed, not even the count of calls); status and
 * the preview of plan read without it (AB_MCP_Sync_Run::settle() takes it
 * for a moment where a step is begun). plan with the token makes a run and
 * changes none: it is not under the lock, and open_one keeps a second run
 * from being made (decision 15).
 *
 * Messages are in gettext, in the language of the site as they arise (a
 * refusal, a reason an entry is locked); each names the way. Codes, states,
 * kinds and the other values the tools name stay as they are, and so do
 * the hints for the assistant (next, effect) and what the report says (the
 * reasons of its locks); no translated text is compared, hashed or part of
 * a print.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-sync-run.php';
require_once __DIR__ . '/class-sync-engine.php';
require_once __DIR__ . '/class-sync-report.php';
require_once __DIR__ . '/class-sync-resources.php';
require_once __DIR__ . '/class-sync-media.php';
require_once __DIR__ . '/class-sync-code-plan.php';

/**
 * Class AB_MCP_Sync_Push
 */
final class AB_MCP_Sync_Push {

	/** The actions. */
	const ACTIONS = array( 'plan', 'next', 'step', 'status', 'abort' );

	/** The choices for a conflict. */
	const CHOICES = array( 'staging_wins', 'keep_live', 'leave_open' );

	/**
	 * Objects one plan writes at most: the steps a plan has (AB_MCP_Sync_Run::
	 * MAX_STEPS, the entries a tool call takes) less the frame of the run
	 * (AB_MCP_Sync_Run::FRAME): 1996. The way back of the same run (one step
	 * per written object, decision 15) has its room besides, up to
	 * AB_MCP_Sync_Run::MAX_RUN_STEPS (Codex round 1 of branch 8, B5; round 2,
	 * N4: a plan of up to 1996 objects, as before B5). More objects go in
	 * parts: select with at most this many entries, the run to its close,
	 * then the next entries from the same report.
	 */
	const MAX_OBJECTS = AB_MCP_Sync_Run::MAX_STEPS - AB_MCP_Sync_Run::FRAME;

	/**
	 * The types of the kind post another object names by a name, not an id,
	 * in the order their steps come before the other objects (branch 9b;
	 * order()): a template part (core/template-part, theme and slug), a
	 * template (page_template). The order is no dependency: a step whose
	 * part is not there yet is refused with its lock as it writes.
	 */
	const NAMED_FIRST = array( 'wp_template_part', 'wp_template' );

	/**
	 * The types whose steps come last where nothing orders them otherwise
	 * (branch 9b, Codex round 2): a navigation, whose check asks what the
	 * other objects of the run leave (AB_MCP_Sync_Post_Writer::
	 * fallback_shown()): the steps that do not need it have ended by then,
	 * and the check reads what they wrote.
	 */
	const LAST = array( 'wp_navigation' );

	/**
	 * The kinds of the structure of a site a run writes (branch 11): a menu
	 * and its items, a widget area, the additional CSS, a core option.
	 */
	const STRUCTURE = array( 'menu', 'menu_item', 'widget_area', 'custom_css', 'option' );

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
			return new WP_Error( 'ab_mcp_sync_push_action', __( 'wp_site_push takes action=plan (with report_id and the selection; the token in the same call), next, step (with run_id and seq), status or abort (with run_id). Pass one of them.', 'alphabridge-mcp' ) );
		}
		$source = AB_MCP_Sync_Proposal::source_id();
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( 'status' === $action ) {
			if ( ! isset( $a['run_id'] ) ) {
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
			$shown = AB_MCP_Sync_Run::shown( $run );
			if ( is_wp_error( $shown ) ) {
				return $shown;
			}
			$shown['next'] = AB_MCP_Sync_Run::way( $run );
			return $shown;
		}
		// next, step and abort change the run: under the lock of the write
		// boundary for the whole call, the run read under it (the decision of
		// 07.11.2026 evening, Variante A).
		$id = isset( $a['run_id'] ) && is_numeric( $a['run_id'] ) ? (int) $a['run_id'] : 0;
		return AB_MCP_Sync_Run::under_lock(
			static function () use ( $a, $action ) {
				return self::change( $a, $action );
			},
			/* translators: %d: run number. */
			'abort' === $action ? sprintf( __( 'Run %d is not aborted.', 'alphabridge-mcp' ), $id ) : sprintf( __( 'Run %d takes no step.', 'alphabridge-mcp' ), $id )
		);
	}

	/**
	 * next, step and abort under the lock of the call: the run read and
	 * settled under it (a step a request that ended left begun makes it
	 * interrupted here), then decided.
	 *
	 * @param array  $a      Arguments.
	 * @param string $action next, step or abort.
	 * @return array|WP_Error
	 */
	private static function change( array $a, $action ) {
		$run = self::own_run( $a );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		AB_MCP_Sync_Run::touch( (int) $run['run_id'], true );
		$run = AB_MCP_Sync_Run::settle( $run );
		if ( is_wp_error( $run ) ) {
			return $run;
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
			$shown['next'] = __( 'The run is closed as aborted. What its steps wrote stays as written (their pairs have the new bases).', 'alphabridge-mcp' ) . ' wp_site_revert with this run_id takes it back pair by pair.';
			return $shown;
		}
		if ( 'interrupted' === $run['state'] ) {
			return AB_MCP_Sync_Run::interrupted_refusal( $run );
		}
		if ( 'open' !== $run['state'] || 'push' !== $run['type'] ) {
			/* translators: 1: run number, 2: its state, as the tools name it (open, closed, …), 3: its type, as the tools name it (push, revert). */
			return new WP_Error( 'ab_mcp_sync_run_state', sprintf( __( 'The run %1$d is %2$s (%3$s) and takes no step here: a run of the way back goes on with wp_site_revert, a closed run no more. Ask for its status.', 'alphabridge-mcp' ), (int) $run['run_id'], $run['state'], $run['type'] ) );
		}
		$seq = null;
		if ( 'step' === $action ) {
			if ( ! isset( $a['seq'] ) || ! is_numeric( $a['seq'] ) || (int) $a['seq'] < 1 ) {
				return new WP_Error( 'ab_mcp_sync_push_seq', __( 'action=step takes seq, the number of the step to run (status lists them). Pass it, or use action=next.', 'alphabridge-mcp' ) );
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
			return new WP_Error( 'ab_mcp_sync_push_action', __( 'wp_site_push takes action=plan (with report_id and the selection; the token in the same call), next, step (with run_id and seq), status or abort (with run_id). Pass one of them.', 'alphabridge-mcp' ) );
		}
		$source = AB_MCP_Sync_Proposal::source_id();
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$anchor = AB_MCP_Sync_Anchor::active();
		$print  = AB_MCP_Sync_Anchor::pair_print();
		if ( null === $anchor || null === $print ) {
			return new WP_Error( 'ab_mcp_sync_pairs_no_anchor', __( 'This site has no confirmed key of a staging site, so no run is planned. Ask the person to confirm it on the page AlphaBridge → Staging of this site; then call again.', 'alphabridge-mcp' ) );
		}
		$status = AB_MCP_Sync_Schema::status();
		if ( ! $status['available'] ) {
			/* translators: %s: why the tables are not there, as the schema names it (multisite, missing, …). */
			return new WP_Error( 'ab_mcp_staging_unavailable', sprintf( __( 'The tables of the takeover are not available on this site (%s): no run begins. Deactivate and activate AlphaBridge in wp-admin so the tables are made, or ask the host to let the database user create tables.', 'alphabridge-mcp' ), (string) $status['reason'] ) );
		}
		$found = AB_MCP_Sync_Report::find( isset( $a['report_id'] ) ? $a['report_id'] : null, $source );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		if ( null === $found['index'] ) {
			return new WP_Error( 'ab_mcp_sync_report_not_done', __( 'This report is not worked out yet: the inventory of this site is still being read. Call wp_site_diff with this report_id until it answers done true, then plan.', 'alphabridge-mcp' ) );
		}
		$report_hash = (string) $found['index']['report_hash'];
		// The selection in its one spelling (selection_of()), and the
		// plan_hash it gives with the report (the key of the replay of
		// decision 15).
		$selection = self::selection_of( $a );
		if ( is_wp_error( $selection ) ) {
			return $selection;
		}
		list( $select, $drafts, $choices, $all, $packages ) = array( $selection['select'], $selection['as_draft'], $selection['choices'], $selection['all'], $selection['packages'] );
		$plan_hash = hash( 'sha256', AB_MCP_Sync_Canon::encode( array( 'report_hash' => $report_hash ) + $selection ) );
		// A run that is not closed, asked before the objects are read: the
		// same plan of this account and connection is given back (the replay
		// of decision 15: a repeated confirmed call after the first steps wrote
		// meets a changed state, gets a new preview, and its confirmation meets
		// the open run), so a plan whose run exists is not held against the
		// report below; any other run that is not closed refuses with the way.
		$existing = null;
		$open     = AB_MCP_Sync_Run::open_run();
		if ( is_array( $open ) ) {
			// Settled, so the refusal names the ways that go for it.
			$open = AB_MCP_Sync_Run::settle( $open );
		}
		if ( is_wp_error( $open ) ) {
			return $open;
		}
		if ( null !== $open ) {
			if ( 'open' === $open['state'] && hash_equals( (string) $open['plan_hash'], $plan_hash ) && null === AB_MCP_Sync_Run::owner_refusal( $open ) ) {
				$existing = (int) $open['run_id'];
			} else {
				return self::another_open( $open );
			}
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
					/* translators: 1: the entry number asked for, 2: the number of entries of the report. */
					return self::selection( sprintf( __( 'The report has no entry %1$d (it has %2$d).', 'alphabridge-mcp' ), $n, (int) $found['index']['total'] ) );
				}
			}
		} else {
			$entries = AB_MCP_Sync_Proposal::entries( $found, $numbers );
			if ( is_wp_error( $entries ) ) {
				return $entries;
			}
			foreach ( $numbers as $n ) {
				if ( ! isset( $entries[ $n ] ) ) {
					/* translators: 1: the entry number asked for, 2: the number of entries of the report. */
					return self::selection( sprintf( __( 'The report has no entry %1$d (it has %2$d).', 'alphabridge-mcp' ), $n, (int) $found['index']['total'] ) );
				}
			}
		}
		foreach ( $drafts as $n ) {
			if ( ! in_array( $n, $select, true ) && ! isset( $choices[ $n ] ) && ! $all ) {
				/* translators: %d: entry number of the report. */
				return self::selection( sprintf( __( 'Entry %d is in as_draft but not chosen: name it in select as well.', 'alphabridge-mcp' ), $n ) );
			}
		}
		// A run of the code (Z8b branch 5; E19, Z30): where the selection
		// names a plugin or a theme, the plan is the one of the code and holds
		// no step of the contents (AB_MCP_Sync_Code_Plan::of(), called here alone).
		$code = AB_MCP_Sync_Code_Plan::of( $found, $entries, $selection + array( 'accept' => array() ), compact( 'source', 'anchor', 'print', 'report_hash', 'plan_hash', 'existing' ) );
		if ( null !== $code ) {
			return $code;
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
		$lines = self::lines_of( $packages, $report_hash );
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
				/* translators: 1: kind of the object, as the tools name it (post, term, …), 2: its reference on the staging site, 3: entry number of the report. */
				return new WP_Error( 'ab_mcp_sync_push_report', sprintf( __( 'The report holds no staging line for the %1$s %2$s of entry %3$d (its packages of the inventory are gone or changed). Make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), $item['kind'], $item['staging_ref'], $n ) );
			}
			$item['staging_hash'] = $hashes[ $key ];
			$line                 = AB_MCP_Sync_Engine::line_of( $item, $lines );
			if ( is_wp_error( $line ) ) {
				return $line;
			}
			$item['size'] = isset( $line['size'] ) ? (int) $line['size'] : 0;
			$item['refs'] = array();
			if ( 'attachment' === $item['kind'] ) {
				// The bytes of the files it may lay besides its rows (decision 8:
				// the new media files are booked, with their temporary copies):
				// of an attachment here only those its list here does not name
				// (a file at a path it names is never written over); and its file
				// list for the paths two objects of the run would share (branch 10).
				$listed = 'update' === $item['op'] ? AB_MCP_Sync_Media::paths_of( (int) $item['live_ref'] ) : array();
				$item['files'] = array();
				foreach ( isset( $line['form']['files'] ) && is_array( $line['form']['files'] ) ? $line['form']['files'] : array() as $file ) {
					if ( is_array( $file ) && isset( $file['path'], $file['size'] ) && is_string( $file['path'] ) ) {
						$item['files'][] = $file['path'];
						if ( null === $listed || ! in_array( $file['path'], $listed, true ) ) {
							$item['size'] += (int) $file['size'];
						}
					}
				}
			}
			foreach ( isset( $line['refs'] ) && is_array( $line['refs'] ) ? $line['refs'] : array() as $ref ) {
				if ( 'attachment' === $item['kind'] && is_array( $ref ) && 'weak' === ( $ref['strength'] ?? null ) && 'post' === ( $ref['space'] ?? null ) && 'post_parent' === ( $ref['at'] ?? null ) && isset( $ref['id'] ) ) {
					// The parent of an attachment, a weak reference (decision 16).
					$item['weak_parent'] = (string) (int) $ref['id'];
					continue;
				}
				if ( is_array( $ref ) && isset( $ref['space'], $ref['id'] ) && in_array( $ref['space'], array( 'post', 'term' ), true ) && 'strong' === ( $ref['strength'] ?? null ) ) {
					$item['refs'][] = array( (string) $ref['space'], (string) (int) $ref['id'] );
					if ( 'term' === $item['kind'] && 'term' === $ref['space'] && 'parent' === ( $ref['at'] ?? null ) ) {
						// The parent of a term, for the order (order()).
						$item['parent_ref'] = (string) (int) $ref['id'];
					}
				}
			}
			if ( 'menu' === $item['kind'] ) {
				// The positions it takes (its form on the staging site), for the
				// order of the menus (branch 11, Codex round 1, B2).
				$item['takes'] = AB_MCP_Sync_Menu_Writer::line_positions( $line );
			}
			if ( 'post' === $item['kind'] ) {
				// The references the boundary asks by an id or a name, for the
				// order (branch 9b, Codex round 1, B): reusable blocks and
				// navigations by their ref, template parts by theme and slug,
				// the template a page names; and the name of the object itself
				// where another names it so.
				$item['order_refs'] = AB_MCP_Sync_Post_Writer::order_refs( $line );
				$item['order_name'] = AB_MCP_Sync_Post_Writer::order_name( $line );
			}
			$items[ $n ] = $item;
		}
		// The menus of the run (branch 11, Codex round 1, B2): the positions
		// are fields of one row (decision 24), and a menu's form names them.
		// A menu that takes a position another menu of the run holds here
		// comes after that menu (an order, no dependency: order()), which
		// gives the position up first; else its step would change that menu,
		// whose step would find it changed since the plan.
		$menus = array();
		foreach ( $items as $n => $item ) {
			if ( 'menu' === $item['kind'] && 'update' === $item['op'] ) {
				$menus[ (int) $item['live_ref'] ] = $n;
			}
		}
		$held = array() === $menus ? array() : AB_MCP_Sync_Menu_Writer::held_positions();
		foreach ( $items as $n => $item ) {
			foreach ( 'menu' === $item['kind'] && isset( $item['takes'] ) ? $item['takes'] : array() as $location ) {
				$holder = isset( $held[ $location ] ) ? $held[ $location ] : null;
				if ( null !== $holder && isset( $menus[ $holder ] ) && $menus[ $holder ] !== $n ) {
					$items[ $n ]['menu_after'][] = $menus[ $holder ];
				}
			}
		}
		// The attachments of the run (branch 10): a path two of them name is
		// shared (attachment:shared_path), both are locked; an attachment
		// here whose new parent the run creates comes after that post (an
		// order, a cycle locked); a new one hangs under it by a parent step
		// after both (decision 16: the weak reference).
		$by_path = array();
		foreach ( $items as $n => $item ) {
			foreach ( 'attachment' === $item['kind'] && isset( $item['files'] ) ? array_unique( $item['files'] ) : array() as $path ) {
				$by_path[ $path ][] = $n;
			}
		}
		foreach ( $by_path as $path => $ns ) {
			if ( count( $ns ) < 2 ) {
				continue;
			}
			$held = AB_MCP_Sync_Kinds::lock( 'attachment', 'shared_path' );
			foreach ( $ns as $n ) {
				if ( isset( $items[ $n ] ) ) {
					$locked[] = array(
						'n'       => (int) $n,
						'kind'    => 'attachment',
						'subtype' => (string) $items[ $n ]['subtype'],
						'action'  => (string) $items[ $n ]['action'],
						'result'  => '',
						/* translators: 1: the reason of the lock, a whole sentence in the language of the site, 2: the path of the file under the uploads. */
						'reason'  => sprintf( __( '%1$s (attachment:shared_path) The file %2$s is named by another attachment of this plan as well.', 'alphabridge-mcp' ), AB_MCP_Sync_Said::text( (string) $held['reason'] ), (string) $path ),
						'stage'   => (string) $held['stage'],
					);
					unset( $items[ $n ] );
				}
			}
		}
		// The objects of the space of posts the run creates, a parent an
		// attachment may hang under: a post, and an attachment as well (the
		// poster image of a new video hangs under the video, which names it
		// as its _thumbnail_id: the strong reference orders the image first,
		// the weak one makes no cycle; GitHub Codex on free#140, P2).
		$made_posts = array();
		foreach ( $items as $n => $item ) {
			if ( in_array( $item['kind'], array( 'post', 'attachment' ), true ) && 'create' === $item['op'] ) {
				$made_posts[ (string) $item['staging_ref'] ] = $n;
			}
		}
		$parents = array();
		foreach ( $items as $n => $item ) {
			if ( 'attachment' !== $item['kind'] || ! isset( $item['weak_parent'], $made_posts[ $item['weak_parent'] ] ) || $made_posts[ $item['weak_parent'] ] === $n ) {
				continue;
			}
			if ( 'update' === $item['op'] ) {
				$items[ $n ]['refs'][] = array( 'post', $item['weak_parent'] );
			} else {
				$items[ $n ]['parent_later'] = true;
				$parents[ $n ]               = $made_posts[ $item['weak_parent'] ];
			}
		}
		$refused = self::too_many( count( $items ) + count( $parents ) );
		if ( null !== $refused ) {
			return $refused;
		}
		if ( array() === $items ) {
			return new WP_Error(
				'ab_mcp_sync_push_nothing',
				array() === $locked
					? __( 'Nothing of the selection is written in this version: no entry a takeover writes was chosen. Choose entries the report marks take_over or create (posts, pages and the other types the engine writes, terms, attachments, menus and their items, widget areas, the additional CSS and core options).', 'alphabridge-mcp' )
					/* translators: %s: the first locked entries, each with its number, kind, subtype and why it is locked. */
					: sprintf( __( 'Nothing of the selection is written in this version: %s Choose entries the report marks take_over or create (posts, pages and the other types the engine writes, terms, attachments, menus and their items, widget areas, the additional CSS and core options).', 'alphabridge-mcp' ), self::locked_list( $locked ) ),
				array( 'locked' => $locked )
			);
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
		// The objects of this site as the report compared them (the lines of
		// this site's inventory the report holds): what the report says of an
		// entry (take_over, or the choice of a conflict) holds for that state
		// only. A target that changed since the report is not written over: a
		// change of this site since the report would be lost without a choice
		// (GitHub Codex round 1 of branch 8). So the hash the plan binds is
		// the one the report compared; the check before the first write and
		// the boundary read it again (decisions 15 and 16). A plan whose run
		// is open already (the replay, above) is not held against the report:
		// its run wrote by the hashes the report compared, and is given back.
		$compared = array();
		if ( array() !== $asked && null === $existing ) {
			$read = AB_MCP_Sync_Proposal::live( $found, AB_MCP_Sync_Report::concurrent() );
			if ( is_wp_error( $read ) ) {
				return $read;
			}
			foreach ( $read['lines'] as $line ) {
				if ( is_array( $line ) && isset( $line['kind'], $line['ref'], $line['hash'] ) && is_string( $line['hash'] ) ) {
					$compared[ (string) $line['kind'] . "\0" . AB_MCP_Sync_Post_Writer::bytes( $line['ref'] ) ] = $line['hash'];
				}
			}
		}
		$pairs = array();
		foreach ( $items as $n => $item ) {
			if ( 'update' !== $item['op'] ) {
				continue;
			}
			$key  = $item['kind'] . "\0" . $item['live_ref'];
			$hash = $live[ $key ];
			if ( 'gone' === $hash ) {
				/* translators: 1: kind of the object, as the tools name it (post, term, …), 2: its reference on this site, 3: entry number of the report. */
				return new WP_Error( 'ab_mcp_sync_push_gone', sprintf( __( 'The %1$s %2$s of this site (entry %3$d) is gone since the report. Make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), $item['kind'], $item['live_ref'], $n ) );
			}
			if ( null === $existing && ( ! isset( $compared[ $key ] ) || ! hash_equals( $compared[ $key ], $hash ) ) ) {
				/* translators: 1: kind of the object, as the tools name it (post, term, …), 2: its reference on this site, 3: entry number of the report. */
				return new WP_Error( 'ab_mcp_sync_push_changed', sprintf( __( 'The %1$s %2$s of this site (entry %3$d) changed since the report (or the report did not read it whole), so what the report says of it no longer holds and it is not written over. Make a new report (wp_site_diff), which compares it as it is now, and plan again.', 'alphabridge-mcp' ), $item['kind'], $item['live_ref'], $n ) );
			}
			$items[ $n ]['live_hash'] = $hash;
			$pair                     = AB_MCP_Sync_Pairs::get( (int) $item['pair_id'] );
			if ( is_wp_error( $pair ) || null === $pair ) {
				/* translators: 1: pair number, 2: entry number of the report. */
				return new WP_Error( 'ab_mcp_sync_push_pair', sprintf( __( 'The pair %1$d of entry %2$d is no longer there. Make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), (int) $item['pair_id'], $n ) );
			}
			if ( 'active' !== $pair['state'] ) {
				/* translators: 1: pair number, 2: entry number of the report, 3: the state of the pair, as the tools name it (unclear, residual, …). */
				return new WP_Error( 'ab_mcp_sync_push_pair', sprintf( __( 'The pair %1$d of entry %2$d is %3$s since the report, and only an active pair is written over. Make a new report (wp_site_diff) and plan again.', 'alphabridge-mcp' ), (int) $item['pair_id'], $n, $pair['state'] ) );
			}
			$items[ $n ]['pair_hash']       = AB_MCP_Sync_Pairs::snapshot_hash( AB_MCP_Sync_Pairs::snapshot( (int) $item['pair_id'] ) );
			$pairs[ (int) $item['pair_id'] ] = $items[ $n ]['pair_hash'];
		}
		// The depth of each term in the tree the run leaves, for the order.
		$depths = self::term_depths(
			$items,
			static function ( $staging_id ) use ( $source ) {
				$pair = AB_MCP_Sync_Pairs::find_staging( (string) $source, 'term', (string) $staging_id );
				return is_array( $pair ) && isset( $pair['live_ref'] ) ? (int) $pair['live_ref'] : null;
			}
		);
		foreach ( $depths as $n => $depth ) {
			$items[ $n ]['depth'] = $depth;
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
			// Each lock of the order with its reason (mutual references, an
			// exchange of positions of menus: Codex round 1 of branch 11, B2).
			/* translators: %s: the first locked entries, each with its number, kind, subtype and why it is locked. */
			return new WP_Error( 'ab_mcp_sync_push_nothing', sprintf( __( 'Nothing of the selection is written: the order of the run locks every object chosen. %s Choose objects that do not refer to each other, or follow the way each lock names.', 'alphabridge-mcp' ), self::locked_list( $ordered['locked'] ) ), array( 'locked' => $locked ) );
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
			// The backup of an object of this site holds its rows as they are,
			// not the staging side: reserved from them, uncompressed, as the
			// store counts them (AB_MCP_Sync_Backup::size_of(); GitHub Codex
			// round 6 on free#137, P2). A new object leaves a small mark.
			$w       = AB_MCP_Sync_Engine::writer( $item['kind'] );
			$live_id = 'update' === $item['op'] && isset( $item['live_ref'] ) ? AB_MCP_Sync_Engine::live_of( $w, $item['live_ref'] ) : 0;
			$current = AB_MCP_Sync_Engine::is_ref( $live_id ) && null !== $w ? $w::current( $live_id ) : null;
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			$absent = 'term' === $item['kind']
				? array( 'taxonomy' => (string) ( $item['subtype'] ?? '' ), 'slug' => (string) $item['staging_ref'] )
				: array( 'post_type' => (string) ( $item['subtype'] ?? '' ), 'post_name' => (string) $item['staging_ref'], 'theme' => '' );
			$bytes += AB_MCP_Sync_Backup::size_of( is_array( $current ) ? $current : array( 'absent' => true, 'identity' => $absent ) );
		}
		$depends_of = array();
		foreach ( $items as $n => $item ) {
			$depends = array( 1 );
			foreach ( $item['depends_on'] as $m ) {
				$depends[] = $by_n[ $m ];
			}
			$depends_of[ $n ] = array_values( array_unique( $depends ) );
			$steps[] = array(
				'op'      => 'write',
				'phase'   => 5,
				'depends' => array_values( array_unique( $depends ) ),
				'pair_id' => isset( $item['pair_id'] ) ? $item['pair_id'] : null,
			);
			unset( $items[ $n ]['depends_on'], $items[ $n ]['after'], $items[ $n ]['refs'], $items[ $n ]['order_refs'], $items[ $n ]['order_name'], $items[ $n ]['parent_ref'], $items[ $n ]['depth'], $items[ $n ]['files'], $items[ $n ]['weak_parent'], $items[ $n ]['takes'], $items[ $n ]['menu_after'] );
		}
		// The parent steps (branch 10): each after the attachment and its
		// post, both of the run; the attachment hangs under no post until then.
		foreach ( $parents as $n => $m ) {
			if ( ! isset( $items[ $n ], $items[ $m ], $by_n[ $n ], $by_n[ $m ] ) ) {
				// The post or the attachment was locked in the order: the
				// attachment is no longer made unattached, its check locks it.
				if ( isset( $items[ $n ] ) ) {
					unset( $items[ $n ]['parent_later'] );
				}
				continue;
			}
			++$seq;
			$depends = array( 1, $by_n[ $n ], $by_n[ $m ] );
			$items[ 'p' . $n ] = array(
				'n'            => (int) $items[ $n ]['n'],
				'kind'         => 'attachment',
				'subtype'      => (string) $items[ $n ]['subtype'],
				'op'           => 'attach',
				'staging_ref'  => (string) $items[ $n ]['staging_ref'],
				'live_ref'     => null,
				'pair_id'      => null,
				'pair_hash'    => null,
				'live_hash'    => null,
				'as_draft'     => false,
				'choice'       => null,
				'action'       => (string) $items[ $n ]['action'],
				'source_id'    => $source,
				'staging_hash' => (string) $items[ $n ]['staging_hash'],
				'size'         => 0,
				'attach_of'    => $by_n[ $n ],
				'seq'          => $seq,
			);
			$depends_of[ 'p' . $n ] = $depends;
			$bytes                += self::JOURNAL_BYTES;
			$steps[]               = array(
				'op'      => 'write',
				'phase'   => 5,
				'depends' => $depends,
				'pair_id' => null,
			);
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
		ksort( $live );
		ksort( $pairs );
		// The order of the steps the token confirms, object by object: it
		// rests on more than the objects chosen (the depth of a term reads
		// the parents of its ancestors here, term_depths()), so a token whose
		// order no longer holds is stale like any other change of the state
		// (branch 9, Codex round 3, B).
		// The dependencies of each step with it: whether an edge is one rests
		// on this site as well (a part it has as a file or a row; Codex round 2
		// of branch 9b, 2), and a dependency decides which step is skipped.
		$order = array();
		foreach ( $items as $n => $item ) {
			$order[] = array( (int) $item['seq'], (string) $item['kind'], (string) $item['staging_ref'], $depends_of[ $n ] );
		}
		$state = hash(
			'sha256',
			AB_MCP_Sync_Canon::encode(
				array(
					'live'        => $live,
					'order'       => $order,
					// The order of the parts of each kind on the way back (C2).
					'part_order'  => AB_MCP_Sync_Engine::part_orders( array_column( $items, 'kind' ) ),
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
		if ( null !== $existing ) {
			$plan['existing_run'] = $existing;
		}
		return $plan;
	}

	/**
	 * The selection of a plan in its one spelling (plan(); the key of the
	 * replay of decision 15 is made of it): the entries chosen (select), the
	 * choices for conflicts, the entries taken over as draft, all, and the
	 * packages passed (objects and media for a run of the contents, code for
	 * a run of the code, where they may be none: lines_of() asks for those of
	 * the contents). accept, the entries of units of code whose code runs
	 * here that the person releases (Z8b branch 5; E8, Z24), is part of it
	 * where it names an entry, so that a plan without it is spelt as before.
	 *
	 * @param array $a Arguments.
	 * @return array|WP_Error all, as_draft, choices, packages, select; accept where it names an entry.
	 */
	private static function selection_of( array $a ) {
		$select = self::numbers( isset( $a['select'] ) ? $a['select'] : array() );
		$drafts = self::numbers( isset( $a['as_draft'] ) ? $a['as_draft'] : array() );
		$accept = self::numbers( isset( $a['accept'] ) ? $a['accept'] : array() );
		$all    = isset( $a['all'] ) && ( true === $a['all'] || 'true' === $a['all'] || 1 === $a['all'] );
		if ( null === $select || null === $drafts ) {
			return self::selection( __( 'select and as_draft are lists of entry numbers of the report.', 'alphabridge-mcp' ) );
		}
		if ( null === $accept ) {
			return self::selection( __( 'accept is a list of entry numbers of the report: the plugins and themes chosen in select whose code runs on this site and whose swap the person releases.', 'alphabridge-mcp' ) );
		}
		$choices = array();
		foreach ( isset( $a['choices'] ) && is_array( $a['choices'] ) ? $a['choices'] : array() as $choice ) {
			if ( ! is_array( $choice ) || ! isset( $choice['entry'], $choice['choice'] ) || null === self::numbers( array( $choice['entry'] ) ) || ! in_array( $choice['choice'], self::CHOICES, true ) ) {
				return self::selection( __( 'A choice is an object of entry (its number) and choice (staging_wins, keep_live or leave_open).', 'alphabridge-mcp' ) );
			}
			$choices[ (int) $choice['entry'] ] = (string) $choice['choice'];
		}
		if ( array() === $select && array() === $choices && ! $all ) {
			return self::selection( __( 'Nothing is chosen: pass select (entry numbers), choices (for conflicts) or all=true.', 'alphabridge-mcp' ) );
		}
		$packages = isset( $a['packages'] ) ? $a['packages'] : array();
		if ( ! is_array( $packages ) || ! array_is_list( $packages ) || count( $packages ) > AB_MCP_Sync_Proposal::MAX_PACKAGES || array() !== array_filter( $packages, static fn( $id ) => ! is_string( $id ) || 1 !== preg_match( AB_MCP_Sync_Key::ID, $id ) ) ) {
			return self::packages_refusal();
		}
		sort( $select );
		sort( $drafts );
		sort( $accept );
		ksort( $choices );
		$selection = array(
			'all'      => $all,
			'as_draft' => $drafts,
			'choices'  => $choices,
			'packages' => array_values( array_unique( $packages ) ),
			'select'   => $select,
		);
		sort( $selection['packages'] );
		return array() === $accept ? $selection : $selection + array( 'accept' => $accept );
	}

	/**
	 * The objects of the staging site a run of the contents writes from, by
	 * the packages passed: a run of the contents has at least one.
	 *
	 * @param string[] $packages    The packages of the selection.
	 * @param string   $report_hash The report.
	 * @return array|WP_Error AB_MCP_Sync_Engine::lines().
	 */
	private static function lines_of( array $packages, $report_hash ) {
		if ( array() === $packages ) {
			return self::packages_refusal();
		}
		return AB_MCP_Sync_Engine::lines(
			array(
				'plan'        => array( 'packages' => $packages ),
				'report_hash' => $report_hash,
			)
		);
	}

	/**
	 * The refusal of packages that are no list of ids of packages, or none
	 * where a run of the contents is planned.
	 *
	 * @return WP_Error
	 */
	private static function packages_refusal() {
		return new WP_Error( 'ab_mcp_sync_push_packages', __( 'A run writes from packages of objects of the staging site: pass packages, the package_ids (32 hex each) this site took over with wp_site_import_chunk (purpose=objects, with this report_hash), made on the staging site with wp_site_export (purpose=objects, report_hash and the refs of the entries).', 'alphabridge-mcp' ) );
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
		// A unit of code the swap takes over or makes (Z8b branch 5) is never
		// a step of the contents: it has a run of its own (E19, Z30;
		// AB_MCP_Sync_Code_Plan), and all=true leaves it out.
		if ( 'environment' === $entry['kind'] && in_array( $action, array( 'take_over', 'create' ), true ) ) {
			return $lock( __( 'A plugin or theme is taken over by a run of the code of its own, which holds no step of the contents: plan it without the entries of the contents, by its number in select (and in accept where its code runs on this site). all=true leaves it out.', 'alphabridge-mcp' ) );
		}
		if ( 'choose' === $action ) {
			if ( null === $choice ) {
				if ( $all ) {
					return null;
				}
				return $lock( __( 'A conflict (or an unclear pair): name the choice in choices (staging_wins, keep_live or leave_open).', 'alphabridge-mcp' ) );
			}
			if ( 'staging_wins' !== $choice ) {
				return null;
			}
			if ( 'unclear' === $entry['result'] ) {
				return $lock( __( 'An unclear pair is written over only after a person confirms its bases (wp_site_pairs action=propose, then confirm).', 'alphabridge-mcp' ) );
			}
		} elseif ( null !== $choice ) {
			/* translators: %d: entry number of the report. */
			return $lock( sprintf( __( 'Entry %d is no conflict; a choice applies to conflicts only.', 'alphabridge-mcp' ), $n ) );
		}
		if ( in_array( $action, array( 'locked', 'waits' ), true ) ) {
			$locks = isset( $entry['locks'] ) && is_array( $entry['locks'] ) ? $entry['locks'] : array();
			$first = array() !== $locks ? $locks[0] : null;
			return $lock(
				'locked' === $action
					? ( null === $first ? __( 'Locked in the report.', 'alphabridge-mcp' ) : AB_MCP_Sync_Said::text( (string) $first['reason'] ) )
					/* translators: %s: what the entry waits for, whole sentences in the language of the site. */
					: sprintf( __( 'Waits in the report: %s', 'alphabridge-mcp' ), implode( ' ', array_map( static fn( $w ) => AB_MCP_Sync_Said::text( (string) ( $w['why'] ?? '' ) ), isset( $entry['waits_for'] ) && is_array( $entry['waits_for'] ) ? array_slice( $entry['waits_for'], 0, 3 ) : array() ) ) ),
				null === $first ? null : (string) $first['stage']
			);
		}
		if ( ! in_array( $action, array( 'take_over', 'create', 'choose' ), true ) ) {
			if ( $all ) {
				return null;
			}
			/* translators: %s: the action of the entry, as the report names it (keep, …). */
			return $lock( sprintf( __( 'The report says %s for this entry: a takeover writes nothing for it.', 'alphabridge-mcp' ), $action ) );
		}
		if ( 'term' === $entry['kind'] ) {
			if ( ! in_array( (string) ( $entry['subtype'] ?? '' ), AB_MCP_Sync_Term_Writer::TAXONOMIES, true ) ) {
				/* translators: 1: the taxonomies the engine writes, separated by commas, 2: the taxonomy of the entry. */
				return $lock( sprintf( __( 'The engine of the kind term writes the terms of %1$s; the taxonomy %2$s waits for its classification.', 'alphabridge-mcp' ), implode( ', ', AB_MCP_Sync_Term_Writer::TAXONOMIES ), (string) ( $entry['subtype'] ?? '' ) ), 'Z8d' );
			}
		} elseif ( 'attachment' === $entry['kind'] ) {
			// An attachment with its files (branch 10): no choice «as draft».
			if ( $as_draft ) {
				return $lock( __( 'An attachment has no status draft: leave it out of as_draft.', 'alphabridge-mcp' ) );
			}
		} elseif ( in_array( $entry['kind'], self::STRUCTURE, true ) ) {
			// The structure of the site (branch 11): no choice «as draft»; a
			// widget area or a core option is changed, never made by a run (the
			// additional CSS is locked to be made in the report already).
			if ( $as_draft ) {
				/* translators: %s: kind of the object, as the tools name it (menu, widget_area, option, …). */
				return $lock( sprintf( __( 'A %s has no status draft: leave it out of as_draft.', 'alphabridge-mcp' ), (string) $entry['kind'] ) );
			}
			if ( 'create' === $action && in_array( $entry['kind'], array( 'widget_area', 'option', 'custom_css' ), true ) ) {
				$held = AB_MCP_Sync_Kinds::lock( (string) $entry['kind'], 'create' );
				return $lock( sprintf( '%1$s (%2$s)', AB_MCP_Sync_Said::text( (string) $held['reason'] ), (string) $held['key'] ), (string) $held['stage'] );
			}
		} elseif ( 'post' !== $entry['kind'] ) {
			/* translators: %s: kind of the object, as the tools name it. */
			return $lock( sprintf( __( 'The kind %s is not written by a run of this version.', 'alphabridge-mcp' ), (string) $entry['kind'] ), 'Z8d' );
		} elseif ( ! in_array( (string) ( $entry['subtype'] ?? '' ), AB_MCP_Sync_Post_Writer::TYPES, true ) ) {
			/* translators: 1: the post types the engine writes, separated by commas, 2: the post type of the entry. */
			return $lock( sprintf( __( 'The engine of the kind post writes the types %1$s; the type %2$s waits for its field schema.', 'alphabridge-mcp' ), implode( ', ', AB_MCP_Sync_Post_Writer::TYPES ), (string) ( $entry['subtype'] ?? '' ) ), 'Z8d' );
		} elseif ( in_array( (string) ( $entry['subtype'] ?? '' ), AB_MCP_Sync_Post_Writer::THEMED, true ) ) {
			// A template, a template part or the global styles of a theme that
			// is not the active one here (branch 9b): a staging site keeps the
			// rows of every theme it tried, so the plan names them locked with
			// the way (all leaves them out) rather than the check before the
			// first write ending the run for them; the check and the boundary
			// ask it again (AB_MCP_Sync_Post_Writer::theme_refusal()).
			$theme = isset( $entry['staging']['identity']['theme'] ) && is_string( $entry['staging']['identity']['theme'] ) ? $entry['staging']['identity']['theme'] : '';
			$other = AB_MCP_Sync_Post_Writer::inactive_theme_detail( (string) $entry['subtype'], (string) ( $entry['staging']['identity']['post_name'] ?? '' ), $theme );
			if ( null !== $other ) {
				$held = AB_MCP_Sync_Kinds::lock( 'post', 'theme_mismatch' );
				return $lock( sprintf( '%1$s (post:theme_mismatch) %2$s', AB_MCP_Sync_Said::text( (string) $held['reason'] ), $other ), (string) $held['stage'] );
			}
		}
		$staging = isset( $entry['staging']['ref'] ) ? (string) $entry['staging']['ref'] : '';
		if ( '' === $staging ) {
			return $lock( __( 'The staging side of this entry has no ref (held by a credential): nothing is written for it.', 'alphabridge-mcp' ) );
		}
		$op = 'create' === $action ? 'create' : 'update';
		if ( 'update' === $op && ( ! isset( $entry['pair']['id'] ) || ! isset( $entry['live']['ref'] ) ) ) {
			return $lock( __( 'The entry names no pair or no object of this site to write over.', 'alphabridge-mcp' ) );
		}
		return array(
			'item' => array(
				'n'           => $n,
				'kind'        => (string) $entry['kind'],
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
		// The objects the run creates, by the space of their ids: a post in
		// the space of posts, a term in the space of terms (a new post in a
		// new category, a new term under a new parent: branch 9).
		$at = array();
		foreach ( $items as $n => $item ) {
			if ( 'create' === $item['op'] ) {
				// A menu in the space of terms, a menu item in that of posts
				// (branch 11; the spaces of the pairs).
				$at[ ( in_array( $item['kind'], AB_MCP_Sync_Proposal::SPACES['term'], true ) ? 'term' : 'post' ) . "\0" . $item['staging_ref'] ] = $n;
			}
		}
		// The objects of the kind post the run writes, created or changed, by
		// their staging id and by the name another object names them by
		// (branch 9b, Codex round 1, B).
		$posts = array();
		$named = array();
		foreach ( $items as $n => $item ) {
			if ( 'post' !== $item['kind'] ) {
				continue;
			}
			$posts[ (int) $item['staging_ref'] ] = $n;
			if ( isset( $item['order_name'] ) && null !== $item['order_name'] ) {
				$named[ (string) $item['order_name'] ] = $n;
			}
		}
		$edges    = array();
		$exchange = array();
		foreach ( $items as $n => $item ) {
			$items[ $n ]['depends_on'] = array();
			$items[ $n ]['after']      = array();
			foreach ( $item['refs'] as list( $space, $id ) ) {
				$key = $space . "\0" . $id;
				if ( isset( $at[ $key ] ) && $at[ $key ] !== $n ) {
					$items[ $n ]['depends_on'][] = $at[ $key ];
					$edges[ $n ][ $at[ $key ] ] = true;
				} elseif ( isset( $at[ $key ] ) ) {
					$edges[ $n ][ $n ] = true;
				}
			}
			// Each reference the boundary asks (a reusable block or a
			// navigation by its ref, a template part by theme and slug, the
			// template of a page) is an edge where its target is written in
			// the same run. It is a dependency only where this site could not
			// resolve the target without its step (a target the run creates
			// and that has no row and no file of the active theme here:
			// without it the boundary refuses the object; a cycle is locked);
			// else it is an order, the target first, the object written all
			// the same where the target fails (a target the run changes, or a
			// new row of a part the theme has as a file; Codex rounds 1 and 2).
			$targets = array();
			foreach ( isset( $item['order_refs']['posts'] ) ? $item['order_refs']['posts'] : array() as $id ) {
				if ( isset( $posts[ (int) $id ] ) ) {
					$targets[] = array( $posts[ (int) $id ], false );
				}
			}
			foreach ( array( 'parts', 'templates' ) as $list ) {
				foreach ( isset( $item['order_refs'][ $list ] ) ? $item['order_refs'][ $list ] : array() as $name ) {
					if ( isset( $named[ (string) $name ] ) ) {
						$targets[] = array( $named[ (string) $name ], isset( $item['order_refs']['here'][ (string) $name ] ) );
					}
				}
			}
			foreach ( $targets as list( $m, $here ) ) {
				if ( $m === $n ) {
					continue;
				}
				if ( 'create' === $items[ $m ]['op'] && ! $here ) {
					$items[ $n ]['depends_on'][] = $m;
					$edges[ $n ][ $m ]           = true;
				} else {
					$items[ $n ]['after'][] = $m;
				}
			}
			// A menu after the menus of the run whose positions it takes (B2).
			foreach ( isset( $item['menu_after'] ) ? $item['menu_after'] : array() as $m ) {
				if ( $m !== $n && isset( $items[ $m ] ) ) {
					$items[ $n ]['after'][] = $m;
					$exchange[ $n ][ $m ]   = true;
				}
			}
			$items[ $n ]['after'] = array_values( array_unique( $items[ $n ]['after'] ) );
			$items[ $n ]['depends_on'] = array_values( array_unique( $items[ $n ]['depends_on'] ) );
		}
		$locked = array();
		// Menus that take each other's position (an exchange: a loop of these
		// orders) are locked, each of them: no order lets every step find the
		// others as planned (B2, menu:position_exchange), and none of them is
		// written halfway.
		foreach ( AB_MCP_Sync_Decision::cycles( $exchange ) as $members ) {
			$lock = AB_MCP_Sync_Kinds::lock( 'menu', 'position_exchange' );
			foreach ( $members as $n ) {
				if ( isset( $items[ $n ] ) ) {
					$locked[] = array(
						'n'       => (int) $n,
						'kind'    => $items[ $n ]['kind'],
						'subtype' => $items[ $n ]['subtype'],
						'action'  => $items[ $n ]['action'],
						'result'  => '',
						'reason'  => sprintf( '%1$s (%2$s)', AB_MCP_Sync_Said::text( (string) $lock['reason'] ), (string) $lock['key'] ),
						'stage'   => $lock['stage'],
					);
					unset( $items[ $n ] );
				}
			}
		}
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
						'reason'  => AB_MCP_Sync_Said::text( (string) $lock['reason'] ),
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
							/* translators: %d: entry number of the report. */
							'reason'  => sprintf( __( 'It refers to entry %d, which is locked.', 'alphabridge-mcp' ), (int) $m ),
							'stage'   => null,
						);
						unset( $items[ $n ] );
						$changed = true;
						break;
					}
				}
			}
		}
		// Topological order: each time the first entry whose targets are
		// placed, the terms first by their depth in the tree the run leaves
		// (term_depths(): written from the root of that tree down, all the
		// ancestors of a term stand where the run puts them when it is
		// written, and as that tree has no loop, its new parent is never one
		// of its descendants; branch 9, Codex rounds 1 and 2, B4), then the
		// template parts and the templates (NAMED_FIRST, branch 9b), then the
		// other objects; within the same depth by the entry of the report
		// (stable, whatever order the entries came in). The order is no
		// dependency: a step whose term deviates leaves the others to run,
		// and each step asks its own loop of parents as it writes
		// (term:parent, fail closed).
		$ordered = array();
		$placed  = array();
		$left    = $items;
		uksort(
			$left,
			static function ( $a, $b ) use ( $items ) {
				$rank = static function ( $n ) use ( $items ) {
					if ( 'term' === $items[ $n ]['kind'] ) {
						return array( 0, isset( $items[ $n ]['depth'] ) ? (int) $items[ $n ]['depth'] : 0, (int) $n );
					}
					// The objects named by a name rather than an id (branch 9b): the
					// template parts a template names by theme and slug, then the
					// templates a page names by slug (page_template), then the rest.
					$named = array_search( (string) ( $items[ $n ]['subtype'] ?? '' ), self::NAMED_FIRST, true );
					$last  = in_array( (string) ( $items[ $n ]['subtype'] ?? '' ), self::LAST, true ) ? 1 : 0;
					return array( 1, false === $named ? count( self::NAMED_FIRST ) + $last : (int) $named, (int) $n );
				};
				return $rank( $a ) <=> $rank( $b );
			}
		);
		$rounds = 0;
		while ( array() !== $left && $rounds < self::MAX_OBJECTS + 1 ) {
			++$rounds;
			$progress = false;
			// The first entry whose dependencies and changed targets are
			// placed (a changed target no longer in the plan counts as placed);
			// where the changed targets close a loop of their own, the first
			// whose dependencies are placed (they are an order, no dependency).
			foreach ( array( true, false ) as $with_after ) {
				foreach ( $left as $n => $item ) {
					$ready = true;
					foreach ( $item['depends_on'] as $m ) {
						if ( ! isset( $placed[ $m ] ) ) {
							$ready = false;
						}
					}
					foreach ( $with_after ? $item['after'] : array() as $m ) {
						if ( isset( $left[ $m ] ) ) {
							$ready = false;
						}
					}
					if ( $ready ) {
						$ordered[ $n ] = $item;
						$placed[ $n ]  = true;
						unset( $left[ $n ] );
						$progress = true;
						break 2;
					}
				}
			}
			if ( ! $progress ) {
				return new WP_Error( 'ab_mcp_sync_push_order', __( 'The objects chosen cannot be ordered by their references (a cycle the lock did not catch). Choose fewer objects.', 'alphabridge-mcp' ) );
			}
		}
		return array(
			'items'  => $ordered,
			'locked' => $locked,
		);
	}

	/**
	 * The depth of each term the run writes in the tree it leaves: every
	 * term here with its parent here, the terms of the run with the parent
	 * they are given (the parent of the staging site, by the run when it
	 * writes that parent too, else by its pair); a new term stands as a node
	 * of its own until its step made it. 0 for a root; a parent without a
	 * pair, or a loop (which the check locks, term:parent), ends the walk.
	 *
	 * @param array    $items  The items of the plan (kind, op, staging_ref, live_ref, subtype, parent_ref).
	 * @param callable $mapped Staging id of a term => its id here, or null.
	 * @return array<int,int> n => depth, for the terms.
	 */
	private static function term_depths( array $items, callable $mapped ) {
		$node_of = array();
		foreach ( $items as $n => $item ) {
			if ( 'term' === $item['kind'] ) {
				$node_of[ (string) $item['staging_ref'] ] = 'update' === $item['op'] ? 'l:' . (int) $item['live_ref'] : 'n:' . (string) $item['staging_ref'];
			}
		}
		// The parent each term of the run is given: a node, null for none (a
		// root), false where it has no counterpart here.
		$given = array();
		foreach ( $items as $n => $item ) {
			if ( 'term' !== $item['kind'] ) {
				continue;
			}
			$node = $node_of[ (string) $item['staging_ref'] ];
			if ( ! isset( $item['parent_ref'] ) ) {
				$given[ $node ] = null;
			} elseif ( isset( $node_of[ (string) $item['parent_ref'] ] ) ) {
				$given[ $node ] = $node_of[ (string) $item['parent_ref'] ];
			} else {
				$here           = $mapped( (string) $item['parent_ref'] );
				$given[ $node ] = null === $here ? false : 'l:' . (int) $here;
			}
		}
		$out = array();
		foreach ( $items as $n => $item ) {
			if ( 'term' !== $item['kind'] ) {
				continue;
			}
			$taxonomy = isset( $item['subtype'] ) ? (string) $item['subtype'] : '';
			$at       = $node_of[ (string) $item['staging_ref'] ];
			$seen     = array( $at => true );
			$depth    = 0;
			while ( $depth <= self::MAX_OBJECTS ) {
				if ( array_key_exists( $at, $given ) ) {
					$up = $given[ $at ];
				} elseif ( 0 === strpos( $at, 'l:' ) ) {
					// A term the run does not write: its parent here.
					$parent = (int) wp_get_term_taxonomy_parent_id( (int) substr( $at, 2 ), $taxonomy );
					$up     = $parent > 0 ? 'l:' . $parent : null;
				} else {
					$up = null;
				}
				if ( ! is_string( $up ) || isset( $seen[ $up ] ) ) {
					break;
				}
				$seen[ $up ] = true;
				$at          = $up;
				++$depth;
			}
			$out[ $n ] = $depth;
		}
		return $out;
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
				$shown['next']        = __( 'This plan has its run already (the same report, selection, account and connection).', 'alphabridge-mcp' ) . ' Go on with it: action=next with this run_id.';
				return $shown;
			}
		}
		$stored = $plan;
		unset( $stored['steps'], $stored['state'], $stored['existing_run'], $stored['resources'], $stored['locked'], $stored['skipped'], $stored['code_shown'] );
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
		if ( isset( $plan['code'] ) ) {
			// A run of the code keeps what it reads until its swaps (the
			// packages of the environment of its report, its packages of code;
			// Z8b branch 5, V20 of its pre-check): each binding checked, and a
			// run that cannot keep them is not left open.
			$bound = AB_MCP_Sync_Code_Plan::bind( $plan, (int) $run['run_id'] );
			if ( is_wp_error( $bound ) ) {
				$closed = AB_MCP_Sync_Engine::abort( $run );
				/* translators: 1: why (one or more whole sentences), 2: run number. */
				return is_wp_error( $closed ) ? $closed : new WP_Error( $bound->get_error_code(), sprintf( __( '%1$s Run %2$d was made for this plan and closed again as aborted at once: nothing was staged or written. Call wp_site_push action=plan again once the packages are there.', 'alphabridge-mcp' ), $bound->get_error_message(), (int) $run['run_id'] ) );
			}
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
		/* translators: %d: number of steps of the run. */
		$shown['next']        = sprintf( _n( 'The run is made with %d step and nothing is written yet.', 'The run is made with %d steps and nothing is written yet.', count( $plan['steps'] ), 'alphabridge-mcp' ), count( $plan['steps'] ) ) . ( isset( $plan['code'] )
			? ' Call action=next with this run_id: the first step checks the means; then each unit is staged in the protected store (over as many calls as the check of its PHP files takes, nothing of this site changes) and swapped (one call under the lock, with the probe of the site before and after); then the caches, then the check of the run closes it. Each step answers with its result; status shows the whole run. A run of the code holds no step of the contents: make a new report (wp_site_diff) after it.'
			: ' Call action=next with this run_id: the first step checks everything before the first write, each next one writes one object under the lock with its backup and journal, then the caches, then the check of the run closes it. Each step answers with its result; status shows the whole run.' );
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
			$unit = AB_MCP_Sync_Code_Plan::step( $plan, $seq );
			if ( null !== $unit ) {
				$shown['unit'] = $unit;
			}
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
			// The person sees the preview (AB_MCP_Confirm_Token): its words in
			// the language of the site (Codex round 2 of branch 13, B1).
			'environment' => $plan['environment'] ? _x( 'compared', 'the environment of the report, in the preview of a takeover', 'alphabridge-mcp' ) : __( 'not compared: the report was made without the packages of the environment; the run writes objects only, which this does not hold back', 'alphabridge-mcp' ),
			'effect'      => __( 'Makes a run that writes these objects of the staging site onto this site, one per call (action=next), each under the lock of AlphaBridge with a backup and a journal, read back against its target; a new object stays on the way back (a rest); the pairs take new bases. Nothing is deleted. The way back is wp_site_revert, pair by pair, byte for byte conditional. A change of another user or process on a way the lock does not refuse (wp_publish_post() by the cron, terms on the classic way, SQL) between the check and the write can be lost without the read back seeing it.', 'alphabridge-mcp' ),
		);
		if ( isset( $plan['code'] ) ) {
			// A run of the code (Z8b branch 5, Z42): per unit the operation,
			// the versions, the sentence on its routines and the paths that
			// lie in the backup afterwards.
			$out['units']  = AB_MCP_Sync_Code_Plan::shown( $plan );
			$out['effect'] = __( 'Makes a run of the code: each plugin or theme named is staged in the protected store first (nothing of this site changes), then swapped in one call under the lock of AlphaBridge, with a probe of the site before and after and the old version kept whole in the backup; a swap whose probe fails afterwards is swapped back in the same call. The run holds no step of the contents: make a new report (wp_site_diff) after it. Nothing is written by this call; the confirmed call makes the run.', 'alphabridge-mcp' );
		}
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
				'keep_until'        => (int) $run['keep_until'],
				'own'               => null === AB_MCP_Sync_Run::owner_refusal( $run ),
			);
		}
		if ( null !== $open ) {
			$settled = AB_MCP_Sync_Run::settle( $open );
			$open    = is_wp_error( $settled ) ? $open : $settled;
		}
		$out = array(
			'open' => null,
			'runs' => $runs,
			'next' => null === $open
				? __( 'No run is open on this site.', 'alphabridge-mcp' ) . ' Plan one with action=plan (report_id, select or all, packages).'
				/* translators: 1: run number, 2: its state, as the tools name it (planned, running, …). */
				: sprintf( __( 'Run %1$d is %2$s.', 'alphabridge-mcp' ), (int) $open['run_id'], $open['state'] ) . ' ' . AB_MCP_Sync_Run::way( $open ),
		);
		if ( null !== $open ) {
			$out['open'] = array(
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
			return new WP_Error( 'ab_mcp_sync_push_run', __( 'Pass run_id, the number the answer of action=plan gave (action=status without run_id lists the runs).', 'alphabridge-mcp' ) );
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
	 * @param array $open The run that is not closed.
	 * @return WP_Error
	 */
	private static function another_open( array $open ) {
		return new WP_Error(
			'ab_mcp_sync_run_open',
			/* translators: 1: run number, 2: its type, as the tools name it (push, revert), 3: its state, as the tools name it, 4: the way it goes on, as AB_MCP_Sync_Run::way() names it (whole sentences). */
			sprintf( __( 'This site has a run that is not closed (run %1$d, %2$s, state %3$s), and at most one run per site is open at a time, so no new plan begins. Ask for its status (wp_site_push action=status). %4$s', 'alphabridge-mcp' ), (int) $open['run_id'], $open['type'], $open['state'], AB_MCP_Sync_Run::way( $open ) ),
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
	 * The first five locked entries, each with its number, kind, subtype
	 * and why it is locked (a whole sentence, or a reason of the report).
	 *
	 * @param array[] $locked The locked entries of the plan.
	 * @return string
	 */
	private static function locked_list( array $locked ) {
		return implode(
			' ',
			array_map(
				/* translators: 1: entry number of the report, 2: kind of the object, as the tools name it, 3: its subtype (post type, taxonomy, …), 4: why the entry is locked, a whole sentence. */
				static fn( $l ) => sprintf( __( 'Entry %1$d (%2$s %3$s): %4$s', 'alphabridge-mcp' ), $l['n'], $l['kind'], $l['subtype'], $l['reason'] ),
				array_slice( $locked, 0, 5 )
			)
		);
	}

	/**
	 * @param string $what What is wrong with the selection, a whole sentence.
	 * @return WP_Error
	 */
	private static function selection( $what ) {
		/* translators: %s: what is wrong with the selection, a whole sentence. */
		return new WP_Error( 'ab_mcp_sync_push_selection', sprintf( __( 'The selection is not one a run takes: %s Pass select (entry numbers of the report of kind post or term with action take_over or create), choices (entry and choice for a conflict), as_draft (entry numbers taken over with the status draft) or all=true, and packages (the package_ids of the objects).', 'alphabridge-mcp' ), $what ) );
	}

	/**
	 * The refusal of a selection with more objects than one plan writes
	 * (MAX_OBJECTS), with the way in parts (N4); null where it fits.
	 *
	 * @param int $count Objects the selection has.
	 * @return WP_Error|null
	 */
	public static function too_many( $count ) {
		if ( (int) $count <= self::MAX_OBJECTS ) {
			return null;
		}
		/* translators: 1: the most objects one run writes, 2: the objects of this selection. */
		return self::selection( sprintf( __( 'One run writes at most %1$d objects; this selection has %2$d. Plan in parts: pass select with at most %1$d entry numbers of this report (instead of all=true), run that plan to its close (action=next until it is closed), then plan the next entries from the same report_id with the same packages; the entries a run wrote read equal in the next report.', 'alphabridge-mcp' ), self::MAX_OBJECTS, (int) $count ) );
	}
}
