<?php
/**
 * Plugin and theme updates that do not take the site down: the one place
 * wp_update_plugin and wp_update_theme hand an update to.
 *
 * WordPress's own update path for a request like this one switches an active
 * plugin off before it replaces it (Plugin_Upgrader::upgrade() outside cron,
 * class-plugin-upgrader.php, deactivate_plugin_before_upgrade()), and a theme
 * update with a fatal error ends every request of the site, this MCP
 * endpoint's too (both measured on Playground, 02.10.2026). The pieces for a
 * safe update are all in core: bulk_upgrade(), the path of the update button
 * in wp-admin, leaves the plugin active and moves the previous version to
 * wp-content/upgrade-temp-backup/ before it copies the new one (deleted at
 * shutdown); the test requests of AB_MCP_Php_Probe find a fatal error;
 * and core's own background updates show the order (maintenance mode, update
 * with clear_update_cache false, maintenance mode again, 2 s, test, restore;
 * class-wp-automatic-updater.php, update()).
 *
 * The rule is the one of the file tools: after the update every target must
 * answer at least as well as before it, or the previous version comes back
 * in the same request (verdict()). One update at a time per site, under the
 * lock core takes for its background updates (auto_updater), with a note of
 * our own beside it so a refusal can say who holds it. The maintenance mode
 * is held from the update to the end of the test or the restore, so visitors
 * see WordPress's 503 page instead of a fatal error; the test requests pass
 * it with the key core derives from .maintenance, read again right before
 * every round, because every maintenance_mode( true ) writes a new time.
 *
 * Not covered, and every answer says so: wp-admin screens, cron, AJAX, code
 * for logged-in users or single pages (AB_MCP_Php_Probe::NOT_CHECKED),
 * and the database: a new version that changes it when it loads keeps that
 * change after a restore, as with core's own rollback.
 *
 * AB_MCP_Activation_Guard (class-activation-guard.php) extends this class
 * for the same test requests, time budget and verdict() when a plugin or
 * theme is switched on.
 *
 * @package AlphaBridge_MCP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-php-probe.php';
require_once __DIR__ . '/class-own-files.php';
require_once __DIR__ . '/class-update-log.php';
require_once __DIR__ . '/class-change-lock.php';

/**
 * Class AB_MCP_Update_Guard
 */
class AB_MCP_Update_Guard {

	/**
	 * Seconds one call may take, counted from the start of the request. nginx
	 * gives PHP 60 s by default (fastcgi_read_timeout) and the MCP SDK for
	 * TypeScript waits 60 s for an answer; this keeps a margin below that.
	 * A start value (decision 11 of the plan), to be measured on real hosts.
	 */
	const BUDGET = 45;

	/**
	 * Seconds kept free for the download, unpacking and copying of the
	 * package when deciding whether an update may start. A start value: how
	 * long that takes on real hosts and with large packages is not measured.
	 */
	const BUFFER = 5;

	/**
	 * Seconds between the update (or the restore) and the test requests,
	 * as core waits before its own test (class-wp-automatic-updater.php:
	 * "a slight delay is required for the loopback to use the updated plugin
	 * code").
	 */
	const WAIT = 2;

	/**
	 * Seconds between switching the maintenance mode on and moving the first
	 * file: a page that was already being built when the mode began finishes
	 * with the files it started with (measured 03.10.2026 on Playground: a
	 * visit under way at that moment answered 500 during a theme update).
	 */
	const SETTLE = 1;

	/** Most seconds a target that timed out is given when asked again (core waits 50). */
	const REASK_MAX = 50;

	/**
	 * Seconds after which a note of our own on the lock belongs to a request
	 * that died: the maintenance mode it held is over by then too (WordPress
	 * ignores a .maintenance older than 10 minutes).
	 */
	const STALE = 600;

	/**
	 * Seconds our note holds the locks while WordPress's upgrader runs: one
	 * of its steps reports nothing while it runs (unpacking, copying,
	 * removing the old version), and over a slow filesystem it can take
	 * minutes; beat() runs only between them (Codex, 06.10.2026). A request
	 * that dies meanwhile blocks other changes this long at most; core's own
	 * update lock waits an hour.
	 */
	const LEASE = 1800;

	/** The lock core takes for its background updates. */
	const LOCK = 'auto_updater';

	/**
	 * Appended to the descriptions of wp_update_plugin and wp_update_theme.
	 * One text, so the two tools cannot describe the same steps differently.
	 */
	const DESCRIPTION = ' It works like WordPress\'s update button: an active plugin stays active, a theme stays in use, and the previous version is moved aside first. Where the code runs on this site (an active plugin, the active theme or its parent), visitors see the maintenance page (HTTP 503) until the check ends (before WordPress 6.6 only while files are copied), and test requests check the home page, this MCP endpoint, wp-admin/admin-post.php and the dashboard before and after, logged in as this connection\'s account. A new fatal error, a target that stops answering or an MCP endpoint that loses its own answer puts the previous version back in the same call, and the answer, an error, says what failed where. After a new fatal error or a lost MCP answer, AlphaBridge also asks WordPress\'s automatic updates to leave that version alone, and the answer says whether and for how long that holds; a new call of this tool tries it again. A fatal error that was there before, or a redirect or wp_die() at the home page or admin-post.php, keeps the update with a warning. Updated without a test, with the warning "not checked": an inactive plugin or theme, a site that cannot reach itself, an update that leaves no time for the test (a large package on a slow host). Not checked: other wp-admin screens, cron, AJAX, code that runs only on some pages or for other users. Only files are put back; what the new version changes in the database stays. No undo point: the previous version comes back only within this call. One update per site (per network on multisite) runs at a time, and none during an activation or a deploy into running code; a second call is refused and changes nothing. A call that timed out can still finish: about a minute later nothing is offered any more if the update was kept, and a new call names its result. AlphaBridge itself is not updated through this tool.';

	/**
	 * The update this request is running, for the shutdown function: our
	 * note's nonce, the $upgrading of the .maintenance we wrote last, the
	 * upgrader.
	 *
	 * @var array|null
	 */
	protected static $live = null;

	/** @var bool */
	private static $shutdown_registered = false;

	/* --------------------------------------------------------------- the update */

