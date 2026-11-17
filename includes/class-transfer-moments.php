<?php
/**
 * The moments of the takeover on the page «Staging» (Z8, branch 12; the
 * plan of the incentives of 03.11.2026, sections 4 and 5, decisions O2, V1,
 * V18, O14, V4 and V19 of Thomas of 03.11.2026): M1, a large report; M2, a
 * run closed as qualified; M3, the third pair of one run taken back.
 *
 * Each text has two parts. The OPERATING PART (what is, in this site's own
 * numbers) stands in every edition and also with the edition notes hidden:
 * M1 «37 changes ready.», M2 «Run 9: 37 steps, 41 calls since the run was
 * made, duration 23 minutes.», M3 «You are taking run 9 back pair by
 * pair.». The EDITION PART (what Free does and why, what Pro does with its
 * limits, the link) stands only
 *
 * - in the edition that does not have the feature (not where the site
 *   edition, ab_mcp_site_edition, is pro, agency or trial, a trial of Pro);
 * - while the edition notes are not hidden (one switch for all, per site);
 * - once this site saw its first report of differences;
 * - once the feature it names is published (PUBLISHED; none is yet, so no
 *   edition part shows in this version: the takeover in bundled steps of
 *   M1 and the way back of a run as a whole of M3 come with a later
 *   release of Pro);
 * - once per site and moment, as a notice: after that, the operating part
 *   as a quiet line;
 * - one notice at a time, by the priority of the plan (M1 before M3; M6,
 *   M5, M4 and M7 come with later stages).
 *
 * Only on the page «Staging», never as a notice of the dashboard; no click
 * is counted. The edition part names a feature of Pro as a line of text
 * with a link, never as a grey switch (V19). The text of M1 has no sentence
 * on stopping a run when the automatic restore point fails (V4: the
 * takeover goes on without a question). M2 has no edition part at all (no
 * estimate for Pro before a model per run is measured).
 *
 * M4 (a run whose backups were pushed out of the store, a grey row without
 * a lock, and its notice) and M8 (the quiet counters at the top of the
 * page) come with Z9a; the retention they show is there since branch 8
 * (AB_MCP_Sync_Schema::backup_rows()).
 *
 * The texts are in gettext calls with the text domain alphabridge-mcp and
 * translated in the nine bundled languages (branch 13, text/z8); the
 * German ones follow the templates of the plan of the incentives.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AB_MCP_Transfer_Moments
 */
final class AB_MCP_Transfer_Moments {

	/** The option of the moments of this site: shown (moment => time), hidden (bool), first_report (time). */
	const OPTION = 'ab_mcp_transfer_moments';

	/** Object pairs a report can take over from which it is a large one (M1; O14). */
	const THRESHOLD = 10;

	/** Pairs of one run taken back from which the way back pair by pair is a moment (M3). */
	const PAIRS_BACK = 3;

	/** The moments that are notices, by their priority (the plan: M6 > M1 > M5 > M3 > M4 > M7). */
	const PRIORITY = array( 'm1', 'm3' );

	/** The feature of Pro each notice names (its edition part). */
	const FEATURES = array(
		'm1' => 'site_sync',
		'm3' => 'run_revert',
	);

	/**
	 * The features of Pro that are published, by the names of FEATURES. None
	 * in this version: the edition part of a moment shows only once the
	 * feature it names is out (plan of the incentives, section 4).
	 */
	const PUBLISHED = array();

	/** The checkout of Pro (as the box on «AI Connection» links it). */
	const CHECKOUT = 'https://checkout.freemius.com/plugin/35076/plan/57642/';

	/** @var string[]|null The published features; PUBLISHED when null. Set by tests only. */
	public static $published = null;

	/* ------------------------------------------------- operating parts */

	/**
	 * The operating part of M1: the object pairs a report can take over
	 * (take_over and create), from THRESHOLD on; null below.
	 *
	 * @param int $takeable Object pairs.
	 * @return string|null
	 */
	public static function m1( $takeable ) {
		/* translators: %d: object pairs the report can take over (10 or more). */
		return (int) $takeable >= self::THRESHOLD ? sprintf( _n( '%d change ready.', '%d changes ready.', (int) $takeable, 'alphabridge-mcp' ), (int) $takeable ) : null;
	}

