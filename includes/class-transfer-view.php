<?php
/**
 * The section «Takeover» of the page «Staging» (Z8, decision 35 of the plan
 * of 01.11.2026, branch 12), drawn from what AB_MCP_Transfer::view() read:
 * nothing is read here. Top to bottom: the outcome of the last form; on
 * the production site of a pair the key of the staging site (confirmed
 * above, in «Key for taking changes over»); the proof of the protected
 * store with the rule for the host; the notice of a moment (one at most,
 * AB_MCP_Transfer_Moments); an interrupted run with its journal, its parts,
 * its rest and «Close as checked»; the run whose steps were asked for, with
 * a form per pair to take it back; the last report with M1; the runs with
 * their state, steps, the time their backups are kept, M2 and «Abort»; the
 * pairs of the source with «Discard»; the switch «Hide edition notes»,
 * where an edition note can show at all. On the staging site, the way and
 * the proof of the store; on any other site one line.
 *
 * Every form posts to admin-post.php with an action of its own and a nonce
 * bound to its object (the run, the step, the pair), which AB_MCP_Admin
 * checks with manage_options before anything is done.
 *
 * The texts are in gettext calls with the text domain alphabridge-mcp and
 * translated in the nine bundled languages (branch 13, text/z8), each a
 * whole sentence with its placeholders. What the journal and the tables
 * show of the tools themselves (the operation, the kind, the state of a
 * step, a part or a pair, the references of objects) stays as the tools
 * name it.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Transfer_View
 */
final class AB_MCP_Transfer_View {

	/**
	 * The word of the state of a run; an unknown state as it is.
	 *
	 * @param string $state The state (AB_MCP_Sync_Run).
	 * @return string
	 */
	public static function run_state( $state ) {
		switch ( (string) $state ) {
			case 'open':
				return _x( 'open', 'state of a run', 'alphabridge-mcp' );
			case 'interrupted':
				return _x( 'interrupted', 'state of a run', 'alphabridge-mcp' );
			case 'reverting':
				return _x( 'going back', 'state of a run', 'alphabridge-mcp' );
			case 'closed_done':
				return _x( 'done', 'state of a run', 'alphabridge-mcp' );
			case 'closed_findings':
				return _x( 'closed with findings', 'state of a run', 'alphabridge-mcp' );
			case 'closed_aborted':
				return _x( 'aborted', 'state of a run', 'alphabridge-mcp' );
			case 'closed_checked':
				return _x( 'closed as checked', 'state of a run', 'alphabridge-mcp' );
			case 'closed_reverted':
				return _x( 'taken back', 'state of a run', 'alphabridge-mcp' );
		}
		return (string) $state;
	}