	/**
	 * Update one plugin or theme with test requests before and after, and put
	 * the previous version back where the new one does worse.
	 *
	 * @param array $job {
	 *     @type string $type    plugin|theme.
	 *     @type string $item    Plugin file (akismet/akismet.php) or stylesheet.
	 *     @type string $slug    Its folder.
	 *     @type string $root    The folder it lies in (WP_PLUGIN_DIR, the theme root).
	 *     @type string $dir     plugins|themes (the folder in upgrade-temp-backup).
	 *     @type string $tool    Tool name, for the log.
	 *     @type bool   $active  Whether its code runs on this site.
	 *     @type string $role    Themes: active|parent|inactive.
	 *     @type array  $offer   { version, package, wporg, slug }.
	 * }
	 * @return array|WP_Error
	 */
	public static function update( array $job ) {
		$start = static::request_start();
		$lock  = self::take_lock( $job );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		// Not while another change of running code runs (an activation, a
		// deploy, a PHP file written or undone): its test requests would see
		// this update, and its way back could undo it (AB_MCP_Change_Lock).
		$change = AB_MCP_Change_Lock::take_for_update();
		if ( is_wp_error( $change ) ) {
			self::free_lock( $lock );
			return $change;
		}
		try {
			// Whether its code runs here, read under the lock: the caller
			// read it before, and an activation or a theme switch through
			// AlphaBridge may have come in between; the lock forgot the
			// cached state when it was taken (Codex, 06.10.2026: an update
			// then went without a test). Where it cannot be read (a filter
			// throws), it counts as running: tested rather than not.
			try {
				$job = static::role_now( $job );
			} catch ( \Throwable $e ) {
				$job['active'] = true;
			}
			$held = AB_MCP_Update_Log::held( $job['type'], $job['item'] );

			// Should the client hang up, PHP may end this request at its next
			// output; the decision about the files has to come first.
			ignore_user_abort( true );
			$limit = (int) ini_get( 'max_execution_time' );
			if ( $limit > 0 && function_exists( 'set_time_limit' ) ) {
				@set_time_limit( max( $limit, 300 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			self::$live = array(
				'nonce'          => $lock,
				'since'          => time(),
				'beat'           => time(),
				'upgrading'      => null,
				'upgrader'       => null,
				'job'            => $job,
				'version_before' => self::version_of( $job ),
				'fp_before'      => self::fingerprint( $job, self::folder( $job ) ),
			);
			static::hook_shutdown();
		} catch ( \Throwable $e ) {
			// Nothing has started: both locks and our note go before the
			// error does (Codex, 06.10.2026: they stayed behind).
			self::$live = null;
			AB_MCP_Change_Lock::release();
			self::free_lock( $lock );
			throw $e;
		}

		$step    = 'start';
		$r       = null;
		$settled = array(
			'hold'    => false,
			'drop'    => array(),
			'auto'    => '',
			'auto_on' => false,
		);
		try {
			try {
				$r = static::run_locked( $job, $start, $step );
			} catch ( \Throwable $e ) {
				$r                   = self::record_of( $job, 'failed_inside' );
				$r['version_before'] = (string) self::$live['version_before'];
				$r['step']           = $step;
				$r['error']         = get_class( $e ) . ': ' . substr( $e->getMessage(), 0, 300 );
				$r['ran']           = null !== self::$live['upgrader'];
				$r['rescued']       = self::rescue( $job );
				$r['version_after'] = self::version_of( $job );
				self::maintenance_end();
			}
			// Still under the lock: the log is written by one update at a time.
			if ( 'budget' !== $r['status'] ) {
				$settled = self::settle( $job, $r );
			}
		} finally {
			self::finish();
		}
		return self::answer( $job, $r, $held, $settled );
	}

	/**
	 * finally does not run after a fatal error of PHP itself (time limit,
	 * memory); the shutdown action does, as the fatal error handler of core
	 * stays out while the maintenance mode is on. Priority 1: before core
	 * deletes the copy of the previous version at 100, which may by then be
	 * the only one. The shutdown function covers a request without the
	 * shutdown action; on_shutdown() does nothing a second time.
	 */
	protected static function hook_shutdown() {
		if ( false === has_action( 'shutdown', array( __CLASS__, 'on_shutdown' ) ) ) {
			add_action( 'shutdown', array( __CLASS__, 'on_shutdown' ), 1, 0 );
		}
		if ( ! self::$shutdown_registered ) {
			register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
			self::$shutdown_registered = true;
		}
	}

	/**
	 * After the update: the log entry, the hold of a version taken back for
	 * a fatal error or a cut connection, and for a kept update the file undo
	 * points of the previous version, which would write old files into the
	 * new one.
	 *
	 * Where the hold cannot reach WordPress's automatic updates (a network
	 * whose main site runs without this plugin), the package comes off WordPress's
	 * list of automatic updates instead, and a kept update puts it back
	 * (AB_MCP_Update_Log). A filter of another plugin that switches every
	 * automatic update on is not countered there, nor an offer with the mark
	 * autoupdate (WP_Automatic_Updater::should_update() reads the list only
	 * without it); rolled_back() says so.
	 *
	 * @param array $job See update().
	 * @param array $r   Record of run_locked().
	 * @return array { hold: bool, drop: string[], auto: ''|switched_off|was_off|failed, auto_on: bool }
	 */
	private static function settle( array $job, array $r ) {
		$to      = (string) ( isset( $job['offer']['version'] ) ? $job['offer']['version'] : '' );
		$row     = self::log_row( $job, $r, $to );
		$hold    = false;
		$drop    = array();
		$auto    = '';
		$auto_on = false;
		if ( 'kept' === $r['status'] ) {
			$auto_on = AB_MCP_Update_Log::release( $job['type'], $job['item'] );
			if ( class_exists( 'AB_MCP_Undo' ) && method_exists( 'AB_MCP_Undo', 'discard_files_of' ) ) {
				$drop = AB_MCP_Undo::discard_files_of( 'theme' === $job['type'] ? 'theme' : 'plugin', $job['slug'] );
			}
		} elseif ( 'rolled_back' === $r['status'] && ! empty( $r['verdict']['hold'] ) ) {
			$hold = true;
			if ( ! self::hold_reaches_auto_updates() ) {
				$auto = AB_MCP_Update_Log::auto_update_off( $job['type'], $job['item'] );
			}
			AB_MCP_Update_Log::hold( $job['type'], $job['item'], self::held_version( $job, $r ), (string) $r['verdict']['why'], (string) $r['version_after'], 'switched_off' === $auto );
			// Off the list since an earlier roll-back through AlphaBridge:
			// still AlphaBridge's doing, and it still goes back on.
			$now = AB_MCP_Update_Log::held( $job['type'], $job['item'] );
			if ( 'was_off' === $auto && ! empty( $now['auto_off'] ) ) {
				$auto = 'switched_off';
			}
		}
		// New files on disk: a file undo point recorded before this update,
		// on any site of the network, would write a file of the previous
		// version into them (the per-site drop above reaches only this one).
		if ( in_array( $r['status'], array( 'kept', 'rollback_failed' ), true ) || in_array( $r['rescued'], array( 'copy_kept', 'incomplete' ), true ) ) {
			AB_MCP_Update_Log::mark_replaced( $job['type'], $job['slug'], null !== self::$live ? (int) self::$live['since'] : time() );
		}
		$row['hold'] = $hold;
		AB_MCP_Update_Log::add( $row );
		self::settle_backup( $job, $r );
		return array(
			'hold'    => $hold,
			'drop'    => $drop,
			'auto'    => $auto,
			'auto_on' => $auto_on,
		);
	}

	/**
	 * The steps under the lock. Fills and returns the record answer() reads.
	 *
	 * @param array  $job   See update().
	 * @param float  $start When the request started.
	 * @param string $step  The step running, for a failure.
	 * @return array
	 */
	protected static function run_locked( array $job, $start, &$step ) {
		$r                   = self::record_of( $job, '' );
		$r['version_before'] = self::version_of( $job );
		$ms                  = array();

		if ( ! AB_MCP_Php_Probe::enabled() ) {
			$plan = 'off';
		} elseif ( empty( $job['active'] ) ) {
			$plan = 'not_active';
		} else {
			$plan = 'run';
		}

		// Step 1: the test before, every target, to the end, so the one after
		// has something to compare with.
		$reached = array();
		if ( 'run' === $plan ) {
			$step               = 'probe_before';
			$t0                 = static::now();
			$r['before']        = AB_MCP_Php_Probe::run( array(), false );
			$ms['probe_before'] = self::ms_since( $t0 );
			$reached            = self::reached( $r['before'] );
			if ( ! $reached ) {
				// Decision 3: a site that cannot reach itself is updated
				// anyway, as the update button in wp-admin does, and the
				// answer says it was not checked.
				$plan = 'unreachable';
			}
		}
		$r['plan'] = $plan;

		// An update starts only when what follows it still fits: the
		// pause, the copy, the wait and the test, and the reserve of a
		// roll-back (putting back, the wait, one more round). The test after
		// the update ends before that reserve ($deadline), so a site that
		// hangs then cannot keep the call past its budget.
		$round    = ( 'run' === $plan ) ? self::round_seconds( $r['before'], $reached ) : 0.0;
		$reserve  = self::WAIT + $round + self::BUFFER;
		$need     = ( 'run' === $plan ) ? self::SETTLE + self::BUFFER + self::WAIT + $round + $reserve : self::WAIT + self::BUFFER;
		$deadline = $start + self::BUDGET - $reserve;
		$elapsed  = static::now() - $start;
		if ( $elapsed + $need > self::BUDGET ) {
			$r['status']  = 'budget';
			$r['elapsed'] = $elapsed;
			$r['need']    = $need;
			$r['ms']      = $ms;
			return $r;
		}

		$step     = 'update';
		self::beat();
		require_once __DIR__ . '/class-update-upgraders.php';
		$skin     = new AB_MCP_Beating_Skin();
		$upgrader = ( 'theme' === $job['type'] ) ? new AB_MCP_Held_Theme_Upgrader( $skin ) : new AB_MCP_Held_Plugin_Upgrader( $skin );
		self::$live['upgrader'] = $upgrader;
		$test     = ( 'run' === $plan );
		// Before WordPress 6.6 no request gets through the maintenance mode,
		// test requests included (wp_is_maintenance_mode() knows no scrape
		// key there): it is then left to bulk_upgrade(), which holds it while
		// it copies, and the test runs without it. has_fatal_error() came with
		// that way through, in the same release.
		$hold     = $test && method_exists( 'WP_Automatic_Updater', 'has_fatal_error' );
		if ( $hold ) {
			self::maintenance_start( $upgrader );
			// From here bulk_upgrade() may switch it on, not off.
			$upgrader->hold = true;
			static::pause( self::SETTLE );
		}
		self::quiet_upgrader_hooks();

		// Step 4: as core's background updates and the update button do.
		// clear_update_cache false keeps the list of offered updates; it is
		// renewed once at the end (Step 11).
		$t0         = static::now();
		self::lease( self::LEASE );
		$results    = $upgrader->bulk_upgrade( array( $job['item'] ), array( 'clear_update_cache' => false ) );
		self::lease( 0 );
		$ms['update'] = self::ms_since( $t0 );
		self::beat();
		$r['ran']   = true;
		// bulk_upgrade() may have written its own .maintenance (a new
		// $upgrading) and, held, did not delete it: probe() reads the key
		// from the file right before every round.
		if ( $hold ) {
			$r['maintenance'] = ( null !== self::$live['upgrading'] ) ? 'held' : 'not_possible';
		} else {
			$r['maintenance'] = $test ? 'not_supported' : 'not_needed';
		}
		$r['version_after'] = self::version_of( $job );
		$one                = ( is_array( $results ) && array_key_exists( $job['item'], $results ) ) ? $results[ $job['item'] ] : $results;

		if ( false === $results ) {
			$r['status'] = 'fs';
		} elseif ( is_wp_error( $one ) ) {
			// Where core had already moved the previous version aside, it
			// would put it back only at shutdown, after this request let
			// visitors in again: put back now, under the maintenance mode.
			$r['status']        = 'failed';
			$r['error']         = implode( ' ', $one->get_error_messages() );
			$r['rescued']       = self::rescue( $job );
			$r['version_after'] = self::version_of( $job );
		} elseif ( ! self::installed( $one, self::$live['fp_before'], self::fingerprint( $job, self::folder( $job ) ) ) ) {
			// true comes back when nothing was installed ("up_to_date"). Not
			// the version decides: a vendor's package can keep the one in its
			// header and still bring new code.
			$r['status'] = 'not_updated';
			$r['error']  = self::skin_says( $skin );
		}
		if ( '' !== $r['status'] ) {
			$r['ms'] = $ms;
			self::maintenance_end();
			return $r;
		}

		if ( ! $test ) {
			$r['status'] = 'kept';
			$r['ms']     = $ms;
			return $r;
		}

		// Step 5: OPcache. Core clears it while copying, where the host lets
		// it; whether that works here decides how long to wait and whether
		// the test can be trusted.
		$step              = 'wait';
		$r['opcache']      = AB_MCP_Own_Files::invalidate_opcache( self::main_php( $job ) );
		$wait              = self::wait_for( $r['opcache'] );
		$r['conclusive']   = self::conclusive( $r['opcache'] );
		static::pause( $wait );
		$ms['wait']        = (int) ( $wait * 1000 );

		// Step 6: the same targets that answered before; to the end where
		// there was a fatal error before, so the old error cannot hide a new
		// one at another target.
		$step              = 'probe_after';
		self::beat();
		$t0                = static::now();
		$fatal_before      = 'fatal' === $r['before']['result'];
		$r['after']        = self::probe_until( $reached, ! $fatal_before, $deadline );
		$r['after']        = self::ask_timeouts_again( $r['before'], $r['after'], $deadline );
		$ms['probe_after'] = self::ms_since( $t0 );

		// Step 7.
		$r['verdict'] = self::verdict( $r['before'], $r['after'] );
		if ( $r['verdict']['keep'] ) {
			$r['status'] = 'kept';
			$r['ms']     = $ms;
			self::maintenance_end();
			return $r;
		}

		// Step 8: the previous version back, then the same targets again.
		$step     = 'rollback';
		self::beat();
		$t0       = static::now();
		$restored = self::restore( $job );
		$ms['rollback'] = self::ms_since( $t0 );
		self::beat();
		if ( is_wp_error( $restored ) ) {
			// The only copy of the previous version: core would delete it at
			// shutdown; settle_backup() takes that off.
			$r['status']     = 'rollback_failed';
			$r['error']      = $restored->get_error_message();
			$r['incomplete'] = 'ab_mcp_update_restore_incomplete' === $restored->get_error_code();
			$r['ms']         = $ms;
			self::maintenance_end();
			return $r;
		}
		$r['version_restored'] = self::version_of( $job );
		static::pause( $wait );
		$t0                     = static::now();
		$r['after_rollback']    = self::probe_until( $reached, false, $start + self::BUDGET );
		$ms['probe_after_rollback'] = self::ms_since( $t0 );
		$r['status']            = 'rolled_back';
		$r['ms']                = $ms;
		self::maintenance_end();
		return $r;
	}

	/**
	 * Is the new version no worse than the old one at every target? A pure
	 * function of the two reports; test/update-verdict-test.php holds its
	 * table.
	 *
	 * Per target, before → after: ok or stopped → fatal, unreachable or
	 * not_probed puts it back, and so does a fatal error other than the one
	 * before; the same fatal error as before stays, with a warning. This MCP
	 * endpoint ending early where it did not before puts it back too: kept,
	 * it would cut this connection. The home page, admin-post.php or the
	 * dashboard ending early (a redirect, wp_die(), the dashboard refusing
	 * the account with a 4xx) stays with a warning, as with the file tools. A target not reached before is not compared, and neither is one
	 * this call had no time left for (out_of_time, see probe_until()): not
	 * checked is not failed. Nor is the dashboard where the report after left
	 * it out for its login (logged_in refused or no_session, see
	 * AB_MCP_Php_Probe::run()): its test request was not let in, which
	 * says nothing about the code (Codex, 06.10.2026: its missing row put the
	 * change back).
	 *
	 * @param array $before Report of AB_MCP_Php_Probe::run() before the update.
	 * @param array $after  Report after it.
	 * @return array {
	 *     @type bool   $keep    Whether the new version stays.
	 *     @type string $why     fatal | mcp_cut | unreachable | not_probed | ''.
	 *     @type bool   $hold    Whether the version is held back from automatic updates.
	 *     @type array  $blamed  After rows that put it back, each with 'before'.
	 *     @type array  $same    After rows with the fatal error that was there before.
	 *     @type array  $stopped After rows of the home page or admin-post.php that end early now.
	 *     @type array  $unknown Before rows of targets not reached before the update.
	 *     @type array  $unchecked After rows of targets this call ran out of time for.
	 *     @type array  $login   After rows of the dashboard, left out after the change for its login, each with 'reason' and 'before'.
	 * }
	 */
	public static function verdict( array $before, array $after ) {
		$now = array();
		foreach ( isset( $after['targets'] ) ? (array) $after['targets'] : array() as $row ) {
			$now[ (string) $row['target'] ] = $row;
		}
		$out = array(
			'keep'      => true,
			'why'       => '',
			'hold'      => false,
			'blamed'    => array(),
			'same'      => array(),
			'stopped'   => array(),
			'unknown'   => array(),
			'unchecked' => array(),
			'login'     => array(),
		);
		$login = self::left_out_for_login( $after );
		$rank  = array(
			'not_probed'  => 1,
			'unreachable' => 2,
			'mcp_cut'     => 3,
			'fatal'       => 4,
		);
		foreach ( isset( $before['targets'] ) ? (array) $before['targets'] : array() as $then ) {
			$name = (string) $then['target'];
			if ( 'unreachable' === $then['result'] ) {
				$out['unknown'][] = $then;
				continue;
			}
			if ( 'admin' === $name && ! isset( $now[ $name ] ) && null !== $login ) {
				$out['login'][] = array(
					'target' => $name,
					'result' => 'not_probed',
					'reason' => isset( $login['reason'] ) ? (string) $login['reason'] : '',
					'before' => $then,
				);
				continue;
			}
			$row = isset( $now[ $name ] ) ? $now[ $name ] : array(
				'target' => $name,
				'result' => 'not_probed',
			);
			if ( ! empty( $row['out_of_time'] ) ) {
				$out['unchecked'][] = $row;
				continue;
			}
			$why = '';
			switch ( $row['result'] ) {
				case 'ok':
					break;
				case 'fatal':
					if ( 'fatal' === $then['result'] && AB_MCP_Own_Files::same_failure( $row, $then ) ) {
						$out['same'][] = $row;
					} else {
						$why = 'fatal';
					}
					break;
				case 'stopped':
					if ( 'mcp' === $name ) {
						$was_so = 'fatal' === $then['result'] || ( 'stopped' === $then['result'] && AB_MCP_Own_Files::same_failure( $row, $then ) );
						if ( ! $was_so ) {
							$why = 'mcp_cut';
						}
					} elseif ( 'ok' === $then['result'] || ( 'stopped' === $then['result'] && ! AB_MCP_Own_Files::same_failure( $row, $then ) ) ) {
						$out['stopped'][] = $row;
					}
					break;
				case 'unreachable':
					$why = 'unreachable';
					break;
				default:
					$why = 'not_probed';
			}
			if ( '' === $why ) {
				continue;
			}
			$row['before']   = $then;
			$out['blamed'][] = $row;
			if ( '' === $out['why'] || $rank[ $why ] > $rank[ $out['why'] ] ) {
				$out['why'] = $why;
			}
			if ( 'fatal' === $why || 'mcp_cut' === $why ) {
				$out['hold'] = true;
			}
		}
		$out['keep'] = ! $out['blamed'];
		return $out;
	}

	/**
	 * What a report says where it left the dashboard out for its login
	 * (logged_in refused or no_session of AB_MCP_Php_Probe::run()), or
	 * null.
	 *
	 * @param array $report Report.
	 * @return array|null Its logged_in.
	 */
	private static function left_out_for_login( array $report ) {
		if ( ! isset( $report['logged_in'] ) || ! is_array( $report['logged_in'] ) ) {
			return null;
		}
		$said = isset( $report['logged_in']['result'] ) ? (string) $report['logged_in']['result'] : '';
		return in_array( $said, array( 'refused', 'no_session' ), true ) ? $report['logged_in'] : null;
	}

	/**
	 * The warning where the dashboard answered before a change and was left
	 * out after it for its login (verdict(), login), or ''.
	 *
	 * @param array $verdict verdict().
	 * @return string
	 */
	public static function login_warning( array $verdict ) {
		if ( empty( $verdict['login'] ) ) {
			return '';
		}
		return sprintf(
			/* translators: %s: why the test request of the dashboard was not let in */
			__( 'The dashboard answered before the change but was not checked after it: %s. Make sure you can still log in.', 'alphabridge-mcp' ),
			(string) $verdict['login'][0]['reason']
		);
	}

	/**
	 * The rows that say what failed: a target not asked after the first fatal
	 * error is named only when nothing else is.
	 *
	 * @param array $rows Blamed rows.
	 * @return array
	 */
	protected static function telling( array $rows ) {
		$said = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return 'not_probed' !== $row['result'];
				}
			)
		);
		return $said ? $said : $rows;
	}