	/**
	 * The operating part of M2, for a run closed as qualified
	 * (forward_closed_at); null for any other run. Steps: the steps of the
	 * run; calls: those since the run was made (AB_MCP_Sync_Run::touch(),
	 * its making counted); the duration closed − created, with every
	 * interruption in it.
	 *
	 * @param array $run   The run (AB_MCP_Sync_Run::get()).
	 * @param int   $steps Its steps.
	 * @return string|null
	 */
	public static function m2( array $run, $steps ) {
		if ( (int) ( $run['forward_closed_at'] ?? 0 ) <= 0 || (int) ( $run['closed'] ?? 0 ) <= 0 ) {
			return null;
		}
		/* translators: 1: run number, 2: its steps, 3: calls since the run was made, 4: duration in words (duration()). */
		return sprintf( __( 'Run %1$d: %2$d steps, %3$d calls since the run was made, duration %4$s.', 'alphabridge-mcp' ), (int) $run['run_id'], (int) $steps, (int) $run['calls'], self::duration( (int) $run['closed'] - (int) $run['created'] ) );
	}

	/**
	 * The operating part of M3: from the PAIRS_BACK-th pair of the run taken
	 * back; null before.
	 *
	 * @param int $run_id Run.
	 * @param int $back   Pairs of it taken back.
	 * @return string|null
	 */
	public static function m3( $run_id, $back ) {
		/* translators: %d: run number. */
		return (int) $back >= self::PAIRS_BACK ? sprintf( __( 'You are taking run %d back pair by pair.', 'alphabridge-mcp' ), (int) $run_id ) : null;
	}