	/**
	 * The section.
	 *
	 * @param array $view AB_MCP_Transfer::view().
	 * @return string HTML, escaped.
	 */
	public static function html( array $view ) {
		$html  = '<section class="ab-card ab-staging-transfer" id="' . esc_attr( AB_MCP_Transfer::ANCHOR ) . '" aria-labelledby="ab-staging-transfer-title">';
		$html .= '<p class="ab-eyebrow">' . esc_html__( 'Takeover', 'alphabridge-mcp' ) . '</p>';
		$html .= '<h2 class="ab-card__title" id="ab-staging-transfer-title">' . esc_html__( 'Take changes over from the staging site', 'alphabridge-mcp' ) . '</h2>';
		if ( null !== $view['outcome'] ) {
			$html .= '<div class="notice inline notice-' . ( $view['outcome']['ok'] ? 'success' : 'error' ) . ' ab-staging-transfer__outcome"><p>' . esc_html( $view['outcome']['text'] ) . '</p></div>';
		}
		if ( 'staging' === $view['side'] ) {
			$html .= '<p class="ab-direct__status">' . esc_html( $view['status']['next'] ) . '</p>';
			$html .= self::store_html( $view['store'] );
			return $html . '</section>';
		}
		$active = 'production' === $view['side'] ? $view['status']['anchor']['active'] : null;
		$runs   = array() !== $view['runs'] || array() !== ( $view['pinned'] ?? array() ) || null !== $view['interrupted'] || null !== $view['run'];
		if ( null !== $active ) {
			/* translators: %s: the fingerprint of the key, as code. */
			$html .= '<p class="ab-direct__status">' . sprintf( esc_html__( 'The key of the staging site is confirmed (fingerprint %s): this site reads its packages and takes over what a person approves.', 'alphabridge-mcp' ), '<code>' . esc_html( $active['fingerprint'] ) . '</code>' ) . '</p>';
			$html .= self::store_html( $view['store'] );
			if ( ! $view['status']['tables'] ) {
				return $html . '<p class="ab-direct__status">' . esc_html( $view['status']['next'] ) . '</p></section>';
			}
		} elseif ( 'production' === $view['side'] ) {
			$html .= '<p class="ab-direct__status">' . esc_html( $view['status']['next'] ) . '</p>';
			$html .= self::store_html( $view['store'] );
		} else {
			$html .= '<p class="ab-note">' . esc_html__( 'Shown here once this site is the confirmed production site paired with a staging site: the report of what differs, the runs that take changes over, and the way back pair by pair.', 'alphabridge-mcp' ) . '</p>';
		}
		if ( null === $active && ! $runs ) {
			return $html . '</section>';
		}
		if ( null === $active ) {
			// The runs stay without the key (Codex round 1 of branch 12, B);
			// only what needs it is left out, and why.
			$html .= '<p class="ab-note ab-staging-transfer__no-key">' . esc_html__( 'The report, the object pairs, taking a pair back and going on with a way back need the confirmed key of the staging site, so they are not shown while there is none. The runs of this site stay: their journal, «Close as checked» and «Abort» need no key.', 'alphabridge-mcp' ) . '</p>';
		}
		$html .= self::notice_html( $view['notice'], $view['notes'] );
		if ( null !== $view['interrupted'] ) {
			$html .= self::interrupted_html( $view['interrupted'] );
		}
		if ( null !== $view['run'] && ( null === $view['interrupted'] || (int) $view['run']['run']['run_id'] !== (int) $view['interrupted']['run']['run_id'] ) ) {
			$html .= self::run_html( $view['run'], $view['notice'] );
		}
		if ( null !== $active ) {
			$html .= self::report_html( $view['report'], $view['notice'] );
		}
		$html .= self::runs_html( $view['runs'], $view['pinned'] ?? array(), $view['runs_next'] ?? null, (int) ( $view['pinned_more'] ?? 0 ) );
		$html .= self::pairs_html( $view['pairs'] );
		// The switch stands in the notice where one shows, else here.
		$html .= null === $view['notice'] ? self::notes_html( $view['notes'] ) : '';
		return $html . '</section>';
	}

	/**
	 * The line above the card while a run is interrupted (decision 18: the
	 * page shows it first), with the way to its journal; '' otherwise.
	 *
	 * @param array|null $open The run that is not closed, settled.
	 * @return string HTML, escaped.
	 */
	public static function interrupted_line( $open ) {
		if ( ! is_array( $open ) || 'interrupted' !== $open['state'] ) {
			return '';
		}
		/* translators: %d: run number. */
		return '<div class="notice notice-warning inline ab-staging-transfer__alert"><p>' . esc_html( sprintf( __( 'Run %d is interrupted: no step runs and no new run begins until a person closes it as checked or the way back leads it on.', 'alphabridge-mcp' ), (int) $open['run_id'] ) ) . ' <a href="' . esc_url( self::url( array(), AB_MCP_Transfer::ANCHOR ) ) . '">' . esc_html__( 'Read its journal', 'alphabridge-mcp' ) . '</a></p></div>';
	}

	/* ---------------------------------------------------------- the parts */

	/**
	 * The proof of the protected store.
	 *
	 * @param array|null $store AB_MCP_Transfer::store().
	 * @return string
	 */
	private static function store_html( $store ) {
		if ( ! is_array( $store ) ) {
			return '';
		}
		$html = '<div class="ab-staging ab-staging-transfer__store"><h3 class="ab-setting__title">' . esc_html__( 'Protected store', 'alphabridge-mcp' ) . '</h3>';
		if ( ! $store['available'] ) {
			return $html . '<p class="ab-direct__status">' . esc_html( (string) $store['message'] ) . '</p></div>';
		}
		if ( 'outside' === $store['where'] ) {
			$where = $store['checked'] > 0
				/* translators: %s: date and time of the self-check. */
				? sprintf( __( 'Beside the WordPress folder, outside the web root: no web request reaches the packages, reports and backups, checked on %s.', 'alphabridge-mcp' ), self::when( $store['checked'] ) )
				: __( 'Beside the WordPress folder, outside the web root: no web request reaches the packages, reports and backups.', 'alphabridge-mcp' );
		} else {
			$where = $store['checked'] > 0
				/* translators: %s: date and time of the self-check. */
				? sprintf( __( 'In the uploads folder, closed to the web, checked on %s.', 'alphabridge-mcp' ), self::when( $store['checked'] ) )
				: __( 'In the uploads folder, closed to the web.', 'alphabridge-mcp' );
		}
		$html .= '<p class="ab-direct__status">' . esc_html( $where ) . '</p>';
		if ( null !== $store['rule'] ) {
			$html .= '<p class="ab-note ab-staging-transfer__rule">' . esc_html( $store['rule'] ) . '</p>';
		}
		return $html . '</div>';
	}