	/* ------------------------------------------------------------ test requests */

	/**
	 * Targets that answered: WordPress ran there, with or without a fatal
	 * error. Only they are asked after the update.
	 *
	 * @param array $report Report.
	 * @return string[]
	 */
	public static function reached( array $report ) {
		$out = array();
		foreach ( isset( $report['targets'] ) ? (array) $report['targets'] : array() as $row ) {
			if ( in_array( $row['result'], array( 'ok', 'stopped', 'fatal' ), true ) ) {
				$out[] = (string) $row['target'];
			}
		}
		return $out;
	}

	/**
	 * Seconds one round of these targets took.
	 *
	 * @param array    $report Report.
	 * @param string[] $names  Targets.
	 * @return float
	 */
	protected static function round_seconds( array $report, array $names ) {
		$sum = 0;
		foreach ( (array) $report['targets'] as $row ) {
			if ( in_array( (string) $row['target'], $names, true ) && isset( $row['ms'] ) ) {
				$sum += (int) $row['ms'];
			}
		}
		return $sum / 1000;
	}

	/**
	 * One round of test requests, with the key of the maintenance mode we
	 * hold, read again from .maintenance right now.
	 *
	 * @param string[]   $only    Targets.
	 * @param bool       $stop    Stop at the first fatal error.
	 * @param float|null $timeout Seconds per request, or the default.
	 * @param float|null $until   When the requests have to be over, by
	 *                            now(): each request, the home page's
	 *                            redirects included, gets at most the time
	 *                            left (AB_MCP_Php_Probe::run()).
	 * @return array Report.
	 */
	protected static function probe( array $only, $stop, $timeout = null, $until = null ) {
		$opts = array();
		if ( null !== self::$live && null !== self::$live['upgrading'] ) {
			$key = self::maintenance_key();
			if ( null !== $key ) {
				$opts['key']   = $key['key'];
				$opts['nonce'] = $key['nonce'];
			}
		}
		if ( null !== $timeout ) {
			$opts['timeout'] = $timeout;
		}
		if ( null !== $until ) {
			$opts['until'] = (float) $until;
			$opts['clock'] = static function () {
				return static::now();
			};
		}
		if ( static::wants_state() ) {
			$opts['state'] = true;
		}
		return AB_MCP_Php_Probe::run( $only, $stop, $opts );
	}

	/**
	 * Whether the test requests report what they loaded
	 * (AB_MCP_Probe_State). An update does not ask: what it replaced are
	 * files, and the site loads them where it loaded the ones before.
	 *
	 * @return bool
	 */
	protected static function wants_state() {
		return false;
	}

	/**
	 * One round of test requests within this call's time: the targets one by
	 * one, each with the time left before $deadline, at most the usual
	 * timeout. A target there is no second left for is not asked, and one
	 * that timed out after less than the usual timeout was not given the time
	 * a test request gets: both are marked out_of_time, not checked rather
	 * than failed (verdict()). A site that hangs after the update times out
	 * with the usual timeout and puts the previous version back; a call that
	 * the update itself has used up keeps it, with "not checked", as the
	 * update button in wp-admin keeps an update without a test.
	 *
	 * @param string[] $only     Targets, in their order.
	 * @param bool     $stop     Stop at the first fatal error.
	 * @param float    $deadline When the round has to be over.
	 * @return array Report, as AB_MCP_Php_Probe::run() gives it.
	 */
	protected static function probe_until( array $only, $stop, $deadline ) {
		try {
			return self::probe_round( $only, $stop, $deadline );
		} catch ( \Throwable $e ) {
			// What the round builds outside AB_MCP_Php_Probe::run(), the
			// URLs of the targets, may throw, from code the change brought:
			// nothing is known then, every target counts as not reached, and
			// the verdict puts the change back (Codex, 06.10.2026: a deploy
			// stayed live unchecked).
			return AB_MCP_Php_Probe::failed_round( $only, $e );
		}
	}

	/**
	 * probe_until() itself.
	 *
	 * @param string[] $only     Targets, in their order.
	 * @param bool     $stop     Stop at the first fatal error.
	 * @param float    $deadline When the round has to be over.
	 * @return array Report.
	 */
	private static function probe_round( array $only, $stop, $deadline ) {
		$rows   = array();
		$fatal  = null;
		$login  = null;
		$urls   = AB_MCP_Php_Probe::targets();
		foreach ( $only as $name ) {
			$name = (string) $name;
			if ( $stop && null !== $fatal ) {
				$rows[] = array(
					'target' => $name,
					'url'    => isset( $urls[ $name ] ) ? $urls[ $name ] : '',
					'result' => 'not_probed',
				);
				continue;
			}
			$left = $deadline - static::now();
			if ( $left < 1 ) {
				$rows[] = array(
					'target'      => $name,
					'url'         => isset( $urls[ $name ] ) ? $urls[ $name ] : '',
					'result'      => 'not_probed',
					'out_of_time' => true,
					'reason'      => __( 'not asked: this call had no time left', 'alphabridge-mcp' ),
				);
				continue;
			}
			// The probe cuts each request, the home page's redirects
			// included, to the time left, and marks one cut short that timed
			// out as out_of_time.
			$one = static::probe( array( $name ), false, null, $deadline );
			if ( isset( $one['logged_in'] ) && is_array( $one['logged_in'] ) ) {
				$login = $one['logged_in'];
			}
			if ( empty( $one['targets'] ) && null !== self::left_out_for_login( $one ) ) {
				// run() left the dashboard out for its login: no row of it, as
				// in a round of all targets, and logged_in says why (verdict()).
				continue;
			}
			$row = isset( $one['targets'][0] ) && is_array( $one['targets'][0] ) ? $one['targets'][0] : array(
				'target' => $name,
				'result' => 'not_probed',
			);
			$rows[] = $row;
			if ( null === $fatal && 'fatal' === $row['result'] ) {
				$fatal = $row;
			}
		}
		$out = array(
			'result'      => AB_MCP_Php_Probe::verdict( $rows ),
			'targets'     => $rows,
			'not_checked' => AB_MCP_Php_Probe::NOT_CHECKED,
		);
		if ( null !== $fatal ) {
			$out['fatal'] = $fatal;
		}
		if ( null !== $login ) {
			$out['logged_in'] = $login;
		}
		return $out;
	}

	/**
	 * A target that answered before and timed out after the update is asked
	 * once more, with longer (the rest of the time before $deadline, at most
	 * 50 s, as core): the first request after an update is often the
	 * slowest. Only while that is more than the usual timeout: a second try
	 * with less time than the first says nothing new.
	 *
	 * @param array $before   Report before.
	 * @param array $after    Report after.
	 * @param float $deadline When the test has to be over.
	 * @return array The report after, those rows replaced by the second answer
	 *               where there is one; without one the first timeout stays.
	 */
	protected static function ask_timeouts_again( array $before, array $after, $deadline ) {
		$then = array();
		foreach ( (array) $before['targets'] as $row ) {
			$then[ (string) $row['target'] ] = $row;
		}
		try {
			$args = AB_MCP_Php_Probe::request_args();
		} catch ( \Throwable $e ) {
			// Its filter fails now: not asked again, the first timeouts stand.
			return $after;
		}
		$usual   = (float) $args['timeout'];
		$changed = false;
		foreach ( (array) $after['targets'] as $i => $row ) {
			$name = (string) $row['target'];
			if ( 'unreachable' !== $row['result'] || ! self::timed_out( $row ) || ! isset( $then[ $name ] ) || ! in_array( $then[ $name ]['result'], array( 'ok', 'stopped' ), true ) ) {
				continue;
			}
			$left = $deadline - static::now();
			if ( $left <= $usual ) {
				$after['targets'][ $i ]['asked_again'] = false;
				continue;
			}
			$again = static::probe( array( $name ), false, min( (float) self::REASK_MAX, $left ), $deadline );
			$new   = isset( $again['targets'][0] ) ? $again['targets'][0] : null;
			if ( ! is_array( $new ) ) {
				continue;
			}
			// A second try without an answer of its own says nothing new: it
			// gets the rest before $deadline, getting it ready takes a moment
			// of that, so its timeout counts as cut short (out_of_time). The
			// first request had a test request's full time; its timeout
			// stands, and the previous version comes back within the reserve
			// kept after $deadline (Codex, 03.10.2026: a home page that hung
			// for good kept the update).
			if ( 'not_probed' === $new['result'] ) {
				$after['targets'][ $i ]['asked_again'] = true;
				$after['targets'][ $i ]['again']       = isset( $new['reason'] ) ? $new['reason'] : '';
				continue;
			}
			$new['asked_again']        = true;
			$new['first']              = array(
				'result' => $row['result'],
				'reason' => isset( $row['reason'] ) ? $row['reason'] : '',
			);
			$after['targets'][ $i ] = $new;
			$changed                = true;
		}
		if ( $changed ) {
			$after['result'] = AB_MCP_Php_Probe::verdict( $after['targets'] );
			unset( $after['fatal'] );
			foreach ( $after['targets'] as $row ) {
				if ( 'fatal' === $row['result'] ) {
					$after['fatal'] = $row;
					break;
				}
			}
		}
		return $after;
	}