	/**
	 * A duration in words: seconds under a minute, minutes under an hour,
	 * else hours and minutes.
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function duration( $seconds ) {
		$seconds = max( 0, (int) $seconds );
		if ( $seconds < 60 ) {
			/* translators: %d: seconds. */
			return sprintf( _n( '%d second', '%d seconds', $seconds, 'alphabridge-mcp' ), $seconds );
		}
		$minutes = intdiv( $seconds, 60 );
		if ( $minutes < 60 ) {
			/* translators: %d: minutes. */
			return sprintf( _n( '%d minute', '%d minutes', $minutes, 'alphabridge-mcp' ), $minutes );
		}
		/* translators: 1: hours, 2: minutes (a duration, as in «3 h 10 min»). */
		return sprintf( __( '%1$d h %2$d min', 'alphabridge-mcp' ), intdiv( $minutes, 60 ), $minutes % 60 );
	}

	/* --------------------------------------------------- edition parts */

	/**
	 * The edition part of a notice: its sentences and its links (text and
	 * address; a link, no switch).
	 *
	 * @param string $moment m1 or m3.
	 * @param int    $count  M1: the object pairs.
	 * @return array{text:string,links:array<int,array{label:string,url:string}>}
	 */
	public static function edition( $moment, $count = 0 ) {
		$monthly = array(
			'label' => __( 'Pro monthly, 9 USD', 'alphabridge-mcp' ),
			'url'   => self::CHECKOUT . '?billing_cycle=monthly',
		);
		if ( 'm1' === $moment ) {
			return array(
				/* translators: %d: object pairs the report can take over. */
				'text'  => sprintf( __( 'Free carries the takeover over step by step, each step a call of your AI of its own, with the lock and a check per step. Pro bundles many steps per call, holds the lock over the whole run and makes a restore point automatically before a bulk takeover. In both editions the lock does not cover every way of writing; a change made past it meanwhile can be lost. You can take all %d over one by one now.', 'alphabridge-mcp' ), (int) $count ),
				'links' => array(
					$monthly,
					array(
						'label' => __( 'Try it for 7 days', 'alphabridge-mcp' ),
						'url'   => self::CHECKOUT . '?trial=free&billing_cycle=monthly',
					),
				),
			);
		}
		return array(
			'text'  => __( 'Free works this way so that every object pair is checked. Pro takes the supported changes of a run back together. What was changed here since and what cannot be taken back stays as a finding in both editions.', 'alphabridge-mcp' ),
			'links' => array( $monthly ),
		);
	}

	/* -------------------------------------------------------- the rules */

	/**
	 * Which of the moments due on this view is shown as a notice with its
	 * edition part, by PRIORITY: the first whose edition part may show
	 * (may_show()); it is noted as shown for this site. The others show
	 * their operating part only. Null where none.
	 *
	 * @param string[] $due The moments due on this view.
	 * @param int|null $now Time; now when null.
	 * @return string|null
	 */
	public static function notice( array $due, $now = null ) {
		foreach ( self::PRIORITY as $moment ) {
			if ( in_array( $moment, $due, true ) && self::may_show( $moment ) ) {
				$state                     = self::state();
				$state['shown'][ $moment ] = null === $now ? time() : (int) $now;
				self::save( $state );
				return $moment;
			}
		}
		return null;
	}

	/**
	 * Whether the edition part of a moment may show now (the head of this
	 * class): not in an edition that has the feature, not with the notes
	 * hidden, not before the first report of differences, not before its
	 * feature is published, not a second time on this site.
	 *
	 * @param string $moment m1 or m3.
	 * @return bool
	 */
	public static function may_show( $moment ) {
		if ( ! isset( self::FEATURES[ $moment ] ) || ! self::possible() ) {
			return false;
		}
		$state = self::state();
		return ! $state['hidden']
			&& $state['first_report'] > 0
			&& in_array( self::FEATURES[ $moment ], self::published(), true )
			&& ! isset( $state['shown'][ $moment ] );
	}

	/**
	 * Whether any edition part can show on this site at all: an edition
	 * without the features (the free one, also for a value that is not a
	 * string; not pro, agency or a trial of Pro), and a feature published.
	 * The switch «Hide edition notes» is offered only then.
	 *
	 * @return bool
	 */
	public static function possible() {
		$edition = apply_filters( 'ab_mcp_site_edition', 'free' );
		return ! ( is_string( $edition ) && in_array( $edition, array( 'pro', 'agency', 'trial' ), true ) ) && array() !== array_intersect( self::FEATURES, self::published() );
	}

	/**
	 * Whether the edition notes are hidden on this site.
	 *
	 * @return bool
	 */
	public static function hidden() {
		return self::state()['hidden'];
	}

	/**
	 * Hide or show the edition notes (the switch on the page «Staging»).
	 *
	 * @param bool $hidden Hidden.
	 * @return void
	 */
	public static function set_hidden( $hidden ) {
		$state           = self::state();
		$state['hidden'] = (bool) $hidden;
		self::save( $state );
	}

	/**
	 * Note that this site has seen a report of differences (its first one,
	 * once): from then on an edition part may show.
	 *
	 * @param int $at When the report was made.
	 * @return void
	 */
	public static function report_seen( $at ) {
		$state = self::state();
		if ( $state['first_report'] > 0 ) {
			return;
		}
		$state['first_report'] = max( 1, (int) $at );
		self::save( $state );
	}

	/**
	 * @return string[] The published features.
	 */
	private static function published() {
		return null !== self::$published ? (array) self::$published : self::PUBLISHED;
	}

	/**
	 * The option in a known shape.
	 *
	 * @return array{shown:array<string,int>,hidden:bool,first_report:int}
	 */
	public static function state() {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'shown'        => isset( $raw['shown'] ) && is_array( $raw['shown'] ) ? array_map( 'intval', $raw['shown'] ) : array(),
			'hidden'       => ! empty( $raw['hidden'] ),
			'first_report' => isset( $raw['first_report'] ) ? (int) $raw['first_report'] : 0,
		);
	}

	/**
	 * @param array $state state().
	 * @return void
	 */
	private static function save( array $state ) {
		update_option( self::OPTION, $state, false );
	}
}