	/**
	 * The notice of a moment: its operating part and its edition part, the
	 * features as lines with a link (no switch), and «Hide edition notes».
	 *
	 * @param array|null $notice AB_MCP_Transfer::view()['notice'].
	 * @param array      $notes  possible, hidden.
	 * @return string
	 */
	private static function notice_html( $notice, array $notes ) {
		if ( ! is_array( $notice ) ) {
			return '';
		}
		$html = '<div class="notice notice-info inline ab-staging-transfer__moment" data-moment="' . esc_attr( $notice['moment'] ) . '"><p>' . esc_html( (string) $notice['text'] ) . '</p>';
		$html .= '<p>' . esc_html( $notice['edition']['text'] ) . '</p><p>';
		$links = array();
		foreach ( $notice['edition']['links'] as $link ) {
			$links[] = '<a href="' . esc_url( $link['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $link['label'] ) . '</a>';
		}
		$html .= implode( ' · ', $links ) . '</p>';
		return $html . self::notes_html( $notes ) . '</div>';
	}

	/**
	 * An interrupted run (decision 18): its journal, parts with their state,
	 * its rest, and «Close as checked».
	 *
	 * @param array $run AB_MCP_Transfer::run_view().
	 * @return string
	 */
	private static function interrupted_html( array $run ) {
		$id   = (int) $run['run']['run_id'];
		/* translators: %d: run number. */
		$html = '<div class="ab-staging ab-staging-transfer__interrupted"><h3 class="ab-setting__title">' . esc_html( sprintf( __( 'Run %d is interrupted', 'alphabridge-mcp' ), $id ) ) . '</h3>';
		$html .= '<p class="ab-direct__status">' . esc_html__( 'A step began and the request that ran it ended before it finished. Nothing more is written until a person decides: read the journal below, check the objects it names in wp-admin, then close the run as checked here (nothing is written; the pairs of its steps left begun become unclear), or let the connection that made it lead its way back (wp_site_revert action=plan with this run, confirmed with its token).', 'alphabridge-mcp' ) . '</p>';
		$html .= self::journal_html( $run );
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'ab_mcp_transfer_checked_' . $id, '_wpnonce', true, false );
		$html .= '<input type="hidden" name="action" value="ab_mcp_transfer_checked"><input type="hidden" name="run_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="state" value="' . esc_attr( (string) $run['print'] ) . '">';
		$html .= '<p><button class="ab-btn">' . esc_html__( 'Close as checked', 'alphabridge-mcp' ) . '</button></p></form>';
		return $html . '</div>';
	}

	/**
	 * One run with its steps; for a closed run of the type push a form per
	 * pair to take it back, with what the way back would do.
	 *
	 * @param array      $run    AB_MCP_Transfer::run_view().
	 * @param array|null $notice The notice of this view.
	 * @return string
	 */
	private static function run_html( array $run, $notice ) {
		$id   = (int) $run['run']['run_id'];
		/* translators: 1: run number, 2: its state (open, done, …). */
		$html = '<div class="ab-staging ab-staging-transfer__run"><h3 class="ab-setting__title">' . esc_html( sprintf( __( 'Run %1$d (%2$s): its steps', 'alphabridge-mcp' ), $id, self::run_state( $run['run']['state'] ) ) ) . '</h3>';
		if ( null !== $run['m2'] ) {
			$html .= '<p class="ab-note ab-staging-transfer__m2">' . esc_html( $run['m2'] ) . '</p>';
		}
		if ( null !== $run['m3'] && ! ( is_array( $notice ) && 'm3' === $notice['moment'] ) ) {
			$html .= '<p class="ab-note ab-staging-transfer__m3">' . esc_html( $run['m3'] ) . '</p>';
		}
		$html .= self::journal_html( $run );
		if ( array() !== $run['pairs'] ) {
			$html .= '<h4>' . esc_html__( 'The way back, pair by pair', 'alphabridge-mcp' ) . '</h4><p class="ab-note">' . esc_html__( 'Each pair goes back on its own, byte for byte where a part is still what the run wrote. A part changed here since is left with its value before; an object the run made stays as a rest.', 'alphabridge-mcp' ) . '</p>';
			$html .= '<div class="ab-table-wrap"><table class="ab-table ab-staging-transfer__back"><thead><tr><th scope="col">' . esc_html__( 'Step', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'Object', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'The way back', 'alphabridge-mcp' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'alphabridge-mcp' ) . '</span></th></tr></thead><tbody>';
			$will  = array(
				'back'           => __( 'goes back', 'alphabridge-mcp' ),
				'back where own' => __( 'goes back where a part is still the run\'s', 'alphabridge-mcp' ),
				'stays'          => __( 'stays as it is (it deviated within its step): check it by hand', 'alphabridge-mcp' ),
				'rest'           => __( 'made by the run: stays as a rest', 'alphabridge-mcp' ),
			);
			foreach ( $run['pairs'] as $pair ) {
				$html .= '<tr><td>' . esc_html( (string) $pair['seq'] ) . '</td><td>' . esc_html( $pair['kind'] . ' ' . ( null !== $pair['live_ref'] ? $pair['live_ref'] : '' ) ) . '</td><td>' . esc_html( $will[ $pair['will'] ] ?? $pair['will'] ) . '</td><td>';
				if ( null !== $pair['plan_state'] ) {
					$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'ab_mcp_transfer_back_' . $id . '_' . (int) $pair['seq'], '_wpnonce', true, false );
					$html .= '<input type="hidden" name="action" value="ab_mcp_transfer_back"><input type="hidden" name="run_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="seq" value="' . esc_attr( (string) (int) $pair['seq'] ) . '"><input type="hidden" name="state" value="' . esc_attr( $pair['plan_state'] ) . '">';
					$html .= '<button class="ab-btn ab-btn--ghost">' . esc_html__( 'Take this pair back', 'alphabridge-mcp' ) . '</button></form>';
				}
				$html .= '</td></tr>';
			}
			$html .= '</tbody></table></div>';
		} elseif ( null !== $run['no_back'] ) {
			$html .= '<p class="ab-note">' . esc_html( $run['no_back'] ) . '</p>';
		}
		return $html . '</div>';
	}