	/**
	 * Did this request run out of time, rather than fail to connect?
	 *
	 * @param array $row Row.
	 * @return bool
	 */
	public static function timed_out( array $row ) {
		return isset( $row['reason'] ) && (bool) preg_match( '/timed out|timeout|cURL error 28/i', (string) $row['reason'] );
	}

	/* ------------------------------------------------------- maintenance mode */

	/**
	 * Switch the maintenance mode on and remember which one is ours.
	 *
	 * @param WP_Upgrader $upgrader Upgrader.
	 */
	private static function maintenance_start( $upgrader ) {
		$upgrader->maintenance_mode( true );
		$key = self::maintenance_key();
		self::$live['upgrading'] = ( null !== $key ) ? $key['upgrading'] : null;
	}

	/**
	 * Switch the maintenance mode off: one written since this update began,
	 * by this guard or by the upgrader inside it (bulk_upgrade() writes its
	 * own, and an exception can come between its write and its delete).
	 * We hold the update lock, so no other update writes one meanwhile.
	 */
	private static function maintenance_end() {
		if ( null === self::$live ) {
			return;
		}
		$key = self::maintenance_key();
		if ( null !== $key && $key['upgrading'] >= (int) self::$live['since'] ) {
			global $wp_filesystem;
			// Before 6.6 maintenance_mode() uses the file system without
			// connecting it first.
			if ( self::$live['upgrader'] instanceof WP_Upgrader && is_object( $wp_filesystem ) ) {
				if ( property_exists( self::$live['upgrader'], 'hold' ) ) {
					self::$live['upgrader']->hold = false;
				}
				self::$live['upgrader']->maintenance_mode( false );
			}
			clearstatcache( true, ABSPATH . '.maintenance' );
			if ( file_exists( ABSPATH . '.maintenance' ) ) {
				@unlink( ABSPATH . '.maintenance' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		self::$live['upgrading'] = null;
	}

	/**
	 * The key core lets through the maintenance mode, from .maintenance as it
	 * is now: md5 of $upgrading and $upgrading as a string
	 * (class-wp-automatic-updater.php, has_fatal_error()). Read, not
	 * included: the file is only a timestamp.
	 *
	 * @return array|null { key, nonce, upgrading }
	 */
	public static function maintenance_key() {
		$file = ABSPATH . '.maintenance';
		clearstatcache( true, $file );
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$src = (string) @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! preg_match( '/\$upgrading\s*=\s*(\d+)\s*;/', $src, $m ) ) {
			return null;
		}
		$upgrading = (int) $m[1];
		return array(
			'key'       => md5( (string) $upgrading ),
			'nonce'     => (string) $upgrading,
			'upgrading' => $upgrading,
		);
	}

	/**
	 * Core removes these before its background updates, which renew the
	 * offers once at the end; in a request that has loaded wp-admin's
	 * filters they would ask wordpress.org after every package.
	 */
	private static function quiet_upgrader_hooks() {
		remove_action( 'upgrader_process_complete', array( 'Language_Pack_Upgrader', 'async_upgrade' ), 20 );
		remove_action( 'upgrader_process_complete', 'wp_version_check' );
		remove_action( 'upgrader_process_complete', 'wp_update_plugins' );
		remove_action( 'upgrader_process_complete', 'wp_update_themes' );
	}

	/* ------------------------------------------------------------------ lock */

	/**
	 * Take core's update lock and put our note beside it.
	 *
	 * @param array $job See update().
	 * @return string|WP_Error Our nonce.
	 */
	private static function take_lock( array $job ) {
		$nonce = bin2hex( random_bytes( 8 ) );
		$taken = self::on_main_site( array( 'WP_Upgrader', 'create_lock' ), self::LOCK );
		if ( ! $taken ) {
			$note = AB_MCP_Update_Log::running();
			if ( self::note_holds_lock( $note ) && ( time() - self::last_sign( $note ) ) >= self::STALE ) {
				// Ours, from a request that died: freed rather than refusing
				// for an hour and blocking core's background updates.
				AB_MCP_Update_Log::clear_running();
				self::on_main_site( array( 'WP_Upgrader', 'release_lock' ), self::LOCK );
				$taken = self::on_main_site( array( 'WP_Upgrader', 'create_lock' ), self::LOCK );
			}
		}
		if ( ! $taken ) {
			return self::busy();
		}
		AB_MCP_Update_Log::set_running(
			array(
				'type'    => (string) $job['type'],
				'item'    => (string) $job['item'],
				'started' => time(),
				'nonce'   => $nonce,
			)
		);
		return $nonce;
	}

	/**
	 * The moment from which a note's STALE counts: its start, the last
	 * beat() of its request, or, while WordPress's upgrader runs, the end of
	 * its lease (lease()) less STALE.
	 *
	 * @param array $note Our note.
	 * @return int
	 */
	public static function last_sign( array $note ) {
		return max(
			(int) $note['started'],
			isset( $note['beat'] ) ? (int) $note['beat'] : 0,
			isset( $note['until'] ) ? (int) $note['until'] - self::STALE : 0
		);
	}

	/**
	 * Hold our note for $seconds from now, or end that (0): see LEASE.
	 *
	 * @param int $seconds Seconds, 0 to end the lease.
	 */
	private static function lease( $seconds ) {
		if ( null === self::$live ) {
			return;
		}
		$note = AB_MCP_Update_Log::running();
		if ( null === $note || $note['nonce'] !== self::$live['nonce'] ) {
			return;
		}
		if ( $seconds > 0 ) {
			$note['until'] = time() + (int) $seconds;
		} else {
			unset( $note['until'] );
		}
		AB_MCP_Update_Log::set_running( $note );
	}

	/**
	 * Keep this update's locks fresh while it runs: the lock for changes
	 * (AB_MCP_Change_Lock::touch()) and our note, whose age decides when
	 * another request takes over core's update lock (STALE). Called between
	 * the steps of the update and by the skin at every step of the upgrader;
	 * writes at most every HEARTBEAT seconds (Codex, 06.10.2026: an update of
	 * more than ten minutes lost both).
	 */
	public static function beat() {
		if ( null === self::$live ) {
			return;
		}
		AB_MCP_Change_Lock::touch();
		if ( ( time() - (int) self::$live['beat'] ) < AB_MCP_Change_Lock::HEARTBEAT ) {
			return;
		}
		self::$live['beat'] = time();
		$note = AB_MCP_Update_Log::running();
		if ( null !== $note && $note['nonce'] === self::$live['nonce'] ) {
			$note['beat'] = time();
			AB_MCP_Update_Log::set_running( $note );
		}
	}

	/**
	 * Whether the plugin or theme runs on this site, read now (see update()).
	 *
	 * @param array $job See update().
	 * @return array The job, its active (and role) as they are now.
	 */
	protected static function role_now( array $job ) {
		if ( 'theme' === $job['type'] ) {
			if ( get_stylesheet() === $job['item'] ) {
				$role = 'active';
			} elseif ( get_template() === $job['item'] ) {
				$role = 'parent';
			} else {
				$role = 'inactive';
			}
			$job['role']   = $role;
			$job['active'] = 'inactive' !== $role;
		} elseif ( function_exists( 'is_plugin_active' ) ) {
			$job['active'] = is_plugin_active( $job['item'] );
		}
		return $job;
	}

	/**
	 * Why the lock is taken: by AlphaBridge, by WordPress's background
	 * updates, or by someone who has just started.
	 *
	 * @return WP_Error
	 */
	private static function busy() {
		$note  = AB_MCP_Update_Log::running();
		$since = (int) self::on_main_site( 'get_option', self::LOCK . '.lock', 0 );
		if ( self::note_holds_lock( $note ) && isset( $note['item'] ) ) {
			return new WP_Error(
				'ab_mcp_update_busy',
				sprintf(
					/* translators: 1: plugin or theme, 2: seconds */
					__( 'Not started: an update of %1$s through AlphaBridge has been running for %2$d s on this site. Updates of one site run one after the other: call the next one when this one has answered. After a call that timed out, wait a minute before calling it again: if that update was kept, nothing is offered any more and the answer names its result.', 'alphabridge-mcp' ),
					(string) $note['item'],
					max( 0, time() - (int) $note['started'] )
				)
			);
		}
		if ( $since > 0 && ( time() - $since ) <= 10 ) {
			return new WP_Error(
				'ab_mcp_update_busy',
				__( 'Not started: another update began on this site a moment ago (through AlphaBridge, or WordPress\'s own background updates). Nothing was changed; try again when it has finished.', 'alphabridge-mcp' )
			);
		}
		return new WP_Error(
			'ab_mcp_update_busy',
			sprintf(
				/* translators: %s: time in UTC */
				__( 'Not started: WordPress\'s own background updates are running on this site (their lock was taken at %s UTC and expires after 60 minutes). Nothing was changed; try again when they have finished.', 'alphabridge-mcp' ),
				$since > 0 ? gmdate( 'H:i', $since ) : '?'
			)
		);
	}

	/**
	 * Is the lock the one our note was written for? The note is written right
	 * after the lock is taken; a lock taken later is someone else's — core's
	 * background updates take over a lock left for an hour by a request that
	 * died without freeing it, and leave our old note beside it.
	 *
	 * @param array|null $note Our note, or null.
	 * @return bool
	 */
	private static function note_holds_lock( $note ) {
		if ( null === $note ) {
			return false;
		}
		$since = (int) self::on_main_site( 'get_option', self::LOCK . '.lock', 0 );
		return $since <= 0 || $since <= (int) $note['started'] + 5;
	}

	/**
	 * Maintenance mode off and lock free, at the end of the update or after
	 * an exception. Our note goes first, then the lock: the other way round a
	 * request taking the lock in between would lose its note.
	 */
	private static function finish() {
		if ( null === self::$live ) {
			return;
		}
		self::maintenance_end();
		// The lock for changes first: an activation asking in between then
		// still finds our note and is told an update runs.
		AB_MCP_Change_Lock::release();
		self::free_lock( self::$live['nonce'] );
		self::$live = null;
	}

	/**
	 * Our note and core's update lock, where the note is still ours.
	 *
	 * @param string $nonce Our nonce.
	 */
	private static function free_lock( $nonce ) {
		$note = AB_MCP_Update_Log::running();
		if ( null !== $note && hash_equals( (string) $note['nonce'], (string) $nonce ) ) {
			AB_MCP_Update_Log::clear_running();
			self::on_main_site( array( 'WP_Upgrader', 'release_lock' ), self::LOCK );
		}
	}

	/**
	 * After a fatal error of PHP (time limit, memory) neither finally nor the
	 * rest of the update runs. This does, before core's clean-up: a previous
	 * version that core had moved aside comes back where the new one is on
	 * disk untested (or the copy is kept where that fails), the maintenance
	 * mode goes off and the lock is freed if our note is on it. Where putting
	 * it back fails, the new files stay: the folder is marked as replaced
	 * before the lock is free, as settle() would have, so no file undo point
	 * of the previous version writes into them. Nothing to do after an update
	 * that ended as it should.
	 */
	public static function on_shutdown() {
		if ( null === self::$live ) {
			return;
		}
		$job = self::$live['job'];
		if ( is_array( $job ) && in_array( self::rescue( $job ), array( 'copy_kept', 'incomplete' ), true ) ) {
			AB_MCP_Update_Log::mark_replaced( $job['type'], $job['slug'], (int) self::$live['since'] );
		}
		self::finish();
	}

	/**
	 * Core's background updates run on the main site of a network and take
	 * their lock in its options; an update from another site of the network
	 * replaces the same files, so it takes the lock there too.
	 *
	 * @param callable $fn      What to call.
	 * @param mixed    ...$args Its arguments.
	 * @return mixed
	 */
	private static function on_main_site( $fn, ...$args ) {
		$switch = function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'is_main_site' ) && ! is_main_site();
		if ( $switch ) {
			switch_to_blog( get_main_site_id() );
		}
		try {
			return call_user_func_array( $fn, $args );
		} finally {
			if ( $switch ) {
				restore_current_blog();
			}
		}
	}

	/* --------------------------------------------------------------- restore */

	/**
	 * Put the previous version back from the copy core moved aside, after the
	 * pattern of WP_Upgrader::restore_temp_backup() — whose parameter naming
	 * a copy exists only from WordPress 6.6, and this plugin runs from 6.5. The order
	 * differs from core's: the new version is renamed out of the way first,
	 * the copy renamed into its place, and only then is the new version
	 * deleted, so the folder is missing for two renames, not while thousands
	 * of files are deleted. move_dir() clears the folder's OPcache entries.
	 *
	 * @param array $job See update().
	 * @return true|WP_Error
	 */
	private static function restore( array $job ) {
		global $wp_filesystem;
		if ( ! is_object( $wp_filesystem ) || ! $wp_filesystem->wp_content_dir() ) {
			return new WP_Error( 'ab_mcp_update_no_fs', __( 'WordPress\'s file access is not available.', 'alphabridge-mcp' ) );
		}
		$base   = trailingslashit( $wp_filesystem->wp_content_dir() ) . 'upgrade-temp-backup/' . $job['dir'] . '/';
		$backup = $base . $job['slug'];
		$folder = $wp_filesystem->find_folder( $job['root'] );
		$dest   = trailingslashit( $folder ? $folder : $job['root'] ) . $job['slug'];
		if ( ! $wp_filesystem->is_dir( $backup ) ) {
			return new WP_Error( 'ab_mcp_update_no_backup', __( 'WordPress\'s copy of the previous version is not there.', 'alphabridge-mcp' ) );
		}
		$aside = $base . $job['slug'] . '.ab-new-' . bin2hex( random_bytes( 4 ) );
		if ( $wp_filesystem->exists( $dest ) ) {
			$moved = move_dir( $dest, $aside, true );
			if ( is_wp_error( $moved ) ) {
				return new WP_Error( 'ab_mcp_update_move_failed', __( 'the new version could not be moved out of the way (file permissions?)', 'alphabridge-mcp' ) );
			}
		}
		$back = move_dir( $backup, $dest, true );
		if ( is_wp_error( $back ) ) {
			// Where it cannot rename, move_dir() copies, and a copy that
			// breaks off leaves part of the previous version in the folder
			// (its complete copy stays in upgrade-temp-backup). The new
			// version, complete under its own name, goes back over that part;
			// only a move that answers true leaves a complete folder.
			if ( $wp_filesystem->exists( $aside ) && true !== move_dir( $aside, $dest, true ) ) {
				return new WP_Error( 'ab_mcp_update_restore_incomplete', __( 'the previous version could not be moved back into place, and the new version could not be moved back where it was either, so the folder may be incomplete (file permissions?)', 'alphabridge-mcp' ) );
			}
			return new WP_Error( 'ab_mcp_update_restore_failed', __( 'the previous version could not be moved back into place (file permissions?)', 'alphabridge-mcp' ) );
		}
		// The new version goes at shutdown, under its own name: deleting
		// thousands of files would keep the maintenance mode on meanwhile.
		self::delete_at_shutdown( $aside );
		return true;
	}

	/**
	 * Where the copy of the previous version lies while an update runs.
	 *
	 * @param array $job See update().
	 * @return string
	 */
	private static function backup_path( array $job ) {
		return WP_CONTENT_DIR . '/upgrade-temp-backup/' . $job['dir'] . '/' . $job['slug'];
	}

	/**
	 * The folder of the package on the site.
	 *
	 * @param array $job See update().
	 * @return string
	 */
	protected static function folder( array $job ) {
		return ( 'theme' === $job['type'] ) ? trailingslashit( $job['root'] ) . $job['slug'] : WP_PLUGIN_DIR . '/' . $job['slug'];
	}

	/**
	 * What tells one copy of the package from another: the sha256 of its main
	 * file (style.css for a theme) and, for a theme, of functions.php. Not
	 * the version: a vendor's package can keep the one in its header and
	 * still bring new code. null without the main file.
	 *
	 * @param array  $job See update().
	 * @param string $dir Folder of a copy.
	 * @return string|null
	 */
	protected static function fingerprint( array $job, $dir ) {
		$files = ( 'theme' === $job['type'] ) ? array( 'style.css', 'functions.php' ) : array( basename( $job['item'] ) );
		$parts = array();
		foreach ( $files as $name ) {
			$file = trailingslashit( $dir ) . $name;
			clearstatcache( true, $file );
			$sha = is_readable( $file ) ? hash_file( 'sha256', $file ) : false;
			if ( false === $sha && ! $parts ) {
				return null;
			}
			$parts[] = ( false === $sha ) ? '-' : $sha;
		}
		return implode( ':', $parts );
	}

	/**
	 * Did the upgrader install something? Its result for the item is the
	 * array of WP_Upgrader::install_package() when it did, true when nothing
	 * was offered when it looked ("up_to_date"); and where something else is
	 * on disk now, it did whatever it says. Not by the version, which a
	 * package can keep.
	 *
	 * @param mixed       $result What bulk_upgrade() gave for the item.
	 * @param string|null $before fingerprint() before the update.
	 * @param string|null $after  fingerprint() after it.
	 * @return bool
	 */
	public static function installed( $result, $before, $after ) {
		return is_array( $result ) || $before !== $after;
	}

	/**
	 * After the update stopped half-way (an error of the upgrader, an
	 * exception, a fatal error of PHP): where this update's upgrader had
	 * moved the previous version aside, put it back at once and take core's
	 * own restore and delete at shutdown off. Whatever is in the folder now
	 * came after the copy, so its main file does not decide: an update can
	 * change only an included file (Codex, 03.10.2026). A copy under the
	 * folder's name that this upgrader did not make (one an earlier request
	 * left) is not this update's. Where putting it back fails, the copy stays.
	 *
	 * @param array $job See update().
	 * @return string restored | copy_kept | incomplete | nothing
	 */
	private static function rescue( array $job ) {
		$upgrader = ( null !== self::$live ) ? self::$live['upgrader'] : null;
		if ( ! is_object( $upgrader ) || empty( $upgrader->backed_up ) || ! is_dir( self::backup_path( $job ) ) ) {
			return 'nothing';
		}
		$res = self::restore( $job );
		self::unhook_core_backup();
		if ( is_wp_error( $res ) ) {
			return 'ab_mcp_update_restore_incomplete' === $res->get_error_code() ? 'incomplete' : 'copy_kept';
		}
		return 'restored';
	}

	/**
	 * Take core's restore and delete of the copy at shutdown off: the copy is
	 * dealt with here. Core's delete goes by the folder's name only, so left
	 * in place it would delete the copy of a later update of the same
	 * package that started in the meantime.
	 */
	private static function unhook_core_backup() {
		$upgrader = ( null !== self::$live ) ? self::$live['upgrader'] : null;
		if ( $upgrader instanceof WP_Upgrader ) {
			remove_action( 'shutdown', array( $upgrader, 'delete_temp_backup' ), 100 );
			remove_action( 'shutdown', array( $upgrader, 'restore_temp_backup' ), 10 );
		}
	}

	/**
	 * After the verdict: the copy of the previous version. A kept update's
	 * copy is renamed to a name of its own and deleted at shutdown, as core
	 * would delete it; after a roll-back there is none left; after a failed
	 * roll-back it stays (the answer names it).
	 *
	 * @param array $job See update().
	 * @param array $r   Record.
	 */
	private static function settle_backup( array $job, array $r ) {
		if ( empty( $r['ran'] ) || null === self::$live || ! self::$live['upgrader'] instanceof WP_Upgrader ) {
			return;
		}
		if ( 'kept' === $r['status'] ) {
			// Through WordPress's file access, as core moved it there: on a
			// host that writes over FTP, PHP itself may not.
			global $wp_filesystem;
			if ( ! is_object( $wp_filesystem ) || ! $wp_filesystem->wp_content_dir() ) {
				return;
			}
			$backup = trailingslashit( $wp_filesystem->wp_content_dir() ) . 'upgrade-temp-backup/' . $job['dir'] . '/' . $job['slug'];
			if ( ! $wp_filesystem->is_dir( $backup ) ) {
				return;
			}
			$old = $backup . '.ab-old-' . bin2hex( random_bytes( 4 ) );
			if ( $wp_filesystem->move( $backup, $old ) ) {
				self::unhook_core_backup();
				self::delete_at_shutdown( $old );
			} elseif ( $wp_filesystem->delete( $backup, true ) ) {
				// Not renamed: deleted now, under the lock, so nothing goes by
				// this name after the lock is free.
				self::unhook_core_backup();
			}
			return;
		}
		if ( in_array( $r['status'], array( 'rolled_back', 'rollback_failed' ), true ) ) {
			self::unhook_core_backup();
		}
	}

	/**
	 * Delete a folder of upgrade-temp-backup at shutdown.
	 *
	 * @param string $path Folder.
	 */
	private static function delete_at_shutdown( $path ) {
		add_action(
			'shutdown',
			static function () use ( $path ) {
				global $wp_filesystem;
				if ( is_object( $wp_filesystem ) && $wp_filesystem->exists( $path ) ) {
					$wp_filesystem->delete( $path, true );
				}
			},
			100,
			0
		);
	}

	/* ------------------------------------------------------------- the answer */

	/**
	 * The answer for the client, and the log entry, hold back and file undo
	 * points that go with it.
	 *
	 * @param array      $job     See update().
	 * @param array      $r       Record of run_locked().
	 * @param array|null $held    The hold on this package before the call.
	 * @param array      $settled What settle() did.
	 * @return array|WP_Error
	 */
	private static function answer( array $job, array $r, $held, array $settled ) {
		$what = self::what( $job );
		$from = (string) $r['version_before'];

		if ( 'budget' === $r['status'] ) {
			return new WP_Error(
				'ab_mcp_update_budget',
				sprintf(
					/* translators: 1: plugin or theme, 2: seconds used, 3: seconds needed, 4: budget */
					__( 'Not started: %1$s was not updated. This call had already taken %2$d s (the test requests before the update included), and the update with its wait and two more rounds of test requests needs about %3$d s more, past the %4$d s a call may take (clients and web servers stop waiting at 60 s). Nothing was changed. Call it again; if the site answers its test requests slowly, an administrator can look into that.', 'alphabridge-mcp' ),
					$what,
					(int) round( $r['elapsed'] ),
					(int) ceil( $r['need'] ),
					self::BUDGET
				)
			);
		}

		// Step 11, once, after the maintenance mode is off.
		$offers = $r['ran'] ? self::refresh_offers( $job ) : null;

		switch ( $r['status'] ) {
			case 'fs':
				return self::fs_refusal( $job );
			case 'failed':
				return new WP_Error(
					'ab_mcp_update_failed',
					sprintf(
						/* translators: 1: plugin or theme, 2: what WordPress said, 3: what is on disk now */
						__( 'Not updated: WordPress\'s upgrader stopped for %1$s with: %2$s %3$s Once the cause is fixed, call this tool again.', 'alphabridge-mcp' ),
						$what,
						rtrim( '' !== (string) $r['error'] ? (string) $r['error'] : __( 'no reason given', 'alphabridge-mcp' ), '.' ) . '.',
						self::rescue_words( $job, $r )
					)
				);
			case 'not_updated':
				return new WP_Error(
					'ab_mcp_update_not_updated',
					sprintf(
						/* translators: 1: plugin or theme, 2: version, 3: what WordPress said */
						__( 'Not updated: %1$s is still at version %2$s on disk after WordPress\'s upgrader ran (it said: %3$s). Nothing was changed. wp_get_update_status shows the offers WordPress knows of.', 'alphabridge-mcp' ),
						$what,
						'' !== $from ? $from : '?',
						'' !== (string) $r['error'] ? (string) $r['error'] : __( 'nothing', 'alphabridge-mcp' )
					)
				);
			case 'failed_inside':
				return new WP_Error(
					'ab_mcp_update_failed',
					sprintf(
						/* translators: 1: plugin or theme, 2: step, 3: what is on disk now */
						__( 'The update of %1$s stopped with an internal error during the step "%2$s"; the error is in AlphaBridge\'s update log, not in this answer, as it can name server paths. %3$s The maintenance mode is off and the update lock free. Check the site and the plugin list before calling it again.', 'alphabridge-mcp' ),
						$what,
						(string) $r['step'],
						self::rescue_words( $job, $r )
					)
				);
			case 'rollback_failed':
				return new WP_Error(
					'ab_mcp_update_rollback_failed',
					sprintf(
						/* translators: 1: plugin or theme, 2: old version, 3: new version, 4: result per target, 5: why, 6: what is on the site, 7: folder */
						__( 'After %1$s was updated from %2$s to %3$s, %4$s — and the previous version could NOT be put back: %5$s. %6$s The previous version is kept in %7$s (WordPress\'s weekly clean-up removes that folder); move it back over SFTP, or pause the plugin or theme with WordPress\'s recovery mode.', 'alphabridge-mcp' ),
						$what,
						'' !== $from ? $from : '?',
						self::new_words( $job, $r ),
						AB_MCP_Own_Files::summary( self::telling( $r['verdict']['blamed'] ) ),
						(string) $r['error'],
						empty( $r['incomplete'] ) ? __( 'The new version is still on the site.', 'alphabridge-mcp' ) : __( 'Parts of the new or the previous version may be missing on the site.', 'alphabridge-mcp' ),
						'wp-content/upgrade-temp-backup/' . $job['dir'] . '/' . $job['slug']
					)
				);
			case 'rolled_back':
				return self::rolled_back( $job, $r, $settled['hold'], $settled['auto'] );
		}

		return self::kept( $job, $r, $held, $offers, $settled['drop'], $settled['auto_on'] );
	}

	/**
	 * A kept update.
	 *
	 * @param array      $job     See update().
	 * @param array      $r       Record.
	 * @param array|null $held    Hold before the call.
	 * @param bool|null  $offers  Whether the offers were loaded again.
	 * @param array      $drop    File undo points dropped.
	 * @param bool       $auto_on Whether its automatic update is switched on again.
	 * @return array
	 */
	private static function kept( array $job, array $r, $held, $offers, array $drop, $auto_on = false ) {
		$warnings = array();
		$notes    = array();
		$v        = isset( $r['verdict'] ) ? $r['verdict'] : null;
		if ( 'not_active' === $r['plan'] ) {
			$notes[] = ( 'theme' === $job['type'] )
				? __( 'The theme is neither the active theme nor its parent, so its code does not run here and was not test-requested.', 'alphabridge-mcp' )
				: ( is_multisite()
					? __( 'The plugin is not active on this site, so it was not test-requested; on a network it may be active on other sites, which were not checked.', 'alphabridge-mcp' )
					: __( 'The plugin is not active, so its code does not run here and was not test-requested.', 'alphabridge-mcp' ) );
		} elseif ( 'off' === $r['plan'] ) {
			$warnings[] = __( 'Not checked: the test requests are switched off with the ab_mcp_php_probe_enabled filter; nothing checked that the site still runs with the new version.', 'alphabridge-mcp' );
		} elseif ( 'unreachable' === $r['plan'] ) {
			$warnings[] = sprintf(
				/* translators: %s: result per target */
				__( 'Not checked: no test request reached this site before the update (%s), so it was updated without a test, as the update button in wp-admin does. Make sure the site still runs. An administrator can give the test requests the headers an HTTP login or firewall wants with the ab_mcp_php_probe_request_args filter.', 'alphabridge-mcp' ),
				AB_MCP_Own_Files::summary( $r['before']['targets'] )
			);
		}
		if ( is_array( $v ) ) {
			if ( $v['same'] ) {
				$warnings[] = sprintf(
					/* translators: %s: result per target */
					__( 'Kept with a fatal error that was there before the update: %s. Where that error ends a request, the new code was not checked.', 'alphabridge-mcp' ),
					AB_MCP_Own_Files::summary( $v['same'] )
				);
			}
			if ( $v['stopped'] ) {
				$warnings[] = sprintf(
					/* translators: %s: result per target */
					__( 'Kept, but %s: the code after that point was not checked. A login wall or a maintenance mode does that on purpose; if the new version does it unasked, ask its vendor.', 'alphabridge-mcp' ),
					AB_MCP_Own_Files::summary( $v['stopped'] )
				);
			}
			if ( $v['unknown'] ) {
				$warnings[] = sprintf(
					/* translators: %s: result per target */
					__( 'Not compared, as already before the update: %s.', 'alphabridge-mcp' ),
					AB_MCP_Own_Files::summary( $v['unknown'] )
				);
			}
			if ( $v['unchecked'] ) {
				$names = array();
				foreach ( $v['unchecked'] as $row ) {
					$names[] = AB_MCP_Php_Probe::label( (string) $row['target'] );
				}
				$warnings[] = sprintf(
					/* translators: 1: targets, 2: seconds */
					__( 'Not checked: this call ran out of time before %1$s could be tested (the update itself took %2$d s). The new version was kept without that test, as the update button in wp-admin keeps an update; make sure the site still runs.', 'alphabridge-mcp' ),
					implode( ', ', $names ),
					(int) round( ( isset( $r['ms']['update'] ) ? (int) $r['ms']['update'] : 0 ) / 1000 )
				);
			}
			$login = self::login_warning( $v );
			if ( '' !== $login ) {
				$warnings[] = $login;
			}
		}
		$offered = isset( $job['offer']['version'] ) ? (string) $job['offer']['version'] : '';
		if ( '' !== $offered && $offered !== (string) $r['version_after'] ) {
			$warnings[] = sprintf(
				/* translators: 1: offered version, 2: version in the header */
				__( 'The package was offered as version %1$s, but the version in its header is %2$s.', 'alphabridge-mcp' ),
				$offered,
				'' !== (string) $r['version_after'] ? (string) $r['version_after'] : '?'
			);
		}
		if ( isset( $r['conclusive'] ) && false === $r['conclusive'] ) {
			$warnings[] = __( 'The test requests are not conclusive: OPcache runs on this server without checking file times, and this request could not clear it, so they may have run the previous code.', 'alphabridge-mcp' );
		}
		if ( 'not_supported' === $r['maintenance'] ) {
			$warnings[] = __( 'Before WordPress 6.6 no test request gets through the maintenance mode, so it was held only while the files were copied, and visitors saw the new version while it was tested.', 'alphabridge-mcp' );
		}
		if ( 'not_possible' === $r['maintenance'] ) {
			$warnings[] = __( 'WordPress could not switch on its maintenance mode (no write access to .maintenance in the WordPress folder), so visitors saw the new version while it was tested.', 'alphabridge-mcp' );
		}
		if ( false === $offers ) {
			$warnings[] = self::offers_warning();
		}
		if ( AB_MCP_Update_Log::holds_version( $held, $offered ) || AB_MCP_Update_Log::holds_version( $held, (string) $r['version_after'] ) ) {
			$warnings[] = sprintf(
				/* translators: 1: version, 2: date */
				__( '%1$s had been rolled back on %2$s; it was tried again because it was asked for by name, and kept this time.', 'alphabridge-mcp' ),
				(string) $held['version'],
				gmdate( 'Y-m-d H:i', (int) $held['time'] ) . ' UTC'
			);
		}
		if ( $drop ) {
			$notes[] = sprintf(
				/* translators: %s: list of files */
				__( 'Undo points for single files of the previous version were dropped, as they would write old files into the new version: %s.', 'alphabridge-mcp' ),
				implode( ', ', $drop )
			);
		}
		if ( $auto_on ) {
			$notes[] = sprintf(
				/* translators: %s: plugin or theme */
				__( 'AlphaBridge switched the automatic update of %s on again, which it had switched off after a roll-back.', 'alphabridge-mcp' ),
				self::what( $job )
			);
		}

		$key = ( 'theme' === $job['type'] ) ? 'stylesheet' : 'plugin';
		$out = array(
			'updated' => true,
			'kept'    => true,
			$key      => $job['item'],
		);
		if ( 'theme' === $job['type'] ) {
			$out['role'] = (string) $job['role'];
		}
		$previous = self::previous_package( $job, (string) $r['version_before'] );
		$out     += array(
			'version_before'    => (string) $r['version_before'],
			'version_after'     => (string) $r['version_after'],
			'version_offered'   => $offered,
			'active_before'     => (bool) $job['active'],
			'active_after'      => self::is_active( $job ),
			'probe'             => array(
				'before'      => $r['before'],
				'after'       => $r['after'],
				'tested'      => self::tested( $r ),
				'conclusive'  => ( 'run' === $r['plan'] ) ? ( isset( $r['conclusive'] ) ? (bool) $r['conclusive'] : true ) : false,
				'not_checked' => AB_MCP_Php_Probe::NOT_CHECKED,
			),
			'rolled_back'       => false,
			'opcache'           => $r['opcache'],
			'maintenance'       => $r['maintenance'],
			'undo'              => null,
			'way_back'          => self::way_back( $previous ),
			'previous_package'  => $previous,
			'dropped_file_undo' => $drop,
			'offers_refreshed'  => (bool) $offers,
			'warning'           => implode( ' ', $warnings ),
			'note'              => implode( ' ', $notes ),
			'ms'                => $r['ms'],
		);
		return $out;
	}

	/**
	 * Taken back. The message carries everything: an MCP client shows the
	 * message of an error, not its data.
	 *
	 * @param array  $job  See update().
	 * @param array  $r    Record.
	 * @param bool   $hold Whether the version is held back now.
	 * @param string $auto Where the hold cannot reach the automatic updates:
	 *                     switched_off, was_off or failed (settle()).
	 * @return WP_Error
	 */
	private static function rolled_back( array $job, array $r, $hold, $auto = '' ) {
		$what   = self::what( $job );
		$v      = $r['verdict'];
		$parts  = array();
		$parts[] = sprintf(
			/* translators: 1: plugin or theme, 2: old version, 3: new version, 4: result per target */
			__( 'Not kept: after %1$s was updated from %2$s to %3$s, %4$s.', 'alphabridge-mcp' ),
			$what,
			'' !== (string) $r['version_before'] ? (string) $r['version_before'] : '?',
			self::new_words( $job, $r ),
			AB_MCP_Own_Files::summary( self::telling( $v['blamed'] ) )
		);
		if ( 'mcp_cut' === $v['why'] ) {
			$parts[] = __( 'Kept, it would have cut this connection.', 'alphabridge-mcp' );
		}
		$back   = (string) $r['version_restored'];
		$active = self::is_active( $job );
		if ( 'theme' === $job['type'] ) {
			$parts[] = ( get_stylesheet() === $job['item'] )
				/* translators: %s: version */
				? sprintf( __( 'Version %s is back and still the active theme.', 'alphabridge-mcp' ), '' !== $back ? $back : '?' )
				/* translators: %s: version */
				: sprintf( __( 'Version %s is back.', 'alphabridge-mcp' ), '' !== $back ? $back : '?' );
		} else {
			$parts[] = $active
				/* translators: %s: version */
				? sprintf( __( 'Version %s is back and active.', 'alphabridge-mcp' ), '' !== $back ? $back : '?' )
				/* translators: %s: version */
				: sprintf( __( 'Version %s is back.', 'alphabridge-mcp' ), '' !== $back ? $back : '?' );
		}
		$control = $r['after_rollback'];
		if ( is_array( $control ) && 'passed' === $control['result'] ) {
			$parts[] = __( 'The test requests pass again.', 'alphabridge-mcp' );
		} elseif ( is_array( $control ) ) {
			$parts[] = sprintf(
				/* translators: %s: result per target */
				__( 'With it back, the test requests say: %s.', 'alphabridge-mcp' ),
				AB_MCP_Own_Files::summary( $control['targets'] )
			);
		}
		$advice = ( 'fatal' === $v['why'] )
			? __( 'Wait for a fixed release, or report the error to the vendor.', 'alphabridge-mcp' )
			: __( 'The new version ends requests to AlphaBridge\'s REST route before AlphaBridge checks its token; report that to its vendor.', 'alphabridge-mcp' );
		// WordPress's list of automatic updates is all that answers on the
		// main site here, and WP_Automatic_Updater::should_update() reads it
		// only for an offer without the mark autoupdate, which wordpress.org
		// sets for some security releases (a vendor's updater can set it too).
		$marked = sprintf(
			/* translators: %s: version */
			__( 'One exception: an update offered with the mark for automatic installation on every site (wordpress.org sets it for some security releases) can be installed by WordPress\'s automatic updates even where the automatic update is off, as the list is not read for it; AlphaBridge MCP active on the main site or network-wide holds %s back from that too.', 'alphabridge-mcp' ),
			self::held_version( $job, $r )
		);
		if ( $hold && 'switched_off' === $auto ) {
			$parts[] = sprintf(
				/* translators: 1: plugin or theme, 2: version */
				__( 'AlphaBridge has switched the automatic update of %1$s off, so that WordPress does not install %2$s again: it runs its automatic updates on the main site of this network, where AlphaBridge MCP is not active. AlphaBridge switches it on again once a newer version is installed: at once when this tool installs it, otherwise at a check AlphaBridge MCP makes twice a day on this site, and then only for a plain version number (such as 2.0.1, not 2.1-rc1). Calling this tool again tries %2$s again.', 'alphabridge-mcp' ),
				$what,
				self::held_version( $job, $r )
			);
			$parts[] = $marked;
			$parts[] = $advice;
		} elseif ( $hold && 'was_off' === $auto ) {
			$parts[] = sprintf(
				/* translators: 1: version, 2: plugin or theme */
				__( '%1$s will not be installed again automatically: the automatic update of %2$s is off.', 'alphabridge-mcp' ),
				self::held_version( $job, $r ),
				$what
			);
			$parts[] = $marked;
			$parts[] = $advice;
		} elseif ( $hold && '' !== $auto ) {
			// Still on WordPress's list: the write did not land.
			$parts[] = sprintf(
				/* translators: %s: version */
				__( 'WordPress runs its automatic updates on the main site of this network, where AlphaBridge MCP is not active, so they may install %s again: switch the automatic update of this plugin or theme off, or activate AlphaBridge MCP network-wide.', 'alphabridge-mcp' ),
				self::held_version( $job, $r )
			);
			$parts[] = $marked;
		} elseif ( $hold ) {
			$parts[] = sprintf(
				/* translators: %s: version */
				__( '%s will not be installed again automatically (neither by WordPress\'s automatic updates); a newer version will, and calling this tool again tries it again.', 'alphabridge-mcp' ),
				self::held_version( $job, $r )
			);
			$parts[] = $advice;
		} elseif ( array_filter( array_column( $v['blamed'], 'no_report' ) ) ) {
			$hang = false;
			foreach ( $v['blamed'] as $row ) {
				$hang = $hang || ( 'unreachable' === $row['result'] && empty( $row['no_report'] ) );
			}
			$parts[] = __( 'With the new version the site answered without WordPress\'s test report. Most often its code ends the answer itself before WordPress has finished (fastcgi_finish_request() or litespeed_finish_request(), or exit or die in the shutdown action), fails at the very end of the request, or cuts the page off; an HTTP 502 or 504 means PHP or the web server ended the request, for example after a crash. The update stays offered: try again later, or report it to its vendor if it stays so.', 'alphabridge-mcp' );
			if ( $hang ) {
				$parts[] = __( 'If the site is only slow, an administrator can give the test requests more time with the ab_mcp_php_probe_request_args filter (timeout, at most 60 seconds).', 'alphabridge-mcp' );
			}
		} else {
			$parts[] = __( 'The update stays offered: a target that no longer answers can also be the host. If the site is only slow, an administrator can give the test requests more time with the ab_mcp_php_probe_request_args filter (timeout, at most 60 seconds); then try again.', 'alphabridge-mcp' );
		}
		$parts[] = __( 'Files only: what the new version changed in the database when it loaded stays.', 'alphabridge-mcp' );
		return new WP_Error(
			'ab_mcp_update_rolled_back',
			implode( ' ', $parts ),
			array(
				'rolled_back'      => true,
				'version_before'   => (string) $r['version_before'],
				'version_after'    => (string) $r['version_after'],
				'version_offered'  => isset( $job['offer']['version'] ) ? (string) $job['offer']['version'] : '',
				'version_restored' => $back,
				'active_after'     => $active,
				'hold'             => $hold,
				'probe'            => array(
					'before'         => $r['before'],
					'after'          => $r['after'],
					'after_rollback' => $r['after_rollback'],
					'not_checked'    => AB_MCP_Php_Probe::NOT_CHECKED,
				),
				'maintenance'      => $r['maintenance'],
				'opcache'          => $r['opcache'],
				'ms'               => $r['ms'],
			)
		);
	}

	/**
	 * Does the hold reach WordPress's automatic updates? They run on the main
	 * site of a network, and the hold is this plugin's filter
	 * (AB_MCP_Update_Log, hooked in the main plugin file): this plugin has to
	 * run there, network-wide or on the main site. Pro hooks the same filter on
	 * the same shared holds (AB_MCP_Pro_Update_Log), so Pro running there counts
	 * as well: this plugin on a site of the network and Pro on its main site is
	 * a network whose automatic updates respect the hold.
	 *
	 * @return bool
	 */
	public static function hold_reaches_auto_updates() {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return true;
		}
		$plugins = array();
		if ( defined( 'AB_MCP_BASENAME' ) ) {
			$plugins[] = (string) AB_MCP_BASENAME;
		} elseif ( defined( 'AB_MCP_FILE' ) ) {
			$plugins[] = plugin_basename( AB_MCP_FILE );
		}
		$plugins[] = defined( 'AB_MCP_PRO_FILE' ) ? plugin_basename( AB_MCP_PRO_FILE ) : 'alphabridge-mcp-pro/alphabridge-mcp-pro.php';
		$main      = (array) get_blog_option( get_main_site_id(), 'active_plugins', array() );
		foreach ( array_unique( $plugins ) as $plugin ) {
			if ( function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $plugin ) ) {
				return true;
			}
			if ( in_array( $plugin, $main, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The refusal for a host where WordPress may not write its files
	 * directly and no FTP access is configured: bulk_upgrade() answers false
	 * there, which is no "no update available".
	 *
	 * @param array $job See update().
	 * @return WP_Error
	 */
	public static function fs_refusal( array $job ) {
		$method = function_exists( 'get_filesystem_method' ) ? (string) get_filesystem_method( array(), $job['root'] ) : '';
		return new WP_Error(
			'ab_mcp_update_fs',
			sprintf(
				/* translators: 1: plugin or theme, 2: method */
				__( 'Not updated: WordPress may not write its files directly on this host (file system method "%2$s") and no FTP access is set in wp-config.php, so it cannot install %1$s without asking for FTP credentials. Nothing was changed. The host can give the web server write access to wp-content, or an administrator sets FS_METHOD and the FTP constants in wp-config.php.', 'alphabridge-mcp' ),
				self::what( $job ),
				'' !== $method ? $method : '?'
			)
		);
	}

	/**
	 * May WordPress write here without asking for credentials?
	 *
	 * @param string $context Folder.
	 * @return bool
	 */
	public static function can_write( $context ) {
		if ( ! function_exists( 'get_filesystem_method' ) ) {
			return true;
		}
		if ( 'direct' === get_filesystem_method( array(), $context ) ) {
			return true;
		}
		return defined( 'FTP_HOST' ) && defined( 'FTP_USER' ) && ( defined( 'FTP_PASS' ) || ( defined( 'FTP_PUBKEY' ) && defined( 'FTP_PRIKEY' ) ) );
	}

	/**
	 * No offer: the installed version and the last update of this package
	 * through AlphaBridge, so a call after a timeout learns how it ended.
	 *
	 * @param array  $job     See update().
	 * @param string $version Installed version.
	 * @return WP_Error
	 */
	public static function no_offer( array $job, $version ) {
		$msg  = sprintf(
			/* translators: 1: plugin or theme, 2: version */
			__( 'No update offered for %1$s; version %2$s is installed. WordPress asks wordpress.org at most every 12 hours (vendors of other plugins answer in the same check); wp_get_update_status shows what it knows of.', 'alphabridge-mcp' ),
			self::what( $job ),
			'' !== (string) $version ? (string) $version : '?'
		);
		$last = AB_MCP_Update_Log::last_for( $job['type'], $job['item'] );
		if ( null !== $last ) {
			$msg .= ' ' . sprintf(
				/* translators: 1: date, 2: result, 3: from, 4: to */
				__( 'Last update through AlphaBridge: %1$s, %2$s (%3$s to %4$s).', 'alphabridge-mcp' ),
				gmdate( 'Y-m-d H:i', (int) $last['time'] ) . ' UTC',
				self::result_words( (string) $last['result'] ),
				'' !== (string) $last['from'] ? (string) $last['from'] : '?',
				'' !== (string) $last['to'] ? (string) $last['to'] : '?'
			);
		}
		return new WP_Error( 'ab_mcp_update_none', $msg );
	}

	/**
	 * What is on disk after an update that stopped half-way.
	 *
	 * @param array $job See update().
	 * @param array $r   Record.
	 * @return string
	 */
	private static function rescue_words( array $job, array $r ) {
		$on_disk = '' !== (string) $r['version_after'] ? (string) $r['version_after'] : '?';
		if ( 'restored' === $r['rescued'] ) {
			/* translators: %s: version */
			return sprintf( __( 'WordPress had already moved the previous version aside; it is back (version %s on disk).', 'alphabridge-mcp' ), $on_disk );
		}
		if ( 'incomplete' === $r['rescued'] ) {
			return sprintf(
				/* translators: %s: folder */
				__( 'The previous version could not be put back, and the new one could not be moved back where it was either, so parts of the plugin or theme may be missing on the site; the previous version is kept in %s (WordPress\'s weekly clean-up removes that folder): move it back over SFTP.', 'alphabridge-mcp' ),
				'wp-content/upgrade-temp-backup/' . $job['dir'] . '/' . $job['slug']
			);
		}
		if ( 'copy_kept' === $r['rescued'] ) {
			return sprintf(
				/* translators: 1: version, 2: folder */
				__( 'The previous version could not be put back; version %1$s is on disk, and the previous one is kept in %2$s (WordPress\'s weekly clean-up removes that folder): move it back over SFTP.', 'alphabridge-mcp' ),
				$on_disk,
				'wp-content/upgrade-temp-backup/' . $job['dir'] . '/' . $job['slug']
			);
		}
		/* translators: %s: version */
		return sprintf( __( 'Version %s is on disk now.', 'alphabridge-mcp' ), $on_disk );
	}

	/**
	 * @param string $result Log result.
	 * @return string
	 */
	private static function result_words( $result ) {
		$words = array(
			'kept'            => __( 'kept', 'alphabridge-mcp' ),
			'not_checked'     => __( 'kept without a test', 'alphabridge-mcp' ),
			'rolled_back'     => __( 'rolled back', 'alphabridge-mcp' ),
			'rollback_failed' => __( 'could not be rolled back', 'alphabridge-mcp' ),
			'not_updated'     => __( 'not updated', 'alphabridge-mcp' ),
			'failed'          => __( 'failed', 'alphabridge-mcp' ),
		);
		return isset( $words[ $result ] ) ? $words[ $result ] : $result;
	}

	/* -------------------------------------------------------------- the log */

	/**
	 * One log entry. No credentials, no content: package, versions, the
	 * result per target with PHP's message, file and line.
	 *
	 * @param array  $job See update().
	 * @param array  $r   Record.
	 * @param string $to  Offered version.
	 * @return array
	 */
	private static function log_row( array $job, array $r, $to ) {
		$result = $r['status'];
		if ( 'kept' === $result && 'not_active' !== $r['plan'] && ! self::tested( $r ) ) {
			$result = 'not_checked';
		} elseif ( 'fs' === $result || 'failed_inside' === $result ) {
			$result = 'failed';
		}
		$row = array(
			'time'             => time(),
			'user'             => (int) get_current_user_id(),
			'tool'             => (string) $job['tool'],
			'type'             => (string) $job['type'],
			'item'             => (string) $job['item'],
			'from'             => (string) $r['version_before'],
			'to'               => (string) $to,
			'installed'        => (string) ( 'rolled_back' === $r['status'] ? $r['version_restored'] : $r['version_after'] ),
			'result'           => $result,
			'reason'           => isset( $r['verdict']['why'] ) ? (string) $r['verdict']['why'] : ( 'not_active' === $r['plan'] ? 'not_in_use' : '' ),
			'probe'            => array(
				'before'         => self::compact( $r['before'] ),
				'after'          => self::compact( $r['after'] ),
				'after_rollback' => self::compact( $r['after_rollback'] ),
			),
			'ms'               => isset( $r['ms'] ) ? $r['ms'] : array(),
			'previous_package' => self::previous_package( $job, (string) $r['version_before'] ),
			'undo'             => null,
		);
		if ( '' !== (string) $r['error'] ) {
			$row['error'] = substr( (string) $r['error'], 0, 500 );
		}
		if ( '' !== (string) $r['step'] ) {
			$row['step'] = (string) $r['step'];
		}
		if ( 'nothing' !== $r['rescued'] ) {
			$row['rescued'] = (string) $r['rescued'];
		}
		return $row;
	}

	/**
	 * A report reduced to what the log keeps.
	 *
	 * @param array|null $report Report.
	 * @return array|null
	 */
	private static function compact( $report ) {
		if ( ! is_array( $report ) ) {
			return null;
		}
		$rows = array();
		foreach ( (array) $report['targets'] as $row ) {
			$c = array(
				'target' => (string) $row['target'],
				'result' => (string) $row['result'],
			);
			if ( isset( $row['http_status'] ) ) {
				$c['http_status'] = (int) $row['http_status'];
			}
			if ( isset( $row['error'] ) && is_array( $row['error'] ) ) {
				$c['error'] = array(
					'message' => substr( (string) $row['error']['message'], 0, 300 ),
					'file'    => (string) $row['error']['file'],
					'line'    => (int) $row['error']['line'],
				);
			}
			if ( isset( $row['reason'] ) ) {
				$c['reason'] = substr( (string) $row['reason'], 0, 300 );
			}
			if ( ! empty( $row['out_of_time'] ) ) {
				$c['out_of_time'] = true;
			}
			$rows[] = $c;
		}
		return array(
			'result'  => (string) $report['result'],
			'targets' => $rows,
		);
	}

	/* -------------------------------------------------------------- helpers */

	/**
	 * An empty record.
	 *
	 * @param array  $job    See update().
	 * @param string $status Status.
	 * @return array
	 */
	private static function record_of( array $job, $status ) {
		return array(
			'status'           => $status,
			'plan'             => '',
			'ran'              => false,
			'version_before'   => self::version_of( $job ),
			'version_after'    => '',
			'version_restored' => '',
			'before'           => null,
			'after'            => null,
			'after_rollback'   => null,
			'maintenance'      => 'not_needed',
			'opcache'          => null,
			'error'            => '',
			'step'             => '',
			'rescued'          => 'nothing',
			'incomplete'       => false,
			'ms'               => array(),
		);
	}

	/**
	 * The version in the header of the main file (style.css for a theme), as
	 * it is on disk now. '' without a version, null without the file.
	 *
	 * @param array $job See update().
	 * @return string|null
	 */
	public static function version_of( array $job ) {
		$file = ( 'theme' === $job['type'] )
			? trailingslashit( $job['root'] ) . $job['slug'] . '/style.css'
			: WP_PLUGIN_DIR . '/' . $job['item'];
		clearstatcache( true, $file );
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$head = get_file_data( $file, array( 'Version' => 'Version' ) );
		return isset( $head['Version'] ) ? (string) $head['Version'] : '';
	}

	/**
	 * A PHP file of the new version, to learn whether this request can clear
	 * OPcache.
	 *
	 * @param array $job See update().
	 * @return string
	 */
	private static function main_php( array $job ) {
		if ( 'theme' !== $job['type'] ) {
			return WP_PLUGIN_DIR . '/' . $job['item'];
		}
		$dir = trailingslashit( $job['root'] ) . $job['slug'];
		return file_exists( $dir . '/functions.php' ) ? $dir . '/functions.php' : $dir . '/index.php';
	}

	/**
	 * Seconds to wait before the test: 2, as core; where OPcache runs and
	 * this request cannot clear it, until PHP looks at the file times again
	 * (opcache.revalidate_freq, default 2) plus one.
	 *
	 * @param string $opcache What invalidate_opcache() said.
	 * @return int
	 */
	private static function wait_for( $opcache ) {
		$wait = self::WAIT;
		if ( 'not_possible' === $opcache && self::ini_on( 'opcache.validate_timestamps' ) ) {
			$wait = max( $wait, (int) ini_get( 'opcache.revalidate_freq' ) + 1 );
		}
		return $wait;
	}

	/**
	 * Can the test requests be trusted to run the new code?
	 *
	 * @param string $opcache What invalidate_opcache() said.
	 * @return bool
	 */
	private static function conclusive( $opcache ) {
		return ! ( 'not_possible' === $opcache && ! self::ini_on( 'opcache.validate_timestamps' ) );
	}

	/**
	 * @param string $key ini key.
	 * @return bool
	 */
	private static function ini_on( $key ) {
		$v = ini_get( $key );
		return false !== $v && in_array( strtolower( trim( (string) $v ) ), array( '1', 'on', 'yes', 'true' ), true );
	}

	/**
	 * Whether the code runs on this site now.
	 *
	 * @param array $job See update().
	 * @return bool
	 */
	private static function is_active( array $job ) {
		if ( 'theme' === $job['type'] ) {
			return get_stylesheet() === $job['item'] || get_template() === $job['item'];
		}
		return function_exists( 'is_plugin_active' ) && is_plugin_active( $job['item'] );
	}

	/**
	 * Did a test request check the new version? Not where the plan had none,
	 * and not where this call had no time left for any target after it.
	 *
	 * @param array $r Record.
	 * @return bool
	 */
	private static function tested( array $r ) {
		if ( 'run' !== $r['plan'] || ! is_array( $r['after'] ) ) {
			return false;
		}
		foreach ( (array) $r['after']['targets'] as $row ) {
			if ( empty( $row['out_of_time'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The version a hold keeps from the automatic updates: the one of the
	 * offer, which they go by; the one in the header where the offer named
	 * none.
	 *
	 * @param array $job See update().
	 * @param array $r   Record.
	 * @return string
	 */
	private static function held_version( array $job, array $r ) {
		$offered = isset( $job['offer']['version'] ) ? (string) $job['offer']['version'] : '';
		return '' !== $offered ? $offered : (string) $r['version_after'];
	}

	/**
	 * The new version in a message: its number, or, where the offer and the
	 * package's header disagree, both.
	 *
	 * @param array $job See update().
	 * @param array $r   Record.
	 * @return string
	 */
	private static function new_words( array $job, array $r ) {
		$after   = (string) $r['version_after'];
		$offered = isset( $job['offer']['version'] ) ? (string) $job['offer']['version'] : '';
		if ( '' !== $offered && $offered !== $after ) {
			/* translators: 1: offered version, 2: version in the header */
			return sprintf( __( 'the package offered as %1$s (version in its header: %2$s)', 'alphabridge-mcp' ), $offered, '' !== $after ? $after : '?' );
		}
		return '' !== $after ? $after : '?';
	}

	/**
	 * What the messages call the package.
	 *
	 * @param array $job See update().
	 * @return string
	 */
	private static function what( array $job ) {
		return ( 'theme' === $job['type'] )
			/* translators: %s: stylesheet */
			? sprintf( __( 'the theme %s', 'alphabridge-mcp' ), $job['item'] )
			/* translators: %s: plugin folder */
			: sprintf( __( 'the plugin %s', 'alphabridge-mcp' ), $job['slug'] );
	}

	/**
	 * The ZIP of the previous version where wordpress.org keeps it: every
	 * release of a plugin or theme there stays downloadable.
	 *
	 * @param array  $job     See update().
	 * @param string $version Previous version.
	 * @return string|null
	 */
	private static function previous_package( array $job, $version ) {
		if ( empty( $job['offer']['wporg'] ) || '' === (string) $version || ! preg_match( '/^[0-9A-Za-z.\-_]+\z/', (string) $version ) ) {
			return null;
		}
		$slug = isset( $job['offer']['slug'] ) ? (string) $job['offer']['slug'] : '';
		if ( ! preg_match( '/^[a-z0-9\-_]+\z/', $slug ) ) {
			return null;
		}
		return 'https://downloads.wordpress.org/' . ( 'theme' === $job['type'] ? 'theme' : 'plugin' ) . '/' . $slug . '.' . $version . '.zip';
	}

	/**
	 * @param string|null $previous Previous package.
	 * @return string
	 */
	private static function way_back( $previous ) {
		$s = __( 'There is no undo point: an update is rolled back only within the request that made it.', 'alphabridge-mcp' );
		if ( null !== $previous ) {
			/* translators: %s: URL */
			$s .= ' ' . sprintf( __( 'wordpress.org keeps the previous version at %s.', 'alphabridge-mcp' ), $previous );
		}
		return $s;
	}

	/**
	 * What the skin collected, for a "not updated".
	 *
	 * @param WP_Upgrader_Skin $skin Skin.
	 * @return string
	 */
	private static function skin_says( $skin ) {
		$parts = array();
		if ( isset( $skin->result ) && is_wp_error( $skin->result ) ) {
			$parts[] = $skin->result->get_error_message();
		}
		if ( method_exists( $skin, 'get_upgrade_messages' ) ) {
			foreach ( (array) $skin->get_upgrade_messages() as $m ) {
				$m = trim( wp_strip_all_tags( (string) $m ) );
				if ( '' !== $m && ! in_array( $m, $parts, true ) ) {
					$parts[] = $m;
				}
			}
		}
		return substr( implode( ' ', $parts ), 0, 500 );
	}

	/**
	 * Load the offers again, once, as core does after its background updates:
	 * then wp_get_update_status shows what is left, and a version taken back
	 * is offered again.
	 *
	 * Core writes the list once before it asks wordpress.org, with only the
	 * time of the check (to which vendors' updaters may add their offers on
	 * writing), and once more only with the answer, which carries "checked",
	 * the versions it asked about (wp-includes/update.php, wp_update_plugins()
	 * and wp_update_themes()). A fresh time alone says nothing.
	 *
	 * @param array $job See update().
	 * @return bool Whether WordPress has a fresh list now.
	 */
	private static function refresh_offers( array $job ) {
		if ( 'theme' === $job['type'] ) {
			wp_clean_themes_cache();
			wp_update_themes();
			$now = get_site_transient( 'update_themes' );
		} else {
			wp_clean_plugins_cache();
			wp_update_plugins();
			$now = get_site_transient( 'update_plugins' );
		}
		return is_object( $now ) && isset( $now->checked ) && is_array( $now->checked );
	}

	/**
	 * @return string
	 */
	private static function offers_warning() {
		return __( 'The list of offered updates could not be loaded again from wordpress.org; wp_get_update_status shows it again after WordPress\'s next check.', 'alphabridge-mcp' );
	}

	/**
	 * When this request started.
	 *
	 * @return float
	 */
	protected static function request_start() {
		return isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : static::now(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a number.
	}

	/**
	 * @return float
	 */
	protected static function now() {
		return microtime( true );
	}

	/**
	 * @param int $seconds Seconds.
	 */
	protected static function pause( $seconds ) {
		sleep( (int) $seconds );
	}

	/**
	 * @param float $t0 Start.
	 * @return int
	 */
	private static function ms_since( $t0 ) {
		return (int) round( ( static::now() - $t0 ) * 1000 );
	}
}