	/**
	 * The journal of a run: each step with its object, its state and its
	 * parts with theirs; the rest (the steps not run) and the objects left
	 * as a rest.
	 *
	 * @param array $run AB_MCP_Transfer::run_view().
	 * @return string
	 */
	private static function journal_html( array $run ) {
		if ( null === $run['shown'] ) {
			return '<p class="ab-direct__status">' . esc_html( (string) $run['error'] ) . '</p>';
		}
		$html = '<div class="ab-table-wrap"><table class="ab-table ab-staging-transfer__journal"><thead><tr><th scope="col">' . esc_html__( 'Step', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'What', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'State', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'Parts', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'Finding', 'alphabridge-mcp' ) . '</th></tr></thead><tbody>';
		foreach ( $run['shown']['steps'] as $step ) {
			$object = is_array( $step['parts'] ) && is_array( $step['parts']['object'] ?? null ) ? $step['parts']['object'] : null;
			$what   = $step['op'] . ( null !== $object ? ': ' . ( $object['kind'] ?? '' ) . ' ' . ( isset( $object['live_ref'] ) && null !== $object['live_ref'] ? (string) $object['live_ref'] : (string) ( $object['staging_ref'] ?? '' ) ) : '' );
			$back   = $run['back_of'][ (int) $step['seq'] ] ?? null;
			if ( null !== $back ) {
				// A step of the way back: the step it takes back and its object.
				$what = (int) $back['run'] !== (int) $run['run']['run_id']
					/* translators: 1: step taken back, 2: its run, 3: kind of the object, 4: its reference on this site. */
					? sprintf( __( 'revert of step %1$d of run %2$d: %3$s %4$s', 'alphabridge-mcp' ), $back['seq'], $back['run'], $back['kind'], $back['live_ref'] )
					/* translators: 1: step taken back, 2: kind of the object, 3: its reference on this site. */
					: sprintf( __( 'revert of step %1$d: %2$s %3$s', 'alphabridge-mcp' ), $back['seq'], $back['kind'], $back['live_ref'] );
			}
			$parts = array();
			foreach ( is_array( $step['parts'] ) && isset( $step['parts']['parts'] ) && is_array( $step['parts']['parts'] ) ? $step['parts']['parts'] : array() as $part ) {
				$parts[] = ( $part['part'] ?? '' ) . ' ' . ( $part['state'] ?? '' );
			}
			// The outcome of a step as the tool reports it (Codex round 1 of
			// branch 12, B): each part with its state, why it was left and
			// the value it held before (checked as it leaves the run,
			// AB_MCP_Sync_Run::cap_values(): never a credential).
			$result = isset( $step['result'] ) && is_array( $step['result'] ) ? $step['result'] : array();
			foreach ( isset( $result['parts'] ) && is_array( $result['parts'] ) ? $result['parts'] : array() as $part ) {
				if ( ! is_array( $part ) ) {
					continue;
				}
				/* translators: %s: the value a part held before. */
				$before  = isset( $part['value_before'] ) && is_string( $part['value_before'] ) ? ' ' . sprintf( __( '(before: %s)', 'alphabridge-mcp' ), $part['value_before'] ) : '';
				$parts[] = trim( ( $part['part'] ?? '' ) . ' ' . ( $part['state'] ?? '' ) . ( isset( $part['note'] ) && '' !== (string) $part['note'] ? ': ' . (string) $part['note'] : '' ) . $before );
			}
			$finding = isset( $result['message'] ) && is_string( $result['message'] ) ? $result['message'] : '';
			$html   .= '<tr><td>' . esc_html( (string) $step['seq'] ) . '</td><td>' . esc_html( trim( $what ) ) . '</td><td>' . esc_html( (string) $step['state'] ) . '</td><td>' . esc_html( implode( '; ', $parts ) ) . '</td><td>' . esc_html( $finding ) . '</td></tr>';
		}
		$html .= '</tbody></table></div>';
		if ( array() !== $run['rest'] ) {
			$left = array();
			foreach ( $run['rest'] as $entry ) {
				if ( isset( $entry['object'] ) ) {
					/* translators: 1: step, 2: its operation, 3: kind and reference of its object. */
					$left[] = sprintf( __( 'step %1$d (%2$s: %3$s)', 'alphabridge-mcp' ), $entry['seq'], $entry['op'], $entry['object']['kind'] . ' ' . (string) ( $entry['object']['live_ref'] ?? $entry['object']['staging_ref'] ) );
				} elseif ( isset( $entry['takes_back'] ) ) {
					/* translators: 1: step, 2: its operation, 3: the step it takes back. */
					$left[] = sprintf( __( 'step %1$d (%2$s, takes step %3$d back)', 'alphabridge-mcp' ), $entry['seq'], $entry['op'], $entry['takes_back'] );
				} else {
					/* translators: 1: step, 2: its operation. */
					$left[] = sprintf( __( 'step %1$d (%2$s)', 'alphabridge-mcp' ), $entry['seq'], $entry['op'] );
				}
			}
			/* translators: %s: the steps not run, separated by semicolons. */
			$html .= '<p class="ab-note ab-staging-transfer__rest">' . esc_html( sprintf( __( 'Not run: %s.', 'alphabridge-mcp' ), implode( '; ', $left ) ) ) . '</p>';
		}
		if ( array() !== $run['shown']['rests'] ) {
			$rests = array();
			foreach ( $run['shown']['rests'] as $rest ) {
				$rests[] = ( is_array( $rest ) ? (string) ( $rest['kind'] ?? '' ) . ' ' . (string) ( $rest['live_ref'] ?? '' ) : (string) $rest );
			}
			/* translators: %s: the objects left as a rest, separated by commas. */
			$html .= '<p class="ab-note ab-staging-transfer__rests">' . esc_html( sprintf( __( 'Left as a rest (made by the run; remove by hand in wp-admin where it should go): %s.', 'alphabridge-mcp' ), implode( ', ', $rests ) ) ) . '</p>';
		}
		return $html;
	}

	/**
	 * The last report, with M1.
	 *
	 * @param array|null $report AB_MCP_Transfer::report_view(), or error.
	 * @param array|null $notice The notice of this view.
	 * @return string
	 */
	private static function report_html( $report, $notice ) {
		$html = '<div class="ab-staging ab-staging-transfer__report"><h3 class="ab-setting__title">' . esc_html__( 'Last report', 'alphabridge-mcp' ) . '</h3>';
		if ( null === $report ) {
			return $html . '<p class="ab-note">' . esc_html__( 'No report is kept on this site (a report holds a day at most). An assistant makes one with wp_site_diff from the packages of the staging site.', 'alphabridge-mcp' ) . '</p></div>';
		}
		if ( isset( $report['error'] ) ) {
			return $html . '<p class="ab-direct__status">' . esc_html( $report['error'] ) . '</p></div>';
		}
		if ( ! $report['done'] ) {
			/* translators: %s: date and time the report was begun. */
			return $html . '<p class="ab-direct__status">' . esc_html( sprintf( __( 'A report begun on %s is not done yet: the assistant goes on with it (wp_site_diff with its report_id).', 'alphabridge-mcp' ), self::when( $report['created'] ) ) ) . '</p></div>';
		}
		$a     = $report['actions'];
		/* translators: 1: date and time made, 2: date and time it holds until, 3: entries, 4: entries that can be taken over, 5: of them changed on the staging site, 6: of them only there, 7: entries waiting for a choice, 8: entries waiting for another object, 9: locked entries. */
		$html .= '<p class="ab-direct__status">' . esc_html( sprintf( __( 'Made on %1$s, holds until %2$s: %3$d entries; %4$d can be taken over (%5$d changed on the staging site, %6$d only there), %7$d wait for a choice, %8$d wait for another object, %9$d are locked.', 'alphabridge-mcp' ), self::when( $report['created'] ), self::when( $report['expires'] ), $report['total'], $report['takeable'], (int) ( $a['take_over'] ?? 0 ), (int) ( $a['create'] ?? 0 ), (int) ( $a['choose'] ?? 0 ), (int) ( $a['waits'] ?? 0 ), (int) ( $a['locked'] ?? 0 ) ) ) . '</p>';
		if ( null !== $report['m1'] && ! ( is_array( $notice ) && 'm1' === $notice['moment'] ) ) {
			$html .= '<p class="ab-note ab-staging-transfer__m1">' . esc_html( $report['m1'] ) . '</p>';
		}
		return $html . '</div>';
	}

	/**
	 * The runs: those to act on whatever page of the list it is on (the run
	 * that is not closed, a run that wrote with a way back still open and
	 * its backups kept; AB_MCP_Transfer::pinned()), then a page of the list,
	 * the newest first, with the way to the next page. Each with its state,
	 * steps, backups, M2, the steps link and «Abort» or «Go on».
	 *
	 * @param array[]  $runs   AB_MCP_Transfer::run_row() each, a page of the list.
	 * @param array[]  $pinned The runs to act on that are not on the page.
	 * @param int|null $next   The run_id the next page goes on after; null for none.
	 * @param int      $more   Kept runs beyond the PINNED looked at.
	 * @return string
	 */
	private static function runs_html( array $runs, array $pinned = array(), $next = null, $more = 0 ) {
		$html = '<div class="ab-staging ab-staging-transfer__runs"><h3 class="ab-setting__title">' . esc_html__( 'Runs', 'alphabridge-mcp' ) . '</h3>';
		if ( array() === $runs && array() === $pinned ) {
			return $html . '<p class="ab-note">' . esc_html__( 'No run yet. A run takes over what a person approved from a report, one step per call of the assistant (wp_site_push).', 'alphabridge-mcp' ) . '</p></div>';
		}
		if ( array() !== $pinned ) {
			$html .= '<p class="ab-note">' . esc_html__( 'To act on: a run that is not closed, or a takeover whose pairs can still be taken back.', 'alphabridge-mcp' ) . '</p>';
			$html .= self::runs_table( $pinned, 'ab-staging-transfer__pinned' );
			if ( $more > 0 ) {
				/* translators: 1: runs listed at most, 2: further runs not looked at. */
				$html .= '<p class="ab-note ab-staging-transfer__more">' . esc_html( sprintf( __( 'At most %1$d runs to act on are listed here; %2$d further runs whose backups are kept were not looked at. They are in the list below (Older runs), and wp_staging_status names the run that is not closed.', 'alphabridge-mcp' ), AB_MCP_Transfer::PINNED, (int) $more ) ) . '</p>';
			}
		}
		if ( array() !== $runs ) {
			$html .= self::runs_table( $runs, 'ab-staging-transfer__list' );
		}
		if ( null !== $next ) {
			$html .= '<p><a href="' . esc_url( self::url( array( 'runs_after' => (int) $next ), AB_MCP_Transfer::ANCHOR ) ) . '">' . esc_html__( 'Older runs', 'alphabridge-mcp' ) . '</a></p>';
		}
		return $html . '<p class="ab-note">' . esc_html__( 'Aborting closes an open run: what its steps wrote stays, and its pairs can be taken back one by one. The backups of the last three completed takeovers are kept without an age; others for the period their close got.', 'alphabridge-mcp' ) . '</p></div>';
	}

	/**
	 * A table of runs.
	 *
	 * @param array[] $runs  AB_MCP_Transfer::run_row() each.
	 * @param string  $class The class of the table.
	 * @return string
	 */
	private static function runs_table( array $runs, $class ) {
		$html = '<div class="ab-table-wrap"><table class="ab-table ' . esc_attr( $class ) . '"><thead><tr><th scope="col">' . esc_html__( 'Run', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'State', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'Steps', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'Backups', 'alphabridge-mcp' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'alphabridge-mcp' ) . '</span></th></tr></thead><tbody>';
		foreach ( $runs as $run ) {
			$id    = (int) $run['run_id'];
			$what  = 'revert' === $run['type']
				/* translators: 1: run number, 2: date and time it was made. */
				? sprintf( __( '%1$d, way back, %2$s', 'alphabridge-mcp' ), $id, self::when( $run['created'] ) )
				/* translators: 1: run number, 2: date and time it was made. */
				: sprintf( __( '%1$d, takeover, %2$s', 'alphabridge-mcp' ), $id, self::when( $run['created'] ) );
			/* translators: 1: steps done, 2: steps of the run. */
			$steps = sprintf( __( '%1$d of %2$d done', 'alphabridge-mcp' ), (int) $run['counts']['done'], (int) $run['steps'] );
			$html .= '<tr><td>' . esc_html( $what ) . '</td><td>' . esc_html( self::run_state( $run['state'] ) ) . ( null !== $run['m2'] ? '<br><span class="ab-note ab-staging-transfer__m2">' . esc_html( $run['m2'] ) . '</span>' : '' ) . '</td><td>' . esc_html( $steps ) . '</td><td>' . esc_html( self::backups_text( $run['backups'] ) ) . '</td><td>';
			$html .= '<a href="' . esc_url( self::url( array( 'run' => $id ), AB_MCP_Transfer::ANCHOR ) ) . '">' . esc_html__( 'Steps', 'alphabridge-mcp' ) . '</a>';
			if ( $run['abort'] ) {
				$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'ab_mcp_transfer_abort_' . $id, '_wpnonce', true, false );
				$html .= '<input type="hidden" name="action" value="ab_mcp_transfer_abort"><input type="hidden" name="run_id" value="' . esc_attr( (string) $id ) . '">';
				$html .= '<button class="ab-btn ab-btn--ghost">' . esc_html__( 'Abort', 'alphabridge-mcp' ) . '</button></form>';
			}
			if ( $run['go_on'] ) {
				$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'ab_mcp_transfer_go_on_' . $id, '_wpnonce', true, false );
				$html .= '<input type="hidden" name="action" value="ab_mcp_transfer_go_on"><input type="hidden" name="run_id" value="' . esc_attr( (string) $id ) . '">';
				$html .= '<button class="ab-btn ab-btn--ghost">' . esc_html__( 'Go on with the way back', 'alphabridge-mcp' ) . '</button></form>';
			}
			$html .= '</td></tr>';
		}
		return $html . '</tbody></table></div>';
	}

	/**
	 * How long the backups of a run are kept, in words.
	 *
	 * @param array $backups AB_MCP_Transfer::backups().
	 * @return string
	 */
	public static function backups_text( array $backups ) {
		switch ( $backups['state'] ) {
			case 'open':
				return __( 'kept while the run is not closed', 'alphabridge-mcp' );
			case 'last_three':
				return __( 'kept: one of the last three completed takeovers', 'alphabridge-mcp' );
			case 'until':
				/* translators: %s: date and time. */
				return sprintf( __( 'kept until %s', 'alphabridge-mcp' ), self::when( $backups['until'] ) );
			case 'kept':
				return __( 'kept (closed before periods were stored)', 'alphabridge-mcp' );
			case 'way_back':
				return __( 'kept for a way back that is not closed', 'alphabridge-mcp' );
			case 'gone':
				return __( 'no longer kept', 'alphabridge-mcp' );
		}
		return __( 'kept (the runs could not be read)', 'alphabridge-mcp' );
	}

	/**
	 * The pairs of the source, a page at a time, with «Discard».
	 *
	 * @param array|null $pairs AB_MCP_Transfer::view()['pairs'].
	 * @return string
	 */
	private static function pairs_html( $pairs ) {
		if ( ! is_array( $pairs ) ) {
			return '';
		}
		$html = '<div class="ab-staging ab-staging-transfer__pairs"><h3 class="ab-setting__title">' . esc_html__( 'Object pairs', 'alphabridge-mcp' ) . '</h3>';
		if ( array() === $pairs['pairs'] ) {
			return $html . '<p class="ab-note">' . ( $pairs['after'] > 0 ? esc_html__( 'No pair of the staging site whose key is confirmed here after these.', 'alphabridge-mcp' ) : esc_html__( 'No pair of the staging site whose key is confirmed here. An assistant pairs objects with wp_site_pairs, or a run pairs what it takes over.', 'alphabridge-mcp' ) ) . '</p></div>';
		}
		$html .= '<p class="ab-note">' . esc_html__( 'Discarding a pair changes no content: its objects stay as they are on both sites, each without a pair from then on.', 'alphabridge-mcp' ) . '</p>';
		$html .= '<div class="ab-table-wrap"><table class="ab-table ab-staging-transfer__pair-list"><thead><tr><th scope="col">' . esc_html__( 'Pair', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'Kind', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'Here', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'On the staging site', 'alphabridge-mcp' ) . '</th><th scope="col">' . esc_html__( 'State', 'alphabridge-mcp' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'alphabridge-mcp' ) . '</span></th></tr></thead><tbody>';
		foreach ( $pairs['pairs'] as $pair ) {
			/* translators: 1: state of the pair (as the tools name it), 2: the run that last touched it. */
			$html .= '<tr><td>' . esc_html( (string) $pair['id'] ) . '</td><td>' . esc_html( $pair['kind'] ) . '</td><td>' . esc_html( $pair['live_ref'] ) . '</td><td>' . esc_html( $pair['staging_ref'] ) . '</td><td>' . esc_html( null !== $pair['last_run'] ? sprintf( __( '%1$s, run %2$d', 'alphabridge-mcp' ), $pair['state'], $pair['last_run'] ) : $pair['state'] ) . '</td><td>';
			if ( null !== $pair['discard'] ) {
				$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'ab_mcp_transfer_discard_' . (int) $pair['id'], '_wpnonce', true, false );
				$html .= '<input type="hidden" name="action" value="ab_mcp_transfer_discard"><input type="hidden" name="pair_id" value="' . esc_attr( (string) (int) $pair['id'] ) . '"><input type="hidden" name="state" value="' . esc_attr( $pair['discard'] ) . '">';
				$html .= '<button class="ab-btn ab-btn--ghost">' . esc_html__( 'Discard', 'alphabridge-mcp' ) . '</button></form>';
			}
			$html .= '</td></tr>';
		}
		$html .= '</tbody></table></div>';
		if ( null !== $pairs['next'] ) {
			$html .= '<p><a href="' . esc_url( self::url( array( 'pairs_after' => (int) $pairs['next'] ), AB_MCP_Transfer::ANCHOR ) ) . '">' . esc_html__( 'Next pairs', 'alphabridge-mcp' ) . '</a></p>';
		}
		return $html . '</div>';
	}

	/**
	 * The switch «Hide edition notes» (one for all, per site), where an
	 * edition note can show at all.
	 *
	 * @param array $notes possible, hidden.
	 * @return string
	 */
	private static function notes_html( array $notes ) {
		if ( ! $notes['possible'] ) {
			return '';
		}
		$html  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ab-staging-transfer__notes">' . wp_nonce_field( 'ab_mcp_transfer_notes', '_wpnonce', true, false );
		$html .= '<input type="hidden" name="action" value="ab_mcp_transfer_notes"><input type="hidden" name="hidden" value="' . ( $notes['hidden'] ? '0' : '1' ) . '">';
		return $html . '<button class="ab-btn ab-btn--ghost">' . ( $notes['hidden'] ? esc_html__( 'Show edition notes', 'alphabridge-mcp' ) : esc_html__( 'Hide edition notes', 'alphabridge-mcp' ) ) . '</button></form>';
	}

	/* ---------------------------------------------------------- helpers */

	/**
	 * The page «Staging» with arguments, at an anchor.
	 *
	 * @param array  $args   Arguments of the address.
	 * @param string $anchor Anchor.
	 * @return string
	 */
	private static function url( array $args, $anchor ) {
		$base = AB_MCP_Guidance::settings_url( '', 'staging' );
		foreach ( $args as $key => $value ) {
			$base .= '&' . rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}
		return $base . '#' . $anchor;
	}

	/**
	 * @param int $at Seconds.
	 * @return string The date and time of the site.
	 */
	private static function when( $at ) {
		return wp_date( 'Y-m-d H:i', (int) $at );
	}
}
